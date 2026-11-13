<?php
/**
 * 退款手动标记（OR-03）。支付通道是第三方的，系统里不做退款；收单方线下通知退款后，
 * 由平台所有者在总后台标记：金额（全额或部分）、日期、备注。标错可以撤销。
 *
 * - 每次标记在 pchq_refunds 写一行；撤销只在那一行上写 voided_at / voided_by，不删行，所以每次操作都有记录。
 * - 标记或撤销后：重算 pchq_orders.refunded_cents 和 refund_state → 重算 pchq_daily 的净额 → 回写店铺
 *   POST /peptide/v1/hq/order-refund（店铺只写两个订单元和一条备注，不创建 WooCommerce 退款对象）。
 * - 店铺暂时连不上时订单记为“待回写”（refund_sync = 1），每 10 分钟的巡检之后重试。
 * - 金额必须大于 0，且不超过订单金额减已标记的退款；只有总后台管理员可以标记或撤销。
 * - pchq_daily 的数字每次轮询会被店铺的汇总覆盖（那时 gross_cents / refunded_cents 回到 0），
 *   所以轮询之后要把净额再算一遍（apply_daily）。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Refunds {

	public const ROUTE = '/peptide/v1/hq/order-refund';
	public const CAP   = 'manage_options';

	public static function register_hooks(): void {
		// 轮询（优先级 10）把 pchq_daily 覆盖之后，重新扣掉已标记的退款；然后重试没回写成功的。
		add_action( Monitor::CRON, array( self::class, 'apply_daily' ), 20, 0 );
		add_action( Monitor::POLL_ONE, array( self::class, 'apply_daily' ), 20, 1 );
		add_action( Monitor::CRON, array( self::class, 'retry_pending' ), 40, 0 );
		// 店铺页的“立即刷新”、改服务器、改设置也会轮询并覆盖 pchq_daily；它们处理完直接退出，所以挂在 shutdown 上再算一遍。
		foreach ( array( 'pchq_site_refresh', 'pchq_site_server', 'pchq_site_config' ) as $action ) {
			add_action(
				'admin_post_' . $action,
				static function (): void {
					add_action( 'shutdown', array( self::class, 'apply_daily' ), 10, 0 );
				},
				1
			);
		}
	}

	public static function table(): string {
		return Db::table( 'refunds' );
	}

	// ---------- 纯函数 ----------

	/** 退款状态：none / partial / full。 */
	public static function state( int $total_cents, int $refunded_cents ): string {
		if ( $refunded_cents <= 0 ) {
			return 'none';
		}
		return $refunded_cents >= $total_cents ? 'full' : 'partial';
	}

	/**
	 * 标记前的检查。通过返回 null，否则返回给操作人看的原因。
	 *
	 * @param string $today 今天的日期（总后台时区），退款日期不能晚于它
	 */
	public static function check( int $total_cents, int $already_cents, int $amount_cents, string $refunded_on, bool $was_paid, string $today ): ?string {
		if ( ! $was_paid ) {
			return '这个订单没有付款记录，不能标记退款。';
		}
		if ( $amount_cents <= 0 ) {
			return '退款金额必须大于 0。';
		}
		$left = $total_cents - $already_cents;
		if ( $amount_cents > $left ) {
			return sprintf( '退款金额超过了可退金额（订单 %1$s，已标记 %2$s，最多还能标记 %3$s）。', Orders::money( $total_cents ), Orders::money( $already_cents ), Orders::money( max( 0, $left ) ) );
		}
		if ( ! Orders::valid_day( $refunded_on ) ) {
			return '退款日期无效，格式是 YYYY-MM-DD。';
		}
		if ( $refunded_on > $today ) {
			return '退款日期不能晚于今天。';
		}
		return null;
	}

	/**
	 * pchq_daily 一行该怎么改（纯函数）。不用改时返回 null。
	 *
	 * 行刚被轮询覆盖时 gross_cents 和 refunded_cents 都是 0，这时 revenue 就是店铺报的毛额；
	 * 已经调整过的行，毛额以 gross_cents 为准。净额 = 毛额 − 这一天订单上已标记的退款。
	 *
	 * @param int $want 这一天（按下单日）的订单上已标记的退款合计
	 * @return array{revenue:float,gross_cents:int,refunded_cents:int}|null
	 */
	public static function adjust_daily( float $revenue, int $gross_cents, int $refunded_cents, int $want ): ?array {
		$fresh = 0 === $gross_cents && 0 === $refunded_cents;
		if ( 0 === $want && 0 === $refunded_cents ) {
			return null;
		}
		if ( ! $fresh && $want === $refunded_cents ) {
			return null;
		}
		$gross = $fresh ? (int) round( $revenue * 100 ) : $gross_cents;
		return array(
			'revenue'        => round( ( $gross - $want ) / 100, 2 ),
			'gross_cents'    => $gross,
			'refunded_cents' => $want,
		);
	}

	// ---------- 标记、撤销 ----------

	private static function allowed( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, self::CAP );
	}

	/**
	 * 标记一笔退款。
	 *
	 * @return array{id:int,pushed:bool,message:string}|\WP_Error
	 */
	public static function mark( int $site_id, int $store_order_id, int $amount_cents, string $refunded_on, string $note, int $user_id ) {
		global $wpdb;
		if ( ! self::allowed( $user_id ) ) {
			return new \WP_Error( 'pchq_refund_cap', '只有总后台管理员可以标记退款。' );
		}
		$order = Orders::get( $site_id, $store_order_id );
		if ( ! $order ) {
			return new \WP_Error( 'pchq_refund_order', '订单不存在。' );
		}
		$error = self::check( $order['total_cents'], $order['refunded_cents'], $amount_cents, $refunded_on, 1 === $order['paid'] || $order['paid_at'] > 0, wp_date( 'Y-m-d' ) );
		if ( null !== $error ) {
			return new \WP_Error( 'pchq_refund_invalid', $error );
		}
		$note = mb_substr( trim( sanitize_text_field( $note ) ), 0, 255 );
		$ok   = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'site_id'        => $site_id,
				'store_order_id' => $store_order_id,
				'amount_cents'   => $amount_cents,
				'refunded_on'    => $refunded_on,
				'note'           => $note,
				'created_by'     => $user_id,
				'created_at'     => time(),
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'pchq_refund_save', '退款标记没能保存，请重试。' );
		}
		$id = (int) $wpdb->insert_id;
		self::recalc( $site_id, $store_order_id );
		$pushed = self::push( $site_id, $store_order_id, $note );
		return array( 'id' => $id, 'pushed' => true === $pushed, 'message' => is_wp_error( $pushed ) ? $pushed->get_error_message() : '' );
	}

	/**
	 * 撤销一笔已标记的退款（保留记录）。
	 *
	 * @return array{id:int,pushed:bool,message:string}|\WP_Error
	 */
	public static function void( int $refund_id, int $user_id ) {
		global $wpdb;
		if ( ! self::allowed( $user_id ) ) {
			return new \WP_Error( 'pchq_refund_cap', '只有总后台管理员可以撤销退款标记。' );
		}
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $refund_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $row ) {
			return new \WP_Error( 'pchq_refund_missing', '这条退款记录不存在。' );
		}
		if ( (int) $row['voided_at'] > 0 ) {
			return new \WP_Error( 'pchq_refund_voided', '这条退款记录已经撤销过了。' );
		}
		$wpdb->update( $t, array( 'voided_at' => time(), 'voided_by' => $user_id ), array( 'id' => $refund_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$site_id = (int) $row['site_id'];
		$order   = (int) $row['store_order_id'];
		self::recalc( $site_id, $order );
		$pushed = self::push( $site_id, $order, '撤销退款标记' );
		return array( 'id' => $refund_id, 'pushed' => true === $pushed, 'message' => is_wp_error( $pushed ) ? $pushed->get_error_message() : '' );
	}

	/** 一个订单的全部退款记录（含已撤销的），新的在前。 */
	public static function for_order( int $site_id, int $store_order_id ): array {
		global $wpdb;
		if ( ! Orders::ready() ) {
			return array();
		}
		$t = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE site_id = %d AND store_order_id = %d ORDER BY id DESC", $site_id, $store_order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 按有效的退款记录重算订单的已退款金额和状态，标为待回写，并重算这家店的每日净额。
	 *
	 * @return array{refunded_cents:int,state:string}
	 */
	public static function recalc( int $site_id, int $store_order_id ): array {
		global $wpdb;
		$t        = self::table();
		$o        = Orders::table();
		$order    = Orders::get( $site_id, $store_order_id );
		$refunded = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_cents),0) FROM {$t} WHERE site_id = %d AND store_order_id = %d AND voided_at = 0", $site_id, $store_order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$state    = self::state( $order ? $order['total_cents'] : 0, $refunded );
		if ( $order && ( $order['refunded_cents'] !== $refunded || $order['refund_state'] !== $state ) ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$o,
				array(
					'refunded_cents' => $refunded,
					'refund_state'   => $state,
					'refund_sync'    => 1,
					'updated_at'     => time(),
				),
				array( 'id' => $order['id'] )
			);
			self::apply_daily( $site_id );
		}
		return array( 'refunded_cents' => $refunded, 'state' => $state );
	}

	/** 店铺重新上报订单之后：金额可能变了，有退款标记的订单重算一次状态。 */
	public static function after_report( int $site_id, array $store_order_ids ): void {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $store_order_ids ) ) );
		if ( ! $ids ) {
			return;
		}
		$t    = self::table();
		$in   = implode( ',', $ids );
		$with = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT store_order_id FROM {$t} WHERE site_id = %d AND store_order_id IN ({$in})", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( $with as $order_id ) {
			self::recalc( $site_id, (int) $order_id );
		}
	}

	// ---------- 回写店铺 ----------

	/**
	 * 把订单现在的退款合计和状态写回店铺。失败时订单保持“待回写”，之后自动重试。
	 *
	 * @return true|\WP_Error
	 */
	public static function push( int $site_id, int $store_order_id, string $note = '' ) {
		global $wpdb;
		$order = Orders::get( $site_id, $store_order_id );
		if ( ! $order ) {
			return new \WP_Error( 'pchq_refund_order', '订单不存在。' );
		}
		if ( is_callable( array( Client::class, 'supports' ) ) && ! Client::supports( $site_id, 'orders.refund' ) ) {
			// 店铺还没声明支持退款标记：先记为待回写，店铺程序更新后的巡检会自动写回。
			$wpdb->update( Orders::table(), array( 'refund_sync' => 1, 'refund_error' => '店铺程序还不支持退款标记，更新店铺程序后会自动写回。' ), array( 'id' => $order['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new \WP_Error( 'pchq_refund_old', '已在总后台标记；这家店的程序还不支持退款标记，更新店铺程序后会自动写回店铺。' );
		}
		$res = Client::request(
			$site_id,
			'POST',
			self::ROUTE,
			array(),
			array(
				'order_id'       => $store_order_id,
				'refunded_cents' => $order['refunded_cents'],
				'state'          => $order['refund_state'],
				'note'           => $note,
			)
		);
		$ok = ! is_wp_error( $res ) && isset( $res['refunded_cents'] ) && (int) $res['refunded_cents'] === $order['refunded_cents'];
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Orders::table(),
			array(
				'refund_sync'  => $ok ? 0 : 1,
				'refund_error' => $ok ? '' : mb_substr( is_wp_error( $res ) ? self::reason( $res ) : '店铺返回的退款金额和总后台不一致。', 0, 190 ),
				'updated_at'   => time(), // 重试按它从旧到新轮流，失败的排到后面去。
			),
			array( 'id' => $order['id'] )
		);
		if ( $ok ) {
			return true;
		}
		return is_wp_error( $res ) ? new \WP_Error( $res->get_error_code(), '已在总后台标记，但还没写回店铺（' . self::reason( $res ) . '），系统会自动重试。' ) : new \WP_Error( 'pchq_refund_reply', '已在总后台标记，但店铺返回的退款金额不一致，系统会自动重试。' );
	}

	/** 回写失败的原因，说给平台所有者听。 */
	private static function reason( \WP_Error $e ): string {
		if ( preg_match( '/No route was found|rest_no_route|HTTP status 404/i', $e->get_error_message() ) ) {
			return '店铺程序还不支持退款标记，更新店铺程序后会自动写回';
		}
		return 'pchq_offline' === $e->get_error_code() ? '店铺暂时连不上' : $e->get_error_message();
	}

	/** 重试没回写成功的订单，一次最多 20 个。 */
	public static function retry_pending(): void {
		global $wpdb;
		if ( ! Orders::ready() ) {
			return;
		}
		$o = Orders::table();
		foreach ( (array) $wpdb->get_results( "SELECT site_id, store_order_id FROM {$o} WHERE refund_sync = 1 ORDER BY updated_at ASC LIMIT 20", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( Site_Lifecycle::active( (int) $r['site_id'] ) ) {
				self::push( (int) $r['site_id'], (int) $r['store_order_id'] );
			} else {
				// 店铺已下线：不再重试，留下原因。
				$wpdb->update( $o, array( 'refund_sync' => 0, 'refund_error' => '店铺已下线，退款标记只记在总后台。' ), array( 'site_id' => (int) $r['site_id'], 'store_order_id' => (int) $r['store_order_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/** 待回写的订单数（订单页提示用）。 */
	public static function pending_count(): int {
		global $wpdb;
		if ( ! Orders::ready() ) {
			return 0;
		}
		$o = Orders::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$o} WHERE refund_sync = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	// ---------- 每日净额 ----------

	/**
	 * 把 pchq_daily 的销售额改成净额：按下单日，把这一天已付款订单上已标记的退款从毛额里扣掉。
	 * $site_id 为 0 时处理所有店铺。可以反复执行，结果不变。
	 */
	public static function apply_daily( $site_id = 0 ): void {
		global $wpdb;
		if ( ! Orders::ready() ) {
			return;
		}
		$site_id = (int) $site_id;
		$o       = Orders::table();
		$d       = Db::table( 'daily' );
		$where   = $site_id > 0 ? $wpdb->prepare( ' AND site_id = %d', $site_id ) : '';
		$want    = array();
		foreach ( (array) $wpdb->get_results( "SELECT site_id, order_day, SUM(refunded_cents) AS refunded FROM {$o} WHERE refunded_cents > 0 AND paid = 1 AND order_day <> ''{$where} GROUP BY site_id, order_day", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$want[ (int) $r['site_id'] . '|' . $r['order_day'] ] = (int) $r['refunded'];
		}
		// 以前扣过、现在可能已经撤销的日期也要过一遍。
		foreach ( (array) $wpdb->get_results( "SELECT site_id, day FROM {$d} WHERE refunded_cents > 0{$where}", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$key          = (int) $r['site_id'] . '|' . $r['day'];
			$want[ $key ] = $want[ $key ] ?? 0;
		}
		foreach ( $want as $key => $refunded ) {
			[ $sid, $day ] = explode( '|', (string) $key );
			$row           = $wpdb->get_row( $wpdb->prepare( "SELECT revenue, gross_cents, refunded_cents FROM {$d} WHERE site_id = %d AND day = %s", (int) $sid, $day ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! $row ) {
				continue; // 这一天总后台没有这家店的数字（太早的订单），不用改。
			}
			$fix = self::adjust_daily( (float) $row['revenue'], (int) $row['gross_cents'], (int) $row['refunded_cents'], $refunded );
			if ( null !== $fix ) {
				$wpdb->update( $d, $fix, array( 'site_id' => (int) $sid, 'day' => $day ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}
}
