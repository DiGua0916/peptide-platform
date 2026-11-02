<?php
/** Cart-only presentation. Personal cart data stays in the Store API response. */
namespace PeptideCore\Activity;

defined( 'ABSPATH' ) || exit;

final class Mount {
	private static bool $rendered = false;
	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ), 30 );
		add_action( 'woocommerce_before_cart', array( self::class, 'classic' ), 8 );
		add_filter( 'render_block_woocommerce/cart', array( self::class, 'block' ), 10, 2 );
	}
	public static function is_cart_page(): bool {
		return function_exists( 'is_cart' ) && is_cart() && ( ! function_exists( 'is_checkout' ) || ! is_checkout() );
	}
	public static function markup(): string {
		if ( ! self::is_cart_page() ) { return ''; }
		$config = Store::effective(); if ( empty( $config['enabled'] ) || empty( $config['promotionId'] ) ) { return ''; }
		$chinese = str_starts_with( determine_locale(), 'zh' );
		$data = array( 'config' => $config, 'extensionNamespace' => 'peptide_core', 'extensionKey' => 'activity_listener', 'cartUrl' => rest_url( 'wc/store/v1/cart' ), 'configUrl' => rest_url( 'peptide/v1/activity-listener' ),
			'blockCart' => function_exists( 'has_block' ) && has_block( 'woocommerce/cart', get_post( wc_get_page_id( 'cart' ) ) ),
			'mascotUrl' => plugins_url( 'assets/images/activity-listener-bean.png', PEPTIDE_CORE_FILE ),
			'labels' => array( 'current' => $chinese ? '当前' : __( 'Current', 'peptide-core' ), 'remaining' => $chinese ? '还差' : __( 'To next tier', 'peptide-core' ), 'complete' => $chinese ? '已达最高档' : __( 'Highest tier reached', 'peptide-core' ), 'progress' => $chinese ? '优惠进度' : __( 'Offer progress', 'peptide-core' ), 'noSavings' => $chinese ? '当前未应用优惠' : __( 'No offer savings applied', 'peptide-core' ) ),
		);
		$json = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : '<div class="pc-activity-listener" data-pc-activity-listener data-cart-only hidden><script type="application/json" class="pc-activity-listener__config">' . $json . '</script></div>';
	}
	public static function assets(): void {
		if ( ! self::is_cart_page() ) { return; }
		$config = Store::effective();
		if ( empty( $config['enabled'] ) || empty( $config['promotionId'] ) ) { return; }
		$base = PEPTIDE_CORE_DIR . '/assets/';
		$deps = array( 'wp-data', 'wp-api-fetch', 'jquery' );
		if ( wp_script_is( 'wc-blocks-data-store', 'registered' ) ) { $deps[] = 'wc-blocks-data-store'; }
		wp_enqueue_style( 'pc-activity-listener', plugins_url( 'assets/activity-listener.css', PEPTIDE_CORE_FILE ), array(), (string) filemtime( $base . 'activity-listener.css' ) );
		wp_enqueue_script( 'pc-activity-listener', plugins_url( 'assets/activity-listener.js', PEPTIDE_CORE_FILE ), $deps, (string) filemtime( $base . 'activity-listener.js' ), true );
	}
	private static function once(): string {
		if ( self::$rendered ) { return ''; }
		$html = self::markup(); if ( '' !== $html ) { self::$rendered = true; } return $html;
	}
	public static function classic(): void { echo self::once(); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup and HEX-encoded public configuration.
	public static function block( string $html, array $parsed = array() ): string { return self::once() . $html; }
}
