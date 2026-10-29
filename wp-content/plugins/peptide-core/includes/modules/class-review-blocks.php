<?php
/** Public review blocks share the store's native Woo review records and moderation. */
namespace PeptideCore\Modules;

use PeptideCore\Reviews;

defined( 'ABSPATH' ) || exit;

final class Review_Blocks {

	private const TYPES = array( 'text_reviews', 'image_reviews', 'video_reviews', 'featured_reviews' );
	private const EFFECTS = array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble', 'pulse', 'shine' );
	private const MORE_ACTION = 'pc_review_block_more';
	private const MORE_BATCH = 12;
	private const MORE_PAGES = 10;
	private const MORE_SCAN = 600;
	private const TOKEN_LIFETIME = 3600;
	private const TOKEN_CONTEXT = 'pc-review-block-more:v1:';
	private const RECORD_SCAN = 10000;
	private const INDEX_SCAN = 15000;
	private const INDEX_PAGES = 1000;
	private const INDEX_CURSOR_SCAN = 240;
	private const INDEX_STATS_SCAN = 600;
	private const INDEX_CURSOR_CONTEXT = 'pc-review-index-cursor:v1:';

	public static function register_hooks(): void {
		add_action( 'wp_ajax_' . self::MORE_ACTION, array( self::class, 'more' ) );
		add_action( 'wp_ajax_nopriv_' . self::MORE_ACTION, array( self::class, 'more' ) );
	}

