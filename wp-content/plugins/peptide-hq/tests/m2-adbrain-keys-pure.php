<?php
/**
 * M2（2026-11-22）：总后台给店铺生成并下发 Ad Brain 接口密钥（Adbrain_Keys）+ 店铺端的校验和指纹（Hq_Connector）。
 *
 * 真的载入 peptide-hq 的 Adbrain_Keys 和 peptide-core 的 Hq_Connector；店铺连接（Client）、加密（Crypto）、
 * 地址（Site_Urls）用内存替身，替身店铺用 Hq_Connector 自己的 adbrain_secret_ok / adbrain_fp 处理下发。覆盖：
 *   1. 店铺端：密钥格式（空 = 关闭、32–256 个可见字符）、指纹和总后台一致；
 *   2. 旧版店铺（没声明 hq.adbrain_secret）不下发；
 *   3. 下发成功：64 位十六进制、加密保存、店铺回报的指纹对上；重新生成换新密钥；
 *   4. 店铺回报的指纹不对 / 店铺用 wp-config 常量 / 连不上：报错且不保存；
 *   5. 关闭：店铺清掉、总后台记录删除；
 *   6. compare 各状态；export 只含已开启的店，地址和站点标识正确。
 *
 * 不碰数据库、不发网络请求、不需要 WordPress：php -n m2-adbrain-keys-pure.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['opts']  = array();
	$GLOBALS['store'] = array(); // site_id => ['secret' => ..., 'constant' => bool, 'tamper' => bool, 'down' => bool]
	$GLOBALS['caps']  = array();
	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { fwrite( STDERR, "FAIL: $name\n" ); exit( 1 ); } }
	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
	function is_wp_error( $x ) { return $x instanceof WP_Error; }
	function add_filter( ...$a ) {}
	function add_action( ...$a ) {}
	class WP_Error {
		private $code; private $msg;
		public function __construct( $code = '', $msg = '', $data = null ) { $this->code = $code; $this->msg = $msg; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->msg; }
	}
	require dirname( __DIR__, 2 ) . '/peptide-core/includes/class-hq-connector.php';
}

namespace PeptideHq {
	final class Crypto {
		public static function encrypt( string $p ): string { return 'enc:' . strrev( base64_encode( $p ) ); }
		public static function decrypt( string $s ): string { return str_starts_with( $s, 'enc:' ) ? (string) base64_decode( strrev( substr( $s, 4 ) ) ) : ''; }
	}
	final class Site_Urls {
		public static function public_url( array $site ): string { return (string) $site['public_url']; }
	}
	/** 替身店铺：按 Hq_Connector 的规则收下发，回报 {enabled, source, fp}。 */
	final class Client {
		public static function supports( int $id, string $cap, string $min = '' ): bool { return isset( $GLOBALS['caps'][ $id ][ $cap ] ); }
		private static function state( int $id ): array {
			$s = $GLOBALS['store'][ $id ] ?? array();
			$secret = (string) ( $s['secret'] ?? '' );
			if ( ! empty( $s['constant'] ) ) { return array( 'enabled' => true, 'source' => 'constant', 'fp' => 'ffffffffffff' ); }
			if ( '' === $secret ) { return array( 'enabled' => false, 'source' => 'none', 'fp' => '' ); }
			$fp = \PeptideCore\Hq_Connector::adbrain_fp( $secret );
			return array( 'enabled' => true, 'source' => 'hq', 'fp' => ! empty( $s['tamper'] ) ? strrev( $fp ) : $fp );
		}
		public static function config( int $id, array $config ) {
			if ( ! empty( $GLOBALS['store'][ $id ]['down'] ) ) { return new \WP_Error( 'pchq_offline', '店铺连不上' ); }
			if ( array_key_exists( 'adbrain_secret', $config ) ) {
				if ( ! \PeptideCore\Hq_Connector::adbrain_secret_ok( (string) $config['adbrain_secret'] ) ) { return new \WP_Error( 'pc_adbrain_secret', 'bad' ); }
				$GLOBALS['store'][ $id ]['secret'] = (string) $config['adbrain_secret'];
			}
			return array( 'adbrain' => self::state( $id ) );
		}
		public static function request( int $id, string $method, string $route, array $q = array(), ?array $body = null, int $timeout = 20 ) {
			return self::config( $id, (array) $body );
		}
	}
}

namespace {
	use PeptideCore\Hq_Connector;
	use PeptideHq\Adbrain_Keys;

	require dirname( __DIR__ ) . '/includes/class-adbrain-keys.php';

	// 1. 店铺端格式和指纹
	check( 'empty = off is valid', Hq_Connector::adbrain_secret_ok( '' ) );
	check( '31 chars rejected', ! Hq_Connector::adbrain_secret_ok( str_repeat( 'a', 31 ) ) );
	check( '32 chars ok', Hq_Connector::adbrain_secret_ok( str_repeat( 'a', 32 ) ) );
	check( '257 chars rejected', ! Hq_Connector::adbrain_secret_ok( str_repeat( 'a', 257 ) ) );
	check( 'space rejected', ! Hq_Connector::adbrain_secret_ok( str_repeat( 'a', 20 ) . ' ' . str_repeat( 'b', 20 ) ) );
	check( 'newline rejected', ! Hq_Connector::adbrain_secret_ok( str_repeat( 'a', 40 ) . "\n" ) );
	check( 'fp same on both sides', Hq_Connector::adbrain_fp( 'x' ) === Adbrain_Keys::fp( 'x' ) && 12 === strlen( Adbrain_Keys::fp( 'x' ) ) );
	$g = Adbrain_Keys::generate_secret();
	check( 'generated is 64 hex and accepted by store', 1 === preg_match( '/^[0-9a-f]{64}$/D', $g ) && Hq_Connector::adbrain_secret_ok( $g ) );
	check( 'generated differs each time', $g !== Adbrain_Keys::generate_secret() );

