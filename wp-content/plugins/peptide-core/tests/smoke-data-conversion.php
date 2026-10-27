<?php
/**
 * 转化率详情页（DATA-01 第 4 步）冒烟测试：整体转化步骤（本期、对比期、变化，和运营数据页一致）、按设备和浏览器分组的漏斗卡片
 * （访问数、订单数、销售额、转化率、测试订单和未付款订单不计入）、group 参数校验、GET /peptide/v1/data/conversion 接口和权限、页面脚本的加载。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-data-conversion.php
 * 测试数据都放在固定的历史日期（店铺时区 2020-04-10 为本期，2020-04-09 为对比期），不受站点上其他数据影响；
 * 需要访客追踪的表已安装；测试创建的订单、用户、访客、访问和事件在结束时彻底删除（订单状态变化会写追踪事件，所以用 Privacy::delete_visitors() 清理）。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Data_Pages;
use PeptideCore\Data\Conversion;
use PeptideCore\Data\Operations;
use PeptideCore\Data\Range;
use PeptideCore\Tracking\Privacy;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;

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

if ( ! Schema::installed() ) {
	echo "FAIL tracking tables not installed\n";
	return;
}

// 商品：第一个已发布、价格大于 0、不是演示商品的简单商品。
$is_demo = static fn( int $id ): bool => '1' === get_post_meta( $id, \PeptideCore\Meta::DEMO_SEED, true );
$simple  = null;
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $candidate ) {
	if ( (float) $candidate->get_price() > 0 && ! $is_demo( $candidate->get_id() ) ) {
		$simple = $candidate;
		break;
	}
}
if ( ! $simple ) {
	echo "FAIL fixture product\n";
	return;
}

// 访客：W1 电脑 Chrome，W2 手机 Safari，W3 手机 Chrome，W4 电脑 Firefox。
$visitor_ids = array();
$visitor     = static function ( int $n, string $device, string $browser ) use ( $wpdb, $at, &$visitor_ids ): int {
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'visitors' ),
		array(
			'vid'      => md5( 'smoke-conv-' . $n . microtime() ),
			'first_ms' => $at( '2020-04-09 08:00' ) * 1000,
			'last_ms'  => $at( '2020-04-10 20:00' ) * 1000,
			'visits'   => 1,
			'device'   => $device,
			'browser'  => $browser,
			'is_test'  => 1,
		)
	);
	$visitor_ids[] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
};
$w1          = $visitor( 1, 'desktop', 'Chrome' );
$w2          = $visitor( 2, 'mobile', 'Safari' );
$w3          = $visitor( 3, 'mobile', 'Chrome' );
$w4          = $visitor( 4, 'desktop', 'Firefox' );

// 访问：设备 = 访客的设备。
$visit_ids = array();
$visit     = static function ( int $visitor_id, string $device, string $local, int $stage ) use ( $wpdb, $at, &$visit_ids ): int {
	$start = $at( $local ) * 1000;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'visits' ),
		array(
			'visitor_id' => $visitor_id,
			'start_ms'   => $start,
			'last_ms'    => $start + 60000,
			'device'     => $device,
			'pageviews'  => 1,
			'stage'      => $stage,
			'is_test'    => 1,
		)
	);
	$visit_ids[] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
};
$visit( $w1, 'desktop', '2020-04-10 10:00', 3 );
$v2 = $visit( $w2, 'mobile', '2020-04-10 10:05', 5 );
$visit( $w3, 'mobile', '2020-04-10 10:10', 1 );
$visit( $w4, 'desktop', '2020-04-09 10:00', 2 );

// 订单：商品 × 1，游客；追踪信息（meta）在改状态的那次保存之前设好。
$make_order = static function ( string $status, string $local, string $email, array $meta = array(), ?callable $setup = null ) use ( $simple, $at ): WC_Order {
	$order = wc_create_order();
	$order->add_product( $simple, 1 );
	$order->set_customer_id( 0 );
	$order->set_billing_email( $email );
	foreach ( $meta as $key => $value ) {
		$order->update_meta_data( $key, $value );
	}
	if ( $setup ) {
		$setup( $order );
	}
	$order->calculate_totals();
	$order->set_status( $status );
	$order->set_date_created( $at( $local ) );
	$order->save();
	return $order;
};
// O1：已付款，W2 的 V2 访问下的单（手机、Safari）。
$o1 = $make_order( 'processing', '2020-04-10 10:06', 'o1@example.com', array( Server_Events::ORDER_VISITOR_META => $w2, Server_Events::ORDER_VISIT_META => $v2 ) );
// O2：已付款，没有追踪信息（unknown）。
$o2 = $make_order( 'completed', '2020-04-10 12:00', 'o2@example.com' );
// O3：测试付款方式（测试订单，不计入），W1 的单。
$o3 = $make_order(
	'processing',
	'2020-04-10 13:00',
	'o3@example.com',
	array( Server_Events::ORDER_VISITOR_META => $w1 ),
	static function ( WC_Order $o ): void {
		$o->set_payment_method( 'cod' );
		$o->set_payment_method_title( 'Test payment – no money is charged' );
	}
);
// O4：未付款（不计入），W1 的单。
$o4 = $make_order( 'pending', '2020-04-10 14:00', 'o4@example.com', array( Server_Events::ORDER_VISITOR_META => $w1 ) );
$t1 = (float) $o1->get_total();
$t2 = (float) $o2->get_total();

$range = Range::from_params( array( 'from' => '2020-04-10', 'to' => '2020-04-10' ) );
$s     = is_array( $range ) ? Conversion::summary( $range, 'device' ) : array();
$cur   = $s['overall']['current'] ?? array();
$prev  = $s['overall']['previous'] ?? array();
$chg   = $s['overall']['change'] ?? array();

// 1. 整体：本期。
$ok( 'overall current: visitors 3, viewed 3, carted 2, checkout 2, paid 2, conversion 66.67', 3 === ( $cur['visitors'] ?? null ) && 3 === ( $cur['viewed'] ?? null ) && 2 === ( $cur['carted'] ?? null ) && 2 === ( $cur['checkout'] ?? null ) && 2 === ( $cur['paid'] ?? null ) && $eq( $cur['conversion'] ?? null, 66.67 ) );

// 2. 整体：对比期和变化。
$ok( 'overall previous: visitors 1, viewed 1, carted 1, checkout 0, paid 0, conversion 0 (not null)', 1 === ( $prev['visitors'] ?? null ) && 1 === ( $prev['viewed'] ?? null ) && 1 === ( $prev['carted'] ?? null ) && 0 === ( $prev['checkout'] ?? null ) && 0 === ( $prev['paid'] ?? null ) && null !== ( $prev['conversion'] ?? null ) && $eq( $prev['conversion'] ?? null, 0.0 ) );
$ok( 'overall change: visitors +200, conversion +66.67 points, paid null (previous 0)', $eq( $chg['visitors'] ?? null, 200.0 ) && $eq( $chg['conversion'] ?? null, 66.67 ) && array_key_exists( 'paid', $chg ) && null === $chg['paid'] );

// 3. 整体和运营数据页一致。
$op_cur  = is_array( $range ) ? Operations::period( (int) $range['from'], (int) $range['to'] ) : array();
$op_prev = is_array( $range ) ? Operations::period( (int) $range['compare_from'], (int) $range['compare_to'] ) : array();
$ok( 'overall equals the operations page (visitors, conversion, paid orders; current and previous)', $op_cur && ( $op_cur['kpis']['visitors'] ?? null ) === ( $cur['visitors'] ?? -1 ) && $eq( $op_cur['kpis']['conversion'] ?? null, $cur['conversion'] ?? null ) && ( $op_cur['kpis']['orders'] ?? null ) === ( $cur['paid'] ?? -1 ) && ( $op_prev['kpis']['visitors'] ?? null ) === ( $prev['visitors'] ?? -1 ) && $eq( $op_prev['kpis']['conversion'] ?? null, $prev['conversion'] ?? null ) && ( $op_prev['kpis']['orders'] ?? null ) === ( $prev['paid'] ?? -1 ) );

// 4. 键的顺序。
$ok( 'overall keys are exactly Conversion::OVERALL in order; groups are Conversion::GROUPS; group is device', array_keys( $cur ) === Conversion::OVERALL && array_keys( $prev ) === Conversion::OVERALL && array_keys( $chg ) === Conversion::OVERALL && Conversion::GROUPS === ( $s['groups'] ?? null ) && 'device' === ( $s['group'] ?? null ) && true === ( $s['tracking'] ?? null ) );

// 5. 设备分组。
$rows = $s['rows'] ?? array();
$pick = static function ( array $list, string $key ): array {
	foreach ( $list as $row ) {
		if ( $key === $row['key'] ) {
			return $row;
		}
	}
	return array();
};
$ok( 'device rows come in the order mobile, desktop, unknown', array( 'mobile', 'desktop', 'unknown' ) === array_column( $rows, 'key' ) );
$mobile  = $pick( $rows, 'mobile' );
$desktop = $pick( $rows, 'desktop' );
$unknown = $pick( $rows, 'unknown' );
$ok( 'mobile: visitors 2, paid 1, conversion 50, sales = O1 total, funnel 2/2/1/1/1', 2 === ( $mobile['visitors'] ?? null ) && 1 === ( $mobile['paid'] ?? null ) && $eq( $mobile['conversion'] ?? null, 50.0 ) && $eq( $mobile['sales'] ?? null, $t1 ) && array( 2, 2, 1, 1, 1 ) === array_column( $mobile['funnel'] ?? array(), 'count' ) );
$ok( 'desktop: visitors 1, paid 0, conversion 0 (not null), sales 0, funnel 1/1/1/1/0', 1 === ( $desktop['visitors'] ?? null ) && 0 === ( $desktop['paid'] ?? null ) && null !== ( $desktop['conversion'] ?? null ) && $eq( $desktop['conversion'] ?? null, 0.0 ) && $eq( $desktop['sales'] ?? null, 0.0 ) && array( 1, 1, 1, 1, 0 ) === array_column( $desktop['funnel'] ?? array(), 'count' ) );
$ok( 'unknown: visitors 0, paid 1, conversion null, sales = O2 total', 0 === ( $unknown['visitors'] ?? null ) && 1 === ( $unknown['paid'] ?? null ) && array_key_exists( 'conversion', $unknown ) && null === $unknown['conversion'] && $eq( $unknown['sales'] ?? null, $t2 ) );

// 6. 漏斗的层。
$mf = $mobile['funnel'] ?? array();
$ok( 'mobile funnel has the Operations::LAYERS keys in order, viewed visitor_rate 100, carted step_rate 50', Operations::LAYERS === array_column( $mf, 'key' ) && $eq( $mf[1]['visitor_rate'] ?? null, 100.0 ) && $eq( $mf[2]['step_rate'] ?? null, 50.0 ) );

// 7. 测试订单和未付款订单不计入。
$ok( 'the test-payment order and the unpaid order are not counted (paid over the device rows is 2)', 2 === array_sum( array_column( $rows, 'paid' ) ) );

// 8. 浏览器分组。
$b      = is_array( $range ) ? Conversion::summary( $range, 'browser' ) : array();
$brows  = $b['rows'] ?? array();
$chrome = $pick( $brows, 'Chrome' );
$safari = $pick( $brows, 'Safari' );
$bunkn  = $pick( $brows, 'unknown' );
$ok( 'browser rows come in the order Chrome, Safari, unknown and the group is browser', array( 'Chrome', 'Safari', 'unknown' ) === array_column( $brows, 'key' ) && 'browser' === ( $b['group'] ?? null ) );
$ok( 'Chrome: visitors 2, paid 0; Safari: visitors 1, paid 1, sales = O1 total; unknown: paid 1', 2 === ( $chrome['visitors'] ?? null ) && 0 === ( $chrome['paid'] ?? null ) && 1 === ( $safari['visitors'] ?? null ) && 1 === ( $safari['paid'] ?? null ) && $eq( $safari['sales'] ?? null, $t1 ) && 1 === ( $bunkn['paid'] ?? null ) );

// 9. group 参数校验。
$none  = Conversion::params( array() );
$empty = Conversion::params( array( 'group' => '' ) );
$brw   = Conversion::params( array( 'group' => 'browser' ) );
$bad   = Conversion::params( array( 'group' => 'utm' ) );
$arr   = Conversion::params( array( 'group' => array( 'device' ) ) );
$flag  = Conversion::params( array( 'group' => true ) );
$ok( 'params: no group and group="" are device, group=browser is browser', 'device' === $none && 'device' === $empty && 'browser' === $brw );
$ok( 'params: group=utm is pc_invalid_param with fields.group invalid_format', is_wp_error( $bad ) && 'pc_invalid_param' === $bad->get_error_code() && 'invalid_format' === ( $bad->get_error_data()['fields']['group'] ?? '' ) );
$ok( 'params: an array or a boolean group is invalid_format too', is_wp_error( $arr ) && is_wp_error( $flag ) && 'invalid_format' === ( $arr->get_error_data()['fields']['group'] ?? '' ) && 'invalid_format' === ( $flag->get_error_data()['fields']['group'] ?? '' ) );

// 10. 接口（管理员）。
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/conversion' );
$req->set_param( 'from', '2020-04-10' );
$req->set_param( 'to', '2020-04-10' );
$req->set_param( 'group', 'browser' );
$res = rest_do_request( $req );
$ok( 'admin can load the conversion data (200, group browser)', 200 === $res->get_status() && 'browser' === ( $res->get_data()['group'] ?? null ) );
$ok( 'conversion response is not cached', 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/conversion' );
$req->set_param( 'from', '2020-04-10' );
$req->set_param( 'to', '2020-04-10' );
$req->set_param( 'group', 'bogus' );
$ok( 'group=bogus returns 400', 400 === rest_do_request( $req )->get_status() );

// 11. 接口（顾客不能看）。
$customer = wp_insert_user(
	array(
		'user_login' => 'smoke_conv_customer',
		'user_email' => 'smoke-conv-customer@example.com',
		'user_pass'  => wp_generate_password(),
		'role'       => 'customer',
	)
);
$customer = is_wp_error( $customer ) ? 0 : (int) $customer;
wp_set_current_user( $customer );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/conversion' );
$req->set_param( 'from', '2020-04-10' );
$req->set_param( 'to', '2020-04-10' );
$ok( 'customers cannot load the conversion data', $customer > 0 && in_array( rest_do_request( $req )->get_status(), array( 401, 403 ), true ) );

// 12. 转化率页的脚本只在转化率详情页加载。
wp_set_current_user( $admin->ID );
$_GET['page'] = 'pc-data-conversion';
Data_Pages::enqueue();
$before = implode( '', (array) wp_scripts()->get_data( 'peptide-core-data-conversion', 'before' ) );
$ok( 'conversion script and style are enqueued on the conversion page', wp_script_is( 'peptide-core-data-conversion', 'enqueued' ) && wp_style_is( 'peptide-core-data-conversion', 'enqueued' ) );
$ok( 'conversion script gets its strings before it loads', false !== strpos( $before, 'window.pcDataConversion' ) && false !== strpos( $before, 'Group by' ) );
foreach ( array( 'peptide-core-data-conversion', Data_Pages::SCRIPT ) as $handle ) {
	wp_dequeue_script( $handle );
}
$_GET['page'] = 'pc-data-products';
Data_Pages::enqueue();
$ok( 'conversion script is not enqueued on the products page', ! wp_script_is( 'peptide-core-data-conversion', 'enqueued' ) && wp_script_is( 'peptide-core-data-products', 'enqueued' ) );
foreach ( array( 'peptide-core-data', 'peptide-core-data-operations', 'peptide-core-data-products', 'peptide-core-data-conversion' ) as $handle ) {
	wp_dequeue_script( $handle );
	wp_dequeue_style( $handle );
}

// 清理：先删订单（订单状态变化会写追踪事件），再删用户，最后用 Privacy::delete_visitors() 删访客和他们的事件、访问。
$order_ids = array( $o1->get_id(), $o2->get_id(), $o3->get_id(), $o4->get_id() );
foreach ( $order_ids as $id ) {
	$order = wc_get_order( $id );
	if ( $order ) {
		$order->delete( true );
	}
}
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( $customer > 0 ) {
	wp_delete_user( $customer );
}
Privacy::delete_visitors( $visitor_ids );
$_GET = $get_backup;
wp_set_current_user( $admin->ID );

// 13. 测试数据都删掉了。
$left = 0;
foreach ( $order_ids as $id ) {
	$left += wc_get_order( $id ) ? 1 : 0;
}
if ( $visitor_ids ) {
	$in = implode( ',', array_map( 'intval', $visitor_ids ) );
	$left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	foreach ( array( 'visits', 'events' ) as $name ) {
		$left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $name ) . " WHERE visitor_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
$ok( 'test data removed (orders, visitors, visits, events, temporary user)', 0 === $left && ( 0 === $customer || ! get_userdata( $customer ) ) && 4 === count( $visitor_ids ) && 4 === count( $visit_ids ) );
