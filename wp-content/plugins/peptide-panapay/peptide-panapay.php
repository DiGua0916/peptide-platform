<?php
/**
 * Plugin Name: VialCove — Panapay Payments
 * Description: Hosted Panapay payments for native WooCommerce and Checkout Blocks.
 * Version: 0.4.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Text Domain: peptide-panapay
 */
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

define( 'PEPTIDE_PANAPAY_FILE', __FILE__ );
define( 'PEPTIDE_PANAPAY_DIR', __DIR__ );
define( 'PEPTIDE_PANAPAY_VERSION', '0.4.0' );

spl_autoload_register( static function ( $class ) {
	$prefix = __NAMESPACE__ . '\\';
	if ( ! str_starts_with( $class, $prefix ) ) { return; }
	$name = substr( $class, strlen( $prefix ) );
	if ( ! preg_match( '/^[A-Z][A-Za-z_]+$/D', $name ) ) { return; }
	$file = __DIR__ . '/includes/class-' . str_replace( '_', '-', strtolower( $name ) ) . '.php';
	if ( is_file( $file ) ) { require_once $file; }
} );

register_activation_hook( __FILE__, static function () {
	if ( defined( 'PEPTIDE_HQ_FILE' ) && class_exists( '\PeptideHq\Sites' ) ) { Hub_Store::install(); }
	elseif ( class_exists( '\WC_Payment_Gateway' ) ) { Store::install(); }
	else { throw new \RuntimeException( 'Activate Peptide HQ or WooCommerce before the payment connector.' ); }
} );
register_deactivation_hook( __FILE__, static function () { wp_clear_scheduled_hook( Hub::CRON ); } );
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );
add_action( 'plugins_loaded', static function () {
	if ( defined( 'PEPTIDE_HQ_FILE' ) && class_exists( '\PeptideHq\Sites' ) ) {
		Hub::register_hooks(); Hub_Page::register_hooks();
		return;
	}
	if ( ! class_exists( '\WC_Payment_Gateway' ) ) { return; }
	Bridge::register_hooks();
	Store_Admin::register_hooks();
	Checkout_Fields::register_hooks();
	add_action( 'woocommerce_admin_order_data_after_order_details', array( Admin::class, 'order_details' ) );
	add_filter( 'woocommerce_payment_gateways', static function ( $gateways ) {
		foreach ( Gateway::account_configurations() as $id => $config ) {
			$gateway = new Gateway( $id, $config );
			$gateways[] = $gateway;
			add_action( 'woocommerce_receipt_' . $gateway->id, array( Callbacks::class, 'receipt' ) );
		}
		return $gateways;
	} );
	// These are registered independently of lazy gateway construction.
	add_action( 'woocommerce_api_pc_panapay_notify', array( Callbacks::class, 'notify' ) );
	add_action( 'woocommerce_api_pc_panapay_return', array( Callbacks::class, 'returned' ) );
	add_action( 'woocommerce_receipt_pc_panapay', array( Callbacks::class, 'receipt' ) );
	add_action( 'template_redirect', array( Callbacks::class, 'protect_payment_pages' ), 0 );
	add_action( 'admin_init', static function () {
		if ( current_user_can( 'activate_plugins' ) && ! Store::installed() ) { Store::install(); }
	} );
	add_action( 'woocommerce_blocks_payment_method_type_registration', static function ( $registry ) {
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			foreach ( Gateway::known_ids() as $id ) {
				if ( ! $registry->is_registered( $id ) ) { $registry->register( new Blocks( $id ) ); }
			}
		}
	} );
}, 20 );
