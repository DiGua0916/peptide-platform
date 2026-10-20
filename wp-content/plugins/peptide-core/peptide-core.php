<?php
/**
 * Plugin Name:       Peptide Core
 * Description:       Batches, test reports and WooCommerce integration for the peptide store.
 * Version:           0.7.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            DiGua
 * Text Domain:       peptide-core
 * Domain Path:       /languages
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

define( 'PEPTIDE_CORE_VERSION', '0.7.0' );
define( 'PEPTIDE_CORE_FILE', __FILE__ );
define( 'PEPTIDE_CORE_DIR', __DIR__ );

require_once __DIR__ . '/includes/autoload.php';

register_activation_hook( __FILE__, array( \PeptideCore\Installer::class, 'activate' ) );

// 声明兼容 WooCommerce 高性能订单存储（HPOS）。本插件只通过 WooCommerce CRUD 接口读写订单。
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PEPTIDE_CORE_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( \PeptideCore\Plugin::class, 'boot' ) );
