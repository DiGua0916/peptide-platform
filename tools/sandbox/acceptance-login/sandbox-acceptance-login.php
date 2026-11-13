<?php
/**
 * 沙盒验收专用：认带签名、有期限的登录链接（?sbx_login=…）。只在沙盒生效；密钥文件删掉后所有链接失效。
 * 不要部署到正式站。
 */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'VIALCOVE_SANDBOX' ) || ! VIALCOVE_SANDBOX ) {
	return;
}
add_action(
	'init',
	static function (): void {
		if ( empty( $_GET['sbx_login'] ) || ! is_string( $_GET['sbx_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$file = '/srv/vialcove-sandbox/access/acceptance.json';
		$cfg  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		$deny = static function ( string $why ): void {
			wp_die( '这个验收链接不能用了（' . esc_html( $why ) . '）。请向 F1 要新的链接。', '沙盒验收', array( 'response' => 403 ) );
		};
		if ( ! is_array( $cfg ) || empty( $cfg['key'] ) ) {
			$deny( '验收入口已关闭' );
		}
		$parts = explode( '.', sanitize_text_field( wp_unslash( $_GET['sbx_login'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! preg_match( '/^[a-z_]{3,30}$/D', $parts[1] ) ) {
			$deny( '格式不对' );
		}
		list( $exp, $login, $sig ) = $parts;
		$host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
		if ( ! hash_equals( hash_hmac( 'sha256', 'login.' . $exp . '.' . $login . '.' . $host, (string) $cfg['key'] ), $sig ) ) {
			$deny( '签名不对' );
		}
		if ( (int) $exp < time() ) {
			$deny( '已过期' );
		}
		if ( ! in_array( $login, array( 'sandbox_admin', 'sbx_buyer', 'sbx_newbuyer' ), true ) ) {
			$deny( '账号不在名单里' );
		}
		$user = get_user_by( 'login', $login );
		if ( ! $user ) {
			$deny( '这个站点没有这个账号' );
		}
		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false );
		wp_safe_redirect( admin_url() );
		exit;
	},
	1
);
