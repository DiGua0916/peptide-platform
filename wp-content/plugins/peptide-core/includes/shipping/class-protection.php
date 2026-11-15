<?php
/**
 * 运费险（1 号站 P0-5，2026-11-14 定稿）。
 *
 * - 一条购物车费用：默认加上（$0.99），客户可以在购物车 / 结账页取消；取消状态存在 WooCommerce 会话里。
 * - 页面上把参考价（$20）划掉显示，所以客户看到“$20 → $0.99”。参考价只用于展示，不参与金额计算。
 * - 只有购物车需要配送时才收。整单免运费（满 $600）时照样收 $0.99，这是定稿的规则。
 * - 取消 / 恢复用 Store API 的 cart/extensions 更新（命名空间 pc-ship-protect），区块购物车和结账会自动刷新金额。
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
		return array(
			'enabled'   => ! empty( $saved['enabled'] ) && $price > 0,
			'price'     => $price,
			'reference' => max( $ref, $price ),
			'label'     => '' !== $label ? $label : 'Shipping protection',
		);
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
		$cart->fees_api()->add_fee(
			array(
				'id'      => self::FEE_ID,
				'name'    => $s['label'],
				'amount'  => $s['price'],
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
			'price_minor'     => $minor( $s['price'] ),
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
}
