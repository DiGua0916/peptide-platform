<?php
/** Received landing-exposure cohorts. No assignment, hidden reference formula or significance inference. */
namespace PeptideCore\Data;
use PeptideCore\Tracking\Experiment_Observations as Observation;
use PeptideCore\Tracking\Impressions;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;
use PeptideCore\Overview;
use PeptideCore\Api\Errors;
defined( 'ABSPATH' ) || exit;

final class Experiments {
	public const METRICS = array( 'exposures', 'exposed_visitors', 'eligible_visitors', 'contaminated_visits', 'viewed_visitors', 'carted_visitors', 'checkout_visitors', 'paid_visitors', 'orders', 'conversion', 'revenue' );
	public static function params( array $raw ) {
		$out = array(); $errors = array();
		$out['sort'] = Params::word( $raw, 'sort', 'exposures', array_merge( self::METRICS, array( 'experiment_id', 'landing_path', 'variant_id' ) ) ); $out['order'] = Params::word( $raw, 'order', 'desc', array( 'asc', 'desc' ) );
		$out['page'] = Params::whole( $raw, 'page', 1, 1, 999999999 ); $out['per_page'] = Params::whole( $raw, 'per_page', 20, 1, 100 );
		foreach ( $out as $k => $v ) { if ( null === $v ) { $errors[$k] = 'invalid_format'; } }
		if ( array_key_exists( 'q', $raw ) && ( ! is_string( $raw['q'] ) || strlen( $raw['q'] ) > 400 || ! preg_match( '//u', $raw['q'] ) ) ) { $errors['q'] = 'invalid_format'; }
		$out['q'] = isset( $raw['q'] ) && is_string( $raw['q'] ) ? mb_substr( trim( $raw['q'] ), 0, 100 ) : '';
		$filter = Report_Filter::params( $raw ); if ( is_wp_error( $filter ) ) { return $filter; }
		$out['filter'] = $filter ?? array( 'entry_domain_state' => 'all', 'country_state' => 'all' );
		return $errors ? Errors::invalid( $errors ) : $out;
	}
	/** Every raw row advances the cursor, including malformed source payloads. */
	private static function exposures( string $where, array $values ): iterable {
		global $wpdb; $cursor = 0;
		do {
			$sql = Observation::joined() . " WHERE e.type='experiment_exposure' AND e.source='server' AND e.id>%d {$where} ORDER BY e.id LIMIT 500";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $cursor ), $values ) ), ARRAY_A ); Observation::check_db(); if ( ! is_array( $rows ) ) { throw new \RuntimeException( 'Experiment reader unavailable' ); }
			foreach ( $rows as $row ) { $id = Impressions::column_id( $row['id'] ?? null ); if ( ! $id || $id <= $cursor ) { throw new \RuntimeException( 'Invalid event cursor' ); } $cursor = $id; $c = Observation::saved( $row ); if ( $c ) { yield $c; } }
		} while ( count( $rows ) === 500 );
	}
	private static function selection( array $range, array $args ): array {
		$groups = array(); $members = array(); $candidate = 0; $matched = 0; $from = (int) $range['from'] * 1000; $to = (int) $range['to'] * 1000;
		if ( ! Schema::installed() ) { return array( 'status' => 'unavailable', 'rows' => array(), 'candidate_exposures' => null, 'matched_exposures' => null, 'contaminated_experiment_visits' => null ); }
		$filter = Report_Filter::active( $args, $to ); $batch = array();
		$consume = static function( array $batch ) use ( &$groups, &$members, &$candidate, &$matched, $filter, $to ): void {
			$facts = $filter ? Report_Dimensions::visit_facts( array_column( $batch, 'visit_id' ), $to ) : array();
			foreach ( $batch as $c ) {
				++$candidate; if ( $filter && ! $filter->matches( Report_Dimensions::for_visit( $facts, $c['visit_id'], $c['visitor_id'] ) ) ) { continue; } ++$matched;
				$d = $c['data']; $key = serialize( array( $d['experiment_id'], $d['landing_path'], $d['variant_id'] ) );
				if ( ! isset( $groups[$key] ) ) { $groups[$key] = array( 'experiment_id' => $d['experiment_id'], 'landing_path' => $d['landing_path'], 'variant_id' => $d['variant_id'], 'exposures' => 0, 'raw' => array(), 'eligible' => array(), 'contaminated' => array(), 'viewed' => array(), 'carted' => array(), 'checkout' => array(), 'paid' => array(), 'orders' => array(), 'revenue' => 0.0 ); }
				++$groups[$key]['exposures']; $groups[$key]['raw'][$c['visitor_id']] = true;
				$experiment = $d['experiment_id']; $visit = $c['visit_id'];
				if ( ! isset( $members[$visit][$experiment] ) ) { $members[$visit][$experiment] = array( 'visitor' => $c['visitor_id'], 'first' => $c['occurred_ms'], 'keys' => array() ); }
				$members[$visit][$experiment]['first'] = min( $members[$visit][$experiment]['first'], $c['occurred_ms'] ); $members[$visit][$experiment]['keys'][$key] = true;
			}
		};
		foreach ( self::exposures( 'AND e.occurred_ms>=%d AND e.occurred_ms<%d', array( $from, $to ) ) as $c ) { $batch[] = $c; if ( count( $batch ) === 500 ) { $consume( $batch ); $batch = array(); } } if ( $batch ) { $consume( $batch ); }
		// Conflict detection includes earlier observations of these visits, independent of search/path/group.
		$conflicts = array();
		foreach ( array_chunk( array_keys( $members ), 500 ) as $ids ) {
			foreach ( self::exposures( 'AND e.occurred_ms<%d AND e.visit_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')', array( $to ) ) as $c ) {
				$experiment = $c['data']['experiment_id']; $visit = $c['visit_id']; if ( ! isset( $members[$visit][$experiment] ) ) { continue; }
				$conflicts[$visit][$experiment][serialize( array( $c['data']['variant_id'], $c['data']['landing_path'] ) )] = true;
			}
		}
		$eligible = array(); $contaminated = 0;
		foreach ( $members as $visit => $experiments ) { foreach ( $experiments as $experiment => $member ) {
			if ( count( $conflicts[$visit][$experiment] ?? array() ) > 1 ) { ++$contaminated; foreach ( $member['keys'] as $key => $_ ) { $groups[$key]['contaminated'][$visit] = true; } continue; }
			foreach ( $member['keys'] as $key => $_ ) { $groups[$key]['eligible'][$member['visitor']] = true; $eligible[$visit][$experiment] = $member + array( 'key' => $key ); }
		} }
		global $wpdb; $e = Schema::table( 'events' ); $s = Schema::table( 'visits' ); $v = Schema::table( 'visitors' );
		foreach ( array_chunk( array_keys( $eligible ), 500 ) as $ids ) {
			$cursor = 0; do {
				$sql = "SELECT e.* FROM {$e} e INNER JOIN {$s} s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id INNER JOIN {$v} v ON v.id=e.visitor_id WHERE e.id>%d AND e.occurred_ms>=%d AND e.occurred_ms<%d AND e.visit_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ") AND e.is_test=0 AND s.is_test=0 AND v.is_test=0 AND ((e.source='client' AND e.type IN ('page_view','product_view')) OR (e.source='server' AND e.type IN ('add_to_cart','order_placed'))) ORDER BY e.id LIMIT 500";
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, $cursor, $from, $to ), ARRAY_A ); Observation::check_db(); if ( ! is_array( $rows ) ) { throw new \RuntimeException( 'Experiment outcomes unavailable' ); }
				foreach ( $rows as $event ) { $id = Impressions::column_id( $event['id'] ?? null ); if ( ! $id || $id <= $cursor ) { throw new \RuntimeException( 'Invalid outcome cursor' ); } $cursor = $id;
					foreach ( $eligible[(int) $event['visit_id']] ?? array() as $member ) { if ( (int) $event['visitor_id'] !== $member['visitor'] || (int) $event['occurred_ms'] < $member['first'] ) { continue; } $key = $member['key']; $visitor = $member['visitor'];
						if ( 'client' === $event['source'] && in_array( $event['type'], array( 'page_view', 'product_view' ), true ) ) { $groups[$key]['viewed'][$visitor] = true; }
						if ( 'server' === $event['source'] && 'add_to_cart' === $event['type'] ) { $groups[$key]['carted'][$visitor] = true; }
						if ( ( 'client' === $event['source'] && 'page_view' === $event['type'] && 'checkout' === $event['page_type'] ) || ( 'server' === $event['source'] && 'order_placed' === $event['type'] ) ) { $groups[$key]['checkout'][$visitor] = true; }
					}
				}
			} while ( count( $rows ) === 500 );
		}
		$paid_orders = Operations::paid_orders( (int) $range['from'], (int) $range['to'] ); Observation::check_db(); $orders_seen = array();
		foreach ( $paid_orders as $order ) {
			if ( ! $order instanceof \WC_Order || self::is_test( $order ) || ! $order->is_paid() ) { continue; }
			$visit = Impressions::column_id( $order->get_meta( Server_Events::ORDER_VISIT_META ) ); $visitor = Impressions::column_id( $order->get_meta( Server_Events::ORDER_VISITOR_META ) ); $ts = self::order_ts( $order ); if ( ! $visit || ! $visitor || null === $ts ) { continue; } $at = $ts * 1000;
			foreach ( $eligible[$visit] ?? array() as $experiment => $member ) { if ( $visitor !== $member['visitor'] || $at < $member['first'] || $at >= $to || $at < $from ) { continue; } $key = $member['key']; $id = Impressions::column_id( $order->get_id() ); if ( ! $id || isset( $orders_seen[$experiment][$id] ) ) { continue; } $orders_seen[$experiment][$id] = true; $groups[$key]['orders'][$id] = true; $groups[$key]['paid'][$visitor] = true; $groups[$key]['revenue'] += self::sales( $order ); }
		}
		$out = array(); foreach ( $groups as $key => $g ) {
			if ( '' !== $args['q'] && false === mb_stripos( $g['experiment_id'] . ' ' . $g['landing_path'], $args['q'] ) ) { continue; }
			$denom = count( $g['eligible'] ); $out[] = array( '_key' => $key, 'experiment_id' => $g['experiment_id'], 'landing_path' => $g['landing_path'], 'variant_id' => $g['variant_id'], 'exposures' => $g['exposures'], 'exposed_visitors' => count( $g['raw'] ), 'eligible_visitors' => $denom, 'contaminated_visits' => count( $g['contaminated'] ), 'viewed_visitors' => count( $g['viewed'] ), 'carted_visitors' => count( $g['carted'] ), 'checkout_visitors' => count( $g['checkout'] ), 'paid_visitors' => count( $g['paid'] ), 'orders' => count( $g['orders'] ), 'conversion' => $denom ? round( count( $g['paid'] ) / $denom * 100, 2 ) : null, 'revenue' => round( $g['revenue'], 2 ) );
		}
		usort( $out, static function( $a, $b ) use ( $args ) { $x = $a[$args['sort']]; $y = $b[$args['sort']]; $cmp = null === $x || null === $y ? (int) ( null === $x ) <=> (int) ( null === $y ) : ( 'asc' === $args['order'] ? $x <=> $y : $y <=> $x ); return $cmp ?: strcmp( $a['_key'], $b['_key'] ); } ); foreach ( $out as &$row ) { unset( $row['_key'] ); } unset( $row );
		return array( 'status' => $candidate ? 'received' : 'not_observed', 'rows' => $out, 'candidate_exposures' => $candidate, 'matched_exposures' => $matched, 'contaminated_experiment_visits' => $contaminated );
	}
	public static function table( array $range, array $args ): array {
		$selection = self::selection( $range, $args ); $rows = $selection['rows']; unset( $selection['rows'] );
		return $selection + array( 'range' => array( 'from' => $range['from'], 'to' => $range['to'], 'dates' => array_intersect_key( $range['dates'], array_flip( array( 'from', 'to' ) ) ) ), 'sort' => $args['sort'], 'order' => $args['order'], 'q' => $args['q'], 'page' => $args['page'], 'per_page' => $args['per_page'], 'filter' => $args['filter'], 'total' => count( $rows ), 'rows' => array_slice( $rows, ( $args['page'] - 1 ) * $args['per_page'], $args['per_page'] ), 'full_coverage' => false, 'cohort' => 'reported_exposure_time', 'conversion_unit' => 'percent_of_eligible_observed_visitors', 'currency' => get_woocommerce_currency() );
	}
	public static function csv( array $range, array $args ): iterable {
		$selection = self::selection( $range, $args );
		if ( 'unavailable' === $selection['status'] ) { throw new \RuntimeException( 'Experiment storage unavailable' ); }
		yield array_merge( array( 'experiment_id', 'landing_path', 'variant_id' ), self::METRICS, array( 'conversion_unit', 'revenue_currency', 'from_epoch_s', 'to_epoch_s', 'coverage', 'status' ) );
		foreach ( $selection['rows'] as $row ) { $cells = array( Csv_Response::text( $row['experiment_id'] ), Csv_Response::text( $row['landing_path'] ), Csv_Response::text( $row['variant_id'] ) ); foreach ( self::METRICS as $key ) { $cells[] = null === $row[$key] ? '' : ( in_array( $key, array( 'conversion', 'revenue' ), true ) ? number_format( $row[$key], 2, '.', '' ) : (string) $row[$key] ); } yield array_merge( $cells, array( 'percent_of_eligible_observed_visitors', Csv_Response::text( get_woocommerce_currency() ), (string) $range['from'], (string) $range['to'], 'partial_received_observations', $selection['status'] ) ); }
	}
	public static function response( \WP_REST_Request $request ) {
		$raw = $request->get_params(); $range = Range::from_params( $raw ); if ( is_wp_error( $range ) ) { return $range; } $args = self::params( $raw ); if ( is_wp_error( $args ) ) { return $args; }
		$format = array_key_exists( 'format', $raw ) ? $raw['format'] : 'json'; if ( ! is_string( $format ) || ! in_array( $format, array( 'json', 'csv' ), true ) ) { return Errors::invalid( array( 'format' => 'invalid_format' ) ); }
		if ( 'csv' === $format ) { foreach ( array( '_envelope', '_jsonp', '_fields' ) as $key ) { if ( array_key_exists( $key, $raw ) ) { return Errors::invalid( array( $key => 'invalid_format' ) ); } } }
		try { if ( 'csv' === $format ) { return new Csv_Response( iterator_to_array( self::csv( $range, $args ) ), 'data-experiments.csv' ); } $response = new \WP_REST_Response( self::table( $range, $args ) ); $response->header( 'Cache-Control', 'no-store' ); return $response; }
		catch ( \Throwable $error ) { return new \WP_Error( 'pc_experiments_unavailable', __( 'Experiment observations are unavailable.', 'peptide-core' ), array( 'status' => 503 ) ); }
	}

	/** M6（NF-02）：测试单规则按开关走 Metrics_Overlay::is_test_order()（关着时就是 Overview 的旧规则）；不带自动加载的纯逻辑测试里没有这个类，直接用旧规则。 */
	private static function is_test( $order ): bool {
		return class_exists( Metrics_Overlay::class ) ? Metrics_Overlay::is_test_order( $order ) : \PeptideCore\Overview::is_test_order( $order );
	}

	/** M6（NF-02）：一张订单的销售额按开关走 Metrics_Overlay::order_sales()（关着时 = 订单金额 − WooCommerce 退款，和以前一样）。 */
	private static function sales( $order ): float {
		return class_exists( Metrics_Overlay::class ) ? Metrics_Overlay::order_sales( $order ) : (float) $order->get_total() - (float) $order->get_total_refunded();
	}

	/** M6（NF-02）：一张订单算在哪个时刻（Unix 秒）按开关走 Metrics_Overlay::order_time()（关着时 = 创建时间，和以前一样）；没有时返回 null。 */
	private static function order_ts( $order ): ?int {
		if ( class_exists( Metrics_Overlay::class ) ) {
			return Metrics_Overlay::order_time( $order );
		}
		$created = $order->get_date_created();
		return $created ? (int) $created->getTimestamp() : null;
	}
}
