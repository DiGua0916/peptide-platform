<?php
/** Auditable points accounts. WooCommerce remains the source of orders and customers. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Loyalty {

	public const OPTION = 'peptide_core_loyalty';
	public const SCHEMA = 'peptide_core_loyalty_schema';
	public const ENDPOINT = 'pc-points';
	public const NOTICE_ATTEMPTS = 5;
	public const NOTICE_LEASE = 300;
	private static array $notice_inflight = array();

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'install' ), 5 );
		add_action( 'init', static function () { add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES ); } );
		add_filter( 'woocommerce_account_menu_items', array( self::class, 'account_menu' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( self::class, 'account_page' ) );
		add_action( 'admin_post_pc_points_redeem', array( self::class, 'redeem_action' ) );
		add_action( 'admin_post_pc_points_release', array( self::class, 'release_action' ) );
		add_action( 'woocommerce_payment_complete', array( self::class, 'earn' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'earn' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'earn' ), 20 );
		add_action( 'woocommerce_order_refunded', array( self::class, 'refunded' ), 20, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'cancelled' ), 20 );
		add_action( 'woocommerce_order_status_failed', array( self::class, 'cancelled' ), 20 );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'cancelled' ), 20 );
		add_filter( 'woocommerce_coupon_is_valid', array( self::class, 'validate_coupon' ), 20, 3 );
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'link_coupons' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'link_coupons' ) );
		add_action( 'pc_loyalty_expire_coupon', array( self::class, 'expire_coupon' ) );
		add_action( 'pc_loyalty_points_notice', array( self::class, 'send_notice' ) );
		add_action( 'before_delete_post', array( self::class, 'deleting_coupon' ) );
		add_action( 'pc_loyalty_reconcile_order', array( self::class, 'reconcile_order' ) );
		add_filter( 'cron_schedules', static function ( $schedules ) { $schedules['pc_points_five_minutes'] = array( 'interval' => 300, 'display' => 'Points notification recovery' ); return $schedules; } );
		add_action( 'init', array( self::class, 'ensure_notice_recovery' ), 20 );
		add_action( 'pc_loyalty_notice_recovery', array( self::class, 'recover_notices' ) );
	}

	public static function table( string $kind ): string {
		global $wpdb;
		return $wpdb->prefix . ( 'accounts' === $kind ? 'pc_points_accounts' : 'pc_points_ledger' );
	}

	public static function install(): void {
		if ( '3' === get_option( self::SCHEMA ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$a = self::table( 'accounts' );
		$l = self::table( 'ledger' );
		dbDelta( "CREATE TABLE {$a} (
user_id bigint(20) unsigned NOT NULL,
balance bigint(20) unsigned NOT NULL DEFAULT 0,
debt bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (user_id)
) ENGINE=InnoDB {$charset};" );
		dbDelta( "CREATE TABLE {$l} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL,
event_key varchar(191) NOT NULL,
kind varchar(32) NOT NULL,
delta bigint(20) NOT NULL,
balance bigint(20) unsigned NOT NULL,
debt bigint(20) unsigned NOT NULL DEFAULT 0,
order_id bigint(20) unsigned NOT NULL DEFAULT 0,
reference_id bigint(20) unsigned NOT NULL DEFAULT 0,
actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
note text NOT NULL,
data longtext NOT NULL,
created_at datetime NOT NULL,
notice_status varchar(12) NOT NULL DEFAULT 'none',
notice_attempts int(10) unsigned NOT NULL DEFAULT 0,
notice_next bigint(20) unsigned NOT NULL DEFAULT 0,
notice_lease bigint(20) unsigned NOT NULL DEFAULT 0,
notice_claim varchar(64) NOT NULL DEFAULT '',
PRIMARY KEY  (id),
UNIQUE KEY event_key (event_key),
KEY user_id (user_id,id),
KEY order_kind (order_id,kind),
KEY reference_kind (reference_id,kind),
KEY notice_due (notice_status,notice_next)
) ENGINE=InnoDB {$charset};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $a ) ) ) === $a && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $l ) ) ) === $l ) {
			$account_status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $a ) ), ARRAY_A );
			$ledger_status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $l ) ), ARRAY_A );
			if ( 'InnoDB' === ( $account_status['Engine'] ?? '' ) && 'InnoDB' === ( $ledger_status['Engine'] ?? '' ) && $wpdb->get_var( "SHOW COLUMNS FROM {$l} LIKE 'notice_claim'" ) ) { update_option( self::SCHEMA, '3', false ); }
		}
	}

	public static function settings(): array {
		return array_merge( array( 'enabled' => false, 'enabled_since' => 0, 'earn_per_unit' => 1.0, 'redeem_enabled' => false, 'redeem_per_unit' => 100, 'minimum' => 100, 'expiry_days' => 30, 'notify_enabled' => false ), (array) get_option( self::OPTION, array() ) );
	}

	public static function save_settings( array $raw ) {
		if ( ! self::can_manage() ) { return new \WP_Error( 'pc_points_forbidden', '没有顾客管理权限。' ); }
		$old = self::settings();
		$earn = (float) ( $raw['earn_per_unit'] ?? 0 );
		$rate = (int) ( $raw['redeem_per_unit'] ?? 0 );
		$min = (int) ( $raw['minimum'] ?? 0 );
		$days = (int) ( $raw['expiry_days'] ?? 0 );
		if ( ! is_finite( $earn ) || $earn <= 0 || $earn > 10000 || $rate < 1 || $rate > 1000000 || $min < 1 || $min > 100000000 || $days < 1 || $days > 365 ) {
			return new \WP_Error( 'pc_points_rules', '请填写有效的积分赚取、兑换比例、最低积分和1–365天有效期。' );
		}
		$on = ! empty( $raw['enabled'] );
		$value = array( 'enabled' => $on, 'enabled_since' => $on && ! $old['enabled'] ? time() : (int) $old['enabled_since'], 'earn_per_unit' => $earn, 'redeem_enabled' => ! empty( $raw['redeem_enabled'] ), 'redeem_per_unit' => $rate, 'minimum' => $min, 'expiry_days' => $days, 'notify_enabled' => ! empty( $raw['notify_enabled'] ) );
		update_option( self::OPTION, $value, false );
		return $value;
	}

	public static function can_manage(): bool {
		$user = wp_get_current_user();
		return current_user_can( 'manage_woocommerce' ) && ( ! class_exists( Staff::class ) || ! Staff::is_staff( $user ) || in_array( 'customers', Staff::granted( (int) $user->ID ), true ) );
	}

	public static function account( int $user ): array {
		global $wpdb;
		$t = self::table( 'accounts' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT balance,debt FROM {$t} WHERE user_id=%d", $user ), ARRAY_A );
		return array( 'balance' => (int) ( $row['balance'] ?? 0 ), 'debt' => (int) ( $row['debt'] ?? 0 ) );
	}

	public static function ledger( int $user, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$t = self::table( 'ledger' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id=%d ORDER BY id DESC LIMIT %d OFFSET %d", $user, min( 100, max( 1, $limit ) ), max( 0, $offset ) ), ARRAY_A );
	}

	private static function event( string $key ): ?array {
		global $wpdb;
		$t = self::table( 'ledger' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE event_key=%s", $key ), ARRAY_A );
	}

	/** Serializes coupon lifecycle changes that span WooCommerce post/meta writes. */
	private static function customer_lock( int $user, callable $work ) {
		global $wpdb;
		$key = 'pc-points-' . substr( hash( 'sha256', self::table( 'accounts' ) ), 0, 16 ) . '-' . $user;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $key ) ) ) { return new \WP_Error( 'pc_points_busy', '积分账户正在处理另一笔请求，请稍后重试。' ); }
		try { return $work(); } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); }
	}

	/** Payment/refund callbacks may arrive out of order; decide from fresh persisted state under one order lock. */
	private static function order_lock( int $id, callable $work ) {
		global $wpdb;
		$key = 'pc-points-order-' . substr( hash( 'sha256', self::table( 'accounts' ) ), 0, 12 ) . '-' . $id;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $key ) ) ) {
			if ( function_exists( 'as_schedule_single_action' ) ) { as_schedule_single_action( time() + 15, 'pc_loyalty_reconcile_order', array( $id ), 'peptide-points', true ); }
			return new \WP_Error( 'pc_points_busy', '订单积分正在处理，稍后自动复核。' );
		}
		try { return $work(); } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); }
	}

	private static function fresh_order( int $id ) {
		clean_post_cache( $id );
		if ( class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) { wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id ); }
		$store = \WC_Data_Store::load( 'order' );
		if ( method_exists( $store->get_current_class_name(), 'clear_cached_data' ) ) { $store->clear_cached_data( array( $id ) ); }
		wp_cache_delete( \WC_Cache_Helper::get_cache_prefix( 'orders' ) . 'refund_ids' . $id, 'orders' );
		return wc_get_order( $id );
	}

	private static function fresh_coupon( int $id ): \WC_Coupon {
		clean_post_cache( $id );
		wp_cache_delete( $id, 'post_meta' );
		wp_cache_delete( \WC_Data::generate_meta_cache_key( $id, 'coupons' ), 'coupons' );
		return new \WC_Coupon( $id );
	}

	public static function reconcile_order( $id ): void {
		self::earn( (int) $id );
		self::reverse_order( (int) $id, 'reconcile' );
	}

	/** One account row lock serializes every adjustment/redemption/refund; the unique event is the retry boundary. */
	private static function change( int $user, string $key, string $kind, callable $calculate, int $order = 0, int $reference = 0, string $note = '', array $data = array() ) {
		if ( '3' !== get_option( self::SCHEMA ) ) { return new \WP_Error( 'pc_points_storage', '积分存储尚未准备好，需要可用的InnoDB事务表。' ); }
		if ( ! Customer_Segments::is_customer( $user ) || strlen( $key ) > 191 ) { return new \WP_Error( 'pc_points_customer', '顾客账户无效。' ); }
		global $wpdb;
		$a = self::table( 'accounts' );
		$l = self::table( 'ledger' );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return new \WP_Error( 'pc_points_transaction', '积分事务暂不可用。' ); }
		try {
			if ( false === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$a} (user_id,balance,debt) VALUES (%d,0,0)", $user ) ) ) { throw new \RuntimeException( 'account' ); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT balance,debt FROM {$a} WHERE user_id=%d FOR UPDATE", $user ), ARRAY_A );
			if ( ! $row ) { throw new \RuntimeException( 'lock' ); }
			$previous = self::event( $key );
			if ( $previous ) {
				$wpdb->query( 'ROLLBACK' );
				return (int) $previous['user_id'] === $user ? $previous : new \WP_Error( 'pc_points_conflict', '请求标识已被使用。' );
			}
			$delta = $calculate( array_map( 'intval', $row ) );
			if ( is_wp_error( $delta ) ) { $wpdb->query( 'ROLLBACK' ); return $delta; }
			$delta = (int) $delta;
			$net = (int) $row['balance'] - (int) $row['debt'] + $delta;
			$balance = max( 0, $net );
			$debt = max( 0, -$net );
			if ( max( $balance, $debt ) > 1000000000 ) { throw new \RuntimeException( 'limit' ); }
			if ( false === $wpdb->update( $a, array( 'balance' => $balance, 'debt' => $debt ), array( 'user_id' => $user ), array( '%d', '%d' ), array( '%d' ) ) ) { throw new \RuntimeException( 'balance' ); }
			$entry = array( 'user_id' => $user, 'event_key' => $key, 'kind' => $kind, 'delta' => $delta, 'balance' => $balance, 'debt' => $debt, 'order_id' => $order, 'reference_id' => $reference, 'actor_id' => get_current_user_id(), 'note' => sanitize_text_field( $note ), 'data' => wp_json_encode( $data ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
			if ( ! $wpdb->insert( $l, $entry ) ) { throw new \RuntimeException( 'ledger' ); }
			$entry['id'] = $wpdb->insert_id;
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'commit' ); }
			// Notification failures must not turn a committed financial entry into a reported rollback.
			try { self::queue_notice( $entry ); do_action( 'peptide_core_points_changed', $user, $entry ); } catch ( \Throwable $notice_error ) { /* The ledger remains authoritative. */ }
			return $entry;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'pc_points_write', '积分暂未变更，请稍后重试。' );
		}
	}

	private static function queue_notice( array $entry ): void {
		if ( ! (int) $entry['delta'] || ! self::settings()['notify_enabled'] ) { return; }
		global $wpdb;
		$wpdb->update( self::table( 'ledger' ), array( 'notice_status' => 'queued', 'notice_next' => time() ), array( 'id' => $entry['id'], 'notice_status' => 'none' ) );
		self::schedule_notice( (int) $entry['id'], time() );
	}

	private static function schedule_notice( int $id, int $when, bool $unique = true ): void {
		if ( $when <= time() && function_exists( 'as_enqueue_async_action' ) ) { as_enqueue_async_action( 'pc_loyalty_points_notice', array( $id ), 'peptide-points', true ); }
		elseif ( function_exists( 'as_schedule_single_action' ) ) { as_schedule_single_action( max( time() + 1, $when ), 'pc_loyalty_points_notice', array( $id ), 'peptide-points', $unique ); }
		elseif ( ! wp_next_scheduled( 'pc_loyalty_points_notice', array( $id ) ) ) { wp_schedule_single_event( max( time() + 1, $when ), 'pc_loyalty_points_notice', array( $id ) ); }
	}

	public static function ensure_notice_recovery(): void {
		if ( self::settings()['notify_enabled'] && ! wp_next_scheduled( 'pc_loyalty_notice_recovery' ) ) { wp_schedule_event( time() + 300, 'pc_points_five_minutes', 'pc_loyalty_notice_recovery' ); }
	}

	private static function notice_lock_key( int $id ): string { return 'pc-notice-' . substr( hash( 'sha256', self::table( 'ledger' ) ), 0, 16 ) . '-' . $id; }

	/** Recover orphan queued rows and abandoned leases. A live sender's advisory lock is never stolen. */
	public static function recover_notices(): void {
		if ( '3' !== get_option( self::SCHEMA ) ) { return; }
		global $wpdb;
		$l = self::table( 'ledger' ); $now = time();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$l} WHERE notice_status='queued' OR (notice_status='failed' AND notice_attempts < %d) OR (notice_status='sending' AND notice_lease <= %d) ORDER BY notice_next,id LIMIT 100", self::NOTICE_ATTEMPTS, $now ) );
		foreach ( $ids as $id ) {
			$id = (int) $id; $key = self::notice_lock_key( $id );
			if ( isset( self::$notice_inflight[ $id ] ) ) { continue; }
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $key ) ) ) { continue; }
			try {
				$r = $wpdb->get_row( $wpdb->prepare( "SELECT notice_status,notice_attempts,notice_next,notice_lease FROM {$l} WHERE id=%d", $id ), ARRAY_A );
				if ( ! $r || ( 'sending' === $r['notice_status'] && (int) $r['notice_lease'] > $now ) ) { continue; }
				if ( ! self::settings()['notify_enabled'] ) { $wpdb->update( $l, array( 'notice_status' => 'skipped', 'notice_claim' => '', 'notice_lease' => 0 ), array( 'id' => $id ) ); continue; }
				if ( (int) $r['notice_attempts'] >= self::NOTICE_ATTEMPTS ) { $wpdb->update( $l, array( 'notice_status' => 'failed', 'notice_claim' => '', 'notice_lease' => 0 ), array( 'id' => $id ) ); continue; }
				$next = max( $now, (int) $r['notice_next'] );
				$wpdb->update( $l, array( 'notice_status' => 'queued', 'notice_claim' => '', 'notice_lease' => 0, 'notice_next' => $next ), array( 'id' => $id ) );
				self::schedule_notice( $id, $next );
			} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); }
		}
		if ( ! self::settings()['notify_enabled'] && ! $wpdb->get_var( "SELECT id FROM {$l} WHERE notice_status IN ('queued','sending') LIMIT 1" ) ) { wp_clear_scheduled_hook( 'pc_loyalty_notice_recovery' ); }
	}

	/**
	 * The DB claim plus advisory lock prevents concurrent live workers from sending twice.
	 * If the process dies after the mail service accepts a message but before 'sent' is recorded,
	 * recovery can resend it: exactly-once external delivery is impossible without provider support.
	 */
	public static function send_notice( $id ): void {
		global $wpdb;
		$id = (int) $id; $l = self::table( 'ledger' ); $key = self::notice_lock_key( $id ); $claim = wp_generate_uuid4(); $now = time();
		if ( isset( self::$notice_inflight[ $id ] ) ) { return; }
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $key ) ) ) { return; }
		self::$notice_inflight[ $id ] = true;
		try {
			if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE {$l} SET notice_status='sending',notice_attempts=notice_attempts+1,notice_lease=%d,notice_claim=%s WHERE id=%d AND notice_status='queued' AND notice_attempts < %d AND notice_next <= %d", $now + self::NOTICE_LEASE, $claim, $id, self::NOTICE_ATTEMPTS, $now ) ) ) { return; }
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$l} WHERE id=%d", $id ), ARRAY_A ); $u = $r ? get_userdata( (int) $r['user_id'] ) : false;
			if ( ! $u || ! is_email( $u->user_email ) || ! self::settings()['notify_enabled'] ) { $wpdb->update( $l, array( 'notice_status' => 'skipped', 'notice_claim' => '', 'notice_lease' => 0 ), array( 'id' => $id, 'notice_claim' => $claim ) ); return; }
			$text = self::kind_label( $r['kind'] ) . "\n" . sprintf( __( 'Points change: %1$+d; available points: %2$d; refund recovery: %3$d.', 'peptide-core' ), $r['delta'], $r['balance'], $r['debt'] ) . "\n" . wc_get_account_endpoint_url( self::ENDPOINT );
			$headers = array( 'X-Peptide-Points-Event: ' . hash( 'sha256', self::table( 'ledger' ) . ':' . $id ) );
			try { $sent = wp_mail( $u->user_email, __( 'Your points activity', 'peptide-core' ), $text, $headers ); } catch ( \Throwable $e ) { $sent = false; }
			$retry = ! $sent && (int) $r['notice_attempts'] < self::NOTICE_ATTEMPTS;
			$delays = array( 60, 300, 1800, 7200 ); $next = $retry ? time() + $delays[ min( 3, max( 0, (int) $r['notice_attempts'] - 1 ) ) ] : 0;
			$wpdb->update( $l, array( 'notice_status' => $sent ? 'sent' : ( $retry ? 'queued' : 'failed' ), 'notice_next' => $next, 'notice_claim' => '', 'notice_lease' => 0 ), array( 'id' => $id, 'notice_claim' => $claim ) );
			if ( $retry ) { self::schedule_notice( $id, $next, false ); }
		} finally { unset( self::$notice_inflight[ $id ] ); $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); }
	}

	public static function adjust( int $user, int $delta, string $note, string $request ) {
		if ( ! self::can_manage() ) { return new \WP_Error( 'pc_points_forbidden', '没有顾客管理权限。' ); }
		if ( ! $delta || abs( $delta ) > 1000000 || '' === trim( $note ) || ! preg_match( '/^[a-zA-Z0-9-]{16,64}$/', $request ) ) { return new \WP_Error( 'pc_points_adjust', '调整需填写非零积分、原因及有效请求标识。' ); }
		return self::change( $user, 'adjust:' . $request, 'adjustment', static function ( $a ) use ( $delta ) { return $delta < 0 && $a['balance'] < -$delta ? new \WP_Error( 'pc_points_balance', '可用积分不足，不能扣至负数。' ) : $delta; }, 0, 0, $note );
	}

	public static function merchandise_total( \WC_Order $order ): float {
		$total = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) { $total += max( 0, (float) $item->get_total() ); }
		return round( $total, wc_get_price_decimals() );
	}

	public static function earn( $order_id ) {
		return self::order_lock( (int) $order_id, static fn() => self::earn_locked( (int) $order_id ) );
	}

	private static function earn_locked( int $order_id ) {
		$order = self::fresh_order( $order_id );
		$s = self::settings();
		if ( ! $s['enabled'] || ! $s['enabled_since'] || ! $order instanceof \WC_Order || ! $order->is_paid() || ! $order->get_date_paid() || ! $order->get_date_created() || $order->get_date_created()->getTimestamp() < (int) $s['enabled_since'] || $order->get_currency() !== get_woocommerce_currency() ) { return null; }
		$user = (int) $order->get_customer_id();
		if ( ! Customer_Segments::is_customer( $user ) ) { return null; }
		$base = self::merchandise_total( $order );
		$points = (int) floor( $base * (float) $s['earn_per_unit'] + 0.000001 );
		if ( ! $points ) { return null; }
		// A partial refund may have arrived before the first payment callback while the order stays paid.
		// Keep the gross earning snapshot, then reconcile cumulative refunds under the same lifecycle lock.
		// The customer lock also prevents redeeming the temporary gross balance between the two entries.
		return self::customer_lock( $user, static function () use ( $user, $order, $points, $base, $s ) {
			$result = self::change( $user, 'earn:' . $order->get_id(), 'earned', static fn() => $points, $order->get_id(), 0, 'Order payment', array( 'base' => $base, 'order_total' => (float) $order->get_total(), 'rate' => $s['earn_per_unit'], 'currency' => $order->get_currency() ) );
			if ( ! is_wp_error( $result ) && $order->get_refunds() ) { self::reverse_locked( $order->get_id(), 'reconcile' ); }
			return $result;
		} );
	}

	private static function refund_ratio( \WC_Order $order, float $base, float $grand_total = 0 ): float {
		if ( $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) { return 1.0; }
		$refunded = 0.0; $unallocated = 0.0;
		foreach ( $order->get_refunds() as $refund ) {
			$items = $refund->get_items( 'line_item' );
			foreach ( $items as $item ) { $refunded += abs( (float) $item->get_total() ); }
			// No item allocation: pro-rate this monetary refund across the original whole order.
			// Explicit shipping/fee-only refunds are not merchandise refunds.
			if ( ! $items && ! $refund->get_items( 'shipping' ) && ! $refund->get_items( 'fee' ) && ! $refund->get_items( 'tax' ) ) { $unallocated += abs( (float) $refund->get_amount() ); }
		}
		// A manually entered full monetary refund may not have line items.
		if ( (float) $order->get_total() > 0 && (float) $order->get_total_refunded() + 0.00001 >= (float) $order->get_total() ) { return 1.0; }
		$grand_total = $grand_total > 0 ? $grand_total : (float) $order->get_total();
		return $base > 0 ? min( 1.0, $refunded / $base + ( $grand_total > 0 ? $unallocated / $grand_total : 0 ) ) : 0.0;
	}

	public static function refunded( $order_id, $refund_id ): void { self::reverse_order( (int) $order_id, 'refund-' . (int) $refund_id ); }
	public static function cancelled( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) { self::reverse_order( (int) $order_id, 'status-' . $order->get_status() ); }
	}

	private static function reverse_order( int $id, string $event ): void {
		self::order_lock( $id, static function () use ( $id, $event ) { self::reverse_locked( $id, $event ); } );
	}

	private static function reverse_locked( int $id, string $event ): void {
		$order = self::fresh_order( $id );
		if ( ! $order instanceof \WC_Order ) { return; }
		if ( 'reconcile' === $event ) { $event .= '-' . md5( wp_json_encode( array( $order->get_status(), $order->get_total_refunded(), array_map( static fn( $r ) => $r->get_id(), $order->get_refunds() ) ) ) ); }
		global $wpdb;
		$l = self::table( 'ledger' );
		$earned = self::event( 'earn:' . $id );
		if ( $earned ) {
			$data = json_decode( $earned['data'], true );
			$target = (int) floor( (int) $earned['delta'] * self::refund_ratio( $order, (float) ( $data['base'] ?? 0 ), (float) ( $data['order_total'] ?? 0 ) ) + 0.000001 );
			self::change( (int) $earned['user_id'], 'reverse:' . $id . ':' . $event, 'earn_reversal', static function () use ( $wpdb, $l, $id, $target ) {
				$sum = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(delta),0) FROM {$l} WHERE order_id=%d AND kind='earn_reversal'", $id ) );
				if ( null === $sum ) { throw new \RuntimeException( 'ledger read' ); }
				$prior = -(int) $sum;
				return -max( 0, $target - $prior );
			}, $id, 0, 'Payment points reversed: ' . $event );
		}
		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			$cid = (int) $coupon_item->get_meta( '_pc_loyalty_coupon' );
			$user = (int) $coupon_item->get_meta( '_pc_loyalty_user' );
			$cost = (int) $coupon_item->get_meta( '_pc_loyalty_points' );
			if ( ! $cid || ! $user || ! $cost ) { continue; }
			$c = new \WC_Coupon( $cid );
			$ratio = self::refund_ratio( $order, (float) $coupon_item->get_meta( '_pc_loyalty_base' ), (float) $coupon_item->get_meta( '_pc_loyalty_grand_total' ) );
			$target = (int) floor( $cost * $ratio + 0.000001 );
			$result = self::change( $user, 'return:' . $cid . ':' . $event, 'redeem_return', static function () use ( $wpdb, $l, $cid, $target ) {
				$sum = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(delta),0) FROM {$l} WHERE reference_id=%d AND kind IN ('redeem_return','redeem_release')", $cid ) );
				if ( null === $sum ) { throw new \RuntimeException( 'ledger read' ); }
				$prior = (int) $sum;
				return max( 0, $target - $prior );
			}, $id, $cid, 'Redeemed points returned: ' . $event );
			if ( ! is_wp_error( $result ) && $ratio >= 1 && $c->get_id() ) { $c->update_meta_data( '_pc_loyalty_released', 'yes' ); $c->save(); }
		}
	}

	/** Reserve the points before creating the single-use personal WooCommerce coupon. */
	public static function redeem( int $user, int $points, string $request ) {
		return self::customer_lock( $user, static fn() => self::redeem_locked( $user, $points, $request ) );
	}

	private static function redeem_locked( int $user, int $points, string $request ) {
		$s = self::settings();
		if ( ! is_user_logged_in() || get_current_user_id() !== $user || ! $s['redeem_enabled'] || ! preg_match( '/^[a-zA-Z0-9-]{16,64}$/', $request ) || $points < (int) $s['minimum'] || $points > 100000000 ) { return new \WP_Error( 'pc_points_redeem', '积分兑换未开启或参数无效。' ); }
		if ( self::event( 'release-failed:' . $request ) ) { return new \WP_Error( 'pc_points_retry', '此请求已退款，请刷新后重新兑换。' ); }
		$decimals = wc_get_price_decimals();
		if ( $decimals < 0 || $decimals > 8 ) { return new \WP_Error( 'pc_points_precision', '当前货币精度不支持积分兑换。' ); }
		$scale = 10 ** $decimals;
		$rate = max( 1, (int) $s['redeem_per_unit'] );
		if ( ( $points * $scale ) % $rate ) { return new \WP_Error( 'pc_points_fraction', sprintf( '请按 %d 积分的整数倍兑换，避免不足最小货币单位的舍入。', self::redemption_step() ) ); }
		$amount = intdiv( $points * $scale, $rate ) / $scale;
		if ( $amount <= 0 ) { return new \WP_Error( 'pc_points_value', '兑换金额过小。' ); }
		$code = 'pcp-' . substr( hash_hmac( 'sha256', $user . ':' . $request, wp_salt( 'nonce' ) ), 0, 24 );
		$result = self::change( $user, 'redeem:' . $request, 'redeemed', static function ( $a ) use ( $points ) {
			return $a['debt'] || $a['balance'] < $points ? new \WP_Error( 'pc_points_balance', '可用积分不足或有退款待抵扣积分。' ) : -$points;
		}, 0, 0, 'Personal coupon redemption', array( 'code' => $code, 'amount' => $amount, 'currency' => get_woocommerce_currency() ) );
		if ( is_wp_error( $result ) ) { return $result; }
		// A retry must use the original reservation's price, not newly edited rules or submitted points.
		$data = json_decode( (string) $result['data'], true );
		$code = (string) $data['code'];
		$cost = -(int) $result['delta'];
		if ( (int) $result['reference_id'] > 0 ) {
			try { $existing = self::fresh_coupon( (int) $result['reference_id'] ); } catch ( \Throwable $e ) { return new \WP_Error( 'pc_points_missing_coupon', '原兑换券暂不可读，请联系店铺核对流水。' ); }
			if ( ! $existing->get_id() || $existing->get_code() !== $code || 'yes' === $existing->get_meta( '_pc_loyalty_released' ) || self::event( 'release-coupon:' . $existing->get_id() ) ) { return new \WP_Error( 'pc_points_missing_coupon', '此兑换已处理，原兑换券已取消或不存在，请联系店铺核对流水。' ); }
			return $existing;
		}
		try {
			$c = new \WC_Coupon( $code );
			if ( ! $c->get_id() ) {
				$c->set_code( $code );
				$c->set_discount_type( 'fixed_cart' );
				$c->set_amount( (float) $data['amount'] );
				$c->set_usage_limit( 1 );
				$c->set_usage_limit_per_user( 1 );
				$c->set_individual_use( true );
				$c->set_email_restrictions( array( get_userdata( $user )->user_email ) );
				$c->set_date_expires( time() + (int) $s['expiry_days'] * DAY_IN_SECONDS );
				$c->set_description( 'Customer points redemption' );
				$c->update_meta_data( '_pc_loyalty_user', $user );
				$c->update_meta_data( '_pc_loyalty_points', $cost );
				$c->update_meta_data( '_pc_loyalty_request', $request );
				$c->update_meta_data( '_pc_loyalty_currency', $data['currency'] );
				if ( ! $c->save() ) { throw new \RuntimeException( 'coupon' ); }
				wp_schedule_single_event( $c->get_date_expires()->getTimestamp() + 60, 'pc_loyalty_expire_coupon', array( $c->get_id() ) );
			}
			global $wpdb;
			if ( 0 === (int) $result['reference_id'] && 1 !== (int) $wpdb->update( self::table( 'ledger' ), array( 'reference_id' => $c->get_id() ), array( 'id' => $result['id'], 'reference_id' => 0 ) ) ) { throw new \RuntimeException( 'reservation link' ); }
			return self::fresh_coupon( $c->get_id() );
		} catch ( \Throwable $e ) {
			if ( isset( $c ) && $c->get_id() ) { update_post_meta( $c->get_id(), '_pc_loyalty_released', 'yes' ); }
			$release = self::change( $user, 'release-failed:' . $request, 'redeem_release', static fn() => $cost, 0, 0, 'Coupon creation failed; reservation returned' );
			return new \WP_Error( 'pc_points_coupon', is_wp_error( $release ) ? '兑换暂未完成，积分预扣需要管理员核对流水后处理。' : '兑换券创建失败，已取消本次兑换并返还积分。' );
		}
	}

	public static function validate_coupon( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || ! $coupon->get_meta( '_pc_loyalty_user' ) ) { return $valid; }
		$reservation = self::event( 'redeem:' . $coupon->get_meta( '_pc_loyalty_request' ) );
		if ( ! $reservation || (int) $reservation['reference_id'] !== $coupon->get_id() || (int) $reservation['user_id'] !== (int) $coupon->get_meta( '_pc_loyalty_user' ) || -(int) $reservation['delta'] !== (int) $coupon->get_meta( '_pc_loyalty_points' ) || (int) $coupon->get_meta( '_pc_loyalty_user' ) !== get_current_user_id() || 'yes' === $coupon->get_meta( '_pc_loyalty_released' ) || self::event( 'release-coupon:' . $coupon->get_id() ) || self::event( 'release-failed:' . $coupon->get_meta( '_pc_loyalty_request' ) ) || $coupon->get_meta( '_pc_loyalty_currency' ) !== get_woocommerce_currency() ) {
			throw new \Exception( __( 'This points coupon is unavailable for this account.', 'peptide-core' ) );
		}
		$linked = (int) $coupon->get_meta( '_pc_loyalty_order' );
		$object = $discounts instanceof \WC_Discounts ? $discounts->get_object() : null;
		if ( $linked && ( ! $object instanceof \WC_Order || $object->get_id() !== $linked ) ) { throw new \Exception( __( 'This points coupon has already been assigned to an order.', 'peptide-core' ) ); }
		return $valid;
	}

	public static function link_coupons( $order ): void {
		if ( ! $order instanceof \WC_Order ) { return; }
		foreach ( $order->get_coupon_codes() as $code ) {
			$c = new \WC_Coupon( $code );
			if ( $c->get_meta( '_pc_loyalty_user' ) ) {
				$id = $c->get_id();
				$r = self::customer_lock( (int) $c->get_meta( '_pc_loyalty_user' ), static function () use ( $id, $order ) {
					$fresh = self::fresh_coupon( $id );
					$linked = (int) $fresh->get_meta( '_pc_loyalty_order' );
					if ( (int) $fresh->get_meta( '_pc_loyalty_user' ) !== (int) $order->get_customer_id() || 'yes' === $fresh->get_meta( '_pc_loyalty_released' ) || self::event( 'release-coupon:' . $id ) || self::event( 'release-failed:' . $fresh->get_meta( '_pc_loyalty_request' ) ) || ( $linked && $linked !== $order->get_id() ) ) { return new \WP_Error( 'pc_points_used', '此积分兑换券已不可用，请重新检查购物车。' ); }
					$fresh->update_meta_data( '_pc_loyalty_order', $order->get_id() );
					$fresh->save();
					foreach ( $order->get_items( 'coupon' ) as $item ) {
						if ( wc_format_coupon_code( $item->get_code() ) === $fresh->get_code() ) {
							$item->update_meta_data( '_pc_loyalty_coupon', $id );
							$item->update_meta_data( '_pc_loyalty_user', (int) $fresh->get_meta( '_pc_loyalty_user' ) );
							$item->update_meta_data( '_pc_loyalty_points', (int) $fresh->get_meta( '_pc_loyalty_points' ) );
							$item->update_meta_data( '_pc_loyalty_base', self::merchandise_total( $order ) );
							$item->update_meta_data( '_pc_loyalty_grand_total', (float) $order->get_total() );
							$item->save();
						}
					}
					global $wpdb;
					$wpdb->update( self::table( 'ledger' ), array( 'order_id' => $order->get_id() ), array( 'event_key' => 'redeem:' . $fresh->get_meta( '_pc_loyalty_request' ) ) );
					return true;
				} );
				if ( is_wp_error( $r ) ) { throw new \Exception( $r->get_error_message() ); }
			}
		}
	}

	public static function release_coupon( int $id, int $user ) {
		return self::customer_lock( $user, static fn() => self::release_locked( $id, $user ) );
	}

	private static function release_locked( int $id, int $user ) {
		$c = self::fresh_coupon( $id );
		if ( ! $c->get_id() || (int) $c->get_meta( '_pc_loyalty_user' ) !== $user || $c->get_meta( '_pc_loyalty_order' ) || $c->get_usage_count() ) { return new \WP_Error( 'pc_points_used', '此兑换券已关联订单，无法直接退回积分。' ); }
		$existing = self::event( 'release-coupon:' . $id );
		if ( $existing ) { update_post_meta( $id, '_pc_loyalty_released', 'yes' ); return $existing; }
		$reserved = self::event( 'redeem:' . $c->get_meta( '_pc_loyalty_request' ) );
		if ( ! $reserved || (int) $reserved['user_id'] !== $user || ( (int) $reserved['reference_id'] && (int) $reserved['reference_id'] !== $id ) || self::event( 'release-failed:' . $c->get_meta( '_pc_loyalty_request' ) ) || 'yes' === $c->get_meta( '_pc_loyalty_released' ) ) { return new \WP_Error( 'pc_points_returned', '此兑换已返还，或预扣流水无效。' ); }
		$cost = (int) $c->get_meta( '_pc_loyalty_points' );
		if ( $cost !== -(int) $reserved['delta'] ) { return new \WP_Error( 'pc_points_mismatch', '兑换券与预扣流水不一致，需要管理员核对。' ); }
		$result = self::change( $user, 'release-coupon:' . $id, 'redeem_release', static fn() => $cost, 0, $id, 'Unused points coupon cancelled/expired' );
		if ( ! is_wp_error( $result ) ) {
			// The ledger is the financial authority even if WooCommerce's cosmetic metadata write fails.
			try { $c->update_meta_data( '_pc_loyalty_released', 'yes' ); $c->save(); } catch ( \Throwable $e ) { /* A retry repairs the marker; validation already denies the released ledger event. */ }
		}
		return $result;
	}

	public static function expire_coupon( $id ): void {
		$c = self::fresh_coupon( (int) $id );
		if ( $c->get_date_expires() && $c->get_date_expires()->getTimestamp() < time() && $c->get_meta( '_pc_loyalty_user' ) ) { self::release_coupon( (int) $id, (int) $c->get_meta( '_pc_loyalty_user' ) ); }
	}

	public static function deleting_coupon( $id ): void {
		if ( 'shop_coupon' !== get_post_type( $id ) ) { return; }
		$user = (int) get_post_meta( $id, '_pc_loyalty_user', true );
		if ( $user ) { self::release_coupon( (int) $id, $user ); }
	}

	public static function account_menu( array $items ): array {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ] = __( 'My points', 'peptide-core' );
		if ( null !== $logout ) { $items['customer-logout'] = $logout; }
		return $items;
	}

	public static function kind_label( string $kind ): string {
		$labels = array( 'adjustment' => 'Manual adjustment', 'earned' => 'Order points earned', 'earn_reversal' => 'Order points reversed', 'redeemed' => 'Coupon redemption', 'redeem_return' => 'Order refund / cancellation return', 'redeem_release' => 'Unused coupon points returned' );
		return __( $labels[ $kind ] ?? $kind, 'peptide-core' );
	}

	public static function redemption_step(): int {
		$a = max( 1, (int) self::settings()['redeem_per_unit'] ); $b = 10 ** min( 8, max( 0, wc_get_price_decimals() ) ); $rate = $a;
		while ( $b ) { $remainder = $a % $b; $a = $b; $b = $remainder; }
		return intdiv( $rate, $a );
	}

	public static function redeem_action(): void {
		check_admin_referer( 'pc_points_redeem' );
		$r = self::redeem( get_current_user_id(), absint( $_POST['points'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['request'] ?? '' ) ) );
		wc_add_notice( is_wp_error( $r ) ? $r->get_error_message() : sprintf( __( 'Your points coupon: %s', 'peptide-core' ), $r->get_code() ), is_wp_error( $r ) ? 'error' : 'success' );
		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) ); exit;
	}

	public static function release_action(): void {
		check_admin_referer( 'pc_points_release' );
		$r = is_user_logged_in() ? self::release_coupon( absint( $_POST['coupon_id'] ?? 0 ), get_current_user_id() ) : new \WP_Error( 'login', 'Please sign in.' );
		wc_add_notice( is_wp_error( $r ) ? $r->get_error_message() : __( 'Unused coupon cancelled and points returned.', 'peptide-core' ), is_wp_error( $r ) ? 'error' : 'success' );
		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) ); exit;
	}

	public static function account_page(): void {
		$user = get_current_user_id();
		if ( ! Customer_Segments::is_customer( $user ) ) { echo '<p>' . esc_html__( 'Points are available to customer accounts.', 'peptide-core' ) . '</p>'; return; }
		$a = self::account( $user ); $s = self::settings();
		echo '<h2>' . esc_html__( 'My points', 'peptide-core' ) . '</h2><p>' . esc_html( sprintf( __( 'Available points: %d', 'peptide-core' ), $a['balance'] ) ) . '</p>';
		if ( $a['debt'] ) { echo '<p>' . esc_html( sprintf( __( 'Refund recovery: %d points. Future earnings first offset this amount.', 'peptide-core' ), $a['debt'] ) ) . '</p>'; }
		if ( $s['enabled'] ) { echo '<p>' . esc_html( sprintf( __( 'Earn %s points per currency unit of paid merchandise, after discounts and excluding tax and shipping. New orders only.', 'peptide-core' ), (string) $s['earn_per_unit'] ) ) . '</p>'; }
		if ( $s['redeem_enabled'] ) {
			$step = self::redemption_step(); $minimum = (int) ceil( $s['minimum'] / $step ) * $step;
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pc_points_redeem"><input type="hidden" name="request" value="' . esc_attr( wp_generate_uuid4() ) . '">'; wp_nonce_field( 'pc_points_redeem' );
			echo '<p>' . esc_html( sprintf( __( '%d points = 1 currency unit; minimum %d points. Coupons expire in %d days.', 'peptide-core' ), $s['redeem_per_unit'], $minimum, $s['expiry_days'] ) ) . ' ' . esc_html( sprintf( __( 'Redeem in multiples of %d points.', 'peptide-core' ), $step ) ) . '</p><p><label>' . esc_html__( 'Points to redeem', 'peptide-core' ) . ' <input name="points" type="number" min="' . $minimum . '" max="' . (int) $a['balance'] . '" step="' . $step . '" required></label> <button class="button">' . esc_html__( 'Redeem', 'peptide-core' ) . '</button></p></form>';
		}
		$coupons = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => 50, 'meta_key' => '_pc_loyalty_user', 'meta_value' => $user, 'fields' => 'ids' ) );
		foreach ( $coupons as $id ) {
			$c = new \WC_Coupon( $id ); if ( 'yes' === $c->get_meta( '_pc_loyalty_released' ) || $c->get_usage_count() ) { continue; }
			echo '<p><code>' . esc_html( $c->get_code() ) . '</code> — ' . wp_kses_post( wc_price( (float) $c->get_amount() ) ) . '</p>';
			if ( ! $c->get_meta( '_pc_loyalty_order' ) ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pc_points_release"><input type="hidden" name="coupon_id" value="' . (int) $id . '">'; wp_nonce_field( 'pc_points_release' ); echo '<button class="button">' . esc_html__( 'Cancel unused coupon and return points', 'peptide-core' ) . '</button></form>'; }
		}
		$page = max( 1, absint( $_GET['points_page'] ?? 1 ) );
		$rows = self::ledger( $user, 21, ( $page - 1 ) * 20 );
		echo '<h3>' . esc_html__( 'Points history', 'peptide-core' ) . '</h3><table class="shop_table"><thead><tr><th>' . esc_html__( 'Date (UTC)', 'peptide-core' ) . '</th><th>' . esc_html__( 'Event', 'peptide-core' ) . '</th><th>' . esc_html__( 'Change', 'peptide-core' ) . '</th><th>' . esc_html__( 'Balance', 'peptide-core' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, 20 ) as $r ) { echo '<tr><td>' . esc_html( $r['created_at'] ) . '</td><td>' . esc_html( self::kind_label( $r['kind'] ) ) . '</td><td>' . (int) $r['delta'] . '</td><td>' . (int) $r['balance'] . '</td></tr>'; }
		echo '</tbody></table><p>';
		if ( $page > 1 ) { echo '<a href="' . esc_url( add_query_arg( 'points_page', $page - 1 ) ) . '">' . esc_html__( 'Previous', 'peptide-core' ) . '</a> '; }
		if ( count( $rows ) > 20 ) { echo '<a href="' . esc_url( add_query_arg( 'points_page', $page + 1 ) ) . '">' . esc_html__( 'Next', 'peptide-core' ) . '</a>'; }
		echo '</p>';
	}
}
