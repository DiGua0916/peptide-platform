<?php
/**
 * FE-05：漏斗推进规则。纯逻辑，加载真正的 Tracker::raise_stage() 和 Funnel_Guard：php -n m6-funnel-guard-pure.php
 *
 * 要证明的事：
 * 1. 开关关着：和以前一样用 GREATEST 推进，不查事件、不写任何计数。
 * 2. 开关开着：跳过填地址直接下单 / 付款的访问不推进（订单号照挂），并记异常；有填地址记录的正常推进。
 * 3. 影子：照旧推进，只记“如果拦会拦掉多少”。
 * 4. 测试单不推进到 4、5，也不算异常；低于 4 的阶段不受限制。
 * 5. 填地址事件晚到时补推进，只补一次。
 * 6. 规则开着之后，漏斗每一步不大于上一步。
 */
require __DIR__ . '/m6-stubs.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics-switch.php';
require dirname( __DIR__ ) . '/includes/tracking/class-funnel-guard.php';
require dirname( __DIR__ ) . '/includes/tracking/class-tracker.php';

use PeptideCore\Data\Metrics;
use PeptideCore\Data\Metrics_Switch;
use PeptideCore\Tracking\Funnel_Guard as Guard;
use PeptideCore\Tracking\Tracker;

$wpdb  = new M6_DB();
$today = gmdate( 'Y-m-d' );
$stage = static fn( int $visit ): int => (int) $GLOBALS['wpdb']->visits[ $visit ]['stage'];
$order = static fn( int $visit ): int => (int) $GLOBALS['wpdb']->visits[ $visit ]['order_id'];

// ---------- 纯判断 ----------
$pass = array( 'raise' => true, 'reason' => '', 'anomaly' => false );
m6_check( 'off passes everything', $pass === Guard::decide( 'off', 5, false, true ) );
m6_check( 'stages below 4 are never gated', $pass === Guard::decide( 'on', 3, false, false ) && $pass === Guard::decide( 'on', 1, false, true ) );
m6_check( 'on blocks 4 and 5 without an address record', array( 'raise' => false, 'reason' => 'no_address', 'anomaly' => true ) === Guard::decide( 'on', 4, false, false ) && ! Guard::decide( 'on', 5, false, false )['raise'] );
m6_check( 'on passes with an address record', $pass === Guard::decide( 'on', 4, true, false ) && $pass === Guard::decide( 'on', 5, true, false ) );
m6_check( 'shadow raises but reports the anomaly', array( 'raise' => true, 'reason' => 'no_address', 'anomaly' => true ) === Guard::decide( 'shadow', 5, false, false ) );
m6_check( 'test orders are held back without being an anomaly', array( 'raise' => false, 'reason' => 'test_order', 'anomaly' => false ) === Guard::decide( 'on', 5, true, true ) );

// ---------- 关：和以前完全一样 ----------
$wpdb->visit( 1, 1, 3 );
Tracker::raise_stage( 1, 4, 77 );
m6_check( 'off: order placed raises to 4 without any address record', 4 === $stage( 1 ) && 77 === $order( 1 ) );
Tracker::raise_stage( 1, 5, 77 );
Tracker::raise_stage( 1, 2 );
m6_check( 'off: stage only goes up', 5 === $stage( 1 ) );
m6_check( 'off: exactly the legacy statements, no event lookup', 3 === count( $wpdb->queries ) && ! array_filter( $wpdb->queries, static fn( $sql ) => str_contains( $sql, 'checkout_address_completed' ) ) );
m6_check( 'off: nothing is counted or stored', array() === $GLOBALS['m6_options'] && array() === Guard::counters() );

// ---------- 开：跳步被拦住 ----------
Metrics_Switch::set( 'on' );
m6_order( 78, 'processing', 20.0, 150, 150, 2, 2 );
$wpdb->visit( 2, 2, 3 );
$wpdb->event( 2, 'checkout_shipping_selected' );
$wpdb->event( 2, 'checkout_payment_selected' ); // 结账页默认带出来的物流和支付，没有填地址。
$wpdb->event( 2, 'order_placed', 'server', 78 );
Tracker::raise_stage( 2, 4, 78 );
m6_check( 'on: order placed without an address record does not advance', 3 === $stage( 2 ) );
m6_check( 'on: the order is still linked to the visit', 78 === $order( 2 ) );
$wpdb->event( 2, 'payment_success', 'server', 78 );
Tracker::raise_stage( 2, 5, 78 );
m6_check( 'on: payment without an address record does not advance either', 3 === $stage( 2 ) );
m6_check( 'on: both skipped steps are counted as anomalies', 1 === Guard::counters()[ $today ]['no_address_4'] && 1 === Guard::counters()[ $today ]['no_address_5'] );

