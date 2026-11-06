<?php
/** Execute actual native asset wiring without WordPress, sessions or requests. */
namespace PeptideCore\Modules {
    class Showcase_Quick_View { public static function catalog_assets(): void {} }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    $scripts = array();
    function add_action( ...$args ): void {}
    function add_filter( ...$args ): void {}
    function get_template_directory_uri(): string { return 'https://shop.test/theme'; }
    function get_template_directory(): string { return dirname( __DIR__ ); }
    function wp_enqueue_script( $handle, $url, $deps, $version, $footer ): void { global $scripts; $scripts[ $handle ] = compact( 'url', 'deps', 'version', 'footer' ); }
    function wp_enqueue_style( ...$args ): void {}
    function wp_localize_script( ...$args ): void {}
    function rest_url( $route ): string { return 'https://shop.test/wp-json/' . $route; }
    function is_user_logged_in(): bool { return false; }
    function check( bool $okay, string $message ): void { if ( ! $okay ) { throw new \Exception( $message ); } echo "ok $message\n"; }
    require dirname( __DIR__ ) . '/inc/home-quick-view.php';
    vc_home_quick_view_assets();
    check( isset( $scripts['vc-product-template-prefetch'] ), 'prefetch is a separately enqueued asset' );
    $prefetch = $scripts['vc-product-template-prefetch'];
    check( array( 'vc-home-quick-view' ) === $prefetch['deps'], 'prefetch runs after native template loader without replacing its dependencies' );
    check( 'https://shop.test/theme/assets/product-template-prefetch.js' === $prefetch['url'] && $prefetch['footer'], 'prefetch is footer loaded from theme assets' );
    check( (string) filemtime( dirname( __DIR__ ) . '/assets/product-template-prefetch.js' ) === $prefetch['version'], 'prefetch cache version follows actual asset' );
    check( array_search( 'vc-home-quick-view', array_keys( $scripts ), true ) < array_search( 'vc-product-template-prefetch', array_keys( $scripts ), true ), 'loader is registered before speculative consumer' );
}
