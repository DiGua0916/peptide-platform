<?php
/**
 * Dealer prompt at $600 (site 1 P1-4, signed off 2026-11-14).
 *
 * - The only trigger: after an add-to-cart, the item total (before coupons) reaches the threshold (default $600).
 * - One popup per visit: "You've unlocked free shipping" + "Buying for resale?" + a WhatsApp button whose
 *   message carries the cart lines, the total and the ad creative code (cr). No prices or discounts are shown.
 * - After it is closed, the block cart page keeps a one-line link to the same WhatsApp chat.
 * - Uses the store's support WhatsApp number (Support_Contacts). Without a number, nothing renders.
 * - Off until enabled in the option peptide_core_resale_prompt.
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Resale_Prompt {

	public const OPTION = 'peptide_core_resale_prompt';

	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/** @return array{enabled:bool,threshold:float} */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$min   = is_numeric( $saved['threshold'] ?? null ) ? max( 1.0, round( (float) $saved['threshold'], 2 ) ) : 600.0;
		return array( 'enabled' => ! empty( $saved['enabled'] ), 'threshold' => $min );
	}

	/** The WhatsApp number from the store's support contacts, digits only, or ''. */
	public static function whatsapp(): string {
		$support = Settings::all()['support'] ?? array();
		$wa      = Support_Contacts::clean_whatsapp( (string) ( is_array( $support ) ? ( $support['whatsapp'] ?? '' ) : '' ) );
		return null === $wa ? '' : (string) $wa;
	}

	public static function config(): array {
		$s = self::settings();
		return array(
			'thresholdMinor' => (int) round( $s['threshold'] * 100 ),
			'whatsapp'       => self::whatsapp(),
			'cartUrl'        => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
			'strings'        => array(
				'free'     => "You've unlocked free shipping",
				'title'    => 'Buying for resale?',
				'body'     => 'Your bag is over $' . rtrim( rtrim( number_format( $s['threshold'], 2, '.', ',' ), '0' ), '.' ) . '. Our account manager can give you dealer pricing on WhatsApp.',
				'points'   => array( 'Dedicated account manager', 'Get exclusive pricing' ),
				'cta'      => 'Get dealer pricing on WhatsApp',
				'continue' => 'Continue shopping',
				'close'    => 'Close',
				'cartLine' => 'Buying for resale? Get dealer pricing on WhatsApp',
				'message'  => "Hi, I'd like dealer pricing for my bag:",
				'total'    => 'Total',
				'ref'      => 'ref',
			),
		);
	}

	public static function assets(): void {
		if ( is_admin() || ! self::settings()['enabled'] || '' === self::whatsapp() ) {
			return;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return; // Never interrupt checkout.
		}
		foreach ( array( 'css', 'js' ) as $ext ) {
			$file = 'assets/resale-prompt.' . $ext;
			$url  = plugins_url( $file, PEPTIDE_CORE_FILE );
			$ver  = (string) filemtime( PEPTIDE_CORE_DIR . '/' . $file );
			if ( 'css' === $ext ) {
				wp_enqueue_style( 'pc-resale-prompt', $url, array(), $ver );
			} else {
				wp_enqueue_script( 'pc-resale-prompt', $url, array(), $ver, true );
				wp_add_inline_script( 'pc-resale-prompt', 'window.PCResalePrompt=' . wp_json_encode( self::config(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
			}
		}
	}
}
