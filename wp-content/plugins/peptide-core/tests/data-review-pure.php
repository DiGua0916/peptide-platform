<?php
/**
 * 数据栏目第 2 步：复盘页的计算（Admin\Data_Review 的纯函数）。不连数据库：php -n data-review-pure.php
 */
define( 'ABSPATH', __DIR__ );
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $u ) { return (string) $u; }
function admin_url( $p = '' ) { return 'https://store.test/wp-admin/' . $p; }
function add_query_arg( array $a, string $u ) { return $u . '?' . http_build_query( $a ); }
require dirname( __DIR__ ) . '/includes/tracking/class-plain-words.php';
require dirname( __DIR__ ) . '/includes/admin/class-data-review.php';

use PeptideCore\Admin\Data_Review as R;

$passed = 0;
function check( string $name, bool $ok ): void {
	global $passed;
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: {$name}\n" );
		exit( 1 );
	}
	++$passed;
}

// ---------- 时间按美国中部 ----------
$now = ( new DateTimeImmutable( '2026-11-13 22:30:00', new DateTimeZone( 'America/Chicago' ) ) )->getTimestamp() * 1000;
$r7  = R::range( 7, $now );
check( '7 days start at Chicago midnight six days ago', '2026-11-07' === R::ymd( $r7['from'] ) && R::day_start( '2026-11-07' ) === $r7['from'] && $now === $r7['to'] );
check( 'day list covers seven Chicago dates', array( '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13' ) === R::days( $r7 ) );
check( 'previous period has the same length and ends where this one starts', $r7['from'] === R::previous( $r7 )['to'] && ( $r7['to'] - $r7['from'] ) === ( R::previous( $r7 )['to'] - R::previous( $r7 )['from'] ) );
$beijing_morning = ( new DateTimeImmutable( '2026-11-14 09:00:00', new DateTimeZone( 'Asia/Shanghai' ) ) )->getTimestamp() * 1000;
check( 'a Beijing morning is still the previous US day', '2026-11-13' === R::ymd( $beijing_morning ) );

// ---------- 数据 ----------
$t   = static fn( string $local ): int => ( new DateTimeImmutable( $local, new DateTimeZone( 'America/Chicago' ) ) )->getTimestamp() * 1000;
$vis = static function ( int $id, int $visitor, string $at, int $stage, string $code = '', string $adset = '', array $extra = array() ) use ( $t ): array {
	return array_merge( array( 'id' => $id, 'visitor_id' => $visitor, 'start_ms' => $t( $at ), 'stage' => $stage, 'order_id' => 0, 'utm_source' => '' !== $code ? 'fb' : '', 'utm_term' => $adset, 'utm_content' => '', 'creative_code' => $code, 'placement' => '' !== $code ? 'Facebook_Mobile_Feed' : '', 'device' => 'mobile', 'in_app' => '' !== $code ? 'fb' : '', 'os' => 'iOS', 'geo_region' => 'Texas', 'landing_path' => '/shop/?cr=' . $code, 'referrer_host' => '', 'first_visit_id' => $id ), $extra );
};
$visits = array(
	$vis( 1, 101, '2026-11-12 20:10', 5, 'C0001', 'AS1' ),
	$vis( 2, 102, '2026-11-12 21:00', 2, 'C0001', 'AS1' ),
	$vis( 3, 102, '2026-11-12 21:30', 3, 'C0001', 'AS1', array( 'first_visit_id' => 2 ) ), // 同一个访客第二次，漏斗只算一次
	$vis( 4, 103, '2026-11-13 08:00', 1, 'C0002', 'AS2' ),
	$vis( 5, 104, '2026-11-13 09:00', 0, 'C0002', 'AS2' ),
	$vis( 6, 105, '2026-11-13 10:00', 5, '', '', array( 'first_visit_id' => 1 ) ), // 回访直接下单，无素材
	$vis( 7, 106, '2026-11-13 11:00', 3 ), // 自然流量，填完地址（事件）
);
$order = static fn( int $id, float $total, string $at, ?array $visit, ?array $first = null ): array => array( 'id' => $id, 'total' => $total, 'paid' => $t( $at ), 'visit' => $visit, 'first' => $first, 'items' => array( array( 'pid' => 9, 'name' => 'BPC-157', 'qty' => 2, 'total' => $total ) ) );
$orders = array(
	$order( 501, 200.0, '2026-11-12 20:30', $visits[0] ),
	$order( 502, 100.0, '2026-11-13 10:05', $visits[5], array( 'creative_code' => 'C0001', 'utm_term' => 'AS1' ) ),
	$order( 503, 50.0, '2026-11-13 12:00', null ), // 没追踪到访问
);
$spend = array(
	array( 'ad_id' => 'A1', 'day' => '2026-11-12', 'spend_cents' => 6000, 'impressions' => 3000, 'link_clicks' => 4 ),
	array( 'ad_id' => 'A1', 'day' => '2026-11-13', 'spend_cents' => 4000, 'impressions' => 2000, 'link_clicks' => 2 ),
	array( 'ad_id' => 'A2', 'day' => '2026-11-13', 'spend_cents' => 5000, 'impressions' => 2500, 'link_clicks' => 10 ),
	array( 'ad_id' => 'A2', 'day' => '2026-11-01', 'spend_cents' => 99900, 'impressions' => 1, 'link_clicks' => 1 ), // 时段外
);
$ads = array( 'A1' => array( 'code' => 'C0001', 'adset' => 'AS1', 'campaign' => 'CP', 'name' => '' ), 'A2' => array( 'code' => 'C0002', 'adset' => 'AS2', 'campaign' => 'CP', 'name' => '' ) );
$raw = array( 'range' => $r7, 'visits' => $visits, 'address' => array( 7 => true ), 'orders' => $orders, 'spend' => $spend );
$p   = R::compute( $raw );

