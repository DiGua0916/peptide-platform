<?php
/**
 * 员工权限（照 YY 后台“店铺设置 → 员工管理”，用户 2026-10-27 选择“按菜单勾选权限”“管理员直接设置密码”）。
 * 后台页面见 Admin\Staff_Page。
 *
 * - 员工是 WordPress 用户，角色 pc_staff。每个员工单独勾选能用的菜单（模块），存在用户资料 pc_staff_modules 里。
 * - 勾了的模块：通过 user_has_cap 临时给这个员工对应的能力（WordPress 和 WooCommerce 的页面按能力判断）。
 * - 没勾的模块：左侧菜单去掉，打开对应页面时拒绝（不认识的后台页面也拒绝）。登录后和打开仪表盘时转到第一个能用的页面。
 *   因为很多店铺页面共用 manage_woocommerce，菜单和页面按模块控制；数据接口按能力控制。
 * - 停用的员工不能登录，已登录的会被退出。
 * - 管理员（administrator）不受影响，始终有全部权限。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Staff {

	public const ROLE          = 'pc_staff';
	public const META_MODULES  = 'pc_staff_modules';
	public const META_DISABLED = 'pc_staff_disabled';
	public const META_LOGIN    = 'pc_last_login';

	/** 员工资料页、上传、后台表单和接口这些不属于某个菜单的地址，所有员工都能用（各自再按能力检查）。 */
	private const ALWAYS = array( 'profile.php', 'admin-ajax.php', 'admin-post.php', 'async-upload.php', 'media-upload.php' );

	/** @var array<int,string[]> 这次请求里算好的员工模块。 */
	private static array $cache = array();

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'ensure_role' ), 5 );
		add_filter( 'user_has_cap', array( self::class, 'grant' ), 20, 4 );
		add_filter( 'map_meta_cap', array( self::class, 'media_caps' ), 10, 4 );
		add_filter( 'wp_authenticate_user', array( self::class, 'block_disabled' ), 30 );
		add_action( 'init', array( self::class, 'logout_disabled' ), 1 );
		add_action( 'wp_login', array( self::class, 'record_login' ), 10, 2 );
		add_filter( 'login_redirect', array( self::class, 'login_redirect' ), 20, 3 );
		add_action( 'admin_menu', array( self::class, 'filter_menu' ), 9999 );
		add_action( 'admin_init', array( self::class, 'redirect_dashboard' ), 1 );
		add_action( 'current_screen', array( self::class, 'gate' ) );
		add_filter( 'gettext_with_context', array( self::class, 'role_name' ), 10, 4 );
	}

	/** 角色名“Staff”是存在数据库里的英文，WordPress 的角色下拉框里翻译成“员工”。 */
	public static function role_name( $translation, $text, $context, $domain ) {
		return 'User role' === $context && 'Staff' === $text && 'default' === $domain ? __( 'Staff member', 'peptide-core' ) : $translation;
	}

	/** 员工角色本身只有“能登录后台”的能力，其余按勾选的模块给。 */
	public static function ensure_role(): void {
		if ( ! get_role( self::ROLE ) ) {
			add_role(
				self::ROLE,
				'Staff',
				array(
					'read'                 => true,
					'view_admin_dashboard' => true, // WooCommerce 只让有这个能力（或能编辑文章 / 管理商店）的人进后台。
				)
			);
		}
	}

	// ---------- 模块 ----------

	/**
	 * 可以勾选的模块（和 YY 的左侧菜单对应）：名称、给的能力、第一个页面。
	 *
	 * @return array<string,array{label:string,caps:string[],home:string}>
	 */
	public static function modules(): array {
		$type = static function ( string $singular, string $plural ): array {
			return array( "edit_{$plural}", "edit_others_{$plural}", "edit_published_{$plural}", "edit_private_{$plural}", "publish_{$plural}", "read_private_{$plural}", "delete_{$plural}", "delete_others_{$plural}", "delete_published_{$plural}", "delete_private_{$plural}", "read_{$singular}", "edit_{$singular}", "delete_{$singular}" );
		};
		return array(
			'orders'    => array(
				'label' => __( 'Orders', 'peptide-core' ),
				'caps'  => array_merge( $type( 'shop_order', 'shop_orders' ), array( 'pc_assign_batches', 'manage_woocommerce' ) ),
				'home'  => 'admin.php?page=wc-orders',
			),
			'products'  => array(
				'label' => __( 'Products', 'peptide-core' ),
				'caps'  => array_merge( $type( 'product', 'products' ), $type( 'pc_report', 'pc_reports' ), $type( 'pc_batch', 'pc_batches' ), array( 'manage_product_terms', 'edit_product_terms', 'delete_product_terms', 'assign_product_terms', 'moderate_comments', 'upload_files' ) ),
				'home'  => 'edit.php?post_type=product',
			),
			'customers' => array(
				'label' => __( 'Customers', 'peptide-core' ),
				'caps'  => array( 'manage_woocommerce', 'view_woocommerce_reports' ),
				'home'  => 'admin.php?page=wc-admin&path=/customers',
			),
			'marketing' => array(
				'label' => __( 'Marketing campaigns', 'peptide-core' ),
				'caps'  => $type( 'shop_coupon', 'shop_coupons' ),
				'home'  => 'admin.php?page=pc-promotions',
			),
			'data'      => array(
				'label' => __( 'Data', 'peptide-core' ),
				'caps'  => array( 'manage_woocommerce', 'view_woocommerce_reports' ),
				'home'  => 'admin.php?page=pc-overview',
			),
			'blog'      => array(
				'label' => __( 'Blog', 'peptide-core' ),
				'caps'  => array_merge( $type( 'post', 'posts' ), array( 'manage_categories', 'upload_files' ) ),
				'home'  => 'edit.php',
			),
			'design'    => array(
				'label' => __( 'Store design', 'peptide-core' ),
				'caps'  => array_merge( $type( 'pc_layout', 'pc_layouts' ), $type( 'page', 'pages' ), array( 'pc_publish_layouts', 'edit_theme_options', 'upload_files' ) ),
				'home'  => 'admin.php?page=pc-store-design',
			),
			'settings'  => array(
				'label' => __( 'Store settings', 'peptide-core' ),
				'caps'  => array( 'manage_woocommerce' ),
				'home'  => 'admin.php?page=pc-shipping',
			),
			'backend'   => array(
				'label' => __( 'Admin settings', 'peptide-core' ),
				'caps'  => array( 'upload_files', Activity_Log::CAP ),
				'home'  => 'admin.php?page=pc-media',
			),
			'apps'      => array(
				'label' => __( 'App center', 'peptide-core' ),
				'caps'  => array( 'manage_woocommerce' ),
				'home'  => 'admin.php?page=pc-apps',
			),
		);
	}

	public static function is_staff( $user ): bool {
		$user = $user instanceof \WP_User ? $user : get_userdata( (int) $user );
		return $user instanceof \WP_User && in_array( self::ROLE, (array) $user->roles, true ) && ! in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * 员工勾选的模块（停用的员工没有）。
	 *
	 * @return string[]
	 */
	public static function granted( int $user_id ): array {
		if ( isset( self::$cache[ $user_id ] ) ) {
			return self::$cache[ $user_id ];
		}
		$raw = get_user_meta( $user_id, self::META_MODULES, true );
		$out = self::disabled( $user_id ) ? array() : array_values( array_intersect( array_keys( self::modules() ), is_array( $raw ) ? $raw : array() ) );
		return self::$cache[ $user_id ] = $out;
	}

	/**
	 * @param string[] $modules
	 */
	public static function set_modules( int $user_id, array $modules ): array {
		$clean = array_values( array_intersect( array_keys( self::modules() ), array_map( 'strval', $modules ) ) );
		update_user_meta( $user_id, self::META_MODULES, $clean );
		unset( self::$cache[ $user_id ] );
		return $clean;
	}

	public static function disabled( int $user_id ): bool {
		return '1' === (string) get_user_meta( $user_id, self::META_DISABLED, true );
	}

	public static function set_disabled( int $user_id, bool $off ): void {
		if ( $off ) {
			update_user_meta( $user_id, self::META_DISABLED, '1' );
			if ( class_exists( '\WP_Session_Tokens' ) ) {
				\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
			}
		} else {
			delete_user_meta( $user_id, self::META_DISABLED );
		}
		unset( self::$cache[ $user_id ] );
	}

	public static function flush_cache(): void {
		self::$cache = array();
	}

	// ---------- 能力 ----------

	public static function grant( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof \WP_User || ! self::is_staff( $user ) ) {
			return $allcaps;
		}
		$modules = self::modules();
		foreach ( self::granted( $user->ID ) as $key ) {
			foreach ( $modules[ $key ]['caps'] as $cap ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}

	/** 有“后台设置”权限的员工可以编辑、删除所有素材（不用给编辑文章的能力）。 */
	public static function media_caps( $caps, $cap, $user_id, $args ) {
		if ( in_array( $cap, array( 'edit_post', 'delete_post' ), true ) && ! empty( $args[0] ) && 'attachment' === get_post_type( (int) $args[0] )
			&& self::is_staff( (int) $user_id ) && in_array( 'backend', self::granted( (int) $user_id ), true ) ) {
			return array( 'upload_files' );
		}
		return $caps;
	}

	// ---------- 登录 ----------

	public static function block_disabled( $user ) {
		if ( $user instanceof \WP_User && self::is_staff( $user ) && self::disabled( $user->ID ) ) {
			return new \WP_Error( 'pc_staff_disabled', __( 'This staff account is disabled. Contact the store owner.', 'peptide-core' ) );
		}
		return $user;
	}

	public static function logout_disabled(): void {
		$id = get_current_user_id();
		if ( $id && self::is_staff( $id ) && self::disabled( $id ) ) {
			wp_logout();
			wp_safe_redirect( wp_login_url() );
			exit;
		}
	}

	public static function record_login( $login, $user ): void {
		if ( $user instanceof \WP_User ) {
			update_user_meta( $user->ID, self::META_LOGIN, time() );
		}
	}

	/** 员工第一个能用的页面；什么都没勾时是个人资料。 */
	public static function home_url( int $user_id ): string {
		$modules = self::modules();
		foreach ( self::granted( $user_id ) as $key ) {
			return admin_url( $modules[ $key ]['home'] );
		}
		return admin_url( 'profile.php' );
	}

	public static function login_redirect( $redirect, $requested, $user ) {
		if ( $user instanceof \WP_User && self::is_staff( $user ) ) {
			// 要去的是能用的后台页面时照常去；否则去第一个能用的页面。
			$req = (string) $requested;
			if ( '' !== $req && str_starts_with( $req, admin_url() ) && self::allowed( $user->ID, self::module_for_url( $req ) ) ) {
				return $req;
			}
			return self::home_url( $user->ID );
		}
		return $redirect;
	}

	public static function redirect_dashboard(): void {
		global $pagenow;
		$id = get_current_user_id();
		if ( 'index.php' === $pagenow && $id && self::is_staff( $id ) && ! wp_doing_ajax() ) {
			wp_safe_redirect( self::home_url( $id ) );
			exit;
		}
	}

	// ---------- 页面和菜单 ----------

	private static function allowed( int $user_id, ?string $module ): bool {
		return 'always' === $module || ( null !== $module && in_array( $module, self::granted( $user_id ), true ) );
	}

	/** 店铺后台用到的 WooCommerce 设置标签（物流、税费、支付、邮件）；其余标签和系统状态是平台设置，只从总后台打开。 */
	public const STORE_TABS = array( 'shipping', 'tax', 'checkout', 'email' );

	/** 代表整个页面的菜单项 => 用到这个页面的菜单（见 filter_menu()）。 */
	private const ACCESS_ITEMS = array(
		'wc-settings' => array( 'settings' ),
		'wc-admin'    => array( 'customers', 'data', 'settings' ),
	);

	/**
	 * 一个后台地址属于哪个模块：always = 都能用，null = 不属于任何模块（员工不能打开）。
	 * $tab 是 WooCommerce 设置的标签（空 = 常规）。
	 */
	public static function module_for( string $file, string $page = '', string $post_type = '', string $taxonomy = '', string $path = '', string $tab = '' ): ?string {
		if ( in_array( $file, self::ALWAYS, true ) ) {
			return 'always';
		}
		if ( '' !== $page ) {
			$pages = array(
				'wc-orders'             => 'orders',
				'pc-recovery'           => 'orders',
				'pc-overview'           => 'data',
				'pc-visitors'           => 'data',
				'pc-data'               => 'data',
				'pc-store-design'       => 'design',
				'pc-design-editor'      => 'design',
				'pc-promotions'         => 'marketing',
				'pc-coupons'            => 'marketing',
				'pc-shipping-discounts' => 'marketing',
				'pc-shipping'           => 'settings',
				'pc-tax'                => 'settings',
				'pc-system'             => 'settings',
				'pc-media'              => 'backend',
				'pc-activity'           => 'backend',
				'pc-apps'               => 'apps',
				'product-reviews'       => 'products',
				'product_attributes'    => 'products',
				'product_importer'      => 'products',
				'product_exporter'      => 'products',
			);
			if ( 'wc-settings' === $page ) {
				return in_array( $tab, self::STORE_TABS, true ) ? 'settings' : null;
			}
			// WooCommerce 首页、扩展市场、它自己的设置等其他 wc-admin 页面不属于店铺后台。
			if ( 'wc-admin' === $page ) {
				if ( str_starts_with( $path, '/customers' ) ) {
					return 'customers';
				}
				if ( str_starts_with( $path, '/analytics' ) ) {
					return 'data';
				}
				return str_starts_with( $path, '/payments' ) ? 'settings' : null;
			}
			// 前缀相同的子页面（例如 pc-store-design-seo、pc-visitors-archive）属于同一个模块；最长的前缀优先。
			$best = null;
			$len  = 0;
			foreach ( $pages as $slug => $module ) {
				if ( ( $page === $slug || str_starts_with( $page, $slug . '-' ) ) && strlen( $slug ) > $len ) {
					$best = $module;
					$len  = strlen( $slug );
				}
			}
			return $best;
		}
		$types = array(
			'product'     => 'products',
			'pc_report'   => 'products',
			'pc_batch'    => 'products',
			'shop_order'  => 'orders',
			'shop_coupon' => 'marketing',
			'post'        => 'blog',
			'page'        => 'design',
			'pc_topic'    => 'design',
			'attachment'  => 'backend',
		);
		$taxes = array(
			'product_cat'   => 'products',
			'product_tag'   => 'products',
			'product_brand' => 'products',
			'category'      => 'blog',
			'post_tag'      => 'blog',
		);
		if ( in_array( $file, array( 'edit-tags.php', 'term.php' ), true ) ) {
			if ( isset( $taxes[ $taxonomy ] ) ) {
				return $taxes[ $taxonomy ];
			}
			return str_starts_with( $taxonomy, 'pa_' ) ? 'products' : null;
		}
		if ( in_array( $file, array( 'edit.php', 'post.php', 'post-new.php' ), true ) ) {
			return $types[ '' !== $post_type ? $post_type : 'post' ] ?? null;
		}
		if ( in_array( $file, array( 'upload.php', 'media-new.php' ), true ) ) {
			return 'backend';
		}
		return null;
	}

	/** 后台地址（菜单项或完整网址）属于哪个模块。 */
	public static function module_for_url( string $url ): ?string {
		$parts = wp_parse_url( $url );
		$path  = (string) ( $parts['path'] ?? '' );
		$args  = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $args );
		if ( ! str_contains( $path, '.php' ) ) {
			// 菜单里的插件页面只写 page 的值（可能带参数，例如 wc-admin&path=/customers）；其他地址（如 /wp-admin/）不算。
			if ( '' === $path || isset( $parts['host'] ) || str_starts_with( $path, '/' ) ) {
				return null;
			}
			return self::module_for_url( 'admin.php?page=' . $url );
		}
		$post_type = (string) ( $args['post_type'] ?? '' );
		if ( 'post.php' === wp_basename( $path ) && ! empty( $args['post'] ) ) {
			$post_type = (string) get_post_type( (int) $args['post'] );
		}
		return self::module_for( wp_basename( $path ), (string) ( $args['page'] ?? '' ), $post_type, (string) ( $args['taxonomy'] ?? '' ), (string) ( $args['path'] ?? '' ), sanitize_key( (string) ( $args['tab'] ?? '' ) ) );
	}

	/** 打开后台页面时检查：员工没勾这个模块就拒绝。 */
	public static function gate( $screen ): void {
		$id = get_current_user_id();
		if ( ! $id || ! self::is_staff( $id ) || ! $screen instanceof \WP_Screen ) {
			return;
		}
		global $pagenow, $plugin_page;
		$page   = (string) ( $plugin_page ?? '' );
		$path   = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$module = self::module_for( (string) $pagenow, $page, (string) $screen->post_type, (string) $screen->taxonomy, $path, $tab );
		if ( self::allowed( $id, $module ) ) {
			return;
		}
		$why = null === $module
			? __( 'This page is not part of the store admin. It is managed by the administrator.', 'peptide-core' )
			: __( 'Your account does not have permission for this menu. Ask the administrator to allow it for your account.', 'peptide-core' );
		wp_die(
			'<p>' . esc_html( $why ) . '</p><p><a href="' . esc_url( self::home_url( $id ) ) . '">' . esc_html__( 'Go to a page you can use', 'peptide-core' ) . '</a></p>',
			esc_html__( 'No permission', 'peptide-core' ),
			array( 'response' => 403 )
		);
	}

	/** 员工的左侧菜单：只留勾选的模块（和个人资料）。 */
	public static function filter_menu(): void {
		$id = get_current_user_id();
		if ( ! $id || ! self::is_staff( $id ) ) {
			return;
		}
		global $menu, $submenu;
		foreach ( (array) $submenu as $parent => $items ) {
			foreach ( (array) $items as $i => $item ) {
				$slug = (string) ( $item[2] ?? '' );
				$url  = str_contains( $slug, '.php' ) ? $slug : ( str_contains( (string) $parent, '.php' ) ? $parent . ( str_contains( (string) $parent, '?' ) ? '&' : '?' ) . 'page=' . $slug : $slug );
				// WooCommerce 设置和 wc-admin 在菜单里各有一项不带标签、路径的（菜单本身不显示）：WordPress 靠它判断这个页面能不能打开，
				// 各个标签和路径再由 gate() 分。用到它们的菜单勾了一个就留着。
				if ( isset( self::ACCESS_ITEMS[ $slug ] ) ) {
					if ( ! array_intersect( self::ACCESS_ITEMS[ $slug ], self::granted( $id ) ) ) {
						unset( $submenu[ $parent ][ $i ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					}
					continue;
				}
				if ( ! self::allowed( $id, self::module_for_url( $url ) ) ) {
					unset( $submenu[ $parent ][ $i ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				}
			}
		}
		foreach ( (array) $menu as $i => $item ) {
			$slug = (string) ( $item[2] ?? '' );
			if ( str_starts_with( (string) ( $item[4] ?? '' ), 'wp-menu-separator' ) ) {
				continue;
			}
			if ( self::allowed( $id, self::module_for_url( $slug ) ) || ! empty( $submenu[ $slug ] ) || 'profile.php' === $slug ) {
				continue;
			}
			unset( $menu[ $i ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}
}
