<?php
/** Run with php -n; synthetic orders only, no WordPress, database, HTTP or cards. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	class WC_Order {
		public int $id; public int $customer = 0; public string $gateway = 'pc_panapay'; public string $status = 'pending'; public string $key;
		public $book = ''; public bool $session_valid = true; public string $number;
		public function __construct( int $id ) { $this->id = $id; $this->key = 'synthetic-order-key-' . $id; $this->number = (string) $id; }
		public function get_id() { return $this->id; } public function get_customer_id() { return $this->customer; }
		public function get_payment_method() { return $this->gateway; } public function get_order_key() { return $this->key; }
		public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
		public function needs_payment() { return in_array( $this->status, array( 'pending', 'failed' ), true ); }
		public function is_paid() { return in_array( $this->status, array( 'processing', 'completed' ), true ); }
		public function get_meta( $key, $single ) { return $this->book; }
		public function get_checkout_payment_url( $on_checkout = false ) { return 'https://store.example.invalid/checkout/order-pay/' . $this->id . '/?key=' . $this->key . ( $on_checkout ? '&receipt=1' : '&pay_for_order=true' ); }
		public function get_view_order_url() { return 'https://store.example.invalid/my-account/view-order/' . $this->id . '/'; }
		public function get_order_number() { return $this->number; }
		public function get_formatted_order_total() { return '<span class="amount">$12.34</span>'; }
	}
	class Fake_Session { public array $values = array(); public int $writes = 0; public bool $throws = false; public function get( $key, $default = null ) { if ( $this->throws ) { throw new \RuntimeException( 'Synthetic optional storage unavailable' ); } return $this->values[ $key ] ?? $default; } public function set( $key, $value ) { $this->values[ $key ] = $value; ++$this->writes; } }
	function WC() { return $GLOBALS['wc']; } function get_current_user_id() { return $GLOBALS['user']; }
	function wc_get_order( $id ) { ++$GLOBALS['native_reads']; return $GLOBALS['orders'][ $id ] ?? false; }
	function wc_get_orders( $args ) { $GLOBALS['query'] = $args; return array_values( $GLOBALS['orders'] ); }
	function wc_get_checkout_url() { return 'https://store.example.invalid/checkout/'; }
	function is_admin() { return $GLOBALS['admin']; } function is_cart() { return $GLOBALS['cart']; } function is_checkout() { return $GLOBALS['checkout']; }
	function is_account_page() { return $GLOBALS['account']; } function is_wc_endpoint_url( $key ) { return $GLOBALS['endpoint'] === $key; }
	function add_action( ...$args ) { $GLOBALS['hooks'][] = $args; } function add_filter( ...$args ) { $GLOBALS['hooks'][] = $args; }
	function __( $s, $domain = '' ) { return $s; } function esc_html__( $s, $domain = '' ) { return htmlspecialchars( $s, ENT_QUOTES ); }
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } function esc_url( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
	function wp_kses_post( $s ) { return $s; } function nocache_headers() { ++$GLOBALS['no_cache']; }
	function get_queried_object_id() { return 9; } function has_block( $block, $id ) { return $GLOBALS['has_block']; }
	function wp_safe_remote_post() { throw new \RuntimeException( 'Real HTTP forbidden' ); } function wp_remote_post() { throw new \RuntimeException( 'Real HTTP forbidden' ); }
}
namespace VialCove\Panapay {
	class Gateway { public static function account_from_id( $id ) { return $id === 'pc_panapay' ? 'default' : null; } }
	class Order_Payload { public static function customer_url( $url ) { return $url; } }
	class Session_Service { public static int $reads = 0; public static function active_session( $order ) { ++self::$reads; if ( ! $order->session_valid ) { throw new \RuntimeException( 'Synthetic changed snapshot or untrusted session' ); } return array( 'type' => 'iframe', 'url' => 'https://cashier.example.invalid/private-session' ); } }
	class Checkout_Lifecycle { public static function new_checkout_url() { return 'https://store.example.invalid/checkout/?synthetic-new-intent=1'; } }
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-pending-orders.php';
	use VialCove\Panapay\Pending_Orders;
	$checks = 0;
	function check( $name, $pass ) { ++$GLOBALS['checks']; if ( ! $pass ) { throw new \RuntimeException( $name ); } }
	function reset_case() {
		$GLOBALS['wc'] = (object) array( 'session' => new Fake_Session() ); $GLOBALS['orders'] = array(); $GLOBALS['user'] = 0;
		$GLOBALS['native_reads'] = 0; $GLOBALS['no_cache'] = 0; $GLOBALS['query'] = null; $GLOBALS['hooks'] = array();
		$GLOBALS['cart'] = true; $GLOBALS['checkout'] = false; $GLOBALS['account'] = false; $GLOBALS['admin'] = false; $GLOBALS['endpoint'] = ''; $GLOBALS['has_block'] = false;
		$p = new \ReflectionProperty( Pending_Orders::class, 'rendered' ); $p->setValue( null, false );
	}
	function add_order( $id, $customer = 0, $status = 'pending' ) { $o = new WC_Order( $id ); $o->customer = $customer; $o->status = $status; $GLOBALS['orders'][ $id ] = $o; return $o; }
	function set_book( $order, $state ) { $order->book = array( 'active' => 'a', 'attempts' => array( 'a' => array( 'state' => $state ) ) ); }
	reset_case();
	check( 'unrecognized guest cannot enumerate orders', array() === Pending_Orders::visible_orders() && null === $GLOBALS['query'] && 0 === $GLOBALS['native_reads'] );
	$o = add_order( 10 ); Pending_Orders::remember( $o );
	check( 'guest session recognizes owned key', array( $o ) === Pending_Orders::visible_orders() );
	check( 'no email query used for guest', null === $GLOBALS['query'] );
	$GLOBALS['wc']->session->values['pc_panapay_pending_orders'][0]['key'] = 'different-key';
	check( 'wrong native key rejected', array() === Pending_Orders::visible_orders() );
	Pending_Orders::remember( $o ); $o->customer = 42;
	check( 'guest cannot show registered customer order', array() === Pending_Orders::visible_orders() );
	reset_case(); $o = add_order( 11, 42 ); Pending_Orders::remember( $o );
	check( 'guest cannot remember registered identity', 0 === $GLOBALS['wc']->session->writes );
	$GLOBALS['user'] = 42; Pending_Orders::remember( $o ); check( 'logged owner remembered', 1 === $GLOBALS['wc']->session->writes );
	$other = add_order( 12, 43 ); $guest = add_order( 13 ); $paid = add_order( 14, 42, 'processing' ); $alternate = add_order( 15, 42 ); $alternate->gateway = 'cod';
	check( 'logged query still enforces ownership and payment eligibility', array( $o ) === Pending_Orders::visible_orders() );
	check( 'query binds customer and statuses', 42 === $GLOBALS['query']['customer_id'] && array( 'pending', 'failed' ) === $GLOBALS['query']['status'] );
	reset_case(); $o = add_order( 16 ); $GLOBALS['wc']->session->values['order_awaiting_payment'] = 16;
	check( 'preexisting guest native pointer is discoverable', array( $o ) === Pending_Orders::visible_orders() );
	check( 'pointer remains unchanged', 16 === $GLOBALS['wc']->session->get( 'order_awaiting_payment' ) );
	$GLOBALS['wc']->session->values['store_api_draft_order'] = '999 OR 1=1';
	check( 'malformed pointer ignored without new lookup', array( $o ) === Pending_Orders::visible_orders() );
	foreach ( array( '0101', '9223372036854775808', true, 101.0, array( 101 ) ) as $bad_pointer ) {
		reset_case(); $GLOBALS['wc']->session->values['order_awaiting_payment'] = $bad_pointer;
		check( 'noncanonical native pointer is not a capability', array() === Pending_Orders::visible_orders() && 0 === $GLOBALS['native_reads'] );
	}
	reset_case(); for ( $i = 1; $i <= 12; ++$i ) { Pending_Orders::remember( add_order( $i ) ); }
	check( 'remembered capabilities bounded', 8 === count( $GLOBALS['wc']->session->get( 'pc_panapay_pending_orders' ) ) );
	check( 'displayed list bounded', 5 === count( Pending_Orders::visible_orders() ) );
	$entries = $GLOBALS['wc']->session->get( 'pc_panapay_pending_orders' ); foreach ( $entries as &$entry ) { $entry['at'] = time() - 604801; } unset( $entry ); $GLOBALS['wc']->session->set( 'pc_panapay_pending_orders', $entries );
	check( 'expired capabilities hidden', array() === Pending_Orders::visible_orders() );
	reset_case(); $o = add_order( 30 ); Pending_Orders::remember( $o ); $o->status = 'completed';
	check( 'paid order disappears from pending surface', array() === Pending_Orders::visible_orders() );
	reset_case(); $o = add_order( 31 ); Pending_Orders::remember( $o ); set_book( $o, 'created' );
	check( 'created session uses native receipt URL', str_contains( Pending_Orders::continuation( $o ), 'receipt=1' ) );
	$o->session_valid = false; check( 'changed or untrusted session has no continuation', null === Pending_Orders::continuation( $o ) );
	foreach ( array( 'unknown', 'creating', 'other' ) as $state ) { set_book( $o, $state ); check( $state . ' never offers unsafe continuation', null === Pending_Orders::continuation( $o ) ); }
	set_book( $o, 'failed' ); check( 'explicit failed attempt can use native payment form', str_contains( Pending_Orders::continuation( $o ), 'pay_for_order=true' ) );
	$o->book = ''; check( 'unstarted pending order can use native payment form', str_contains( Pending_Orders::continuation( $o ), 'pay_for_order=true' ) );
	$o->book = 'malformed'; check( 'malformed metadata hides payment link', null === Pending_Orders::continuation( $o ) );
	set_book( $o, 'created' ); $o->session_valid = true; $o->number = '<script>synthetic</script>';
	$html = Pending_Orders::markup();
	check( 'unpaid heading and amount present', str_contains( $html, 'Your unpaid orders' ) && str_contains( $html, '$12.34' ) );
	check( 'safe native continuation and explicit new checkout present', str_contains( $html, 'Continue payment' ) && str_contains( $html, 'synthetic-new-intent=1' ) );
	check( 'guest sees no native account view link', ! str_contains( $html, 'View order' ) );
	check( 'order label escaped', ! str_contains( $html, '<script>' ) && str_contains( $html, '&lt;script&gt;' ) );
	check( 'provider session and arbitrary customer details never rendered', ! str_contains( $html, 'cashier.example.invalid' ) && ! str_contains( $html, 'private-session' ) );
	check( 'one widget per render request', '' === Pending_Orders::markup() );
	reset_case(); $o = add_order( 40, 9 ); $GLOBALS['user'] = 9; set_book( $o, 'unknown' ); $html = Pending_Orders::markup();
	check( 'logged owner can view native order', str_contains( $html, 'View order' ) && str_contains( $html, '/view-order/40/' ) );
	check( 'unconfirmed state is informative and nonblocking', str_contains( $html, 'You can still place a new order' ) && ! str_contains( $html, 'Continue payment' ) );
	foreach ( array( 'order-pay', 'order-received' ) as $test_endpoint ) { reset_case(); Pending_Orders::remember( add_order( 50 ) ); $GLOBALS['endpoint'] = $test_endpoint; check( $test_endpoint . ' excludes widget', '' === Pending_Orders::markup() ); }
	reset_case(); Pending_Orders::protect_page(); check( 'private target disables shared HTML cache', defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE && 1 === $GLOBALS['no_cache'] );
	reset_case(); $GLOBALS['admin'] = true; Pending_Orders::protect_page(); check( 'admin not a public discovery surface', 0 === $GLOBALS['no_cache'] );
	reset_case(); $GLOBALS['wc']->session->throws = true; Pending_Orders::remember( add_order( 60 ) );
	check( 'optional remember storage failure cannot abort checkout', 0 === $GLOBALS['wc']->session->writes );
	check( 'optional storage failure does not block checkout rendering', array() === Pending_Orders::visible_orders() && '' === Pending_Orders::markup() );
	reset_case(); Pending_Orders::register_hooks(); $names = array_column( $GLOBALS['hooks'], 0 );
	foreach ( array( 'pc_panapay_checkout_detached', 'pc_panapay_session_created', 'render_block_woocommerce/cart', 'render_block_woocommerce/checkout' ) as $hook ) { check( $hook . ' registered', in_array( $hook, $names, true ) ); }
	if ( in_array( '--preview', $argv ?? array(), true ) ) {
		reset_case(); $GLOBALS['user'] = 42; $ready = add_order( 101, 42 ); set_book( $ready, 'created' ); $waiting = add_order( 102, 42 ); set_book( $waiting, 'unknown' );
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/pending-orders.css' );
		echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Isolated unpaid-order layout preview</title><style>body{margin:0;background:#fff;color:#213b46;font-family:Manrope,system-ui,sans-serif}main{max-width:1000px;margin:32px auto;padding:0 16px}h1{font-size:32px}p{line-height:1.5}:root{--ink:#213b46;--line:#d4dfe2;--paper:#f6f8f8}a{color:inherit}</style><style>' . $css . '</style><main><p>Isolated layout preview with synthetic orders.</p><h1>Checkout</h1>' . Pending_Orders::markup() . '<p>The existing native checkout continues below this nonblocking section.</p></main></html>';
	} else {
		echo 'PASS ' . $checks . " pending order privacy checks; zero real HTTP, orders or callbacks.\n";
	}
}
