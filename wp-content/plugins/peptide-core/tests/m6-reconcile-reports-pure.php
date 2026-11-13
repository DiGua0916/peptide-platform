<?php
/**
 * NF-02 对账用例（三）：各张报表共用的口径。纯逻辑：php -n m6-reconcile-reports-pure.php
 *
 *   用例 4  谁是测试单            Metrics_Overlay::is_test_order()（17 个报表类的 is_test() 都走它）
 *   用例 5  哪些订单算在时间段里  Metrics_Overlay::paid_orders()（Operations::paid_orders() 的结尾）
 *   用例 6  一张订单算多少钱、算在哪个时刻  Metrics_Overlay::order_sales() / order_time()
 *   用例 7  分组转化率            Metrics_Overlay::conversion() 和 Visit_Metrics::finish()（落地页 / 来源 / 分维度明细）
 *   用例 8  八层漏斗              Visitor_Funnel::period()：开着时和 Metrics::funnel() 在同一份数据上一致，每一步 ≤ 上一步
 *
 * 每个用例都检查：关 = 旧口径、不读开关以外的任何东西；影子 = 仍是旧口径、不一致进日志；开 = 新口径。
 */
require __DIR__ . '/m6-stubs.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics-switch.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics-reconcile.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics-overlay.php';
require dirname( __DIR__ ) . '/includes/data/class-visit-metrics.php';
require dirname( __DIR__ ) . '/includes/data/class-visitor-funnel.php';

use PeptideCore\Data\Metrics;
use PeptideCore\Data\Metrics_Overlay as Overlay;
use PeptideCore\Data\Metrics_Reconcile as Reconcile;
use PeptideCore\Data\Metrics_Switch as Sw;
use PeptideCore\Data\Visit_Metrics;
use PeptideCore\Data\Visitor_Funnel;

$wpdb  = new M6_DB();
$mode  = static function ( string $mode ): void {
	Sw::set( $mode );
	Sw::discard_pending();
	Overlay::flush();
	Metrics::flush();
};
$diffs = static fn( string $metric ): array => array_values( array_filter( Sw::discard_pending(), static fn( array $item ): bool => $metric === $item['metric'] ) );

// ---------- 用例 4：谁是测试单 ----------
$real   = m6_order( 1 );
$ones   = m6_order( 2 );
$demo   = m6_order( 3 );
$ones->ones        = true; // 收件信息全 1：只有新规则认。
$demo->legacy_test = true; // 测试付款方式 / 演示商品：两条规则都认。

m6_check( 'case 4 off: legacy rule only', false === Overlay::is_test_order( $real ) && false === Overlay::is_test_order( $ones ) && true === Overlay::is_test_order( $demo ) );
m6_check( 'case 4 off: nothing logged, no option written', array() === Sw::discard_pending() && array() === $GLOBALS['m6_options'] );

$mode( 'shadow' );
m6_check( 'case 4 shadow: reports still use the legacy rule', false === Overlay::is_test_order( $real ) && false === Overlay::is_test_order( $ones ) && true === Overlay::is_test_order( $demo ) );
Overlay::is_test_order( $ones ); // 同一个请求里再问一次：不重复记。
$logged = $diffs( 'reports.test_order' );
m6_check( 'case 4 shadow: the all-1 order is logged once, with its order number', 1 === count( $logged ) && array( 'order' => 2 ) === $logged[0]['context'] && false === $logged[0]['diffs'][0]['legacy'] && true === $logged[0]['diffs'][0]['metrics'] );

$mode( 'on' );
m6_check( 'case 4 on: the all-1 order is a test order in every report', false === Overlay::is_test_order( $real ) && true === Overlay::is_test_order( $ones ) && true === Overlay::is_test_order( $demo ) );

// ---------- 用例 5：哪些订单算在时间段里（“今天” = [100, 200)） ----------
$GLOBALS['m6_orders'] = array();
$a = m6_order( 11, 'processing', 20.0, 150, 150 ); // 今天创建、今天付款。
$b = m6_order( 12, 'processing', 30.0, 250, 150 ); // 今天创建、明天才付款。
$c = m6_order( 13, 'completed', 40.0, 150, 50 );   // 昨天创建、今天付款。
$legacy_orders = array( $a, $b ); // Operations::paid_orders() 按旧口径（时间段内创建）查出来的。
$ids           = static fn( array $orders ): array => array_map( static fn( $o ) => $o->get_id(), $orders );

$mode( 'off' );
unset( $GLOBALS['m6_order_queries'] );
m6_check( 'case 5 off: the legacy list comes back untouched', $legacy_orders === Overlay::paid_orders( $legacy_orders, 100, 200 ) );
m6_check( 'case 5 off: no order query at all', ! isset( $GLOBALS['m6_order_queries'] ) && array() === Sw::discard_pending() );