	// 2. 旧版店铺
	$r = Adbrain_Keys::issue( 1 );
	check( 'old store refused', is_wp_error( $r ) && 'pchq_adbrain_old' === $r->get_error_code() && ! isset( $GLOBALS['store'][1] ) );
	check( 'old store revoke refused', is_wp_error( Adbrain_Keys::revoke( 1 ) ) );
	check( 'old store remote unknown', null === Adbrain_Keys::remote( 1 ) );

	// 3. 下发成功
	foreach ( array( 1, 2, 3, 4, 5 ) as $id ) { $GLOBALS['caps'][ $id ]['hq.adbrain_secret'] = '1'; }
	check( 'issue ok', true === Adbrain_Keys::issue( 1 ) );
	$s1 = Adbrain_Keys::secret( 1 );
	check( 'store got same secret', $s1 === $GLOBALS['store'][1]['secret'] && 64 === strlen( $s1 ) );
	check( 'stored encrypted', ! str_contains( json_encode( $GLOBALS['opts'] ), $s1 ) );
	check( 'record fp', Adbrain_Keys::record( 1 )['fp'] === Adbrain_Keys::fp( $s1 ) );
	check( 'compare on', 'on' === Adbrain_Keys::compare( Adbrain_Keys::remote( 1 ), Adbrain_Keys::record( 1 ) )['state'] );
	check( 'reissue ok', true === Adbrain_Keys::issue( 1 ) );
	check( 'reissue rotates', Adbrain_Keys::secret( 1 ) !== $s1 && Adbrain_Keys::secret( 1 ) === $GLOBALS['store'][1]['secret'] );

	// 4. 失败不保存
	$GLOBALS['store'][2]['tamper'] = true;
	$r = Adbrain_Keys::issue( 2 );
	check( 'fp mismatch error', is_wp_error( $r ) && 'pchq_adbrain_verify' === $r->get_error_code() && null === Adbrain_Keys::record( 2 ) );
	$GLOBALS['store'][3]['constant'] = true;
	$r = Adbrain_Keys::issue( 3 );
	check( 'constant error', is_wp_error( $r ) && 'pchq_adbrain_constant' === $r->get_error_code() && null === Adbrain_Keys::record( 3 ) );
	check( 'compare constant', 'constant' === Adbrain_Keys::compare( Adbrain_Keys::remote( 3 ), null )['state'] );
	$GLOBALS['store'][4]['down'] = true;
	$r = Adbrain_Keys::issue( 4 );
	check( 'offline error', is_wp_error( $r ) && 'pchq_offline' === $r->get_error_code() && null === Adbrain_Keys::record( 4 ) );
	check( 'offline remote unknown', 'unknown' === Adbrain_Keys::compare( Adbrain_Keys::remote( 4 ), null )['state'] );

	// 5. 关闭
	check( 'issue 5', true === Adbrain_Keys::issue( 5 ) );
	check( 'revoke ok', true === Adbrain_Keys::revoke( 5 ) );
	check( 'revoke cleared store', '' === $GLOBALS['store'][5]['secret'] );
	check( 'revoke cleared record', null === Adbrain_Keys::record( 5 ) && '' === Adbrain_Keys::secret( 5 ) );
	check( 'compare off', 'off' === Adbrain_Keys::compare( Adbrain_Keys::remote( 5 ), null )['state'] );

	// 6. compare 其他情况
	check( 'compare lost', 'lost' === Adbrain_Keys::compare( array( 'enabled' => false ), array( 'fp' => 'abc', 'at' => 1 ) )['state'] );
	check( 'compare mismatch', 'mismatch' === Adbrain_Keys::compare( array( 'enabled' => true, 'source' => 'hq', 'fp' => 'abc' ), array( 'fp' => 'abd', 'at' => 1 ) )['state'] );
	check( 'compare mismatch no record', 'mismatch' === Adbrain_Keys::compare( array( 'enabled' => true, 'source' => 'hq', 'fp' => 'abc' ), null )['state'] );

	// export
	$sites = array(
		1 => array( 'name' => 'Store One', 'url' => 'https://admin.one.test', 'public_url' => 'https://www.One.test/' ),
		2 => array( 'name' => '', 'url' => 'https://two.test', 'public_url' => 'https://two.test' ),
		5 => array( 'name' => 'Five', 'url' => 'https://five.test', 'public_url' => 'https://five.test' ),
	);
	$ex = Adbrain_Keys::export( $sites );
	check( 'export only enabled', 1 === count( $ex ) );
	check( 'export fields', 'Store One' === $ex[0]['store'] && 'one.test' === $ex[0]['site'] && 'https://www.One.test/wp-json/peptide/v1/adbrain/' === $ex[0]['base_url'] && Adbrain_Keys::secret( 1 ) === $ex[0]['secret'] );

	echo "m2-adbrain-keys-pure: {$checks} checks passed\n";
}
