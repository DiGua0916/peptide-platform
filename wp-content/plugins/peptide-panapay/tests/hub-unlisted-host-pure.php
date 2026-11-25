<?php
/**
 * 2026-11-25 店主：收单方换付款页域名不再拦截。
 *
 * 行为级：真的载入 Hub 和 Configuration，用反射调用 Hub::unlisted_host()：
 *   1. 名单外、HTTPS、公网域名 → 返回域名（放行）；
 *   2. http、IP、带非 443 端口、带账号密码、内网解析、超长域名 → 返回空（照旧拦下）；
 *   3. 已在名单里的域名不算“新域名”；名单快满（>30）时不再往里加，避免下游超过 32 个上限；
 * 再做源码级检查：session() 先试名单，名单外才走 unlisted_host()，放行时开提醒、不清提醒。
 *
 * 不碰数据库、不发网络请求（DNS 结果预先写进 Configuration 的缓存）。
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); }
		$checks = ( $checks ?? 0 ) + 1;
	}
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function wp_http_validate_url( $url ) { return $url; }
}
namespace {
	require_once dirname( __DIR__ ) . '/includes/class-configuration.php';
	require_once dirname( __DIR__ ) . '/includes/class-hub.php';

	use VialCove\Panapay\Configuration;
	use VialCove\Panapay\Hub;

	// 预置 DNS：公网 / 内网。
	$cache = new ReflectionProperty( Configuration::class, 'public_hosts' );
	$cache->setAccessible( true );
	$cache->setValue( null, array(
		'pay.newcashier.top' => true, 'easygatewayb.top' => true, 'www.backupaoy.top' => true,
		'intranet.example.top' => false,
	) );
	$m = new ReflectionMethod( Hub::class, 'unlisted_host' );
	$m->setAccessible( true );
	$trust = array( 'gateway' => 'https://www.backupaoy.top', 'payment_hosts' => array( 'marina-style.com', 'easygatewayb.top' ) );
	$u = fn( string $url, ?array $t = null ) => $m->invoke( null, $url, $t ?? $trust );

	check( '名单外 HTTPS 公网域名放行', 'pay.newcashier.top' === $u( 'https://pay.newcashier.top/checkout?id=1' ) );
	check( '大写域名统一成小写', 'pay.newcashier.top' === $u( 'https://PAY.NewCashier.top/x' ) );
	check( '显式 443 端口也放行', 'pay.newcashier.top' === $u( 'https://pay.newcashier.top:443/x' ) );
	check( '名单内的不算新域名', '' === $u( 'https://easygatewayb.top/x' ) );
	check( 'http 拦下', '' === $u( 'http://pay.newcashier.top/x' ) );
	check( 'IP 拦下', '' === $u( 'https://203.0.113.9/x' ) );
	check( '非 443 端口拦下', '' === $u( 'https://pay.newcashier.top:8443/x' ) );
	check( '带账号密码拦下', '' === $u( 'https://a:b@pay.newcashier.top/x' ) );
	check( '解析到内网拦下', '' === $u( 'https://intranet.example.top/x' ) );
	check( '空地址拦下', '' === $u( '' ) );
	check( '含空格的地址拦下', '' === $u( 'https://pay.newcashier.top/a b' ) );
	$full = array( 'gateway' => 'https://www.backupaoy.top', 'payment_hosts' => array_map( fn( $i ) => "h$i.example.com", range( 1, 31 ) ) );
	check( '名单快满时不再加', '' === $u( 'https://pay.newcashier.top/x', $full ) );

	// 源码级：放行路径的接线。
	$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-hub.php' );
	$session = substr( $src, strpos( $src, 'private static function session(' ), 3000 );
	check( 'session 先试名单', false !== strpos( $session, '$listed = Configuration::payment_url( $url, $trust );' ) );
	check( '名单外才走 unlisted_host', false !== strpos( $session, "\$new_host = \$listed ? '' : self::unlisted_host( \$url, \$trust );" ) );
	check( '放行的新域名进入发给店铺的策略', strpos( $session, "\$trust['payment_hosts'][] = \$new_host;" ) < strpos( $session, 'self::session_policy( $row, $trust )' ) );
	check( '放行时开提醒（allowed=true），不清提醒', false !== strpos( $session, 'self::host_alert( $row, $url, self::checkout_trust( $row ), true );' ) );
	check( '测试模式在生产照旧拦下', false !== strpos( $session, "! ( \$row['test_mode'] && 'production' === wp_get_environment_type() )" ) );
	check( '提醒文案写明已放行', false !== strpos( $src, '已自动放行，顾客可以正常付款' ) );

	echo "PASS $checks 收银台新域名放行检查\n";
}
