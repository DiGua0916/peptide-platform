<?php
/**
 * 每个店铺每天的订单数、销售额、访客数（店铺时区的日期），用于概览的汇总和图表。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Stats {

	/** 保存店铺返回的每天数字（同一天覆盖）。 */
	public static function store( int $site_id, array $days, string $currency ): void {
		global $wpdb;
		$t = Db::table( 'daily' );
		foreach ( $days as $d ) {
			$day = (string) ( $d['day'] ?? '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
				continue;
			}
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$t,
				array(
					'site_id'  => $site_id,
					'day'      => $day,
					'orders'   => max( 0, (int) ( $d['orders'] ?? 0 ) ),
					'revenue'  => round( (float) ( $d['revenue'] ?? 0 ), 2 ),
					'visitors' => max( 0, (int) ( $d['visitors'] ?? 0 ) ),
					'currency' => substr( sanitize_key( $currency ), 0, 8 ),
					'updated'  => time(),
				)
			);
		}
	}

	/** 最近 N 天（不含今天）平均每天几单。 */
	public static function average_orders( int $site_id, int $days ): float {
		global $wpdb;
		$t    = Db::table( 'daily' );
		$from = wp_date( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		$to   = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
		$sum  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(orders),0) FROM {$t} WHERE site_id = %d AND day BETWEEN %s AND %s", $site_id, $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return $days > 0 ? $sum / $days : 0.0;
	}

	/**
	 * 时间范围：today / yesterday / 7d / 30d → [开始日期, 结束日期]（含两端，总后台时区）。
	 *
	 * @return array{0:string,1:string}
	 */
	public static function range( string $key ): array {
		$today = wp_date( 'Y-m-d' );
		switch ( $key ) {
			case 'yesterday':
				$y = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
				return array( $y, $y );
			case '30d':
				return array( wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS ), $today );
			case '7d':
				return array( wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ), $today );
			default:
				return array( $today, $today );
		}
	}

	/**
	 * 每个店铺在这段时间的合计。
	 *
	 * @return array<int,array{orders:int,revenue:float,visitors:int,currency:string}>
	 */
	public static function per_site( string $from, string $to ): array {
		global $wpdb;
		$t   = Db::table( 'daily' );
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT site_id, SUM(orders) AS orders, SUM(revenue) AS revenue, SUM(visitors) AS visitors, MAX(currency) AS currency FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY site_id", $from, $to ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$out[ (int) $r['site_id'] ] = array(
				'orders'   => (int) $r['orders'],
				'revenue'  => (float) $r['revenue'],
				'visitors' => (int) $r['visitors'],
				'currency' => strtoupper( (string) $r['currency'] ),
			);
		}
		return $out;
	}

	/**
	 * 每天所有店铺加起来的销售额和订单（按币种分开）。
	 *
	 * @return array<string,array{orders:int,revenue:float}> 日期 => 数字（没有数据的日期也列出来，为 0）
	 */
	public static function per_day( string $from, string $to, string $currency = '' ): array {
		global $wpdb;
		$t   = Db::table( 'daily' );
		$out = array();
		for ( $d = strtotime( $from . ' 00:00:00 UTC' ); $d <= strtotime( $to . ' 00:00:00 UTC' ); $d += DAY_IN_SECONDS ) {
			$out[ gmdate( 'Y-m-d', $d ) ] = array(
				'orders'  => 0,
				'revenue' => 0.0,
			);
		}
		$where = '' !== $currency ? $wpdb->prepare( ' AND currency = %s', strtolower( $currency ) ) : '';
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(orders) AS orders, SUM(revenue) AS revenue FROM {$t} WHERE day BETWEEN %s AND %s{$where} GROUP BY day", $from, $to ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( isset( $out[ $r['day'] ] ) ) {
				$out[ $r['day'] ] = array(
					'orders'  => (int) $r['orders'],
					'revenue' => (float) $r['revenue'],
				);
			}
		}
		return $out;
	}
}
