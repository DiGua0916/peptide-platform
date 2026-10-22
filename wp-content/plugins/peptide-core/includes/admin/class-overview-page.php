<?php
/**
 * 后台“概览”数据大盘（OVERVIEW-01），参照 Shopname 的概览页。用户要求后台首页改成数据大盘。
 *
 * - 左侧菜单最上面的“概览”。能管理商店的人（manage_woocommerce）打开后台首页（index.php）时自动来到这里；
 *   WordPress 原来的仪表盘（含 VialCove 工作台）仍可以从页面右上角的链接打开（index.php?pc_classic=1）。
 * - 数据：Overview::summary()（待处理、实时、节日、币种）和 Overview::range_data()（今天 / 昨天 / 7 天 / 30 天）。
 *   切换时间段时调用 GET /peptide/v1/overview?range=…，由 assets/overview.js 渲染。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Api\Rest_Controller;
use PeptideCore\Overview;

defined( 'ABSPATH' ) || exit;

final class Overview_Page {

	public const SLUG = 'pc-overview';
	public const CAP  = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 5 );
		add_action( 'load-index.php', array( self::class, 'redirect_dashboard' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/** REST 路由在任何请求里都要注册（后台页面通过 REST 取数据）。 */
	public static function register_rest(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					Rest_Controller::ROUTE_NAMESPACE,
					'/overview',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static function ( \WP_REST_Request $request ) {
							$res = new \WP_REST_Response(
								array(
									'summary' => Overview::summary(),
									'range'   => Overview::range_data( is_string( $request['range'] ) ? sanitize_key( $request['range'] ) : 'today' ),
								)
							);
							$res->header( 'Cache-Control', 'no-store' );
							return $res;
						},
						'permission_callback' => static fn() => current_user_can( self::CAP ) ? true : new \WP_Error( 'pc_forbidden', __( 'Sorry, you are not allowed to view store data.', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) ),
					)
				);
			}
		);
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function add_menu(): void {
		add_menu_page( __( 'Overview', 'peptide-core' ), __( 'Overview', 'peptide-core' ), self::CAP, self::SLUG, array( self::class, 'render' ), 'dashicons-chart-area', 1 );
	}

	/** 后台首页直接显示概览；带 pc_classic=1 时显示 WordPress 原来的仪表盘。 */
	public static function redirect_dashboard(): void {
		if ( ! current_user_can( self::CAP ) || isset( $_GET['pc_classic'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		$url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$ver = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-overview', $url . 'overview.css', array(), $ver( 'overview.css' ) );
		wp_enqueue_script( 'peptide-core-overview', $url . 'overview.js', array( 'wp-api-fetch' ), $ver( 'overview.js' ), array( 'in_footer' => true ) );
		wp_add_inline_script(
			'peptide-core-overview',
			'window.pcOverview = ' . wp_json_encode(
				array(
					'summary'  => Overview::summary(),
					'range'    => Overview::range_data( 'today' ),
					'links'    => array(
						'orders'      => admin_url( 'admin.php?page=wc-orders&status=wc-processing' ),
						'onHold'      => admin_url( 'admin.php?page=wc-orders&status=wc-on-hold' ),
						'unpaid'      => admin_url( 'admin.php?page=wc-orders&status=wc-pending' ),
						'failed'      => admin_url( 'admin.php?page=wc-orders&status=wc-failed' ),
						'batches'     => admin_url( 'edit.php?post_type=pc_batch' ),
						'visitors'    => admin_url( 'admin.php?page=' . Tracking_Page::SLUG ),
						'conversion'  => admin_url( 'admin.php?page=' . Tracking_Page::STATS_SLUG ),
						'classic'     => admin_url( 'index.php?pc_classic=1' ),
						'products'    => admin_url( 'edit.php?post_type=product' ),
						'promotions'  => admin_url( 'edit.php?post_type=pc_promotion' ),
					),
					'strings'  => self::strings(),
				)
			) . ';',
			'before'
		);
	}

	private static function strings(): array {
		return array(
			/* translators: %s: store name */
			'morning'      => __( '%s, good morning! Here is today’s overview.', 'peptide-core' ),
			/* translators: %s: store name */
			'afternoon'    => __( '%s, good afternoon! Here is today’s overview.', 'peptide-core' ),
			/* translators: %s: store name */
			'evening'      => __( '%s, good evening! Here is today’s overview.', 'peptide-core' ),
			'storeTime'    => __( 'Store time zone', 'peptide-core' ),
			'sales'        => __( 'Sales', 'peptide-core' ),
			'visitors'     => __( 'Visitors', 'peptide-core' ),
			'registered'   => __( 'New sign-ups', 'peptide-core' ),
			'orders'       => __( 'Orders', 'peptide-core' ),
			'conversion'   => __( 'Order conversion', 'peptide-core' ),
			'aov'          => __( 'Average order value', 'peptide-core' ),
			'returning'    => __( 'Returning visitors', 'peptide-core' ),
			'todo'         => __( 'To do', 'peptide-core' ),
			'toShip'       => __( 'Paid, waiting to ship', 'peptide-core' ),
			'onHold'       => __( 'On hold', 'peptide-core' ),
			'missingBatch' => __( 'Orders missing a batch', 'peptide-core' ),
			'noReport'     => __( 'Public batches without a report', 'peptide-core' ),
			'unfinished'   => __( 'Unfinished orders (last 7 days)', 'peptide-core' ),
			'unpaid'       => __( 'Waiting for payment', 'peptide-core' ),
			'failed'       => __( 'Payment failed', 'peptide-core' ),
			'unfinishedNote' => __( 'Abandoned-cart recovery emails are not set up yet; follow up on these orders manually.', 'peptide-core' ),
			'currency'     => __( 'Currency', 'peptide-core' ),
			'default'      => __( 'Default', 'peptide-core' ),
			'holidays'     => __( 'Upcoming US shopping days', 'peptide-core' ),
			'inDays'       => __( 'in %d days', 'peptide-core' ),
			'todayTag'     => __( 'today', 'peptide-core' ),
			'live'         => __( 'Real time', 'peptide-core' ),
			'online'       => __( 'Visitors online', 'peptide-core' ),
			'todayOrders'  => __( 'Orders today', 'peptide-core' ),
			'todaySales'   => __( 'Sales today', 'peptide-core' ),
			'more'         => __( 'More', 'peptide-core' ),
			'today'        => __( 'Today', 'peptide-core' ),
			'yesterday'    => __( 'Yesterday', 'peptide-core' ),
			'7d'           => __( 'Last 7 days', 'peptide-core' ),
			'30d'          => __( 'Last 30 days', 'peptide-core' ),
			'funnel'       => __( 'Conversion', 'peptide-core' ),
			'trend'        => __( 'Visitors and orders', 'peptide-core' ),
			'totalVisitors' => __( 'Total visitors: %d', 'peptide-core' ),
			'topProducts'  => __( 'Top products', 'peptide-core' ),
			'sold'         => __( '%d sold', 'peptide-core' ),
			'noData'       => __( 'No data yet', 'peptide-core' ),
			'trackingOff'  => __( 'Visitor tracking is off, so visitor numbers, conversion and the funnel stay at zero. It is a test feature: turn it on only for test visits.', 'peptide-core' ),
			'trackingLink' => __( 'Visitor data', 'peptide-core' ),
			'classic'      => __( 'WordPress dashboard', 'peptide-core' ),
			'stages'       => array(
				__( 'Visitors', 'peptide-core' ),
				__( 'Viewed a product', 'peptide-core' ),
				__( 'Added to cart', 'peptide-core' ),
				__( 'Reached checkout', 'peptide-core' ),
				__( 'Placed an order', 'peptide-core' ),
				__( 'Paid', 'peptide-core' ),
			),
			'ofVisitors'   => __( '%s of visitors', 'peptide-core' ),
			'holidayNames' => array(
				'new_year'     => __( 'New Year’s Day', 'peptide-core' ),
				'valentine'    => __( 'Valentine’s Day', 'peptide-core' ),
				'mothers'      => __( 'Mother’s Day', 'peptide-core' ),
				'memorial'     => __( 'Memorial Day', 'peptide-core' ),
				'fathers'      => __( 'Father’s Day', 'peptide-core' ),
				'independence' => __( 'Independence Day', 'peptide-core' ),
				'labor'        => __( 'Labor Day', 'peptide-core' ),
				'halloween'    => __( 'Halloween', 'peptide-core' ),
				'thanksgiving' => __( 'Thanksgiving', 'peptide-core' ),
				'black_friday' => __( 'Black Friday', 'peptide-core' ),
				'cyber_monday' => __( 'Cyber Monday', 'peptide-core' ),
				'christmas'    => __( 'Christmas', 'peptide-core' ),
			),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="wrap pc-ov-wrap"><h1 class="screen-reader-text">' . esc_html__( 'Overview', 'peptide-core' ) . '</h1><div id="pc-overview" class="pc-ov"><p>' . esc_html__( 'Loading…', 'peptide-core' ) . '</p></div></div>';
	}
}
