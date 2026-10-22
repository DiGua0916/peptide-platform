<?php
/**
 * 后台“概览”数据大盘（OVERVIEW-01）冒烟测试：时间段、节日、订单 / 访客 / 注册数字、商品排名、接口权限。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-overview.php
 * 测试创建的订单、用户和访客数据在结束时彻底删除。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Overview_Page;
use PeptideCore\Overview;
use PeptideCore\Tracking\Schema;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

global $wpdb;
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );

// 1. 时间段（店铺时区）。
$now = time();
[ $f, $t, $b ] = Overview::window( 'today', $now );
$ok( 'today: midnight to midnight, hourly', 86400 === $t - $f && 'hour' === $b && $f <= $now && $now < $t && '00:00' === wp_date( 'H:i', $f ) );
[ $f7, $t7, $b7 ] = Overview::window( '7d', $now );
$ok( 'last 7 days: daily, ends with today', 7 * 86400 === $t7 - $f7 && 'day' === $b7 && $t7 === $t );
[ $fy, $ty ] = Overview::window( 'yesterday', $now );
$ok( 'yesterday ends where today starts', $ty === $f );

// 2. 节日（美国）。
$h = Overview::holidays( strtotime( '2026-10-22 12:00:00 UTC' ) );
$ok( 'next holidays in order with countdown', 'halloween' === $h[0]['key'] && '2026-10-31' === $h[0]['date'] && 9 === $h[0]['days'] );
$ok( 'Thanksgiving is the 4th Thursday, Black Friday the day after', 'thanksgiving' === $h[1]['key'] && '2026-11-26' === $h[1]['date'] && 'black_friday' === $h[2]['key'] && '2026-11-27' === $h[2]['date'] );
$h2 = Overview::holidays( strtotime( '2026-12-26 12:00:00 UTC' ) );
$ok( 'rolls over to next year', 'new_year' === $h2[0]['key'] && '2027-01-01' === $h2[0]['date'] );

// 3. 今天的数字：一笔已付款订单 + 一笔未付款订单 + 一位新注册用户 + 两位访客。
$before  = Overview::range_data( 'today' );
$product = null;
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => 20 ) ) as $p ) {
	if ( (float) $p->get_price() > 0 ) {
		$product = $p;
		break;
	}
}
$paid = wc_create_order();
$paid->add_product( $product, 2 );
$paid->calculate_totals();
$paid->set_status( 'processing' );
$paid->save();
$unpaid = wc_create_order();
$unpaid->add_product( $product, 1 );
$unpaid->calculate_totals();
$unpaid->save();
// 测试订单：测试付款方式，或包含演示商品。不计入。
$test_pay = wc_create_order();
$test_pay->add_product( $product, 5 );
$test_pay->set_payment_method( 'cod' );
$test_pay->set_payment_method_title( 'Test payment – no money is charged' );
$test_pay->calculate_totals();
$test_pay->set_status( 'processing' );
$test_pay->save();
$demo_ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => \PeptideCore\Meta::DEMO_SEED, 'meta_value' => '1' ) ); // phpcs:ignore
$test_demo = null;
if ( $demo_ids && wc_get_product( $demo_ids[0] ) ) {
	$test_demo = wc_create_order();
	$test_demo->add_product( wc_get_product( $demo_ids[0] ), 1 );
	$test_demo->calculate_totals();
	$test_demo->set_status( 'processing' );
	$test_demo->save();
}
$user = wp_insert_user( array( 'user_login' => 'smoke_overview_user', 'user_email' => 'smoke-overview@example.com', 'user_pass' => wp_generate_password(), 'role' => 'customer' ) );

$visitor_ids = array();
if ( Schema::installed() ) {
	$ms = (int) floor( microtime( true ) * 1000 );
	foreach ( array( $ms - 3 * 86400000, $ms - 1000 ) as $i => $first ) {
		$wpdb->insert( Schema::table( 'visitors' ), array( 'vid' => md5( 'smoke-ov-' . $i . $ms ), 'first_ms' => $first, 'last_ms' => $ms, 'visits' => 1, 'device' => 'mobile', 'is_test' => 1 ) ); // phpcs:ignore
		$vid           = (int) $wpdb->insert_id;
		$visitor_ids[] = $vid;
		$wpdb->insert( Schema::table( 'visits' ), array( 'visitor_id' => $vid, 'start_ms' => $ms - 500, 'last_ms' => $ms, 'stage' => $i ? 1 : 5, 'pageviews' => 2, 'device' => 'mobile', 'is_test' => 1 ) ); // phpcs:ignore
	}
}

$d     = Overview::range_data( 'today' );
$total = (float) $paid->get_total();
$ok( 'sales and orders count only paid orders', abs( $d['sales'] - $before['sales'] - $total ) < 0.01 && $d['orders'] === $before['orders'] + 1 );
$ok( 'test orders are left out and counted separately', $d['test_orders'] === $before['test_orders'] + 1 + ( $test_demo ? 1 : 0 ) );
$ok( 'is_test_order detects test payment and demo products', \PeptideCore\Overview::is_test_order( $test_pay ) && ( ! $test_demo || \PeptideCore\Overview::is_test_order( $test_demo ) ) && ! \PeptideCore\Overview::is_test_order( $paid ) );
$ok( 'average order value', $d['orders'] && abs( $d['aov'] - round( $d['sales'] / $d['orders'], 2 ) ) < 0.01 );
$ok( 'new sign-up counted', $d['registered'] >= 1 );
if ( Schema::installed() ) {
	$ok( 'visitors and returning visitors', $d['visitors'] >= $before['visitors'] + 2 && $d['returning'] >= $before['returning'] + 1 );
	$ok( 'conversion = orders / visitors', null !== $d['conversion'] && abs( $d['conversion'] - round( $d['orders'] / $d['visitors'] * 100, 2 ) ) < 0.01 );
	$ok( 'funnel included', 6 === count( $d['funnel'] ) && $d['funnel'][5]['count'] >= 1 );
}
$ok( 'hourly series covers the day', 24 === count( $d['series'] ) && array_sum( array_column( $d['series'], 'orders' ) ) === $d['orders'] );
$ok( 'product ranking from paid orders', in_array( $product->get_id(), array_column( $d['products'], 'product_id' ), true ) );
$ok( '30-day series has 30 points', 30 === count( Overview::range_data( '30d' )['series'] ) );

$s = Overview::summary();
$ok( 'summary: store time zone label and next midnight', str_contains( $s['tz_label'], wp_timezone_string() ) && $s['next_midnight_ms'] === Overview::window( 'today' )[1] * 1000 && '00:00' === wp_date( 'H:i', (int) ( $s['next_midnight_ms'] / 1000 ) ) );
$ok( 'summary: currency, greeting, to-do counts', 'USD' === $s['currency']['code'] && in_array( $s['greeting'], array( 'morning', 'afternoon', 'evening' ), true ) && isset( $s['todo']['to_ship'], $s['todo']['unpaid_7d'] ) && $s['todo']['unpaid_7d'] >= 1 );

// 4. 接口与入口。
$res = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/overview' ) );
$ok( 'admin can load overview data', 200 === $res->get_status() && isset( $res->get_data()['summary'], $res->get_data()['range'] ) );
$req = new WP_REST_Request( 'GET', '/peptide/v1/overview' );
$req->set_param( 'range', '7d' );
$ok( 'range parameter', '7d' === rest_do_request( $req )->get_data()['range']['range'] );
wp_set_current_user( $user );
$ok( 'customers cannot load overview data', in_array( rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/overview' ) )->get_status(), array( 401, 403 ), true ) );
wp_set_current_user( $admin->ID );
$ok( 'admin home redirects to the overview', (bool) has_action( 'load-index.php', array( Overview_Page::class, 'redirect_dashboard' ) ) || ! is_admin() );

// 5. 清理。
$paid->delete( true );
$test_pay->delete( true );
if ( $test_demo ) {
	$test_demo->delete( true );
}
$unpaid->delete( true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $user );
foreach ( $visitor_ids as $id ) {
	$wpdb->delete( Schema::table( 'visits' ), array( 'visitor_id' => $id ) ); // phpcs:ignore
	$wpdb->delete( Schema::table( 'visitors' ), array( 'id' => $id ) ); // phpcs:ignore
}
$s = Overview::summary();
$after = Overview::range_data( 'today' );
$ok( 'test data removed', $after['orders'] === $before['orders'] && $after['visitors'] === $before['visitors'] );
