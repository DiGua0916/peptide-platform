<?php
/**
 * M9 投手版后台：请求白名单和按请求给能力的规则（纯逻辑，不加载 WordPress）。
 *
 * 运行：php -n wp-content/plugins/peptide-core/tests/m9-buyer-scope-pure.php
 * 覆盖 Staff_Buyer::classify()、rest_scope()、menu_items()、menu_for_page() 和 Capabilities::BUYER_SCOPES。
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}
	require dirname( __DIR__ ) . '/includes/class-capabilities.php';
	require dirname( __DIR__ ) . '/includes/class-staff-buyer.php';

	$page = static fn( string $page, array $more = array() ): array => array_merge( array( 'doing' => 'page', 'file' => 'admin.php', 'method' => 'GET', 'page' => $page, 'action' => '', 'action2' => '' ), $more );
	$file = static fn( string $file, string $method = 'GET' ): array => array( 'doing' => 'page', 'file' => $file, 'method' => $method, 'page' => '', 'action' => '', 'action2' => '' );
	$ajax = static fn( string $action, bool $nopriv = false ): array => array( 'doing' => 'ajax', 'file' => 'admin-ajax.php', 'method' => 'POST', 'page' => '', 'action' => $action, 'action2' => '', 'nopriv' => $nopriv );
	$post = static fn( string $action, bool $nopriv = false ): array => array( 'doing' => 'post', 'file' => 'admin-post.php', 'method' => 'POST', 'page' => '', 'action' => $action, 'action2' => '', 'nopriv' => $nopriv );
	$is   = static function ( array $r, string $verdict, string $scope = '', string $to = '', bool $must_change = false ): bool {
		$v = \PeptideCore\Staff_Buyer::classify( $r, $must_change );
		return $v === array( 'verdict' => $verdict, 'scope' => $scope, 'to' => $to );
	};

	// 六个菜单，顺序固定。
	$menu = \PeptideCore\Staff_Buyer::menu_items();
	check( 'exactly six menus in order', array( 'home', 'orders', 'recovery', 'data', 'ads', 'store' ) === array_keys( $menu ) );
	check( 'menu labels', array( '首页', '订单', '弃单', '数据', '广告设置', '店铺信息' ) === array_column( $menu, 'label' ) );
	check( 'orders menu opens paid orders', 'admin.php?page=wc-orders&status=wc-processing' === $menu['orders']['slug'] );
	check( 'data sub-page belongs to data', 'data' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-data-landing' ) );
	check( 'visitors belong to data', 'data' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-visitors-archive' ) );
	check( 'wizard highlights home', 'home' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-onboarding' ) );
	check( 'look-alike slug is not a data page', '' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-datax' ) );
	check( 'hidden page has no menu', '' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-promotions' ) );

	// 前台浏览不受影响，也不给能力。
	check( 'storefront allowed without caps', $is( array( 'doing' => 'front' ), 'allow' ) );

	// 投手自己的页面。
	foreach ( array( 'pc-buyer-home', 'pc-buyer-ads', 'pc-store-info', 'pc-onboarding' ) as $own ) {
		check( "own page $own", $is( $page( $own ), 'allow' ) );
		check( "own page $own rejects POST", $is( $page( $own, array( 'method' => 'POST' ) ), 'deny' ) );
	}

	// 订单只读。
	check( 'orders list', $is( $page( 'wc-orders' ), 'allow', 'orders' ) );
	check( 'orders list filter submit', $is( $page( 'wc-orders', array( 'action' => '-1', 'action2' => '-1' ) ), 'allow', 'orders' ) );
	check( 'open one order', $is( $page( 'wc-orders', array( 'action' => 'edit' ) ), 'allow', 'orders' ) );
	check( 'HEAD counts as read', $is( $page( 'wc-orders', array( 'method' => 'HEAD' ) ), 'allow', 'orders' ) );
	check( 'save order (POST) denied', $is( $page( 'wc-orders', array( 'action' => 'edit', 'method' => 'POST' ) ), 'deny' ) );
	check( 'new order denied', $is( $page( 'wc-orders', array( 'action' => 'new' ) ), 'deny' ) );
	check( 'trash order denied', $is( $page( 'wc-orders', array( 'action' => 'trash' ) ), 'deny' ) );
	check( 'bulk action denied', $is( $page( 'wc-orders', array( 'action' => 'mark_completed' ) ), 'deny' ) );
	check( 'bottom bulk action denied', $is( $page( 'wc-orders', array( 'action' => '-1', 'action2' => 'trash' ) ), 'deny' ) );
	// “清空回收站”（delete_all）和“接管编辑”（claim-lock）不带 action，request() 把它们记在 writes 里。
	check( 'empty trash denied', $is( $page( 'wc-orders', array( 'writes' => '1' ) ), 'deny' ) );
	check( 'empty trash with a filter submit denied', $is( $page( 'wc-orders', array( 'action' => '-1', 'action2' => '-1', 'writes' => '1' ) ), 'deny' ) );
	check( 'claiming an edit lock denied', $is( $page( 'wc-orders', array( 'action' => 'edit', 'writes' => '1' ) ), 'deny' ) );
	// 主线 2026-11-11：打开订单转到订单详情页（pc-order）；原生订单页带 pc_native 才打开，只用来退款、发货、改地址。
	check( 'order detail page is readable', $is( $page( 'pc-order' ), 'allow', 'orders' ) );
	check( 'order detail page rejects POST', $is( $page( 'pc-order', array( 'method' => 'POST' ) ), 'deny' ) );
	check( 'order detail highlights the orders menu', 'orders' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-order' ) );
	check( 'native order page (refund / ship / edit address) denied', $is( $page( 'wc-orders', array( 'action' => 'edit', 'native' => '1' ) ), 'deny' ) );
	check( 'order list ignores a stray pc_native', $is( $page( 'wc-orders', array( 'native' => '1' ) ), 'allow', 'orders' ) );
	check( 'detail page writes (void / seller note / tags) denied', $is( $post( 'pc_order_detail' ), 'deny' ) );
	check( 'manual email from an order denied', $is( $page( 'pc-mail', array( 'action' => 'compose' ) ), 'deny' ) && $is( $post( 'pc_mail_send' ), 'deny' ) );
	check( 'copying an order into a manual order denied', $is( $page( 'pc-manual-order' ), 'deny' ) );
	// 导出订单（只读下载）。
	check( 'order export allowed', $is( $post( 'pc_buyer_orders_export' ), 'allow' ) );
	check( 'order export waits for the password change', $is( $post( 'pc_buyer_orders_export' ), 'deny', '', '', true ) );
	// WordPress 的命令面板会列出全部后台菜单的名字和地址：投手不加载它。
	$buyer_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-staff-buyer.php' );
	check( 'command palette is not loaded for buyers', false !== strpos( $buyer_src, "remove_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' )" ) && false !== strpos( $buyer_src, "array( self::class, 'no_command_palette' ), 0 )" ) );
	check( 'command palette button is removed from the toolbar', false !== strpos( $buyer_src, "'comments', 'command-palette' ) as " ) );
	check( 'export action name matches the constant', 'pc_buyer_orders_export' === \PeptideCore\Staff_Buyer::ACTION_EXPORT && 'pc-order' === \PeptideCore\Staff_Buyer::PAGE_ORDER );

	// 弃单、数据、实时访客。
	check( 'abandoned checkouts', $is( $page( 'pc-recovery' ), 'allow', 'data' ) );
	check( 'data landing page', $is( $page( 'pc-data' ), 'allow', 'data' ) );
	check( 'data sub-page', $is( $page( 'pc-data-page-conversion' ), 'allow', 'data' ) );
	check( 'live visitors', $is( $page( 'pc-visitors' ), 'allow', 'data' ) );
	check( 'data POST denied', $is( $page( 'pc-data', array( 'method' => 'POST' ) ), 'deny' ) );

	// 转向。
	check( 'dashboard goes home', $is( $file( 'index.php' ), 'redirect', '', 'pc-buyer-home' ) );
	check( 'overview goes home', $is( $page( 'pc-overview' ), 'redirect', '', 'pc-buyer-home' ) );
	check( 'profile goes to store info', $is( $file( 'profile.php' ), 'redirect', '', 'pc-store-info' ) );

	// 其余菜单：直接打开地址也拒绝。
	foreach ( array( 'pc-promotions', 'pc-coupons', 'pc-shipping', 'pc-tax', 'pc-system', 'pc-staff', 'pc-apps', 'pc-mail', 'pc-store-design', 'pc-design-editor', 'pc-customers', 'pc-media', 'pc-activity', 'pc-site-tools', 'pc-manual-order', 'pc-ship-preview', 'pc-datax', 'wc-settings', 'wc-admin', 'wc-status', 'cbs-dashboard' ) as $hidden ) {
		check( "hidden page $hidden", $is( $page( $hidden ), 'deny' ) );
	}
	foreach ( array( 'edit.php', 'post.php', 'post-new.php', 'upload.php', 'users.php', 'user-new.php', 'plugins.php', 'themes.php', 'options-general.php', 'tools.php', 'edit-tags.php', 'customize.php', 'site-health.php', 'export.php', 'about.php' ) as $core ) {
		check( "core screen $core", $is( $file( $core ), 'deny' ) );
	}
	check( 'admin.php without page', $is( $file( 'admin.php' ), 'deny' ) );

	// admin-ajax：白名单 + 本来就公开的动作。
	check( 'heartbeat', $is( $ajax( 'heartbeat' ), 'allow' ) );
	check( 'REST nonce refresh', $is( $ajax( 'rest-nonce' ), 'allow' ) );
	check( 'role is not offered in role dropdowns', array( 'pc_staff' => array() ) === \PeptideCore\Staff_Buyer::hide_role( array( 'pc_staff' => array(), 'pc_media_buyer' => array() ) ) );
	check( 'order preview', $is( $ajax( 'woocommerce_get_order_details' ), 'allow', 'orders' ) );
	foreach ( array( 'woocommerce_refund_line_items', 'woocommerce_add_order_item', 'woocommerce_save_order_items', 'woocommerce_mark_order_status', 'woocommerce_add_order_note', 'woocommerce_delete_refund', 'pc_mail_body', 'pc_collections_request', 'pc_offer_products', '' ) as $write ) {
		check( "ajax $write denied", $is( $ajax( $write ), 'deny' ) );
	}
	check( 'public ajax action allowed without caps', $is( $ajax( 'pc_recent_history', true ), 'allow' ) );

	// admin-post：只有投手版页面自己的表单。
	foreach ( array( 'pc_buyer_password', 'pc_buyer_store_save', 'pc_buyer_ads_save' ) as $own ) {
		check( "post $own", $is( $post( $own ), 'allow' ) );
	}
	foreach ( array( 'pc_pixels_save', 'pc_promo_save', 'pc_coupon_save', 'pc_recovery_void', 'pc_recovery_scan', 'pc_shipping_save', 'pc_tax_save', 'pc_contact_save', 'pc_staff_save', 'pc_staff_buyer', 'pc_mail_send', 'pc_tracking_purge', '' ) as $other ) {
		check( "post $other denied", $is( $post( $other ), 'deny' ) );
	}
	check( 'public form post allowed', $is( $post( 'pc_subscribe', true ), 'allow' ) );

	// 还没改密码：只能打开向导、提交改密码。
	check( 'locked: wizard', $is( $page( 'pc-onboarding' ), 'allow', '', '', true ) );
	check( 'locked: home goes to wizard', $is( $page( 'pc-buyer-home' ), 'redirect', '', 'pc-onboarding', true ) );
	check( 'locked: orders go to wizard without caps', $is( $page( 'wc-orders' ), 'redirect', '', 'pc-onboarding', true ) );
	check( 'locked: dashboard goes to wizard', $is( $file( 'index.php' ), 'redirect', '', 'pc-onboarding', true ) );
	check( 'locked: password form', $is( $post( 'pc_buyer_password' ), 'allow', '', '', true ) );
	check( 'locked: other forms denied', $is( $post( 'pc_buyer_store_save' ), 'deny', '', '', true ) );
	check( 'locked: ajax denied', $is( $ajax( 'woocommerce_get_order_details' ), 'deny', '', '', true ) );

	// REST：只有名单里的接口给能力。
	$rest = static fn( string $route, string $method = 'GET' ): string => \PeptideCore\Staff_Buyer::rest_scope( $route, $method );
	check( 'data report read', 'data' === $rest( '/peptide/v1/data/landing' ) );
	check( 'data report write denied', '' === $rest( '/peptide/v1/data/visit-classifications', 'POST' ) );
	check( 'overview read', 'data' === $rest( '/peptide/v1/overview' ) && 'data' === $rest( '/peptide/v1/overview/extras' ) );
	check( 'overview look-alike', '' === $rest( '/peptide/v1/overviewx' ) );
	check( 'live visitors read', 'data' === $rest( '/peptide/v1/track/admin/live' ) );
	check( 'visitor AI analysis', 'data' === $rest( '/peptide/v1/track/admin/ai/analyse', 'POST' ) );
	check( 'pixel test', 'data' === $rest( '/peptide/v1/admin/pixel-test', 'POST' ) );
	check( 'ad link', 'data' === $rest( '/peptide/v1/admin/ad-link', 'POST' ) );
	check( 'mark recovery contacted', 'data' === $rest( '/peptide/v1/admin/recovery/15/contacted', 'POST' ) );
	check( 'route without leading slash', 'data' === $rest( 'peptide/v1/data/sources' ) );
	foreach ( array( '/wc/v3/orders', '/wc/v3/orders/12/refunds', '/wc/v3/payment_gateways', '/wc/v3/settings', '/wc/v3/products', '/wc-admin/options', '/wc-analytics/reports/orders', '/wp/v2/users', '/wp/v2/settings', '/peptide/v1/hq/status', '/peptide/v1/layouts/3', '/peptide/v1/campaign-countdown', '/peptide/v1/activity-listener' ) as $route ) {
		check( "rest $route read gets no caps", '' === $rest( $route ) );
		check( "rest $route write gets no caps", '' === $rest( $route, 'POST' ) );
	}

	// 能力组：订单组不能删除、新建、退款；数据组没有任何订单或商品能力。
	$scopes = \PeptideCore\Capabilities::BUYER_SCOPES;
	check( 'two scopes', array( 'orders', 'data' ) === array_keys( $scopes ) );
	foreach ( array( 'delete_shop_orders', 'delete_shop_order', 'publish_shop_orders', 'edit_products', 'edit_shop_coupons', 'manage_options', 'edit_posts', 'upload_files', 'edit_theme_options', 'create_users', 'pc_publish_layouts', 'pc_manage_settings' ) as $cap ) {
		check( "no $cap in any scope", ! in_array( $cap, array_merge( $scopes['orders'], $scopes['data'] ), true ) );
	}
	check( 'data scope is exactly reports', array( 'manage_woocommerce', 'view_woocommerce_reports' ) === $scopes['data'] );

	echo "PASS {$checks} media-buyer scope checks\n";
}
