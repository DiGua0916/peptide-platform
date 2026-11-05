<?php
/** Bounded, observed behaviour facts for operators and a future AI connector. */
namespace PeptideCore\Tracking;
defined( 'ABSPATH' ) || exit;

final class Preferences {
 public const LIMIT = 20000;
 public static function register_hooks(): void {
  add_action( 'admin_init', static function (): void { if ( function_exists( 'wp_add_privacy_policy_content' ) ) { wp_add_privacy_policy_content( 'Store behaviour analytics', '<p>When visit analytics is enabled, we record clicks on identified storefront modules and products, and estimate active page viewing from visible, active presence signals. We do not collect form values or click text. These observations follow the existing opt-out, retention, export and erasure rules. Browsing does not establish a person’s intent or sensitive attributes.</p>' ); } } );
  add_action( 'rest_api_init', static function (): void {
   register_rest_route( 'peptide/v1', '/track/admin/preferences', array(
    'methods' => 'GET', 'permission_callback' => array( self::class, 'permission' ),
    'callback' => array( self::class, 'response' ),
   ) );
  } );
 }
 public static function permission() {
  if ( ! current_user_can( 'manage_woocommerce' ) || ( \PeptideCore\Staff::is_staff( get_current_user_id() ) && ! in_array( 'data', \PeptideCore\Staff::granted( get_current_user_id() ), true ) ) ) {
   return new \WP_Error( 'pc_preferences_forbidden', '此账号没有客户数据权限。', array( 'status' => rest_authorization_required_code() ) );
  }
  return true;
 }
 public static function response( \WP_REST_Request $request ) {
  $period = $request['period'] ?? 'day'; $date = $request['date'] ?? wp_date( 'Y-m-d' ); $visitor = $request['visitor_id'] ?? '0';
  if ( ! in_array( $period, array( 'day', 'week', 'month' ), true ) || ! is_string( $date ) || ! preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $date ) || ! is_scalar( $visitor ) || ! preg_match( '/\A(?:0|[1-9][0-9]{0,9})\z/', (string) $visitor ) ) {
   return new \WP_Error( 'pc_invalid_param', '请选择有效的日期和统计周期。', array( 'status' => 400 ) );
  }
  $day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
  if ( ! $day || $day->format( 'Y-m-d' ) !== $date || $date > wp_date( 'Y-m-d' ) ) { return new \WP_Error( 'pc_invalid_param', '日期不能无效或晚于今天。', array( 'status' => 400 ) ); }
  $start = $period === 'week' ? $day->modify( 'monday this week' ) : ( $period === 'month' ? $day->modify( 'first day of this month' ) : $day );
  $end = $start->modify( $period === 'week' ? '+1 week' : ( $period === 'month' ? '+1 month' : '+1 day' ) );
  $from = $start->getTimestamp() * 1000; $to = min( $end->getTimestamp() * 1000, Tracker::now_ms() );
  $ready = Schema::installed();
  global $wpdb;
  $table = Schema::table( 'events' );
  $where = $wpdb->prepare( 'occurred_ms >= %d AND occurred_ms < %d AND is_test = 0', $from, $to );
  if ( (int) $visitor ) { $where .= $wpdb->prepare( ' AND visitor_id = %d', (int) $visitor ); }
  $where .= ' AND visitor_id > 0 AND visit_id > 0';
  $types = "'page_view','heartbeat','visibility_change','page_exit','product_view','product_intent','module_click','product_click','add_to_cart'";
  $wpdb->last_error = '';
  $rows = array();
  if ( $ready ) {
  $rows = $wpdb->get_results( "SELECT id, visitor_id, visit_id, tab_id, page_instance_id, seq, type, source, occurred_ms, page_type, path, product_id, data FROM {$table} WHERE {$where} AND type IN ({$types}) ORDER BY occurred_ms, id LIMIT " . ( self::LIMIT + 1 ), ARRAY_A );
  if ( $wpdb->last_error ) { return new \WP_Error( 'pc_preferences_unavailable', '暂时无法读取偏好数据，请重试。', array( 'status' => 503 ) ); }
  }
  $partial = count( $rows ) > self::LIMIT; if ( $partial ) { array_pop( $rows ); }
  $data = self::summarize( $rows, $from, $to );
  $data['schema_version'] = 1;
  if ( ! $ready ) { $data['summary'] = array_fill_keys( array_keys( $data['summary'] ), null ); }
  $data['scope'] = (int) $visitor ? 'visitor' : 'site';
  $data['subject'] = (int) $visitor ? substr( hash_hmac( 'sha256', 'visitor:' . $visitor, wp_salt( 'auth' ) ), 0, 24 ) : 'site';
  $data['period'] = array( 'kind' => $period, 'date' => $date, 'from' => $start->format( 'Y-m-d' ), 'to' => $end->modify( '-1 day' )->format( 'Y-m-d' ), 'timezone' => wp_timezone_string() );
  $data['coverage'] = array( 'schema_ready' => $ready, 'partial' => $partial, 'event_limit' => self::LIMIT, 'tracking_enabled' => Tracker::enabled(), 'retention_days' => (int) Tracker::settings()['retention_days'], 'dwell' => 'observed_active_intervals_max_30s', 'module_clicks' => 'available_after_instrumentation_only', 'ranking_limit' => 50, 'ranking_truncated' => max( $data['ranking_counts'] ) > 50, 'identity' => 'browser_visitor_not_verified_person' );
  if ( ! (int) $visitor ) { $native = \PeptideCore\Data\Operations::period( (int) ( $from / 1000 ), (int) ( $to / 1000 ) ); $data['business_kpis'] = $native['kpis']; }
  $data['ai'] = array( 'status' => 'not_connected', 'cadences' => array( 'day', 'week', 'month' ), 'automatic_analysis' => false, 'profile_id' => (int) $visitor ? 'visitor_analysis' : 'site_analysis', 'persona_status' => 'not_configured', 'separate_personas' => true );
  $response = new \WP_REST_Response( $data ); $response->header( 'Cache-Control', 'private, no-store' ); return $response;
 }

 /** Pure aggregation: no inferred purchases, no inferred preference labels. */
 public static function summarize( array $rows, int $from, int $to ): array {
  $pages = array(); $products = array(); $modules = array(); $last = array(); $visitors = array(); $visits = array(); $intervals = array(); $page_intervals = array(); $product_intervals = array(); $views = 0;
  foreach ( $rows as $row ) {
   $at = (int) $row['occurred_ms']; if ( $at < $from || $at >= $to ) { continue; }
   $vid = (int) $row['visitor_id']; $visitors[$vid] = true; $visits[(int) $row['visit_id']] = true;
   $type = $row['type']; $d = json_decode( (string) $row['data'], true ); $d = is_array( $d ) ? $d : array();
   $path = explode( '?', (string) $row['path'], 2 )[0];
   if ( in_array( $row['page_type'], array( 'account', 'order_pay', 'order_received' ), true ) ) { $path = '[' . $row['page_type'] . ']'; }
   $path = mb_substr( $path, 0, 500 );
   if ( ! isset( $pages[$path] ) ) { $pages[$path] = array( 'path' => $path, 'views' => 0, 'active_seconds' => 0.0 ); }
   if ( $type === 'page_view' ) { ++$pages[$path]['views']; ++$views; }
   $pid = (int) $row['product_id'];
   if ( $pid > 0 ) {
    if ( ! isset( $products[$pid] ) ) { $products[$pid] = array( 'product_id' => $pid, 'name' => mb_substr( (string) ( $d['product_name'] ?? ( '#' . $pid ) ), 0, 120 ), 'views' => 0, 'clicks' => 0, 'cart_additions' => 0, 'active_seconds' => 0.0 ); }
    if ( $type === 'product_view' ) { ++$products[$pid]['views']; }
    if ( $type === 'product_click' ) { ++$products[$pid]['clicks']; }
    if ( $type === 'add_to_cart' && $row['source'] === 'server' ) { ++$products[$pid]['cart_additions']; }
   }
   if ( $type === 'module_click' && is_string( $d['module_id'] ?? null ) && preg_match( '/\A[a-zA-Z0-9_-]{1,60}\z/', $d['module_id'] ) ) {
    $key = $d['module_id']; if ( ! isset( $modules[$key] ) ) { $modules[$key] = array( 'module_id' => $key, 'module_type' => preg_match( '/\A[a-zA-Z0-9_-]{1,40}\z/', (string) ( $d['module_type'] ?? '' ) ) ? $d['module_type'] : '', 'clicks' => 0 ); } ++$modules[$key]['clicks'];
   }
   // Presence describes an observed state, never an assumption that an open tab is read.
   if ( ! in_array( $type, array( 'page_view', 'heartbeat', 'visibility_change', 'page_exit' ), true ) ) { continue; }
   $key = $vid . '|' . $row['tab_id'] . '|' . $row['page_instance_id'];
   $previous = $last[$key] ?? null;
   if ( $previous && $at > $previous['at'] && $at - $previous['at'] <= 30000 && $previous['visible'] && $previous['active'] ) {
    // Idle threshold may have been reached before this receipt. Clip using the activity age.
    $until = $at;
    if ( isset( $d['last_activity_age_ms'] ) && ( $d['activity'] ?? '' ) === 'idle' ) { $until = min( $until, $at - max( 0, (int) $d['last_activity_age_ms'] - Tracker::IDLE_MS ) ); }
    $until = max( $previous['at'], $until ); $seconds = ( $until - $previous['at'] ) / 1000;
    $page_intervals[$previous['path']][$vid][] = array( $previous['at'], $until );
    if ( $previous['product'] && isset( $products[$previous['product']] ) ) { $product_intervals[$previous['product']][$vid][] = array( $previous['at'], $until ); }
    if ( $until > $previous['at'] ) { $intervals[$vid][] = array( $previous['at'], $until ); }
   }
   $last[$key] = array( 'at' => $at, 'path' => $path, 'product' => $pid, 'visible' => ( $d['visibility'] ?? '' ) === 'visible', 'active' => ( $d['activity'] ?? '' ) === 'active' && $type !== 'page_exit' );
  }
  // Union overlapping tabs per visitor for the overall reading total.
  $union = static function ( array $by_visitor ): float {
   $total = 0; foreach ( $by_visitor as $list ) { usort( $list, static fn( $a, $b ) => $a[0] <=> $b[0] ); $end = 0; foreach ( $list as [$a,$b] ) { $total += max( 0, $b - max( $a, $end ) ); $end = max( $end, $b ); } } return round( $total / 1000, 1 );
  };
  $active = $union( $intervals );
  foreach ( $page_intervals as $key => $list ) { $pages[$key]['active_seconds'] = $union( $list ); }
  foreach ( $product_intervals as $key => $list ) { $products[$key]['active_seconds'] = $union( $list ); }
  $rank = static function ( array $items, string $key ): array { $items = array_values( $items ); usort( $items, static fn( $a, $b ) => $b[$key] <=> $a[$key] ?: strcmp( json_encode( $a ), json_encode( $b ) ) ); return array_slice( $items, 0, 50 ); };
  return array( 'ranking_counts' => array( 'pages' => count( $pages ), 'products' => count( $products ), 'modules' => count( $modules ) ), 'summary' => array( 'visitors' => count( $visitors ), 'visits' => count( $visits ), 'pageviews' => $views, 'active_seconds' => $active, 'observed_events' => count( $rows ) ), 'pages' => $rank( $pages, 'active_seconds' ), 'modules' => $rank( $modules, 'clicks' ), 'products' => $rank( $products, 'clicks' ) );
 }
}
