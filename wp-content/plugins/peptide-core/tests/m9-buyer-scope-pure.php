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

	// 七个菜单，顺序固定。2026-11-12 主线把“广告设置”和“素材库”合成“广告数据追踪”（键 adtrack）；
	// “营销活动”（只读，平台所有者 2026-11-13 定）排在数据后面，它显示不显示另看 F2 的只读页面在不在。
	$menu = \PeptideCore\Staff_Buyer::menu_items();
	check( 'exactly seven menus in order', array( 'home', 'orders', 'recovery', 'data', 'campaigns', 'adtrack', 'store' ) === array_keys( $menu ) );
	check( 'menu labels', array( '首页', '订单', '弃单', '数据', '营销活动', '广告数据追踪', '店铺信息' ) === array_column( $menu, 'label' ) );
	check( 'campaigns menu opens the promotions page (F2 shows its read-only list there)', 'admin.php?page=pc-promotions' === $menu['campaigns']['slug'] );
	check( 'ad settings and creative pages belong to adtrack', 'adtrack' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-buyer-ads' ) && 'adtrack' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-creatives' ) && 'adtrack' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-creatives-spend' ) && 'adtrack' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-creatives-board' ) );
	check( 'adtrack submenu: ad settings then creative library', array( 'admin.php?page=pc-buyer-ads', 'admin.php?page=pc-creatives' ) === array_column( \PeptideCore\Staff_Buyer::adtrack_submenu(), 2 ) );
	check( 'look-alike creatives slug is not a creatives page', '' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-creativesx' ) );
	check( 'the old menu key is gone', ! isset( $menu['ads'] ) );
	check( 'orders menu opens paid orders', 'admin.php?page=wc-orders&status=wc-processing' === $menu['orders']['slug'] );
	check( 'data sub-page belongs to data', 'data' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-data-landing' ) );
	check( 'visitors belong to data', 'data' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-visitors-archive' ) );
	check( 'wizard highlights home', 'home' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-onboarding' ) );
	check( 'look-alike slug is not a data page', '' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-datax' ) );
	check( 'hidden page has no menu', '' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-apps' ) );
	foreach ( \PeptideCore\Staff_Buyer::CAMPAIGN_PAGES as $campaign_page ) {
		check( "$campaign_page belongs to the campaigns menu", 'campaigns' === \PeptideCore\Staff_Buyer::menu_for_page( $campaign_page ) );
	}
	check( 'the three campaign pages are exactly these', array( 'pc-promotions', 'pc-coupons', 'pc-shipping-discounts' ) === \PeptideCore\Staff_Buyer::CAMPAIGN_PAGES );
	// 地址只是以 pc-promotions- 开头的其他营销页面（赠品 / 捆绑、活动积木、订阅者）不算“营销活动”。
	foreach ( array( 'pc-promotions-product-offers', 'pc-promotions-campaigns', 'pc-promotions-subscribers', 'pc-coupons-import', 'pc-shipping-discounts-x' ) as $look_alike ) {
		check( "$look_alike is not a campaigns page", '' === \PeptideCore\Staff_Buyer::menu_for_page( $look_alike ) );
	}

	// 前台浏览不受影响，也不给能力。
	check( 'storefront allowed without caps', $is( array( 'doing' => 'front' ), 'allow' ) );

	// 投手自己的页面。
	foreach ( array( 'pc-buyer-home', 'pc-buyer-ads', 'pc-store-info', 'pc-onboarding' ) as $own ) {
		check( "own page $own", $is( $page( $own ), 'allow' ) );
		check( "own page $own rejects POST", $is( $page( $own, array( 'method' => 'POST' ) ), 'deny' ) );
	}

	// 素材的三个页面：只读打开，不给店铺能力；写操作走 admin-post 的三个动作。
	foreach ( array( 'pc-creatives', 'pc-creatives-spend', 'pc-creatives-board' ) as $own ) {
		check( "creatives page $own", $is( $page( $own ), 'allow' ) );
		check( "creatives page $own rejects POST", $is( $page( $own, array( 'method' => 'POST' ) ), 'deny' ) );
	}
	foreach ( array( 'pc_creative_save', 'pc_creative_spend_import', 'pc_creative_export' ) as $action ) {
		check( "creatives action $action allowed", $is( $post( $action ), 'allow' ) );
		check( "creatives action $action blocked before password change", ! $is( $post( $action ), 'allow', '', '', true ) );
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
	// 订单详情页（pc-order）：订单列表里的链接和原生编辑页都转到这里。之前它不在投手的名单里，点开任何订单都是 403。
	check( 'order detail page opens read-only with the orders caps', $is( $page( 'pc-order' ), 'allow', 'orders' ) && $is( $page( 'pc-order', array( 'method' => 'HEAD' ) ), 'allow', 'orders' ) );
	check( 'order detail page rejects POST', $is( $page( 'pc-order', array( 'method' => 'POST' ) ), 'deny' ) );
	check( 'order detail writes (void / seller note / tags) stay denied', $is( $post( 'pc_order_detail' ), 'deny' ) && $is( array_merge( $post( 'pc_order_detail' ), array( 'method' => 'GET' ) ), 'deny' ) );
	check( 'pages the detail page links to for writing stay denied', $is( $page( 'pc-manual-order' ), 'deny' ) && $is( $page( 'pc-mail', array( 'action' => 'compose' ) ), 'deny' ) );
	check( 'order detail page keeps the orders menu highlighted', 'orders' === \PeptideCore\Staff_Buyer::menu_for_page( 'pc-order' ) );
	check( 'a look-alike slug is not the order detail page', $is( $page( 'pc-orderx' ), 'deny' ) );
	check( 'locked: order detail goes to the wizard without caps', $is( $page( 'pc-order' ), 'redirect', '', 'pc-onboarding', true ) );
	// 以下是模块分支比热修复多出来的：带 pc_native 的原生订单页（退款、发货、改地址）对投手也拒绝；写邮件的提交。
	check( 'manual email submit from an order denied', $is( $post( 'pc_mail_send' ), 'deny' ) );
	check( 'native order page (refund / ship / edit address) denied', $is( $page( 'wc-orders', array( 'action' => 'edit', 'native' => '1' ) ), 'deny' ) );
	check( 'order list ignores a stray pc_native', $is( $page( 'wc-orders', array( 'native' => '1' ) ), 'allow', 'orders' ) );
	// 导出订单（只读下载）。
	check( 'order export allowed', $is( $post( 'pc_buyer_orders_export' ), 'allow' ) );
	check( 'order export waits for the password change', $is( $post( 'pc_buyer_orders_export' ), 'deny', '', '', true ) );
	// WordPress 的命令面板会列出全部后台菜单的名字和地址：投手不加载它。
	$buyer_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-staff-buyer.php' );
	check( 'command palette is not loaded for buyers', false !== strpos( $buyer_src, "remove_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' )" ) && false !== strpos( $buyer_src, "array( self::class, 'no_command_palette' ), 0 )" ) );
	check( 'command palette button is removed from the toolbar', false !== strpos( $buyer_src, "\$nodes[] = 'command-palette';" ) );
	check( 'both only when the store switches it on (pc_buyer_no_palette, off by default = as online)', false !== strpos( $buyer_src, "if ( self::current() && self::hide_palette() ) {" ) && false !== strpos( $buyer_src, "if ( self::hide_palette() ) {\n\t\t\t\$nodes[] = 'command-palette';" ) );
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
	// 营销活动（F2 的只读“当前在跑的活动”）。$r['campaigns'] 由 request() 填：F2 的只读页面在这份代码里、并且 F2 认为这个账号不能改。
	$ro = array( 'campaigns' => '1' );
	foreach ( \PeptideCore\Staff_Buyer::CAMPAIGN_PAGES as $campaign_page ) {
		check( "$campaign_page: read-only list opens, with no store caps handed out", $is( $page( $campaign_page, $ro ), 'allow' ) );
		check( "$campaign_page: HEAD is also read-only", $is( $page( $campaign_page, $ro + array( 'method' => 'HEAD' ) ), 'allow' ) );
		check( "$campaign_page: new / edit form address denied", $is( $page( $campaign_page, $ro + array( 'edits' => '1' ) ), 'deny' ) );
		check( "$campaign_page: bulk or toggle action in the address denied", $is( $page( $campaign_page, $ro + array( 'action' => 'delete' ) ), 'deny' ) && $is( $page( $campaign_page, $ro + array( 'action' => '-1', 'action2' => 'disable' ) ), 'deny' ) );
		check( "$campaign_page: action=new and action=edit in the address denied", $is( $page( $campaign_page, $ro + array( 'action' => 'new' ) ), 'deny' ) && $is( $page( $campaign_page, $ro + array( 'action' => 'edit' ) ), 'deny' ) );
		check( "$campaign_page: list filter submit without an action still opens", $is( $page( $campaign_page, $ro + array( 'action' => '-1', 'action2' => '-1' ) ), 'allow' ) );
		check( "$campaign_page: POST denied", $is( $page( $campaign_page, $ro + array( 'method' => 'POST' ) ), 'deny' ) );
		check( "$campaign_page: denied while the read-only page is not in this code (or F2 says the account can edit)", $is( $page( $campaign_page ), 'deny' ) && $is( $page( $campaign_page, array( 'campaigns' => '' ) ), 'deny' ) );
		check( "$campaign_page: waits for the password change", $is( $page( $campaign_page, $ro ), 'redirect', '', 'pc-onboarding', true ) );
	}
	check( 'F2 read-only code is not loaded in this test, so nothing is opened by default', ! \PeptideCore\Staff_Buyer::campaigns_ready() && ! \PeptideCore\Staff_Buyer::campaigns_read_only() );
	// 营销里其余的页面和应用中心里的设置页（离开挽回、转盘、加购推荐、已付款弹窗、物流球等）：有没有只读列表都不开放。
	foreach ( array( 'pc-first-order-offer', 'pc-exit-intent', 'pc-marketing', 'pc-promotions-product-offers', 'pc-promotions-campaigns', 'pc-promotions-subscribers', 'pc-apps', 'pc-paid-popup', 'pc-tracking-ball', 'pc-cart-links', 'pc-referrals', 'pc-accounts', 'pc-checkout-dropoff', 'pc-collections', 'pc-product-reviews', 'pc-product-reports', 'pc-settings' ) as $closed ) {
		check( "still closed: $closed", $is( $page( $closed ), 'deny' ) && $is( $page( $closed, $ro ), 'deny' ) );
	}
	// 营销页面的保存、启停、批量、设置：都走 admin-post.php，不在投手的名单里（F2 那边也各自返回 403）。
	foreach ( array( 'pc_promo_save', 'pc_promo_toggle', 'pc_promo_bulk', 'pc_promo_settings', 'pc_coupon_save', 'pc_coupon_toggle', 'pc_coupon_bulk', 'pc_coupon_settings', 'pc_shipdisc_save', 'pc_shipdisc_toggle', 'pc_shipdisc_bulk' ) as $campaign_write ) {
		check( "campaign write $campaign_write denied (POST and GET, read-only list or not)", $is( $post( $campaign_write ), 'deny' ) && $is( $post( $campaign_write ) + $ro, 'deny' ) && $is( array_merge( $post( $campaign_write ), array( 'method' => 'GET' ) ) + $ro, 'deny' ) );
	}
	foreach ( array( 'pc-shipping', 'pc-tax', 'pc-system', 'pc-staff', 'pc-apps', 'pc-mail', 'pc-store-design', 'pc-design-editor', 'pc-customers', 'pc-media', 'pc-activity', 'pc-site-tools', 'pc-manual-order', 'pc-ship-preview', 'pc-datax', 'wc-settings', 'wc-admin', 'wc-status', 'cbs-dashboard' ) as $hidden ) {
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

	// 主线 2026-11-12 在总后台加的“物流填单”（peptide-hq 的 pchq-logistics）：它不在店铺后台，
	// 即使同一个站点上两个插件都开着，投手也打不开页面、下载不了表、动不了腾讯文档的设置。
	foreach ( array( 'pchq', 'pchq-logistics' ) as $hq_page ) {
		check( "HQ page $hq_page denied", $is( $page( $hq_page ), 'deny' ) && $is( $page( $hq_page, array( 'method' => 'POST' ) ), 'deny' ) );
	}
	foreach ( array( 'pchq_logistics_xlsx', 'pchq_logistics_save', 'pchq_logistics_track', 'pchq_tdocs_save', 'pchq_tdocs_auth', 'pchq_tdocs_callback', 'pchq_tdocs_sync' ) as $hq_action ) {
		check( "HQ logistics action $hq_action denied (POST and GET)", $is( $post( $hq_action ), 'deny' ) && $is( array_merge( $post( $hq_action ), array( 'method' => 'GET' ) ), 'deny' ) );
	}

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
