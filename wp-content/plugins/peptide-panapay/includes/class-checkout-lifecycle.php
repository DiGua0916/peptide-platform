<?php
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

/** Separate a new checkout view from an earlier, immutable hosted payment. */
final class Checkout_Lifecycle {

	private const INTENT = 'pc_panapay_new_checkout';
	private const NONCE = 'pc_panapay_checkout_nonce';
	private const POINTERS = array( 'store_api_draft_order', 'order_awaiting_payment' );
	private static bool $registered = false;

	public static function register_hooks(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;
		add_action( 'template_redirect', array( self::class, 'on_checkout_navigation' ), 5 );
		if ( function_exists( 'add_filter' ) && class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Routes\\V1\\Checkout' ) ) {
			add_filter( 'rest_endpoints', array( self::class, 'checkout_routes' ) );
			add_action( 'woocommerce_store_api_checkout_order_created', array( Checkout_Handoff::class, 'created' ), 1 );
		}
	}

	/** Decorate only the exact native checkout, retaining all native route arguments and permission checks. */
	public static function checkout_routes( array $routes ): array {
		$native = '\\Automattic\\WooCommerce\\StoreApi\\Routes\\V1\\Checkout';
		foreach ( array( '/wc/store/checkout', '/wc/store/v1/checkout' ) as $path ) {
			if ( ! isset( $routes[ $path ] ) || ! is_array( $routes[ $path ] ) ) { continue; }
			foreach ( $routes[ $path ] as &$handler ) {
				$callback = is_array( $handler ) ? ( $handler['callback'] ?? null ) : null;
				if ( ! is_array( $callback ) || 2 !== count( $callback ) || ! is_object( $callback[0] )
					|| get_class( $callback[0] ) !== ltrim( $native, '\\' ) || 'get_response' !== $callback[1] ) { continue; }
				$original = $callback[0];
				$handler['callback'] = static fn( \WP_REST_Request $request ) => Checkout_Route::dispatch( $request, $original );
			}
			unset( $handler );
		}
		return $routes;
	}

	/** Explicit new-order link; each generated intent is nonce-bound to this Woo session. */
	public static function new_checkout_url(): string {
		$url = wc_get_checkout_url();
		$session = self::session();
		if ( null === $session || '' === (string) $session->get_customer_id() ) { return $url; }
		$intent = bin2hex( random_bytes( 16 ) );
		return add_query_arg(
			array( self::INTENT => $intent, self::NONCE => wp_create_nonce( self::nonce_action( $session, $intent ) ) ),
			$url
		);
	}

	/**
	 * A normal checkout GET is a new checkout view. Order-pay continues the saved order.
	 * Never detach on an AJAX/REST POST: a duplicate submission must keep its order fence.
	 */
	public static function on_checkout_navigation(): void {
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || is_admin() || wp_doing_ajax()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! is_checkout()
			|| is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' )
			|| isset( $_GET['wc-api'] ) || '' !== (string) get_query_var( 'wc-api', '' ) ) { return; }

		$session = self::session();
		if ( null === $session || '' === (string) $session->get_customer_id() ) { return; }
		if ( isset( $_GET[ self::INTENT ] ) || isset( $_GET[ self::NONCE ] ) ) {
			$intent = $_GET[ self::INTENT ] ?? null;
			$nonce = $_GET[ self::NONCE ] ?? null;
			if ( ! is_string( $intent ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $intent ) || ! is_string( $nonce )
				|| ! wp_verify_nonce( wp_unslash( $nonce ), self::nonce_action( $session, $intent ) ) ) { return; }
		}

		$remembered = array();
		$detached = array();
		foreach ( self::POINTERS as $key ) {
			$id = self::order_id( $session->get( $key, null ) );
			if ( 0 === $id ) { continue; }
			try {
				$order = wc_get_order( $id );
				if ( ! self::owns_frozen_order( $order ) ) { continue; }
				// Observers may retain the owner's order/key before its cart pointer is removed.
				if ( ! isset( $remembered[ $id ] ) ) {
					do_action( 'pc_panapay_checkout_detached', $order );
					$remembered[ $id ] = true;
				}
				// Do not remove a replacement pointer installed by another hook.
				if ( $id !== self::order_id( $session->get( $key, null ) ) ) { continue; }
				$detached[ $key ] = $session->get( $key, null );
				$session->__unset( $key );
			} catch ( \Throwable $ignored ) {
				// Uncertain ownership or observer failure must not discard an existing reference.
				continue;
			}
		}
		if ( $detached ) {
			try { $session->save_data(); }
			catch ( \Throwable $ignored ) {
				// Discovery is optional. A failed save is not a successful new-checkout split.
				// Restore only our missing pointers; never overwrite another observer's replacement.
				foreach ( $detached as $key => $value ) {
					if ( null === $session->get( $key, null ) ) { $session->set( $key, $value ); }
				}
			}
		}
		if ( class_exists( Checkout_Handoff::class ) ) { Checkout_Handoff::clear_navigation(); }
	}

	private static function session(): ?object {
		$wc = WC();
		$session = is_object( $wc ) ? ( $wc->session ?? null ) : null;
		return is_object( $session ) && is_callable( array( $session, 'get' ) )
			&& is_callable( array( $session, 'set' ) ) && is_callable( array( $session, 'save_data' ) )
			&& is_callable( array( $session, '__unset' ) ) && is_callable( array( $session, 'get_customer_id' ) ) ? $session : null;
	}

	private static function nonce_action( object $session, string $intent ): string {
		return 'pc_panapay_new_checkout:' . hash( 'sha256', (string) $session->get_customer_id() ) . ':' . $intent;
	}

	private static function order_id( $value ): int {
		if ( is_int( $value ) ) { return $value > 0 ? $value : 0; }
		return is_string( $value ) && preg_match( '/\A[1-9][0-9]{0,18}\z/', $value )
			&& (string) (int) $value === $value ? (int) $value : 0;
	}

	private static function owns_frozen_order( $order ): bool {
		if ( ! $order instanceof \WC_Order || ! $order->has_status( array( 'pending', 'failed' ) ) ) { return false; }
		$owner = (int) $order->get_customer_id();
		if ( $owner !== get_current_user_id() ) { return false; }
		// The ID came only from this Woo session; frozen_gateway verifies the persisted attempt identity.
		return null !== Session_Service::frozen_gateway( $order );
	}
}
