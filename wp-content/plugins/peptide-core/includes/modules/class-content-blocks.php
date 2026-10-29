<?php
/** Blog and topic blocks keep their bindings to the store's real published records. */
namespace PeptideCore\Modules;

defined( 'ABSPATH' ) || exit;

final class Content_Blocks {

	public static function definitions( array $defs ): array {
		$common = array(
			Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
			Fields::f( 'textarea', 'description', __( 'Description', 'peptide-core' ), '' ),
			Fields::f( 'number', 'limit', __( 'Maximum items', 'peptide-core' ), 8, array( 'min' => 1, 'max' => 24 ) ),
			Fields::f( 'number', 'columns', __( 'Items per row', 'peptide-core' ), array( 'desktop' => 4, 'mobile' => 2 ), array( 'device' => true, 'min' => 1, 'max' => 6 ) ),
		);
		$category = Fields::f( 'record', 'blogCategory', __( 'Blog collection', 'peptide-core' ), null, array( 'resource' => 'blog_category', 'help' => __( 'Leave empty to use all blog collections. A selected collection limits the results.', 'peptide-core' ) ) );
		$posts = Fields::f( 'repeater', 'posts', __( 'Choose blog posts', 'peptide-core' ), null, array(
			'max' => 24,
			'help' => __( 'Leave this list empty for automatic posts. A configured list shows only the selected published posts.', 'peptide-core' ),
			'fields' => array( Fields::f( 'record', 'post', __( 'Blog post', 'peptide-core' ), null, array( 'resource' => 'post' ) ) ),
		) );
		foreach ( array( 'blog_posts' => __( 'Selected blog posts', 'peptide-core' ), 'category_blog' => __( 'Blog collection posts', 'peptide-core' ), 'blog_categories' => __( 'Blog collections', 'peptide-core' ), 'campaign_pages' => __( 'Campaign pages', 'peptide-core' ) ) as $type => $title ) {
			$defs[ $type ] = array_merge( $defs[ $type ] ?? array(), array( 'title' => $title, 'category' => 'operation', 'icon' => 'admin-post', 'settings' => $common ) );
			// These four types have settings-level bindings, with no module-level item editor.
			unset( $defs[ $type ]['items'] );
			if ( 'campaign_pages' !== $type ) { $defs[ $type ]['settings'][] = $category; }
			if ( in_array( $type, array( 'blog_posts', 'category_blog' ), true ) ) { $defs[ $type ]['settings'][] = $posts; }
		}
		$defs['campaign_pages']['settings'][] = Fields::f( 'select', 'source', __( 'Campaign page source', 'peptide-core' ), 'all', array( 'options' => array( 'all' => __( 'All published campaign pages', 'peptide-core' ), 'manual' => __( 'Choose campaign pages', 'peptide-core' ) ) ) );
		$defs['campaign_pages']['settings'][] = Fields::f( 'repeater', 'pages', __( 'Choose campaign pages', 'peptide-core' ), null, array(
			'max' => 24, 'show_if' => array( 'source' => 'manual' ),
			'fields' => array( Fields::f( 'record', 'page', __( 'Campaign page', 'peptide-core' ), null, array( 'resource' => 'topic' ) ) ),
		) );
		return $defs;
	}

	/** Return only the inner block content; Commerce_Blocks supplies the shared heading and wrapper. */
	public static function render( string $type, array $s, array $items = array() ): string {
		if ( ! in_array( $type, array( 'blog_posts', 'category_blog', 'blog_categories', 'campaign_pages' ), true ) ) { return ''; }
		$limit = max( 1, min( 24, (int) ( $s['limit'] ?? 8 ) ) );
		if ( 'blog_categories' === $type ) { return self::categories( $s, $limit ); }
		$post_type = 'campaign_pages' === $type ? 'pc_topic' : 'post';
		$args = array( 'post_type' => $post_type, 'post_status' => 'publish', 'has_password' => false, 'numberposts' => $limit, 'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ) );
		if ( 'post' === $post_type && self::has_category_binding( $s ) ) {
			$category = get_term( self::record_id( $s['blogCategory'] ), 'category' );
			if ( ! $category instanceof \WP_Term ) { return ''; }
			$args['category'] = $category->term_id;
		}
		$manual = 'pc_topic' === $post_type ? 'manual' === ( $s['source'] ?? 'all' ) : ! empty( $s['posts'] );
		if ( $manual ) {
			$ids = self::selected_ids( (array) ( $s[ 'pc_topic' === $post_type ? 'pages' : 'posts' ] ?? array() ), 'pc_topic' === $post_type ? 'page' : 'post' );
			// Empty or stale explicit choices must not become an unfiltered query.
			if ( ! $ids ) { return ''; }
			// get_posts() rewrites its "include" argument's limit; post__in preserves the block's limit.
			$args['post__in'] = $ids; $args['orderby'] = 'post__in';
		}
		$html = '';
		foreach ( get_posts( $args ) as $post ) { $html .= self::post_card( $post, $post_type ); }
		return '' !== $html ? self::grid( $s ) . $html . '</div>' : '';
	}

	private static function record_id( $value ): int { return is_scalar( $value ) ? absint( $value ) : 0; }

	private static function has_category_binding( array $s ): bool {
		return array_key_exists( 'blogCategory', $s ) && ! in_array( $s['blogCategory'], array( null, '', 0, '0', false ), true );
	}

	private static function selected_ids( array $rows, string $key ): array {
		$ids = array();
		foreach ( array_slice( $rows, 0, 24 ) as $row ) {
			if ( ! is_array( $row ) || ( array_key_exists( 'enabled', $row ) && ! $row['enabled'] ) ) { continue; }
			$id = self::record_id( $row['settings'][ $key ] ?? 0 );
			if ( $id ) { $ids[] = $id; }
		}
		return array_values( array_unique( $ids ) );
	}

	private static function categories( array $s, int $limit ): string {
		if ( self::has_category_binding( $s ) ) {
			$term = get_term( self::record_id( $s['blogCategory'] ), 'category' );
			$terms = $term instanceof \WP_Term ? array( $term ) : array();
		} else { $terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => true, 'number' => $limit, 'orderby' => 'name', 'order' => 'ASC' ) ); }
		if ( is_wp_error( $terms ) || ! $terms ) { return ''; }
		$html = '';
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) { continue; }
			$url = get_term_link( $term );
			if ( ! is_wp_error( $url ) ) { $html .= '<a class="pc-cb-card" href="' . esc_url( $url ) . '"><h3>' . esc_html( $term->name ) . '</h3></a>'; }
		}
		return '' !== $html ? self::grid( $s ) . $html . '</div>' : '';
	}

	private static function post_card( \WP_Post $post, string $type ): string {
		if ( $type !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) { return ''; }
		return '<article class="pc-cb-card"><a href="' . esc_url( get_permalink( $post->ID ) ) . '">' . get_the_post_thumbnail( $post->ID, 'medium' ) . '<h3>' . esc_html( get_the_title( $post->ID ) ) . '</h3></a><p>' . esc_html( wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 30 ) ) . '</p></article>';
	}

	private static function grid( array $s ): string {
		$columns = is_array( $s['columns'] ?? null ) ? $s['columns'] : array( 'desktop' => 4, 'mobile' => 2 );
		$desktop = max( 1, min( 6, (int) ( $columns['desktop'] ?? 4 ) ) ); $mobile = max( 1, min( 6, (int) ( $columns['mobile'] ?? $desktop ) ) );
		return '<div class="pc-cb-grid" style="--pc-cb-cols:' . $desktop . ';--pc-cb-cols-m:' . $mobile . '">';
	}
}
