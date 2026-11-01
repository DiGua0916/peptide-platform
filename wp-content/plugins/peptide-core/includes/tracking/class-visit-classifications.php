<?php
/** Authenticated source verdicts. No inferred visitor classifier or business side effects. */
namespace PeptideCore\Tracking;
defined( 'ABSPATH' ) || exit;
final class Visit_Classifications {
 public const TYPE = 'visit_classification';
 public const STATUSES = array( 'normal', 'abnormal', 'unclassified' );
 public static function clean( $input ): ?array {
  if ( ! is_array( $input ) || ( $input['version'] ?? null ) !== 1 || ! is_int( $input['revision'] ?? null ) || $input['revision'] < 1 || $input['revision'] > 1000000000 || ! in_array( $input['status'] ?? null, self::STATUSES, true ) || ! is_string( $input['rule_version'] ?? null ) || ! preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,39}\z/', $input['rule_version'] ) ) { return null; }
  return array( 'version' => 1, 'revision' => $input['revision'], 'status' => $input['status'], 'rule_version' => $input['rule_version'] );
 }
 public static function id( $value ): bool { return is_int( $value ) && $value > 0 && $value <= min( PHP_INT_MAX, 9007199254740991 ); }
 public static function uid( $value ): bool { return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9-]{8,64}\z/', $value ); }
 public static function permission() { return current_user_can( 'manage_woocommerce' ) && get_current_user_id() > 0 ? true : new \WP_Error( 'pc_forbidden', __( 'Source classification requires store management permission.', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) ); }
 private static function owner( array $row ): bool {
  $v = (int) ( $row['visitor_id'] ?? 0 ); $s = (int) ( $row['visit_id'] ?? 0 );
  return $v > 0 && $s > 0 && (int) ( $row['matched_visitor_id'] ?? 0 ) === $v && (int) ( $row['matched_visit_id'] ?? 0 ) === $s && (int) ( $row['owner'] ?? 0 ) === $v && (int) ( $row['is_test'] ?? 1 ) === 0 && (int) ( $row['visit_test'] ?? 1 ) === 0 && (int) ( $row['visitor_test'] ?? 1 ) === 0;
 }
 private static function column( $value ): ?int { if ( is_string( $value ) ) { if ( ! preg_match( '/\A[1-9][0-9]*\z/', $value ) || strlen( $value ) > 16 || ( strlen( $value ) === 16 && strcmp( $value, '9007199254740991' ) > 0 ) ) { return null; } $value = (int) $value; } return self::id( $value ) ? $value : null; }
 public static function saved( array $row ): ?array {
  if ( self::column( $row['received_ms'] ?? null ) === null || self::column( $row['occurred_ms'] ?? null ) !== self::column( $row['received_ms'] ?? null ) || ( $row['type'] ?? null ) !== self::TYPE || ( $row['source'] ?? null ) !== 'server' || ! self::uid( $row['event_uid'] ?? null ) || ! self::owner( $row ) ) { return null; }
  $raw = is_array( $row['data'] ?? null ) ? $row['data'] : json_decode( (string) ( $row['data'] ?? '' ), true ); $clean = self::clean( $raw );
  return $clean !== null && $raw === $clean ? $clean : null;
 }
 private static function winner( int $visitor, string $uid ): ?array {
  global $wpdb; $e = Schema::table( 'events' ); $s = Schema::table( 'visits' ); $v = Schema::table( 'visitors' );
  $row = $wpdb->get_row( $wpdb->prepare( "SELECT e.*,s.visitor_id AS owner,s.id AS matched_visit_id,v.id AS matched_visitor_id,s.is_test AS visit_test,v.is_test AS visitor_test FROM {$e} e LEFT JOIN {$s} s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id LEFT JOIN {$v} v ON v.id=e.visitor_id WHERE e.visitor_id=%d AND e.event_uid=%s", $visitor, $uid ), ARRAY_A );
  if ( $wpdb->last_error ) { throw new \RuntimeException( 'Classification read unavailable.' ); } return is_array( $row ) ? $row : null;
 }
 private static function replay( array $incoming, array $row ): string {
  if ( ( $row['event_uid'] ?? null ) !== $incoming['event_id'] || (int) $row['visitor_id'] !== $incoming['visitor_id'] || (int) $row['visit_id'] !== $incoming['visit_id'] || ( $row['type'] ?? null ) !== self::TYPE || ( $row['source'] ?? null ) !== 'server' ) { return 'duplicate_conflict'; }
  $raw = json_decode( (string) ( $row['data'] ?? '' ), true );
  if ( $raw !== $incoming['data'] ) { return 'duplicate_conflict'; } return self::saved( $row ) === null ? 'original_visit_missing' : 'ack';
 }
 /** Public entry remains authenticated even when invoked independently of the REST route. */
 public static function receive( \WP_REST_Request $request ) {
  $permission = self::permission(); if ( $permission !== true ) { return $permission; }
  $raw = $request->get_body(); if ( strlen( $raw ) > 65536 ) { return new \WP_Error( 'pc_classification_too_large', __( 'Classification batch is too large.', 'peptide-core' ), array( 'status' => 413 ) ); }
  $body = json_decode( $raw, true );
  if ( ! is_array( $body ) || ( $body['protocol'] ?? null ) !== 1 || ! is_array( $body['events'] ?? null ) || ! array_is_list( $body['events'] ) ) { return new \WP_Error( 'pc_classification_bad_request', __( 'Invalid classification batch.', 'peptide-core' ), array( 'status' => 400 ) ); }
  if ( count( $body['events'] ) > 20 ) { return new \WP_Error( 'pc_classification_too_large', __( 'Classification batch is too large.', 'peptide-core' ), array( 'status' => 413 ) ); }
  $now = time(); $key = 'pc_classify_' . get_current_user_id(); $rate = get_transient( $key );
  if ( ! is_array( $rate ) || (int) ( $rate[0] ?? 0 ) + 300 <= $now ) { $rate = array( $now, 0 ); }
  if ( $rate[1] + count( $body['events'] ) > 600 ) { return new \WP_Error( 'pc_classification_rate_limited', __( 'Too many classification events.', 'peptide-core' ), array( 'status' => 429, 'retry_after' => max( 1, $rate[0] + 300 - $now ) ) ); }
  set_transient( $key, array( $rate[0], $rate[1] + count( $body['events'] ) ), 300 );
  if ( ! Schema::installed() ) { return new \WP_Error( 'pc_classification_unavailable', __( 'Classification storage is unavailable.', 'peptide-core' ), array( 'status' => 503 ) ); }
  global $wpdb; $acks = array(); $rejected = array(); $e = Schema::table( 'events' ); $s = Schema::table( 'visits' ); $v = Schema::table( 'visitors' );
  foreach ( $body['events'] as $input ) {
   $uid = is_array( $input ) && self::uid( $input['event_id'] ?? null ) ? $input['event_id'] : ''; $code = 'bad_classification';
   if ( $uid && self::id( $input['visitor_id'] ?? null ) && self::id( $input['visit_id'] ?? null ) && ( $data = self::clean( is_array( $input['data'] ?? null ) ? array_merge( $input['data'], array( 'revision' => $input['revision'] ?? null ) ) : null ) ) !== null ) {
    $incoming = array( 'event_id' => $uid, 'visitor_id' => $input['visitor_id'], 'visit_id' => $input['visit_id'], 'data' => $data );
    try {
     $winner = self::winner( $incoming['visitor_id'], $uid );
     if ( $winner ) { $code = self::replay( $incoming, $winner ); }
     else {
      $owner = $wpdb->get_row( $wpdb->prepare( "SELECT s.id,s.visitor_id,s.is_test AS visit_test,v.is_test AS visitor_test FROM {$s} s INNER JOIN {$v} v ON v.id=s.visitor_id WHERE s.id=%d AND s.visitor_id=%d", $incoming['visit_id'], $incoming['visitor_id'] ), ARRAY_A );
      if ( $wpdb->last_error ) { throw new \RuntimeException( 'Classification owner unavailable.' ); }
      if ( ! $owner || (int) $owner['id'] !== $incoming['visit_id'] || (int) $owner['visitor_id'] !== $incoming['visitor_id'] || (int) $owner['visit_test'] || (int) $owner['visitor_test'] ) { $code = 'original_visit_missing'; }
      else {
       $ms = Tracker::now_ms();
       $inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$e} (event_uid,visitor_id,visit_id,type,source,occurred_ms,received_ms,data,is_test) VALUES (%s,%d,%d,%s,'server',%d,%d,%s,0)", $uid, $incoming['visitor_id'], $incoming['visit_id'], self::TYPE, $ms, $ms, wp_json_encode( $data ) ) );
       if ( $inserted === false ) { throw new \RuntimeException( 'Classification write unavailable.' ); }
       if ( (int) $inserted === 1 ) { $code = 'ack'; } else { $winner = self::winner( $incoming['visitor_id'], $uid ); $code = $winner ? self::replay( $incoming, $winner ) : 'server_error'; }
      }
     }
    } catch ( \Throwable $error ) { $code = 'server_error'; }
   }
   if ( $code === 'ack' ) { $acks[] = $uid; } else { $rejected[] = array( 'event_id' => $uid, 'code' => $code, 'retry' => $code === 'server_error' ); }
  }
  $response = new \WP_REST_Response( array( 'protocol' => 1, 'ack' => $acks, 'rejected' => $rejected ) ); $response->header( 'Cache-Control', 'no-store' ); return $response;
 }
 /** Bounded facts for an explicit original visit-owner map, never visitor-latest classification. */
 public static function for_visits( array $owners, int $cutoff ): array {
  if ( ! Schema::installed() ) { throw new \RuntimeException( 'Classification storage unavailable.' ); }
  $out = array(); foreach ( $owners as $visit => $visitor ) { if ( ! self::id( $visit ) || ! self::id( $visitor ) ) { throw new \InvalidArgumentException( 'Invalid classification owner map.' ); } $out[$visit] = array( 'status' => 'unclassified', 'observed' => false, 'conflict' => false, 'revision' => 0, 'rule_version' => null ); }
  global $wpdb; $e = Schema::table( 'events' ); $s = Schema::table( 'visits' ); $v = Schema::table( 'visitors' );
  foreach ( array_chunk( array_keys( $owners ), 500 ) as $ids ) {
   $cursor = 0; $in = implode( ',', $ids );
   do {
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.*,s.visitor_id AS owner,s.id AS matched_visit_id,v.id AS matched_visitor_id,s.is_test AS visit_test,v.is_test AS visitor_test FROM {$e} e INNER JOIN {$s} s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id INNER JOIN {$v} v ON v.id=e.visitor_id WHERE e.visit_id IN ({$in}) AND e.type='visit_classification' AND e.source='server' AND e.is_test=0 AND s.is_test=0 AND v.is_test=0 AND e.received_ms<%d AND e.id>%d ORDER BY e.id ASC LIMIT 500", $cutoff, $cursor ), ARRAY_A );
    if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new \RuntimeException( 'Classification observations unavailable.' ); }
    foreach ( $rows as $row ) {
     $cursor = (int) $row['id']; $visit = (int) $row['visit_id'];
     if ( ! isset( $owners[$visit] ) || $owners[$visit] !== (int) $row['visitor_id'] || (int) $row['received_ms'] >= $cutoff ) { continue; }
     $d = self::saved( $row ); if ( $d === null ) { continue; }
     if ( $d['revision'] > $out[$visit]['revision'] ) { $out[$visit] = array( 'status' => $d['status'], 'observed' => true, 'conflict' => false, 'revision' => $d['revision'], 'rule_version' => $d['rule_version'] ); }
     elseif ( $d['revision'] === $out[$visit]['revision'] && ( $d['status'] !== $out[$visit]['status'] || $d['rule_version'] !== $out[$visit]['rule_version'] ) ) { $out[$visit]['status'] = 'unclassified'; $out[$visit]['conflict'] = true; }
    }
   } while ( count( $rows ) === 500 );
  }
  return $out;
 }
 public static function register_hooks(): void { add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'add_exporter' ) ); add_action( 'admin_init', array( self::class, 'policy_text' ) ); }
 public static function add_exporter( $exporters ): array { $exporters = is_array( $exporters ) ? $exporters : array(); $exporters['peptide-core-visit-classifications'] = array( 'exporter_friendly_name' => __( 'Source visit status observations', 'peptide-core' ), 'callback' => array( self::class, 'export' ) ); return $exporters; }
 public static function export( string $email, int $page = 1 ): array {
  global $wpdb; if ( $page < 1 || $page - 1 > intdiv( PHP_INT_MAX, 500 ) ) { return array( 'data' => array(), 'done' => true ); }
  $ids = Privacy::visitors_for_email( $email ); if ( ! $ids ) { return array( 'data' => array(), 'done' => true ); }
  $e = Schema::table( 'events' ); $s = Schema::table( 'visits' ); $v = Schema::table( 'visitors' ); $in = implode( ',', array_map( 'intval', $ids ) );
  $rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.*,s.visitor_id AS owner,s.id AS matched_visit_id,v.id AS matched_visitor_id,s.is_test AS visit_test,v.is_test AS visitor_test FROM {$e} e INNER JOIN {$s} s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id INNER JOIN {$v} v ON v.id=e.visitor_id WHERE e.visitor_id IN ({$in}) AND e.type='visit_classification' AND e.source='server' ORDER BY e.id ASC LIMIT 501 OFFSET %d", ( $page - 1 ) * 500 ), ARRAY_A );
  if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new \RuntimeException( 'Classification export unavailable.' ); }
  $items = array(); foreach ( array_slice( $rows, 0, 500 ) as $row ) { $d = self::saved( $row ); if ( $d === null || ! in_array( (int) $row['visitor_id'], $ids, true ) ) { continue; } $snapshot = array( 'visit_id' => (int) $row['visit_id'], 'received_at_utc' => gmdate( 'Y-m-d H:i:s', intdiv( (int) $row['received_ms'], 1000 ) ) . ' UTC' ) + $d; $items[] = array( 'group_id' => 'peptide-core-visit-classifications', 'group_label' => __( 'Source visit status observations', 'peptide-core' ), 'item_id' => 'visit-classification-' . (int) $row['id'], 'data' => array( array( 'name' => __( 'Source visit status snapshot (JSON)', 'peptide-core' ), 'value' => wp_json_encode( $snapshot ) ) ) ); }
  return array( 'data' => $items, 'done' => count( $rows ) <= 500 );
 }
 public static function policy_sentence(): string { return 'An authenticated source may mark an existing visit as normal, abnormal or unclassified with an opaque rule version and revision. We store these explicit source observations and their server receipt time, not a guessed bot score, IP-based verdict or raw classification rules. Missing or conflicting observations remain unclassified. These records follow visit retention and whole-event deletion rules and are included in a separate personal-data export.'; }
 public static function policy_text(): void { if ( function_exists( 'wp_add_privacy_policy_content' ) ) { wp_add_privacy_policy_content( __( 'Store visit analytics', 'peptide-core' ), '<p>' . esc_html__( self::policy_sentence(), 'peptide-core' ) . '</p>' ); } }
}
