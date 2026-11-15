<?php
/**
 * 已购顾客回访（M11，FE-10）。这里的“Buyer”是付过款的顾客，不是投手（投手见 Staff_Buyer）。
 *
 * 2026-11-14 平台所有者定了 Q20（方案 A，“优先用站点已经存在的”）：看物流只用线上已有的物流面板（Track_Widget，
 * 右下角物流球）。M11 原来的物流卡片、/track-order 页面、[pc_track_order] 短代码、POST peptide/v1/order-lookup
 * 和 GET peptide/v1/buyer/status 都已下线。M11 只做线上没有的一件事：认出已购顾客，让营销弹窗不再打扰他。
 *
 * - 开关：选项 pc_buyer_recognition，值 on 才生效，默认关。关着时不写 cookie、不静默弹窗、页面上不多任何东西，
 *   和线上一样。
 * - 怎么认：① 浏览器带着有效的 cookie pc_buyer；或者 ② 已登录、账户名下有已付款订单（测试单不算）。
 * - cookie pc_buyer：`v1.<订单号-订单号…>.<签发时间>.<HMAC>`，最多记 10 张订单，1 年，HttpOnly，SameSite=Lax。
 *   签名用本店的密钥，改不了、伪造不了；cookie 里没有任何顾客资料。格式和线上旧版完全相同，旧 cookie 照样认。
 * - 什么时候写 cookie：顾客带着订单密钥回到店铺、订单已付款时（付款成功弹窗的落地页 /?pc_paid=…&pc_key=…，
 *   和 WooCommerce 的“订单已收到”页面）；以及线上物流面板核对订单成功、触发动作 pc_track_order_verified 时
 *   （这个动作要日常会话在物流面板里加，约定见 docs/CONTRACT.md 的 M11 一节；没加之前这一条不生效）。测试单不写。
 * - 静默弹窗：F2 的过滤器 pc_marketing_popups_suppressed 返回 true（首单券条、离开挽回、转盘、活动倒计时都读它，
 *   读取用 \PeptideCore\Pricing\Popups::suppressed()，约定见 docs/CONTRACT.md 的 M4 一节）。带着 cookie 的访客
 *   和已登录的顾客本来就不走首页缓存，所以在服务端判断即可，新访客没有任何额外请求。页头另外输出
 *   window.pcBuyer 并让 F2 的前台开关重新读一次。注册弹窗要不要也让开，由日常会话读 Popups::suppressed() 决定。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Buyer_Status {

	public const OPTION      = 'pc_buyer_recognition';
	public const COOKIE      = 'pc_buyer';
	/** F2（M4）提供的营销弹窗静默过滤器，读取用 \PeptideCore\Pricing\Popups::suppressed()。 */
	public const MUTE_FILTER = 'pc_marketing_popups_suppressed';
	/** 线上物流面板核对订单成功时触发的动作（参数：那张 WC_Order）。由日常会话在 Track_Widget 里加。 */
	public const VERIFIED_ACTION = 'pc_track_order_verified';
	/** 已下线的查订单短代码；留一个空的处理，万一哪家店的页面里还留着它，也不会露出方括号原文。 */
	public const RETIRED_SHORTCODE = 'pc_track_order';
	public const MAX_ORDERS  = 10;
	public const LIFETIME    = 31536000; // 1 年。

	/** @var array{orders:int[],issued:int}|false|null 这次请求里 cookie 的校验结果（null = 还没看）。 */
	private static $cookie = null;

	/** @var array<int,bool> 这次请求里查过的账户：编号 => 名下有没有已付款订单。 */
	private static $accounts = array();

	public static function register_hooks(): void {
		add_filter( self::MUTE_FILTER, array( self::class, 'mute' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_remember' ), 0 );
		add_action( self::VERIFIED_ACTION, array( self::class, 'verified' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
		add_filter( 'pc_hq_capabilities', array( self::class, 'capabilities' ) );
		add_shortcode( self::RETIRED_SHORTCODE, '__return_empty_string' );
	}

	/** 这家店有没有打开已购回访。 */
	public static function enabled(): bool {
		return (bool) apply_filters( 'pc_buyer_recognition_enabled', 'on' === (string) get_option( self::OPTION, 'off' ) );
	}

	/** 向总后台声明这家店的代码支持已购回访（新旧版本混跑时用）；m11.plan_a 表示查订单已交给线上物流面板。 */
	public static function capabilities( $caps ) {
		return is_array( $caps ) ? $caps + array( 'm11.buyer_status' => '1', 'm11.plan_a' => '1' ) : $caps;
	}

	// ---------- cookie ----------

	private static function secret(): string {
		return wp_salt( 'pc_buyer' );
	}

	/** cookie 的值（不读数据库，测试里直接调用）。订单号从旧到新，最多保留最近 10 个。 */
	public static function encode( array $order_ids, int $issued, string $secret ): string {
		$ids = implode( '-', array_slice( array_values( array_unique( array_filter( array_map( 'intval', $order_ids ), static fn( int $id ): bool => $id > 0 ) ) ), -self::MAX_ORDERS ) );
		if ( '' === $ids ) {
			return '';
		}
		return 'v1.' . $ids . '.' . $issued . '.' . hash_hmac( 'sha256', 'pc-buyer-v1|' . $ids . '|' . $issued, $secret );
	}

	/**
	 * 校验 cookie（不读数据库）。格式不对、签名不对、超过一年或签发时间在未来，都返回 null。
	 *
	 * @return array{orders:int[],issued:int}|null
	 */
	public static function decode( string $value, int $now, string $secret ): ?array {
		if ( 1 !== preg_match( '/^v1\.([0-9]{1,12}(?:-[0-9]{1,12}){0,' . ( self::MAX_ORDERS - 1 ) . '})\.([0-9]{9,11})\.([a-f0-9]{64})$/D', $value, $m ) ) {
			return null;
		}
		$issued = (int) $m[2];
		if ( $issued > $now + 300 || $now - $issued > self::LIFETIME ) {
			return null;
		}
		if ( ! hash_equals( hash_hmac( 'sha256', 'pc-buyer-v1|' . $m[1] . '|' . $issued, $secret ), $m[3] ) ) {
			return null;
		}
		return array(
			'orders' => array_map( 'intval', explode( '-', $m[1] ) ),
			'issued' => $issued,
		);
	}

	/** 把新订单号加到列表最后（已经在列表里就挪到最后）。 */
	public static function merge_ids( array $existing, int $order_id ): array {
		$ids   = array_values( array_filter( array_map( 'intval', $existing ), static fn( int $id ): bool => $id > 0 && $id !== $order_id ) );
		$ids[] = $order_id;
		return array_slice( $ids, -self::MAX_ORDERS );
	}

	/**
	 * 这次请求带来的有效 cookie；没有或无效时为 null。只验签名，不查订单。
	 *
	 * @return array{orders:int[],issued:int}|null
	 */
	public static function cookie(): ?array {
		if ( null === self::$cookie ) {
			$raw          = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
			$decoded      = '' !== $raw ? self::decode( $raw, time(), self::secret() ) : null;
			self::$cookie = null === $decoded ? false : $decoded;
		}
		return false === self::$cookie ? null : self::$cookie;
	}

	// ---------- 已登录的账户 ----------

	/**
	 * 一组订单里有没有算数的已付款订单（不读数据库，测试里直接调用）：付过款（有付款时间），而且不是测试单。
	 *
	 * @param iterable $orders  WC_Order（或带 get_date_paid() 的对象）。
	 * @param callable $is_test 判断测试单，默认 Test_Orders::is_test_order()。
	 */
	public static function has_paid_order( iterable $orders, ?callable $is_test = null ): bool {
		$is_test = $is_test ?? array( Test_Orders::class, 'is_test_order' );
		foreach ( $orders as $order ) {
			if ( is_object( $order ) && method_exists( $order, 'get_date_paid' ) && $order->get_date_paid() && ! $is_test( $order ) ) {
				return true;
			}
		}
		return false;
	}

	/** 这个账户名下有没有已付款订单（订单状态“处理中 / 已完成”，测试单不算）。同一次请求里只查一次。 */
	public static function account_is_buyer( int $user_id ): bool {
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}
		if ( ! isset( self::$accounts[ $user_id ] ) ) {
			$paid   = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : array( 'processing', 'completed' );
			$orders = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'type'        => 'shop_order',
					'status'      => array_map( static fn( $s ): string => 'wc-' . $s, $paid ),
					'limit'       => 5,
					'orderby'     => 'date',
					'order'       => 'DESC',
					'return'      => 'objects',
				)
			);
			self::$accounts[ $user_id ] = self::has_paid_order( is_array( $orders ) ? $orders : array() );
		}
		return self::$accounts[ $user_id ];
	}

	/** 这位访客是不是已购顾客：开关打开，而且带着有效的 cookie，或者已登录且账户名下有已付款订单。 */
	public static function known(): bool {
		if ( ! self::enabled() ) {
			return false;
		}
		if ( null !== self::cookie() ) {
			return true;
		}
		return function_exists( 'is_user_logged_in' ) && is_user_logged_in() && self::account_is_buyer( (int) get_current_user_id() );
	}

	// ---------- 写 cookie ----------

	/** 给这个浏览器记上一张已付款的订单。 */
	public static function remember( \WC_Order $order ): bool {
		if ( ! self::enabled() || headers_sent() ) {
			return false;
		}
		$now   = time();
		$ids   = self::merge_ids( self::cookie()['orders'] ?? array(), (int) $order->get_id() );
		$value = self::encode( $ids, $now, self::secret() );
		if ( '' === $value ) {
			return false;
		}
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $now + self::LIFETIME,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $value;
		self::$cookie            = array( 'orders' => $ids, 'issued' => $now );
		return true;
	}

	/** 一张订单能不能记成“已购”：真正的订单、付过款、不是测试单。 */
	private static function rememberable( $order ): bool {
		return $order instanceof \WC_Order && 'shop_order' === $order->get_type() && $order->is_paid() && ! Test_Orders::is_test_order( $order );
	}

	/** 带着订单密钥回到店铺的那张已付款订单（付款成功落地页，或“订单已收到”页面）。测试单不算。 */
	private static function order_from_request(): ?\WC_Order {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 用订单密钥核对，不是表单提交。
		$id  = 0;
		$key = '';
		if ( ! empty( $_GET['pc_paid'] ) && ! empty( $_GET['pc_key'] ) && is_string( $_GET['pc_key'] ) ) {
			$id  = absint( $_GET['pc_paid'] );
			$key = sanitize_text_field( wp_unslash( $_GET['pc_key'] ) );
		} elseif ( function_exists( 'is_order_received_page' ) && is_order_received_page() && ! empty( $_GET['key'] ) && is_string( $_GET['key'] ) ) {
			$id  = absint( get_query_var( 'order-received' ) );
			$key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		}
		// phpcs:enable
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) || ! self::rememberable( $order ) ) {
			return null;
		}
		return $order;
	}

	public static function maybe_remember(): void {
		if ( is_admin() || ! self::enabled() ) {
			return;
		}
		$order = self::order_from_request();
		if ( $order ) {
			self::remember( $order );
		}
	}

	/** 动作 pc_track_order_verified：线上物流面板核对订单成功（订单号 + 下单邮箱，或这台设备记住的订单密钥）。 */
	public static function verified( $order ): void {
		if ( self::enabled() && self::rememberable( $order ) ) {
			self::remember( $order );
		}
	}

	// ---------- 静默营销弹窗 ----------

	/** 过滤器 pc_marketing_popups_suppressed：已购顾客在前台不弹营销弹窗。别的代码已经要求静默时保持静默。 */
	public static function mute( $muted ) {
		return $muted || ( ! is_admin() && self::known() );
	}

	/** 只给已购顾客输出 window.pcBuyer；新访客的页面没有任何变化。 */
	public static function enqueue(): void {
		if ( ! self::known() ) {
			return;
		}
		// 营销脚本可以同步读这个标记（服务端已经通过过滤器静默）。F2 的前台开关如果先运行了，让它重新读一次。
		wp_register_script( 'peptide-core-buyer-flag', false, array(), PEPTIDE_CORE_VERSION, false );
		wp_enqueue_script( 'peptide-core-buyer-flag' );
		wp_add_inline_script( 'peptide-core-buyer-flag', 'window.pcBuyer={known:true,suppressPopups:true};if(window.pcMarketingQuiet&&window.pcMarketingQuiet.refresh){window.pcMarketingQuiet.refresh();}' );
	}
}
