<?php
/**
 * Actual Provider_Client response parsing against synthetic transport only.
 * Run: php -n provider-client-response-envelope.php
 * No WordPress configuration, database, merchant credentials or real HTTP are loaded.
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	class WP_Error {
		public function get_error_code(): string { return 'http_request_failed'; }
		public function get_error_message(): string { return 'Synthetic transport failure.'; }
	}
	function _wp_http_get_object() {
		return new class {
			public function block_request( string $url ): bool { return $GLOBALS['policy_blocked']; }
		};
	}
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function wp_json_encode( $value ): string { return json_encode( $value, JSON_THROW_ON_ERROR ); }
	function wp_safe_remote_post( string $url, array $args ) {
		++$GLOBALS['transport_calls'];
		$GLOBALS['transport_args'] = $args;
		return $GLOBALS['transport_response'];
	}
	function wp_remote_retrieve_response_code( array $response ): int { return $response['code']; }
	function wp_remote_retrieve_body( array $response ) { return $response['body']; }
	function has_action( string $hook ): bool { return 'pc_panapay_provider_diagnostic' === $hook; }
	function do_action( string $hook, array $event ): void { $GLOBALS['events'][] = $event; }
}
namespace VialCove\Panapay {
	final class Configuration {
		public static function origin( string $value ): string { return $value; }
	}
}
namespace {
	use VialCove\Panapay\Provider_Client;
	require dirname( __DIR__ ) . '/includes/class-provider-client.php';
	$checks = 0;
	function check( string $name, bool $condition ): void {
		++$GLOBALS['checks'];
		if ( ! $condition ) { throw new \RuntimeException( 'Failed: ' . $name ); }
	}
	function run_response( $response, string $state, string $reason ): array {
		$GLOBALS['policy_blocked'] = false;
		$GLOBALS['transport_response'] = $response;
		$GLOBALS['transport_calls'] = 0;
		$GLOBALS['events'] = array();
		$result = Provider_Client::create( array( 'order_id' => 'synthetic-order' ), array( 'gateway' => 'https://gateway.example.invalid', 'payment_hosts' => array() ) );
		check( $reason . ' result state', $state === $result['state'] );
		check( $reason . ' no retry', 1 === $GLOBALS['transport_calls'] );
		check( $reason . ' fixed diagnostic', 1 === count( $GLOBALS['events'] ) && $reason === $GLOBALS['events'][0]['reason'] );
		if ( 'created' !== $state ) { check( $reason . ' no checkout leaked', array( 'state' => $state ) === $result ); }
		return $result;
	}
	function run_json( $data, string $state = 'unknown', string $reason = 'response_contract_invalid' ): array {
		return run_response( array( 'code' => 200, 'body' => json_encode( $data, JSON_THROW_ON_ERROR ) ), $state, $reason );
	}
	$iframe = array( 'type' => 'iframe', 'payment_url' => 'https://checkout.example.invalid/synthetic-session' );
	$redirect = array( 'type' => 'redirect', 'payment_url' => 'https://checkout.example.invalid/synthetic-redirect' );
	foreach ( array( $iframe, $redirect ) as $pair ) {
		$direct = run_json( array_merge( array( 'status' => 'SUCCEEDED' ), $pair ), 'created', 'created' );
		$nested = run_json( array( 'status' => 'SUCCEEDED', 'data' => array_merge( $pair, array( 'serial_number' => 'synthetic-serial' ) ) ), 'created', 'created' );
		check( 'direct and wrapper normalize identically', $direct === $nested && $direct === array( 'state' => 'created', 'type' => $pair['type'], 'url' => $pair['payment_url'] ) );
		$both = run_json( array_merge( array( 'status' => 'SUCCEEDED', 'data' => $pair ), $pair ), 'created', 'created' );
		check( 'equal complete levels accepted', $direct === $both );
		foreach ( array( null, 'unrelated metadata', false ) as $metadata ) {
			$legacy = run_json( array_merge( array( 'status' => 'SUCCEEDED', 'data' => $metadata ), $pair ), 'created', 'created' );
			check( 'complete legacy pair tolerates non-session data metadata', $direct === $legacy );
		}
	}
	check( 'transport retains TLS and zero redirects', true === $GLOBALS['transport_args']['sslverify'] && true === $GLOBALS['transport_args']['reject_unsafe_urls'] && 0 === $GLOBALS['transport_args']['redirection'] );
	$rejected = array(
		'no pair' => array( 'status' => 'SUCCEEDED' ),
		'top type only' => array( 'status' => 'SUCCEEDED', 'type' => 'iframe' ),
		'top URL only' => array( 'status' => 'SUCCEEDED', 'payment_url' => $iframe['payment_url'] ),
		'nested type only' => array( 'status' => 'SUCCEEDED', 'data' => array( 'type' => 'iframe' ) ),
		'nested URL only' => array( 'status' => 'SUCCEEDED', 'data' => array( 'payment_url' => $iframe['payment_url'] ) ),
		'cross level pair' => array( 'status' => 'SUCCEEDED', 'type' => 'iframe', 'data' => array( 'payment_url' => $iframe['payment_url'] ) ),
		'top partial with complete nested' => array( 'status' => 'SUCCEEDED', 'type' => 'iframe', 'data' => $iframe ),
		'nested partial with complete top' => array_merge( array( 'status' => 'SUCCEEDED', 'data' => array( 'type' => 'iframe' ) ), $iframe ),
		'top null pair with complete nested' => array( 'status' => 'SUCCEEDED', 'type' => null, 'payment_url' => null, 'data' => $iframe ),
		'type conflict' => array_merge( array( 'status' => 'SUCCEEDED', 'data' => array_merge( $iframe, array( 'type' => 'redirect' ) ) ), $iframe ),
		'URL conflict' => array_merge( array( 'status' => 'SUCCEEDED', 'data' => array_merge( $iframe, array( 'payment_url' => $redirect['payment_url'] ) ) ), $iframe ),
		'unsupported top type' => array_merge( array( 'status' => 'SUCCEEDED', 'data' => $iframe ), array( 'type' => 'popup', 'payment_url' => $iframe['payment_url'] ) ),
		'unsupported nested type' => array( 'status' => 'SUCCEEDED', 'data' => array_merge( $iframe, array( 'type' => 'popup' ) ) ),
		'nested invalid with valid top' => array_merge( array( 'status' => 'SUCCEEDED', 'data' => array_merge( $iframe, array( 'type' => false ) ) ), $iframe ),
		'nested invalid URL with valid top' => array_merge( array( 'status' => 'SUCCEEDED', 'data' => array_merge( $iframe, array( 'payment_url' => 123 ) ) ), $iframe ),
		'empty URL' => array( 'status' => 'SUCCEEDED', 'data' => array( 'type' => 'iframe', 'payment_url' => '' ) ),
		'array URL' => array( 'status' => 'SUCCEEDED', 'data' => array( 'type' => 'iframe', 'payment_url' => array( $iframe['payment_url'] ) ) ),
		'nested-only null data' => array( 'status' => 'SUCCEEDED', 'data' => null ),
		'nested-only scalar data' => array( 'status' => 'SUCCEEDED', 'data' => 'not a session object' ),
		'nested-only boolean data' => array( 'status' => 'SUCCEEDED', 'data' => false ),
		'missing root status' => array( 'data' => array_merge( array( 'status' => 'SUCCEEDED' ), $iframe ) ),
		'wrong root status' => array( 'status' => 'PENDING', 'data' => $iframe ),
		'other URL alias ignored' => array( 'status' => 'SUCCEEDED', 'data' => array( 'type' => 'iframe', 'url' => $iframe['payment_url'] ) ),
		'deeper pair ignored' => array( 'status' => 'SUCCEEDED', 'data' => array( 'data' => $iframe ) ),
		'list pair ignored' => array( 'status' => 'SUCCEEDED', 'data' => array( $iframe ) ),
	);
	foreach ( $rejected as $name => $data ) { run_json( $data ); }
	$max_url = 'https://checkout.example.invalid/' . str_repeat( 'x', 8192 - strlen( 'https://checkout.example.invalid/' ) );
	check( '8192 byte URL accepted', 'created' === run_json( array( 'status' => 'SUCCEEDED', 'data' => array( 'type' => 'iframe', 'payment_url' => $max_url ) ), 'created', 'created' )['state'] );
	run_json( array( 'status' => 'SUCCEEDED', 'data' => array( 'type' => 'iframe', 'payment_url' => $max_url . 'x' ) ) );
	run_json( array_merge( array( 'status' => 'SUCCEEDED', 'data' => array( 'type' => 'iframe', 'payment_url' => $max_url . 'x' ) ), $iframe ) );
	run_json( array( 'status' => 'FAILED', 'data' => $iframe ), 'failed', 'provider_failed' );
	run_json( array( 'status' => 'FAILED', 'type' => null, 'payment_url' => null, 'data' => 'invalid' ), 'failed', 'provider_failed' );
	foreach ( array( 201, 301, 400, 422, 500 ) as $code ) {
		run_response( array( 'code' => $code, 'body' => json_encode( array( 'status' => 'SUCCEEDED', 'data' => $iframe ) ) ), 'unknown', 'http_status' );
		run_response( array( 'code' => $code, 'body' => '{"status":"FAILED"}' ), 'unknown', 'http_status' );
	}
	run_response( array( 'code' => 200, 'body' => '<html>not JSON</html>' ), 'unknown', 'response_json_invalid' );
	run_response( array( 'code' => 200, 'body' => '{"status":' ), 'unknown', 'response_json_invalid' );
	run_response( array( 'code' => 200, 'body' => 'true' ), 'unknown', 'response_shape_invalid' );
	run_response( array( 'code' => 200, 'body' => null ), 'unknown', 'response_body_invalid' );
	run_response( array( 'code' => 200, 'body' => str_repeat( 'x', 65537 ) ), 'unknown', 'response_too_large' );
	run_response( new WP_Error(), 'unknown', 'transport_error' );
	$untrusted = run_json( array( 'status' => 'SUCCEEDED', 'data' => array( 'type' => 'iframe', 'payment_url' => 'https://outside.example.invalid/synthetic-only' ) ), 'created', 'created' );
	check( 'parser leaves URL trust to existing downstream validation', 'untrusted' === $GLOBALS['events'][0]['response_data']['payment_url_host'] && 'created' === $untrusted['state'] );
	$GLOBALS['policy_blocked'] = true; $GLOBALS['transport_calls'] = 0;
	check( 'pretransport local block preserved', array( 'state' => 'failed' ) === Provider_Client::create( array(), array( 'gateway' => 'https://gateway.example.invalid' ) ) && 0 === $GLOBALS['transport_calls'] );
	echo json_encode( array( 'status' => 'passed', 'checks' => $checks, 'transport' => 'synthetic only', 'real_http_requests' => 0 ), JSON_PRETTY_PRINT ) . "\n";
}
