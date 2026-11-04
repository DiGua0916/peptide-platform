<?php
/** Actual route decorator: synthetic native auth, batch lifetime and post-response-sync boundaries. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	class WP_REST_Request { public function __construct( public bool $authorized = true ) {} }
	class WC_Order {
		public function __construct( public bool $frozen = true ) {}
		public function get_meta( $key, $single = true ) { return $this->frozen ? array( 'synthetic' ) : ''; }
		public function get_payment_method(): string { return $this->frozen ? 'pc_panapay' : 'other'; }
	}
	function add_action( ...$args ) {}
	function add_filter( ...$args ) {}
}
namespace Automattic\WooCommerce\StoreApi\Routes\V1 {
	class Checkout {
		protected $schema_controller; protected $schema; private string $namespace = 'wc/store/v1';
		private int $visits = 0;
		public function __construct( $controller, $schema ) { $this->schema_controller = $controller; $this->schema = $schema; }
		public function get_namespace(): string { return $this->namespace; }
		public function set_namespace( $namespace ) { $this->namespace = $namespace; }
		public function get_response( \WP_REST_Request $request ) {
			$result = array( 'auth_failed' => true );
			if ( $request->authorized ) { $result = $this->get_response_by_request_method( $request ); }
			// Model native variants that sync drafts even after a rejected nonce or an unknown payment.
			$this->cart_updated( $request );
			return $result;
		}
		protected function get_response_by_request_method( \WP_REST_Request $request ) {
			++$this->visits;
			$this->get_draft_order();
			return array( 'visits' => $this->visits, 'namespace' => $this->namespace, 'controller' => $this->schema_controller, 'schema' => $this->schema );
		}
		protected function get_draft_order() { ++$GLOBALS['native_reads']; return $GLOBALS['order']; }
		protected function cart_updated( \WP_REST_Request $request ) { ++$GLOBALS['native_sync']; $this->get_draft_order(); }
	}
	class ThirdPartyCheckout extends Checkout {}
}
namespace VialCove\Panapay {
	class Checkout_Handoff {
		public static function resolve( $order, \WP_REST_Request $request ) { ++$GLOBALS['handoff_calls']; if ( $GLOBALS['throw'] ) { throw new \RuntimeException( 'synthetic failure' ); } return $order; }
		public static function finish() { ++$GLOBALS['finished']; }
	}
	class Session_Service { public static function frozen_gateway( $order ) { return $order->frozen ? 'pc_panapay' : null; } }
	class Gateway { public static function account_from_id( $id ) { return 'pc_panapay' === $id ? 'default' : null; } }
}
namespace {
	use Automattic\WooCommerce\StoreApi\Routes\V1\Checkout;
	use VialCove\Panapay\Checkout_Route;
	use VialCove\Panapay\Checkout_Lifecycle;
	require dirname( __DIR__ ) . '/includes/class-checkout-route.php';
	require dirname( __DIR__ ) . '/includes/class-checkout-lifecycle.php';
	$checks = 0;
	function assert_route( bool $ok, string $label ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new \RuntimeException( $label ); } }
	function reset_route(): void { $GLOBALS['order'] = new WC_Order(); $GLOBALS['native_reads'] = 0; $GLOBALS['native_sync'] = 0; $GLOBALS['handoff_calls'] = 0; $GLOBALS['finished'] = 0; $GLOBALS['throw'] = false; }
	reset_route(); $native = new Checkout( 'native-controller', 'native-schema' );
	$bad = Checkout_Route::dispatch( new WP_REST_Request( false ), $native );
	assert_route( true === $bad['auth_failed'], 'native auth result preserved' );
	assert_route( 0 === $GLOBALS['handoff_calls'] && 0 === $GLOBALS['native_sync'] && 0 === $GLOBALS['native_reads'], 'bad nonce performs no handoff or draft synchronization' );
	assert_route( 1 === $GLOBALS['finished'], 'failed auth still cleans request context' );
	reset_route(); $first = Checkout_Route::dispatch( new WP_REST_Request(), $native ); $second = Checkout_Route::dispatch( new WP_REST_Request(), $native );
	assert_route( 1 === $first['visits'] && 1 === $second['visits'], 'batch dispatches use fresh native private order state' );
	assert_route( 2 === $GLOBALS['handoff_calls'], 'only authorized draft lookups enter handoff' );
	assert_route( 0 === $GLOBALS['native_sync'], 'frozen post-response draft does not overwrite result or mutate old order' );
	assert_route( 'native-controller' === $first['controller'] && 'native-schema' === $first['schema'] && 'wc/store/v1' === $first['namespace'], 'native dependencies and namespace preserved' );
	reset_route(); $GLOBALS['order'] = new WC_Order( false ); Checkout_Route::dispatch( new WP_REST_Request(), $native );
	assert_route( 1 === $GLOBALS['native_sync'], 'unfrozen native draft retains normal synchronization' );
	reset_route(); $GLOBALS['throw'] = true; $caught = false;
	try { Checkout_Route::dispatch( new WP_REST_Request(), $native ); } catch ( \RuntimeException $expected ) { $caught = true; }
	assert_route( $caught && 1 === $GLOBALS['finished'], 'exception releases handoff context through finally' );
	reset_route(); $native_handler = array( 'callback' => array( $native, 'get_response' ), 'permission_callback' => 'native-permission', 'args' => array( 'native-schema' ), 'allow_batch' => array( 'v1' => true ), 'methods' => array( 'POST' => true ) );
	$third = new \Automattic\WooCommerce\StoreApi\Routes\V1\ThirdPartyCheckout( 'third', 'third' );
	$input = array( '/wc/store/v1/checkout' => array( $native_handler ), '/wc/store/checkout' => array( $native_handler ),
		'/wc/store/v1/checkout/999' => array( $native_handler ), '/wc/store/v1/cart' => array( $native_handler ),
		'/other' => array( array( 'callback' => array( $third, 'get_response' ) ) ) );
	$output = Checkout_Lifecycle::checkout_routes( $input );
	assert_route( $output['/wc/store/v1/checkout'][0]['callback'] instanceof Closure && $output['/wc/store/checkout'][0]['callback'] instanceof Closure, 'only exact native checkout paths decorated' );
	foreach ( array( 'permission_callback', 'args', 'allow_batch', 'methods' ) as $key ) { assert_route( $output['/wc/store/v1/checkout'][0][$key] === $native_handler[$key], 'native handler ' . $key . ' preserved' ); }
	assert_route( $output['/wc/store/v1/checkout/999'] === $input['/wc/store/v1/checkout/999'] && $output['/wc/store/v1/cart'] === $input['/wc/store/v1/cart'], 'order-pay/cart/unrelated routes untouched' );
	assert_route( Checkout_Lifecycle::checkout_routes( $output ) === $output, 'route decoration idempotent' );
	$input['/wc/store/v1/checkout'][0]['callback'] = array( $third, 'get_response' );
	assert_route( Checkout_Lifecycle::checkout_routes( $input )['/wc/store/v1/checkout'] === $input['/wc/store/v1/checkout'], 'third-party native subclass not replaced' );
	echo json_encode( array( 'passed' => true, 'checks' => $checks, 'real_http' => 0, 'real_database' => 0 ), JSON_THROW_ON_ERROR ) . "\n";
}
