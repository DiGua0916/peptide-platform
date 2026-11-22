<?php
/**
 * P1-1 (排版 A) + P0-6: Slim, privacy-safe top return bar on front page & shop/product pages.
 * - Pending/failed order ALWAYS surfaces (never suppressed by vcWelcomeShown), with instant WhatsApp rescue option.
 * - Saved checkout draft (pcCheckoutDraft, 30 days) shows masked State + ZIP + Coupon and jumps straight to /checkout/.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', static function (): void {
	if ( is_admin() || ! function_exists( 'wc_get_cart_url' ) ) {
		return;
	}
	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		wp_register_script( 'vc-welcome-back-clear', false, array(), null, true );
		wp_enqueue_script( 'vc-welcome-back-clear' );
		wp_add_inline_script( 'vc-welcome-back-clear', "try{localStorage.removeItem('vcBagSnapshot');localStorage.removeItem('pcCheckoutDraft');}catch(e){}" );
		return;
	}
	if ( function_exists( 'is_cart' ) && is_cart() ) {
		return;
	}
	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		return;
	}
	$dir = get_template_directory() . '/assets/';
	$uri = get_template_directory_uri() . '/assets/';
	$wa  = class_exists( '\PeptideCore\Emails\Chase_Copy' ) ? \PeptideCore\Emails\Chase_Copy::whatsapp_link() : '';
	wp_enqueue_style( 'vc-welcome-back', $uri . 'welcome-back.css', array(), (string) filemtime( $dir . 'welcome-back.css' ) );
	wp_enqueue_script( 'vc-welcome-back', $uri . 'welcome-back.js', array(), (string) filemtime( $dir . 'welcome-back.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_add_inline_script(
		'vc-welcome-back',
		'window.vcWelcomeBack=' . wp_json_encode(
			array(
				'cartApi'     => rest_url( 'wc/store/v1/cart' ),
				'recentApi'   => rest_url( 'peptide/v1/recently-viewed/items' ),
				'cartUrl'     => wc_get_cart_url(),
				'checkoutUrl' => wc_get_checkout_url(),
				'whatsappUrl' => $wa,
			),
			JSON_HEX_TAG | JSON_HEX_AMP
		) . ';',
		'before'
	);
}, 120 );
