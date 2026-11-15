<?php
/**
 * NF-02 对账用例（四）：转化报表从头跑到尾。纯逻辑：php -n m6-reconcile-conversion-pure.php
 *
 *   用例 9   转化详情（按设备）      Conversion::summary( $range, 'device' )            —— Operations + Visitor_Funnel + 分组卡片
 *   用例 10  转化详情（按国家）      Conversion::summary( $range, 'country' )           —— Dimension_Conversion
 *   用例 11  转化详情（只看正常访客）Conversion::summary( $range, 'device', 'normal' )  —— Classified_Conversion
 *
 * 用的是真正的报表类（和 data-classified-conversion-pure.php 同一套夹具），只有数据库和 WooCommerce 是假的。
 * 同一份数据跑三遍：关 = 旧数字；影子 = 页面和关着时一模一样、差异进日志；开 = 新数字，而且三张报表的漏斗和 Data\Metrics 一致。
 */
require __DIR__ . '/fixtures/visit-classification-boundaries.php';
require __DIR__ . '/fixtures/conversion-db-059.php';

use PeptideCore\Data\Conversion;
use PeptideCore\Data\Metrics;
use PeptideCore\Data\Metrics_Overlay as Overlay;
use PeptideCore\Data\Metrics_Reconcile as Reconcile;
use PeptideCore\Data\Metrics_Switch as Sw;

// 夹具里没有的 WordPress 函数（所以别的用例里开关一律是关着的）。在这里补上，开关才读得到。
$GLOBALS['m6_options'] = array();
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['m6_options'] ) ? $GLOBALS['m6_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['m6_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['m6_options'][ $name ] ); return true; }
function apply_filters( $tag, $value, ...$args ) { return $value; }
function do_action( $tag, ...$args ) {}
function wp_date( $format, $timestamp = null ) { return gmdate( $format, null === $timestamp ? time() : (int) $timestamp ); }
function wc_get_order( $id ) {
	foreach ( $GLOBALS['orders059'] as $order ) {
		if ( $order->id === (int) $id ) { return $order; }
	}
	return false;
}

/** 夹具的假订单 + 指标要用的三个方法。 */
final class M6_Order059 extends WC_Order {
	public array $ship_to = array();
	public function get_status() { return $this->status; }
	public function get_currency() { return 'USD'; }
	public function get_address( $type = 'billing' ) { return 'shipping' === $type ? $this->ship_to : array(); }
}

