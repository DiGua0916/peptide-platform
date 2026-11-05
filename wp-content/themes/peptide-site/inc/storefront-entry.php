<?php
/** Public catalogue entry compatibility and dialog presentation. No purchase transport. */
defined( 'ABSPATH' ) || exit;

function vc_storefront_entry_public_request(): bool {
    if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) || is_admin()
        || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) { return false; }
    foreach ( array( 'REST_REQUEST', 'XMLRPC_REQUEST', 'WP_CLI' ) as $constant ) { if ( defined( $constant ) && constant( $constant ) ) { return false; } }
    $path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
    if ( ! is_string( $path ) || preg_match( '/[\\\\\x00-\x1f\x7f]|%[a-f0-9]{2}/i', rawurldecode( $path ) ) || preg_match( '~(?:^|/)(?:wp-admin|wp-login\.php|wp-json|cart|checkout|order-pay|order-received|feed|embed)(?:/|$)~i', rawurldecode( $path ) ) ) { return false; }
    foreach ( array_keys( $_GET ) as $key ) {
        if ( ! is_string( $key ) || preg_match( '/preview|customiz|editor|design|nonce|token|signature|payment|panapay|order[_-]?key/i', $key )
            || in_array( strtolower( $key ), array( 'rest_route', 'wc-ajax', 'add-to-cart', 'remove_item', 'undo_item', 'quantity', 'download_file', 'key', 'order_key', 'sig', 'action', 'redirect_to', 'wc-api', 'pay_for_order' ), true ) ) { return false; }
    }
    foreach ( array( 'is_feed', 'is_trackback', 'is_embed', 'is_preview', 'is_cart', 'is_checkout' ) as $condition ) { if ( function_exists( $condition ) && $condition() ) { return false; } }
    return ! ( class_exists( '\PeptideCore\Templates' ) && \PeptideCore\Templates::previewing_id() );
}

function vc_storefront_entry_enabled( int $product_id = 0 ): bool {
    if ( ! function_exists( 'vc_live_product_settings' ) ) { return false; }
    $settings = vc_live_product_settings( $product_id );
    $device = function_exists( 'wp_is_mobile' ) && wp_is_mobile() ? 'mobile' : 'desktop';
    return ! empty( $settings['enabled'] ) && ! empty( $settings['mobileDialog'] ) && false !== ( $settings['visibility'][ $device ] ?? true );
}

function vc_storefront_entry_product( int $id ) {
    $product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
    return class_exists( '\PeptideCore\Modules\Products' ) && class_exists( '\PeptideCore\Modules\Showcase_Quick_View' )
        && \PeptideCore\Modules\Products::public_catalog_product( $product ) && \PeptideCore\Modules\Showcase_Quick_View::supports( $product )
        && vc_storefront_entry_enabled( $id ) ? $product : null;
}

