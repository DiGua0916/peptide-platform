<?php
/**
 * HQ-07：投手对营销活动只读，在真实的 WordPress + WooCommerce 上验证。
 *
 * 平台所有者 2026-11-13 定：投手可以看只读的“当前在跑的活动”，其余一律不能动。这里用一个真的投手账号
 * （故意同时给了店长角色，模拟“被误授了营销权限”）把每一个能改活动和优惠码的入口实际撞一遍：
 *   - 三个营销页面的新建 / 编辑地址、向页面提交；
 *   - 15 个提交入口共 17 种提交（满减 / 优惠码 / 运费折扣的保存、启停、批量、页面设置，首单优惠，赠品 / 捆绑的保存和删除，离开挽回的保存），
 *     每个都带着有效的 nonce——被拒绝是因为权限，不是因为 nonce；
 *   - WooCommerce 原生的优惠码能力（列表、编辑、删除、发布）和 REST 接口 wc/v3/coupons（查、建、改、删、批量）；
 *   - 直接调用保存方法、直接删文章。
 * 全部应当被服务端拒绝，而且数据一点没变。同时确认管理员和不是投手的店长照旧能改（营销功能没有被削弱），
 * 并把只读页面的文字打印出来给人看。
 *
 * 运行（只能在沙盒 / 本地站点）：
 *   M4_WALKTHROUGH=sandbox wp eval-file wp-content/plugins/peptide-core/tests/m4-buyer-readonly-native.php
 * 结束时删除临时账号、临时活动并恢复改过的选项。不发邮件、不建订单。
 *
 * @package PeptideCore
 */

use PeptideCore\Admin\Apps_Page;
use PeptideCore\Admin\Coupons_Page;
use PeptideCore\Admin\First_Order_Offer_Page;
use PeptideCore\Admin\Marketing;
use PeptideCore\Admin\Product_Offers_Page;
use PeptideCore\Admin\Promotions_Page;
use PeptideCore\Admin\Shipping_Discounts_Page;
use PeptideCore\Coupon_Rules;
use PeptideCore\Exit_Intent;
use PeptideCore\First_Order_Offer;
use PeptideCore\Product_Offers;
use PeptideCore\Promotions;
use PeptideCore\Shipping\Discounts;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'M4_Buyer_Stop' ) ) {
	/** wp_die 或跳转被截下来：code = HTTP 状态码（跳转记 302）。 */
	final class M4_Buyer_Stop extends RuntimeException {}
}

