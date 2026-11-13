<?php
/**
 * NF-02：Data\Metrics 的口径（docs/metrics.md）。纯逻辑，不启动 WordPress：php -n m6-metrics-pure.php
 *
 * 覆盖：金额和比率的换算；一张订单的事实（已付款 / 测试单 / 已标记退款）；订单按付款时间归属；
 * 八层漏斗每一步不大于上一步、没有填地址的访问后面几步不算（FE-05 的读数侧）；弃单数和召回激活率；汇总和按天拆分。
 */
require __DIR__ . '/m6-stubs.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics.php';

use PeptideCore\Data\Metrics;
use PeptideCore\Recovery_Items;
use PeptideCore\Tracking\Schema;

$wpdb = new M6_DB();

// ---------- 换算 ----------
m6_check( 'cents rounds to whole cents', 1999 === Metrics::cents( 19.99 ) && 10 === Metrics::cents( '0.1' ) && 3550 === Metrics::cents( 35.5 ) && 0 === Metrics::cents( '' ) );
m6_check( 'money converts cents back', 19.99 === Metrics::money( 1999 ) && 0.0 === Metrics::money( 0 ) );
m6_check( 'rate is a percentage with two decimals', 7.14 === Metrics::rate( 1, 14 ) && 100.0 === Metrics::rate( 3, 3 ) && 0.0 === Metrics::rate( 0, 5 ) );
m6_check( 'rate without a denominator is null, never zero', null === Metrics::rate( 1, 0 ) && null === Metrics::rate( 0, 0 ) );

// ---------- 订单：时间段 [100, 200) ----------
m6_order( 1, 'processing', 20.0, 150, 150, 8, 18 );                       // 正常已付款。
$o2 = m6_order( 2, 'completed', 35.5, 160, 90, 9, 19 );                    // 之前创建、时间段内付款；已标记退款 5.50。
$o2->meta['_pc_refund_cents'] = 550;
m6_order( 3, 'processing', 10.0, 250, 150 );                              // 时间段内创建、之后才付款：不算在这一段。
m6_order( 4, 'processing', 1.0, 150, 150, 11, 21 )->ones = true;           // 收件信息全 1 的测试单。
m6_order( 5, 'refunded', 40.0, 170, 150 )->meta['_pc_refund_cents'] = 4000; // 付过款、全额标记退款：算订单，销售额为 0。
m6_order( 6, 'refunded', 15.0, null, 150 );                               // 标成已退款但从没付过款：不算。
m6_order( 7, 'on-hold', 99.0, null, 150 );                                // 保留（待确认）：不算。
m6_order( 8, 'processing', 5.0, null, 120, 99, 98 );                      // 已付款状态但没有付款时间：按创建时间算。
m6_order( 9, 'processing', 7.0, 150, 150 )->legacy_test = true;            // 测试付款方式 / 演示商品。
m6_order( 10, 'pending', 50.0, null, 150, 12, 22 );                       // 待付款。

