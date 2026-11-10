<?php
/**
 * 已购顾客回访（M11，FE-10）。这里的“Buyer”是付过款的顾客，不是投手（投手见 Staff_Buyer）。
 *
 * 顾客付款成功后，浏览器记住“已购”一年。再次进店时不弹营销弹窗，而是看到物流卡片（订单号、商品、状态、运单号）；
 * 关掉卡片后正常购物。满减、优惠码、阶梯价等价格逻辑完全不动。
 *
 * - 开关：选项 pc_buyer_recognition，值 on 才生效，默认关。关着时不写 cookie、不静默弹窗、不加载任何前台脚本，
 *   接口一律按“不是已购顾客”回答，线上行为和以前一样。
 * - cookie pc_buyer：`v1.<订单号-订单号…>.<签发时间>.<HMAC>`，最多记 10 张订单，1 年，HttpOnly，SameSite=Lax。
 *   签名用本店的密钥，改不了、伪造不了；cookie 里没有任何顾客资料。
 * - 什么时候写：顾客带着订单密钥回到店铺、订单已付款时（付款成功弹窗的落地页 /?pc_paid=…&pc_key=…，
 *   和 WooCommerce 的“订单已收到”页面）；以及“查订单”核对成功时（Order_Lookup）。测试单不写。
 * - 静默弹窗：F2 的过滤器 pc_marketing_popups_suppressed 返回 true（首单券条、离开挽回、转盘、活动倒计时都读它，
 *   约定见 docs/CONTRACT.md 的 M4 一节）。带着 cookie 的访客本来就不走首页缓存，所以在服务端判断即可，
 *   新访客没有任何额外请求。页头另外输出 window.pcBuyer 并让 F2 的前台开关重新读一次。
 * - 物流卡片：GET peptide/v1/buyer/status 只看 cookie 和最近一张订单；前台脚本等 800 毫秒，没等到就当新客。
 * - 运单号：先读订单上的 _pc_shipment（发货对接预留，OR-01），没有再读 WooCommerce 订单履约；都没有时显示“待发货”。
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
	public const SHIP_META   = '_pc_shipment';
	public const MAX_ORDERS  = 10;
	public const LIFETIME    = 31536000; // 1 年。
	public const TIMEOUT_MS  = 800;

	/** @var array{orders:int[],issued:int}|false|null 这次请求里 cookie 的校验结果（null = 还没看）。 */
	private static $cookie = null;

	public static function register_hooks(): void {
		add_filter( self::MUTE_FILTER, array( self::class, 'mute' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_remember' ), 0 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'pc_hq_capabilities', array( self::class, 'capabilities' ) );
		Order_Lookup::register_hooks();
	}

	/** 这家店有没有打开已购回访。 */
	public static function enabled(): bool {
		return (bool) apply_filters( 'pc_buyer_recognition_enabled', 'on' === (string) get_option( self::OPTION, 'off' ) );
	}

	/** 向总后台声明这家店的代码支持已购回访（新旧版本混跑时用）。 */
	public static function capabilities( $caps ) {
		return is_array( $caps ) ? $caps + array( 'm11.buyer_status' => '1' ) : $caps;
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

	/** 这位访客是不是已购顾客（开关打开，而且带着有效的 cookie）。 */
	public static function known(): bool {
		return self::enabled() && null !== self::cookie();
	}

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
		if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) || ! $order->is_paid() || Test_Orders::is_test_order( $order ) ) {
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

	// ---------- 静默营销弹窗 ----------

	/** 过滤器 pc_marketing_popups_suppressed：已购顾客在前台不弹营销弹窗。别的代码已经要求静默时保持静默。 */
	public static function mute( $muted ) {
		return $muted || ( ! is_admin() && self::known() );
	}

	// ---------- 订单状态和物流 ----------

	/**
	 * 运单信息（不读数据库，测试里直接调用）。
	 *
	 * @param mixed      $meta   订单上的 _pc_shipment：carrier、number、url、shipped_at。
	 * @param array|null $native Shipping_Tracking::customer_view() 的结果。
	 * @param string     $status WooCommerce 订单状态（不带 wc-）。
	 * @return array{carrier:string,number:string,url:string,shipped:bool,shipped_at:string}
	 */
	public static function shipment_from( $meta, ?array $native, string $status ): array {
		$out = array( 'carrier' => '', 'number' => '', 'url' => '', 'shipped' => 'completed' === $status, 'shipped_at' => '' );
		if ( is_array( $meta ) && ( '' !== trim( (string) ( $meta['number'] ?? '' ) ) || ! empty( $meta['shipped_at'] ) ) ) {
			$out['carrier']    = trim( (string) ( $meta['carrier'] ?? '' ) );
			$out['number']     = trim( (string) ( $meta['number'] ?? '' ) );
			$out['url']        = self::safe_url( (string) ( $meta['url'] ?? '' ) );
			$out['shipped_at'] = (string) ( $meta['shipped_at'] ?? '' );
			$out['shipped']    = true;
			return $out;
		}
		$first = is_array( $native ) && ! empty( $native['shipments'][0] ) && is_array( $native['shipments'][0] ) ? $native['shipments'][0] : null;
		if ( $first ) {
			$out['carrier']    = (string) ( $first['provider_name'] ?? '' );
			$out['number']     = (string) ( $first['tracking_number'] ?? '' );
			$out['url']        = self::safe_url( (string) ( $first['tracking_url'] ?? '' ) );
			$out['shipped_at'] = (string) ( $first['shipped_at'] ?? '' );
			$out['shipped']    = true;
		}
		return $out;
	}

	/** 追踪链接只接受 http / https。 */
	private static function safe_url( string $url ): string {
		$url = trim( $url );
		return 1 === preg_match( '#^https?://[^\s<>"\']+$#i', $url ) ? $url : '';
	}

	/**
	 * 一张订单的运单信息。
	 *
	 * @return array{carrier:string,number:string,url:string,shipped:bool,shipped_at:string}
	 */
	public static function shipment( \WC_Order $order ): array {
		$meta   = $order->get_meta( self::SHIP_META );
		$native = ( is_array( $meta ) && $meta ) ? null : Shipping_Tracking::customer_view( $order );
		return self::shipment_from( $meta, $native, (string) $order->get_status() );
	}

	/**
	 * 给顾客看的订单状态（不读数据库）：[代号, 英文文字]。
	 *
	 * @return array{0:string,1:string}
	 */
	public static function state( string $status, bool $paid, bool $shipped, string $refund_state = '' ): array {
		if ( 'refunded' === $status || 'full' === $refund_state ) {
			return array( 'refunded', 'Refunded' );
		}
		if ( in_array( $status, array( 'cancelled', 'failed' ), true ) ) {
			return array( 'cancelled', 'Cancelled' );
		}
		if ( ! $paid ) {
			return array( 'unpaid', 'Payment not received yet' );
		}
		return $shipped ? array( 'shipped', 'Shipped' ) : array( 'awaiting_shipment', 'Awaiting shipment' );
	}

	/**
	 * 物流卡片要显示的内容：订单号、下单日期、状态、商品、运单。不含姓名、邮箱、电话和地址。
	 *
	 * @return array<string,mixed>
	 */
	public static function card( \WC_Order $order ): array {
		$shipment = self::shipment( $order );
		$state    = self::state( (string) $order->get_status(), (bool) $order->is_paid(), $shipment['shipped'], (string) $order->get_meta( '_pc_refund_state' ) );
		$items    = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$items[] = array(
				'name'     => wp_strip_all_tags( (string) $item->get_name() ),
				'quantity' => (int) $item->get_quantity(),
			);
		}
		$created = $order->get_date_created();
		return array(
			'number'       => (string) $order->get_order_number(),
			'placed'       => $created ? wp_date( 'M j, Y', $created->getTimestamp() ) : '',
			'status'       => $state[0],
			'status_label' => $state[1],
			'items'        => array_slice( $items, 0, 12 ),
			'more_items'   => max( 0, count( $items ) - 12 ),
			'shipment'     => array(
				'carrier' => $shipment['carrier'],
				'number'  => $shipment['number'],
				'url'     => $shipment['url'],
			),
		);
	}

	// ---------- 接口 ----------

	public static function register_routes(): void {
		register_rest_route(
			'peptide/v1',
			'/buyer/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true', // 公开接口：只认浏览器自己带来的、签过名的 cookie。
				'callback'            => array( self::class, 'status_route' ),
			)
		);
	}

	/** GET /peptide/v1/buyer/status → { buyer: false } 或 { buyer: true, order: {…} }。只看 cookie 里最近的一张订单。 */
	public static function status_route() {
		$data   = array( 'buyer' => false );
		$cookie = self::enabled() ? self::cookie() : null;
		if ( $cookie && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) end( $cookie['orders'] ) );
			if ( $order instanceof \WC_Order && $order->get_date_paid() ) {
				$data = array( 'buyer' => true, 'order' => self::card( $order ) );
			}
		}
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	// ---------- 前台 ----------

	/** 前台脚本的地址和版本（物流卡片、查订单表单共用）。 */
	public static function register_assets(): void {
		$ver = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_register_style( 'peptide-core-buyer-status', plugins_url( 'assets/buyer-status.css', PEPTIDE_CORE_FILE ), array(), $ver( 'buyer-status.css' ) );
		wp_register_script( 'peptide-core-buyer-status', plugins_url( 'assets/buyer-status.js', PEPTIDE_CORE_FILE ), array(), $ver( 'buyer-status.js' ), true );
		wp_localize_script(
			'peptide-core-buyer-status',
			'pcBuyerStatus',
			array(
				'rest'    => esc_url_raw( rest_url( 'peptide/v1/' ) ),
				'timeout' => self::TIMEOUT_MS,
				'known'   => self::known(),
				'lookup'  => Order_Lookup::page_url(),
			)
		);
	}

	/** 只给已购顾客加载物流卡片；新访客的页面没有任何变化。结账、购物车和付款成功落地页不弹卡片。 */
	public static function enqueue(): void {
		if ( ! self::known() ) {
			return;
		}
		self::register_assets();
		// 营销脚本可以同步读这个标记（服务端已经通过过滤器静默）。F2 的前台开关如果先运行了，让它重新读一次。
		wp_register_script( 'peptide-core-buyer-flag', false, array(), PEPTIDE_CORE_VERSION, false );
		wp_enqueue_script( 'peptide-core-buyer-flag' );
		wp_add_inline_script( 'peptide-core-buyer-flag', 'window.pcBuyer={known:true,suppressPopups:true};if(window.pcMarketingQuiet&&window.pcMarketingQuiet.refresh){window.pcMarketingQuiet.refresh();}' );
		$busy = ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_cart' ) && is_cart() ) || isset( $_GET['pc_paid'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $busy ) {
			return;
		}
		wp_enqueue_style( 'peptide-core-buyer-status' );
		wp_enqueue_script( 'peptide-core-buyer-status' );
	}
}
