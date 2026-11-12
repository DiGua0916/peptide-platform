<?php
/**
 * NF-02：测试单规则，用真正的 Test_Orders、Overview、Data\Metrics（别的 M6 用例里这三个有两个是替身）。
 * 纯逻辑：php -n m6-test-order-flag-pure.php
 *
 * 11-11 起主线的旧规则（Overview::is_test_order()）也认测试单标记了，所以现在新旧两条规则对同一张订单的结论应该一样：
 *   - 订单上的标记 _pc_test_order = 1（下单时收件信息全 1 自动打的，或店主事后标的）→ 测试单；
 *   - 标记 = 0（下单时地址正常）→ 不是测试单，哪怕地址后来被改成全 1；
 *   - 没有标记的老订单 → 看收货 / 账单地址是不是全 1；
 *   - 测试付款方式、演示商品 → 测试单。
 * 影子档下 reports.test_order 不应该再记任何差异；开着时测试单不进订单数和销售额。
 */

namespace PeptideCore {
	final class Meta {
		public const DEMO_SEED = '_pc_demo_seed';
	}
}

namespace PeptideCore\Tracking {
	final class Server_Events {
		public const ORDER_VISITOR_META = '_pc_track_visitor';
		public const ORDER_VISIT_META   = '_pc_track_visit';
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['t_options'] = array();
	$GLOBALS['t_demo']    = array( 77 ); // 演示商品的编号。
	function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default; }
	function update_option( $name, $value, $autoload = null ) { $GLOBALS['t_options'][ $name ] = $value; return true; }
	function apply_filters( $tag, $value, ...$args ) { return $value; }
	function add_action( $tag, $fn, $priority = 10, $accepted = 1 ) { return true; }
	function do_action( $tag, ...$args ) {}
	function get_post_meta( $id, $key, $single = false ) { return '_pc_demo_seed' === $key && in_array( (int) $id, $GLOBALS['t_demo'], true ) ? '1' : ''; }
	function wc_get_is_paid_statuses() { return array( 'processing', 'completed' ); }

	final class WC_Order_Item_Product {
		public function __construct( private int $product ) {}
		public function get_product_id() { return $this->product; }
	}
	/** 假订单：只有测试单规则和订单事实要读的字段。 */
	final class WC_Order {
		public array $meta     = array();
		public array $shipping = array( 'first_name' => 'Ann', 'last_name' => 'Lee', 'address_1' => '5 Main St', 'city' => 'Austin' );
		public array $billing  = array( 'first_name' => 'Ann', 'last_name' => 'Lee', 'address_1' => '5 Main St', 'city' => 'Austin' );
		public string $method_title = 'Card';
		public array $items    = array();
		public function __construct( public int $id ) {}
		public function get_id() { return $this->id; }
		public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
		public function get_address( $type = 'billing' ) { return 'shipping' === $type ? $this->shipping : $this->billing; }
		public function get_payment_method_title() { return $this->method_title; }
		public function get_items() { return $this->items; }
		public function get_status() { return 'processing'; }
		public function get_total() { return 20.0; }
		public function get_total_refunded() { return 0.0; }
		public function get_date_paid() { return new DateTimeImmutable( '@150' ); }
		public function get_date_created() { return new DateTimeImmutable( '@150' ); }
		public function get_customer_id() { return 0; }
		public function get_billing_email() { return ''; }
		public function get_currency() { return 'USD'; }
	}

	require dirname( __DIR__ ) . '/includes/class-test-orders.php';
	require dirname( __DIR__ ) . '/includes/class-overview.php';
	require dirname( __DIR__ ) . '/includes/data/class-metrics.php';
	require dirname( __DIR__ ) . '/includes/data/class-metrics-switch.php';
	require dirname( __DIR__ ) . '/includes/data/class-metrics-overlay.php';

