<?php
/**
 * 后台“数据”板块（DATA-01 第 1 步）冒烟测试：店铺时区的时间段（预设 / 自定义 / 对比时间段 / 夏令时 / 错误参数）、
 * 变化百分比、GET /peptide/v1/data/range 接口和权限、九个页面的菜单顺序与位置、页面脚本的加载。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-data.php
 * 只在命令行里模拟一次后台菜单生成，不写数据库；临时创建的顾客账号在结束时彻底删除。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Admin_Menus;
use PeptideCore\Admin\Data_Pages;
use PeptideCore\Data\Range;
use PeptideCore\Overview;

// $menu_order / $default_menu_order：wp-admin/includes/menu.php 的排序函数按全局变量读它们，命令行里 eval-file 在函数里执行，要先声明成全局。
global $menu, $submenu, $pagenow, $plugin_page, $menu_order, $default_menu_order;

$ok =static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

// 参数错误：400 + pc_invalid_param，并且 fields 里有这个字段和原因。
$bad = static function ( $r, string $field, string $reason ): bool {
	if ( ! is_wp_error( $r ) || 'pc_invalid_param' !== $r->get_error_code() ) {
		return false;
	}
	$data = $r->get_error_data();
	return 400 === ( $data['status'] ?? 0 ) && ( $data['fields'][ $field ] ?? '' ) === $reason;
};

$get_backup = $_GET;
$admin      = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$tz = wp_timezone();

// 固定的“现在”：店铺时区（America/Los_Angeles）2026-10-22 05:00。
$now = strtotime( '2026-10-22 12:00:00 UTC' );

// 1. 什么都不传 = 今天。
$a = Range::from_params( array(), $now );
$b = Range::from_params( array( 'range' => 'today' ), $now );
$ok( 'no parameters means today: same as range=today, 1 day, hourly', is_array( $a ) && $a === $b && 'today' === $a['preset'] && 1 === $a['days'] && 'hour' === $a['bucket'] );

// 2. 预设和概览页的时间段完全一致。
foreach ( Range::PRESETS as $preset ) {
	$r = Range::from_params( array( 'range' => $preset ), $now );
	$w = Overview::window( $preset, $now );
	$ok( "preset $preset matches Overview::window()", is_array( $r ) && $preset === $r['preset'] && $w[0] === $r['from'] && $w[1] === $r['to'] );
}

// 3. 最近 7 天：7 天、按天、对比 = 之前的 7 天。
$r = Range::from_params( array( 'range' => '7d' ), $now );
$ok( 'last 7 days: 7 days, daily, comparison ends where the period starts', is_array( $r ) && 7 === $r['days'] && 'day' === $r['bucket'] && $r['compare_to'] === $r['from'] );
$ok( 'last 7 days: comparison starts exactly 7 calendar days earlier', is_array( $r ) && '2026-10-16' === $r['dates']['from'] && '2026-10-22' === $r['dates']['to'] && '2026-10-09' === $r['dates']['compare_from'] && '2026-10-15' === $r['dates']['compare_to'] && ( new DateTimeImmutable( '@' . $r['from'] ) )->setTimezone( $tz )->modify( '-7 days' )->getTimestamp() === $r['compare_from'] );

// 4. 今天的默认对比是昨天。
$r = Range::from_params( array( 'range' => 'today' ), $now );
$y = Overview::window( 'yesterday', $now );
$ok( 'today is compared with yesterday by default', is_array( $r ) && $y[0] === $r['compare_from'] && $y[1] === $r['compare_to'] && '2026-10-21' === $r['dates']['compare_from'] && '2026-10-21' === $r['dates']['compare_to'] );

// 5. 自定义时间段。
$r = Range::from_params( array( 'from' => '2026-10-01', 'to' => '2026-10-03' ), $now );
$ok( 'custom range: no preset, 3 days, daily', is_array( $r ) && null === $r['preset'] && 3 === $r['days'] && 'day' === $r['bucket'] );
$ok( 'custom range: from is the first day 00:00 and to the next day after the last one 00:00 in store time', is_array( $r ) && '2026-10-01 00:00' === wp_date( 'Y-m-d H:i', $r['from'] ) && '2026-10-04 00:00' === wp_date( 'Y-m-d H:i', $r['to'] ) && '2026-10-01' === $r['dates']['from'] && '2026-10-03' === $r['dates']['to'] );
$ok( 'custom range: default comparison is the 3 days before', is_array( $r ) && '2026-09-28' === $r['dates']['compare_from'] && '2026-09-30' === $r['dates']['compare_to'] && '2026-09-28 00:00' === wp_date( 'Y-m-d H:i', $r['compare_from'] ) && '2026-10-01 00:00' === wp_date( 'Y-m-d H:i', $r['compare_to'] ) );

// 6. 自定义的单日。
$r = Range::from_params( array( 'from' => '2026-10-05', 'to' => '2026-10-05' ), $now );
$ok( 'custom single day: 1 day, hourly', is_array( $r ) && 1 === $r['days'] && 'hour' === $r['bucket'] );

// 7. 夏令时：2026-11-01 美国夏令时结束，这一天有 25 小时。
$r = Range::from_params( array( 'from' => '2026-11-01', 'to' => '2026-11-01' ), $now );
$ok( 'DST end day is one calendar day of 25 hours', is_array( $r ) && 1 === $r['days'] && 25 * 3600 === $r['to'] - $r['from'] );

// 8. 指定对比时间段（长度可以和主时间段不同）。
$r = Range::from_params( array( 'from' => '2026-10-01', 'to' => '2026-10-03', 'compare_from' => '2026-09-01', 'compare_to' => '2026-09-10' ), $now );
$ok( 'explicit comparison range is used as given', is_array( $r ) && 3 === $r['days'] && '2026-09-01' === $r['dates']['compare_from'] && '2026-09-10' === $r['dates']['compare_to'] && '2026-09-01 00:00' === wp_date( 'Y-m-d H:i', $r['compare_from'] ) && '2026-09-11 00:00' === wp_date( 'Y-m-d H:i', $r['compare_to'] ) );

// 9. 错误参数。
$ok( 'unknown range is rejected (range: invalid_format)', $bad( Range::from_params( array( 'range' => 'week' ), $now ), 'range', 'invalid_format' ) );
$ok( 'from without to is rejected (to: required)', $bad( Range::from_params( array( 'from' => '2026-10-01' ), $now ), 'to', 'required' ) );
$ok( 'to without from is rejected (from: required)', $bad( Range::from_params( array( 'to' => '2026-10-01' ), $now ), 'from', 'required' ) );
$ok( 'month 13 is rejected (from: invalid_format)', $bad( Range::from_params( array( 'from' => '2026-13-01', 'to' => '2026-13-02' ), $now ), 'from', 'invalid_format' ) );
$ok( 'February 30 is rejected (from: invalid_format)', $bad( Range::from_params( array( 'from' => '2026-02-30', 'to' => '2026-03-01' ), $now ), 'from', 'invalid_format' ) );
$ok( 'text that is not a date is rejected (from: invalid_format)', $bad( Range::from_params( array( 'from' => '10/01/2026', 'to' => '2026-10-02' ), $now ), 'from', 'invalid_format' ) );
$ok( 'from after to is rejected (from: invalid_format)', $bad( Range::from_params( array( 'from' => '2026-10-05', 'to' => '2026-10-01' ), $now ), 'from', 'invalid_format' ) );
$ok( 'more than 366 days is rejected (to: invalid_format)', $bad( Range::from_params( array( 'from' => '2025-01-01', 'to' => '2026-12-31' ), $now ), 'to', 'invalid_format' ) );
$ok( 'exactly 366 days is accepted', is_array( Range::from_params( array( 'from' => '2025-12-31', 'to' => '2026-12-31' ), $now ) ) );
$ok( 'compare_from without compare_to is rejected (compare_to: required)', $bad( Range::from_params( array( 'range' => '7d', 'compare_from' => '2026-09-01' ), $now ), 'compare_to', 'required' ) );
$ok( 'invalid comparison date is rejected (compare_from: invalid_format)', $bad( Range::from_params( array( 'range' => '7d', 'compare_from' => '2026-09-31', 'compare_to' => '2026-10-01' ), $now ), 'compare_from', 'invalid_format' ) );

// 10. 变化百分比。
$ok( 'change(120, 100) is 20.0', 20.0 === Range::change( 120, 100 ) );
$ok( 'change(50, 100) is -50.0', -50.0 === Range::change( 50, 100 ) );
$ok( 'change(5, 0) is null', null === Range::change( 5, 0 ) );
$ok( 'change(0, 0) is null', null === Range::change( 0, 0 ) );
$ok( 'change(1, 3) is -66.67', -66.67 === Range::change( 1, 3 ) );

// 11–14. 接口。
$res  = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/data/range' ) );
$data = $res->get_data();
$ok( 'admin can load the range', 200 === $res->get_status() && is_array( $data ) && ! array_diff( array( 'preset', 'from', 'to', 'compare_from', 'compare_to', 'days', 'bucket', 'dates' ), array_keys( $data ) ) );
$ok( 'range response is not cached', 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/range' );
$req->set_param( 'range', '7d' );
$res = rest_do_request( $req );
$ok( 'range=7d returns 7 days', 200 === $res->get_status() && 7 === ( $res->get_data()['days'] ?? 0 ) );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/range' );
$req->set_param( 'from', '2026-10-05' );
$req->set_param( 'to', '2026-10-01' );
$res = rest_do_request( $req );
$ok( 'from after to returns 400 pc_invalid_param', 400 === $res->get_status() && 'pc_invalid_param' === ( $res->get_data()['code'] ?? '' ) );

$customer = wp_insert_user( array( 'user_login' => 'smoke_data_user', 'user_email' => 'smoke-data@example.com', 'user_pass' => wp_generate_password(), 'role' => 'customer' ) );
wp_set_current_user( (int) $customer );
$ok( 'customers cannot load the range', in_array( rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/data/range' ) )->get_status(), array( 401, 403 ), true ) );
wp_set_current_user( $admin->ID );

// 15–17. 菜单：和 smoke-admin-menus.php 一样，在命令行里模拟一次后台菜单生成。
$pagenow      = 'admin.php';
$_GET['page'] = 'pc-data';
$plugin_page  = 'pc-data';
require_once ABSPATH . 'wp-admin/includes/admin.php';
Admin_Menus::register_hooks();
Data_Pages::register_hooks();
require ABSPATH . 'wp-admin/menu.php';

$slugs = array_column( $menu, 2 );
$ok( 'Data top-level menu exists', in_array( 'pc-data', $slugs, true ) );
$ok( 'Data submenu has the nine pages in Shopname order', array_values( Data_Pages::PAGES ) === array_column( $submenu['pc-data'] ?? array(), 2 ) && 9 === count( $submenu['pc-data'] ?? array() ) );
$promo = array_search( 'pc-promotions', $slugs, true );
$mine  = array_search( 'pc-data', $slugs, true );
$store = array_search( 'admin.php?page=wc-settings', $slugs, true );
$ok( 'Data comes after marketing campaigns and before store settings', false !== $promo && false !== $mine && false !== $store && $promo < $mine && $mine < $store );

// 18. 页面脚本只在数据页面加载。
$_GET['page'] = 'pc-data-operations';
Data_Pages::enqueue();
$before = implode( '', (array) wp_scripts()->get_data( Data_Pages::SCRIPT, 'before' ) );
$ok( 'data script is enqueued on a data page with its settings', wp_script_is( Data_Pages::SCRIPT, 'enqueued' ) && false !== strpos( $before, 'window.pcData' ) && false !== strpos( $before, '"page":"operations"' ) );
wp_dequeue_script( Data_Pages::SCRIPT );
wp_dequeue_script( 'peptide-core-data-operations' );
$_GET['page'] = 'pc-overview';
Data_Pages::enqueue();
$ok( 'data script is not enqueued on other pages', ! wp_script_is( Data_Pages::SCRIPT, 'enqueued' ) );

// 19. 当前页面是哪一个。
$_GET['page'] = 'pc-data';
$k1           = Data_Pages::current_key();
$_GET['page'] = 'pc-data-emails';
$k2           = Data_Pages::current_key();
$_GET['page'] = 'pc-overview';
$k3           = Data_Pages::current_key();
$ok( 'current_key maps page slugs to keys', 'realtime' === $k1 && 'emails' === $k2 && null === $k3 );

// 清理。
wp_delete_user( (int) $customer );
$_GET = $get_backup;
