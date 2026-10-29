<?php
namespace PeptideCore\Admin;

use PeptideCore\Reviews;
use PeptideCore\Review_Replies;
use PeptideCore\Modules\Commerce_Blocks;

defined( 'ABSPATH' ) || exit;

final class Reviews_Page {
	public const SLUG = 'pc-product-reviews';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 80 );
		add_action( 'admin_post_pc_review_save', array( self::class, 'save' ) );
		add_action( 'admin_post_pc_review_moderate', array( self::class, 'moderate' ) );
		add_action( 'admin_post_pc_review_reply', array( self::class, 'reply' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	public static function menu(): void {
		remove_submenu_page( 'edit.php?post_type=product', 'product-reviews' );
		$hook = add_submenu_page( 'edit.php?post_type=product', '商品评价', '商品评价', 'edit_products', self::SLUG, array( self::class, 'render' ) );
		if ( $hook ) { add_action( 'load-' . $hook, array( self::class, 'validate_context' ) ); }
	}

	public static function url( array $args = array() ): string { return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) ); }

	public static function assets(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) || ! Reviews::can_manage() ) { return; }
		wp_enqueue_style( 'pc-reviews-admin', plugins_url( 'assets/reviews-admin.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION );
		wp_enqueue_style( 'pc-review-replies-admin', plugins_url( 'assets/review-replies-admin.css', PEPTIDE_CORE_FILE ), array( 'pc-reviews-admin' ), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/review-replies-admin.css' ) );
		if ( ! empty( $_GET['product'] ) || ! empty( $_GET['edit'] ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'pc-reviews-admin', plugins_url( 'assets/reviews-admin.js', PEPTIDE_CORE_FILE ), array( 'media-editor' ), PEPTIDE_CORE_VERSION, true );
		}
	}

	private static function guard(): void { if ( ! Reviews::can_manage() ) { wp_die( '没有商品评价管理权限。', '', array( 'response' => 403 ) ); } }

	public static function save(): void {
		self::guard(); check_admin_referer( 'pc_review_save' );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( '请使用表单提交。', '', array( 'response' => 405 ) ); }
		$id = absint( $_POST['id'] ?? 0 ); $product = absint( $_POST['product'] ?? 0 );
		if ( $id && (int) get_comment( $id )?->comment_post_ID !== $product ) { wp_die( '评价不属于当前商品。', '', array( 'response' => 403 ) ); }
		$result = Reviews::save_admin( wp_unslash( $_POST ), $_FILES );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '评价未保存', array( 'response' => (int) ( $result->get_error_data()['status'] ?? 400 ), 'back_link' => true ) ); }
		wp_safe_redirect( self::url( array_merge( self::return_args( $product ), array( 'edit' => $result, 'saved' => '1' ) ) ) ); exit;
	}

	public static function moderate(): void {
		self::guard(); check_admin_referer( 'pc_review_moderate' );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( '请使用表单提交。', '', array( 'response' => 405 ) ); }
		$id = absint( $_POST['review'] ?? 0 ); $status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		if ( ! Reviews::is_review( $id ) || ! current_user_can( 'edit_comment', $id ) || ! in_array( $status, array( 'approve', 'hold', 'spam', 'trash' ), true ) ) { wp_die( '无效的评价操作。', '', array( 'response' => 403 ) ); }
		$product = (int) get_comment( $id )->comment_post_ID;
		if ( ! current_user_can( 'edit_post', $product ) || ( isset( $_POST['product'] ) && absint( $_POST['product'] ) !== $product ) ) { wp_die( '评价不属于当前商品。', '', array( 'response' => 403 ) ); }
		if ( ! wp_set_comment_status( $id, $status ) ) { wp_die( '评价状态未能保存，请重试。', '', array( 'response' => 500 ) ); }
		$history = get_comment_meta( $id, '_pc_review_changes', true ); $history = is_array( $history ) ? $history : array(); $history[] = array( 'at' => gmdate( 'c' ), 'by' => get_current_user_id(), 'action' => $status ); update_comment_meta( $id, '_pc_review_changes', array_slice( $history, -20 ) );
		wp_safe_redirect( self::url( array_merge( self::return_args( $product ), array( 'moderated' => '1' ) ) ) ); exit;
	}

	/** Separate intent: saving a buyer review or private note never publishes a reply. */
	public static function reply(): void {
		self::guard();
		$result = Review_Replies::change( wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '公开回复未保存', array( 'response' => (int) ( $result->get_error_data()['status'] ?? 400 ), 'back_link' => true ) ); }
		$product = (int) get_comment( $result )->comment_post_ID;
		$review = (int) get_comment( $result )->comment_parent;
		$action = sanitize_key( wp_unslash( $_POST['reply_action'] ?? '' ) );
		wp_safe_redirect( self::url( array_merge( self::return_args( $product ), array( 'edit' => $review, 'reply_saved' => $action ) ) ) . '#pc-review-replies' ); exit;
	}

	/** Return only known local filters; never accept an arbitrary redirect URL. */
	private static function return_args( int $product ): array {
		$args = array( 'product' => $product );
		$status = sanitize_key( wp_unslash( $_POST['return_status'] ?? 'all' ) );
		if ( in_array( $status, array( 'all', 'hold', 'approve', 'spam', 'trash' ), true ) ) { $args['status'] = $status; }
		$args['s'] = sanitize_text_field( wp_unslash( $_POST['return_search'] ?? '' ) );
		$args['paged'] = max( 1, absint( $_POST['return_paged'] ?? 1 ) );
		return $args;
	}

	private static function return_fields( string $status, string $search, int $page ): void {
		echo '<input type="hidden" name="return_status" value="' . esc_attr( $status ) . '"><input type="hidden" name="return_search" value="' . esc_attr( $search ) . '"><input type="hidden" name="return_paged" value="' . $page . '">';
	}

	public static function validate_context(): void { self::context(); }

	/** Validate before WordPress sends the page header, and also protect direct render calls. */
	private static function context(): array {
		self::guard();
		$id = absint( $_GET['edit'] ?? 0 ); $comment = $id ? get_comment( $id ) : null;
		if ( $id && ( ! Reviews::is_review( $comment ) || ! current_user_can( 'edit_comment', $id ) ) ) { wp_die( '找不到可编辑的商品评价。', '', array( 'response' => 404 ) ); }
		$product = absint( $_GET['product'] ?? 0 );
		if ( $product && 'product_variation' === get_post_type( $product ) ) { $product = (int) wp_get_post_parent_id( $product ); }
		if ( ! $product && ! isset( $_GET['product'] ) && $comment ) { $product = (int) $comment->comment_post_ID; }
		if ( ( isset( $_GET['product'] ) && ! $product ) || ( $product && ( 'product' !== get_post_type( $product ) || 'trash' === get_post_status( $product ) || ! current_user_can( 'edit_post', $product ) ) ) || ( $comment && (int) $comment->comment_post_ID !== $product ) ) { wp_die( '找不到可管理的商品。', '', array( 'response' => 404 ) ); }
		return array( $product, $comment );
	}

	public static function render(): void {
		list( $product, $comment ) = self::context();
		echo '<div class="wrap pc-reviews-admin">';
		if ( ! $product ) {
			echo '<h1>商品评价</h1><p>选择商品，管理该商品下所有规格的评价。没有评价的商品也会显示在这里。</p>';
			self::catalog(); echo '</div>'; return;
		}
		echo '<p><a href="' . esc_url( self::url() ) . '">← 返回商品列表</a></p><h1 class="wp-heading-inline">' . esc_html( get_the_title( $product ) ) . '：商品评价</h1> <a class="page-title-action" href="' . esc_url( self::url( array( 'product' => $product, 'new' => '1' ) ) ) . '#pc-review-editor">新增评价</a><p>本页管理该商品下所有规格的评价。来源、内部备注和编辑记录仅后台可见，已购买标记由真实订单自动核验。</p>';
		if ( isset( $_GET['saved'] ) || isset( $_GET['moderated'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>评价已保存。</p></div>'; }
		$reply_notice = array( 'create' => '商家公开回复已发布。', 'edit' => '商家回复已保存。', 'trash' => '商家回复已移入回收站，可以恢复。', 'restore' => '商家回复已恢复到移入回收站前的状态。' )[ sanitize_key( wp_unslash( $_GET['reply_saved'] ?? '' ) ) ] ?? '';
		if ( $reply_notice ) { echo '<div class="notice notice-success is-dismissible" role="status"><p>' . esc_html( $reply_notice ) . '</p></div>'; }
		self::editor( $comment, $product );
		if ( $comment ) { self::reply_editor( $comment, $product ); }
		self::listing( $product ); echo '</div>';
	}

	private static function catalog(): void {
		global $wpdb;
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$statuses = array( 'draft', 'pending' );
		if ( current_user_can( 'edit_published_products' ) ) { $statuses = array_merge( $statuses, array( 'publish', 'future' ) ); }
		if ( current_user_can( 'edit_private_products' ) ) { $statuses[] = 'private'; }
		$args = array( 'post_type' => 'product', 'post_status' => $statuses, 'posts_per_page' => 20, 'paged' => $page, 'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ), 's' => $search, 'search_columns' => array( 'post_title' ) );
		if ( ! current_user_can( 'edit_others_products' ) ) { $args['author'] = get_current_user_id(); }
		$query = new \WP_Query( $args );
		$pages = max( 1, (int) $query->max_num_pages );
		if ( $page > $pages ) {
			$args['paged'] = 1; $query = new \WP_Query( $args ); $pages = max( 1, (int) $query->max_num_pages ); $page = min( $page, $pages );
			if ( $page > 1 ) { $args['paged'] = $page; $query = new \WP_Query( $args ); }
		}
		$ids = array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) ); $counts = array();
		if ( $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$counts = $wpdb->get_results( $wpdb->prepare( "SELECT comment_post_ID AS product, COUNT(*) AS total, SUM(comment_approved='1') AS approved, SUM(comment_approved='0') AS pending FROM {$wpdb->comments} WHERE comment_post_ID IN ($placeholders) AND comment_parent=0 AND comment_type IN ('','review') AND comment_approved IN ('0','1') GROUP BY comment_post_ID", $ids ), OBJECT_K );
		}
		echo '<form method="get" class="pc-review-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label>搜索商品<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="输入商品名称"></label><button class="button">搜索</button></form><p>共 ' . (int) $query->found_posts . ' 个商品</p><div class="pc-review-table pc-review-products"><table class="widefat striped"><thead><tr><th>商品</th><th>评价总数</th><th>已发布</th><th>待审核</th><th>操作</th></tr></thead><tbody>';
		foreach ( $query->posts as $post ) {
			$product = (int) $post->ID; if ( ! current_user_can( 'edit_post', $product ) ) { continue; }
			$wc = wc_get_product( $product ); $row = $counts[ $product ] ?? null;
			$url = self::url( array( 'product' => $product ) ); $status = get_post_status_object( $post->post_status );
			echo '<tr data-product-id="' . $product . '"><td><div class="pc-review-product-title">' . ( $wc ? $wc->get_image( array( 48, 48 ) ) : '' ) . '<div><strong><a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $product ) ) . '</a></strong><small>' . esc_html( $status ? $status->label : $post->post_status ) . '</small></div></div></td><td>' . (int) ( $row->total ?? 0 ) . '</td><td>' . (int) ( $row->approved ?? 0 ) . '</td><td>' . (int) ( $row->pending ?? 0 ) . '</td><td><a class="button" href="' . esc_url( $url ) . '">管理评价</a></td></tr>';
		}
		if ( ! $query->posts ) { echo '<tr><td colspan="5">没有找到符合条件的商品。</td></tr>'; }
		echo '</tbody></table></div>';
		self::pagination( $page, $pages, array( 's' => $search ) );
	}

	private static function editor( ?\WP_Comment $c, int $product ): void {
		$id = $c ? (int) $c->comment_ID : 0;
		$images = $c ? array_values( array_filter( array_map( 'absint', (array) get_comment_meta( $id, Commerce_Blocks::REVIEW_IMAGES, true ) ) ) ) : array(); $video = $c ? (int) get_comment_meta( $id, Commerce_Blocks::REVIEW_VIDEO, true ) : 0;
		$source = $c ? (string) get_comment_meta( $id, Reviews::SOURCE, true ) : 'store';
		echo '<details class="pc-review-editor" id="pc-review-editor"' . ( $c || isset( $_GET['new'] ) ? ' open' : '' ) . '><summary>' . ( $c ? '编辑评价 #' . $id : '新增评价' ) . '</summary><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pc_review_save"><input type="hidden" name="id" value="' . $id . '">';
		wp_nonce_field( 'pc_review_save' );
		self::return_fields( sanitize_key( wp_unslash( $_GET['status'] ?? 'all' ) ), sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ), max( 1, absint( $_GET['paged'] ?? 1 ) ) );
		echo '<input type="hidden" name="product" value="' . $product . '"><div class="pc-review-grid"><label>商品<span class="pc-review-readonly">' . esc_html( get_the_title( $product ) ) . '</span></label><label>评价人<input name="author" required maxlength="100" value="' . esc_attr( $c ? $c->comment_author : '' ) . '"></label><label>邮箱（不公开，可选）<input type="email" name="email" value="' . esc_attr( $c ? $c->comment_author_email : '' ) . '"></label><label>星级<select name="rating" required>';
		$rating = $c ? (int) get_comment_meta( $id, 'rating', true ) : 5;
		foreach ( array( 5, 4, 3, 2, 1 ) as $value ) { echo '<option value="' . $value . '"' . selected( $rating, $value, false ) . '>' . $value . ' 星</option>'; }
		echo '</select></label><label>日期（店铺时区）<input type="datetime-local" name="date" value="' . esc_attr( str_replace( ' ', 'T', substr( $c ? $c->comment_date : current_time( 'mysql' ), 0, 16 ) ) ) . '"></label><label>状态<select name="status">';
		foreach ( array( '0' => '待审核', '1' => '已发布', 'spam' => '垃圾评价', 'trash' => '回收站' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $c ? $c->comment_approved : '0', (string) $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><label>来源';
		if ( $c ) { echo '<span class="pc-review-readonly">' . esc_html( array( 'buyer' => '买家提交', 'store' => '店铺录入', 'imported' => '历史导入' )[ $source ] ?? '原有评价' ) . '（来源保留）</span>'; }
		else { echo '<select name="source"><option value="store">店铺录入</option><option value="imported">历史导入</option></select>'; }
		echo '</label><label>精选<label class="pc-review-inline"><input type="checkbox" name="featured" value="1"' . checked( '1', $c ? get_comment_meta( $id, Commerce_Blocks::REVIEW_FEATURED, true ) : '', false ) . '> 在精选评价积木中展示</label></label></div><label>评价内容<textarea name="content" rows="5" maxlength="20000" required>' . esc_textarea( $c ? $c->comment_content : '' ) . '</textarea></label><label>内部来源备注（不公开）<textarea name="reference" rows="2">' . esc_textarea( $c ? get_comment_meta( $id, '_pc_review_reference', true ) : '' ) . '</textarea></label><p class="description">可记录原平台链接或资料位置；不要把测试内容作为真实买家反馈发布。</p>';
		echo '<fieldset class="pc-review-media-editor"><legend>图片（最多 5 张）</legend><input type="hidden" name="image_ids" value="' . esc_attr( implode( ',', array_map( 'intval', $images ) ) ) . '"><div class="pc-review-thumbnails" data-pc-review-images>';
		foreach ( $images as $image ) { echo '<span data-media-id="' . (int) $image . '">' . wp_get_attachment_image( $image, 'thumbnail' ) . '<button type="button" class="button-link" data-pc-remove-image>移除</button></span>'; }
		echo '</div><button type="button" class="button" data-pc-choose-images>从素材库选择</button><label>或上传新图片<input type="file" name="pc_review_photos[]" multiple accept="image/jpeg,image/png,image/webp"></label></fieldset><fieldset><legend>视频（MP4 / WebM，最多 25 MB）</legend><input type="hidden" name="video_id" value="' . $video . '"><div data-pc-review-video>';
		if ( $video ) { echo '<video controls preload="metadata" src="' . esc_url( wp_get_attachment_url( $video ) ) . '"></video>'; }
		echo '</div><button type="button" class="button" data-pc-choose-video>从素材库选择视频</button> <button type="button" class="button" data-pc-remove-video>移除视频</button><label>或上传新视频<input type="file" name="pc_review_video" accept="video/mp4,video/webm"></label></fieldset><p class="description">新上传文件在审核前仅评价提交者和有商品权限的管理员可访问。前台只展示已审核且商品公开的评价。</p><p><button type="submit" class="button button-primary">保存评价</button> <a class="button" href="' . esc_url( self::url( array( 'product' => $product ) ) ) . '">返回评价列表</a></p>';
		if ( $c ) { echo '<p>购买核验：' . ( wc_review_is_from_verified_owner( $id ) ? '存在匹配的真实订单' : '未核验购买' ) . '；不能手动勾选为已购买。</p>'; }
		$history = $c ? get_comment_meta( $id, '_pc_review_changes', true ) : array();
		if ( is_array( $history ) && $history ) { echo '<details class="pc-review-history"><summary>编辑与审核记录</summary><ul>'; foreach ( array_reverse( $history ) as $entry ) { $actor = get_userdata( (int) ( $entry['by'] ?? 0 ) ); $label = array( 'create' => '新增', 'edit' => '编辑', 'approve' => '审核通过', 'hold' => '转待审', 'spam' => '标为垃圾', 'trash' => '移到回收站' )[ $entry['action'] ?? '' ] ?? '保存'; echo '<li>' . esc_html( ( $entry['at'] ?? '' ) . ' UTC · ' . ( $actor ? $actor->display_name : '#' . (int) ( $entry['by'] ?? 0 ) ) . ' · ' . $label ) . '</li>'; } echo '</ul></details>'; }
		echo '</form></details>';
	}

	private static function listing( int $product ): void {
		global $wpdb;
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? 'all' ) ); if ( ! in_array( $status, array( 'all', 'hold', 'approve', 'spam', 'trash' ), true ) ) { $status = 'all'; }
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$args = array( 'pc_review_product_list' => true, 'post_type' => 'product', 'post_id' => $product, 'parent' => 0, 'status' => $status, 'search' => $search, 'number' => 20, 'orderby' => array( 'comment_date_gmt' => 'DESC', 'comment_ID' => 'DESC' ) );
		// WP strips an empty type__in value. Include legacy empty reviews without admitting ordinary "comment" rows.
		$types = static function ( array $clauses, \WP_Comment_Query $query ) use ( $wpdb, $product ): array {
			if ( ! empty( $query->query_vars['pc_review_product_list'] ) && (int) $query->query_vars['post_id'] === $product ) { $clauses['where'] .= " AND {$wpdb->comments}.comment_type IN ('','review')"; }
			return $clauses;
		};
		add_filter( 'comments_clauses', $types, 10, 2 );
		try {
			$total = (int) get_comments( array_merge( $args, array( 'count' => true, 'number' => 0 ) ) ); $pages = max( 1, (int) ceil( $total / 20 ) ); $page = min( $page, $pages ); $args['offset'] = ( $page - 1 ) * 20; $rows = get_comments( $args );
		} finally { remove_filter( 'comments_clauses', $types, 10 ); }
		echo '<h2>评价列表</h2><form method="get" class="pc-review-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="product" value="' . $product . '"><label>状态<select name="status">';
		foreach ( array( 'all' => '全部', 'hold' => '待审核', 'approve' => '已发布', 'spam' => '垃圾评价', 'trash' => '回收站' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><label>搜索评价<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="评价人 / 邮箱 / 内容"></label><button class="button">筛选</button></form><p>共 ' . (int) $total . ' 条</p><div class="pc-review-table"><table class="widefat striped"><thead><tr><th>评价</th><th>星级 / 来源</th><th>状态 / 操作</th></tr></thead><tbody>';
		foreach ( $rows as $c ) {
			$id = (int) $c->comment_ID;
			echo '<tr data-review-id="' . $id . '"><td><strong>' . esc_html( $c->comment_author ) . '</strong><br><small>' . esc_html( $c->comment_date ) . '</small><p>' . esc_html( wp_trim_words( $c->comment_content, 40 ) ) . '</p>' . Reviews::media_html( $c ) . '</td><td>' . str_repeat( '★', max( 0, min( 5, (int) get_comment_meta( $id, 'rating', true ) ) ) ) . Reviews::source_html( $c, true ) . ( wc_review_is_from_verified_owner( $id ) ? '<small>已核验购买</small>' : '' ) . '</td><td>' . esc_html( array( '0' => '待审核', '1' => '已发布', 'spam' => '垃圾评价', 'trash' => '回收站' )[ $c->comment_approved ] ?? $c->comment_approved ) . '<p><a class="button" href="' . esc_url( self::url( array( 'product' => $product, 'edit' => $id, 'status' => $status, 's' => $search, 'paged' => $page ) ) ) . '#pc-review-editor">编辑</a></p>';
			echo '<p><a class="button" href="' . esc_url( self::url( array( 'product' => $product, 'edit' => $id, 'status' => $status, 's' => $search, 'paged' => $page ) ) ) . '#pc-review-replies">公开回复</a></p>';
			foreach ( array( 'approve' => '审核通过', 'hold' => '转待审', 'spam' => '标为垃圾', 'trash' => '移到回收站' ) as $value => $label ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-review-status-form"><input type="hidden" name="action" value="pc_review_moderate"><input type="hidden" name="product" value="' . $product . '"><input type="hidden" name="review" value="' . $id . '"><input type="hidden" name="status" value="' . esc_attr( $value ) . '">' . wp_nonce_field( 'pc_review_moderate', '_wpnonce', false, false ); self::return_fields( $status, $search, $page ); echo '<button class="button-link">' . esc_html( $label ) . '</button></form>'; }
			echo '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="3">这个商品暂无符合条件的评价，可点击上方“新增评价”。</td></tr>'; }
		echo '</tbody></table></div>';
		self::pagination( $page, $pages, array( 'status' => $status, 's' => $search, 'product' => $product ) );
	}

	/** Sibling forms, outside the review editor and table moderation forms. */
	private static function reply_editor( \WP_Comment $review, int $product ): void {
		$id = (int) $review->comment_ID;
		$rows = Review_Replies::manageable_for( $id );
		$allowed = '1' === (string) $review->comment_approved;
		$version = Review_Replies::version( $id );
		echo '<section id="pc-review-replies" class="pc-review-replies" aria-labelledby="pc-review-replies-heading"><h2 id="pc-review-replies-heading">商家公开回复</h2><p class="description">此处的回复会公开展示，与上方的内部备注分开。精选评价积木展示该评价最新一条已发布商家回复。</p>';
		if ( ! $allowed ) { echo '<p class="pc-reply-state">请先审核通过这条买家评价，再发布、编辑或恢复公开回复。</p>'; }
		if ( ! $rows ) { echo '<p class="pc-reply-empty">这条评价还没有商家公开回复。需要回复时，请在下方填写正文并单独发布。</p>'; }
		foreach ( $rows as $row ) {
			$reply_id = (int) $row->comment_ID;
			$status = (string) $row->comment_approved;
			$editable = $allowed && in_array( $status, array( '0', '1' ), true );
			echo '<div class="pc-reply-entry"><div class="pc-reply-meta"><strong>回复 #' . $reply_id . '</strong><span>' . esc_html( array( '1' => '已发布 · 公开展示', '0' => '待审核 · 不公开', 'trash' => '回收站 · 不公开', 'spam' => '垃圾回复 · 不公开' )[ $status ] ?? '不公开' ) . '</span><time>' . esc_html( $row->comment_date ) . '</time></div>';
			if ( $allowed ) {
				self::reply_form_start( $id, $product, $reply_id, $version );
				if ( $editable ) { echo '<label for="pc-reply-content-' . $reply_id . '">回复正文</label><textarea id="pc-reply-content-' . $reply_id . '" name="reply_content" rows="3" maxlength="20000" required>' . esc_textarea( $row->comment_content ) . '</textarea>'; }
				else { echo '<div class="pc-reply-body">' . wp_kses_post( wpautop( $row->comment_content ) ) . '</div>'; }
				echo '<div class="pc-reply-actions">';
				if ( $editable ) { echo '<button type="submit" class="button button-primary" name="reply_action" value="edit">保存回复</button>'; }
				if ( 'trash' === $status ) { echo '<button type="submit" class="button" name="reply_action" value="restore">恢复回复</button><span class="description">恢复到回收前状态；已发布回复将再次公开。</span>'; }
				elseif ( defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS ) { echo '<button type="submit" class="button" name="reply_action" value="trash" formnovalidate>移入回收站</button>'; }
				echo '</div></form>';
			} else { echo '<div class="pc-reply-body">' . wp_kses_post( wpautop( $row->comment_content ) ) . '</div>'; }
			echo '</div>';
		}
		if ( $allowed ) {
			self::reply_form_start( $id, $product, 0, $version );
			echo '<h3>新增公开回复</h3><p class="description">将以当前登录账户的商家身份发布。仅保存上方买家评价不会发布这里的内容。</p><input type="hidden" name="submission" value="' . esc_attr( wp_generate_uuid4() ) . '"><label for="pc-reply-content-new">回复正文</label><textarea id="pc-reply-content-new" name="reply_content" rows="4" maxlength="20000" required aria-describedby="pc-reply-public-hint"></textarea><p id="pc-reply-public-hint" class="description">点击发布后，商品与评价公开时买家即可看到这条回复。支持基本文字格式；请勿填写内部备注或客户隐私资料。</p><button type="submit" class="button button-primary" name="reply_action" value="create">发布公开回复</button></form>';
		}
		echo '</section>';
	}

	private static function reply_form_start( int $review, int $product, int $reply, string $version ): void {
		echo '<form method="post" class="pc-reply-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pc_review_reply"><input type="hidden" name="review" value="' . $review . '"><input type="hidden" name="product" value="' . $product . '"><input type="hidden" name="reply" value="' . $reply . '"><input type="hidden" name="thread_version" value="' . esc_attr( $version ) . '">';
		wp_nonce_field( 'pc_review_reply_' . $review );
		self::return_fields( sanitize_key( wp_unslash( $_GET['status'] ?? 'all' ) ), sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ), max( 1, absint( $_GET['paged'] ?? 1 ) ) );
	}

	private static function pagination( int $page, int $pages, array $args ): void {
		echo '<div class="tablenav"><div class="tablenav-pages">';
		if ( $page > 1 ) { echo '<a class="button" href="' . esc_url( self::url( array_merge( $args, array( 'paged' => $page - 1 ) ) ) ) . '">上一页</a> '; }
		echo (int) $page . ' / ' . (int) $pages;
		if ( $page < $pages ) { echo ' <a class="button" href="' . esc_url( self::url( array_merge( $args, array( 'paged' => $page + 1 ) ) ) ) . '">下一页</a>'; }
		echo '</div></div>';
	}
}
