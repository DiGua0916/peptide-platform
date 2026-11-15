<?php
/**
 * FE-03 "viewed / added to bag" line (server side): switch default and the minimum to show.
 * Run: php -n wp-content/themes/peptide-site/tests/m14-product-activity-pure.php
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['options'] = array();
function check( bool $ok, string $label ): void { global $checks; if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } $checks = ( $checks ?? 0 ) + 1; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function apply_filters( $tag, $value ) { return array_key_exists( $tag, $GLOBALS['filter_values'] ?? array() ) ? $GLOBALS['filter_values'][ $tag ] : $value; }
function add_filter( $tag, $callback, $priority = 10 ) { $GLOBALS['filters'][ $tag ][] = $callback; }
function add_action( $tag, $callback, $priority = 10 ) { $GLOBALS['actions'][ $tag ][] = array( $callback, $priority ); }
function is_admin() { return false; }
function wp_script_is( $handle, $list ) { return in_array( $handle, $GLOBALS['enqueued'] ?? array(), true ); }
function get_template_directory() { return dirname( __DIR__ ); }
function get_template_directory_uri() { return 'https://shop.example/wp-content/themes/peptide-site'; }
function rest_url( $path = '' ) { return 'https://shop.example/wp-json/' . $path; }
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) { $GLOBALS['enqueued'][] = $handle; $GLOBALS['script'][ $handle ] = array( $src, $footer ); }
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['localized'][ $name ] = $data; }
require dirname( __DIR__ ) . '/inc/product-activity.php';

check( false === vc_product_activity_enabled(), 'off by default' );
$GLOBALS['options']['pc_product_activity'] = 'true';
check( false === vc_product_activity_enabled(), 'only the exact value on counts' );

// The minimum: a whole number from 1 to 1000; anything else falls back to 5.
check( 5 === vc_product_activity_min(), 'default minimum is 5' );
check( 3 === vc_product_activity_min( 3 ) && 3 === vc_product_activity_min( '3' ) && 1 === vc_product_activity_min( 1 ), 'a store can lower it' );
check( 1000 === vc_product_activity_min( 5000 ) && 1000 === vc_product_activity_min( '999999' ), 'capped at 1000' );
foreach ( array( 0, -2, '0', 'abc', '3.5', 2.5, array( 3 ), true, '' ) as $bad ) {
    check( 5 === vc_product_activity_min( $bad ), 'unusable minimum falls back to 5' );
}
$GLOBALS['options']['pc_product_activity_min'] = '8';
check( 8 === vc_product_activity_min(), 'read from the store option' );

// Assets: nothing while the switch is off; only where the product dialog can open.
$enqueue = $GLOBALS['actions']['wp_enqueue_scripts'][0][0];
$GLOBALS['enqueued'] = array( 'vc-home-quick-view' );
$enqueue();
check( ! in_array( 'vc-product-activity', $GLOBALS['enqueued'], true ), 'switch off: no script' );
$GLOBALS['options']['pc_product_activity'] = 'on';
// The data module's endpoint is not there yet: the switch alone loads nothing (no 404 requests).
check( false === vc_product_activity_available(), 'endpoint not announced by default' );
$enqueue();
check( ! in_array( 'vc-product-activity', $GLOBALS['enqueued'], true ), 'switch on but no endpoint: no script' );
$GLOBALS['filter_values']['pc_product_activity_available'] = 1;
check( false === vc_product_activity_available(), 'only a real true counts as available' );
$GLOBALS['filter_values']['pc_product_activity_available'] = true;
check( true === vc_product_activity_available(), 'the data module announces its endpoint' );
$GLOBALS['enqueued'] = array();
$enqueue();
check( ! in_array( 'vc-product-activity', $GLOBALS['enqueued'], true ), 'pages without the product dialog: no script' );
$GLOBALS['enqueued'] = array( 'vc-home-quick-view' );
$enqueue();
check( in_array( 'vc-product-activity', $GLOBALS['enqueued'], true ) && true === $GLOBALS['script']['vc-product-activity'][1], 'switch on, dialog page: script in the footer' );
check( array( 'enabled' => true, 'url' => 'https://shop.example/wp-json/peptide/v1/product-activity', 'min' => 8 ) === $GLOBALS['localized']['vcProductActivity'], 'the script gets the data endpoint and the minimum, nothing else' );
check( 125 === $GLOBALS['actions']['wp_enqueue_scripts'][0][1], 'enqueued after the dialog scripts (35 / 110)' );

// No number is ever produced by the theme.
$src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/product-activity.php' ) . (string) file_get_contents( dirname( __DIR__ ) . '/assets/product-activity.js' );
foreach ( array( 'Math.random', 'rand(', 'mt_rand', 'random_int', 'wp_rand', 'Math.ceil', 'Math.max(' ) as $forbidden ) {
    check( ! str_contains( $src, $forbidden ), "no $forbidden: numbers are shown as received or not at all" );
}
$declare = $GLOBALS['filters']['pc_hq_capabilities'][0];
check( array( 'a' => '1', 'm14.product_activity' => '1' ) === $declare( array( 'a' => '1' ) ) && 'x' === $declare( 'x' ), 'declares the feature to the master admin' );

echo "PASS {$checks} product activity checks\n";
