<?php
/**
 * Plugin Name:       Peptide HQ
 * Description:       Master admin for all VialCove stores: sales and orders of every store, alerts, one-click login and salesperson accounts.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            DiGua
 * Text Domain:       peptide-hq
 * Domain Path:       /languages
 *
 * 总后台（用户 2026-10-27）：装在单独的 WordPress 上，不装在店铺上。每个店铺用 peptide-core 的“总后台连接”接进来。
 *
 * @package PeptideHq
 */

defined( 'ABSPATH' ) || exit;

define( 'PEPTIDE_HQ_VERSION', '0.1.0' );
define( 'PEPTIDE_HQ_FILE', __FILE__ );
define( 'PEPTIDE_HQ_DIR', __DIR__ );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'PeptideHq\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class, strlen( $prefix ) ) );
		$name  = array_pop( $parts );
		$dirs  = array_map( 'strtolower', $parts );
		$path  = __DIR__ . '/includes/' . ( $dirs ? implode( '/', $dirs ) . '/' : '' ) . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( \PeptideHq\Db::class, 'install' ) );
register_deactivation_hook( __FILE__, array( \PeptideHq\Monitor::class, 'unschedule' ) );
add_action( 'plugins_loaded', array( \PeptideHq\Plugin::class, 'boot' ) );
