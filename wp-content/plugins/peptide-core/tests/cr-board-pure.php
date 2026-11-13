<?php
/**
 * CR 素材归因线 4（看板、漏参提醒、检查点导出、每日上报）纯逻辑测试：
 * 意向分层各条规则（含数据缺失）、访问特征、汇总与指标（0 分母）、样本不足、漏参判断、检查点触发（含版本节点）、
 * 导出 JSON / CSV、上报请求体与 tests/fixtures/creatives/report-sample.json 一致、签名、501 / 失败重试。
 * 数据全是这里造的假数据。不需要 WordPress、数据库或网络。运行：php -n cr-board-pure.php
 */
define( 'ABSPATH', __DIR__ . '/' );
$inc = dirname( __DIR__ ) . '/includes';
require $inc . '/class-hq-connector.php';
foreach ( array( 'contract', 'intent-tiers', 'board', 'export', 'daily-report', 'module', 'board-page' ) as $f ) {
	require $inc . '/creatives/class-' . $f . '.php';
}

use PeptideCore\Creatives\Board;
use PeptideCore\Creatives\Board_Page;
use PeptideCore\Creatives\Contract;
use PeptideCore\Creatives\Daily_Report as D;
use PeptideCore\Creatives\Export;
use PeptideCore\Creatives\Intent_Tiers as I;

$checks = 0;
function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }

// ---------- 意向分层 ----------
$base = array( 'bot' => false, 'ip_type' => 'residential', 'dwell_ms' => 60000, 'pageviews' => 2, 'interactions' => 3, 'timezone' => 'America/New_York', 'language' => 'en-US', 'ip_country' => 'US', 'products' => array( 7 ), 'first_product_pv' => 2, 'visits' => 1 );
check( 'tier: plain visitor is low', I::LOW === I::tier( $base ) );
check( 'tier: empty features = low, not invalid (missing data)', I::LOW === I::tier( array() ) );
check( 'tier: all unknown values = low', I::LOW === I::tier( array( 'bot' => null, 'ip_type' => '', 'dwell_ms' => null, 'interactions' => null, 'timezone' => '', 'language' => '', 'ip_country' => '' ) ) );
check( 'invalid: bot / abnormal', I::INVALID === I::tier( array( 'bot' => true ) + $base ) );
check( 'invalid: hosting ip', I::INVALID === I::tier( array( 'ip_type' => 'hosting' ) + $base ) );
check( 'invalid: vpn ip (case-insensitive)', I::INVALID === I::tier( array( 'ip_type' => 'VPN' ) + $base ) );
check( 'valid: mobile ip', I::LOW === I::tier( array( 'ip_type' => 'mobile' ) + $base ) );
$bounce = array( 'dwell_ms' => 3000, 'interactions' => 0, 'pageviews' => 1, 'products' => array() ) + $base;
check( 'invalid: <5s and no action', I::INVALID === I::tier( $bounce ) );
check( 'bounce: 5s exactly is valid', I::LOW === I::tier( array( 'dwell_ms' => 5000 ) + $bounce ) );
check( 'bounce: scrolled is valid', I::LOW === I::tier( array( 'scrolled' => true ) + $bounce ) );
check( 'bounce: any action is valid', I::LOW === I::tier( array( 'interactions' => 1 ) + $bounce ) );
check( 'bounce: second page is valid', I::LOW === I::tier( array( 'pageviews' => 2 ) + $bounce ) );
check( 'bounce: unknown dwell -> not invalid', I::LOW === I::tier( array( 'dwell_ms' => null ) + $bounce ) );
check( 'bounce: unknown actions -> not invalid', I::LOW === I::tier( array( 'interactions' => null ) + $bounce ) );
check( 'invalid: timezone vs IP continent conflict', I::INVALID === I::tier( array( 'timezone' => 'Asia/Shanghai' ) + $base ) );
check( 'geo: same continent fine', I::LOW === I::tier( array( 'timezone' => 'America/Toronto', 'ip_country' => 'CA' ) + $base ) );
check( 'geo: unknown country -> no judgement', I::LOW === I::tier( array( 'timezone' => 'Asia/Shanghai', 'ip_country' => '' ) + $base ) );
check( 'geo: unlisted country -> no judgement', I::LOW === I::tier( array( 'timezone' => 'Asia/Shanghai', 'ip_country' => 'ZZ' ) + $base ) );
check( 'geo: UTC timezone -> no judgement', I::LOW === I::tier( array( 'timezone' => 'UTC', 'language' => '' ) + $base ) );
check( 'geo: language region used only when timezone missing', I::INVALID === I::tier( array( 'timezone' => '', 'language' => 'zh-CN' ) + $base ) );
check( 'geo: language alone without region -> no judgement', I::LOW === I::tier( array( 'timezone' => '', 'language' => 'zh' ) + $base ) );
check( 'geo: chinese language with US timezone and IP is fine', I::LOW === I::tier( array( 'language' => 'zh-CN' ) + $base ) );
check( 'high: carted', I::HIGH === I::tier( array( 'carted' => true ) + $base ) );
check( 'high: checkout', I::HIGH === I::tier( array( 'checkout' => true ) + $base ) );
$direct = array( 'returning' => true, 'products' => array( 7, 9 ), 'prior_products' => array( 7 ), 'first_product_pv' => 1 ) + $base;
check( 'high: return and go straight to same product', I::HIGH === I::tier( $direct ) );
check( 'direct: not returning -> not high', I::HIGH !== I::tier( array( 'returning' => false ) + $direct ) );
check( 'direct: different product -> not high', I::HIGH !== I::tier( array( 'prior_products' => array( 3 ) ) + $direct ) );
check( 'direct: product found late -> not high', I::HIGH !== I::tier( array( 'first_product_pv' => 4 ) + $direct ) );
check( 'high: org ip + deep browsing', I::HIGH === I::tier( array( 'ip_type' => 'education', 'pageviews' => 6 ) + $base ) );
check( 'org ip without deep browsing is not high', I::LOW === I::tier( array( 'ip_type' => 'business' ) + $base ) );
check( 'mid: 2+ products', I::MID === I::tier( array( 'products' => array( 1, 2 ) ) + $base ) );
check( 'mid: same product twice is still 1 product', I::LOW === I::tier( array( 'products' => array( 1, 1 ) ) + $base ) );
check( 'mid: variant switch', I::MID === I::tier( array( 'variant' => true ) + $base ) );
check( 'mid: lab report / faq', I::MID === I::tier( array( 'report_faq' => true ) + $base ) );
check( 'mid: 3rd visit (returned twice)', I::MID === I::tier( array( 'visits' => 3 ) + $base ) );
check( 'mid: 2nd visit is not enough', I::LOW === I::tier( array( 'visits' => 2 ) + $base ) );
check( 'conflict: mid + high -> high', I::HIGH === I::tier( array( 'variant' => true, 'carted' => true ) + $base ) );
check( 'conflict: invalid beats high', I::INVALID === I::tier( array( 'carted' => true, 'ip_type' => 'vpn' ) + $base ) );
check( 'explain lists rules', array( 'cart_or_checkout', 'org_ip_deep' ) === I::explain( array( 'carted' => true, 'ip_type' => 'edu', 'dwell_ms' => 200000 ) + $base )['rules'] );
// 访客合并
check( 'merge: visitor with a carted visit is high', I::HIGH === I::visitor_tier( array( $base, array( 'carted' => true ) + $base ) ) );
check( 'merge: any datacenter visit -> invalid', I::INVALID === I::visitor_tier( array( $base, array( 'ip_type' => 'hosting', 'carted' => true ) + $base ) ) );
check( 'merge: geo conflict on one visit -> invalid', I::INVALID === I::visitor_tier( array( $base, array( 'timezone' => 'Europe/Paris' ) + $base ) ) );
check( 'merge: direct return on 2nd visit survives merge', I::HIGH === I::visitor_tier( array( $base, $direct ) ) );
check( 'merge: two short visits are not a bounce', I::LOW === I::visitor_tier( array( $bounce, $bounce ) ) );
check( 'merge: single bounce stays invalid', I::INVALID === I::visitor_tier( array( $bounce ) ) );
check( 'merge: unknown bot stays unknown', null === I::merge( array( array( 'bot' => null ), array() ) )['bot'] );

