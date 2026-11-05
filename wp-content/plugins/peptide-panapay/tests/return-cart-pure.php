<?php
/** Exact cart cleanup policy + installed Woo functions; synthetic memory only, no WP boot/DB/HTTP. */
namespace Automattic\WooCommerce\Enums {
	final class OrderStatus { public const FAILED = 'failed', PENDING = 'pending', CANCELLED = 'cancelled'; }
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$wp_root = $argv[1] ?? 'D:/Codex/Sites/vialcovepeptidescom/app/public';
	if ( ! is_file( $wp_root . '/wp-includes/plugin.php' ) ) { throw new \RuntimeException( 'Supply the installed WordPress source directory.' ); }
	require $wp_root . '/wp-includes/plugin.php';
	function WC() { return $GLOBALS['woo']; }
	function get_current_user_id() { return $GLOBALS['user']; }
	function absint( $value ) { return abs( (int) $value ); }
	function wc_clean( $value ) { return (string) $value; }
	function wp_unslash( $value ) { return $value; }
	function wc_get_order( $id ) { return $GLOBALS['orders'][ (int) $id ] ?? false; }
	function wp_remote_post( ...$unused ) { throw new \RuntimeException( 'No HTTP allowed in this fixture.' ); }
	class WC_Order {
		public array $meta = array(); public int $customer = 0; public string $gateway = 'pc_panapay';
		public string $status = 'pending', $hash = 'cart-a', $key = 'synthetic-key';
		public function __construct( public int $id = 7 ) {}
		public function get_id() { return $this->id; }
		public function get_customer_id() { return $this->customer; }
		public function get_payment_method() { return $this->gateway; }
		public function has_status( $states ) { return in_array( $this->status, (array) $states, true ); }
		public function has_cart_hash( $hash ) { return hash_equals( $this->hash, $hash ); }
		public function get_order_key() { return $this->key; }
		public function meta_exists( $key ) { return array_key_exists( $key, $this->meta ); }
		public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	}
	class FixtureSession {
		public array $data = array(); public bool $exists = true, $throws = false; public int $writes = 0;
		public function has_session() { return $this->exists; }
		public function get( $key, $default = null ) { if ( $this->throws ) { throw new \RuntimeException( 'Memory session read failed' ); } return $this->data[ $key ] ?? $default; }
		public function set( $key, $value ) { $this->data[ $key ] = $value; ++$this->writes; }
		public function __unset( $key ) { unset( $this->data[ $key ] ); ++$this->writes; }
		public function __get( $key ) { return $this->get( $key ); }
		public function __isset( $key ) { return isset( $this->data[ $key ] ); }
	}
	class WC_Cart {
		public string $hash = 'cart-a'; public bool $empty = false, $throws = false; public array $empty_calls = array();
		public function get_cart_hash() { return $this->hash; }
		public function is_empty() { return $this->empty; }
		public function empty_cart( $persistent = true ) {
			if ( $this->throws ) { throw new \RuntimeException( 'Synthetic extension cart failure' ); }
			$this->empty_calls[] = $persistent; $this->empty = true;
			do_action( 'woocommerce_cart_emptied', $persistent );
			// Execute Woo's actual session cleanup with the same cart/session doubles.
			( new \WC_Cart_Session( $this ) )->destroy_cart_session();
		}
	}
}
namespace VialCove\Panapay {
	final class Gateway {
		public static function account_from_id( $id ) { return 'pc_panapay' === $id ? 'default' : ( preg_match( '/\Apc_panapay_[a-f0-9]{32}\z/D', $id ) ? substr( $id, 11 ) : null ); }
	}
	final class Session_Service {
		public static function parse_reference( $ref ) {
			if ( ! preg_match( '/\APP[a-f0-9]{8}-([1-9][0-9]*)-([a-f0-9]{32})\z/D', $ref, $m ) ) { throw new \RuntimeException( 'Bad fixture reference' ); }
			return array( 'order_id' => (int) $m[1], 'attempt_id' => $m[2] );
		}
		public static function return_status( $order, $ref ) { if ( $GLOBALS['status_throws'] ) { throw new \RuntimeException( 'Ledger boundary unavailable' ); } ++$GLOBALS['status_reads']; return $GLOBALS['return_state']; }
		public static function bound_gateway( $order ) { return $order->meta_exists( '_pc_panapay_sessions' ) && null !== Gateway::account_from_id( $order->gateway ) ? $order->gateway : null; }
		public static function frozen_gateway( $order ) { return 'processing' === $order->status ? null : self::bound_gateway( $order ); }
	}
}
namespace {
	use VialCove\Panapay\Return_Cart;
	$woo_root = $wp_root . '/wp-content/plugins/woocommerce';
	require $woo_root . '/includes/wc-cart-functions.php';
	require $woo_root . '/includes/class-wc-cart-session.php';
	require dirname( __DIR__ ) . '/includes/class-return-cart.php';
	const SNAPSHOT = 'pc_panapay_checkout_cart';
	const ATTEMPTS = '_pc_panapay_sessions';
	const AID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	const REF = 'PP1234abcd-7-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	$checks = array();
	function check( string $name, bool $pass ): void { $GLOBALS['checks'][] = array( 'name' => $name, 'pass' => $pass ); if ( ! $pass ) { throw new \RuntimeException( 'Failed: ' . $name ); } }
	function reset_case(): \WC_Order {
		$GLOBALS['woo'] = (object) array( 'session' => new FixtureSession(), 'cart' => new WC_Cart() );
		$GLOBALS['orders'] = array( 7 => new WC_Order( 7 ) ); $GLOBALS['user'] = 0; $GLOBALS['return_state'] = 'unknown';
		$GLOBALS['status_throws'] = false; $GLOBALS['status_reads'] = 0;
		$GLOBALS['wp'] = (object) array( 'query_vars' => array() ); $_GET = array();
		WC()->session->set( 'order_awaiting_payment', 7 );
		return $GLOBALS['orders'][7];
	}
	function freeze_order( \WC_Order $o, string $ref = REF, string $state = 'created' ): void {
		$a = VialCove\Panapay\Session_Service::parse_reference( $ref );
		$o->meta[ ATTEMPTS ] = array( 'active' => $a['attempt_id'], 'attempts' => array( $a['attempt_id'] => array( 'reference' => $ref, 'state' => $state ) ) );
	}
	function checkout( bool $classic = false ): \WC_Order {
		$o = reset_case();
		if ( $classic ) { do_action( 'woocommerce_checkout_order_processed', 7, array(), $o ); }
		else { do_action( 'woocommerce_store_api_checkout_order_processed', $o ); }
		freeze_order( $o ); do_action( 'pc_panapay_session_created', $o );
		return $o;
	}
	function paid( \WC_Order $o ): void { $o->status = 'processing'; $GLOBALS['return_state'] = 'paid'; $o->meta[ATTEMPTS]['attempts'][ AID ]['state'] = 'paid'; }
	Return_Cart::register_hooks(); Return_Cart::register_hooks();
	check( 'Registration is idempotent', 1 === count( $GLOBALS['wp_filter']['woocommerce_checkout_order_processed']->callbacks[1] ) );
	check( 'No read/totals/cart_updated invalidation hooks', ! has_action( 'woocommerce_after_calculate_totals', array( Return_Cart::class, 'invalidate' ) ) && ! has_action( 'woocommerce_cart_updated', array( Return_Cart::class, 'invalidate' ) ) );

