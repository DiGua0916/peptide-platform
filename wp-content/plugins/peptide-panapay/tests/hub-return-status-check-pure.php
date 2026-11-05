<?php
/** Real HQ return routing with synthetic rows; no WP bootstrap, HTTP or financial writes. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	final class WP_REST_Request {
		public function __construct( private array $query ) {}
		public function get_query_params(): array { return $this->query; }
		public function get_method(): string { return 'GET'; }
		public function get_route(): string { return '/pc-panapay/v1/hub/return'; }
	}
	final class WP_REST_Response {
		public function __construct( private $data = null, private int $status = 200, private array $headers = array() ) {}
		public function get_data() { return $this->data; }
		public function get_status(): int { return $this->status; }
		public function get_headers(): array { return $this->headers; }
	}
	function wp_get_environment_type(): string { return 'production'; }
	function wp_parse_url( string $url, int $part = -1 ) { return parse_url( $url, $part ); }
	function trailingslashit( string $url ): string { return rtrim( $url, '/' ) . '/'; }
	function add_query_arg( array $fields, string $url ): string {
		$parts = parse_url( $url ); $query = array(); parse_str( $parts['query'] ?? '', $query );
		$base = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '/' );
		return $base . '?' . http_build_query( array_replace( $query, $fields ), '', '&', PHP_QUERY_RFC3986 );
	}
	function wp_safe_remote_post( ...$args ) { ++$GLOBALS['http_calls']; throw new \RuntimeException( 'Forbidden HTTP' ); }
	function wp_safe_remote_get( ...$args ) { ++$GLOBALS['http_calls']; throw new \RuntimeException( 'Forbidden HTTP' ); }
	function wp_remote_post( ...$args ) { ++$GLOBALS['http_calls']; throw new \RuntimeException( 'Forbidden HTTP' ); }
	function wp_remote_get( ...$args ) { ++$GLOBALS['http_calls']; throw new \RuntimeException( 'Forbidden HTTP' ); }
	function wp_mail( ...$args ) { ++$GLOBALS['mail_calls']; throw new \RuntimeException( 'Forbidden mail' ); }
	function wp_redirect( string $url, int $status, string $by ): void {
		++$GLOBALS['redirects'];
		check( 'redirect expected only in isolated scenario', is_string( $GLOBALS['scenario'] ) );
		$parts = parse_url( $url ); $fields = array(); parse_str( $parts['query'] ?? '', $fields );
		check( 'fixed redirect status', 302 === $status );
		check( 'exact original store origin/path', 'https' === $parts['scheme'] && 'www.merchant.example.com' === $parts['host'] && '/wc-api/pc_panapay_return/' === $parts['path'] );
		check( 'same native order and opaque local browser token', $GLOBALS['local_ref'] === ( $fields['reference'] ?? null ) && $GLOBALS['store_token'] === ( $fields['token'] ?? null ) );
		check( 'original rows are immutable', $GLOBALS['fixture_snapshot'] === serialize( array( $GLOBALS['fixture_row'], $GLOBALS['fixture_site'] ) ) );
		check( 'no creation, financial write, mail or HTTP', 0 === $GLOBALS['financial_calls'] && 0 === $GLOBALS['http_calls'] && 0 === $GLOBALS['mail_calls'] );
		check( 'browser target is never forwarded', ! isset( $fields['return_url'] ) && ! isset( $fields['store_home'] ) && ! isset( $fields['redirect'] ) && ! str_contains( $url, 'attacker' ) );
		if ( str_starts_with( $GLOBALS['scenario'], 'legacy-' ) ) {
			$expected_status = 'legacy-failed' === $GLOBALS['scenario'] ? 'FAILED' : 'SUCCEEDED';
			$expected_transaction = 'FAILED' === $expected_status ? '' : 'synthetic-provider-transaction';
			$keys = array_keys( $fields ); sort( $keys );
			check( 'legacy redirect has only original binding and signed hints', array( 'reference', 'sign', 'status', 'token', 'transaction_id' ) === $keys );
			check( 'legacy status/transaction preserved', $expected_status === ( $fields['status'] ?? null ) && $expected_transaction === ( $fields['transaction_id'] ?? null ) );
			check( 'legacy response re-signed with frozen link key', \VialCove\Panapay\Signature::verify( array( 'transaction_id' => $expected_transaction, 'status' => $expected_status ), $fields['sign'], 'synthetic-link-key' ) );
			check( 'legacy provider signature was checked', in_array( 'hub-provider', $GLOBALS['decrypt_purposes'], true ) );
		} else {
			$keys = array_keys( $fields ); sort( $keys );
			check( 'status check contains only its independent proof and binding', array( 'check', 'reference', 'sign', 'token' ) === $keys );
			check( 'status check is explicitly read only', '1' === ( $fields['check'] ?? null ) && ! isset( $fields['status'] ) && ! isset( $fields['transaction_id'] ) );
			$proof = array( 'purpose' => 'pc-panapay-return-check-v1', 'reference' => $GLOBALS['local_ref'], 'token' => $GLOBALS['store_token'] );
			check( 'proof uses frozen internal link key and exact purpose', \VialCove\Panapay\Signature::verify( $proof, $fields['sign'], 'synthetic-link-key' ) );
			check( 'forwarded proof passes the real store proof verifier', \VialCove\Panapay\Return_Context::verify_check( $fields, 'synthetic-link-key' ) );
			check( 'provider key cannot validate internal proof', ! \VialCove\Panapay\Signature::verify( $proof, $fields['sign'], 'synthetic-provider-key' ) );
			$changed = $proof; $changed['reference'] = str_replace( '-11737-', '-11738-', $changed['reference'] );
			check( 'proof cannot be replayed on another order', ! \VialCove\Panapay\Signature::verify( $changed, $fields['sign'], 'synthetic-link-key' ) );
			$changed = $proof; $changed['token'] = str_repeat( 'f', 48 );
			check( 'proof cannot be replayed with another token', ! \VialCove\Panapay\Signature::verify( $changed, $fields['sign'], 'synthetic-link-key' ) );
			$changed = $proof; $changed['purpose'] = 'pc-panapay-notify';
			check( 'proof cannot authorize a different purpose', ! \VialCove\Panapay\Signature::verify( $changed, $fields['sign'], 'synthetic-link-key' ) );
			check( 'proof is not a signed paid/failed hint', ! \VialCove\Panapay\Signature::verify( array( 'transaction_id' => 'synthetic-provider-transaction', 'status' => 'SUCCEEDED' ), $fields['sign'], 'synthetic-link-key' ) );
		}
		echo json_encode( array( 'passed' => true, 'scenario' => $GLOBALS['scenario'], 'checks' => $GLOBALS['checks'], 'redirect' => true, 'http' => 0, 'financial_writes' => 0 ), JSON_THROW_ON_ERROR ) . "\n";
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
		public static function get( string $reference ): ?array { return $GLOBALS['provider_ref'] === $reference ? $GLOBALS['fixture_row'] : null; }
		public static function receive( ...$args ): void { forbidden_financial(); }
		public static function reserve( ...$args ): void { forbidden_financial(); }
		public static function finish_create( ...$args ): void { forbidden_financial(); }
	}
	function forbidden_financial(): never { ++$GLOBALS['financial_calls']; throw new \RuntimeException( 'Forbidden business mutation' ); }
	final class Crypto {
		public static function decrypt( string $box, string $purpose = 'secret' ): string {
			$GLOBALS['decrypt_purposes'][] = $purpose;
			if ( ( 'hub-provider' === $purpose && $GLOBALS['provider_key_fails'] ) || ( 'hub-link' === $purpose && $GLOBALS['link_key_fails'] ) || ( 'hub-return' === $purpose && $GLOBALS['target_decrypt_fails'] ) ) { throw new \RuntimeException( 'Private synthetic decryption detail' ); }
			return match ( $purpose ) { 'hub-provider' => 'synthetic-provider-key', 'hub-link' => 'synthetic-link-key', 'hub-return' => $GLOBALS['frozen_target'], default => throw new \RuntimeException( 'Unexpected decryption purpose' ) };
		}
	}
	final class Configuration {
		public static function origin( string $url ): string {
			$p = \parse_url( $url );
			if ( ! is_array( $p ) || 'https' !== ( $p['scheme'] ?? '' ) || ! is_string( $p['host'] ?? null ) || isset( $p['user'] ) || isset( $p['pass'] ) ) { throw new \RuntimeException( 'Invalid synthetic origin' ); }
			return rtrim( $url, '/' );
		}
	}
	final class Database { public static function open(): object { forbidden_financial(); } }
}
namespace {
	foreach ( array( 'protocol-error', 'signature', 'hub-protocol', 'session-service', 'hub-urls', 'payment-return-view', 'return-context', 'hub' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
	require dirname( __DIR__, 2 ) . '/peptide-hq/includes/class-site-urls.php';
	use VialCove\Panapay\{Hub, Signature};
	$checks = 0; $clock = 2000000000; $provider_ref = 'PH' . str_repeat( 'c', 32 );
	$local_ref = 'PPab12cd34-11737-' . str_repeat( 'a', 32 ); $store_token = str_repeat( 'b', 48 ); $hq_token = str_repeat( 'd', 48 );
	$scenario = null;
	foreach ( array_slice( $argv, 1 ) as $arg ) { if ( str_starts_with( $arg, '--case=' ) ) { $scenario = substr( $arg, 7 ); } }
	$http_calls = $financial_calls = $mail_calls = $redirects = $cache_headers = 0; $header_names = array();
	function check( string $label, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new \RuntimeException( 'FAIL: ' . $label ); } }
	function reset_case(): void {
		$GLOBALS['fixture_site'] = array( 'id' => 2, 'uuid' => '11111111-2222-4333-8444-555555555555', 'dual_domain' => true, 'public_url' => 'https://www.merchant.example.com/', 'management_url' => 'https://manager.example.com/' );
		$GLOBALS['fixture_row'] = array( 'ref' => $GLOBALS['provider_ref'], 'site_id' => 2, 'site_uuid' => $GLOBALS['fixture_site']['uuid'], 'order_id' => 11737,
			'created_at' => $GLOBALS['clock'] - 60, 'local_reference' => $GLOBALS['local_ref'], 'return_token_hash' => hash( 'sha256', $GLOBALS['hq_token'] ),
			'secret_box' => 'synthetic-provider-box', 'link_key_box' => 'synthetic-link-box', 'return_box' => 'synthetic-target-box', 'test_mode' => 0 );
		$GLOBALS['site_active'] = true; $GLOBALS['provider_key_fails'] = $GLOBALS['link_key_fails'] = $GLOBALS['target_decrypt_fails'] = false; $GLOBALS['decrypt_purposes'] = array();
		$GLOBALS['frozen_target'] = 'https://www.merchant.example.com/wc-api/pc_panapay_return/?reference=' . $GLOBALS['local_ref'] . '&token=' . $GLOBALS['store_token'];
	}
	function query( array $override = array() ): array {
		$p = array_replace( array( 'reference' => $GLOBALS['provider_ref'], 'token' => $GLOBALS['hq_token'], 'status' => 'SUCCEEDED', 'transaction_id' => 'synthetic-provider-transaction' ), $override );
		if ( is_string( $p['transaction_id'] ) && is_string( $p['status'] ) ) { $p['sign'] = Signature::generate( array( 'transaction_id' => $p['transaction_id'], 'status' => $p['status'] ), 'synthetic-provider-key' ); }
		return $p;
	}
	function negative( string $label, array $p, ?string $expected_home = null ): void {
		$before = serialize( array( $GLOBALS['fixture_row'], $GLOBALS['fixture_site'] ) ); $redirects = $GLOBALS['redirects'];
		$result = Hub::returned( new WP_REST_Request( $p ) );
		check( $label . ': safe error view', $result instanceof WP_REST_Response && 400 === $result->get_status() && 1 === ( $result->get_data()['pc_panapay_return_view'] ?? null ) );
		check( $label . ': only trusted home disclosed', $expected_home === $result->get_data()['store_home'] );
		check( $label . ': no redirect or writes', $redirects === $GLOBALS['redirects'] && 0 === $GLOBALS['financial_calls'] && 0 === $GLOBALS['http_calls'] && 0 === $GLOBALS['mail_calls'] );
		check( $label . ': source snapshots unchanged', $before === serialize( array( $GLOBALS['fixture_row'], $GLOBALS['fixture_site'] ) ) );
		$json = json_encode( $result->get_data(), JSON_THROW_ON_ERROR );
		foreach ( array( $GLOBALS['provider_ref'], $GLOBALS['local_ref'], $GLOBALS['hq_token'], $GLOBALS['store_token'], 'synthetic-provider-key', 'synthetic-link-key', 'synthetic-provider-transaction', 'Private synthetic', 'attacker' ) as $private ) { check( $label . ': no private value in view', ! str_contains( $json, $private ) ); }
	}
	reset_case();
	if ( null !== $scenario ) {
		$p = query( array( 'return_url' => 'https://attacker.example.com/', 'store_home' => 'https://attacker.example.com/', 'redirect' => 'https://attacker.example.com/' ) );
		switch ( $scenario ) {
			case 'legacy-success': break;
			case 'legacy-failed': $p = query( array( 'status' => 'FAILED', 'transaction_id' => '' ) ); break;
			case 'status-missing-all': unset( $p['status'], $p['transaction_id'], $p['sign'] ); break;
			case 'status-missing-status': unset( $p['status'] ); break;
			case 'status-missing-transaction': unset( $p['transaction_id'] ); break;
			case 'status-missing-sign': unset( $p['sign'] ); break;
			case 'status-bad-sign': $p['sign'] = str_repeat( '0', 32 ); break;
			case 'status-unknown-status': $p = query( array( 'status' => 'UNKNOWN' ) ); break;
			case 'status-wrong-transaction': $p['transaction_id'] = 'tampered-transaction'; break;
			case 'status-empty-success-transaction': $p = query( array( 'transaction_id' => '' ) ); break;
			case 'status-array-provider-field': $p['status'] = array( 'SUCCEEDED' ); break;
			case 'status-oversized-provider-field': $p['sign'] = str_repeat( 'a', 513 ); break;
			case 'status-provider-key-unavailable': $GLOBALS['provider_key_fails'] = true; break;
			case 'status-forged-proof-query': $p['check'] = '1'; $p['sign'] = Signature::generate( array( 'purpose' => 'pc-panapay-return-check-v1', 'reference' => $local_ref, 'token' => $store_token ), 'wrong-browser-key' ); break;
			default: throw new \RuntimeException( 'Unknown isolated scenario' );
		}
		$fixture_snapshot = serialize( array( $fixture_row, $fixture_site ) );
		Hub::returned( new WP_REST_Request( $p ) );
		throw new \RuntimeException( 'Expected bound redirect did not reach native exit: ' . $scenario );
	}
	foreach ( array( 'reference', 'token' ) as $field ) {
		reset_case(); $p = query(); unset( $p[ $field ] ); negative( 'missing ' . $field, $p );
		reset_case(); $p = query(); $p[ $field ] = array( 'typed-value' ); negative( 'array ' . $field, $p );
		reset_case(); $p = query(); $p[ $field ] = str_repeat( 'a', 513 ); negative( 'oversized ' . $field, $p );
	}
	reset_case(); negative( 'unknown reference', query( array( 'reference' => 'PH' . str_repeat( 'e', 32 ) ) ) );
	reset_case(); negative( 'wrong token', query( array( 'token' => str_repeat( 'e', 48 ) ) ) );
	reset_case(); $fixture_row['created_at'] = $clock - 7 * DAY_IN_SECONDS - 1; negative( 'expired attempt', query() );
	reset_case(); $fixture_site = null; negative( 'missing registered site', query() );
	reset_case(); $fixture_row['site_uuid'] = 'other-identity'; negative( 'changed site UUID', query() );
	reset_case(); $site_active = false; negative( 'retained site', query() );
	reset_case(); $fixture_site['public_url'] = 'https://user:private@attacker.example.com/'; negative( 'invalid registered home', query() );
	foreach ( array(
		'https://attacker.example.com/wc-api/pc_panapay_return/?reference=wrong&token=' . $store_token,
		'https://www.merchant.example.com/checkout/?reference=' . $local_ref . '&token=' . $store_token,
		'https://www.merchant.example.com/wc-api/pc_panapay_return/?reference=' . $local_ref . '&token=' . $store_token . '&redirect=https://attacker.example.com/',
		'https://www.merchant.example.com/wc-api/pc_panapay_return/?reference=' . str_replace( '-11737-', '-11738-', $local_ref ) . '&token=' . $store_token,
	) as $target ) { reset_case(); $frozen_target = $target; negative( 'invalid frozen callback', query(), 'https://www.merchant.example.com/' ); }
	reset_case(); $target_decrypt_fails = true; negative( 'unavailable frozen callback', query(), 'https://www.merchant.example.com/' );
	reset_case(); $link_key_fails = true; negative( 'unavailable link key', query(), 'https://www.merchant.example.com/' );
	check( 'negative cases performed no redirect or financial activity', 0 === $redirects && 0 === $financial_calls && 0 === $http_calls && 0 === $mail_calls );
	$positive = array( 'legacy-success', 'legacy-failed', 'status-missing-all', 'status-missing-status', 'status-missing-transaction', 'status-missing-sign', 'status-bad-sign', 'status-unknown-status', 'status-wrong-transaction', 'status-empty-success-transaction', 'status-array-provider-field', 'status-oversized-provider-field', 'status-provider-key-unavailable', 'status-forged-proof-query' );
	$children = array();
	foreach ( $positive as $case ) {
		$process = proc_open( array( PHP_BINARY, __FILE__, '--case=' . $case ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) { throw new \RuntimeException( 'Unable to run isolated return scenario' ); }
		fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[2] ); $exit = proc_close( $process );
		if ( 0 !== $exit ) { throw new \RuntimeException( 'Return scenario failed: ' . $case . "\n" . $stdout . $stderr ); }
		$child = json_decode( trim( $stdout ), true, 16, JSON_THROW_ON_ERROR );
		check( 'isolated case passed: ' . $case, true === ( $child['passed'] ?? null ) && $case === $child['scenario'] && true === $child['redirect'] && 0 === $child['http'] && 0 === $child['financial_writes'] );
		$checks += $child['checks']; $children[] = array( 'scenario' => $case, 'checks' => $child['checks'] );
	}
	echo json_encode( array( 'passed' => true, 'checks' => $checks, 'isolated_redirect_cases' => $children, 'http' => 0, 'financial_writes' => 0, 'wordpress_bootstrapped' => false ), JSON_THROW_ON_ERROR ) . "\n";
}
