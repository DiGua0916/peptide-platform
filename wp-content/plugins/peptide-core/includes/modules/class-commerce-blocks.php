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
	private const HISTORY_COOKIE = 'pc_recent_history_v1';
	public const REVIEW_IMAGES = '_pc_review_images';
	public const REVIEW_VIDEO = '_pc_review_video';
	public const REVIEW_FEATURED = '_pc_review_featured';
	private const EFFECTS = array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble', 'pulse', 'shine' );
	private const TYPES = array( 'featured_product', 'video_products', 'product_carousel', 'product_scroll', 'product_tabs', 'category_tabs', 'topic_tabs', 'product_ranking', 'recent_products', 'product_waterfall', 'category_list', 'featured_categories', 'category_navigation', 'product_comparison', 'promotion_card', 'product_offer', 'subscribe', 'announcement', 'coupon_list', 'promotion_list', 'shipping_discount_list', 'text_reviews', 'image_reviews', 'video_reviews', 'featured_reviews', 'blog_posts', 'blog_categories', 'category_blog', 'campaign_pages', 'social_links', 'youtube_list', 'tiktok_list', 'partners', 'product_search', 'search_terms', 'site_search_hero' );

	public static function register_hooks(): void {
		add_action( 'wp_ajax_pc_product_waterfall_more', array( self::class, 'waterfall_more' ) );
		add_action( 'wp_ajax_nopriv_pc_product_waterfall_more', array( self::class, 'waterfall_more' ) );
		// Record only when Core is actually about to include the selected storefront template.
		if ( version_compare( $GLOBALS['wp_version'] ?? '0', '6.9', '>=' ) ) {
			add_action( 'wp_before_include_template', array( self::class, 'record_recent_history' ), 10, 0 );
		} else {
			add_filter( 'template_include', array( self::class, 'record_recent_history_template' ), PHP_INT_MAX );
		}
		add_action( 'wp_ajax_pc_recent_history', array( self::class, 'recent_history_more' ) );
		add_action( 'wp_ajax_nopriv_pc_recent_history', array( self::class, 'recent_history_more' ) );
		Review_Blocks::register_hooks();
		Social_Feeds::register_hooks();
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
		$defs['pricing_cards'] = self::pricing_cards_definition();
		// P14 has a distinct identity; absent AI uses only the merchant's real fallback collection.
		$defs['ai_product_waterfall'] = array(
			'title' => 'AI商品瀑布流', 'category' => 'product', 'icon' => 'products',
			'settings' => array(
				self::field( 'text', 'title', '标题', 'CollectionWaterfall', array( 'panel' => 'content' ) ),
				self::field( 'textarea', 'description', '原简述', '', array( 'uiHidden' => true ) ),
				self::field( 'html', 'descriptionHtml', '简述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/ai-product-waterfall', 'panel' => 'content' ) ),
				self::field( 'category', 'category', '手动后备专辑', null, array( 'ui' => 'collection', 'panel' => 'source', 'help' => 'AI未接入，当前使用所选专辑作为后备展示。商品名称、价格和链接来自真实商品。' ) ),
				self::field( 'number', 'cartCount', '加购来源数量', 10, array( 'min' => 0, 'max' => 20, 'ui' => 'range', 'panel' => 'source', 'help' => 'AI未接入时，三项数量合计决定每批后备商品数；不代表已有加购推荐。' ) ),
				self::field( 'number', 'browseCount', '浏览来源数量', 5, array( 'min' => 0, 'max' => 10, 'ui' => 'range', 'panel' => 'source' ) ),
				self::field( 'number', 'purchaseCount', '购买来源数量', 5, array( 'min' => 0, 'max' => 10, 'ui' => 'range', 'panel' => 'source' ) ),
				self::field( 'number', 'loadBatches', '加载总批数', 3, array( 'min' => 1, 'max' => 5, 'ui' => 'range', 'panel' => 'layout', 'help' => '包含首批；达到次数后停止追加。无脚本或加载失败时，可通过专辑链接继续浏览。' ) ),
				self::field( 'number', 'desktopColumns', '电脑每排数量', 4, array( 'min' => 2, 'max' => 5, 'ui' => 'range', 'panel' => 'layout' ) ),
				self::field( 'number', 'mobileColumns', '手机每排数量', 2, array( 'min' => 2, 'max' => 3, 'ui' => 'range', 'panel' => 'layout' ) ),
				self::field( 'toggle', 'fullWidth', '宽度铺满', false, array( 'panel' => 'layout' ) ),
			),
			'panels' => array(
				array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
				array( 'id' => 'source', 'label' => '推荐数量与后备专辑', 'initialOpen' => true, 'fields' => array( 'category', 'cartCount', 'browseCount', 'purchaseCount' ) ),
				array( 'id' => 'layout', 'label' => '瀑布排列与加载', 'initialOpen' => true, 'fields' => array( 'desktopColumns', 'mobileColumns', 'loadBatches', 'fullWidth' ) ),
			),
		);

		// P15 is a separate identity; saved lifetime sales rankings remain completely unchanged.
		$defs['behavior_product_ranking'] = array(
			'title' => '商品榜单', 'category' => 'product', 'icon' => 'chart-bar',
			'settings' => array(
				self::field( 'toggle', 'fullWidth', '宽度铺满', false, array( 'panel' => 'layout' ) ),
				self::field( 'number', 'rankingDesktopColumns', '电脑每排数量', 4, array( 'min' => 3, 'max' => 8, 'ui' => 'range', 'panel' => 'layout' ) ),
				self::field( 'number', 'rankingMobileColumns', '手机每排数量', 2, array( 'min' => 1, 'max' => 2, 'ui' => 'range', 'panel' => 'layout' ) ),
				self::field( 'text', 'viewsTitle', '浏览榜标题', 'Most Viewed', array( 'panel' => 'views' ) ),
				self::field( 'number', 'viewsDays', '最大窗口期（天）', 7, array( 'min' => 1, 'max' => 40, 'ui' => 'range', 'panel' => 'views', 'help' => '按店铺时区含今天的自然日计算。使用既有浏览事件样本，不代表独立访客，也不保证完整历史；不会开启追踪或延长保存期限。' ) ),
				self::field( 'number', 'viewsCount', '浏览榜商品数量', 8, array( 'min' => 1, 'max' => 100, 'panel' => 'views' ) ),
				self::field( 'text', 'salesTitle', '销量榜标题', 'Best Sellers', array( 'panel' => 'sales' ) ),
				self::field( 'number', 'salesDays', '最大窗口期（天）', 7, array( 'min' => 1, 'max' => 40, 'ui' => 'range', 'panel' => 'sales', 'help' => '窗口内创建且当前已付款订单的净件数。排除测试订单，扣除当前有效退款（包括跨窗口退款）；按店铺时区含今天的自然日和订单创建时间计算，不是支付时间。小数数量沿既有数据页按整件截断。' ) ),
				self::field( 'number', 'salesCount', '销量榜商品数量', 8, array( 'min' => 1, 'max' => 100, 'panel' => 'sales' ) ),
				self::field( 'text', 'cartTitle', '加购榜标题', 'Most Added to Cart', array( 'panel' => 'cart' ) ),
				self::field( 'number', 'cartDays', '最大窗口期（天）', 7, array( 'min' => 1, 'max' => 40, 'ui' => 'range', 'panel' => 'cart', 'help' => '按店铺时区含今天的自然日计算。使用既有服务端成功加购事件条数，不计客户端尝试，不按加购件数加权。属于已采集样本，不保证完整历史。' ) ),
				self::field( 'number', 'cartCount', '加购榜商品数量', 8, array( 'min' => 1, 'max' => 100, 'panel' => 'cart' ) ),
			),
			'panels' => array(
				array( 'id' => 'layout', 'label' => '榜单布局', 'initialOpen' => true, 'fields' => array( 'fullWidth', 'rankingDesktopColumns', 'rankingMobileColumns' ) ),
				array( 'id' => 'views', 'label' => '浏览最多', 'initialOpen' => true, 'fields' => array( 'viewsTitle', 'viewsDays', 'viewsCount' ) ),
				array( 'id' => 'sales', 'label' => '销量最多', 'fields' => array( 'salesTitle', 'salesDays', 'salesCount' ) ),
				array( 'id' => 'cart', 'label' => '成功加购最多', 'fields' => array( 'cartTitle', 'cartDays', 'cartCount' ) ),
			),
		);

		// Extend the existing Registry block only. Legacy rendering still falls through to its theme.
		if ( isset( $defs['product_collection'] ) ) {
			$defs['product_collection']['settings'] = array_merge( $defs['product_collection']['settings'], array(
				self::field( 'select', 'collectionMode', '专辑商品外观', 'legacy', array( 'options' => array( 'legacy' => '原有商品目录', 'collection' => '专辑商品列表' ), 'panel' => 'layout' ) ),
				self::field( 'category', 'collection', '关联专辑', null, array( 'ui' => 'collection', 'panel' => 'content', 'show_if' => array( 'collectionMode' => 'collection' ) ) ),
				self::field( 'html', 'descriptionHtml', '简述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/product-collection', 'panel' => 'content', 'show_if' => array( 'collectionMode' => 'collection' ) ) ),
				self::field( 'number', 'collectionTotal', '展示商品总数', 8, array( 'min' => 1, 'max' => 100, 'ui' => 'range', 'panel' => 'layout', 'show_if' => array( 'collectionMode' => 'collection' ) ) ),
				self::field( 'select', 'collectionDisplay', '展示样式', 'grid', array( 'options' => array( 'grid' => '原始平铺', 'carousel' => '轮播' ), 'panel' => 'layout', 'show_if' => array( 'collectionMode' => 'collection' ), 'help' => '轮播使用手动切换与原生横向滚动，不自动播放。' ) ),
				self::field( 'text', 'collectionMore', '查看更多按钮标题', 'View More', array( 'panel' => 'button', 'show_if' => array( 'collectionMode' => 'collection' ), 'help' => '留空不显示；按钮链接到当前关联专辑。' ) ),
			) );
			foreach ( $defs['product_collection']['settings'] as &$field ) {
				if ( 'title' === $field['key'] ) { $field['panel'] = 'content'; }
				if ( in_array( $field['key'], array( 'eyebrow', 'description', 'link', 'source', 'category', 'products', 'sort', 'limit', 'emptyText' ), true ) ) { $field['show_if'] = array_merge( $field['show_if'] ?? array(), array( 'collectionMode' => 'legacy' ) ); }
				if ( 'columns' === $field['key'] ) { $field['panel'] = 'layout'; $field['ui'] = 'range'; $field['uiMin'] = array( 'desktop' => 2, 'mobile' => 1 ); $field['uiMax'] = array( 'desktop' => 5, 'mobile' => 2 ); }
				if ( 'reportPreview' === $field['key'] ) { $field['panel'] = 'layout'; }
			}
			unset( $field );
			$defs['product_collection']['editorDefaults'] = array_merge( $defs['product_collection']['editorDefaults'] ?? array(), array( 'collectionMode' => 'collection', 'title' => 'Collection products' ) );
			$defs['product_collection']['panels'] = array(
				array( 'id' => 'content', 'label' => '关联专辑与文案', 'initialOpen' => true, 'fields' => array( 'title', 'collection', 'descriptionHtml', 'eyebrow', 'description', 'source', 'category', 'products', 'sort', 'emptyText' ) ),
				array( 'id' => 'layout', 'label' => '商品排版', 'initialOpen' => true, 'fields' => array( 'collectionMode', 'collectionDisplay', 'collectionTotal', 'columns', 'reportPreview', 'limit' ) ),
				array( 'id' => 'button', 'label' => '查看更多按钮', 'fields' => array( 'collectionMore', 'link' ) ),
			);
		}

		$product = array_merge( self::heading(), self::source_fields(), self::layout_fields() );
		$names = array(
			'product_carousel' => __( 'Product carousel', 'peptide-core' ), 'product_scroll' => __( 'Scrolling products', 'peptide-core' ),
			'product_waterfall' => __( 'Product masonry', 'peptide-core' ), 'product_ranking' => __( 'Product sales ranking', 'peptide-core' ),
			'product_comparison' => __( 'Product price comparison', 'peptide-core' ),
		);
		foreach ( $names as $type => $name ) {
			$defs[ $type ] = array( 'title' => $name, 'category' => 'product', 'icon' => 'products', 'settings' => $product );
		}

		// Only this new collection presentation opts into paged loading and the C14 carrier.
		$defs['product_waterfall']['settings'] = array_merge( $defs['product_waterfall']['settings'], array(
			self::field( 'select', 'waterfallMode', '商品瀑布外观', 'legacy', array( 'options' => array( 'legacy' => '原有商品网格', 'collection' => '商品专辑瀑布' ), 'panel' => 'layout' ) ),
			self::field( 'html', 'descriptionHtml', '简述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/product-waterfall', 'panel' => 'content', 'show_if' => array( 'waterfallMode' => 'collection' ) ) ),
			self::field( 'toggle', 'fullWidth', '宽度铺满', false, array( 'panel' => 'layout', 'show_if' => array( 'waterfallMode' => 'collection' ) ) ),
			self::field( 'number', 'loadBatches', '加载次数', 3, array( 'min' => 1, 'max' => 20, 'ui' => 'range', 'panel' => 'layout', 'show_if' => array( 'waterfallMode' => 'collection' ), 'help' => '包含首次显示的一批。滚动到底部后逐批加载，达到次数后显示专辑链接。' ) ),
			self::field( 'number', 'batchSize', '每次获取商品数', 10, array( 'min' => 1, 'max' => 50, 'ui' => 'range', 'panel' => 'layout', 'show_if' => array( 'waterfallMode' => 'collection' ) ) ),
			self::field( 'text', 'viewMoreLabel', '查看更多按钮标题', 'View More', array( 'panel' => 'button', 'show_if' => array( 'waterfallMode' => 'collection' ) ) ),
			self::field( 'select', 'buttonEffect', '按钮特效', 'none', array( 'options' => array_intersect_key( self::effect_options(), array_flip( array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble' ) ) ), 'panel' => 'button', 'show_if' => array( 'waterfallMode' => 'collection' ) ) ),
			self::field( 'number', 'effectDurationMs', '按钮动画时间', 1000, array( 'ui' => 'seconds', 'min' => 1000, 'max' => 5000, 'step' => 100, 'panel' => 'button', 'show_if' => array( 'waterfallMode' => 'collection' ) ) ),
			self::field( 'number', 'effectIntervalMs', '按钮动画间隔时间', 1500, array( 'ui' => 'seconds', 'min' => 500, 'max' => 5000, 'step' => 100, 'panel' => 'button', 'show_if' => array( 'waterfallMode' => 'collection' ) ) ),
		) );
		foreach ( $defs['product_waterfall']['settings'] as &$field ) {
			if ( 'title' === $field['key'] ) { $field['panel'] = 'content'; }
			if ( in_array( $field['key'], array( 'description', 'source', 'products', 'sort', 'limit' ), true ) ) { $field['show_if'] = array( 'waterfallMode' => 'legacy' ); }
			if ( 'category' === $field['key'] ) { $field['ui'] = 'collection'; $field['panel'] = 'content'; unset( $field['show_if'] ); }
			if ( 'columns' === $field['key'] ) { $field['ui'] = 'range'; $field['uiMin'] = array( 'desktop' => 2, 'mobile' => 2 ); $field['uiMax'] = array( 'desktop' => 5, 'mobile' => 3 ); $field['panel'] = 'layout'; }
		}
		unset( $field );
		$defs['product_waterfall']['editorDefaults'] = array( 'title' => 'Collection Waterfall', 'waterfallMode' => 'collection', 'source' => 'category' );
		$defs['product_waterfall']['panels'] = array(
			array( 'id' => 'content', 'label' => '关联专辑与文案', 'initialOpen' => true, 'fields' => array( 'title', 'category', 'descriptionHtml', 'description', 'source', 'products', 'sort', 'limit' ) ),
			array( 'id' => 'layout', 'label' => '瀑布排版与加载', 'initialOpen' => true, 'fields' => array( 'waterfallMode', 'fullWidth', 'columns', 'loadBatches', 'batchSize' ) ),
			array( 'id' => 'button', 'label' => '查看更多按钮', 'fields' => array( 'viewMoreLabel', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
		);
		foreach ( array( 'product_carousel', 'product_scroll' ) as $type ) {
			$defs[ $type ]['settings'][] = self::field( 'toggle', 'autoplay', __( 'Autoplay', 'peptide-core' ), false );
			$defs[ $type ]['settings'][] = self::field( 'number', 'intervalMs', __( 'Autoplay interval (ms)', 'peptide-core' ), 5000, array( 'min' => 2000, 'max' => 20000 ) );
		}
		// P07 is an explicit new presentation; saved carousel/autoplay contracts remain intact.
		$defs['product_scroll']['settings'] = array_merge( $defs['product_scroll']['settings'], array(
			self::field( 'select', 'scrollStyle', '商品滚动外观', 'legacy', array( 'options' => array( 'legacy' => '原有商品滑轨', 'marquee' => '专辑商品连续滚动' ), 'panel' => 'layout' ) ),
			self::field( 'html', 'descriptionHtml', '简述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/product-scroll', 'panel' => 'content', 'show_if' => array( 'scrollStyle' => 'marquee' ) ) ),
			self::field( 'number', 'scrollTotal', '展示商品总数', 4, array( 'min' => 1, 'max' => 100, 'ui' => 'range', 'panel' => 'layout', 'show_if' => array( 'scrollStyle' => 'marquee' ) ) ),
			self::field( 'number', 'scrollImageSize', '商品图片大小', array( 'desktop' => 192, 'mobile' => 128 ), array( 'device' => true, 'min' => 0, 'max' => 500, 'uiMin' => array( 'desktop' => 100, 'mobile' => 0 ), 'uiMax' => array( 'desktop' => 500, 'mobile' => 300 ), 'ui' => 'range', 'panel' => 'layout', 'show_if' => array( 'scrollStyle' => 'marquee' ), 'help' => '设置为 0 时隐藏该端图片，商品名称、价格和链接仍显示。' ) ),
			self::field( 'number', 'scrollSeconds', '滚动一轮时间', 30, array( 'min' => 1, 'max' => 120, 'ui' => 'range', 'unit' => 's', 'panel' => 'play', 'show_if' => array( 'scrollStyle' => 'marquee' ), 'help' => '鼠标停留、键盘阅读、手动暂停或减少动态效果时停止滚动。' ) ),
			self::field( 'toggle', 'fullWidth', '宽度铺满', false, array( 'panel' => 'layout', 'show_if' => array( 'scrollStyle' => 'marquee' ) ) ),
			self::field( 'text', 'collectionButton', '专辑按钮标题', '', array( 'panel' => 'button', 'show_if' => array( 'scrollStyle' => 'marquee' ), 'help' => '留空不显示；填写后链接到当前关联专辑。' ) ),
		) );
		foreach ( $defs['product_scroll']['settings'] as &$field ) {
			if ( 'title' === $field['key'] ) { $field['panel'] = 'content'; }
			if ( in_array( $field['key'], array( 'description', 'source', 'products', 'sort', 'limit', 'columns', 'autoplay', 'intervalMs' ), true ) ) { $field['show_if'] = array( 'scrollStyle' => 'legacy' ); }
			if ( 'category' === $field['key'] ) { $field['ui'] = 'collection'; $field['panel'] = 'content'; unset( $field['show_if'] ); }
		}
		unset( $field );
		$defs['product_scroll']['editorDefaults'] = array( 'scrollStyle' => 'marquee', 'source' => 'category' );
		$defs['product_scroll']['panels'] = array(
			array( 'id' => 'content', 'label' => '关联专辑与文案', 'initialOpen' => true, 'fields' => array( 'title', 'category', 'descriptionHtml', 'description', 'source', 'products', 'sort' ) ),
			array( 'id' => 'layout', 'label' => '滚动排版', 'initialOpen' => true, 'fields' => array( 'scrollStyle', 'scrollTotal', 'scrollImageSize', 'fullWidth', 'limit', 'columns' ) ),
			array( 'id' => 'play', 'label' => '播放设置', 'fields' => array( 'scrollSeconds', 'autoplay', 'intervalMs' ) ),
			array( 'id' => 'button', 'label' => '专辑按钮', 'fields' => array( 'collectionButton' ) ),
		);

		// P10 is a separate opt-in collection rail, with its own desktop-only count setting.
		$defs['product_carousel']['settings'] = array_merge( $defs['product_carousel']['settings'], array(
			self::field( 'select', 'carouselStyle', '商品轮播外观', 'legacy', array( 'options' => array( 'legacy' => '原有商品滑轨', 'collection' => '专辑商品轮播' ), 'panel' => 'layout' ) ),
			self::field( 'category', 'collection', '关联专辑', null, array( 'ui' => 'collection', 'panel' => 'content', 'show_if' => array( 'carouselStyle' => 'collection' ) ) ),
			self::field( 'html', 'descriptionHtml', '简述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/product-carousel', 'panel' => 'content', 'show_if' => array( 'carouselStyle' => 'collection' ) ) ),
			self::field( 'toggle', 'fullWidth', '宽度铺满', false, array( 'panel' => 'layout', 'show_if' => array( 'carouselStyle' => 'collection' ) ) ),
			self::field( 'number', 'collectionTotal', '展示商品总数', 8, array( 'min' => 1, 'max' => 100, 'ui' => 'range', 'panel' => 'layout', 'show_if' => array( 'carouselStyle' => 'collection' ) ) ),
			self::field( 'number', 'slideColumns', '电脑每排数量', 3, array( 'min' => 3, 'max' => 5, 'ui' => 'range', 'panel' => 'layout', 'show_if' => array( 'carouselStyle' => 'collection' ), 'help' => '手机显示约两项半，可横向滚动查看。' ) ),
			self::field( 'toggle', 'reportPreview', '显示商品原有报告悬停卡', true, array( 'panel' => 'layout', 'show_if' => array( 'carouselStyle' => 'collection' ) ) ),
			self::field( 'text', 'collectionMore', '查看更多按钮标题', 'View More', array( 'panel' => 'button', 'show_if' => array( 'carouselStyle' => 'collection' ), 'help' => '留空不显示；按钮链接到当前关联专辑。' ) ),
		) );
		foreach ( $defs['product_carousel']['settings'] as &$field ) {
			if ( 'title' === $field['key'] ) { $field['panel'] = 'content'; }
			if ( in_array( $field['key'], array( 'description', 'source', 'category', 'products', 'sort', 'limit', 'columns', 'autoplay', 'intervalMs' ), true ) ) { $field['show_if'] = array_merge( $field['show_if'] ?? array(), array( 'carouselStyle' => 'legacy' ) ); }
		}
		unset( $field );
		$defs['product_carousel']['editorDefaults'] = array( 'title' => 'Collection Slides', 'carouselStyle' => 'collection' );
		$defs['product_carousel']['panels'] = array(
			array( 'id' => 'content', 'label' => '关联专辑与文案', 'initialOpen' => true, 'fields' => array( 'title', 'collection', 'descriptionHtml', 'description', 'source', 'category', 'products', 'sort' ) ),
			array( 'id' => 'layout', 'label' => '轮播排版', 'initialOpen' => true, 'fields' => array( 'carouselStyle', 'fullWidth', 'collectionTotal', 'slideColumns', 'reportPreview', 'columns', 'limit' ) ),
			array( 'id' => 'button', 'label' => '查看更多按钮', 'fields' => array( 'collectionMore' ) ),
			array( 'id' => 'play', 'label' => '原有滑轨播放', 'fields' => array( 'autoplay', 'intervalMs' ) ),
		);

		$defs['featured_product'] = array( 'title' => __( 'Featured product', 'peptide-core' ), 'category' => 'product', 'icon' => 'products', 'settings' => array_merge( self::heading(), array( self::field( 'product', 'product', __( 'Product', 'peptide-core' ) ) ) ) );
		$defs['featured_product']['settings'] = array_merge( $defs['featured_product']['settings'], array(
			self::field( 'select', 'displayMode', '展示方式', 'card', array( 'options' => array( 'card' => '商品卡片', 'detail' => '商品详情与购买' ), 'panel' => 'layout' ) ),
			self::field( 'toggle', 'showSubtitle', '显示副标题', true, array( 'show_if' => array( 'displayMode' => 'detail' ), 'panel' => 'product', 'help' => '副标题在商品编辑页的 Peptide data 面板填写；留空不显示。' ) ),
			self::field( 'toggle', 'showShortDescription', '显示简短描述', true, array( 'show_if' => array( 'displayMode' => 'detail' ), 'panel' => 'product' ) ),
			self::field( 'select', 'imagePosition', '电脑图片位置', 'left', array( 'options' => array( 'left' => '居左', 'right' => '居右' ), 'show_if' => array( 'displayMode' => 'detail' ), 'panel' => 'layout', 'help' => '手机始终先显示商品图片，再显示商品信息。' ) ),
		) );
		$defs['featured_product']['editorDefaults'] = array( 'displayMode' => 'detail' );
		$defs['featured_product']['panels'] = array(
			array( 'id' => 'product', 'label' => '关联商品', 'initialOpen' => true, 'fields' => array( 'product', 'showSubtitle', 'showShortDescription' ) ),
			array( 'id' => 'layout', 'label' => '商品排版', 'initialOpen' => true, 'fields' => array( 'displayMode', 'imagePosition' ) ),
			array( 'id' => 'heading', 'label' => '区块标题与说明', 'fields' => array( 'title', 'description' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);
		$defs['video_products'] = array( 'title' => __( 'Video product list', 'peptide-core' ), 'category' => 'product', 'icon' => 'video-alt3', 'settings' => array_merge( self::heading(), self::layout_fields() ), 'items' => self::items( array( self::field( 'product', 'product', __( 'Product', 'peptide-core' ) ), self::field( 'video', 'video', __( 'Product video', 'peptide-core' ) ) ) ) );
		$defs['video_products']['settings'] = array_merge( $defs['video_products']['settings'], array(
			self::field( 'select', 'videoStyle', '视频商品外观', 'legacy', array( 'options' => array( 'legacy' => '原有商品网格', 'showcase' => '视频商品列表' ), 'panel' => 'layout' ) ),
			self::field( 'html', 'descriptionHtml', '描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/video-products', 'panel' => 'content', 'show_if' => array( 'videoStyle' => 'showcase' ) ) ),
			self::field( 'select', 'videoRatio', '视频比例', '9:16', array( 'options' => array( '9:16' => '9:16', '16:9' => '16:9', '4:3' => '4:3', '1:1' => '1:1' ), 'panel' => 'layout', 'show_if' => array( 'videoStyle' => 'showcase' ) ) ),
			self::field( 'select', 'videoDisplay', '展示样式', 'single', array( 'options' => array( 'single' => '单排', 'grid' => '平铺' ), 'panel' => 'layout', 'show_if' => array( 'videoStyle' => 'showcase' ) ) ),
			self::field( 'toggle', 'autoCarousel', '自动轮播', true, array( 'panel' => 'play', 'show_if' => array( 'videoStyle' => 'showcase', 'videoDisplay' => 'single' ), 'help' => '当前视频实际结束后切换；循环播放开启时留在当前视频。自动播放需静音，浏览器可能要求先手动播放。' ) ),
			self::field( 'toggle', 'autoplay', '自动播放', true, array( 'panel' => 'play', 'show_if' => array( 'videoStyle' => 'showcase', 'videoDisplay' => 'grid' ), 'help' => '仅在默认静音时请求自动播放；无法自动播放时可使用视频控件。' ) ),
			self::field( 'toggle', 'loop', '循环播放', false, array( 'panel' => 'play', 'show_if' => array( 'videoStyle' => 'showcase' ) ) ),
			self::field( 'toggle', 'muted', '默认静音', true, array( 'panel' => 'play', 'show_if' => array( 'videoStyle' => 'showcase' ) ) ),
			self::field( 'link', 'videoMore', '查看更多按钮', null, array( 'panel' => 'button', 'show_if' => array( 'videoStyle' => 'showcase' ) ) ),
			self::field( 'select', 'buttonEffect', '按钮特效', 'none', array( 'options' => array_intersect_key( self::effect_options(), array_flip( array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble' ) ) ), 'panel' => 'button', 'show_if' => array( 'videoStyle' => 'showcase' ) ) ),
			self::field( 'number', 'effectDurationMs', '按钮动画时间', 1000, array( 'ui' => 'seconds', 'min' => 1000, 'max' => 5000, 'step' => 100, 'panel' => 'button', 'show_if' => array( 'videoStyle' => 'showcase' ) ) ),
			self::field( 'number', 'effectIntervalMs', '按钮动画间隔时间', 1500, array( 'ui' => 'seconds', 'min' => 500, 'max' => 5000, 'step' => 100, 'panel' => 'button', 'show_if' => array( 'videoStyle' => 'showcase' ) ) ),
		) );
		foreach ( $defs['video_products']['settings'] as &$field ) {
			if ( in_array( $field['key'], array( 'title', 'description' ), true ) ) { $field['panel'] = 'content'; }
			if ( 'description' === $field['key'] ) { $field['show_if'] = array( 'videoStyle' => 'legacy' ); }
			if ( in_array( $field['key'], array( 'limit', 'columns' ), true ) ) { $field['panel'] = 'layout'; }
		}
		unset( $field );
		$defs['video_products']['editorDefaults'] = array( 'title' => 'Product videos', 'videoStyle' => 'showcase', 'columns' => array( 'desktop' => 3, 'mobile' => 1 ) );
		// This count is used only by the insert factory; normalization of saved empty items stays empty.
		$defs['video_products']['items']['default_count'] = 5;
		$defs['video_products']['items']['label'] = '视频与商品';
		$defs['video_products']['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与描述', 'initialOpen' => true, 'fields' => array( 'title', 'description', 'descriptionHtml' ) ),
			array( 'id' => 'layout', 'label' => '视频排列', 'initialOpen' => true, 'fields' => array( 'videoStyle', 'videoRatio', 'videoDisplay', 'limit', 'columns' ) ),
			array( 'id' => 'play', 'label' => '播放设置', 'initialOpen' => true, 'fields' => array( 'autoCarousel', 'autoplay', 'loop', 'muted' ) ),
			array( 'id' => 'button', 'label' => '查看更多按钮', 'fields' => array( 'videoMore', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);
		$defs['recent_products'] = array( 'title' => __( 'Recently viewed products', 'peptide-core' ), 'category' => 'product', 'icon' => 'visibility', 'settings' => array_merge( self::heading(), self::layout_fields() ), 'singleRender' => true );
		$category_rows = self::items( array( self::field( 'category', 'category', __( 'Category', 'peptide-core' ) ) ) );
		foreach ( array( 'category_list' => __( 'Category list', 'peptide-core' ), 'featured_categories' => __( 'Featured categories', 'peptide-core' ), 'category_navigation' => __( 'Category navigation', 'peptide-core' ) ) as $type => $name ) {
			$defs[ $type ] = array( 'title' => $name, 'category' => 'product', 'icon' => 'category', 'settings' => array_merge( self::heading(), self::layout_fields() ), 'items' => $category_rows );
		}

		// New presentations opt in; existing category-card and 24-item recent contracts remain intact.
		$defs['featured_categories']['settings'] = array_merge( $defs['featured_categories']['settings'], array(
			self::field( 'select', 'collectionStyle', '专辑展示方式', 'legacy', array( 'options' => array( 'legacy' => '专辑卡片', 'recommendation' => '推荐专辑与商品' ), 'panel' => 'layout' ) ),
			self::field( 'category', 'collection', '关联专辑', null, array( 'ui' => 'collection', 'panel' => 'content', 'show_if' => array( 'collectionStyle' => 'recommendation' ) ) ),
			self::field( 'record', 'featuredProduct', '主推商品', null, array( 'resource' => 'product', 'panel' => 'content', 'show_if' => array( 'collectionStyle' => 'recommendation' ), 'help' => '未选择时使用专辑第一件可公开商品；手动选择只替换主推位置，不改变下方专辑商品。' ) ),
			self::field( 'html', 'descriptionHtml', '简述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/featured-categories', 'panel' => 'content', 'show_if' => array( 'collectionStyle' => 'recommendation' ) ) ),
			self::field( 'select', 'featuredPosition', '电脑商品位置', 'right', array( 'options' => array( 'right' => '商品居右', 'left' => '商品居左' ), 'panel' => 'layout', 'show_if' => array( 'collectionStyle' => 'recommendation' ), 'help' => '手机始终先显示专辑，再显示主推商品和商品列表。' ) ),
			self::field( 'number', 'collectionLimit', '商品展示数量', 4, array( 'min' => 1, 'max' => 100, 'ui' => 'range', 'panel' => 'layout', 'show_if' => array( 'collectionStyle' => 'recommendation' ) ) ),
			self::field( 'text', 'shopLabel', '专辑按钮标题', 'Shop now', array( 'panel' => 'button', 'show_if' => array( 'collectionStyle' => 'recommendation' ) ) ),
			self::field( 'select', 'buttonEffect', '按钮特效', 'none', array( 'options' => array_intersect_key( self::effect_options(), array_flip( array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble' ) ) ), 'panel' => 'button', 'show_if' => array( 'collectionStyle' => 'recommendation' ) ) ),
			self::field( 'number', 'effectDurationMs', '按钮动画时间', 1000, array( 'ui' => 'seconds', 'min' => 1000, 'max' => 5000, 'step' => 100, 'panel' => 'button', 'show_if' => array( 'collectionStyle' => 'recommendation' ) ) ),
			self::field( 'number', 'effectIntervalMs', '按钮动画间隔时间', 1500, array( 'ui' => 'seconds', 'min' => 500, 'max' => 5000, 'step' => 100, 'panel' => 'button', 'show_if' => array( 'collectionStyle' => 'recommendation' ) ) ),
		) );
		$defs['featured_categories']['items']['show_if'] = array( 'collectionStyle' => 'legacy' );
		foreach ( $defs['featured_categories']['settings'] as &$field ) {
			if ( 'title' === $field['key'] ) { $field['panel'] = 'content'; }
			if ( in_array( $field['key'], array( 'description', 'limit' ), true ) ) { $field['show_if'] = array( 'collectionStyle' => 'legacy' ); }
			if ( 'columns' === $field['key'] ) { $field['panel'] = 'layout'; $field['ui'] = 'range'; $field['uiMin'] = array( 'desktop' => 3, 'mobile' => 1 ); $field['uiMax'] = array( 'desktop' => 5, 'mobile' => 2 ); }
		}
		unset( $field );
		$defs['featured_categories']['editorDefaults'] = array( 'title' => 'Featured collection', 'collectionStyle' => 'recommendation', 'columns' => array( 'desktop' => 4, 'mobile' => 2 ) );
		$defs['featured_categories']['panels'] = array(
			array( 'id' => 'content', 'label' => '专辑与主推商品', 'initialOpen' => true, 'fields' => array( 'title', 'collection', 'featuredProduct', 'descriptionHtml', 'description' ) ),
			array( 'id' => 'layout', 'label' => '商品排版', 'initialOpen' => true, 'fields' => array( 'collectionStyle', 'featuredPosition', 'collectionLimit', 'columns', 'limit' ) ),
			array( 'id' => 'button', 'label' => '专辑按钮', 'fields' => array( 'shopLabel', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
		);
		$defs['recent_products']['settings'] = array_merge( $defs['recent_products']['settings'], array(
			self::field( 'select', 'recentStyle', '浏览记录展示', 'legacy', array( 'options' => array( 'legacy' => '原有浏览记录', 'history' => '最近浏览商品' ), 'panel' => 'layout' ) ),
			self::field( 'html', 'descriptionHtml', '简述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/recent-products', 'panel' => 'content', 'show_if' => array( 'recentStyle' => 'history' ) ) ),
			self::field( 'toggle', 'fullWidth', '宽度铺满', false, array( 'panel' => 'layout', 'show_if' => array( 'recentStyle' => 'history' ) ) ),
			self::field( 'number', 'recentTotal', '商品展示数量', 8, array( 'min' => 1, 'max' => 100, 'ui' => 'range', 'panel' => 'layout', 'show_if' => array( 'recentStyle' => 'history' ), 'help' => '仅显示本浏览器实际访问过且当前可公开的商品。没有记录时隐藏；关闭 JavaScript 时不显示个人浏览记录。' ) ),
		) );
		foreach ( $defs['recent_products']['settings'] as &$field ) {
			if ( 'title' === $field['key'] ) { $field['panel'] = 'content'; }
			if ( in_array( $field['key'], array( 'description', 'limit' ), true ) ) { $field['show_if'] = array( 'recentStyle' => 'legacy' ); }
			if ( 'columns' === $field['key'] ) { $field['panel'] = 'layout'; $field['ui'] = 'range'; $field['uiMin'] = array( 'desktop' => 3, 'mobile' => 1 ); $field['uiMax'] = array( 'desktop' => 5, 'mobile' => 2 ); }
		}
		unset( $field );
		$defs['recent_products']['editorDefaults'] = array( 'title' => 'Recently Viewed', 'recentStyle' => 'history' );
		$defs['recent_products']['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml', 'description' ) ),
			array( 'id' => 'layout', 'label' => '浏览记录与排版', 'initialOpen' => true, 'fields' => array( 'recentStyle', 'recentTotal', 'columns', 'fullWidth', 'limit' ) ),
		);
		$defs['category_list']['settings'] = array_merge( $defs['category_list']['settings'], array(
			self::field( 'select', 'categoryStyle', '专辑列表外观', 'legacy', array( 'options' => array( 'legacy' => '原有专辑网格', 'collections' => '圆图专辑列表' ), 'panel' => 'layout' ) ),
			self::field( 'html', 'descriptionHtml', '简述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/category-list', 'panel' => 'content', 'show_if' => array( 'categoryStyle' => 'collections' ) ) ),
			self::field( 'number', 'collectionColumns', '每排专辑数量', array( 'desktop' => 6, 'mobile' => 3 ), array( 'device' => true, 'min' => 1, 'max' => 8, 'ui' => 'range', 'uiMin' => array( 'desktop' => 3, 'mobile' => 1 ), 'uiMax' => array( 'desktop' => 8, 'mobile' => 4 ), 'panel' => 'layout', 'show_if' => array( 'categoryStyle' => 'collections' ) ) ),
			self::field( 'color', 'nameColor', '专辑名称颜色', '#000000', array( 'panel' => 'layout', 'show_if' => array( 'categoryStyle' => 'collections' ) ) ),
		) );
		foreach ( $defs['category_list']['settings'] as &$field ) {
			if ( 'title' === $field['key'] ) { $field['panel'] = 'content'; }
			if ( in_array( $field['key'], array( 'description', 'limit', 'columns' ), true ) ) { $field['show_if'] = array( 'categoryStyle' => 'legacy' ); }
		}
		unset( $field );
		$defs['category_list']['items']['fields'][0]['ui'] = 'collection';
		$defs['category_list']['items']['fields'][] = self::field( 'text', 'title', '显示名称（圆图列表）', '', array( 'help' => '圆图列表中留空时使用专辑当前名称。' ) );
		$defs['category_list']['items']['default_count'] = 6;
		$defs['category_list']['items']['label'] = '商品专辑';
		$defs['category_list']['editorDefaults'] = array( 'title' => 'Top Collections', 'categoryStyle' => 'collections' );
		$defs['category_list']['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml', 'description' ) ),
			array( 'id' => 'layout', 'label' => '专辑排版', 'initialOpen' => true, 'fields' => array( 'categoryStyle', 'collectionColumns', 'nameColor', 'columns', 'limit' ) ),
		);
		// P08 owns independent links inside each explicitly chosen collection.
		$defs['category_navigation']['settings'] = array_merge( $defs['category_navigation']['settings'], array(
			self::field( 'select', 'navigationStyle', '专辑导航外观', 'legacy', array( 'options' => array( 'legacy' => '原有专辑链接', 'collections' => '图文专辑导航' ), 'panel' => 'layout' ) ),
			self::field( 'html', 'descriptionHtml', '简述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/category-navigation', 'panel' => 'content', 'show_if' => array( 'navigationStyle' => 'collections' ) ) ),
			self::field( 'text', 'collectionsMore', '查看更多按钮标题', 'View More', array( 'panel' => 'button', 'show_if' => array( 'navigationStyle' => 'collections' ), 'help' => '留空不显示；按钮进入站内真实专辑列表。' ) ),
			self::field( 'select', 'buttonEffect', '按钮特效', 'none', array( 'options' => array_intersect_key( self::effect_options(), array_flip( array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble' ) ) ), 'panel' => 'button', 'show_if' => array( 'navigationStyle' => 'collections' ) ) ),
			self::field( 'number', 'effectDurationMs', '按钮动画时间', 1000, array( 'ui' => 'seconds', 'min' => 1000, 'max' => 5000, 'step' => 100, 'panel' => 'button', 'show_if' => array( 'navigationStyle' => 'collections' ) ) ),
			self::field( 'number', 'effectIntervalMs', '按钮动画间隔时间', 1500, array( 'ui' => 'seconds', 'min' => 500, 'max' => 5000, 'step' => 100, 'panel' => 'button', 'show_if' => array( 'navigationStyle' => 'collections' ) ) ),
		) );
		foreach ( $defs['category_navigation']['settings'] as &$field ) {
			if ( 'title' === $field['key'] ) { $field['panel'] = 'content'; }
			if ( in_array( $field['key'], array( 'description', 'limit', 'columns' ), true ) ) { $field['show_if'] = array( 'navigationStyle' => 'legacy' ); }
		}
		unset( $field );
		$defs['category_navigation']['items']['fields'][0]['ui'] = 'collection';
		$defs['category_navigation']['items']['fields'][] = self::field( 'color', 'navigationBackground', '卡片背景颜色', '', array( 'help' => '留空使用透明背景。' ) );
		$defs['category_navigation']['items']['fields'][] = self::field( 'repeater', 'links', '二级链接', null, array( 'max' => 12, 'previewSelect' => true, 'regenerateIdsOnDuplicate' => true, 'fields' => array( self::field( 'text', 'title', '链接标题', '' ), self::field( 'url', 'href', '链接', '', array( 'ui' => 'site-link' ) ) ) ) );
		$defs['category_navigation']['items']['default_count'] = 4;
		$defs['category_navigation']['items']['label'] = '商品专辑与独立链接';
		$defs['category_navigation']['editorDefaults'] = array( 'title' => 'Collections', 'navigationStyle' => 'collections' );
		$defs['category_navigation']['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml', 'description' ) ),
			array( 'id' => 'layout', 'label' => '专辑导航排版', 'fields' => array( 'navigationStyle', 'limit', 'columns' ) ),
			array( 'id' => 'button', 'label' => '专辑列表按钮', 'fields' => array( 'collectionsMore', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
		);

		$defs['product_tabs'] = array( 'title' => __( 'Product tabs', 'peptide-core' ), 'category' => 'product', 'icon' => 'index-card', 'settings' => array_merge( self::heading(), self::layout_fields() ), 'items' => self::items( array_merge( array( self::field( 'text', 'title', __( 'Tab title', 'peptide-core' ), '' ) ), self::source_fields() ) ) );
		$defs['category_tabs'] = array( 'title' => __( 'Category tabs', 'peptide-core' ), 'category' => 'product', 'icon' => 'index-card', 'settings' => array_merge( self::heading(), self::layout_fields() ), 'items' => self::items( array( self::field( 'text', 'title', __( 'Tab title', 'peptide-core' ), '' ), self::field( 'repeater', 'categories', __( 'Categories', 'peptide-core' ), null, array( 'max' => 12, 'fields' => $category_rows['fields'] ) ) ) ) );
		// P12 owns a new explicit collection list per group; old categories/fallback stay intact.
		$defs['category_tabs']['settings'] = array_merge( $defs['category_tabs']['settings'], array(
			self::field( 'select', 'categoryTabsStyle', '专辑选项卡外观', 'legacy', array( 'options' => array( 'legacy' => '原有专辑选项卡', 'collections' => '专辑封面选项卡' ), 'panel' => 'layout' ) ),
			self::field( 'html', 'descriptionHtml', '简述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/category-tabs', 'panel' => 'content', 'show_if' => array( 'categoryTabsStyle' => 'collections' ) ) ),
			self::field( 'toggle', 'fullWidth', '宽度铺满', false, array( 'panel' => 'layout', 'show_if' => array( 'categoryTabsStyle' => 'collections' ) ) ),
			self::field( 'toggle', 'showCollectionTitle', '显示专辑名称', true, array( 'panel' => 'layout', 'show_if' => array( 'categoryTabsStyle' => 'collections' ) ) ),
			self::field( 'number', 'collectionColumns', '每排专辑数量', array( 'desktop' => 6, 'mobile' => 2 ), array( 'device' => true, 'min' => 1, 'max' => 8, 'ui' => 'range', 'uiMin' => array( 'desktop' => 2, 'mobile' => 1 ), 'uiMax' => array( 'desktop' => 8, 'mobile' => 4 ), 'panel' => 'layout', 'show_if' => array( 'categoryTabsStyle' => 'collections' ) ) ),
			self::field( 'select', 'collectionSwitch', '选项卡切换方式', 'hover', array( 'options' => array( 'hover' => '鼠标移动', 'click' => '鼠标点击' ), 'panel' => 'play', 'show_if' => array( 'categoryTabsStyle' => 'collections' ) ) ),
		) );
		foreach ( $defs['category_tabs']['settings'] as &$field ) {
			if ( 'title' === $field['key'] ) { $field['panel'] = 'content'; }
			if ( in_array( $field['key'], array( 'description', 'limit', 'columns' ), true ) ) { $field['show_if'] = array( 'categoryTabsStyle' => 'legacy' ); }
		}
		unset( $field );
		$defs['category_tabs']['items']['fields'][] = self::field( 'repeater', 'collections', '本组专辑（封面样式）', null, array( 'max' => 32, 'ui' => 'collection-multiple', 'previewSelect' => true, 'regenerateIdsOnDuplicate' => true, 'fields' => array( self::field( 'category', 'category', '商品专辑', null, array( 'ui' => 'collection' ) ) ) ) );
		$defs['category_tabs']['items']['default_count'] = 1;
		$defs['category_tabs']['items']['editorDefaults'] = array( 'title' => 'TAB' );
		$defs['category_tabs']['editorDefaults'] = array( 'categoryTabsStyle' => 'collections' );
		$defs['category_tabs']['items']['parentMode'] = array( 'setting' => 'categoryTabsStyle', 'fallback' => 'legacy', 'fields' => array( 'legacy' => array( 'title', 'categories' ), 'collections' => array( 'title', 'collections' ) ) );
		$defs['category_tabs']['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml', 'description' ) ),
			array( 'id' => 'layout', 'label' => '专辑封面排版', 'initialOpen' => true, 'fields' => array( 'categoryTabsStyle', 'fullWidth', 'showCollectionTitle', 'collectionColumns', 'columns', 'limit' ) ),
			array( 'id' => 'play', 'label' => '切换方式', 'fields' => array( 'collectionSwitch' ) ),
		);

		$defs['topic_tabs'] = array( 'title' => __( 'Campaign page tabs', 'peptide-core' ), 'category' => 'product', 'icon' => 'index-card', 'settings' => self::heading(), 'items' => self::items( array( self::field( 'text', 'title', __( 'Tab title', 'peptide-core' ), '' ), self::field( 'repeater', 'pages', __( 'Campaign pages', 'peptide-core' ), null, array( 'max' => 12, 'fields' => array( self::field( 'record', 'page', __( 'Campaign page', 'peptide-core' ), null, array( 'resource' => 'topic' ) ) ) ) ) ) ) );

		// P13 is a separately opted-in published pc_topic presentation, never placeholder content.
		$defs['topic_tabs']['settings'] = array_merge( $defs['topic_tabs']['settings'], array(
			self::field( 'select', 'topicTabsStyle', '专题选项卡外观', 'legacy', array( 'options' => array( 'legacy' => '原有专题选项卡', 'topics' => '专题封面选项卡' ), 'panel' => 'layout' ) ),
			self::field( 'html', 'descriptionHtml', '简述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/topic-tabs', 'panel' => 'content', 'show_if' => array( 'topicTabsStyle' => 'topics' ) ) ),
			self::field( 'toggle', 'fullWidth', '宽度铺满', false, array( 'panel' => 'layout', 'show_if' => array( 'topicTabsStyle' => 'topics' ) ) ),
			self::field( 'toggle', 'showTopicCover', '显示专题封面', true, array( 'panel' => 'layout', 'show_if' => array( 'topicTabsStyle' => 'topics' ) ) ),
			self::field( 'number', 'topicImagePadding', '图片内边距', 0, array( 'min' => 0, 'max' => 100, 'unit' => 'px', 'ui' => 'range', 'panel' => 'layout', 'show_if' => array( 'topicTabsStyle' => 'topics', 'showTopicCover' => true ), 'help' => '窄卡片中会限制内边距，避免封面超出卡片。' ) ),
			self::field( 'color', 'topicImageBackground', '图片背景颜色', '#6e6d6d66', array( 'ui' => 'alpha-color', 'panel' => 'layout', 'show_if' => array( 'topicTabsStyle' => 'topics', 'showTopicCover' => true ) ) ),
			self::field( 'toggle', 'showTopicDescription', '显示专题描述', true, array( 'panel' => 'layout', 'show_if' => array( 'topicTabsStyle' => 'topics' ) ) ),
			self::field( 'select', 'topicTextAlign', '文字对齐', 'center', array( 'options' => array( 'left' => '居左', 'center' => '居中', 'right' => '居右' ), 'panel' => 'layout', 'show_if' => array( 'topicTabsStyle' => 'topics' ) ) ),
			self::field( 'number', 'topicColumns', '每排专题数量', array( 'desktop' => 3, 'mobile' => 1 ), array( 'device' => true, 'min' => 1, 'max' => 10, 'ui' => 'range', 'uiMin' => array( 'desktop' => 2, 'mobile' => 1 ), 'uiMax' => array( 'desktop' => 10, 'mobile' => 3 ), 'panel' => 'layout', 'show_if' => array( 'topicTabsStyle' => 'topics' ) ) ),
			self::field( 'text', 'topicMoreLabel', '阅读全文文案', 'Read More >', array( 'panel' => 'content', 'show_if' => array( 'topicTabsStyle' => 'topics' ) ) ),
			self::field( 'select', 'topicSwitch', '选项卡切换方式', 'hover', array( 'options' => array( 'hover' => '鼠标移动', 'click' => '鼠标点击' ), 'panel' => 'play', 'show_if' => array( 'topicTabsStyle' => 'topics' ) ) ),
		) );
		foreach ( $defs['topic_tabs']['settings'] as &$field ) {
			if ( 'title' === $field['key'] ) { $field['panel'] = 'content'; }
			if ( 'description' === $field['key'] ) { $field['show_if'] = array( 'topicTabsStyle' => 'legacy' ); }
		}
		unset( $field );
		$defs['topic_tabs']['items']['fields'][] = self::field( 'repeater', 'topics', '本组专题（封面样式）', null, array( 'max' => 32, 'ui' => 'topic-multiple', 'previewSelect' => true, 'regenerateIdsOnDuplicate' => true, 'fields' => array( self::field( 'record', 'topic', '专题', null, array( 'resource' => 'topic' ) ) ), 'help' => '买家只会看到已发布且无密码的专题；失效关联不自动替换。' ) );
		$defs['topic_tabs']['items']['default_count'] = 1;
		$defs['topic_tabs']['items']['editorDefaults'] = array( 'title' => 'TAB' );
		$defs['topic_tabs']['editorDefaults'] = array( 'topicTabsStyle' => 'topics' );
		$defs['topic_tabs']['items']['parentMode'] = array( 'setting' => 'topicTabsStyle', 'fallback' => 'legacy', 'fields' => array( 'legacy' => array( 'title', 'pages' ), 'topics' => array( 'title', 'topics' ) ) );
		$defs['topic_tabs']['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml', 'description', 'topicMoreLabel' ) ),
			array( 'id' => 'layout', 'label' => '专题卡片排版', 'initialOpen' => true, 'fields' => array( 'topicTabsStyle', 'fullWidth', 'showTopicCover', 'topicImagePadding', 'topicImageBackground', 'showTopicDescription', 'topicTextAlign', 'topicColumns' ) ),
			array( 'id' => 'play', 'label' => '切换方式', 'fields' => array( 'topicSwitch' ) ),
		);

		$defs['promotion_card'] = array( 'title' => __( 'Timed promotion card', 'peptide-core' ), 'category' => 'operation', 'icon' => 'clock', 'singleRender' => true, 'settings' => array_merge( self::heading(), array(
			self::field( 'select', 'activityType', __( 'Activity type', 'peptide-core' ), 'promotion', array( 'options' => array( 'promotion' => __( 'Promotion', 'peptide-core' ), 'product_offer' => __( 'Gift or product bundle', 'peptide-core' ) ) ) ),
			self::field( 'record', 'promotion', __( 'Promotion', 'peptide-core' ), null, array( 'resource' => 'promotion', 'show_if' => array( 'activityType' => 'promotion' ) ) ),
			self::field( 'record', 'offer', __( 'Gift or product bundle', 'peptide-core' ), null, array( 'resource' => 'product_offer', 'show_if' => array( 'activityType' => 'product_offer' ) ) ),
			self::field( 'image', 'image', __( 'Image', 'peptide-core' ) ),
		) ) );
		$defs['product_offer'] = array( 'title' => __( 'Gift or product bundle', 'peptide-core' ), 'category' => 'operation', 'icon' => 'products', 'singleRender' => true, 'settings' => array_merge( self::heading(), array( self::field( 'record', 'offer', __( 'Gift or product bundle', 'peptide-core' ), null, array( 'resource' => 'product_offer' ) ) ) ) );
		$defs['subscribe'] = array( 'title' => __( 'Email subscription', 'peptide-core' ), 'category' => 'operation', 'icon' => 'email', 'singleRender' => true, 'settings' => array_merge( self::heading(), array( self::field( 'text', 'buttonLabel', __( 'Button text', 'peptide-core' ), __( 'Subscribe', 'peptide-core' ) ), self::field( 'text', 'consentText', __( 'Consent text', 'peptide-core' ), __( 'I agree to receive store emails. I can unsubscribe at any time.', 'peptide-core' ) ) ) ) );
		// O17 is opt-in. Missing mode and the existing footer call keep the original form and defaults.
		$subscribe_reference = array( 'group' => 'general', 'show_if' => array( 'subscribeMode' => 'reference' ) );
		$defs['subscribe']['settings'] = array_merge( $defs['subscribe']['settings'], array(
			self::field( 'select', 'subscribeMode', '订阅外观', 'legacy', array( 'group' => 'general', 'panel' => 'content', 'options' => array( 'legacy' => '原有订阅表单', 'reference' => '居中订阅' ) ) ),
			self::field( 'html', 'descriptionHtml', '简短描述', null, $subscribe_reference + array( 'panel' => 'content', 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/subscribe' ) ),
			self::field( 'toggle', 'collectOtherContact', '收集其他联系方式', false, $subscribe_reference + array( 'panel' => 'content', 'help' => '需在店铺设置 → 系统设置 → 购物偏好开启其他联系方式。仅收集单行文本，不发送消息；邮箱仍保留。' ) ),
			self::field( 'textarea', 'subscribeThanks', '提交后的补充感谢文案', '', $subscribe_reference + array( 'panel' => 'content', 'help' => '仅在请求成功后追加普通文字；不会替代真实确认提示，也不表示已激活或邮件已送达。' ) ),
			self::field( 'toggle', 'fullWidth', '宽度铺满', false, $subscribe_reference + array( 'panel' => 'layout' ) ),
			self::field( 'image', 'subscribeBackgroundImage', '背景图片', null, $subscribe_reference + array( 'panel' => 'style' ) ),
			self::field( 'number', 'subscribeOverlayOpacity', '蒙层透明度', 0, $subscribe_reference + array( 'panel' => 'style', 'ui' => 'range', 'unit' => '%', 'min' => 0, 'max' => 100, 'step' => 1 ) ),
			self::field( 'select', 'buttonEffect', '按钮特效', 'none', $subscribe_reference + array( 'panel' => 'button', 'options' => array_intersect_key( self::effect_options(), array_fill_keys( array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble' ), true ) ) ) ),
			self::field( 'number', 'effectDurationMs', '按钮动画时间', 1000, $subscribe_reference + array( 'panel' => 'button', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 1000, 'max' => 5000, 'step' => 100 ) ),
			self::field( 'number', 'effectIntervalMs', '按钮动画间隔时间', 1500, $subscribe_reference + array( 'panel' => 'button', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 500, 'max' => 5000, 'step' => 100 ) ),
		) );
		foreach ( array( 'subscribeOverlayColor' => array( '蒙层颜色', '#ffffff' ), 'subscribeTitleColor' => array( '标题颜色', '#1d1f21' ), 'subscribeDescriptionColor' => array( '简述颜色', '#666666' ), 'subscribeButtonBackground' => array( '按钮背景颜色', '#1d1f21' ), 'subscribeButtonText' => array( '按钮文字颜色', '#ffffff' ) ) as $key => $color ) {
			$defs['subscribe']['settings'][] = self::field( 'color', $key, $color[0], $color[1], $subscribe_reference + array( 'panel' => 'style' ) );
		}
		foreach ( array( 'Top' => '上', 'Right' => '右', 'Bottom' => '下', 'Left' => '左' ) as $edge => $label ) {
			$default = in_array( $edge, array( 'Right', 'Left' ), true ) ? 15 : 0;
			$defs['subscribe']['settings'][] = self::field( 'number', 'subscribePadding' . $edge . 'Px', '内容' . $label . '间距', array( 'desktop' => $default, 'mobile' => $default ), $subscribe_reference + array( 'panel' => 'spacing', 'device' => true, 'unit' => 'px', 'min' => 0, 'max' => 200, 'step' => 1 ) );
		}
		foreach ( $defs['subscribe']['settings'] as &$field ) {
			if ( in_array( $field['key'], array( 'title', 'description', 'consentText' ), true ) ) { $field['panel'] = 'content'; }
			if ( 'buttonLabel' === $field['key'] ) { $field['panel'] = 'button'; }
			if ( 'description' === $field['key'] ) { $field['show_if'] = array( 'subscribeMode' => 'legacy' ); }
		} unset( $field );
		$defs['subscribe']['editorDefaults'] = array( 'subscribeMode' => 'reference', 'title' => 'Subscribe to our newsletter', 'descriptionHtml' => '<p>Join our Friends and Family Mailing List</p>', 'paddingTopPx' => array( 'desktop' => 0, 'mobile' => 0 ), 'paddingBottomPx' => array( 'desktop' => 0, 'mobile' => 0 ) );
		$defs['subscribe']['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与订阅说明', 'initialOpen' => true, 'fields' => array( 'subscribeMode', 'title', 'descriptionHtml', 'description', 'consentText', 'subscribeThanks', 'collectOtherContact' ) ),
			array( 'id' => 'button', 'label' => '按钮与特效', 'initialOpen' => true, 'fields' => array( 'buttonLabel', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
			array( 'id' => 'layout', 'label' => '宽度', 'fields' => array( 'fullWidth' ) ),
			array( 'id' => 'style', 'label' => '背景与颜色', 'fields' => array( 'subscribeBackgroundImage', 'subscribeOverlayOpacity', 'subscribeOverlayColor', 'subscribeTitleColor', 'subscribeDescriptionColor', 'subscribeButtonBackground', 'subscribeButtonText' ) ),
			array( 'id' => 'spacing', 'label' => '内容间距', 'ui' => 'boxmodel', 'fields' => array( 'subscribePaddingTopPx', 'subscribePaddingRightPx', 'subscribePaddingBottomPx', 'subscribePaddingLeftPx' ) ),
		);
		$defs['announcement'] = array( 'title' => __( 'Campaign announcement', 'peptide-core' ), 'category' => 'operation', 'icon' => 'megaphone', 'settings' => array_merge( self::heading(), array(
			self::field( 'select', 'mode', __( 'Announcement source', 'peptide-core' ), 'manual', array( 'options' => array( 'manual' => __( 'Write announcements', 'peptide-core' ), 'bound' => __( 'Use real activities', 'peptide-core' ) ) ) ),
			self::field( 'repeater', 'bindings', __( 'Bound activities', 'peptide-core' ), null, array( 'show_if' => array( 'mode' => 'bound' ), 'max' => 12, 'fields' => self::activity_fields() ) ),
			self::field( 'toggle', 'autoplay', __( 'Autoplay', 'peptide-core' ), false ), self::field( 'number', 'intervalMs', __( 'Autoplay interval (ms)', 'peptide-core' ), 5000, array( 'min' => 2000, 'max' => 20000 ) ),
		) ), 'items' => self::items( array( self::field( 'text', 'title', __( 'Announcement', 'peptide-core' ), '' ), self::field( 'link', 'link', __( 'Link', 'peptide-core' ) ) ) ) );

		// O21: plain, global and opt-in. Old items/mode and mobile overrides remain stored.
		$announcement_reference = array( 'group' => 'general', 'show_if' => array( 'announcementStyle' => 'reference' ) );
		$defs['announcement']['settings'][] = self::field( 'select', 'announcementStyle', '公告外观', 'legacy', array( 'group' => 'general', 'panel' => 'announcement-content', 'options' => array( 'legacy' => '原有公告', 'reference' => '纵向公告栏' ) ) );
		$defs['announcement']['settings'][] = self::field( 'repeater', 'announcementRows', '公告内容', null, $announcement_reference + array( 'panel' => 'announcement-content', 'ui' => 'announcement-rows', 'announcementRows' => true, 'max' => 12, 'fields' => array(
			self::field( 'text', 'pcText', '电脑端公告', '' ), self::field( 'text', 'phoneText', '手机端公告', '' ),
			self::field( 'link', 'link', '按钮', null ),
			self::field( 'select', 'targetMode', '目标来源', 'manual', array( 'options' => array( 'manual' => '手动网址', 'binding' => '关联站内记录' ) ) ),
			self::field( 'select', 'targetKind', '目标类型', '', array( 'options' => array( '' => '', 'route' => '常用页面', 'product' => '商品', 'variation' => '商品规格', 'category' => '商品专辑', 'page' => '页面', 'post' => '博客文章', 'blog_category' => '博客专辑详情', 'topic' => '专题', 'promotion' => '满减活动', 'coupon' => '优惠码' ) ) ),
			self::field( 'text', 'targetId', '目标编号', '' ),
		) ) );
		$defs['announcement']['settings'][] = self::field( 'select', 'announcementIcon', '公告图标', 'megaphone', $announcement_reference + array( 'panel' => 'announcement-style', 'ui' => 'announcement-icons', 'announcementIcons' => true, 'options' => array( 'megaphone' => '喇叭', 'bell' => '铃铛', 'gift' => '礼物', 'tag' => '标签', 'spark' => '星光', 'ticket' => '优惠券', 'clock' => '时钟' ) ) );
		$defs['announcement']['settings'][] = self::field( 'image', 'announcementImage', '自定义图标', null, $announcement_reference + array( 'panel' => 'announcement-style', 'help' => '有效图片优先于内置图标；清除图片后显示所选图标。' ) );
		$defs['announcement']['settings'][] = self::field( 'toggle', 'announcementFullWidth', '宽度铺满', false, $announcement_reference + array( 'panel' => 'announcement-style' ) );
		foreach ( array( 'announcementBackground' => array( '背景颜色', '#000000' ), 'announcementText' => array( '公告文字颜色', '#ffffff' ), 'announcementButtonBackground' => array( '按钮背景颜色', '#ffffff' ), 'announcementButtonText' => array( '按钮文字颜色', '#000000' ) ) as $key => $color ) { $defs['announcement']['settings'][] = self::field( 'color', $key, $color[0], $color[1], $announcement_reference + array( 'panel' => 'announcement-style' ) ); }
		$defs['announcement']['settings'][] = self::field( 'number', 'announcementIntervalMs', '公告滚动间隔', 3000, $announcement_reference + array( 'panel' => 'announcement-motion', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 1000, 'max' => 10000, 'step' => 100 ) );
		$defs['announcement']['settings'][] = self::field( 'select', 'announcementEffect', '按钮特效', 'none', $announcement_reference + array( 'panel' => 'announcement-motion', 'options' => array_intersect_key( self::effect_options(), array_fill_keys( array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble' ), true ) ) ) );
		$defs['announcement']['settings'][] = self::field( 'number', 'announcementEffectDurationMs', '按钮动画时间', 1000, $announcement_reference + array( 'panel' => 'announcement-motion', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 1000, 'max' => 5000, 'step' => 100 ) );
		$defs['announcement']['settings'][] = self::field( 'number', 'announcementEffectIntervalMs', '按钮动画间隔时间', 1500, $announcement_reference + array( 'panel' => 'announcement-motion', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 500, 'max' => 5000, 'step' => 100 ) );
		$defs['announcement']['announcementFactoryRows'] = 2;
		$defs['announcement']['editorDefaults'] = array( 'announcementStyle' => 'reference', 'paddingTopPx' => array( 'desktop' => 0, 'mobile' => 0 ), 'paddingBottomPx' => array( 'desktop' => 0, 'mobile' => 0 ) );
		$defs['announcement']['panels'] = array(
			array( 'id' => 'announcement-content', 'label' => '公告内容', 'initialOpen' => true, 'fields' => array( 'announcementStyle', 'announcementRows' ) ),
			array( 'id' => 'announcement-style', 'label' => '图标与颜色', 'fields' => array( 'announcementIcon', 'announcementImage', 'announcementFullWidth', 'announcementBackground', 'announcementText', 'announcementButtonBackground', 'announcementButtonText' ) ),
			array( 'id' => 'announcement-motion', 'label' => '滚动与按钮', 'fields' => array( 'announcementIntervalMs', 'announcementEffect', 'announcementEffectDurationMs', 'announcementEffectIntervalMs' ) ),
		);

		foreach ( array( 'coupon_list' => __( 'Public coupon codes', 'peptide-core' ), 'promotion_list' => __( 'Spend-and-save offers', 'peptide-core' ), 'shipping_discount_list' => __( 'Shipping discounts', 'peptide-core' ), 'text_reviews' => __( 'Customer reviews', 'peptide-core' ), 'image_reviews' => __( 'Customer photo reviews', 'peptide-core' ), 'video_reviews' => __( 'Customer video reviews', 'peptide-core' ), 'featured_reviews' => __( 'Featured customer reviews', 'peptide-core' ) ) as $type => $name ) {
			$defs[ $type ] = array( 'title' => $name, 'category' => 'operation', 'icon' => 'format-quote', 'settings' => array_merge( self::heading(), self::layout_fields() ) );
		}
		foreach ( array( 'coupon_list' => 'coupon', 'promotion_list' => 'promotion', 'shipping_discount_list' => 'shipping_discount' ) as $type => $resource ) {
			$defs[ $type ]['settings'] = array_merge( $defs[ $type ]['settings'], array(
				self::field( 'select', 'selection', __( 'Activities to show', 'peptide-core' ), 'auto', array( 'options' => array( 'auto' => __( 'All public activities', 'peptide-core' ), 'manual' => __( 'Choose activities', 'peptide-core' ) ) ) ),
				self::field( 'repeater', 'records', __( 'Selected activities', 'peptide-core' ), null, array( 'show_if' => array( 'selection' => 'manual' ), 'max' => 24, 'fields' => array( self::field( 'record', 'record', __( 'Activity', 'peptide-core' ), null, array( 'resource' => $resource ) ) ) ) ),
			) );
		}
		$defs['shipping_discount_list'] = Shipping_Discount_Block::definition( $defs['shipping_discount_list'] );
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
		// O04 is opt-in. Old normalized YouTube lists retain their saved grid and mobile semantics.
		foreach ( $defs['youtube_list']['settings'] as &$field ) {
			$field['panel'] = in_array( $field['key'], array( 'limit', 'columns' ), true ) ? 'layout' : 'content';
			if ( in_array( $field['key'], array( 'description', 'limit', 'columns' ), true ) ) { $field['show_if'] = array( 'youtubeMode' => 'legacy' ); }
		}
		unset( $field );
		$youtube_global = array( 'group' => 'general', 'show_if' => array( 'youtubeMode' => 'reference' ) );
		$defs['youtube_list']['settings'] = array_merge( $defs['youtube_list']['settings'], array(
			self::field( 'select', 'youtubeMode', 'YouTube 列表外观', 'legacy', array( 'group' => 'general', 'panel' => 'layout', 'options' => array( 'legacy' => '原有标题与视频网格', 'reference' => '视频与关联商品' ) ) ),
			self::field( 'html', 'descriptionHtml', '简短描述', null, array_merge( $youtube_global, array( 'panel' => 'content', 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/youtube-list', 'help' => '电脑和手机共用。未编辑沿用原简述；清空后隐藏。' ) ) ),
			self::field( 'select', 'displayMode', '展示方式', 'single', array_merge( $youtube_global, array( 'panel' => 'layout', 'options' => array( 'single' => '单排展示', 'tiled' => '平铺展示' ) ) ) ),
			self::field( 'text', 'productButtonLabel', '商品按钮文字', 'Shop now', array_merge( $youtube_global, array( 'panel' => 'button', 'help' => '每个可公开商品链接到自己的商品详情页；留空只隐藏按钮。' ) ) ),
			self::field( 'text', 'moreLabel', '查看更多文案', 'View More', array_merge( $youtube_global, array( 'panel' => 'button' ) ) ),
			self::field( 'url', 'moreUrl', '查看更多跳转链接', '', array_merge( $youtube_global, array( 'panel' => 'button', 'ui' => 'site-link', 'help' => '与商品和视频链接独立。文案或链接为空时不显示查看更多。' ) ) ),
			self::field( 'select', 'buttonEffect', '按钮特效', 'none', array_merge( $youtube_global, array( 'panel' => 'button', 'options' => array_intersect_key( self::effect_options(), array_fill_keys( array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble' ), true ) ) ) ) ),
			self::field( 'number', 'effectDurationMs', '按钮动画时间', 1000, array_merge( $youtube_global, array( 'panel' => 'button', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 1000, 'max' => 5000, 'step' => 100 ) ) ),
			self::field( 'number', 'effectIntervalMs', '按钮动画间隔', 1500, array_merge( $youtube_global, array( 'panel' => 'button', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 500, 'max' => 5000, 'step' => 100 ) ) ),
		) );
		$defs['youtube_list']['items']['label'] = '视频';
		$defs['youtube_list']['items']['default_count'] = 4;
		$defs['youtube_list']['items']['max'] = 12;
		$defs['youtube_list']['items']['fields'][] = self::field( 'repeater', 'products', '关联商品', null, array( 'ui' => 'youtube-products', 'youtubeProducts' => true, 'max' => 20, 'fields' => array( self::field( 'record', 'product', '商品', null, array( 'resource' => 'product' ) ) ), 'help' => '本地上限：每条视频20个商品、每个区块12条视频。失效关联保留ID；买家只会看到当前可公开商品。' ) );
		$defs['youtube_list']['items']['parentMode'] = array( 'setting' => 'youtubeMode', 'fallback' => 'legacy', 'fields' => array( 'legacy' => array( 'title', 'url' ), 'reference' => array( 'url', 'products' ) ) );
		foreach ( $defs['youtube_list']['items']['fields'] as &$field ) {
			if ( 'url' === $field['key'] ) { $field['ui'] = 'youtube-metadata'; $field['youtubeMetadata'] = true; $field['help'] = '输入完整 HTTPS YouTube 视频链接。视频与关联商品外观可自动读取基础信息，无需密钥；读取失败仍可手动播放。'; }
		}
		unset( $field );
		$defs['youtube_list']['editorDefaults'] = array( 'youtubeMode' => 'reference', 'title' => 'Youtube' );
		$defs['youtube_list']['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml', 'description' ) ),
			array( 'id' => 'layout', 'label' => '展示方式', 'initialOpen' => true, 'fields' => array( 'youtubeMode', 'displayMode', 'limit', 'columns' ) ),
			array( 'id' => 'button', 'label' => '按钮与查看更多', 'fields' => array( 'productButtonLabel', 'moreLabel', 'moreUrl', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);

		// O05 is a new type; content/bindings are shared while CSS adapts the two rails.
		$tiktok_global = array( 'group' => 'general', 'panel' => 'content' );
		$defs['tiktok_list'] = array( 'title' => __( 'TikTok video list', 'peptide-core' ), 'category' => 'operation', 'icon' => 'video-alt3', 'singleRender' => true, 'settings' => array(
			self::field( 'text', 'title', '标题', 'TikTok', $tiktok_global ),
			self::field( 'textarea', 'description', '原有简述', '', array_merge( $tiktok_global, array( 'uiHidden' => true ) ) ),
			self::field( 'html', 'descriptionHtml', '简短描述', null, array_merge( $tiktok_global, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/tiktok-list', 'help' => '电脑和手机共用。未编辑沿用原简述；清空后隐藏。' ) ) ),
			self::field( 'text', 'moreLabel', '查看更多文案', 'View More', array( 'group' => 'general', 'panel' => 'button' ) ),
			self::field( 'url', 'moreUrl', '查看更多跳转链接', '', array( 'group' => 'general', 'panel' => 'button', 'ui' => 'site-link', 'help' => '与视频和商品链接独立。文案或链接为空时隐藏。' ) ),
			self::field( 'select', 'buttonEffect', '按钮特效', 'none', array( 'group' => 'general', 'panel' => 'button', 'options' => array_intersect_key( self::effect_options(), array_fill_keys( array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble' ), true ) ) ) ),
			self::field( 'number', 'effectDurationMs', '按钮动画时间', 1000, array( 'group' => 'general', 'panel' => 'button', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 1000, 'max' => 5000, 'step' => 100 ) ),
			self::field( 'number', 'effectIntervalMs', '按钮动画间隔', 1500, array( 'group' => 'general', 'panel' => 'button', 'ui' => 'seconds', 'unit' => 'ms', 'min' => 500, 'max' => 5000, 'step' => 100 ) ),
		), 'items' => self::items( array(
			self::field( 'url', 'url', 'TikTok 视频地址', '', array( 'help' => '输入完整 HTTPS 链接，例如 https://www.tiktok.com/@scout2015/video/6718335390845095173。短链需先在 TikTok 打开并复制完整地址。' ) ),
			self::field( 'repeater', 'products', '关联商品', null, array( 'ui' => 'media-product-multiple', 'mediaProducts' => true, 'max' => 20, 'fields' => array( self::field( 'record', 'product', '商品', null, array( 'resource' => 'product' ) ) ), 'help' => '本地上限：每个视频20个商品、每个区块12条视频。失效ID保留；买家只看到当前可公开商品。' ) ),
		), 12 ) );
		$defs['tiktok_list']['items']['label'] = '视频';
		$defs['tiktok_list']['items']['default_count'] = 6;
		$defs['tiktok_list']['items']['max'] = 12;
		$defs['tiktok_list']['editorDefaults'] = array( 'title' => 'TikTok' );
		$defs['tiktok_list']['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml', 'description' ) ),
			array( 'id' => 'button', 'label' => '查看更多与按钮效果', 'fields' => array( 'moreLabel', 'moreUrl', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);

		$defs['social_links'] = array( 'title' => __( 'Social media links', 'peptide-core' ), 'category' => 'operation', 'icon' => 'share', 'settings' => self::heading(), 'items' => self::items( array( self::field( 'text', 'title', __( 'Network name', 'peptide-core' ), '' ), self::field( 'url', 'url', __( 'Profile URL', 'peptide-core' ), '' ) ) ) );
		$defs['partners'] = array( 'title' => __( 'Partner logos', 'peptide-core' ), 'category' => 'operation', 'icon' => 'groups', 'settings' => array_merge( self::heading(), self::layout_fields() ), 'items' => self::items( array( self::field( 'text', 'title', __( 'Partner name', 'peptide-core' ), '' ), self::field( 'image', 'image', __( 'Logo', 'peptide-core' ) ), self::field( 'url', 'url', __( 'Website', 'peptide-core' ), '' ) ) ) );
		// O06 is a factory-only opt-in. Existing partner fields and legacy renderer stay intact.
		$defs['partners']['settings'] = array_merge( $defs['partners']['settings'], array(
			self::field( 'select', 'partnersMode', '合作伙伴外观', 'legacy', array( 'group' => 'general', 'panel' => 'layout', 'options' => array( 'legacy' => '原有品牌列表', 'reference' => '合作伙伴展示' ) ) ),
			self::field( 'html', 'descriptionHtml', '简短描述', null, array( 'group' => 'general', 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/partners', 'panel' => 'content', 'show_if' => array( 'partnersMode' => 'reference' ) ) ),
			self::field( 'select', 'partnersStyle', '样式', 'slider', array( 'group' => 'layout', 'panel' => 'layout', 'options' => array( 'slider' => '横向滑动', 'grid' => '竖向平铺', 'scroll' => '横向滚动' ), 'show_if' => array( 'partnersMode' => 'reference' ) ) ),
			self::field( 'select', 'partnersMobileIndicator', '移动端滑动展示', 'dots', array( 'group' => 'layout', 'panel' => 'layout', 'options' => array( 'dots' => '圆圈', 'scrollbar' => '滚动条' ), 'show_if' => array( 'partnersMode' => 'reference', 'partnersStyle' => 'slider' ) ) ),
			self::field( 'select', 'partnersGridAlign', '最后一排对齐', 'center', array( 'group' => 'layout', 'panel' => 'layout', 'options' => array( 'center' => '居中', 'right' => '右对齐', 'left' => '左对齐' ), 'show_if' => array( 'partnersMode' => 'reference', 'partnersStyle' => 'grid' ) ) ),
			self::field( 'number', 'partnersDesktopColumns', '电脑端一排数量', 6, array( 'group' => 'layout', 'panel' => 'layout', 'ui' => 'range', 'min' => 2, 'max' => 14, 'step' => 1, 'show_if' => array( 'partnersMode' => 'reference' ) ) ),
			self::field( 'number', 'partnersMobileColumns', '手机端一排数量', 3, array( 'group' => 'layout', 'panel' => 'layout', 'ui' => 'range', 'min' => 1, 'max' => 4, 'step' => 1, 'show_if' => array( 'partnersMode' => 'reference' ) ) ),
			self::field( 'select', 'partnersRatio', '强制图片尺寸', 'original', array( 'group' => 'layout', 'panel' => 'image', 'options' => array( 'original' => '原始', '1:1' => '正方形 (1:1)', '3:4' => '长方形 (3:4)', '2:3' => '竖长方形 (2:3)', '1:2' => '竖长方形 (1:2)' ), 'show_if' => array( 'partnersMode' => 'reference' ) ) ),
			self::field( 'select', 'partnersFit', '列表图片填充规则', 'cover', array( 'group' => 'layout', 'panel' => 'image', 'options' => array( 'contain' => '自适应长边中心显示', 'cover' => '图片自适应中心显示', 'fill' => '图片铺满' ), 'help' => '仅在强制图片尺寸不是原始时生效。', 'show_if' => array( 'partnersMode' => 'reference' ) ) ),
			self::field( 'toggle', 'partnersSeamless', '无缝滚动', false, array( 'group' => 'play', 'panel' => 'play', 'show_if' => array( 'partnersMode' => 'reference', 'partnersStyle' => 'scroll' ) ) ),
			self::field( 'toggle', 'partnersPauseOnHover', '鼠标悬停时暂停', true, array( 'group' => 'play', 'panel' => 'play', 'show_if' => array( 'partnersMode' => 'reference', 'partnersStyle' => 'scroll' ) ) ),
			self::field( 'select', 'partnersDirection', '滚动方向', 'left', array( 'group' => 'play', 'panel' => 'play', 'options' => array( 'left' => '靠左', 'right' => '靠右' ), 'show_if' => array( 'partnersMode' => 'reference', 'partnersStyle' => 'scroll' ) ) ),
			self::field( 'number', 'partnersSpeedSeconds', '滚动速度', 4, array( 'group' => 'play', 'panel' => 'play', 'ui' => 'range', 'unit' => 's', 'min' => 1, 'max' => 60, 'step' => 1, 'help' => '每移动一个品牌位置所需秒数。', 'show_if' => array( 'partnersMode' => 'reference', 'partnersStyle' => 'scroll' ) ) ),
		) );
		foreach ( $defs['partners']['settings'] as &$field ) {
			if ( 'title' === $field['key'] ) { $field['panel'] = 'content'; }
			if ( in_array( $field['key'], array( 'description', 'limit', 'columns' ), true ) ) { $field['show_if'] = array( 'partnersMode' => 'legacy' ); $field['panel'] = 'description' === $field['key'] ? 'content' : 'layout'; }
		}
		unset( $field );
		$defs['partners']['items']['fields'] = array_merge( $defs['partners']['items']['fields'], array(
			self::field( 'textarea', 'partnersCaption', '文字', null, array( 'help' => '普通文字，按内容自动换行。' ) ),
			self::field( 'color', 'partnersTextColor', '文字颜色', '#1d1f21' ),
			self::field( 'url', 'partnersHref', '跳转链接', null, array( 'ui' => 'site-link', 'help' => '选择已有站内页面或填写网址；留空不跳转。' ) ),
		) );
		$defs['partners']['items']['label'] = '品牌';
		$defs['partners']['items']['default_count'] = 6;
		$defs['partners']['items']['editorDefaults'] = array( 'partnersCaption' => '', 'partnersHref' => '' );
		$defs['partners']['items']['parentMode'] = array( 'setting' => 'partnersMode', 'fallback' => 'legacy', 'fields' => array( 'legacy' => array( 'title', 'image', 'url' ), 'reference' => array( 'image', 'partnersCaption', 'partnersTextColor', 'partnersHref' ) ) );
		$defs['partners']['editorDefaults'] = array( 'partnersMode' => 'reference', 'title' => 'Partner' );
		$defs['partners']['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml', 'description' ) ),
			array( 'id' => 'layout', 'label' => '排列与数量', 'initialOpen' => true, 'fields' => array( 'partnersMode', 'partnersStyle', 'partnersMobileIndicator', 'partnersGridAlign', 'partnersDesktopColumns', 'partnersMobileColumns', 'limit', 'columns' ) ),
			array( 'id' => 'image', 'label' => '图片尺寸', 'fields' => array( 'partnersRatio', 'partnersFit' ) ),
			array( 'id' => 'play', 'label' => '滚动设置', 'fields' => array( 'partnersSeamless', 'partnersPauseOnHover', 'partnersDirection', 'partnersSpeedSeconds' ) ),
		);
		$defs['product_search'] = array( 'title' => __( 'Advanced product search', 'peptide-core' ), 'category' => 'operation', 'icon' => 'search', 'singleRender' => true, 'settings' => array_merge( self::heading(), array( self::field( 'toggle', 'priceRange', __( 'Show price range fields', 'peptide-core' ), true ) ) ) );
		$defs['search_terms'] = array( 'title' => __( 'Suggested product searches', 'peptide-core' ), 'category' => 'operation', 'icon' => 'search', 'settings' => self::heading(), 'items' => self::items( array( self::field( 'text', 'title', __( 'Search term', 'peptide-core' ), '' ) ) ) );
		// O02 opts in only for a newly added search-terms composition; old normalized blocks remain legacy.
		$defs['search_terms']['settings'] = array_merge( $defs['search_terms']['settings'], array(
			self::field( 'select', 'searchTermsMode', '搜索词外观', 'legacy', array( 'options' => array( 'legacy' => '原有搜索词列表', 'reference' => '居中搜索词' ), 'panel' => 'layout' ) ),
			self::field( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/search-terms', 'panel' => 'content', 'show_if' => array( 'searchTermsMode' => 'reference' ) ) ),
			self::field( 'color', 'termsTitleColor', '标题颜色', '#1d1f21', array( 'panel' => 'style', 'show_if' => array( 'searchTermsMode' => 'reference' ) ) ),
			self::field( 'color', 'termsDescriptionColor', '简述颜色', '#666666', array( 'panel' => 'style', 'show_if' => array( 'searchTermsMode' => 'reference' ) ) ),
			self::field( 'image', 'termsBackgroundImage', '背景图片', null, array( 'panel' => 'style', 'show_if' => array( 'searchTermsMode' => 'reference' ) ) ),
		) );
		foreach ( array( 'Top' => '上', 'Right' => '右', 'Bottom' => '下', 'Left' => '左' ) as $edge => $label ) {
			$default = in_array( $edge, array( 'Top', 'Bottom' ), true ) ? 30 : 0;
			$defs['search_terms']['settings'][] = self::field( 'number', 'termsPadding' . $edge . 'Px', '内容' . $label . '间距', array( 'desktop' => $default, 'mobile' => $default ), array( 'device' => true, 'ui' => 'range', 'unit' => 'px', 'min' => 0, 'max' => 200, 'step' => 1, 'panel' => 'spacing', 'show_if' => array( 'searchTermsMode' => 'reference' ) ) );
		}
		foreach ( $defs['search_terms']['settings'] as &$field ) {
			if ( 'title' === $field['key'] ) { $field['panel'] = 'content'; }
			if ( 'description' === $field['key'] ) { $field['panel'] = 'content'; $field['show_if'] = array( 'searchTermsMode' => 'legacy' ); }
		}
		unset( $field );
		$defs['search_terms']['items']['fields'][] = self::field( 'url', 'url', '关键词链接', '', array( 'ui' => 'site-link', 'help' => '选择站内页面或填写网址；留空按这个关键词搜索商品。' ) );
		$defs['search_terms']['items']['parentMode'] = array( 'setting' => 'searchTermsMode', 'fallback' => 'legacy', 'fields' => array( 'legacy' => array( 'title' ), 'reference' => array( 'title', 'url' ) ) );
		$defs['search_terms']['editorDefaults'] = array( 'searchTermsMode' => 'reference', 'title' => 'Suggested searches' );
		$defs['search_terms']['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml', 'description' ) ),
			array( 'id' => 'layout', 'label' => '搜索词外观', 'fields' => array( 'searchTermsMode' ) ),
			array( 'id' => 'style', 'label' => '颜色与背景', 'fields' => array( 'termsTitleColor', 'termsDescriptionColor', 'termsBackgroundImage' ) ),
			array( 'id' => 'spacing', 'label' => '内容间距', 'ui' => 'box-model', 'fields' => array( 'termsPaddingTopPx', 'termsPaddingRightPx', 'termsPaddingBottomPx', 'termsPaddingLeftPx' ) ),
		);

		$search_types = array( 'products' => '商品', 'collections' => '专辑', 'topics' => '专题', 'blogs' => '博客', 'pages' => '页面（含政策）', 'promotions' => '满减活动', 'coupons' => '优惠码' );
		$search_defaults = array();
		foreach ( array_slice( array_keys( $search_types ), 0, 5 ) as $type ) { $search_defaults[] = array( 'id' => 'search-' . $type, 'enabled' => true, 'settings' => array( 'type' => $type ) ); }
		$defs['site_search_hero'] = array(
			'title' => '多类型高级搜索', 'category' => 'operation', 'icon' => 'search', 'singleRender' => true,
			'editorDefaults' => array( 'descriptionHtml' => '<p>Find Your Favorite</p>' ),
			'settings' => array(
				self::field( 'text', 'title', '标题', 'Quick Search', array( 'panel' => 'content' ) ),
				self::field( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/site-search-hero', 'panel' => 'content' ) ),
				self::field( 'text', 'placeholder', '搜索框提示', 'Search', array( 'panel' => 'content' ) ),
				self::field( 'repeater', 'searchTypes', '搜索范围', $search_defaults, array( 'ui' => 'site-search-types', 'siteSearchTypes' => true, 'max' => 7, 'fields' => array( self::field( 'select', 'type', '类型', null, array( 'options' => $search_types ) ) ), 'panel' => 'content', 'help' => '选择可搜索的公开内容；全部取消后不显示搜索入口。' ) ),
				self::field( 'toggle', 'fullWidth', '宽度铺满', true, array( 'panel' => 'layout' ) ),
				self::field( 'image', 'backgroundImage', '背景图片', null, array( 'panel' => 'image', 'help' => '可设置替代文字；建议横向图片，素材建议尺寸不决定实际区块高度。' ) ),
				self::field( 'select', 'imageHeight', '图片高度', 'medium', array( 'options' => array( 'small' => '小', 'medium' => '中', 'large' => '大', 'auto' => '自适应' ), 'panel' => 'image' ) ),
				self::field( 'number', 'overlayPercent', '图片遮罩', 20, array( 'ui' => 'range', 'unit' => '%', 'min' => 1, 'max' => 100, 'step' => 1, 'panel' => 'image' ) ),
			),
			'panels' => array(
				array( 'id' => 'content', 'label' => '标题与搜索范围', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml', 'placeholder', 'searchTypes' ) ),
				array( 'id' => 'image', 'label' => '背景图片', 'initialOpen' => true, 'fields' => array( 'backgroundImage', 'imageHeight', 'overlayPercent' ) ),
				array( 'id' => 'layout', 'label' => '布局', 'fields' => array( 'fullWidth' ) ),
			),
		);

		// P16 is a distinct promotional composition; existing activity and promo-grid blocks stay unchanged.
		$defs['promotion_trio'] = array(
			'title' => '促销', 'category' => 'product', 'icon' => 'columns',
			'settings' => array( self::field( 'number', 'cardGap', '区块间距（电脑）', 24, array( 'ui' => 'range', 'min' => 0, 'max' => 100, 'step' => 4, 'unit' => 'px', 'panel' => 'layout', 'help' => '手机按单列排列，卡片间距为16px。' ) ) ),
			'panels' => array( array( 'id' => 'layout', 'label' => '布局', 'initialOpen' => true, 'fields' => array( 'cardGap' ) ) ),
			'items' => array(
				'label' => '卡片', 'default_count' => 3, 'min' => 0, 'max' => 12, 'title_field' => 'cardType',
				'default_items' => array( array( 'cardType' => 'product' ), array( 'cardType' => 'promotion' ), array( 'cardType' => 'product' ) ),
				'fields' => array(
					self::field( 'select', 'cardType', '卡片类型', 'product', array( 'ui' => 'item-title-option', 'panel' => 'card', 'options' => array( 'product' => '产品卡片', 'promotion' => '促销卡片' ) ) ),
					self::field( 'image', 'productImage', '产品图片', null, array( 'panel' => 'content', 'show_if' => array( 'cardType' => 'product' ), 'help' => '建议图片宽度1242px，高度自适应。图片与专辑独立设置。' ) ),
					self::field( 'url', 'imageHref', '图片链接', '', array( 'ui' => 'site-link', 'panel' => 'content', 'show_if' => array( 'cardType' => 'product' ) ) ),
					self::field( 'category', 'collection', '选择专辑', null, array( 'ui' => 'collection', 'panel' => 'content', 'show_if' => array( 'cardType' => 'product' ), 'help' => '按钮打开此专辑的前两个公开商品，展示当前图片、名称、价格与商品详情。图片单独设置。' ) ),
					self::field( 'text', 'productButtonLabel', '按钮文字', 'View Products', array( 'panel' => 'content', 'show_if' => array( 'cardType' => 'product' ), 'help' => '留空可隐藏商品按钮与弹层。' ) ),
					self::field( 'select', 'productButtonStyle', '按钮样式', 'primary', array( 'panel' => 'style', 'show_if' => array( 'cardType' => 'product' ), 'options' => array( 'primary' => '主要', 'secondary' => '次要' ) ) ),
					self::field( 'image', 'backgroundImage', '背景图片', null, array( 'panel' => 'content', 'show_if' => array( 'cardType' => 'promotion' ), 'help' => '建议图片宽度1242px，高度自适应。' ) ),
					self::field( 'text', 'subtitle', '副标题', '', array( 'panel' => 'content', 'show_if' => array( 'cardType' => 'promotion' ) ) ),
					self::field( 'text', 'title', '标题', '', array( 'panel' => 'content', 'show_if' => array( 'cardType' => 'promotion' ) ) ),
					self::field( 'textarea', 'description', '描述', '', array( 'panel' => 'content', 'show_if' => array( 'cardType' => 'promotion' ) ) ),
					self::field( 'toggle', 'showCountdown', '显示倒计时', true, array( 'panel' => 'content', 'show_if' => array( 'cardType' => 'promotion' ) ) ),
					self::field( 'text', 'endDate', '倒计时结束日期', '', array( 'ui' => 'date', 'panel' => 'content', 'show_if' => array( 'cardType' => 'promotion', 'showCountdown' => true ), 'help' => '按店铺时区当天23:59:59结束。留空不显示；此手动日期不会创建优惠或改变关联活动期间。' ) ),
					self::field( 'text', 'promotionButtonLabel', '按钮文字', 'Shop Sale', array( 'panel' => 'content', 'show_if' => array( 'cardType' => 'promotion' ) ) ),
					self::field( 'url', 'promotionHref', '按钮链接', '', array( 'ui' => 'site-link', 'panel' => 'content', 'show_if' => array( 'cardType' => 'promotion' ), 'help' => '可选择已有满减、优惠码或专题等站内页面；实际优惠由营销活动设置管理。' ) ),
					self::field( 'color', 'textColor', '文字颜色', '#ffffff', array( 'panel' => 'style', 'show_if' => array( 'cardType' => 'promotion' ) ) ),
					self::field( 'color', 'overlayColor', '蒙层颜色', '#00000000', array( 'ui' => 'alpha-color', 'panel' => 'style', 'show_if' => array( 'cardType' => 'promotion' ) ) ),
				),
				'panels' => array(
					array( 'id' => 'card', 'label' => '卡片设置', 'initialOpen' => true, 'fields' => array( 'cardType' ) ),
					array( 'id' => 'content', 'label' => '内容与链接', 'initialOpen' => true ),
					array( 'id' => 'style', 'label' => '颜色与按钮' ),
				),
			),
		);

		$defs['shop_by_gram'] = Shop_By_Gram::definition();

		$defs['order_fulfillment'] = Order_Fulfillment::definition();
		foreach ( Social_Feeds::definitions() as $social_type => $social_definition ) { $defs[ $social_type ] = $social_definition; }

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


	/** Only O21 uses this local icon policy; no shared Assets or RTE approval semantics change. */
	private static function announcement_image( $value ): ?array {
		if ( ! is_array( $value ) ) { return null; }
		$id = (int) ( $value['attachmentId'] ?? 0 );
		if ( $id > 0 ) {
			$image = get_post( $id );
			if ( ! $image instanceof \WP_Post || 'attachment' !== $image->post_type || ! in_array( $image->post_status, array( 'inherit', 'publish' ), true ) || '' !== (string) $image->post_password || metadata_exists( 'post', $id, '_pc_review_owner' ) || metadata_exists( 'post', $id, '_pc_review_file' ) || ! wp_attachment_is_image( $id ) ) { return null; }
			$parent = $image->post_parent ? get_post( (int) $image->post_parent ) : null;
			if ( $parent instanceof \WP_Post && ( 'private' === $parent->post_status || '' !== (string) $parent->post_password ) ) { return null; }
		}
		return Assets::resolve( $value, 'thumbnail' );
	}
	private static function announcement_icon( string $name ): string {
		$paths = array(
			'megaphone' => 'M3 10v4h4l10 4V6L7 10zM7 14l2 6h3l-2-5M20 9v6',
			'bell' => 'M5 17h14l-2-3v-4a5 5 0 0 0-10 0v4zM10 20h4M12 3V2',
			'gift' => 'M3 10h18v4H3zM5 14v7h14v-7M12 10v11M12 10C5 10 5 3 8 3c3 0 4 7 4 7s1-7 4-7c3 0 3 7-4 7',
			'tag' => 'M3 3h8l10 10-8 8L3 11zM7 7h.01',
			'spark' => 'M12 2l3 7 7 3-7 3-3 7-3-7-7-3 7-3z',
			'ticket' => 'M3 5h18v5a2 2 0 0 0 0 4v5H3v-5a2 2 0 0 0 0-4zM15 5v3M15 11v2M15 16v3',
			'clock' => 'M12 3a9 9 0 1 0 0 18 9 9 0 1 0 0-18M12 7v5l4 2',
		);
		$path = $paths[ $name ] ?? $paths['megaphone'];
		return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . esc_attr( $path ) . '"/></svg>';
	}

	private static function announcement_href( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || str_starts_with( $value, '//' ) || preg_match( '/[\x00-\x1f\x7f\\\\]/', $value ) ) { return ''; }
		if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $value, $match ) && ! in_array( strtolower( $match[1] ), array( 'http', 'https', 'mailto', 'tel' ), true ) ) { return ''; }
		return Fields::url( $value );
	}
	private static function announcement_reference( array $module, string $context ): string {
		$s = $module['settings']; $rows = ''; $count = 0;
		$image = self::announcement_image( $s['announcementImage'] ?? null );
		$icon = $image ? '<img src="' . esc_url( $image['src'] ) . '" alt="" width="22" height="22" loading="lazy">' : self::announcement_icon( (string) ( $s['announcementIcon'] ?? 'megaphone' ) );
		foreach ( array_slice( self::rows( $s['announcementRows'] ?? array() ), 0, 12 ) as $row ) {
			$v = $row['settings']; $pc = trim( (string) ( $v['pcText'] ?? '' ) ); $phone = trim( (string) ( $v['phoneText'] ?? '' ) );
			if ( '' === $pc && '' === $phone && '' === trim( (string) ( $v['link']['label'] ?? '' ) ) ) { continue; }
			$pc = '' !== $pc ? $pc : $phone; $phone = '' !== $phone ? $phone : $pc; $link = $v['link'] ?? array(); $href = '';
			if ( 'binding' === ( $v['targetMode'] ?? 'manual' ) ) {
				try { $target = Links::announcement_target( (string) ( $v['targetKind'] ?? '' ), (string) ( $v['targetId'] ?? '' ) ); $href = $target['url'] ?? ''; } catch ( \Throwable $problem ) { $href = ''; }
			} else { $href = self::announcement_href( (string) ( $link['href'] ?? '' ) ); }
			$label = trim( (string) ( $link['label'] ?? '' ) ); $button = '';
			if ( '' !== $href && '' !== $label ) { $button = '<a class="pc-announcement-reference__button" href="' . esc_url( $href ) . '"' . ( '_blank' === ( $link['target'] ?? '_self' ) ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '><span class="pc-campaign__button pc-campaign__button--' . esc_attr( $s['announcementEffect'] ?? 'none' ) . '">' . esc_html( $label ) . '</span></a>'; }
			if ( '' === $pc && '' === $phone && '' === $button ) { continue; }
			$rows .= '<div class="pc-announcement-reference__row" data-pc-announcement-row data-module-item="' . esc_attr( $row['id'] ) . '"><span class="pc-announcement-reference__icon">' . $icon . '</span><span class="pc-announcement-reference__copy"><span class="pc-announcement-reference__pc">' . esc_html( $pc ) . '</span><span class="pc-announcement-reference__phone">' . esc_html( $phone ) . '</span></span>' . $button . '</div>'; ++$count;
		}
		if ( ! $count ) { return 'editor' === $context ? '<div class="pc-announcement-reference__empty">添加公告内容后将在这里预览。</div>' : ''; }
		$vars = array();
		foreach ( array( 'announcementBackground' => array( 'background', '#000000' ), 'announcementText' => array( 'text', '#ffffff' ), 'announcementButtonBackground' => array( 'button-background', '#ffffff' ), 'announcementButtonText' => array( 'button-text', '#000000' ) ) as $key => $color ) { $vars[] = '--pc-announcement-' . $color[0] . ':' . ( sanitize_hex_color( $s[ $key ] ?? '' ) ?: $color[1] ); }
		$vars[] = '--pc-campaign-effect-duration:' . max( 1000, min( 5000, (int) ( $s['announcementEffectDurationMs'] ?? 1000 ) ) ) . 'ms';
		return '<section class="pc-announcement-reference' . ( ! empty( $s['announcementFullWidth'] ) ? ' pc-announcement-reference--full' : '' ) . '" data-pc-announcement-reference data-editor="' . ( self::interactive( $context ) ? '0' : '1' ) . '" data-interval="' . max( 1000, min( 10000, (int) ( $s['announcementIntervalMs'] ?? 3000 ) ) ) . '" data-effect="' . esc_attr( $s['announcementEffect'] ?? 'none' ) . '" data-effect-duration="' . max( 1000, min( 5000, (int) ( $s['announcementEffectDurationMs'] ?? 1000 ) ) ) . '" data-effect-interval="' . max( 500, min( 5000, (int) ( $s['announcementEffectIntervalMs'] ?? 1500 ) ) ) . '" aria-label="' . esc_attr__( 'Store announcements', 'peptide-core' ) . '" style="' . esc_attr( implode( ';', $vars ) ) . '"><div class="pc-announcement-reference__inner"><div class="pc-announcement-reference__viewport" tabindex="0" role="group" aria-roledescription="carousel" aria-label="' . esc_attr__( 'Announcements. Use up and down arrow keys to change.', 'peptide-core' ) . '"><div class="pc-announcement-reference__track">' . $rows . '</div></div><button type="button" class="pc-announcement-reference__pause" data-pc-announcement-pause hidden aria-pressed="false" aria-label="' . esc_attr__( 'Pause announcements', 'peptide-core' ) . '"><span data-pc-announcement-pause-icon aria-hidden="true"><svg class="pc-announcement-reference__pause-icon--pause" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M9 5v14M15 5v14"/></svg><svg class="pc-announcement-reference__pause-icon--play" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 4l12 8-12 8z"/></svg></span></button><span class="pc-announcement-reference__status" data-pc-announcement-status role="status" aria-live="off"></span></div></section>';
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

	/** An explicitly chosen public product, never a second catalog or stock source. */
	private static function featured_detail( array $module, string $device ): string {
		$s = $module['settings'];
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) ( $s['product'] ?? 0 ) ) : null;
		if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() || ! $product->is_publicly_viewable() || '' !== $product->get_post_password() ) { return ''; }
		$instance = wp_unique_id( 'pc-featured-' . $module['id'] . '-' . $device . '-' );
		$gallery = ''; $thumbs = ''; $count = 0;
		$images = array_values( array_unique( array_filter( array_map( 'absint', array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) ) ) );
		foreach ( $images as $image_id ) {
			if ( ! wp_attachment_is_image( $image_id ) ) { continue; }
			$image = wp_get_attachment_image( $image_id, 'woocommerce_single', false, array( 'loading' => 'lazy', 'decoding' => 'async' ) );
			if ( ! $image ) { continue; }
			$gallery .= '<figure class="pc-featured__picture" id="' . esc_attr( $instance . '-image-' . $count ) . '">' . $image . '</figure>';
			$thumbs .= '<button type="button" data-pc-featured-image="' . $count . '" aria-label="' . esc_attr( sprintf( __( 'Show product image %d', 'peptide-core' ), $count + 1 ) ) . '" aria-controls="' . esc_attr( $instance . '-images' ) . '" aria-current="' . ( 0 === $count ? 'true' : 'false' ) . '" disabled>' . wp_get_attachment_image( $image_id, 'woocommerce_gallery_thumbnail', false, array( 'alt' => '', 'loading' => 'lazy' ) ) . '</button>';
			++$count;
		}
		$out = '<div class="pc-featured product woocommerce' . ( 'right' === ( $s['imagePosition'] ?? 'left' ) ? ' is-image-right' : '' ) . ( $gallery ? ' has-media' : '' ) . '" data-pc-featured>';
		if ( $gallery ) { $out .= '<div class="pc-featured__gallery" data-pc-featured-gallery><div class="pc-featured__images" id="' . esc_attr( $instance . '-images' ) . '" tabindex="0" aria-label="' . esc_attr__( 'Product images', 'peptide-core' ) . '">' . $gallery . '</div>' . ( $count > 1 ? '<div class="pc-featured__thumbnails" aria-label="' . esc_attr__( 'Choose product image', 'peptide-core' ) . '">' . $thumbs . '</div>' : '' ) . '</div>'; }
		$out .= '<div class="pc-featured__summary summary"><h2 class="pc-featured__title">' . esc_html( $product->get_name() ) . '</h2>';
		$subtitle = $product->get_meta( \PeptideCore\Meta::PRODUCT_SUBTITLE );
		if ( ! empty( $s['showSubtitle'] ) && is_string( $subtitle ) && '' !== trim( $subtitle ) ) { $out .= '<p class="pc-featured__subtitle">' . esc_html( $subtitle ) . '</p>'; }
		$description = $product->get_short_description();
		if ( ! empty( $s['showShortDescription'] ) && '' !== trim( $description ) ) { $out .= '<div class="pc-featured__description">' . wp_kses_post( $description ) . '</div>'; }
		$price = ! $product->is_type( 'variable' ) && function_exists( 'vc_public_price_html' ) ? vc_public_price_html( $product ) : $product->get_price_html();
		$out .= '<p class="price pc-featured__price">' . wp_kses_post( $price ) . '</p>';
		if ( Showcase_Quick_View::supports( $product ) ) { $out .= Showcase_Quick_View::inline_purchase( $product, $instance ); }
		else { $out .= '<a class="button pc-featured__details" href="' . esc_url( $product->get_permalink() ) . '">' . esc_html__( 'View purchasing options', 'peptide-core' ) . '</a>'; }
		return $out . '</div></div>';
	}

	/** Explicit product selection uses PDP visibility, rather than catalog inclusion. */
	private static function video_product( int $id ): ?\WC_Product {
		$product = wc_get_product( $id ); $post = get_post( $id );
		return $product && $post && 'product' === $post->post_type && 'publish' === $product->get_status() && '' === $post->post_password && is_post_publicly_viewable( $post ) ? $product : null;
	}

	/** Uploaded public files only; private review and nonpublic parent media remain unavailable. */
	private static function video_file( int $id ): string {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! in_array( $post->post_status, array( 'inherit', 'publish' ), true ) || '' !== $post->post_password || ! str_starts_with( (string) get_post_mime_type( $id ), 'video/' ) || metadata_exists( 'post', $id, '_pc_review_owner' ) || metadata_exists( 'post', $id, '_pc_review_file' ) ) { return ''; }
		if ( $post->post_parent ) { $parent = get_post( (int) $post->post_parent ); if ( ! $parent || '' !== $parent->post_password || ! is_post_publicly_viewable( $parent ) ) { return ''; } }
		return (string) wp_get_attachment_url( $id );
	}

	private static function video_products( array $module, string $context ): string {
		$s = $module['settings']; $out = ''; $missing = 0;
		foreach ( array_slice( self::rows( $module['items'] ), 0, (int) $s['limit'] ) as $item ) {
			$row = $item['settings']; $product = self::video_product( (int) $row['product'] ); $id = (int) ( $row['video']['attachmentId'] ?? 0 );
			if ( ! $product || '' === self::video_file( $id ) ) { $missing++; continue; }
			$image_id = (int) $product->get_image_id();
			$thumb = $image_id && wp_attachment_is_image( $image_id ) ? wp_get_attachment_image( $image_id, 'thumbnail', false, array( 'class' => 'pc-video-products__thumb', 'alt' => '', 'loading' => 'lazy' ) ) : '';
			$link = '<a class="pc-video-products__product" href="' . esc_url( $product->get_permalink() ) . '">' . $thumb . '<span>' . esc_html( $product->get_name() ) . '</span><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14m-6-6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></a>';
			$out .= '<article class="pc-video-products__item" data-module-item="' . esc_attr( $item['id'] ) . '"><div class="pc-video-products__card">' . Fallback::local_file_video( $id, (bool) $s['muted'], (bool) $s['loop'], 'pc-video-products__media' ) . $link . '<p class="pc-video-products__status" role="status" aria-live="polite" hidden></p></div></article>';
		}
		if ( '' === $out && ! in_array( $context, array( 'editor', 'preview' ), true ) ) { return ''; }
		$description = null !== ( $s['descriptionHtml'] ?? null ) ? (string) $s['descriptionHtml'] : ( '' !== $s['description'] ? '<p>' . nl2br( esc_html( $s['description'] ) ) . '</p>' : '' );
		$heading = ( '' !== $s['title'] ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-video-products__description">' . $description . '</div>' : '' );
		$notice = in_array( $context, array( 'editor', 'preview' ), true ) && ( $missing || '' === $out ) ? '<p class="pc-video-products__empty">每条需选择可公开的商品和视频文件。未配齐或不可公开的条目不会对买家展示。</p>' : '';
		$ratio = in_array( $s['videoRatio'] ?? '', array( '9:16', '16:9', '4:3', '1:1' ), true ) ? $s['videoRatio'] : '9:16';
		$display = 'grid' === ( $s['videoDisplay'] ?? '' ) ? 'grid' : 'single'; $columns = $s['columns'];
		$style = '--pc-video-ratio:' . str_replace( ':', '/', $ratio ) . ';--pc-video-cols:' . max( 1, min( 6, (int) $columns['desktop'] ) ) . ';--pc-video-cols-m:' . max( 1, min( 6, (int) ( $columns['mobile'] ?? $columns['desktop'] ) ) );
		$more = $s['videoMore'] ?? array(); $button = '';
		if ( ! empty( $more['label'] ) && ! empty( $more['href'] ) ) {
			$effect = in_array( $s['buttonEffect'] ?? '', self::EFFECTS, true ) ? $s['buttonEffect'] : 'none';
			$button = '<div class="pc-video-products__more"><a class="vc-btn" href="' . esc_url( $more['href'] ) . '"' . ( '_blank' === ( $more['target'] ?? '_self' ) ? ' target="_blank" rel="noopener noreferrer"' : '' ) . ' data-vc-button-effect="' . esc_attr( $effect ) . '" data-vc-effect-duration="' . (int) $s['effectDurationMs'] . '" data-vc-effect-interval="' . (int) $s['effectIntervalMs'] . '">' . esc_html( $more['label'] ) . '</a></div>';
		}
		$controls = 'single' === $display && '' !== $out ? '<div class="pc-video-products__controls" hidden><button type="button" data-pc-video-prev aria-label="Previous video">←</button><button type="button" data-pc-video-next aria-label="Next video">→</button></div>' : '';
		return '<div class="pc-cb vc-m__inner pc-video-products" data-pc-commerce="video_products" data-pc-video-products data-display="' . $display . '" data-ratio="' . esc_attr( $ratio ) . '" data-auto-carousel="' . ( ! empty( $s['autoCarousel'] ) ? '1' : '0' ) . '" data-autoplay="' . ( ! empty( $s['autoplay'] ) ? '1' : '0' ) . '" data-muted="' . ( ! empty( $s['muted'] ) ? '1' : '0' ) . '"' . ( in_array( $context, array( 'editor', 'preview' ), true ) ? ' data-editor="1"' : '' ) . ' style="' . esc_attr( $style ) . '">' . ( $heading ? '<header class="pc-video-products__head">' . $heading . '</header>' : '' ) . $notice . ( $out ? '<div class="pc-video-products__list">' . $out . '</div>' : '' ) . $controls . $button . '</div>';
	}

	/** Explicit featured PDPs retain P01 eligibility, including public catalog-hidden products. */
	private static function selected_public_product( $product ): bool {
		return $product instanceof \WC_Product && 'product' === get_post_type( $product->get_id() ) && 'publish' === $product->get_status()
			&& $product->is_publicly_viewable() && '' === $product->get_post_password();
	}

	private static function merchandise_card( $product, bool $quick, bool $explicit = false ): string {
		if ( ! ( $explicit ? self::selected_public_product( $product ) : Products::public_catalog_product( $product ) ) ) { return ''; }
		if ( $explicit && function_exists( 'vc_product_card' ) ) {
			ob_start(); vc_product_card( $product ); $card = (string) ob_get_clean();
			$card = (string) apply_filters( 'peptide_core_commerce_product_card_html', $card, $product );
		} elseif ( $explicit && ! $product->is_visible() ) {
			$card = '<article class="pc-cb-card"><a href="' . esc_url( $product->get_permalink() ) . '">' . wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ) . '<h3>' . esc_html( $product->get_name() ) . '</h3></a><div class="price">' . wp_kses_post( $product->get_price_html() ) . '</div></article>';
		} else { $card = self::card( $product ); }
		if ( '' === $card ) { return ''; }
		$can_quick = $quick && Showcase_Quick_View::supports( $product );
		return '<div class="pc-merch-card" data-pc-merch-product="' . (int) $product->get_id() . '">' . $card . ( $can_quick ? '<a class="pc-merch-card__quick" href="' . esc_url( $product->get_permalink() ) . '" data-vc-quick-open="' . (int) $product->get_id() . '">' . esc_html__( 'Quick view', 'peptide-core' ) . '</a>' . Showcase_Quick_View::template( $product ) : '' ) . '</div>';
	}

	private static function merchandise_columns( array $s ): string {
		$cols = $s['columns'] ?? array( 'desktop' => 4, 'mobile' => 2 );
		return '--pc-merch-cols:' . max( 1, min( 6, (int) $cols['desktop'] ) ) . ';--pc-merch-cols-m:' . max( 1, min( 6, (int) ( $cols['mobile'] ?? $cols['desktop'] ) ) );
	}

	private static function merchandise_description( array $s ): string {
		return null !== ( $s['descriptionHtml'] ?? null ) ? wp_kses_post( $s['descriptionHtml'] ) : nl2br( esc_html( $s['description'] ?? '' ) );
	}

	private static function collection_recommendation( array $module, string $context ): string {
		$s = $module['settings']; $preview = in_array( $context, array( 'editor', 'preview' ), true );
		$category = (int) ( $s['collection'] ?? 0 ); $term = $category > 0 ? get_term( $category, 'product_cat' ) : null;
		if ( ! $term instanceof \WP_Term ) { return $preview ? '<div class="pc-cb vc-m__inner"><p>' . esc_html__( 'Choose a real collection to preview its image and products.', 'peptide-core' ) . '</p></div>' : ''; }
		$url = get_term_link( $term ); if ( is_wp_error( $url ) ) { return ''; }
		$limit = max( 1, min( 100, (int) ( $s['collectionLimit'] ?? 4 ) ) );
		try {
			$products = Products::collection_page( $category, 1, min( 50, $limit ) )['products'];
			if ( $limit > 50 ) { $products = array_merge( $products, Products::collection_page( $category, 2, 50 )['products'] ); }
			$products = array_slice( $products, 0, $limit );
		} catch ( \Throwable $error ) { return '<div class="pc-cb vc-m__inner"><p role="status">' . esc_html__( 'Collection products could not be loaded. Please refresh to try again.', 'peptide-core' ) . '</p></div>'; }
		$id = (int) ( $s['featuredProduct'] ?? 0 ); $featured = $id > 0 ? wc_get_product( $id ) : ( $products[0] ?? null );
		$quick = self::interactive( $context ); if ( $quick ) { Showcase_Quick_View::catalog_assets(); }
		$featured_card = self::merchandise_card( $featured, $quick, true );
		$grid = ''; foreach ( $products as $product ) { $grid .= self::merchandise_card( $product, $quick ); }
		$title = '' !== ( $s['title'] ?? '' ) ? $s['title'] : $term->name; $description = self::merchandise_description( $s );
		$image = wp_get_attachment_image( (int) get_term_meta( $term->term_id, 'thumbnail_id', true ), 'full', false, array( 'loading' => 'lazy', 'class' => 'pc-recommendation__image', 'alt' => '' ) );
		$effect = in_array( $s['buttonEffect'] ?? '', self::EFFECTS, true ) ? $s['buttonEffect'] : 'none';
		$label = trim( (string) ( $s['shopLabel'] ?? 'Shop now' ) );
		$button = '' !== $label ? '<span class="pc-campaign__button pc-campaign__button--' . esc_attr( $effect ) . '" data-pc-campaign-effect="' . esc_attr( $effect ) . '" data-effect-duration="' . max( 1000, min( 5000, (int) ( $s['effectDurationMs'] ?? 1000 ) ) ) . '" data-effect-interval="' . max( 500, min( 5000, (int) ( $s['effectIntervalMs'] ?? 1500 ) ) ) . '">' . esc_html( $label ) . '</span>' : '';
		$tile = '<article class="pc-recommendation__tile"><a class="pc-recommendation__collection-link" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $title . ( '' !== $label ? ': ' . $label : '' ) ) . '"></a>' . $image . '<div class="pc-recommendation__copy"><h2>' . esc_html( $title ) . '</h2>' . ( '' !== $description ? '<div class="pc-recommendation__description">' . $description . '</div>' : '' ) . $button . '</div></article>';
		$notice = '' === $featured_card && $preview ? '<p class="pc-recommendation__empty">' . esc_html__( 'No eligible public featured product is available. Choose a product or add public products to this collection.', 'peptide-core' ) . '</p>' : '';
		return '<div class="pc-cb vc-m__inner pc-recommendation' . ( 'left' === ( $s['featuredPosition'] ?? 'right' ) ? ' is-product-left' : '' ) . '" style="' . esc_attr( self::merchandise_columns( $s ) ) . '"><div class="pc-recommendation__hero">' . $tile . '<div class="pc-recommendation__featured">' . $featured_card . $notice . '</div></div>' . ( '' !== $grid ? '<div class="pc-merch-grid pc-recommendation__grid">' . $grid . '</div>' : '' ) . '</div>';
	}

	private static function collection_list( array $module, string $context ): string {
		$s = $module['settings']; $preview = in_array( $context, array( 'editor', 'preview' ), true ); $out = '';
		foreach ( self::rows( $module['items'] ) as $row ) {
			$values = $row['settings']; $term = get_term( (int) ( $values['category'] ?? 0 ), 'product_cat' );
			if ( ! $term instanceof \WP_Term ) { if ( $preview ) { $out .= '<div class="pc-collections__item pc-collections__placeholder" data-module-item="' . esc_attr( $row['id'] ) . '"><span class="pc-collections__picture"></span><span>' . esc_html__( 'Choose collection', 'peptide-core' ) . '</span></div>'; } continue; }
			$url = get_term_link( $term ); if ( is_wp_error( $url ) ) { continue; }
			$label = trim( (string) ( $values['title'] ?? '' ) ); if ( '' === $label ) { $label = $term->name; }
			$image = wp_get_attachment_image( (int) get_term_meta( $term->term_id, 'thumbnail_id', true ), 'woocommerce_thumbnail', false, array( 'loading' => 'lazy', 'alt' => '' ) );
			$out .= '<a class="pc-collections__item" data-module-item="' . esc_attr( $row['id'] ) . '" href="' . esc_url( $url ) . '"><span class="pc-collections__picture">' . $image . '</span><span class="pc-collections__name">' . esc_html( $label ) . '</span></a>';
		}
		if ( '' === $out ) { return $preview ? '<div class="pc-cb vc-m__inner"><p>' . esc_html__( 'Add real collections to preview this list.', 'peptide-core' ) . '</p></div>' : ''; }
		$cols = $s['collectionColumns'] ?? array( 'desktop' => 6, 'mobile' => 3 ); $color = $s['nameColor'] ?? '#000000';
		if ( ! is_string( $color ) || ! preg_match( '/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/iD', $color ) ) { $color = '#000000'; }
		$style = '--pc-collections-cols:' . max( 1, min( 8, (int) $cols['desktop'] ) ) . ';--pc-collections-cols-m:' . max( 1, min( 8, (int) ( $cols['mobile'] ?? $cols['desktop'] ) ) ) . ';--pc-collections-name:' . $color;
		$description = self::merchandise_description( $s );
		$head = ( '' !== ( $s['title'] ?? '' ) ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-collections__description">' . $description . '</div>' : '' );
		return '<div class="pc-cb vc-m__inner pc-collections" data-pc-collection-list style="' . esc_attr( $style ) . '">' . ( '' !== $head ? '<header class="pc-collections__head">' . $head . '</header>' : '' ) . '<div class="pc-collections__list" data-pc-collection-rail>' . $out . '</div><nav class="pc-collections__dots" data-pc-collection-dots aria-label="' . esc_attr__( 'Collection positions', 'peptide-core' ) . '" hidden></nav></div>';
	}

	private static function history_cookie( array $ids ): string {
		$now = time(); $data = array( 'v' => 1, 'iat' => $now, 'exp' => $now + MONTH_IN_SECONDS, 'ids' => $ids );
		$encoded = rtrim( strtr( base64_encode( wp_json_encode( $data ) ), '+/', '-_' ), '=' );
		return $encoded . '.' . hash_hmac( 'sha256', 'pc-recent-history:v1:' . $encoded, wp_salt( 'auth' ) );
	}

	private static function history_ids(): array {
		$raw = $_COOKIE[ self::HISTORY_COOKIE ] ?? '';
		if ( ! is_string( $raw ) || strlen( $raw ) > 4096 || ! preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $raw, $parts ) || ! hash_equals( hash_hmac( 'sha256', 'pc-recent-history:v1:' . $parts[1], wp_salt( 'auth' ) ), $parts[2] ) ) { return array(); }
		$data = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ), true ) ?: '', true );
		if ( ! is_array( $data ) || array_keys( $data ) !== array( 'v', 'iat', 'exp', 'ids' ) || 1 !== $data['v'] || ! is_int( $data['iat'] ) || ! is_int( $data['exp'] ) || $data['exp'] !== $data['iat'] + MONTH_IN_SECONDS || $data['exp'] < time() || $data['iat'] > time() + 60 || ! is_array( $data['ids'] ) || count( $data['ids'] ) > 100 || array_values( $data['ids'] ) !== $data['ids'] ) { return array(); }
		foreach ( $data['ids'] as $id ) { if ( ! is_int( $id ) || $id < 1 ) { return array(); } }
		return count( array_unique( $data['ids'], SORT_NUMERIC ) ) === count( $data['ids'] ) ? $data['ids'] : array();
	}

	/** Cookie IDs are history hints, never permission to expose withheld products. */
	private static function history_products( array $ids, int $total ): array {
		if ( ! $ids ) { return array(); }
		$ids = array_slice( $ids, 0, 100 );
		global $wpdb; $queries = (int) ( $wpdb->num_queries ?? 0 );
		$suppress = is_object( $wpdb ) && method_exists( $wpdb, 'suppress_errors' ); $previous = $suppress ? $wpdb->suppress_errors( true ) : null;
		try {
			$products = wc_get_products( array( 'include' => $ids, 'status' => 'publish', 'visibility' => 'visible', 'has_password' => false, 'limit' => count( $ids ), 'orderby' => 'include', 'return' => 'objects', 'cache_results' => false ) );
			if ( ! is_array( $products ) || ( (int) ( $wpdb->num_queries ?? 0 ) > $queries && '' !== (string) ( $wpdb->last_error ?? '' ) ) ) { throw new \RuntimeException( 'Recent products are unavailable.' ); }
		} finally { if ( $suppress ) { $wpdb->suppress_errors( $previous ); } }
		$by_id = array(); foreach ( $products as $product ) { if ( Products::public_catalog_product( $product ) ) { $by_id[ $product->get_id() ] = $product; } }
		$ordered = array(); foreach ( $ids as $id ) { if ( isset( $by_id[ $id ] ) ) { $ordered[] = $by_id[ $id ]; if ( count( $ordered ) >= max( 1, min( 100, $total ) ) ) { break; } } }
		return $ordered;
	}

	/** Pre-6.9 compatibility: observe the final readable PHP template without replacing it. */
	public static function record_recent_history_template( $template ) {
		$path = is_string( $template ) && '' !== $template ? realpath( $template ) : false;
		if ( is_string( $path ) && str_ends_with( $path, '.php' ) && is_file( $path ) && is_readable( $path ) ) { self::record_recent_history(); }
		return $template;
	}

	/** A separate signed history preserves the original 24-item cookie and functions byte-for-byte. */
	public static function record_recent_history(): void {
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || is_admin() || is_preview() || Renderer::$in_editor || ! function_exists( 'is_product' ) || ! is_product() || Templates::previewing_id() || headers_sent() || ! apply_filters( 'peptide_core_recent_history_enabled', true ) || ( \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) ) { return; }
		$product = wc_get_product( get_queried_object_id() ); if ( ! Products::public_catalog_product( $product ) ) { return; }
		$ids = self::history_ids(); array_unshift( $ids, $product->get_id() ); $ids = array_slice( array_values( array_unique( $ids ) ), 0, 100 );
		try { $products = self::history_products( $ids, 100 ); } catch ( \Throwable $error ) { return; }
		$ids = array_map( static fn( $item ) => $item->get_id(), $products );
		$expiry = time() + MONTH_IN_SECONDS; $value = self::history_cookie( $ids );
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0, must-revalidate', true ); header( 'Vary: Cookie', false );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		$options = static function ( $options, $name ) { if ( self::HISTORY_COOKIE === $name ) { $options['samesite'] = 'Lax'; $options['httponly'] = true; } return $options; };
		add_filter( 'woocommerce_set_cookie_options', $options, 10, 2 );
		try { wc_setcookie( self::HISTORY_COOKIE, $value, $expiry, is_ssl(), true ); }
		finally { remove_filter( 'woocommerce_set_cookie_options', $options, 10 ); }
	}

	/** Read only this visitor's cookie. Public cached pages never contain personal product HTML. */
	public static function recent_history_more(): void {
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0, must-revalidate', true ); header( 'Vary: Cookie', false );
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { header( 'Allow: GET' ); wp_send_json_error( array( 'code' => 'invalid_method' ), 405 ); }
		if ( \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { wp_send_json_error( array( 'code' => 'login_required' ), 401 ); }
		$total = $_GET['total'] ?? '';
		if ( array_diff( array_keys( $_GET ), array( 'action', 'total' ) ) || ! is_string( $total ) || ! preg_match( '/^(?:[1-9][0-9]?|100)$/D', $total ) ) { wp_send_json_error( array( 'code' => 'invalid_request' ), 400 ); }
		$cards = array();
		try {
			$products = apply_filters( 'peptide_core_recent_history_enabled', true ) ? self::history_products( self::history_ids(), (int) $total ) : array();
			foreach ( $products as $product ) { $card = self::merchandise_card( $product, true ); if ( '' !== $card ) { $cards[] = $card; } }
		} catch ( \Throwable $error ) { wp_send_json_error( array( 'code' => 'load_failed' ), 503 ); }
		wp_send_json_success( array( 'html' => implode( '', $cards ), 'count' => count( $cards ) ) );
	}

	private static function recent_history( array $module, string $context ): string {
		$s = $module['settings']; $preview = in_array( $context, array( 'editor', 'preview' ), true );
		$title = (string) ( $s['title'] ?? '' ); $description = self::merchandise_description( $s );
		$head = ( '' !== $title ? '<h2>' . esc_html( $title ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-recent-history__description">' . $description . '</div>' : '' );
		$total = max( 1, min( 100, (int) ( $s['recentTotal'] ?? 8 ) ) );
		$rows = '';
		if ( $preview ) { foreach ( range( 1, $total ) as $index ) { $rows .= '<div class="pc-recent-history__placeholder" aria-hidden="true"><span class="pc-recent-history__placeholder-image"></span><span>' . esc_html__( 'Product preview', 'peptide-core' ) . '</span></div>'; } }
		else { Showcase_Quick_View::catalog_assets(); }
		return '<div class="pc-cb vc-m__inner pc-recent-history' . ( ! empty( $s['fullWidth'] ) ? ' is-full-width' : '' ) . '"' . ( $preview ? '' : ' data-pc-recent-history data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-total="' . $total . '" hidden' ) . ' style="' . esc_attr( self::merchandise_columns( $s ) ) . '">' . ( '' !== $head ? '<header class="pc-recent-history__head">' . $head . '</header>' : '' ) . ( $preview ? '<p class="pc-recent-history__preview-note">' . esc_html__( 'Preview placeholders only. Buyers see their own browser history; without visits or JavaScript this block stays hidden.', 'peptide-core' ) . '</p>' : '' ) . '<div class="pc-merch-grid" data-pc-recent-list>' . $rows . '</div></div>';
	}

	/** Stateless public query context, not editor authority or a product-data snapshot. */
	private static function waterfall_token( int $category, int $per_page, int $batches, bool $quick ): string {
		$now = time();
		$data = array( 'v' => 1, 'iat' => $now, 'exp' => $now + 3600, 'category' => $category, 'perPage' => $per_page, 'batches' => $batches, 'quick' => $quick );
		$encoded = rtrim( strtr( base64_encode( wp_json_encode( $data ) ), '+/', '-_' ), '=' );
		return $encoded . '.' . hash_hmac( 'sha256', 'pc-product-waterfall:v1:' . $encoded, wp_salt( 'auth' ) );
	}

	private static function waterfall_context( string $token ): ?array {
		if ( strlen( $token ) > 2048 || ! preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $parts ) || ! hash_equals( hash_hmac( 'sha256', 'pc-product-waterfall:v1:' . $parts[1], wp_salt( 'auth' ) ), $parts[2] ) ) { return null; }
		$data = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ), true ) ?: '', true );
		if ( ! is_array( $data ) || array_keys( $data ) !== array( 'v', 'iat', 'exp', 'category', 'perPage', 'batches', 'quick' ) || 1 !== $data['v'] || ! is_bool( $data['quick'] ) ) { return null; }
		foreach ( array( 'iat', 'exp', 'category', 'perPage', 'batches' ) as $field ) { if ( ! is_int( $data[ $field ] ) ) { return null; } }
		if ( $data['exp'] !== $data['iat'] + 3600 || $data['exp'] < time() || $data['iat'] > time() + 60 || $data['category'] < 1 || $data['perPage'] < 1 || $data['perPage'] > 50 || $data['batches'] < 1 || $data['batches'] > 20 ) { return null; }
		return $data;
	}

	/** Theme card, server price and the existing Quick View engine, without a second matcher/cart. */
	private static function waterfall_cards( array $products, bool $quick ): string {
		$out = '';
		foreach ( $products as $product ) {
			if ( ! Products::public_catalog_product( $product ) ) { continue; }
			$card = self::card( $product ); if ( '' === $card ) { continue; }
			$can_quick = $quick && Showcase_Quick_View::supports( $product );
			$action = $can_quick ? '<a class="pc-waterfall__quick" href="' . esc_url( $product->get_permalink() ) . '" data-vc-quick-open="' . (int) $product->get_id() . '">' . esc_html__( 'Quick view', 'peptide-core' ) . '</a>' : '';
			$out .= '<div class="pc-waterfall__item" data-pc-waterfall-product="' . (int) $product->get_id() . '">' . $card . $action . ( $can_quick ? Showcase_Quick_View::template( $product ) : '' ) . '</div>';
		}
		return $out;
	}

	/** Public GET, immutable signed collection scope, strict per-request caps and fresh qualification. */
	public static function waterfall_more(): void {
		nocache_headers();
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { header( 'Allow: GET' ); wp_send_json_error( array( 'code' => 'invalid_method' ), 405 ); }
		if ( \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { wp_send_json_error( array( 'code' => 'login_required' ), 401 ); }
		$token = $_GET['context'] ?? ''; $page = $_GET['page'] ?? '';
		$data = is_string( $token ) ? self::waterfall_context( wp_unslash( $token ) ) : null;
		if ( ! $data ) { wp_send_json_error( array( 'code' => 'invalid_context' ), 403 ); }
		if ( ! is_string( $page ) || ! preg_match( '/^[1-9][0-9]?$/D', $page ) || (int) $page < 2 || (int) $page > $data['batches'] ) { wp_send_json_error( array( 'code' => 'invalid_page' ), 400 ); }
		try {
			$result = Products::collection_page( $data['category'], (int) $page, $data['perPage'] );
			$html = self::waterfall_cards( $result['products'], $data['quick'] );
		} catch ( \Throwable $error ) { wp_send_json_error( array( 'code' => 'load_failed' ), 503 ); }
		wp_send_json_success( array( 'html' => $html, 'page' => (int) $page, 'hasMore' => $result['hasMore'] && (int) $page < $data['batches'] ) );
	}

	private static function waterfall( array $module, string $context, string $device ): string {
		$s = $module['settings']; $category = (int) ( $s['category'] ?? 0 );
		$preview = in_array( $context, array( 'editor', 'preview' ), true );
		$term = $category > 0 ? get_term( $category, 'product_cat' ) : null;
		if ( ! $term instanceof \WP_Term ) { return $preview ? '<div class="pc-cb vc-m__inner"><p>' . esc_html__( 'Choose a real product collection to show its public products.', 'peptide-core' ) . '</p></div>' : ''; }
		$per_page = max( 1, min( 50, (int) ( $s['batchSize'] ?? 10 ) ) ); $batches = max( 1, min( 20, (int) ( $s['loadBatches'] ?? 3 ) ) );
		try { $result = Products::collection_page( $category, 1, $per_page ); }
		catch ( \Throwable $error ) { return '<div class="pc-cb vc-m__inner"><p role="status">' . esc_html__( 'Products could not be loaded. Please refresh to try again.', 'peptide-core' ) . '</p></div>'; }
		$quick = self::interactive( $context );
		if ( $quick ) { Showcase_Quick_View::catalog_assets(); }
		$cards = self::waterfall_cards( $result['products'], $quick );
		if ( '' === $cards && ! $result['hasMore'] && ! $preview ) { return ''; }
		$notice = '' === $cards && $preview ? '<p class="pc-waterfall__empty">' . esc_html__( 'This collection has no eligible public products to display.', 'peptide-core' ) . '</p>' : '';
		$url = get_term_link( $term ); $url = is_wp_error( $url ) ? '' : (string) $url;
		$description = null !== ( $s['descriptionHtml'] ?? null ) ? wp_kses_post( $s['descriptionHtml'] ) : nl2br( esc_html( $s['description'] ?? '' ) );
		$heading = ( '' !== ( $s['title'] ?? '' ) ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-waterfall__description">' . $description . '</div>' : '' );
		$cols = $s['columns'] ?? array( 'desktop' => 4, 'mobile' => 2 );
		$style = '--pc-waterfall-cols:' . max( 1, min( 6, (int) $cols['desktop'] ) ) . ';--pc-waterfall-cols-m:' . max( 1, min( 6, (int) ( $cols['mobile'] ?? $cols['desktop'] ) ) );
		$more = '' !== $url && '' !== trim( (string) ( $s['viewMoreLabel'] ?? '' ) ) ? self::action_button( $s, $url, $s['viewMoreLabel'], $context ) : '';
		$has_more = $result['hasMore'] && $batches > 1;
		$token = self::waterfall_token( $category, $per_page, $batches, $quick );
		return '<div class="pc-cb vc-m__inner pc-waterfall' . ( ! empty( $s['fullWidth'] ) ? ' is-full-width' : '' ) . '" data-pc-waterfall data-context="' . esc_attr( $token ) . '" data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-has-more="' . ( $has_more ? '1' : '0' ) . '" data-device="' . esc_attr( $device ) . '" style="' . esc_attr( $style ) . '">' . ( $heading ? '<header class="pc-waterfall__head">' . $heading . '</header>' : '' ) . $notice . '<div class="pc-waterfall__list" data-pc-waterfall-list>' . $cards . '</div><div class="pc-waterfall__sentinel" data-pc-waterfall-sentinel aria-hidden="true"></div><p class="pc-waterfall__status" data-pc-waterfall-status role="status" aria-live="polite"></p><button class="pc-waterfall__retry" type="button" data-pc-waterfall-retry hidden>' . esc_html__( 'Try loading products again', 'peptide-core' ) . '</button><div class="pc-waterfall__more" data-pc-waterfall-more>' . $more . '</div></div>';
	}

	/** P07 uses the current server price renderer and the existing bounded catalog query. */
	private static function collection_scroll( array $module, string $context ): string {
		$s = $module['settings']; $preview = in_array( $context, array( 'editor', 'preview' ), true );
		if ( ! $preview && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return ''; }
		$category = (int) ( $s['category'] ?? 0 ); $term = $category > 0 ? get_term( $category, 'product_cat' ) : null;
		if ( ! $term instanceof \WP_Term ) { return $preview ? '<div class="pc-cb vc-m__inner"><p>' . esc_html__( 'Choose a real collection to preview its scrolling products.', 'peptide-core' ) . '</p></div>' : ''; }
		$limit = max( 1, min( 100, (int) ( $s['scrollTotal'] ?? 4 ) ) );
		try {
			$products = Products::collection_page( $category, 1, min( 50, $limit ) )['products'];
			if ( $limit > 50 ) { $products = array_merge( $products, Products::collection_page( $category, 2, 50 )['products'] ); }
			$products = array_slice( $products, 0, $limit );
		} catch ( \Throwable $error ) { return '<div class="pc-cb vc-m__inner"><p role="status">' . esc_html__( 'Collection products could not be loaded. Please refresh to try again.', 'peptide-core' ) . '</p></div>'; }
		$cards = ''; $seen = array();
		foreach ( $products as $product ) {
			if ( ! Products::public_catalog_product( $product ) || isset( $seen[ $product->get_id() ] ) ) { continue; } $seen[ $product->get_id() ] = true;
			$price = function_exists( 'vc_card_price_html' ) ? vc_card_price_html( $product ) : $product->get_price_html();
			$cards .= '<li class="pc-product-scroll__item" data-pc-scroll-product="' . (int) $product->get_id() . '"><a href="' . esc_url( $product->get_permalink() ) . '"><span class="pc-product-scroll__image">' . wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ) . '</span><span class="pc-product-scroll__copy"><span class="pc-product-scroll__name">' . esc_html( $product->get_name() ) . '</span><span class="pc-product-scroll__price">' . wp_kses_post( $price ) . '</span></span></a></li>';
		}
		if ( '' === $cards ) { return $preview ? '<div class="pc-cb vc-m__inner"><p>' . esc_html__( 'This collection has no eligible public products.', 'peptide-core' ) . '</p></div>' : ''; }
		$images = $s['scrollImageSize'] ?? array( 'desktop' => 192, 'mobile' => 128 );
		$desktop = max( 0, min( 500, (int) ( $images['desktop'] ?? 192 ) ) ); $phone = max( 0, min( 500, (int) ( $images['mobile'] ?? $desktop ) ) );
		$style = '--pc-scroll-image:' . $desktop . 'px;--pc-scroll-image-m:' . $phone . 'px;--pc-scroll-duration:' . max( 1, min( 120, (int) ( $s['scrollSeconds'] ?? 30 ) ) ) . 's';
		$description = self::merchandise_description( $s );
		$head = ( '' !== ( $s['title'] ?? '' ) ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-product-scroll__description">' . $description . '</div>' : '' );
		$url = get_term_link( $term ); $label = trim( (string) ( $s['collectionButton'] ?? '' ) );
		$action = ! is_wp_error( $url ) && '' !== $label ? self::action_button( $s, (string) $url, $label, $context ) : '';
		$viewport = wp_unique_id( 'pc-product-scroll-' );
		return '<div class="pc-cb vc-m__inner pc-product-scroll' . ( ! empty( $s['fullWidth'] ) ? ' is-full-width' : '' ) . ( 0 === $desktop ? ' no-image-desktop' : '' ) . ( 0 === $phone ? ' no-image-phone' : '' ) . '" data-pc-product-scroll data-pause-label="' . esc_attr__( 'Pause scrolling', 'peptide-core' ) . '" data-resume-label="' . esc_attr__( 'Resume scrolling', 'peptide-core' ) . '" data-editor="' . ( $preview ? '1' : '0' ) . '" style="' . esc_attr( $style ) . '">' . ( '' !== $head ? '<header class="pc-product-scroll__head">' . $head . '</header>' : '' ) . '<div class="pc-product-scroll__viewport" id="' . esc_attr( $viewport ) . '" tabindex="0" aria-label="' . esc_attr__( 'Collection products', 'peptide-core' ) . '"><div class="pc-product-scroll__track"><ul class="pc-product-scroll__group">' . $cards . '</ul></div></div><button class="pc-product-scroll__toggle" type="button" hidden aria-pressed="false" aria-controls="' . esc_attr( $viewport ) . '">' . esc_html__( 'Pause scrolling', 'peptide-core' ) . '</button>' . $action . '</div>';
	}

	/** P08 never substitutes all categories when an explicit binding disappears. */
	private static function collection_navigation( array $module, string $context ): string {
		$s = $module['settings']; $preview = in_array( $context, array( 'editor', 'preview' ), true ); $cards = '';
		foreach ( self::rows( $module['items'] ) as $row ) {
			$values = $row['settings']; $category = (int) ( $values['category'] ?? 0 ); $term = $category > 0 ? get_term( $category, 'product_cat' ) : null;
			if ( ! $term instanceof \WP_Term ) { if ( $preview ) { $cards .= '<article class="pc-collection-navigation__item pc-collection-navigation__placeholder" data-module-item="' . esc_attr( $row['id'] ) . '"><p>' . esc_html__( 'Choose collection', 'peptide-core' ) . '</p></article>'; } continue; }
			$url = get_term_link( $term ); if ( is_wp_error( $url ) ) { continue; }
			$background = $values['navigationBackground'] ?? ''; if ( ! is_string( $background ) || ! preg_match( '/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/iD', $background ) ) { $background = 'transparent'; }
			$image = wp_get_attachment_image( (int) get_term_meta( $term->term_id, 'thumbnail_id', true ), 'large', false, array( 'loading' => 'lazy', 'alt' => '' ) );
			$links = '';
			foreach ( self::rows( (array) ( $values['links'] ?? array() ) ) as $link ) {
				$title = trim( (string) ( $link['settings']['title'] ?? '' ) ); $href = Fields::url( (string) ( $link['settings']['href'] ?? '' ) );
				if ( '' !== $title && '' !== $href ) { $links .= '<dd data-module-item="' . esc_attr( $link['id'] ) . '"><a href="' . esc_url( $href ) . '">' . esc_html( $title ) . '</a></dd>'; }
				elseif ( $preview ) { $links .= '<dd data-module-item="' . esc_attr( $link['id'] ) . '"><span>' . esc_html__( 'Add a link title and destination', 'peptide-core' ) . '</span></dd>'; }
			}
			$cards .= '<article class="pc-collection-navigation__item" data-module-item="' . esc_attr( $row['id'] ) . '" style="background:' . esc_attr( $background ) . '"><a class="pc-collection-navigation__image" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $term->name ) . '">' . $image . '</a><dl><dt><a href="' . esc_url( $url ) . '">' . esc_html( $term->name ) . '</a></dt>' . $links . '</dl></article>';
		}
		if ( '' === $cards ) { return $preview ? '<div class="pc-cb vc-m__inner"><p>' . esc_html__( 'Add real collections to preview this navigation.', 'peptide-core' ) . '</p></div>' : ''; }
		$description = self::merchandise_description( $s );
		$head = ( '' !== ( $s['title'] ?? '' ) ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-collection-navigation__description">' . $description . '</div>' : '' );
		$label = trim( (string) ( $s['collectionsMore'] ?? 'View More' ) );
		$url = \PeptideCore\Design_Pages::sample_url( 'collections' );
		$action = '' !== $label && '' !== $url ? self::action_button( $s, $url, $label, $context ) : '';
		return '<div class="pc-cb vc-m__inner pc-collection-navigation" data-pc-collection-navigation>' . ( '' !== $head ? '<header class="pc-collection-navigation__head">' . $head . '</header>' : '' ) . '<div class="pc-collection-navigation__list">' . $cards . '</div>' . $action . '</div>';
	}

	/** O01 manual pricing cards are distinct from the existing Woo attribute comparison table. */
	private static function pricing_cards_definition(): array {
		$settings = array(
			self::field( 'text', 'title', '标题', 'Pricing options', array( 'panel' => 'content' ) ),
			self::field( 'textarea', 'description', '原简述', '', array( 'uiHidden' => true ) ),
			self::field( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/pricing-cards', 'panel' => 'content' ) ),
			self::field( 'number', 'desktopColumns', 'PC每排数量', 4, array( 'min' => 3, 'max' => 5, 'ui' => 'range', 'panel' => 'layout' ) ),
			self::field( 'select', 'mobileStyle', '移动端样式', 'scroll', array( 'panel' => 'layout', 'options' => array( 'scroll' => '横向滑动', 'single' => '竖向平铺（一排一个）', 'double' => '竖向平铺（一排两个）' ) ) ),
			self::field( 'toggle', 'loadAnimation', '载入动画', true, array( 'panel' => 'layout' ) ),
			self::field( 'select', 'featureIcon', '图标', 'check', array( 'panel' => 'content', 'options' => array( 'check' => '勾', 'dot' => '圆点', 'thumb' => '赞', 'square' => '方块' ) ) ),
			self::field( 'text', 'buttonLabel', '主要按钮文案', 'Order Now', array( 'panel' => 'content' ) ),
			self::field( 'number', 'recommendedPosition', '推荐选项卡位置', 3, array( 'min' => 0, 'max' => 30, 'panel' => 'recommend', 'help' => '按启用的价格卡顺序，从1开始；0或超出数量时不显示推荐标记。推荐只改变展示。' ) ),
			self::field( 'text', 'recommendedLabel', '推荐文案', 'Popular', array( 'panel' => 'recommend' ) ),
			self::field( 'number', 'selectedPosition', '默认选中选项卡位置', 1, array( 'min' => 0, 'max' => 30, 'panel' => 'selection', 'help' => '从1开始；0或超出数量时不预选。选择仅改变外观，不加购、不更改数量。' ) ),
		);
		$colors = array(
			'headerBackground' => array( '栏目标题背景', '#f3f3f3', 'style' ), 'headerText' => array( '栏目标题文字', '#171717', 'style' ),
			'contentBackground' => array( '内容背景', '#ffffff', 'style' ), 'contentText' => array( '内容文字', '#404040', 'style' ),
			'priceColor' => array( '现价', '#171717', 'style' ), 'comparePriceColor' => array( '原价', '#737373', 'style' ),
			'primaryBackground' => array( '主要按钮背景', '#171717', 'style' ), 'primaryText' => array( '主要按钮文字', '#ffffff', 'style' ),
			'secondaryBackground' => array( '次要按钮背景', '#ffffff', 'style' ), 'secondaryText' => array( '次要按钮文字与边框', '#171717', 'style' ),
			'recommendedBackground' => array( '推荐标记背景', '#171717', 'recommend' ), 'recommendedText' => array( '推荐标记文字', '#ffffff', 'recommend' ),
			'selectedHeaderBackground' => array( '选中栏目标题背景', '#171717', 'selection' ), 'selectedHeaderText' => array( '选中栏目标题文字', '#ffffff', 'selection' ), 'selectedContentBackground' => array( '选中内容背景', '#fafafa', 'selection' ),
		);
		foreach ( $colors as $key => $color ) { $settings[] = self::field( 'color', $key, $color[0], $color[1], array( 'panel' => $color[2] ) ); }
		$fields = array(
			self::field( 'image', 'image', '图片', null, array( 'help' => '建议112×112px；可不设置。' ) ),
			self::field( 'text', 'title', '主标题', '' ), self::field( 'text', 'subtitle', '原副标题', '', array( 'uiHidden' => true ) ),
			self::field( 'html', 'subtitleHtml', '副标题', null, array( 'ui' => 'richtext', 'legacyKey' => 'subtitle', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/pricing-cards', 'richMediaItem' => true ) ),
			self::field( 'text', 'price', '售价（展示）', '', array( 'help' => '仅普通链接模式使用。绑定商品规格时，前台改用Woo当前真实价格，手填值保留。' ) ),
			self::field( 'text', 'comparePrice', '原价（展示）', '', array( 'help' => '仅普通链接模式使用；不会修改商品售价。' ) ),
			self::field( 'text', 'unit', '单位', '', array( 'help' => '仅展示文案，不会创建订阅或自动续费。' ) ),
			self::field( 'textarea', 'features', '特点描述', '', array( 'help' => '每行一项，按换行显示。' ) ),
			self::field( 'select', 'actionType', '主要按钮行为', 'link', array( 'options' => array( 'link' => '普通链接', 'variation' => '立即购买指定商品规格' ) ) ),
			self::field( 'url', 'href', '主要按钮跳转链接', '', array( 'ui' => 'site-link', 'show_if' => array( 'actionType' => 'link' ) ) ),
			self::field( 'record', 'variation', '主要商品规格', null, array( 'resource' => 'product_variation', 'show_if' => array( 'actionType' => 'variation' ), 'help' => '仅可选择公开、可售、有库存且属性确定的规格；每次购买1件。以Woo实时价格、库存和结账校验为准。' ) ),
			self::field( 'text', 'secondaryLabel', '次要按钮文案', '' ),
			self::field( 'select', 'secondaryActionType', '次要按钮行为', 'link', array( 'options' => array( 'link' => '普通链接', 'variation' => '立即购买指定商品规格' ) ) ),
			self::field( 'url', 'secondaryHref', '次要按钮跳转链接', '', array( 'ui' => 'site-link', 'show_if' => array( 'secondaryActionType' => 'link' ) ) ),
			self::field( 'record', 'secondaryVariation', '次要商品规格', null, array( 'resource' => 'product_variation', 'show_if' => array( 'secondaryActionType' => 'variation' ), 'help' => '每次购买1件。主、次规格不同时，会在次按钮旁另列其真实规格名和当前价格。只有次按钮绑定时，卡面也使用该规格真实价格。' ) ),
		);
		$items = self::items( $fields, 30 ); $items['label'] = '价格卡'; $items['default_count'] = 4;
		$items['panels'] = array(
			array( 'id' => 'card', 'label' => '图片与文案', 'initialOpen' => true, 'fields' => array( 'image', 'title', 'subtitleHtml', 'price', 'comparePrice', 'unit', 'features' ) ),
			array( 'id' => 'actions', 'label' => '按钮与商品关联', 'initialOpen' => true, 'fields' => array( 'actionType', 'href', 'variation', 'secondaryLabel', 'secondaryActionType', 'secondaryHref', 'secondaryVariation' ) ),
		);
		return array( 'title' => '价格对比', 'category' => 'operation', 'icon' => 'products', 'settings' => $settings, 'items' => $items,
			'panels' => array(
				array( 'id' => 'content', 'label' => '标题与内容', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml', 'featureIcon', 'buttonLabel' ) ),
				array( 'id' => 'layout', 'label' => '排列与载入', 'initialOpen' => true, 'fields' => array( 'desktopColumns', 'mobileStyle', 'loadAnimation' ) ),
				array( 'id' => 'style', 'label' => '颜色设置', 'initialOpen' => false, 'fields' => array( 'headerBackground', 'headerText', 'contentBackground', 'contentText', 'priceColor', 'comparePriceColor', 'primaryBackground', 'primaryText', 'secondaryBackground', 'secondaryText' ) ),
				array( 'id' => 'recommend', 'label' => '推荐选项卡设置', 'initialOpen' => false, 'fields' => array( 'recommendedPosition', 'recommendedLabel', 'recommendedBackground', 'recommendedText' ) ),
				array( 'id' => 'selection', 'label' => '默认选中选项卡设置', 'initialOpen' => false, 'fields' => array( 'selectedPosition', 'selectedHeaderText', 'selectedHeaderBackground', 'selectedContentBackground' ) ),
			),
		);
	}


	private static function pricing_feature_icon( string $kind ): string {
		$paths = array( 'check' => '<path d="m5 12 4 4L19 6"/>', 'dot' => '<circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/>', 'square' => '<rect x="8" y="8" width="8" height="8" rx="1" fill="currentColor" stroke="none"/>', 'thumb' => '<path d="M8 10h-4v10h4m0-10 4-7c2 0 2 2 1 6h5c2 0 2 2 1 4l-2 7H8V10Z"/>' );
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $kind ] ?? $paths['check'] ) . '</svg>';
	}

	/** Display-only text is never written to a Woo product or a cart payload. */
	private static function pricing_manual_price( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) { return ''; }
		return preg_match( '/^[0-9]{1,10}(?:\.[0-9]{1,4})?$/D', $value ) && function_exists( 'wc_price' ) ? wp_kses_post( wc_price( (float) $value ) ) : esc_html( $value );
	}

	/** An unavailable fixed choice may only fall back to its independently public parent PDP. */
	private static function pricing_variation_fallback( $id ): string {
		if ( ! $id || ! function_exists( 'wc_get_product' ) ) { return ''; }
		$variation = wc_get_product( (int) $id );
		if ( ! $variation instanceof \WC_Product_Variation ) { return ''; }
		$parent = wc_get_product( $variation->get_parent_id() );
		return Products::public_catalog_product( $parent ) && $parent->is_type( 'variable' ) ? $parent->get_permalink() : '';
	}

	private static function pricing_action( array $row, string $label, bool $secondary, string $context, string $instance ): string {
		if ( '' === trim( $label ) ) { return ''; }
		$kind = $row[ $secondary ? 'secondaryActionType' : 'actionType' ] ?? 'link';
		$class = 'pc-pricing__' . ( $secondary ? 'secondary' : 'primary' );
		if ( 'variation' === $kind ) {
			$id = $row[ $secondary ? 'secondaryVariation' : 'variation' ] ?? null;
			$variation = Showcase_Quick_View::fixed_variation_product( $id );
			if ( $variation && self::interactive( $context ) ) { $purchase = Showcase_Quick_View::inline_variation_purchase( $variation, $instance, $label ); if ( '' !== $purchase ) { return $secondary ? '<div class="pc-pricing__secondary-purchase">' . $purchase . '</div>' : $purchase; } }
			if ( ! self::interactive( $context ) ) { return '<button type="button" class="' . $class . '" disabled title="' . esc_attr__( 'Purchases are disabled in design previews.', 'peptide-core' ) . '">' . esc_html( $label ) . '</button>'; }
			$url = self::pricing_variation_fallback( $id );
			return '' !== $url ? '<a class="' . $class . '" href="' . esc_url( $url ) . '">' . esc_html__( 'View available options', 'peptide-core' ) . '</a>' : '';
		}
		$url = (string) ( $row[ $secondary ? 'secondaryHref' : 'href' ] ?? '' );
		if ( ! self::interactive( $context ) ) { return '<button type="button" class="' . $class . '" disabled>' . esc_html( $label ) . '</button>'; }
		return '' !== $url ? '<a class="' . $class . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : '';
	}

	/** Real Woo prices take precedence whenever either action is configured as a fixed purchase. */
	private static function pricing_cards( array $module, string $context, string $device ): string {
		$s = $module['settings']; $preview = in_array( $context, array( 'editor', 'preview' ), true );
		if ( ! $preview && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return ''; }
		$cards = ''; $count = 0; $selected = max( 0, min( 30, (int) ( $s['selectedPosition'] ?? 1 ) ) );
		$recommended = max( 0, min( 30, (int) ( $s['recommendedPosition'] ?? 3 ) ) );
		foreach ( self::rows( $module['items'] ) as $index => $row ) {
			$r = $row['settings']; $position = $index + 1;
			$primary = 'variation' === ( $r['actionType'] ?? 'link' ) ? Showcase_Quick_View::fixed_variation_product( $r['variation'] ?? null ) : null;
			$secondary = 'variation' === ( $r['secondaryActionType'] ?? 'link' ) ? Showcase_Quick_View::fixed_variation_product( $r['secondaryVariation'] ?? null ) : null;
			$priced = $primary ?: $secondary;
			$bound = 'variation' === ( $r['actionType'] ?? 'link' ) || 'variation' === ( $r['secondaryActionType'] ?? 'link' );
			$price = $priced ? wp_kses_post( function_exists( 'vc_public_price_html' ) ? vc_public_price_html( $priced ) : $priced->get_price_html() ) : ( $bound ? '' : self::pricing_manual_price( (string) ( $r['price'] ?? '' ) ) );
			$old = $bound ? '' : self::pricing_manual_price( (string) ( $r['comparePrice'] ?? '' ) );
			$image = self::image( $r['image'] ?? null, 'thumbnail' );
			$features = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) ( $r['features'] ?? '' ) ) ), static fn( $line ) => '' !== $line ) );
			$instance = 'pricing-' . $module['id'] . '-' . $row['id'] . '-' . $device;
			$action = self::pricing_action( $r, (string) ( $s['buttonLabel'] ?? 'Order Now' ), false, $context, $instance . '-primary' );
			$second_action = self::pricing_action( $r, (string) ( $r['secondaryLabel'] ?? '' ), true, $context, $instance . '-secondary' );
			$title = trim( (string) ( $r['title'] ?? '' ) ); $subtitle = null !== ( $r['subtitleHtml'] ?? null ) ? wp_kses_post( $r['subtitleHtml'] ) : esc_html( trim( (string) ( $r['subtitle'] ?? '' ) ) ); $unit = trim( (string) ( $r['unit'] ?? '' ) );
			if ( '' === trim( wp_strip_all_tags( $subtitle ) ) && ! preg_match( '/<img\b/i', $subtitle ) ) { $subtitle = ''; }
			if ( ! $preview && '' === $title && '' === $subtitle && '' === $unit && '' === $price && '' === $old && '' === $image && ! $features && '' === $action && '' === $second_action ) { continue; }
			$count++; $is_selected = $position === $selected;
			$heading = '' !== $title ? $title : ( $priced ? $priced->get_name() : ( $preview ? __( 'Add option title', 'peptide-core' ) : sprintf( __( 'Option %d', 'peptide-core' ), $position ) ) );
			$badge = $position === $recommended && '' !== trim( (string) ( $s['recommendedLabel'] ?? '' ) ) ? '<span class="pc-pricing__recommend" title="' . esc_attr( $s['recommendedLabel'] ) . '"><span>' . esc_html( $s['recommendedLabel'] ) . '</span></span>' : '';
			$cards .= '<article class="pc-pricing__card' . ( $is_selected ? ' is-selected' : '' ) . '" data-pc-pricing-item="' . $position . '" data-module-item="' . esc_attr( $row['id'] ) . '"><header class="pc-pricing__card-head"><h3><button type="button" data-pc-pricing-select aria-pressed="' . ( $is_selected ? 'true' : 'false' ) . '">' . esc_html( $heading ) . '</button></h3></header><div class="pc-pricing__body">' . $badge;
			if ( '' !== $image ) { $cards .= '<div class="pc-pricing__image">' . $image . '</div>'; }
			if ( '' !== $subtitle ) { $cards .= '<div class="pc-pricing__subtitle">' . $subtitle . '</div>'; }
			if ( $priced ) { $cards .= '<p class="pc-pricing__product">' . esc_html( $priced->get_name() ) . '</p>'; }
			if ( '' !== $price || '' !== $old ) { $cards .= '<div class="pc-pricing__price" data-price-source="' . ( $priced ? 'woocommerce' : 'manual' ) . '">' . ( '' !== $price ? '<span class="pc-pricing__current">' . $price . '</span>' : '' ) . ( '' !== $old ? '<del class="pc-pricing__compare">' . $old . '</del>' : '' ) . '</div>'; }
			elseif ( $preview ) { $cards .= '<p class="pc-pricing__placeholder">' . esc_html__( 'Add a display price or bind a product variation.', 'peptide-core' ) . '</p>'; }
			if ( '' !== $unit ) { $cards .= '<p class="pc-pricing__unit">' . esc_html( $unit ) . '</p>'; }
			if ( $features ) { $cards .= '<ul class="pc-pricing__features">'; foreach ( $features as $line ) { $cards .= '<li>' . self::pricing_feature_icon( (string) ( $s['featureIcon'] ?? 'check' ) ) . '<span>' . esc_html( $line ) . '</span></li>'; } $cards .= '</ul>'; }
			elseif ( $preview ) { $cards .= '<p class="pc-pricing__placeholder">' . esc_html__( 'Add selling points, one per line.', 'peptide-core' ) . '</p>'; }
			if ( $preview && $bound && ! $priced ) { $cards .= '<p class="pc-pricing__notice" role="status">' . esc_html__( 'Choose a public, available fixed product variation. Purchases stay disabled in previews.', 'peptide-core' ) . '</p>'; }
			$cards .= '<div class="pc-pricing__actions">' . $action;
			if ( $secondary && $primary && $secondary->get_id() !== $primary->get_id() && '' !== $second_action ) { $cards .= '<p class="pc-pricing__alternate">' . esc_html( $secondary->get_name() ) . '<span>' . wp_kses_post( function_exists( 'vc_public_price_html' ) ? vc_public_price_html( $secondary ) : $secondary->get_price_html() ) . '</span></p>'; }
			$cards .= $second_action . '</div></div></article>';
		}
		if ( '' === $cards ) { return $preview ? '<div class="pc-cb vc-m__inner"><p>' . esc_html__( 'Add pricing options to preview this block.', 'peptide-core' ) . '</p></div>' : ''; }
		$description = self::merchandise_description( $s );
		$head = ( '' !== ( $s['title'] ?? '' ) ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-pricing__description">' . $description . '</div>' : '' );
		$columns = max( 3, min( 5, (int) ( $s['desktopColumns'] ?? 4 ) ) ); $phone = in_array( $s['mobileStyle'] ?? '', array( 'scroll', 'single', 'double' ), true ) ? $s['mobileStyle'] : 'scroll';
		$style = '--pc-pricing-cols:' . $columns;
		foreach ( array( 'headerBackground', 'headerText', 'contentBackground', 'contentText', 'priceColor', 'comparePriceColor', 'primaryBackground', 'primaryText', 'secondaryBackground', 'secondaryText', 'recommendedBackground', 'recommendedText', 'selectedHeaderBackground', 'selectedHeaderText', 'selectedContentBackground' ) as $key ) { $color = sanitize_hex_color( (string) ( $s[ $key ] ?? '' ) ); if ( $color ) { $style .= ';--pc-pricing-' . strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $key ) ) . ':' . $color; } }
		$dots = ''; for ( $i = 1; $i <= $count; $i++ ) { $dots .= '<button type="button" data-pc-pricing-dot="' . $i . '" aria-label="' . esc_attr( sprintf( __( 'Show option %d', 'peptide-core' ), $i ) ) . '"></button>'; }
		return '<div class="pc-cb vc-m__inner pc-pricing" data-pc-pricing data-mobile-style="' . $phone . '" data-load-animation="' . ( ! empty( $s['loadAnimation'] ) ? '1' : '0' ) . '" style="' . esc_attr( $style ) . '">' . ( '' !== $head ? '<header class="pc-pricing__head">' . $head . '</header>' : '' ) . '<div class="pc-pricing__rail" data-pc-pricing-rail>' . $cards . '</div><nav class="pc-pricing__dots" data-pc-pricing-dots aria-label="' . esc_attr__( 'Pricing options', 'peptide-core' ) . '" hidden>' . $dots . '</nav></div>';
	}

	/** P14 is explicitly a manual fallback until an AI service is separately authorized and connected. */
	private static function ai_product_waterfall( array $module, string $context, string $device ): string {
		$s = $module['settings']; $preview = in_array( $context, array( 'editor', 'preview' ), true );
		if ( ! $preview && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return ''; }
		$merchant = $preview ? '<p class="pc-ai-waterfall__merchant" role="status">' . esc_html__( 'AI is not connected. This preview uses the selected fallback collection.', 'peptide-core' ) . '</p>' : '';
		$category = (int) ( $s['category'] ?? 0 ); $term = $category > 0 ? get_term( $category, 'product_cat' ) : null;
		if ( ! $term instanceof \WP_Term ) { return $preview ? '<div class="pc-cb vc-m__inner">' . $merchant . '<p>' . esc_html__( 'Choose a real fallback collection to display its public products.', 'peptide-core' ) . '</p></div>' : ''; }
		$per_page = max( 0, min( 20, (int) ( $s['cartCount'] ?? 10 ) ) ) + max( 0, min( 10, (int) ( $s['browseCount'] ?? 5 ) ) ) + max( 0, min( 10, (int) ( $s['purchaseCount'] ?? 5 ) ) );
		if ( 0 === $per_page ) { return $preview ? '<div class="pc-cb vc-m__inner">' . $merchant . '<p>' . esc_html__( 'All three quantities are zero. Increase a quantity to display fallback products.', 'peptide-core' ) . '</p></div>' : ''; }
		$batches = max( 1, min( 5, (int) ( $s['loadBatches'] ?? 3 ) ) );
		try { $result = Products::collection_page( $category, 1, $per_page ); }
		catch ( \Throwable $error ) { return '<div class="pc-cb vc-m__inner">' . $merchant . '<p role="status">' . esc_html__( 'Products could not be loaded. Please refresh to try again.', 'peptide-core' ) . '</p></div>'; }
		$quick = self::interactive( $context ); if ( $quick ) { Showcase_Quick_View::catalog_assets(); }
		$cards = self::waterfall_cards( $result['products'], $quick );
		if ( '' === $cards && ! $result['hasMore'] && ! $preview ) { return ''; }
		$empty = '' === $cards && $preview ? '<p class="pc-waterfall__empty">' . esc_html__( 'This collection has no eligible public products to display.', 'peptide-core' ) . '</p>' : '';
		$description = self::merchandise_description( $s );
		$heading = ( '' !== ( $s['title'] ?? '' ) ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-waterfall__description">' . $description . '</div>' : '' );
		$style = '--pc-waterfall-cols:' . max( 2, min( 5, (int) ( $s['desktopColumns'] ?? 4 ) ) ) . ';--pc-waterfall-cols-m:' . max( 2, min( 3, (int) ( $s['mobileColumns'] ?? 2 ) ) );
		$url = get_term_link( $term ); $more = ! is_wp_error( $url ) ? self::action_button( $s, (string) $url, __( 'View collection', 'peptide-core' ), $context ) : '';
		$token = self::waterfall_token( $category, $per_page, $batches, $quick );
		return '<div class="pc-cb vc-m__inner pc-waterfall pc-ai-waterfall' . ( ! empty( $s['fullWidth'] ) ? ' is-full-width' : '' ) . '" data-pc-ai-waterfall data-context="' . esc_attr( $token ) . '" data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-has-more="' . ( $result['hasMore'] && $batches > 1 ? '1' : '0' ) . '" data-batches="' . $batches . '" data-device="' . esc_attr( $device ) . '" style="' . esc_attr( $style ) . '">' . ( '' !== $heading ? '<header class="pc-waterfall__head">' . $heading . '</header>' : '' ) . $merchant . $empty . '<div class="pc-waterfall__list" data-pc-ai-waterfall-list>' . $cards . '</div><div class="pc-waterfall__sentinel" data-pc-ai-waterfall-sentinel aria-hidden="true"></div><p class="pc-waterfall__status" data-pc-ai-waterfall-status role="status" aria-live="polite"></p><button class="pc-waterfall__retry" type="button" data-pc-ai-waterfall-retry hidden>' . esc_html__( 'Try loading products again', 'peptide-core' ) . '</button><div class="pc-waterfall__more" data-pc-ai-waterfall-more hidden>' . $more . '</div><noscript><div class="pc-waterfall__more">' . $more . '</div></noscript></div>';
	}

	/** Independent source-backed behavior rankings; no public operating-data response. */
	private static function behavior_rankings( array $module, string $context ): string {
		$s = $module['settings']; $preview = in_array( $context, array( 'editor', 'preview' ), true );
		if ( ! $preview && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return ''; }
		$quick = self::interactive( $context ); if ( $quick ) { Showcase_Quick_View::catalog_assets(); }
		$id = wp_unique_id( 'pc-behavior-ranking-' ); $nav = ''; $panels = ''; $notices = array(); $any = false;
		foreach ( array( 'views' => 'Most Viewed', 'sales' => 'Best Sellers', 'cart' => 'Most Added to Cart' ) as $metric => $fallback ) {
			$days = max( 1, min( 40, (int) ( $s[ $metric . 'Days' ] ?? 7 ) ) ); $count = max( 1, min( 100, (int) ( $s[ $metric . 'Count' ] ?? 8 ) ) );
			$title = trim( (string) ( $s[ $metric . 'Title' ] ?? $fallback ) ); if ( '' === $title ) { $title = $fallback; }
			$result = Product_Behavior_Rankings::get( $metric, $days, $count ); $cards = '';
			foreach ( $result['products'] as $product ) { $cards .= self::merchandise_card( $product, $quick ); }
			$any = $any || '' !== $cards;
			$tab = $id . '-' . $metric . '-tab'; $panel = $id . '-' . $metric . '-panel';
			$nav .= '<a id="' . esc_attr( $tab ) . '" href="#' . esc_attr( $panel ) . '" aria-controls="' . esc_attr( $panel ) . '">' . esc_html( $title ) . '</a>';
			$panels .= '<section class="pc-behavior-ranking__panel" id="' . esc_attr( $panel ) . '" aria-labelledby="' . esc_attr( $tab ) . '"><h3 class="pc-behavior-ranking__group-title">' . esc_html( $title ) . '</h3>' . ( '' !== $cards ? '<div class="pc-behavior-ranking__products">' . $cards . '</div>' : '<p class="pc-behavior-ranking__empty">' . esc_html__( 'No products are available in this ranking yet.', 'peptide-core' ) . '</p>' ) . '</section>';
			if ( $preview ) {
				$messages = array(
					'sampled_events' => '按既有事件样本排序；不代表全体顾客或独立访客。',
					'tracking_disabled' => '当前追踪已关闭，仅使用仍保留的历史样本。',
					'retention_limited' => '所选窗口长于当前保存期限，历史不完整；不会自动延长保存期限。',
					'empty_sample' => '窗口内没有可公开展示的有效商品数据，未用普通目录补榜。',
					'tracking_unavailable' => '现有追踪数据表不可用；未开启追踪或安装新表。',
					'unavailable' => '数据暂时不可用；未把错误当作真实排名或普通商品目录。',
					'unsupported_order_store' => '当前订单存储不是支持的Woo CPT/HPOS标准存储，销量榜暂不可用；未使用不可靠的报表数据替代。',
				);
				$text = array(); foreach ( $result['notes'] as $code ) { if ( isset( $messages[ $code ] ) ) { $text[] = $messages[ $code ]; } }
				if ( $text ) { $notices[] = '<li><strong>' . esc_html( $title ) . '</strong>：' . esc_html( implode( ' ', $text ) ) . '</li>'; }
			}
		}
		if ( ! $any && ! $preview ) { return ''; }
		$style = '--pc-behavior-cols:' . max( 3, min( 8, (int) ( $s['rankingDesktopColumns'] ?? 4 ) ) ) . ';--pc-behavior-cols-m:' . max( 1, min( 2, (int) ( $s['rankingMobileColumns'] ?? 2 ) ) );
		$merchant = $notices ? '<aside class="pc-behavior-ranking__merchant" role="status"><ul>' . implode( '', $notices ) . '</ul></aside>' : '';
		return '<div class="pc-cb vc-m__inner pc-behavior-ranking' . ( ! empty( $s['fullWidth'] ) ? ' is-full-width' : '' ) . '" style="' . esc_attr( $style ) . '" data-pc-behavior-ranking><nav class="pc-behavior-ranking__tabs" aria-label="' . esc_attr__( 'Product rankings', 'peptide-core' ) . '">' . $nav . '</nav>' . $merchant . $panels . '</div>';
	}

	/** Keep the theme's reportPreview boolean and all existing card/Quick engines. */
	private static function collection_product_card( $product, bool $quick, bool $report_preview ): string {
		if ( ! Products::public_catalog_product( $product ) ) { return ''; }
		if ( ! function_exists( 'vc_product_card' ) ) { return self::merchandise_card( $product, $quick ); }
		ob_start(); vc_product_card( $product, $report_preview ); $card = (string) ob_get_clean();
		$card = (string) apply_filters( 'peptide_core_commerce_product_card_html', $card, $product );
		if ( '' === $card ) { return ''; }
		$can_quick = $quick && Showcase_Quick_View::supports( $product );
		return '<div class="pc-merch-card" data-pc-merch-product="' . (int) $product->get_id() . '">' . $card . ( $can_quick ? '<a class="pc-merch-card__quick" href="' . esc_url( $product->get_permalink() ) . '" data-vc-quick-open="' . (int) $product->get_id() . '">' . esc_html__( 'Quick view', 'peptide-core' ) . '</a>' . Showcase_Quick_View::template( $product ) : '' ) . '</div>';
	}

	/** P09/P10 share only the new explicit collection presentation and real card adapter. */
	private static function collection_products( array $module, string $context, bool $slides = false ): string {
		$s = $module['settings']; $preview = in_array( $context, array( 'editor', 'preview' ), true );
		if ( ! $preview && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return ''; }
		$category = (int) ( $s['collection'] ?? 0 ); $term = $category > 0 ? get_term( $category, 'product_cat' ) : null;
		if ( ! $term instanceof \WP_Term ) { return $preview ? '<div class="pc-cb vc-m__inner"><p>' . esc_html__( 'Choose a real collection to preview its products.', 'peptide-core' ) . '</p></div>' : ''; }
		$limit = max( 1, min( 100, (int) ( $s['collectionTotal'] ?? 8 ) ) );
		try {
			$products = Products::collection_page( $category, 1, min( 50, $limit ) )['products'];
			if ( $limit > 50 ) { $products = array_merge( $products, Products::collection_page( $category, 2, 50 )['products'] ); }
			$products = array_slice( $products, 0, $limit );
		} catch ( \Throwable $error ) { return '<div class="pc-cb vc-m__inner"><p role="status">' . esc_html__( 'Collection products could not be loaded. Please refresh to try again.', 'peptide-core' ) . '</p></div>'; }
		$quick = self::interactive( $context ); if ( $quick ) { Showcase_Quick_View::catalog_assets(); }
		$cards = ''; foreach ( $products as $product ) { $cards .= self::collection_product_card( $product, $quick, (bool) ( $s['reportPreview'] ?? true ) ); }
		if ( '' === $cards ) { return $preview ? '<div class="pc-cb vc-m__inner"><p>' . esc_html__( 'This collection has no eligible public products.', 'peptide-core' ) . '</p></div>' : ''; }
		$description = self::merchandise_description( $s );
		$head = ( '' !== ( $s['title'] ?? '' ) ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-collection-products__description">' . $description . '</div>' : '' );
		$display = $slides || 'carousel' === ( $s['collectionDisplay'] ?? 'grid' ) ? 'carousel' : 'grid';
		$cols = $s['columns'] ?? array( 'desktop' => 4, 'mobile' => 2 ); $desktop = max( 1, min( 6, (int) $cols['desktop'] ) ); $phone = max( 1, min( 6, (int) ( $cols['mobile'] ?? $desktop ) ) );
		if ( $slides ) { $desktop = max( 3, min( 5, (int) ( $s['slideColumns'] ?? 3 ) ) ); $phone = 2.5; }
		$style = '--pc-collection-product-cols:' . $desktop . ';--pc-collection-product-cols-m:' . $phone;
		$viewport = wp_unique_id( 'pc-collection-products-' ); $url = get_term_link( $term ); $label = trim( (string) ( $s['collectionMore'] ?? 'View More' ) );
		$more = ! is_wp_error( $url ) && '' !== $label ? self::action_button( $s, (string) $url, $label, $context ) : '';
		return '<div class="pc-cb vc-m__inner pc-collection-products' . ( $slides ? ' pc-collection-products--slides' : '' ) . ( ! empty( $s['fullWidth'] ) ? ' is-full-width' : '' ) . '" data-pc-collection-products data-style="' . ( $slides ? 'slides' : 'collection' ) . '" data-display="' . $display . '" style="' . esc_attr( $style ) . '">' . ( '' !== $head ? '<header class="pc-collection-products__head">' . $head . '</header>' : '' ) . '<div class="pc-collection-products__viewport" id="' . esc_attr( $viewport ) . '"><div class="pc-collection-products__list">' . $cards . '</div></div><nav class="pc-collection-products__controls" data-pc-collection-products-controls aria-label="' . esc_attr__( 'Collection product positions', 'peptide-core' ) . '" hidden><button type="button" data-pc-collection-products-prev aria-label="' . esc_attr__( 'Previous products', 'peptide-core' ) . '" aria-controls="' . esc_attr( $viewport ) . '">←</button>' . ( $slides ? '' : '<div class="pc-collection-products__dots" data-pc-collection-products-dots></div>' ) . '<button type="button" data-pc-collection-products-next aria-label="' . esc_attr__( 'Next products', 'peptide-core' ) . '" aria-controls="' . esc_attr( $viewport ) . '">→</button></nav>' . $more . '</div>';
	}

	/** Explicit collection covers only: never call the legacy all-category fallback. */
	private static function collection_tabs( array $module, string $context ): string {
		$s = $module['settings']; $preview = in_array( $context, array( 'editor', 'preview' ), true ); $groups = array();
		foreach ( self::rows( $module['items'] ) as $row ) {
			$cards = ''; $seen = array();
			foreach ( array_slice( self::rows( (array) ( $row['settings']['collections'] ?? array() ) ), 0, 32 ) as $child ) {
				$id = (int) ( $child['settings']['category'] ?? 0 );
				$term = $id > 0 ? get_term( $id, 'product_cat' ) : null;
				if ( ! $term instanceof \WP_Term ) { if ( $preview ) { $cards .= '<div class="pc-collection-tabs__placeholder" data-module-item="' . esc_attr( $child['id'] ) . '">' . esc_html__( 'Choose an available collection', 'peptide-core' ) . '</div>'; } continue; }
				if ( isset( $seen[ $term->term_id ] ) ) { continue; } $seen[ $term->term_id ] = true;
				$url = get_term_link( $term ); if ( is_wp_error( $url ) ) { continue; }
				$image = wp_get_attachment_image( (int) get_term_meta( $term->term_id, 'thumbnail_id', true ), 'large', false, array( 'loading' => 'lazy', 'alt' => '' ) );
				$cards .= '<a class="pc-collection-tabs__card" data-module-item="' . esc_attr( $child['id'] ) . '" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $term->name ) . '">' . $image . ( ! empty( $s['showCollectionTitle'] ) ? '<span class="pc-collection-tabs__name">' . esc_html( $term->name ) . '</span>' : '' ) . '</a>';
			}
			if ( '' === $cards && ! $preview ) { continue; }
			$label = trim( (string) ( $row['settings']['title'] ?? '' ) );
			$groups[] = array( 'id' => $row['id'], 'label' => '' !== $label ? $label : 'TAB', 'html' => '' !== $cards ? '<div class="pc-collection-tabs__grid">' . $cards . '</div>' : '<p>' . esc_html__( 'Select real collections for this group.', 'peptide-core' ) . '</p>' );
		}
		if ( ! $groups ) { return $preview ? '<div class="pc-cb vc-m__inner"><p>' . esc_html__( 'Add a group and choose real collections.', 'peptide-core' ) . '</p></div>' : ''; }
		$id = wp_unique_id( 'pc-collection-tabs-' ); $nav = ''; $panels = '';
		foreach ( $groups as $index => $group ) {
			$panel = $id . '-panel-' . $index; $tab = $id . '-tab-' . $index;
			$nav .= '<a id="' . esc_attr( $tab ) . '" href="#' . esc_attr( $panel ) . '" aria-controls="' . esc_attr( $panel ) . '">' . esc_html( $group['label'] ) . '</a>';
			$panels .= '<section class="pc-collection-tabs__panel" id="' . esc_attr( $panel ) . '" data-module-item="' . esc_attr( $group['id'] ) . '" aria-labelledby="' . esc_attr( $tab ) . '"><h3 class="pc-collection-tabs__group-title">' . esc_html( $group['label'] ) . '</h3>' . $group['html'] . '</section>';
		}
		$cols = $s['collectionColumns'] ?? array( 'desktop' => 6, 'mobile' => 2 );
		$style = '--pc-collection-tab-cols:' . max( 1, min( 8, (int) $cols['desktop'] ) ) . ';--pc-collection-tab-cols-m:' . max( 1, min( 8, (int) ( $cols['mobile'] ?? $cols['desktop'] ) ) );
		$description = self::merchandise_description( $s );
		$head = ( '' !== ( $s['title'] ?? '' ) ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-collection-tabs__description">' . $description . '</div>' : '' );
		return '<div class="pc-cb vc-m__inner pc-collection-tabs' . ( ! empty( $s['fullWidth'] ) ? ' is-full-width' : '' ) . '" style="' . esc_attr( $style ) . '">' . ( '' !== $head ? '<header class="pc-collection-tabs__head">' . $head . '</header>' : '' ) . '<div data-pc-collection-tabs data-switch-mode="' . ( 'click' === ( $s['collectionSwitch'] ?? 'hover' ) ? 'click' : 'hover' ) . '"><nav class="pc-collection-tabs__nav" aria-label="' . esc_attr__( 'Collection groups', 'peptide-core' ) . '">' . $nav . '</nav>' . $panels . '</div></div>';
	}

	/** Cookie-unlocked passwords and editor lookup labels do not authorize public topic cards. */
	private static function topic_tabs_showcase( array $module, string $context ): string {
		$s = $module['settings']; $preview = in_array( $context, array( 'editor', 'preview' ), true ); $groups = array();
		foreach ( self::rows( $module['items'] ) as $row ) {
			$cards = ''; $seen = array();
			foreach ( array_slice( self::rows( (array) ( $row['settings']['topics'] ?? array() ) ), 0, 32 ) as $child ) {
				$id = (int) ( $child['settings']['topic'] ?? 0 ); $post = $id > 0 ? get_post( $id ) : null;
				if ( ! $post || 'pc_topic' !== $post->post_type || 'publish' !== $post->post_status || '' !== (string) $post->post_password || ! is_post_publicly_viewable( $post ) ) {
					if ( $preview ) { $cards .= '<div class="pc-collection-tabs__placeholder" data-module-item="' . esc_attr( $child['id'] ) . '">' . esc_html__( 'Choose an available published topic without a password.', 'peptide-core' ) . '</div>'; } continue;
				}
				if ( isset( $seen[ $id ] ) ) { continue; } $seen[ $id ] = true;
				$url = get_permalink( $id ); if ( ! is_string( $url ) || '' === $url ) { continue; }
				$title = get_the_title( $id ); $image = ! empty( $s['showTopicCover'] ) ? get_the_post_thumbnail( $id, 'large', array( 'loading' => 'lazy', 'alt' => '' ) ) : '';
				$cards .= '<article class="pc-topic-tabs__card" data-module-item="' . esc_attr( $child['id'] ) . '">';
				if ( ! empty( $s['showTopicCover'] ) ) { $cards .= '<a class="pc-topic-tabs__image" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $title ?: __( 'View topic', 'peptide-core' ) ) . '">' . wp_kses_post( $image ) . '</a>'; }
				$cards .= '<h3><a href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $title ?: __( 'View topic', 'peptide-core' ) ) . '">' . esc_html( $title ) . '</a></h3>';
				$raw = $post->post_excerpt ?: $post->post_content; if ( function_exists( 'strip_shortcodes' ) ) { $raw = strip_shortcodes( $raw ); }
				$excerpt = wp_trim_words( wp_strip_all_tags( $raw ), 30 );
				if ( ! empty( $s['showTopicDescription'] ) && '' !== trim( $excerpt ) ) { $cards .= '<p class="pc-topic-tabs__excerpt">' . esc_html( $excerpt ) . '</p>'; }
				$more = trim( (string) ( $s['topicMoreLabel'] ?? 'Read More >' ) );
				if ( '' !== $more ) { $cards .= '<a class="pc-topic-tabs__more" href="' . esc_url( $url ) . '">' . esc_html( $more ) . '</a>'; }
				$cards .= '</article>';
			}
			if ( '' === $cards && ! $preview ) { continue; }
			$label = trim( (string) ( $row['settings']['title'] ?? '' ) );
			$groups[] = array( 'id' => $row['id'], 'label' => '' !== $label ? $label : 'TAB', 'html' => '' !== $cards ? '<div class="pc-topic-tabs__grid">' . $cards . '</div>' : '<p>' . esc_html__( 'Select published topics for this group.', 'peptide-core' ) . '</p>' );
		}
		if ( ! $groups ) { return $preview ? '<div class="pc-cb vc-m__inner"><p>' . esc_html__( 'Add a group and select real published topics.', 'peptide-core' ) . '</p></div>' : ''; }
		$id = wp_unique_id( 'pc-topic-tabs-' ); $nav = ''; $panels = '';
		foreach ( $groups as $index => $group ) {
			$panel = $id . '-panel-' . $index; $tab = $id . '-tab-' . $index;
			$nav .= '<a id="' . esc_attr( $tab ) . '" href="#' . esc_attr( $panel ) . '" aria-controls="' . esc_attr( $panel ) . '">' . esc_html( $group['label'] ) . '</a>';
			$panels .= '<section class="pc-collection-tabs__panel" id="' . esc_attr( $panel ) . '" data-module-item="' . esc_attr( $group['id'] ) . '" aria-labelledby="' . esc_attr( $tab ) . '"><h3 class="pc-collection-tabs__group-title">' . esc_html( $group['label'] ) . '</h3>' . $group['html'] . '</section>';
		}
		$cols = $s['topicColumns'] ?? array( 'desktop' => 3, 'mobile' => 1 ); $align = $s['topicTextAlign'] ?? 'center';
		if ( ! in_array( $align, array( 'left', 'center', 'right' ), true ) ) { $align = 'center'; }
		$background = $s['topicImageBackground'] ?? '#6e6d6d66'; if ( ! is_string( $background ) || ! preg_match( '/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/iD', $background ) ) { $background = '#6e6d6d66'; }
		$style = '--pc-topic-cols:' . max( 1, min( 10, (int) $cols['desktop'] ) ) . ';--pc-topic-cols-m:' . max( 1, min( 10, (int) ( $cols['mobile'] ?? $cols['desktop'] ) ) ) . ';--pc-topic-align:' . $align . ';--pc-topic-image-bg:' . $background . ';--pc-topic-image-pad:' . max( 0, min( 100, (int) ( $s['topicImagePadding'] ?? 0 ) ) ) . 'px';
		$description = self::merchandise_description( $s );
		$head = ( '' !== ( $s['title'] ?? '' ) ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-collection-tabs__description">' . $description . '</div>' : '' );
		return '<div class="pc-cb vc-m__inner pc-collection-tabs pc-topic-tabs' . ( ! empty( $s['fullWidth'] ) ? ' is-full-width' : '' ) . '" style="' . esc_attr( $style ) . '">' . ( '' !== $head ? '<header class="pc-collection-tabs__head">' . $head . '</header>' : '' ) . '<div data-pc-collection-tabs data-switch-mode="' . ( 'click' === ( $s['topicSwitch'] ?? 'hover' ) ? 'click' : 'hover' ) . '"><nav class="pc-collection-tabs__nav" aria-label="' . esc_attr__( 'Topic groups', 'peptide-core' ) . '">' . $nav . '</nav>' . $panels . '</div></div>';
	}

	/** Manual P16 artwork and links do not create discounts or alter activity periods. */
	private static function promotion_trio( array $module, string $context, string $device ): string {
		$preview = in_array( $context, array( 'editor', 'preview' ), true ); $out = '';
		if ( ! $preview && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return ''; }
		foreach ( array_slice( self::rows( $module['items'] ), 0, 12 ) as $row ) {
			$s = $row['settings']; $kind = $s['cardType'] ?? 'product'; $notice = '';
			if ( 'promotion' === $kind ) {
				$image = self::image( $s['backgroundImage'] ?? null, 'large' );
				$title = trim( (string) ( $s['title'] ?? '' ) ); $subtitle = trim( (string) ( $s['subtitle'] ?? '' ) ); $description = trim( (string) ( $s['description'] ?? '' ) );
				$href = Fields::url( (string) ( $s['promotionHref'] ?? '' ) ); $label = trim( (string) ( $s['promotionButtonLabel'] ?? '' ) );
				$date = trim( (string) ( $s['endDate'] ?? '' ) ); $end = null;
				if ( ! empty( $s['showCountdown'] ) && '' !== $date ) {
					if ( preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
						try { $end = new \DateTimeImmutable( $date . ' 23:59:59', wp_timezone() ); } catch ( \Exception $error ) { $end = null; }
					}
					if ( ! $end && $preview ) { $notice = '<p class="pc-promotion-trio__notice">' . esc_html__( 'Choose a valid end date. No countdown is shown for an invalid date.', 'peptide-core' ) . '</p>'; }
				}
				$populated = '' !== $image || '' !== $title || '' !== $subtitle || '' !== $description || ( '' !== $href && '' !== $label ) || null !== $end;
				if ( ! $populated && ! $preview ) { continue; }
				$text = $s['textColor'] ?? '#ffffff'; $overlay = $s['overlayColor'] ?? '#00000000';
				$valid_color = static fn( $value ) => is_string( $value ) && 1 === preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/iD', $value );
				$style = '--pc-trio-text:' . ( $valid_color( $text ) ? $text : '#ffffff' ) . ';--pc-trio-overlay:' . ( $valid_color( $overlay ) ? $overlay : '#00000000' );
				$out .= '<article class="pc-promotion-trio__card pc-promotion-trio__promotion" data-module-item="' . esc_attr( $row['id'] ) . '" style="' . esc_attr( $style ) . '">' . ( '' !== $image ? '<div class="pc-promotion-trio__art" aria-hidden="true">' . $image . '</div>' : '' ) . '<div class="pc-promotion-trio__mask" aria-hidden="true"></div><div class="pc-promotion-trio__content">';
				$out .= ( '' !== $subtitle ? '<p class="pc-promotion-trio__subtitle">' . esc_html( $subtitle ) . '</p>' : '' ) . ( '' !== $title ? '<h3 class="pc-promotion-trio__title">' . esc_html( $title ) . '</h3>' : '' ) . ( '' !== $description ? '<p class="pc-promotion-trio__description">' . nl2br( esc_html( $description ) ) . '</p>' : '' );
				if ( $end ) {
					$remaining = max( 0, $end->getTimestamp() - time() ); $values = array( 'days' => intdiv( $remaining, 86400 ), 'hours' => intdiv( $remaining % 86400, 3600 ), 'minutes' => intdiv( $remaining % 3600, 60 ), 'seconds' => $remaining % 60 );
					$out .= '<div class="pc-promotion-trio__countdown" data-pc-trio-countdown data-end="' . (int) ( $end->getTimestamp() * 1000 ) . '" data-end-iso="' . esc_attr( $end->format( DATE_ATOM ) ) . '" role="timer" aria-live="off" aria-label="' . esc_attr__( 'Time remaining', 'peptide-core' ) . '">';
					foreach ( array( 'days' => __( 'Days', 'peptide-core' ), 'hours' => __( 'Hours', 'peptide-core' ), 'minutes' => __( 'Mins', 'peptide-core' ), 'seconds' => __( 'Secs', 'peptide-core' ) ) as $part => $name ) { $out .= '<span class="pc-promotion-trio__time"><strong data-pc-trio-part="' . $part . '">' . sprintf( '%02d', $values[ $part ] ) . '</strong><span>' . esc_html( $name ) . '</span></span>'; }
					$out .= '</div><p class="pc-promotion-trio__ended" data-pc-trio-ended' . ( $remaining > 0 ? ' hidden' : '' ) . '>' . esc_html__( 'Countdown ended', 'peptide-core' ) . '</p>';
				}
				if ( '' !== $href && '' !== $label ) { $out .= '<a class="vc-btn pc-promotion-trio__button pc-promotion-trio__promo-button" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>'; }
				if ( ! $populated && $preview ) { $notice .= '<p class="pc-promotion-trio__notice">' . esc_html__( 'Add artwork, text, a valid countdown date or a real button link for this promotion card.', 'peptide-core' ) . '</p>'; }
				$out .= $notice . '</div></article>';
				continue;
			}
			$image = self::image( $s['productImage'] ?? null, 'large' ); $image_href = Fields::url( (string) ( $s['imageHref'] ?? '' ) );
			$category = (int) ( $s['collection'] ?? 0 ); $term = $category > 0 ? get_term( $category, 'product_cat' ) : null; $url = ''; $products = array();
			if ( $term instanceof \WP_Term ) {
				$resolved = get_term_link( $term ); $url = is_wp_error( $resolved ) ? '' : Fields::url( (string) $resolved );
				try { $products = Products::collection_page( $category, 1, 2 )['products']; }
				catch ( \Throwable $error ) { if ( $preview ) { $notice = '<p class="pc-promotion-trio__notice">' . esc_html__( 'Products are unavailable. Retry the preview or open the collection.', 'peptide-core' ) . '</p>'; } }
			} elseif ( $preview ) { $notice = '<p class="pc-promotion-trio__notice">' . esc_html__( 'Choose a real collection to connect this product card.', 'peptide-core' ) . '</p>'; }
			$products = array_values( array_filter( $products, array( Products::class, 'public_catalog_product' ) ) );
			if ( '' === $image && '' === $url && ! $preview ) { continue; }
			$out .= '<article class="pc-promotion-trio__card pc-promotion-trio__product" data-module-item="' . esc_attr( $row['id'] ) . '">';
			if ( '' !== $image ) { $out .= '<div class="pc-promotion-trio__art">' . ( '' !== $image_href ? '<a href="' . esc_url( $image_href ) . '" aria-label="' . esc_attr__( 'View linked page', 'peptide-core' ) . '">' . $image . '</a>' : $image ) . '</div>'; }
			$label = trim( (string) ( $s['productButtonLabel'] ?? 'View Products' ) ); $panel_id = wp_unique_id( 'pc-trio-look-' );
			if ( '' !== $url && '' !== $label ) { $out .= '<a class="vc-btn pc-promotion-trio__button pc-promotion-trio__look-button' . ( 'secondary' === ( $s['productButtonStyle'] ?? 'primary' ) ? ' is-secondary' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $products ? ' data-pc-trio-open aria-controls="' . esc_attr( $panel_id ) . '"' : '' ) . '>' . esc_html( $label ) . '</a>'; }
			if ( $products && '' !== $url && '' !== $label ) {
				$out .= '<section class="pc-promotion-trio__panel" id="' . esc_attr( $panel_id ) . '" data-pc-trio-panel aria-labelledby="' . esc_attr( $panel_id . '-title' ) . '"><header><h4 id="' . esc_attr( $panel_id . '-title' ) . '">' . esc_html__( 'Shop the Look', 'peptide-core' ) . '</h4><button type="button" data-pc-trio-close aria-label="' . esc_attr__( 'Close', 'peptide-core' ) . '" hidden><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header><div class="pc-promotion-trio__products">';
				foreach ( $products as $product ) { $out .= '<a class="pc-promotion-trio__item" data-pc-trio-product="' . (int) $product->get_id() . '" href="' . esc_url( $product->get_permalink() ) . '"><span class="pc-promotion-trio__thumb">' . wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ) . '</span><span class="pc-promotion-trio__identity"><span class="pc-promotion-trio__name">' . esc_html( $product->get_name() ) . '</span><span class="pc-promotion-trio__price">' . wp_kses_post( $product->get_price_html() ) . '</span></span></a>'; }
				$out .= '</div></section>';
			}
			$out .= $notice . '</article>';
		}
		if ( '' === $out ) { return ''; }
		return '<div class="pc-cb vc-m__inner pc-promotion-trio" data-pc-promotion-trio data-device="' . esc_attr( $device ) . '" style="--pc-trio-gap:' . max( 0, min( 100, (int) ( $module['settings']['cardGap'] ?? 24 ) ) ) . 'px"><div class="pc-promotion-trio__grid">' . $out . '</div></div>';
	}


	/** O02 merchant-curated terms. A stored link wins; only an empty link becomes a real Woo search. */
	private static function search_terms_reference( array $module, string $context ): string {
		$s = $module['settings']; $chips = '';
		foreach ( self::rows( $module['items'] ?? array() ) as $item ) {
			$row = is_array( $item['settings'] ?? null ) ? $item['settings'] : array();
			$label = trim( (string) ( $row['title'] ?? '' ) ); if ( '' === $label ) { continue; }
			$url = Fields::url( (string) ( $row['url'] ?? '' ) );
			if ( '' === $url ) { $url = add_query_arg( array( 's' => rawurlencode( $label ), 'post_type' => 'product' ), home_url( '/' ) ); }
			$chips .= '<a class="pc-search-terms__chip" data-module-item="' . esc_attr( (string) ( $item['id'] ?? '' ) ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		if ( '' === $chips ) { return in_array( $context, array( 'editor', 'preview' ), true ) ? '<div class="pc-cb vc-m__inner"><p>添加店铺真实推荐的关键词，即可预览搜索入口。</p></div>' : ''; }
		$styles = array();
		foreach ( array( 'termsTitleColor' => array( 'title', '#1d1f21' ), 'termsDescriptionColor' => array( 'description', '#666666' ) ) as $key => $color ) {
			$value = $s[ $key ] ?? $color[1];
			if ( ! is_string( $value ) || ! preg_match( '/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/iD', $value ) ) { $value = $color[1]; }
			$styles[] = '--pc-search-terms-' . $color[0] . ':' . $value;
		}
		foreach ( array( 'Top' => 'top', 'Right' => 'right', 'Bottom' => 'bottom', 'Left' => 'left' ) as $edge => $name ) {
			$default = in_array( $edge, array( 'Top', 'Bottom' ), true ) ? 30 : 0;
			$value = $s[ 'termsPadding' . $edge . 'Px' ] ?? array( 'desktop' => $default, 'mobile' => $default );
			$value = is_array( $value ) ? $value : array( 'desktop' => $default, 'mobile' => $default );
			$desktop = max( 0, min( 200, (int) ( $value['desktop'] ?? $default ) ) );
			$phone = max( 0, min( 200, (int) ( $value['mobile'] ?? $desktop ) ) );
			$styles[] = '--pc-search-terms-' . $name . ':' . $desktop . 'px';
			$styles[] = '--pc-search-terms-' . $name . '-m:' . $phone . 'px';
		}
		$background = Assets::resolve( $s['termsBackgroundImage'] ?? null, 'large' );
		$image = $background ? '<img class="pc-search-terms__background" src="' . esc_url( $background['src'] ) . '" alt="" aria-hidden="true" loading="lazy">' : '';
		$description = self::merchandise_description( $s );
		$heading = '' !== ( $s['title'] ?? '' ) ? '<h2 class="pc-search-terms__title">' . esc_html( $s['title'] ) . '</h2>' : '';
		$heading .= '' !== $description ? '<div class="pc-search-terms__description">' . $description . '</div>' : '';
		return '<div class="pc-cb vc-m__inner pc-search-terms" data-pc-search-terms style="' . esc_attr( implode( ';', $styles ) ) . '">' . $image . '<div class="pc-search-terms__inner">' . ( '' !== $heading ? '<header class="pc-search-terms__heading">' . $heading . '</header>' : '' ) . '<nav class="pc-search-terms__chips" aria-label="' . esc_attr__( 'Suggested product searches', 'peptide-core' ) . '">' . $chips . '</nav></div></div>';
	}

	/** Read-only suggestions may run in preview; navigation waits for the public storefront. */
	private static function site_search_hero( array $module, string $context ): string {
		$s = $module['settings']; $types = \PeptideCore\Public_Site_Search::configured_types( $s['searchTypes'] ?? array() );
		if ( ! $types ) { return in_array( $context, array( 'editor', 'preview' ), true ) ? '<div class="pc-cb vc-m__inner"><p>选择至少一种搜索类型，即可预览搜索入口。</p></div>' : ''; }
		$preview = in_array( $context, array( 'editor', 'preview' ), true );
		if ( ! $preview && is_wp_error( \PeptideCore\Public_Site_Search::permission() ) ) { return ''; }
		\PeptideCore\Public_Site_Search::assets();
		$id = wp_unique_id( 'pc-site-search-' ); $scope = implode( ',', $types ); $labels = \PeptideCore\Public_Site_Search::labels();
		$config = array( 'url' => rest_url( 'peptide/v1/site-search' ), 'nonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '', 'types' => $types, 'labels' => array_intersect_key( $labels, array_fill_keys( $types, true ) ), 'preview' => $preview );
		if ( is_user_logged_in() && ! headers_sent() ) { nocache_headers(); }
		$height = in_array( $s['imageHeight'] ?? '', array( 'small', 'medium', 'large', 'auto' ), true ) ? $s['imageHeight'] : 'medium';
		$image = self::image( $s['backgroundImage'] ?? null, 'full' ); $background = Assets::resolve( $s['backgroundImage'] ?? null, 'full' );
		$overlay = max( 0, min( 100, (int) ( $s['overlayPercent'] ?? 20 ) ) ) / 100;
		$ratio = $background && (int) $background['width'] > 0 && (int) $background['height'] > 0 ? (float) $background['width'] / (float) $background['height'] : 1;
		$style = '--pc-site-search-overlay:' . $overlay . ';--pc-site-search-ratio:' . $ratio;
		$description = null !== ( $s['descriptionHtml'] ?? null ) ? wp_kses_post( $s['descriptionHtml'] ) : '';
		$head = '' !== ( $s['title'] ?? '' ) ? '<h2 class="pc-site-search-hero__title">' . esc_html( $s['title'] ) . '</h2>' : '';
		$head .= '' !== $description ? '<div class="pc-site-search-hero__description">' . $description . '</div>' : '';
		$tabs = ''; foreach ( $types as $index => $type ) { $tabs .= '<button type="button" role="tab" aria-selected="' . ( 0 === $index ? 'true' : 'false' ) . '" tabindex="' . ( 0 === $index ? '0' : '-1' ) . '" data-pc-search-type="' . esc_attr( $type ) . '">' . esc_html( $labels[ $type ] ) . '</button>'; }
		$form = '<form role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '"><input type="hidden" name="pc_site_search" value="1"><input type="hidden" name="pc_site_type" value="' . esc_attr( $types[0] ) . '"><input type="hidden" name="pc_site_scope" value="' . esc_attr( $scope ) . '"><input type="hidden" name="pc_site_page" value="1"><label class="screen-reader-text" for="' . esc_attr( $id . '-query' ) . '">' . esc_html__( 'Search this store', 'peptide-core' ) . '</label><input id="' . esc_attr( $id . '-query' ) . '" name="pc_site_q" type="search" autocomplete="off" maxlength="100" required placeholder="' . esc_attr( (string) ( $s['placeholder'] ?? 'Search' ) ) . '" role="combobox" aria-autocomplete="list" aria-haspopup="dialog" aria-expanded="false" aria-controls="' . esc_attr( $id . '-panel' ) . '"><button type="submit">' . esc_html__( 'Search', 'peptide-core' ) . '</button></form>';
		$panel = '<div class="pc-site-search__panel" id="' . esc_attr( $id . '-panel' ) . '" role="dialog" aria-label="' . esc_attr__( 'Search suggestions', 'peptide-core' ) . '" hidden><div class="pc-site-search__tabs" role="tablist" aria-label="' . esc_attr__( 'Search type', 'peptide-core' ) . '">' . $tabs . '</div><div class="pc-site-search__body"><p class="pc-site-search__status" role="status" aria-live="polite"></p><ul class="pc-site-search__results"></ul></div></div>';
		return '<div class="pc-cb vc-m__inner pc-site-search-hero' . ( ! empty( $s['fullWidth'] ) ? ' vc-m__inner--full' : '' ) . '" data-height="' . esc_attr( $height ) . '" data-pc-site-search="' . esc_attr( wp_json_encode( $config ) ) . '" style="' . esc_attr( $style ) . '">' . ( $image ? '<div class="pc-site-search-hero__image">' . $image . '</div>' : '' ) . '<div class="pc-site-search-hero__overlay" aria-hidden="true"></div><div class="pc-site-search-hero__content">' . $head . '<div class="pc-site-search__control">' . $form . $panel . '</div></div></div>';
	}


	/** Strict parsing is scoped to O04; the legacy Fallback extractor stays unchanged. */
	private static function youtube_reference_id( string $url ): ?string {
		return \PeptideCore\YouTube_Metadata::video_id( $url );
	}

	private static function youtube_reference_button( array $s, string $url, string $label, string $context, string $class = '' ): string {
		if ( '' === trim( $url ) || '' === trim( $label ) ) { return ''; }
		$effect = in_array( $s['buttonEffect'] ?? '', array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble' ), true ) ? $s['buttonEffect'] : 'none';
		$span = '<span class="pc-campaign__button pc-campaign__button--' . esc_attr( $effect ) . '" data-pc-campaign-effect="' . esc_attr( $effect ) . '" data-effect-duration="' . max( 1000, min( 5000, (int) ( $s['effectDurationMs'] ?? 1000 ) ) ) . '" data-effect-interval="' . max( 500, min( 5000, (int) ( $s['effectIntervalMs'] ?? 1500 ) ) ) . '">' . esc_html( $label ) . '</span>';
		return self::interactive( $context ) ? '<a class="pc-youtube__button ' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . $span . '</a>' : '<span class="pc-youtube__button ' . esc_attr( $class ) . '" aria-disabled="true">' . $span . '</span>';
	}

	private static function youtube_reference_products( $rows, array $s, string $context ): string {
		if ( ! in_array( $context, array( 'editor', 'preview' ), true ) && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return ''; }
		$html = ''; $seen = array();
		foreach ( is_array( $rows ) ? array_slice( $rows, 0, 20 ) : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['enabled'] ) ) { continue; }
			$id = Records::sanitize( $row['settings']['product'] ?? null ); if ( ! $id || isset( $seen[ $id ] ) ) { continue; } $seen[ $id ] = true;
			try { $product = wc_get_product( $id ); if ( ! Products::public_catalog_product( $product ) ) { continue; }
				$url = (string) $product->get_permalink(); $name = (string) $product->get_name();
				$price = function_exists( 'vc_public_price_html' ) ? vc_public_price_html( $product ) : $product->get_price_html();
				$image = wp_kses_post( $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ) );
				$visual = '<span class="pc-youtube__product-image">' . $image . '</span><span class="pc-youtube__product-name">' . esc_html( $name ) . '</span>';
				$visual = self::interactive( $context ) ? '<a class="pc-youtube__product-identity" href="' . esc_url( $url ) . '">' . $visual . '</a>' : '<span class="pc-youtube__product-identity">' . $visual . '</span>';
				$html .= '<li class="pc-youtube__product" data-module-item="' . esc_attr( (string) ( $row['id'] ?? '' ) ) . '">' . $visual . '<span class="pc-youtube__product-price">' . wp_kses_post( $price ) . '</span>' . self::youtube_reference_button( $s, $url, (string) ( $s['productButtonLabel'] ?? 'Shop now' ), $context ) . '</li>';
			} catch ( \Throwable $error ) { continue; }
		}
		return $html ? '<ul class="pc-youtube__products" aria-label="' . esc_attr__( 'Products in this video', 'peptide-core' ) . '">' . $html . '</ul>' : '';
	}

	private static function youtube_reference( array $module, string $context ): string {
		if ( ! in_array( $context, array( 'editor', 'preview' ), true ) && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return ''; }
		$s = $module['settings']; $cards = ''; $count = 0; $invalid = 0;
		$site = wp_parse_url( home_url( '/' ) ); $origin = ( $site['scheme'] ?? 'https' ) . '://' . ( $site['host'] ?? '' ) . ( isset( $site['port'] ) ? ':' . (int) $site['port'] : '' );
		foreach ( array_slice( self::rows( $module['items'] ?? array() ), 0, 12 ) as $item ) {
			$row = $item['settings']; $url = is_string( $row['url'] ?? null ) ? trim( $row['url'] ) : ''; $video = self::youtube_reference_id( $url );
			if ( ! $video ) { if ( '' !== $url ) { $invalid++; } continue; } $count++;
			$embed = 'https://www.youtube-nocookie.com/embed/' . $video . '?enablejsapi=1&playsinline=1&rel=0&origin=' . rawurlencode( $origin );
			$metadata = \PeptideCore\YouTube_Metadata::cached( $video ); // Public rendering is cache-only, after forceLogin.
			$id = wp_unique_id( 'pc-youtube-player-' ); $label = $metadata['title'] ?: ( $row['title'] ?? '' ); $label = '' !== trim( (string) $label ) ? (string) $label : sprintf( __( 'YouTube video %d', 'peptide-core' ), $count );
			$info = ! empty( $metadata['fetchedAt'] ) ? '<div class="pc-youtube__metadata"><h3>' . esc_html( $label ) . '</h3>' . ( $metadata['authorName'] ? '<p>' . esc_html( $metadata['authorName'] ) . '</p>' : '' ) . '</div>' : '';
			$poster = '';
			if ( $metadata['thumbnailUrl'] ) {
				$poster_content = '<img src="' . esc_url( $metadata['thumbnailUrl'] ) . '" alt="" loading="lazy" referrerpolicy="no-referrer"><span>' . esc_html__( 'Watch on YouTube', 'peptide-core' ) . '</span>';
				$poster = self::interactive( $context ) ? '<a class="pc-youtube__poster" href="https://www.youtube.com/watch?v=' . esc_attr( $video ) . '" target="_blank" rel="noopener noreferrer">' . $poster_content . '</a>' : '<div class="pc-youtube__poster">' . $poster_content . '</div>';
			}
			// Metadata siblings survive the existing player slot.replaceChildren() retry.
			$cards .= '<article class="pc-youtube__card" data-pc-youtube-card data-module-item="' . esc_attr( (string) ( $item['id'] ?? '' ) ) . '"><div class="pc-youtube__frame"><iframe id="' . esc_attr( $id ) . '" src="' . esc_url( $embed ) . '" data-pc-youtube-src="' . esc_url( $embed ) . '" title="' . esc_attr( $label ) . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="encrypted-media; fullscreen; picture-in-picture" allowfullscreen></iframe></div>' . $info . $poster . '<p class="pc-youtube__status" role="status" aria-live="polite"></p><a class="pc-youtube__watch" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Watch on YouTube', 'peptide-core' ) . '</a>' . self::youtube_reference_products( $row['products'] ?? array(), $s, $context ) . '</article>';
		}
		$preview = ! self::interactive( $context );
		$description = null !== ( $s['descriptionHtml'] ?? null ) ? wp_kses_post( (string) $s['descriptionHtml'] ) : ( '' !== ( $s['description'] ?? '' ) ? '<p>' . nl2br( esc_html( (string) $s['description'] ) ) . '</p>' : '' );
		$head = '' !== ( $s['title'] ?? '' ) ? '<h2>' . esc_html( (string) $s['title'] ) . '</h2>' : '';
		$head .= '' !== $description ? '<div class="pc-youtube__description">' . $description . '</div>' : '';
		if ( ! $cards && ! $preview ) { return ''; }
		$mode = 'tiled' === ( $s['displayMode'] ?? '' ) ? 'tiled' : 'single';
		$controls = $count > 1 ? '<div class="pc-youtube__controls" hidden><button type="button" data-pc-youtube-prev aria-label="' . esc_attr__( 'Previous videos', 'peptide-core' ) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg></button><button type="button" data-pc-youtube-next aria-label="' . esc_attr__( 'Next videos', 'peptide-core' ) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m10 6 6 6-6 6"/></svg></button></div>' : '';
		$empty = ! $cards ? '<p class="pc-youtube__empty">添加完整的 YouTube 视频链接，并在右侧读取视频信息。基础信息无需密钥；读取失败仍可手动播放。</p>' : '';
		$note = $preview && $invalid ? '<p class="pc-youtube__empty">有视频链接不符合支持的 HTTPS YouTube 格式，已保留原数据，请检查。</p>' : '';
		return '<section class="pc-cb vc-m__inner pc-youtube" data-pc-youtube data-display="' . $mode . '">' . ( $head ? '<header class="pc-youtube__head">' . $head . '</header>' : '' ) . $empty . $note . '<div class="pc-youtube__list" role="region" aria-label="' . esc_attr__( 'YouTube videos', 'peptide-core' ) . '" tabindex="0">' . $cards . '</div>' . $controls . self::youtube_reference_button( $s, (string) ( $s['moreUrl'] ?? '' ), (string) ( $s['moreLabel'] ?? 'View More' ), $context, 'pc-youtube__more' ) . '</section>';
	}

	/** O05 accepts canonical full video links only. Decimal IDs stay strings. */
	private static function tiktok_reference_id( string $url ): ?string {
		if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $url ) ) { return null; }
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || 'https' !== strtolower( $p['scheme'] ?? '' ) || ! in_array( strtolower( $p['host'] ?? '' ), array( 'tiktok.com', 'www.tiktok.com' ), true ) || isset( $p['user'] ) || isset( $p['pass'] ) || ( isset( $p['port'] ) && 443 !== (int) $p['port'] ) ) { return null; }
		return preg_match( '~^/@[A-Za-z0-9._]{1,64}/video/([1-9][0-9]{9,24})/?$~D', $p['path'] ?? '', $m ) ? $m[1] : null;
	}

	private static function tiktok_reference_products( $rows, string $context ): string {
		if ( ! in_array( $context, array( 'editor', 'preview' ), true ) && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return ''; }
		$html = ''; $seen = array();
		foreach ( is_array( $rows ) ? array_slice( $rows, 0, 20 ) : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['enabled'] ) ) { continue; }
			$id = Records::sanitize( $row['settings']['product'] ?? null ); if ( ! $id || isset( $seen[ $id ] ) ) { continue; } $seen[ $id ] = true;
			try { $product = wc_get_product( $id ); if ( ! Products::public_catalog_product( $product ) ) { continue; }
				$url = (string) $product->get_permalink(); $name = (string) $product->get_name();
				$price = function_exists( 'vc_public_price_html' ) ? vc_public_price_html( $product ) : $product->get_price_html();
				$visual = '<span class="pc-tiktok__product-image">' . wp_kses_post( $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ) ) . '</span><span class="pc-tiktok__product-info"><span class="pc-tiktok__product-name">' . esc_html( $name ) . '</span><span class="pc-tiktok__product-price">' . wp_kses_post( $price ) . '</span></span>';
				$html .= '<li class="pc-tiktok__product" data-module-item="' . esc_attr( (string) ( $row['id'] ?? '' ) ) . '">' . ( self::interactive( $context ) ? '<a href="' . esc_url( $url ) . '">' . $visual . '</a>' : '<span class="pc-tiktok__product-preview">' . $visual . '</span>' ) . '</li>';
			} catch ( \Throwable $error ) { continue; }
		}
		if ( ! $html ) { return ''; }
		$controls = '<nav class="pc-tiktok__product-controls" aria-label="' . esc_attr__( 'Products in this video', 'peptide-core' ) . '" hidden><button type="button" data-pc-tiktok-product-prev aria-label="' . esc_attr__( 'Previous product', 'peptide-core' ) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg></button><button type="button" data-pc-tiktok-product-next aria-label="' . esc_attr__( 'Next product', 'peptide-core' ) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m10 6 6 6-6 6"/></svg></button></nav>';
		return '<div class="pc-tiktok__products-region"><ul class="pc-tiktok__products" tabindex="0" aria-label="' . esc_attr__( 'Products in this video', 'peptide-core' ) . '">' . $html . '</ul>' . $controls . '</div>';
	}

	private static function tiktok_reference( array $module, string $context ): string {
		$preview = in_array( $context, array( 'editor', 'preview' ), true );
		if ( ! $preview && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return ''; }
		$s = $module['settings']; $cards = ''; $count = 0;
		foreach ( array_slice( self::rows( $module['items'] ?? array() ), 0, 12 ) as $item ) {
			$row = $item['settings']; $url = is_string( $row['url'] ?? null ) ? trim( $row['url'] ) : ''; $video = self::tiktok_reference_id( $url );
			if ( ! $video ) { if ( $preview ) { $count++; $cards .= '<article class="pc-tiktok__card pc-tiktok__placeholder" data-pc-tiktok-card data-module-item="' . esc_attr( (string) ( $item['id'] ?? '' ) ) . '"><div class="pc-tiktok__frame"><p>' . ( '' === $url ? esc_html__( 'Add a full TikTok video URL.', 'peptide-core' ) : esc_html__( 'This video link is unsupported. The saved URL is retained.', 'peptide-core' ) ) . '</p></div></article>'; } continue; }
			$count++; $embed = 'https://www.tiktok.com/player/v1/' . $video . '?music_info=1&description=1&controls=1&autoplay=' . ( $preview ? '0' : '1' ) . '&muted=1&loop=' . ( $preview ? '0' : '1' );
			$cards .= '<article class="pc-tiktok__card" data-pc-tiktok-card data-module-item="' . esc_attr( (string) ( $item['id'] ?? '' ) ) . '"><div class="pc-tiktok__frame"><iframe data-pc-tiktok-src="' . esc_url( $embed ) . '" title="' . esc_attr( sprintf( __( 'TikTok video %d', 'peptide-core' ), $count ) ) . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="autoplay; encrypted-media; fullscreen; picture-in-picture" allowfullscreen></iframe><a class="pc-tiktok__watch" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Watch on TikTok', 'peptide-core' ) . '</a></div><p class="pc-tiktok__status" role="status" aria-live="polite"></p>' . self::tiktok_reference_products( $row['products'] ?? array(), $context ) . '</article>';
		}
		if ( ! $cards && ! $preview ) { return ''; }
		$description = null !== ( $s['descriptionHtml'] ?? null ) ? wp_kses_post( (string) $s['descriptionHtml'] ) : ( '' !== ( $s['description'] ?? '' ) ? '<p>' . nl2br( esc_html( (string) $s['description'] ) ) . '</p>' : '' );
		$head = '' !== ( $s['title'] ?? '' ) ? '<h2>' . esc_html( (string) $s['title'] ) . '</h2>' : ''; $head .= '' !== $description ? '<div class="pc-tiktok__description">' . $description . '</div>' : '';
		$more = ''; $more_url = (string) ( $s['moreUrl'] ?? '' ); $more_label = (string) ( $s['moreLabel'] ?? 'View More' );
		if ( '' !== trim( $more_url ) && '' !== trim( $more_label ) ) {
			$effect = in_array( $s['buttonEffect'] ?? '', array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble' ), true ) ? $s['buttonEffect'] : 'none';
			$button = '<span class="pc-campaign__button pc-campaign__button--' . esc_attr( $effect ) . '" data-pc-campaign-effect="' . esc_attr( $effect ) . '" data-effect-duration="' . max( 1000, min( 5000, (int) ( $s['effectDurationMs'] ?? 1000 ) ) ) . '" data-effect-interval="' . max( 500, min( 5000, (int) ( $s['effectIntervalMs'] ?? 1500 ) ) ) . '">' . esc_html( $more_label ) . '</span>';
			$more = self::interactive( $context ) ? '<a class="pc-tiktok__more" href="' . esc_url( $more_url ) . '">' . $button . '</a>' : '<span class="pc-tiktok__more" aria-disabled="true">' . $button . '</span>';
		}
		$controls = $count > 1 ? '<nav class="pc-tiktok__controls" hidden aria-label="' . esc_attr__( 'TikTok videos', 'peptide-core' ) . '"><button type="button" data-pc-tiktok-prev aria-label="' . esc_attr__( 'Previous videos', 'peptide-core' ) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg></button><button type="button" data-pc-tiktok-next aria-label="' . esc_attr__( 'Next videos', 'peptide-core' ) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m10 6 6 6-6 6"/></svg></button></nav>' : '';
		$dots = ''; if ( $count > 1 ) { foreach ( range( 1, $count ) as $index ) { $dots .= '<button type="button" data-pc-tiktok-dot="' . ( $index - 1 ) . '" aria-label="' . esc_attr( sprintf( __( 'Show video %d', 'peptide-core' ), $index ) ) . '"' . ( 1 === $index ? ' aria-current="true"' : '' ) . '><span></span></button>'; } }
		return '<section class="pc-cb vc-m__inner pc-tiktok" data-pc-tiktok data-context="' . ( $preview ? 'preview' : 'public' ) . '">' . ( $head ? '<header class="pc-tiktok__head">' . $head . '</header>' : '' ) . ( ! $cards ? '<p class="pc-tiktok__empty">' . esc_html__( 'Add TikTok video links to preview this list.', 'peptide-core' ) . '</p>' : '' ) . '<div class="pc-tiktok__list" role="region" aria-label="' . esc_attr__( 'TikTok videos', 'peptide-core' ) . '" tabindex="0">' . $cards . '</div>' . $controls . ( $dots ? '<nav class="pc-tiktok__dots" aria-label="' . esc_attr__( 'Choose a video', 'peptide-core' ) . '" hidden>' . $dots . '</nav>' : '' ) . $more . '</section>';
	}

	/** O06 uses existing media and verified URL snapshots; it never creates partner records. */
	private static function partners_reference( array $module, string $context ): string {
		if ( ! in_array( $context, array( 'editor', 'preview' ), true ) && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return ''; }
		$s = $module['settings']; $preview = ! self::interactive( $context ); $cards = ''; $populated = 0;
		foreach ( array_slice( self::rows( $module['items'] ), 0, 12 ) as $index => $item ) {
			$row = $item['settings']; $caption = null !== ( $row['partnersCaption'] ?? null ) ? (string) $row['partnersCaption'] : (string) ( $row['title'] ?? '' );
			$url = Fields::url( (string) ( null !== ( $row['partnersHref'] ?? null ) ? $row['partnersHref'] : ( $row['url'] ?? '' ) ) );
			$media = Assets::resolve( is_array( $row['image'] ?? null ) ? $row['image'] : null, 'large' );
			$image = $media ? '<img loading="lazy" src="' . esc_url( $media['src'] ) . '" alt="' . esc_attr( $media['alt'] ) . '"' . ( $media['width'] ? ' width="' . (int) $media['width'] . '"' : '' ) . ( $media['height'] ? ' height="' . (int) $media['height'] . '"' : '' ) . ( $media['srcset'] ? ' srcset="' . esc_attr( $media['srcset'] ) . '"' : '' ) . '>' : '';
			$present = '' !== $image || '' !== trim( $caption ); if ( ! $present && ! $preview ) { continue; }
			if ( $present ) { ++$populated; }
			$color = $row['partnersTextColor'] ?? '#1d1f21'; if ( ! is_string( $color ) || ! preg_match( '/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/iD', $color ) ) { $color = '#1d1f21'; }
			$tag = '' !== $url && $present && ! $preview ? 'a' : 'div';
			$natural_name = preg_replace( '/[\p{Z}\s\x{FEFF}]+/u', '', $caption . ( $media['alt'] ?? '' ) ) ?? '';
			$link_name = 'a' === $tag && '' === $natural_name ? ' aria-label="' . esc_attr( sprintf( __( 'Open partner link %d', 'peptide-core' ), $index + 1 ) ) . '"' : '';
			$cards .= '<li class="pc-partners__item" data-module-item="' . esc_attr( (string) ( $item['id'] ?? '' ) ) . '"><' . $tag . ' class="pc-partners__brand"' . ( 'a' === $tag ? ' href="' . esc_url( $url ) . '"' . $link_name : ( $preview && '' !== $url ? ' aria-disabled="true"' : '' ) ) . '>';
			if ( '' !== $image ) { $cards .= '<div class="pc-partners__image">' . $image . '</div>'; }
			elseif ( $preview ) { $cards .= '<div class="pc-partners__placeholder"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8" cy="8" r="1.5"/><path d="m4 18 5-5 3 3 3-4 5 6"/></svg><span class="screen-reader-text">' . esc_html__( 'Choose a partner image', 'peptide-core' ) . '</span></div>'; }
			if ( '' !== $caption ) { $cards .= '<p class="pc-partners__caption" style="color:' . esc_attr( $color ) . '">' . esc_html( $caption ) . '</p>'; }
			$cards .= '</' . $tag . '></li>';
		}
		if ( ! $preview && ! $populated ) { return ''; }
		if ( '' === $cards ) { return '<div class="pc-cb vc-m__inner pc-partners"><p>' . esc_html__( 'Add a brand and choose an image or write its name.', 'peptide-core' ) . '</p></div>'; }
		$description = null !== ( $s['descriptionHtml'] ?? null ) ? wp_kses_post( (string) $s['descriptionHtml'] ) : ( '' !== ( $s['description'] ?? '' ) ? '<p>' . nl2br( esc_html( (string) $s['description'] ) ) . '</p>' : '' );
		$head = '' !== ( $s['title'] ?? '' ) ? '<h2 class="pc-partners__title">' . esc_html( (string) $s['title'] ) . '</h2>' : '';
		$head .= '' !== $description ? '<div class="pc-partners__description">' . $description . '</div>' : '';
		$style = in_array( $s['partnersStyle'] ?? '', array( 'slider', 'grid', 'scroll' ), true ) ? $s['partnersStyle'] : 'slider';
		$ratio = in_array( $s['partnersRatio'] ?? '', array( '1:1', '3:4', '2:3', '1:2' ), true ) ? $s['partnersRatio'] : 'original';
		$fit = in_array( $s['partnersFit'] ?? '', array( 'cover', 'contain', 'fill' ), true ) ? $s['partnersFit'] : 'cover';
		$align = in_array( $s['partnersGridAlign'] ?? '', array( 'left', 'right', 'center' ), true ) ? $s['partnersGridAlign'] : 'center';
		$vars = '--pc-partners-cols:' . max( 2, min( 14, (int) ( $s['partnersDesktopColumns'] ?? 6 ) ) ) . ';--pc-partners-cols-m:' . max( 1, min( 4, (int) ( $s['partnersMobileColumns'] ?? 3 ) ) ) . ';--pc-partners-fit:' . $fit . ';--pc-partners-align:' . array( 'left' => 'flex-start', 'right' => 'flex-end', 'center' => 'center' )[ $align ];
		if ( 'original' !== $ratio ) { $vars .= ';--pc-partners-ratio:' . str_replace( ':', '/', $ratio ); }
		$id = wp_unique_id( 'pc-partners-' );
		$arrow = static fn( string $path ): string => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . $path . '"/></svg>';
		$controls = '<div class="pc-partners__controls" hidden><button type="button" data-pc-partners-prev aria-label="' . esc_attr__( 'Previous partners', 'peptide-core' ) . '" aria-controls="' . esc_attr( $id ) . '">' . $arrow( 'm14 6-6 6 6 6' ) . '</button><button type="button" data-pc-partners-toggle aria-pressed="false" hidden>' . esc_html__( 'Pause scrolling', 'peptide-core' ) . '</button><button type="button" data-pc-partners-next aria-label="' . esc_attr__( 'Next partners', 'peptide-core' ) . '" aria-controls="' . esc_attr( $id ) . '">' . $arrow( 'm10 6 6 6-6 6' ) . '</button></div>';
		$navigation = '<nav class="pc-partners__indicators" aria-label="' . esc_attr__( 'Partner positions', 'peptide-core' ) . '" hidden><div class="pc-partners__dots"></div><div class="pc-partners__scrollbar-wrap"><div class="pc-partners__scrollbar-track" aria-hidden="true"><span class="pc-partners__scrollbar-thumb"></span></div><input class="pc-partners__scrollbar" type="range" min="0" max="1" step="1" value="0" aria-label="' . esc_attr__( 'Partner position', 'peptide-core' ) . '" aria-controls="' . esc_attr( $id ) . '"></div></nav>';
		return '<div class="pc-cb vc-m__inner pc-partners" data-pc-partners data-style="' . esc_attr( $style ) . '" data-ratio="' . esc_attr( $ratio ) . '" data-indicator="' . ( 'scrollbar' === ( $s['partnersMobileIndicator'] ?? '' ) ? 'scrollbar' : 'dots' ) . '" data-seamless="' . ( ! empty( $s['partnersSeamless'] ) ? '1' : '0' ) . '" data-hover-pause="' . ( ! array_key_exists( 'partnersPauseOnHover', $s ) || ! empty( $s['partnersPauseOnHover'] ) ? '1' : '0' ) . '" data-direction="' . ( 'right' === ( $s['partnersDirection'] ?? '' ) ? 'right' : 'left' ) . '" data-seconds="' . max( 1, min( 60, (int) ( $s['partnersSpeedSeconds'] ?? 4 ) ) ) . '" data-preview="' . ( $preview ? '1' : '0' ) . '" style="' . esc_attr( $vars ) . '">' . ( '' !== $head ? '<header class="pc-partners__head">' . $head . '</header>' : '' ) . '<div class="pc-partners__viewport" id="' . esc_attr( $id ) . '"' . ( 'grid' !== $style ? ' tabindex="0" role="region" aria-label="' . esc_attr__( 'Partner list', 'peptide-core' ) . '"' : '' ) . '><ul class="pc-partners__list">' . $cards . '</ul></div>' . $controls . $navigation . '</div>';
	}

	/** Only the explicitly selected O17 presentation changes. The service remains the existing subscriber flow. */
	private static function subscribe_reference( array $module, string $context ): string {
		$preview = ( 'editor' === $context && Renderer::$in_editor ) || ( 'preview' === $context && Templates::previewing_id() > 0 );
		if ( ( 'public' !== $context && ! $preview ) || ( ! $preview && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) ) { return ''; }
		$s = $module['settings']; $styles = array();
		foreach ( array( 'subscribeOverlayColor' => array( 'mask', '#ffffff' ), 'subscribeTitleColor' => array( 'title', '#1d1f21' ), 'subscribeDescriptionColor' => array( 'description', '#666666' ), 'subscribeButtonBackground' => array( 'button-bg', '#1d1f21' ), 'subscribeButtonText' => array( 'button-text', '#ffffff' ) ) as $key => $color ) {
			$value = $s[ $key ] ?? $color[1];
			if ( ! is_string( $value ) || ! preg_match( '/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/iD', $value ) ) { $value = $color[1]; }
			$styles[] = '--pc-subscribe-' . $color[0] . ':' . $value;
		}
		$styles[] = '--pc-subscribe-opacity:' . max( 0, min( 100, (int) ( $s['subscribeOverlayOpacity'] ?? 0 ) ) ) / 100;
		foreach ( array( 'Top' => 'top', 'Right' => 'right', 'Bottom' => 'bottom', 'Left' => 'left' ) as $edge => $name ) {
			$default = in_array( $edge, array( 'Right', 'Left' ), true ) ? 15 : 0;
			$value = $s[ 'subscribePadding' . $edge . 'Px' ] ?? array();
			$value = is_array( $value ) ? $value : array();
			$desktop = max( 0, min( 200, (int) ( $value['desktop'] ?? $default ) ) );
			$phone = max( 0, min( 200, (int) ( $value['mobile'] ?? $desktop ) ) );
			$styles[] = '--pc-subscribe-' . $name . ':' . $desktop . 'px'; $styles[] = '--pc-subscribe-' . $name . '-m:' . $phone . 'px';
		}
		$background = Assets::resolve( $s['subscribeBackgroundImage'] ?? null, 'large' );
		$image = $background ? '<img class="pc-subscribe-reference__background" src="' . esc_url( $background['src'] ) . '" alt="" aria-hidden="true" loading="lazy">' : '';
		$description = null !== ( $s['descriptionHtml'] ?? null ) ? wp_kses_post( (string) $s['descriptionHtml'] ) : ( '' !== ( $s['description'] ?? '' ) ? '<p>' . nl2br( esc_html( (string) $s['description'] ) ) . '</p>' : '' );
		$head = '' !== ( $s['title'] ?? '' ) ? '<h2 class="pc-subscribe-reference__title">' . esc_html( (string) $s['title'] ) . '</h2>' : '';
		$head .= '' !== $description ? '<div class="pc-subscribe-reference__description">' . $description . '</div>' : '';
		$form = class_exists( Subscriptions::class ) ? Subscriptions::render_reference_form( $s, self::interactive( $context ) ) : '';
		if ( '' === $form ) { return ''; }
		return '<section class="pc-cb vc-m__inner pc-subscribe-reference' . ( ! empty( $s['fullWidth'] ) ? ' vc-m__inner--full' : '' ) . '" data-pc-subscribe-block style="' . esc_attr( implode( ';', $styles ) ) . '">' . $image . '<div class="pc-subscribe-reference__mask" aria-hidden="true"></div><div class="pc-subscribe-reference__content">' . ( '' !== $head ? '<header class="pc-subscribe-reference__head">' . $head . '</header>' : '' ) . $form . '</div></section>';
	}

	public static function render( $html, array $module, string $context, string $device ) {
		if ( null === $html && in_array( $module['type'] ?? '', array( 'x_timeline', 'pinterest_profile', 'facebook_page' ), true ) ) { return Social_Feeds::render( $module, $context ); }
		if ( null === $html && 'shop_by_gram' === ( $module['type'] ?? '' ) ) { return Shop_By_Gram::render( $module, $context ); }
		if ( null === $html && 'order_fulfillment' === ( $module['type'] ?? '' ) ) { self::assets(); return Order_Fulfillment::render( $module, $context ); }
		if ( null === $html && 'pricing_cards' === $module['type'] ) { self::assets(); return self::pricing_cards( $module, $context, $device ); }
		if ( null === $html && 'promotion_trio' === ( $module['type'] ?? '' ) ) { self::assets(); return self::promotion_trio( $module, $context, $device ); }
		if ( null === $html && 'product_collection' === $module['type'] && 'collection' === ( $module['settings']['collectionMode'] ?? 'legacy' ) ) { self::assets(); return self::collection_products( $module, $context ); }

		if ( null === $html && 'ai_product_waterfall' === $module['type'] ) { self::assets(); return self::ai_product_waterfall( $module, $context, $device ); }
		if ( null === $html && 'behavior_product_ranking' === $module['type'] ) { self::assets(); return self::behavior_rankings( $module, $context ); }
		if ( null !== $html || ! in_array( $module['type'], self::TYPES, true ) ) { return $html; }
		if ( 'announcement' === $module['type'] && 'reference' === ( $module['settings']['announcementStyle'] ?? 'legacy' ) ) {
			$preview = ( 'editor' === $context && Renderer::$in_editor ) || ( 'preview' === $context && Templates::previewing_id() > 0 );
			if ( ( 'public' !== $context && ! $preview ) || ( ! $preview && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) ) { return ''; }
			self::assets(); return self::announcement_reference( $module, $context );
		}
		if ( 'shipping_discount_list' === $module['type'] && 'reference' === ( $module['settings']['shippingMode'] ?? 'legacy' ) ) {
			$out = Shipping_Discount_Block::render( $module, $context ); if ( '' !== $out ) { self::assets(); } return $out;
		}
		if ( 'subscribe' === $module['type'] && 'reference' === ( $module['settings']['subscribeMode'] ?? 'legacy' ) ) {
			$preview = ( 'editor' === $context && Renderer::$in_editor ) || ( 'preview' === $context && Templates::previewing_id() > 0 );
			if ( ( 'public' !== $context && ! $preview ) || ( ! $preview && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) ) { return ''; }
			self::assets(); return self::subscribe_reference( $module, $context );
		}
		if ( 'partners' === $module['type'] && 'reference' === ( $module['settings']['partnersMode'] ?? 'legacy' ) ) {
			if ( ! in_array( $context, array( 'editor', 'preview' ), true ) && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return ''; }
			self::assets(); return self::partners_reference( $module, $context );
		}
		self::assets(); $type = $module['type']; $s = $module['settings']; $out = ''; $items = self::rows( $module['items'] );
		if ( 'search_terms' === $type && 'reference' === ( $s['searchTermsMode'] ?? 'legacy' ) ) { return self::search_terms_reference( $module, $context ); }
		if ( 'site_search_hero' === $type ) { return self::site_search_hero( $module, $context ); }
		if ( 'youtube_list' === $type && 'reference' === ( $s['youtubeMode'] ?? 'legacy' ) ) { return self::youtube_reference( $module, $context ); }
		if ( 'tiktok_list' === $type ) { return self::tiktok_reference( $module, $context ); }
		if ( 'category_tabs' === $type && 'collections' === ( $s['categoryTabsStyle'] ?? 'legacy' ) ) { return self::collection_tabs( $module, $context ); }
		if ( 'topic_tabs' === $type && 'topics' === ( $s['topicTabsStyle'] ?? 'legacy' ) ) { return self::topic_tabs_showcase( $module, $context ); }
		if ( 'product_carousel' === $type && 'collection' === ( $s['carouselStyle'] ?? 'legacy' ) ) { return self::collection_products( $module, $context, true ); }
		if ( 'product_scroll' === $type && 'marquee' === ( $s['scrollStyle'] ?? 'legacy' ) ) { return self::collection_scroll( $module, $context ); }
		if ( 'category_navigation' === $type && 'collections' === ( $s['navigationStyle'] ?? 'legacy' ) ) { return self::collection_navigation( $module, $context ); }
		if ( 'featured_categories' === $type && 'recommendation' === ( $s['collectionStyle'] ?? 'legacy' ) ) { return self::collection_recommendation( $module, $context ); }
		if ( 'recent_products' === $type && 'history' === ( $s['recentStyle'] ?? 'legacy' ) ) { return self::recent_history( $module, $context ); }
		if ( 'category_list' === $type && 'collections' === ( $s['categoryStyle'] ?? 'legacy' ) ) { return self::collection_list( $module, $context ); }
		if ( 'product_waterfall' === $type && 'collection' === ( $s['waterfallMode'] ?? 'legacy' ) ) { return self::waterfall( $module, $context, $device ); }
		$featured = 'featured_product' === $type && 'detail' === ( $s['displayMode'] ?? 'card' );
		if ( $featured ) {
			$out = self::featured_detail( $module, $device );
		} elseif ( 'product_tabs' === $type ) {
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
			if ( 'showcase' === ( $s['videoStyle'] ?? 'legacy' ) ) { return self::video_products( $module, $context ); }
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
			if ( in_array( $type, array( 'blog_posts', 'blog_categories', 'category_blog', 'campaign_pages' ), true ) && 'reference' === ( $s[ 'campaign_pages' === $type ? 'campaignPagesMode' : ( 'category_blog' === $type ? 'categoryBlogMode' : 'blogMode' ) ] ?? 'legacy' ) ) { return Content_Blocks::reference( $type, $s, $context ); }
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
			if ( $featured && ! self::interactive( $context ) ) { return '<div class="pc-cb vc-m__inner pc-cb--featured-detail"><p class="pc-featured__empty">' . esc_html__( 'Choose a published product to preview its real images, specifications and purchasing options.', 'peptide-core' ) . '</p></div>'; }
			if ( 'coupon_list' === $type && ! self::interactive( $context ) ) { return '<div class="pc-cb vc-m__inner"><div class="pc-campaign__empty"><strong>选择优惠券，预览真实优惠</strong><p>请添加优惠券并选择可公开的记录；未选择、已停用、已过期或当前顾客不适用的优惠券不会展示。</p>' . ( current_user_can( 'edit_shop_coupons' ) ? '<a href="' . esc_url( admin_url( 'edit.php?post_type=shop_coupon' ) ) . '" target="_blank" rel="noopener noreferrer">管理优惠券</a>' : '' ) . '</div></div>'; }
			if ( $campaign && ! self::interactive( $context ) ) { return '<div class="pc-cb vc-m__inner"><div class="pc-campaign__empty"><strong>选择活动，预览真实优惠</strong><p>请在设置中选择一个公开且适用的活动。未选择、已停用、已结束或当前顾客不适用的活动不会对买家展示。</p>' . ( current_user_can( 'edit_shop_coupons' ) ? '<a href="' . esc_url( admin_url( 'admin.php?page=pc-promotions' ) ) . '" target="_blank" rel="noopener noreferrer">管理满减活动</a>' : '' ) . '</div></div>'; }
			return 'editor' === $context ? '<div class="pc-cb"><p>' . esc_html__( 'No eligible published content. Configure this block or add real store records.', 'peptide-core' ) . '</p></div>' : '';
		}
		$classes = 'pc-cb vc-m__inner' . ( $featured ? ' pc-cb--featured-detail woocommerce' : '' ) . ( $campaign ? ' pc-cb--campaigns' : '' ) . ( 'product_tabs' === $type ? ' pc-cb--product-tabs' : '' ) . ( ( $campaign || 'product_tabs' === $type ) && ! empty( $s['fullWidth'] ) ? ' vc-m__inner--full' : '' );
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
