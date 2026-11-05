<?php
/** Isolated opt-in schema and real-template guards; no WordPress, store or layout writes. */
namespace PeptideCore\Modules {
    final class Fields {
        public static function f( $type, $key, $label, $default = null, $extra = array() ): array {
            return array_merge( array( 'type' => $type, 'key' => $key, 'label' => $label, 'default' => $default ), $extra );
        }
    }
    final class Products {
        public static function public_catalog_product( $product ): bool { return $product instanceof \ProductFirstFixture && $product->public; }
        public static function for_module( $module ): array { return array( $GLOBALS['fixture'] ); }
    }
    final class Showcase_Quick_View { public static function supports( $product ): bool { return true; } }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    $filters = $template_calls = array();
    $product_reads = $count_reads = $native_forms = $image_reads = $passed = 0;
    final class ProductFirstFixture {
        public bool $public = true;
        public function get_id() { return 21; }
        public function get_name() { return 'Real product'; }
        public function get_sku() { return 'SKU21'; }
        public function is_type( $type ) { return 'simple' === $type; }
        public function get_price() { return '55'; }
        public function get_image_id( $context = 'view' ) { return 42; }
        public function get_permalink() { return 'https://store.example/product/real-product/'; }
    }
    final class WP_HTML_Tag_Processor {
        public function __construct( private string $html ) {}
        public function next_tag( ...$unused ) { return false; }
        public function get_updated_html() { return $this->html; }
    }
    $fixture = new ProductFirstFixture();
    function add_filter( $hook, $callback, $priority = 10, $argc = 1 ) { $GLOBALS['filters'][ $hook ][] = array( $callback, $priority, $argc ); }
    function add_action( ...$unused ) {}
    function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
    function esc_attr( $value ) { return esc_html( $value ); }
    function esc_url( $value ) { return preg_match( '~^(?:https?://|/|#)~', $value ) ? esc_attr( $value ) : ''; }
    function __( $value, ...$unused ) { return $value; }
    function vc_icon( ...$unused ) { return '<svg data-fixture-icon aria-hidden="true"></svg>'; }
    function wc_get_product( $id ) { ++$GLOBALS['product_reads']; return $GLOBALS['fixture']; }
    function wc_get_products( $args ) { ++$GLOBALS['count_reads']; return array( $GLOBALS['fixture'] ); }
    function vc_card_display_product( $product ) { return $product; }
    function wp_get_attachment_image( ...$unused ) { ++$GLOBALS['image_reads']; return '<img src="https://store.example/product.png" alt="Real product">'; }
    function vc_home_quick_view_template( $product ) { ++$GLOBALS['native_forms']; return '<template data-fixture-native-form></template>'; }
    function number_format_i18n( $number ) { return number_format( $number ); }
    function get_the_terms( ...$unused ) { return array(); }
    function vc_product_card( $product, ...$unused ) { echo '<article data-real-product-card><a href="' . esc_url( $product->get_permalink() ) . '">' . esc_html( $product->get_name() ) . '</a></article>'; }
    function get_template_part( $path, $name = null, $args = array() ) {
        $GLOBALS['template_calls'][] = array( $path, $args );
        if ( in_array( $path, array( 'template-parts/modules/demo-hero-h1', 'template-parts/modules/demo-catalog' ), true ) ) { require dirname( __DIR__ ) . '/' . $path . '.php'; }
        else { throw new \RuntimeException( 'Unexpected template dispatch: ' . $path ); }
    }
    function check( bool $condition, string $message ): void { if ( ! $condition ) { throw new \RuntimeException( $message ); } ++$GLOBALS['passed']; }
    function field( array $definition, string $key ): array {
        foreach ( $definition['settings'] as $item ) { if ( $key === $item['key'] ) { return $item; } }
        throw new \RuntimeException( 'Missing field: ' . $key );
    }
    // Use the production escaping/link helper, rather than a duplicate button implementation.
    require dirname( __DIR__ ) . '/inc/layout-modules.php';
    $before_schema = count( $filters['peptide_core_layout_definitions'] );
    $before_dispatch = count( $filters['peptide_core_module_html'] );
    require dirname( __DIR__ ) . '/inc/demo-home-modules.php';
    $hero_schema = $filters['peptide_core_layout_definitions'][ $before_schema ][0];
    $hero_dispatch = $filters['peptide_core_module_html'][ $before_dispatch ][0];
    $before_schema = count( $filters['peptide_core_layout_definitions'] );
    $before_dispatch = count( $filters['peptide_core_module_html'] );
    require dirname( __DIR__ ) . '/inc/demo-home-catalog.php';
    $catalog_schema = $filters['peptide_core_layout_definitions'][ $before_schema ][0];
    $catalog_dispatch = $filters['peptide_core_module_html'][ $before_dispatch ][0];

