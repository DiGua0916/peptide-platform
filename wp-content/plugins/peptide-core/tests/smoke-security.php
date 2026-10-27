<?php
/**
 * 安全检查（2026-10-15）发现问题的回归测试（开发环境专用，需先运行 wp peptide demo seed）。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-security.php
 * 会临时创建账号、订单、商品并修改少量元数据，结束时全部还原。不要在正式站点运行。
 *
 * 真实 HTTP 部分（Cookie + pc_nonce 下载）需要配合环境变量 PC_SEC_HTTP_FILE 另行执行，见 backend.md。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

// 测试中创建的订单不发送通知邮件。
add_filter( 'pre_wp_mail', '__return_false' );

use PeptideCore\Access;
use PeptideCore\Api\Rest_Controller;
use PeptideCore\Capabilities;
use PeptideCore\Catalog;
use PeptideCore\Installer;
use PeptideCore\Meta;
use PeptideCore\Order_Batches;
use PeptideCore\Settings;

$ok  = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};
$get = static function ( string $route, array $params = array() ) {
	$request = new WP_REST_Request( 'GET', $route );
	foreach ( $params as $k => $v ) {
		$request->set_param( $k, $v );
	}
	return rest_do_request( $request );
};

$a1        = Catalog::public_batch_id_by_no( 'DEMO-A-2401' );
$a2        = Catalog::public_batch_id_by_no( 'DEMO-A-2402' );
$r1        = Catalog::batch( $a1, 0 )['reports'][0]['id'];
$r2        = Catalog::batch( $a2, 0 )['reports'][0]['id'];
$product_a = wc_get_product( (int) get_post_meta( $a1, Meta::BATCH_PRODUCT_ID, true ) );
$customer  = wp_insert_user(
	array(
		'user_login' => 'pc_smoke_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'customer',
	)
);

// 1. 订单所有者判断“查不到就不放行”：顾客有一张收到 A1 的已付款订单，但没有收到 A2。
$order = wc_create_order( array( 'customer_id' => $customer ) );
$order->add_product( $product_a, 1 );
$order->set_status( 'processing' );
$order->save();
Order_Batches::save_assignments( $order, array( array_key_first( $order->get_items() ) => $a1 ) );
update_post_meta( $r2, Meta::REPORT_ACCESS, 'approved' );
foreach ( array( 'HPOS meta_query' => true, 'order scan (legacy-style)' => false ) as $mode => $use_meta ) {
	$filter = static fn() => $use_meta;
	add_filter( 'peptide_core_use_order_meta_query', $filter );
	Order_Batches::flush_cache();
	$ok( "[$mode] owner of A1 order received A1", Order_Batches::user_received_batch( $customer, $a1 ) );
	$ok( "[$mode] owner of A1 order did NOT receive A2", ! Order_Batches::user_received_batch( $customer, $a2 ) );
	$ok( "[$mode] A2 approved report stays locked for that customer", 'approval_required' === Access::for_report( $r2, $customer )['locked_reason'] );
	$ok( "[$mode] reverse lookup finds the order", in_array( $order->get_id(), Order_Batches::order_ids_with_batch( $a1 ), true ) && ! in_array( $order->get_id(), Order_Batches::order_ids_with_batch( $a2 ), true ) );
	remove_filter( 'peptide_core_use_order_meta_query', $filter );
}
update_post_meta( $r2, Meta::REPORT_ACCESS, 'registered' );
Order_Batches::flush_cache();

// 2. 撤回报告不暴露存在性：不可见时 404，可见时才 410。
wp_set_current_user( 0 );
update_post_meta( $r1, Meta::REPORT_STATUS, 'withdrawn' );
$ok( 'withdrawn report in public batch -> 410', 410 === $get( '/peptide/v1/reports/' . $r1 . '/file' )->get_status() );
update_post_meta( $a1, Meta::BATCH_VISIBILITY, 'hidden' );
$ok( 'withdrawn report in hidden batch -> 404 for guest', 404 === $get( '/peptide/v1/reports/' . $r1 . '/file' )->get_status() );
update_post_meta( $a1, Meta::BATCH_VISIBILITY, 'public' );
update_post_meta( $r1, Meta::REPORT_ACCESS, 'admin' );
$ok( 'withdrawn staff-only report -> 404 for guest', 404 === $get( '/peptide/v1/reports/' . $r1 . '/file' )->get_status() );
update_post_meta( $r1, Meta::REPORT_ACCESS, 'public' );
update_post_meta( $r1, Meta::REPORT_STATUS, 'published' );

// 3. 下载地址只带这份报告专用的 nonce。
wp_set_current_user( $customer );
$url = Catalog::download_url( $r1, $customer );
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
$ok( 'download url has pc_nonce, no wp_rest nonce', isset( $q['pc_nonce'] ) && ! isset( $q['_wpnonce'] ) );
$ok( 'pc_nonce is scoped to that report only', false !== wp_verify_nonce( $q['pc_nonce'], 'pc_report_' . $r1 ) && false === wp_verify_nonce( $q['pc_nonce'], 'pc_report_' . $r2 ) && false === wp_verify_nonce( $q['pc_nonce'], 'wp_rest' ) );
wp_set_current_user( 0 );

// 4. 设置第一次保存（option 不存在）时，结账确认不会被吞掉。
$original = get_option( Settings::OPTION, null );
delete_option( Settings::OPTION );
add_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
update_option(
	Settings::OPTION,
	array(
		'brand_name'           => 'VialCove',
		'demo_mode'            => '1',
		'checkout_ack_enabled' => '1',
		'checkout_ack_label'   => 'SECURITY TEST label',
	)
);
remove_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
$ok( 'first settings save keeps checkout confirmation', 'SECURITY TEST label' === Settings::checkout_ack_label() );
if ( null === $original ) {
	delete_option( Settings::OPTION );
} else {
	update_option( Settings::OPTION, $original );
}

// 5. 含中文的商品 slug 可以查询；设了密码的商品不公开批次。
$cn = new WC_Product_Simple();
$cn->set_name( '安全测试商品' );
$cn->set_status( 'publish' );
$cn_id   = $cn->save();
$cn_slug = get_post_field( 'post_name', $cn_id );
$ok( 'non-ASCII slug (encoded) -> 200', 200 === $get( '/peptide/v1/products/' . $cn_slug . '/batches' )->get_status() );
$ok( 'non-ASCII slug (decoded) -> 200', 200 === $get( '/peptide/v1/products/' . rawurldecode( $cn_slug ) . '/batches' )->get_status() );
wp_update_post(
	array(
		'ID'            => $cn_id,
		'post_password' => 'secret-test',
	)
);
$ok( 'password-protected product -> 404', 404 === $get( '/peptide/v1/products/' . $cn_slug . '/batches' )->get_status() );
wc_get_product( $cn_id )->delete( true );

// 6. 限流用的访客 IP：只信任配置过的代理；IPv6 按 /64 归并。
$server = $_SERVER;
$_SERVER['REMOTE_ADDR']           = '203.0.113.7';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.9';
$ok( 'forwarded header ignored without trusted proxy', '203.0.113.7' === Rest_Controller::client_ip() );
$_SERVER['REMOTE_ADDR'] = '2001:db8:aaaa:bbbb:1:2:3:4';
unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
$ok( 'IPv6 grouped by /64', '2001:db8:aaaa:bbbb::/64' === Rest_Controller::client_ip() );
$_SERVER = $server;

// 7. 升级时只补授新增能力，不恢复管理员手动移除的能力；重新启用插件时全部恢复。
Installer::activate(); // 先建立“已授能力”记录（第一次升级到带记录的版本时会全部授一次）。
$sm = get_role( 'shop_manager' );
$sm->remove_cap( 'pc_assign_batches' );
update_option( Installer::OPTION_VERSION, '0.0.1' );
Installer::maybe_upgrade();
$ok( 'upgrade does not re-grant manually removed cap', ! get_role( 'shop_manager' )->has_cap( 'pc_assign_batches' ) );
$ok( 'upgrade records current version', PEPTIDE_CORE_VERSION === get_option( Installer::OPTION_VERSION ) );
Installer::activate();
$ok( 'activate restores all caps', get_role( 'shop_manager' )->has_cap( 'pc_assign_batches' ) && ! get_role( 'shop_manager' )->has_cap( 'pc_manage_settings' ) );

// 还原。
$order->delete( true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $customer );
$ok( 'cleanup: demo state restored', 'available' === Catalog::batch( $a1, 0 )['report_state'] && 'restricted' === Catalog::batch( $a2, 0 )['report_state'] );
