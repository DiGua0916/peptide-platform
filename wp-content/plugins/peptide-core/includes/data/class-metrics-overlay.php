<?php
/**
 * 把旧报表的数字按 M6 开关换成 Data\Metrics 的数字（开发文档 6.1：“逐个改为调用 Data\Metrics”）。
 *
 * 每个接入点一个函数：传入旧算法已经算好的结果，返回页面该显示的结果。
 * - 开关关着：原样返回，Metrics 一次都不调用；
 * - 影子：原样返回，同时用 Metrics 再算一遍，不一致写进对账日志（Metrics_Switch::log()）；
 * - 开：把核心数字换成 Metrics 的；Metrics 出错时原样返回。
 *
 * 已接入：
 * - overview()   概览页（仪表盘）的访客、订单、销售额、客单价、转化率、八层漏斗、趋势图里的订单和销售额；
 * - recovery()   概览页的“弃单激活”；
 * - operations() Operations::period() 的访客、订单、销售额、客单价、转化率 —— 运营数据页、转化率详情的整体数字、
 *                实时数据页的“今日”、分维度转化的整体数字都从它取数，一处换全部跟着换。
 * 还没接入的报表见 docs/handoffs/m6-metrics.md。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

use PeptideCore\Overview;

defined( 'ABSPATH' ) || exit;

final class Metrics_Overlay {

	// ---------- 概览页 ----------

	/** 旧结果里参加对账的数字（金额换成美分）。客单价是销售额 ÷ 订单数推出来的，不单独比。 */
	public static function overview_legacy( array $data ): array {
		return array(
			'visitors'    => (int) ( $data['visitors'] ?? 0 ),
			'orders'      => (int) ( $data['orders'] ?? 0 ),
			'sales_cents' => Metrics::cents( $data['sales'] ?? 0 ),
			'conversion'  => isset( $data['conversion'] ) ? (float) $data['conversion'] : null,
			'steps'       => self::step_counts( (array) ( $data['steps'] ?? array() ) ),
		);
	}

	/** Metrics::summary() 里和上面对应的数字。 */
	public static function overview_modern( array $summary ): array {
		return array(
			'visitors'    => (int) $summary['visitors'],
			'orders'      => (int) $summary['orders'],
			'sales_cents' => (int) $summary['sales_cents'],
			'conversion'  => $summary['conversion_rate'],
			'steps'       => self::step_counts( (array) $summary['funnel'] ),
		);
	}

	/** 漏斗层 → key => 人数。 */
	private static function step_counts( array $layers ): array {
		$out = array();
		foreach ( $layers as $layer ) {
			if ( is_array( $layer ) && isset( $layer['key'] ) ) {
				$out[ (string) $layer['key'] ] = (int) ( $layer['count'] ?? 0 );
			}
		}
		return $out;
	}

	/**
	 * 概览页一个时间段的数据（Overview::range_data() 的结果）。
	 *
	 * @param array    $data Overview::range_data() 的返回值。
	 * @param int|null $now  当前时间（测试用）。
	 */
	public static function overview( array $data, ?int $now = null ): array {
		if ( Metrics_Switch::off() ) {
			return $data;
		}
		$range   = (string) ( $data['range'] ?? 'today' );
		$summary = null;
		Metrics_Switch::pick(
			'overview.totals',
			self::overview_legacy( $data ),
			static function () use ( $range, $now, &$summary ) {
				$computed = Metrics::preset( $range, $now );
				$compare  = self::overview_modern( $computed );
				$summary  = $computed;
				return $compare;
			},
			array( 'range' => $range, 'day' => wp_date( 'Y-m-d', $now ) )
		);
		if ( ! Metrics_Switch::on() || null === $summary ) {
			return $data;
		}
		return self::apply_overview( $data, $summary );
	}

	/** 开的时候：把概览页的核心数字换成 Metrics 的。商品排名等其他部分不动。 */
	public static function apply_overview( array $data, array $summary ): array {
		$data['visitors']    = (int) $summary['visitors'];
		$data['orders']      = (int) $summary['orders'];
		$data['sales']       = Metrics::money( (int) $summary['sales_cents'] );
		$data['aov']         = Metrics::money( (int) $summary['aov_cents'] );
		$data['conversion']  = $summary['conversion_rate'];
		$data['test_orders'] = (int) $summary['test_orders'];
		$data['steps']       = $summary['funnel'];
		if ( isset( $data['series'] ) && is_array( $data['series'] ) ) {
			$data['series'] = self::series( $data['series'], Metrics::order_facts( (int) $summary['from'], (int) $summary['to'] ), (string) ( $data['bucket'] ?? 'day' ) );
		}
		$data['metrics'] = array( 'version' => Metrics::VERSION, 'mode' => 'on' );
		return $data;
	}

	/**
	 * 趋势图：每个时间格的订单数和销售额按付款时间重算（访客数和浏览量不动）。
	 *
	 * @param array<int,array<string,mixed>> $series 概览页的时间格，每格带 start（Y-m-d H:00 或 Y-m-d）。
	 * @param array<int,array<string,mixed>> $facts  Metrics::order_facts()。
	 */
	public static function series( array $series, array $facts, string $bucket ): array {
		$index = array();
		$cents = array();
		foreach ( $series as $i => $slot ) {
			$index[ (string) ( $slot['start'] ?? '' ) ] = $i;
			$series[ $i ]['orders']                     = 0;
			$cents[ $i ]                                = 0;
		}
		foreach ( $facts as $fact ) {
			if ( empty( $fact['counted'] ) ) {
				continue;
			}
			$key = wp_date( 'hour' === $bucket ? 'Y-m-d H:00' : 'Y-m-d', (int) $fact['paid_at'] );
			if ( ! isset( $index[ $key ] ) ) {
				continue;
			}
			++$series[ $index[ $key ] ]['orders'];
			$cents[ $index[ $key ] ] += (int) $fact['net_cents'];
		}
		foreach ( $cents as $i => $value ) {
			$series[ $i ]['sales'] = Metrics::money( $value );
		}
		return $series;
	}

	/**
	 * 概览页的“弃单激活”（Overview::summary() 里的 recovery，四个时间段各一份）。
	 * 影子只对账当前选中的时间段；开的时候四个时间段都换成弃单列表（Recovery_Items）的口径。
	 */
	public static function recovery( array $summary, string $range, ?int $now = null ): array {
		if ( Metrics_Switch::off() || ! isset( $summary['recovery'] ) || ! is_array( $summary['recovery'] ) ) {
			return $summary;
		}
		$range  = in_array( $range, Overview::RANGES, true ) ? $range : 'today';
		$legacy = array(
			'emailed'   => (int) ( $summary['recovery'][ $range ]['emailed'] ?? 0 ),
			'recovered' => (int) ( $summary['recovery'][ $range ]['recovered'] ?? 0 ),
		);
		$rows   = array();
		Metrics_Switch::pick(
			'overview.recovery',
			$legacy,
			static function () use ( $range, $now, &$rows ) {
				$computed = array();
				foreach ( Metrics_Switch::on() ? Overview::RANGES : array( $range ) as $preset ) {
					$window              = Metrics::window( $preset, $now );
					$abandoned           = Metrics::abandoned( $window['from'], $window['to'] );
					$computed[ $preset ] = array(
						'emailed'   => (int) $abandoned['emailed'],
						'recovered' => (int) $abandoned['recovered'],
						'rate'      => (float) ( $abandoned['rate'] ?? 0 ),
						'amount'    => Metrics::money( (int) $abandoned['recovered_cents'] ),
					);
				}
				$rows = $computed;
				return array( 'emailed' => $computed[ $range ]['emailed'], 'recovered' => $computed[ $range ]['recovered'] );
			},
			array( 'range' => $range, 'day' => wp_date( 'Y-m-d', $now ) )
		);
		if ( Metrics_Switch::on() && count( $rows ) === count( Overview::RANGES ) ) {
			$summary['recovery'] = $rows;
		}
		return $summary;
	}

	// ---------- 运营数据 ----------

	/**
	 * Operations::period() 的 12 个指标里，访客、订单、销售额、客单价、转化率换成 Metrics 的；其余 7 个不动。
	 *
	 * @param array<string,int|float|null> $kpis Operations::period()['kpis']。
	 */
	public static function operations( array $kpis, int $from, int $to ): array {
		if ( Metrics_Switch::off() ) {
			return $kpis;
		}
		$legacy = array(
			'visitors'    => (int) ( $kpis['visitors'] ?? 0 ),
			'orders'      => (int) ( $kpis['orders'] ?? 0 ),
			'sales_cents' => Metrics::cents( $kpis['sales'] ?? 0 ),
			'conversion'  => isset( $kpis['conversion'] ) ? (float) $kpis['conversion'] : null,
		);
		$modern = null;
		Metrics_Switch::pick(
			'operations.kpis',
			$legacy,
			static function () use ( $from, $to, &$modern ) {
				$orders   = Metrics::orders( $from, $to );
				$counts   = Metrics::funnel( $from, $to )['counts'];
				$computed = array(
					'visitors'    => (int) $counts['visitors'],
					'orders'      => (int) $orders['orders'],
					'sales_cents' => (int) $orders['sales_cents'],
					'conversion'  => Metrics::rate( (int) $counts['paid'], (int) $counts['visitors'] ),
					'aov_cents'   => (int) $orders['aov_cents'],
				);
				$modern   = $computed;
				unset( $computed['aov_cents'] );
				return $computed;
			},
			array( 'from' => wp_date( 'Y-m-d H:i', $from ), 'to' => wp_date( 'Y-m-d H:i', $to - 1 ) )
		);
		if ( ! Metrics_Switch::on() || null === $modern ) {
			return $kpis;
		}
		$kpis['visitors']   = $modern['visitors'];
		$kpis['orders']     = $modern['orders'];
		$kpis['sales']      = Metrics::money( $modern['sales_cents'] );
		$kpis['aov']        = Metrics::money( $modern['aov_cents'] );
		$kpis['conversion'] = $modern['conversion'];
		return $kpis;
	}
}