	use PeptideCore\Data\Metrics;
	use PeptideCore\Data\Metrics_Overlay as Overlay;
	use PeptideCore\Data\Metrics_Switch as Sw;
	use PeptideCore\Overview;
	use PeptideCore\Test_Orders;

	$passed = 0;
	$check  = static function ( string $name, $ok ) use ( &$passed ): void {
		if ( ! $ok ) { throw new RuntimeException( 'FAILED: ' . $name ); }
		++$passed;
	};
	$ones = array( 'first_name' => '1', 'last_name' => '1', 'address_1' => '1', 'city' => '1' );

	$real = new WC_Order( 1 );                       // 普通订单，下单时标记 = 0。
	$real->meta[ Test_Orders::META ] = '0';
	$auto = new WC_Order( 2 );                       // 收件信息全 1，下单时自动标记 = 1。
	$auto->shipping                  = $ones;
	$auto->meta[ Test_Orders::META ] = '1';
	$flag = new WC_Order( 3 );                       // 地址正常，店主事后标成测试单（主店 #11841 就是这种）。
	$flag->meta[ Test_Orders::META ] = '1';
	$old = new WC_Order( 4 );                        // 没有标记的老订单，账单地址全 1。
	$old->billing = $ones;
	$edited = new WC_Order( 5 );                     // 下单时地址正常（标记 = 0），后来地址被改成全 1。
	$edited->shipping                  = $ones;
	$edited->meta[ Test_Orders::META ] = '0';
	$gateway = new WC_Order( 6 );                    // 测试付款方式。
	$gateway->method_title             = 'Test payment (sandbox)';
	$gateway->meta[ Test_Orders::META ] = '0';
	$demo = new WC_Order( 7 );                       // 含演示商品。
	$demo->items                     = array( new WC_Order_Item_Product( 77 ) );
	$demo->meta[ Test_Orders::META ] = '0';

	$expected = array( 1 => false, 2 => true, 3 => true, 4 => true, 5 => false, 6 => true, 7 => true );
	$orders   = array( $real, $auto, $flag, $old, $edited, $gateway, $demo );

	foreach ( $orders as $order ) {
		$id = $order->get_id();
		$check( "order {$id}: Data\\Metrics rule", $expected[ $id ] === Metrics::is_test_order( $order ) );
		$check( "order {$id}: the legacy rule agrees since 11-11", $expected[ $id ] === Overview::is_test_order( $order ) );
	}
	$check( 'the owner flag wins over the address in both directions', Test_Orders::is_test_order( $flag ) && ! Test_Orders::is_test_order( $edited ) );

	// 订单事实：测试单 paid 仍然是 true，但不算数（counted = false），所以不进订单数和销售额。
	$fact = Metrics::order_fact( $flag );
	$check( 'a flagged order is paid but not counted', true === $fact['paid'] && true === $fact['test'] && false === $fact['counted'] );
	$check( 'summing facts leaves the flagged order out and reports it as a test order', array( 'orders' => 1, 'sales_cents' => 2000, 'test_orders' => 1 ) === array_intersect_key( Metrics::sum_orders( array( Metrics::order_fact( $real ), $fact ) ), array( 'orders' => 0, 'sales_cents' => 0, 'test_orders' => 0 ) ) );

	// 三档下报表用的判断都一样，影子和开着时都不记差异。
	foreach ( array( 'off', 'shadow', 'on' ) as $mode ) {
		Sw::set( $mode );
		Overlay::flush();
		foreach ( $orders as $order ) {
			$check( "{$mode}: reports see order " . $order->get_id() . ' the same way', $expected[ $order->get_id() ] === Overlay::is_test_order( $order ) );
		}
		$check( "{$mode}: no test-order difference is logged", array() === array_filter( Sw::discard_pending(), static fn( array $i ): bool => 'reports.test_order' === $i['metric'] && $i['diffs'] ) );
	}

	echo 'PASS ', $passed, " test-order rule assertions (real Test_Orders / Overview / Metrics)\n";
}
