<?php
/**
 * 后台读取（VISITOR-ANALYTICS-TEST-01）：实时访客、单访客的访问历史与时间线、转化统计。只给管理员 / 店长用。
 *
 * 在线口径（服务器时间）：
 * - active：最后信号 ≤ 45 秒，页面可见且没有空闲；
 * - idle：最后信号 ≤ 45 秒，但页面在后台（hidden）或 60 秒没有操作；
 * - offline：超过 45 秒没有信号，或最后一个事件是离开页面且之后 10 秒没有新信号。
 * 访客的状态取各标签页里最“在线”的一个，见 presence()。前端在页面隐藏时继续发心跳（visibility=hidden），
 * 所以切到后台的标签页是 idle，不会被当成离开。离开的访客进“访客归档”（archive()），历史不删除。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Query {

	private const RANK = array( 'active' => 0, 'idle' => 1, 'away' => 2, 'offline' => 3, 'left' => 4, 'gone' => 5 );

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

	/**
	 * 访客现在的状态，按这位访客的**全部**标签页聚合：
	 * - active / idle：至少一个标签页的最后信号在 45 秒内（idle = 后台或空闲，仍算在线）；
	 * - away：45 秒内没有信号，但不是“全部标签页都离开”，且最后信号在 30 分钟内——暂离 / 无信号，仍留在实时列表；
	 * - left：全部标签页最后一个事件都是离开页面，且之后 10 秒没有新信号（离页信号）——进归档；
	 * - gone：30 分钟没有任何信号（超时推定离站）——进归档。
	 * 离站时间都是推定的：浏览器被冻结、断网时同样收不到信号。
	 *
	 * @param array<int,array> $tabs
	 */
	public static function presence( array $tabs, int $now_ms ): string {
		$best   = 'gone';
		$last   = 0;
		$exited = true;
		foreach ( $tabs as $t ) {
			$last   = max( $last, (int) $t['last_signal_ms'] );
			$status = self::tab_status( $t, $now_ms );
			if ( 'offline' !== $status && self::RANK[ $status ] < self::RANK[ $best ] ) {
				$best = $status;
			}
			// 只看最近 30 分钟用过的标签页：很久以前关掉但没来得及发离页事件的标签页不影响判断。
			$recent = (int) $t['last_signal_ms'] >= $now_ms - Tracker::VISIT_GAP_MS;
			if ( $recent && ( 'page_exit' !== $t['last_type'] || $now_ms - (int) $t['last_signal_ms'] <= Tracker::EXIT_MS ) ) {
				$exited = false;
			}
		}
		if ( in_array( $best, array( 'active', 'idle' ), true ) ) {
			return $best;
		}
		if ( ! $tabs || $last < $now_ms - Tracker::VISIT_GAP_MS ) {
			return 'gone';
		}
		return $exited ? 'left' : 'away';
	}

	/**
	 * 最近 30 分钟有信号的访客的全部标签页（先按访客取最后信号，再取这些访客的所有标签页，不会因为条数上限漏掉某个仍在线的标签页）。
	 *
	 * @return array<int,array<int,array>> 访客 ID => 标签页行
	 */
	private static function recent_tabs( int $now_ms ): array {
		global $wpdb;
		$tabs = Schema::table( 'tabs' );
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT visitor_id FROM {$tabs} GROUP BY visitor_id HAVING MAX(last_signal_ms) >= %d ORDER BY MAX(last_signal_ms) DESC LIMIT 500", $now_ms - Tracker::VISIT_GAP_MS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $ids ) {
			return array();
		}
		$in  = implode( ',', array_map( 'intval', $ids ) );
		$by  = array();
		foreach ( $wpdb->get_results( "SELECT * FROM {$tabs} WHERE visitor_id IN ({$in})", ARRAY_A ) as $t ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$by[ (int) $t['visitor_id'] ][] = $t;
		}
		return $by;
	}

	/** 实时访客：在线、空闲和暂离的访客（离开的进“访客归档”），按状态和最后信号排列。 */
	public static function live( int $now_ms ): array {
		global $wpdb;
		$by = self::recent_tabs( $now_ms );
		if ( ! $by ) {
			return array();
		}
		$ids      = implode( ',', array_map( 'intval', array_keys( $by ) ) );
		$visitors = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'visitors' ) . " WHERE id IN ({$ids})", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out      = array();
		foreach ( $by as $vid => $list ) {
			$presence = self::presence( $list, $now_ms );
			if ( ! isset( $visitors[ $vid ] ) || in_array( $presence, array( 'left', 'gone' ), true ) ) {
				continue;
			}
			$tabs_out = array();
			$last     = 0;
			$visit_id = 0;
			foreach ( $list as $t ) {
				if ( (int) $t['last_signal_ms'] < $now_ms - Tracker::VISIT_GAP_MS ) {
					continue; // 很久以前用过的标签页不再列出。
				}
				if ( (int) $t['last_signal_ms'] > $last ) {
					$last     = (int) $t['last_signal_ms'];
					$visit_id = (int) $t['visit_id'];
				}
				$tabs_out[] = array(
					'tab'            => substr( (string) $t['tab_id'], 0, 8 ),
					'status'         => self::tab_status( $t, $now_ms ),
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
				'cart'           => Carts::view( (array) $visitors[ $vid ], false ),
				'status'         => $presence,
				'last_signal_ms' => $last,
				'visit'          => $visit ? self::visit_view( $visit ) : null,
				'tabs'           => $tabs_out,
			);
		}
		usort( $out, static fn( $a, $b ) => array( self::RANK[ $a['status'] ], -$a['last_signal_ms'] ) <=> array( self::RANK[ $b['status'] ], -$b['last_signal_ms'] ) );
		return $out;
	}

	/** 分页游标：“毫秒:ID”，按（时间, ID）排序。 */
	private static function cursor( $value ): ?array {
		return is_string( $value ) && preg_match( '/^(\d{1,15}):(\d{1,15})$/', $value, $m ) ? array( (int) $m[1], (int) $m[2] ) : null;
	}

	/** 一次访问最后一个事件和最后一个页面（归档列表显示离站原因和最后页面）。 */
	private static function visit_tail( int $visit_id ): array {
		global $wpdb;
		$events = Schema::table( 'events' );
		$type   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT type FROM {$events} WHERE visit_id = %d AND source = 'client' ORDER BY occurred_ms DESC, id DESC LIMIT 1", $visit_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$page   = $wpdb->get_row( $wpdb->prepare( "SELECT page_type, path, data FROM {$events} WHERE visit_id = %d AND type = 'page_view' ORDER BY occurred_ms DESC, id DESC LIMIT 1", $visit_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$data   = $page ? json_decode( (string) $page['data'], true ) : array();
		return array(
			'last_type'  => $type,
			'last_page'  => $page ? array( 'page_type' => (string) $page['page_type'], 'path' => (string) $page['path'], 'product_name' => (string) ( $data['product_name'] ?? '' ) ) : null,
		);
	}

	/**
	 * 访客归档：已经结束的访问（每次访问一行），新的在前，游标分页。
	 * 结束 = 最后信号超过 30 分钟（超时推定），或这位访客的全部标签页已经离开（离页信号）。
	 * 正在浏览的访客，他以前的访问也会在这里；访客回来后重新出现在实时列表，历史不删除。
	 *
	 * @param array{limit?:int,cursor?:string,from_ms?:int,to_ms?:int,device?:string,q?:string} $args
	 */
	public static function archive( int $now_ms, array $args = array() ): array {
		global $wpdb;
		$visits   = Schema::table( 'visits' );
		$visitors = Schema::table( 'visitors' );
		$limit    = max( 1, min( 100, (int) ( $args['limit'] ?? 25 ) ) );
		$cutoff   = $now_ms - Tracker::VISIT_GAP_MS;

		// 最近 30 分钟内、全部标签页都已离开的访客：他们最近一次访问也算结束。
		$left_visits = array();
		foreach ( self::recent_tabs( $now_ms ) as $vid => $list ) {
			if ( 'left' === self::presence( $list, $now_ms ) ) {
				$latest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$visits} WHERE visitor_id = %d ORDER BY start_ms DESC, id DESC LIMIT 1", $vid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				if ( $latest ) {
					$left_visits[] = $latest;
				}
			}
		}
		$ended = $wpdb->prepare( 's.last_ms < %d', $cutoff );
		if ( $left_visits ) {
			$ended = '( ' . $ended . ' OR s.id IN (' . implode( ',', array_map( 'intval', $left_visits ) ) . ') )';
		}
		$where = array( $ended );
		if ( ! empty( $args['from_ms'] ) ) {
			$where[] = $wpdb->prepare( 's.start_ms >= %d', (int) $args['from_ms'] );
		}
		if ( ! empty( $args['to_ms'] ) ) {
			$where[] = $wpdb->prepare( 's.start_ms < %d', (int) $args['to_ms'] );
		}
		if ( in_array( $args['device'] ?? '', array( 'desktop', 'mobile', 'tablet' ), true ) ) {
			$where[] = $wpdb->prepare( 's.device = %s', $args['device'] );
		}
		$q = trim( (string) ( $args['q'] ?? '' ) );
		if ( '' !== $q ) {
			if ( preg_match( '/^#?(\d+)$/', $q, $m ) ) {
				$where[] = $wpdb->prepare( 's.visitor_id = %d', (int) $m[1] );
			} else {
				$like  = '%' . $wpdb->esc_like( $q ) . '%';
				$users = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE display_name LIKE %s OR user_email LIKE %s OR user_login LIKE %s LIMIT 200", $like, $like, $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$where[] = $users ? 'v.user_id IN (' . implode( ',', array_map( 'intval', $users ) ) . ')' : '0 = 1';
			}
		}
		$cursor = self::cursor( $args['cursor'] ?? null );
		if ( $cursor ) {
			$where[] = $wpdb->prepare( '( s.last_ms < %d OR ( s.last_ms = %d AND s.id < %d ) )', $cursor[0], $cursor[0], $cursor[1] );
		}
		$rows = $wpdb->get_results( "SELECT s.*, v.id AS v_id, v.vid, v.user_id AS v_user, v.first_ms, v.last_ms AS v_last_ms, v.visits AS v_visits, v.device AS v_device, v.browser, v.cart, v.cart_ms, v.cart_key FROM {$visits} s INNER JOIN {$visitors} v ON v.id = s.visitor_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY s.last_ms DESC, s.id DESC LIMIT ' . ( $limit + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$more  = count( $rows ) > $limit;
		$rows  = array_slice( $rows, 0, $limit );
		$items = array();
		foreach ( $rows as $r ) {
			$v    = array(
				'id'       => (int) $r['v_id'],
				'user_id'  => (int) $r['v_user'],
				'device'   => (string) $r['v_device'],
				'browser'  => (string) $r['browser'],
				'visits'   => (int) $r['v_visits'],
				'first_ms' => (int) $r['first_ms'],
				'last_ms'  => (int) $r['v_last_ms'],
				'cart'     => $r['cart'],
				'cart_ms'  => $r['cart_ms'],
				'cart_key' => $r['cart_key'],
			);
			$tail = self::visit_tail( (int) $r['id'] );
			$items[] = array(
				'visit'   => self::visit_view( $r ),
				'visitor' => self::visitor_label( $v ),
				'cart'    => Carts::view( $v, false ),
				// 离站原因：最后一个前端事件是离开页面 → 离页信号；否则超时推定。都不是精确的关站时间。
				'reason'  => 'page_exit' === $tail['last_type'] ? 'exit_signal' : 'timeout',
				'left_ms' => (int) $r['last_ms'],
				'last_page' => $tail['last_page'],
			);
		}
		$last = end( $rows );
		return array(
			'now_ms'      => $now_ms,
			'items'       => $items,
			'has_more'    => $more,
			'next_cursor' => $more && $last ? (int) $last['last_ms'] . ':' . (int) $last['id'] : null,
		);
	}

	/** 一位访客的访问历史，新的在前，游标分页（按开始时间）。 */
	public static function visits_page( int $visitor_id, ?string $cursor = null, int $limit = 25 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		$where = $wpdb->prepare( 'visitor_id = %d', $visitor_id );
		$c     = self::cursor( $cursor );
		if ( $c ) {
			$where .= $wpdb->prepare( ' AND ( start_ms < %d OR ( start_ms = %d AND id < %d ) )', $c[0], $c[0], $c[1] );
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'visits' ) . " WHERE {$where} ORDER BY start_ms DESC, id DESC LIMIT " . ( $limit + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$more = count( $rows ) > $limit;
		$rows = array_slice( $rows, 0, $limit );
		$last = end( $rows );
		return array(
			'items'       => array_map( array( self::class, 'visit_view' ), $rows ),
			'has_more'    => $more,
			'next_cursor' => $more && $last ? (int) $last['start_ms'] . ':' . (int) $last['id'] : null,
		);
	}

	/** 一位访客：基本信息、访问历史第一页、同一账号的其他访客编号（其他设备 / 浏览器）。 */
	public static function visitor( int $visitor_id ): ?array {
		global $wpdb;
		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'visitors' ) . ' WHERE id = %d', $visitor_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $v ) {
			return null;
		}
		$others = array();
		if ( (int) $v['user_id'] ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'visitors' ) . ' WHERE user_id = %d AND id <> %d ORDER BY last_ms DESC LIMIT 20', (int) $v['user_id'], $visitor_id ), ARRAY_A ) as $o ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
				$others[] = self::visitor_label( $o );
			}
		}
		$page = self::visits_page( $visitor_id );
		$now  = Tracker::now_ms();
		$tabs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'tabs' ) . ' WHERE visitor_id = %d', $visitor_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return array(
			'visitor'     => self::visitor_label( $v ),
			'presence'    => self::presence( $tabs, $now ),
			'cart'        => Carts::view( $v, false ),
			'visits'      => $page['items'],
			'has_more'    => $page['has_more'],
			'next_cursor' => $page['next_cursor'],
			'others'      => $others,
		);
	}

	/**
	 * 一次访问的时间线：按发生时间排列，游标分页（默认每页 200 条）。心跳默认在数据库里就过滤掉，
	 * 隐藏心跳后仍然能翻到末尾的业务事件。
	 */
	public static function timeline( int $visit_id, ?string $cursor = null, int $limit = 200, bool $heartbeats = false ): ?array {
		global $wpdb;
		$visit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'visits' ) . ' WHERE id = %d', $visit_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $visit ) {
			return null;
		}
		$limit = max( 1, min( 500, $limit ) );
		$where = $wpdb->prepare( 'visit_id = %d', $visit_id );
		if ( ! $heartbeats ) {
			$where .= " AND type <> 'heartbeat'";
		}
		$c = self::cursor( $cursor );
		if ( $c ) {
			$where .= $wpdb->prepare( ' AND ( occurred_ms > %d OR ( occurred_ms = %d AND id > %d ) )', $c[0], $c[0], $c[1] );
		}
		$rows   = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'events' ) . " WHERE {$where} ORDER BY occurred_ms ASC, id ASC LIMIT " . ( $limit + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$more   = count( $rows ) > $limit;
		$rows   = array_slice( $rows, 0, $limit );
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
		$last = end( $rows );
		return array(
			'visit'       => self::visit_view( $visit ),
			'events'      => $events,
			'has_more'    => $more,
			'next_cursor' => $more && $last ? (int) $last['occurred_ms'] . ':' . (int) $last['id'] : null,
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
