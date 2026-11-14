<?php
/**
 * NF-07 (theme side): switch default, which entry page gets what, the non-blocking stylesheet
 * tag, and the product landing helpers (image preload, inline template check).
 * Run: php -n wp-content/themes/peptide-site/tests/m14-front-speed-pure.php
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['options'] = array();
$GLOBALS['state'] = array( 'admin' => false, 'front' => true, 'lazy' => true );
function check( bool $ok, string $label ): void { global $checks; if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } $checks = ( $checks ?? 0 ) + 1; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function apply_filters( $tag, $value ) { return $value; }
function add_filter( $tag, $callback, $priority = 10, $args = 1 ) { $GLOBALS['filters'][ $tag ][] = array( $callback, $priority ); }
function add_action( $tag, $callback, $priority = 10, $args = 1 ) { $GLOBALS['actions'][ $tag ][] = array( $callback, $priority ); }
function remove_action( $tag, $callback, $priority = 10 ) { $GLOBALS['removed'][] = $tag . ':' . $callback . ':' . $priority; return true; }
function is_admin() { return $GLOBALS['state']['admin']; }
function is_front_page() { return $GLOBALS['state']['front']; }
function vc_lazy_dialog_enabled() { return $GLOBALS['state']['lazy']; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function home_url( $path = '' ) { return 'https://shop.example' . $path; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
require dirname( __DIR__ ) . '/inc/front-speed.php';

// Switch: off unless the store sets the option to exactly "on".
check( false === vc_front_speed_enabled() && false === vc_front_speed_applies() && '' === vc_front_speed_page(), 'off by default' );
$GLOBALS['options']['pc_front_speed'] = 'yes';
check( false === vc_front_speed_enabled(), 'only the exact value on counts' );
$filter = $GLOBALS['filters']['style_loader_tag'][0][0];
$link   = "<link rel='stylesheet' id='woocommerce-general-css' href='https://shop.example/wc.css?ver=1' media='all' />\n";
check( $link === $filter( $link, 'woocommerce-general' ), 'switch off: the tag is printed exactly as before' );
$init = $GLOBALS['actions']['init'][0][0];
$init();
check( empty( $GLOBALS['removed'] ), 'switch off: emoji hooks stay' );
$_GET = array( 'vc_product' => '12' );
check( null === vc_front_speed_entry_product(), 'switch off: no product landing work' );
$_GET = array();

// Which kind of entry page a request is.
$kind = static fn( array $keys, bool $lazy = true, bool $preview = false ): string => vc_front_speed_page_kind( $keys, $lazy, $preview );
check( 'home' === $kind( array() ) && 'home' === $kind( array( 'utm_source', 'fbclid' ) ), 'home page, with or without ad parameters' );
check( 'product' === $kind( array( 'vc_product', 'utm_source' ), false ), 'product landing' );
check( 'collection' === $kind( array( 'vc_catalog_category' ), false ) && 'collection' === $kind( array( 'vc_catalog_q' ), false ), 'collection and search landings' );
check( 'product' === $kind( array( 'vc_catalog_category', 'vc_product' ), false ), 'a product opened from a collection link is a product landing' );
check( '' === $kind( array(), false ), 'home page whose dialog scripts stay eager for another reason: left alone' );
check( '' === $kind( array( 'vc_product' ), false, true ) && '' === $kind( array( 'pc_template_preview' ) ) && '' === $kind( array( 'pc_frame', 'vc_catalog_category' ), false ), 'previews and editor frames: left alone' );

$GLOBALS['options']['pc_front_speed'] = 'on';
check( true === vc_front_speed_enabled() && 'home' === vc_front_speed_page() && true === vc_front_speed_applies(), 'on: applies on the home page' );
$GLOBALS['state'] = array( 'admin' => false, 'front' => false, 'lazy' => true );
check( '' === vc_front_speed_page() && $link === $filter( $link, 'woocommerce-general' ), 'cart, checkout and other pages: unchanged' );
$GLOBALS['state'] = array( 'admin' => true, 'front' => true, 'lazy' => true );
check( '' === vc_front_speed_page(), 'admin: unchanged' );
$GLOBALS['state'] = array( 'admin' => false, 'front' => true, 'lazy' => true );

// Home page: the eleven closed-surface stylesheets; the dialog's own sheets belong to offscreen-styles.php.
$out = $filter( $link, 'woocommerce-general' );
check( str_contains( $out, " media=\"print\" data-vc-media=\"all\" onload=\"this.media=this.dataset.vcMedia;this.onload=null\"" ), 'media=all becomes print and is restored on load' );
check( str_contains( $out, "id='woocommerce-general-css'" ) && str_contains( $out, "href='https://shop.example/wc.css?ver=1'" ) && 1 === substr_count( $out, '<noscript>' ) && str_contains( $out, '<noscript>' . trim( $link ) . '</noscript>' ), 'same id and address, original kept for browsers without scripts' );
check( $link === $filter( $link, 'vialcove-brand' ) && $link === $filter( $link, 'peptide-core-modules' ), 'first-screen stylesheets are not touched' );
check( $link === $filter( $link, 'vc-home-quick-view' ), 'home page: the dialog sheets are not handled twice' );

// Collection landing: the dialog is closed too, so its three sheets join the eleven.
$GLOBALS['state']['lazy'] = false;
$_GET = array( 'vc_catalog_category' => '15', 'utm_source' => 'ig' );
check( 'collection' === vc_front_speed_page() && true === vc_front_speed_applies(), 'collection landing applies' );
check( str_contains( $filter( $link, 'woocommerce-general' ), 'data-vc-media="all"' ) && str_contains( $filter( $link, 'vc-home-quick-view' ), 'data-vc-media="all"' ) && str_contains( $filter( $link, 'vc-product-dialog-polish' ), 'data-vc-media="all"' ), 'collection landing: closed-surface and dialog sheets stop blocking' );
check( $link === $filter( $link, 'vialcove-demo-catalog' ) && $link === $filter( $link, 'vialcove-brand' ), 'collection landing: the catalogue itself stays blocking' );

// Product landing: the dialog opens at once, so no stylesheet is deferred.
$_GET = array( 'vc_product' => '12', 'fbclid' => 'x' );
check( 'product' === vc_front_speed_page() && false === vc_front_speed_applies(), 'product landing is not a deferred-styles page' );
foreach ( array( 'woocommerce-general', 'vc-live-cart-experience', 'vc-home-quick-view', 'vc-product-dialog-polish', 'vc-product-report-viewer' ) as $handle ) {
    check( $link === $filter( $link, $handle ), "product landing keeps $handle blocking" );
}
$_GET = array();
$GLOBALS['state']['lazy'] = true;

// The tag itself.
$small = "<link rel='stylesheet' id='woocommerce-smallscreen-css' href='https://shop.example/s.css' media='only screen and (max-width: 768px)' />";
$out   = vc_front_speed_style_tag( $small );
check( str_contains( $out, 'media="print" data-vc-media="only screen and (max-width: 768px)"' ) && ! str_contains( explode( '<noscript>', $out )[0], "media='only screen" ), 'a media query is kept and put back, not replaced by all' );
$bare = '<link rel="stylesheet" id="x-css" href="https://shop.example/x.css">';
check( str_contains( vc_front_speed_style_tag( $bare ), '<link media="print" data-vc-media="all"' ), 'no media attribute: treated as all' );
foreach ( array(
    'already print' => "<link rel='stylesheet' href='https://shop.example/p.css' media='print' />",
    'has its own onload' => "<link rel='stylesheet' href='https://shop.example/p.css' media='all' onload='x()' />",
    'two links in one tag' => "<link rel='stylesheet' href='a.css' media='all' /><link rel='stylesheet' href='b.css' media='all' />",
    'no link at all' => '<style>.a{color:red}</style>',
    'odd media value' => "<link rel='stylesheet' href='a.css' media='all><script>' />",
) as $label => $tag ) {
    check( $tag === vc_front_speed_style_tag( $tag ), "left untouched: $label" );
}
$handles = vc_front_speed_style_handles();
check( 11 === count( $handles ) && count( $handles ) === count( array_unique( $handles ) ) && ! array_intersect( $handles, vc_front_speed_dialog_style_handles() ), 'eleven closed-surface stylesheets, none twice, separate from the three dialog sheets' );
foreach ( array( 'vialcove-brand', 'vialcove-site', 'peptide-core-modules', 'vc-campaign-bubble', 'vc-floating-cart', 'vc-storefront-utilities', 'vc-footer-details', 'vialcove-storefront-motion', 'vc-inline-delivery', 'pc-campaign-countdown', 'pc-track-widget', 'pc-account-gate' ) as $first_screen ) {
    check( ! in_array( $first_screen, array_merge( $handles, vc_front_speed_dialog_style_handles() ), true ), "$first_screen stays blocking" );
}

// Product landing helpers.
$image = array(
    'src' => 'https://shop.example/wp-content/uploads/a-600x600.jpg', 'width' => 600, 'height' => 600,
    'srcset' => 'https://shop.example/wp-content/uploads/a-600x600.jpg 600w, https://shop.example/wp-content/uploads/a-300x300.jpg 300w',
    'sizes' => '(max-width: 767px) calc(100vw - 64px), 500px', 'alt' => 'A',
);
$preload = vc_front_speed_image_preload( $image, 'https://shop.example/' );
check( str_starts_with( $preload, '<link rel="preload" as="image" href="https://shop.example/wp-content/uploads/a-600x600.jpg"' ) && str_contains( $preload, ' fetchpriority="high">' ), 'image preload points at the dialog image' );
check( str_contains( $preload, ' media="(max-width: 767px)"' ), 'only on phone widths, where the dialog image uses exactly this srcset and sizes' );
check( str_contains( $preload, 'imagesrcset="' . esc_attr( $image['srcset'] ) . '"' ) && str_contains( $preload, 'imagesizes="(max-width: 767px) calc(100vw - 64px), 500px"' ), 'with the same srcset and sizes the dialog uses' );
$plain = vc_front_speed_image_preload( array( 'src' => 'https://shop.example/a.jpg', 'srcset' => '', 'sizes' => '100vw' ), 'https://shop.example/' );
check( ! str_contains( $plain, 'imagesrcset' ) && ! str_contains( $plain, 'imagesizes' ), 'no srcset: a plain preload without sizes' );
foreach ( array(
    'no image' => null,
    'empty src' => array( 'src' => '' ),
    'another host (the dialog would not show it either)' => array( 'src' => 'https://cdn.other.example/a.jpg' ),
    'not http' => array( 'src' => 'data:image/png;base64,AAAA' ),
    'not an array' => 'https://shop.example/a.jpg',
) as $label => $bad ) {
    check( '' === vc_front_speed_image_preload( $bad, 'https://shop.example/' ), "no preload: $label" );
}
$quoted = vc_front_speed_image_preload( array( 'src' => 'https://shop.example/a.jpg', 'srcset' => 'https://shop.example/a.jpg 1x"><script>', 'sizes' => '' ), 'https://shop.example/' );
check( ! str_contains( $quoted, '<script>' ), 'attribute values are escaped' );

$tpl = '<template data-vc-quick-product="12" data-vc-quick-config="{}"><div class="vc-showcase-quick-product" data-vc-product-id="12"></div></template>';
check( true === vc_front_speed_template_ok( $tpl, 12 ) && true === vc_front_speed_template_ok( "\n " . $tpl . "\n", 12 ), 'the product template the dialog script looks for' );
check( false === vc_front_speed_template_ok( $tpl, 13 ) && false === vc_front_speed_template_ok( '', 12 ) && false === vc_front_speed_template_ok( '<div>' . $tpl . '</div>', 12 ) && false === vc_front_speed_template_ok( $tpl . '<script>x</script>', 12 ) && false === vc_front_speed_template_ok( $tpl, 0 ), 'anything else is not printed' );

// Emoji detection script and styles: removed only when the switch is on, never in admin.
$init();
check( array( 'wp_head:print_emoji_detection_script:7', 'wp_enqueue_scripts:wp_enqueue_emoji_styles:10', 'wp_print_styles:print_emoji_styles:10' ) === $GLOBALS['removed'], 'on: emoji script and styles are not printed' );
$GLOBALS['removed'] = array(); $GLOBALS['state']['admin'] = true; $init();
check( empty( $GLOBALS['removed'] ), 'admin keeps emoji hooks' );

// Marketing components: this file never touches a script.
$src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/front-speed.php' );
check( ! str_contains( $src, 'script_loader_tag' ) && ! str_contains( $src, 'wp_dequeue_script' ) && ! str_contains( $src, 'wp_deregister_script' ) && ! str_contains( $src, 'wp_dequeue_style' ), 'no script is deferred or removed, no stylesheet is dropped' );
check( 5 === array_values( array_filter( $GLOBALS['actions']['wp_footer'] ?? array(), static fn( $a ) => 5 === $a[1] ) )[0][1] && 2 === ( $GLOBALS['actions']['wp_head'][0][1] ?? 0 ), 'template is printed before the footer scripts, preload early in the head' );
$declare = $GLOBALS['filters']['pc_hq_capabilities'][0][0];
check( array( 'a' => '1', 'm14.front_speed' => '1' ) === $declare( array( 'a' => '1' ) ) && 'x' === $declare( 'x' ), 'declares the feature to the master admin' );

echo "PASS {$checks} front speed checks\n";
