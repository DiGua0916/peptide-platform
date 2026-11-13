<?php
/**
 * 转化率详情页（DATA-01 第 4 步）：整体转化步骤（本期、对比期、变化）和按设备或浏览器分组的漏斗卡片；口径见 Shopname“转化率详情”页。
 *
 * 时间段是 [from, to)（Unix 秒，店铺时区由 Data\Range 换算好）；访问按 start_ms（毫秒）落在时间段内计算。
 *
 * 整体（见 OVERALL，顺序 = Shopname 的顺序），数字直接取自 Operations::period()，所以和运营数据页永远一致：
 * - conversion 转化率 = kpis['conversion']（已付款订单 / 访问用户 × 100，没有访问用户时为 null）；paid 支付成功 = kpis['orders'];
 * - visitors 访问用户、viewed 查看商品、carted 加购商品、checkout 发起结账 = 漏斗对应层的 count。
 * - 本期和对比期各算一遍；变化：conversion 是百分点差 round( 本期 - 对比期, 2 )，其余用 Range::change()；任何一边是 null 时变化是 null。
 *
 * 分组（只算本期，和 Shopname 一样；见 GROUPS，默认 device）。每个分组值一行：
 * - 访问的分组值：device = 访问的 device；browser = 访问所属访客的 browser（访客行缺失时按 ''）；空字符串一律归为 unknown。
 * - 已付款订单的分组值：device = 订单 _pc_track_visit 对应访问的 device（访问行存在但 device 为 '' 时是 unknown，不再往访客找），
 *   只有访问行缺失（没有这个 meta 或行已删除）时才用 _pc_track_visitor 对应访客的 device；browser = 访客的 browser；都没有就是 unknown。
 * - visitors / viewed / carted / checkout：这个分组的访问里 stage ≥ 0 / 1 / 2 / 3 的不同访客数（和运营数据的漏斗一样）；
 *   paid：这个分组的已付款非测试订单数；sales：这些订单的 订单金额 - 已退款金额 之和（和运营数据的销售额一样）；
 *   conversion = paid / visitors × 100，保留 2 位，没有访问用户时为 null；funnel = Operations::layers()。
 * - visitors 或 paid 不为 0 的分组值才出现；排序：visitors 从多到少，再 paid 从多到少，再 key 从小到大（strcmp）；
 *   所有分组的 paid 之和 = 整体的 paid。访客追踪的表没装时：tracking 为 false，访问数字都是 0，每个已付款订单都归在 unknown。
 *
 * checkout_steps 兄弟字段单独报告收到的结账步骤和支付通道，不改变上面的旧漏斗口径。
	 * UTM 来源、入口域名和已观察国家采用独立的原访问事实路径；缺失、空值、未观察不混为直接来源。
	 * 操作系统和屏幕尺寸来自新页面观察；正常/异常分类仍没有采集。分组不筛选整体或结账观察。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

use PeptideCore\Api\Errors;
use PeptideCore\Overview;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;

defined( 'ABSPATH' ) || exit;

final class Conversion {

	/** 整体转化步骤，顺序 = Shopname 的顺序（页面和测试都依赖这个顺序）。 */
	public const OVERALL = array( 'conversion', 'paid', 'visitors', 'viewed', 'carted', 'checkout' );

	/** 可以分组的维度；第一个是默认值。 */
	public const GROUPS = array( 'device', 'browser', 'utm', 'domain', 'country', 'os', 'screen', 'channel' );

	/**
	 * 转化率详情页的全部数字：整体步骤（本期、对比期、变化）和分组卡片（本期）。
	 *
	 * @param array  $range A successful Range::from_params() result.
	 * @param string $group One of GROUPS; anything else is treated as 'device'.
	 */
	public static function summary( array $range, string $group, string $visitor_status = 'all' ): array {
		if ( $visitor_status !== 'all' ) { $out = Classified_Conversion::summary( $range, $group, $visitor_status ); if ( 'channel' === $group ) { $out['channel_policy'] = Report_Dimensions::CHANNEL_POLICY; } return $out; }
		$group    = in_array( $group, self::GROUPS, true ) ? $group : 'device';
		if ( isset( Dimension_Reports::GROUPS[$group] ) ) { $out = Dimension_Conversion::summary( $range, $group ); if ( 'channel' === $group ) { $out['channel_policy'] = Report_Dimensions::CHANNEL_POLICY; } return $out; }
		$from     = (int) $range['from'];
		$to       = (int) $range['to'];
		$current  = self::overall( Operations::period( $from, $to ) );
		$previous = self::overall( Operations::period( (int) $range['compare_from'], (int) $range['compare_to'] ) );
		$reader = new Visitor_Funnel();
		$detail = $reader->period( $from * 1000, $to * 1000, 'start_ms', $group );
		$before = $reader->period( (int) $range['compare_from'] * 1000, (int) $range['compare_to'] * 1000 );
		$rows = self::rows( $from, $to, $group );
		foreach ( $rows as &$row ) {
			$row['visitor_funnel'] = $detail['groups'][ $row['key'] ] ?? array( 'unit' => 'visitors', 'layers' => Visitor_Funnel::layers( Visitor_Funnel::zero() ) );
			// M6（NF-02）：分组转化率按开关走。关着时算出来还是 rows() 里的 订单数 ÷ 访客；开了用这一组漏斗里“支付成功”的访客数做分子。
			if ( class_exists( Metrics_Overlay::class ) ) {
				$paid_uv           = (int) ( array_column( $row['visitor_funnel']['layers'], 'count', 'key' )['paid'] ?? 0 );
				$row['conversion'] = Metrics_Overlay::conversion( (int) $row['paid'], $paid_uv, (int) $row['visitors'], 'conversion.' . $group );
			}
		}
		unset( $row );

		$change = array();
		foreach ( self::OVERALL as $key ) {
			$c = $current[ $key ];
			$p = $previous[ $key ];
			if ( null === $c || null === $p ) {
				$change[ $key ] = null;
			} elseif ( 'conversion' === $key ) {
				// 转化率本身是百分比，变化用百分点差。
				$change[ $key ] = round( (float) $c - (float) $p, 2 );
			} else {
				$change[ $key ] = Range::change( (float) $c, (float) $p );
			}
		}

		return array(
			'range'    => $range,
			'tracking' => Schema::installed(),
			'group'    => $group,
			'groups'   => self::GROUPS,
			'overall'  => array(
				'current'  => $current,
				'previous' => $previous,
				'change'   => $change,
			),
			'rows'     => $rows,
			'visitor_funnel' => Visitor_Funnel::compare( $detail['overall'], $before['overall'] ),
			'checkout_steps' => Checkout_Steps::summary( $range ),
		);
	}

	/**
	 * 校验 group 参数：没传或为空 = device；在 GROUPS 里就是它；其他（包括数组、布尔）返回 pc_invalid_param。
	 *
	 * @param array $params Request parameters.
	 * @return string|\WP_Error 分组的 key。
	 */
	public static function params( array $params ) {
		$raw = $params['group'] ?? '';
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return Errors::invalid( array( 'group' => 'invalid_format' ) );
		}
		$text = trim( (string) $raw );
		if ( '' === $text ) {
			return self::GROUPS[0];
		}
		return in_array( $text, self::GROUPS, true ) ? $text : Errors::invalid( array( 'group' => 'invalid_format' ) );
	}

	/** Explicit query state; the existing group-only params return contract is unchanged. */
	public static function classification_param( array $params ) {
		if ( ! array_key_exists( 'visitor_status', $params ) ) { return 'all'; }
		$value = $params['visitor_status'];
		return is_string( $value ) && in_array( $value, array( 'all', 'normal', 'abnormal', 'unclassified' ), true ) ? $value : Errors::invalid( array( 'visitor_status' => 'invalid_format' ) );
	}

	/**
	 * 一个时间段的整体步骤（Operations::period() 的结果换成 OVERALL 的六个数字）。
	 *
	 * @param array $period Operations::period() 的结果。
	 * @return array{conversion:?float,paid:int,visitors:int,viewed:int,carted:int,checkout:int}
	 */
	public static function overall( array $period ): array {
		$counts = array();
		foreach ( (array) ( $period['funnel'] ?? array() ) as $layer ) {
			$counts[ $layer['key'] ] = (int) $layer['count'];
		}
		$conversion = $period['kpis']['conversion'] ?? null;
		return array(
			'conversion' => null === $conversion ? null : (float) $conversion,
			'paid'       => (int) ( $period['kpis']['orders'] ?? 0 ),
			'visitors'   => $counts['visitors'] ?? 0,
			'viewed'     => $counts['viewed'] ?? 0,
			'carted'     => $counts['carted'] ?? 0,
			'checkout'   => $counts['checkout'] ?? 0,
		);
	}

	/**
	 * 分组卡片：每个分组值一行，已按 visitors、paid、key 排好序。
	 *
	 * @return array<int,array{key:string,conversion:?float,sales:float,visitors:int,paid:int,funnel:array}>
	 */
	private static function rows( int $from, int $to, string $group ): array {
		$stats = self::visit_groups( $from, $to, $group );
		foreach ( self::paid_groups( $from, $to, $group ) as $key => $paid ) {
			if ( ! isset( $stats[ $key ] ) ) {
				$stats[ $key ] = self::empty_stats();
			}
			$stats[ $key ]['paid']  = $paid['paid'];
			$stats[ $key ]['sales'] = $paid['sales'];
		}

		$rows = array();
		foreach ( $stats as $key => $s ) {
			if ( ! $s['visitors'] && ! $s['paid'] ) {
				continue;
			}
			$rows[] = array(
				'key'        => (string) $key,
				'conversion' => $s['visitors'] ? round( $s['paid'] / $s['visitors'] * 100, 2 ) : null,
				'sales'      => round( $s['sales'], 2 ),
				'visitors'   => $s['visitors'],
				'paid'       => $s['paid'],
				'funnel'     => Operations::layers(
					array(
						'visitors' => $s['visitors'],
						'viewed'   => $s['viewed'],
						'carted'   => $s['carted'],
						'checkout' => $s['checkout'],
						'paid'     => $s['paid'],
					)
				),
			);
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$cmp = $b['visitors'] <=> $a['visitors'];
				if ( 0 === $cmp ) {
					$cmp = $b['paid'] <=> $a['paid'];
				}
				return 0 !== $cmp ? $cmp : strcmp( $a['key'], $b['key'] );
			}
		);
		return $rows;
	}

	/** @return array{visitors:int,viewed:int,carted:int,checkout:int,paid:int,sales:float} */
	private static function empty_stats(): array {
		return array(
			'visitors' => 0,
			'viewed'   => 0,
			'carted'   => 0,
			'checkout' => 0,
			'paid'     => 0,
			'sales'    => 0.0,
		);
	}

	/** 分组值：空字符串算 unknown（设备和浏览器的原始值里不会有 'unknown'，所以换名不会把两行合并）。 */
	private static function group_key( string $raw ): string {
		return '' === $raw ? 'unknown' : $raw;
	}

	/**
	 * 时间段内的访问按分组统计访客数（表没装时没有任何分组）。
	 *
	 * @return array<string,array{visitors:int,viewed:int,carted:int,checkout:int,paid:int,sales:float}>
	 */
	private static function visit_groups( int $from, int $to, string $group ): array {
		global $wpdb;
		if ( ! Schema::installed() ) {
			return array();
		}
		$visits   = Schema::table( 'visits' );
		$visitors = Schema::table( 'visitors' );
		if ( 'browser' === $group ) {
			$join = "LEFT JOIN {$visitors} v ON v.id = s.visitor_id";
			$col  = "COALESCE( v.browser, '' )";
		} else {
			$join = '';
			$col  = 's.device';
		}

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$col} AS g, COUNT(DISTINCT s.visitor_id) AS visitors, COUNT(DISTINCT CASE WHEN s.stage >= 1 THEN s.visitor_id END) AS viewed, COUNT(DISTINCT CASE WHEN s.stage >= 2 THEN s.visitor_id END) AS carted, COUNT(DISTINCT CASE WHEN s.stage >= 3 THEN s.visitor_id END) AS checkout FROM {$visits} s {$join} WHERE s.start_ms >= %d AND s.start_ms < %d GROUP BY {$col}", $from * 1000, $to * 1000 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$stats             = self::empty_stats();
			$stats['visitors'] = (int) $row['visitors'];
			$stats['viewed']   = (int) $row['viewed'];
			$stats['carted']   = (int) $row['carted'];
			$stats['checkout'] = (int) $row['checkout'];
			$out[ self::group_key( (string) $row['g'] ) ] = $stats;
		}
		return $out;
	}

	/**
	 * 时间段内的已付款非测试订单按分组统计订单数和销售额。
	 *
	 * @return array<string,array{paid:int,sales:float}>
	 */
	public static function paid_groups( int $from, int $to, string $group ): array {
		$orders = array();
		foreach ( Operations::paid_orders( $from, $to ) as $order ) {
			if ( self::is_test( $order ) ) {
				continue;
			}
			// meta 值 0 表示“没有”（没带追踪信息的订单）。
			$orders[] = array(
				'sales'   => self::sales( $order ),
				'visitor' => (int) $order->get_meta( Server_Events::ORDER_VISITOR_META ),
				'visit'   => (int) $order->get_meta( Server_Events::ORDER_VISIT_META ),
			);
		}
		// New dimensions use only the exact saved order visit and its owner. No legacy fallback.
		if ( isset( Dimension_Reports::GROUPS[$group] ) ) {
			$dimension = Dimension_Reports::GROUPS[$group];
			$facts = Schema::installed() ? Report_Dimensions::visit_facts( array_column( $orders, 'visit' ), $to * 1000 ) : array(); $out = array();
			foreach ( $orders as $order ) {
				$fact = Report_Dimensions::for_visit( $facts, $order['visit'], $order['visitor'] ); $typed = $fact[$dimension]; $key = Report_Dimensions::key( $typed );
				if ( ! isset( $out[$key] ) ) { $out[$key] = array( 'paid' => 0, 'sales' => 0.0, 'dimension' => $typed ); }
				++$out[$key]['paid']; $out[$key]['sales'] += $order['sales'];
			}
			return $out;
		}

		// 需要的访问 / 访客行一次查出来（最多两条查询）。
		$by_visit   = array();
		$by_visitor = array();
		if ( $orders && Schema::installed() ) {
			if ( 'device' === $group ) {
				$by_visit = self::lookup( 'visits', 'device', array_column( $orders, 'visit' ) );
				// 只有访问行缺失的订单才去看访客的设备。
				$need = array();
				foreach ( $orders as $o ) {
					if ( $o['visit'] <= 0 || ! isset( $by_visit[ $o['visit'] ] ) ) {
						$need[] = $o['visitor'];
					}
				}
				$by_visitor = self::lookup( 'visitors', 'device', $need );
			} else {
				$by_visitor = self::lookup( 'visitors', 'browser', array_column( $orders, 'visitor' ) );
			}
		}

		$out = array();
		foreach ( $orders as $o ) {
			$raw = '';
			if ( 'device' === $group && $o['visit'] > 0 && isset( $by_visit[ $o['visit'] ] ) ) {
				$raw = $by_visit[ $o['visit'] ];
			} elseif ( $o['visitor'] > 0 && isset( $by_visitor[ $o['visitor'] ] ) ) {
				$raw = $by_visitor[ $o['visitor'] ];
			}
			$key = self::group_key( $raw );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'paid'  => 0,
					'sales' => 0.0,
				);
			}
			++$out[ $key ]['paid'];
			$out[ $key ]['sales'] += $o['sales'];
		}
		return $out;
	}

	/**
	 * 按 id 取追踪表里的一列：id => 值。IN 列表只由 intval 过的 id 组成；没有 id 时不查询。
	 *
	 * @param string $name   Schema::table() 的名字（visits / visitors）。
	 * @param string $column device 或 browser（只由本类传入固定值）。
	 * @param int[]  $ids
	 * @return array<int,string>
	 */
	private static function lookup( string $name, string $column, array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( 'SELECT id, ' . $column . ' AS g FROM ' . Schema::table( $name ) . " WHERE id IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = (string) $row['g'];
		}
		return $out;
	}

	/** M6（NF-02）：测试单规则按开关走 Metrics_Overlay::is_test_order()（关着时就是 Overview 的旧规则）；不带自动加载的纯逻辑测试里没有这个类，直接用旧规则。 */
	private static function is_test( $order ): bool {
		return class_exists( Metrics_Overlay::class ) ? Metrics_Overlay::is_test_order( $order ) : \PeptideCore\Overview::is_test_order( $order );
	}

	/** M6（NF-02）：一张订单的销售额按开关走 Metrics_Overlay::order_sales()（关着时 = 订单金额 − WooCommerce 退款，和以前一样）。 */
	private static function sales( $order ): float {
		return class_exists( Metrics_Overlay::class ) ? Metrics_Overlay::order_sales( $order ) : (float) $order->get_total() - (float) $order->get_total_refunded();
	}
}
