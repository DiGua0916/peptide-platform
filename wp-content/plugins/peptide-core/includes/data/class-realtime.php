<?php
/**
 * 实时数据页（DATA-01 第 6 步）：最近 10 分钟的访客、访问量、订单、销售额、购买力（每个数字后面带今日合计）、实时访客行为漏斗、
 * 每分钟访客数量，以及今日的热门商品和页面；口径见 Shopname“实时数据”页和帮助中心。
 *
 * 约定（所有数字都由 summary() 在同一个时刻 now 算出，测试可以传固定的 now）：
 * - now = Tracking\Tracker::now_ms()（毫秒）；W 分钟的窗口是 (now − W·60 000, now]；订单用整秒：s = intdiv( now, 1000 )，
 *   窗口是 date_created 在 (s − W·60, s]，即 Operations::paid_orders( s − W·60 + 1, s + 1 )。
 * - 今日 = 包含 now 的店铺日：[ from, to ) = Overview::window( 'today', s )，不自己算午夜。
 *
 * 访客活动事件：浏览器事件类型（Ingest::types() 的全部键）加服务端的 add_to_cart、order_placed、login、register，它们都是访客自己的请求写下的。
 * 不算 order_status、payment_success、payment_failed：管理员改订单状态或支付回调也会写这三种，访客不一定在网站上。
 * 查询一律用 type IN ( … )，走 type_time ( type, occurred_ms ) 索引。
 *
 * 卡片（窗口固定 10 分钟）和今日（见 cards() / today()）：
 * - visitors 访客数：卡片 = last_ms 落在窗口里的访问的不同访客数（最近有动静的人）；今日 = Operations::period() 的 visitors（今天开始的访问）。
 *   所以刚过午夜时卡片的数字可能比今日大，这是有意的（卡片数最近活动，今日数今天开始的访问）。
 * - pageviews 访问量：page_view 事件数（卡片按窗口，今日按店铺日）；
 * - orders 订单数 / sales 销售额：已付款的非测试订单（Overview::is_test_order()）数 / Σ（订单金额 − 已退款金额），保留 2 位；今日取 Operations::period() 的 orders / sales；
 * - units_per_order 客单数 = 售出件数（数量 − 已退数量）/ 订单数，保留 2 位，没有订单时 0.0；今日 = kpis['items'] / kpis['orders']，同一规则；
 * - aov 客单价 = 销售额 / 订单数，保留 2 位，没有订单时 0.0；今日 = kpis['aov']。
 *
 * 漏斗（funnel 窗口 10 / 20 / 30 分钟，默认 10）：last_ms 落在窗口里的访问，按 stage ≥ 0 / 1 / 2 / 3 / 5 数不同的访客，
 * 对应 visitors / viewed / carted / checkout / paid（完成支付 = 付了款的访客），结果交给 Operations::layers()。
 *
 * 每分钟访客数量（minutes 窗口 10 / 20 / 30 分钟，默认 10）：ago = W−1 … 0（最旧的在前），每一格是访客活动事件落在
 * (now − (ago+1)·60 000, now − ago·60 000] 里的不同访客数。SQL 里用 FLOOR( ( now − occurred_ms ) / 60 000 ) 分格：
 * 差值在 [ago·60 000, (ago+1)·60 000) 里就是第 ago 格，正好是上面的左开右闭区间（正好在 now 的事件是 0 格，正好早 60 000 的是 1 格）。
 * 不能用 CEIL( … ) − 1，整分钟的边界会算错。
 *
 * 今日列表（各取前 5 名，按数量从多到少）：
 * - viewed：product_view 事件最多的商品（product_id > 0）；carted：add_to_cart 事件最多的商品；
 * - sold：今日已付款非测试订单里售出件数（数量 − 已退数量）最多的商品（取父商品，款式合并；件数 ≤ 0 的不列）；
 * - pages：page_view 事件最多的路径（path 不为空）。
 * 并列：商品按商品 ID 从小到大；页面按路径 strcmp。SQL 的 GROUP BY path 按表的排序规则不分大小写（和 Page_Visits 一样），所以页面在 PHP 里排序。
 * 商品条目：id、name（商品已删除时是 #id）、count、edit_url（商品已删除时是空字符串）；页面条目：path、url（站点的协议 + 域名（+ 端口）+ 路径）、count。
 *
 * 访客追踪的表没装时：tracking 为 false，访客、访问量、漏斗、每分钟和基于事件的列表是 0 或空，订单数字照常。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

use PeptideCore\Api\Errors;
use PeptideCore\Overview;
use PeptideCore\Tracking\Ingest;
use PeptideCore\Tracking\Schema;

defined( 'ABSPATH' ) || exit;

final class Realtime {

	/** 漏斗和每分钟图可以选的窗口（分钟），第一个是默认值。 */
	public const WINDOWS = array( 10, 20, 30 );

	/** 今日每个列表的条数。 */
	public const LIST_SIZE = 5;

	/** 卡片的窗口（分钟），固定。 */
	private const CARD_WINDOW = 10;

	/** 服务端写下的访客活动事件（访客自己的请求触发）；浏览器的事件类型取自 Ingest::types()。 */
	private const SERVER_ACTIVITY = array( 'add_to_cart', 'order_placed', 'login', 'register' );

	/**
	 * 页面需要的全部数字，在 $now_ms 这个时刻算出。
	 *
	 * @param int $now_ms  当前时刻（毫秒）。
	 * @param int $funnel  漏斗窗口，WINDOWS 里的值，其他按 10。
	 * @param int $minutes 每分钟图的窗口，WINDOWS 里的值，其他按 10。
	 */
	public static function summary( int $now_ms, int $funnel, int $minutes ): array {
		$funnel  = in_array( $funnel, self::WINDOWS, true ) ? $funnel : self::WINDOWS[0];
		$minutes = in_array( $minutes, self::WINDOWS, true ) ? $minutes : self::WINDOWS[0];
		$sec     = intdiv( $now_ms, 1000 );
		$day     = Overview::window( 'today', $sec );
		$day_ms  = array( $day[0] * 1000, $day[1] * 1000 );

		// 访问的漏斗数：卡片的 10 分钟和漏斗窗口相同时只查一次。
		$visits = array( self::CARD_WINDOW => self::visit_counts( $now_ms, self::CARD_WINDOW ) );
		if ( ! isset( $visits[ $funnel ] ) ) {
			$visits[ $funnel ] = self::visit_counts( $now_ms, $funnel );
		}

		// 卡片：10 分钟窗口的订单（整秒）和事件。
		$card_orders = self::order_numbers( self::real_orders( Operations::paid_orders( $sec - self::CARD_WINDOW * 60 + 1, $sec + 1 ) ) );
		$card_window = self::window_ms( $now_ms, self::CARD_WINDOW );
		$cards       = array(
			'window'          => self::CARD_WINDOW,
			'visitors'        => $visits[ self::CARD_WINDOW ]['visitors'],
			'pageviews'       => self::count_page_views( $card_window[0], $card_window[1] ),
			'orders'          => $card_orders['orders'],
			'sales'           => $card_orders['sales'],
			'units_per_order' => $card_orders['orders'] ? round( $card_orders['units'] / $card_orders['orders'], 2 ) : 0.0,
			'aov'             => $card_orders['orders'] ? round( $card_orders['sales'] / $card_orders['orders'], 2 ) : 0.0,
		);

		// 今日：数字取自运营数据的同一个方法（一次调用），商品销量用今天已付款订单的商品行（只取一次订单）。
		$period = Operations::period( $day[0], $day[1] );
		$kpis   = $period['kpis'];
		$today  = array(
			'visitors'        => (int) $kpis['visitors'],
			'pageviews'       => self::count_page_views( $day_ms[0], $day_ms[1] ),
			'orders'          => (int) $kpis['orders'],
			'sales'           => (float) $kpis['sales'],
			'units_per_order' => (int) $kpis['orders'] ? round( (int) $kpis['items'] / (int) $kpis['orders'], 2 ) : 0.0,
			'aov'             => (float) $kpis['aov'],
		);

		return array(
			'now_ms'   => $now_ms,
			'updated'  => wp_date( 'Y-m-d H:i:s', $sec ),
			'tracking' => Schema::installed(),
			'cards'    => $cards,
			'today'    => $today,
			'funnel'   => array(
				'window' => $funnel,
				'layers' => Operations::layers( $visits[ $funnel ] ),
			),
			'minutes'  => array(
				'window' => $minutes,
				'points' => self::per_minute( $now_ms, $minutes ),
			),
			'lists'    => array(
				'viewed' => self::top_products( 'product_view', $day_ms[0], $day_ms[1] ),
				'carted' => self::top_products( 'add_to_cart', $day_ms[0], $day_ms[1] ),
				'sold'   => self::top_sold( self::real_orders( Operations::paid_orders( $day[0], $day[1] ) ) ),
				'pages'  => self::top_pages( $day_ms[0], $day_ms[1] ),
			),
			'links'    => array(
				'products' => admin_url( 'admin.php?page=pc-data-products&range=today' ),
				'pages'    => admin_url( 'admin.php?page=pc-data-pages&range=today' ),
			),
		);
	}

	/**
	 * 校验 funnel / minutes：没传或为空 = 10；是 WINDOWS 里的值就是它；其他返回 pc_invalid_param（400）。
	 *
	 * @param array<string,mixed> $params Request parameters.
	 * @return array{funnel:int,minutes:int}|\WP_Error
	 */
	public static function params( array $params ) {
		$allowed = array_map( 'strval', self::WINDOWS );
		$errors  = array();

		$funnel = Params::word( $params, 'funnel', $allowed[0], $allowed );
		if ( null === $funnel ) {
			$errors['funnel'] = 'invalid_format';
		}
		$minutes = Params::word( $params, 'minutes', $allowed[0], $allowed );
		if ( null === $minutes ) {
			$errors['minutes'] = 'invalid_format';
		}
		if ( $errors ) {
			return Errors::invalid( $errors );
		}

		return array(
			'funnel'  => (int) $funnel,
			'minutes' => (int) $minutes,
		);
	}

	/** History validates raw keys before the shared range parser can default empty values. */
	public static function history_params( array $params, ?int $now = null ) {
		$errors = array();
		$custom = array_key_exists( 'from', $params ) || array_key_exists( 'to', $params );
		foreach ( array( 'range', 'from', 'to' ) as $key ) {
			if ( array_key_exists( $key, $params ) && ! is_scalar( $params[ $key ] ) ) {
				$errors[ $key ] = 'invalid_format';
			}
		}
		if ( array_key_exists( 'range', $params ) && ! isset( $errors['range'] ) && ! in_array( trim( (string) $params['range'] ), Range::PRESETS, true ) ) {
			$errors['range'] = 'invalid_format';
		}
		if ( $custom ) {
			foreach ( array( 'from', 'to' ) as $key ) {
				if ( ! isset( $errors[ $key ] ) && ( ! array_key_exists( $key, $params ) || '' === trim( (string) $params[ $key ] ) ) ) {
					$errors[ $key ] = 'required';
				}
			}
		}
		if ( $errors ) {
			return Errors::invalid( $errors );
		}
		return Range::from_params( array_intersect_key( $params, array_flip( array( 'range', 'from', 'to' ) ) ), $now );
	}

	/**
	 * Calendar history, [from,to) in the shop timezone. Visitors/funnel/chart share
	 * the visit-start cohort (distinct visitors, never a sum of bucket UV). Events
	 * use occurred_ms; paid non-test orders use creation time and current refunds.
	 * Funnel paid visitors are independent of the native paid-order card count.
	 * Paid orders are fetched once; this path does not query buyer history.
	 */
	public static function history( array $range ): array {
		global $wpdb;
		$from = (int) $range['from'];
		$to   = (int) $range['to'];
		$fm   = $from * 1000;
		$tm   = $to * 1000;
		$counts = array_fill_keys( Operations::LAYERS, 0 );
		if ( Schema::installed() ) {
			$visits = Schema::table( 'visits' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_id) AS visitors, COUNT(DISTINCT CASE WHEN stage >= 1 THEN visitor_id END) AS viewed, COUNT(DISTINCT CASE WHEN stage >= 2 THEN visitor_id END) AS carted, COUNT(DISTINCT CASE WHEN stage >= 3 THEN visitor_id END) AS checkout, COUNT(DISTINCT CASE WHEN stage >= 5 THEN visitor_id END) AS paid FROM {$visits} WHERE start_ms >= %d AND start_ms < %d", $fm, $tm ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( $counts as $key => $zero ) {
				$counts[ $key ] = (int) ( $row[ $key ] ?? 0 );
			}
		}
		$orders = self::real_orders( Operations::paid_orders( $from, $to ) );
		$numbers = self::order_numbers( $orders );
		$query = $range['preset'] ? array( 'range' => $range['preset'] ) : array( 'from' => $range['dates']['from'], 'to' => $range['dates']['to'] );
		return array(
			'mode'     => 'history',
			'range'    => array_intersect_key( $range, array_flip( array( 'preset', 'from', 'to', 'days', 'bucket' ) ) ) + array( 'dates' => array( 'from' => $range['dates']['from'], 'to' => $range['dates']['to'] ), 'timezone' => wp_timezone_string() ),
			'updated'  => wp_date( 'Y-m-d H:i:s' ),
			'tracking' => Schema::installed(),
			'cards'    => array(
				'visitors' => $counts['visitors'],
				'pageviews' => self::count_page_views( $fm, $tm ),
				'orders' => $numbers['orders'],
				'sales' => $numbers['sales'],
				'units_per_order' => $numbers['orders'] ? round( $numbers['units'] / $numbers['orders'], 2 ) : 0.0,
				'aov' => $numbers['orders'] ? round( $numbers['sales'] / $numbers['orders'], 2 ) : 0.0,
			),
			'funnel' => array( 'layers' => Operations::layers( $counts ) ),
			'series' => self::history_series( $range ),
			'lists' => array(
				'viewed' => self::top_products( 'product_view', $fm, $tm ),
				'carted' => self::top_products( 'add_to_cart', $fm, $tm ),
				'sold' => self::top_sold( $orders ),
				'pages' => self::top_pages( $fm, $tm, true ),
			),
			'links' => array(
				'products' => add_query_arg( $query, admin_url( 'admin.php?page=pc-data-products' ) ),
				'pages' => add_query_arg( $query, admin_url( 'admin.php?page=pc-data-pages' ) ),
			),
		);
	}

	/** At most 366 aggregate rows, one SQL query, no raw visitor IDs or per-bucket queries. */
	private static function history_series( array $range ): array {
		global $wpdb;
		$from = (int) $range['from'];
		$to = (int) $range['to'];
		$hour = 'hour' === $range['bucket'];
		$points = array();
		$day = ( new \DateTimeImmutable( '@' . $from ) )->setTimezone( wp_timezone() );
		for ( $start = $from; $start < $to; $start = $next ) {
			$next = $hour ? $start + HOUR_IN_SECONDS : $day->modify( '+1 day' )->getTimestamp();
			$points[] = array( 'start' => $start, 'end' => min( $next, $to ), 'label' => wp_date( $hour ? 'H:i T' : 'Y-m-d', $start ), 'visitors' => 0 );
			$day = $day->modify( '+1 day' );
		}
		if ( $points && Schema::installed() ) {
			$cases = array();
			$args = array();
			foreach ( $points as $i => $point ) {
				$cases[] = 'WHEN start_ms < %d THEN %d';
				$args[] = $point['end'] * 1000;
				$args[] = $i;
			}
			$args[] = $from * 1000;
			$args[] = $to * 1000;
			$visits = Schema::table( 'visits' );
			$case = implode( ' ', $cases );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT CASE {$case} END AS bucket, COUNT(DISTINCT visitor_id) AS visitors FROM {$visits} WHERE start_ms >= %d AND start_ms < %d GROUP BY bucket", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$points[ (int) $row['bucket'] ]['visitors'] = (int) $row['visitors'];
			}
		}
		return array( 'bucket' => $range['bucket'], 'points' => $points );
	}

	/**
	 * (now − W·60 000, now] 换成左闭右开的毫秒区间 [from, to)（整数毫秒，两种写法完全一样）。
	 *
	 * @return array{0:int,1:int}
	 */
	private static function window_ms( int $now_ms, int $minutes ): array {
		return array( $now_ms - $minutes * 60000 + 1, $now_ms + 1 );
	}

	/** 访客活动事件的类型（浏览器的全部类型加服务端的四种）。 */
	private static function activity_types(): array {
		return array_values( array_unique( array_merge( array_keys( Ingest::types() ), self::SERVER_ACTIVITY ) ) );
	}

	/** 去掉测试订单。 */
	private static function real_orders( array $orders ): array {
		return array_values( array_filter( $orders, static fn( $o ) => $o instanceof \WC_Order && ! Overview::is_test_order( $o ) ) );
	}

	/**
	 * 一批订单的订单数、销售额（订单金额 − 已退款金额，2 位小数）和售出件数（数量 − 已退数量）。
	 *
	 * @param \WC_Order[] $orders 已经去掉测试订单。
	 * @return array{orders:int,sales:float,units:int}
	 */
	private static function order_numbers( array $orders ): array {
		$sales = 0.0;
		$units = 0;
		foreach ( $orders as $order ) {
			$sales += (float) $order->get_total() - (float) $order->get_total_refunded();
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product ) {
					$units += (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
				}
			}
		}
		return array(
			'orders' => count( $orders ),
			'sales'  => round( $sales, 2 ),
			'units'  => $units,
		);
	}

	/**
	 * last_ms 落在窗口里的访问：按 stage 数不同的访客（表没装时全是 0）。
	 *
	 * @return array{visitors:int,viewed:int,carted:int,checkout:int,paid:int}
	 */
	private static function visit_counts( int $now_ms, int $minutes ): array {
		global $wpdb;
		$out = array(
			'visitors' => 0,
			'viewed'   => 0,
			'carted'   => 0,
			'checkout' => 0,
			'paid'     => 0,
		);
		if ( ! Schema::installed() ) {
			return $out;
		}
		$visits = Schema::table( 'visits' );
		$w      = self::window_ms( $now_ms, $minutes );
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_id) AS visitors, COUNT(DISTINCT CASE WHEN stage >= 1 THEN visitor_id END) AS viewed, COUNT(DISTINCT CASE WHEN stage >= 2 THEN visitor_id END) AS carted, COUNT(DISTINCT CASE WHEN stage >= 3 THEN visitor_id END) AS checkout, COUNT(DISTINCT CASE WHEN stage >= 5 THEN visitor_id END) AS paid FROM {$visits} WHERE last_ms >= %d AND last_ms < %d", $w[0], $w[1] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( is_array( $row ) ) {
			foreach ( array_keys( $out ) as $key ) {
				$out[ $key ] = (int) $row[ $key ];
			}
		}
		return $out;
	}

	/** page_view 事件数，occurred_ms 在 [from, to)（毫秒）里；表没装时 0。 */
	private static function count_page_views( int $from_ms, int $to_ms ): int {
		global $wpdb;
		if ( ! Schema::installed() ) {
			return 0;
		}
		$events = Schema::table( 'events' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE type = 'page_view' AND occurred_ms >= %d AND occurred_ms < %d", $from_ms, $to_ms ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 每分钟的访客数：最旧的在前，一共 $minutes 个，没有事件的分钟是 0。
	 *
	 * @return array<int,array{ago:int,visitors:int}>
	 */
	private static function per_minute( int $now_ms, int $minutes ): array {
		global $wpdb;
		$by_ago = array();
		if ( Schema::installed() ) {
			$events = Schema::table( 'events' );
			$types  = self::activity_types();
			$marks  = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
			// 差值先转成有符号数再相减（occurred_ms 是无符号的，负数时 MySQL 会报“超出范围”）。
			$sql  = "SELECT FLOOR( ( %d - CAST( occurred_ms AS SIGNED ) ) / 60000 ) AS ago, COUNT( DISTINCT visitor_id ) AS visitors FROM {$events} WHERE type IN ( {$marks} ) AND occurred_ms > %d AND occurred_ms <= %d GROUP BY ago";
			$args = array_merge( array( $now_ms ), $types, array( $now_ms - $minutes * 60000, $now_ms ) );
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$by_ago[ (int) $row['ago'] ] = (int) $row['visitors'];
			}
		}

		$points = array();
		for ( $ago = $minutes - 1; $ago >= 0; $ago-- ) {
			$points[] = array(
				'ago'      => $ago,
				'visitors' => $by_ago[ $ago ] ?? 0,
			);
		}
		return $points;
	}

	/**
	 * 事件最多的商品（今日，前 LIST_SIZE 名）：并列按商品 ID 从小到大；表没装时空。
	 *
	 * @param string $type product_view 或 add_to_cart（本类传入的固定值）。
	 * @return array<int,array{id:int,name:string,count:int,edit_url:string}>
	 */
	private static function top_products( string $type, int $from_ms, int $to_ms ): array {
		global $wpdb;
		if ( ! Schema::installed() ) {
			return array();
		}
		$events = Schema::table( 'events' );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, COUNT(*) AS cnt FROM {$events} WHERE type = %s AND product_id > 0 AND occurred_ms >= %d AND occurred_ms < %d GROUP BY product_id ORDER BY cnt DESC, product_id ASC LIMIT %d", $type, $from_ms, $to_ms, self::LIST_SIZE ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out    = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::product_entry( (int) $row['product_id'], (int) $row['cnt'] );
		}
		return $out;
	}

	/**
	 * 今日售出件数最多的商品（前 LIST_SIZE 名）：已付款非测试订单的商品行，数量 − 已退数量，款式合并到父商品；并列按商品 ID 从小到大。
	 *
	 * @param \WC_Order[] $orders 今日已付款订单，已经去掉测试订单。
	 * @return array<int,array{id:int,name:string,count:int,edit_url:string}>
	 */
	private static function top_sold( array $orders ): array {
		$units = array();
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$id = (int) $item->get_product_id();
				if ( $id <= 0 ) {
					continue;
				}
				$units[ $id ] = ( $units[ $id ] ?? 0 ) + (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
			}
		}
		// 件数 ≤ 0（全部退掉）的不列；按件数从多到少，再商品 ID 从小到大。
		$units = array_filter( $units, static fn( int $n ): bool => $n > 0 );
		uksort(
			$units,
			static function ( int $a, int $b ) use ( $units ): int {
				$cmp = $units[ $b ] <=> $units[ $a ];
				return 0 !== $cmp ? $cmp : $a <=> $b;
			}
		);
		$out = array();
		foreach ( array_slice( $units, 0, self::LIST_SIZE, true ) as $id => $count ) {
			$out[] = self::product_entry( (int) $id, (int) $count );
		}
		return $out;
	}

	/**
	 * 今日访问量最多的页面路径（前 LIST_SIZE 名）：page_view 事件数，并列按路径 strcmp；表没装时空。
	 *
	 * @return array<int,array{path:string,url:string,count:int}>
	 */
	private static function top_pages( int $from_ms, int $to_ms, bool $bounded = false ): array {
		global $wpdb;
		if ( ! Schema::installed() ) {
			return array();
		}
		$events = Schema::table( 'events' );
		$limit = $bounded ? ' ORDER BY cnt DESC, BINARY path ASC LIMIT ' . self::LIST_SIZE : '';
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT path, COUNT(*) AS cnt FROM {$events} WHERE type = 'page_view' AND path <> '' AND occurred_ms >= %d AND occurred_ms < %d GROUP BY path{$limit}", $from_ms, $to_ms ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$pages  = array();
		foreach ( (array) $rows as $row ) {
			$pages[] = array(
				'path'  => (string) $row['path'],
				'count' => (int) $row['cnt'],
			);
		}
		usort(
			$pages,
			static function ( array $a, array $b ): int {
				$cmp = $b['count'] <=> $a['count'];
				return 0 !== $cmp ? $cmp : strcmp( $a['path'], $b['path'] );
			}
		);

		// 链接 = 站点的协议 + 域名（+ 端口）+ 存下来的路径（路径已经带着 WordPress 的子目录，不能再用 home_url( $path )），和 Page_Visits 一样。
		$home   = (array) wp_parse_url( home_url() );
		$origin = empty( $home['host'] ) ? '' : ( $home['scheme'] ?? 'http' ) . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );

		$out = array();
		foreach ( array_slice( $pages, 0, self::LIST_SIZE ) as $page ) {
			$out[] = array(
				'path'  => $page['path'],
				'url'   => $origin . $page['path'],
				'count' => $page['count'],
			);
		}
		return $out;
	}

	/**
	 * 商品条目；商品已删除时名字是 #id，编辑链接为空。
	 *
	 * @return array{id:int,name:string,count:int,edit_url:string}
	 */
	private static function product_entry( int $id, int $count ): array {
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product ) {
			return array(
				'id'       => $id,
				'name'     => sprintf( '#%d', $id ),
				'count'    => $count,
				'edit_url' => '',
			);
		}
		return array(
			'id'       => $id,
			'name'     => $product->get_name(),
			'count'    => $count,
			'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
		);
	}
}
