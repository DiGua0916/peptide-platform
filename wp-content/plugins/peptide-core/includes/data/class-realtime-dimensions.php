<?php
/** Additive realtime panels. Saved observations never imply an IP-derived location. */
namespace PeptideCore\Data;

use PeptideCore\Api\Errors;
use PeptideCore\Tracking\Attribution;
use PeptideCore\Tracking\Page_Context;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class Realtime_Dimensions {
	public const PANELS = array( 'viewed', 'carted', 'sold', 'map', 'active_countries', 'popular_countries', 'sources', 'collections', 'pages' );
	private const BATCH = 500;

	/** Strict mode-specific parameters; presence differs deliberately from a missing window. */
	public static function params( array $params ) {
		$errors = array();
		$mode = Params::word( $params, 'mode', 'live', array( 'live', 'history' ) );
		if ( null === $mode ) { $errors['mode'] = 'invalid_format'; }
		if ( array_key_exists( 'format', $params ) && ( ! is_string( $params['format'] ) || 'json' !== $params['format'] ) ) { $errors['format'] = 'invalid_format'; }
		$windows = array(); $anchor = null; $range = null;
		if ( 'history' === $mode ) {
			foreach ( array_merge( self::PANELS, array( 'anchor_ms', 'range', 'preset', 'compare_from', 'compare_to', 'compare' ) ) as $key ) { if ( array_key_exists( $key, $params ) ) { $errors[ $key ] = 'invalid_format'; } }
			if ( ! array_key_exists( 'from', $params ) || ! array_key_exists( 'to', $params ) ) { $errors['from'] = 'required'; $errors['to'] = 'required'; }
			if ( ! $errors ) { $range = Realtime::history_params( $params ); if ( is_wp_error( $range ) ) { return $range; } }
		} elseif ( 'live' === $mode ) {
			foreach ( array( 'from', 'to', 'range', 'preset', 'compare_from', 'compare_to', 'compare' ) as $key ) { if ( array_key_exists( $key, $params ) ) { $errors[ $key ] = 'invalid_format'; } }
			$raw = $params['anchor_ms'] ?? null;
			if ( ( ! is_int( $raw ) && ! is_string( $raw ) ) || ! preg_match( '/\A[0-9]{1,16}\z/', (string) $raw ) || (int) $raw <= 0 || (int) $raw > Tracker::now_ms() ) { $errors['anchor_ms'] = 'invalid_format'; }
			else { $anchor = (int) $raw; }
			foreach ( self::PANELS as $key ) {
				$raw = $params[ $key ] ?? null;
				if ( ! array_key_exists( $key, $params ) ) { $windows[ $key ] = 10; }
				elseif ( ( is_int( $raw ) || is_string( $raw ) ) && in_array( (string) $raw, array( '10', '20', '30' ), true ) ) { $windows[ $key ] = (int) $raw; }
				else { $errors[ $key ] = 'invalid_format'; }
			}
		}
		return $errors ? Errors::invalid( $errors ) : array( 'mode' => $mode, 'anchor_ms' => $anchor, 'range' => $range, 'windows' => $windows );
	}

	/** One request, bounded raw batches, distinct aggregates only. Anchor is a cutoff, not MVCC. */
	public static function summary( array $validated ): array {
		global $wpdb;
		$history = 'history' === $validated['mode'];
		$out = array( 'mode' => $validated['mode'], 'anchor_ms' => $validated['anchor_ms'], 'range' => $history ? array( 'from' => $validated['range']['from'], 'to' => $validated['range']['to'], 'dates' => array_intersect_key( $validated['range']['dates'], array_flip( array( 'from', 'to' ) ) ), 'timezone' => wp_timezone_string() ) : null, 'timezone' => wp_timezone_string(), 'panels' => array() );
		foreach ( self::PANELS as $key ) {
			$window = $history ? null : $validated['windows'][ $key ];
			$from = $history ? $validated['range']['from'] * 1000 : $validated['anchor_ms'] - $window * 60000 + 1;
			$to = $history ? $validated['range']['to'] * 1000 : $validated['anchor_ms'] + 1;
			$unit = in_array( $key, array( 'viewed', 'carted' ), true ) ? 'events' : ( 'sold' === $key ? 'units' : ( in_array( $key, array( 'pages', 'popular_countries', 'collections' ), true ) ? 'page_views' : 'visitors' ) );
			$coverage = in_array( $key, array( 'viewed', 'carted', 'sold', 'pages' ), true ) ? null : array( 'base_unit' => in_array( $key, array( 'popular_countries', 'collections' ), true ) ? 'page_views' : 'visits', 'base_count' => null, 'known' => null, 'unknown' => null, 'unobserved' => null, 'unavailable' => null );
			$out['panels'][ $key ] = array( 'from_ms' => $from, 'to_ms' => $to, 'window' => $window, 'unit' => $unit, 'state' => 'unavailable', 'reason' => 'dependency_unavailable', 'coverage' => $coverage, 'rows' => null, 'top_n' => 'map' === $key ? null : 5, 'total_rows' => null );
		}
		foreach ( array( 'viewed', 'carted', 'sold', 'pages' ) as $key ) {
			try {
				if ( 'sold' === $key && ! function_exists( 'wc_get_orders' ) ) { continue; }
				$p = &$out['panels'][ $key ]; $list = Realtime::ranged_list( $key, $p['from_ms'], $p['to_ms'] );
				if ( $wpdb->last_error ) { throw new \RuntimeException( 'List dependency failed.' ); }
				$p['rows'] = $list['rows']; $p['total_rows'] = $list['total_rows']; $p['state'] = $list['total_rows'] ? 'available' : 'empty'; $p['reason'] = null; unset( $p );
			} catch ( \Throwable $e ) { unset( $p ); }
		}
		if ( ! Schema::installed() ) { return $out; }
		// Equal cohort windows share a single visit scan and latest-geo scan.
		$cohorts = array();
		foreach ( array( 'map', 'active_countries', 'sources' ) as $key ) { $p = $out['panels'][ $key ]; $cohorts[ $p['from_ms'] . ':' . $p['to_ms'] ][] = $key; }
		foreach ( $cohorts as $keys ) {
			$p = $out['panels'][ $keys[0] ];
			try {
				$aggregates = self::visits( $p['from_ms'], $p['to_ms'], $history, in_array( 'sources', $keys, true ), count( array_diff( $keys, array( 'sources' ) ) ) > 0 );
				foreach ( $keys as $key ) { $out['panels'][ $key ] = self::finish( $out['panels'][ $key ], $aggregates[ 'sources' === $key ? 'sources' : 'geo' ], 'map' === $key ); }
			} catch ( \Throwable $e ) { /* Independent sections retain truthful unavailable/null. */ }
		}
		try {
			$pv = self::page_views( $out['panels']['popular_countries'], $out['panels']['collections'] );
			foreach ( array( 'popular_countries', 'collections' ) as $key ) { $out['panels'][ $key ] = self::finish( $out['panels'][ $key ], $pv[ $key ] ); }
		} catch ( \Throwable $e ) { /* Other panels remain usable. */ }
		return $out;
	}

	private static function aggregate( string $unit ): array {
		return array( 'coverage' => array( 'base_unit' => $unit, 'base_count' => 0, 'known' => 0, 'unknown' => 0, 'unobserved' => 0, 'unavailable' => 0 ), 'rows' => array(), 'visitors' => array() );
	}
	private static function observed( array &$a, string $status, int $visitor = 0 ): void {
		++$a['coverage']['base_count']; ++$a['coverage'][ $status ];
		if ( $visitor ) { $a['visitors'][ $visitor ] = true; }
	}
	private static function check_db(): void { global $wpdb; if ( $wpdb->last_error ) { throw new \RuntimeException( 'Observation query failed.' ); } }

	/** Visit-start history / last-activity live; latest geo is unbounded below within each visit. */
	private static function visits( int $from, int $to, bool $history, bool $sources, bool $geo ): array {
		global $wpdb;
		$out = array( 'geo' => self::aggregate( 'visits' ), 'sources' => self::aggregate( 'visits' ) );
		$out['sources']['coverage'] += array( 'valued' => 0, 'captured_missing' => 0, 'captured_empty' => 0 );
		$column = $history ? 'start_ms' : 'last_ms'; $cursor = 0; $visits = Schema::table( 'visits' ); $events = Schema::table( 'events' );
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, visitor_id, ' . implode( ', ', Attribution::COLUMNS ) . " FROM {$visits} WHERE {$column} >= %d AND {$column} < %d AND id > %d ORDER BY id ASC LIMIT %d", $from, $to, $cursor, self::BATCH ), ARRAY_A ); self::check_db();
			$owners = array(); foreach ( (array) $rows as $row ) { $owners[ (int) $row['id'] ] = (int) $row['visitor_id']; }
			$latest = array();
			if ( $geo && $owners ) {
				$in = implode( ',', array_keys( $owners ) ); $at = $to; $id = PHP_INT_MAX;
				do {
					$observations = $wpdb->get_results( $wpdb->prepare( "SELECT id, visit_id, visitor_id, occurred_ms, data FROM {$events} WHERE visit_id IN ({$in}) AND type = 'page_view' AND occurred_ms < %d AND (occurred_ms < %d OR (occurred_ms = %d AND id < %d)) ORDER BY occurred_ms DESC, id DESC LIMIT %d", $to, $at, $at, $id, self::BATCH ), ARRAY_A ); self::check_db();
					foreach ( (array) $observations as $event ) {
						$visit = (int) $event['visit_id']; if ( isset( $latest[ $visit ] ) || $owners[ $visit ] !== (int) $event['visitor_id'] ) { continue; }
						$data = json_decode( (string) $event['data'], true ); $context = Page_Context::saved( is_array( $data ) ? ( $data['page_context'] ?? null ) : null );
						if ( isset( $context['geo'] ) ) { $latest[ $visit ] = $context['geo']; }
					}
					if ( $observations ) { $last = end( $observations ); $at = (int) $last['occurred_ms']; $id = (int) $last['id']; }
				} while ( count( (array) $observations ) === self::BATCH );
			}
			foreach ( (array) $rows as $row ) {
				$cursor = (int) $row['id']; $visitor = (int) $row['visitor_id'];
				if ( $geo ) { $g = $latest[ $cursor ] ?? array( 'status' => 'unobserved' ); self::observed( $out['geo'], $g['status'], $visitor ); if ( 'known' === $g['status'] ) { $code = $g['country_code']; $out['geo']['rows'][ $code ]['visitors'][ $visitor ] = true; } }
				if ( $sources ) {
					$snapshot = Attribution::snapshot( $row ); $captured = 'captured' === $snapshot['status'] && (int) $row['attribution_ms'] < $to;
					self::observed( $out['sources'], $captured ? 'known' : 'unobserved', $visitor );
					if ( $captured ) {
						$value = $snapshot['utm_source']; $kind = null === $value ? 'captured_missing' : ( '' === $value ? 'captured_empty' : 'valued' ); ++$out['sources']['coverage'][ $kind ];
						$key = $kind . ':' . (string) $value; $out['sources']['rows'][ $key ] = ( $out['sources']['rows'][ $key ] ?? array( 'kind' => $kind, 'value' => $value, 'visitors' => array() ) ); $out['sources']['rows'][ $key ]['visitors'][ $visitor ] = true;
					}
				}
			}
		} while ( count( (array) $rows ) === self::BATCH );
		foreach ( $out as &$a ) { $a['coverage']['base_visitors'] = count( $a['visitors'] ); foreach ( $a['rows'] as &$r ) { $r['count'] = count( $r['visitors'] ); unset( $r['visitors'] ); } unset( $r ); } unset( $a );
		return $out;
	}

	/** One keyset scan over the union of the two PV windows; no raw JSON retained across batches. */
	private static function page_views( array $popular, array $collections ): array {
		global $wpdb;
		$out = array( 'popular_countries' => self::aggregate( 'page_views' ), 'collections' => self::aggregate( 'page_views' ) );
		$out['collections']['coverage'] += array( 'collection' => 0, 'known_non_collection' => 0 );
		$from = min( $popular['from_ms'], $collections['from_ms'] ); $to = max( $popular['to_ms'], $collections['to_ms'] ); $at = $from; $id = 0; $table = Schema::table( 'events' );
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, occurred_ms, data FROM {$table} WHERE type = 'page_view' AND occurred_ms >= %d AND occurred_ms < %d AND (occurred_ms > %d OR (occurred_ms = %d AND id > %d)) ORDER BY occurred_ms ASC, id ASC LIMIT %d", $from, $to, $at, $at, $id, self::BATCH ), ARRAY_A ); self::check_db();
			foreach ( (array) $rows as $row ) {
				$at = (int) $row['occurred_ms']; $id = (int) $row['id']; $data = json_decode( (string) $row['data'], true ); $context = Page_Context::saved( is_array( $data ) ? ( $data['page_context'] ?? null ) : null );
				if ( $at >= $popular['from_ms'] && $at < $popular['to_ms'] ) { $g = $context['geo'] ?? array( 'status' => 'unobserved' ); self::observed( $out['popular_countries'], $g['status'] ); if ( 'known' === $g['status'] ) { $key = $g['country_code']; $out['popular_countries']['rows'][ $key ]['count'] = ( $out['popular_countries']['rows'][ $key ]['count'] ?? 0 ) + 1; } }
				if ( $at >= $collections['from_ms'] && $at < $collections['to_ms'] ) {
					$status = null === $context ? 'unobserved' : ( 'unknown' === $context['kind'] ? 'unknown' : 'known' ); self::observed( $out['collections'], $status );
					if ( 'known' === $status ) { $kind = 'collection' === $context['kind'] ? 'collection' : 'known_non_collection'; ++$out['collections']['coverage'][ $kind ]; if ( 'collection' === $kind ) { $key = $context['term_id']; $out['collections']['rows'][ $key ]['count'] = ( $out['collections']['rows'][ $key ]['count'] ?? 0 ) + 1; } }
				}
			}
		} while ( count( (array) $rows ) === self::BATCH );
		return $out;
	}

	/** Stable public rows contain aggregates only; no visitor/event identities escape. */
	private static function finish( array $panel, array $a, bool $all = false ): array {
		$coverage = $a['coverage']; $rows = array();
		$countries = function_exists( 'WC' ) && WC() && WC()->countries ? WC()->countries->get_countries() : array();
		$is_source = isset( $coverage['captured_missing'] ); $is_collection = isset( $coverage['known_non_collection'] );
		$terms = array();
		if ( $is_collection && $a['rows'] ) {
			foreach ( array_chunk( array_map( 'intval', array_keys( $a['rows'] ) ), self::BATCH ) as $ids ) {
				$found = get_terms( array( 'taxonomy' => 'product_cat', 'include' => $ids, 'hide_empty' => false ) );
				if ( is_wp_error( $found ) ) { throw new \RuntimeException( 'Collection labels unavailable.' ); }
				foreach ( $found as $term ) { $terms[ $term->term_id ] = $term; }
			}
		}
		foreach ( $a['rows'] as $key => $row ) {
			if ( $is_source ) { $rows[] = array( 'key' => $key, 'kind' => $row['kind'], 'value' => $row['value'], 'count' => $row['count'] ); }
			elseif ( $is_collection ) { $rows[] = array( 'id' => (int) $key, 'label' => isset( $terms[ $key ] ) ? $terms[ $key ]->name : '#' . $key, 'count' => $row['count'] ); }
			else { $rows[] = array( 'code' => (string) $key, 'label' => html_entity_decode( (string) ( $countries[ $key ] ?? $key ), ENT_QUOTES, 'UTF-8' ), 'count' => $row['count'] ); }
		}
		usort( $rows, static function ( $a, $b ) use ( $is_source, $is_collection ): int { $cmp = $b['count'] <=> $a['count']; if ( $cmp ) { return $cmp; } if ( $is_collection ) { return $a['id'] <=> $b['id']; } if ( $is_source ) { $order = array( 'captured_missing' => 0, 'captured_empty' => 1, 'valued' => 2 ); $cmp = $order[ $a['kind'] ] <=> $order[ $b['kind'] ]; return $cmp ?: strcmp( (string) $a['value'], (string) $b['value'] ); } return strcmp( $a['code'], $b['code'] ); } );
		$total = count( $rows );
		if ( 'page_views' === $panel['unit'] ) { foreach ( $rows as &$row ) { $row['share'] = $coverage['base_count'] ? round( $row['count'] / $coverage['base_count'] * 100, 2 ) : null; } unset( $row ); }
		$panel['coverage'] = $coverage; $panel['rows'] = $all ? $rows : array_slice( $rows, 0, 5 ); $panel['total_rows'] = $total; $panel['reason'] = null;
		if ( 0 === $coverage['base_count'] || ( $is_collection && $coverage['known_non_collection'] === $coverage['base_count'] ) ) { $panel['state'] = 'empty'; }
		elseif ( $coverage['unobserved'] === $coverage['base_count'] ) { $panel['state'] = 'unavailable'; $panel['reason'] = 'not_collected'; }
		elseif ( $coverage['unavailable'] === $coverage['base_count'] ) { $panel['state'] = 'unavailable'; $panel['reason'] = 'source_unavailable'; }
		elseif ( ! $coverage['known'] && ! $coverage['unknown'] ) { $panel['state'] = 'unavailable'; $panel['reason'] = 'not_collected_and_source_unavailable'; }
		else { $panel['state'] = $coverage['unobserved'] || $coverage['unavailable'] ? 'partial' : 'available'; }
		return $panel;
	}
}
