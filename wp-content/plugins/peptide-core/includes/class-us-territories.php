<?php
/**
 * 美国属地当“州”选（2026-11-09）。
 *
 * WooCommerce 把波多黎各当成一个单独的国家（PR），结账页要先把“国家/地区”从 United States 换成 Puerto Rico 才能选到；
 * 顾客习惯在 United States 下面的“州”里找，找不到就以为不支持。这里把波多黎各加进美国的州列表（州代码 PR），
 * 国家级的 Puerto Rico 选项照旧保留，两种选法都能下单。
 *
 * 运费：配送方案按“国家 + 州”匹配，覆盖 US 全部州的方案自动包含 PR 州；邮编校验（Postal_Assist）用州代码核对，PR 邮编 006/007/009 通过。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class US_Territories {

	/** 州代码 => 显示名。只加波多黎各；其他属地（GU、VI、AS、MP）按需再加。 */
	public const STATES = array(
		'PR' => 'Puerto Rico',
	);

	public static function register_hooks(): void {
		add_filter( 'woocommerce_states', array( self::class, 'states' ), 20 );
		// 店主 2026-11-11：国家选 Puerto Rico 时 WooCommerce 不要“州”，只把城市叫“Municipality”，
		// 发到支付平台（pp）的州就是空的。像 Shopname 一样，把市镇（Ponce、Villalba…）写进州。
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'fill_state' ), 5 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'fill_state' ), 5 );
	}

	/** 没有州列表的属地（PR、GU、VI、AS、MP）：州空着就用城市（市镇）。 */
	public static function fill_state( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$country = (string) $order->{"get_{$type}_country"}();
			if ( in_array( $country, array( 'PR', 'GU', 'VI', 'AS', 'MP' ), true ) && '' === trim( (string) $order->{"get_{$type}_state"}() ) ) {
				$city = trim( (string) $order->{"get_{$type}_city"}() );
				if ( '' !== $city ) {
					$order->{"set_{$type}_state"}( $city );
				}
			}
		}
	}

	/** @param array<string,array<string,string>> $states */
	public static function states( $states ): array {
		$states = is_array( $states ) ? $states : array();
		$us     = is_array( $states['US'] ?? null ) ? $states['US'] : array();
		foreach ( self::STATES as $code => $label ) {
			if ( ! isset( $us[ $code ] ) ) {
				$us[ $code ] = $label;
			}
		}
		$states['US'] = $us;
		return $states;
	}
}
