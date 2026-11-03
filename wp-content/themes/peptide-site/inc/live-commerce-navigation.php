<?php
/** Storefront entry points and browser history; no alternate cart or checkout. */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function (): void {
    $settings = function_exists( 'vc_live_product_settings' ) ? vc_live_product_settings() : array();
    $cart_enabled = function_exists( 'vc_live_cart_available' ) && vc_live_cart_available();
    $product_enabled = ! empty( $settings['enabled'] ) && ( ! ( function_exists( 'is_checkout' ) && is_checkout() ) || $cart_enabled );
    if ( is_admin() || ( ! $product_enabled && ! $cart_enabled ) ) { return; }
    if ( $product_enabled ) { vc_home_quick_view_assets(); }
    $dependencies = $product_enabled ? array( 'vc-home-quick-view' ) : array();
    if ( $cart_enabled ) { $dependencies[] = 'vc-live-cart-experience'; }
    $path = get_template_directory() . '/assets/live-commerce-navigation.js';
    wp_enqueue_script( 'vc-live-commerce-navigation', get_template_directory_uri() . '/assets/live-commerce-navigation.js', $dependencies, (string) filemtime( $path ), true );
    wp_localize_script( 'vc-live-commerce-navigation', 'VialCoveCommerceNavigation', array(
        'cartUrl' => wc_get_cart_url(),
        'shopUrl' => vc_url( 'shop' ),
    ) );
}, 95 );

/** Keep real permalinks as non-JavaScript / open-in-new-tab fallbacks. */
function vc_live_product_link_attributes( $product ): string {
    if ( ! $product instanceof WC_Product || ! function_exists( 'vc_live_product_settings' )
        || empty( vc_live_product_settings( $product->get_id() )['enabled'] )
        || ! class_exists( '\\PeptideCore\\Modules\\Showcase_Quick_View' )
        || ! \PeptideCore\Modules\Showcase_Quick_View::supports( $product ) ) { return ''; }
    return ' data-vc-home-product="' . esc_attr( (string) $product->get_id() ) . '" data-vc-quick-open="' . esc_attr( (string) $product->get_id() ) . '"';
}
