<?php
/** Visitor collector. Endpoint, mode and enablement are owned by peptide-core. */
defined( 'ABSPATH' ) || exit;

function vc_visitor_page_context(): array {
    $context = array( 'page_type' => 'content', 'path' => '/' );
    if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
        return array( 'page_type' => 'order_received', 'path' => '/checkout/' );
    }
    if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
        return array( 'page_type' => 'order_pay', 'path' => '/checkout/' );
    }
    if ( function_exists( 'is_account_page' ) && is_account_page() ) {
        return array( 'page_type' => 'account', 'path' => '/my-account/' );
    }
    if ( function_exists( 'is_product' ) && is_product() ) {
        $product = wc_get_product( get_queried_object_id() );
        if ( $product ) {
            return array( 'page_type' => 'product', 'path' => wp_parse_url( $product->get_permalink(), PHP_URL_PATH ) ?: '/', 'product_id' => $product->get_id(), 'product_name' => $product->get_name() );
        }
    }
    if ( function_exists( 'is_cart' ) && is_cart() ) {
        $context = array( 'page_type' => 'cart', 'path' => wp_parse_url( wc_get_cart_url(), PHP_URL_PATH ) ?: '/cart/' );
    } elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
        $context = array( 'page_type' => 'checkout', 'path' => wp_parse_url( wc_get_checkout_url(), PHP_URL_PATH ) ?: '/checkout/' );
    } elseif ( is_front_page() ) {
        $context['page_type'] = 'home';
    } elseif ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
        $context = array( 'page_type' => 'shop', 'path' => wp_parse_url( wc_get_page_permalink( 'shop' ), PHP_URL_PATH ) ?: '/shop/' );
    } elseif ( is_singular() ) {
        $context['path'] = wp_parse_url( get_permalink(), PHP_URL_PATH ) ?: '/';
    }
    return $context;
}

add_action( 'wp_enqueue_scripts', function (): void {
    // The plugin supplies the documented protocol and same-site endpoint.
    $config = apply_filters( 'vialcove_visitor_observer_config', array( 'enabled' => false, 'test_mode' => true ) );
    if ( ! is_array( $config ) || true !== ( $config['enabled'] ?? false ) || 1 !== ( $config['protocol'] ?? null ) || empty( $config['endpoint'] ) ) { return; }
    if ( is_admin() || is_preview() || is_customize_preview() || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) { return; }
    if ( isset( $_GET['pc_frame'] ) || isset( $_GET['preview'] ) || isset( $_GET['pc_preview_token'] ) ) { return; }
    if ( class_exists( '\PeptideCore\Templates' ) && \PeptideCore\Templates::previewing_id() ) { return; }
    $root = get_template_directory();
    $url = get_template_directory_uri();
    wp_enqueue_script( 'vialcove-visitor-video', $url . '/assets/visitor-video.js', array(), (string) filemtime( $root . '/assets/visitor-video.js' ), true );
    wp_enqueue_script( 'vialcove-visitor-observer', $url . '/assets/visitor-tracking.js', array( 'vialcove-visitor-video' ), (string) filemtime( $root . '/assets/visitor-tracking.js' ), true );
    wp_add_inline_script( 'vialcove-visitor-observer', 'window.VialCoveVisitorPage = ' . wp_json_encode( vc_visitor_page_context(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
    $transport = array(
        'enabled' => true,
        'test_mode' => true === ( $config['test_mode'] ?? false ),
        'mode' => true === ( $config['test_mode'] ?? false ) ? 'test' : 'live',
        'protocol' => 1,
        'endpoint' => esc_url_raw( $config['endpoint'] ),
        'heartbeat_ms' => (int) ( $config['heartbeat_ms'] ?? 15000 ),
        'idle_ms' => (int) ( $config['idle_ms'] ?? 60000 ),
        'batch_max' => min( 20, max( 1, (int) ( $config['batch_max'] ?? 20 ) ) ),
    );
    wp_enqueue_script( 'vialcove-visitor-adapter', $url . '/assets/visitor-adapter.js', array( 'vialcove-visitor-observer' ), (string) filemtime( $root . '/assets/visitor-adapter.js' ), true );
    wp_add_inline_script( 'vialcove-visitor-adapter', 'window.VialCoveVisitorConfig = ' . wp_json_encode( $transport, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
}, 40 );
