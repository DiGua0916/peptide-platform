<?php
/**
 * 运营数据页（DATA-01 第 7a 步）：九张趋势图的数字（访客数带浏览量、订单数、销售额、客单数、客单价、复购率、复购订单量、复购成交额、复购客单价），
 * 每张图有本期合计、对比期合计、变化，以及本期和对比期各自按时间分桶的点；口径见 Shopname 帮助中心“运营数据”和页面提示。
 *
 * 时间段是 [from, to)（Unix 秒，店铺时区由 Data\Range 换算好）。
 *
 * 分桶：
 * - range.bucket 是 hour 时，每个桶是真实的一小时，从 from 开始每次加 3600 秒直到 to；夏令时那天是 23 或 25 个桶，标签 H:00。
 * - 是 day 时，每个桶是店铺时区的一个日历天（从当天 0 点到下一个 0 点，用 DateTimeImmutable 的 +1 day 步进），标签 m-d。
 * - 一条数据属于“开始 <= 它的时间 < 结束”的那个桶：访问按 start_ms，事件按 occurred_ms，订单按创建时间。
 * - 对比期也用主时间段的桶类型，但按自己的长度分桶，所以点数可以不同（夏令时那天 25 对 24，或者自定义的对比期更长）；页面按下标对应。
 *
 * 数字（每个桶 / 时间段合计）：
 * - visitors 访客数：桶内是这个桶里开始访问的不同访客；合计是整个时间段开始访问的不同访客（和运营数据页指标卡片同一个口径），所以不是各桶之和；
 * - pageviews 浏览量（PV）：page_view 事件数，合计是各桶之和；
 * - orders 订单数 / sales 销售额 / aov 客单价：已付款订单（去掉测试订单），销售额 = 订单金额 - 已退款金额，保留 2 位小数，没有订单时客单价为 0；
 * - units_per_order 客单数：已付款件数 / 订单数，件数 = 商品行数量 - 已退数量，保留 2 位小数，没有订单时为 0；
 * - repurchase_rate 复购率：复购用户 / 有购买的用户 × 100，保留 2 位，没有购买用户时为 null；
 * - repurchase_orders 复购订单量 / repurchase_sales 复购成交额：复购订单的个数和销售额，合计是各桶之和；
 * - repurchase_aov 复购客单价：复购成交额 / 复购订单量，没有复购订单时为 0。
 *
 * 复购：
 * - 购买用户的标识：有账号用 'u' + 账号 ID，游客用 'e' + 小写去空格的账单邮箱；游客没有邮箱的订单只在复购数字里跳过。
 * - 一个订单是复购订单，当它的购买用户在它之前已经有一个已付款的非测试订单：在时间段开始之前（历史），或者在本时间段里更早（按创建时间、再按订单 ID 从早到晚）。
 * - 桶或时间段里的“购买用户”是它的订单里不同的购买用户；“复购用户”是其中至少有一个复购订单的用户。
 * - 历史查询：每个购买用户只查一次（只查本期第一次出现的用户），最多取 20 个更早的已付款订单，找到第一个非测试订单就算有历史。
 *
 * 变化：用 Range::change()；复购率的变化是百分点差（round( 本期 - 对比期, 2 )）；任何一边是 null 时变化为 null。
 * 访客追踪的表没装时，访客数和浏览量到处都是 0，tracking 为 false。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

use PeptideCore\Overview;
use PeptideCore\Tracking\Schema;

defined( 'ABSPATH' ) || exit;

final class Trends {

	/** 九张图用到的十个数字（浏览量跟访客数在同一张图里），页面和测试都依赖这个顺序。 */
	public const METRICS = array( 'visitors', 'pageviews', 'orders', 'sales', 'units_per_order', 'aov', 'repurchase_rate', 'repurchase_orders', 'repurchase_sales', 'repurchase_aov' );

	/**
	 * 趋势图的全部数字：本期、对比期和变化。
	 *
	 * @param array $range A successful Range::from_params() result.
	 * @return array{range:array,tracking:bool,bucket:string,current:array,previous:array,change:array<string,?float>}
	 */
	public static function summary( array $range ): array {
		$bucket   = 'hour' === ( $range['bucket'] ?? '' ) ? 'hour' : 'day';
		$current  = self::period( (int) $range['from'], (int) $range['to'], $bucket );
		$previous = self::period( (int) $range['compare_from'], (int) $range['compare_to'], $bucket );

		$change = array();
		foreach ( self::METRICS as $key ) {
			$c = $current['totals'][ $key ];
			$p = $previous['totals'][ $key ];
			if ( 'repurchase_rate' === $key ) {
				// 复购率本身是百分比，变化用百分点差。
				$change[ $key ] = ( null === $c || null === $p ) ? null : round( (float) $c - (float) $p, 2 );
			} else {
				$change[ $key ] = Range::change( (float) $c, (float) $p );
			}
		}

		return array(
			'range'    => $range,
			'tracking' => Schema::installed(),
			'bucket'   => $bucket,
			'current'  => $current,
			'previous' => $previous,
			'change'   => $change,
		);
	}

	/**
	 * 一个时间段 [from, to) 的合计和每个桶的点。
	 *
	 * @return array{totals:array,points:array}
	 */
	private static function period( int $from, int $to, string $bucket ): array {
		$buckets = self::buckets( $from, $to, $bucket );
		$starts  = array_column( $buckets, 'start' );

		// 每个桶一份累加器；'total' 是整个时间段。
		$acc = array( 'total' => self::blank() );
		foreach ( array_keys( $buckets ) as $i ) {
			$acc[ $i ] = self::blank();
		}

		// 订单：只取一次，订单数、销售额、件数和复购都从这一份算（按创建时间从早到晚，复购要按先后判断）。
		$rows = array();
		foreach ( Operations::paid_orders( $from, $to ) as $order ) {
			if ( Overview::is_test_order( $order ) ) {
				continue;
			}
			$created = $order->get_date_created();
			$rows[]  = array(
				'ts'    => $created ? $created->getTimestamp() : 0,
				'id'    => (int) $order->get_id(),
				'order' => $order,
			);
		}
		usort( $rows, static fn( array $a, array $b ): int => array( $a['ts'], $a['id'] ) <=> array( $b['ts'], $b['id'] ) );

		$history = array();
		$seen    = array();
		foreach ( $rows as $row ) {
			$order = $row['order'];
			$i     = self::index( $starts, $to, $row['ts'] );
			if ( null === $i ) {
				continue;
			}
			$sales = (float) $order->get_total() - (float) $order->get_total_refunded();
			$units = 0;
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product ) {
					$units += (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
				}
			}

			$key   = self::buyer_key( $order );
			$again = false;
			if ( '' !== $key ) {
				// 本期里更早买过，或者时间段开始之前买过；本期第一次出现的用户才去查历史。
				$again        = isset( $seen[ $key ] ) || self::has_history( $order, $key, $from, $history );
				$seen[ $key ] = true;
			}

			foreach ( array( $i, 'total' ) as $slot ) {
				++$acc[ $slot ]['orders'];
				$acc[ $slot ]['sales'] += $sales;
				$acc[ $slot ]['units'] += $units;
				if ( '' === $key ) {
					continue;
				}
				$acc[ $slot ]['buyers'][ $key ] = true;
				if ( $again ) {
					$acc[ $slot ]['again_buyers'][ $key ] = true;
					++$acc[ $slot ]['again_orders'];
					$acc[ $slot ]['again_sales'] += $sales;
				}
			}
		}

		// 访客和浏览量：追踪的表没装时全是 0。
		$visitors_total = 0;
		if ( Schema::installed() ) {
			global $wpdb;
			$visits = Schema::table( 'visits' );
			$events = Schema::table( 'events' );
			$fm     = $from * 1000;
			$tm     = $to * 1000;

			// 合计：和指标卡片同一个口径（整个时间段里不同的访客），不是各桶之和。
			$visitors_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT visitor_id ) FROM {$visits} WHERE start_ms >= %d AND start_ms < %d", $fm, $tm ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

			// 每个桶：SQL 按距离 from 的真实小时数分组（不受夏令时影响），再在 PHP 里归到桶（一个天桶里有好几个小时）。
			$rows_v = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT visitor_id, FLOOR( ( start_ms - %d ) / 3600000 ) AS h FROM {$visits} WHERE start_ms >= %d AND start_ms < %d", $fm, $fm, $tm ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows_v as $r ) {
				$i = self::index( $starts, $to, $from + (int) $r['h'] * HOUR_IN_SECONDS );
				if ( null !== $i ) {
					$acc[ $i ]['visitors'][ (int) $r['visitor_id'] ] = true;
				}
			}
			$rows_p = $wpdb->get_results( $wpdb->prepare( "SELECT FLOOR( ( occurred_ms - %d ) / 3600000 ) AS h, COUNT(*) AS n FROM {$events} WHERE type = 'page_view' AND occurred_ms >= %d AND occurred_ms < %d GROUP BY h", $fm, $fm, $tm ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows_p as $r ) {
				$i = self::index( $starts, $to, $from + (int) $r['h'] * HOUR_IN_SECONDS );
				if ( null !== $i ) {
					$acc[ $i ]['pageviews']    += (int) $r['n'];
					$acc['total']['pageviews'] += (int) $r['n'];
				}
			}
		}

		$points = array();
		foreach ( $buckets as $i => $b ) {
			$points[] = array(
				'label' => $b['label'],
				'start' => $b['start'],
			) + self::values( $acc[ $i ], count( $acc[ $i ]['visitors'] ) );
		}

		return array(
			'totals' => self::values( $acc['total'], $visitors_total ),
			'points' => $points,
		);
	}

	/** 一份空的累加器。 */
	private static function blank(): array {
		return array(
			'visitors'     => array(),
			'pageviews'    => 0,
			'orders'       => 0,
			'sales'        => 0.0,
			'units'        => 0,
			'buyers'       => array(),
			'again_buyers' => array(),
			'again_orders' => 0,
			'again_sales'  => 0.0,
		);
	}

	/**
	 * 累加器变成十个数字（顺序 = METRICS）。
	 *
	 * @param array $a        blank() 的累加结果。
	 * @param int   $visitors 这份累加器的不同访客数（合计不是各桶之和，所以由调用方给）。
	 * @return array<string,int|float|null>
	 */
	private static function values( array $a, int $visitors ): array {
		$orders       = (int) $a['orders'];
		$sales        = round( (float) $a['sales'], 2 );
		$again_orders = (int) $a['again_orders'];
		$again_sales  = round( (float) $a['again_sales'], 2 );
		$buyers       = count( $a['buyers'] );
		return array(
			'visitors'          => $visitors,
			'pageviews'         => (int) $a['pageviews'],
			'orders'            => $orders,
			'sales'             => $sales,
			'units_per_order'   => $orders ? round( $a['units'] / $orders, 2 ) : 0.0,
			'aov'               => $orders ? round( $sales / $orders, 2 ) : 0.0,
			'repurchase_rate'   => $buyers ? round( count( $a['again_buyers'] ) / $buyers * 100, 2 ) : null,
			'repurchase_orders' => $again_orders,
			'repurchase_sales'  => $again_sales,
			'repurchase_aov'    => $again_orders ? round( $again_sales / $again_orders, 2 ) : 0.0,
		);
	}

	/**
	 * 把时间段切成桶。
	 *
	 * @return array<int,array{label:string,start:int}>
	 */
	private static function buckets( int $from, int $to, string $bucket ): array {
		$out = array();
		if ( 'hour' === $bucket ) {
			// 真实的小时：夏令时那天是 23 或 25 个，重复的那个小时标签相同。
			for ( $t = $from; $t < $to; $t += HOUR_IN_SECONDS ) {
				$out[] = array(
					'label' => wp_date( 'H:00', $t ),
					'start' => $t,
				);
			}
			return $out;
		}
		// 店铺时区的日历天：从一个 0 点到下一个 0 点，不用 86400 秒相加。
		$day = ( new \DateTimeImmutable( '@' . $from ) )->setTimezone( wp_timezone() );
		while ( $day->getTimestamp() < $to ) {
			$out[] = array(
				'label' => wp_date( 'm-d', $day->getTimestamp() ),
				'start' => $day->getTimestamp(),
			);
			$day = $day->modify( '+1 day' );
		}
		return $out;
	}

	/**
	 * 时间点属于哪个桶：开始时间 <= 它的最后一个桶；在第一个桶之前或不早于 $end 时为 null。二分查找。
	 *
	 * @param int[] $starts 桶的开始时间，从早到晚。
	 */
	private static function index( array $starts, int $end, int $ts ): ?int {
		if ( ! $starts || $ts < $starts[0] || $ts >= $end ) {
			return null;
		}
		$lo = 0;
		$hi = count( $starts ) - 1;
		while ( $lo < $hi ) {
			$mid = intdiv( $lo + $hi + 1, 2 );
			if ( $starts[ $mid ] <= $ts ) {
				$lo = $mid;
			} else {
				$hi = $mid - 1;
			}
		}
		return $lo;
	}

	/** 购买用户的标识：有账号按账号，游客按小写去空格的账单邮箱；游客没有邮箱时为 ''（这个订单不进复购数字）。 */
	private static function buyer_key( \WC_Order $order ): string {
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			return 'u' . $customer_id;
		}
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		return '' === $email ? '' : 'e' . $email;
	}

	/**
	 * 购买用户在时间段开始之前有没有已付款的非测试订单。每个用户只查一次，结果记在 $cache 里。
	 *
	 * @param array<string,bool> $cache buyer_key => 有没有历史。
	 */
	private static function has_history( \WC_Order $order, string $key, int $from, array &$cache ): bool {
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$args = array(
			'type'         => 'shop_order',
			'status'       => array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() ),
			'date_created' => '<' . $from,
			'limit'        => 20,
			'return'       => 'ids',
		);
		if ( (int) $order->get_customer_id() > 0 ) {
			$args['customer_id'] = (int) $order->get_customer_id();
		} else {
			$args['billing_email'] = substr( $key, 1 );
		}
		$found = false;
		$ids   = wc_get_orders( $args );
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$past = wc_get_order( $id );
			if ( $past instanceof \WC_Order && ! Overview::is_test_order( $past ) ) {
				$found = true;
				break;
			}
		}
		$cache[ $key ] = $found;
		return $found;
	}
}
