<?php
/**
 * Async Retry Queue + 10-Minute Real-Time Transient Failure Reconciler (`class-reconcile-cron.php` V3.7).
 *
 * Strict Policy:
 * 1. Drains transient retry queue for shallow/IC events (strictly within 24h).
 * 2. Scans ONLY real-time paid HPOS orders whose authentic `paid_ts` (`date_paid`) is within the last 24 hours (`<= 86400s`),
 *    verifies against Gateway `POST /v3/reconcile/batch-check`, and compensates genuine transient network misses.
 * 3. NEVER automatically backfills historical orders older than 24 hours (`enable_historical_backfill` defaults to `false`).
 */

namespace StealthCapiBridge;

defined( 'ABSPATH' ) || exit;

final class Reconcile_Cron {

	public const CRON_SCHEDULE      = 'stealth_capi_every_10m';
	public const CRON_RECONCILE     = 'stealth_capi_reconcile_cron';
	public const CRON_RETRY_WORKER  = 'stealth_capi_retry_worker';
	public const OPTION_RETRY_QUEUE = 'stealth_capi_retry_queue';
	public const OPTION_STATS       = 'stealth_capi_reconciler_stats';
	public const HARD_24H_CAP_SEC   = 86400;
	private const MAX_QUEUE_SIZE    = 200;
	private const MAX_ATTEMPTS      = 8;