$fact = Metrics::order_fact( $GLOBALS['m6_orders'][2] );
m6_check( 'order fact carries cents, marked refund and paid time', 3550 === $fact['gross_cents'] && 550 === $fact['refund_cents'] && 3000 === $fact['net_cents'] && 160 === $fact['paid_at'] && 90 === $fact['created_at'] && $fact['counted'] );
m6_check( 'order fact keeps the visitor link written at checkout', 9 === $fact['visitor'] && 19 === $fact['visit'] );
m6_check( 'all-ones test order is paid but not counted', Metrics::order_fact( $GLOBALS['m6_orders'][4] )['test'] && ! Metrics::order_fact( $GLOBALS['m6_orders'][4] )['counted'] );
m6_check( 'legacy test payment / demo product is still a test order', Metrics::is_test_order( $GLOBALS['m6_orders'][9] ) );
m6_check( 'refunded with a paid time received a payment notification', Metrics::is_paid_order( $GLOBALS['m6_orders'][5] ) );
m6_check( 'refunded without a paid time, on-hold and pending are not paid', ! Metrics::is_paid_order( $GLOBALS['m6_orders'][6] ) && ! Metrics::is_paid_order( $GLOBALS['m6_orders'][7] ) && ! Metrics::is_paid_order( $GLOBALS['m6_orders'][10] ) );
m6_check( 'unpaid order has no paid time', 0 === Metrics::order_fact( $GLOBALS['m6_orders'][10] )['paid_at'] );
$clamp = new WC_Order( 900, 'processing', 10.0 );
$clamp->meta['_pc_refund_cents'] = 5000;
m6_check( 'marked refund never exceeds the order amount', 1000 === Metrics::order_fact( $clamp )['refund_cents'] && 0 === Metrics::order_fact( $clamp )['net_cents'] );
$clamp->meta['_pc_refund_cents'] = -30;
m6_check( 'negative refund mark is ignored', 0 === Metrics::order_fact( $clamp )['refund_cents'] );
$clamp->wc_refund = 2.5;
m6_check( 'WooCommerce refunds are reported for reconciliation but never subtracted', 250 === Metrics::order_fact( $clamp )['wc_refund_cents'] && 1000 === Metrics::order_fact( $clamp )['net_cents'] );

$ids = array_column( Metrics::order_facts( 100, 200 ), 'id' );
sort( $ids );
m6_check( 'orders belong to the period they were paid in', array( 1, 2, 4, 5, 8, 9 ) === $ids );
$orders = Metrics::orders( 100, 200 );
m6_check( 'orders exclude both kinds of test orders', 4 === $orders['orders'] && 2 === $orders['test_orders'] );
m6_check( 'sales = paid amount - marked refunds', 10050 === $orders['gross_cents'] && 4550 === $orders['refund_cents'] && 5500 === $orders['sales_cents'] );
m6_check( 'average order value = sales / orders', 1375 === $orders['aov_cents'] );
m6_check( 'no orders gives zero, not a division error', array( 'orders' => 0, 'gross_cents' => 0, 'refund_cents' => 0, 'sales_cents' => 0, 'aov_cents' => 0, 'test_orders' => 0 ) === Metrics::sum_orders( array() ) );
m6_check( 'empty or reversed period reads nothing', array() === Metrics::order_facts( 200, 100 ) && 0 === Metrics::visitors( 200, 100 ) );
$before = count( $GLOBALS['m6_order_queries'] );
Metrics::order_facts( 100, 200 );
m6_check( 'the same period is read once per request', count( $GLOBALS['m6_order_queries'] ) === $before );

