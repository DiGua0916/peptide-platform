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
		$out['group_by'] = array_key_exists( 'group_by', $params ) ? $params['group_by'] : 'utm';
		if ( ! is_string( $out['group_by'] ) || ! in_array( $out['group_by'], array( 'utm', 'channel' ), true ) ) { $errors['group_by'] = 'invalid_format'; }
		if ( 'channel' === $out['group_by'] && 1 === $out['split_medium'] ) { $errors['split_medium'] = 'incompatible_group'; }
		$out['q'] = isset( $params['q'] ) && is_scalar( $params['q'] ) ? mb_substr( trim( (string) $params['q'] ), 0, 100 ) : '';
		return $errors ? Errors::invalid( $errors ) : $out;
	}
	public static function table( array $range, array $args ): array {
		$coverage = null; $rows = self::selection( $range, $args, $coverage ); $total = count( $rows );
		$out = array( 'range' => $range, 'tracking' => Schema::installed(), 'sort' => $args['sort'], 'order' => $args['order'], 'page' => $args['page'], 'per_page' => $args['per_page'], 'split_medium' => $args['split_medium'], 'q' => $args['q'], 'total' => $total, 'rows' => array_slice( $rows, ( $args['page'] - 1 ) * $args['per_page'], $args['per_page'] ) );
		if ( 'channel' === ( $args['group_by'] ?? 'utm' ) ) { $out['group_by'] = 'channel'; $out['channel_policy'] = Report_Dimensions::CHANNEL_POLICY; $out['coverage'] = $coverage; }
		return $out;
	}
	public static function all_rows( array $range, array $args, ?array &$coverage = null ): iterable { foreach ( self::selection( $range, $args, $coverage ) as $row ) { yield $row; } }
	private static function identity( array $fact, bool $split, string $group_by = 'utm' ): array {
		if ( 'channel' === $group_by ) { return array( 'channel' => $fact['channel'] ); }
		$out = array( 'utm_source' => $fact['utm_source'] ); if ( $split ) { $out['utm_medium'] = $fact['utm_medium']; } return $out;
	}
	private static function selection( array $range, array $args, ?array &$coverage = null ): array {
		global $wpdb; $groups = array(); $split = 1 === $args['split_medium']; $cutoff = (int) $range['to'] * 1000; $group_by = $args['group_by'] ?? 'utm';
		$coverage = 'channel' === $group_by ? array( 'visits' => array( 'unit' => 'visits', 'base_count' => 0, 'value' => 0, 'unknown' => 0, 'unobserved' => 0 ), 'orders' => array( 'unit' => 'orders', 'base_count' => 0, 'value' => 0, 'unknown' => 0, 'unobserved' => 0 ) ) : null;
		$group = static function ( array $fact ) use ( &$groups, $split, $group_by ): string { $identity = self::identity( $fact, $split, $group_by ); $key = serialize( $identity ); if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = Visit_Metrics::blank() + $identity + array( '_channel_visits' => 0 ); } return $key; };
		if ( Schema::installed() ) {
			$visits = Schema::table( 'visits' ); $visitors = Schema::table( 'visitors' ); $cursor = 0;
			do {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.id,s.visitor_id,s.user_id,s.stage FROM {$visits} s INNER JOIN {$visitors} v ON v.id=s.visitor_id WHERE s.start_ms >= %d AND s.start_ms < %d AND s.id > %d AND s.is_test=0 AND v.is_test=0 ORDER BY s.id LIMIT 500", (int) $range['from'] * 1000, $cutoff, $cursor ), ARRAY_A ); Report_Dimensions::check_db();
				$facts = Report_Dimensions::visit_facts( array_column( (array) $rows, 'id' ), $cutoff );
				foreach ( (array) $rows as $row ) { $cursor = (int) $row['id']; $key = $group( Report_Dimensions::for_visit( $facts, $cursor, (int) $row['visitor_id'] ) ); Visit_Metrics::visit( $groups[ $key ], $row ); ++$groups[$key]['_channel_visits']; }
			} while ( count( (array) $rows ) === 500 );
		}
		$orders = array(); $ids = array();
		foreach ( Operations::paid_orders( (int) $range['from'], (int) $range['to'] ) as $order ) {
			if ( self::is_test( $order ) ) { continue; }
			$o = Visit_Metrics::order_fact( $order );
			$o['visit'] = Report_Dimensions::positive_id( $order->get_meta( \PeptideCore\Tracking\Server_Events::ORDER_VISIT_META ) ) ?? 0;
			$o['visitor'] = Report_Dimensions::positive_id( $order->get_meta( \PeptideCore\Tracking\Server_Events::ORDER_VISITOR_META ) ) ?? 0;
			$orders[] = $o; if ( $o['visit'] && $o['visitor'] ) { $ids[] = $o['visit']; }
		}
		$facts = Schema::installed() ? Report_Dimensions::visit_facts( $ids, $cutoff ) : array();
		foreach ( $orders as $o ) { $fact = Report_Dimensions::for_visit( $facts, $o['visit'], $o['visitor'] ); $key = $group( $fact ); Visit_Metrics::order( $groups[ $key ], $o ); }
		$rows = array();
		foreach ( $groups as $key => $g ) {
			$row = self::identity( $g, $split, $group_by ) + Visit_Metrics::finish( $g ); if ( ! $row['uv'] && ! $row['orders'] ) { continue; }
			if ( 'channel' === $group_by ) { $row['channel_label'] = Report_Dimensions::channel_label( $row['channel'] ); $text = $row['channel_label'] . ' ' . (string) $row['channel']['value']; }
			else { $text = (string) $row['utm_source']['value'] . ( $split ? ' ' . (string) $row['utm_medium']['value'] : '' ); }
			if ( '' !== $args['q'] && false === mb_stripos( $text, $args['q'] ) ) { continue; }
			if ( 'channel' === $group_by ) { foreach ( array( 'visits' => $g['_channel_visits'], 'orders' => $g['orders'] ) as $unit => $count ) { $coverage[$unit]['base_count'] += $count; $coverage[$unit][$row['channel']['state']] += $count; } }
			$row['_key'] = $key; $rows[] = $row;
		}
		usort( $rows, static function ( array $a, array $b ) use ( $args ): int { $av = $a[ $args['sort'] ] ?? -1; $bv = $b[ $args['sort'] ] ?? -1; $cmp = 'asc' === $args['order'] ? $av <=> $bv : $bv <=> $av; return $cmp ?: strcmp( $a['_key'], $b['_key'] ); } );
		foreach ( $rows as &$row ) { unset( $row['_key'] ); } unset( $row ); return $rows;
	}

	/** M6（NF-02）：测试单规则按开关走 Metrics_Overlay::is_test_order()（关着时就是 Overview 的旧规则）；不带自动加载的纯逻辑测试里没有这个类，直接用旧规则。 */
	private static function is_test( $order ): bool {
		return class_exists( Metrics_Overlay::class ) ? Metrics_Overlay::is_test_order( $order ) : \PeptideCore\Overview::is_test_order( $order );
	}
}
