<?php
/** Actual Hub, URL/payload validation and Signature with in-memory DB/provider boundaries. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	class WP_Error { public function __construct( public string $code, public string $message, public array $data ) {} }
	class WP_REST_Response { public function __construct( public array $data ) {} public function header( ...$args ): void {} }
	class WP_REST_Request { public function __construct( private array $input ) {} public function get_json_params(): array { return $this->input; } }
	function get_option( string $key, $default = false ) {
		if ( 'pc_panapay_provider_site_bindings' !== $key ) { return $default; }
		$GLOBALS['events'][] = 'provider_site'; return $GLOBALS['bindings'];
	}
	function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
	function trailingslashit( string $url ): string { return rtrim( $url, '/' ) . '/'; }
	function wp_get_environment_type(): string { return 'production'; }
	function home_url( string $path = '' ): string { return 'https://hq.example.invalid/' . ltrim( $path, '/' ); }
	function wp_json_encode( $data ): string { return json_encode( $data, JSON_THROW_ON_ERROR ); }
	function is_email( string $email ): bool { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
	function add_query_arg( $key, $value, ?string $url = null ): string {
		if ( is_array( $key ) ) { $url = $value; $fields = $key; } else { $fields = array( $key => $value ); }
		return $url . '?' . http_build_query( $fields );
	}
}
namespace VialCove\Panapay {
	final class Configuration { public static function origin( string $url ): string { return rtrim( $url, '/' ); } }
	final class Crypto { public static function encrypt( string $value, string $purpose ): string { return 'synthetic-' . $purpose; } }
	final class Database {
		public static function open(): object {
			return new class {
				public string $last_error = '';
				public function prepare( string $query, ...$args ): string { return $query; }
				public function get_var( string $query ) { return null; }
				public function close(): void {}
			};
		}
	}
	final class Hub_Accounts { public static function load( string $account ): array { return $GLOBALS['config']; } }
	final class Hub_Store {
		public static function table( string $name ): string { return 'synthetic_' . $name; }
		public static function reserve( array $record, string $legacy_hash ): array {
			$GLOBALS['events'][] = 'reserve';
			if ( ! empty( $GLOBALS['reserve_conflict'] ) ) { throw new Protocol_Error( 'order_creation_conflict', 409 ); }
			$GLOBALS['row'] = array_replace( $record, array( 'state' => 'unknown' ) ); return array( 'winner' => $GLOBALS['winner'] );
		}
		public static function finish_create( array $record, array $response ): void { $GLOBALS['events'][] = 'finish'; }
		public static function find( int $site, string $reference ): array { return $GLOBALS['row']; }
	}
	final class Provider_Client {
		public static function create( array $payload, array $config ): array { $GLOBALS['events'][] = 'provider'; $GLOBALS['outbound'][] = $payload; return array( 'state' => 'unknown' ); }
	}
}
namespace {
	require dirname( __DIR__, 2 ) . '/peptide-hq/includes/class-site-urls.php';
	foreach ( array( 'protocol-error', 'hub-protocol', 'session-service', 'signature', 'hub-urls', 'hub' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
	use VialCove\Panapay\{Hub, Hub_Urls, Signature, Protocol_Error};
	$checks = 0; $events = array(); $outbound = array(); $winner = true;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new \RuntimeException( 'FAIL ' . $name ); } }
	function rejected( callable $call ): bool { try { $call(); return false; } catch ( Protocol_Error $error ) { return 'provider_site_invalid' === $error->getMessage() && 503 === $error->getCode(); } }
	$account = str_repeat( 'a', 32 ); $uuid = '11111111-1111-4111-8111-111111111111';
	$site = array( 'id' => 7, 'uuid' => $uuid, 'url' => 'https://manager.example.invalid/', 'management_url' => 'https://manager.example.invalid/', 'public_url' => 'https://s0001.vialcovepeptides.com/', 'dual_domain' => true );
	$key = $account . ':' . $uuid;
	$record = array( 'site_id' => 7, 'public_host' => 's0001.vialcovepeptides.com', 'provider_site' => 'vialcovepeptides.com' );
	$valid = array( 'version' => 1, 'bindings' => array( $key => $record ) );
	$method = new \ReflectionMethod( Hub::class, 'provider_site' );
	$bindings = null;
	check( 'absent option preserves registered URL exactly', $site['public_url'] === $method->invoke( null, $site, $account ) );
	$bindings = $valid;
	check( 'explicit parent domain binding returns bare root', 'vialcovepeptides.com' === $method->invoke( null, $site, $account ) );
	$bindings['bindings'][ $key ]['provider_site'] = $record['public_host'];
	check( 'explicit exact public host allowed', $record['public_host'] === $method->invoke( null, $site, $account ) );
	$bindings = $valid;
	check( 'other account does not inherit binding', $site['public_url'] === $method->invoke( null, $site, str_repeat( 'b', 32 ) ) );
	check( 'legacy default does not inherit account binding', $site['public_url'] === $method->invoke( null, $site, 'default' ) );
	check( 'other UUID does not inherit binding', $site['public_url'] === $method->invoke( null, array_replace( $site, array( 'uuid' => '22222222-2222-4222-8222-222222222222' ) ), $account ) );
	$bindings = array( 'version' => 1, 'bindings' => array( 'unrelated:identity' => 'invalid unrelated record' ) );
	check( 'unrelated malformed record never becomes current binding', $site['public_url'] === $method->invoke( null, $site, $account ) );
	foreach ( array( false, 'invalid', array(), array( 'version' => '1', 'bindings' => array() ), array( 'version' => 2, 'bindings' => array() ), array( 'version' => 1, 'bindings' => null ), array( 'version' => 1, 'bindings' => array(), 'extra' => true ) ) as $bad ) {
		$bindings = $bad; check( 'malformed option rejected ' . $checks, rejected( static fn() => $method->invoke( null, $site, $account ) ) );
	}
	foreach ( array( null, array(), array_replace( $record, array( 'site_id' => '7' ) ), array_replace( $record, array( 'site_id' => 8 ) ), array_replace( $record, array( 'public_host' => 'old.vialcovepeptides.com' ) ), array_merge( $record, array( 'extra' => true ) ) ) as $bad ) {
		$bindings = array( 'version' => 1, 'bindings' => array( $key => $bad ) ); check( 'current identity record strictly checked ' . $checks, rejected( static fn() => $method->invoke( null, $site, $account ) ) );
	}
	foreach ( array( 'evilvialcovepeptides.com', 'vialcovepeptides.com.attacker.invalid', 'com', 'https://vialcovepeptides.com', 'vialcovepeptides.com/', 'vialcovepeptides.com:443', 'vialcovepeptides.com?token=x', 'Vialcovepeptides.com', 'vialcovepeptides..com', '-vialcovepeptides.com', '肽.com', str_repeat( 'a', 254 ) . '.com' ) as $domain ) {
		$bindings = $valid; $bindings['bindings'][ $key ]['provider_site'] = $domain;
		check( 'nonbare or unauthorized domain rejected ' . $checks, rejected( static fn() => $method->invoke( null, $site, $account ) ) );
	}
	$bindings = $valid;
	check( 'public-host rename invalidates old scoped binding', rejected( static fn() => $method->invoke( null, array_replace( $site, array( 'public_url' => 'https://renamed.vialcovepeptides.com/' ) ), $account ) ) );
	$config = array( 'all_sites' => true, 'sites' => array(), 'enabled' => true, 'archived' => false, 'gateway' => 'https://provider.example.invalid', 'mchid' => 'synthetic-merchant', 'secret' => 'synthetic-secret', 'tag' => '', 'mode' => '2', 'payment_hosts' => array(), 'test_mode' => false );
	$reference = 'PP12345678-42-' . str_repeat( 'c', 32 );
	$input = array( 'account_id' => $account, 'order_id' => $reference, 'currency' => 'USD', 'amount' => '1.00', 'email' => 'synthetic@example.invalid', 'phone' => '+12025550123', 'ip' => '', 'accept_language' => '', 'user_agent' => '',
		'return_url' => $site['public_url'] . '?wc-api=pc_panapay_return&reference=' . $reference . '&token=' . str_repeat( 'd', 48 ),
		'products' => array( array( 'name' => 'Synthetic item', 'quantity' => 1, 'price' => '1.00', 'total' => '1.00', 'image' => $site['public_url'] . 'synthetic.png', 'url' => $site['public_url'] . 'product/synthetic' ) ) );
	foreach ( array( 'billing', 'shipping' ) as $group ) { foreach ( array( 'firstname', 'lastname', 'company', 'address_1', 'address_2', 'postcode', 'city', 'state', 'country' ) as $field ) { $input[ $group . '_' . $field ] = 'country' === $field ? 'US' : 'Synthetic'; } }
	function run_create( array $input, array $site ) {
		$GLOBALS['events'] = array(); $GLOBALS['outbound'] = array();
		$request = new \WP_REST_Request( $input );
		$auth = new \ReflectionProperty( Hub::class, 'auth' );
		$auth->setValue( null, array( spl_object_id( $request ) => array( 'site' => $site, 'key' => str_repeat( 'e', 64 ), 'nonce' => str_repeat( 'f', 32 ) ) ) );
		return Hub::create( $request );
	}
	$bindings = $valid; $bindings['bindings'][ $key ]['public_host'] = 'wrong.example.invalid';
	$result = run_create( $input, $site );
	check( 'invalid binding stops before any reservation or provider call', $result instanceof \WP_Error && 503 === $result->data['status'] && array( 'provider_site' ) === $events && array() === $outbound );
	$bindings = $valid;
	$result = run_create( $input, array_replace( $site, array( 'id' => 8 ) ) );
	check( 'changed site id stops before reservation or provider call', $result instanceof \WP_Error && 503 === $result->data['status'] && array( 'provider_site' ) === $events && array() === $outbound );
	$result = run_create( array_replace( $input, array( 'phone' => '' ) ), $site );
	check( 'payload validated before binding lookup and reservation', $result instanceof \WP_Error && 400 === $result->data['status'] && array() === $events );
	$result = run_create( $input, $site );
	check( 'actual create validates binding before reserve and sends once', $result instanceof \WP_REST_Response && array( 'provider_site', 'reserve', 'provider', 'finish' ) === $events && 1 === count( $outbound ) );
	$p = $outbound[0];
	check( 'winner uses only explicitly bound bare provider site', 'vialcovepeptides.com' === $p['site'] && $reference !== $p['order_id'] );
	$fields = array_intersect_key( $p, array_flip( array( 'mchid', 'currency', 'amount', 'site', 'order_id' ) ) );
	check( 'five-field signature uses final provider site', Signature::verify( $fields, $p['sign'], $config['secret'] ) && ! Signature::verify( array_replace( $fields, array( 'site' => $site['public_url'] ) ), $p['sign'], $config['secret'] ) );
	check( 'notify and return addresses retain HQ routing', str_starts_with( $p['notify_url'], home_url() . '?rest_route=' ) && str_starts_with( $p['return_url'], home_url() . '?rest_route=' ) && $site['public_url'] === Hub_Urls::public_url( $site ) );
	check( 'product and image URLs are untouched', $input['products'] === $p['products'] );
	$winner = false; $result = run_create( $input, $site );
	check( 'existing reserved attempt never retries or changes channel', $result instanceof \WP_REST_Response && array( 'provider_site', 'reserve' ) === $events && array() === $outbound && 'unknown' === $result->data['data']->state );
	$winner = true; $reserve_conflict = true;
	$new_reference = str_replace( str_repeat( 'c', 32 ), str_repeat( 'b', 32 ), $reference );
	$result = run_create( array_replace( $input, array( 'order_id' => $new_reference, 'return_url' => str_replace( $reference, $new_reference, $input['return_url'] ) ) ), $site );
	check( 'same-order different-reference reservation conflict never reaches provider', $result instanceof \WP_Error && 409 === $result->data['status'] && array( 'provider_site', 'reserve' ) === $events && array() === $outbound );
	$reserve_conflict = false;
	$winner = true; $bindings = null; $result = run_create( $input, $site );
	check( 'actual unbound create preserves complete original site value', $result instanceof \WP_REST_Response && $site['public_url'] === $outbound[0]['site'] );
	echo json_encode( array( 'status' => 'passed', 'checks' => $checks, 'database' => 'in-memory boundary', 'provider_http' => 'synthetic boundary only', 'real_requests' => 0 ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}
