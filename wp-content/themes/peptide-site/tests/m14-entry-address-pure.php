<?php
/**
 * FE-02 entry address (server side): switch default, same-site path extraction.
 * Run: php -n wp-content/themes/peptide-site/tests/m14-entry-address-pure.php
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['options'] = array();
$GLOBALS['enqueued'] = array();
function check( bool $ok, string $label ): void { global $checks; if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } $checks = ( $checks ?? 0 ) + 1; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function apply_filters( $tag, $value ) { return $value; }
function add_action( $tag, $callback, $priority = 10 ) { $GLOBALS['actions'][ $tag ][] = array( $callback, $priority ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return 'https://shop.example' . $path; }
function is_wp_error( $value ) { return false; }
function get_term_link( $id, $taxonomy ) { return 42 === $id ? 'https://shop.example/collections/best-sellers/' : 'https://other.example/x/'; }
function wp_script_is( $handle, $list ) { return in_array( $handle, $GLOBALS['enqueued'], true ); }
require dirname( __DIR__ ) . '/inc/entry-address.php';

check( false === vc_entry_address_enabled(), 'off unless the store switches it on' );
$GLOBALS['options']['pc_entry_address'] = 'on';
check( true === vc_entry_address_enabled(), 'on when the option says on' );
$GLOBALS['options']['pc_entry_address'] = '1';
check( false === vc_entry_address_enabled(), 'only the exact value on counts' );

$home = 'https://shop.example/';
check( '/product/bpc-157/' === vc_entry_address_path( 'https://shop.example/product/bpc-157/', $home ), 'product permalink path' );
check( '/collections/best/' === vc_entry_address_path( 'https://SHOP.example/collections/best/?orderby=price#top', $home ), 'query and fragment dropped, host case ignored' );
check( '/' === vc_entry_address_path( 'https://shop.example', $home ), 'bare host is the root path' );
check( '' === vc_entry_address_path( 'https://other.example/product/x/', $home ), 'other host refused' );
check( '' === vc_entry_address_path( '/product/x/', $home ), 'relative address refused' );
check( '' === vc_entry_address_path( 'javascript:alert(1)', $home ), 'script address refused' );
check( '' === vc_entry_address_path( "https://shop.example/a'b/", $home ), 'quote in path refused' );
check( '' === vc_entry_address_path( 'https://shop.example/a b/', $home ), 'space in path refused' );
check( '' === vc_entry_address_path( array( 'x' ), $home ) && '' === vc_entry_address_path( '', $home ), 'non-string and empty refused' );

$_GET = array( 'vc_catalog_category' => '42' );
check( '/collections/best-sellers/' === vc_entry_address_category(), 'collection landing resolves to its permalink path' );
$_GET = array( 'vc_catalog_category' => '7' );
check( '' === vc_entry_address_category(), 'collection link on another host is not used' );
foreach ( array( '0', '-1', '4 2', '42abc', array( '42' ) ) as $bad ) {
    $_GET = array( 'vc_catalog_category' => $bad );
    check( '' === vc_entry_address_category(), 'malformed collection id ignored' );
}
$_GET = array();
check( '' === vc_entry_address_category(), 'no collection entry' );
check( 1 === count( $GLOBALS['actions']['wp_enqueue_scripts'] ?? array() ) && 120 === $GLOBALS['actions']['wp_enqueue_scripts'][0][1], 'enqueue hook runs after the dialog layer script (95)' );

echo "PASS {$checks} entry address checks\n";
