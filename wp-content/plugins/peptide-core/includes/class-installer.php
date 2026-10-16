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
			update_option( self::OPTION_VERSION, PEPTIDE_CORE_VERSION, false );
		}
	}

	/**
	 * 卸载：移除能力、版本记录和插件设置；不删除批次、报告和报告文件（避免误删真实资料）。
	 */
	public static function uninstall(): void {
		Capabilities::revoke();
		delete_option( self::OPTION_VERSION );
		delete_option( Settings::OPTION );
		delete_option( Capabilities::GRANTED_OPTION );
	}
}
