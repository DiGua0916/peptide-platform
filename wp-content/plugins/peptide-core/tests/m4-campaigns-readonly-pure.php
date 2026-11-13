<?php
/**
 * HQ-07 店铺端：投手（pc_media_buyer）看不到创建优惠的入口，只读显示“当前在跑的活动”。
 *
 * 验证：投手打开新建 / 编辑地址得到 403；列表页是只读的“当前在跑的活动”，没有新建、编辑、启停、删除；
 * 直接提交保存 / 启停 / 批量 / 页面设置请求也是 403；赠品 / 捆绑、首单优惠、离开挽回同样改不了；
 * 投手没有任何优惠码相关的能力（WooCommerce 原生页面和接口靠这些能力把关）。管理员和原有的营销员工照旧能改。
 * 2026-11-13 平台所有者定：投手可以看只读列表——只读页用大白话写清每个活动的条件和优惠，不显示内部编号。
 *
 * 运行：php -n tests/m4-campaigns-readonly-pure.php   不连 WordPress、不连数据库。
 *
 * @package PeptideCore
 */

namespace PeptideCore {
	/** M3 的系列同步（替身）：只回答“营销活动是否已由系列接管”。 */
	final class Series_Sync {
		public static function manages( string $section ): bool {
			return 'campaign' === $section && ! empty( $GLOBALS['m4_series_managed'] );
		}
	}
}

namespace PeptideCore\Admin {
	final class Apps_Page {
		public static function require_allowed( $app ) {}
		public static function url( $args = array() ) { return 'admin.php?page=pc-apps'; }
	}
}

namespace {
	require __DIR__ . '/fixtures/m4-harness.php';
	require __DIR__ . '/fixtures/m4-cases.php';

	use PeptideCore\Admin\Coupons_Page;
	use PeptideCore\Admin\First_Order_Offer_Page;
	use PeptideCore\Admin\Marketing;
	use PeptideCore\Admin\Promotions_Page;
	use PeptideCore\Admin\Shipping_Discounts_Page;
	use PeptideCore\Pricing\Campaigns;
	use PeptideCore\Product_Offers;

