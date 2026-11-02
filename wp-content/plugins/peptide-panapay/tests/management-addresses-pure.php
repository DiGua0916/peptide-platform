<?php
/** Actual HQ address/payload/relay code with synthetic in-memory boundaries; no WP, database or network. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	class WP_Error { public function __construct( public string $code, public string $message = '', public array $data = array() ) {} }
	function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
	function trailingslashit( string $s ): string { return rtrim( $s, '/' ) . '/'; }
	function wp_get_environment_type(): string { return 'local'; }
	function is_email( string $s ): bool { return false !== filter_var( $s, FILTER_VALIDATE_EMAIL ); }
	function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
	function wp_json_encode( $v ): string { return json_encode( $v ); }
	function add_query_arg( $key, $value, ?string $url = null ): string { return $url . '?' . http_build_query( array( $key => $value ), '', '&', PHP_QUERY_RFC3986 ); }
	function add_filter( ...$args ): void {}
	function remove_filter( ...$args ): void {}
	function wp_remote_retrieve_response_code( array $r ): int { return $r['code']; }
	function wp_remote_retrieve_body( array $r ): string { return $r['body']; }
	function wp_safe_remote_post( string $url, array $args ): array {
		$GLOBALS['http'][] = array( $url, $args );
		$data = array( 'accepted' => true, 'disposition' => 'paid' ); $nonce = $args['headers']['X-PC-Pay-Nonce'];
		$sign = hash_hmac( 'sha256', "pc-pay-response-v1\n" . $nonce . "\n" . hash( 'sha256', \VialCove\Panapay\Hub_Protocol::json( $data ) ), str_repeat( 'a', 64 ) );
		return array( 'code' => 200, 'body' => json_encode( array( 'data' => $data, 'nonce' => $nonce, 'sign' => $sign ) ) );
	}
}
namespace PeptideHq {
	final class Sites {
		public static function get( int $id ): ?array { return $GLOBALS['site']; }
		public static function key( int $id ): string { return str_repeat( 'a', 64 ); }
	}
}
namespace VialCove\Panapay {
	final class Crypto { public static function decrypt( string $value, string $purpose ): string { return $value; } }
	final class Hub_Store {
		public static function pending( int $limit ): array { return array( $GLOBALS['event'] ); }
		public static function claim_delivery( string $id ): array { return $GLOBALS['event']; }
		public static function get( string $id ): array { return $GLOBALS['attempt']; }
		public static function delivery_result( ...$args ): void { $GLOBALS['delivery'] = $args; }
	}
}
namespace {
	require dirname( __DIR__, 2 ) . '/peptide-hq/includes/class-site-urls.php';
	foreach ( array( 'protocol-error', 'hub-protocol', 'session-service', 'signature', 'hub-urls', 'hub' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
	use VialCove\Panapay\{Hub, Hub_Urls};
	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new \RuntimeException( 'FAIL ' . $name ); } }
	function rejected( callable $test ): bool { try { $test(); return false; } catch ( \VialCove\Panapay\Protocol_Error $e ) { return true; } }
	$uuid = '11111111-1111-4111-8111-111111111111';
	$site = array( 'id' => 1, 'uuid' => $uuid, 'url' => 'https://a.manager.local/', 'management_url' => 'https://a.manager.local/', 'public_url' => 'https://shop.local/', 'dual_domain' => true );
	$reference = 'PP12345678-42-' . str_repeat( 'b', 32 ); $token = str_repeat( 'c', 48 );
	$query = 'reference=' . $reference . '&token=' . $token;
	$plain = 'https://shop.local/?wc-api=pc_panapay_return&' . $query;
	$pretty = 'https://shop.local/wc-api/pc_panapay_return/?' . $query;
	check( 'provider site is registered public', 'https://shop.local/' === Hub_Urls::public_url( $site ) );
	check( 'event target is registered management', 'https://a.manager.local/' === Hub_Urls::management( $site ) );
	foreach ( array( $plain, $pretty, str_replace( '/?', '?', $pretty ), str_replace( 'shop.local', 'shop.local:443', $plain ) ) as $good ) { check( 'accept native registered callback ' . $checks, $good === Hub_Urls::return_url( $good, $site, $reference ) ); }
	$bad = array(
		str_replace( 'shop.local', 'a.manager.local', $plain ), str_replace( 'shop.local', 'other.local', $plain ), str_replace( 'shop.local', 'shop.local.evil.local', $plain ),
		str_replace( 'https:', 'http:', $plain ), str_replace( 'shop.local', 'shop.local:8443', $plain ), str_replace( 'shop.local', 'user@shop.local', $plain ),
		$plain . '#fragment', str_replace( '/wc-api/', '/%2e%2e/wc-api/', $pretty ), str_replace( '/wc-api/', '//wc-api/', $pretty ),
		str_replace( 'wc-api/pc_panapay_return/', 'wp-admin/', $pretty ), str_replace( 'pc_panapay_return', 'other_callback', $plain ),
		$plain . '&redirect_to=https%3A%2F%2Fevil.local', $plain . '&rest_route=%2Fpeptide-ops%2Fv1%2Fdownload', $plain . '&token=' . $token,
		str_replace( $token, 'bad', $plain ), str_replace( '-42-', '-43-', $plain ), str_replace( 'https:', '', $plain ), str_replace( '/wc-api/', '/wc-api%5c/', $pretty ),
	);
	foreach ( $bad as $url ) { check( 'reject unsafe/nonregistered callback ' . $checks, rejected( static fn() => Hub_Urls::return_url( $url, $site, $reference ) ) ); }
	$legacy = array( 'url' => 'http://127.0.0.1:18080/shop/', 'dual_domain' => false );
	$legacy_url = 'http://127.0.0.1:18080/shop/?wc-api=pc_panapay_return&' . $query;
	check( 'legacy local subdirectory keeps original callback', $legacy_url === Hub_Urls::return_url( $legacy_url, $legacy, $reference ) );
	check( 'legacy sibling path rejected', rejected( static fn() => Hub_Urls::return_url( str_replace( '/shop/', '/other/', $legacy_url ), $legacy, $reference ) ) );
	$next = array_replace( $site, array( 'public_url' => 'https://new-shop.local/' ) );
	check( 'public change leaves event origin fixed', Hub_Urls::management( $next ) === Hub_Urls::management( $site ) );
	check( 'old frozen return fails closed after public change', rejected( static fn() => Hub_Urls::return_url( $plain, $next, $reference ) ) );
	check( 'new public accepts only its own callback', str_replace( 'shop.local', 'new-shop.local', $plain ) === Hub_Urls::return_url( str_replace( 'shop.local', 'new-shop.local', $plain ), $next, $reference ) );
	$p = array( 'order_id' => $reference, 'currency' => 'USD', 'amount' => '1.00', 'email' => 'synthetic@example.invalid', 'phone' => '+12025550123', 'ip' => '', 'accept_language' => '', 'user_agent' => '', 'return_url' => $plain, 'products' => array( array( 'name' => 'Synthetic item', 'quantity' => 1, 'price' => '1.00', 'total' => '1.00', 'image' => '', 'url' => '' ) ) );
	foreach ( array( 'billing', 'shipping' ) as $type ) { foreach ( array( 'firstname', 'lastname', 'company', 'address_1', 'address_2', 'postcode', 'city', 'state', 'country' ) as $field ) { $p[ $type . '_' . $field ] = 'country' === $field ? 'US' : 'Synthetic'; } }
	$method = new \ReflectionMethod( Hub::class, 'payload' );
	check( 'actual Hub payload accepts buyer return distinct from management', $plain === $method->invoke( null, $p, $site )['return_url'] );
	check( 'actual Hub payload rejects manager as buyer return', rejected( static fn() => $method->invoke( null, array_replace( $p, array( 'return_url' => str_replace( 'shop.local', 'a.manager.local', $plain ) ) ), $site ) ) );
	$GLOBALS['site'] = $next;
	$GLOBALS['event'] = array( 'event_id' => 'synthetic-event', 'ref' => 'synthetic-ref', 'status' => 'SUCCEEDED', 'transaction_box' => 'synthetic-transaction' );
	$GLOBALS['attempt'] = array( 'site_id' => 1, 'site_uuid' => $uuid, 'local_reference' => $reference, 'link_key_box' => str_repeat( 'd', 64 ), 'account_id' => 'default' );
	check( 'actual Hub outbox relay delivers one synthetic event', 1 === Hub::retry_pending() && 'delivered' === $GLOBALS['delivery'][1] );
	check( 'actual Hub relay never posts to changed buyer origin', str_starts_with( $GLOBALS['http'][0][0], 'https://a.manager.local/?rest_route=%2Fpc-panapay%2Fv1%2Fstore%2Fevent' ) && 1 === count( $GLOBALS['http'] ) );
	echo 'PASS ', $checks, " pure Panapay HQ address checks; only simulated HTTP boundary.\n";
}
