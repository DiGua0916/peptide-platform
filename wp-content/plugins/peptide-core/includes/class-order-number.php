<?php
/**
 * 订单号显示成 pp 的订单 id（店主 2026-11-11）。
 *
 * 付款时 HQ 发给 pp 的订单 id（hang-…）随回复存到订单上（meta `_pc_pp_ref`，由 peptide-panapay 写入）。
 * 有这个值的订单，顾客和后台看到的订单号就是它，pp 后台按订单 id 导入物流时能一一对上。
 * 老订单没有这个值，照旧显示原来的数字单号（店主：已经产生的订单不要改）；也不改订单数据，只改显示。
 * 同一张单重新付款会生成新的 hang- 号（末两位是第几次付款），订单号跟着变成最新那次；付款成功的那次就是最终的。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Order_Number {

	public const META = '_pc_pp_ref';

	public static function register_hooks(): void {
		add_filter( 'woocommerce_order_number', array( self::class, 'number' ), 20, 2 );
		// 后台订单列表可以直接搜 hang- 号。
		add_filter( 'woocommerce_order_table_search_query_meta_keys', array( self::class, 'search_keys' ) );
		add_filter( 'woocommerce_shop_order_search_fields', array( self::class, 'search_keys' ) );
	}

	public static function ref( $order ): string {
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}
		$ref = (string) $order->get_meta( self::META );
		return 1 === preg_match( '/\Ahang-[0-9]{1,13}\z/D', $ref ) ? $ref : '';
	}

	public static function number( $number, $order ) {
		$ref = self::ref( $order );
		return '' !== $ref ? $ref : $number;
	}

	public static function search_keys( $keys ): array {
		$keys   = is_array( $keys ) ? $keys : array();
		$keys[] = self::META;
		return array_values( array_unique( $keys ) );
	}

	/** 顾客输入的订单号（#11843 或 hang-161188301）找订单。 */
	public static function find( string $input ): ?\WC_Order {
		$input = strtolower( trim( ltrim( trim( $input ), '#' ) ) );
		if ( 1 === preg_match( '/\Ahang-[0-9]{1,13}\z/D', $input ) ) {
			$found = wc_get_orders( array( 'limit' => 1, 'type' => 'shop_order', 'meta_key' => self::META, 'meta_value' => $input, 'return' => 'objects' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			return $found && $found[0] instanceof \WC_Order ? $found[0] : null;
		}
		if ( 1 !== preg_match( '/\A[0-9]{1,12}\z/D', $input ) ) {
			return null;
		}
		$o = wc_get_order( (int) $input );
		// 数字号也接受（顾客付款前看到的是数字号；查询还要对上下单邮箱）。
		return $o instanceof \WC_Order ? $o : null;
	}
}
