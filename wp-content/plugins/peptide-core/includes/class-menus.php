<?php
/**
 * 菜单导航（MENU-NAV-01）：参照 Shopname 的“店铺装修 → 菜单导航”。
 *
 * 数据使用 WordPress 自带的导航菜单（nav_menu 分类 + nav_menu_item 文章），在此基础上增加：
 * - 两份固定菜单：页头（顶部导航菜单）和页尾（页尾菜单），不可删除；第一次使用时按主题现有的页头 / 页脚链接建好；
 * - 每一项的链接类型：无、首页、所有商品、分类、商品、页面、文章、购物车、我的账号、登录、注册、忘记密码、
 *   我的订单、搜索、自定义网址。站内对象按 ID 保存，显示时再取最新网址，改了网址或删除对象不会留下坏链接；
 * - 每一项的设置：打开方式、是否让搜索引擎跟踪（nofollow）、链接 title、字体颜色、图标、斜体、加粗、装饰线、说明文字；
 * - 最多两级（一级目录 + 二级目录），每份菜单最多 200 项；
 * - 导出 / 导入 JSON（导入总是新建菜单，不覆盖现有菜单）。
 *
 * 前台用 peptide_core_get_menu( 'header' | 'footer' ) 取得整理好的菜单树。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

use PeptideCore\Modules\Fields;

defined( 'ABSPATH' ) || exit;

final class Menus {

	public const CAP = 'edit_theme_options';

	/** 菜单（分类项）的元数据。 */
	public const FIXED_META    = '_pc_fixed';
	public const AUTHOR_META   = '_pc_author';
	public const MODIFIED_META = '_pc_modified';

	/** 菜单项的元数据：特殊链接类型（首页、购物车等）和样式。 */
	public const LINK_META  = '_pc_link';
	/** 链接到某张优惠码时，优惠码的 ID（优惠码没有自己的固定网址，显示时按当前代码生成详情页网址）。 */
	public const LINK_ID_META = '_pc_link_id';
	public const STYLE_META = '_pc_style';

	/** 我们的位置 => 主题的菜单位置。主题已经注册了 primary；页尾位置由插件注册。 */
	public const LOCATIONS = array(
		'header' => 'primary',
		'footer' => 'footer',
	);

	public const MAX_ITEMS = 200;
	public const EXPORT_VERSION = 1;

	/** 网址由插件计算的链接类型。 */
	private const SPECIAL = array( 'home', 'shop', 'cart', 'account', 'login', 'register', 'lost_password', 'orders', 'search', 'coupons' );

	/** 按 ID 关联的站内对象：链接类型 => [菜单项类型, 对象]。 */
	private const OBJECTS = array(
		'page'     => array( 'post_type', 'page' ),
		'post'     => array( 'post_type', 'post' ),
		'product'  => array( 'post_type', 'product' ),
		'category' => array( 'taxonomy', 'product_cat' ),
	);

	private const STYLE_OPTIONS = array(
		'italic'     => array( '', 'normal', 'italic' ),
		'bold'       => array( '', 'normal', 'bold' ),
		'decoration' => array( '', 'none', 'underline', 'overline', 'line-through' ),
	);

	public static function register_hooks(): void {
		add_action( 'after_setup_theme', array( self::class, 'register_locations' ), 20 );
		add_action( 'pre_delete_term', array( self::class, 'protect_fixed' ), 10, 2 );
	}

	public static function register_locations(): void {
		$registered = get_registered_nav_menus();
		$add        = array();
		if ( ! isset( $registered[ self::LOCATIONS['header'] ] ) ) {
			$add[ self::LOCATIONS['header'] ] = __( 'Header menu', 'peptide-core' );
		}
		if ( ! isset( $registered[ self::LOCATIONS['footer'] ] ) ) {
			$add[ self::LOCATIONS['footer'] ] = __( 'Footer menu', 'peptide-core' );
		}
		if ( $add ) {
			register_nav_menus( $add );
		}
	}

	/** 固定菜单不能删除（包括从“外观 → 菜单”删除）。 */
	public static function protect_fixed( $term_id, $taxonomy ): void {
		if ( 'nav_menu' === $taxonomy && self::fixed_key( (int) $term_id ) ) {
			wp_die( esc_html__( 'The header and footer menus cannot be deleted.', 'peptide-core' ), 403 );
		}
	}

	/**
	 * 链接类型 => 名称（编辑器的“链接页面”下拉框）。
	 *
	 * @return array<string,string>
	 */
	public static function link_types(): array {
		return array(
			'none'          => __( 'None (heading only)', 'peptide-core' ),
			'home'          => __( 'Homepage', 'peptide-core' ),
			'shop'          => __( 'All products', 'peptide-core' ),
			'category'      => __( 'Product category', 'peptide-core' ),
			'product'       => __( 'Product page', 'peptide-core' ),
			'page'          => __( 'Custom page', 'peptide-core' ),
			'post'          => __( 'Blog post', 'peptide-core' ),
			'cart'          => __( 'Cart', 'peptide-core' ),
			'account'       => __( 'My account', 'peptide-core' ),
			'orders'        => __( 'My orders / order tracking', 'peptide-core' ),
			'login'         => __( 'Log in', 'peptide-core' ),
			'register'      => __( 'Register', 'peptide-core' ),
			'lost_password' => __( 'Forgot password', 'peptide-core' ),
			'search'        => __( 'Product search', 'peptide-core' ),
			'coupons'       => __( 'Coupons page', 'peptide-core' ),
			'coupon'        => __( 'Coupon details', 'peptide-core' ),
			'custom'        => __( 'Custom URL', 'peptide-core' ),
		);
	}

	public static function special_url( string $key ): string {
		switch ( $key ) {
			case 'home':
				return home_url( '/' );
			case 'shop':
				return (string) wc_get_page_permalink( 'shop' );
			case 'cart':
				return (string) wc_get_cart_url();
			case 'account':
			case 'login':
			case 'register':
				return (string) wc_get_page_permalink( 'myaccount' );
			case 'orders':
				return (string) wc_get_account_endpoint_url( 'orders' );
			case 'lost_password':
				return (string) wc_lostpassword_url();
			case 'search':
				return add_query_arg( array( 's' => '', 'post_type' => 'product' ), home_url( '/' ) );
			case 'coupons':
				return Coupons::list_url();
		}
		return '';
	}

	/** 需要选择具体对象（带 ID）的链接类型。 */
	private static function needs_id( string $type ): bool {
		return isset( self::OBJECTS[ $type ] ) || 'coupon' === $type;
	}

	// ---------- 菜单 ----------

	public static function exists( int $menu_id ): bool {
		return $menu_id > 0 && is_nav_menu( $menu_id ) && ( wp_get_nav_menu_object( $menu_id ) instanceof \WP_Term );
	}

	/** 固定菜单的键（header / footer），普通菜单返回空字符串。 */
	public static function fixed_key( int $menu_id ): string {
		$key = (string) get_term_meta( $menu_id, self::FIXED_META, true );
		return isset( self::LOCATIONS[ $key ] ) ? $key : '';
	}

	/** 这份菜单现在用在哪里：header / footer / 空。 */
	public static function location_of( int $menu_id ): string {
		$locations = get_nav_menu_locations();
		foreach ( self::LOCATIONS as $key => $theme_location ) {
			if ( isset( $locations[ $theme_location ] ) && (int) $locations[ $theme_location ] === $menu_id ) {
				return $key;
			}
		}
		return '';
	}

	public static function menu_for( string $location ): int {
		if ( ! isset( self::LOCATIONS[ $location ] ) ) {
			return 0;
		}
		$locations = get_nav_menu_locations();
		$id        = (int) ( $locations[ self::LOCATIONS[ $location ] ] ?? 0 );
		return self::exists( $id ) ? $id : 0;
	}

	public static function set_location( int $menu_id, string $location ): void {
		$locations = get_theme_mod( 'nav_menu_locations', array() );
		$locations = is_array( $locations ) ? $locations : array();
		foreach ( self::LOCATIONS as $key => $theme_location ) {
			if ( $key === $location ) {
				$locations[ $theme_location ] = $menu_id;
			} elseif ( isset( $locations[ $theme_location ] ) && (int) $locations[ $theme_location ] === $menu_id ) {
				unset( $locations[ $theme_location ] );
			}
		}
		set_theme_mod( 'nav_menu_locations', $locations );
	}

	/**
	 * 确保两份固定菜单存在。第一次运行时按主题现有的页头 / 页脚链接建好，并在主题位置空着时设为页头 / 页尾菜单。
	 *
	 * @return array{header:int,footer:int}
	 */
	public static function ensure_fixed(): array {
		$ids = array();
		foreach ( array_keys( self::LOCATIONS ) as $key ) {
			$found = get_terms(
				array(
					'taxonomy'   => 'nav_menu',
					'hide_empty' => false,
					'fields'     => 'ids',
					'number'     => 1,
					'meta_key'   => self::FIXED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( ! is_wp_error( $found ) && $found ) {
				$ids[ $key ] = (int) $found[0];
				continue;
			}
			$name = 'header' === $key ? __( 'Header menu', 'peptide-core' ) : __( 'Footer menu', 'peptide-core' );
			$id   = wp_create_nav_menu( self::unique_name( $name ) );
			if ( is_wp_error( $id ) ) {
				$ids[ $key ] = 0;
				continue;
			}
			update_term_meta( $id, self::FIXED_META, $key );
			self::touch( $id );
			[ $seed ] = self::clean_items( self::seed_items( $key ) );
			self::save_items( $id, $seed );
			if ( ! self::menu_for( $key ) ) {
				self::set_location( $id, $key );
			}
			$ids[ $key ] = (int) $id;
		}
		return $ids;
	}

	/** 主题现有的页头 / 页脚链接（2026-10-22 的 header.php、navigation.php、footer.php），用作固定菜单的初始内容。 */
	private static function seed_items( string $key ): array {
		$page = static function ( string $slug, string $title, string $description = '' ): ?array {
			$p = get_page_by_path( $slug );
			return $p ? self::seed( $title, 'page', $p->ID, $description ) : null;
		};
		if ( 'header' === $key ) {
			return array(
				self::seed( 'Products', 'shop' ),
				self::seed( 'Research', 'none', 0, '', array_filter( array( $page( 'certificates', 'Certificates & reports', 'Browse documentation by product' ), $page( 'faq', 'FAQ', 'Answers to common questions' ) ) ) ),
				self::seed( 'About', 'none', 0, '', array_filter( array( $page( 'about-us', 'About us', 'The people and places behind our vision' ), $page( 'quality-standards', 'Quality standards', 'What to look for in the documentation' ), $page( 'methodology', 'Methodology', 'Follow the record from product to batch' ) ) ) ),
			);
		}
		return array_values(
			array_filter(
				array(
					self::seed( 'Explore the collection', 'shop' ),
					$page( 'batch-reports', 'Find a batch report' ),
					self::seed( 'My account', 'account' ),
				)
			)
		);
	}

	private static function seed( string $title, string $type, int $id = 0, string $description = '', array $children = array() ): array {
		return array(
			'title'       => $title,
			'description' => $description,
			'link'        => array( 'type' => $type, 'id' => $id, 'url' => '' ),
			'children'    => array_values( $children ),
		);
	}

	private static function unique_name( string $name ): string {
		$base = $name;
		for ( $i = 2; wp_get_nav_menu_object( $name ); $i++ ) {
			$name = $base . ' (' . $i . ')';
		}
		return $name;
	}

	private static function touch( int $menu_id ): string {
		$stamp = (string) microtime( true );
		update_term_meta( $menu_id, self::MODIFIED_META, $stamp );
		return $stamp;
	}

	public static function modified( int $menu_id ): string {
		return (string) get_term_meta( $menu_id, self::MODIFIED_META, true );
	}

	/** @return int|\WP_Error */
	public static function create( string $name ) {
		$name = self::clean_name( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'pc_menu_name', __( 'Please enter a menu name.', 'peptide-core' ) );
		}
		$id = wp_create_nav_menu( self::unique_name( $name ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_term_meta( $id, self::AUTHOR_META, get_current_user_id() );
		self::touch( $id );
		return (int) $id;
	}

	/** @return true|\WP_Error */
	public static function delete( int $menu_id ) {
		if ( ! self::exists( $menu_id ) ) {
			return new \WP_Error( 'pc_menu_missing', __( 'Menu not found.', 'peptide-core' ) );
		}
		if ( self::fixed_key( $menu_id ) ) {
			return new \WP_Error( 'pc_menu_fixed', __( 'The header and footer menus cannot be deleted.', 'peptide-core' ) );
		}
		self::set_location( $menu_id, '' );
		$r = wp_delete_nav_menu( $menu_id );
		return is_wp_error( $r ) ? $r : true;
	}

	private static function clean_name( string $name ): string {
		$name = trim( sanitize_text_field( $name ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 40 ) : substr( $name, 0, 40 );
	}

	/**
	 * 菜单列表（后台“菜单导航”页）：固定菜单在前。
	 *
	 * @return array<int,array{id:int,name:string,fixed:string,location:string,top:string[],count:int,author:string}>
	 */
	public static function all(): array {
		$rows = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) );
			$items = is_array( $items ) ? $items : array();
			$top   = array();
			foreach ( $items as $item ) {
				if ( ! (int) $item->menu_item_parent ) {
					$top[] = (string) $item->title;
				}
			}
			$author = (int) get_term_meta( $menu->term_id, self::AUTHOR_META, true );
			$user   = $author ? get_userdata( $author ) : false;
			$rows[] = array(
				'id'       => (int) $menu->term_id,
				'name'     => (string) $menu->name,
				'fixed'    => self::fixed_key( (int) $menu->term_id ),
				'location' => self::location_of( (int) $menu->term_id ),
				'top'      => $top,
				'count'    => count( $items ),
				'author'   => $user ? (string) $user->display_name : '',
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				$order = array( 'header' => 0, 'footer' => 1, '' => 2 );
				return array( $order[ $a['fixed'] ], $a['id'] ) <=> array( $order[ $b['fixed'] ], $b['id'] );
			}
		);
		return $rows;
	}

	/**
	 * 编辑器使用的一份菜单。
	 *
	 * @return array{id:int,name:string,fixed:string,location:string,modified:string,items:array}|null
	 */
	public static function get( int $menu_id ): ?array {
		if ( ! self::exists( $menu_id ) ) {
			return null;
		}
		$menu = wp_get_nav_menu_object( $menu_id );
		return array(
			'id'       => $menu_id,
			'name'     => (string) $menu->name,
			'fixed'    => self::fixed_key( $menu_id ),
			'location' => self::location_of( $menu_id ),
			'modified' => self::modified( $menu_id ),
			'items'    => self::tree( $menu_id, false ),
		);
	}

	// ---------- 菜单项 ----------

	/**
	 * 菜单项整理成两级树。$public 为 true 时用于前台：跳过已删除 / 未发布的对象，算好网址和样式。
	 */
	private static function tree( int $menu_id, bool $public ): array {
		$items = wp_get_nav_menu_items( $menu_id, array( 'update_post_term_cache' => false ) );
		$items = is_array( $items ) ? $items : array();
		$nodes = array();
		$order = array();
		foreach ( $items as $item ) {
			$node = $public ? self::public_item( $item ) : self::editor_item( $item );
			if ( null === $node ) {
				continue;
			}
			$nodes[ (int) $item->ID ] = $node;
			$order[]                  = array( (int) $item->ID, (int) $item->menu_item_parent );
		}
		$tree  = array();
		$index = array();
		foreach ( $order as [ $id, $parent ] ) {
			if ( $parent && isset( $index[ $parent ] ) ) {
				$tree[ $index[ $parent ] ]['children'][] = $nodes[ $id ];
			} elseif ( ! $parent ) {
				$index[ $id ] = count( $tree );
				$tree[]       = $nodes[ $id ];
			}
			// 父级被跳过（例如对象已删除）或者第三级的项不显示。
		}
		return $tree;
	}

	/** 菜单项的链接：{type, id, url}。 */
	private static function link_of( \WP_Post $item ): array {
		$special = (string) get_post_meta( $item->ID, self::LINK_META, true );
		if ( 'custom' === $item->type ) {
			if ( 'coupon' === $special ) {
				return array( 'type' => 'coupon', 'id' => (int) get_post_meta( $item->ID, self::LINK_ID_META, true ), 'url' => '' );
			}
			if ( in_array( $special, self::SPECIAL, true ) ) {
				return array( 'type' => $special, 'id' => 0, 'url' => '' );
			}
			$url = (string) get_post_meta( $item->ID, '_menu_item_url', true );
			return '' === $url || '#' === $url ? array( 'type' => 'none', 'id' => 0, 'url' => '' ) : array( 'type' => 'custom', 'id' => 0, 'url' => $url );
		}
		foreach ( self::OBJECTS as $type => [ $item_type, $object ] ) {
			if ( $item->type === $item_type && $item->object === $object ) {
				return array( 'type' => $type, 'id' => (int) $item->object_id, 'url' => '' );
			}
		}
		// 其他来源（例如在“外观 → 菜单”里加的文章分类）：按当时的网址保留。
		return array( 'type' => 'custom', 'id' => 0, 'url' => (string) $item->url );
	}

	private static function style_of( int $item_id ): array {
		$s = get_post_meta( $item_id, self::STYLE_META, true );
		$s = is_array( $s ) ? $s : array();
		return array(
			'color'      => (string) ( sanitize_hex_color( (string) ( $s['color'] ?? '' ) ) ?? '' ),
			'italic'     => self::style_option( $s, 'italic' ),
			'bold'       => self::style_option( $s, 'bold' ),
			'decoration' => self::style_option( $s, 'decoration' ),
			'icon'       => absint( $s['icon'] ?? 0 ),
		);
	}

	/** 斜体 / 加粗 / 装饰线的取值，不在可选范围内时为空（维持现状）。 */
	private static function style_option( array $style, string $key ): string {
		$value = is_scalar( $style[ $key ] ?? null ) ? (string) $style[ $key ] : '';
		return in_array( $value, self::STYLE_OPTIONS[ $key ], true ) ? $value : '';
	}

	/** 对象是否还在、标题是什么（编辑器里提示坏链接）。 */
	private static function object_state( array $link ): array {
		if ( 'coupon' === $link['type'] ) {
			if ( ! $link['id'] || 'shop_coupon' !== get_post_type( $link['id'] ) || 'trash' === get_post_status( $link['id'] ) ) {
				return array( 'label' => '', 'broken' => true );
			}
			$coupon = new \WC_Coupon( $link['id'] );
			return array( 'label' => strtoupper( $coupon->get_code() ), 'broken' => '' !== Coupons::hidden_reason( $coupon ) );
		}
		if ( ! isset( self::OBJECTS[ $link['type'] ] ) ) {
			return array( 'label' => '', 'broken' => false );
		}
		[ $item_type, $object ] = self::OBJECTS[ $link['type'] ];
		if ( 'taxonomy' === $item_type ) {
			$term = get_term( $link['id'], $object );
			return $term instanceof \WP_Term ? array( 'label' => html_entity_decode( $term->name ), 'broken' => false ) : array( 'label' => '', 'broken' => true );
		}
		$post = get_post( $link['id'] );
		if ( ! $post || $post->post_type !== $object || 'trash' === $post->post_status ) {
			return array( 'label' => '', 'broken' => true );
		}
		return array( 'label' => html_entity_decode( get_the_title( $post ) ), 'broken' => 'publish' !== $post->post_status );
	}

	private static function editor_item( \WP_Post $item ): array {
		$link  = self::link_of( $item );
		$state = self::object_state( $link );
		return array(
			'id'          => (int) $item->ID,
			// WordPress 在名称与对象标题相同时不单独保存名称，这里取已经补全的 title。
			'title'       => html_entity_decode( (string) $item->title ),
			'description' => (string) $item->post_content,
			'link'        => $link + $state,
			'target'      => in_array( (string) $item->target, array( '_self', '_blank' ), true ) ? (string) $item->target : '',
			'nofollow'    => str_contains( ' ' . (string) $item->xfn . ' ', ' nofollow ' ),
			'attrTitle'   => (string) $item->post_excerpt,
			'style'       => self::style_of( (int) $item->ID ),
			'children'    => array(),
		);
	}

	/** 前台的一项；对象已删除或未发布时返回 null（不显示）。 */
	private static function public_item( \WP_Post $item ): ?array {
		$link = self::link_of( $item );
		$url  = '';
		if ( in_array( $link['type'], self::SPECIAL, true ) ) {
			$url = self::special_url( $link['type'] );
		} elseif ( 'custom' === $link['type'] ) {
			$url = $link['url'];
		} elseif ( 'coupon' === $link['type'] ) {
			// 优惠码过期、用完或不再公开时，这一项不显示。
			if ( self::object_state( $link )['broken'] ) {
				return null;
			}
			$url = Coupons::detail_url( (string) get_post_field( 'post_title', $link['id'] ) );
		} elseif ( isset( self::OBJECTS[ $link['type'] ] ) ) {
			$state = self::object_state( $link );
			if ( $state['broken'] ) {
				return null;
			}
			$url = 'category' === $link['type'] ? get_term_link( $link['id'], 'product_cat' ) : get_permalink( $link['id'] );
			$url = is_wp_error( $url ) ? '' : (string) $url;
		}
		$style = self::style_of( (int) $item->ID );
		$css   = array();
		if ( $style['color'] ) {
			$css[] = 'color:' . $style['color'];
		}
		if ( $style['italic'] ) {
			$css[] = 'font-style:' . $style['italic'];
		}
		if ( $style['bold'] ) {
			$css[] = 'font-weight:' . ( 'bold' === $style['bold'] ? '700' : '400' );
		}
		if ( $style['decoration'] ) {
			$css[] = 'text-decoration:' . $style['decoration'];
		}
		$icon = null;
		if ( $style['icon'] ) {
			$src = wp_get_attachment_image_src( $style['icon'], 'thumbnail' );
			if ( $src ) {
				$icon = array(
					'url'    => (string) $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
					'alt'    => (string) get_post_meta( $style['icon'], '_wp_attachment_image_alt', true ),
				);
			}
		}
		$title = html_entity_decode( (string) $item->title );
		return array(
			'id'          => (int) $item->ID,
			'title'       => $title,
			'description' => (string) $item->post_content,
			'type'        => $link['type'],
			'object_id'   => $link['id'],
			'url'         => $url,
			'target'      => in_array( (string) $item->target, array( '_self', '_blank' ), true ) ? (string) $item->target : '',
			'rel'         => trim( ( str_contains( ' ' . (string) $item->xfn . ' ', ' nofollow ' ) ? 'nofollow ' : '' ) . ( '_blank' === $item->target ? 'noopener' : '' ) ),
			'attr_title'  => (string) $item->post_excerpt,
			'style'       => implode( ';', $css ),
			'icon'        => $icon,
			'current'     => self::is_current( $link ),
			'children'    => array(),
		);
	}

	private static function is_current( array $link ): bool {
		if ( did_action( 'wp' ) === 0 ) {
			return false;
		}
		switch ( $link['type'] ) {
			case 'home':
				return is_front_page();
			case 'shop':
				return function_exists( 'is_shop' ) && is_shop();
			case 'cart':
				return function_exists( 'is_cart' ) && is_cart();
			case 'account':
				return function_exists( 'is_account_page' ) && is_account_page();
			case 'coupons':
				return Coupons::page_id() && is_page( Coupons::page_id() );
			case 'category':
				return is_tax( 'product_cat', $link['id'] );
			case 'page':
			case 'post':
			case 'product':
				return is_singular() && get_queried_object_id() === $link['id'];
		}
		return false;
	}

	/**
	 * 前台菜单树：位置（header / footer）上没有菜单时返回 null，主题继续显示自己的默认链接。
	 */
	public static function public_tree( string $location ): ?array {
		$id = self::menu_for( $location );
		return $id ? self::tree( $id, true ) : null;
	}

	/**
	 * 清理编辑器提交的菜单项。返回 [items, errors]。
	 *
	 * @return array{0:array,1:string[]}
	 */
	public static function clean_items( $raw ): array {
		$count  = 0;
		$errors = array();
		$out    = array();
		foreach ( is_array( $raw ) ? array_values( $raw ) : array() as $item ) {
			$node = self::clean_item( $item, 1, $count, $errors );
			if ( $node ) {
				$out[] = $node;
			}
		}
		if ( $count > self::MAX_ITEMS ) {
			/* translators: %d: maximum number of items */
			$errors[] = sprintf( __( 'A menu can have at most %d items.', 'peptide-core' ), self::MAX_ITEMS );
		}
		return array( $out, $errors );
	}

	private static function clean_item( $raw, int $depth, int &$count, array &$errors ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		++$count;
		$text  = static function ( $v, int $max ): string {
			$v = is_scalar( $v ) ? trim( sanitize_text_field( (string) $v ) ) : '';
			return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
		};
		$link  = is_array( $raw['link'] ?? null ) ? $raw['link'] : array();
		$type  = is_string( $link['type'] ?? null ) && isset( self::link_types()[ $link['type'] ] ) ? $link['type'] : 'none';
		$id    = absint( $link['id'] ?? 0 );
		$url   = 'custom' === $type ? Fields::url( is_scalar( $link['url'] ?? null ) ? (string) $link['url'] : '' ) : '';
		$title = $text( $raw['title'] ?? '', 80 );
		if ( 'custom' === $type && '' === $url ) {
			$type = 'none';
		}
		if ( self::needs_id( $type ) ) {
			$state = self::object_state( array( 'type' => $type, 'id' => $id ) );
			if ( ! $id || ( $state['broken'] && '' === $state['label'] ) ) {
				/* translators: %s: menu item name */
				$errors[] = sprintf( __( '“%s”: the linked page no longer exists. Choose another link.', 'peptide-core' ), '' !== $title ? $title : __( '(untitled)', 'peptide-core' ) );
				return null;
			}
			if ( '' === $title ) {
				$title = $state['label'];
			}
		}
		if ( '' === $title ) {
			$errors[] = __( 'Every menu item needs a name.', 'peptide-core' );
			return null;
		}
		$style    = is_array( $raw['style'] ?? null ) ? $raw['style'] : array();
		$icon     = absint( $style['icon'] ?? 0 );
		$children = array();
		if ( 1 === $depth ) {
			foreach ( is_array( $raw['children'] ?? null ) ? array_values( $raw['children'] ) : array() as $child ) {
				$node = self::clean_item( $child, 2, $count, $errors );
				if ( $node ) {
					$children[] = $node;
				}
			}
		}
		return array(
			'id'          => absint( $raw['id'] ?? 0 ),
			'title'       => $title,
			'description' => $text( $raw['description'] ?? '', 200 ),
			'link'        => array( 'type' => $type, 'id' => self::needs_id( $type ) ? $id : 0, 'url' => $url ),
			'target'      => in_array( $raw['target'] ?? '', array( '_self', '_blank' ), true ) ? (string) ( $raw['target'] ?? '' ) : '',
			'nofollow'    => ! empty( $raw['nofollow'] ),
			'attrTitle'   => $text( $raw['attrTitle'] ?? '', 200 ),
			'style'       => array(
				'color'      => strtolower( (string) ( sanitize_hex_color( is_scalar( $style['color'] ?? null ) ? (string) $style['color'] : '' ) ?? '' ) ),
				'italic'     => self::style_option( $style, 'italic' ),
				'bold'       => self::style_option( $style, 'bold' ),
				'decoration' => self::style_option( $style, 'decoration' ),
				'icon'       => $icon && wp_attachment_is_image( $icon ) ? $icon : 0,
			),
			'children'    => $children,
		);
	}

	/**
	 * 保存整份菜单：名称、位置、菜单项。$modified 与服务器记录不同时返回 409（别人刚改过）。
	 *
	 * @return array|\WP_Error 保存后的菜单。
	 */
	public static function save( int $menu_id, array $data, ?string $modified = null ) {
		if ( ! self::exists( $menu_id ) ) {
			return new \WP_Error( 'pc_menu_missing', __( 'Menu not found.', 'peptide-core' ), array( 'status' => 404 ) );
		}
		if ( null !== $modified && $modified !== self::modified( $menu_id ) ) {
			return new \WP_Error( 'pc_menu_conflict', __( 'This menu was changed somewhere else after you opened it. Reload the page to see the latest version.', 'peptide-core' ), array( 'status' => 409 ) );
		}
		[ $items, $errors ] = self::clean_items( $data['items'] ?? array() );
		$name               = self::clean_name( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			$errors[] = __( 'Please enter a menu name.', 'peptide-core' );
		}
		if ( $errors ) {
			return new \WP_Error( 'pc_menu_invalid', implode( ' ', array_unique( $errors ) ), array( 'status' => 400 ) );
		}
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( $name !== $menu->name ) {
			$other = wp_get_nav_menu_object( $name );
			if ( $other && (int) $other->term_id !== $menu_id ) {
				return new \WP_Error( 'pc_menu_name_taken', __( 'Another menu already uses this name.', 'peptide-core' ), array( 'status' => 400 ) );
			}
			wp_update_nav_menu_object( $menu_id, array( 'menu-name' => $name ) );
		}
		self::save_items( $menu_id, $items );
		if ( array_key_exists( 'location', $data ) ) {
			$location = is_string( $data['location'] ) && isset( self::LOCATIONS[ $data['location'] ] ) ? $data['location'] : '';
			self::set_location( $menu_id, $location );
		}
		self::touch( $menu_id );
		return self::get( $menu_id );
	}

	/** 把清理好的菜单项写进数据库：更新已有的、新增没有 ID 的、删除不再出现的。 */
	private static function save_items( int $menu_id, array $items ): void {
		$existing = array();
		foreach ( (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) as $item ) {
			$existing[ (int) $item->ID ] = true;
		}
		$kept     = array();
		$position = 0;
		$write    = static function ( array $node, int $parent ) use ( $menu_id, &$existing, &$kept, &$position, &$write ): void {
			$link = $node['link'];
			$args = array(
				'menu-item-title'       => $node['title'],
				'menu-item-description' => $node['description'],
				'menu-item-attr-title'  => $node['attrTitle'],
				'menu-item-target'      => $node['target'],
				'menu-item-xfn'         => $node['nofollow'] ? 'nofollow' : '',
				'menu-item-parent-id'   => $parent,
				'menu-item-position'    => ++$position,
				'menu-item-status'      => 'publish',
			);
			if ( isset( self::OBJECTS[ $link['type'] ] ) ) {
				[ $item_type, $object ]          = self::OBJECTS[ $link['type'] ];
				$args['menu-item-type']      = $item_type;
				$args['menu-item-object']    = $object;
				$args['menu-item-object-id'] = $link['id'];
				$args['menu-item-url']       = '';
			} else {
				$args['menu-item-type']      = 'custom';
				$args['menu-item-object']    = 'custom';
				$args['menu-item-object-id'] = 0;
				$args['menu-item-url']       = 'custom' === $link['type'] ? $link['url'] : ( in_array( $link['type'], self::SPECIAL, true ) ? self::special_url( $link['type'] ) : '' );
				if ( 'coupon' === $link['type'] ) {
					$args['menu-item-url'] = Coupons::detail_url( (string) get_post_field( 'post_title', $link['id'] ) );
				}
			}
			$db_id = $node['id'] && isset( $existing[ $node['id'] ] ) ? $node['id'] : 0;
			$id    = wp_update_nav_menu_item( $menu_id, $db_id, wp_slash( $args ) );
			if ( is_wp_error( $id ) || ! $id ) {
				return;
			}
			$kept[ (int) $id ] = true;
			if ( in_array( $link['type'], self::SPECIAL, true ) || 'coupon' === $link['type'] ) {
				update_post_meta( $id, self::LINK_META, $link['type'] );
			} else {
				delete_post_meta( $id, self::LINK_META );
			}
			if ( 'coupon' === $link['type'] ) {
				update_post_meta( $id, self::LINK_ID_META, $link['id'] );
			} else {
				delete_post_meta( $id, self::LINK_ID_META );
			}
			$style = array_filter( $node['style'] ?? array() );
			if ( $style ) {
				update_post_meta( $id, self::STYLE_META, $style );
			} else {
				delete_post_meta( $id, self::STYLE_META );
			}
			foreach ( $node['children'] ?? array() as $child ) {
				$write( $child, (int) $id );
			}
		};
		foreach ( $items as $node ) {
			$write( $node, 0 );
		}
		foreach ( array_keys( $existing ) as $id ) {
			if ( ! isset( $kept[ $id ] ) ) {
				wp_delete_post( $id, true );
			}
		}
	}

	// ---------- 编辑器的选项 ----------

	/**
	 * 编辑器“链接页面”第二个下拉框的选项。
	 *
	 * @return array<string,array<int,array{id:int,label:string,name:string}>>
	 */
	public static function link_options(): array {
		$posts = static function ( string $type, int $limit ): array {
			$out = array();
			foreach ( get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
					'posts_per_page' => $limit,
					'orderby'        => 'post' === $type ? 'date' : 'title',
					'order'          => 'post' === $type ? 'DESC' : 'ASC',
					'no_found_rows'  => true,
				)
			) as $p ) {
				$name  = html_entity_decode( get_the_title( $p ) );
				/* translators: %s: page title */
				$label = 'publish' === $p->post_status ? $name : sprintf( __( '%s (not published)', 'peptide-core' ), $name );
				$out[] = array( 'id' => (int) $p->ID, 'label' => $label, 'name' => $name );
			}
			return $out;
		};
		$cats = array();
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$cats[] = array( 'id' => (int) $t->term_id, 'label' => html_entity_decode( $t->name ) . ' (' . (int) $t->count . ')', 'name' => html_entity_decode( $t->name ) );
		}
		$coupons = array();
		foreach ( Coupons::public_list() as $c ) {
			$coupons[] = array( 'id' => $c['id'], 'label' => $c['code'] . ' · ' . $c['title'], 'name' => $c['title'] );
		}
		return array(
			'coupon'   => $coupons,
			'page'     => $posts( 'page', 300 ),
			'post'     => $posts( 'post', 300 ),
			'product'  => $posts( 'product', 2000 ),
			'category' => $cats,
		);
	}

	// ---------- 导出 / 导入 ----------

	/** 导出全部菜单。站内对象用 slug 记录，换一个站点导入时按 slug 找回。 */
	public static function export(): array {
		$menus = array();
		foreach ( self::all() as $row ) {
			$menu    = self::get( $row['id'] );
			$menus[] = array(
				'name'     => $menu['name'],
				'fixed'    => $menu['fixed'],
				'location' => $menu['location'],
				'items'    => array_map( array( self::class, 'export_item' ), $menu['items'] ),
			);
		}
		return array(
			'format'   => 'peptide-core-menus',
			'version'  => self::EXPORT_VERSION,
			'exported' => gmdate( 'c' ),
			'site'     => home_url( '/' ),
			'menus'    => $menus,
		);
	}

	private static function export_item( array $item ): array {
		$link = $item['link'];
		$ref  = array( 'type' => $link['type'] );
		if ( 'custom' === $link['type'] ) {
			$ref['url'] = $link['url'];
		} elseif ( 'coupon' === $link['type'] ) {
			$ref['code'] = strtoupper( (string) get_post_field( 'post_title', $link['id'] ) );
		} elseif ( 'category' === $link['type'] ) {
			$term        = get_term( $link['id'], 'product_cat' );
			$ref['slug'] = $term instanceof \WP_Term ? $term->slug : '';
		} elseif ( isset( self::OBJECTS[ $link['type'] ] ) ) {
			$ref['slug'] = (string) get_post_field( 'post_name', $link['id'] );
		}
		return array(
			'title'       => $item['title'],
			'description' => $item['description'],
			'link'        => $ref,
			'target'      => $item['target'],
			'nofollow'    => $item['nofollow'],
			'attrTitle'   => $item['attrTitle'],
			// 图标是本站媒体库的文件，换站点后不一定存在，所以不导出。
			'style'       => array_diff_key( $item['style'], array( 'icon' => true ) ),
			'children'    => array_map( array( self::class, 'export_item' ), $item['children'] ),
		);
	}

	/**
	 * 导入：每份菜单都新建（名称重复时自动加编号），不改动现有菜单，也不设置位置。
	 * 找不到的站内对象改为“无链接”，并在结果里列出。
	 *
	 * @return array{created:int[],missing:string[]}|\WP_Error
	 */
	public static function import( $data ) {
		if ( ! is_array( $data ) || 'peptide-core-menus' !== ( $data['format'] ?? '' ) || ! is_array( $data['menus'] ?? null ) ) {
			return new \WP_Error( 'pc_menu_import_format', __( 'This file is not a menu export from this plugin.', 'peptide-core' ) );
		}
		$created = array();
		$missing = array();
		foreach ( array_slice( $data['menus'], 0, 20 ) as $menu ) {
			if ( ! is_array( $menu ) ) {
				continue;
			}
			$items = array_map(
				static function ( $item ) use ( &$missing ) {
					return self::import_item( $item, $missing );
				},
				is_array( $menu['items'] ?? null ) ? $menu['items'] : array()
			);
			[ $items ] = self::clean_items( $items );
			$id        = self::create( (string) ( $menu['name'] ?? '' ) !== '' ? (string) $menu['name'] : __( 'Imported menu', 'peptide-core' ) );
			if ( is_wp_error( $id ) ) {
				continue;
			}
			self::save_items( $id, $items );
			$created[] = $id;
		}
		return array(
			'created' => $created,
			'missing' => array_values( array_unique( $missing ) ),
		);
	}

	private static function import_item( $item, array &$missing ): array {
		$item = is_array( $item ) ? $item : array();
		$ref  = is_array( $item['link'] ?? null ) ? $item['link'] : array();
		$type = (string) ( $ref['type'] ?? 'none' );
		$link = array( 'type' => $type, 'id' => 0, 'url' => is_scalar( $ref['url'] ?? null ) ? (string) $ref['url'] : '' );
		if ( 'coupon' === $type ) {
			$link['id'] = is_scalar( $ref['code'] ?? null ) ? (int) wc_get_coupon_id_by_code( (string) $ref['code'] ) : 0;
			if ( ! $link['id'] ) {
				$missing[] = (string) ( $item['title'] ?? '' );
				$link      = array( 'type' => 'none', 'id' => 0, 'url' => '' );
			}
		} elseif ( isset( self::OBJECTS[ $type ] ) ) {
			$slug = sanitize_title( (string) ( $ref['slug'] ?? '' ) );
			if ( 'category' === $type ) {
				$term = $slug ? get_term_by( 'slug', $slug, 'product_cat' ) : false;
				$link['id'] = $term ? (int) $term->term_id : 0;
			} else {
				$found      = $slug ? get_posts( array( 'name' => $slug, 'post_type' => self::OBJECTS[ $type ][1], 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) ) : array();
				$link['id'] = $found ? (int) $found[0] : 0;
			}
			if ( ! $link['id'] ) {
				$missing[] = (string) ( $item['title'] ?? $slug );
				$link      = array( 'type' => 'none', 'id' => 0, 'url' => '' );
			}
		}
		$item['id']       = 0;
		$item['link']     = $link;
		$item['children'] = array_map(
			static function ( $child ) use ( &$missing ) {
				return self::import_item( $child, $missing );
			},
			is_array( $item['children'] ?? null ) ? $item['children'] : array()
		);
		return $item;
	}
}
