<?php
/**
 * 后台“概览”数据大盘（OVERVIEW-01），参照 Shopname 的概览页。用户要求后台首页改成数据大盘。
 *
 * - 左侧菜单最上面的“仪表盘”（用户 2026-10-22 要求：删掉 WordPress 原来的仪表盘，用这个数据大盘代替）。
 *   能管理商店的人（manage_woocommerce）打开后台首页（index.php）时自动来到这里。原来仪表盘里“VialCove 工作台”的待办，
 *   在这里的“待处理”卡片里。WordPress 的“更新”页面仍可以从插件 / 主题页面或 update-core.php 打开。
 * - 右侧“客户追踪”：最近 30 分钟的访客，每人一行，每 10 秒刷新；点进去是这个访客的单独追踪页面（访客数据 → 访客详情）。
 * - 数据：Overview::summary()（待处理、实时、节日、币种）和 Overview::range_data()（今天 / 昨天 / 7 天 / 30 天）。
 *   切换时间段时调用 GET /peptide/v1/overview?range=…，由 assets/overview.js 渲染。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Api\Rest_Controller;
use PeptideCore\Overview;
use PeptideCore\Recovery;

defined( 'ABSPATH' ) || exit;

final class Overview_Page {

	public const SLUG = 'pc-overview';
	public const CAP  = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 5 );
		add_action( 'admin_menu', array( self::class, 'remove_wp_dashboard' ), 999 );
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
		add_menu_page( __( 'Dashboard', 'peptide-core' ), __( 'Dashboard', 'peptide-core' ), self::CAP, self::SLUG, array( self::class, 'render' ), 'dashicons-dashboard', 1 );
	}

	/** 去掉 WordPress 原来的“仪表盘”菜单（只对能看到数据大盘的人）。 */
	public static function remove_wp_dashboard(): void {
		if ( current_user_can( self::CAP ) ) {
			remove_menu_page( 'index.php' );
		}
	}

	/** 后台首页直接显示数据大盘。 */
	public static function redirect_dashboard(): void {
		if ( ! current_user_can( self::CAP ) ) {
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
					'recovery' => Recovery::enabled(),
					// 开着但发不出去（缺店铺地址 / 发件邮箱无效）时，仪表盘照实说明。
					'blocked'  => Recovery::enabled() ? Recovery::blocked_reason() : '',
					'links'    => array(
						'orders'      => admin_url( 'admin.php?page=wc-orders&status=wc-processing' ),
						'onHold'      => admin_url( 'admin.php?page=wc-orders&status=wc-on-hold' ),
						'unpaid'      => admin_url( 'admin.php?page=wc-orders&status=wc-pending' ),
						'failed'      => admin_url( 'admin.php?page=wc-orders&status=wc-failed' ),
						'batches'     => admin_url( 'edit.php?post_type=pc_batch' ),
						'visitors'    => admin_url( 'admin.php?page=' . Tracking_Page::SLUG ),
						'conversion'  => admin_url( 'admin.php?page=' . Tracking_Page::STATS_SLUG ),
						'visitor'     => admin_url( 'admin.php?page=' . Tracking_Page::SLUG . '&visitor=' ),
						'products'    => admin_url( 'edit.php?post_type=product' ),
						'promotions'  => admin_url( 'admin.php?page=' . Promotions_Page::SLUG ),
						'recovery'    => admin_url( 'admin.php?page=' . Recovery_Page::SLUG ),
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
			'recoveryOn'   => __( 'Cart recovery is on: shoppers who left an email get reminder emails automatically.', 'peptide-core' ),
			'recoveryOff'  => __( 'Cart recovery emails are off; follow up on these orders manually or turn them on.', 'peptide-core' ),
			'noAddress'    => __( 'Cart recovery is on, but no reminder is sent until the store address is filled in (US law requires it in marketing emails).', 'peptide-core' ),
			'noSender'     => __( 'Cart recovery is on, but no reminder is sent until a valid sender email is set.', 'peptide-core' ),
			'recoveryLink' => __( 'Cart recovery', 'peptide-core' ),
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
			'metricPick'   => __( 'Chart metric', 'peptide-core' ),
			'metrics'      => array(
				'visitors'   => __( 'Visitor count', 'peptide-core' ),
				'page_views' => __( 'Page views', 'peptide-core' ),
				'orders'     => __( 'Order count', 'peptide-core' ),
				'sales'      => __( 'Sales', 'peptide-core' ),
				'conversion' => __( 'Order conversion', 'peptide-core' ),
			),
			'totalVisitors' => __( 'Total visitors: %d', 'peptide-core' ),
			'topProducts'  => __( 'Top products', 'peptide-core' ),
			'sold'         => __( '%d sold', 'peptide-core' ),
			'noData'       => __( 'No data yet', 'peptide-core' ),
			'trackingOff'  => __( 'Visitor tracking is off, so visitor numbers, conversion and the funnel stay at zero. It is a test feature: turn it on only for test visits.', 'peptide-core' ),
			'trackingLink' => __( 'Visitor data', 'peptide-core' ),
			'liveVisitors' => __( 'Customer tracking', 'peptide-core' ),
			'liveEmpty'    => __( 'No visitors in the last 30 minutes.', 'peptide-core' ),
			'liveOff'      => __( 'Visitor tracking is off. Turn it on in Visitor data to see customers here.', 'peptide-core' ),
			'viewAll'      => __( 'View all', 'peptide-core' ),
			'active'       => __( 'Active', 'peptide-core' ),
			'idle'         => __( 'Idle', 'peptide-core' ),
			'offline'      => __( 'Offline', 'peptide-core' ),
			'away'         => __( 'No signal (away)', 'peptide-core' ),
			/* translators: %s: time, e.g. 5s */
			'ago'          => __( '%s ago', 'peptide-core' ),
			/* translators: %d: number of pages */
			'tabsOpen'     => __( '%d pages open', 'peptide-core' ),
			'pageTypes'    => array(
				'home'           => __( 'Home', 'peptide-core' ),
				'shop'           => __( 'Shop', 'peptide-core' ),
				'product'        => __( 'Product', 'peptide-core' ),
				'cart'           => __( 'Cart', 'peptide-core' ),
				'checkout'       => __( 'Checkout', 'peptide-core' ),
				'account'        => __( 'Account', 'peptide-core' ),
				'order_received' => __( 'Order received', 'peptide-core' ),
				'order_pay'      => __( 'Order payment', 'peptide-core' ),
				'content'        => __( 'Page', 'peptide-core' ),
			),
			/* translators: %d: number of orders */
			'testOrders'   => __( 'Only real orders are counted: %d test order(s) in this period (test payment or demo products) are left out.', 'peptide-core' ),
			'stages'       => array(
				__( 'Visitors', 'peptide-core' ),
				__( 'Viewed a product', 'peptide-core' ),
				__( 'Added to cart', 'peptide-core' ),
				__( 'Reached checkout', 'peptide-core' ),
				__( 'Placed an order', 'peptide-core' ),
				__( 'Paid', 'peptide-core' ),
			),
			'ofVisitors'   => __( '%s of visitors', 'peptide-core' ),
			'stepRate'     => __( 'From previous step', 'peptide-core' ),
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
		echo '<div class="wrap pc-ov-wrap"><h1 class="screen-reader-text">' . esc_html__( 'Dashboard', 'peptide-core' ) . '</h1><div id="pc-overview" class="pc-ov"><p>' . esc_html__( 'Loading…', 'peptide-core' ) . '</p></div></div>';
	}
}
