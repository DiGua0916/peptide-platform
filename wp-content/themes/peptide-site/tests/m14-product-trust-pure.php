<?php
/**
 * FE-03 trust strip (server side): switch default, which payment marks are usable, what is printed.
 * Run: php -n wp-content/themes/peptide-site/tests/m14-product-trust-pure.php
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['options'] = array();
function check( bool $ok, string $label ): void { global $checks; if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } $checks = ( $checks ?? 0 ) + 1; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function apply_filters( $tag, $value ) { return $value; }
function add_filter( $tag, $callback, $priority = 10 ) { $GLOBALS['filters'][ $tag ][] = $callback; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function home_url( $path = '' ) { return 'https://shop.example' . $path; }
function is_ssl() { return false; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
require dirname( __DIR__ ) . '/inc/product-trust.php';

// Switch: off unless the store sets the option to exactly "on".
check( false === vc_trust_badges_enabled(), 'off by default' );
check( '' === vc_trust_badges(), 'nothing is printed while the switch is off' );
$GLOBALS['options']['pc_trust_badges'] = '1';
check( false === vc_trust_badges_enabled(), 'only the exact value on counts' );
$GLOBALS['options']['pc_trust_badges'] = 'on';
check( true === vc_trust_badges_enabled(), 'on when the option says on' );

// Secure line: only when customers reach the store over HTTPS.
check( true === vc_trust_secure( true, 'http://shop.example/' ), 'this request is HTTPS' );
check( true === vc_trust_secure( false, 'https://shop.example/' ), 'the public address is HTTPS (PHP behind a proxy)' );
check( false === vc_trust_secure( false, 'http://shop.example/' ) && false === vc_trust_secure( false, '' ), 'plain HTTP store: no secure line' );

// Payment marks: only well-formed rows with an image on this site.
$home = 'https://shop.example/';
$visa = array( 'id' => 'visa', 'label' => 'Visa', 'url' => 'https://shop.example/wp-content/plugins/peptide-panapay/assets/payment-icons/visa.svg' );
$mc   = array( 'id' => 'mastercard', 'label' => 'Mastercard', 'url' => 'https://SHOP.example/wp-content/plugins/peptide-panapay/assets/payment-icons/mastercard.svg' );
check( array( $visa, $mc ) === vc_trust_clean_icons( array( $visa, $mc ), $home ), 'usable marks are kept in order, host case ignored' );
check( array( $visa ) === vc_trust_clean_icons( array( $visa, $visa ), $home ), 'duplicates dropped' );
$bad = array(
    array( 'id' => 'evil', 'label' => 'Evil', 'url' => 'https://other.example/x.svg' ),
    array( 'id' => 'script', 'label' => 'S', 'url' => 'javascript:alert(1)' ),
    array( 'id' => 'path', 'label' => 'P', 'url' => 'https://shop.example/a/../b.svg' ),
    array( 'id' => 'ext', 'label' => 'E', 'url' => 'https://shop.example/a/b.php' ),
    array( 'id' => 'q', 'label' => 'Q', 'url' => 'https://shop.example/a b.svg' ),
    array( 'id' => 'Bad Id', 'label' => 'B', 'url' => $visa['url'] ),
    array( 'id' => 'nolabel', 'label' => ' ', 'url' => $visa['url'] ),
    array( 'id' => 'long', 'label' => str_repeat( 'x', 41 ), 'url' => $visa['url'] ),
    array( 'id' => 'arr', 'label' => array( 'x' ), 'url' => $visa['url'] ),
    'visa',
);
check( array() === vc_trust_clean_icons( $bad, $home ), 'other hosts, scripts, odd paths and malformed rows are refused' );
check( array() === vc_trust_clean_icons( null, $home ) && array() === vc_trust_clean_icons( 'x', $home ), 'non-arrays are empty' );
$many = array();
foreach ( range( 1, 12 ) as $i ) { $many[] = array( 'id' => 'card' . $i, 'label' => 'Card ' . $i, 'url' => 'https://shop.example/i/' . $i . '.svg' ); }
check( 9 === count( vc_trust_clean_icons( $many, $home ) ), 'at most nine marks' );

// Markup: nothing when there is nothing true to show; no free text.
check( '' === vc_trust_markup( false, array() ), 'no HTTPS and no marks: nothing' );
$secure_only = vc_trust_markup( true, array() );
check( str_contains( $secure_only, 'Secure checkout' ) && ! str_contains( $secure_only, 'vc-trust__pay' ), 'secure line alone' );
$marks_only = vc_trust_markup( false, array( $visa, $mc ) );
check( ! str_contains( $marks_only, 'Secure checkout' ) && 2 === substr_count( $marks_only, '<img ' ) && str_contains( $marks_only, 'aria-label="Accepted payments: Visa, Mastercard"' ), 'marks alone, named for screen readers' );
$quoted = vc_trust_markup( false, array( array( 'id' => 'x', 'label' => 'A"><script>', 'url' => 'https://shop.example/i/x.svg' ) ) );
check( ! str_contains( $quoted, '<script>' ), 'labels are escaped' );
$both = vc_trust_markup( true, array( $visa ) );
foreach ( array( 'guarantee', 'certified', 'money back', 'FDA', 'GMP', 'verified', 'ships', 'delivery', 'refund' ) as $claim ) {
    check( false === stripos( $both, $claim ), "the strip makes no \"$claim\" claim" );
}
// With the switch on: the secure line follows the store address; no gateway here, so no marks.
check( str_contains( vc_trust_badges(), 'Secure checkout' ) && ! str_contains( vc_trust_badges(), '<img ' ), 'switch on, HTTPS store, no gateway marks' );
$declare = $GLOBALS['filters']['pc_hq_capabilities'][0];
check( array( 'a' => '1', 'm14.trust_badges' => '1' ) === $declare( array( 'a' => '1' ) ) && 'x' === $declare( 'x' ), 'declares the feature to the master admin' );
$dialog = (string) file_get_contents( dirname( __DIR__ ) . '/inc/product-dialog-content.php' );
check( str_contains( $dialog, "\$trust . \$html . \$statement" ) && str_contains( $dialog, "function_exists( 'vc_trust_badges' ) ? vc_trust_badges() : ''" ), 'the dialog prints the strip before the product content' );

echo "PASS {$checks} trust strip checks\n";
