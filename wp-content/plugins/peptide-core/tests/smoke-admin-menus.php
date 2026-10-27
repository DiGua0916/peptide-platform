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
\PeptideCore\Admin\Store_Settings::register_hooks();
// WooCommerce 的营销菜单（Internal\Admin\Marketing 在后台请求里注册），这里补一个同名菜单来模拟。
// WooCommerce 的顶层“支付”菜单（PaymentsController 在后台请求里注册）。
add_action( 'admin_menu', static function () { add_menu_page( 'Payments', 'Payments', 'manage_woocommerce', 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM', null, 'dashicons-money-alt', 56 ); }, 6 );
// WooCommerce 分析（同样只在后台请求里注册）：顶层菜单 + 子菜单。
add_action( 'admin_menu', static function () { add_menu_page( 'Analytics', 'Analytics', 'view_woocommerce_reports', 'wc-admin&path=/analytics/overview', '__return_null', 'dashicons-chart-bar', 57 ); foreach ( array( 'overview', 'products', 'revenue', 'orders', 'settings' ) as $r ) { add_submenu_page( 'wc-admin&path=/analytics/overview', $r, $r, 'view_woocommerce_reports', 'wc-admin&path=/analytics/' . $r, '__return_null' ); } }, 6 );
add_action( 'admin_menu', static function () { add_menu_page( 'Marketing', 'Marketing', 'manage_woocommerce', 'woocommerce-marketing', '__return_null', 'dashicons-megaphone', 58 ); add_submenu_page( 'woocommerce-marketing', 'Coupons', 'Coupons', 'manage_woocommerce', 'edit.php?post_type=shop_coupon' ); }, 6 );
require ABSPATH . 'wp-admin/menu.php';

$slugs = array_column( $menu, 2 );
$ok( 'posts, media and comments removed', ! array_intersect( array( 'edit.php', 'upload.php', 'edit-comments.php' ), $slugs ) );
$ok( 'WooCommerce top-level menu removed', ! in_array( 'woocommerce', $slugs, true ) );
$ok( 'dashboard first, then orders and customers', 'pc-overview' === array_values( array_filter( $slugs ) )[0] && in_array( 'admin.php?page=wc-orders', $slugs, true ) && in_array( 'admin.php?page=wc-admin&path=/customers', $slugs, true ) );
$ok( 'WooCommerce marketing menu replaced by marketing campaigns: spend-and-save, coupon codes, shipping discounts', ! in_array( 'woocommerce-marketing', $slugs, true ) && in_array( 'pc-promotions', $slugs, true ) && array( 'pc-promotions', 'pc-coupons', 'pc-shipping-discounts' ) === array_column( $submenu['pc-promotions'] ?? array(), 2 ) );
$ok( 'app center menu', in_array( 'pc-apps', $slugs, true ) );
$ok( 'analytics keeps only its top-level menu, no submenus', in_array( 'wc-admin&path=/analytics/overview', $slugs, true ) && empty( $submenu['wc-admin&path=/analytics/overview'] ) );
$_GET['page'] = 'wc-settings';
$wc_tabs      = \PeptideCore\Admin\Admin_Menus::settings_tabs( array( 'general' => 'General', 'products' => 'Products', 'shipping' => 'Shipping', 'checkout' => 'Payments', 'account' => 'Accounts', 'email' => 'Emails', 'integration' => 'Integration', 'site-visibility' => 'Visibility', 'point-of-sale' => 'POS', 'wcpay_multi_currency' => 'Multi-currency', 'advanced' => 'Advanced' ) );
$ok( 'WooCommerce settings tab bar keeps general, products, accounts and advanced', array( 'general', 'products', 'account', 'advanced' ) === array_keys( $wc_tabs ) );
// WooCommerce 打开设置页时，只要这个标签的设置动作还在就照常显示（html-admin-settings.php 的 $tab_exists），所以隐藏的标签仍能直接打开。
include_once WC()->plugin_path() . '/includes/admin/class-wc-admin-settings.php';
WC_Admin_Settings::get_settings_pages();
$ok( 'hidden tabs still open from direct links (their settings actions remain)', has_action( 'woocommerce_settings_email' ) && has_action( 'woocommerce_settings_shipping' ) && has_action( 'woocommerce_settings_checkout' ) );
$_GET['page'] = 'wc-orders';
$ok( 'store settings menu: shipping, tax, system (payments has its own WooCommerce menu)', array( 'pc-shipping', 'pc-tax', 'pc-system' ) === array_column( $submenu['admin.php?page=wc-settings'] ?? array(), 2 ) );
foreach ( array( 'wc-orders' => 'admin.php?page=wc-orders', 'wc-settings' => 'admin.php?page=wc-settings', 'pc-settings' => 'admin.php?page=wc-settings', 'wc-status' => 'admin.php?page=wc-settings', 'pc-system' => 'admin.php?page=wc-settings', 'pc-tax' => 'admin.php?page=wc-settings' ) as $page => $parent ) {
	$_GET['page'] = $page;
	$plugin_page  = $page;
	$ok( "$page still opens and highlights the new menu", user_can_access_admin_page() && $parent === apply_filters( 'parent_file', get_admin_page_parent() ) );
}

// 店铺设置子菜单高亮（STORE-SETTINGS-01）。
$_GET['page'] = 'wc-settings';
$_GET['tab'] = 'checkout';
$ok( 'payment settings open under the WooCommerce payments menu', 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM' === apply_filters( 'parent_file', 'x' ) );
foreach ( array( 'shipping' => 'pc-shipping', 'tax' => 'pc-tax', 'checkout' => 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM', 'general' => 'pc-system', 'email' => 'pc-system' ) as $tab => $sub ) {
	$_GET['tab'] = $tab;
	$ok( "wc-settings tab $tab highlights $sub", $sub === apply_filters( 'submenu_file', null ) );
}
unset( $_GET['tab'] );
foreach ( array( 'pc-settings', 'wc-status' ) as $page ) {
	$_GET['page'] = $page;
	$ok( "$page sits under system settings", 'pc-system' === apply_filters( 'submenu_file', null ) );
}

// 系统设置页面：三张占位卡片 + 广告像素 + 其他设置；占位页列出现有替代设置。
$_GET = array( 'page' => 'pc-system' );
ob_start();
\PeptideCore\Admin\Store_Settings::render_system();
$html = ob_get_clean();
$ok( 'system settings shows the four section cards (with ad pixels)', 4 === substr_count( $html, 'page=pc-system&#038;section=' ) + substr_count( $html, 'page=pc-system&section=' ) && false !== strpos( $html, 'section=pixels' ) );
$ok( 'system settings links the old store settings pages', false !== strpos( $html, 'page=pc-settings' ) && false !== strpos( $html, 'page=wc-status' ) && false !== strpos( $html, 'tab=general' ) );
$_GET['section'] = 'email';
ob_start();
\PeptideCore\Admin\Store_Settings::render_system();
$html = ob_get_clean();
$ok( 'email notifications section opens the email center', false !== strpos( $html, 'pc_email_toggle' ) && false === strpos( $html, 'pc-ss-placeholder' ) );
$_GET['section'] = 'terms';
ob_start();
\PeptideCore\Admin\Store_Settings::render_system();
$html = ob_get_clean();
$ok( 'terms of sale section opens the terms editor', false !== strpos( $html, 'pc_terms_save' ) && false === strpos( $html, 'pc-ss-placeholder' ) );
$_GET['section'] = 'nope';
ob_start();
\PeptideCore\Admin\Store_Settings::render_system();
$ok( 'unknown section falls back to the card list', false !== strpos( ob_get_clean(), 'pc-ss-grid' ) );

// 税费：关闭时显示开启按钮（不在测试里真的开启）。
if ( ! wc_tax_enabled() ) {
	ob_start();
	\PeptideCore\Admin\Tax_Page::render();
	$html = ob_get_clean();
	$ok( 'tax page offers to turn taxes on with a nonce', false !== strpos( $html, 'pc_enable_tax' ) && false !== strpos( $html, '_wpnonce' ) );
}
// 左侧菜单不弹悬停预览（只在宽菜单、用鼠标时；收起的菜单和触屏保留）。
ob_start();
\PeptideCore\Admin\Admin_Menus::no_flyout();
$css = ob_get_clean();
$ok( 'menu hover flyout hidden for the wide menu with a mouse, kept when folded', str_contains( $css, 'li.wp-not-current-submenu>.wp-submenu{display:none}' ) && str_contains( $css, 'body:not(.folded)' ) && str_contains( $css, 'pointer:fine' ) );
wp_set_current_user( 0 );
ob_start();
\PeptideCore\Admin\Store_Settings::render_system();
$ok( 'visitors see nothing', '' === ob_get_clean() );
$_GET = array();
