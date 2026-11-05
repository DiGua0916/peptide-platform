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
		add_filter( 'script_loader_src', array( self::class, 'wordpress_dialogs' ), 18 );
		add_filter( 'script_loader_src', array( self::class, 'asset_version' ), 20 );
		add_filter( 'script_loader_src', array( self::class, 'vendor_dialogs' ), 19 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'vendor_chunks' ), 10000 );
		add_action( 'admin_print_scripts', array( self::class, 'vendor_chunks' ), 19 );
		add_action( 'admin_print_footer_scripts', array( self::class, 'vendor_chunks' ), 19 );
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

	private static function vendor_manifest(): ?array {
		static $manifest = null;
		if ( null === $manifest ) {
			$manifest = json_decode( (string) file_get_contents( PEPTIDE_CORE_DIR . '/assets/woocommerce-dialogs/manifest.json' ), true );
		}
		return is_array( $manifest ) ? $manifest : null;
	}

	/** Keep WooCommerce files untouched; serve reviewed, hash-matched dialog adapters. */
	public static function vendor_dialogs( string $src ): string {
		if ( ! is_admin() || ! defined( 'WC_PLUGIN_FILE' ) || ! defined( 'WC_VERSION' ) ) { return $src; }
		$prefix = plugins_url( '', WC_PLUGIN_FILE ) . '/';
		if ( ! str_starts_with( $src, $prefix ) ) { return $src; }
		$relative = (string) wp_parse_url( substr( $src, strlen( $prefix ) ), PHP_URL_PATH );
		$manifest = self::vendor_manifest();
		if ( ! $manifest || $manifest['version'] !== WC_VERSION ) { return $src; }
		$record = $manifest['extraFiles'][ $relative ] ?? null;
		if ( $record ) {
			$name = $record['target'];
		} elseif ( str_starts_with( $relative, 'assets/js/admin/' ) ) {
			$name = str_replace( '.min.js', '.js', basename( $relative ) );
			$relative = 'assets/js/admin/' . $name;
			$record = $manifest['files'][ $name ] ?? null;
		}
		if ( ! $record ) { return $src; }
		$file = dirname( WC_PLUGIN_FILE ) . '/' . $relative;
		if ( ! is_file( $file ) || hash_file( 'sha256', $file ) !== $record['upstream'] ) { return $src; }
		return plugins_url( 'assets/woocommerce-dialogs/' . $name, PEPTIDE_CORE_FILE ) . '?ver=' . substr( $record['adapted'], 0, 16 );
	}

	/** Register reviewed JSONP modules before Woo's client runtime consumes its chunk queue. */
	public static function vendor_chunks(): void {
		global $wp_scripts;
		if ( ! is_admin() || ! defined( 'WC_PLUGIN_FILE' ) || ! defined( 'WC_VERSION' ) || ! is_object( $wp_scripts ) ) { return; }
		$manifest = self::vendor_manifest();
		if ( ! $manifest || $manifest['version'] !== WC_VERSION ) { return; }
		$handles = array();
		foreach ( $manifest['preloadChunks'] ?? array() as $relative ) {
			$record = $manifest['extraFiles'][ $relative ] ?? null;
			$file = dirname( WC_PLUGIN_FILE ) . '/' . $relative;
			if ( ! $record || ! is_file( $file ) || hash_file( 'sha256', $file ) !== $record['upstream'] ) { continue; }
			$handle = 'peptide-core-dialog-chunk-' . substr( hash( 'sha256', $relative ), 0, 12 );
			if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
				wp_register_script( $handle, plugins_url( 'assets/woocommerce-dialogs/' . $record['target'], PEPTIDE_CORE_FILE ), array(), substr( $record['adapted'], 0, 16 ), false );
			}
			$handles[] = $handle;
		}
		$prefix = plugins_url( 'assets/client/admin/', WC_PLUGIN_FILE );
		foreach ( $wp_scripts->registered as $script ) {
			if ( ! is_string( $script->src ) || ! str_starts_with( $script->src, $prefix ) ) { continue; }
			foreach ( $handles as $handle ) {
				if ( ! in_array( $handle, $script->deps, true ) ) { $script->deps[] = $handle; }
			}
		}
	}

	/** Hash-matched WordPress adapters keep core files and beforeunload guards intact. */
	public static function wordpress_dialogs( string $src ): string {
		global $wp_version;
		if ( ! is_admin() ) { return $src; }
		$name = str_replace( '.min.js', '.js', basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) );
		$relative = null;
		if ( str_starts_with( $src, admin_url( 'js/' ) ) || str_starts_with( '/' . ltrim( $src, '/' ), '/wp-admin/js/' ) ) { $relative = 'wp-admin/js/' . $name; }
		elseif ( str_starts_with( $src, includes_url( 'js/' ) ) || str_starts_with( '/' . ltrim( $src, '/' ), '/' . WPINC . '/js/' ) ) { $relative = WPINC . '/js/' . $name; }
		if ( null === $relative ) { return $src; }
		static $manifest = null;
		if ( null === $manifest ) { $manifest = json_decode( (string) file_get_contents( PEPTIDE_CORE_DIR . '/assets/wordpress-dialogs/manifest.json' ), true ); }
		if ( ! is_array( $manifest ) || $manifest['version'] !== $wp_version || ! isset( $manifest['files'][ $relative ] ) ) { return $src; }
		$file = ABSPATH . $relative;
		$record = $manifest['files'][ $relative ];
		if ( ! is_file( $file ) || hash_file( 'sha256', $file ) !== $record['upstream'] ) { return $src; }
		return plugins_url( 'assets/wordpress-dialogs/' . $name, PEPTIDE_CORE_FILE ) . '?ver=' . substr( $record['adapted'], 0, 16 );
	}

	public static function enqueue(): void {
		// Keep adapted core dependencies in their normal order rather than load-scripts.php concatenation.
		$GLOBALS['concatenate_scripts'] = false;
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
