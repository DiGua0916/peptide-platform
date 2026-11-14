<?php
/**
 * Checkout assist (site 1 P1-1 / P1-6, signed off 2026-11-14).
 *
 * - Loads assets/checkout-assist.js on the block checkout: saves and refills what the shopper typed,
 *   US ZIP fills the state, phone note + inline check, "Pay $X" with "You save $X", and a payment-failure line.
 * - New visitors start with country US and no state (no default California).
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Checkout_Assist {

	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ), 150 );
		add_filter( 'woocommerce_customer_default_location', array( self::class, 'default_location' ), 20 );
	}

	/** Country only; the state stays empty until the shopper (or their ZIP) sets it. */
	public static function default_location( $location ): string {
		$country = is_string( $location ) ? strtoupper( substr( $location, 0, 2 ) ) : '';
		return ( '' === $country || 'US' === $country ) ? 'US' : $country;
	}

	public static function assets(): void {
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() ) {
			return;
		}
		foreach ( array( 'css', 'js' ) as $ext ) {
			$file = 'assets/checkout-assist.' . $ext;
			$url  = plugins_url( $file, PEPTIDE_CORE_FILE );
			$ver  = (string) filemtime( PEPTIDE_CORE_DIR . '/' . $file );
			if ( 'css' === $ext ) {
				wp_enqueue_style( 'pc-checkout-assist', $url, array(), $ver );
			} else {
				wp_enqueue_script( 'pc-checkout-assist', $url, array( 'wp-data', 'wc-blocks-data-store' ), $ver, true );
			}
		}
	}
}