	public static function register_hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_cron_interval' ) );
		add_action( self::CRON_RECONCILE, array( self::class, 'run_all_compensation' ) );
		add_action( self::CRON_RETRY_WORKER, array( self::class, 'process_retry_queue' ) );
		add_action( 'init', array( self::class, 'ensure_cron_scheduled' ), 30 );
	}

	public static function add_cron_interval( array $schedules ): array {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 600,
			'display'  => 'Every 10 Minutes (Stealth CAPI 24h Real-Time Reconciler)',
		);
		return $schedules;
	}

	public static function ensure_cron_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_RECONCILE ) ) {
			wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_RECONCILE );
		}
	}

	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::CRON_RECONCILE );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_RECONCILE );
		}
	}

	public static function enqueue_event_retry( array $payload, array $tenant ): void {
		if ( empty( $payload['action_uuid'] ) ) {
			return;
		}
		$occurred_at = isset( $payload['occurred_at'] ) ? (int) $payload['occurred_at'] : time();
		if ( ( time() - $occurred_at ) > self::HARD_24H_CAP_SEC ) {
			return;
		}
		$queue = get_option( self::OPTION_RETRY_QUEUE, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		$uuid = (string) $payload['action_uuid'];
		if ( ! isset( $queue[ $uuid ] ) && count( $queue ) >= self::MAX_QUEUE_SIZE ) {
			array_shift( $queue );
		}
		$attempts       = isset( $queue[ $uuid ]['attempts'] ) ? (int) $queue[ $uuid ]['attempts'] : 0;
		$queue[ $uuid ] = array(
			'payload'         => $payload,
			'blog_id'         => (int) ( $tenant['blog_id'] ?? 1 ),
			'attempts'        => $attempts,
			'next_attempt_at' => time() + min( 600, (int) pow( 2, max( 1, $attempts + 1 ) ) * 5 ),
			'updated_at'      => time(),
		);
		update_option( self::OPTION_RETRY_QUEUE, $queue, false );
		self::schedule_async_worker( self::CRON_RETRY_WORKER, 10 );
	}

	public static function schedule_immediate_reconcile(): void {
		self::schedule_async_worker( self::CRON_RECONCILE, 15 );
	}

	private static function schedule_async_worker( string $hook, int $delay_seconds ): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_has_scheduled_action( $hook, array(), 'stealth-capi' ) ) {
				as_schedule_single_action( time() + $delay_seconds, $hook, array(), 'stealth-capi' );
			}
			return;
		}
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( time() + $delay_seconds, $hook );
		}
	}

	public static function run_all_compensation(): array {
		$retry_res     = self::process_retry_queue();
		$reconcile_res = self::reconcile_paid_orders();

		$stats = array(
			'last_run_at'          => time(),
			'queue_delivered'      => $retry_res['delivered'],
			'queue_remaining'      => $retry_res['remaining'],
			'orders_scanned'       => $reconcile_res['scanned'],
			'skipped_outside_24h'  => $reconcile_res['skipped_outside_24h'],
			'batch_missing_found'  => $reconcile_res['batch_missing_found'],
			'orders_compensated'   => $reconcile_res['compensated'],
			'orders_still_pending' => $reconcile_res['still_pending'],
		);
		update_option( self::OPTION_STATS, $stats, false );
		return $stats;
	}

	public static function process_retry_queue(): array {
		$queue = get_option( self::OPTION_RETRY_QUEUE, array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return array( 'delivered' => 0, 'remaining' => 0 );
		}

		$now       = time();
		$delivered = 0;
		$tenant    = Config_Manager::resolve_current_tenant();
		if ( ! $tenant ) {
			return array( 'delivered' => 0, 'remaining' => count( $queue ) );
		}

		foreach ( $queue as $uuid => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['payload'] ) ) {
				unset( $queue[ $uuid ] );
				continue;
			}
			$ev_ts = isset( $entry['payload']['occurred_at'] ) ? (int) $entry['payload']['occurred_at'] : (int) ( $entry['updated_at'] ?? $now );
			if ( ( $now - $ev_ts ) > self::HARD_24H_CAP_SEC || ( $now - (int) ( $entry['updated_at'] ?? $now ) ) > self::HARD_24H_CAP_SEC ) {
				unset( $queue[ $uuid ] );
				continue;
			}
			if ( (int) ( $entry['next_attempt_at'] ?? 0 ) > $now ) {
				continue;
			}

			$res = Config_Manager::dispatch_to_gateway( $entry['payload'], $tenant );
			if ( $res['ok'] ) {
				unset( $queue[ $uuid ] );
				++$delivered;
				continue;
			}

			$attempts = (int) ( $entry['attempts'] ?? 0 ) + 1;
			if ( $attempts >= self::MAX_ATTEMPTS ) {
				unset( $queue[ $uuid ] );
				continue;
			}
			$queue[ $uuid ]['attempts']        = $attempts;
			$queue[ $uuid ]['next_attempt_at'] = $now + min( 900, (int) pow( 2, $attempts ) * 10 );
			$queue[ $uuid ]['updated_at']      = $now;
		}

		update_option( self::OPTION_RETRY_QUEUE, $queue, false );
		return array( 'delivered' => $delivered, 'remaining' => count( $queue ) );
	}

	/**
	 * Two-Way Gateway Delta Reconciler via `POST /v3/reconcile/batch-check`.
	 * Strictly clamped to `paid_ts` within the last 24 hours (`<= 86400s`) unless explicitly invoked by an admin-authorized backfill.
	 */
	public static function reconcile_paid_orders( int $lookback_seconds = 86400, int $limit = 100, bool $allow_extended_window = false ): array {
		$summary = array(
			'scanned'             => 0,
			'skipped_outside_24h' => 0,
			'batch_missing_found' => 0,
			'compensated'         => 0,
			'still_pending'       => 0,
		);

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $summary;
		}

		$tenant = Config_Manager::resolve_current_tenant();
		if ( ! $tenant ) {
			return $summary;
		}

		$cfg         = Config_Manager::get_config();
		$cfg_max_age = isset( $cfg['reconcile_max_paid_age_sec'] ) ? (int) $cfg['reconcile_max_paid_age_sec'] : self::HARD_24H_CAP_SEC;
		$max_paid_age = $allow_extended_window
			? min( Cashier_Listener::MAX_EVENT_AGE_SECONDS, max( 3600, $lookback_seconds ) )
			: min( self::HARD_24H_CAP_SEC, max( 3600, min( $cfg_max_age, $lookback_seconds ) ) );

		$now    = time();
		$orders = wc_get_orders(
			array(
				'status'     => array( 'processing', 'completed' ),
				'date_paid'  => '>' . ( $now - $max_paid_age ),
				'limit'      => max( 1, min( 500, $limit ) ),
				'orderby'    => 'ID',
				'order'      => 'DESC',
				'return'     => 'objects',
			)
		);

		if ( ! is_array( $orders ) || empty( $orders ) ) {
			return $summary;
		}

		$blog_id      = (int) $tenant['blog_id'];
		$is_hub       = Config_Manager::is_organic_retention_hub( $tenant );
		$uuid_prefix  = $is_hub ? 'rep' : 'ord';
		$order_by_uid = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order || ! $order->is_paid() ) {
				continue;
			}
			$date_paid = $order->get_date_paid();
			$paid_ts   = $date_paid ? (int) $date_paid->getTimestamp() : 0;
			if ( $paid_ts <= 0 || ( $now - $paid_ts ) > $max_paid_age ) {
				++$summary['skipped_outside_24h'];
				continue;
			}
			++$summary['scanned'];
			$uid                  = sprintf( '%s_%d_%d_purchase', $uuid_prefix, $blog_id, (int) $order->get_id() );
			$order_by_uid[ $uid ] = $order;
		}

		if ( empty( $order_by_uid ) ) {
			return $summary;
		}

		// Step 1: Query Gateway `/v3/reconcile/batch-check` (HMAC-SHA256 signed).
		$gateway_missing_set = array();
		$batch_res           = Config_Manager::dispatch_hmac_json(
			'/v3/reconcile/batch-check',
			array(
				'site_key'     => (string) $tenant['site_key'],
				'action_uuids' => array_keys( $order_by_uid ),
			),
			$tenant,
			4.0
		);

		if ( $batch_res['ok'] && isset( $batch_res['data']['missing_action_uuids'] ) && is_array( $batch_res['data']['missing_action_uuids'] ) ) {
			foreach ( $batch_res['data']['missing_action_uuids'] as $m_uid ) {
				$gateway_missing_set[ (string) $m_uid ] = true;
			}
			$summary['batch_missing_found'] = count( $gateway_missing_set );
		}

		// Step 2: Compensate ONLY orders within the allowed window that genuinely missed delivery due to transient failure.
		foreach ( $order_by_uid as $uid => $order ) {
			$lock_state = (string) $order->get_meta( Cashier_Listener::META_PURCHASE_LOCK, true );
			if ( in_array( $lock_state, array( 'expired_7d_skipped', 'filtered_safe_page', 'filtered_bot_ua' ), true ) ) {
				continue;
			}
			$reported_by_gw     = isset( $gateway_missing_set[ $uid ] );
			$locally_incomplete = ( 'delivered' !== $lock_state );

			if ( ! $reported_by_gw && ! $locally_incomplete ) {
				continue;
			}

			if ( Cashier_Listener::reconcile_single_order( $order, $tenant, $reported_by_gw ) ) {
				++$summary['compensated'];
			} else {
				++$summary['still_pending'];
			}
		}

		return $summary;
	}

	/**
	 * Historical backfill (>24h up to 6.5d) is DISABLED by default (`enable_historical_backfill = false`).
	 */
	public static function backfill_historical_paid_orders( int $days = 7 ): array {
		$cfg = Config_Manager::get_config();
		if ( empty( $cfg['enable_historical_backfill'] ) ) {
			return array(
				'scanned'             => 0,
				'skipped_outside_24h' => 0,
				'batch_missing_found' => 0,
				'compensated'         => 0,
				'still_pending'       => 0,
				'disabled_by_policy'  => true,
			);
		}
		$days = max( 1, min( 7, $days ) );
		return self::reconcile_paid_orders( min( Cashier_Listener::MAX_EVENT_AGE_SECONDS, $days * 86400 ), 300, true );
	}

	public static function get_local_health(): array {
		$queue     = get_option( self::OPTION_RETRY_QUEUE, array() );
		$stats     = get_option( self::OPTION_STATS, array() );
		$last_paid = (int) get_option( Cashier_Listener::OPTION_LAST_PURCHASE_AT, 0 );
		return array(
			'retry_queue_depth'        => is_array( $queue ) ? count( $queue ) : 0,
			'last_cashier_purchase_at' => $last_paid,
			'last_reconciler'          => is_array( $stats ) ? $stats : array(),
			'next_scheduled_at'        => (int) wp_next_scheduled( self::CRON_RECONCILE ),
		);
	}
}

if ( ! class_exists( __NAMESPACE__ . '\\Retry_Reconciler', false ) ) {
	class_alias( Reconcile_Cron::class, __NAMESPACE__ . '\\Retry_Reconciler' );
}
