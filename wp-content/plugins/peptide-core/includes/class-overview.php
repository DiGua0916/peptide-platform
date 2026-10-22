<?php
/**
 * 后台“概览”数据大盘（OVERVIEW-01），参照 Shopname 的概览页。
 *
 * 口径（时间都按店铺时区）：
 * - 销售额：时间段内创建、已付款（processing / completed 等 Woo 的已付款状态）的订单金额，减去已退款金额；
 * - 订单数：上述订单数量；客单价 = 销售额 / 订单数；
 * - 访问用户 / 回访用户：来自访客追踪（测试版）。时间段内有访问的访客；回访 = 第一次出现早于时间段开始；
 * - 注册用户：时间段内注册的账号；
 * - 订单转化率 = 订单数 / 访问用户；
 * - 转化漏斗：访客追踪的访问阶段（Tracking\Query::stats）；
 * - 访客趋势：今天 / 昨天按小时，7 天 / 30 天按天；
 * - 商品排名：时间段内已付款订单里的销量。
 * 访客追踪关闭或没有数据时，访客相关的数字是 0，并在页面上说明。
 *
 * 只统计真实数据：测试订单（用“Test payment”测试付款方式下的单，或包含演示商品的单）不计入销售额、订单数、
 * 客单价、转化率、趋势和商品排名，页面上显示排除了几笔。所有数字都在打开页面时从数据库实时计算，
 * 店铺时区（2026-10-22 起为 America/Los_Angeles）每天 0 点开始新的“今天”。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

use PeptideCore\Tracking\Query;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class Overview {

	public const RANGES = array( 'today', 'yesterday', '7d', '30d' );

	/**
	 * 时间段：[开始时间戳, 结束时间戳, 按小时还是按天]。
	 *
	 * @return array{0:int,1:int,2:string}
	 */
	public static function window( string $range, ?int $now = null ): array {
		$tz    = wp_timezone();
		$now   = $now ?? time();
		$today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->setTime( 0, 0 );
		switch ( $range ) {
			case 'yesterday':
				return array( $today->modify( '-1 day' )->getTimestamp(), $today->getTimestamp(), 'hour' );
			case '7d':
				return array( $today->modify( '-6 days' )->getTimestamp(), $today->modify( '+1 day' )->getTimestamp(), 'day' );
			case '30d':
				return array( $today->modify( '-29 days' )->getTimestamp(), $today->modify( '+1 day' )->getTimestamp(), 'day' );
		}
		return array( $today->getTimestamp(), $today->modify( '+1 day' )->getTimestamp(), 'hour' );
	}

	/** 测试订单：测试付款方式（订单上保存的付款方式名称以 “Test payment” 开头），或者包含演示商品。 */
	public static function is_test_order( \WC_Order $order ): bool {
		if ( str_starts_with( (string) $order->get_payment_method_title(), 'Test payment' ) ) {
			return true;
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && '1' === get_post_meta( $item->get_product_id(), Meta::DEMO_SEED, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return \WC_Order[] 时间段内创建的已付款订单（包括测试订单，由调用方排除）。 */
	private static function paid_orders( int $from, int $to ): array {
		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() ),
				'date_created' => $from . '...' . ( $to - 1 ),
				'limit'        => 2000,
				'return'       => 'objects',
			)
		);
		return is_array( $orders ) ? $orders : array();
	}

	private static function count_orders( array $statuses, int $from = 0 ): int {
		$args = array(
			'type'     => 'shop_order',
			'status'   => array_map( static fn( $s ) => 'wc-' . $s, $statuses ),
			'limit'    => -1,
			'return'   => 'ids',
		);
		if ( $from ) {
			$args['date_created'] = '>=' . $from;
		}
		return count( (array) wc_get_orders( $args ) );
	}

	/**
	 * 访客数据（追踪表不存在时全部为 0）。
	 *
	 * @return array{visitors:int,returning:int,series:array}
	 */
	private static function visitor_numbers( int $from, int $to, string $bucket ): array {
		global $wpdb;
		$out = array( 'visitors' => 0, 'returning' => 0, 'series' => self::empty_series( $from, $to, $bucket ) );
		if ( ! Schema::installed() ) {
			return $out;
		}
		$visits   = Schema::table( 'visits' );
		$visitors = Schema::table( 'visitors' );
		$fm       = $from * 1000;
		$tm       = $to * 1000;
		$out['visitors']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_id) FROM {$visits} WHERE start_ms >= %d AND start_ms < %d", $fm, $tm ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out['returning'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT s.visitor_id) FROM {$visits} s INNER JOIN {$visitors} v ON v.id = s.visitor_id WHERE s.start_ms >= %d AND s.start_ms < %d AND v.first_ms < %d", $fm, $tm, $fm ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows             = $wpdb->get_results( $wpdb->prepare( "SELECT visitor_id, start_ms FROM {$visits} WHERE start_ms >= %d AND start_ms < %d LIMIT 50000", $fm, $tm ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$seen             = array();
		$index            = array_flip( array_column( $out['series'], 'key' ) );
		foreach ( $rows as $r ) {
			$key = self::bucket_key( (int) floor( (int) $r['start_ms'] / 1000 ), $bucket );
			if ( isset( $index[ $key ] ) && ! isset( $seen[ $key ][ $r['visitor_id'] ] ) ) {
				$seen[ $key ][ $r['visitor_id'] ] = true;
				++$out['series'][ $index[ $key ] ]['visitors'];
			}
		}
		return $out;
	}

	private static function bucket_key( int $ts, string $bucket ): string {
		return wp_date( 'hour' === $bucket ? 'Y-m-d H' : 'Y-m-d', $ts );
	}

	private static function empty_series( int $from, int $to, string $bucket ): array {
		$out  = array();
		$step = 'hour' === $bucket ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
		for ( $t = $from; $t < $to; $t += $step ) {
			$out[] = array(
				'key'      => self::bucket_key( $t, $bucket ),
				'label'    => wp_date( 'hour' === $bucket ? 'H:00' : 'm-d', $t ),
				'visitors' => 0,
				'orders'   => 0,
			);
		}
		return $out;
	}

	/** 某个时间段的全部数字。 */
	public static function range_data( string $range, ?int $now = null ): array {
		$range                  = in_array( $range, self::RANGES, true ) ? $range : 'today';
		[ $from, $to, $bucket ] = self::window( $range, $now );
		$all                    = self::paid_orders( $from, $to );
		$orders                 = array_values( array_filter( $all, static fn( $o ) => ! self::is_test_order( $o ) ) );
		$sales                  = 0.0;
		$products               = array();
		$v                      = self::visitor_numbers( $from, $to, $bucket );
		$index                  = array_flip( array_column( $v['series'], 'key' ) );
		foreach ( $orders as $order ) {
			$sales += (float) $order->get_total() - (float) $order->get_total_refunded();
			$created = $order->get_date_created();
			if ( $created ) {
				$key = self::bucket_key( $created->getTimestamp(), $bucket );
				if ( isset( $index[ $key ] ) ) {
					++$v['series'][ $index[ $key ] ]['orders'];
				}
			}
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$pid = $item->get_product_id();
				if ( ! isset( $products[ $pid ] ) ) {
					$products[ $pid ] = array( 'product_id' => $pid, 'quantity' => 0, 'sales' => 0.0 );
				}
				$products[ $pid ]['quantity'] += (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
				$products[ $pid ]['sales']    += (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item->get_id() );
			}
		}
		usort( $products, static fn( $a, $b ) => array( $b['quantity'], $b['sales'] ) <=> array( $a['quantity'], $a['sales'] ) );
		$top = array();
		foreach ( array_slice( array_filter( $products, static fn( $p ) => $p['quantity'] > 0 ), 0, 10 ) as $p ) {
			$product = wc_get_product( $p['product_id'] );
			$image   = $product ? wp_get_attachment_image_src( (int) $product->get_image_id(), 'thumbnail' ) : false;
			$top[]   = array(
				'product_id' => $p['product_id'],
				'name'       => $product ? $product->get_name() : sprintf( '#%d', $p['product_id'] ),
				'sku'        => $product ? $product->get_sku() : '',
				'image'      => $image ? $image[0] : '',
				'url'        => $product ? (string) get_edit_post_link( $p['product_id'], 'raw' ) : '',
				'quantity'   => $p['quantity'],
				'sales'      => round( $p['sales'], 2 ),
			);
		}
		$count      = count( $orders );
		$registered = new \WP_User_Query(
			array(
				'count_total' => true,
				'number'      => 1,
				'fields'      => 'ID',
				'role__not_in' => array( 'administrator', 'shop_manager', 'editor', 'author' ),
				'date_query'  => array(
					array(
						'after'     => gmdate( 'Y-m-d H:i:s', $from ),
						'before'    => gmdate( 'Y-m-d H:i:s', $to ),
						'inclusive' => true,
						'column'    => 'user_registered',
					),
				),
			)
		);
		$funnel = Schema::installed() ? Query::stats( $from * 1000, $to * 1000 )['funnel'] : array();
		return array(
			'range'      => $range,
			'from'       => wp_date( 'Y-m-d H:i', $from ),
			'to'         => wp_date( 'Y-m-d H:i', $to - 1 ),
			'bucket'     => $bucket,
			'sales'      => round( $sales, 2 ),
			'orders'     => $count,
			'aov'        => $count ? round( $sales / $count, 2 ) : 0,
			'test_orders' => count( $all ) - $count,
			'visitors'   => $v['visitors'],
			'returning'  => $v['returning'],
			'registered' => (int) $registered->get_total(),
			'conversion' => $v['visitors'] ? round( $count / $v['visitors'] * 100, 2 ) : null,
			'funnel'     => $funnel,
			'series'     => array_map( static fn( $s ) => array( 'label' => $s['label'], 'visitors' => $s['visitors'], 'orders' => $s['orders'] ), $v['series'] ),
			'products'   => $top,
		);
	}

	/** 不随时间段变化的部分：待处理、实时、节日、币种。 */
	public static function summary( ?int $now = null ): array {
		$now     = $now ?? time();
		$online  = 0;
		if ( Schema::installed() ) {
			foreach ( Query::live( $now * 1000 ) as $l ) {
				if ( in_array( $l['status'], array( 'active', 'idle' ), true ) ) {
					++$online;
				}
			}
		}
		$hour     = (int) wp_date( 'G', $now );
		$tomorrow = self::window( 'today', $now )[1];
		$currency = get_woocommerce_currency();
		$week_ago = $now - 7 * DAY_IN_SECONDS;
		// 待发货：开启了订单履约（SHIP-TRACK-01）时按“已付款但没发货”，否则按 processing 状态。
		$ship     = Workbench::orders_awaiting_shipment();
		return array(
			'store'     => (string) get_bloginfo( 'name' ),
			'greeting'  => $hour < 12 ? 'morning' : ( $hour < 18 ? 'afternoon' : 'evening' ),
			'now'       => wp_date( 'Y-m-d H:i:s', $now ),
			'timezone'  => wp_timezone_string(),
			// 例如 “America/Los_Angeles (PDT, UTC-07:00)”。夏令时自动切换。
			'tz_label'  => wp_timezone_string() . ' (' . wp_date( 'T', $now ) . ', UTC' . wp_date( 'P', $now ) . ')',
			// 店铺时区的下一个 0 点（毫秒），页面在这个时间自动刷新“今天”。
			'next_midnight_ms' => $tomorrow * 1000,
			'currency'  => array( 'code' => $currency, 'symbol' => html_entity_decode( get_woocommerce_currency_symbol( $currency ) ) ),
			'tracking'  => Tracker::enabled(),
			'todo'      => array(
				'to_ship'           => null !== $ship ? (int) $ship['count'] : self::count_orders( array( 'processing' ) ),
				'on_hold'           => self::count_orders( array( 'on-hold' ) ),
				'unpaid_7d'         => self::count_orders( array( 'pending' ), $week_ago ),
				'failed_7d'         => self::count_orders( array( 'failed' ), $week_ago ),
				'missing_batch'     => (int) Workbench::orders_missing_batch()['count'],
				'batches_no_report' => (int) Workbench::batches_without_report()['count'],
			),
			'online'    => $online,
			'holidays'  => self::holidays( $now ),
		);
	}

	/** 第 n 个星期几（n=-1 表示最后一个）。 */
	private static function nth_weekday( int $year, int $month, int $weekday, int $n ): \DateTimeImmutable {
		$tz = wp_timezone();
		if ( $n < 0 ) {
			$d = new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), $tz );
			$d = $d->modify( 'last day of this month' );
			while ( (int) $d->format( 'w' ) !== $weekday ) {
				$d = $d->modify( '-1 day' );
			}
			return $d;
		}
		$d = new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), $tz );
		while ( (int) $d->format( 'w' ) !== $weekday ) {
			$d = $d->modify( '+1 day' );
		}
		return $d->modify( '+' . ( $n - 1 ) . ' weeks' );
	}

	/**
	 * 接下来的美国零售节日（店铺面向美国），最近的 4 个，带倒计时天数。
	 *
	 * @return array<int,array{key:string,name:string,date:string,days:int}>
	 */
	public static function holidays( ?int $now = null ): array {
		$now   = $now ?? time();
		$tz    = wp_timezone();
		$today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->setTime( 0, 0 );
		$list  = array();
		foreach ( array( (int) $today->format( 'Y' ), (int) $today->format( 'Y' ) + 1 ) as $y ) {
			$thanks = self::nth_weekday( $y, 11, 4, 4 );
			$days   = array(
				'new_year'     => new \DateTimeImmutable( "$y-01-01", $tz ),
				'valentine'    => new \DateTimeImmutable( "$y-02-14", $tz ),
				'mothers'      => self::nth_weekday( $y, 5, 0, 2 ),
				'memorial'     => self::nth_weekday( $y, 5, 1, -1 ),
				'fathers'      => self::nth_weekday( $y, 6, 0, 3 ),
				'independence' => new \DateTimeImmutable( "$y-07-04", $tz ),
				'labor'        => self::nth_weekday( $y, 9, 1, 1 ),
				'halloween'    => new \DateTimeImmutable( "$y-10-31", $tz ),
				'thanksgiving' => $thanks,
				'black_friday' => $thanks->modify( '+1 day' ),
				'cyber_monday' => $thanks->modify( '+4 days' ),
				'christmas'    => new \DateTimeImmutable( "$y-12-25", $tz ),
			);
			foreach ( $days as $key => $d ) {
				$diff = (int) round( ( $d->getTimestamp() - $today->getTimestamp() ) / DAY_IN_SECONDS );
				if ( $diff >= 0 ) {
					$list[] = array( 'key' => $key, 'date' => $d->format( 'Y-m-d' ), 'days' => $diff );
				}
			}
		}
		usort( $list, static fn( $a, $b ) => $a['days'] <=> $b['days'] );
		return array_slice( $list, 0, 4 );
	}
}
