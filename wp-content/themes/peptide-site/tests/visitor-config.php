<?php
/** Standalone configuration regression checks. Run: php -n tests/visitor-config.php */

namespace PeptideCore {
    final class Templates {
        public static int $preview_id = 0;
        public static function previewing_id(): int { return self::$preview_id; }
    }
}

namespace {
    define( 'ABSPATH', __DIR__ . '/' );

    $checks = 0;
    $hooks = array();
    $fixture = array();
    $scripts = array();
    $inline = array();

    function expect_same( $expected, $actual, string $label ): void {
        global $checks;
        ++$checks;
        if ( $expected !== $actual ) {
            fwrite( STDERR, "FAIL: {$label}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
            exit( 1 );
        }
    }

    // Only the WordPress functions needed by the real enqueue callback are stubbed.
    function add_action( string $hook, callable $callback, int $priority = 10 ): void {
        $GLOBALS['hooks'][ $hook ] = array( 'callback' => $callback, 'priority' => $priority );
    }
    function apply_filters( string $hook, $default ) {
        expect_same( 'vialcove_visitor_observer_config', $hook, 'plugin configuration filter' );
        return $GLOBALS['fixture']['config'];
    }
    function is_admin(): bool { return ! empty( $GLOBALS['fixture']['admin'] ); }
    function is_preview(): bool { return ! empty( $GLOBALS['fixture']['preview'] ); }
    function is_customize_preview(): bool { return ! empty( $GLOBALS['fixture']['customizer'] ); }
    function current_user_can( string $capability ): bool { return ! empty( $GLOBALS['fixture'][ $capability ] ); }
    function is_front_page(): bool { return true; }
    function is_singular(): bool { return false; }
    function get_template_directory(): string { return dirname( __DIR__ ); }
    function get_template_directory_uri(): string { return 'https://example.test/wp-content/themes/peptide-site'; }
    function esc_url_raw( string $url ): string { return $url; }
    function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
    function wp_enqueue_script( string $handle, string $url, array $dependencies, string $version, bool $footer ): void {
        $GLOBALS['scripts'][ $handle ] = compact( 'url', 'dependencies', 'version', 'footer' );
    }
    function wp_add_inline_script( string $handle, string $code, string $position = 'after' ): void {
        $GLOBALS['inline'][ $handle ] = compact( 'code', 'position' );
    }

    require dirname( __DIR__ ) . '/inc/visitor-tracking.php';
    expect_same( true, isset( $hooks['wp_enqueue_scripts'] ), 'enqueue hook registered' );
    expect_same( 40, $hooks['wp_enqueue_scripts']['priority'], 'enqueue priority' );

    function enqueue_fixture( $config, array $flags = array(), array $query = array() ): void {
        $GLOBALS['fixture'] = array_merge( array( 'config' => $config ), $flags );
        $GLOBALS['scripts'] = array();
        $GLOBALS['inline'] = array();
        $_GET = $query;
        \PeptideCore\Templates::$preview_id = (int) ( $flags['template_preview'] ?? 0 );
        ( $GLOBALS['hooks']['wp_enqueue_scripts']['callback'] )();
    }

    function expect_no_assets( string $label ): void {
        expect_same( array(), $GLOBALS['scripts'], $label . ': no visitor scripts' );
        expect_same( array(), $GLOBALS['inline'], $label . ': no visitor globals' );
    }

    function inline_value( string $handle, string $variable ): array {
        $entry = $GLOBALS['inline'][ $handle ] ?? array();
        expect_same( 'before', $entry['position'] ?? null, $variable . ' precedes its script' );
        $prefix = 'window.' . $variable . ' = ';
        $code = $entry['code'] ?? '';
        expect_same( true, str_starts_with( $code, $prefix ) && str_ends_with( $code, ';' ), $variable . ' assignment' );
        $data = json_decode( substr( $code, strlen( $prefix ), -1 ), true, 512, JSON_THROW_ON_ERROR );
        expect_same( true, is_array( $data ), $variable . ' JSON object' );
        return $data;
    }

    $base = array(
        'enabled' => true,
        'test_mode' => false,
        'mode' => 'live',
        'protocol' => 1,
        'endpoint' => 'https://example.test/wp-json/peptide/v1/track/events',
        'heartbeat_ms' => 15000,
        'idle_ms' => 60000,
        'batch_max' => 20,
    );

    foreach ( array( false, true ) as $test_mode ) {
        $config = array_merge( $base, array( 'test_mode' => $test_mode, 'mode' => $test_mode ? 'test' : 'live' ) );
        enqueue_fixture( $config );
        expect_same( array( 'vialcove-visitor-video', 'vialcove-visitor-observer', 'vialcove-visitor-adapter' ), array_keys( $scripts ), 'enabled mode loads all three scripts' );
        expect_same( array( 'vialcove-visitor-video' ), $scripts['vialcove-visitor-observer']['dependencies'], 'observer follows video helper' );
        expect_same( array( 'vialcove-visitor-observer' ), $scripts['vialcove-visitor-adapter']['dependencies'], 'transport follows observer' );
        foreach ( $scripts as $handle => $script ) {
            expect_same( true, $script['footer'], $handle . ' footer placement' );
            expect_same( true, ctype_digit( $script['version'] ), $handle . ' asset cache version' );
        }
        expect_same( array( 'page_type' => 'home', 'path' => '/' ), inline_value( 'vialcove-visitor-observer', 'VialCoveVisitorPage' ), 'page context retained' );
        expect_same( $config, inline_value( 'vialcove-visitor-adapter', 'VialCoveVisitorConfig' ), 'live/test mode and complete transport preserved' );
    }

    foreach ( array( null, false, 'invalid' ) as $config ) {
        enqueue_fixture( $config );
        expect_no_assets( 'non-array configuration ' . var_export( $config, true ) );
    }
    foreach ( array( false, 0, 1, 'true', '1', null ) as $enabled ) {
        enqueue_fixture( array_merge( $base, array( 'enabled' => $enabled ) ) );
        expect_no_assets( 'enabled must be literal true: ' . var_export( $enabled, true ) );
    }
    foreach ( array( 0, 2, '1', 1.0, true, null ) as $protocol ) {
        enqueue_fixture( array_merge( $base, array( 'protocol' => $protocol ) ) );
        expect_no_assets( 'protocol must be integer 1: ' . var_export( $protocol, true ) );
    }
    foreach ( array( '', null, false ) as $endpoint ) {
        enqueue_fixture( array_merge( $base, array( 'endpoint' => $endpoint ) ) );
        expect_no_assets( 'empty endpoint: ' . var_export( $endpoint, true ) );
    }
    foreach ( array( 'enabled', 'protocol', 'endpoint' ) as $missing ) {
        $config = $base;
        unset( $config[ $missing ] );
        enqueue_fixture( $config );
        expect_no_assets( 'missing ' . $missing );
    }

    foreach ( array( 'admin', 'preview', 'customizer', 'manage_woocommerce', 'manage_options' ) as $flag ) {
        enqueue_fixture( $base, array( $flag => true ) );
        expect_no_assets( 'excluded ' . $flag );
    }
    foreach ( array( 'pc_frame', 'preview', 'pc_preview_token' ) as $key ) {
        enqueue_fixture( $base, array(), array( $key => '' ) );
        expect_no_assets( 'excluded query flag ' . $key );
    }
    enqueue_fixture( $base, array( 'template_preview' => 123 ) );
    expect_no_assets( 'excluded backend template preview' );

    $config = array( 'enabled' => true, 'protocol' => 1, 'endpoint' => $base['endpoint'] );
    enqueue_fixture( $config );
    expect_same( $base, inline_value( 'vialcove-visitor-adapter', 'VialCoveVisitorConfig' ), 'omitted optional configuration has live-mode defaults' );

    $config = array_merge( $base, array( 'heartbeat_ms' => 12000, 'idle_ms' => 90000, 'batch_max' => 7 ) );
    enqueue_fixture( $config );
    expect_same( $config, inline_value( 'vialcove-visitor-adapter', 'VialCoveVisitorConfig' ), 'backend timing and batch values forwarded' );
    foreach ( array( 0 => 1, 99 => 20 ) as $input => $expected ) {
        enqueue_fixture( array_merge( $base, array( 'batch_max' => $input ) ) );
        $transport = inline_value( 'vialcove-visitor-adapter', 'VialCoveVisitorConfig' );
        expect_same( $expected, $transport['batch_max'], 'batch limit stays within protocol range' );
    }

    echo "PASS: {$checks} visitor configuration assertions\n";
}
