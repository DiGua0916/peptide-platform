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
		add_action( 'admin_post_pc_tracking_purge', array( self::class, 'handle_purge' ) );
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
		wp_enqueue_style( 'peptide-core-tracking-insights', $url . 'tracking-insights.css', array( 'peptide-core-tracking-admin' ), $ver( 'tracking-insights.css' ) );
		wp_enqueue_script( 'peptide-core-tracking-insights', $url . 'tracking-insights.js', array( 'wp-api-fetch', 'peptide-core-tracking-admin' ), $ver( 'tracking-insights.js' ), true );
		wp_enqueue_script( 'peptide-core-tracking-admin', $url . 'tracking-admin.js', array( 'wp-api-fetch', 'peptide-core-visitor-cart-adapter' ), $ver( 'tracking-admin.js' ), array( 'in_footer' => true ) );
		wp_enqueue_script( 'peptide-core-tracking-refresh', $url . 'tracking-refresh.js', array( 'peptide-core-tracking-admin' ), $ver( 'tracking-refresh.js' ), true );
		wp_add_inline_script(
			'peptide-core-tracking-admin',
			'window.pcTrackingAdmin = ' . wp_json_encode(
				array(
					'view'       => self::view(),
					'visitor'    => isset( $_GET['visitor'] ) ? absint( $_GET['visitor'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'visit'      => isset( $_GET['visit'] ) ? absint( $_GET['visit'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'liveUrl'    => admin_url( 'admin.php?page=' . self::SLUG ),
					'archiveUrl' => admin_url( 'admin.php?page=' . self::ARCHIVE_SLUG ),
					'refreshMs'  => 15000, // Was 5 s; each poll is a full WordPress request (code review 2026-11-06).
					// 后台时间都按店铺时区显示（IANA 名称时用浏览器的 Intl 换算；只有偏移量时按浏览器本地时间）。
					'timezone'   => wp_timezone_string(),
					'today'      => wp_date( 'Y-m-d' ),
					// 时间线 / 行为概览用的中文名（2026-11-09）。
					'moduleLabels'   => \PeptideCore\Tracking\Labels::modules(),
					'shippingLabels' => \PeptideCore\Tracking\Labels::shipping(),
					'paymentLabels'  => \PeptideCore\Tracking\Labels::payments(),
					'orderStatuses'  => \PeptideCore\Tracking\Labels::order_statuses(),
					// 时间线里用的白话标签，覆盖 types 里的翻译（2026-11-09）。
					'typeLabelsZh'   => \PeptideCore\Tracking\Labels::type_labels_zh(),
					'weekAgo'    => wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ),
					'strings'    => self::strings(),
				)
			) . ';',
			'before'
		);
	}

	/** 店铺后台是中文时，把统计术语换成白话（2026-11-09，用户要求“看得懂”）。 */
	private static function strings(): array {
		$base = self::strings_base();
		return str_starts_with( (string) determine_locale(), 'zh' ) ? array_replace_recursive( $base, self::strings_zh() ) : $base;
	}

	private static function strings_zh(): array {
		return array(
			'active'      => '正在浏览',
			'idle'        => '停住了（页面在后台或没操作）',
			'away'        => '刚离开（30 分钟内没动静）',
			'left'        => '已离开',
			'gone'        => '已离开（超过 30 分钟没动静）',
			'archiveNote' => '所有访问按进站时间从新到旧排，还在逛的标“正在浏览”。离开的判断：关掉了全部页面，或 30 分钟没有动静；离开时间取最后一次动作，不是精确关页时间。',
			'archiveEmpty' => '没有符合条件的已结束访问。',
			'visitTime'   => '访问时间（开始 → 最后一次动作）',
			'leftReason'  => '怎么离开的',
			'reasonExit'  => '关掉了页面',
			'reasonTimeout' => '30 分钟没动静',
			'leftEstimated' => '估算',
			'lastPage'    => '最后看的页面',
			'searchVisitor' => '访客编号、姓名或邮箱',
			'noVisitors'  => '最近 30 分钟没有访客。',
			'tabs'        => '开着的页面',
			'stage'       => '走到哪一步',
			'visitStart'  => '什么时候来的',
			'lastSignal'  => '最后一次动作',
			'events'      => '记录到的动作',
			'duration'    => '停留时长',
			'landing'     => '从哪个页面进来',
			'tab'         => '标签页',
			'event'       => '动作',
			'details'     => '说明',
			'server'      => '服务器记录',
			'otherDevices' => '同一个账号在其他设备或浏览器上',
			'firstSeen'   => '第一次来',
			'guest'       => '游客（没登录）',
			'chooseVisit' => '点一次访问，看它的完整动作。',
			'hideHeartbeats' => '隐藏“页面还开着”的记录',
			'stages'      => array( '只是逛了逛', '看了商品', '加了购物车', '进了结账页', '下了单', '已付款' ),
			'pageTypes'   => array(
				'home'           => '首页',
				'shop'           => '商品列表',
				'product'        => '商品页',
				'cart'           => '购物车',
				'checkout'       => '结账页',
				'account'        => '我的账户',
				'order_received' => '下单成功页',
				'order_pay'      => '订单付款页',
				'content'        => '普通页面',
			),
		);
	}

	private static function strings_base(): array {
		return array(
			'pageKind' => __( 'Page kind', 'peptide-core' ),
			'collectionId' => __( 'Collection ID', 'peptide-core' ),
			'countryObservation' => __( 'Country observation', 'peptide-core' ),
			'sourceReported' => __( 'Source reported', 'peptide-core' ),
			'entryHost' => __( 'Entry host', 'peptide-core' ),
			'notObservedKey' => __( 'Key absent', 'peptide-core' ),
			'observedEmpty' => __( 'Observed empty', 'peptide-core' ),
			'referrerHost' => __( 'Referrer host', 'peptide-core' ),
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
				'module_click' => '模块点击', 'product_click' => '商品点击',
				'product_impression'      => __( 'Product exposure observed', 'peptide-core' ),
				'page_view'               => __( 'Opened page', 'peptide-core' ),
				'product_view'            => __( 'Viewed product', 'peptide-core' ),
				'cart_view'               => __( 'Viewed cart', 'peptide-core' ),
				'checkout_view'           => __( 'Opened checkout', 'peptide-core' ),
				'visibility_change'       => __( 'Visibility changed', 'peptide-core' ),
				'heartbeat'               => __( 'Heartbeat', 'peptide-core' ),
				'page_exit'               => __( 'Left page', 'peptide-core' ),
				'variant_select'          => __( 'Chose option', 'peptide-core' ),
				'add_to_cart_attempt'     => __( 'Clicked add to cart', 'peptide-core' ),
				'checkout_submit_attempt' => __( 'Submit attempted in browser', 'peptide-core' ),
				'checkout_address_completed' => __( 'Address ready in browser', 'peptide-core' ),
				'checkout_shipping_selected' => __( 'Shipping selected', 'peptide-core' ),
				'checkout_payment_selected' => __( 'Payment method selected', 'peptide-core' ),
				'checkout_step_applicability' => __( 'Checkout step applicability', 'peptide-core' ),
				'payment_attempt' => __( 'Payment processing started', 'peptide-core' ),
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
			'observedProduct' => __( 'Observed product ID', 'peptide-core' ),
			'observedVariation' => __( 'Observed variation ID', 'peptide-core' ),
			'visibleFraction' => __( 'Visible fraction', 'peptide-core' ),
			'visibleDuration' => __( 'Visible duration (ms)', 'peptide-core' ),
			'pageEntity' => __( 'Page entity ID', 'peptide-core' ),
			'templateId' => __( 'Template ID', 'peptide-core' ),
			'moduleId' => __( 'Module ID', 'peptide-core' ),
		);
	}

	// ---------- 测试版数据 ----------

	// 访客追踪的开关、保留天数、GPC 由总后台下发（Hq_Connector::config_route，2026-10-27 前后台隔开）。

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
			<?php if ( 'live' === $view ) : ?>
				<?php $s = Tracker::settings(); ?>
				<div class="pc-trk-controls">
					<p class="description"><?php echo esc_html( ( $enabled ? sprintf( '访客记录已开启，保留 %d 天。', (int) $s['retention_days'] ) : '访客记录已关闭，不会记录新数据。' ) . '员工账号和打开过退出链接的浏览器不记录。' ); ?></p>
					<?php $test_left = current_user_can( 'manage_options' ) ? Schema::test_visitors() : 0; ?>
					<?php if ( $test_left ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-trk-purge" data-pc-confirm-json="<?php echo esc_attr( wp_json_encode( __( 'Delete the data from the test version? This cannot be undone.', 'peptide-core' ) ) ); ?>">
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
			<div id="pc-trk-insights"></div>
		</div>
		<?php
	}
}
