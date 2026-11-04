<?php
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

/** A bounded checkout handoff, not a second order or payment store. Native Woo creates every order. */
final class Checkout_Handoff {
	private const ANCHOR = 'pc_panapay_checkout_handoff';
	private const MAX_AGE = 604800;
	private static ?array $context = null;

	/** Only called inside the native checkout dispatch, after its nonce/cart authentication. */
	public static function resolve( $order, \WP_REST_Request $request ) {
		$verb = $request->get_method();
		if ( ! in_array( $verb, array( 'GET', 'PATCH', 'PUT', 'POST' ), true ) ) { return $order; }
		$session = self::session();
		if ( ! $session ) { return $order; }
		if ( self::$context ) {
			Store::assert_owner( self::$context['lock'] );
			return self::$context['new'] ? self::reusable( self::$context['new'], self::$context['target'], $request ) : null;
		}
		$frozen = $order instanceof \WC_Order ? Session_Service::frozen_gateway( $order ) : null;
		if ( $order instanceof \WC_Order && '' !== $order->get_meta( '_pc_panapay_sessions', true ) && null === $frozen ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'pc_panapay_checkout_identity_unconfirmed', __( 'Please return to checkout to start a new order.', 'peptide-panapay' ), 409
			);
		}
		if ( null !== $frozen && ! self::owned( $order ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'pc_panapay_checkout_owner_unconfirmed', __( 'Please return to checkout to start a new order.', 'peptide-panapay' ), 409
			);
		}
		$target = $request->get_param( 'payment_method' );
		$target = is_string( $target ) && preg_match( '/\A[a-zA-Z0-9_-]{1,120}\z/', $target ) ? $target : '';
		$session_hash = hash( 'sha256', (string) $session->get_customer_id() );
		$anchor = $session->get( self::ANCHOR, null );
		$old_id = null !== $frozen ? $order->get_id() : self::anchor_id( $anchor, $session_hash );
		if ( ! $old_id || ( 'POST' === $verb && null !== $frozen && $target === $frozen && self::unchanged( $order, $request ) ) ) { return $order; }
		try {
			if ( 'POST' === $verb && '' === $target ) { throw new \RuntimeException( 'checkout_handoff_method_unavailable' ); }
			$lock = Store::acquire( $old_id );
			self::$context = array( 'lock' => $lock, 'old' => $old_id, 'new' => 0, 'target' => $target, 'slot' => null );
			Store::assert_owner( $lock );
			$old = new \WC_Order( $old_id );
			if ( ! self::owned( $old ) || null === Session_Service::frozen_gateway( $old )
				|| ! Persistence::has_status( $old_id, array( 'pending', 'failed' ) ) ) { throw new \RuntimeException( 'checkout_handoff_unavailable' ); }
			Store::assert_no_completion( $old_id );
			if ( 'POST' !== $verb ) {
				self::detach( $old, $session, $session_hash );
				return null; // Native GET/PATCH now uses its no-order flow and creates no order.
			}

			$cart = WC()->cart;
			if ( ! is_object( $cart ) || ! is_callable( array( $cart, 'get_cart_hash' ) ) ) { throw new \RuntimeException( 'checkout_handoff_unavailable' ); }
			$cart_hash = hash( 'sha256', (string) $cart->get_cart_hash() );
			$params = self::canonical( $request->get_params() );
			$body = wp_json_encode( $params );
			if ( ! is_string( $body ) || strlen( $body ) > 65536 ) { throw new \RuntimeException( 'checkout_handoff_unavailable' ); }
			$record = array( 'v' => 1, 'session' => $session_hash, 'old' => $old_id, 'target' => $target,
				'cart' => $cart_hash, 'request' => hash( 'sha256', $body ), 'new' => 0, 'created' => time() );
			$name = 'pc_panapay_handoff_' . hash( 'sha256', $session_hash . '|' . $old_id . '|' . $target . '|' . $cart_hash . '|' . $record['request'] );
			$slot = self::reserve( $name, $record );
			self::$context['slot'] = $slot;
			$next = $slot['record']['new'];
			if ( $order instanceof \WC_Order && $order->get_id() !== $old_id && $order->get_id() !== $next ) { throw new \RuntimeException( 'checkout_handoff_conflict' ); }
			if ( $next ) {
				$new = self::reusable( $next, $target, $request );
				self::$context['new'] = $next;
				self::detach( $old, $session, $session_hash );
				$session->set( 'store_api_draft_order', $next ); $session->save_data();
				return $new;
			}
			if ( ! $slot['fresh'] ) { throw new \RuntimeException( 'checkout_handoff_unconfirmed' ); }
			self::detach( $old, $session, $session_hash );
			return null;
		} catch ( \Throwable $error ) {
			// Never replace an ambiguous slot, reset a financial fence or mutate its original order.
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'pc_panapay_checkout_handoff_unconfirmed',
				__( 'Your earlier order is saved. Please return to checkout to start a new order.', 'peptide-panapay' ), 409
			);
		}
	}

	/** Native order creation fires after its initial save and before its first payment call. */
	public static function created( $order ): void {
		$context = self::$context;
		if ( ! $context || ! $context['slot'] || $context['new'] ) { return; }
		Store::assert_owner( $context['lock'] );
		if ( ! $order instanceof \WC_Order || $order->get_id() <= 0 || $order->get_id() === $context['old']
			|| $order->get_customer_id() !== get_current_user_id() || ! $order->has_status( 'checkout-draft' )
			|| ! $order->has_cart_hash( WC()->cart->get_cart_hash() )
			|| ! Persistence::has_status( $order->get_id(), array( 'checkout-draft' ) )
			|| '' !== $order->get_meta( '_pc_panapay_sessions', true ) ) { throw new \RuntimeException( 'checkout_handoff_binding_failed' ); }
		$slot = $context['slot']; $next = $slot['record']; $next['new'] = $order->get_id();
		self::bind( $slot, $next );
		self::$context['slot']['record'] = $next;
		self::$context['new'] = $order->get_id();
	}

	public static function finish(): void {
		$context = self::$context; self::$context = null;
		if ( $context ) { Store::release( $context['lock'] ); }
	}

	public static function clear_navigation(): void {
		try {
			$session = self::session();
			if ( $session && null !== $session->get( self::ANCHOR, null ) ) { $session->__unset( self::ANCHOR ); $session->save_data(); }
		} catch ( \Throwable $ignored ) { /* Optional stale-intent cleanup must not break a checkout navigation. */ }
	}

	private static function session(): ?object {
		$session = function_exists( 'WC' ) && WC() ? WC()->session : null;
		return is_object( $session ) && is_callable( array( $session, 'get' ) ) && is_callable( array( $session, 'set' ) )
			&& is_callable( array( $session, 'get_customer_id' ) ) && '' !== (string) $session->get_customer_id()
			&& is_callable( array( $session, '__unset' ) ) && is_callable( array( $session, 'save_data' ) ) ? $session : null;
	}

	private static function owned( $order ): bool {
		return $order instanceof \WC_Order && $order->get_id() > 0 && $order->get_customer_id() === get_current_user_id()
			&& $order->has_status( array( 'pending', 'failed' ) ) && ! $order->is_paid() && ! $order->get_date_paid()
			&& '' === $order->get_transaction_id();
	}

	private static function anchor_id( $anchor, string $session ): int {
		return is_array( $anchor ) && is_int( $anchor['old'] ?? null ) && $anchor['old'] > 0
			&& is_string( $anchor['session'] ?? null ) && hash_equals( $session, $anchor['session'] )
			&& is_int( $anchor['created'] ?? null ) && $anchor['created'] >= time() - self::MAX_AGE && $anchor['created'] <= time() + 60 ? $anchor['old'] : 0;
	}

	private static function detach( \WC_Order $old, object $session, string $hash ): void {
		Store::assert_owner( self::$context['lock'] );
		$before = array();
		foreach ( array( 'store_api_draft_order', 'order_awaiting_payment' ) as $pointer ) { $before[ $pointer ] = $session->get( $pointer, null ); }
		do_action( 'pc_panapay_checkout_detached', $old );
		foreach ( $before as $pointer => $previous ) {
			$current = $session->get( $pointer, null );
			if ( ( $old->get_id() === $previous || (string) $old->get_id() === $previous ) && null !== $current
				&& $current !== $previous && ( ! self::$context['new'] || ( self::$context['new'] !== $current && (string) self::$context['new'] !== $current ) ) ) {
				throw new \RuntimeException( 'checkout_handoff_pointer_changed' );
			}
		}
		foreach ( array( 'store_api_draft_order', 'order_awaiting_payment' ) as $pointer ) {
			$value = $session->get( $pointer, null );
			if ( $old->get_id() === $value || (string) $old->get_id() === $value ) { $session->__unset( $pointer ); }
		}
		$session->set( self::ANCHOR, array( 'old' => $old->get_id(), 'session' => $hash, 'created' => time() ) );
		$session->save_data();
	}

	private static function reusable( int $id, string $target, \WP_REST_Request $request ): \WC_Order {
		$order = new \WC_Order( $id );
		if ( $id <= 0 || $order->get_customer_id() !== get_current_user_id() || $order->is_paid() || $order->get_date_paid()
			|| '' !== $order->get_transaction_id() || ! Persistence::has_status( $id, array( 'checkout-draft', 'pending', 'failed' ) )
			|| ! $order->has_cart_hash( WC()->cart->get_cart_hash() )
			|| ! $order->has_status( array( 'checkout-draft', 'pending', 'failed' ) ) ) { throw new \RuntimeException( 'checkout_handoff_order_unavailable' ); }
		Store::assert_no_completion( $id );
		$book = $order->get_meta( '_pc_panapay_sessions', true );
		if ( '' === $book ) {
			if ( ! $order->has_status( 'checkout-draft' ) || ! in_array( $order->get_payment_method(), array( '', $target ), true ) ) { throw new \RuntimeException( 'checkout_handoff_order_unavailable' ); }
			return $order;
		}
		if ( Session_Service::frozen_gateway( $order ) !== $target || $order->get_payment_method() !== $target || ! Persistence::has_gateway( $id, $target ) ) { throw new \RuntimeException( 'checkout_handoff_order_unavailable' ); }
		$attempt = $book['attempts'][ $book['active'] ] ?? null;
		if ( ! is_array( $attempt ) || ! in_array( $attempt['state'] ?? null, array( 'created', 'failed' ), true )
			|| ! is_string( $attempt['fingerprint'] ?? null ) || ! hash_equals( $attempt['fingerprint'], Order_Payload::fingerprint( $order ) )
			|| ! Store::creation_ready( $id ) || ! self::unchanged( $order, $request ) ) { throw new \RuntimeException( 'checkout_handoff_order_unconfirmed' ); }
		return $order;
	}

	/** Compare the values the native POST will apply, without calling any order setter or controller. */
	private static function unchanged( \WC_Order $order, \WP_REST_Request $request ): bool {
		try {
			$book = $order->get_meta( '_pc_panapay_sessions', true );
			$attempt = is_array( $book ) ? ( $book['attempts'][ $book['active'] ] ?? null ) : null;
			if ( ! is_array( $attempt ) || ! is_string( $attempt['fingerprint'] ?? null )
				|| ! hash_equals( $attempt['fingerprint'], Order_Payload::fingerprint( $order ) )
				|| $request->get_param( 'create_account' ) || ! $order->has_cart_hash( WC()->cart->get_cart_hash() )
				|| $order->get_currency( 'edit' ) !== get_woocommerce_currency()
				|| wc_format_decimal( $order->get_total( 'edit' ), wc_get_price_decimals() ) !== wc_format_decimal( WC()->cart->get_total( 'edit' ), wc_get_price_decimals() ) ) { return false; }
			$customer = WC()->customer;
			if ( ! is_object( $customer ) ) { return false; }
			foreach ( array( 'billing', 'shipping' ) as $group ) {
				$previous = $order->get_address( $group, 'edit' );
				$names = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );
				if ( 'billing' === $group ) { $names[] = 'email'; }
				foreach ( $names as $field ) {
					$getter = 'get_' . $group . '_' . $field;
					if ( ! is_callable( array( $customer, $getter ) ) ) { return false; }
					$next = (string) $customer->$getter();
					if ( (string) ( $previous[ $field ] ?? '' ) !== $next
						&& ! ( 'billing' === $group && 'phone' === $field && '' === $next && self::same_hidden_phone( $order, $request, (string) ( $previous[ $field ] ?? '' ) ) ) ) { return false; }
				}
			}
			$note = $request->get_param( 'customer_note' ) ?? '';
			if ( ! is_string( $note ) || $order->get_customer_note( 'edit' ) !== wc_sanitize_textarea( $note ) ) { return false; }
			$posted = $request->get_param( 'additional_fields' ) ?? array();
			if ( ! is_array( $posted ) ) { return false; }
			$fields = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
			foreach ( $posted as $key => $value ) {
				if ( ! is_string( $key ) || ! $fields->is_field( $key ) || $fields->get_field_from_object( $key, $order, 'other' ) !== $value ) { return false; }
			}
			foreach ( array_keys( $fields->get_fields_for_location( 'address' ) ) as $key ) {
				foreach ( array( 'billing', 'shipping' ) as $group ) {
					if ( $fields->get_field_from_object( $key, $order, $group ) !== $fields->get_field_from_object( $key, $customer, $group ) ) { return false; }
				}
			}
			return true;
		} catch ( \Throwable $error ) { return false; }
	}

	/** The conditional phone is copied after native customer processing; compare that exact known fallback. */
	private static function same_hidden_phone( \WC_Order $order, \WP_REST_Request $request, string $previous ): bool {
		if ( 'hidden' !== get_option( 'woocommerce_checkout_phone_field', 'optional' ) ) { return false; }
		$key = Checkout_Fields::FIELD_ID;
		$fields = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
		$meta = \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::get_group_key( 'other' ) . $key;
		if ( ! $fields->is_field( $key ) || ! $order->meta_exists( $meta ) ) { return false; }
		$posted = $request->get_param( 'additional_fields' ) ?? array();
		$value = is_array( $posted ) && array_key_exists( $key, $posted ) ? $posted[ $key ] : $fields->get_field_from_object( $key, $order, 'other' );
		$normal = Checkout_Fields::sanitize_phone( $value );
		return '' !== $normal && hash_equals( $previous, $normal );
	}

	private static function canonical( $value, int $depth = 0 ) {
		if ( $depth > 16 ) { throw new \RuntimeException( 'checkout_handoff_request_invalid' ); }
		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) { ksort( $value ); }
			foreach ( $value as &$item ) { $item = self::canonical( $item, $depth + 1 ); }
			unset( $item ); return $value;
		}
		if ( ! is_scalar( $value ) && null !== $value ) { throw new \RuntimeException( 'checkout_handoff_request_invalid' ); }
		return $value;
	}

	private static function reserve( string $name, array $record ): array {
		$db = Database::open();
		try {
			$raw = wp_json_encode( $record );
			$inserted = $db->query( $db->prepare( "INSERT IGNORE INTO {$db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $raw ) );
			if ( false === $inserted ) { throw new \RuntimeException( 'checkout_handoff_storage_unavailable' ); }
			$actual = self::read( $db, $name );
			$stored = json_decode( $actual, true, 16, JSON_THROW_ON_ERROR );
			if ( ! is_array( $stored ) || array_keys( $stored ) !== array_keys( $record ) || ! is_int( $stored['new'] ) || $stored['new'] < 0
				|| $stored['new'] === $record['old'] || ! is_int( $stored['created'] ) || $stored['created'] < time() - self::MAX_AGE || $stored['created'] > time() + 60 ) { throw new \RuntimeException( 'checkout_handoff_storage_invalid' ); }
			foreach ( array( 'v', 'session', 'old', 'target', 'cart', 'request' ) as $key ) {
				if ( $stored[ $key ] !== $record[ $key ] ) { throw new \RuntimeException( 'checkout_handoff_storage_invalid' ); }
			}
			return array( 'name' => $name, 'raw' => $actual, 'record' => $stored, 'fresh' => 1 === $inserted );
		} finally { $db->close(); }
	}

	private static function bind( array $slot, array $next ): void {
		Store::assert_owner( self::$context['lock'] );
		$db = Database::open();
		try {
			$raw = wp_json_encode( $next );
			$result = $db->query( $db->prepare( "UPDATE {$db->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $raw, $slot['name'], $slot['raw'] ) );
			if ( 1 !== $result || ! hash_equals( $raw, self::read( $db, $slot['name'] ) ) ) { throw new \RuntimeException( 'checkout_handoff_binding_unconfirmed' ); }
		} finally { $db->close(); }
	}

	private static function read( \wpdb $db, string $name ): string {
		$rows = $db->get_results( $db->prepare( "SELECT option_value FROM {$db->options} WHERE option_name = %s LIMIT 2", $name ), ARRAY_A );
		if ( ! is_array( $rows ) || 1 !== count( $rows ) || '' !== (string) $db->last_error || ! is_string( $rows[0]['option_value'] ?? null )
			|| strlen( $rows[0]['option_value'] ) > 2048 ) { throw new \RuntimeException( 'checkout_handoff_storage_unavailable' ); }
		return $rows[0]['option_value'];
	}
}
