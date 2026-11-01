<?php
/** Count only canonical original-owner received success operations; absence is not zero. */
namespace PeptideCore\Data;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Product_Actions as Source;
defined( 'ABSPATH' ) || exit;
final class Product_Actions {
 public const METRICS = array( 'favorite_count', 'share_count', 'share_users', 'intent_count', 'intent_users' );
 public static function empty_metrics(): array { return array_fill_keys( self::METRICS, null ); }
 public static function coverage( int $count, bool $available = true ): array { return array( 'availability' => ! $available ? 'unavailable' : ( $count ? 'observed' : 'not_observed' ), 'coverage' => 'partial', 'full_coverage' => false, 'received_operations' => $count, 'scope' => 'parent_product' ); }
 public static function period( int $from, int $to, ?Report_Filter $filter = null ): array {
  $out = array( 'products' => array(), 'coverage' => self::coverage( 0, Schema::installed() ), 'product_coverage' => array(), 'kind_coverage' => array_fill_keys( array( 'favorite', 'share', 'intent' ), self::coverage( 0, Schema::installed() ) ) );
  if ( ! Schema::installed() ) { return $out; }
  global $wpdb; $e = Schema::table( 'events' ); $s = Schema::table( 'visits' ); $v = Schema::table( 'visitors' ); $cursor = 0; $raw = array(); $total = 0;
  do {
   $rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.*,v.id AS matched_visitor_id,s.id AS matched_visit_id,s.visitor_id AS owner,s.is_test AS visit_test,v.is_test AS visitor_test FROM {$e} e INNER JOIN {$s} s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id INNER JOIN {$v} v ON v.id=e.visitor_id WHERE e.id>%d AND e.type IN ('product_favorite','product_share','product_intent') AND e.source='client' AND e.is_test=0 AND s.is_test=0 AND v.is_test=0 AND e.occurred_ms>=%d AND e.occurred_ms<%d ORDER BY e.id ASC LIMIT 500", $cursor, $from * 1000, $to * 1000 ), ARRAY_A );
   if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new \RuntimeException( 'Product action observations unavailable.' ); }
   $facts = $filter ? Report_Dimensions::visit_facts( array_column( $rows, 'visit_id' ), $to * 1000 ) : array();
   foreach ( $rows as $row ) {
    $cursor = (int) $row['id'];
    if ( (int) ( $row['is_test'] ?? 1 ) || (int) ( $row['visit_test'] ?? 1 ) || (int) ( $row['visitor_test'] ?? 1 ) || (int) ( $row['owner'] ?? 0 ) !== (int) ( $row['visitor_id'] ?? 0 ) || (int) $row['occurred_ms'] < $from * 1000 || (int) $row['occurred_ms'] >= $to * 1000 ) { continue; }
    $data = Source::saved( $row ); if ( $data === null || ( $filter && ! $filter->matches( Report_Dimensions::for_visit( $facts, (int) $row['visit_id'], (int) $row['visitor_id'] ) ) ) ) { continue; }
    $pid = $data['product_id']; $kind = substr( $row['type'], 8 );
    if ( ! isset( $raw[$pid][$kind] ) ) { $raw[$pid][$kind] = array( 'count' => 0, 'users' => array() ); }
    ++$raw[$pid][$kind]['count']; $raw[$pid][$kind]['users'][(int) $row['visitor_id']] = true; ++$total;
   }
  } while ( count( $rows ) === 500 );
  foreach ( $raw as $pid => $kinds ) {
   $metrics = self::empty_metrics(); $coverage = array();
   foreach ( array( 'favorite', 'share', 'intent' ) as $kind ) {
    $count = $kinds[$kind]['count'] ?? 0; $coverage[$kind] = self::coverage( $count );
    if ( $count ) { $metrics[$kind . '_count'] = $count; if ( $kind !== 'favorite' ) { $metrics[$kind . '_users'] = count( $kinds[$kind]['users'] ); } }
   }
   $out['products'][$pid] = $metrics; $out['product_coverage'][$pid] = $coverage;
  }
  $out['coverage'] = self::coverage( $total );
  foreach ( array( 'favorite', 'share', 'intent' ) as $kind ) { $count = 0; foreach ( $raw as $kinds ) { $count += $kinds[$kind]['count'] ?? 0; } $out['kind_coverage'][$kind] = self::coverage( $count ); }
  return $out;
 }
}
