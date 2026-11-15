<?php
/**
 * 收款回写探测（release.py 在每家店的后台副本上用 wp eval-file 以站点账号运行）。
 *
 * 用这家店自己的密钥签一个请求，POST 到自己的 /pc-panapay/v1/store/event：走真实的签名校验和防重放
 * （防重放要往支付库写一个 10 分钟后自动清掉的标记，正是 11-13 因为 db.php 断掉的那一步）。
 * 事件编号故意不合法，所以到不了“处理通知”那一步，不会改任何订单。
 * 期望：HTTP 400 hub_event_invalid。503 = 支付库打不开或没接入；401 = 签名对不上。
 * 只输出 PASS / FAIL 一行，不输出密钥。
 */
defined( 'ABSPATH' ) || exit( 1 );
$fail = static function ( string $why ) { echo 'FAIL ', $why, "\n"; };
if ( ! class_exists( '\VialCove\Panapay\Hub_Protocol' ) || ! class_exists( '\PeptideCore\Hq_Connector' ) ) {
	$fail( '支付插件或店铺连接没有加载' );
	return;
}
$s    = \PeptideCore\Hq_Connector::settings();
$key  = is_string( $s['key'] ?? null ) ? $s['key'] : '';
$uuid = is_string( $s['uuid'] ?? null ) ? $s['uuid'] : '';
if ( 64 !== strlen( $key ) || '' === $uuid ) {
	$fail( '这家店没有接入总后台（没有店铺密钥）' );
	return;
}
$route = \VialCove\Panapay\Hub_Protocol::EVENT;
// 和总后台发通知用同一个地址：这家店的管理地址 + ?rest_route=（Hub_Protocol::request() 的写法）。
$base  = class_exists( '\PeptideCore\Management_Host' ) ? \PeptideCore\Management_Host::management_url() : '';
if ( '' === $base ) {
	$fail( '这份副本没有配置管理地址（PEPTIDE_CORE_MANAGEMENT_URL）' );
	return;
}
$body  = (string) wp_json_encode( array( 'event_id' => 'release-probe' ) );
$time  = time();
$nonce = bin2hex( random_bytes( 16 ) );
$sign  = \VialCove\Panapay\Hub_Protocol::signature( $key, 'POST', $route, $time, $nonce, $body );
$res   = wp_remote_post(
	add_query_arg( 'rest_route', $route, trailingslashit( $base ) ),
	array(
		'timeout'     => 20,
		'redirection' => 0,
		'headers' => array( 'Content-Type' => 'application/json', 'X-PC-Pay-Time' => (string) $time, 'X-PC-Pay-Nonce' => $nonce, 'X-PC-Pay-Sign' => $sign, 'X-PC-Pay-Store' => $uuid ),
		'body'    => $body,
	)
);
if ( is_wp_error( $res ) ) {
	$fail( '请求发不出去：' . $res->get_error_message() );
	return;
}
$code = (int) wp_remote_retrieve_response_code( $res );
$err  = json_decode( (string) wp_remote_retrieve_body( $res ), true );
$err  = is_array( $err ) ? (string) ( $err['code'] ?? '' ) : '';
if ( 400 === $code ) {
	echo 'PASS 签名和支付库都正常（HTTP 400，探测事件按预期被拒）', "\n";
	return;
}
$fail( 'HTTP ' . $code . ( '' !== $err ? ' ' . $err : '' ) . ( 503 === $code ? '（支付库打不开或没接入）' : ( 401 === $code ? '（签名对不上）' : '' ) ) );