/** Redirect only a normal old catalogue/PDP read, temporarily; preserve native entities. */
function vc_storefront_entry_destination(): string {
    if ( ! vc_storefront_entry_public_request() || is_front_page() ) { return ''; }
    $args = array();
    $product_route = function_exists( 'is_product' ) && is_product();
    $category_route = function_exists( 'is_product_category' ) && is_product_category();
    $search_route = is_search() && 'product' === get_query_var( 'post_type' );
    if ( $product_route ) {
        $id = (int) get_queried_object_id();
        if ( ! vc_storefront_entry_product( $id ) ) { return ''; }
        $args['vc_product'] = $id;
    } elseif ( ( function_exists( 'is_shop' ) && is_shop() ) || $category_route || $search_route ) {
        if ( ! vc_storefront_entry_enabled() ) { return ''; }
        if ( $category_route ) { $args['vc_catalog_category'] = (int) get_queried_object_id(); }
        $search = get_search_query( false );
        if ( is_string( $search ) && '' !== trim( $search ) ) { $args['vc_catalog_q'] = mb_substr( $search, 0, 200 ); }
    } else { return ''; }
    foreach ( array( 'pc_lang', 'pc_lang_auto', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid' ) as $key ) {
        $value = $_GET[ $key ] ?? null;
        if ( ! is_string( $value ) || strlen( $value ) > 2048 ) { continue; }
        if ( 'pc_lang' === $key && ! in_array( $value, array( 'en', 'es' ), true ) ) { continue; }
        if ( 'pc_lang_auto' === $key && '1' !== $value ) { continue; }
        $args[ $key ] = wp_unslash( $value );
    }
    foreach ( $_GET as $key => $value ) {
        if ( is_string( $key ) && preg_match( '/\Aattribute_[a-z0-9_-]{1,80}\z/D', $key ) && is_string( $value ) && strlen( $value ) <= 200 ) { $args[ $key ] = wp_unslash( $value ); }
    }
    return add_query_arg( $args, home_url( '/' ) ) . '#vc-products';
}

add_action( 'template_redirect', static function (): void {
    $destination = vc_storefront_entry_destination();
    if ( '' !== $destination ) { nocache_headers(); if ( ! headers_sent() ) { header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' ); } wp_safe_redirect( $destination, 302, 'VialCove Storefront Entry' ); exit; }
}, 4 );

/** Public content identity, including the native presenters this adapter depends on. */
function vc_storefront_entry_release(): string {
    static $release = null;
    if ( null === $release ) {
        $parts = array();
        foreach ( array( 'inc/storefront-entry.php', 'assets/product-dialog-polish.js', 'assets/product-dialog-polish.css', 'assets/home-quick-view.js', 'assets/product-dialog-experience.js', 'assets/demo-catalog.js', 'assets/live-commerce-navigation.js' ) as $path ) {
            $file = get_template_directory() . '/' . $path;
            $parts[] = $path . ':' . ( is_readable( $file ) ? hash_file( 'sha256', $file ) : 'absent' );
        }
        $release = hash( 'sha256', implode( "\n", $parts ) );
    }
    return $release;
}
add_action( 'rest_api_init', static function (): void {
    register_rest_route( 'vialcove/v1', '/storefront-entry', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => static function (): WP_REST_Response {
        $response = new WP_REST_Response( array( 'version' => 1, 'release' => vc_storefront_entry_release() ) );
        $response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
        return $response;
    } ) );
} );

add_action( 'wp_enqueue_scripts', static function (): void {
    if ( ! vc_storefront_entry_public_request() ) { return; }
    $entry = $_GET['vc_product'] ?? '';
    $id = is_string( $entry ) && preg_match( '/\A[1-9][0-9]{0,9}\z/D', $entry ) && (int) $entry <= 2147483647 ? (int) $entry : 0;
    if ( ! vc_storefront_entry_enabled( $id ) ) { return; }
    vc_home_quick_view_assets();
    $dependencies = array( 'vc-home-quick-view', 'vc-product-dialog-experience', 'vc-product-dialog-offer-follow' );
    if ( wp_script_is( 'vc-live-commerce-navigation', 'enqueued' ) ) { $dependencies[] = 'vc-live-commerce-navigation'; }
    if ( class_exists( '\PeptideCore\Campaign_Countdown' ) ) { \PeptideCore\Campaign_Countdown::enqueue_assets(); $dependencies[] = 'pc-campaign-countdown'; }
    wp_enqueue_style( 'vc-product-dialog-polish', get_template_directory_uri() . '/assets/product-dialog-polish.css', array( 'vc-home-quick-view' ), (string) filemtime( get_template_directory() . '/assets/product-dialog-polish.css' ) );
    wp_enqueue_script( 'vc-product-dialog-polish', get_template_directory_uri() . '/assets/product-dialog-polish.js', $dependencies, (string) filemtime( get_template_directory() . '/assets/product-dialog-polish.js' ), true );
    $category = $_GET['vc_catalog_category'] ?? '';
    $category_ids = array();
    if ( is_string( $category ) && preg_match( '/\A[1-9][0-9]{0,9}\z/D', $category ) ) {
        $term = get_term( (int) $category, 'product_cat' );
        if ( $term instanceof WP_Term ) {
            $category_ids[] = (string) $term->term_id; $children = get_term_children( $term->term_id, 'product_cat' );
            if ( ! is_wp_error( $children ) ) { foreach ( array_slice( $children, 0, 299 ) as $child ) { $category_ids[] = (string) (int) $child; } }
        }
    }
    $query = $_GET['vc_catalog_q'] ?? '';
    wp_localize_script( 'vc-product-dialog-polish', 'vcStorefrontEntry', array(
        'version' => 1, 'release' => vc_storefront_entry_release(), 'releaseUrl' => rest_url( 'vialcove/v1/storefront-entry' ),
        'productId' => $id, 'productAvailable' => $id ? (bool) vc_storefront_entry_product( $id ) : false,
        'catalog' => array( 'query' => is_string( $query ) ? mb_substr( wp_unslash( $query ), 0, 200 ) : '', 'categoryIds' => $category_ids, 'requestedCategory' => array_key_exists( 'vc_catalog_category', $_GET ) ),
        'campaign' => class_exists( '\PeptideCore\Campaign_Countdown' ) ? \PeptideCore\Campaign_Countdown::for_position( 'product' ) : null,
        'campaignUrl' => rest_url( 'peptide/v1/campaign-countdown' ),
    ) );
}, 110 );
