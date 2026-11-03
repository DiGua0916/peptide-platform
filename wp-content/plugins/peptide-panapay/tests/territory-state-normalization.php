<?php
/** Native WC customer getters/session read and WP hook dispatcher; memory-only storage, no WP bootstrap/DB/HTTP. */
define( 'ABSPATH', __DIR__ . '/' );
$woo_root = getenv( 'PANAPAY_MODEL_WC_ROOT' ) ?: 'D:/Codex/Sites/vialcovepeptidescom/app/public/wp-content/plugins/woocommerce';
$wp_root = getenv( 'PANAPAY_MODEL_WP_ROOT' ) ?: 'D:/Codex/Sites/vialcovepeptidescom/app/public';
foreach ( array( $wp_root . '/wp-includes/class-wp-hook.php', $wp_root . '/wp-includes/plugin.php', $woo_root . '/includes/class-wc-customer.php', $woo_root . '/i18n/states.php' ) as $file ) {
	if ( ! is_readable( $file ) ) { throw new RuntimeException( 'Set model WordPress/WooCommerce roots to existing source trees.' ); }
}
require $wp_root . '/wp-includes/plugin.php';
function __( $text, $domain = 'default' ) { return $text; }
function _x( $text, $context, $domain = 'default' ) { return $text; }
function get_option( $name, $default = false ) { return $default; }
function is_checkout() { throw new RuntimeException( 'Checkout-page context must not be required.' ); }
function is_user_logged_in() { return false; }
function wc_get_customer_default_location() { return array( 'country' => 'US', 'state' => 'CA' ); }
function update_option( ...$args ) { ++$GLOBALS['forbidden_writes']; throw new RuntimeException( 'No option writes.' ); }
function update_user_meta( ...$args ) { ++$GLOBALS['forbidden_writes']; throw new RuntimeException( 'No user writes.' ); }
function update_post_meta( ...$args ) { ++$GLOBALS['forbidden_writes']; throw new RuntimeException( 'No order writes.' ); }
function wp_remote_post( ...$args ) { ++$GLOBALS['forbidden_writes']; throw new RuntimeException( 'No HTTP.' ); }
function WC() {
	++$GLOBALS['wc_calls'];
	if ( $GLOBALS['wc_throws'] ) { throw new RuntimeException( 'Woo not ready.' ); }
	return $GLOBALS['woo'];
}
final class WC_Data_Store {
	public static function load( ...$args ) { ++$GLOBALS['forbidden_writes']; throw new RuntimeException( 'No native storage loading.' ); }
}
require $woo_root . '/includes/abstracts/abstract-wc-data.php';
require $woo_root . '/includes/class-wc-customer.php';
require $woo_root . '/includes/data-stores/class-wc-data-store-wp.php';
require $woo_root . '/includes/interfaces/class-wc-customer-data-store-interface.php';
require $woo_root . '/includes/interfaces/class-wc-object-data-store-interface.php';
require $woo_root . '/includes/data-stores/class-wc-customer-data-store-session.php';
require dirname( __DIR__ ) . '/includes/class-checkout-fields.php';
use VialCove\Panapay\Checkout_Fields;

