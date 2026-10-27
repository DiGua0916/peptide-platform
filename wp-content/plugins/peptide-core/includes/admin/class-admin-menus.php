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
 * - 营销（MARKETING-01，用户 2026-10-23 要求）：去掉 WooCommerce 的营销菜单（概览、优惠券和原来的满减活动），
 *   换成 Shopname 的“营销活动”：满减活动、优惠码、运费折扣（见 Marketing）。
 * - 分析（用户 2026-10-23 要求）：WooCommerce 分析只保留顶层菜单，点开是分析概览；
 *   下面的概述、产品、收入、订单、变量、分类、优惠券、税费、下载、库存、设置子菜单都去掉。
 * - 支付（用户 2026-10-23 要求）：店铺设置里的“支付设置”去掉，用 WooCommerce 自己的顶层“支付”菜单；
 *   打开支付设置时左侧菜单停在“支付”。
 * - WooCommerce 设置的标签栏（用户 2026-10-23 要求去掉用不上或已经有了的）：
 *   配送（= 物流设置）、付款（= 支付菜单）、电子邮件（= 邮件通知）、站点可见性（= 基础配置的店铺经营状态）、
 *   集成、销售点、Multi-currency（本站不用）从标签栏去掉。只是不显示标签，设置值不变，
 *   插件页面里指向这些设置的链接（例如邮件通知的“设置”、物流设置里的“运费区域”）仍然能打开。
 *   常规（可销售国家等）、产品（单位、库存、评价）、帐户和隐私（游客结账、账户创建）、高级（购物车 / 结账页面、接口）保留。
 * - 订单（用户 2026-10-27 要求和 Shopname 一样）：全部订单、待处理订单（WooCommerce 的“处理中”，已付款待发货）、弃单。
 * - 悬停预览（用户 2026-10-27 要求去掉）：鼠标移到没展开的菜单上时，旁边不再弹出子菜单，点菜单进去后照常展开。
 *   只在展开的宽菜单、用鼠标时去掉；收起成图标的菜单和触屏设备没有别的办法打开子菜单，保留。
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
	public const PENDING   = 'admin.php?page=wc-orders&status=wc-processing';
	public const CUSTOMERS = 'admin.php?page=wc-admin&path=/customers';
	public const SETTINGS  = 'admin.php?page=wc-settings';
	public const ANALYTICS = 'wc-admin&path=/analytics/overview';

	/** 从 WooCommerce 设置标签栏去掉的标签。 */
	public const HIDDEN_TABS = array( 'shipping', 'checkout', 'email', 'site-visibility', 'integration', 'point-of-sale', 'wcpay_multi_currency' );

	/** parent_file() 临时改动前 $_wp_real_parent_file['woocommerce'] 的值（放在数组里，以区分“原来没有”）。 */
	private static $real_parent = null;

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menus' ), 60 );
		add_action( 'admin_menu', array( self::class, 'remove_menus' ), 1000 );
		add_filter( 'parent_file', array( self::class, 'parent_file' ), 20 );
		add_filter( 'submenu_file', array( self::class, 'submenu_file' ), 20 );
		add_filter( 'woocommerce_settings_tabs_array', array( self::class, 'settings_tabs' ), 999 );
		add_action( 'admin_head', array( self::class, 'no_flyout' ) );
		add_action( 'adminmenu', array( self::class, 'restore_parent' ) );
	}

	/** 去掉左侧菜单的悬停预览（没展开的菜单旁边弹出的子菜单和小箭头）。 */
	public static function no_flyout(): void {
		if ( ! self::applies() ) {
			return;
		}
		echo '<style id="pc-no-menu-flyout">@media screen and (min-width:961px) and (hover:hover) and (pointer:fine){'
			. 'body:not(.folded) #adminmenu li.wp-not-current-submenu>.wp-submenu{display:none}'
			. 'body:not(.folded) #adminmenu li.wp-has-submenu.wp-not-current-submenu:hover:after,'
			. 'body:not(.folded) #adminmenu li.wp-has-submenu.wp-not-current-submenu:focus-within:after{display:none}'
			. '}</style>' . "\n";
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
		add_submenu_page( self::SETTINGS, __( 'Shipping settings', 'peptide-core' ), __( 'Shipping settings', 'peptide-core' ), self::CAP, Shipping_Page::SLUG, array( Shipping_Page::class, 'render' ) );
		add_submenu_page( self::SETTINGS, __( 'Tax settings', 'peptide-core' ), __( 'Tax settings', 'peptide-core' ), self::CAP, Tax_Page::SLUG, array( Tax_Page::class, 'render' ) );
		add_submenu_page( self::SETTINGS, __( 'System settings', 'peptide-core' ), __( 'System settings', 'peptide-core' ), self::CAP, Store_Settings::SYSTEM_SLUG, array( Store_Settings::class, 'render_system' ) );
		// WordPress 会自动把顶层菜单本身加成第一个子菜单，去掉它。
		remove_submenu_page( self::SETTINGS, self::SETTINGS );

		// 营销活动：顶层菜单就是第一个子菜单（满减活动）。
		add_menu_page( __( 'Marketing campaigns', 'peptide-core' ), __( 'Marketing campaigns', 'peptide-core' ), Marketing::CAP, Promotions_Page::SLUG, array( Promotions_Page::class, 'render' ), 'dashicons-megaphone', 56 );
		add_submenu_page( Promotions_Page::SLUG, __( 'Spend-and-save promotions', 'peptide-core' ), __( 'Spend-and-save', 'peptide-core' ), Marketing::CAP, Promotions_Page::SLUG, array( Promotions_Page::class, 'render' ) );
		add_submenu_page( Promotions_Page::SLUG, __( 'Coupon codes', 'peptide-core' ), __( 'Coupon codes', 'peptide-core' ), Marketing::CAP, Coupons_Page::SLUG, array( Coupons_Page::class, 'render' ) );
		add_submenu_page( Promotions_Page::SLUG, __( 'Shipping discounts', 'peptide-core' ), __( 'Shipping discounts', 'peptide-core' ), Marketing::CAP, Shipping_Discounts_Page::SLUG, array( Shipping_Discounts_Page::class, 'render' ) );

		// 应用中心（APP-CENTER-01）：在店铺设置下面。
		add_menu_page( __( 'App center', 'peptide-core' ), __( 'App center', 'peptide-core' ), Apps_Page::CAP, Apps_Page::SLUG, array( Apps_Page::class, 'render' ), 'dashicons-screenoptions', 59 );
	}

	public static function remove_menus(): void {
		global $submenu;
		if ( ! self::applies() ) {
			return;
		}
		// 分析：只留顶层菜单。没有子菜单时 WordPress 直接链接到顶层菜单自己的页面（分析概览）。
		unset( $submenu[ self::ANALYTICS ] );
		// 文章、媒体、评论、WooCommerce（订单、顾客、设置已移到上面的新菜单）、WooCommerce 营销（换成营销活动）。
		// remove_menu_page() 每次只去掉第一个匹配项，所以重复执行到没有为止。
		foreach ( array( 'edit.php', 'upload.php', 'edit-comments.php', 'woocommerce', 'woocommerce-marketing' ) as $slug ) {
			while ( false !== remove_menu_page( $slug ) ) {
				continue;
			}
		}
	}

	/** WooCommerce 设置页的标签栏只留常规、产品、帐户和隐私、高级（和其他插件新加的标签）。 */
	public static function settings_tabs( $tabs ) {
		if ( ! is_array( $tabs ) || 'wc-settings' !== self::current_page() ) {
			return $tabs;
		}
		return array_diff_key( $tabs, array_flip( self::HIDDEN_TABS ) );
	}

	private static function current_page(): string {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	private static function current_tab(): string {
		return isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** WooCommerce 自己的顶层“支付”菜单（没有 WooPayments 时链接到支付设置；接入 WooPayments 后由它提供）。 */
	public static function payments_menu(): string {
		global $menu;
		foreach ( (array) $menu as $item ) {
			$slug = (string) ( $item[2] ?? '' );
			if ( str_starts_with( $slug, 'admin.php?page=wc-settings&tab=checkout' ) || str_starts_with( $slug, 'wc-admin&path=/payments' ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * 打开移走的页面时，左侧菜单展开新的位置。
	 *
	 * WordPress 在这个过滤器之后还会调用一次 get_admin_page_parent()（menu-header.php）。这些页面仍然登记在
	 * WooCommerce 已经去掉的顶层菜单下面，它会把 $parent_file 改回 woocommerce，结果没有菜单展开、子菜单只能靠悬停看到。
	 * 所以这一次请求里让 woocommerce 指向新的位置（$_wp_real_parent_file），菜单输出完（adminmenu）再还原。
	 * 页面钩子在这之前已经算好，WooCommerce 页面照常显示。
	 */
	public static function parent_file( $parent_file ) {
		if ( ! self::applies() ) {
			return $parent_file;
		}
		$target = self::target_parent();
		if ( '' === $target ) {
			return $parent_file;
		}
		global $_wp_real_parent_file;
		if ( null === self::$real_parent ) {
			self::$real_parent = array( $_wp_real_parent_file['woocommerce'] ?? null );
		}
		$_wp_real_parent_file['woocommerce'] = $target;
		return $target;
	}

	/** 菜单输出完以后，还原 parent_file() 临时改的 $_wp_real_parent_file。 */
	public static function restore_parent(): void {
		if ( null === self::$real_parent ) {
			return;
		}
		global $_wp_real_parent_file;
		if ( null === self::$real_parent[0] ) {
			unset( $_wp_real_parent_file['woocommerce'] );
		} else {
			$_wp_real_parent_file['woocommerce'] = self::$real_parent[0];
		}
		self::$real_parent = null;
	}

	/** 当前页面应该展开的顶层菜单；不是移走的页面时为空。 */
	private static function target_parent(): string {
		$page = self::current_page();
		if ( 'wc-settings' === $page && 'checkout' === self::current_tab() && '' !== self::payments_menu() ) {
			return self::payments_menu();
		}
		if ( isset( self::MOVED[ $page ] ) ) {
			return self::MOVED[ $page ];
		}
		if ( 'wc-admin' === $page && isset( $_GET['path'] ) && '/customers' === wp_unslash( $_GET['path'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return self::CUSTOMERS;
		}
		// 分析的各个报表（从概览里的链接打开时）也停在“分析”菜单。
		if ( 'wc-admin' === $page && isset( $_GET['path'] ) && str_starts_with( (string) wp_unslash( $_GET['path'] ), '/analytics' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return self::ANALYTICS;
		}
		return '';
	}

	public static function submenu_file( $submenu_file ) {
		if ( ! self::applies() ) {
			return $submenu_file;
		}
		$page = self::current_page();
		// WooCommerce 设置按标签页对应到店铺设置的子菜单，其余（常规、商品、邮件等）和 VialCove 设置、系统状态都算“系统设置”。
		if ( 'wc-settings' === $page ) {
			$tab = self::current_tab();
			if ( 'checkout' === $tab && '' !== self::payments_menu() ) {
				return self::payments_menu();
			}
			$map = array(
				'shipping' => Shipping_Page::SLUG,
				'tax'      => Tax_Page::SLUG,
			);
			return $map[ $tab ] ?? Store_Settings::SYSTEM_SLUG;
		}
		if ( in_array( $page, array( 'pc-settings', 'wc-status' ), true ) ) {
			return Store_Settings::SYSTEM_SLUG;
		}
		if ( 'wc-orders' === $page ) {
			// 待处理订单 = 处理中的订单列表；打开某一张订单时仍算“全部订单”。
			$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'wc-processing' === $status && ! isset( $_GET['action'] ) ? self::PENDING : self::ORDERS; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		// 插件自己放在这些菜单下的页面：WordPress 只在 $submenu_file 为空时才按 page 参数高亮，这里直接指定。
		if ( in_array( $page, array( Recovery_Page::SLUG, Shipping_Page::SLUG, Tax_Page::SLUG, Store_Settings::SYSTEM_SLUG ), true ) ) {
			return $page;
		}
		return $submenu_file;
	}
}
