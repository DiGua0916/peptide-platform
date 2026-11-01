<?php
/** Source/medium report. Visit-start denominators and original-visit native orders. */
namespace PeptideCore\Data;
use PeptideCore\Api\Errors;
use PeptideCore\Overview;
use PeptideCore\Tracking\Schema;
defined( 'ABSPATH' ) || exit;

final class Sources {
	public const METRICS = Landing::METRICS;
	public static function params( array $params ) {
		$out = array(); $errors = array();
		foreach ( array( 'sort' => array( 'uv', self::METRICS ), 'order' => array( 'desc', array( 'asc', 'desc' ) ) ) as $key => $rule ) { $out[ $key ] = Params::word( $params, $key, $rule[0], $rule[1] ); if ( null === $out[ $key ] ) { $errors[ $key ] = 'invalid_format'; } }
		foreach ( array( 'page' => array( 1, 999999999 ), 'per_page' => array( 20, 100 ), 'split_medium' => array( 0, 1 ) ) as $key => $rule ) { $out[ $key ] = Params::whole( $params, $key, $rule[0], 'split_medium' === $key ? 0 : 1, $rule[1] ); if ( null === $out[ $key ] ) { $errors[ $key ] = 'invalid_format'; } }
		$out['q'] = isset( $params['q'] ) && is_scalar( $params['q'] ) ? mb_substr( trim( (string) $params['q'] ), 0, 100 ) : '';
		return $errors ? Errors::invalid( $errors ) : $out;
	}
	public static function table( array $range, array $args ): array {
		$rows = self::selection( $range, $args ); $total = count( $rows );
		return array( 'range' => $range, 'tracking' => Schema::installed(), 'sort' => $args['sort'], 'order' => $args['order'], 'page' => $args['page'], 'per_page' => $args['per_page'], 'split_medium' => $args['split_medium'], 'q' => $args['q'], 'total' => $total, 'rows' => array_slice( $rows, ( $args['page'] - 1 ) * $args['per_page'], $args['per_page'] ) );
	}
	public static function all_rows( array $range, array $args ): iterable { foreach ( self::selection( $range, $args ) as $row ) { yield $row; } }
	private static function identity( array $fact, bool $split ): array {
		$out = array( 'utm_source' => $fact['utm_source'] ); if ( $split ) { $out['utm_medium'] = $fact['utm_medium']; } return $out;
	}
	private static function selection( array $range, array $args ): array {
		global $wpdb; $groups = array(); $split = 1 === $args['split_medium']; $cutoff = (int) $range['to'] * 1000;
		$group = static function ( array $fact ) use ( &$groups, $split ): string { $identity = self::identity( $fact, $split ); $key = serialize( $identity ); if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = Visit_Metrics::blank() + $identity; } return $key; };
		if ( Schema::installed() ) {
			$visits = Schema::table( 'visits' ); $visitors = Schema::table( 'visitors' ); $cursor = 0;
			do {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.id,s.visitor_id,s.user_id,s.stage FROM {$visits} s INNER JOIN {$visitors} v ON v.id=s.visitor_id WHERE s.start_ms >= %d AND s.start_ms < %d AND s.id > %d AND s.is_test=0 AND v.is_test=0 ORDER BY s.id LIMIT 500", (int) $range['from'] * 1000, $cutoff, $cursor ), ARRAY_A ); Report_Dimensions::check_db();
				$facts = Report_Dimensions::visit_facts( array_column( (array) $rows, 'id' ), $cutoff );
				foreach ( (array) $rows as $row ) { $cursor = (int) $row['id']; $key = $group( Report_Dimensions::for_visit( $facts, $cursor, (int) $row['visitor_id'] ) ); Visit_Metrics::visit( $groups[ $key ], $row ); }
			} while ( count( (array) $rows ) === 500 );
		}
		$orders = array(); $ids = array();
		foreach ( Operations::paid_orders( (int) $range['from'], (int) $range['to'] ) as $order ) {
			if ( Overview::is_test_order( $order ) ) { continue; }
			$o = Visit_Metrics::order_fact( $order );
			$o['visit'] = Report_Dimensions::positive_id( $order->get_meta( \PeptideCore\Tracking\Server_Events::ORDER_VISIT_META ) ) ?? 0;
			$o['visitor'] = Report_Dimensions::positive_id( $order->get_meta( \PeptideCore\Tracking\Server_Events::ORDER_VISITOR_META ) ) ?? 0;
			$orders[] = $o; if ( $o['visit'] && $o['visitor'] ) { $ids[] = $o['visit']; }
		}
		$facts = Schema::installed() ? Report_Dimensions::visit_facts( $ids, $cutoff ) : array();
		foreach ( $orders as $o ) { $fact = Report_Dimensions::for_visit( $facts, $o['visit'], $o['visitor'] ); $key = $group( $fact ); Visit_Metrics::order( $groups[ $key ], $o ); }
		$rows = array();
		foreach ( $groups as $key => $g ) {
			$row = self::identity( $g, $split ) + Visit_Metrics::finish( $g ); if ( ! $row['uv'] && ! $row['orders'] ) { continue; }
			$text = (string) $row['utm_source']['value'] . ( $split ? ' ' . (string) $row['utm_medium']['value'] : '' );
			if ( '' !== $args['q'] && false === mb_stripos( $text, $args['q'] ) ) { continue; }
			$row['_key'] = $key; $rows[] = $row;
		}
		usort( $rows, static function ( array $a, array $b ) use ( $args ): int { $av = $a[ $args['sort'] ] ?? -1; $bv = $b[ $args['sort'] ] ?? -1; $cmp = 'asc' === $args['order'] ? $av <=> $bv : $bv <=> $av; return $cmp ?: strcmp( $a['_key'], $b['_key'] ); } );
		foreach ( $rows as &$row ) { unset( $row['_key'] ); } unset( $row ); return $rows;
	}
}
