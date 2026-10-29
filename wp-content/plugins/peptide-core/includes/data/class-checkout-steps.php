<?php
/** Observed checkout states in visit-start cohorts, separate from the existing visitor funnel. */
namespace PeptideCore\Data;

use PeptideCore\Overview;
use PeptideCore\Tracking\Schema;

defined( 'ABSPATH' ) || exit;

final class Checkout_Steps {
	public const EVENTS = array(
		'checkout_view' => 'client', 'checkout_address_completed' => 'client',
		'checkout_shipping_selected' => 'client', 'checkout_payment_selected' => 'client',
		'checkout_submit_attempt' => 'client', 'payment_attempt' => 'server', 'payment_success' => 'server',
	);
	private const METRICS = array( 'initial', 'user', 'automatic', 'attempts', 'attempt_visits', 'attempt_orders', 'succeeded', 'failed' );

	public static function labels(): array {
		return array(
			'checkout_view' => __( 'Entered checkout', 'peptide-core' ),
			'checkout_address_completed' => __( 'Address ready in browser', 'peptide-core' ),
			'checkout_shipping_selected' => __( 'Shipping selected', 'peptide-core' ),
			'checkout_payment_selected' => __( 'Payment method selected', 'peptide-core' ),
			'checkout_submit_attempt' => __( 'Submit attempted in browser', 'peptide-core' ),
			'payment_attempt' => __( 'Payment processing started', 'peptide-core' ),
			'payment_success' => __( 'Paid, confirmed by server', 'peptide-core' ),
		);
	}

	public static function summary( array $range ): array {
		$current = self::period( (int) $range['from'], (int) $range['to'] );
		$previous = self::period( (int) $range['compare_from'], (int) $range['compare_to'] );
		$change = array();
		foreach ( self::EVENTS as $type => $source ) { $change[$type] = Range::change( $current['counts'][$type], $previous['counts'][$type] ); }
		$channels = array();
		$methods = array_unique( array_merge( array_keys( $current['channels'] ), array_keys( $previous['channels'] ) ) );
		sort( $methods, SORT_STRING );
		foreach ( $methods as $method ) {
			$c = $current['channels'][$method] ?? self::empty_channel();
			$p = $previous['channels'][$method] ?? self::empty_channel();
			$delta = array();
			foreach ( self::METRICS as $key ) { $delta[$key] = Range::change( $c[$key], $p[$key] ); }
			$delta['success_rate'] = null === $c['success_rate'] || null === $p['success_rate'] ? null : round( $c['success_rate'] - $p['success_rate'], 2 );
			$channels[] = array( 'method' => $method, 'current' => $c, 'previous' => $p, 'change' => $delta );
		}
		return array( 'labels' => self::labels(), 'sources' => self::EVENTS, 'current' => $current['counts'], 'previous' => $previous['counts'], 'change' => $change, 'channels' => $channels, 'local_simulation' => \PeptideCore\Payments\Local_Payments::allowed() );
	}

	private static function empty_channel(): array {
		return array_fill_keys( self::METRICS, 0 ) + array( 'success_rate' => null );
	}

