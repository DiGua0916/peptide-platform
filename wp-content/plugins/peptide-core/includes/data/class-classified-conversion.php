<?php
/** Explicit source-label subset; legacy all reports and their SQL remain separate. */
namespace PeptideCore\Data;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;
use PeptideCore\Tracking\Visit_Classifications as Classifications;
use PeptideCore\Overview;
defined( 'ABSPATH' ) || exit;
final class Classified_Conversion {
 private const CLIENT = array( 'checkout_view', 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected', 'checkout_submit_attempt' );
 private const MIDDLE = array( 'checkout_address_completed' => 'address', 'checkout_shipping_selected' => 'shipping', 'checkout_payment_selected' => 'payment' );
 private const CHANNEL = array( 'initial', 'user', 'automatic', 'attempts', 'attempt_visits', 'attempt_orders', 'succeeded', 'failed' );
 public static function summary( array $range, string $group, string $status ): array {
  if ( ! in_array( $group, Conversion::GROUPS, true ) || ! in_array( $status, Classifications::STATUSES, true ) ) { throw new \InvalidArgumentException( 'Invalid classified conversion subset.' ); }
  $current = self::period( $range['from'], $range['to'], $group, $status ); $previous = self::period( $range['compare_from'], $range['compare_to'], $group, $status );
  $change = array(); foreach ( Conversion::OVERALL as $key ) { $c = $current['overall'][$key]; $p = $previous['overall'][$key]; $change[$key] = null === $c || null === $p ? null : ( $key === 'conversion' ? round( $c - $p, 2 ) : Range::change( (float) $c, (float) $p ) ); }
  $out = array( 'range' => $range, 'tracking' => true, 'group' => $group, 'groups' => Conversion::GROUPS, 'overall' => array( 'current' => $current['overall'], 'previous' => $previous['overall'], 'change' => $change ), 'rows' => $current['rows'], 'visitor_funnel' => Visitor_Funnel::compare( $current['funnel'], $previous['funnel'] ), 'checkout_steps' => self::checkout_compare( $current['checkout'], $previous['checkout'] ), 'visitor_status' => $status, 'classification_coverage' => array( 'current' => $current['coverage'], 'previous' => $previous['coverage'] ), 'classification_cutoffs' => array( 'current' => $range['to'] * 1000, 'previous' => $range['compare_to'] * 1000 ), 'overall_scope' => 'classified_visit_start_cohort', 'checkout_steps_scope' => 'classified_visit_start_cohort' );
  if ( isset( $current['dimension_coverage'] ) ) { $out['dimension_coverage'] = $current['dimension_coverage']; $out['dimension_scope'] = 'classified_visit_start_cohort_saved_facts_before_period_end'; }
  return $out;
 }
 private static function checked_rows( string $sql ): array { global $wpdb; $rows = $wpdb->get_results( $sql, ARRAY_A ); if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new \RuntimeException( 'Classified conversion unavailable.' ); } return $rows; }
 private static function coverage(): array { return array( 'unit' => 'visits', 'base_count' => 0, 'normal' => 0, 'abnormal' => 0, 'unclassified' => 0, 'conflict' => 0, 'observed' => 0, 'coverage' => 'partial', 'full_coverage' => false ); }
 private static function zero_sets(): array { return array_fill_keys( Visitor_Funnel::KEYS, array() ); }
 private static function counts( array $sets ): array { return array_map( 'count', $sets ); }
 private static function method( $value ): string { return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9_:-]{1,80}\z/', $value ) ? $value : 'unknown'; }
 private static function empty_channel(): array { return array_fill_keys( self::CHANNEL, 0 ) + array( 'success_rate' => null ); }
 private static function load_orders( array $need, array &$orders ): void {
  if ( ! $need ) { return; } foreach ( $need as $id ) { $orders[$id] = null; }
  foreach ( wc_get_orders( array( 'post__in' => array_values( $need ), 'limit' => count( $need ), 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $order ) {
   $id = $order->get_id(); if ( ! isset( $need[$id] ) ) { continue; }
   $was_paid = $order->is_paid() || null !== $order->get_date_paid();
   $orders[$id] = array( 'test' => self::is_test( $order ), 'paid' => $was_paid, 'counted' => class_exists( Metrics_Switch::class ) && Metrics_Switch::on() ? Metrics::is_paid_order( $order ) : $was_paid, 'failed' => $order->has_status( 'failed' ), 'method' => self::method( $order->get_payment_method() ), 'visitor' => (int) $order->get_meta( Server_Events::ORDER_VISITOR_META ), 'visit' => (int) $order->get_meta( Server_Events::ORDER_VISIT_META ) );
  }
 }
 /** Classification is as-of receipt cutoff; visit stage and checkout remain accumulated historical progress. */
 public static function period( int $from, int $to, string $group, string $status ): array {
  if ( ! Schema::installed() ) { throw new \RuntimeException( 'Classification storage unavailable.' ); }
  global $wpdb; $s = Schema::table( 'visits' ); $v = Schema::table( 'visitors' ); $e = Schema::table( 'events' ); $cursor = 0; $cohort = array();
  do {
   $rows = self::checked_rows( $wpdb->prepare( "SELECT s.*,v.browser AS owner_browser,v.id AS matched_visitor_id,v.is_test AS visitor_test FROM {$s} s INNER JOIN {$v} v ON v.id=s.visitor_id WHERE s.start_ms>=%d AND s.start_ms<%d AND s.is_test=0 AND v.is_test=0 AND s.id>%d ORDER BY s.id ASC LIMIT 500", $from * 1000, $to * 1000, $cursor ) );
   foreach ( $rows as $row ) { $cursor = (int) $row['id']; $visitor = (int) $row['visitor_id']; if ( $cursor <= 0 || $visitor <= 0 || (int) $row['matched_visitor_id'] !== $visitor || (int) $row['is_test'] || (int) $row['visitor_test'] || (int) $row['start_ms'] < $from * 1000 || (int) $row['start_ms'] >= $to * 1000 ) { continue; } $cohort[$cursor] = $row; }
  } while ( count( $rows ) === 500 );
  $owners = array(); foreach ( $cohort as $id => $row ) { $owners[$id] = (int) $row['visitor_id']; }
  $verdicts = Classifications::for_visits( $owners, $to * 1000 ); $coverage = self::coverage(); $selected = array();
  foreach ( $cohort as $id => $row ) { $verdict = $verdicts[$id]; ++$coverage['base_count']; ++$coverage[$verdict['status']]; if ( $verdict['observed'] ) { ++$coverage['observed']; } if ( $verdict['conflict'] ) { ++$coverage['conflict']; } if ( $verdict['status'] === $status ) { $selected[$id] = $row; } }
  $dimension = Dimension_Reports::GROUPS[$group] ?? null; $mapping = null;
  if ( $dimension !== null ) { $facts = Report_Dimensions::visit_facts( array_keys( $selected ), $to * 1000 ); $safe = array(); foreach ( $selected as $id => $row ) { $safe[$id] = Report_Dimensions::for_visit( $facts, $id, (int) $row['visitor_id'] ); } $mapping = Dimension_Reports::mapping( $safe, $dimension ); }
  $sets = self::zero_sets(); $groups = array(); $keys = array();
  foreach ( $selected as $id => $row ) {
   if ( $dimension !== null ) { $key = $mapping['map'][$id]['key']; }
   else { $raw = $group === 'browser' ? (string) $row['owner_browser'] : (string) $row['device']; $key = $raw === '' ? 'unknown' : $raw; }
   $keys[$id] = $key; if ( ! isset( $groups[$key] ) ) { $groups[$key] = array( 'sets' => self::zero_sets(), 'paid' => 0, 'sales' => 0.0 ); }
   foreach ( array( 'visitors' => 0, 'viewed' => 1, 'carted' => 2, 'checkout' => 3 ) as $step => $stage ) { if ( (int) $row['stage'] >= $stage ) { $sets[$step][(int) $row['visitor_id']] = true; $groups[$key]['sets'][$step][(int) $row['visitor_id']] = true; } }
  }
  $checkout_seen = array_fill_keys( array_keys( Checkout_Steps::EVENTS ), array() ); $channels = array(); $orders = array();
  // M6（FE-05）：开关开着时，后四层先按访问记下来，整段事件读完再按 Metrics::tail_steps() 结算（没填地址的付款不算，付了款的四层都算）。关着和影子时和以前一样逐条累加。
  $strict = class_exists( Metrics_Switch::class ) && Metrics_Switch::on(); $visit_steps = array();
  foreach ( array_chunk( array_keys( $selected ), 500 ) as $ids ) {
   $in = implode( ',', $ids ); $cursor = 0;
   do {
    $rows = self::checked_rows( $wpdb->prepare( "SELECT e.*,s.order_id AS linked_order,s.visitor_id AS owner,s.is_test AS visit_test,v.is_test AS visitor_test FROM {$e} e INNER JOIN {$s} s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id INNER JOIN {$v} v ON v.id=e.visitor_id WHERE e.visit_id IN ({$in}) AND e.id>%d AND e.is_test=0 AND s.is_test=0 AND v.is_test=0 AND ((e.source='client' AND e.type IN ('checkout_view','checkout_address_completed','checkout_shipping_selected','checkout_payment_selected','checkout_submit_attempt')) OR (e.source='server' AND e.type IN ('payment_attempt','payment_success','payment_failed'))) ORDER BY e.id ASC LIMIT 500", $cursor ) );
    $need = array(); foreach ( $rows as $row ) { $id = (int) ( $row['order_id'] ?: ( $row['linked_order'] ?? 0 ) ); if ( $id > 0 && ! array_key_exists( $id, $orders ) ) { $need[$id] = $id; } } self::load_orders( $need, $orders );
    foreach ( $rows as $row ) {
     $cursor = (int) $row['id']; $visit = (int) $row['visit_id']; $visitor = (int) $row['visitor_id']; $source = $row['source']; $type = $row['type'];
     if ( ! isset( $selected[$visit] ) || $visitor !== (int) $selected[$visit]['visitor_id'] || $visitor !== (int) $row['owner'] || (int) $row['is_test'] || (int) $row['visit_test'] || (int) $row['visitor_test'] || ( $source === 'client' ? ! in_array( $type, self::CLIENT, true ) : $source !== 'server' || ! in_array( $type, array( 'payment_attempt', 'payment_success', 'payment_failed' ), true ) ) ) { continue; }
     $id = (int) ( $row['order_id'] ?: ( $row['linked_order'] ?? 0 ) ); $order = $orders[$id] ?? null;
     if ( ( $id && ( ! $order || $order['test'] || $order['visit'] !== $visit || $order['visitor'] !== $visitor ) ) || ( $source === 'server' && (int) $row['order_id'] <= 0 ) || ( $type === 'payment_success' && ( ! $order || ! $order['paid'] ) ) ) { continue; }
     $step = self::MIDDLE[$type] ?? ( $type === 'payment_success' ? 'paid' : null ); if ( $step !== null ) { if ( $strict ) { if ( $step !== 'paid' || $order['counted'] ) { $visit_steps[$visit][$step] = true; } } else { $sets[$step][$visitor] = true; $groups[$keys[$visit]]['sets'][$step][$visitor] = true; } }
     if ( isset( Checkout_Steps::EVENTS[$type] ) ) { $checkout_seen[$type][$visit] = true; }
     if ( ! in_array( $type, array( 'checkout_payment_selected', 'payment_attempt', 'payment_success', 'payment_failed' ), true ) ) { continue; }
     $data = json_decode( (string) $row['data'], true ); $method = self::method( is_array( $data ) ? ( $data['method'] ?? '' ) : '' );
     if ( ! isset( $channels[$method] ) ) { $channels[$method] = array( 'initial' => array(), 'user' => array(), 'automatic' => array(), 'attempts' => 0, 'attempt_visits' => array(), 'attempt_orders' => array(), 'paid' => array() ); }
     if ( $type === 'checkout_payment_selected' ) { $origin = $data['origin'] ?? ''; if ( in_array( $origin, array( 'initial', 'user', 'automatic' ), true ) ) { $channels[$method][$origin][$visit] = true; } }
     elseif ( $type === 'payment_attempt' ) { ++$channels[$method]['attempts']; $channels[$method]['attempt_visits'][$visit] = true; $channels[$method]['attempt_orders'][$id] = true; }
     elseif ( $type === 'payment_success' ) { $channels[$method]['paid'][$id] = true; }
    }
   } while ( count( $rows ) === 500 );
  }
  foreach ( $visit_steps as $visit => $steps ) { $visitor = (int) $selected[$visit]['visitor_id']; $reached = (int) $selected[$visit]['stage']; if ( $reached < 3 ) { continue; } foreach ( Metrics::tail_steps( $steps, ! empty( $steps['paid'] ) && $reached >= 5 ) as $step ) { $sets[$step][$visitor] = true; $groups[$keys[$visit]]['sets'][$step][$visitor] = true; } }
  $paid = 0;
  foreach ( Operations::paid_orders( $from, $to ) as $order ) {
   if ( self::is_test( $order ) ) { continue; } $at = self::order_ts( $order ) ?? -1;
   $visit = (int) $order->get_meta( Server_Events::ORDER_VISIT_META ); $visitor = (int) $order->get_meta( Server_Events::ORDER_VISITOR_META );
   if ( $at < $from || $at >= $to || ! isset( $selected[$visit] ) || $visitor !== (int) $selected[$visit]['visitor_id'] ) { continue; }
   ++$paid; ++$groups[$keys[$visit]]['paid']; $groups[$keys[$visit]]['sales'] += self::sales( $order );
  }
  $counts = self::counts( $sets ); $rows = array();
  foreach ( $groups as $key => $g ) {
   $n = self::counts( $g['sets'] ); $row = array( 'key' => (string) $key, 'conversion' => self::conversion( (int) $g['paid'], (int) $n['paid'], (int) $n['visitors'], 'conversion.classified.' . $group ), 'sales' => round( $g['sales'], 2 ), 'visitors' => $n['visitors'], 'paid' => $g['paid'], 'funnel' => Operations::layers( array( 'visitors' => $n['visitors'], 'viewed' => $n['viewed'], 'carted' => $n['carted'], 'checkout' => $n['checkout'], 'paid' => $g['paid'] ) ), 'visitor_funnel' => array( 'unit' => 'visitors', 'layers' => Visitor_Funnel::layers( $n ) ) );
   if ( $dimension !== null ) { $row['dimension'] = $mapping['typed'][$key]; $row['label'] = Dimension_Reports::label( $row['dimension'], $dimension ); } $rows[] = $row;
  }
  usort( $rows, static fn( $a, $b ) => ( $b['visitors'] <=> $a['visitors'] ) ?: ( $b['paid'] <=> $a['paid'] ) ?: strcmp( $a['key'], $b['key'] ) );
  $out_channels = array(); foreach ( $channels as $method => $channel ) { $m = self::empty_channel(); foreach ( array( 'initial', 'user', 'automatic', 'attempt_visits', 'attempt_orders' ) as $key ) { $m[$key] = count( $channel[$key] ); } $m['attempts'] = $channel['attempts']; foreach ( $channel['attempt_orders'] as $id => $unused ) { if ( $orders[$id]['method'] !== $method ) { continue; } if ( isset( $channel['paid'][$id] ) && $orders[$id]['paid'] ) { ++$m['succeeded']; } if ( $orders[$id]['failed'] ) { ++$m['failed']; } } $m['success_rate'] = $m['attempt_orders'] ? round( 100 * $m['succeeded'] / $m['attempt_orders'], 2 ) : null; $out_channels[$method] = $m; }
  $out = array( 'coverage' => $coverage, 'overall' => array( 'conversion' => self::conversion( (int) $paid, (int) $counts['paid'], (int) $counts['visitors'], 'conversion.classified.overall' ), 'paid' => $paid, 'visitors' => $counts['visitors'], 'viewed' => $counts['viewed'], 'carted' => $counts['carted'], 'checkout' => $counts['checkout'] ), 'rows' => $rows, 'funnel' => array( 'unit' => 'visitors', 'layers' => Visitor_Funnel::layers( $counts ) ), 'checkout' => array( 'counts' => array_map( 'count', $checkout_seen ), 'channels' => $out_channels ) );
  if ( $mapping !== null ) { $out['dimension_coverage'] = $mapping['coverage']; } return $out;
 }
 private static function checkout_compare( array $current, array $previous ): array {
  $change = array(); foreach ( Checkout_Steps::EVENTS as $type => $source ) { $change[$type] = Range::change( $current['counts'][$type], $previous['counts'][$type] ); }
  $methods = array_unique( array_merge( array_keys( $current['channels'] ), array_keys( $previous['channels'] ) ) ); sort( $methods, SORT_STRING ); $channels = array();
  foreach ( $methods as $method ) { $c = $current['channels'][$method] ?? self::empty_channel(); $p = $previous['channels'][$method] ?? self::empty_channel(); $delta = array(); foreach ( self::CHANNEL as $key ) { $delta[$key] = Range::change( $c[$key], $p[$key] ); } $delta['success_rate'] = null === $c['success_rate'] || null === $p['success_rate'] ? null : round( $c['success_rate'] - $p['success_rate'], 2 ); $channels[] = array( 'method' => $method, 'current' => $c, 'previous' => $p, 'change' => $delta ); }
  return array( 'labels' => Checkout_Steps::labels(), 'sources' => Checkout_Steps::EVENTS, 'current' => $current['counts'], 'previous' => $previous['counts'], 'change' => $change, 'channels' => $channels, 'local_simulation' => \PeptideCore\Payments\Local_Payments::allowed() );
 }

	/** M6（NF-02）：测试单规则按开关走 Metrics_Overlay::is_test_order()（关着时就是 Overview 的旧规则）；不带自动加载的纯逻辑测试里没有这个类，直接用旧规则。 */
	private static function is_test( $order ): bool {
		return class_exists( Metrics_Overlay::class ) ? Metrics_Overlay::is_test_order( $order ) : \PeptideCore\Overview::is_test_order( $order );
	}

	/** M6（NF-02）：一张订单的销售额按开关走 Metrics_Overlay::order_sales()（关着时 = 订单金额 − WooCommerce 退款，和以前一样）。 */
	private static function sales( $order ): float {
		return class_exists( Metrics_Overlay::class ) ? Metrics_Overlay::order_sales( $order ) : (float) $order->get_total() - (float) $order->get_total_refunded();
	}

	/** M6（NF-02）：一张订单算在哪个时刻（Unix 秒）按开关走 Metrics_Overlay::order_time()（关着时 = 创建时间，和以前一样）；没有时返回 null。 */
	private static function order_ts( $order ): ?int {
		if ( class_exists( Metrics_Overlay::class ) ) {
			return Metrics_Overlay::order_time( $order );
		}
		$created = $order->get_date_created();
		return $created ? (int) $created->getTimestamp() : null;
	}
	/** M6（NF-02）：分组转化率按开关走 Metrics_Overlay::conversion()（关着时 = 订单数 ÷ 访客，和以前一样；开了 = 付了款的访客 ÷ 访客）。 */
	private static function conversion( int $orders, int $paid_visitors, int $visitors, string $report ): ?float {
		if ( class_exists( Metrics_Overlay::class ) ) {
			return Metrics_Overlay::conversion( $orders, $paid_visitors, $visitors, $report );
		}
		return $visitors ? round( $orders / $visitors * 100, 2 ) : null;
	}
}
