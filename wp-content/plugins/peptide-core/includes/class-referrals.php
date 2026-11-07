<?php
/**
 * 推广人归属（REF-01）：一个店，多个推广人，各发各的专属链接 ?ref=代号。
 *
 * 规则：谁先带来就算谁的（first touch）。
 * - 访客第一次带有效代号进站：在 init 写一个签名的第一方 cookie（pc_ref，365 天），并记一条“带来访客”记录；
 *   已有有效 cookie、或已登录且账号已有归属时，后来的代号一律忽略。
 * - 注册 / 登录：账号还没有归属时，继承 cookie 的归属；之后换设备登录仍算原推广人。
 * - 下单：订单记归属人，优先账号归属，其次 cookie。
 * 页面缓存把 ref 当作广告参数（不影响命中，渲染前从地址里去掉）；ref 不传给 Facebook。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Referrals {

	public const OPTION     = 'peptide_core_referral_owners';
	public const PARAM      = 'ref';
	public const COOKIE     = 'pc_ref';
	public const USER_META  = '_pc_ref_owner';
	public const USER_AT    = '_pc_ref_owner_at';
	public const ORDER_META = '_pc_ref_owner';
	public const DAYS       = 365;
	private const SCHEMA    = 'peptide_core_referral_schema';
	private const DB_VERSION = '1';
	private const BOTS      = '/bot|crawl|spider|slurp|facebookexternalhit|facebot|preview|headless|lighthouse|curl|wget|python|httpclient/i';

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'install' ), 5 );
		add_action( 'init', array( self::class, 'capture' ), 11 );
		add_action( 'user_register', array( self::class, 'bind_user' ), 20, 1 );
		add_action( 'wp_login', static function ( $login, $user ) { if ( $user instanceof \WP_User ) { self::bind_user( (int) $user->ID ); } }, 20, 2 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'stamp_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'stamp_order' ), 20, 1 );
	}

	// ---------- 推广人名单 ----------

	/** @return array<string,string> 代号 => 名字 */
	public static function owners(): array {
		$raw = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $code => $name ) {
			if ( is_string( $code ) && self::code_shape( $code ) && is_string( $name ) && '' !== trim( $name ) ) { $out[ $code ] = trim( $name ); }
		}
		return $out;
	}

	public static function code_shape( string $code ): bool {
		return 1 === preg_match( '/\A[a-z0-9][a-z0-9_-]{0,19}\z/', $code );
	}

	/** 只接受名单里的代号；大小写不敏感。 */
	public static function valid_code( $value ): ?string {
		if ( ! is_string( $value ) ) { return null; }
		$code = strtolower( trim( $value ) );
		return self::code_shape( $code ) && isset( self::owners()[ $code ] ) ? $code : null;
	}

	public static function name( string $code ): string {
		return self::owners()[ $code ] ?? $code;
	}

	public static function link( string $code, string $url = '' ): string {
		return add_query_arg( self::PARAM, rawurlencode( $code ), '' !== $url ? $url : home_url( '/' ) );
	}

	/**
	 * 保存名单。每行“代号|名字”。删掉的代号不再接收新访客，已有归属保留（报表里仍显示代号）。
	 *
	 * @return true|\WP_Error
	 */
	public static function save_owners( string $text ) {
		$out = array();
		foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
			if ( '' === trim( $line ) ) { continue; }
			$parts = explode( '|', $line, 2 );
			$code  = strtolower( trim( $parts[0] ) );
			$name  = isset( $parts[1] ) ? trim( wp_strip_all_tags( $parts[1] ) ) : '';
			if ( ! self::code_shape( $code ) || '' === $name || isset( $out[ $code ] ) ) {
				return new \WP_Error( 'format', '每行写“代号|名字”，代号用小写英文或数字（最多 20 个字符，不能重复），例如 a|张三。' );
			}
			$out[ $code ] = mb_substr( $name, 0, 40 );
		}
		update_option( self::OPTION, $out, true );
		return true;
	}

	// ---------- cookie ----------

	public static function sign( string $code, int $ts ): string {
		return substr( hash_hmac( 'sha256', $code . '|' . $ts, wp_salt( 'auth' ) ), 0, 20 );
	}

	/** @return array{code:string,ts:int}|null 签名正确、代号仍在名单里的 cookie。 */
	public static function from_cookie(): ?array {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		$parsed = self::parse( $raw );
		if ( ! $parsed || ! hash_equals( self::sign( $parsed['code'], $parsed['ts'] ), $parsed['sig'] ) || null === self::valid_code( $parsed['code'] ) ) { return null; }
		return array( 'code' => $parsed['code'], 'ts' => $parsed['ts'] );
	}

	/** 纯解析：code.ts.sig。 @return array{code:string,ts:int,sig:string}|null */
	public static function parse( string $raw ): ?array {
		if ( ! preg_match( '/\A([a-z0-9][a-z0-9_-]{0,19})\.([0-9]{9,11})\.([0-9a-f]{20})\z/', $raw, $m ) ) { return null; }
		return array( 'code' => $m[1], 'ts' => (int) $m[2], 'sig' => $m[3] );
	}

	/** init：带有效代号的第一次进站写 cookie。后台、接口请求、定时任务不处理。 */
	public static function capture(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读广告参数。
		if ( ! isset( $_GET[ self::PARAM ] ) || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || headers_sent() ) { return; }
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		self::land( $_GET[ self::PARAM ], $ua ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * 一次带代号的进站：代号有效、不是爬虫、这个浏览器和账号都还没有归属时，记为这个推广人带来的。
	 *
	 * @return bool 这次是否新记了归属。
	 */
	public static function land( $raw_code, string $ua ): bool {
		$code = self::valid_code( $raw_code );
		if ( null === $code || '' === $ua || preg_match( self::BOTS, $ua ) || null !== self::from_cookie() ) { return false; }
		$user = get_current_user_id();
		if ( $user && '' !== (string) get_user_meta( $user, self::USER_META, true ) ) { return false; }
		$ts    = time();
		$value = $code . '.' . $ts . '.' . self::sign( $code, $ts );
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $value, array( 'expires' => $ts + self::DAYS * DAY_IN_SECONDS, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		}
		$_COOKIE[ self::COOKIE ] = $value;
		self::record_touch( $code, $user );
		if ( $user ) { self::bind_user( $user ); }
		return true;
	}

	// ---------- 归属 ----------

	public static function bind_user( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || '' !== (string) get_user_meta( $user_id, self::USER_META, true ) ) { return; }
		$c = self::from_cookie();
		if ( ! $c ) { return; }
		update_user_meta( $user_id, self::USER_META, $c['code'] );
		update_user_meta( $user_id, self::USER_AT, time() );
	}

	/** 下单时（订单保存前）记归属人。已有归属的订单不改。 */
	public static function stamp_order( $order ): void {
		if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( self::ORDER_META ) ) { return; }
		$user = (int) $order->get_customer_id();
		$code = $user ? (string) get_user_meta( $user, self::USER_META, true ) : '';
		if ( '' === $code ) {
			$c = self::from_cookie();
			if ( $c ) {
				$code = $c['code'];
				if ( $user ) { self::bind_user( $user ); }
			}
		}
		if ( '' !== $code ) { $order->update_meta_data( self::ORDER_META, $code ); }
	}

	/** 管理员手动改客户归属；空字符串 = 取消归属。只影响以后的订单。 */
	public static function set_user_owner( int $user_id, string $code ): bool {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) { return false; }
		if ( '' === $code ) { delete_user_meta( $user_id, self::USER_META ); delete_user_meta( $user_id, self::USER_AT ); return true; }
		if ( null === self::valid_code( $code ) ) { return false; }
		update_user_meta( $user_id, self::USER_META, $code );
		update_user_meta( $user_id, self::USER_AT, time() );
		return true;
	}

	public static function set_order_owner( \WC_Order $order, string $code ): bool {
		if ( '' === $code ) { $order->delete_meta_data( self::ORDER_META ); $order->save(); return true; }
		if ( null === self::valid_code( $code ) ) { return false; }
		$order->update_meta_data( self::ORDER_META, $code );
		$order->save();
		return true;
	}

	// ---------- 带来访客记录 ----------

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_referral_touches';
	}

	public static function install(): void {
		if ( self::DB_VERSION === get_option( self::SCHEMA ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner varchar(20) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  path varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY owner_created (owner,created_at)
) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::SCHEMA, self::DB_VERSION, true );
	}

	private static function record_touch( string $code, int $user ): void {
		global $wpdb;
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		$wpdb->insert( self::table(), array( 'owner' => $code, 'user_id' => $user, 'path' => mb_substr( '' !== $path ? $path : '/', 0, 255 ), 'created_at' => current_time( 'mysql', true ) ), array( '%s', '%d', '%s', '%s' ) );
	}

	// ---------- 统计 ----------

	/**
	 * 每个推广人的业绩。$days = 0 表示全部时间。
	 *
	 * @return array<string,array{visitors:int,customers:int,orders:int,sales:float}>
	 */
	public static function stats( int $days = 30 ): array {
		global $wpdb;
		$since_ts  = $days > 0 ? time() - $days * DAY_IN_SECONDS : 0;
		$since_sql = gmdate( 'Y-m-d H:i:s', $since_ts );
		$codes     = array_keys( self::owners() );
		$out       = array();
		foreach ( $codes as $code ) { $out[ $code ] = array( 'visitors' => 0, 'customers' => 0, 'orders' => 0, 'sales' => 0.0 ); }

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT owner, COUNT(*) n FROM ' . self::table() . ' WHERE created_at >= %s GROUP BY owner', $since_sql ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $rows as $r ) { if ( isset( $out[ $r['owner'] ] ) ) { $out[ $r['owner'] ]['visitors'] = (int) $r['n']; } }

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_value owner, COUNT(*) n FROM {$wpdb->usermeta} m JOIN {$wpdb->usermeta} t ON t.user_id = m.user_id AND t.meta_key = %s WHERE m.meta_key = %s AND CAST(t.meta_value AS UNSIGNED) >= %d GROUP BY m.meta_value", self::USER_AT, self::USER_META, $since_ts ), ARRAY_A );
		foreach ( (array) $rows as $r ) { if ( isset( $out[ $r['owner'] ] ) ) { $out[ $r['owner'] ]['customers'] = (int) $r['n']; } }

		if ( function_exists( 'wc_get_orders' ) ) {
			$paid = array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() );
			foreach ( $codes as $code ) {
				$args = array( 'limit' => -1, 'status' => $paid, 'type' => 'shop_order', 'return' => 'objects', 'meta_query' => array( array( 'key' => self::ORDER_META, 'value' => $code ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				if ( $since_ts ) { $args['date_created'] = '>=' . $since_ts; }
				foreach ( wc_get_orders( $args ) as $order ) {
					$out[ $code ]['orders']++;
					$out[ $code ]['sales'] += (float) $order->get_total() - (float) $order->get_total_refunded();
				}
			}
		}
		return $out;
	}
}
