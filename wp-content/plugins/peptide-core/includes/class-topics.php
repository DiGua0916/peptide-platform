<?php
/**
 * 活动专题页（用户 2026-10-27 要求照 YY 后台做实际功能）。
 *
 * YY 的做法（店铺装修 → 活动专题页）：
 * - 列表：已发布 / 待发布 / 全部，搜索；列：专题标题、专题封面、发布状态、访问量、创建时间、最后修改时间。
 * - 编辑：专题标题、简短描述、专题内容（富文本）、专题页状态（发布 / 不发布）、是否展示专题标题、
 *   封面（1:1，宽 1000px 以上）、SEO（标题、描述、链接、关键词；改链接时旧链接自动跳转）。
 * - 网址：/topics/<链接>。
 *
 * 这里对应 WordPress 的一个文章类型 pc_topic：标题、内容（区块编辑器）、摘要 = 简短描述、特色图片 = 封面，
 * 发布 / 草稿 = 专题页状态；链接就是 WordPress 的别名，改别名后旧地址由 WordPress 自动跳转（_wp_old_slug）。
 * 前台用主题的 single-pc_topic.php，没有时用 page.php（普通页面的样式）。
 *
 * 没做：YY 的“专题装修”（用装修编辑器单独给专题做样式），要等装修编辑器支持更多页面类型。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Topics {

	public const POST_TYPE  = 'pc_topic';
	public const SLUG       = 'topics';
	public const META_SEO   = '_pc_topic_seo';
	public const META_TITLE = '_pc_topic_show_title';
	public const META_VIEWS = '_pc_topic_views';
	private const REWRITE_VERSION = '1';

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'init', array( self::class, 'maybe_flush' ), 99 );
		add_filter( 'single_template_hierarchy', array( self::class, 'template_hierarchy' ) );
		add_filter( 'the_title', array( self::class, 'hide_title' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'count_view' ) );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . self::POST_TYPE, array( self::class, 'add_meta_box' ) );
			add_action( 'save_post_' . self::POST_TYPE, array( self::class, 'save_meta' ), 10, 2 );
			add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
			add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
			add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( self::class, 'sortable' ) );
			add_action( 'pre_get_posts', array( self::class, 'sort_by_views' ) );
		}
	}

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Campaign pages', 'peptide-core' ),
					'singular_name'      => __( 'Campaign page', 'peptide-core' ),
					'add_new'            => __( 'New campaign page', 'peptide-core' ),
					'add_new_item'       => __( 'New campaign page', 'peptide-core' ),
					'edit_item'          => __( 'Edit campaign page', 'peptide-core' ),
					'view_item'          => __( 'View campaign page', 'peptide-core' ),
					'search_items'       => __( 'Search campaign pages', 'peptide-core' ),
					'not_found'          => __( 'No campaign pages yet.', 'peptide-core' ),
					'not_found_in_trash' => __( 'No campaign pages in the trash.', 'peptide-core' ),
					'all_items'          => __( 'Campaign pages', 'peptide-core' ),
					'featured_image'     => __( 'Cover', 'peptide-core' ),
					'set_featured_image' => __( 'Set cover (1:1, at least 1000 px wide)', 'peptide-core' ),
					'remove_featured_image' => __( 'Remove cover', 'peptide-core' ),
				),
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => false, // 入口在“店铺装修 → 活动专题页”。
				'show_in_rest'    => true,
				'has_archive'     => false,
				'rewrite'         => array( 'slug' => self::SLUG, 'with_front' => false ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
			)
		);
	}

	/** 文章类型第一次注册（或网址规则变了）时刷新一次网址规则。 */
	public static function maybe_flush(): void {
		if ( self::REWRITE_VERSION !== get_option( 'pc_topics_rewrite' ) ) {
			flush_rewrite_rules( false );
			update_option( 'pc_topics_rewrite', self::REWRITE_VERSION, true );
		}
	}

	/** 主题没有 single-pc_topic.php 时用 page.php（普通页面的样式），而不是文章列表样式。 */
	public static function template_hierarchy( $templates ): array {
		$templates = (array) $templates;
		if ( ! is_singular( self::POST_TYPE ) ) {
			return $templates;
		}
		$pos = array_search( 'single.php', $templates, true );
		array_splice( $templates, false === $pos ? count( $templates ) : $pos, 0, array( 'page.php' ) );
		return $templates;
	}

	public static function shows_title( int $id ): bool {
		return '0' !== (string) get_post_meta( $id, self::META_TITLE, true );
	}

	/** “不展示专题标题”时，专题页正文上方的标题为空（菜单、列表、浏览器标题不受影响）。 */
	public static function hide_title( $title, $id = 0 ) {
		$id = (int) $id;
		if ( $id && ! is_admin() && is_singular( self::POST_TYPE ) && in_the_loop() && is_main_query() && get_queried_object_id() === $id && ! self::shows_title( $id ) ) {
			return '';
		}
		return $title;
	}

	/** 访问量：已发布的专题页被访客打开一次加一（员工、预览不算）。 */
	public static function count_view(): void {
		if ( ! is_singular( self::POST_TYPE ) || is_preview() || current_user_can( 'edit_pages' ) ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( $id && 'publish' === get_post_status( $id ) ) {
			update_post_meta( $id, self::META_VIEWS, (int) get_post_meta( $id, self::META_VIEWS, true ) + 1 );
		}
	}

	/** 专题页的 SEO：自己填的优先；没填的标题用专题标题，描述用简短描述。 */
	public static function seo( int $id ): array {
		$own = get_post_meta( $id, self::META_SEO, true );
		$set = Seo::clean_set( is_array( $own ) ? $own : array() );
		if ( '' === $set['title'] ) {
			$set['title'] = (string) get_the_title( $id );
		}
		if ( '' === $set['description'] ) {
			$set['description'] = wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $id ) );
		}
		return $set;
	}

	// ---------- 后台 ----------

	public static function add_meta_box(): void {
		add_meta_box( 'pc_topic_settings', __( 'Campaign page settings', 'peptide-core' ), array( self::class, 'render_meta_box' ), self::POST_TYPE, 'side', 'high' );
		add_meta_box( 'pc_topic_seo', __( 'SEO', 'peptide-core' ), array( self::class, 'render_seo_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	public static function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'pc_topic_save', 'pc_topic_nonce' );
		echo '<p><label><input type="checkbox" name="pc_topic_show_title" value="1"' . checked( self::shows_title( $post->ID ), true, false ) . '> ' . esc_html__( 'Show the title on the page', 'peptide-core' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Published = visible at its address; draft = not visible yet. The short description goes in the Excerpt box; the cover is the featured image.', 'peptide-core' ) . '</p>';
		$views = (int) get_post_meta( $post->ID, self::META_VIEWS, true );
		/* translators: %d: number of views */
		echo '<p>' . esc_html( sprintf( __( 'Views: %d', 'peptide-core' ), $views ) ) . '</p>';
	}

	public static function render_seo_box( \WP_Post $post ): void {
		$own = get_post_meta( $post->ID, self::META_SEO, true );
		$set = Seo::clean_set( is_array( $own ) ? $own : array() );
		$url = 'publish' === $post->post_status ? (string) get_permalink( $post ) : home_url( '/' . self::SLUG . '/' . ( $post->post_name ? $post->post_name : '…' ) . '/' );
		echo '<p class="description">' . esc_html__( 'Leave empty to use the campaign title and short description. The link is the page’s slug (edit it next to the title); after a change the old link redirects to the new one.', 'peptide-core' ) . '</p>';
		echo '<p><label for="pc_topic_seo_title"><strong>' . esc_html__( 'SEO title', 'peptide-core' ) . '</strong></label><br><input type="text" class="large-text" id="pc_topic_seo_title" name="pc_topic_seo[title]" maxlength="' . esc_attr( (string) Seo::MAX_TEXT ) . '" value="' . esc_attr( $set['title'] ) . '"></p>';
		echo '<p><label for="pc_topic_seo_desc"><strong>' . esc_html__( 'SEO description', 'peptide-core' ) . '</strong></label><br><textarea class="large-text" rows="3" id="pc_topic_seo_desc" name="pc_topic_seo[description]" maxlength="' . esc_attr( (string) Seo::MAX_TEXT ) . '">' . esc_textarea( $set['description'] ) . '</textarea></p>';
		echo '<p><label for="pc_topic_seo_keys"><strong>' . esc_html__( 'SEO keywords', 'peptide-core' ) . '</strong></label><br><input type="text" class="large-text" id="pc_topic_seo_keys" name="pc_topic_seo[keywords]" value="' . esc_attr( implode( ', ', $set['keywords'] ) ) . '" placeholder="' . esc_attr__( 'Separate keywords with commas', 'peptide-core' ) . '"></p>';
		echo '<p>' . esc_html__( 'Address:', 'peptide-core' ) . ' <code>' . esc_html( $url ) . '</code></p>';
	}

	public static function save_meta( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['pc_topic_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pc_topic_nonce'] ) ), 'pc_topic_save' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, self::META_TITLE, isset( $_POST['pc_topic_show_title'] ) ? '1' : '0' );
		if ( '' === (string) get_post_meta( $post_id, self::META_VIEWS, true ) ) {
			update_post_meta( $post_id, self::META_VIEWS, 0 ); // 按访问量排序时，没有访问的也要列出来。
		}
		$raw = isset( $_POST['pc_topic_seo'] ) && is_array( $_POST['pc_topic_seo'] ) ? wp_unslash( $_POST['pc_topic_seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Seo::clean_set 清理。
		update_post_meta( $post_id, self::META_SEO, Seo::clean_set( $raw ) );
	}

	public static function columns( $cols ): array {
		$cols = (array) $cols;
		$out  = array();
		foreach ( $cols as $key => $label ) {
			if ( 'title' === $key ) {
				$out['pc_cover'] = __( 'Cover', 'peptide-core' );
			}
			if ( 'date' === $key ) {
				$out['pc_views']    = __( 'Visit count', 'peptide-core' );
				$out['pc_modified'] = __( 'Last modified', 'peptide-core' );
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	public static function column( string $col, int $post_id ): void {
		if ( 'pc_cover' === $col ) {
			$img = get_the_post_thumbnail( $post_id, array( 60, 60 ), array( 'style' => 'width:60px;height:60px;object-fit:cover;border-radius:4px' ) );
			echo $img ? $img : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'pc_views' === $col ) {
			echo esc_html( number_format_i18n( (int) get_post_meta( $post_id, self::META_VIEWS, true ) ) );
		} elseif ( 'pc_modified' === $col ) {
			echo esc_html( get_post_modified_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), false, $post_id, true ) );
		}
	}

	public static function sortable( $cols ): array {
		$cols             = (array) $cols;
		$cols['pc_views'] = 'pc_views';
		$cols['pc_modified'] = 'modified';
		return $cols;
	}

	public static function sort_by_views( \WP_Query $q ): void {
		if ( $q->is_main_query() && self::POST_TYPE === $q->get( 'post_type' ) && 'pc_views' === $q->get( 'orderby' ) ) {
			$q->set( 'meta_key', self::META_VIEWS ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$q->set( 'orderby', 'meta_value_num' );
		}
	}
}
