<?php
/** Narrow, uncached confirmation of this gateway's writes to the active native Woo store. */
namespace VialCove\Panapay;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

final class Persistence {
	private const META = '_pc_panapay_sessions';

	/** A successful WC_Order::save return alone does not prove that its metadata was written. */
	public static function assert_book( \WC_Order $order, array $book ): void {
		$order_id = $order->get_id();
		if ( ! is_int( $order_id ) || $order_id <= 0 || 'shop_order' !== $order->get_type() ) {
			throw new \RuntimeException( 'payment_persistence_unconfirmed' );
		}
		global $wpdb;
		$store = self::store();
		if ( $store['hpos'] ) {
			$sql = $wpdb->prepare(
				"SELECT m.meta_value FROM `{$store['meta']}` m INNER JOIN `{$store['orders']}` o ON o.id = m.order_id WHERE m.order_id = %d AND m.meta_key = %s AND o.type = %s ORDER BY m.id LIMIT 2",
				$order_id, self::META, 'shop_order'
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT m.meta_value FROM `{$store['meta']}` m INNER JOIN `{$store['orders']}` o ON o.ID = m.post_id WHERE m.post_id = %d AND m.meta_key = %s AND o.post_type = %s ORDER BY m.meta_id LIMIT 2",
				$order_id, self::META, 'shop_order'
			);
		}
		$rows = self::rows( $sql );
		$expected = maybe_serialize( $book );
		if ( 1 !== count( $rows ) || ! is_string( $expected ) || ! is_string( $rows[0]['meta_value'] ?? null )
			|| ! hash_equals( $expected, $rows[0]['meta_value'] ) ) {
			throw new \RuntimeException( 'payment_persistence_unconfirmed' );
		}
	}

	/** Reads native persisted status and transaction ID, never the just-mutated order object/cache. */
	public static function is_paid( int $order_id, string $transaction ): bool {
		if ( $order_id <= 0 || '' === $transaction ) { return false; }
		global $wpdb;
		$store = self::store();
		if ( $store['hpos'] ) {
			$sql = $wpdb->prepare(
				"SELECT status, transaction_id FROM `{$store['orders']}` WHERE id = %d AND type = %s LIMIT 2",
				$order_id, 'shop_order'
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT o.post_status AS status, m.meta_value AS transaction_id FROM `{$store['orders']}` o LEFT JOIN `{$store['meta']}` m ON m.post_id = o.ID AND m.meta_key = %s WHERE o.ID = %d AND o.post_type = %s ORDER BY m.meta_id LIMIT 2",
				'_transaction_id', $order_id, 'shop_order'
			);
		}
		$rows = self::rows( $sql );
		if ( count( $rows ) > 1 ) { throw new \RuntimeException( 'payment_persistence_unconfirmed' ); }
		if ( ! $rows || ! is_string( $rows[0]['status'] ?? null ) || ! is_string( $rows[0]['transaction_id'] ?? null ) ) {
			return false;
		}
		$paid = wc_get_is_paid_statuses();
		if ( ! is_array( $paid ) ) { throw new \RuntimeException( 'payment_persistence_unconfirmed' ); }
		$statuses = array();
		foreach ( $paid as $status ) {
			if ( ! is_string( $status ) || ! preg_match( '/\A[a-z0-9_-]{1,20}\z/', $status ) ) {
				throw new \RuntimeException( 'payment_persistence_unconfirmed' );
			}
			$statuses[] = 'wc-' . $status;
		}
		return in_array( $rows[0]['status'], $statuses, true ) && hash_equals( $transaction, $rows[0]['transaction_id'] );
	}

	/** No fallback to the synchronized backup store or plugin-defined order datastore. */
	private static function store(): array {
		global $wpdb;
		try {
			$hpos = OrderUtil::custom_orders_table_usage_is_enabled();
			$actual = ltrim( (string) \WC_Data_Store::load( 'order' )->get_current_class_name(), '\\' );
			if ( $actual !== ( $hpos ? OrdersTableDataStore::class : 'WC_Order_Data_Store_CPT' ) ) {
				throw new \RuntimeException();
			}
			$store = array(
				'hpos' => $hpos,
				'orders' => $hpos ? OrdersTableDataStore::get_orders_table_name() : $wpdb->posts,
				'meta' => $hpos ? OrdersTableDataStore::get_meta_table_name() : $wpdb->postmeta,
			);
			foreach ( array( 'orders', 'meta' ) as $key ) {
				if ( ! is_string( $store[ $key ] ) || ! preg_match( '/\A[A-Za-z0-9_]+\z/', $store[ $key ] ) ) {
					throw new \RuntimeException();
				}
			}
			return $store;
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'payment_persistence_unavailable' );
		}
	}

	private static function rows( string $sql ): array {
		$db = Database::open();
		try {
			$rows = $db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed, prepared native schema above.
			if ( ! is_array( $rows ) || '' !== (string) $db->last_error || count( $rows ) > 2 ) {
				throw new \RuntimeException( 'payment_persistence_unavailable' );
			}
			return $rows;
		} finally { $db->close(); }
	}
}
