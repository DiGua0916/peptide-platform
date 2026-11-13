<?php
/**
 * 机型识别（Tracking\User_Agent::parse）纯函数测试：不需要 WordPress、数据库或网络。
 * 运行：php wp-content/plugins/peptide-core/tests/tracking-user-agent-pure.php
 */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/tracking/class-user-agent.php';

use PeptideCore\Tracking\User_Agent as U;

$n = 0;
function check( string $name, bool $ok ): void {
	global $n;
	if ( ! $ok ) { fwrite( STDERR, "FAIL: $name\n" ); exit( 1 ); }
	++$n;
}
$is = static fn( array $r, string $os, string $ver, string $model ): bool => $r['os'] === $os && $r['os_version'] === $ver && $r['model'] === $model;

check( 'FB in-app iPhone: model from FBDV', $is( U::parse( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/480.0.0.42.110;FBDV/iPhone15,3;FBMD/iPhone;FBSN/iOS;FBSV/18.1]' ), 'iOS', '18.1', 'iPhone 14 Pro Max' ) );
check( 'IG in-app iPhone: model from Instagram block', $is( U::parse( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 350.0.0.31.98 (iPhone14,5; iOS 18_0; en_US; en; scale=3.00; 1170x2532; 640000000)' ), 'iOS', '18.0', 'iPhone 13' ) );
check( 'Safari iPhone: only generic model', $is( U::parse( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1' ), 'iOS', '17.6.1', 'iPhone' ) );
check( 'unknown Apple code kept as code', 'iPhone99,1' === U::parse( 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_0 like Mac OS X) Mobile/15E148 [FBAN/FBIOS;FBDV/iPhone99,1]' )['model'] );
check( 'iPad', $is( U::parse( 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' ), 'iPadOS', '17.5', 'iPad' ) );
check( 'FB in-app Android: Samsung code to name', $is( U::parse( 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.100 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/480.0.0.40.109;]' ), 'Android', '14', 'Galaxy S23 Ultra（SM-S918B）' ) );
check( 'IG Android: Pixel kept', $is( U::parse( 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36 Instagram 350.0.0.31.98 Android' ), 'Android', '14', 'Pixel 8' ) );
check( 'reduced Chrome UA uses client hint', $is( U::parse( 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', '"SM-A546E"' ), 'Android', '10', 'Galaxy A54（SM-A546E）' ) );
check( 'reduced Chrome UA without hint: no model', '' === U::parse( 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 Chrome/130.0.0.0 Mobile Safari/537.36' )['model'] );
check( 'Windows 11 via platform version', $is( U::parse( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36', '', '"15.0.0"' ), 'Windows', '11', 'PC' ) );
check( 'Windows without hint: 10/11', '10/11' === U::parse( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0.0.0 Safari/537.36' )['os_version'] );
check( 'Mac', $is( U::parse( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36' ), 'macOS', '', 'Mac' ) );
check( 'empty UA', $is( U::parse( '' ), '', '', '' ) );

echo "PASS $n user agent checks; no WordPress, database, or network.\n";