$mode( 'shadow' );
m6_check( 'case 5 shadow: reports still get the legacy list', $legacy_orders === Overlay::paid_orders( $legacy_orders, 100, 200 ) );
$logged = $diffs( 'reports.paid_orders' );
// 日志里 only_legacy / only_metrics 两行的 metrics 一栏分别是“只在旧口径里的订单号”和“只在新口径里的订单号”。
m6_check( 'case 5 shadow: the two lists are compared by order number', 1 === count( $logged ) && array( 'only_legacy', 'only_metrics' ) === array_column( $logged[0]['diffs'], 'path' ) && '12' === $logged[0]['diffs'][0]['metrics'] && '13' === $logged[0]['diffs'][1]['metrics'] );
$why = Reconcile::orders( 100, 200 );
m6_check( 'case 5: both differences are explained by paid-time attribution', $why['explained'] && array( 'paid_outside_range', 'created_outside_range' ) === array_keys( $why['reasons'] ) && array( 12 ) === $why['reasons']['paid_outside_range']['ids'] && array( 13 ) === $why['reasons']['created_outside_range']['ids'] );

$mode( 'on' );
m6_check( 'case 5 on: reports get the orders paid in the range', array( 11, 13 ) === $ids( Overlay::paid_orders( $legacy_orders, 100, 200 ) ) );
m6_check( 'case 5 on: same orders as the totals', 2 === Metrics::orders( 100, 200 )['orders'] && 6000 === Metrics::orders( 100, 200 )['sales_cents'] );

// 同一批订单时不记差异。
$mode( 'shadow' );
Overlay::paid_orders( array( $a, $c ), 100, 200 );
Sw::flush();
m6_check( 'case 5 shadow: identical lists are counted as compared, not as different', 1 === Sw::log()['compared']['reports.paid_orders'] && ! isset( Sw::log()['differed']['reports.paid_orders'] ) );
Sw::reset_log();

// ---------- 用例 6：一张订单算多少钱、算在哪个时刻 ----------
$refunded            = m6_order( 21, 'processing', 100.0, 180, 150 );
$refunded->wc_refund = 30.0;                                      // WooCommerce 里记的退款（旧口径减它）。
$refunded->meta[ Metrics::REFUND_META ] = 1000;                   // 总后台标记的退款 10.00（新口径减它）。
$unpaid_date = m6_order( 22, 'processing', 15.0, null, 150 );     // 状态已付款但没有付款时间（导入的订单）。

foreach ( array( 'off', 'shadow' ) as $legacy_mode ) {
	$mode( $legacy_mode );
	m6_check( "case 6 {$legacy_mode}: sales = total − WooCommerce refunds", 70.0 === Overlay::order_sales( $refunded ) && 15.0 === Overlay::order_sales( $unpaid_date ) );
	m6_check( "case 6 {$legacy_mode}: the order belongs to the moment it was created", 150 === Overlay::order_time( $refunded ) );
}
$mode( 'on' );
m6_check( 'case 6 on: sales = total − refunds marked by HQ', 90.0 === Overlay::order_sales( $refunded ) && 15.0 === Overlay::order_sales( $unpaid_date ) );
m6_check( 'case 6 on: the order belongs to the moment it was paid', 180 === Overlay::order_time( $refunded ) );
m6_check( 'case 6 on: no paid date falls back to the created date', 150 === Overlay::order_time( $unpaid_date ) );
$why = Reconcile::classify( array( Reconcile::row( $refunded, 100, 200 ) ) );
m6_check( 'case 6: the amount difference is explained by the refund basis', $why['explained'] && array( 'refund_basis' ) === array_keys( $why['reasons'] ) && 2000 === $why['reasons']['refund_basis']['cents'] );

// ---------- 用例 7：分组转化率 ----------
// 一组 10 个访客，其中 2 个人付了款，一共 3 张订单（有一个人买了两单）。
$mode( 'off' );
m6_check( 'case 7 off: orders ÷ visitors', 30.0 === Overlay::conversion( 3, 2, 10, 'sources' ) && null === Overlay::conversion( 3, 2, 0, 'sources' ) && array() === Sw::discard_pending() );
$mode( 'shadow' );
m6_check( 'case 7 shadow: still orders ÷ visitors', 30.0 === Overlay::conversion( 3, 2, 10, 'sources' ) );
$logged = $diffs( 'reports.conversion' );
m6_check( 'case 7 shadow: the two rates are logged under the report name', 1 === count( $logged ) && array( 'report' => 'sources' ) === $logged[0]['context'] && 30.0 === $logged[0]['diffs'][0]['legacy'] && 20.0 === $logged[0]['diffs'][0]['metrics'] );
Overlay::conversion( 2, 2, 10, 'sources' ); // 每人一单：两个算法一样。
Overlay::conversion( 3, 2, 10 );            // 没给报表名：不记。
m6_check( 'case 7 shadow: equal rates and unnamed calls are not logged', array() === $diffs( 'reports.conversion' ) );
$mode( 'on' );
m6_check( 'case 7 on: paying visitors ÷ visitors', 20.0 === Overlay::conversion( 3, 2, 10, 'sources' ) && null === Overlay::conversion( 3, 2, 0, 'sources' ) );

