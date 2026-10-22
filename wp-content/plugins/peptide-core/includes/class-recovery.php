<?php
/**
 * 弃单召回（CART-RECOVERY-01，2026-10-23 按 Shopname 改为三阶段：CART-RECOVERY-02）。
 *
 * 弃单：顾客在结账页填了邮箱和地址，购物车里有商品，但没有付款。
 * 数据来源是 WooCommerce 会话（woocommerce_sessions）：区块结账填写时会把邮箱、地址写进会话的 customer，
 * 会话里还有购物车、选中的配送 / 支付方式和结账草稿订单（store_api_draft_order，用来显示订单编号）。不依赖访客追踪。
 *
 * 状态（和 Shopname 的“弃单”列表一致）：
 * - pending 待召回：已记录，还没有发提醒；
 * - recalling 召回中：已经发了提醒，三封还没发完；
 * - success 召回成功：顾客点了邮件里的链接回来，或者在收到提醒后付了款；
 * - failed 召回失败：开启的提醒都发完了，最后一封之后 24 小时内没有回来（或会话已过期）；
 * - void 已作废：没等提醒就自己付了款、清空了购物车、会话在发提醒前就过期、退订，或在后台手动作废。
 *
 * 提醒邮件是三封 WooCommerce 邮件（Emails\Cart_Recovery_1/2/3），各有开关、优惠券和延迟秒数，
 * 在“系统设置 → 邮件通知 → 弃单通知”里设置。WP-Cron 每 5 分钟扫描一次（也可以 wp peptide recovery scan）。
 * 没有店铺实体地址或发件邮箱无效时不发（美国 CAN-SPAM）。退订的邮箱只保存哈希。员工账号不记录。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

use PeptideCore\Emails\Admin_Abandoned;
use PeptideCore\Emails\Cart_Recovery;

defined( 'ABSPATH' ) || exit;

final class Recovery {

	public const OPTOUT     = 'peptide_core_recovery_optout';
	public const DB_OPTION  = 'peptide_core_recovery_db';
	public const DB_VERSION = '2';
	public const CRON       = 'peptide_core_recovery_scan';

	public const STATUSES = array( 'pending', 'recalling', 'success', 'failed', 'void' );
	public const OPEN     = array( 'pending', 'recalling' );

	/** 最后一封提醒发出后多久没回来算召回失败。 */
	public const FAIL_AFTER_MS = DAY_IN_SECONDS * 1000;

	public static function register_hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'cron_schedule' ) );
		add_action( self::CRON, array( self::class, 'scan' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( 'wp_loaded', array( self::class, 'handle_links' ), 40 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'on_paid' ), 30 );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_status' ), 30, 4 );
	}

	// ---------- 邮件 ----------

	/** 第 n 阶段的提醒邮件（1–3）。 */
	public static function email( int $stage ): ?Cart_Recovery {
		$emails = function_exists( 'WC' ) ? WC()->mailer()->get_emails() : array();
		$e      = $emails[ 'PC_Email_Cart_Recovery_' . $stage ] ?? null;
		return $e instanceof Cart_Recovery ? $e : null;
	}

	public static function admin_email(): ?Admin_Abandoned {
		$e = WC()->mailer()->get_emails()['PC_Email_Admin_Abandoned'] ?? null;
		return $e instanceof Admin_Abandoned ? $e : null;
	}

	/**
	 * 有开启的提醒（或管理员弃单通知）时才需要扫描。直接读邮件设置，不加载邮件类（init 时每个请求都会调用）。
	 * 顾客提醒默认开启，管理员通知默认关闭。
	 */
	public static function enabled(): bool {
		$on = static function ( string $id, string $default ): bool {
			$o = get_option( 'woocommerce_' . $id . '_settings', array() );
			return 'yes' === ( is_array( $o ) && isset( $o['enabled'] ) ? $o['enabled'] : $default );
		};
		return $on( 'pc_cart_recovery_1', 'yes' ) || $on( 'pc_cart_recovery_2', 'yes' ) || $on( 'pc_cart_recovery_3', 'yes' ) || $on( 'pc_admin_abandoned', 'no' );
	}

	/** 店铺实体地址（WooCommerce 店铺地址）。没有地址时不发营销邮件。 */
	public static function store_address(): string {
		return get_option( 'woocommerce_store_address' ) && get_option( 'woocommerce_store_city' ) ? Store_Config::address_line() : '';
	}

	/** 不能发送的原因：address（缺店铺地址）、sender（发件邮箱无效）；可以发送时为空。 */
	public static function blocked_reason(): string {
		if ( '' === self::store_address() ) {
			return 'address';
		}
		return is_email( (string) get_option( 'woocommerce_email_from_address' ) ) ? '' : 'sender';
	}

	// ---------- 表 ----------

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_recovery';
	}

	public static function installed(): bool {
		return self::DB_VERSION === get_option( self::DB_OPTION );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t      = self::table();
		$before = (string) get_option( self::DB_OPTION );
		dbDelta(
			"CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_key varchar(64) NOT NULL,
  email varchar(190) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(190) NOT NULL DEFAULT '',
  cart longtext NULL,
  cart_hash char(32) NOT NULL DEFAULT '',
  items int(10) unsigned NOT NULL DEFAULT 0,
  subtotal varchar(32) NOT NULL DEFAULT '0',
  total varchar(32) NOT NULL DEFAULT '',
  currency varchar(8) NOT NULL DEFAULT '',
  country varchar(4) NOT NULL DEFAULT '',
  ship_method varchar(190) NOT NULL DEFAULT '',
  pay_method varchar(190) NOT NULL DEFAULT '',
  draft_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'pending',
  stage tinyint(3) unsigned NOT NULL DEFAULT 0,
  emails tinyint(3) unsigned NOT NULL DEFAULT 0,
  changed_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  sent1_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  sent2_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  sent3_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  last_sent_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  admin_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  clicked_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_total varchar(32) NOT NULL DEFAULT '',
  paid_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  token char(32) NOT NULL DEFAULT '',
  created_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  note varchar(190) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY session_status (session_key,status),
  KEY email (email),
  KEY status (status),
  KEY created_ms (created_ms)
) {$wpdb->get_charset_collate()};"
		);
		if ( '1' === $before ) {
			// v1 → v2：旧状态换成 Shopname 的五种状态。
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "UPDATE {$t} SET status = IF(stage > 0, 'recalling', 'pending'), emails = stage, last_sent_ms = GREATEST(sent1_ms, sent2_ms) WHERE status = 'open'" );
			$wpdb->query( "UPDATE {$t} SET status = 'success', note = 'paid', paid_ms = updated_ms, emails = stage WHERE status = 'recovered'" );
			$wpdb->query( "UPDATE {$t} SET status = 'void', note = 'paid before reminder', paid_ms = updated_ms WHERE status = 'converted'" );
			$wpdb->query( "UPDATE {$t} SET status = IF(stage > 0, 'failed', 'void') WHERE status IN ('closed','expired','unsubscribed')" );
			// phpcs:enable
			delete_option( 'peptide_core_recovery' );
		}
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	private static function now_ms(): int {
		return (int) floor( microtime( true ) * 1000 );
	}

	// ---------- 定时扫描 ----------

	public static function cron_schedule( $schedules ) {
		$schedules['pc_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Every 5 minutes (peptide-core)',
		);
		$schedules['pc_ten_minutes']  = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => 'Every 10 minutes (peptide-core)',
		);
		return $schedules;
	}

	/** 有开启的提醒时排好每 5 分钟的扫描，全部关闭时取消；旧的 10 分钟排程换成 5 分钟。 */
	public static function schedule(): void {
		if ( ! did_action( 'woocommerce_init' ) ) {
			return;
		}
		if ( get_option( self::DB_OPTION ) && ! self::installed() ) {
			self::install();
		}
		$on    = self::enabled();
		$event = wp_get_scheduled_event( self::CRON );
		if ( $event && ( ! $on || 'pc_five_minutes' !== $event->schedule ) ) {
			wp_clear_scheduled_hook( self::CRON );
			$event = false;
		}
		if ( $on && ! $event ) {
			wp_schedule_event( time() + 60, 'pc_five_minutes', self::CRON );
		}
	}

	private static function opted_out( string $email ): bool {
		$list = get_option( self::OPTOUT, array() );
		return is_array( $list ) && in_array( hash( 'sha256', strtolower( $email ) ), $list, true );
	}

	public static function is_opted_out( string $email ): bool {
		return self::opted_out( $email );
	}

	public static function opt_out( string $email ): void {
		$list   = get_option( self::OPTOUT, array() );
		$list   = is_array( $list ) ? $list : array();
		$list[] = hash( 'sha256', strtolower( $email ) );
		update_option( self::OPTOUT, array_values( array_unique( $list ) ), false );
	}

	/**
	 * 解析一条 Woo 会话：邮箱、姓名、地址是否已填、购物车、配送 / 支付方式、草稿订单。
	 */
	public static function parse_session( string $key, string $value ): ?array {
		$data  = maybe_unserialize( $value );
		$data  = is_array( $data ) ? $data : array();
		$cust  = maybe_unserialize( $data['customer'] ?? '' );
		$cust  = is_array( $cust ) ? $cust : array();
		$lines = maybe_unserialize( $data['cart'] ?? '' );
		$tot   = maybe_unserialize( $data['cart_totals'] ?? '' );
		$user  = ctype_digit( $key ) ? (int) $key : 0;
		$email = sanitize_email( (string) ( $cust['email'] ?? '' ) );
		if ( '' === $email && $user ) {
			$u     = get_userdata( $user );
			$email = $u ? (string) $u->user_email : '';
		}
		$items = array();
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			if ( ! is_array( $line ) || empty( $line['product_id'] ) || empty( $line['quantity'] ) ) {
				continue;
			}
			$items[] = array(
				'product_id'    => (int) $line['product_id'],
				'variation_id'  => (int) ( $line['variation_id'] ?? 0 ),
				'variation'     => array_map( 'strval', (array) ( $line['variation'] ?? array() ) ),
				'quantity'      => (float) $line['quantity'],
				'line_subtotal' => (float) ( $line['line_subtotal'] ?? 0 ),
			);
		}
		$ship     = maybe_unserialize( $data['chosen_shipping_methods'] ?? '' );
		$pay      = (string) maybe_unserialize( $data['chosen_payment_method'] ?? '' );
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$tot      = is_array( $tot ) ? $tot : array();
		return array(
			'email'       => is_email( $email ) ? strtolower( $email ) : '',
			'user_id'     => $user,
			'name'        => trim( (string) ( $cust['first_name'] ?? '' ) . ' ' . (string) ( $cust['last_name'] ?? '' ) ),
			'has_address' => '' !== trim( (string) ( $cust['address_1'] ?? '' ) ) || '' !== trim( (string) ( $cust['shipping_address_1'] ?? '' ) ),
			'country'     => strtoupper( (string) ( ( $cust['shipping_country'] ?? '' ) ? $cust['shipping_country'] : ( $cust['country'] ?? '' ) ) ),
			'ship_method' => is_array( $ship ) && $ship ? (string) reset( $ship ) : '',
			'pay_method'  => '' !== $pay && isset( $gateways[ $pay ] ) ? wp_strip_all_tags( (string) $gateways[ $pay ]->get_title() ) : $pay,
			'draft_id'    => absint( maybe_unserialize( $data['store_api_draft_order'] ?? 0 ) ),
			'items'       => $items,
			'subtotal'    => (float) ( $tot['subtotal'] ?? array_sum( array_column( $items, 'line_subtotal' ) ) ),
			'total'       => isset( $tot['total'] ) ? (float) $tot['total'] : null,
		);
	}

	/**
	 * 扫描有效会话，更新弃单记录，按时间发送提醒。返回本次处理的统计。
	 * $only 是会话键前缀，只给测试用：测试用模拟的未来时间扫描时，只处理测试自己的会话，不碰真实记录。
	 *
	 * @return array{tracked:int,sent:int,voided:int,failed:int,admin:int,blocked:string}
	 */
	public static function scan( ?int $now_ms = null, string $only = '' ): array {
		global $wpdb;
		$stats = array( 'tracked' => 0, 'sent' => 0, 'voided' => 0, 'failed' => 0, 'admin' => 0, 'blocked' => '' );
		if ( ! self::enabled() ) {
			$stats['blocked'] = 'disabled';
			return $stats;
		}
		if ( ! self::installed() ) {
			self::install();
		}
		$now   = $now_ms ?? self::now_ms();
		$t     = self::table();
		$scope = '' !== $only ? $wpdb->prepare( ' AND session_key LIKE %s', $wpdb->esc_like( $only ) . '%' ) : '';
		$open  = "status IN ('pending','recalling')" . $scope;
		$alive = array();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT session_key, session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_expiry > %d{$scope} LIMIT 5000", (int) floor( $now / 1000 ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $row ) {
			$key           = (string) $row['session_key'];
			$alive[ $key ] = true;
			$p             = self::parse_session( $key, (string) $row['session_value'] );
			$rec           = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_key = %s AND {$open} ORDER BY id DESC LIMIT 1", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! $p || ! $p['items'] ) {
				if ( $rec ) {
					self::finish( $rec, 'void', 'cart emptied', $now );
					++$stats['voided'];
				}
				continue;
			}
			if ( '' === $p['email'] || ! $p['has_address'] || ( $p['user_id'] && ( user_can( $p['user_id'], 'manage_woocommerce' ) || user_can( $p['user_id'], 'manage_options' ) ) ) ) {
				continue; // 还没填邮箱和地址就不算弃单；员工账号不记录。
			}
			$hash = md5( wp_json_encode( array_map( static fn( $i ) => array( $i['product_id'], $i['variation_id'], $i['variation'], $i['quantity'] ), $p['items'] ) ) );
			$data = array(
				'email'       => $p['email'],
				'user_id'     => $p['user_id'],
				'name'        => substr( $p['name'], 0, 190 ),
				'cart'        => wp_json_encode( $p['items'] ),
				'cart_hash'   => $hash,
				'items'       => (int) array_sum( array_column( $p['items'], 'quantity' ) ),
				'subtotal'    => (string) round( $p['subtotal'], 2 ),
				'total'       => null === $p['total'] ? '' : (string) round( $p['total'], 2 ),
				'currency'    => get_woocommerce_currency(),
				'country'     => substr( $p['country'], 0, 4 ),
				'ship_method' => substr( self::shipping_label( $p['ship_method'], $p['draft_id'] ), 0, 190 ),
				'pay_method'  => substr( $p['pay_method'], 0, 190 ),
				'draft_id'    => $p['draft_id'],
				'updated_ms'  => $now,
			);
			if ( ! $rec ) {
				$wpdb->insert( $t, $data + array( 'session_key' => $key, 'status' => 'pending', 'changed_ms' => $now, 'created_ms' => $now, 'token' => bin2hex( random_bytes( 16 ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$stats['tracked'];
				continue;
			}
			if ( $rec['cart_hash'] !== $hash || $rec['email'] !== $p['email'] ) {
				// 顾客还在改购物车：重新计时（已经发过的提醒不重发）。
				$data['changed_ms'] = $now;
			}
			$wpdb->update( $t, $data, array( 'id' => (int) $rec['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// 会话已经不在：发过提醒的算召回失败，没发过的作废。
		foreach ( $wpdb->get_results( "SELECT * FROM {$t} WHERE {$open}", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! isset( $alive[ $r['session_key'] ] ) ) {
				self::finish( $r, (int) $r['emails'] ? 'failed' : 'void', 'session expired', $now );
				++$stats[ (int) $r['emails'] ? 'failed' : 'voided' ];
			}
		}

		$stats['blocked'] = self::blocked_reason();
		if ( '' !== $stats['blocked'] ) {
			return $stats;
		}

		// 管理员弃单通知（每条记录一次）。
		$admin = self::admin_email();
		if ( $admin && $admin->is_enabled() ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$open} AND admin_ms = 0 AND changed_ms <= %d LIMIT 100", $now - $admin->delay() * 1000 ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$admin->trigger( $r );
				$wpdb->update( $t, array( 'admin_ms' => $now ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$stats['admin'];
			}
		}

		// 顾客提醒：每条记录每次扫描最多发一封，按阶段顺序。
		$stages = array();
		foreach ( array( 1, 2, 3 ) as $s ) {
			$e = self::email( $s );
			if ( $e && $e->is_enabled() ) {
				$stages[ $s ] = $e;
			}
		}
		foreach ( $wpdb->get_results( "SELECT * FROM {$t} WHERE {$open} LIMIT 500", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$next = null;
			foreach ( $stages as $s => $e ) {
				if ( $s > (int) $r['stage'] ) {
					$next = $s;
					break;
				}
			}
			if ( null === $next ) {
				// 开启的提醒都发完了：最后一封之后 24 小时没回来算失败。
				if ( (int) $r['emails'] && (int) $r['last_sent_ms'] && $now - (int) $r['last_sent_ms'] >= self::FAIL_AFTER_MS ) {
					self::finish( $r, 'failed', 'no response', $now );
					++$stats['failed'];
				}
				continue;
			}
			if ( (int) $r['changed_ms'] + $stages[ $next ]->delay() * 1000 > $now ) {
				continue;
			}
			if ( self::opted_out( (string) $r['email'] ) ) {
				self::finish( $r, 'void', 'unsubscribed', $now );
				++$stats['voided'];
				continue;
			}
			// 发提醒前再确认：这个邮箱在记录之后有没有付款（例如用别的设备下了单）。
			$paid = self::paid_order_since( (string) $r['email'], (int) $r['created_ms'] );
			if ( $paid ) {
				self::close_with_order( $r, $paid, $now );
				continue;
			}
			if ( $stages[ $next ]->trigger( $r ) ) {
				$wpdb->update( $t, array( 'stage' => $next, 'sent' . $next . '_ms' => $now, 'last_sent_ms' => $now, 'emails' => (int) $r['emails'] + 1, 'status' => 'recalling', 'updated_ms' => $now, 'note' => '' ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$stats['sent'];
			} else {
				$wpdb->update( $t, array( 'note' => 'send failed', 'updated_ms' => $now ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		return $stats;
	}

	/** 配送方式显示名：优先取草稿订单上的名称，否则用运费 ID。 */
	private static function shipping_label( string $rate_id, int $draft_id ): string {
		if ( $draft_id ) {
			$order = wc_get_order( $draft_id );
			if ( $order && $order->get_shipping_method() ) {
				return (string) $order->get_shipping_method();
			}
		}
		return $rate_id;
	}

	private static function finish( array $r, string $status, string $note, int $now ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => $status, 'note' => $note, 'updated_ms' => $now ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** 后台手动作废。 */
	public static function void( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->update( self::table(), array( 'status' => 'void', 'note' => 'manual', 'updated_ms' => self::now_ms() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	// ---------- 链接 ----------

	public static function recover_url( array $r ): string {
		return add_query_arg( 'pc_recover', (int) $r['id'] . '.' . $r['token'], home_url( '/' ) );
	}

	public static function unsubscribe_url( array $r ): string {
		return add_query_arg( 'pc_unsub', (int) $r['id'] . '.' . $r['token'], home_url( '/' ) );
	}

	public static function get_record( int $id ): ?array {
		global $wpdb;
		if ( ! self::installed() ) {
			return null;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return $r ? $r : null;
	}

	private static function record_from( string $value ): ?array {
		if ( ! preg_match( '/^(\d{1,15})\.([a-f0-9]{32})$/', $value, $m ) ) {
			return null;
		}
		$r = self::get_record( (int) $m[1] );
		return $r && hash_equals( (string) $r['token'], $m[2] ) ? $r : null;
	}

	public static function handle_links(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 链接由一次性令牌校验。
		if ( isset( $_GET['pc_unsub'] ) ) {
			$r = self::record_from( sanitize_text_field( wp_unslash( $_GET['pc_unsub'] ) ) );
			if ( $r ) {
				self::unsubscribe( (string) $r['email'] );
			}
			wp_die( esc_html__( 'You will no longer receive cart reminder emails from us.', 'peptide-core' ), esc_html__( 'Unsubscribed', 'peptide-core' ), array( 'response' => 200 ) );
		}
		if ( isset( $_GET['pc_recover'] ) ) {
			$r = self::record_from( sanitize_text_field( wp_unslash( $_GET['pc_recover'] ) ) );
			if ( $r && function_exists( 'WC' ) && WC()->cart ) {
				self::restore( $r );
			}
			wp_safe_redirect( $r ? wc_get_checkout_url() : wc_get_cart_url() );
			exit;
		}
		// phpcs:enable
	}

	public static function unsubscribe( string $email ): void {
		global $wpdb;
		self::opt_out( $email );
		if ( self::installed() ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'void', note = 'unsubscribed', updated_ms = %d WHERE email = %s AND status IN ('pending','recalling')", self::now_ms(), strtolower( $email ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * 顾客从邮件链接回来：把商品放回购物车（已经在车里的不重复添加），记为召回成功，按最近一封邮件的设置使用优惠码。
	 */
	public static function restore( array $r ): int {
		global $wpdb;
		$added = 0;
		$have  = array();
		foreach ( WC()->cart->get_cart() as $line ) {
			$have[ (int) $line['product_id'] . ':' . (int) $line['variation_id'] ] = true;
		}
		foreach ( (array) json_decode( (string) $r['cart'], true ) as $i ) {
			$k = (int) $i['product_id'] . ':' . (int) $i['variation_id'];
			if ( isset( $have[ $k ] ) ) {
				continue;
			}
			if ( WC()->cart->add_to_cart( (int) $i['product_id'], (float) $i['quantity'], (int) $i['variation_id'], (array) $i['variation'] ) ) {
				++$added;
			}
		}
		if ( WC()->customer && '' === (string) WC()->customer->get_billing_email() ) {
			WC()->customer->set_billing_email( (string) $r['email'] );
		}
		$email = self::email( max( 1, (int) $r['stage'] ) );
		$code  = $email ? $email->coupon() : '';
		if ( '' !== $code && ! WC()->cart->has_discount( $code ) ) {
			Coupons::remember( $code );
		}
		$set = array( 'clicked_ms' => self::now_ms(), 'updated_ms' => self::now_ms() );
		if ( in_array( $r['status'], array( 'pending', 'recalling', 'failed' ), true ) ) {
			$set += array( 'status' => 'success', 'note' => 'clicked' );
		}
		$wpdb->update( self::table(), $set, array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $added;
	}

	// ---------- 付款 ----------

	/** 这个邮箱在某时间之后有没有已付款订单（返回最早的一张）。 */
	private static function paid_order_since( string $email, int $since_ms ): ?\WC_Order {
		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'status'        => array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() ),
				'date_created'  => '>=' . (int) floor( $since_ms / 1000 ),
				'limit'         => 1,
				'orderby'       => 'date',
				'order'         => 'ASC',
			)
		);
		return $orders ? $orders[0] : null;
	}

	/** 付款后结束记录：收到提醒或点过链接后付款算召回成功，没等提醒就付款算作废。 */
	private static function close_with_order( array $r, \WC_Order $order, int $now ): void {
		global $wpdb;
		$paid     = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		$at       = $paid ? $paid->getTimestamp() * 1000 : $now;
		$reminded = ( (int) $r['sent1_ms'] || (int) $r['sent2_ms'] || (int) $r['sent3_ms'] || (int) $r['clicked_ms'] );
		$first    = min( array_filter( array( (int) $r['sent1_ms'], (int) $r['sent2_ms'], (int) $r['sent3_ms'], (int) $r['clicked_ms'], PHP_INT_MAX ) ) );
		$success  = 'success' === $r['status'] || ( $reminded && $at >= $first );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'status'      => $success ? 'success' : 'void',
				'note'        => $success ? ( 'clicked' === $r['note'] ? 'clicked' : 'paid' ) : 'paid before reminder',
				'order_id'    => $order->get_id(),
				'order_total' => (string) $order->get_total(),
				'paid_ms'     => $at,
				'updated_ms'  => $now,
			),
			array( 'id' => (int) $r['id'] )
		);
	}

	public static function on_paid( $order_id ): void {
		global $wpdb;
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::installed() ) {
			return;
		}
		$email = strtolower( (string) $order->get_billing_email() );
		if ( '' === $email ) {
			return;
		}
		$created = $order->get_date_created();
		$at      = $created ? $created->getTimestamp() * 1000 : self::now_ms();
		// 未结束的记录，以及已经点击回来、还没关联订单的记录。
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE email = %s AND ( status IN ('pending','recalling') OR ( status IN ('success','failed') AND order_id = 0 ) ) AND created_ms <= %d", $email, $at + 60000 ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			self::close_with_order( $r, $order, self::now_ms() );
		}
	}

	public static function on_status( $order_id, $from, $to, $order = null ): void {
		if ( in_array( $to, wc_get_is_paid_statuses(), true ) ) {
			self::on_paid( $order_id );
		}
	}

	// ---------- 后台 ----------

	/** 时间段内的统计：弃单数、发过提醒的、点击回来的、召回成功的（数量和付款金额）。 */
	public static function stats( int $from_ms, int $to_ms ): array {
		global $wpdb;
		if ( ! self::installed() ) {
			return array( 'abandoned' => 0, 'emailed' => 0, 'clicked' => 0, 'recovered' => 0, 'recovered_total' => 0.0, 'rate' => null );
		}
		$row     = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS abandoned, SUM(emails > 0) AS emailed, SUM(clicked_ms > 0) AS clicked, SUM(status = \'success\') AS recovered, SUM(IF(status = \'success\' AND order_id > 0, CAST(order_total AS DECIMAL(18,2)), 0)) AS total FROM ' . self::table() . ' WHERE created_ms >= %d AND created_ms < %d', $from_ms, $to_ms ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$emailed = (int) $row['emailed'];
		return array(
			'abandoned'       => (int) $row['abandoned'],
			'emailed'         => $emailed,
			'clicked'         => (int) $row['clicked'],
			'recovered'       => (int) $row['recovered'],
			'recovered_total' => (float) $row['total'],
			'rate'            => $emailed ? round( (int) $row['recovered'] / $emailed * 100, 1 ) : null,
		);
	}

	/** 各状态的记录数。 */
	public static function counts(): array {
		global $wpdb;
		$out = array_fill_keys( self::STATUSES, 0 );
		if ( ! self::installed() ) {
			return $out;
		}
		foreach ( $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( isset( $out[ $r['status'] ] ) ) {
				$out[ $r['status'] ] = (int) $r['n'];
			}
		}
		return $out;
	}

	/**
	 * 弃单列表（新的在前）。
	 *
	 * @param array{status?:string,from_ms?:int,to_ms?:int,q?:string} $filter
	 */
	public static function records( array $filter = array(), int $page = 1, int $per = 50 ): array {
		global $wpdb;
		if ( ! self::installed() ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		$where = array( '1=1' );
		if ( in_array( $filter['status'] ?? '', self::STATUSES, true ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $filter['status'] );
		}
		if ( ! empty( $filter['from_ms'] ) ) {
			$where[] = $wpdb->prepare( 'created_ms >= %d', (int) $filter['from_ms'] );
		}
		if ( ! empty( $filter['to_ms'] ) ) {
			$where[] = $wpdb->prepare( 'created_ms < %d', (int) $filter['to_ms'] );
		}
		$q = trim( (string) ( $filter['q'] ?? '' ) );
		if ( '' !== $q ) {
			$ids     = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $q ) ) );
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = $ids
				? $wpdb->prepare( '(email LIKE %s OR draft_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') OR order_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . '))', array_merge( array( $like ), $ids, $ids ) ) // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				: $wpdb->prepare( '(email LIKE %s OR name LIKE %s)', $like, $like );
		}
		$sql   = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$sql}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE {$sql} ORDER BY created_ms DESC, id DESC LIMIT %d OFFSET %d", $per, max( 0, ( $page - 1 ) * $per ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array( 'items' => $rows, 'total' => $total );
	}
}