    $definitions = array();
    foreach ( array( 'hero_h1', 'info_list', 'report_feature', 'counter', 'card_list', 'faq', 'cta', 'rich_text' ) as $type ) {
        $definitions[ $type ] = array( 'settings' => array( array( 'key' => 'info_list' === $type ? 'visualPresentation' : 'presentation', 'default' => 'standard', 'options' => array( 'standard' => 'Native standard' ) ) ), 'panels' => array(), 'items' => array( 'fields' => array(), 'panels' => array() ) );
    }
    $definitions['product_collection'] = array( 'settings' => array( array( 'key' => 'cardPresentation', 'default' => 'standard', 'options' => array( 'standard' => 'Native standard' ) ), array( 'key' => 'products' ) ), 'panels' => array() );
    $definitions['unrelated'] = array( 'sentinel' => 'Native schema remains intact' );
    $extended = $catalog_schema( $hero_schema( $definitions ) );
    check( 'standard' === field( $extended['hero_h1'], 'presentation' )['default'] && isset( field( $extended['hero_h1'], 'presentation' )['options']['product_first'] ), 'The compact hero is an opt-in presentation, never the default.' );
    check( ! isset( field( $extended['faq'], 'presentation' )['options']['product_first'] ) && ! isset( field( $extended['info_list'], 'visualPresentation' )['options']['product_first'] ), 'Product-first is not offered to unrelated module types.' );
    check( 'standard' === field( $extended['product_collection'], 'catalogLayout' )['default'] && isset( field( $extended['product_collection'], 'catalogLayout' )['options']['product_first'] ), 'The catalog compact class has its own opt-in field.' );
    check( 'standard' === field( $extended['product_collection'], 'cardPresentation' )['default'] && $extended['unrelated'] === $definitions['unrelated'], 'Native catalog presentation and unrelated schema are preserved.' );

