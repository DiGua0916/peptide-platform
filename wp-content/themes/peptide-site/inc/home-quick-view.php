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
    wp_enqueue_style( 'vc-live-cart-feedback', $base . 'live-cart-feedback.css', array(), (string) filemtime( $path . 'live-cart-feedback.css' ) );
    wp_enqueue_script( 'vc-live-cart-feedback', $base . 'live-cart-feedback.js', array(), (string) filemtime( $path . 'live-cart-feedback.js' ), true );
    foreach ( array( 'product-purchase-dock', 'product-purchase-return', 'product-unit-pricing', 'product-dialog-meta' ) as $name ) {
        $dependencies = 'product-dialog-meta' === $name ? array( 'vc-product-unit-pricing', 'jquery' ) : array();
        wp_enqueue_script( 'vc-' . $name, $base . $name . '.js', $dependencies, (string) filemtime( $path . $name . '.js' ), true );
    }
    foreach ( array( 'ticker-model', 'ticker', 'report-viewer', 'feedback', 'experience', 'recent' ) as $part ) {
        $name = 'product-dialog-' . $part;
        $dependencies = 'experience' === $part ? array( 'vc-product-purchase-dock', 'vc-product-purchase-return', 'vc-product-dialog-meta' ) : array();
        wp_enqueue_script( 'vc-' . $name, $base . $name . '.js', $dependencies, (string) filemtime( $path . $name . '.js' ), true );
    }
    wp_enqueue_script( 'vc-home-quick-view', $base . 'home-quick-view.js', array( 'jquery', 'peptide-showcase-quick-view', 'vc-live-cart-feedback', 'vc-product-dialog-ticker-model', 'vc-product-dialog-ticker', 'vc-product-dialog-report-viewer', 'vc-product-dialog-feedback', 'vc-product-dialog-experience', 'vc-product-dialog-recent' ), (string) filemtime( $path . 'home-quick-view.js' ), true );
    wp_localize_script( 'vc-home-quick-view', 'vcHomeQuickView', array(
        'productApi' => rest_url( 'vialcove/v1/home-product/' ),
        'wpNonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
        'reportApi' => rest_url( 'peptide/v1/report-library' ),
        'cartUrl' => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
        'assetBase' => $base,
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
        'args' => array(
            'id' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX ),
            'card' => array( 'type' => 'boolean', 'default' => false ),
            'variation' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX ),
        ),
        'callback' => 'vc_home_quick_view_response',
    ) );
} );

function vc_home_quick_view_response( WP_REST_Request $request ) {
    $product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $request['id'] ) : null;
    if ( ! class_exists( '\PeptideCore\Modules\Products' ) || ! \PeptideCore\Modules\Products::public_catalog_product( $product )
        || ! class_exists( '\PeptideCore\Modules\Showcase_Quick_View' ) || ! \PeptideCore\Modules\Showcase_Quick_View::supports( $product ) ) {
        return new WP_Error( 'vc_product_unavailable', 'This product is not available. Open the product page to continue.', array( 'status' => 404 ) );
    }
    if ( true === $request['card'] ) {
        $card = vc_home_quick_view_card( $product, $request->has_param( 'variation' ) ? (int) $request['variation'] : 0 );
        if ( ! $card ) { return new WP_Error( 'vc_product_unavailable', 'This specification is not available. Choose another option.', array( 'status' => 404 ) ); }
        $response = new WP_REST_Response( array( 'id' => $product->get_id(), 'card' => $card ) );
        $response->header( 'Cache-Control', 'private, no-store' );
        return $response;
    }
    $html = vc_home_quick_view_template( $product );
    if ( '' === $html ) { return new WP_Error( 'vc_product_unavailable', 'Open the product page to continue.', array( 'status' => 404 ) ); }
    $response = new WP_REST_Response( array( 'id' => $product->get_id(), 'html' => $html ) );
    $response->header( 'Cache-Control', 'private, no-store' );
    return $response;
}

/** A display SKU never grants purchase authority; the native add route checks it again. */
function vc_home_quick_view_card_can_add_one( $product, $sku ): bool {
    if ( ! $product->is_purchasable() || ! $product->is_in_stock() || ! $sku->is_purchasable() || ! $sku->is_in_stock() ) { return false; }
    if ( $sku->is_type( 'variation' ) ) {
        $attributes = $sku->get_variation_attributes(); $required = $product->get_variation_attributes();
        if ( ! $required ) { return false; }
        foreach ( $required as $name => $options ) {
            $value = $attributes[ wc_variation_attribute_name( $name ) ] ?? null;
            if ( ! is_string( $value ) || '' === trim( $value ) ) { return false; }
        }
        foreach ( $attributes as $value ) { if ( ! is_string( $value ) || '' === trim( $value ) ) { return false; } }
    }
    $limits_class = '\\Automattic\\WooCommerce\\StoreApi\\Utilities\\QuantityLimits';
    if ( ! class_exists( $limits_class ) ) { return false; }
    try {
        // Match native product reads: held-stock limits need Woo's ordinary
        // session handler. Preparing it may refresh an anonymous session record,
        // but must not load/create a cart or perform a cart/order mutation here.
        if ( $sku->managing_stock() && ! $sku->backorders_allowed() && null !== $sku->get_stock_quantity() ) {
            $woocommerce = function_exists( 'WC' ) ? WC() : null;
            if ( ! is_object( $woocommerce ) ) { return false; }
            if ( ! ( $woocommerce->session ?? null ) ) {
                if ( ! method_exists( $woocommerce, 'initialize_session' ) ) { return false; }
                $woocommerce->initialize_session();
            }
            if ( ! ( $woocommerce->session ?? null ) ) { return false; }
        }
        $limits = ( new $limits_class() )->get_add_to_cart_limits( $sku );
        foreach ( array( 'minimum', 'maximum', 'multiple_of' ) as $key ) {
            if ( ! isset( $limits[ $key ] ) || ! is_numeric( $limits[ $key ] ) || ! is_finite( (float) $limits[ $key ] ) ) { return false; }
        }
        $step = (float) $limits['multiple_of'];
        return $step > 0 && (float) $limits['minimum'] <= 1 && (float) $limits['maximum'] >= 1
            && abs( 1 / $step - round( 1 / $step ) ) < 0.0001;
    } catch ( Throwable $error ) { return false; }
}

