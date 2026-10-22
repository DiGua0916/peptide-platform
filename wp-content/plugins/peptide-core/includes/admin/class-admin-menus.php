<?php
/**
 * 后台左侧菜单整理（ADMIN-MENU-01）。用户 2026-10-22 要求删掉“文章、媒体、评论、WooCommerce”四个菜单。
 *
 * - 文章、媒体、评论：直接去掉菜单。页面本身还在：商品图片等仍然用媒体库选图，地址仍可以直接打开。
 * - WooCommerce：去掉顶层菜单，但订单、顾客、设置是开店必需的，按 Shopname 的方式移到新的顶层菜单：
 *   订单（WooCommerce 订单列表，带待处理数量）、顾客（WooCommerce 顾客）、
 *   店铺设置（STORE-SETTINGS-01 起按 Shopname 分为：物流设置、税费设置、支付设置、系统设置；
 *   原来的 WooCommerce 设置、VialCove 设置、系统状态放在“系统设置”页面底部）。
 *   这些页面仍然是 WooCommerce 自己的页面，打开时左侧菜单停在新的位置。
 *
 * 只影响能管理商店的人（manage_woocommerce）；其他角色的菜单不变。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

defined( 'ABSPATH' ) || exit;

final class Admin_Menus {

	public const CAP = 'manage_woocommerce';

	/** 移走的 WooCommerce 页面（page 参数）=> 新的顶层菜单。 */
	private const MOVED = array(
		'wc-orders'   => 'admin.php?page=wc-orders',
		'wc-settings' => self::SETTINGS,
		'wc-status'   => self::SETTINGS,
		'pc-settings' => self::SETTINGS,
	);

	public const ORDERS    = 'admin.php?page=wc-orders';
	public const CUSTOMERS = 'admin.php?page=wc-admin&path=/customers';
	public const SETTINGS  = 'admin.php?page=wc-settings';
	public const SHIPPING  = 'admin.php?page=wc-settings&tab=shipping';
	public const PAYMENTS  = 'admin.php?page=wc-settings&tab=checkout';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menus' ), 60 );
		add_action( 'admin_menu', array( self::class, 'remove_menus' ), 1000 );
		add_filter( 'parent_file', array( self::class, 'parent_file' ), 20 );
		add_filter( 'submenu_file', array( self::class, 'submenu_file' ), 20 );
	}

	private static function applies(): bool {
		return current_user_can( self::CAP );
	}

	public static function add_menus(): void {
		if ( ! self::applies() ) {
			return;
		}
		$count = function_exists( 'wc_processing_order_count' ) ? (int) wc_processing_order_count() : 0;
		$badge = $count ? ' <span class="awaiting-mod update-plugins count-' . $count . '"><span class="processing-count">' . number_format_i18n( $count ) . '</span></span>' : '';
		add_menu_page( __( 'Orders', 'peptide-core' ), __( 'Orders', 'peptide-core' ) . $badge, 'edit_shop_orders', self::ORDERS, '', 'dashicons-cart', 3 );
		add_menu_page( __( 'Customers', 'peptide-core' ), __( 'Customers', 'peptide-core' ), self::CAP, self::CUSTOMERS, '', 'dashicons-groups', 4 );
		add_menu_page( __( 'Store settings', 'peptide-core' ), __( 'Store settings', 'peptide-core' ), self::CAP, self::SETTINGS, '', 'dashicons-admin-settings', 58 );
		// 顶层菜单链接到第一个子菜单（物流设置）。
		add_submenu_page( self::SETTINGS, __( 'Shipping settings', 'peptide-core' ), __( 'Shipping settings', 'peptide-core' ), self::CAP, self::SHIPPING );
		$tax = add_submenu_page( self::SETTINGS, __( 'Tax settings', 'peptide-core' ), __( 'Tax settings', 'peptide-core' ), self::CAP, Store_Settings::TAX_SLUG, array( Store_Settings::class, 'render_tax' ) );
		if ( $tax ) {
			add_action( 'load-' . $tax, array( Store_Settings::class, 'maybe_redirect_tax' ) );
		}
		add_submenu_page( self::SETTINGS, __( 'Payment settings', 'peptide-core' ), __( 'Payment settings', 'peptide-core' ), self::CAP, self::PAYMENTS );
		add_submenu_page( self::SETTINGS, __( 'System settings', 'peptide-core' ), __( 'System settings', 'peptide-core' ), self::CAP, Store_Settings::SYSTEM_SLUG, array( Store_Settings::class, 'render_system' ) );
		// WordPress 会自动把顶层菜单本身加成第一个子菜单，去掉它。
		remove_submenu_page( self::SETTINGS, self::SETTINGS );
	}

	public static function remove_menus(): void {
		if ( ! self::applies() ) {
			return;
		}
		// 文章、媒体、评论、WooCommerce（订单、顾客、设置已移到上面的新菜单）。
		// remove_menu_page() 每次只去掉第一个匹配项，所以重复执行到没有为止。
		foreach ( array( 'edit.php', 'upload.php', 'edit-comments.php', 'woocommerce' ) as $slug ) {
			while ( false !== remove_menu_page( $slug ) ) {
				continue;
			}
		}
	}

	private static function current_page(): string {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** 打开移走的页面时，左侧菜单展开新的位置。 */
	public static function parent_file( $parent_file ) {
		if ( ! self::applies() ) {
			return $parent_file;
		}
		$page = self::current_page();
		if ( isset( self::MOVED[ $page ] ) ) {
			return self::MOVED[ $page ];
		}
		if ( 'wc-admin' === $page && isset( $_GET['path'] ) && '/customers' === wp_unslash( $_GET['path'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return self::CUSTOMERS;
		}
		return $parent_file;
	}

	public static function submenu_file( $submenu_file ) {
		if ( ! self::applies() ) {
			return $submenu_file;
		}
		$page = self::current_page();
		// WooCommerce 设置按标签页对应到店铺设置的子菜单，其余（常规、商品、邮件等）和 VialCove 设置、系统状态都算“系统设置”。
		if ( 'wc-settings' === $page ) {
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$map = array(
				'shipping' => self::SHIPPING,
				'tax'      => Store_Settings::TAX_SLUG,
				'checkout' => self::PAYMENTS,
			);
			return $map[ $tab ] ?? Store_Settings::SYSTEM_SLUG;
		}
		if ( in_array( $page, array( 'pc-settings', 'wc-status' ), true ) ) {
			return Store_Settings::SYSTEM_SLUG;
		}
		if ( 'wc-orders' === $page ) {
			return self::ORDERS;
		}
		return $submenu_file;
	}
}
