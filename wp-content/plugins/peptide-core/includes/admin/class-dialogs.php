<?php
/**
 * 后台页面内对话框：用页面内对话框代替浏览器原生的确认框和提示框（如 WooCommerce 的退款确认）。
 * 实现原理见 assets/admin-dialogs.js 顶部注释。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

defined( 'ABSPATH' ) || exit;

final class Dialogs {

	public const HANDLE = 'peptide-core-admin-dialogs';

	public static function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function enqueue(): void {
		$url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$ver = PEPTIDE_CORE_VERSION;

		wp_enqueue_style( self::HANDLE, $url . 'admin-dialogs.css', array(), $ver );
		wp_enqueue_script( self::HANDLE, $url . 'admin-dialogs.js', array(), $ver, array( 'in_footer' => false ) );
		wp_localize_script(
			self::HANDLE,
			'pcAdminDialogs',
			array(
				'confirmTitle' => __( 'Please confirm', 'peptide-core' ),
				'alertTitle'   => __( 'Notice', 'peptide-core' ),
				'ok'           => __( 'OK', 'peptide-core' ),
				'cancel'       => __( 'Cancel', 'peptide-core' ),
			)
		);
	}
}
