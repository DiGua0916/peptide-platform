<?php
/** Read-only editor target lookup. Only a verified URL is copied into the old href. */
namespace PeptideCore\Modules;

use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Links {
	private const ACTION = 'pc_layout_link_targets';
	private const POSTS = array( 'product' => 'product', 'page' => 'page', 'post' => 'post', 'topic' => 'pc_topic' );

	public static function register_hooks(): void { add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'ajax' ) ); }
	public static function config(): array { return array( 'url' => admin_url( 'admin-ajax.php' ), 'security' => wp_create_nonce( self::ACTION ), 'types' => self::types() ); }
	private static function types(): array {
		$types = array( array( 'value' => 'route', 'label' => '常用页面' ) );
		foreach ( array( 'product' => '商品', 'category' => '商品专辑', 'page' => '页面', 'post' => '博客文章', 'topic' => '专题页面' ) as $type => $label ) {
			$object = 'category' === $type ? get_taxonomy( 'product_cat' ) : get_post_type_object( self::POSTS[ $type ] );
			if ( $object && $object->public ) { $types[] = array( 'value' => $type, 'label' => $label ); }
		}
		foreach ( array( 'promotion' => array( '\\PeptideCore\\Promotions', '满减活动详情' ), 'coupon' => array( '\\PeptideCore\\Coupons', '优惠码详情' ) ) as $type => $service ) { if ( class_exists( $service[0] ) && ( 'coupon' !== $type || class_exists( '\\WC_Coupon' ) ) ) { $types[] = array( 'value' => $type, 'label' => $service[1] ); } }
		return $types;
	}
	public static function ajax(): void {
		$result = self::request( wp_unslash( $_GET ) );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), (int) ( $result->get_error_data()['status'] ?? 400 ) ); }
		wp_send_json_success( $result );
	}
	/** Every entry point checks permissions and nonce, including server-side resolve. */
	public static function request( array $input ) {
		if ( ! current_user_can( Templates::CAP_EDIT ) ) { return self::error( 'permission', '没有装修权限。', 403 ); }
		if ( ! wp_verify_nonce( self::scalar( $input, 'security' ), self::ACTION ) ) { return self::error( 'nonce', '链接选择权限已过期，请刷新后台。', 403 ); }
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return self::error( 'method', '请使用链接选择器读取目标。', 405 ); }
		foreach ( array( 'type', 'mode', 'id', 'term', 'paged' ) as $key ) { if ( isset( $input[ $key ] ) && ! is_string( $input[ $key ] ) && ! is_int( $input[ $key ] ) ) { return self::error( 'query', '链接查询条件无效。', 400 ); } }
		$announcement = isset( $input['announcement_ui'] ) || isset( $input['announcement_module'] ) || isset( $input['announcement_mode'] );
		if ( $announcement && ( '1' !== ( $input['announcement_ui'] ?? '' ) || 'announcement' !== ( $input['announcement_module'] ?? '' ) || 'reference' !== ( $input['announcement_mode'] ?? '' ) ) ) { return self::error( 'query', '公告链接选择条件无效。', 400 ); }
		$type = self::scalar( $input, 'type', 'route' ); $mode = self::scalar( $input, 'mode', 'list' );
		if ( ! in_array( $type, array_column( $announcement ? self::announcement_types() : self::types(), 'value' ), true ) || ! in_array( $mode, array( 'list', 'resolve' ), true ) ) { return self::error( 'type', '请选择可用的链接类型。', 400 ); }
		if ( 'resolve' === $mode ) {
			$id = self::scalar( $input, 'id' );
			if ( 'route' !== $type && ! preg_match( '/^[1-9][0-9]{0,17}$/D', $id ) ) { return self::error( 'id', '链接目标已不可用，请重新选择。', 404 ); }
			try { $row = self::read( static fn() => $announcement ? self::announcement_target( $type, $id ) : self::resolve( $type, $id ) ); } catch ( \Throwable $exception ) { return self::error( 'unavailable', '无法核验目标，请重试。', 503 ); }
			return $row ? array( 'target' => $row ) : self::error( 'missing', '目标已删除、下架或不公开，请重新选择。', 404 );
		}
		$term = self::scalar( $input, 'term' ); $page = self::scalar( $input, 'paged', '1' );
		if ( ! preg_match( '/^[1-9][0-9]{0,3}$/D', $page ) || (int) $page > 1000 || ! preg_match( '//u', $term ) || ( function_exists( 'mb_strlen' ) ? mb_strlen( $term, 'UTF-8' ) : strlen( $term ) ) > 100 || ( isset( $input['term'] ) && ! is_scalar( $input['term'] ) ) ) { return self::error( 'query', '搜索条件无效，请精简关键词后重试。', 400 ); }
		try { return $announcement ? self::announcement_listing( $type, sanitize_text_field( $term ), (int) $page ) : self::listing( $type, sanitize_text_field( $term ), (int) $page ); }
		catch ( \Throwable $exception ) { return self::error( 'unavailable', '链接读取失败，请重试。', 503 ); }
	}
	private static function listing( string $type, string $term, int $page ): array {
		if ( in_array( $type, array( 'promotion', 'coupon' ), true ) ) { return self::marketing_listing( $type, $term, $page ); }
		if ( 'route' === $type ) {
			$rows = array_values( array_filter( self::routes(), static fn( $row ) => '' === $term || false !== stripos( $row['label'], $term ) || false !== stripos( $row['id'], $term ) ) );
			return array( 'options' => array_slice( $rows, ( $page - 1 ) * 20, 20 ), 'more' => count( $rows ) > $page * 20 );
		}
		if ( 'category' === $type ) {
			$terms = self::read( static fn() => get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'search' => $term, 'number' => 21, 'offset' => ( $page - 1 ) * 20, 'orderby' => 'name', 'order' => 'ASC' ) ) );
			if ( is_wp_error( $terms ) ) { throw new \RuntimeException( 'terms' ); }
			$ids = array_map( static fn( $t ) => (string) $t->term_id, array_slice( $terms, 0, 20 ) ); $more = count( $terms ) > 20;
		} else {
			$args = array( 'post_type' => self::POSTS[ $type ], 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 21, 'offset' => ( $page - 1 ) * 20, 'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ), 'no_found_rows' => true, 's' => $term, 'search_columns' => array( 'post_title' ), 'ignore_sticky_posts' => true );
			if ( 'product' === $type && '' !== $term && function_exists( 'wc_get_product_id_by_sku' ) ) {
				$sku_id = (int) self::read( static fn() => wc_get_product_id_by_sku( $term ) );
				if ( $sku_id && 'product_variation' === get_post_type( $sku_id ) ) { $sku_id = (int) wp_get_post_parent_id( $sku_id ); }
				if ( $sku_id ) { $args['post__in'] = array( $sku_id ); $args['s'] = ''; }
			}
			$query = self::read( static fn() => new \WP_Query( $args ) );
			$ids = array_map( 'strval', wp_list_pluck( array_slice( $query->posts, 0, 20 ), 'ID' ) ); $more = count( $query->posts ) > 20;
		}
		$rows = array(); foreach ( $ids as $id ) { $row = self::read( static fn() => self::resolve( $type, $id ) ); if ( $row ) { $rows[] = $row; } }
		return array( 'options' => $rows, 'more' => $more && $page < 1000 );
	}
	private static function resolve( string $type, string $id ): ?array {
		if ( 'route' === $type ) { foreach ( self::routes() as $row ) { if ( $row['id'] === $id ) { return $row; } } return null; }
		if ( 'category' === $type ) {
			$term = get_term( (int) $id, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) { return null; }
			$url = get_term_link( $term );
			return is_wp_error( $url ) ? null : self::row( $type, $id, $term->name, $url );
		}
		if ( in_array( $type, array( 'promotion', 'coupon' ), true ) ) { return self::marketing_target( $type, $id ); }
		$post = get_post( (int) $id );
		if ( ! $post || $post->post_type !== self::POSTS[ $type ] || 'publish' !== $post->post_status || '' !== (string) $post->post_password || ! is_post_publicly_viewable( $post ) ) { return null; }
		if ( 'product' === $type ) { $item = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $id ) : null; if ( ! $item || ! $item->is_visible() || (int) $item->get_parent_id() ) { return null; } }
		return self::row( $type, $id, get_the_title( $post ), get_permalink( $post ) );
	}
	private static function routes(): array {
		$rows = array();
		$object = get_post_type_object( 'post' );
		if ( $object && $object->public ) {
			$url = get_post_type_archive_link( 'post' ); if ( $url ) { $row = self::row( 'route', 'blog_list', '博客列表', $url ); if ( $row ) { $rows[] = $row; } }
			if ( class_exists( '\PeptideCore\Blog' ) ) { $row = self::row( 'route', 'blog_collections', '博客专辑列表', \PeptideCore\Blog::collections_url() ); if ( $row ) { $rows[] = $row; } }
		}
		$topic_type = get_post_type_object( 'pc_topic' ); $topic_archive = $topic_type && $topic_type->public ? get_post_type_archive_link( 'pc_topic' ) : false;
		if ( $topic_archive ) { $row = self::row( 'route', 'topic_list', '专题列表', $topic_archive ); if ( $row ) { $rows[] = $row; } }
		$login = array( 'WordPress 登录（备用）', wp_login_url() );
		if ( function_exists( 'wc_get_page_id' ) && function_exists( 'wc_get_page_permalink' ) ) {
			$account_id = (int) wc_get_page_id( 'myaccount' );
			if ( $account_id > 0 && self::resolve( 'page', (string) $account_id ) ) {
				$account_url = Fields::url( (string) wc_get_page_permalink( 'myaccount' ) );
				if ( '' !== $account_url ) { $login = array( '买家登录', $account_url ); }
			}
		}
		foreach ( array( 'home' => array( '首页', home_url( '/' ) ), 'search' => array( '站内搜索', get_search_link( '' ) ), 'login' => $login ) as $id => $value ) { $row = self::row( 'route', $id, $value[0], $value[1] ); if ( $row ) { $rows[] = $row; } }
		if ( function_exists( 'wc_get_page_id' ) && function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( array( 'shop' => '商品列表', 'cart' => '购物车', 'checkout' => '结账', 'myaccount' => '我的账户' ) as $key => $label ) {
				$id = (int) wc_get_page_id( $key );
				if ( $id > 0 && self::resolve( 'page', (string) $id ) ) { $row = self::row( 'route', $key, $label, wc_get_page_permalink( $key ) ); if ( $row ) { $rows[] = $row; } }
			}
		}
		if ( class_exists( '\PeptideCore\Review_Index' ) ) { $row = self::row( 'route', 'reviews', '顾客评价', \PeptideCore\Review_Index::url() ); if ( $row ) { $rows[] = $row; } }
		foreach ( array( 'promotion' => '满减活动列表', 'coupon' => '优惠码列表' ) as $kind => $label ) { $url = self::marketing_page( $kind ); if ( '' !== $url ) { $row = self::row( 'route', $kind . '_list', $label, $url ); if ( $row ) { $rows[] = $row; } } }
		foreach ( self::shortcut_routes() as $row ) { $rows[] = $row; }
		return $rows;
	}
	/** Shortcuts only reuse existing public handlers; no page or endpoint is created. */
	private static function shortcut_routes(): array {
		$rows = array();
		if ( class_exists( '\PeptideCore\Design_Pages' ) && shortcode_exists( 'product_categories' ) ) {
			$row = self::row( 'route', 'collections', '商品专辑列表', \PeptideCore\Design_Pages::sample_url( 'collections' ) );
			if ( $row ) { $rows[] = $row; }
		}
		$account = function_exists( 'wc_get_page_id' ) ? self::shortcode_page( (int) wc_get_page_id( 'myaccount' ), 'woocommerce_my_account' ) : null;
		if ( $account ) {
			if ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration', 'no' ) ) {
				// reg_email is the required email input in Woo's existing registration form.
				$row = self::row( 'route', 'register', '买家注册', preg_replace( '/#.*$/', '', $account['url'] ) . '#reg_email' );
				if ( $row ) { $rows[] = $row; }
			}
			$endpoint = get_option( 'woocommerce_myaccount_lost_password_endpoint', '' );
			if ( is_string( $endpoint ) && '' !== trim( $endpoint ) && function_exists( 'wc_lostpassword_url' ) ) {
				$row = self::row( 'route', 'lost_password', '忘记密码', wc_lostpassword_url() );
				if ( $row ) { $rows[] = $row; }
			}
		}
		if ( shortcode_exists( 'woocommerce_order_tracking' ) ) {
			$ids = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'has_password' => false, 'fields' => 'ids', 's' => 'woocommerce_order_tracking', 'search_columns' => array( 'post_content' ), 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'ignore_sticky_posts' => true ) );
			foreach ( $ids as $id ) {
				$page = self::shortcode_page( (int) $id, 'woocommerce_order_tracking' );
				if ( $page ) { $row = self::row( 'route', 'order_tracking', '订单追踪查询', $page['url'] ); if ( $row ) { $rows[] = $row; break; } }
			}
		}
		return $rows;
	}
	/** A registered native shortcode must occur as executable text on a public page. */
	private static function shortcode_page( int $id, string $tag ): ?array {
		if ( $id < 1 || ! shortcode_exists( $tag ) ) { return null; }
		$page = self::resolve( 'page', (string) $id ); $post = $page ? get_post( $id ) : null;
		if ( ! $page || ! $post ) { return null; }
		$raw = ''; $raw_tags = array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE', 'XMP', 'IFRAME', 'NOEMBED', 'NOFRAMES', 'PLAINTEXT' );
		foreach ( wp_html_split( (string) $post->post_content ) as $position => $text ) {
			if ( '' === $text ) { continue; }
			// Odd chunks are regex captures, not necessarily markup: <3 and < foo are text.
			if ( 1 === $position % 2 && preg_match( '~^<(/?)([A-Za-z][^\t\n\f\r />]*)(?=[\t\n\f\r />]|$)~', $text, $element ) ) {
				$name = strtoupper( $element[2] ); $closing = '/' === $element[1];
				if ( '' !== $raw ) { if ( 'PLAINTEXT' !== $raw && $closing && $name === $raw ) { $raw = ''; } }
				elseif ( ! $closing && in_array( $name, $raw_tags, true ) ) { $raw = $name; }
				continue;
			}
			// Declarations, comments, CDATA, processing instructions and bogus closers are markup.
			if ( '' !== $raw || ( 1 === $position % 2 && preg_match( '~^<[!?/]~', $text ) ) || ! preg_match_all( '/' . get_shortcode_regex( array( $tag ) ) . '/s', $text, $matches, PREG_SET_ORDER ) ) { continue; }
			foreach ( $matches as $match ) { if ( '[' !== $match[1] || ']' !== $match[6] ) { return $page; } }
		}
		return null;
	}
	/** Only public marketing pages can supply link targets; lookup never creates pages. */
	private static function marketing_page( string $type ): string {
		$service = 'promotion' === $type ? '\PeptideCore\Promotions' : ( 'coupon' === $type ? '\PeptideCore\Coupons' : '' );
		if ( '' === $service || ! class_exists( $service ) ) { return ''; }
		$id = (int) $service::page_id();
		return $id > 0 && self::resolve( 'page', (string) $id ) ? Fields::url( $service::list_url() ) : '';
	}
	private static function marketing_listing( string $type, string $term, int $page ): array {
		if ( '' === self::marketing_page( $type ) ) { return array( 'options' => array(), 'more' => false ); }
		$args = array( 'post_type' => 'promotion' === $type ? \PeptideCore\Promotions::CPT : 'shop_coupon', 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 21, 'offset' => ( $page - 1 ) * 20, 'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ), 'no_found_rows' => true, 's' => $term, 'search_columns' => array( 'post_title' ), 'ignore_sticky_posts' => true );
		$query = self::read( static fn() => new \WP_Query( $args ) );
		$rows = array();
		foreach ( array_slice( $query->posts, 0, 20 ) as $post ) { $row = self::read( static fn() => self::marketing_target( $type, (string) $post->ID ) ); if ( $row ) { $rows[] = $row; } }
		return array( 'options' => $rows, 'more' => count( $query->posts ) > 20 && $page < 1000 );
	}
	private static function marketing_target( string $type, string $id ): ?array {
		$post = get_post( (int) $id );
		$expected = 'promotion' === $type ? \PeptideCore\Promotions::CPT : 'shop_coupon';
		if ( ! $post || $post->post_type !== $expected || 'publish' !== $post->post_status || '' !== (string) $post->post_password || '' === self::marketing_page( $type ) ) { return null; }
		if ( 'promotion' === $type ) {
			$config = \PeptideCore\Promotions::config( (int) $id );
			if ( empty( $config['public'] ) || 'all' !== ( $config['customer_rule']['customers'] ?? 'all' ) || ! in_array( \PeptideCore\Promotions::status( (int) $id ), array( 'active', 'upcoming' ), true ) ) { return null; }
			$view = \PeptideCore\Promotions::get_public( (int) $id );
			return $view ? self::row( $type, $id, get_the_title( $post ), \PeptideCore\Promotions::detail_url( (int) $id ) ) : null;
		}
		if ( ! class_exists( '\WC_Coupon' ) ) { return null; }
		$coupon = new \WC_Coupon( (int) $id );
		$rule = get_post_meta( (int) $id, \PeptideCore\Customer_Segments::COUPON_META, true );
		if ( ( is_array( $rule ) && 'all' !== ( $rule['customers'] ?? 'all' ) ) || ( ! empty( $rule ) && ! is_array( $rule ) ) ) { return null; }
		$view = \PeptideCore\Coupons::get_public( $coupon->get_code() );
		return $view && $coupon->get_id() === (int) $id ? self::row( $type, $id, strtoupper( $coupon->get_code() ), \PeptideCore\Coupons::detail_url( $coupon->get_code() ) ) : null;
	}


	/** Opt-in O21 public identity resolution. Stored href is never a binding fallback. */
	public static function announcement_target( string $type, string $id ): ?array {
		if ( ! in_array( $type, array_column( self::announcement_types(), 'value' ), true ) || ( 'route' !== $type && ! preg_match( '/^[1-9][0-9]{0,17}$/D', $id ) ) ) { return null; }
		return self::read( static function () use ( $type, $id ) {
			if ( 'variation' === $type ) {
				$product = Showcase_Quick_View::fixed_variation_product( $id );
				$parent = $product ? get_post( (int) $product->get_parent_id() ) : null;
				if ( ! $parent instanceof \WP_Post || 'product' !== $parent->post_type || 'publish' !== $parent->post_status || '' !== (string) $parent->post_password || ! is_post_publicly_viewable( $parent ) ) { return null; }
				return $product ? self::row( $type, $id, $product->get_name(), $product->get_permalink() ) : null;
			}
			if ( 'blog_category' === $type ) {
				$term = get_term( (int) $id, 'category' );
				if ( ! $term instanceof \WP_Term || Content_Blocks::blog_category_count( (int) $id ) < 1 ) { return null; }
				$url = get_term_link( $term ); return is_wp_error( $url ) ? null : self::row( $type, $id, $term->name, $url );
			}
			return self::resolve( $type, $id );
		} );
	}
	private static function announcement_types(): array {
		$types = self::types();
		if ( function_exists( 'wc_get_product' ) && class_exists( Showcase_Quick_View::class ) ) { $types[] = array( 'value' => 'variation', 'label' => '商品规格详情' ); }
		$taxonomy = get_taxonomy( 'category' ); $post = get_post_type_object( 'post' );
		if ( $taxonomy && $taxonomy->public && $post && $post->public && class_exists( Content_Blocks::class ) ) { $types[] = array( 'value' => 'blog_category', 'label' => '博客专辑详情' ); }
		return $types;
	}
	/** Eligibility precedes final LIMIT/OFFSET. No post-filtering the first20 candidates. */
	private static function announcement_listing( string $type, string $term, int $page ): array {
		if ( 'route' === $type ) { return self::listing( $type, $term, $page ); }
		return self::read( static function () use ( $type, $term, $page ) {
			global $wpdb; $taxonomy = 'blog_category' === $type ? 'category' : 'product_cat';
			$is_term = in_array( $type, array( 'category', 'blog_category' ), true ); $eligible = array();
			if ( $is_term ) {
				$candidates = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'search' => $term, 'number' => 0, 'orderby' => 'name', 'order' => 'ASC' ) );
				if ( is_wp_error( $candidates ) ) { throw new \RuntimeException( 'terms' ); }
				$ids = array_map( static fn( $t ) => (int) $t->term_id, $candidates );
			} else {
				$post_type = 'variation' === $type ? 'product_variation' : ( 'promotion' === $type ? \PeptideCore\Promotions::CPT : ( 'coupon' === $type ? 'shop_coupon' : self::POSTS[ $type ] ) );
				$args = array( 'post_type' => $post_type, 'post_status' => 'publish', 'has_password' => false, 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ), 's' => $term, 'search_columns' => array( 'post_title' ), 'no_found_rows' => true, 'ignore_sticky_posts' => true );
				if ( in_array( $type, array( 'product', 'variation' ), true ) && '' !== $term && function_exists( 'wc_get_product_id_by_sku' ) ) {
					$sku = (int) wc_get_product_id_by_sku( $term );
					if ( $sku && 'product' === $type && 'product_variation' === get_post_type( $sku ) ) { $sku = (int) wp_get_post_parent_id( $sku ); }
					if ( $sku ) { $args['post__in'] = array( $sku ); $args['s'] = ''; }
				}
				$query = new \WP_Query( $args ); $ids = array_map( 'intval', $query->posts );
			}
			foreach ( $ids as $id ) { if ( $id > 0 && self::announcement_target( $type, (string) $id ) ) { $eligible[ $id ] = $id; } }
			if ( ! $eligible ) { return array( 'options' => array(), 'more' => false ); }
			$placeholders = implode( ',', array_fill( 0, count( $eligible ), '%d' ) );
			if ( $is_term ) { $sql = $wpdb->prepare( "SELECT tt.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy=%s AND tt.term_id IN ($placeholders) ORDER BY t.name ASC, tt.term_id ASC LIMIT %d OFFSET %d", ...array_merge( array( $taxonomy ), array_values( $eligible ), array( 21, ( $page - 1 ) * 20 ) ) ); }
			else { $sql = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ($placeholders) ORDER BY post_title ASC, ID ASC LIMIT %d OFFSET %d", ...array_merge( array_values( $eligible ), array( 21, ( $page - 1 ) * 20 ) ) ); }
			$selected = array_map( 'strval', $wpdb->get_col( $sql ) ); $rows = array();
			foreach ( array_slice( $selected, 0, 20 ) as $id ) { $row = self::announcement_target( $type, $id ); if ( $row ) { $rows[] = $row; } }
			return array( 'options' => $rows, 'more' => count( $selected ) > 20 && $page < 1000 );
		} );
	}

	private static function row( string $type, string $id, string $label, $url ): ?array {
		$url = is_string( $url ) ? Fields::url( $url ) : '';
		return '' === $url ? null : array( 'key' => $type . ':' . $id, 'type' => $type, 'id' => $id, 'label' => wp_specialchars_decode( wp_strip_all_tags( $label ), ENT_QUOTES ), 'url' => $url );
	}
	/** WP DB errors are not exceptions; don't convert actual failed reads to fake empty results. */
	private static function read( callable $work ) {
		global $wpdb;
		$before = $wpdb->num_queries; $previous = $wpdb->suppress_errors( true );
		try { $result = $work(); if ( $wpdb->num_queries > $before && $wpdb->last_error ) { throw new \RuntimeException( 'lookup' ); } return $result; }
		finally { $wpdb->suppress_errors( $previous ); }
	}
	private static function scalar( array $input, string $key, string $default = '' ): string { return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : $default; }
	private static function error( string $code, string $message, int $status ): \WP_Error { return new \WP_Error( 'pc_link_' . $code, $message, array( 'status' => $status ) ); }
}
