<?php
/**
 * 启用与卸载时的一次性操作。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public const OPTION_VERSION = 'peptide_core_version';

	/**
	 * 启用插件：授予全部能力（包括管理员手动移除过的），准备私有目录。
	 */
	public static function activate(): void {
		Capabilities::grant();
		Private_Files::ensure_dir();
		update_option( self::OPTION_VERSION, PEPTIDE_CORE_VERSION, false );
	}

	/**
	 * 插件代码更新后（版本号变化）：只补授这次新增的能力，不恢复管理员手动从某个角色移除的能力。
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION_VERSION ) !== PEPTIDE_CORE_VERSION ) {
			Capabilities::grant( true );
			Private_Files::ensure_dir();
			// 访客追踪的表只在开启过追踪后存在；结构有更新时同步。
			if ( get_option( Tracking\Schema::OPTION ) && ! Tracking\Schema::installed() ) {
				Tracking\Schema::install();
			}
			update_option( self::OPTION_VERSION, PEPTIDE_CORE_VERSION, false );
		}
		// 一次性数据修复（2026-11-13）：交给后台定时任务跑，不拖慢前台请求；跑过就只读一个自动加载的选项。
		if ( '1' !== (string) get_option( Tracking\Schema::FIRST_VISIT_FIX, '' ) && get_option( Tracking\Schema::OPTION ) && ! wp_next_scheduled( Tracking\Schema::FIRST_VISIT_CRON ) ) {
			wp_schedule_single_event( time(), Tracking\Schema::FIRST_VISIT_CRON );
		}
	}

	/**
	 * 卸载：移除能力、版本记录和插件设置；不删除批次、报告、报告文件和装修模板（避免误删真实资料）。
	 */
	public static function uninstall(): void {
		Capabilities::revoke();
		delete_option( self::OPTION_VERSION );
		delete_option( Settings::OPTION );
		delete_option( Capabilities::GRANTED_OPTION );
		delete_option( Templates::ACTIVE_OPTION );
		delete_option( Templates::DOMAIN_OPTION );
	}
}
