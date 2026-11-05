<?php
/** Strict shipping trace fixture checks. No WordPress bootstrap, database or HTTP. */
define( 'ABSPATH', __DIR__ . '/' );
final class WC_Cart {
	public bool $needs = true, $calculated = true;
	public $total = '40.00';
	public function needs_shipping() { return $this->needs; }
	public function has_calculated_shipping() { return $this->calculated; }
	public function get_shipping_total() { return $this->total; }
	public function calculate_shipping() { throw new RuntimeException( 'Must not calculate shipping' ); }
	public function calculate_totals() { throw new RuntimeException( 'Must not calculate totals' ); }
}
final class WC_Shipping_Rate {
	public function __construct( public string $id, public $cost, public array $meta = array() ) {}
	public function get_id() { return $this->id; }
	public function get_cost() { return $this->cost; }
	public function get_meta_data() { return $this->meta; }
	public function set_cost( $value ) { throw new RuntimeException( 'Must not change shipping cost' ); }
	public function set_taxes( $value ) { throw new RuntimeException( 'Must not change shipping taxes' ); }
}
final class TraceSession {
	public array $chosen = array( 'pc_plans:1-2' );
	public function get( $key, $fallback = null ) { if ( 'chosen_shipping_methods' !== $key ) { throw new RuntimeException( 'Unexpected session read' ); } return $this->chosen; }
	public function set( ...$args ) { throw new RuntimeException( 'Must not write session' ); }
}
final class TraceShipping {
	public array $packages = array();
	public bool $throws = false;
	public function get_packages() { if ( $this->throws ) { throw new RuntimeException( 'Unavailable shipping provider' ); } return $this->packages; }
	public function calculate_shipping( ...$args ) { throw new RuntimeException( 'Must not recalculate packages' ); }
}
final class TraceWoo {
	public ?TraceSession $session;
	public TraceShipping $shipping;
	public function __construct() { $this->session = new TraceSession(); $this->shipping = new TraceShipping(); }
	public function shipping() { return $this->shipping; }
}
$woo = new TraceWoo(); $decimals = 2; $currency = 'USD';
function WC() { return $GLOBALS['woo']; }
function wc_get_price_decimals() { return $GLOBALS['decimals']; }
function get_woocommerce_currency() { return $GLOBALS['currency']; }
function wc_format_decimal( $value, $decimals, $trim ) { return number_format( (float) $value, $decimals, '.', '' ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
require dirname( __DIR__ ) . '/includes/shipping/class-discounts.php';
require dirname( __DIR__ ) . '/includes/shipping/class-savings-trace.php';
use PeptideCore\Shipping\Discounts;
use PeptideCore\Shipping\Savings_Trace;
$checks = array();
$check = static function( string $name, bool $pass ) use ( &$checks ) { $checks[] = compact( 'name', 'pass' ); };
$make = static function( string $id = 'pc_plans:1-2', $original = '45.00', $payable = '40.00', $discount_id = '10', $name = 'Delivery offer' ) {
	return new WC_Shipping_Rate( $id, $payable, array( Discounts::META_COST => $original, Discounts::META_NAME => $name, Discounts::META_ID => $discount_id, Discounts::META_PAYABLE => $payable, '_private_customer_email' => 'never-return@example.invalid' ) );
};
$reset = static function() use ( $make ) {
	$GLOBALS['woo'] = new TraceWoo(); $GLOBALS['decimals'] = 2; $GLOBALS['currency'] = 'USD';
	WC()->shipping->packages = array( array( 'package_id' => 0, 'rates' => array( 'pc_plans:1-2' => $make() ), 'destination' => array( 'address_1' => 'Do not publish' ), 'contents' => array( 'private' => 'Do not publish' ) ) );
	return new WC_Cart();
};
$cart = $reset(); $before = serialize( array( $cart, WC() ) ); $dto = Savings_Trace::for_cart( $cart );
$check( 'exact one-package trace shape and minor unit amounts', array( 'version' => 1, 'status' => 'applied', 'complete' => true, 'tax_basis' => 'exclusive', 'currency_code' => 'USD', 'currency_minor_unit' => 2, 'original_shipping' => '4500', 'shipping_discount' => '500', 'payable_shipping' => '4000', 'packages' => array( array( 'package_id' => 0, 'rate_id' => 'pc_plans:1-2', 'original_shipping' => '4500', 'shipping_discount' => '500', 'payable_shipping' => '4000', 'discount_id' => 10, 'discount_name' => 'Delivery offer' ) ) ) === $dto );
$check( 'all cart/session/rate state remains byte identical', $before === serialize( array( $cart, WC() ) ) );
$check( 'allowlisted DTO excludes addresses and private metadata', ! str_contains( json_encode( $dto ), 'Do not publish' ) && ! str_contains( json_encode( $dto ), 'never-return' ) );
$cart = $reset(); WC()->shipping->packages[1] = array( 'package_id' => 1, 'rates' => array( 'flat:2' => new WC_Shipping_Rate( 'flat:2', '10.00' ) ) ); WC()->session->chosen[1] = 'flat:2'; $cart->total = '50'; $dto = Savings_Trace::for_cart( $cart );
$check( 'undiscounted selected packages included in complete coverage', '5500' === $dto['original_shipping'] && '5000' === $dto['payable_shipping'] && '500' === $dto['shipping_discount'] && '0' === $dto['packages'][1]['shipping_discount'] && '1000' === $dto['packages'][1]['original_shipping'] );
$cart = $reset(); WC()->shipping->packages[1] = array( 'package_id' => 1, 'rates' => array( 'flat:2' => $make( 'flat:2', '20', '15' ) ) ); WC()->session->chosen[1] = 'flat:2'; $cart->total = '55'; $dto = Savings_Trace::for_cart( $cart );
$check( 'same real discount may apply to multiple packages once each', '1000' === $dto['shipping_discount'] && count( $dto['packages'] ) === 2 && 10 === $dto['packages'][1]['discount_id'] );
WC()->shipping->packages[1]['rates']['flat:2']->meta[Discounts::META_NAME] = 'Different name';
$check( 'same discount ID with inconsistent names rejected', null === Savings_Trace::for_cart( $cart ) );
$cart = $reset(); WC()->shipping->packages[0]['rates']['cheap'] = new WC_Shipping_Rate( 'cheap', '1' );
$check( 'trace uses chosen rate not cheaper unselected candidate', '4000' === Savings_Trace::for_cart( $cart )['payable_shipping'] );
foreach ( array( 'needs', 'calculated' ) as $key ) { $cart = $reset(); $cart->$key = false; $check( 'no trace when ' . $key . ' false', null === Savings_Trace::for_cart( $cart ) ); }
foreach ( array( null, new stdClass() ) as $cart ) { $check( 'non-native cart rejected', null === Savings_Trace::for_cart( $cart ) ); }
$cart = $reset(); WC()->session = null; $check( 'no session is unknown not estimated', null === Savings_Trace::for_cart( $cart ) );
$cart = $reset(); WC()->session->chosen = array(); $check( 'no chosen rate does not trigger Woo default selection', null === Savings_Trace::for_cart( $cart ) );
$cart = $reset(); WC()->session->chosen = array( 'stale-rate' ); $check( 'stale chosen rate rejected', null === Savings_Trace::for_cart( $cart ) );
$cart = $reset(); WC()->shipping->packages[0]['rates']['pc_plans:1-2']->id = 'other'; $check( 'chosen map key and native rate ID must agree', null === Savings_Trace::for_cart( $cart ) );
foreach ( array( Discounts::META_COST, Discounts::META_NAME, Discounts::META_ID, Discounts::META_PAYABLE ) as $key ) { $cart = $reset(); unset( WC()->shipping->packages[0]['rates']['pc_plans:1-2']->meta[$key] ); $check( 'partial or legacy provenance rejected: ' . $key, null === Savings_Trace::for_cart( $cart ) ); }
$cart = $reset(); WC()->shipping->packages[0]['rates']['pc_plans:1-2']->cost = '39'; $cart->total = '39'; $check( 'downstream mutation invalidates recorded payable', null === Savings_Trace::for_cart( $cart ) );
$cart = $reset(); $cart->total = '41'; $check( 'cart shipping total must reconcile all packages', null === Savings_Trace::for_cart( $cart ) );
$cart = $reset(); WC()->shipping->packages[1] = array( 'package_id' => 1, 'rates' => array( 'flat:2' => $make( 'flat:2', '20', '15' ) ) ); $check( 'partial chosen package coverage rejected', null === Savings_Trace::for_cart( $cart ) );
$cart = $reset(); WC()->shipping->packages = array(); $check( 'no packages rejected', null === Savings_Trace::for_cart( $cart ) );
$cart = $reset(); WC()->shipping->packages = array_fill( 0, 101, WC()->shipping->packages[0] ); $check( 'excessive package count bounded', null === Savings_Trace::for_cart( $cart ) );
$cart = $reset(); WC()->shipping->packages[0]['rates']['pc_plans:1-2']->meta = array(); $check( 'no promotion does not fabricate zero savings DTO', null === Savings_Trace::for_cart( $cart ) );
$cart = $reset(); WC()->shipping->packages[0]['rates']['pc_plans:1-2'] = $make( 'pc_plans:1-2', '40', '40' ); $check( 'provenance without positive saving rejected', null === Savings_Trace::for_cart( $cart ) );
$cart = $reset(); WC()->shipping->packages[0]['rates']['pc_plans:1-2'] = $make( 'pc_plans:1-2', '30', '40' ); $check( 'negative reduction rejected', null === Savings_Trace::for_cart( $cart ) );
$cart = $reset(); WC()->shipping->packages[0]['rates']['pc_plans:1-2'] = $make( 'pc_plans:1-2', '45', '0' ); $cart->total = '0'; $dto = Savings_Trace::for_cart( $cart ); $check( 'genuine free shipping retains actual pre-discount value', '4500' === $dto['shipping_discount'] && '0' === $dto['payable_shipping'] );
foreach ( array( null, true, array(), '', '-1', 'NaN', 'INF', '1e8', ' 45', '45,00', INF, NAN, 1e20 ) as $index => $bad ) { $cart = $reset(); WC()->shipping->packages[0]['rates']['pc_plans:1-2']->meta[Discounts::META_COST] = $bad; $check( 'malformed or unsafe original amount ' . $index, null === Savings_Trace::for_cart( $cart ) ); }
foreach ( array( null, true, array(), 0, -1, '01', '1.0', '1e2', ' 10', '9007199254740992' ) as $index => $bad ) { $cart = $reset(); WC()->shipping->packages[0]['rates']['pc_plans:1-2']->meta[Discounts::META_ID] = $bad; $check( 'invalid stable discount ID ' . $index, null === Savings_Trace::for_cart( $cart ) ); }
foreach ( array( null, true, '', ' ', '<b></b>', str_repeat( 'a', 501 ) ) as $index => $bad ) { $cart = $reset(); WC()->shipping->packages[0]['rates']['pc_plans:1-2']->meta[Discounts::META_NAME] = $bad; $check( 'invalid public discount name ' . $index, null === Savings_Trace::for_cart( $cart ) ); }
$cart = $reset(); WC()->shipping->packages[0]['rates']['pc_plans:1-2']->meta[Discounts::META_NAME] = '<b>Delivery offer</b>'; $check( 'public name is plain text', 'Delivery offer' === Savings_Trace::for_cart( $cart )['packages'][0]['discount_name'] );
foreach ( array( -1, 9, '2', true ) as $bad ) { $cart = $reset(); $GLOBALS['decimals'] = $bad; $check( 'invalid currency precision ' . json_encode( $bad ), null === Savings_Trace::for_cart( $cart ) ); }
foreach ( array( '', 'usd', 'USDD', '<b>USD</b>' ) as $bad ) { $cart = $reset(); $GLOBALS['currency'] = $bad; $check( 'invalid currency code ' . $bad, null === Savings_Trace::for_cart( $cart ) ); }
$cart = $reset(); $GLOBALS['decimals'] = 0; $GLOBALS['currency'] = 'JPY'; $cart->total = '40'; $dto = Savings_Trace::for_cart( $cart ); $check( 'zero decimal money matches Store API units', '45' === $dto['original_shipping'] && '5' === $dto['shipping_discount'] && 0 === $dto['currency_minor_unit'] );
$cart = $reset(); $GLOBALS['decimals'] = 3; $GLOBALS['currency'] = 'KWD'; WC()->shipping->packages[0]['rates']['pc_plans:1-2'] = $make( 'pc_plans:1-2', '1.235', '1.230' ); $cart->total = '1.230'; $dto = Savings_Trace::for_cart( $cart ); $check( 'three decimal native money preserved', '1235' === $dto['original_shipping'] && '5' === $dto['shipping_discount'] && '1230' === $dto['payable_shipping'] );
$cart = $reset(); WC()->shipping->packages[0]['rates']['pc_plans:1-2'] = $make( 'pc_plans:1-2', '1.005', '0.995' ); $cart->total = '0.995'; $dto = Savings_Trace::for_cart( $cart ); $check( 'round each native monetary field half up before reconciling', '101' === $dto['original_shipping'] && '100' === $dto['payable_shipping'] && '1' === $dto['shipping_discount'] );
$cart = $reset(); WC()->shipping->packages['parcel-a'] = WC()->shipping->packages[0]; WC()->shipping->packages['parcel-a']['package_id'] = 'parcel-a'; unset( WC()->shipping->packages[0] ); WC()->session->chosen = array( 'parcel-a' => 'pc_plans:1-2' ); $check( 'valid named package key retained', 'parcel-a' === Savings_Trace::for_cart( $cart )['packages'][0]['package_id'] );
$cart = $reset(); WC()->shipping->packages[0]['package_id'] = 9; WC()->session->chosen = array( 9 => 'pc_plans:1-2' ); $check( 'explicit native package ID takes precedence over array index', 9 === Savings_Trace::for_cart( $cart )['packages'][0]['package_id'] );
$cart = $reset(); WC()->shipping->packages[1] = WC()->shipping->packages[0]; $cart->total = '80'; $check( 'duplicate native package IDs rejected', null === Savings_Trace::for_cart( $cart ) );
$cart = $reset(); unset( WC()->shipping->packages[0]['package_id'] ); $check( 'missing native package identity is not inferred', null === Savings_Trace::for_cart( $cart ) );
foreach ( array( null, true, -1, 1.5, 9007199254740992, '', str_repeat( 'x', 101 ), "\xff" ) as $index => $bad ) { $cart = $reset(); WC()->shipping->packages[0]['package_id'] = $bad; $check( 'invalid package identity ' . $index, null === Savings_Trace::for_cart( $cart ) ); }
$cart = $reset(); WC()->shipping->throws = true; $check( 'provider exception cannot interrupt checkout', null === Savings_Trace::for_cart( $cart ) );
echo json_encode( array( 'passed' => count( array_filter( $checks, static fn( $row ) => $row['pass'] ) ), 'total' => count( $checks ), 'checks' => $checks ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
exit( array_filter( $checks, static fn( $row ) => ! $row['pass'] ) ? 1 : 0 );
