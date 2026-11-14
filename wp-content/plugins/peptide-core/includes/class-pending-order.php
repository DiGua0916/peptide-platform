<?php
/**
 * "Order waiting for payment" data for the homepage strip (site 1 P1-1, signed off 2026-11-14).
 *
 * Lane A renders the strip; this class only says whether the current visitor has an unpaid order and
 * where to pay it. Sources, in order: the Woo session's order awaiting payment (guests and customers),
 * then, for a logged-in customer, their latest unpaid order. Only orders that are pending or failed,
 * still need payment and are under 72 hours old count. No address, email or item names are exposed.
 *
 * No double payments: a shopper who has just paid on the provider's page may still have a pending order
 * until the payment notification arrives. So a pending order only shows after GRACE (30 minutes) with no
 * activity (order changes or a new Panapay payment attempt); a failed order shows at once unless a new
 * payment attempt was started within GRACE.
 *
 * Read it from the Store API cart response: extensions.peptide_core.pending_order (null when none),
 * or in PHP with Pending_Order::for_visitor().
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Pending_Order {

	public const MAX_AGE = 72 * HOUR_IN_SECONDS;
	public const GRACE   = 30 * MINUTE_IN_SECONDS;
	/** Panapay's payment attempts on the order (read only; states that may still turn into a payment). */
	private const PANAPAY_META = '_pc_panapay_sessions';
	private const LIVE_ATTEMPT = array( 'creating', 'created', 'unknown' );

	/**
	 * @return array{order_id:int,state:string,total_minor:int,currency:string,items:int,pay_url:string,created:int}|null
	 */
	public static function for_visitor(): ?array {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$ids     = array();
		$session = WC()->session ?? null;
		if ( $session ) {
			$ids[] = absint( $session->get( 'order_awaiting_payment' ) );
			$ids[] = absint( $session->get( 'store_api_draft_order' ) );
		}
		$user = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$order = wc_get_order( $id );
			// A session order must belong to this visitor: a guest order, or one of this customer's orders.
			if ( $order && (int) $order->get_customer_id() === (int) $user ) {
				$out = self::describe( $order );
				if ( $out ) {
					return $out;
				}
			}
		}
		if ( $user > 0 && function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'customer_id'  => $user,
					'status'       => array( 'pending', 'failed' ),
					'date_created' => '>' . ( time() - self::MAX_AGE ),
					'orderby'      => 'date',
					'order'        => 'DESC',
					'limit'        => 1,
				)
			);
			if ( $orders && self::no_paid_order_after( $user, $orders[0] ) ) {
				return self::describe( $orders[0] );
			}
		}
		return null;
	}

	/** The pure decision, kept separate so it can be tested without WooCommerce. */
	public static function qualifies( string $status, bool $needs_payment, int $created, int $now, int $modified = 0, int $attempt = 0 ): bool {
		if ( ! in_array( $status, array( 'pending', 'failed' ), true ) || ! $needs_payment || $created <= 0 || ( $now - $created ) >= self::MAX_AGE ) {
			return false;
		}
		if ( $attempt > 0 && ( $now - $attempt ) < self::GRACE ) {
			return false; // A payment was just started; it may be completing right now.
		}
		$active = max( $created, $modified );
		return 'failed' === $status || ( $now - $active ) >= self::GRACE;
	}

	/** Start time of the latest Panapay attempt that may still become a payment, or 0. */
	public static function live_attempt_at( $book ): int {
		$latest = 0;
		foreach ( ( is_array( $book ) && is_array( $book['attempts'] ?? null ) ) ? $book['attempts'] : array() as $attempt ) {
			if ( is_array( $attempt ) && in_array( $attempt['state'] ?? null, self::LIVE_ATTEMPT, true ) && is_int( $attempt['created_at'] ?? null ) ) {
				$latest = max( $latest, $attempt['created_at'] );
			}
		}
		return $latest;
	}

	private static function describe( $order ): ?array {
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		$created  = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
		$modified = $order->get_date_modified() ? $order->get_date_modified()->getTimestamp() : 0;
		$attempt  = self::live_attempt_at( $order->get_meta( self::PANAPAY_META, true ) );
		if ( ! self::qualifies( (string) $order->get_status(), (bool) $order->needs_payment(), (int) $created, time(), (int) $modified, $attempt ) ) {
			return null;
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return array(
			'order_id'    => (int) $order->get_id(),
			'state'       => 'failed' === $order->get_status() ? 'failed' : 'pending',
			'total_minor' => (int) round( (float) $order->get_total() * ( 10 ** $decimals ) ),
			'currency'    => (string) $order->get_currency(),
			'items'       => (int) $order->get_item_count(),
			'pay_url'     => (string) $order->get_checkout_payment_url(),
			'created'     => (int) $created,
		);
	}

	private static function no_paid_order_after( int $user, \WC_Order $unpaid ): bool {
		$created = $unpaid->get_date_created();
		if ( ! $created ) {
			return false;
		}
		$paid = wc_get_orders(
			array(
				'customer_id'  => $user,
				'status'       => function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' ),
				'date_created' => '>' . $created->getTimestamp(),
				'limit'        => 1,
				'return'       => 'ids',
			)
		);
		return empty( $paid );
	}
}
