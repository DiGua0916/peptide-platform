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
		Monitor::register_hooks();
		Register::register_hooks();
		Operations::register_hooks();
		if ( is_callable( array( Admin\Menu::class, 'register_login_hooks' ) ) ) {
			Admin\Menu::register_login_hooks();
		}
		if ( is_admin() ) {
			Admin\Menu::register_hooks();
			Admin\Operations_Page::hooks();
		}
	}
}