check( 'spend outside the period is ignored', abs( $p['spend'] - 150.0 ) < 0.001 && $p['has_spend'] );
check( 'revenue and order count', 350.0 === $p['revenue'] && 3 === $p['n_orders'] );
check( 'roas, cpa, aov', abs( $p['roas'] - 350 / 150 ) < 1e-9 && abs( $p['cpa'] - 50.0 ) < 1e-9 && abs( $p['aov'] - 350 / 3 ) < 1e-9 );
check( 'funnel deduplicates visitors and keeps the furthest step', array( 'visitors' => 6, 'view' => 5, 'cart' => 4, 'checkout' => 4, 'address' => 3, 'paid' => 3 ) === $p['funnel'] );
check( 'conversion rate is orders over visitors', abs( $p['cvr'] - 0.5 ) < 1e-9 );

$daily = R::daily_with_spend( $p, $spend );
check( 'daily buckets by Chicago date', 7 === count( $daily ) && 60.0 === $daily['2026-11-12']['spend'] && 200.0 === $daily['2026-11-12']['revenue'] && 90.0 === $daily['2026-11-13']['spend'] && 150.0 === $daily['2026-11-13']['revenue'] && 2 === $daily['2026-11-13']['orders'] && 0.0 === $daily['2026-11-07']['spend'] );

// ---------- 素材 ----------
check( 'order source prefers the order visit', array( 'C0001', 'AS1' ) === R::order_source( $orders[0] ) );
check( 'returning order falls back to first click', array( 'C0001', 'AS1' ) === R::order_source( $orders[1] ) );
check( 'untracked order has no source', array( '', '' ) === R::order_source( $orders[2] ) );
$cr = R::by_creative( $p, $ads, array( 'C0001' => array( 'note' => '检测报告视频', 'thumb' => '' ) ) );
check( 'creatives sorted by spend', array( 'C0001', 'C0002' ) === array_keys( $cr ) );
check( 'C0001 numbers', 100.0 === $cr['C0001']['spend'] && 2 === $cr['C0001']['visitors'] && 2 === $cr['C0001']['cart'] && 2 === $cr['C0001']['orders'] && 300.0 === $cr['C0001']['revenue'] && 3.0 === $cr['C0001']['roas'] && 50.0 === $cr['C0001']['cpa'] && '检测报告视频' === $cr['C0001']['note'] );
check( 'landing rate = visitors / link clicks', 0.2 === $cr['C0002']['land'] && 0.0 === $cr['C0002']['revenue'] && abs( $cr['C0001']['land'] - 2 / 6 ) < 1e-9 );

// ---------- 建议 ----------
$row = static fn( float $spend, int $orders, float $revenue, int $visitors = 50, int $cart = 10 ): array => array( 'spend' => $spend, 'orders' => $orders, 'revenue' => $revenue, 'roas' => $spend > 0 ? $revenue / $spend : null, 'cpa' => $orders && $spend > 0 ? $spend / $orders : null, 'visitors' => $visitors, 'cart' => $cart );
check( 'no spend imported', '没有花费' === R::verdict( $row( 0, 2, 100 ) )[0] );
check( 'small sample', '样本不足' === R::verdict( $row( 30, 1, 40 ) )[0] );
check( 'spent without orders', '关掉' === R::verdict( $row( 60, 0, 0 ) )[0] );
check( 'high roas scales', '加预算' === R::verdict( $row( 100, 5, 300 ) )[0] );
check( 'low roas stops, low add-to-cart blames the audience', array( '关掉', 'red' ) === array_slice( R::verdict( $row( 100, 3, 80, 100, 3 ) ), 0, 2 ) && str_contains( R::verdict( $row( 100, 3, 80, 100, 3 ) )[2], '素材吸引的人不对' ) );
check( 'middle roas watches', '观察' === R::verdict( $row( 100, 4, 180 ) )[0] );
$fatigue = array( 'recent' => 0.8, 'before' => 2.6, 'before_orders' => 5, 'recent_spend' => 45.0 );
check( 'fatigue beats the overall roas', '疲劳·换素材' === R::verdict( $row( 300, 10, 700 ), $fatigue )[0] );
check( 'fatigue needs enough earlier orders', '观察' === R::verdict( $row( 300, 10, 700 ), array( 'before_orders' => 2 ) + $fatigue )[0] );
$tr = R::trends( array( 'C0001' => array( 'roas' => 1.0, 'spend' => 40.0, 'orders' => 1 ) ), array( 'C0001' => array( 'roas' => 3.0, 'orders' => 4 ) ) );
check( 'trends pair recent with before', array( 'recent' => 1.0, 'before' => 3.0, 'before_orders' => 4, 'recent_spend' => 40.0 ) === $tr['C0001'] );

