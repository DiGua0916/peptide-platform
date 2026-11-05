<?php
/**
 * Canonical selected-SKU pricing regression using the real services.
 *
 * Run with PHP only; this deliberately never boots WordPress, reads a store DB,
 * creates an order, or writes a product. Fixtures mirror the public TR20/TR15
 * identities and prices observed on 2026-11-05, not private customer data.
 */
define( 'ABSPATH', __DIR__ . '/' );

final class WC_Product {

	public array $meta = array(), $children = array();

	public function __construct(
		public int $id,
		public string $type,
		public int $parent,
		public string $price,
		public string $regular,
		public bool $sale = false,
		public bool $purchasable = true
	) {}

	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent; }
	public function is_type( $type ) { return $this->type === $type; }
	public function get_price() { return $this->price; }
	public function get_regular_price() { return $this->regular; }
	public function is_on_sale() { return $this->sale; }
	public function is_purchasable() { return $this->purchasable; }
	public function get_visible_children() { return $this->children; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function save() { throw new RuntimeException( 'A read-only pricing test attempted a product write.' ); }
}

$products = array();
$categories = array();
$currency = 'USD';
$decimals = 2;
$shop_tax = 'excl';

function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? null; }
function wc_get_product_cat_ids( $id ) { return $GLOBALS['categories'][ $id ] ?? array(); }
function wc_format_decimal( $value, $dp = false ) { return false === $dp ? (string) (float) $value : number_format( (float) $value, $dp, '.', '' ); }
function wc_get_price_decimals() { return $GLOBALS['decimals']; }
function get_woocommerce_currency() { return $GLOBALS['currency']; }
function get_option( $key, $default = false ) { return 'woocommerce_tax_display_shop' === $key ? $GLOBALS['shop_tax'] : $default; }
function get_transient( ...$args ) { throw new RuntimeException( 'Canonical pair unexpectedly depends on a transient.' ); }
function set_transient( ...$args ) { throw new RuntimeException( 'Canonical pair attempted to persist cached data.' ); }
function update_option( ...$args ) { throw new RuntimeException( 'Read-only pricing attempted an option write.' ); }

require dirname( __DIR__ ) . '/includes/class-pricing.php';
require dirname( __DIR__ ) . '/includes/class-promotions.php';

use PeptideCore\Pricing;
use PeptideCore\Promotions;

$checks = array();
$check = static function ( string $name, bool $pass ) use ( &$checks ): void {
	$checks[] = compact( 'name', 'pass' );
};
$expect = static function ( string $name, array $actual, array $expected ) use ( $check ): void {
	foreach ( $expected as $key => $value ) {
		$check( $name . ': ' . $key, array_key_exists( $key, $actual ) && $actual[ $key ] === $value );
	}
};

$parent = new WC_Product( 11306, 'variable', 0, '40', '40', true );
$tr20 = new WC_Product( 11307, 'variation', 11306, '55', '78', true );
$tr10 = new WC_Product( 11308, 'variation', 11306, '40', '40' );
$tr15 = new WC_Product( 11309, 'variation', 11306, '50', '50' );
$parent->children = array( 11307, 11308, 11309 );
foreach ( array( $parent, $tr20, $tr10, $tr15 ) as $product ) {
	$products[ $product->id ] = $product;
}

$sale_pair = array(
	'product_id' => 11306, 'variation_id' => 11307,
	'price' => '5500', 'regular_price' => '7800', 'on_sale' => true,
	'savings' => '2300', 'percent_off' => 29,
	'compare_source' => 'manual', 'compare_is_demo' => false,
);
$no_sale_pair = array(
	'product_id' => 11306, 'variation_id' => 11309,
	'price' => '5000', 'regular_price' => '5000', 'on_sale' => false,
	'savings' => '0', 'percent_off' => 0,
	'compare_source' => null, 'compare_is_demo' => false,
);

// Repeated A -> B -> A calls must provide explicit clearing values for B.
$initial_tr20 = Pricing::pair( $tr20 );
$expect( 'TR20 initial discounted SKU', $initial_tr20, $sale_pair );
$expect( 'TR15 after discounted sibling', Pricing::pair( $tr15 ), $no_sale_pair );
$returned_tr20 = Pricing::pair( $tr20 );
$expect( 'TR20 after returning from full-price sibling', $returned_tr20, $sale_pair );
$check( 'interleaved reads preserve the original SKU snapshot', $initial_tr20 === $returned_tr20 );

