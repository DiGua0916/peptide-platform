<?php
/**
 * 后台页面内对话框：显式异步确认，保留取消及服务端权限检查。
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
		add_filter( 'script_loader_src', array( self::class, 'asset_version' ), 20 );
	}

	public static function asset_version( string $src ): string {
		$prefix = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		if ( ! is_admin() || ! str_starts_with( $src, $prefix ) ) {
			return $src;
		}
		$name = basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
		$file = PEPTIDE_CORE_DIR . '/assets/' . $name;
		return is_file( $file ) ? add_query_arg( 'vc', (string) filemtime( $file ), $src ) : $src;
	}

	public static function enqueue(): void {
		$url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$ver = PEPTIDE_CORE_VERSION . '.' . (int) filemtime( PEPTIDE_CORE_DIR . '/assets/admin-dialogs.js' );

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
