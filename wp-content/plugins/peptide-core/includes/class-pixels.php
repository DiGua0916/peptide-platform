<?php
/**
 * 广告像素（PIXELS-01），对照 Shopname 的“系统设置 → 广告像素”。把网站上的浏览、加购、结账、下单等事件回传给广告平台。
 *
 * - 平台：Facebook、Google Analytics、Google Ads、Google Tag Manager、Bing、Yahoo、Pinterest、TikTok、Snapchat、快手、
 *   Taboola、BIGO Ads、VKontakte、Yandex Metrica、Outbrain、Criteo、X（Twitter）、MediaGo、NewsBreak、AppLovin、SmartNews、
 *   Reddit、OpenAI。每个平台可以填多个像素 ID（不同投手各用各的），可以限定只在某个域名上回传。
 * - 事件都按 WooCommerce 的真实数据生成（金额、币种、商品 ID、件数），统一成一套事件名，再由 assets/pixels.js 换成各平台的写法：
 *   page_view、view_home、view_list、view_item、search、view_cart、add_to_cart、begin_checkout、add_payment_info、
 *   place_order、purchase、sign_up、login。
 *   - 页面事件（浏览、查看商品、搜索、购物车、发起结账、下单）在页面输出时生成；
 *   - 加购、注册、登录发生在请求里，先放进购物会话的队列，下一次页面、Store API 购物车响应或 wc-ajax=pc_px_queue 时交给浏览器；
 *   - 下单：订单成功页输出 place_order + purchase，每个订单只输出一次。
 * - 服务端回传：Facebook Conversions API、TikTok Events API（填了 access token 的像素），见 Pixels\Server。
 *   和浏览器用同一个 event_id，平台自动去重。购买事件在订单第一次变成处理中 / 已完成 / 保留时由服务端发送。
 * - Facebook 的选项：销售额使用商品金额、Content ID 类型（SPU / SKU）、Content Name 类型、上传自定义事件、购买事件只回传首单、
 *   在线订单回传比例。其他平台有“销售额使用商品金额”。
 * - 隐私：默认遵守浏览器的 GPC（拒绝出售 / 分享）信号；任何前台地址加 ?pc_px_optout=1 可以让这个浏览器不再回传（=0 恢复），
 *   可以做成页脚的 “Do Not Sell or Share My Personal Information” 链接。员工访问默认不回传。
 *
 * 设置存在选项 peptide_core_pixels（不自动加载，含 access token，不输出到前台）。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Pixels {

	public const OPTION      = 'peptide_core_pixels';
	public const QUEUE_KEY   = 'pc_px_queue';
	public const OPTOUT      = 'pc_px_optout';
	public const ORDER_SHOWN = '_pc_px_browser';
	public const MAX_QUEUE   = 20;

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'handle_optout' ) );
		add_action( 'init', array( self::class, 'remember_click_ids' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 30 );
		add_action( 'woocommerce_add_to_cart', array( self::class, 'on_add_to_cart' ), 50, 6 );
		add_action( 'woocommerce_created_customer', array( self::class, 'on_sign_up' ), 20, 1 );
		add_action( 'wp_login', array( self::class, 'on_login' ), 20, 2 );
		add_action( 'wc_ajax_pc_px_queue', array( self::class, 'ajax_queue' ) );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_order_status' ), 20, 4 );
		Pixels\Server::register_hooks();
	}

	// ---------- 平台 ----------

	/**
	 * 平台：名称、获取方法链接、填写方式（pixels = 像素表格；ids = 多个 ID 每行一个；yahoo / gads 特殊）、
	 * 是否能填服务端 token、统一事件 => 平台事件名。
	 */
	public static function platforms(): array {
		$std = array( 'page_view' => 'PageView', 'view_item' => 'ViewContent', 'search' => 'Search', 'add_to_cart' => 'AddToCart', 'begin_checkout' => 'InitiateCheckout', 'purchase' => 'Purchase' );
		return array(
			'facebook'  => array( 'label' => 'Facebook', 'kind' => 'pixels', 'token' => true, 'help' => 'https://www.facebook.com/business/help/952192354843755', 'events' => $std + array( 'add_payment_info' => 'AddPaymentInfo', 'sign_up' => 'CompleteRegistration' ) ),
			'ga4'       => array( 'label' => 'Google Analytics', 'kind' => 'ids', 'placeholder' => 'G-EP1021BD77', 'help' => 'https://support.google.com/analytics/answer/9539598', 'events' => array( 'page_view' => 'page_view', 'view_list' => 'view_item_list', 'view_item' => 'view_item', 'search' => 'search', 'add_to_cart' => 'add_to_cart', 'view_cart' => 'view_cart', 'begin_checkout' => 'begin_checkout', 'add_payment_info' => 'add_payment_info', 'purchase' => 'purchase', 'sign_up' => 'sign_up', 'login' => 'login' ) ),
			'gads'      => array( 'label' => 'Google Ads', 'kind' => 'gads', 'placeholder' => 'AW-872163871231', 'help' => 'https://support.google.com/google-ads/answer/6095821', 'events' => array( 'page_view' => 'page_view', 'view_item' => 'view_item', 'search' => 'search', 'add_to_cart' => 'add_to_cart', 'begin_checkout' => 'begin_checkout', 'purchase' => 'purchase' ) ),
			'gtm'       => array( 'label' => 'Google Tag Manager', 'kind' => 'pixels', 'placeholder' => 'GTM-XXXXXXX', 'help' => 'https://support.google.com/tagmanager/answer/6103696', 'events' => array( 'page_view' => 'page_view', 'view_item' => 'view_item', 'search' => 'search', 'add_to_cart' => 'add_to_cart', 'begin_checkout' => 'begin_checkout', 'purchase' => 'purchase' ) ),
			'bing'      => array( 'label' => 'Bing', 'kind' => 'pixels', 'placeholder' => '343123456', 'help' => 'https://help.ads.microsoft.com/#apex/ads/en/56682/2', 'events' => array( 'page_view' => 'page_view', 'begin_checkout' => 'begin_checkout', 'search' => 'search', 'view_item' => 'view_item', 'sign_up' => 'sign_up', 'login' => 'login', 'add_to_cart' => 'add_to_cart', 'purchase' => 'purchase' ) ),
			'yahoo'     => array( 'label' => 'Yahoo', 'kind' => 'yahoo', 'help' => 'https://help.yahooinc.com/dsp/docs/dot-tags', 'events' => array( 'view_item' => 'ViewProduct', 'add_to_cart' => 'AddToCart', 'purchase' => 'Purchase' ) ),
			'pinterest' => array( 'label' => 'Pinterest', 'kind' => 'pixels', 'placeholder' => '2612345678901', 'help' => 'https://help.pinterest.com/en/business/article/install-the-pinterest-tag', 'events' => array( 'page_view' => 'page', 'view_list' => 'viewcategory', 'view_item' => 'pagevisit', 'search' => 'search', 'sign_up' => 'signup', 'add_to_cart' => 'addtocart', 'purchase' => 'checkout' ) ),
			'tiktok'    => array( 'label' => 'TikTok', 'kind' => 'pixels', 'token' => true, 'placeholder' => 'C4A1B2C3D4E5F6G7H8I9', 'help' => 'https://ads.tiktok.com/help/article/get-started-pixel', 'events' => array( 'page_view' => 'Pageview', 'view_item' => 'ViewContent', 'search' => 'Search', 'add_to_cart' => 'AddToCart', 'begin_checkout' => 'InitiateCheckout', 'add_payment_info' => 'AddPaymentInfo', 'sign_up' => 'CompleteRegistration', 'purchase' => 'Purchase' ) ),
			'snapchat'  => array( 'label' => 'Snapchat', 'kind' => 'pixels', 'help' => 'https://businesshelp.snapchat.com/s/article/pixel-website-install', 'events' => array( 'page_view' => 'PAGE_VIEW', 'view_item' => 'VIEW_CONTENT', 'search' => 'SEARCH', 'add_to_cart' => 'ADD_CART', 'begin_checkout' => 'START_CHECKOUT', 'sign_up' => 'SIGN_UP', 'purchase' => 'PURCHASE' ) ),
			'kwai'      => array( 'label' => __( 'Kwai', 'peptide-core' ), 'kind' => 'ids', 'help' => 'https://docs.qingque.cn/d/home/eZQCNZ1wBFnEpQEAMmOhfoVwI?identityId=1pTerwwOjbg', 'note' => __( 'Amounts are only sent in BRL, IDR or USD (Kwai supports no other currency).', 'peptide-core' ), 'events' => array( 'page_view' => 'page', 'view_item' => 'contentView', 'search' => 'search', 'add_to_cart' => 'addToCart', 'begin_checkout' => 'initiatedCheckout', 'add_payment_info' => 'addPaymentInfo', 'place_order' => 'placeOrder', 'sign_up' => 'completeRegistration', 'purchase' => 'purchase' ) ),
			'taboola'   => array( 'label' => 'Taboola', 'kind' => 'ids', 'help' => 'https://help.taboola.com/hc/en-us/articles/115006880507', 'events' => array( 'page_view' => 'page_view', 'view_item' => 'view_content', 'add_to_cart' => 'add_to_cart', 'add_payment_info' => 'add_payment_info', 'search' => 'search', 'begin_checkout' => 'start_checkout', 'sign_up' => 'complete_registration', 'purchase' => 'make_purchase' ) ),
			'bigo'      => array( 'label' => 'BIGO Ads', 'kind' => 'ids', 'help' => 'https://bigoads.com/help/detail?id=135&moduleId=11&currentLan=EN', 'note' => __( 'A page loads only one BIGO pixel, so only the first ID is used. The browser pixel does not send amounts.', 'peptide-core' ), 'events' => array( 'page_view' => 'page_view', 'view_item' => 'ec_detail_view', 'add_to_cart' => 'ec_add_cart', 'place_order' => 'ec_order', 'purchase' => 'ec_purchase', 'sign_up' => 'ec_register' ) ),
			// VK：结账和搜索不是 VK 的标准事件，不发。
			'vk'        => array( 'label' => 'VKontakte', 'kind' => 'ids', 'placeholder' => '3123456', 'help' => 'https://ads.vk.ru/en/help/articles/pixel', 'events' => array( 'page_view' => 'pageView', 'view_item' => 'viewProduct', 'add_to_cart' => 'addToCart', 'purchase' => 'purchase' ) ),
			'yandex'    => array( 'label' => 'Yandex Metrica', 'kind' => 'ids', 'help' => 'https://yandex.com/support/metrica/general/creating-counter.html', 'events' => array( 'page_view' => 'pageview', 'view_item' => 'detail', 'add_to_cart' => 'add', 'begin_checkout' => 'InitiateCheckout', 'purchase' => 'purchase', 'search' => 'Search', 'sign_up' => 'CompleteRegistration' ) ),
			'outbrain'  => array( 'label' => 'Outbrain', 'kind' => 'ids', 'help' => 'https://www.outbrain.com/help/advertisers/outbrain-pixel/', 'events' => array( 'page_view' => 'PAGE_VIEW', 'view_item' => 'Content View', 'search' => 'Search', 'add_to_cart' => 'Add to Cart', 'begin_checkout' => 'Checkout', 'purchase' => 'Purchase' ) ),
			'criteo'    => array( 'label' => 'Criteo', 'kind' => 'ids', 'help' => 'https://help.criteo.com/kb/guide/en/all-articles/onetag-installation-guide-HB1Y3wLwrV/', 'events' => array( 'page_view' => 'viewPage', 'view_home' => 'viewHome', 'view_list' => 'viewList', 'view_item' => 'viewItem', 'add_to_cart' => 'addToCart', 'view_cart' => 'viewBasket', 'purchase' => 'trackTransaction', 'search' => 'viewSearchResult' ) ),
			// X：基础代码记录访问；其他事件要在 X 广告管理器里为每个事件建事件 ID（tw-像素-事件），填在“事件 ID”里。
			'twitter'   => array( 'label' => 'X (Twitter)', 'kind' => 'ids', 'placeholder' => 'o1abc', 'help' => 'https://help.x.com/en/business-and-advertising/conversion-tracking-for-websites', 'note' => __( 'The base code records visits. For the other events, create each event in X Ads Manager (Events Manager → Add event → Define with code) and fill in its event ID below; events without an ID are not sent.', 'peptide-core' ), 'events' => array( 'page_view' => 'Page view', 'view_item' => 'Content view', 'search' => 'Search', 'add_to_cart' => 'Add to cart', 'begin_checkout' => 'Checkout initiated', 'add_payment_info' => 'Added payment info', 'sign_up' => 'Lead', 'purchase' => 'Purchase' ) ),
			'mediago'   => array( 'label' => 'MediaGo', 'kind' => 'ids', 'help' => 'https://business.mediago.io/platform/helpCenter/conversionTrackingSection', 'note' => __( 'Fill in the MediaGo account ID. In MediaGo, create the conversions with the Google Tag Manager method and the same conversion types; purchases are sent as the important conversion.', 'peptide-core' ), 'events' => array( 'page_view' => 'pageview', 'view_item' => 'view_content', 'search' => 'search', 'add_to_cart' => 'add_to_cart', 'begin_checkout' => 'start_checkout', 'add_payment_info' => 'add_payment_info', 'sign_up' => 'complete_registration', 'purchase' => 'purchase' ) ),
			// NewsBreak：官方说明里没有搜索事件；一个页面只认一个像素 ID（用第一个），金额只接受美元。
			'newsbreak' => array( 'label' => 'NewsBreak', 'kind' => 'pixels', 'placeholder' => 'ID-XXXXXXXXXXXXXXXXXXX', 'help' => 'https://adhelp.newsbreak.com/hc/en-us/articles/42484984988301', 'note' => __( 'A page loads only one NewsBreak pixel, so only the first one that matches the domain is used.', 'peptide-core' ), 'events' => array( 'page_view' => 'pageload', 'view_item' => 'view_content', 'sign_up' => 'complete_registration', 'add_to_cart' => 'add_to_cart', 'add_payment_info' => 'add_payment_info', 'begin_checkout' => 'initiate_checkout', 'place_order' => 'place_an_order', 'purchase' => 'complete_payment' ) ),
			// AppLovin（Axon Pixel）：填的是 Event Key，一个页面只认一个（用第一个）。
			'applovin'  => array( 'label' => 'AppLovin', 'kind' => 'pixels', 'help' => 'https://support.applovin.com/en/growth/promoting-your-websites/track-and-optimize/axon-pixel-native-js', 'note' => __( 'Fill in the Axon Event Key. A page loads only one, so only the first one that matches the domain is used.', 'peptide-core' ), 'events' => array( 'page_view' => 'page_view', 'view_item' => 'view_item', 'sign_up' => 'sign_up', 'login' => 'login', 'search' => 'search', 'add_to_cart' => 'add_to_cart', 'view_cart' => 'view_cart', 'begin_checkout' => 'begin_checkout', 'add_payment_info' => 'add_payment_info', 'purchase' => 'purchase' ) ),
			// SmartNews：没有“下单”标准事件。
			'smartnews' => array( 'label' => 'SmartNews', 'kind' => 'pixels', 'help' => 'https://help-ads.smartnews.com/en/item-3056/', 'events' => array( 'page_view' => 'PageView', 'view_item' => 'ViewContent', 'sign_up' => 'CompleteRegistration', 'login' => 'Login', 'add_to_cart' => 'AddToCart', 'view_cart' => 'VisitCart', 'search' => 'Search', 'begin_checkout' => 'InitiateCheckout', 'add_payment_info' => 'AddPaymentInfo', 'purchase' => 'Purchase' ) ),
			'reddit'    => array( 'label' => 'Reddit', 'kind' => 'pixels', 'placeholder' => 'a2_abcdefghijk', 'help' => 'https://business.reddithelp.com/s/article/Install-the-Reddit-Pixel-on-your-website', 'events' => array( 'page_view' => 'PageVisit', 'view_item' => 'ViewContent', 'search' => 'Search', 'add_to_cart' => 'AddToCart', 'begin_checkout' => 'InitiateCheckout', 'place_order' => 'PlaceAnOrder', 'sign_up' => 'SignUp', 'purchase' => 'Purchase' ) ),
			'openai'    => array( 'label' => 'OpenAI', 'kind' => 'pixels', 'help' => 'https://developers.openai.com/ads/measurement-pixel', 'events' => array( 'page_view' => 'page_viewed', 'view_item' => 'contents_viewed', 'add_to_cart' => 'items_added', 'begin_checkout' => 'checkout_started', 'purchase' => 'order_created', 'sign_up' => 'registration_completed' ) ),
		);
	}

	/** 统一事件的中文说明（后台显示“PageView（浏览页面）”）。 */
	public static function event_labels(): array {
		return array(
			'page_view'        => __( 'page view', 'peptide-core' ),
			'view_home'        => __( 'home page', 'peptide-core' ),
			'view_list'        => __( 'view category', 'peptide-core' ),
			'view_item'        => __( 'view product', 'peptide-core' ),
			'search'           => __( 'search', 'peptide-core' ),
			'view_cart'        => __( 'view cart', 'peptide-core' ),
			'add_to_cart'      => __( 'add to cart', 'peptide-core' ),
			'begin_checkout'   => __( 'checkout', 'peptide-core' ),
			'add_payment_info' => __( 'add payment info', 'peptide-core' ),
			'place_order'      => __( 'place an order', 'peptide-core' ),
			'purchase'         => __( 'purchase', 'peptide-core' ),
			'sign_up'          => __( 'sign up', 'peptide-core' ),
			'login'            => __( 'login', 'peptide-core' ),
		);
	}

	// ---------- 设置 ----------

	public static function defaults(): array {
		$platforms = array();
		foreach ( self::platforms() as $key => $p ) {
			$platforms[ $key ] = array(
				'pixels'      => array(), // [{id, note, domain, token}]
				'items_value' => false,   // 销售额使用商品金额（不含运费和税）
			);
		}
		$platforms['facebook'] += array(
			'content_id'       => 'spu',   // spu / sku
			'content_name'     => 'name',  // name / internal / category / spu
			'custom_events'    => true,
			'first_order_only' => false,
			'ratio'            => 100,
		);
		$platforms['gads'] += array( 'purchase' => array(), 'labels' => array() ); // labels：统一事件 => AW-xxx/label（每行一个）
		$platforms['gtm']  += array( 'user_data' => false );
		$platforms['yahoo'] += array( 'project_id' => '' );
		$platforms['twitter'] += array( 'event_ids' => array() ); // 统一事件 => [ tw-像素-事件, … ]
		return array(
			'gpc'        => true,   // 遵守浏览器的 GPC 信号
			'staff'      => false,  // 员工访问也回传（测试用）
			'timing'     => 'load', // load：页面加载后；interaction：第一次互动后（页面更快）
			'match'      => true,   // 高级匹配：已知的邮箱 / 电话加密后回传
			'test_code'  => '',     // Facebook 测试事件代码（只用于服务端）
			'server_pv'  => false,  // 服务端也回传 PageView
			'platforms'  => $platforms,
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$d     = self::defaults();
		$s     = array_merge( $d, array_intersect_key( $saved, $d ) );
		foreach ( $d['platforms'] as $key => $pd ) {
			$s['platforms'][ $key ] = array_merge( $pd, is_array( $saved['platforms'][ $key ] ?? null ) ? $saved['platforms'][ $key ] : array() );
		}
		return $s;
	}

	/** 一个像素 ID 的清理：只留字母、数字和 - _ . / :（Google Ads 的转化是 AW-123/abc）。 */
	public static function clean_id( $v ): string {
		$v = is_scalar( $v ) ? trim( (string) $v ) : '';
		return substr( (string) preg_replace( '/[^A-Za-z0-9_\-\.\/:]/', '', $v ), 0, 120 );
	}

	/** 域名：只留主机名（不含 www.），空 = 所有域名。 */
	public static function clean_domain( $v ): string {
		$v    = is_scalar( $v ) ? strtolower( trim( (string) $v ) ) : '';
		$host = wp_parse_url( str_contains( $v, '://' ) ? $v : 'https://' . $v, PHP_URL_HOST );
		return is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : '';
	}

	/**
	 * 清理后台提交的设置。$old 用来保留没有重新填写的 access token。
	 *
	 * @return array{0:array,1:string[]}
	 */
	public static function clean( array $raw, ?array $old = null ): array {
		$old    = $old ?? self::settings();
		$d      = self::defaults();
		$errors = array();
		$s      = array(
			'gpc'       => ! empty( $raw['gpc'] ),
			'staff'     => ! empty( $raw['staff'] ),
			'timing'    => 'interaction' === ( $raw['timing'] ?? '' ) ? 'interaction' : 'load',
			'match'     => ! empty( $raw['match'] ),
			'test_code' => self::clean_id( $raw['test_code'] ?? '' ),
			'server_pv' => ! empty( $raw['server_pv'] ),
		);
		foreach ( self::platforms() as $key => $def ) {
			$in  = is_array( $raw['platforms'][ $key ] ?? null ) ? $raw['platforms'][ $key ] : array();
			$was = $old['platforms'][ $key ] ?? $d['platforms'][ $key ];
			$p   = $d['platforms'][ $key ];
			$p['items_value'] = ! empty( $in['items_value'] );
			// 像素：表格（pixels[] 每行 id/note/domain/token）或者每行一个 ID 的文本框（ids）。
			$rows = array();
			if ( isset( $in['ids'] ) && ! isset( $in['pixels'] ) ) {
				foreach ( preg_split( '/[\r\n,]+/', (string) $in['ids'] ) as $line ) {
					$rows[] = array( 'id' => $line );
				}
			} else {
				$rows = is_array( $in['pixels'] ?? null ) ? array_values( $in['pixels'] ) : array();
			}
			$seen = array();
			foreach ( $rows as $row ) {
				$id = self::clean_id( is_array( $row ) ? ( $row['id'] ?? '' ) : $row );
				if ( '' === $id || isset( $seen[ strtolower( $id ) ] ) ) {
					continue;
				}
				$seen[ strtolower( $id ) ] = true;
				$token                     = '';
				if ( ! empty( $def['token'] ) && is_array( $row ) ) {
					$token = trim( (string) ( $row['token'] ?? '' ) );
					if ( ! empty( $row['clear_token'] ) ) {
						$token = '';
					} elseif ( '' === $token ) {
						// 没有重新填写时保留原来的 token（页面上只显示前几位）。
						foreach ( (array) $was['pixels'] as $wp ) {
							if ( strtolower( (string) ( $wp['id'] ?? '' ) ) === strtolower( $id ) ) {
								$token = (string) ( $wp['token'] ?? '' );
							}
						}
					}
					$token = substr( (string) preg_replace( '/[^A-Za-z0-9_\-\.]/', '', $token ), 0, 512 );
				}
				$p['pixels'][] = array(
					'id'     => $id,
					'note'   => is_array( $row ) ? substr( sanitize_text_field( (string) ( $row['note'] ?? '' ) ), 0, 100 ) : '',
					'domain' => is_array( $row ) ? self::clean_domain( $row['domain'] ?? '' ) : '',
					'token'  => $token,
				);
			}
			$p['pixels'] = array_slice( $p['pixels'], 0, 20 );
			if ( 'facebook' === $key ) {
				$p['content_id']       = 'sku' === ( $in['content_id'] ?? '' ) ? 'sku' : 'spu';
				$p['content_name']     = in_array( $in['content_name'] ?? '', array( 'internal', 'category', 'spu' ), true ) ? (string) $in['content_name'] : 'name';
				$p['custom_events']    = ! empty( $in['custom_events'] );
				$p['first_order_only'] = ! empty( $in['first_order_only'] );
				$ratio                 = is_numeric( $in['ratio'] ?? null ) ? (int) $in['ratio'] : 100;
				if ( $ratio < 1 || $ratio > 100 ) {
					$errors[] = __( 'The online order report ratio must be between 1% and 100%.', 'peptide-core' );
				}
				$p['ratio'] = max( 1, min( 100, $ratio ) );
			}
			if ( 'gads' === $key ) {
				$p['purchase'] = array();
				foreach ( preg_split( '/[\r\n,]+/', (string) ( $in['purchase'] ?? '' ) ) as $line ) {
					$line = self::clean_id( $line );
					if ( '' !== $line ) {
						if ( ! preg_match( '#^AW-[0-9]+/[A-Za-z0-9_\-]+$#', $line ) ) {
							/* translators: %s: conversion id */
							$errors[] = sprintf( __( 'Google Ads purchase conversion %s should look like AW-123456789/AbCdEf.', 'peptide-core' ), $line );
							continue;
						}
						$p['purchase'][] = $line;
					}
				}
				$p['labels'] = array();
				foreach ( array( 'page_view', 'view_item', 'search', 'add_to_cart', 'begin_checkout' ) as $ev ) {
					$v = self::clean_id( $in['labels'][ $ev ] ?? '' );
					if ( '' !== $v && preg_match( '#^AW-[0-9]+/[A-Za-z0-9_\-]+$#', $v ) ) {
						$p['labels'][ $ev ] = $v;
					}
				}
			}
			if ( 'gtm' === $key ) {
				$p['user_data'] = ! empty( $in['user_data'] );
			}
			if ( 'yahoo' === $key ) {
				$p['project_id'] = self::clean_id( $in['project_id'] ?? '' );
				if ( $p['pixels'] && '' === $p['project_id'] ) {
					$errors[] = __( 'Yahoo needs both the pixel ID and the project ID.', 'peptide-core' );
				}
			}
			if ( 'twitter' === $key ) {
				$p['event_ids'] = array();
				foreach ( array_keys( $def['events'] ) as $ev ) {
					foreach ( preg_split( '/[\s,]+/', (string) ( $in['event_ids'][ $ev ] ?? '' ) ) as $v ) {
						$v = self::clean_id( $v );
						if ( '' === $v ) {
							continue;
						}
						if ( ! preg_match( '/^tw-[a-z0-9]+-[a-z0-9]+$/i', $v ) ) {
							/* translators: %s: event id */
							$errors[] = sprintf( __( 'X event ID %s should look like tw-o1abc-o2def.', 'peptide-core' ), $v );
							continue;
						}
						$p['event_ids'][ $ev ][] = $v;
					}
				}
			}
			foreach ( $p['pixels'] as $px ) {
				$bad = ( 'ga4' === $key && ! preg_match( '/^G-[A-Z0-9]+$/i', $px['id'] ) )
					|| ( 'gads' === $key && ! preg_match( '/^AW-[0-9]+$/i', $px['id'] ) )
					|| ( 'gtm' === $key && ! preg_match( '/^GTM-[A-Z0-9]+$/i', $px['id'] ) );
				if ( $bad ) {
					/* translators: 1: platform, 2: id */
					$errors[] = sprintf( __( '%1$s ID %2$s does not look right. Check the example in the box.', 'peptide-core' ), $def['label'], $px['id'] );
				}
			}
			$s['platforms'][ $key ] = $p;
		}
		return array( $s, array_values( array_unique( $errors ) ) );
	}

	public static function save( array $raw ): array {
		[ $s, $errors ] = self::clean( $raw );
		update_option( self::OPTION, $s, false );
		return $errors;
	}

	/** 有没有填过任何像素。 */
	public static function any( ?array $s = null ): bool {
		$s = $s ?? self::settings();
		foreach ( $s['platforms'] as $p ) {
			if ( $p['pixels'] ) {
				return true;
			}
		}
		return false;
	}

	// ---------- 谁不回传 ----------

	public static function handle_optout(): void {
		if ( is_admin() || ! isset( $_GET[ self::OPTOUT ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$on = '0' !== (string) $_GET[ self::OPTOUT ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
		if ( ! headers_sent() ) {
			setcookie( self::OPTOUT, $on ? '1' : '', array( 'expires' => $on ? time() + YEAR_IN_SECONDS : time() - HOUR_IN_SECONDS, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ) );
		}
		$_COOKIE[ self::OPTOUT ] = $on ? '1' : '';
		if ( $on && function_exists( 'wc_add_notice' ) && function_exists( 'WC' ) && WC()->session ) {
			wc_add_notice( __( 'This browser will no longer share your activity with advertising partners.', 'peptide-core' ), 'success' );
		}
	}

	/** 这个请求要不要回传：没有像素、员工、拒绝分享（GPC 或退出）时不回传。 */
	public static function allowed( ?array $s = null ): bool {
		$s = $s ?? self::settings();
		if ( ! self::any( $s ) ) {
			return false;
		}
		if ( ! empty( $_COOKIE[ self::OPTOUT ] ) ) {
			return false;
		}
		if ( $s['gpc'] && '1' === (string) ( $_SERVER['HTTP_SEC_GPC'] ?? '' ) ) {
			return false;
		}
		if ( ! $s['staff'] && ( Access::is_staff( get_current_user_id() ) || current_user_can( 'manage_woocommerce' ) ) ) {
			return false;
		}
		return true;
	}

	/** 从广告点进来时，链接上的点击编号存成 Cookie，服务端回传要用（fbclid → _fbc，ttclid → pc_ttclid）。 */
	public static function remember_click_ids(): void {
		if ( is_admin() || headers_sent() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$opts = array( 'expires' => time() + 90 * DAY_IN_SECONDS, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'secure' => is_ssl(), 'samesite' => 'Lax' );
		if ( ! empty( $_GET['fbclid'] ) ) {
			$fbc = 'fb.1.' . (int) round( microtime( true ) * 1000 ) . '.' . sanitize_text_field( wp_unslash( $_GET['fbclid'] ) );
			setcookie( '_fbc', $fbc, $opts );
			$_COOKIE['_fbc'] = $fbc;
		}
		if ( ! empty( $_GET['ttclid'] ) ) {
			$tt = sanitize_text_field( wp_unslash( $_GET['ttclid'] ) );
			setcookie( 'pc_ttclid', $tt, $opts + array( 'httponly' => true ) );
			$_COOKIE['pc_ttclid'] = $tt;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	// ---------- 事件 ----------

	public static function event_id( string $prefix = 'e' ): string {
		return $prefix . '_' . str_replace( '-', '', wp_generate_uuid4() );
	}

	/** 商品在事件里的样子。$product 可以是规格；名称、SPU、分类按父商品。 */
	public static function item( \WC_Product $product, int $qty = 1, ?float $price = null ): array {
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		$parent = $parent ? $parent : $product;
		$cats   = wc_get_product_cat_ids( $parent->get_id() );
		$cat    = $cats ? get_term( (int) $cats[0], 'product_cat' ) : null;
		$spu    = (string) $parent->get_meta( '_pc_spu' );
		$sku    = (string) $product->get_sku();
		return array(
			'id'       => (string) $product->get_id(),
			'spu'      => '' !== $spu ? $spu : (string) $parent->get_id(),
			'sku'      => '' !== $sku ? $sku : (string) $product->get_id(),
			'name'     => html_entity_decode( $parent->get_name() ),
			'internal' => (string) $parent->get_slug(),
			'category' => $cat instanceof \WP_Term ? html_entity_decode( $cat->name ) : '',
			'variant'  => $product->get_parent_id() ? html_entity_decode( wc_get_formatted_variation( $product, true, false, false ) ) : '',
			'price'    => round( null !== $price ? $price : (float) wc_get_price_excluding_tax( $product ), 2 ),
			'quantity' => max( 1, $qty ),
		);
	}

	/** 已知的顾客资料（加密后才会发出去）：邮箱、电话、顾客编号。 */
	/**
	 * 电话号码：只留数字并带国家区号，例如 (555) 123-4567 + US => 15551234567。
	 * 没写 + 或 00 的号码按账单国家（没有时用店铺所在国家）补区号。
	 */
	public static function phone_digits( string $phone, string $country = '' ): string {
		$phone  = trim( $phone );
		$digits = (string) preg_replace( '/\D+/', '', $phone );
		if ( strlen( $digits ) < 7 ) {
			return '';
		}
		if ( str_starts_with( $phone, '+' ) ) {
			return $digits;
		}
		if ( str_starts_with( $digits, '00' ) ) {
			return substr( $digits, 2 );
		}
		$countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries : null;
		if ( ! $countries ) {
			return $digits;
		}
		$code = (string) preg_replace( '/\D+/', '', (string) $countries->get_country_calling_code( '' !== $country ? $country : $countries->get_base_country() ) );
		if ( '' === $code ) {
			return $digits;
		}
		if ( '1' === $code ) {
			return 10 === strlen( $digits ) ? '1' . $digits : $digits; // 美国 / 加拿大：10 位本地号码。
		}
		return str_starts_with( $digits, '0' ) || strlen( $digits ) < 10 || ! str_starts_with( $digits, $code ) ? $code . ltrim( $digits, '0' ) : $digits;
	}

	public static function user_data( ?\WC_Order $order = null ): array {
		$email   = '';
		$phone   = '';
		$country = '';
		$id      = '';
		if ( $order ) {
			$email   = (string) $order->get_billing_email();
			$phone   = (string) $order->get_billing_phone();
			$country = (string) $order->get_billing_country();
			$id      = $order->get_customer_id() ? (string) $order->get_customer_id() : '';
		} else {
			$user = wp_get_current_user();
			if ( $user && $user->ID ) {
				$email = (string) $user->user_email;
				$id    = (string) $user->ID;
			}
			if ( function_exists( 'WC' ) && WC()->customer ) {
				$email = '' !== (string) WC()->customer->get_billing_email() ? (string) WC()->customer->get_billing_email() : $email;
				$phone   = (string) WC()->customer->get_billing_phone();
				$country = (string) WC()->customer->get_billing_country();
			}
		}
		$out = array();
		if ( '' !== $email && is_email( $email ) ) {
			$out['em'] = hash( 'sha256', strtolower( trim( $email ) ) );
		}
		$digits = self::phone_digits( $phone, $country );
		if ( '' !== $digits ) {
			$out['ph']      = hash( 'sha256', $digits );
			$out['ph_plus'] = hash( 'sha256', '+' . $digits );
		}
		if ( '' !== $id ) {
			$out['external_id'] = hash( 'sha256', $id );
		}
		return $out;
	}

	/** 购物车商品 → 事件商品和金额。 */
	private static function cart_items(): array {
		$items = array();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $items;
		}
		foreach ( WC()->cart->get_cart() as $line ) {
			$p = $line['data'] ?? null;
			if ( $p instanceof \WC_Product ) {
				$qty     = (int) $line['quantity'];
				$items[] = self::item( $p, $qty, $qty ? (float) $line['line_subtotal'] / $qty : null );
			}
		}
		return $items;
	}

	private static function sum( array $items ): float {
		return round( array_sum( array_map( static fn( $i ) => $i['price'] * $i['quantity'], $items ) ), 2 );
	}

	/** 一个事件。$value 为空时按商品金额。 */
	public static function make( string $name, array $items = array(), ?float $value = null, array $extra = array() ): array {
		return array_merge(
			array(
				'name'     => $name,
				'id'       => self::event_id( substr( $name, 0, 3 ) ),
				'time'     => time(),
				'items'    => array_values( $items ),
				'value'    => null !== $value ? round( $value, 2 ) : self::sum( $items ),
				'items_value' => self::sum( $items ),
				'currency' => get_woocommerce_currency(),
				'url'      => self::current_url(),
			),
			$extra
		);
	}

	private static function current_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return esc_url_raw( home_url( $uri ) );
	}

	/** 订单 → 下单 / 购买事件（同一个订单的 event_id 固定，浏览器和服务端可以去重）。 */
	public static function order_event( \WC_Order $order, string $name = 'purchase' ): array {
		$items = array();
		foreach ( $order->get_items() as $line ) {
			$p = $line instanceof \WC_Order_Item_Product ? $line->get_product() : null;
			if ( $p instanceof \WC_Product ) {
				$qty     = (int) $line->get_quantity();
				$items[] = self::item( $p, $qty, $qty ? (float) $line->get_subtotal() / $qty : null );
			}
		}
		$s     = self::settings();
		$fb    = $s['platforms']['facebook'];
		$skip  = array();
		$first = ! self::has_earlier_order( $order );
		if ( 'purchase' === $name && ( ( $fb['first_order_only'] && ! $first ) || ! self::in_ratio( $order->get_id(), (int) $fb['ratio'] ) ) ) {
			$skip[] = 'facebook';
		}
		return array(
			'name'        => $name,
			'id'          => ( 'purchase' === $name ? 'order_' : 'place_' ) . $order->get_id(),
			'time'        => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time(),
			'items'       => $items,
			'value'       => round( (float) $order->get_total(), 2 ),
			'items_value' => self::sum( $items ),
			'currency'    => $order->get_currency(),
			'order_id'    => (string) $order->get_order_number(),
			'tax'         => round( (float) $order->get_total_tax(), 2 ),
			'shipping'    => round( (float) $order->get_shipping_total(), 2 ),
			'first_order' => $first,
			'skip'        => $skip,
			'url'         => $order->get_checkout_order_received_url(),
		);
	}

	/** 这位顾客以前有没有付款的订单（按账户或邮箱，不算这一单）。 */
	public static function has_earlier_order( \WC_Order $order ): bool {
		$statuses = array_unique( array_merge( wc_get_is_paid_statuses(), array( 'on-hold' ) ) );
		$args     = array( 'status' => $statuses, 'limit' => 1, 'return' => 'ids', 'exclude' => array( $order->get_id() ), 'date_created' => '<=' . ( $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() ) );
		if ( $order->get_customer_id() && wc_get_orders( $args + array( 'customer_id' => $order->get_customer_id() ) ) ) {
			return true;
		}
		$email = (string) $order->get_billing_email();
		return '' !== $email && (bool) wc_get_orders( $args + array( 'billing_email' => $email ) );
	}

	/** 在线订单回传比例：按订单号固定地取一部分（同一个订单每次结果一样）。 */
	public static function in_ratio( int $order_id, int $ratio ): bool {
		return $ratio >= 100 || ( crc32( 'pc' . $order_id ) % 100 ) < $ratio;
	}

	/** 当前页面要输出的事件。 */
	public static function page_events(): array {
		$events = array( self::make( 'page_view' ) );
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			global $wp;
			$order = wc_get_order( absint( $wp->query_vars['order-received'] ?? 0 ) );
			$key   = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $order && hash_equals( $order->get_order_key(), (string) $key ) && ! $order->has_status( array( 'failed', 'cancelled', 'pending' ) ) && ! $order->get_meta( self::ORDER_SHOWN ) ) {
				$order->update_meta_data( self::ORDER_SHOWN, time() );
				$order->save_meta_data();
				$events[] = self::order_event( $order, 'place_order' );
				$events[] = self::order_event( $order, 'purchase' ) + array( 'user' => self::user_data( $order ) );
			}
			return $events;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$items    = self::cart_items();
			$events[] = self::make( 'begin_checkout', $items, (float) WC()->cart->get_total( 'edit' ) );
			return $events;
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			$events[] = self::make( 'view_cart', self::cart_items() );
			return $events;
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product ) {
				$item = self::item( $product );
				if ( $product->is_type( 'variable' ) ) {
					$item['price'] = round( (float) $product->get_variation_price( 'min' ), 2 );
				}
				$events[] = self::make( 'view_item', array( $item ) );
			}
			return $events;
		}
		if ( is_search() ) {
			$events[] = self::make( 'search', array(), 0.0, array( 'search' => get_search_query( false ) ) );
			return $events;
		}
		if ( ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) ) {
			$term     = is_product_taxonomy() ? get_queried_object() : null;
			$events[] = self::make( 'view_list', array(), 0.0, array( 'list' => $term instanceof \WP_Term ? html_entity_decode( $term->name ) : __( 'Shop', 'peptide-core' ) ) );
			return $events;
		}
		if ( is_front_page() ) {
			$events[] = self::make( 'view_home' );
		}
		return $events;
	}

	// ---------- 请求里发生的事件（加购、注册、登录） ----------

	public static function queue( array $event ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$q   = (array) WC()->session->get( self::QUEUE_KEY, array() );
		$q[] = $event;
		WC()->session->set( self::QUEUE_KEY, array_slice( $q, -1 * self::MAX_QUEUE ) );
	}

	/** 取出并清空队列。 */
	public static function pop_queue(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}
		$q = (array) WC()->session->get( self::QUEUE_KEY, array() );
		if ( $q ) {
			WC()->session->set( self::QUEUE_KEY, array() );
		}
		return array_values( $q );
	}

	public static function on_add_to_cart( $key, $product_id, $quantity, $variation_id = 0, $variation = array(), $data = array() ): void {
		if ( ! self::allowed() ) {
			return;
		}
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return;
		}
		$event = self::make( 'add_to_cart', array( self::item( $product, (int) $quantity ) ) );
		self::queue( $event );
		Pixels\Server::send( array( $event ), self::user_data() );
	}

	public static function on_sign_up( $customer_id ): void {
		if ( ! self::allowed() ) {
			return;
		}
		$event = self::make( 'sign_up', array(), 0.0 );
		self::queue( $event );
		Pixels\Server::send( array( $event ), self::user_data() );
	}

	public static function on_login( $login, $user ): void {
		if ( ! $user instanceof \WP_User || user_can( $user, 'edit_posts' ) ) {
			return; // 员工登录不算。
		}
		if ( self::any() && empty( $_COOKIE[ self::OPTOUT ] ) ) {
			self::queue( self::make( 'login', array(), 0.0 ) );
		}
	}

	/** wc-ajax=pc_px_queue：经典的 AJAX 加购之后，浏览器来取队列。 */
	public static function ajax_queue(): void {
		wp_send_json_success( array( 'events' => self::allowed() ? self::pop_queue() : array() ) );
	}

	/** 订单第一次变成处理中 / 已完成 / 保留时，服务端回传购买。 */
	public static function on_order_status( $order_id, $from, $to, $order ): void {
		if ( ! in_array( $to, array( 'processing', 'completed', 'on-hold' ), true ) || ! $order instanceof \WC_Order || $order->get_meta( Pixels\Server::ORDER_SENT ) ) {
			return;
		}
		if ( ! self::any() || '1' === (string) $order->get_meta( '_pc_px_optout' ) ) {
			return;
		}
		$order->update_meta_data( Pixels\Server::ORDER_SENT, time() );
		$order->save_meta_data();
		Pixels\Server::send( array( self::order_event( $order, 'purchase' ) ), self::user_data( $order ), Pixels\Server::context_from_order( $order ) );
	}

	// ---------- 前台 ----------

	/** 给浏览器的配置（不含 access token）。 */
	public static function browser_config( array $s, array $events ): array {
		$host      = (string) preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$platforms = array();
		foreach ( self::platforms() as $key => $def ) {
			$p   = $s['platforms'][ $key ];
			$ids = array();
			foreach ( $p['pixels'] as $px ) {
				$ids[] = array( 'id' => $px['id'], 'domain' => $px['domain'] );
			}
			if ( ! $ids ) {
				continue;
			}
			$c = array(
				'ids'        => $ids,
				'events'     => $def['events'],
				'itemsValue' => (bool) $p['items_value'],
			);
			if ( 'facebook' === $key ) {
				$c += array( 'contentId' => $p['content_id'], 'contentName' => $p['content_name'], 'custom' => (bool) $p['custom_events'] );
			}
			if ( 'gads' === $key ) {
				$c += array( 'purchase' => $p['purchase'], 'labels' => (object) $p['labels'] );
			}
			if ( 'gtm' === $key ) {
				$c += array( 'userData' => (bool) $p['user_data'] );
			}
			if ( 'yahoo' === $key ) {
				$c += array( 'project' => $p['project_id'] );
			}
			if ( 'twitter' === $key ) {
				$c += array( 'eventIds' => (object) $p['event_ids'] );
			}
			$platforms[ $key ] = $c;
		}
		return array(
			'platforms' => $platforms,
			'events'    => $events,
			'user'      => $s['match'] ? self::user_data() : (object) array(),
			'currency'  => get_woocommerce_currency(),
			'host'      => $host,
			'timing'    => $s['timing'],
			'gpc'       => (bool) $s['gpc'],
			'queue'     => \WC_AJAX::get_endpoint( 'pc_px_queue' ),
		);
	}

	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}
		$s = self::settings();
		if ( ! self::allowed( $s ) ) {
			return;
		}
		$events = array_merge( self::page_events(), self::pop_queue() );
		// 页面事件的服务端回传（加购等在发生时已经发过；购买由订单状态触发）。
		$server = array_values( array_filter( $events, static fn( $e ) => in_array( $e['name'], array( 'view_item', 'search', 'begin_checkout', 'view_cart' ), true ) || ( 'page_view' === $e['name'] && $s['server_pv'] ) ) );
		if ( $server ) {
			Pixels\Server::send( $server, self::user_data() );
		}
		$ver = PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/pixels.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_script( 'peptide-core-pixels', plugins_url( 'assets/pixels.js', PEPTIDE_CORE_FILE ), array(), $ver, true );
		wp_localize_script( 'peptide-core-pixels', 'pcPixels', self::browser_config( $s, $events ) );
	}
}
