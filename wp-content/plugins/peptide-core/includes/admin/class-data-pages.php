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
use PeptideCore\Data\Apps;
use PeptideCore\Data\Conversion;
use PeptideCore\Data\Checkout_Steps;
use PeptideCore\Data\Emails;
use PeptideCore\Data\Landing;
use PeptideCore\Data\Operations;
use PeptideCore\Data\Page_Visits;
use PeptideCore\Data\Products;
use PeptideCore\Data\Csv_Response;
use PeptideCore\Data\Report_Exports;
use PeptideCore\Data\Rankings;
use PeptideCore\Data\Range;
use PeptideCore\Data\Realtime;
use PeptideCore\Data\Realtime_Dimensions;
use PeptideCore\Data\Realtime_Filter;
use PeptideCore\Data\Realtime_Filter_Options;
use PeptideCore\Data\Trends;
use PeptideCore\Tracking\Schema;
use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;

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
		add_filter( 'rest_pre_serve_request', array( Csv_Response::class, 'serve' ), 10, 4 );
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
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/data/realtime/history',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static function ( \WP_REST_Request $request ) {
							$r = Realtime::history_params( $request->get_params() );
							if ( is_wp_error( $r ) ) {
								return $r;
							}
							$res = new \WP_REST_Response( Realtime::history( $r ) );
							$res->header( 'Cache-Control', 'no-store' );
							return $res;
						},
						'permission_callback' => $permission,
					)
				);
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/data/realtime/dimensions',
					array(
						'methods' => \WP_REST_Server::READABLE,
						'callback' => static function ( \WP_REST_Request $request ) {
							$params = Realtime_Dimensions::params( $request->get_params() );
							if ( is_wp_error( $params ) ) { return $params; }
							$filter = Realtime_Filter::params( $request->get_params() );
							if ( is_wp_error( $filter ) ) { return $filter; }
							try { $response = new \WP_REST_Response( Realtime_Dimensions::summary( $params, $filter->is_all() ? null : $filter ) ); }
							catch ( \Throwable $error ) { return new \WP_Error( 'pc_realtime_filter_unavailable', __( 'Realtime entry attribution is unavailable.', 'peptide-core' ), array( 'status' => 503 ) ); }
							$response->header( 'Cache-Control', 'no-store' );
							return $response;
						},
						'permission_callback' => $permission,
					)
				);
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE, '/data/realtime/filtered',
					array(
						'methods' => \WP_REST_Server::READABLE,
						'permission_callback' => $permission,
						'callback' => static function ( \WP_REST_Request $request ) {
							$raw = $request->get_params(); $now = (int) round( microtime( true ) * 1000 );
							$filter = Realtime_Filter::params( $raw ); if ( is_wp_error( $filter ) ) { return $filter; }
							$mode = \PeptideCore\Data\Params::word( $raw, 'mode', 'live', array( 'live', 'history' ) );
							if ( null === $mode || ( array_key_exists( 'format', $raw ) && ( ! is_string( $raw['format'] ) || 'json' !== $raw['format'] ) ) ) { return \PeptideCore\Api\Errors::invalid( array( null === $mode ? 'mode' : 'format' => 'invalid_format' ) ); }
							$params = Realtime::params( $raw ); if ( is_wp_error( $params ) ) { return $params; }
							$range = 'history' === $mode ? Realtime::history_params( $raw, intdiv( $now, 1000 ) ) : null;
							if ( is_wp_error( $range ) ) { return $range; }
							try {
								$data = 'history' === $mode ? Realtime::filtered_history( $range, $filter ) : Realtime::filtered_summary( $now, $params['funnel'], $params['minutes'], $filter );
								$response = new \WP_REST_Response( $data ); $response->header( 'Cache-Control', 'no-store' ); return $response;
							} catch ( \Throwable $error ) { return new \WP_Error( 'pc_realtime_filter_unavailable', __( 'Realtime entry attribution is unavailable.', 'peptide-core' ), array( 'status' => 503 ) ); }
						},
					)
				);
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE, '/data/realtime/filter-options',
					array(
						'methods' => \WP_REST_Server::READABLE,
						'permission_callback' => $permission,
						'callback' => static function ( \WP_REST_Request $request ) {
							$raw = $request->get_params(); $params = Realtime_Filter_Options::params( $raw ); if ( is_wp_error( $params ) ) { return $params; }
							$filter = Realtime_Filter::params( $raw ); if ( is_wp_error( $filter ) ) { return $filter; }
							try { $response = new \WP_REST_Response( Realtime_Filter_Options::summary( $params, $filter ) ); $response->header( 'Cache-Control', 'no-store' ); return $response; }
							catch ( \Throwable $error ) { return new \WP_Error( 'pc_realtime_filter_unavailable', __( 'Realtime entry attribution is unavailable.', 'peptide-core' ), array( 'status' => 503 ) ); }
						},
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
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE, '/data/checkout-steps',
					array(
						'methods' => \WP_REST_Server::READABLE,
						'permission_callback' => $permission,
						'callback' => static function ( \WP_REST_Request $request ) {
							$raw = $request->get_params(); $range = Range::from_params( $raw );
							if ( is_wp_error( $range ) ) { return $range; }
							if ( array_key_exists( 'format', $raw ) && ( ! is_string( $raw['format'] ) || 'json' !== $raw['format'] ) ) { return \PeptideCore\Api\Errors::invalid( array( 'format' => 'invalid_format' ) ); }
							try {
								$type = class_exists( CartCheckoutUtils::class ) && is_callable( array( CartCheckoutUtils::class, 'is_checkout_block_default' ) ) && CartCheckoutUtils::is_checkout_block_default() ? 'blocks' : 'unconfirmed';
								$steps = Checkout_Steps::summary( $range );
								$response = new \WP_REST_Response( array(
									'range' => $range, 'tracking' => Schema::installed(), 'checkout_type' => $type,
									'checkout_type_scope' => 'current_configuration', 'steps' => $steps,
									'details_url' => add_query_arg( array( 'page' => 'pc-data-conversion', 'from' => $range['dates']['from'], 'to' => $range['dates']['to'], 'compare_from' => $range['dates']['compare_from'], 'compare_to' => $range['dates']['compare_to'] ), admin_url( 'admin.php' ) ),
								) );
								$response->header( 'Cache-Control', 'no-store' ); return $response;
							} catch ( \Throwable $error ) { return new \WP_Error( 'pc_checkout_steps_unavailable', __( 'Checkout observations could not be loaded.', 'peptide-core' ), array( 'status' => 500 ) ); }
							},
					)
				);
				// 运营数据页的趋势图（DATA-01 第 7a 步）：九张趋势图的本期合计、对比期合计、变化和每个桶的点。
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/data/trends',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static function ( \WP_REST_Request $request ) {
							$r = Range::from_params( $request->get_params() );
							if ( is_wp_error( $r ) ) {
								return $r;
							}
							$res = new \WP_REST_Response( Trends::summary( $r ) );
							$res->header( 'Cache-Control', 'no-store' );
							return $res;
						},
						'permission_callback' => $permission,
					)
				);
				// 运营数据页的排行表（DATA-01 第 7b 步）：十四张排行表，每张的名称、本期、对比期和变化。
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/data/rankings',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static function ( \WP_REST_Request $request ) {
							$r = Range::from_params( $request->get_params() );
							if ( is_wp_error( $r ) ) {
								return $r;
							}
							$res = new \WP_REST_Response( Rankings::summary( $r ) );
							$res->header( 'Cache-Control', 'no-store' );
							return $res;
						},
						'permission_callback' => $permission,
					)
				);
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/data/rankings/table',
					array(
						'methods' => \WP_REST_Server::READABLE,
						'callback' => static function ( \WP_REST_Request $request ) {
							$r = Range::from_params( $request->get_params() );
							if ( is_wp_error( $r ) ) { return $r; }
							$a = Rankings::query_params( $request->get_params() );
							return is_wp_error( $a ) ? $a : Report_Exports::ranking_response( $request, $r, $a );
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
							return Report_Exports::response( $request, 'products', $r, $a );
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
							return Report_Exports::response( $request, 'pages', $r, $a );
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
							return Report_Exports::conversion_response( $request, $r, $g );
						},
						'permission_callback' => $permission,
					)
				);
				// 应用统计页（DATA-01 第 8 步）：每个应用一张卡片，卡片里是它的各项数字，本期、对比期和变化。
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/data/apps',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static function ( \WP_REST_Request $request ) {
							$r = Range::from_params( $request->get_params() );
							if ( is_wp_error( $r ) ) {
								return $r;
							}
							$res = new \WP_REST_Response( Apps::summary( $r ) );
							$res->header( 'Cache-Control', 'no-store' );
							return $res;
						},
						'permission_callback' => $permission,
					)
				);
				// 落地页转化页（DATA-01 第 9 步）：每个落地页路径的 UV、顾客数、查看 / 加购 / 付款 UV、订单数、转化率、销售额、客单价、UV 价值（只有本期，没有对比期）；可排序、搜索、分页。
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/data/landing',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static function ( \WP_REST_Request $request ) {
							$r = Range::from_params( $request->get_params() );
							if ( is_wp_error( $r ) ) {
								return $r;
							}
							$a = Landing::params( $request->get_params() );
							if ( is_wp_error( $a ) ) {
								return $a;
							}
							$res = new \WP_REST_Response( Landing::table( $r, $a ) );
							$res->header( 'Cache-Control', 'no-store' );
							return $res;
						},
						'permission_callback' => $permission,
					)
				);
				// 邮件详情数据页（DATA-01 第 8 步）：邮件发送数和成功率（本期、对比期、变化），以及本期每封邮件的发送数和成功率。
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/data/emails',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static function ( \WP_REST_Request $request ) {
							$r = Range::from_params( $request->get_params() );
							if ( is_wp_error( $r ) ) {
								return $r;
							}
							$res = new \WP_REST_Response( Emails::summary( $r ) );
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
					'page'        => $key,
					'title'       => self::titles()[ $key ],
					'range'       => Range::from_params( array() ), // 今天，由服务器按店铺时区算好。
					'showRange'   => 'realtime' !== $key,           // 实时数据页没有时间段工具栏。
					'showCompare' => ! in_array( $key, array( 'landing', 'pages' ), true ), // 这两页只统计本期。
					'timezone'    => wp_timezone_string() . ' (UTC' . wp_date( 'P' ) . ')',
					'currency'    => array(
						'code'   => get_woocommerce_currency(),
						'symbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
					),
					'strings'     => self::strings(),
				)
			) . ';',
			'before'
		);

		// 实时数据页的报表（三张卡片、访客行为漏斗、每分钟访客数量、今日列表），在 data.js 之后加载，用 PcData.register( 'realtime', … ) 画进页面。
		if ( 'realtime' === $key ) {
			wp_enqueue_style( 'peptide-core-data-realtime', $url . 'data-realtime.css', array( 'peptide-core-data' ), $ver( 'data-realtime.css' ) );
			wp_enqueue_style( 'peptide-core-data-realtime-dimensions', $url . 'data-realtime-dimensions.css', array( 'peptide-core-data-realtime' ), $ver( 'data-realtime-dimensions.css' ) );
			wp_register_script( 'peptide-core-data-realtime-countries', $url . 'data-realtime-countries.js', array(), $ver( 'data-realtime-countries.js' ), array( 'in_footer' => true ) );
			wp_register_script( 'peptide-core-data-realtime-dimensions', $url . 'data-realtime-dimensions.js', array( self::SCRIPT, 'peptide-core-data-realtime-countries' ), $ver( 'data-realtime-dimensions.js' ), array( 'in_footer' => true ) );
			wp_enqueue_style( 'peptide-core-data-realtime-filters', $url . 'data-realtime-filters.css', array( 'peptide-core-data-realtime' ), $ver( 'data-realtime-filters.css' ) );
			wp_register_script( 'peptide-core-data-realtime-filters', $url . 'data-realtime-filters.js', array( self::SCRIPT ), $ver( 'data-realtime-filters.js' ), array( 'in_footer' => true ) );
			wp_enqueue_script( 'peptide-core-data-realtime', $url . 'data-realtime.js', array( self::SCRIPT, 'peptide-core-data-realtime-dimensions', 'peptide-core-data-realtime-filters' ), $ver( 'data-realtime.js' ), array( 'in_footer' => true ) );
			wp_add_inline_script(
				'peptide-core-data-realtime',
				'window.pcDataRealtime = ' . wp_json_encode( array( 'strings' => self::realtime_strings(), 'timezone' => wp_timezone_string() ) ) . ';',
				'before'
			);
		}

		// 运营数据页的报表（指标卡片和转化漏斗），在 data.js 之后加载，用 PcData.register( 'operations', … ) 画进页面。
		if ( 'operations' === $key ) {
			wp_enqueue_style( 'peptide-core-data-operations', $url . 'data-operations.css', array( 'peptide-core-data' ), $ver( 'data-operations.css' ) );
			wp_register_script( 'peptide-core-data-export', $url . 'data-export.js', array( self::SCRIPT, 'wp-api-fetch', 'wp-url' ), $ver( 'data-export.js' ), array( 'in_footer' => true ) );
			wp_enqueue_style( 'peptide-core-data-rankings', $url . 'data-rankings.css', array( 'peptide-core-data-operations' ), $ver( 'data-rankings.css' ) );
			wp_register_script( 'peptide-core-data-rankings', $url . 'data-rankings.js', array( self::SCRIPT, 'peptide-core-data-export' ), $ver( 'data-rankings.js' ), array( 'in_footer' => true ) );
			wp_enqueue_style( 'peptide-core-data-operations-checkout', $url . 'data-operations-checkout.css', array( 'peptide-core-data-operations' ), $ver( 'data-operations-checkout.css' ) );
			wp_register_script( 'peptide-core-data-operations-checkout', $url . 'data-operations-checkout.js', array( self::SCRIPT ), $ver( 'data-operations-checkout.js' ), array( 'in_footer' => true ) );
			wp_enqueue_script( 'peptide-core-data-operations', $url . 'data-operations.js', array( self::SCRIPT, 'peptide-core-data-rankings', 'peptide-core-data-operations-checkout' ), $ver( 'data-operations.js' ), array( 'in_footer' => true ) );
			wp_add_inline_script(
				'peptide-core-data-operations',
				'window.pcDataOperations = ' . wp_json_encode( array( 'strings' => self::operations_strings() ) ) . ';',
				'before'
			);
		}

		if ( in_array( $key, array( 'products', 'pages' ), true ) ) {
			wp_enqueue_script( 'peptide-core-data-export', $url . 'data-export.js', array( self::SCRIPT, 'wp-api-fetch', 'wp-url' ), $ver( 'data-export.js' ), array( 'in_footer' => true ) );
		}

		// 商品详情数据页的报表（商品表格、款式展开、排序搜索分页），同样在 data.js 之后加载，用 PcData.register( 'products', … ) 画进页面。
		if ( 'products' === $key ) {
			wp_enqueue_style( 'peptide-core-data-products', $url . 'data-products.css', array( 'peptide-core-data' ), $ver( 'data-products.css' ) );
			wp_enqueue_script( 'peptide-core-data-products', $url . 'data-products.js', array( self::SCRIPT, 'peptide-core-data-export' ), $ver( 'data-products.js' ), array( 'in_footer' => true ) );
			wp_add_inline_script(
				'peptide-core-data-products',
				'window.pcDataProducts = ' . wp_json_encode( array( 'strings' => self::products_strings() ) ) . ';',
				'before'
			);
		}

		// 页面访问统计页的报表（页面路径表格、搜索、排序、分页），同样在 data.js 之后加载，用 PcData.register( 'pages', … ) 画进页面。
		if ( 'pages' === $key ) {
			wp_enqueue_style( 'peptide-core-data-pages', $url . 'data-pages.css', array( 'peptide-core-data' ), $ver( 'data-pages.css' ) );
			wp_enqueue_script( 'peptide-core-data-pages', $url . 'data-pages.js', array( self::SCRIPT, 'peptide-core-data-export' ), $ver( 'data-pages.js' ), array( 'in_footer' => true ) );
			wp_add_inline_script(
				'peptide-core-data-pages',
				'window.pcDataPages = ' . wp_json_encode( array( 'strings' => self::pages_strings() ) ) . ';',
				'before'
			);
		}

		// 转化率详情页的报表（整体转化步骤和分组漏斗卡片），同样在 data.js 之后加载，用 PcData.register( 'conversion', … ) 画进页面。
		if ( 'conversion' === $key ) {
			wp_enqueue_style( 'peptide-core-data-conversion', $url . 'data-conversion.css', array( 'peptide-core-data' ), $ver( 'data-conversion.css' ) );
			wp_register_script( 'peptide-core-data-export', $url . 'data-export.js', array( self::SCRIPT, 'wp-api-fetch', 'wp-url' ), $ver( 'data-export.js' ), array( 'in_footer' => true ) );
			wp_enqueue_script( 'peptide-core-data-conversion', $url . 'data-conversion.js', array( self::SCRIPT, 'peptide-core-data-export' ), $ver( 'data-conversion.js' ), array( 'in_footer' => true ) );
			wp_add_inline_script(
				'peptide-core-data-conversion',
				'window.pcDataConversion = ' . wp_json_encode( array( 'strings' => self::conversion_strings() ) ) . ';',
				'before'
			);
		}

		// 应用统计页的报表（每个应用一张卡片，各项数字和对比时间段），同样在 data.js 之后加载，用 PcData.register( 'apps', … ) 画进页面。
		if ( 'apps' === $key ) {
			wp_enqueue_style( 'peptide-core-data-apps', $url . 'data-apps.css', array( 'peptide-core-data' ), $ver( 'data-apps.css' ) );
			wp_enqueue_script( 'peptide-core-data-apps', $url . 'data-apps.js', array( self::SCRIPT ), $ver( 'data-apps.js' ), array( 'in_footer' => true ) );
			wp_add_inline_script(
				'peptide-core-data-apps',
				'window.pcDataApps = ' . wp_json_encode( array( 'strings' => self::apps_strings() ) ) . ';',
				'before'
			);
		}

		// 落地页转化页的报表（落地页路径表格、搜索、排序、分页），同样在 data.js 之后加载，用 PcData.register( 'landing', … ) 画进页面。
		if ( 'landing' === $key ) {
			wp_enqueue_style( 'peptide-core-data-landing', $url . 'data-landing.css', array( 'peptide-core-data' ), $ver( 'data-landing.css' ) );
			wp_enqueue_script( 'peptide-core-data-landing', $url . 'data-landing.js', array( self::SCRIPT ), $ver( 'data-landing.js' ), array( 'in_footer' => true ) );
			wp_add_inline_script(
				'peptide-core-data-landing',
				'window.pcDataLanding = ' . wp_json_encode( array( 'strings' => self::landing_strings() ) ) . ';',
				'before'
			);
		}

		// 邮件详情数据页的报表（发送数和成功率的合计，每封邮件一行的表格），同样在 data.js 之后加载，用 PcData.register( 'emails', … ) 画进页面。
		if ( 'emails' === $key ) {
			wp_enqueue_style( 'peptide-core-data-emails', $url . 'data-emails.css', array( 'peptide-core-data' ), $ver( 'data-emails.css' ) );
			wp_enqueue_script( 'peptide-core-data-emails', $url . 'data-emails.js', array( self::SCRIPT ), $ver( 'data-emails.js' ), array( 'in_footer' => true ) );
			wp_add_inline_script(
				'peptide-core-data-emails',
				'window.pcDataEmails = ' . wp_json_encode( array( 'strings' => self::emails_strings() ) ) . ';',
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
	private static function visitor_funnel_strings(): array {
		return array(
			'address' => __( 'Address completed', 'peptide-core' ),
			'shipping' => __( 'Shipping selected', 'peptide-core' ),
			'payment' => __( 'Payment selected', 'peptide-core' ),
			'paidVisitors' => __( 'Paying visitors', 'peptide-core' ),
			'visitorConversion' => __( 'Paying visitor conversion rate', 'peptide-core' ),
			'viewDetails' => __( 'View details', 'peptide-core' ),
			'hideDetails' => __( 'Hide details', 'peptide-core' ),
			'orderVsVisitor' => __( 'The KPI conversion rate counts paid orders per visitor. These funnels count paying visitors per visitor; one customer can place several orders.', 'peptide-core' ),
			'detailHelp' => __( 'Each step counts distinct visitors in the selected visit cohort. Payment requires a recorded server confirmation. Optional steps, default selections and missing historical observations can make steps noncumulative. Missing observations are not backfilled. Sales and order cards count orders separately.', 'peptide-core' ),
			'detailUnavailable' => __( 'Detailed visitor steps are unavailable in this response. The legacy five-step report is shown; its paid count may use orders.', 'peptide-core' ),
		);
	}

	private static function realtime_dimension_strings(): array {
		return array(
			'viewed' => __( 'Recently viewed products', 'peptide-core' ),
			'carted' => __( 'Recently carted products', 'peptide-core' ),
			'sold' => __( 'Recently sold product units', 'peptide-core' ),
			'map' => __( 'Visitor countries map', 'peptide-core' ),
			'active_countries' => __( 'Active countries — visitors', 'peptide-core' ),
			'popular_countries' => __( 'Popular countries — page views', 'peptide-core' ),
			'sources' => __( 'Visit sources — visitors', 'peptide-core' ),
			'collections' => __( 'Collections — page views', 'peptide-core' ),
			'pages' => __( 'Popular pages in this range', 'peptide-core' ),
			'events' => _x( 'Events', 'realtime event count', 'peptide-core' ),
			'units' => __( 'Product units', 'peptide-core' ),
			'page_views' => __( 'Page views', 'peptide-core' ),
			'visitors' => _x( 'Visitors', 'realtime distinct visitor count', 'peptide-core' ),
			'visits' => __( 'Visits', 'peptide-core' ),
			'loading' => __( 'Loading…', 'peptide-core' ),
			'error' => __( 'These panels could not be loaded. Retry this accepted range.', 'peptide-core' ),
			'retry' => __( 'Retry', 'peptide-core' ),
			'stale' => __( 'Showing the last accepted range; these results may be stale.', 'peptide-core' ),
			'empty' => __( 'No matching observations in this range.', 'peptide-core' ),
			'unavailable' => __( 'Unavailable', 'peptide-core' ),
			'partial' => __( 'Partial observation coverage', 'peptide-core' ),
			'available' => __( 'Available', 'peptide-core' ),
			'not_collected' => __( 'No observations have been collected for these records.', 'peptide-core' ),
			'source_unavailable' => __( 'The source reported that country data was unavailable.', 'peptide-core' ),
			'not_collected_and_source_unavailable' => __( 'Records contain both missing observations and source-reported unavailability.', 'peptide-core' ),
			'dependency_unavailable' => __( 'A report dependency is unavailable; counts are unknown.', 'peptide-core' ),
			'known' => __( 'Known', 'peptide-core' ),
			'unknown' => __( 'Observed unknown', 'peptide-core' ),
			'unobserved' => __( 'Unobserved', 'peptide-core' ),
			'coverage' => __( 'Observation coverage', 'peptide-core' ),
			'coverageNA' => __( 'Observation coverage does not apply to this event/unit ranking.', 'peptide-core' ),
			'base' => __( 'Base records', 'peptide-core' ),
			'top' => __( 'Showing %1$d of %2$d matching rows (Top 5).', 'peptide-core' ),
			'allCountries' => __( 'All observed country codes', 'peptide-core' ),
			'productLabel' => _x( 'Product', 'realtime ranking item', 'peptide-core' ),
			'countryLabel' => _x( 'Country', 'realtime ranking item', 'peptide-core' ),
			'sourceLabel' => _x( 'Source', 'realtime ranking item', 'peptide-core' ),
			'collectionLabel' => _x( 'Collection', 'realtime ranking item', 'peptide-core' ),
			'pageLabel' => _x( 'Page', 'realtime ranking item', 'peptide-core' ),
			'count' => __( 'Count', 'peptide-core' ),
			'share' => __( 'Share of page views', 'peptide-core' ),
			'window' => __( '%d minutes', 'peptide-core' ),
			'history' => __( 'Selected calendar range', 'peptide-core' ),
			'captured_missing' => __( 'Captured source key absent', 'peptide-core' ),
			'captured_empty' => __( 'Captured source observed empty', 'peptide-core' ),
			'noOutline' => __( 'No outline in this map', 'peptide-core' ),
			'zoomIn' => __( 'Zoom map in', 'peptide-core' ),
			'zoomOut' => __( 'Zoom map out', 'peptide-core' ),
			'resetMap' => __( 'Reset map', 'peptide-core' ),
			'mapHelp' => __( 'Offline country outlines show source-reported visitor counts, not visitor coordinates. Countries without an outline remain in the table. Use arrow keys to pan the focused map; page scrolling is unchanged.', 'peptide-core' ),
			'geoHelp' => __( 'Country observations are source reported, not verified by IP. Visitor counts use each visit’s latest country observation before the cutoff; one visitor may appear in several countries. Popular countries counts each page view instead.', 'peptide-core' ),
			'sourceHelp' => __( 'Sources use the first successfully captured visit snapshot and preserve absent, empty and case-sensitive values. No direct source is inferred. Late claims can update an earlier cutoff; this is not an as-of snapshot.', 'peptide-core' ),
			'collectionHelp' => __( 'Collections count saved collection observations, not today’s product categories or guessed URL paths. Deleted collections retain their identifier.', 'peptide-core' ),
			'rangeHelp' => __( 'Each live panel has its own window. All historical panels use the accepted calendar dates. Counts may change after late observations; visitor counts across rows are not additive.', 'peptide-core' ),
		);
	}

	private static function realtime_filter_strings(): array {
		return array(
			'entry_domain' => __( 'Entry domain (first captured)', 'peptide-core' ),
			'utm_source' => __( 'UTM source (first captured)', 'peptide-core' ),
			'all' => __( 'All entry sources', 'peptide-core' ),
			'unobserved' => __( 'No entry attribution observed', 'peptide-core' ),
			'missing' => __( 'Captured without a UTM source key', 'peptide-core' ),
			'empty' => __( 'Captured empty UTM source', 'peptide-core' ),
			'help' => __( 'Entry filters use the first successfully captured visit snapshot. Domain and UTM conditions are combined with AND. Orders use creation time and their original visit entry, not revenue from visits in the selected period. Values preserve case; unknown is not direct traffic.', 'peptide-core' ),
			'search' => __( 'Search entry values', 'peptide-core' ),
			'more' => __( 'More entry values', 'peptide-core' ),
			'previous' => __( 'Previous entry values', 'peptide-core' ),
			'notObserved' => __( 'Selected entry value was not observed in this period.', 'peptide-core' ),
			'notObservedOption' => __( 'Not observed in this period', 'peptide-core' ),
			'reset' => __( 'Reset entry filters to All', 'peptide-core' ),
			'loading' => __( 'Loading entry-filtered data…', 'peptide-core' ),
			'stale' => __( 'Showing the last accepted entry filter; the new request has not succeeded.', 'peptide-core' ),
			'error' => __( 'Entry-filter data could not be loaded. Retry or reset to All.', 'peptide-core' ),
			'urlError' => __( 'Entry filters in the URL are invalid. Correct them or reset to All.', 'peptide-core' ),
			'unavailable' => __( 'Entry filters are unavailable; the All report remains available.', 'peptide-core' ),
			'optionsError' => __( 'Entry values could not be loaded. The accepted report remains unchanged.', 'peptide-core' ),
			'retry' => __( 'Retry entry filters', 'peptide-core' ),
			'coverage' => __( 'Candidate coverage: distinct visits', 'peptide-core' ),
			'captured' => __( 'Captured entry snapshots', 'peptide-core' ),
			'v0' => __( 'Visits without captured attribution', 'peptide-core' ),
			'late_capture' => __( 'Snapshots received after this cutoff', 'peptide-core' ),
			'unlinked_events' => __( 'Unlinked eligible events (separate count)', 'peptide-core' ),
			'unlinked_orders' => __( 'Unlinked eligible orders (separate count)', 'peptide-core' ),
		);
	}

	private static function realtime_strings(): array {
		return self::visitor_funnel_strings() + array(
			'dimensions' => self::realtime_dimension_strings(),
			'filters' => self::realtime_filter_strings(),
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
			'notYet'         => __( 'The visitor map, countries, sources and collections need data that is not collected yet.', 'peptide-core' ),
			'trackingOff'    => __( 'Visitor tracking is off, so visitor numbers, conversion and the funnels stay at zero.', 'peptide-core' ),
			'live'          => __( 'Real time', 'peptide-core' ),
			'todayOption'   => __( 'Today', 'peptide-core' ),
			'yesterday'     => __( 'Yesterday', 'peptide-core' ),
			'from'          => __( 'Start date', 'peptide-core' ),
			'to'            => __( 'End date', 'peptide-core' ),
			'apply'         => __( 'Query', 'peptide-core' ),
			'custom'        => __( 'Custom dates', 'peptide-core' ),
			'period'        => __( 'Selected period', 'peptide-core' ),
			'historyVisits' => __( 'Period visits', 'peptide-core' ),
			'historySales'  => __( 'Period sales', 'peptide-core' ),
			'historyPower'  => __( 'Period buying power', 'peptide-core' ),
			'historyFunnel' => __( 'Visitor behaviour in this period', 'peptide-core' ),
			'historyChart'  => __( 'Visitors by visit start time', 'peptide-core' ),
			'historyViewed'=> __( 'Most viewed products in this period', 'peptide-core' ),
			'historyCarted'=> __( 'Most added to cart in this period', 'peptide-core' ),
			'historySold'  => __( 'Best sellers in this period', 'peptide-core' ),
			'historyPages' => __( 'Popular pages in this period', 'peptide-core' ),
			'manualRefresh'=> __( 'History updates when you query or refresh.', 'peptide-core' ),
			'rangeError'   => __( 'Enter valid start and end dates in order, up to 366 days.', 'peptide-core' ),
			'historyNote'  => __( 'Visitors, funnel and chart use visits that started in this period. A visitor counts once in the period and once in each chart bucket they visited. Page views use event time. Paid orders use creation time, exclude test orders and subtract current refunds. Completed payment counts visitors in the funnel, and orders in the cards.', 'peptide-core' ),
		);
	}

	/** 运营数据页脚本里的文字：12 个指标、漏斗的五层、表头和说明，以及九张趋势图的标题、说明和提示。 */
	private static function operations_strings(): array {
		return self::visitor_funnel_strings() + self::export_strings() + array(
			'checkoutPanel'   => self::operations_checkout_strings(),
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
			'trendVisitors'   => __( 'Visitor count', 'peptide-core' ),
			'trendPv'         => __( 'PV', 'peptide-core' ),
			'trendOrders'     => __( 'Order count', 'peptide-core' ),
			'trendSales'      => __( 'Sales', 'peptide-core' ),
			'trendUnits'      => __( 'Units per order', 'peptide-core' ),
			'trendAov'        => __( 'Average order value', 'peptide-core' ),
			'trendRepRate'    => __( 'Repurchase rate', 'peptide-core' ),
			'trendRepOrders'  => __( 'Repurchase orders', 'peptide-core' ),
			'trendRepSales'   => __( 'Repurchase sales', 'peptide-core' ),
			'trendRepAov'     => __( 'Repurchase average order value', 'peptide-core' ),
			'helpUnits'       => __( 'Paid units / paid orders', 'peptide-core' ),
			'helpRepRate'     => __( 'Buyers with more than one purchase / all buyers in the period × 100%', 'peptide-core' ),
			'helpRepOrders'   => __( "Orders from buyers who had bought before; each buyer's first purchase is not a repurchase", 'peptide-core' ),
			'helpRepAov'      => __( 'Repurchase sales / repurchase orders', 'peptide-core' ),
			/* translators: 1: metric name, 2: value */
			'totalLine'       => __( '%1$s: %2$s', 'peptide-core' ),
			'trendsError'     => __( 'The trend charts could not be loaded.', 'peptide-core' ),

			// 排行表（第 7b 步）：标题、表头、查看更多 / 收起、空表和出错的文字，设备和浏览器的名称，十四张表的标题（t_ + 表的 key）。
			'rankings'      => __( 'Rankings', 'peptide-core' ),
			/* translators: 1: visible rows, 2: matching rows */
			'rankShowing'   => __( 'Showing %1$d of %2$d', 'peptide-core' ),
			'rankOpen'      => __( 'Open full results', 'peptide-core' ),
			/* translators: %s: ranking table name */
			'rankOpenLabel' => __( 'Open full results: %s', 'peptide-core' ),
			'rankClose'     => __( 'Close full results', 'peptide-core' ),
			'rankSearch'    => __( 'Search ranking names or keys', 'peptide-core' ),
			'rankPerPage'   => __( 'Per page', 'peptide-core' ),
			'rankPage'      => __( 'Page %1$d of %2$d', 'peptide-core' ),
			'rankTotal'     => __( '%d matching rows', 'peptide-core' ),
			'rankPrev'      => __( 'Previous', 'peptide-core' ),
			'rankNext'      => __( 'Next', 'peptide-core' ),
			'rankRetry'     => __( 'Retry', 'peptide-core' ),
			'rankPoints'    => __( 'Percentage points', 'peptide-core' ),
			'rankExportHelp' => __( 'Export every matching ranking row, across all result pages.', 'peptide-core' ),

			'rankName'      => __( 'Name', 'peptide-core' ),
			'rankMore'      => __( 'View more', 'peptide-core' ),
			'rankLess'      => __( 'Show less', 'peptide-core' ),
			'rankEmpty'     => __( 'No data in this period.', 'peptide-core' ),
			'rankingsError' => __( 'The ranking tables could not be loaded.', 'peptide-core' ),
			'labelDesktop'  => __( 'Desktop', 'peptide-core' ),
			'labelMobile'   => __( 'Mobile', 'peptide-core' ),
			'labelTablet'   => __( 'Tablet', 'peptide-core' ),
			'labelOther'    => __( 'Other', 'peptide-core' ),
			'labelUnknown'  => __( 'Unknown', 'peptide-core' ),

			't_products_units'     => __( 'Best sellers', 'peptide-core' ),
			't_products_sales'     => __( 'Top products by sales', 'peptide-core' ),
			't_products_viewed'    => __( 'Most viewed products', 'peptide-core' ),
			't_products_carted'    => __( 'Most added to cart', 'peptide-core' ),
			't_categories_units'   => __( 'Product categories by units', 'peptide-core' ),
			't_categories_sales'   => __( 'Product categories by sales', 'peptide-core' ),
			't_order_countries'    => __( 'Order countries', 'peptide-core' ),
			't_customer_countries' => __( 'Customer countries', 'peptide-core' ),
			't_visitor_browsers'   => __( 'Visitor browsers', 'peptide-core' ),
			't_order_browsers'     => __( 'Order browsers', 'peptide-core' ),
			't_payment_methods'    => __( 'Payment methods', 'peptide-core' ),
			't_payment_success'    => __( 'Payment success rate', 'peptide-core' ),
			't_coupons'            => __( 'Coupon usage', 'peptide-core' ),
			't_devices'            => __( 'Devices', 'peptide-core' ),
		);
	}

	/** Operations-only labels; existing Conversion output and its translations are untouched. */
	private static function operations_checkout_strings(): array {
		$existing = self::conversion_strings();
		return array_intersect_key( $existing, array_flip( array( 'checkoutTitle', 'checkoutHelp', 'browserObservation', 'serverFact', 'checkoutChannels', 'channelHelp', 'initial', 'user', 'automatic', 'attempts', 'attempt_visits', 'attempt_orders', 'succeeded', 'failed', 'observedStep', 'paymentChannel', 'emptyCheckout', 'emptyChannels', 'localNotice', 'localCard', 'localWallet' ) ) ) + array(
			'blocks' => __( 'Current checkout type: WooCommerce Blocks', 'peptide-core' ),
			'unconfirmed' => __( 'Current checkout type is unconfirmed', 'peptide-core' ),
			'typeHelp' => __( 'Checkout type describes the current configuration only. These observations are not grouped by historical checkout mode.', 'peptide-core' ),
			'currentVisits' => __( 'Current visits', 'peptide-core' ),
			'previousVisits' => __( 'Previous period visits', 'peptide-core' ),
			'relativeChange' => __( 'Relative change (%)', 'peptide-core' ),
			'currentPeriod' => __( 'Current period', 'peptide-core' ),
			'previousPeriod' => __( 'Previous period', 'peptide-core' ),
			'success_rate' => __( 'Processing success rate (%)', 'peptide-core' ),
			'points' => __( 'Percentage points', 'peptide-core' ),
			'unknown' => __( 'Unknown', 'peptide-core' ),
			'details' => __( 'View detailed checkout analysis', 'peptide-core' ),
			'loading' => __( 'Loading checkout observations…', 'peptide-core' ),
			'error' => __( 'Checkout observations could not be loaded.', 'peptide-core' ),
			'retry' => __( 'Retry checkout observations', 'peptide-core' ),
			'applyingRange' => __( 'Applying date range…', 'peptide-core' ),
		);
	}

	/** 商品详情数据页脚本里的文字：商品列、12 个指标的表头、搜索、编辑表头、分页。 */
	private static function products_strings(): array {
		return self::export_strings() + array(
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
			/* translators: %s: metric name */
			'moveUp'      => __( 'Move %s up', 'peptide-core' ),
			/* translators: %s: metric name */
			'moveDown'    => __( 'Move %s down', 'peptide-core' ),
			'exportHelp'  => __( 'Export all matching products and their data-bearing variations, including collapsed variations.', 'peptide-core' ),
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

	private static function export_strings(): array {
		return array(
			'exportCsv' => __( 'Export CSV', 'peptide-core' ),
			'exportBusy' => __( 'Preparing CSV…', 'peptide-core' ),
			'exportDone' => __( 'CSV download started.', 'peptide-core' ),
			'exportError' => __( 'CSV could not be downloaded. Try again, or refresh the page if your session expired.', 'peptide-core' ),
		);
	}

	/** 页面访问统计页脚本里的文字：表头、跳出率说明、搜索、每页条数、总数、分页。 */
	private static function pages_strings(): array {
		return self::export_strings() + array(
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
			'exportHelp'   => __( 'Export all matching page paths, across every result page.', 'peptide-core' ),
			'trackingNote' => __( 'Page visits come from the visitor tracking script in the browser; without it this page stays empty.', 'peptide-core' ),
		);
	}

	/** 转化率详情页脚本里的文字：整体步骤、分组选择、设备和浏览器名、卡片文字和说明。 */
	private static function conversion_strings(): array {
		return self::export_strings() + self::visitor_funnel_strings() + array(
			'retry'          => __( 'Retry', 'peptide-core' ),
			'exportHelp'     => __( 'Export all results in the selected grouping, eight visitor steps, both transition views, observed visits and payment channels. Group figures cover the current period only.', 'peptide-core' ),
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
			'checkoutTitle'  => __( 'Observed checkout steps', 'peptide-core' ),
			'checkoutHelp'   => __( 'Distinct visits grouped by visit start time. Browser observations are best effort and need not form a cumulative funnel; default selections can precede address readiness.', 'peptide-core' ),
			'browserObservation' => __( 'Browser observation', 'peptide-core' ),
			'serverFact'     => __( 'Server fact', 'peptide-core' ),
			'checkoutChannels' => __( 'Payment channels', 'peptide-core' ),
			'channelHelp'    => __( 'Success rate uses distinct orders with observed server processing attempts in this visit cohort. Retries count as separate attempts; outcomes use WooCommerce orders and server payment facts. This differs from the existing all-orders payment success metric.', 'peptide-core' ),
			'initial'        => __( 'Initial / restored selections (visits)', 'peptide-core' ),
			'user'           => __( 'Explicit selections (visits)', 'peptide-core' ),
			'automatic'      => __( 'Automatic changes (visits)', 'peptide-core' ),
			'attempts'       => __( 'Processing attempts (events)', 'peptide-core' ),
			'attempt_visits' => __( 'Processing visits', 'peptide-core' ),
			'attempt_orders' => __( 'Processing orders', 'peptide-core' ),
			'succeeded'      => __( 'Succeeded processing orders', 'peptide-core' ),
			'failed'         => __( 'Failed processing orders', 'peptide-core' ),
			'success_rate'   => __( 'Success / processing orders', 'peptide-core' ),
			'observedStep'   => __( 'Observed step', 'peptide-core' ),
			'currentPeriod'  => __( 'Current', 'peptide-core' ),
			'previousPeriod' => __( 'Previous period', 'peptide-core' ),
			'percentagePoints' => __( 'Percentage points', 'peptide-core' ),
			'periodChange'   => __( 'Change', 'peptide-core' ),
			'paymentChannel' => __( 'Payment channel', 'peptide-core' ),
			'emptyCheckout' => __( 'No checkout signals in this visit-start cohort.', 'peptide-core' ),
			'emptyChannels' => __( 'No observed payment selection or processing in this cohort.', 'peptide-core' ),
			'localNotice' => __( 'Local simulation is enabled. These simulated orders appear in local Data reports; no money is charged.', 'peptide-core' ),
			'localCard' => \PeptideCore\Payments\Local_Payments::title( 'pc_local_card' ),
			'localWallet' => \PeptideCore\Payments\Local_Payments::title( 'pc_local_wallet' ),
		);
	}

	/** 应用统计页脚本里的文字：离开意图的七个指标名、开关状态、设置链接、没有应用时的提示。 */
	private static function apps_strings(): array {
		return array(
			'shown'        => __( 'Shown', 'peptide-core' ),
			'claims'       => __( 'Claimed / spun', 'peptide-core' ),
			'claim_rate'   => __( 'Claim rate', 'peptide-core' ),
			'wins'         => __( 'Prizes won', 'peptide-core' ),
			'closed'       => __( 'Times closed', 'peptide-core' ),
			'orders'       => __( 'Orders placed with the offer', 'peptide-core' ),
			'order_amount' => __( 'Amount of orders placed with the offer', 'peptide-core' ),
			'on'           => __( 'On', 'peptide-core' ),
			'off'          => __( 'Off', 'peptide-core' ),
			'settings'     => __( 'Settings', 'peptide-core' ),
			'noApps'       => __( 'No apps are installed.', 'peptide-core' ),
		);
	}

	/** 落地页转化页脚本里的文字：路径列、十个指标的表头、两个说明、搜索、每页条数、总数、空表、分页、页面说明。 */
	private static function landing_strings(): array {
		return array(
			'showParams'     => __( 'Show allowed URL parameters', 'peptide-core' ),
			'sourceUnknown'  => __( 'Source unknown', 'peptide-core' ),
			'retry'          => __( 'Retry', 'peptide-core' ),
			'applyingRange'  => __( 'Applying date range…', 'peptide-core' ),
			'paramsNote'     => __( 'Groups use the first successfully captured entry host, path and allowed utm_source/utm_medium values. Query value case is preserved. Visits without this observation remain source unknown, separately from captured empty parameters. This does not identify a direct source; storefront collection must supply the optional metadata.', 'peptide-core' ),
			'path'           => __( 'Landing page path', 'peptide-core' ),
			'unknown'        => __( 'Unknown landing page', 'peptide-core' ),
			'uv'             => __( 'UV', 'peptide-core' ),
			'customers'      => __( 'Customer count', 'peptide-core' ),
			'viewed_uv'      => __( 'Viewing UV', 'peptide-core' ),
			'carted_uv'      => __( 'Add-to-cart UV', 'peptide-core' ),
			'paid_uv'        => __( 'Paying UV', 'peptide-core' ),
			'orders'         => __( 'Order count', 'peptide-core' ),
			'conversion'     => __( 'Conversion rate', 'peptide-core' ),
			'sales'          => __( 'Sales', 'peptide-core' ),
			'aov'            => __( 'Average order value', 'peptide-core' ),
			'uv_value'       => __( 'UV value', 'peptide-core' ),
			'uvValueHelp'    => __( 'Sales / UV', 'peptide-core' ),
			'conversionHelp' => __( 'Paid orders / UV × 100%', 'peptide-core' ),
			'open'           => __( 'Open page', 'peptide-core' ),
			'search'         => __( 'Search landing pages', 'peptide-core' ),
			'perPage'        => __( 'Per page', 'peptide-core' ),
			/* translators: %d: number of landing pages */
			'total'          => __( '%d landing pages in total', 'peptide-core' ),
			'noData'         => __( 'No visits or paid orders in this period.', 'peptide-core' ),
			/* translators: 1: current page number, 2: total number of pages */
			'page'           => __( 'Page %1$d of %2$d', 'peptide-core' ),
			'prev'           => __( 'Previous', 'peptide-core' ),
			'next'           => __( 'Next', 'peptide-core' ),
			'note'           => __( 'The landing page is the first page of each visit, without URL parameters. Orders count toward the landing page of the visit they were placed in. Orders without a recorded visit, or placed in a visit that did not start on a page, appear under "Unknown landing page".', 'peptide-core' ),
		);
	}

	/** 邮件详情数据页脚本里的文字：两个合计、表格的列名、成功率说明、没有统计打开和点击的说明、发送记录链接、空表提示。 */
	private static function emails_strings(): array {
		return array(
			'sent'         => __( 'Send attempts', 'peptide-core' ),
			'success_rate' => __( 'Delivery success rate', 'peptide-core' ),
			'name'         => __( 'Email name', 'peptide-core' ),
			'successHelp'  => __( 'Emails accepted by the mail server / send attempts × 100%', 'peptide-core' ),
			'notTracked'   => __( 'Opens and clicks are not tracked yet, so those numbers are not shown. The send log keeps 180 days.', 'peptide-core' ),
			'logLink'      => __( 'View the send log', 'peptide-core' ),
			'noEmails'     => __( 'No emails were sent in this period.', 'peptide-core' ),
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