/** Read one exact SKU's label, image and display price without rendering a form. */
function vc_home_quick_view_card( $product, int $variation_id = 0 ): ?array {
    if ( ! $product instanceof WC_Product || ! \PeptideCore\Modules\Products::public_catalog_product( $product )
        || ! \PeptideCore\Modules\Showcase_Quick_View::supports( $product ) ) { return null; }
    $sku = $variation_id ? wc_get_product( $variation_id ) : ( function_exists( 'vc_card_display_product' ) ? vc_card_display_product( $product ) : $product );
    if ( $product->is_type( 'variable' ) ) {
        if ( ! $sku instanceof WC_Product_Variation || 'product_variation' !== get_post_type( $sku->get_id() )
            || $sku->get_parent_id() !== $product->get_id() || 'publish' !== $sku->get_status()
            || '' !== $sku->get_post_password() || '' !== (string) get_post_field( 'post_password', $sku->get_id(), 'raw' )
            || ! $sku->variation_is_active() || ! $sku->variation_is_visible() ) { return null; }
    } elseif ( $variation_id || $sku !== $product || ! $product->is_type( 'simple' ) ) { return null; }
    if ( '' === $sku->get_price() ) { return null; }
    $price = wc_get_price_to_display( $sku );
    if ( ! is_numeric( $price ) || ! is_finite( (float) $price ) || (float) $price < 0 ) { return null; }
    $plain = static fn( $value ) => html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' );
    $image = vc_home_quick_view_image( $sku );
    return array(
        'productId' => $product->get_id(), 'purchaseId' => $sku->get_id(),
        'variationId' => $sku->is_type( 'variation' ) ? $sku->get_id() : 0,
        'variationLabel' => $sku->is_type( 'variation' ) ? $plain( wc_get_formatted_variation( $sku, true, false, false ) ) : '',
        'price' => array( 'text' => $plain( wc_price( $price ) ) ),
        'image' => array( 'url' => $image['src'] ?? '', 'alt' => $image['alt'] ?? $plain( $product->get_name() ) ),
        'canAddOne' => vc_home_quick_view_card_can_add_one( $product, $sku ),
    );
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
    $data['vc_home_promotions'] = vc_home_quick_view_promotions( $variation );
    return $data;
}, 30, 3 );

/** Read the existing public, eligible promotion DTO; no frontend discount engine. */
function vc_home_quick_view_promotions( $product ): array {
    if ( ! class_exists( '\PeptideCore\Promotions' ) ) { return array(); }
    $items = \PeptideCore\Promotions::for_product( $product );
    $plain = static fn( $value ) => html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' );
    foreach ( $items as &$item ) {
        foreach ( $item['tiers'] as &$tier ) {
            $tier['thresholdText'] = 'spend' === $item['basis'] ? $plain( wc_price( $tier['min'] ) ) : (string) $tier['min'];
            $tier['rewardText'] = $plain( \PeptideCore\Promotions::reward_text( $item, $tier ) );
            $tier['text'] = $plain( $tier['text'] );
        }
        unset( $tier );
    }
    unset( $item );
    return $items;
}

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
        'variationId' => $first->is_type( 'variation' ) ? $first->get_id() : 0,
        'unitPricing' => function_exists( 'peptide_core_get_unit_pricing' ) ? peptide_core_get_unit_pricing( $first ) : null,
        'ratingHtml' => $product->get_rating_count() > 0 ? wp_kses_post( wc_get_rating_html( (float) $product->get_average_rating(), $product->get_rating_count() ) ) : '',
        'ratingCount' => $product->get_rating_count(),
        'promotions' => vc_home_quick_view_promotions( $first ),
        'priceHtml' => wp_kses_post( function_exists( 'vc_public_price_html' ) ? vc_public_price_html( $first ) : $first->get_price_html() ),
    );
    $tags = new WP_HTML_Tag_Processor( $html );
    if ( $tags->next_tag( array( 'tag_name' => 'DIV', 'class_name' => 'vc-showcase-quick-product' ) ) ) {
        $tags->add_class( 'vc-home-quick-product' );
        $tags->set_attribute( 'data-vc-home-initial', wp_json_encode( $initial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) );
        $settings = function_exists( 'vc_live_product_settings' ) ? vc_live_product_settings( $product->get_id() ) : array( 'enabled' => false );
        $tags->set_attribute( 'data-vc-experience', wp_json_encode( $settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) );
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
