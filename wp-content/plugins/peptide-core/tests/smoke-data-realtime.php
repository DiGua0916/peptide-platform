<?php
/**
 * 实时数据页（DATA-01 第 6 步）冒烟测试：最近 10 分钟的访客、访问量、订单、销售额、客单数、客单价（和今日合计）、
 * 访客行为漏斗（10 / 20 分钟）、每分钟访客数量（10 / 20 分钟，窗口边界、服务端状态事件不算）、今日热门商品和页面、
 * funnel / minutes 参数校验、GET /peptide/v1/data/realtime 接口和权限、页面脚本的加载和时间段工具栏开关。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-data-realtime.php
 * 测试数据都放在固定的历史时刻（店铺时区 2020-06-10 12:00:00 当作“现在”，传给 Realtime::summary()），不受站点上其他数据影响；
 * 需要访客追踪的表已安装；测试创建的订单、用户、访客、访问和事件在结束时彻底删除（用 Privacy::delete_visitors() 清理）。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Data_Pages;
use PeptideCore\Data\Operations;
use PeptideCore\Data\Realtime;
use PeptideCore\Tracking\Privacy;
use PeptideCore\Tracking\Schema;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};
// 金额和比例：差不到 0.01 就算相等；null 只等于 null。
$eq = static function ( $got, $want ): bool {
	if ( null === $want || null === $got ) {
		return null === $want && null === $got;
	}
	return abs( (float) $got - (float) $want ) < 0.01;
};

global $wpdb;
$get_backup = $_GET;
$admin      = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );

// 店铺时区的某个时刻 => Unix 秒。
$at = static fn( string $local ) => ( new DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();
$ms = static fn( string $local ) => $at( $local ) * 1000;

if ( ! Schema::installed() ) {
	echo "FAIL tracking tables not installed\n";
	return;
}

// 商品：第一个已发布、价格大于 0、不是演示商品的简单商品。
$product = null;
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $candidate ) {
	if ( (float) $candidate->get_price() > 0 && '1' !== get_post_meta( $candidate->get_id(), \PeptideCore\Meta::DEMO_SEED, true ) ) {
		$product = $candidate;
		break;
	}
}
if ( ! $product ) {
	echo "FAIL fixture product\n";
	return;
}
$pid = (int) $product->get_id();

// “现在”：2020-06-10 12:00:00（店铺时区）。
$now = $ms( '2020-06-10 12:00:00' );

// 访客 W1–W4 和各自的访问。
$visitor_ids = array();
$visit_ids   = array();
$track       = static function ( int $n, string $start, string $last, int $stage ) use ( $wpdb, $ms, &$visitor_ids, &$visit_ids ): array {
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'visitors' ),
		array(
			'vid'      => md5( 'smoke-rt-' . $n . microtime() ),
			'first_ms' => $ms( $start ),
			'last_ms'  => $ms( $last ),
			'visits'   => 1,
			'device'   => 'desktop',
			'browser'  => 'Chrome',
			'is_test'  => 1,
		)
	);
	$visitor_id    = (int) $wpdb->insert_id;
	$visitor_ids[] = $visitor_id;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'visits' ),
		array(
			'visitor_id'   => $visitor_id,
			'start_ms'     => $ms( $start ),
			'last_ms'      => $ms( $last ),
			'landing_path' => '/rt-a/',
			'device'       => 'desktop',
			'pageviews'    => 1,
			'stage'        => $stage,
			'is_test'      => 1,
		)
	);
	$visit_id    = (int) $wpdb->insert_id;
	$visit_ids[] = $visit_id;
	return array( $visitor_id, $visit_id );
};
list( $w1, $v1 ) = $track( 1, '2020-06-10 11:52:30', '2020-06-10 11:58:30', 3 );
list( $w2, $v2 ) = $track( 2, '2020-06-10 11:40:30', '2020-06-10 11:59:30', 5 );
list( $w3, $v3 ) = $track( 3, '2020-06-10 11:30:30', '2020-06-10 11:45:30', 1 );
list( $w4, $v4 ) = $track( 4, '2020-06-10 09:00:30', '2020-06-10 09:10:30', 2 );

// 事件。
$event_ids = array();
$event     = static function ( int $visitor_id, int $visit_id, string $type, string $source, string $local, string $path = '', int $product_id = 0, string $data = '' ) use ( $wpdb, $ms, &$event_ids ): void {
	$time = $ms( $local );
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'events' ),
		array(
			'event_uid'   => 'smoke-rt-' . count( $event_ids ) . '-' . md5( microtime() . $local . $type . $path ),
			'visitor_id'  => $visitor_id,
			'visit_id'    => $visit_id,
			'type'        => $type,
			'source'      => $source,
			'occurred_ms' => $time,
			'received_ms' => $time,
			'path'        => $path,
			'product_id'  => $product_id,
			'data'        => '' === $data ? null : $data,
			'is_test'     => 1,
		)
	);
	$event_ids[] = (int) $wpdb->insert_id;
};
// W1：页面浏览、查看商品、加购（服务端）、心跳、订单状态（服务端，每分钟图要忽略）。
$event( $w1, $v1, 'page_view', 'client', '2020-06-10 11:52:30', '/rt-a/' );
$event( $w1, $v1, 'product_view', 'client', '2020-06-10 11:53:30', '', $pid );
$event( $w1, $v1, 'add_to_cart', 'server', '2020-06-10 11:55:30', '', $pid, '{"quantity":2}' );
$event( $w1, $v1, 'heartbeat', 'client', '2020-06-10 11:58:30' );
$event( $w1, $v1, 'order_status', 'server', '2020-06-10 11:57:30' );
// W2：两次页面浏览。
$event( $w2, $v2, 'page_view', 'client', '2020-06-10 11:40:30', '/rt-a/' );
$event( $w2, $v2, 'page_view', 'client', '2020-06-10 11:59:30', '/rt-b/' );
// W3：页面浏览、查看商品、心跳。
$event( $w3, $v3, 'page_view', 'client', '2020-06-10 11:30:30', '/rt-b/' );
$event( $w3, $v3, 'product_view', 'client', '2020-06-10 11:31:30', '', $pid );
$event( $w3, $v3, 'heartbeat', 'client', '2020-06-10 11:45:30' );

// 订单（不带追踪信息，游客）。
$make_order = static function ( int $qty, string $status, string $created, ?callable $setup = null ) use ( $product, $at ): WC_Order {
	$order = wc_create_order();
	$order->add_product( $product, $qty );
	$order->set_customer_id( 0 );
	if ( $setup ) {
		$setup( $order );
	}
	$order->calculate_totals();
	$order->set_status( $status );
	$order->set_date_created( $at( $created ) );
	$order->save();
	return $order;
};
$o1 = $make_order( 1, 'processing', '2020-06-10 11:57:30' );
$o2 = $make_order( 2, 'completed', '2020-06-10 10:00:00' );
$o3 = $make_order(
	5,
	'processing',
	'2020-06-10 11:58:30',
	static function ( WC_Order $o ): void {
		$o->set_payment_method( 'cod' );
		$o->set_payment_method_title( 'Test payment – no money is charged' );
	}
);
$o4 = $make_order( 1, 'pending', '2020-06-10 11:56:30' );
$t1 = (float) $o1->get_total();
$t2 = (float) $o2->get_total();

$s = Realtime::summary( $now, 10, 10 );

// 1. 卡片（最近 10 分钟）。
$c = $s['cards'] ?? array();
$ok( 'cards: 2 visitors, 2 page views, 1 order, window 10', 2 === ( $c['visitors'] ?? null ) && 2 === ( $c['pageviews'] ?? null ) && 1 === ( $c['orders'] ?? null ) && 10 === ( $c['window'] ?? null ) );
$ok( 'cards: sales = t1, units per order 1.0, average order value = t1 (test and unpaid orders left out)', $eq( $c['sales'] ?? null, $t1 ) && $eq( $c['units_per_order'] ?? null, 1.0 ) && $eq( $c['aov'] ?? null, $t1 ) );

// 2. 今日。
$d = $s['today'] ?? array();
$ok( 'today: 4 visitors, 4 page views, 2 orders', 4 === ( $d['visitors'] ?? null ) && 4 === ( $d['pageviews'] ?? null ) && 2 === ( $d['orders'] ?? null ) );
$ok( 'today: sales = t1 + t2, units per order 1.5, average order value = (t1 + t2) / 2', $eq( $d['sales'] ?? null, $t1 + $t2 ) && $eq( $d['units_per_order'] ?? null, 1.5 ) && $eq( $d['aov'] ?? null, ( $t1 + $t2 ) / 2 ) );

// 3. 漏斗（10 分钟）。
$layers = $s['funnel']['layers'] ?? array();
$ok( 'funnel 10: counts 2 / 2 / 2 / 2 / 1, keys in Operations::LAYERS order, window 10', array( 2, 2, 2, 2, 1 ) === array_column( $layers, 'count' ) && array_column( $layers, 'key' ) === Operations::LAYERS && 10 === ( $s['funnel']['window'] ?? null ) );

// 4. 漏斗（20 分钟）。
$s20    = Realtime::summary( $now, 20, 10 );
$layers = $s20['funnel']['layers'] ?? array();
$ok( 'funnel 20: counts 3 / 3 / 2 / 2 / 1', array( 3, 3, 2, 2, 1 ) === array_column( $layers, 'count' ) && 20 === ( $s20['funnel']['window'] ?? null ) );

// 5. 每分钟（10 分钟）：服务端的 order_status 不算。
$points = $s['minutes']['points'] ?? array();
$ok( 'minutes 10: 10 points from ago 9 down to 0', 10 === count( $points ) && range( 9, 0 ) === array_column( $points, 'ago' ) && 10 === ( $s['minutes']['window'] ?? null ) );
$ok( 'minutes 10: visitors by ago 9..0 = 0,0,1,1,0,1,0,0,1,1 (order_status at 11:57:30 is ignored)', array( 0, 0, 1, 1, 0, 1, 0, 0, 1, 1 ) === array_column( $points, 'visitors' ) );

// 6. 每分钟（20 分钟）。
$s_m20    = Realtime::summary( $now, 10, 20 );
$points20 = $s_m20['minutes']['points'] ?? array();
$by20     = array();
foreach ( $points20 as $p ) {
	$by20[ $p['ago'] ] = $p['visitors'];
}
$ok( 'minutes 20: 20 points, ago 14 = 1 (W3 heartbeat) and ago 19 = 1 (W2 page view at 11:40:30)', 20 === count( $points20 ) && 1 === ( $by20[14] ?? null ) && 1 === ( $by20[19] ?? null ) );

// 7. 今日列表。
$lists = $s['lists'] ?? array();
$ok( 'lists viewed: P first with 2 views, with name and edit link', $pid === ( $lists['viewed'][0]['id'] ?? 0 ) && 2 === ( $lists['viewed'][0]['count'] ?? 0 ) && $product->get_name() === ( $lists['viewed'][0]['name'] ?? '' ) && '' !== ( $lists['viewed'][0]['edit_url'] ?? '' ) );
$ok( 'lists carted: P with 1', $pid === ( $lists['carted'][0]['id'] ?? 0 ) && 1 === ( $lists['carted'][0]['count'] ?? 0 ) );
$ok( 'lists sold: P with 3 units (the test order is left out)', $pid === ( $lists['sold'][0]['id'] ?? 0 ) && 3 === ( $lists['sold'][0]['count'] ?? 0 ) );
$pages = $lists['pages'] ?? array();
$ok( 'lists pages: /rt-a/ 2 then /rt-b/ 2 (tie by path), urls start with http', 2 === count( $pages ) && '/rt-a/' === ( $pages[0]['path'] ?? '' ) && 2 === ( $pages[0]['count'] ?? 0 ) && '/rt-b/' === ( $pages[1]['path'] ?? '' ) && 2 === ( $pages[1]['count'] ?? 0 ) && 0 === strpos( (string) ( $pages[0]['url'] ?? '' ), 'http' ) && 0 === strpos( (string) ( $pages[1]['url'] ?? '' ), 'http' ) );

// 8. 更新时间（店铺时区）和“查看详情”链接。
$ok( 'updated starts with 2020-06-10 12:00 and the links point at the product and page reports for today', 0 === strpos( (string) ( $s['updated'] ?? '' ), '2020-06-10 12:00' ) && false !== strpos( (string) ( $s['links']['products'] ?? '' ), 'page=pc-data-products&range=today' ) && false !== strpos( (string) ( $s['links']['pages'] ?? '' ), 'page=pc-data-pages&range=today' ) && true === ( $s['tracking'] ?? null ) && $now === ( $s['now_ms'] ?? null ) );

// 9. 窗口边界和不算的事件类型（在 1–8 之后再加数据）。
$event( $w3, $v3, 'heartbeat', 'client', '2020-06-10 11:50:00' );
$event( $w4, $v4, 'heartbeat', 'client', '2020-06-10 11:51:00' );
$event( $w4, $v4, 'heartbeat', 'client', '2020-06-10 11:59:00' );
$event( $w4, $v4, 'heartbeat', 'client', '2020-06-10 12:00:00' );
$event( $w2, $v2, 'payment_success', 'server', '2020-06-10 11:56:30' );
$sb      = Realtime::summary( $now, 10, 10 );
$pb      = $sb['minutes']['points'] ?? array();
$by_edge = array();
foreach ( $pb as $p ) {
	$by_edge[ $p['ago'] ] = $p['visitors'];
}
$ok( 'boundaries: still 10 points, ago 9 down to 0', 10 === count( $pb ) && range( 9, 0 ) === array_column( $pb, 'ago' ) );
$ok( 'boundaries: ago 9 = 1 (W4 at 11:51:00 is the right edge of (11:50, 11:51]; W3 at exactly 11:50:00 is outside the window)', 1 === ( $by_edge[9] ?? null ) );
$ok( 'boundaries: ago 1 = 2 (W1 at 11:58:30 plus W4 at 11:59:00) and ago 0 = 2 (W2 at 11:59:30 plus W4 exactly at now)', 2 === ( $by_edge[1] ?? null ) && 2 === ( $by_edge[0] ?? null ) );
$ok( 'boundaries: ago 3 = 0 (payment_success is not visitor activity), ago 7, 6 and 4 still 1', 0 === ( $by_edge[3] ?? null ) && 1 === ( $by_edge[7] ?? null ) && 1 === ( $by_edge[6] ?? null ) && 1 === ( $by_edge[4] ?? null ) );
$ok( 'boundaries: page views still 2', 2 === ( $sb['cards']['pageviews'] ?? null ) );

// 10. 参数校验。
$none = Realtime::params( array() );
$both = Realtime::params( array( 'funnel' => '20', 'minutes' => '30' ) );
$bad1 = Realtime::params( array( 'funnel' => '15' ) );
$bad2 = Realtime::params( array( 'minutes' => 'abc' ) );
$ok( 'params: no params give 10 / 10, funnel=20 and minutes=30 give 20 / 30', array( 'funnel' => 10, 'minutes' => 10 ) === $none && array( 'funnel' => 20, 'minutes' => 30 ) === $both );
$ok( 'params: funnel=15 is pc_invalid_param with fields.funnel invalid_format', is_wp_error( $bad1 ) && 'pc_invalid_param' === $bad1->get_error_code() && 'invalid_format' === ( $bad1->get_error_data()['fields']['funnel'] ?? '' ) );
$ok( 'params: minutes=abc is pc_invalid_param with fields.minutes invalid_format', is_wp_error( $bad2 ) && 'pc_invalid_param' === $bad2->get_error_code() && 'invalid_format' === ( $bad2->get_error_data()['fields']['minutes'] ?? '' ) );

// 11. 接口（管理员）。
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/realtime' );
$res = rest_do_request( $req );
$rd  = $res->get_data();
$ok( 'admin can load the real-time data (200) with cards, today, funnel, minutes and lists', 200 === $res->get_status() && is_array( $rd ) && isset( $rd['cards'], $rd['today'], $rd['funnel'], $rd['minutes'], $rd['lists'] ) );
$ok( 'real-time response is not cached', 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/realtime' );
$req->set_param( 'funnel', '99' );
$ok( 'funnel=99 returns 400', 400 === rest_do_request( $req )->get_status() );

// 12. 接口（顾客不能看）。
$customer = wp_insert_user(
	array(
		'user_login' => 'smoke_rt_customer',
		'user_email' => 'smoke-rt-customer@example.com',
		'user_pass'  => wp_generate_password(),
		'role'       => 'customer',
	)
);
$customer = is_wp_error( $customer ) ? 0 : (int) $customer;
wp_set_current_user( $customer );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/realtime' );
$ok( 'customers cannot load the real-time data', $customer > 0 && in_array( rest_do_request( $req )->get_status(), array( 401, 403 ), true ) );

// 13. 页面脚本只在实时数据页加载，时间段工具栏只在实时数据页关掉。
wp_set_current_user( $admin->ID );
$handles = array( 'peptide-core-data', 'peptide-core-data-operations', 'peptide-core-data-products', 'peptide-core-data-pages', 'peptide-core-data-conversion', 'peptide-core-data-realtime' );
$last    = static function ( string $handle ): string {
	$before = (array) wp_scripts()->get_data( $handle, 'before' );
	return (string) end( $before );
};
$_GET['page'] = 'pc-data';
Data_Pages::enqueue();
$ok( 'real-time script and style are enqueued on the real-time page, its strings arrive before it loads', wp_script_is( 'peptide-core-data-realtime', 'enqueued' ) && wp_style_is( 'peptide-core-data-realtime', 'enqueued' ) && false !== strpos( $last( 'peptide-core-data-realtime' ), 'window.pcDataRealtime' ) && false !== strpos( $last( 'peptide-core-data-realtime' ), 'Last updated' ) );
$ok( 'the shared data script gets "showRange":false on the real-time page', false !== strpos( $last( Data_Pages::SCRIPT ), '"showRange":false' ) );
foreach ( array( 'peptide-core-data-realtime', Data_Pages::SCRIPT ) as $handle ) {
	wp_dequeue_script( $handle );
}
$_GET['page'] = 'pc-data-pages';
Data_Pages::enqueue();
$ok( 'the real-time script is not enqueued on the page visits page, and the shared script gets "showRange":true', ! wp_script_is( 'peptide-core-data-realtime', 'enqueued' ) && wp_script_is( 'peptide-core-data-pages', 'enqueued' ) && false !== strpos( $last( Data_Pages::SCRIPT ), '"showRange":true' ) );
foreach ( $handles as $handle ) {
	wp_dequeue_script( $handle );
	wp_dequeue_style( $handle );
}

// 14. 清理：订单、临时用户，再用 Privacy::delete_visitors() 删访客和他们的事件、访问。
foreach ( array( $o1, $o2, $o3, $o4 ) as $order ) {
	$saved = wc_get_order( $order->get_id() );
	if ( $saved ) {
		$saved->delete( true );
	}
}
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( $customer > 0 ) {
	wp_delete_user( $customer );
}
Privacy::delete_visitors( $visitor_ids );
$_GET = $get_backup;
wp_set_current_user( $admin->ID );

$left = 0;
foreach ( array( $o1, $o2, $o3, $o4 ) as $order ) {
	$left += wc_get_order( $order->get_id() ) ? 1 : 0;
}
if ( $visitor_ids ) {
	$in    = implode( ',', array_map( 'intval', $visitor_ids ) );
	$left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	foreach ( array( 'visits', 'events' ) as $name ) {
		$left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $name ) . " WHERE visitor_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
$ok( 'test data removed (orders, visitors, visits, events, temporary user)', 0 === $left && ( 0 === $customer || ! get_userdata( $customer ) ) && 4 === count( $visitor_ids ) && 4 === count( $visit_ids ) && 15 === count( $event_ids ) );
