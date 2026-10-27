<?php
/**
 * 访客追踪（正式版，TRACKING-LIVE-01；测试版 VISITOR-ANALYTICS-TEST-01 的数据 is_test = 1）：开关、访客 / 访问编号、给主题的配置。
 *
 * 身份规则：
 * - 访客：前台页面加载时由服务器发一个 HttpOnly cookie（pc_vid，32 位十六进制随机数，一年）。脚本读不到它，
 *   同时打开的多个标签页共用同一个访客。它只用于统计，不能当作登录凭证。数据库里的访客行在第一次收到事件时才建，
 *   不跑脚本的爬虫不会产生记录。
 * - 访问：同一访客 30 分钟（VISIT_GAP_MS）没有任何事件，下一次事件开始新的访问。
 * - 标签页：前端在 sessionStorage 里保存的 tab_id（同一标签页内跨页面不变），没有时退回 page_instance_id。
 * - 登录：登录时（wp_login）把当前访客关联到账号；之后每批事件也会根据登录 cookie 补关联。员工账号不追踪。
 *
 * 默认关闭。开启：后台“访客数据”页面的开关，或 wp peptide tracking enable。
 * 不追踪：员工；浏览器发出 GPC 信号（设置里默认遵守）；打开过退出链接 ?pc_px_optout=1 的浏览器（和广告像素共用）。
 * 保存期限、顾客的导出 / 抹除请求见 Privacy。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Tracker {

	public const OPTION   = 'peptide_core_tracking';
	public const COOKIE   = 'pc_vid';
	public const PROTOCOL = 1;

	public const VISIT_GAP_MS = 1800000; // 30 分钟没有事件 → 新访问。
	public const HEARTBEAT_MS = 15000;
	public const IDLE_MS      = 60000;
	public const OFFLINE_MS   = 45000;   // 最后一次信号超过 45 秒（三次心跳）→ 离线。
	public const EXIT_MS      = 10000;   // 最后一个事件是离开页面且 10 秒内没有新信号 → 离线。

	public const RETENTION_CHOICES = array( 30, 90, 180, 365 );
	public const DEFAULT_RETENTION = 180;

	public static function register_hooks(): void {
		// 表结构有新版本时先升级（只比较一个选项，开销很小），否则追踪会因为“未安装”而停下。
		if ( get_option( Schema::OPTION ) && ! Schema::installed() ) {
			Schema::install();
		}
		// 保存期限和隐私请求：关着追踪时也要能删旧数据、处理顾客的请求。
		Privacy::register_hooks();
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'template_redirect', array( self::class, 'ensure_cookie' ), 1 );
		add_filter( 'vialcove_visitor_observer_config', array( self::class, 'observer_config' ), 20 );
		Server_Events::register_hooks();
		Carts::register_hooks();
	}

	/** 设置：开关、保存天数、是否遵守 GPC（默认遵守）。 */
	public static function settings(): array {
		$o    = get_option( self::OPTION, array() );
		$o    = is_array( $o ) ? $o : array();
		$days = (int) ( $o['retention_days'] ?? self::DEFAULT_RETENTION );
		return array(
			'enabled'        => ! empty( $o['enabled'] ),
			'retention_days' => in_array( $days, self::RETENTION_CHOICES, true ) ? $days : self::DEFAULT_RETENTION,
			'gpc'            => ! array_key_exists( 'gpc', $o ) || ! empty( $o['gpc'] ),
		);
	}

	public static function enabled(): bool {
		return self::settings()['enabled'] && Schema::installed();
	}

	public static function set_enabled( bool $on ): void {
		if ( $on && ! Schema::installed() ) {
			Schema::install();
		}
		self::store( array( 'enabled' => $on ) + self::settings() );
		Privacy::schedule( $on );
	}

	public static function save_settings( int $retention_days, bool $gpc ): void {
		self::store( array( 'retention_days' => in_array( $retention_days, self::RETENTION_CHOICES, true ) ? $retention_days : self::DEFAULT_RETENTION, 'gpc' => $gpc ) + self::settings() );
	}

	private static function store( array $s ): void {
		update_option( self::OPTION, array( 'enabled' => (bool) $s['enabled'], 'retention_days' => (int) $s['retention_days'], 'gpc' => (bool) $s['gpc'], 'changed_ms' => self::now_ms() ), false );
	}

	/** 这个浏览器表示不要追踪：发出 GPC 信号（设置里默认遵守），或打开过退出链接（和广告像素共用 pc_px_optout）。 */
	public static function declined(): bool {
		if ( self::settings()['gpc'] && isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) ) ) {
			return true;
		}
		return isset( $_COOKIE[ \PeptideCore\Pixels::OPTOUT ] ) && '1' === (string) $_COOKIE[ \PeptideCore\Pixels::OPTOUT ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	public static function now_ms(): int {
		return (int) floor( microtime( true ) * 1000 );
	}

	// ---------- 身份 ----------

	/** 浏览器带来的访客编号；没有或格式不对时返回空字符串。 */
	public static function current_vid(): string {
		$vid = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return preg_match( '/^[a-f0-9]{32}$/', $vid ) ? $vid : '';
	}

	/** 发一个新的访客编号（写 cookie，并让本次请求后面的代码也能读到）。 */
	public static function issue_vid(): string {
		$vid = bin2hex( random_bytes( 16 ) );
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$vid,
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE ] = $vid;
		return $vid;
	}

	/** 登录 cookie 对应的账号（只用来关联统计，不改变当前请求的登录状态）。 */
	public static function cookie_user_id(): int {
		$id = is_user_logged_in() ? get_current_user_id() : (int) wp_validate_auth_cookie( '', 'logged_in' );
		return max( 0, $id );
	}

	public static function is_staff( int $user_id ): bool {
		return $user_id > 0 && ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) );
	}

	/** 前台页面加载时发访客 cookie。后台、预览、员工账号不发。 */
	public static function ensure_cookie(): void {
		if ( is_admin() || is_preview() || is_customize_preview() || isset( $_GET['pc_frame'] ) || isset( $_GET['pc_preview_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( self::is_staff( self::cookie_user_id() ) || '' !== self::current_vid() || self::declined() ) {
			return;
		}
		self::issue_vid();
	}

	public static function device_of( string $ua ): string {
		if ( preg_match( '/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua ) ) {
			return 'tablet';
		}
		return preg_match( '/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone/i', $ua ) ? 'mobile' : 'desktop';
	}

	public static function browser_of( string $ua ): string {
		foreach ( array( 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung', 'CriOS' => 'Chrome', 'Chrome/' => 'Chrome', 'FxiOS' => 'Firefox', 'Firefox/' => 'Firefox', 'Safari/' => 'Safari' ) as $needle => $name ) {
			if ( str_contains( $ua, $needle ) ) {
				return $name;
			}
		}
		return 'Other';
	}

	private static function user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 400 ) : '';
	}

	/**
	 * 访客行：没有时建好。返回 [id, user_id]。
	 *
	 * @return array{id:int,user_id:int}
	 */
	public static function visitor( string $vid, int $now_ms ): array {
		global $wpdb;
		$table = Schema::table( 'visitors' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM {$table} WHERE vid = %s", $vid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $row ) {
			return array( 'id' => (int) $row['id'], 'user_id' => (int) $row['user_id'] );
		}
		$ua = self::user_agent();
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (vid, first_ms, last_ms, device, browser, is_test) VALUES (%s, %d, %d, %s, %s, 0)", $vid, $now_ms, $now_ms, self::device_of( $ua ), self::browser_of( $ua ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM {$table} WHERE vid = %s", $vid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array( 'id' => (int) ( $row['id'] ?? 0 ), 'user_id' => (int) ( $row['user_id'] ?? 0 ) );
	}

	/** 把访客关联到账号（只在还没关联或关联的是同一账号时写入；换账号登录时以最新登录为准）。 */
	public static function link_user( int $visitor_id, int $user_id, int $visit_id = 0 ): void {
		global $wpdb;
		if ( $visitor_id <= 0 || $user_id <= 0 ) {
			return;
		}
		$wpdb->update( Schema::table( 'visitors' ), array( 'user_id' => $user_id ), array( 'id' => $visitor_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $visit_id ) {
			$wpdb->update( Schema::table( 'visits' ), array( 'user_id' => $user_id ), array( 'id' => $visit_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * 这次活动属于哪一次访问：最近一次访问在 30 分钟内有事件就沿用，否则新开一次。
	 */
	public static function visit( int $visitor_id, int $now_ms, string $landing_path = '' ): int {
		global $wpdb;
		// 同一访客的两个标签页同时上报时，不能各自新开一次访问：查找和新建放在访客级的数据库锁里。
		$lock = 'pc_trk_visit_' . $visitor_id;
		$got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
		try {
			return self::find_or_create_visit( $visitor_id, $now_ms, $landing_path );
		} finally {
			if ( $got ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}

	private static function find_or_create_visit( int $visitor_id, int $now_ms, string $landing_path ): int {
		global $wpdb;
		$visits = Schema::table( 'visits' );
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT id, last_ms FROM {$visits} WHERE visitor_id = %d ORDER BY start_ms DESC, id DESC LIMIT 1", $visitor_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $row && (int) $row['last_ms'] >= $now_ms - self::VISIT_GAP_MS ) {
			// 先把最后活动时间推到现在，锁释放后别的请求看到的就是这次访问。
			$wpdb->query( $wpdb->prepare( "UPDATE {$visits} SET last_ms = GREATEST(last_ms, %d) WHERE id = %d", $now_ms, (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			return (int) $row['id'];
		}
		$visitor = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id, device FROM ' . Schema::table( 'visitors' ) . ' WHERE id = %d', $visitor_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$visits,
			array(
				'visitor_id'   => $visitor_id,
				'user_id'      => (int) ( $visitor['user_id'] ?? 0 ),
				'start_ms'     => $now_ms,
				'last_ms'      => $now_ms,
				'landing_path' => substr( $landing_path, 0, 500 ),
				'device'       => (string) ( $visitor['device'] ?? '' ),
				'is_test'      => 0,
			)
		);
		$id = (int) $wpdb->insert_id;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'visitors' ) . ' SET visits = visits + 1 WHERE id = %d', $visitor_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return $id;
	}

	/** 访问的转化阶段只升不降：1 看过商品、2 加购成功、3 进入结账、4 下单、5 支付成功。 */
	public static function raise_stage( int $visit_id, int $stage, int $order_id = 0 ): void {
		global $wpdb;
		$visits = Schema::table( 'visits' );
		if ( $order_id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$visits} SET stage = GREATEST(stage, %d), order_id = %d WHERE id = %d", $stage, $order_id, $visit_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE {$visits} SET stage = GREATEST(stage, %d) WHERE id = %d", $stage, $visit_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}
	}

	// ---------- 给主题的配置 ----------

	/** 主题过滤器 vialcove_visitor_observer_config：开启时告诉前端接收地址和计时口径；不要追踪的浏览器保持主题的默认值（关闭）。 */
	public static function observer_config( $config ): array {
		$config = is_array( $config ) ? $config : array();
		if ( self::declined() ) {
			return $config;
		}
		return array_merge(
			$config,
			array(
				'enabled'      => true,
				'test_mode'    => false,
				'mode'         => 'live',
				'protocol'     => self::PROTOCOL,
				'endpoint'     => rest_url( 'peptide/v1/track/events' ),
				'heartbeat_ms' => self::HEARTBEAT_MS,
				'idle_ms'      => self::IDLE_MS,
				'batch_max'    => Ingest::MAX_EVENTS,
			)
		);
	}
}
