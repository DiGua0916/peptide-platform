<?php
/**
 * Public product-data helper against actual Core services, without WP bootstrap.
 * Woo/posts/options below are finite memory fixtures. Store_Api, Pricing,
 * Unit_Pricing, Catalog, Products and public field readers are not DTO stubs.
 * Null is a fail-closed result, not permission to retry a REST route.
 */
define( 'ABSPATH', __DIR__ . '/' );

class WC_Product {
	public string $status = 'publish', $password = '', $price = '20', $regular = '25';
	public bool $public = true, $visible = true, $purchasable = true, $stock = true, $sale = true;
	public array $meta = array(), $children = array(), $required = array(), $attributes = array();
	public string $throws = '';
	public function __construct( public int $id, public string $type = 'simple', public int $parent = 0 ) {}
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent; }
	public function is_type( $type ) { return in_array( $this->type, (array) $type, true ); }
	public function get_status() { if ( 'status' === $this->throws ) { throw new RuntimeException( 'Synthetic read failure' ); } return $this->status; }
	public function get_post_password() { return $this->password; }
	public function is_publicly_viewable() { return $this->public; }
	public function is_visible() { return $this->visible && ( empty( $GLOBALS['hide_out_of_stock'] ) || $this->stock ); }
	public function is_purchasable() { return $this->purchasable; }
	public function is_in_stock() { return $this->stock; }
	public function get_price() { return isset( $GLOBALS['price_filter'] ) ? ( $GLOBALS['price_filter'] )( $this->price, $this ) : $this->price; }
	public function get_regular_price() { return $this->regular; }
	public function is_on_sale() { return $this->sale; }
	public function get_visible_children() { return $this->children; }
	public function get_meta( $key, ...$args ) { return $this->meta[ $key ] ?? ''; }
	public function get_attribute( $key ) { return $this->attributes[ $key ] ?? ''; }
	public function get_variation_attributes() { return $this->required; }
	public function get_attributes() { return array(); }
	public function save() { forbidden_io( 'product-save' ); }
	public function update_meta_data( ...$args ) { forbidden_io( 'product-meta-write' ); }
}
class WC_Product_Variation extends WC_Product {
	public bool $active = true, $variation_visible = true;
	public function __construct( int $id, int $parent ) { parent::__construct( $id, 'variation', $parent ); }
	public function variation_is_active() { return $this->active; }
	public function variation_is_visible() { return $this->variation_visible; }
	public function get_variation_attributes() { return $this->attributes; }
}
class Blocked_Commerce_IO { public function __construct() { forbidden_io( 'commerce-stock-service' ); } }
class_alias( Blocked_Commerce_IO::class, 'Automattic\\WooCommerce\\StoreApi\\Utilities\\QuantityLimits' );
class_alias( Blocked_Commerce_IO::class, 'Automattic\\WooCommerce\\Checkout\\Helpers\\ReserveStock' );

