<?php
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

/**
 * Scope only this gateway's native completion. Core catches some save exceptions but
 * can still emit payment/status hooks; prevent default downstream actions on unsaved state.
 */
final class Completion_Guard {
	private static array $active = array();
	private static array $registered_statuses = array();
	private static bool $registered = false;

	public static function complete( \WC_Order $order, string $transaction ): void {
		$id = $order->get_id();
		if ( isset( self::$active[ $id ] ) ) { throw new \RuntimeException( 'payment_completion_reentered' ); }
		if ( ! self::$registered ) {
			add_action( 'woocommerce_payment_complete', array( self::class, 'guard' ), PHP_INT_MIN, 1 );
			add_action( 'woocommerce_order_status_changed', array( self::class, 'changed' ), PHP_INT_MIN, 3 );
			self::$registered = true;
		}
		foreach ( wc_get_is_paid_statuses() as $status ) {
			if ( ! is_string( $status ) || ! preg_match( '/^[a-z0-9_-]{1,20}$/D', $status ) ) {
				throw new \RuntimeException( 'payment_completion_status_invalid' );
			}
			if ( ! isset( self::$registered_statuses[ $status ] ) ) {
				add_action( 'woocommerce_order_status_' . $status, array( self::class, 'guard' ), PHP_INT_MIN, 1 );
				self::$registered_statuses[ $status ] = true;
			}
		}
		self::$active[ $id ] = array( 'transaction' => $transaction, 'gateway' => $order->get_payment_method(), 'blocked' => false );
		try {
			$order->payment_complete( $transaction );
			if ( self::$active[ $id ]['blocked'] ) { throw new \RuntimeException( 'payment_persistence_unconfirmed' ); }
		} finally {
			unset( self::$active[ $id ] );
		}
	}

	public static function changed( $id, $from, $to ): void {
		if ( in_array( $to, wc_get_is_paid_statuses(), true ) ) { self::guard( $id ); }
	}

	public static function guard( $id ): void {
		if ( ! isset( self::$active[ $id ] ) ) { return; }
		try {
			if ( ! self::$active[ $id ]['blocked'] && Persistence::has_gateway( (int) $id, self::$active[ $id ]['gateway'] )
				&& Persistence::is_paid( (int) $id, self::$active[ $id ]['transaction'] ) ) { return; }
		} catch ( \Throwable $error ) {
			// A persistence read error is not permission to emit completion effects.
		}
		self::$active[ $id ]['blocked'] = true;
		throw new \RuntimeException( 'payment_persistence_unconfirmed' );
	}
}