	final class M4_Died extends RuntimeException {}
	if ( ! class_exists( 'WP_Post' ) ) {
		#[AllowDynamicProperties]
		final class WP_Post {
			public int $ID = 0;
			public string $post_type = '';
		}
	}
	$GLOBALS['caps'] = array();
	function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['caps'], true ); }
	/** 和 WordPress 一样：第二个参数是整数时就是 HTTP 状态码。 */
	function wp_die( $message = '', $title = '', $args = array() ) { throw new M4_Died( (string) $message, is_int( $title ) ? $title : (int) ( is_array( $args ) ? ( $args['response'] ?? 500 ) : $args ) ); }
	function check_admin_referer( $action ) { $GLOBALS['nonce_checked'][] = $action; return true; }
	function wp_timezone_string() { return 'UTC'; }
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
	function get_term( $id, $tax = '' ) { return null; }
	function wp_unslash( $v ) { return $v; }
	function sanitize_hex_color( $v ) { return preg_match( '/^#[0-9a-f]{6}$/i', (string) $v ) ? $v : ''; }
	function sanitize_textarea_field( $v ) { return trim( (string) $v ); }
	function wp_attachment_is_image( $id ) { return false; }
	function esc_url_raw( $v, $protocols = null ) { return (string) $v; }
	function get_woocommerce_currency_symbol() { return '$'; }
	function home_url( $path = '' ) { return 'https://shop.example' . $path; }
	function admin_url( $path = '' ) { return 'https://shop.example/wp-admin/' . $path; }
	function add_query_arg( $key, $value = null, $url = '' ) {
		if ( is_array( $key ) ) {
			return ( (string) $value ) . ( $key ? ( false === strpos( (string) $value, '?' ) ? '?' : '&' ) . http_build_query( $key ) : '' );
		}
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value;
	}

	$checks = 0;
	$failed = array();
	$check  = static function ( string $label, bool $ok ) use ( &$checks, &$failed ): void {
		++$checks;
		if ( ! $ok ) {
			$failed[] = $label;
			echo 'FAIL  ' . $label . "\n";
		}
	};
	$status = static function ( callable $fn ): int {
		try {
			ob_start();
			$fn();
			ob_end_clean();
			return 200;
		} catch ( M4_Died $e ) {
			ob_end_clean();
			return $e->getCode();
		}
	};
	$html = static function ( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	};
	/** 一家有各种进行中活动的店，登录的人按 $role / $caps。 */
	$login = static function ( string $role, array $caps ): void {
		m4_reset();
		catalog();
		m4_user( 3, 'staff@example.com' );
		$GLOBALS['m4']['users'][3]['roles'] = array( $role );
		$GLOBALS['caps']                    = $caps;
		$GLOBALS['nonce_checked']           = array();
		$_GET                               = array();
		$_POST                              = array();
		$_SERVER['REQUEST_METHOD']          = 'GET';
		m4_promotion( 500, 'Black Friday', promo( 'spend_amount', array( array( 100, 10 ), array( 200, 25 ) ) ) );
		m4_promotion( 501, 'Not started', promo( 'spend_amount', array( array( 50, 5 ) ), array( 'start' => time() + 86400 ) ) );
		update_post_meta_m4( 500, Campaigns::SERIES_META, 'promotion/black-friday' );
		welcome5();
		m4_coupon( 901, 'SAVE10', array( 'type' => 'percent', 'amount' => '10' ) );
		m4_coupon( 902, 'OLD', array( 'type' => 'percent', 'amount' => '50', 'expires' => time() - 60 ) );
		loyalty_coupon( 920, 7, '7.5' );
		m4_shipping_discount( array( 'id' => 1, 'name' => 'Half shipping', 'kind' => 'percent', 'value' => 50.0 ) );
		m4_shipping_discount( array( 'id' => 2, 'name' => 'Paused', 'kind' => 'percent', 'value' => 50.0, 'enabled' => false ) );
		m4_offer( 700, 'Free vial', array( 'kind' => 'gift', 'threshold' => 60.0, 'gift_product' => 105 ) );
		update_option( \PeptideCore\Exit_Intent::OPTION, array( 'enabled' => true, 'type' => 'wheel', 'title' => 'Spin to win', 'prizes' => array( array( 'label' => 'A', 'coupon' => 'save10', 'weight' => 1 ), array( 'label' => 'B', 'coupon' => '', 'weight' => 1 ) ) ) );
	};
	function update_post_meta_m4( int $id, string $key, $value ): void { $GLOBALS['m4']['posts'][ $id ]['meta'][ $key ] = $value; }
	function wp_strip_all_tags_m4( string $html ): string { return trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $html ) ) ); }

	// ---------- 投手：只读 ----------
	// M9 的投手账号平时只有 read：不靠任何营销能力也能看只读页（平台所有者 2026-11-13 定）。
	$login( 'pc_media_buyer', array() );
	$check( '投手（没有任何营销能力）：能看、不能改；注册菜单用 read；只读入口是满减活动那个页面', Marketing::is_buyer() && Marketing::can_view() && ! Marketing::can_manage() && 'read' === Marketing::menu_cap() && Marketing::VIEW_PAGE === Promotions_Page::SLUG && false !== strpos( Marketing::view_url(), 'page=pc-promotions' ) );
	$check( '投手不带任何能力打开页面：是只读的“当前在跑的活动”', false !== strpos( $html( array( Promotions_Page::class, 'render' ) ), '当前在跑的活动' ) );
	$GLOBALS['m4']['users'][3]['roles'] = array( 'pc_staff', 'pc_media_buyer' );
	$check( '销售账号加上投手角色（M9 的做法）：算投手', Marketing::is_buyer() && Marketing::is_buyer( 3 ) && ! Marketing::can_manage() );
	$GLOBALS['m4']['users'][3]['roles'] = array( 'administrator', 'pc_media_buyer' );
	$check( '管理员永远不算投手', ! Marketing::is_buyer() );
	$check( '没登录、不存在的账号不算投手', ! Marketing::is_buyer( 0 ) && ! Marketing::is_buyer( 999 ) );

	$login( 'pc_media_buyer', array( Marketing::VIEW_CAP ) );
	$check( '投手：能打开营销活动页面，但不能改', Marketing::can_view() && ! Marketing::can_manage() && Marketing::VIEW_CAP === Marketing::menu_cap() );
	foreach ( array( '满减活动' => Promotions_Page::class, '优惠码' => Coupons_Page::class, '运费折扣' => Shipping_Discounts_Page::class ) as $name => $page ) {
		$_GET = array( 'new' => '1' );
		$check( '投手打开“新建' . $name . '”的地址：403', 403 === $status( array( $page, 'render' ) ) );
		$_GET = array( 'edit' => '500' );
		$check( '投手打开“编辑' . $name . '”的地址：403', 403 === $status( array( $page, 'render' ) ) );
		$_GET = array();
		$out  = $html( array( $page, 'render' ) );
		$check( '投手的' . $name . '列表：只读的“当前在跑的活动”，没有新建 / 编辑 / 启停 / 删除入口', false !== strpos( $out, '当前在跑的活动' ) && false === strpos( $out, 'new=1' ) && false === strpos( $out, 'edit=' ) && false === strpos( $out, '<form' ) && false === strpos( $out, 'admin-post.php' ) && false === strpos( $out, '<input' ) );
	}
	foreach ( array( '满减活动' => Promotions_Page::class, '优惠码' => Coupons_Page::class, '运费折扣' => Shipping_Discounts_Page::class ) as $name => $page ) {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$check( '投手向“' . $name . '”页面提交（POST）：403', 403 === $status( array( $page, 'render' ) ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}
	// 全部 11 个提交入口：保存、启停、批量（启用 / 停用 / 删除）、页面设置。
	$entries = array(
		array( Promotions_Page::class, 'save' ), array( Promotions_Page::class, 'toggle' ), array( Promotions_Page::class, 'bulk' ), array( Promotions_Page::class, 'save_settings' ),
		array( Coupons_Page::class, 'save' ), array( Coupons_Page::class, 'toggle' ), array( Coupons_Page::class, 'bulk' ), array( Coupons_Page::class, 'save_settings' ),
		array( Shipping_Discounts_Page::class, 'save' ), array( Shipping_Discounts_Page::class, 'toggle' ), array( Shipping_Discounts_Page::class, 'bulk' ),
	);
	foreach ( $entries as [ $page, $action ] ) {
		$_POST = array( 'id' => '500', 'ids' => array( '500', '901', '1' ), 'bulk' => 'delete', 'name' => 'x' );
		$_GET  = array( 'id' => '500', 'on' => '0' );
		$check( '投手直接提交 ' . $page . '::' . $action . '：403', 403 === $status( array( $page, $action ) ) );
	}
	$_POST = array();
	$_GET  = array();
	$check( '投手被挡在校验 nonce 之前，没有任何写入', array() === $GLOBALS['nonce_checked'] && array() === $GLOBALS['wpdb']->writes );
	$check( '被拒绝之后数据没变：满减还在跑、优惠码还是 10%、运费折扣还在', 'publish' === get_post_status( 500 ) && 10.0 === (float) ( new WC_Coupon( 901 ) )->get_amount() && 2 === count( get_option( \PeptideCore\Shipping\Discounts::OPTION ) ) );
	$GLOBALS['caps'][] = 'edit_shop_coupons';
	$GLOBALS['caps'][] = 'manage_woocommerce';
	$check( '投手即使被误授了优惠券权限，仍然不能改', ! Marketing::can_manage() && 403 === $status( array( Promotions_Page::class, 'save' ) ) && 403 === $status( array( Coupons_Page::class, 'bulk' ) ) );
	$check( '投手不能配置首单优惠', 403 === $status( array( First_Order_Offer_Page::class, 'handle' ) ) );
	$check( '投手不能新建赠品 / 捆绑', is_wp_error( Product_Offers::store( 0, array( 'title' => 'x', 'kind' => 'gift' ) ) ) );
	$offer_post             = new WP_Post();
	$offer_post->ID         = 700;
	$offer_post->post_type  = Product_Offers::CPT;
	$other_post             = new WP_Post();
	$other_post->ID         = 105;
	$other_post->post_type  = 'product';
	$check( '投手删不掉赠品 / 捆绑（删除和移到回收站都拦）；别的文章类型不管', false === Product_Offers::guard_delete( null, $offer_post ) && null === Product_Offers::guard_delete( null, $other_post ) && true === Product_Offers::guard_delete( true, $offer_post ) );
	$exit_before = get_option( \PeptideCore\Exit_Intent::OPTION );
	$errors      = \PeptideCore\Exit_Intent::save( array( 'enabled' => false, 'type' => 'message', 'title' => 'Changed by buyer', 'button' => '', 'desktop_exit' => '1', 'pages' => array( 'home' ) ) );
	$check( '投手不能改离开挽回 / 转盘的设置，内容不变', 1 === count( $errors ) && $exit_before === get_option( \PeptideCore\Exit_Intent::OPTION ) );

	// 有只读视图的页面只有三个；离开挽回 / 首单优惠的设置页没有只读视图，投手打开直接 403。
	$check( '有只读视图的页面名单：满减活动、优惠码、运费折扣', array( 'pc-promotions', 'pc-coupons', 'pc-shipping-discounts' ) === Marketing::pages() && Marketing::VIEW_PAGE === Marketing::pages()[0] );
	foreach ( array( '离开挽回 / 转盘' => 'exit-intent', '首单优惠' => 'first-order-offer' ) as $name => $app ) {
		$_GET = array( 'page' => 'pc-apps', 'app' => $app );
		$check( '投手打开“' . $name . '”的设置页：403（没有只读视图）', 403 === $status( array( Marketing::class, 'deny_buyer_settings' ) ) );
	}
	$_GET = array( 'page' => 'pc-promotions' );
	$check( '投手打开只读入口：这一道不拦', 200 === $status( array( Marketing::class, 'deny_buyer_settings' ) ) );
	$_GET = array();

	// 投手没有任何优惠码相关的能力：WooCommerce 原生的优惠码页面、快速编辑、REST 接口都靠这些能力把关。
	$given = array( 'read' => true, 'manage_woocommerce' => true, 'edit_shop_coupons' => true, 'edit_shop_coupon' => true, 'publish_shop_coupons' => true, 'delete_shop_coupons' => true, 'edit_others_shop_coupons' => true, 'read_private_shop_coupons' => true, 'manage_shop_coupon_terms' => true, 'edit_shop_orders' => true );
	$buyer = get_userdata( 3 );
	$left  = Marketing::buyer_caps( $given, array( 'edit_shop_coupons' ), array(), $buyer );
	$check( '投手的能力里去掉全部优惠码相关的（共 7 项），其余不动', array( 'read', 'manage_woocommerce', 'edit_shop_orders' ) === array_keys( $left ) );
	$GLOBALS['m4']['users'][4] = array( 'user_email' => 'manager@example.com', 'meta' => array(), 'roles' => array( 'shop_manager' ) );
	$GLOBALS['m4']['users'][5] = array( 'user_email' => 'admin@example.com', 'meta' => array(), 'roles' => array( 'administrator', 'pc_media_buyer' ) );
	$check( '不是投手的人（店长、管理员）能力原样不动', $given === Marketing::buyer_caps( $given, array(), array(), get_userdata( 4 ) ) && $given === Marketing::buyer_caps( $given, array(), array(), get_userdata( 5 ) ) && $given === Marketing::buyer_caps( $given, array(), array(), null ) );

	// ---------- 当前在跑的活动 ----------
	$rows  = Campaigns::running();
	$kinds = array_count_values( array_column( $rows, 'kind' ) );
	$by    = array();
	foreach ( $rows as $row ) {
		$by[ $row['kind'] . ':' . $row['id'] ] = $row;
	}
	$check( '在跑的活动：满减 1（未开始的不算）、优惠码 1（过期的、积分个人券不算）、首单 1、运费折扣 1（停用的不算）、赠品 1、离开挽回 1', array( 'promotion' => 1, 'first_order' => 1, 'coupon' => 1, 'shipping_discount' => 1, 'gift' => 1, 'exit_intent' => 1 ) == $kinds ); // phpcs:ignore Universal.Operators.StrictComparisons
	$check( '系列统一下发的活动标成 series，本店自建的标成 store', 'series' === $by['promotion:500']['source'] && 'store' === $by['coupon:901']['source'] );
	$check( '规则是给人看的文字', false !== strpos( $by['promotion:500']['rule'], '$100.00' ) && false !== strpos( $by['shipping_discount:1']['rule'], '50' ) && false !== strpos( $by['exit_intent:0']['rule'], '转盘' ) );
	$_GET = array();
	$out  = $html( array( Promotions_Page::class, 'render' ) );
	$check( '只读页面里没有任何表单、按钮、链接和提交地址（纯文字）', false === stripos( $out, '<form' ) && false === stripos( $out, '<input' ) && false === stripos( $out, '<button' ) && false === stripos( $out, '<a ' ) && false === strpos( $out, 'admin-post.php' ) && false === strpos( $out, '_wpnonce' ) && false === strpos( $out, 'data-id' ) );
	$check( '只读页面用大白话标出条件和优惠', false !== strpos( $out, '条件（顾客要做到）' ) && false !== strpos( $out, '优惠（顾客能得到）' ) && false !== strpos( $out, '一单买满 $100' ) && false !== strpos( $out, '减 $10' ) && false !== strpos( $out, '一单买满 $200' ) && false !== strpos( $out, '减 $25' ) );
	$check( '优惠码写明怎么用和广告链接的写法', false !== strpos( $out, '输入优惠码 SAVE10' ) && false !== strpos( $out, '?pc_coupon=SAVE10' ) && false !== strpos( $out, '减 10%（相当于 9 折）' ) && false !== strpos( $out, '只限第一次下单的新顾客' ) );
	$check( '运费折扣、赠品、转盘也是大白话', false !== strpos( $out, '运费减 50%' ) && false !== strpos( $out, '一单买满 $60' ) && false !== strpos( $out, '免费送' ) && false !== strpos( $out, '抽奖转盘' ) && false !== strpos( $out, '不要承诺一定中奖' ) );
	$check( '只读页面不显示内部编号（活动的文章编号、运费折扣编号）', ! preg_match( '/(?<![\d$.,])(500|501|700|901|902|920)(?![\d.,])/', wp_strip_all_tags_m4( $out ) ) && false === strpos( $out, 'ID' ) && false === strpos( $out, '#' ) );
	$check( '投手看不到“来源”这一行（对写文案没有用）', false === strpos( $out, '来源' ) && false === strpos( $out, '系列统一下发' ) );
	$GLOBALS['m4']['users'][3]['roles'] = array( 'shop_manager' );
	$GLOBALS['caps']                    = array( Marketing::VIEW_CAP );
	$out                                = $html( array( Promotions_Page::class, 'render' ) );
	$check( '只读页面列出这些活动和来源', false !== strpos( $out, 'Black Friday' ) && false !== strpos( $out, 'WELCOME5' ) && false !== strpos( $out, 'Half shipping' ) && false !== strpos( $out, 'Free vial' ) && false !== strpos( $out, '系列统一下发' ) && false === strpos( $out, 'pcp-920' ) && false === strpos( $out, 'Not started' ) );

	// ---------- 管理员、原有的营销员工：照旧 ----------
	$login( 'administrator', array( 'edit_shop_coupons', 'manage_woocommerce' ) );
	$check( '管理员（含总后台一键登录）：能改', Marketing::can_manage() && Marketing::can_view() && ! Marketing::read_only_gate() && Marketing::CAP === Marketing::menu_cap() );
	$check( '管理员提交保存：通过权限检查，接着校验 nonce', 200 === $status( static fn() => Marketing::guard( 'pc_promo_save' ) ) && array( 'pc_promo_save' ) === $GLOBALS['nonce_checked'] );
	$login( 'shop_manager', array( 'edit_shop_coupons' ) );
	$check( '原有的营销员工（有优惠券权限、不是投手）：能改', Marketing::can_manage() );
	$_GET = array( 'page' => 'pc-apps', 'app' => 'exit-intent' );
	$check( '不是投手的人打开离开挽回的设置页：这一道不拦（照旧由应用中心自己的权限决定）', 200 === $status( array( Marketing::class, 'deny_buyer_settings' ) ) );
	$_GET = array();
	$login( 'customer', array() );
	$check( '没有任何营销权限的人：看不到页面', ! Marketing::can_view() && '' === $html( array( Promotions_Page::class, 'render' ) ) && 403 === $status( static fn() => Marketing::guard( 'pc_promo_save' ) ) );
	$login( 'shop_manager', array( Marketing::VIEW_CAP ) );
	add_filter( 'pc_marketing_can_manage', static fn() => true );
	$check( '过滤器 pc_marketing_can_manage 可以给不是投手的人放开', Marketing::can_manage() );
	$login( 'pc_media_buyer', array( Marketing::VIEW_CAP, 'edit_shop_coupons' ) );
	add_filter( 'pc_marketing_can_manage', static fn() => true );
	$check( '过滤器也不能把投手改成可以修改', ! Marketing::can_manage() && 403 === $status( array( Coupons_Page::class, 'save' ) ) );

	// ---------- 系列统一下发的活动：对所有人只读（管理员也不能改） ----------
	$login( 'administrator', array( 'edit_shop_coupons', 'manage_woocommerce', 'manage_options' ) );
	update_post_meta_m4( 500, \PeptideCore\Pricing\Series_Campaigns::META, 'promotion/black-friday' );
	update_post_meta_m4( 901, \PeptideCore\Pricing\Series_Campaigns::META, 'coupon/save10' );
	$all = get_option( \PeptideCore\Shipping\Discounts::OPTION );
	$all[0]['series_key'] = 'half';
	update_option( \PeptideCore\Shipping\Discounts::OPTION, $all );
	$check( '认得出哪些是系列下发的', Marketing::locked( 'promotion', 500 ) && ! Marketing::locked( 'promotion', 501 ) && Marketing::locked( 'coupon', 901 ) && ! Marketing::locked( 'coupon', 902 ) && Marketing::locked( 'ship_discount', 1 ) && ! Marketing::locked( 'ship_discount', 2 ) && '' !== Marketing::locked_badge( 'promotion', 500 ) && '' === Marketing::locked_badge( 'promotion', 501 ) );
	foreach ( array( '满减活动' => array( Promotions_Page::class, 500 ), '优惠码' => array( Coupons_Page::class, 901 ), '运费折扣' => array( Shipping_Discounts_Page::class, 1 ) ) as $name => [ $page, $id ] ) {
		$_GET = array( 'edit' => (string) $id );
		$check( '管理员打开系列下发的' . $name . '的编辑页：403', 403 === $status( array( $page, 'render' ) ) );
		$_GET  = array( 'id' => (string) $id, 'on' => '0' );
		$check( '管理员停用系列下发的' . $name . '：403', 403 === $status( array( $page, 'toggle' ) ) );
		$_GET  = array();
		$_POST = array( 'id' => (string) $id, 'name' => 'x' );
		$check( '管理员保存系列下发的' . $name . '：403', 403 === $status( array( $page, 'save' ) ) );
		$_POST = array();
	}
	$check( '管理员处理本店自己建的活动：不拦', 200 === $status( static fn() => Marketing::guard_unlocked( 'promotion', 501 ) ) && 200 === $status( static fn() => Marketing::guard_unlocked( 'coupon', 902 ) ) && 200 === $status( static fn() => Marketing::guard_unlocked( 'ship_discount', 2 ) ) );
	$GLOBALS['pagenow'] = 'post.php';
	$_REQUEST           = array( 'post' => '901', 'action' => 'edit', 'pc_classic' => '1' );
	$check( 'WooCommerce 原来的优惠券编辑页也改不了系列下发的券', 403 === $status( array( Marketing::class, 'redirect_old_screens' ) ) );
	$_REQUEST = array();
	update_option( \PeptideCore\First_Order_Offer::OPTION, array( 'enabled' => true, 'coupon_id' => 900, 'series_key' => 'main' ) );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = array( 'mode' => 'save', 'coupon_id' => '0' );
	$check( '首单优惠由系列下发时，管理员在店铺后台也改不了', 403 === $status( array( First_Order_Offer_Page::class, 'handle' ) ) && 900 === get_option( \PeptideCore\First_Order_Offer::OPTION )['coupon_id'] );
	$_POST = array();
	update_option( \PeptideCore\Exit_Intent::OPTION, array( 'enabled' => true, 'type' => 'message', 'title' => 'Series popup', 'series_key' => 'exit_intent/main', 'series_hash' => 'h' ) );
	$errors = \PeptideCore\Exit_Intent::save( array( 'enabled' => false, 'type' => 'message', 'title' => 'Changed by store' ) );
	$check( '离开挽回由系列下发时，登录的后台用户保存被拒绝，内容不变', 1 === count( $errors ) && 'Series popup' === get_option( \PeptideCore\Exit_Intent::OPTION )['title'] );
	$GLOBALS['m4']['user'] = 0;
	$errors                = \PeptideCore\Exit_Intent::save( array( 'enabled' => false, 'type' => 'message', 'title' => 'Series popup', 'button' => '', 'desktop_exit' => '1', 'pages' => array( 'home' ) ) );
	$after                 = get_option( \PeptideCore\Exit_Intent::OPTION );
	$check( '总后台的应用开关（没有登录用户）仍然可以关掉弹窗，系列标记保留', array() === $errors && false === $after['enabled'] && 'exit_intent/main' === $after['series_key'] );

	// ---------- 营销活动已由系列接管：店铺后台不再给新建入口 ----------
	$GLOBALS['m4_series_managed'] = true;
	$login( 'shop_manager', array( 'edit_shop_coupons' ) );
	$_GET = array( 'new' => '1' );
	$check( '系列接管后，原来能改的营销员工也只读：新建地址 403，列表是“当前在跑的活动”', ! Marketing::can_manage() && Marketing::can_view() && 403 === $status( array( Promotions_Page::class, 'render' ) ) );
	$_GET = array();
	$check( '系列接管后，营销员工的列表页只读', false !== strpos( $html( array( Coupons_Page::class, 'render' ) ), '当前在跑的活动' ) );
	$login( 'administrator', array( 'edit_shop_coupons', 'manage_woocommerce', 'manage_options' ) );
	$check( '系列接管后，平台管理员（总后台一键登录）仍可处理本店自己建的活动', Marketing::can_manage() );
	$GLOBALS['m4_series_managed'] = false;

	printf( "RESULT %d 项检查：%s\n", $checks, $failed ? count( $failed ) . ' 项不通过' : '全部通过' );
	exit( $failed ? 1 : 0 );
}
