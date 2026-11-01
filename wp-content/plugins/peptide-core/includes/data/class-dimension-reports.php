<?php
/** Typed dimensions shared by the new reports; legacy report calculations stay separate. */
namespace PeptideCore\Data;

use PeptideCore\Tracking\Schema;

defined( 'ABSPATH' ) || exit;

final class Dimension_Reports {

	public const GROUPS = array( 'utm' => 'utm_source', 'domain' => 'entry_domain', 'country' => 'country', 'os' => 'operating_system', 'screen' => 'screen_size', 'channel' => 'channel' );
	public const TABLES = array( 'visitor_source', 'order_source', 'visitor_domain', 'order_domain', 'visitor_country' );

	/** Visits, not page rows; saved facts are read in bounded batches at the same cutoff. */
	public static function cohort( int $from, int $to ): array {
		global $wpdb;
		$out = array(); $cursor = 0;
		if ( ! Schema::installed() ) { return $out; }
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,visitor_id FROM ' . Schema::table( 'visits' ) . ' WHERE start_ms >= %d AND start_ms < %d AND id > %d ORDER BY id LIMIT 500', $from * 1000, $to * 1000, $cursor ), ARRAY_A ); Report_Dimensions::check_db();
			$facts = Report_Dimensions::visit_facts( array_column( (array) $rows, 'id' ), $to * 1000 );
			foreach ( (array) $rows as $row ) { $cursor = (int) $row['id']; $out[$cursor] = Report_Dimensions::for_visit( $facts, $cursor, (int) $row['visitor_id'] ); }
		} while ( count( (array) $rows ) === 500 );
		return $out;
	}

	public static function label( array $typed, string $dimension ): string {
		if ( 'channel' === $dimension ) { return Report_Dimensions::channel_label( $typed ); }
		if ( 'value' === $typed['state'] ) {
			$value = (string) $typed['value'];
			if ( 'country' === $dimension && function_exists( 'WC' ) && WC() && WC()->countries ) { $countries = WC()->countries->get_countries(); return html_entity_decode( $countries[$value] ?? $value, ENT_QUOTES, 'UTF-8' ); }
			if ( 'screen_size' === $dimension && preg_match( '/\A([0-9]+)x([0-9]+)\z/D', $value, $matches ) ) { return $matches[1] . ' × ' . $matches[2] . ' ' . __( 'CSS pixels', 'peptide-core' ); }
			return $value;
		}
		if ( in_array( $dimension, array( 'operating_system', 'screen_size' ), true ) ) {
			$labels = 'operating_system' === $dimension ? array( 'unknown' => __( 'Operating system unknown', 'peptide-core' ), 'unavailable' => __( 'Operating system source unavailable', 'peptide-core' ) ) : array( 'unknown' => __( 'Screen size unknown', 'peptide-core' ), 'unavailable' => __( 'Screen size source unavailable', 'peptide-core' ) );
			return $labels[$typed['state']] ?? __( 'Not observed', 'peptide-core' );
		}
		$labels = array( 'unobserved' => __( 'Not observed', 'peptide-core' ), 'missing' => __( 'UTM source missing', 'peptide-core' ), 'empty' => __( 'UTM source empty', 'peptide-core' ), 'unknown' => __( 'Country unknown', 'peptide-core' ), 'unavailable' => __( 'Country source unavailable', 'peptide-core' ) );
		return $labels[$typed['state']] ?? __( 'Not observed', 'peptide-core' );
	}

	public static function coverage( string $unit ): array { return array( 'unit' => $unit, 'base_count' => 0, 'value' => 0, 'missing' => 0, 'empty' => 0, 'unknown' => 0, 'unavailable' => 0, 'unobserved' => 0 ); }

	/** A map entry is owner-bound; a visitor can intentionally belong to several typed groups. */
	public static function mapping( array $cohort, string $dimension ): array {
		$map = array(); $typed = array(); $coverage = self::coverage( 'visits' );
		foreach ( $cohort as $visit => $fact ) {
			$value = $fact[$dimension]; $key = Report_Dimensions::key( $value ); $typed[$key] = $value;
			$map[$visit] = array( 'visitor_id' => (int) $fact['visitor_id'], 'key' => $key );
			++$coverage['base_count']; ++$coverage[$value['state']];
		}
		return array( 'map' => $map, 'typed' => $typed, 'coverage' => $coverage );
	}

	/** Orders have already passed Rankings' unchanged native paid/test eligibility. */
	public static function rankings( int $from, int $to, array $orders, array &$cache ): array {
		$out = array_fill_keys( self::TABLES, array() ); $cohort = self::cohort( $from, $to );
		foreach ( array( 'visitor_source' => 'utm_source', 'visitor_domain' => 'entry_domain', 'visitor_country' => 'country' ) as $table => $dimension ) {
			$mapping = self::mapping( $cohort, $dimension ); $sets = array();
			foreach ( $mapping['map'] as $item ) { if ( $item['visitor_id'] > 0 ) { $sets[$item['key']][$item['visitor_id']] = true; } }
			foreach ( $sets as $key => $set ) { $out[$table][$key] = count( $set ); }
			foreach ( $mapping['typed'] as $key => $typed ) { $cache['dimension_labels'][$table][$key] = self::label( $typed, $dimension ); }
			$cache['dimension_coverage'][$table][] = $mapping['coverage'];
		}
		$facts = Schema::installed() ? Report_Dimensions::visit_facts( array_column( $orders, 'visit' ), $to * 1000 ) : array();
		foreach ( array( 'order_source' => 'utm_source', 'order_domain' => 'entry_domain' ) as $table => $dimension ) {
			$coverage = self::coverage( 'orders' );
			foreach ( $orders as $order ) {
				$fact = Report_Dimensions::for_visit( $facts, $order['visit'], $order['visitor'] ); $typed = $fact[$dimension]; $key = Report_Dimensions::key( $typed );
				$out[$table][$key] = ( $out[$table][$key] ?? 0 ) + 1; $cache['dimension_labels'][$table][$key] = self::label( $typed, $dimension ); ++$coverage['base_count']; ++$coverage[$typed['state']];
			}
			$cache['dimension_coverage'][$table][] = $coverage;
		}
		return $out;
	}
}
