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

/** Keep only a native same-origin pathname; never return request/query contents. */
function vc_visitor_observation_path( $url ): ?string {
    if ( ! is_string( $url ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) { return null; }
    $parts = wp_parse_url( $url );
    $home = wp_parse_url( home_url( '/' ) );
    if ( ! is_array( $parts ) || ! is_array( $home ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return null; }
    foreach ( array( $parts, $home ) as $origin ) {
        if ( ! in_array( strtolower( $origin['scheme'] ?? '' ), array( 'http', 'https' ), true ) || empty( $origin['host'] ) ) { return null; }
    }
    $port = static function ( array $origin ): int { return (int) ( $origin['port'] ?? ( 'https' === strtolower( $origin['scheme'] ) ? 443 : 80 ) ); };
    if ( strtolower( $parts['scheme'] ) !== strtolower( $home['scheme'] ) || strtolower( $parts['host'] ) !== strtolower( $home['host'] ) || $port( $parts ) !== $port( $home ) ) { return null; }
    $path = $parts['path'] ?? '/';
    if ( ! is_string( $path ) || '' === $path || '/' !== $path[0] || str_starts_with( $path, '//' ) || strlen( $path ) > 500 || ! preg_match( '//u', $path ) || preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', $path ) ) { return null; }
    return $path;
}

/** Pure status classification; no installed global-range capability means unknown. */
function vc_visitor_observation_geo_status( $address, ?int $global_range_flag ): array {
    $public = null !== $global_range_flag && is_string( $address ) && false !== filter_var( $address, FILTER_VALIDATE_IP, $global_range_flag );
    if ( $public ) {
        // PHP's global-range filter admits multicast; it is not a visitor address.
        $packed = inet_pton( $address );
        if ( false === $packed || ( 4 === strlen( $packed ) && ord( $packed[0] ) >= 224 ) || ( 16 === strlen( $packed ) && 255 === ord( $packed[0] ) ) ) { $public = false; }
    }
    return array( 'status' => $public ? 'unavailable' : 'unknown' );
}

/** Derive classification capability only from the installed, guarded native constant. */
function vc_visitor_observation_geo(): array {
    $flag = defined( 'FILTER_FLAG_GLOBAL_RANGE' ) ? constant( 'FILTER_FLAG_GLOBAL_RANGE' ) : null;
    $status = vc_visitor_observation_geo_status( $_SERVER['REMOTE_ADDR'] ?? null, is_int( $flag ) ? $flag : null );
    if ( 'unavailable' === $status['status'] && is_callable( array( '\\PeptideCore\\Tracking\\Country_Provider', 'lookup' ) ) ) {
        try {
            $geo = \PeptideCore\Tracking\Country_Provider::lookup( $_SERVER['REMOTE_ADDR'] );
            if ( is_array( $geo ) && 'known' === ( $geo['status'] ?? null ) && is_string( $geo['country_code'] ?? null ) && 1 === preg_match( '/\A[A-Z]{2}\z/D', $geo['country_code'] ) ) { return array( 'status' => 'known', 'country_code' => $geo['country_code'] ); }
            if ( is_array( $geo ) && in_array( $geo['status'] ?? null, array( 'unknown', 'unavailable' ), true ) ) { return array( 'status' => $geo['status'] ); }
        } catch ( \Throwable $error ) { /* A local provider failure must not break the document. */ }
    }
    return $status;
}

/** A search term is read only when the installed receiver explicitly supports it. */
function vc_visitor_search_capable(): bool {
    foreach ( array( 'clean', 'saved', 'replay', 'search_protocol' ) as $method ) { if ( ! is_callable( array( '\\PeptideCore\\Tracking\\Page_Context', $method ) ) ) { return false; } }
    foreach ( array( 'source', 'clean', 'saved' ) as $method ) { if ( ! is_callable( array( '\\PeptideCore\\Tracking\\Search_Context', $method ) ) ) { return false; } }
    return 1 === \PeptideCore\Tracking\Page_Context::search_protocol();
}

/** Read only an already validated native result state; never parse request/query values here. */
function vc_visitor_search_observation(): ?array {
    if ( ! vc_visitor_search_capable() ) { return null; }
    $native = '\\PeptideCore\\Public_Site_Search';
    if ( is_callable( array( $native, 'is_results_request' ) ) ) {
        try { $results = $native::is_results_request(); } catch ( \Throwable $error ) { return null; }
        if ( true === $results ) {
            if ( ! is_callable( array( $native, 'page_state' ) ) ) { return null; }
            try { $state = $native::page_state(); } catch ( \Throwable $error ) { return null; }
            return is_array( $state ) && is_string( $state['q'] ?? null ) ? \PeptideCore\Tracking\Search_Context::source( $state['q'] ) : null;
        }
        if ( false !== $results ) { return null; }
    }
    return function_exists( 'get_search_query' ) ? \PeptideCore\Tracking\Search_Context::source( get_search_query( false ) ) : null;
}

/** Side-effect-free document observation, independent of the legacy page resolver. */
function vc_visitor_page_observation(): ?array {
    $page = vc_visitor_page_context();
    $kind = $page['page_type'];
    $context = array( 'version' => 1, 'kind' => $kind );
    $private = in_array( $kind, array( 'account', 'order_received', 'order_pay' ), true );
    $search = function_exists( 'is_search' ) && is_search();
    $taxonomy = function_exists( 'is_product_taxonomy' ) && is_product_taxonomy();
    $archive = function_exists( 'is_archive' ) && is_archive();
    if ( $search && ! $private && ! in_array( $kind, array( 'product', 'cart', 'checkout' ), true ) ) {
        $path = vc_visitor_observation_path( home_url( '/' ) );
        if ( null === $path ) { return null; }
        $page = array( 'page_type' => 'content', 'path' => $path );
        $context['kind'] = 'search';
        $search_observation = vc_visitor_search_observation();
        if ( null !== $search_observation ) { $context['search'] = $search_observation; }
    } elseif ( ! $private && ( 'shop' === $kind || $taxonomy || $archive ) ) {
        $path = vc_visitor_observation_path( get_pagenum_link( max( 1, absint( get_query_var( 'paged' ) ) ), false ) );
        if ( null === $path ) { return null; }
        $page['path'] = $path;
        if ( $taxonomy ) {
            $term = get_queried_object();
            if ( ! $term instanceof WP_Term || ! is_string( $term->taxonomy ) || '' === $term->taxonomy ) { return null; }
            if ( $term instanceof WP_Term && 'product_cat' === $term->taxonomy ) {
                if ( ! is_int( $term->term_id ) || $term->term_id <= 0 || $term->term_id > 9007199254740991 ) { return null; }
                $context['kind'] = 'collection';
                $context['term_id'] = $term->term_id;
            }
        }
    }
    if ( ! is_string( $page['path'] ?? null ) || '' === $page['path'] || '/' !== $page['path'][0] || str_starts_with( $page['path'], '//' ) || strlen( $page['path'] ) > 500 || preg_match( '/[\x00-\x20\x7f\\\\?#]/', $page['path'] ) || ! preg_match( '//u', $page['path'] ) ) { return null; }
    if ( 'product' === $page['page_type'] && ( ! is_int( $page['product_id'] ?? null ) || $page['product_id'] <= 0 || $page['product_id'] > 9007199254740991 ) ) { return null; }
    $context['geo'] = vc_visitor_observation_geo();
    return array( 'version' => 1, 'page' => $page, 'context' => $context );
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
    $observer_dependencies = array( 'vialcove-visitor-video' );
    $product_actions = is_callable( array( '\\PeptideCore\\Tracking\\Product_Actions', 'protocol' ) ) && 1 === \PeptideCore\Tracking\Product_Actions::protocol() && is_callable( array( '\\PeptideCore\\Tracking\\Product_Actions', 'clean' ) ) && is_callable( array( '\\PeptideCore\\Tracking\\Product_Actions', 'saved' ) );
    if ( $product_actions ) {
        wp_enqueue_script( 'vialcove-visitor-intents', $url . '/assets/visitor-intents.js', array(), (string) filemtime( $root . '/assets/visitor-intents.js' ), true );
        $observer_dependencies[] = 'vialcove-visitor-intents';
    }
    $product_impressions = is_callable( array( '\\PeptideCore\\Tracking\\Impressions', 'clean' ) ) && is_callable( array( '\\PeptideCore\\Tracking\\Impressions', 'saved' ) ) && is_callable( array( '\\PeptideCore\\Tracking\\Impressions', 'replay_code' ) );
    if ( $product_impressions ) {
        wp_enqueue_script( 'vialcove-visitor-products', $url . '/assets/visitor-products.js', array(), (string) filemtime( $root . '/assets/visitor-products.js' ), true );
        $observer_dependencies[] = 'vialcove-visitor-products';
    }
    if ( function_exists( 'wp_script_is' ) && wp_script_is( 'peptide-core-tracking-checkout', 'registered' ) ) {
        $observer_dependencies[] = 'peptide-core-tracking-checkout';
    }
    wp_enqueue_script( 'vialcove-visitor-observer', $url . '/assets/visitor-tracking.js', $observer_dependencies, (string) filemtime( $root . '/assets/visitor-tracking.js' ), true );
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
    if ( $product_actions ) { $transport['product_actions_protocol'] = 1; }
    $metadata_protocols = array();
    if ( $product_impressions ) { $transport['product_impression_protocol'] = 1; }
    if ( is_callable( array( '\\PeptideCore\\Tracking\\Attribution', 'clean' ) ) && is_callable( array( '\\PeptideCore\\Tracking\\Attribution', 'capture' ) ) ) { $metadata_protocols['attribution'] = 1; }
    if ( is_callable( array( '\\PeptideCore\\Tracking\\Page_Context', 'clean' ) ) && is_callable( array( '\\PeptideCore\\Tracking\\Page_Context', 'replay' ) ) ) { $metadata_protocols['page_context'] = 1; }
    if ( $metadata_protocols ) { $transport['metadata_protocols'] = $metadata_protocols; }
    if ( isset( $metadata_protocols['page_context'] ) && is_callable( array( '\\PeptideCore\\Tracking\\Page_Context', 'client_environment_protocol' ) ) && is_callable( array( '\\PeptideCore\\Tracking\\Client_Environment', 'clean' ) ) && is_callable( array( '\\PeptideCore\\Tracking\\Client_Environment', 'saved' ) ) && 1 === \PeptideCore\Tracking\Page_Context::client_environment_protocol() ) { $transport['client_environment_protocol'] = 1; }
    if ( isset( $metadata_protocols['page_context'] ) && vc_visitor_search_capable() ) { $transport['search_terms_protocol'] = 1; }
    if ( isset( $metadata_protocols['page_context'] ) || $product_impressions ) {
        $observation = vc_visitor_page_observation();
        if ( null !== $observation ) { $transport['page_observation'] = $observation; }
    }
    wp_enqueue_script( 'vialcove-visitor-adapter', $url . '/assets/visitor-adapter.js', array( 'vialcove-visitor-observer' ), (string) filemtime( $root . '/assets/visitor-adapter.js' ), true );
    wp_add_inline_script( 'vialcove-visitor-adapter', 'window.VialCoveVisitorConfig = ' . wp_json_encode( $transport, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
}, 40 );

// License credit for an explicitly configured country observation resource.
add_action( 'wp_footer', static function () {
	if ( is_callable( array( \PeptideCore\Tracking\Country_Provider::class, 'render_attribution' ) ) ) { \PeptideCore\Tracking\Country_Provider::render_attribution(); }
}, 99 );
