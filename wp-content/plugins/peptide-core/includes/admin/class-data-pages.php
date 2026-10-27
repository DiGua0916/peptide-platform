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
use PeptideCore\Data\Range;

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
						'permission_callback' => static fn() => current_user_can( self::CAP ) ? true : new \WP_Error( 'pc_forbidden', __( 'Sorry, you are not allowed to view store data.', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) ),
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
					'page'     => $key,
					'title'    => self::titles()[ $key ],
					'range'    => Range::from_params( array() ), // 今天，由服务器按店铺时区算好。
					'timezone' => wp_timezone_string() . ' (UTC' . wp_date( 'P' ) . ')',
					'currency' => array(
						'code'   => get_woocommerce_currency(),
						'symbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
					),
					'strings'  => self::strings(),
				)
			) . ';',
			'before'
		);
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

	public static function render(): void {
		$key = self::current_key();
		if ( ! current_user_can( self::CAP ) || null === $key ) {
			return;
		}
		echo '<div class="wrap pc-data-wrap"><h1 class="screen-reader-text">' . esc_html( self::titles()[ $key ] ) . '</h1><div id="pc-data" class="pc-data" data-page="' . esc_attr( $key ) . '"><p>' . esc_html__( 'Loading…', 'peptide-core' ) . '</p></div></div>';
	}
}
