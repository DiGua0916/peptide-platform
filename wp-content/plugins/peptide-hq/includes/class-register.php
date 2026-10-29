<?php
/**
 * 店铺自动接入（用户 2026-10-27：所有站点都是我开的，接入总后台是默认的，不要让人去贴连接码）。
 *
 * - 总后台有一个注册密钥。开新店铺时，在店铺的 wp-config.php 里加两行：总后台地址 PEPTIDE_HQ_URL 和注册密钥 PEPTIDE_HQ_SECRET。
 * - 店铺自己生成连接密钥，用注册密钥签名后发到这里的 /peptide-hq/v1/register，总后台就列出这个店铺。
 *   签名：HMAC-SHA256( 注册密钥, "register" \n 时间 \n 请求体的 SHA-256 )，放在 X-PC-HQ-Time / X-PC-HQ-Sign。
 * - 已经接入的店铺换了密钥再来注册时，新密钥先放在“待确认”，下次检查用它连得上才换掉（防止有人冒用注册密钥抢走店铺）。
 * - 注册时不回头请求店铺（店铺正在等这个请求的回应），稍后由检查任务确认能连上。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Register {

	public const OPTION = 'pchq_register_secret';
	public const NS     = 'peptide-hq/v1';
	private const SKEW  = 300;

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function secret(): string {
		$s = (string) get_option( self::OPTION, '' );
		if ( 64 !== strlen( $s ) ) {
			$s = bin2hex( random_bytes( 32 ) );
			update_option( self::OPTION, $s, false );
		}
		return $s;
	}

	/** 换注册密钥：已经接入的店铺不受影响，之后开的店铺要用新的。 */
	public static function regenerate(): void {
		update_option( self::OPTION, bin2hex( random_bytes( 32 ) ), false );
	}

	/** 开新店铺时加到 wp-config.php 的两行。 */
	public static function config_lines(): string {
		return "define( 'PEPTIDE_HQ_URL', '" . home_url( '/' ) . "' );\n" . "define( 'PEPTIDE_HQ_SECRET', '" . self::secret() . "' );";
	}

	public static function sign( string $secret, int $time, string $body ): string {
		return hash_hmac( 'sha256', "register\n" . $time . "\n" . hash( 'sha256', $body ), $secret );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => array( self::class, 'verify' ),
			)
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function verify( \WP_REST_Request $request ) {
		$time = (int) $request->get_header( 'x_pc_hq_time' );
		$sign = strtolower( (string) $request->get_header( 'x_pc_hq_sign' ) );
		if ( abs( time() - $time ) > self::SKEW ) {
			return new \WP_Error( 'pchq_time', 'The request time is too far from the master admin time.', array( 'status' => 401 ) );
		}
		if ( 64 !== strlen( $sign ) || ! hash_equals( self::sign( self::secret(), $time, (string) $request->get_body() ), $sign ) ) {
			return new \WP_Error( 'pchq_sign', 'The signature is not valid.', array( 'status' => 401 ) );
		}
		$seen = 'pchq_reg_' . substr( $sign, 0, 40 );
		if ( get_transient( $seen ) ) {
			return new \WP_Error( 'pchq_replay', 'This request was already used.', array( 'status' => 401 ) );
		}
		set_transient( $seen, 1, self::SKEW * 2 );
		return true;
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function handle( \WP_REST_Request $request ) {
		$p   = (array) $request->get_json_params();
		$url = esc_url_raw( (string) ( $p['url'] ?? '' ), array( 'http', 'https' ) );
		$key = (string) ( $p['key'] ?? '' );
		if ( '' === $url || ! preg_match( '/^[0-9a-f]{64}$/', $key ) ) {
			return new \WP_Error( 'pchq_bad', 'Store address or key missing.', array( 'status' => 400 ) );
		}
		[ $id, $state ] = Sites::register( $url, $key, sanitize_text_field( (string) ( $p['name'] ?? '' ) ), (string) ( $p['uuid'] ?? '' ) );
		if ( 'same' !== $state ) {
			// 稍后检查一次：确认能连上，并载入最近 31 天的数字。
			wp_schedule_single_event( time() + 5, Monitor::POLL_ONE, array( $id ) );
		}
		return array(
			'site'  => $id,
			'state' => $state,
		);
	}
}
