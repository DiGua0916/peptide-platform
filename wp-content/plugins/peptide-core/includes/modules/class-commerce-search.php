<?php
namespace PeptideCore\Modules;
defined( 'ABSPATH' ) || exit;
final class Commerce_Search {
	public static function register_hooks(): void { add_action( 'init', array( self::class, 'clean_prices' ), 1 ); }
	/** Woo reads GET directly. Empty optional bounds must be absent, while an explicit zero remains valid. */
	public static function clean_prices(): void {
		if ( is_admin() || 'product' !== ( $_GET['post_type'] ?? '' ) ) { return; }
		foreach ( array( 'min_price', 'max_price' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && ( ! is_scalar( $_GET[ $key ] ) || '' === trim( (string) $_GET[ $key ] ) ) ) { unset( $_GET[ $key ], $_REQUEST[ $key ] ); }
		}
	}
}
