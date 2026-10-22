<?php
/**
 * 插件邮件的注册与触发（EMAIL-CENTER-01）。
 *
 * - 把本插件的邮件加进 WooCommerce 邮件列表（woocommerce_email_classes），预览时填示例数据。
 * - 触发：
 *   - 付款后：潜在重复支付（管理员）、顾客注册邀请；
 *   - 订单进入“保留”：线下付款订单通知（管理员）；开启时 WooCommerce“新订单”不再为保留订单另发一封；
 *   - 注册：注册邮箱验证、购物发现（注册后延迟检查，没下单就发）。
 * - 弃单提醒和管理员弃单通知由 PeptideCore\Recovery 的定时扫描发送。
 * - 所有 WooCommerce 邮件发送后写入发送记录（Log）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

use PeptideCore\Recovery;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/** 注册到 WooCommerce 的类名（不带命名空间，见 wc-classes.php）。 */
	public const CLASSES = array(
		'PC_Email_Cart_Recovery_1',
		'PC_Email_Cart_Recovery_2',
		'PC_Email_Cart_Recovery_3',
		'PC_Email_Browse_Reminder',
		'PC_Email_Register_Invite',
		'PC_Email_Register_Verify',
		'PC_Email_Admin_Abandoned',
		'PC_Email_Admin_Duplicate',
		'PC_Email_Admin_Offline',
	);

	public const BROWSE_EVENT = 'peptide_core_browse_check';
	public const INVITED      = 'peptide_core_invited';
	private const PAID_META   = '_pc_paid_emails';
	private const BROWSE_META = '_pc_browse_sent';

	public static function register_hooks(): void {
		add_filter( 'woocommerce_email_classes', array( self::class, 'add_classes' ) );
		add_filter( 'woocommerce_prepare_email_for_preview', array( self::class, 'preview' ), 20 );
		add_action( 'woocommerce_email', array( self::class, 'adjust_new_order' ), 20 );
		add_action( 'woocommerce_email_sent', array( Log::class, 'record' ), 10, 3 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'on_paid' ), 40 );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_status' ), 40, 4 );
		add_action( 'woocommerce_order_status_pending_to_on-hold', array( self::class, 'on_hold' ), 20, 2 );
		add_action( 'woocommerce_order_status_failed_to_on-hold', array( self::class, 'on_hold' ), 20, 2 );
		add_action( 'peptide_core_send_registration_verification', array( self::class, 'send_verification' ), 10, 2 );
		add_action( 'woocommerce_created_customer', array( self::class, 'schedule_browse' ), 20 );
		add_action( self::BROWSE_EVENT, array( self::class, 'browse_check' ) );
		add_action( 'wp_loaded', array( self::class, 'handle_optout' ), 41 );
	}

	/** @param array $emails */
	public static function add_classes( $emails ): array {
		require_once __DIR__ . '/wc-classes.php';
		foreach ( self::CLASSES as $class ) {
			$emails[ $class ] = new $class();
		}
		return $emails;
	}

	/** @param \WC_Email $email */
	public static function preview( $email ) {
		if ( $email instanceof Base ) {
			$email->preview();
		}
		return $email;
	}

	/** 按 WooCommerce 里的类名（例如 PC_Email_Admin_Offline）取邮件对象。 */
	public static function get( string $key ): ?Base {
		$e = WC()->mailer()->get_emails()[ $key ] ?? null;
		return $e instanceof Base ? $e : null;
	}

	/** 不加载邮件类，直接读开关（默认值同邮件类）。 */
	public static function option_enabled( string $id, string $default ): bool {
		$o = get_option( 'woocommerce_' . $id . '_settings', array() );
		return 'yes' === ( is_array( $o ) && isset( $o['enabled'] ) ? $o['enabled'] : $default );
	}

	/**
	 * 线下付款订单通知开启时，WooCommerce“新订单”不再为保留状态的订单发邮件（避免管理员收到两封）。
	 *
	 * @param \WC_Emails $mailer
	 */
	public static function adjust_new_order( $mailer ): void {
		if ( ! self::option_enabled( 'pc_admin_offline', 'no' ) ) {
			return;
		}
		$new = $mailer->emails['WC_Email_New_Order'] ?? null;
		if ( $new ) {
			foreach ( array( 'pending', 'failed', 'cancelled' ) as $from ) {
				remove_action( 'woocommerce_order_status_' . $from . '_to_on-hold_notification', array( $new, 'trigger' ), 10 );
			}
		}
	}

	// ---------- 付款后 ----------

	public static function on_status( $order_id, $from, $to, $order = null ): void {
		if ( in_array( $to, wc_get_is_paid_statuses(), true ) ) {
			self::on_paid( $order_id );
		}
	}

	/** 每张订单只检查一次：潜在重复支付、注册邀请。 */
	public static function on_paid( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order->get_meta( self::PAID_META ) ) {
			return;
		}
		$order->update_meta_data( self::PAID_META, 1 );
		$order->save_meta_data();
		$email = strtolower( (string) $order->get_billing_email() );
		if ( '' === $email ) {
			return;
		}
		if ( self::option_enabled( 'pc_admin_duplicate', 'no' ) ) {
			$dup    = self::get( 'PC_Email_Admin_Duplicate' );
			$others = $dup instanceof Admin_Duplicate ? self::duplicates( $order, $dup->window_hours() ) : array();
			if ( $others ) {
				$dup->trigger( $order, $others );
			}
		}
		if ( ! $order->get_customer_id() && ! email_exists( $email ) && self::option_enabled( 'pc_register_invite', 'yes' ) && 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) {
			$sent = (array) get_option( self::INVITED, array() );
			$hash = hash( 'sha256', $email );
			if ( ! in_array( $hash, $sent, true ) ) {
				$invite = self::get( 'PC_Email_Register_Invite' );
				if ( $invite instanceof Register_Invite && $invite->trigger( $email ) ) {
					$sent[] = $hash;
					update_option( self::INVITED, array_slice( array_values( array_unique( $sent ) ), -5000 ), false );
				}
			}
		}
	}

	/**
	 * 同一邮箱在时间窗口内金额相同的其他已付款订单。
	 *
	 * @return \WC_Order[]
	 */
	public static function duplicates( \WC_Order $order, int $hours ): array {
		$others = wc_get_orders(
			array(
				'billing_email' => $order->get_billing_email(),
				'status'        => array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() ),
				'date_created'  => '>' . ( time() - $hours * HOUR_IN_SECONDS ),
				'exclude'       => array( $order->get_id() ),
				'type'          => 'shop_order',
				'limit'         => 10,
			)
		);
		return array_values( array_filter( $others, static fn( $o ) => abs( (float) $o->get_total() - (float) $order->get_total() ) < 0.01 && $o->get_currency() === $order->get_currency() ) );
	}

	public static function on_hold( $order_id, $order = null ): void {
		if ( ! self::option_enabled( 'pc_admin_offline', 'no' ) ) {
			return;
		}
		$e = self::get( 'PC_Email_Admin_Offline' );
		if ( $e instanceof Admin_Offline ) {
			$e->trigger( $order_id, $order );
		}
	}

	// ---------- 注册 ----------

	public static function send_verification( $user_id, $url ): void {
		$e = self::get( 'PC_Email_Register_Verify' );
		if ( $e instanceof Register_Verify ) {
			$e->trigger( (int) $user_id, (string) $url );
		}
	}

	public static function schedule_browse( $customer_id ): void {
		if ( ! self::option_enabled( 'pc_browse_reminder', 'yes' ) ) {
			return;
		}
		$o     = get_option( 'woocommerce_pc_browse_reminder_settings', array() );
		$delay = max( 300, (int) ( is_array( $o ) && isset( $o['delay'] ) ? $o['delay'] : 1800 ) );
		wp_schedule_single_event( time() + $delay, self::BROWSE_EVENT, array( (int) $customer_id ) );
	}

	/** 注册后延迟检查：还没下单、没退订、有可列出的商品时，发购物发现邮件。返回是否发出。 */
	public static function browse_check( $customer_id ): bool {
		$user = get_userdata( (int) $customer_id );
		if ( ! $user || get_user_meta( $user->ID, self::BROWSE_META, true ) || user_can( $user, 'edit_posts' ) || ! self::option_enabled( 'pc_browse_reminder', 'yes' ) ) {
			return false;
		}
		$email = strtolower( (string) $user->user_email );
		if ( Recovery::is_opted_out( $email ) || '' !== Recovery::blocked_reason() ) {
			return false;
		}
		$orders = wc_get_orders( array( 'customer' => $email, 'limit' => 1, 'return' => 'ids', 'status' => array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ) ) );
		$mine   = wc_get_orders( array( 'customer_id' => $user->ID, 'limit' => 1, 'return' => 'ids', 'status' => array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ) ) );
		if ( $orders || $mine ) {
			return false;
		}
		$items = self::browse_items( $user->ID );
		$e     = self::get( 'PC_Email_Browse_Reminder' );
		if ( ! $items || ! $e instanceof Browse_Reminder ) {
			return false;
		}
		$ok = $e->trigger( $email, $items, self::optout_url( $email ) );
		if ( $ok ) {
			update_user_meta( $user->ID, self::BROWSE_META, time() );
		}
		return $ok;
	}

	/** 顾客最近加购（账户里保存的购物车）和浏览过（访客追踪）的已发布商品，最多 4 个。 */
	public static function browse_items( int $user_id ): array {
		global $wpdb;
		$ids  = array();
		$cart = get_user_meta( $user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), true );
		foreach ( is_array( $cart ) && isset( $cart['cart'] ) ? (array) $cart['cart'] : array() as $line ) {
			if ( ! empty( $line['product_id'] ) ) {
				$ids[ (int) $line['product_id'] ] = (int) ( $line['variation_id'] ?? 0 );
			}
		}
		if ( count( $ids ) < 4 && \PeptideCore\Tracking\Schema::installed() ) {
			$v    = \PeptideCore\Tracking\Schema::table( 'visitors' );
			$ev   = \PeptideCore\Tracking\Schema::table( 'events' );
			$seen = $wpdb->get_col( $wpdb->prepare( "SELECT e.product_id FROM {$ev} e INNER JOIN {$v} v ON v.id = e.visitor_id WHERE v.user_id = %d AND e.product_id > 0 AND e.type IN ('page_view','add_to_cart') ORDER BY e.occurred_ms DESC LIMIT 40", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( $seen as $pid ) {
				if ( ! isset( $ids[ (int) $pid ] ) ) {
					$ids[ (int) $pid ] = 0;
				}
			}
		}
		$out = array();
		foreach ( $ids as $pid => $vid ) {
			$p = wc_get_product( $pid );
			if ( $p && 'publish' === $p->get_status() && $p->is_visible() ) {
				$out[] = array( 'product_id' => $pid, 'variation_id' => $vid );
			}
			if ( count( $out ) >= 4 ) {
				break;
			}
		}
		return $out;
	}

	// ---------- 退订（购物发现用） ----------

	public static function optout_url( string $email ): string {
		$enc = rtrim( strtr( base64_encode( strtolower( $email ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return add_query_arg( 'pc_optout', $enc . '.' . substr( hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) ), 0, 32 ), home_url( '/' ) );
	}

	/** 校验退订链接，返回邮箱；无效时返回空字符串。 */
	public static function optout_email( string $value ): string {
		[ $enc, $sig ] = array_pad( explode( '.', $value, 2 ), 2, '' );
		$email         = strtolower( (string) base64_decode( strtr( $enc, '-_', '+/' ), true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return is_email( $email ) && hash_equals( substr( hash_hmac( 'sha256', $email, wp_salt( 'auth' ) ), 0, 32 ), $sig ) ? $email : '';
	}

	public static function handle_optout(): void {
		if ( is_admin() || ! isset( $_GET['pc_optout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$email = self::optout_email( sanitize_text_field( wp_unslash( $_GET['pc_optout'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $email ) {
			Recovery::unsubscribe( $email );
		}
		wp_die( esc_html__( 'You will no longer receive these emails from us.', 'peptide-core' ), esc_html__( 'Unsubscribed', 'peptide-core' ), array( 'response' => 200 ) );
	}
}
