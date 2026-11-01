<?php
/** Homepage presentation for the plugin's native WooCommerce quick-view flow. */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/product-dialog-content.php';

/** This adapter adds no cart endpoint, variation matcher, or browser cart storage. */
function vc_home_quick_view_assets(): void {
    if ( ! class_exists( '\PeptideCore\Modules\Showcase_Quick_View' ) ) { return; }
    $base = get_template_directory_uri() . '/assets/';
    $path = get_template_directory() . '/assets/';
    // Prime the original button presenter even when a paged catalogue's first
    // supported variable product is not included in the initial server batch.
    wp_enqueue_script( 'vialcove-product-options', $base . 'product-options.js', array( 'jquery', 'wc-add-to-cart-variation' ), (string) filemtime( $path . 'product-options.js' ), true );
    \PeptideCore\Modules\Showcase_Quick_View::catalog_assets();
    wp_enqueue_style( 'vc-home-quick-view', $base . 'home-quick-view.css', array( 'vialcove-product-options' ), (string) filemtime( $path . 'home-quick-view.css' ) );
    wp_enqueue_script( 'vc-home-quick-view', $base . 'home-quick-view.js', array( 'jquery', 'peptide-showcase-quick-view' ), (string) filemtime( $path . 'home-quick-view.js' ), true );
    wp_localize_script( 'vc-home-quick-view', 'vcHomeQuickView', array(
        'productApi' => rest_url( 'vialcove/v1/home-product/' ),
        'wpNonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
    ) );
}

add_action( 'wp_enqueue_scripts', function (): void {
    if ( is_front_page() ) { vc_home_quick_view_assets(); }
}, 35 );

/** Read one public native form on demand; cart writes remain the plugin's Store API flow. */
add_action( 'rest_api_init', function (): void {
    register_rest_route( 'vialcove/v1', '/home-product/(?P<id>[1-9][0-9]*)', array(
        'methods' => WP_REST_Server::READABLE,
        'permission_callback' => '__return_true',
        'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX ) ),
        'callback' => 'vc_home_quick_view_response',
    ) );
} );

function vc_home_quick_view_response( WP_REST_Request $request ) {
    $product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $request['id'] ) : null;
    if ( ! class_exists( '\PeptideCore\Modules\Products' ) || ! \PeptideCore\Modules\Products::public_catalog_product( $product )
        || ! class_exists( '\PeptideCore\Modules\Showcase_Quick_View' ) || ! \PeptideCore\Modules\Showcase_Quick_View::supports( $product ) ) {
        return new WP_Error( 'vc_product_unavailable', 'This product is not available. Open the product page to continue.', array( 'status' => 404 ) );
    }
    $html = vc_home_quick_view_template( $product );
    if ( '' === $html ) { return new WP_Error( 'vc_product_unavailable', 'Open the product page to continue.', array( 'status' => 404 ) ); }
    $response = new WP_REST_Response( array( 'id' => $product->get_id(), 'html' => $html ) );
    $response->header( 'Cache-Control', 'private, no-store' );
    return $response;
}

/** Do not substitute a parent's different dosage when a variation has no artwork. */
function vc_home_quick_view_image( $product ): ?array {
    $id = $product->get_image_id( $product->is_type( 'variation' ) ? 'edit' : 'view' );
    $image = $id ? wp_get_attachment_image_src( $id, 'woocommerce_single' ) : false;
    if ( ! $image ) { return null; }
    return array(
        'src' => $image[0], 'width' => (int) $image[1], 'height' => (int) $image[2],
        'srcset' => wp_get_attachment_image_srcset( $id, 'woocommerce_single' ) ?: '',
        'sizes' => '(max-width: 767px) calc(100vw - 64px), 500px',
        'alt' => $product->get_name(),
    );
}

/** Native AJAX variation responses need the same exact-image semantics as the cards. */
add_filter( 'woocommerce_available_variation', function ( array $data, $parent, $variation ): array {
    $data['vc_home_image'] = vc_home_quick_view_image( $variation );
    return $data;
}, 30, 3 );

/**
 * Render once per public product. Links retain the real permalink and use both
 * data-vc-home-product and data-vc-quick-open to opt into this presentation.
 * Storage, public eligibility, native forms and cart transport remain plugin-owned.
 */
function vc_home_quick_view_template( $product ): string {
    if ( ! class_exists( '\PeptideCore\Modules\Showcase_Quick_View' ) || ! \PeptideCore\Modules\Showcase_Quick_View::supports( $product ) ) { return ''; }
    vc_home_quick_view_assets();
    $first = function_exists( 'vc_card_display_product' ) ? vc_card_display_product( $product ) : $product;
    $attributes = $first->is_type( 'variation' ) ? $first->get_variation_attributes() : array();
    $select_first = static function ( array $args ) use ( $product, $attributes ): array {
        if ( ! isset( $args['product'] ) || ! $args['product'] instanceof WC_Product || $args['product']->get_id() !== $product->get_id() ) { return $args; }
        $key = wc_variation_attribute_name( (string) ( $args['attribute'] ?? '' ) );
        // An "any" attribute is deliberately left to the native selection UI.
        if ( isset( $attributes[ $key ] ) && '' !== $attributes[ $key ] ) { $args['selected'] = $attributes[ $key ]; }
        return $args;
    };
    add_filter( 'woocommerce_dropdown_variation_attribute_options_args', $select_first, PHP_INT_MAX );
    try { $html = \PeptideCore\Modules\Showcase_Quick_View::template( $product ); }
    finally { remove_filter( 'woocommerce_dropdown_variation_attribute_options_args', $select_first, PHP_INT_MAX ); }
    if ( '' === $html ) { return ''; }
    $initial = array(
        'image' => vc_home_quick_view_image( $first ), 'sku' => $first->get_sku(), 'attributes' => $attributes,
        'priceHtml' => wp_kses_post( function_exists( 'vc_public_price_html' ) ? vc_public_price_html( $first ) : $first->get_price_html() ),
    );
    $tags = new WP_HTML_Tag_Processor( $html );
    if ( $tags->next_tag( array( 'tag_name' => 'DIV', 'class_name' => 'vc-showcase-quick-product' ) ) ) {
        $tags->add_class( 'vc-home-quick-product' );
        $tags->set_attribute( 'data-vc-home-initial', wp_json_encode( $initial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) );
    }
    $html = $tags->get_updated_html();
    // The native template has one product root. Keep the existing purchase form
    // intact and put the public PDP content in the same dialog, after its summary.
    $content = vc_product_dialog_content( $product );
    if ( '' !== $content ) {
        $html = preg_replace_callback( '~</div>\s*</template>\s*$~i', static fn( $match ) => $content . $match[0], $html, 1 );
    }
    return $html;
}
