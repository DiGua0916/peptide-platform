<?php
/**
 * 后台左侧菜单整理（ADMIN-MENU-01）。用户 2026-10-22 要求删掉“文章、媒体、评论、WooCommerce”四个菜单。
 *
 * - 文章、媒体、评论：直接去掉菜单。页面本身还在：商品图片等仍然用媒体库选图，地址仍可以直接打开。
 *   2026-10-27 起文章改成“博客”菜单（Blog_Admin），媒体由“后台设置 → 素材管理”管理（Media_Page）。
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
 * - 商品（用户 2026-10-27 要求和 Shopname 一样）：商品管理、商品专辑（WooCommerce 分类）、商品评论、商品标签，加上检测报告。
 *   添加新产品（列表页有“添加”按钮）、品牌、属性、批次不在菜单里显示，页面仍能直接打开；批次在检测报告里选或新建。
 * - 外观、工具、设置（用户 2026-10-27 要求去掉）：常规里的店铺名、管理员邮箱、图标、时区、语言、注册已在“基础配置”；
 *   仍然要用的页面（阅读 / 搜索引擎、固定链接、隐私、讨论、个人数据导出和抹除、站点健康、计划操作、主题）
 *   放在“系统设置”底部的“WordPress 设置和工具”，打开时左侧菜单停在“店铺设置 → 系统设置”。
 *   用不上的页面（撰写、媒体、连接、字体、样板、导入、外观 → 菜单、小工具）直接关掉，打开时转到“系统设置”；
 *   主题和插件的文件编辑器对所有人关闭（edit_themes / edit_plugins / edit_files 不再允许）。
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

	/** 去掉了顶层菜单、但页面还在用的父菜单：它们的页面打开时停在新的位置（见 parent_file()）。 */
	private const HIDDEN_PARENTS = array( 'woocommerce', 'options-general.php', 'tools.php', 'themes.php' );

	/** 外观、工具、设置下面的 WordPress 页面（$pagenow），打开时停在“店铺设置 → 系统设置”。 */
	private const WP_PAGES = array( 'options-general.php', 'options-writing.php', 'options-reading.php', 'options-discussion.php', 'options-media.php', 'options-permalink.php', 'options-privacy.php', 'options-connectors.php', 'options.php', 'tools.php', 'import.php', 'export.php', 'site-health.php', 'export-personal-data.php', 'erase-personal-data.php', 'themes.php', 'theme-install.php', 'theme-editor.php', 'nav-menus.php', 'widgets.php' );

	/** 已经删掉的 WordPress 页面（$pagenow）：用不上，打开时转到“系统设置”。 */
	private const REMOVED_PAGES = array( 'options-writing.php', 'options-media.php', 'options-connectors.php', 'font-library.php', 'site-editor.php', 'import.php', 'nav-menus.php', 'widgets.php' );

	/** parent_file() 临时改动前 $_wp_real_parent_file 里这些父菜单的值（null = 原来没有）。 */
	private static $real_parent = null;

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menus' ), 60 );
		add_action( 'admin_menu', array( self::class, 'remove_menus' ), 1000 );
		add_action( 'admin_menu', array( self::class, 'order_menu' ), 10000 );
		add_filter( 'parent_file', array( self::class, 'parent_file' ), 20 );
		add_filter( 'submenu_file', array( self::class, 'submenu_file' ), 20 );
		add_filter( 'woocommerce_settings_tabs_array', array( self::class, 'settings_tabs' ), 999 );
		add_action( 'admin_head', array( self::class, 'no_flyout' ) );
		add_action( 'adminmenu', array( self::class, 'restore_parent' ) );
		add_action( 'admin_init', array( self::class, 'block_removed_pages' ) );
		add_filter( 'map_meta_cap', array( self::class, 'no_file_editors' ), 10, 2 );
		add_action( 'admin_bar_menu', array( self::class, 'clean_admin_bar' ), 999 );
		add_action( 'admin_init', array( self::class, 'no_update_nags' ) );
	}

	/**
	 * 店铺后台和总后台彻底隔开（用户 2026-10-27）：顶部工具条去掉 WordPress 标志菜单、更新提示、评论和“新建用户”。
	 * 插件、主题、更新这些平台操作从总后台进入。
	 */
	public static function clean_admin_bar( $bar ): void {
		if ( ! $bar instanceof \WP_Admin_Bar ) {
			return;
		}
		foreach ( array( 'wp-logo', 'updates', 'comments', 'new-user' ) as $node ) {
			$bar->remove_node( $node );
		}
	}

	/** 店铺后台不显示 WordPress 和插件的更新提示（更新由总后台统一处理）。 */
	public static function no_update_nags(): void {
		remove_action( 'admin_notices', 'update_nag', 3 );
		remove_action( 'network_admin_notices', 'update_nag', 3 );
		remove_action( 'admin_notices', 'maintenance_nag', 10 );
	}

	/** 打开已经删掉的页面时转到“系统设置”（带提示）。 */
	public static function block_removed_pages(): void {
		$to = self::removed_redirect();
		if ( '' !== $to && wp_safe_redirect( $to ) ) {
			exit;
		}
	}

	/** 当前页面是已经删掉的页面时，返回要转去的地址；否则为空。导入工具（admin.php?import=）也算。 */
	public static function removed_redirect(): string {
		global $pagenow;
		if ( ! self::applies() || wp_doing_ajax() ) {
			return '';
		}
		$importer = 'admin.php' === $pagenow && isset( $_GET['import'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $importer && ! in_array( (string) $pagenow, self::REMOVED_PAGES, true ) ) {
			return '';
		}
		return admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG . '&pc_removed=1' );
	}

	/** 主题和插件的文件编辑器：在后台直接改代码容易把网站改坏，也是常见的入侵途径，对所有人关闭。 */
	public static function no_file_editors( $caps, $cap ) {
		return in_array( $cap, array( 'edit_themes', 'edit_plugins', 'edit_files' ), true ) ? array( 'do_not_allow' ) : $caps;
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
		// 媒体、评论、WooCommerce（订单、顾客、设置已移到上面的新菜单）、WooCommerce 营销（换成营销活动）。
		// 文章改成“博客”（Blog_Admin，2026-10-27），媒体在“后台设置 → 素材管理”。
		// remove_menu_page() 每次只去掉第一个匹配项，所以重复执行到没有为止。
		// 插件（2026-10-27 前后台隔开）：插件、主题、更新是平台操作，从总后台的“平台工具”进入。
		// 用户（2026-10-27 用户要求）：一个销售一个站点，账号在总后台管理，店铺后台不需要“用户”菜单；
		// 员工管理也移到总后台（销售账号）。两个页面的地址还在，管理员直接打开地址仍能用。
		foreach ( array( 'upload.php', 'edit-comments.php', 'woocommerce', 'woocommerce-marketing', 'themes.php', 'tools.php', 'options-general.php', 'users.php', 'plugins.php' ) as $slug ) {
			while ( false !== remove_menu_page( $slug ) ) {
				continue;
			}
		}
		remove_submenu_page( self::SETTINGS, Staff_Page::SLUG );
		self::product_menu();
	}

	/**
	 * 顶层菜单按 YY 的顺序：……访客数据、博客、……店铺设置、后台设置、应用中心。
	 * WordPress 按位置的文字排序（58.12345 排在 58.5 后面），小数位置靠不住，所以排好后重新编号。
	 */
	public static function order_menu(): void {
		global $menu;
		if ( empty( $menu ) || ! is_array( $menu ) ) {
			return;
		}
		uksort( $menu, 'strnatcasecmp' );
		$items = array_values( $menu );
		$move  = static function ( string $slug, string $after ) use ( &$items ): void {
			$from = array_search( $slug, array_column( $items, 2 ), true );
			if ( false === $from ) {
				return;
			}
			$item = $items[ $from ];
			array_splice( $items, $from, 1 );
			$to = array_search( $after, array_column( $items, 2 ), true );
			array_splice( $items, false === $to ? count( $items ) : $to + 1, 0, array( $item ) );
		};
		$move( 'edit.php', 'pc-visitors' );
		$move( Media_Page::SLUG, self::SETTINGS );
		$menu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		foreach ( $items as $i => $item ) {
			$menu[ ( $i + 1 ) * 5 ] = $item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/** 商品菜单：改名、排序，去掉用不上的子菜单（其他插件加的子菜单放在最后，不去掉）。 */
	private static function product_menu(): void {
		global $menu, $submenu;
		$parent = 'edit.php?post_type=product';
		foreach ( (array) $menu as $i => $item ) {
			if ( $parent === ( $item[2] ?? '' ) ) {
				$menu[ $i ][0] = __( 'Products', 'peptide-core' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}
		$labels = array(
			'all'     => __( 'Product management', 'peptide-core' ),
			'cat'     => __( 'Collections', 'peptide-core' ),
			'reviews' => __( 'Product reviews', 'peptide-core' ),
			'tag'     => __( 'Product tags', 'peptide-core' ),
			'reports' => null,
		);
		$found  = array_fill_keys( array_keys( $labels ), null );
		$others = array();
		foreach ( $submenu[ $parent ] as $item ) {
			$key = self::product_item( (string) ( $item[2] ?? '' ) );
			if ( 'hide' === $key ) {
				continue;
			}
			if ( '' === $key ) {
				$others[] = $item;
				continue;
			}
			if ( null !== $labels[ $key ] ) {
				$item[0] = $labels[ $key ];
			}
			$found[ $key ] = $item;
		}
		$submenu[ $parent ] = array_merge( array_values( array_filter( $found ) ), $others ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/** 商品子菜单属于哪一项：all / cat / reviews / tag / reports，hide = 不显示，空 = 其他插件的。 */
	private static function product_item( string $slug ): string {
		if ( 'edit.php?post_type=product' === $slug ) {
			return 'all';
		}
		if ( str_contains( $slug, 'taxonomy=product_cat' ) ) {
			return 'cat';
		}
		if ( 'product-reviews' === $slug || str_contains( $slug, 'page=product-reviews' ) ) {
			return 'reviews';
		}
		if ( str_contains( $slug, 'taxonomy=product_tag' ) ) {
			return 'tag';
		}
		if ( 'edit.php?post_type=' . \PeptideCore\Post_Types::REPORT === $slug ) {
			return 'reports';
		}
		if ( 'post-new.php?post_type=product' === $slug || str_contains( $slug, 'taxonomy=product_brand' ) || 'product_attributes' === $slug || 'edit.php?post_type=' . \PeptideCore\Post_Types::BATCH === $slug ) {
			return 'hide';
		}
		return '';
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
		// 批次不在商品菜单里了，WordPress 找不到它的父菜单；仍然展开商品菜单（子菜单高亮“检测报告”）。
		global $typenow;
		if ( \PeptideCore\Post_Types::BATCH === $typenow ) {
			return 'edit.php?post_type=product';
		}
		$target = self::target_parent();
		if ( '' === $target ) {
			return $parent_file;
		}
		global $_wp_real_parent_file;
		if ( null === self::$real_parent ) {
			self::$real_parent = array();
			foreach ( self::HIDDEN_PARENTS as $key ) {
				self::$real_parent[ $key ] = $_wp_real_parent_file[ $key ] ?? null;
			}
		}
		foreach ( self::HIDDEN_PARENTS as $key ) {
			$_wp_real_parent_file[ $key ] = $target;
		}
		return $target;
	}

	/** 菜单输出完以后，还原 parent_file() 临时改的 $_wp_real_parent_file。 */
	public static function restore_parent(): void {
		if ( null === self::$real_parent ) {
			return;
		}
		global $_wp_real_parent_file;
		foreach ( self::$real_parent as $key => $value ) {
			if ( null === $value ) {
				unset( $_wp_real_parent_file[ $key ] );
			} else {
				$_wp_real_parent_file[ $key ] = $value;
			}
		}
		self::$real_parent = null;
	}

	/** 当前页面应该展开的顶层菜单；不是移走的页面时为空。 */
	private static function target_parent(): string {
		if ( self::on_wp_page() ) {
			return self::SETTINGS;
		}
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

	/** 是不是外观、工具、设置下面的页面（WordPress 自己的，或者插件挂在这些菜单下的）。 */
	private static function on_wp_page(): bool {
		global $pagenow, $plugin_page, $submenu;
		if ( ! empty( $plugin_page ) ) {
			foreach ( array( 'options-general.php', 'tools.php', 'themes.php' ) as $parent ) {
				if ( in_array( $plugin_page, array_column( (array) ( $submenu[ $parent ] ?? array() ), 2 ), true ) ) {
					return true;
				}
			}
			return false;
		}
		return in_array( (string) $pagenow, self::WP_PAGES, true );
	}

	public static function submenu_file( $submenu_file ) {
		if ( ! self::applies() ) {
			return $submenu_file;
		}
		if ( self::on_wp_page() ) {
			return Store_Settings::SYSTEM_SLUG;
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
		// 商品菜单里隐藏的页面：批次算“检测报告”，添加商品、品牌、属性算“商品管理”。
		global $typenow, $pagenow, $taxonomy;
		if ( \PeptideCore\Post_Types::BATCH === $typenow ) {
			return 'edit.php?post_type=' . \PeptideCore\Post_Types::REPORT;
		}
		if ( 'product' === $typenow && ( 'post-new.php' === $pagenow || 'product_attributes' === $page || 'product_brand' === $taxonomy || ( is_string( $taxonomy ) && '' !== $taxonomy && taxonomy_is_product_attribute( $taxonomy ) ) ) ) {
			return 'edit.php?post_type=product';
		}
		// 插件自己放在这些菜单下的页面：WordPress 只在 $submenu_file 为空时才按 page 参数高亮，这里直接指定。
		if ( in_array( $page, array( Recovery_Page::SLUG, Shipping_Page::SLUG, Tax_Page::SLUG, Store_Settings::SYSTEM_SLUG, Staff_Page::SLUG ), true ) ) {
			return $page;
		}
		return $submenu_file;
	}
}
