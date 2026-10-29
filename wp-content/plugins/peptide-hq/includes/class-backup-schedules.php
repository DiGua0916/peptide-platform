<?php
/** Daily backup scheduling. Cron only queues work; it never contacts stores. */
namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Backup_Schedules {
	public const CRON = 'pchq_backup_schedules';
	public const INTERVAL = 'pchq_backup_five_minutes';
	private const PREFIX = 'pchq_backup_schedule_';
	private const LAST_PREFIX = 'pchq_backup_schedule_last_';
	private const SLOT_PREFIX = 'pchq_backup_slot_';

	public static function register_hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'cron_intervals' ) );
		add_action( self::CRON, array( self::class, 'tick' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) { wp_schedule_event( time() + 60, self::INTERVAL, self::CRON ); }
	}

	public static function cron_intervals( array $schedules ): array {
		$schedules[ self::INTERVAL ] = array( 'interval' => 300, 'display' => '每五分钟检查店铺备份计划' );
		return $schedules;
	}

	public static function unschedule(): void { wp_clear_scheduled_hook( self::CRON ); }

	/** Zero returns stored schedules keyed by site id; one site includes defaults and its next due time. */
	public static function settings( int $site_id = 0 ): array {
		if ( $site_id > 0 ) { return self::setting( $site_id ); }
		$out = array();
		foreach ( Sites::all() as $id => $site ) { if ( is_array( get_option( self::PREFIX . $id, null ) ) ) { $out[ $id ] = self::setting( (int) $id ); } }
		return $out;
	}

	/** Permissions and nonce verification belong to the caller's administrator form. */
	public static function save( int $site_id, array $input ) {
		if ( $site_id < 1 || ! Sites::get( $site_id ) ) { return self::error( 'site', '没有找到需要设置备份计划的店铺。' ); }
		$old = self::raw( $site_id );
		$enabled = $input['enabled'] ?? $old['enabled'];
		if ( ! in_array( $enabled, array( true, false, 0, 1, '0', '1' ), true ) ) { return self::error( 'enabled', '自动备份开关无效。' ); }
		$time = (string) ( $input['time'] ?? $old['time'] );
		if ( ! preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time ) ) { return self::error( 'time', '请用 24 小时制填写备份时间，例如 02:00。' ); }
		$timezone = (string) ( $input['timezone'] ?? $old['timezone'] );
		if ( ! in_array( $timezone, \DateTimeZone::listIdentifiers(), true ) && 'UTC' !== $timezone ) { return self::error( 'timezone', '请选择有效的备份时区。' ); }
		$retention = $input['retention'] ?? $old['retention'];
		if ( ! is_scalar( $retention ) || ! preg_match( '/^[0-9]+$/D', (string) $retention ) || (int) $retention < 2 || (int) $retention > 30 ) { return self::error( 'retention', '自动备份保留数量需在 2 到 30 份之间。' ); }
		$value = array( 'enabled' => (bool) (int) $enabled, 'time' => $time, 'timezone' => $timezone, 'retention' => (int) $retention, 'last' => $old['last'], 'updated_at' => time() );
		update_option( self::PREFIX . $site_id, $value, false );
		if ( get_option( self::PREFIX . $site_id, null ) !== $value ) { return self::error( 'save', '备份计划未能保存，请重试。' ); }
		return self::setting( $site_id );
	}

	/** Five-minute cron callback: no remote calls or long-running backup work here. */
	public static function tick(): void {
		if ( ! class_exists( Operations::class ) || ! method_exists( Operations::class, 'enqueue' ) ) { return; }
		// Local ledger refresh calls record_result; no browser visit is needed to release failed daily slots.
		if ( method_exists( Operations::class, 'jobs' ) ) { try { Operations::jobs(); } catch ( \Throwable $e ) { /* Existing claims remain safe when a local ledger is temporarily unreadable. */ } }
		foreach ( Sites::all() as $id => $site ) {
			$id = (int) $id; $setting = self::raw( $id );
			if ( ! $setting['enabled'] ) { continue; }
			try {
				$now = new \DateTimeImmutable( 'now', new \DateTimeZone( $setting['timezone'] ) );
				list( $hour, $minute ) = array_map( 'intval', explode( ':', $setting['time'] ) );
				if ( $now < $now->setTime( $hour, $minute, 0 ) ) { continue; }
				$date = $now->format( 'Y-m-d' ); $key = self::slot_key( $id, $date );
				$operation = self::daily_id( $id, (string) ( $site['uuid'] ?? '' ), $date );
				$claim = self::claim( $key, $operation, $date, $setting['retention'] );
				if ( ! $claim ) { continue; }
				self::update_last( $id, array( 'operation_id' => $operation, 'date' => $date, 'status' => 'claiming', 'updated_at' => time() ) );
				try {
					$job = Operations::enqueue( $id, 'backup.create', array( 'reason' => 'scheduled', 'schedule_date' => $date, 'retention' => $claim['retention'] ), $operation );
					if ( is_wp_error( $job ) || ! is_array( $job ) ) {
						if ( self::release( $key, $claim ) ) { self::update_last( $id, array( 'operation_id' => $operation, 'date' => $date, 'status' => 'enqueue_failed', 'updated_at' => time(), 'message' => '自动备份任务暂时未能入队，将在下次检查时重试。' ) ); }
						continue;
					}
					$before = $claim; $status = (string) ( $job['status'] ?? 'queued' );
					$claim['state'] = in_array( $status, array( 'succeeded', 'failed', 'running', 'waiting' ), true ) ? $status : 'queued';
					$claim['updated_at'] = time(); $claim['enqueued_at'] = time();
					if ( 'failed' === $claim['state'] ) { $claim['retry_after'] = time() + 300; }
					if ( self::replace_claim( $key, $before, $claim ) ) { self::update_last( $id, array( 'operation_id' => $operation, 'date' => $date, 'status' => $claim['state'], 'enqueued_at' => $claim['enqueued_at'], 'updated_at' => time() ) ); }
				} catch ( \Throwable $e ) {
					if ( self::release( $key, $claim ) ) { self::update_last( $id, array( 'operation_id' => $operation, 'date' => $date, 'status' => 'enqueue_failed', 'updated_at' => time(), 'message' => '自动备份任务暂时未能入队，将在下次检查时重试。' ) ); }
				}
			} catch ( \Throwable $e ) { /* One invalid saved schedule must not prevent other stores from queuing. */ }
		}
	}

	/** Operations can publish local job status here; failed backups retry with the same daily UUID. */
	public static function record_result( int $site_id, string $operation_id, array $job ): void {
		$setting = self::raw( $site_id ); $last = $setting['last'];
		if ( ( $last['operation_id'] ?? '' ) !== $operation_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', (string) ( $last['date'] ?? '' ) ) ) { return; }
		$key = self::slot_key( $site_id, $last['date'] ); $claim = get_option( $key, array() );
		if ( ! is_array( $claim ) || ( $claim['operation_id'] ?? '' ) !== $operation_id ) { return; }
		$status = (string) ( $job['status'] ?? '' );
		if ( ! in_array( $status, array( 'queued', 'running', 'waiting', 'succeeded', 'failed' ), true ) ) { return; }
		$before = $claim;
		$next_state = 'failed' === $status && ! empty( $job['result']['manual_recovery_required'] ) ? 'blocked' : $status;
		if ( $next_state === ( $claim['state'] ?? '' ) ) { return; }
		$claim['state'] = $next_state;
		$claim['updated_at'] = time();
		if ( 'failed' === $claim['state'] ) { $claim['retry_after'] = time() + 300; }
		if ( ! self::replace_claim( $key, $before, $claim ) ) { return; }
		$last['status'] = $claim['state']; $last['updated_at'] = time();
		self::update_last( $site_id, $last );
	}

	private static function raw( int $id ): array {
		$value = get_option( self::PREFIX . $id, array() );
		$value = is_array( $value ) ? $value : array();
		$defaults = array( 'enabled' => false, 'time' => '02:00', 'timezone' => 'Asia/Shanghai', 'retention' => 7, 'last' => array(), 'updated_at' => 0 );
		$out = array_merge( $defaults, array_intersect_key( $value, $defaults ) );
		$last = get_option( self::LAST_PREFIX . $id, null );
		if ( is_array( $last ) ) { $out['last'] = $last; }
		return $out;
	}

	private static function setting( int $id ): array {
		$value = self::raw( $id ); $value['site_id'] = $id; $value['next_due'] = null; $value['next_due_local'] = '';
		if ( ! $value['enabled'] ) { return $value; }
		try {
			$now = new \DateTimeImmutable( 'now', new \DateTimeZone( $value['timezone'] ) );
			list( $hour, $minute ) = array_map( 'intval', explode( ':', $value['time'] ) );
			$due = $now->setTime( $hour, $minute, 0 );
			$claim = get_option( self::slot_key( $id, $now->format( 'Y-m-d' ) ), array() );
			if ( is_array( $claim ) && in_array( $claim['state'] ?? '', array( 'queued', 'running', 'waiting', 'succeeded', 'blocked' ), true ) ) { $due = $due->modify( '+1 day' ); }
			$value['next_due'] = $due->getTimestamp(); $value['next_due_local'] = $due->format( 'Y-m-d H:i T' );
		} catch ( \Throwable $e ) { $value['invalid'] = true; }
		return $value;
	}

	private static function claim( string $key, string $id, string $date, int $retention ): ?array {
		$existing = get_option( $key, null );
		if ( null !== $existing ) {
			if ( ! is_array( $existing ) || ( $existing['operation_id'] ?? '' ) !== $id ) { return null; }
			$retry = 'failed' === ( $existing['state'] ?? '' ) && (int) ( $existing['retry_after'] ?? PHP_INT_MAX ) <= time();
			$expired = 'claiming' === ( $existing['state'] ?? '' ) && (int) ( $existing['lease_until'] ?? PHP_INT_MAX ) < time();
			if ( ! $retry && ! $expired ) { return null; }
			// Reusing a daily UUID also reuses its original payload when settings change during a retry.
			$retention = max( 2, min( 30, (int) ( $existing['retention'] ?? $retention ) ) );
			if ( ! self::release( $key, $existing ) ) { return null; }
		}
		$value = array( 'operation_id' => $id, 'date' => $date, 'retention' => $retention, 'state' => 'claiming', 'token' => wp_generate_uuid4(), 'lease_until' => time() + 600, 'updated_at' => time() );
		return add_option( $key, $value, '', false ) ? $value : null;
	}

	/** Compare-and-delete avoids deleting a claim another cron process has already replaced. */
	private static function release( string $key, array $expected ): bool {
		global $wpdb;
		$deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => $key, 'option_value' => maybe_serialize( $expected ) ), array( '%s', '%s' ) );
		if ( $deleted ) { wp_cache_delete( $key, 'options' ); }
		return (bool) $deleted;
	}

	private static function update_last( int $id, array $last ): void {
		update_option( self::LAST_PREFIX . $id, $last, false );
	}

	private static function replace_claim( string $key, array $before, array $after ): bool {
		global $wpdb;
		$changed = $wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $after ) ), array( 'option_name' => $key, 'option_value' => maybe_serialize( $before ) ), array( '%s' ), array( '%s', '%s' ) );
		if ( $changed ) { wp_cache_delete( $key, 'options' ); }
		return (bool) $changed;
	}

	private static function daily_id( int $site_id, string $uuid, string $date ): string {
		$hash = substr( hash( 'sha256', 'vialcove-backup-schedule-v1:' . $uuid . ':' . $site_id . ':' . $date ), 0, 32 );
		$hash[12] = '5'; $hash[16] = dechex( ( hexdec( $hash[16] ) & 3 ) | 8 );
		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-' . substr( $hash, 12, 4 ) . '-' . substr( $hash, 16, 4 ) . '-' . substr( $hash, 20 );
	}

	private static function slot_key( int $id, string $date ): string { return self::SLOT_PREFIX . $id . '_' . str_replace( '-', '', $date ); }
	private static function error( string $code, string $message ): \WP_Error { return new \WP_Error( 'pchq_backup_schedule_' . $code, $message ); }
}
