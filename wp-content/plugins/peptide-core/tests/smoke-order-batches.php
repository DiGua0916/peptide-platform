<?php
/**
 * 订单与批次关联冒烟测试（BE-04，开发环境专用，需先运行 wp peptide demo seed）。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-order-batches.php
 * 会临时创建测试顾客和测试订单，结束时删除并恢复演示数据原状。不要在正式站点运行。
 *
 * 若设置了环境变量 PC_SMOKE_HTTP_FILE，会额外写出一个“顾客登录 Cookie + 带 nonce 的下载地址”，
 * 供 curl 走真实 HTTP 验证订单所有者下载隐藏批次的报告；该测试顾客保留，需再次运行本脚本并带 PC_SMOKE_CLEANUP=1 清理。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Access;
use PeptideCore\Admin\Order_Metabox;
use PeptideCore\Catalog;
use PeptideCore\Meta;
use PeptideCore\Order_Batches;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};
$get = static function ( string $route ) {
	return rest_do_request( new WP_REST_Request( 'GET', $route ) );
};

// 清理模式：删除上次 HTTP 测试留下的顾客和订单，恢复演示数据。
if ( getenv( 'PC_SMOKE_CLEANUP' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( get_users( array( 'search' => 'pc_smoke_*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
		foreach ( wc_get_orders( array( 'customer_id' => $u->ID, 'limit' => -1 ) ) as $o ) {
			$o->delete( true );
		}
		wp_delete_user( $u->ID );
	}
	$a2 = PeptideCore\Batches::find_id_by_batch_no( 'DEMO-A-2402' );
	update_post_meta( $a2, Meta::BATCH_VISIBILITY, 'public' );
	foreach ( PeptideCore\Reports::ids_for_batch( $a2 ) as $rid ) {
		update_post_meta( $rid, Meta::REPORT_ACCESS, 'registered' );
	}
	$ok( 'cleanup done', 'restricted' === Catalog::batch( $a2, 0 )['report_state'] );
	return;
}

$a1 = Catalog::public_batch_id_by_no( 'DEMO-A-2401' );
$a2 = Catalog::public_batch_id_by_no( 'DEMO-A-2402' );
$b1 = Catalog::public_batch_id_by_no( 'DEMO-B-2401' );
$r2 = Catalog::batch( $a2, 0 )['reports'][0]['id'];
$product_a = wc_get_product( (int) get_post_meta( $a1, Meta::BATCH_PRODUCT_ID, true ) );

$make_user = static fn() => wp_insert_user(
	array(
		'user_login' => 'pc_smoke_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'customer',
	)
);
$customer = $make_user();
$other    = $make_user();

$order = wc_create_order( array( 'customer_id' => $customer ) );
$order->add_product( $product_a, 2 );
$order->set_status( 'processing' );
$order->save();
$item_id = array_key_first( $order->get_items() );
$item    = $order->get_item( $item_id );

// 1. 可选批次只包含本商品的在售批次。
$cands = Order_Batches::candidates( $item );
$ok( 'candidates include A batches only', in_array( $a1, $cands, true ) && in_array( $a2, $cands, true ) && ! in_array( $b1, $cands, true ) );

// 2. 通过后台面板保存（模拟管理员提交表单）。
wp_set_current_user( 1 );
$_POST = array(
	'pc_assign_batches_nonce' => wp_create_nonce( 'pc_assign_batches' ),
	'pc_item_batch'           => array( $item_id => $b1 ),
);
Order_Metabox::save( $order->get_id() );
$order = wc_get_order( $order->get_id() );
$ok( 'wrong-product batch rejected', 0 === (int) $order->get_item( $item_id )->get_meta( Order_Batches::ITEM_META ) );

$_POST['pc_item_batch'] = array( $item_id => $a2 );
Order_Metabox::save( $order->get_id() );
$order = wc_get_order( $order->get_id() );
$ok( 'batch saved on order item', $a2 === (int) $order->get_item( $item_id )->get_meta( Order_Batches::ITEM_META ) );
$ok( 'order index has batch', in_array( (string) $a2, array_map( 'strval', wp_list_pluck( $order->get_meta( Order_Batches::ORDER_META, false ), 'value' ) ), true ) );
$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
$ok( 'order note recorded', $notes && str_contains( $notes[0]->content, 'DEMO-A-2402' ) );
$_POST = array();
delete_transient( 'pc_admin_notices_1' );

// 3. 订单所有者权限：报告设为“需审核”，所有者仍可下载；其他顾客不行。
update_post_meta( $r2, Meta::REPORT_ACCESS, 'approved' );
Order_Batches::flush_cache();
$ok( 'owner gets order_owner access', 'order_owner' === Access::for_report( $r2, $customer )['access_reason'] );
$ok( 'other customer still needs approval', 'approval_required' === Access::for_report( $r2, $other )['locked_reason'] );
update_post_meta( $r2, Meta::REPORT_ACCESS, 'admin' );
Order_Batches::flush_cache();
$ok( 'staff-only report not granted to owner', false === Access::for_report( $r2, $customer )['allowed'] );
update_post_meta( $r2, Meta::REPORT_ACCESS, 'approved' );

// 未付款订单不算。
$order->set_status( 'pending' );
$order->save();
Order_Batches::flush_cache();
$ok( 'unpaid order does not grant access', false === Access::for_report( $r2, $customer )['allowed'] );
$order->set_status( 'processing' );
$order->save();
Order_Batches::flush_cache();

// 4. /me/orders/{n}/batches
wp_set_current_user( $customer );
$res = $get( '/peptide/v1/me/orders/' . $order->get_order_number() . '/batches' );
$data = $res->get_data();
$ok( 'my order batches 200', 200 === $res->get_status() );
$ok( 'item shows batch DEMO-A-2402 downloadable', 'DEMO-A-2402' === ( $data['items'][0]['batch']['batch_no'] ?? null ) && true === ( $data['items'][0]['batch']['reports'][0]['can_download'] ?? null ) );
$ok( 'quantity 2', 2 === ( $data['items'][0]['quantity'] ?? null ) );

// 5. 我的账户订单详情里的批次与下载链接。
ob_start();
do_action( 'woocommerce_order_item_meta_end', $item_id, $order->get_item( $item_id ), $order, false );
$html = ob_get_clean();
$ok( 'my-account shows batch and report link', str_contains( $html, 'DEMO-A-2402' ) && str_contains( $html, '/peptide/v1/reports/' . $r2 . '/file' ) );
ob_start();
do_action( 'woocommerce_order_item_meta_end', $item_id, $order->get_item( $item_id ), $order, true );
$plain = ob_get_clean();
$ok( 'plain-text email shows batch only', str_contains( $plain, 'DEMO-A-2402' ) && ! str_contains( $plain, 'http' ) );

wp_set_current_user( $other );
$ok( 'other customer -> 404', 404 === $get( '/peptide/v1/me/orders/' . $order->get_order_number() . '/batches' )->get_status() );
wp_set_current_user( 0 );
$ok( 'guest -> 401', 401 === $get( '/peptide/v1/me/orders/' . $order->get_order_number() . '/batches' )->get_status() );

// 6. 隐藏批次：非所有者下载 404。
update_post_meta( $a2, Meta::BATCH_VISIBILITY, 'hidden' );
wp_set_current_user( $other );
$ok( 'hidden batch report, non-owner -> 404', 404 === $get( '/peptide/v1/reports/' . $r2 . '/file' )->get_status() );
wp_set_current_user( 0 );

$http_file = getenv( 'PC_SMOKE_HTTP_FILE' );
if ( $http_file ) {
	// 生成测试顾客的登录 Cookie 和与之匹配的 nonce，交给 curl 走真实 HTTP。
	$exp    = time() + HOUR_IN_SECONDS;
	$token  = WP_Session_Tokens::get_instance( $customer )->create( $exp );
	$cookie = wp_generate_auth_cookie( $customer, $exp, 'logged_in', $token );
	$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
	wp_set_current_user( $customer );
	file_put_contents(
		$http_file,
		wp_json_encode(
			array(
				'cookie' => LOGGED_IN_COOKIE . '=' . rawurlencode( $cookie ),
				'url'    => add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'peptide/v1/reports/' . $r2 . '/file' ) ),
			)
		)
	);
	echo "HTTP test data written; run again with PC_SMOKE_CLEANUP=1 afterwards.\n";
	return;
}

// 还原。
$order->delete( true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $customer );
wp_delete_user( $other );
update_post_meta( $a2, Meta::BATCH_VISIBILITY, 'public' );
update_post_meta( $r2, Meta::REPORT_ACCESS, 'registered' );
$ok( 'cleanup: demo state restored', 'restricted' === Catalog::batch( $a2, 0 )['report_state'] );
