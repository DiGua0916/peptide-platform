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
    final class Order_Lookup { public static string $url = ''; public static function page_url(): string { return self::$url; } }
}
namespace {
    define( 'ABSPATH', __DIR__ . '/' );
    $GLOBALS['config'] = array();
    $GLOBALS['page'] = 0;
    function check( bool $ok, string $label ): void { global $checks; if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } $checks = ( $checks ?? 0 ) + 1; }
    function apply_filters( $tag, $value ) { return $value; }
    function vc_site_config(): array { return $GLOBALS['config']; }
    function wp_kses_post( $html ) { return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html ); }
    function is_page() { return $GLOBALS['page'] > 0; }
    function get_queried_object_id() { return $GLOBALS['page']; }
    function get_option( $name, $default = false ) { return 'pc_track_order_page' === $name ? 77 : $default; }
    function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES ); }
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

    // Track-your-order entry: only with the store switch on and a look-up page; not on that page.
    check( '' === $capture( 'vc_track_order_entry' ), 'no entry while returning-buyer recognition is off' );
    \PeptideCore\Buyer_Status::$on = true;
    check( '' === $capture( 'vc_track_order_entry' ), 'no entry without a look-up page' );
    \PeptideCore\Order_Lookup::$url = 'https://shop.example/track-order/';
    $entry = $capture( 'vc_track_order_entry' );
    check( str_contains( $entry, '<p class="vc-track-order"><a href="https://shop.example/track-order/">Track your order ' ), 'entry links to the look-up page' );
    $GLOBALS['page'] = 77;
    check( '' === $capture( 'vc_track_order_entry' ), 'no entry on the look-up page itself' );
    $GLOBALS['page'] = 12;
    check( '' !== $capture( 'vc_track_order_entry' ), 'entry on other pages' );
    \PeptideCore\Buyer_Status::$on = false;
    check( '' === $capture( 'vc_track_order_entry' ), 'switching off removes the entry again' );

    echo "PASS {$checks} storefront identity checks\n";
}
