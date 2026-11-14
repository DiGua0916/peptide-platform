<?php
/**
 * Plugin Name:       Peptide Core
 * Description:       Batches, test reports and WooCommerce integration for the peptide store.
 * Version:           0.33.1
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

define( 'PEPTIDE_CORE_VERSION', '0.33.1' );
define( 'PEPTIDE_CORE_FILE', __FILE__ );
define( 'PEPTIDE_CORE_DIR', __DIR__ );

require_once __DIR__ . '/includes/autoload.php';

// 2026-11-14 提速：这些设置（很多在库里根本不存在）每个请求都会被逐个单独查一次，后台约 50 次、前台约 70 次。
// 在 WooCommerce 加载之前用一条查询全部取回（不存在的也记成“没有”），之后的 get_option 都直接用缓存。
if ( function_exists( 'wp_prime_option_caches' ) ) {
	wp_prime_option_caches( array( '_fluentsmtp_intended_outlook_info', 'https_migration_required', 'pc_accounts', 'pc_buyer_recognition', 'pc_campaign_countdown_v1', 'pc_cart_links', 'pc_email_campaigns_schema', 'pc_entry_address', 'pc_first_order_offer', 'pc_mail_observation_schema', 'pc_paid_popup', 'pc_panapay_central_managed', 'pc_panapay_db_version', 'pc_postal_assist', 'pc_public_brand', 'pc_ship_preview', 'pc_stock_range', 'pc_storefront_language_settings', 'pc_storefront_translation_enabled', 'pc_subscriptions_schema', 'pc_track_ball', 'peptide_core_content_tools', 'peptide_core_coupons_page', 'peptide_core_exit_intent', 'peptide_core_flush_rewrites', 'peptide_core_hq', 'peptide_core_loyalty', 'peptide_core_loyalty_schema', 'peptide_core_pixels', 'peptide_core_promotions_page', 'peptide_core_recovery_db', 'peptide_core_seo', 'peptide_core_site_tools', 'peptide_core_store_config', 'peptide_core_terms', 'peptide_core_tracking', 'peptide_core_tracking_db', 'peptide_core_version', 'site_logo', 'wc_feature_woocommerce_additional_variation_images_enabled', 'wc_feature_woocommerce_brands_enabled', 'woocommerce_address_autocomplete_provider', 'woocommerce_bacs_settings', 'woocommerce_brand_permalink', 'woocommerce_cheque_settings', 'woocommerce_cod_settings', 'woocommerce_custom_orders_table_background_sync_mode', 'woocommerce_custom_orders_table_data_sync_enabled', 'woocommerce_customer_verify_email_settings', 'woocommerce_demo_store', 'woocommerce_email_from_address', 'woocommerce_enable_delayed_account_creation', 'woocommerce_pc_admin_abandoned_settings', 'woocommerce_pc_admin_duplicate_settings', 'woocommerce_pc_admin_offline_settings', 'woocommerce_pc_browse_reminder_settings', 'woocommerce_pc_cart_recovery_1_settings', 'woocommerce_pc_cart_recovery_2_settings', 'woocommerce_pc_cart_recovery_3_settings', 'woocommerce_pc_panapay_settings', 'woocommerce_pc_register_invite_settings', 'woocommerce_pc_register_verify_settings', 'woocommerce_pickup_location_settings', 'woocommerce_pos_store_name', 'woocommerce_thumbnail_cropping', 'woocommerce_analytics_uses_old_full_refund_data', 'woocommerce_analytics_show_old_refund_data_tool', 'woocommerce_show_marketplace_suggestions', 'woocommerce_actionable_order_statuses', 'medium_crop', 'medium_large_crop', 'large_crop', 'woocommerce_bacs_accounts', 'woocommerce_registration_generate_password', 'woocommerce_customer_cancelled_order_settings' ) );
}

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
