<?php
/** Isolated view/REST shape checks only. No WP bootstrap, DB, HTTP or payment. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	class WP_REST_Request {
		public function __construct( private string $route, private string $method = 'GET' ) {}
		public function get_route(): string { return $this->route; }
		public function get_method(): string { return $this->method; }
	}
	class WP_REST_Response {
		public function __construct( private $data = null, private int $status = 200, private array $headers = array() ) {}
		public function get_data() { return $this->data; }
		public function get_status(): int { return $this->status; }
		public function get_headers(): array { return $this->headers; }
	}
	function __( $text, $domain = '' ) { return $text; }
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $text ) { return esc_html( $text ); }
	function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
	function esc_url( $text ) { return esc_attr( $text ); }
	function get_bloginfo( $key ) { return 'en-US'; }
	function wp_parse_url( $url ) { return parse_url( $url ); }
	function add_filter( ...$args ) { $GLOBALS['view_hooks'][] = $args; }
	function status_header( $status ) { $GLOBALS['view_status'] = $status; }
}
namespace VialCove\Panapay {
	function headers_sent() { return false; }
	function header( $text, $replace = true ) { $GLOBALS['view_headers'][] = $text; }
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-payment-return-view.php';
	use VialCove\Panapay\Payment_Return_View as View;
	$count = 0;
	function view_check( bool $yes, string $message ): void {
		++$GLOBALS['count'];
		if ( ! $yes ) { throw new \RuntimeException( $message ); }
	}
	$generic = View::failure();
	view_check( 400 === $generic->get_status(), 'return failure remains 400' );
	view_check( null === $generic->get_data()['store_home'], 'unverified return has no target' );
	view_check( str_contains( $generic->get_headers()['Content-Type'], 'text/html' ), 'HTML response header' );
	foreach ( array( 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "form-action 'none'" ) as $key => $value ) {
		view_check( str_contains( $generic->get_headers()[ $key ], $value ), 'safe header:' . $key );
	}
	$html = View::render();
	foreach ( array( '<!doctype html>', '<main aria-labelledby=', '<h1 id=', 'name="viewport"', 'do not pay again', 'Open the store where' ) as $needle ) { view_check( str_contains( $html, $needle ), 'generic view:' . $needle ); }
	foreach ( array( '<script', '<form', 'href=', 'transaction_id', 'reference=', 'token=', 'sign=', 'Payment successful' ) as $needle ) { view_check( ! str_contains( $html, $needle ), 'generic excludes:' . $needle ); }
	$valid = View::failure( 'https://Shop.Example.com:443/' );
	view_check( 'https://shop.example.com/' === $valid->get_data()['store_home'], 'normalize authenticated root home' );
	view_check( str_contains( View::render( 'https://shop.example.com/' ), 'href="https://shop.example.com/" rel="noreferrer"' ), 'safe home anchor' );
	foreach ( array( '', 'http://shop.example.com/', '//shop.example.com/', 'javascript:alert(1)', 'https://evil.example.com/?next=https://shop.example.com', 'https://shop.example.com/#token', 'https://user:secret@shop.example.com/', 'https://shop.example.com/pay', 'https://shop.example.com:8443/', 'https://127.0.0.1/', 'https://localhost/', 'https://foo.local/', "https://shop.example.com/\n", 'https://shop.example.com\\evil', 'https://shop.example.com/?token=private', 'https://shop.example.com/%0a' ) as $url ) {
		view_check( null === View::failure( $url )->get_data()['store_home'], 'reject unsafe/nonroot home' );
		view_check( ! str_contains( View::render( $url ), 'href=' ), 'unsafe home has no anchor' );
	}
	View::register_hooks(); View::register_hooks();
	view_check( 1 === count( $GLOBALS['view_hooks'] ), 'hook registered once' );
	view_check( 'rest_pre_serve_request' === $GLOBALS['view_hooks'][0][0], 'REST serve hook' );
	$request = new WP_REST_Request( '/pc-panapay/v1/hub/return' );
	foreach ( array(
		array( true, $generic, $request ),
		array( false, $generic, new WP_REST_Request( '/pc-panapay/v1/hub/notify' ) ),
		array( false, $generic, new WP_REST_Request( '/pc-panapay/v1/hub/return', 'POST' ) ),
		array( false, new WP_REST_Response( $generic->get_data(), 200 ), $request ),
		array( false, new WP_REST_Response( array( 'code' => 'invalid_payment_return' ), 400 ), $request ),
		array( false, new WP_REST_Response( array( 'pc_panapay_return_view' => '1', 'store_home' => null ), 400 ), $request ),
		array( false, new WP_REST_Response( array( 'pc_panapay_return_view' => 1, 'store_home' => 'https://evil.example.com/?token=x' ), 400 ), $request ),
		array( false, new WP_REST_Response( array( 'pc_panapay_return_view' => 1, 'store_home' => null, 'extra' => true ), 400 ), $request ),
	) as $args ) {
		ob_start(); $result = View::serve( ...array_merge( $args, array( null ) ) ); $body = ob_get_clean();
		view_check( $result === $args[0] && '' === $body, 'unrelated or malformed response untouched' );
	}
	ob_start(); $served = View::serve( false, $generic, $request, null ); $body = ob_get_clean();
	view_check( true === $served && str_starts_with( $body, '<!doctype html>' ), 'real return error served as HTML' );
	view_check( 400 === $GLOBALS['view_status'], 'HTML keeps error HTTP status' );
	view_check( in_array( 'Referrer-Policy: no-referrer', $GLOBALS['view_headers'], true ), 'serve sends privacy headers' );
	view_check( ! str_contains( $body, 'pc_panapay_return_view' ), 'internal marker not rendered' );
	echo json_encode( array( 'passed' => true, 'checks' => $count, 'http_or_database_used' => false ) ) . "\n";
}
