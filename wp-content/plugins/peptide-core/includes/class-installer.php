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

	public static function activate(): void {
		Capabilities::grant();
		Private_Files::ensure_dir();
		update_option( self::OPTION_VERSION, PEPTIDE_CORE_VERSION, false );
	}

	/**
	 * 插件代码更新后（版本号变化）补授新增的能力，不必停用再启用。
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION_VERSION ) !== PEPTIDE_CORE_VERSION ) {
			self::activate();
		}
	}

	/**
	 * 卸载时只移除能力和设置，不删除批次、报告和报告文件（避免误删真实资料）。
	 */
	public static function uninstall(): void {
		Capabilities::revoke();
		delete_option( self::OPTION_VERSION );
	}
}
