<?php
/**
 * 总后台连接（用户 2026-10-27：一个销售一个站点，所有站点都是用户开的，一定连着总后台；店铺上不要连接页面）。
 * 总后台本身是另一个插件 peptide-hq。
 *
 * - 开店铺时在 wp-config.php 加两行：PEPTIDE_HQ_URL（总后台地址）和 PEPTIDE_HQ_SECRET（总后台的注册密钥）。
 *   店铺自己生成连接密钥，用注册密钥签名后发给总后台注册（进后台时、每天一次；密钥变了马上再注册）。没人需要看到或复制密钥。
 * - 总后台调用店铺的接口 /peptide/v1/hq/*，每次请求都要签名：
 *   HMAC-SHA256( 连接密钥, 方法 \n 路由 \n 排好序的查询参数 \n 时间 \n 请求体的 SHA-256 )，
 *   放在 X-PC-HQ-Time / X-PC-HQ-Sign 头里。时间相差超过 5 分钟、签名不对或重复使用的请求都拒绝。
 * - 接口：status（店铺状态）、summary（每天的订单、销售额、访客）、login（一次性登录链接，2 分钟内有效，
 *   可以直接打开某个平台工具页面）、staff（销售账号：列出、新建 / 修改、删除，规则和店铺的员工权限相同）、
 *   config（总后台下发：服务器 IP、搜索引擎收录、访客追踪；店铺后台不显示这些开关，2026-10-27 前后台隔开）。
 * - 顾客的个人信息不通过这些接口给出，总后台只拿汇总数字。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Hq_Connector {

	public const OPTION    = 'peptide_core_hq';
	public const NS        = 'peptide/v1';
	public const LOGIN_ARG = 'pc_hq_login';
	public const CRON      = 'pc_hq_register';

	/** 请求时间和店铺时间最多相差几秒。 */
	private const SKEW = 300;

	/** 一次性登录链接有效几秒。 */
	private const LOGIN_TTL = 120;

	/** summary 最多给多少天。 */
	public const MAX_DAYS = 92;

	/**
	 * 平台工具（2026-10-27 用户要求前后台彻底隔开）：店铺后台不显示这些页面，只从总后台一键登录后打开。
	 * 键 => 后台地址。
	 */
	public const PLATFORM_PAGES = array(
		'plugins'     => 'plugins.php',
		'themes'      => 'themes.php',
		'updates'     => 'update-core.php',
		'general'     => 'options-general.php',
		'reading'     => 'options-reading.php',
		'permalinks'  => 'options-permalink.php',
		'privacy'     => 'options-privacy.php',
		'discussion'  => 'options-discussion.php',
		'health'      => 'site-health.php',
		'status'      => 'admin.php?page=wc-status',
		'scheduler'   => 'admin.php?page=wc-status&tab=action-scheduler',
		'wc_settings' => 'admin.php?page=wc-settings&tab=general',
		'pc_settings' => 'admin.php?page=pc-settings',
		'export_data' => 'export-personal-data.php',
		'erase_data'  => 'erase-personal-data.php',
		'users'       => 'users.php',
	);

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'init', array( self::class, 'maybe_login' ), 0 );
		add_action( 'admin_init', array( self::class, 'maybe_register' ) );
		add_action( self::CRON, array( self::class, 'daily_register' ) );
		if ( self::configured() && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'daily', self::CRON );
		}
	}

	// ---------- 密钥和连接码 ----------

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'key'        => (string) ( $s['key'] ?? '' ),
			'created'    => (int) ( $s['created'] ?? 0 ),
			'hq_url'     => (string) ( $s['hq_url'] ?? '' ),
			'seen'       => (int) ( $s['seen'] ?? 0 ),
			'registered' => (int) ( $s['registered'] ?? 0 ),
			'reg_key'    => (string) ( $s['reg_key'] ?? '' ),
			'reg_url'    => (string) ( $s['reg_url'] ?? '' ),
			'reg_error'  => (string) ( $s['reg_error'] ?? '' ),
			'uuid'       => (string) ( $s['uuid'] ?? '' ),
		);
	}

	/** 店铺的固定编号：店铺换主域名（网址变了）后，总后台仍然认得是同一个店铺。 */
	public static function uuid(): string {
		$s = self::settings();
		if ( '' === $s['uuid'] ) {
			$s['uuid'] = wp_generate_uuid4();
			self::save( $s );
		}
		return $s['uuid'];
	}

	private static function save( array $s ): void {
		update_option( self::OPTION, $s, false );
	}

	/** 连接密钥；没有时生成。 */
	public static function key(): string {
		$s = self::settings();
		if ( 64 !== strlen( $s['key'] ) ) {
			$s['key']     = bin2hex( random_bytes( 32 ) );
			$s['created'] = time();
			self::save( $s );
		}
		return $s['key'];
	}

	/** 换一个连接密钥（例如怀疑泄露）。店铺下次进后台或定时任务时会用新密钥重新注册，总后台确认能连上后换掉旧的。 */
	public static function regenerate(): void {
		$s            = self::settings();
		$s['key']     = bin2hex( random_bytes( 32 ) );
		$s['created'] = time();
		self::save( $s );
	}

	// ---------- 自动注册到总后台 ----------

	/** wp-config.php 里有没有总后台的两行。 */
	public static function configured(): bool {
		return defined( 'PEPTIDE_HQ_URL' ) && defined( 'PEPTIDE_HQ_SECRET' ) && '' !== (string) PEPTIDE_HQ_URL && 64 === strlen( (string) PEPTIDE_HQ_SECRET );
	}

	/** 进后台时：还没注册过、密钥变了或超过一天，就注册一次（失败后 10 分钟内不重试）。 */
	public static function maybe_register(): void {
		if ( ! self::configured() || wp_doing_ajax() ) {
			return;
		}
		$s = self::settings();
		if ( $s['reg_key'] === hash( 'sha256', self::key() ) && home_url( '/' ) === $s['reg_url'] && time() - $s['registered'] < DAY_IN_SECONDS ) {
			return;
		}
		if ( get_transient( 'pc_hq_reg_wait' ) ) {
			return;
		}
		set_transient( 'pc_hq_reg_wait', 1, 10 * MINUTE_IN_SECONDS );
		self::register();
	}

	public static function daily_register(): void {
		if ( self::configured() ) {
			self::register();
		}
	}

	/**
	 * 向总后台注册：店铺地址、名称、连接密钥，用注册密钥签名。
	 *
	 * @return true|\WP_Error
	 */
	public static function register() {
		if ( ! self::configured() ) {
			return new \WP_Error( 'pc_hq_unset', 'PEPTIDE_HQ_URL / PEPTIDE_HQ_SECRET are not set in wp-config.php.' );
		}
		$key  = self::key();
		$body = (string) wp_json_encode(
			array(
				'url'     => home_url( '/' ),
				'name'    => get_bloginfo( 'name' ),
				'key'     => $key,
				'uuid'    => self::uuid(),
				'version' => PEPTIDE_CORE_VERSION,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		$time = time();
		$res  = wp_remote_post(
			add_query_arg( 'rest_route', rawurlencode( '/peptide-hq/v1/register' ), trailingslashit( (string) PEPTIDE_HQ_URL ) ),
			array(
				'timeout' => 15,
				'headers' => array(
					'X-PC-HQ-Time' => (string) $time,
					'X-PC-HQ-Sign' => hash_hmac( 'sha256', "register\n" . $time . "\n" . hash( 'sha256', $body ), (string) PEPTIDE_HQ_SECRET ),
					'Content-Type' => 'application/json',
				),
				'body'    => $body,
			)
		);
		$s    = self::settings();
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$s['reg_error'] = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $code;
			self::save( $s );
			return new \WP_Error( 'pc_hq_register', $s['reg_error'] );
		}
		$s['registered'] = time();
		$s['reg_key']    = hash( 'sha256', $key );
		$s['reg_url']    = home_url( '/' );
		$s['reg_error']  = '';
		self::save( $s );
		return true;
	}

	// ---------- 签名 ----------

	/** 签名用的查询参数：去掉 rest_route，按键排序。 */
	public static function canonical_query( array $params ): string {
		unset( $params['rest_route'] );
		ksort( $params );
		return http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	public static function sign( string $key, string $method, string $route, string $query, int $time, string $body ): string {
		return hash_hmac( 'sha256', strtoupper( $method ) . "\n" . $route . "\n" . $query . "\n" . $time . "\n" . hash( 'sha256', $body ), $key );
	}

	/**
	 * 检查总后台的签名。
	 *
	 * @return true|\WP_Error
	 */
	public static function verify( \WP_REST_Request $request ) {
		$s = self::settings();
		if ( 64 !== strlen( $s['key'] ) ) {
			return new \WP_Error( 'pc_hq_off', 'This store has no connection key yet.', array( 'status' => 401 ) );
		}
		$time = (int) $request->get_header( 'x_pc_hq_time' );
		$sign = strtolower( (string) $request->get_header( 'x_pc_hq_sign' ) );
		if ( abs( time() - $time ) > self::SKEW ) {
			return new \WP_Error( 'pc_hq_time', 'The request time is too far from the store time.', array( 'status' => 401 ) );
		}
		$expect = self::sign( $s['key'], $request->get_method(), $request->get_route(), self::canonical_query( $request->get_query_params() ), $time, (string) $request->get_body() );
		if ( 64 !== strlen( $sign ) || ! hash_equals( $expect, $sign ) ) {
			return new \WP_Error( 'pc_hq_sign', 'The signature is not valid.', array( 'status' => 401 ) );
		}
		// 同一个签名只能用一次（防止别人截下请求再发一遍）。
		$seen = 'pc_hq_n_' . substr( $sign, 0, 40 );
		if ( get_transient( $seen ) ) {
			return new \WP_Error( 'pc_hq_replay', 'This request was already used.', array( 'status' => 401 ) );
		}
		set_transient( $seen, 1, self::SKEW * 2 );
		$hq          = esc_url_raw( (string) $request->get_header( 'x_pc_hq_url' ) );
		$s['hq_url'] = '' !== $hq ? $hq : $s['hq_url'];
		$s['seen']   = time();
		self::save( $s );
		return true;
	}

	// ---------- 接口 ----------

	public static function routes(): void {
		$auth = array( self::class, 'verify' );
		register_rest_route(
			self::NS,
			'/hq/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'status' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/hq/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'summary_route' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/hq/login',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'login_route' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/hq/staff',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'staff_list' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'staff_save' ),
					'permission_callback' => $auth,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/hq/staff/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'staff_delete' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/hq/config',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'config_route' ),
				'permission_callback' => $auth,
			)
		);
	}

	/** 由总后台决定的店铺设置（店铺后台不显示这些开关）。 */
	public static function config(): array {
		$t = Tracking\Tracker::settings();
		return array(
			'server_ip'   => Domains::server_ip(),
			'blog_public' => '1' === (string) get_option( 'blog_public', '1' ),
			'tracking'    => array(
				'enabled'        => Tracking\Tracker::enabled(),
				'retention_days' => (int) $t['retention_days'],
				'gpc'            => (bool) $t['gpc'],
			),
		);
	}

	/**
	 * 总后台下发设置：服务器 IP（解析记录指向它）、是否允许搜索引擎收录、访客追踪（开关、保留天数、GPC）。只改传过来的项。
	 *
	 * @return array|\WP_Error
	 */
	public static function config_route( \WP_REST_Request $request ) {
		$p = (array) $request->get_json_params();
		if ( array_key_exists( 'server_ip', $p ) ) {
			$done = Domains::save_server_ip( (string) $p['server_ip'] );
			if ( is_wp_error( $done ) ) {
				$done->add_data( array( 'status' => 400 ) );
				return $done;
			}
		}
		if ( array_key_exists( 'blog_public', $p ) ) {
			update_option( 'blog_public', ! empty( $p['blog_public'] ) ? '1' : '0' );
		}
		if ( isset( $p['tracking'] ) && is_array( $p['tracking'] ) ) {
			$t   = $p['tracking'];
			$now = Tracking\Tracker::settings();
			if ( isset( $t['retention_days'] ) || isset( $t['gpc'] ) ) {
				Tracking\Tracker::save_settings( (int) ( $t['retention_days'] ?? $now['retention_days'] ), (bool) ( $t['gpc'] ?? $now['gpc'] ) );
			}
			if ( isset( $t['enabled'] ) ) {
				Tracking\Tracker::set_enabled( (bool) $t['enabled'] );
			}
		}
		return self::config();
	}

	/** 店铺状态：名称、地址、版本、域名、待发货订单、最近一笔订单的时间，以及总后台下发的设置。 */
	public static function status(): array {
		$last    = function_exists( 'wc_get_orders' ) ? wc_get_orders( array( 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order', 'return' => 'objects' ) ) : array();
		$counts  = (array) wp_count_posts( 'product' );
		$theme   = wp_get_theme();
		$plugins = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $p ) {
			if ( is_plugin_active( $file ) ) {
				$plugins[] = array(
					'name'    => (string) $p['Name'],
					'version' => (string) $p['Version'],
				);
			}
		}
		return array(
			'uuid'        => self::uuid(),
			'name'        => get_bloginfo( 'name' ),
			'url'         => home_url( '/' ),
			'admin_url'   => admin_url(),
			'config'      => self::config(),
			'theme'       => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'plugins'     => $plugins,
			'versions'    => array(
				'plugin' => PEPTIDE_CORE_VERSION,
				'wp'     => get_bloginfo( 'version' ),
				'wc'     => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'php'    => PHP_VERSION,
			),
			'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'timezone'    => wp_timezone_string(),
			'coming_soon' => 'yes' === get_option( 'woocommerce_coming_soon' ),
			'tracking'    => Tracking\Tracker::enabled(),
			'products'    => (int) ( $counts['publish'] ?? 0 ),
			'processing'  => function_exists( 'wc_processing_order_count' ) ? (int) wc_processing_order_count() : 0,
			'last_order'  => $last && $last[0]->get_date_created() ? (int) $last[0]->get_date_created()->getTimestamp() : 0,
			'staff'       => count( get_users( array( 'role' => Staff::ROLE, 'fields' => 'ID' ) ) ),
			'domains'     => array_values(
				array_map(
					static fn( array $d ): array => array(
						'host'    => $d['host'],
						'conn'    => $d['conn'],
						'https'   => $d['https'],
						'primary' => $d['host'] === Domains::primary_host(),
					),
					Domains::items()
				)
			),
			'time'        => time(),
		);
	}

	public static function summary_route( \WP_REST_Request $request ): array {
		return self::summary( (int) ( $request->get_param( 'days' ) ?? 30 ) );
	}

	/**
	 * 最近 N 天（店铺时区，含今天）每天的已付款订单数、销售额（减去退款）和访客数。
	 */
	public static function summary( int $days ): array {
		$days  = max( 1, min( self::MAX_DAYS, $days ) );
		$tz    = wp_timezone();
		$start = ( new \DateTimeImmutable( 'today', $tz ) )->modify( '-' . ( $days - 1 ) . ' days' );
		$end   = ( new \DateTimeImmutable( 'tomorrow', $tz ) );
		$rows  = array();
		for ( $d = $start; $d < $end; $d = $d->modify( '+1 day' ) ) {
			$rows[ $d->format( 'Y-m-d' ) ] = array(
				'day'      => $d->format( 'Y-m-d' ),
				'orders'   => 0,
				'revenue'  => 0.0,
				'visitors' => 0,
			);
		}
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'limit'        => -1,
					'type'         => 'shop_order',
					'status'       => array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() ),
					'date_created' => $start->getTimestamp() . '...' . ( $end->getTimestamp() - 1 ),
					'return'       => 'objects',
				)
			);
			foreach ( $orders as $o ) {
				if ( ! $o->get_date_created() ) {
					continue;
				}
				$day = wp_date( 'Y-m-d', $o->get_date_created()->getTimestamp() );
				if ( isset( $rows[ $day ] ) ) {
					++$rows[ $day ]['orders'];
					$rows[ $day ]['revenue'] += (float) $o->get_total() - (float) $o->get_total_refunded();
				}
			}
		}
		if ( Tracking\Schema::installed() ) {
			global $wpdb;
			$visits = Tracking\Schema::table( 'visits' );
			$list   = $wpdb->get_results( $wpdb->prepare( "SELECT visitor_id, start_ms FROM {$visits} WHERE start_ms >= %d AND start_ms < %d AND is_test = 0 LIMIT 500000", $start->getTimestamp() * 1000, $end->getTimestamp() * 1000 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$seen   = array();
			foreach ( (array) $list as $r ) {
				$day = wp_date( 'Y-m-d', (int) floor( (int) $r['start_ms'] / 1000 ) );
				if ( isset( $rows[ $day ] ) && ! isset( $seen[ $day ][ $r['visitor_id'] ] ) ) {
					$seen[ $day ][ $r['visitor_id'] ] = true;
					++$rows[ $day ]['visitors'];
				}
			}
		}
		foreach ( $rows as &$r ) {
			$r['revenue'] = round( $r['revenue'], 2 );
		}
		unset( $r );
		return array(
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'timezone' => wp_timezone_string(),
			'days'     => array_values( $rows ),
		);
	}

	// ---------- 一键登录 ----------

	/** 总后台一键进店铺后台时用的管理员：最早的管理员账号。 */
	private static function login_user(): int {
		$ids = get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID' ) );
		return (int) ( $ids[0] ?? 0 );
	}

	/** 一次性登录链接；to 是 PLATFORM_PAGES 里的键时，登录后打开那个平台工具页面，否则打开后台首页。 */
	public static function login_route( \WP_REST_Request $request ): array {
		$user  = self::login_user();
		$to    = sanitize_key( (string) ( ( (array) $request->get_json_params() )['to'] ?? '' ) );
		$token = wp_generate_password( 40, false );
		set_transient(
			'pc_hq_login_' . hash( 'sha256', $token ),
			array(
				'user' => $user,
				'to'   => self::PLATFORM_PAGES[ $to ] ?? '',
			),
			self::LOGIN_TTL
		);
		return array(
			'url'  => add_query_arg( self::LOGIN_ARG, $token, home_url( '/' ) ),
			'user' => $user ? (string) get_userdata( $user )->user_login : '',
		);
	}

	/** 打开一次性登录链接：登录成管理员，进后台。链接只能用一次。 */
	public static function maybe_login(): void {
		if ( empty( $_GET[ self::LOGIN_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET[ self::LOGIN_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key   = 'pc_hq_login_' . hash( 'sha256', $token );
		$data  = get_transient( $key );
		$user  = is_array( $data ) ? (int) ( $data['user'] ?? 0 ) : 0;
		delete_transient( $key );
		nocache_headers();
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		wp_set_current_user( $user );
		wp_set_auth_cookie( $user, false, is_ssl() );
		Activity_Log::log( 'login', 'login', array( 'type' => 'user', 'id' => $user, 'label' => get_userdata( $user )->user_login ), array( 'via' => 'HQ' ), $user );
		$to = (string) ( $data['to'] ?? '' );
		wp_safe_redirect( admin_url( in_array( $to, self::PLATFORM_PAGES, true ) ? $to : '' ) );
		exit;
	}

	// ---------- 销售账号 ----------

	private static function account( \WP_User $u ): array {
		return array(
			'id'         => $u->ID,
			'login'      => $u->user_login,
			'email'      => $u->user_email,
			'name'       => (string) $u->first_name,
			'modules'    => array_values( array_intersect( array_keys( Staff::modules() ), (array) get_user_meta( $u->ID, Staff::META_MODULES, true ) ) ),
			'disabled'   => Staff::disabled( $u->ID ),
			'last_login' => (int) get_user_meta( $u->ID, Staff::META_LOGIN, true ),
			'created'    => (int) strtotime( $u->user_registered . ' UTC' ),
		);
	}

	public static function staff_list(): array {
		return array(
			'accounts' => array_map( array( self::class, 'account' ), get_users( array( 'role' => Staff::ROLE, 'orderby' => 'ID' ) ) ),
			'modules'  => array_map( static fn( array $m ): string => $m['label'], Staff::modules() ),
		);
	}

	/**
	 * 新建或修改销售账号（和店铺“员工管理”同一套规则）。
	 *
	 * @return array|\WP_Error
	 */
	public static function staff_save( \WP_REST_Request $request ) {
		$p      = (array) $request->get_json_params();
		$result = Admin\Staff_Page::save(
			absint( $p['id'] ?? 0 ),
			array(
				'login'    => sanitize_user( (string) ( $p['login'] ?? '' ), true ),
				'email'    => sanitize_email( (string) ( $p['email'] ?? '' ) ),
				'name'     => sanitize_text_field( (string) ( $p['name'] ?? '' ) ),
				'password' => (string) ( $p['password'] ?? '' ),
				'modules'  => array_map( 'sanitize_key', (array) ( $p['modules'] ?? array() ) ),
				'disabled' => ! empty( $p['disabled'] ),
			)
		);
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return self::account( new \WP_User( (int) $result ) );
	}

	/**
	 * 删除销售账号：他的内容转给最早的管理员。
	 *
	 * @return array|\WP_Error
	 */
	public static function staff_delete( \WP_REST_Request $request ) {
		$id = absint( ( (array) $request->get_json_params() )['id'] ?? 0 );
		if ( ! Staff::is_staff( $id ) ) {
			return new \WP_Error( 'pc_staff_missing', __( 'This account is not a staff account.', 'peptide-core' ), array( 'status' => 404 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $id, self::login_user() );
		return array( 'deleted' => $id );
	}
}
