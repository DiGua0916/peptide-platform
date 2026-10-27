<?php
/**
 * 运营数据页（DATA-01 第 2 步）冒烟测试：12 个指标（销售额、访问用户、注册用户、订单数、转化率、客单价、商品销售件数、
 * 商品净销售额、退款金额、平均访问时长、顾客数、移动端）、访客转化漏斗、回访用户转化漏斗、和对比期的变化、
 * GET /peptide/v1/data/operations 接口和权限、页面脚本的加载。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-data-operations.php
 * 测试数据都放在固定的历史日期（店铺时区 2020-01-15 为本期，2020-01-14 为对比期），不受站点上其他数据影响；
 * 测试创建的订单、退款、用户和访客数据在结束时彻底删除。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Data_Pages;
use PeptideCore\Data\Operations;
use PeptideCore\Data\Range;
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
$all_eq = static function ( array $got, array $want ) use ( $eq ): bool {
	if ( count( $got ) !== count( $want ) ) {
		return false;
	}
	foreach ( array_values( $want ) as $i => $w ) {
		if ( ! $eq( array_values( $got )[ $i ], $w ) ) {
			return false;
		}
	}
	return true;
};

global $wpdb;
$get_backup = $_GET;
$admin      = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );

// 店铺时区的某个时刻 => Unix 秒。
$at = static fn( string $local ) => ( new DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();

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
$p = (float) $product->get_price();

// 用户 U1：本期注册。
$u1 = wp_insert_user(
	array(
		'user_login'      => 'smoke_ops_u1',
		'user_email'      => 'smoke-ops-u1@example.com',
		'user_pass'       => wp_generate_password(),
		'role'            => 'customer',
		'user_registered' => gmdate( 'Y-m-d H:i:s', $at( '2020-01-15 12:00' ) ),
	)
);
if ( is_wp_error( $u1 ) ) {
	echo 'FAIL fixture user: ', $u1->get_error_message(), "\n";
	return;
}
$u1 = (int) $u1;

// 订单。
$make_order = static function ( int $qty, string $status, int $created, ?callable $setup = null ) use ( $product ): WC_Order {
	$order = wc_create_order();
	$order->add_product( $product, $qty );
	if ( $setup ) {
		$setup( $order );
	}
	$order->calculate_totals();
	$order->set_status( $status );
	$order->set_date_created( $created );
	$order->save();
	return $order;
};
// A：U1 的已付款订单，2 件。
$order_a = $make_order( 2, 'processing', $at( '2020-01-15 10:00' ), static function ( WC_Order $o ) use ( $u1 ): void {
	$o->set_customer_id( $u1 );
} );
// B：游客的已完成订单，邮箱大小写混合。
$order_b = $make_order( 1, 'completed', $at( '2020-01-15 11:00' ), static function ( WC_Order $o ): void {
	$o->set_customer_id( 0 );
	$o->set_billing_email( 'Smoke-Ops-Guest@Example.com' );
} );
// C：未付款。
$order_c = $make_order( 1, 'pending', $at( '2020-01-15 11:30' ) );
// D：测试付款方式下的单（测试订单，不计入）。
$order_d = $make_order( 5, 'processing', $at( '2020-01-15 12:00' ), static function ( WC_Order $o ): void {
	$o->set_payment_method( 'cod' );
	$o->set_payment_method_title( 'Test payment – no money is charged' );
} );
// E：对比期的已付款订单。
$order_e = $make_order( 1, 'processing', $at( '2020-01-14 12:00' ) );

// A 退掉 1 件。
$a_items   = $order_a->get_items();
$a_item_id = (int) array_key_first( $a_items );
$refund    = wc_create_refund(
	array(
		'order_id'   => $order_a->get_id(),
		'amount'     => $p,
		'line_items' => array(
			$a_item_id => array(
				'qty'          => 1,
				'refund_total' => $p,
			),
		),
	)
);
$refund_id = 0;
if ( $refund instanceof WC_Order_Refund ) {
	$refund->set_date_created( $at( '2020-01-15 13:00' ) );
	$refund->save();
	$refund_id = (int) $refund->get_id();
}
$ok( 'fixture refund created', $refund_id > 0 );

// 访客追踪的数据（表装好时）。
$visitor_ids = array();
$visit_ids   = array();
$tracking    = Schema::installed();
if ( $tracking ) {
	$ms    = static fn( string $local ) => $at( $local ) * 1000;
	$track = static function ( int $n, string $first, int $user_id, string $device, string $start, string $last, int $stage, int $order_id ) use ( $wpdb, $ms, &$visitor_ids, &$visit_ids ): void {
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'visitors' ),
			array(
				'vid'      => md5( 'smoke-ops-' . $n . microtime() ),
				'user_id'  => $user_id,
				'first_ms' => $ms( $first ),
				'last_ms'  => $ms( $last ),
				'visits'   => 1,
				'device'   => $device,
				'is_test'  => 1,
			)
		);
		$visitor_id    = (int) $wpdb->insert_id;
		$visitor_ids[] = $visitor_id;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'visits' ),
			array(
				'visitor_id' => $visitor_id,
				'user_id'    => $user_id,
				'start_ms'   => $ms( $start ),
				'last_ms'    => $ms( $last ),
				'device'     => $device,
				'pageviews'  => 1,
				'stage'      => $stage,
				'order_id'   => $order_id,
				'is_test'    => 1,
			)
		);
		$visit_ids[] = (int) $wpdb->insert_id;
	};
	// V1：U1 登录访问，下了订单 A（300 秒）。
	$track( 1, '2020-01-15 09:00', $u1, 'desktop', '2020-01-15 09:00', '2020-01-15 09:05', 5, $order_a->get_id() );
	// V2：手机，加购（60 秒）。
	$track( 2, '2020-01-15 09:10', 0, 'mobile', '2020-01-15 09:10', '2020-01-15 09:11', 2, 0 );
	// V3：手机，只看了一下（0 秒）。
	$track( 3, '2020-01-15 09:20', 0, 'mobile', '2020-01-15 09:20', '2020-01-15 09:20', 0, 0 );
	// V4：回访用户（第一次来是 2019-12-01），发起结账（120 秒）。
	$track( 4, '2019-12-01 09:00', 0, 'desktop', '2020-01-15 09:30', '2020-01-15 09:32', 3, 0 );
	// V5：对比期，看了商品。
	$track( 5, '2020-01-14 09:00', 0, 'desktop', '2020-01-14 09:00', '2020-01-14 09:00', 1, 0 );
}

$range = Range::from_params( array( 'from' => '2020-01-15', 'to' => '2020-01-15' ) );
$s     = is_array( $range ) ? Operations::summary( $range ) : array();
$cur   = $s['current'] ?? array();
$prev  = $s['previous'] ?? array();
$chg   = $s['change'] ?? array();

// 1. 订单数和测试订单。
$ok( 'orders: 2 paid real orders this period, 1 in the previous period', 2 === ( $cur['orders'] ?? null ) && 1 === ( $prev['orders'] ?? null ) );
$ok( 'the test-payment order is left out and counted as 1 test order', 1 === ( $s['test_orders'] ?? null ) );

// 2. 销售额和客单价。
$ok( 'sales = 2p (3p minus the refund p), previous sales = p', $eq( $cur['sales'] ?? null, 2 * $p ) && $eq( $prev['sales'] ?? null, $p ) );
$ok( 'average order value = p', $eq( $cur['aov'] ?? null, $p ) && is_float( $cur['aov'] ?? null ) );

// 3. 件数、商品净销售额、退款。
$ok( 'product units sold = 2 (3 minus 1 refunded)', 2 === ( $cur['items'] ?? null ) );
$ok( 'net product sales = 2p', $eq( $cur['net_item_sales'] ?? null, 2 * $p ) );
$ok( 'refund amount = p this period, 0 in the previous period', $eq( $cur['refunds'] ?? null, $p ) && $eq( $prev['refunds'] ?? null, 0 ) );

// 4. 注册用户。
$ok( 'new sign-ups: 1 this period, 0 in the previous period', 1 === ( $cur['registered'] ?? null ) && 0 === ( $prev['registered'] ?? null ) );

// 5. 变化。
$ok( 'change: sales +100%, orders +100%', $eq( $chg['sales'] ?? null, 100.0 ) && $eq( $chg['orders'] ?? null, 100.0 ) );
$ok( 'change: refunds is null when the previous period is 0', array_key_exists( 'refunds', $chg ) && null === $chg['refunds'] );

// 6. 指标和漏斗的顺序。
$ok( 'KPI keys are exactly Operations::KPIS in order', array_keys( $cur ) === Operations::KPIS && array_keys( $prev ) === Operations::KPIS && array_keys( $chg ) === Operations::KPIS );
$ok( 'funnel layers are exactly Operations::LAYERS in order', array_column( $s['funnel']['current'] ?? array(), 'key' ) === Operations::LAYERS && array_column( $s['returning']['current'] ?? array(), 'key' ) === Operations::LAYERS );

if ( $tracking ) {
	$funnel     = $s['funnel']['current'] ?? array();
	$prev_layer = $s['funnel']['previous'] ?? array();
	$returning  = $s['returning']['current'] ?? array();

	// 7. 访问用户、移动端、平均访问时长。
	$ok( 'visited users: 4 this period, 1 in the previous period', 4 === ( $cur['visitors'] ?? null ) && 1 === ( $prev['visitors'] ?? null ) );
	$ok( 'mobile users: 2', 2 === ( $cur['mobile'] ?? null ) );
	$ok( 'average visit duration: 120 s this period, 0 s in the previous period', 120 === ( $cur['avg_duration'] ?? null ) && 0 === ( $prev['avg_duration'] ?? null ) );

	// 8. 转化率（变化是百分点差）。
	$ok( 'conversion: 50% this period, 100% in the previous period', $eq( $cur['conversion'] ?? null, 50.0 ) && $eq( $prev['conversion'] ?? null, 100.0 ) );
	$ok( 'conversion change is the difference in points (-50), visitors change +300%', $eq( $chg['conversion'] ?? null, -50.0 ) && $eq( $chg['visitors'] ?? null, 300.0 ) );

	// 9. 顾客数。
	$ok( 'customers: 2 (U1 once from visit and order, plus the guest email)', 2 === ( $cur['customers'] ?? null ) );

	// 10. 访客转化漏斗。
	$ok( 'visitor funnel counts 4, 3, 3, 2, 2', array( 4, 3, 3, 2, 2 ) === array_column( $funnel, 'count' ) );
	$ok( 'visitor funnel step rates -, 75, 100, 66.67, 100', $all_eq( array_column( $funnel, 'step_rate' ), array( null, 75.0, 100.0, 66.67, 100.0 ) ) );
	$ok( 'visitor funnel visitor rates 100, 75, 75, 50, 50', $all_eq( array_column( $funnel, 'visitor_rate' ), array( 100.0, 75.0, 75.0, 50.0, 50.0 ) ) );

	// 11. 对比期的访客转化漏斗。
	$ok( 'previous visitor funnel counts 1, 1, 0, 0, 1', array( 1, 1, 0, 0, 1 ) === array_column( $prev_layer, 'count' ) );

	// 12. 回访用户转化漏斗。
	$ok( 'returning visitor funnel counts 1, 1, 1, 1, 0', array( 1, 1, 1, 1, 0 ) === array_column( $returning, 'count' ) );

	// 13. 漏斗的变化。
	$ok( 'funnel change: visitors +300%', $eq( $s['funnel']['change']['visitors'] ?? null, 300.0 ) );
} else {
	$ok( 'tracking tables not installed, visitor checks skipped', true );
}

// 14–16. 接口。
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/operations' );
$req->set_param( 'from', '2020-01-15' );
$req->set_param( 'to', '2020-01-15' );
$res = rest_do_request( $req );
$ok( 'admin can load the operations data (200, 2 orders)', 200 === $res->get_status() && 2 === ( $res->get_data()['current']['orders'] ?? null ) );
$ok( 'operations response is not cached', 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/operations' );
$req->set_param( 'from', '2020-01-15' );
$req->set_param( 'to', '2020-01-10' );
$res = rest_do_request( $req );
$ok( 'from after to returns 400 pc_invalid_param', 400 === $res->get_status() && 'pc_invalid_param' === ( $res->get_data()['code'] ?? '' ) );
wp_set_current_user( $u1 );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/operations' );
$req->set_param( 'from', '2020-01-15' );
$req->set_param( 'to', '2020-01-15' );
$ok( 'customers cannot load the operations data', in_array( rest_do_request( $req )->get_status(), array( 401, 403 ), true ) );
wp_set_current_user( $admin->ID );

// 17. 运营数据的脚本只在运营数据页加载。
$_GET['page'] = 'pc-data-operations';
Data_Pages::enqueue();
$before = implode( '', (array) wp_scripts()->get_data( 'peptide-core-data-operations', 'before' ) );
$ok( 'operations script is enqueued on the operations page with its strings', wp_script_is( 'peptide-core-data-operations', 'enqueued' ) && false !== strpos( $before, 'window.pcDataOperations' ) );
wp_dequeue_script( 'peptide-core-data-operations' );
wp_dequeue_script( Data_Pages::SCRIPT );
$_GET['page'] = 'pc-data-products';
Data_Pages::enqueue();
$ok( 'operations script is not enqueued on other data pages, the shared data script is', ! wp_script_is( 'peptide-core-data-operations', 'enqueued' ) && wp_script_is( Data_Pages::SCRIPT, 'enqueued' ) );
wp_dequeue_script( Data_Pages::SCRIPT );

// 清理。
$order_ids = array( $order_a->get_id(), $order_b->get_id(), $order_c->get_id(), $order_d->get_id(), $order_e->get_id() );
if ( $refund_id ) {
	$saved_refund = wc_get_order( $refund_id );
	if ( $saved_refund ) {
		$saved_refund->delete( true );
	}
}
foreach ( $order_ids as $id ) {
	$order = wc_get_order( $id );
	if ( $order ) {
		$order->delete( true );
	}
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $u1 );
foreach ( $visit_ids as $id ) {
	$wpdb->delete( Schema::table( 'visits' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
foreach ( $visitor_ids as $id ) {
	$wpdb->delete( Schema::table( 'visitors' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
$_GET = $get_backup;

// 18. 测试数据都删掉了。
$left = 0;
foreach ( $order_ids as $id ) {
	$left += wc_get_order( $id ) ? 1 : 0;
}
$left += ( $refund_id && wc_get_order( $refund_id ) ) ? 1 : 0;
if ( $tracking ) {
	foreach ( $visitor_ids as $id ) {
		$left += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}
	foreach ( $visit_ids as $id ) {
		$left += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'visits' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
$ok( 'test data removed', 0 === $left && ! get_userdata( $u1 ) );
