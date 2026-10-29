<?php
/** Native WooCommerce Blocks price annotations; all amounts remain backend-owned. */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function (): void {
	if ( ! function_exists( 'is_cart' ) || ( ! is_cart() && ! is_checkout() ) || is_order_received_page() ) {
		return;
	}
	if ( ! wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
		return;
	}
	$base = get_template_directory();
	$uri  = get_template_directory_uri();
	wp_enqueue_style( 'vialcove-cart-pricing', $uri . '/assets/cart-pricing.css', array( 'vialcove-sale-pricing' ), (string) filemtime( $base . '/assets/cart-pricing.css' ) );
	wp_enqueue_script( 'vialcove-cart-pricing', $uri . '/assets/cart-pricing.js', array( 'wc-blocks-checkout', 'wc-blocks-data-store', 'wp-data', 'wp-element', 'wp-plugins' ), (string) filemtime( $base . '/assets/cart-pricing.js' ), true );
}, 35 );
