<?php
/**
 * 博客（照 YY 后台“博客 → 博客管理 / 博客专辑”，用户 2026-10-27）。用 WordPress 自己的文章和分类。
 *
 * YY 的做法：
 * - 博客管理：已发布 / 未发布 / 全部，搜索，导出 / 导入；列：博客标题、博客图片、发布状态、是否置顶、博客专辑、访问量、最后修改时间。
 * - 编辑：标题、简短描述、内容、发布、置顶、发布时间、作者（文字）、博客专辑、封面、SEO、博客底部展示关联商品。网址 /blogs/<链接>。
 * - 博客专辑：专辑名称、简短描述、专辑顶部描述、专辑底部描述、封面、SEO；列：专辑图片、博客数量、访问量、修改时间。网址 /news/<链接>。
 *
 * 这里：文章 = 博客，分类 = 博客专辑，摘要 = 简短描述，特色图片 = 封面，WordPress 的“置顶” = 置顶博客。
 * - 网址：博客列表 /blogs/，博客 /blogs/<别名>/（旧的 /<别名>/ 由 WordPress 301 到新网址），博客专辑 /news/<别名>/。
 * - 博客列表第一页把置顶的博客放在最前面。
 * - 关联商品：主题没有声明 add_theme_support( 'pc-blog-products' ) 时，插件在正文后面用 WooCommerce 的商品列表显示；
 *   主题想自己排版时声明这个支持，再用 Blog::related_products() 取商品。
 * - 没做：YY 的“AI 生成”、“查看收录 / 手动提交”（要接搜索引擎的站长工具）。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Blog {

	public const BASE      = 'blogs';
	public const TERM_BASE = 'news';

	public const META_VIEWS    = '_pc_views';
	public const META_SEO      = '_pc_seo';
	public const META_AUTHOR   = '_pc_blog_author';
	public const META_PRODUCTS = '_pc_blog_products';

	public const TERM_COVER    = '_pc_cover';
	public const TERM_TOP      = '_pc_top_html';
	public const TERM_BOTTOM   = '_pc_bottom_html';
	public const TERM_MODIFIED = '_pc_modified';

	/** 博客底部最多展示几个关联商品。 */
	public const MAX_PRODUCTS = 12;

	private const REWRITE_VERSION = '1';

	public static function register_hooks(): void {
		add_filter( 'register_post_type_args', array( self::class, 'post_args' ), 10, 2 );
		add_filter( 'register_taxonomy_args', array( self::class, 'category_args' ), 10, 2 );
		add_filter( 'post_type_labels_post', array( self::class, 'post_labels' ) );
		add_filter( 'taxonomy_labels_category', array( self::class, 'category_labels' ) );
		add_action( 'init', array( self::class, 'rewrite' ), 20 );
		add_filter( 'query_vars', static function( $vars ) { return array_merge( $vars, array( 'pc_blog_collections', 'pc_blog_collections_page' ) ); } );
		add_action( 'pre_get_posts', array( self::class, 'collections_query' ) );
		add_filter( 'pre_handle_404', array( self::class, 'collections_404' ), 10, 2 );
		add_filter( 'redirect_canonical', static fn( $url ) => '1' === (string) get_query_var( 'pc_blog_collections' ) ? false : $url );
		add_filter( 'template_include', array( self::class, 'collections_template' ), 10001 );
		add_filter( 'pre_get_document_title', static function( $title ) { return '1' === (string) get_query_var( 'pc_blog_collections' ) ? __( 'Blog collections', 'peptide-core' ) . ( (int) get_query_var( 'pc_blog_collections_page' ) > 1 ? ' — ' . sprintf( __( 'Page %d', 'peptide-core' ), (int) get_query_var( 'pc_blog_collections_page' ) ) : '' ) . ' | ' . get_bloginfo( 'name' ) : $title; }, 31 );
		add_action( 'init', array( self::class, 'maybe_flush' ), 99 );
		add_filter( 'post_link', array( self::class, 'post_link' ), 10, 2 );
		add_filter( 'post_type_archive_link', array( self::class, 'archive_link' ), 10, 2 );
		add_filter( 'the_posts', array( self::class, 'sticky_first' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'redirect_old' ), 5 );
		add_action( 'template_redirect', array( self::class, 'count_view' ) );
		add_filter( 'the_content', array( self::class, 'append_products' ), 20 );
		add_filter( 'the_author', array( self::class, 'author_name' ) );
	}

	// ---------- 注册：文章 = 博客，分类 = 博客专辑 ----------

	/** 博客列表在 /blogs/。 */
	public static function post_args( $args, $post_type ) {
		if ( 'post' === $post_type ) {
			$args['has_archive'] = self::BASE;
			$args['menu_icon']   = 'dashicons-welcome-write-blog';
		}
		return $args;
	}

	/** 博客专辑在 /news/<别名>/（和 YY 一样），不用 WordPress 的 /category/。 */
	public static function category_args( $args, $taxonomy ) {
		if ( 'category' === $taxonomy ) {
			$args['rewrite'] = array(
				'slug'         => self::TERM_BASE,
				'with_front'   => false,
				'hierarchical' => true,
			);
		}
		return $args;
	}

	public static function post_labels( $labels ) {
		$labels                        = (object) $labels;
		$labels->name                  = __( 'Blog', 'peptide-core' );
		$labels->singular_name         = __( 'Blog article', 'peptide-core' );
		$labels->menu_name             = __( 'Blog', 'peptide-core' );
		$labels->all_items             = __( 'Blog posts', 'peptide-core' );
		$labels->add_new               = __( 'New blog post', 'peptide-core' );
		$labels->add_new_item          = __( 'New blog post', 'peptide-core' );
		$labels->edit_item             = __( 'Edit blog post', 'peptide-core' );
		$labels->new_item              = __( 'New blog post', 'peptide-core' );
		$labels->view_item             = __( 'View blog post', 'peptide-core' );
		$labels->search_items          = __( 'Search blog posts', 'peptide-core' );
		$labels->not_found             = __( 'No blog posts yet.', 'peptide-core' );
		$labels->not_found_in_trash    = __( 'No blog posts in the trash.', 'peptide-core' );
		$labels->featured_image        = __( 'Cover', 'peptide-core' );
		$labels->set_featured_image    = __( 'Set cover (4:3, 3:2, 2:1 or 1:1, at least 1000 px wide)', 'peptide-core' );
		$labels->remove_featured_image = __( 'Remove cover', 'peptide-core' );
		$labels->use_featured_image    = __( 'Use as cover', 'peptide-core' );
		return $labels;
	}

	public static function category_labels( $labels ) {
		$labels                = (object) $labels;
		$labels->name          = __( 'Blog collections', 'peptide-core' );
		$labels->singular_name = __( 'Blog collection', 'peptide-core' );
		$labels->menu_name     = __( 'Blog collections', 'peptide-core' );
		$labels->all_items     = __( 'All collections', 'peptide-core' );
		$labels->edit_item     = __( 'Edit collection', 'peptide-core' );
		$labels->view_item     = __( 'View collection', 'peptide-core' );
		$labels->update_item   = __( 'Update collection', 'peptide-core' );
		$labels->add_new_item  = __( 'New collection', 'peptide-core' );
		$labels->new_item_name = __( 'Collection name', 'peptide-core' );
		$labels->search_items  = __( 'Search collections', 'peptide-core' );
		$labels->not_found     = __( 'No collections yet.', 'peptide-core' );
		$labels->back_to_items = __( '← Back to blog collections', 'peptide-core' );
		return $labels;
	}

	/** 博客列表和博客的网址规则（放在最前面，不和页面的网址冲突）。 */
	public static function rewrite(): void {
		add_rewrite_rule( '^' . self::TERM_BASE . '/?$', 'index.php?pc_blog_collections=1', 'top' );
		add_rewrite_rule( '^' . self::BASE . '/?$', 'index.php?post_type=post', 'top' );
		add_rewrite_rule( '^' . self::BASE . '/page/([0-9]{1,})/?$', 'index.php?post_type=post&paged=$matches[1]', 'top' );
		add_rewrite_rule( '^' . self::BASE . '/([^/]+)/?$', 'index.php?name=$matches[1]', 'top' );
	}

	public static function maybe_flush(): void {
		if ( self::REWRITE_VERSION !== get_option( 'pc_blog_rewrite' ) ) {
			flush_rewrite_rules( false );
			update_option( 'pc_blog_rewrite', self::REWRITE_VERSION, true );
		}
	}

	/** 博客的网址：/blogs/<别名>/。草稿没有别名时保持 WordPress 的预览地址。 */
	public static function post_link( $url, $post ) {
		$post = get_post( $post );
		if ( ! $post || 'post' !== $post->post_type || '' === $post->post_name || ! get_option( 'permalink_structure' ) || in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			return $url;
		}
		return home_url( user_trailingslashit( '/' . self::BASE . '/' . $post->post_name ) );
	}

	public static function archive_link( $link, $post_type ) {
		if ( 'post' !== $post_type ) { return $link; }
		if ( get_option( 'permalink_structure' ) ) { return home_url( user_trailingslashit( '/' . self::BASE ) ); }
		$home = home_url( '/' );
		return untrailingslashit( (string) $link ) === untrailingslashit( $home ) ? add_query_arg( 'post_type', 'post', $home ) : $link;
	}

	/** 是不是博客列表（/blogs/）的主查询。 */
	private static function is_blog_list( \WP_Query $q ): bool {
		return $q->is_main_query() && $q->is_post_type_archive( 'post' ) && ! is_admin();
	}

	/** 博客列表第一页：置顶的博客放在最前面（WordPress 只在首页这样做）。 */
	public static function sticky_first( $posts, $q ) {
		if ( ! $q instanceof \WP_Query || ! self::is_blog_list( $q ) || (int) $q->get( 'paged' ) > 1 ) {
			return $posts;
		}
		$sticky = array_map( 'intval', (array) get_option( 'sticky_posts', array() ) );
		if ( ! $sticky ) {
			return $posts;
		}
		$posts = array_values(
			array_filter(
				(array) $posts,
				static fn( $p ) => ! in_array( (int) $p->ID, $sticky, true )
			)
		);
		$pinned = get_posts(
			array(
				'post__in'       => $sticky,
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => count( $sticky ),
				'orderby'        => 'date',
			)
		);
		return array_merge( $pinned, $posts );
	}

	// ---------- 前台 ----------

	/** 旧网址（/<别名>/）打开博客时 301 到 /blogs/<别名>/。WordPress 自己不会跳，两个网址会被当成重复内容。 */
	public static function redirect_old(): void {
		if ( ! is_singular( 'post' ) || is_preview() || ! get_option( 'permalink_structure' ) ) {
			return;
		}
		$path   = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		$prefix = trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) . self::BASE . '/';
		if ( str_starts_with( trailingslashit( $path ), $prefix ) ) {
			return;
		}
		$link = (string) get_permalink( get_queried_object_id() );
		if ( str_contains( $link, '/' . self::BASE . '/' ) ) {
			$query = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
			wp_safe_redirect( '' !== $query ? $link . '?' . $query : $link, 301 );
			exit;
		}
	}

	/** 访问量：访客打开一次博客或博客专辑加一（员工、预览不算）。 */
	public static function count_view(): void {
		if ( is_preview() || current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( is_singular( 'post' ) ) {
			$id = (int) get_queried_object_id();
			if ( $id && 'publish' === get_post_status( $id ) ) {
				update_post_meta( $id, self::META_VIEWS, (int) get_post_meta( $id, self::META_VIEWS, true ) + 1 );
			}
		} elseif ( is_category() ) {
			$id = (int) get_queried_object_id();
			if ( $id ) {
				update_term_meta( $id, self::META_VIEWS, (int) get_term_meta( $id, self::META_VIEWS, true ) + 1 );
			}
		}
	}

	/**
	 * 博客底部展示的关联商品（已发布、按选择的顺序）。
	 *
	 * @return int[]
	 */
	public static function related_products( int $post_id ): array {
		$ids = array_map( 'intval', (array) get_post_meta( $post_id, self::META_PRODUCTS, true ) );
		return array_values(
			array_filter(
				$ids,
				static fn( int $id ): bool => $id > 0 && 'product' === get_post_type( $id ) && 'publish' === get_post_status( $id )
			)
		);
	}

	/** 主题没有自己排版关联商品时，插件在正文后面显示。 */
	public static function append_products( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() || current_theme_supports( 'pc-blog-products' ) || ! shortcode_exists( 'products' ) ) {
			return $content;
		}
		$ids = self::related_products( (int) get_the_ID() );
		if ( ! $ids ) {
			return $content;
		}
		$list = do_shortcode( '[products ids="' . implode( ',', $ids ) . '" orderby="post__in" columns="4" limit="' . self::MAX_PRODUCTS . '"]' );
		return $content . '<section class="pc-blog-products"><h2>' . esc_html__( 'Products in this post', 'peptide-core' ) . '</h2>' . $list . '</section>';
	}

	/** 填了“作者”时，前台显示这个名字（不改 WordPress 的账号）。 */
	public static function author_name( $name ) {
		if ( is_admin() ) {
			return $name;
		}
		$post = get_post();
		if ( $post && 'post' === $post->post_type ) {
			$own = (string) get_post_meta( $post->ID, self::META_AUTHOR, true );
			if ( '' !== $own ) {
				return $own;
			}
		}
		return $name;
	}

	// ---------- SEO ----------

	/** 博客的 SEO：自己填的优先；没填的标题用博客标题，描述用简短描述。 */
	public static function seo_post( int $id ): array {
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

	/** 博客专辑的 SEO：没填的标题用专辑名称，描述用简短描述。 */
	public static function seo_term( int $term_id ): array {
		$own  = get_term_meta( $term_id, self::META_SEO, true );
		$set  = Seo::clean_set( is_array( $own ) ? $own : array() );
		$term = get_term( $term_id, 'category' );
		if ( $term instanceof \WP_Term ) {
			if ( '' === $set['title'] ) {
				$set['title'] = $term->name;
			}
			if ( '' === $set['description'] ) {
				$set['description'] = wp_strip_all_tags( $term->description );
			}
		}
		return $set;
	}

	/** 博客专辑的封面地址。 */
	public static function term_cover( int $term_id, string $size = 'large' ): string {
		$img = (int) get_term_meta( $term_id, self::TERM_COVER, true );
		return $img ? (string) wp_get_attachment_image_url( $img, $size ) : '';
	}

	/**
	 * 博客专辑给主题用的资料：封面、顶部描述、底部描述（HTML 已按文章内容的规则清理）。
	 *
	 * @return array{cover:string,top:string,bottom:string}
	 */
	public static function term_extras( int $term_id ): array {
		return array(
			'cover'  => self::term_cover( $term_id ),
			'top'    => wp_kses_post( (string) get_term_meta( $term_id, self::TERM_TOP, true ) ),
			'bottom' => wp_kses_post( (string) get_term_meta( $term_id, self::TERM_BOTTOM, true ) ),
		);
	}

	// ---------- 导出 / 导入 ----------

	public const CSV_COLUMNS = array( 'slug', 'title', 'status', 'sticky', 'date', 'author', 'collections', 'excerpt', 'content', 'cover_url', 'seo_title', 'seo_description', 'seo_keywords', 'products' );

	/** CSV：每篇博客一行；专辑、关键词、关联商品用 | 分隔。以 = + - @ 开头的单元格前面加 '，防止表格软件当公式。 */
	public static function export_csv(): string {
		$out  = fopen( 'php://temp', 'r+' );
		$safe = static fn( $v ): string => preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : (string) $v;
		fputcsv( $out, self::CSV_COLUMNS );
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$sticky = array_map( 'intval', (array) get_option( 'sticky_posts', array() ) );
		foreach ( $posts as $p ) {
			$seo  = self::seo_raw( $p->ID );
			$cats = wp_get_post_terms( $p->ID, 'category', array( 'fields' => 'slugs' ) );
			$row  = array(
				$p->post_name,
				$p->post_title,
				$p->post_status,
				in_array( (int) $p->ID, $sticky, true ) ? '1' : '0',
				$p->post_date,
				(string) get_post_meta( $p->ID, self::META_AUTHOR, true ),
				is_array( $cats ) ? implode( '|', $cats ) : '',
				$p->post_excerpt,
				$p->post_content,
				(string) get_the_post_thumbnail_url( $p->ID, 'full' ),
				$seo['title'],
				$seo['description'],
				implode( '|', $seo['keywords'] ),
				implode( '|', array_map( 'intval', (array) get_post_meta( $p->ID, self::META_PRODUCTS, true ) ) ),
			);
			fputcsv( $out, array_map( $safe, $row ) );
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );
		return "\xEF\xBB\xBF" . $csv;
	}

	private static function seo_raw( int $id ): array {
		$own = get_post_meta( $id, self::META_SEO, true );
		return Seo::clean_set( is_array( $own ) ? $own : array() );
	}

	/**
	 * 导入 CSV：按别名找博客，有就更新，没有就新建。专辑按别名找，没有就新建；封面只认本站媒体库里的图片地址。
	 *
	 * @return array{0:int,1:int,2:string[]} [新建数, 更新数, 出错的行]
	 */
	public static function import_csv( string $csv ): array {
		$csv   = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$lines = fopen( 'php://temp', 'r+' );
		fwrite( $lines, (string) $csv );
		rewind( $lines );
		$head = fgetcsv( $lines );
		$head = is_array( $head ) ? array_map( static fn( $h ) => strtolower( trim( (string) $h ) ), $head ) : array();
		$new  = 0;
		$upd  = 0;
		$errs = array();
		$line = 1;
		if ( ! in_array( 'title', $head, true ) ) {
			fclose( $lines );
			return array( 0, 0, array( __( 'The file has no “title” column. Export first to get the format.', 'peptide-core' ) ) );
		}
		$unformula = static fn( string $v ): string => preg_match( "/^'[=+\-@\t\r]/", $v ) ? substr( $v, 1 ) : $v;
		while ( false !== ( $cells = fgetcsv( $lines ) ) ) {
			++$line;
			if ( array( null ) === $cells ) {
				continue;
			}
			$row = array();
			foreach ( $head as $i => $key ) {
				$row[ $key ] = $unformula( (string) ( $cells[ $i ] ?? '' ) );
			}
			$title = sanitize_text_field( $row['title'] ?? '' );
			if ( '' === $title ) {
				/* translators: %d: line number */
				$errs[] = sprintf( __( 'Line %d: the title is empty.', 'peptide-core' ), $line );
				continue;
			}
			$slug     = sanitize_title( '' !== ( $row['slug'] ?? '' ) ? $row['slug'] : $title );
			$existing = get_page_by_path( $slug, OBJECT, 'post' );
			$status   = in_array( $row['status'] ?? '', array( 'publish', 'draft', 'pending', 'private' ), true ) ? $row['status'] : 'draft';
			$data     = array(
				'post_type'    => 'post',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => $status,
				'post_excerpt' => sanitize_textarea_field( $row['excerpt'] ?? '' ),
				'post_content' => (string) ( $row['content'] ?? '' ), // wp_insert_post 按当前用户的权限清理 HTML。
			);
			$date = (string) ( $row['date'] ?? '' );
			if ( '' !== $date && false !== strtotime( $date ) ) {
				$data['post_date'] = gmdate( 'Y-m-d H:i:s', (int) strtotime( $date ) );
			}
			if ( $existing ) {
				$data['ID'] = $existing->ID;
				$id         = wp_update_post( wp_slash( $data ), true );
			} else {
				$id = wp_insert_post( wp_slash( $data ), true );
			}
			if ( is_wp_error( $id ) ) {
				/* translators: 1: line number, 2: error */
				$errs[] = sprintf( __( 'Line %1$d: %2$s', 'peptide-core' ), $line, $id->get_error_message() );
				continue;
			}
			$existing ? ++$upd : ++$new;
			$id = (int) $id;
			if ( isset( $row['sticky'] ) ) {
				'1' === trim( $row['sticky'] ) ? stick_post( $id ) : unstick_post( $id );
			}
			update_post_meta( $id, self::META_AUTHOR, mb_substr( sanitize_text_field( $row['author'] ?? '' ), 0, 63 ) );
			$terms = array();
			foreach ( array_filter( array_map( 'trim', explode( '|', (string) ( $row['collections'] ?? '' ) ) ) ) as $term_slug ) {
				$term = get_term_by( 'slug', sanitize_title( $term_slug ), 'category' );
				if ( ! $term && current_user_can( 'manage_categories' ) ) {
					$made = wp_insert_term( $term_slug, 'category', array( 'slug' => sanitize_title( $term_slug ) ) );
					$term = is_wp_error( $made ) ? null : get_term( (int) $made['term_id'], 'category' );
				}
				if ( $term instanceof \WP_Term ) {
					$terms[] = (int) $term->term_id;
				}
			}
			if ( $terms ) {
				wp_set_post_categories( $id, $terms );
			}
			$cover = (string) ( $row['cover_url'] ?? '' );
			if ( '' !== $cover ) {
				$img = attachment_url_to_postid( $cover );
				if ( $img ) {
					set_post_thumbnail( $id, $img );
				}
			}
			update_post_meta(
				$id,
				self::META_SEO,
				Seo::clean_set(
					array(
						'title'       => $row['seo_title'] ?? '',
						'description' => $row['seo_description'] ?? '',
						'keywords'    => str_replace( '|', ',', (string) ( $row['seo_keywords'] ?? '' ) ),
					)
				)
			);
			update_post_meta( $id, self::META_PRODUCTS, self::clean_products( explode( '|', (string) ( $row['products'] ?? '' ) ) ) );
			if ( '' === (string) get_post_meta( $id, self::META_VIEWS, true ) ) {
				update_post_meta( $id, self::META_VIEWS, 0 );
			}
		}
		fclose( $lines );
		return array( $new, $upd, $errs );
	}

	/**
	 * 关联商品：只留存在的商品，去重，最多 12 个。
	 *
	 * @param array<int|string> $ids
	 * @return int[]
	 */
	public static function clean_products( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && 'product' === get_post_type( $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return array_slice( $out, 0, self::MAX_PRODUCTS );
	}

	/** Real category index; no page is created and no rewrite flush is triggered here. */
	public static function collections_url( int $page = 1 ): string {
		$url = home_url( user_trailingslashit( '/' . self::TERM_BASE ) );
		return $page > 1 ? add_query_arg( 'pc_blog_collections_page', max( 1, min( 1000, $page ) ), $url ) : $url;
	}
	public static function collections_query( $query ): void {
		if ( is_admin() || ! $query->is_main_query() || '1' !== (string) $query->get( 'pc_blog_collections' ) ) { return; }
		$query->is_home = false; $query->is_page = false; $query->is_archive = true; $query->is_404 = false;
		$query->set( 'post_type', 'post' ); $query->set( 'post__in', array( 0 ) ); $query->set( 'posts_per_page', 1 );
	}
	public static function collections_404( $preempt, $query ) {
		if ( ! is_admin() && '1' === (string) $query->get( 'pc_blog_collections' ) ) { status_header( 200 ); return true; }
		return $preempt;
	}
	public static function collections_template( string $template ): string {
		if ( is_admin() || '1' !== (string) get_query_var( 'pc_blog_collections' ) || \PeptideCore\Modules\Renderer::$in_editor || Templates::previewing_id() ) { return $template; }
		// Actual rewriting and canonical behavior still require controlled real-WP acceptance.
		if ( ! \PeptideCore\Modules\Content_Blocks::blog_allowed( 'public' ) ) { return $template; }
		status_header( 200 );
		return PEPTIDE_CORE_DIR . '/templates/blog-collections.php';
	}
}
