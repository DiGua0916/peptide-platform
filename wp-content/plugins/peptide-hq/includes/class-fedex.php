<?php
/**
 * FedEx 官方追踪接口，总后台统一接入（店主 2026-11-11：密钥放总后台，各店铺共用）。
 *
 * - 总后台 → 物流追踪（FedEx）填 API Key / Secret Key（Basic Integrated Visibility，原 Track API）。
 * - 店铺查单号时签名请求 POST /peptide-hq/v1/fedex/track（和邮件、AI 同一套店铺签名，Mail::verify），总后台去问 FedEx。
 * - OAuth token 缓存到过期前 1 分钟；每个单号结果缓存 30 分钟（出错 5 分钟），FedEx 额度每个组织每天 10 万次。
 * - 密钥只在总后台服务端使用，不回显、不发给店铺。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Fedex {

	public const OPTION = 'pchq_fedex';

	private const HOSTS = array(
		'production' => 'https://apis.fedex.com',
		'sandbox'    => 'https://apis-sandbox.fedex.com',
	);

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		$secret = '';
		if ( '' !== (string) ( $s['secret_box'] ?? '' ) ) {
			try {
				$secret = (string) Crypto::decrypt( (string) $s['secret_box'] );
			} catch ( \Throwable $e ) {
				$secret = '';
			}
		}
		return array(
			'client_id'     => (string) ( $s['client_id'] ?? '' ),
			'client_secret' => $secret,
			'env'           => 'sandbox' === ( $s['env'] ?? '' ) ? 'sandbox' : 'production',
		);
	}

	public static function configured(): bool {
		$s = self::settings();
		return '' !== $s['client_id'] && '' !== $s['client_secret'];
	}

	/** 保存；Secret 留空表示不改。 */
	public static function save( array $in ): void {
		if ( ! empty( $in['clear'] ) ) {
			delete_option( self::OPTION );
			delete_transient( 'pchq_fedex_token' );
			return;
		}
		$old = self::settings();
		$id  = trim( sanitize_text_field( (string) ( $in['client_id'] ?? '' ) ) );
		$sec = trim( sanitize_text_field( (string) ( $in['client_secret'] ?? '' ) ) );
		if ( '' === $sec && $id === $old['client_id'] ) {
			$sec = $old['client_secret'];
		}
		update_option(
			self::OPTION,
			array(
				'client_id'  => $id,
				'secret_box' => '' !== $sec ? Crypto::encrypt( $sec ) : '',
				'env'        => 'sandbox' === ( $in['env'] ?? '' ) ? 'sandbox' : 'production',
			),
			false
		);
		delete_transient( 'pchq_fedex_token' );
	}

	private static function token() {
		$t = get_transient( 'pchq_fedex_token' );
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
			return new \WP_Error( 'pchq_fedex_net', 'FedEx 连接失败：' . $res->get_error_message() );
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || empty( $j['access_token'] ) ) {
			$msg = $j['errors'][0]['message'] ?? ( 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
			return new \WP_Error( 'pchq_fedex_auth', 'FedEx 密钥验证失败：' . $msg );
		}
		set_transient( 'pchq_fedex_token', (string) $j['access_token'], max( 60, (int) ( $j['expires_in'] ?? 3600 ) - 60 ) );
		return (string) $j['access_token'];
	}

	/**
	 * 查一个单号：status / code / delivered / eta / events（新的在前），或 WP_Error。
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function track( string $number, bool $fresh = false ) {
		$number = preg_replace( '/\s+/', '', $number );
		if ( ! preg_match( '/\A[0-9A-Za-z]{6,40}\z/', $number ) ) {
			return new \WP_Error( 'pchq_fedex_number', 'Invalid tracking number.' );
		}
		if ( ! self::configured() ) {
			return new \WP_Error( 'pchq_fedex_off', 'FedEx 追踪还没有在总后台设置密钥。' );
		}
		$key = 'pchq_fedex_t_' . md5( self::settings()['env'] . $number );
		if ( ! $fresh ) {
			$hit = get_transient( $key );
			if ( is_array( $hit ) ) {
				return isset( $hit['error'] ) ? new \WP_Error( 'pchq_fedex_cached', (string) $hit['error'] ) : $hit;
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
			return new \WP_Error( 'pchq_fedex_net', 'FedEx 连接失败：' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$j    = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 401 === $code ) {
			delete_transient( 'pchq_fedex_token' );
		}
		$r = $j['output']['completeTrackResults'][0]['trackResults'][0] ?? null;
		if ( 200 !== $code || ! is_array( $r ) ) {
			$msg = $j['errors'][0]['message'] ?? ( 'HTTP ' . $code );
			set_transient( $key, array( 'error' => $msg ), 5 * MINUTE_IN_SECONDS );
			return new \WP_Error( 'pchq_fedex_http', $msg );
		}
		if ( ! empty( $r['error'] ) ) {
			$msg = (string) ( $r['error']['message'] ?? 'Tracking number not found.' );
			set_transient( $key, array( 'error' => $msg ), 5 * MINUTE_IN_SECONDS );
			return new \WP_Error( 'pchq_fedex_notfound', $msg );
		}
		$out = self::parse( $r );
		set_transient( $key, $out, 30 * MINUTE_IN_SECONDS );
		return $out;
	}

	public static function parse( array $r ): array {
		$dates = array();
		foreach ( (array) ( $r['dateAndTimes'] ?? array() ) as $d ) {
			if ( ! empty( $d['type'] ) && ! empty( $d['dateTime'] ) ) {
				$dates[ (string) $d['type'] ] = (string) $d['dateTime'];
			}
		}
		$eta    = $r['estimatedDeliveryTimeWindow']['window']['ends'] ?? ( $dates['ESTIMATED_DELIVERY'] ?? null );
		$events = array();
		foreach ( (array) ( $r['scanEvents'] ?? array() ) as $e ) {
			$loc      = (array) ( $e['scanLocation'] ?? array() );
			$events[] = array(
				'time'  => (string) ( $e['date'] ?? '' ),
				'text'  => (string) ( $e['eventDescription'] ?? ( $e['derivedStatus'] ?? '' ) ),
				'place' => implode( ', ', array_filter( array( $loc['city'] ?? '', $loc['stateOrProvinceCode'] ?? '', $loc['countryCode'] ?? '' ) ) ),
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

	public static function routes(): void {
		register_rest_route(
			Register::NS,
			'/fedex/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_track' ),
				'permission_callback' => array( Mail::class, 'verify' ),
			)
		);
	}

	/** 店铺签名请求：{numbers: [...]}，最多 10 个。 */
	public static function rest_track( \WP_REST_Request $r ) {
		$p    = $r->get_json_params();
		$nums = is_array( $p['numbers'] ?? null ) ? array_slice( array_values( array_unique( array_map( 'strval', $p['numbers'] ) ) ), 0, 10 ) : array();
		$use17 = Track17::configured();
		$out   = array( 'configured' => $use17 || self::configured(), 'results' => array() );
		foreach ( $nums as $n ) {
			$t                       = $use17 ? Track17::track( $n ) : self::track( $n );
			$out['results'][ $n ] = is_wp_error( $t ) ? array( 'error' => $t->get_error_message() ) : $t;
		}
		return rest_ensure_response( $out );
	}
}
