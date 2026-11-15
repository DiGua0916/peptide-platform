<?php
/**
 * 发布前后“同一份数据逐页比对”的准备（F5，2026-11-14）。只在沙盒或本机副本上用，不要在生产上跑。
 *
 *   wp eval-file tools/release-compare/prepare.php <配置文件> <投手账号> <要改密码的投手账号或 -> <管理员账号>
 *   （文件不在服务器上时：wp eval-file - <配置文件> … < tools/release-compare/prepare.php）
 *
 * 做两件事，结果只写进 <配置文件>（权限 600），屏幕上不打印登录信息：
 * 1. 给三个账号各开一个 2 小时的登录会话，记下浏览器要带的 cookie（compare.py 用它们以对应身份取页面）。
 * 2. 挑出这份库里比对要用的东西：一张已付款订单、一件简单商品、两件多规格商品、一个有商品的专辑。
 *
 * 用完跑 cleanup.php 把这些会话作废。配置文件里是登录凭据：不要提交到 Git，用完删掉。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

$f5_args = isset( $args ) && is_array( $args ) ? array_values( $args ) : array();
if ( count( $f5_args ) < 4 ) {
	WP_CLI::error( '用法：wp eval-file prepare.php <配置文件> <投手账号> <要改密码的投手账号或 -> <管理员账号>' );
}
list( $f5_out, $f5_buyer, $f5_mustchange, $f5_admin ) = $f5_args;

$f5_expires = time() + 2 * HOUR_IN_SECONDS;
$f5_cookies = static function ( string $login ) use ( $f5_expires ): array {
	$user = get_user_by( 'login', $login );
	if ( ! $user ) {
		WP_CLI::error( '没有这个账号：' . $login );
	}
	// 三个 cookie 共用同一个会话，cleanup.php 按它作废。
	$token = WP_Session_Tokens::get_instance( $user->ID )->create( $f5_expires );
	return array(
		'user_id' => (int) $user->ID,
		'token'   => $token,
		'cookies' => array(
			AUTH_COOKIE        => wp_generate_auth_cookie( $user->ID, $f5_expires, 'auth', $token ),
			SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user->ID, $f5_expires, 'secure_auth', $token ),
			LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user->ID, $f5_expires, 'logged_in', $token ),
		),
	);
};

$f5_roles = array(
	'buyer'      => $f5_cookies( $f5_buyer ),
	'localadmin' => $f5_cookies( $f5_admin ),
);
if ( '-' !== $f5_mustchange ) {
	$f5_roles['relnew'] = $f5_cookies( $f5_mustchange );
}

// 比对要用的商品、订单、专辑：挑这份库里现有的。
$f5_pick = static function ( string $type, int $n ): array {
	$out = array();
	foreach ( wc_get_products( array( 'type' => $type, 'status' => 'publish', 'limit' => 20, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $p ) {
		if ( $p->is_purchasable() || 'variable' === $type ) {
			$out[] = array( 'id' => (int) $p->get_id(), 'path' => wp_make_link_relative( get_permalink( $p->get_id() ) ) );
		}
		if ( count( $out ) >= $n ) {
			break;
		}
	}
	return $out;
};
$f5_simple   = $f5_pick( 'simple', 1 );
$f5_variable = $f5_pick( 'variable', 2 );
$f5_orders   = wc_get_orders( array( 'type' => 'shop_order', 'status' => array( 'processing', 'completed' ), 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids' ) );
$f5_cats     = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 1 ) );
if ( ! $f5_simple || count( $f5_variable ) < 2 || ! $f5_orders || is_wp_error( $f5_cats ) || ! $f5_cats ) {
	WP_CLI::error( '这份库里缺比对要用的东西：要 1 件简单商品、2 件多规格商品、1 张已付款订单、1 个有商品的专辑。' );
}

$f5_config = array(
	'base'     => untrailingslashit( home_url() ),
	'created'  => gmdate( 'c' ),
	'expires'  => gmdate( 'c', $f5_expires ),
	'roles'    => $f5_roles,
	'order'    => (int) $f5_orders[0],
	'products' => array(
		'simple'   => $f5_simple[0],
		'variable' => $f5_variable,
	),
	'category' => array(
		'id'   => (int) $f5_cats[0]->term_id,
		'path' => wp_make_link_relative( get_term_link( $f5_cats[0] ) ),
	),
);

$f5_old = umask( 0077 );
$f5_ok  = file_put_contents( $f5_out, wp_json_encode( $f5_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
umask( $f5_old );
if ( false === $f5_ok ) {
	WP_CLI::error( '写不进配置文件：' . $f5_out );
}
@chmod( $f5_out, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

WP_CLI::success(
	sprintf(
		'配置已写到 %s（含登录 cookie，2 小时内有效，用完跑 cleanup.php 并删掉文件）。站点 %s；身份：%s；订单 #%d；简单商品 %d；多规格商品 %s；专辑 %d。',
		$f5_out,
		$f5_config['base'],
		implode( '、', array_keys( $f5_roles ) ),
		$f5_config['order'],
		$f5_simple[0]['id'],
		implode( '、', array_column( $f5_variable, 'id' ) ),
		$f5_config['category']['id']
	)
);
