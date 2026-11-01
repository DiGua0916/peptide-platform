<?php
/** Read-only, request-local entry/country facts. Cutoffs are not historical snapshots. */
namespace PeptideCore\Data;

use PeptideCore\Tracking\Attribution;
use PeptideCore\Tracking\Page_Context;
use PeptideCore\Tracking\Schema;

defined( 'ABSPATH' ) || exit;

final class Report_Dimensions {

	public static function positive_id( $value ): ?int {
		if ( is_int( $value ) ) { return $value > 0 ? $value : null; }
		if ( ! is_string( $value ) || ! preg_match( '/\A[0-9]+\z/', $value ) ) { return null; }
		$digits = ltrim( $value, '0' ); $max = (string) PHP_INT_MAX;
		return '' !== $digits && ( strlen( $digits ) < strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) <= 0 ) ) ? (int) $digits : null;
	}

	public static function unknown(): array {
		$out = array( 'visitor_id' => 0 );
		foreach ( array( 'entry_domain', 'utm_source', 'utm_medium', 'country', 'operating_system', 'screen_size' ) as $key ) { $out[ $key ] = array( 'state' => 'unobserved', 'value' => null ); }
		return $out;
	}

	/** The state participates in identity: missing, empty and literal labels never collide. */
	public static function key( array $typed ): string { return serialize( array( $typed['state'], $typed['value'] ) ); }

	public static function for_visit( array $facts, int $visit_id, int $visitor_id ): array {
		return $visit_id > 0 && $visitor_id > 0 && isset( $facts[ $visit_id ] ) && (int) $facts[ $visit_id ]['visitor_id'] === $visitor_id ? $facts[ $visit_id ] : self::unknown();
	}

	/** IDs are chunked; observations are keyset-scanned, never truncated to the first batch. */
	public static function visit_facts( array $visit_ids, int $cutoff_ms ): array {
		global $wpdb;
		if ( $cutoff_ms <= 0 || ! Schema::installed() ) { throw new \RuntimeException( 'Report dimensions unavailable.' ); }
		$ids = array(); foreach ( $visit_ids as $id ) { $id = self::positive_id( $id ); if ( $id ) { $ids[ $id ] = $id; } }
		$out = array(); $visits = Schema::table( 'visits' ); $events = Schema::table( 'events' );
		foreach ( array_chunk( array_values( $ids ), 500 ) as $chunk ) {
			$marks = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, visitor_id, ' . implode( ', ', Attribution::COLUMNS ) . " FROM {$visits} WHERE id IN ({$marks})", $chunk ), ARRAY_A ); self::check_db();
			$owners = array();
			foreach ( (array) $rows as $row ) {
				$id = (int) $row['id']; $fact = self::unknown(); $fact['visitor_id'] = (int) $row['visitor_id'];
				if ( $fact['visitor_id'] > 0 && 1 === (int) $row['attribution_version'] && (int) $row['attribution_ms'] < $cutoff_ms ) {
					$fact['entry_domain'] = array( 'state' => 'value', 'value' => (string) $row['attribution_host'] );
					foreach ( array( 'utm_source', 'utm_medium' ) as $key ) { $value = $row[ $key ]; $fact[ $key ] = array( 'state' => null === $value ? 'missing' : ( '' === $value ? 'empty' : 'value' ), 'value' => $value ); }
				}
				$out[ $id ] = $fact; $owners[ $id ] = $fact['visitor_id'];
			}
			if ( ! $owners ) { continue; }
			$in = implode( ',', array_keys( $owners ) ); $at = $cutoff_ms; $cursor = PHP_INT_MAX; $seen = array();
			do {
				$observations = $wpdb->get_results( $wpdb->prepare( "SELECT id, visit_id, visitor_id, source, occurred_ms, data FROM {$events} WHERE visit_id IN ({$in}) AND type = 'page_view' AND occurred_ms < %d AND (occurred_ms < %d OR (occurred_ms = %d AND id < %d)) ORDER BY occurred_ms DESC, id DESC LIMIT 500", $cutoff_ms, $at, $at, $cursor ), ARRAY_A ); self::check_db();
				foreach ( (array) $observations as $event ) {
					$id = (int) $event['visit_id']; if ( ! isset( $owners[ $id ] ) || $owners[ $id ] <= 0 || $owners[ $id ] !== (int) $event['visitor_id'] ) { continue; }
					$data = json_decode( (string) $event['data'], true ); $context = Page_Context::saved( is_array( $data ) ? ( $data['page_context'] ?? null ) : null );
					if ( ! isset( $seen[$id]['country'] ) && isset( $context['geo'] ) ) {
						$geo = $context['geo']; $out[$id]['country'] = array( 'state' => 'known' === $geo['status'] ? 'value' : $geo['status'], 'value' => $geo['country_code'] ?? null ); $seen[$id]['country'] = true;
					}
					if ( 'client' !== ( $event['source'] ?? null ) || ! isset( $context['environment'] ) ) { continue; }
					foreach ( array( 'os' => 'operating_system', 'screen' => 'screen_size' ) as $field => $dimension ) {
						if ( isset( $seen[$id][$dimension] ) || ! isset( $context['environment'][$field] ) ) { continue; }
						$fact = $context['environment'][$field];
						$value = 'known' !== $fact['status'] ? null : ( 'os' === $field ? $fact['family'] : $fact['width'] . 'x' . $fact['height'] );
						$out[$id][$dimension] = array( 'state' => 'known' === $fact['status'] ? 'value' : $fact['status'], 'value' => $value ); $seen[$id][$dimension] = true;
					}
				}
				if ( $observations ) { $last = end( $observations ); $at = (int) $last['occurred_ms']; $cursor = (int) $last['id']; }
			} while ( count( (array) $observations ) === 500 );
		}
		return $out;
	}

	public static function check_db(): void { global $wpdb; if ( $wpdb->last_error ) { throw new \RuntimeException( 'Report dimension query failed.' ); } }
}
