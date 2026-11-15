<?php
/**
 * Checkout assist (site 1 P1-1 / P1-6, signed off 2026-11-14).
 *
 * - Loads assets/checkout-assist.js on the block checkout: saves and refills what the shopper typed,
 *   US ZIP fills the state, phone note + inline check, "Pay $X" with "You save $X", and a payment-failure line.
 * - New visitors start with country US and no state (no default California).
 * - The saved details are removed from the browser on the order-received page.
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
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		// Order placed: forget the saved checkout details (name, address, phone) so they don't stay on a shared phone.
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			wp_register_script( 'pc-checkout-assist-clear', false, array(), PEPTIDE_CORE_VERSION, true );
			wp_enqueue_script( 'pc-checkout-assist-clear' );
			wp_add_inline_script( 'pc-checkout-assist-clear', "try{localStorage.removeItem('pcCheckoutDraft')}catch(e){}" );
			return;
		}
		if ( is_wc_endpoint_url() ) {
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
