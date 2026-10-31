<?php
/** Anchored disjunctive facets over facts used by this Realtime page, never a site-wide DISTINCT. */
namespace PeptideCore\Data;

use PeptideCore\Api\Errors;
use PeptideCore\Overview;
use PeptideCore\Tracking\Ingest;
use PeptideCore\Tracking\Schema;

defined( 'ABSPATH' ) || exit;

final class Realtime_Filter_Options {

	public static function params( array $params ) {
		$context = Realtime_Dimensions::params( $params ); if ( is_wp_error( $context ) ) { return $context; }
		$main = Realtime::params( $params ); if ( is_wp_error( $main ) ) { return $main; }
		$errors = array(); $out = $context + $main;
		foreach ( array( 'entry', 'utm' ) as $key ) {
			$search = array_key_exists( $key . '_search', $params ) ? $params[ $key . '_search' ] : '';
			if ( ! Realtime_Filter::source( $search ) ) { $errors[ $key . '_search' ] = 'invalid_format'; } else { $out[ $key . '_search' ] = $search; }
			$page = Params::whole( $params, $key . '_page', 1, 1, 100000 );
			if ( null === $page || ( array_key_exists( $key . '_page', $params ) && ( null === $params[ $key . '_page' ] || '' === $params[ $key . '_page' ] ) ) ) { $errors[ $key . '_page' ] = 'invalid_format'; } else { $out[ $key . '_page' ] = $page; }
		}
		$per = Params::whole( $params, 'per_page', 100, 1, 100 );
		if ( null === $per || ( array_key_exists( 'per_page', $params ) && ( null === $params['per_page'] || '' === $params['per_page'] ) ) ) { $errors['per_page'] = 'invalid_format'; } else { $out['per_page'] = $per; }
		return $errors ? Errors::invalid( $errors ) : $out;
	}

