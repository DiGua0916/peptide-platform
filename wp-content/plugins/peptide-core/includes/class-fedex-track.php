<?php
/**
 * FedEx 官方追踪接口（Track API，店主 2026-11-11）。
 *
 * 店主在 developer.fedex.com 建项目、选 Track API，拿到 API Key / Secret Key 填到后台“店铺设置 → 物流追踪”。
 * 先用 OAuth（client_credentials）换 token（缓存到过期前 1 分钟），再查单号的扫描记录。
 * 每个单号的结果缓存 30 分钟（出错缓存 5 分钟），顾客反复打开不会刷爆 FedEx 的额度。
 * 密钥只在服务端使用，不会发到浏览器，后台也不回显。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Fedex_Track {

	public const OPTION = 'pc_fedex';

	private const HOSTS = array(
		'production' => 'https://apis.fedex.com',
		'sandbox'    => 'https://apis-sandbox.fedex.com',
	);

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'client_id'     => (string) ( $s['client_id'] ?? '' ),
			'client_secret' => (string) ( $s['client_secret'] ?? '' ),
			'env'           => 'sandbox' === ( $s['env'] ?? '' ) ? 'sandbox' : 'production',
		);
	}

	public static function configured(): bool {
		$s = self::settings();
		return '' !== $s['client_id'] && '' !== $s['client_secret'];
	}

	/** 保存；密钥留空表示不改。 */
	public static function save( array $in ): void {
		$old = self::settings();
		$id  = trim( sanitize_text_field( (string) ( $in['client_id'] ?? '' ) ) );
		$sec = trim( sanitize_text_field( (string) ( $in['client_secret'] ?? '' ) ) );
		$new = array(
			'client_id'     => $id,
			'client_secret' => '' !== $sec ? $sec : ( $id === $old['client_id'] ? $old['client_secret'] : '' ),
			'env'           => 'sandbox' === ( $in['env'] ?? '' ) ? 'sandbox' : 'production',
		);
		if ( ! empty( $in['clear'] ) ) {
			$new = array( 'client_id' => '', 'client_secret' => '', 'env' => 'production' );
		}
		update_option( self::OPTION, $new, false );
		delete_transient( 'pc_fedex_token' );
	}

	/** 这个包裹是不是 FedEx 的：承运商选的 FedEx，或者没选承运商但单号是 FedEx 的格式（12 / 15 / 20 / 22 位数字）。 */
	public static function is_fedex( ?string $provider, ?string $number ): bool {
		if ( 'fedex' === strtolower( (string) $provider ) ) {
			return true;
		}
		$n = preg_replace( '/\s+/', '', (string) $number );
		return '' === (string) $provider && 1 === preg_match( '/\A(?:\d{12}|\d{15}|\d{20}|\d{22})\z/', $n );
	}

	private static function token() {
		$t = get_transient( 'pc_fedex_token' );
		if ( is_string( $t ) && '' !== $t ) {
			return $t;
		}
		$s   = self::settings();
		$res = wp_remote_post(
			self::HOSTS[ $s['env'] ] . '/oauth/token',
			array(
				'timeout' => 12,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $s['client_id'],
					'client_secret' => $s['client_secret'],
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'pc_fedex_net', 'FedEx 连接失败：' . $res->get_error_message() );
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || empty( $j['access_token'] ) ) {
			$msg = $j['errors'][0]['message'] ?? ( 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
			return new \WP_Error( 'pc_fedex_auth', 'FedEx 密钥验证失败：' . $msg );
		}
		set_transient( 'pc_fedex_token', (string) $j['access_token'], max( 60, (int) ( $j['expires_in'] ?? 3600 ) - 60 ) );
		return (string) $j['access_token'];
	}

	/**
	 * 查一个单号。返回 status / code / delivered / eta / events（新的在前），或 WP_Error。
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function track( string $number, bool $fresh = false ) {
		$number = preg_replace( '/\s+/', '', $number );
		if ( '' === $number || ! self::configured() ) {
			return new \WP_Error( 'pc_fedex_off', 'FedEx 追踪还没有设置密钥。' );
		}
		$key = 'pc_fedex_t_' . md5( self::settings()['env'] . $number );
		if ( ! $fresh ) {
			$hit = get_transient( $key );
			if ( is_array( $hit ) ) {
				return isset( $hit['error'] ) ? new \WP_Error( 'pc_fedex_cached', (string) $hit['error'] ) : $hit;
			}
		}
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$res = wp_remote_post(
			self::HOSTS[ self::settings()['env'] ] . '/track/v1/trackingnumbers',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
					'X-locale'      => 'en_US',
				),
				'body'    => wp_json_encode(
					array(
						'includeDetailedScans' => true,
						'trackingInfo'         => array( array( 'trackingNumberInfo' => array( 'trackingNumber' => $number ) ) ),
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'pc_fedex_net', 'FedEx 连接失败：' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$j    = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 401 === $code ) {
			delete_transient( 'pc_fedex_token' );
		}
		$r = $j['output']['completeTrackResults'][0]['trackResults'][0] ?? null;
		if ( 200 !== $code || ! is_array( $r ) ) {
			$msg = $j['errors'][0]['message'] ?? ( 'HTTP ' . $code );
			set_transient( $key, array( 'error' => $msg ), 5 * MINUTE_IN_SECONDS );
			return new \WP_Error( 'pc_fedex_http', $msg );
		}
		if ( ! empty( $r['error'] ) ) {
			$msg = (string) ( $r['error']['message'] ?? 'Tracking number not found.' );
			set_transient( $key, array( 'error' => $msg ), 5 * MINUTE_IN_SECONDS );
			return new \WP_Error( 'pc_fedex_notfound', $msg );
		}
		$out = self::parse( $r );
		set_transient( $key, $out, 30 * MINUTE_IN_SECONDS );
		return $out;
	}

	/** 把 FedEx 的结果整理成前台要的样子（公开函数，方便测试）。 */
	public static function parse( array $r ): array {
		$dates = array();
		foreach ( (array) ( $r['dateAndTimes'] ?? array() ) as $d ) {
			if ( ! empty( $d['type'] ) && ! empty( $d['dateTime'] ) ) {
				$dates[ (string) $d['type'] ] = (string) $d['dateTime'];
			}
		}
		$eta = $r['estimatedDeliveryTimeWindow']['window']['ends'] ?? ( $dates['ESTIMATED_DELIVERY'] ?? null );
		$events = array();
		foreach ( (array) ( $r['scanEvents'] ?? array() ) as $e ) {
			$loc   = (array) ( $e['scanLocation'] ?? array() );
			$place = implode( ', ', array_filter( array( $loc['city'] ?? '', $loc['stateOrProvinceCode'] ?? '', $loc['countryCode'] ?? '' ) ) );
			$events[] = array(
				'time'  => (string) ( $e['date'] ?? '' ),
				'text'  => (string) ( $e['eventDescription'] ?? ( $e['derivedStatus'] ?? '' ) ),
				'place' => $place,
			);
		}
		usort( $events, static fn( $a, $b ) => (int) strtotime( $b['time'] ) <=> (int) strtotime( $a['time'] ) );
		return array(
			'status'    => (string) ( $r['latestStatusDetail']['description'] ?? ( $r['latestStatusDetail']['statusByLocale'] ?? '' ) ),
			'code'      => (string) ( $r['latestStatusDetail']['code'] ?? '' ),
			'delivered' => $dates['ACTUAL_DELIVERY'] ?? null,
			'eta'       => $eta ? (string) $eta : null,
			'events'    => array_slice( $events, 0, 40 ),
		);
	}
}