// ---------- 按维度看 ----------
$adset = R::dim_rows( $p, 'adset', $ads, array( 'AS1' => '广泛受众' ) );
check( 'adset rows carry spend and first-click orders', 100.0 === $adset['广泛受众']['spend'] && 2 === $adset['广泛受众']['orders'] && 3.0 === $adset['广泛受众']['roas'] );
check( 'unnamed adset shows the id tail', isset( $adset['广告组 …AS2'] ) && 50.0 === $adset['广告组 …AS2']['spend'] );
check( 'untracked order gets its own row', 1 === $adset['（没追踪到访问）']['orders'] );
$newret = R::dim_rows( $p, 'newret' );
check( 'new vs returning', 5 === $newret['新客（第一次来）']['visitors'] && 2 === $newret['回访']['visitors'] && 1 === $newret['回访']['orders'] );
$hours = R::dim_rows( $p, 'hour' );
check( 'hours sorted by clock and in Chicago time', '08:00–08:59' === array_key_first( $hours ) && isset( $hours['20:00–20:59'] ) );
$week = R::dim_rows( $p, 'weekday' );
check( 'weekdays sorted Monday first', array( '周四', '周五' ) === array_keys( array_filter( $week, static fn( $r ) => $r['visitors'] > 0 ) ) );
$src = R::dim_rows( $p, 'source' );
check( 'source splits ads and direct', 4 === $src['fb（广告）']['visitors'] && 2 === $src['直接打开']['visitors'] );
$land = R::dim_rows( $p, 'landing' );
check( 'landing drops the query string', 6 === $land['/shop/']['visitors'] );

// ---------- 漏斗、结论 ----------
check( 'worst step against the benchmark', 'cart' === R::worst_step( array( 'visitors' => 100, 'view' => 50, 'cart' => 5, 'checkout' => 3, 'address' => 2, 'paid' => 1 ) ) );
check( 'no judgement when the previous step has under 3 people', null === R::worst_step( array( 'visitors' => 2, 'view' => 0, 'cart' => 0, 'checkout' => 0, 'address' => 0, 'paid' => 0 ) ) );
$lines = R::conclusions( $p, $cr, array() );
check( 'three conclusions with spend', 3 === count( $lines ) && str_contains( $lines[0], 'ROAS <b>2.33</b>' ) && str_contains( $lines[0], '整体在赚钱' ) && str_contains( $lines[1], 'C0001（检测报告视频）' ) );
$nospend = R::compute( array( 'spend' => array() ) + $raw );
$lines   = R::conclusions( $nospend, R::by_creative( $nospend, $ads, array() ), array() );
check( 'without spend the page says so instead of guessing', null === $nospend['roas'] && null === $nospend['cpa'] && ! $nospend['has_spend'] && str_contains( $lines[0], '还没导入广告花费' ) && str_contains( $lines[1], '导入花费后' ) );
$xss = R::conclusions( $p, R::by_creative( $p, $ads, array( 'C0001' => array( 'note' => '<script>x</script>', 'thumb' => '' ) ) ), array() );
check( 'creative notes are escaped in conclusions', ! str_contains( implode( '', $xss ), '<script>' ) );

// ---------- 小工具 ----------
check( 'delta up is good', array( '▲ 50%', 'good' ) === R::delta( 150, 100 ) );
check( 'delta down is good for cost', array( '▼ 50%', 'good' ) === R::delta( 50, 100, false ) );
check( 'delta without previous', 'flat' === R::delta( 5, 0 )[1] && 'flat' === R::delta( null, 3 )[1] );
check( 'money and pct', '$1,235' === R::money( 1234.5 ) && '—' === R::money( null ) && '12.5%' === R::pct( 0.125 ) );

