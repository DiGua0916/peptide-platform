<?php
/**
 * 店铺邮件交给总后台“邮件中心”代发（2026-11-08）。
 *
 * - 店铺没有自己的发信连接时（FluentSMTP 里没有连接），所有 wp_mail()（订单通知、弃单提醒、注册邮件等）
 *   都用店铺的连接密钥签名后发到总后台 /peptide-hq/v1/mail，由总后台的发信邮箱发出。新开的店铺不用任何设置。
 * - 店铺自己在 FluentSMTP 里连了邮箱，就照常本地发送，不经过总后台。
 * - 签名和总后台调用店铺时相同（Hq_Connector::sign），另加 X-PC-HQ-Site（店铺编号）。
 * - 总后台拒收（关闭、超上限、未连接）或连不上时，这封邮件算发送失败（触发 wp_mail_failed），不会重复发送。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Hq_Mail {

	private const ROUTE = '/peptide-hq/v1/mail';

	public static function register_hooks(): void {
		add_filter( 'pre_wp_mail', array( self::class, 'relay' ), 20, 2 );
	}

	/** 店铺自己有没有发信连接（FluentSMTP）。 */
	public static function local_connection(): bool {
		$s = get_option( 'fluentmail-settings', array() );
		return is_array( $s ) && ! empty( $s['connections'] ) && is_array( $s['connections'] );
	}

	public static function enabled(): bool {
		if ( ! Hq_Connector::configured() || self::local_connection() ) { return false; }
		$s = Hq_Connector::settings();
		return 64 === strlen( $s['key'] ) && '' !== $s['uuid'] && '' !== $s['reg_key'];
	}

	/**
	 * @param null|bool $return
	 * @param array     $atts   to, subject, message, headers, attachments
	 * @return null|bool
	 */
	public static function relay( $return, $atts ) {
		if ( null !== $return || ! is_array( $atts ) || ! self::enabled() ) {
			return $return;
		}
		$to = $atts['to'] ?? array();
		$to = is_array( $to ) ? $to : explode( ',', (string) $to );
		$to = array_values( array_filter( array_map( 'trim', array_map( 'strval', $to ) ) ) );
		$headers = $atts['headers'] ?? array();
		$headers = is_array( $headers ) ? $headers : preg_split( '/\r\n|\r|\n/', (string) $headers );
		$files = array();
		foreach ( array_slice( (array) ( $atts['attachments'] ?? array() ), 0, 3 ) as $name => $path ) {
			$path = (string) $path;
			if ( is_file( $path ) && is_readable( $path ) && filesize( $path ) <= MB_IN_BYTES ) {
				$files[] = array( 'name' => is_string( $name ) ? $name : basename( $path ), 'data' => base64_encode( (string) file_get_contents( $path ) ) );
			}
		}
		$body = (string) wp_json_encode( array(
			'to'          => $to,
			'subject'     => (string) ( $atts['subject'] ?? '' ),
			'message'     => (string) ( $atts['message'] ?? '' ),
			'headers'     => array_values( array_filter( array_map( 'strval', (array) $headers ) ) ),
			'attachments' => $files,
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$s    = Hq_Connector::settings();
		$time = time();
		$res  = wp_remote_post(
			add_query_arg( 'rest_route', rawurlencode( self::ROUTE ), trailingslashit( (string) PEPTIDE_HQ_URL ) ),
			array(
				'timeout' => 12,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-PC-HQ-Site' => $s['uuid'],
					'X-PC-HQ-Time' => (string) $time,
					'X-PC-HQ-Sign' => Hq_Connector::sign( $s['key'], 'POST', self::ROUTE, '', $time, $body ),
				),
				'body'    => $body,
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( 200 === $code ) {
			do_action( 'wp_mail_succeeded', $atts );
			return true;
		}
		$msg = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $code;
		if ( ! is_wp_error( $res ) ) {
			$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( is_array( $json ) && is_string( $json['message'] ?? null ) ) { $msg .= ' ' . $json['message']; }
		}
		do_action( 'wp_mail_failed', new \WP_Error( 'pc_hq_mail', 'Mail center: ' . $msg, $atts ) );
		return false;
	}
}
