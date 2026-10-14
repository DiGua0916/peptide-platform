<?php
/**
 * 后台工作台冒烟测试（BE-04b，开发环境专用，需先运行 wp peptide demo seed）。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-workbench.php
 * 会临时创建一个测试订单，结束时删除。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

// 测试中创建的订单不发送通知邮件。
add_filter( 'pre_wp_mail', '__return_false' );

use PeptideCore\Admin\Dashboard;
use PeptideCore\Catalog;
use PeptideCore\Meta;
use PeptideCore\Order_Batches;
use PeptideCore\Workbench;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

wp_set_current_user( 1 );

// 1. 面板注册：工作台在第一列最上面，快速草稿和新闻被移除。
require_once ABSPATH . 'wp-admin/includes/dashboard.php';
set_current_screen( 'dashboard' );
// 命令行下 is_admin() 为假，插件不会注册后台钩子，这里手动注册。
if ( ! has_action( 'wp_dashboard_setup', array( Dashboard::class, 'setup' ) ) ) {
	Dashboard::register_hooks();
}
wp_dashboard_setup();
global $wp_meta_boxes;
$normal_high = $wp_meta_boxes['dashboard']['normal']['high'] ?? array();
$ok( 'workbench registered first in column', Dashboard::WIDGET_ID === array_key_first( $normal_high ) );
$ok( 'quick draft removed', empty( $wp_meta_boxes['dashboard']['side']['core']['dashboard_quick_press'] ) );
$ok( 'news removed', empty( $wp_meta_boxes['dashboard']['side']['core']['dashboard_primary'] ) );

// 2. 演示数据下的初始状态。
$before = Workbench::orders_missing_batch()['count'];
$no_report = Workbench::batches_without_report();
$ok( 'DEMO-B-2401 listed as batch without report (pending)', in_array( 'DEMO-B-2401', array_column( $no_report['items'], 'batch_no' ), true ) && true === $no_report['items'][ array_search( 'DEMO-B-2401', array_column( $no_report['items'], 'batch_no' ), true ) ]['pending'] );
$ok( 'DEMO-A batches not listed (have reports)', ! in_array( 'DEMO-A-2401', array_column( $no_report['items'], 'batch_no' ), true ) );
$ok( 'demo records counted', Workbench::demo_record_count() >= 7 );

// 3. 已付款订单未指定批次 → 出现；指定后 → 消失。
$a1      = Catalog::public_batch_id_by_no( 'DEMO-A-2401' );
$product = wc_get_product( (int) get_post_meta( $a1, Meta::BATCH_PRODUCT_ID, true ) );
$order   = wc_create_order();
$order->add_product( $product, 1 );
$order->set_status( 'processing' );
$order->save();
$ok( 'processing order without batch counted', $before + 1 === Workbench::orders_missing_batch()['count'] );

ob_start();
Dashboard::render();
$html = ob_get_clean();
$ok( 'render lists the order', str_contains( $html, '#' . $order->get_order_number() ) );
$ok( 'render shows demo notice', str_contains( $html, 'wp peptide demo purge' ) );

Order_Batches::save_assignments( $order, array( array_key_first( $order->get_items() ) => $a1 ) );
$ok( 'order disappears after batch assigned', $before === Workbench::orders_missing_batch()['count'] );

// 4. 草稿报告计数。
$draft = wp_insert_post(
	array(
		'post_type'   => 'pc_report',
		'post_status' => 'publish',
		'post_title'  => 'Smoke draft',
		'meta_input'  => array( Meta::REPORT_STATUS => 'draft' ),
	)
);
$ok( 'draft report counted', Workbench::draft_report_count() >= 1 );

// 5. 无权限用户看不到工作台。
$sub = wp_insert_user(
	array(
		'user_login' => 'pc_smoke_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'subscriber',
	)
);
wp_set_current_user( $sub );
$ok( 'subscriber cannot view workbench', false === Dashboard::can_view() );

// 还原。
wp_set_current_user( 1 );
wp_delete_post( $draft, true );
$order->delete( true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $sub );
$ok( 'cleanup', $before === Workbench::orders_missing_batch()['count'] );
