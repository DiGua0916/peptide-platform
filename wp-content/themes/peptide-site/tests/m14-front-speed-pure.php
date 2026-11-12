<?php
/**
 * NF-07 (theme side): switch default, where it applies, and the non-blocking stylesheet tag.
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
require dirname( __DIR__ ) . '/inc/front-speed.php';

// Switch: off unless the store sets the option to exactly "on".
check( false === vc_front_speed_enabled() && false === vc_front_speed_applies(), 'off by default' );
$GLOBALS['options']['pc_front_speed'] = 'yes';
check( false === vc_front_speed_enabled(), 'only the exact value on counts' );
$filter = $GLOBALS['filters']['style_loader_tag'][0][0];
$link   = "<link rel='stylesheet' id='woocommerce-general-css' href='https://shop.example/wc.css?ver=1' media='all' />\n";
check( $link === $filter( $link, 'woocommerce-general' ), 'switch off: the tag is printed exactly as before' );
$init = $GLOBALS['actions']['init'][0][0];
$init();
check( empty( $GLOBALS['removed'] ), 'switch off: emoji hooks stay' );

$GLOBALS['options']['pc_front_speed'] = 'on';
check( true === vc_front_speed_enabled() && true === vc_front_speed_applies(), 'on: applies on the home page' );
// Where it applies: the home page with the dialog closed; never landings, other pages or admin.
$GLOBALS['state']['lazy'] = false;
check( false === vc_front_speed_applies() && $link === $filter( $link, 'woocommerce-general' ), 'product / collection landing or preview: unchanged' );
$GLOBALS['state'] = array( 'admin' => false, 'front' => false, 'lazy' => true );
check( false === vc_front_speed_applies() && $link === $filter( $link, 'woocommerce-general' ), 'cart, checkout and other pages: unchanged' );
$GLOBALS['state'] = array( 'admin' => true, 'front' => true, 'lazy' => true );
check( false === vc_front_speed_applies(), 'admin: unchanged' );
$GLOBALS['state'] = array( 'admin' => false, 'front' => true, 'lazy' => true );

// The tag: same link, loaded without blocking, own media put back on load, noscript copy.
$out = $filter( $link, 'woocommerce-general' );
check( str_contains( $out, " media=\"print\" data-vc-media=\"all\" onload=\"this.media=this.dataset.vcMedia;this.onload=null\"" ), 'media=all becomes print and is restored on load' );
check( str_contains( $out, "id='woocommerce-general-css'" ) && str_contains( $out, "href='https://shop.example/wc.css?ver=1'" ) && 1 === substr_count( $out, '<noscript>' ) && str_contains( $out, '<noscript>' . trim( $link ) . '</noscript>' ), 'same id and address, original kept for browsers without scripts' );
check( $link === $filter( $link, 'vialcove-brand' ) && $link === $filter( $link, 'peptide-core-modules' ), 'first-screen stylesheets are not touched' );
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
check( 11 === count( $handles ) && count( $handles ) === count( array_unique( $handles ) ), 'eleven stylesheets, none twice' );
foreach ( array( 'vialcove-brand', 'vialcove-site', 'peptide-core-modules', 'vc-campaign-bubble', 'vc-floating-cart', 'vc-storefront-utilities', 'vc-footer-details', 'vialcove-storefront-motion', 'vc-inline-delivery' ) as $first_screen ) {
    check( ! in_array( $first_screen, $handles, true ), "$first_screen stays blocking" );
}
// Emoji detection script and styles: removed only when the switch is on, never in admin.
$init();
check( array( 'wp_head:print_emoji_detection_script:7', 'wp_enqueue_scripts:wp_enqueue_emoji_styles:10', 'wp_print_styles:print_emoji_styles:10' ) === $GLOBALS['removed'], 'on: emoji script and styles are not printed' );
$GLOBALS['removed'] = array(); $GLOBALS['state']['admin'] = true; $init();
check( empty( $GLOBALS['removed'] ), 'admin keeps emoji hooks' );
// Marketing components: this file never touches a script.
$src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/front-speed.php' );
check( ! str_contains( $src, 'script_loader_tag' ) && ! str_contains( $src, 'wp_dequeue_script' ) && ! str_contains( $src, 'wp_deregister_script' ) && ! str_contains( $src, 'wp_dequeue_style' ), 'no script is deferred or removed, no stylesheet is dropped' );
$declare = $GLOBALS['filters']['pc_hq_capabilities'][0][0];
check( array( 'a' => '1', 'm14.front_speed' => '1' ) === $declare( array( 'a' => '1' ) ) && 'x' === $declare( 'x' ), 'declares the feature to the master admin' );

echo "PASS {$checks} front speed checks\n";
