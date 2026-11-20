<?php
/**
 * Site 1 P0-5 shipping tiers: three shipping discounts with minimum orders
 * ($150 → $10 off, $300 → $20 off, $600 → free) on a $55 rate pick the lowest shipping.
 * No WordPress bootstrap, database or HTTP.
 */
define( 'ABSPATH', __DIR__ . '/' );
final class WC_Shipping_Rate {
	public array $meta = array();
	public array $taxes = array();
	public function __construct( public string $id, public $cost ) {}
	public function get_cost() { return $this->cost; }
	public function set_cost( $value ) { $this->cost = $value; }
	public function get_taxes() { return $this->taxes; }
	public function set_taxes( $value ) { $this->taxes = $value; }
	public function add_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
}
$options = array();
function get_option( $key, $fallback = false ) { return $GLOBALS['options'][ $key ] ?? $fallback; }
function get_current_user_id() { return 0; }
function WC() { return (object) array( 'customer' => null ); }
function wc_get_price_decimals() { return 2; }
function wc_format_decimal( $value, $decimals ) { return number_format( (float) $value, $decimals, '.', '' ); }
function wp_date( $format, $ts ) { return gmdate( $format, $ts ); }
function __( $text ) { return $text; }
function wc_price( $amount ) { return '$' . number_format( (float) $amount, 2 ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function absint( $v ) { return abs( (int) $v ); }
function wc_format_localized_decimal( $v ) { return (string) $v; }
require dirname( __DIR__ ) . '/includes/shipping/class-discounts.php';
// Customer_Segments is outside this fixture: every shopper matches.
eval( 'namespace PeptideCore; final class Customer_Segments { public static function matches( $d, $e, $u ) { return true; } public static function clean_rule( $raw ) { return array( array(), array() ); } }' );
// Pricing engine (M4): a switch the test flips; "on" hands the same discounts to the engine's real Shipping_Math.
$GLOBALS['engine_on'] = false;
require dirname( __DIR__ ) . '/includes/pricing/class-shipping-math.php';
eval( 'namespace PeptideCore\\Pricing; final class Runtime { public static function on() { return $GLOBALS["engine_on"]; } public static function shipping_applicable() { return \\PeptideCore\\Shipping\\Discounts::applicable(); } }' );
use PeptideCore\Shipping\Discounts;

$base = Discounts::defaults();
$GLOBALS['options'][ Discounts::OPTION ] = array(
	array_merge( $base, array( 'id' => 1, 'name' => 'Orders over $150', 'kind' => 'amount', 'value' => 10, 'min_subtotal' => 150 ) ),
	array_merge( $base, array( 'id' => 2, 'name' => 'Orders over $300', 'kind' => 'amount', 'value' => 20, 'min_subtotal' => 300 ) ),
	array_merge( $base, array( 'id' => 3, 'name' => 'Free shipping over $600', 'kind' => 'percent', 'value' => 100, 'min_subtotal' => 600 ) ),
);
$package = static fn( float $items, float $after_coupon ) => array( 'contents' => array( array( 'line_subtotal' => $items, 'line_total' => $after_coupon ) ) );
$ship = static function ( float $items, float $after_coupon = -1 ) use ( $package ): array {
	$rate = new WC_Shipping_Rate( 'pc_plans:1-1', '55.00' );
	Discounts::apply( array( 'pc_plans:1-1' => $rate ), $package( $items, $after_coupon < 0 ? $items : $after_coupon ) );
	return array( (float) $rate->cost, $rate->meta[ Discounts::META_NAME ] ?? '' );
};
$checks = array();
$check = static function ( string $name, bool $pass ) use ( &$checks ) { $checks[] = compact( 'name', 'pass' ); };

foreach ( array( false => 'engine off', true => 'engine on' ) as $on => $label ) {
	$GLOBALS['engine_on'] = (bool) $on;
	$check( "$label: under \$150 pays the full \$55", array( 55.0, '' ) === $ship( 110 ) );
	$check( "$label: \$150 exactly gets \$10 off", array( 45.0, 'Orders over $150' ) === $ship( 150 ) );
	$check( "$label: \$330 gets \$20 off", array( 35.0, 'Orders over $300' ) === $ship( 330 ) );
	$check( "$label: \$605 ships free", array( 0.0, 'Free shipping over $600' ) === $ship( 605 ) );
	$check( "$label: tier uses the item total before coupons (WELCOME5 does not drop a tier)", array( 35.0, 'Orders over $300' ) === $ship( 310, 294.5 ) );
}
$GLOBALS['engine_on'] = false;
$check( 'rule text names the minimum', array( 'Orders of $150.00 or more: $10.00 off shipping' ) === Discounts::rule_lines( $GLOBALS['options'][ Discounts::OPTION ][0] ) );
[ $clean ] = Discounts::normalize( array( 'name' => 'x', 'value' => 10, 'min_subtotal' => '-5' ) );
$check( 'a negative minimum is stored as no minimum', 0.0 === $clean['min_subtotal'] );
[ $clean ] = Discounts::normalize( array( 'name' => 'x', 'value' => 10, 'min_subtotal' => '149.999' ) );
$check( 'the minimum is rounded to cents', 150.0 === $clean['min_subtotal'] );

$passed = count( array_filter( $checks, static fn( $c ) => $c['pass'] ) );
echo json_encode( array( 'passed' => $passed, 'total' => count( $checks ), 'checks' => $checks ), JSON_PRETTY_PRINT ) . "\n";
exit( $passed === count( $checks ) ? 0 : 1 );
