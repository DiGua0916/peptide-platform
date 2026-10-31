<?php
/**
 * Page layouts reuse the existing revision, preview and published snapshot system.
 * Native commerce/account content remains a single, server-rendered module.
 *
 * @package PeptideCore
 */
namespace PeptideCore;

use PeptideCore\Modules\Layout;
use PeptideCore\Modules\Registry;

defined( 'ABSPATH' ) || exit;

final class Design_Pages {
	public const PREVIEW_DOC = 'pc_design_document';
	private static string $native_template = '';
	private static string $active_document = 'home';
	private static int $native_depth = 0;
	private static ?string $native_html = null;

	public static function register_hooks(): void {
		add_filter( 'peptide_core_template_documents', array( self::class, 'documents' ) );
		add_filter( 'peptide_core_layout_document_label', array( self::class, 'document_label' ), 10, 3 );
		add_action( 'admin_post_pc_design_open', array( self::class, 'open_document' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_action( 'add_meta_boxes', array( self::class, 'meta_boxes' ) );
		add_filter( 'query_vars', static function( $vars ) { $vars[] = 'pc_collections'; return $vars; } );
		add_action( 'pre_get_posts', static function( $query ) {
			if ( ! is_admin() && $query->is_main_query() && '1' === (string) $query->get( 'pc_collections' ) ) {
				$query->is_home = false; $query->is_page = false; $query->is_archive = true; $query->is_404 = false;
				$query->set( 'post_type', 'product' ); $query->set( 'posts_per_page', 1 );
			}
		} );
		add_filter( 'pre_get_document_title', static function( $title ) { return get_query_var( 'pc_collections' ) ? __( 'Collections', 'peptide-core' ) . ' | ' . get_bloginfo( 'name' ) : $title; }, 30 );
		add_action( 'init', static function() {
			if ( ! get_page_by_path( 'collections' ) ) {
				add_rewrite_rule( '^collections/?$', 'index.php?pc_collections=1', 'top' );
			}
		} );
	}

	public static function documents( array $docs ): array {
		return $docs + array(
			'product' => __( 'Product details', 'peptide-core' ),
			'shop' => __( 'Product catalogue', 'peptide-core' ),
			'category' => __( 'Collection details', 'peptide-core' ),
			'collections' => __( 'Collection list', 'peptide-core' ),
			'topic' => __( 'Campaign page', 'peptide-core' ),
			'custom' => __( 'Custom page', 'peptide-core' ),
			'search' => __( 'Search results', 'peptide-core' ),
			'coupon' => __( 'Coupon page', 'peptide-core' ),
			'promotion' => __( 'Promotion page', 'peptide-core' ),
			'blog' => __( 'Blog list', 'peptide-core' ),
			'article' => __( 'Blog article', 'peptide-core' ),
			'account' => __( 'Customer account', 'peptide-core' ),
			'not_found' => __( 'Page not found', 'peptide-core' ),
		);
	}

	public static function document_label( $label, string $doc, int $template_id = 0 ): string {
		if ( preg_match( '/^(product|custom|topic|article|category)_([1-9][0-9]*)$/D', $doc, $m ) ) {
			$id = (int) $m[2];
			if ( 'category' === $m[1] ) {
				$term = get_term( $id, 'product_cat' );
				return $term && ! is_wp_error( $term ) ? __( 'Collection', 'peptide-core' ) . ': ' . $term->name : '';
			}
			$type = array( 'product' => 'product', 'custom' => 'page', 'topic' => 'pc_topic', 'article' => 'post' )[ $m[1] ];
			$post = get_post( $id );
			if ( 'custom' === $m[1] && in_array( $id, self::protected_pages(), true ) ) { return ''; }
			return $post && $type === $post->post_type && 'trash' !== $post->post_status ? get_the_title( $post ) : '';
		}
		return is_string( $label ) ? $label : '';
	}

	public static function label( string $doc ): string {
		return Templates::documents()[ $doc ] ?? self::document_label( '', $doc );
	}

	private static function protected_pages(): array {
		return array_filter( array( (int) get_option( 'woocommerce_cart_page_id' ), (int) get_option( 'woocommerce_checkout_page_id' ) ) );
	}

	public static function open_url( int $template_id, string $doc ): string {
		$id = Templates::document_id( $template_id, $doc, false );
		if ( $id ) { return Admin\Design_Editor::url( $id ); }
		return wp_nonce_url( add_query_arg( array(
			'action' => 'pc_design_open', 'template' => $template_id, 'document' => $doc,
		), admin_url( 'admin-post.php' ) ), 'pc_design_open_' . $template_id );
	}

	public static function open_document(): void {
		$template = absint( $_GET['template'] ?? 0 );
		$doc = sanitize_key( wp_unslash( $_GET['document'] ?? '' ) );
		if ( ! current_user_can( Templates::CAP_EDIT ) || ! current_user_can( 'edit_post', $template ) ) {
			wp_die( esc_html__( 'You cannot edit this design.', 'peptide-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pc_design_open_' . $template );
		if ( ! self::can_target( $doc ) ) {
			wp_die( esc_html__( 'You cannot edit this page.', 'peptide-core' ), '', array( 'response' => 403 ) );
		}
		$id = Templates::document_id( $template, $doc );
		if ( ! $id ) {
			wp_die( esc_html__( 'Design document not found.', 'peptide-core' ), '', array( 'response' => 404 ) );
		}
		self::seed( $id, $doc );
		wp_safe_redirect( Admin\Design_Editor::url( $id ) );
		exit;
	}

	public static function can_target( string $doc ): bool {
		if ( isset( Templates::documents()[ $doc ] ) ) { return true; }
		if ( '' === self::document_label( '', $doc ) ) { return false; }
		preg_match( '/^([a-z]+)_([0-9]+)$/', $doc, $m );
		return 'category' === $m[1] ? current_user_can( 'manage_product_terms' ) : current_user_can( 'edit_post', (int) $m[2] );
	}

	public static function seed( int $id, string $doc ): void {
		if ( '' !== trim( (string) get_post_field( 'post_content', $id ) ) || get_post_meta( $id, '_pc_design_seeded', true ) ) { return; }
		$type = array( 'header' => 'site_header', 'footer' => 'site_footer', 'global' => 'site_styles' )[ $doc ] ?? 'context_content';
		if ( 'home' === $doc || ! Registry::get( $type ) ) { return; }
		$rows = Layout::clean_modules( array( array( 'block' => 'vialcove/' . str_replace( '_', '-', $type ), 'attrs' => array() ) ) );
		wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( Layout::content_from_modules( $rows ) ) ) );
		update_post_meta( $id, '_pc_design_seeded', 1 );
	}

	public static function row_actions( array $actions, \WP_Post $post ): array {
		$kind = array( 'page' => 'custom', 'pc_topic' => 'topic', 'product' => 'product', 'post' => 'article' )[ $post->post_type ] ?? '';
		if ( 'custom' === $kind && in_array( $post->ID, self::protected_pages(), true ) ) { return $actions; }
		if ( $kind && current_user_can( Templates::CAP_EDIT ) && current_user_can( 'edit_post', $post->ID ) ) {
			$template = Templates::default_id( false );
			if ( $template ) { $actions['pc_design'] = '<a href="' . esc_url( self::open_url( $template, $kind . '_' . $post->ID ) ) . '">' . esc_html__( 'Design this page', 'peptide-core' ) . '</a>'; }
		}
		return $actions;
	}

	public static function meta_boxes(): void {
		foreach ( array( 'page', 'pc_topic', 'product', 'post' ) as $type ) {
			add_meta_box( 'pc-page-design', __( 'Page design', 'peptide-core' ), static function( $post ) {
				$actions = self::row_actions( array(), $post );
				echo isset( $actions['pc_design'] ) ? wp_kses_post( $actions['pc_design'] ) : esc_html__( 'Save this page before opening its design.', 'peptide-core' );
			}, $type, 'side' );
		}
	}

	public static function sample_url( string $doc ): string {
		if ( preg_match( '/^(product|custom|topic|article)_([0-9]+)$/', $doc, $m ) ) {
			$id = (int) $m[2];
			return 'publish' === get_post_status( $id ) ? get_permalink( $id ) : get_preview_post_link( $id );
		}
		if ( preg_match( '/^category_([0-9]+)$/', $doc, $m ) ) {
			$link = get_term_link( (int) $m[1], 'product_cat' );
			return is_wp_error( $link ) ? home_url( '/' ) : $link;
		}
		if ( 'search' === $doc ) { return add_query_arg( 's', 'peptide', home_url( '/' ) ); }
		if ( 'not_found' === $doc ) { return home_url( '/pc-design-missing-page/' ); }
		if ( 'collections' === $doc ) { return add_query_arg( 'pc_collections', '1', home_url( '/' ) ); }
		if ( 'account' === $doc && function_exists( 'wc_get_page_permalink' ) ) { return wc_get_page_permalink( 'myaccount' ); }
		if ( in_array( $doc, array( 'shop', 'category' ), true ) && function_exists( 'wc_get_page_permalink' ) ) {
			if ( 'category' === $doc ) {
				$terms = get_terms( array( 'taxonomy' => 'product_cat', 'number' => 1, 'hide_empty' => false ) );
				if ( ! is_wp_error( $terms ) && $terms ) { return get_term_link( $terms[0] ); }
			}
			return wc_get_page_permalink( 'shop' );
		}
		$type = array( 'product' => 'product', 'topic' => 'pc_topic', 'article' => 'post', 'custom' => 'page' )[ $doc ] ?? '';
		if ( $type ) {
			$ids = get_posts( array( 'post_type' => $type, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'post__not_in' => self::protected_pages() ) );
			if ( $ids ) { return get_permalink( $ids[0] ); }
		}
		if ( 'blog' === $doc ) { return get_post_type_archive_link( 'post' ) ?: home_url( '/blogs/' ); }
		if ( in_array( $doc, array( 'coupon', 'promotion' ), true ) ) {
			$shortcode = 'coupon' === $doc ? 'pc_coupons' : 'pc_promotions';
			$ids = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 50 ) );
			foreach ( $ids as $page ) { if ( has_shortcode( $page->post_content, $shortcode ) ) { return get_permalink( $page ); } }
		}
		return home_url( '/' );
	}

