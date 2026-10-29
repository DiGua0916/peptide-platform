<?php
/**
 * 调用店铺接口（peptide-core 的 /peptide/v1/hq/*）。每次请求签名：
 * HMAC-SHA256( 密钥, 方法 \n 路由 \n 排好序的查询参数 \n 时间 \n 请求体的 SHA-256 )，放在 X-PC-HQ-Time / X-PC-HQ-Sign。
 * 用 ?rest_route= 访问，店铺没开固定链接也能用。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Client {

	public const TIMEOUT = 20;

	public static function canonical_query( array $params ): string {
		unset( $params['rest_route'] );
		ksort( $params );
		return http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * @return array|\WP_Error 店铺返回的 JSON
	 */
	public static function request_raw( string $site_url, string $key, string $method, string $route, array $query = array(), ?array $body = null ) {
		$time    = time();
		$payload = null === $body ? '' : (string) wp_json_encode( (object) $body );
		$sign    = hash_hmac( 'sha256', strtoupper( $method ) . "\n" . $route . "\n" . self::canonical_query( $query ) . "\n" . $time . "\n" . hash( 'sha256', $payload ), $key );
		$url     = add_query_arg( array_map( 'rawurlencode', array_merge( array( 'rest_route' => $route ), $query ) ), $site_url );
		$res     = wp_remote_request(
			$url,
			array(
				'method'      => strtoupper( $method ),
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'X-PC-HQ-Time' => (string) $time,
					'X-PC-HQ-Sign' => $sign,
					'X-PC-HQ-Url'  => home_url( '/' ),
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => '' === $payload ? null : $payload,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'pchq_offline', $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 401 === $code || 403 === $code ) {
			return new \WP_Error( 'pchq_denied', __( 'The store refused the connection key. Paste the current connection code from the store.', 'peptide-hq' ) );
		}
		if ( $code >= 400 || ! is_array( $data ) ) {
			/* translators: %d: HTTP status */
			$msg = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : sprintf( __( 'The store answered with HTTP status %d. Is peptide-core up to date there?', 'peptide-hq' ), $code );
			return new \WP_Error( ( $code >= 500 || 0 === $code ) ? 'pchq_offline' : 'pchq_error', $msg );
		}
		return $data;
	}

	/** @return array|\WP_Error */
	public static function request( int $site_id, string $method, string $route, array $query = array(), ?array $body = null ) {
		$site = Sites::get( $site_id );
		if ( ! $site ) {
			return new \WP_Error( 'pchq_missing', __( 'Store not found.', 'peptide-hq' ) );
		}
		return self::request_raw( $site['url'], Sites::key( $site_id ), $method, $route, $query, $body );
	}

	public static function status( int $site_id ) {
		return self::request( $site_id, 'GET', '/peptide/v1/hq/status' );
	}

	public static function summary( int $site_id, int $days ) {
		return self::request( $site_id, 'GET', '/peptide/v1/hq/summary', array( 'days' => (string) $days ) );
	}

	/** 一次性登录链接；$to 是店铺“平台工具”页面的键（见 Platform::tools()），空时进后台首页。 */
	public static function login( int $site_id, string $to = '' ) {
		return self::request( $site_id, 'POST', '/peptide/v1/hq/login', array(), '' !== $to ? array( 'to' => $to ) : array() );
	}

	/**
	 * 下发设置：server_ip、blog_public（是否允许搜索引擎收录）、tracking（enabled / retention_days / gpc）。只传要改的项。
	 *
	 * @return array|\WP_Error 店铺现在的设置
	 */
	public static function config( int $site_id, array $config ) {
		return self::request( $site_id, 'POST', '/peptide/v1/hq/config', array(), $config );
	}

	public static function staff_list( int $site_id ) {
		return self::request( $site_id, 'GET', '/peptide/v1/hq/staff' );
	}

	public static function staff_save( int $site_id, array $account ) {
		return self::request( $site_id, 'POST', '/peptide/v1/hq/staff', array(), $account );
	}

	public static function staff_delete( int $site_id, int $user_id ) {
		return self::request( $site_id, 'POST', '/peptide/v1/hq/staff/delete', array(), array( 'id' => $user_id ) );
	}
}
