<?php
/**
 * FedEx 物流记录（店主 2026-11-11：密钥统一放在总后台“物流追踪（FedEx）”）。
 *
 * 店铺不保存 FedEx 密钥：查单号时签名请求总后台 /peptide-hq/v1/fedex/track，总后台去问 FedEx。
 * 店铺这边每个单号再缓存 30 分钟（出错 5 分钟）；总后台说还没填密钥时，10 分钟内不再问。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Fedex_Track {

	private const ROUTE = '/peptide-hq/v1/fedex/track';
	private const OFF   = 'pc_fedex_hq_off';

	/** 这个包裹是不是 FedEx 的：承运商选的 FedEx，或者没选承运商但单号是 FedEx 的格式（12 / 15 / 20 / 22 位数字）。 */
	public static function is_fedex( ?string $provider, ?string $number ): bool {
		if ( 'fedex' === strtolower( (string) $provider ) ) {
			return true;
		}
		$n = preg_replace( '/\s+/', '', (string) $number );
		return '' === (string) $provider && 1 === preg_match( '/\A(?:\d{12}|\d{15}|\d{20}|\d{22})\z/', $n );
	}

	/** 能不能去总后台查（接入了总后台，且总后台最近没说“还没填密钥”）。 */
	public static function configured(): bool {
		if ( ! class_exists( Hq_Connector::class ) || ! Hq_Connector::configured() ) {
			return false;
		}
		$s = Hq_Connector::settings();
		return 64 === strlen( (string) $s['key'] ) && '' !== (string) $s['uuid'] && ! get_transient( self::OFF );
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function track( string $number, bool $fresh = false ) {
		$number = preg_replace( '/\s+/', '', $number );
		if ( $fresh ) {
			delete_transient( self::OFF ); // 后台手动测试：不管上次总后台怎么说，重新问。
		}
		if ( '' === $number || ! self::configured() ) {
			return new \WP_Error( 'pc_fedex_off', 'FedEx 追踪还没有在总后台设置密钥，或这家店没有接入总后台。' );
		}
		$key = 'pc_fedex_t_' . md5( $number );
		if ( ! $fresh ) {
			$hit = get_transient( $key );
			if ( is_array( $hit ) ) {
				return isset( $hit['error'] ) ? new \WP_Error( 'pc_fedex_cached', (string) $hit['error'] ) : $hit;
			}
		}
		$s    = Hq_Connector::settings();
		$time = time();
		$body = (string) wp_json_encode( array( 'numbers' => array( $number ) ), JSON_UNESCAPED_SLASHES );
		$res  = wp_remote_post(
			add_query_arg( 'rest_route', rawurlencode( self::ROUTE ), trailingslashit( (string) PEPTIDE_HQ_URL ) ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-PC-HQ-Site' => $s['uuid'],
					'X-PC-HQ-Time' => (string) $time,
					'X-PC-HQ-Sign' => Hq_Connector::sign( $s['key'], 'POST', self::ROUTE, '', $time, $body ),
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'pc_fedex_hq', '连不上总后台：' . $res->get_error_message() );
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || ! is_array( $j ) ) {
			return new \WP_Error( 'pc_fedex_hq', '总后台返回：' . ( is_array( $j ) && is_string( $j['message'] ?? null ) ? $j['message'] : 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );
		}
		if ( empty( $j['configured'] ) ) {
			set_transient( self::OFF, 1, 10 * MINUTE_IN_SECONDS );
			return new \WP_Error( 'pc_fedex_off', 'FedEx 追踪还没有在总后台设置密钥。' );
		}
		$r = $j['results'][ $number ] ?? null;
		if ( ! is_array( $r ) || isset( $r['error'] ) ) {
			$msg = is_array( $r ) ? (string) $r['error'] : 'No result.';
			set_transient( $key, array( 'error' => $msg ), 5 * MINUTE_IN_SECONDS );
			return new \WP_Error( 'pc_fedex_err', $msg );
		}
		$out = array(
			'status'    => (string) ( $r['status'] ?? '' ),
			'code'      => (string) ( $r['code'] ?? '' ),
			'delivered' => isset( $r['delivered'] ) ? (string) $r['delivered'] : null,
			'eta'       => isset( $r['eta'] ) ? (string) $r['eta'] : null,
			'events'    => array_values( array_map( static fn( $e ) => array( 'time' => (string) ( $e['time'] ?? '' ), 'text' => (string) ( $e['text'] ?? '' ), 'place' => (string) ( $e['place'] ?? '' ) ), array_slice( (array) ( $r['events'] ?? array() ), 0, 40 ) ) ),
		);
		set_transient( $key, $out, 30 * MINUTE_IN_SECONDS );
		return $out;
	}
}
