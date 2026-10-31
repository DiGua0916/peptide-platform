<?php
/**
 * 运营数据页（DATA-01 第 2 步）：12 个指标卡片、访客转化漏斗、回访用户转化漏斗，本期和对比期一起算；口径见 Shopname 帮助中心“运营数据”和概览页。
 *
 * 时间段是 [from, to)（Unix 秒，店铺时区由 Data\Range 换算好）；访问按 start_ms（毫秒）落在时间段内计算。
 * “已付款订单”= 和概览页一样的已付款状态、时间段内创建，再去掉测试订单（Overview::is_test_order()）。
 *
 * 12 个指标（顺序 = Shopname 卡片顺序，见 KPIS）：
 * - sales 销售额：已付款订单的 订单金额 - 已退款金额 之和，保留 2 位小数；
 * - visitors 访问用户：时间段内有访问的不同访客数；
 * - registered 注册用户：时间段内注册的账号（不含管理员、店长、编辑、作者），和概览页同一个查询；
 * - orders 订单数：已付款订单数；
 * - conversion 转化率：订单数 / 访问用户 × 100，保留 2 位；没有访问用户时为 null；
 * - aov 客单价：销售额 / 订单数，保留 2 位；没有订单时为 0；
 * - items 商品销售件数：已付款订单商品行的 数量 - 已退数量 之和；
 * - net_item_sales 商品净销售额：已付款订单商品行的 金额 - 已退金额 之和（不含运费和税）；
 * - refunds 退款金额：时间段内创建的退款金额之和（原订单存在且不是测试订单）；
 * - avg_duration 平均访问时长：访问的 最后活动 - 开始 的平均值，整秒；没有访问时为 0；
 * - customers 顾客数：登录访问的账号 + 已付款订单的顾客（有账号按账号，游客按账单邮箱，不分大小写），去重；
 * - mobile 移动端：设备是手机（mobile）的不同访客数。
 *
 * 转化漏斗（五层，见 LAYERS）：访问用户 → 查看商品（stage ≥ 1）→ 加购商品（≥ 2）→ 发起结账（≥ 3）→ 支付成功（= 已付款订单数，
 * 按订单不按人，所以可能比发起结账多）。每层有 step_rate（占上一层 %）和 visitor_rate（占访问用户 %）。
 * 回访用户转化漏斗：同样五层，只算回访的访问（和概览页同一个判断：第一次访问当天结束后再过“回访用户计算时间”）；
 * 支付成功 = 这些访问里下的、本时间段的已付款订单数（去重）。
 *
 * 对比：本期和对比期的每个数字都算一遍；变化用 Range::change()，只有转化率的变化是百分点差（0% 对 2.99% 是 -2.99%）。
 * 访客追踪的表没装时，访客相关的数字（访问用户、转化率、平均访问时长、移动端、漏斗除支付成功以外的层）都是 0 或 null。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

use PeptideCore\Overview;
use PeptideCore\Store_Config;
use PeptideCore\Tracking\Schema;

defined( 'ABSPATH' ) || exit;

final class Operations {

	/** 12 个指标，顺序 = Shopname 卡片顺序（页面和测试都依赖这个顺序）。 */
	public const KPIS = array( 'sales', 'visitors', 'registered', 'orders', 'conversion', 'aov', 'items', 'net_item_sales', 'refunds', 'avg_duration', 'customers', 'mobile' );

	/** 转化漏斗的五层，从上到下。 */
	public const LAYERS = array( 'visitors', 'viewed', 'carted', 'checkout', 'paid' );

	/**
	 * 运营数据页的全部数字：本期、对比期和变化。
	 *
	 * @param array $range A successful Range::from_params() result.
	 */
	public static function summary( array $range ): array {
		$current  = self::period( (int) $range['from'], (int) $range['to'] );
		$previous = self::period( (int) $range['compare_from'], (int) $range['compare_to'] );
		$reader = new Visitor_Funnel();
		$detail = $reader->period( (int) $range['from'] * 1000, (int) $range['to'] * 1000 );
		$before = $reader->period( (int) $range['compare_from'] * 1000, (int) $range['compare_to'] * 1000 );

		$change = array();
		foreach ( self::KPIS as $key ) {
			$c = $current['kpis'][ $key ];
			$p = $previous['kpis'][ $key ];
			if ( 'conversion' === $key ) {
				// 转化率本身是百分比，变化用百分点差。
				$change[ $key ] = ( null === $c || null === $p ) ? null : round( (float) $c - (float) $p, 2 );
			} else {
				$change[ $key ] = Range::change( (float) $c, (float) $p );
			}
		}

		return array(
			'range'       => $range,
			'tracking'    => Schema::installed(),
			'current'     => $current['kpis'],
			'previous'    => $previous['kpis'],
			'change'      => $change,
			'funnel'      => self::compare( $current['funnel'], $previous['funnel'] ),
			'returning'   => self::compare( $current['returning'], $previous['returning'] ),
			'test_orders' => $current['test_orders'],
			'visitor_funnel' => Visitor_Funnel::compare( $detail['overall'], $before['overall'] ),
			'returning_visitor_funnel' => Visitor_Funnel::compare( $detail['returning'], $before['returning'] ),
		);
	}

	/**
	 * Every number for one period [from, to) in unix seconds.
	 *
	 * @return array{kpis:array<string,int|float|null>,funnel:array,returning:array,test_orders:int}
	 */
	public static function period( int $from, int $to ): array {
		// 已付款订单只取一次，销售额、订单数、件数、净销售额、顾客（订单部分）和漏斗的支付成功都从这一份算。
		$all       = self::paid_orders( $from, $to );
		$orders    = array_values( array_filter( $all, static fn( $o ) => ! Overview::is_test_order( $o ) ) );
		$sales     = 0.0;
		$items     = 0;
		$net       = 0.0;
		$customers = array();
		$paid_ids  = array();
		foreach ( $orders as $order ) {
			$sales += (float) $order->get_total() - (float) $order->get_total_refunded();

			$paid_ids[ (int) $order->get_id() ] = true;
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$items += (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
				$net   += (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item->get_id() );
			}
			// 顾客：有账号按账号，游客按账单邮箱（不分大小写）。
			$customer_id = (int) $order->get_customer_id();
			if ( $customer_id > 0 ) {
				$customers[ 'u' . $customer_id ] = true;
			} else {
				$email = strtolower( trim( (string) $order->get_billing_email() ) );
				if ( '' !== $email ) {
					$customers[ 'e' . $email ] = true;
				}
			}
		}
		$count = count( $orders );

		$v = self::visitor_numbers( $from, $to, $paid_ids );
		foreach ( $v['users'] as $user_id ) {
			$customers[ 'u' . $user_id ] = true;
		}

		$sales = round( $sales, 2 );
		$kpis  = array(
			'sales'          => $sales,
			'visitors'       => $v['visitors'],
			'registered'     => self::registered( $from, $to ),
			'orders'         => $count,
			'conversion'     => $v['visitors'] ? round( $count / $v['visitors'] * 100, 2 ) : null,
			'aov'            => $count ? round( $sales / $count, 2 ) : 0.0,
			'items'          => $items,
			'net_item_sales' => round( $net, 2 ),
			'refunds'        => self::refunds( $from, $to ),
			'avg_duration'   => $v['avg_duration'],
			'customers'      => count( $customers ),
			'mobile'         => $v['mobile'],
		);

		$funnel            = $v['funnel'];
		$funnel['paid']    = $count;
		$returning         = $v['returning'];
		$returning['paid'] = $v['returning_paid'];

		return array(
			'kpis'        => $kpis,
			'funnel'      => self::layers( $funnel ),
			'returning'   => self::layers( $returning ),
			'test_orders' => count( $all ) - $count,
		);
	}

	/** @return \WC_Order[] 时间段内创建的已付款订单（包括测试订单，由调用方排除）。和 Overview::paid_orders() 相同，只是不限数量。其他数据页面（商品详情数据）也用这一份。 */
	public static function paid_orders( int $from, int $to ): array {
		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() ),
				'date_created' => $from . '...' . ( $to - 1 ),
				'limit'        => -1,
				'return'       => 'objects',
			)
		);
		return is_array( $orders ) ? $orders : array();
	}

	/** 时间段内注册的账号数（和概览页同一个查询）。 */
	private static function registered( int $from, int $to ): int {
		$registered = new \WP_User_Query(
			array(
				'count_total'  => true,
				'number'       => 1,
				'fields'       => 'ID',
				'role__not_in' => array( 'administrator', 'shop_manager', 'editor', 'author' ),
				'date_query'   => array(
					array(
						'after'     => gmdate( 'Y-m-d H:i:s', $from ),
						'before'    => gmdate( 'Y-m-d H:i:s', $to ),
						'inclusive' => true,
						'column'    => 'user_registered',
					),
				),
			)
		);
		return (int) $registered->get_total();
	}

	/** 时间段内创建的退款金额之和；原订单不存在或是测试订单的退款不算。 */
	private static function refunds( int $from, int $to ): float {
		$refunds = wc_get_orders(
			array(
				'type'         => 'shop_order_refund',
				'date_created' => $from . '...' . ( $to - 1 ),
				'limit'        => -1,
				'return'       => 'objects',
			)
		);
		$total = 0.0;
		foreach ( is_array( $refunds ) ? $refunds : array() as $refund ) {
			if ( ! $refund instanceof \WC_Order_Refund ) {
				continue;
			}
			$parent = wc_get_order( $refund->get_parent_id() );
			if ( ! $parent instanceof \WC_Order || Overview::is_test_order( $parent ) ) {
				continue;
			}
			$total += abs( (float) $refund->get_amount() );
		}
		return round( $total, 2 );
	}

	/**
	 * 访客追踪的数字（表没装时全部为 0）。
	 *
	 * @param array<int,true> $paid_ids 本时间段已付款（非测试）订单的 ID => true。
	 * @return array{visitors:int,mobile:int,avg_duration:int,users:int[],funnel:array<string,int>,returning:array<string,int>,returning_paid:int}
	 */
	private static function visitor_numbers( int $from, int $to, array $paid_ids ): array {
		global $wpdb;
		$zero = array(
			'visitors' => 0,
			'viewed'   => 0,
			'carted'   => 0,
			'checkout' => 0,
		);
		$out  = array(
			'visitors'       => 0,
			'mobile'         => 0,
			'avg_duration'   => 0,
			'users'          => array(),
			'funnel'         => $zero,
			'returning'      => $zero,
			'returning_paid' => 0,
		);
		if ( ! Schema::installed() ) {
			return $out;
		}
		$visits   = Schema::table( 'visits' );
		$visitors = Schema::table( 'visitors' );
		$fm       = $from * 1000;
		$tm       = $to * 1000;

		// 访问用户、移动端、平均访问时长和漏斗前四层一次查完。时长先转成有符号数再相减（无符号相减出现负数时 MySQL 会报错）。
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_id) AS visitors, COUNT(DISTINCT CASE WHEN device = 'mobile' THEN visitor_id END) AS mobile, AVG(CAST(last_ms AS SIGNED) - CAST(start_ms AS SIGNED)) AS avg_ms, COUNT(DISTINCT CASE WHEN stage >= 1 THEN visitor_id END) AS viewed, COUNT(DISTINCT CASE WHEN stage >= 2 THEN visitor_id END) AS carted, COUNT(DISTINCT CASE WHEN stage >= 3 THEN visitor_id END) AS checkout FROM {$visits} WHERE start_ms >= %d AND start_ms < %d", $fm, $tm ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( is_array( $row ) ) {
			$out['visitors']     = (int) $row['visitors'];
			$out['mobile']       = (int) $row['mobile'];
			$out['avg_duration'] = null === $row['avg_ms'] ? 0 : (int) round( (float) $row['avg_ms'] / 1000 );
			$out['funnel']       = array(
				'visitors' => (int) $row['visitors'],
				'viewed'   => (int) $row['viewed'],
				'carted'   => (int) $row['carted'],
				'checkout' => (int) $row['checkout'],
			);
		}

		// 顾客数里“登录访问”的部分：时间段内关联了账号的访问。
		$users        = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$visits} WHERE start_ms >= %d AND start_ms < %d AND user_id > 0", $fm, $tm ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out['users'] = array_map( 'intval', (array) $users );

		// 回访的访问（和概览页同一个判断）：第一次访问当天结束（店铺时区 23:59:59）之后，再过“回访用户计算时间”（默认 1 天）再来的访问。
		// 当天结束 = 按店铺时区取整到天的下一个 0 点；时区偏移按现在的值计算（夏令时切换当天最多差 1 小时）。
		$gap       = max( 1, (int) Store_Config::get( 'returning_hours' ) ) * HOUR_IN_SECONDS * 1000;
		$off       = wp_timezone()->getOffset( new \DateTimeImmutable() ) * 1000;
		$returning = "FROM {$visits} s INNER JOIN {$visitors} v ON v.id = s.visitor_id WHERE s.start_ms >= %d AND s.start_ms < %d AND s.start_ms >= ( FLOOR( ( v.first_ms + %d ) / 86400000 ) + 1 ) * 86400000 - %d + %d";
		$row       = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(DISTINCT s.visitor_id) AS visitors, COUNT(DISTINCT CASE WHEN s.stage >= 1 THEN s.visitor_id END) AS viewed, COUNT(DISTINCT CASE WHEN s.stage >= 2 THEN s.visitor_id END) AS carted, COUNT(DISTINCT CASE WHEN s.stage >= 3 THEN s.visitor_id END) AS checkout {$returning}", $fm, $tm, $off, $off, $gap ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( is_array( $row ) ) {
			$out['returning'] = array(
				'visitors' => (int) $row['visitors'],
				'viewed'   => (int) $row['viewed'],
				'carted'   => (int) $row['carted'],
				'checkout' => (int) $row['checkout'],
			);
		}

		// 回访漏斗的支付成功：回访的访问里下的订单，且是本时间段的已付款（非测试）订单，去重。
		$order_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT s.order_id {$returning} AND s.order_id > 0", $fm, $tm, $off, $off, $gap ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $order_ids as $id ) {
			if ( isset( $paid_ids[ (int) $id ] ) ) {
				++$out['returning_paid'];
			}
		}
		return $out;
	}

	/**
	 * 五层漏斗：数量、占上一层 %、占访问用户 %。转化率详情页（Data\Conversion）的每张分组卡片也用这个方法，所以是 public。
	 *
	 * @param array<string,int> $counts LAYERS 的 key => 数量。
	 * @return array<int,array{key:string,count:int,step_rate:?float,visitor_rate:?float}>
	 */
	public static function layers( array $counts ): array {
		$first = (int) ( $counts['visitors'] ?? 0 );
		$prev  = null;
		$out   = array();
		foreach ( self::LAYERS as $key ) {
			$count = (int) ( $counts[ $key ] ?? 0 );
			$out[] = array(
				'key'          => $key,
				'count'        => $count,
				'step_rate'    => $prev ? round( $count / $prev * 100, 2 ) : null,
				'visitor_rate' => $first ? round( $count / $first * 100, 2 ) : null,
			);
			$prev  = $count;
		}
		return $out;
	}

	/** 本期、对比期和每层数量的变化。 */
	private static function compare( array $current, array $previous ): array {
		$change = array();
		foreach ( $current as $i => $layer ) {
			$change[ $layer['key'] ] = Range::change( (float) $layer['count'], (float) ( $previous[ $i ]['count'] ?? 0 ) );
		}
		return array(
			'current'  => $current,
			'previous' => $previous,
			'change'   => $change,
		);
	}
}
