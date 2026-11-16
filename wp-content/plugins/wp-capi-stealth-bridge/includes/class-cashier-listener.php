<?php
/**
 * External Cashier Async Callback Listener (`class-cashier-listener.php`):
 * - Listens to `woocommerce_payment_complete` / `processing` / `completed`.
 * - Atomic MySQL `INSERT IGNORE` lock + HPOS state machine for 100% concurrency idempotency.
 * - Strictly restores frozen buyer US IP / UA / _fbc from Order Meta (zero cashier server IP pollution).
 * - Automatically routes `AD_ACQUISITION` -> `/v3/events/ingest` and `ORGANIC_RETENTION_HUB` -> `/v3/events/repurchase-relay`.
 */

namespace StealthCapiBridge;

defined( 'ABSPATH' ) || exit;

final class Cashier_Listener {

	public const META_PURCHASE_LOCK         = '_capi_purchase_sent_lock';
	public const META_PURCHASE_DELIVERED_AT = '_capi_purchase_delivered_at';
	public const META_PURCHASE_LAST_ERROR   = '_capi_purchase_last_error';
	public const OPTION_LAST_PURCHASE_AT    = '_stealth_capi_last_purchase_at';

	public static function register_hooks(): void {
		add_action( 'woocommerce_payment_complete', array( self::class, 'on_payment_complete' ), 15, 1 );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'on_paid_status_transition' ), 15, 2 );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'on_paid_status_transition' ), 15, 2 );
		add_action( 'woocommerce_thankyou', array( self::class, 'on_thankyou_page' ), 15, 1 );
	}

	public static function on_payment_complete( $order_id ): void {
		self::process_paid_order( (int) $order_id );
	}

	public static function on_paid_status_transition( $order_id, $order = null ): void {
		self::process_paid_order( (int) $order_id, $order instanceof \WC_Order ? $order : null );
	}

	public static function on_thankyou_page( $order_id ): void {
		if ( ! $order_id ) {
			return;
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( $order instanceof \WC_Order && $order->is_paid() ) {
			self::process_paid_order( (int) $order_id, $order );
		}
	}

	public static function process_paid_order( int $order_id, ?\WC_Order $order = null ): bool {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$tenant = Config_Manager::resolve_current_tenant();
		if ( ! $tenant ) {
			return false;
		}

		$order = $order ?? wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		if ( 'delivered' === (string) $order->get_meta( self::META_PURCHASE_LOCK, true ) ) {
			return false;
		}

		$blog_id = (int) $tenant['blog_id'];
		if ( ! self::acquire_atomic_order_lock( $blog_id, $order_id ) ) {
			return false;
		}

		$state = (string) $order->get_meta( self::META_PURCHASE_LOCK, true );
		if ( 'delivered' === $state ) {
			return false;
		}

		return self::dispatch_purchase_from_frozen_context( $order, $tenant, true );
	}

	/**
	 * Reconciler entry point (supports forcing redelivery when Gateway `/v3/reconcile/batch-check`
	 * explicitly reports the `action_uuid` missing from `capi_ingested_actions`).
	 */
	public static function reconcile_single_order( \WC_Order $order, ?array $tenant = null, bool $force_from_batch_diff = false ): bool {
		$tenant = $tenant ?? Config_Manager::resolve_current_tenant();
		if ( ! $tenant ) {
			return false;
		}

		$state = (string) $order->get_meta( self::META_PURCHASE_LOCK, true );
		if ( 'delivered' === $state && ! $force_from_batch_diff ) {
			return false;
		}

		$blog_id  = (int) $tenant['blog_id'];
		$order_id = (int) $order->get_id();

		self::acquire_atomic_order_lock( $blog_id, $order_id );

		if ( ! self::acquire_reconcile_mutex( $blog_id, $order_id ) ) {
			return false;
		}

		try {
			return self::dispatch_purchase_from_frozen_context( $order, $tenant, false );
		} finally {
			self::release_reconcile_mutex( $blog_id, $order_id );
		}
	}

	private static function dispatch_purchase_from_frozen_context( \WC_Order $order, array $tenant, bool $enqueue_on_fail ): bool {
		$blog_id     = (int) $tenant['blog_id'];
		$order_id    = (int) $order->get_id();
		$is_hub      = Config_Manager::is_organic_retention_hub( $tenant );
		$uuid_prefix = $is_hub ? 'rep' : 'ord';
		$action_uuid = sprintf( '%s_%d_%d_purchase', $uuid_prefix, $blog_id, $order_id );

		$frozen_ctx = Context_Freezer::read_frozen_context( $order );
		$payload    = Context_Freezer::build_order_payload( $order, 'Purchase', $action_uuid, $frozen_ctx, $tenant );

		if ( $is_hub ) {
			$payload['fbp'] = '';
			$payload['fbc'] = '';
		}

		$order->update_meta_data( self::META_PURCHASE_LOCK, 'in_flight' );
		$order->save();

		$endpoint = $is_hub ? '/v3/events/repurchase-relay' : '/v3/events/ingest';
		$res      = Config_Manager::dispatch_to_gateway( $payload, $tenant, $endpoint );

		if ( $res['ok'] ) {
			$now = time();
			$order->update_meta_data( self::META_PURCHASE_LOCK, 'delivered' );
			$order->update_meta_data( self::META_PURCHASE_DELIVERED_AT, (string) $now );
			$order->delete_meta_data( self::META_PURCHASE_LAST_ERROR );
			$order->save();
			update_option( self::OPTION_LAST_PURCHASE_AT, $now, false );
			return true;
		}

		$order->update_meta_data( self::META_PURCHASE_LOCK, 'retry_pending' );
		$order->update_meta_data( self::META_PURCHASE_LAST_ERROR, (string) $res['error'] );
		$order->save();

		if ( $enqueue_on_fail ) {
			Reconcile_Cron::schedule_immediate_reconcile();
		}
		return false;
	}

	private static function acquire_atomic_order_lock( int $blog_id, int $order_id ): bool {
		global $wpdb;
		$lock_name = sprintf( '_stealth_capi_plock_%d_%d', $blog_id, $order_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$lock_name,
				(string) time()
			)
		);
		return ( 1 === (int) $inserted );
	}

	private static function acquire_reconcile_mutex( int $blog_id, int $order_id ): bool {
		global $wpdb;
		$mutex_name = sprintf( '_stealth_capi_pmutex_%d_%d', $blog_id, $order_id );
		$now        = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				$mutex_name,
				$now - 60
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$mutex_name,
				(string) $now
			)
		);
		return ( 1 === (int) $inserted );
	}

	private static function release_reconcile_mutex( int $blog_id, int $order_id ): void {
		global $wpdb;
		$mutex_name = sprintf( '_stealth_capi_pmutex_%d_%d', $blog_id, $order_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->options, array( 'option_name' => $mutex_name ), array( '%s' ) );
	}
}
