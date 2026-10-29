<?php
/** Supplement new customer-admin strings without replacing the existing language catalogue. */
namespace PeptideCore;
defined( 'ABSPATH' ) || exit;
final class Customer_Admin_I18n {
	public static function register_hooks(): void {
		add_filter( 'gettext', array( self::class, 'translate' ), 15, 3 );
	}
	public static function translate( string $translated, string $text, string $domain ): string {
		if ( 'peptide-core' !== $domain || ! str_starts_with( determine_locale(), 'zh' ) ) { return $translated; }
		static $map = null;
		if ( null === $map ) {
			$map = array();
			foreach ( glob( PEPTIDE_CORE_DIR . '/languages/customer-*-zh_CN.php' ) ?: array() as $path ) {
				$part = require $path;
				if ( is_array( $part ) ) { $map = array_merge( $map, $part ); }
			}
		}
		return $map[ $text ] ?? $translated;
	}
}
