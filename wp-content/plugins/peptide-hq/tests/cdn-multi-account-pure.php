<?php
/**
 * 2026-11-17（用户要求，一人一号）：一个 Cloudflare 账号一个令牌，域名自己认账号。
 *
 * 行为级测试：真的载入 PeptideHq\Cdn，用一个假的 HTTP 层（按 Authorization 头分辨是哪个令牌）
 * 模拟"老毛的号只看得到 korgavex.com、光辉的号只看得到 lumornea.com"，然后验证：
 *
 *   1. 旧版单个令牌会被当成列表里的第一个账号，不用重新配置（迁移）；
 *   2. 加账号会验证令牌，格式不对 / 不是 active 都拒绝；
 *   3. bind_domain 能认出域名属于哪个账号，并把结果记在域名行上，第二次不再重复探测；
 *   4. 认不出来时给的是明确错误，不是悄悄用错账号；
 *   5. 换了令牌会清掉归属，让域名重新认；删账号同理。
 *
 * 不碰数据库、不需要 WordPress。
 */
namespace {

	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['opts']  = array();
	$GLOBALS['calls'] = array();
	/** 令牌 => 这个令牌能看到的域名 */
	$GLOBALS['visible'] = array();

	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}

	function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
	function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
	function is_wp_error( $t ) { return $t instanceof \WP_Error; }
	function apply_filters( $tag, $value ) { return $value; }
	function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 200; }
	function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
	function wp_generate_uuid4() { return sprintf( '%08x-aaaa-4bbb-8ccc-%012x', wp_rand(), wp_rand() ); }
	function wp_rand( $min = 0, $max = 0xffffff ) { static $n = 1; return $min + ( ( $n++ * 7919 ) % max( 1, $max - $min + 1 ) ); }

	/** 假的 Cloudflare：按 Authorization 头分辨令牌，只回答这个令牌看得见的东西。 */
	function wp_remote_request( $url, $args = array() ) {
		$token = str_replace( 'Bearer ', '', (string) ( $args['headers']['Authorization'] ?? '' ) );
		$path  = str_replace( 'https://api.cloudflare.com/client/v4', '', (string) $url );
		$GLOBALS['calls'][] = array( 'token' => $token, 'path' => $path );
		$ok = static fn( $result ) => array( 'code' => 200, 'body' => json_encode( array( 'success' => true, 'result' => $result ) ) );
		if ( '/user/tokens/verify' === $path ) {
			return isset( $GLOBALS['visible'][ $token ] )
				? $ok( array( 'status' => 'active' ) )
				: array( 'code' => 403, 'body' => json_encode( array( 'success' => false, 'errors' => array( array( 'code' => 1000, 'message' => 'Invalid API Token' ) ) ) ) );
		}
		if ( str_starts_with( $path, '/zones?name=' ) ) {
			$name  = rawurldecode( substr( $path, strlen( '/zones?name=' ) ) );
			$mine  = $GLOBALS['visible'][ $token ] ?? array();
			return $ok( in_array( $name, $mine, true ) ? array( array( 'id' => 'zone-' . $name, 'name' => $name, 'status' => 'active' ) ) : array() );
		}
		return $ok( array() );
	}

	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}

namespace PeptideHq {

	/** 测试里不做真加密，但保持"存的不是明文"这个形状。 */
	final class Crypto {
		public static function encrypt( string $v ): string { return 'enc:' . base64_encode( $v ); }
		public static function decrypt( string $v ): string { return str_starts_with( $v, 'enc:' ) ? (string) base64_decode( substr( $v, 4 ) ) : ''; }
	}
}

namespace {

	require_once dirname( __DIR__ ) . '/includes/class-cdn.php';

	use PeptideHq\Cdn;

	$LAOMAO  = 'cfut_laomao_token_000000000000000000';
	$GUANGHUI = 'cfut_guanghui_token_00000000000000000';
	$GLOBALS['visible'] = array(
		$LAOMAO   => array( 'korgavex.com' ),
		$GUANGHUI => array( 'lumornea.com' ),
	);

	// ---------- 1. 旧版单令牌的迁移 ----------

	$GLOBALS['opts']['pchq_cdn'] = array( 'token_enc' => 'enc:' . base64_encode( $LAOMAO ), 'enabled' => true, 'verified_at' => 111 );
	$migrated = Cdn::accounts();
	check( '旧版单令牌被当成一个账号', 1 === count( $migrated ) );
	check( '迁移出来的账号编号是 legacy', isset( $migrated['legacy'] ) );
	check( '迁移保留了验证时间', 111 === $migrated['legacy']['verified_at'] );
	check( 'has_token() 对旧版配置仍然为真', Cdn::has_token() );

