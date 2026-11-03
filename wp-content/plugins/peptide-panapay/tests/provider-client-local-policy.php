<?php
/**
 * Actual Provider_Client and native WP_Http policy with synthetic transport only.
 * Run: php provider-client-local-policy.php /path/to/wp-includes/class-wp-http.php
 * Loads no WordPress config, database, HTTP transport or merchant credentials.
 */
namespace {
	$http_source = $argv[1] ?? '';
	if ( ! is_file( $http_source ) || 'class-wp-http.php' !== basename( $http_source ) ) {
		fwrite( STDERR, "Provide the WordPress wp-includes/class-wp-http.php source path.\n" );
		exit( 2 );
	}
	define( 'ABSPATH', dirname( $http_source, 2 ) . '/' );
	define( 'WPINC', 'wp-includes' );
	define( 'WP_HTTP_BLOCK_EXTERNAL', true );
	define( 'WP_ACCESSIBLE_HOSTS', 'provider-allowed.example.invalid' );
	require $http_source;
	class WP_Error {
		public function __construct( public string $code, public string $message = '' ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
	function get_option( string $key ) { return 'siteurl' === $key ? 'https://store.example.invalid' : false; }
	function apply_filters( string $hook, $value ) { return $value; }
	function _wp_http_get_object(): \WP_Http {
		static $http = null;
		return $http ??= new \WP_Http();
	}
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function wp_json_encode( $value ): string { return json_encode( $value, JSON_THROW_ON_ERROR ); }
	function wp_remote_retrieve_response_code( array $response ): int { return $response['code']; }
	function wp_remote_retrieve_body( array $response ) { return $response['body']; }
	function has_action( string $hook ): bool { return 'pc_panapay_provider_diagnostic' === $hook && is_callable( $GLOBALS['diagnostic_listener'] ?? null ); }
	function do_action( string $hook, array $event ): void {
		++$GLOBALS['diagnostic_dispatches'];
		if ( has_action( $hook ) ) { ( $GLOBALS['diagnostic_listener'] )( $event ); }
	}
	function wp_safe_remote_post( string $url, array $args ) {
		$GLOBALS['transport_calls'][] = array( 'url' => $url, 'args' => $args );
		return $GLOBALS['transport_response'];
	}
}
namespace VialCove\Panapay {
	/** URL/DNS boundary is isolated; the transport policy itself uses WordPress source. */
	final class Configuration {
		public static function origin( string $url ): string {
			if ( ! in_array( $url, array( 'https://provider-allowed.example.invalid', 'https://provider-blocked.example.invalid' ), true ) ) {
				throw new \RuntimeException( 'Unexpected synthetic gateway.' );
			}
			return $url;
		}
	}
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-provider-client.php';
	use VialCove\Panapay\Provider_Client;
	$checks = 0;
	$diagnostic_dispatches = 0;
	function check( string $name, bool $ok ): void {
		++$GLOBALS['checks'];
		if ( ! $ok ) { throw new \RuntimeException( 'FAIL ' . $name ); }
	}
	function create_with( $response, string $host = 'provider-allowed.example.invalid' ): array {
		$GLOBALS['transport_calls'] = array();
		$GLOBALS['transport_response'] = $response;
		return Provider_Client::create( array( 'order_id' => 'synthetic-only', 'amount' => '1.00' ), array( 'gateway' => 'https://' . $host ) );
	}
	$path = '/index.php/admin/Payments/createPayment';
	check( 'native policy denies unlisted provider', _wp_http_get_object()->block_request( 'https://provider-blocked.example.invalid' . $path ) );
	check( 'native policy permits exact listed provider', ! _wp_http_get_object()->block_request( 'https://provider-allowed.example.invalid' . $path ) );
	$success = array( 'code' => 200, 'body' => '{"status":"SUCCEEDED","type":"iframe","payment_url":"https://checkout.example.invalid/synthetic"}' );
	check( 'blocked before transport is definite failed', array( 'state' => 'failed' ) === create_with( $success, 'provider-blocked.example.invalid' ) );
	check( 'blocked makes zero transport calls', array() === $GLOBALS['transport_calls'] );
	check( 'allowed preserves successful protocol', array( 'state' => 'created', 'type' => 'iframe', 'url' => 'https://checkout.example.invalid/synthetic' ) === create_with( $success ) );
	check( 'allowed makes one transport call without retry', 1 === count( $GLOBALS['transport_calls'] ) );
	$call = $GLOBALS['transport_calls'][0];
	check( 'exact validated create endpoint', 'https://provider-allowed.example.invalid' . $path === $call['url'] );
	check( 'HTTPS verification unsafe URL rejection and zero redirects retained', true === $call['args']['sslverify'] && true === $call['args']['reject_unsafe_urls'] && 0 === $call['args']['redirection'] );
	check( 'transport timeout and response bound retained', 30 === $call['args']['timeout'] && 65536 === $call['args']['limit_response_size'] );
	check( 'JSON payload retained', array( 'order_id' => 'synthetic-only', 'amount' => '1.00' ) === json_decode( $call['args']['body'], true ) );
	check( 'redirect success retained', 'created' === create_with( array( 'code' => 200, 'body' => '{"status":"SUCCEEDED","type":"redirect","payment_url":"https://checkout.example.invalid/synthetic"}' ) )['state'] );
	check( 'explicit provider failure retained', array( 'state' => 'failed' ) === create_with( array( 'code' => 200, 'body' => '{"status":"FAILED"}' ) ) );
	$ambiguous = array(
		'timeout' => new WP_Error( 'http_request_failed' ),
		'error after transport resembling policy rejection' => new WP_Error( 'http_request_not_executed' ),
		'redirect HTTP status' => array( 'code' => 302, 'body' => '' ),
		'client HTTP status' => array( 'code' => 400, 'body' => '{"status":"FAILED"}' ),
		'server HTTP status' => array( 'code' => 500, 'body' => '' ),
		'oversize response' => array( 'code' => 200, 'body' => str_repeat( 'x', 65537 ) ),
		'nonstring body' => array( 'code' => 200, 'body' => null ),
		'malformed JSON' => array( 'code' => 200, 'body' => '{' ),
		'scalar JSON' => array( 'code' => 200, 'body' => 'null' ),
		'missing status' => array( 'code' => 200, 'body' => '{}' ),
		'unsupported success type' => array( 'code' => 200, 'body' => '{"status":"SUCCEEDED","type":"popup","payment_url":"https://checkout.example.invalid/synthetic"}' ),
		'empty payment URL' => array( 'code' => 200, 'body' => '{"status":"SUCCEEDED","type":"iframe","payment_url":""}' ),
	);
	foreach ( $ambiguous as $name => $response ) {
		check( $name . ' remains unknown', array( 'state' => 'unknown' ) === create_with( $response ) );
		check( $name . ' attempted transport exactly once', 1 === count( $GLOBALS['transport_calls'] ) );
	}
	$baseline_checks = $checks;
	check( 'original 36 checks unchanged', 36 === $baseline_checks );
	check( 'absent observer dispatches no action', 0 === $diagnostic_dispatches );
	$events = array();
	$diagnostic_listener = static function ( array $event ) use ( &$events ): void { $events[] = $event; };
	$cases = array(
		'local_policy_blocked' => array( $success, 'provider-blocked.example.invalid', 'failed', 0 ),
		'transport_error' => array( new WP_Error( 'http_request_failed', 'cURL error 28: synthetic timeout' ), 'provider-allowed.example.invalid', 'unknown', 1 ),
		'http_status' => array( array( 'code' => 503, 'body' => 'synthetic only' ), 'provider-allowed.example.invalid', 'unknown', 1 ),
		'response_body_invalid' => array( array( 'code' => 200, 'body' => null ), 'provider-allowed.example.invalid', 'unknown', 1 ),
		'response_too_large' => array( $ambiguous['oversize response'], 'provider-allowed.example.invalid', 'unknown', 1 ),
		'response_json_invalid' => array( $ambiguous['malformed JSON'], 'provider-allowed.example.invalid', 'unknown', 1 ),
		'response_shape_invalid' => array( $ambiguous['scalar JSON'], 'provider-allowed.example.invalid', 'unknown', 1 ),
		'provider_failed' => array( array( 'code' => 200, 'body' => '{"status":"FAILED"}' ), 'provider-allowed.example.invalid', 'failed', 1 ),
		'response_contract_invalid' => array( $ambiguous['unsupported success type'], 'provider-allowed.example.invalid', 'unknown', 1 ),
		'created' => array( $success, 'provider-allowed.example.invalid', 'created', 1 ),
	);
	foreach ( $cases as $reason => $case ) {
		$events = array();
		check( $reason . ' result unchanged with listener', $case[2] === create_with( $case[0], $case[1] )['state'] );
		check( $reason . ' observes exactly once without transport changes', 1 === count( $events ) && $reason === $events[0]['reason'] && $case[3] === count( $GLOBALS['transport_calls'] ) );
		check( $reason . ' uses only fixed event keys', ! array_diff( array_keys( $events[0] ), array( 'reason', 'http_status', 'wp_error_code', 'curl_error_number', 'response_root', 'response_data' ) ) );
	}
	$marker = 'SENSITIVE_SYNTHETIC_83dca41';
	$host_token = 'b99999999999999999999999999999999';
	$events = array(); $transport_calls = array();
	$transport_response = array( 'code' => 200, 'body' => json_encode( array(
		'status' => $marker, 'type' => $marker, 'payment_url' => 'https://' . $host_token . '.collector.invalid/' . $marker . '?token=' . $marker,
		'data' => array( 'status' => 'SUCCEEDED', 'type' => 'redirect', 'payment_url' => 'https://checkout.example.invalid/' . $marker . '?token=' . $marker, 'customer' => $marker ),
		$marker => $marker, 'merchant' => $marker, 'message' => $marker, 'orderRef' => $marker,
	) ) );
	$privacy_result = Provider_Client::create( array( 'order_id' => $marker, 'email' => $marker, 'signature' => $marker ), array( 'gateway' => 'https://provider-allowed.example.invalid', 'payment_hosts' => array( 'checkout.example.invalid' ), 'mchid' => $marker, 'secret' => $marker ) );
	check( 'nested noncontract response remains unknown', array( 'state' => 'unknown' ) === $privacy_result );
	check( 'root free text becomes enums and untrusted host is never copied', 'other' === $events[0]['response_root']['status'] && 'other' === $events[0]['response_root']['type'] && 'untrusted' === $events[0]['response_root']['payment_url_host'] );
	check( 'nested known fields reported without URL path query', 'SUCCEEDED' === $events[0]['response_data']['status'] && 'redirect' === $events[0]['response_data']['type'] && 'checkout.example.invalid' === $events[0]['response_data']['payment_url_host'] );
	check( 'sensitive values and unknown field names never reach event', ! str_contains( json_encode( $events ), $marker ) && ! str_contains( json_encode( $events ), $host_token ) );
	check( 'response field shapes contain only fixed known names', array( 'status' => 'string', 'type' => 'string', 'payment_url' => 'string', 'data' => 'array' ) === $events[0]['response_root']['fields'] );
	$events = array();
	create_with( array( 'code' => 200, 'body' => '{"status":"SUCCEEDED","type":"iframe","payment_url":"https://provider-allowed.example.invalid/private?token=synthetic"}' ) );
	check( 'canonical gateway host is trusted without checkout path', 'provider-allowed.example.invalid' === $events[0]['response_root']['payment_url_host'] );
	foreach ( array( 'https://user:pass@provider-allowed.example.invalid/private', 'https://provider-allowed.example.invalid:8443/private', 'http://provider-allowed.example.invalid/private', 'https://bad_host.invalid/private' ) as $unsafe ) {
		$events = array();
		create_with( array( 'code' => 200, 'body' => json_encode( array( 'status' => 'SUCCEEDED', 'type' => 'iframe', 'payment_url' => $unsafe ) ) ) );
		check( 'diagnostic rejects unsafe URL form ' . $checks, 'untrusted' === $events[0]['response_root']['payment_url_host'] );
	}
	$events = array();
	create_with( new WP_Error( 'http_request_failed', 'cURL error 28: ' . $marker . ' https://' . $host_token . '.collector.invalid/?token=' . $marker ) );
	check( 'cURL message contributes only numeric error', array( 'reason' => 'transport_error', 'wp_error_code' => 'http_request_failed', 'curl_error_number' => 28 ) === $events[0] );
	$events = array();
	create_with( new WP_Error( $marker, 'secret ' . $marker . ' cURL error 1234: invalid' ) );
	check( 'arbitrary error code and message are never copied', array( 'reason' => 'transport_error', 'wp_error_code' => 'other' ) === $events[0] );
	$events = array();
	$http_failure = array( 'code' => 403, 'body' => json_encode( array( 'status' => 'FAILED', 'message' => $marker, $marker => $marker,
		'data' => array( 'status' => $marker, 'type' => 'redirect', 'payment_url' => 'https://' . $host_token . '.collector.invalid/' . $marker . '?token=' . $marker ) ) ) );
	check( 'non200 provider FAILED JSON remains unknown', array( 'state' => 'unknown' ) === create_with( $http_failure ) );
	check( 'non200 reports bounded root and nested JSON shape', 1 === count( $events ) && 'http_status' === $events[0]['reason'] && 403 === $events[0]['http_status'] && 'FAILED' === $events[0]['response_root']['status'] && 'missing' === $events[0]['response_root']['type'] && 'other' === $events[0]['response_data']['status'] );
	check( 'non200 unknown fields messages and untrusted hostname stay private', 'untrusted' === $events[0]['response_data']['payment_url_host'] && ! str_contains( json_encode( $events ), $marker ) && ! str_contains( json_encode( $events ), $host_token ) );
	$events = array();
	check( 'non200 success-shaped JSON never creates a session', array( 'state' => 'unknown' ) === create_with( array_replace( $success, array( 'code' => 500 ) ) ) && 'SUCCEEDED' === $events[0]['response_root']['status'] );
	$deep = array( 'status' => $marker );
	for ( $i = 0; $i < 20; ++$i ) { $deep = array( 'data' => $deep ); }
	foreach ( array( 'malformed' => '{' . $marker, 'oversize' => str_repeat( $marker, 4000 ), 'nonstring' => array( $marker ), 'overdepth' => json_encode( $deep ) ) as $name => $invalid_body ) {
		$events = array();
		check( 'non200 ' . $name . ' body keeps result and single transport', array( 'state' => 'unknown' ) === create_with( array( 'code' => 502, 'body' => $invalid_body ) ) && 1 === count( $GLOBALS['transport_calls'] ) );
		check( 'non200 ' . $name . ' body cannot suppress event or expose text', array( array( 'reason' => 'http_status', 'http_status' => 502 ) ) === $events );
	}
	$events = array();
	create_with( array( 'code' => 200, 'body' => '{}' ) );
	check( 'missing known fields distinguish missing from other', 'missing' === $events[0]['response_root']['status'] && 'missing' === $events[0]['response_root']['type'] );
	$events = array();
	create_with( array( 'code' => 200, 'body' => '{"status":null,"type":null}' ) );
	check( 'present null status and type remain other', 'other' === $events[0]['response_root']['status'] && 'other' === $events[0]['response_root']['type'] );
	$diagnostic_listener = static function ( array $event ): void { throw new \RuntimeException( 'synthetic observer failure' ); };
	foreach ( $cases as $reason => $case ) {
		check( $reason . ' observer exception cannot change result or retry', $case[2] === create_with( $case[0], $case[1] )['state'] && $case[3] === count( $GLOBALS['transport_calls'] ) );
	}
	echo json_encode( array( 'status' => 'passed', 'checks' => $checks, 'original_policy_checks' => $baseline_checks, 'native_wp_http_policy' => true, 'transport' => 'synthetic only', 'real_requests' => 0 ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}
