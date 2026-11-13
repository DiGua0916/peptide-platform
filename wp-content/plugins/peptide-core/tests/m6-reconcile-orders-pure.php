<?php
/**
 * NF-02 对账用例（一）：订单数、销售额、访客数的新旧口径。纯逻辑：php -n m6-reconcile-orders-pure.php
 *
 * 旧口径 = 概览页 / 运营数据（时间段内创建、已付款状态、去掉测试付款和演示商品、减 WooCommerce 退款；访客不看 is_test）。
 * 新口径 = Data\Metrics。要求：没有特殊订单时两边完全相等；有差异时每一分钱、每一张订单都落在一个写明的原因里。
 */
require __DIR__ . '/m6-stubs.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics-reconcile.php';

use PeptideCore\Data\Metrics;
use PeptideCore\Data\Metrics_Reconcile as Reconcile;

$wpdb = new M6_DB();

// ---------- 旧结果 = 新结果：普通订单，时间段 [100, 200) ----------
m6_order( 1, 'processing', 20.0, 150, 150 );
m6_order( 2, 'completed', 12.34, 199, 100 );
$plain = Reconcile::orders( 100, 200 );
m6_check( 'ordinary orders: legacy equals metrics', $plain['legacy'] === $plain['metrics'] && array( 'orders' => 2, 'sales_cents' => 3234 ) === $plain['metrics'] );
m6_check( 'ordinary orders: nothing to explain', array() === $plain['reasons'] && $plain['explained'] && array( 'orders' => 0, 'sales_cents' => 0 ) === $plain['delta'] );
m6_check( 'legacy total matches the old formula (total - WooCommerce refund)', 3234 === Metrics::cents( 20.0 + 12.34 ) );

// ---------- 每一种差异 ----------
$GLOBALS['m6_orders'] = array();
Metrics::flush();
m6_order( 1, 'processing', 20.0, 150, 150 );                               // 两边都算，金额相同。
m6_order( 2, 'processing', 10.0, 250, 150 );                               // 时间段内创建、之后付款。
m6_order( 3, 'processing', 15.0, 150, 50 );                                // 之前创建、时间段内付款。
m6_order( 4, 'processing', 1.0, 150, 150 )->ones = true;                    // 收件信息全 1 的测试单。
m6_order( 5, 'refunded', 40.0, 160, 150 )->meta['_pc_refund_cents'] = 4000; // 已退款但付过款。
m6_order( 6, 'processing', 30.0, 150, 150 )->wc_refund = 5.0;               // 只有 WooCommerce 退款。
m6_order( 7, 'processing', 30.0, 150, 150 )->meta['_pc_refund_cents'] = 1000; // 只有总后台标记的退款。
m6_order( 8, 'processing', 9.0, 150, 150 )->legacy_test = true;            // 旧规则的测试单：两边都不算。
m6_order( 9, 'pending', 50.0, null, 150 );                                 // 待付款：两边都不算。