// ---------- 访问特征 ----------
$visit  = array( 'id' => 1, 'start_ms' => 1000, 'last_ms' => 61000, 'stage' => 0, 'ip_type' => 'residential', 'timezone' => 'America/New_York', 'language' => 'en-US', 'pageviews' => 1 );
$events = array(
	array( 'type' => 'page_view', 'path' => '/', 'data' => json_encode( array( 'page_context' => array( 'geo' => array( 'status' => 'known', 'country_code' => 'us' ) ) ) ) ),
	array( 'type' => 'heartbeat' ),
	array( 'type' => 'page_view', 'path' => '/product/bpc-157/' ),
	array( 'type' => 'product_view', 'product_id' => 7 ),
	array( 'type' => 'variant_select', 'product_id' => 7 ),
	array( 'type' => 'module_click', 'data' => array( 'module_type' => 'report-feature' ) ),
);
$vf = Board::visit_features( $visit, $events, 'normal' );
check( 'features: dwell', 60000 === $vf['dwell_ms'] );
check( 'features: pageviews from events', 2 === $vf['pageviews'] );
check( 'features: interactions exclude passive', 3 === $vf['interactions'] );
check( 'features: products and first product page', array( 7 ) === $vf['products'] && 2 === $vf['first_product_pv'] );
check( 'features: variant, report, country', $vf['variant'] && $vf['report_faq'] && 'US' === $vf['ip_country'] );
check( 'features: classification', false === $vf['bot'] && true === Board::visit_features( $visit, array(), 'abnormal' )['bot'] && null === Board::visit_features( $visit, array(), 'unclassified' )['bot'] );
check( 'features: no events -> interactions unknown', null === Board::visit_features( $visit, array(), null )['interactions'] );
check( 'features: faq path', Board::visit_features( $visit, array( array( 'type' => 'page_view', 'path' => '/faq/' ) ), null )['report_faq'] );
check( 'features: stage 2 means carted', Board::visit_features( array( 'stage' => 2 ) + $visit, array(), null )['carted'] );

