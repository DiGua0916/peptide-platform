<?php
/**
 * 总后台连接（店铺这边）冒烟测试：连接码、签名检查（错签名、过期、重放、篡改参数）、状态、每日数字、
 * 一次性登录链接、销售账号接口、重新生成密钥。全部是真实的 HTTP 请求。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-hq-connector.php
 * 结束时恢复原来的连接密钥（已经接上的总后台不受影响），删掉测试账号。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Hq_Connector;
use PeptideCore\Staff;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$orig = get_option( Hq_Connector::OPTION, null );
$key  = Hq_Connector::key();

/** 像总后台一样签名发请求。$mutate 可以在签名后改请求（用来测试篡改）。 */
$call = static function ( string $method, string $route, array $query = array(), ?array $body = null, array $opt = array() ) use ( &$key ) {
	$time    = $opt['time'] ?? time();
	$payload = null === $body ? '' : (string) wp_json_encode( (object) $body );
	$sign    = $opt['sign'] ?? Hq_Connector::sign( $opt['key'] ?? $key, $method, $route, Hq_Connector::canonical_query( $query ), $time, $payload );
	$send    = $opt['send_query'] ?? $query;
	$url     = add_query_arg( array_map( 'rawurlencode', array_merge( array( 'rest_route' => $route ), $send ) ), home_url( '/' ) );
	$res     = wp_remote_request(
		$url,
		array(
			'method'      => $method,
			'timeout'     => 30,
			'redirection' => 0,
			'headers'     => array(
				'X-PC-HQ-Time' => (string) $time,
				'X-PC-HQ-Sign' => $sign,
				'X-PC-HQ-Url'  => 'http://hq.smoke.example/',
				'Content-Type' => 'application/json',
			),
			'body'        => '' === $payload ? null : $payload,
		)
	);
	return array( (int) wp_remote_retrieve_response_code( $res ), json_decode( (string) wp_remote_retrieve_body( $res ), true ), $sign );
};

// 1. 自动注册（wp-config.php 里有总后台的两行时）。
$ok( 'the store makes its own 64-character connection key', 64 === strlen( $key ) && ctype_xdigit( $key ) );
if ( Hq_Connector::configured() ) {
	$reg = Hq_Connector::register();
	wp_cache_delete( Hq_Connector::OPTION, 'options' );
	$st = Hq_Connector::settings();
	$ok( 'the store registers itself with the master admin (real request)', true === $reg && $st['reg_key'] === hash( 'sha256', $key ) && $st['registered'] >= time() - 60 && '' === $st['reg_error'] );
	delete_transient( 'pc_hq_reg_wait' );
	$before = $st['registered'];
	Hq_Connector::maybe_register();
	wp_cache_delete( Hq_Connector::OPTION, 'options' );
	$ok( 'opening the admin does not register again while nothing changed', Hq_Connector::settings()['registered'] === $before );
} else {
	echo "SKIP PEPTIDE_HQ_URL / PEPTIDE_HQ_SECRET not set, registration not tested\n";
}

// 2. 签名。
[ $c, $status ] = $call( 'GET', '/peptide/v1/hq/status' );
$ok( 'a signed request gets the store status (name, versions, domains, open orders)', 200 === $c && get_bloginfo( 'name' ) === $status['name'] && PEPTIDE_CORE_VERSION === $status['versions']['plugin'] && isset( $status['domains'], $status['processing'], $status['last_order'], $status['coming_soon'] ) );
wp_cache_delete( Hq_Connector::OPTION, 'options' ); // 接口是另一个请求写的，读之前清掉这里的缓存。
$ok( 'the store remembers which master admin connected and when', 'http://hq.smoke.example/' === Hq_Connector::settings()['hq_url'] && Hq_Connector::settings()['seen'] >= time() - 60 );
[ $c ] = $call( 'GET', '/peptide/v1/hq/status', array(), null, array( 'sign' => str_repeat( 'a', 64 ) ) );
$ok( 'a wrong signature is refused (401)', 401 === $c );
[ $c ] = $call( 'GET', '/peptide/v1/hq/status', array(), null, array( 'key' => str_repeat( 'b', 64 ) ) );
$ok( 'a different key is refused (401)', 401 === $c );
[ $c ] = $call( 'GET', '/peptide/v1/hq/status', array(), null, array( 'time' => time() - 600 ) );
$ok( 'a request older than 5 minutes is refused (401)', 401 === $c );
[ $c, , $sign ] = $call( 'GET', '/peptide/v1/hq/summary', array( 'days' => '3' ) );
[ $c2 ]          = $call( 'GET', '/peptide/v1/hq/summary', array( 'days' => '3' ), null, array( 'sign' => $sign, 'time' => (int) ( time() ) ) );
$ok( 'the same signed request cannot be sent twice (replay refused)', 200 === $c && 401 === $c2 );
[ $c ] = $call( 'GET', '/peptide/v1/hq/summary', array( 'days' => '3' ), null, array( 'send_query' => array( 'days' => '90' ) ) );
$ok( 'changing a parameter after signing is refused (401)', 401 === $c );
$plain = wp_remote_get( add_query_arg( 'rest_route', rawurlencode( '/peptide/v1/hq/status' ), home_url( '/' ) ), array( 'timeout' => 20 ) );
$ok( 'without any signature the store says nothing (401)', 401 === (int) wp_remote_retrieve_response_code( $plain ) );

