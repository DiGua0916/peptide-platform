<?php
/**
 * 平台工具（用户 2026-10-27：总后台的操作都放到总后台，店铺后台不让销售看到，前后台彻底隔开）。
 * 这些是店铺上的 WordPress / WooCommerce 平台页面，店铺后台的菜单里没有；在总后台点一下，一键登录后直接打开。
 * 键要和店铺 peptide-core 的 Hq_Connector::PLATFORM_PAGES 一致。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Platform {

	/**
	 * @return array<string,array{0:string,1:string}> 键 => [名称, 说明]
	 */
	public static function tools(): array {
		return array(
			'plugins'     => array( __( 'Plugins', 'peptide-hq' ), __( 'Install, turn on or off and update plugins.', 'peptide-hq' ) ),
			'themes'      => array( __( 'Themes', 'peptide-hq' ), __( 'The store theme. Change it only together with the theme developer.', 'peptide-hq' ) ),
			'updates'     => array( __( 'Updates', 'peptide-hq' ), __( 'WordPress, plugin and theme updates.', 'peptide-hq' ) ),
			'status'      => array( __( 'System status', 'peptide-hq' ), __( 'Server, database and plugin information for troubleshooting.', 'peptide-hq' ) ),
			'scheduler'   => array( __( 'Scheduled actions', 'peptide-hq' ), __( 'Background jobs such as pixel sends and reminder emails.', 'peptide-hq' ) ),
			'health'      => array( __( 'Site health', 'peptide-hq' ), __( 'Security and performance checks from WordPress.', 'peptide-hq' ) ),
			'wc_settings' => array( __( 'WooCommerce settings', 'peptide-hq' ), __( 'General, products, accounts and advanced WooCommerce options.', 'peptide-hq' ) ),
			'pc_settings' => array( __( 'VialCove settings', 'peptide-hq' ), __( 'Brand name, demo mode, use statement, checkout confirmation, batch check, support button.', 'peptide-hq' ) ),
			'general'     => array( __( 'WordPress general settings', 'peptide-hq' ), __( 'Tagline, site address, date format.', 'peptide-hq' ) ),
			'reading'     => array( __( 'Reading', 'peptide-hq' ), __( 'What the home page shows.', 'peptide-hq' ) ),
			'permalinks'  => array( __( 'Permalinks', 'peptide-hq' ), __( 'The address format of pages and products.', 'peptide-hq' ) ),
			'privacy'     => array( __( 'Privacy policy page', 'peptide-hq' ), __( 'Which page is the privacy policy.', 'peptide-hq' ) ),
			'discussion'  => array( __( 'Discussion', 'peptide-hq' ), __( 'Review moderation and blocked words.', 'peptide-hq' ) ),
			'export_data' => array( __( 'Export personal data', 'peptide-hq' ), __( 'When a customer asks for a copy of their data.', 'peptide-hq' ) ),
			'erase_data'  => array( __( 'Erase personal data', 'peptide-hq' ), __( 'When a customer asks to delete their data.', 'peptide-hq' ) ),
			'users'       => array( __( 'All accounts on the store', 'peptide-hq' ), __( 'Administrators, salespeople and customers (WordPress user list).', 'peptide-hq' ) ),
		);
	}
}