	$o = reset_case(); Return_Cart::capture( $o ); $pending = WC()->session->get( SNAPSHOT );
	check( 'Pending capture contains only version/order/hash/user', array( 'version' => 1, 'order_id' => 7, 'hash' => 'cart-a', 'user' => 0 ) === $pending );
	check( 'Unbound snapshot never clears even a paid boundary', ! Return_Cart::clear( $o, REF ) );
	freeze_order( $o ); do_action( 'pc_panapay_session_created', $o );
	check( 'Created saved attempt binds exact reference once', REF === WC()->session->get( SNAPSHOT )['reference'] );
	paid( $o );
	check( 'Confirmed same order/session/cart clears via native API', Return_Cart::clear( $o, REF ) && array( true ) === WC()->cart->empty_calls );
	check( 'Native session destruction removes both order markers', null === WC()->session->get( 'order_awaiting_payment' ) && null === WC()->session->get( 'store_api_draft_order' ) );
	check( 'Successful clear consumes snapshot', null === WC()->session->get( SNAPSHOT ) );
	check( 'Repeated return does not clear twice', ! Return_Cart::clear( $o, REF ) && array( true ) === WC()->cart->empty_calls );

	$o = checkout( true ); paid( $o );
	check( 'Classic processed hook captures/binds/clears', Return_Cart::clear( $o, REF ) );
	$old = reset_case(); freeze_order( $old ); paid( $old );
	$new = new WC_Order( 8 ); $GLOBALS['orders'][8] = $new; WC()->session->set( 'store_api_draft_order', 8 );
	do_action( 'woocommerce_store_api_checkout_order_processed', $new );
	$pending = WC()->session->get( SNAPSHOT );
	check( 'New processed checkout captures pending snapshot despite stale awaiting marker', array( 'version' => 1, 'order_id' => 8, 'hash' => 'cart-a', 'user' => 0 ) === $pending );
	check( 'New unbound snapshot never authorizes previous paid order', ! Return_Cart::clear( $old, REF ) );
	$next_ref = 'PP1234abcd-8-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; freeze_order( $new, $next_ref );
	do_action( 'pc_panapay_session_created', $new );
	check( 'New attempt cannot bind before stale marker is replaced', $pending === WC()->session->get( SNAPSHOT ) );
	WC()->session->set( 'order_awaiting_payment', 8 ); do_action( 'pc_panapay_session_created', $new );
	check( 'Native payment marker replacement allows exact new-reference binding', $next_ref === WC()->session->get( SNAPSHOT )['reference'] );
	check( 'Previous return cannot reuse newly bound checkout snapshot', ! Return_Cart::clear( $old, REF ) );
	paid( $new );
	check( 'Confirmed new checkout clears only after exact marker/reference binding', Return_Cart::clear( $new, $next_ref ) );
	$o = checkout(); $o->hash = 'store-api-line-item-hash'; paid( $o );
	check( 'Store API line-item order hash is not confused with native cart hash', Return_Cart::clear( $o, REF ) );
	$o = checkout(); WC()->session->set( 'store_api_draft_order', '7' ); paid( $o );
	check( 'Two exact same-order markers can clear', Return_Cart::clear( $o, REF ) );
	$o = checkout(); WC()->session->__unset( 'order_awaiting_payment' ); WC()->session->set( 'store_api_draft_order', 7 ); paid( $o );
	check( 'Exact draft-only marker can clear', Return_Cart::clear( $o, REF ) );
	$o = checkout(); WC()->session->__unset( 'order_awaiting_payment' ); paid( $o );
	check( 'No order markers conservatively preserves current cart', ! Return_Cart::clear( $o, REF ) );
	foreach ( array( 8, '08', '7x', 7.0, true, false, 0, '0', array( 7 ) ) as $conflict ) {
		$o = checkout(); WC()->session->set( 'store_api_draft_order', $conflict ); paid( $o );
		check( 'Populated conflicting/malformed draft is rejected: ' . gettype( $conflict ) . ':' . count( $checks ), ! Return_Cart::clear( $o, REF ) );
	}
	$o = checkout(); WC()->session->set( 'store_api_draft_order', '' ); paid( $o );
	check( 'Empty-string draft is unpopulated', Return_Cart::clear( $o, REF ) );
	$o = checkout(); WC()->cart->hash = 'later-cart'; paid( $o );
	check( 'Later cart hash preserves added items', ! Return_Cart::clear( $o, REF ) );