// 3. 每天的数字。
[ $c, $sum ] = $call( 'GET', '/peptide/v1/hq/summary', array( 'days' => '7' ) );
$days        = (array) ( $sum['days'] ?? array() );
$ok( 'summary: 7 days up to today with orders, sales and visitors', 200 === $c && 7 === count( $days ) && wp_date( 'Y-m-d' ) === $days[6]['day'] && isset( $days[0]['orders'], $days[0]['revenue'], $days[0]['visitors'] ) && '' !== $sum['currency'] );
[ , $big ] = $call( 'GET', '/peptide/v1/hq/summary', array( 'days' => '500' ) );
$ok( 'at most 92 days', Hq_Connector::MAX_DAYS === count( (array) ( $big['days'] ?? array() ) ) );

// 4. 一次性登录链接。
[ $c, $login ] = $call( 'POST', '/peptide/v1/hq/login', array(), array() );
$url           = (string) ( $login['url'] ?? '' );
$first         = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 0 ) );
$cookies       = implode( ' ', array_map( static fn( $ck ) => $ck->name, (array) wp_remote_retrieve_cookies( $first ) ) );
$ok( 'the login link logs in as the store administrator and opens the admin', 200 === $c && str_contains( $url, Hq_Connector::LOGIN_ARG . '=' ) && 302 === (int) wp_remote_retrieve_response_code( $first ) && str_contains( (string) wp_remote_retrieve_header( $first, 'location' ), '/wp-admin/' ) && str_contains( $cookies, 'wordpress_logged_in' ) );
$again = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 0 ) );
$ok( 'the login link works only once', 302 === (int) wp_remote_retrieve_response_code( $again ) && str_contains( (string) wp_remote_retrieve_header( $again, 'location' ), 'wp-login.php' ) );
$fake = wp_remote_get( add_query_arg( Hq_Connector::LOGIN_ARG, 'made-up-token', home_url( '/' ) ), array( 'timeout' => 20, 'redirection' => 0 ) );
$ok( 'a made-up login token goes to the login page', str_contains( (string) wp_remote_retrieve_header( $fake, 'location' ), 'wp-login.php' ) );

// 5. 销售账号。
$pass         = wp_generate_password( 16 );
[ $c, $made ] = $call( 'POST', '/peptide/v1/hq/staff', array(), array( 'login' => 'pc_hq_conn_smoke', 'email' => 'pc-hq-conn-smoke@example.com', 'name' => 'Conn Smoke', 'password' => $pass, 'modules' => array( 'orders', 'products', 'bogus' ) ) );
$sid          = (int) ( $made['id'] ?? 0 );
$ok( 'the master admin creates a salesperson account with the ticked menus', 200 === $c && $sid && Staff::is_staff( $sid ) && array( 'orders', 'products' ) === $made['modules'] && wp_check_password( $pass, get_userdata( $sid )->user_pass, $sid ) );
[ $c, $bad ] = $call( 'POST', '/peptide/v1/hq/staff', array(), array( 'login' => 'pc_hq_conn_smoke2', 'email' => 'nope', 'password' => $pass ) );
$ok( 'invalid data is refused with a message (400)', 400 === $c && '' !== (string) ( $bad['message'] ?? '' ) );
[ $c, $list ] = $call( 'GET', '/peptide/v1/hq/staff' );
$ok( 'the account list shows the account and the 10 menus', 200 === $c && in_array( 'pc_hq_conn_smoke', array_column( (array) $list['accounts'], 'login' ), true ) && 10 === count( (array) $list['modules'] ) );
[ $c, $off ] = $call( 'POST', '/peptide/v1/hq/staff', array(), array( 'id' => $sid, 'email' => 'pc-hq-conn-smoke@example.com', 'name' => 'Conn Smoke', 'modules' => array( 'orders' ), 'disabled' => true ) );
Staff::flush_cache();
clean_user_cache( $sid );
$ok( 'disabling and changing menus works; the password stays', 200 === $c && true === $off['disabled'] && array( 'orders' ) === $off['modules'] && Staff::disabled( $sid ) && wp_check_password( $pass, get_userdata( $sid )->user_pass, $sid ) );
[ $c ] = $call( 'POST', '/peptide/v1/hq/staff/delete', array(), array( 'id' => $sid ) );
clean_user_cache( $sid );
wp_cache_delete( $sid, 'users' );
$ok( 'the account can be deleted', 200 === $c && ! get_userdata( $sid ) );
[ $c ] = $call( 'POST', '/peptide/v1/hq/staff/delete', array(), array( 'id' => 1 ) );
$ok( 'an administrator cannot be deleted through this (404)', 404 === $c && get_userdata( 1 ) );

