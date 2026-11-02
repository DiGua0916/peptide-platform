<?php
/** Read-only product interest, counted from accepted live product_view events. */
namespace PeptideCore\Tracking;

use PeptideCore\Data\Range;
use PeptideCore\Api\Errors;

defined( 'ABSPATH' ) || exit;

final class Product_Interest {

	public const SORTS = array( 'views', 'viewers', 'repeat_viewers', 'returning_viewers', 'last_view_ms' );

	/** Store-calendar dates and bounded, scalar report parameters. No writes. */
	public static function params( array $input ) {
		foreach ( array( 'from', 'to', 'device', 'q', 'sort', 'page', 'per_page', 'product_id' ) as $key ) {
			if ( isset( $input[ $key ] ) && ! is_scalar( $input[ $key ] ) ) { return Errors::invalid( array( $key => 'invalid_format' ) ); }
		}
		$dates = array_intersect_key( $input, array_flip( array( 'from', 'to' ) ) );
		if ( empty( $dates['from'] ) && empty( $dates['to'] ) ) { $dates = array( 'range' => '7d' ); }
		$range = Range::from_params( $dates );
		if ( is_wp_error( $range ) ) { return $range; }
		$device = (string) ( $input['device'] ?? '' );
		if ( ! in_array( $device, array( '', 'desktop', 'mobile', 'tablet' ), true ) ) { return Errors::invalid( array( 'device' => 'invalid_format' ) ); }
		$sort = (string) ( $input['sort'] ?? 'views' );
		if ( ! in_array( $sort, self::SORTS, true ) ) { return Errors::invalid( array( 'sort' => 'invalid_format' ) ); }
		$q = trim( (string) ( $input['q'] ?? '' ) );
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $q ) : strlen( $q ) ) > 100 || preg_match( '/[\x00-\x1f\x7f]/', $q ) ) { return Errors::invalid( array( 'q' => 'invalid_format' ) ); }
		$numbers = array();
		foreach ( array( 'page' => 1, 'per_page' => 20, 'product_id' => 0 ) as $key => $default ) {
			$value = $input[ $key ] ?? $default;
			$text = (string) $value;
			if ( ! preg_match( '/\A(?:0|[1-9][0-9]{0,15})\z/', $text ) || ( 'product_id' !== $key && (int) $value < 1 ) || ( 'per_page' === $key && (int) $value > 100 ) || ( 'page' === $key && (int) $value > 1000000 ) ) { return Errors::invalid( array( $key => 'invalid_format' ) ); }
			$numbers[ $key ] = (int) $value;
		}
		return array_merge( $numbers, array( 'range' => $range, 'device' => $device, 'q' => $q, 'sort' => $sort ) );
	}

	/** No new table or event collection: aggregate the existing immutable event UIDs. */
	public static function report( array $input ) {
		$args = self::params( $input );
		if ( is_wp_error( $args ) ) { return $args; }
		$range = $args['range'];
		$out = array(
			'from' => $range['dates']['from'], 'to' => $range['dates']['to'], 'timezone' => wp_timezone()->getName(),
			'tracking' => Schema::installed(), 'collection_enabled' => Tracker::enabled(),
			'device' => $args['device'], 'q' => $args['q'], 'sort' => $args['sort'],
			'page' => $args['page'], 'per_page' => $args['per_page'], 'total' => 0,
			'summary' => array( 'products' => 0, 'views' => 0, 'viewers' => 0 ), 'rows' => array(),
		);
		if ( $args['product_id'] ) { $out['product'] = self::product( $args['product_id'] ); }
		if ( ! $out['tracking'] ) { return $out; }
		global $wpdb;
		$pairs = self::pairs_sql( $args );
		$summary = $wpdb->get_row( "SELECT COUNT(DISTINCT b.product_id) AS products, COALESCE(SUM(b.views),0) AS views, COUNT(DISTINCT b.visitor_id) AS viewers FROM ({$pairs}) b", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->last_error ) { return self::unavailable(); }
		foreach ( array( 'products', 'views', 'viewers' ) as $key ) { $out['summary'][ $key ] = (int) ( $summary[ $key ] ?? 0 ); }
		$limit = $args['per_page']; $offset = ( $args['page'] - 1 ) * $limit;
		if ( $args['product_id'] ) {
			$out['total'] = $out['summary']['viewers'];
			$rows = $wpdb->get_results( "SELECT b.* FROM ({$pairs}) b ORDER BY b.last_view_ms DESC, b.visitor_id ASC LIMIT {$limit} OFFSET {$offset}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $wpdb->last_error ) { return self::unavailable(); }
			$people = Schema::table( 'visitors' ); $events = Schema::table( 'events' );
			foreach ( $rows as $row ) {
				$who = $wpdb->get_row( $wpdb->prepare( "SELECT user_id FROM {$people} WHERE id=%d", $row['visitor_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $wpdb->last_error ) { return self::unavailable(); }
				$user = ! empty( $who['user_id'] ) ? get_userdata( (int) $who['user_id'] ) : false;
				$last_id = (int) substr( $row['last_key'], 20 );
				$visit_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT visit_id FROM {$events} WHERE id=%d AND visitor_id=%d", $last_id, $row['visitor_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $wpdb->last_error ) { return self::unavailable(); }
				$id = (int) $row['visitor_id'];
				$out['rows'][] = array(
					'visitor_id' => $id, 'label' => $user ? (string) $user->display_name : sprintf( __( '访客 #%d', 'peptide-core' ), $id ),
					'account_type' => $user ? 'registered' : 'guest', 'views' => (int) $row['views'], 'visits' => (int) $row['visits'],
					'last_view_ms' => (int) $row['last_view_ms'], 'visit_id' => $visit_id,
					'visitor_url' => add_query_arg( array( 'page' => 'pc-visitors', 'visitor' => $id, 'visit' => $visit_id ), admin_url( 'admin.php' ) ),
				);
			}
		} else {
			$out['total'] = $out['summary']['products'];
			$sort = $args['sort']; // Fixed allowlist in params(), never a raw SQL identifier.
			$rows = $wpdb->get_results( "SELECT b.product_id, SUM(b.views) AS views, COUNT(*) AS viewers, SUM(b.views>=2) AS repeat_viewers, SUM(b.visits>=2) AS returning_viewers, MAX(b.last_view_ms) AS last_view_ms FROM ({$pairs}) b GROUP BY b.product_id ORDER BY {$sort} DESC, b.product_id ASC LIMIT {$limit} OFFSET {$offset}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $wpdb->last_error ) { return self::unavailable(); }
			foreach ( $rows as $row ) {
				$item = self::product( (int) $row['product_id'] );
				foreach ( self::SORTS as $key ) { $item[ $key ] = (int) $row[ $key ]; }
				$out['rows'][] = $item;
			}
		}
		return $out;
	}

	/** One row per parent product and anonymous visitor; timestamps follow event time. */
	private static function pairs_sql( array $args ): string {
		global $wpdb;
		$events = Schema::table( 'events' ); $visits = Schema::table( 'visits' ); $visitors = Schema::table( 'visitors' );
		$parent = "CASE WHEN ep.post_type='product_variation' AND ep.post_parent>0 THEN ep.post_parent ELSE e.product_id END";
		$where = $wpdb->prepare( "e.type='product_view' AND e.source='client' AND e.product_id>0 AND e.is_test=0 AND v.is_test=0 AND s.is_test=0 AND e.occurred_ms >= %d AND e.occurred_ms < %d", $args['range']['from'] * 1000, $args['range']['to'] * 1000 );
		if ( $args['device'] ) { $where .= $wpdb->prepare( ' AND s.device=%s', $args['device'] ); }
		if ( $args['product_id'] ) { $where .= $wpdb->prepare( " AND ({$parent})=%d", $args['product_id'] ); }
		if ( '' !== $args['q'] && ! $args['product_id'] ) {
			$like = '%' . $wpdb->esc_like( $args['q'] ) . '%';
			$where .= $wpdb->prepare( " AND (COALESCE(pp.post_title,CONCAT('#',{$parent})) LIKE %s OR CAST(({$parent}) AS CHAR)=%s)", $like, $args['q'] );
		}
		return "SELECT {$parent} AS product_id, e.visitor_id, COUNT(*) AS views, COUNT(DISTINCT e.visit_id) AS visits, MAX(e.occurred_ms) AS last_view_ms, MAX(CONCAT(LPAD(e.occurred_ms,20,'0'),LPAD(e.id,20,'0'))) AS last_key FROM {$events} e INNER JOIN {$visits} s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id INNER JOIN {$visitors} v ON v.id=e.visitor_id LEFT JOIN {$wpdb->posts} ep ON ep.ID=e.product_id LEFT JOIN {$wpdb->posts} pp ON pp.ID=({$parent}) WHERE {$where} GROUP BY {$parent},e.visitor_id";
	}

	private static function product( int $id ): array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
		$valid = $product instanceof \WC_Product;
		return array(
			'product_id' => $id,
			'name' => $valid ? html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ) : sprintf( __( '已删除或不可用商品 #%d', 'peptide-core' ), $id ),
			'image_url' => $valid && $product->get_image_id() ? (string) wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
			'product_url' => $valid && 'publish' === $product->get_status() ? (string) $product->get_permalink() : '',
		);
	}

	private static function unavailable(): \WP_Error {
		return new \WP_Error( 'pc_product_interest_unavailable', __( '暂时无法读取商品浏览统计，请重试。', 'peptide-core' ), array( 'status' => 503 ) );
	}
}
