<?php
/** Real hero template/asset-bootstrap boundaries, with no WordPress or database. */
namespace PeptideCore\Modules {
    final class Products { public static function public_catalog_product( $product ): bool { return $product->public; } }
    final class Showcase_Quick_View { public static int $asset_calls = 0; public static function catalog_assets(): void { ++self::$asset_calls; } }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    $passed = 0; $mode_assets = in_array( '--assets', $argv, true );
    function check( bool $condition, string $message ): void { if ( ! $condition ) { throw new \RuntimeException( $message ); } ++$GLOBALS['passed']; echo "ok $message\n"; }
    if ( $mode_assets ) {
        $actions = $scripts = $styles = $localized = array();
        function add_action( $name, $callback, $priority = 10, $argc = 1 ): void { $GLOBALS['actions'][ $name ][ $priority ][] = $callback; }
        function add_filter( ...$unused ): void {}
        function is_front_page(): bool { return true; }
        function get_template_directory_uri(): string { return 'https://store.example/theme'; }
        function get_template_directory(): string { return dirname( __DIR__ ); }
        function wp_enqueue_script( $handle, $url, $dependencies, $version, $footer ): void { $GLOBALS['scripts'][ $handle ] = array( $url, $dependencies ); }
        function wp_enqueue_style( $handle, $url, $dependencies, $version ): void { $GLOBALS['styles'][ $handle ] = $url; }
        function wp_localize_script( $handle, $key, $value ): void { $GLOBALS['localized'][ $key ] = $value; }
        function rest_url( $route ): string { return 'https://store.example/wp-json/' . $route; }
        function is_user_logged_in(): bool { return false; }
        function wp_create_nonce( $action ): string { throw new \RuntimeException( 'Anonymous bootstrap cannot need a nonce.' ); }
        function wc_get_cart_url(): string { return 'https://store.example/cart/'; }
        require dirname( __DIR__ ) . '/inc/home-quick-view.php';
        check( isset( $actions['wp_enqueue_scripts'][35] ), 'front-page bootstrap is registered independently of hero rendering' );
        foreach ( $actions['wp_enqueue_scripts'][35] as $callback ) { $callback(); }
        check( 1 === \PeptideCore\Modules\Showcase_Quick_View::$asset_calls, 'native quick-view assets are primed without creating a product form' );
        check( isset( $scripts['vc-home-quick-view'], $scripts['vialcove-product-options'], $scripts['vc-product-dialog-experience'] ), 'shared loader and original product presenter still load on the homepage' );
        check( in_array( 'peptide-showcase-quick-view', $scripts['vc-home-quick-view'][1], true ), 'native presenter remains a dependency of the home adapter' );
        check( 'https://store.example/wp-json/vialcove/v1/home-product/' === $localized['vcHomeQuickView']['productApi'], 'the existing on-demand template endpoint is localized' );
        check( '' === $localized['vcHomeQuickView']['wpNonce'], 'anonymous bootstrap remains anonymous' );
        echo "$passed hero asset checks passed\n";
        return;
    }
    $reads = $form_reads = 0; $image_calls = $image_contexts = array();
    final class HeroProduct {
        public bool $public = true;
        public function get_id(): int { return 11306; }
        public function get_name(): string { return 'Product <&>'; }
        public function is_type( $type ): bool { return false; }
        public function get_image_id( $context = 'view' ): int { return 99; }
        public function get_permalink(): string { return 'https://store.example/product/tirzepatide/'; }
    }
    final class HeroVariation {
        public function is_type( $type ): bool { return 'variation' === $type; }
        public function get_image_id( $context = 'view' ): int { $GLOBALS['image_contexts'][] = $context; return 42; }
        public function get_permalink(): string { return 'https://store.example/product/tirzepatide/?attribute_pack=20mg&pc_lang=es'; }
    }
    $product_fixture = new HeroProduct(); $variation_fixture = new HeroVariation();
    function esc_html( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
    function esc_attr( $value ): string { return esc_html( $value ); }
    function esc_url( $value ): string { return esc_attr( $value ); }
    function __( $text, ...$unused ): string { return $text; }
    function wc_get_product( $id ) { ++$GLOBALS['reads']; return $GLOBALS['product_fixture']; }
    function vc_card_display_product( $product ) { return $GLOBALS['variation_fixture']; }
    function wp_get_attachment_image( $id, $size, $icon, $attrs ): string { $GLOBALS['image_calls'][] = array( $id, $size, $attrs ); return '<img src="https://store.example/real-variation.webp" loading="' . esc_attr( $attrs['loading'] ) . '" fetchpriority="' . esc_attr( $attrs['fetchpriority'] ) . '" alt="Real variation">'; }
    function vc_module_picture( ...$unused ): string { return '<img data-configured-media src="https://store.example/configured.webp">'; }
    function vc_module_button( $button, $class = '' ): string { return empty( $button['href'] ) ? '' : '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $button['href'] ) . '">' . esc_html( $button['label'] ) . '</a>'; }
    function vc_icon( ...$unused ): string { return '<svg aria-hidden="true"></svg>'; }
    if ( ! $mode_assets ) {
        function vc_home_quick_view_template( $product ): string { ++$GLOBALS['form_reads']; throw new \RuntimeException( 'The hero must not pre-render native forms.' ); }
    }
    function vc_demo_home_catalog_counts(): array { return array( 'products' => 82 ); }
    function number_format_i18n( $number ): string { return (string) $number; }
    function render_hero( array $settings ): string { $args = array( 'module' => array( 'settings' => $settings ) ); ob_start(); require dirname( __DIR__ ) . '/template-parts/modules/demo-hero-h1.php'; return (string) ob_get_clean(); }
    $settings = array( 'presentation' => 'demo', 'featuredProduct' => 11306, 'showMedia' => true, 'title' => "Current title\nSecond line", 'description' => 'Current description', 'eyebrow' => 'Current intro', 'caption' => 'Current caption', 'catalogCaption' => '{count} products', 'button' => array( 'href' => '#vc-collection', 'label' => 'Shop peptides' ), 'secondaryLink' => array( 'href' => '/coa-library/', 'label' => 'View COA' ), 'mediaPosition' => array( 'desktop' => 'right', 'mobile' => 'left' ) );
    $html = render_hero( $settings );
    check( 'edit' === $image_contexts[0], 'hero keeps exact variation artwork semantics' );
    check( 0 === $form_reads && ! str_contains( $html, '<template' ) && ! str_contains( $html, '<form' ), 'a featured hero creates no hidden template or form' );
    check( str_contains( $html, 'data-vc-home-product="11306" data-vc-quick-open="11306"' ), 'hero retains the parent product identity for the shared loader' );
    check( str_contains( $html, 'https://store.example/product/tirzepatide/?attribute_pack=20mg&amp;pc_lang=es' ), 'real exact-spec permalink and language context remain the fallback' );
    check( str_contains( $html, 'loading="eager" fetchpriority="high"' ) && 42 === $image_calls[0][0] && 'large' === $image_calls[0][1], 'visible hero still eagerly prioritizes the real variation image' );
    check( '(max-width:767px) 90vw, 45vw' === $image_calls[0][2]['sizes'], 'hero responsive sizes remain unchanged' );
    check( str_contains( $html, 'Product &lt;&amp;&gt;' ) && str_contains( $html, '<h1>Current title<br' ) && str_contains( $html, 'Current description' ), 'heading, public copy and escaped product accessibility label remain present' );
    check( str_contains( $html, 'Shop peptides' ) && str_contains( $html, '/coa-library/' ) && str_contains( $html, '82 products' ), 'catalog action, COA link and authoritative count copy are preserved' );
    $no_media = render_hero( array_merge( $settings, array( 'showMedia' => false ) ) );
    check( ! str_contains( $no_media, '<img' ) && ! str_contains( $no_media, 'data-vc-quick-open' ) && 0 === $form_reads, 'media-disabled hero does not manufacture a product trigger or hidden form' );
    $product_fixture->public = false; $private = render_hero( $settings );
    check( str_contains( $private, 'data-configured-media' ) && ! str_contains( $private, 'data-vc-quick-open' ), 'nonpublic product remains unavailable and uses configured media only' );
    $before_reads = $reads; $compact = render_hero( array_merge( $settings, array( 'presentation' => 'product_first' ) ) );
    check( $before_reads === $reads && ! str_contains( $compact, '<img' ) && ! str_contains( $compact, '<template' ), 'compact mode remains free of product/media/form reads' );
    echo "$passed hero template checks passed\n";
}
