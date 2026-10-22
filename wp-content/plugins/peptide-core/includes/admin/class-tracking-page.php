<?php
/**
 * 后台“访客数据”（VISITOR-ANALYTICS-TEST-01，测试版）：
 *
 * - 实时访客：最近 30 分钟有信号的访客，每 5 秒刷新；每个标签页单独显示当前页面、在线 / 空闲 / 离线、视频状态。
 * - 访客详情（?visitor=<ID>）：访问历史、同一账号的其他设备，以及每次访问的时间线（前端动作 + 服务端成交事实）。
 * - 转化统计：按日期和设备筛选，访问 → 看商品 → 加购成功 → 进入结账 → 下单 → 支付成功。
 *
 * 页面由 assets/tracking-admin.js 通过 /peptide/v1/track/admin/* 渲染。需要 manage_woocommerce；开关和清空需要 manage_options。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class Tracking_Page {

	public const SLUG       = 'pc-visitors';
	public const STATS_SLUG = 'pc-visitors-stats';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 57 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_post_pc_tracking_toggle', array( self::class, 'handle_toggle' ) );
		add_action( 'admin_post_pc_tracking_purge', array( self::class, 'handle_purge' ) );
	}

	public static function add_menu(): void {
		add_menu_page( __( 'Visitors', 'peptide-core' ), __( 'Visitors', 'peptide-core' ), 'manage_woocommerce', self::SLUG, array( self::class, 'render' ), 'dashicons-visibility', 57 );
		add_submenu_page( self::SLUG, __( 'Live visitors', 'peptide-core' ), __( 'Live visitors', 'peptide-core' ), 'manage_woocommerce', self::SLUG, array( self::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Conversion', 'peptide-core' ), __( 'Conversion', 'peptide-core' ), 'manage_woocommerce', self::STATS_SLUG, array( self::class, 'render' ) );
	}

	private static function view(): string {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::STATS_SLUG === $page ) {
			return 'stats';
		}
		return isset( $_GET['visitor'] ) ? 'visitor' : 'live'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, self::SLUG ) && ! str_ends_with( $hook, self::STATS_SLUG ) ) {
			return;
		}
		$url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$ver = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-tracking-admin', $url . 'tracking-admin.css', array(), $ver( 'tracking-admin.css' ) );
		wp_enqueue_script( 'peptide-core-tracking-admin', $url . 'tracking-admin.js', array( 'wp-api-fetch' ), $ver( 'tracking-admin.js' ), array( 'in_footer' => true ) );
		wp_add_inline_script(
			'peptide-core-tracking-admin',
			'window.pcTrackingAdmin = ' . wp_json_encode(
				array(
					'view'       => self::view(),
					'visitor'    => isset( $_GET['visitor'] ) ? absint( $_GET['visitor'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'visit'      => isset( $_GET['visit'] ) ? absint( $_GET['visit'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'liveUrl'    => admin_url( 'admin.php?page=' . self::SLUG ),
					'refreshMs'  => 5000,
					// 后台时间都按店铺时区显示（IANA 名称时用浏览器的 Intl 换算；只有偏移量时按浏览器本地时间）。
					'timezone'   => wp_timezone_string(),
					'today'      => wp_date( 'Y-m-d' ),
					'weekAgo'    => wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ),
					'strings'    => self::strings(),
				)
			) . ';',
			'before'
		);
	}

	private static function strings(): array {
		return array(
			'active'      => __( 'Active', 'peptide-core' ),
			'idle'        => __( 'Idle', 'peptide-core' ),
			'offline'     => __( 'Offline', 'peptide-core' ),
			'noVisitors'  => __( 'No visitors in the last 30 minutes.', 'peptide-core' ),
			'visitor'     => __( 'Visitor', 'peptide-core' ),
			'device'      => __( 'Device', 'peptide-core' ),
			'tabs'        => __( 'Open pages', 'peptide-core' ),
			'stage'       => __( 'Furthest step', 'peptide-core' ),
			'cart'        => __( 'Cart', 'peptide-core' ),
			/* translators: %d: number of items */
			'cartView'    => __( 'View (%d)', 'peptide-core' ),
			'cartEmpty'   => __( 'Empty', 'peptide-core' ),
			'cartUnknown' => __( 'Not synced yet', 'peptide-core' ),
			'cartExpired' => __( 'Session expired', 'peptide-core' ),
			'cartTitle'   => __( 'Current cart', 'peptide-core' ),
			'cartExpiredNote' => __( 'The store session has expired, so this may no longer be what the customer sees.', 'peptide-core' ),
			'cartUnknownNote' => __( 'No cart activity has been recorded for this visitor yet.', 'peptide-core' ),
			'cartEmptyNote'   => __( 'The cart is empty.', 'peptide-core' ),
			'product'     => __( 'Product', 'peptide-core' ),
			'qty'         => __( 'Qty', 'peptide-core' ),
			'unitPrice'   => __( 'Price', 'peptide-core' ),
			'lineTotal'   => __( 'Total', 'peptide-core' ),
			'subtotal'    => __( 'Subtotal', 'peptide-core' ),
			'discount'    => __( 'Discount', 'peptide-core' ),
			'updated'     => __( 'Updated %s', 'peptide-core' ),
			'close'       => __( 'Close', 'peptide-core' ),
			'visitStart'  => __( 'Visit started', 'peptide-core' ),
			'lastSignal'  => __( 'Last signal', 'peptide-core' ),
			'ago'         => __( '%s ago', 'peptide-core' ),
			'seconds'     => __( '%ds', 'peptide-core' ),
			'minutes'     => __( '%dm', 'peptide-core' ),
			'hours'       => __( '%dh', 'peptide-core' ),
			'visits'      => __( 'Visits', 'peptide-core' ),
			'pageviews'   => __( 'Page views', 'peptide-core' ),
			'events'      => __( 'Events', 'peptide-core' ),
			'duration'    => __( 'Duration', 'peptide-core' ),
			'landing'     => __( 'Landing page', 'peptide-core' ),
			'order'       => __( 'Order', 'peptide-core' ),
			'time'        => __( 'Time', 'peptide-core' ),
			'tab'         => __( 'Tab', 'peptide-core' ),
			'event'       => __( 'Event', 'peptide-core' ),
			'page'        => __( 'Page', 'peptide-core' ),
			'details'     => __( 'Details', 'peptide-core' ),
			'server'      => __( 'Server fact', 'peptide-core' ),
			'back'        => __( '← Live visitors', 'peptide-core' ),
			'otherDevices' => __( 'Same account on other devices / browsers', 'peptide-core' ),
			'firstSeen'   => __( 'First seen', 'peptide-core' ),
			'account'     => __( 'Account', 'peptide-core' ),
			'guest'       => __( 'Guest', 'peptide-core' ),
			'timeline'    => __( 'Timeline', 'peptide-core' ),
			'chooseVisit' => __( 'Choose a visit to see its timeline.', 'peptide-core' ),
			'refresh'     => __( 'Refresh', 'peptide-core' ),
			'loading'     => __( 'Loading…', 'peptide-core' ),
			'error'       => __( 'Could not load: %s', 'peptide-core' ),
			'from'        => __( 'From', 'peptide-core' ),
			'to'          => __( 'To', 'peptide-core' ),
			'allDevices'  => __( 'All devices', 'peptide-core' ),
			'desktop'     => __( 'Desktop', 'peptide-core' ),
			'mobile'      => __( 'Mobile', 'peptide-core' ),
			'tablet'      => __( 'Tablet', 'peptide-core' ),
			'apply'       => __( 'Apply', 'peptide-core' ),
			'visitors'    => __( 'Visitors', 'peptide-core' ),
			'funnel'      => __( 'Conversion funnel', 'peptide-core' ),
			'ofVisits'    => __( '%s of visits', 'peptide-core' ),
			'fromPrev'    => __( '%s from the previous step', 'peptide-core' ),
			'byDay'       => __( 'By day (store time zone)', 'peptide-core' ),
			'day'         => __( 'Day', 'peptide-core' ),
			'carts'       => __( 'Added to cart', 'peptide-core' ),
			'orders'      => __( 'Orders', 'peptide-core' ),
			'paid'        => __( 'Paid', 'peptide-core' ),
			'products'    => __( 'Products', 'peptide-core' ),
			'views'       => __( 'Views', 'peptide-core' ),
			'none'        => __( 'No data in this period.', 'peptide-core' ),
			'video'       => __( 'Video', 'peptide-core' ),
			'stages'      => array(
				__( 'Visited', 'peptide-core' ),
				__( 'Viewed a product', 'peptide-core' ),
				__( 'Added to cart', 'peptide-core' ),
				__( 'Reached checkout', 'peptide-core' ),
				__( 'Placed an order', 'peptide-core' ),
				__( 'Paid', 'peptide-core' ),
			),
			'pageTypes'   => array(
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
			'types'       => array(
				'page_view'               => __( 'Opened page', 'peptide-core' ),
				'product_view'            => __( 'Viewed product', 'peptide-core' ),
				'cart_view'               => __( 'Viewed cart', 'peptide-core' ),
				'checkout_view'           => __( 'Opened checkout', 'peptide-core' ),
				'visibility_change'       => __( 'Visibility changed', 'peptide-core' ),
				'heartbeat'               => __( 'Heartbeat', 'peptide-core' ),
				'page_exit'               => __( 'Left page', 'peptide-core' ),
				'variant_select'          => __( 'Chose option', 'peptide-core' ),
				'add_to_cart_attempt'     => __( 'Clicked add to cart', 'peptide-core' ),
				'checkout_submit_attempt' => __( 'Clicked place order', 'peptide-core' ),
				'cart_open'               => __( 'Opened cart drawer', 'peptide-core' ),
				'cart_close'              => __( 'Closed cart drawer', 'peptide-core' ),
				'cart_change_attempt'     => __( 'Changed cart', 'peptide-core' ),
				'cart_change_response'    => __( 'Cart updated (browser)', 'peptide-core' ),
				'video_play'              => __( 'Video play', 'peptide-core' ),
				'video_pause'             => __( 'Video pause', 'peptide-core' ),
				'video_progress'          => __( 'Video progress', 'peptide-core' ),
				'video_seek'              => __( 'Video seek', 'peptide-core' ),
				'video_end'               => __( 'Video ended', 'peptide-core' ),
				'add_to_cart'             => __( 'Added to cart', 'peptide-core' ),
				'order_placed'            => __( 'Order placed', 'peptide-core' ),
				'payment_success'         => __( 'Payment succeeded', 'peptide-core' ),
				'payment_failed'          => __( 'Payment failed', 'peptide-core' ),
				'order_status'            => __( 'Order status changed', 'peptide-core' ),
				'login'                   => __( 'Logged in', 'peptide-core' ),
				'register'                => __( 'Registered', 'peptide-core' ),
			),
			'hideHeartbeats' => __( 'Hide heartbeats', 'peptide-core' ),
		);
	}

	// ---------- 开关 ----------

	public static function handle_toggle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( 'pc_tracking_toggle' );
		Tracker::set_enabled( ! empty( $_POST['enable'] ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public static function handle_purge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( 'pc_tracking_purge' );
		if ( Schema::installed() ) {
			Schema::purge();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&pc_purged=1' ) );
		exit;
	}

	// ---------- 页面 ----------

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$view    = self::view();
		$enabled = Tracker::enabled();
		$titles  = array(
			'live'    => __( 'Live visitors', 'peptide-core' ),
			'visitor' => __( 'Visitor details', 'peptide-core' ),
			'stats'   => __( 'Conversion', 'peptide-core' ),
		);
		?>
		<div class="wrap pc-trk">
			<h1 class="wp-heading-inline"><?php echo esc_html( $titles[ $view ] ); ?></h1>
			<span class="pc-trk-chip <?php echo $enabled ? 'is-on' : 'is-off'; ?>"><?php echo $enabled ? esc_html__( 'Collecting (test mode)', 'peptide-core' ) : esc_html__( 'Tracking is off', 'peptide-core' ); ?></span>
			<div class="notice notice-warning inline pc-trk-test"><p><?php esc_html_e( 'Test version: data here comes from test visits on this site. There is no consent banner or retention policy yet, so do not turn this on for real customers.', 'peptide-core' ); ?></p></div>
			<?php if ( isset( $_GET['pc_purged'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'All tracking test data was deleted.', 'peptide-core' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'live' === $view && current_user_can( 'manage_options' ) ) : ?>
				<div class="pc-trk-controls">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pc_tracking_toggle">
						<?php wp_nonce_field( 'pc_tracking_toggle' ); ?>
						<?php if ( $enabled ) : ?>
							<button class="button"><?php esc_html_e( 'Turn off tracking', 'peptide-core' ); ?></button>
						<?php else : ?>
							<input type="hidden" name="enable" value="1">
							<button class="button button-primary"><?php esc_html_e( 'Turn on test tracking', 'peptide-core' ); ?></button>
						<?php endif; ?>
					</form>
					<?php if ( Schema::installed() ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete all tracking test data? This cannot be undone.', 'peptide-core' ) ) ); ?>);">
							<input type="hidden" name="action" value="pc_tracking_purge">
							<?php wp_nonce_field( 'pc_tracking_purge' ); ?>
							<button class="button button-link-delete"><?php esc_html_e( 'Delete test data', 'peptide-core' ); ?></button>
						</form>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Staff accounts (shop managers, administrators) are never tracked. Use a customer test account or a private window to test.', 'peptide-core' ); ?></p>
				</div>
			<?php endif; ?>
			<div id="pc-trk-app" class="pc-trk-app"><p><?php esc_html_e( 'Loading…', 'peptide-core' ); ?></p></div>
		</div>
		<?php
	}
}
