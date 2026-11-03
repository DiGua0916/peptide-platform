<?php
/** Real URL/crypto/return code with synthetic in-memory rows and an intercepted redirect. No WP bootstrap, DB, HTTP or payment. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'PC_PANAPAY_ENCRYPTION_KEY', base64_encode( str_repeat( "\x42", 32 ) ) ); // Synthetic; never consult a real environment key.
	final class WP_Error { public function __construct( public string $code, public string $message = '', public array $data = array() ) {} }
	final class WP_REST_Request {
		public function __construct( private array $query ) {}
		public function get_query_params(): array { return $this->query; }
	}
	final class Redirect_Observed extends \RuntimeException {}
	function wp_parse_url( string $url, int $part = -1 ) { return parse_url( $url, $part ); }
	function trailingslashit( string $s ): string { return rtrim( $s, '/' ) . '/'; }
	function wp_get_environment_type(): string { return 'local'; }
	function wp_salt( string $scheme ): string { return 'synthetic-crypto-key-for-frozen-return-only-' . $scheme; }
	function nocache_headers(): void {}
	function add_query_arg( $key, $value, ?string $url = null ): string {
		if ( is_array( $key ) ) { $args = $key; $url = $value; }
		else { $args = array( $key => $value ); }
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}
	function wp_redirect( string $url, int $status = 302, string $by = '' ): void {
		$GLOBALS['redirects'][] = array( 'url' => $url, 'status' => $status );
		// Prevent Hub::returned's exit while observing the real validated redirect boundary.
		throw new Redirect_Observed();
	}
	function wp_safe_remote_post( ...$args ) { ++$GLOBALS['external_calls']; throw new \RuntimeException( 'Forbidden transport.' ); }
	function wp_mail( ...$args ) { ++$GLOBALS['mail_calls']; throw new \RuntimeException( 'Forbidden mail.' ); }
}
namespace PeptideHq {
	final class Sites { public static function get( int $id ): ?array { return $GLOBALS['current_site']; } }
}
namespace VialCove\Panapay {
	final class Hub_Store {
		public static function get( string $ref ): ?array { return $ref === $GLOBALS['row']['ref'] ? $GLOBALS['row'] : null; }
		public static function receive( ...$args ): void { ++$GLOBALS['financial_writes']; throw new \RuntimeException( 'Forbidden financial write.' ); }
		public static function reserve( ...$args ): void { ++$GLOBALS['financial_writes']; throw new \RuntimeException( 'Forbidden creation.' ); }
		public static function finish_create( ...$args ): void { ++$GLOBALS['financial_writes']; throw new \RuntimeException( 'Forbidden creation.' ); }
	}
}
namespace {
	require dirname( __DIR__, 2 ) . '/peptide-hq/includes/class-site-urls.php';
	foreach ( array( 'protocol-error', 'hub-protocol', 'session-service', 'signature', 'crypto', 'hub-urls', 'hub' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
	use VialCove\Panapay\{Crypto, Hub, Hub_Urls, Signature};
	$checks = 0; $GLOBALS['external_calls'] = $GLOBALS['mail_calls'] = $GLOBALS['financial_writes'] = 0;
	function check( string $label, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new \RuntimeException( 'FAIL ' . $label ); } }
	function rejects( callable $fn ): bool { try { $fn(); return false; } catch ( \Throwable $e ) { return true; } }
	$uuid = '11111111-1111-4111-8111-111111111111';
	$site = array( 'id' => 2, 'uuid' => $uuid, 'url' => 'https://s0001.manager.local/', 'management_url' => 'https://s0001.manager.local/', 'public_url' => 'https://www.shop.local/', 'dual_domain' => true );
	$reference = 'PP1234abcd-42-' . str_repeat( 'b', 32 );
	$store_token = str_repeat( 'c', 48 ); $hq_token = str_repeat( 'd', 48 ); $provider_secret = str_repeat( 'p', 64 );
	$plain = 'https://s0001.shop.local/?wc-api=pc_panapay_return&reference=' . $reference . '&token=' . $store_token;
	$pretty = 'https://s0001.shop.local/wc-api/pc_panapay_return/?reference=' . $reference . '&token=' . $store_token;
	$row = array( 'ref' => 'PH' . str_repeat( 'a', 32 ), 'site_id' => '2', 'site_uuid' => $uuid, 'order_id' => '42', 'local_reference' => $reference,
		'created_at' => time(), 'return_box' => Crypto::encrypt( $plain, 'hub-return' ), 'return_token_hash' => hash( 'sha256', $hq_token ),
		'secret_box' => Crypto::encrypt( $provider_secret, 'hub-provider' ), 'link_key_box' => Crypto::encrypt( str_repeat( 'l', 64 ), 'hub-link' ),
		'state' => 'unknown', 'checkout_box' => '', 'request_hash' => str_repeat( 'e', 64 ) );
	$before = serialize( array( $row, $site ) );
	check( 'existing frozen plain callback survives hostname change', Hub_Urls::attempt_return_url( $row, $site ) === $plain );
	check( 'new creation still rejects old hostname', rejects( static fn() => Hub_Urls::return_url( $plain, $site, $reference ) ) );
	$new_url = str_replace( 's0001.shop.local', 'www.shop.local', $plain );
	check( 'new creation accepts only current public callback', $new_url === Hub_Urls::return_url( $new_url, $site, $reference ) );
	check( 'existing rows and current registration not mutated', serialize( array( $row, $site ) ) === $before );
	foreach ( array( $pretty, rtrim( explode( '?', $pretty )[0], '/' ) . '?' . explode( '?', $pretty )[1], str_replace( 's0001.shop.local', 's0001.shop.local:443', $plain ) ) as $url ) {
		$r = array_replace( $row, array( 'return_box' => Crypto::encrypt( $url, 'hub-return' ) ) );
		check( 'existing exact native callback preserved ' . $checks, $url === Hub_Urls::attempt_return_url( $r, $site ) );
	}
	$unchanged = array_replace( $row, array( 'return_box' => Crypto::encrypt( $new_url, 'hub-return' ) ) );
	check( 'unchanged current origin still works', $new_url === Hub_Urls::attempt_return_url( $unchanged, $site ) );
	$aliases = array_replace( $site, array( 'aliases' => array( 'new-alias.local' ), 'parent_domain' => 'shop.local' ) );
	check( 'unrelated alias data does not alter frozen target', $plain === Hub_Urls::attempt_return_url( $row, $aliases ) );
	foreach ( array( 'shop.local', 'new-alias.local', 'other-shop.local', 'shop.local.evil.local', 's0001.manager.local' ) as $host ) {
		$attempted = str_replace( 's0001.shop.local', $host, $plain );
		check( 'creation does not infer parent or alias permission ' . $host, rejects( static fn() => Hub_Urls::return_url( $attempted, $aliases, $reference ) ) );
	}
	foreach ( array(
		array( 'site_id' => 3 ), array( 'site_id' => '2x' ), array( 'site_id' => '02' ), array( 'site_id' => 2.0 ), array( 'site_id' => true ),
		array( 'site_uuid' => '22222222-2222-4222-8222-222222222222' ), array( 'site_uuid' => '' ), array( 'site_uuid' => array() ),
		array( 'order_id' => 43 ), array( 'order_id' => '042' ), array( 'order_id' => false ), array( 'order_id' => '99999999999999999999999999' ),
		array( 'local_reference' => str_replace( '-42-', '-43-', $reference ) ), array( 'local_reference' => 'invalid' ),
		array( 'created_at' => time() - 7 * DAY_IN_SECONDS - 1 ), array( 'created_at' => 0 ), array( 'created_at' => false ),
		array( 'return_box' => '' ), array( 'return_box' => array() ),
	) as $change ) {
		check( 'invalid attempt binding rejected ' . $checks, rejects( static fn() => Hub_Urls::attempt_return_url( array_replace( $row, $change ), $site ) ) );
	}
	foreach ( array( array( 'id' => 3 ), array( 'id' => '2x' ), array( 'id' => 2.0 ), array( 'uuid' => '' ), array( 'uuid' => '22222222-2222-4222-8222-222222222222' ) ) as $change ) {
		check( 'invalid current site binding rejected ' . $checks, rejects( static fn() => Hub_Urls::attempt_return_url( $row, array_replace( $site, $change ) ) ) );
	}
	$bad_targets = array(
		str_replace( 'https:', 'http:', $plain ), str_replace( 's0001.shop.local', 's0001.shop.local:8443', $plain ),
		str_replace( 's0001.shop.local', 'user@s0001.shop.local', $plain ), $plain . '#fragment', $plain . "\n",
		str_replace( 's0001.shop.local', '127.0.0.1', $plain ), str_replace( 's0001.shop.local', 'localhost', $plain ),
		str_replace( '/?', '/subdir/?', $plain ), str_replace( '/wc-api/', '/%2e%2e/wc-api/', $pretty ), str_replace( '/wc-api/', '//wc-api/', $pretty ),
		str_replace( 'wc-api/pc_panapay_return/', 'wp-admin/', $pretty ), str_replace( 'pc_panapay_return', 'other_callback', $plain ),
		$plain . '&redirect_to=https%3A%2F%2Fevil.local', $plain . '&rest_route=%2Fpeptide-ops%2Fdownload', $plain . '&token=' . $store_token,
		str_replace( $store_token, str_repeat( 'z', 48 ), $plain ), str_replace( $store_token, str_repeat( 'c', 47 ), $plain ),
		str_replace( '-42-', '-43-', $plain ), str_replace( '/wc-api/', '/wc-api%5c/', $pretty ), str_replace( 'https:', '', $plain ),
	);
	foreach ( $bad_targets as $url ) {
		$r = array_replace( $row, array( 'return_box' => Crypto::encrypt( $url, 'hub-return' ) ) );
		check( 'authenticated malformed frozen callback rejected ' . $checks, rejects( static fn() => Hub_Urls::attempt_return_url( $r, $site ) ) );
	}
	$r = array_replace( $row, array( 'return_box' => Crypto::encrypt( $plain, 'checkout-url' ) ) );
	check( 'ciphertext context cannot be swapped', rejects( static fn() => Hub_Urls::attempt_return_url( $r, $site ) ) );
	$body = base64_decode( substr( $row['return_box'], 3 ), true ); $body[30] = chr( ord( $body[30] ) ^ 1 );
	$r = array_replace( $row, array( 'return_box' => 'v1:' . base64_encode( $body ) ) );
	check( 'ciphertext tampering rejected by real authenticated crypto', rejects( static fn() => Hub_Urls::attempt_return_url( $r, $site ) ) );
	$legacy = array_replace( $site, array( 'dual_domain' => false, 'url' => 'http://127.0.0.1:18080/shop/' ) );
	$legacy_url = 'http://127.0.0.1:18080/shop/?wc-api=pc_panapay_return&reference=' . $reference . '&token=' . $store_token;
	$r = array_replace( $row, array( 'return_box' => Crypto::encrypt( $legacy_url, 'hub-return' ) ) );
	check( 'unchanged legacy local installation remains supported', $legacy_url === Hub_Urls::attempt_return_url( $r, $legacy ) );
	$legacy_changed = array_replace( $legacy, array( 'url' => 'http://localhost:18080/shop/' ) );
	check( 'legacy HTTP hostname changes not implicitly authorized', rejects( static fn() => Hub_Urls::attempt_return_url( $r, $legacy_changed ) ) );
	$changed_path = array_replace( $site, array( 'dual_domain' => false, 'url' => 'https://www.shop.local/new-install/' ) );
	check( 'installation path changes are not hostname migrations', rejects( static fn() => Hub_Urls::attempt_return_url( $row, $changed_path ) ) );
	foreach ( array( str_repeat( 'a', 16 ), 'Legacy_store-1234', str_repeat( 'a', 32 ), str_repeat( 'b', 64 ) ) as $legacy_identity ) {
		$legacy_row = array_replace( $row, array( 'site_uuid' => $legacy_identity ) );
		$legacy_site = array_replace( $site, array( 'uuid' => $legacy_identity, 'dual_domain' => false, 'url' => 'https://s0001.shop.local/' ) );
		check( 'permission-compatible legacy identity retains same origin ' . $checks, $plain === Hub_Urls::attempt_return_url( $legacy_row, $legacy_site ) );
		$legacy_dual = array_replace( $site, array( 'uuid' => $legacy_identity ) );
		check( 'legacy identity cannot use dual-domain hostname exception ' . $checks, rejects( static fn() => Hub_Urls::attempt_return_url( $legacy_row, $legacy_dual ) ) );
		$legacy_changed = array_replace( $legacy_site, array( 'url' => 'https://www.shop.local/' ) );
		check( 'legacy identity cannot change hostname in legacy mode ' . $checks, rejects( static fn() => Hub_Urls::attempt_return_url( $legacy_row, $legacy_changed ) ) );
	}
	foreach ( array( str_repeat( 'a', 15 ), str_repeat( 'b', 65 ), 'invalid/identity-1234' ) as $invalid_identity ) {
		$invalid_row = array_replace( $row, array( 'site_uuid' => $invalid_identity ) );
		$invalid_site = array_replace( $site, array( 'uuid' => $invalid_identity, 'public_url' => 'https://s0001.shop.local/' ) );
		check( 'outside permission identity syntax rejected even at same origin ' . $checks, rejects( static fn() => Hub_Urls::attempt_return_url( $invalid_row, $invalid_site ) ) );
	}

	// Exercise the real Hub return handler, including provider signature/browser token
	// checks. Only its DB reads and final redirect are replaced with memory boundaries.
	$query = array( 'reference' => $row['ref'], 'token' => $hq_token, 'status' => 'SUCCEEDED', 'transaction_id' => 'synthetic-transaction' );
	$query['sign'] = Signature::generate( array_intersect_key( $query, array_flip( array( 'transaction_id', 'status' ) ) ), $provider_secret );
	$baseline_row = $row; $baseline_site = $site; $baseline_query = $query;
	$baseline_snapshot = serialize( array( $baseline_row, $baseline_site, $baseline_query ) );
	function call_return( array $r, array $s, array $q ): array {
		$saved = array();
		foreach ( array( 'row', 'current_site', 'redirects' ) as $key ) {
			$saved[ $key ] = array( 'exists' => array_key_exists( $key, $GLOBALS ), 'value' => $GLOBALS[ $key ] ?? null );
		}
		try {
			$GLOBALS['row'] = $r; $GLOBALS['current_site'] = $s; $GLOBALS['redirects'] = array();
			$before = serialize( array( $GLOBALS['row'], $GLOBALS['current_site'] ) );
			Hub::returned( new WP_REST_Request( $q ) );
			check( 'return handler leaves persisted memory rows unchanged ' . $GLOBALS['checks'], $before === serialize( array( $GLOBALS['row'], $GLOBALS['current_site'] ) ) );
			return $GLOBALS['redirects'];
		} finally {
			foreach ( $saved as $key => $entry ) {
				if ( $entry['exists'] ) { $GLOBALS[ $key ] = $entry['value']; }
				else { unset( $GLOBALS[ $key ] ); }
			}
		}
	}
	$redirects = call_return( $baseline_row, $baseline_site, $baseline_query );
	check( 'real return handler reaches original frozen hostname', count( $redirects ) === 1 && str_starts_with( $redirects[0]['url'], $plain . '&status=SUCCEEDED&' ) && $redirects[0]['status'] === 302 );
	check( 'return fixture restores absent site context and original row', ! array_key_exists( 'current_site', $GLOBALS ) && $row === $baseline_row );
	$failed_query = array_replace( $baseline_query, array( 'status' => 'FAILED', 'transaction_id' => '' ) );
	$failed_query['sign'] = Signature::generate( array_intersect_key( $failed_query, array_flip( array( 'transaction_id', 'status' ) ) ), $provider_secret );
	$redirects = call_return( $baseline_row, $baseline_site, $failed_query );
	check( 'failed browser hint also remains read only and bound', count( $redirects ) === 1 && str_starts_with( $redirects[0]['url'], $plain . '&status=FAILED&' ) );
	foreach ( array( 'redirect_to', 'return_url', 'target', 'url', 'public_url' ) as $extra ) {
		$redirects = call_return( $baseline_row, $baseline_site, array_replace( $baseline_query, array( $extra => 'https://evil.local/?token=attacker' ) ) );
		check( 'browser cannot supply an alternative target ' . $extra, count( $redirects ) === 1 && str_starts_with( $redirects[0]['url'], $plain . '&status=SUCCEEDED&' ) );
	}
	foreach ( array(
		array( 'token' => str_repeat( 'a', 48 ) ), array( 'token' => '' ), array( 'token' => array() ),
		array( 'reference' => 'PH' . str_repeat( 'f', 32 ) ), array( 'status' => 'REFUNDED' ),
		array( 'transaction_id' => 'different-transaction' ), array( 'sign' => str_repeat( '0', 64 ) ),
	) as $change ) {
		check( 'invalid browser authentication rejected ' . $checks, call_return( $baseline_row, $baseline_site, array_replace( $baseline_query, $change ) ) === array() );
	}
	foreach ( array(
		array( 'created_at' => time() - 7 * DAY_IN_SECONDS - 1 ), array( 'site_id' => 3 ), array( 'site_id' => '02' ),
		array( 'site_uuid' => '22222222-2222-4222-8222-222222222222' ), array( 'order_id' => 43 ),
		array( 'local_reference' => 'PP1234abcd-42-' . str_repeat( 'f', 32 ) ),
	) as $change ) {
		check( 'real return handler rejects isolated stale or mismatched row ' . $checks, call_return( array_replace( $baseline_row, $change ), $baseline_site, $baseline_query ) === array() );
		check( 'each invalid row leaves immutable and global baseline fresh ' . $checks, $baseline_snapshot === serialize( array( $baseline_row, $baseline_site, $baseline_query ) ) && $row === $baseline_row );
	}
	$legacy_identity = 'Legacy_store-1234';
	$legacy_row = array_replace( $baseline_row, array( 'site_uuid' => $legacy_identity ) );
	$legacy_site = array_replace( $baseline_site, array( 'uuid' => $legacy_identity, 'dual_domain' => false, 'url' => 'https://s0001.shop.local/' ) );
	$redirects = call_return( $legacy_row, $legacy_site, $baseline_query );
	check( 'real handler accepts same-origin legacy permission identity', count( $redirects ) === 1 && str_starts_with( $redirects[0]['url'], $plain . '&status=SUCCEEDED&' ) );
	check( 'real handler rejects legacy identity hostname migration', call_return( $legacy_row, array_replace( $baseline_site, array( 'uuid' => $legacy_identity ) ), $baseline_query ) === array() );
	check( 'successful return still works after all isolated negative rows', count( call_return( $baseline_row, $baseline_site, $baseline_query ) ) === 1 && $row === $baseline_row );
	check( 'no payment creation, notification application, HTTP or mail', $GLOBALS['financial_writes'] === 0 && $GLOBALS['external_calls'] === 0 && $GLOBALS['mail_calls'] === 0 );
	echo 'PASS ', $checks, " frozen return hostname checks; real crypto and return code, synthetic rows/redirect only.\n";
}
