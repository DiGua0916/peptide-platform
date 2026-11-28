<?php
/**
 * 店铺域名的邮件发送（2026-11-09）：开店把域名接进 Cloudflare 邮件发送，并在 FluentSMTP 建好 orders@域名 的连接。
 *
 * - Cloudflare：POST /zones/{zone}/email/sending/subdomains {name: 域名}，再按 GET …/dns 给出的记录建 MX / TXT（cf-bounce、DKIM、_dmarc）。
 *   需要 CDN 页那把令牌有 Email Sending 读写 + DNS 编辑权限。
 * - FluentSMTP：复制主店的 Cloudflare 连接（同一把发信令牌、同一账户），只换发件地址和显示名；加进 mappings，邮件中心按发件地址路由。
 * - CDN 配置走到“done”时自动做；定时 tick 也会给已完成但还没有发信连接的域名补上。失败只记在域名状态里，不影响开店。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Mail_Domains {

	/** 这个域名在 FluentSMTP 里有没有自己的发件地址。 */
	public static function has_connection( string $domain ): bool {
		$fm = get_option( 'fluentmail-settings', array() );
		return is_array( $fm ) && isset( $fm['mappings'][ 'orders@' . strtolower( $domain ) ] );
	}

	/**
	 * 一次做完：接入发送域名、建 DNS、建连接。@return true|\WP_Error
	 */
	public static function ensure( string $domain, string $zone_id ) {
		$domain = strtolower( trim( $domain ) );
		$sub    = self::ensure_subdomain( $domain, $zone_id );
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		$dns = self::ensure_dns( $domain, $zone_id, (string) ( $sub['id'] ?? $sub['tag'] ?? '' ) );
		if ( is_wp_error( $dns ) ) {
			return $dns;
		}
		return self::ensure_connection( $domain );
	}

	/** @return array|\WP_Error 发送域名对象 */
	private static function ensure_subdomain( string $domain, string $zone_id ) {
		$list = Cdn::request( 'GET', '/zones/' . rawurlencode( $zone_id ) . '/email/sending/subdomains?per_page=50', null, $domain );
		if ( ! is_wp_error( $list ) ) {
			foreach ( (array) $list as $row ) {
				if ( strtolower( (string) ( $row['name'] ?? '' ) ) === $domain ) {
					return $row;
				}
			}
		} elseif ( 403 === (int) ( $list->get_error_data()['status'] ?? 0 ) || 10000 === (int) ( $list->get_error_data()['cf_code'] ?? 0 ) ) {
			return new \WP_Error( 'pchq_mail_domain_token', 'Cloudflare 令牌缺少 Email Sending 权限，邮件域名没有自动接入。' );
		}
		$created = Cdn::request( 'POST', '/zones/' . rawurlencode( $zone_id ) . '/email/sending/subdomains', array( 'name' => $domain ), $domain );
		if ( is_wp_error( $created ) ) {
			return new \WP_Error( 'pchq_mail_domain', '邮件发送域名没接上：' . $created->get_error_message() );
		}
		return $created;
	}

	/** 按 Cloudflare 给出的期望记录补齐 MX / TXT。@return true|\WP_Error */
	private static function ensure_dns( string $domain, string $zone_id, string $sub_id ) {
		if ( '' === $sub_id ) {
			return true;
		}
		$expected = Cdn::request( 'GET', '/zones/' . rawurlencode( $zone_id ) . '/email/sending/subdomains/' . rawurlencode( $sub_id ) . '/dns', null, $domain );
		if ( is_wp_error( $expected ) ) {
			return new \WP_Error( 'pchq_mail_domain_dns', '读不到邮件发送需要的 DNS 记录：' . $expected->get_error_message() );
		}
		$records = is_array( $expected['records'] ?? null ) ? $expected['records'] : ( is_array( $expected ) && isset( $expected[0]['type'] ) ? $expected : array() );
		$existing = Cdn::request( 'GET', '/zones/' . rawurlencode( $zone_id ) . '/dns_records?per_page=200', null, $domain );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		$have = array();
		foreach ( (array) $existing as $r ) {
			$have[] = strtolower( (string) $r['type'] . '|' . rtrim( (string) $r['name'], '.' ) . '|' . rtrim( trim( (string) $r['content'], '"' ), '.' ) ); // Cloudflare 存 MX 时不带末尾的点
		}
		foreach ( $records as $rec ) {
			$type    = strtoupper( (string) ( $rec['type'] ?? '' ) );
			$name    = rtrim( (string) ( $rec['name'] ?? '' ), '.' );
			$content = trim( (string) ( $rec['content'] ?? '' ), '"' );
			if ( '' === $type || '' === $name || '' === $content ) {
				continue;
			}
			if ( in_array( strtolower( $type . '|' . $name . '|' . rtrim( $content, '.' ) ), $have, true ) ) {
				continue;
			}
			$body = array( 'type' => $type, 'name' => $name, 'content' => 'MX' === $type ? rtrim( $content, '.' ) : $content, 'ttl' => 1, 'comment' => 'VialCove mail sending (auto)' );
			if ( 'MX' === $type ) {
				$body['priority'] = (int) ( $rec['priority'] ?? 10 );
			}
			$done = Cdn::request( 'POST', '/zones/' . rawurlencode( $zone_id ) . '/dns_records', $body, $domain );
			if ( is_wp_error( $done ) && false === stripos( $done->get_error_message(), 'already exists' ) ) {
				return new \WP_Error( 'pchq_mail_domain_dns', '邮件 DNS 记录 ' . $name . ' 没建上：' . $done->get_error_message() );
			}
		}
		return true;
	}

	/** 在 FluentSMTP 里给这个域名建 orders@ 连接（复制主店的 Cloudflare 连接）。@return true|\WP_Error */
	public static function ensure_connection( string $domain ) {
		$email = 'orders@' . $domain;
		$fm    = get_option( 'fluentmail-settings', array() );
		if ( ! is_array( $fm ) || empty( $fm['connections'] ) ) {
			return new \WP_Error( 'pchq_mail_domain_fm', '总后台还没有 Cloudflare 发信连接，不能自动给新店建发件地址。' );
		}
		if ( isset( $fm['mappings'][ $email ] ) ) {
			return true;
		}
		$base = null;
		$default = (string) ( $fm['misc']['default_connection'] ?? '' );
		if ( '' !== $default && 'cloudflare' === ( $fm['connections'][ $default ]['provider_settings']['provider'] ?? '' ) ) {
			$base = $fm['connections'][ $default ];
		} else {
			foreach ( $fm['connections'] as $c ) {
				if ( 'cloudflare' === ( $c['provider_settings']['provider'] ?? '' ) ) {
					$base = $c;
					break;
				}
			}
		}
		if ( ! $base ) {
			return new \WP_Error( 'pchq_mail_domain_fm', '总后台没有 Cloudflare 发信连接可复制。' );
		}
		$other = self::other_account( $domain, (array) ( $base['provider_settings'] ?? array() ) );
		if ( '' !== $other ) {
			return new \WP_Error( 'pchq_mail_domain_account', '这个域名在 Cloudflare 账号「' . $other . '」里，主店发信连接属于另一个账号，Cloudflare 不让它用这个域名发信（2026-11-28 店铺邮件全部被拒就是这个原因）。这家店的邮件继续用总后台默认发信邮箱发出' );
		}
		$brand = Mail::brand_name( $domain, $domain );
		$c     = $base;
		$c['provider_settings']['sender_email'] = $email;
		$c['provider_settings']['sender_name']  = $brand;
		$c['title'] = $brand;
		$key   = md5( $email );
		$fm['connections'][ $key ] = $c;
		$fm['mappings'][ $email ]  = $key;
		update_option( 'fluentmail-settings', $fm );
		return true;
	}

	/**
	 * 域名归 CDN 页的某个 Cloudflare 账号，而这个账号不是主店发信连接所在的账号时，返回那个账号的名字；否则空串。
	 *
	 * Cloudflare 只让一个账号用自己名下的域名发信。复制来的连接用的是主店账号的令牌，
	 * 拿去发投手账号里的域名会被拒（email.sending.error.email.invalid），所以这种域名不建 orders@ 连接。
	 * 没记账号的域名（单账号时代）照旧。
	 */
	public static function other_account( string $domain, array $base_settings ): string {
		$acct = (string) ( Cdn::domains()[ $domain ]['account'] ?? '' );
		if ( '' === $acct || ! method_exists( Cdn::class, 'accounts' ) ) {
			return '';
		}
		$row     = Cdn::accounts()[ $acct ] ?? array();
		$their   = (string) ( $row['account_id'] ?? '' );
		$ours    = (string) ( $base_settings['account_id'] ?? '' );
		if ( '' !== $their && '' !== $ours && $their === $ours ) {
			return '';
		}
		return '' !== (string) ( $row['label'] ?? '' ) ? (string) $row['label'] : $acct;
	}

	public static function register_hooks(): void {
		add_action( 'pchq_site_moved', array( self::class, 'on_site_moved' ), 10, 2 );
		add_action( self::MOVED_EVENT, array( self::class, 'run_moved' ), 10, 1 );
	}

	public const MOVED_EVENT = 'pchq_mail_domain_moved';

	/** 换域名时 CDN 行可能没有记 zone 编号（11-17 那三家就是）：按域名问一次 Cloudflare 并记下。@return string|\WP_Error */
	public static function zone_id( string $domain ) {
		$row = Cdn::domains()[ $domain ] ?? array();
		$zid = (string) ( $row['zone_id'] ?? '' );
		if ( '' !== $zid ) {
			return $zid;
		}
		$found = Cdn::request( 'GET', '/zones?name=' . rawurlencode( $domain ), null, $domain );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$zid = (string) ( $found[0]['id'] ?? '' );
		if ( '' === $zid ) {
			return new \WP_Error( 'pchq_mail_domain_zone', $domain . ' 不在任何已保存的 Cloudflare 账号里，没法接邮件发送。' );
		}
		if ( $row ) {
			self::set_domain( $domain, array( 'zone_id' => $zid ) );
		}
		return $zid;
	}

	/** 给所有 CDN 已完成、但还没有发件地址的店铺域名补上（定时调用）。没有记 zone 编号的也补。 */
	public static function backfill(): void {
		foreach ( Cdn::domains() as $domain => $row ) {
			if ( 'done' !== ( $row['stage'] ?? '' ) || self::has_connection( (string) $domain ) ) {
				continue;
			}
			if ( ! empty( $row['mail_error_at'] ) && time() - (int) $row['mail_error_at'] < 6 * HOUR_IN_SECONDS ) {
				continue;
			}
			self::apply( (string) $domain, (string) ( $row['zone_id'] ?? '' ) );
		}
	}

	/** 执行并把结果写进域名状态（mail_ok / mail_error）。$zone_id 为空时自己去查。 */
	public static function apply( string $domain, string $zone_id = '' ): void {
		$zone = '' !== $zone_id ? $zone_id : self::zone_id( $domain );
		$res  = is_wp_error( $zone ) ? $zone : self::ensure( $domain, $zone );
		if ( is_wp_error( $res ) ) {
			self::set_domain( $domain, array( 'mail_error' => $res->get_error_message(), 'mail_error_at' => time() ) );
		} else {
			self::set_domain( $domain, array( 'mail_ok' => time(), 'mail_error' => '', 'mail_error_at' => 0 ) );
		}
	}

	private static function set_domain( string $domain, array $fields ): void {
		if ( ! isset( Cdn::domains()[ $domain ] ) ) {
			return; // 只更新 CDN 页已有的域名行，不凭空加行。
		}
		$ref = new \ReflectionMethod( Cdn::class, 'set_domain' );
		$ref->setAccessible( true );
		$ref->invoke( null, $domain, $fields );
	}

	// ---------- 店铺换了顾客域名 ----------

	/**
	 * Sites::register 认出店铺换了顾客域名（'moved'）时调用。注册请求不能等 Cloudflare，
	 * 所以放到稍后的单次任务里做；同一域名重复触发只排一次。
	 */
	public static function on_site_moved( int $site_id, array $before ): void {
		$site = Sites::get( $site_id );
		if ( ! $site ) {
			return;
		}
		$new = Domain_Verify::bare( (string) wp_parse_url( Site_Urls::public_url( $site ), PHP_URL_HOST ) );
		$old = Domain_Verify::bare( (string) wp_parse_url( Site_Urls::public_url( $before ), PHP_URL_HOST ) );
		if ( '' === $new || $new === $old ) {
			return;
		}
		if ( ! wp_next_scheduled( self::MOVED_EVENT, array( $site_id ) ) ) {
			wp_schedule_single_event( time() + 30, self::MOVED_EVENT, array( $site_id ) );
		}
	}

	/** 单次任务：给店铺现在的顾客域名准备 orders@ 发件地址，并把检查结果记下来给邮件中心看。 */
	public static function run_moved( int $site_id ): void {
		$site = Sites::get( (int) $site_id );
		if ( ! $site ) {
			return;
		}
		$domain = Domain_Verify::bare( (string) wp_parse_url( Site_Urls::public_url( $site ), PHP_URL_HOST ) );
		if ( '' === $domain ) {
			return;
		}
		if ( ! self::has_connection( $domain ) && 'done' === ( Cdn::domains()[ $domain ]['stage'] ?? '' ) ) {
			self::apply( $domain );
		}
		self::remember_check( $domain, self::check( $domain, true ) );
	}

	// ---------- 发信域名检查 ----------

	/** 最近一次在线检查的结果，邮件中心页面直接读，不在页面里发网络请求。 */
	public const CHECKS = 'pchq_mail_domain_checks';

	public static function remember_check( string $domain, array $check ): void {
		$all = get_option( self::CHECKS, array() );
		$all = is_array( $all ) ? $all : array();
		$all[ $domain ] = $check + array( 'at' => time() );
		update_option( self::CHECKS, $all, false );
	}

	public static function last_check( string $domain ): ?array {
		$all = get_option( self::CHECKS, array() );
		return is_array( $all[ $domain ] ?? null ) ? $all[ $domain ] : null;
	}

	/**
	 * 这个域名能不能用自己的地址发信。
	 *
	 * ok=true：FluentSMTP 有 orders@域名 的连接，且（在线时）Cloudflare 要求的 SPF / DKIM / 退信记录都在 DNS 里。
	 * ok=false：会退回总后台默认发信邮箱，或者发出去可能被拒；message 说明原因和怎么补。
	 * ok=null：只做了离线检查，DNS 没核对。
	 *
	 * @return array{ok:?bool,sender:bool,missing:array<int,string>,message:string}
	 */
	public static function check( string $domain, bool $online ): array {
		$sender = self::has_connection( $domain );
		$row    = Cdn::domains()[ $domain ] ?? array();
		if ( ! $sender ) {
			$why = '' !== (string) ( $row['mail_error'] ?? '' ) ? '上次自动接入失败：' . $row['mail_error'] : ( $row ? '还没有自动接入（定时任务会补）' : '这个域名不在 CDN 页的域名表里，不会自动接入' );
			return array( 'ok' => false, 'sender' => false, 'missing' => array(), 'message' => 'orders@' . $domain . ' 还不能发信，这家店的邮件会用总后台默认发信邮箱发出。' . $why . '。' );
		}
		$fm      = get_option( 'fluentmail-settings', array() );
		$own     = (array) ( $fm['connections'][ $fm['mappings'][ 'orders@' . $domain ] ?? '' ]['provider_settings'] ?? array() );
		$other   = self::other_account( $domain, $own );
		if ( '' !== $other ) {
			return array( 'ok' => false, 'sender' => true, 'missing' => array(), 'message' => 'orders@' . $domain . ' 的发信连接用的是主店的 Cloudflare 账号，但域名在账号「' . $other . '」里，Cloudflare 会拒发。请在 FluentSMTP 删掉这条连接，邮件会改用总后台默认发信邮箱。' );
		}
		if ( ! $online ) {
			return array( 'ok' => null, 'sender' => true, 'missing' => array(), 'message' => 'orders@' . $domain . ' 已配好；DNS 认证没有在线核对。' );
		}
		$missing = self::missing_records( $domain );
		if ( is_wp_error( $missing ) ) {
			return array( 'ok' => null, 'sender' => true, 'missing' => array(), 'message' => 'orders@' . $domain . ' 已配好，但核对 DNS 认证时出错：' . $missing->get_error_message() );
		}
		if ( $missing ) {
			return array( 'ok' => false, 'sender' => true, 'missing' => $missing, 'message' => $domain . ' 的发信认证记录（SPF / DKIM / 退信）没齐，邮件可能被收件方拒收：缺 ' . implode( '、', $missing ) . '。' );
		}
		return array( 'ok' => true, 'sender' => true, 'missing' => array(), 'message' => 'orders@' . $domain . ' 可以发信，SPF / DKIM 记录齐全。' );
	}

	/**
	 * Cloudflare 邮件发送要求的记录里，DNS 区域里还没有的那些（类型 名称）。
	 *
	 * @return array<int,string>|\WP_Error
	 */
	public static function missing_records( string $domain ) {
		$zone = self::zone_id( $domain );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}
		$list = Cdn::request( 'GET', '/zones/' . rawurlencode( $zone ) . '/email/sending/subdomains?per_page=50', null, $domain );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$sub = null;
		foreach ( (array) $list as $row ) {
			if ( strtolower( (string) ( $row['name'] ?? '' ) ) === $domain ) {
				$sub = $row;
				break;
			}
		}
		if ( ! $sub ) {
			return array( 'Cloudflare 邮件发送域名（没接入）' );
		}
		$expected = Cdn::request( 'GET', '/zones/' . rawurlencode( $zone ) . '/email/sending/subdomains/' . rawurlencode( (string) ( $sub['id'] ?? $sub['tag'] ?? '' ) ) . '/dns', null, $domain );
		if ( is_wp_error( $expected ) ) {
			return $expected;
		}
		$records  = is_array( $expected['records'] ?? null ) ? $expected['records'] : ( is_array( $expected ) && isset( $expected[0]['type'] ) ? $expected : array() );
		$existing = Cdn::request( 'GET', '/zones/' . rawurlencode( $zone ) . '/dns_records?per_page=200', null, $domain );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		return self::diff_records( $records, (array) $existing );
	}

	/** 纯函数：期望记录里哪些不在现有记录中。@return array<int,string> */
	public static function diff_records( array $expected, array $existing ): array {
		$have = array();
		foreach ( $existing as $r ) {
			$have[] = strtolower( (string) ( $r['type'] ?? '' ) . '|' . rtrim( (string) ( $r['name'] ?? '' ), '.' ) . '|' . rtrim( trim( (string) ( $r['content'] ?? '' ), '"' ), '.' ) );
		}
		$missing = array();
		foreach ( $expected as $rec ) {
			$type    = strtoupper( (string) ( $rec['type'] ?? '' ) );
			$name    = rtrim( (string) ( $rec['name'] ?? '' ), '.' );
			$content = rtrim( trim( (string) ( $rec['content'] ?? '' ), '"' ), '.' );
			if ( '' === $type || '' === $name || '' === $content ) {
				continue;
			}
			if ( ! in_array( strtolower( $type . '|' . $name . '|' . $content ), $have, true ) ) {
				$missing[] = $type . ' ' . $name;
			}
		}
		return array_values( array_unique( $missing ) );
	}
}