	// ---------- 2. 添加账号要验证令牌 ----------

	$GLOBALS['opts'] = array();
	check( '空名字被拒绝', is_wp_error( Cdn::save_account( '', '', $LAOMAO ) ) );
	check( '格式不对的令牌被拒绝', is_wp_error( Cdn::save_account( '', '老毛', 'not-a-token' ) ) );
	check( '验证不通过的令牌被拒绝', is_wp_error( Cdn::save_account( '', '老毛', 'cfut_unknown_token_0000000000000000' ) ) );

	check( '老毛的账号保存成功', true === Cdn::save_account( '', '老毛', $LAOMAO ) );
	check( '光辉的账号保存成功', true === Cdn::save_account( '', '光辉', $GUANGHUI ) );
	$accounts = Cdn::accounts();
	check( '现在有两个账号', 2 === count( $accounts ) );
	$labels = array_map( static fn( array $a ): string => $a['label'], array_values( $accounts ) );
	check( '两个账号的名字都在', array( '老毛', '光辉' ) === $labels );
	check( '令牌不是明文存的', ! str_contains( json_encode( $GLOBALS['opts'] ), $LAOMAO ) );

	// ---------- 3. 域名自己认账号 ----------

	$GLOBALS['calls'] = array();
	check( 'korgavex.com 认账号成功', true === Cdn::bind_domain( 'korgavex.com' ) );
	$owner_korgavex = Cdn::domains()['korgavex.com']['account'] ?? '';
	check( 'korgavex.com 记下了所属账号', '' !== $owner_korgavex );
	check( '认出来的是老毛那个账号', '老毛' === $accounts[ $owner_korgavex ]['label'] );
	check( '绑定之后当前账号就是它', $owner_korgavex === Cdn::current_account() );

	check( 'lumornea.com 认账号成功', true === Cdn::bind_domain( 'lumornea.com' ) );
	$owner_lumornea = Cdn::domains()['lumornea.com']['account'] ?? '';
	check( '认出来的是光辉那个账号', '光辉' === $accounts[ $owner_lumornea ]['label'] );
	check( '两个域名认到的不是同一个账号', $owner_korgavex !== $owner_lumornea );

	// 第二次不该再去探测。
	$GLOBALS['calls'] = array();
	check( '再次绑定同一个域名', true === Cdn::bind_domain( 'korgavex.com' ) );
	check( '第二次不再调用 Cloudflare（用记下来的归属）', array() === $GLOBALS['calls'] );

	// ---------- 4. 认不出来要明确报错 ----------

	$res = Cdn::bind_domain( 'dalhoben.com' );
	check( '没有账号能访问的域名返回错误', is_wp_error( $res ) );
	check( '错误码是 no_account', 'pchq_cdn_no_account' === $res->get_error_code() );
	check( '错误里点名了这个域名', str_contains( $res->get_error_message(), 'dalhoben.com' ) );
	check( '认不出来时不会留下错误的归属', ! isset( Cdn::domains()['dalhoben.com']['account'] ) );
	check( '认不出来时当前账号被清空', '' === Cdn::current_account() );

	// ---------- 5. 换令牌 / 删账号会让域名重新认 ----------

	check( '给老毛换一个令牌', true === Cdn::save_account( $owner_korgavex, '老毛', $LAOMAO ) );
	check( '换令牌后 korgavex.com 的归属被清掉', ! isset( Cdn::domains()['korgavex.com']['account'] ) );
	check( '没换令牌的 lumornea.com 归属还在', isset( Cdn::domains()['lumornea.com']['account'] ) );

	Cdn::remove_account( $owner_lumornea );
	check( '删掉光辉的账号后只剩一个', 1 === count( Cdn::accounts() ) );
	check( '删账号后 lumornea.com 的归属也被清掉', ! isset( Cdn::domains()['lumornea.com']['account'] ) );
	check( '现在没有账号能认 lumornea.com', is_wp_error( Cdn::bind_domain( 'lumornea.com' ) ) );

	// 只剩一个账号时也要确认它看得见这个域名，不能想当然。
	$GLOBALS['calls'] = array();
	check( '只剩一个账号时 korgavex.com 仍能绑定', true === Cdn::bind_domain( 'korgavex.com' ) );
	check( '只剩一个账号时仍然探测过一次', 1 === count( $GLOBALS['calls'] ) );
	check( '探测走的是 /zones?name=', str_contains( $GLOBALS['calls'][0]['path'], '/zones?name=' ) );

	echo "PASS {$checks} 多账号 CDN 令牌检查\n";
}
