<?php
/**
 * 波多黎各地址统一成「国家 US + 州 PR」（店主 2026-11-19）。
 *
 * 背景：WooCommerce 把波多黎各当成一个独立国家（PR），同时我们又把它加进了美国的州列表，
 * 于是同一个岛的顾客有两个入口，订单里出现了三种写法：
 *   US/PR（标准）、PR/空（选国家 Puerto Rico，州字段被 WooCommerce 隐藏）、
 *   PR/城市名（2026-11-11 加的 fill_state 把市镇写进州字段，本次去掉）。
 * 发卡行和 AVS 认的是「US + PR」，`PR/Trujillo` 这种州值在校验时必然对不上；
 * 三种写法并存也让对账、运费规则和数据分析全部对不准。
 *
 * 现在的做法：
 * 1. 美国的州列表里保留 Puerto Rico（州代码 PR），顾客在 United States 下面就能选到；
 * 2. 国家下拉里去掉 Puerto Rico，只留一个入口，不再产生 PR/* 的写法；
 * 3. 下单时兜底归一：国家是 PR 的改成 US/PR；国家是 US、邮编是波多黎各（006/007/009）
 *    但州不是 PR 的，也改成 US/PR。手动订单链接走的是同一套结账流程，一样覆盖。
 *
 * 不动已产生的订单（店主要求），只影响从现在起的新订单。
 *
 * 运费：配送方案按「国家 + 州」匹配，覆盖 US 全部州的方案自动包含 PR 州。
 * 邮编校验（Postal_Assist）用州代码核对，PR 邮编 006/007/009 通过。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class US_Territories {

	/** 加进美国州列表的属地：州代码 => 显示名。 */
	public const STATES = array(
		'PR' => 'Puerto Rico',
	);

	/** 波多黎各邮编前三位（008 是美属维尔京群岛，不算）。 */
	private const PR_ZIP = array( '006', '007', '009' );

	public static function register_hooks(): void {
		add_filter( 'woocommerce_states', array( self::class, 'states' ), 20 );
		// 国家下拉里去掉已经做成「州」的属地，避免同一个地方有两个入口。
		add_filter( 'woocommerce_countries_allowed_countries', array( self::class, 'drop_states_from_countries' ), 20 );
		add_filter( 'woocommerce_countries_shipping_countries', array( self::class, 'drop_states_from_countries' ), 20 );
		// 下单时兜底：经典结账、区块结账 / Store API（手动订单链接也走这条）都覆盖。
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'normalize_order' ), 5 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'normalize_order' ), 5 );
	}

	/**
	 * 一个地址该不该改写。
	 *
	 * @param string $country 两位国家代码（已大写去空格）
	 * @param string $state   州 / 市镇（已大写去空格）
	 * @param string $zip     邮编，只保留数字
	 * @return array{0:string,1:string}|null 要改就返回 [国家, 州]；不用改返回 null
	 */
	public static function normalize( string $country, string $state, string $zip ): ?array {
		if ( isset( self::STATES[ $country ] ) ) {
			// PR/空、PR/Trujillo、PR/Cidra, Puerto Rico … 一律收成 US/PR。
			return array( 'US', $country );
		}
		if ( 'US' === $country && 'PR' !== $state && in_array( substr( $zip, 0, 3 ), self::PR_ZIP, true ) ) {
			// 国家选了美国、邮编是波多黎各，但州填成别的（或空着）。
			return array( 'US', 'PR' );
		}
		return null;
	}

	/** 下单时把收货和账单地址都归一。 */
	public static function normalize_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$country = strtoupper( trim( (string) $order->{"get_{$type}_country"}() ) );
			$state   = strtoupper( trim( (string) $order->{"get_{$type}_state"}() ) );
			$zip     = (string) preg_replace( '/\D/', '', (string) $order->{"get_{$type}_postcode"}() );
			$fixed   = self::normalize( $country, $state, $zip );
			if ( null === $fixed ) {
				continue;
			}
			$order->{"set_{$type}_country"}( $fixed[0] );
			$order->{"set_{$type}_state"}( $fixed[1] );
		}
	}

	/**
	 * 国家列表里去掉已经做成美国「州」的属地。
	 *
	 * @param array<string,string> $countries
	 * @return array<string,string>
	 */
	public static function drop_states_from_countries( $countries ): array {
		$countries = is_array( $countries ) ? $countries : array();
		// 美国本身没开放时不动（否则波多黎各顾客会连入口都没有）。
		if ( ! isset( $countries['US'] ) ) {
			return $countries;
		}
		foreach ( array_keys( self::STATES ) as $code ) {
			unset( $countries[ $code ] );
		}
		return $countries;
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
