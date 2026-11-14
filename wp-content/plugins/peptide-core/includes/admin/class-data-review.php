<?php
/**
 * 数据栏目改造第 2 步（店主 2026-11-14）：复盘页（数据 → 复盘，pc-data-review）。
 *
 * 看 7 / 14 / 30 天，回答三件事：赚没赚钱、钱漏在哪一步、哪个素材该加预算 / 该关。
 * - 时间一律按美国中部时间（广告账户和客户的时区），和店铺时区无关。
 * - 花费来自素材库导入的 Meta 每日报表（pc_ad_daily_spend）；没导入时花费、ROAS、单单成本显示“—”，页面顶部提示去导入。
 * - 销售额 = 时段里付款的真实订单（处理中 / 已完成，去掉测试单）的金额减退款。
 * - 订单归到素材：下单那次访问带的素材；直接回访下单的，算到这位访客最早一次带素材的访问（首次点击）。
 * - 漏斗按访客去重，取这位访客在时段里走到最远的一步。
 *
 * 计算部分（compute / by_creative / verdict / dim_rows / conclusions）是纯函数，tests/data-review-pure.php 直接测；
 * 取数部分（load_*）只读数据库，不写任何东西。
 *
 * @package PeptideCore\Admin
 */

namespace PeptideCore\Admin;

use PeptideCore\Tracking\Schema as TS;
use PeptideCore\Creatives\Schema as CS;

defined( 'ABSPATH' ) || exit;

final class Data_Review {

	public const SLUG = 'pc-data-review';
	public const TZ   = 'America/Chicago';
	public const DAY  = 86400000;

	/** 参考水平（新手看的“正常范围”）：[下限, 上限, 名字]。 */
	public const BENCH = array(
		'view'     => array( 0.40, 0.60, '看商品' ),
		'cart'     => array( 0.15, 0.30, '加购' ),
		'checkout' => array( 0.40, 0.60, '去结账' ),
		'address'  => array( 0.60, 0.80, '填完地址' ),
		'paid'     => array( 0.60, 0.85, '付款' ),
	);

	public const RANGES = array( '7d' => 7, '14d' => 14, '30d' => 30 );

