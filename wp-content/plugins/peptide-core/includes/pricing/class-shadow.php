<?php
/**
 * 价格引擎的核对和日志（M4 / NF-02）。影子和开启两档都用。
 *
 * 每个请求结束时（shutdown），如果这次请求里购物车算过总价：引擎用同一份购物车再算一遍，
 * 和 WooCommerce 实际给顾客的数字逐项比较——应付总额、商品小计、优惠码合计、每张优惠码、每个满减活动、运费、每行单价。
 * 这一步在页面内容已经生成之后进行，只读购物车，不改任何价格。
 *
 * 记录两样东西（各一张表，第一次需要写入时才建表）：
 * - {prefix}pc_m4_shadow_stats：每天、每档的核对次数（一致 / 不一致 / 跳过 / 出错）；
 * - {prefix}pc_m4_shadow_log：不一致、出错、跳过的明细（JSON），只留最近 300 条。明细里不记邮箱、姓名、地址。
 *
 * “跳过”= 引擎算不了（价格含税、别的插件加的费用或价格过滤器……）或购物车在算完之后又被改过。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

defined( 'ABSPATH' ) || exit;

final class Shadow {

	public const DB_OPTION  = 'pc_m4_db';
	public const DB_VERSION = '1';
	public const KEEP       = 300;
	public const SESSION    = 'pc_m4_shadow';
	public const FIELDS     = array( 'total', 'subtotal', 'discount', 'coupons', 'fees', 'shipping', 'units' );

	private static bool $done = false;

	public static function table( string $kind ): string {
		global $wpdb;
		return $wpdb->prefix . ( 'stats' === $kind ? 'pc_m4_shadow_stats' : 'pc_m4_shadow_log' );
	}

	/** 建表（可重复执行）。只加表，不动别的表。 */
	public static function install(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$charset = $wpdb->get_charset_collate();
		$stats   = self::table( 'stats' );
		$log     = self::table( 'log' );
		dbDelta(
			"CREATE TABLE {$stats} (
day date NOT NULL,
mode varchar(8) NOT NULL,
compared int(10) unsigned NOT NULL DEFAULT 0,
matched int(10) unsigned NOT NULL DEFAULT 0,
mismatched int(10) unsigned NOT NULL DEFAULT 0,
skipped int(10) unsigned NOT NULL DEFAULT 0,
errors int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (day,mode)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$log} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
created_gmt datetime NOT NULL,
kind varchar(12) NOT NULL,
mode varchar(8) NOT NULL,
legacy_cents bigint(20) NOT NULL DEFAULT 0,
engine_cents bigint(20) NOT NULL DEFAULT 0,
detail longtext NOT NULL,
PRIMARY KEY  (id),
KEY kind (kind,id)
) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	private static function ready(): bool {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
		return self::DB_VERSION === get_option( self::DB_OPTION );
	}

	/**
	 * 两张表是不是真的在。只看版本号不够：整店复制整表复制设置，来源店的版本号会留在新店，表却不跟着来
	 * （和离开挽回的统计表是同一类问题）。只在查看和清空时用，核对时不多查这一次。
	 */
	private static function tables_exist(): bool {
		global $wpdb;
		foreach ( array( 'stats', 'log' ) as $kind ) {
			$t = self::table( $kind );
			if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
		}
		return true;
	}

	/** 写一次；写不进去（版本号在、表不在）就补建表再写一次。第二次不再静默，仍然失败会照常进错误日志。 */
	private static function write( callable $write ): void {
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true );
		$done  = $write();
		$wpdb->suppress_errors( $quiet );
		if ( false === $done ) {
			self::install();
			$write();
		}
	}

	// ---------- 取数 ----------

	/** WooCommerce 实际算给顾客的数字（分）。 */
	public static function legacy( \WC_Cart $cart ): array {
		$s    = 10 ** wc_get_price_decimals();
		$fees = array();
		foreach ( $cart->get_fees() as $fee ) {
			$fees[ (string) $fee->id ] = round( (float) $fee->total * $s, 4 );
		}
		$units = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ( $item['data'] ?? null ) instanceof \WC_Product ) {
				$units[ (string) $key ] = round( (float) $item['data']->get_price(), 8 );
			}
		}
		$coupons = array();
		foreach ( (array) $cart->get_coupon_discount_totals() as $code => $amount ) {
			if ( $amount ) {
				$coupons[ (string) $code ] = (int) round( (float) $amount * $s );
			}
		}
		ksort( $fees );
		ksort( $units );
		ksort( $coupons );
		return array(
			'total'    => (int) round( ( (float) $cart->get_total( 'edit' ) - ( wc_tax_enabled() ? (float) $cart->get_total_tax() : 0.0 ) ) * $s ),
			'subtotal' => round( (float) $cart->get_subtotal() * $s, 4 ),
			'discount' => round( (float) $cart->get_discount_total() * $s, 4 ),
			'coupons'  => $coupons,
			'fees'     => $fees,
			'shipping' => round( (float) $cart->get_shipping_total() * $s, 4 ),
			'units'    => $units,
		);
	}

	/** 引擎算出的同一组数字（分）。 */
	public static function engine( array $result ): array {
		$fees = array();
		foreach ( $result['promotions']['fees'] as $id => $fee ) {
			$fees[ (string) $id ] = round( (float) $fee['total_cents'], 4 );
		}
		$units = array();
		foreach ( $result['lines'] as $key => $line ) {
			$units[ (string) $key ] = round( (float) $line['unit'], 8 );
		}
		$coupons = array_filter( $result['coupons'] );
		ksort( $fees );
		ksort( $units );
		ksort( $coupons );
		return array(
			'total'    => (int) $result['totals']['total_cents'],
			'subtotal' => round( (float) $result['totals']['items_subtotal_cents'], 4 ),
			'discount' => round( (float) $result['totals']['coupon_discount_cents'], 4 ),
			'coupons'  => $coupons,
			'fees'     => $fees,
			'shipping' => round( (float) $result['totals']['shipping_cents'], 4 ),
			'units'    => $units,
		);
	}

	/**
	 * 核对一份刚算完总价的购物车。只读。
	 *
	 * @return array{kind:string,legacy:array,engine:array,diff:array,reason:string[],result:array,input:array}
	 */
	public static function check( \WC_Cart $cart ): array {
		$legacy = self::legacy( $cart );
		$input  = Cart_Reader::read( $cart );
		$result = Engine::price( $input );
		$engine = self::engine( $result );
		$out    = array( 'kind' => 'matched', 'legacy' => $legacy, 'engine' => $engine, 'diff' => array(), 'reason' => array(), 'result' => $result, 'input' => $input );
		if ( ! $result['supported'] ) {
			$out['kind']   = 'skipped';
			$out['reason'] = $result['unsupported'];
			return $out;
		}
		// 购物车在上一次算总价之后又被改过（加了商品、改了数量但还没重算）：这一次的数字对不上是正常的，不算不一致。
		$fresh = 0;
		foreach ( $cart->get_cart() as $item ) {
			if ( ( $item['data'] ?? null ) instanceof \WC_Product ) {
				$line   = Engine::cents( (float) $item['data']->get_price() * (float) $item['quantity'], (int) $input['decimals'] );
				$fresh += $input['round_at_subtotal'] ? $line : Engine::round( $line );
			}
		}
		if ( abs( $fresh - $legacy['subtotal'] ) > 0.0001 ) {
			$out['kind']   = 'skipped';
			$out['reason'] = array( 'stale_cart' );
			return $out;
		}
		foreach ( self::FIELDS as $field ) {
			// 启用税费时应付总额含税，引擎不算税：只比不含税的各项。
			if ( 'total' === $field && ! empty( $input['tax_enabled'] ) ) {
				continue;
			}
			if ( $legacy[ $field ] != $engine[ $field ] ) { // phpcs:ignore Universal.Operators.StrictComparisons -- 2998 和 2998.0 算相等。
				$out['diff'][ $field ] = array( 'legacy' => $legacy[ $field ], 'engine' => $engine[ $field ] );
			}
		}
		if ( $out['diff'] ) {
			$out['kind'] = 'mismatched';
		}
		return $out;
	}

	// ---------- 请求末尾 ----------

	/** shutdown：这一次请求里购物车算过总价的话，核对一次并记录。任何异常都不外抛。 */
	public static function compare(): void {
		if ( self::$done ) {
			return;
		}
		self::$done = true;
		$mode       = Mode::get();
		if ( Mode::OFF === $mode || ! function_exists( 'WC' ) || ! did_action( 'woocommerce_after_calculate_totals' ) ) {
			return;
		}
		try {
			$wc   = WC();
			$cart = $wc->cart ?? null;
			if ( ! $cart instanceof \WC_Cart || $cart->is_empty() ) {
				return;
			}
			// 同一位顾客、同一份购物车、同样的结果，只核对一次。
			$hash = md5( (string) wp_json_encode( array( $mode, $cart->get_cart_hash(), $cart->get_applied_coupons(), (string) $cart->get_total( 'edit' ), (string) $cart->get_shipping_total(), (string) $cart->get_fee_total() ) ) );
			if ( $wc->session && $hash === $wc->session->get( self::SESSION ) ) {
				return;
			}
			$check = self::check( $cart );
			self::record( $mode, $check['kind'], $check );
			if ( $wc->session ) {
				$wc->session->set( self::SESSION, $hash );
			}
		} catch ( \Throwable $e ) {
			self::error( $mode, 'compare', $e );
		}
	}

	/** 引擎出错（核对时，或开启档接管时）：计一次数，记一条明细。 */
	public static function error( string $mode, string $where, \Throwable $e ): void {
		try {
			self::record( $mode, 'errors', array( 'legacy' => array( 'total' => 0 ), 'engine' => array( 'total' => 0 ), 'error' => array( 'where' => $where, 'class' => get_class( $e ), 'message' => $e->getMessage(), 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ) ) );
		} catch ( \Throwable $ignored ) {
			return; // 日志写不进去也不能影响顾客的请求。
		}
	}

	/** 写统计；不一致 / 出错 / 跳过另外写一条明细（跳过的同一原因每小时最多一条）。 */
	public static function record( string $mode, string $kind, array $check ): void {
		global $wpdb;
		if ( ! in_array( $kind, array( 'matched', 'mismatched', 'skipped', 'errors' ), true ) || ! self::ready() ) {
			return;
		}
		$stats = self::table( 'stats' );
		$count = $wpdb->prepare( "INSERT INTO {$stats} (day, mode, compared, {$kind}) VALUES (%s, %s, 1, 1) ON DUPLICATE KEY UPDATE compared = compared + 1, {$kind} = {$kind} + 1", wp_date( 'Y-m-d' ), $mode ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名和列名来自上面的白名单。
		self::write( static fn() => $wpdb->query( $count ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( 'matched' === $kind ) {
			return;
		}
		if ( 'skipped' === $kind ) {
			$throttle = 'pc_m4_skip_' . md5( implode( ',', (array) ( $check['reason'] ?? array() ) ) );
			if ( get_transient( $throttle ) ) {
				return;
			}
			set_transient( $throttle, 1, HOUR_IN_SECONDS );
		}
		$log = self::table( 'log' );
		$row = array(
			'created_gmt'  => gmdate( 'Y-m-d H:i:s' ),
			'kind'         => $kind,
			'mode'         => $mode,
			'legacy_cents' => (int) ( $check['legacy']['total'] ?? 0 ),
			'engine_cents' => (int) ( $check['engine']['total'] ?? 0 ),
			'detail'       => (string) wp_json_encode( self::detail( $check ) ),
		);
		self::write( static fn() => $wpdb->insert( $log, $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->insert_id && 0 === $wpdb->insert_id % 20 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE id <= %d", (int) $wpdb->insert_id - self::KEEP ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
	}

	/** 日志明细：只留排查需要的内容，不带顾客的邮箱等个人信息；积分券的码是个人的，只记 ID。 */
	public static function detail( array $check ): array {
		$out = array();
		foreach ( array( 'diff', 'reason', 'legacy', 'engine', 'error' ) as $key ) {
			if ( ! empty( $check[ $key ] ) ) {
				$out[ $key ] = $check[ $key ];
			}
		}
		if ( isset( $check['input'] ) ) {
			$in    = $check['input'];
			$lines = array();
			foreach ( $in['lines'] as $key => $line ) {
				$lines[ (string) $key ] = array( 'product' => (int) $line['product_id'], 'parent' => (int) $line['parent_id'], 'qty' => $line['qty'], 'price' => $line['price'], 'on_sale' => (bool) $line['on_sale'], 'offer' => $line['offer'] ? array( 'offer' => $line['offer']['offer'], 'kind' => $line['offer']['kind'] ) : null );
			}
			$coupons = array();
			foreach ( $in['coupons'] as $c ) {
				$coupons[] = array( 'id' => (int) $c['id'], 'code' => 'loyalty' === $c['origin'] ? '' : (string) $c['code'], 'origin' => $c['origin'], 'type' => $c['type'], 'amount' => (string) $c['amount'], 'strategy' => $c['strategy'] );
			}
			$out['cart'] = array(
				'lines'      => $lines,
				'coupons'    => $coupons,
				'promotions' => array_map( 'intval', array_keys( $in['promotions'] ) ),
				'shipping'   => $in['shipping']['rates'],
				'logged_in'  => ! empty( $in['customer']['user_id'] ),
				'sequential' => (bool) $in['sequential'],
				'tax'        => ! empty( $in['tax_enabled'] ),
			);
		}
		if ( isset( $check['result'] ) ) {
			$mask = static function ( array $row ): array {
				if ( 'loyalty' === ( $row['source'] ?? '' ) ) {
					$row['code'] = 'loyalty#' . (int) ( $row['id'] ?? 0 );
					if ( isset( $row['label'] ) ) {
						$row['label'] = $row['code'];
					}
				}
				return $row;
			};
			$out['entries']  = array_map( $mask, $check['result']['entries'] );
			$out['rejected'] = array_map( $mask, $check['result']['rejected'] );
		}
		foreach ( array( 'legacy', 'engine' ) as $side ) {
			if ( isset( $out[ $side ]['coupons'] ) && isset( $check['input'] ) ) {
				foreach ( $check['input']['coupons'] as $c ) {
					if ( 'loyalty' === $c['origin'] && isset( $out[ $side ]['coupons'][ $c['code'] ] ) ) {
						$out[ $side ]['coupons'][ 'loyalty#' . (int) $c['id'] ] = $out[ $side ]['coupons'][ $c['code'] ];
						unset( $out[ $side ]['coupons'][ $c['code'] ] );
					}
				}
			}
		}
		return $out;
	}

	// ---------- 查看 ----------

	/** 最近 N 天的核对次数（按档位合计）和最近的明细。 */
	public static function summary( int $days = 7, int $rows = 20 ): array {
		global $wpdb;
		$out = array( 'days' => $days, 'totals' => array(), 'recent' => array() );
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) || ! self::tables_exist() ) {
			return $out; // 还没写过任何记录（或者版本号是复制店铺时带过来的，表还没建）。
		}
		$stats = self::table( 'stats' );
		$log   = self::table( 'log' );
		$from  = wp_date( 'Y-m-d', time() - max( 0, $days - 1 ) * DAY_IN_SECONDS );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT mode, SUM(compared) AS compared, SUM(matched) AS matched, SUM(mismatched) AS mismatched, SUM(skipped) AS skipped, SUM(errors) AS errors FROM {$stats} WHERE day >= %s GROUP BY mode", $from ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$out['totals'][ (string) $row['mode'] ] = array_map( 'intval', array_diff_key( $row, array( 'mode' => 1 ) ) );
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, created_gmt, kind, mode, legacy_cents, engine_cents, detail FROM {$log} ORDER BY id DESC LIMIT %d", max( 0, min( 100, $rows ) ) ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$row['detail']   = json_decode( (string) $row['detail'], true );
			$out['recent'][] = $row;
		}
		return $out;
	}

	/** 清空明细和统计（切换档位重新观察之前用）。 */
	public static function clear(): void {
		global $wpdb;
		if ( self::DB_VERSION === get_option( self::DB_OPTION ) && self::tables_exist() ) {
			$wpdb->query( 'DELETE FROM ' . self::table( 'log' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'DELETE FROM ' . self::table( 'stats' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
	}

	/** 测试用：允许同一进程里再核对一次。 */
	public static function reset(): void {
		self::$done = false;
	}
}
