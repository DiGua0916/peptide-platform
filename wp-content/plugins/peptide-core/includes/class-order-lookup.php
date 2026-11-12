<?php
/**
 * 查订单入口（M11，FE-11）。
 *
 * 顾客换了手机或清了浏览器数据，店铺就认不出他是已购顾客。前台放一个“查订单”页面：填下单邮箱和订单号，
 * 对得上就显示和物流卡片一样的内容，并把这个浏览器记成已购（Buyer_Status 的 cookie）。
 *
 * - 接口 POST peptide/v1/order-lookup { email, order }。邮箱和订单号必须同时对上；对不上（订单不存在、邮箱不符、
 *   测试单）一律返回同一句提示，不透露是哪一项不对，也不透露订单是否存在。
 * - 限流：每个 IP 每分钟 5 次（成功失败都算），超过返回 429。IP 的取法和现有接口一致（Rest_Controller::client_ip()）。
 * - 页面：短代码 [pc_track_order]。开关（pc_buyer_recognition）第一次打开时自动建一个 /track-order 页面，
 *   已经有就不动；开关关着时短代码不输出表单，接口按“没找到”回答。
 * - 发货邮件里的链接可以带 ?email=…&order=… 预填表单（不自动提交）。
 * - 订单号两种都认：原来的数字号，和 2026-11-11 起顾客看到的 hang- 号（主线 Order_Number，存在订单的 `_pc_pp_ref`）。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Order_Lookup {

	public const SHORTCODE   = 'pc_track_order';
	public const PAGE_SLUG   = 'track-order';
	public const PAGE_OPTION = 'pc_track_order_page';
	public const LIMIT       = 5;
	public const WINDOW      = 60;

	/** 对不上时统一的提示。 */
	public const NOT_FOUND = 'We couldn’t find an order with that email and order number. Please check both and try again.';

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_action( 'add_option_' . Buyer_Status::OPTION, array( self::class, 'option_added' ), 10, 2 );
		add_action( 'update_option_' . Buyer_Status::OPTION, array( self::class, 'option_updated' ), 10, 2 );
	}

	// ---------- 查订单页面 ----------

	public static function option_added( $name, $value ): void {
		if ( 'on' === $value ) {
			self::ensure_page();
		}
	}

	public static function option_updated( $old, $value ): void {
		if ( 'on' === $value && 'on' !== $old ) {
			self::ensure_page();
		}
	}

	/** 没有查订单页面时建一个（标题和内容是英文，顾客看）。返回页面 ID；建不了时返回 0。 */
	public static function ensure_page(): int {
		$id = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return $id;
		}
		$existing = get_page_by_path( self::PAGE_SLUG );
		if ( $existing instanceof \WP_Post && 'publish' === $existing->post_status ) {
			$id = (int) $existing->ID;
		} else {
			$id = (int) wp_insert_post(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'post_title'     => 'Track your order',
					'post_name'      => self::PAGE_SLUG,
					'post_content'   => '[' . self::SHORTCODE . ']',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			);
		}
		if ( $id > 0 ) {
			update_option( self::PAGE_OPTION, $id, false );
		}
		return max( 0, $id );
	}

	/** 查订单页面的地址；还没有页面时为空。 */
	public static function page_url(): string {
		$id = (int) get_option( self::PAGE_OPTION, 0 );
		return $id && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
	}

	/** [pc_track_order]：查订单表单。结果由前台脚本显示在表单下面。 */
	public static function shortcode(): string {
		if ( ! Buyer_Status::enabled() ) {
			return '';
		}
		Buyer_Status::register_assets();
		wp_enqueue_style( 'peptide-core-buyer-status' );
		wp_enqueue_script( 'peptide-core-buyer-status' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只用来预填表单，不执行查询。
		$email = isset( $_GET['email'] ) && is_string( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		$order = isset( $_GET['order'] ) && is_string( $_GET['order'] ) ? self::clean_order_ref( wp_unslash( $_GET['order'] ) ) : '';
		// phpcs:enable
		return '<div class="pc-ol">'
			. '<form class="pc-ol__form" data-pc-order-lookup novalidate>'
			. '<p class="pc-ol__intro">Enter the email you used at checkout and your order number. You can find the order number in your confirmation email.</p>'
			. '<p class="pc-ol__field"><label for="pc-ol-email">Email</label><input type="email" id="pc-ol-email" name="email" autocomplete="email" inputmode="email" required value="' . esc_attr( $email ) . '"></p>'
			. '<p class="pc-ol__field"><label for="pc-ol-order">Order number</label><input type="text" id="pc-ol-order" name="order" autocomplete="off" autocapitalize="none" spellcheck="false" required maxlength="24" placeholder="e.g. hang-131189201" value="' . esc_attr( (string) $order ) . '"></p>'
			. '<p class="pc-ol__actions"><button type="submit" class="pc-ol__submit">Find my order</button></p>'
			. '<p class="pc-ol__message" data-pc-order-lookup-message role="alert" hidden></p>'
			. '</form><div class="pc-ol__result" data-pc-order-lookup-result aria-live="polite"></div></div>';
	}

	// ---------- 核对 ----------

	/** 订单号：去掉 # 和空格，只留数字；不是纯数字时为 0（不读数据库）。 */
	public static function clean_order_number( string $raw ): int {
		$raw = ltrim( trim( $raw ), "# \t" );
		return 1 === preg_match( '/^[0-9]{1,12}$/D', $raw ) ? (int) $raw : 0;
	}

	/**
	 * 顾客填的订单号整理成统一写法（不读数据库）：数字号 "1234"，或 hang- 号 "hang-131189201"（不分大小写）；
	 * 认不出来为空。hang- 号的格式和主线 Order_Number::ref() 一致。
	 */
	public static function clean_order_ref( string $raw ): string {
		$raw = strtolower( ltrim( trim( $raw ), "# \t" ) );
		if ( 1 === preg_match( '/^hang-[0-9]{1,13}$/D', $raw ) ) {
			return $raw;
		}
		$id = self::clean_order_number( $raw );
		return $id > 0 ? (string) $id : '';
	}

	/** 按整理过的订单号取订单：数字号直接取；hang- 号交给主线的 Order_Number::find()（没有它时查不到）。 */
	private static function resolve( string $ref ): ?\WC_Order {
		if ( '' === $ref || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		if ( ctype_digit( $ref ) ) {
			$order = wc_get_order( (int) $ref );
		} else {
			$finder = array( '\PeptideCore\Order_Number', 'find' );
			$order  = is_callable( $finder ) ? call_user_func( $finder, $ref ) : null;
		}
		return $order instanceof \WC_Order ? $order : null;
	}

	/** 邮箱是不是同一个（不分大小写，不看首尾空格；任一为空都不算）。不读数据库。 */
	public static function same_email( string $given, string $on_order ): bool {
		$given    = strtolower( trim( $given ) );
		$on_order = strtolower( trim( $on_order ) );
		return '' !== $given && '' !== $on_order && hash_equals( $on_order, $given );
	}

	/**
	 * 限流的一步（不读数据库，测试里直接调用）。
	 *
	 * @param array{count:int,reset:int}|null $bucket 这个 IP 目前的计数。
	 * @return array{0:bool,1:array{count:int,reset:int}} 这次能不能继续，和新的计数。
	 */
	public static function rate_step( ?array $bucket, int $now, int $limit = self::LIMIT, int $window = self::WINDOW ): array {
		if ( ! is_array( $bucket ) || (int) ( $bucket['reset'] ?? 0 ) <= $now ) {
			$bucket = array( 'count' => 0, 'reset' => $now + $window );
		}
		if ( (int) $bucket['count'] >= $limit ) {
			return array( false, $bucket );
		}
		++$bucket['count'];
		return array( true, $bucket );
	}

	/** 这个 IP 这一分钟还能不能查；超了返回 429。 */
	private static function throttle(): ?\WP_Error {
		$now = time();
		$key = 'pc_ol_' . substr( hash_hmac( 'sha256', Api\Rest_Controller::client_ip(), wp_salt( 'nonce' ) ), 0, 32 );
		$stored          = get_transient( $key );
		[ $ok, $bucket ] = self::rate_step( is_array( $stored ) ? $stored : null, $now );
		if ( ! $ok ) {
			return new \WP_Error( 'pc_rate_limited', 'Too many attempts. Please wait a minute and try again.', array( 'status' => 429, 'retry_after' => max( 1, $bucket['reset'] - $now ) ) );
		}
		set_transient( $key, $bucket, max( 1, $bucket['reset'] - $now ) );
		return null;
	}

	/**
	 * 邮箱和订单号都对得上的那张订单；对不上返回 null。测试单不给查。
	 *
	 * @param string $ref clean_order_ref() 整理过的订单号。
	 */
	public static function find( string $email, string $ref ): ?\WC_Order {
		if ( '' === $ref || ! is_email( $email ) ) {
			return null;
		}
		$order = self::resolve( $ref );
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() || 'checkout-draft' === $order->get_status() ) {
			return null;
		}
		if ( ! self::same_email( $email, (string) $order->get_billing_email() ) || Test_Orders::is_test_order( $order ) ) {
			return null;
		}
		return $order;
	}

	public static function register_routes(): void {
		register_rest_route(
			'peptide/v1',
			'/order-lookup',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // 公开接口：靠邮箱 + 订单号核对，另有限流。
				'callback'            => array( self::class, 'lookup_route' ),
			)
		);
	}

	/**
	 * POST /peptide/v1/order-lookup { email, order }
	 * → { found: true, order: {…} }，或 { found: false, message }（HTTP 200，统一提示），或 429。
	 */
	public static function lookup_route( \WP_REST_Request $request ) {
		$limited = self::throttle();
		if ( $limited ) {
			return $limited;
		}
		$email = sanitize_email( (string) $request['email'] );
		$order = Buyer_Status::enabled() ? self::find( $email, self::clean_order_ref( (string) $request['order'] ) ) : null;
		if ( ! $order ) {
			$response = new \WP_REST_Response( array( 'found' => false, 'message' => self::NOT_FOUND ) );
		} else {
			// 付过款的订单：把这个浏览器记成已购，下次进店直接看到物流卡片。
			if ( $order->get_date_paid() ) {
				Buyer_Status::remember( $order );
			}
			$response = new \WP_REST_Response( array( 'found' => true, 'order' => Buyer_Status::card( $order ) ) );
		}
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
