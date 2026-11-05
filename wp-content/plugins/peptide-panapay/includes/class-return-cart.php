<?php
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

/** Bind optional cart cleanup to the exact checkout cart in this existing Woo session. */
final class Return_Cart {

	private const SNAPSHOT = 'pc_panapay_checkout_cart';
	private const ATTEMPTS = '_pc_panapay_sessions';
	private const POINTERS = array( 'order_awaiting_payment', 'store_api_draft_order' );
	private static bool $registered = false;

	public static function register_hooks(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'capture_processed' ), 1, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'capture' ), 1 );
		add_action( 'pc_panapay_session_created', array( self::class, 'bind' ), 1 );
		foreach ( array( 'woocommerce_add_to_cart', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored',
			'woocommerce_applied_coupon', 'woocommerce_removed_coupon', 'woocommerce_cart_emptied' ) as $hook ) {
			add_action( $hook, array( self::class, 'invalidate' ), 1, 0 );
		}
		add_action( 'woocommerce_after_cart_item_quantity_update', array( self::class, 'quantity_changed' ), 1, 4 );
		add_filter( 'woocommerce_should_clear_cart_after_payment', array( self::class, 'should_clear' ), PHP_INT_MAX );
	}

	/** Classic checkout emits the native order as its third argument, before payment begins. */
	public static function capture_processed( $order_id, $posted_data, $order ): void {
		if ( $order instanceof \WC_Order && self::order_id( $order_id ) === $order->get_id() ) { self::capture( $order ); }
	}

	/** A retry of an already-frozen payment cannot rebind a later cart to the earlier order. */
	public static function capture( \WC_Order $order ): void {
		try {
			$context = self::context();
			if ( null === $context || $order->get_id() <= 0 || ! $order->has_status( array( 'pending', 'failed' ) )
				|| null === Gateway::account_from_id( $order->get_payment_method() )
				|| $order->meta_exists( self::ATTEMPTS ) || ! self::owner( $order ) || $context['cart']->is_empty() ) { return; }
			$hash = $context['cart']->get_cart_hash();
			if ( ! self::valid_hash( $hash ) ) { return; }
			// Native processed runs before process_payment replaces an earlier
			// awaiting-payment marker. This unbound snapshot grants no cleanup;
			// bind() must later find every populated marker on this new order.
			// Store API's order cart_hash hashes line items, whereas WC_Cart also
			// hashes session data/totals. Capture that actual cart hash at checkout.
			$context['session']->set( self::SNAPSHOT, array( 'version' => 1, 'order_id' => $order->get_id(),
				'hash' => $hash, 'user' => get_current_user_id() ) );
		} catch ( \Throwable $ignored ) { /* Optional cleanup must never block a checkout. */ }
	}

	/** Bind the pending checkout snapshot once, after the native attempt was saved. */
	public static function bind( \WC_Order $order ): void {
		try {
			$context = self::context();
			if ( null === $context || ! self::owner( $order ) || $context['cart']->is_empty()
				|| ! self::pointers_agree( $context['session'], $order->get_id(), true )
				|| Session_Service::bound_gateway( $order ) !== $order->get_payment_method() ) { return; }
			$snapshot = $context['session']->get( self::SNAPSHOT, null );
			if ( ! self::snapshot( $snapshot, $order, false ) ) { return; }
			$hash = $context['cart']->get_cart_hash();
			if ( ! self::valid_hash( $hash ) || ! hash_equals( $snapshot['hash'], $hash ) ) { return; }
			$book = $order->get_meta( self::ATTEMPTS, true );
			$attempt = is_array( $book ) && is_string( $book['active'] ?? null ) ? ( $book['attempts'][ $book['active'] ] ?? null ) : null;
			if ( ! is_array( $attempt ) || 'created' !== ( $attempt['state'] ?? null ) || ! is_string( $attempt['reference'] ?? null ) ) { return; }
			$parsed = Session_Service::parse_reference( $attempt['reference'] );
			if ( $parsed['order_id'] !== $order->get_id() || ! hash_equals( $book['active'], $parsed['attempt_id'] ) ) { return; }
			// A five-field snapshot is already bound and intentionally fails the
			// four-field pending shape above. Retrying/remembering cannot rebind it.
			$snapshot['reference'] = $attempt['reference'];
			$context['session']->set( self::SNAPSHOT, $snapshot );
		} catch ( \Throwable $ignored ) { /* Incomplete binding simply retains the cart. */ }
	}

	/** Only real mutations invalidate the original checkout snapshot; reads/totals do not. */
	public static function invalidate(): void {
		try {
			$context = self::context();
			if ( null !== $context ) { $context['session']->__unset( self::SNAPSHOT ); }
		} catch ( \Throwable $ignored ) { /* Missing session support cannot authorize clearing. */ }
	}

	public static function quantity_changed( $key, $quantity, $old_quantity, $cart ): void {
		if ( is_numeric( $quantity ) && is_numeric( $old_quantity ) && (float) $quantity === (float) $old_quantity ) { return; }
		self::invalidate();
	}

	/** Caller already authenticated the return; ledger/native confirmation is still independently required. */
	public static function clear( \WC_Order $order, string $reference ): bool {
		try {
			if ( ! self::eligible( $order, $reference ) ) { return false; }
			$context = self::context();
			if ( null === $context ) { return false; }
			// Consume before the native mutation, including when an extension throws.
			// Never restore an authorization snapshot after a partly-cleared cart.
			$context['session']->__unset( self::SNAPSHOT );
			$context['cart']->empty_cart();
			return true;
		} catch ( \Throwable $ignored ) { return false; }
	}

	/** Veto Woo's more permissive automatic cleanup only for this payment method. */
	public static function should_clear( $should_clear ) {
		if ( ! $should_clear ) { return $should_clear; }
		$scoped = false;
		try {
			$order = self::native_order();
			if ( ! $order instanceof \WC_Order ) { return $should_clear; }
			$scoped = $order->meta_exists( self::ATTEMPTS ) || null !== Gateway::account_from_id( $order->get_payment_method() )
				|| null !== Session_Service::frozen_gateway( $order ) || null !== Session_Service::bound_gateway( $order );
			if ( ! $scoped ) { return $should_clear; }
			$book = $order->get_meta( self::ATTEMPTS, true );
			$reference = is_array( $book ) && is_string( $book['active'] ?? null )
				? ( $book['attempts'][ $book['active'] ]['reference'] ?? null ) : null;
			return is_string( $reference ) && self::eligible( $order, $reference ) ? $should_clear : false;
		} catch ( \Throwable $ignored ) { return $scoped ? false : $should_clear; }
	}

	private static function eligible( \WC_Order $order, string $reference ): bool {
		if ( 'paid' !== Session_Service::return_status( $order, $reference ) || ! self::owner( $order ) ) { return false; }
		$context = self::context();
		if ( null === $context || $context['cart']->is_empty()
			|| ! self::pointers_agree( $context['session'], $order->get_id(), true ) ) { return false; }
		$snapshot = $context['session']->get( self::SNAPSHOT, null );
		if ( ! self::snapshot( $snapshot, $order, true ) || ! hash_equals( $snapshot['reference'], $reference ) ) { return false; }
		$hash = $context['cart']->get_cart_hash();
		return self::valid_hash( $hash ) && hash_equals( $snapshot['hash'], $hash );
	}

	private static function snapshot( $snapshot, \WC_Order $order, bool $bound ): bool {
		$keys = $bound ? array( 'version', 'order_id', 'hash', 'user', 'reference' ) : array( 'version', 'order_id', 'hash', 'user' );
		return is_array( $snapshot ) && count( $snapshot ) === count( $keys ) && ! array_diff( array_keys( $snapshot ), $keys )
			&& 1 === ( $snapshot['version'] ?? null ) && $order->get_id() === ( $snapshot['order_id'] ?? null )
			&& get_current_user_id() === ( $snapshot['user'] ?? null ) && self::valid_hash( $snapshot['hash'] ?? null )
			&& ( ! $bound || ( is_string( $snapshot['reference'] ?? null ) && '' !== $snapshot['reference'] ) );
	}

	/** Read only. Never call wc_load_cart(), initialize_session(), or set a session cookie. */
	private static function context(): ?array {
		if ( ! function_exists( 'WC' ) ) { return null; }
		$woo = WC();
		$session = is_object( $woo ) ? ( $woo->session ?? null ) : null;
		$cart = is_object( $woo ) ? ( $woo->cart ?? null ) : null;
		if ( ! is_object( $session ) || ! is_object( $cart ) ) { return null; }
		foreach ( array( 'has_session', 'get', 'set', '__unset' ) as $method ) { if ( ! is_callable( array( $session, $method ) ) ) { return null; } }
		foreach ( array( 'is_empty', 'get_cart_hash', 'empty_cart' ) as $method ) { if ( ! is_callable( array( $cart, $method ) ) ) { return null; } }
		return $session->has_session() ? array( 'session' => $session, 'cart' => $cart ) : null;
	}

	private static function owner( \WC_Order $order ): bool { return get_current_user_id() === (int) $order->get_customer_id(); }
	private static function valid_hash( $hash ): bool { return is_string( $hash ) && '' !== $hash && strlen( $hash ) <= 128; }

	private static function order_id( $id ): int {
		if ( is_int( $id ) ) { return $id > 0 ? $id : 0; }
		return is_string( $id ) && 1 === preg_match( '/\A[1-9][0-9]{0,18}\z/D', $id ) && (string) (int) $id === $id ? (int) $id : 0;
	}

	private static function pointers_agree( object $session, int $order_id, bool $require_match ): bool {
		$matched = false;
		foreach ( self::POINTERS as $key ) {
			$id = $session->get( $key, null );
			if ( null === $id || '' === $id ) { continue; }
			if ( $order_id !== self::order_id( $id ) ) { return false; }
			$matched = true;
		}
		return ! $require_match || $matched;
	}

	/** Select the same order as wc_clear_cart_after_payment(); this never grants return access. */
	private static function native_order(): ?\WC_Order {
		global $wp;
		if ( ! empty( $wp->query_vars['order-received'] ) ) {
			$order = wc_get_order( absint( $wp->query_vars['order-received'] ) );
			$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
			if ( $order instanceof \WC_Order && hash_equals( $order->get_order_key(), $key ) ) { return $order; }
		}
		$woo = function_exists( 'WC' ) ? WC() : null;
		$session = is_object( $woo ) ? ( $woo->session ?? null ) : null;
		$id = is_object( $session ) && is_callable( array( $session, 'get' ) ) ? $session->get( 'order_awaiting_payment', null ) : null;
		if ( ( is_int( $id ) || is_string( $id ) ) && is_numeric( $id ) && (float) $id > 0 ) {
			$order = wc_get_order( absint( $id ) );
			if ( $order instanceof \WC_Order ) { return $order; }
		}
		return null;
	}
}