// ---------- 汇总 ----------
$tz   = 'America/New_York';
$day1 = Board::range_ms( '2026-11-10', '2026-11-10', $tz );
check( 'range_ms: one NY day is 24h', 86400000 === $day1[1] - $day1[0] );
check( 'range_ms: bad input', null === Board::range_ms( '2026-11-31', '2026-12-01', $tz ) && null === Board::range_ms( '2026-11-12', '2026-11-11', $tz ) );
check( 'day(): store timezone', '2026-11-10' === Board::day( $day1[0], $tz ) && '2026-11-10' === Board::day( $day1[1] - 1, $tz ) && '2026-11-11' === Board::day( $day1[1], $tz ) );
$t0  = $day1[0] + 3600000;
$ok  = array( 'bot' => false, 'ip_type' => 'residential', 'dwell_ms' => 90000, 'pageviews' => 3, 'interactions' => 2, 'timezone' => $tz, 'language' => 'en-US', 'ip_country' => 'US', 'products' => array( 5 ), 'first_product_pv' => 2 );
$ad1 = '120210000000000011';
$ad2 = '120210000000000022';
$ad3 = '120210000000000033';
$paid = static fn( int $id, int $vid, int $ms, string $ad, array $extra = array(), array $f = array() ) => array_merge(
	array( 'id' => $id, 'visitor_id' => $vid, 'start_ms' => $ms, 'last_ms' => $ms + 60000, 'source_class' => 'paid', 'utm_source' => 'fb', 'utm_term' => '2200', 'utm_content' => $ad, 'placement' => 'Facebook_Mobile_Feed', 'ad_name' => '', 'creative_code' => '', 'stage' => 0, 'order_id' => 0, 'device' => 'mobile', 'in_app' => 'fb', 'language' => 'en-US', 'timezone' => $tz, 'ip_type' => 'residential', 'geo_region' => 'New York' ),
	$extra,
	array( 'features' => $f + $ok )
);
$visits = array(
	$paid( 1, 101, $t0, $ad1, array( 'creative_code' => 'C0001', 'stage' => 5, 'order_id' => 900 ), array( 'carted' => true, 'checkout' => true ) ),
	$paid( 2, 102, $t0 + 1000, $ad1, array( 'creative_code' => 'C0001' ), array( 'ip_type' => 'hosting' ) ),
	$paid( 3, 103, $t0 + 2000, $ad2, array( 'ad_name' => 'C0002_hook-price_fmt-img_angle-price' ) ),
	$paid( 4, 104, $t0 + 3000, $ad3 ), // 广告表里登记的编号没在素材库 → UNREG
	array( 'id' => 5, 'visitor_id' => 105, 'start_ms' => $t0 + 4000, 'source_class' => 'fb_untagged', 'stage' => 0, 'features' => $ok ),
	// 106：第一次是自然流量（范围前），后来点广告进站
	array( 'id' => 6, 'visitor_id' => 106, 'start_ms' => $t0 - 86400000 * 3, 'source_class' => 'other', 'stage' => 0, 'features' => $ok ),
	$paid( 7, 106, $t0 + 5000, $ad2, array( 'creative_code' => 'C0002', 'stage' => 2 ) ),
	// 107：先 C0001 后 C0002，第二次加购
	$paid( 8, 107, $t0 + 6000, $ad1, array( 'creative_code' => 'C0001' ) ),
	$paid( 9, 107, $t0 + 7000, $ad2, array( 'creative_code' => 'C0002', 'stage' => 3 ) ),
	// 范围之后的访问不算
	$paid( 10, 108, $day1[1] + 1000, $ad1, array( 'creative_code' => 'C0001' ) ),
);
$data = array(
	'visits'    => $visits,
	'visitors'  => array( 101 => array( 'first_visit_id' => 1 ), 106 => array( 'first_visit_id' => 6 ), 107 => array( 'first_visit_id' => 0 ) ),
	'ads'       => array( $ad1 => array( 'code' => 'C0001', 'ad_name' => 'C0001_hook-lab_fmt-vid_angle-purity', 'adset_id' => '2200' ), $ad3 => array( 'code' => 'C0099', 'ad_name' => 'C0099_x', 'adset_id' => '2200' ) ),
	'creatives' => array( 'C0001' => array( 'attrs' => array( 'hook' => 'lab', 'fmt' => 'vid', 'angle' => 'purity', 'lang' => 'en' ), 'thumb' => 'https://s.test/1.jpg' ), 'C0002' => array( 'attrs' => array( 'hook' => 'price', 'fmt' => 'img', 'angle' => 'price' ), 'thumb' => '' ) ),
	'spend'     => array(
		array( 'ad_id' => $ad1, 'day' => '2026-11-10', 'spend_cents' => 5000, 'impressions' => 4000, 'link_clicks' => 40, 'ad_name' => 'C0001_hook-lab_fmt-vid_angle-purity' ),
		array( 'ad_id' => $ad2, 'day' => '2026-11-10', 'spend_cents' => 2000, 'impressions' => 1000, 'link_clicks' => 10, 'ad_name' => 'C0002_hook-price_fmt-img_angle-price' ),
		array( 'ad_id' => '120210000000000044', 'day' => '2026-11-10', 'spend_cents' => 1500, 'impressions' => 900, 'link_clicks' => 25, 'ad_name' => 'C0001_hook-lab_fmt-vid_angle-purity_v2' ),
	),
	'orders'    => array( 900 => 15900 ),
	'labels'    => array( 'hook' => array( 'lab' => '检测报告', 'price' => '价格' ) ),
);
$opt   = array( 'from_ms' => $day1[0], 'to_ms' => $day1[1], 'tz' => $tz );
$first = Board::aggregate( $data, $opt + array( 'mode' => 'first', 'dim' => 'creative' ) );
$rows  = array_column( $first['rows'], null, 'key' );
check( 'first: paid visitors (106 first came organic, 108 out of range)', 5 === $first['paid'] && 5 === $first['total']['visitors'] );
check( 'first: untagged counted separately', 1 === $first['untagged'] );
check( 'first: C0001 gets 101, 102, 107', 3 === $rows['C0001']['visitors'] && 1 === $rows['C0001']['intent']['invalid'] && 2 === $rows['C0001']['valid'] );
check( 'first: 107 behaviour after first ad counts (checkout on 2nd visit)', 2 === $rows['C0001']['checkout'] && 2 === $rows['C0001']['carted'] );
check( 'first: order and sales', 1 === $rows['C0001']['orders'] && 15900 === $rows['C0001']['sales_cents'] && 1 === $rows['C0001']['cashier'] );
check( 'first: code from ad name when visit has none', 1 === $rows['C0002']['visitors'] );
check( 'first: unregistered code -> UNREG', 1 === $rows['UNREG']['visitors'] && '未登记素材' === $rows['UNREG']['label'] );
check( 'first: spend joined by ads table and by spend ad name', 6500 === $rows['C0001']['spend_cents'] && 65 === $rows['C0001']['link_clicks'] && 2000 === $rows['C0002']['spend_cents'] );
check( 'first: thumb carried for creative rows', 'https://s.test/1.jpg' === $rows['C0001']['meta']['thumb'] );
check( 'first: rows sorted by spend', 'C0001' === $first['rows'][0]['key'] );
check( 'metrics: arrival rate = visitors / clicks', abs( $rows['C0001']['arrival_rate'] - 3 / 65 ) < 1e-9 );
check( 'metrics: valid rate', abs( $rows['C0001']['valid_rate'] - 2 / 3 ) < 1e-9 );
check( 'metrics: cart rate over valid visitors', 1.0 === $rows['C0001']['cart_rate'] );
check( 'metrics: cost per valid / high, roas', 3250.0 === $rows['C0001']['cost_per_valid'] && 3250.0 === $rows['C0001']['cost_per_high'] && abs( $rows['C0001']['roas'] - 15900 / 6500 ) < 1e-9 );
check( 'metrics: UNREG with zero spend -> cost 0, roas and arrival null', 0.0 === $rows['UNREG']['cost_per_valid'] && null === $rows['UNREG']['roas'] && null === $rows['UNREG']['arrival_rate'] );
check( 'insufficient: < 30 valid visitors', $rows['C0001']['insufficient'] && $first['total']['insufficient'] );
check( 'drill: visitor ids kept', array( 101, 102, 107 ) === $rows['C0001']['visitor_ids'] );
check( 'total spend counts every ad', 8500 === $first['total']['spend_cents'] );
check( 'ad/day visitor counts for missing-param check', 3 === $first['ad_day_visitors'][ $ad1 . '|2026-11-10' ] && ! isset( $first['ad_day_visitors'][ $ad1 . '|2026-11-11' ] ) );
check( 'audience: by device', 'mobile' === $first['audience']['device'][0]['value'] && 5 === $first['audience']['device'][0]['visitors'] );
check( 'audience: country from page context', 'US' === $first['audience']['country'][0]['value'] && 5 === $first['audience']['country'][0]['visitors'] );
check( 'audience: missing value labelled unknown', 'unknown' === Board::aggregate( array( 'visits' => array( $paid( 50, 150, $t0, $ad1, array( 'device' => '' ) ) ) ), $opt + array( 'mode' => 'first', 'dim' => 'creative' ) )['audience']['device'][0]['value'] );

