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
		return self::reference_definitions( $defs );
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

	/** O08/O09 opt in. Existing saved fields and automatic legacy queries are untouched. */
	private static function reference_definitions( array $defs ): array {
		foreach ( array( 'blog_posts' => 'Blog', 'blog_categories' => 'News', 'category_blog' => 'Featured Blogs', 'campaign_pages' => 'Topic' ) as $type => $title ) {
			$mode_key = 'campaign_pages' === $type ? 'campaignPagesMode' : ( 'category_blog' === $type ? 'categoryBlogMode' : 'blogMode' );
			foreach ( $defs[ $type ]['settings'] as &$field ) { if ( 'title' !== $field['key'] ) { $field['show_if'] = array_merge( $field['show_if'] ?? array(), array( $mode_key => 'legacy' ) ); } } unset( $field );
			$global = array( 'group' => 'general', 'panel' => 'content', 'show_if' => array( $mode_key => 'reference' ) );
			$fields = array(
				Fields::f( 'select', $mode_key, '博客展示方式', 'legacy', array( 'group' => 'general', 'panel' => 'content', 'options' => array( 'legacy' => '原有展示', 'reference' => '博客卡片' ) ) ),
				Fields::f( 'html', 'descriptionHtml', '简短描述', null, $global + array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/' . str_replace( '_', '-', $type ) ) ),
				Fields::f( 'toggle', 'blogFullWidth', '宽度铺满', false, $global ),
				Fields::f( 'toggle', 'blogShowCover', '显示封面图片', true, $global ),
				Fields::f( 'toggle', 'blogShowExcerpt', '显示简短描述', true, $global ),
				Fields::f( 'select', 'blogAlign', '文字对齐方式', 'center', $global + array( 'options' => array( 'left' => '左对齐', 'center' => '居中', 'right' => '右对齐' ) ) ),
				Fields::f( 'number', 'blogColumns', '每排数量（仅电脑）', 3, $global + array( 'ui' => 'range', 'min' => 2, 'max' => 'campaign_pages' === $type ? 6 : 5 ) ),
				Fields::f( 'text', 'blogReadMore', '阅读全文文案', 'Read More >', $global ),
				Fields::f( 'text', 'blogViewMore', '查看更多文案', 'View More', $global ),
			);
			if ( in_array( $type, array( 'blog_posts', 'category_blog' ), true ) ) {
				$fields[] = Fields::f( 'toggle', 'blogShowDate', '显示日期', true, $global );
				$fields[] = Fields::f( 'toggle', 'blogShowAuthor', '显示作者', 'category_blog' === $type, $global );
			}
			if ( 'blog_posts' === $type ) {
				$fields[] = Fields::f( 'url', 'blogMoreUrl', '查看更多链接', null, $global + array( 'ui' => 'site-link', 'help' => '未编辑时使用博客列表；清空后不显示查看更多。' ) );
			}
			foreach ( array( 'blogEffect' => array( 'select', '按钮特效', 'none', array( 'options' => array( 'none' => '无特效', 'bounce' => '跳动', 'rubberband' => '橡皮筋', 'shake' => '左右抖动', 'swing' => '摇摆', 'wobble' => '摇晃' ) ) ), 'blogDurationMs' => array( 'number', '按钮动画时间', 1000, array( 'ui' => 'seconds', 'unit' => 'ms', 'min' => 1000, 'max' => 5000, 'step' => 100 ) ), 'blogIntervalMs' => array( 'number', '按钮动画间隔时间', 1500, array( 'ui' => 'seconds', 'unit' => 'ms', 'min' => 500, 'max' => 5000, 'step' => 100 ) ) ) as $key => $f ) { $fields[] = Fields::f( $f[0], $key, $f[1], $f[2], array_merge( $global, $f[3], array( 'panel' => 'button' ) ) ); }
			$resource = 'campaign_pages' === $type ? 'topic' : ( 'blog_posts' === $type ? 'post' : 'blog_category' );
			if ( 'category_blog' === $type ) {
				$fields[] = Fields::f( 'record', 'categoryBlogCategory', '选择博客专辑', null, $global + array( 'ui' => 'blog-category-one', 'blogRecords' => true, 'blogModule' => $type, 'resource' => 'blog_category' ) );
				$fields[] = Fields::f( 'number', 'categoryBlogCount', '博客总数量', 3, $global + array( 'min' => 1, 'max' => 100, 'ui' => 'range' ) );
			} else {
			$fields[] = Fields::f( 'repeater', 'blogEntries', 'campaign_pages' === $type ? '选择专题' : ( 'blog_posts' === $type ? '博客' : '选择专辑' ), null, $global + array( 'ui' => 'blog-records', 'blogRecords' => true, 'blogModule' => $type, 'max' => 24, 'fields' => array( Fields::f( 'record', 'entry', 'campaign_pages' === $type ? '专题' : ( 'blog_posts' === $type ? '博客' : '博客专辑' ), null, array( 'resource' => $resource ) ) ) ) );
			}
			$defs[ $type ]['settings'] = array_merge( $defs[ $type ]['settings'], $fields );
			$defs[ $type ]['editorDefaults'] = array( $mode_key => 'reference', 'title' => $title );
			if ( 'category_blog' !== $type ) { $defs[ $type ]['blogFactoryRows'] = 3; }
			$settings_keys = array( 'categoryBlogCategory', 'title', 'descriptionHtml', 'blogFullWidth', 'blogShowDate', 'blogShowAuthor', 'blogShowCover', 'blogShowExcerpt', 'blogAlign', 'blogColumns', 'categoryBlogCount', 'blogReadMore', 'blogViewMore', 'blogMoreUrl', 'description', 'limit', 'columns', 'blogCategory', 'posts', 'source', 'pages' );
			$defs[ $type ]['panels'] = array(
				array( 'id' => 'settings', 'label' => '设置', 'initialOpen' => true, 'fields' => $settings_keys ),
				array( 'id' => 'button', 'label' => '按钮特效设置', 'initialOpen' => false, 'fields' => array( 'blogEffect', 'blogDurationMs', 'blogIntervalMs' ) ),
			);
			if ( 'category_blog' !== $type ) { $defs[ $type ]['panels'][] = array( 'id' => 'entries', 'label' => 'blog_posts' === $type ? '博客' : '内容', 'initialOpen' => true, 'fields' => array( 'blogEntries' ) ); }
			$defs[ $type ]['panels'][] = array( 'id' => 'compatibility', 'label' => '兼容设置', 'initialOpen' => false, 'fields' => array( $mode_key ) );
		}
		return $defs;
	}

	public static function blog_assets(): void {
		$base = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		wp_enqueue_style( 'pc-commerce-blocks', $base . 'commerce-blocks.css', array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/commerce-blocks.css' ) );
		wp_enqueue_script( 'pc-commerce-blocks', $base . 'commerce-blocks.js', array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/commerce-blocks.js' ), true );
	}
	public static function blog_preview( string $context ): bool {
		return ( 'editor' === $context && Renderer::$in_editor ) || ( 'preview' === $context && \PeptideCore\Templates::previewing_id() > 0 );
	}
	public static function blog_allowed( string $context ): bool {
		return self::blog_preview( $context ) || ! \PeptideCore\Store_Config::get( 'force_login' ) || is_user_logged_in();
	}
	private static function blog_public_post( $post, string $expected = 'post' ): bool {
		return $post instanceof \WP_Post && $expected === $post->post_type && 'publish' === $post->post_status && '' === (string) $post->post_password && is_post_publicly_viewable( $post );
	}
	/** Reference covers keep explicit publication intent, but never republish private or review-owned media. */
	private static function blog_cover_allowed( int $id ): bool {
		$image = $id > 0 ? get_post( $id ) : null;
		if ( ! $image instanceof \WP_Post || 'attachment' !== $image->post_type || ! in_array( $image->post_status, array( 'inherit', 'publish' ), true ) || '' !== (string) $image->post_password || metadata_exists( 'post', $id, '_pc_review_owner' ) || metadata_exists( 'post', $id, '_pc_review_file' ) || ! wp_attachment_is_image( $id ) ) { return false; }
		if ( $image->post_parent ) {
			$parent = get_post( (int) $image->post_parent );
			if ( $parent instanceof \WP_Post && ( 'private' === $parent->post_status || '' !== (string) $parent->post_password ) ) { return false; }
		}
		return true;
	}

	/** Metadata for the editor's existing authenticated, nonce-protected category resource only. */
	public static function blog_category_count( int $id ): int {
		$q = new \WP_Query( array( 'post_type' => 'post', 'post_status' => 'publish', 'has_password' => false, 'cat' => $id, 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => false ) );
		return max( 0, (int) $q->found_posts );
	}
	private static function blog_term( int $id ): ?array {
		$term = get_term( $id, 'category' ); if ( ! $term instanceof \WP_Term ) { return null; }
		$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'has_password' => false, 'category' => $id, 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
		if ( ! $posts || ! self::blog_public_post( $posts[0] ) ) { return null; }
		$url = get_term_link( $term ); if ( is_wp_error( $url ) ) { return null; }
		return array( 'title' => $term->name, 'excerpt' => wp_strip_all_tags( $term->description ), 'url' => $url, 'cover' => (int) get_term_meta( $id, \PeptideCore\Blog::TERM_COVER, true ) );
	}
	/** Prepared membership read, native category ancestors, then ordered pagination of the complete eligible set. */
	public static function blog_index( int $page, string $context = 'public' ): array {
		if ( ! self::blog_allowed( $context ) ) { return array( 'ids' => array(), 'more' => false ); }
		global $wpdb; $page = max( 1, min( 1000, $page ) );
		// Match WP_Query(cat) / get_posts(category): a public child post also qualifies its category ancestors.
		$membership = $wpdb->prepare( "SELECT DISTINCT tt.term_id FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id=tt.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON p.ID=tr.object_id WHERE tt.taxonomy=%s AND p.post_type=%s AND p.post_status=%s AND p.post_password=''", 'category', 'post', 'publish' );
		$eligible = array();
		foreach ( $wpdb->get_col( $membership ) as $direct ) {
			$id = (int) $direct; if ( $id < 1 ) { continue; } $eligible[ $id ] = $id;
			foreach ( get_ancestors( $id, 'category', 'taxonomy' ) as $ancestor ) { $ancestor = (int) $ancestor; if ( $ancestor > 0 ) { $eligible[ $ancestor ] = $ancestor; } }
		}
		if ( ! $eligible ) { return array( 'ids' => array(), 'more' => false ); }
		// Expand before LIMIT/OFFSET, then let the database retain its native name collation and ID tie-break.
		$placeholders = implode( ',', array_fill( 0, count( $eligible ), '%d' ) );
		$args = array_merge( array( 'category' ), array_values( $eligible ), array( 13, ( $page - 1 ) * 12 ) );
		$sql = $wpdb->prepare( "SELECT tt.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id WHERE tt.taxonomy=%s AND tt.term_id IN ($placeholders) ORDER BY t.name ASC, tt.term_id ASC LIMIT %d OFFSET %d", ...$args );
		$ids = array_map( 'intval', $wpdb->get_col( $sql ) );
		return array( 'ids' => array_slice( $ids, 0, 12 ), 'more' => count( $ids ) > 12 );
	}
	public static function reference( string $type, array $s, string $context ): string {
		if ( ! in_array( $type, array( 'blog_posts', 'blog_categories', 'category_blog', 'campaign_pages' ), true ) || ! self::blog_allowed( $context ) ) { return ''; }
		$preview = self::blog_preview( $context ); $cards = ''; $seen = array();
		$entries = (array) ( $s['blogEntries'] ?? array() ); $category_url = '';
		if ( 'category_blog' === $type ) {
			$category = Records::sanitize( $s['categoryBlogCategory'] ?? null ); $term = $category ? get_term( $category, 'category' ) : null;
			if ( $term instanceof \WP_Term ) {
				$url = get_term_link( $term ); $category_url = is_wp_error( $url ) ? '' : $url;
				$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'has_password' => false, 'category' => $category, 'numberposts' => max( 1, min( 100, (int) ( $s['categoryBlogCount'] ?? 3 ) ) ), 'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ) ) );
				$entries = array_map( static fn( $post ) => array( 'enabled' => true, 'settings' => array( 'entry' => $post->ID ) ), $posts );
			} else { $entries = array(); }
		}
		foreach ( array_slice( $entries, 0, 'category_blog' === $type ? 100 : 24 ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['enabled'] ) ) { continue; }
			$id = Records::sanitize( $row['settings']['entry'] ?? null ); if ( ! $id || isset( $seen[ $id ] ) ) { continue; } $seen[ $id ] = true;
			if ( 'blog_categories' !== $type ) {
				$post = get_post( $id ); if ( ! self::blog_public_post( $post, 'campaign_pages' === $type ? 'pc_topic' : 'post' ) ) { continue; }
				$data = array( 'title' => get_the_title( $id ), 'excerpt' => wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 30 ), 'url' => get_permalink( $id ), 'coverHtml' => ! empty( $s['blogShowCover'] ) && self::blog_cover_allowed( (int) get_post_thumbnail_id( $id ) ) ? get_the_post_thumbnail( $id, 'large' ) : '' );
				$meta = ''; if ( 'campaign_pages' !== $type && ! empty( $s['blogShowDate'] ) ) { $meta .= '<time datetime="' . esc_attr( get_the_date( 'c', $post ) ) . '">' . esc_html( get_the_date( '', $post ) ) . '</time>'; }
				if ( 'campaign_pages' !== $type && ! empty( $s['blogShowAuthor'] ) ) { $author = (string) get_post_meta( $id, \PeptideCore\Blog::META_AUTHOR, true ); if ( '' === $author ) { $author = (string) get_the_author_meta( 'display_name', $post->post_author ); } if ( '' !== $author ) { $meta .= '<span>' . esc_html( $author ) . '</span>'; } }
			} else {
				$data = self::blog_term( $id ); if ( ! $data ) { continue; } $meta = '';
				$data['coverHtml'] = ! empty( $s['blogShowCover'] ) && self::blog_cover_allowed( $data['cover'] ) ? wp_get_attachment_image( $data['cover'], 'large' ) : '';
			}
			if ( '' === trim( (string) $data['title'] ) ) { $data['title'] = __( 'Untitled content', 'peptide-core' ); }
			$link = static fn( string $html, string $class ): string => $preview ? '<span class="' . $class . '" aria-disabled="true">' . $html . '</span>' : '<a class="' . $class . '"' . ( 'pc-blog__cover' === $class ? ' aria-label="' . esc_attr( $data['title'] ) . '"' : '' ) . ' href="' . esc_url( $data['url'] ) . '">' . $html . '</a>';
			$card = $data['coverHtml'] ? $link( $data['coverHtml'], 'pc-blog__cover' ) : '';
			$card .= '<h3 class="pc-blog__name">' . $link( esc_html( $data['title'] ), 'pc-blog__title-link' ) . '</h3>';
			if ( ! empty( $s['blogShowExcerpt'] ) && '' !== $data['excerpt'] ) { $card .= '<p class="pc-blog__excerpt">' . esc_html( $data['excerpt'] ) . '</p>'; }
			if ( $meta ) { $card .= '<div class="pc-blog__meta">' . $meta . '</div>'; }
			if ( '' !== (string) ( $s['blogReadMore'] ?? '' ) ) { $card .= $link( esc_html( $s['blogReadMore'] ), 'pc-blog__read-more' ); }
			$cards .= '<li class="pc-blog__card">' . $card . '</li>';
		}
		if ( '' === $cards ) { return $preview ? '<section class="pc-blog-reference"><p class="pc-blog__empty">' . ( 'campaign_pages' === $type ? '请选择已发布的专题。' : ( 'blog_posts' === $type ? '请选择已发布的博客。' : '请选择含公开博客的专辑。' ) ) . '未选择的内容不会在店铺中展示。</p></section>' : ''; }
		$description = null !== ( $s['descriptionHtml'] ?? null ) ? wp_kses_post( (string) $s['descriptionHtml'] ) : ( '' !== ( $s['description'] ?? '' ) ? '<p>' . nl2br( esc_html( $s['description'] ) ) . '</p>' : '' );
		$heading = ( '' !== ( $s['title'] ?? '' ) ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '' ) . ( $description ? '<div class="pc-blog__description">' . $description . '</div>' : '' );
		$url = 'campaign_pages' === $type ? ( get_post_type_archive_link( 'pc_topic' ) ?: '' ) : ( 'category_blog' === $type ? $category_url : ( 'blog_categories' === $type ? \PeptideCore\Blog::collections_url() : ( null === ( $s['blogMoreUrl'] ?? null ) ? ( get_post_type_archive_link( 'post' ) ?: home_url( '/blogs/' ) ) : (string) $s['blogMoreUrl'] ) ) );
		$more = ''; if ( '' !== trim( $url ) && '' !== (string) ( $s['blogViewMore'] ?? '' ) ) {
			$effect = in_array( $s['blogEffect'] ?? '', array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble' ), true ) ? $s['blogEffect'] : 'none';
			$button = '<span class="pc-campaign__button pc-campaign__button--' . $effect . '" data-pc-campaign-effect="' . $effect . '" data-effect-duration="' . max( 1000, min( 5000, (int) ( $s['blogDurationMs'] ?? 1000 ) ) ) . '" data-effect-interval="' . max( 500, min( 5000, (int) ( $s['blogIntervalMs'] ?? 1500 ) ) ) . '">' . esc_html( $s['blogViewMore'] ) . '</span>';
			$more = '<div class="pc-blog__more">' . ( $preview ? '<span aria-disabled="true">' . $button . '</span>' : '<a href="' . esc_url( $url ) . '">' . $button . '</a>' ) . '</div>';
		}
		$align = in_array( $s['blogAlign'] ?? '', array( 'left', 'center', 'right' ), true ) ? $s['blogAlign'] : 'center'; $columns = max( 2, min( 'campaign_pages' === $type ? 6 : 5, (int) ( $s['blogColumns'] ?? 3 ) ) );
		return '<section class="vc-m__inner pc-blog-reference' . ( ! empty( $s['blogFullWidth'] ) ? ' is-full-width' : '' ) . '" data-pc-blog="' . esc_attr( $type ) . '" style="--pc-blog-columns:' . $columns . ';--pc-blog-align:' . $align . '">' . ( $heading ? '<header class="pc-blog__head">' . $heading . '</header>' : '' ) . '<ul class="pc-blog__list">' . $cards . '</ul>' . $more . '</section>';
	}
}
