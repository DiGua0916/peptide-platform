<?php
/**
 * 访客追踪正式版的隐私部分（TRACKING-LIVE-01，用户 2026-10-27 要求接入正式版）：
 *
 * - 保存期限：每天清理一次超过保存天数（设置里选 30 / 90 / 180 / 365 天，默认 180）的访问、事件、标签页和访客。
 * - 顾客的隐私请求：接入 WordPress 的“导出个人数据 / 抹除个人数据”。按邮箱找到账号和订单，
 *   再找到关联的访客，导出访问记录或删除这些访客的全部追踪数据。
 * - 隐私政策：在 WordPress 的隐私政策指南里提供一段参考文字（店铺前台是英文，所以是英文）。
 *
 * 不追踪的人（GPC 信号、退出链接、员工）见 Tracker::declined() 和 Tracker::is_staff()。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	public const PRUNE_HOOK = 'pc_trk_prune';
	public const BATCH      = 500;

	public static function register_hooks(): void {
		add_action( self::PRUNE_HOOK, array( self::class, 'prune_now' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'add_eraser' ) );
		add_action( 'admin_init', array( self::class, 'policy_text' ) );
		// 开着追踪却没有排好每天的清理时补上（例如从测试版升级上来）。
		if ( Tracker::enabled() && ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			self::schedule( true );
		}
	}

	/** 开启时排好每天的清理，关闭时取消。 */
	public static function schedule( bool $on ): void {
		$next = wp_next_scheduled( self::PRUNE_HOOK );
		if ( $on && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		} elseif ( ! $on && $next ) {
			wp_clear_scheduled_hook( self::PRUNE_HOOK );
		}
	}

	public static function prune_now(): void {
		if ( Schema::installed() ) {
			self::prune( Tracker::settings()['retention_days'], Tracker::now_ms() );
		}
	}

	/**
	 * 删除超过保存天数的数据。返回删掉的访问数。
	 */
	public static function prune( int $days, int $now_ms ): int {
		global $wpdb;
		$cutoff  = $now_ms - $days * DAY_IN_SECONDS * 1000;
		$visits  = Schema::table( 'visits' );
		$events  = Schema::table( 'events' );
		$removed = 0;
		// 旧访问和它们的事件。
		do {
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$visits} WHERE last_ms < %d LIMIT %d", $cutoff, self::BATCH ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( $ids ) {
				$in = implode( ',', $ids );
				$wpdb->query( "DELETE FROM {$events} WHERE visit_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$wpdb->query( "DELETE FROM {$visits} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$removed += count( $ids );
			}
		} while ( count( $ids ) === self::BATCH );
		// 不属于任何访问的旧事件、旧标签页。
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE visit_id = 0 AND occurred_ms < %d", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'tabs' ) . ' WHERE last_signal_ms < %d', $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		// 很久没来过的访客。
		do {
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'visitors' ) . ' WHERE last_ms < %d LIMIT %d', $cutoff, self::BATCH ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			self::delete_visitors( $ids );
		} while ( count( $ids ) === self::BATCH );
		return $removed;
	}

	/**
	 * 删除这些访客的全部追踪数据。
	 *
	 * @param int[] $ids
	 */
	public static function delete_visitors( array $ids ): void {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return;
		}
		$in = implode( ',', $ids );
		foreach ( array( 'events', 'tabs', 'visits' ) as $name ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $name ) . " WHERE visitor_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'visitors' ) . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	// ---------- 顾客的隐私请求 ----------

	/**
	 * 邮箱对应的访客：这个邮箱的账号关联的访客，以及用这个邮箱下单的访问所属的访客。
	 *
	 * @return int[]
	 */
	public static function visitors_for_email( string $email ): array {
		global $wpdb;
		if ( ! Schema::installed() || ! is_email( $email ) ) {
			return array();
		}
		$ids  = array();
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$ids = array_merge(
				$ids,
				$wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'visitors' ) . ' WHERE user_id = %d', $user->ID ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
				$wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT visitor_id FROM ' . Schema::table( 'visits' ) . ' WHERE user_id = %d', $user->ID ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			);
		}
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = array_map( 'intval', wc_get_orders( array( 'billing_email' => $email, 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order' ) ) );
			if ( $orders ) {
				$ids = array_merge( $ids, $wpdb->get_col( 'SELECT DISTINCT visitor_id FROM ' . Schema::table( 'visits' ) . ' WHERE order_id IN (' . implode( ',', $orders ) . ')' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			}
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	public static function add_exporter( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$exporters['peptide-core-visits'] = array(
			'exporter_friendly_name' => __( 'Store visits', 'peptide-core' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	public static function add_eraser( $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : array();
		$erasers['peptide-core-visits'] = array(
			'eraser_friendly_name' => __( 'Store visits', 'peptide-core' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * 导出：每次访问一条（开始时间、着陆页、设备、看过的页面数、订单号）。
	 */
	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$items = array();
		$ids   = self::visitors_for_email( $email );
		if ( $ids ) {
			$in   = implode( ',', $ids );
			$rows = $wpdb->get_results( 'SELECT id, start_ms, last_ms, landing_path, device, order_id FROM ' . Schema::table( 'visits' ) . " WHERE visitor_id IN ({$in}) ORDER BY start_ms ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $r ) {
				$pages   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . " WHERE visit_id = %d AND type = 'page_view'", (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
				$items[] = array(
					'group_id'    => 'peptide-core-visits',
					'group_label' => __( 'Store visits', 'peptide-core' ),
					'item_id'     => 'visit-' . (int) $r['id'],
					'data'        => array(
						array( 'name' => __( 'Started', 'peptide-core' ), 'value' => gmdate( 'Y-m-d H:i', (int) floor( (int) $r['start_ms'] / 1000 ) ) . ' UTC' ),
						array( 'name' => __( 'Last activity', 'peptide-core' ), 'value' => gmdate( 'Y-m-d H:i', (int) floor( (int) $r['last_ms'] / 1000 ) ) . ' UTC' ),
						array( 'name' => __( 'Landing page', 'peptide-core' ), 'value' => (string) $r['landing_path'] ),
						array( 'name' => __( 'Device', 'peptide-core' ), 'value' => (string) $r['device'] ),
						array( 'name' => __( 'Pages viewed', 'peptide-core' ), 'value' => (string) $pages ),
						array( 'name' => __( 'Order', 'peptide-core' ), 'value' => (int) $r['order_id'] ? '#' . (int) $r['order_id'] : '—' ),
					),
				);
			}
		}
		return array( 'data' => $items, 'done' => true );
	}

	/**
	 * 抹除：删除这些访客的全部追踪数据（不影响订单本身）。
	 */
	public static function erase( string $email, int $page = 1 ): array {
		$ids = self::visitors_for_email( $email );
		self::delete_visitors( $ids );
		return array(
			'items_removed'  => (bool) $ids,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/** 隐私政策指南里的参考文字（前台是英文）。 */
	public static function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$days = Tracker::settings()['retention_days'];
		$text = '<p>' . sprintf(
			/* translators: %d: number of days */
			esc_html__( 'When you browse this store we record which pages and products you view, what you add to your cart and whether you check out, so we can understand how the store is used and improve it. We use a first-party cookie (pc_vid) that only identifies your browser, not you. If you sign in or place an order, these visits are linked to your account or order. The records are kept for %d days and are not sold or shared with advertisers. Browsers that send the Global Privacy Control signal, and browsers that used our opt-out link, are not recorded. You can ask us to export or delete this data.', 'peptide-core' ),
			$days
		) . '</p>';
		wp_add_privacy_policy_content( __( 'Store visit analytics', 'peptide-core' ), wp_kses_post( $text ) );
	}
}
