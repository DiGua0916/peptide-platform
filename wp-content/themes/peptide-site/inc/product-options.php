<?php
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) { return; }
    $root = get_template_directory_uri();
    wp_enqueue_style( 'vialcove-product-options', $root . '/assets/product-options.css', array( 'vialcove-navigation' ), (string) filemtime( __DIR__ . '/../assets/product-options.css' ) );
    $product = wc_get_product( get_queried_object_id() );
    if ( $product && $product->is_type( 'variable' ) ) {
        wp_enqueue_script( 'vialcove-product-options', $root . '/assets/product-options.js', array( 'jquery', 'wc-add-to-cart-variation' ), (string) filemtime( __DIR__ . '/../assets/product-options.js' ), true );
    }
}, 20 );

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
