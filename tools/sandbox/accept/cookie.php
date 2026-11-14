<?php
// 沙盒验收用：给指定账号生成一小时有效的登录 cookie 和几个 nonce（不经过密码）。只在沙盒执行。
if ( ! defined( 'VIALCOVE_SANDBOX' ) || ! VIALCOVE_SANDBOX ) { fwrite( STDERR, "sandbox only\n" ); exit( 1 ); }
$u = get_user_by( 'login', getenv( 'ACC_LOGIN' ) );
if ( ! $u ) { fwrite( STDERR, "no user\n" ); exit( 1 ); }
$exp    = time() + 3600;
$token  = WP_Session_Tokens::get_instance( $u->ID )->create( $exp );
$logged = wp_generate_auth_cookie( $u->ID, $exp, 'logged_in', $token );
echo 'COOKIE=' . SECURE_AUTH_COOKIE . '=' . rawurlencode( wp_generate_auth_cookie( $u->ID, $exp, 'secure_auth', $token ) ) . '; ' . LOGGED_IN_COOKIE . '=' . rawurlencode( $logged ) . "\n";
wp_set_current_user( $u->ID );
$_COOKIE[ LOGGED_IN_COOKIE ] = $logged;
foreach ( array( 'wp_rest', 'woocommerce-mark-order-status', 'order-item', 'bulk-orders', 'update-order_' . getenv( 'ACC_ORDER' ) ) as $a ) {
	echo 'NONCE_' . preg_replace( '/[^a-z]+/', '_', $a ) . '=' . wp_create_nonce( $a ) . "\n";
}
