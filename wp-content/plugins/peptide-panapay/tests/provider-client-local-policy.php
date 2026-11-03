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
		public function __construct( public string $code ) {}
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
	echo json_encode( array( 'status' => 'passed', 'checks' => $checks, 'native_wp_http_policy' => true, 'transport' => 'synthetic only', 'real_requests' => 0 ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}
