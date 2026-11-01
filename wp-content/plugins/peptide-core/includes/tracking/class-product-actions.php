<?php
/** Explicit source-reported successful operations, not business or payment attestation. */
namespace PeptideCore\Tracking;
defined( 'ABSPATH' ) || exit;
final class Product_Actions {
 public const TYPES = array( 'product_favorite', 'product_share', 'product_intent' );
 public static function protocol(): int { return 1; }
 public static function is_type( string $type ): bool { return in_array( $type, self::TYPES, true ); }
 public static function clean( string $type, $input ): array {
  $bad = array( 'ok' => false, 'code' => 'bad_product_action' );
  if ( ! self::is_type( $type ) || ! is_array( $input ) || ! is_int( $input['version'] ?? null ) ) { return $bad; }
  if ( $input['version'] !== 1 ) { return array( 'ok' => false, 'code' => 'unsupported_product_action_version' ); }
  if ( ! Impressions::input_id( $input['product_id'] ?? null ) || ( array_key_exists( 'variation_id', $input ) && ! Impressions::input_id( $input['variation_id'], true ) ) ) { return $bad; }
  $d = array( 'version' => 1, 'product_id' => $input['product_id'], 'variation_id' => $input['variation_id'] ?? 0 );
  if ( $type === 'product_favorite' ) {
   if ( ( $input['action'] ?? null ) !== 'add' ) { return $bad; } $d['action'] = 'add';
  } elseif ( $type === 'product_share' ) {
   if ( ! in_array( $input['method'] ?? null, array( 'native', 'copy_link', 'other' ), true ) || ( $input['result'] ?? null ) !== 'completed' ) { return $bad; }
   $d['method'] = $input['method']; $d['result'] = 'completed';
  } else {
   if ( ! in_array( $input['kind'] ?? null, array( 'quick_view_open', 'buy_now' ), true ) || ( $input['result'] ?? null ) !== 'completed' ) { return $bad; }
   $d['kind'] = $input['kind']; $d['result'] = 'completed';
  }
  return array( 'ok' => true, 'value' => $d );
 }
 public static function saved( array $row ): ?array {
  $type = $row['type'] ?? ''; if ( ! self::is_type( $type ) || ( $row['source'] ?? '' ) !== 'client' || Impressions::path( $row['path'] ?? null ) === null || ! is_string( $row['event_uid'] ?? null ) || ! preg_match( '/\A[A-Za-z0-9-]{8,64}\z/', $row['event_uid'] ) ) { return null; }
  $v = Impressions::column_id( $row['visitor_id'] ?? null ); $s = Impressions::column_id( $row['visit_id'] ?? null );
  if ( ! $v || ! $s || Impressions::column_id( $row['matched_visitor_id'] ?? null ) !== $v || Impressions::column_id( $row['matched_visit_id'] ?? null ) !== $s ) { return null; }
  $raw = is_array( $row['data'] ?? null ) ? $row['data'] : json_decode( (string) ( $row['data'] ?? '' ), true ); $c = self::clean( $type, $raw );
  if ( ! $c['ok'] || $raw !== $c['value'] || Impressions::column_id( $row['product_id'] ?? null ) !== $c['value']['product_id'] || Impressions::column_id( $row['variation_id'] ?? null, true ) !== $c['value']['variation_id'] ) { return null; }
  return $c['value'];
 }
 public static function equal( int $visitor, array $incoming, array $row ): bool {
  $fixed = array( 'event_uid' => $incoming['event_id'], 'visitor_id' => $visitor, 'type' => $incoming['type'], 'source' => 'client', 'path' => $incoming['page']['path'], 'page_type' => $incoming['page']['page_type'], 'product_id' => $incoming['data']['product_id'], 'variation_id' => $incoming['data']['variation_id'], 'page_instance_id' => $incoming['page_instance_id'], 'tab_id' => $incoming['tab_id'], 'seq' => $incoming['seq'] );
  foreach ( $fixed as $k => $value ) { if ( is_int( $value ) ? Impressions::column_id( $row[$k] ?? null, true ) !== $value : ( $row[$k] ?? null ) !== $value ) { return false; } }
  $c = self::clean( $incoming['type'], json_decode( (string) ( $row['data'] ?? '' ), true ) ); return $c['ok'] && $c['value'] === $incoming['data'];
 }
 public static function replay_code( int $visitor, array $incoming, array $row ): string {
  if ( ! self::equal( $visitor, $incoming, $row ) ) { return 'duplicate_conflict'; } return self::saved( $row ) === null ? 'original_visit_missing' : 'ack';
 }
 public static function register_hooks(): void {
  add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'add_exporter' ) );
  add_action( 'admin_init', array( self::class, 'policy_text' ) );
 }
 public static function add_exporter( $exporters ): array {
  $exporters = is_array( $exporters ) ? $exporters : array();
  $exporters['peptide-core-product-actions'] = array( 'exporter_friendly_name' => __( 'Product action observations', 'peptide-core' ), 'callback' => array( self::class, 'export' ) ); return $exporters;
 }
 /** Separate exporter; raw pages advance independently of filtered item count. */
 public static function export( string $email, int $page = 1 ): array {
  global $wpdb;
  if ( $page < 1 || $page - 1 > intdiv( PHP_INT_MAX, 500 ) ) { return array( 'data' => array(), 'done' => true ); }
  $ids = Privacy::visitors_for_email( $email ); if ( ! $ids ) { return array( 'data' => array(), 'done' => true ); }
  $e = Schema::table( 'events' ); $s = Schema::table( 'visits' ); $v = Schema::table( 'visitors' );
  $sql = "SELECT e.*,v.id AS matched_visitor_id,s.id AS matched_visit_id FROM {$e} e JOIN {$s} s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id JOIN {$v} v ON v.id=e.visitor_id WHERE e.visitor_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ") AND e.type IN ('product_favorite','product_share','product_intent') AND e.source='client' ORDER BY e.id ASC LIMIT 501 OFFSET %d";
  $rows = $wpdb->get_results( $wpdb->prepare( $sql, ( $page - 1 ) * 500 ), ARRAY_A );
  if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new \RuntimeException( 'Product action export unavailable.' ); }
  $items = array(); foreach ( array_slice( $rows, 0, 500 ) as $row ) {
   if ( ! in_array( (int) $row['visitor_id'], $ids, true ) ) { continue; }
   $d = self::saved( $row ); if ( $d === null ) { continue; }
   $safe = array( 'type' => $row['type'], 'observed_at_utc' => gmdate( 'Y-m-d H:i:s', intdiv( (int) $row['occurred_ms'], 1000 ) ) . ' UTC', 'path' => $row['path'] ) + $d;
   $items[] = array( 'group_id' => 'peptide-core-product-actions', 'group_label' => __( 'Product action observations', 'peptide-core' ), 'item_id' => 'product-action-' . (int) $row['id'], 'data' => array( array( 'name' => __( 'Product action snapshot (JSON)', 'peptide-core' ), 'value' => wp_json_encode( $safe ) ) ) );
  }
  return array( 'data' => $items, 'done' => count( $rows ) <= 500 );
 }
 public static function policy_sentence(): string { return 'When supported storefront features report a successful favorite addition, completed share or explicit quick-view/buy-now operation, we keep its product, optional variation, operation kind, page path and time with the visit. These are partial client-reported observations, not verified business outcomes or current favorite balances. No share recipient or destination is collected. They use the same visit retention, opt-out, personal export and whole-event deletion rules.'; }
 public static function policy_text(): void { if ( function_exists( 'wp_add_privacy_policy_content' ) ) { wp_add_privacy_policy_content( __( 'Store visit analytics', 'peptide-core' ), '<p>' . esc_html__( self::policy_sentence(), 'peptide-core' ) . '</p>' ); } }

}
