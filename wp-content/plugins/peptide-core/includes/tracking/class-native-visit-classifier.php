<?php
/** Approved operational visit rules. No business mutation, IP or raw-UA retention. */
namespace PeptideCore\Tracking;
defined( 'ABSPATH' ) || exit;
final class Native_Visit_Classifier {
 public const OPTION = 'peptide_core_visit_classifier_policy';
 public const BOTS = array( 'Googlebot', 'Bingbot', 'DuckDuckBot', 'Baiduspider', 'YandexBot', 'facebookexternalhit', 'Twitterbot' );
 public const TYPES = array( 'page_view', 'product_view', 'add_to_cart_attempt', 'checkout_submit_attempt', 'product_intent', 'product_favorite', 'product_share' );
 public static function register_hooks(): void {
  add_action( 'peptide_core_tracking_client_persisted', array( self::class, 'after_persisted' ), 10, 3 );
  add_action( 'admin_menu', array( self::class, 'menu' ), 61 );
  add_action( 'admin_post_pc_visit_classifier_settings', array( self::class, 'handle_settings' ) );
  add_action( 'admin_post_pc_visit_classifier_manual', array( self::class, 'handle_manual' ) );
 }
 public static function defaults(): array { return array( 'version' => 1, 'enabled' => true, 'window_s' => 60, 'threshold' => 120, 'bots_enabled' => true, 'bots' => self::BOTS ); }
 public static function normalize( $input ): ?array {
  if ( ! is_array( $input ) || ! is_bool( $input['enabled'] ?? null ) ) { return null; }
  $policy = Visit_Classifications::auto_policy( $input );
  return $policy ? array( 'version' => 1, 'enabled' => $input['enabled'] ) + array_diff_key( $policy, array( 'version' => true ) ) : null;
 }
 public static function config(): array {
  $raw = get_option( self::OPTION, null ); if ( $raw === null ) { return self::defaults(); }
  return self::normalize( $raw ) ?? array_replace( self::defaults(), array( 'enabled' => false ) );
 }
 public static function rule_version( array $policy ): string { return 'native-v1-' . substr( hash( 'sha256', wp_json_encode( $policy ) ), 0, 24 ); }
 public static function automatic_uid( int $visitor, int $visit, array $data ): string { return 'auto-class-' . substr( hash( 'sha256', $visitor . ':' . $visit . ':' . $data['rule_version'] . ':' . $data['status'] ), 0, 40 ); }
 private static function checked_rows( string $sql ): array {
  global $wpdb; $rows = $wpdb->get_results( $sql, ARRAY_A ); if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new \RuntimeException( 'Visit classifier storage unavailable.' ); } return $rows;
 }
 private static function privacy_allowed(): bool { return Tracker::enabled() && ! Tracker::declined() && ! Tracker::is_staff( Tracker::cookie_user_id() ); }
 /** Exact native original owner and at least one saved non-test PV; never allocates identities. */
 public static function eligible( int $visitor, int $visit ): bool {
  if ( ! Visit_Classifications::id( $visitor ) || ! Visit_Classifications::id( $visit ) || ! Schema::installed() ) { return false; }
  global $wpdb; $s = Schema::table( 'visits' ); $v = Schema::table( 'visitors' ); $e = Schema::table( 'events' );
  $id = $wpdb->get_var( $wpdb->prepare( "/*classifier:eligible*/ SELECT s.id FROM {$s} s INNER JOIN {$v} v ON v.id=s.visitor_id WHERE s.id=%d AND s.visitor_id=%d AND s.is_test=0 AND v.is_test=0 AND EXISTS (SELECT 1 FROM {$e} e WHERE e.visit_id=s.id AND e.visitor_id=s.visitor_id AND e.type='page_view' AND e.source='client' AND e.is_test=0 AND e.received_ms<=%d)", $visit, $visitor, Tracker::now_ms() ) );
  if ( $wpdb->last_error ) { throw new \RuntimeException( 'Visit classifier owner unavailable.' ); } return (int) $id === $visit;
 }
 /** Read all valid immutable source labels. Any nonautomatic label stops automatic processing. */
 public static function history( int $visitor, int $visit ): array {
  $out = array( 'maximum' => 0, 'external' => false, 'automatic' => null, 'abnormal' => false, 'policy_conflict' => false );
  global $wpdb; $e = Schema::table( 'events' ); $s = Schema::table( 'visits' ); $v = Schema::table( 'visitors' ); $cursor = 0;
  do {
   $rows = self::checked_rows( $wpdb->prepare( "/*classifier:history*/ SELECT e.*,s.visitor_id AS owner,s.id AS matched_visit_id,v.id AS matched_visitor_id,s.is_test AS visit_test,v.is_test AS visitor_test FROM {$e} e INNER JOIN {$s} s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id INNER JOIN {$v} v ON v.id=e.visitor_id WHERE e.visit_id=%d AND e.visitor_id=%d AND e.type='visit_classification' AND e.source='server' AND e.id>%d ORDER BY e.id ASC LIMIT 500", $visit, $visitor, $cursor ) );
   foreach ( $rows as $row ) {
    $cursor = (int) $row['id']; if ( (int) $row['visit_id'] !== $visit || (int) $row['visitor_id'] !== $visitor ) { continue; }
    $d = Visit_Classifications::saved( $row ); if ( ! $d ) { continue; } $out['maximum'] = max( $out['maximum'], $d['revision'] );
    if ( ( $d['origin'] ?? '' ) !== 'automatic' ) { $out['external'] = true; continue; }
    if ( $out['automatic'] === null ) { $out['automatic'] = $d; }
    elseif ( $out['automatic']['policy'] !== $d['policy'] ) { $out['policy_conflict'] = true; }
    if ( $d['status'] === 'abnormal' ) { $out['abnormal'] = true; }
   }
  } while ( count( $rows ) === 500 );
  return $out;
 }
 private static function new_critical( int $visitor, int $visit, array $uids ): bool {
  if ( ! $uids || count( $uids ) > 20 || ! array_is_list( $uids ) ) { return false; }
  foreach ( $uids as $uid ) { if ( ! Visit_Classifications::uid( $uid ) ) { return false; } }
  global $wpdb; $e = Schema::table( 'events' ); $marks = implode( ',', array_fill( 0, count( $uids ), '%s' ) ); $types = "'" . implode( "','", self::TYPES ) . "'";
  $id = $wpdb->get_var( $wpdb->prepare( "/*classifier:new*/ SELECT id FROM {$e} WHERE visitor_id=%d AND visit_id=%d AND source='client' AND is_test=0 AND type IN ({$types}) AND event_uid IN ({$marks}) AND received_ms<=%d LIMIT 1", array_merge( array( $visitor, $visit ), $uids, array( Tracker::now_ms() ) ) ) );
  if ( $wpdb->last_error ) { throw new \RuntimeException( 'Visit classifier evidence unavailable.' ); } return (int) $id > 0;
 }
 /** Window is (receipt-window,receipt], using distinct accepted event rows, never retry attempts. */
 public static function evidence( int $visitor, int $visit, array $policy, int $at ): array {
  global $wpdb; $e = Schema::table( 'events' ); $from = max( 0, $at - $policy['window_s'] * 1000 ); $types = "'" . implode( "','", self::TYPES ) . "'";
  $count = $wpdb->get_var( $wpdb->prepare( "/*classifier:count*/ SELECT COUNT(DISTINCT id) FROM {$e} WHERE visitor_id=%d AND visit_id=%d AND source='client' AND is_test=0 AND type IN ({$types}) AND received_ms>%d AND received_ms<=%d", $visitor, $visit, $from, $at ) );
  if ( $wpdb->last_error || $count === null || ! is_numeric( $count ) || (int) $count < 0 || (int) $count > 1000000 ) { throw new \RuntimeException( 'Visit classifier counts unavailable.' ); }
  $bot = null; $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? substr( $_SERVER['HTTP_USER_AGENT'], 0, 400 ) : '';
  if ( $policy['bots_enabled'] ) { foreach ( $policy['bots'] as $token ) { if ( stripos( $ua, $token ) !== false ) { $bot = $token; break; } } }
  return array( 'window_from_ms' => $from, 'window_to_ms' => $at, 'event_count' => (int) $count, 'bot_token' => $bot );
 }
 private static function decision( array $policy, array $evidence ): array {
  $reason = $evidence['bot_token'] !== null ? 'known_bot' : ( $evidence['event_count'] > $policy['threshold'] ? 'high_rate' : 'valid_visit' ); $status = $reason === 'valid_visit' ? 'normal' : 'abnormal';
  return array( 'version' => 1, 'revision' => $status === 'normal' ? 1 : 2, 'status' => $status, 'rule_version' => self::rule_version( $policy ), 'origin' => 'automatic', 'reason' => $reason, 'policy' => $policy, 'evidence' => $evidence );
 }
 public static function verify_automatic( int $visitor, int $visit, array $data ): bool {
  if ( ! self::config()['enabled'] || ! self::privacy_allowed() || ! self::eligible( $visitor, $visit ) ) { return false; }
  $h = self::history( $visitor, $visit ); if ( $h['external'] || $h['abnormal'] || $h['policy_conflict'] ) { return false; }
  $policy = $h['automatic']['policy'] ?? array_diff_key( self::config(), array( 'enabled' => true ) );
  if ( $policy !== $data['policy'] || $data['evidence']['window_to_ms'] > Tracker::now_ms() ) { return false; }
  return self::decision( $policy, self::evidence( $visitor, $visit, $policy, $data['evidence']['window_to_ms'] ) ) === $data;
 }
 /** Hook receives only actual new persisted UIDs; failures are optional and cannot affect ACK. */
 public static function after_persisted( int $visitor, int $visit, array $uids ): void {
  try {
   $config = self::config(); if ( ! $config['enabled'] || ! self::privacy_allowed() || ! self::eligible( $visitor, $visit ) || ! self::new_critical( $visitor, $visit, $uids ) ) { return; }
   $history = self::history( $visitor, $visit ); if ( $history['external'] || $history['abnormal'] || $history['policy_conflict'] ) { return; }
   $policy = $history['automatic']['policy'] ?? array_diff_key( $config, array( 'enabled' => true ) );
   $data = self::decision( $policy, self::evidence( $visitor, $visit, $policy, Tracker::now_ms() ) );
   if ( $history['automatic'] && $data['status'] === 'normal' ) { return; }
   Visit_Classifications::write_automatic( $visitor, $visit, $data );
  } catch ( \Throwable $optional_failure ) { /* No ACK, activity, stage or source event is rewritten. */ }
 }
 public static function menu(): void { add_submenu_page( 'pc-data', __( 'Visit classification', 'peptide-core' ), __( 'Visit classification', 'peptide-core' ), 'manage_woocommerce', 'pc-data-visit-classifier', array( self::class, 'render' ) ); }
 private static function permission(): void { if ( Visit_Classifications::permission() !== true ) { wp_die( esc_html__( 'Store management permission is required.', 'peptide-core' ), '', array( 'response' => 403 ) ); } }
 private static function posted_int( $value ): ?int {
  if ( is_string( $value ) && preg_match( '/\A(?:0|[1-9][0-9]{0,15})\z/D', $value ) && ( strlen( $value ) < 16 || strcmp( $value, '9007199254740991' ) <= 0 ) ) { return (int) $value; }
  return is_int( $value ) && $value >= 0 && $value <= 9007199254740991 ? $value : null;
 }
 private static function redirect( string $notice ): void { wp_safe_redirect( add_query_arg( array( 'page' => 'pc-data-visit-classifier', 'notice' => $notice ), admin_url( 'admin.php' ) ) ); exit; }
 public static function handle_settings(): void {
  self::permission(); check_admin_referer( 'pc_visit_classifier_settings' );
  $policy = array( 'version' => 1, 'enabled' => isset( $_POST['enabled'] ), 'window_s' => self::posted_int( $_POST['window_s'] ?? null ), 'threshold' => self::posted_int( $_POST['threshold'] ?? null ), 'bots_enabled' => isset( $_POST['bots_enabled'] ), 'bots' => isset( $_POST['bots'] ) && is_array( $_POST['bots'] ) ? array_values( wp_unslash( $_POST['bots'] ) ) : array() );
  $clean = self::normalize( $policy ); if ( ! $clean ) { self::redirect( 'invalid' ); }
  update_option( self::OPTION, $clean, false ); self::redirect( 'saved' );
 }
 public static function handle_manual(): void {
  self::permission(); $visit = self::posted_int( $_POST['visit_id'] ?? null ); check_admin_referer( 'pc_visit_classifier_manual_' . ( $visit ?? 0 ) );
  $visitor = self::posted_int( $_POST['visitor_id'] ?? null ); $expected = self::posted_int( $_POST['expected_revision'] ?? null ); $status = is_string( $_POST['status'] ?? null ) ? $_POST['status'] : '';
  if ( ! $visit || ! $visitor || $expected === null ) { self::redirect( 'invalid' ); }
  try { $result = Visit_Classifications::write_manual( $visitor, $visit, $expected, $status ); } catch ( \Throwable $error ) { $result = 'unavailable'; }
  self::redirect( $result === 'ack' ? 'corrected' : ( $result === 'stale_revision' ? 'stale' : 'unavailable' ) );
 }
 private static function status_label( string $status ): string { $labels = array( 'normal' => 'Normal', 'abnormal' => 'Abnormal', 'unclassified' => 'Unclassified' ); return __( $labels[$status] ?? 'Unclassified', 'peptide-core' ); }
 private static function reason_label( string $reason ): string { $labels = array( 'valid_visit' => 'Valid saved visit', 'known_bot' => 'Known-bot literal token', 'high_rate' => 'Accepted-event threshold exceeded', 'manual_review' => 'Manual review', 'external_source' => 'External source', 'not_observed' => 'Not observed' ); return __( $labels[$reason] ?? 'Not observed', 'peptide-core' ); }
 private static function evidence_label( array $verdict ): string {
  if ( ! isset( $verdict['policy'], $verdict['evidence'] ) ) { return __( 'No automatic evidence snapshot.', 'peptide-core' ); }
  return sprintf( __( 'Window: %1$d seconds; received events: %2$d / threshold %3$d; bot token: %4$s.', 'peptide-core' ), $verdict['policy']['window_s'], $verdict['evidence']['event_count'], $verdict['policy']['threshold'], $verdict['evidence']['bot_token'] ?? __( 'None', 'peptide-core' ) );
 }
 public static function render(): void {
  self::permission(); $config = self::config();
  echo '<div class="wrap"><h1>' . esc_html__( 'Visit classification', 'peptide-core' ) . '</h1><p>' . esc_html__( 'Classifies saved valid visits using selected user-agent tokens and accepted-event frequency. A valid saved page view is required. A user-agent token is a reported marker, not authenticated identity. Disabling preserves historical verdicts.', 'peptide-core' ) . '</p>';
  $notices = array( 'saved' => 'Policy saved. It applies to newly classified visits.', 'corrected' => 'Manual correction recorded. Concurrent verdict conflicts remain explicitly unclassified.', 'stale' => 'Revision changed. Reload before correcting this visit.', 'invalid' => 'Invalid policy or correction.', 'unavailable' => 'Classification storage is unavailable. No correction was confirmed.' );
  $notice = is_string( $_GET['notice'] ?? null ) ? $_GET['notice'] : ''; if ( isset( $notices[$notice] ) ) { echo '<div class="notice notice-info"><p>' . esc_html( __( $notices[$notice], 'peptide-core' ) ) . '</p></div>'; }
  echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pc_visit_classifier_settings">'; wp_nonce_field( 'pc_visit_classifier_settings' );
  foreach ( array( 'enabled' => 'Automatic classification enabled', 'bots_enabled' => 'Match selected known-bot literal tokens' ) as $key => $label ) { echo '<p><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . checked( $config[$key], true, false ) . '> ' . esc_html( __( $label, 'peptide-core' ) ) . '</label></p>'; }
  echo '<p><label>' . esc_html__( 'Receipt window in seconds (30–300)', 'peptide-core' ) . ' <input type="number" name="window_s" min="30" max="300" value="' . esc_attr( $config['window_s'] ) . '" required></label></p><p><label>' . esc_html__( 'Abnormal only above this accepted-event count (20–500)', 'peptide-core' ) . ' <input type="number" name="threshold" min="20" max="500" value="' . esc_attr( $config['threshold'] ) . '" required></label></p>';
  foreach ( self::BOTS as $bot ) { echo '<label><input type="checkbox" name="bots[]" value="' . esc_attr( $bot ) . '"' . checked( in_array( $bot, $config['bots'], true ), true, false ) . '> ' . esc_html( $bot ) . '</label> '; }
  echo '<p>' . esc_html__( 'Distinct accepted critical client events only; heartbeat, visibility, video, rejected requests and retries do not count. Each visit freezes its first policy; automatic abnormal verdicts never downgrade. Manual or external labels stop automatic updates.', 'peptide-core' ) . '</p><button class="button button-primary">' . esc_html__( 'Save policy', 'peptide-core' ) . '</button></form><h2>' . esc_html__( 'Recent visits and manual review', 'peptide-core' ) . '</h2>';
  try {
   if ( ! Schema::installed() ) { throw new \RuntimeException(); }
   $page = max( 1, min( 1000000, self::posted_int( $_GET['paged'] ?? '1' ) ?? 1 ) ); global $wpdb; $st = Schema::table( 'visits' ); $vt = Schema::table( 'visitors' );
   $rows = self::checked_rows( $wpdb->prepare( "/*classifier:admin*/ SELECT s.id AS visit_id,s.visitor_id FROM {$st} s INNER JOIN {$vt} v ON v.id=s.visitor_id WHERE s.is_test=0 AND v.is_test=0 ORDER BY s.id DESC LIMIT 20 OFFSET %d", ( $page - 1 ) * 20 ) );
   $owners = array(); foreach ( $rows as $row ) { $owners[(int) $row['visit_id']] = (int) $row['visitor_id']; } $verdicts = Visit_Classifications::for_visits( $owners, Tracker::now_ms() + 1 );
   echo '<table class="widefat striped"><thead><tr>'; foreach ( array( 'Visit / visitor', 'Status', 'Reason / rule / revision', 'Frozen policy / evidence', 'Manual review' ) as $heading ) { echo '<th>' . esc_html( __( $heading, 'peptide-core' ) ) . '</th>'; } echo '</tr></thead><tbody>';
   foreach ( $owners as $visit => $visitor ) {
    $v = $verdicts[$visit]; echo '<tr><td>' . esc_html( $visit . ' / ' . $visitor ) . '</td><td>' . esc_html( self::status_label( $v['status'] ) . ( $v['conflict'] ? ' (' . __( 'Conflicting revision', 'peptide-core' ) . ')' : '' ) ) . '</td><td>' . esc_html( self::reason_label( $v['reason'] ?? ( $v['observed'] ? 'external_source' : 'not_observed' ) ) . ' / ' . ( $v['rule_version'] ?? '—' ) . ' / ' . $v['revision'] ) . '</td><td>' . esc_html( self::evidence_label( $v ) ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pc_visit_classifier_manual"><input type="hidden" name="visitor_id" value="' . esc_attr( $visitor ) . '"><input type="hidden" name="visit_id" value="' . esc_attr( $visit ) . '"><input type="hidden" name="expected_revision" value="' . esc_attr( $v['revision'] ) . '">';
    wp_nonce_field( 'pc_visit_classifier_manual_' . $visit ); echo '<select name="status">'; foreach ( Visit_Classifications::STATUSES as $status ) { echo '<option value="' . esc_attr( $status ) . '"' . selected( $status, $v['status'], false ) . '>' . esc_html( self::status_label( $status ) ) . '</option>'; } echo '</select><input type="hidden" name="reason" value="manual_review"><button class="button">' . esc_html__( 'Record manual review', 'peptide-core' ) . '</button></form></td></tr>';
   }
   echo '</tbody></table><p>'; if ( $page > 1 ) { echo '<a href="' . esc_url( add_query_arg( array( 'page' => 'pc-data-visit-classifier', 'paged' => $page - 1 ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Previous', 'peptide-core' ) . '</a> '; } if ( count( $rows ) === 20 ) { echo '<a href="' . esc_url( add_query_arg( array( 'page' => 'pc-data-visit-classifier', 'paged' => $page + 1 ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Next', 'peptide-core' ) . '</a>'; } echo '</p>';
  } catch ( \Throwable $error ) { echo '<p>' . esc_html__( 'Classification storage is unavailable.', 'peptide-core' ) . '</p>'; }
  echo '</div>';
 }
}
