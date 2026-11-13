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
\PeptideCore\Admin\Recovery_Page::register_hooks();
\PeptideCore\Admin\Tracking_Page::register_hooks();
\PeptideCore\Admin\Blog_Admin::register_hooks();
\PeptideCore\Admin\Media_Page::register_hooks();
\PeptideCore\Admin\Activity_Page::register_hooks();
\PeptideCore\Admin\Staff_Page::register_hooks();
// WooCommerce 的营销菜单（Internal\Admin\Marketing 在后台请求里注册），这里补一个同名菜单来模拟。
// WooCommerce 的顶层“支付”菜单（PaymentsController 在后台请求里注册）。
add_action( 'admin_menu', static function () { add_menu_page( 'Payments', 'Payments', 'manage_woocommerce', 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM', null, 'dashicons-money-alt', 56 ); }, 6 );
// WooCommerce 分析（同样只在后台请求里注册）：顶层菜单 + 子菜单。
add_action( 'admin_menu', static function () { add_menu_page( 'Analytics', 'Analytics', 'view_woocommerce_reports', 'wc-admin&path=/analytics/overview', '__return_null', 'dashicons-chart-bar', 57 ); foreach ( array( 'overview', 'products', 'revenue', 'orders', 'settings' ) as $r ) { add_submenu_page( 'wc-admin&path=/analytics/overview', $r, $r, 'view_woocommerce_reports', 'wc-admin&path=/analytics/' . $r, '__return_null' ); } }, 6 );
add_action( 'admin_menu', static function () { add_menu_page( 'Marketing', 'Marketing', 'manage_woocommerce', 'woocommerce-marketing', '__return_null', 'dashicons-megaphone', 58 ); add_submenu_page( 'woocommerce-marketing', 'Coupons', 'Coupons', 'manage_woocommerce', 'edit.php?post_type=shop_coupon' ); }, 6 );
require ABSPATH . 'wp-admin/menu.php';

$slugs = array_column( $menu, 2 );
$ok( 'media and comments removed (posts are the Blog menu now)', ! array_intersect( array( 'upload.php', 'edit-comments.php' ), $slugs ) );
// 2026-10-27 照 YY：……访客数据、博客、店铺设置、后台设置、应用中心；员工管理在店铺设置里。
$pos = array_flip( array_values( array_filter( $slugs, static fn( $s ) => $s && ! str_starts_with( (string) $s, 'separator' ) ) ) );
// 店主 2026-11-10：博客先隐藏（Admin_Menus::hide_menus()，页面地址还在）；后台设置仍在店铺设置和插件之间。
$ok( 'Blog is hidden from the menu; Admin settings between Store settings and App center', ! isset( $pos['edit.php'] ) && isset( $pos['pc-visitors'], $pos['pc-media'], $pos['pc-apps'], $pos['admin.php?page=wc-settings'] ) && $pos['pc-media'] === $pos['admin.php?page=wc-settings'] + 1 && $pos['pc-apps'] === $pos['pc-media'] + 1 );
$ok( 'Blog has Blog posts and Blog collections only; Admin settings has Media library and Activity log', array( 'edit.php', 'edit-tags.php?taxonomy=category' ) === array_column( $submenu['edit.php'] ?? array(), 2 ) && array( 'pc-media', 'pc-activity' ) === array_column( $submenu['pc-media'] ?? array(), 2 ) );
$ok( 'appearance, tools and settings removed', ! array_intersect( array( 'themes.php', 'tools.php', 'options-general.php' ), $slugs ) );
$ok( 'WooCommerce top-level menu removed', ! in_array( 'woocommerce', $slugs, true ) );
// 店主 2026-11-11：订单菜单顶层是“待处理订单”（Admin_Menus::PENDING）。
$ok( 'dashboard first, then orders (pending list) and customers', 'pc-overview' === array_values( array_filter( $slugs ) )[0] && in_array( \PeptideCore\Admin\Admin_Menus::PENDING, $slugs, true ) && ! in_array( \PeptideCore\Admin\Admin_Menus::ORDERS, $slugs, true ) && in_array( 'admin.php?page=wc-admin&path=/customers', $slugs, true ) );
$ok( 'WooCommerce marketing menu replaced by marketing campaigns: spend-and-save, coupon codes, shipping discounts', ! in_array( 'woocommerce-marketing', $slugs, true ) && in_array( 'pc-promotions', $slugs, true ) && array( 'pc-promotions', 'pc-coupons', 'pc-shipping-discounts' ) === array_column( $submenu['pc-promotions'] ?? array(), 2 ) );
$ok( 'app center menu', in_array( 'pc-apps', $slugs, true ) );
// 2026-11-04：WooCommerce 分析的顶层菜单由“数据”（Data_Pages）顶替，这里不再有分析菜单；它的子菜单一直不显示。
$ok( 'WooCommerce analytics menu is gone (Data replaces it), no analytics submenus', ! in_array( \PeptideCore\Admin\Admin_Menus::ANALYTICS, $slugs, true ) && empty( $submenu[ \PeptideCore\Admin\Admin_Menus::ANALYTICS ] ) );
$_GET['page'] = 'wc-settings';
$wc_tabs      = \PeptideCore\Admin\Admin_Menus::settings_tabs( array( 'general' => 'General', 'products' => 'Products', 'shipping' => 'Shipping', 'checkout' => 'Payments', 'account' => 'Accounts', 'email' => 'Emails', 'integration' => 'Integration', 'site-visibility' => 'Visibility', 'point-of-sale' => 'POS', 'wcpay_multi_currency' => 'Multi-currency', 'advanced' => 'Advanced' ) );
$ok( 'WooCommerce settings tab bar keeps general, products, accounts and advanced', array( 'general', 'products', 'account', 'advanced' ) === array_keys( $wc_tabs ) );
// WooCommerce 打开设置页时，只要这个标签的设置动作还在就照常显示（html-admin-settings.php 的 $tab_exists），所以隐藏的标签仍能直接打开。
include_once WC()->plugin_path() . '/includes/admin/class-wc-admin-settings.php';
WC_Admin_Settings::get_settings_pages();
$ok( 'hidden tabs still open from direct links (their settings actions remain)', has_action( 'woocommerce_settings_email' ) && has_action( 'woocommerce_settings_shipping' ) && has_action( 'woocommerce_settings_checkout' ) );
$_GET['page'] = 'wc-orders';
$ok( 'store settings menu includes shipping, tax, system and administrator site tools', array( 'pc-shipping', 'pc-tax', 'pc-system', 'pc-site-tools' ) === array_column( $submenu['admin.php?page=wc-settings'] ?? array(), 2 ) );
$ok( 'no Users menu on the store (accounts are managed in the master admin)', ! in_array( 'users.php', $slugs, true ) );
$ok( 'no Plugins menu on the store (platform tools open from the master admin)', ! in_array( 'plugins.php', $slugs, true ) );
foreach ( array( 'wc-orders' => 'admin.php?page=wc-orders', 'wc-settings' => 'admin.php?page=wc-settings', 'pc-settings' => 'admin.php?page=wc-settings', 'wc-status' => 'admin.php?page=wc-settings', 'pc-system' => 'admin.php?page=wc-settings', 'pc-tax' => 'admin.php?page=wc-settings' ) as $page => $parent ) {
	$_GET['page'] = $page;
	$plugin_page  = $page;
	// 和 menu-header.php 一样：先过滤，再调用一次 get_admin_page_parent()（它会改写 $parent_file），然后输出菜单。
	$GLOBALS['parent_file'] = apply_filters( 'parent_file', get_admin_page_parent() );
	get_admin_page_parent();
	$after = $GLOBALS['parent_file'];
	do_action( 'adminmenu' );
	$ok( "$page still opens and keeps the new menu open after WordPress re-checks the parent", user_can_access_admin_page() && $parent === $after );
}
$pm = $submenu['edit.php?post_type=product'] ?? array();
$ps = array_column( $pm, 2 );
$pos = static function ( string $needle ) use ( $ps ): int {
	foreach ( $ps as $i => $slug ) {
		if ( str_contains( $slug, $needle ) ) {
			return $i;
		}
	}
	return -1;
};
// 现在的顺序：商品管理、专辑、商品评价、商品标签；检测报告从“报告库”页面进（Report_Library_Page，这里没有登记），原来的报告列表不显示。
$ok( 'products menu: product management first, then collections, reviews and tags; raw report list hidden', 'edit.php?post_type=product' === ( $ps[0] ?? '' ) && __( 'Product management', 'peptide-core' ) === ( $pm[0][0] ?? '' ) && $pos( 'taxonomy=product_cat' ) > 0 && $pos( 'product-reviews' ) > $pos( 'taxonomy=product_cat' ) && $pos( 'taxonomy=product_tag' ) > $pos( 'product-reviews' ) && -1 === $pos( 'post_type=pc_report' ) );
$ok( 'products menu labels: Collections, Product tags', __( 'Collections', 'peptide-core' ) === $pm[ $pos( 'taxonomy=product_cat' ) ][0] && __( 'Product tags', 'peptide-core' ) === $pm[ $pos( 'taxonomy=product_tag' ) ][0] );
$ok( 'add new, brands, attributes and batches are not in the products menu', -1 === $pos( 'post-new.php' ) && -1 === $pos( 'product_brand' ) && -1 === $pos( 'product_attributes' ) && -1 === $pos( 'post_type=pc_batch' ) );
$ok( 'top-level menu is called Products', in_array( __( 'Products', 'peptide-core' ), array_column( array_filter( $menu, static fn( $m ) => 'edit.php?post_type=product' === ( $m[2] ?? '' ) ), 0 ), true ) );
$ok( 'the temporary WooCommerce parent mapping is removed after the menu is printed', ! isset( $GLOBALS['_wp_real_parent_file']['woocommerce'] ) );
// 外观、工具、设置下面的页面：停在“店铺设置 → 系统设置”。
$saved_page = $pagenow;
unset( $_GET['page'] );
$plugin_page = null;
foreach ( array( 'options-reading.php', 'options-permalink.php', 'erase-personal-data.php', 'site-health.php', 'themes.php' ) as $wp_page ) {
	$pagenow = $wp_page;
	$GLOBALS['pagenow'] = $wp_page;
	$GLOBALS['parent_file'] = apply_filters( 'parent_file', get_admin_page_parent() );
	get_admin_page_parent();
	$after = $GLOBALS['parent_file'];
	$sub   = apply_filters( 'submenu_file', null );
	do_action( 'adminmenu' );
	$ok( "$wp_page opens under store settings, system settings", 'admin.php?page=wc-settings' === $after && 'pc-system' === $sub );
}
foreach ( array( 'options-writing.php', 'options-media.php', 'nav-menus.php', 'import.php', 'site-editor.php' ) as $gone ) {
	$GLOBALS['pagenow'] = $gone;
	$ok( "$gone is removed and goes to system settings", str_contains( \PeptideCore\Admin\Admin_Menus::removed_redirect(), 'page=pc-system&pc_removed=1' ) );
}
foreach ( array( 'options-reading.php', 'options-permalink.php', 'erase-personal-data.php', 'site-health.php', 'themes.php' ) as $kept ) {
	$GLOBALS['pagenow'] = $kept;
	$ok( "$kept still opens", '' === \PeptideCore\Admin\Admin_Menus::removed_redirect() );
}
$ok( 'theme and plugin file editors are off for everyone', ! current_user_can( 'edit_themes' ) && ! current_user_can( 'edit_plugins' ) && ! current_user_can( 'edit_files' ) && current_user_can( 'switch_themes' ) && current_user_can( 'activate_plugins' ) );
$ok( 'and the temporary mappings are removed again', ! array_intersect_key( (array) $GLOBALS['_wp_real_parent_file'], array_flip( array( 'woocommerce', 'options-general.php', 'tools.php', 'themes.php' ) ) ) );
$pagenow = $saved_page;
$GLOBALS['pagenow'] = $saved_page;
$plugin_page = 'wc-orders';
// 店主 2026-11-11：订单菜单只剩待处理订单和弃单（手动订单由 Manual_Orders 另外登记，这里没有载入）。
$ok( 'orders menu: pending orders, abandoned checkouts', array( \PeptideCore\Admin\Admin_Menus::PENDING, 'pc-recovery' ) === array_column( $submenu[ \PeptideCore\Admin\Admin_Menus::PENDING ] ?? array(), 2 ) && empty( $submenu[ \PeptideCore\Admin\Admin_Menus::ORDERS ] ) );
$_GET['page']   = 'wc-orders';
$_GET['status'] = 'wc-processing';
$ok( 'the processing order list highlights pending orders', \PeptideCore\Admin\Admin_Menus::PENDING === apply_filters( 'submenu_file', null ) );
unset( $_GET['status'] );
$ok( 'other order lists also sit under pending orders (the only orders entry)', \PeptideCore\Admin\Admin_Menus::PENDING === apply_filters( 'submenu_file', null ) );

// 店铺设置子菜单高亮（STORE-SETTINGS-01）。
$_GET['page'] = 'wc-settings';
$_GET['tab'] = 'checkout';
// 支付设置归总后台管的店（peptide-panapay 的 Bridge::enabled()，接了总后台的店是这样，沙盒就是）：Store_Admin::hide_menus() 会把
// WooCommerce 的“支付”菜单去掉，支付设置页也打不开（guard_settings() 转回概览）。这时没有“支付”菜单可展开，
// 这个标签页和其他标签页一样算“店铺设置 → 系统设置”。两种店各有一组预期，不是哪种结果都算过。
$pay_slug  = 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM';
$pay_moved = class_exists( '\VialCove\Panapay\Bridge' ) && \VialCove\Panapay\Bridge::enabled();
$ok( $pay_moved ? 'payment settings moved to HQ: the WooCommerce payments menu is gone' : 'the WooCommerce payments menu is kept while payment settings stay on the store', ( $pay_moved ? '' : $pay_slug ) === \PeptideCore\Admin\Admin_Menus::payments_menu() && $pay_moved !== in_array( $pay_slug, array_column( $menu, 2 ), true ) );
$ok( $pay_moved ? 'payment settings moved to HQ: the payments tab falls back to store settings' : 'payment settings open under the WooCommerce payments menu', ( $pay_moved ? 'admin.php?page=wc-settings' : $pay_slug ) === apply_filters( 'parent_file', 'x' ) );
foreach ( array( 'shipping' => 'pc-shipping', 'tax' => 'pc-tax', 'checkout' => $pay_moved ? 'pc-system' : $pay_slug, 'general' => 'pc-system', 'email' => 'pc-system' ) as $tab => $sub ) {
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
// 卡片后来加到 8 张（基本信息、联系方式、购物、视频、语言、广告像素、邮件、条款）。别的页面会直接链过来的几张必须在；数量不写死，免得再加一张就要改这里。
preg_match_all( '/page=pc-system&(?:#038;)?section=([a-z_-]+)/', $html, $section_links );
$sections = array_values( array_unique( $section_links[1] ) );
$ok( 'system settings shows its section cards (basic, ad pixels, email, terms among them)', count( $sections ) >= 4 && count( $sections ) === count( $section_links[1] ) && ! array_diff( array( 'basic', 'pixels', 'email', 'terms' ), $sections ) );
// 2026-10-27 前后台隔开：WordPress 设置和工具、WooCommerce 设置、VialCove 设置、系统状态从总后台的“平台工具”进入，店铺后台不显示。
$ok( 'system settings no longer lists platform tools (they open from the master admin)', ! str_contains( $html, 'options-reading.php' ) && ! str_contains( $html, 'options-permalink.php' ) && ! str_contains( $html, 'page=wc-status' ) && ! str_contains( $html, 'page=pc-settings' ) );
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