	public static function summary( array $params, Realtime_Filter $filter ): array {
		global $wpdb;
		$history = 'history' === $params['mode']; $cutoff = $history ? $params['range']['to'] * 1000 : $params['anchor_ms'] + 1;
		$filter = $filter->at( $cutoff ); $filter->require_ready();
		$context = array( 'mode' => $params['mode'], 'anchor_ms' => $params['anchor_ms'], 'range' => $history ? array( 'from' => $params['range']['from'], 'to' => $params['range']['to'], 'dates' => array_intersect_key( $params['range']['dates'], array_flip( array( 'from', 'to' ) ) ), 'timezone' => wp_timezone_string() ) : null, 'timezone' => wp_timezone_string(), 'windows' => $params['windows'], 'funnel' => $params['funnel'], 'minutes' => $params['minutes'] );
		if ( $history ) {
			$from = $params['range']['from'] * 1000; $to = $params['range']['to'] * 1000;
			$visit_where = $wpdb->prepare( 'start_ms >= %d AND start_ms < %d', $from, $to );
			$event_where = $wpdb->prepare( "occurred_ms >= %d AND occurred_ms < %d AND (type='page_view' OR (type IN ('product_view','add_to_cart') AND product_id>0))", $from, $to );
			$order_from = $params['range']['from']; $order_to = $params['range']['to'];
		} else {
			$anchor = $params['anchor_ms']; $w = $params['windows']; $day = Overview::window( 'today', intdiv( $anchor, 1000 ) );
			$visit_window = max( 10, $params['funnel'], $w['map'], $w['active_countries'], $w['sources'] );
			$visit_where = $wpdb->prepare( '((last_ms >= %d AND last_ms < %d) OR (start_ms >= %d AND start_ms < %d))', $anchor - $visit_window * 60000 + 1, $anchor + 1, $day[0] * 1000, $day[1] * 1000 );
			$conditions = array();
			foreach ( array( 'page_view' => max( $w['popular_countries'], $w['collections'], $w['pages'], 10 ), 'product_view' => $w['viewed'], 'add_to_cart' => $w['carted'] ) as $type => $minutes ) {
				$conditions[] = $wpdb->prepare( "(type=%s" . ( 'page_view' === $type ? '' : ' AND product_id>0' ) . ' AND ((occurred_ms >= %d AND occurred_ms < %d) OR (occurred_ms >= %d AND occurred_ms < %d)))', $type, $anchor - $minutes * 60000 + 1, $anchor + 1, $day[0] * 1000, $day[1] * 1000 );
			}
			$activity = array_values( array_unique( array_merge( array_keys( Ingest::types() ), array( 'add_to_cart', 'order_placed', 'login', 'register' ) ) ) );
			$marks = implode( ',', array_fill( 0, count( $activity ), '%s' ) );
			$conditions[] = $wpdb->prepare( "(type IN ({$marks}) AND occurred_ms > %d AND occurred_ms <= %d)", array_merge( $activity, array( $anchor - $params['minutes'] * 60000, $anchor ) ) );
			$event_where = '(' . implode( ' OR ', $conditions ) . ')';
			$order_from = min( $day[0], intdiv( $anchor, 1000 ) - 599, (int) ceil( ( $anchor - $w['sold'] * 60000 + 1 ) / 1000 ) );
			$order_to = max( $day[1], intdiv( $anchor, 1000 ) + 1 );
		}
		$facets = array();
		foreach ( array( 'entry_domain', 'utm_source' ) as $key ) { $facets[ $key ] = array( 'coverage' => array( 'base_unit' => 'distinct_visits', 'total_visits' => 0, 'captured' => 0, 'v0' => 0, 'late_capture' => 0, 'unlinked_events' => 0, 'unlinked_orders' => 0 ), 'values' => array(), 'states' => array( 'unobserved' => 0, 'missing' => 0, 'empty' => 0 ) ); }
		$seen = array();
		$add_visit = static function( array $visit ) use ( &$seen, &$facets, $filter ): void {
			$id = (int) $visit['id']; if ( isset( $seen[ $id ] ) ) { return; } $seen[ $id ] = true; $fact = $filter->classify( $visit );
			foreach ( $facets as $key => &$facet ) {
				if ( ! $filter->matches( $fact, 'entry_domain' === $key ? 'utm_source' : 'entry_domain' ) ) { continue; }
				++$facet['coverage']['total_visits']; ++$facet['coverage'][ $fact['captured'] ? 'captured' : ( 'late_capture' === $fact['reason'] ? 'late_capture' : 'v0' ) ];
				$state = $fact[ $key . '_state' ];
				if ( 'value' === $state ) { $value = $fact[ $key ]; $facet['values'][ 'v:' . $value ] = ( $facet['values'][ 'v:' . $value ] ?? array( 'value' => $value, 'count' => 0 ) ); ++$facet['values'][ 'v:' . $value ]['count']; }
				else { ++$facet['states'][ $state ]; }
			} unset( $facet );
		};
		$add_unlinked = static function( string $kind ) use ( &$facets, $filter ): void {
			$fact = $filter->classify( null ); foreach ( $facets as $key => &$facet ) { if ( $filter->matches( $fact, 'entry_domain' === $key ? 'utm_source' : 'entry_domain' ) ) { ++$facet['coverage'][ $kind ]; } } unset( $facet );
		};
		$table = Schema::table( 'visits' ); $cursor = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,visitor_id,attribution_version,attribution_ms,attribution_host,utm_source FROM {$table} WHERE ({$visit_where}) AND id>%d ORDER BY id LIMIT 500", $cursor ), ARRAY_A ); self::check_db();
			foreach ( (array) $rows as $row ) { $cursor = (int) $row['id']; $add_visit( $row ); }
		} while ( count( (array) $rows ) === 500 );
		$table = Schema::table( 'events' ); $at = -1; $id = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,visit_id,visitor_id,occurred_ms FROM {$table} WHERE ({$event_where}) AND (occurred_ms>%d OR (occurred_ms=%d AND id>%d)) ORDER BY occurred_ms,id LIMIT 500", $at, $at, $id ), ARRAY_A ); self::check_db();
			$filter->prime_visits( array_column( (array) $rows, 'visit_id' ) );
			foreach ( (array) $rows as $row ) { $at = (int) $row['occurred_ms']; $id = (int) $row['id']; $visit = $filter->linked_visit( (int) $row['visit_id'], (int) $row['visitor_id'] ); if ( $visit ) { $add_visit( $visit ); } else { $add_unlinked( 'unlinked_events' ); } }
		} while ( count( (array) $rows ) === 500 );
		foreach ( $filter->orders( $order_from, $order_to, false ) as $order ) { $visit = $filter->order_visit( $order ); if ( $visit ) { $add_visit( $visit ); } else { $add_unlinked( 'unlinked_orders' ); } }
		$result = array( 'context' => $context, 'filter' => $filter->normalized(), 'facets' => array() );
		foreach ( $facets as $key => $facet ) { $prefix = 'entry_domain' === $key ? 'entry' : 'utm'; $result['facets'][ $key ] = self::page( $facet, $key, $params[ $prefix . '_search' ], $params[ $prefix . '_page' ], $params['per_page'], $filter->normalized() ); }
		return $result;
	}
	private static function check_db(): void { global $wpdb; if ( $wpdb->last_error ) { throw new \RuntimeException( 'Attribution candidates unavailable.' ); } }
	private static function page( array $facet, string $key, string $search, int $page, int $per, array $filter ): array {
		$values = array_values( $facet['values'] ); usort( $values, static fn( $a, $b ) => strcmp( $a['value'], $b['value'] ) );
		if ( '' !== $search ) { $values = array_values( array_filter( $values, static fn( $row ) => false !== strpos( $row['value'], $search ) ) ); }
		$total = count( $values ); $selected = array( 'state' => $filter[ $key . '_state' ] ); if ( 'value' === $selected['state'] ) { $selected['value'] = $filter[ $key ]; }
		$choices = array( array( 'state' => 'all', 'observed' => true, 'disabled' => false, 'count' => null ) );
		foreach ( 'utm_source' === $key ? array( 'unobserved', 'missing', 'empty' ) : array( 'unobserved' ) as $state ) {
			$count = $facet['states'][ $state ]; $observed = $count > 0 || ( 'unobserved' === $state && ( $facet['coverage']['unlinked_events'] || $facet['coverage']['unlinked_orders'] ) );
			$choices[] = array( 'state' => $state, 'observed' => $observed, 'disabled' => ! $observed && $selected['state'] !== $state, 'count' => $count );
		}
		$items = array_slice( $values, ( $page - 1 ) * $per, $per );
		if ( 'value' === $selected['state'] && ! in_array( $selected['value'], array_column( $items, 'value' ), true ) ) { $items = array_merge( array( $facet['values'][ 'v:' . $selected['value'] ] ?? array( 'value' => $selected['value'], 'count' => 0 ) ), $items ); }
		foreach ( $items as $row ) { $choices[] = array( 'state' => 'value', 'value' => $row['value'], 'observed' => $row['count'] > 0, 'disabled' => false, 'count' => $row['count'] ); }
		return array( 'selected' => $selected, 'choices' => $choices, 'search' => $search, 'page' => $page, 'per_page' => $per, 'total_values' => $total, 'has_more' => $page * $per < $total, 'coverage' => $facet['coverage'] );
	}
}
