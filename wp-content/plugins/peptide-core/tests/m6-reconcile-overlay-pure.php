<?php
/**
 * NF-02 对账用例（二）：已经接入开关的三个地方。纯逻辑：php -n m6-reconcile-overlay-pure.php
 *
 *   用例 1  概览页的核心数字和八层漏斗      Metrics_Overlay::overview()
 *   用例 2  概览页的“弃单激活”              Metrics_Overlay::recovery()
 *   用例 3  运营数据的五个核心指标          Metrics_Overlay::operations()（Operations::period() 调用它）
 *
 * 每个用例都检查：关 = 原样返回且 Metrics 一次都不调用；影子 = 页面仍是旧值、不一致进日志；开 = 换成新值；
 * 普通数据下旧结果 = 新结果；有差异时差异能用 Metrics_Reconcile 归到原因里。
 */
require __DIR__ . '/m6-stubs.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics-switch.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics-reconcile.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics-overlay.php';

use PeptideCore\Data\Metrics;
use PeptideCore\Data\Metrics_Overlay as Overlay;
use PeptideCore\Data\Metrics_Reconcile as Reconcile;
use PeptideCore\Data\Metrics_Switch as Sw;
use PeptideCore\Recovery_Items;

$wpdb   = new M6_DB();
$layers = static fn( array $counts ): array => Metrics::layers( array_combine( Metrics::FUNNEL_KEYS, $counts ) );
$series = static fn( int $orders, float $sales ): array => array(
	array( 'label' => '00:00', 'start' => '1970-01-01 00:00', 'visitors' => 5, 'page_views' => 9, 'orders' => $orders, 'sales' => $sales ),
	array( 'label' => '01:00', 'start' => '1970-01-01 01:00', 'visitors' => 0, 'page_views' => 0, 'orders' => 0, 'sales' => 0.0 ),
);
$events = static function ( int $visit ) use ( $wpdb ): void {
	foreach ( array( 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected' ) as $type ) { $wpdb->event( $visit, $type ); }
};

// 普通数据：两张已付款订单，各自的访问都填了地址、选了物流和支付。“今天” = [100, 200)。
m6_order( 1, 'processing', 20.0, 150, 150, 3, 13 );
m6_order( 2, 'completed', 12.34, 160, 150, 4, 14 );
$wpdb->visit( 11, 1, 0 );
$wpdb->visit( 12, 2, 2 );
$wpdb->visit( 13, 3, 5, 1 );
$events( 13 );
$wpdb->visit( 14, 4, 5, 2 );
$events( 14 );

// 旧算法（Overview::range_data / Operations::period）在这份数据上的结果。
$plain = array( 'range' => 'today', 'bucket' => 'hour', 'visitors' => 4, 'orders' => 2, 'sales' => 32.34, 'aov' => 16.17, 'conversion' => 50.0, 'test_orders' => 0, 'steps' => $layers( array( 4, 3, 3, 2, 2, 2, 2, 2 ) ), 'series' => $series( 2, 32.34 ), 'products' => array( 'kept' ) );
$kpis  = array( 'sales' => 32.34, 'visitors' => 4, 'registered' => 1, 'orders' => 2, 'conversion' => 50.0, 'aov' => 16.17, 'items' => 7, 'net_item_sales' => 30.0, 'refunds' => 0.0, 'avg_duration' => 12, 'customers' => 3, 'mobile' => 4 );
$recovery_legacy = array( 'emailed' => 3, 'recovered' => 1, 'rate' => 33.33, 'amount' => 20.0 );
$summary         = array( 'store' => 'Test store', 'recovery' => array( 'today' => $recovery_legacy, 'yesterday' => $recovery_legacy, '7d' => $recovery_legacy, '30d' => $recovery_legacy ) );

// ---------- 关：三个接入点都原样返回，Metrics 一次都不调用 ----------
m6_check( 'off: overview is returned untouched', $plain === Overlay::overview( $plain ) );
m6_check( 'off: recovery is returned untouched', $summary === Overlay::recovery( $summary, 'today' ) );
m6_check( 'off: operations KPIs are returned untouched', $kpis === Overlay::operations( $kpis, 100, 200 ) );
m6_check( 'off: no order query, no SQL, no log, no option', ! isset( $GLOBALS['m6_order_queries'] ) && array() === $wpdb->queries && array() === Sw::discard_pending() && array() === $GLOBALS['m6_options'] && array() === $GLOBALS['m6_transients'] );

// ---------- 用例 1：概览页 ----------
Sw::set( 'shadow' );
m6_check( 'case 1 shadow: the page still shows the legacy data', $plain === Overlay::overview( $plain ) );
Sw::flush();
m6_check( 'case 1: ordinary data, legacy equals metrics', 1 === Sw::log()['compared']['overview.totals'] && ! isset( Sw::log()['differed']['overview.totals'] ) );

// 再来一张收件信息全 1 的测试单：旧算法把它当真实订单。
m6_order( 3, 'processing', 1.0, 150, 150, 5, 15 )->ones = true;
$wpdb->visit( 15, 5, 5, 3 );
$events( 15 );
Metrics::flush();
$with_test = array( 'visitors' => 5, 'orders' => 3, 'sales' => 33.34, 'aov' => 11.11, 'conversion' => 60.0, 'steps' => $layers( array( 5, 4, 4, 3, 3, 3, 3, 3 ) ), 'series' => $series( 3, 33.34 ) ) + $plain;
m6_check( 'case 1 shadow: a difference never changes what the page shows', $with_test === Overlay::overview( $with_test ) );
$pending = Sw::discard_pending();
m6_check( 'case 1 shadow: the difference is logged path by path', array( 'orders', 'sales_cents', 'conversion', 'steps.address', 'steps.shipping', 'steps.payment', 'steps.paid' ) === array_column( $pending[0]['diffs'], 'path' ) && 'today' === $pending[0]['context']['range'] );
$why = Reconcile::orders( 100, 200 );
m6_check( 'case 1: the difference is explained by the test-order rule', $why['explained'] && array( 'test_order_rule' ) === array_keys( $why['reasons'] ) && -1 === $why['reasons']['test_order_rule']['orders'] && -100 === $why['reasons']['test_order_rule']['cents'] );

Sw::set( 'on' );
$on = Overlay::overview( $with_test );
m6_check( 'case 1 on: core numbers come from Data\\Metrics', 5 === $on['visitors'] && 2 === $on['orders'] && 32.34 === $on['sales'] && 16.17 === $on['aov'] && 40.0 === $on['conversion'] && 1 === $on['test_orders'] );
m6_check( 'case 1 on: funnel stops the test order at checkout', array( 5, 4, 4, 3, 2, 2, 2, 2 ) === array_column( $on['steps'], 'count' ) && Metrics::FUNNEL_KEYS === array_column( $on['steps'], 'key' ) );
m6_check( 'case 1 on: chart orders and sales follow the paid time', 2 === $on['series'][0]['orders'] && 32.34 === $on['series'][0]['sales'] && 0 === $on['series'][1]['orders'] && 0.0 === $on['series'][1]['sales'] );
m6_check( 'case 1 on: chart visitors and everything else are left alone', 5 === $on['series'][0]['visitors'] && 9 === $on['series'][0]['page_views'] && array( 'kept' ) === $on['products'] && 'hour' === $on['bucket'] );
m6_check( 'case 1 on: the result says which definition it uses', array( 'version' => Metrics::VERSION, 'mode' => 'on' ) === $on['metrics'] );
m6_check( 'case 1 on: totals equal the chart', $on['orders'] === array_sum( array_column( $on['series'], 'orders' ) ) && $on['sales'] === round( array_sum( array_column( $on['series'], 'sales' ) ), 2 ) );
Sw::discard_pending();

// ---------- 用例 2：弃单激活 ----------
$GLOBALS['m6_transients'] = array();
Recovery_Items::$calls    = 0;
Recovery_Items::$items    = array(
	array( 'created_ms' => 150000, 'status' => 'recalling', 'total' => 10.0 ),
	array( 'created_ms' => 150000, 'status' => 'success', 'total' => 30.5 ),
	array( 'created_ms' => 150000, 'status' => 'pending', 'total' => 10.0 ),
);
Sw::set( 'shadow' );
m6_check( 'case 2 shadow: the card still shows the legacy numbers', $summary === Overlay::recovery( $summary, 'today' ) );
$pending = Sw::discard_pending();
m6_check( 'case 2 shadow: only the selected period is compared', 'overview.recovery' === $pending[0]['metric'] && array( 'emailed' ) === array_column( $pending[0]['diffs'], 'path' ) && 1 === Recovery_Items::$calls );
Sw::set( 'on' );
$card     = Overlay::recovery( $summary, 'not-a-range' );
$expected = array( 'emailed' => 2, 'recovered' => 1, 'rate' => 50.0, 'amount' => 30.5 );
m6_check( 'case 2 on: all four periods use the abandoned list', array( 'today' => $expected, 'yesterday' => $expected, '7d' => $expected, '30d' => $expected ) === $card['recovery'] && 'Test store' === $card['store'] );
m6_check( 'case 2: a summary without the card is left alone', array( 'store' => 'x' ) === Overlay::recovery( array( 'store' => 'x' ), 'today' ) );
Sw::discard_pending();

// ---------- 用例 3：运营数据 ----------
Sw::set( 'shadow' );
$kpis_test = array( 'sales' => 33.34, 'visitors' => 5, 'orders' => 3, 'conversion' => 60.0, 'aov' => 11.11 ) + $kpis;
m6_check( 'case 3 shadow: KPIs still show the legacy values', $kpis_test === Overlay::operations( $kpis_test, 100, 200 ) );
$pending = Sw::discard_pending();
m6_check( 'case 3 shadow: differences are logged', 'operations.kpis' === $pending[0]['metric'] && array( 'orders', 'sales_cents', 'conversion' ) === array_column( $pending[0]['diffs'], 'path' ) );
Sw::set( 'on' );
$swapped = Overlay::operations( $kpis_test, 100, 200 );
m6_check( 'case 3 on: five KPIs come from Data\\Metrics', 32.34 === $swapped['sales'] && 5 === $swapped['visitors'] && 2 === $swapped['orders'] && 40.0 === $swapped['conversion'] && 16.17 === $swapped['aov'] );
m6_check( 'case 3 on: the other seven KPIs and the card order are untouched', array_keys( $kpis_test ) === array_keys( $swapped ) && array( 1, 7, 30.0, 0.0, 12, 3, 4 ) === array( $swapped['registered'], $swapped['items'], $swapped['net_item_sales'], $swapped['refunds'], $swapped['avg_duration'], $swapped['customers'], $swapped['mobile'] ) );
Sw::discard_pending();

// ---------- 新算法出错：页面照常显示旧值 ----------
Metrics::flush();
$wpdb = new class() {
	public function prepare( string $sql, ...$args ): string { return $sql; }
	public function get_var( string $sql ) { throw new RuntimeException( 'database is away' ); }
	public function get_results( string $sql, $mode = null ) { throw new RuntimeException( 'database is away' ); }
};
m6_check( 'on: a failing calculation leaves the overview untouched', $with_test === Overlay::overview( $with_test ) );
m6_check( 'on: a failing calculation leaves the KPIs untouched', $kpis_test === Overlay::operations( $kpis_test, 100, 200 ) );
$pending = Sw::discard_pending();
m6_check( 'on: the failure is logged for both', 2 === count( $pending ) && 'database is away' === $pending[0]['diffs'][0]['error'] );

echo 'PASS ', $GLOBALS['m6_passed'], " overlay reconciliation assertions (overview / recovery / operations)\n";
