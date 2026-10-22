<?php
/**
 * 后台菜单整理（ADMIN-MENU-01）冒烟测试：去掉文章 / 媒体 / 评论 / WooCommerce，订单、顾客、店铺设置移到新菜单后仍能打开。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-admin-menus.php
 * 在命令行里模拟一次后台菜单生成（手动载入 WooCommerce 的菜单类），不写数据库。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

global $menu, $submenu, $pagenow, $plugin_page;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
$pagenow     = 'admin.php';
$_GET['page'] = 'wc-orders';
$plugin_page = 'wc-orders';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once WC()->plugin_path() . '/includes/admin/wc-admin-functions.php';
require_once WC()->plugin_path() . '/includes/admin/class-wc-admin-menus.php';
new WC_Admin_Menus();
// HPOS 的订单页由 WooCommerce 在后台请求里注册，这里补一个同名子菜单来模拟。
add_action( 'admin_menu', static function () { add_submenu_page( 'woocommerce', 'Orders', 'Orders', 'edit_shop_orders', 'wc-orders', '__return_null' ); }, 9 );
\PeptideCore\Admin\Overview_Page::register_hooks();
\PeptideCore\Admin\Settings_Page::register_hooks();
\PeptideCore\Admin\Admin_Menus::register_hooks();
require ABSPATH . 'wp-admin/menu.php';

$slugs = array_column( $menu, 2 );
$ok( 'posts, media and comments removed', ! array_intersect( array( 'edit.php', 'upload.php', 'edit-comments.php' ), $slugs ) );
$ok( 'WooCommerce top-level menu removed', ! in_array( 'woocommerce', $slugs, true ) );
$ok( 'dashboard first, then orders and customers', 'pc-overview' === array_values( array_filter( $slugs ) )[0] && in_array( 'admin.php?page=wc-orders', $slugs, true ) && in_array( 'admin.php?page=wc-admin&path=/customers', $slugs, true ) );
$ok( 'store settings menu holds Woo settings, VialCove settings and status', array( 'admin.php?page=wc-settings', 'admin.php?page=pc-settings', 'admin.php?page=wc-status' ) === array_column( $submenu['admin.php?page=wc-settings'] ?? array(), 2 ) );
foreach ( array( 'wc-orders' => 'admin.php?page=wc-orders', 'wc-settings' => 'admin.php?page=wc-settings', 'pc-settings' => 'admin.php?page=wc-settings', 'wc-status' => 'admin.php?page=wc-settings' ) as $page => $parent ) {
	$_GET['page'] = $page;
	$plugin_page  = $page;
	$ok( "$page still opens and highlights the new menu", user_can_access_admin_page() && $parent === apply_filters( 'parent_file', get_admin_page_parent() ) );
}