class Model_Customer extends WC_Customer {
	public function has_shipping_address() { return true; }
	public function save() { ++$GLOBALS['forbidden_writes']; throw new RuntimeException( 'No customer save.' ); }
}
final class Throwing_Country_Customer extends Model_Customer {
	public function get_billing_country( $context = 'view' ) { throw new RuntimeException( 'Country is unavailable.' ); }
}
final class WC_Order {
	public function __call( $name, $args ) { ++$GLOBALS['forbidden_writes']; throw new RuntimeException( 'Never call order getters or saves.' ); }
}
final class Memory_Countries {
	public array $calls = array();
	public bool $throws = false;
	public function __construct( public $pr, public $us ) {}
	public function get_states( $country ) { $this->calls[] = $country; if ( $this->throws ) { throw new RuntimeException( 'States are not ready.' ); } return 'PR' === $country ? $this->pr : $this->us; }
}
final class Memory_Session {
	public function __construct( public array $customer = array() ) {}
	public function get( $key, $default = null ) { return 'customer' === $key ? $this->customer : $default; }
	public function set( ...$args ) { ++$GLOBALS['forbidden_writes']; throw new RuntimeException( 'Production normalization must not write a session.' ); }
}
final class Memory_Woo {
	public function __construct( public $countries, public $session = null ) {}
	public function __get( $name ) { ++$GLOBALS['global_customer_reads']; throw new RuntimeException( 'Do not read a global customer.' ); }
}
$GLOBALS['checks'] = array();
$GLOBALS['forbidden_writes'] = $GLOBALS['global_customer_reads'] = $GLOBALS['wc_calls'] = 0;
$GLOBALS['wc_throws'] = false;
$native_states = include $woo_root . '/i18n/states.php';
$GLOBALS['woo'] = new Memory_Woo( new Memory_Countries( $native_states['PR'], $native_states['US'] ) );
function check( string $name, bool $pass ): void { $GLOBALS['checks'][] = array( 'name' => $name, 'pass' => $pass ); if ( ! $pass ) { throw new RuntimeException( 'FAIL ' . $name ); } }
function customer( string $billing_country = 'PR', $billing_state = 'CA', string $shipping_country = 'PR', $shipping_state = 'NY' ): Model_Customer {
	$object = ( new ReflectionClass( Model_Customer::class ) )->newInstanceWithoutConstructor();
	$object->set_billing_country( $billing_country ); $object->set_billing_state( $billing_state );
	$object->set_shipping_country( $shipping_country ); $object->set_shipping_state( $shipping_state );
	return $object;
}
function maps( $pr, $us ): Memory_Countries { $countries = new Memory_Countries( $pr, $us ); $GLOBALS['woo'] = new Memory_Woo( $countries ); return $countries; }
Checkout_Fields::register_hooks();
check( 'billing getter filter registered immediately with two arguments', 10 === has_filter( 'woocommerce_customer_get_billing_state', array( Checkout_Fields::class, 'billing_state' ) ) );
check( 'shipping getter filter registered immediately with two arguments', 10 === has_filter( 'woocommerce_customer_get_shipping_state', array( Checkout_Fields::class, 'shipping_state' ) ) );
check( 'existing native phone-field initialization remains registered', 20 === has_action( 'woocommerce_init', array( Checkout_Fields::class, 'register_field' ) ) );
check( 'existing admin phone notice remains registered', 10 === has_action( 'admin_notices', array( Checkout_Fields::class, 'admin_notice' ) ) );
Checkout_Fields::register_hooks();
check( 'register_hooks is idempotent', count( $GLOBALS['wp_filter']['woocommerce_customer_get_billing_state']->callbacks[10] ) === 1 && count( $GLOBALS['wp_filter']['woocommerce_customer_get_shipping_state']->callbacks[10] ) === 1 );
check( 'native PR state map is an actual empty array', $native_states['PR'] === array() );
check( 'native US map contains known codes', isset( $native_states['US']['CA'], $native_states['US']['NY'] ) );