// Old management metadata is not an authority for current sale state.
$tr15->meta[ Pricing::META ] = array( 'base' => '50', 'compare' => '100.02', 'source' => Pricing::SOURCE_DEMO );
$expect( 'non-sale clears even historical demo comparison', Pricing::pair( $tr15 ), $no_sale_pair );
$tr20->sale = false;
$expect( 'native non-sale state wins over a larger regular field', Pricing::pair( $tr20 ), array(
	'variation_id' => 11307, 'price' => '5500', 'regular_price' => '5500',
	'on_sale' => false, 'savings' => '0', 'percent_off' => 0, 'compare_source' => null,
) );
$tr20->sale = true;

// Parent aggregation cannot be used as the selected variation's sale flag.
$expect( 'parent card selects the cheapest child and its own comparison', Pricing::card_pair( $parent ), array(
	'product_id' => 11306, 'variation_id' => 11308,
	'price' => '4000', 'regular_price' => '4000', 'on_sale' => false,
	'savings' => '0', 'percent_off' => 0,
) );
$check( 'aggregate parent on-sale does not imply selected TR15 is discounted', $parent->is_on_sale() && ! Pricing::pair( $tr15 )['on_sale'] );
$tr10->purchasable = false;
$expect( 'unavailable cheapest child is skipped', Pricing::card_pair( $parent ), array( 'variation_id' => 11309, 'price' => '5000', 'percent_off' => 0 ) );
$tr10->purchasable = true;
$parent->children = array( 999999 );
$check( 'no eligible child gives null instead of another cached SKU', null === Pricing::card_pair( $parent ) );
$parent->children = array( 11307, 11308, 11309 );

$expect( 'cart uses exact TR15 rather than sibling or parent', Pricing::cart_line( array( 'data' => $tr15, 'quantity' => 3 ) ), array(
	'variation_id' => 11309, 'quantity' => 3,
	'regular_subtotal' => '15000', 'sale_subtotal' => '15000', 'sale_savings' => '0', 'percent_off' => 0,
) );
$expect( 'cart uses exact TR20 quantity without coupon estimates', Pricing::cart_line( array( 'data' => $tr20, 'quantity' => 2 ) ), array(
	'variation_id' => 11307, 'regular_subtotal' => '15600', 'sale_subtotal' => '11000', 'sale_savings' => '4600', 'percent_off' => 29,
) );

// No request-local memo can leak currency precision/tax settings between reads.
$currency = 'JPY'; $decimals = 0; $shop_tax = 'incl';
$expect( 'fresh zero-decimal context', Pricing::pair( $tr15 ), array(
	'variation_id' => 11309, 'price' => '50', 'currency_code' => 'JPY', 'currency_minor_unit' => 0, 'tax_display' => 'incl',
) );
$currency = 'KWD'; $decimals = 3; $shop_tax = 'excl';
$expect( 'fresh three-decimal context', Pricing::pair( $tr15 ), array(
	'variation_id' => 11309, 'price' => '50000', 'currency_code' => 'KWD', 'currency_minor_unit' => 3, 'tax_display' => 'excl',
) );
$currency = 'USD'; $decimals = 2;
$expect( 'restored USD has no stale context', Pricing::pair( $tr15 ), $no_sale_pair + array( 'currency_code' => 'USD', 'currency_minor_unit' => 2 ) );

// A cart campaign's scope is separate from native SKU compare-price state.
$scope = array( 'scope' => 'products', 'products' => array( 11306 ), 'categories' => array(), 'exclude_sale' => false );
$check( 'parent-targeted campaign covers discounted child', Promotions::covers( $scope, $tr20 ) );
$check( 'parent-targeted campaign also covers non-sale child', Promotions::covers( $scope, $tr15 ) );
$expect( 'campaign coverage leaves the native non-sale price pair intact', Pricing::pair( $tr15 ), $no_sale_pair );
$scope['products'] = array( 11307 );
$check( 'exact-variation campaign covers that SKU', Promotions::covers( $scope, $tr20 ) );
$check( 'exact-variation campaign does not cover a sibling', ! Promotions::covers( $scope, $tr15 ) );
$scope['products'] = array( 11306 ); $scope['exclude_sale'] = true;
$check( 'exclude_sale checks discounted SKU', ! Promotions::covers( $scope, $tr20 ) );
$check( 'exclude_sale retains non-sale sibling despite parent aggregate sale', Promotions::covers( $scope, $tr15 ) );
$categories[11306] = array( 353 );
$scope['scope'] = 'categories'; $scope['categories'] = array( 353 );
$check( 'category campaign resolves through the selected SKU parent', Promotions::covers( $scope, $tr15 ) );

$passed = count( array_filter( $checks, static fn ( $row ) => $row['pass'] ) );
echo json_encode( array(
	'kind' => 'Actual Pricing and Promotions selected-SKU read contracts; in-memory fixtures, no WordPress bootstrap or database',
	'passed' => $passed, 'total' => count( $checks ), 'checks' => $checks,
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . PHP_EOL;
exit( $passed === count( $checks ) ? 0 : 1 );