$last  = Board::aggregate( $data, $opt + array( 'mode' => 'last', 'dim' => 'creative' ) );
$lrows = array_column( $last['rows'], null, 'key' );
check( 'last: 106 counted via its paid visit', 6 === $last['paid'] );
check( 'last: 107 moves to C0002', 2 === $lrows['C0001']['visitors'] && 3 === $lrows['C0002']['visitors'] );
check( 'last: behaviour after the last ad only', 2 === $lrows['C0002']['carted'] );

$attr = Board::aggregate( $data, $opt + array( 'mode' => 'first', 'dim' => 'attr' ) );
$arow = array_column( $attr['rows'], null, 'key' );
check( 'attr: rows per attribute value', 3 === $arow['hook:lab']['visitors'] && 1 === $arow['hook:price']['visitors'] && 1 === $arow['hook:UNREG']['visitors'] );
check( 'attr: every attribute group sums to the total', 5 === array_sum( array_map( static fn( $r ) => str_starts_with( $r['key'], 'fmt:' ) ? $r['visitors'] : 0, $attr['rows'] ) ) );
check( 'attr: vocab label used', '钩子 · 检测报告' === $arow['hook:lab']['label'] );
check( 'attr: missing attribute shows 未填', '语言 · 未填' === $arow['lang:']['label'] );
check( 'attr: spend follows attrs', 6500 === $arow['hook:lab']['spend_cents'] && 6500 === $arow['lang:en']['spend_cents'] );
check( 'attr: grouped in hook, fmt, angle, lang order', str_starts_with( $attr['rows'][0]['key'], 'hook:' ) && str_starts_with( end( $attr['rows'] )['key'], 'lang:' ) );

