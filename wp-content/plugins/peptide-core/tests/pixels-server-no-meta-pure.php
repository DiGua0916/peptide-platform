<?php
/**
 * 2026-11-17（用户要求）：服务端不准再直连 Meta。
 *
 * 前端那半由 tests/pixels-no-facebook-pure.php 守着（文本级）；这里是行为级：真的把
 * PeptideCore\Pixels\Server 载进来，喂一个"配了 facebook 像素 + CAPI 令牌 + 域名正好对上"
 * 的设置——也就是改动之前百分之百会发往 graph.facebook.com 的那种配置——然后断言：
 *
 *   1. targets() 一个 facebook 目标都不给；
 *   2. tiktok 没被连带砍掉，照样是目标；
 *   3. test_event()（后台"发送测试事件"按钮）直接返回禁用，不发任何 HTTP 请求。
 *
 * 任何 HTTP 调用都会让测试失败：wp_remote_post 被换成会直接判错的桩。
 * 不碰数据库、不需要 WordPress。
 */
namespace {

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );

	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}

	// 任何一次外发请求都是失败：改动的全部意义就是这里不再有请求。
	function wp_remote_post( $url = '', $args = array() ) {
		fwrite( STDERR, "FAIL: 服务端仍然发出了 HTTP 请求：{$url}\n" );
		exit( 1 );
	}
	function wp_remote_retrieve_response_code( $r ) { return 0; }
	function wp_remote_retrieve_body( $r ) { return ''; }
	function is_wp_error( $t ) { return false; }
	function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
	function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
	function home_url( $p = '' ) { return 'https://korgavex.com' . $p; }
	function esc_url_raw( $v ) { return $v; }
}

namespace PeptideCore {

	/** 只补 Server 在这两条路径上真正用到的东西。 */
	final class Pixels {
		public static array $settings = array();
		public static function settings(): array { return self::$settings; }
		/** 和真实实现同口径：填了就用填的，没填用店铺域名；都不含 www.。 */
		public static function pixel_domain( array $px ): string {
			$d = (string) ( $px['domain'] ?? '' );
			return '' !== $d ? $d : 'korgavex.com';
		}
		public static function platforms(): array { return array( 'facebook' => array( 'events' => array() ), 'tiktok' => array( 'events' => array() ) ); }
		public static function make( string $n, array $i = array(), float $v = 0.0, array $x = array() ): array { return array( 'name' => $n, 'id' => 'e1', 'time' => time() ) + $x; }
		public static function event_id( string $k ): string { return $k . '-1'; }
	}
}

namespace {

	require_once dirname( __DIR__ ) . '/includes/pixels/class-server.php';

	use PeptideCore\Pixels\Server;

	$pixel = function ( string $id, string $domain, string $token ): array {
		return array( 'id' => $id, 'domain' => $domain, 'token' => $token, 'note' => '' );
	};

	// 改动之前必发的配置：令牌有、域名和当前 host 完全一致。
	$settings = array(
		'test_code' => '',
		'platforms' => array(
			'facebook' => array( 'pixels' => array( $pixel( '1798847024660236', 'korgavex.com', 'EXAMPLE_TOKEN_NOT_REAL' ) ) ),
			'tiktok'   => array( 'pixels' => array( $pixel( 'TT_PIXEL_1', 'korgavex.com', 'EXAMPLE_TOKEN_NOT_REAL' ) ) ),
		),
	);
	\PeptideCore\Pixels::$settings = $settings;

	// ---------- 1. 名单里没有 facebook ----------

	check( 'Server::SERVER_PLATFORMS 存在', defined( Server::class . '::SERVER_PLATFORMS' ) || is_array( Server::SERVER_PLATFORMS ) );
	check( 'SERVER_PLATFORMS 里没有 facebook', ! in_array( 'facebook', Server::SERVER_PLATFORMS, true ) );
	check( 'SERVER_PLATFORMS 里还有 tiktok', in_array( 'tiktok', Server::SERVER_PLATFORMS, true ) );

	// ---------- 2. targets()：facebook 配得再齐也拿不到目标 ----------

	$targets   = Server::targets( $settings, 'korgavex.com' );
	$platforms = array_map( static fn( array $t ): string => $t[0], $targets );

	check( '域名完全对上时，facebook 仍然不是回传目标', ! in_array( 'facebook', $platforms, true ) );
	check( 'tiktok 没被连带砍掉，仍然是回传目标', in_array( 'tiktok', $platforms, true ) );
	check( '目标里不含那条 facebook 像素的 ID', ! in_array( '1798847024660236', array_map( static fn( array $t ): string => (string) $t[1], $targets ), true ) );

	// 只配 facebook 时：一个目标都没有（以前这里会有一个）。
	$only_fb = $settings;
	$only_fb['platforms']['tiktok']['pixels'] = array();
	check( '只配了 facebook 时没有任何回传目标', array() === Server::targets( $only_fb, 'korgavex.com' ) );

	// 换域名场景：host 变了，原来就该停；现在无论如何都是停。
	check( '换域名后也没有 facebook 目标', ! in_array( 'facebook', array_map( static fn( array $t ): string => $t[0], Server::targets( $settings, 'lumornea.com' ) ), true ) );

	// ---------- 3. test_event()：按钮不再直连，且不发请求 ----------

	$res = Server::test_event();

	check( 'test_event 返回禁用（ok=false）', false === $res['ok'] );
	check( 'test_event 的 reason 是 meta_server_disabled', 'meta_server_disabled' === $res['reason'] );
	check( 'test_event 没有逐像素结果', array() === $res['results'] );
	check( 'test_event 的提示指向桥', str_contains( (string) $res['message'], '桥' ) );
	check( 'test_event 仍然返回调用方要的全部键', array() === array_diff( array( 'ok', 'pixel', 'message', 'reason', 'test_code', 'results' ), array_keys( $res ) ) );
	// 走到这里说明 wp_remote_post 一次都没被调用过（被调用就已经 exit(1) 了）。
	check( '整个过程没有发出任何 HTTP 请求', true );

	// ---------- 4. 直连地址只许出现在这两个受控方法里 ----------

	$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/pixels/class-server.php' );
	// 只数会执行的行：说明性注释里提到这个地址是允许的。
	$code = implode(
		"\n",
		array_filter(
			preg_split( '/\R/', $src ),
			static fn( string $line ): bool => ! preg_match( '~^\s*(?://|\*|/\*|\*/|#)~', $line )
		)
	);
	$hits = preg_match_all( '~graph\.facebook\.com~', $code );
	check( 'class-server.php 的代码里 graph.facebook.com 只剩 2 处（队列发送 + 测试按钮，都已被名单挡住），实际 ' . $hits, 2 === $hits );
	check( 'targets() 读的是 SERVER_PLATFORMS 常量，不是写死的数组', (bool) preg_match( '/foreach \(\s*self::SERVER_PLATFORMS as \$key \)/', $src ) );
	check( 'test_event() 开头就按名单拦下来', (bool) preg_match( '/function test_event\(\): array \{.{0,400}?in_array\( .facebook., self::SERVER_PLATFORMS, true \)/s', $src ) );

	echo "PASS {$checks} 服务端无 Meta 直连检查\n";
}