	public static function definitions( array $definitions ): array {
		foreach ( self::TYPES as $type ) {
			if ( ! isset( $definitions[ $type ] ) ) { continue; }
			foreach ( $definitions[ $type ]['settings'] as &$field ) {
				if ( 'description' === $field['key'] ) { $field['uiHidden'] = true; }
				if ( 'columns' === $field['key'] ) { $field['ui'] = 'range'; }
				if ( 'limit' === $field['key'] ) { $field['label'] = '评论总数'; $field['show_if'] = array( 'reviewOptionsMode' => 'custom' ); }
				$field['panel'] = in_array( $field['key'], array( 'columns', 'limit' ), true ) ? 'layout' : 'content';
			}
			unset( $field );
			$definitions[ $type ]['settings'] = array_merge( $definitions[ $type ]['settings'], array(
				Fields::f( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'panel' => 'content' ) ),
				Fields::f( 'select', 'reviewSource', '评论范围', 'all', array( 'panel' => 'source', 'options' => array( 'all' => '所有商品', 'current' => '当前商品', 'product' => '指定商品', 'current_collection' => '当前专辑' ), 'help' => '没有符合条件的已审核评论时自动隐藏。当前商品或专辑只在对应详情页生效。' ) ),
				Fields::f( 'product', 'reviewProduct', '指定商品', null, array( 'panel' => 'source', 'show_if' => array( 'reviewSource' => 'product' ), 'help' => '规格统一使用主商品的评论。' ) ),
				Fields::f( 'select', 'reviewOptionsMode', '显示配置', 'inherit', array( 'panel' => 'layout', 'options' => array( 'inherit' => '沿用全局评论设置', 'custom' => '独立设置此积木' ), 'help' => '独立设置使用本积木的数量、排列、排序和星级；沿用全局保留已有评论设置的行为。' ) ),
				Fields::f( 'select', 'mobileLayout', '移动端排版样式', 'carousel', array( 'panel' => 'layout', 'group' => 'layout', 'show_if' => array( 'reviewOptionsMode' => 'custom' ), 'options' => array( 'grid' => '竖向平铺', 'carousel' => '横向切换' ) ) ),
				Fields::f( 'select', 'reviewOrder', '评论时间', 'newest', array( 'panel' => 'filter', 'show_if' => array( 'reviewOptionsMode' => 'custom' ), 'options' => array( 'newest' => '最新评论', 'oldest' => '最早评论' ) ) ),
				Fields::f( 'select', 'minimumRating', '评论星级', '4', array( 'panel' => 'filter', 'show_if' => array( 'reviewOptionsMode' => 'custom' ), 'options' => array( '5' => '五星', '4' => '四星及以上', '3' => '三星及以上', '2' => '二星及以上', '1' => '一星及以上' ) ) ),
				Fields::f( 'select', 'viewMoreMode', '查看更多', 'none', array( 'panel' => 'button', 'group' => 'button', 'options' => array( 'none' => '不显示', 'auto' => '按评论范围显示', 'index' => '全站评价页' ), 'help' => '按范围模式仅在还有符合条件的评论时显示；商品范围进入真实商品评价，其余范围打开范围内评价。全站评价页进入公开的全部评价页面。' ) ),
				Fields::f( 'text', 'viewMoreLabel', '查看更多文案', '', array( 'panel' => 'button', 'group' => 'button' ) ),
				Fields::f( 'select', 'buttonEffect', '按钮特效', 'none', array( 'panel' => 'button', 'group' => 'button', 'options' => array( 'none' => '无特效', 'bounce' => '跳动', 'rubberband' => '橡皮筋', 'shake' => '左右抖动', 'swing' => '摇摆', 'wobble' => '摇晃', 'pulse' => '呼吸', 'shine' => '流光' ) ) ),
				Fields::f( 'number', 'effectDurationMs', '按钮动画时间', 1000, array( 'panel' => 'button', 'group' => 'button', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 1000, 'max' => 5000, 'step' => 100 ) ),
				Fields::f( 'number', 'effectIntervalMs', '按钮动画间隔时间', 1500, array( 'panel' => 'button', 'group' => 'button', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 500, 'max' => 5000, 'step' => 100 ) ),
			) );
			$definitions[ $type ]['editorDefaults'] = array( 'title' => 'Customer Reviews', 'reviewOptionsMode' => 'custom', 'columns' => array( 'desktop' => 3, 'mobile' => 1 ), 'mobileLayout' => 'carousel', 'limit' => 6, 'reviewOrder' => 'oldest', 'minimumRating' => '4', 'viewMoreMode' => 'auto', 'viewMoreLabel' => 'View More' );
			$definitions[ $type ]['panels'] = array(
				array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
				array( 'id' => 'source', 'label' => '评论范围', 'initialOpen' => true, 'fields' => array( 'reviewSource', 'reviewProduct' ) ),
				array( 'id' => 'layout', 'label' => '评论排列', 'initialOpen' => true, 'fields' => array( 'reviewOptionsMode', 'columns', 'mobileLayout', 'limit' ) ),
				array( 'id' => 'filter', 'label' => '排序与星级', 'fields' => array( 'reviewOrder', 'minimumRating' ) ),
				array( 'id' => 'button', 'label' => '查看更多按钮', 'fields' => array( 'viewMoreMode', 'viewMoreLabel', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
				array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
			);
			if ( 'image_reviews' === $type ) {
				foreach ( $definitions[ $type ]['settings'] as &$field ) {
					if ( in_array( $field['key'], array( 'columns', 'mobileLayout', 'viewMoreMode', 'viewMoreLabel', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ), true ) ) { $field['uiHidden'] = true; }
					if ( 'limit' === $field['key'] ) { $field['label'] = '加载评论个数'; }
				}
				unset( $field );
				foreach ( array( 'cardBackground' => '卡片背景色', 'textColor' => '文字颜色', 'verifiedColor' => 'Verified标识颜色' ) as $key => $label ) { $definitions[ $type ]['settings'][] = Fields::f( 'color', $key, $label, '', array( 'panel' => 'style', 'group' => 'style' ) ); }
				$definitions[ $type ]['editorDefaults'] = array_merge( $definitions[ $type ]['editorDefaults'], array( 'title' => 'Customer Say!', 'limit' => 4, 'columns' => array( 'desktop' => 4, 'mobile' => 2 ), 'mobileLayout' => 'grid', 'viewMoreMode' => 'none', 'viewMoreLabel' => '', 'cardBackground' => '#ffffff', 'textColor' => '#222222', 'verifiedColor' => '#555555' ) );
				$definitions[ $type ]['panels'] = array(
					array( 'id' => 'content', 'label' => '标题设置', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
					array( 'id' => 'source', 'label' => '评论范围', 'initialOpen' => true, 'fields' => array( 'reviewSource', 'reviewProduct' ) ),
					array( 'id' => 'layout', 'label' => '加载设置', 'initialOpen' => true, 'fields' => array( 'reviewOptionsMode', 'limit' ) ),
					array( 'id' => 'filter', 'label' => '排序与星级', 'fields' => array( 'reviewOrder', 'minimumRating' ) ),
					array( 'id' => 'style', 'label' => '样式设置', 'initialOpen' => true, 'fields' => array( 'cardBackground', 'textColor', 'verifiedColor' ) ),
					array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
				);
			}
			if ( 'video_reviews' === $type ) {
				foreach ( $definitions[ $type ]['settings'] as &$field ) { if ( in_array( $field['key'], array( 'columns', 'mobileLayout' ), true ) ) { $field['uiHidden'] = true; } }
				unset( $field );
				$definitions[ $type ]['settings'][] = Fields::f( 'toggle', 'autoplay', '自动轮播', false, array( 'panel' => 'media', 'group' => 'play', 'show_if' => array( 'reviewOptionsMode' => 'custom' ), 'help' => '播放视频、触屏操作、键盘操作或按下暂停后停止自动切换；减少动画和页面不可见时不自动切换。' ) );
				$definitions[ $type ]['settings'][] = Fields::f( 'select', 'videoRatio', '视频比例', '16/9', array( 'panel' => 'media', 'group' => 'layout', 'show_if' => array( 'reviewOptionsMode' => 'custom' ), 'options' => array( '9/16' => '9:16', '16/9' => '16:9', '4/3' => '4:3', '1/1' => '1:1' ) ) );
				$definitions[ $type ]['editorDefaults'] = array_merge( $definitions[ $type ]['editorDefaults'], array( 'title' => 'Customer Reviews', 'limit' => 10, 'columns' => array( 'desktop' => 4, 'mobile' => 2 ), 'autoplay' => true, 'videoRatio' => '16/9' ) );
				$definitions[ $type ]['panels'] = array(
					array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
					array( 'id' => 'source', 'label' => '评论范围', 'initialOpen' => true, 'fields' => array( 'reviewSource', 'reviewProduct' ) ),
					array( 'id' => 'layout', 'label' => '评论加载', 'initialOpen' => true, 'fields' => array( 'reviewOptionsMode', 'limit' ) ),
					array( 'id' => 'media', 'label' => '视频展示', 'initialOpen' => true, 'fields' => array( 'autoplay', 'videoRatio' ) ),
					array( 'id' => 'filter', 'label' => '排序与星级', 'fields' => array( 'reviewOrder', 'minimumRating' ) ),
					array( 'id' => 'button', 'label' => '查看更多按钮', 'fields' => array( 'viewMoreMode', 'viewMoreLabel', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
					array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
				);
			}
			if ( 'featured_reviews' === $type ) {
				foreach ( $definitions[ $type ]['settings'] as &$field ) {
					if ( in_array( $field['key'], array( 'reviewSource', 'reviewProduct', 'reviewOptionsMode', 'columns', 'mobileLayout', 'limit', 'reviewOrder', 'minimumRating' ), true ) ) { $field['show_if'] = array_merge( $field['show_if'] ?? array(), array( 'featuredMode' => 'auto' ) ); }
				}
				unset( $field );
				$definitions[ $type ]['settings'][] = Fields::f( 'select', 'featuredMode', '精选方式', 'auto', array( 'panel' => 'selection', 'options' => array( 'manual' => '手动选择真实评价', 'auto' => '使用已标记的精选评价' ), 'help' => '手动选择按条目顺序展示已公开审核的评价；不可用的条目不会展示，也不会自动换成其他评价。' ) );
				foreach ( array( 'reviewBackground' => '背景颜色', 'headingColor' => '标题颜色', 'descriptionColor' => '描述颜色' ) as $key => $label ) { $definitions[ $type ]['settings'][] = Fields::f( 'color', $key, $label, '', array( 'panel' => 'style', 'group' => 'style' ) ); }
				$definitions[ $type ]['items'] = array( 'label' => '精选评论', 'fields' => array( Fields::f( 'record', 'review', '评论', null, array( 'resource' => 'review' ) ) ), 'default_count' => 1, 'min' => 0, 'max' => 100, 'title_field' => 'review', 'show_if' => array( 'featuredMode' => 'manual' ) );
				$definitions[ $type ]['editorDefaults'] = array_merge( $definitions[ $type ]['editorDefaults'], array( 'title' => 'Featured Reviews', 'featuredMode' => 'manual', 'limit' => 8, 'columns' => array( 'desktop' => 4, 'mobile' => 2 ), 'mobileLayout' => 'grid', 'viewMoreMode' => 'index', 'reviewBackground' => '#ffffff', 'headingColor' => '#171717', 'descriptionColor' => '#555555' ) );
				$definitions[ $type ]['panels'] = array(
					array( 'id' => 'selection', 'label' => '精选方式', 'initialOpen' => true, 'fields' => array( 'featuredMode' ) ),
					array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
					array( 'id' => 'style', 'label' => '颜色设置', 'initialOpen' => true, 'fields' => array( 'reviewBackground', 'headingColor', 'descriptionColor' ) ),
					array( 'id' => 'source', 'label' => '自动精选范围', 'fields' => array( 'reviewSource', 'reviewProduct' ) ),
					array( 'id' => 'layout', 'label' => '自动精选排列', 'fields' => array( 'reviewOptionsMode', 'columns', 'mobileLayout', 'limit' ) ),
					array( 'id' => 'filter', 'label' => '自动精选筛选', 'fields' => array( 'reviewOrder', 'minimumRating' ) ),
					array( 'id' => 'button', 'label' => '查看更多按钮', 'fields' => array( 'viewMoreMode', 'viewMoreLabel', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
					array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
				);
			}
		}
		return $definitions;
	}

	private static function parent_product( $id ): int {
		$id = is_scalar( $id ) ? absint( $id ) : 0;
		if ( $id && 'product_variation' === get_post_type( $id ) ) { $id = (int) wp_get_post_parent_id( $id ); }
		return $id && 'product' === get_post_type( $id ) ? $id : 0;
	}

	/** Null denotes the whole catalog, zero denotes an invalid or absent explicit binding. */
	private static function bound_product( array $settings ): ?int {
		$source = $settings['reviewSource'] ?? 'all';
		if ( 'all' === $source ) { return null; }
		if ( 'product' === $source ) { return self::parent_product( $settings['reviewProduct'] ?? 0 ); }
		if ( 'current' === $source ) {
			$object = get_queried_object();
			return $object instanceof \WP_Post ? self::parent_product( $object->ID ) : 0;
		}
		return 0;
	}

	/** Old modules keep global precedence; new custom modules explicitly own these display choices. */
	private static function options( array $settings, string $type = 'text_reviews' ): array {
		$options = Site_Design::review_settings();
		$options['featured_manual'] = false;
		$custom = 'custom' === ( $settings['reviewOptionsMode'] ?? 'inherit' );
		$options['limit'] = $custom ? max( 1, min( 24, (int) ( $settings['limit'] ?? 6 ) ) ) : ( $options['limit'] > 0 ? max( 1, min( 100, (int) $options['limit'] ) ) : max( 1, min( 24, (int) ( $settings['limit'] ?? 8 ) ) ) );
		if ( $custom ) {
			$options['order'] = in_array( $settings['reviewOrder'] ?? '', array( 'newest', 'oldest' ), true ) ? $settings['reviewOrder'] : 'oldest';
			$options['minimum_rating'] = max( 1, min( 5, (int) ( $settings['minimumRating'] ?? 4 ) ) );
		}
		$options['desktop_layout'] = ! $custom && 'carousel' === $options['layout'] ? 'carousel' : 'grid';
		$options['mobile_layout'] = $custom ? ( 'grid' === ( $settings['mobileLayout'] ?? 'carousel' ) ? 'grid' : 'carousel' ) : $options['desktop_layout'];
		if ( $custom && 'image_reviews' === $type ) { $options['mobile_layout'] = 'grid'; }
		if ( $custom && 'video_reviews' === $type ) {
			$options['desktop_layout'] = 'carousel'; $options['mobile_layout'] = 'carousel';
			$options['autoplay'] = (bool) ( $settings['autoplay'] ?? false );
			$options['video_ratio'] = in_array( $settings['videoRatio'] ?? '', array( '9/16', '16/9', '4/3', '1/1' ), true ) ? $settings['videoRatio'] : '16/9';
		}
		if ( 'featured_reviews' === $type && 'manual' === ( $settings['featuredMode'] ?? 'auto' ) ) {
			$options['featured_manual'] = true;
			$options['minimum_rating'] = 0; $options['order'] = 'manual'; $options['limit'] = 100;
			$options['desktop_layout'] = 'grid'; $options['mobile_layout'] = 'grid'; $options['autoplay'] = false;
		}
		$options['show_rating'] = $options['show_rating'] && ( ! function_exists( 'wc_review_ratings_enabled' ) || wc_review_ratings_enabled() );
		return $options;
	}

	/** Null means catalog-wide; an empty explicit/context scope must never become catalog-wide. */
	private static function product_scope( array $settings ): ?array {
		if ( 'current_collection' !== ( $settings['reviewSource'] ?? 'all' ) ) {
			$product = self::bound_product( $settings );
			return null === $product ? null : ( $product ? array( $product ) : array() );
		}
		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term || 'product_cat' !== $term->taxonomy ) { return array(); }
		$tax = array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array( (int) $term->term_id ), 'include_children' => true ) );
		if ( function_exists( 'wc_get_product_visibility_term_ids' ) ) {
			$visibility = wc_get_product_visibility_term_ids(); $excluded = array_filter( array( $visibility['exclude-from-catalog'] ?? 0, 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ? ( $visibility['outofstock'] ?? 0 ) : 0 ) );
			if ( $excluded ) { $tax[] = array( 'taxonomy' => 'product_visibility', 'field' => 'term_taxonomy_id', 'terms' => array_values( $excluded ), 'operator' => 'NOT IN' ); }
		}
		// Woo maintains _wc_review_count. No persistent cache or record writes are needed here.
		return array_map( 'intval', get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'has_password' => false, 'numberposts' => 50, 'fields' => 'ids', 'meta_key' => '_wc_review_count', 'orderby' => array( 'meta_value_num' => 'DESC', 'ID' => 'ASC' ), 'tax_query' => array_merge( array( 'relation' => 'AND' ), $tax ) ) ) );
	}

	/** A historical verified meta flag or typed email is never sufficient. */
	private static function verified( \WP_Comment $review ): bool {
		$user = $review->user_id ? get_userdata( (int) $review->user_id ) : false;
		return $user && 0 === strcasecmp( $user->user_email, $review->comment_author_email ) && (bool) wc_customer_bought_product( $user->user_email, $user->ID, $review->comment_post_ID );
	}

	private static function selected_ids( array $items ): array {
		$ids = array();
		foreach ( array_slice( $items, 0, 100 ) as $item ) {
			if ( ! is_array( $item ) || ( array_key_exists( 'enabled', $item ) && ! $item['enabled'] ) ) { continue; }
			$id = Records::sanitize( $item['settings']['review'] ?? null );
			if ( $id && ! in_array( $id, $ids, true ) ) { $ids[] = $id; }
		}
		return $ids;
	}

	private static function comment_filter(): \Closure {
		global $wpdb;
		return static function ( array $sql, \WP_Comment_Query $query ) use ( $wpdb ): array {
			if ( ! empty( $query->query_vars['pc_review_block'] ) ) { $sql['where'] .= " AND {$wpdb->comments}.comment_type IN ('','review') AND {$wpdb->posts}.post_password=''"; }
			if ( ! empty( $query->query_vars['pc_review_index_cursor'] ) ) {
				$order = $query->query_vars['pc_review_index_order']; $position = $query->query_vars['pc_review_index_position'] ?? null;
				$rank = "(SELECT pcm_rank.meta_value FROM {$wpdb->commentmeta} pcm_rank WHERE pcm_rank.comment_id={$wpdb->comments}.comment_ID AND pcm_rank.meta_key='rating' ORDER BY pcm_rank.meta_id ASC LIMIT 1)";
				$rank = "(CASE WHEN CHAR_LENGTH({$rank})=1 AND ASCII({$rank}) BETWEEN 49 AND 53 THEN ASCII({$rank})-48 ELSE 0 END)";
				$time_direction = 'oldest' === $order ? 'ASC' : 'DESC'; $time_compare = 'oldest' === $order ? '>' : '<';
				$sql['orderby'] = ( in_array( $order, array( 'highest', 'lowest' ), true ) ? $rank . ( 'highest' === $order ? ' DESC, ' : ' ASC, ' ) : '' ) . "{$wpdb->comments}.comment_date_gmt {$time_direction}, {$wpdb->comments}.comment_ID {$time_direction}";
				if ( $position ) {
					$time = $wpdb->prepare( "({$wpdb->comments}.comment_date_gmt {$time_compare} %s OR ({$wpdb->comments}.comment_date_gmt=%s AND {$wpdb->comments}.comment_ID {$time_compare} %d))", $position['date'], $position['date'], $position['id'] );
					$sql['where'] .= in_array( $order, array( 'highest', 'lowest' ), true ) ? $wpdb->prepare( " AND ({$rank} " . ( 'highest' === $order ? '<' : '>' ) . " %d OR ({$rank}=%d AND {$time}))", $position['rating'], $position['rating'] ) : ' AND ' . $time;
				}
			}
			return $sql;
		};
	}

	/** This gate is shared by automatic blocks, curated IDs, picker metadata and signed pagination. */
	private static function public_row( string $type, \WP_Comment $review, ?array $scope, array $options, int $as_of, bool $manual = false ): ?array {
		if ( ! Reviews::is_review( $review ) || '1' !== (string) $review->comment_approved ) { return null; }
		if ( null !== $scope && ! in_array( (int) $review->comment_post_ID, $scope, true ) ) { return null; }
		if ( $as_of && strtotime( $review->comment_date_gmt . ' UTC' ) > $as_of ) { return null; }
		$item = wc_get_product( $review->comment_post_ID );
		if ( ! $item || 'publish' !== $item->get_status() || ! $item->is_visible() || post_password_required( $review->comment_post_ID ) || '' !== (string) get_post_field( 'post_password', $review->comment_post_ID ) ) { return null; }
		$rating = ! empty( $options['normalize_rating'] ) ? self::index_rating( (int) $review->comment_ID ) : (int) get_comment_meta( $review->comment_ID, 'rating', true );
		if ( $rating < $options['minimum_rating'] || ( ! empty( $options['rating_exact'] ) && $rating !== (int) $options['rating_exact'] ) ) { return null; }
		$images = Commerce_Blocks::review_image_ids( $review->comment_ID ); $video = Commerce_Blocks::review_video_id( $review->comment_ID );
		$has_media = $images || $video;
		if ( ( 'with_media' === ( $options['media_filter'] ?? 'all' ) && ! $has_media ) || ( 'without_media' === ( $options['media_filter'] ?? 'all' ) && $has_media ) ) { return null; }
		if ( ( 'image_reviews' === $type && ! $images ) || ( 'video_reviews' === $type && ! $video ) || ( 'featured_reviews' === $type && ! $manual && '1' !== (string) get_comment_meta( $review->comment_ID, Commerce_Blocks::REVIEW_FEATURED, true ) ) ) { return null; }
		return compact( 'review', 'item', 'images', 'video' );
	}

	private static function manual_rows( array $ids, array $options ): array {
		if ( ! $ids ) { return array(); }
		$ids = array_slice( $ids, 0, 100 ); $rows = array(); $clauses = self::comment_filter();
		add_filter( 'comments_clauses', $clauses, 10, 2 );
		try {
			$reviews = get_comments( array( 'pc_review_block' => true, 'status' => 'approve', 'post_type' => 'product', 'post_status' => 'publish', 'parent' => 0, 'comment__in' => $ids, 'number' => count( $ids ), 'orderby' => 'comment_ID', 'order' => 'ASC' ) );
			foreach ( $reviews as $review ) {
				if ( ! $review instanceof \WP_Comment || ! in_array( (int) $review->comment_ID, $ids, true ) ) { continue; }
				$row = self::public_row( 'featured_reviews', $review, null, $options, 0, true );
				if ( $row ) { $rows[ (int) $review->comment_ID ] = $row; }
			}
		} finally { remove_filter( 'comments_clauses', $clauses, 10 ); }
		$ordered = array(); foreach ( $ids as $id ) { if ( isset( $rows[ $id ] ) ) { $ordered[] = $rows[ $id ]; } }
		return $ordered;
	}

	/** Editor metadata contains only public product title, actual rating, excerpt and the selected ID. */
	public static function record_options( array $ids, array $filters = array() ): array {
		if ( ( function_exists( 'wc_reviews_enabled' ) && ! wc_reviews_enabled() ) || ! function_exists( 'wc_get_product' ) ) { return array(); }
		$clean = array(); foreach ( array_slice( $ids, 0, 100 ) as $candidate ) { $id = Records::sanitize( $candidate ); if ( $id && ! in_array( $id, $clean, true ) ) { $clean[] = $id; } }
		$media = $filters['media'] ?? 'all'; $rating_filter = $filters['rating'] ?? 0;
		if ( ! in_array( $media, array( 'all', 'with_media', 'without_media' ), true ) || ( ! is_int( $rating_filter ) && ! is_string( $rating_filter ) ) || ! in_array( (string) $rating_filter, array( '0', '1', '2', '3', '4', '5' ), true ) ) { return array(); }
		$options = array();
		foreach ( self::manual_rows( $clean, array( 'minimum_rating' => 0 ) ) as $row ) {
			$review = $row['review']; $rating = self::index_rating( (int) $review->comment_ID );
			$has_media = ! empty( $row['images'] ) || ! empty( $row['video'] );
			if ( ( 'with_media' === $media && ! $has_media ) || ( 'without_media' === $media && $has_media ) || ( (int) $rating_filter && $rating !== (int) $rating_filter ) ) { continue; }
			$excerpt = mb_substr( trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $review->comment_content ), ENT_QUOTES, 'UTF-8' ) ) ?? '' ), 0, 160 );
			$title = $row['item']->get_name(); $id = (int) $review->comment_ID;
			$options[ $id ] = array( 'value' => (string) $id, 'label' => $title . ' · ' . ( $rating ? $rating . '星' : '未评分' ) . ' · ' . $excerpt, 'product_title' => $title, 'rating' => $rating, 'excerpt' => $excerpt );
		}
		return $options;
	}

	/** SKU/SPU is an exact product reference, never a comment/email/IP search term. */
	public static function record_search( string $term, int $page, array $filters ): array {
		$empty = array( 'options' => array(), 'more' => false );
		if ( $page < 1 || $page > 100 || ! function_exists( 'wc_get_product' ) || ( function_exists( 'wc_reviews_enabled' ) && ! wc_reviews_enabled() ) ) { return $empty; }
		$media = $filters['media'] ?? 'all'; $rating = $filters['rating'] ?? 0;
		if ( ! in_array( $media, array( 'all', 'with_media', 'without_media' ), true ) || ( ! is_int( $rating ) && ! is_string( $rating ) ) || ! in_array( (string) $rating, array( '0', '1', '2', '3', '4', '5' ), true ) ) { return $empty; }
		$args = array( 'pc_review_block' => true, 'status' => 'approve', 'post_type' => 'product', 'post_status' => 'publish', 'parent' => 0, 'number' => 50, 'offset' => 0, 'fields' => 'ids', 'orderby' => array( 'comment_date_gmt' => 'DESC', 'comment_ID' => 'DESC' ) );
		$term = trim( mb_substr( $term, 0, 100 ) );
		if ( '' !== $term ) {
			$id = function_exists( 'wc_get_product_id_by_sku' ) ? self::parent_product( wc_get_product_id_by_sku( $term ) ) : 0;
			$product = $id ? wc_get_product( $id ) : false;
			if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_visible() || '' !== (string) get_post_field( 'post_password', $id ) || post_password_required( $id ) ) { return $empty; }
			$args['post_id'] = $id;
		}
		if ( (int) $rating ) { $args['meta_query'] = array( array( 'key' => 'rating', 'value' => (int) $rating, 'compare' => '=', 'type' => 'NUMERIC' ) ); }
		if ( 'with_media' === $media ) { $args['meta_query'][] = array( 'relation' => 'OR', array( 'key' => Commerce_Blocks::REVIEW_IMAGES, 'value' => array( '', 'a:0:{}' ), 'compare' => 'NOT IN' ), array( 'key' => Commerce_Blocks::REVIEW_VIDEO, 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ) ); }
		$clauses = self::comment_filter(); add_filter( 'comments_clauses', $clauses, 10, 2 );
		$matching = array(); $seen = array(); $eligible = 0; $scanned = 0; $skip = ( $page - 1 ) * 20; $exhausted = false;
		try {
			do {
				$ids = array_map( 'intval', get_comments( $args ) ); $scanned += count( $ids );
				foreach ( self::record_options( $ids, $filters ) as $id => $option ) {
					if ( isset( $seen[ $id ] ) ) { continue; } $seen[ $id ] = true;
					if ( $eligible++ < $skip ) { continue; } $matching[] = $option;
					if ( count( $matching ) > 20 ) { break 2; }
				}
				$exhausted = count( $ids ) < $args['number']; $args['offset'] += $args['number'];
			} while ( ! $exhausted && $scanned < self::RECORD_SCAN );
		} finally { remove_filter( 'comments_clauses', $clauses, 10 ); }
		return array( 'options' => array_slice( $matching, 0, 20 ), 'more' => count( $matching ) > 20 && $page < 100, 'limited' => ! $exhausted && $scanned >= self::RECORD_SCAN );
	}

	/** Shared query construction; public renderers, bounded cursors and aggregates use the same native gate. */
	private static function query_args( string $type, ?array $scope, array $options, int $limit, int $as_of ): array {
		$order = in_array( $options['order'], array( 'oldest', 'rating_asc' ), true ) ? 'ASC' : 'DESC';
		$args = array( 'pc_review_block' => true, 'cache_domain' => 'pc-review-blocks-v2', 'status' => 'approve', 'post_type' => 'product', 'post_status' => 'publish', 'parent' => 0, 'number' => isset( $options['query_batch'] ) ? max( 20, min( 100, (int) $options['query_batch'] ) ) : min( 48, max( 20, $limit * 2 ) ), 'offset' => 0, 'orderby' => array( 'comment_date_gmt' => $order, 'comment_ID' => $order ) );
		if ( null !== $scope ) { if ( 1 === count( $scope ) ) { $args['post_id'] = $scope[0]; } else { $args['post__in'] = $scope; } }
		if ( $as_of ) { $args['date_query'] = array( array( 'column' => 'comment_date_gmt', 'before' => gmdate( 'Y-m-d H:i:s', $as_of ), 'inclusive' => true ) ); }
		if ( in_array( $options['order'], array( 'rating_desc', 'rating_asc' ), true ) ) { $args['meta_key'] = 'rating'; $args['orderby'] = array( 'meta_value_num' => $order, 'comment_ID' => $order ); }
		$meta = array();
		if ( ! empty( $options['rating_exact'] ) ) { $meta[] = array( 'key' => 'rating', 'value' => (int) $options['rating_exact'], 'compare' => '=', 'type' => 'NUMERIC' ); }
		if ( $options['minimum_rating'] > 0 ) { $meta[] = array( 'key' => 'rating', 'value' => $options['minimum_rating'], 'compare' => '>=', 'type' => 'NUMERIC' ); }
		if ( 'image_reviews' === $type ) { $meta[] = array( 'key' => Commerce_Blocks::REVIEW_IMAGES, 'value' => array( '', 'a:0:{}' ), 'compare' => 'NOT IN' ); }
		if ( 'video_reviews' === $type ) { $meta[] = array( 'key' => Commerce_Blocks::REVIEW_VIDEO, 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ); }
		if ( 'featured_reviews' === $type ) { $meta[] = array( 'key' => Commerce_Blocks::REVIEW_FEATURED, 'value' => '1', 'compare' => '=' ); }
		if ( $meta ) { $args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta ); }
		return $args;
	}

	/** Eligible offsets never count withheld comments; API scans are independently bounded. */
	private static function fetch( string $type, ?array $scope, array $options, int $offset, int $limit, int $scan_budget = 0, int $as_of = 0 ): array {
		$args = self::query_args( $type, $scope, $options, $limit, $as_of );
		$clauses = self::comment_filter();
		$rows = array(); $eligible = 0; $scanned = 0; $limited = false;
		add_filter( 'comments_clauses', $clauses, 10, 2 );
		try {
			// Fetch bounded pages until enough eligible records are found; no fixed newest-200 cutoff.
			do {
				$reviews = get_comments( $args );
				foreach ( $reviews as $review ) {
					if ( $scan_budget && ++$scanned > $scan_budget ) { $limited = true; break 2; }
					if ( ! $review instanceof \WP_Comment ) { continue; }
					$row = self::public_row( $type, $review, $scope, $options, $as_of ); if ( ! $row ) { continue; }
					if ( $eligible++ < $offset ) { continue; }
					$rows[] = $row;
					if ( count( $rows ) >= $limit ) { break 2; }
				}
				$args['offset'] += $args['number'];
			} while ( count( $reviews ) === $args['number'] );
		} finally { remove_filter( 'comments_clauses', $clauses, 10 ); }
		return array( 'rows' => $rows, 'limited' => $limited );
	}

	/** A narrow public SSR facade. Filters cannot expand to private records or editor metadata. */
	public static function index_page( int $page, array $filters = array() ): array {
		$empty = array( 'html' => '', 'count' => 0, 'hasMore' => false, 'truncated' => false, 'error' => null );
		if ( $page < 1 || $page > self::INDEX_PAGES ) { return array_replace( $empty, array( 'error' => 'invalid_page' ) ); }
		if ( array_diff( array_keys( $filters ), array( 'rating', 'media', 'order', 'productId' ) ) ) { return array_replace( $empty, array( 'error' => 'invalid_filters' ) ); }
		$rating = array_key_exists( 'rating', $filters ) ? $filters['rating'] : 0; $media = array_key_exists( 'media', $filters ) ? $filters['media'] : 'all'; $order = array_key_exists( 'order', $filters ) ? $filters['order'] : 'newest'; $product_id = array_key_exists( 'productId', $filters ) ? $filters['productId'] : 0;
		if ( ( ! is_int( $rating ) && ! is_string( $rating ) ) || ! in_array( (string) $rating, array( '0', '1', '2', '3', '4', '5' ), true ) || ! in_array( $media, array( 'all', 'with_media', 'without_media' ), true ) || ! in_array( $order, array( 'newest', 'oldest' ), true ) || ( ! is_int( $product_id ) && ! is_string( $product_id ) ) || ! preg_match( '/^[0-9]+$/D', (string) $product_id ) ) { return array_replace( $empty, array( 'error' => 'invalid_filters' ) ); }
		if ( ! function_exists( 'wc_get_product' ) || ( function_exists( 'wc_reviews_enabled' ) && ! wc_reviews_enabled() ) ) { return $empty; }
		$scope = null;
		if ( (int) $product_id ) {
			$parent = self::parent_product( $product_id ); $product = $parent ? wc_get_product( $parent ) : false;
			if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_visible() || '' !== (string) get_post_field( 'post_password', $parent ) || post_password_required( $parent ) ) { return $empty; }
			$scope = array( $parent );
		}
		try {
			$options = self::options( array( 'reviewOptionsMode' => 'custom', 'reviewOrder' => $order, 'minimumRating' => '1' ) );
			$options['minimum_rating'] = 0; $options['rating_exact'] = (int) $rating; $options['media_filter'] = $media; $options['query_batch'] = 96; $options['featured_manual'] = true;
			$result = self::fetch( 'text_reviews', $scope, $options, ( $page - 1 ) * self::MORE_BATCH, self::MORE_BATCH + 1, self::INDEX_SCAN );
			$extra = count( $result['rows'] ) > self::MORE_BATCH; $rows = array_slice( $result['rows'], 0, self::MORE_BATCH );
			self::assets();
			return array( 'html' => self::cards( 'featured_reviews', $rows, $options ), 'count' => count( $rows ), 'hasMore' => $extra && $page < self::INDEX_PAGES, 'truncated' => $result['limited'] || ( $extra && self::INDEX_PAGES === $page ), 'error' => null );
		} catch ( \Throwable $error ) { return array_replace( $empty, array( 'error' => 'unavailable' ) ); }
	}

	private static function index_filters( array $filters ): ?array {
		if ( array_diff( array_keys( $filters ), array( 'rating', 'media', 'order', 'productId' ) ) ) { return null; }
		$values = array_replace( array( 'rating' => 0, 'media' => 'all', 'order' => 'newest', 'productId' => 0 ), $filters );
		if ( ( ! is_int( $values['rating'] ) && ! is_string( $values['rating'] ) ) || ! in_array( (string) $values['rating'], array( '0', '1', '2', '3', '4', '5' ), true ) || ! in_array( $values['media'], array( 'all', 'with_media', 'without_media' ), true ) || ! in_array( $values['order'], array( 'newest', 'highest', 'lowest', 'oldest' ), true ) || ( ! is_int( $values['productId'] ) && ! is_string( $values['productId'] ) ) || ! preg_match( '/^[0-9]+$/D', (string) $values['productId'] ) ) { return null; }
		$values['rating'] = (int) $values['rating']; $values['productId'] = (int) $values['productId'];
		return $values;
	}

	/** wpdb soft failures must not masquerade as a complete empty result; stale cached errors do not count. */
	private static function index_read( callable $read ) {
		global $wpdb;
		$queries = (int) ( $wpdb->num_queries ?? 0 );
		$suppress = is_object( $wpdb ) && method_exists( $wpdb, 'suppress_errors' ); $previous = $suppress ? $wpdb->suppress_errors( true ) : null;
		try {
			$result = $read();
			if ( (int) ( $wpdb->num_queries ?? 0 ) > $queries && '' !== (string) ( $wpdb->last_error ?? '' ) ) {
				// Core can cache failed comment reads as empty; never let a retry treat that as proof of zero.
				if ( function_exists( 'wp_cache_set_comments_last_changed' ) ) { wp_cache_set_comments_last_changed(); }
				throw new \RuntimeException( 'Review data is unavailable.' );
			}
			return $result;
		} finally { if ( $suppress ) { $wpdb->suppress_errors( $previous ); } }
	}

	private static function index_rating( int $review_id ): int {
		$value = self::index_read( static fn() => get_comment_meta( $review_id, 'rating', true ) );
		return ( is_int( $value ) || is_string( $value ) ) && preg_match( '/^[1-5]$/D', (string) $value ) ? (int) $value : 0;
	}

	private static function index_position( \WP_Comment $review ): ?array {
		if ( (int) $review->comment_ID < 1 || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $review->comment_date_gmt ) ) { return null; }
		return array( 'date' => $review->comment_date_gmt, 'id' => (int) $review->comment_ID, 'rating' => self::index_rating( (int) $review->comment_ID ) );
	}

	private static function index_cursor_token( array $filters, array $position, int $as_of, int $page ): string {
		$payload = array( 'v' => 1, 'exp' => $as_of + self::TOKEN_LIFETIME, 'asOf' => $as_of, 'filters' => $filters, 'position' => $position, 'page' => $page );
		$encoded = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
		return $encoded . '.' . hash_hmac( 'sha256', self::INDEX_CURSOR_CONTEXT . $encoded, wp_salt( 'auth' ) );
	}

	/** Return a known error string instead of accepting partially valid cursor shapes. */
	private static function index_cursor_payload( string $cursor, array $filters ) {
		if ( strlen( $cursor ) > 4096 || ! preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $cursor, $parts ) || ! hash_equals( hash_hmac( 'sha256', self::INDEX_CURSOR_CONTEXT . $parts[1], wp_salt( 'auth' ) ), $parts[2] ) ) { return 'invalid_cursor'; }
		$decoded = base64_decode( strtr( $parts[1], '-_', '+/' ), true ); $data = false === $decoded ? null : json_decode( $decoded, true, 8 );
		if ( ! is_array( $data ) || array_diff( array_keys( $data ), array( 'v', 'exp', 'asOf', 'filters', 'position', 'page' ) ) || 1 !== ( $data['v'] ?? 0 ) || ! is_int( $data['exp'] ?? null ) || ! is_int( $data['asOf'] ?? null ) || $data['exp'] !== $data['asOf'] + self::TOKEN_LIFETIME || $data['asOf'] > time() + 60 || ( $data['filters'] ?? null ) !== $filters || ! is_int( $data['page'] ?? null ) || $data['page'] < 1 || $data['page'] >= self::INDEX_PAGES ) { return 'invalid_cursor'; }
		if ( $data['exp'] < time() || $data['asOf'] < time() - self::TOKEN_LIFETIME ) { return 'expired_cursor'; }
		$p = $data['position'] ?? null;
		if ( ! is_array( $p ) || array_diff( array_keys( $p ), array( 'date', 'id', 'rating' ) ) || ! is_string( $p['date'] ?? null ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $p['date'] ) || ! is_int( $p['id'] ?? null ) || $p['id'] < 1 || ! is_int( $p['rating'] ?? null ) || $p['rating'] < 0 || $p['rating'] > 5 ) { return 'invalid_cursor'; }
		return $data;
	}

	private static function index_cache_domain( string $order, ?array $position ): string {
		return 'pc-review-index-v1-' . hash( 'sha256', wp_json_encode( array( $order, $position, function_exists( 'wp_cache_get_last_changed' ) ? wp_cache_get_last_changed( 'posts' ) : '', function_exists( 'wp_cache_get_last_changed' ) ? wp_cache_get_last_changed( 'terms' ) : '' ) ) );
	}

	/** Keyset candidates never re-scan preceding pages; both SQL batch and total raw reads are bounded. */
	private static function index_rows( ?array $scope, array $options, ?array $position, int $as_of, int $budget, int $target, bool $prove_end = false ): array {
		$args = self::query_args( 'text_reviews', $scope, $options, $target, $as_of );
		$args['pc_review_index_cursor'] = true; $args['pc_review_index_order'] = $options['order']; $args['pc_review_index_budget'] = $budget;
		$rows = array(); $scanned = 0; $exhausted = false; $last = $position; $resume = null; $clauses = self::comment_filter();
		add_filter( 'comments_clauses', $clauses, 10, 2 );
		try {
			while ( $scanned < $budget ) {
				$args['number'] = min( 96, $budget - $scanned ); $args['pc_review_index_position'] = $last;
				// Core's comment cache excludes custom query vars. Bind the SQL-only tuple/order
				// and product generations explicitly; approval/rating meta use core's comment salt.
				$args['cache_domain'] = self::index_cache_domain( $options['order'], $last );
				$batch = self::index_read( static fn() => get_comments( $args ) );
				foreach ( $batch as $review ) {
					$scanned++;
					if ( ! $review instanceof \WP_Comment ) { continue; }
					$next = self::index_position( $review ); if ( ! $next ) { continue; } $last = $next;
					$row = self::index_read( static fn() => self::public_row( 'text_reviews', $review, $scope, $options, $as_of ) ); if ( ! $row ) { continue; }
					$rows[] = $row;
					if ( count( $rows ) === self::MORE_BATCH ) { $resume = $last; }
					if ( count( $rows ) >= $target ) { return array( 'rows' => $rows, 'position' => $resume ?: $last, 'truncated' => false, 'more' => true, 'scanned' => $scanned ); }
				}
				$exhausted = count( $batch ) < $args['number']; if ( $exhausted ) { break; }
			}
			if ( ! $exhausted && $prove_end && $last ) {
				// Prove an exact-budget aggregate is complete without materializing a601st object.
				$args['number'] = 1; $args['fields'] = 'ids'; $args['pc_review_index_position'] = $last; $args['pc_review_index_end_probe'] = true;
				$args['cache_domain'] = self::index_cache_domain( $options['order'], $last );
				$exhausted = ! self::index_read( static fn() => get_comments( $args ) );
			}
		} finally { remove_filter( 'comments_clauses', $clauses, 10 ); }
		return array( 'rows' => $rows, 'position' => $last, 'truncated' => ! $exhausted, 'more' => ! $exhausted && null !== $last, 'scanned' => $scanned );
	}

	/** Exact whenever the complete scope fits the explicit budget; never a first-page average. No stale cross-request cache. */
	private static function index_stats( ?array $scope, int $as_of ): ?array {
		try {
			$options = self::options( array( 'reviewOptionsMode' => 'custom', 'reviewOrder' => 'newest' ) );
			$options['minimum_rating'] = 0; $options['normalize_rating'] = true; $options['order'] = 'newest';
			$result = self::index_rows( $scope, $options, null, $as_of, self::INDEX_STATS_SCAN, self::INDEX_STATS_SCAN + 1, true );
			if ( $result['truncated'] ) { return null; }
			$stats = array( 'total' => count( $result['rows'] ), 'rated' => 0, 'average' => 0, 'ratings' => array_fill_keys( range( 1, 5 ), 0 ) ); $sum = 0;
			foreach ( $result['rows'] as $row ) { $rating = self::index_rating( (int) $row['review']->comment_ID ); if ( $rating ) { $stats['ratings'][ $rating ]++; $stats['rated']++; $sum += $rating; } }
			$stats['average'] = $stats['rated'] ? round( $sum / $stats['rated'], 1 ) : 0;
			return $stats;
		} catch ( \Throwable $error ) { return null; }
	}

	/** Public index API: immutable signed filters, bounded keysets, genuine public cards and truthful aggregate scope. */
	public static function cursor_page( ?string $cursor = null, array $filters = array() ): array {
		$display_options = self::options( array( 'reviewOptionsMode' => 'custom', 'reviewOrder' => 'newest' ) );
		$empty = array( 'html' => '', 'count' => 0, 'nextCursor' => null, 'hasMore' => false, 'truncated' => false, 'error' => null, 'stats' => null, 'ratingsEnabled' => (bool) $display_options['show_rating'] );
		$filters = self::index_filters( $filters ); if ( null === $filters ) { return array_replace( $empty, array( 'error' => 'invalid_filters' ) ); }
		if ( ! $empty['ratingsEnabled'] ) { $filters['rating'] = 0; if ( in_array( $filters['order'], array( 'highest', 'lowest' ), true ) ) { $filters['order'] = 'newest'; } }
		if ( ! function_exists( 'wc_get_product' ) || ( function_exists( 'wc_reviews_enabled' ) && ! wc_reviews_enabled() ) ) { return $empty; }
		try {
			$scope = null;
			if ( $filters['productId'] ) {
				$id = self::parent_product( $filters['productId'] ); $product = $id ? wc_get_product( $id ) : false;
				if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_visible() || '' !== (string) get_post_field( 'post_password', $id ) || post_password_required( $id ) ) { return array_replace( $empty, array( 'error' => 'invalid_product' ) ); }
				$filters['productId'] = $id; $scope = array( $id );
			}
			$payload = null;
			if ( null !== $cursor && '' !== $cursor ) { $payload = self::index_cursor_payload( $cursor, $filters ); if ( is_string( $payload ) ) { return array_replace( $empty, array( 'error' => $payload ) ); } }
			$as_of = $payload['asOf'] ?? time(); $page = ( $payload['page'] ?? 0 ) + 1;
			$options = $display_options;
			$options['minimum_rating'] = 0; $options['rating_exact'] = $filters['rating']; $options['media_filter'] = $filters['media']; $options['normalize_rating'] = true; $options['order'] = $filters['order']; $options['featured_manual'] = true;
			$result = self::index_rows( $scope, $options, $payload['position'] ?? null, $as_of, self::INDEX_CURSOR_SCAN, self::MORE_BATCH + 1 );
			$rows = array_slice( $result['rows'], 0, self::MORE_BATCH ); $can_continue = $result['more'] && $page < self::INDEX_PAGES;
			$next = $can_continue ? self::index_cursor_token( $filters, $result['position'], $as_of, $page ) : null;
			self::assets();
			return array( 'html' => self::index_read( static fn() => self::cards( 'featured_reviews', $rows, $options ) ), 'count' => count( $rows ), 'nextCursor' => $next, 'hasMore' => null !== $next, 'truncated' => $result['truncated'] || ( $result['more'] && ! $can_continue ), 'error' => null, 'stats' => self::index_stats( $scope, time() ), 'ratingsEnabled' => $empty['ratingsEnabled'] );
		} catch ( \Throwable $error ) { return array_replace( $empty, array( 'error' => 'unavailable' ) ); }
	}

	private static function cards( string $type, array $rows, array $options ): string {
		if ( 'featured_reviews' === $type && ! empty( $options['featured_manual'] ) ) {
			$ids = array_map( static fn( $row ) => (int) $row['review']->comment_ID, $rows );
			$options['merchant_replies'] = class_exists( \PeptideCore\Review_Replies::class ) ? \PeptideCore\Review_Replies::public_for( $ids ) : array();
		}
		$out = ''; foreach ( $rows as $row ) { $out .= self::card( $type, $row['review'], $row['item'], $options, $row['images'], $row['video'] ); } return $out;
	}

	/** Render only reviewed public records. The commerce renderer still owns the outer module wrapper. */
	public static function render( string $type, array $settings, string $context = 'public', string $key = '', array $items = array() ): string {
		if ( ! in_array( $type, self::TYPES, true ) || ( function_exists( 'wc_reviews_enabled' ) && ! wc_reviews_enabled() ) ) { return ''; }
		$manual = 'featured_reviews' === $type && 'manual' === ( $settings['featuredMode'] ?? 'auto' );
		$review_ids = $manual ? self::selected_ids( $items ) : null;
		if ( $manual && ! $review_ids ) { return ''; }
		$scope = $manual ? null : self::product_scope( $settings ); if ( array() === $scope ) { return ''; }
		$options = self::options( $settings, $type ); $limit = $options['limit'];
		$more = 'auto' === ( $settings['viewMoreMode'] ?? 'none' ) && '' !== trim( (string) ( $settings['viewMoreLabel'] ?? '' ) );
		$as_of = $more ? time() : 0;
		$result = $manual ? array( 'rows' => self::manual_rows( $review_ids, $options ), 'limited' => false ) : self::fetch( $type, $scope, $options, 0, $limit + ( $more ? 1 : 0 ), 0, $as_of );
		$has_more = $more && count( $result['rows'] ) > $limit;
		$index_more = 'index' === ( $settings['viewMoreMode'] ?? 'none' ) && '' !== trim( (string) ( $settings['viewMoreLabel'] ?? '' ) );
		$out = self::cards( $type, array_slice( $result['rows'], 0, $limit ), $options );
		if ( '' === $out ) { return ''; }
		self::assets();
		$description = array_key_exists( 'descriptionHtml', $settings ) && null !== $settings['descriptionHtml'] ? wp_kses_post( (string) $settings['descriptionHtml'] ) : nl2br( esc_html( $settings['description'] ?? '' ) );
		$head = ( '' !== (string) ( $settings['title'] ?? '' ) ? '<h2>' . esc_html( $settings['title'] ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-rb__description">' . $description . '</div>' : '' );
		$id = 'pc-rb-list-' . sanitize_html_class( $key ?: wp_unique_id() );
		$columns = $settings['columns'] ?? array( 'desktop' => 4, 'mobile' => 2 );
		$desktop = max( 1, min( 6, (int) ( $columns['desktop'] ?? 4 ) ) ); $mobile = max( 1, min( 6, (int) ( $columns['mobile'] ?? $desktop ) ) );
		$style = '--pc-rb-cols:' . $desktop . ';--pc-rb-cols-m:' . $mobile;
		foreach ( array( 'cardBackground' => 'card-bg', 'textColor' => 'card-text', 'verifiedColor' => 'verified', 'reviewBackground' => 'background', 'headingColor' => 'heading', 'descriptionColor' => 'description' ) as $field => $name ) { $color = $settings[ $field ] ?? ''; if ( is_string( $color ) && preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color ) ) { $style .= ';--pc-rb-' . $name . ':' . $color; } }
		return '<div class="pc-rb pc-rb--' . esc_attr( $type ) . ( $manual ? ' pc-rb--featured-manual' : '' ) . '" data-pc-review-block data-desktop-layout="' . esc_attr( $options['desktop_layout'] ) . '" data-mobile-layout="' . esc_attr( $options['mobile_layout'] ) . '" data-autoplay="' . ( $options['autoplay'] ? '1' : '0' ) . '" data-interval="' . max( 2000, min( 20000, (int) $options['interval'] ) ) . '" style="' . esc_attr( $style ) . '">' . ( $head ? '<header class="pc-rb__head">' . $head . '</header>' : '' ) . '<div class="pc-rb__list" id="' . esc_attr( $id ) . '">' . $out . '</div>' . self::controls( $options['autoplay'], $id ) . ( $has_more || $index_more ? self::more_control( $type, $settings, $scope, $id, $context, $as_of, $review_ids ) : '' ) . '</div>';
	}

	private static function assets(): void {
		$base = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		wp_enqueue_style( 'pc-review-blocks', $base . 'review-blocks.css', array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/review-blocks.css' ) );
		wp_enqueue_script( 'pc-review-blocks', $base . 'review-blocks.js', array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/review-blocks.js' ), true );
	}

	/** Sign only public query scope and display filters, never customer or comment data. */
	private static function token( string $type, ?array $scope, array $settings, int $as_of, ?array $review_ids = null ): string {
		$payload = array( 'v' => 1, 'exp' => $as_of + self::TOKEN_LIFETIME, 'asOf' => $as_of, 'type' => $type, 'scope' => $scope, 'settings' => array(
			'reviewOptionsMode' => 'custom' === ( $settings['reviewOptionsMode'] ?? '' ) ? 'custom' : 'inherit',
			'reviewOrder' => 'oldest' === ( $settings['reviewOrder'] ?? '' ) ? 'oldest' : 'newest',
			'minimumRating' => max( 1, min( 5, (int) ( $settings['minimumRating'] ?? 4 ) ) ),
			'limit' => max( 1, min( 24, (int) ( $settings['limit'] ?? 8 ) ) ),
		) );
		if ( 'video_reviews' === $type ) { $payload['settings']['videoRatio'] = in_array( $settings['videoRatio'] ?? '', array( '9/16', '16/9', '4/3', '1/1' ), true ) ? $settings['videoRatio'] : '16/9'; }
		if ( 'featured_reviews' === $type ) {
			$payload['settings']['featuredMode'] = 'manual' === ( $settings['featuredMode'] ?? 'auto' ) ? 'manual' : 'auto';
			if ( 'manual' === $payload['settings']['featuredMode'] ) { $payload['reviews'] = $review_ids ?? array(); $payload['settings']['minimumRating'] = 0; $payload['settings']['reviewOrder'] = 'manual'; }
		}
		$encoded = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
		return $encoded . '.' . hash_hmac( 'sha256', self::TOKEN_CONTEXT . $encoded, wp_salt( 'auth' ) );
	}

	private static function token_payload( string $token ): ?array {
		if ( strlen( $token ) > 4096 || ! preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $parts ) || ! hash_equals( hash_hmac( 'sha256', self::TOKEN_CONTEXT . $parts[1], wp_salt( 'auth' ) ), $parts[2] ) ) { return null; }
		$decoded = base64_decode( strtr( $parts[1], '-_', '+/' ), true );
		$payload = false === $decoded ? null : json_decode( $decoded, true, 12 );
		if ( ! is_array( $payload ) || array_diff( array_keys( $payload ), array( 'v', 'exp', 'asOf', 'type', 'scope', 'settings', 'reviews' ) ) || 1 !== ( $payload['v'] ?? 0 ) || ! is_int( $payload['exp'] ?? null ) || ! is_int( $payload['asOf'] ?? null ) || $payload['exp'] < time() || $payload['asOf'] > time() + 60 || $payload['exp'] > $payload['asOf'] + self::TOKEN_LIFETIME || $payload['asOf'] < time() - self::TOKEN_LIFETIME || ! in_array( $payload['type'] ?? '', self::TYPES, true ) || ! array_key_exists( 'scope', $payload ) ) { return null; }
		$scope = $payload['scope'];
		if ( null !== $scope ) {
			if ( ! is_array( $scope ) || ! $scope || count( $scope ) > 50 || array_values( $scope ) !== $scope ) { return null; }
			foreach ( $scope as $id ) { if ( ! is_int( $id ) || $id <= 0 ) { return null; } }
			if ( count( array_unique( $scope, SORT_NUMERIC ) ) !== count( $scope ) ) { return null; }
		}
		$s = $payload['settings'] ?? null;
		$manual = is_array( $s ) && 'featured_reviews' === $payload['type'] && 'manual' === ( $s['featuredMode'] ?? 'auto' );
		if ( ! is_array( $s ) || array_diff( array_keys( $s ), array( 'reviewOptionsMode', 'reviewOrder', 'minimumRating', 'limit', 'videoRatio', 'featuredMode' ) ) || ! in_array( $s['reviewOptionsMode'] ?? '', array( 'inherit', 'custom' ), true ) || ! in_array( $s['reviewOrder'] ?? '', $manual ? array( 'manual' ) : array( 'oldest', 'newest' ), true ) || ! is_int( $s['minimumRating'] ?? null ) || ( $manual ? 0 !== $s['minimumRating'] : ( $s['minimumRating'] < 1 || $s['minimumRating'] > 5 ) ) || ! is_int( $s['limit'] ?? null ) || $s['limit'] < 1 || $s['limit'] > 24 || ( isset( $s['videoRatio'] ) && ( 'video_reviews' !== $payload['type'] || ! in_array( $s['videoRatio'], array( '9/16', '16/9', '4/3', '1/1' ), true ) ) ) || ( isset( $s['featuredMode'] ) && ( 'featured_reviews' !== $payload['type'] || ! in_array( $s['featuredMode'], array( 'auto', 'manual' ), true ) ) ) ) { return null; }
		if ( $manual ) {
			$ids = $payload['reviews'] ?? null;
			if ( null !== $scope || ! is_array( $ids ) || ! $ids || count( $ids ) > 100 || array_values( $ids ) !== $ids ) { return null; }
			foreach ( $ids as $id ) { if ( ! is_int( $id ) || $id <= 0 ) { return null; } }
			if ( count( array_unique( $ids, SORT_NUMERIC ) ) !== count( $ids ) ) { return null; }
		} elseif ( array_key_exists( 'reviews', $payload ) ) { return null; }
		return $payload;
	}

	/** Public, read-only pagination. A valid signature never grants access to withheld records. */
	public static function more(): void {
		nocache_headers();
		$raw_token = $_POST['token'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Stateless public-scope signature below.
		$raw_page = $_POST['page'] ?? '1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$payload = is_string( $raw_token ) ? self::token_payload( wp_unslash( $raw_token ) ) : null;
		if ( ! $payload ) { wp_send_json_error( array( 'code' => 'invalid_context', 'message' => __( 'This review list has expired. Close it and refresh the page to try again.', 'peptide-core' ) ), 403 ); }
		if ( ! is_scalar( $raw_page ) || ! preg_match( '/^[1-9][0-9]?$/D', (string) $raw_page ) || (int) $raw_page > self::MORE_PAGES ) { wp_send_json_error( array( 'code' => 'invalid_page', 'message' => __( 'This review page is unavailable.', 'peptide-core' ) ), 400 ); }
		if ( function_exists( 'wc_reviews_enabled' ) && ! wc_reviews_enabled() ) { wp_send_json_error( array( 'code' => 'reviews_unavailable', 'message' => __( 'Reviews are currently unavailable.', 'peptide-core' ) ), 403 ); }
		$page = (int) $raw_page;
		try {
			$options = self::options( $payload['settings'], $payload['type'] );
			if ( isset( $payload['reviews'] ) ) {
				$selected = self::manual_rows( $payload['reviews'], $options );
				$page_ids = array_slice( $payload['reviews'], ( $page - 1 ) * self::MORE_BATCH, self::MORE_BATCH );
				$remaining = array_slice( $payload['reviews'], $page * self::MORE_BATCH );
				$rows = array_values( array_filter( $selected, static fn( $row ) => in_array( (int) $row['review']->comment_ID, $page_ids, true ) ) );
				$has_more = $page < self::MORE_PAGES && (bool) array_filter( $selected, static fn( $row ) => in_array( (int) $row['review']->comment_ID, $remaining, true ) );
				$limited = false;
			} else {
				$result = self::fetch( $payload['type'], $payload['scope'], $options, ( $page - 1 ) * self::MORE_BATCH, self::MORE_BATCH + 1, self::MORE_SCAN, $payload['asOf'] );
				$extra = count( $result['rows'] ) > self::MORE_BATCH;
				$rows = array_slice( $result['rows'], 0, self::MORE_BATCH );
				$has_more = $extra && $page < self::MORE_PAGES;
				$limited = $result['limited'] || ( $extra && self::MORE_PAGES === $page );
			}
			$message = $limited ? __( 'Only the currently loaded matching reviews are shown.', 'peptide-core' ) : ( $rows ? sprintf( __( '%d reviews loaded.', 'peptide-core' ), count( $rows ) ) : __( 'There are no more matching reviews.', 'peptide-core' ) );
			$response = array( 'html' => self::cards( $payload['type'], $rows, $options ), 'count' => count( $rows ), 'page' => $page, 'hasMore' => $has_more, 'limited' => $limited, 'message' => $message );
		} catch ( \Throwable $error ) {
			wp_send_json_error( array( 'code' => 'load_failed', 'message' => __( 'Unable to load reviews. Please try again.', 'peptide-core' ) ), 500 );
		}
		wp_send_json_success( $response );
	}

	private static function more_control( string $type, array $settings, ?array $scope, string $id, string $context, int $as_of, ?array $review_ids = null ): string {
		$effect = in_array( $settings['buttonEffect'] ?? '', self::EFFECTS, true ) ? $settings['buttonEffect'] : 'none';
		$label = '<span class="pc-rb__button pc-rb__button--' . esc_attr( $effect ) . '" data-pc-review-effect="' . esc_attr( $effect ) . '" data-duration="' . max( 1000, min( 5000, (int) ( $settings['effectDurationMs'] ?? 1000 ) ) ) . '" data-interval="' . max( 500, min( 5000, (int) ( $settings['effectIntervalMs'] ?? 1500 ) ) ) . '">' . esc_html( $settings['viewMoreLabel'] ) . '</span>';
		if ( 'index' === ( $settings['viewMoreMode'] ?? 'none' ) ) {
			if ( ! class_exists( \PeptideCore\Review_Index::class ) ) { return ''; }
			$public = 'public' === $context && ! Renderer::$in_editor && ! \PeptideCore\Templates::previewing_id();
			return '<div class="pc-rb__more-wrap">' . ( $public ? '<a class="pc-rb__more" href="' . esc_url( \PeptideCore\Review_Index::url() ) . '">' . $label . '</a>' : '<span class="pc-rb__more" aria-disabled="true">' . $label . '</span>' ) . '</div>';
		}
		if ( null === $review_ids && in_array( $settings['reviewSource'] ?? 'all', array( 'product', 'current' ), true ) && $scope && 1 === count( $scope ) ) {
			$product = wc_get_product( $scope[0] ); if ( ! $product ) { return ''; }
			$public = 'public' === $context && ! Renderer::$in_editor && ! \PeptideCore\Templates::previewing_id();
			return '<div class="pc-rb__more-wrap">' . ( $public ? '<a class="pc-rb__more" href="' . esc_url( $product->get_permalink() . '#reviews' ) . '">' . $label . '</a>' : '<span class="pc-rb__more" aria-disabled="true">' . $label . '</span>' ) . '</div>';
		}
		$dialog = $id . '-all';
		return '<div class="pc-rb__more-wrap"><button type="button" class="pc-rb__more" data-pc-review-more data-token="' . esc_attr( self::token( $type, $scope, $settings, $as_of, $review_ids ) ) . '" data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" aria-haspopup="dialog" aria-controls="' . esc_attr( $dialog ) . '">' . $label . '</button></div><dialog class="pc-rb__dialog" id="' . esc_attr( $dialog ) . '" aria-labelledby="' . esc_attr( $dialog . '-title' ) . '"><header class="pc-rb__dialog-head"><h2 id="' . esc_attr( $dialog . '-title' ) . '">' . esc_html__( 'All reviews', 'peptide-core' ) . '</h2><button type="button" data-pc-review-close aria-label="' . esc_attr__( 'Close reviews', 'peptide-core' ) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header><div class="pc-rb__dialog-body"><div class="pc-rb__dialog-grid" data-pc-review-results></div><p class="pc-rb__status" data-pc-review-status role="status" aria-live="polite"></p></div><footer class="pc-rb__dialog-foot"><button type="button" data-pc-review-next-page hidden>' . esc_html__( 'Load more reviews', 'peptide-core' ) . '</button><button type="button" data-pc-review-retry hidden>' . esc_html__( 'Try again', 'peptide-core' ) . '</button></footer></dialog>';
	}

	private static function public_name( \WP_Comment $review ): string {
		$name = trim( wp_strip_all_tags( get_comment_author( $review ) ) );
		if ( '' === $name || 'anonymous' === strtolower( $name ) ) { return __( 'Anonymous', 'peptide-core' ); }
		if ( str_contains( $name, '@' ) ) { $name = explode( '@', $name, 2 )[0]; }
		$length = mb_strlen( $name ); $edge = $length >= 5 ? 2 : 1;
		return mb_substr( $name, 0, $edge ) . '***' . ( $length > 2 ? mb_substr( $name, -$edge ) : '' );
	}

	private static function gallery( array $images, int $video ): string {
		$media = array();
		foreach ( $images as $image ) { $url = wp_get_attachment_url( $image ); if ( $url ) { $media[] = array( 'kind' => 'image', 'id' => $image, 'url' => $url ); } }
		if ( $video ) { $url = wp_get_attachment_url( $video ); if ( $url ) { $media[] = array( 'kind' => 'video', 'id' => $video, 'url' => $url ); } }
		if ( ! $media ) { return ''; }
		$id = 'pc-rb-gallery-' . wp_generate_uuid4(); $multiple = count( $media ) > 1; $panels = ''; $thumbs = '';
		foreach ( $media as $index => $item ) {
			$panel_id = $id . '-panel-' . $index; $tab_id = $id . '-tab-' . $index;
			$content = 'image' === $item['kind'] ? '<a href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener noreferrer">' . wp_get_attachment_image( $item['id'], 'large', false, array( 'loading' => 'lazy' ) ) . '</a>' : '<video controls preload="none" src="' . esc_url( $item['url'] ) . '"></video>';
			$panels .= '<div class="pc-rb-gallery__panel" id="' . esc_attr( $panel_id ) . '"' . ( $multiple ? ' role="tabpanel" aria-labelledby="' . esc_attr( $tab_id ) . '"' : '' ) . ( $index ? ' hidden' : '' ) . '>' . $content . '</div>';
			if ( $multiple ) {
				$label = sprintf( 'image' === $item['kind'] ? __( 'Show review image %d', 'peptide-core' ) : __( 'Show review video %d', 'peptide-core' ), $index + 1 );
				$thumb = 'image' === $item['kind'] ? wp_get_attachment_image( $item['id'], 'thumbnail', false, array( 'alt' => '', 'loading' => 'lazy' ) ) : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m10 9 5 3-5 3Z" fill="currentColor" stroke="none"/></svg>';
				$thumbs .= '<button type="button" class="pc-rb-gallery__thumb" id="' . esc_attr( $tab_id ) . '" role="tab" aria-controls="' . esc_attr( $panel_id ) . '" aria-selected="' . ( $index ? 'false' : 'true' ) . '" aria-label="' . esc_attr( $label ) . '" tabindex="' . ( $index ? '-1' : '0' ) . '">' . $thumb . '</button>';
			}
		}
		return '<div class="pc-rb-gallery" data-pc-review-gallery><div class="pc-rb-gallery__panels">' . $panels . '</div>' . ( $multiple ? '<div class="pc-rb-gallery__thumbs" role="tablist" aria-label="' . esc_attr__( 'Review media', 'peptide-core' ) . '">' . $thumbs . '</div>' : '' ) . '</div>';
	}

	/** The shared service alone decides genuine public merchant replies. Never read note metadata here. */
	private static function merchant_replies( \WP_Comment $review, array $options ): string {
		$reply = $options['merchant_replies'][ (int) $review->comment_ID ] ?? null;
		if ( ! is_array( $reply ) || empty( $reply['content'] ) ) { return ''; }
		return '<aside class="pc-rb-feature__reply"><header><strong>' . esc_html__( 'Reply:', 'peptide-core' ) . '</strong><time datetime="' . esc_attr( $reply['datetime'] ) . '">' . esc_html( $reply['date'] ) . '</time></header><div class="pc-rb-feature__reply-content">' . $reply['content'] . '</div></aside>';
	}

	private static function featured_card( \WP_Comment $review, \WC_Product $item, array $options, array $images, int $video ): string {
		$gallery = self::gallery( $images, $video );
		$out = '<figure class="pc-rb__card pc-rb-feature' . ( $gallery ? '' : ' pc-rb-feature--text' ) . '">' . $gallery . '<div class="pc-rb-feature__body"><figcaption class="pc-rb__author"><strong>' . esc_html( self::public_name( $review ) ) . '</strong>' . ( self::verified( $review ) ? '<span class="pc-rb__verified">' . esc_html__( 'Verified purchase', 'peptide-core' ) . '</span>' : '' ) . '</figcaption>';
		$rating = ! empty( $options['normalize_rating'] ) ? self::index_rating( (int) $review->comment_ID ) : min( 5, max( 0, (int) get_comment_meta( $review->comment_ID, 'rating', true ) ) );
		if ( $rating && $options['show_rating'] ) {
			$out .= '<div class="pc-rb__rating" role="img" aria-label="' . esc_attr( sprintf( __( '%d out of 5', 'peptide-core' ), $rating ) ) . '">';
			for ( $i = 1; $i <= 5; $i++ ) { $out .= '<svg viewBox="0 0 24 24" fill="' . ( $i <= $rating ? 'currentColor' : 'none' ) . '" stroke="currentColor" stroke-width="1.2" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9Z"/></svg>'; }
			$out .= '</div>';
		}
		$out .= '<blockquote class="pc-rb__quote">' . wp_kses_post( wpautop( $review->comment_content ) ) . '</blockquote>' . self::merchant_replies( $review, $options );
		$image_id = (int) $item->get_image_id(); $price = wp_kses_post( $item->get_price_html() );
		$out .= '<a class="pc-rb-feature__product" href="' . esc_url( $item->get_permalink() ) . '">' . ( $image_id && wp_attachment_is_image( $image_id ) ? wp_get_attachment_image( $image_id, 'woocommerce_thumbnail', false, array( 'loading' => 'lazy' ) ) : '' ) . '<span><strong>' . esc_html( $item->get_name() ) . '</strong>' . ( $price ? '<span class="pc-rb-feature__price">' . $price . '</span>' : '' ) . '</span></a></div></figure>';
		return $out;
	}

	private static function card( string $type, \WP_Comment $review, \WC_Product $item, array $options, array $images, int $video ): string {
		if ( 'featured_reviews' === $type && ! empty( $options['featured_manual'] ) ) { return self::featured_card( $review, $item, $options, $images, $video ); }
		$rating = min( 5, max( 0, (int) get_comment_meta( $review->comment_ID, 'rating', true ) ) );
		$out = '<figure class="pc-rb__card"><figcaption class="pc-rb__author"><strong>' . esc_html( get_comment_author( $review ) ) . '</strong>' . ( self::verified( $review ) ? '<span class="pc-rb__verified">' . esc_html__( 'Verified purchase', 'peptide-core' ) . '</span>' : '' ) . '</figcaption>';
		if ( $rating && $options['show_rating'] ) {
			$out .= '<div class="pc-rb__rating" role="img" aria-label="' . esc_attr( sprintf( __( '%d out of 5', 'peptide-core' ), $rating ) ) . '">';
			for ( $i = 1; $i <= 5; $i++ ) { $out .= '<svg viewBox="0 0 24 24" fill="' . ( $i <= $rating ? 'currentColor' : 'none' ) . '" stroke="currentColor" stroke-width="1.2" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9Z"/></svg>'; }
			$out .= '</div>';
		}
		$out .= '<blockquote class="pc-rb__quote">' . wp_kses_post( wpautop( $review->comment_content ) ) . '</blockquote>';
		if ( $images && in_array( $type, array( 'image_reviews', 'featured_reviews' ), true ) ) { $out .= '<div class="pc-rb__photos">'; foreach ( $images as $image ) { $url = wp_get_attachment_url( $image ); if ( $url ) { $out .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . wp_get_attachment_image( $image, 'medium' ) . '</a>'; } } $out .= '</div>'; }
		if ( $video && in_array( $type, array( 'video_reviews', 'featured_reviews' ), true ) ) { $ratio = in_array( $options['video_ratio'], array( '16/9', '4/3', '1/1', '9/16' ), true ) ? ' style="aspect-ratio:' . esc_attr( $options['video_ratio'] ) . '"' : ''; $out .= '<video class="pc-rb__video' . ( $ratio ? ' pc-rb__video--ratio' : '' ) . '" controls preload="metadata"' . $ratio . ' src="' . esc_url( wp_get_attachment_url( $video ) ) . '"></video>'; }
		$out .= '<footer class="pc-rb__footer"><a href="' . esc_url( $item->get_permalink() ) . '#reviews">' . esc_html( $item->get_name() ) . '</a><time datetime="' . esc_attr( get_comment_date( 'c', $review ) ) . '">' . esc_html( get_comment_date( '', $review ) ) . '</time></footer></figure>';
		return $out;
	}

	private static function controls( bool $autoplay, string $list_id ): string {
		$arrow = static fn( bool $next ) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . ( $next ? 'm9 6 6 6-6 6' : 'm15 6-6 6 6 6' ) . '"/></svg>';
		return '<div class="pc-rb__controls" hidden><button type="button" data-pc-review-prev aria-controls="' . esc_attr( $list_id ) . '" aria-label="' . esc_attr__( 'Previous reviews', 'peptide-core' ) . '">' . $arrow( false ) . '</button><button type="button" data-pc-review-next aria-controls="' . esc_attr( $list_id ) . '" aria-label="' . esc_attr__( 'Next reviews', 'peptide-core' ) . '">' . $arrow( true ) . '</button>' . ( $autoplay ? '<button type="button" data-pc-review-pause aria-pressed="false" data-pause="' . esc_attr__( 'Pause slideshow', 'peptide-core' ) . '" data-play="' . esc_attr__( 'Play slideshow', 'peptide-core' ) . '">' . esc_html__( 'Pause slideshow', 'peptide-core' ) . '</button>' : '' ) . '</div>';
	}
}