	foreach ( array( 'unknown', 'failed', 'review' ) as $state ) {
		$o = checkout(); $GLOBALS['return_state'] = $state; $o->status = 'on-hold';
		check( 'Financial boundary ' . $state . ' never clears', ! Return_Cart::clear( $o, REF ) );
	}
	$o = checkout(); $GLOBALS['status_throws'] = true;
	check( 'Unavailable financial confirmation fails closed', ! Return_Cart::clear( $o, REF ) );
	$o = checkout(); paid( $o ); WC()->session->exists = false;
	check( 'No real existing session creates no cleanup/session', ! Return_Cart::clear( $o, REF ) );
	$o = checkout(); paid( $o ); $GLOBALS['woo']->cart = null;
	check( 'Missing cart fails closed', ! Return_Cart::clear( $o, REF ) );
	$o = checkout(); paid( $o ); $GLOBALS['woo']->session = null;
	check( 'Missing session fails closed', ! Return_Cart::clear( $o, REF ) );
	$o = checkout(); paid( $o ); WC()->session->throws = true;
	check( 'Session read failure does not clear', ! Return_Cart::clear( $o, REF ) );
	$o = checkout(); paid( $o ); WC()->cart->throws = true;
	check( 'Native empty failure leaves authorization consumed', ! Return_Cart::clear( $o, REF ) && null === WC()->session->get( SNAPSHOT ) );
	$o = checkout(); paid( $o ); $GLOBALS['user'] = 88;
	check( 'Guest checkout cannot clear a signed-in user cart', ! Return_Cart::clear( $o, REF ) );
	$o = reset_case(); $o->customer = 88; $GLOBALS['user'] = 88; Return_Cart::capture( $o ); freeze_order( $o ); Return_Cart::bind( $o ); paid( $o );
	check( 'Same authenticated checkout owner clears', Return_Cart::clear( $o, REF ) );
	$o = checkout(); paid( $o ); $o->customer = 88;
	check( 'Another registered customer cannot clear guest cart', ! Return_Cart::clear( $o, REF ) );
	$o = checkout(); paid( $o );
	check( 'Another reference cannot use an existing checkout snapshot', ! Return_Cart::clear( $o, 'PP1234abcd-7-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ) );
	$mutations = array( 'version' => '1', 'order_id' => '7', 'user' => '0', 'hash' => array(), 'reference' => array(), 'unexpected' => true );
	foreach ( $mutations as $key => $value ) {
		$o = checkout(); paid( $o ); $snapshot = WC()->session->get( SNAPSHOT ); $snapshot[ $key ] = $value; WC()->session->set( SNAPSHOT, $snapshot );
		check( 'Malformed snapshot field rejected: ' . $key, ! Return_Cart::clear( $o, REF ) );
	}

	foreach ( array( 'woocommerce_add_to_cart', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored', 'woocommerce_applied_coupon', 'woocommerce_removed_coupon', 'woocommerce_cart_emptied' ) as $hook ) {
		$o = checkout(); do_action( $hook, 'synthetic-value' ); paid( $o );
		check( 'Mutation invalidates without relying on hash difference: ' . $hook, ! Return_Cart::clear( $o, REF ) && null === WC()->session->get( SNAPSHOT ) );
		Return_Cart::capture( $o ); Return_Cart::bind( $o );
		check( 'Reopened old payment cannot recapture after mutation: ' . $hook, null === WC()->session->get( SNAPSHOT ) );
	}
	$o = checkout(); do_action( 'woocommerce_after_cart_item_quantity_update', 'line', 2, 1, WC()->cart ); paid( $o );
	check( 'Quantity mutation invalidates snapshot', ! Return_Cart::clear( $o, REF ) );
	$o = checkout(); do_action( 'woocommerce_after_cart_item_quantity_update', 'line', '1', 1, WC()->cart ); paid( $o );
	check( 'No-op quantity update preserves snapshot', Return_Cart::clear( $o, REF ) );
	$o = checkout(); do_action( 'woocommerce_cart_updated' ); do_action( 'woocommerce_after_calculate_totals', WC()->cart ); paid( $o );
	check( 'Read/totals hooks preserve unchanged checkout snapshot', Return_Cart::clear( $o, REF ) );
	$o = checkout(); $before = WC()->session->get( SNAPSHOT ); freeze_order( $o, 'PP1234abcd-7-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ); Return_Cart::bind( $o );
	check( 'A bound checkout cannot switch to another saved attempt', $before === WC()->session->get( SNAPSHOT ) );
	$o = reset_case(); $o->meta[ ATTEMPTS ] = ''; Return_Cart::capture( $o );
	check( 'Even an existing empty attempts meta forbids recapture', null === WC()->session->get( SNAPSHOT ) );
	$o = reset_case(); $o->customer = 55; Return_Cart::capture( $o );
	check( 'Capture refuses another owner', null === WC()->session->get( SNAPSHOT ) );
	$o = reset_case(); $o->gateway = 'other_gateway'; Return_Cart::capture( $o );
	check( 'Capture ignores other gateways', null === WC()->session->get( SNAPSHOT ) );
	$o = reset_case(); WC()->session->exists = false; $writes = WC()->session->writes; Return_Cart::capture( $o );
	check( 'Capture does not establish a new Woo session', $writes === WC()->session->writes && null === WC()->session->get( SNAPSHOT ) );

	// Installed original wc_clear_cart_after_payment() invokes the real WP filter.
	$o = checkout(); paid( $o ); wc_clear_cart_after_payment();
	check( 'Original Woo auto cleanup accepts exact confirmed snapshot', array( true ) === WC()->cart->empty_calls );
	foreach ( array( 'on-hold' => 'unknown', 'pending' => 'unknown', 'processing' => 'review' ) as $status => $state ) {
		$o = checkout(); $o->status = $status; $GLOBALS['return_state'] = $state;
		if ( 'pending' === $status ) { $GLOBALS['wp']->query_vars['order-received'] = 7; $_GET['key'] = $o->key; }
		wc_clear_cart_after_payment();
		check( 'Original Woo auto cleanup vetoed for ' . $status . '/' . $state, array() === WC()->cart->empty_calls );
	}
	$o = checkout(); paid( $o ); WC()->session->__unset( SNAPSHOT ); wc_clear_cart_after_payment();
	check( 'Original Woo cannot clear legacy paid order without snapshot', array() === WC()->cart->empty_calls );
	$o = checkout(); paid( $o ); WC()->session->set( 'store_api_draft_order', 8 ); wc_clear_cart_after_payment();
	check( 'Original Woo cannot ignore a different draft marker', array() === WC()->cart->empty_calls );
	$o = checkout(); paid( $o ); WC()->session->__unset( 'order_awaiting_payment' ); $GLOBALS['wp']->query_vars['order-received'] = 7; $_GET['key'] = $o->key;
	wc_clear_cart_after_payment();
	check( 'Order key bearer cannot clear without same-session marker', array() === WC()->cart->empty_calls );
	$o = checkout(); paid( $o ); $GLOBALS['user'] = 9; wc_clear_cart_after_payment();
	check( 'Original Woo cannot clear another logged-in owner', array() === WC()->cart->empty_calls );
	$o = reset_case(); $o->gateway = 'other_gateway'; $o->status = 'on-hold'; wc_clear_cart_after_payment();
	check( 'Other payment methods retain native auto cleanup behavior', array( true ) === WC()->cart->empty_calls );
	$o = checkout(); $o->gateway = 'other_gateway'; $o->status = 'on-hold'; wc_clear_cart_after_payment();
	check( 'Changed native gateway with Panapay history still vetoes unsafe native clearing', array() === WC()->cart->empty_calls );
	$o = reset_case(); $o->gateway = 'other_gateway'; $o->status = 'processing'; $o->meta[ ATTEMPTS ] = ''; wc_clear_cart_after_payment();
	check( 'Presence of malformed Panapay history is veto-only and never permits clearing', array() === WC()->cart->empty_calls );
	$o = reset_case(); $o->gateway = 'other_gateway'; $o->status = 'processing'; wc_clear_cart_after_payment();
	check( 'Paid other gateway without Panapay history retains native behavior', array( true ) === WC()->cart->empty_calls );
	$o = checkout(); paid( $o );
	check( 'Earlier false filter value is never promoted', false === Return_Cart::should_clear( false ) );
	check( 'Scope includes paid Panapay although frozen_gateway omits paid state', null === VialCove\Panapay\Session_Service::frozen_gateway( $o ) && true === Return_Cart::should_clear( true ) );
	$report = array( 'scope' => 'Actual Return_Cart source and installed Woo wc_clear_cart_after_payment()/WC_Cart_Session with in-memory doubles; WP hook API only, no WP bootstrap/DB/HTTP/customer data/payment.',
		'sources' => array( 'return_cart' => hash_file( 'sha256', dirname( __DIR__ ) . '/includes/class-return-cart.php' ), 'native_wc_clear' => hash_file( 'sha256', $woo_root . '/includes/wc-cart-functions.php' ), 'native_cart_session' => hash_file( 'sha256', $woo_root . '/includes/class-wc-cart-session.php' ) ),
		'passed' => count( $checks ), 'total' => count( $checks ), 'checks' => $checks );
	echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}