// Visit_Metrics（落地页 / 来源 / 分维度明细共用）：4 个访客；访客 1 买了两单；还有一单来自不在这一组访客里的人（访问在时间段之前）。
$group = Visit_Metrics::blank();
foreach ( array( 1, 2, 3, 4 ) as $visitor ) {
	Visit_Metrics::visit( $group, array( 'visitor_id' => $visitor, 'stage' => 2, 'user_id' => 0 ) );
}
foreach ( array( array( 31, 1 ), array( 32, 1 ), array( 33, 9 ) ) as [ $order_id, $visitor ] ) {
	Visit_Metrics::order( $group, Visit_Metrics::order_fact( m6_order( $order_id, 'processing', 10.0, 150, 150, $visitor, 0 ) ) );
}
$mode( 'off' );
$legacy_row = Visit_Metrics::finish( $group );
m6_check( 'case 7 off: Visit_Metrics keeps orders ÷ visitors', 75.0 === $legacy_row['conversion'] && 3 === $legacy_row['orders'] && 2 === $legacy_row['paid_uv'] && 30.0 === $legacy_row['sales'] );
$mode( 'shadow' );
m6_check( 'case 7 shadow: Visit_Metrics row is identical to off', $legacy_row === Visit_Metrics::finish( $group ) && 1 === count( $diffs( 'reports.conversion' ) ) );
$mode( 'on' );
$modern_row = Visit_Metrics::finish( $group );
m6_check( 'case 7 on: only buyers who are visitors of the group count, once each', 25.0 === $modern_row['conversion'] );
m6_check( 'case 7 on: nothing else in the row changes', array( 'conversion' ) === array_keys( array_diff_assoc( array_map( 'strval', $legacy_row ), array_map( 'strval', $modern_row ) ) ) );

// ---------- 用例 8：八层漏斗 ----------
$GLOBALS['m6_orders'] = array();
$steps = static function ( int $visit, array $types ) use ( $wpdb ): void {
	foreach ( $types as $type ) { $wpdb->event( $visit, 'checkout_' . $type ); }
};
// 访客 1：填地址、选物流、选支付、付款 —— 完整走完。
$wpdb->visit( 21, 1, 5, 1 );
$steps( 21, array( 'address_completed', 'shipping_selected', 'payment_selected' ) );
$wpdb->event( 21, 'payment_success', 'server', 1 );
m6_order( 1, 'processing', 20.0, 150, 150, 1, 21 );
// 访客 2：没有填地址的记录就付了款（FE-05 要拦的跳步）。
$wpdb->visit( 22, 2, 5, 2 );
$wpdb->event( 22, 'payment_success', 'server', 2 );
m6_order( 2, 'processing', 20.0, 150, 150, 2, 22 );
// 访客 3：填了地址直接付款（快捷支付，没有选物流 / 选支付的事件）。
$wpdb->visit( 23, 3, 5, 3 );
$steps( 23, array( 'address_completed' ) );
$wpdb->event( 23, 'payment_success', 'server', 3 );
m6_order( 3, 'completed', 20.0, 150, 150, 3, 23 );
// 访客 4：填了地址、选了物流，没付款。访客 5：只看了商品。
$wpdb->visit( 24, 4, 3 );
$steps( 24, array( 'address_completed', 'shipping_selected' ) );
$wpdb->visit( 25, 5, 1 );
// 访客 6：收件信息全 1 的测试单，走完了全程（测试单规则：只计入“发起结账”）。
$wpdb->visit( 26, 6, 5, 6 );
$steps( 26, array( 'address_completed', 'shipping_selected', 'payment_selected' ) );
$wpdb->event( 26, 'payment_success', 'server', 6 );
m6_order( 6, 'processing', 1.0, 150, 150, 6, 26 )->ones = true;
// 访客 7：测试版留下的访问（is_test = 1）。
$wpdb->visit( 27, 7, 2, 0, 150000, 1 );
// 访客 8：付过款、后来取消了的订单，走完了全程。
$wpdb->visit( 28, 8, 5, 8 );
$steps( 28, array( 'address_completed', 'shipping_selected', 'payment_selected' ) );
$wpdb->event( 28, 'payment_success', 'server', 8 );
m6_order( 8, 'cancelled', 20.0, 150, 150, 8, 28 );
$wpdb->devices = array( 21 => 'mobile', 22 => 'mobile', 23 => 'desktop' );

