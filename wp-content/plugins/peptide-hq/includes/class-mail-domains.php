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
		$list = Cdn::request( 'GET', '/zones/' . rawurlencode( $zone_id ) . '/email/sending/subdomains?per_page=50' );
		if ( ! is_wp_error( $list ) ) {
			foreach ( (array) $list as $row ) {
				if ( strtolower( (string) ( $row['name'] ?? '' ) ) === $domain ) {
					return $row;
				}
			}
		} elseif ( 403 === (int) ( $list->get_error_data()['status'] ?? 0 ) || 10000 === (int) ( $list->get_error_data()['cf_code'] ?? 0 ) ) {
			return new \WP_Error( 'pchq_mail_domain_token', 'Cloudflare 令牌缺少 Email Sending 权限，邮件域名没有自动接入。' );
		}
		$created = Cdn::request( 'POST', '/zones/' . rawurlencode( $zone_id ) . '/email/sending/subdomains', array( 'name' => $domain ) );
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
		$expected = Cdn::request( 'GET', '/zones/' . rawurlencode( $zone_id ) . '/email/sending/subdomains/' . rawurlencode( $sub_id ) . '/dns' );
		if ( is_wp_error( $expected ) ) {
			return new \WP_Error( 'pchq_mail_domain_dns', '读不到邮件发送需要的 DNS 记录：' . $expected->get_error_message() );
		}
		$records = is_array( $expected['records'] ?? null ) ? $expected['records'] : ( is_array( $expected ) && isset( $expected[0]['type'] ) ? $expected : array() );
		$existing = Cdn::request( 'GET', '/zones/' . rawurlencode( $zone_id ) . '/dns_records?per_page=200' );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		$have = array();
		foreach ( (array) $existing as $r ) {
			$have[] = strtolower( (string) $r['type'] . '|' . (string) $r['name'] . '|' . trim( (string) $r['content'], '"' ) );
		}
		foreach ( $records as $rec ) {
			$type    = strtoupper( (string) ( $rec['type'] ?? '' ) );
			$name    = rtrim( (string) ( $rec['name'] ?? '' ), '.' );
			$content = trim( (string) ( $rec['content'] ?? '' ), '"' );
			if ( '' === $type || '' === $name || '' === $content ) {
				continue;
			}
			if ( in_array( strtolower( $type . '|' . $name . '|' . $content ), $have, true ) ) {
				continue;
			}
			$body = array( 'type' => $type, 'name' => $name, 'content' => 'MX' === $type ? rtrim( $content, '.' ) : $content, 'ttl' => 1, 'comment' => 'VialCove mail sending (auto)' );
			if ( 'MX' === $type ) {
				$body['priority'] = (int) ( $rec['priority'] ?? 10 );
			}
			$done = Cdn::request( 'POST', '/zones/' . rawurlencode( $zone_id ) . '/dns_records', $body );
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

	/** 给所有 CDN 已完成、但还没有发件地址的店铺域名补上（定时调用）。 */
	public static function backfill(): void {
		foreach ( Cdn::domains() as $domain => $row ) {
			if ( 'done' !== ( $row['stage'] ?? '' ) || '' === (string) ( $row['zone_id'] ?? '' ) || self::has_connection( (string) $domain ) ) {
				continue;
			}
			if ( ! empty( $row['mail_error_at'] ) && time() - (int) $row['mail_error_at'] < 6 * HOUR_IN_SECONDS ) {
				continue;
			}
			self::apply( (string) $domain, (string) $row['zone_id'] );
		}
	}

	/** 执行并把结果写进域名状态（mail_ok / mail_error）。 */
	public static function apply( string $domain, string $zone_id ): void {
		$res = self::ensure( $domain, $zone_id );
		$ref = new \ReflectionMethod( Cdn::class, 'set_domain' );
		$ref->setAccessible( true );
		if ( is_wp_error( $res ) ) {
			$ref->invoke( null, $domain, array( 'mail_error' => $res->get_error_message(), 'mail_error_at' => time() ) );
		} else {
			$ref->invoke( null, $domain, array( 'mail_ok' => time(), 'mail_error' => '', 'mail_error_at' => 0 ) );
		}
	}
}
