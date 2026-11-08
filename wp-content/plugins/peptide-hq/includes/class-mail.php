<?php
/**
 * 邮件中心（2026-11-08）：所有店铺的邮件统一由总后台代发。
 *
 * - 发信邮箱只在总后台连接一次（FluentSMTP，例如微软邮箱），新开的店铺不用任何设置。
 * - 店铺用自己的连接密钥签名，把邮件交给 POST /peptide-hq/v1/mail；签名方式和总后台调用店铺时相同：
 *   HMAC-SHA256( 连接密钥, 方法 \n 路由 \n 查询参数 \n 时间 \n 请求体 SHA-256 )，头 X-PC-HQ-Site（店铺编号）/ X-PC-HQ-Time / X-PC-HQ-Sign。
 * - 发件人名称用店铺名称；回复地址沿用店铺邮件里的 Reply-To。
 * - 每天总量上限（默认 280，个人微软邮箱每天约 300 封）和每店每小时上限，超了就拒收，保护发信邮箱。
 * - 记录每封邮件的店铺、收件人数量、主题、结果，不保存正文。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Mail {

	public const OPTION     = 'pchq_mail';
	private const DB_OPTION = 'pchq_mail_db';
	private const DB_VER    = '1';
	private const SKEW      = 300;
	private const KEEP      = 3000;

	/** 微软登录回来的地址：个人微软账户不接受带“?”的回调地址，总后台又没有 /wp-json/ 路径，所以给一个干净的地址再转进去。 */
	public const OUTLOOK_CALLBACK = '/oauth/outlook-callback/';

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'rest_url', array( self::class, 'outlook_callback_url' ), 10, 2 );
		add_action( 'init', array( self::class, 'outlook_callback_forward' ), 0 );
	}

	public static function outlook_callback_url( $url, $path ) {
		return 'fluent-smtp/outlook_callback' === trim( (string) $path, '/' ) ? home_url( self::OUTLOOK_CALLBACK ) : $url;
	}

	public static function outlook_callback_forward(): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( rtrim( $path, '/' ) . '/' !== self::OUTLOOK_CALLBACK || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		$query = (string) ( $_SERVER['QUERY_STRING'] ?? '' );
		wp_safe_redirect( home_url( '/index.php?rest_route=/fluent-smtp/outlook_callback' . ( '' !== $query ? '&' . $query : '' ) ) );
		exit;
	}

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'enabled'   => ! array_key_exists( 'enabled', $s ) || ! empty( $s['enabled'] ),
			'daily_cap' => max( 1, min( 100000, (int) ( $s['daily_cap'] ?? 280 ) ) ),
			'site_hour' => max( 1, min( 10000, (int) ( $s['site_hour'] ?? 120 ) ) ),
		);
	}

	public static function save_settings( array $in ): void {
		$cur = self::settings();
		update_option( self::OPTION, array(
			'enabled'   => array_key_exists( 'enabled', $in ) ? (bool) $in['enabled'] : $cur['enabled'],
			'daily_cap' => max( 1, min( 100000, (int) ( $in['daily_cap'] ?? $cur['daily_cap'] ) ) ),
			'site_hour' => max( 1, min( 10000, (int) ( $in['site_hour'] ?? $cur['site_hour'] ) ) ),
		), false );
	}

	/** 总后台的发信连接（FluentSMTP 默认连接）。 */
	public static function sender(): array {
		$s = get_option( 'fluentmail-settings', array() );
		if ( ! is_array( $s ) || empty( $s['connections'] ) || ! is_array( $s['connections'] ) ) {
			return array( 'ready' => false, 'provider' => '', 'email' => '' );
		}
		$default = (string) ( $s['misc']['default_connection'] ?? '' );
		$conn    = $s['connections'][ $default ] ?? reset( $s['connections'] );
		$p       = is_array( $conn['provider_settings'] ?? null ) ? $conn['provider_settings'] : array();
		$email   = sanitize_email( (string) ( $p['sender_email'] ?? '' ) );
		$ready   = '' !== $email && ( 'outlook' !== ( $p['provider'] ?? '' ) || ! empty( $p['access_token'] ) || ! empty( $p['refresh_token'] ) );
		return array( 'ready' => $ready, 'provider' => (string) ( $p['provider'] ?? '' ), 'email' => $email );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pchq_mail_log';
	}

	public static function ready_table(): bool {
		if ( self::DB_VER === get_option( self::DB_OPTION ) ) { return true; }
		try {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$t = self::table();
			dbDelta( "CREATE TABLE {$t} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 site_id bigint(20) unsigned NOT NULL DEFAULT 0,
 recipients smallint(5) unsigned NOT NULL DEFAULT 0,
 first_to varchar(190) NOT NULL DEFAULT '',
 subject varchar(190) NOT NULL DEFAULT '',
 status varchar(16) NOT NULL DEFAULT '',
 error varchar(255) NOT NULL DEFAULT '',
 created_at bigint(20) unsigned NOT NULL,
 PRIMARY KEY  (id),
 KEY site_time (site_id,created_at),
 KEY created (created_at)
) {$wpdb->get_charset_collate()};" );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { return false; }
			update_option( self::DB_OPTION, self::DB_VER, false );
			return true;
		} catch ( \Throwable $e ) { return false; }
	}

	public static function routes(): void {
		register_rest_route( Register::NS, '/mail', array(
			'methods'             => 'POST',
			'callback'            => array( self::class, 'handle' ),
			'permission_callback' => array( self::class, 'verify' ),
		) );
	}

	/** 店铺签名。成功时把店铺 ID 记在请求上。 */
	public static function verify( \WP_REST_Request $request ) {
		$uuid = strtolower( trim( (string) $request->get_header( 'x_pc_hq_site' ) ) );
		$time = (int) $request->get_header( 'x_pc_hq_time' );
		$sign = strtolower( (string) $request->get_header( 'x_pc_hq_sign' ) );
		if ( ! preg_match( '/^[a-z0-9-]{8,64}$/', $uuid ) || 64 !== strlen( $sign ) ) {
			return new \WP_Error( 'pchq_mail_auth', 'Store identity missing.', array( 'status' => 401 ) );
		}
		if ( abs( time() - $time ) > self::SKEW ) {
			return new \WP_Error( 'pchq_mail_time', 'The request time is too far from the master admin time.', array( 'status' => 401 ) );
		}
		$site = null;
		foreach ( Sites::all() as $s ) { if ( '' !== (string) $s['uuid'] && hash_equals( strtolower( (string) $s['uuid'] ), $uuid ) ) { $site = $s; break; } }
		if ( ! $site ) {
			return new \WP_Error( 'pchq_mail_site', 'Unknown store.', array( 'status' => 401 ) );
		}
		$params = $request->get_query_params();
		unset( $params['rest_route'] );
		ksort( $params );
		$query  = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		$data   = strtoupper( $request->get_method() ) . "\n" . $request->get_route() . "\n" . $query . "\n" . $time . "\n" . hash( 'sha256', (string) $request->get_body() );
		$key    = Sites::key( (int) $site['id'] );
		if ( 64 !== strlen( $key ) || ! hash_equals( hash_hmac( 'sha256', $data, $key ), $sign ) ) {
			return new \WP_Error( 'pchq_mail_sign', 'The signature is not valid.', array( 'status' => 401 ) );
		}
		$seen = 'pchq_mail_' . substr( $sign, 0, 40 );
		if ( get_transient( $seen ) ) {
			return new \WP_Error( 'pchq_mail_replay', 'This request was already used.', array( 'status' => 401 ) );
		}
		set_transient( $seen, 1, self::SKEW * 2 );
		$request->set_param( '_pchq_site', (int) $site['id'] );
		return true;
	}

	public static function handle( \WP_REST_Request $request ) {
		$site_id = (int) $request->get_param( '_pchq_site' );
		$site    = Sites::get( $site_id );
		$p       = $request->get_json_params();
		if ( ! is_array( $p ) || strlen( (string) $request->get_body() ) > 6 * MB_IN_BYTES ) {
			return new \WP_Error( 'pchq_mail_bad', 'Invalid mail.', array( 'status' => 400 ) );
		}
		$to = array_values( array_filter( array_map( static fn( $a ) => sanitize_email( (string) $a ), (array) ( $p['to'] ?? array() ) ), 'is_email' ) );
		$subject = mb_substr( wp_strip_all_tags( (string) ( $p['subject'] ?? '' ) ), 0, 300 );
		$message = (string) ( $p['message'] ?? '' );
		if ( ! $to || count( $to ) > 20 || '' === $message || strlen( $message ) > 4 * MB_IN_BYTES ) {
			return new \WP_Error( 'pchq_mail_bad', 'Invalid recipients or message.', array( 'status' => 400 ) );
		}
		$s = self::settings();
		$sender = self::sender();
		if ( ! $s['enabled'] || ! $sender['ready'] ) {
			self::log( $site_id, $to, $subject, 'refused', ! $s['enabled'] ? '邮件中心已关闭' : '总后台还没有连接发信邮箱' );
			return new \WP_Error( 'pchq_mail_off', 'The mail center is not available.', array( 'status' => 503 ) );
		}
		if ( self::count_since( time() - DAY_IN_SECONDS ) >= $s['daily_cap'] ) {
			self::log( $site_id, $to, $subject, 'refused', '已达到每日发送上限' );
			return new \WP_Error( 'pchq_mail_cap', 'The daily sending limit has been reached.', array( 'status' => 429 ) );
		}
		if ( self::count_since( time() - HOUR_IN_SECONDS, $site_id ) >= $s['site_hour'] ) {
			self::log( $site_id, $to, $subject, 'refused', '这家店铺已达到每小时发送上限' );
			return new \WP_Error( 'pchq_mail_rate', 'This store is sending too fast.', array( 'status' => 429 ) );
		}

		// 只保留安全的头：内容类型、回复地址、抄送。发件人统一用总后台的发信邮箱 + 店铺名称。
		$headers = array();
		foreach ( array_slice( (array) ( $p['headers'] ?? array() ), 0, 30 ) as $line ) {
			$line = trim( str_replace( array( "\r", "\n" ), '', (string) $line ) );
			if ( preg_match( '/^(content-type|reply-to|cc|bcc)\s*:/i', $line ) && strlen( $line ) < 1000 ) { $headers[] = $line; }
		}
		$name = trim( str_replace( array( '"', "\r", "\n", '<', '>' ), '', (string) ( $site['name'] ?? '' ) ) );
		$headers[] = 'From: ' . ( '' !== $name ? '"' . $name . '" ' : '' ) . '<' . $sender['email'] . '>';

		$files = array();
		$dir   = trailingslashit( get_temp_dir() ) . 'pchq-mail-' . wp_generate_password( 12, false );
		try {
			foreach ( array_slice( (array) ( $p['attachments'] ?? array() ), 0, 3 ) as $att ) {
				$bin = base64_decode( (string) ( $att['data'] ?? '' ), true );
				$fn  = sanitize_file_name( (string) ( $att['name'] ?? 'attachment' ) );
				if ( false === $bin || strlen( $bin ) > MB_IN_BYTES || '' === $fn ) { continue; }
				if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
				$path = $dir . '/' . $fn;
				if ( false !== file_put_contents( $path, $bin ) ) { $files[] = $path; }
			}
			$error = '';
			$catch = static function ( $e ) use ( &$error ) { if ( $e instanceof \WP_Error ) { $error = $e->get_error_message(); } };
			add_action( 'wp_mail_failed', $catch );
			$ok = wp_mail( $to, $subject, $message, $headers, $files );
			remove_action( 'wp_mail_failed', $catch );
		} finally {
			foreach ( $files as $f ) { @unlink( $f ); }
			if ( is_dir( $dir ) ) { @rmdir( $dir ); }
		}
		self::log( $site_id, $to, $subject, $ok ? 'sent' : 'failed', $ok ? '' : ( '' !== $error ? $error : '发送失败' ) );
		if ( ! $ok ) {
			return new \WP_Error( 'pchq_mail_failed', 'The mail could not be sent.', array( 'status' => 502 ) );
		}
		return array( 'sent' => true );
	}

	public static function count_since( int $since, int $site_id = 0 ): int {
		if ( ! self::ready_table() ) { return 0; }
		global $wpdb;
		$t = self::table();
		return $site_id
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status='sent' AND site_id=%d AND created_at>=%d", $site_id, $since ) )
			: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status='sent' AND created_at>=%d", $since ) );
	}

	public static function log( int $site_id, array $to, string $subject, string $status, string $error = '' ): void {
		try {
			if ( ! self::ready_table() ) { return; }
			global $wpdb;
			$first = (string) ( $to[0] ?? '' );
			$first = preg_replace( '/^(.{2}).*(@.*)$/', '$1***$2', $first );
			$wpdb->insert( self::table(), array(
				'site_id' => $site_id, 'recipients' => count( $to ), 'first_to' => mb_substr( (string) $first, 0, 190 ),
				'subject' => mb_substr( $subject, 0, 190 ), 'status' => $status, 'error' => mb_substr( $error, 0, 255 ), 'created_at' => time(),
			) );
			if ( 0 === wp_rand( 0, 50 ) ) {
				$t   = self::table();
				$cut = (int) $wpdb->get_var( "SELECT id FROM {$t} ORDER BY id DESC LIMIT 1 OFFSET " . self::KEEP );
				if ( $cut ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE id<=%d", $cut ) ); }
			}
		} catch ( \Throwable $e ) { /* 记录失败不影响发信。 */ }
	}

	/** @return array<int,array> */
	public static function recent( int $limit = 50 ): array {
		if ( ! self::ready_table() ) { return array(); }
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
	}

	/** @return array<int,array{sent:int,failed:int,refused:int}> 最近 $days 天每家店的数量 */
	public static function per_site( int $days ): array {
		if ( ! self::ready_table() ) { return array(); }
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT site_id, status, COUNT(*) n FROM ' . self::table() . ' WHERE created_at>=%d GROUP BY site_id, status', time() - $days * DAY_IN_SECONDS ), ARRAY_A ) as $r ) {
			$out[ (int) $r['site_id'] ][ (string) $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}
}