	public static function preview_url( int $template, string $doc ): string {
		$args = array();
		parse_str( (string) wp_parse_url( Templates::preview_url( $template, true ), PHP_URL_QUERY ), $args );
		return add_query_arg( array_merge( $args, array( self::PREVIEW_DOC => $doc ) ), self::sample_url( $doc ) );
	}

	private static function usable( string $doc ): bool {
		$template = Templates::current_id();
		$id = Templates::document_id( $template, $doc, false );
		if ( ! $id ) { return false; }
		if ( Templates::previewing_id() ) {
			$draft = Layout::preview_draft( $template, $doc );
			return $draft ? (bool) $draft['modules'] : '' !== trim( (string) get_post_field( 'post_content', $id ) );
		}
		return (bool) Layout::published_modules( $doc );
	}

	public static function route(): string {
		if ( function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() ) ) { return ''; }
		if ( Templates::previewing_id() && isset( $_GET[ self::PREVIEW_DOC ] ) ) {
			$doc = sanitize_key( wp_unslash( $_GET[ self::PREVIEW_DOC ] ) );
			if ( self::label( $doc ) && ! in_array( $doc, array( 'header', 'footer', 'global' ), true ) ) { return $doc; }
			return 'home';
		}
		if ( get_query_var( 'pc_collections' ) ) { return 'collections'; }
		if ( is_front_page() ) { return 'home'; }
		if ( is_search() ) { return 'search'; }
		if ( is_404() ) { return 'not_found'; }
		$id = get_queried_object_id();
		$candidates = array();
		if ( is_singular( 'product' ) ) { $candidates = array( 'product_' . $id, 'product' ); }
		elseif ( is_tax( 'product_cat' ) ) { $candidates = array( 'category_' . $id, 'category' ); }
		elseif ( function_exists( 'is_shop' ) && is_shop() ) { return 'shop'; }
		elseif ( is_singular( 'pc_topic' ) ) { $candidates = array( 'topic_' . $id, 'topic' ); }
		elseif ( function_exists( 'is_account_page' ) && is_account_page() ) { return 'account'; }
		elseif ( is_singular( 'page' ) ) {
			$content = (string) get_post_field( 'post_content', $id );
			if ( has_shortcode( $content, 'pc_coupons' ) ) { return 'coupon'; }
			if ( has_shortcode( $content, 'pc_promotions' ) ) { return 'promotion'; }
			if ( has_shortcode( $content, 'product_categories' ) ) { return 'collections'; }
			$candidates = array( 'custom_' . $id, 'custom' );
		}
		elseif ( is_singular( 'post' ) ) { $candidates = array( 'article_' . $id, 'article' ); }
		elseif ( is_home() || is_category() || is_tag() || is_post_type_archive( 'post' ) ) { return 'blog'; }
		foreach ( $candidates as $doc ) { if ( self::usable( $doc ) ) { return $doc; } }
		return $candidates ? (string) end( $candidates ) : '';
	}

	public static function template_page( string $template ): string {
		if ( self::$native_depth > 0 ) { return $template; }
		self::$native_template = $template;
		self::$native_html = null;
		$doc = self::route();
		if ( 'collections' === $doc && get_query_var( 'pc_collections' ) && ! self::usable( $doc ) ) {
			status_header( 200 );
			return PEPTIDE_CORE_DIR . '/templates/design-collections.php';
		}
		if ( '' === $doc || ! self::usable( $doc ) ) { return $template; }
		self::$active_document = $doc;
		if ( 'collections' === $doc && get_query_var( 'pc_collections' ) ) { status_header( 200 ); }
		return PEPTIDE_CORE_DIR . '/templates/design-page.php';
	}

	public static function active_document(): string { return self::$active_document; }
	public static function rendering_native(): bool { return self::$native_depth > 0; }

	/** Native templates call get_footer(), which uses require_once even if its guard returned early. */
	public static function footer(): void {
		$path = locate_template( array( 'footer.php' ), false, false );
		if ( ! $path ) { get_footer(); return; }
		do_action( 'get_footer', null, array() );
		load_template( $path, false, array() );
	}

	public static function native_content(): string {
		if ( null !== self::$native_html ) { return self::$native_html; }
		if ( 'collections' === self::$active_document ) { return do_shortcode( '[product_categories]' ); }
		if ( 'coupon' === self::$active_document && ! is_singular( 'page' ) ) { return do_shortcode( '[pc_coupons]' ); }
		if ( 'promotion' === self::$active_document && ! is_singular( 'page' ) ) { return do_shortcode( '[pc_promotions]' ); }
		$base = preg_replace( '/_[0-9]+$/', '', self::$active_document );
		$sample_types = array( 'product' => 'product', 'custom' => 'page', 'topic' => 'pc_topic', 'article' => 'post' );
		if ( isset( $sample_types[ $base ] ) && ! is_singular( $sample_types[ $base ] ) ) {
			return '<div class="vc-m__notice">' . esc_html__( 'Create and publish a page of this type to preview its original content. You can configure the surrounding blocks now.', 'peptide-core' ) . '</div>';
		}
		if ( ! self::$native_template || ! is_file( self::$native_template ) ) { return ''; }
		global $wp_query, $post, $product, $wp_filter;
		$original_query = $wp_query ? clone $wp_query : null;
		$query_object = $wp_query;
		$original_post = $post;
		$original_product = $product;
		$native_hooks = array();
		foreach ( array( 'get_header', 'get_footer' ) as $tag ) {
			$native_hooks[ $tag ] = $wp_filter[ $tag ] ?? null;
			unset( $wp_filter[ $tag ] );
		}
		++self::$native_depth;
		ob_start();
		try {
			include self::$native_template;
			self::$native_html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
			--self::$native_depth;
			foreach ( $native_hooks as $tag => $original_hook ) {
				$added_hook = $wp_filter[ $tag ] ?? null;
				unset( $wp_filter[ $tag ] );
				if ( $original_hook ) { $wp_filter[ $tag ] = $original_hook; }
				if ( $added_hook ) {
					foreach ( $added_hook->callbacks as $priority => $callbacks ) {
						foreach ( $callbacks as $callback ) { add_action( $tag, $callback['function'], $priority, $callback['accepted_args'] ); }
					}
				}
			}
			if ( $original_query ) {
				foreach ( get_object_vars( $original_query ) as $key => $value ) { $query_object->$key = $value; }
				$wp_query = $query_object;
			}
			$post = $original_post;
			$product = $original_product;
			if ( $post ) { setup_postdata( $post ); }
		}
		return self::$native_html ?? '';
	}

	public static function validate_publish( int $document ): ?\WP_Error {
		$doc = (string) get_post_meta( $document, Templates::DOC_META, true );
		$rows = Layout::modules_from_content( (string) get_post_field( 'post_content', $document ) );
		if ( in_array( $doc, array( 'header', 'footer', 'global' ), true ) && array_filter( $rows, static fn( $row ) => 'vialcove/context-content' === $row['block'] ) ) {
			return new \WP_Error( 'pc_layout_native_scope', __( 'Original page content belongs in the page layout, not in the header, footer or global settings.', 'peptide-core' ), array( 'status' => 400 ) );
		}
		foreach ( array( 'vialcove/context-content', 'vialcove/site-header', 'vialcove/site-footer', 'vialcove/site-styles' ) as $block ) {
			if ( count( array_filter( $rows, static fn( $row ) => $row['block'] === $block ) ) > 1 ) {
				return new \WP_Error( 'pc_layout_duplicate_part', __( 'This block can only appear once on a page.', 'peptide-core' ), array( 'status' => 400 ) );
			}
		}
		if ( ! ( 'account' === $doc || preg_match( '/^product(?:_[0-9]+)?$/D', $doc ) ) ) { return null; }
		foreach ( $rows as $row ) {
			if ( 'vialcove/context-content' === $row['block'] && false !== ( $row['attrs']['enabled'] ?? true )
				&& false !== ( $row['attrs']['settings']['showDesktop'] ?? true ) && false !== ( $row['attrs']['settings']['showMobile'] ?? true ) ) { return null; }
		}
		return new \WP_Error( 'pc_layout_native_required', __( 'Keep the original page content block visible on both devices so purchasing and account actions remain available.', 'peptide-core' ), array( 'status' => 400 ) );
	}
}
