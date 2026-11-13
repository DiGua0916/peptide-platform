<?php
/**
 * 像素自动检查（Pixels\Health::evaluate / explain）的纯函数测试：不需要 WordPress、数据库或网络。
 * 运行：php wp-content/plugins/peptide-core/tests/pixel-health-pure.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/pixels/class-health.php';

use PeptideCore\Pixels\Health as H;

$n = 0;
function check( string $name, bool $ok ): void {
	global $n;
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: $name\n" );
		exit( 1 );
	}
	++$n;
}

$base = array( 'pixel' => '1234567890', 'total' => 0, 'ok' => 0, 'last_ms' => 0, 'last_ok' => true, 'last_ok_ms' => 0, 'fail_streak' => 0, 'last_error' => '', 'last_code' => 0, 'paid_visits' => 0 );
$token_err = '{"error":{"message":"Error validating access token: Session has expired","type":"OAuthException","code":190,"error_subcode":463}}';
$pixel_err = '{"error":{"message":"Unsupported post request. Object with ID \'123\' does not exist","type":"GraphMethodException","code":100,"error_subcode":33}}';

// 没填像素
check( 'no pixel = none', 'none' === H::evaluate( array( 'pixel' => '' ) + $base )['level'] );

// 正常
$ok = H::evaluate( array( 'total' => 312, 'ok' => 311 ) + $base );
check( 'mostly ok = ok', 'ok' === $ok['level'] && str_contains( $ok['title'], '312' ) && str_contains( $ok['title'], '99%' ) );

// 没有事件
check( 'no events, no ad visitors = idle', 'idle' === H::evaluate( $base )['level'] );
$stuck = H::evaluate( array( 'paid_visits' => 7 ) + $base );
check( 'ad visitors but no events = warn', 'warn' === $stuck['level'] && str_contains( $stuck['title'], '7' ) );

// 令牌失效：失败一次就是 bad
$t = H::evaluate( array( 'total' => 5, 'ok' => 4, 'last_ok' => false, 'fail_streak' => 1, 'last_error' => $token_err, 'last_code' => 400 ) + $base );
check( 'expired token = bad at once', 'bad' === $t['level'] && str_contains( $t['detail'], '令牌' ) && str_contains( $t['title'], '最近一次' ) );

// 像素 ID 不对
$p = H::evaluate( array( 'total' => 3, 'ok' => 0, 'last_ok' => false, 'fail_streak' => 3, 'last_error' => $pixel_err, 'last_code' => 400 ) + $base );
check( 'wrong pixel id = bad', 'bad' === $p['level'] && str_contains( $p['detail'], '像素 ID' ) && str_contains( $p['title'], '3 次' ) );

// 网络错误：一次 = warn，连续两次 = bad
$w1 = H::evaluate( array( 'total' => 10, 'ok' => 9, 'last_ok' => false, 'fail_streak' => 1, 'last_error' => 'cURL error 28: timed out', 'last_code' => 0 ) + $base );
check( 'one network error = warn', 'warn' === $w1['level'] && str_contains( $w1['detail'], '连不上' ) );
$w2 = H::evaluate( array( 'total' => 10, 'ok' => 8, 'last_ok' => false, 'fail_streak' => 2, 'last_error' => 'cURL error 28: timed out', 'last_code' => 0 ) + $base );
check( 'two network errors in a row = bad', 'bad' === $w2['level'] );
check( 'ten or more failures wording', str_contains( H::evaluate( array( 'total' => 40, 'last_ok' => false, 'fail_streak' => 10, 'last_error' => $token_err, 'last_code' => 400 ) + $base )['title'], '10 次以上' ) );

// 失败比例偏高，但最近一次成功
$r = H::evaluate( array( 'total' => 50, 'ok' => 40 ) + $base );
check( '20% failures, last ok = warn', 'warn' === $r['level'] && str_contains( $r['title'], '80%' ) );
check( 'few events with one failure stays ok', 'ok' === H::evaluate( array( 'total' => 5, 'ok' => 4 ) + $base )['level'] );

// 原因归类
check( 'explain token', 'token' === H::explain( $token_err, 400 )['kind'] );
check( 'explain pixel', 'pixel' === H::explain( $pixel_err, 400 )['kind'] );
check( 'explain network', 'network' === H::explain( 'cURL error 6', 0 )['kind'] );
check( 'explain rate limit', 'rate' === H::explain( '{"error":{"code":17}}', 400 )['kind'] );
check( 'explain other keeps a short reason', 'other' === H::explain( '{"error":{"message":"Invalid parameter","code":100}}', 400 )['kind'] );

echo "PASS $n pixel health checks; no WordPress, database, or network.\n";
