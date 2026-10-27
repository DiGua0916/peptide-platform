<?php
/**
 * 后台“数据”菜单（DATA-01，参照 Shopname 左侧“数据”板块）：九个子页面的外壳、时间段接口；各页面的数据在后续步骤里接入。
 *
 * - 菜单：顶层“数据”（位置在“营销活动”之后），下面九个页面，顺序和 Shopname 一致：
 *   实时数据、运营数据、商品详情数据、页面访问统计、页面商品转化、应用统计、落地页转化、转化率详情、邮件详情数据。
 * - 每个页面都是同一个外壳：标题、时间段选择（今天 / 昨天 / 最近 7 天 / 最近 30 天 / 自定义）、对比时间段（VS），
 *   由 assets/data.js 渲染；时间段的算法只有一份，在 Data\Range（店铺时区、结束日期包含当天）。
 * - 接口：GET /peptide/v1/data/range 校验并换算一个时间段，页面切换时间段时调用。
 * - 后续的报表脚本依赖脚本句柄 SCRIPT（peptide-core-data），调用 PcData.register( 页面 key, fn ) 把报表画进页面里。
 *
 * 只给能管理商店的人（manage_woocommerce）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Api\Rest_Controller;
use PeptideCore\Data\Conversion;
use PeptideCore\Data\Operations;
use PeptideCore\Data\Page_Visits;
use PeptideCore\Data\Products;
use PeptideCore\Data\Range;
use PeptideCore\Data\Realtime;

defined( 'ABSPATH' ) || exit;

final class Data_Pages {

	public const CAP    = 'manage_woocommerce';
	public const SLUG   = 'pc-data';            // 顶层菜单 = 第一个页面（实时数据）。
	public const SCRIPT = 'peptide-core-data';  // 后续报表脚本依赖的脚本句柄。

	/** 页面 key => slug，顺序就是菜单顺序（和 Shopname 一致）。 */
	public const PAGES = array(
		'realtime'        => 'pc-data',
		'operations'      => 'pc-data-operations',
		'products'        => 'pc-data-products',
		'pages'           => 'pc-data-pages',
		'page_conversion' => 'pc-data-page-conversion',
		'apps'            => 'pc-data-apps',
		'landing'         => 'pc-data-landing',
		'conversion'      => 'pc-data-conversion',
		'emails'          => 'pc-data-emails',
	);

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/** REST 路由在任何请求里都要注册（后台页面通过 REST 取数据）。 */
	public static function register_rest(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				// 所有数据接口共用的权限判断。
				$permission = static fn() => current_user_can( self::CAP ) ? true : new \WP_Error( 'pc_forbidden', __( 'Sorry, you are not allowed to view store data.', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) );
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/data/range',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static function ( \WP_REST_Request $request ) {
							$r = Range::from_params( $request->get_params() );
							if ( is_wp_error( $r ) ) {
								return $r;
							}
							$res = new \WP_REST_Response( $r );
							$res->header( 'Cache-Control', 'no-store' );
							return $res;
						},
						'permission_callback' => $permission,
					)
				);
				// 实时数据页（DATA-01 第 6 步）：最近 10 分钟的数字（带今日合计）、访客行为漏斗、每分钟访客数量、今日的热门商品和页面。没有时间段（始终是“现在”），只有 funnel / minutes 两个窗口参数。
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/data/realtime',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static function ( \WP_REST_Request $request ) {
							$p = Realtime::params( $request->get_params() );
							if ( is_wp_error( $p ) ) {
								return $p;
							}
							$res = new \WP_REST_Response( Realtime::summary( (int) round( microtime( true ) * 1000 ), $p['funnel'], $p['minutes'] ) );
							$res->header( 'Cache-Control', 'no-store' );
							return $res;
						},
						'permission_callback' => $permission,
					)
				);
				// 运营数据页（DATA-01 第 2 步）：指标卡片和两个转化漏斗，本期和对比期。
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/data/operations',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static function ( \WP_REST_Request $request ) {
							$r = Range::from_params( $request->get_params() );
							if ( is_wp_error( $r ) ) {
								return $r;
							}
							$res = new \WP_REST_Response( Operations::summary( $r ) );
							$res->header( 'Cache-Control', 'no-store' );
							return $res;
						},
						'permission_callback' => $permission,
					)
				);
				// 商品详情数据页（DATA-01 第 3 步）：每个商品（和它的款式）的查看、加购、付款数字，本期和对比期；可排序、搜索、分页。
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/data/products',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static function ( \WP_REST_Request $request ) {
							$r = Range::from_params( $request->get_params() );
							if ( is_wp_error( $r ) ) {
								return $r;
							}
							$a = Products::params( $request->get_params() );
							if ( is_wp_error( $a ) ) {
								return $a;
							}
							$res = new \WP_REST_Response( Products::table( $r, $a ) );
							$res->header( 'Cache-Control', 'no-store' );
							return $res;
						},
						'permission_callback' => $permission,
					)
				);
				// 页面访问统计页（DATA-01 第 5 步）：每个页面路径的访问量、访问用户、跳出率（只有本期，没有对比期）；可排序、搜索、分页。
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/data/pages',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static function ( \WP_REST_Request $request ) {
							$r = Range::from_params( $request->get_params() );
							if ( is_wp_error( $r ) ) {
								return $r;
							}
							$a = Page_Visits::params( $request->get_params() );
							if ( is_wp_error( $a ) ) {
								return $a;
							}
							$res = new \WP_REST_Response( Page_Visits::table( $r, $a ) );
							$res->header( 'Cache-Control', 'no-store' );
							return $res;
						},
						'permission_callback' => $permission,
					)
				);
				// 转化率详情页（DATA-01 第 4 步）：整体转化步骤（本期、对比期、变化）和按设备或浏览器分组的漏斗卡片。
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/data/conversion',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static function ( \WP_REST_Request $request ) {
							$r = Range::from_params( $request->get_params() );
							if ( is_wp_error( $r ) ) {
								return $r;
							}
							$g = Conversion::params( $request->get_params() );
							if ( is_wp_error( $g ) ) {
								return $g;
							}
							$res = new \WP_REST_Response( Conversion::summary( $r, $g ) );
							$res->header( 'Cache-Control', 'no-store' );
							return $res;
						},
						'permission_callback' => $permission,
					)
				);
			}
		);
	}

	/**
	 * 菜单：顶层就是第一个页面，下面按顺序列出九个页面。
	 *
	 * 位置（字符串）：要排在“营销活动”（56）之后、分析（57）之前。“营销活动”和 WooCommerce 的“支付”都是 56，
	 * WordPress 会给后来的加上 5 位小数的偏移（例如 56.42082），而且排序用自然顺序（strnatcasecmp），小数部分当整数比较，
	 * 所以 '56.5' 会排到 56.42082 前面。'56.999995' 的小数部分有 6 位，比任何 5 位偏移都大，又小于 57。
	 */
	public static function add_menu(): void {
		add_menu_page( __( 'Data', 'peptide-core' ), __( 'Data', 'peptide-core' ), self::CAP, self::SLUG, array( self::class, 'render' ), 'dashicons-chart-area', '56.999995' );
		$titles = self::titles();
		foreach ( self::PAGES as $key => $slug ) {
			// 第一个页面和顶层菜单同一个 slug，WordPress 就把它显示成第一个子菜单，而不是多出一个“数据”。
			add_submenu_page( self::SLUG, $titles[ $key ], $titles[ $key ], self::CAP, $slug, array( self::class, 'render' ) );
		}
	}

	/** 当前打开的是哪个数据页面（PAGES 的 key），不是数据页面时为 null。 */
	public static function current_key(): ?string {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key  = array_search( $page, self::PAGES, true );
		return false === $key ? null : (string) $key;
	}

	/** 不比较 $hook：子菜单的 hook 名带有翻译后的菜单标题，换语言就变，所以用页面参数判断。 */
	public static function enqueue(): void {
		$key = self::current_key();
		if ( null === $key || ! current_user_can( self::CAP ) ) {
			return;
		}
		$url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$ver = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-data', $url . 'data.css', array(), $ver( 'data.css' ) );
		wp_enqueue_script( self::SCRIPT, $url . 'data.js', array( 'wp-api-fetch', 'wp-url' ), $ver( 'data.js' ), array( 'in_footer' => true ) );
		wp_add_inline_script(
			self::SCRIPT,
			'window.pcData = ' . wp_json_encode(
				array(
					'page'      => $key,
					'title'     => self::titles()[ $key ],
					'range'     => Range::from_params( array() ), // 今天，由服务器按店铺时区算好。
					'showRange' => 'realtime' !== $key,           // 实时数据页没有时间段工具栏。
					'timezone'  => wp_timezone_string() . ' (UTC' . wp_date( 'P' ) . ')',
					'currency'  => array(
						'code'   => get_woocommerce_currency(),
						'symbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
					),
					'strings'   => self::strings(),
				)
			) . ';',
			'before'
		);

		// 实时数据页的报表（三张卡片、访客行为漏斗、每分钟访客数量、今日列表），在 data.js 之后加载，用 PcData.register( 'realtime', … ) 画进页面。
		if ( 'realtime' === $key ) {
			wp_enqueue_style( 'peptide-core-data-realtime', $url . 'data-realtime.css', array( 'peptide-core-data' ), $ver( 'data-realtime.css' ) );
			wp_enqueue_script( 'peptide-core-data-realtime', $url . 'data-realtime.js', array( self::SCRIPT ), $ver( 'data-realtime.js' ), array( 'in_footer' => true ) );
			wp_add_inline_script(
				'peptide-core-data-realtime',
				'window.pcDataRealtime = ' . wp_json_encode( array( 'strings' => self::realtime_strings() ) ) . ';',
				'before'
			);
		}

		// 运营数据页的报表（指标卡片和转化漏斗），在 data.js 之后加载，用 PcData.register( 'operations', … ) 画进页面。
		if ( 'operations' === $key ) {
			wp_enqueue_style( 'peptide-core-data-operations', $url . 'data-operations.css', array( 'peptide-core-data' ), $ver( 'data-operations.css' ) );
			wp_enqueue_script( 'peptide-core-data-operations', $url . 'data-operations.js', array( self::SCRIPT ), $ver( 'data-operations.js' ), array( 'in_footer' => true ) );
			wp_add_inline_script(
				'peptide-core-data-operations',
				'window.pcDataOperations = ' . wp_json_encode( array( 'strings' => self::operations_strings() ) ) . ';',
				'before'
			);
		}

		// 商品详情数据页的报表（商品表格、款式展开、排序搜索分页），同样在 data.js 之后加载，用 PcData.register( 'products', … ) 画进页面。
		if ( 'products' === $key ) {
			wp_enqueue_style( 'peptide-core-data-products', $url . 'data-products.css', array( 'peptide-core-data' ), $ver( 'data-products.css' ) );
			wp_enqueue_script( 'peptide-core-data-products', $url . 'data-products.js', array( self::SCRIPT ), $ver( 'data-products.js' ), array( 'in_footer' => true ) );
			wp_add_inline_script(
				'peptide-core-data-products',
				'window.pcDataProducts = ' . wp_json_encode( array( 'strings' => self::products_strings() ) ) . ';',
				'before'
			);
		}

		// 页面访问统计页的报表（页面路径表格、搜索、排序、分页），同样在 data.js 之后加载，用 PcData.register( 'pages', … ) 画进页面。
		if ( 'pages' === $key ) {
			wp_enqueue_style( 'peptide-core-data-pages', $url . 'data-pages.css', array( 'peptide-core-data' ), $ver( 'data-pages.css' ) );
			wp_enqueue_script( 'peptide-core-data-pages', $url . 'data-pages.js', array( self::SCRIPT ), $ver( 'data-pages.js' ), array( 'in_footer' => true ) );
			wp_add_inline_script(
				'peptide-core-data-pages',
				'window.pcDataPages = ' . wp_json_encode( array( 'strings' => self::pages_strings() ) ) . ';',
				'before'
			);
		}

		// 转化率详情页的报表（整体转化步骤和分组漏斗卡片），同样在 data.js 之后加载，用 PcData.register( 'conversion', … ) 画进页面。
		if ( 'conversion' === $key ) {
			wp_enqueue_style( 'peptide-core-data-conversion', $url . 'data-conversion.css', array( 'peptide-core-data' ), $ver( 'data-conversion.css' ) );
			wp_enqueue_script( 'peptide-core-data-conversion', $url . 'data-conversion.js', array( self::SCRIPT ), $ver( 'data-conversion.js' ), array( 'in_footer' => true ) );
			wp_add_inline_script(
				'peptide-core-data-conversion',
				'window.pcDataConversion = ' . wp_json_encode( array( 'strings' => self::conversion_strings() ) ) . ';',
				'before'
			);
		}
	}

	/** 页面 key => 翻译后的标题（也是菜单名）。 */
	private static function titles(): array {
		return array(
			'realtime'        => __( 'Real-time data', 'peptide-core' ),
			'operations'      => __( 'Operations data', 'peptide-core' ),
			'products'        => __( 'Product details data', 'peptide-core' ),
			'pages'           => __( 'Page visit statistics', 'peptide-core' ),
			'page_conversion' => __( 'Page product conversion', 'peptide-core' ),
			'apps'            => __( 'App statistics', 'peptide-core' ),
			'landing'         => __( 'Landing page conversion', 'peptide-core' ),
			'conversion'      => __( 'Conversion rate details', 'peptide-core' ),
			'emails'          => __( 'Email details data', 'peptide-core' ),
		);
	}

	/** 页面脚本里显示的所有文字（在这里翻译，脚本里不写死文字）。 */
	private static function strings(): array {
		return array(
			'today'       => __( 'Today', 'peptide-core' ),
			'yesterday'   => __( 'Yesterday', 'peptide-core' ),
			'7d'          => __( 'Last 7 days', 'peptide-core' ),
			'30d'         => __( 'Last 30 days', 'peptide-core' ),
			'custom'      => __( 'Custom range', 'peptide-core' ),
			'compareWith' => __( 'Compare with', 'peptide-core' ),
			'vs'          => 'VS',
			'startDate'   => __( 'Start date', 'peptide-core' ),
			'endDate'     => __( 'End date', 'peptide-core' ),
			'apply'       => __( 'Apply', 'peptide-core' ),
			'storeTime'   => __( 'Store time zone', 'peptide-core' ),
			'loading'     => __( 'Loading…', 'peptide-core' ),
			'building'    => __( 'This page is being built. The date range already works; the report will appear here in a later update.', 'peptide-core' ),
			'rangeError'  => __( 'Check the dates: the start date must be on or before the end date, and a range can cover at most 366 days.', 'peptide-core' ),
			'loadError'   => __( 'The data could not be loaded. Try again.', 'peptide-core' ),
		);
	}

	/** 实时数据页脚本里的文字：更新时间、三张卡片和六个指标、“今日”、访客行为漏斗、每分钟图、四个今日列表、说明。 */
	private static function realtime_strings(): array {
		return array(
			/* translators: %s: date and time */
			'updated'        => __( 'Last updated: %s', 'peptide-core' ),
			'refresh'        => __( 'Refresh', 'peptide-core' ),
			'autoRefresh'    => __( 'Updates every minute while this page is open.', 'peptide-core' ),
			'visits'         => __( 'Real-time visits', 'peptide-core' ),
			'sales'          => __( 'Real-time sales', 'peptide-core' ),
			'power'          => __( 'Real-time buying power', 'peptide-core' ),
			'visitors'       => __( 'Visitor count', 'peptide-core' ),
			'pageviews'      => __( 'Page visits', 'peptide-core' ),
			'orders'         => __( 'Order count', 'peptide-core' ),
			'salesAmount'    => __( 'Sales', 'peptide-core' ),
			'unitsPerOrder'  => __( 'Units per order', 'peptide-core' ),
			'aov'            => __( 'Average order value', 'peptide-core' ),
			/* translators: %s: today's value */
			'today'          => __( '(Today %s)', 'peptide-core' ),
			'windowNote'     => __( 'Last 10 minutes', 'peptide-core' ),
			'behaviour'      => __( 'Real-time visitor behaviour', 'peptide-core' ),
			'perMinute'      => __( 'Visitors per minute', 'peptide-core' ),
			/* translators: %d: number of minutes */
			'minutesOption'  => __( '%d minutes', 'peptide-core' ),
			/* translators: %d: number of minutes */
			'minutesAgo'     => __( '%d min ago', 'peptide-core' ),
			'now'            => __( 'Now', 'peptide-core' ),
			'funnelVisitors' => __( 'Visited users', 'peptide-core' ),
			'viewed'         => __( 'Viewed products', 'peptide-core' ),
			'carted'         => __( 'Carted products', 'peptide-core' ),
			'checkout'       => __( 'Started checkout', 'peptide-core' ),
			'paid'           => __( 'Completed payment', 'peptide-core' ),
			/* translators: %s: a percentage, for example 54.72% */
			'ofVisitors'     => __( 'Of visitors: %s', 'peptide-core' ),
			'listViewed'     => __( 'Most viewed products today', 'peptide-core' ),
			'listCarted'     => __( 'Most added to cart today', 'peptide-core' ),
			'listSold'       => __( 'Best sellers today', 'peptide-core' ),
			'listPages'      => __( 'Popular pages today', 'peptide-core' ),
			'details'        => __( 'View details', 'peptide-core' ),
			'empty'          => __( 'No data yet.', 'peptide-core' ),
			'notYet'         => __( 'Address, shipping and payment steps, the visitor map, countries, sources and collections need data that is not collected yet.', 'peptide-core' ),
			'trackingOff'    => __( 'Visitor tracking is off, so visitor numbers, conversion and the funnels stay at zero.', 'peptide-core' ),
		);
	}

	/** 运营数据页脚本里的文字：12 个指标、漏斗的五层、表头和说明。 */
	private static function operations_strings(): array {
		return array(
			'sales'           => __( 'Sales', 'peptide-core' ),
			'visitors'        => __( 'Visited users', 'peptide-core' ),
			'registered'      => __( 'New sign-ups', 'peptide-core' ),
			'orders'          => __( 'Order count', 'peptide-core' ),
			'conversion'      => __( 'Conversion rate', 'peptide-core' ),
			'aov'             => __( 'Average order value', 'peptide-core' ),
			'items'           => __( 'Product units sold', 'peptide-core' ),
			'net_item_sales'  => __( 'Net product sales', 'peptide-core' ),
			'refunds'         => __( 'Refund amount', 'peptide-core' ),
			'avg_duration'    => __( 'Average visit duration', 'peptide-core' ),
			'customers'       => __( 'Customer count', 'peptide-core' ),
			'mobile'          => __( 'Mobile users', 'peptide-core' ),
			'viewed'          => __( 'Viewed products', 'peptide-core' ),
			'carted'          => __( 'Carted products', 'peptide-core' ),
			'checkout'        => __( 'Started checkout', 'peptide-core' ),
			'paid'            => __( 'Paid successfully', 'peptide-core' ),
			'visitorFunnel'   => __( 'Visitor conversion rate', 'peptide-core' ),
			'returningFunnel' => __( 'Returning visitor conversion rate', 'peptide-core' ),
			'event'           => __( 'Conversion event', 'peptide-core' ),
			'thisPeriod'      => __( 'This period', 'peptide-core' ),
			'previousPeriod'  => __( 'Previous period', 'peptide-core' ),
			'change'          => __( 'Change', 'peptide-core' ),
			/* translators: %s: a percentage, for example 54.72% */
			'ofVisitors'      => __( 'Of visitors: %s', 'peptide-core' ),
			/* translators: 1: minutes, 2: seconds */
			'duration'        => __( '%1$d min %2$d s', 'peptide-core' ),
			'trackingOff'     => __( 'Visitor tracking is off, so visitor numbers, conversion and the funnels stay at zero.', 'peptide-core' ),
			/* translators: %d: number of orders */
			'testOrders'      => __( 'Only real orders are counted: %d test order(s) in this period (test payment or demo products) are left out.', 'peptide-core' ),
		);
	}

	/** 商品详情数据页脚本里的文字：商品列、12 个指标的表头、搜索、编辑表头、分页。 */
	private static function products_strings(): array {
		return array(
			'product'     => __( 'Product', 'peptide-core' ),
			'views'       => __( 'Product views', 'peptide-core' ),
			'view_users'  => __( 'Viewing users', 'peptide-core' ),
			'carts'       => __( 'Add-to-cart actions', 'peptide-core' ),
			'cart_users'  => __( 'Users who added to cart', 'peptide-core' ),
			'cart_units'  => __( 'Units added to cart', 'peptide-core' ),
			'cart_rate'   => __( 'Add-to-cart rate', 'peptide-core' ),
			'paid_orders' => __( 'Paid orders', 'peptide-core' ),
			'paid_users'  => __( 'Paying users', 'peptide-core' ),
			'paid_units'  => __( 'Units paid', 'peptide-core' ),
			'conversion'  => __( 'Conversion rate', 'peptide-core' ),
			'sales'       => __( 'Paid amount', 'peptide-core' ),
			'net_sales'   => __( 'Net sales', 'peptide-core' ),
			'search'      => __( 'Search products', 'peptide-core' ),
			'columns'     => __( 'Edit columns', 'peptide-core' ),
			'variations'  => __( 'Variation details', 'peptide-core' ),
			'noData'      => __( 'No product data in this period.', 'peptide-core' ),
			/* translators: 1: current page number, 2: total number of pages */
			'page'        => __( 'Page %1$d of %2$d', 'peptide-core' ),
			'prev'        => __( 'Previous', 'peptide-core' ),
			'next'        => __( 'Next', 'peptide-core' ),
			/* translators: %s: a product SKU */
			'sku'         => __( 'SKU: %s', 'peptide-core' ),
		);
	}

	/** 页面访问统计页脚本里的文字：表头、跳出率说明、搜索、每页条数、总数、分页。 */
	private static function pages_strings(): array {
		return array(
			'path'         => __( 'Page path', 'peptide-core' ),
			'views'        => __( 'Page visits', 'peptide-core' ),
			'visitors'     => __( 'Visited users', 'peptide-core' ),
			'bounce_rate'  => __( 'Bounce rate', 'peptide-core' ),
			'bounceHelp'   => __( 'Bounce rate = users who left after viewing only this page / all users who viewed this page', 'peptide-core' ),
			'open'         => __( 'Open page', 'peptide-core' ),
			'search'       => __( 'Search page paths', 'peptide-core' ),
			'perPage'      => __( 'Per page', 'peptide-core' ),
			/* translators: %d: number of page paths */
			'total'        => __( '%d pages in total', 'peptide-core' ),
			'noData'       => __( 'No page visits in this period.', 'peptide-core' ),
			/* translators: 1: current page number, 2: total number of pages */
			'page'         => __( 'Page %1$d of %2$d', 'peptide-core' ),
			'prev'         => __( 'Previous', 'peptide-core' ),
			'next'         => __( 'Next', 'peptide-core' ),
			'trackingNote' => __( 'Page visits come from the visitor tracking script in the browser; without it this page stays empty.', 'peptide-core' ),
		);
	}

	/** 转化率详情页脚本里的文字：整体步骤、分组选择、设备和浏览器名、卡片文字和说明。 */
	private static function conversion_strings(): array {
		return array(
			'conversion'     => __( 'Conversion rate', 'peptide-core' ),
			'conversionHelp' => __( 'Paid successfully / visited users × 100%', 'peptide-core' ),
			'paid'           => __( 'Paid successfully', 'peptide-core' ),
			'visitors'       => __( 'Visited users', 'peptide-core' ),
			'viewed'         => __( 'Viewed products', 'peptide-core' ),
			'carted'         => __( 'Carted products', 'peptide-core' ),
			'checkout'       => __( 'Started checkout', 'peptide-core' ),
			'groupBy'        => __( 'Group by', 'peptide-core' ),
			'device'         => __( 'Device', 'peptide-core' ),
			'browser'        => __( 'Browser', 'peptide-core' ),
			'desktop'        => __( 'Desktop', 'peptide-core' ),
			'mobile'         => __( 'Mobile', 'peptide-core' ),
			'tablet'         => __( 'Tablet', 'peptide-core' ),
			'other'          => __( 'Other', 'peptide-core' ),
			'unknown'        => __( 'Unknown', 'peptide-core' ),
			/* translators: %s: the name of a device or browser, for example Mobile */
			'groupRate'      => __( '%s visitor conversion rate:', 'peptide-core' ),
			'groupSales'     => __( 'Sales:', 'peptide-core' ),
			/* translators: %s: a percentage, for example 54.72% */
			'ofVisitors'     => __( 'Of visitors: %s', 'peptide-core' ),
			'noGroups'       => __( 'No visits or paid orders in this period.', 'peptide-core' ),
			'trackingOff'    => __( 'Visitor tracking is off, so visitor numbers, conversion and the funnels stay at zero.', 'peptide-core' ),
			'notYet'         => __( 'Adding an address, choosing shipping and choosing payment are not recorded yet, so those steps are not shown.', 'peptide-core' ),
		);
	}

	public static function render(): void {
		$key = self::current_key();
		if ( ! current_user_can( self::CAP ) || null === $key ) {
			return;
		}
		echo '<div class="wrap pc-data-wrap"><h1 class="screen-reader-text">' . esc_html( self::titles()[ $key ] ) . '</h1><div id="pc-data" class="pc-data" data-page="' . esc_attr( $key ) . '"><p>' . esc_html__( 'Loading…', 'peptide-core' ) . '</p></div></div>';
	}
}