foreach ( array_keys( $native_states['US'] ) as $code ) {
	$object = customer( 'PR', $code, 'PR', $code );
	check( 'PR native billing/shipping view removes exact US code ' . $code, '' === $object->get_billing_state() && '' === $object->get_shipping_state() );
	check( 'PR native edit keeps exact raw US code ' . $code, $code === $object->get_billing_state( 'edit' ) && $code === $object->get_shipping_state( 'edit' ) );
}
foreach ( array( 'US', 'CA', 'FR', 'DE', 'GB', 'VI', 'GU', '', 'pr' ) as $country ) {
	$object = customer( $country, 'CA', $country, 'NY' );
	check( 'other country remains byte-exact ' . ( $country ?: '(empty)' ), 'CA' === $object->get_billing_state() && 'NY' === $object->get_shipping_state() );
}
foreach ( array( '', 'ny', ' CA', 'CA ', 'New York', 'PR', 'Unknown', 'Municipality', "C\0A", '0' ) as $value ) {
	$object = customer( 'PR', $value, 'PR', $value );
	check( 'PR non-code or empty value remains byte-exact ' . bin2hex( $value ), $object->get_billing_state() === $value && $object->get_shipping_state() === $value );
}
$object = customer( 'PR', 'CA', 'US', 'NY' );
check( 'billing normalization never derives country from shipping', '' === $object->get_billing_state() && 'NY' === $object->get_shipping_state() );
$object = customer( 'US', 'CA', 'PR', 'NY' );
check( 'shipping normalization never derives country from billing', 'CA' === $object->get_billing_state() && '' === $object->get_shipping_state() );
$object = customer(); $before = serialize( $object );
check( 'view/edit getters leave customer object unchanged', '' === $object->get_billing_state() && 'CA' === $object->get_billing_state( 'edit' ) && $before === serialize( $object ) );
$filtered_country = static fn( $value ) => 'PR';
add_filter( 'woocommerce_customer_get_billing_country', $filtered_country );
$object = customer( 'US', 'CA' );
check( 'normalization reads country edit rather than a filtered view', 'PR' === $object->get_billing_country() && 'CA' === $object->get_billing_state() );
remove_filter( 'woocommerce_customer_get_billing_country', $filtered_country );
$filtered_country = static fn( $value ) => 'US'; add_filter( 'woocommerce_customer_get_shipping_country', $filtered_country );
$object = customer(); check( 'raw PR shipping still normalizes if country view is filtered', 'US' === $object->get_shipping_country() && '' === $object->get_shipping_state() );
remove_filter( 'woocommerce_customer_get_shipping_country', $filtered_country );

foreach ( array( array( 'SJ' => 'San Juan' ), array( 'CA' => 'Future PR area' ), false, null, 'unavailable' ) as $pr ) {
	maps( $pr, $native_states['US'] ); $object = customer();
	check( 'nonempty/custom/unavailable PR map preserves all values ' . get_debug_type( $pr ) . ':' . json_encode( $pr ), 'CA' === $object->get_billing_state() && 'NY' === $object->get_shipping_state() );
}
foreach ( array( array(), false, null, 'unavailable', array( 'ZZ' => 'Synthetic' ) ) as $us ) {
	maps( array(), $us ); $object = customer();
	check( 'US map unavailable or missing exact code preserves value ' . get_debug_type( $us ) . ':' . json_encode( $us ), 'CA' === $object->get_billing_state() );
}
$countries = maps( array(), array( 'ZZ' => 'Synthetic US map entry' ) );
check( 'code membership follows actual current Woo map rather than a hardcoded code list', '' === customer( 'PR', 'ZZ' )->get_billing_state() && 'CA' === customer( 'PR', 'CA' )->get_billing_state() );
$countries->throws = true; check( 'countries exception preserves value', 'CA' === customer()->get_billing_state() );
$GLOBALS['wc_throws'] = true; check( 'Woo exception preserves value', 'CA' === customer()->get_billing_state() ); $GLOBALS['wc_throws'] = false;
foreach ( array( null, new stdClass() ) as $woo ) { $GLOBALS['woo'] = $woo; check( 'Woo or countries not initialized preserves value', 'CA' === customer()->get_billing_state() ); }
foreach ( array( null, false, new stdClass() ) as $countries ) { $GLOBALS['woo'] = new Memory_Woo( $countries ); check( 'missing countries API preserves value ' . get_debug_type( $countries ), 'CA' === customer()->get_billing_state() ); }
maps( array(), $native_states['US'] );
$throwing = ( new ReflectionClass( Throwing_Country_Customer::class ) )->newInstanceWithoutConstructor();
check( 'customer country getter exception preserves value', 'CA' === Checkout_Fields::billing_state( 'CA', $throwing ) );
foreach ( array( null, 0, false, array( 'CA' ), new stdClass() ) as $value ) {
	check( 'non-string callback values preserve exact type ' . get_debug_type( $value ), $value === Checkout_Fields::billing_state( $value, customer() ) && $value === Checkout_Fields::shipping_state( $value, customer() ) );
}
foreach ( array( null, new stdClass(), new WC_Order() ) as $object ) { check( 'non-customer objects never invoke order/global getters', 'CA' === Checkout_Fields::billing_state( 'CA', $object ) && 'NY' === Checkout_Fields::shipping_state( 'NY', $object ) ); }

