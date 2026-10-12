<?php
/**
 * 类自动加载：PeptideCore\Admin\Batch_Metabox => includes/admin/class-batch-metabox.php
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'PeptideCore\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class, strlen( $prefix ) ) );
		$name  = array_pop( $parts );
		$dirs  = array_map( 'strtolower', $parts );
		$file  = 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		$path  = __DIR__ . '/' . ( $dirs ? implode( '/', $dirs ) . '/' : '' ) . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
