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

/**
 * 验收期间免登录：名单里的 IP（acceptance.json 的 ips）打开后台时自动以 sandbox_admin 登录；
 * 地址后面加 ?as=buyer / ?as=newbuyer / ?as=admin 切换成投手、新店投手、管理员。
 */
add_action(
	'init',
	static function (): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() ) {
			return;
		}
		$file = '/srv/vialcove-sandbox/access/acceptance.json';
		$cfg  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		$ip   = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		if ( ! is_array( $cfg ) || empty( $cfg['ips'] ) || (int) ( $cfg['expires'] ?? 0 ) < time() || ! in_array( $ip, (array) $cfg['ips'], true ) ) {
			return;
		}
		$map  = array( 'admin' => 'sandbox_admin', 'buyer' => 'sbx_buyer', 'newbuyer' => 'sbx_newbuyer' );
		$as   = isset( $_GET['as'] ) && is_string( $_GET['as'] ) ? sanitize_key( wp_unslash( $_GET['as'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = (string) ( $GLOBALS['pagenow'] ?? '' );
		$want = '';
		if ( isset( $map[ $as ] ) ) {
			$want = $map[ $as ];
		} elseif ( ! is_user_logged_in() && ( is_admin() || 'wp-login.php' === $page ) && empty( $_POST ) && 'logout' !== ( $_GET['action'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$want = 'sandbox_admin';
		}
		if ( '' === $want ) {
			return;
		}
		$user = get_user_by( 'login', $want );
		if ( ! $user ) {
			return; // 总后台没有投手账号：?as=buyer 在那里不起作用。
		}
		if ( get_current_user_id() !== (int) $user->ID ) {
			wp_clear_auth_cookie();
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, false );
		}
		$uri    = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$target = ( isset( $map[ $as ] ) || 'wp-login.php' === $page || '' === $uri ) ? admin_url() : home_url( remove_query_arg( 'as', $uri ) );
		if ( is_admin() && ! isset( $map[ $as ] ) ) {
			$target = admin_url( preg_replace( '#^.*/wp-admin/#', '', $uri ) );
		}
		wp_safe_redirect( $target );
		exit;
	},
	2
);
