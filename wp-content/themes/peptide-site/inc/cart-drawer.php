<?php
/** Desktop cart presentation backed by the existing WooCommerce Store API. */
defined( 'ABSPATH' ) || exit;

function vc_has_cart_drawer(): bool {
    // Native Blocks own the full cart/checkout pages and their live data store.
    return function_exists( 'WC' ) && ! is_cart() && ! is_checkout();
}

add_action( 'wp_enqueue_scripts', function (): void {
    if ( ! vc_has_cart_drawer() ) { return; }
    $path = get_template_directory();
    $uri = get_template_directory_uri();
    wp_enqueue_style( 'vialcove-cart-drawer', $uri . '/assets/cart-drawer.css', array( 'vialcove-brand' ), (string) filemtime( $path . '/assets/cart-drawer.css' ) );
    wp_enqueue_script( 'vialcove-cart-drawer', $uri . '/assets/cart-drawer.js', array(), (string) filemtime( $path . '/assets/cart-drawer.js' ), true );
    wp_localize_script( 'vialcove-cart-drawer', 'VialCoveCart', array(
        'apiUrl' => rest_url( 'wc/store/v1/cart' ),
        'cartUrl' => wc_get_cart_url(),
        'checkoutUrl' => wc_get_checkout_url(),
        'shopUrl' => vc_url( 'shop' ),
        'wpNonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
        'inclusiveTax' => 'incl' === get_option( 'woocommerce_tax_display_cart' ),
    ) );
}, 30 );

add_action( 'wp_footer', function (): void {
    if ( vc_has_cart_drawer() ) { get_template_part( 'template-parts/cart-drawer' ); }
}, 5 );