( static function (): void {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( 'sandbox' !== getenv( 'M4_WALKTHROUGH' ) || ! preg_match( '/(^|[.\-])(sandbox|localhost|local|test|staging)([.\-]|$)/i', $host ) ) {
		echo "拒绝运行：只能在沙盒 / 本地站点跑（需要 M4_WALKTHROUGH=sandbox，域名里带 sandbox / localhost / local / test / staging；当前 {$host}）。\n";
		exit( 3 );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	add_filter( 'pre_wp_mail', '__return_false' );

	$checks = 0;
	$failed = array();
	$check  = static function ( string $label, bool $ok, $got = null ) use ( &$checks, &$failed ): void {
		++$checks;
		echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . ( ! $ok && null !== $got ? '  实际：' . wp_json_encode( $got, JSON_UNESCAPED_UNICODE ) : '' ) . "\n";
		if ( ! $ok ) {
			$failed[] = $label;
		}
	};

	// wp_die 和跳转都改成抛异常：被拒绝 = 403；没被拦住的提交入口会走到跳转（302），在这里算失败，而且不会把脚本带走。
	add_filter(
		'wp_die_handler',
		static fn() => static function ( $message, $title = '', $args = array() ): void {
			$code = is_int( $title ) ? $title : ( is_int( $args ) ? $args : (int) ( is_array( $args ) ? ( $args['response'] ?? 500 ) : 500 ) );
			throw new M4_Buyer_Stop( is_wp_error( $message ) ? $message->get_error_message() : wp_strip_all_tags( (string) $message ), $code );
		},
		PHP_INT_MAX
	);
	add_filter(
		'wp_redirect',
		static function ( $location ) {
			throw new M4_Buyer_Stop( '跳转到 ' . $location, 302 );
		},
		PHP_INT_MAX
	);
	/** 执行一个入口，返回 [状态码, 说明, 输出]。200 = 正常跑完。 */
	$run = static function ( callable $fn ): array {
		ob_start();
		try {
			$fn();
			return array( 200, '', (string) ob_get_clean() );
		} catch ( M4_Buyer_Stop $e ) {
			ob_end_clean();
			return array( $e->getCode(), $e->getMessage(), '' );
		}
	};
	$request = static function ( string $method, array $get = array(), array $post = array() ): void {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET                      = $get;
		$_POST                     = $post;
		$_REQUEST                  = array_merge( $get, $post );
	};

	$tag      = 'm4by' . wp_rand( 1000, 9999 );
	$posts    = array();
	$users    = array();
	$admin    = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
	$was_user = get_current_user_id();
	$options  = array( Discounts::OPTION, First_Order_Offer::OPTION, Exit_Intent::OPTION, Promotions::PAGE_OPTION );
	$saved    = array();
	foreach ( $options as $name ) {
		$saved[ $name ] = get_option( $name, null );
	}
	// 投手角色由 M9 创建；这条分支上没有 M9 的代码时临时建一个，结束时删掉。
	$made_role = false;
	if ( ! get_role( Marketing::BUYER_ROLE ) ) {
		add_role( Marketing::BUYER_ROLE, 'Media buyer', array( 'read' => true, 'view_admin_dashboard' => true ) );
		$made_role = true;
	}

	try {
		wp_set_current_user( $admin );

		// ---------- 一家有各种活动的店 ----------
		$product = static function ( string $name, string $price ) use ( $tag, &$posts ): int {
			$p = new WC_Product_Simple();
			$p->set_name( 'M4BY ' . $name . ' ' . $tag );
			$p->set_regular_price( $price );
			$p->set_status( 'publish' );
			$p->set_catalog_visibility( 'hidden' );
			$id      = (int) $p->save();
			$posts[] = $id;
			return $id;
		};
		$pa = $product( 'Peptide A', '50' );
		$pb = $product( 'Peptide B', '30' );
		$pg = $product( 'Bac Water', '8' );

		$promo = (int) wp_insert_post( array( 'post_type' => Promotions::CPT, 'post_status' => 'publish', 'post_title' => 'M4BY Spend and save ' . $tag ) );
		update_post_meta( $promo, Promotions::META, Promotions::clean( array( 'type' => 'spend_amount', 'tiers' => array( array( 'min' => 100, 'value' => 10 ), array( 'min' => 200, 'value' => 25 ) ), 'scope' => 'products', 'products' => array( $pa, $pb ) ) )[0] );
		$posts[] = $promo;
		$promo2  = (int) wp_insert_post( array( 'post_type' => Promotions::CPT, 'post_status' => 'publish', 'post_title' => 'M4BY Buy 2 get 1 ' . $tag ) );
		update_post_meta( $promo2, Promotions::META, Promotions::clean( array( 'type' => 'buy_x_free_y', 'tiers' => array( array( 'min' => 3, 'value' => 1 ) ), 'scope' => 'products', 'products' => array( $pg ), 'with_coupons' => false ) )[0] );
		$posts[] = $promo2;

		$coupon = static function ( string $code, string $type, string $amount, array $meta = array(), array $props = array() ) use ( $tag, &$posts ): int {
			$k = new WC_Coupon();
			$k->set_code( $tag . $code );
			$k->set_discount_type( $type );
			$k->set_amount( $amount );
			foreach ( $props as $setter => $value ) {
				$k->{'set_' . $setter}( $value );
			}
			foreach ( $meta as $key => $value ) {
				$k->update_meta_data( $key, $value );
			}
			$id      = (int) $k->save();
			$posts[] = $id;
			return $id;
		};
		$save10  = $coupon( 'save10', 'percent', '10', array( Coupon_Rules::STRATEGY_META => 'stack' ) );
		$solo5   = $coupon( 'solo5', 'fixed_cart', '5', array( Coupon_Rules::STRATEGY_META => 'no_stack', Coupon_Rules::MIN_SPEND_META => '60' ), array( 'usage_limit' => 100, 'usage_limit_per_user' => 1 ) );
		$welcome = $coupon( 'welcome', 'percent', '5', array( Coupon_Rules::FIRST_META => 'yes', Coupon_Rules::STRATEGY_META => 'stack' ), array( 'usage_limit_per_user' => 1 ) );
		update_option( First_Order_Offer::OPTION, array( 'enabled' => true, 'coupon_id' => $welcome ), false );

		$all   = is_array( $saved[ Discounts::OPTION ] ) ? $saved[ Discounts::OPTION ] : array();
		$disc  = 880000 + wp_rand( 1, 9999 );
		$all[] = array_merge( Discounts::defaults(), array( 'id' => $disc, 'name' => 'M4BY Half shipping ' . $tag, 'kind' => 'percent', 'value' => 50.0, 'created' => time() ) );
		update_option( Discounts::OPTION, $all, false );

		$offer = (int) wp_insert_post( array( 'post_type' => Product_Offers::CPT, 'post_status' => 'publish', 'post_title' => 'M4BY Free water ' . $tag ) );
		update_post_meta( $offer, Product_Offers::META, array_merge( Product_Offers::defaults(), array( 'enabled' => true, 'kind' => 'gift', 'basis' => 'spend', 'threshold' => 80.0, 'qualifiers' => array( $pa ), 'gift_product' => $pg, 'gift_qty' => 1 ) ) );
		$posts[] = $offer;

		update_option( Exit_Intent::OPTION, array_merge( Exit_Intent::defaults(), array( 'enabled' => true, 'type' => 'wheel', 'title' => 'Spin to win', 'pages' => array( 'home', 'product' ), 'audience' => 'cart', 'frequency_days' => 3, 'prizes' => array( array( 'label' => '10% OFF', 'coupon' => $tag . 'save10', 'weight' => 3 ), array( 'label' => 'Try again', 'coupon' => '', 'weight' => 97 ) ) ) ), false );

		/** 数据的指纹：所有尝试之后应当和之前一模一样。 */
		$fingerprint = static function () use ( $promo, $promo2, $save10, $solo5, $welcome, $offer ): string {
			wp_cache_flush();
			$parts = array();
			foreach ( array( $promo, $promo2, $save10, $solo5, $welcome, $offer ) as $id ) {
				$post    = get_post( $id );
				$parts[] = array( $id, $post ? $post->post_status : 'GONE', $post ? $post->post_title : '', get_post_meta( $id ) );
			}
			$parts[] = get_option( Discounts::OPTION );
			$parts[] = get_option( First_Order_Offer::OPTION );
			$parts[] = get_option( Exit_Intent::OPTION );
			$parts[] = (int) wp_count_posts( 'shop_coupon' )->publish + (int) wp_count_posts( 'shop_coupon' )->draft + (int) wp_count_posts( 'shop_coupon' )->trash;
			$parts[] = (int) wp_count_posts( Promotions::CPT )->publish + (int) wp_count_posts( Promotions::CPT )->draft;
			return md5( (string) wp_json_encode( $parts ) );
		};
		$before = $fingerprint();

		// ---------- 账号 ----------
		$user = static function ( string $login, array $roles ) use ( $tag, &$users ): int {
			$id = (int) wp_insert_user( array( 'user_login' => $tag . $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $tag . $login . '@example.com', 'role' => array_shift( $roles ) ) );
			foreach ( $roles as $role ) {
				get_userdata( $id )->add_role( $role );
			}
			$users[] = $id;
			return $id;
		};
		$buyer_plus = $user( 'buyerplus', array( 'shop_manager', Marketing::BUYER_ROLE ) ); // 投手，但被误授了店长的全部能力
		$buyer      = $user( 'buyer', array( Marketing::BUYER_ROLE ) );                     // 正常的投手：只有 read
		$manager    = $user( 'manager', array( 'shop_manager' ) );                          // 不是投手的店长

		// ---------- 1. 能力 ----------
		wp_set_current_user( $buyer_plus );
		$coupon_caps = array( 'edit_shop_coupons', 'edit_others_shop_coupons', 'publish_shop_coupons', 'read_private_shop_coupons', 'delete_shop_coupons', 'delete_private_shop_coupons', 'delete_published_shop_coupons', 'delete_others_shop_coupons', 'edit_private_shop_coupons', 'edit_published_shop_coupons', 'manage_shop_coupon_terms', 'edit_shop_coupon_terms', 'delete_shop_coupon_terms', 'assign_shop_coupon_terms' );
		$has         = array_values( array_filter( $coupon_caps, 'current_user_can' ) );
		$check( '投手（即使带着店长角色）没有任何优惠码能力，共 ' . count( $coupon_caps ) . ' 项', array() === $has, $has );
		$meta_caps = array();
		foreach ( array( '优惠码' => $save10, '满减活动' => $promo, '赠品' => $offer ) as $name => $id ) {
			foreach ( array( 'edit_post', 'delete_post', 'publish_post' ) as $cap ) {
				if ( current_user_can( $cap, $id ) ) {
					$meta_caps[] = $name . ':' . $cap;
				}
			}
		}
		$check( '投手不能编辑、删除、发布任何一张优惠码、满减活动、赠品（WordPress 原生的权限判断）', array() === $meta_caps, $meta_caps );
		$check( '店长的其他能力没有被动（只去掉优惠码相关的）', current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_products' ) );
		$check( '投手：能看、不能改；注册菜单用 read', Marketing::is_buyer() && Marketing::can_view() && ! Marketing::can_manage() && 'read' === Marketing::menu_cap() );

		// ---------- 2. 三个页面 ----------
		$pages = array( '满减活动' => array( Promotions_Page::class, $promo ), '优惠码' => array( Coupons_Page::class, $save10 ), '运费折扣' => array( Shipping_Discounts_Page::class, $disc ) );
		foreach ( $pages as $name => [ $page, $id ] ) {
			$request( 'GET', array( 'page' => $page::SLUG, 'new' => '1' ) );
			$check( '投手打开“新建' . $name . '”：403', 403 === $run( array( $page, 'render' ) )[0] );
			$request( 'GET', array( 'page' => $page::SLUG, 'edit' => (string) $id ) );
			$check( '投手打开“编辑' . $name . '”：403', 403 === $run( array( $page, 'render' ) )[0] );
			$request( 'POST', array( 'page' => $page::SLUG ), array( 'name' => 'x' ) );
			$check( '投手向“' . $name . '”页面提交：403', 403 === $run( array( $page, 'render' ) )[0] );
			$request( 'GET', array( 'page' => $page::SLUG ) );
			[ $code, , $out ] = $run( array( $page, 'render' ) );
			$check( '投手打开“' . $name . '”列表：只读的“当前在跑的活动”，没有表单、按钮、链接', 200 === $code && false !== strpos( $out, '当前在跑的活动' ) && false === stripos( $out, '<form' ) && false === stripos( $out, '<input' ) && false === stripos( $out, '<button' ) && false === stripos( $out, '<a ' ) && false === strpos( $out, 'admin-post.php' ) && false === strpos( $out, '_wpnonce' ) );
		}

		// 离开挽回 / 首单优惠的设置页没有只读视图：投手（哪怕带着店长的能力）打开直接 403。
		foreach ( array( '离开挽回 / 转盘' => 'exit-intent', '首单优惠' => 'first-order-offer' ) as $name => $app ) {
			$request( 'GET', array( 'page' => 'pc-apps', 'app' => $app ) );
			$check( '投手打开“' . $name . '”的设置页（应用中心里）：403', 403 === $run( array( Marketing::class, 'deny_buyer_settings' ) )[0] );
		}
		$check( '有只读视图的页面只有三个', array( 'pc-promotions', 'pc-coupons', 'pc-shipping-discounts' ) === Marketing::pages() );

		// ---------- 3. 15 个提交入口（17 种提交），都带有效的 nonce ----------
		// 每个入口按真实的 admin-post 动作触发（do_action( 'admin_post_<动作>' )），和浏览器提交走的是同一条路。
		// 命令行里后台页面的钩子没有注册（它们只在 wp-admin 里注册），所以这里先把处理函数挂到各自的动作上。
		$entries = array(
			'满减活动 · 保存'        => array( 'pc_promo_save', array( Promotions_Page::class, 'save' ), 'pc_promo_save', array(), array( 'id' => (string) $promo, 'name' => 'HACKED', 'type' => 'spend_amount', 'tiers' => array( array( 'min' => '1', 'value' => '0.5' ) ), 'enabled' => '1' ) ),
			'满减活动 · 新建'        => array( 'pc_promo_save', array( Promotions_Page::class, 'save' ), 'pc_promo_save', array(), array( 'id' => '0', 'name' => 'HACKED NEW', 'type' => 'spend_amount', 'tiers' => array( array( 'min' => '1', 'value' => '0.5' ) ), 'enabled' => '1' ) ),
			'满减活动 · 停用'        => array( 'pc_promo_toggle', array( Promotions_Page::class, 'toggle' ), 'pc_promo_toggle', array( 'id' => (string) $promo, 'on' => '0' ), array() ),
			'满减活动 · 批量删除'    => array( 'pc_promo_bulk', array( Promotions_Page::class, 'bulk' ), 'pc_promo_bulk', array(), array( 'ids' => array( (string) $promo, (string) $promo2 ), 'bulk' => 'delete', 'bulk_action' => 'delete' ) ),
			'满减活动 · 页面设置'    => array( 'pc_promo_settings', array( Promotions_Page::class, 'save_settings' ), 'pc_promo_settings', array(), array( 'page_id' => '0' ) ),
			'优惠码 · 保存'          => array( 'pc_coupon_save', array( Coupons_Page::class, 'save' ), 'pc_coupon_save', array(), array( 'id' => (string) $save10, 'code' => $tag . 'save10', 'amount' => '99', 'discount_type' => 'percent' ) ),
			'优惠码 · 新建'          => array( 'pc_coupon_save', array( Coupons_Page::class, 'save' ), 'pc_coupon_save', array(), array( 'id' => '0', 'code' => $tag . 'hacked', 'amount' => '99', 'discount_type' => 'percent' ) ),
			'优惠码 · 停用'          => array( 'pc_coupon_toggle', array( Coupons_Page::class, 'toggle' ), 'pc_coupon_toggle', array( 'id' => (string) $save10, 'on' => '0' ), array() ),
			'优惠码 · 批量删除'      => array( 'pc_coupon_bulk', array( Coupons_Page::class, 'bulk' ), 'pc_coupon_bulk', array(), array( 'ids' => array( (string) $save10, (string) $solo5 ), 'bulk' => 'delete', 'bulk_action' => 'delete' ) ),
			'优惠码 · 页面设置'      => array( 'pc_coupon_settings', array( Coupons_Page::class, 'save_settings' ), 'pc_coupon_settings', array(), array( 'page_id' => '0' ) ),
			'运费折扣 · 保存'        => array( 'pc_shipdisc_save', array( Shipping_Discounts_Page::class, 'save' ), 'pc_shipdisc_save', array(), array( 'id' => (string) $disc, 'name' => 'HACKED', 'kind' => 'percent', 'value' => '100' ) ),
			'运费折扣 · 停用'        => array( 'pc_shipdisc_toggle', array( Shipping_Discounts_Page::class, 'toggle' ), 'pc_shipdisc_toggle', array( 'id' => (string) $disc, 'on' => '0' ), array() ),
			'运费折扣 · 批量删除'    => array( 'pc_shipdisc_bulk', array( Shipping_Discounts_Page::class, 'bulk' ), 'pc_shipdisc_bulk', array(), array( 'ids' => array( (string) $disc ), 'bulk' => 'delete', 'bulk_action' => 'delete' ) ),
			'首单优惠 · 保存'        => array( First_Order_Offer_Page::ACTION, array( First_Order_Offer_Page::class, 'handle' ), First_Order_Offer_Page::ACTION, array(), array( 'mode' => 'save', 'coupon_id' => '0' ) ),
			'赠品 / 捆绑 · 保存'     => array( 'pc_product_offer_save', array( Product_Offers_Page::class, 'save' ), 'pc_product_offer_save', array(), array( 'id' => (string) $offer, 'title' => 'HACKED', 'kind' => 'gift' ) ),
			'赠品 / 捆绑 · 删除'     => array( 'pc_product_offer_delete', array( Product_Offers_Page::class, 'delete' ), 'pc_product_offer_delete_' . $offer, array(), array( 'id' => (string) $offer, 'confirm_delete' => '1' ) ),
			'离开挽回 / 转盘 · 保存' => array( 'pc_app_exit_save', array( Apps_Page::class, 'save_exit' ), 'pc_app_exit_save', array(), array( 'enabled' => '0', 'type' => 'message', 'title' => 'HACKED' ) ),
		);
		foreach ( $entries as $name => [ $action, $handler, $nonce, $get, $post ] ) {
			if ( false === has_action( 'admin_post_' . $action, $handler ) ) {
				add_action( 'admin_post_' . $action, $handler );
			}
			$post['_wpnonce'] = wp_create_nonce( $nonce );
			$get['_wpnonce']  = $post['_wpnonce'];
			$request( 'POST', $get + array( 'action' => $action ), $post );
			[ $code, $message ] = $run( static fn() => do_action( 'admin_post_' . $action ) );
			$check( '投手提交“' . $name . '”：403', 403 === $code, array( $code, $message ) );
		}
		$request( 'GET' );

		// ---------- 4. 直接调用保存方法、直接删文章 ----------
		$check( '投手直接调用“保存赠品 / 捆绑”：拒绝', is_wp_error( Product_Offers::store( $offer, array( 'title' => 'HACKED', 'kind' => 'gift' ) ) ) && is_wp_error( Product_Offers::store( 0, array( 'title' => 'HACKED', 'kind' => 'gift' ) ) ) );
		$check( '投手直接删除赠品（彻底删除、移到回收站）：删不掉', false === wp_delete_post( $offer, true ) && false === wp_trash_post( $offer ) && 'publish' === get_post_status( $offer ) );
		$errors = Exit_Intent::save( array( 'enabled' => false, 'type' => 'message', 'title' => 'HACKED', 'button' => '', 'desktop_exit' => '1', 'pages' => array( 'home' ) ) );
		$check( '投手直接调用“保存离开挽回设置”：拒绝', 1 === count( $errors ) && 'Spin to win' === Exit_Intent::settings()['title'], $errors );

		// ---------- 5. WooCommerce 的 REST 接口 ----------
		$rest = static function ( string $method, string $route, array $body = array() ): int {
			$req = new WP_REST_Request( $method, $route );
			foreach ( $body as $k => $v ) {
				$req->set_param( $k, $v );
			}
			return (int) rest_do_request( $req )->get_status();
		};
		$denied = static fn( int $status ): bool => in_array( $status, array( 401, 403 ), true );
		$codes  = array(
			'查列表' => $rest( 'GET', '/wc/v3/coupons' ),
			'新建'   => $rest( 'POST', '/wc/v3/coupons', array( 'code' => $tag . 'restnew', 'discount_type' => 'percent', 'amount' => '99' ) ),
			'修改'   => $rest( 'PUT', '/wc/v3/coupons/' . $save10, array( 'amount' => '99' ) ),
			'删除'   => $rest( 'DELETE', '/wc/v3/coupons/' . $save10, array( 'force' => true ) ),
			'批量'   => $rest( 'POST', '/wc/v3/coupons/batch', array( 'update' => array( array( 'id' => $save10, 'amount' => '99' ) ), 'delete' => array( $solo5 ) ) ),
		);
		$check( '投手调用 REST 接口 wc/v3/coupons（查、建、改、删、批量）：全部拒绝', 5 === count( array_filter( $codes, $denied ) ), $codes );
		$check( 'REST 没有建出新的优惠码', 0 === wc_get_coupon_id_by_code( $tag . 'restnew' ) && 0 === wc_get_coupon_id_by_code( $tag . 'hacked' ) );

		// ---------- 6. 数据一点没变 ----------
		wp_set_current_user( $admin );
		$check( '所有尝试之后：满减、优惠码、运费折扣、首单、赠品、离开挽回的数据和之前一模一样，也没有多出新的', $before === $fingerprint() );

		// ---------- 7. 正常的投手（只有 read）看只读页 ----------
		wp_set_current_user( $buyer );
		$check( '正常的投手（只有 read，没有任何店铺能力）：能看、不能改', Marketing::can_view() && ! Marketing::can_manage() && 'read' === Marketing::menu_cap() && ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_coupons' ) );
		$request( 'GET', array( 'page' => Promotions_Page::SLUG ) );
		[ $code, , $out ] = $run( array( Promotions_Page::class, 'render' ) );
		$text = trim( (string) preg_replace( '/[ \t]+/', ' ', (string) preg_replace( '/\s*\n\s*/', "\n", wp_strip_all_tags( (string) preg_replace( '#</(h1|h2|h3|p|li|tr|dt|dd|th|td|section)>#', "$0\n", $out ) ) ) ) );
		$wants = array(
			'条件（顾客要做到）', '优惠（顾客能得到）',
			'一单买满 $100', '减 $10', '一单买满 $200', '减 $25',          // 满额减元两档
			'买 3 件', '其中最便宜的 1 件免费', '不能和优惠码一起用',      // 买 X 免 Y，不能和优惠码同时用
			'输入优惠码 ' . strtoupper( $tag . 'save10' ), '?pc_coupon=' . strtoupper( $tag . 'save10' ), '商品金额减 10%（相当于 9 折）',
			'一单买满 $60', '整单减 $5', '全店一共 100 次，还剩 100 次', '每位顾客最多用 1 次',
			'只限第一次下单的新顾客', '首单券条',
			'运费减 50%（相当于 5 折）',
			'一单买满 $80', '免费送 M4BY Bac Water ' . $tag . ' × 1',
			'抽奖转盘', '10% OFF（中了发优惠码 ' . strtoupper( $tag . 'save10' ) . '）', 'Try again（这一格没有奖）', '不要承诺一定中奖',
			'M4BY Peptide A ' . $tag,                                       // 适用商品写的是商品名
		);
		$missing = array_values( array_filter( $wants, static fn( $w ) => false === strpos( $text, $w ) ) );
		$check( '只读页面用大白话写清每个活动的条件和优惠（' . count( $wants ) . ' 处文字都在）', 200 === $code && array() === $missing, $missing );
		$ids   = array( $promo, $promo2, $save10, $solo5, $welcome, $offer, $disc, $pa, $pb, $pg );
		$shown = array_values( array_filter( $ids, static fn( $id ) => (bool) preg_match( '/(?<![\d$.,])' . $id . '(?![\d.,])/', $text ) ) );
		$check( '只读页面不显示内部编号（活动、优惠码、运费折扣、商品的编号都不出现），也不显示中奖概率', array() === $shown && false === strpos( $text, '97' ) && false === strpos( $out, 'data-id' ), $shown );
		$check( '投手看不到“来源”这一行', false === strpos( $text, '来源' ) );

		// ---------- 8. 没有被削弱：管理员和不是投手的店长照旧 ----------
		wp_set_current_user( $manager );
		$check( '不是投手的店长：优惠码能力都在，能改', current_user_can( 'edit_shop_coupons' ) && current_user_can( 'edit_post', $save10 ) && current_user_can( 'delete_post', $save10 ) && Marketing::can_manage() && ! Marketing::is_buyer() );
		$request( 'POST', array(), array( '_wpnonce' => wp_create_nonce( 'pc_coupon_save' ) ) );
		$check( '不是投手的店长提交保存：通过权限检查和 nonce 检查', 200 === $run( static fn() => Marketing::guard( 'pc_coupon_save' ) )[0] );
		wp_set_current_user( $admin );
		$request( 'GET', array( 'page' => Promotions_Page::SLUG ) );
		[ $code, , $out ] = $run( array( Promotions_Page::class, 'render' ) );
		$check( '管理员：看到的是原来可以管理的列表（有新建入口），不是只读页', 200 === $code && Marketing::can_manage() && false === strpos( $out, '当前在跑的活动' ) && false !== strpos( $out, 'new=1' ) );
		$created = $rest( 'POST', '/wc/v3/coupons', array( 'code' => $tag . 'adminrest', 'discount_type' => 'percent', 'amount' => '7' ) );
		$new_id  = (int) wc_get_coupon_id_by_code( $tag . 'adminrest' );
		if ( $new_id ) {
			$posts[] = $new_id;
		}
		$check( '管理员用 REST 接口建优惠码：照常成功', 201 === $created && $new_id > 0, $created );
		$errors = Promotions::set_enabled( $promo2, false );
		$check( '管理员停用一个满减活动：照常成功', array() === $errors && 'off' === Promotions::status( $promo2 ), $errors );

		echo "\n-------- 投手看到的只读页面（纯文字） --------\n" . $text . "\n-------- 只读页面结束 --------\n\n";
	} finally {
		wp_set_current_user( $admin );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		foreach ( $posts as $id ) {
			if ( 'product' === get_post_type( $id ) ) {
				$p = wc_get_product( $id );
				if ( $p ) {
					$p->delete( true );
				}
			} else {
				wp_delete_post( (int) $id, true );
			}
		}
		foreach ( $users as $id ) {
			wp_delete_user( (int) $id );
		}
		foreach ( $saved as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value, false );
			}
		}
		if ( $made_role ) {
			remove_role( Marketing::BUYER_ROLE );
		}
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
		wp_set_current_user( $was_user );
	}
	$left = get_posts( array( 'post_type' => array( 'product', 'shop_coupon', Promotions::CPT, Product_Offers::CPT ), 'post_status' => 'any', 's' => $tag, 'fields' => 'ids', 'posts_per_page' => 5 ) );
	printf( "RESULT %d 项检查：%s\n", $checks, $failed ? count( $failed ) . ' 项不通过' : '全部通过' );
	echo $left ? "注意：还有 " . count( $left ) . " 条临时数据没删掉（名字里带 {$tag}）。\n" : "临时账号和临时数据已删除，选项已恢复。\n";
	exit( $failed ? 1 : 0 );
} )();