$counts = static fn( array $funnel ): array => array_column( $funnel['layers'], 'count', 'key' );
$funnel = static fn( string $group = '' ): array => ( new Visitor_Funnel() )->period( 100000, 200000, 'start_ms', $group );
$named  = static fn( array $numbers ): array => array_combine( Visitor_Funnel::KEYS, $numbers );

$mode( 'off' );
$legacy_funnel = $funnel();
m6_check( 'case 8 off: the legacy funnel, including the skipped step (5 paid > 3 chose payment)', $named( array( 8, 8, 7, 6, 5, 4, 3, 5 ) ) === $counts( $legacy_funnel['overall'] ) );
m6_check( 'case 8 off: nothing logged', array() === Sw::discard_pending() );

$mode( 'shadow' );
m6_check( 'case 8 shadow: the page gets exactly the legacy funnel', $legacy_funnel === $funnel() );
$logged = $diffs( 'reports.visitor_funnel' );
m6_check( 'case 8 shadow: the rule difference is logged layer by layer', 1 === count( $logged ) && array( 'shipping', 'payment', 'paid' ) === array_column( $logged[0]['diffs'], 'path' ) && array( 4, 3, 5 ) === array_column( $logged[0]['diffs'], 'legacy' ) && array( 5, 4, 3 ) === array_column( $logged[0]['diffs'], 'metrics' ) );
m6_check( 'case 8 shadow: the log says which period', 'start_ms' === $logged[0]['context']['basis'] && 'all' === $logged[0]['context']['scope'] && isset( $logged[0]['context']['from'], $logged[0]['context']['to'] ) );

$mode( 'on' );
$modern_funnel = $funnel();
$modern_counts = $counts( $modern_funnel['overall'] );
m6_check( 'case 8 on: skipped payment, test order, cancelled order and beta visit are all handled', $named( array( 7, 7, 6, 6, 4, 4, 3, 2 ) ) === $modern_counts );
$descending = static function ( array $list ): bool {
	for ( $i = 1, $n = count( $list ); $i < $n; ++$i ) {
		if ( $list[ $i ] > $list[ $i - 1 ] ) { return false; }
	}
	return true;
};
m6_check( 'case 8 on: every step is at most the previous one (and the legacy funnel was not)', $descending( array_values( $modern_counts ) ) && ! $descending( array_values( $counts( $legacy_funnel['overall'] ) ) ) );
m6_check( 'case 8 on: the same numbers as Data\Metrics on the same data', Metrics::funnel( 100, 200 )['counts'] === $modern_counts );
m6_check( 'case 8 on: Metrics::conversion_rate() is the last layer ÷ the first', Metrics::rate( $modern_counts['paid'], $modern_counts['visitors'] ) === Metrics::conversion_rate( 100, 200 ) );

$by_device = $funnel( 'device' )['groups'];
m6_check( 'case 8 on: grouped funnels follow the same rule', array( 'mobile', 'desktop', 'unknown' ) === array_keys( $by_device ) && $named( array( 2, 2, 2, 2, 1, 1, 1, 1 ) ) === $counts( $by_device['mobile'] ) && $named( array( 1, 1, 1, 1, 1, 1, 1, 1 ) ) === $counts( $by_device['desktop'] ) && $named( array( 4, 4, 3, 3, 2, 2, 1, 0 ) ) === $counts( $by_device['unknown'] ) );
m6_check( 'case 8 on: group conversion uses the paid layer of the group', 50.0 === Overlay::conversion( 2, $counts( $by_device['mobile'] )['paid'], $counts( $by_device['mobile'] )['visitors'], 'conversion.device' ) );

// 后四层的规则本身。
m6_check( 'tail_steps: no address, nothing counts — even a payment', array() === Metrics::tail_steps( array( 'shipping' => true, 'payment' => true ), true ) );
m6_check( 'tail_steps: address only', array( 'address' ) === Metrics::tail_steps( array( 'address' => true, 'payment' => true ), false ) );
m6_check( 'tail_steps: a payment implies shipping and payment selection', array( 'address', 'shipping', 'payment', 'paid' ) === Metrics::tail_steps( array( 'address' => true ), true ) );
m6_check( 'tail_steps: each step needs the previous one', array( 'address', 'shipping', 'payment' ) === Metrics::tail_steps( array( 'address' => true, 'shipping' => true, 'payment' => true ), false ) );

// 换回关：和最开始完全一样（开关不留痕迹）。
$mode( 'off' );
m6_check( 'back to off: the legacy funnel again', $legacy_funnel === $funnel() && array() === Sw::discard_pending() );

echo 'PASS ', $GLOBALS['m6_passed'], " report reconciliation assertions (test orders / paid orders / sales / conversion / funnel)\n";