$ad = Board::aggregate( $data, $opt + array( 'mode' => 'first', 'dim' => 'ad' ) );
$adr = array_column( $ad['rows'], null, 'key' );
check( 'ad: named from ads table', 'C0001_hook-lab_fmt-vid_angle-purity' === $adr[ 'ad:' . $ad1 ]['label'] );
check( 'ad: spend-only ad shows up with no visitors', 0 === $adr['ad:120210000000000044']['visitors'] && 1500 === $adr['ad:120210000000000044']['spend_cents'] );
$adset = Board::aggregate( $data, $opt + array( 'mode' => 'first', 'dim' => 'adset' ) );
check( 'adset: grouped by utm_term', 5 === array_column( $adset['rows'], null, 'key' )['adset:2200']['visitors'] );
$plat = Board::aggregate( $data, $opt + array( 'mode' => 'first', 'dim' => 'platform' ) );
$prow = $plat['rows'][0];
check( 'platform: label and no spend', 'fb / Facebook_Mobile_Feed' === $prow['label'] && null === $prow['spend_cents'] && null === $prow['cost_per_valid'] && null === $prow['roas'] );

// ---------- 指标的 0 分母 ----------
$z = Board::metrics( array( 'visitors' => 0, 'spend_cents' => 0, 'link_clicks' => 0 ) );
foreach ( array( 'arrival_rate', 'valid_rate', 'mid_high_share', 'cart_rate', 'checkout_rate', 'cost_per_valid', 'cost_per_high', 'roas' ) as $k ) {
	check( 'zero denominator -> null: ' . $k, null === $z[ $k ] );
}
$m = Board::metrics( array( 'visitors' => 40, 'intent' => array( 'high' => 0, 'mid' => 10, 'low' => 20, 'invalid' => 10 ), 'spend_cents' => 3000, 'link_clicks' => 50, 'sales_cents' => 0 ) );
check( 'no high-intent visitors -> cost per high null', null === $m['cost_per_high'] && 100.0 === $m['cost_per_valid'] );
check( 'roas 0 when spend but no sales', 0.0 === $m['roas'] );
check( 'mid/high share over valid', abs( $m['mid_high_share'] - 10 / 30 ) < 1e-9 );
check( 'exactly 30 valid is enough', false === $m['insufficient'] && true === Board::metrics( array( 'visitors' => 29 ) )['insufficient'] );
check( 'min_valid adjustable', false === Board::metrics( array( 'visitors' => 5 ), 5 )['insufficient'] );

// ---------- 漏参提醒 ----------
check( 'untagged: 10% exactly is not alerted', null === Board::untagged_alert( 10, 90, 10 ) );
$ua = Board::untagged_alert( 11, 89, 10 );
check( 'untagged: over threshold', null !== $ua && abs( $ua['share'] - 0.11 ) < 1e-9 );
check( 'untagged: none -> no alert', null === Board::untagged_alert( 0, 0, 10 ) && null === Board::untagged_alert( 0, 50, 0 ) );
check( 'untagged: adjustable threshold', null !== Board::untagged_alert( 3, 97, 2.5 ) );
$miss = Board::missing_params( $data['spend'], $first['ad_day_visitors'], 20, $data['ads'] );
check( 'missing: only the spend-only ad with >= 20 clicks', 1 === count( $miss ) && '120210000000000044' === $miss[0]['ad_id'] && 'C0001_hook-lab_fmt-vid_angle-purity_v2' === $miss[0]['ad_name'] );
check( 'missing: ad with 10 clicks and visitors is fine', ! in_array( $ad2, array_column( $miss, 'ad_id' ), true ) );
check( 'missing: raised threshold hides it', array() === Board::missing_params( $data['spend'], $first['ad_day_visitors'], 26 ) );
check( 'missing: ad with clicks but visitors only on another day', 1 === count( Board::missing_params( array( array( 'ad_id' => $ad1, 'day' => '2026-11-11', 'link_clicks' => 30, 'ad_name' => '' ) ), $first['ad_day_visitors'], 20, $data['ads'] ) ) );
check( 'missing: falls back to ads table name', 'C0001_hook-lab_fmt-vid_angle-purity' === Board::missing_params( array( array( 'ad_id' => $ad1, 'day' => '2026-11-11', 'link_clicks' => 30, 'ad_name' => '' ) ), array(), 20, $data['ads'] )[0]['ad_name'] );

