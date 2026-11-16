<?php
/**
 * Plugin Name:       WP CAPI Stealth Bridge (V3.2 Ultimate Edition)
 * Description:       100% uncut zero-intrusion CAPI Network Plugin: Cloak real-IP synergy (`$wprlo_verified_client_ip`), pre-cashier buyer context freezing, 10-min `batch-check` delta reconciliation, 5-in-1 60-BM Master Hub Console, and Subsite Read-Only Media Buyer Heatmap.
 * Version:           3.2.2
 * Author:            Peptides Engineering (Dev B)
 * Network:           true
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.5
 */

defined( 'ABSPATH' ) || exit;

define( 'STEALTH_CAPI_BRIDGE_VERSION', '3.2.2' );
define( 'STEALTH_CAPI_BRIDGE_FILE', __FILE__ );
define( 'STEALTH_CAPI_BRIDGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'STEALTH_CAPI_BRIDGE_URL', plugin_dir_url( __FILE__ ) );

require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-config-manager.php';
require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-reconcile-cron.php';
require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-context-freezer.php';
require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-cashier-listener.php';
require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-rest-pulse-api.php';
require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-network-hub-ui.php';
require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-subsite-buyer-ui.php';

// 1. Declare WooCommerce HPOS (`custom_order_tables`) compatibility.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				STEALTH_CAPI_BRIDGE_FILE,
				true
			);
		}
	}
);

// 2. Lifecycle hooks for 10-minute `batch-check` reconciliation cron.
register_activation_hook(
	STEALTH_CAPI_BRIDGE_FILE,
	static function (): void {
		add_filter( 'cron_schedules', array( \StealthCapiBridge\Reconcile_Cron::class, 'add_cron_interval' ) );
		\StealthCapiBridge\Reconcile_Cron::ensure_cron_scheduled();
	}
);

register_deactivation_hook(
	STEALTH_CAPI_BRIDGE_FILE,
	array( \StealthCapiBridge\Reconcile_Cron::class, 'deactivate' )
);

// 3. Bootstrap runtime modules, 5-in-1 Hub UI & Subsite Buyer Read-Only Heatmap UI.
add_action(
	'plugins_loaded',
	static function (): void {
		\StealthCapiBridge\Reconcile_Cron::register_hooks();
		if ( \StealthCapiBridge\Config_Manager::is_connector_ready() ) {
			\StealthCapiBridge\Context_Freezer::register_hooks();
			\StealthCapiBridge\Cashier_Listener::register_hooks();
		}
		\StealthCapiBridge\REST_Pulse_API::register_hooks();
		\StealthCapiBridge\Network_Hub_UI::register_hooks();

		if ( is_admin() ) {
			\StealthCapiBridge\Subsite_Buyer_UI::register_hooks();
		}
	},
	15
);

// 4. Non-destructive runtime bypass of legacy `peptide-core` Facebook Pixel & direct Meta CAPI
//    (ensures 0 browser Meta Pixel & prevents double Purchase while keeping GA4/GAds/TikTok intact).
add_filter(
	'option_peptide_core_pixels',
	static function ( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$cfg    = \StealthCapiBridge\Config_Manager::get_config();
		$tenant = \StealthCapiBridge\Config_Manager::resolve_current_tenant();
		if ( empty( $cfg['suppress_legacy_meta'] ) || ! $tenant ) {
			return $value;
		}
		foreach ( array( 'facebook', 'meta' ) as $fb_key ) {
			if ( isset( $value[ $fb_key ] ) ) {
				unset( $value[ $fb_key ] );
			}
			if ( isset( $value['platforms'][ $fb_key ] ) ) {
				unset( $value['platforms'][ $fb_key ] );
			}
		}
		return $value;
	},
	99
);

// 5. Server-side first-party cookie capture on landing (`?fbclid=...`).
//    Strictly disabled on `ORGANIC_RETENTION_HUB` (December Station #2 = 0 cookies).
add_action(
	'init',
	static function (): void {
		if ( is_admin() || headers_sent() ) {
			return;
		}
		$tenant = \StealthCapiBridge\Config_Manager::resolve_current_tenant();
		if ( ! $tenant || \StealthCapiBridge\Config_Manager::is_organic_retention_hub( $tenant ) ) {
			return;
		}
		$now_ms = (int) round( microtime( true ) * 1000 );
		if ( empty( $_COOKIE['_capi_fbp'] ) ) {
			$rand10 = (string) wp_rand( 1000000000, 9999999999 );
			$fbp    = 'fb.1.' . $now_ms . '.' . $rand10;
			setcookie( '_capi_fbp', $fbp, time() + 7776000, '/', '', is_ssl(), false );
			$_COOKIE['_capi_fbp'] = $fbp;
		}
		if ( ! empty( $_GET['fbclid'] ) && is_string( $_GET['fbclid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$fbclid    = trim( sanitize_text_field( wp_unslash( $_GET['fbclid'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$candidate = 'fb.1.' . $now_ms . '.' . $fbclid;
			if ( '' !== \StealthCapiBridge\Config_Manager::sanitize_fb_cookie( $candidate ) ) {
				setcookie( '_capi_fbc', $candidate, time() + 7776000, '/', '', is_ssl(), false );
				$_COOKIE['_capi_fbc'] = $candidate;
			}
		}
	},
	1
);

// 6. Auto-enqueue `<3KB` fp-pulse.js on `AD_ACQUISITION` stores ONLY (0 scripts on `ORGANIC_RETENTION_HUB`).
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		$cfg    = \StealthCapiBridge\Config_Manager::get_config();
		$tenant = \StealthCapiBridge\Config_Manager::resolve_current_tenant();
		if ( empty( $cfg['auto_enqueue_sdk'] ) || ! $tenant || \StealthCapiBridge\Config_Manager::is_organic_retention_hub( $tenant ) ) {
			return;
		}
		wp_register_script(
			'stealth-capi-fp-pulse',
			STEALTH_CAPI_BRIDGE_URL . 'assets/fp-pulse.js',
			array(),
			STEALTH_CAPI_BRIDGE_VERSION,
			true
		);
		$inline_cfg = array(
			'endpoint'     => esc_url_raw( rest_url( 'stealth-capi/v1/pulse' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'autoPageView' => true,
		);
		if ( function_exists( 'is_product' ) && is_product() && function_exists( 'wc_get_product' ) ) {
			$prod_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
			$product = $prod_id > 0 ? wc_get_product( $prod_id ) : null;
			if ( $product instanceof \WC_Product ) {
				$raw_sku                = (string) $product->get_sku();
				$raw_sku                = '' !== $raw_sku ? $raw_sku : 'WC_' . $product->get_id();
				$price                  = round( (float) $product->get_price(), 2 );
				$inline_cfg['viewItem'] = array(
					'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
					'total_value' => $price,
					'items'       => array(
						array(
							'raw_sku'    => $raw_sku,
							'quantity'   => 1,
							'unit_price' => $price,
						),
					),
				);
			}
		}
		wp_add_inline_script(
			'stealth-capi-fp-pulse',
			'window.STEALTH_CAPI_CFG=' . wp_json_encode( $inline_cfg, JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);
		wp_enqueue_script( 'stealth-capi-fp-pulse' );
	},
	5
);
