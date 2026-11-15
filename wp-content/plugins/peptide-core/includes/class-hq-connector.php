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
		add_action( 'admin_init', array( self::class, 'allow_hq_embed' ), 0 );
		add_action( 'admin_init', array( self::class, 'maybe_register' ) );
		add_action( self::CRON, array( self::class, 'daily_register' ) );
		add_action( 'shutdown', array( self::class, 'record_code_copy' ), 99 );
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
			'reg_public_url' => (string) ( $s['reg_public_url'] ?? '' ),
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
		$url = self::connection_url();
		$public_unchanged = ! Management_Host::enabled() || Management_Host::public_url() === $s['reg_public_url'];
		if ( $s['reg_key'] === hash( 'sha256', self::key() ) && $url === $s['reg_url'] && $public_unchanged && time() - $s['registered'] < DAY_IN_SECONDS ) {
			return;
		}
		if ( get_transient( 'pc_hq_reg_wait' ) ) {
			return;
		}
		set_transient( 'pc_hq_reg_wait', 1, 10 * MINUTE_IN_SECONDS );
		// Never make an admin screen wait for HQ (up to 15 s when HQ is slow; code review
		// 2026-11-06): register after the page has been sent. Independent of WP-Cron.
		add_action( 'shutdown', array( self::class, 'register_after_response' ), PHP_INT_MAX );
	}

	public static function register_after_response(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) { fastcgi_finish_request(); }
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
		if ( Management_Host::enabled() && '' !== Management_Host::configuration_error() ) {
			return new \WP_Error( 'pc_management_configuration', 'The store management identity is not configured correctly.' );
		}
		$key  = self::key();
		$origins = Management_Host::enabled() ? array( 'management_url' => Management_Host::management_url(), 'public_url' => Management_Host::public_url() ) : array();
		$body = (string) wp_json_encode(
			array_merge( array(
				'url'     => self::connection_url(),
				'name'    => get_bloginfo( 'name' ),
				'key'     => $key,
				'uuid'    => self::uuid(),
				'version' => PEPTIDE_CORE_VERSION,
			), $origins ),
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
		$s['reg_url']    = self::connection_url();
		if ( Management_Host::enabled() ) { $s['reg_public_url'] = Management_Host::public_url(); }
		$s['reg_error']  = '';
		self::save( $s );
		return true;
	}

	/** Stable machine address; customer aliases must never change the HQ destination. */
	public static function connection_url(): string {
		return Management_Host::enabled() ? trailingslashit( Management_Host::management_url() ) : home_url( '/' );
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
		register_rest_route( self::NS, '/hq/design-export', array( 'methods' => 'GET', 'callback' => array( self::class, 'design_export_route' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/hq/design-templates', array( 'methods' => 'GET', 'callback' => array( self::class, 'design_templates_route' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/hq/design-template', array( 'methods' => 'POST', 'callback' => array( self::class, 'design_template_route' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/hq/design', array( 'methods' => 'POST', 'callback' => array( self::class, 'design_route' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/hq/design-lock', array( 'methods' => 'POST', 'callback' => array( self::class, 'design_lock_route' ), 'permission_callback' => $auth ) );
		register_rest_route(
			self::NS,
			'/hq/apps',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'apps_route' ),
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

	/**
	 * 总后台决定这个店铺能不能使用某个应用（2026-11-08）。停用时先以店铺管理员身份把应用关掉，
	 * 关掉成功才记为停用；之后店铺后台看不到、也保存不了这个应用。重新开通后应用保持关闭，由店铺自己决定开不开。
	 *
	 * @return array|\WP_Error 全部应用和开通状态
	 */
	public static function apps_route( \WP_REST_Request $request ) {
		$p   = (array) $request->get_json_params();
		$key = sanitize_key( (string) ( $p['key'] ?? '' ) );
		if ( '' === $key || ! array_key_exists( 'allowed', $p ) || ! is_bool( $p['allowed'] ) || ! isset( Admin\Apps_Page::catalog()[ $key ] ) ) {
			return new \WP_Error( 'pc_app_request', '请求无效或店铺里没有这个应用。', array( 'status' => 400 ) );
		}
		if ( ! $p['allowed'] && Admin\Apps_Page::allowed( $key ) ) {
			$previous = get_current_user_id();
			$user     = self::login_user();
			if ( ! $user ) {
				return new \WP_Error( 'pc_app_user', '店铺没有可用的管理员账号。', array( 'status' => 409 ) );
			}
			wp_set_current_user( $user );
			try {
				$off = App_Switch::set( $key, false );
			} finally {
				wp_set_current_user( $previous );
			}
			if ( is_wp_error( $off ) ) {
				return new \WP_Error( 'pc_app_off', '没能先关闭这个应用，未修改：' . $off->get_error_message(), array( 'status' => 409 ) );
			}
		}
		Admin\Apps_Page::set_allowed( $key, $p['allowed'] );
		Activity_Log::log( 'settings', $p['allowed'] ? 'app_allowed' : 'app_blocked', array( 'type' => 'app', 'id' => 0, 'label' => $key ), array( 'via' => 'HQ' ) );
		return array( 'apps' => self::apps() );
	}

	/** 模板工作室：导出某个模板已发布的装修（不传 template 时导出默认模板）。 */
	public static function design_export_route( \WP_REST_Request $request ): array {
		return Design_Sync::export( absint( $request->get_param( 'template' ) ) );
	}

	/** 模板工作室：模板清单。 */
	public static function design_templates_route(): array {
		return array( 'templates' => Design_Sync::studio_list() );
	}

	/** 模板工作室：新建 / 改名 / 设为下发 / 删除。以店铺管理员身份执行。@return array|\WP_Error */
	public static function design_template_route( \WP_REST_Request $request ) {
		$p        = (array) $request->get_json_params();
		$op       = sanitize_key( (string) ( $p['op'] ?? '' ) );
		$id       = absint( $p['id'] ?? 0 );
		$previous = get_current_user_id();
		$user     = self::login_user();
		if ( ! $user ) {
			return new \WP_Error( 'pc_design_user', '店铺没有可用的管理员账号。', array( 'status' => 409 ) );
		}
		wp_set_current_user( $user );
		try {
			if ( 'create' === $op ) {
				$res = Design_Sync::studio_create( (string) ( $p['name'] ?? '' ), (string) ( $p['note'] ?? '' ), absint( $p['from'] ?? 0 ) );
				$res = is_wp_error( $res ) ? $res : array( 'id' => $res );
			} elseif ( 'update' === $op ) {
				$fields = array_intersect_key( $p, array_flip( array( 'name', 'note', 'system' ) ) );
				if ( array_key_exists( 'system', $fields ) ) {
					$fields['system'] = true === $fields['system'];
				}
				$res = Design_Sync::studio_update( $id, $fields );
			} elseif ( 'trash' === $op ) {
				$res = Design_Sync::studio_trash( $id );
			} else {
				$res = new \WP_Error( 'pc_design_request', '请求无效。', array( 'status' => 400 ) );
			}
		} finally {
			wp_set_current_user( $previous );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'ok' => true, 'result' => $res, 'templates' => Design_Sync::studio_list() );
	}

	/**
	 * 总后台“模板库”里直接装修：只有从总后台一键进入的会话，才允许被总后台页面嵌入（只允许总后台的地址），
	 * 其他情况保持 WordPress 默认的禁止嵌入。
	 */
	public static function allow_hq_embed(): void {
		$hq = self::embed_ancestor();
		if ( '' === $hq ) {
			return;
		}
		remove_action( 'admin_init', 'send_frame_options_header', 10 );
		if ( ! headers_sent() ) {
			header( "Content-Security-Policy: frame-ancestors 'self' " . $hq );
		}
	}

	/** 从总后台模板库进入的会话：允许嵌入的总后台地址（https://域名）；其他情况返回空。 */
	public static function embed_ancestor(): string {
		if ( empty( $_COOKIE['pc_hq_embed'] ) ) {
			return '';
		}
		$hq     = (string) self::settings()['hq_url'];
		$scheme = (string) wp_parse_url( $hq, PHP_URL_SCHEME );
		$host   = strtolower( (string) wp_parse_url( $hq, PHP_URL_HOST ) );
		return 'https' === $scheme && preg_match( '/\A[a-z0-9.-]{3,253}\z/', $host ) ? 'https://' . $host : '';
	}

	/** 总后台下发统一装修。以店铺管理员身份写入，避免权限检查挡住发布。@return array|\WP_Error */
	public static function design_route( \WP_REST_Request $request ) {
		$p = (array) $request->get_json_params();
		if ( ! is_array( $p['bundle'] ?? null ) || ! is_bool( $p['lock'] ?? null ) || ! is_string( $p['source_host'] ?? null ) ) {
			return new \WP_Error( 'pc_design_request', '请求无效。', array( 'status' => 400 ) );
		}
		if ( true === ( $p['dry_run'] ?? false ) ) {
			// 测试下发：校验内容、下载并核对媒体，不写入、不锁定（母版店也可以安全测试）。
			return Design_Sync::apply( $p['bundle'], $p['lock'], $p['source_host'], true );
		}
		$previous = get_current_user_id();
		$user     = self::login_user();
		if ( ! $user ) {
			return new \WP_Error( 'pc_design_user', '店铺没有可用的管理员账号。', array( 'status' => 409 ) );
		}
		wp_set_current_user( $user );
		try {
			$res = Design_Sync::apply( $p['bundle'], $p['lock'], $p['source_host'] );
		} finally {
			wp_set_current_user( $previous );
		}
		if ( ! is_wp_error( $res ) ) {
			Activity_Log::log( 'settings', 'design_applied', array( 'type' => 'design', 'id' => 0, 'label' => substr( (string) ( $res['version'] ?? '' ), 0, 12 ) ), array( 'via' => 'HQ' ) );
		}
		return $res;
	}

	public static function design_lock_route( \WP_REST_Request $request ) {
		$p = (array) $request->get_json_params();
		if ( ! is_bool( $p['lock'] ?? null ) ) {
			return new \WP_Error( 'pc_design_request', '请求无效。', array( 'status' => 400 ) );
		}
		return Design_Sync::set_lock( $p['lock'] );
	}

	/** 一次性登录可以直接打开某个应用的设置页：app_<应用键>。 */
	private static function login_target( string $to ): string {
		if ( isset( self::PLATFORM_PAGES[ $to ] ) ) {
			return self::PLATFORM_PAGES[ $to ];
		}
		if ( 'design' === $to ) {
			return 'admin.php?page=pc-store-design';
		}
		if ( preg_match( '/^design_tpl_([0-9]{1,12})$/D', $to, $t ) && Templates::exists( (int) $t[1] ) && ! Design_Sync::is_system( (int) $t[1] ) ) {
			$doc = Templates::document_id( (int) $t[1], 'home' );
			return $doc ? 'admin.php?page=pc-design-editor&document=' . $doc : '';
		}
		if ( 'apps_center' === $to && class_exists( Admin\Apps_Page::class ) ) {
			return 'admin.php?page=' . Admin\Apps_Page::SLUG;
		}
		if ( preg_match( '/^app_([a-z0-9-]{1,40})$/D', $to, $m ) && class_exists( Admin\Apps_Page::class ) && isset( Admin\Apps_Page::apps()[ $m[1] ] ) ) {
			return 'admin.php?page=' . Admin\Apps_Page::SLUG . '&app=' . $m[1];
		}
		return '';
	}

	private static function allowed_target( string $to ): bool {
		return in_array( $to, self::PLATFORM_PAGES, true ) || 'admin.php?page=pc-store-design' === $to || 1 === preg_match( '/^admin\.php\?page=pc-design-editor&document=[0-9]{1,12}$/D', $to ) || 1 === preg_match( '/^admin\.php\?page=pc-apps(?:&app=[a-z0-9-]{1,40})?$/D', $to );
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
		// 总后台改店铺名称：同步到店铺自己的名称（2026-11-08）。
		if ( isset( $p['name'] ) && is_string( $p['name'] ) && '' !== trim( sanitize_text_field( $p['name'] ) ) ) {
			update_option( 'blogname', mb_substr( trim( sanitize_text_field( $p['name'] ) ), 0, 190 ) );
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

	/** 全部内置应用和总后台给这个店铺的开通状态（总后台“插件管理 → 店铺应用”）。出错时返回空列表，不影响店铺状态。 */
	private static function apps(): array {
		$out = array();
		try {
			if ( ! class_exists( Admin\Apps_Page::class ) ) {
				return $out;
			}
			foreach ( Admin\Apps_Page::catalog() as $key => $app ) {
				$out[] = array(
					'key'     => (string) $key,
					'name'    => (string) $app['name'],
					'text'    => (string) ( $app['text'] ?? '' ),
					'allowed' => Admin\Apps_Page::allowed( (string) $key ),
				);
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		return $out;
	}

	/**
	 * 本店支持的功能和版本号，随 /hq/status 上报；总后台只对声明了的店调用新接口（新旧版本混跑时用）。
	 *
	 * 各模块用过滤器声明自己的功能，不改本类：
	 *   add_filter( 'pc_hq_capabilities', static fn( array $caps ): array => $caps + array( 'series.sync' => '1' ) );
	 * 键：小写字母、数字、点、下划线、连字符，最长 64；值：版本号字符串，最长 32。格式不对的条目丢弃。
	 *
	 * @return array<string,string>
	 */
	public static function capabilities(): array {
		$base = array( 'hq.capabilities' => '1' );
		try {
			$declared = apply_filters( 'pc_hq_capabilities', $base );
		} catch ( \Throwable $e ) {
			// 某个模块的声明出错不能让轮询失败（店铺会被误报“连不上”）。
			$declared = $base;
		}
		return self::clean_capabilities( $declared ) + $base;
	}

	/**
	 * @param mixed $declared
	 * @return array<string,string>
	 */
	public static function clean_capabilities( $declared ): array {
		$out = array();
		foreach ( is_array( $declared ) ? $declared : array() as $key => $version ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/D', $key ) ) {
				continue;
			}
			if ( true === $version ) {
				$version = '1';
			}
			if ( ! is_string( $version ) && ! is_int( $version ) && ! is_float( $version ) ) {
				continue;
			}
			$version = (string) $version;
			if ( '' === $version || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,31}$/D', $version ) ) {
				continue;
			}
			$out[ $key ] = $version;
		}
		ksort( $out, SORT_STRING );
		return array_slice( $out, 0, 200, true );
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
			'url'         => self::connection_url(),
			'management_url' => Management_Host::enabled() ? Management_Host::management_url() : untrailingslashit( home_url( '/' ) ),
			'public_url'  => Management_Host::enabled() ? Management_Host::public_url() : untrailingslashit( home_url( '/' ) ),
			'admin_url'   => admin_url(),
			'config'      => self::config(),
			'theme'       => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'plugins'     => $plugins,
			'apps'        => self::apps(),
			'capabilities' => self::capabilities(),
			'modules'     => self::module_status(),
			'code'        => self::code_report(),
			'design'      => Design_Sync::summary(),
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
		$rows = self::with_abandoned( $rows, $start->getTimestamp(), $end->getTimestamp() );
		return array(
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'timezone' => wp_timezone_string(),
			'days'     => array_values( $rows ),
		);
	}

	/**
	 * 每日汇总里加上弃单数（M6 的 Data\Metrics::abandoned_daily()，店铺时区的日期）：总后台用它算弃单率。
	 * 店铺上还没有 M6 的代码时不加这几项——总后台看到没有 `abandoned` 这个键就知道这家店还不支持，不能当成 0。
	 *
	 * @param array<string,array> $rows 日期 => 当天的汇总
	 * @return array<string,array>
	 */
	public static function with_abandoned( array $rows, int $from, int $to ): array {
		$metrics = __NAMESPACE__ . '\\Data\\Metrics';
		if ( ! $rows || ! is_callable( array( $metrics, 'abandoned_daily' ) ) ) {
			return $rows;
		}
		try {
			$by_day = (array) call_user_func( array( $metrics, 'abandoned_daily' ), $from, $to );
		} catch ( \Throwable $e ) {
			return $rows; // 弃单读不出来不能让每日汇总失败。
		}
		foreach ( $rows as $day => $row ) {
			$a                               = is_array( $by_day[ $day ] ?? null ) ? $by_day[ $day ] : array();
			$rows[ $day ]['abandoned']       = (int) ( $a['count'] ?? 0 );
			$rows[ $day ]['abandoned_open']  = (int) ( $a['open'] ?? 0 );
			$rows[ $day ]['recall_emailed']  = (int) ( $a['emailed'] ?? 0 );
			$rows[ $day ]['recall_recovered'] = (int) ( $a['recovered'] ?? 0 );
		}
		return $rows;
	}

	/**
	 * /hq/status 里各模块的简报：总后台巡检不另发请求，只看这里。
	 * 约定好的两项直接带上（类还没部署时不带）；其他模块用过滤器 pc_hq_status_modules 加自己的键：
	 *   add_filter( 'pc_hq_status_modules', fn( array $m ) => $m + array( 'my_module' => array( … ) ) );
	 * 值必须能转成 JSON，不要放顾客资料和密钥。某一项出错只丢这一项。
	 *
	 * @return array<string,mixed>
	 */
	public static function module_status(): array {
		$out     = array();
		$sources = array(
			'pricing_engine' => array( __NAMESPACE__ . '\\Pricing\\Runtime', 'status_brief' ),   // M4：档位和今天的核对次数
			'onboarding'     => array( __NAMESPACE__ . '\\Admin\\Onboarding', 'store_status' ),  // M9：开店向导是否完成
		);
		foreach ( $sources as $key => $callable ) {
			if ( is_callable( $callable ) ) {
				try {
					$out[ $key ] = call_user_func( $callable );
				} catch ( \Throwable $e ) {
					unset( $out[ $key ] );
				}
			}
		}
		try {
			$extra = apply_filters( 'pc_hq_status_modules', array() );
		} catch ( \Throwable $e ) {
			$extra = array();
		}
		foreach ( is_array( $extra ) ? $extra : array() as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/^[a-z][a-z0-9_]{1,40}$/D', $key ) && ! isset( $out[ $key ] ) && ( is_array( $value ) || is_scalar( $value ) ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	// ---------- 代码版本（M1-6）----------

	/** 一家店的前台和后台是两份代码副本、共用一个库：每份副本把自己的组件哈希记在这个选项里，总后台一次轮询就能看到全部副本。 */
	public const CODE_OPTION = 'pc_code_copies';

	/** 和 tools/deployment/prepare_release.py 一致：这些目录不进发布包，不参与哈希。 */
	private const CODE_SKIP_DIRS = array( 'tests', 'node_modules', 'vendor', 'uploads', 'cache', 'backups', 'private', '__pycache__' );

	/**
	 * 一个组件目录的文件清单哈希。算法（发布脚本用同一个，改了要两边一起改）：
	 * 目录下除 CODE_SKIP_DIRS 以外的全部文件，按相对路径排序，逐个拼 “相对路径 \0 文件的 sha256 \n”，再整体取 sha256。
	 *
	 * @return array{files:int,hash:string}
	 */
	public static function manifest_hash( string $dir ): array {
		$dir = rtrim( $dir, '/\\' );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return array( 'files' => 0, 'hash' => '' );
		}
		$files = array();
		$walk  = static function ( string $path, string $rel ) use ( &$walk, &$files ): void {
			foreach ( (array) scandir( $path ) as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$full = $path . '/' . $name;
				if ( is_dir( $full ) ) {
					if ( ! is_link( $full ) && ! in_array( strtolower( $name ), self::CODE_SKIP_DIRS, true ) ) {
						$walk( $full, $rel . $name . '/' );
					}
				} elseif ( is_file( $full ) ) {
					$files[ $rel . $name ] = $full;
				}
			}
		};
		$walk( $dir, '' );
		ksort( $files, SORT_STRING );
		$text = '';
		foreach ( $files as $rel => $full ) {
			$text .= $rel . "\0" . hash_file( 'sha256', $full ) . "\n";
		}
		return array( 'files' => count( $files ), 'hash' => hash( 'sha256', $text ) );
	}

	/** 便宜的变化标记：文件数、总大小、最新修改时间。没变就不用重新读一遍全部文件。 */
	private static function dir_signature( string $dir ): string {
		$count = 0;
		$size  = 0;
		$mtime = 0;
		$walk  = static function ( string $path ) use ( &$walk, &$count, &$size, &$mtime ): void {
			foreach ( (array) @scandir( $path ) as $name ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$full = $path . '/' . $name;
				if ( is_dir( $full ) ) {
					if ( ! is_link( $full ) && ! in_array( strtolower( $name ), self::CODE_SKIP_DIRS, true ) ) {
						$walk( $full );
					}
				} else {
					++$count;
					$size += (int) @filesize( $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$mtime = max( $mtime, (int) @filemtime( $full ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		};
		$walk( $dir );
		return $count . '|' . $size . '|' . $mtime;
	}

	/**
	 * 这份副本上各组件的版本号和清单哈希。
	 *
	 * @return array<string,array{version:string,files:int,hash:string}>
	 */
	public static function code_components(): array {
		$plugins = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '';
		$dirs    = array(
			'peptide-core'    => array( $plugins . '/peptide-core', $plugins . '/peptide-core/peptide-core.php' ),
			'peptide-panapay' => array( $plugins . '/peptide-panapay', $plugins . '/peptide-panapay/peptide-panapay.php' ),
			'peptide-ops'     => array( $plugins . '/peptide-ops', $plugins . '/peptide-ops/peptide-ops.php' ),
			'peptide-site'    => array( get_theme_root() . '/peptide-site', get_theme_root() . '/peptide-site/style.css' ),
		);
		$out = array();
		foreach ( $dirs as $name => $pair ) {
			list( $dir, $main ) = $pair;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$sig    = self::dir_signature( $dir );
			$key    = 'pc_code_m_' . md5( $dir );
			$cached = get_transient( $key );
			if ( ! is_array( $cached ) || ( $cached['sig'] ?? '' ) !== $sig ) {
				$cached = self::manifest_hash( $dir ) + array( 'sig' => $sig );
				set_transient( $key, $cached, DAY_IN_SECONDS );
			}
			$header       = is_readable( $main ) ? get_file_data( $main, array( 'version' => 'Version' ) ) : array();
			$out[ $name ] = array(
				'version' => (string) ( $header['version'] ?? '' ),
				'files'   => (int) $cached['files'],
				'hash'    => (string) $cached['hash'],
			);
		}
		return $out;
	}

	private static function code_copy_id(): string {
		return substr( md5( ABSPATH ), 0, 12 );
	}

	/** 每份副本大约每 10 分钟把自己的组件哈希写进共用的选项（内容没变时一小时写一次，只为刷新时间）。 */
	public static function record_code_copy(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) ) {
			return;
		}
		$id = self::code_copy_id();
		if ( get_transient( 'pc_code_copy_' . $id ) ) {
			return;
		}
		set_transient( 'pc_code_copy_' . $id, 1, 10 * MINUTE_IN_SECONDS );
		try {
			$now    = time();
			$copies = get_option( self::CODE_OPTION, array() );
			$copies = is_array( $copies ) ? $copies : array();
			$mine   = array(
				'path'       => untrailingslashit( ABSPATH ),
				'host'       => isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '',
				'components' => self::code_components(),
				'at'         => $now,
			);
			$old    = is_array( $copies[ $id ] ?? null ) ? $copies[ $id ] : array();
			if ( ( $old['components'] ?? null ) === $mine['components'] && $now - (int) ( $old['at'] ?? 0 ) < HOUR_IN_SECONDS ) {
				return;
			}
			$copies[ $id ] = $mine;
			foreach ( $copies as $key => $copy ) {
				if ( ! is_array( $copy ) || $now - (int) ( $copy['at'] ?? 0 ) > 3 * DAY_IN_SECONDS ) {
					unset( $copies[ $key ] ); // 三天没有请求的副本（旧的发布目录）不再上报。
				}
			}
			update_option( self::CODE_OPTION, $copies, false );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * 给总后台的代码版本报告：回答这次请求的这份副本（现算），加上各副本自己登记的（可能有几分钟延迟）。
	 *
	 * @return array{this:string,copies:array<string,array>}
	 */
	public static function code_report(): array {
		try {
			$copies = get_option( self::CODE_OPTION, array() );
			$copies = is_array( $copies ) ? $copies : array();
			$id     = self::code_copy_id();
			$copies[ $id ] = array(
				'path'       => untrailingslashit( ABSPATH ),
				'host'       => (string) ( $copies[ $id ]['host'] ?? '' ),
				'components' => self::code_components(),
				'at'         => time(),
			);
			return array( 'this' => $id, 'copies' => $copies );
		} catch ( \Throwable $e ) {
			return array( 'this' => '', 'copies' => array() );
		}
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
			array_merge( array(
				'user' => $user,
				'to'   => self::login_target( $to ),
				'embed' => 1 === preg_match( '/^design_tpl_[0-9]+$/D', $to ),
			), Management_Host::enabled() ? array( 'management_url' => Management_Host::management_url(), 'uuid' => self::uuid() ) : array() ),
			self::LOGIN_TTL
		);
		return array(
			'url'  => add_query_arg( self::LOGIN_ARG, $token, self::connection_url() ),
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
		$bound = ! Management_Host::enabled() || ( Management_Host::is_management_request() && is_array( $data )
			&& hash_equals( self::uuid(), (string) ( $data['uuid'] ?? '' ) )
			&& hash_equals( Management_Host::management_url(), (string) ( $data['management_url'] ?? '' ) ) );
		if ( ! $bound || ! $user || ! user_can( $user, 'manage_options' ) ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		wp_set_current_user( $user );
		wp_set_auth_cookie( $user, false, is_ssl() );
		// 从总后台“模板库”打开装修：这个会话允许被总后台嵌入（见 allow_hq_embed）。
		if ( ! empty( $data['embed'] ) ) {
			setcookie( 'pc_hq_embed', '1', array( 'expires' => 0, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
			$_COOKIE['pc_hq_embed'] = '1';
		} elseif ( isset( $_COOKIE['pc_hq_embed'] ) ) {
			setcookie( 'pc_hq_embed', '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		}
		Activity_Log::log( 'login', 'login', array( 'type' => 'user', 'id' => $user, 'label' => get_userdata( $user )->user_login ), array( 'via' => 'HQ' ), $user );
		$to = (string) ( $data['to'] ?? '' );
		wp_safe_redirect( admin_url( self::allowed_target( $to ) ? $to : '' ) );
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
			'staff_entry_url' => Staff_Entry::url( $u->ID ),
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
