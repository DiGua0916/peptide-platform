<?php
/** Actual lifecycle code; synthetic Woo/WordPress boundaries, zero DB, HTTP or payment. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	if ( in_array( '--rest-context', $argv ?? array(), true ) ) { define( 'REST_REQUEST', true ); }
	final class WC_Order {
		public function __construct( public int $id, public int $customer = 0, public string $status = 'pending', public bool $bound = true ) {}
		public function get_id(): int { return $this->id; }
		public function get_customer_id(): int { return $this->customer; }
		public function has_status( array $statuses ): bool { return in_array( $this->status, $statuses, true ); }
	}
	final class Model_Session {
		public array $data = array(); public int $saves = 0; public bool $save_throws = false;
		public function __construct( public string $customer = 'synthetic-guest-a' ) {}
		public function get( string $key, $default = null ) { return $this->data[ $key ] ?? $default; }
		public function set( string $key, $value ): void { $this->data[ $key ] = $value; }
		public function get_customer_id(): string { return $this->customer; }
		public function __unset( string $key ): void { unset( $this->data[ $key ] ); }
		public function save_data(): void { ++$this->saves; if ( $this->save_throws ) { throw new \RuntimeException( 'Synthetic save failure' ); } }
	}
	function WC(): object { return $GLOBALS['wc']; }
	function wc_get_order( int $id ) { return $GLOBALS['orders'][ $id ] ?? false; }
	function get_current_user_id(): int { return $GLOBALS['user']; }
	function is_admin(): bool { return $GLOBALS['admin']; }
	function wp_doing_ajax(): bool { return $GLOBALS['ajax']; }
	function is_checkout(): bool { return $GLOBALS['checkout']; }
	function is_wc_endpoint_url( string $name ): bool { return $GLOBALS['endpoint'] === $name; }
	function get_query_var( string $name, $default = '' ) { return $GLOBALS['query'][ $name ] ?? $default; }
	function wc_get_checkout_url(): string { return 'https://store.example.invalid/checkout/'; }
	function wp_create_nonce( string $action ): string { return substr( hash( 'sha256', 'synthetic-nonce|' . $action ), 0, 10 ); }
	function wp_verify_nonce( string $nonce, string $action ): bool { return hash_equals( wp_create_nonce( $action ), $nonce ); }
	function wp_unslash( string $value ): string { return stripslashes( $value ); }
	function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
	function add_action( ...$args ): void { $GLOBALS['hooks'][] = $args; }
	function do_action( string $hook, WC_Order $order ): void {
		$GLOBALS['events'][] = array( $hook, $order->get_id(), WC()->session->data );
		if ( $GLOBALS['observer_throws'] ) { throw new \RuntimeException( 'Synthetic observer failure' ); }
		if ( $GLOBALS['replacement'] ) { WC()->session->data['store_api_draft_order'] = 999; }
	}
}
namespace VialCove\Panapay {
	final class Session_Service {
		public static function bound_gateway( \WC_Order $order ): ?string { return $order->bound ? 'pc_panapay' : null; }
	}
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-checkout-lifecycle.php';
	use VialCove\Panapay\Checkout_Lifecycle as Lifecycle;
	$checks = 0;
	function check( string $label, bool $pass ): void { ++$GLOBALS['checks']; if ( ! $pass ) { throw new \RuntimeException( 'FAIL: ' . $label ); } }
	function reset_case(): void {
		$GLOBALS['wc'] = (object) array( 'session' => new Model_Session() );
		$GLOBALS['orders'] = array( 101 => new WC_Order( 101 ), 102 => new WC_Order( 102 ) );
		$GLOBALS['user'] = 0; $GLOBALS['admin'] = false; $GLOBALS['ajax'] = false; $GLOBALS['checkout'] = true;
		$GLOBALS['endpoint'] = ''; $GLOBALS['query'] = array(); $GLOBALS['events'] = array(); $GLOBALS['observer_throws'] = false; $GLOBALS['replacement'] = false;
		$_GET = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
		WC()->session->data = array( 'store_api_draft_order' => 101, 'order_awaiting_payment' => 101, 'cart' => 'synthetic-cart', 'customer' => 'synthetic-address', '_pc_panapay_pending_orders' => array( 101 ) );
	}
	function untouched( string $label ): void {
		$before = serialize( WC()->session->data ); $orders = serialize( $GLOBALS['orders'] );
		Lifecycle::on_checkout_navigation();
		check( $label . ' preserves session', $before === serialize( WC()->session->data ) );
		check( $label . ' preserves orders', $orders === serialize( $GLOBALS['orders'] ) );
		check( $label . ' does not notify/save', array() === $GLOBALS['events'] && 0 === WC()->session->saves );
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		reset_case(); untouched( 'REST context never becomes page navigation' );
		echo json_encode( array( 'checks' => $checks, 'passed' => true, 'http' => 0, 'orders_written' => 0 ), JSON_THROW_ON_ERROR ) . "\n";
		exit;
	}
	reset_case(); $order_snapshot = serialize( $GLOBALS['orders'] );
	Lifecycle::on_checkout_navigation();
	check( 'ordinary checkout GET detaches both matching old pointers', ! isset( WC()->session->data['store_api_draft_order'] ) && ! isset( WC()->session->data['order_awaiting_payment'] ) );
	check( 'cart customer and pending index survive', WC()->session->data === array( 'cart' => 'synthetic-cart', 'customer' => 'synthetic-address', '_pc_panapay_pending_orders' => array( 101 ) ) );
	check( 'old order objects remain byte identical', $order_snapshot === serialize( $GLOBALS['orders'] ) );
	check( 'one remembered event precedes detachment', 1 === count( $GLOBALS['events'] ) && $GLOBALS['events'][0][0] === 'pc_panapay_checkout_detached' && 101 === $GLOBALS['events'][0][2]['store_api_draft_order'] );
	check( 'changed session persisted once', 1 === WC()->session->saves );
	Lifecycle::on_checkout_navigation();
	check( 'second GET with no old pointer is a no-op', 1 === WC()->session->saves && 1 === count( $GLOBALS['events'] ) );

	foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', '' ) as $method ) { reset_case(); $_SERVER['REQUEST_METHOD'] = $method; untouched( $method . ' is not new navigation' ); }
	foreach ( array( 'order-pay', 'order-received' ) as $excluded_endpoint ) { reset_case(); $GLOBALS['endpoint'] = $excluded_endpoint; untouched( $excluded_endpoint ); }
	foreach ( array( 'admin', 'ajax' ) as $mode ) { reset_case(); $GLOBALS[ $mode ] = true; untouched( $mode ); }
	reset_case(); $GLOBALS['checkout'] = false; untouched( 'other public page' );
	reset_case(); $_GET['wc-api'] = 'pc_panapay_return'; untouched( 'query callback' );
	reset_case(); $GLOBALS['query']['wc-api'] = 'pc_panapay_notify'; untouched( 'rewrite callback' );
	foreach ( array( 'processing', 'completed', 'on-hold', 'cancelled', 'refunded' ) as $status ) { reset_case(); $GLOBALS['orders'][101]->status = $status; untouched( $status . ' order' ); }
	reset_case(); $GLOBALS['orders'][101]->bound = false; untouched( 'non-Panapay/unbound order' );
	reset_case(); unset( $GLOBALS['orders'][101] ); untouched( 'missing referenced order' );
	reset_case(); $GLOBALS['orders'][101]->status = 'failed'; Lifecycle::on_checkout_navigation();
	check( 'earlier failed Panapay order also separates on a new view', ! isset( WC()->session->data['store_api_draft_order'] ) );
	reset_case(); $GLOBALS['orders'][101]->customer = 7; untouched( 'guest cannot detach account order' );
	reset_case(); $GLOBALS['user'] = 7; untouched( 'account user cannot inherit guest pointer' );
	reset_case(); $GLOBALS['orders'][101]->customer = 8; $GLOBALS['user'] = 7; untouched( 'another account order' );
	reset_case(); $GLOBALS['orders'][101]->customer = 7; $GLOBALS['user'] = 7; Lifecycle::on_checkout_navigation();
	check( 'account owner can start new checkout view', ! isset( WC()->session->data['store_api_draft_order'] ) );
	foreach ( array( false, true, 0, -1, 101.0, '0101', '101x', '9223372036854775808', array( 101 ), null ) as $bad ) {
		reset_case(); WC()->session->data['store_api_draft_order'] = $bad; WC()->session->data['order_awaiting_payment'] = $bad; untouched( 'malformed session pointer ' . gettype( $bad ) );
	}
	reset_case(); WC()->session->data['store_api_draft_order'] = '101'; Lifecycle::on_checkout_navigation();
	check( 'native decimal string pointer accepted', ! isset( WC()->session->data['store_api_draft_order'] ) );
	reset_case(); WC()->session->data['order_awaiting_payment'] = 102; $GLOBALS['orders'][102]->bound = false; Lifecycle::on_checkout_navigation();
	check( 'different-method pointer retained', 102 === WC()->session->data['order_awaiting_payment'] && ! isset( WC()->session->data['store_api_draft_order'] ) );
	reset_case(); $GLOBALS['observer_throws'] = true; $before = serialize( WC()->session->data ); Lifecycle::on_checkout_navigation();
	check( 'failed observer does not discard old identity', $before === serialize( WC()->session->data ) && 0 === WC()->session->saves );
	reset_case(); $GLOBALS['replacement'] = true; Lifecycle::on_checkout_navigation();
	check( 'observer replacement pointer preserved', 999 === WC()->session->data['store_api_draft_order'] && ! isset( WC()->session->data['order_awaiting_payment'] ) );
	reset_case(); WC()->session->save_throws = true; $session_snapshot = serialize( WC()->session->data ); $order_snapshot = serialize( $GLOBALS['orders'] ); Lifecycle::on_checkout_navigation();
	$expected_values = unserialize( $session_snapshot ); $actual_values = WC()->session->data; ksort( $expected_values ); ksort( $actual_values );
	check( 'failed optional save restores original pointers in memory', $expected_values === $actual_values );
	check( 'failed save never changes old orders', $order_snapshot === serialize( $GLOBALS['orders'] ) );
	check( 'failed save is attempted once and not retried', 1 === WC()->session->saves );
	reset_case(); $GLOBALS['replacement'] = true; WC()->session->save_throws = true; Lifecycle::on_checkout_navigation();
	check( 'failed save does not overwrite observer replacement', 999 === WC()->session->data['store_api_draft_order'] && 101 === WC()->session->data['order_awaiting_payment'] );
	reset_case(); WC()->session->customer = ''; untouched( 'missing session identity' );
	reset_case(); $GLOBALS['wc']->session = null; Lifecycle::on_checkout_navigation(); check( 'missing session does not initialize one', null === WC()->session );

	reset_case(); $url = Lifecycle::new_checkout_url(); parse_str( parse_url( $url, PHP_URL_QUERY ), $args );
	check( 'new link uses native checkout URL', str_starts_with( $url, wc_get_checkout_url() . '?' ) );
	check( 'new link has random intent and nonce', preg_match( '/\A[a-f0-9]{32}\z/', $args['pc_panapay_new_checkout'] ) && is_string( $args['pc_panapay_checkout_nonce'] ) );
	check( 'successive links use different intent', $url !== Lifecycle::new_checkout_url() );
	$_GET = $args; Lifecycle::on_checkout_navigation(); check( 'matching session nonce allows explicit new checkout', ! isset( WC()->session->data['store_api_draft_order'] ) );
	reset_case(); WC()->session->customer = 'synthetic-guest-b'; $_GET = $args; untouched( 'other guest cannot replay nonce' );
	reset_case(); $_GET = array( 'pc_panapay_new_checkout' => $args['pc_panapay_new_checkout'] ); untouched( 'missing nonce' );
	reset_case(); $_GET = array( 'pc_panapay_checkout_nonce' => $args['pc_panapay_checkout_nonce'] ); untouched( 'missing intent' );
	reset_case(); $_GET = $args; $_GET['pc_panapay_checkout_nonce'] = 'bad'; untouched( 'bad nonce' );
	reset_case(); $_GET = $args; $_GET['pc_panapay_new_checkout'] = array( 'bad' ); untouched( 'array intent' );
	reset_case(); $_GET = $args; $_GET['pc_panapay_checkout_nonce'] = array( 'bad' ); untouched( 'array nonce' );
	reset_case(); $GLOBALS['wc']->session = null; check( 'link degrades to native checkout without session', wc_get_checkout_url() === Lifecycle::new_checkout_url() );
	reset_case(); $GLOBALS['hooks'] = array(); Lifecycle::register_hooks(); Lifecycle::register_hooks();
	check( 'hook registration idempotent and navigation-only', 1 === count( $GLOBALS['hooks'] ) && 'template_redirect' === $GLOBALS['hooks'][0][0] && 5 === $GLOBALS['hooks'][0][2] );
	echo json_encode( array( 'checks' => $checks, 'passed' => true, 'http' => 0, 'orders_written' => 0 ), JSON_THROW_ON_ERROR ) . "\n";
}
