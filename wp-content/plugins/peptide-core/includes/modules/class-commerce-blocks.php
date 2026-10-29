<?php
/** Catalog and marketing layout blocks backed by the existing store records. */
namespace PeptideCore\Modules;

use PeptideCore\Coupons;
use PeptideCore\Customer_Segments;
use PeptideCore\Product_Offers;
use PeptideCore\Promotions;
use PeptideCore\Shipping\Discounts;
use PeptideCore\Subscriptions;
use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Commerce_Blocks {
	public const RECENT_COOKIE = 'pc_recent_products';
	public const REVIEW_IMAGES = '_pc_review_images';
	public const REVIEW_VIDEO = '_pc_review_video';
	public const REVIEW_FEATURED = '_pc_review_featured';
	private const EFFECTS = array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble', 'pulse', 'shine' );
	private const TYPES = array( 'featured_product', 'video_products', 'product_carousel', 'product_scroll', 'product_tabs', 'category_tabs', 'topic_tabs', 'product_ranking', 'recent_products', 'product_waterfall', 'category_list', 'featured_categories', 'category_navigation', 'product_comparison', 'promotion_card', 'product_offer', 'subscribe', 'announcement', 'coupon_list', 'promotion_list', 'shipping_discount_list', 'text_reviews', 'image_reviews', 'video_reviews', 'featured_reviews', 'blog_posts', 'blog_categories', 'category_blog', 'campaign_pages', 'social_links', 'youtube_list', 'partners', 'product_search', 'search_terms' );

	public static function register_hooks(): void {
		Review_Blocks::register_hooks();
		\PeptideCore\Review_Index::register_hooks();
		add_filter( 'peptide_core_layout_definitions', array( self::class, 'definitions' ) );
		add_filter( 'peptide_core_module_html', array( self::class, 'render' ), 10, 4 );
		add_action( 'template_redirect', array( self::class, 'record_recent' ), 5 );
		add_action( 'add_meta_boxes_comment', array( self::class, 'review_box' ) );
		add_action( 'edit_comment', array( self::class, 'save_review_media' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'review_assets' ) );
	}

	private static function field( string $type, string $key, string $label, $default = null, array $extra = array() ): array {
		return Fields::f( $type, $key, $label, $default, $extra );
	}

	private static function heading(): array {
		return array( self::field( 'text', 'title', __( 'Title', 'peptide-core' ), '' ), self::field( 'textarea', 'description', __( 'Description', 'peptide-core' ), '' ) );
	}

	private static function effect_options(): array {
		return array( 'none' => '无特效', 'bounce' => '跳动', 'rubberband' => '橡皮筋', 'shake' => '左右抖动', 'swing' => '摇摆', 'wobble' => '摇晃', 'pulse' => '呼吸', 'shine' => '流光' );
	}

	private static function source_fields(): array {
		return array(
			self::field( 'select', 'source', __( 'Products to show', 'peptide-core' ), 'catalog', array( 'options' => array( 'catalog' => __( 'Whole catalog', 'peptide-core' ), 'category' => __( 'One category', 'peptide-core' ), 'manual' => __( 'Choose products', 'peptide-core' ) ) ) ),
			self::field( 'category', 'category', __( 'Category', 'peptide-core' ), null, array( 'show_if' => array( 'source' => 'category' ) ) ),
			self::field( 'repeater', 'products', __( 'Products', 'peptide-core' ), null, array( 'show_if' => array( 'source' => 'manual' ), 'max' => 24, 'fields' => array( self::field( 'product', 'product', __( 'Product', 'peptide-core' ) ) ) ) ),
			self::field( 'select', 'sort', __( 'Sort order', 'peptide-core' ), 'catalog', array( 'options' => array( 'catalog' => __( 'Catalog order', 'peptide-core' ), 'newest' => __( 'Newest first', 'peptide-core' ), 'title' => __( 'Name A–Z', 'peptide-core' ), 'price_asc' => __( 'Price: low to high', 'peptide-core' ), 'price_desc' => __( 'Price: high to low', 'peptide-core' ) ) ) ),
		);
	}

	private static function layout_fields(): array {
		return array(
			self::field( 'number', 'limit', __( 'Maximum items', 'peptide-core' ), 8, array( 'min' => 1, 'max' => 24 ) ),
			self::field( 'number', 'columns', __( 'Items per row', 'peptide-core' ), array( 'desktop' => 4, 'mobile' => 2 ), array( 'device' => true, 'min' => 1, 'max' => 6 ) ),
		);
	}

	/** Presentation controls only. Activity IDs and discount calculations stay with their business engines. */
	private static function campaign_fields( bool $columns = false ): array {
		$fields = array(
			self::field( 'html', 'descriptionHtml', '活动简述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'help' => '未编辑时沿用原简述；清空后不显示简述。' ) ),
			self::field( 'toggle', 'fullWidth', '宽度铺满', false, array( 'group' => 'layout' ) ),
			self::field( 'select', 'tierLayout', '阶梯优惠展示样式', array( 'desktop' => 'grid', 'mobile' => 'carousel' ), array( 'group' => 'layout', 'device' => true, 'options' => array( 'grid' => '横向平铺', 'carousel' => '轮播图' ) ) ),
			self::field( 'toggle', 'showCountdown', '显示活动倒计时', true, array( 'group' => 'play', 'help' => '使用所选活动的真实开始、结束时间；未设置时间的活动不显示倒计时。' ) ),
			self::field( 'select', 'countdownFormat', '活动倒计时显示样式', 'dhms', array( 'group' => 'play', 'show_if' => array( 'showCountdown' => true ), 'options' => array( 'dhms' => '天:时:分:秒', 'hms' => '时:分:秒' ) ) ),
			self::field( 'text', 'upcomingLabel', '未开始文案', 'Starts in', array( 'group' => 'play', 'show_if' => array( 'showCountdown' => true ) ) ),
			self::field( 'text', 'activeLabel', '进行中文案', 'Ends in', array( 'group' => 'play', 'show_if' => array( 'showCountdown' => true ) ) ),
			self::field( 'text', 'endedLabel', '已结束文案', 'Offer ended', array( 'group' => 'play', 'show_if' => array( 'showCountdown' => true ) ) ),
			self::field( 'text', 'buttonLabel', '按钮标题', '', array( 'group' => 'button', 'help' => '留空不显示按钮；按钮链接到所选真实活动详情。' ) ),
			self::field( 'select', 'buttonEffect', '按钮特效', 'none', array( 'group' => 'button', 'options' => self::effect_options() ) ),
			self::field( 'number', 'effectDurationMs', '按钮动画时间', 1000, array( 'group' => 'button', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 1000, 'max' => 5000, 'step' => 100 ) ),
			self::field( 'number', 'effectIntervalMs', '按钮动画间隔时间', 1500, array( 'group' => 'button', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 500, 'max' => 5000, 'step' => 100 ) ),
			self::field( 'html', 'conditionTemplateHtml', '门槛文案模板', '', array( 'ui' => 'template', 'tokens' => array( '{{condition}}' ), 'help' => '支持 {{condition}}。留空按真实活动的金额或件数自动生成。仅支持安全的文字格式。' ) ),
			self::field( 'html', 'discountTemplateHtml', '优惠文案模板', '', array( 'ui' => 'template', 'tokens' => array( '{{discount}}' ), 'help' => '支持 {{discount}}。留空按真实优惠类型生成，不改变实际结账规则。仅支持安全的文字格式。' ) ),
		);
		if ( $columns ) {
			array_splice( $fields, 2, 0, array( self::field( 'number', 'columns', '每排数量', array( 'desktop' => 3, 'mobile' => 1 ), array( 'group' => 'layout', 'device' => true, 'ui' => 'range', 'min' => 1, 'max' => 6, 'uiMax' => array( 'desktop' => 5, 'mobile' => 2 ) ) ) ) );
		}
		$colors = array(
			'countdownBackground' => array( '倒计时背景颜色', '#111111' ),
			'countdownBorderColor' => array( '倒计时边框颜色', '#111111' ),
			'countdownNumberColor' => array( '倒计时数字颜色', '#ffffff' ),
			'countdownTextColor' => array( '倒计时文字颜色', '#333333' ),
			'conditionTextColor' => array( '门槛文字颜色', '#666666' ),
			'conditionBackground' => array( '门槛背景颜色', '#f7f7f7' ),
			'discountTextColor' => array( '优惠文字颜色', '#e62416' ),
			'discountBackground' => array( '优惠背景颜色', '#ffffff' ),
		);
		foreach ( $colors as $key => $color ) {
			$fields[] = self::field( 'color', $key, $color[0], $color[1], array( 'group' => 'style' ) );
		}
		return $fields;
	}

	private static function items( array $fields, int $max = 12 ): array {
		return array( 'label' => __( 'Items', 'peptide-core' ), 'fields' => $fields, 'default_count' => 0, 'min' => 0, 'max' => $max, 'title_field' => 'title' );
	}

	/** Raw definitions receive the same defaulting, sanitization and device fields as core blocks. */
	public static function definitions( array $defs ): array {
		$product = array_merge( self::heading(), self::source_fields(), self::layout_fields() );
		$names = array(
			'product_carousel' => __( 'Product carousel', 'peptide-core' ), 'product_scroll' => __( 'Scrolling products', 'peptide-core' ),
			'product_waterfall' => __( 'Product masonry', 'peptide-core' ), 'product_ranking' => __( 'Product sales ranking', 'peptide-core' ),
			'product_comparison' => __( 'Product price comparison', 'peptide-core' ),
		);
		foreach ( $names as $type => $name ) {
			$defs[ $type ] = array( 'title' => $name, 'category' => 'product', 'icon' => 'products', 'settings' => $product );
		}
		foreach ( array( 'product_carousel', 'product_scroll' ) as $type ) {
			$defs[ $type ]['settings'][] = self::field( 'toggle', 'autoplay', __( 'Autoplay', 'peptide-core' ), false );
			$defs[ $type ]['settings'][] = self::field( 'number', 'intervalMs', __( 'Autoplay interval (ms)', 'peptide-core' ), 5000, array( 'min' => 2000, 'max' => 20000 ) );
		}
		$defs['featured_product'] = array( 'title' => __( 'Featured product', 'peptide-core' ), 'category' => 'product', 'icon' => 'products', 'settings' => array_merge( self::heading(), array( self::field( 'product', 'product', __( 'Product', 'peptide-core' ) ) ) ) );
		$defs['video_products'] = array( 'title' => __( 'Video product list', 'peptide-core' ), 'category' => 'product', 'icon' => 'video-alt3', 'settings' => array_merge( self::heading(), self::layout_fields() ), 'items' => self::items( array( self::field( 'product', 'product', __( 'Product', 'peptide-core' ) ), self::field( 'video', 'video', __( 'Product video', 'peptide-core' ) ) ) ) );
		$defs['recent_products'] = array( 'title' => __( 'Recently viewed products', 'peptide-core' ), 'category' => 'product', 'icon' => 'visibility', 'settings' => array_merge( self::heading(), self::layout_fields() ), 'singleRender' => true );
		$category_rows = self::items( array( self::field( 'category', 'category', __( 'Category', 'peptide-core' ) ) ) );
		foreach ( array( 'category_list' => __( 'Category list', 'peptide-core' ), 'featured_categories' => __( 'Featured categories', 'peptide-core' ), 'category_navigation' => __( 'Category navigation', 'peptide-core' ) ) as $type => $name ) {
			$defs[ $type ] = array( 'title' => $name, 'category' => 'product', 'icon' => 'category', 'settings' => array_merge( self::heading(), self::layout_fields() ), 'items' => $category_rows );
		}
		$defs['product_tabs'] = array( 'title' => __( 'Product tabs', 'peptide-core' ), 'category' => 'product', 'icon' => 'index-card', 'settings' => array_merge( self::heading(), self::layout_fields() ), 'items' => self::items( array_merge( array( self::field( 'text', 'title', __( 'Tab title', 'peptide-core' ), '' ) ), self::source_fields() ) ) );
		$defs['category_tabs'] = array( 'title' => __( 'Category tabs', 'peptide-core' ), 'category' => 'product', 'icon' => 'index-card', 'settings' => array_merge( self::heading(), self::layout_fields() ), 'items' => self::items( array( self::field( 'text', 'title', __( 'Tab title', 'peptide-core' ), '' ), self::field( 'repeater', 'categories', __( 'Categories', 'peptide-core' ), null, array( 'max' => 12, 'fields' => $category_rows['fields'] ) ) ) ) );
		$defs['topic_tabs'] = array( 'title' => __( 'Campaign page tabs', 'peptide-core' ), 'category' => 'product', 'icon' => 'index-card', 'settings' => self::heading(), 'items' => self::items( array( self::field( 'text', 'title', __( 'Tab title', 'peptide-core' ), '' ), self::field( 'repeater', 'pages', __( 'Campaign pages', 'peptide-core' ), null, array( 'max' => 12, 'fields' => array( self::field( 'record', 'page', __( 'Campaign page', 'peptide-core' ), null, array( 'resource' => 'topic' ) ) ) ) ) ) ) );

		$defs['promotion_card'] = array( 'title' => __( 'Timed promotion card', 'peptide-core' ), 'category' => 'operation', 'icon' => 'clock', 'singleRender' => true, 'settings' => array_merge( self::heading(), array(
			self::field( 'select', 'activityType', __( 'Activity type', 'peptide-core' ), 'promotion', array( 'options' => array( 'promotion' => __( 'Promotion', 'peptide-core' ), 'product_offer' => __( 'Gift or product bundle', 'peptide-core' ) ) ) ),
			self::field( 'record', 'promotion', __( 'Promotion', 'peptide-core' ), null, array( 'resource' => 'promotion', 'show_if' => array( 'activityType' => 'promotion' ) ) ),
			self::field( 'record', 'offer', __( 'Gift or product bundle', 'peptide-core' ), null, array( 'resource' => 'product_offer', 'show_if' => array( 'activityType' => 'product_offer' ) ) ),
			self::field( 'image', 'image', __( 'Image', 'peptide-core' ) ),
		) ) );
		$defs['product_offer'] = array( 'title' => __( 'Gift or product bundle', 'peptide-core' ), 'category' => 'operation', 'icon' => 'products', 'singleRender' => true, 'settings' => array_merge( self::heading(), array( self::field( 'record', 'offer', __( 'Gift or product bundle', 'peptide-core' ), null, array( 'resource' => 'product_offer' ) ) ) ) );
		$defs['subscribe'] = array( 'title' => __( 'Email subscription', 'peptide-core' ), 'category' => 'operation', 'icon' => 'email', 'singleRender' => true, 'settings' => array_merge( self::heading(), array( self::field( 'text', 'buttonLabel', __( 'Button text', 'peptide-core' ), __( 'Subscribe', 'peptide-core' ) ), self::field( 'text', 'consentText', __( 'Consent text', 'peptide-core' ), __( 'I agree to receive store emails. I can unsubscribe at any time.', 'peptide-core' ) ) ) ) );
		$defs['announcement'] = array( 'title' => __( 'Campaign announcement', 'peptide-core' ), 'category' => 'operation', 'icon' => 'megaphone', 'settings' => array_merge( self::heading(), array(
			self::field( 'select', 'mode', __( 'Announcement source', 'peptide-core' ), 'manual', array( 'options' => array( 'manual' => __( 'Write announcements', 'peptide-core' ), 'bound' => __( 'Use real activities', 'peptide-core' ) ) ) ),
			self::field( 'repeater', 'bindings', __( 'Bound activities', 'peptide-core' ), null, array( 'show_if' => array( 'mode' => 'bound' ), 'max' => 12, 'fields' => self::activity_fields() ) ),
			self::field( 'toggle', 'autoplay', __( 'Autoplay', 'peptide-core' ), false ), self::field( 'number', 'intervalMs', __( 'Autoplay interval (ms)', 'peptide-core' ), 5000, array( 'min' => 2000, 'max' => 20000 ) ),
		) ), 'items' => self::items( array( self::field( 'text', 'title', __( 'Announcement', 'peptide-core' ), '' ), self::field( 'link', 'link', __( 'Link', 'peptide-core' ) ) ) ) );
		foreach ( array( 'coupon_list' => __( 'Public coupon codes', 'peptide-core' ), 'promotion_list' => __( 'Spend-and-save offers', 'peptide-core' ), 'shipping_discount_list' => __( 'Shipping discounts', 'peptide-core' ), 'text_reviews' => __( 'Customer reviews', 'peptide-core' ), 'image_reviews' => __( 'Customer photo reviews', 'peptide-core' ), 'video_reviews' => __( 'Customer video reviews', 'peptide-core' ), 'featured_reviews' => __( 'Featured customer reviews', 'peptide-core' ) ) as $type => $name ) {
			$defs[ $type ] = array( 'title' => $name, 'category' => 'operation', 'icon' => 'format-quote', 'settings' => array_merge( self::heading(), self::layout_fields() ) );
		}
		foreach ( array( 'coupon_list' => 'coupon', 'promotion_list' => 'promotion', 'shipping_discount_list' => 'shipping_discount' ) as $type => $resource ) {
			$defs[ $type ]['settings'] = array_merge( $defs[ $type ]['settings'], array(
				self::field( 'select', 'selection', __( 'Activities to show', 'peptide-core' ), 'auto', array( 'options' => array( 'auto' => __( 'All public activities', 'peptide-core' ), 'manual' => __( 'Choose activities', 'peptide-core' ) ) ) ),
				self::field( 'repeater', 'records', __( 'Selected activities', 'peptide-core' ), null, array( 'show_if' => array( 'selection' => 'manual' ), 'max' => 24, 'fields' => array( self::field( 'record', 'record', __( 'Activity', 'peptide-core' ), null, array( 'resource' => $resource ) ) ) ) ),
			) );
		}
		foreach ( array( 'promotion_list', 'promotion_card' ) as $type ) {
			foreach ( $defs[ $type ]['settings'] as &$field ) {
				if ( 'description' === $field['key'] ) { $field['uiHidden'] = true; }
				if ( in_array( $field['key'], array( 'limit', 'columns' ), true ) ) { $field['group'] = 'layout'; }
				if ( 'columns' === $field['key'] ) { $field['ui'] = 'range'; $field['uiMax'] = array( 'desktop' => 5, 'mobile' => 2 ); }
			}
			unset( $field );
			$defs[ $type ]['settings'] = array_merge( $defs[ $type ]['settings'], self::campaign_fields( 'promotion_card' === $type ) );
			$defs[ $type ]['editorDefaults'] = array( 'columns' => array( 'desktop' => 3, 'mobile' => 1 ) );
			if ( 'promotion_list' === $type ) { $defs[ $type ]['editorDefaults']['selection'] = 'manual'; }
			$defs[ $type ]['icon'] = 'tickets-alt';
			$panels = array(
				array( 'id' => 'activity', 'label' => '关联活动', 'initialOpen' => true, 'fields' => array( 'activityType', 'promotion', 'offer', 'selection', 'records' ) ),
				array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml', 'image' ) ),
				array( 'id' => 'layout', 'label' => '宽度与排列', 'initialOpen' => true, 'fields' => array( 'fullWidth', 'columns', 'tierLayout', 'limit' ) ),
				array( 'id' => 'countdown', 'label' => '活动倒计时', 'fields' => array( 'showCountdown', 'countdownFormat', 'upcomingLabel', 'activeLabel', 'endedLabel' ) ),
				array( 'id' => 'button', 'label' => '活动按钮', 'fields' => array( 'buttonLabel', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
				array( 'id' => 'wording', 'label' => '门槛与优惠文案', 'fields' => array( 'conditionTemplateHtml', 'discountTemplateHtml' ) ),
				array( 'id' => 'style', 'label' => '优惠与倒计时颜色', 'fields' => array( 'countdownBackground', 'countdownBorderColor', 'countdownNumberColor', 'countdownTextColor', 'conditionTextColor', 'conditionBackground', 'discountTextColor', 'discountBackground' ) ),
				array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
			);
			foreach ( $defs[ $type ]['settings'] as &$field ) {
				foreach ( $panels as $panel ) { if ( in_array( $field['key'], $panel['fields'], true ) ) { $field['panel'] = $panel['id']; break; } }
			}
			unset( $field );
			$defs[ $type ]['panels'] = $panels;
		}
		// Keep original selection/layout keys so older coupon lists survive their next save.
		foreach ( $defs['coupon_list']['settings'] as &$field ) {
			if ( in_array( $field['key'], array( 'description', 'columns' ), true ) ) { $field['uiHidden'] = true; }
			if ( 'records' === $field['key'] ) { $field['label'] = '优惠券'; $field['fields'][0]['label'] = '选择优惠券'; }
			$field['panel'] = in_array( $field['key'], array( 'selection', 'records' ), true ) ? 'activity' : ( 'limit' === $field['key'] ? 'layout' : 'content' );
		}
		unset( $field );
		$defs['coupon_list']['settings'][] = self::field( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'panel' => 'content' ) );
		$defs['coupon_list']['settings'][] = self::field( 'color', 'themeColor', '主题颜色', '#202020', array( 'group' => 'style', 'panel' => 'style' ) );
		$defs['coupon_list']['icon'] = 'tickets-alt';
		$defs['coupon_list']['editorDefaults'] = array( 'title' => 'Coupons', 'descriptionHtml' => '<p>Copy a coupon code below and apply it when checkout</p>', 'selection' => 'manual' );
		$defs['coupon_list']['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
			array( 'id' => 'style', 'label' => '风格设置', 'initialOpen' => true, 'fields' => array( 'themeColor' ) ),
			array( 'id' => 'activity', 'label' => '优惠券', 'initialOpen' => true, 'fields' => array( 'selection', 'records' ) ),
			array( 'id' => 'layout', 'label' => '展示数量', 'fields' => array( 'limit' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);
		foreach ( $defs['product_tabs']['settings'] as &$field ) {
			if ( 'description' === $field['key'] ) { $field['uiHidden'] = true; }
			if ( 'columns' === $field['key'] ) { $field['ui'] = 'range'; }
			$field['panel'] = in_array( $field['key'], array( 'limit', 'columns' ), true ) ? 'layout' : 'content';
		}
		unset( $field );
		$defs['product_tabs']['settings'] = array_merge( $defs['product_tabs']['settings'], array(
			self::field( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'panel' => 'content' ) ),
			self::field( 'toggle', 'fullWidth', '宽度铺满', false, array( 'group' => 'layout', 'panel' => 'layout' ) ),
			self::field( 'select', 'switchMode', '专辑切换方式', 'click', array( 'group' => 'play', 'panel' => 'layout', 'options' => array( 'click' => '鼠标点击', 'hover' => '鼠标移动' ), 'help' => '手机始终支持点击，键盘始终支持方向键切换。' ) ),
			self::field( 'text', 'viewMoreLabel', '查看更多文案', '', array( 'panel' => 'button', 'group' => 'button', 'help' => '留空不显示按钮；选择专辑后链接该专辑，全部商品链接商店。' ) ),
			self::field( 'select', 'buttonEffect', '按钮特效', 'none', array( 'panel' => 'button', 'group' => 'button', 'options' => self::effect_options() ) ),
			self::field( 'number', 'effectDurationMs', '按钮动画时间', 1000, array( 'panel' => 'button', 'group' => 'button', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 1000, 'max' => 5000, 'step' => 100 ) ),
			self::field( 'number', 'effectIntervalMs', '按钮动画间隔时间', 1500, array( 'panel' => 'button', 'group' => 'button', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 500, 'max' => 5000, 'step' => 100 ) ),
		) );
		$defs['product_tabs']['items']['label'] = '选择专辑';
		$defs['product_tabs']['items']['editorDefaults'] = array( 'source' => 'category', 'category' => null );
		foreach ( $defs['product_tabs']['items']['fields'] as &$field ) {
			if ( 'category' === $field['key'] ) { $field['ui'] = 'collection'; $field['label'] = '选择专辑'; }
			if ( 'title' === $field['key'] ) { $field['label'] = '标签标题'; $field['help'] = '留空时使用所选专辑的名称。'; }
		}
		unset( $field );
		$defs['product_tabs']['editorDefaults'] = array( 'viewMoreLabel' => 'View More', 'switchMode' => 'hover' );
		$defs['product_tabs']['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
			array( 'id' => 'layout', 'label' => '宽度与商品排列', 'initialOpen' => true, 'fields' => array( 'fullWidth', 'limit', 'columns', 'switchMode' ) ),
			array( 'id' => 'button', 'label' => '查看更多按钮', 'fields' => array( 'viewMoreLabel', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);
		$defs['youtube_list'] = array( 'title' => __( 'YouTube video list', 'peptide-core' ), 'category' => 'operation', 'icon' => 'video-alt3', 'settings' => array_merge( self::heading(), self::layout_fields() ), 'items' => self::items( array( self::field( 'text', 'title', __( 'Video title', 'peptide-core' ), '' ), self::field( 'url', 'url', __( 'YouTube URL', 'peptide-core' ), '' ) ) ) );
		$defs['social_links'] = array( 'title' => __( 'Social media links', 'peptide-core' ), 'category' => 'operation', 'icon' => 'share', 'settings' => self::heading(), 'items' => self::items( array( self::field( 'text', 'title', __( 'Network name', 'peptide-core' ), '' ), self::field( 'url', 'url', __( 'Profile URL', 'peptide-core' ), '' ) ) ) );
		$defs['partners'] = array( 'title' => __( 'Partner logos', 'peptide-core' ), 'category' => 'operation', 'icon' => 'groups', 'settings' => array_merge( self::heading(), self::layout_fields() ), 'items' => self::items( array( self::field( 'text', 'title', __( 'Partner name', 'peptide-core' ), '' ), self::field( 'image', 'image', __( 'Logo', 'peptide-core' ) ), self::field( 'url', 'url', __( 'Website', 'peptide-core' ), '' ) ) ) );
		$defs['product_search'] = array( 'title' => __( 'Advanced product search', 'peptide-core' ), 'category' => 'operation', 'icon' => 'search', 'singleRender' => true, 'settings' => array_merge( self::heading(), array( self::field( 'toggle', 'priceRange', __( 'Show price range fields', 'peptide-core' ), true ) ) ) );
		$defs['search_terms'] = array( 'title' => __( 'Suggested product searches', 'peptide-core' ), 'category' => 'operation', 'icon' => 'search', 'settings' => self::heading(), 'items' => self::items( array( self::field( 'text', 'title', __( 'Search term', 'peptide-core' ), '' ) ) ) );
		return Review_Blocks::definitions( Content_Blocks::definitions( $defs ) );
	}

	private static function activity_fields(): array {
		$fields = array( self::field( 'select', 'activityType', __( 'Activity type', 'peptide-core' ), 'promotion', array( 'options' => array( 'promotion' => __( 'Promotion', 'peptide-core' ), 'coupon' => __( 'Coupon code', 'peptide-core' ), 'shipping_discount' => __( 'Shipping discount', 'peptide-core' ), 'product_offer' => __( 'Gift or product bundle', 'peptide-core' ) ) ) ) );
		foreach ( array( 'promotion' => 'promotion', 'coupon' => 'coupon', 'shippingDiscount' => 'shipping_discount', 'offer' => 'product_offer' ) as $key => $resource ) { $fields[] = self::field( 'record', $key, __( 'Activity', 'peptide-core' ), null, array( 'resource' => $resource, 'show_if' => array( 'activityType' => $resource ) ) ); }
		return $fields;
	}

	private static function assets(): void {
		$base = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		wp_enqueue_style( 'pc-commerce-blocks', $base . 'commerce-blocks.css', array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/commerce-blocks.css' ) );
		wp_enqueue_script( 'pc-commerce-blocks', $base . 'commerce-blocks.js', array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/commerce-blocks.js' ), true );
	}

	private static function rows( array $items ): array {
		return array_values( array_filter( $items, static fn( $row ) => ! empty( $row['enabled'] ) ) );
	}

	private static function heading_html( array $s ): string {
		return ( '' !== $s['title'] ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '' ) . ( '' !== $s['description'] ? '<p class="pc-cb-description">' . nl2br( esc_html( $s['description'] ) ) . '</p>' : '' );
	}

	public static function grid( array $s, string $extra = '' ): string {
		$cols = $s['columns'] ?? array( 'desktop' => 4, 'mobile' => 2 );
		return '<div class="pc-cb-grid ' . esc_attr( $extra ) . '" style="--pc-cb-cols:' . max( 1, min( 6, (int) $cols['desktop'] ) ) . ';--pc-cb-cols-m:' . max( 1, min( 6, (int) ( $cols['mobile'] ?? $cols['desktop'] ) ) ) . '">';
	}

	private static function controls( bool $autoplay ): string {
		return '<div class="pc-cb-controls"><button type="button" data-pc-cb-prev aria-label="' . esc_attr__( 'Previous items', 'peptide-core' ) . '">←</button><button type="button" data-pc-cb-next aria-label="' . esc_attr__( 'Next items', 'peptide-core' ) . '">→</button>' . ( $autoplay ? '<button type="button" data-pc-cb-toggle aria-pressed="false" data-pause="' . esc_attr__( 'Pause slideshow', 'peptide-core' ) . '" data-play="' . esc_attr__( 'Play slideshow', 'peptide-core' ) . '">' . esc_html__( 'Pause slideshow', 'peptide-core' ) . '</button>' : '' ) . '</div>';
	}

	public static function card( \WC_Product $product ): string {
		if ( 'publish' !== $product->get_status() || ! $product->is_visible() ) { return ''; }
		if ( function_exists( 'vc_product_card' ) ) {
			ob_start(); vc_product_card( $product ); $html = (string) ob_get_clean();
		} else {
			ob_start(); do_action( 'peptide_core_product_card_extras', $product ); $extras = (string) ob_get_clean();
			$html = '<article class="pc-cb-card"><a href="' . esc_url( $product->get_permalink() ) . '">' . wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ) . '<h3>' . esc_html( $product->get_name() ) . '</h3></a><div class="price">' . wp_kses_post( $product->get_price_html() ) . '</div>' . $extras . '<a href="' . esc_url( $product->get_permalink() ) . '">' . esc_html__( 'View product', 'peptide-core' ) . '</a></article>';
		}
		return (string) apply_filters( 'peptide_core_commerce_product_card_html', $html, $product );
	}

	public static function products( array $settings, bool $ranked = false ): array {
		if ( ! function_exists( 'wc_get_products' ) ) { return array(); }
		if ( ! $ranked ) { return Products::for_module( array( 'settings' => $settings ) ); }
		if ( 'manual' === ( $settings['source'] ?? '' ) ) {
			$products = Products::for_module( array( 'settings' => array_merge( $settings, array( 'limit' => 24 ) ) ) );
		} else {
			$args = array( 'status' => 'publish', 'visibility' => 'visible', 'limit' => max( 1, min( 24, (int) ( $settings['limit'] ?? 8 ) ) ), 'return' => 'objects', 'meta_key' => 'total_sales', 'orderby' => array( 'meta_value_num' => 'DESC', 'ID' => 'ASC' ) );
			if ( 'category' === ( $settings['source'] ?? '' ) ) {
				$term = get_term( (int) ( $settings['category'] ?? 0 ), 'product_cat' );
				if ( ! $term instanceof \WP_Term ) { return array(); }
				$args['category'] = array( $term->slug );
			}
			$products = wc_get_products( $args );
		}
		usort( $products, static fn( $a, $b ) => $b->get_total_sales() <=> $a->get_total_sales() ?: $a->get_id() <=> $b->get_id() );
		return array_slice( $products, 0, max( 1, min( 24, (int) ( $settings['limit'] ?? 8 ) ) ) );
	}

	private static function image( ?array $reference, string $size = 'medium' ): string {
		$image = Assets::resolve( $reference, $size );
		return $image ? '<img loading="lazy" src="' . esc_url( $image['src'] ) . '" alt="' . esc_attr( $image['alt'] ) . '"' . ( $image['width'] ? ' width="' . (int) $image['width'] . '"' : '' ) . ( $image['height'] ? ' height="' . (int) $image['height'] . '"' : '' ) . ( $image['srcset'] ? ' srcset="' . esc_attr( $image['srcset'] ) . '"' : '' ) . '>' : '';
	}

	private static function comparison( array $products ): string {
		$products = array_values( array_filter( $products, static fn( $product ) => 'publish' === $product->get_status() && $product->is_visible() ) );
		if ( ! $products ) { return ''; }
		$attributes = array();
		foreach ( $products as $product ) { foreach ( $product->get_attributes() as $key => $attribute ) { if ( $attribute->get_visible() ) { $attributes[ $key ] = wc_attribute_label( $attribute->get_name(), $product ); } } }
		$out = '<div class="pc-cb-compare-wrap" tabindex="0"><table class="pc-cb-compare"><caption class="screen-reader-text">' . esc_html__( 'Compare current product prices and attributes', 'peptide-core' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Product', 'peptide-core' ) . '</th>';
		foreach ( $products as $product ) { $out .= '<th scope="col">' . self::card( $product ) . '</th>'; }
		$out .= '</tr></thead><tbody><tr><th scope="row">' . esc_html__( 'Price', 'peptide-core' ) . '</th>';
		foreach ( $products as $product ) { $out .= '<td>' . wp_kses_post( $product->get_price_html() ) . '</td>'; }
		$out .= '</tr><tr><th scope="row">' . esc_html__( 'Availability', 'peptide-core' ) . '</th>';
		foreach ( $products as $product ) { $stock = wc_get_stock_html( $product ); $out .= '<td>' . ( $stock ? wp_kses_post( $stock ) : esc_html( $product->is_in_stock() ? __( 'In stock', 'peptide-core' ) : __( 'Out of stock', 'peptide-core' ) ) ) . '</td>'; }
		$out .= '</tr>';
		foreach ( array_slice( $attributes, 0, 12, true ) as $key => $label ) {
			$out .= '<tr><th scope="row">' . esc_html( $label ) . '</th>';
			foreach ( $products as $product ) { $out .= '<td>' . esc_html( $product->get_attribute( $key ) ?: '—' ) . '</td>'; }
			$out .= '</tr>';
		}
		return $out . '</tbody></table></div>';
	}

	/** Browser-owned history, public product views only; never writes user profiles. */
	public static function record_recent(): void {
		if ( ! is_admin() && ! headers_sent() && ! empty( $_COOKIE[ self::RECENT_COOKIE ] ) ) { nocache_headers(); if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } }
		if ( is_admin() || is_preview() || ! function_exists( 'is_product' ) || ! is_product() || \PeptideCore\Templates::previewing_id() || headers_sent() ) { return; }
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_visible() ) { return; }
		$ids = self::recent_ids();
		array_unshift( $ids, $product->get_id() );
		$ids = array_slice( array_values( array_unique( $ids ) ), 0, 24 );
		$value = implode( ',', $ids );
		wc_setcookie( self::RECENT_COOKIE, $value, time() + MONTH_IN_SECONDS, is_ssl(), true );
		$_COOKIE[ self::RECENT_COOKIE ] = $value;
	}

	public static function recent_ids(): array {
		$raw = isset( $_COOKIE[ self::RECENT_COOKIE ] ) && is_string( $_COOKIE[ self::RECENT_COOKIE ] ) ? substr( wp_unslash( $_COOKIE[ self::RECENT_COOKIE ] ), 0, 600 ) : '';
		return array_slice( array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) ), 0, 24 );
	}

	private static function category_cards( array $rows, array $s, bool $navigation = false ): string {
		$terms = array();
		foreach ( self::rows( $rows ) as $row ) {
			$term = get_term( (int) ( $row['settings']['category'] ?? 0 ), 'product_cat' );
			if ( $term instanceof \WP_Term ) { $terms[ $term->term_id ] = $term; }
		}
		if ( ! $terms ) { $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => (int) ( $s['limit'] ?? 8 ) ) ); }
		if ( is_wp_error( $terms ) || ! $terms ) { return ''; }
		$html = self::grid( $s, $navigation ? 'pc-cb-category-nav' : '' );
		foreach ( array_slice( array_values( $terms ), 0, (int) ( $s['limit'] ?? 8 ) ) as $term ) {
			$url = get_term_link( $term ); if ( is_wp_error( $url ) ) { continue; }
			$html .= '<a class="pc-cb-card" href="' . esc_url( $url ) . '">' . ( ! $navigation ? wp_get_attachment_image( (int) get_term_meta( $term->term_id, 'thumbnail_id', true ), 'woocommerce_thumbnail' ) : '' ) . '<h3>' . esc_html( $term->name ) . '</h3></a>';
		}
		return $html . '</div>';
	}

	private static function tabs( array $module ): string {
		$items = self::rows( $module['items'] ); if ( ! $items ) { return ''; }
		$id = wp_unique_id( 'pc-cb-tabs-' ); $buttons = ''; $panels = '';
		foreach ( $items as $i => $row ) {
			$s = $row['settings']; $panel = '';
			if ( 'product_tabs' === $module['type'] ) {
				$products = self::products( $s + $module['settings'] );
				$panel = $products ? self::grid( $module['settings'] ) . implode( '', array_map( array( self::class, 'card' ), $products ) ) . '</div>' : '';
			} elseif ( 'category_tabs' === $module['type'] ) {
				$panel = self::category_cards( (array) ( $s['categories'] ?? array() ), $module['settings'] );
			} else {
				$panel = self::grid( $module['settings'] );
				foreach ( self::rows( (array) ( $s['pages'] ?? array() ) ) as $page ) { $panel .= self::post_card( (int) ( $page['settings']['page'] ?? 0 ), 'pc_topic' ); }
				$panel .= '</div>';
			}
			$label = '' !== $s['title'] ? $s['title'] : sprintf( __( 'Tab %d', 'peptide-core' ), $i + 1 );
			$buttons .= '<button type="button" role="tab" id="' . esc_attr( $id . '-tab-' . $i ) . '" aria-controls="' . esc_attr( $id . '-panel-' . $i ) . '" aria-selected="' . ( $i ? 'false' : 'true' ) . '" tabindex="' . ( $i ? '-1' : '0' ) . '">' . esc_html( $label ) . '</button>';
			$panels .= '<div role="tabpanel" data-module-item="' . esc_attr( $row['id'] ) . '" id="' . esc_attr( $id . '-panel-' . $i ) . '" aria-labelledby="' . esc_attr( $id . '-tab-' . $i ) . '"' . ( $i ? ' hidden' : '' ) . '>' . ( $panel ?: '<p>' . esc_html__( 'No published items in this tab.', 'peptide-core' ) . '</p>' ) . '</div>';
		}
		return '<div data-pc-cb-tabs><div class="pc-cb-tablist" role="tablist" aria-label="' . esc_attr( $module['settings']['title'] ?: __( 'Collections', 'peptide-core' ) ) . '">' . $buttons . '</div>' . $panels . '</div>';
	}

	/** Existing per-tab catalog/category/manual sources remain authoritative. */
	private static function product_tabs( array $module, string $context ): string {
		$items = self::rows( $module['items'] );
		if ( ! $items ) { return ''; }
		$s = $module['settings']; $buttons = ''; $panels = ''; $id = wp_unique_id( 'pc-product-tabs-' );
		foreach ( $items as $index => $row ) {
			$settings = $row['settings']; $source = $settings['source'] ?? 'catalog'; $term = null; $url = '';
			if ( 'category' === $source ) {
				$term = get_term( (int) ( $settings['category'] ?? 0 ), 'product_cat' );
				if ( $term instanceof \WP_Term ) { $link = get_term_link( $term ); if ( ! is_wp_error( $link ) ) { $url = (string) $link; } }
			} elseif ( 'catalog' === $source ) { $url = (string) wc_get_page_permalink( 'shop' ); }
			$label = trim( (string) ( $settings['title'] ?? '' ) );
			if ( '' === $label ) { $label = $term instanceof \WP_Term ? $term->name : sprintf( __( 'Tab %d', 'peptide-core' ), $index + 1 ); }
			$products = self::products( $settings + $s ); $cards = '';
			foreach ( $products as $product ) { $card = self::card( $product ); if ( '' !== $card ) { $cards .= '<div class="pc-cb-product">' . $card . '</div>'; } }
			$panel = $cards ? self::grid( $s ) . $cards . '</div>' : '<p class="pc-product-tabs__empty">' . esc_html__( 'No eligible published products in this collection.', 'peptide-core' ) . '</p>';
			$button_label = (string) ( $s['viewMoreLabel'] ?? '' );
			if ( $cards && '' !== trim( $button_label ) && '' !== $url ) { $panel .= self::action_button( $s, $url, $button_label, $context ); }
			$buttons .= '<button type="button" role="tab" id="' . esc_attr( $id . '-tab-' . $index ) . '" aria-controls="' . esc_attr( $id . '-panel-' . $index ) . '" aria-selected="' . ( $index ? 'false' : 'true' ) . '" tabindex="' . ( $index ? '-1' : '0' ) . '">' . esc_html( $label ) . '</button>';
			$panels .= '<div role="tabpanel" data-module-item="' . esc_attr( $row['id'] ) . '" id="' . esc_attr( $id . '-panel-' . $index ) . '" aria-labelledby="' . esc_attr( $id . '-tab-' . $index ) . '"' . ( $index ? ' hidden' : '' ) . '>' . $panel . '</div>';
		}
		$description = self::campaign_description( $s, array() );
		$heading = ( '' !== (string) ( $s['title'] ?? '' ) ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-product-tabs__description">' . $description . '</div>' : '' );
		$mode = 'hover' === ( $s['switchMode'] ?? 'click' ) ? 'hover' : 'click';
		return '<div class="pc-product-tabs" data-pc-cb-tabs data-switch-mode="' . $mode . '" style="--pc-campaign-effect-duration:' . max( 1000, min( 5000, (int) ( $s['effectDurationMs'] ?? 1000 ) ) ) . 'ms">' . ( $heading ? '<header class="pc-product-tabs__head">' . $heading . '</header>' : '' ) . '<div class="pc-cb-tablist" role="tablist" aria-label="' . esc_attr( $s['title'] ?: __( 'Collections', 'peptide-core' ) ) . '">' . $buttons . '</div>' . $panels . '</div>';
	}

	public static function post_card( int $id, string $type = 'post' ): string {
		$post = get_post( $id ); if ( ! $post || $type !== $post->post_type || 'publish' !== $post->post_status || post_password_required( $post ) ) { return ''; }
		return '<article class="pc-cb-card"><a href="' . esc_url( get_permalink( $id ) ) . '">' . get_the_post_thumbnail( $id, 'medium' ) . '<h3>' . esc_html( get_the_title( $id ) ) . '</h3></a><p>' . esc_html( wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 30 ) ) . '</p></article>';
	}

	/** Personalized activity visibility must not be cached for another customer. */
	private static function activity_rule( array $rule, bool $member_day = false ): bool {
		if ( 'all' !== ( $rule['customers'] ?? 'all' ) || $member_day ) { self::activity_no_cache(); }
		return Customer_Segments::matches( $rule, Customer_Segments::current_email() );
	}

	private static function activity_no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		if ( ! headers_sent() ) { nocache_headers(); }
	}

	private static function interactive( string $context ): bool { return 'public' === $context && ! Renderer::$in_editor && ! Templates::previewing_id(); }

	/** Build only public, presently eligible display data. The business engines remain authoritative. */
	private static function activity( string $type, int $id ): ?array {
		if ( ! $id ) { return null; }
		if ( 'promotion' === $type ) {
			$p = Promotions::get_public( $id ); $config = Promotions::config( $id );
			if ( ! $p || ! in_array( $p['status'], array( 'active', 'upcoming' ), true ) || ! self::activity_rule( $config['customer_rule'] ) ) { return null; }
			$rules = array_column( $p['tiers'], 'text' );
			if ( 'all' !== ( $config['customer_rule']['customers'] ?? 'all' ) ) { $rules[] = __( 'Available for your customer group.', 'peptide-core' ); }
			if ( ! $p['with_coupons'] ) { $rules[] = __( 'Cannot be combined with coupon codes.', 'peptide-core' ); }
			return array( 'id' => $id, 'type' => $type, 'title' => $p['title'], 'text' => $p['text'], 'rules' => $rules, 'url' => $p['url'], 'start' => (int) $config['start'], 'end' => (int) $config['end'], 'status' => $p['status'], 'tiers' => $p['tiers'], 'basis' => $p['basis'], 'reward' => $p['reward'], 'promotion_type' => $p['type'], 'unlimited' => (bool) $p['unlimited'], 'notes' => array_values( array_diff( $rules, array_column( $p['tiers'], 'text' ) ) ) );
		}
		if ( 'coupon' === $type ) {
			if ( 'shop_coupon' !== get_post_type( $id ) ) { return null; }
			$coupon = new \WC_Coupon( $id ); $p = Coupons::get_public( $coupon->get_code() );
			$rule = get_post_meta( $id, Customer_Segments::COUPON_META, true ); $rule = is_array( $rule ) ? $rule : array( 'customers' => 'all' );
			if ( ! $p || ! self::activity_rule( $rule ) ) { return null; }
			$rules = array( $p['value'] );
			if ( $p['minimum_spend'] ) { $rules[] = sprintf( __( 'Minimum spend: %s.', 'peptide-core' ), $p['minimum_spend_text'] ); }
			if ( $p['maximum_spend'] ) { $rules[] = sprintf( __( 'Maximum spend: %s.', 'peptide-core' ), $p['maximum_spend_text'] ); }
			if ( $p['eligible_spend'] ) { $rules[] = sprintf( __( 'Eligible items must total at least %s.', 'peptide-core' ), $p['eligible_spend_text'] ); }
			if ( $p['eligible_items'] ) { $rules[] = sprintf( __( 'At least %d eligible items.', 'peptide-core' ), $p['eligible_items'] ); }
			if ( $p['first_order'] ) { $rules[] = __( 'First order only.', 'peptide-core' ); }
			if ( $p['individual_use'] ) { $rules[] = __( 'Cannot be combined with other coupon codes.', 'peptide-core' ); }
			if ( 'all' !== ( $rule['customers'] ?? 'all' ) ) { $rules[] = __( 'Available for your customer group.', 'peptide-core' ); }
			return array( 'id' => $id, 'type' => $type, 'title' => $p['title'], 'text' => $p['text'], 'rules' => $rules, 'url' => $p['url'], 'code' => $p['code'], 'apply_url' => $p['apply_url'], 'start' => $p['starts'] ? (int) strtotime( $p['starts'] ) : 0, 'end' => $p['expires'] ? (int) strtotime( $p['expires'] ) : 0, 'status' => 'active', 'order' => $p['order'], 'coupon_type' => $p['type'], 'amount' => $p['amount'], 'expires_text' => $p['expires_text'], 'starts_text' => $p['starts_text'] );
		}
		if ( 'shipping_discount' === $type ) {
			$d = Discounts::get( $id ); if ( ! $d || ! self::activity_rule( $d, (bool) $d['member_day'] ) || ! Discounts::applies_to( $d, Customer_Segments::current_email(), get_current_user_id() ) ) { return null; }
			$value = Discounts::value_now( $d ); if ( null === $value ) { return null; }
			$rules = array( Discounts::value_text( $d['kind'], $value ) );
			if ( 'all' !== $d['customers'] ) { $rules[] = __( 'Available for your customer group.', 'peptide-core' ); }
			if ( $d['member_day'] ) { $rules[] = __( 'Member-day shipping offer.', 'peptide-core' ); }
			$rules[] = __( 'The best eligible shipping discount is applied at checkout.', 'peptide-core' );
			return array( 'id' => $id, 'type' => $type, 'title' => $d['name'], 'text' => '', 'rules' => $rules, 'url' => wc_get_cart_url(), 'start' => (int) $d['start'], 'end' => (int) $d['end'], 'status' => 'active' );
		}
		if ( 'product_offer' === $type ) {
			$offer = Product_Offers::get( $id ); if ( ! $offer || ! Product_Offers::active( $offer ) ) { return null; }
			$html = Product_Offers::render_form( $id ); if ( '' === $html ) { return null; }
			$ids = 'gift' === $offer['kind'] ? $offer['qualifiers'] : array_merge( ...array_column( $offer['slots'], 'choices' ) ); $url = (string) wc_get_page_permalink( 'shop' );
			foreach ( $ids as $pid ) { $product = wc_get_product( $pid ); if ( $product && 'publish' === $product->get_status() && ( ! $product->get_parent_id() || 'publish' === get_post_status( $product->get_parent_id() ) ) ) { $url = $product->get_permalink(); break; } }
			$rules = array( Product_Offers::policy() );
			if ( 'gift' === $offer['kind'] ) {
				$gift = wc_get_product( $offer['gift_product'] );
				$threshold = 'quantity' === $offer['basis'] ? (string) $offer['threshold'] : html_entity_decode( wp_strip_all_tags( wc_price( $offer['threshold'] ) ) );
				$rules[] = sprintf( 'quantity' === $offer['basis'] ? __( 'Buy at least %1$s eligible items and receive %2$s × %3$s automatically, while stock is available.', 'peptide-core' ) : __( 'Spend at least %1$s on eligible items and receive %2$s × %3$s automatically, while stock is available.', 'peptide-core' ), $threshold, $offer['gift_qty'], $gift ? $gift->get_name() : '' );
			} else { $rules[] = 'fixed' === $offer['price_type'] ? html_entity_decode( wp_strip_all_tags( wc_price( $offer['price_value'] ) ) ) : sprintf( __( '%s%% off the selected products', 'peptide-core' ), $offer['price_value'] ); }
			return array( 'id' => $id, 'type' => $type, 'title' => $offer['title'], 'text' => '', 'rules' => $rules, 'url' => $url, 'start' => (int) $offer['start'], 'end' => (int) $offer['end'], 'status' => 'active', 'offer_html' => $html );
		}
		return null;
	}

	private static function activity_ids( string $type, array $s ): array {
		if ( 'manual' === ( $s['selection'] ?? 'auto' ) ) { $ids = array(); foreach ( self::rows( (array) ( $s['records'] ?? array() ) ) as $row ) { $ids[] = absint( $row['settings']['record'] ?? 0 ); } return array_values( array_unique( array_filter( $ids ) ) ); }
		if ( 'shipping_discount' === $type ) { return array_keys( array_filter( Discounts::all(), static fn( $d ) => 'all' === $d['customers'] ) ); }
		$args = array( 'post_type' => 'coupon' === $type ? 'shop_coupon' : Promotions::CPT, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'menu_order date', 'order' => 'ASC' );
		if ( 'coupon' === $type ) { $args['meta_key'] = Coupons::PUBLIC_META; $args['meta_value'] = 'yes'; }
		return array_map( 'intval', get_posts( $args ) );
	}

	private static function activity_period( array $a, bool $countdown = false ): string {
		$out = '';
		if ( $a['start'] && $a['start'] > time() ) { $out .= '<p>' . esc_html__( 'Starts', 'peptide-core' ) . ': <time datetime="' . esc_attr( gmdate( 'c', $a['start'] ) ) . '">' . esc_html( wp_date( 'Y-m-d H:i', $a['start'] ) ) . '</time></p>'; }
		if ( $a['end'] ) { $out .= '<p>' . esc_html__( 'Ends', 'peptide-core' ) . ': <time datetime="' . esc_attr( gmdate( 'c', $a['end'] ) ) . '">' . esc_html( wp_date( 'Y-m-d H:i', $a['end'] ) ) . '</time></p>'; if ( $countdown ) { $out .= '<output data-pc-cb-countdown="' . $a['end'] . '" data-ended="' . esc_attr__( 'Offer ended', 'peptide-core' ) . '" aria-live="off"></output>'; } }
		return $out;
	}

	private static function activity_card( array $a, string $context, bool $countdown = false, ?array $image = null ): string {
		$out = '<article class="pc-cb-card pc-cb-offer" data-pc-activity="' . esc_attr( $a['type'] ) . '" data-activity-id="' . (int) $a['id'] . '">' . self::image( $image, 'large' );
		if ( 'product_offer' === $a['type'] && self::interactive( $context ) ) { self::activity_no_cache(); return $out . $a['offer_html'] . self::activity_period( $a, $countdown ) . '</article>'; }
		$out .= '<h3><a href="' . esc_url( $a['url'] ) . '">' . esc_html( $a['title'] ) . '</a></h3>' . ( '' !== $a['text'] ? '<p>' . esc_html( $a['text'] ) . '</p>' : '' ) . '<ul>';
		foreach ( array_unique( $a['rules'] ) as $rule ) { $out .= '<li>' . esc_html( $rule ) . '</li>'; }
		$out .= '</ul>' . self::activity_period( $a, $countdown );
		if ( 'coupon' === $a['type'] ) {
			$out .= '<code>' . esc_html( $a['code'] ) . '</code><p><button type="button" data-pc-cb-copy="' . esc_attr( $a['code'] ) . '" data-copied="' . esc_attr__( 'Copied', 'peptide-core' ) . '" data-copy-fallback="' . esc_attr__( 'Code selected. Copy it to continue.', 'peptide-core' ) . '">' . esc_html__( 'Copy code', 'peptide-core' ) . '</button>';
			if ( self::interactive( $context ) ) { $out .= ' <a href="' . esc_url( $a['apply_url'] ) . '">' . esc_html__( 'Use code', 'peptide-core' ) . '</a>'; }
			$out .= ' <a href="' . esc_url( $a['url'] ) . '">' . esc_html__( 'Offer details', 'peptide-core' ) . '</a></p>';
		}
		if ( 'product_offer' === $a['type'] ) { $out .= '<p>' . esc_html__( 'Purchase actions are disabled in design previews.', 'peptide-core' ) . '</p>'; }
		return $out . '</article>';
	}

	private static function activity_list( string $type, array $s, string $context ): string {
		$activities = array();
		foreach ( self::activity_ids( $type, $s ) as $id ) { $activity = self::activity( $type, (int) $id ); if ( $activity ) { $activities[] = $activity; } }
		if ( 'coupon' === $type && 'manual' !== ( $s['selection'] ?? 'auto' ) ) { usort( $activities, static fn( $a, $b ) => array( $a['order'], $a['end'] ?: PHP_INT_MAX, $a['code'] ) <=> array( $b['order'], $b['end'] ?: PHP_INT_MAX, $b['code'] ) ); }
		$out = ''; foreach ( array_slice( $activities, 0, (int) ( $s['limit'] ?? 8 ) ) as $activity ) { $out .= self::activity_card( $activity, $context ); }
		return $out ? self::grid( $s ) . $out . '</div>' : '';
	}

	private static function campaign_value( $value, string $device, $fallback ) {
		if ( ! is_array( $value ) ) { return null === $value ? $fallback : $value; }
		return $value[ $device ] ?? $value['desktop'] ?? $fallback;
	}

	/** Inline formatting and the two documented tokens only; values are never executable templates. */
	private static function campaign_template( string $template, array $tokens ): string {
		$html = wp_kses( $template, array( 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'u' => array(), 's' => array(), 'span' => array(), 'br' => array() ) );
		foreach ( $tokens as $key => $value ) { $html = str_replace( '{{' . $key . '}}', esc_html( (string) $value ), $html ); }
		return $html;
	}

	private static function campaign_description( array $s, array $activity ): string {
		// Null/missing preserves old plain text. An explicitly empty rich description clears it.
		if ( array_key_exists( 'descriptionHtml', $s ) && null !== $s['descriptionHtml'] ) { return wp_kses_post( (string) $s['descriptionHtml'] ); }
		$text = '' !== (string) ( $s['description'] ?? '' ) ? (string) $s['description'] : (string) ( $activity['text'] ?? '' );
		return nl2br( esc_html( $text ) );
	}

	/** CSS values are independently allowlisted even when a renderer is called outside normalization. */
	private static function campaign_style( array $s ): string {
		$vars = array();
		foreach ( array( 'countdownBackground' => 'clock-bg', 'countdownBorderColor' => 'clock-border', 'countdownNumberColor' => 'clock-number', 'countdownTextColor' => 'clock-text', 'conditionTextColor' => 'condition-text', 'conditionBackground' => 'condition-bg', 'discountTextColor' => 'discount-text', 'discountBackground' => 'discount-bg' ) as $key => $name ) {
			$value = $s[ $key ] ?? '';
			if ( is_string( $value ) && preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) { $vars[] = '--pc-campaign-' . $name . ':' . $value; }
		}
		$vars[] = '--pc-campaign-cols:' . max( 1, min( 6, (int) self::campaign_value( $s['columns'] ?? null, 'desktop', 3 ) ) );
		$vars[] = '--pc-campaign-cols-m:' . max( 1, min( 6, (int) self::campaign_value( $s['columns'] ?? null, 'mobile', 1 ) ) );
		$vars[] = '--pc-campaign-effect-duration:' . max( 1000, min( 5000, (int) ( $s['effectDurationMs'] ?? 1000 ) ) ) . 'ms';
		return implode( ';', $vars );
	}

	private static function campaign_countdown( array $a, array $s ): string {
		if ( empty( $s['showCountdown'] ) ) { return ''; }
		$start = (int) ( $a['start'] ?? 0 ); $end = (int) ( $a['end'] ?? 0 ); $now = time();
		$upcoming = $start > $now; $deadline = $upcoming ? $start : $end;
		if ( ! $deadline ) { return ''; }
		$format = 'hms' === ( $s['countdownFormat'] ?? '' ) ? 'hms' : 'dhms';
		$label = $upcoming ? ( $s['upcomingLabel'] ?? 'Starts in' ) : ( $s['activeLabel'] ?? 'Ends in' );
		$seconds = max( 0, $deadline - $now );
		$parts = 'hms' === $format ? array( (int) floor( $seconds / 3600 ), (int) floor( $seconds / 60 ) % 60, $seconds % 60 ) : array( (int) floor( $seconds / 86400 ), (int) floor( $seconds / 3600 ) % 24, (int) floor( $seconds / 60 ) % 60, $seconds % 60 );
		$digits = array_map( static fn( $part ) => '<span class="pc-campaign__digit">' . esc_html( str_pad( (string) $part, 2, '0', STR_PAD_LEFT ) ) . '</span>', $parts );
		return '<div class="pc-campaign__countdown" data-pc-campaign-countdown data-start="' . $start . '" data-end="' . $end . '" data-format="' . esc_attr( $format ) . '" data-upcoming-label="' . esc_attr( $s['upcomingLabel'] ?? 'Starts in' ) . '" data-active-label="' . esc_attr( $s['activeLabel'] ?? 'Ends in' ) . '" data-ended-label="' . esc_attr( $s['endedLabel'] ?? 'Offer ended' ) . '"><span class="pc-campaign__countdown-label">' . esc_html( $label ) . '</span><output class="pc-campaign__clock" aria-live="off" aria-label="' . esc_attr( wp_date( 'Y-m-d H:i:s T', $deadline ) ) . '">' . implode( '<span class="pc-campaign__separator" aria-hidden="true">:</span>', $digits ) . '</output></div>';
	}

	private static function campaign_tier( array $a, array $tier, array $s ): string {
		$money = static fn( $value ) => html_entity_decode( wp_strip_all_tags( wc_price( (float) $value ) ), ENT_QUOTES, 'UTF-8' );
		$kind = (string) ( $a['promotion_type'] ?? 'spend_amount' );
		$condition = 'spend' === ( $a['basis'] ?? 'spend' ) ? $money( $tier['min'] ?? 0 ) : wc_format_localized_decimal( $tier['min'] ?? 0 );
		$discount = 'percent' === ( $a['reward'] ?? 'amount' ) ? wc_format_localized_decimal( $tier['value'] ?? 0 ) . '%' : $money( $tier['value'] ?? 0 );
		$condition_template = 'spend' === ( $a['basis'] ?? 'spend' ) ? 'Spend {{condition}}, get' : 'Buy {{condition}} items, get';
		$discount_template = '{{discount}} OFF';
		if ( ! empty( $a['unlimited'] ) ) { $condition_template = 'spend' === ( $a['basis'] ?? 'spend' ) ? 'Every {{condition}} spent' : 'Every {{condition}} items'; }
		if ( 'bundle_price' === $kind ) { $condition_template = 'Any {{condition}} items'; $discount_template = 'For {{discount}}'; }
		if ( 'buy_x_free_y' === $kind ) { $condition_template = 'Buy {{condition}} items'; $discount_template = '{{discount}} FREE'; $discount = wc_format_localized_decimal( $tier['value'] ?? 0 ); }
		if ( 'nth_percent' === $kind ) { $condition_template = 'Qualifying item {{condition}}'; }
		$condition_template = '' !== trim( (string) ( $s['conditionTemplateHtml'] ?? '' ) ) ? (string) $s['conditionTemplateHtml'] : $condition_template;
		$discount_template = '' !== trim( (string) ( $s['discountTemplateHtml'] ?? '' ) ) ? (string) $s['discountTemplateHtml'] : $discount_template;
		$tokens = array( 'condition' => $condition, 'discount' => $discount );
		$detail = ( ! empty( $a['unlimited'] ) || in_array( $kind, array( 'bundle_price', 'buy_x_free_y', 'nth_percent' ), true ) ) && ! empty( $tier['text'] ) ? '<p class="pc-campaign__tier-detail">' . esc_html( $tier['text'] ) . '</p>' : '';
		return '<div class="pc-campaign__tier"><div class="pc-campaign__condition">' . self::campaign_template( $condition_template, $tokens ) . '</div><div class="pc-campaign__discount">' . self::campaign_template( $discount_template, $tokens ) . '</div>' . $detail . '</div>';
	}

	private static function campaign_controls( int $count, string $rail_id ): string {
		$arrow = static fn( bool $next ) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . ( $next ? 'm9 6 6 6-6 6' : 'm15 6-6 6 6 6' ) . '"/></svg>';
		$dots = '';
		for ( $i = 0; $i < $count; $i++ ) { $dots .= '<button type="button" class="pc-campaign__dot" data-pc-campaign-slide="' . $i . '" aria-controls="' . esc_attr( $rail_id ) . '" aria-label="' . esc_attr( sprintf( __( 'Go to offer %d', 'peptide-core' ), $i + 1 ) ) . '" aria-current="' . ( $i ? 'false' : 'true' ) . '"></button>'; }
		return '<div class="pc-campaign__rail-controls"><button type="button" class="pc-campaign__arrow" data-pc-campaign-prev aria-controls="' . esc_attr( $rail_id ) . '" aria-label="' . esc_attr__( 'Previous offer', 'peptide-core' ) . '">' . $arrow( false ) . '</button><div class="pc-campaign__dots">' . $dots . '</div><button type="button" class="pc-campaign__arrow" data-pc-campaign-next aria-controls="' . esc_attr( $rail_id ) . '" aria-label="' . esc_attr__( 'Next offer', 'peptide-core' ) . '">' . $arrow( true ) . '</button></div>';
	}

	private static function action_button( array $s, string $url, string $label, string $context ): string {
		$effect = in_array( $s['buttonEffect'] ?? '', self::EFFECTS, true ) ? $s['buttonEffect'] : 'none';
		$button = '<span class="pc-campaign__button pc-campaign__button--' . esc_attr( $effect ) . '" data-pc-campaign-effect="' . esc_attr( $effect ) . '" data-effect-duration="' . max( 1000, min( 5000, (int) ( $s['effectDurationMs'] ?? 1000 ) ) ) . '" data-effect-interval="' . max( 500, min( 5000, (int) ( $s['effectIntervalMs'] ?? 1500 ) ) ) . '">' . esc_html( $label ) . '</span>';
		return '<div class="pc-campaign__action">' . ( self::interactive( $context ) ? '<a class="pc-campaign__button-link" href="' . esc_url( $url ) . '">' . $button . '</a>' : '<span class="pc-campaign__button-preview" aria-disabled="true" title="' . esc_attr__( 'Actions are disabled in design previews.', 'peptide-core' ) . '">' . $button . '</span>' ) . '</div>';
	}

	/** A whole activity is one section; only its actual tiers form the offer grid. */
	private static function campaign( array $a, array $s, string $context, string $key ): string {
		$title = '' !== (string) ( $s['title'] ?? '' ) ? (string) $s['title'] : (string) ( $a['title'] ?? '' );
		$description = self::campaign_description( $s, $a );
		$out = '<article class="pc-campaign" data-pc-campaign data-pc-activity="' . esc_attr( $a['type'] ) . '" data-activity-id="' . (int) $a['id'] . '" data-state="' . esc_attr( $a['status'] ?? 'active' ) . '" style="' . esc_attr( self::campaign_style( $s ) ) . '">';
		if ( ! empty( $s['image'] ) ) { $image = self::image( $s['image'], 'large' ); if ( $image ) { $out .= '<div class="pc-campaign__image">' . $image . '</div>'; } }
		$out .= '<header class="pc-campaign__head">' . ( '' !== $title ? '<h2 class="pc-campaign__title">' . esc_html( $title ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-campaign__description">' . $description . '</div>' : '' ) . self::campaign_countdown( $a, $s ) . '</header>';
		if ( 'promotion' === $a['type'] && ! empty( $a['tiers'] ) ) {
			$desktop = self::campaign_value( $s['tierLayout'] ?? null, 'desktop', 'grid' ); $mobile = self::campaign_value( $s['tierLayout'] ?? null, 'mobile', 'carousel' );
			$desktop = 'carousel' === $desktop ? 'carousel' : 'grid'; $mobile = 'carousel' === $mobile ? 'carousel' : 'grid';
			$rail_id = 'pc-campaign-tiers-' . sanitize_html_class( $key . '-' . $a['id'] );
			$out .= '<div class="pc-campaign__rail" data-pc-campaign-rail data-desktop-layout="' . $desktop . '" data-mobile-layout="' . $mobile . '"><div class="pc-campaign__tiers" id="' . esc_attr( $rail_id ) . '">';
			foreach ( $a['tiers'] as $tier ) { $out .= self::campaign_tier( $a, $tier, $s ); }
			$out .= '</div>' . self::campaign_controls( count( $a['tiers'] ), $rail_id ) . '</div>';
		} elseif ( 'product_offer' === $a['type'] ) {
			if ( self::interactive( $context ) ) { self::activity_no_cache(); $out .= '<div class="pc-campaign__offer pc-cb-offer">' . $a['offer_html'] . '</div>'; }
			else { $out .= '<div class="pc-campaign__offer"><ul>'; foreach ( $a['rules'] as $rule ) { $out .= '<li>' . esc_html( $rule ) . '</li>'; } $out .= '</ul><p>' . esc_html__( 'Purchase actions are disabled in design previews.', 'peptide-core' ) . '</p></div>'; }
		}
		if ( ! empty( $a['notes'] ) ) { $out .= '<div class="pc-campaign__notes">'; foreach ( $a['notes'] as $note ) { $out .= '<p>' . esc_html( $note ) . '</p>'; } $out .= '</div>'; }
		$button_label = (string) ( $s['buttonLabel'] ?? '' );
		if ( '' !== trim( $button_label ) ) {
			$out .= self::action_button( $s, $a['url'], $button_label, $context );
		}
		return $out . '</article>';
	}

	private static function campaigns( array $s, string $context, string $key ): string {
		$activities = array();
		foreach ( self::activity_ids( 'promotion', $s ) as $id ) { $a = self::activity( 'promotion', (int) $id ); if ( $a ) { $activities[] = $a; } }
		$activities = array_slice( $activities, 0, (int) ( $s['limit'] ?? 8 ) );
		$out = ''; $multiple = count( $activities ) > 1;
		foreach ( $activities as $a ) {
			$settings = $s;
			if ( $multiple ) { $settings['title'] = ''; }
			$out .= self::campaign( $a, $settings, $context, $key );
		}
		return $out;
	}

	/** Match the copy button's foreground to the chosen theme, including alpha hex colors. */
	private static function coupon_style( array $s ): string {
		$color = $s['themeColor'] ?? '#202020';
		if ( ! is_string( $color ) || ! preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color ) ) { $color = '#202020'; }
		$hex = substr( $color, 1 );
		if ( strlen( $hex ) <= 4 ) { $hex = implode( '', array_map( static fn( $part ) => $part . $part, str_split( $hex ) ) ); }
		$alpha = 8 === strlen( $hex ) ? hexdec( substr( $hex, 6, 2 ) ) / 255 : 1;
		$luminance = 0;
		foreach ( array( .2126, .7152, .0722 ) as $index => $weight ) {
			$channel = ( hexdec( substr( $hex, $index * 2, 2 ) ) * $alpha + 255 * ( 1 - $alpha ) ) / 255;
			$luminance += $weight * ( $channel <= .04045 ? $channel / 12.92 : pow( ( $channel + .055 ) / 1.055, 2.4 ) );
		}
		return '--pc-coupon-theme:' . $color . ';--pc-coupon-on-theme:' . ( $luminance > .179 ? '#000000' : '#ffffff' );
	}

	private static function coupon_ticket( array $a, string $key ): string {
		$end = (int) ( $a['end'] ?? 0 );
		if ( $end && $end <= time() ) { return ''; }
		$amount = 'percent' === ( $a['coupon_type'] ?? '' ) ? wc_format_localized_decimal( $a['amount'] ?? 0 ) . '%' : html_entity_decode( wp_strip_all_tags( wc_price( (float) ( $a['amount'] ?? 0 ) ) ), ENT_QUOTES, 'UTF-8' );
		$amount_length = max( 1, function_exists( 'mb_strlen' ) ? mb_strlen( $amount, 'UTF-8' ) : strlen( $amount ) );
		$clock = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3 2"/></svg>';
		$copy = '<svg class="pc-coupon__copy-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M8 8h12v12H8zM4 16V4h12"/></svg><svg class="pc-coupon__copied-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>';
		$feedback_id = 'pc-coupon-feedback-' . sanitize_html_class( $key . '-' . $a['id'] );
		$out = '<article class="pc-coupon" data-pc-coupon data-pc-activity="coupon" data-activity-id="' . (int) $a['id'] . '" data-pc-coupon-expiry="' . $end . '" style="--pc-coupon-value-length:' . $amount_length . '"><div class="pc-coupon__ticket"><div class="pc-coupon__value"><strong>' . esc_html( $amount ) . '</strong><span>' . esc_html__( 'OFF', 'peptide-core' ) . '</span>' . ( 'fixed_product' === ( $a['coupon_type'] ?? '' ) ? '<small>' . esc_html__( 'per eligible item', 'peptide-core' ) . '</small>' : '' ) . '</div><div class="pc-coupon__info"><h3 class="pc-coupon__title">' . esc_html( $a['title'] ) . '</h3>';
		$out .= '<p class="pc-coupon__period">' . $clock . ( $end ? '<time datetime="' . esc_attr( gmdate( 'c', $end ) ) . '">' . esc_html( sprintf( __( 'Expires %s', 'peptide-core' ), $a['expires_text'] ?: wp_date( 'Y-m-d H:i', $end ) ) ) . '</time>' : '<span>' . esc_html__( 'No expiration date', 'peptide-core' ) . '</span>' ) . '</p>';
		if ( (int) ( $a['start'] ?? 0 ) > time() ) { $out .= '<p class="pc-coupon__period">' . $clock . '<time datetime="' . esc_attr( gmdate( 'c', $a['start'] ) ) . '">' . esc_html( sprintf( __( 'Starts %s', 'peptide-core' ), $a['starts_text'] ?: wp_date( 'Y-m-d H:i', $a['start'] ) ) ) . '</time></p>'; }
		if ( '' !== trim( (string) ( $a['text'] ?? '' ) ) ) { $out .= '<p class="pc-coupon__text">' . nl2br( esc_html( $a['text'] ) ) . '</p>'; }
		$rules = array_values( array_unique( array_slice( $a['rules'], 1 ) ) );
		if ( $rules ) { $out .= '<ul class="pc-coupon__rules">'; foreach ( $rules as $rule ) { $out .= '<li>' . esc_html( $rule ) . '</li>'; } $out .= '</ul>'; }
		$out .= '<button type="button" class="pc-coupon__copy" data-pc-cb-copy="' . esc_attr( $a['code'] ) . '" data-copied="' . esc_attr__( 'Code copied', 'peptide-core' ) . '" data-copy-fallback="' . esc_attr__( 'Copy failed. Select the code and copy it to continue.', 'peptide-core' ) . '" aria-label="' . esc_attr( sprintf( __( 'Copy coupon code %s', 'peptide-core' ), $a['code'] ) ) . '" aria-describedby="' . esc_attr( $feedback_id ) . '"><code>' . esc_html( $a['code'] ) . '</code>' . $copy . '</button></div></div><p class="pc-coupon__feedback" id="' . esc_attr( $feedback_id ) . '" data-pc-copy-feedback role="status" aria-live="polite"></p></article>';
		return $out;
	}

	private static function coupon_tickets( array $s, string $key ): string {
		$activities = array();
		foreach ( self::activity_ids( 'coupon', $s ) as $id ) { $activity = self::activity( 'coupon', (int) $id ); if ( $activity ) { $activities[] = $activity; } }
		if ( 'manual' !== ( $s['selection'] ?? 'auto' ) ) { usort( $activities, static fn( $a, $b ) => array( $a['order'], $a['end'] ?: PHP_INT_MAX, $a['code'] ) <=> array( $b['order'], $b['end'] ?: PHP_INT_MAX, $b['code'] ) ); }
		$out = ''; foreach ( array_slice( $activities, 0, (int) ( $s['limit'] ?? 8 ) ) as $activity ) { $out .= self::coupon_ticket( $activity, $key ); }
		if ( '' === $out ) { return ''; }
		$description = self::campaign_description( $s, array() );
		$head = ( '' !== (string) ( $s['title'] ?? '' ) ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-coupons__description">' . $description . '</div>' : '' );
		return '<div class="pc-coupons" style="' . esc_attr( self::coupon_style( $s ) ) . '">' . ( $head ? '<header class="pc-coupons__head">' . $head . '</header>' : '' ) . '<div class="pc-coupons__list">' . $out . '</div></div>';
	}

	private static function announcements( array $s, array $items ): string {
		$out = '';
		if ( 'bound' === ( $s['mode'] ?? 'manual' ) ) {
			$seen = array();
			foreach ( self::rows( (array) ( $s['bindings'] ?? array() ) ) as $item ) {
				$row = $item['settings']; $type = (string) ( $row['activityType'] ?? '' ); $keys = array( 'promotion' => 'promotion', 'coupon' => 'coupon', 'shipping_discount' => 'shippingDiscount', 'product_offer' => 'offer' );
				if ( ! isset( $keys[ $type ] ) ) { continue; } $id = absint( $row[ $keys[ $type ] ] ?? 0 ); $a = self::activity( $type, $id ); $key = $type . ':' . $id;
				if ( ! $a || isset( $seen[ $key ] ) ) { continue; } $seen[ $key ] = true;
				$out .= '<div class="pc-cb-announcement" data-module-item="' . esc_attr( $item['id'] ) . '" data-pc-activity="' . esc_attr( $type ) . '" data-activity-id="' . $id . '"><strong>' . esc_html( $a['title'] ) . '</strong><span> ' . esc_html( implode( ' · ', $a['rules'] ) ) . '</span>' . self::activity_period( $a ) . '<a href="' . esc_url( $a['url'] ) . '">' . esc_html__( 'View offer', 'peptide-core' ) . '</a></div>';
			}
		} else {
			foreach ( $items as $item ) { $row = $item['settings']; if ( '' === $row['title'] && empty( $row['link']['label'] ) ) { continue; } $out .= '<div class="pc-cb-announcement" data-module-item="' . esc_attr( $item['id'] ) . '">' . esc_html( $row['title'] ) . ( ! empty( $row['link']['href'] ) && ! empty( $row['link']['label'] ) ? ' <a href="' . esc_url( $row['link']['href'] ) . '"' . ( '_blank' === $row['link']['target'] ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( $row['link']['label'] ) . '</a>' : '' ) . '</div>'; }
		}
		return $out ? '<div data-pc-cb-rail data-autoplay="' . ( $s['autoplay'] ? '1' : '0' ) . '" data-interval="' . (int) $s['intervalMs'] . '"><div class="pc-cb-rail pc-cb-announcements">' . $out . '</div>' . self::controls( ! empty( $s['autoplay'] ) ) . '</div>' : '';
	}

	private static function reviews( string $type, array $s, string $context = 'public', string $key = '', array $items = array() ): string { return Review_Blocks::render( $type, $s, $context, $key, $items ); }

	public static function render( $html, array $module, string $context, string $device ) {
		if ( null !== $html || ! in_array( $module['type'], self::TYPES, true ) ) { return $html; }
		self::assets(); $type = $module['type']; $s = $module['settings']; $out = ''; $items = self::rows( $module['items'] );
		if ( 'product_tabs' === $type ) {
			$out = self::product_tabs( $module, $context );
		} elseif ( in_array( $type, array( 'category_tabs', 'topic_tabs' ), true ) ) {
			$out = self::tabs( $module );
		} elseif ( in_array( $type, array( 'category_list', 'featured_categories', 'category_navigation' ), true ) ) {
			$out = self::category_cards( $items, $s, 'category_navigation' === $type );
		} elseif ( in_array( $type, array( 'product_carousel', 'product_scroll', 'product_waterfall', 'product_ranking', 'product_comparison', 'recent_products', 'featured_product' ), true ) ) {
			if ( 'recent_products' === $type ) {
				$ids = self::recent_ids(); $products = $ids ? wc_get_products( array( 'include' => $ids, 'status' => 'publish', 'visibility' => 'visible', 'orderby' => 'include', 'limit' => (int) $s['limit'] ) ) : array();
			} elseif ( 'featured_product' === $type ) { $p = wc_get_product( (int) $s['product'] ); $products = $p ? array( $p ) : array();
			} else { $products = self::products( $s, 'product_ranking' === $type ); }
			if ( $products && 'product_comparison' === $type ) { $out = self::comparison( $products );
			} elseif ( $products ) {
				$rail = in_array( $type, array( 'product_carousel', 'product_scroll' ), true );
				$out = self::grid( $s, $rail ? 'pc-cb-rail' : ( 'product_waterfall' === $type ? 'pc-cb-masonry' : '' ) );
				foreach ( $products as $index => $product ) { $card = self::card( $product ); if ( '' !== $card ) { $out .= '<div class="pc-cb-product">' . ( 'product_ranking' === $type ? '<span class="pc-cb-rank">' . ( $index + 1 ) . '</span>' : '' ) . $card . '</div>'; } }
				$out .= '</div>';
				if ( $rail ) { $out = '<div data-pc-cb-rail data-autoplay="' . ( ! empty( $s['autoplay'] ) ? '1' : '0' ) . '" data-interval="' . (int) $s['intervalMs'] . '">' . $out . self::controls( ! empty( $s['autoplay'] ) ) . '</div>'; }
			}
		} elseif ( 'video_products' === $type ) {
			foreach ( array_slice( $items, 0, (int) $s['limit'] ) as $item ) { $p = wc_get_product( (int) $item['settings']['product'] ); $id = (int) ( $item['settings']['video']['attachmentId'] ?? 0 ); if ( ! $p || ! str_starts_with( (string) get_post_mime_type( $id ), 'video/' ) ) { continue; } $card = self::card( $p ); if ( $card ) { $out .= '<div class="pc-cb-video-product" data-module-item="' . esc_attr( $item['id'] ) . '"><video controls preload="metadata" src="' . esc_url( wp_get_attachment_url( $id ) ) . '"></video>' . $card . '</div>'; } }
			$out = $out ? self::grid( $s ) . $out . '</div>' : '';
		} elseif ( in_array( $type, array( 'text_reviews', 'image_reviews', 'video_reviews', 'featured_reviews' ), true ) ) {
			$out = self::reviews( $type, $s, $context, $module['id'] . '-' . $device, $module['items'] );
		} elseif ( 'subscribe' === $type ) {
			$out = 'public' === $context && class_exists( Subscriptions::class ) ? Subscriptions::render_form( $s ) : '<p>' . esc_html__( 'Subscription preview. No email is sent from the design editor.', 'peptide-core' ) . '</p>';
		} elseif ( 'promotion_card' === $type ) {
			$activity_type = 'product_offer' === ( $s['activityType'] ?? 'promotion' ) ? 'product_offer' : 'promotion';
			$activity = self::activity( $activity_type, absint( $s[ 'product_offer' === $activity_type ? 'offer' : 'promotion' ] ?? 0 ) );
			$out = $activity ? self::campaign( $activity, $s, $context, $module['id'] . '-' . $device ) : '';
		} elseif ( 'product_offer' === $type ) {
			$activity = self::activity( 'product_offer', absint( $s['offer'] ?? 0 ) ); $out = $activity ? self::activity_card( $activity, $context ) : '';
		} elseif ( 'promotion_list' === $type ) {
			$out = self::campaigns( $s, $context, $module['id'] . '-' . $device );
		} elseif ( 'coupon_list' === $type ) {
			$out = self::coupon_tickets( $s, $module['id'] . '-' . $device );
		} elseif ( 'shipping_discount_list' === $type ) {
			$out = self::activity_list( 'shipping_discount', $s, $context );
		} elseif ( 'announcement' === $type ) {
			$out = self::announcements( $s, $items );
		} elseif ( in_array( $type, array( 'blog_posts', 'category_blog', 'blog_categories', 'campaign_pages' ), true ) ) {
			$out = Content_Blocks::render( $type, $s, $items );
		} elseif ( 'social_links' === $type || 'search_terms' === $type || 'partners' === $type || 'youtube_list' === $type ) {
			foreach ( $items as $item ) {
				$row = $item['settings'];
				if ( 'youtube_list' === $type ) { $id = Fallback::youtube_id( $row['url'] ); if ( $id ) { $out .= '<figure class="pc-cb-card"><iframe loading="lazy" src="https://www.youtube-nocookie.com/embed/' . esc_attr( $id ) . '" title="' . esc_attr( $row['title'] ?: __( 'YouTube video', 'peptide-core' ) ) . '" allow="fullscreen; picture-in-picture" allowfullscreen></iframe><figcaption>' . esc_html( $row['title'] ) . '</figcaption></figure>'; } }
				elseif ( 'partners' === $type ) { $image = self::image( $row['image'] ?? null ); $out .= '<div class="pc-cb-card">' . ( $row['url'] ? '<a href="' . esc_url( $row['url'] ) . '">' : '' ) . $image . '<p>' . esc_html( $row['title'] ) . '</p>' . ( $row['url'] ? '</a>' : '' ) . '</div>'; }
				else { $url = 'search_terms' === $type ? add_query_arg( array( 's' => $row['title'], 'post_type' => 'product' ), home_url( '/' ) ) : $row['url']; if ( $url && $row['title'] ) { $out .= '<a class="pc-cb-link" href="' . esc_url( $url ) . '">' . esc_html( $row['title'] ) . '</a>'; } }
			}
			$out = $out ? self::grid( $s ) . $out . '</div>' : '';
		} elseif ( 'product_search' === $type ) {
			$out = '<form class="pc-cb-search" role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '"><input type="hidden" name="post_type" value="product"><label>' . esc_html__( 'Search products', 'peptide-core' ) . '<input type="search" name="s" required></label>';
			if ( $s['priceRange'] ) { $out .= '<label>' . esc_html__( 'Minimum price', 'peptide-core' ) . '<input type="number" min="0" step="0.01" name="min_price"></label><label>' . esc_html__( 'Maximum price', 'peptide-core' ) . '<input type="number" min="0" step="0.01" name="max_price"></label>'; }
			$out .= '<button type="submit">' . esc_html__( 'Search', 'peptide-core' ) . '</button></form>';
		}
		$campaign = in_array( $type, array( 'promotion_card', 'promotion_list' ), true );
		if ( '' === $out ) {
			if ( 'coupon_list' === $type && ! self::interactive( $context ) ) { return '<div class="pc-cb vc-m__inner"><div class="pc-campaign__empty"><strong>选择优惠券，预览真实优惠</strong><p>请添加优惠券并选择可公开的记录；未选择、已停用、已过期或当前顾客不适用的优惠券不会展示。</p>' . ( current_user_can( 'edit_shop_coupons' ) ? '<a href="' . esc_url( admin_url( 'edit.php?post_type=shop_coupon' ) ) . '" target="_blank" rel="noopener noreferrer">管理优惠券</a>' : '' ) . '</div></div>'; }
			if ( $campaign && ! self::interactive( $context ) ) { return '<div class="pc-cb vc-m__inner"><div class="pc-campaign__empty"><strong>选择活动，预览真实优惠</strong><p>请在设置中选择一个公开且适用的活动。未选择、已停用、已结束或当前顾客不适用的活动不会对买家展示。</p>' . ( current_user_can( 'edit_shop_coupons' ) ? '<a href="' . esc_url( admin_url( 'admin.php?page=pc-promotions' ) ) . '" target="_blank" rel="noopener noreferrer">管理满减活动</a>' : '' ) . '</div></div>'; }
			return 'editor' === $context ? '<div class="pc-cb"><p>' . esc_html__( 'No eligible published content. Configure this block or add real store records.', 'peptide-core' ) . '</p></div>' : '';
		}
		$classes = 'pc-cb vc-m__inner' . ( $campaign ? ' pc-cb--campaigns' : '' ) . ( 'product_tabs' === $type ? ' pc-cb--product-tabs' : '' ) . ( ( $campaign || 'product_tabs' === $type ) && ! empty( $s['fullWidth'] ) ? ' vc-m__inner--full' : '' );
		$heading = $campaign || in_array( $type, array( 'coupon_list', 'product_tabs', 'text_reviews', 'image_reviews', 'video_reviews', 'featured_reviews' ), true ) ? '' : self::heading_html( $s );
		if ( 'promotion_list' === $type && '' !== (string) ( $s['title'] ?? '' ) && substr_count( $out, 'data-pc-campaign ' ) > 1 ) { $heading = '<h2 class="pc-campaign__collection-title">' . esc_html( $s['title'] ) . '</h2>'; }
		return '<div class="' . esc_attr( $classes ) . '" data-pc-commerce="' . esc_attr( $type ) . '">' . $heading . $out . '</div>';
	}

	public static function review_image_ids( int $comment_id ): array {
		return array_values( array_filter( array_map( 'absint', (array) get_comment_meta( $comment_id, self::REVIEW_IMAGES, true ) ), 'wp_attachment_is_image' ) );
	}
	public static function review_video_id( int $comment_id ): int {
		$id = (int) get_comment_meta( $comment_id, self::REVIEW_VIDEO, true );
		return str_starts_with( (string) get_post_mime_type( $id ), 'video/' ) ? $id : 0;
	}
	public static function review_box(): void { add_meta_box( 'pc-review-media', __( 'Customer review media', 'peptide-core' ), array( self::class, 'render_review_box' ), 'comment', 'normal' ); }
	public static function render_review_box( \WP_Comment $comment ): void {
		if ( 'product' !== get_post_type( $comment->comment_post_ID ) ) { return; }
		wp_nonce_field( 'pc_review_media_' . $comment->comment_ID, 'pc_review_media_nonce' );
		echo '<p>' . esc_html__( 'Attach only media belonging to this real customer review. Unapproved reviews are never shown.', 'peptide-core' ) . '</p><p><input type="hidden" name="pc_review_images" value="' . esc_attr( implode( ',', self::review_image_ids( $comment->comment_ID ) ) ) . '"><button type="button" class="button" data-pc-review-images>' . esc_html__( 'Choose review photos', 'peptide-core' ) . '</button> <span data-pc-review-image-count>' . count( self::review_image_ids( $comment->comment_ID ) ) . '</span></p><p><input type="hidden" name="pc_review_video" value="' . (int) self::review_video_id( $comment->comment_ID ) . '"><button type="button" class="button" data-pc-review-video>' . esc_html__( 'Choose review video', 'peptide-core' ) . '</button><button type="button" class="button" data-pc-review-clear>' . esc_html__( 'Remove review media', 'peptide-core' ) . '</button></p><label><input type="checkbox" name="pc_review_featured" value="1" ' . checked( '1', get_comment_meta( $comment->comment_ID, self::REVIEW_FEATURED, true ), false ) . '> ' . esc_html__( 'Feature this review', 'peptide-core' ) . '</label>';
	}
	public static function review_assets( string $hook ): void {
		if ( 'comment.php' === $hook && current_user_can( 'moderate_comments' ) ) { wp_enqueue_media(); wp_enqueue_script( 'pc-review-media', plugins_url( 'assets/commerce-review-media.js', PEPTIDE_CORE_FILE ), array( 'jquery', 'media-editor' ), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/commerce-review-media.js' ), true ); }
	}
	public static function save_review_media( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment || 'product' !== get_post_type( $comment->comment_post_ID ) || ! current_user_can( 'edit_comment', $comment_id ) || ! isset( $_POST['pc_review_media_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pc_review_media_nonce'] ) ), 'pc_review_media_' . $comment_id ) ) { return; }
		$raw = isset( $_POST['pc_review_images'] ) ? sanitize_text_field( wp_unslash( $_POST['pc_review_images'] ) ) : '';
		$images = array_slice( array_values( array_filter( array_unique( array_map( 'absint', explode( ',', $raw ) ) ), 'wp_attachment_is_image' ) ), 0, 12 );
		$video = isset( $_POST['pc_review_video'] ) ? absint( $_POST['pc_review_video'] ) : 0;
		update_comment_meta( $comment_id, self::REVIEW_IMAGES, $images );
		update_comment_meta( $comment_id, self::REVIEW_VIDEO, str_starts_with( (string) get_post_mime_type( $video ), 'video/' ) ? $video : 0 );
		update_comment_meta( $comment_id, self::REVIEW_FEATURED, empty( $_POST['pc_review_featured'] ) ? '0' : '1' );
	}
}
