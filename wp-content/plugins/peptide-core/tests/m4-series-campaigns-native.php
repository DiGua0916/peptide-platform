<?php
/**
 * HQ-07 店铺端：按系列接收营销活动（Pricing\Series_Campaigns），在真实的 WordPress + WooCommerce 上验证。
 *
 * 直接调用 M3 约定的四个钩子（不需要 M3 的代码在场）：试运行不写入、下发写入各功能现有的存储并打系列标记、
 * 重复下发不重复写、内容不合规时一条都不写、全量对齐（没下发的停用，首单 / 弹窗还原成本店原来的设置）、
 * 和本店自建活动冲突时不覆盖、快照和恢复、母版店导出；并用真实购物车确认下发的满减和首单券真的生效、和引擎核对一致。
 *
 * 运行（只能在沙盒 / 本地站点）：
 *   M4_WALKTHROUGH=sandbox wp eval-file wp-content/plugins/peptide-core/tests/m4-series-campaigns-native.php
 * 结束时删除临时数据并恢复改过的选项。
 *
 * @package PeptideCore
 */

use PeptideCore\Admin\Marketing;
use PeptideCore\Coupon_Rules;
use PeptideCore\Exit_Intent;
use PeptideCore\First_Order_Offer;
use PeptideCore\Pricing\Campaigns;
use PeptideCore\Pricing\Series_Campaigns;
use PeptideCore\Pricing\Shadow;
use PeptideCore\Promotions;
use PeptideCore\Shipping\Discounts;

defined( 'ABSPATH' ) || exit;

