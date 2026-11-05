<?php
/**
 * Installed WooCommerce field/Store API contract in an isolated process.
 * Usage: php -n checkout-phone-required-native.php /absolute/wordpress/root
 * Loads native source only; does not bootstrap WordPress, connect SQL or create orders.
 */
declare( strict_types = 1 );
if ( 'cli' !== PHP_SAPI ) { exit( 2 ); }
$wp = rtrim( (string) ( $argv[1] ?? '' ), '/\\' );
if ( ! is_file( $wp . '/wp-includes/plugin.php' ) ) { throw new RuntimeException( 'Supply installed WordPress root' ); }
define( 'ABSPATH', $wp . '/' );
define( 'WPINC', 'wp-includes' );
$wc = $wp . '/wp-content/plugins/woocommerce/';
require $wp . '/wp-includes/plugin.php';
require $wp . '/wp-includes/class-wp-error.php';
$options = array(
	'woocommerce_checkout_phone_field' => 'optional',
	'woocommerce_checkout_company_field' => 'optional',
	'woocommerce_checkout_address_2_field' => 'optional',
);
$checks = 0;
function get_option( $name, $default = false ) {
	$pre = apply_filters( 'pre_option_' . $name, false, $name, $default );
	return false !== $pre ? $pre : ( $GLOBALS['options'][ $name ] ?? $default );
}
function update_option( ...$args ) { throw new RuntimeException( 'Fixture must not persist settings' ); }
function __( $text, $domain = '' ) { return $text; }
function _x( $text, $context = '', $domain = '' ) { return $text; }
function esc_attr__( $text, $domain = '' ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function determine_locale() { return 'en_US'; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, $args ); }
function wc_checkout_fields_uasort_comparison( $a, $b ) { return ( $a['priority'] ?? 0 ) <=> ( $b['priority'] ?? 0 ); }
function wc_string_to_bool( $value ) { return is_bool( $value ) ? $value : in_array( strtolower( (string) $value ), array( 'yes', 'true', '1' ), true ); }
function phone_native_check( string $name, bool $pass ): void { if ( ! $pass ) { throw new RuntimeException( 'FAIL: ' . $name ); } ++$GLOBALS['checks']; }
class WC_Data {}
class WC_Order extends WC_Data {
	public function __construct( private array $address ) {}
	public function get_address( $type ) { return $this->address; }
	public function save() { throw new RuntimeException( 'Fixture must not save orders' ); }
}
require $wc . 'src/Blocks/Utils/CartCheckoutUtils.php';
require $wc . 'src/Blocks/Domain/Services/CoreCheckoutFields.php';
require $wc . 'includes/class-wc-countries.php';
require $wc . 'src/Blocks/Domain/Services/CheckoutFieldsStorage.php';
require $wc . 'src/Blocks/Domain/Services/CheckoutFields.php';
require $wc . 'src/StoreApi/Utilities/OrderController.php';
require dirname( __DIR__ ) . '/includes/class-checkout-phone.php';
use Automattic\WooCommerce\Blocks\Domain\Services\CoreCheckoutFields;
use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;
use Automattic\WooCommerce\StoreApi\Utilities\OrderController;
use PeptideCore\Checkout_Phone;
class PhoneNativeCountries extends WC_Countries {
	public function get_allowed_countries() { return array( 'US' => 'United States', 'PR' => 'Puerto Rico' ); }
	public function get_shipping_countries() { return $this->get_allowed_countries(); }
	public function get_base_country() { return 'US'; }
}
class PhoneNativeAdditionalFields extends CheckoutFields {
	public function __construct() {}
	public function get_all_fields_from_object( WC_Data $object, string $group = 'other', bool $all = false ) { return array(); }
	public function validate_fields_for_location( $fields, $location, $group = 'other' ) { return new WP_Error(); }
}
$native_woo = (object) array( 'countries' => new PhoneNativeCountries() );
function wc() { return $GLOBALS['native_woo']; }
$before = $options;
phone_native_check( 'legacy optional is reproduced in installed Blocks field', false === CoreCheckoutFields::get_fields()['phone']['required'] );
$options['woocommerce_checkout_phone_field'] = 'hidden';
phone_native_check( 'legacy hidden is reproduced in installed Blocks field', true === CoreCheckoutFields::get_fields()['phone']['hidden'] );
Checkout_Phone::register_hooks();
phone_native_check( 'native visibility now required even with old hidden option', 'required' === CartCheckoutUtils::get_phone_field_visibility() );
$field = CoreCheckoutFields::get_fields()['phone'];
phone_native_check( 'native Blocks marks phone required', true === $field['required'] );
phone_native_check( 'native Blocks shows phone', false === $field['hidden'] );
phone_native_check( 'native field label is Phone, not optionalLabel', 'Phone' === $field['label'] );
phone_native_check( 'native telephone control and autofill retained', 'tel' === $field['type'] && 'tel' === $field['autocomplete'] );
$reflection = new ReflectionClass( OrderController::class );
$controller = $reflection->newInstanceWithoutConstructor();
$service = $reflection->getProperty( 'additional_fields_controller' );
$service->setValue( $controller, new PhoneNativeAdditionalFields() );
$validate = $reflection->getMethod( 'validate_address_fields' );
foreach ( array( 'US', 'PR' ) as $country ) {
	foreach ( array( 'billing', 'shipping' ) as $type ) {
		$address = array( 'country' => $country, 'first_name' => 'Synthetic', 'last_name' => 'Fixture', 'address_1' => 'Synthetic address', 'address_2' => '', 'company' => '', 'city' => 'Synthetic city', 'state' => 'US' === $country ? 'CA' : '', 'postcode' => 'US' === $country ? '90001' : '00901' );
		$empty = new WP_Error();
		$validate->invoke( $controller, new WC_Order( $address ), $type, $empty );
		phone_native_check( $country . ' ' . $type . ' native full checkout rejects missing phone', $empty->has_errors() && 'phone' === $empty->get_error_data( $type ) );
		$address['phone'] = '   ';
		$blank = new WP_Error();
		$validate->invoke( $controller, new WC_Order( $address ), $type, $blank );
		phone_native_check( $country . ' ' . $type . ' native full checkout rejects whitespace phone', $blank->has_errors() && 'phone' === $blank->get_error_data( $type ) );
		$address['phone'] = 'US' === $country ? '+1 (555) 010-0200' : '+1 (787) 555-0100';
		$order = new WC_Order( $address );
		$filled = new WP_Error();
		$validate->invoke( $controller, $order, $type, $filled );
		phone_native_check( $country . ' ' . $type . ' native required-field validation accepts present phone', ! $filled->has_errors() );
		phone_native_check( $country . ' ' . $type . ' policy does not rewrite address or number', $address === $order->get_address( $type ) );
	}
}
$options['woocommerce_checkout_phone_field'] = 'optional';
phone_native_check( 'persisted option values unchanged by candidate policy', $before === $options );
echo json_encode( array( 'passed' => $checks, 'native_sources' => array( 'WordPress hooks/errors', 'Woo CartCheckoutUtils', 'Woo CoreCheckoutFields', 'Woo WC_Countries locales', 'Woo Store API OrderController::validate_address_fields' ), 'sql_connections' => 0, 'real_orders' => 0, 'scope' => 'Native required-field validation only; phone-format validation remains native and was not bypassed' ) ) . PHP_EOL;
