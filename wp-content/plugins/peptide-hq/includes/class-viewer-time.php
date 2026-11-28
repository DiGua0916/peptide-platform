<?php
/**
 * 总后台“美西时间”开关（店主 2026-11-28，照店铺后台 peptide-core Viewer_Time 做）。
 *
 * - 每个总后台账号自己选，存在账号上（用户元数据 pchq_view_tz），刷新、重新登录都还在。
 * - 打开后，这个账号的总后台页面（wp-admin）、后台 admin-ajax 和从后台页面发出的 REST 请求里，
 *   总后台时区（timezone_string / gmt_offset）换成美西（America/Los_Angeles，含夏令时）：
 *   “今天”“昨天”、按时间戳算的订单 / 销售额 / 退款 / 顾客按美西 0 点切日，列表里的时间按美西显示。
 * - 访客、弃单、广告花费是各店 / 广告账户按天汇总后送来的（一天一行），没法按美西重新切日；
 *   打开美西时这几列照原来的日子取数，页面上标出来（见 day_keyed_note）。
 * - 不受影响：店铺上报的接口（店铺发来的请求没有后台 referer）、定时任务、WP-CLI、其他账号、
 *   常规设置页（总后台自己的时区设置永远按原时区显示和保存）。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Viewer_Time {

	public const META = 'pchq_view_tz';

	/** 可选时区（和 Meta 广告账户的美西时区一致）。 */
	public const ZONES = array(
		'us_west' => array( 'tz' => 'America/Los_Angeles', 'label' => '美西' ),
	);

	private static bool $bypass = false;

	public static function register_hooks(): void {
		add_filter( 'pre_option_timezone_string', array( self::class, 'filter_timezone_string' ), 10, 1 );
		add_filter( 'pre_option_gmt_offset', array( self::class, 'filter_gmt_offset' ), 10, 1 );
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	// ---------- 选择 ----------

	public static function choice( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$value = (string) get_user_meta( $user_id, self::META, true );
		return isset( self::ZONES[ $value ] ) ? $value : '';
	}

	public static function save( int $user_id, string $zone ): string {
		$zone = isset( self::ZONES[ $zone ] ) ? $zone : '';
		if ( '' === $zone ) {
			delete_user_meta( $user_id, self::META );
		} else {
			update_user_meta( $user_id, self::META, $zone );
		}
		return $zone;
	}

	/** 点按钮后的新选择：点已选中的 = 回到默认；点另一个 = 换成那个。纯函数。 */
	public static function toggle( string $current, string $clicked ): string {
		if ( ! isset( self::ZONES[ $clicked ] ) ) {
			return '';
		}
		return $current === $clicked ? '' : $clicked;
	}

	// ---------- 这次请求用哪个时区 ----------

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

	/** 总后台自己的时区（不受开关影响）。 */
	public static function home_timezone(): \DateTimeZone {
		$previous     = self::$bypass;
		self::$bypass = true;
		try {
			return wp_timezone();
		} finally {
			self::$bypass = $previous;
		}
	}

	/** 总后台自己时区的日期文字（给按店铺日存的表取日子用，不跟着美西开关变）。 */
	public static function home_date( string $format, ?int $timestamp = null ): string {
		return (string) wp_date( $format, $timestamp ?? time(), self::home_timezone() );
	}

	/** 概览页的说明：概览全是各店按天送来的数，不换美西日；没打开返回 ''。 */
	public static function overview_note(): string {
		if ( '' === self::active() ) {
			return '';
		}
		return '已打开美西时间：概览的数字是各店每天汇总后送来的（按' . self::zone_name( self::home_timezone() ) . '的日子），所以这里的“今天”“昨天”仍按原来的日子；要按美西日看订单和销售额，请看跨店报表。';
	}

	/** 打开美西时给“按天送来的数”加的说明；没打开返回 ''。 */
	public static function day_keyed_note(): string {
		if ( '' === self::active() ) {
			return '';
		}
		return '订单、销售额、退款、顾客按美西时间切日；访客和弃单是各店按' . self::zone_name( self::home_timezone() ) . '每天汇总的，广告花费按广告账户的日子记，这几列没有换成美西日。';
	}

	private static function request_applies(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return self::from_admin_page();
		}
		if ( ! is_admin() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return self::from_admin_page();
		}
		return ! self::admin_excluded( (string) ( $GLOBALS['pagenow'] ?? '' ) );
	}

	/** 时区设置页永远按总后台自己的时区。纯函数。 */
	public static function admin_excluded( string $pagenow ): bool {
		return in_array( $pagenow, array( 'options-general.php', 'options.php' ), true );
	}

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

	// ---------- 替换时区 ----------

	/** @param mixed $pre */
	public static function filter_timezone_string( $pre ) {
		$zone = self::active();
		return '' === $zone ? $pre : self::ZONES[ $zone ]['tz'];
	}

	/** @param mixed $pre */
	public static function filter_gmt_offset( $pre ) {
		$zone = self::active();
		if ( '' === $zone ) {
			return $pre;
		}
		$offset = ( new \DateTimeImmutable( 'now', new \DateTimeZone( self::ZONES[ $zone ]['tz'] ) ) )->getOffset();
		return $offset / HOUR_IN_SECONDS;
	}

	// ---------- 顶栏开关 ----------

	public static function control_html(): string {
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return '';
		}
		$current = self::choice( $user );
		$home    = self::zone_name( self::home_timezone() );
		$label   = '' === $current ? $home : self::ZONES[ $current ]['label'] . '时间';
		$title   = '' === $current
			? '总后台按' . $home . '显示和切日。点“美西”换成美西时间，再点一次回来。'
			: '总后台按美西时间（America/Los_Angeles）显示和切日。再点一次“美西”回到' . $home . '。';
		$html  = '<div class="pchq-view-tz" role="group" aria-label="后台时间" data-current="' . esc_attr( $current ) . '" title="' . esc_attr( $title ) . '">';
		$html .= '<span class="pchq-view-tz__label" aria-live="polite">' . esc_html( $label ) . '</span>';
		foreach ( self::ZONES as $key => $zone ) {
			$html .= '<button type="button" class="pchq-view-tz__btn" data-zone="' . esc_attr( $key ) . '" aria-pressed="' . ( $key === $current ? 'true' : 'false' ) . '">' . esc_html( $zone['label'] ) . '</button>';
		}
		return $html . '</div>';
	}

	public static function script_config(): array {
		return array(
			'endpoint' => esc_url_raw( rest_url( Register::NS . '/me/view-timezone' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'labels'   => array_map( static fn( $z ) => $z['label'] . '时间', self::ZONES ) + array( '' => self::zone_name( self::home_timezone() ) ),
		);
	}

	private static function zone_name( \DateTimeZone $tz ): string {
		$name = $tz->getName();
		if ( 'Asia/Shanghai' === $name || '+08:00' === $name ) {
			return '北京时间';
		}
		return $name . ' UTC' . ( new \DateTimeImmutable( 'now', $tz ) )->format( 'P' );
	}

	// ---------- 接口 ----------

	public static function register_route(): void {
		register_rest_route(
			Register::NS,
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
							return new \WP_Error( 'pchq_invalid_param', '时区只能是 us_west 或空（总后台默认）。', array( 'status' => 400 ) );
						}
						return self::response( self::save( get_current_user_id(), $zone ) );
					},
					'permission_callback' => array( self::class, 'can' ),
					'args'                => array( 'zone' => array( 'type' => 'string' ) ),
				),
			)
		);
	}

	public static function can() {
		return is_user_logged_in() && current_user_can( Admin\Menu::CAP )
			? true
			: new \WP_Error( 'pchq_forbidden', '请先登录总后台。', array( 'status' => rest_authorization_required_code() ) );
	}

	private static function response( string $zone ): \WP_REST_Response {
		$res = new \WP_REST_Response(
			array(
				'zone'     => $zone,
				'timezone' => '' === $zone ? self::home_timezone()->getName() : self::ZONES[ $zone ]['tz'],
			)
		);
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}
}
