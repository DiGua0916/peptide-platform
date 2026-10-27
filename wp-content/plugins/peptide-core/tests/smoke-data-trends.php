<?php
/**
 * 运营数据页趋势图（DATA-01 第 7a 步）冒烟测试：九张图的十个数字（访客数、浏览量、订单数、销售额、客单数、客单价、复购率、复购订单量、
 * 复购成交额、复购客单价）的本期合计、每个小时 / 每天的点、对比期和变化、合计和运营数据页指标卡片一致、复购判断（时间段之前的历史、
 * 本期里更早的订单、测试订单不算）、天桶、夏令时（23 / 25 小时）、GET /peptide/v1/data/trends 接口和权限、页面脚本的文字。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-data-trends.php
 * 测试数据都放在固定的历史日期（店铺时区 2020-07-01 的历史订单，2020-07-09 和 2020-07-10 的订单和访问；夏令时检查用 2020-03-08、
 * 2020-10-31 至 2020-11-02，这几天不放数据），不受站点上其他数据影响；需要访客追踪的表已安装；
 * 测试创建的订单、用户、访客、访问和事件在结束时彻底删除（访客用 Privacy::delete_visitors() 清理）。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Data_Pages;
use PeptideCore\Data\Operations;
use PeptideCore\Data\Range;
use PeptideCore\Data\Trends;
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

// 顾客：C1 是注册用户，G、H、K 是游客（邮箱唯一）；另有一个临时顾客用来测接口权限。
$uniq = substr( md5( uniqid( '', true ) ), 0, 8 );
$c1   = wp_insert_user(
	array(
		'user_login' => 'smoke_tr_c1_' . $uniq,
		'user_email' => 'smoke-tr-c1-' . $uniq . '@example.com',
		'user_pass'  => wp_generate_password(),
		'role'       => 'customer',
	)
);
$customer = wp_insert_user(
	array(
		'user_login' => 'smoke_tr_cust_' . $uniq,
		'user_email' => 'smoke-tr-cust-' . $uniq . '@example.com',
		'user_pass'  => wp_generate_password(),
		'role'       => 'customer',
	)
);
if ( is_wp_error( $c1 ) || is_wp_error( $customer ) ) {
	echo "FAIL fixture users\n";
	return;
}
$c1       = (int) $c1;
$customer = (int) $customer;
$email_g  = 'g-' . $uniq . '@example.com';
$email_h  = 'h-' . $uniq . '@example.com';
$email_k  = 'k-' . $uniq . '@example.com';

// 订单。
$make_order = static function ( int $qty, string $status, string $created, ?callable $setup = null ) use ( $product, $at ): WC_Order {
	$order = wc_create_order();
	$order->add_product( $product, $qty );
	if ( $setup ) {
		$setup( $order );
	}
	$order->calculate_totals();
	$order->set_status( $status );
	$order->set_date_created( $at( $created ) );
	$order->save();
	return $order;
};
$as_user  = static fn( int $id ) => static function ( WC_Order $o ) use ( $id ): void {
	$o->set_customer_id( $id );
};
$as_guest = static fn( string $email ) => static function ( WC_Order $o ) use ( $email ): void {
	$o->set_customer_id( 0 );
	$o->set_billing_email( $email );
};

$p0 = $make_order( 1, 'completed', '2020-07-01 12:00', $as_user( $c1 ) );   // 历史订单。
$o1 = $make_order( 1, 'processing', '2020-07-10 10:15', $as_user( $c1 ) );
$o2 = $make_order( 2, 'completed', '2020-07-10 11:20', $as_guest( $email_g ) );
$o3 = $make_order( 1, 'processing', '2020-07-10 15:40', $as_guest( $email_g ) );
$o4 = $make_order( 3, 'processing', '2020-07-10 15:50', $as_guest( $email_h ) );
$o5 = $make_order(
	5,
	'processing',
	'2020-07-10 12:00',
	static function ( WC_Order $o ): void {
		$o->set_customer_id( 0 );
		$o->set_payment_method( 'cod' );
		$o->set_payment_method_title( 'Test payment – no money is charged' );
	}
);
$o6 = $make_order( 1, 'completed', '2020-07-09 09:30', $as_guest( $email_k ) );
$t1 = (float) $o1->get_total();
$t2 = (float) $o2->get_total();
$t3 = (float) $o3->get_total();
$t4 = (float) $o4->get_total();
$t6 = (float) $o6->get_total();

// 访客追踪：W1–W3，2020-07-10。W1 有两次访问（10:05 和 14:10），不是两个访客。
$visitor_ids = array();
$visit_ids   = array();
$track       = static function ( int $n, string $start ) use ( $wpdb, $ms, &$visitor_ids, &$visit_ids ): array {
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'visitors' ),
		array(
			'vid'      => md5( 'smoke-tr-' . $n . microtime() ),
			'first_ms' => $ms( $start ),
			'last_ms'  => $ms( $start ),
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
			'visitor_id' => $visitor_id,
			'start_ms'   => $ms( $start ),
			'last_ms'    => $ms( $start ),
			'device'     => 'desktop',
			'pageviews'  => 1,
			'is_test'    => 1,
		)
	);
	$visit_ids[] = (int) $wpdb->insert_id;
	return array( $visitor_id, (int) $wpdb->insert_id );
};
list( $w1, $v1 ) = $track( 1, '2020-07-10 10:05' );
list( $w2 )      = $track( 2, '2020-07-10 10:30' );
list( $w3, $v3 ) = $track( 3, '2020-07-10 14:00' );
// W1 的第二次访问：同一个访客，新的一行 visits。
$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	Schema::table( 'visits' ),
	array(
		'visitor_id' => $w1,
		'start_ms'   => $ms( '2020-07-10 14:10' ),
		'last_ms'    => $ms( '2020-07-10 14:10' ),
		'device'     => 'desktop',
		'pageviews'  => 0,
		'is_test'    => 1,
	)
);
$visit_ids[] = (int) $wpdb->insert_id;
// 页面浏览事件：W1 10:05 和 10:06，W3 14:00。
$event_ids = array();
$event     = static function ( int $visitor_id, int $visit_id, string $local ) use ( $wpdb, $ms, &$event_ids ): void {
	$time = $ms( $local );
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'events' ),
		array(
			'event_uid'   => 'smoke-tr-' . count( $event_ids ) . '-' . md5( microtime() . $local ),
			'visitor_id'  => $visitor_id,
			'visit_id'    => $visit_id,
			'type'        => 'page_view',
			'source'      => 'client',
			'occurred_ms' => $time,
			'received_ms' => $time,
			'path'        => '/tr-a/',
			'is_test'     => 1,
		)
	);
	$event_ids[] = (int) $wpdb->insert_id;
};
$event( $w1, $v1, '2020-07-10 10:05' );
$event( $w1, $v1, '2020-07-10 10:06' );
$event( $w3, $v3, '2020-07-10 14:00' );

$range = Range::from_params( array( 'from' => '2020-07-10', 'to' => '2020-07-10' ) );
$s     = is_array( $range ) ? Trends::summary( $range ) : array();
$cur   = $s['current']['totals'] ?? array();
$prev  = $s['previous']['totals'] ?? array();
$chg   = $s['change'] ?? array();
$pts   = $s['current']['points'] ?? array();

// 1. 小时桶。
$labels = array();
for ( $h = 0; $h < 24; $h++ ) {
	$labels[] = sprintf( '%02d:00', $h );
}
$ok( 'one day: bucket is hour, 24 points labelled 00:00 to 23:00, tracking is on', 'hour' === ( $s['bucket'] ?? '' ) && 24 === count( $pts ) && $labels === array_column( $pts, 'label' ) && true === ( $s['tracking'] ?? null ) );

// 2. 本期合计。
$sum = $t1 + $t2 + $t3 + $t4;
$ok( 'totals: 4 orders, sales = t1+t2+t3+t4, units per order 1.75, average order value = sales / 4', 4 === ( $cur['orders'] ?? null ) && $eq( $cur['sales'] ?? null, $sum ) && $eq( $cur['units_per_order'] ?? null, 1.75 ) && $eq( $cur['aov'] ?? null, $sum / 4 ) );
$ok( 'totals: 3 visitors (W1 has two visits but counts once, not the sum of the hours) and 3 page views', 3 === ( $cur['visitors'] ?? null ) && 3 === ( $cur['pageviews'] ?? null ) );

// 3. 复购合计。
$ok( 'repurchase totals: rate 66.67 (C1 and G of C1, G, H), 2 orders (O1, O3), sales t1+t3, average (t1+t3)/2; the test order is left out', $eq( $cur['repurchase_rate'] ?? null, 66.67 ) && 2 === ( $cur['repurchase_orders'] ?? null ) && $eq( $cur['repurchase_sales'] ?? null, $t1 + $t3 ) && $eq( $cur['repurchase_aov'] ?? null, ( $t1 + $t3 ) / 2 ) );

// 4. 每个小时的点。
$ok( 'hour 10: 1 order, 1 repurchase order, rate 100, 2 visitors, 2 page views', 1 === ( $pts[10]['orders'] ?? null ) && 1 === ( $pts[10]['repurchase_orders'] ?? null ) && $eq( $pts[10]['repurchase_rate'] ?? null, 100.0 ) && 2 === ( $pts[10]['visitors'] ?? null ) && 2 === ( $pts[10]['pageviews'] ?? null ) );
$ok( 'hour 11: 1 order, rate 0 (G buys for the first time)', 1 === ( $pts[11]['orders'] ?? null ) && $eq( $pts[11]['repurchase_rate'] ?? null, 0.0 ) && null !== ( $pts[11]['repurchase_rate'] ?? null ) );
$ok( 'hour 15: 2 orders, rate 50 (G again, H first), 1 repurchase order', 2 === ( $pts[15]['orders'] ?? null ) && $eq( $pts[15]['repurchase_rate'] ?? null, 50.0 ) && 1 === ( $pts[15]['repurchase_orders'] ?? null ) );
$ok( 'hour 14: 2 visitors (W3 and the second visit of W1), 1 page view', 2 === ( $pts[14]['visitors'] ?? null ) && 1 === ( $pts[14]['pageviews'] ?? null ) );
$ok( 'hour 3: no orders, repurchase rate is null', 0 === ( $pts[3]['orders'] ?? null ) && array_key_exists( 'repurchase_rate', $pts[3] ?? array() ) && null === $pts[3]['repurchase_rate'] );

// 5. 对比期（2020-07-09）和变化。
$ok( 'previous: 1 order, repurchase rate 0, 0 repurchase orders (K buys for the first time)', 1 === ( $prev['orders'] ?? null ) && $eq( $prev['repurchase_rate'] ?? null, 0.0 ) && null !== ( $prev['repurchase_rate'] ?? null ) && 0 === ( $prev['repurchase_orders'] ?? null ) && $eq( $prev['sales'] ?? null, $t6 ) );
$ok( 'change: repurchase rate +66.67 points, repurchase orders null (previous is 0), orders +300%', $eq( $chg['repurchase_rate'] ?? null, 66.67 ) && array_key_exists( 'repurchase_orders', $chg ) && null === $chg['repurchase_orders'] && $eq( $chg['orders'] ?? null, 300.0 ) );

// 6. 合计和运营数据页的指标卡片一致。
$kpi = is_array( $range ) ? Operations::period( (int) $range['from'], (int) $range['to'] )['kpis'] : array();
$ok( 'totals match Operations::period(): visitors, orders, sales, average order value', ( $kpi['visitors'] ?? -1 ) === ( $cur['visitors'] ?? -2 ) && ( $kpi['orders'] ?? -1 ) === ( $cur['orders'] ?? -2 ) && $eq( $kpi['sales'] ?? null, $cur['sales'] ?? 0 ) && $eq( $kpi['aov'] ?? null, $cur['aov'] ?? 0 ) );

// 7. 十个数字都在合计里和每个点里，顺序不变。
$shape = Trends::METRICS === array_keys( $cur ) && Trends::METRICS === array_keys( $prev ) && Trends::METRICS === array_keys( $chg );
foreach ( array_merge( $pts, $s['previous']['points'] ?? array() ) as $point ) {
	$shape = $shape && array( 'label', 'start' ) === array_slice( array_keys( $point ), 0, 2 ) && Trends::METRICS === array_slice( array_keys( $point ), 2 );
}
$ok( 'every metric of Trends::METRICS is in the totals, in the change and in every point, in order', $shape && 10 === count( Trends::METRICS ) );

// 8. 天桶：两天，K 的订单不是复购，C1 靠时间段之前的订单、G 靠本期更早的订单。
$range2 = Range::from_params( array( 'from' => '2020-07-09', 'to' => '2020-07-10' ) );
$s2     = is_array( $range2 ) ? Trends::summary( $range2 ) : array();
$days   = $s2['current']['points'] ?? array();
$ok( 'two days: bucket is day, 2 points labelled 07-09 and 07-10 with 1 and 4 orders', 'day' === ( $s2['bucket'] ?? '' ) && array( '07-09', '07-10' ) === array_column( $days, 'label' ) && 1 === ( $days[0]['orders'] ?? null ) && 4 === ( $days[1]['orders'] ?? null ) && 5 === ( $s2['current']['totals']['orders'] ?? null ) );
$ok( 'two days: 2 repurchase orders in total (O1 through the history order P0 before the range, O3 through O2; K does not repeat; both are on 07-10), 3 visitors on 07-10', 2 === ( $s2['current']['totals']['repurchase_orders'] ?? null ) && 2 === ( $days[1]['repurchase_orders'] ?? null ) && 0 === ( $days[0]['repurchase_orders'] ?? null ) && 3 === ( $days[1]['visitors'] ?? null ) );

// 9. 夏令时（店铺时区是 America/Los_Angeles 时）。
if ( 'America/Los_Angeles' === wp_timezone_string() ) {
	$fall   = Trends::summary( Range::from_params( array( 'from' => '2020-11-01', 'to' => '2020-11-01' ) ) );
	$spring = Trends::summary( Range::from_params( array( 'from' => '2020-03-08', 'to' => '2020-03-08' ) ) );
	$three  = Trends::summary( Range::from_params( array( 'from' => '2020-10-31', 'to' => '2020-11-02' ) ) );
	$fall_l = array_column( $fall['current']['points'], 'label' );
	$ok( 'DST fall back (2020-11-01): 25 hourly points, the repeated hour 01:00 appears twice', 25 === count( $fall['current']['points'] ) && 2 === count( array_keys( $fall_l, '01:00', true ) ) );
	$ok( 'DST spring forward (2020-03-08): 23 hourly points', 23 === count( $spring['current']['points'] ) );
	$ok( 'three days across the change: 3 day points labelled 10-31, 11-01, 11-02', 'day' === $three['bucket'] && array( '10-31', '11-01', '11-02' ) === array_column( $three['current']['points'], 'label' ) );
} else {
	$ok( 'store time zone is not America/Los_Angeles, DST checks skipped', true );
}

// 10. 接口（管理员）。
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/trends' );
$req->set_param( 'from', '2020-07-10' );
$req->set_param( 'to', '2020-07-10' );
$res = rest_do_request( $req );
$rd  = $res->get_data();
$ok( 'admin can load the trends (200, 4 orders, 24 points) and the response is not cached', 200 === $res->get_status() && 4 === ( $rd['current']['totals']['orders'] ?? null ) && 24 === count( $rd['current']['points'] ?? array() ) && 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/trends' );
$req->set_param( 'from', 'bad' );
$req->set_param( 'to', '2020-07-10' );
$ok( 'from=bad returns 400', 400 === rest_do_request( $req )->get_status() );

// 11. 接口（顾客不能看）。
wp_set_current_user( $customer );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/trends' );
$req->set_param( 'from', '2020-07-10' );
$req->set_param( 'to', '2020-07-10' );
$ok( 'customers cannot load the trends', in_array( rest_do_request( $req )->get_status(), array( 401, 403 ), true ) );
wp_set_current_user( $admin->ID );

// 页面脚本拿到趋势图的文字。
$_GET['page'] = 'pc-data-operations';
Data_Pages::enqueue();
$before = (array) wp_scripts()->get_data( 'peptide-core-data-operations', 'before' );
$last   = (string) end( $before );
$ok( 'operations page strings include the trend titles, help texts, total line and error text', false !== strpos( $last, 'window.pcDataOperations' ) && false !== strpos( $last, '"trendRepAov"' ) && false !== strpos( $last, '"helpRepRate"' ) && false !== strpos( $last, '"totalLine"' ) && false !== strpos( $last, '"trendsError"' ) );
wp_dequeue_script( 'peptide-core-data-operations' );
wp_dequeue_style( 'peptide-core-data-operations' );
wp_dequeue_script( Data_Pages::SCRIPT );
wp_dequeue_style( 'peptide-core-data' );

// 12. 清理：订单、临时用户，再用 Privacy::delete_visitors() 删访客和他们的事件、访问。
$orders = array( $p0, $o1, $o2, $o3, $o4, $o5, $o6 );
foreach ( $orders as $order ) {
	$saved = wc_get_order( $order->get_id() );
	if ( $saved ) {
		$saved->delete( true );
	}
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $c1 );
wp_delete_user( $customer );
Privacy::delete_visitors( $visitor_ids );
$_GET = $get_backup;
wp_set_current_user( $admin->ID );

$left = 0;
foreach ( $orders as $order ) {
	$left += wc_get_order( $order->get_id() ) ? 1 : 0;
}
$in = implode( ',', array_map( 'intval', $visitor_ids ) );
if ( '' !== $in ) {
	$left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	foreach ( array( 'visits', 'events' ) as $name ) {
		$left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $name ) . " WHERE visitor_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
$ok( 'test data removed (7 orders, 3 visitors with their 4 visits and 3 events, both temporary users)', 0 === $left && ! get_userdata( $c1 ) && ! get_userdata( $customer ) && 3 === count( $visitor_ids ) && 4 === count( $visit_ids ) && 3 === count( $event_ids ) );
