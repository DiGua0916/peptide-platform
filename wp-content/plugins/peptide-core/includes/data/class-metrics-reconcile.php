<?php
/**
 * 新旧口径对账（M6，开发文档 12.5：“新旧报表差异都能解释”）。只读，给脱敏快照演练和影子模式排查用：
 *
 *   wp eval 'print_r( PeptideCore\Data\Metrics_Reconcile::report( "30d" ) );'
 *   wp eval-file wp-content/plugins/peptide-core/tests/m6-reconcile-report-smoke.php
 *
 * 把每一张订单在旧口径（概览页 / 运营数据：时间段内创建、已付款状态、去掉测试付款和演示商品、减 WooCommerce 退款）
 * 和新口径（Data\Metrics：时间段内付款、去掉全部测试单、减已标记退款）下算不算、算多少，逐张比，
 * 不一样的归到一个原因里。归不进任何原因的进 other —— other 不是空的，就说明还有没解释的差异。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

use PeptideCore\Overview;
use PeptideCore\Recovery;
use PeptideCore\Tracking\Schema;

defined( 'ABSPATH' ) || exit;

final class Metrics_Reconcile {

	/** 差异原因 => 白话说明。 */
	public const REASONS = array(
		'test_order_rule'       => '新口径认定是测试单、旧口径当成真实订单。2026-11-11 起两条规则相同，这一项正常是空的；有订单落在这里说明两条规则又分开了。',
		'paid_outside_range'    => '时间段内创建、但不是在这个时间段付的款：旧口径按创建时间算，新口径按付款时间算（算在付款那天）。',
		'created_outside_range' => '时间段内付的款、但订单是之前创建的：旧口径算在创建那天，新口径算在付款这天。',
		'refunded_status'       => '状态是已退款、但付过款的订单：旧口径不算订单，新口径算订单，金额减去已标记的退款。',
		'refund_basis'          => '退款口径：旧口径减 WooCommerce 里记的退款，新口径减总后台标记的退款（_pc_refund_cents）。',
		'other'                 => '没有解释的差异，需要人工看这些订单。',
	);

	/**
	 * 纯计算：逐张订单比较。
	 *
	 * @param array<int,array<string,mixed>> $rows 每张订单：id、legacy / metrics（两种口径算不算）、legacy_cents / metrics_cents、
	 *                                             created_in、paid_in、status_paid、test、legacy_test。
	 * @return array{legacy:array,metrics:array,delta:array,reasons:array,unexplained:array,explained:bool}
	 */
	public static function classify( array $rows ): array {
		$legacy  = array( 'orders' => 0, 'sales_cents' => 0 );
		$metrics = array( 'orders' => 0, 'sales_cents' => 0 );
		$reasons = array();
		foreach ( $rows as $row ) {
			$in_legacy     = ! empty( $row['legacy'] );
			$in_metrics    = ! empty( $row['metrics'] );
			$legacy_cents  = $in_legacy ? (int) ( $row['legacy_cents'] ?? 0 ) : 0;
			$metrics_cents = $in_metrics ? (int) ( $row['metrics_cents'] ?? 0 ) : 0;
			if ( $in_legacy ) {
				++$legacy['orders'];
				$legacy['sales_cents'] += $legacy_cents;
			}
			if ( $in_metrics ) {
				++$metrics['orders'];
				$metrics['sales_cents'] += $metrics_cents;
			}
			if ( $in_legacy === $in_metrics && $legacy_cents === $metrics_cents ) {
				continue;
			}
			$reason = self::reason( $row, $in_legacy, $in_metrics );
			if ( ! isset( $reasons[ $reason ] ) ) {
				$reasons[ $reason ] = array( 'orders' => 0, 'cents' => 0, 'ids' => array(), 'why' => self::REASONS[ $reason ] );
			}
			$reasons[ $reason ]['orders'] += (int) $in_metrics - (int) $in_legacy;
			$reasons[ $reason ]['cents']  += $metrics_cents - $legacy_cents;
			if ( count( $reasons[ $reason ]['ids'] ) < 50 ) {
				$reasons[ $reason ]['ids'][] = (int) ( $row['id'] ?? 0 );
			}
		}
		$unexplained = $reasons['other'] ?? array( 'orders' => 0, 'cents' => 0, 'ids' => array(), 'why' => self::REASONS['other'] );
		return array(
			'legacy'      => $legacy,
			'metrics'     => $metrics,
			'delta'       => array( 'orders' => $metrics['orders'] - $legacy['orders'], 'sales_cents' => $metrics['sales_cents'] - $legacy['sales_cents'] ),
			'reasons'     => $reasons,
			'unexplained' => $unexplained,
			'explained'   => ! $unexplained['ids'],
		);
	}

	private static function reason( array $row, bool $in_legacy, bool $in_metrics ): string {
		if ( $in_legacy && ! $in_metrics ) {
			if ( ! empty( $row['test'] ) && empty( $row['legacy_test'] ) ) {
				return 'test_order_rule';
			}
			return empty( $row['paid_in'] ) ? 'paid_outside_range' : 'other';
		}
		if ( $in_metrics && ! $in_legacy ) {
			if ( empty( $row['created_in'] ) ) {
				return 'created_outside_range';
			}
			return empty( $row['status_paid'] ) ? 'refunded_status' : 'other';
		}
		return 'refund_basis'; // 两边都算，只是金额不同。
	}

	/** 一张订单在两种口径下的样子（classify() 的一行）。 */
	public static function row( \WC_Order $order, int $from, int $to ): array {
		$fact        = Metrics::order_fact( $order );
		$created_in  = $fact['created_at'] >= $from && $fact['created_at'] < $to;
		$paid_in     = $fact['paid'] && $fact['paid_at'] >= $from && $fact['paid_at'] < $to;
		$status_paid = in_array( $fact['status'], wc_get_is_paid_statuses(), true );
		$legacy_test = Overview::is_test_order( $order );
		return array(
			'id'            => $fact['id'],
			'created_in'    => $created_in,
			'paid_in'       => $paid_in,
			'status_paid'   => $status_paid,
			'test'          => $fact['test'],
			'legacy_test'   => $legacy_test,
			'legacy'        => $created_in && $status_paid && ! $legacy_test,
			'legacy_cents'  => $fact['gross_cents'] - $fact['wc_refund_cents'],
			'metrics'       => $paid_in && ! $fact['test'],
			'metrics_cents' => $fact['net_cents'],
		);
	}

	/** 订单数和销售额的对账：[from, to)，Unix 秒。 */
	public static function orders( int $from, int $to ): array {
		$rows = array();
		if ( $to > $from && function_exists( 'wc_get_orders' ) ) {
			// 旧口径的候选：时间段内创建、已付款状态。
			$created = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'status'       => array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() ),
					'date_created' => $from . '...' . ( $to - 1 ),
					'limit'        => -1,
					'return'       => 'objects',
				)
			);
			foreach ( is_array( $created ) ? $created : array() as $order ) {
				if ( $order instanceof \WC_Order ) {
					$rows[ $order->get_id() ] = self::row( $order, $from, $to );
				}
			}
			// 新口径的候选：时间段内付款。
			foreach ( Metrics::order_facts( $from, $to ) as $fact ) {
				if ( isset( $rows[ $fact['id'] ] ) ) {
					continue;
				}
				$order = wc_get_order( $fact['id'] );
				if ( $order instanceof \WC_Order ) {
					$rows[ $fact['id'] ] = self::row( $order, $from, $to );
				}
			}
		}
		return self::classify( array_values( $rows ) );
	}

	/** 访客数的对账：旧口径不看 is_test、也不要求访客行还在。 */
	public static function visitors( int $from, int $to ): array {
		global $wpdb;
		$out = array( 'legacy' => 0, 'metrics' => 0, 'delta' => 0, 'reasons' => array(), 'unexplained' => 0, 'explained' => true );
		if ( $to <= $from || ! Schema::installed() ) {
			return $out;
		}
		$visits   = Schema::table( 'visits' );
		$visitors = Schema::table( 'visitors' );
		$fm       = $from * 1000;
		$tm       = $to * 1000;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$legacy  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_id) FROM {$visits} WHERE start_ms >= %d AND start_ms < %d", $fm, $tm ) );
		$orphans = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT s.visitor_id) FROM {$visits} s LEFT JOIN {$visitors} v ON v.id = s.visitor_id WHERE s.start_ms >= %d AND s.start_ms < %d AND v.id IS NULL", $fm, $tm ) );
		$tests   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT s.visitor_id) FROM {$visits} s INNER JOIN {$visitors} v ON v.id = s.visitor_id WHERE s.start_ms >= %d AND s.start_ms < %d AND ( s.is_test <> 0 OR v.is_test <> 0 ) AND s.visitor_id NOT IN ( SELECT s2.visitor_id FROM {$visits} s2 INNER JOIN {$visitors} v2 ON v2.id = s2.visitor_id WHERE s2.start_ms >= %d AND s2.start_ms < %d AND s2.is_test = 0 AND v2.is_test = 0 )", $fm, $tm, $fm, $tm ) );
		// phpcs:enable
		$metrics = Metrics::visitors( $from, $to );
		$out     = array(
			'legacy'      => $legacy,
			'metrics'     => $metrics,
			'delta'       => $metrics - $legacy,
			'reasons'     => array(
				'orphan_visits' => array( 'visitors' => -$orphans, 'why' => '访问还在、访客行已经被清掉（隐私抹除 / 清数据）：旧口径照算，新口径不算。' ),
				'test_flag'     => array( 'visitors' => -$tests, 'why' => '测试版留下的访客（is_test = 1）：旧口径照算，新口径不算。' ),
			),
			'unexplained' => $metrics - $legacy + $orphans + $tests,
		);
		$out['explained'] = 0 === $out['unexplained'];
		return $out;
	}

	/**
	 * 一个预设时间段（today / yesterday / 7d / 30d）的整份对账。转化率和召回激活率是定义变了，不逐条归因，只把两边的数放在一起。
	 */
	public static function report( string $preset = '30d', ?int $now = null ): array {
		$window   = Metrics::window( $preset, $now );
		$from     = $window['from'];
		$to       = $window['to'];
		$orders   = self::orders( $from, $to );
		$visitors = self::visitors( $from, $to );
		$summary  = Metrics::summary( $from, $to );
		$recovery = class_exists( Recovery::class ) ? Recovery::stats( $from * 1000, $to * 1000 ) : array();
		return array(
			'preset'     => $preset,
			'from'       => wp_date( 'Y-m-d H:i', $from ),
			'to'         => wp_date( 'Y-m-d H:i', $to - 1 ),
			'orders'     => $orders,
			'visitors'   => $visitors,
			'conversion' => array(
				'legacy'  => Metrics::rate( (int) $orders['legacy']['orders'], (int) $visitors['legacy'] ),
				'metrics' => $summary['conversion_rate'],
				'why'     => '定义变了：旧口径是 订单数 ÷ 访客，新口径是 支付成功访客 ÷ 访客（一人多单只算一人；访问里没有填地址记录的不算）。',
			),
			'recovery'   => array(
				'legacy'  => array( 'emailed' => (int) ( $recovery['emailed'] ?? 0 ), 'recovered' => (int) ( $recovery['recovered'] ?? 0 ), 'rate' => Metrics::rate( (int) ( $recovery['recovered'] ?? 0 ), (int) ( $recovery['emailed'] ?? 0 ) ) ),
				'metrics' => array( 'emailed' => $summary['recall_emailed'], 'recovered' => $summary['recall_recovered'], 'rate' => $summary['recall_rate'] ),
				'why'     => '数据源变了：旧口径读弃单邮件序列表（pc_recovery，一次结账一条），新口径读弃单列表（Recovery_Items，一位顾客一条，付款帮助信和手写信也算召回）。',
			),
			'explained'  => $orders['explained'] && $visitors['explained'],
		);
	}
}
