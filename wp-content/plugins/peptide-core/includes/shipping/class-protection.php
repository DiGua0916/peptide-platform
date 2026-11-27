<?php
/**
 * 运费险（1 号站 P0-5，2026-11-14 定稿）。
 *
 * - 一条购物车费用：默认加上（$0.99），客户可以在购物车 / 结账页取消；取消状态存在 WooCommerce 会话里。
 * - 页面上把参考价（$20）划掉显示，所以客户看到“$20 → $0.99”。参考价只用于展示，不参与金额计算。
 * - 只有购物车需要配送时才收。整单免运费（满 $600）时照样收 $0.99，这是定稿的规则。
 * - 取消 / 恢复用 Store API 的 cart/extensions 更新（命名空间 pc-ship-protect），区块购物车和结账会自动刷新金额。
 * - 保费按订单金额分四档（价格文档 §3.4，店主 2026-11-27）：<$160 收 $9.90；$160–319 收 $14.90；
 *   $320–599 收 $19.90；≥$600 收 $24.90。没配 tiers 时退回单一价格，老行为不变。
 * - 下单时把「有没有选」和「收了多少」写进订单（_pc_ship_protect / _pc_ship_premium），看板靠这两项统计。
 * - 默认关闭（enabled = false），上线时在选项 peptide_core_shipping_protection 里打开，不会因为部署代码就开始收费。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Shipping;

defined( 'ABSPATH' ) || exit;

final class Protection {

	public const OPTION      = 'peptide_core_shipping_protection';
	public const SESSION_KEY = 'pc_ship_protect_off';
	public const NAMESPACE   = 'pc-ship-protect';
	public const FEE_ID      = 'pc-shipping-protection';

	public static function register_hooks(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( self::class, 'add_fee' ), 20 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'stamp_order' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'stamp_order' ), 20 );
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_update();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( self::class, 'register_update' ) );
		}
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/** @return array{enabled:bool,price:float,reference:float,label:string} */
	public static function settings(): array {
		$saved = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$saved = is_array( $saved ) ? $saved : array();
		$price = is_numeric( $saved['price'] ?? null ) ? max( 0.0, round( (float) $saved['price'], 2 ) ) : 0.99;
		$ref   = is_numeric( $saved['reference'] ?? null ) ? max( 0.0, round( (float) $saved['reference'], 2 ) ) : 20.0;
		$label = trim( (string) ( $saved['label'] ?? '' ) );
		$tiers = self::clean_tiers( $saved['tiers'] ?? null );
		return array(
			'enabled'   => ! empty( $saved['enabled'] ) && ( $price > 0 || $tiers ),
			'price'     => $price,
			'tiers'     => $tiers,
			'reference' => max( $ref, $price ),
			'label'     => '' !== $label ? $label : 'Shipping protection',
		);
	}

	/** 价格文档 §3.4 的四档保费。没配就是空数组，退回单一价格。 */
	public static function default_tiers(): array {
		return array(
			array( 'min' => 0.0,   'max' => 160.0, 'price' => 9.90 ),
			array( 'min' => 160.0, 'max' => 320.0, 'price' => 14.90 ),
			array( 'min' => 320.0, 'max' => 600.0, 'price' => 19.90 ),
			array( 'min' => 600.0, 'max' => null,  'price' => 24.90 ),
		);
	}

	/** @return array<int,array{min:float,max:float|null,price:float}> 按下限排序 */
	public static function clean_tiers( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $t ) {
			if ( ! is_array( $t ) || ! is_numeric( $t['price'] ?? null ) ) {
				continue;
			}
			$max  = $t['max'] ?? null;
			$out[] = array(
				'min'   => max( 0.0, (float) ( $t['min'] ?? 0 ) ),
				'max'   => ( null === $max || '' === $max ) ? null : max( 0.0, (float) $max ),
				'price' => max( 0.0, round( (float) $t['price'], 2 ) ),
			);
		}
		usort( $out, static fn( $a, $b ) => $a['min'] <=> $b['min'] );
		return $out;
	}

	/** 这个订单金额该收多少保费。左闭右开，和运费方案的规则一致。 */
	public static function tier_price( float $subtotal, array $s ): float {
		foreach ( $s['tiers'] as $t ) {
			if ( $subtotal >= $t['min'] && ( null === $t['max'] || $subtotal < $t['max'] ) ) {
				return $t['price'];
			}
		}
		return (float) $s['price'];
	}

	/** 这位客户现在有没有选运费险（默认选上）。 */
	public static function chosen(): bool {
		return ! ( function_exists( 'WC' ) && WC()->session && 'yes' === WC()->session->get( self::SESSION_KEY ) );
	}

	public static function set_chosen( bool $on ): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $on ? null : 'yes' );
		}
	}

	/** woocommerce_cart_calculate_fees */
	public static function add_fee( $cart ): void {
		$s = self::settings();
		if ( ! $s['enabled'] || ! self::chosen() || ! is_object( $cart ) || ! method_exists( $cart, 'needs_shipping' ) || ! $cart->needs_shipping() ) {
			return;
		}
		$amount = self::tier_price( (float) ( method_exists( $cart, 'get_subtotal' ) ? $cart->get_subtotal() : 0 ), $s );
		if ( $amount <= 0 ) {
			return;
		}
		$cart->fees_api()->add_fee(
			array(
				'id'      => self::FEE_ID,
				'name'    => $s['label'],
				'amount'  => $amount,
				'taxable' => false,
			)
		);
	}

	public static function register_update(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::NAMESPACE,
				'callback'  => static function ( $data ): void {
					self::set_chosen( ! empty( $data['on'] ) );
				},
			)
		);
	}

	/** 购物车接口里给页面用的数据（金额为最小货币单位）。 */
	public static function for_cart( $cart = null ): array {
		$s       = self::settings();
		$minor   = static fn( float $v ): int => (int) round( $v * 100 );
		$applies = $s['enabled'] && is_object( $cart ) && method_exists( $cart, 'needs_shipping' ) && $cart->needs_shipping();
		return array(
			'enabled'         => $applies,
			'chosen'          => $applies && self::chosen(),
			'label'           => $s['label'],
			'price_minor'     => $minor( self::tier_price( (float) ( is_object( $cart ) && method_exists( $cart, 'get_subtotal' ) ? $cart->get_subtotal() : 0 ), $s ) ),
			'reference_minor' => $minor( $s['reference'] ),
			'namespace'       => self::NAMESPACE,
		);
	}

	public static function assets(): void {
		if ( is_admin() || ! self::settings()['enabled'] || ! ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) ) {
			return;
		}
		$file = 'assets/shipping-protection.js';
		wp_enqueue_script( 'pc-shipping-protection', plugins_url( $file, PEPTIDE_CORE_FILE ), array(), (string) filemtime( PEPTIDE_CORE_DIR . '/' . $file ), true );
		$css = 'assets/shipping-protection.css';
		wp_enqueue_style( 'pc-shipping-protection', plugins_url( $css, PEPTIDE_CORE_FILE ), array(), (string) filemtime( PEPTIDE_CORE_DIR . '/' . $css ) );
	}

	/** 下单时记下有没有选运费险、收了多少。看板（Protection_Stats）靠这两项统计。 */
	public static function stamp_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$s = self::settings();
		if ( ! $s['enabled'] ) {
			return;
		}
		$premium = 0.0;
		foreach ( $order->get_fees() as $fee ) {
			if ( self::FEE_ID === $fee->get_name() || $s['label'] === $fee->get_name() ) {
				$premium += (float) $fee->get_total();
			}
		}
		$order->update_meta_data( '_pc_ship_protect', $premium > 0 ? 'yes' : 'no' );
		$order->update_meta_data( '_pc_ship_premium', round( $premium, 2 ) );
	}
}