    $settings = array(
        'presentation' => 'product_first', 'featuredProduct' => 999, 'showMedia' => true,
        'title' => "Research <b>peptides</b>\nClearly specified.", 'description' => 'Real specifications & <script>unsafe</script> text.',
        'eyebrow' => 'Hidden eyebrow', 'catalogCaption' => 'Hidden count {count}', 'caption' => 'Hidden image caption',
        'button' => array( 'label' => 'Shop <b>peptides</b>', 'href' => 'https://store.example/shop/?source=hero&sort=order', 'target' => '_blank' ),
        'secondaryLink' => array( 'label' => 'Hidden secondary COA link', 'href' => 'https://store.example/reports/' ),
        'mediaPosition' => array( 'desktop' => 'right', 'mobile' => 'left' ), 'textAlign' => 'left',
    );
    $hero = array( 'type' => 'hero_h1', 'settings' => $settings );
    $markup = $hero_dispatch( null, $hero, 'public', 'mobile' );
    check( str_contains( $markup, 'vc-demo-hero--product-first' ) && str_contains( $markup, '<h1>Research &lt;b&gt;peptides&lt;/b&gt;<br' ), 'The compact class and escaped merchant heading render through the real template.' );
    check( str_contains( $markup, 'Real specifications &amp; &lt;script&gt;unsafe&lt;/script&gt; text.' ) && ! str_contains( $markup, '<script>' ), 'Merchant description stays escaped rather than executable.' );
    check( str_contains( $markup, 'Shop &lt;b&gt;peptides&lt;/b&gt;' ) && str_contains( $markup, 'https://store.example/shop/?source=hero&amp;sort=order' ), 'The real button helper escapes its label while preserving the full chosen URL.' );
    check( str_contains( $markup, 'target="_blank" rel="noopener noreferrer"' ) && str_contains( $markup, 'vc-demo-shop-link' ), 'The original link target and safe rel are retained in the compact action.' );
    check( ! str_contains( $markup, 'Hidden eyebrow' ) && ! str_contains( $markup, 'Hidden count' ) && ! str_contains( $markup, 'Hidden secondary' ) && ! str_contains( $markup, 'Hidden image caption' ), 'Compact mode suppresses only the eyebrow, catalog-count line, secondary link and image caption.' );
    check( ! str_contains( $markup, '<img' ) && ! str_contains( $markup, 'vc-demo-hero-media' ) && ! str_contains( $markup, 'data-fixture-native-form' ), 'Compact mode creates no hero media or hidden native purchase form.' );
    check( 0 === $product_reads && 0 === $count_reads && 0 === $image_reads && 0 === $native_forms, 'Compact rendering does not read a featured product, count the catalog, fetch imagery or build purchase forms.' );
    check( 'Existing renderer output' === $hero_dispatch( 'Existing renderer output', $hero, 'public', 'mobile' ), 'A prior renderer result is never replaced by the compact dispatcher.' );
    $standard = $hero; $standard['settings']['presentation'] = 'standard';
    check( null === $hero_dispatch( null, $standard, 'public', 'mobile' ), 'The native standard hero remains outside the opt-in dispatcher.' );
    foreach ( array( 'faq', 'info_list', 'counter', 'product_collection' ) as $type ) {
        check( null === $hero_dispatch( null, array( 'type' => $type, 'settings' => array( 'presentation' => 'product_first', 'visualPresentation' => 'product_first' ) ), 'public', 'mobile' ), 'Product-first cannot accidentally intercept ' . $type . '.' );
    }
    $legacy = $hero; $legacy['settings']['presentation'] = 'demo';
    $markup = $hero_dispatch( null, $legacy, 'public', 'mobile' );
    check( ! str_contains( $markup, 'vc-demo-hero--product-first' ) && str_contains( $markup, 'Hidden eyebrow' ) && str_contains( $markup, 'Hidden count 1' ) && str_contains( $markup, 'Hidden secondary COA link' ), 'Legacy demo mode retains its existing eyebrow, live count and secondary action.' );
    check( str_contains( $markup, 'vc-demo-hero-media' ) && ! str_contains( $markup, 'data-fixture-native-form' ) && 1 === $product_reads && 0 === $native_forms && 1 === $count_reads, 'Legacy demo mode retains real featured media and defers the native form to the shared loader.' );

    $catalog = array( 'id' => 'collection', 'type' => 'product_collection', 'settings' => array(
        'cardPresentation' => 'demo', 'catalogLayout' => 'product_first', 'demoFullCatalog' => false, 'source' => 'manual',
        'demoPageSize' => 8, 'eyebrow' => '', 'title' => 'Selected products', 'description' => '', 'link' => null,
        'demoSearchLabel' => 'Find a peptide', 'columns' => array( 'desktop' => 4, 'mobile' => 2 ),
        'emptyText' => 'No matching products.', 'demoPriceNote' => '', 'demoMoreLabel' => 'View more',
    ) );
    $markup = $catalog_dispatch( null, $catalog, 'public', 'mobile' );
    check( str_contains( $markup, 'vc-demo-catalog--product-first' ) && str_contains( $markup, 'data-real-product-card' ) && str_contains( $markup, 'https://store.example/product/real-product/' ), 'The compact catalog only adds its wrapper class and preserves the real product card/link.' );
    check( str_contains( $markup, 'data-vc-catalog-search' ) && str_contains( $markup, 'data-vc-category="all"' ) && str_contains( $markup, 'data-vc-catalog-more' ), 'Existing search, category and paging controls remain wired.' );
    $catalog['settings']['catalogLayout'] = 'standard';
    check( ! str_contains( $catalog_dispatch( null, $catalog, 'public', 'mobile' ), 'vc-demo-catalog--product-first' ), 'The standard catalog does not acquire the compact class.' );
    $catalog['settings']['cardPresentation'] = 'standard';
    check( 'Native catalog' === $catalog_dispatch( 'Native catalog', $catalog, 'public', 'mobile' ), 'A non-demo catalog retains its native renderer output.' );
    echo $passed . ' home product-first template guards passed' . PHP_EOL;
}
