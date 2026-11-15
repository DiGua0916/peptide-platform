<?php
/**
 * Brand name (ST-07), product use statement (NF-06) and "Track your order" entry (FE-11).
 * Run: php -n wp-content/themes/peptide-site/tests/m14-storefront-identity-pure.php
 */
namespace PeptideCore\Admin {
    final class Store_Settings { public static string $brand = ''; public static function public_brand(): string { return self::$brand; } }
}
namespace PeptideCore {
    final class Buyer_Status { public static bool $on = false; public static function enabled(): bool { return self::$on; } }
    final class Track_Widget { public static bool $on = true; public static function enabled(): bool { return self::$on; } }
}
namespace {
    define( 'ABSPATH', __DIR__ . '/' );
    $GLOBALS['config'] = array();
    $GLOBALS['customize'] = false;
    function check( bool $ok, string $label ): void { global $checks; if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } $checks = ( $checks ?? 0 ) + 1; }
    function apply_filters( $tag, $value ) { return $value; }
    function vc_site_config(): array { return $GLOBALS['config']; }
    function wp_kses_post( $html ) { return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html ); }
    function is_customize_preview() { return $GLOBALS['customize']; }
    function vc_icon(): string { return '<svg></svg>'; }
    require dirname( __DIR__ ) . '/inc/storefront-identity.php';
    $capture = static function ( callable $fn ): string { ob_start(); $fn(); return (string) ob_get_clean(); };

    // Brand name: the store's own English name when set, otherwise the default as before.
    check( 'VialCovePeptides' === vc_brand_name(), 'default brand unchanged while nothing is set' );
    \PeptideCore\Admin\Store_Settings::$brand = 'VialCove Plus';
    check( 'VialCove Plus' === vc_brand_name(), 'store brand used once the store sets one' );
    \PeptideCore\Admin\Store_Settings::$brand = '';

    // Use statement: only what the store configured, never invented wording.
    check( '' === vc_use_statement_markup() && '' === $capture( 'vc_use_statement' ), 'nothing printed without a configured statement' );
    $GLOBALS['config'] = array( 'use_statement_html' => "  \n " );
    check( '' === vc_use_statement_markup(), 'blank statement prints nothing' );
    $GLOBALS['config'] = array( 'use_statement_html' => '<p>Configured by the store.</p><script>alert(1)</script>' );
    $markup = vc_use_statement_markup();
    check( str_contains( $markup, '<section class="vc-use-statement" aria-label="Product use statement">' ) && str_contains( $markup, '<p>Configured by the store.</p>' ), 'configured statement is printed in its section' );
    check( ! str_contains( $markup, '<script' ), 'statement goes through wp_kses_post' );
    check( $markup === $capture( 'vc_use_statement' ), 'footer and product dialog print the same markup' );
    $dialog = (string) file_get_contents( dirname( __DIR__ ) . '/inc/product-dialog-content.php' );
    check( str_contains( $dialog, 'vc_use_statement_markup()' ) && str_contains( $dialog, '$html . $statement' ), 'product dialog content ends with the use statement' );
    $footer = (string) file_get_contents( dirname( __DIR__ ) . '/footer.php' );
    check( str_contains( $footer, 'vc_use_statement(); vc_track_order_entry();' ), 'footer prints the statement and the order entry' );

    // Track-your-order entry (Q20 plan A, 2026-11-14): opens the store's existing tracking panel.
    check( '' === $capture( 'vc_track_order_entry' ), 'no entry while returning-buyer recognition is off (as online)' );
    \PeptideCore\Buyer_Status::$on = true;
    $entry = $capture( 'vc_track_order_entry' );
    check( '<p class="vc-track-order"><button type="button" data-pc-open-track>Track your order <svg></svg></button></p>' === $entry, 'entry is a button that opens the existing tracking panel' );
    check( ! str_contains( $entry, 'href' ) && ! str_contains( $entry, 'track-order/' ), 'no link to the retired look-up page' );
    \PeptideCore\Track_Widget::$on = false;
    check( '' === $capture( 'vc_track_order_entry' ), 'no entry when the store switched the tracking panel off' );
    \PeptideCore\Track_Widget::$on = true;
    $GLOBALS['customize'] = true;
    check( '' === $capture( 'vc_track_order_entry' ), 'no entry in the customizer preview (the panel is not there either)' );
    $GLOBALS['customize'] = false;
    \PeptideCore\Buyer_Status::$on = false;
    check( '' === $capture( 'vc_track_order_entry' ), 'switching recognition off removes the entry again' );
    $panel = (string) file_get_contents( dirname( __DIR__, 3 ) . '/plugins/peptide-core/assets/track-widget.js' );
    check( str_contains( $panel, "closest( '[data-pc-open-track]' )" ), 'the tracking panel still listens for [data-pc-open-track]' );
    $css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/site.css' );
    check( str_contains( $css, '.vc-track-order button{' ), 'the entry button is styled like the old link' );

    echo "PASS {$checks} storefront identity checks\n";
}
