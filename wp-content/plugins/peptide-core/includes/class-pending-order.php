<?php
/**
 * "Order waiting for payment" data for the homepage strip (site 1 P1-1, signed off 2026-11-14).
 *
 * Lane A renders the strip; this class only says whether the current visitor has an unpaid order and
 * where to pay it. Sources, in order: the Woo session's order awaiting payment (guests and customers),
 * then, for a logged-in customer, their latest unpaid order. Only orders that are pending or failed,
 * still need payment and are under 72 hours old count. No address, email or item names are exposed.
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
	public static function qualifies( string $status, bool $needs_payment, int $created, int $now ): bool {
		return in_array( $status, array( 'pending', 'failed' ), true ) && $needs_payment && $created > 0 && ( $now - $created ) < self::MAX_AGE;
	}

	private static function describe( $order ): ?array {
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
		if ( ! self::qualifies( (string) $order->get_status(), (bool) $order->needs_payment(), (int) $created, time() ) ) {
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
