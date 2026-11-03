<?php
/** Actual Hub return/signature/frozen URL/view code with synthetic read-only boundaries. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' ); define( 'DAY_IN_SECONDS', 86400 );
	final class WP_REST_Request { public function __construct( private array $query ) {} public function get_query_params(): array { return $this->query; } }
	final class WP_REST_Response {
		public function __construct( private $data = null, private int $status = 200, private array $headers = array() ) {}
		public function get_data() { return $this->data; }
		public function get_status(): int { return $this->status; }
		public function get_headers(): array { return $this->headers; }
	}
	function wp_get_environment_type(): string { return 'production'; }
	function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
	function trailingslashit( string $url ): string { return rtrim( $url, '/' ) . '/'; }
	function add_query_arg( array $fields, string $url ): string {
		$parts = parse_url( $url ); $query = array(); parse_str( $parts['query'] ?? '', $query );
		$base = $parts['scheme'] . '://' . $parts['host'] . ( $parts['path'] ?? '/' );
		return $base . '?' . http_build_query( array_replace( $query, $fields ), '', '&', PHP_QUERY_RFC3986 );
	}
	function wp_safe_remote_post( ...$args ) { ++$GLOBALS['http_calls']; throw new \RuntimeException( 'Forbidden HTTP' ); }
	function wp_safe_remote_get( ...$args ) { ++$GLOBALS['http_calls']; throw new \RuntimeException( 'Forbidden HTTP' ); }
	function wp_remote_post( ...$args ) { ++$GLOBALS['http_calls']; throw new \RuntimeException( 'Forbidden HTTP' ); }
	function wp_remote_get( ...$args ) { ++$GLOBALS['http_calls']; throw new \RuntimeException( 'Forbidden HTTP' ); }
	function wp_redirect( string $url, int $status, string $by ): void {
		++$GLOBALS['redirects'];
		if ( ! $GLOBALS['success_mode'] ) { throw new \RuntimeException( 'Unexpected redirect in negative case' ); }
		$parts = parse_url( $url ); $fields = array(); parse_str( $parts['query'] ?? '', $fields );
		check( 'redirect only after provider signature validation', in_array( 'hub-provider', $GLOBALS['decrypt_purposes'], true ) );
		check( 'redirect keeps frozen host/path', 'www.merchant.example.com' === $parts['host'] && '/wc-api/pc_panapay_return/' === $parts['path'] );
		check( 'original local reference and browser token retained', $GLOBALS['local_ref'] === ( $fields['reference'] ?? null ) && str_repeat( 'b', 48 ) === ( $fields['token'] ?? null ) );
		check( 'status and transaction forwarded', $GLOBALS['success_status'] === ( $fields['status'] ?? null ) && $GLOBALS['success_tx'] === ( $fields['transaction_id'] ?? null ) );
		check( 'provider return re-signed with frozen store link key', \VialCove\Panapay\Signature::verify( array( 'transaction_id' => $fields['transaction_id'], 'status' => $fields['status'] ), $fields['sign'], 'synthetic-link-key' ) );
		check( 'browser-supplied redirect fields never forwarded', ! isset( $fields['store_home'] ) && ! isset( $fields['return_url'] ) && ! isset( $fields['redirect'] ) && ! str_contains( $url, 'attacker' ) );
		check( 'redirect performs no financial write or HTTP', 0 === $GLOBALS['financial_calls'] && 0 === $GLOBALS['http_calls'] );
		echo json_encode( array( 'passed' => true, 'checks' => $GLOBALS['checks'], 'mode' => $GLOBALS['success_status'], 'redirect' => true, 'http' => 0, 'financial_writes' => 0 ), JSON_THROW_ON_ERROR ) . "\n";
	}
}
namespace PeptideHq {
	final class Sites { public static function get( int $id ): ?array { return 2 === $id ? $GLOBALS['fixture_site'] : null; } }
	final class Site_Lifecycle { public static function active( int $id ): bool { return 2 === $id && $GLOBALS['site_active']; } }
}
namespace VialCove\Panapay {
	function time(): int { return $GLOBALS['clock']; }
	function nocache_headers(): void { ++$GLOBALS['cache_headers']; }
	function header( string $value, bool $replace = true ): void { $GLOBALS['header_names'][] = explode( ':', $value, 2 )[0]; }
	final class Hub_Store {
		public static function get( string $reference ): ?array { ++$GLOBALS['row_reads']; return $GLOBALS['provider_ref'] === $reference ? $GLOBALS['fixture_row'] : null; }
		public static function receive( ...$args ): void { ++$GLOBALS['financial_calls']; throw new \RuntimeException( 'Forbidden payment mutation' ); }
	}
	final class Crypto {
		public static function decrypt( string $box, string $purpose ): string {
			$GLOBALS['decrypt_purposes'][] = $purpose;
			if ( $GLOBALS['decrypt_fails'] ) { throw new \RuntimeException( 'Synthetic private exception message' ); }
			return match ( $purpose ) { 'hub-provider' => 'synthetic-provider-key', 'hub-link' => 'synthetic-link-key', 'hub-return' => $GLOBALS['frozen_target'], default => throw new \RuntimeException( 'Unexpected purpose' ) };
		}
	}
	final class Configuration {
		public static function origin( string $url ): string {
			$p = \parse_url( $url );
			if ( ! is_array( $p ) || 'https' !== ( $p['scheme'] ?? '' ) || ! is_string( $p['host'] ?? null ) || isset( $p['user'] ) || isset( $p['pass'] ) ) { throw new \RuntimeException( 'Invalid synthetic origin' ); }
			return rtrim( $url, '/' );
		}
	}
	final class Database { public static function open(): object { ++$GLOBALS['financial_calls']; throw new \RuntimeException( 'Forbidden business database access' ); } }
}
namespace {
	foreach ( array( 'protocol-error', 'signature', 'hub-protocol', 'session-service', 'hub-urls', 'payment-return-view', 'hub' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
	require dirname( __DIR__, 2 ) . '/peptide-hq/includes/class-site-urls.php';
	use VialCove\Panapay\{Hub, Signature};
	$checks = 0; $clock = 2000000000; $provider_ref = 'PH' . str_repeat( 'c', 32 );
	$local_ref = 'PPab12cd34-11737-' . str_repeat( 'a', 32 );
	$http_calls = 0; $financial_calls = 0; $redirects = 0; $cache_headers = 0; $row_reads = 0; $header_names = array();
	$success_mode = in_array( '--success', $argv ?? array(), true ) || in_array( '--success-failed', $argv ?? array(), true );
	$success_status = in_array( '--success-failed', $argv ?? array(), true ) ? 'FAILED' : 'SUCCEEDED';
	$success_tx = 'FAILED' === $success_status ? '' : 'synthetic-transaction-sentinel';
	function check( string $label, bool $pass ): void { ++$GLOBALS['checks']; if ( ! $pass ) { throw new \RuntimeException( 'FAIL: ' . $label ); } }
	function reset_case(): void {
		$GLOBALS['fixture_site'] = array( 'id' => 2, 'uuid' => '11111111-2222-4333-8444-555555555555', 'dual_domain' => true,
			'public_url' => 'https://www.merchant.example.com/', 'management_url' => 'https://manager.example.com/' );
		$GLOBALS['fixture_row'] = array( 'ref' => $GLOBALS['provider_ref'], 'site_id' => 2, 'site_uuid' => $GLOBALS['fixture_site']['uuid'],
			'order_id' => 11737, 'created_at' => $GLOBALS['clock'] - 60, 'local_reference' => $GLOBALS['local_ref'], 'return_token_hash' => hash( 'sha256', 'synthetic-browser-token' ),
			'secret_box' => 'synthetic-provider-box', 'link_key_box' => 'synthetic-link-box', 'return_box' => 'synthetic-target-box', 'test_mode' => 0 );
		$GLOBALS['site_active'] = true; $GLOBALS['decrypt_fails'] = false; $GLOBALS['decrypt_purposes'] = array();
		$GLOBALS['frozen_target'] = 'https://www.merchant.example.com/wc-api/pc_panapay_return/?reference=' . $GLOBALS['local_ref'] . '&token=' . str_repeat( 'b', 48 );
	}
	function query( array $override = array() ): array {
		$p = array_replace( array( 'reference' => $GLOBALS['provider_ref'], 'token' => 'synthetic-browser-token', 'status' => 'SUCCEEDED', 'transaction_id' => 'synthetic-transaction-sentinel' ), $override );
		$p['sign'] = Signature::generate( array( 'transaction_id' => $p['transaction_id'], 'status' => $p['status'] ), 'synthetic-provider-key' );
		return $p;
	}
	function failure( string $label, array $p, ?string $home ): void {
		$before_redirects = $GLOBALS['redirects'];
		$result = Hub::returned( new WP_REST_Request( $p ) );
		check( $label . ' is HTTP400 view marker', $result instanceof WP_REST_Response && 400 === $result->get_status() && 1 === ( $result->get_data()['pc_panapay_return_view'] ?? null ) );
		check( $label . ' uses only expected home', $home === $result->get_data()['store_home'] );
		check( $label . ' never redirects', $before_redirects === $GLOBALS['redirects'] );
		check( $label . ' returns exact public-safe shape', array( 'pc_panapay_return_view', 'store_home' ) === array_keys( $result->get_data() ) );
		$encoded = json_encode( $result->get_data(), JSON_THROW_ON_ERROR );
		foreach ( array( 'synthetic-browser-token', 'synthetic-transaction-sentinel', 'synthetic-provider-key', 'synthetic-link-key', 'Synthetic private exception', $GLOBALS['provider_ref'], $GLOBALS['local_ref'], 'attacker.example.com' ) as $private ) {
			check( $label . ' excludes private or forged value', ! str_contains( $encoded, $private ) );
		}
	}
	reset_case();
	if ( $success_mode ) {
		$p = query( array( 'status' => $success_status, 'transaction_id' => $success_tx, 'store_home' => 'https://attacker.example.com/', 'return_url' => 'https://attacker.example.com/' ) );
		Hub::returned( new WP_REST_Request( $p ) );
		throw new \RuntimeException( 'Valid return did not reach native redirect/exit' );
	}
	foreach ( array( 'reference', 'token' ) as $field ) {
		reset_case(); $p = query(); unset( $p[ $field ] ); failure( 'missing binding ' . $field, $p, null );
		check( 'missing binding does not decrypt', array() === $decrypt_purposes );
		reset_case(); $p = query(); $p[ $field ] = array( 'not-string' ); failure( 'typed binding ' . $field, $p, null );
		check( 'typed binding does not decrypt', array() === $decrypt_purposes );
	}
	reset_case(); failure( 'unknown row', query( array( 'reference' => 'PH' . str_repeat( 'd', 32 ) ) ), null );
	reset_case(); failure( 'bad browser token', query( array( 'token' => 'wrong' ) ), null ); check( 'bad token does not read secret', array() === $decrypt_purposes );
	reset_case(); $fixture_row['created_at'] = $clock - 604801; failure( 'expired binding', query(), null ); check( 'expired binding does not read secret', array() === $decrypt_purposes );
	reset_case(); $fixture_site = null; failure( 'missing registered site', query(), null );
	reset_case(); $fixture_row['site_uuid'] = 'other-site-identity'; failure( 'changed registered UUID', query(), null );
	reset_case(); $site_active = false; failure( 'retained site', query(), null );
	reset_case(); $fixture_site['public_url'] = 'https://user:private@attacker.example.com/'; failure( 'invalid registered home', query(), null );
	foreach ( array( 'status', 'transaction_id', 'sign' ) as $field ) {
		reset_case(); $p = query(); unset( $p[ $field ] ); failure( 'missing provider field ' . $field, $p, 'https://www.merchant.example.com/' );
		reset_case(); $p = query(); $p[ $field ] = str_repeat( 'x', 513 ); failure( 'oversized provider field ' . $field, $p, 'https://www.merchant.example.com/' );
	}
	reset_case(); failure( 'invalid status', query( array( 'status' => 'UNKNOWN' ) ), 'https://www.merchant.example.com/' );
	reset_case(); failure( 'success missing transaction', query( array( 'transaction_id' => '' ) ), 'https://www.merchant.example.com/' );
	reset_case(); failure( 'control character in transaction', query( array( 'transaction_id' => "private\ntransaction" ) ), 'https://www.merchant.example.com/' );
	reset_case(); $p = query(); $p['sign'] = str_repeat( '0', 32 ); failure( 'bad provider signature', $p, 'https://www.merchant.example.com/' );
	check( 'bad signature never reads link key or frozen target', array( 'hub-provider' ) === $decrypt_purposes );
	reset_case(); $p = query( array( 'store_home' => 'https://attacker.example.com/', 'return_url' => 'https://attacker.example.com/', 'redirect' => 'https://attacker.example.com/' ) ); $p['sign'] = str_repeat( '0', 32 ); failure( 'forged home parameter', $p, 'https://www.merchant.example.com/' );
	reset_case(); $decrypt_fails = true; failure( 'unavailable frozen key', query(), 'https://www.merchant.example.com/' );
	reset_case(); $frozen_target = 'https://attacker.example.com/wc-api/pc_panapay_return/?reference=wrong&token=' . str_repeat( 'b', 48 ); failure( 'invalid frozen local reference', query(), 'https://www.merchant.example.com/' );
	check( 'GET failures never receive financial notification or HTTP', 0 === $financial_calls && 0 === $http_calls );
	check( 'negative suite never redirects', 0 === $redirects );
	echo json_encode( array( 'passed' => true, 'checks' => $checks, 'http' => 0, 'financial_writes' => 0 ), JSON_THROW_ON_ERROR ) . "\n";
}
