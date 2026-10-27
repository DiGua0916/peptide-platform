<?php
/**
 * 服务端事实事件（source=server）。前端只能上报“尝试”，成交结果以 WooCommerce 为准：
 *
 * - add_to_cart：商品真的进了购物车（woocommerce_add_to_cart，经典购物车和 Store API / 区块都会触发）。一次成功记一次。
 * - order_placed：客户提交订单（经典结账 woocommerce_checkout_order_processed、区块结账
 *   woocommerce_store_api_checkout_order_processed）。订单上记下访客和访问编号，以后的支付回调靠它关联。
 * - payment_success：woocommerce_payment_complete，或订单第一次变成已付款状态（processing / completed）。
 * - payment_failed：订单变成 failed。order_status：每次状态变化（from → to）。
 * - login / register：登录、注册，同时把访客关联到账号。
 *
 * 订单事件的 event_uid 固定（srv-order-<id>-placed / -paid / -failed / -st-<状态>），同一订单同一结果只记一次。
 * 没有访客 cookie、也没有订单关联时（例如后台手工建单）不记录。员工账号不记录。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Server_Events {

	public const ORDER_VISITOR_META = '_pc_track_visitor';
	public const ORDER_VISIT_META   = '_pc_track_visit';

	public static function register_hooks(): void {
		add_action( 'woocommerce_add_to_cart', array( self::class, 'on_add_to_cart' ), 20, 6 );
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'on_classic_order' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'on_order_placed' ), 20 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'on_payment_complete' ), 20 );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_status_changed' ), 20, 4 );
		add_action( 'wp_login', array( self::class, 'on_login' ), 20, 2 );
		add_action( 'user_register', array( self::class, 'on_register' ), 20 );
	}

	/**
	 * 当前浏览器的访客（有 cookie 时）；员工或没有 cookie 时返回 null。
	 *
	 * @return array{id:int,user_id:int}|null
	 */
	private static function browser_visitor( int $user_id = -1 ): ?array {
		$vid = Tracker::current_vid();
		$uid = $user_id >= 0 ? $user_id : Tracker::cookie_user_id();
		if ( '' === $vid || Tracker::is_staff( $uid ) || Tracker::declined() ) {
			return null;
		}
		$v = Tracker::visitor( $vid, Tracker::now_ms() );
		return $v['id'] ? $v : null;
	}

	/**
	 * 记一条服务端事件。返回是否新写入（重复的 uid 返回 false）。
	 */
	public static function record( int $visitor_id, int $visit_id, string $uid, string $type, array $fields = array(), array $data = array() ): bool {
		global $wpdb;
		$now = Tracker::now_ms();
		$ok  = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Schema::table( 'events' ) . " (event_uid, visitor_id, visit_id, type, source, occurred_ms, received_ms, page_type, path, product_id, variation_id, order_id, data, is_test) VALUES (%s, %d, %d, %s, 'server', %d, %d, %s, %s, %d, %d, %d, %s, 0)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				substr( $uid, 0, 64 ),
				$visitor_id,
				$visit_id,
				$type,
				$now,
				$now,
				(string) ( $fields['page_type'] ?? '' ),
				(string) ( $fields['path'] ?? '' ),
				(int) ( $fields['product_id'] ?? 0 ),
				(int) ( $fields['variation_id'] ?? 0 ),
				(int) ( $fields['order_id'] ?? 0 ),
				wp_json_encode( $data )
			)
		);
		if ( 1 !== (int) $ok ) {
			return false;
		}
		if ( $visit_id ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'visits' ) . ' SET events = events + 1 WHERE id = %d', $visit_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		return true;
	}

	/** 正在浏览的访客：沿用 / 新开访问，并延长访问的最后活动时间。 */
	private static function live_visit( array $visitor ): int {
		global $wpdb;
		$now   = Tracker::now_ms();
		$visit = Tracker::visit( $visitor['id'], $now );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'visits' ) . ' SET last_ms = GREATEST(last_ms, %d) WHERE id = %d', $now, $visit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'visitors' ) . ' SET last_ms = GREATEST(last_ms, %d) WHERE id = %d', $now, $visitor['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return $visit;
	}

	public static function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ): void {
		$visitor = self::browser_visitor();
		if ( ! $visitor ) {
			return;
		}
		$visit = self::live_visit( $visitor );
		if ( self::record(
			$visitor['id'],
			$visit,
			'srv-atc-' . wp_generate_uuid4(),
			'add_to_cart',
			array( 'product_id' => (int) $product_id, 'variation_id' => (int) $variation_id ),
			array( 'product_id' => (int) $product_id, 'variation_id' => (int) $variation_id, 'quantity' => (float) $quantity, 'product_name' => (string) get_the_title( $variation_id ? $variation_id : $product_id ) )
		) ) {
			Tracker::raise_stage( $visit, 2 );
		}
	}

	public static function on_classic_order( $order_id, $posted = array(), $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order ) {
			self::on_order_placed( $order );
		}
	}

	public static function on_order_placed( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$visitor = self::browser_visitor();
		if ( ! $visitor || Tracker::is_staff( (int) $order->get_customer_id() ) ) {
			return;
		}
		$visit = self::live_visit( $visitor );
		$order->update_meta_data( self::ORDER_VISITOR_META, $visitor['id'] );
		$order->update_meta_data( self::ORDER_VISIT_META, $visit );
		$order->save_meta_data();
		if ( $order->get_customer_id() ) {
			Tracker::link_user( $visitor['id'], (int) $order->get_customer_id(), $visit );
		}
		$placed = self::record(
			$visitor['id'],
			$visit,
			'srv-order-' . $order->get_id() . '-placed',
			'order_placed',
			array( 'order_id' => $order->get_id(), 'page_type' => 'checkout' ),
			array(
				'status'   => $order->get_status(),
				'total'    => (float) $order->get_total(),
				'currency' => $order->get_currency(),
				'items'    => (int) $order->get_item_count(),
				'method'   => (string) $order->get_payment_method(),
			)
		);
		if ( $placed ) {
			Tracker::raise_stage( $visit, 4, $order->get_id() );
		}
		// 有些付款方式在下单的同一请求里就完成了付款。
		if ( $order->is_paid() ) {
			self::paid( $order );
		}
	}

	/**
	 * 订单关联的访客和访问（下单时记下）。
	 *
	 * @return array{0:int,1:int}
	 */
	private static function order_link( \WC_Order $order ): array {
		return array( (int) $order->get_meta( self::ORDER_VISITOR_META ), (int) $order->get_meta( self::ORDER_VISIT_META ) );
	}

	private static function paid( \WC_Order $order ): void {
		[ $visitor_id, $visit_id ] = self::order_link( $order );
		if ( ! $visitor_id ) {
			return;
		}
		if ( self::record( $visitor_id, $visit_id, 'srv-order-' . $order->get_id() . '-paid', 'payment_success', array( 'order_id' => $order->get_id() ), array( 'method' => (string) $order->get_payment_method(), 'total' => (float) $order->get_total(), 'currency' => $order->get_currency() ) ) && $visit_id ) {
			Tracker::raise_stage( $visit_id, 5, $order->get_id() );
		}
	}

	public static function on_payment_complete( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::paid( $order );
		}
	}

	public static function on_status_changed( $order_id, $from, $to, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		[ $visitor_id, $visit_id ] = self::order_link( $order );
		if ( ! $visitor_id ) {
			return;
		}
		self::record( $visitor_id, $visit_id, 'srv-order-' . $order->get_id() . '-st-' . sanitize_key( (string) $to ), 'order_status', array( 'order_id' => $order->get_id() ), array( 'from' => (string) $from, 'to' => (string) $to ) );
		if ( 'failed' === $to ) {
			self::record( $visitor_id, $visit_id, 'srv-order-' . $order->get_id() . '-failed', 'payment_failed', array( 'order_id' => $order->get_id() ), array( 'method' => (string) $order->get_payment_method() ) );
		} elseif ( in_array( $to, wc_get_is_paid_statuses(), true ) ) {
			self::paid( $order );
		}
	}

	public static function on_login( $login, $user ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$visitor = self::browser_visitor( (int) $user->ID );
		if ( ! $visitor ) {
			return;
		}
		$visit = self::live_visit( $visitor );
		Tracker::link_user( $visitor['id'], (int) $user->ID, $visit );
		self::record( $visitor['id'], $visit, 'srv-login-' . wp_generate_uuid4(), 'login', array(), array( 'user_id' => (int) $user->ID ) );
	}

	public static function on_register( $user_id ): void {
		$visitor = self::browser_visitor( 0 );
		if ( ! $visitor ) {
			return;
		}
		$visit = self::live_visit( $visitor );
		Tracker::link_user( $visitor['id'], (int) $user_id, $visit );
		self::record( $visitor['id'], $visit, 'srv-register-' . (int) $user_id, 'register', array(), array( 'user_id' => (int) $user_id ) );
	}
}
