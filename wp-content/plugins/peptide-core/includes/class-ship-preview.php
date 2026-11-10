<?php
/**
 * 运费提前显示（店主 2026-11-10，阳的 AI 全站分析建议）。
 *
 * 顾客到结账填地址时才第一次看到 $45 运费，很多人在这一步离开。开启后：
 * - 商品弹窗的加购区：显示“Shipping $X · Estimated total $Y”（购物车里已有的 + 这件）；
 * - 购物车抽屉本来就显示运费和应付总额（vc-live-cart-drawer），不用改。
 * 运费按这家店自己的运费方案和运费优惠算（Shipping\Plans / Shipping\Discounts），默认按美国估算。
 * 每家店单独开关（默认关），开启时间告诉 AI，方便对比开启前后。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Ship_Preview {

	public const OPTION = 'pc_ship_preview';

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 60 );
	}

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array( 'enabled' => ! empty( $s['enabled'] ), 'since' => (int) ( $s['since'] ?? 0 ) );
	}

	public static function set_enabled( bool $on ): void {
		$old = self::settings();
		update_option( self::OPTION, array( 'enabled' => $on, 'since' => $on ? ( $old['enabled'] ? $old['since'] : time() ) : 0, 'off_at' => $on ? 0 : time() ), false );
	}

	/** 给 AI 看的一句话。 */
	public static function ai_note(): string {
		$s = self::settings();
		return $s['enabled']
			? '开启（' . wp_date( 'Y-m-d H:i', $s['since'] ) . ' 起，商品弹窗加购区显示运费和预计总价（购物车抽屉本来就显示运费和应付总额）；对比开启前后的“填完地址 ÷ 发起结账”）'
			: '关闭（商品弹窗不显示运费；购物车抽屉显示运费和应付总额）';
	}

	public static function enqueue(): void {
		if ( is_admin() || ! self::settings()['enabled'] || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			return;
		}
		$file = PEPTIDE_CORE_DIR . '/assets/ship-preview.js';
		if ( ! is_readable( $file ) ) {
			return;
		}
		wp_enqueue_script( 'pc-ship-preview', plugins_url( 'assets/ship-preview.js', PEPTIDE_CORE_FILE ), array(), (string) filemtime( $file ), true );
		wp_add_inline_script( 'pc-ship-preview', 'window.pcShipPreview=' . wp_json_encode( array( 'url' => rest_url( 'peptide/v1/ship-estimate' ) ) ) . ';', 'before' );
	}

	public static function routes(): void {
		register_rest_route(
			'peptide/v1',
			'/ship-estimate',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'rest_estimate' ),
			)
		);
	}

	private static function money( float $v ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $v ) ), ENT_QUOTES, 'UTF-8' );
	}

	public static function rest_estimate( \WP_REST_Request $r ) {
		if ( ! self::settings()['enabled'] || ! function_exists( 'WC' ) ) {
			return new \WP_Error( 'pc_ship_off', 'Off', array( 'status' => 404 ) );
		}
		if ( null === WC()->cart ) {
			wc_load_cart();
		}
		$contents = array();
		$subtotal = 0.0;
		$in_cart  = 0;
		$discount = 0.0;
		if ( WC()->cart ) {
			WC()->cart->calculate_totals();
			foreach ( WC()->cart->get_cart() as $item ) {
				$contents[] = $item;
				$subtotal  += (float) ( $item['line_subtotal'] ?? 0 );
				++$in_cart;
			}
			$discount = (float) WC()->cart->get_discount_total();
		}
		// 商品弹窗：把正在看的这件也算进去（还没加购）。
		$add = absint( $r['add'] );
		$qty = max( 1, min( 999, absint( $r['qty'] ) ?: 1 ) );
		if ( $add ) {
			$p = wc_get_product( $add );
			if ( $p && $p->is_purchasable() ) {
				$line       = (float) wc_get_price_excluding_tax( $p, array( 'qty' => $qty ) );
				$contents[] = array( 'data' => $p, 'product_id' => $p->get_parent_id() ?: $p->get_id(), 'quantity' => $qty, 'line_subtotal' => $line );
				$subtotal  += $line;
			}
		}
		if ( ! $contents ) {
			return rest_ensure_response( array( 'empty' => true ) );
		}
		$country = WC()->customer ? (string) ( WC()->customer->get_shipping_country() ?: WC()->customer->get_billing_country() ) : '';
		$country = '' !== $country ? $country : 'US';
		$state   = WC()->customer ? (string) WC()->customer->get_shipping_state() : '';
		$quotes  = class_exists( Shipping\Plans::class ) ? Shipping\Plans::quote( array( 'contents' => $contents, 'destination' => array( 'country' => $country, 'state' => $state, 'postcode' => '' ) ) ) : array();
		if ( ! $quotes ) {
			return rest_ensure_response( array( 'unknown' => true ) );
		}
		$ship = min( array_map( static fn( $q ) => (float) $q['cost'], $quotes ) );
		// 运费优惠（例如运费 5% off）按当前对所有顾客生效的那条算。
		if ( class_exists( Shipping\Discounts::class ) && $ship > 0 ) {
			$best = $ship;
			foreach ( Shipping\Discounts::applicable() as $pair ) {
				[ $d, $v ] = $pair;
				$best = min( $best, Shipping\Discounts::discounted( $ship, (string) $d['kind'], (float) $v ) );
			}
			$ship = $best;
		}
		$total = max( 0, $subtotal - $discount ) + $ship;
		return rest_ensure_response(
			array(
				'shipping'      => $ship,
				'shipping_text' => $ship > 0 ? self::money( $ship ) : 'Free',
				'total'         => $total,
				'total_text'    => self::money( $total ),
				'with_cart'     => $add && $in_cart > 0,
			)
		);
	}
}