// ---------- 检查点 ----------
check( 'checkpoint: nothing below 100', array() === Export::due( 99, '', 0, array() ) );
check( 'checkpoint: 100', array( 'all:100' ) === array_column( Export::due( 100, '', 0, array() ), 'key' ) );
check( 'checkpoint: jump to 350 -> 100 and 300 due together', array( 'all:100', 'all:300' ) === array_column( Export::due( 350, '', 0, array() ), 'key' ) );
check( 'checkpoint: done ones skipped', array( 'all:500' ) === array_column( Export::due( 520, '', 0, array( 'all:100', 'all:300' ) ), 'key' ) );
check( 'checkpoint: none after 1000 without versions', array() === Export::due( 5000, '', 0, array( 'all:100', 'all:300', 'all:500', 'all:1000' ) ) );
$doneall = array( 'all:100', 'all:300', 'all:500', 'all:1000' );
check( 'version: 499 not yet', array() === Export::due( 2000, 'v2', 499, $doneall ) );
check( 'version: 500 in v2', array( 'ver:v2:500' ) === array_column( Export::due( 2000, 'v2', 500, $doneall ), 'key' ) );
check( 'version: 1000 in v2 after 500', array( 'ver:v2:1000' ) === array_column( Export::due( 2600, 'v2', 1000, array_merge( $doneall, array( 'ver:v2:500' ) ) ), 'key' ) );
check( 'version: new version starts again', array( 'ver:v3:500' ) === array_column( Export::due( 3200, 'v3', 600, array_merge( $doneall, array( 'ver:v2:500', 'ver:v2:1000' ) ) ), 'key' ) );
check( 'version: first version 500 coincides with all:500', array( 'all:500', 'ver:v1:500' ) === array_column( Export::due( 500, 'v1', 500, array( 'all:100', 'all:300' ) ), 'key' ) );
check( 'label', '累计 300 访客；版本 v1 满 500 访客' === Export::label( Export::due( 500, 'v1', 500, array( 'all:100', 'all:500' ) ) ) );
$vers = array( array( 'ver' => 'v2', 'since_ms' => 2000 ), array( 'ver' => 'v1', 'since_ms' => 1000 ), array( 'ver' => 'v3', 'since_ms' => 3000 ) );
check( 'version range: middle', array( 2000, 3000 ) === Export::version_range( $vers, 'v2', 9999 ) );
check( 'version range: current runs to now', array( 3000, 9999 ) === Export::version_range( $vers, 'v3', 9999 ) );
check( 'version range: unknown', null === Export::version_range( $vers, 'v9', 9999 ) );
check( 'previous version', 'v2' === Export::previous_version( $vers, 'v3' ) && '' === Export::previous_version( $vers, 'v1' ) );
check( 'export id format', Export::valid_id( '20261111-093000-0123456789ab' ) && ! Export::valid_id( '../etc/passwd' ) );

// ---------- 导出内容 ----------
$ds  = Export::dataset( array( 'name' => 'all', 'label' => '全量', 'from_day' => '2026-11-10', 'to_day' => '2026-11-10', 'from_ms' => $day1[0], 'to_ms' => $day1[1] ), $first, $attr );
$exp = Export::build( array( 'id' => '20261111-093000-0123456789ab', 'kind' => 'checkpoint', 'label' => '累计 100 访客', 'checkpoints' => array( 'all:100' ), 'generated_at' => 1, 'tz' => $tz, 'version' => 'v1' ), $vers, array( $ds ) );
check( 'export: top-level fields', array( 'format', 'id', 'kind', 'label', 'checkpoints', 'generated_at', 'site', 'tz', 'version', 'min_valid', 'intent_tiers', 'version_log', 'datasets' ) === array_keys( $exp ) );
check( 'export: version log sorted', array( 'v1', 'v2', 'v3' ) === array_column( $exp['version_log'], 'ver' ) );
check( 'export: dataset sections', array( 'name', 'label', 'range', 'attribution', 'totals', 'intent', 'untagged', 'audience', 'creatives', 'attributes' ) === array_keys( $ds ) );
check( 'export: no visitor ids leak into the export', ! str_contains( json_encode( $exp ), 'visitor_ids' ) );
check( 'export: creative row has code + thumb', 'C0001' === $ds['creatives'][0]['code'] && 'https://s.test/1.jpg' === $ds['creatives'][0]['thumb'] );
check( 'export: attribute row split', 'hook' === $ds['attributes'][0]['attr'] );
check( 'export: intent totals', 5 === array_sum( $ds['intent'] ) );
$csv = Export::csv_rows( $exp );
check( 'csv: header', Export::CSV_HEAD === $csv[0] );
check( 'csv: every row same width', 1 === count( array_unique( array_map( 'count', $csv ) ) ) );
check( 'csv: sections present', array( 'version_log', 'total', 'untagged', 'creative', 'attribute' ) === array_values( array_unique( array_filter( array_column( $csv, 1 ), static fn( $s ) => ! str_starts_with( $s, 'audience' ) && 'section' !== $s ) ) ) );
check( 'csv: audience rows', in_array( 'audience:device', array_column( $csv, 1 ), true ) );
check( 'csv: null -> blank cell', in_array( '', array_column( array_values( array_filter( $csv, static fn( $r ) => 'UNREG' === $r[2] ) ), 25 ), true ) );
check( 'csv: formula injection neutralised', "'=1+1" === Export::text( '=1+1' ) && "'-x" === Export::text( '-x' ) && 'C0001' === Export::text( 'C0001' ) );

