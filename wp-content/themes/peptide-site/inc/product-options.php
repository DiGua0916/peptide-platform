<?php
defined( 'ABSPATH' ) || exit;

/** Both callers use the same existing files; only a real showcase form opts in off the PDP. */
function vc_enqueue_product_options( $product ): void {
    $root = get_template_directory_uri();
    wp_enqueue_style( 'vialcove-product-options', $root . '/assets/product-options.css', array( 'vialcove-navigation' ), (string) filemtime( __DIR__ . '/../assets/product-options.css' ) );
    if ( $product && $product->is_type( 'variable' ) ) {
        wp_enqueue_script( 'vialcove-product-options', $root . '/assets/product-options.js', array( 'jquery', 'wc-add-to-cart-variation' ), (string) filemtime( __DIR__ . '/../assets/product-options.js' ), true );
    }
}

add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) { return; }
    vc_enqueue_product_options( wc_get_product( get_queried_object_id() ) );
}, 20 );
add_action( 'peptide_core_showcase_quick_view_assets', 'vc_enqueue_product_options' );

/**
 * Imported catalogue attributes are named "Specificatio" (truncated at import).
 * Correct only that exact display label; attribute keys, variations and stored
 * order item meta are unchanged, so matching and unit pricing are unaffected.
 */
function vc_attribute_display_label( $label ) {
    return is_string( $label ) && 0 === strcasecmp( trim( $label ), 'Specificatio' ) ? 'Specification' : $label;
}
add_filter( 'woocommerce_attribute_label', 'vc_attribute_display_label', 20 );

// Specifications are the image selector on variable product pages. Keep the
// primary gallery image for WooCommerce's native variation image updates.
add_action( 'wp', function () {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) { return; }
    $product = wc_get_product( get_queried_object_id() );
    if ( $product && $product->is_type( 'variable' ) ) {
        remove_action( 'woocommerce_product_thumbnails', 'woocommerce_show_product_thumbnails', 20 );
    }
} );

// Single-spec products have no variation selector. Surface the published Pack
// from the plugin's public product extension without inventing another option.
add_action( 'woocommerce_single_product_summary', function () {
    global $product;
    if ( ! $product || ! $product->is_type( 'simple' ) ) { return; }
    foreach ( vc_product_data( $product )['specs'] ?? array() as $spec ) {
        if ( 0 !== strcasecmp( (string) ( $spec['label'] ?? '' ), 'Pack' ) || empty( $spec['value'] ) ) { continue; }
        echo '<div class="vc-single-pack"><span class="vc-pack-label">' . esc_html( $spec['label'] ) . '</span><span class="vc-pack-value">' . esc_html( $spec['value'] . ( empty( $spec['unit'] ) ? '' : ' ' . $spec['unit'] ) ) . '</span></div>';
    }
}, 25 );
