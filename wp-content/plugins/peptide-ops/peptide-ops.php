<?php
/**
 * Plugin Name: VialCove Operations Agent
 * Description: Private authenticated maintenance worker for VialCove managed sites.
 * Version: 0.1.0
 * Requires PHP: 8.1
 */
defined( 'ABSPATH' ) || exit;
if ( defined( 'PEPTIDE_OPS_DIR' ) ) { return; }
define( 'PEPTIDE_OPS_DIR', __DIR__ );
define( 'PEPTIDE_OPS_VERSION', '0.1.0' );
spl_autoload_register( static function ( $class ): void {
	$prefix = 'PeptideOps\\';
	if ( ! str_starts_with( $class, $prefix ) ) { return; }
	$name = substr( $class, strlen( $prefix ) );
	if ( ! preg_match( '/^[A-Za-z_]+$/D', $name ) ) { return; }
	$file = __DIR__ . '/includes/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
	if ( is_readable( $file ) ) { require_once $file; }
} );
add_action( 'plugins_loaded', static function (): void {
	if ( class_exists( '\PeptideOps\Runtime' ) ) { \PeptideOps\Runtime::register_hooks(); }
}, 25 );
