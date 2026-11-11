<?php
/**
 * 总后台插件入口。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		load_plugin_textdomain( 'peptide-hq', false, dirname( plugin_basename( PEPTIDE_HQ_FILE ) ) . '/languages' );
		Db::maybe_upgrade();
		Site_Lifecycle::register_hooks();
		Monitor::register_hooks();
		Register::register_hooks();
		Operations::register_hooks();
		Store_Plugins::register_hooks();
		Cdn::register_hooks();
		Design_Push::register_hooks();
		Mail::register_hooks();
		Costs::register_hooks();
		Ai::register_hooks();
		Fedex::register_hooks();
		Track17::register_hooks();
		Series::register_hooks(); // M3 系列与商品目录（系列、推送、系列管理页都从这里启动）。
		Orders::register_hooks(); // M10 订单汇总、报表与退款（订单上报、退款标记、订单页和报表页都从这里启动）。
		Creatives::register_hooks(); // CR 素材归因：各店每日素材汇总、属性词表下发。
		if ( is_callable( array( Admin\Menu::class, 'register_login_hooks' ) ) ) {
			Admin\Menu::register_login_hooks();
		}
		if ( is_admin() ) {
			Admin\Menu::register_hooks();
			Admin\Operations_Page::hooks();
			Admin\Provision_Page::hooks();
			Admin\Lifecycle_Page::hooks();
			Admin\Plugins_Page::hooks();
			Admin\Cdn_Page::hooks();
			Admin\Design_Page::hooks();
			Admin\Mail_Page::hooks();
			Admin\Clone_Page::hooks();
			Admin\Costs_Page::hooks();
			Admin\Ai_Page::hooks();
			Admin\Fedex_Page::hooks();
		}
	}
}