// 6. 总后台下发的设置（服务器 IP、搜索引擎收录、访客追踪）和平台工具。
$orig_ip     = \PeptideCore\Domains::server_ip();
$orig_public = get_option( 'blog_public' );
$orig_trk    = get_option( \PeptideCore\Tracking\Tracker::OPTION, null );
[ $c, $conf ] = $call( 'POST', '/peptide/v1/hq/config', array(), array( 'server_ip' => '198.51.100.7', 'blog_public' => false, 'tracking' => array( 'retention_days' => 90 ) ) );
wp_cache_delete( 'blog_public', 'options' );
wp_cache_delete( \PeptideCore\Domains::OPTION, 'options' );
wp_cache_delete( 'alloptions', 'options' );
$ok( 'the master admin sets the server IP, search engine indexing and visitor data retention', 200 === $c && '198.51.100.7' === $conf['server_ip'] && false === $conf['blog_public'] && 90 === $conf['tracking']['retention_days'] && '198.51.100.7' === \PeptideCore\Domains::server_ip() );
$ok( 'the DNS record for new domains points to that server', '198.51.100.7' === \PeptideCore\Domains::record( 'smoke-hq-example.com', 'none' )['value'] );
[ $c ] = $call( 'POST', '/peptide/v1/hq/config', array(), array( 'server_ip' => 'not-an-ip' ) );
$ok( 'an invalid server IP is refused (400)', 400 === $c );
[ , $st ] = $call( 'GET', '/peptide/v1/hq/status' );
$ok( 'the status reports the store ID, settings, theme and active plugins', Hq_Connector::uuid() === $st['uuid'] && '198.51.100.7' === $st['config']['server_ip'] && '' !== $st['theme'] && in_array( 'Peptide Core', array_column( (array) $st['plugins'], 'name' ), true ) );
[ , $login ] = $call( 'POST', '/peptide/v1/hq/login', array(), array( 'to' => 'plugins' ) );
$to_res      = wp_remote_get( (string) $login['url'], array( 'timeout' => 20, 'redirection' => 0 ) );
$ok( 'a platform tool link logs in and opens that page (Plugins)', str_ends_with( (string) wp_remote_retrieve_header( $to_res, 'location' ), '/wp-admin/plugins.php' ) );
[ , $login ] = $call( 'POST', '/peptide/v1/hq/login', array(), array( 'to' => 'nowhere' ) );
$to_res      = wp_remote_get( (string) $login['url'], array( 'timeout' => 20, 'redirection' => 0 ) );
$ok( 'an unknown page opens the admin home instead', str_ends_with( (string) wp_remote_retrieve_header( $to_res, 'location' ), '/wp-admin/' ) );
// 还原（设置是接口那个请求改的，先清掉这里缓存的旧值，否则 update_option 会以为没变化而不写）。
wp_cache_delete( \PeptideCore\Tracking\Tracker::OPTION, 'options' );
wp_cache_delete( 'blog_public', 'options' );
wp_cache_delete( 'alloptions', 'options' );
\PeptideCore\Domains::save_server_ip( $orig_ip );
update_option( 'blog_public', $orig_public );
if ( null === $orig_trk ) {
	delete_option( \PeptideCore\Tracking\Tracker::OPTION );
} else {
	update_option( \PeptideCore\Tracking\Tracker::OPTION, $orig_trk );
}

// 7. 换密钥。
$old = $key;
Hq_Connector::regenerate();
[ $c ] = $call( 'GET', '/peptide/v1/hq/status', array(), null, array( 'key' => $old ) );
$ok( 'after the key changes, the old key is refused', 401 === $c && Hq_Connector::key() !== $old );
if ( Hq_Connector::configured() ) {
	delete_transient( 'pc_hq_reg_wait' );
	Hq_Connector::maybe_register();
	wp_cache_delete( Hq_Connector::OPTION, 'options' );
	$ok( 'with a new key the store registers again by itself', Hq_Connector::settings()['reg_key'] === hash( 'sha256', Hq_Connector::key() ) );
}

// 清理：恢复原来的密钥并重新注册（已经接上的总后台继续可用）。
if ( null === $orig ) {
	delete_option( Hq_Connector::OPTION );
} else {
	update_option( Hq_Connector::OPTION, $orig, false );
}
if ( Hq_Connector::configured() ) {
	Hq_Connector::register();
}
delete_transient( 'pc_hq_reg_wait' );
$ok( 'original connection key restored', null === $orig || $orig['key'] === Hq_Connector::settings()['key'] );