$wpdb->visit( 3, 3, 3 );
$wpdb->event( 3, 'checkout_address_completed' );
m6_order( 79, 'processing', 20.0, 150, 150, 3, 3 );
Tracker::raise_stage( 3, 4, 79 );
Tracker::raise_stage( 3, 5, 79 );
m6_check( 'on: with an address record the visit advances to paid', 5 === $stage( 3 ) && 79 === $order( 3 ) );
m6_check( 'on: a normal checkout adds no anomaly', 1 === Guard::counters()[ $today ]['no_address_4'] && 1 === Guard::counters()[ $today ]['no_address_5'] );

$wpdb->visit( 4, 4, 0 );
Tracker::raise_stage( 4, 1 );
Tracker::raise_stage( 4, 2 );
Tracker::raise_stage( 4, 3 );
m6_check( 'on: viewing, carting and reaching checkout are not gated', 3 === $stage( 4 ) );

// ---------- 开：测试单 ----------
$wpdb->visit( 5, 5, 3 );
$wpdb->event( 5, 'checkout_address_completed' );
m6_order( 90, 'processing', 1.0, 150, 150, 5, 5 )->ones = true;
Tracker::raise_stage( 5, 4, 90 );
Tracker::raise_stage( 5, 5, 90 );
m6_check( 'on: an all-ones test order stays at checkout', 3 === $stage( 5 ) && 90 === $order( 5 ) );
m6_check( 'on: test orders are counted separately, not as anomalies', 2 === Guard::counters()[ $today ]['test_order'] && 1 === Guard::counters()[ $today ]['no_address_4'] );

// ---------- 开：填地址事件晚到 ----------
Guard::register_hooks();
$wpdb->visit( 6, 6, 3 );
m6_order( 91, 'processing', 20.0, 150, 150, 6, 6 );
$wpdb->event( 6, 'order_placed', 'server', 91 );
Tracker::raise_stage( 6, 4, 91 );
$wpdb->event( 6, 'payment_success', 'server', 91 );
Tracker::raise_stage( 6, 5, 91 );
m6_check( 'late address: held back while the event is missing', 3 === $stage( 6 ) && 2 === Guard::counters()[ $today ]['no_address_4'] );
$wpdb->event( 6, 'checkout_address_completed' );
do_action( Guard::ACTION, 6, 6 );
m6_check( 'late address: advanced as soon as the event arrives', 5 === $stage( 6 ) && 91 === $order( 6 ) );
m6_check( 'late address: counted as settled once', 1 === Guard::counters()[ $today ]['settled'] );
$wpdb->event( 6, 'checkout_address_completed' );
do_action( Guard::ACTION, 6, 6 );
m6_check( 'late address: a second address event settles nothing again', 1 === Guard::counters()[ $today ]['settled'] );
$wpdb->event( 4, 'checkout_address_completed' );
do_action( Guard::ACTION, 4, 4 );
m6_check( 'an address event without an order changes nothing', 3 === $stage( 4 ) && 1 === Guard::counters()[ $today ]['settled'] );
$wpdb->visit( 7, 7, 3 );
m6_order( 92, 'processing', 1.0, 150, 150, 7, 7 )->ones = true;
$wpdb->event( 7, 'order_placed', 'server', 92 );
$wpdb->event( 7, 'checkout_address_completed' );
do_action( Guard::ACTION, 7, 7 );
m6_check( 'a late address never promotes a test order', 3 === $stage( 7 ) );

// ---------- 影子 ----------
Metrics_Switch::set( 'shadow' );
$wpdb->visit( 8, 8, 3 );
m6_order( 93, 'processing', 20.0, 150, 150, 8, 8 );
$wpdb->event( 8, 'order_placed', 'server', 93 );
Tracker::raise_stage( 8, 4, 93 );
m6_check( 'shadow: advances exactly like before', 4 === $stage( 8 ) && 93 === $order( 8 ) );
m6_check( 'shadow: records what would have been held back', 3 === Guard::counters()[ $today ]['no_address_4'] );
$wpdb->event( 8, 'checkout_address_completed' );
do_action( Guard::ACTION, 8, 8 );
m6_check( 'shadow: a late address is noted as settled', 4 === $stage( 8 ) && 2 === Guard::counters()[ $today ]['settled'] );