/** 夹具的假 $wpdb + Visitor_Funnel 后四层的事件查询 + Data\Metrics 的三条查询。 */
final class M6_ConversionDB extends ConversionDB059 {
	private function real( string $sql ): array {
		return array_filter( $this->cohort( $sql ), fn( array $s ): bool => 0 === (int) $s['is_test'] && isset( $this->visitors[ $s['visitor_id'] ] ) && 0 === (int) $this->visitors[ $s['visitor_id'] ]['is_test'] );
	}
	public function get_results( $sql, $format ) {
		$a = $this->args( $sql );
		if ( str_contains( $sql, 'SELECT e.id,e.type,e.source,e.visit_id,e.visitor_id,e.order_id,s.order_id AS linked_order' ) ) { // Visitor_Funnel。
			$cohort = str_contains( $sql, 'AND s.is_test = 0' ) ? $this->real( $sql ) : $this->cohort( $sql );
			$rows   = array();
			foreach ( $this->events as $e ) {
				$s    = $cohort[ $e['visit_id'] ] ?? null;
				$step = 'client' === $e['source'] ? in_array( $e['type'], array( 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected' ), true ) : 'payment_success' === $e['type'];
				if ( ! $s || ! $step || $e['id'] <= $a[0] || $s['visitor_id'] !== $e['visitor_id'] ) { continue; }
				$rows[] = array( 'id' => $e['id'], 'type' => $e['type'], 'source' => $e['source'], 'visit_id' => $e['visit_id'], 'visitor_id' => $e['visitor_id'], 'order_id' => $e['order_id'], 'linked_order' => $s['order_id'], 'visit_stage' => $s['stage'], 'g' => $s['device'], 'returning_visit' => 0 );
			}
			usort( $rows, static fn( $x, $y ) => $x['id'] <=> $y['id'] );
			return array_slice( $rows, 0, 500 );
		}
		if ( str_contains( $sql, 'SELECT s.id,s.visitor_id,s.stage FROM' ) ) { // Visitor_Funnel：按自定义分组（国家等）时逐条读访问。
			$rows = array();
			foreach ( str_contains( $sql, 'AND s.is_test = 0' ) ? $this->real( $sql ) : $this->cohort( $sql ) as $s ) {
				if ( $s['id'] > $a[0] ) { $rows[] = array( 'id' => $s['id'], 'visitor_id' => $s['visitor_id'], 'stage' => $s['stage'] ); }
			}
			usort( $rows, static fn( $x, $y ) => $x['id'] <=> $y['id'] );
			return array_slice( $rows, 0, 500 );
		}
		if ( str_contains( $sql, 'SELECT s.id, s.visitor_id, s.stage, s.order_id FROM' ) ) { // Metrics：时间段内的正式访问。
			$rows = array();
			foreach ( $this->real( $sql ) as $s ) {
				if ( $s['id'] > $a[2] ) { $rows[] = array( 'id' => $s['id'], 'visitor_id' => $s['visitor_id'], 'stage' => $s['stage'], 'order_id' => $s['order_id'] ); }
			}
			usort( $rows, static fn( $x, $y ) => $x['id'] <=> $y['id'] );
			return array_slice( $rows, 0, 1000 );
		}
		if ( str_contains( $sql, 'SELECT DISTINCT e.visit_id, e.type FROM' ) ) { // Metrics：填地址 / 选物流 / 选支付三种事件。
			$cohort = $this->cohort( $sql );
			$rows   = array();
			foreach ( $this->events as $e ) {
				if ( isset( $cohort[ $e['visit_id'] ] ) && $cohort[ $e['visit_id'] ]['stage'] >= 3 && in_array( $e['type'], array( 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected' ), true ) ) {
					$rows[ $e['visit_id'] . '|' . $e['type'] ] = array( 'visit_id' => $e['visit_id'], 'type' => $e['type'] );
				}
			}
			return array_values( $rows );
		}
		if ( str_contains( $sql, 'SELECT DISTINCT e.visit_id, e.order_id FROM' ) ) { // Metrics：付款事件里的订单号。
			$cohort = $this->cohort( $sql );
			$rows   = array();
			foreach ( $this->events as $e ) {
				if ( isset( $cohort[ $e['visit_id'] ] ) && $cohort[ $e['visit_id'] ]['stage'] >= 5 && 'server' === $e['source'] && 'payment_success' === $e['type'] && $e['order_id'] > 0 ) {
					$rows[ $e['visit_id'] . '|' . $e['order_id'] ] = array( 'visit_id' => $e['visit_id'], 'order_id' => $e['order_id'] );
				}
			}
			return array_values( $rows );
		}
		return parent::get_results( $sql, $format );
	}
}

$n     = 0;
$check = static function ( string $name, $ok ) use ( &$n ): void {
	if ( ! $ok ) { throw new RuntimeException( 'FAILED: ' . $name ); }
	++$n;
};

// ---------- 数据：“今天” = [100, 200) 秒，五个访客各一次访问，全部被判为正常访客 ----------
$wpdb = new M6_ConversionDB();
$GLOBALS['orders059'] = array();
$visit = static function ( int $id, string $device, int $stage, int $order ) use ( $wpdb ): void {
	$wpdb->visitors[ $id ] = array( 'id' => $id, 'is_test' => 0, 'browser' => 'Browser', 'first_ms' => 1000 );
	$wpdb->visits[ $id ]   = array( 'id' => $id, 'visitor_id' => $id, 'is_test' => 0, 'start_ms' => 110000, 'last_ms' => 170000, 'stage' => $stage, 'device' => $device, 'order_id' => $order, 'attribution_version' => 1, 'attribution_ms' => 120000, 'attribution_host' => 'shop.test', 'utm_source' => 'fb', 'utm_medium' => null );
	$wpdb->events[]        = row059( $id, $id, $id, 1, 'normal' );
};
$next  = 100;
$event = static function ( int $visit_id, string $type, string $source = 'client', int $order = 0 ) use ( $wpdb, &$next ): void {
	$wpdb->events[] = array( 'id' => ++$next, 'event_uid' => 'observed-' . $next, 'visit_id' => $visit_id, 'visitor_id' => $visit_id, 'type' => $type, 'source' => $source, 'occurred_ms' => 150000, 'received_ms' => 150000, 'order_id' => $order, 'is_test' => 0, 'data' => json_encode( array( 'method' => 'card', 'origin' => 'user' ) ) );
};
$order = static function ( int $id, int $visit_id, float $total, float $wc_refund = 0.0 ): M6_Order059 {
	$o         = new M6_Order059( $id, $visit_id, $visit_id );
	$o->total  = $total;
	$o->refund = $wc_refund;
	$GLOBALS['orders059'][] = $o;
	return $o;
};
// 访客 1（电脑）：填地址、选物流、选支付、付款。订单 30 元，WooCommerce 里记了 7 元退款（总后台没有标记退款）。
$visit( 1, 'desktop', 5, 10 );
foreach ( array( 'checkout_view', 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected' ) as $type ) { $event( 1, $type ); }
$event( 1, 'payment_success', 'server', 10 );
$order( 10, 1, 30.0, 7.0 );
// 访客 2（电脑）：没有填地址的记录就付了款 —— FE-05 的跳步。
$visit( 2, 'desktop', 5, 20 );
$event( 2, 'payment_success', 'server', 20 );
$order( 20, 2, 50.0 );
// 访客 3（手机）：填了地址直接付款。
$visit( 3, 'mobile', 5, 30 );
$event( 3, 'checkout_address_completed' );
$event( 3, 'payment_success', 'server', 30 );
$order( 30, 3, 20.0 );
// 访客 4（手机）：只有结账页默认带出来的物流和支付，没填地址，没下单。
$visit( 4, 'mobile', 3, 0 );
$event( 4, 'checkout_shipping_selected' );
$event( 4, 'checkout_payment_selected' );
// 访客 5（手机）：收件信息全 1 的测试单，走完全程。
$visit( 5, 'mobile', 5, 40 );
foreach ( array( 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected' ) as $type ) { $event( 5, $type ); }
$event( 5, 'payment_success', 'server', 40 );
$order( 40, 5, 1.0 )->ship_to = array( 'first_name' => '1', 'last_name' => '1', 'address_1' => '1', 'city' => '1' );

$range   = array( 'from' => 100, 'to' => 200, 'compare_from' => 0, 'compare_to' => 100, 'dates' => array( 'from' => '1970-01-01', 'to' => '1970-01-02', 'compare_from' => '1969-12-31', 'compare_to' => '1970-01-01' ) );
$funnel  = static fn( array $summary ): array => array_values( array_column( $summary['visitor_funnel']['current'], 'count', 'key' ) );
$rows    = static fn( array $summary ): array => array_column( $summary['rows'], null, 'key' );
$reports = static function () use ( $range ): array {
	Metrics::flush();
	Overlay::flush();
	return array(
		'device'  => Conversion::summary( $range, 'device' ),
		'country' => Conversion::summary( $range, 'country' ),
		'normal'  => Conversion::summary( $range, 'device', 'normal' ),
	);
};

// ---------- 关 ----------
$check( 'the switch is off by default', 'off' === Sw::mode() );
$off = $reports();
$check( 'off: nothing logged, no option written', array() === Sw::discard_pending() && array() === $GLOBALS['m6_options'] );
$check( 'case 9 off: 4 orders ÷ 5 visitors, the all-1 test order counted as real', 80.0 === $off['device']['overall']['current']['conversion'] && 4 === $off['device']['overall']['current']['paid'] && 5 === $off['device']['overall']['current']['visitors'] );
$check( 'case 9 off: the legacy funnel skips a step (4 paid > 2 chose payment)', array( 5, 5, 5, 5, 3, 2, 2, 4 ) === $funnel( $off['device'] ) );
$check( 'case 9 off: group cards are orders ÷ visitors, sales minus WooCommerce refunds', 100.0 === $rows( $off['device'] )['desktop']['conversion'] && 73.0 === $rows( $off['device'] )['desktop']['sales'] && 66.67 === $rows( $off['device'] )['mobile']['conversion'] && 21.0 === $rows( $off['device'] )['mobile']['sales'] );
// 这份数据没有国家信息，五次访问都落在“没有观察到”这一组里：一行，正好能和总数对上。
$check( 'case 10 off: same totals by country', 1 === count( $off['country']['rows'] ) && 5 === $off['country']['rows'][0]['visitors'] && 4 === $off['country']['rows'][0]['paid'] && 94.0 === $off['country']['rows'][0]['sales'] && 80.0 === $off['country']['rows'][0]['conversion'] && $funnel( $off['device'] ) === $funnel( $off['country'] ) );
$check( 'case 11 off: normal visitors, legacy rule (defaults count as steps)', 80.0 === $off['normal']['overall']['current']['conversion'] && 4 === $off['normal']['overall']['current']['paid'] && array( 5, 5, 5, 5, 3, 3, 3, 4 ) === $funnel( $off['normal'] ) );

// ---------- 影子：页面和关着时一模一样，差异进日志 ----------
Sw::set( 'shadow' );
$shadow = $reports();
$check( 'shadow: all three reports are byte-for-byte what they were with the switch off', json_encode( $off ) === json_encode( $shadow ) );
$logged = array();
foreach ( Sw::discard_pending() as $item ) {
	if ( $item['diffs'] ) { $logged[ $item['metric'] ][] = $item; }
}
$check( 'shadow: the test-order rule difference is logged for order 40', isset( $logged['reports.test_order'] ) && array( 40 ) === array_values( array_unique( array_map( static fn( $i ) => $i['context']['order'], $logged['reports.test_order'] ) ) ) );
$check( 'shadow: the funnel rule difference is logged', isset( $logged['reports.visitor_funnel'] ) && in_array( 'paid', array_column( $logged['reports.visitor_funnel'][0]['diffs'], 'path' ), true ) );
$check( 'shadow: the KPI difference is logged', isset( $logged['operations.kpis'] ) && array( 'orders', 'sales_cents', 'conversion' ) === array_column( $logged['operations.kpis'][0]['diffs'], 'path' ) );
// 这份数据里每个付款的人都只有一单，所以分组卡片的“订单数 ÷ 访客”和“付款访客 ÷ 访客”是同一个数，不记差异；
// 规则带来的差异记在上面两条（reports.visitor_funnel、operations.kpis）里。一人多单的情况见 m6-reconcile-reports-pure.php 用例 7。
$check( 'shadow: one order per buyer, so the group cards log no conversion difference', ! isset( $logged['reports.conversion'] ) );
$check( 'shadow: same orders in the range under both rules, so no order-list difference', ! isset( $logged['reports.paid_orders'] ) );
$why = Reconcile::orders( 100, 200 );
$check( 'shadow: every order difference has a reason', $why['explained'] && array( 'refund_basis', 'test_order_rule' ) === array_keys( $why['reasons'] ) && array( 10 ) === $why['reasons']['refund_basis']['ids'] && array( 40 ) === $why['reasons']['test_order_rule']['ids'] );
$check( 'shadow: totals of the two rules', 4 === $why['legacy']['orders'] && 9400 === $why['legacy']['sales_cents'] && 3 === $why['metrics']['orders'] && 10000 === $why['metrics']['sales_cents'] );

// ---------- 开 ----------
Sw::set( 'on' );
$on     = $reports();
$metric = Metrics::funnel( 100, 200 )['counts'];
$check( 'on: Data\Metrics on this data', array( 5, 5, 5, 5, 2, 2, 2, 2 ) === array_values( $metric ) && 3 === Metrics::orders( 100, 200 )['orders'] && 10000 === Metrics::orders( 100, 200 )['sales_cents'] && 40.0 === Metrics::conversion_rate( 100, 200 ) );
$check( 'case 9 on: overall = Data\Metrics (3 orders, 2 paying visitors of 5)', 40.0 === $on['device']['overall']['current']['conversion'] && 3 === $on['device']['overall']['current']['paid'] && 5 === $on['device']['overall']['current']['visitors'] );
$check( 'case 9 on: the funnel is the Data\Metrics funnel, no skipped step', array_values( $metric ) === $funnel( $on['device'] ) );
$check( 'case 9 on: group cards — paying visitors ÷ visitors, test order gone, sales minus HQ refunds only', 50.0 === $rows( $on['device'] )['desktop']['conversion'] && 2 === $rows( $on['device'] )['desktop']['paid'] && 80.0 === $rows( $on['device'] )['desktop']['sales'] && 33.33 === $rows( $on['device'] )['mobile']['conversion'] && 1 === $rows( $on['device'] )['mobile']['paid'] && 20.0 === $rows( $on['device'] )['mobile']['sales'] );
$check( 'case 9 on: group cards add up to the totals', 3 === array_sum( array_column( $on['device']['rows'], 'paid' ) ) && 100.0 === array_sum( array_column( $on['device']['rows'], 'sales' ) ) );
$check( 'case 9 on: each group funnel has no skipped step either', array( 2, 2, 2, 2, 1, 1, 1, 1 ) === array_column( $rows( $on['device'] )['desktop']['visitor_funnel']['layers'], 'count' ) && array( 3, 3, 3, 3, 1, 1, 1, 1 ) === array_column( $rows( $on['device'] )['mobile']['visitor_funnel']['layers'], 'count' ) );
$check( 'case 10 on: by country — same orders, same sales, same funnel', 3 === array_sum( array_column( $on['country']['rows'], 'paid' ) ) && 100.0 === array_sum( array_column( $on['country']['rows'], 'sales' ) ) && array_values( $metric ) === $funnel( $on['country'] ) && $on['device']['overall'] === $on['country']['overall'] );
$check( 'case 10 on: the row is the whole store — 2 paying visitors of 5, its funnel is the Data\Metrics funnel', 1 === count( $on['country']['rows'] ) && 40.0 === $on['country']['rows'][0]['conversion'] && 5 === $on['country']['rows'][0]['visitors'] && array_values( $metric ) === array_column( $on['country']['rows'][0]['visitor_funnel']['layers'], 'count' ) );
$check( 'case 11 on: normal visitors — same rule as everywhere else', 40.0 === $on['normal']['overall']['current']['conversion'] && 3 === $on['normal']['overall']['current']['paid'] && array_values( $metric ) === $funnel( $on['normal'] ) );
$check( 'case 11 on: its group cards match the unfiltered report (every visitor here is normal)', array_column( $on['normal']['rows'], 'conversion', 'key' ) === array_column( $on['device']['rows'], 'conversion', 'key' ) && array_column( $on['normal']['rows'], 'sales', 'key' ) === array_column( $on['device']['rows'], 'sales', 'key' ) );

// ---------- 换回关：和最开始一模一样 ----------
Sw::set( 'off' );
Sw::discard_pending();
$check( 'back to off: the three reports are exactly the legacy ones again', json_encode( $off ) === json_encode( $reports() ) && array() === Sw::discard_pending() );

echo 'PASS ', $n, " conversion report reconciliation assertions (device / country / classified)\n";
