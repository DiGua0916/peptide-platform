<?php
/**
 * 素材人群画像和规则分析（2026-11-13）：纯函数，不需要 WordPress、数据库或网络。
 * 运行：php wp-content/plugins/peptide-core/tests/cr-audience-pure.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
$inc = dirname( __DIR__ ) . '/includes';
foreach ( array( 'contract', 'intent-tiers', 'audience', 'board', 'audience-ai' ) as $f ) {
	require $inc . '/creatives/class-' . $f . '.php';
}

use PeptideCore\Creatives\Audience;
use PeptideCore\Creatives\Audience_Ai;

$n = 0;
function check( string $label, bool $ok ): void {
	global $n;
	if ( ! $ok ) {
		throw new RuntimeException( 'FAIL ' . $label );
	}
	++$n;
}

// ---------- 取值 ----------
check( 'model: region code folded', 'Galaxy S23' === Audience::model_name( 'Galaxy S23（SM-S911U）' ) );
check( 'tier: iPhone Pro Max high', '高端' === Audience::tier( 'iOS', 'iPhone 16 Pro Max', 'mobile' ) );
check( 'tier: iPhone Air high', '高端' === Audience::tier( 'iOS', 'iPhone Air', 'mobile' ) );
check( 'tier: iPhone 15 mid', '中端' === Audience::tier( 'iOS', 'iPhone 15', 'mobile' ) );
check( 'tier: iPhone 11 entry', '入门' === Audience::tier( 'iOS', 'iPhone 11', 'mobile' ) );
check( 'tier: Safari iPhone unknown', '' === Audience::tier( 'iOS', 'iPhone', 'mobile' ) );
check( 'tier: S24 Ultra high', '高端' === Audience::tier( 'Android', 'Galaxy S24 Ultra', 'mobile' ) );
check( 'tier: A15 entry', '入门' === Audience::tier( 'Android', 'Galaxy A15', 'mobile' ) );
check( 'tier: desktop', '电脑' === Audience::tier( 'Windows', 'PC', 'desktop' ) );
check( 'browser: in-app first', 'FB 内置' === Audience::browser( 'Mozilla Safari/604.1', 'fb' ) && 'IG 内置' === Audience::browser( '', 'ig' ) );
check( 'browser: iOS Chrome', 'Chrome' === Audience::browser( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) CriOS/131 Mobile Safari/604.1', '' ) );
check( 'browser: Samsung before Chrome', '三星浏览器' === Audience::browser( 'Mozilla/5.0 (Linux; Android 14) SamsungBrowser/26.0 Chrome/122 Mobile Safari/537.36', '' ) );
check( 'browser: Safari', 'Safari' === Audience::browser( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) Version/18.1 Mobile/15E148 Safari/604.1', '' ) );
check( 'screen: normalised', '390×844' === Audience::screen( '390x844' ) && '' === Audience::screen( 'big' ) );
$k = Audience::keys( array( 'os' => 'iOS', 'os_version' => '18.1.1', 'device_model' => 'iPhone 15 Pro', 'in_app' => 'fb', 'geo_country' => 'us', 'language' => 'en-US', 'device' => 'mobile', 'ip_type' => 'residential' ) );
check( 'keys: os version major', 'iOS 18' === $k['os_version'] );
check( 'keys: country upper, language base', 'US' === $k['country'] && 'en' === $k['language'] );
check( 'keys: missing -> unknown', 'unknown' === $k['city'] && 'unknown' === $k['screen'] );
check( 'keys: every dim present', array_keys( Audience::DIMS ) === array_keys( $k ) );
check( 'label: Chinese names', '美国' === Audience::label( 'country', 'US' ) && '家庭宽带' === Audience::label( 'ip_type', 'residential' ) && '未知' === Audience::label( 'os', 'unknown' ) );

// ---------- 合成数据 ----------
// C0001：240 人，iPhone 180（Pro 120 / 普通 60）、安卓 60。iPhone Pro 下单多；安卓加购正常但付款很少（卡在付款）。
// C0002：200 人，安卓 150、iPhone 50，质量一般。
$acc = array();
$add = static function ( string $code, array $visit, string $tier, bool $cart, bool $checkout, int $orders_cents, array $beh ) use ( &$acc ): void {
	static $oid = 1000;
	$orders = array();
	if ( $orders_cents > 0 ) {
		$orders[ ++$oid ] = $orders_cents;
	}
	Audience::add( $acc, $code, Audience::keys( $visit ), $tier, array( 'carted' => $cart, 'checkout' => $checkout, 'orders' => $orders ), $beh );
};
$ios_pro = array( 'os' => 'iOS', 'os_version' => '18.1', 'device_model' => 'iPhone 16 Pro', 'in_app' => 'fb', 'geo_country' => 'US', 'geo_region' => 'California', 'language' => 'en-US', 'device' => 'mobile', 'ip_type' => 'residential', 'placement' => 'Facebook_Mobile_Feed' );
$ios     = array( 'device_model' => 'iPhone 13' ) + $ios_pro;
$and     = array( 'os' => 'Android', 'os_version' => '14', 'device_model' => 'Galaxy A15（SM-A155F）', 'in_app' => 'fb', 'geo_country' => 'US', 'geo_region' => 'Texas', 'language' => 'en-US', 'device' => 'mobile', 'ip_type' => 'mobile', 'placement' => 'Facebook_Mobile_Feed' );
$good    = array( 'dwell_ms' => 120000, 'pageviews' => 3, 'scroll_depth' => 100 );
$meh     = array( 'dwell_ms' => 30000, 'pageviews' => 1, 'scroll_depth' => 25 );
for ( $i = 0; $i < 120; $i++ ) { // iPhone Pro：18 单
	$add( 'C0001', $ios_pro, $i < 70 ? 'high' : 'low', $i < 30, $i < 22, $i < 18 ? 18600 : 0, $good );
}
for ( $i = 0; $i < 60; $i++ ) { // 普通 iPhone：3 单
	$add( 'C0001', $ios, $i < 20 ? 'mid' : 'low', $i < 8, $i < 5, $i < 3 ? 12800 : 0, $good );
}
for ( $i = 0; $i < 60; $i++ ) { // 安卓：加购 12、进结账 10、只付 1 单
	$add( 'C0001', $and, $i < 15 ? 'mid' : 'low', $i < 12, $i < 10, 0 === $i ? 9400 : 0, $good );
}
for ( $i = 0; $i < 150; $i++ ) {
	$add( 'C0002', $and, $i < 10 ? 'mid' : ( $i < 140 ? 'low' : 'invalid' ), $i < 5, $i < 3, $i < 2 ? 9000 : 0, $meh );
}
for ( $i = 0; $i < 50; $i++ ) {
	$add( 'C0002', $ios, 'low', $i < 2, $i < 1, 0, $meh );
}
// C0003：只有 40 人（样本不足）。
for ( $i = 0; $i < 40; $i++ ) {
	$add( 'C0003', $ios, 'mid', $i < 4, $i < 2, $i < 1 ? 10000 : 0, $good );
}

$p = Audience::finish( $acc );
check( 'finish: per creative and ALL', isset( $p['C0001'], $p['C0002'], $p['C0003'], $p[ Audience::ALL ] ) );
check( 'finish: ALL counts everyone', 480 === $p[ Audience::ALL ]['total']['visitors'] );
check( 'finish: C0001 visitors', 240 === $p['C0001']['total']['visitors'] );
$os = $p['C0001']['dims']['os'];
check( 'finish: sorted by visitors', 'iOS' === $os[0]['value'] && 180 === $os[0]['visitors'] && abs( $os[0]['share'] - 0.75 ) < 1e-9 );
check( 'finish: orders and sales per value', 21 === $os[0]['orders'] && 18 * 18600 + 3 * 12800 === $os[0]['sales_cents'] );
check( 'finish: invalid excluded from funnel', 0 === $p['C0002']['dims']['os'][0]['carted'] - 5 && 10 === $p['C0002']['total']['invalid'] );
check( 'finish: behaviour averages', 120 === $p['C0001']['dims']['tier'][0]['dwell_s'] || 120 === $p['C0001']['total']['dwell_s'] );
check( 'finish: model folded across region codes', 'Galaxy A15' === $p['C0002']['dims']['model'][0]['value'] );
check( 'finish: rates', abs( (float) $p['C0001']['total']['order_rate'] - 22 / 240 ) < 1e-9 );

// ---------- 规则分析 ----------
$a = Audience::analyse( $p['C0001'], $p[ Audience::ALL ] );
check( 'analyse: confidence mid for 240', 'mid' === $a['confidence'] );
check( 'analyse: common has iOS', (bool) array_filter( $a['common'], static fn( $s ) => str_contains( $s, '系统：iOS' ) ) );
check( 'analyse: common has US', (bool) array_filter( $a['common'], static fn( $s ) => str_contains( $s, '国家：美国' ) ) );
check( 'analyse: diff shows iOS above average', (bool) array_filter( $a['diff'], static fn( $d ) => str_contains( $d['text'], '系统 iOS' ) && 'up' === $d['dir'] ) );
check( 'analyse: buyers table has segments', count( $a['buyers'] ) >= 3 );
$names = array_column( $a['buyers'], 'mark', 'name' );
check( 'analyse: high-end marked good', 'good' === ( $names['高端机型'] ?? '' ) );
check( 'analyse: Android marked bad', 'bad' === ( $names['Android 用户'] ?? '' ) );
check( 'analyse: buyer note names best segment', str_contains( $a['buyer_notes'][0]['text'] ?? '', '高端机型' ) );
$stuck = array_column( $a['stuck'], 'step', 'seg' );
check( 'analyse: Android stuck at pay', 'pay' === ( $stuck['Android 用户'] ?? '' ) );
$kinds = array_column( $a['advice'], 'kind' );
check( 'analyse: scale advice', in_array( 'scale', $kinds, true ) );
check( 'analyse: check-then-spend advice for Android', in_array( 'check', $kinds, true ) && (bool) array_filter( $a['advice'], static fn( $x ) => 'check' === $x['kind'] && str_contains( $x['text'], 'Android' ) ) );
check( 'analyse: clone advice when quality high', in_array( 'clone', $kinds, true ) );
check( 'analyse: quality above average', '高于平均' === $a['quality'][0]['level'] );
check( 'analyse: headline mentions iOS and weak Android', str_contains( $a['headline'], 'iOS' ) && str_contains( $a['headline'], '；Android 用户' ) && str_contains( $a['headline'], '转化明显差' ) );
check( 'analyse: identical segments reported once', 1 === count( array_filter( $a['stuck'], static fn( $x ) => '入门机型' === $x['seg'] || 'Android 用户' === $x['seg'] ) ) );
check( 'analyse: every item has evidence', ! array_filter( array_merge( $a['who'], $a['quality'], $a['stuck'], $a['advice'] ), static fn( $x ) => '' === (string) ( $x['ev'] ?? '' ) ) );

$b = Audience::analyse( $p['C0002'], $p[ Audience::ALL ] );
check( 'analyse C0002: quality below average', '低于平均' === $b['quality'][0]['level'] );
check( 'analyse C0002: no clone advice', ! in_array( 'clone', array_column( $b['advice'], 'kind' ), true ) );
check( 'analyse C0002: Android above average share', (bool) array_filter( $b['diff'], static fn( $d ) => str_contains( $d['text'], '系统 Android' ) && 'up' === $d['dir'] ) );

$c = Audience::analyse( $p['C0003'], $p[ Audience::ALL ] );
check( 'analyse: thin sample -> no conclusions', 'low' === $c['confidence'] && '' === $c['headline'] && ! $c['advice'] && str_contains( $c['confidence_text'], '不到 100' ) );

// ---------- AI 事实和输出 ----------
$facts = Audience_Ai::facts( 'C0001', array( 'spend_cents' => 50000, 'orders' => 22, 'sales_cents' => 400000, 'roas' => 8.0 ), $p['C0001'], $p[ Audience::ALL ], array( 'from' => '2026-11-01', 'to' => '2026-11-13', 'mode' => 'first', 'ver' => '' ), array( 'hook' => 'lab' ), array( 'hook' => array( 'lab' => '实验室报告' ) ) );
$json  = json_encode( $facts, JSON_UNESCAPED_UNICODE );
check( 'facts: sections', array( '素材', '投放结果', '这条素材合计', '这条素材人群画像', '全部素材合计（平均参照）', '全部素材人群画像（平均参照）', '规则结论', '说明' ) === array_keys( $facts ) );
check( 'facts: attrs in Chinese', '实验室报告' === $facts['素材']['属性']['钩子'] );
check( 'facts: all dims present', count( $facts['这条素材人群画像'] ) === count( Audience::DIMS ) );
check( 'facts: no visitor ids or IPs', ! str_contains( $json, 'visitor_id' ) && ! preg_match( '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $json ) );
check( 'facts: small payload', strlen( $json ) < 200000 );
$html = Audience_Ai::html( "## 1. 来的是什么人\n- **iPhone** 用户为主 <script>x</script>\n普通一行" );
check( 'ai html: heading, bold, list, escaped', str_contains( $html, '<h4>' ) && str_contains( $html, '<b>iPhone</b>' ) && str_contains( $html, 'class="is-li"' ) && ! str_contains( $html, '<script>' ) );

echo 'PASS ' . $n . " creative audience checks; no WordPress, database, or network.\n";
