<?php
/**
 * 库存保持在范围内（店主 2026-11-11）。
 *
 * - 普通商品每个规格 90–120，热销品 20–30（店主 2026-11-11 从 5–10 改；热销品由店主指定，存选项 hot_ids：商品 ID = 整个商品，规格 ID = 只这个规格）。
 * - 每天刷新一次：所有开了库存管理的规格换成范围内的新随机数。
 * - 卖出后掉到下限以下（或被改到范围外）时，马上补回范围内的随机数。
 * - 只动开了库存管理的规格；没开的不碰。草稿 / 下架商品不动。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Stock_Range {

	public const OPTION = 'pc_stock_range';
	public const CRON   = 'pc_stock_range_daily';

	private static bool $busy = false;
	/** @var array<int,bool> 本次请求里要检查的规格。 */
	private static array $queue = array();

	public static function register_hooks(): void {
		add_action( 'woocommerce_product_set_stock', array( self::class, 'queue' ), 20 );
		add_action( 'woocommerce_variation_set_stock', array( self::class, 'queue' ), 20 );
		add_action( 'shutdown', array( self::class, 'flush_queue' ), 5 );
		add_action( self::CRON, array( self::class, 'refresh_all' ) );
		add_action(
			'init',
			static function (): void {
				if ( self::settings()['enabled'] && ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
				}
			}
		);
	}

	public static function defaults(): array {
		return array( 'enabled' => false, 'min' => 90, 'max' => 120, 'hot_min' => 20, 'hot_max' => 30, 'hot_ids' => array() );
	}

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = array_merge( self::defaults(), is_array( $s ) ? $s : array() );
		$s['enabled'] = ! empty( $s['enabled'] );
		foreach ( array( 'min', 'max', 'hot_min', 'hot_max' ) as $k ) {
			$s[ $k ] = max( 0, (int) $s[ $k ] );
		}
		if ( $s['max'] < $s['min'] ) {
			$s['max'] = $s['min'];
		}
		if ( $s['hot_max'] < $s['hot_min'] ) {
			$s['hot_max'] = $s['hot_min'];
		}
		$s['hot_ids'] = array_values( array_unique( array_filter( array_map( 'intval', (array) $s['hot_ids'] ) ) ) );
		return $s;
	}

	/** 这个规格（或简单商品）应该在的范围；不归这里管时返回 null。 */
	public static function range_for( \WC_Product $p ): ?array {
		$s = self::settings();
		if ( ! $s['enabled'] || ! $p->managing_stock() ) {
			return null;
		}
		$parent_id = $p->get_parent_id() ?: $p->get_id();
		if ( 'publish' !== get_post_status( $parent_id ) ) {
			return null;
		}
		// 热销品可以是整个商品（填商品 ID），也可以只是某个规格（填规格 ID，例如 TR20 = Tirzepatide 20mg）。
		$hot = in_array( (int) $parent_id, $s['hot_ids'], true ) || in_array( (int) $p->get_id(), $s['hot_ids'], true );
		return $hot ? array( $s['hot_min'], $s['hot_max'] ) : array( $s['min'], $s['max'] );
	}

	/** 设成范围内的随机数。$force = false 时只在超出范围才改。返回是否改了。 */
	public static function fit( \WC_Product $p, bool $force ): bool {
		$range = self::range_for( $p );
		if ( ! $range ) {
			return false;
		}
		$qty = (int) $p->get_stock_quantity();
		if ( ! $force && $qty >= $range[0] && $qty <= $range[1] ) {
			return false;
		}
		self::$busy = true;
		try {
			wc_update_product_stock( $p, wp_rand( $range[0], $range[1] ), 'set' );
		} finally {
			self::$busy = false;
		}
		return true;
	}

	/** 库存变了（卖出、后台改）：记下来，请求结束时统一检查，避免在下单过程中途改库存。 */
	public static function queue( $product ): void {
		if ( self::$busy || ! $product instanceof \WC_Product ) {
			return;
		}
		self::$queue[ $product->get_id() ] = true;
	}

	public static function flush_queue(): void {
		if ( ! self::$queue || ! self::settings()['enabled'] ) {
			return;
		}
		$ids         = array_keys( self::$queue );
		self::$queue = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( $p instanceof \WC_Product ) {
				self::fit( $p, false );
			}
		}
	}

	/**
	 * 所有上架商品的可售规格。
	 *
	 * @return \WC_Product[]
	 */
	private static function units(): array {
		$out = array();
		foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'return' => 'objects' ) ) as $p ) {
			if ( $p->is_type( 'variable' ) ) {
				foreach ( $p->get_children() as $cid ) {
					$c = wc_get_product( $cid );
					if ( $c instanceof \WC_Product ) {
						$out[] = $c;
					}
				}
			} else {
				$out[] = $p;
			}
		}
		return $out;
	}

	/** 每天刷新：全部换新随机数。$force = false 时只修正超出范围的。返回改了几个。 */
	public static function refresh_all( bool $force = true ): int {
		if ( ! self::settings()['enabled'] ) {
			return 0;
		}
		$n = 0;
		foreach ( self::units() as $u ) {
			if ( self::fit( $u, $force ) ) {
				++$n;
			}
		}
		update_option( 'pc_stock_range_last', time(), false );
		return $n;
	}
}
