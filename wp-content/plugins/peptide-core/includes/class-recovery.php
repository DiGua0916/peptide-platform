<?php
/**
 * 弃单召回（CART-RECOVERY-01），对应 Shopname 的“弃单激活 / 购物车召回（阶段一、阶段二）”。
 *
 * 数据来源：WooCommerce 会话（woocommerce_sessions）。客户在结账页填写邮箱后，区块结账会立刻把邮箱写进会话的
 * customer 数据；会话里同时有购物车。所以“弃单” = 有效会话里有商品、有邮箱，购物车一段时间没变，也还没付款。
 * 不依赖访客追踪，追踪关闭时也能用。
 *
 * 流程（WP-Cron 每 10 分钟扫描一次，也可以 wp peptide recovery scan）：
 * - 新出现或购物车有变化：记一条 / 更新弃单记录，计时从这次变化开始；
 * - 购物车 delay1（默认 60 分钟）没变化：发第一封提醒；第一封之后再过 delay2（默认 24 小时）仍没付款：发第二封；
 * - 客户付款（woocommerce_payment_complete，或订单进入已付款状态）：同邮箱的未结记录结束，
 *   在提醒邮件之后付款的记为“召回成功”，并记下订单和金额；
 * - 购物车被清空：结束（closed）；会话过期：结束（expired）。
 *
 * 邮件（美国 CAN-SPAM）：每封都有退订链接和店铺实体地址；WooCommerce 设置里没有店铺地址时不发。
 * 退订的邮箱以后不再收到提醒（只保存邮箱的哈希）。员工账号不发。默认关闭。
 * 邮件里的“完成订单”链接带一次性令牌：恢复购物车（不重复添加已有商品）、可选地使用设置里的优惠码，然后进入结账页。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Recovery {

	public const OPTION     = 'peptide_core_recovery';
	public const OPTOUT     = 'peptide_core_recovery_optout';
	public const DB_OPTION  = 'peptide_core_recovery_db';
	public const DB_VERSION = '1';
	public const CRON       = 'peptide_core_recovery_scan';

	public static function register_hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'cron_schedule' ) );
		add_action( self::CRON, array( self::class, 'scan' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( 'wp_loaded', array( self::class, 'handle_links' ), 40 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'on_paid' ), 30 );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_status' ), 30, 4 );
	}

	// ---------- 设置 ----------

	public static function defaults(): array {
		return array(
			'enabled'      => false,
			'delay1_min'   => 60,
			'stage2'       => true,
			'delay2_hours' => 24,
			'coupon'       => '',
			'subject1'     => 'You left something in your cart',
			'subject2'     => 'Your cart is still saved',
			'heading'      => 'Still thinking it over?',
			'intro'        => 'You left these items in your cart. Your cart is saved, so you can pick up right where you left off.',
			'button'       => 'Complete your order',
		);
	}

	public static function settings(): array {
		$o = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $o ) ? $o : array() );
	}

	public static function save_settings( array $raw ): array {
		$d    = self::defaults();
		$text = static fn( $v, int $max, string $fallback ): string => '' !== trim( (string) $v ) ? ( function_exists( 'mb_substr' ) ? mb_substr( sanitize_text_field( (string) $v ), 0, $max ) : substr( sanitize_text_field( (string) $v ), 0, $max ) ) : $fallback;
		$code = wc_format_coupon_code( (string) ( $raw['coupon'] ?? '' ) );
		$s    = array(
			'enabled'      => ! empty( $raw['enabled'] ),
			'delay1_min'   => max( 15, min( 72 * 60, (int) ( $raw['delay1_min'] ?? $d['delay1_min'] ) ) ),
			'stage2'       => ! empty( $raw['stage2'] ),
			'delay2_hours' => max( 1, min( 14 * 24, (int) ( $raw['delay2_hours'] ?? $d['delay2_hours'] ) ) ),
			'coupon'       => '' !== $code && wc_get_coupon_id_by_code( $code ) ? $code : '',
			'subject1'     => $text( $raw['subject1'] ?? '', 120, $d['subject1'] ),
			'subject2'     => $text( $raw['subject2'] ?? '', 120, $d['subject2'] ),
			'heading'      => $text( $raw['heading'] ?? '', 120, $d['heading'] ),
			'intro'        => '' !== trim( (string) ( $raw['intro'] ?? '' ) ) ? ( function_exists( 'mb_substr' ) ? mb_substr( sanitize_textarea_field( (string) $raw['intro'] ), 0, 600 ) : substr( sanitize_textarea_field( (string) $raw['intro'] ), 0, 600 ) ) : $d['intro'],
			'button'       => $text( $raw['button'] ?? '', 40, $d['button'] ),
		);
		update_option( self::OPTION, $s, false );
		if ( $s['enabled'] ) {
			self::install();
		}
		self::schedule();
		return $s;
	}

	/** 店铺实体地址（WooCommerce → 设置 → 常规）。CAN-SPAM 要求商业邮件写明实体地址，没有时不发。 */
	public static function store_address(): string {
		$parts = array_filter(
			array(
				get_option( 'woocommerce_store_address' ),
				get_option( 'woocommerce_store_address_2' ),
				trim( get_option( 'woocommerce_store_city' ) . ' ' . get_option( 'woocommerce_store_postcode' ) ),
				WC()->countries ? WC()->countries->get_base_country() : '',
			)
		);
		return get_option( 'woocommerce_store_address' ) && get_option( 'woocommerce_store_city' ) ? implode( ', ', array_map( 'strval', $parts ) ) : '';
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
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_key varchar(64) NOT NULL,
  email varchar(190) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  cart longtext NULL,
  cart_hash char(32) NOT NULL DEFAULT '',
  items int(10) unsigned NOT NULL DEFAULT 0,
  subtotal varchar(32) NOT NULL DEFAULT '0',
  currency varchar(8) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL DEFAULT 'open',
  stage tinyint(3) unsigned NOT NULL DEFAULT 0,
  changed_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  sent1_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  sent2_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  clicked_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_total varchar(32) NOT NULL DEFAULT '',
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
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	private static function now_ms(): int {
		return (int) floor( microtime( true ) * 1000 );
	}

	// ---------- 定时扫描 ----------

	public static function cron_schedule( $schedules ) {
		$schedules['pc_ten_minutes'] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => 'Every 10 minutes (peptide-core)',
		);
		return $schedules;
	}

	/** 开启时排好每 10 分钟的扫描，关闭时取消。 */
	public static function schedule(): void {
		$on   = self::settings()['enabled'];
		$next = wp_next_scheduled( self::CRON );
		if ( $on && ! $next ) {
			wp_schedule_event( time() + 60, 'pc_ten_minutes', self::CRON );
		} elseif ( ! $on && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	private static function opted_out( string $email ): bool {
		$list = get_option( self::OPTOUT, array() );
		return is_array( $list ) && in_array( hash( 'sha256', strtolower( $email ) ), $list, true );
	}

	public static function opt_out( string $email ): void {
		$list   = get_option( self::OPTOUT, array() );
		$list   = is_array( $list ) ? $list : array();
		$list[] = hash( 'sha256', strtolower( $email ) );
		update_option( self::OPTOUT, array_values( array_unique( $list ) ), false );
	}

	/**
	 * 解析一条 Woo 会话：邮箱、账号、购物车行。没有邮箱或购物车为空时返回 null。
	 */
	public static function parse_session( string $key, string $value ): ?array {
		$data  = maybe_unserialize( $value );
		$data  = is_array( $data ) ? $data : array();
		$cust  = maybe_unserialize( $data['customer'] ?? '' );
		$lines = maybe_unserialize( $data['cart'] ?? '' );
		$tot   = maybe_unserialize( $data['cart_totals'] ?? '' );
		$user  = ctype_digit( $key ) ? (int) $key : 0;
		$email = is_array( $cust ) ? sanitize_email( (string) ( $cust['email'] ?? '' ) ) : '';
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
		return array(
			'email'    => is_email( $email ) ? strtolower( $email ) : '',
			'user_id'  => $user,
			'items'    => $items,
			'subtotal' => is_array( $tot ) ? (float) ( $tot['subtotal'] ?? 0 ) : array_sum( array_column( $items, 'line_subtotal' ) ),
		);
	}

	/**
	 * 扫描有效会话，更新弃单记录，按时间发送提醒。返回本次处理的统计。
	 *
	 * @return array{tracked:int,sent:int,closed:int,expired:int,blocked:string}
	 */
	public static function scan( ?int $now_ms = null ): array {
		global $wpdb;
		$stats = array( 'tracked' => 0, 'sent' => 0, 'closed' => 0, 'expired' => 0, 'blocked' => '' );
		$s     = self::settings();
		if ( ! $s['enabled'] ) {
			$stats['blocked'] = 'disabled';
			return $stats;
		}
		if ( ! self::installed() ) {
			self::install();
		}
		$now   = $now_ms ?? self::now_ms();
		$t     = self::table();
		$alive = array();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT session_key, session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_expiry > %d LIMIT 5000", (int) floor( $now / 1000 ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			$key           = (string) $row['session_key'];
			$alive[ $key ] = true;
			$p             = self::parse_session( $key, (string) $row['session_value'] );
			$open          = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_key = %s AND status = 'open' ORDER BY id DESC LIMIT 1", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! $p || ! $p['items'] ) {
				if ( $open ) {
					$wpdb->update( $t, array( 'status' => 'closed', 'updated_ms' => $now, 'note' => 'cart emptied' ), array( 'id' => (int) $open['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					++$stats['closed'];
				}
				continue;
			}
			if ( '' === $p['email'] || ( $p['user_id'] && ( user_can( $p['user_id'], 'manage_woocommerce' ) || user_can( $p['user_id'], 'manage_options' ) ) ) ) {
				continue; // 没有邮箱就没法提醒；员工账号不提醒。
			}
			$hash = md5( wp_json_encode( array_map( static fn( $i ) => array( $i['product_id'], $i['variation_id'], $i['variation'], $i['quantity'] ), $p['items'] ) ) );
			$data = array(
				'email'      => $p['email'],
				'user_id'    => $p['user_id'],
				'cart'       => wp_json_encode( $p['items'] ),
				'cart_hash'  => $hash,
				'items'      => (int) array_sum( array_column( $p['items'], 'quantity' ) ),
				'subtotal'   => (string) round( $p['subtotal'], 2 ),
				'currency'   => get_woocommerce_currency(),
				'updated_ms' => $now,
			);
			if ( ! $open ) {
				$wpdb->insert( $t, $data + array( 'session_key' => $key, 'status' => 'open', 'changed_ms' => $now, 'created_ms' => $now, 'token' => bin2hex( random_bytes( 16 ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$stats['tracked'];
				continue;
			}
			if ( $open['cart_hash'] !== $hash || $open['email'] !== $p['email'] ) {
				// 客户还在改购物车：重新计时（已经发过的阶段不重发）。
				$wpdb->update( $t, $data + array( 'changed_ms' => $now ), array( 'id' => (int) $open['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				continue;
			}
			$wpdb->update( $t, array( 'updated_ms' => $now ), array( 'id' => (int) $open['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// 会话已经不在的未结记录：过期。
		foreach ( $wpdb->get_results( "SELECT id, session_key FROM {$t} WHERE status = 'open'", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! isset( $alive[ $r['session_key'] ] ) ) {
				$wpdb->update( $t, array( 'status' => 'expired', 'updated_ms' => $now, 'note' => 'session expired' ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$stats['expired'];
			}
		}

		// 发送提醒。
		if ( '' === self::store_address() ) {
			$stats['blocked'] = 'address';
			return $stats;
		}
		$due1 = $now - (int) $s['delay1_min'] * 60000;
		$due2 = $now - (int) $s['delay2_hours'] * 3600000;
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'open' AND ( ( stage = 0 AND changed_ms <= %d ) OR ( stage = 1 AND %d = 1 AND sent1_ms <= %d AND changed_ms <= %d ) ) LIMIT 200", $due1, $s['stage2'] ? 1 : 0, $due2, $due1 ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( self::opted_out( (string) $r['email'] ) ) {
				$wpdb->update( $t, array( 'status' => 'unsubscribed', 'updated_ms' => $now ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				continue;
			}
			// 发提醒前再确认：这个邮箱在计时开始后有没有付款（例如用别的设备下了单）。
			$paid = self::paid_order_since( (string) $r['email'], (int) $r['created_ms'] );
			if ( $paid ) {
				self::close_with_order( $r, $paid, $now );
				continue;
			}
			$stage = (int) $r['stage'] + 1;
			if ( self::send( $r, $stage ) ) {
				$wpdb->update( $t, array( 'stage' => $stage, 'sent' . $stage . '_ms' => $now, 'updated_ms' => $now ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$stats['sent'];
			} else {
				$wpdb->update( $t, array( 'note' => 'send failed', 'updated_ms' => $now ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		return $stats;
	}

	// ---------- 邮件 ----------

	public static function recover_url( array $r ): string {
		return add_query_arg( 'pc_recover', (int) $r['id'] . '.' . $r['token'], home_url( '/' ) );
	}

	public static function unsubscribe_url( array $r ): string {
		return add_query_arg( 'pc_unsub', (int) $r['id'] . '.' . $r['token'], home_url( '/' ) );
	}

	/** 邮件正文（HTML，放进 WooCommerce 邮件模板）。 */
	public static function email_html( array $r, int $stage ): string {
		$s     = self::settings();
		$items = json_decode( (string) $r['cart'], true );
		$rows  = '';
		foreach ( is_array( $items ) ? $items : array() as $i ) {
			$product = wc_get_product( $i['variation_id'] ? $i['variation_id'] : $i['product_id'] );
			if ( ! $product ) {
				continue;
			}
			$image = wp_get_attachment_image_src( (int) $product->get_image_id(), 'thumbnail' );
			$rows .= '<tr><td style="padding:8px 0;width:64px">' . ( $image ? '<img src="' . esc_url( $image[0] ) . '" alt="" width="56" height="56" style="display:block;border-radius:4px">' : '' ) . '</td><td style="padding:8px">' . esc_html( $product->get_name() ) . ' &times; ' . esc_html( wc_stock_amount( $i['quantity'] ) ) . '</td><td style="padding:8px 0;text-align:right">' . wp_kses_post( wc_price( $i['line_subtotal'] ) ) . '</td></tr>';
		}
		$store = get_bloginfo( 'name' );
		$html  = '<p>' . nl2br( esc_html( $s['intro'] ) ) . '</p>';
		$html .= '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:16px 0">' . $rows . '</table>';
		$html .= '<p style="text-align:right"><strong>' . esc_html__( 'Subtotal', 'peptide-core' ) . ': ' . wp_kses_post( wc_price( (float) $r['subtotal'] ) ) . '</strong></p>';
		if ( '' !== $s['coupon'] ) {
			/* translators: %s: coupon code */
			$html .= '<p>' . sprintf( esc_html__( 'Use code %s at checkout.', 'peptide-core' ), '<strong>' . esc_html( strtoupper( $s['coupon'] ) ) . '</strong>' ) . '</p>';
		}
		$html .= '<p style="margin:24px 0"><a href="' . esc_url( self::recover_url( $r ) ) . '" style="display:inline-block;padding:12px 24px;background:#177e9b;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold">' . esc_html( $s['button'] ) . '</a></p>';
		/* translators: 1: store name, 2: store address */
		$html .= '<p style="font-size:12px;color:#6b7280">' . sprintf( esc_html__( 'You are receiving this because you started a checkout at %1$s. %2$s', 'peptide-core' ), esc_html( $store ), esc_html( self::store_address() ) ) . '<br><a href="' . esc_url( self::unsubscribe_url( $r ) ) . '" style="color:#6b7280">' . esc_html__( 'Unsubscribe from cart reminders', 'peptide-core' ) . '</a></p>';
		return $html;
	}

	public static function send( array $r, int $stage ): bool {
		$s       = self::settings();
		$mailer  = WC()->mailer();
		$subject = 2 === $stage ? $s['subject2'] : $s['subject1'];
		$message = $mailer->wrap_message( $s['heading'], self::email_html( $r, $stage ) );
		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'List-Unsubscribe: <' . self::unsubscribe_url( $r ) . '>' );
		return (bool) $mailer->send( (string) $r['email'], $subject, $message, $headers );
	}

	// ---------- 链接 ----------

	private static function record_from( string $value ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^(\d{1,15})\.([a-f0-9]{32})$/', $value, $m ) || ! self::installed() ) {
			return null;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $m[1] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
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
				self::unsubscribe( $r );
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

	public static function unsubscribe( array $r ): void {
		global $wpdb;
		self::opt_out( (string) $r['email'] );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'unsubscribed', updated_ms = %d WHERE email = %s AND status = 'open'", self::now_ms(), $r['email'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** 把记录里的商品放回当前购物车（已经在车里的不重复添加），记下点击时间，可选地使用优惠码。 */
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
		$code = self::settings()['coupon'];
		if ( '' !== $code && ! WC()->cart->has_discount( $code ) ) {
			Coupons::remember( $code );
		}
		$wpdb->update( self::table(), array( 'clicked_ms' => self::now_ms() ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $added;
	}

	// ---------- 付款 ----------

	/** 这个邮箱在某时间之后有没有已付款订单（返回订单）。 */
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

	/** 结束一条记录：在提醒邮件之后付款的算召回成功，否则算自行下单。 */
	private static function close_with_order( array $r, \WC_Order $order, int $now ): void {
		global $wpdb;
		$created = $order->get_date_created();
		$at      = $created ? $created->getTimestamp() * 1000 : $now;
		$sent    = (int) $r['sent1_ms'];
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'status'      => $sent && $at >= $sent ? 'recovered' : 'converted',
				'order_id'    => $order->get_id(),
				'order_total' => (string) $order->get_total(),
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
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE email = %s AND status = 'open' AND created_ms <= %d", $email, $at + 60000 ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			self::close_with_order( $r, $order, self::now_ms() );
		}
	}

	public static function on_status( $order_id, $from, $to, $order = null ): void {
		if ( in_array( $to, wc_get_is_paid_statuses(), true ) ) {
			self::on_paid( $order_id );
		}
	}

	// ---------- 后台 ----------

	/**
	 * 统计：时间段内出现的弃单、发过提醒的、召回成功的（数量和金额）。
	 */
	public static function stats( int $from_ms, int $to_ms ): array {
		global $wpdb;
		if ( ! self::installed() ) {
			return array( 'abandoned' => 0, 'emailed' => 0, 'clicked' => 0, 'recovered' => 0, 'recovered_total' => 0.0, 'rate' => null );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS abandoned, SUM(sent1_ms > 0) AS emailed, SUM(clicked_ms > 0) AS clicked, SUM(status = \'recovered\') AS recovered, SUM(IF(status = \'recovered\', CAST(order_total AS DECIMAL(18,2)), 0)) AS total FROM ' . self::table() . ' WHERE created_ms >= %d AND created_ms < %d', $from_ms, $to_ms ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
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

	/** 弃单列表（新的在前）。 */
	public static function records( string $status = '', int $page = 1, int $per = 25 ): array {
		global $wpdb;
		if ( ! self::installed() ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		$where = in_array( $status, array( 'open', 'recovered', 'converted', 'closed', 'expired', 'unsubscribed' ), true ) ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " {$where} ORDER BY created_ms DESC, id DESC LIMIT %d OFFSET %d", $per, max( 0, ( $page - 1 ) * $per ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array( 'items' => $rows, 'total' => $total );
	}
}