// Actual native session read/defaults + actual customer getters, with only the session and default-location boundary in memory.
$session = new Memory_Session( array( 'id' => '0', 'date_modified' => '', 'country' => 'PR', 'state' => '', 'shipping_country' => 'PR', 'shipping_state' => '' ) );
$GLOBALS['woo'] = new Memory_Woo( new Memory_Countries( $native_states['PR'], $native_states['US'] ), $session );
$store = new WC_Customer_Data_Store_Session();
$first = customer( '', '', '', '' ); $store->read( $first );
check( 'native session read fills raw default state but view stays empty for PR', 'CA' === $first->get_billing_state( 'edit' ) && '' === $first->get_billing_state() && 'PR' === $first->get_billing_country( 'edit' ) );
check( 'order-copy view projection stays stable without rewriting raw customer', array( 'country' => 'PR', 'state' => '' ) === array( 'country' => $first->get_billing_country(), 'state' => $first->get_billing_state() ) );
// Native session serialization reads edit values; emulate only these four fields without invoking save_to_session.
$session->customer = array( 'id' => '0', 'date_modified' => '', 'country' => $first->get_billing_country( 'edit' ), 'state' => $first->get_billing_state( 'edit' ), 'shipping_country' => $first->get_shipping_country( 'edit' ), 'shipping_state' => $first->get_shipping_state( 'edit' ) );
$second = customer( '', '', '', '' ); $store->read( $second );
check( 'second native session read cannot reintroduce default US code into PR view', '' === $second->get_billing_state() && 'CA' === $second->get_billing_state( 'edit' ) && '' === $second->get_shipping_state() );
$session->customer['country'] = 'US'; $session->customer['state'] = 'CA'; $session->customer['shipping_country'] = 'US'; $session->customer['shipping_state'] = 'NY';
$third = customer( '', '', '', '' ); $store->read( $third );
check( 'native session read preserves real US billing and shipping states', 'CA' === $third->get_billing_state() && 'NY' === $third->get_shipping_state() );
check( 'normalization never accesses global customer', 0 === $GLOBALS['global_customer_reads'] );
check( 'no saves database options order methods or HTTP calls occurred', 0 === $GLOBALS['forbidden_writes'] );
$report = array( 'complete' => true, 'checks' => count( $GLOBALS['checks'] ), 'failed' => 0, 'cases' => $GLOBALS['checks'], 'sourceSha256' => hash_file( 'sha256', dirname( __DIR__ ) . '/includes/class-checkout-fields.php' ), 'nativeSources' => array( 'customer' => hash_file( 'sha256', $woo_root . '/includes/class-wc-customer.php' ), 'session' => hash_file( 'sha256', $woo_root . '/includes/data-stores/class-wc-customer-data-store-session.php' ), 'states' => hash_file( 'sha256', $woo_root . '/i18n/states.php' ) ), 'realWordPressBootstrap' => false, 'realDatabase' => false, 'realHttp' => false, 'realBusinessOrExistingOrderWrites' => false, 'limits' => 'Actual WP dispatcher and native WC getters/setters/session read run on constructor-free in-memory customer objects; no real WC constructor, persisted session, checkout HTTP, order save/fingerprint or payment API executed.' );
if ( isset( $argv[1] ) ) { file_put_contents( $argv[1], json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ); }
echo 'PASS ', $report['checks'], " native customer/memory-boundary checks; no business data written.\n";
