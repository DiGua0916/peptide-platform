<?php
/** Checkout policy contract. In-memory options only; no WordPress bootstrap or data writes. */
declare( strict_types = 1 );
if ( 'cli' !== PHP_SAPI ) { exit( 2 ); }
define( 'ABSPATH', __DIR__ . '/' );
$hooks = array(); $options = array(); $reads = 0; $checks = 0;
function add_filter( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $name ][ $priority ][] = array( $callback, $args ); }
function apply_filters( $name, $value, ...$args ) {
	$callbacks = $GLOBALS['hooks'][ $name ] ?? array(); ksort( $callbacks );
	foreach ( $callbacks as $list ) { foreach ( $list as [ $callback, $accepted ] ) { $value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted ) ); } }
	return $value;
}
function get_option( $name, $default = false ) {
	$pre = apply_filters( 'pre_option_' . $name, false, $name, $default );
	if ( false !== $pre ) { return $pre; }
	++$GLOBALS['reads']; return $GLOBALS['options'][ $name ] ?? $default;
}
function update_option( ...$args ) { throw new RuntimeException( 'Unexpected persisted option mutation' ); }
function phone_check( $name, $pass ) { if ( ! $pass ) { throw new RuntimeException( 'FAIL: ' . $name ); } ++$GLOBALS['checks']; }
require dirname( __DIR__ ) . '/includes/class-checkout-phone.php';
use PeptideCore\Checkout_Phone;
$options = array( 'woocommerce_checkout_phone_field' => 'optional', 'woocommerce_checkout_company_field' => 'optional' );
$before = $options;
phone_check( 'original optional preference is effective before boot', 'optional' === get_option( 'woocommerce_checkout_phone_field' ) );
Checkout_Phone::register_hooks(); Checkout_Phone::register_hooks();
phone_check( 'boot registers one native option policy', 1 === count( $hooks['pre_option_woocommerce_checkout_phone_field'][20] ) );
foreach ( array( 'optional', 'hidden', 'required', '', false, null, array() ) as $stored ) {
	$options['woocommerce_checkout_phone_field'] = $stored;
	phone_check( 'phone is visible and required for a legacy preference', 'required' === get_option( 'woocommerce_checkout_phone_field', 'optional' ) );
}
unset( $options['woocommerce_checkout_phone_field'] );
phone_check( 'absent option does not trigger a read or migration', 'required' === get_option( 'woocommerce_checkout_phone_field' ) );
phone_check( 'unrelated native fields retain their existing policy', 'optional' === get_option( 'woocommerce_checkout_company_field' ) );
$options['woocommerce_checkout_phone_field'] = 'optional';
phone_check( 'registration and reads do not mutate persisted settings', $before == $options );
phone_check( 'visibility accepts WordPress pre-option input without customer data', 'required' === Checkout_Phone::visibility( false ) );
echo json_encode( array( 'passed' => $checks, 'database_writes' => 0, 'scope' => 'Pure policy hook fixture; no real customer or order' ) ) . PHP_EOL;
