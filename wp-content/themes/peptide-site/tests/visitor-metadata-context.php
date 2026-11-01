<?php
/** Standalone real resolver/enqueue tests. Child processes isolate capability class definitions. */
namespace {
    $mode = $argv[1] ?? null;
    if ( null === $mode ) {
        $total = 0;
        foreach ( array( 'both', 'none', 'attribution', 'page_context', 'attr_no_clean', 'attr_no_capture', 'context_no_clean', 'context_no_replay' ) as $variant ) {
            $process = proc_open( array( PHP_BINARY, '-n', __FILE__, $variant ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
            if ( ! is_resource( $process ) ) { throw new \RuntimeException( 'Unable to start isolated capability test' ); }
            fclose( $pipes[0] );
            $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] );
            fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
            echo $stdout;
            if ( 0 !== $exit || '' !== $stderr ) { fwrite( STDERR, $stderr ); exit( 1 ); }
            if ( ! preg_match( '/CHILD (\d+) PASS, 0 FAIL/', $stdout, $matches ) ) { throw new \RuntimeException( 'Missing child result' ); }
            $total += (int) $matches[1];
        }
        echo "TOTAL {$total} PASS, 0 FAIL, 0 SKIP\n";
        exit;
    }
}
namespace PeptideCore\Tracking {
    if ( in_array( $GLOBALS['mode'], array( 'both', 'attribution' ), true ) ) {
        final class Attribution { public static function clean() { throw new \RuntimeException( 'Capability invoked' ); } public static function capture() { throw new \RuntimeException( 'Capability invoked' ); } }
    } elseif ( 'attr_no_clean' === $GLOBALS['mode'] ) {
        final class Attribution { public static function capture() {} }
    } elseif ( 'attr_no_capture' === $GLOBALS['mode'] ) {
        final class Attribution { public static function clean() {} }
    }
    if ( in_array( $GLOBALS['mode'], array( 'both', 'page_context' ), true ) ) {
        final class Page_Context { public static function clean() { throw new \RuntimeException( 'Capability invoked' ); } public static function replay() { throw new \RuntimeException( 'Capability invoked' ); } }
    } elseif ( 'context_no_clean' === $GLOBALS['mode'] ) {
        final class Page_Context { public static function replay() {} }
    } elseif ( 'context_no_replay' === $GLOBALS['mode'] ) {
        final class Page_Context { public static function clean() {} }
    }
}
namespace PeptideCore { final class Templates { public static function previewing_id(): int { return (int) ( $GLOBALS['fixture']['template_preview'] ?? 0 ); } } }
namespace {
    define( 'ABSPATH', __DIR__ . '/' );
    $checks = 0; $hooks = array(); $fixture = array(); $scripts = array(); $inline = array(); $pagination = array(); $provider_calls = 0;
    function same( $expected, $actual, string $name ): void {
        ++$GLOBALS['checks'];
        if ( $expected !== $actual ) { fwrite( STDERR, 'FAIL ' . $name . "\n" ); exit( 1 ); }
        echo 'PASS ' . $GLOBALS['mode'] . ': ' . $name . "\n";
    }
    class WP_Term { public $term_id; public $taxonomy; public function __construct( $id, $taxonomy = 'product_cat' ) { $this->term_id = $id; $this->taxonomy = $taxonomy; } }
    class WP_Query { public $is_home = true; public $is_page = true; public $is_search = false; public $is_404 = true; public $vars = array(); public function is_main_query(): bool { return true; } public function set( $key, $value ): void { $this->vars[ $key ] = $value; } }
    class WC_Geolocation { public static function geolocate_ip() { ++$GLOBALS['provider_calls']; throw new \RuntimeException( 'Provider called' ); } }
    function WC() { ++$GLOBALS['provider_calls']; throw new \RuntimeException( 'Woo/provider called' ); }
    function get_term() { throw new \RuntimeException( 'Catalog lookup called' ); }
    function add_action( $hook, $callback, $priority = 10 ): void { $GLOBALS['hooks'][ $hook ] = $callback; }
    function apply_filters( $hook, $default ) { return $GLOBALS['fixture']['config']; }
    function is_admin(): bool { return ! empty( $GLOBALS['fixture']['admin'] ); }
    function is_preview(): bool { return ! empty( $GLOBALS['fixture']['preview'] ); }
    function is_customize_preview(): bool { return ! empty( $GLOBALS['fixture']['customizer'] ); }
    function current_user_can( $cap ): bool { return ! empty( $GLOBALS['fixture'][ $cap ] ); }
    function is_wc_endpoint_url( $endpoint ): bool { return ( $GLOBALS['fixture']['endpoint'] ?? '' ) === $endpoint; }
    function is_account_page(): bool { return ! empty( $GLOBALS['fixture']['account'] ); }
    function is_product(): bool { return ! empty( $GLOBALS['fixture']['product'] ); }
    function is_cart(): bool { return ! empty( $GLOBALS['fixture']['cart'] ); }
    function is_checkout(): bool { return ! empty( $GLOBALS['fixture']['checkout'] ); }
    function is_front_page(): bool { return ! empty( $GLOBALS['fixture']['home'] ); }
    function is_shop(): bool { return ! empty( $GLOBALS['fixture']['shop'] ); }
    function is_product_taxonomy(): bool { return ! empty( $GLOBALS['fixture']['taxonomy'] ); }
    function is_archive(): bool { return ! empty( $GLOBALS['fixture']['archive'] ); }
    function is_singular(): bool { return ! empty( $GLOBALS['fixture']['singular'] ); }
    function is_search(): bool { return ! empty( $GLOBALS['fixture']['search'] ) || ! empty( $GLOBALS['native_query']->is_search ); }
    function get_queried_object() { return $GLOBALS['fixture']['term'] ?? null; }
    function get_queried_object_id(): int { return 17; }
    function wc_get_product( $id ) { return new class { public function get_id(): int { return 17; } public function get_name(): string { return 'Synthetic'; } public function get_permalink(): string { return 'https://shop.test/Store/Product/'; } }; }
    function wc_get_cart_url(): string { return 'https://shop.test/Store/Cart/'; }
    function wc_get_checkout_url(): string { return 'https://shop.test/Store/Checkout/'; }
    function wc_get_page_permalink( $name ): string { return 'https://shop.test/Store/Shop/'; }
    function get_permalink(): string { return $GLOBALS['fixture']['permalink'] ?? 'https://shop.test/Store/Content/'; }
    function home_url( $path ): string { return $GLOBALS['fixture']['home_url'] ?? 'https://shop.test/Store/'; }
    function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
    function get_query_var( $key ) { return $GLOBALS['fixture']['paged'] ?? 1; }
    function absint( $value ): int { return abs( (int) $value ); }
    function get_pagenum_link( $page, $escape = true ) { $GLOBALS['pagination'][] = array( $page, $escape ); return $GLOBALS['fixture']['pagination_url'] ?? 'https://shop.test/Store/Collection/page/2/?sort=private#private'; }
    function get_template_directory(): string { return dirname( __DIR__ ); }
    function get_template_directory_uri(): string { return 'https://shop.test/theme'; }
    function esc_url_raw( $url ) { return $url; }
    function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
    function wp_enqueue_script( $handle, $url, $deps, $version, $footer ): void { $GLOBALS['scripts'][] = $handle; }
    function wp_add_inline_script( $handle, $code, $position ): void { $GLOBALS['inline'][ $handle ] = $code; }
    require dirname( __DIR__ ) . '/inc/visitor-tracking.php';
    require dirname( __DIR__, 3 ) . '/plugins/peptide-core/includes/class-public-site-search.php';
    function reset_fixture( array $values = array() ): void {
        $GLOBALS['fixture'] = array_merge( array( 'config' => array( 'enabled' => true, 'protocol' => 1, 'endpoint' => 'https://shop.test/wp-json/peptide/v1/track/events' ) ), $values );
        $GLOBALS['scripts'] = array(); $GLOBALS['inline'] = array(); $GLOBALS['pagination'] = array(); unset( $GLOBALS['native_query'] );
        $_GET = array(); $_SERVER = array( 'REMOTE_ADDR' => '127.0.0.1' );
    }
    function config(): array {
        ( $GLOBALS['hooks']['wp_enqueue_scripts'] )();
        $code = $GLOBALS['inline']['vialcove-visitor-adapter'] ?? '';
        return $code ? json_decode( substr( $code, strlen( 'window.VialCoveVisitorConfig = ' ), -1 ), true, 512, JSON_THROW_ON_ERROR ) : array();
    }
    reset_fixture(); $transport = config(); $expected = array();
    if ( in_array( $mode, array( 'both', 'attribution' ), true ) ) { $expected['attribution'] = 1; }
    if ( in_array( $mode, array( 'both', 'page_context' ), true ) ) { $expected['page_context'] = 1; }
    same( $expected ?: null, $transport['metadata_protocols'] ?? null, 'independent exact callable capability pair' );
    same( isset( $expected['page_context'] ), isset( $transport['page_observation'] ), 'observation only with page-context capability' );
    foreach ( array( 'admin', 'preview', 'customizer', 'manage_woocommerce', 'manage_options', 'template_preview' ) as $flag ) { reset_fixture( array( $flag => true ) ); same( array(), config(), 'unchanged exclusion ' . $flag ); }
    foreach ( array( 'pc_frame', 'preview', 'pc_preview_token' ) as $key ) { reset_fixture(); $_GET[ $key ] = ''; same( array(), config(), 'unchanged query exclusion ' . $key ); }
    foreach ( array( false, 1, 'true' ) as $enabled ) { reset_fixture( array( 'config' => array( 'enabled' => $enabled, 'protocol' => 1, 'endpoint' => 'https://shop.test/track' ) ) ); same( array(), config(), 'strict enabled gate' ); }
    if ( 'both' === $mode ) {
        foreach ( array( 'home' => 'home', 'product' => 'product', 'cart' => 'cart', 'checkout' => 'checkout', 'account' => 'account', 'singular' => 'content' ) as $flag => $kind ) {
            reset_fixture( array( $flag => true ) ); $observed = vc_visitor_page_observation();
            same( $kind, $observed['context']['kind'], 'native kind ' . $kind );
            same( array( 'status' => 'unknown' ), $observed['context']['geo'], 'loopback geo ' . $kind );
        }
        foreach ( array( 'order-received' => 'order_received', 'order-pay' => 'order_pay' ) as $endpoint => $kind ) { reset_fixture( array( 'endpoint' => $endpoint, 'search' => true ) ); $value = vc_visitor_page_observation(); same( $kind, $value['context']['kind'], 'order kind precedence ' . $kind ); same( '/checkout/', $value['page']['path'], 'order private path ' . $kind ); }
        reset_fixture( array( 'account' => true, 'search' => true ) ); same( '/my-account/', vc_visitor_page_observation()['page']['path'], 'account private path precedence' );
        foreach ( array( 1, 2, 0, -3 ) as $page ) {
            reset_fixture( array( 'taxonomy' => true, 'term' => new WP_Term( 42 ), 'paged' => $page ) ); $value = vc_visitor_page_observation();
            same( 'collection', $value['context']['kind'], 'native category collection' ); same( 42, $value['context']['term_id'], 'native category identity' );
            same( '/Store/Collection/page/2/', $value['page']['path'], 'case-preserving native pagination without query or fragment' );
            same( array( array( max( 1, abs( $page ) ), false ) ), $pagination, 'native page API arguments' );
        }
        foreach ( array( 'product_tag', 'pa_color' ) as $taxonomy ) { reset_fixture( array( 'taxonomy' => true, 'term' => new WP_Term( 88, $taxonomy ) ) ); $value = vc_visitor_page_observation(); same( 'shop', $value['context']['kind'], 'tag/attribute never collection ' . $taxonomy ); same( false, isset( $value['context']['term_id'] ), 'tag/attribute has no term identity' ); }
        foreach ( array( null, new WP_Term( 0 ), new WP_Term( '42' ), new WP_Term( 1.5 ), new WP_Term( 9007199254740992 ) ) as $term ) { reset_fixture( array( 'taxonomy' => true, 'term' => $term ) ); same( null, vc_visitor_page_observation(), 'unsupported native category is omitted' ); same( false, isset( config()['page_observation'] ), 'unsupported observation not fabricated in config' ); }
        foreach ( array( 'https://other.test/Collection/', 'http://shop.test/Collection/', 'https://shop.test:444/Collection/', 'https://user@shop.test/Collection/', '/Collection/', 'https://shop.test//ambiguous/', 'https://shop.test/a/../b/', 'https://shop.test/' . str_repeat( 'a', 501 ) ) as $url ) { reset_fixture( array( 'shop' => true, 'pagination_url' => $url ) ); same( null, vc_visitor_page_observation(), 'unsupported native URL omitted' ); }
        reset_fixture( array( 'archive' => true ) ); same( '/Store/Collection/page/2/', vc_visitor_page_observation()['page']['path'], 'generic archive uses native pagination path' );
        foreach ( array( 1, 3 ) as $page ) {
            reset_fixture( array( 'taxonomy' => true, 'term' => new WP_Term( 42 ), 'paged' => $page ) );
            $_GET = array( 'pc_site_search' => '1', 'pc_site_q' => 'PRIVATE_SEARCH', 'pc_site_page' => (string) $page );
            $native_query = new WP_Query(); \PeptideCore\Public_Site_Search::main_query( $native_query );
            same( true, $native_query->is_search, 'actual public-search main-query boundary' );
            $value = vc_visitor_page_observation(); same( 'search', $value['context']['kind'], 'search takes precedence over archive/collection' );
            same( array( 'page_type' => 'content', 'path' => '/Store/' ), $value['page'], 'parameterized/paginated search uses home path' );
            same( array(), $pagination, 'search does not inspect pagination URL' ); same( false, str_contains( json_encode( $value ), 'PRIVATE_SEARCH' ), 'search terms absent' );
        }
        foreach ( array( null, '', 'bad', '127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.1', '169.254.1.1', '100.64.0.1', '0.0.0.0', '192.0.2.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255', '::1', 'fc00::1', 'fe80::1', 'ff02::1', 'ff0e::1', '2001:db8::1' ) as $address ) {
            reset_fixture(); if ( null === $address ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $address; }
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8'; $_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
            same( array( 'status' => 'unknown' ), vc_visitor_observation_geo(), 'invalid/private/reserved native input unknown' );
        }
        foreach ( array( '8.8.8.8', '1.1.1.1', '2606:4700:4700::1111' ) as $address ) { reset_fixture(); $_SERVER['REMOTE_ADDR'] = $address; same( array( 'status' => 'unavailable' ), vc_visitor_observation_geo(), 'public input without provider unavailable' ); same( false, str_contains( json_encode( vc_visitor_page_observation() ), $address ), 'address never serialized' ); }
        reset_fixture( array( 'shop' => true ) ); $a = vc_visitor_page_observation(); $_SERVER['REMOTE_ADDR'] = '8.8.8.8'; same( array( 'status' => 'unknown' ), $a['context']['geo'], 'previous observation is a value snapshot' );
        // Execute the actual pure missing-capability branch on this runtime; not a PHP8.1 runtime claim.
        $missing_cases = array( 'missing' => null, 'invalid' => 'bad', 'non_scalar' => array(), 'private_ipv4' => '10.0.0.1', 'public_ipv4' => '8.8.8.8', 'local_ipv6' => '::1', 'private_ipv6' => 'fc00::1', 'public_ipv6' => '2606:4700:4700::1111' );
        foreach ( $missing_cases as $name => $address ) {
            $status = vc_visitor_observation_geo_status( $address, null );
            same( array( 'status' => 'unknown' ), $status, 'missing native classifier returns unknown: ' . $name );
            same( array( 'status' ), array_keys( $status ), 'missing native classifier emits only status: ' . $name );
        }
        same( 0, $provider_calls, 'native and missing classifiers make zero Woo/provider calls' );
    }
    echo "CHILD {$checks} PASS, 0 FAIL\n";
}