( static function (): void {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( 'sandbox' !== getenv( 'M4_WALKTHROUGH' ) || ! preg_match( '/(^|[.\-])(sandbox|localhost|local|test|staging)([.\-]|$)/i', $host ) ) {
		echo "拒绝运行：只能在沙盒 / 本地站点跑（需要 M4_WALKTHROUGH=sandbox，域名里带 sandbox / localhost / local / test / staging；当前 {$host}）。\n";
		exit( 3 );
	}
	if ( null === WC()->cart ) {
		wc_load_cart();
	}
	$checks = 0;
	$failed = array();
	$check  = static function ( string $label, bool $ok ) use ( &$checks, &$failed ): void {
		++$checks;
		echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . "\n";
		if ( ! $ok ) {
			$failed[] = $label;
		}
	};
	$options = array( Series_Campaigns::OPTION, First_Order_Offer::OPTION, Exit_Intent::OPTION, Discounts::OPTION, \PeptideCore\Pricing\Mode::OPTION );
	$saved   = array();
	foreach ( $options as $name ) {
		$saved[ $name ] = get_option( $name, null );
	}
	$posts = array();
	$terms = array();
	$tag   = 'm4sc' . wp_rand( 1000, 9999 );
	$marked = static function (): array {
		return get_posts( array( 'post_type' => array( Promotions::CPT, 'shop_coupon' ), 'post_status' => array( 'publish', 'draft' ), 'meta_key' => Series_Campaigns::META, 'fields' => 'ids', 'posts_per_page' => 500, 'no_found_rows' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	};
	$before_marked = $marked();
	$apply  = static fn( array $items, int $ver, bool $dry = false ) => apply_filters( 'pc_series_apply_campaigns', null, $items, $ver, $dry );
	$status = static function ( $report ): array {
		$out = array();
		foreach ( is_array( $report ) ? (array) ( $report['items'] ?? array() ) : array() as $row ) {
			$out[ $row['kind'] . '/' . $row['key'] ] = $row['status'];
		}
		return $out;
	};

	try {
		// ---------- 本店的商品和原有设置 ----------
		$term    = wp_insert_term( 'M4 series ' . $tag, 'product_cat', array( 'slug' => $tag ) );
		$cat     = (int) $term['term_id'];
		$terms[] = $cat;
		$product = static function ( string $sku, string $price ) use ( $tag, $cat, &$posts ): int {
			$p = new \WC_Product_Simple();
			$p->set_name( 'M4SC ' . $sku );
			$p->set_status( 'publish' );
			$p->set_catalog_visibility( 'hidden' );
			$p->set_regular_price( $price );
			$p->set_category_ids( array( $cat ) );
			$id = (int) $p->save();
			update_post_meta( $id, '_pc_series_sku', $tag . '-' . $sku );
			$posts[] = $id;
			return $id;
		};
		$p1 = $product( 'a', '50.00' );
		$p2 = $product( 'b', '20.00' );
		// 本店原来自己配的离开挽回弹窗（系列接管、再停用后要还原成它）。
		update_option( Exit_Intent::OPTION, array_merge( Exit_Intent::defaults(), array( 'enabled' => false, 'type' => 'message', 'title' => 'Store own popup ' . $tag ) ), false );
		update_option( First_Order_Offer::OPTION, First_Order_Offer::defaults(), false );

		$welcome = strtoupper( $tag ) . 'WELCOME';
		$save10  = strtoupper( $tag ) . 'SAVE10';
		$items   = array(
			array( 'kind' => 'coupon', 'key' => 'welcome', 'active' => true, 'config' => array( 'code' => $welcome, 'description' => 'First order', 'type' => 'percent', 'amount' => 5, 'usage_limit_per_user' => 1, 'first_order' => true, 'strategy' => 'stack' ) ),
			array( 'kind' => 'coupon', 'key' => 'save10', 'active' => true, 'config' => array( 'code' => $save10, 'type' => 'percent', 'amount' => 10, 'products' => array( $tag . '-a' ), 'strategy' => 'no_stack', 'min_spend' => 20 ) ),
			array( 'kind' => 'promotion', 'key' => 'bf', 'active' => true, 'config' => array( 'name' => 'Series BF ' . $tag, 'type' => 'spend_amount', 'tiers' => array( array( 'min' => 100, 'value' => 10 ), array( 'min' => 200, 'value' => 25 ) ), 'scope' => 'products', 'products' => array( $tag . '-a', $tag . '-b', $tag . '-not-in-this-store' ), 'with_coupons' => true ) ),
			array( 'kind' => 'ship_discount', 'key' => 'half', 'active' => true, 'config' => array( 'name' => 'Series half shipping ' . $tag, 'mode' => 'fixed', 'kind' => 'percent', 'value' => 50 ) ),
			array( 'kind' => 'first_order', 'key' => 'main', 'active' => true, 'config' => array( 'coupon' => $welcome ) ),
			array( 'kind' => 'exit_intent', 'key' => 'main', 'active' => true, 'config' => array( 'type' => 'coupon', 'title' => 'Series popup ' . $tag, 'coupon' => $save10, 'desktop_exit' => true, 'mobile' => array( 'back' ), 'pages' => array( 'home', 'product' ) ) ),
			array( 'kind' => 'upsell', 'key' => 'main', 'active' => true, 'config' => array() ),
		);

		// ---------- 试运行：只出差异，不写 ----------
		$dry = $apply( $items, 1, true );
		$st  = $status( $dry );
		$check( '试运行：6 条要新建、1 条这一版不接，并指出本店找不到的商品', array( 'coupon/welcome' => 'create', 'coupon/save10' => 'create', 'promotion/bf' => 'create', 'ship_discount/half' => 'create', 'first_order/main' => 'create', 'exit_intent/main' => 'create', 'upsell/main' => 'unsupported' ) === $st && in_array( $tag . '-not-in-this-store', (array) ( $dry['items'][2]['missing'] ?? array() ), true ) );
		$check( '试运行：什么都没写', $before_marked === $marked() && ! wc_get_coupon_id_by_code( $welcome ) && array() === Series_Campaigns::state()['items'] && '' === (string) ( get_option( Exit_Intent::OPTION )['series_key'] ?? '' ) );

		// ---------- 下发 ----------
		$r1 = $apply( $items, 1 );
		$st = $status( $r1 );
		$check( '下发：6 条写入，返回摘要', is_array( $r1 ) && 6 === ( $r1['summary']['applied'] ?? 0 ) && 1 === ( $r1['summary']['unsupported'] ?? 0 ) && 'applied' === $st['promotion/bf'] );
		$promo_ids = array_values( array_diff( array_map( 'intval', get_posts( array( 'post_type' => Promotions::CPT, 'post_status' => 'publish', 'meta_key' => Series_Campaigns::META, 'meta_value' => 'promotion/bf', 'fields' => 'ids' ) ) ), $before_marked ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$promo     = (int) ( $promo_ids[0] ?? 0 );
		$config    = $promo ? Promotions::config( $promo ) : array();
		$check( '满减：写成本店的满减活动，商品键换成了本店的商品 ID，带系列标记', $promo && 'active' === Promotions::status( $promo ) && array( $p1, $p2 ) === ( $config['products'] ?? null ) && 'spend_amount' === $config['type'] && 2 === count( $config['tiers'] ) && Series_Campaigns::locked( 'promotion', $promo ) );
		$wid = (int) wc_get_coupon_id_by_code( $welcome );
		$sid = (int) wc_get_coupon_id_by_code( $save10 );
		$w   = new \WC_Coupon( $wid );
		$s   = new \WC_Coupon( $sid );
		$check( '优惠码：两张都建好，类型、金额、限定商品、叠加策略、全局首单、满金额都对', $wid && $sid && 'percent' === $w->get_discount_type() && 5.0 === (float) $w->get_amount() && 1 === (int) $w->get_usage_limit_per_user() && 'yes' === get_post_meta( $wid, Coupon_Rules::FIRST_META, true ) && 'stack' === Coupon_Rules::strategy( $w ) && array( $p1 ) === array_map( 'intval', $s->get_product_ids() ) && 'no_stack' === Coupon_Rules::strategy( $s ) && 20.0 === (float) get_post_meta( $sid, Coupon_Rules::MIN_SPEND_META, true ) && $welcome === Coupon_Rules::display_code( $w ) && Series_Campaigns::locked( 'coupon', $wid ) );
		$first = First_Order_Offer::coupon();
		$check( '首单优惠：绑定到系列下发的券并启用', $first instanceof \WC_Coupon && $first->get_id() === $wid );
		$ship = array_values( array_filter( Discounts::all(), static fn( $d ) => 'half' === ( $d['series_key'] ?? '' ) ) );
		$check( '运费折扣：建好并启用，带系列标记', 1 === count( $ship ) && $ship[0]['enabled'] && 'percent' === $ship[0]['kind'] && 50.0 === (float) $ship[0]['value'] && Series_Campaigns::locked( 'ship_discount', (int) $ship[0]['id'] ) );
		$exit = Exit_Intent::settings();
		$check( '离开挽回：换成系列下发的弹窗并启用', $exit['enabled'] && 'coupon' === $exit['type'] && wc_format_coupon_code( $save10 ) === $exit['coupon'] && 'exit_intent/main' === $exit['series_key'] );
		$running = array();
		foreach ( Campaigns::running() as $row ) {
			if ( 'series' === $row['source'] ) {
				$running[] = $row['kind'];
			}
		}
		sort( $running );
		$check( '“当前在跑的活动”里这些都标成系列统一下发', array( 'coupon', 'exit_intent', 'first_order', 'promotion', 'shipping_discount' ) === $running );
		$check( '本店自己建的活动不算系列下发', ! Marketing::locked( 'promotion', 0 ) && ! Marketing::locked( 'coupon', $p1 ) );

		// 真实购物车：下发的满减和首单券真的生效，引擎核对一致。
		$cart = WC()->cart;
		$cart->empty_cart();
		$cart->remove_coupons();
		WC()->customer->set_billing_email( 'm4sc-new@example.invalid' );
		$cart->add_to_cart( $p1, 2 );
		$cart->apply_coupon( $welcome );
		wc_clear_notices();
		$cart->calculate_totals();
		$fees = array();
		foreach ( $cart->get_fees() as $fee ) {
			$fees[ $fee->id ] = (float) $fee->total;
		}
		update_option( \PeptideCore\Pricing\Mode::OPTION, 'shadow', true );
		$shadow = Shadow::check( $cart );
		$check( '真实购物车：$100 的商品，系列满减减 $10、系列首单券减 $5，引擎核对一致', array( 'pc-promo-' . $promo => -10.0 ) === $fees && 5.0 === (float) $cart->get_coupon_discount_amount( wc_format_coupon_code( $welcome ) ) && 'matched' === $shadow['kind'] );
		$cart->empty_cart();
		$cart->remove_coupons();

		// ---------- 重复下发 ----------
		$count = count( $marked() );
		$r2    = $apply( $items, 2 );
		$check( '同样的内容再下发一次：全部“没变”，不重复建', 6 === ( $r2['summary']['unchanged'] ?? 0 ) && $count === count( $marked() ) && 2 === Series_Campaigns::state()['ver'] );

		// ---------- 快照（给后面的恢复用） ----------
		$snapshot = apply_filters( 'pc_series_snapshot', array( 'id' => 1 ) );
		$check( '快照里记下了本店现在接管着的系列活动', 7 === count( $snapshot['campaigns']['items'] ?? array() ) && 2 === ( $snapshot['campaigns']['ver'] ?? 0 ) );

		// ---------- 内容不合规：一条都不写 ----------
		$bad                          = $items;
		$bad[2]['config']['tiers'][0] = array( 'min' => 100, 'value' => 15 );
		$bad[1]['config']['amount']   = 250; // 按比例不能超过 100
		$r3                           = $apply( $bad, 3 );
		$check( '有一条不合规：返回错误，满减的层级没有被改，版本没变', is_wp_error( $r3 ) && 'pc_series_campaigns_invalid' === $r3->get_error_code() && 10.0 === (float) Promotions::config( $promo )['tiers'][0]['value'] && 2 === Series_Campaigns::state()['ver'] );

		// ---------- 全量对齐 ----------
		$next   = array( $items[0], $items[4] );
		$next[] = array( 'kind' => 'promotion', 'key' => 'bf', 'active' => true, 'config' => array_merge( $items[2]['config'], array( 'tiers' => array( array( 'min' => 100, 'value' => 15 ) ) ) ) );
		$next[] = array( 'kind' => 'ship_discount', 'key' => 'half', 'active' => false, 'config' => $items[3]['config'] );
		$r4     = $apply( $next, 4 );
		$st     = $status( $r4 );
		$exit   = Exit_Intent::settings();
		$check( '改了层级的满减更新；没再下发的优惠码和弹窗停用；设为不启用的运费折扣停用', 'applied' === $st['promotion/bf'] && 15.0 === (float) Promotions::config( $promo )['tiers'][0]['value'] && 'disabled' === $st['coupon/save10'] && 'draft' === get_post_status( $sid ) && 'disabled' === $st['exit_intent/main'] && 'applied' === $st['ship_discount/half'] && ! Discounts::get( (int) $ship[0]['id'] )['enabled'] );
		$check( '弹窗停用后还原成本店原来自己的设置', 'Store own popup ' . $tag === $exit['title'] && ! $exit['enabled'] && '' === (string) ( get_option( Exit_Intent::OPTION )['series_key'] ?? '' ) );
		$check( '停用的优惠码不删除（订单上还引用它）', 'shop_coupon' === get_post_type( $sid ) );

		// ---------- 和本店自建的活动冲突：不覆盖 ----------
		$own = (int) wp_insert_post( array( 'post_type' => Promotions::CPT, 'post_status' => 'publish', 'post_title' => 'Store own ' . $tag ) );
		update_post_meta( $own, Promotions::META, array_merge( Promotions::defaults(), array( 'type' => 'qty_percent', 'basis' => 'quantity', 'reward' => 'percent', 'tiers' => array( array( 'min' => 2.0, 'value' => 10.0 ) ), 'scope' => 'categories', 'categories' => array( $cat ) ) ) );
		$posts[]    = $own;
		$own_coupon = new \WC_Coupon();
		$own_coupon->set_code( $tag . 'own' );
		$own_coupon->set_discount_type( 'percent' );
		$own_coupon->set_amount( 20 );
		$posts[] = (int) $own_coupon->save();
		$clash   = $next;
		$clash[] = array( 'kind' => 'promotion', 'key' => 'cat', 'active' => true, 'config' => array( 'name' => 'Series cat ' . $tag, 'type' => 'qty_amount', 'tiers' => array( array( 'min' => 2, 'value' => 3 ) ), 'scope' => 'categories', 'categories' => array( $tag ) ) );
		$clash[] = array( 'kind' => 'coupon', 'key' => 'own', 'active' => true, 'config' => array( 'code' => $tag . 'own', 'type' => 'percent', 'amount' => 50 ) );
		$r5      = $apply( $clash, 5 );
		$st      = $status( $r5 );
		$check( '和本店自建的满减、同码优惠券冲突：标 conflict，不覆盖本店的，其余照常', 'conflict' === $st['promotion/cat'] && 'conflict' === $st['coupon/own'] && 20.0 === (float) ( new \WC_Coupon( $tag . 'own' ) )->get_amount() && 'publish' === get_post_status( $own ) && 'unchanged' === $st['promotion/bf'] );

		// ---------- 恢复快照 ----------
		do_action( 'pc_series_restore', $snapshot );
		$exit = Exit_Intent::settings();
		$check( '恢复快照：满减回到当时的层级，停用的优惠码、弹窗、运费折扣都恢复', 10.0 === (float) Promotions::config( $promo )['tiers'][0]['value'] && 'publish' === get_post_status( $sid ) && $exit['enabled'] && 'exit_intent/main' === $exit['series_key'] && Discounts::get( (int) $ship[0]['id'] )['enabled'] && 2 === Series_Campaigns::state()['ver'] );
		do_action( 'pc_series_restore', array( 'id' => 0 ) );
		$check( '快照里没有活动（这个功能上线前存的）：恢复时不动活动', 'publish' === get_post_status( $sid ) );
		do_action( 'pc_series_restore', array( 'campaigns' => array( 'ver' => 0, 'items' => array() ) ) );
		$check( '恢复到“系列还没下发过活动”：系列活动全部停用，首单还原成本店原来的（未绑定）', 'draft' === get_post_status( $promo ) && 'draft' === get_post_status( $wid ) && null === First_Order_Offer::coupon() && 0 === First_Order_Offer::settings()['coupon_id'] && 'publish' === get_post_status( $own ) );

		// ---------- 母版店导出 ----------
		$apply( $items, 6 );
		$export = apply_filters( 'pc_series_export_campaigns', array() );
		$mine   = array();
		foreach ( $export as $row ) {
			if ( false !== strpos( wp_json_encode( $row ), $tag ) || false !== stripos( wp_json_encode( $row ), $tag ) ) {
				$mine[ $row['kind'] . '/' . $row['key'] ] = $row;
			}
		}
		$check( '导出：满减（商品换回商品键）、两张优惠码、运费折扣、首单、弹窗都在，外形是 kind / key / active / config', isset( $mine['promotion/bf'], $mine['coupon/welcome'], $mine['coupon/save10'], $mine['ship_discount/half'], $mine['first_order/main'], $mine['exit_intent/main'] ) && array( $tag . '-a', $tag . '-b' ) === $mine['promotion/bf']['config']['products'] && true === $mine['promotion/bf']['active'] && $welcome === $mine['coupon/welcome']['config']['code'] && $welcome === $mine['first_order/main']['config']['coupon'] );
		$plan = Series_Campaigns::plan( array_values( $mine ) );
		$check( '导出的内容可以原样再下发（检查不出不合规）', 0 === $plan['errors'] );
	} catch ( \Throwable $e ) {
		$check( '运行中出错：' . get_class( $e ) . '：' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine(), false );
	} finally {
		foreach ( array_diff( $marked(), $before_marked ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( $posts as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( $terms as $id ) {
			wp_delete_term( (int) $id, 'product_cat' );
		}
		foreach ( $saved as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
		WC()->cart->empty_cart();
	}
	printf( "RESULT %d 项检查：%s\n", $checks, $failed ? count( $failed ) . ' 项不通过' : '全部通过' );
	echo "临时数据已删除，选项已恢复。\n";
	exit( $failed ? 1 : 0 );
} )();