// ---------- 每日上报：请求体和共用样例一致 ----------
$fx       = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/tests/fixtures/creatives/report-sample.json' ), true );
$expected = $fx['expected'];
$c12      = $fx['input']['rows'][0];
$board_row = array( 'key' => 'C0012', 'meta' => array( 'thumb' => '' ), 'intent' => $c12['intent'], 'visitors' => 40, 'valid' => 35, 'carted' => 4, 'checkout' => 2, 'cashier' => 2, 'orders' => 1, 'sales_cents' => 15900, 'spend_cents' => 2350, 'impressions' => 3100, 'link_clicks' => 52 );
$board_unreg = array( 'key' => 'UNREG', 'meta' => array(), 'intent' => array(), 'visitors' => 5, 'valid' => 4, 'carted' => 0, 'checkout' => 0, 'cashier' => 0, 'orders' => 0, 'sales_cents' => 0, 'spend_cents' => null, 'impressions' => null, 'link_clicks' => null );
$library  = array( 'C0012' => array( 'attrs' => $c12['attrs'], 'custom' => array( 'cta' => 'shopnow' ), 'thumb' => $c12['thumb'] ) );
$payload  = Contract::report_payload( $expected['day'], $expected['tz'], D::report_rows( array( $board_row, $board_unreg ), $library ), 7 );
$want     = $expected;
unset( $want['hq_rejected'] );
$want['rows'] = array( $expected['rows'][1], $expected['rows'][2] );
check( 'report: payload from board rows equals the shared fixture rows', $want === $payload );
check( 'report: row keys in fixture order', array_keys( $expected['rows'][0] ) === array_keys( $payload['rows'][0] ) );
$req  = D::request( $payload, 'site-uuid', str_repeat( 'k', 64 ), 'https://hq.example.test/', 1762800000, 'nonce-1' );
$body = json_decode( $req['body'], true );
check( 'report: url uses rest_route', 'https://hq.example.test/?rest_route=%2Fpeptide-hq%2Fv1%2Fcreatives%2Freport' === $req['url'] );
check( 'report: body = nonce + payload', array( 'nonce' => 'nonce-1' ) + $payload === $body && 'nonce' === array_key_first( $body ) );
check( 'report: headers', 'site-uuid' === $req['headers']['X-PC-HQ-Site'] && '1762800000' === $req['headers']['X-PC-HQ-Time'] && 'application/json' === $req['headers']['Content-Type'] );
check( 'report: signature verifies like HQ', hash_equals( hash_hmac( 'sha256', "POST\n/peptide-hq/v1/creatives/report\n\n1762800000\n" . hash( 'sha256', $req['body'] ), str_repeat( 'k', 64 ) ), $req['headers']['X-PC-HQ-Sign'] ) );
check( 'report: unicode and slashes not escaped', ! str_contains( $req['body'], '\\/' ) );

// ---------- 每日上报：重试 ----------
check( 'outcome: 200 done', 'done' === D::outcome( 200 ) );
check( 'outcome: 501 retry (HQ not implemented yet)', 'retry' === D::outcome( 501 ) );
check( 'outcome: network failure retry', 'retry' === D::outcome( null ) );
check( 'outcome: 503 / 429 / 401 retry', 'retry' === D::outcome( 503 ) && 'retry' === D::outcome( 429 ) && 'retry' === D::outcome( 401 ) );
check( 'outcome: 400 / 422 drop', 'drop' === D::outcome( 400 ) && 'drop' === D::outcome( 422 ) );
check( 'backoff: 1h, 2h, 4h ... capped at 1 day', 3600 === D::backoff( 1 ) && 7200 === D::backoff( 2 ) && 57600 === D::backoff( 5 ) && 86400 === D::backoff( 6 ) && 86400 === D::backoff( 50 ) );
$now = 1762800000;
$q   = D::enqueue( array(), array( '2026-11-10', 'bad', '2026-02-30', '2026-11-08' ), $now );
check( 'enqueue: only valid days, sorted', array( '2026-11-08', '2026-11-10' ) === array_keys( $q ) );
$a = D::after( $q, '2026-11-10', 'retry', $now, 'HTTP 501 Creative reports are not stored yet (line 3).' );
check( 'after 501: kept with attempt 1, next in 1h', 1 === $a['queue']['2026-11-10']['attempts'] && $now + 3600 === $a['queue']['2026-11-10']['next'] && ! $a['dropped'] );
check( 'due: retried day waits', array( '2026-11-08' ) === D::due( $a['queue'], $now ) && array( '2026-11-08', '2026-11-10' ) === D::due( $a['queue'], $now + 3600 ) );
$b = D::after( $a['queue'], '2026-11-10', 'retry', $now + 3600, 'network' );
check( 'second failure doubles wait', 2 === $b['queue']['2026-11-10']['attempts'] && $now + 3600 + 7200 === $b['queue']['2026-11-10']['next'] );
check( 'success removes the day', ! isset( D::after( $b['queue'], '2026-11-10', 'done', $now, '' )['queue']['2026-11-10'] ) );
check( 'drop removes and flags', D::after( $b['queue'], '2026-11-10', 'drop', $now, 'HTTP 400' )['dropped'] );
$many = array( '2026-11-10' => array( 'attempts' => D::MAX_ATTEMPTS - 1, 'next' => 0, 'error' => '' ) );
check( 'gives up after MAX_ATTEMPTS', D::after( $many, '2026-11-10', 'retry', $now, 'x' )['dropped'] );
check( 're-enqueue resets attempts (rereport)', 0 === D::enqueue( $b['queue'], array( '2026-11-10' ), $now )['2026-11-10']['attempts'] );
$big = D::enqueue( array(), array_map( static fn( $i ) => sprintf( '2026-10-%02d', $i ), range( 1, 20 ) ), $now );
check( 'due: at most PER_TICK oldest first', D::PER_TICK === count( D::due( $big, $now ) ) && '2026-10-01' === D::due( $big, $now )[0] );
check( 'yesterday in store timezone', '2026-11-09' === D::yesterday( (int) ( $day1[0] / 1000 ) + 3600, $tz ) );