// ---------- 访客和漏斗 ----------
$wpdb->visit( 11, 1, 0 );
$wpdb->visit( 12, 2, 1 );
$wpdb->visit( 13, 3, 2 );
$wpdb->visit( 14, 4, 3 );
$wpdb->visit( 15, 5, 3 );
$wpdb->event( 15, 'checkout_address_completed' );
$wpdb->visit( 16, 6, 3 );
$wpdb->event( 16, 'checkout_address_completed' );
$wpdb->event( 16, 'checkout_shipping_selected' );
$wpdb->visit( 17, 7, 3 );
foreach ( array( 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected' ) as $type ) { $wpdb->event( 17, $type ); }
$wpdb->visit( 18, 8, 5, 1 );                                   // 付了款，只记到了填地址：物流、支付按已付款补上。
$wpdb->event( 18, 'checkout_address_completed' );
$wpdb->visit( 19, 9, 5, 2 );                                   // 跳步：没有填地址的记录却到了已付款。
$wpdb->event( 19, 'checkout_shipping_selected' );
$wpdb->event( 19, 'checkout_payment_selected' );
$wpdb->visit( 20, 10, 3 );                                     // 结账页一打开就带的默认支付方式，没填地址。
$wpdb->event( 20, 'checkout_payment_selected' );
$wpdb->visit( 21, 11, 5, 4 );                                  // 测试单。
foreach ( array( 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected' ) as $type ) { $wpdb->event( 21, $type ); }
$wpdb->visit( 22, 12, 4, 10 );                                 // 下了单没付款。
foreach ( array( 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected' ) as $type ) { $wpdb->event( 22, $type ); }
$wpdb->visit( 23, 13, 5, 999 );                                // 关联的订单已经不存在。
foreach ( array( 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected' ) as $type ) { $wpdb->event( 23, $type ); }
$wpdb->visit( 24, 14, 5, 8 );                                  // 订单上记的是别的访客 / 访问。
foreach ( array( 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected' ) as $type ) { $wpdb->event( 24, $type ); }
$wpdb->visit( 25, 1, 0 );                                      // 访客 1 的第二次访问。
$wpdb->visit( 26, 15, 5, 0, 150000, 1 );                       // 测试版留下的访问。
$wpdb->visit( 27, 16, 5 );
$wpdb->visitors[16]['is_test'] = 1;                            // 测试版留下的访客。
$wpdb->visit( 28, 17, 3, 0, 250000 );                          // 时间段之外。
$wpdb->visit( 29, 99, 1 );                                     // 访客行已经被清掉。

m6_check( 'visitors are distinct, real and inside the period', 14 === Metrics::visitors( 100, 200 ) );
$funnel = Metrics::funnel( 100, 200 );
m6_check( 'eight funnel layers in the fixed order', Metrics::FUNNEL_KEYS === array_keys( $funnel['counts'] ) && Metrics::FUNNEL_KEYS === array_column( $funnel['layers'], 'key' ) );
m6_check( 'funnel counts', array( 14, 13, 12, 11, 6, 5, 4, 1 ) === array_values( $funnel['counts'] ) );
m6_check( 'funnel visitors equal the visitor metric', Metrics::visitors( 100, 200 ) === $funnel['counts']['visitors'] );
m6_check( 'layer rates', 100.0 === $funnel['layers'][0]['visitor_rate'] && null === $funnel['layers'][0]['step_rate'] && 54.55 === $funnel['layers'][4]['step_rate'] && 7.14 === $funnel['layers'][7]['visitor_rate'] );
m6_check( 'paid visitors and conversion rate share the funnel definition', 1 === Metrics::paid_visitors( 100, 200 ) && 7.14 === Metrics::conversion_rate( 100, 200 ) );

$visits = array( 1 => array( 'visitor' => 1, 'stage' => 5, 'order' => 50 ) );
$order  = array( 50 => array( 'test' => false, 'counted' => true, 'visitor' => 1, 'visit' => 1 ) );
m6_check( 'FE-05: paid without an address record stops at checkout', array( 1, 1, 1, 1, 0, 0, 0, 0 ) === array_values( Metrics::funnel_counts( $visits, array( 1 => array( 'shipping' => true, 'payment' => true ) ), $order ) ) );
m6_check( 'FE-05: paid with an address record fills shipping and payment', array( 1, 1, 1, 1, 1, 1, 1, 1 ) === array_values( Metrics::funnel_counts( $visits, array( 1 => array( 'address' => true ) ), $order ) ) );
m6_check( 'payment selected without shipping is not counted', array( 1, 1, 1, 1, 1, 0, 0, 0 ) === array_values( Metrics::funnel_counts( array( 1 => array( 'visitor' => 1, 'stage' => 3, 'order' => 0 ) ), array( 1 => array( 'address' => true, 'payment' => true ) ), array() ) ) );
m6_check( 'address recorded below the checkout stage is not counted', array( 1, 1, 1, 0, 0, 0, 0, 0 ) === array_values( Metrics::funnel_counts( array( 1 => array( 'visitor' => 1, 'stage' => 2, 'order' => 0 ) ), array( 1 => array( 'address' => true ) ), array() ) ) );
m6_check( 'visits without a visitor are ignored', 0 === array_sum( Metrics::funnel_counts( array( 1 => array( 'visitor' => 0, 'stage' => 5, 'order' => 0 ) ), array(), array() ) ) );
// 同一次访问里先付了一单（50），又下了一张没付的（51）：访问上只留 51，付款事件里有 50。
$two = array( 50 => array( 'test' => false, 'counted' => true, 'visitor' => 1, 'visit' => 1 ), 51 => array( 'test' => false, 'counted' => false, 'visitor' => 1, 'visit' => 1 ) );
m6_check( 'a later unpaid order on the same visit does not hide the paid one', array( 1, 1, 1, 1, 1, 1, 1, 1 ) === array_values( Metrics::funnel_counts( array( 1 => array( 'visitor' => 1, 'stage' => 5, 'order' => 51, 'orders' => array( 50 ) ) ), array( 1 => array( 'address' => true ) ), $two ) ) );
m6_check( 'without the payment-event order the visit is not paid', array( 1, 1, 1, 1, 1, 0, 0, 0 ) === array_values( Metrics::funnel_counts( array( 1 => array( 'visitor' => 1, 'stage' => 5, 'order' => 51 ) ), array( 1 => array( 'address' => true ) ), $two ) ) );
$tests_only = array( 52 => array( 'test' => true, 'counted' => false, 'visitor' => 1, 'visit' => 1 ) );
m6_check( 'a visit whose orders are all test or deleted stops at checkout', array( 1, 1, 1, 1, 0, 0, 0, 0 ) === array_values( Metrics::funnel_counts( array( 1 => array( 'visitor' => 1, 'stage' => 5, 'order' => 52, 'orders' => array( 52, 999 ) ) ), array( 1 => array( 'address' => true, 'shipping' => true, 'payment' => true ) ), $tests_only ) ) );

// 每一步不大于上一步：500 组随机数据（固定种子）。
mt_srand( 20261110 );
for ( $round = 0; $round < 500; $round++ ) {
	$visits   = array();
	$evidence = array();
	$known    = array();
	for ( $i = 1; $i <= 40; $i++ ) {
		$visits[ $i ] = array( 'visitor' => mt_rand( 0, 12 ), 'stage' => mt_rand( 0, 5 ), 'order' => mt_rand( 0, 6 ) );
		foreach ( array( 'address', 'shipping', 'payment' ) as $step ) {
			if ( mt_rand( 0, 1 ) ) { $evidence[ $i ][ $step ] = true; }
		}
	}
	for ( $o = 1; $o <= 5; $o++ ) {
		$known[ $o ] = array( 'test' => (bool) mt_rand( 0, 1 ), 'counted' => (bool) mt_rand( 0, 1 ), 'visitor' => mt_rand( 1, 12 ), 'visit' => mt_rand( 1, 40 ) );
	}
	$counts = array_values( Metrics::funnel_counts( $visits, $evidence, $known ) );
	for ( $k = 1; $k < 8; $k++ ) {
		if ( $counts[ $k ] > $counts[ $k - 1 ] ) { throw new RuntimeException( 'FAILED: funnel step ' . $k . ' exceeds the previous step in round ' . $round ); }
	}
}
m6_check( 'FE-05: no funnel step exceeds the previous one in 500 random cohorts', true );

Schema::$installed = false;
Metrics::flush();
m6_check( 'without tracking tables visitor numbers are zero', 0 === Metrics::visitors( 100, 200 ) && 0 === array_sum( Metrics::funnel( 100, 200 )['counts'] ) && null === Metrics::conversion_rate( 100, 200 ) );
Schema::$installed = true;
Metrics::flush();

// ---------- 弃单 ----------
$later = new class() { public function get_total() { return '12.34'; } };
Recovery_Items::$items = array(
	array( 'created_ms' => 150000, 'status' => 'pending', 'total' => 10.0 ),
	array( 'created_ms' => 150000, 'status' => 'recalling', 'total' => 10.0 ),
	array( 'created_ms' => 150000, 'status' => 'failed', 'total' => 10.0 ),
	array( 'created_ms' => 150000, 'status' => 'success', 'total' => 30.5 ),
	array( 'created_ms' => 150000, 'status' => 'void', 'total' => 10.0 ),
	array( 'created_ms' => 250000, 'status' => 'success', 'total' => 10.0 ),
	array( 'created_ms' => 199999, 'status' => 'success', 'total' => 99.0, 'paid_later' => $later ),
);
$abandoned = Metrics::abandoned( 100, 200 );
m6_check( 'abandoned counts one row per customer, without void and outside rows', 5 === $abandoned['count'] && 2 === $abandoned['open'] && 1 === $abandoned['failed'] );
m6_check( 'recall rate = recovered / emailed', 4 === $abandoned['emailed'] && 2 === $abandoned['recovered'] && 50.0 === $abandoned['rate'] );
m6_check( 'recovered amount uses the order that was actually paid', 4284 === $abandoned['recovered_cents'] );
Metrics::abandoned( 100, 200 );
m6_check( 'abandoned list is read once and cached', 1 === Recovery_Items::$calls );
m6_check( 'no reminder sent means no rate', null === Metrics::abandoned_from_items( array( array( 'created_ms' => 5, 'status' => 'pending' ) ), 0, 10 )['rate'] );
Recovery_Items::$fail = true;
m6_check( 'a failing abandoned list never breaks the caller', 0 === Metrics::abandoned( 300, 400 )['count'] );
Recovery_Items::$fail = false;

// ---------- 汇总 ----------
$summary = Metrics::summary( 100, 200 );
m6_check( 'summary carries the seven metrics', 14 === $summary['visitors'] && 7.14 === $summary['conversion_rate'] && 4 === $summary['orders'] && 5500 === $summary['sales_cents'] && 1375 === $summary['aov_cents'] && 5 === $summary['abandoned'] && 50.0 === $summary['recall_rate'] );
m6_check( 'summary names its version, period and currency', Metrics::VERSION === $summary['version'] && 100 === $summary['from'] && 200 === $summary['to'] && 'USD' === $summary['currency'] );
m6_check( 'summary funnel has eight layers', 8 === count( $summary['funnel'] ) && 1 === $summary['paid_visitors'] );
m6_check( 'window uses the overview presets', array( 'from' => 0, 'to' => 700, 'bucket' => 'day' ) === Metrics::window( '7d' ) && array( 'from' => 100, 'to' => 200, 'bucket' => 'hour' ) === Metrics::window( 'nonsense' ) );
$preset = Metrics::preset( 'nonsense' );
m6_check( 'preset falls back to today and equals the summary of its window', 'today' === $preset['preset'] && 5500 === $preset['sales_cents'] && 100 === $preset['from'] );

// ---------- 按天拆分：[第 2 天 0 点, 第 4 天 0 点) ----------
$GLOBALS['m6_orders'] = array();
$wpdb                 = new M6_DB();
Metrics::flush();
$day = 86400;
m6_order( 31, 'processing', 10.0, $day + 10, $day + 10 );
m6_order( 32, 'completed', 20.5, 2 * $day + 5, $day + 20 );          // 第 2 天创建、第 3 天付款：算在第 3 天。
m6_order( 33, 'processing', 3.0, 2 * $day + 6, 2 * $day + 6 )->ones = true;
$wpdb->visit( 1, 1, 0, 0, ( $day + 1 ) * 1000 );
$wpdb->visit( 2, 2, 0, 0, ( 2 * $day + 1 ) * 1000 );
$wpdb->visit( 3, 3, 1, 0, ( 2 * $day + 2 ) * 1000 );
$rows = Metrics::daily( $day, 3 * $day );
m6_check( 'daily rows follow store days', array( '1970-01-02', '1970-01-03' ) === array_column( $rows, 'date' ) );
m6_check( 'daily orders and sales land on the paid day', array( 1, 1 ) === array_column( $rows, 'orders' ) && array( 1000, 2050 ) === array_column( $rows, 'sales_cents' ) );
m6_check( 'daily visitors', array( 1, 2 ) === array_column( $rows, 'visitors' ) && array( 0.0, 0.0 ) === array_column( $rows, 'conversion_rate' ) );
m6_check( 'daily rows add up to the period total', array_sum( array_column( $rows, 'sales_cents' ) ) === Metrics::orders( $day, 3 * $day )['sales_cents'] );
m6_check( 'reversed daily period is empty', array() === Metrics::daily( 5, 5 ) );

// 每日汇总里的弃单数（给总后台的每日汇总和 F3 的报表）：弃单按创建那天归属。
m6_check( 'daily rows carry abandoned counts, zero when there are none', array( 0, 0 ) === array_column( $rows, 'abandoned' ) && array( 0, 0 ) === array_column( $rows, 'recall_recovered' ) );
Recovery_Items::$items = array(
	array( 'created_ms' => ( $day + 100 ) * 1000, 'status' => 'pending', 'total' => 10.0 ),
	array( 'created_ms' => ( $day + 200 ) * 1000, 'status' => 'success', 'total' => 20.0 ),
	array( 'created_ms' => ( 2 * $day + 100 ) * 1000, 'status' => 'recalling', 'total' => 10.0 ),
	array( 'created_ms' => ( 2 * $day + 200 ) * 1000, 'status' => 'void', 'total' => 10.0 ),   // 自己付了款的：不算弃单。
	array( 'created_ms' => ( 3 * $day + 100 ) * 1000, 'status' => 'pending', 'total' => 10.0 ), // 时间段之外。
);
$GLOBALS['m6_transients'] = array();
$calls = Recovery_Items::$calls;
$rows  = Metrics::daily( $day, 3 * $day );
m6_check( 'daily abandoned: by the day the cart was abandoned', array( 2, 1 ) === array_column( $rows, 'abandoned' ) && array( 1, 1 ) === array_column( $rows, 'abandoned_open' ) );
m6_check( 'daily recall: reminded and recovered', array( 1, 1 ) === array_column( $rows, 'recall_emailed' ) && array( 1, 0 ) === array_column( $rows, 'recall_recovered' ) );
m6_check( 'daily abandoned adds up to the period total', array_sum( array_column( $rows, 'abandoned' ) ) === Metrics::abandoned( $day, 3 * $day )['count'] );
Metrics::daily( $day, 3 * $day );
m6_check( 'the abandoned list is read once per period for the daily rows, then cached', $calls + 2 === Recovery_Items::$calls );
$by_day = Metrics::abandoned_daily( $day, 3 * $day );
m6_check( 'abandoned_daily is keyed by store date and skips empty days', array( '1970-01-02', '1970-01-03' ) === array_keys( $by_day ) && 2000 === $by_day['1970-01-02']['recovered_cents'] );
Recovery_Items::$fail = true;
$GLOBALS['m6_transients'] = array();
$rows = Metrics::daily( $day, 3 * $day );
m6_check( 'a failing abandoned list leaves zeros and never breaks the daily rows', 2 === count( $rows ) && array( 0, 0 ) === array_column( $rows, 'abandoned' ) && array( 1, 1 ) === array_column( $rows, 'orders' ) );
Recovery_Items::$fail = false;
Recovery_Items::$items = array();

// ---------- 先付款、后又下了一张没付的单（从数据库读） ----------
$GLOBALS['m6_orders'] = array();
$wpdb                 = new M6_DB();
Metrics::flush();
m6_order( 40, 'processing', 20.0, 150, 150, 18, 30 );
m6_order( 41, 'pending', 9.0, null, 160, 18, 30 );
$wpdb->visit( 30, 18, 5, 41 );                                 // 访问上最后挂的是没付的 41。
$wpdb->event( 30, 'checkout_address_completed' );
$wpdb->event( 30, 'payment_success', 'server', 40 );
m6_check( 'the paid order is found through the payment event, not the last linked order', 1 === Metrics::paid_visitors( 100, 200 ) && 100.0 === Metrics::conversion_rate( 100, 200 ) );

echo 'PASS ', $GLOBALS['m6_passed'], " Data\\Metrics assertions\n";