// ---------- 准确的异常数 ----------
$exact = Guard::anomalies( 100, 200 );
m6_check( 'exact anomalies: visits with an order event and never an address record', array( 2 ) === $exact['visit_ids'] && 1 === $exact['visits'] && 1 === $exact['placed'] && 1 === $exact['paid'] );
m6_check( 'exact anomalies ignore other periods', 0 === Guard::anomalies( 300, 400 )['visits'] && 0 === Guard::anomalies( 200, 100 )['visits'] );

// ---------- 规则出错时不丢数据 ----------
Metrics_Switch::set( 'on' );
$broken = new class() extends stdClass {
	public array $visits = array( 9 => array( 'stage' => 3, 'order_id' => 0 ) );
	public function prepare( string $sql, ...$args ): string { return vsprintf( str_replace( '%d', '%s', $sql ), $args ); }
	public function get_var( string $sql ) { throw new RuntimeException( 'database is away' ); }
	public function query( string $sql ) { if ( preg_match( '/GREATEST\(stage, (\d+)\)/', $sql, $m ) ) { $this->visits[9]['stage'] = max( $this->visits[9]['stage'], (int) $m[1] ); } return 1; }
};
$real = $wpdb;
$wpdb = $broken;
Tracker::raise_stage( 9, 4 );
m6_check( 'a failing guard lets the stage advance (tracking never loses data to the rule)', 4 === $broken->visits[9]['stage'] );
$wpdb = $real;

// ---------- 浏览器的结账事件一条都没到 ----------
$wpdb->visit( 10, 10, 1 ); // 只记到“看过商品”，没有进入结账的事件。
m6_order( 94, 'processing', 20.0, 150, 150, 10, 10 );
$wpdb->event( 10, 'order_placed', 'server', 94 );
Tracker::raise_stage( 10, 4, 94 );
m6_check( 'on: an order without any client checkout event still counts as reaching checkout, not beyond', 3 === $stage( 10 ) && 94 === $order( 10 ) );

// ---------- 一批里有两条填地址事件（顾客改来改去） ----------
$wpdb->visit( 11, 11, 3 );
m6_order( 95, 'processing', 20.0, 150, 150, 11, 11 );
$wpdb->event( 11, 'order_placed', 'server', 95 );
Tracker::raise_stage( 11, 4, 95 );
$wpdb->event( 11, 'payment_success', 'server', 95 );
Tracker::raise_stage( 11, 5, 95 );
$settled = Guard::counters()[ $today ]['settled'];
$wpdb->event( 11, 'checkout_address_completed' );
$wpdb->event( 11, 'checkout_address_completed' );
do_action( Guard::ACTION, 11, 11, 2 );
m6_check( 'late address: two address events in one batch still settle, counted once', 5 === $stage( 11 ) && $settled + 1 === Guard::counters()[ $today ]['settled'] );
// 两批同时到、谁都不是“第一批”时也要补上，只是不再多记一次“已补上”。
$wpdb->visit( 12, 12, 3 );
m6_order( 96, 'processing', 20.0, 150, 150, 12, 12 );
$wpdb->event( 12, 'payment_success', 'server', 96 );
Tracker::raise_stage( 12, 5, 96 );
$wpdb->event( 12, 'checkout_address_completed' );
$wpdb->event( 12, 'checkout_address_completed' );
do_action( Guard::ACTION, 12, 12, 1 );
m6_check( 'late address: a batch that is not the first still advances the visit', 5 === $stage( 12 ) && $settled + 1 === Guard::counters()[ $today ]['settled'] );

// ---------- 漏斗每一步不大于上一步 ----------
Metrics::flush();
$counts = array_values( Metrics::funnel( 100, 200 )['counts'] );
$sorted = $counts;
rsort( $sorted );
m6_check( 'funnel after the rule: no step exceeds the previous one', $counts === $sorted );
// 访客 1（关着时推进到 5，订单不存在）、2 和 10（跳步）、5（测试单）停在发起结账；4、7、8 只到填地址；3、6、11、12 付款成功。
m6_check( 'funnel after the rule: skipped visits stop at checkout, real ones are paid', array( 11, 11, 11, 11, 7, 4, 4, 4 ) === $counts );

echo 'PASS ', $GLOBALS['m6_passed'], " funnel guard (FE-05) assertions\n";
