<?php
/**
 * 后台“博客”（照 YY 后台，用户 2026-10-27）：博客管理（WordPress 文章列表）和博客专辑（WordPress 分类）。数据和前台见 PeptideCore\Blog。
 *
 * - 菜单：顶层“博客”放在“访客数据”和“店铺设置”之间，下面只有博客管理、博客专辑（新建在列表页按钮里，标签不用）。
 * - 博客管理：列为博客标题、博客图片、发布状态、是否置顶（开关）、博客专辑、访问量、最后修改时间；上方有导出 / 导入博客。
 * - 编辑博客：右侧“博客设置”（作者、访问量、网址），下方“SEO”和“博客底部展示关联商品”。置顶在 WordPress 的“置顶”选项里。
 * - 博客专辑：封面、专辑顶部描述、专辑底部描述、SEO；列为专辑图片、名称、别名、博客数量、访问量、修改时间。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Blog;
use PeptideCore\Seo;

defined( 'ABSPATH' ) || exit;

final class Blog_Admin {

	/** 菜单位置：访客数据（57）和店铺设置（58）之间，和 YY 的“数据 → 博客 → …”顺序一致。 */
	private const POSITION = '57.5';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 1001 );
		add_filter( 'submenu_file', array( self::class, 'submenu_file' ), 30 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'add_meta_boxes_post', array( self::class, 'add_meta_boxes' ) );
		add_action( 'save_post_post', array( self::class, 'save_meta' ), 10, 2 );
		add_filter( 'manage_post_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_post_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'manage_edit-post_sortable_columns', array( self::class, 'sortable' ) );
		add_action( 'pre_get_posts', array( self::class, 'sort_by_views' ) );
		add_action( 'all_admin_notices', array( self::class, 'list_toolbar' ) );
		add_action( 'admin_post_pc_blog_sticky', array( self::class, 'handle_sticky' ) );
		add_action( 'admin_post_pc_blog_export', array( self::class, 'handle_export' ) );
		add_action( 'admin_post_pc_blog_import', array( self::class, 'handle_import' ) );
		add_action( 'category_add_form_fields', array( self::class, 'term_add_fields' ) );
		add_action( 'category_edit_form_fields', array( self::class, 'term_edit_fields' ) );
		add_action( 'created_category', array( self::class, 'save_term' ) );
		add_action( 'edited_category', array( self::class, 'save_term' ) );
		add_filter( 'manage_edit-category_columns', array( self::class, 'term_columns' ) );
		add_filter( 'manage_category_custom_column', array( self::class, 'term_column' ), 10, 3 );
		add_filter( 'manage_edit-category_sortable_columns', array( self::class, 'term_sortable' ) );
	}

	// ---------- 菜单 ----------

	/** 顶层“博客”：改名、换图标、挪位置；子菜单只留博客管理和博客专辑。 */
	public static function menu(): void {
		global $menu, $submenu;
		foreach ( (array) $menu as $i => $item ) {
			if ( 'edit.php' !== ( $item[2] ?? '' ) ) {
				continue;
			}
			$item[0] = __( 'Blog', 'peptide-core' );
			$item[6] = 'dashicons-welcome-write-blog';
			unset( $menu[ $i ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$menu[ self::POSITION ] = $item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			break;
		}
		if ( empty( $submenu['edit.php'] ) ) {
			return;
		}
		$keep = array();
		foreach ( $submenu['edit.php'] as $item ) {
			$slug = (string) ( $item[2] ?? '' );
			if ( 'edit.php' === $slug ) {
				$item[0]  = __( 'Blog posts', 'peptide-core' );
				$keep[10] = $item;
			} elseif ( str_contains( $slug, 'taxonomy=category' ) ) {
				$item[0]  = __( 'Blog collections', 'peptide-core' );
				$keep[20] = $item;
			} elseif ( 'post-new.php' !== $slug && ! str_contains( $slug, 'taxonomy=post_tag' ) ) {
				$keep[ 100 + count( $keep ) ] = $item; // 其他插件加的子菜单放在后面。
			}
		}
		ksort( $keep );
		$submenu['edit.php'] = array_values( $keep ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/** 新建博客时左侧停在“博客管理”。 */
	public static function submenu_file( $submenu_file ) {
		global $pagenow, $typenow;
		return 'post' === $typenow && 'post-new.php' === $pagenow ? 'edit.php' : $submenu_file;
	}

	public static function enqueue( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$ver = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( 'post' === $screen->base && 'post' === $screen->post_type ) {
			// WooCommerce 的商品搜索下拉框（关联商品）。
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
		if ( in_array( $screen->base, array( 'edit', 'edit-tags', 'term' ), true ) && in_array( $screen->id, array( 'edit-post', 'edit-category' ), true ) ) {
			wp_enqueue_style( 'peptide-core-blog-admin', plugins_url( 'assets/blog-admin.css', PEPTIDE_CORE_FILE ), array(), $ver( 'blog-admin.css' ) );
		}
		if ( in_array( $screen->base, array( 'edit-tags', 'term' ), true ) && 'category' === $screen->taxonomy ) {
			wp_enqueue_media();
			wp_enqueue_script( 'peptide-core-blog-admin', plugins_url( 'assets/blog-admin.js', PEPTIDE_CORE_FILE ), array( 'jquery' ), $ver( 'blog-admin.js' ), true );
			wp_localize_script(
				'peptide-core-blog-admin',
				'pcBlogAdmin',
				array(
					'choose' => __( 'Choose cover', 'peptide-core' ),
					'use'    => __( 'Use as cover', 'peptide-core' ),
				)
			);
		}
	}

	// ---------- 编辑博客 ----------

	public static function add_meta_boxes(): void {
		add_meta_box( 'pc_blog_settings', __( 'Blog settings', 'peptide-core' ), array( self::class, 'render_settings_box' ), 'post', 'side', 'high' );
		add_meta_box( 'pc_blog_seo', __( 'SEO', 'peptide-core' ), array( self::class, 'render_seo_box' ), 'post', 'normal', 'high' );
		add_meta_box( 'pc_blog_products', __( 'Products shown under the post', 'peptide-core' ), array( self::class, 'render_products_box' ), 'post', 'normal', 'default' );
	}

	public static function render_settings_box( \WP_Post $post ): void {
		wp_nonce_field( 'pc_blog_save', 'pc_blog_nonce' );
		$author = (string) get_post_meta( $post->ID, Blog::META_AUTHOR, true );
		echo '<p><label for="pc_blog_author"><strong>' . esc_html__( 'Author', 'peptide-core' ) . '</strong></label><br><input type="text" class="widefat" id="pc_blog_author" name="pc_blog_author" maxlength="63" value="' . esc_attr( $author ) . '" placeholder="' . esc_attr__( 'Enter the author name', 'peptide-core' ) . '"></p>';
		echo '<p class="description">' . esc_html__( 'Shown as the author on the storefront. Leave empty to use the account name. Pin a post with “Stick to the top of the blog”; the short description goes in the Excerpt box and the cover is the featured image.', 'peptide-core' ) . '</p>';
		/* translators: %d: number of views */
		echo '<p>' . esc_html( sprintf( __( 'Views: %d', 'peptide-core' ), (int) get_post_meta( $post->ID, Blog::META_VIEWS, true ) ) ) . '</p>';
		$url = 'publish' === $post->post_status ? (string) get_permalink( $post ) : home_url( '/' . Blog::BASE . '/' . ( $post->post_name ? $post->post_name : '…' ) . '/' );
		echo '<p>' . esc_html__( 'Address:', 'peptide-core' ) . ' <code>' . esc_html( $url ) . '</code></p>';
	}

	public static function render_seo_box( \WP_Post $post ): void {
		$own = get_post_meta( $post->ID, Blog::META_SEO, true );
		$set = Seo::clean_set( is_array( $own ) ? $own : array() );
		self::seo_fields( 'pc_blog_seo', $set, __( 'Leave empty to use the post title and short description. The link is the post’s slug (edit it next to the title); after a change the old link redirects to the new one.', 'peptide-core' ) );
	}

	/** SEO 三项（博客和博客专辑共用）。 */
	private static function seo_fields( string $name, array $set, string $note ): void {
		echo '<p class="description">' . esc_html( $note ) . '</p>';
		echo '<p><label for="' . esc_attr( $name ) . '_title"><strong>' . esc_html__( 'SEO title', 'peptide-core' ) . '</strong></label><br><input type="text" class="large-text" id="' . esc_attr( $name ) . '_title" name="' . esc_attr( $name ) . '[title]" maxlength="' . esc_attr( (string) Seo::MAX_TEXT ) . '" value="' . esc_attr( $set['title'] ) . '"></p>';
		echo '<p><label for="' . esc_attr( $name ) . '_desc"><strong>' . esc_html__( 'SEO description', 'peptide-core' ) . '</strong></label><br><textarea class="large-text" rows="3" id="' . esc_attr( $name ) . '_desc" name="' . esc_attr( $name ) . '[description]" maxlength="' . esc_attr( (string) Seo::MAX_TEXT ) . '">' . esc_textarea( $set['description'] ) . '</textarea></p>';
		echo '<p><label for="' . esc_attr( $name ) . '_keys"><strong>' . esc_html__( 'SEO keywords', 'peptide-core' ) . '</strong></label><br><input type="text" class="large-text" id="' . esc_attr( $name ) . '_keys" name="' . esc_attr( $name ) . '[keywords]" value="' . esc_attr( implode( ', ', $set['keywords'] ) ) . '" placeholder="' . esc_attr__( 'Separate keywords with commas', 'peptide-core' ) . '"></p>';
	}

	public static function render_products_box( \WP_Post $post ): void {
		$ids = array_map( 'intval', (array) get_post_meta( $post->ID, Blog::META_PRODUCTS, true ) );
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %d: maximum number of products */
				__( 'Customers see these products under the post and can open them from there. Up to %d products.', 'peptide-core' ),
				Blog::MAX_PRODUCTS
			)
		) . '</p>';
		echo '<input type="hidden" name="pc_blog_products_sent" value="1">';
		echo '<select class="wc-product-search" multiple="multiple" style="width:100%" name="pc_blog_products[]" data-placeholder="' . esc_attr__( 'Search for a product…', 'peptide-core' ) . '" data-action="woocommerce_json_search_products" data-limit="' . (int) Blog::MAX_PRODUCTS . '" aria-label="' . esc_attr__( 'Products shown under the post', 'peptide-core' ) . '">';
		foreach ( $ids as $id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( $product ) {
				echo '<option value="' . (int) $id . '" selected="selected">' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
			}
		}
		echo '</select>';
	}

	public static function save_meta( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['pc_blog_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pc_blog_nonce'] ) ), 'pc_blog_save' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$author = isset( $_POST['pc_blog_author'] ) ? sanitize_text_field( wp_unslash( $_POST['pc_blog_author'] ) ) : '';
		update_post_meta( $post_id, Blog::META_AUTHOR, mb_substr( $author, 0, 63 ) );
		$raw = isset( $_POST['pc_blog_seo'] ) && is_array( $_POST['pc_blog_seo'] ) ? wp_unslash( $_POST['pc_blog_seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Seo::clean_set 清理。
		update_post_meta( $post_id, Blog::META_SEO, Seo::clean_set( $raw ) );
		if ( isset( $_POST['pc_blog_products_sent'] ) ) {
			$ids = isset( $_POST['pc_blog_products'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['pc_blog_products'] ) ) : array();
			update_post_meta( $post_id, Blog::META_PRODUCTS, Blog::clean_products( $ids ) );
		}
		if ( '' === (string) get_post_meta( $post_id, Blog::META_VIEWS, true ) ) {
			update_post_meta( $post_id, Blog::META_VIEWS, 0 ); // 按访问量排序时，没有访问的也要列出来。
		}
	}

	// ---------- 博客管理列表 ----------

	public static function columns( $cols ): array {
		$cols = (array) $cols;
		return array(
			'cb'             => $cols['cb'] ?? '<input type="checkbox">',
			'title'          => __( 'Blog title', 'peptide-core' ),
			'pc_cover'       => __( 'Blog image', 'peptide-core' ),
			'pc_status'      => __( 'Status', 'peptide-core' ),
			'pc_sticky'      => __( 'Pinned', 'peptide-core' ),
			'categories'     => __( 'Blog collections', 'peptide-core' ),
			'pc_views'       => __( 'Visit count', 'peptide-core' ),
			'pc_modified'    => __( 'Last modified', 'peptide-core' ),
		);
	}

	public static function column( string $col, int $post_id ): void {
		if ( 'pc_cover' === $col ) {
			$img = get_the_post_thumbnail( $post_id, array( 60, 60 ), array( 'class' => 'pc-blog-thumb' ) );
			echo $img ? $img : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'pc_status' === $col ) {
			$status = (string) get_post_status( $post_id );
			$label  = 'publish' === $status ? __( 'Published', 'peptide-core' ) : ( 'future' === $status ? __( 'Scheduled', 'peptide-core' ) : __( 'Not published', 'peptide-core' ) );
			echo '<span class="pc-blog-status is-' . esc_attr( 'publish' === $status ? 'on' : 'off' ) . '">' . esc_html( $label ) . '</span>';
		} elseif ( 'pc_sticky' === $col ) {
			$on = is_sticky( $post_id );
			if ( current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_post', $post_id ) ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=pc_blog_sticky&post=' . $post_id ), 'pc_blog_sticky_' . $post_id );
				/* translators: %s: post title */
				$label = sprintf( $on ? __( 'Unpin %s', 'peptide-core' ) : __( 'Pin %s', 'peptide-core' ), get_the_title( $post_id ) );
				echo '<a class="pc-blog-switch' . ( $on ? ' is-on' : '' ) . '" href="' . esc_url( $url ) . '" role="switch" aria-checked="' . ( $on ? 'true' : 'false' ) . '" aria-label="' . esc_attr( $label ) . '"></a>';
			} else {
				echo '<span class="pc-blog-switch is-disabled' . ( $on ? ' is-on' : '' ) . '" role="img" aria-label="' . esc_attr( $on ? __( 'Pinned', 'peptide-core' ) : __( 'Not pinned', 'peptide-core' ) ) . '"></span>';
			}
		} elseif ( 'pc_views' === $col ) {
			echo esc_html( number_format_i18n( (int) get_post_meta( $post_id, Blog::META_VIEWS, true ) ) );
		} elseif ( 'pc_modified' === $col ) {
			echo esc_html( get_post_modified_time( 'Y-m-d H:i', false, $post_id, true ) );
		}
	}

	public static function sortable( $cols ): array {
		$cols                = (array) $cols;
		$cols['pc_views']    = 'pc_views';
		$cols['pc_modified'] = 'modified';
		return $cols;
	}

	public static function sort_by_views( \WP_Query $q ): void {
		if ( is_admin() && $q->is_main_query() && 'post' === $q->get( 'post_type' ) && 'pc_views' === $q->get( 'orderby' ) ) {
			$q->set( 'meta_key', Blog::META_VIEWS ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$q->set( 'orderby', 'meta_value_num' );
		}
	}

	/** 博客管理列表上方：导出 / 导入博客（和 YY 一样）。 */
	public static function list_toolbar(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-post' !== $screen->id || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$result = get_transient( 'pc_blog_import_' . get_current_user_id() );
		if ( is_array( $result ) ) {
			delete_transient( 'pc_blog_import_' . get_current_user_id() );
			/* translators: 1: posts created, 2: posts updated */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Import finished: %1$d created, %2$d updated.', 'peptide-core' ), (int) $result[0], (int) $result[1] ) ) . '</p>';
			foreach ( array_slice( (array) $result[2], 0, 20 ) as $err ) {
				echo '<p class="pc-blog-import-error">' . esc_html( (string) $err ) . '</p>';
			}
			echo '</div>';
		}
		?>
		<div class="pc-blog-toolbar">
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pc_blog_export' ), 'pc_blog_export' ) ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e( 'Export blog posts', 'peptide-core' ); ?></a>
			<?php if ( current_user_can( 'publish_posts' ) ) : ?>
				<details class="pc-blog-import">
					<summary class="button"><span class="dashicons dashicons-upload" aria-hidden="true"></span><?php esc_html_e( 'Import blog posts', 'peptide-core' ); ?></summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="pc_blog_import">
						<?php wp_nonce_field( 'pc_blog_import' ); ?>
						<p class="description"><?php esc_html_e( 'CSV with the same columns as the export. Posts with the same slug are updated, others are created. Covers are matched to images already in the media library.', 'peptide-core' ); ?></p>
						<p>
							<label class="screen-reader-text" for="pc-blog-csv"><?php esc_html_e( 'CSV file', 'peptide-core' ); ?></label>
							<input type="file" id="pc-blog-csv" name="csv" accept=".csv,text/csv" required>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'peptide-core' ); ?></button>
						</p>
					</form>
				</details>
			<?php endif; ?>
		</div>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			// 和 YY 一样放在标题旁边（这个钩子在标题前面输出）。
			var bar = document.querySelector( '.pc-blog-toolbar' );
			var anchor = document.querySelector( '.wrap .page-title-action' );
			if ( bar && anchor ) {
				anchor.insertAdjacentElement( 'afterend', bar );
				bar.classList.add( 'is-inline' );
			}
		} );
		</script>
		<?php
	}

	public static function handle_sticky(): void {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'pc_blog_sticky_' . $id );
		if ( ! $id || 'post' !== get_post_type( $id ) || ! current_user_can( 'edit_others_posts' ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		is_sticky( $id ) ? unstick_post( $id ) : stick_post( $id );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php' ) );
		exit;
	}

	public static function handle_export(): void {
		check_admin_referer( 'pc_blog_export' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="blog-posts-' . gmdate( 'Ymd' ) . '.csv"' );
		echo Blog::export_csv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV 下载。
		exit;
	}

	public static function handle_import(): void {
		check_admin_referer( 'pc_blog_import' );
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		$file = $_FILES['csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || (int) $file['size'] > 5 * MB_IN_BYTES || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			set_transient( 'pc_blog_import_' . get_current_user_id(), array( 0, 0, array( __( 'Choose a CSV file (up to 5 MB).', 'peptide-core' ) ) ), 300 );
		} else {
			set_transient( 'pc_blog_import_' . get_current_user_id(), Blog::import_csv( (string) file_get_contents( (string) $file['tmp_name'] ) ), 300 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		wp_safe_redirect( admin_url( 'edit.php' ) );
		exit;
	}

	// ---------- 博客专辑 ----------

	public static function term_add_fields(): void {
		wp_nonce_field( 'pc_blog_term', 'pc_blog_term_nonce' );
		?>
		<div class="form-field">
			<label><?php esc_html_e( 'Cover', 'peptide-core' ); ?></label>
			<?php self::cover_picker( 0 ); ?>
		</div>
		<div class="form-field">
			<label for="pc_term_top"><?php esc_html_e( 'Description above the collection', 'peptide-core' ); ?></label>
			<textarea id="pc_term_top" name="pc_term_top" rows="4"></textarea>
			<p><?php esc_html_e( 'Shown above the posts. HTML is allowed; edit the collection later for the rich text editor.', 'peptide-core' ); ?></p>
		</div>
		<div class="form-field">
			<label for="pc_term_bottom"><?php esc_html_e( 'Description below the collection', 'peptide-core' ); ?></label>
			<textarea id="pc_term_bottom" name="pc_term_bottom" rows="4"></textarea>
		</div>
		<div class="form-field pc-term-seo">
			<?php self::seo_fields( 'pc_term_seo', Seo::clean_set( array() ), __( 'Leave empty to use the collection name and description.', 'peptide-core' ) ); ?>
		</div>
		<?php
	}

	public static function term_edit_fields( \WP_Term $term ): void {
		$own = get_term_meta( $term->term_id, Blog::META_SEO, true );
		$set = Seo::clean_set( is_array( $own ) ? $own : array() );
		wp_nonce_field( 'pc_blog_term', 'pc_blog_term_nonce' );
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Cover', 'peptide-core' ); ?></th>
			<td><?php self::cover_picker( (int) get_term_meta( $term->term_id, Blog::TERM_COVER, true ) ); ?></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Description above the collection', 'peptide-core' ); ?></th>
			<td>
				<?php
				wp_editor(
					(string) get_term_meta( $term->term_id, Blog::TERM_TOP, true ),
					'pc_term_top',
					array(
						'textarea_name' => 'pc_term_top',
						'textarea_rows' => 6,
						'media_buttons' => true,
					)
				);
				?>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Description below the collection', 'peptide-core' ); ?></th>
			<td>
				<?php
				wp_editor(
					(string) get_term_meta( $term->term_id, Blog::TERM_BOTTOM, true ),
					'pc_term_bottom',
					array(
						'textarea_name' => 'pc_term_bottom',
						'textarea_rows' => 6,
						'media_buttons' => true,
					)
				);
				?>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'SEO', 'peptide-core' ); ?></th>
			<td class="pc-term-seo">
				<?php self::seo_fields( 'pc_term_seo', $set, __( 'Leave empty to use the collection name and description.', 'peptide-core' ) ); ?>
				<p><?php esc_html_e( 'Address:', 'peptide-core' ); ?> <code><?php echo esc_html( (string) get_term_link( $term ) ); ?></code></p>
			</td>
		</tr>
		<?php
	}

	private static function cover_picker( int $image_id ): void {
		$url = $image_id ? (string) wp_get_attachment_image_url( $image_id, 'medium' ) : '';
		?>
		<div class="pc-cover-picker">
			<input type="hidden" name="pc_term_cover" value="<?php echo $image_id ? (int) $image_id : ''; ?>">
			<span class="pc-cover-picker__preview"><?php echo '' !== $url ? '<img src="' . esc_url( $url ) . '" alt="">' : ''; ?></span>
			<button type="button" class="button pc-cover-picker__choose"><?php esc_html_e( 'Choose cover', 'peptide-core' ); ?></button>
			<button type="button" class="button-link pc-cover-picker__remove"<?php echo '' === $url ? ' hidden' : ''; ?>><?php esc_html_e( 'Remove cover', 'peptide-core' ); ?></button>
			<p class="description"><?php esc_html_e( 'Common ratios 4:3, 3:2, 2:1 or 1:1, at least 1000 px wide.', 'peptide-core' ); ?></p>
		</div>
		<?php
	}

	public static function save_term( int $term_id ): void {
		if ( ! isset( $_POST['pc_blog_term_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pc_blog_term_nonce'] ) ), 'pc_blog_term' ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		$cover = isset( $_POST['pc_term_cover'] ) ? absint( $_POST['pc_term_cover'] ) : 0;
		if ( $cover && wp_attachment_is_image( $cover ) ) {
			update_term_meta( $term_id, Blog::TERM_COVER, $cover );
		} else {
			delete_term_meta( $term_id, Blog::TERM_COVER );
		}
		foreach ( array( 'pc_term_top' => Blog::TERM_TOP, 'pc_term_bottom' => Blog::TERM_BOTTOM ) as $field => $key ) {
			$html = isset( $_POST[ $field ] ) ? wp_kses_post( wp_unslash( $_POST[ $field ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_kses_post 清理。
			update_term_meta( $term_id, $key, $html );
		}
		$raw = isset( $_POST['pc_term_seo'] ) && is_array( $_POST['pc_term_seo'] ) ? wp_unslash( $_POST['pc_term_seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		update_term_meta( $term_id, Blog::META_SEO, Seo::clean_set( $raw ) );
		update_term_meta( $term_id, Blog::TERM_MODIFIED, time() );
		if ( '' === (string) get_term_meta( $term_id, Blog::META_VIEWS, true ) ) {
			update_term_meta( $term_id, Blog::META_VIEWS, 0 );
		}
	}

	public static function term_columns( $cols ): array {
		$cols = (array) $cols;
		return array(
			'cb'          => $cols['cb'] ?? '<input type="checkbox">',
			'pc_cover'    => __( 'Collection image', 'peptide-core' ),
			'name'        => __( 'Collection name', 'peptide-core' ),
			'posts'       => __( 'Blog posts', 'peptide-core' ),
			'pc_views'    => __( 'Visit count', 'peptide-core' ),
			'pc_modified' => __( 'Modified', 'peptide-core' ),
		);
	}

	public static function term_column( $out, $col, $term_id ) {
		$term_id = (int) $term_id;
		if ( 'pc_cover' === $col ) {
			$url = Blog::term_cover( $term_id, 'thumbnail' );
			return '' !== $url ? '<img class="pc-blog-thumb" src="' . esc_url( $url ) . '" alt="">' : '—';
		}
		if ( 'pc_views' === $col ) {
			return esc_html( number_format_i18n( (int) get_term_meta( $term_id, Blog::META_VIEWS, true ) ) );
		}
		if ( 'pc_modified' === $col ) {
			$time = (int) get_term_meta( $term_id, Blog::TERM_MODIFIED, true );
			return $time ? esc_html( wp_date( 'Y-m-d H:i', $time ) ) : '—';
		}
		return $out;
	}

	public static function term_sortable( $cols ): array {
		$cols          = (array) $cols;
		$cols['posts'] = 'count';
		return $cols;
	}
}