// ---------- 页面参数和格式 ----------
$now2 = (int) ( $day1[0] / 1000 ) + 7200;
$fdef = Board_Page::filters( array(), $tz, $now2 );
// ---------- 建议标签和排序（2026-11-13） ----------
$vr = static fn( array $x ): array => $x + array( 'spend_cents' => 10000, 'orders' => 3, 'roas' => 2.0, 'insufficient' => false, 'visitors' => 40 );
check( 'verdict: roas >= 3 = scale', 'scale' === Board::verdict( $vr( array( 'roas' => 3.0 ) ) ) );
check( 'verdict: between = watch', 'watch' === Board::verdict( $vr( array() ) ) );
check( 'verdict: roas < 1.5 = stop', 'stop' === Board::verdict( $vr( array( 'roas' => 1.2 ) ) ) );
check( 'verdict: spent $100 with no order = stop even when thin', 'stop' === Board::verdict( $vr( array( 'orders' => 0, 'roas' => 0.0, 'insufficient' => true ) ) ) );
check( 'verdict: thin sample = thin', 'thin' === Board::verdict( $vr( array( 'insufficient' => true, 'spend_cents' => 5000 ) ) ) );
check( 'verdict: no spend imported = nospend', 'nospend' === Board::verdict( $vr( array( 'spend_cents' => null, 'roas' => null ) ) ) );
check( 'verdict: custom rules', 'scale' === Board::verdict( $vr( array() ), array( 'scale_roas' => 1.8 ) ) );
$sorted = Board::sort_rows( array( array( 'label' => 'a', 'roas' => 1.0, 'visitors' => 5 ), array( 'label' => 'b', 'roas' => null, 'visitors' => 50 ), array( 'label' => 'c', 'roas' => 4.0, 'visitors' => 9 ), array( 'label' => 'd', 'roas' => 1.0, 'visitors' => 20 ) ), 'roas' );
check( 'sort by roas: high first, null last, ties by visitors', array( 'c', 'd', 'a', 'b' ) === array_column( $sorted, 'label' ) );
check( 'sort by orders', array( 'y', 'x' ) === array_column( Board::sort_rows( array( array( 'label' => 'x', 'orders' => 1 ), array( 'label' => 'y', 'orders' => 3 ) ), 'orders' ), 'label' ) );
check( 'filters: defaults', 'creative' === $fdef['dim'] && 'first' === $fdef['mode'] && '2026-11-10' === $fdef['to'] && '2026-11-04' === $fdef['from'] && '' === $fdef['ver'] );
$fbad = Board_Page::filters( array( 'dim' => 'x', 'mode' => array(), 'from' => '2026-11-09', 'to' => '2026-11-01', 'ver' => '../v1' ), $tz, $now2 );
check( 'filters: bad input falls back', 'creative' === $fbad['dim'] && 'first' === $fbad['mode'] && '2026-11-04' === $fbad['from'] && '' === $fbad['ver'] );
$fok = Board_Page::filters( array( 'dim' => 'ad', 'mode' => 'last', 'from' => '2026-11-01', 'to' => '2026-11-09', 'ver' => 'v1.2' ), $tz, $now2 );
check( 'filters: valid input kept', array( 'dim' => 'ad', 'mode' => 'last', 'from' => '2026-11-01', 'to' => '2026-11-09', 'ver' => 'v1.2', 'sort' => 'roas' ) === $fok );
check( 'filters: sort kept, bad sort falls back', 'orders' === Board_Page::filters( array( 'sort' => 'orders' ), 'UTC', 1794268800 )['sort'] && 'roas' === Board_Page::filters( array( 'sort' => 'x' ), 'UTC', 1794268800 )['sort'] );
check( 'version names', Board_Page::valid_version( 'v2' ) && Board_Page::valid_version( '2026.11-a' ) && ! Board_Page::valid_version( '' ) && ! Board_Page::valid_version( 'v 2' ) && ! Board_Page::valid_version( str_repeat( 'a', 33 ) ) );
check( 'format: dash for null', '—' === Board_Page::format( null, 'pct' ) );
check( 'format: money / pct / x / int', '$23.50' === Board_Page::format( 2350, 'money' ) && '12.5%' === Board_Page::format( 0.125, 'pct' ) && '2.45' === Board_Page::format( 2.4462, 'x' ) && '1,234' === Board_Page::format( 1234, 'int' ) );

echo "PASS {$checks} CR board checks; no WordPress, database, or network.\n";
