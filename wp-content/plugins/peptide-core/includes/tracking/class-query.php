<?php
/**
 * 后台读取（VISITOR-ANALYTICS-TEST-01）：实时访客、单访客的访问历史与时间线、转化统计。只给管理员 / 店长用。
 *
 * 在线口径（服务器时间）：
 * - active：最后信号 ≤ 45 秒，页面可见且没有空闲；
 * - idle：最后信号 ≤ 45 秒，但页面在后台（hidden）或 60 秒没有操作；
 * - offline：超过 45 秒没有信号，或最后一个事件是离开页面且之后 10 秒没有新信号。
 * 访客的状态取各标签页里最“在线”的一个。前端在页面隐藏时不发心跳，所以后台标签页会在 45 秒后显示为离线。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Query {

	private const RANK = array( 'active' => 0, 'idle' => 1, 'offline' => 2 );

	public static function tab_status( array $tab, int $now_ms ): string {
		$age = $now_ms - (int) $tab['last_signal_ms'];
		if ( $age > Tracker::OFFLINE_MS || ( 'page_exit' === $tab['last_type'] && $age > Tracker::EXIT_MS ) ) {
			return 'offline';
		}
		return 'hidden' === $tab['visibility'] || 'idle' === $tab['activity'] ? 'idle' : 'active';
	}

	private static function visitor_label( array $v ): array {
		$user = (int) $v['user_id'] ? get_userdata( (int) $v['user_id'] ) : false;
		return array(
			'id'       => (int) $v['id'],
			'label'    => $user ? $user->display_name : sprintf( 'Visitor #%d', (int) $v['id'] ),
			'user_id'  => $user ? (int) $user->ID : 0,
			'email'    => $user ? $user->user_email : '',
			'device'   => (string) $v['device'],
			'browser'  => (string) $v['browser'],
			'visits'   => (int) $v['visits'],
			'first_ms' => (int) $v['first_ms'],
			'last_ms'  => (int) $v['last_ms'],
		);
	}

	private static function visit_view( array $s ): array {
		return array(
			'id'        => (int) $s['id'],
			'start_ms'  => (int) $s['start_ms'],
			'last_ms'   => (int) $s['last_ms'],
			'landing'   => (string) $s['landing_path'],
			'device'    => (string) $s['device'],
			'events'    => (int) $s['events'],
			'pageviews' => (int) $s['pageviews'],
			'stage'     => (int) $s['stage'],
			'order_id'  => (int) $s['order_id'],
			'order_url' => (int) $s['order_id'] ? self::order_url( (int) $s['order_id'] ) : '',
		);
	}

	private static function order_url( int $order_id ): string {
		$order = wc_get_order( $order_id );
		return $order ? $order->get_edit_order_url() : '';
	}

	/** 最近 30 分钟有信号的访客，按状态和最后信号排列。 */
	public static function live( int $now_ms ): array {
		global $wpdb;
		$tabs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'tabs' ) . ' WHERE last_signal_ms >= %d ORDER BY last_signal_ms DESC LIMIT 500', $now_ms - Tracker::VISIT_GAP_MS ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$by   = array();
		foreach ( $tabs as $t ) {
			$by[ (int) $t['visitor_id'] ][] = $t;
		}
		if ( ! $by ) {
			return array();
		}
		$ids      = implode( ',', array_map( 'intval', array_keys( $by ) ) );
		$visitors = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'visitors' ) . " WHERE id IN ({$ids})", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out      = array();
		foreach ( $by as $vid => $list ) {
			if ( ! isset( $visitors[ $vid ] ) ) {
				continue;
			}
			$tabs_out = array();
			$best     = 'offline';
			$last     = 0;
			$visit_id = 0;
			foreach ( $list as $t ) {
				$status = self::tab_status( $t, $now_ms );
				if ( self::RANK[ $status ] < self::RANK[ $best ] ) {
					$best = $status;
				}
				if ( (int) $t['last_signal_ms'] > $last ) {
					$last     = (int) $t['last_signal_ms'];
					$visit_id = (int) $t['visit_id'];
				}
				$tabs_out[] = array(
					'tab'            => substr( (string) $t['tab_id'], 0, 8 ),
					'status'         => $status,
					'page_type'      => (string) $t['page_type'],
					'path'           => (string) $t['path'],
					'product_id'     => (int) $t['product_id'],
					'product_name'   => (string) $t['product_name'],
					'visibility'     => (string) $t['visibility'],
					'activity'       => (string) $t['activity'],
					'last_type'      => (string) $t['last_type'],
					'last_signal_ms' => (int) $t['last_signal_ms'],
					'video'          => $t['video'] ? json_decode( (string) $t['video'], true ) : null,
				);
			}
			usort( $tabs_out, static fn( $a, $b ) => array( self::RANK[ $a['status'] ], -$a['last_signal_ms'] ) <=> array( self::RANK[ $b['status'] ], -$b['last_signal_ms'] ) );
			$visit = $visit_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'visits' ) . ' WHERE id = %d', $visit_id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$out[] = array(
				'visitor'        => self::visitor_label( (array) $visitors[ $vid ] ),
				'status'         => $best,
				'last_signal_ms' => $last,
				'visit'          => $visit ? self::visit_view( $visit ) : null,
				'tabs'           => $tabs_out,
			);
		}
		usort( $out, static fn( $a, $b ) => array( self::RANK[ $a['status'] ], -$a['last_signal_ms'] ) <=> array( self::RANK[ $b['status'] ], -$b['last_signal_ms'] ) );
		return $out;
	}

	/** 一位访客：基本信息、访问历史（最近 100 次）、同一账号的其他访客编号（其他设备 / 浏览器）。 */
	public static function visitor( int $visitor_id ): ?array {
		global $wpdb;
		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'visitors' ) . ' WHERE id = %d', $visitor_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $v ) {
			return null;
		}
		$visits = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'visits' ) . ' WHERE visitor_id = %d ORDER BY start_ms DESC LIMIT 100', $visitor_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$others = array();
		if ( (int) $v['user_id'] ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'visitors' ) . ' WHERE user_id = %d AND id <> %d ORDER BY last_ms DESC LIMIT 20', (int) $v['user_id'], $visitor_id ), ARRAY_A ) as $o ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
				$others[] = self::visitor_label( $o );
			}
		}
		return array(
			'visitor' => self::visitor_label( $v ),
			'visits'  => array_map( array( self::class, 'visit_view' ), $visits ),
			'others'  => $others,
		);
	}

	/** 一次访问的时间线：按发生时间排列，同一时间按页面内序号。 */
	public static function timeline( int $visit_id ): ?array {
		global $wpdb;
		$visit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'visits' ) . ' WHERE id = %d', $visit_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $visit ) {
			return null;
		}
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'events' ) . ' WHERE visit_id = %d ORDER BY occurred_ms ASC, page_instance_id ASC, seq ASC, id ASC LIMIT 2000', $visit_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$events = array();
		foreach ( $rows as $r ) {
			$data     = json_decode( (string) $r['data'], true );
			$events[] = array(
				'id'           => (int) $r['id'],
				'type'         => (string) $r['type'],
				'source'       => (string) $r['source'],
				'at_ms'        => (int) $r['occurred_ms'],
				'received_ms'  => (int) $r['received_ms'],
				'tab'          => substr( (string) $r['tab_id'], 0, 8 ),
				'page'         => substr( (string) $r['page_instance_id'], 0, 8 ),
				'seq'          => (int) $r['seq'],
				'page_type'    => (string) $r['page_type'],
				'path'         => (string) $r['path'],
				'product_id'   => (int) $r['product_id'],
				'variation_id' => (int) $r['variation_id'],
				'order_id'     => (int) $r['order_id'],
				'order_url'    => (int) $r['order_id'] ? self::order_url( (int) $r['order_id'] ) : '',
				'data'         => is_array( $data ) ? $data : array(),
			);
		}
		return array(
			'visit'  => self::visit_view( $visit ),
			'events' => $events,
		);
	}

	/**
	 * 转化统计：时间段内开始的访问，各阶段到达人次（阶段只升不降，所以“到达加购”包含之后下单的访问）。
	 *
	 * @param string $device '' / desktop / mobile / tablet
	 */
	public static function stats( int $from_ms, int $to_ms, string $device = '' ): array {
		global $wpdb;
		$visits = Schema::table( 'visits' );
		$where  = $wpdb->prepare( 'start_ms >= %d AND start_ms < %d', $from_ms, $to_ms );
		if ( in_array( $device, array( 'desktop', 'mobile', 'tablet' ), true ) ) {
			$where .= $wpdb->prepare( ' AND device = %s', $device );
		}
		$row = $wpdb->get_row( "SELECT COUNT(*) AS visits, COUNT(DISTINCT visitor_id) AS visitors, SUM(stage >= 1) AS s1, SUM(stage >= 2) AS s2, SUM(stage >= 3) AS s3, SUM(stage >= 4) AS s4, SUM(stage >= 5) AS s5, SUM(pageviews) AS pageviews FROM {$visits} WHERE {$where}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		// 按店铺时区的日期分组（每天 0 点开始新的一天）。
		$days = array();
		foreach ( $wpdb->get_results( "SELECT start_ms, stage FROM {$visits} WHERE {$where} ORDER BY start_ms LIMIT 100000", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$d = wp_date( 'Y-m-d', (int) floor( (int) $r['start_ms'] / 1000 ) );
			if ( ! isset( $days[ $d ] ) ) {
				$days[ $d ] = array( 'day' => $d, 'visits' => 0, 'carts' => 0, 'orders' => 0, 'paid' => 0 );
			}
			++$days[ $d ]['visits'];
			$days[ $d ]['carts']  += (int) ( (int) $r['stage'] >= 2 );
			$days[ $d ]['orders'] += (int) ( (int) $r['stage'] >= 4 );
			$days[ $d ]['paid']   += (int) ( (int) $r['stage'] >= 5 );
		}
		$ev   = Schema::table( 'events' );
		$top  = $wpdb->get_results( "SELECT e.product_id, SUM(e.type = 'product_view') AS views, SUM(e.type = 'add_to_cart') AS carts FROM {$ev} e INNER JOIN {$visits} ON {$visits}.id = e.visit_id WHERE " . str_replace( array( 'start_ms', 'device' ), array( "{$visits}.start_ms", "{$visits}.device" ), $where ) . " AND e.product_id > 0 AND e.type IN ('product_view','add_to_cart') GROUP BY e.product_id ORDER BY views DESC, carts DESC LIMIT 10", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$n    = static fn( $v ) => (int) ( $v ?? 0 );
		return array(
			'from_ms'   => $from_ms,
			'to_ms'     => $to_ms,
			'device'    => $device,
			'visits'    => $n( $row['visits'] ?? 0 ),
			'visitors'  => $n( $row['visitors'] ?? 0 ),
			'pageviews' => $n( $row['pageviews'] ?? 0 ),
			'funnel'    => array(
				array( 'stage' => 0, 'count' => $n( $row['visits'] ?? 0 ) ),
				array( 'stage' => 1, 'count' => $n( $row['s1'] ?? 0 ) ),
				array( 'stage' => 2, 'count' => $n( $row['s2'] ?? 0 ) ),
				array( 'stage' => 3, 'count' => $n( $row['s3'] ?? 0 ) ),
				array( 'stage' => 4, 'count' => $n( $row['s4'] ?? 0 ) ),
				array( 'stage' => 5, 'count' => $n( $row['s5'] ?? 0 ) ),
			),
			'days'      => array_values( $days ),
			'products'  => array_map(
				static fn( $p ) => array( 'product_id' => (int) $p['product_id'], 'name' => html_entity_decode( (string) get_the_title( (int) $p['product_id'] ) ), 'views' => (int) $p['views'], 'carts' => (int) $p['carts'] ),
				$top
			),
		);
	}
}
