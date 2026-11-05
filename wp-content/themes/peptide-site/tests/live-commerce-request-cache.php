<?php
/** Actual presentation getters, isolated native snapshots; no WordPress/database writes. */
namespace PeptideCore {
    class Templates {
        public static int $template = 123;
        public static function current_id() { return self::$template; }
        public static function previewing_id() { return $GLOBALS['preview'] ?? 0; }
    }
}
namespace PeptideCore\Modules {
    class Layout {
        public static function published_modules( $document, $template ) {
            $key = \get_current_blog_id() . ':' . $template . ':' . $document;
            $GLOBALS['reads'][] = $key;
            return $GLOBALS['published'][ $key ] ?? array();
        }
        public static function preview_draft( ...$args ) { throw new \RuntimeException( 'Never read a draft from a public settings getter.' ); }
    }
    class Renderer {
        public static function normalize( $type, $attrs ) {
            ++$GLOBALS['normalizations'];
            $attrs['settings']['whatsappLabel'] = ( $attrs['settings']['whatsappLabel'] ?? 'Support' ) . ( $GLOBALS['definition_suffix'] ?? '' );
            return $attrs + array( 'enabled' => true, 'visibility' => array( 'desktop' => true, 'mobile' => true ) );
        }
    }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    $hooks = array();
    function add_filter( ...$args ) {}
    function add_action( $name, $callback, ...$args ) { $GLOBALS['hooks'][ $name ][] = $callback; }
    function apply_filters( $name, $value, ...$args ) { return 'vialcove_live_commerce_request_memo' === $name ? ( $GLOBALS['memo_enabled'] ?? true ) : $value; }
    function is_admin() { return $GLOBALS['admin'] ?? false; }
    function wp_doing_ajax() { return $GLOBALS['ajax'] ?? false; }
    function wp_doing_cron() { return $GLOBALS['cron'] ?? false; }
    function get_current_blog_id() { return $GLOBALS['blog'] ?? 1; }
    function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
    require dirname( __DIR__ ) . '/inc/live-commerce-settings.php';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $reads = array(); $normalizations = 0; $checks = 0;
    function check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new \RuntimeException( $message ); } }
    function settings_row( array $settings = array(), array $mobile = array(), array $extra = array() ): array {
        return array( 'block' => 'vialcove/product-experience', 'attrs' => $extra + array( 'settings' => $settings + array( 'mobileDialog' => true ), 'mobile' => array( 'settings' => $mobile ) ) );
    }
    $published = array( '1:123:product' => array( settings_row( array( 'whatsappLabel' => 'Published' ), array( 'reportGalleryEnabled' => false ) ) ) );
    $measure = static function ( bool $memo ): array {
        $GLOBALS['memo_enabled'] = $memo; $GLOBALS['reads'] = array();
        vc_live_commerce_published_rows( '', 0, true );
        for ( $id = 1; $id <= 82; ++$id ) { vc_live_product_settings( $id ); }
        $cold = count( $GLOBALS['reads'] );
        for ( $id = 1; $id <= 82; ++$id ) { vc_live_product_settings( $id ); }
        return array( 'productCount' => 82, 'firstPassPublishedReads' => $cold, 'secondPassPublishedReads' => count( $GLOBALS['reads'] ) - $cold );
    };
    $before = $measure( false ); $after = $measure( true );
    check( 164 === $before['firstPassPublishedReads'] && 164 === $before['secondPassPublishedReads'], 'No-memo control reads each native specific/generic document.' );
    check( 83 === $after['firstPassPublishedReads'] && 0 === $after['secondPassPublishedReads'], 'Each distinct specific/generic snapshot is read once per request, including negative lookups.' );
    $out = vc_live_product_settings( 8 );
    check( $out['enabled'] && 'product' === $out['document'] && ! $out['devices']['mobile']['reportGalleryEnabled'] && $out['devices']['desktop']['reportGalleryEnabled'], 'Generic fallback and per-device explicit false retain their exact meaning.' );
    $out['devices']['desktop']['enabled'] = false;
    check( vc_live_product_settings( 8 )['devices']['desktop']['enabled'], 'Caller mutations cannot poison a later DTO.' );
    $definition_suffix = ' fresh';
    check( 'Published fresh' === vc_live_product_settings( 8 )['whatsappLabel'], 'Normalization is fresh for each call; only published raw rows are memoized.' );
    $published['1:123:product_7'] = array( settings_row( array(), array(), array( 'enabled' => false ) ) );
    foreach ( $hooks['peptide_core_layout_published'] as $callback ) { $callback( 7 ); }
    $out = vc_live_product_settings( 7 );
    check( ! $out['enabled'] && 'product_7' === $out['document'], 'A disabled product override wins over enabled generic settings after native publication.' );
    $published['1:456:product'] = array( settings_row( array( 'whatsappLabel' => 'Other template' ) ) );
    \PeptideCore\Templates::$template = 456;
    check( 'Other template fresh' === vc_live_product_settings()['whatsappLabel'], 'Changing current template cannot reuse another template snapshot.' );
    $published['2:456:product'] = array( settings_row( array( 'whatsappLabel' => 'Other blog' ) ) ); $blog = 2;
    check( 'Other blog fresh' === vc_live_product_settings()['whatsappLabel'], 'A switched blog has an independent snapshot key.' );
    foreach ( array( 'admin', 'ajax', 'cron', 'preview' ) as $bypass ) {
        $GLOBALS[ $bypass ] = true; $start = count( $reads );
        vc_live_product_settings(); vc_live_product_settings();
        check( count( $reads ) - $start === 2, $bypass . ' retains native fresh snapshot reads.' );
        $GLOBALS[ $bypass ] = false;
    }
    $_SERVER['REQUEST_METHOD'] = 'POST'; $start = count( $reads ); vc_live_product_settings(); vc_live_product_settings();
    check( count( $reads ) - $start === 2, 'Mutating request methods do not memoize public render state.' );
    $_SERVER['REQUEST_METHOD'] = 'GET'; $memo_enabled = false; $start = count( $reads ); vc_live_product_settings(); vc_live_product_settings();
    check( count( $reads ) - $start === 2, 'Context-sensitive integrations can explicitly opt out.' );
    echo json_encode( array( 'passed' => $checks, 'scope' => 'Actual settings getters with native snapshot stubs; document calls, not measured SQL or server time.', 'before' => $before, 'after' => $after ), JSON_PRETTY_PRINT ) . "\n";
}
