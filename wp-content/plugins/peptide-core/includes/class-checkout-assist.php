<?php
/**
 * Checkout assist:
 * - 30-day browser draft (contact, shipping, billing, coupons, order note) + auto-refill
 * - US 5-digit ZIP Code prioritized before City/State + offline 40,979-ZIP City & State auto-fill
 * - Mobile sticky bottom Pay bar
 * - Payment-error emergency WhatsApp specialist rescue + external browser resume link
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Checkout_Assist {

	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ), 150 );
		add_filter( 'woocommerce_customer_default_location', array( self::class, 'default_location' ), 20 );
		add_filter( 'woocommerce_default_address_fields', array( self::class, 'prioritize_zip_field' ), 30 );
		add_filter( 'woocommerce_get_country_locale', array( self::class, 'us_locale_zip_priority' ), 30 );
	}

	/** Country only; state & city fill automatically when the shopper enters their 5-digit US ZIP. */
	public static function default_location( $location ): string {
		$country = is_string( $location ) ? strtoupper( substr( $location, 0, 2 ) ) : '';
		return ( '' === $country || 'US' === $country ) ? 'US' : $country;
	}

	/** P1-4: Place ZIP/Postal Code (priority 65) right after Street Address (50/60) and before City (70) & State (80). */
	public static function prioritize_zip_field( array $fields ): array {
		if ( isset( $fields['postcode'] ) && is_array( $fields['postcode'] ) ) {
			$fields['postcode']['priority'] = 65;
		}
		if ( isset( $fields['city'] ) && is_array( $fields['city'] ) ) {
			$fields['city']['priority'] = 70;
		}
		if ( isset( $fields['state'] ) && is_array( $fields['state'] ) ) {
			$fields['state']['priority'] = 80;
		}
		return $fields;
	}

	public static function us_locale_zip_priority( array $locales ): array {
		foreach ( array( 'US', 'PR' ) as $cc ) {
			if ( ! isset( $locales[ $cc ] ) || ! is_array( $locales[ $cc ] ) ) {
				$locales[ $cc ] = array();
			}
			$locales[ $cc ]['postcode'] = array_merge(
				(array) ( $locales[ $cc ]['postcode'] ?? array() ),
				array( 'priority' => 65 )
			);
			$locales[ $cc ]['city']     = array_merge(
				(array) ( $locales[ $cc ]['city'] ?? array() ),
				array( 'priority' => 70 )
			);
			$locales[ $cc ]['state']    = array_merge(
				(array) ( $locales[ $cc ]['state'] ?? array() ),
				array( 'priority' => 80 )
			);
		}
		return $locales;
	}

	public static function assets(): void {
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			wp_register_script( 'pc-checkout-assist-clear', false, array(), PEPTIDE_CORE_VERSION, true );
			wp_enqueue_script( 'pc-checkout-assist-clear' );
			wp_add_inline_script( 'pc-checkout-assist-clear', "try{localStorage.removeItem('pcCheckoutDraft');localStorage.removeItem('vcBagSnapshot');}catch(e){}" );
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
				$wa_link = class_exists( Emails\Chase_Copy::class ) ? Emails\Chase_Copy::whatsapp_link() : '';
				$wa_num  = class_exists( Emails\Chase_Copy::class ) ? Emails\Chase_Copy::whatsapp() : '';
				$resumed = WC()->session ? (string) WC()->session->get( 'pc_resumed_note', '' ) : '';
				if ( '' !== $resumed && WC()->session ) {
					WC()->session->set( 'pc_resumed_note', null );
				}
				wp_add_inline_script(
					'pc-checkout-assist',
					'window.PCCheckoutAssist=' . wp_json_encode(
						array(
							'zipBaseUrl'     => trailingslashit( plugins_url( 'assets/zip-us', PEPTIDE_CORE_FILE ) ),
							'resumeEndpoint' => rest_url( 'peptide/v1/checkout-resume' ),
							'whatsappUrl'    => $wa_link,
							'whatsappNumber' => $wa_num,
							'resumed'        => isset( $_GET['pc_resumed'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
							'resumedNote'    => $resumed,
						),
						JSON_HEX_TAG | JSON_HEX_AMP
					) . ';',
					'before'
				);
			}
		}
	}
}