	/** Keyset batches bound row/order loading; each distinct order is classified once, never per event. */
	public static function period( int $from, int $to ): array {
		global $wpdb;
		$seen = array_fill_keys( array_keys( self::EVENTS ), array() );
		$channels = array();
		$orders = array();
		if ( ! Schema::installed() ) { return array( 'counts' => array_fill_keys( array_keys( self::EVENTS ), 0 ), 'channels' => array() ); }
		$cursor = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT e.id,e.type,e.source,e.visit_id,e.order_id,e.data,s.order_id AS linked_order FROM ' . Schema::table( 'events' ) . ' e INNER JOIN ' . Schema::table( 'visits' ) . " s ON s.id=e.visit_id WHERE s.start_ms >= %d AND s.start_ms < %d AND e.id > %d AND ((e.source='client' AND e.type IN ('checkout_view','checkout_address_completed','checkout_shipping_selected','checkout_payment_selected','checkout_submit_attempt')) OR (e.source='server' AND e.type IN ('payment_attempt','payment_success','payment_failed'))) ORDER BY e.id LIMIT 500",
				$from * 1000, $to * 1000, $cursor
			), ARRAY_A );
			$need = array();
			foreach ( (array) $rows as $row ) {
				$id = (int) ( $row['order_id'] ?: $row['linked_order'] );
				if ( $id && ! array_key_exists( $id, $orders ) ) { $need[$id] = $id; }
			}
			if ( $need ) {
				foreach ( $need as $id ) { $orders[$id] = null; }
				// post__in is supported by CPT and mapped to id by installed HPOS OrdersTableQuery.
				foreach ( wc_get_orders( array( 'post__in' => array_values( $need ), 'limit' => count( $need ), 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $order ) {
					if ( ! isset( $need[$order->get_id()] ) ) { continue; }
					$orders[$order->get_id()] = array( 'test' => Overview::is_test_order( $order ), 'paid' => $order->is_paid() || null !== $order->get_date_paid(), 'failed' => $order->has_status( 'failed' ), 'method' => self::method( $order->get_payment_method() ) );
				}
			}
			foreach ( (array) $rows as $row ) {
				$cursor = (int) $row['id'];
				$type = $row['type']; $visit = (int) $row['visit_id'];
				$id = (int) ( $row['order_id'] ?: $row['linked_order'] );
				$order = $orders[$id] ?? null;
				// A genuine abandoned visit has no linked order. Deleted links are excluded, not reclassified.
				if ( ( $id && ! $order ) || ( $order && $order['test'] ) || ( 'server' === $row['source'] && ! (int) $row['order_id'] ) ) { continue; }
				if ( 'payment_success' === $type && ! $order['paid'] ) { continue; }
				if ( isset( self::EVENTS[$type] ) ) { $seen[$type][$visit] = true; }
				if ( ! in_array( $type, array( 'checkout_payment_selected', 'payment_attempt', 'payment_success', 'payment_failed' ), true ) ) { continue; }
				$data = json_decode( (string) $row['data'], true );
				$method = self::method( is_array( $data ) ? ( $data['method'] ?? '' ) : '' );
				if ( ! isset( $channels[$method] ) ) { $channels[$method] = array( 'initial' => array(), 'user' => array(), 'automatic' => array(), 'attempts' => 0, 'attempt_visits' => array(), 'attempt_orders' => array(), 'paid' => array() ); }
				if ( 'checkout_payment_selected' === $type ) {
					$origin = $data['origin'] ?? '';
					if ( in_array( $origin, array( 'initial', 'user', 'automatic' ), true ) ) { $channels[$method][$origin][$visit] = true; }
				} elseif ( 'payment_attempt' === $type ) {
					++$channels[$method]['attempts']; $channels[$method]['attempt_visits'][$visit] = true; $channels[$method]['attempt_orders'][$id] = true;
				} elseif ( 'payment_success' === $type && $order['paid'] ) { $channels[$method]['paid'][$id] = true; }
			}
		} while ( count( (array) $rows ) === 500 );
		$out = array();
		foreach ( $channels as $method => $channel ) {
			$metrics = self::empty_channel();
			foreach ( array( 'initial', 'user', 'automatic', 'attempt_visits', 'attempt_orders' ) as $key ) { $metrics[$key] = count( $channel[$key] ); }
			$metrics['attempts'] = $channel['attempts'];
			foreach ( $channel['attempt_orders'] as $id => $unused ) {
				if ( $orders[$id]['method'] !== $method ) { continue; }
				if ( isset( $channel['paid'][$id] ) && $orders[$id]['paid'] ) { ++$metrics['succeeded']; }
				if ( $orders[$id]['failed'] ) { ++$metrics['failed']; }
			}
			$metrics['success_rate'] = $metrics['attempt_orders'] ? round( 100 * $metrics['succeeded'] / $metrics['attempt_orders'], 2 ) : null;
			$out[$method] = $metrics;
		}
		return array( 'counts' => array_map( 'count', $seen ), 'channels' => $out );
	}

	private static function method( $value ): string {
		return is_string( $value ) && preg_match( '/^[A-Za-z0-9_:-]{1,80}$/D', $value ) ? $value : 'unknown';
	}
}