$r = Reconcile::orders( 100, 200 );
m6_check( 'legacy totals', array( 'orders' => 5, 'sales_cents' => 8600 ) === $r['legacy'] );
m6_check( 'metrics totals equal Data\\Metrics', array( 'orders' => 5, 'sales_cents' => 8500 ) === $r['metrics'] && 5 === Metrics::orders( 100, 200 )['orders'] && 8500 === Metrics::orders( 100, 200 )['sales_cents'] );
m6_check( 'delta', array( 'orders' => 0, 'sales_cents' => -100 ) === $r['delta'] );
$reasons = $r['reasons'];
ksort( $reasons );
m6_check( 'every difference has a named reason', array( 'created_outside_range', 'paid_outside_range', 'refund_basis', 'refunded_status', 'test_order_rule' ) === array_keys( $reasons ) );
m6_check( 'paid later: legacy only', -1 === $reasons['paid_outside_range']['orders'] && -1000 === $reasons['paid_outside_range']['cents'] && array( 2 ) === $reasons['paid_outside_range']['ids'] );
m6_check( 'created earlier: metrics only', 1 === $reasons['created_outside_range']['orders'] && 1500 === $reasons['created_outside_range']['cents'] && array( 3 ) === $reasons['created_outside_range']['ids'] );
m6_check( 'all-ones test order: legacy only', -1 === $reasons['test_order_rule']['orders'] && -100 === $reasons['test_order_rule']['cents'] && array( 4 ) === $reasons['test_order_rule']['ids'] );
m6_check( 'refunded but paid: metrics only, net zero', 1 === $reasons['refunded_status']['orders'] && 0 === $reasons['refunded_status']['cents'] && array( 5 ) === $reasons['refunded_status']['ids'] );
m6_check( 'refund basis: same orders, different money', 0 === $reasons['refund_basis']['orders'] && -500 === $reasons['refund_basis']['cents'] && array( 6, 7 ) === $reasons['refund_basis']['ids'] );
m6_check( 'reasons add up to the delta exactly', $r['delta']['orders'] === array_sum( array_column( $reasons, 'orders' ) ) && $r['delta']['sales_cents'] === array_sum( array_column( $reasons, 'cents' ) ) );
m6_check( 'nothing is left unexplained', $r['explained'] && array() === $r['unexplained']['ids'] );
m6_check( 'every reason has a plain explanation', ! array_diff( array_keys( $reasons ), array_keys( Reconcile::REASONS ) ) && '' !== $reasons['refund_basis']['why'] );

$odd = Reconcile::classify( array( array( 'id' => 77, 'legacy' => true, 'metrics' => false, 'legacy_cents' => 500, 'metrics_cents' => 0, 'created_in' => true, 'paid_in' => true, 'status_paid' => true, 'test' => false, 'legacy_test' => false ) ) );
m6_check( 'a difference without a known reason is reported as unexplained', ! $odd['explained'] && array( 77 ) === $odd['unexplained']['ids'] && -500 === $odd['unexplained']['cents'] );

// ---------- 访客 ----------
$wpdb->visit( 1, 1 );
$wpdb->visit( 2, 2 );
$wpdb->visit( 3, 3, 0, 0, 150000, 1 );    // 只有测试标记的访问。
$wpdb->visit( 4, 4 );
$wpdb->visitors[4]['is_test'] = 1;       // 测试访客。
$wpdb->visit( 5, 95 );                   // 访客行已经不在。
$wpdb->visit( 6, 5, 0, 0, 150000, 1 );    // 访客 5：一次测试访问 + 一次真实访问，算真实访客。
$wpdb->visit( 7, 5 );
$wpdb->visit( 8, 6, 0, 0, 250000 );       // 时间段之外。
$v = Reconcile::visitors( 100, 200 );
m6_check( 'visitor totals', 6 === $v['legacy'] && 3 === $v['metrics'] && -3 === $v['delta'] );
m6_check( 'visitor differences are orphan visits and test flags', -1 === $v['reasons']['orphan_visits']['visitors'] && -2 === $v['reasons']['test_flag']['visitors'] );
m6_check( 'visitor delta fully explained', 0 === $v['unexplained'] && $v['explained'] );
m6_check( 'no visits, nothing to explain', Reconcile::visitors( 300, 400 )['explained'] && 0 === Reconcile::visitors( 300, 400 )['legacy'] );

// ---------- 整份报告 ----------
$report = Reconcile::report( 'today' );
m6_check( 'report covers orders, visitors, conversion and recovery', array( 'preset', 'from', 'to', 'orders', 'visitors', 'conversion', 'recovery', 'explained' ) === array_keys( $report ) && $report['explained'] );
m6_check( 'conversion shows both definitions side by side', 83.33 === $report['conversion']['legacy'] && 0.0 === $report['conversion']['metrics'] && '' !== $report['conversion']['why'] );
m6_check( 'recovery shows both sources side by side', array( 'emailed' => 0, 'recovered' => 0, 'rate' => null ) === $report['recovery']['legacy'] && null === $report['recovery']['metrics']['rate'] );

echo 'PASS ', $GLOBALS['m6_passed'], " reconciliation (orders / visitors) assertions\n";
