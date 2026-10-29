<?php
/** Passive source-side checkout instrumentation; WooCommerce owns all business state. */
namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Checkout_Steps {

	public const HANDLE = 'peptide-core-tracking-checkout';
	private static ?\WeakMap $attempts = null;

	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_script' ), 30 );
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( self::class, 'payment_attempt' ), 1 );
	}

	public static function eligible(): bool {
		if ( ! Tracker::enabled() || Tracker::declined() || Tracker::is_staff( Tracker::cookie_user_id() ) || is_admin() || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( isset( $_GET['pc_frame'] ) || isset( $_GET['preview'] ) || isset( $_GET['pc_preview_token'] ) || \PeptideCore\Templates::previewing_id() ) {
			return false;
		}
		$config = apply_filters( 'vialcove_visitor_observer_config', array( 'enabled' => false ) );
		return is_array( $config ) && true === ( $config['enabled'] ?? false ) && 1 === ( $config['protocol'] ?? null ) && ! empty( $config['endpoint'] );
	}

	public static function register_script(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() || ! self::eligible() ) {
			return;
		}
		$dependencies = array( 'wp-data', 'wp-hooks', 'wc-blocks-data-store' );
		foreach ( $dependencies as $handle ) {
			if ( ! wp_script_is( $handle, 'registered' ) ) { return; }
		}
		wp_register_script( self::HANDLE, plugins_url( 'assets/tracking-checkout.js', PEPTIDE_CORE_FILE ), $dependencies, (string) filemtime( PEPTIDE_CORE_DIR . '/assets/tracking-checkout.js' ), true );
	}

	/** One attempt for each native invocation; duplicate listeners on the same context are harmless. */
	public static function payment_attempt( $context ): void {
		if ( ! $context instanceof \Automattic\WooCommerce\StoreApi\Payments\PaymentContext || ! Tracker::enabled() || Tracker::declined() || Tracker::is_staff( Tracker::cookie_user_id() ) ) { return; }
		$order = $context->order;
		if ( ! $order instanceof \WC_Order || ! $order->needs_payment() || Tracker::is_staff( (int) $order->get_customer_id() ) ) { return; }
		[ $visitor, $visit ] = Server_Events::order_link( $order );
		if ( ! $visitor || ! $visit ) { return; }
		self::$attempts ??= new \WeakMap();
		if ( isset( self::$attempts[ $context ] ) ) { return; }
		self::$attempts[ $context ] = true;
		Server_Events::record( $visitor, $visit, 'srv-pay-' . wp_generate_uuid4(), 'payment_attempt', array( 'order_id' => $order->get_id(), 'page_type' => 'checkout' ), array( 'method' => substr( sanitize_key( (string) $context->payment_method ), 0, 80 ) ) );
	}
}
