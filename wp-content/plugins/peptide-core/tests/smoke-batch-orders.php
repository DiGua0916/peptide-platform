<?php
/**
 * 批次反查订单冒烟测试（开发环境专用，需先运行 wp peptide demo seed）。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-batch-orders.php
 * 会临时创建测试订单和账号，结束时删除。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

// 测试中创建的订单不发送通知邮件。
add_filter( 'pre_wp_mail', '__return_false' );

use PeptideCore\Admin\Batch_Orders;
use PeptideCore\Catalog;
use PeptideCore\Meta;
use PeptideCore\Order_Batches;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$a1        = Catalog::public_batch_id_by_no( 'DEMO-A-2401' );
$a2        = Catalog::public_batch_id_by_no( 'DEMO-A-2402' );
$product_a = wc_get_product( (int) get_post_meta( $a1, Meta::BATCH_PRODUCT_ID, true ) );
$before    = count( Order_Batches::orders_for_batch( $a1 ) );

$make_user = static fn( string $role ) => wp_insert_user(
	array(
		'user_login' => 'pc_smoke_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => $role,
	)
);
$customer = $make_user( 'customer' );

$make_order = static function ( int $qty, string $status, int $user_id, string $first_name ) use ( $product_a ) {
	$order = wc_create_order( array( 'customer_id' => $user_id ) );
	$order->add_product( $product_a, $qty );
	$order->set_billing_first_name( $first_name );
	$order->set_billing_last_name( 'Smoke' );
	$order->set_billing_email( strtolower( preg_replace( '/[^a-z]/i', '', $first_name ) ) . '@example.com' );
	$order->set_status( $status );
	$order->save();
	return $order;
};

// 三个订单收到 A1（其中一个已取消，名字带公式字符用于测试 CSV 注入），一个订单收到 A2。
$o1 = $make_order( 2, 'processing', 0, 'Guest' );
$o2 = $make_order( 1, 'completed', $customer, '=HYPERLINK("http://evil.example")' );
$o3 = $make_order( 1, 'cancelled', 0, 'Cancelled' );
$o4 = $make_order( 5, 'processing', 0, 'Other' );
foreach ( array( $o1, $o2, $o3 ) as $o ) {
	Order_Batches::save_assignments( $o, array( array_key_first( $o->get_items() ) => $a1 ) );
}
Order_Batches::save_assignments( $o4, array( array_key_first( $o4->get_items() ) => $a2 ) );

// 1. 反查结果。
$rows = Order_Batches::orders_for_batch( $a1 );
$ids  = array_column( $rows, 'order_id' );
$ok( 'three orders found for A1', $before + 3 === count( $rows ) );
$ok( 'A2-only order not included', ! in_array( $o4->get_id(), $ids, true ) );
$ok( 'cancelled order still listed (for recall)', in_array( $o3->get_id(), $ids, true ) );
$mine = array_values( array_filter( $rows, static fn( $r ) => in_array( $r['order_id'], array( $o1->get_id(), $o2->get_id(), $o3->get_id() ), true ) ) );
$ok( 'quantity summed per order (2+1+1)', 4 === array_sum( array_column( $mine, 'quantity' ) ) );
$ok( 'customer email included', in_array( 'guest@example.com', array_column( $mine, 'email' ), true ) );

// 2. 索引过期时以订单行为准：把 o3 的订单行改成 A2，但不重建订单索引。
$item = $o3->get_item( array_key_first( $o3->get_items() ) );
$item->update_meta_data( Order_Batches::ITEM_META, $a2 );
$item->save();
$ok( 'stale index ignored (order line wins)', ! in_array( $o3->get_id(), array_column( Order_Batches::orders_for_batch( $a1 ), 'order_id' ), true ) );

// 3. CSV：BOM、表头、公式注入防护。
$csv = Order_Batches::orders_csv( 'DEMO-A-2401', $mine );
$ok( 'csv has BOM and header', str_starts_with( $csv, "\xEF\xBB\xBFbatch_no,order_number" ) );
$ok( 'csv formula injection neutralised', str_contains( $csv, "'=HYPERLINK" ) && ! preg_match( '/,"?=HYPERLINK/', $csv ) );

// 4. 后台面板与权限。
wp_set_current_user( 1 );
ob_start();
Batch_Orders::render( get_post( $a1 ) );
$html = ob_get_clean();
$ok( 'admin panel lists order and export link', str_contains( $html, '#' . $o1->get_order_number() ) && str_contains( $html, 'action=pc_export_batch_orders' ) && str_contains( $html, '_wpnonce=' ) );
$manager = $make_user( 'shop_manager' );
wp_set_current_user( $manager );
$ok( 'shop_manager can view', Batch_Orders::can_view( $a1 ) );
wp_set_current_user( $customer );
$ok( 'customer cannot view', ! Batch_Orders::can_view( $a1 ) );
wp_set_current_user( 0 );
$ok( 'guest cannot view', ! Batch_Orders::can_view( $a1 ) );

// 5. 命令行。
$res  = WP_CLI::runcommand(
	'peptide batch orders demo-a-2401 --format=json',
	array(
		'return'     => 'all',
		'launch'     => false,
		'exit_error' => false,
	)
);
$json = json_decode( (string) $res->stdout, true );
$ok( 'cli lists orders for batch (case-insensitive)', 0 === $res->return_code && is_array( $json ) && count( $json ) === count( Order_Batches::orders_for_batch( $a1 ) ) );

// 还原。
foreach ( array( $o1, $o2, $o3, $o4 ) as $o ) {
	$o->delete( true );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $customer );
wp_delete_user( $manager );
$ok( 'cleanup', $before === count( Order_Batches::orders_for_batch( $a1 ) ) );