	public const DIMS = array(
		'creative'  => '素材',
		'adset'     => '广告组',
		'placement' => '版位',
		'source'    => '来源',
		'landing'   => '落地页',
		'os'        => '手机系统',
		'in_app'    => 'App 内 / 浏览器',
		'state'     => '美国各州',
		'newret'    => '新客 / 回访',
		'hour'      => '几点（美国中部）',
		'weekday'   => '星期几',
	);

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 61 );
	}

	public static function menu(): void {
		add_submenu_page( Data_Pages::SLUG, '复盘', '复盘', Data_Pages::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	// ======================================================================
	// 时间（美国中部）
	// ======================================================================

	private static function tz(): \DateTimeZone {
		return new \DateTimeZone( self::TZ );
	}

	public static function day_start( string $ymd ): int {
		return ( new \DateTimeImmutable( $ymd . ' 00:00:00', self::tz() ) )->getTimestamp() * 1000;
	}

	public static function ymd( int $ms ): string {
		return ( new \DateTimeImmutable( '@' . intdiv( $ms, 1000 ) ) )->setTimezone( self::tz() )->format( 'Y-m-d' );
	}

	private static function local( int $ms, string $format ): string {
		return ( new \DateTimeImmutable( '@' . intdiv( $ms, 1000 ) ) )->setTimezone( self::tz() )->format( $format );
	}

	/** 最近 N 天：从美国中部 N-1 天前的零点到现在。 @return array{from:int,to:int,days:int} */
	public static function range( int $days, int $now_ms ): array {
		$today = self::ymd( $now_ms );
		$from  = self::day_start( ( new \DateTimeImmutable( $today, self::tz() ) )->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' ) );
		return array( 'from' => $from, 'to' => $now_ms, 'days' => $days );
	}

	/** 前一段同样长的时间。 */
	public static function previous( array $r ): array {
		$len = $r['to'] - $r['from'];
		return array( 'from' => $r['from'] - $len, 'to' => $r['from'], 'days' => $r['days'] );
	}

	/** 时段覆盖的美国中部日期（含首尾）。 @return string[] */
	public static function days( array $r ): array {
		$out = array();
		$d   = self::ymd( $r['from'] );
		$end = self::ymd( max( $r['from'], $r['to'] - 1 ) );
		for ( $i = 0; $i < 400 && $d <= $end; $i++ ) {
			$out[] = $d;
			$d     = ( new \DateTimeImmutable( $d, self::tz() ) )->modify( '+1 day' )->format( 'Y-m-d' );
		}
		return $out;
	}

	// ======================================================================
	// 取数（只读）
	// ======================================================================

	private static function spend_installed(): bool {
		return class_exists( CS::class ) && CS::installed();
	}

	/** 每条广告每天的花费行。 */
	private static function load_spend( array $r ): array {
		global $wpdb;
		if ( ! self::spend_installed() ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ad_id, day, spend_cents, impressions, link_clicks FROM ' . CS::table( 'daily_spend' ) . ' WHERE day >= %s AND day <= %s', self::ymd( $r['from'] ), self::ymd( max( $r['from'], $r['to'] - 1 ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** 有没有导入过任何花费（决定页面要不要提示去导入）。 */
	public static function any_spend(): bool {
		global $wpdb;
		return self::spend_installed() && (bool) $wpdb->get_var( 'SELECT 1 FROM ' . CS::table( 'daily_spend' ) . ' LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** @return array<string,array{code:string,adset:string,campaign:string,name:string}> */
	public static function load_ads(): array {
		global $wpdb;
		if ( ! self::spend_installed() ) {
			return array();
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT ad_id, code, adset_id, campaign_id, ad_name FROM ' . CS::table( 'ads' ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$out[ (string) $row['ad_id'] ] = array( 'code' => (string) $row['code'], 'adset' => (string) $row['adset_id'], 'campaign' => (string) $row['campaign_id'], 'name' => (string) $row['ad_name'] );
		}
		return $out;
	}

	private const VISIT_COLS = 'v.id, v.visitor_id, v.start_ms, v.stage, v.order_id, v.utm_source, v.utm_term, v.utm_content, v.creative_code, v.placement, v.device, v.in_app, v.os, v.geo_region, v.landing_path, v.referrer_host, p.first_visit_id';

	/** 时段里开始的真实访问。 */
	private static function load_visits( array $r ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::VISIT_COLS . ' FROM ' . TS::table( 'visits' ) . ' v JOIN ' . TS::table( 'visitors' ) . ' p ON p.id = v.visitor_id WHERE v.is_test = 0 AND v.start_ms >= %d AND v.start_ms < %d', $r['from'], $r['to'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** 填完地址的访问。 @return array<int,true> */
	private static function load_address( array $visit_ids ): array {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_map( 'intval', $visit_ids ), 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			foreach ( (array) $wpdb->get_col( 'SELECT DISTINCT visit_id FROM ' . TS::table( 'events' ) . " WHERE type = 'checkout_address_completed' AND visit_id IN ({$in})" ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$out[ (int) $id ] = true;
			}
		}
		return $out;
	}

	private static function is_test_order( \WC_Order $order ): bool {
		if ( class_exists( \PeptideCore\Data\Metrics_Overlay::class ) ) {
			return \PeptideCore\Data\Metrics_Overlay::is_test_order( $order );
		}
		return class_exists( \PeptideCore\Overview::class ) && \PeptideCore\Overview::is_test_order( $order );
	}

	/** 时段里付款的真实订单，带下单那次访问和首次带素材的访问。 */
	private static function load_orders( array $r ): array {
		global $wpdb;
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$ids = wc_get_orders( array( 'status' => array( 'processing', 'completed' ), 'limit' => -1, 'type' => 'shop_order', 'date_paid' => intdiv( $r['from'], 1000 ) . '...' . intdiv( $r['to'], 1000 ), 'return' => 'ids' ) );
		$out = array();
		foreach ( (array) $ids as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o instanceof \WC_Order || self::is_test_order( $o ) ) {
				continue;
			}
			$v     = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::VISIT_COLS . ' FROM ' . TS::table( 'visits' ) . ' v JOIN ' . TS::table( 'visitors' ) . ' p ON p.id = v.visitor_id WHERE v.order_id = %d ORDER BY v.stage DESC, v.id DESC LIMIT 1', $o->get_id() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$first = null;
			if ( $v && '' === (string) $v['creative_code'] ) {
				$first = $wpdb->get_row( $wpdb->prepare( 'SELECT creative_code, utm_term FROM ' . TS::table( 'visits' ) . " WHERE visitor_id = %d AND creative_code <> '' ORDER BY start_ms ASC LIMIT 1", (int) $v['visitor_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			}
			$items = array();
			foreach ( $o->get_items() as $it ) {
				$items[] = array( 'pid' => (int) $it->get_product_id(), 'name' => (string) $it->get_name(), 'qty' => (int) $it->get_quantity(), 'total' => (float) $it->get_total() );
			}
			$paid  = $o->get_date_paid();
			$out[] = array(
				'id'      => $o->get_id(),
				'total'   => max( 0.0, (float) $o->get_total() - (float) $o->get_total_refunded() ),
				'paid'    => $paid ? $paid->getTimestamp() * 1000 : 0,
				'visit'   => $v ?: null,
				'first'   => $first ?: null,
				'items'   => $items,
			);
		}
		return $out;
	}

	/** 一段时间的原始数据。 */
	public static function load( array $r ): array {
		$visits = self::load_visits( $r );
		return array(
			'range'   => $r,
			'visits'  => $visits,
			'address' => self::load_address( array_column( $visits, 'id' ) ),
			'orders'  => self::load_orders( $r ),
			'spend'   => self::load_spend( $r ),
		);
	}

	// ======================================================================
	// 计算（纯函数）
	// ======================================================================

	/** 订单归到哪个素材 / 广告组：下单那次访问带的，没有就用首次带素材的访问。 */
	public static function order_source( array $o ): array {
		$v = $o['visit'] ?? null;
		if ( $v && '' !== (string) $v['creative_code'] ) {
			return array( (string) $v['creative_code'], (string) $v['utm_term'] );
		}
		if ( ! empty( $o['first'] ) ) {
			return array( (string) $o['first']['creative_code'], (string) $o['first']['utm_term'] );
		}
		return array( '', '' );
	}

	/** 按天的花费行摊到时段里（今天只到现在，所以今天的花费按全天算，不再按小时摊）。 */
	public static function spend_by_ad( array $rows, array $r ): array {
		$from_day = self::ymd( $r['from'] );
		$to_day   = self::ymd( max( $r['from'], $r['to'] - 1 ) );
		$out      = array();
		foreach ( $rows as $row ) {
			$day = (string) $row['day'];
			if ( $day < $from_day || $day > $to_day ) {
				continue;
			}
			$a                 = $out[ $row['ad_id'] ] ?? array( 'spend' => 0.0, 'impressions' => 0, 'clicks' => 0 );
			$a['spend']       += (int) $row['spend_cents'] / 100;
			$a['impressions'] += (int) $row['impressions'];
			$a['clicks']      += (int) $row['link_clicks'];
			$out[ (string) $row['ad_id'] ] = $a;
		}
		return $out;
	}

	/** 一段时间的全部数字。 */
	public static function compute( array $raw ): array {
		$visits  = $raw['visits'];
		$orders  = $raw['orders'];
		$spend   = $raw['spend_ad'] ?? self::spend_by_ad( $raw['spend'], $raw['range'] ); // 今日作战室按小时摊算后传进来
		$address = $raw['address'];
		$total   = array_sum( array_column( $spend, 'spend' ) );
		$clicks  = array_sum( array_column( $spend, 'clicks' ) );
		$best    = array();
		foreach ( $visits as $v ) {
			$step = (int) $v['stage'];
			$st   = $step >= 4 ? 4 : ( isset( $address[ (int) $v['id'] ] ) ? 4 : min( 3, $step ) );
			$vid  = (int) $v['visitor_id'];
			$best[ $vid ] = max( $best[ $vid ] ?? 0, $st );
		}
		$reach   = static fn( int $min ): int => count( array_filter( $best, static fn( $s ) => $s >= $min ) );
		$buyers  = array();
		foreach ( $orders as $i => $o ) {
			$buyers[ $o['visit'] ? 'v' . (int) $o['visit']['visitor_id'] : 'o' . $i ] = true;
		}
		$revenue  = array_sum( array_column( $orders, 'total' ) );
		$n        = count( $orders );
		$visitors = count( $best );
		return array(
			'range'    => $raw['range'],
			'visits'   => $visits,
			'orders'   => $orders,
			'spend_ad' => $spend,
			'spend'    => $total,
			'has_spend'=> ! empty( $spend ),
			'clicks'   => $clicks,
			'revenue'  => $revenue,
			'n_orders' => $n,
			'visitors' => $visitors,
			'funnel'   => array( 'visitors' => $visitors, 'view' => $reach( 1 ), 'cart' => $reach( 2 ), 'checkout' => $reach( 3 ), 'address' => $reach( 4 ), 'paid' => count( $buyers ) ),
			'roas'     => $total > 0 ? $revenue / $total : null,
			'cpa'      => $total > 0 && $n ? $total / $n : null,
			'aov'      => $n ? $revenue / $n : null,
			'cvr'      => $visitors ? $n / $visitors : null,
		);
	}

	/** 每天的花费 / 销售额 / 订单（美国中部日期）。 */
	public static function daily( array $p ): array {
		$out = array_fill_keys( self::days( $p['range'] ), array( 'spend' => 0.0, 'revenue' => 0.0, 'orders' => 0 ) );
		foreach ( $p['orders'] as $o ) {
			$d = self::ymd( (int) $o['paid'] );
			if ( isset( $out[ $d ] ) ) {
				$out[ $d ]['revenue'] += $o['total'];
				++$out[ $d ]['orders'];
			}
		}
		return $out;
	}

	/** 把按天花费合进 daily()。 */
	public static function daily_with_spend( array $p, array $spend_rows ): array {
		$out = self::daily( $p );
		foreach ( $spend_rows as $row ) {
			if ( isset( $out[ (string) $row['day'] ] ) ) {
				$out[ (string) $row['day'] ]['spend'] += (int) $row['spend_cents'] / 100;
			}
		}
		return $out;
	}

	/** 每个素材的数字。$creatives = code => [note, thumb]。 */
	public static function by_creative( array $p, array $ads, array $creatives ): array {
		$blank = static fn( string $code ): array => array( 'code' => $code, 'note' => (string) ( $creatives[ $code ]['note'] ?? '' ), 'thumb' => (string) ( $creatives[ $code ]['thumb'] ?? '' ), 'spend' => 0.0, 'clicks' => 0, 'visitors' => array(), 'view' => array(), 'cart' => array(), 'checkout' => array(), 'orders' => 0, 'revenue' => 0.0 );
		$rows  = array();
		foreach ( $p['spend_ad'] as $ad => $s ) {
			$code = (string) ( $ads[ $ad ]['code'] ?? '' );
			if ( '' === $code ) {
				continue;
			}
			$rows[ $code ]            = $rows[ $code ] ?? $blank( $code );
			$rows[ $code ]['spend']  += $s['spend'];
			$rows[ $code ]['clicks'] += $s['clicks'];
		}
		foreach ( $p['visits'] as $v ) {
			$code = (string) $v['creative_code'];
			if ( '' === $code ) {
				continue;
			}
			$rows[ $code ] = $rows[ $code ] ?? $blank( $code );
			$vid           = (int) $v['visitor_id'];
			$rows[ $code ]['visitors'][ $vid ] = true;
			foreach ( array( 1 => 'view', 2 => 'cart', 3 => 'checkout' ) as $min => $f ) {
				if ( (int) $v['stage'] >= $min ) {
					$rows[ $code ][ $f ][ $vid ] = true;
				}
			}
		}
		foreach ( $p['orders'] as $o ) {
			$code = self::order_source( $o )[0];
			if ( '' === $code ) {
				continue;
			}
			$rows[ $code ] = $rows[ $code ] ?? $blank( $code );
			++$rows[ $code ]['orders'];
			$rows[ $code ]['revenue'] += $o['total'];
		}
		foreach ( $rows as $k => $r ) {
			foreach ( array( 'visitors', 'view', 'cart', 'checkout' ) as $f ) {
				$rows[ $k ][ $f ] = count( $r[ $f ] );
			}
			$rows[ $k ]['roas'] = $r['spend'] > 0 ? $r['revenue'] / $r['spend'] : null;
			$rows[ $k ]['cpa']  = $r['spend'] > 0 && $rows[ $k ]['orders'] ? $r['spend'] / $rows[ $k ]['orders'] : null;
			$rows[ $k ]['land'] = $r['clicks'] > 0 ? min( 1, $rows[ $k ]['visitors'] / $r['clicks'] ) : null;
		}
		uasort( $rows, static fn( $a, $b ) => array( $b['spend'], $b['visitors'] ) <=> array( $a['spend'], $a['visitors'] ) );
		return $rows;
	}

	/** 素材最近 2 天 vs 之前 5 天（看疲劳）。$recent / $before 是 by_creative() 的结果。 */
	public static function trends( array $recent, array $before ): array {
		$out = array();
		foreach ( $recent as $code => $r ) {
			$out[ $code ] = array( 'recent' => $r['roas'], 'before' => $before[ $code ]['roas'] ?? null, 'before_orders' => (int) ( $before[ $code ]['orders'] ?? 0 ), 'recent_spend' => (float) $r['spend'] );
		}
		return $out;
	}

	/** 素材建议。@return array{0:string,1:string,2:string} [标签, 颜色, 原因] */
	public static function verdict( array $r, ?array $trend = null ): array {
		$spend = (float) $r['spend'];
		if ( $spend <= 0 ) {
			return array( '没有花费', 'gray', $r['orders'] ? sprintf( '%d 单，但这段时间没导入它的花费', $r['orders'] ) : '这段时间没导入它的花费' );
		}
		if ( $trend && null !== $trend['recent'] && null !== $trend['before'] && $trend['before'] >= 1.5 && $trend['recent'] < $trend['before'] * 0.5 && $trend['before_orders'] >= 3 && $trend['recent_spend'] >= 30 ) {
			return array( '疲劳·换素材', 'orange', sprintf( '前几天 ROAS %.1f，最近 2 天掉到 %.1f', $trend['before'], $trend['recent'] ) );
		}
		if ( $spend < 40 && $r['orders'] < 3 ) {
			return array( '样本不足', 'gray', sprintf( '才花 $%.0f、%d 单，再跑一两天看', $spend, $r['orders'] ) );
		}
		if ( 0 === (int) $r['orders'] ) {
			return array( '关掉', 'red', sprintf( '花了 $%.0f 没出单', $spend ) );
		}
		if ( null !== $r['roas'] && $r['roas'] >= 2.5 ) {
			return array( '加预算', 'green', sprintf( 'ROAS %.1f，单单成本 $%.0f', $r['roas'], (float) $r['cpa'] ) );
		}
		if ( null !== $r['roas'] && $r['roas'] < 1 ) {
			$why = $r['visitors'] && $r['cart'] / max( 1, $r['visitors'] ) < 0.06 ? '进来的人几乎不加购，素材吸引的人不对' : '加购后没成交';
			return array( '关掉', 'red', sprintf( 'ROAS %.1f，%s', $r['roas'], $why ) );
		}
		return array( '观察', 'blue', sprintf( 'ROAS %.1f，保持预算', (float) $r['roas'] ) );
	}

	/** 某个维度下一次访问 / 一个订单落在哪一行。 */
	public static function dim_key( string $dim, array $v, array $adsets = array() ): string {
		switch ( $dim ) {
			case 'adset':
				$id = (string) $v['utm_term'];
				return '' === $id ? '（没有广告参数）' : ( $adsets[ $id ] ?? ( '广告组 …' . substr( $id, -6 ) ) );
			case 'placement':
				return '' !== (string) $v['placement'] ? (string) $v['placement'] : '（没有广告参数）';
			case 'source':
				if ( '' !== (string) $v['utm_source'] ) {
					return (string) $v['utm_source'] . '（广告）';
				}
				return '' !== (string) $v['referrer_host'] ? (string) $v['referrer_host'] : '直接打开';
			case 'landing':
				$path = (string) wp_parse_url( (string) $v['landing_path'], PHP_URL_PATH );
				return '' !== $path ? $path : '/';
			case 'os':
				return '' !== (string) $v['os'] ? (string) $v['os'] : ( '' !== (string) $v['device'] ? (string) $v['device'] : '未知' );
			case 'in_app':
				return '' !== (string) $v['in_app'] ? strtoupper( (string) $v['in_app'] ) . ' App 内' : '普通浏览器';
			case 'state':
				return '' !== (string) $v['geo_region'] ? (string) $v['geo_region'] : '未知';
			case 'newret':
				return (int) $v['first_visit_id'] === (int) $v['id'] ? '新客（第一次来）' : '回访';
			case 'hour':
				$hh = (int) self::local( (int) $v['start_ms'], 'G' );
				return sprintf( '%02d:00–%02d:59', $hh, $hh );
			case 'weekday':
				return array( '周日', '周一', '周二', '周三', '周四', '周五', '周六' )[ (int) self::local( (int) $v['start_ms'], 'w' ) ];
		}
		return '';
	}

	/** “按维度看”的行（素材以外的维度）。订单算在下单那次访问上；广告组按首次点击归。 */
	public static function dim_rows( array $p, string $dim, array $ads = array(), array $adsets = array() ): array {
		$g     = array();
		$blank = array( 'v' => array(), 'view' => array(), 'cart' => array(), 'orders' => 0, 'revenue' => 0.0, 'spend' => null );
		foreach ( $p['visits'] as $v ) {
			$k   = self::dim_key( $dim, $v, $adsets );
			$vid = (int) $v['visitor_id'];
			$g[ $k ] = $g[ $k ] ?? $blank;
			$g[ $k ]['v'][ $vid ] = true;
			if ( (int) $v['stage'] >= 1 ) {
				$g[ $k ]['view'][ $vid ] = true;
			}
			if ( (int) $v['stage'] >= 2 ) {
				$g[ $k ]['cart'][ $vid ] = true;
			}
		}
		foreach ( $p['orders'] as $o ) {
			if ( empty( $o['visit'] ) ) {
				$k = '（没追踪到访问）';
			} else {
				$row = $o['visit'];
				if ( 'adset' === $dim ) {
					$row['utm_term'] = self::order_source( $o )[1];
				}
				$k = self::dim_key( $dim, $row, $adsets );
			}
			$g[ $k ] = $g[ $k ] ?? $blank;
			++$g[ $k ]['orders'];
			$g[ $k ]['revenue'] += $o['total'];
		}
		if ( 'adset' === $dim ) {
			foreach ( $p['spend_ad'] as $ad => $s ) {
				$id = (string) ( $ads[ $ad ]['adset'] ?? '' );
				if ( '' === $id ) {
					continue;
				}
				$k = self::dim_key( 'adset', array( 'utm_term' => $id ), $adsets );
				$g[ $k ] = $g[ $k ] ?? $blank;
				$g[ $k ]['spend'] = ( $g[ $k ]['spend'] ?? 0.0 ) + $s['spend'];
			}
		}
		$out = array();
		foreach ( $g as $k => $r ) {
			$n       = count( $r['v'] );
			$out[ $k ] = array( 'label' => (string) $k, 'visitors' => $n, 'view' => count( $r['view'] ), 'cart' => count( $r['cart'] ), 'orders' => $r['orders'], 'revenue' => $r['revenue'], 'spend' => $r['spend'], 'cvr' => $n ? $r['orders'] / $n : null, 'roas' => $r['spend'] ? $r['revenue'] / $r['spend'] : null );
		}
		if ( 'hour' === $dim ) {
			ksort( $out );
		} elseif ( 'weekday' === $dim ) {
			$order = array_flip( array( '周一', '周二', '周三', '周四', '周五', '周六', '周日' ) );
			uksort( $out, static fn( $a, $b ) => ( $order[ $a ] ?? 9 ) <=> ( $order[ $b ] ?? 9 ) );
		} else {
			uasort( $out, static fn( $a, $b ) => array( $b['visitors'], $b['orders'] ) <=> array( $a['visitors'], $a['orders'] ) );
		}
		return $out;
	}

	/** 漏斗里掉得最多的一步（和正常范围下限比）。前一步不到 3 人时不判断。 */
	public static function worst_step( array $f ): ?string {
		$keys  = array( 'visitors', 'view', 'cart', 'checkout', 'address', 'paid' );
		$worst = null;
		$gap   = 0.0;
		foreach ( $keys as $i => $k ) {
			if ( ! $i || ! isset( self::BENCH[ $k ] ) || (int) $f[ $keys[ $i - 1 ] ] < 3 ) {
				continue;
			}
			$rate = $f[ $k ] / $f[ $keys[ $i - 1 ] ];
			$low  = self::BENCH[ $k ][0];
			if ( $rate < $low && ( $low - $rate ) / $low > $gap ) {
				$gap   = ( $low - $rate ) / $low;
				$worst = $k;
			}
		}
		return $worst;
	}

	/** 三句自动结论（规则写的，不调 AI）。可以带 <b>。 */
	public static function conclusions( array $p, array $cr, array $tr ): array {
		$out = array();
		if ( $p['has_spend'] ) {
			$out[] = sprintf( '这段时间花了 <b>%s</b>，卖了 <b>%s</b>（%d 单），ROAS <b>%s</b>，%s。', self::money( $p['spend'] ), self::money( $p['revenue'] ), $p['n_orders'], null === $p['roas'] ? '—' : number_format( $p['roas'], 2 ), $p['roas'] >= 2 ? '整体在赚钱' : ( $p['roas'] >= 1 ? '大致保本，利润要靠砍掉差素材' : '整体在亏钱' ) );
		} else {
			$out[] = sprintf( '这段时间卖了 <b>%s</b>（%d 单），%d 个访客。还没导入广告花费，算不出赚没赚钱。', self::money( $p['revenue'] ), $p['n_orders'], $p['visitors'] );
		}
		$scale  = array();
		$stop   = array();
		$wasted = 0.0;
		foreach ( $cr as $r ) {
			$v    = self::verdict( $r, $tr[ $r['code'] ] ?? null );
			$name = esc_html( $r['code'] . ( '' !== $r['note'] ? '（' . $r['note'] . '）' : '' ) );
			if ( '加预算' === $v[0] ) {
				$scale[] = $name;
			}
			if ( in_array( $v[0], array( '关掉', '疲劳·换素材' ), true ) ) {
				$stop[] = $name;
				if ( null !== $r['roas'] && $r['roas'] < 1 ) {
					$wasted += $r['spend'] - $r['revenue'];
				}
			}
		}
		if ( $p['has_spend'] ) {
			$out[] = ( $scale ? '把预算往 <b>' . implode( '、', $scale ) . '</b> 挪' : '暂时没有明显该加预算的素材' ) . ( $stop ? '；<b>' . implode( '、', $stop ) . '</b> 该停或换' . ( $wasted > 0 ? '，这些素材这段时间净亏约 <b>' . self::money( $wasted ) . '</b>' : '' ) : '' ) . '。';
		} else {
			$tagged = array_filter( $cr, static fn( $r ) => $r['visitors'] > 0 );
			$out[]  = $tagged ? sprintf( '有 %d 条素材带来了访客；导入花费后这里会直接告诉你哪条该加预算、哪条该关。', count( $tagged ) ) : '还没有带素材编号的广告访客；广告链接带上素材参数后，这里能按素材看效果。';
		}
		$f     = $p['funnel'];
		$worst = self::worst_step( $f );
		$c2co  = self::ratio( $f['checkout'], $f['cart'] );
		$co2a  = self::ratio( $f['address'], $f['checkout'] );
		$out[] = sprintf( '漏斗：加购 %d 人里 %d 人去结账（%s），进结账的 %d 人里 %d 人填完地址（%s）。', $f['cart'], $f['checkout'], self::pct( $c2co, 0 ), $f['checkout'], $f['address'], self::pct( $co2a, 0 ) ) . ( $worst ? '最大漏点在<b>' . self::BENCH[ $worst ][2] . '</b>这一步。' : '各步都在正常范围或人数太少。' );
		return $out;
	}

	// ======================================================================
	// 小工具
	// ======================================================================

	public static function money( ?float $v, int $dec = 0 ): string {
		return null === $v ? '—' : '$' . number_format( $v, $dec );
	}

	public static function pct( ?float $v, int $dec = 1 ): string {
		return null === $v ? '—' : number_format( $v * 100, $dec ) . '%';
	}

	public static function ratio( int $a, int $b ): ?float {
		return $b > 0 ? $a / $b : null;
	}

	/** 和上一段比：[文字, good|bad|flat]。 */
	public static function delta( $cur, $prev, bool $up_is_good = true ): array {
		if ( null === $cur || null === $prev || 0.0 === (float) $prev ) {
			return array( '上一段 —', 'flat' );
		}
		$d    = ( (float) $cur - (float) $prev ) / abs( (float) $prev );
		$good = $up_is_good ? $d > 0.03 : $d < -0.03;
		$bad  = $up_is_good ? $d < -0.03 : $d > 0.03;
		return array( ( $d >= 0 ? '▲ ' : '▼ ' ) . number_format( abs( $d ) * 100, 0 ) . '%', $good ? 'good' : ( $bad ? 'bad' : 'flat' ) );
	}

	public static function kpi( string $label, string $value, array $delta, string $hint = '' ): string {
		return '<div class="pc-rv-kpi"><span class="pc-rv-kpi__label">' . esc_html( $label ) . ( $hint ? ' <i title="' . esc_attr( $hint ) . '">?</i>' : '' ) . '</span><b>' . esc_html( $value ) . '</b><em class="is-' . esc_attr( $delta[1] ) . '">' . esc_html( $delta[0] ) . '</em></div>';
	}

	public static function tag( string $text, string $color ): string {
		return '<span class="pc-rv-tag is-' . esc_attr( $color ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function url( array $args ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	// ======================================================================
	// 页面
	// ======================================================================

	public static function render(): void {
		if ( ! current_user_can( Data_Pages::CAP ) ) {
			return;
		}
		$key = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '7d'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( self::RANGES[ $key ] ) ? $key : '7d';
		$dim = isset( $_GET['dim'] ) ? sanitize_key( wp_unslash( $_GET['dim'] ) ) : 'creative'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dim = isset( self::DIMS[ $dim ] ) ? $dim : 'creative';
		$now = (int) floor( microtime( true ) * 1000 );

		$r      = self::range( self::RANGES[ $key ], $now );
		$raw    = self::load( $r );
		$p      = self::compute( $raw );
		$q      = self::compute( self::load( self::previous( $r ) ) );
		$ads    = self::load_ads();
		$cmeta  = self::creative_meta();
		$cr     = self::by_creative( $p, $ads, $cmeta );
		$today  = self::day_start( self::ymd( $now ) );
		$recent = self::by_creative( self::compute( self::load( array( 'from' => $today - 2 * self::DAY, 'to' => $now, 'days' => 2 ) ) ), $ads, $cmeta );
		$before = self::by_creative( self::compute( self::load( array( 'from' => $today - 7 * self::DAY, 'to' => $today - 2 * self::DAY, 'days' => 5 ) ) ), $ads, $cmeta );
		$tr     = self::trends( $recent, $before );
		$spend_page = admin_url( 'admin.php?page=pc-creatives-spend' );

		echo '<div class="wrap pc-rv">';
		self::styles();
		echo '<div class="pc-rv-head"><div><h1>复盘</h1><p class="pc-rv-sub">' . esc_html( sprintf( '最近 %d 天（美国中部时间 %s 至今）· 和前一段同样长的时间比较', $r['days'], self::ymd( $r['from'] ) ) ) . '</p></div><div class="pc-rv-tabs">';
		foreach ( self::RANGES as $k => $n ) {
			echo '<a class="' . ( $k === $key ? 'is-on' : '' ) . '" href="' . esc_url( self::url( array( 'range' => $k, 'dim' => $dim ) ) ) . '">' . esc_html( $n . ' 天' ) . '</a>';
		}
		echo '</div></div>';

		if ( ! self::any_spend() ) {
			echo '<div class="notice notice-warning inline pc-rv-notice"><p><b>还没导入广告花费。</b>花费、ROAS、单单成本和素材建议都要靠它。在 Meta 广告管理工具按“天 + 广告”导出报表，然后 <a class="button button-small" href="' . esc_url( $spend_page ) . '">去导入花费</a></p></div>';
		}

		echo '<section class="pc-rv-card pc-rv-ai"><h2>这段时间的结论</h2><ol>';
		foreach ( self::conclusions( $p, $cr, $tr ) as $line ) {
			echo '<li>' . wp_kses( $line, array( 'b' => array() ) ) . '</li>';
		}
		echo '</ol><p class="pc-rv-help">按规则自动算的。想要更深入的分析，打开 <a href="' . esc_url( admin_url( 'admin.php?page=pc-data-ai' ) ) . '">AI 分析</a>。</p></section>';

		echo '<section class="pc-rv-card"><h2>赚钱吗</h2><div class="pc-rv-kpis">';
		$sd    = self::delta( $p['has_spend'] ? $p['spend'] : null, $q['has_spend'] ? $q['spend'] : null );
		$sd[1] = 'flat';
		echo self::kpi( '花费', $p['has_spend'] ? self::money( $p['spend'] ) : '—', $sd, '素材库导入的 Meta 每日花费' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::kpi( '销售额', self::money( $p['revenue'] ), self::delta( $p['revenue'], $q['revenue'] ), '付款订单金额减退款，不含测试单' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::kpi( 'ROAS', null === $p['roas'] ? '—' : number_format( $p['roas'], 2 ), self::delta( $p['roas'], $q['roas'] ), '销售额 ÷ 花费，大于 1 才回本' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::kpi( '单单成本', self::money( $p['cpa'] ), self::delta( $p['cpa'], $q['cpa'], false ), '花费 ÷ 订单数' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::kpi( '订单', (string) $p['n_orders'], self::delta( $p['n_orders'], $q['n_orders'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::kpi( '客单价', self::money( $p['aov'] ), self::delta( $p['aov'], $q['aov'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::kpi( '访客', (string) $p['visitors'], self::delta( $p['visitors'], $q['visitors'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::kpi( '转化率', self::pct( $p['cvr'], 1 ), self::delta( $p['cvr'], $q['cvr'] ), '订单 ÷ 访客' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>' . self::daily_chart( self::daily_with_spend( $p, $raw['spend'] ), $p['has_spend'] ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<div class="pc-rv-grid"><section class="pc-rv-card"><h2>漏斗</h2>' . self::funnel_html( $p, $q ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<section class="pc-rv-card"><h2>卖得最好的商品</h2>' . self::products_html( $p ) . '</section></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<section class="pc-rv-card" id="pc-rv-dim"><h2>按维度看</h2><div class="pc-rv-dimtabs">';
		foreach ( self::DIMS as $k => $label ) {
			echo '<a class="' . ( $k === $dim ? 'is-on' : '' ) . '" href="' . esc_url( self::url( array( 'range' => $key, 'dim' => $k ) ) . '#pc-rv-dim' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</div>';
		if ( 'creative' === $dim ) {
			echo self::creative_table( $cr, $tr ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo self::dim_table( self::dim_rows( $p, $dim, $ads ), $dim ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</section>';
		echo '<p class="pc-rv-foot">更细的报表：<a href="' . esc_url( admin_url( 'admin.php?page=pc-data-operations' ) ) . '">运营数据（旧版明细）</a> · <a href="' . esc_url( admin_url( 'admin.php?page=pc-data-conversion' ) ) . '">转化率详情</a> · <a href="' . esc_url( admin_url( 'admin.php?page=pc-creatives-board' ) ) . '">素材看板</a></p>';
		echo '</div>';
	}

	/** 素材编号 => [备注, 缩略图]。 */
	public static function creative_meta(): array {
		$out = array();
		if ( ! class_exists( \PeptideCore\Creatives\Creatives::class ) ) {
			return $out;
		}
		foreach ( \PeptideCore\Creatives\Creatives::all() as $c ) {
			$out[ (string) $c['code'] ] = array( 'note' => (string) ( $c['note'] ?? '' ), 'thumb' => ! empty( $c['attachment_id'] ) ? (string) wp_get_attachment_image_url( (int) $c['attachment_id'], 'thumbnail' ) : '' );
		}
		return $out;
	}

	public static function funnel_html( array $p, array $q ): string {
		$f     = $p['funnel'];
		$steps = array( 'visitors' => '进站', 'view' => '看商品', 'cart' => '加购', 'checkout' => '去结账', 'address' => '填完地址', 'paid' => '付款' );
		$keys  = array_keys( $steps );
		$worst = self::worst_step( $f );
		$max   = max( 1, $f['visitors'] );
		$rows  = '';
		foreach ( $keys as $i => $k ) {
			$n    = (int) $f[ $k ];
			$prev = $i ? (int) $f[ $keys[ $i - 1 ] ] : 0;
			$rate = $i ? self::ratio( $n, $prev ) : null;
			$b    = self::BENCH[ $k ] ?? null;
			$cls  = '';
			if ( $b && null !== $rate && $prev >= 3 ) {
				$cls = $rate < $b[0] ? 'is-bad' : ( $rate >= $b[1] ? 'is-good' : '' );
			}
			$pr    = $i ? self::ratio( (int) $q['funnel'][ $k ], (int) $q['funnel'][ $keys[ $i - 1 ] ] ) : null;
			$rows .= '<div class="pc-rv-step ' . $cls . '"><span>' . esc_html( $steps[ $k ] ) . '</span><span class="pc-rv-step__bar"><i style="width:' . esc_attr( (string) max( 2, round( $n / $max * 100 ) ) ) . '%"></i></span><b>' . $n . '</b><span class="pc-rv-step__rate">' . ( $i ? esc_html( self::pct( $rate, 0 ) ) : '' ) . '</span><span class="pc-rv-step__bench">' . ( $b ? esc_html( '正常 ' . round( $b[0] * 100 ) . '–' . round( $b[1] * 100 ) . '%' ) : '' ) . ( null !== $pr ? esc_html( ' · 上一段 ' . self::pct( $pr, 0 ) ) : '' ) . '</span></div>';
		}
		$advice = array(
			'view'     => '进站的人很少点开商品：首屏要更快看到主推商品和价格，素材承诺的东西要在首屏出现。',
			'cart'     => '看了商品不加购：检查价格、检测报告是否显眼，运费有没有提前说清楚。',
			'checkout' => '加购了不去结账：多半是看到运费 / 总价后犹豫，试试满额包邮或在购物车提示还差多少减运费。',
			'address'  => '进了结账不填地址：表单太长或运费在这一步才出现，检查手机上的结账页。',
			'paid'     => '填完地址没付款：重点查支付通道成功率和报错，换个支付方式试试。',
		);
		$tip = $worst ? '<p class="pc-rv-leak">' . self::tag( '最大漏点：' . $steps[ $worst ], 'red' ) . ' ' . esc_html( $advice[ $worst ] ) . '</p>' : '<p class="pc-rv-leak">' . self::tag( $f['visitors'] >= 3 ? '各步都在正常范围' : '人数太少，先不判断', $f['visitors'] >= 3 ? 'green' : 'gray' ) . '</p>';
		return $tip . '<div class="pc-rv-funnel">' . $rows . '</div><p class="pc-rv-help">每一步的百分比 = 这一步人数 ÷ 上一步人数，按访客去重。</p>';
	}

	private static function daily_chart( array $days, bool $has_spend ): string {
		$max = 1.0;
		foreach ( $days as $d ) {
			$max = max( $max, $d['spend'], $d['revenue'] );
		}
		$w   = 64;
		$h   = 150;
		$vw  = count( $days ) * $w + 10;
		$svg = '<svg class="pc-rv-chart" width="' . (int) ( $vw * 1.6 ) . '" height="' . (int) ( ( $h + 46 ) * 1.6 ) . '" viewBox="0 0 ' . $vw . ' ' . ( $h + 46 ) . '" role="img" aria-label="每天花费和销售额">';
		$i   = 0;
		foreach ( $days as $day => $d ) {
			$x    = 10 + $i * $w;
			$sh   = (int) round( $d['spend'] / $max * $h );
			$rh   = (int) round( $d['revenue'] / $max * $h );
			$roas = $d['spend'] > 0 ? $d['revenue'] / $d['spend'] : null;
			$svg .= '<rect x="' . $x . '" y="' . ( $h - $sh + 14 ) . '" width="22" height="' . $sh . '" class="s"><title>' . esc_html( $day . ' 花费 ' . self::money( $d['spend'] ) ) . '</title></rect>';
			$svg .= '<rect x="' . ( $x + 24 ) . '" y="' . ( $h - $rh + 14 ) . '" width="22" height="' . $rh . '" class="r"><title>' . esc_html( $day . ' 销售额 ' . self::money( $d['revenue'] ) . '，' . $d['orders'] . ' 单' ) . '</title></rect>';
			$svg .= '<text x="' . ( $x + 23 ) . '" y="' . ( $h + 30 ) . '" text-anchor="middle">' . esc_html( substr( $day, 5 ) ) . '</text>';
			$svg .= '<text x="' . ( $x + 23 ) . '" y="' . ( $h + 44 ) . '" text-anchor="middle" class="' . ( null !== $roas && $roas < 1 ? 'bad' : 'ok' ) . '">' . esc_html( $has_spend ? 'ROAS ' . ( null === $roas ? '—' : number_format( $roas, 1 ) ) : $d['orders'] . ' 单' ) . '</text>';
			++$i;
		}
		return '<div class="pc-rv-legend"><span class="s"></span>花费 <span class="r"></span>销售额（每天，美国中部时间）</div><div class="pc-rv-tablewrap">' . $svg . '</svg></div>';
	}

	private static function products_html( array $p ): string {
		$by = array();
		foreach ( $p['orders'] as $o ) {
			foreach ( $o['items'] as $it ) {
				$k        = $it['pid'];
				$by[ $k ] = array( 'name' => $it['name'], 'qty' => ( $by[ $k ]['qty'] ?? 0 ) + $it['qty'], 'total' => ( $by[ $k ]['total'] ?? 0 ) + $it['total'] );
			}
		}
		if ( ! $by ) {
			return '<p class="pc-rv-empty">这段时间没有卖出商品。</p>';
		}
		uasort( $by, static fn( $a, $b ) => $b['total'] <=> $a['total'] );
		$h = '<table class="pc-rv-table"><thead><tr><th>商品</th><th>件数</th><th>销售额</th></tr></thead><tbody>';
		foreach ( array_slice( $by, 0, 5, true ) as $r ) {
			$h .= '<tr><td>' . esc_html( $r['name'] ) . '</td><td>' . (int) $r['qty'] . '</td><td>' . esc_html( self::money( (float) $r['total'] ) ) . '</td></tr>';
		}
		return $h . '</tbody></table><p class="pc-rv-help"><a href="' . esc_url( admin_url( 'admin.php?page=pc-data-products' ) ) . '">全部商品明细 →</a></p>';
	}

	/** $basis：给建议用的数字（今日作战室用最近 3 天，表里显示所选时段）。 */
	public static function creative_table( array $cr, array $tr, ?array $basis = null, string $why = '' ): string {
		if ( ! $cr ) {
			return '<p class="pc-rv-empty">这段时间没有带素材编号的访客，也没有导入花费。</p>';
		}
		$h = '<div class="pc-rv-tablewrap"><table class="pc-rv-table"><thead><tr><th>素材</th><th>花费</th><th>进站</th><th>到站率</th><th>加购</th><th>订单</th><th>销售额</th><th>ROAS</th><th>单单成本</th><th>' . esc_html( '' !== $why ? $why : '建议' ) . '</th></tr></thead><tbody>';
		foreach ( $cr as $r ) {
			$v   = self::verdict( $basis[ $r['code'] ] ?? $r, $tr[ $r['code'] ] ?? null );
			$img = '' !== $r['thumb'] ? '<img src="' . esc_url( $r['thumb'] ) . '" alt="">' : '';
			$h  .= '<tr><td><div class="pc-rv-cr">' . $img . '<div><b>' . esc_html( $r['code'] ) . '</b>' . ( '' !== $r['note'] ? '<small>' . esc_html( $r['note'] ) . '</small>' : '' ) . '</div></div></td><td>' . esc_html( $r['spend'] > 0 ? self::money( $r['spend'] ) : '—' ) . '</td><td>' . (int) $r['visitors'] . '</td><td class="' . ( null !== $r['land'] && $r['land'] < 0.6 ? 'is-bad' : '' ) . '">' . esc_html( self::pct( $r['land'], 0 ) ) . '</td><td>' . (int) $r['cart'] . '</td><td>' . (int) $r['orders'] . '</td><td>' . esc_html( self::money( $r['revenue'] ) ) . '</td><td><b class="' . ( null !== $r['roas'] ? ( $r['roas'] >= 2 ? 'is-good' : ( $r['roas'] < 1 ? 'is-bad' : '' ) ) : '' ) . '">' . esc_html( null === $r['roas'] ? '—' : number_format( $r['roas'], 2 ) ) . '</b></td><td>' . esc_html( self::money( $r['cpa'] ) ) . '</td><td>' . self::tag( $v[0], $v[1] ) . '<span class="pc-rv-why">' . esc_html( ( null !== $basis && isset( $basis[ $r['code'] ] ) && '没有花费' !== $v[0] ? '近 3 天：' : '' ) . $v[2] ) . '</span></td></tr>';
		}
		return $h . '</tbody></table></div><p class="pc-rv-help">订单按首次点击归到素材（直接回访下单的也算回第一次带它进来的素材）。到站率 = 进站访客 ÷ Meta 链接点击，低于 60% 说明点了广告没打开页面（加载慢或被拦）。疲劳 = 前 5 天 ROAS 不错、最近 2 天掉了一半以上。</p>';
	}

	private static function dim_table( array $rows, string $dim ): string {
		if ( ! $rows ) {
			return '<p class="pc-rv-empty">这段时间没有访客。</p>';
		}
		$spend = 'adset' === $dim;
		$best  = 0.0;
		foreach ( $rows as $r ) {
			if ( $r['visitors'] >= 10 ) {
				$best = max( $best, (float) $r['cvr'] );
			}
		}
		$h = '<div class="pc-rv-tablewrap"><table class="pc-rv-table"><thead><tr><th>' . esc_html( self::DIMS[ $dim ] ) . '</th>' . ( $spend ? '<th>花费</th>' : '' ) . '<th>访客</th><th>看商品率</th><th>加购率</th><th>订单</th><th>转化率</th><th>销售额</th>' . ( $spend ? '<th>ROAS</th>' : '' ) . '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$n   = (int) $r['visitors'];
			$hot = $best > 0 && $n >= 10 && null !== $r['cvr'] && $r['cvr'] >= $best * 0.8;
			$h  .= '<tr><td>' . esc_html( $r['label'] ) . '</td>' . ( $spend ? '<td>' . esc_html( self::money( $r['spend'] ) ) . '</td>' : '' ) . '<td>' . $n . '</td><td>' . esc_html( self::pct( self::ratio( $r['view'], $n ), 0 ) ) . '</td><td>' . esc_html( self::pct( self::ratio( $r['cart'], $n ), 0 ) ) . '</td><td>' . (int) $r['orders'] . '</td><td class="' . ( $hot ? 'is-good' : '' ) . '">' . esc_html( self::pct( $r['cvr'], 1 ) ) . '</td><td>' . esc_html( self::money( $r['revenue'] ) ) . '</td>' . ( $spend ? '<td><b>' . esc_html( null === $r['roas'] ? '—' : number_format( $r['roas'], 2 ) ) . '</b></td>' : '' ) . '</tr>';
		}
		return $h . '</tbody></table></div><p class="pc-rv-help">绿色 = 这一列里转化最好的几行（至少 10 个访客）。订单算在下单那次访问上' . ( $spend ? '，广告组按首次点击归' : '' ) . '。</p>';
	}

	public static function styles(): void {
		echo '<style>
.pc-rv{--rv-line:#e5e5e5;--rv-muted:#6b7280;--rv-green:#0a7d38;--rv-red:#c0262d;--rv-orange:#b45309;--rv-blue:#1d4ed8;max-width:1280px}
.pc-rv h1{font-size:24px;margin:0;padding:0}.pc-rv h2{font-size:15px;margin:0 0 10px}
.pc-rv-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin:8px 0 14px}
.pc-rv-sub,.pc-rv-help{color:var(--rv-muted);font-size:12px;margin:4px 0 10px}
.pc-rv-tabs,.pc-rv-dimtabs{display:flex;gap:4px;flex-wrap:wrap}
.pc-rv-tabs{background:#f1f1f1;border-radius:8px;padding:3px}
.pc-rv-tabs a,.pc-rv-dimtabs a{padding:6px 12px;border-radius:6px;text-decoration:none;color:#333;font-size:13px}
.pc-rv-dimtabs{margin-bottom:12px}.pc-rv-dimtabs a{background:#f1f1f1}
.pc-rv-tabs a.is-on,.pc-rv-dimtabs a.is-on{background:#171717;color:#fff}
.pc-rv-notice{margin:0 0 14px!important}
.pc-rv-card{background:#fff;border:1px solid var(--rv-line);border-radius:10px;padding:16px 18px;margin:0 0 14px}
.pc-rv-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:14px}.pc-rv-grid>.pc-rv-card{margin:0 0 14px;min-width:0}
.pc-rv-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px}
.pc-rv-kpi{display:flex;flex-direction:column;gap:2px;padding:8px 10px;border-radius:8px;background:#fafafa}
.pc-rv-kpi__label{font-size:12px;color:var(--rv-muted)}.pc-rv-kpi__label i{font-style:normal;display:inline-block;width:14px;height:14px;line-height:14px;text-align:center;border-radius:50%;background:#e5e5e5;font-size:10px;cursor:help}
.pc-rv-kpi b{font-size:24px;font-variant-numeric:tabular-nums;line-height:1.2}
.pc-rv-kpi em{font-style:normal;font-size:12px;color:var(--rv-muted)}.pc-rv-kpi em.is-good{color:var(--rv-green)}.pc-rv-kpi em.is-bad{color:var(--rv-red)}
.pc-rv-tag{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;font-weight:600;white-space:nowrap}
.pc-rv-tag.is-green{background:#e7f6ec;color:var(--rv-green)}.pc-rv-tag.is-red{background:#fdeaea;color:var(--rv-red)}.pc-rv-tag.is-orange{background:#fff4e5;color:var(--rv-orange)}.pc-rv-tag.is-blue{background:#e8efff;color:var(--rv-blue)}.pc-rv-tag.is-gray{background:#f1f1f1;color:#555}
.pc-rv-funnel{display:grid;gap:6px}
.pc-rv-step{display:grid;grid-template-columns:72px 1fr 44px 48px 150px;align-items:center;gap:8px;font-size:13px}
.pc-rv-step__bar{background:#f1f1f1;border-radius:4px;height:16px;overflow:hidden}.pc-rv-step__bar i{display:block;height:100%;background:#171717;border-radius:4px}
.pc-rv-step.is-bad .pc-rv-step__bar i{background:var(--rv-red)}.pc-rv-step.is-bad .pc-rv-step__rate{color:var(--rv-red);font-weight:700}.pc-rv-step.is-good .pc-rv-step__rate{color:var(--rv-green)}
.pc-rv-step b{text-align:right;font-variant-numeric:tabular-nums}.pc-rv-step__bench{font-size:11px;color:var(--rv-muted)}
.pc-rv-leak{margin:0 0 10px;font-size:13px}
.pc-rv-tablewrap{overflow-x:auto}
.pc-rv-table{width:100%;border-collapse:collapse;font-size:13px}.pc-rv-table th{text-align:left;font-weight:600;color:var(--rv-muted);font-size:12px;border-bottom:1px solid var(--rv-line);padding:6px 8px;white-space:nowrap}
.pc-rv-table td{border-bottom:1px solid #f1f1f1;padding:8px;vertical-align:middle;font-variant-numeric:tabular-nums}
.pc-rv-table .is-good{color:var(--rv-green)}.pc-rv-table .is-bad{color:var(--rv-red)}
.pc-rv-cr{display:flex;gap:8px;align-items:center}.pc-rv-cr img{width:40px;height:40px;border-radius:6px;object-fit:cover}.pc-rv-cr small{display:block;color:var(--rv-muted);font-size:11px;max-width:160px}
.pc-rv-why{display:block;font-size:11px;color:var(--rv-muted);margin-top:2px;max-width:220px}
.pc-rv-empty{color:var(--rv-muted)}
.pc-rv-ai ol{margin:0 0 0 18px;display:grid;gap:6px;font-size:14px;line-height:1.6}
.pc-rv-chart{max-width:100%;height:auto}.pc-rv-chart rect.s{fill:#c7c7c7}.pc-rv-chart rect.r{fill:#171717}.pc-rv-chart text{font-size:11px;fill:#555}.pc-rv-chart text.bad{fill:var(--rv-red);font-weight:700}.pc-rv-chart text.ok{fill:var(--rv-green)}
.pc-rv-legend{font-size:12px;color:var(--rv-muted);margin:12px 0 6px}.pc-rv-legend span{display:inline-block;width:10px;height:10px;border-radius:2px;margin:0 4px 0 10px;vertical-align:middle}.pc-rv-legend span.s{background:#c7c7c7}.pc-rv-legend span.r{background:#171717}
.pc-rv-foot{font-size:12px;color:var(--rv-muted)}
@media(max-width:782px){.pc-rv-grid{grid-template-columns:1fr}.pc-rv-step{grid-template-columns:60px 1fr 36px 40px}.pc-rv-step__bench{display:none}}
</style>';
	}
}
