<?php
/**
 * 漏斗推进规则（FE-05）：访问要升到“下单”（4）或“支付成功”（5），同一次访问里必须已经有填地址的事件
 * （checkout_address_completed）。没有的话只记事件、不推进阶段，并记一次异常。
 *
 * 为什么：结账页一打开就带默认的物流和支付方式，以前的数据里会出现“没填地址却到了选支付 / 已付款”，
 * 漏斗后面的层比前面的还大。口径见 docs/metrics.md 第 3 节。
 *
 * 受 M6 开关控制（Data\Metrics_Switch）：
 * - off    什么都不做，Tracker::raise_stage() 和以前一样（GREATEST 推进），不查库、不写任何东西；
 * - shadow 照旧推进，只在异常计数里记下“如果拦会拦掉多少”；
 * - on     没有填地址的记录就不推进到 4、5：最多记到 3（下了单说明到过结账页），订单号仍然挂到访问上。
 *
 * 其他规则：
 * - 低于 4 的阶段（看商品、加购、进入结账）不受限制；
 * - 测试单不推进到 4、5（漏斗只计到“发起结账”），也不算异常；
 * - 填地址的事件比下单晚到时（浏览器攒批上报），事件一到就补推进，并记一次“已补上”；
 * - 规则本身出错（查库失败等）时照旧推进：追踪不能因为这条规则丢数据。
 *
 * 异常计数有两个：counters() 是写入时按店铺日期累计的（并发时可能少记几次，只用来快速看趋势）；
 * anomalies() 按事件表现算，是准确数。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

use PeptideCore\Data\Metrics;
use PeptideCore\Data\Metrics_Switch;

defined( 'ABSPATH' ) || exit;

final class Funnel_Guard {

	/** 从这个阶段起要求有填地址的记录：4 = 下单（选定支付方式并提交）。 */
	public const GATED_STAGE = 4;

	/** 填地址事件新保存之后触发的动作：( int $visitor_id, int $visit_id, int $new )，$new = 这一批里新保存了几条。 */
	public const ACTION = 'peptide_core_tracking_address_completed';

	public const OPTION    = 'peptide_core_m6_funnel_anomalies';
	public const KEEP_DAYS = 35;

	public static function register_hooks(): void {
		add_action( self::ACTION, array( self::class, 'settle' ), 10, 3 );
	}

	/**
	 * 纯判断：要不要推进、为什么不、算不算异常。
	 *
	 * @return array{raise:bool,reason:string,anomaly:bool} reason：'' / test_order / no_address。
	 */
	public static function decide( string $mode, int $stage, bool $has_address, bool $is_test ): array {
		if ( 'off' === $mode || $stage < self::GATED_STAGE ) {
			return array( 'raise' => true, 'reason' => '', 'anomaly' => false );
		}
		if ( $is_test ) {
			return array( 'raise' => 'on' !== $mode, 'reason' => 'test_order', 'anomaly' => false );
		}
		if ( ! $has_address ) {
			return array( 'raise' => 'on' !== $mode, 'reason' => 'no_address', 'anomaly' => true );
		}
		return array( 'raise' => true, 'reason' => '', 'anomaly' => false );
	}

	/** Tracker::raise_stage() 在升到 4、5 之前问这里：true = 照常推进。 */
	public static function allow( int $visit_id, int $stage, int $order_id = 0 ): bool {
		if ( $stage < self::GATED_STAGE ) {
			return true;
		}
		$mode = Metrics_Switch::mode();
		if ( 'off' === $mode ) {
			return true;
		}
		try {
			$verdict = self::decide( $mode, $stage, self::has_address( $visit_id ), self::is_test( $order_id ) );
			if ( '' !== $verdict['reason'] ) {
				self::note( 'no_address' === $verdict['reason'] ? 'no_address_' . $stage : $verdict['reason'] );
			}
			return $verdict['raise'];
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/** 这次访问里有没有填地址的事件。 */
	public static function has_address( int $visit_id ): bool {
		global $wpdb;
		if ( $visit_id <= 0 ) {
			return false;
		}
		$events = Schema::table( 'events' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$events} WHERE visit_id = %d AND type = 'checkout_address_completed' LIMIT 1", $visit_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	private static function is_test( int $order_id ): bool {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order && Metrics::is_test_order( $order );
	}

	/**
	 * 填地址的事件到了（动作 peptide_core_tracking_address_completed）：如果这次访问里早就有下单 / 付款的服务端事件，
	 * 说明当时因为事件还没到被拦过（或者影子模式下记过一次异常），现在补上。
	 *
	 * @param int $visitor_id 访客编号（没有用到，动作的第一个参数）。
	 * @param int $visit_id   访问编号。
	 * @param int $new        这一批里新保存的填地址事件有几条（顾客改来改去时，一批里可能不止一条）。
	 */
	public static function settle( $visitor_id, $visit_id, $new = 1 ): void {
		global $wpdb;
		$visit_id = (int) $visit_id;
		if ( $visit_id <= 0 ) {
			return;
		}
		$mode = Metrics_Switch::mode();
		if ( 'off' === $mode ) {
			return;
		}
		try {
			$events = Schema::table( 'events' );
			// “已补上”只记一次：这次访问的填地址事件全都是这一批才到的（之前一条都没有）。
			// 之前就有的话，下单时已经过了规则，或者已经补过。
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE visit_id = %d AND type = 'checkout_address_completed'", $visit_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( $count < 1 ) {
				return;
			}
			$first    = $count <= max( 1, (int) $new );
			$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT type, order_id FROM {$events} WHERE visit_id = %d AND source = 'server' AND type IN ('order_placed','payment_success')", $visit_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$stage    = 0;
			$order_id = 0;
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$stage = max( $stage, 'payment_success' === $row['type'] ? 5 : 4 );
				if ( (int) $row['order_id'] > 0 ) {
					$order_id = (int) $row['order_id'];
				}
			}
			if ( ! $stage || self::is_test( $order_id ) ) {
				return;
			}
			if ( $first ) {
				self::note( 'settled' );
			}
			if ( 'on' === $mode ) {
				// 可以重复执行：阶段只升不降，这时已经有填地址的记录，allow() 会放行。
				// 两批事件同时到、各自都以为自己不是第一批时，靠这一步仍然能补上。
				Tracker::raise_stage( $visit_id, $stage, $order_id );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	// ---------- 异常计数 ----------

	/** 按店铺日期累计：no_address_4 / no_address_5（拦下或本该拦下的次数）、settled（事件晚到后补上的）、test_order。 */
	private static function note( string $key ): void {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$day = wp_date( 'Y-m-d' );
		$all[ $day ][ $key ] = (int) ( $all[ $day ][ $key ] ?? 0 ) + 1;
		if ( count( $all ) > self::KEEP_DAYS ) {
			ksort( $all );
			$all = array_slice( $all, -self::KEEP_DAYS, null, true );
		}
		update_option( self::OPTION, $all, false );
	}

	/**
	 * 最近几天的异常计数：日期 => [ no_address_4, no_address_5, settled, test_order ]，新的在前。
	 * 没补上的异常 ≈ no_address_4 + no_address_5 − settled（同一张订单下单和付款各记一次）。
	 *
	 * @return array<string,array<string,int>>
	 */
	public static function counters( int $days = 7 ): array {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		krsort( $all );
		$out = array();
		foreach ( array_slice( $all, 0, max( 1, $days ), true ) as $day => $row ) {
			$out[ (string) $day ] = array(
				'no_address_4' => (int) ( $row['no_address_4'] ?? 0 ),
				'no_address_5' => (int) ( $row['no_address_5'] ?? 0 ),
				'settled'      => (int) ( $row['settled'] ?? 0 ),
				'test_order'   => (int) ( $row['test_order'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * 准确数：时间段 [from, to)（Unix 秒）内开始的访问里，有下单 / 付款的服务端事件、却始终没有填地址事件的（测试单不算）。
	 * 不看开关，随时可以查；影子模式之前的历史数据也能查。
	 *
	 * @return array{visits:int,placed:int,paid:int,visit_ids:int[]}
	 */
	public static function anomalies( int $from, int $to ): array {
		global $wpdb;
		$out = array( 'visits' => 0, 'placed' => 0, 'paid' => 0, 'visit_ids' => array() );
		if ( $to <= $from || ! Schema::installed() ) {
			return $out;
		}
		$events = Schema::table( 'events' );
		$visits = Schema::table( 'visits' );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT e.visit_id, e.type, e.order_id FROM {$events} e INNER JOIN {$visits} s ON s.id = e.visit_id WHERE s.start_ms >= %d AND s.start_ms < %d AND e.source = 'server' AND e.type IN ('order_placed','payment_success') AND NOT EXISTS ( SELECT 1 FROM {$events} a WHERE a.visit_id = e.visit_id AND a.type = 'checkout_address_completed' ) LIMIT 5000", $from * 1000, $to * 1000 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$seen   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( self::is_test( (int) $row['order_id'] ) ) {
				continue;
			}
			$seen[ (int) $row['visit_id'] ] = true;
			++$out[ 'payment_success' === $row['type'] ? 'paid' : 'placed' ];
		}
		$out['visits']    = count( $seen );
		$out['visit_ids'] = array_slice( array_keys( $seen ), 0, 100 );
		return $out;
	}
}