// ---------- 支付结果（店主 2026-11-14：哪个时段容易失败） ----------
require_once dirname( __DIR__ ) . '/includes/admin/class-data-cockpit.php';
$pv = static fn( string $at, array $extra = array() ): array => array_merge( $vis( 1, 1, $at, 3, '', '' ), $extra );
$pay = array(
	array( 'outcome' => 'paid',      'created_ms' => $t( '2026-11-13 09:10' ), 'total' => 120.0, 'visit' => $pv( '2026-11-13 09:00', array( 'geo_region' => 'Texas', 'isp' => 'CHARTER-20115', 'ip_type' => 'residential' ) ) ),
	array( 'outcome' => 'failed',    'created_ms' => $t( '2026-11-13 21:10' ), 'total' => 300.0, 'visit' => $pv( '2026-11-13 21:00', array( 'geo_region' => 'Texas', 'isp' => 'M247 Europe SRL', 'ip_type' => 'vpn' ) ) ),
	array( 'outcome' => 'failed',    'created_ms' => $t( '2026-11-13 21:40' ), 'total' => 300.0, 'visit' => $pv( '2026-11-13 21:30', array( 'geo_region' => 'Texas', 'isp' => 'M247 Europe SRL', 'ip_type' => 'vpn' ) ) ),
	array( 'outcome' => 'abandoned', 'created_ms' => $t( '2026-11-13 21:50' ), 'total' => 90.0,  'visit' => $pv( '2026-11-13 21:45', array( 'geo_region' => 'New York', 'isp' => '', 'ip_type' => '' ) ) ),
	array( 'outcome' => 'paid',      'created_ms' => $t( '2026-11-13 09:40' ), 'total' => 450.0, 'visit' => null ),
);
$byh = R::payment_by( $pay, 'hour' );
check( 'payments grouped by the hour the visit started', 2 === $byh['09:00–09:59']['total'] && 2 === $byh['09:00–09:59']['paid'] && 3 === $byh['21:00–21:59']['total'] && 0 === $byh['21:00–21:59']['paid'] );
check( 'hours sorted, success rate and money not collected', '09:00–09:59' === array_key_first( $byh ) && 1.0 === $byh['09:00–09:59']['rate'] && 0.0 === $byh['09:00–09:59']['lost'] && 690.0 === $byh['21:00–21:59']['lost'] );
check( 'an order with no tracked visit still lands in its hour', 2 === $byh['09:00–09:59']['paid'] );
$byn = R::payment_by( $pay, 'network' );
check( 'VPN orders group together and all failed', 2 === $byn['M247 Europe SRL · VPN']['total'] && 2 === $byn['M247 Europe SRL · VPN']['failed'] && 0.0 === $byn['M247 Europe SRL · VPN']['rate'] );
check( 'a missing visit is its own row', 1 === $byn['查不到（没追踪到访问）']['total'] );
$bya = R::payment_by( $pay, 'amount' );
check( 'amount buckets in order', array( '$100 以下', '$100–200', '$200–400', '$400 以上' ) === array_keys( $bya ) );
check( 'the big-ticket bucket', 1 === $bya['$400 以上']['paid'] && 2 === $bya['$200–400']['failed'] );
$bys = R::payment_by( $pay, 'state' );
check( 'states are shown in Chinese', isset( $bys['德州'] ) && isset( $bys['纽约州'] ) && 3 === $bys['德州']['total'] );
$html = R::payment_html( $pay, 'hour', 'pc-data-review', array( 'range' => '7d' ) );
check( 'the worst hour is flagged', str_contains( $html, 'is-bad-row' ) && str_contains( $html, '21:00–21:59' ) && str_contains( $html, '最差的是「21:00–21:59」' ) );
check( 'totals shown above the table', str_contains( $html, '>2<' ) && str_contains( $html, '被拒' ) && str_contains( $html, '打开支付页没付' ) );
check( 'no payments at all says so', str_contains( R::payment_html( array(), 'hour', 'p', array() ), '没有进到支付这一步' ) );

// ---------- 客户维度 ----------
$cv = array( $vis( 1, 101, '2026-11-12 20:10', 2, '', '', array( 'geo_region' => 'Texas', 'geo_city' => 'Houston', 'isp' => 'T-MOBILE-AS21928', 'ip_type' => 'mobile', 'language' => 'es-419' ) ) );
$cp = R::compute( array( 'range' => $r7, 'visits' => $cv, 'address' => array(), 'orders' => array(), 'spend' => array() ) );
check( 'city row carries the state', isset( R::dim_rows( $cp, 'city' )['德州 Houston'] ) );
check( 'network row says what kind of connection', isset( R::dim_rows( $cp, 'network' )['T-Mobile · 手机网络'] ) );
check( 'language row in Chinese', isset( R::dim_rows( $cp, 'language' )['西班牙语（拉美）'] ) );

echo "PASS {$passed} data review assertions\n";
