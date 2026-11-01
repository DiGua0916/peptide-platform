<?php
/** Standalone actual enqueue and exact native-card function, with explicit WordPress boundaries. */
namespace {
    $mode = $argv[1] ?? null;
    if ( null === $mode ) {
        $total = 0;
        foreach ( array( 'full', 'none', 'no_clean', 'no_saved', 'no_replay' ) as $case ) {
            $process = proc_open( array( PHP_BINARY, '-n', __FILE__, $case ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
            if ( ! is_resource( $process ) ) { throw new \RuntimeException( 'Child start failed' ); }
            fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
            if ( 0 !== $exit || '' !== $error || ! preg_match( '/PASS (\d+) product config assertions/', $out, $matches ) ) { fwrite( STDERR, $out . $error ); exit( 1 ); }
            echo $out; $total += (int) $matches[1];
        }
        echo "TOTAL {$total} PASS, 0 FAIL\n"; exit;
    }
}
namespace PeptideCore\Tracking {
    if ( 'full' === $GLOBALS['mode'] ) { final class Impressions { public static function clean() { throw new \RuntimeException( 'Receiver called' ); } public static function saved() { throw new \RuntimeException( 'Receiver called' ); } public static function replay_code() { throw new \RuntimeException( 'Receiver called' ); } } }
    elseif ( 'no_clean' === $GLOBALS['mode'] ) { final class Impressions { public static function saved() {} public static function replay_code() {} } }
    elseif ( 'no_saved' === $GLOBALS['mode'] ) { final class Impressions { public static function clean() {} public static function replay_code() {} } }
    elseif ( 'no_replay' === $GLOBALS['mode'] ) { final class Impressions { public static function clean() {} public static function saved() {} } }
}
namespace {
    define( 'ABSPATH', __DIR__ . '/' ); $checks = 0; $hooks = array(); $scripts = array(); $inline = array(); $staff = false; $enabled = true;
    $check = static function ( string $label, bool $value ) use ( &$checks ): void { if ( ! $value ) { throw new RuntimeException( $label ); } ++$checks; };
    function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; }
    function apply_filters( $name, $default ) { return array( 'enabled' => $GLOBALS['enabled'], 'protocol' => 1, 'endpoint' => 'https://products.test/wp-json/peptide/v1/track/events' ); }
    function is_admin() { return false; } function is_preview() { return false; } function is_customize_preview() { return false; } function current_user_can( $cap ) { return $GLOBALS['staff']; }
    function is_front_page() { return true; } function is_singular() { return false; }
    function get_template_directory() { return dirname( __DIR__ ); } function get_template_directory_uri() { return 'https://products.test/theme'; }
    function esc_url_raw( $value ) { return $value; } function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
    function wp_script_is( $handle, $state ) { return false; }
    function wp_enqueue_script( $handle, $url, $dependencies, $version, $footer ) { $GLOBALS['scripts'][$handle] = array( 'dependencies' => $dependencies, 'url' => $url ); }
    function wp_add_inline_script( $handle, $text, $position ) { $GLOBALS['inline'][$handle] = $text; }
    require dirname( __DIR__ ) . '/inc/visitor-tracking.php';
    $hooks['wp_enqueue_scripts']();
    $text = $inline['vialcove-visitor-adapter']; $transport = json_decode( substr( $text, strpos( $text, '= ' ) + 2, -1 ), true ); $full = 'full' === $mode;
    $check( 'strict complete receiver capability only', $full ? 1 === $transport['product_impression_protocol'] : ! array_key_exists( 'product_impression_protocol', $transport ) );
    $check( 'optional module enqueued only when receiver supports full protocol', isset( $scripts['vialcove-visitor-products'] ) === $full );
    $check( 'collector dependencies retain video and optionally visibility', $scripts['vialcove-visitor-observer']['dependencies'] === ( $full ? array( 'vialcove-visitor-video', 'vialcove-visitor-products' ) : array( 'vialcove-visitor-video' ) ) );
    $check( 'trusted context available for impression-only capability; legacy config unchanged', $full ? 'home' === $transport['page_observation']['context']['kind'] : ! isset( $transport['page_observation'] ) );
    $scripts = array(); $staff = true; $hooks['wp_enqueue_scripts'](); $check( 'staff gate prevents all producers', array() === $scripts );
    $staff = false; $enabled = false; $hooks['wp_enqueue_scripts'](); $check( 'disabled backend prevents all producers', array() === $scripts );

    // Extract the unchanged function tokens directly, rather than duplicate a renderer implementation.
    $tokens = token_get_all( file_get_contents( dirname( __DIR__ ) . '/functions.php' ) ); $capture = false; $found = false; $level = 0; $body = '';
    foreach ( $tokens as $index => $token ) {
        if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
            $name = ''; for ( $next = $index + 1; $next < count( $tokens ); ++$next ) { if ( is_array( $tokens[$next] ) && T_STRING === $tokens[$next][0] ) { $name = $tokens[$next][1]; break; } if ( '(' === $tokens[$next] ) { break; } }
            if ( 'vc_product_card' === $name ) { $capture = true; }
        }
        if ( ! $capture ) { continue; } $body .= is_array( $token ) ? $token[1] : $token;
        if ( '{' === $token ) { ++$level; $found = true; } elseif ( '}' === $token && 0 === --$level && $found ) { break; }
    }
    if ( ! $found ) { throw new RuntimeException( 'Native renderer function missing' ); } eval( $body );
    class WC_Product { public function __construct( private int $id, private int $parent = 0 ) {} public function get_id() { return $this->id; } public function get_parent_id() { return $this->parent; } public function get_name() { return 'Synthetic'; } public function get_sku() { return ''; } public function get_image_id() { return 0; } public function get_permalink() { return '/product/'; } }
    function vc_product_data( $product ) { return array(); } function vc_vial( ...$args ) { echo '<svg></svg>'; } function vc_icon() { return ''; } function vc_card_price_html( $product ) { return '10'; } function vc_report_label( $value ) { return ''; }
    function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); } function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); } function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); } function wp_kses_post( $value ) { return $value; } function do_action( ...$args ) {}
    $render = static function ( $product ): string { ob_start(); vc_product_card( $product, false ); return ob_get_clean(); };
    $parent = $render( new WC_Product( 17 ) ); $variation = $render( new WC_Product( 101, 17 ) ); $invalid = $render( new WC_Product( 9007199254740992 ) );
    $check( 'native parent marker is escaped numeric identity', str_contains( $parent, 'data-vc-observe-product="17" data-vc-observe-variation="0"' ) );
    $check( 'native variation marker uses actual parent and variation', str_contains( $variation, 'data-vc-observe-product="17" data-vc-observe-variation="101"' ) );
    $check( 'unsupported native integer cannot fabricate marker', ! str_contains( $invalid, 'data-vc-observe-product' ) );
    $check( 'existing native card markup and links remain', str_contains( $parent, 'class="product-card"' ) && str_contains( $parent, 'class="product-heading"' ) && str_contains( $parent, 'href="/product/"' ) );
    echo 'PASS ', $checks, " product config assertions (", $mode, ")\n";
}
