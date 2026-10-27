<?php
/**
 * 后台“访客数据”（VISITOR-ANALYTICS-TEST-01；TRACKING-LIVE-01 起是正式版，开关就是正式追踪的开关）：
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
	public const STATS_SLUG   = 'pc-visitors-stats';
	public const ARCHIVE_SLUG = 'pc-visitors-archive';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 57 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_post_pc_tracking_toggle', array( self::class, 'handle_toggle' ) );
		add_action( 'admin_post_pc_tracking_purge', array( self::class, 'handle_purge' ) );
		add_action( 'admin_post_pc_tracking_settings', array( self::class, 'handle_settings' ) );
	}

	public static function add_menu(): void {
		add_menu_page( __( 'Visitors', 'peptide-core' ), __( 'Visitors', 'peptide-core' ), 'manage_woocommerce', self::SLUG, array( self::class, 'render' ), 'dashicons-visibility', 57 );
		add_submenu_page( self::SLUG, __( 'Live visitors', 'peptide-core' ), __( 'Live visitors', 'peptide-core' ), 'manage_woocommerce', self::SLUG, array( self::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Visitor archive', 'peptide-core' ), __( 'Visitor archive', 'peptide-core' ), 'manage_woocommerce', self::ARCHIVE_SLUG, array( self::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Conversion', 'peptide-core' ), __( 'Conversion', 'peptide-core' ), 'manage_woocommerce', self::STATS_SLUG, array( self::class, 'render' ) );
	}

	private static function view(): string {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::STATS_SLUG === $page ) {
			return 'stats';
		}
		if ( self::ARCHIVE_SLUG === $page ) {
			return 'archive';
		}
		return isset( $_GET['visitor'] ) ? 'visitor' : 'live'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, self::SLUG ) && ! str_ends_with( $hook, self::STATS_SLUG ) && ! str_ends_with( $hook, self::ARCHIVE_SLUG ) ) {
			return;
		}
		$url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$ver = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-tracking-admin', $url . 'tracking-admin.css', array(), $ver( 'tracking-admin.css' ) );
		// 购物车弹窗组件由 Codex 编写（VISITOR-CART-01，独立浏览器验收），插件只负责接口和挂载。
		wp_enqueue_style( 'peptide-core-visitor-cart', $url . 'visitor-cart.css', array(), $ver( 'visitor-cart.css' ) );
		wp_register_script( 'peptide-core-visitor-cart', $url . 'visitor-cart.js', array(), $ver( 'visitor-cart.js' ), array( 'in_footer' => true ) );
		wp_register_script( 'peptide-core-visitor-cart-adapter', $url . 'visitor-cart-adapter.js', array( 'peptide-core-visitor-cart' ), $ver( 'visitor-cart-adapter.js' ), array( 'in_footer' => true ) );
		wp_enqueue_script( 'peptide-core-tracking-admin', $url . 'tracking-admin.js', array( 'wp-api-fetch', 'peptide-core-visitor-cart-adapter' ), $ver( 'tracking-admin.js' ), array( 'in_footer' => true ) );
		wp_add_inline_script(
			'peptide-core-tracking-admin',
			'window.pcTrackingAdmin = ' . wp_json_encode(
				array(
					'view'       => self::view(),
					'visitor'    => isset( $_GET['visitor'] ) ? absint( $_GET['visitor'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'visit'      => isset( $_GET['visit'] ) ? absint( $_GET['visit'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'liveUrl'    => admin_url( 'admin.php?page=' . self::SLUG ),
					'archiveUrl' => admin_url( 'admin.php?page=' . self::ARCHIVE_SLUG ),
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
			'away'        => __( 'No signal (away)', 'peptide-core' ),
			'left'        => __( 'Left the site', 'peptide-core' ),
			'gone'        => __( 'Left (timed out)', 'peptide-core' ),
			'status'      => __( 'Status', 'peptide-core' ),
			'archive'     => __( 'Visitor archive', 'peptide-core' ),
			'archiveNote' => __( 'Visits that have ended: every page was closed (leave signal), or no signal for 30 minutes (estimated). The leave time is the last signal, not an exact moment. A visitor who comes back shows up in Live visitors again; nothing is deleted.', 'peptide-core' ),
			'archiveEmpty' => __( 'No ended visits match these filters.', 'peptide-core' ),
			'visitTime'   => __( 'Visit (start → last signal)', 'peptide-core' ),
			'leftReason'  => __( 'How the visit ended', 'peptide-core' ),
			'reasonExit'  => __( 'Leave signal', 'peptide-core' ),
			'reasonTimeout' => __( 'No signal for 30 min', 'peptide-core' ),
			'leftEstimated' => __( 'Estimated', 'peptide-core' ),
			'lastPage'    => __( 'Last page', 'peptide-core' ),
			'searchVisitor' => __( 'Visitor #ID, name or email', 'peptide-core' ),
			'loadMore'    => __( 'Load more', 'peptide-core' ),
			'offline'     => __( 'Offline', 'peptide-core' ),
			'noVisitors'  => __( 'No visitors in the last 30 minutes.', 'peptide-core' ),
			'visitor'     => __( 'Visitor', 'peptide-core' ),
			'device'      => __( 'Device', 'peptide-core' ),
			'tabs'        => __( 'Open pages', 'peptide-core' ),
			'stage'       => __( 'Furthest step', 'peptide-core' ),
			'cart'        => __( 'Cart', 'peptide-core' ),
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

	public static function handle_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( 'pc_tracking_settings' );
		Tracker::save_settings( absint( $_POST['retention_days'] ?? 0 ), ! empty( $_POST['gpc'] ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&pc_saved=1' ) );
		exit;
	}

	public static function handle_purge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( 'pc_tracking_purge' );
		if ( Schema::installed() ) {
			Schema::purge_test();
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
			'archive' => __( 'Visitor archive', 'peptide-core' ),
		);
		?>
		<div class="wrap pc-trk">
			<h1 class="wp-heading-inline"><?php echo esc_html( $titles[ $view ] ); ?></h1>
			<span class="pc-trk-chip <?php echo $enabled ? 'is-on' : 'is-off'; ?>"><?php echo $enabled ? esc_html__( 'Collecting', 'peptide-core' ) : esc_html__( 'Tracking is off', 'peptide-core' ); ?></span>
			<?php if ( isset( $_GET['pc_purged'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The data from the test version was deleted.', 'peptide-core' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['pc_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'peptide-core' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'live' === $view && current_user_can( 'manage_options' ) ) : ?>
				<?php $s = Tracker::settings(); ?>
				<div class="pc-trk-controls">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-trk-switch-form">
						<input type="hidden" name="action" value="pc_tracking_toggle">
						<?php wp_nonce_field( 'pc_tracking_toggle' ); ?>
						<?php if ( ! $enabled ) : ?>
							<input type="hidden" name="enable" value="1">
						<?php endif; ?>
						<button type="submit" class="pc-trk-switch <?php echo $enabled ? 'is-on' : 'is-off'; ?>" role="switch" aria-checked="<?php echo $enabled ? 'true' : 'false'; ?>">
							<span class="pc-trk-switch__track" aria-hidden="true"><span class="pc-trk-switch__knob"></span></span>
							<span class="pc-trk-switch__label"><?php esc_html_e( 'Visitor tracking', 'peptide-core' ); ?></span>
							<span class="pc-trk-switch__state"><?php echo $enabled ? esc_html__( 'On', 'peptide-core' ) : esc_html__( 'Off', 'peptide-core' ); ?></span>
						</button>
					</form>
					<p class="description"><?php echo $enabled ? esc_html__( 'Customers’ visits are being recorded. Click the switch to stop; the data already collected is kept.', 'peptide-core' ) : esc_html__( 'Nothing is recorded. Click the switch to start recording customers’ visits.', 'peptide-core' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-trk-settings">
						<input type="hidden" name="action" value="pc_tracking_settings">
						<?php wp_nonce_field( 'pc_tracking_settings' ); ?>
						<label><?php esc_html_e( 'Keep data for', 'peptide-core' ); ?>
							<select name="retention_days">
								<?php foreach ( Tracker::RETENTION_CHOICES as $d ) : ?>
									<?php /* translators: %d: number of days */ ?>
									<option value="<?php echo esc_attr( (string) $d ); ?>" <?php selected( $s['retention_days'], $d ); ?>><?php echo esc_html( sprintf( _n( '%d day', '%d days', $d, 'peptide-core' ), $d ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label><input type="checkbox" name="gpc" value="1" <?php checked( $s['gpc'] ); ?>> <?php esc_html_e( 'Do not record browsers that send the Global Privacy Control (GPC) signal', 'peptide-core' ); ?></label>
						<button class="button"><?php esc_html_e( 'Save', 'peptide-core' ); ?></button>
					</form>
					<p class="description"><?php esc_html_e( 'Never recorded: staff accounts, browsers with GPC (when ticked) and browsers that opened the opt-out link (?pc_px_optout=1). Older data is deleted every day. A customer’s visits are included when you use Export / Erase personal data. Mention this in your privacy policy; suggested text is in the WordPress privacy policy guide.', 'peptide-core' ); ?></p>
					<?php $test_left = Schema::test_visitors(); ?>
					<?php if ( $test_left ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-trk-purge" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete the data from the test version? This cannot be undone.', 'peptide-core' ) ) ); ?>);">
							<input type="hidden" name="action" value="pc_tracking_purge">
							<?php wp_nonce_field( 'pc_tracking_purge' ); ?>
							<?php /* translators: %d: number of visitors */ ?>
							<span><?php echo esc_html( sprintf( _n( '%d visitor from the test version is still here.', '%d visitors from the test version are still here.', $test_left, 'peptide-core' ), $test_left ) ); ?></span>
							<button class="button button-link-delete"><?php esc_html_e( 'Delete test data', 'peptide-core' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div id="pc-trk-app" class="pc-trk-app"><p><?php esc_html_e( 'Loading…', 'peptide-core' ); ?></p></div>
		</div>
		<?php
	}
}
