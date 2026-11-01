<?php
/** Count genuine original-owner client search page views, not typed text or inferred orders. */
namespace PeptideCore\Data;
use PeptideCore\Tracking\Page_Context;
use PeptideCore\Tracking\Schema;
defined( 'ABSPATH' ) || exit;
final class Search_Observations {
	public static function period( int $from, int $to ): array {
		$out = array( 'rows' => array(), 'coverage' => array( 'unit' => 'search_page_views', 'base_count' => 0, 'known' => 0, 'empty' => 0, 'filtered' => 0, 'unobserved' => 0 ) );
		if ( ! Schema::installed() ) { return $out; }
		global $wpdb;
		$events = Schema::table( 'events' ); $visits = Schema::table( 'visits' ); $visitors = Schema::table( 'visitors' ); $cursor = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.id,e.event_uid,e.visitor_id,e.visit_id,e.type,e.source,e.is_test,e.occurred_ms,e.data,s.visitor_id AS owner,s.is_test AS visit_test,v.is_test AS visitor_test FROM $events e INNER JOIN $visits s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id INNER JOIN $visitors v ON v.id=e.visitor_id WHERE e.id>%d AND e.type='page_view' AND e.source='client' AND e.is_test=0 AND s.is_test=0 AND v.is_test=0 AND e.occurred_ms>=%d AND e.occurred_ms<%d ORDER BY e.id ASC LIMIT 500", $cursor, $from * 1000, $to * 1000 ), ARRAY_A );
			if ( $wpdb->last_error ) { throw new \RuntimeException( 'Search observations unavailable.' ); }
			foreach ( (array) $rows as $event ) {
				$cursor = (int) $event['id'];
				if ( (int) $event['visitor_id'] <= 0 || (int) $event['visit_id'] <= 0 || (int) $event['owner'] !== (int) $event['visitor_id'] || 'client' !== $event['source'] || 'page_view' !== $event['type'] || (int) $event['is_test'] || (int) $event['visit_test'] || (int) $event['visitor_test'] || (int) $event['occurred_ms'] < $from * 1000 || (int) $event['occurred_ms'] >= $to * 1000 ) { continue; }
				// Native unique(visitor_id,event_uid) means a replay is the same stored row.
				if ( ! is_string( $event['event_uid'] ?? null ) || 1 !== preg_match( '/\A[A-Za-z0-9-]{8,64}\z/D', $event['event_uid'] ) ) { continue; }
				$data = json_decode( (string) $event['data'], true ); $context = Page_Context::saved( is_array( $data ) ? ( $data['page_context'] ?? null ) : null );
				if ( null === $context || 'search' !== $context['kind'] ) { continue; }
				++$out['coverage']['base_count']; $search = $context['search'] ?? null;
				$status = $search['status'] ?? 'unobserved'; ++$out['coverage'][$status];
				if ( 'known' === $status ) { $term = $search['term']; $out['rows'][$term] = ( $out['rows'][$term] ?? 0 ) + 1; }
			}
		} while ( count( (array) $rows ) === 500 );
		return $out;
	}
}
