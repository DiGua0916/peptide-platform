<?php
/**
 * 数据栏目第 3 步：今日作战室（Admin\Data_Cockpit 的纯函数）。不连数据库：php -n data-cockpit-pure.php
 */
define( 'ABSPATH', __DIR__ );
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $u ) { return (string) $u; }
function admin_url( $p = '' ) { return 'https://store.test/wp-admin/' . $p; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
require dirname( __DIR__ ) . '/includes/admin/class-data-review.php';
require dirname( __DIR__ ) . '/includes/admin/class-data-cockpit.php';

use PeptideCore\Admin\Data_Cockpit as C;
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
$ct  = static fn( string $local ): int => ( new DateTimeImmutable( $local, new DateTimeZone( 'America/Chicago' ) ) )->getTimestamp() * 1000;
$now = $ct( '2026-11-13 18:00' );

// ---------- 时段 ----------
$r = C::range( '24h', $now );
check( '24h is the last 24 hours', $now - R::DAY === $r['from'] && $now === $r['to'] );
$r = C::range( 'today', $now );
check( 'today starts at Chicago midnight', $ct( '2026-11-13 00:00' ) === $r['from'] && $now === $r['to'] && str_contains( $r['label'], '2026-11-13' ) );
$r = C::range( 'yesterday', $now );
check( 'yesterday is the whole previous Chicago day', $ct( '2026-11-12 00:00' ) === $r['from'] && $ct( '2026-11-13 00:00' ) === $r['to'] );
check( 'unknown key falls back to 24h', $now - R::DAY === C::range( 'nope', $now )['from'] );
$dst = C::range( 'yesterday', $ct( '2026-11-02 12:00' ) );
check( 'the DST change day is 25 hours long', 25 * 3600000 === $dst['to'] - $dst['from'] );

// ---------- 花费按小时摊 ----------
$rows = array(
	array( 'ad_id' => 'A', 'day' => '2026-11-12', 'spend_cents' => 2400, 'impressions' => 2400, 'link_clicks' => 24 ),
	array( 'ad_id' => 'A', 'day' => '2026-11-13', 'spend_cents' => 1800, 'impressions' => 1800, 'link_clicks' => 18 ),
);
$w = C::spend_window( $rows, $now - R::DAY, $now, $now );
check( '24h takes the last 6 hours of yesterday plus all of today so far', abs( $w['A']['spend'] - ( 24 * 6 / 24 + 18 ) ) < 1e-9 && abs( $w['A']['clicks'] - ( 6 + 18 ) ) < 1e-9 );
$w = C::spend_window( $rows, $ct( '2026-11-13 00:00' ), $now, $now );
check( 'today gets all of today\'s imported spend', abs( $w['A']['spend'] - 18 ) < 1e-9 );
$w = C::spend_window( $rows, $ct( '2026-11-12 00:00' ), $ct( '2026-11-13 00:00' ), $now );
check( 'yesterday gets yesterday only', abs( $w['A']['spend'] - 24 ) < 1e-9 );
check( 'no overlap, no row', array() === C::spend_window( $rows, $ct( '2026-11-01 00:00' ), $ct( '2026-11-02 00:00' ), $now ) );

// ---------- 提醒 ----------
$p = static fn( array $o = array() ): array => $o + array( 'has_spend' => true, 'spend' => 100.0, 'revenue' => 250.0, 'roas' => 2.5, 'n_orders' => 3, 'visitors' => 100 );
$cr = static fn( array $o = array() ): array => $o + array( 'code' => 'C0001', 'note' => '视频', 'spend' => 20.0, 'orders' => 1, 'revenue' => 50.0, 'roas' => 2.5, 'cpa' => 20.0, 'visitors' => 30, 'cart' => 5, 'clicks' => 35, 'land' => 30 / 35 );
$labels = static fn( array $a ): array => array_map( static fn( $x ) => $x[0], $a );
check( 'healthy period has no alerts', array() === C::alerts( $p(), $p(), array( 'C0001' => $cr() ), array(), array( 'any_spend' => true, 'spend_yesterday' => true, 'pixel' => array( 'level' => 'ok' ) ) ) );
check( 'loss when ROAS under 1 with real spend', array( '亏钱' ) === $labels( C::alerts( $p( array( 'roas' => 0.8, 'revenue' => 80.0 ) ), $p(), array(), array() ) ) );
check( 'no loss alert on small spend', array() === C::alerts( $p( array( 'spend' => 30.0, 'roas' => 0.5, 'revenue' => 15.0 ) ), $p(), array(), array() ) );
$a = C::alerts( $p( array( 'n_orders' => 1 ) ), $p(), array(), array(), array( 'pay' => array( 'failed' => 4, 'abandoned' => 0 ) ) );
check( 'declines, red when they beat paid orders', array( '支付被拒' ) === $labels( $a ) && 'red' === $a[0][1] && str_contains( $a[0][2], '4 笔' ) );
check( 'one decline is not an alert', array() === C::alerts( $p(), $p(), array(), array(), array( 'pay' => array( 'failed' => 1 ) ) ) );
$a = C::alerts( $p( array( 'n_orders' => 1 ) ), $p(), array(), array(), array( 'pay' => array( 'failed' => 0, 'abandoned' => 5 ) ) );
check( 'opened the payment page and never paid', array( '打开支付页没付' ) === $labels( $a ) && str_contains( $a[0][2], '5 个客户' ) );
check( 'abandons below the paid count are not an alert', array() === C::alerts( $p( array( 'n_orders' => 9 ) ), $p(), array(), array(), array( 'pay' => array( 'abandoned' => 4 ) ) ) );

// 口径：付款被拒以后订单会被超时取消，订单状态就不是 failed 了，所以优先看支付尝试。
$att = static fn( array $states ): array => array( 'attempts' => array_map( static fn( $s ) => array( 'state' => $s ), $states ) );
check( 'a decline later cancelled still counts as a decline', 'failed' === C::payment_outcome( $att( array( 'failed' ) ), 'cancelled' ) );
check( 'order status failed with no attempt record', 'failed' === C::payment_outcome( '', 'failed' ) );
check( 'opened the payment page, never came back', 'abandoned' === C::payment_outcome( $att( array( 'created' ) ), 'cancelled' ) );
check( 'paid wins over any earlier decline', 'paid' === C::payment_outcome( $att( array( 'failed', 'paid' ) ), 'cancelled' ) && 'paid' === C::payment_outcome( '', 'processing' ) );
check( 'never reached payment', 'none' === C::payment_outcome( '', 'cancelled' ) && 'none' === C::payment_outcome( array(), 'pending' ) );
check( 'low landing rate on enough clicks', array( '点了没进站' ) === $labels( C::alerts( $p(), $p(), array( 'C0001' => $cr( array( 'clicks' => 50, 'visitors' => 20, 'land' => 0.4 ) ) ), array() ) ) );
check( 'low landing rate needs 20 clicks', array() === C::alerts( $p(), $p(), array( 'C0001' => $cr( array( 'clicks' => 10, 'visitors' => 3, 'land' => 0.3 ) ) ), array() ) );
$fat = array( 'C0001' => array( 'recent' => 0.5, 'before' => 3.0, 'before_orders' => 4, 'recent_spend' => 50.0 ) );
check( 'fatigue alert from the trend', array( '素材疲劳' ) === $labels( C::alerts( $p(), $p(), array( 'C0001' => $cr( array( 'spend' => 120.0, 'orders' => 5, 'revenue' => 300.0, 'roas' => 2.5 ) ) ), $fat ) ) );
check( 'traffic drop against the previous period', array( '流量下跌' ) === $labels( C::alerts( $p( array( 'visitors' => 40 ) ), $p( array( 'visitors' => 100 ) ), array(), array() ) ) );
check( 'no traffic alert on tiny traffic', array() === C::alerts( $p( array( 'visitors' => 2 ) ), $p( array( 'visitors' => 10 ) ), array(), array() ) );
$a = C::alerts( $p(), $p(), array(), array(), array( 'pixel' => array( 'level' => 'bad', 'title' => '像素回传失败：最近 3 次都没发成功', 'detail' => '令牌过期' ) ) );
check( 'pixel failure comes first and is red', array( '像素回传' ) === $labels( $a ) && 'red' === $a[0][1] && str_contains( $a[0][2], '令牌过期' ) );
check( 'pixel idle or not set is not an alert', array() === C::alerts( $p(), $p(), array(), array(), array( 'pixel' => array( 'level' => 'none' ) ) ) );
check( 'missing yesterday spend only when spend was ever imported', array( '花费没导入' ) === $labels( C::alerts( $p(), $p(), array(), array(), array( 'any_spend' => true, 'spend_yesterday' => false ) ) ) && array() === C::alerts( $p( array( 'has_spend' => false ) ), $p(), array(), array(), array( 'any_spend' => false ) ) );

// ---------- 正在逛的人 ----------
$live = array(
	array( 'id' => 9, 'visitor_id' => 7, 'last_ms' => $now - 4 * 60000, 'stage' => 3, 'creative_code' => 'C0001', 'utm_source' => 'fb', 'referrer_host' => '', 'device' => 'mobile', 'os' => 'iOS', 'geo_region' => 'Texas', 'geo_city' => 'Austin', 'in_app' => 'fb', 'cart' => '{"count":2,"subtotal_text":"$120.00"}', 'visits' => 2 ),
	array( 'id' => 8, 'visitor_id' => 7, 'last_ms' => $now - 20 * 60000, 'stage' => 1, 'creative_code' => '', 'utm_source' => '', 'referrer_host' => '', 'device' => 'mobile', 'os' => 'iOS', 'geo_region' => '', 'geo_city' => '', 'in_app' => '', 'cart' => null, 'visits' => 2 ),
	array( 'id' => 5, 'visitor_id' => 3, 'last_ms' => $now, 'stage' => 0, 'creative_code' => '', 'utm_source' => '', 'referrer_host' => 'www.google.com', 'device' => 'desktop', 'os' => '', 'geo_region' => '<b>x</b>', 'geo_city' => '', 'in_app' => '', 'cart' => '', 'visits' => 1 ),
);
$h = C::live_html( $live, $now );
check( 'one line per visitor', 2 === substr_count( $h, '<li' ) );
check( 'checkout visitor is highlighted with cart and details link', str_contains( $h, 'class="is-hot"' ) && str_contains( $h, '购物车 2 件 $120.00' ) && str_contains( $h, '素材 C0001 · iOS（FB 内） · Austin, Texas · 第 2 次来 · 4 分钟前' ) && str_contains( $h, 'admin.php?page=pc-visitors&visitor=7' ) );
check( 'organic visitor shows the referrer and is escaped', str_contains( $h, 'www.google.com · desktop · &lt;b&gt;x&lt;/b&gt; · 新客 · 刚刚' ) );
check( 'nobody online', str_contains( C::live_html( array(), $now ), '最近 30 分钟没有人' ) );

echo "PASS {$passed} data cockpit assertions\n";
