<?php
/** Actual Unit_Pricing service under bounded in-memory Woo fixtures, never a store DB. */
define( 'ABSPATH', __DIR__ . '/' );
final class WC_Product {
	public string $status = 'publish', $password = '', $price = '55.00';
	public bool $visible = true, $public = true, $purchasable = true, $stock = true, $active = true;
	public array $meta = array(), $attributes = array(), $required = array();
	public function __construct( public int $id, public string $type = 'simple', public int $parent = 0 ) {}
	public function get_id() { return $this->id; }
	public function is_type( $types ) { return in_array( $this->type, (array) $types, true ); }
	public function get_status() { return $this->status; }
	public function get_post_password() { return $this->password; }
	public function is_purchasable() { return $this->purchasable; }
	public function is_in_stock() { return $this->stock; }
	public function is_publicly_viewable() { return $this->public; }
	public function is_visible() { return $this->visible; }
	public function get_parent_id() { return $this->parent; }
	public function variation_is_active() { return $this->active; }
	public function variation_is_visible() { return $this->visible; }
	public function get_variation_attributes() { return 'variable' === $this->type ? $this->required : $this->attributes; }
	public function get_attribute( $key ) { return $this->attributes[ $key ] ?? ''; }
	public function get_meta( $key, ...$args ) { return $this->meta[ $key ] ?? ''; }
	public function get_price() { return $this->price; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
}
final class WC_Admin_Meta_Boxes { public static array $errors = array(); public static function add_error( $message ) { self::$errors[] = $message; } }
$products = array(); $decimals = 2; $currency = 'USD'; $tax = array( 'shop' => 'excl', 'cart' => 'excl' ); $hooks = array(); $fields = array();
function wc_get_product( $id ) { global $products; return $products[ $id ] ?? null; }
function wc_get_price_decimals() { global $decimals; return $decimals; }
function get_woocommerce_currency() { global $currency; return $currency; }
function wc_variation_attribute_name( $name ) { return 'attribute_' . $name; }
function get_option( $key, $default = null ) { global $tax; return $tax[ str_replace( 'woocommerce_tax_display_', '', $key ) ] ?? $default; }
function wc_get_price_to_display( $product, $args ) { global $tax; if ( ! empty( $GLOBALS['throw_tax'] ) ) { throw new RuntimeException( 'Synthetic unavailable tax extension' ); } return $args['price'] * ( 'incl' === $tax[ $args['display_context'] ] ? 1.2 : 1 ); }
function wc_price( $value ) { global $decimals, $currency; return '<span>' . $currency . ' ' . number_format( $value, $decimals, '.', '' ) . '</span>'; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function add_action( ...$args ) { global $hooks; $hooks[] = $args; }
function add_filter( ...$args ) { global $hooks; $hooks[] = $args; }
function woocommerce_wp_text_input( $args ) { global $fields; $fields[] = $args; }
require dirname( __DIR__ ) . '/includes/class-meta.php';
require dirname( __DIR__ ) . '/includes/class-unit-pricing.php';
use PeptideCore\Unit_Pricing as Unit;
$checks = array();
$check = static function( string $name, bool $pass ) use ( &$checks ) { $checks[] = compact( 'name', 'pass' ); };
foreach ( array( '20mg*10vials' => 10, '2.5 mg × 5 vials / box' => 5, '1ml/vial*10bottles/pack' => 10, '10瓶/盒' => 10, '1 vial' => 1, '10000vials' => 10000 ) as $pack => $count ) { $check( 'recognize complete pack ' . $pack, $count === Unit::parse_pack( $pack ) ); }
foreach ( array( '10mg', '10ml', '20mg', '', '0vials', '10001vials', '10vials + 5vials', '10vials/box|20vials/box', '10mg*10', '20mg (10 vials)', 'vials 10', '<b>10vials</b>', str_repeat( ' ', 200 ) . '10vials', '10vials and 2ml water' ) as $pack ) { $check( 'reject ambiguous pack ' . substr( $pack, 0, 40 ), null === Unit::parse_pack( $pack ) ); }
foreach ( array( '', -1, 10001, true, false, 1.5, '1.0', '01', '1e3', ' 10 ', array( 10 ) ) as $i => $count ) { $check( 'reject malformed count ' . $i, null === Unit::normalize_count( $count ) ); }
$simple = new WC_Product( 10 ); $products[10] = $simple;
$check( 'unknown packaging has no price', null === Unit::for_product( $simple ) );
$simple->meta[ PeptideCore\Meta::PRODUCT_SPECS ] = array( array( 'key' => 'pack', 'value' => '20mg*10vials' ) );
$dto = Unit::for_product( $simple );
$check( 'existing imported pack needs no migration', $dto['vialsPerSalesUnit'] === 10 && 'stored_pack' === $dto['countSource'] );
$check( 'same SKU box and vial price', $dto['salesUnitPrice']['minor'] === '5500' && $dto['perVialPrice']['minor'] === '550' && $dto['perVialPrice']['text'] === 'USD 5.50' );
$check( 'reference is not split-box purchase', false === $dto['isPurchasableAsSingleVial'] && false === $dto['includesCoupons'] && false === $dto['approximate'] );
$simple->meta[Unit::META] = '5'; $check( 'explicit SKU count overrides stored pack', 5 === Unit::for_product( $simple )['vialsPerSalesUnit'] );
$simple->meta[Unit::META] = '0'; $check( 'explicit zero hides rather than deriving', null === Unit::for_product( $simple ) );
$simple->meta[Unit::META] = 'bad'; $check( 'corrupt own metadata does not fall back', null === Unit::for_product( $simple ) );
unset( $simple->meta[Unit::META] );
$simple->attributes['pack'] = '10vials + 5vials'; $check( 'ambiguous packaging defeats other fallback', null === Unit::for_product( $simple ) );
$simple->attributes['pack'] = '5vials'; $check( 'conflicting explicit stored packs hidden', null === Unit::for_product( $simple ) );
unset( $simple->attributes['pack'] );
$simple->meta[Unit::META] = '3'; $simple->price = '10.00'; $dto = Unit::for_product( $simple );
$check( 'round recurring cents but mark approximation', '333' === $dto['perVialPrice']['minor'] && true === $dto['approximate'] );
$simple->price = '10.01'; $check( 'round half up independently from box charge', '334' === Unit::for_product( $simple )['perVialPrice']['minor'] );
$simple->meta[Unit::META] = '10'; $simple->price = '55'; $tax['shop'] = 'incl'; $dto = Unit::for_product( $simple );
$check( 'shop includes tax according to Woo context', '6600' === $dto['salesUnitPrice']['minor'] && '660' === $dto['perVialPrice']['minor'] && 'incl' === $dto['taxDisplay'] );
$dto = Unit::for_product( $simple, 'cart' ); $check( 'cart uses its own tax display setting', '5500' === $dto['salesUnitPrice']['minor'] && 'excl' === $dto['taxDisplay'] && 'cart_display_price' === $dto['basis'] );
$check( 'unknown display context rejected', null === Unit::for_product( $simple, 'other' ) );
$GLOBALS['throw_tax'] = true; $check( 'a display-price extension failure cannot break checkout', null === Unit::for_product( $simple ) ); unset( $GLOBALS['throw_tax'] );
$tax['shop'] = 'excl'; $decimals = 0; $currency = 'JPY'; $simple->price = '55'; $dto = Unit::for_product( $simple );
$check( 'zero-decimal currency has no phantom cents', 0 === $dto['currencyMinorUnit'] && 'JPY' === $dto['currency'] && '6' === $dto['perVialPrice']['minor'] && 'JPY 6' === $dto['perVialPrice']['text'] && $dto['approximate'] );
$decimals = 3; $currency = 'KWD'; $simple->price = '1.235'; $simple->meta[Unit::META] = '5'; $dto = Unit::for_product( $simple );
$check( 'three-decimal currency retained', '1235' === $dto['salesUnitPrice']['minor'] && '247' === $dto['perVialPrice']['minor'] && 'KWD 0.247' === $dto['perVialPrice']['text'] );
$decimals = 2; $currency = 'USD';
foreach ( array( '', '-1', 'INF', 'NAN', '1e20' ) as $bad ) { $simple->price = $bad; $check( 'bad or unsafe price hidden ' . $bad, null === Unit::for_product( $simple ) ); }
$simple->price = '0'; $check( 'genuine zero price remains zero', '0' === Unit::for_product( $simple )['perVialPrice']['minor'] );
$simple->price = '55';
foreach ( array( -1, 9 ) as $bad ) { $decimals = $bad; $check( 'invalid currency precision ' . $bad, null === Unit::for_product( $simple ) ); } $decimals = 2;
foreach ( array( 'status' => 'draft', 'password' => 'secret', 'visible' => false, 'public' => false, 'purchasable' => false, 'stock' => false ) as $key => $bad ) { $old = $simple->$key; $simple->$key = $bad; $check( 'unavailable product hidden ' . $key, null === Unit::for_product( $simple ) ); $simple->$key = $old; }
$parent = new WC_Product( 20, 'variable' ); $parent->required = array( 'pack' => array( '20mg*10vials' ) ); $parent->meta[Unit::META] = '5'; $products[20] = $parent;
$variant = new WC_Product( 21, 'variation', 20 ); $variant->attributes = array( 'attribute_pack' => '20mg*10vials' ); $products[21] = $variant;
$check( 'variable parent does not guess cheapest SKU', null === Unit::for_product( $parent ) );
$dto = Unit::for_product( $variant ); $check( 'selected variation identities and SKU packaging', 20 === $dto['productId'] && 21 === $dto['variationId'] && 21 === $dto['purchasableId'] && 10 === $dto['vialsPerSalesUnit'] );
$variant->meta[Unit::META] = '2'; $check( 'variation explicit override strongest', 2 === Unit::for_product( $variant )['vialsPerSalesUnit'] ); unset( $variant->meta[Unit::META] );
$variant->attributes = array( 'attribute_pack' => '' ); $check( 'any variation is not a selected SKU', null === Unit::for_product( $variant ) );
$parent->required = array( 'strength' => array( '20mg' ) ); $variant->attributes = array( 'attribute_strength' => '20mg' );
$dto = Unit::for_product( $variant ); $check( 'parent fallback only when SKU has no pack', 5 === $dto['vialsPerSalesUnit'] && 'explicit_parent' === $dto['countSource'] );
$parent->meta[Unit::META] = '0'; $check( 'parent zero stops missing-package display', null === Unit::for_product( $variant ) ); $parent->meta[Unit::META] = '5';
$parent->visible = false; $check( 'hidden parent cannot leak child price', null === Unit::for_product( $variant ) ); $parent->visible = true;
$variant->active = false; $check( 'inactive variation hidden', null === Unit::for_product( $variant ) ); $variant->active = true;
$native = Unit::variation_data( array( 'variation_id' => 21, 'display_price' => 55 ), $parent, $variant );
$check( 'native variation DTO additive only', $native['variation_id'] === 21 && $native['display_price'] === 55 && $native['pc_unit_pricing']['vialsPerSalesUnit'] === 5 );
$_POST = array( 'pc_vials_per_sales_unit' => '12' ); Unit::save_product_field( $simple ); $check( 'native admin save stores one validated metadata value', '12' === $simple->meta[Unit::META] && '55' === $simple->price );
$_POST['pc_vials_per_sales_unit'] = '1.5'; Unit::save_product_field( $simple ); $check( 'invalid save retains old count and emits notice', '12' === $simple->meta[Unit::META] && 1 === count( WC_Admin_Meta_Boxes::$errors ) );
$_POST['pc_vials_per_sales_unit'] = array( '10' ); Unit::save_product_field( $simple ); $check( 'array injection retains old count', '12' === $simple->meta[Unit::META] && 2 === count( WC_Admin_Meta_Boxes::$errors ) );
$_POST = array(); Unit::save_product_field( $simple ); $check( 'unrelated product save preserves count', '12' === $simple->meta[Unit::META] );
$_POST = array( 'pc_vials_per_sales_unit' => '' ); Unit::save_product_field( $simple ); $check( 'explicit empty removes only own override', ! isset( $simple->meta[Unit::META] ) && isset( $simple->meta[PeptideCore\Meta::PRODUCT_SPECS] ) );
$_POST = array( 'pc_vials_per_sales_unit_variation' => array( 3 => '0' ) ); Unit::save_variation_field( $variant, 3 ); $check( 'variation admin saves explicit hide', '0' === $variant->meta[Unit::META] && null === Unit::for_product( $variant ) );
$product_object = $simple; Unit::render_product_field(); Unit::render_variation_field( 3, array(), (object) array( 'ID' => 21 ) );
$check( 'admin fields retain native semantics and bounded integers', count( $fields ) === 2 && 'pc_vials_per_sales_unit_variation[3]' === $fields[1]['name'] && '10000' === $fields[0]['custom_attributes']['max'] );
Unit::register_hooks(); $check( 'only native admin and read-only variation hooks registered', count( $hooks ) === 5 && 'woocommerce_available_variation' === $hooks[4][0] );
$passed = count( array_filter( $checks, static fn( $row ) => $row['pass'] ) );
echo json_encode( array( 'kind' => 'Actual Unit_Pricing pure fixtures, no database or price mutations', 'passed' => $passed, 'total' => count( $checks ), 'checks' => $checks ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . PHP_EOL;
exit( $passed === count( $checks ) ? 0 : 1 );
