<?php
/**
 * 后台“看数时区”（店主 2026-11-26）：每个后台账号可以把整个后台的时间换成美西或美东，默认是店铺时区。
 *
 * - 选择存在账号上（用户元数据 pc_view_tz），刷新、关浏览器、重新登录、换电脑都还在。
 * - 生效范围：这个账号打开的后台页面（wp-admin）、后台的 admin-ajax，和从后台页面发出的 REST 请求。
 *   这些请求里 WordPress 的店铺时区（timezone_string / gmt_offset）换成所选时区，所以“今天”“昨天”、按天的数字、
 *   图表的日期、列表里显示的时间都按所选时区算和显示，0 点按所选时区切日。后台表单里填的日期时间也按所选时区理解，
 *   保存时换成同一个绝对时间，所以活动开始/结束时间不会错位。
 * - 不受影响：店铺前台和顾客、定时任务（每日库存刷新等）、总后台和双桥等外部接口、其他账号。
 * - 例外：店铺时区设置页（系统设置、WordPress 常规设置）永远显示和保存店铺自己的时区，避免把店铺时区改掉；
 *   WooCommerce 自带分析接口（wc-analytics / wc-admin）按店铺时区存的本地日期列查数，也不换。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Viewer_Time {

	public const META = 'pc_view_tz';

	/** 可选的时区：键存在账号上，值是带夏令时的城市时区（和 Meta 广告账户的美西 / 美东时区一致）。 */
	public const ZONES = array(
		'us_west' => array( 'tz' => 'America/Los_Angeles', 'label' => '美西' ),
		'us_east' => array( 'tz' => 'America/New_York', 'label' => '美东' ),
	);

	/** 读店铺自己的时区时临时关掉替换。 */
	private static bool $bypass = false;

	public static function register_hooks(): void {
		add_filter( 'pre_option_timezone_string', array( self::class, 'filter_timezone_string' ), 10, 1 );
		add_filter( 'pre_option_gmt_offset', array( self::class, 'filter_gmt_offset' ), 10, 1 );
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	// ---------- 选择 ----------

	/** 账号选的时区键（'' = 店铺默认）。 */
	public static function choice( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$value = (string) get_user_meta( $user_id, self::META, true );
		return isset( self::ZONES[ $value ] ) ? $value : '';
	}

	/** 保存选择；'' 或不认识的值都回到默认（删掉元数据）。 */
	public static function save( int $user_id, string $zone ): string {
		$zone = isset( self::ZONES[ $zone ] ) ? $zone : '';
		if ( '' === $zone ) {
			delete_user_meta( $user_id, self::META );
		} else {
			update_user_meta( $user_id, self::META, $zone );
		}
		return $zone;
	}

	/**
	 * 点按钮后的新选择：点已经选中的那个 = 回到默认；点另一个 = 换成那个。纯函数。
	 */
	public static function toggle( string $current, string $clicked ): string {
		if ( ! isset( self::ZONES[ $clicked ] ) ) {
			return '';
		}
		return $current === $clicked ? '' : $clicked;
	}

	// ---------- 这次请求用哪个时区 ----------

	/** 这次请求生效的时区键（'' = 不替换）。 */
	public static function active(): string {
		if ( self::$bypass || ! did_action( 'set_current_user' ) ) {
			return '';
		}
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return '';
		}
		$zone = self::choice( $user );
		if ( '' === $zone || ! self::request_applies() ) {
			return '';
		}
		return $zone;
	}

	/** 店铺自己的时区（不受看数时区影响）。 */
	public static function store_timezone(): \DateTimeZone {
		$previous     = self::$bypass;
		self::$bypass = true;
		try {
			return wp_timezone();
		} finally {
			self::$bypass = $previous;
		}
	}

	/** 店铺时区的日期 / 时间文字（给全店共用、按店铺天存的东西用）。 */
	public static function store_date( string $format, ?int $timestamp = null ): string {
		return (string) wp_date( $format, $timestamp ?? time(), self::store_timezone() );
	}

	/**
	 * 缓存名后缀：默认时区是 ''（缓存名和以前一样），换了时区带上时区名，
	 * 不同时区的人不会拿到彼此按另一套日界算出来的数字。
	 */
	public static function cache_tag(): string {
		$zone = self::active();
		return '' === $zone ? '' : '|tz:' . self::ZONES[ $zone ]['tz'];
	}

	/** 这次请求是不是后台账号在后台看东西。 */
	private static function request_applies(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return false;
		}
		$rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
		if ( $rest ) {
			return self::from_admin_page() && ! self::rest_excluded( self::rest_route() );
		}
		if ( ! is_admin() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return self::from_admin_page();
		}
		return ! self::admin_excluded(
			(string) ( $GLOBALS['pagenow'] ?? '' ),
			isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? $_GET['page'] : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? $_REQUEST['action'] : '' // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	/** 店铺时区设置相关的后台页面和保存动作。纯函数。 */
	public static function admin_excluded( string $pagenow, string $page, string $action ): bool {
		if ( in_array( $pagenow, array( 'options-general.php', 'options.php' ), true ) ) {
			return true;
		}
		if ( 'pc-system' === $page ) { // Admin\Store_Settings::SYSTEM_SLUG：系统设置（含店铺时区）。
			return true;
		}
		return 'admin-post.php' === $pagenow && 'pc_basic_config_save' === $action;
	}

	/** WooCommerce 自带分析接口按店铺本地日期列查数，不换时区。纯函数。 */
	public static function rest_excluded( string $route ): bool {
		$route = '/' . ltrim( $route, '/' );
		return 1 === preg_match( '#^/(wc-analytics|wc-admin)(/|$)#', $route );
	}

	/** 请求是从后台页面发出的（同站、路径在 /wp-admin/ 下）。前台页面登录状态下发的请求不算。 */
	private static function from_admin_page(): bool {
		$referer = function_exists( 'wp_get_raw_referer' ) ? (string) wp_get_raw_referer() : '';
		return self::is_admin_referer( $referer, admin_url( '/' ) );
	}

	/** 纯函数：referer 是不是同一个站的后台地址。 */
	public static function is_admin_referer( string $referer, string $admin_url ): bool {
		if ( '' === $referer ) {
			return false;
		}
		$ref   = wp_parse_url( $referer );
		$admin = wp_parse_url( $admin_url );
		if ( ! is_array( $ref ) || ! is_array( $admin ) || empty( $ref['host'] ) || empty( $admin['host'] ) ) {
			return false;
		}
		if ( strtolower( (string) $ref['host'] ) !== strtolower( (string) $admin['host'] ) ) {
			return false;
		}
		$base = rtrim( (string) ( $admin['path'] ?? '/wp-admin/' ), '/' ) . '/';
		return str_starts_with( (string) ( $ref['path'] ?? '' ), $base );
	}

	private static function rest_route(): string {
		$wp = $GLOBALS['wp'] ?? null;
		if ( is_object( $wp ) && isset( $wp->query_vars['rest_route'] ) ) {
			return (string) $wp->query_vars['rest_route'];
		}
		return isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ? $_GET['rest_route'] : (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	// ---------- 替换店铺时区 ----------

	/** @param mixed $pre */
	public static function filter_timezone_string( $pre ) {
		$zone = self::active();
		return '' === $zone ? $pre : self::ZONES[ $zone ]['tz'];
	}

	/** gmt_offset 给 current_time( 'timestamp' ) 等老函数用：按当前时刻（含夏令时）的小时差。 @param mixed $pre */
	public static function filter_gmt_offset( $pre ) {
		$zone = self::active();
		if ( '' === $zone ) {
			return $pre;
		}
		$offset = ( new \DateTimeImmutable( 'now', new \DateTimeZone( self::ZONES[ $zone ]['tz'] ) ) )->getOffset();
		return $offset / HOUR_IN_SECONDS;
	}

	// ---------- 页面上的开关 ----------

	/** 顶栏开关的 HTML（两个按钮：美西、美东；点选中的那个回到默认）。 */
	public static function control_html(): string {
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return '';
		}
		$current = self::choice( $user );
		$store   = self::store_timezone();
		$label   = '' === $current ? '店铺时间' : self::ZONES[ $current ]['label'] . '时间';
		$title   = '' === $current
			? '后台按店铺时区（' . self::zone_name( $store ) . '）显示和切日。点“美西”或“美东”换时区，再点一次回到店铺时间。'
			: '后台按' . self::ZONES[ $current ]['label'] . '时间（' . self::ZONES[ $current ]['tz'] . '）显示和切日。再点一次“' . self::ZONES[ $current ]['label'] . '”回到店铺时间（' . self::zone_name( $store ) . '）。';
		$html  = '<div class="pc-view-tz" role="group" aria-label="后台时间" data-current="' . esc_attr( $current ) . '" title="' . esc_attr( $title ) . '">';
		$html .= '<span class="pc-view-tz__label" aria-live="polite">' . esc_html( $label ) . '</span>';
		foreach ( self::ZONES as $key => $zone ) {
			$html .= '<button type="button" class="pc-view-tz__btn" data-zone="' . esc_attr( $key ) . '" aria-pressed="' . ( $key === $current ? 'true' : 'false' ) . '">' . esc_html( $zone['label'] ) . '</button>';
		}
		return $html . '</div>';
	}

	/** 给开关脚本的配置。 */
	public static function script_config(): array {
		return array(
			'endpoint' => esc_url_raw( rest_url( Api\Rest_Controller::ROUTE_NAMESPACE . '/me/view-timezone' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'labels'   => array_map( static fn( $z ) => $z['label'] . '时间', self::ZONES ) + array( '' => '店铺时间' ),
		);
	}

	private static function zone_name( \DateTimeZone $tz ): string {
		$name   = $tz->getName();
		$offset = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'P' );
		return ( 'Asia/Shanghai' === $name ? '北京时间' : $name ) . ' UTC' . $offset;
	}

	// ---------- 接口 ----------

	public static function register_route(): void {
		register_rest_route(
			Api\Rest_Controller::ROUTE_NAMESPACE,
			'/me/view-timezone',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn() => self::response( self::choice( get_current_user_id() ) ),
					'permission_callback' => array( self::class, 'can' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static function ( \WP_REST_Request $request ) {
						$zone = $request->get_param( 'zone' );
						$zone = is_string( $zone ) ? $zone : '';
						if ( '' !== $zone && ! isset( self::ZONES[ $zone ] ) ) {
							return new \WP_Error( 'pc_invalid_param', '时区只能是 us_west、us_east 或空（店铺默认）。', array( 'status' => 400 ) );
						}
						return self::response( self::save( get_current_user_id(), $zone ) );
					},
					'permission_callback' => array( self::class, 'can' ),
					'args'                => array( 'zone' => array( 'type' => 'string' ) ),
				),
			)
		);
	}

	/** 能进后台的已登录账号都可以设自己的。 */
	public static function can() {
		return is_user_logged_in() && current_user_can( 'read' )
			? true
			: new \WP_Error( 'pc_forbidden', '请先登录后台。', array( 'status' => rest_authorization_required_code() ) );
	}

	private static function response( string $zone ): \WP_REST_Response {
		$res = new \WP_REST_Response(
			array(
				'zone'     => $zone,
				'timezone' => '' === $zone ? self::store_timezone()->getName() : self::ZONES[ $zone ]['tz'],
			)
		);
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}
}