$products = array(); $posts = array(); $lookups = 0; $report_reads = 0; $forbidden = array();
$force_login = false; $user_id = 0; $currency = 'USD'; $decimals = 2; $tax = 'excl'; $hide_out_of_stock = false;
function forbidden_io( $operation ) { $GLOBALS['forbidden'][] = $operation; throw new RuntimeException( 'Forbidden I/O: ' . $operation ); }
function WC() { forbidden_io( 'woocommerce-session-or-cart' ); }
function wc_load_cart() { forbidden_io( 'load-cart' ); }
function wc_get_product( $id ) { ++$GLOBALS['lookups']; return $GLOBALS['products'][ $id ] ?? null; }
function get_post_type( $id ) { return $GLOBALS['posts'][ $id ]['post_type'] ?? false; }
function get_post_field( $field, $id, $context = 'display' ) { return $GLOBALS['posts'][ $id ][ $field ] ?? ''; }
function get_post_status( $id ) { $post = $GLOBALS['posts'][ $id ] ?? array(); return 'inherit' === ( $post['post_status'] ?? '' ) && ! empty( $post['post_parent'] ) ? get_post_status( $post['post_parent'] ) : ( $post['post_status'] ?? false ); }
function get_current_user_id() { return $GLOBALS['user_id']; }
function is_user_logged_in() { return $GLOBALS['user_id'] > 0; }
function get_option( $key, $default = false ) {
	if ( 'peptide_core_store_config' === $key ) { return array( 'force_login' => $GLOBALS['force_login'] ); }
	return str_starts_with( $key, 'woocommerce_tax_display_' ) ? $GLOBALS['tax'] : $default;
}
function get_posts( $args ) { ++$GLOBALS['report_reads']; if ( ! empty( $GLOBALS['throw_catalog'] ) ) { throw new RuntimeException( 'Synthetic catalog failure' ); } return array(); }
function wc_get_price_decimals() { return $GLOBALS['decimals']; }
function get_woocommerce_currency() { return $GLOBALS['currency']; }
function wc_format_decimal( $value, $dp = false ) { return false === $dp ? (string) (float) $value : number_format( (float) $value, $dp, '.', '' ); }
function wc_get_price_to_display( $product, $args ) { return $args['price'] * ( 'incl' === $GLOBALS['tax'] ? 1.2 : 1 ); }
function wc_price( $value ) { return '<span>' . $GLOBALS['currency'] . ' ' . number_format( $value, $GLOBALS['decimals'], '.', '' ) . '</span>'; }
function wc_variation_attribute_name( $name ) { return 'attribute_' . strtolower( $name ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function rest_sanitize_boolean( $value ) { return in_array( $value, array( true, 1, '1', 'true' ), true ); }
function update_option( ...$args ) { forbidden_io( 'option-write' ); }
function set_transient( ...$args ) { forbidden_io( 'persistent-result-cache' ); }
function wp_remote_get( ...$args ) { forbidden_io( 'http-fallback' ); }
function wp_remote_post( ...$args ) { forbidden_io( 'http-write' ); }

$includes = dirname( __DIR__ ) . '/includes/';
foreach ( array( 'class-meta.php', 'class-post-types.php', 'class-store-config.php', 'modules/class-products.php', 'class-pricing.php', 'class-unit-pricing.php', 'admin/class-product-panel.php', 'class-catalog.php', 'api/class-store-api.php', 'functions.php' ) as $file ) { require $includes . $file; }

$checks = array();
$check = static function ( string $name, bool $pass ) use ( &$checks ): void { $checks[] = compact( 'name', 'pass' ); };
$register = static function ( WC_Product $product ): void {
	$GLOBALS['products'][ $product->id ] = $product;
	$GLOBALS['posts'][ $product->id ] = array( 'post_type' => $product->is_type( 'variation' ) ? 'product_variation' : 'product', 'post_status' => 'publish', 'post_password' => '', 'post_parent' => $product->parent );
};
$read = static function ( $product, string $name ) use ( $check ) {
	$before = serialize( array( $GLOBALS['products'], $GLOBALS['posts'], $GLOBALS['force_login'], $GLOBALS['user_id'], $GLOBALS['currency'], $GLOBALS['decimals'], $GLOBALS['tax'] ) );
	$data = peptide_core_get_store_product_data( $product );
	$check( $name . ' does not mutate product/post/options/customer state', $before === serialize( array( $GLOBALS['products'], $GLOBALS['posts'], $GLOBALS['force_login'], $GLOBALS['user_id'], $GLOBALS['currency'], $GLOBALS['decimals'], $GLOBALS['tax'] ) ) );
	return $data;
};
$reject = static function ( $product, string $name ) use ( $check, $read ): void {
	$before = $GLOBALS['report_reads'];
	$check( $name . ' returns null', null === $read( $product, $name ) );
	$check( $name . ' does not invoke the extension data service', $before === $GLOBALS['report_reads'] );
};

$simple = new WC_Product( 100 );
$simple->meta = array( PeptideCore\Meta::PRODUCT_SPECS => array( array( 'key' => 'pack', 'label' => 'Pack', 'value' => '10vials', 'unit' => null ) ), '_private_supplier_secret' => 'NEVER_PUBLIC' );
$register( $simple );
$parent = new WC_Product( 11306, 'variable' ); $parent->price = '40'; $parent->regular = '40';
$parent->children = array( 11307, 11309, 11308 );
$parent->required = array( 'pack' => array( '20mg*10vials', '15mg*10vials', '10mg*10vials' ) );
$parent->meta[PeptideCore\Meta::PRODUCT_SPECS] = array( array( 'key' => 'parent-spec', 'label' => 'Source', 'value' => 'Public parent specification', 'unit' => null ) );
$register( $parent );
$tr20 = new WC_Product_Variation( 11307, 11306 ); $tr20->price = '55'; $tr20->regular = '78'; $tr20->attributes = array( 'attribute_pack' => '20mg*10vials' ); $register( $tr20 );
$tr15 = new WC_Product_Variation( 11309, 11306 ); $tr15->price = '50'; $tr15->regular = '50'; $tr15->sale = false; $tr15->attributes = array( 'attribute_pack' => '15mg*10vials' ); $register( $tr15 );
$tr10 = new WC_Product_Variation( 11308, 11306 ); $tr10->price = '40'; $tr10->regular = '40'; $tr10->sale = false; $tr10->attributes = array( 'attribute_pack' => '10mg*10vials' ); $register( $tr10 );

foreach ( array( null, 100, '100', array( 'id' => 100 ), new stdClass(), false ) as $index => $bad ) {
	$before = $lookups; $reject( $bad, 'non-Woo input ' . $index ); $check( 'non-Woo input does not resolve an arbitrary product ' . $index, $lookups === $before );
}
$reject( new WC_Product( 0 ), 'unsaved product' );

$data = $read( $simple, 'public simple' );
$check( 'simple helper equals actual Store_Api data for the same object', $data === PeptideCore\Api\Store_Api::data( $simple ) );
$check( 'simple current and regular prices come from actual Pricing', $data['price_pair']['price'] === '2000' && $data['price_pair']['regular_price'] === '2500' && $data['price_pair']['variation_id'] === 0 );
$check( 'public specs remain native field-reader output', $data['specs'] === $simple->meta[PeptideCore\Meta::PRODUCT_SPECS] );
$check( 'DTO does not add canAddOne or private metadata', ! array_key_exists( 'canAddOne', $data ) && ! str_contains( json_encode( $data ), 'NEVER_PUBLIC' ) );

foreach ( array( $tr20, $tr15, $tr20 ) as $index => $sku ) {
	$data = $read( $sku, 'selected variation ' . $index );
	$check( 'variation helper equals actual Store_Api data ' . $index, $data === PeptideCore\Api\Store_Api::data( $sku ) );
	$check( 'variation keeps exact selected identity ' . $index, $data['price_pair']['product_id'] === 11306 && $data['price_pair']['variation_id'] === $sku->id && $data['unit_pricing']['purchasableId'] === $sku->id );
	$check( 'variation specs remain parent-owned ' . $index, $data['specs'] === $parent->meta[PeptideCore\Meta::PRODUCT_SPECS] );
}
$data = $read( $tr15, 'non-sale selected variation' );
$check( 'selected TR15 actively returns no discount', $data['price_pair']['on_sale'] === false && $data['price_pair']['percent_off'] === 0 && $data['price_pair']['compare_source'] === null );
$data = $read( $parent, 'variable parent aggregate' );
$check( 'variable parent keeps native aggregate/card result', $data === PeptideCore\Api\Store_Api::data( $parent ) && $data['price_pair']['variation_id'] === 11308 && $data['unit_pricing'] === null );

foreach ( array( 'draft', 'private', 'pending', 'trash' ) as $status ) {
	$simple->status = $status; $reject( $simple, 'Woo status ' . $status ); $simple->status = 'publish';
	$posts[100]['post_status'] = $status; $reject( $simple, 'raw status ' . $status ); $posts[100]['post_status'] = 'publish';
}
$posts[11309]['post_status'] = 'inherit';
$check( 'fixture models get_post_status inheritance', get_post_status(11309) === 'publish' );
$reject( $tr15, 'raw child inherit cannot borrow parent publish' ); $posts[11309]['post_status'] = 'publish';
$posts[11306]['post_status'] = 'inherit'; $reject( $tr15, 'raw parent inherit is not canonical publish' ); $posts[11306]['post_status'] = 'publish';
foreach ( array( 'password' => 'protected', 'visible' => false, 'public' => false ) as $property => $value ) {
	$original = $simple->$property; $simple->$property = $value; $reject( $simple, 'simple ' . $property ); $simple->$property = $original;
}
$posts[100]['post_password'] = 'raw-only-secret'; $reject( $simple, 'raw simple password' ); $posts[100]['post_password'] = '';
$posts[11309]['post_password'] = 'raw-only-secret';
$check( 'native variation password-hydration edge is represented', $tr15->get_post_password() === '' );
$reject( $tr15, 'raw variation password despite empty Woo property' ); $posts[11309]['post_password'] = '';
$tr15->password = 'object-only-secret'; $reject( $tr15, 'Woo variation password' ); $tr15->password = '';
foreach ( array( 'password' => 'protected', 'visible' => false, 'public' => false, 'status' => 'private' ) as $property => $value ) {
	$original = $parent->$property; $parent->$property = $value; $reject( $tr15, 'parent ' . $property ); $parent->$property = $original;
}
$posts[11306]['post_password'] = 'raw-parent-secret'; $reject( $tr15, 'raw parent password' ); $posts[11306]['post_password'] = '';
$posts[11306]['post_status'] = 'private'; $reject( $tr15, 'raw parent private with public Woo object' ); $posts[11306]['post_status'] = 'publish';
foreach ( array( 'active', 'variation_visible' ) as $property ) { $tr15->$property = false; $reject( $tr15, 'variation ' . $property ); $tr15->$property = true; }
$posts[11309]['post_type'] = 'product'; $reject( $tr15, 'wrong native variation post type' ); $posts[11309]['post_type'] = 'product_variation';
$posts[100]['post_type'] = 'page'; $reject( $simple, 'wrong simple native post type' ); $posts[100]['post_type'] = 'product';
$fake_variation = new WC_Product(11309, 'variation', 11306); $reject( $fake_variation, 'variation label without WC_Product_Variation class' );
$tr15->type = 'simple'; $reject( $tr15, 'variation object mislabeled simple' ); $tr15->type = 'variation';
$tr15->parent = 0; $reject( $tr15, 'zero parent' ); $tr15->parent = 11306;
$posts[11309]['post_parent'] = 999; $reject( $tr15, 'foreign raw parent' ); $posts[11309]['post_parent'] = 11306;
unset( $products[11306] ); $reject( $tr15, 'missing parent' ); $products[11306] = $parent;
$products[11306] = new WC_Product( 999, 'variable' ); $reject( $tr15, 'lookup returns different parent identity' ); $products[11306] = $parent;
$parent->type = 'simple'; $reject( $tr15, 'parent is not variable' ); $parent->type = 'variable';

// Buyability is not top-level display authority. Optional unit data may be null.
foreach ( array( 'purchasable', 'stock' ) as $property ) {
	$simple->$property = false; $data = $read( $simple, 'display-only simple ' . $property );
	$check( 'simple remains display data without ' . $property, is_array( $data ) && $data['price_pair']['price'] === '2000' && $data['unit_pricing'] === null ); $simple->$property = true;
	$tr15->$property = false; $data = $read( $tr15, 'display-only variation ' . $property );
	$check( 'variation remains display data without ' . $property, is_array( $data ) && $data['price_pair']['variation_id'] === 11309 && $data['unit_pricing'] === null ); $tr15->$property = true;
}
$hide_out_of_stock = true; $simple->stock = false; $reject( $simple, 'existing public-catalog hide-out-of-stock policy' ); $hide_out_of_stock = false; $simple->stock = true;
$force_login = true; $reject( $simple, 'force-login anonymous' ); $user_id = 7;
$check( 'logged-in public object works under force-login', is_array( $read( $tr15, 'force-login member' ) ) );
$posts[11309]['post_status'] = 'private'; $reject( $tr15, 'login cannot override private status' ); $posts[11309]['post_status'] = 'publish';
$force_login = false; $user_id = 0;

$currency = 'JPY'; $decimals = 0; $data = $read( $tr15, 'JPY selected' );
$check( 'fresh JPY exact SKU and precision', $data === PeptideCore\Api\Store_Api::data( $tr15 ) && $data['price_pair']['price'] === '50' && $data['price_pair']['currency_code'] === 'JPY' && $data['unit_pricing']['currencyMinorUnit'] === 0 );
$currency = 'KWD'; $decimals = 3; $tax = 'incl'; $data = $read( $tr15, 'KWD including tax selected' );
$check( 'tax and currency remain actual service data', $data === PeptideCore\Api\Store_Api::data( $tr15 ) && $data['price_pair']['price'] === '50000' && $data['unit_pricing']['salesUnitPrice']['minor'] === '60000' && $data['unit_pricing']['taxDisplay'] === 'incl' );
$currency = 'USD'; $decimals = 2; $tax = 'excl';
$GLOBALS['price_filter'] = static fn ( $price, $product ) => $product->id === 11309 ? '45' : $price;
$data = $read( $tr15, 'dynamic selected-SKU price' );
$check( 'dynamic getter read is not cached by helper', $data === PeptideCore\Api\Store_Api::data( $tr15 ) && $data['price_pair']['price'] === '4500' ); unset( $GLOBALS['price_filter'] );
$check( 'subsequent read restores live price', peptide_core_get_store_product_data( $tr15 )['price_pair']['price'] === '5000' );
$simple->throws = 'status'; $reject( $simple, 'qualification exception fails closed' ); $simple->throws = '';
$GLOBALS['throw_catalog'] = true; $check( 'actual service exception returns null without REST fallback', null === $read( $simple, 'service exception' ) ); unset( $GLOBALS['throw_catalog'] );
$check( 'no stock service/session/cart/HTTP/cache-write/product-write was attempted', array() === $forbidden );

$passed = count( array_filter( $checks, static fn ( $row ) => $row['pass'] ) );
echo json_encode( array( 'kind' => 'Actual public helper and Store_Api/Pricing/Unit_Pricing/Catalog services; finite memory Woo fixtures, no WordPress bootstrap', 'passed' => $passed, 'total' => count( $checks ), 'checks' => $checks, 'forbidden_io' => $forbidden ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . PHP_EOL;
exit( $passed === count( $checks ) ? 0 : 1 );
