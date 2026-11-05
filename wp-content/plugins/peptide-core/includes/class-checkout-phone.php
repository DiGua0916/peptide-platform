<?php
/** Native checkout contact policy shared by Blocks and server validation. */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Checkout_Phone {

	private static bool $registered = false;

	public static function register_hooks(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;
		// Woo reads this same option for Blocks' defaultFields, country address
		// requirements and Store API validation. Do not add another phone input
		// or silently rewrite customer numbers / persisted merchant settings.
		add_filter( 'pre_option_woocommerce_checkout_phone_field', array( self::class, 'visibility' ), 20 );
	}

	/** A delivery contact is required for every checkout, regardless of gateway. */
	public static function visibility( $pre_option ): string {
		return 'required';
	}
}
