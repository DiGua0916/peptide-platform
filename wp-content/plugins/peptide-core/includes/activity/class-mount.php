<?php
/** Cart-only inert mounting contract. Personal cart data stays in the Store API response. */
namespace PeptideCore\Activity;

defined( 'ABSPATH' ) || exit;

final class Mount {
	private static bool $rendered = false;
	public static function register_hooks(): void {
		add_action( 'woocommerce_before_cart', array( self::class, 'classic' ), 8 );
		add_filter( 'render_block_woocommerce/cart', array( self::class, 'block' ), 10, 2 );
	}
	public static function is_cart_page(): bool {
		return function_exists( 'is_cart' ) && is_cart() && ( ! function_exists( 'is_checkout' ) || ! is_checkout() );
	}
	public static function markup(): string {
		if ( ! self::is_cart_page() ) { return ''; }
		$config = Store::effective(); if ( empty( $config['enabled'] ) || empty( $config['promotionId'] ) ) { return ''; }
		$data = array( 'config' => $config, 'extensionNamespace' => 'peptide_core', 'extensionKey' => 'activity_listener', 'cartUrl' => rest_url( 'wc/store/v1/cart' ), 'configUrl' => rest_url( 'peptide/v1/activity-listener' ) );
		$json = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : '<div class="pc-activity-listener" data-pc-activity-listener data-cart-only hidden><script type="application/json" class="pc-activity-listener__config">' . $json . '</script></div>';
	}
	private static function once(): string {
		if ( self::$rendered ) { return ''; }
		$html = self::markup(); if ( '' !== $html ) { self::$rendered = true; } return $html;
	}
	public static function classic(): void { echo self::once(); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup and HEX-encoded public configuration.
	public static function block( string $html, array $parsed = array() ): string { return self::once() . $html; }
}
