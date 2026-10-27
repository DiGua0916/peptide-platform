<?php
/**
 * 营销活动（MARKETING-01 / PROMO-02 / COUPON-02 / SHIPPING-DISCOUNT-01）冒烟测试：
 * 满减活动的 7 种优惠类型、上不封顶、商品分配优先级、冲突检查；优惠码的叠加策略、开始时间、门槛、全局首单、后台保存；
 * 运费折扣的阶段、会员日、指定顾客、取最低运费；菜单和旧地址跳转；三个页面能打开。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-marketing.php
 * 测试建的商品、分类、活动、优惠码、订单和运费折扣在结束时彻底删除，购物车清空，顾客邮箱和运费折扣设置恢复原样。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Coupons_Page;
use PeptideCore\Admin\Marketing;
use PeptideCore\Admin\Promotions_Page;
use PeptideCore\Admin\Shipping_Discounts_Page;
use PeptideCore\Coupon_Rules;
use PeptideCore\Promotions;
use PeptideCore\Shipping\Discounts;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$made_posts = array();
$made_terms = array();
$made_order = array();
$had_disc   = get_option( Discounts::OPTION, null );
$had_page   = Promotions::page_id();
$had_cpage  = \PeptideCore\Coupons::page_id();

// 测试商品：贵的 $30、便宜的 $10，放在一个测试专辑里；另一个 $20 的商品不在专辑里。
$term         = wp_insert_term( 'PC smoke marketing ' . wp_rand(), 'product_cat' );
$cat          = (int) $term['term_id'];
$made_terms[] = $cat;
$product      = static function ( string $name, float $price, array $cats ) use ( &$made_posts ): int {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( (string) $price );
	$p->set_status( 'private' );
	$p->set_category_ids( $cats );
	$id           = $p->save();
	$made_posts[] = $id;
	return $id;
};
$big   = $product( 'PC mk Big', 30, array( $cat ) );
$small = $product( 'PC mk Small', 10, array( $cat ) );
$other = $product( 'PC mk Other', 20, array() );

$promo = static function ( string $title, array $raw, string $status = 'publish' ) use ( &$made_posts ): int {
	$id           = wp_insert_post( array( 'post_type' => Promotions::CPT, 'post_status' => $status, 'post_title' => $title ) );
	$made_posts[] = $id;
	update_post_meta( $id, Promotions::META, Promotions::clean( $raw )[0] );
	return $id;
};
$off = static function ( int $id ) {
	wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
};

wc_load_cart();
$cart = WC()->cart;
$fill = static function ( array $items ) use ( $cart ) {
	$cart->empty_cart();
	foreach ( $items as $pid => $qty ) {
		$cart->add_to_cart( $pid, $qty );
	}
	$cart->calculate_totals();
};
$discount_of = static function ( int $id ) use ( $cart ): float {
	foreach ( Promotions::progress( $cart )['applied_all'] ?? array() as $a ) {
		if ( $a['id'] === $id ) {
			return (float) $a['discount'];
		}
	}
	return 0.0;
};

// 1. 规则校验。
[ , $e ] = Promotions::clean( array( 'type' => 'nth_percent', 'tiers' => array( array( 'min' => 1, 'value' => 50 ) ) ) );
$ok( 'Xth item: X must be at least 2', (bool) $e );
[ , $e ] = Promotions::clean( array( 'type' => 'buy_x_free_y', 'tiers' => array( array( 'min' => 3, 'value' => 3 ) ) ) );
$ok( 'buy X get Y free: Y must be smaller than X', (bool) $e );
[ , $e ] = Promotions::clean( array( 'type' => 'bundle_price', 'tiers' => array( array( 'min' => 1, 'value' => 20 ) ) ) );
$ok( 'bundle price needs at least 2 items', (bool) $e );
[ $c, $e ] = Promotions::clean( array( 'type' => 'spend_amount', 'unlimited' => true, 'tiers' => array( array( 'min' => 50, 'value' => 5 ), array( 'min' => 100, 'value' => 12 ) ) ) );
$ok( 'no upper limit works with one tier only', (bool) $e && 1 === count( $c['tiers'] ) );
[ $c, $e ] = Promotions::clean( array( 'type' => 'spend_percent', 'unlimited' => true, 'tiers' => array( array( 'min' => 50, 'value' => 10 ) ) ) );
$ok( 'percentage types cannot repeat', ! $e && false === $c['unlimited'] && 'spend' === $c['basis'] && 'percent' === $c['reward'] );
$ok( 'old records without a type keep working', 'qty_percent' === Promotions::config( $promo( 'SMOKE mk legacy', array( 'basis' => 'quantity', 'reward' => 'percent', 'tiers' => array( array( 'min' => 2, 'value' => 10 ) ) ), 'draft' ) )['type'] );

// 2. 7 种优惠类型（都只针对 Big + Small 所在的专辑）。购物车：Big ×2（$30）+ Small ×2（$10）= 4 件 $80。
$scope = array( 'scope' => 'categories', 'categories' => array( $cat ) );
$cases = array(
	'spend & save: spend $50 save $5'            => array( array( 'type' => 'spend_amount', 'tiers' => array( array( 'min' => 50, 'value' => 5 ) ) ), 5.0 ),
	'spend & save, no upper limit: every $30'    => array( array( 'type' => 'spend_amount', 'unlimited' => true, 'tiers' => array( array( 'min' => 30, 'value' => 4 ) ) ), 8.0 ),
	'spend & get % off: highest tier reached'    => array( array( 'type' => 'spend_percent', 'tiers' => array( array( 'min' => 50, 'value' => 10 ), array( 'min' => 80, 'value' => 20 ) ) ), 16.0 ),
	'buy items & save: buy 3 save $7'            => array( array( 'type' => 'qty_amount', 'tiers' => array( array( 'min' => 3, 'value' => 7 ) ) ), 7.0 ),
	'buy items & get % off: buy 4 get 25%'       => array( array( 'type' => 'qty_percent', 'tiers' => array( array( 'min' => 4, 'value' => 25 ) ) ), 20.0 ),
	'Xth item: 2nd 50%, 3rd 100% (cumulative)'   => array( array( 'type' => 'nth_percent', 'tiers' => array( array( 'min' => 2, 'value' => 50 ), array( 'min' => 3, 'value' => 100 ) ) ), 25.0 ),
	'Xth item repeating: every 2nd item 50% off' => array( array( 'type' => 'nth_percent', 'unlimited' => true, 'tiers' => array( array( 'min' => 2, 'value' => 50 ) ) ), 20.0 ),
	'bundle: the 3 dearest items for $50'        => array( array( 'type' => 'bundle_price', 'tiers' => array( array( 'min' => 3, 'value' => 50 ) ) ), 20.0 ),
	'bundle repeating: every 2 items for $25'    => array( array( 'type' => 'bundle_price', 'unlimited' => true, 'tiers' => array( array( 'min' => 2, 'value' => 25 ) ) ), 35.0 ),
	'buy 4, the cheapest 2 free'                 => array( array( 'type' => 'buy_x_free_y', 'tiers' => array( array( 'min' => 4, 'value' => 2 ) ) ), 20.0 ),
	'buy 2 get 1 free, repeating'                => array( array( 'type' => 'buy_x_free_y', 'unlimited' => true, 'tiers' => array( array( 'min' => 2, 'value' => 1 ) ) ), 40.0 ),
);
$fill( array( $big => 2, $small => 2 ) );
foreach ( $cases as $label => [ $raw, $expect ] ) {
	$id  = $promo( 'SMOKE mk ' . $label, $raw + $scope );
	$got = $discount_of( $id );
	$ok( $label . ' = $' . $expect . ( abs( $got - $expect ) < 0.011 ? '' : ' (got $' . $got . ')' ), abs( $got - $expect ) < 0.011 );
	$off( $id );
}
// 上不封顶的下一档提示：每满 $30 减 $4，现在 $80，再加 $10 到 $90。
$rep = $promo( 'SMOKE mk repeat hint', array( 'type' => 'spend_amount', 'unlimited' => true, 'tiers' => array( array( 'min' => 30, 'value' => 4 ) ) ) + $scope );
$p   = Promotions::progress( $cart );
$ok( 'no upper limit: next hint is the next multiple', $p['next'] && $rep === $p['next']['id'] && abs( $p['next']['remaining'] - 10 ) < 0.011 );
$off( $rep );
$five = $promo( 'SMOKE mk five', array( 'type' => 'qty_amount', 'tiers' => array( array( 'min' => 5, 'value' => 6 ) ) ) + $scope );
$p    = Promotions::progress( $cart );
$ok( 'item hint reads “Add 1 more item to get …”', $p['next'] && str_starts_with( $p['next']['message'], 'Add 1 more item to get' ) );
$off( $five );

// 3. 商品分配：指定商品 > 专辑 > 全部商品；没达到门槛也不改用别的活动；不同商品的活动优惠相加。
$p_all  = $promo( 'SMOKE mk all', array( 'type' => 'spend_amount', 'tiers' => array( array( 'min' => 10, 'value' => 3 ) ) ) );
$p_cat  = $promo( 'SMOKE mk cat', array( 'type' => 'qty_amount', 'tiers' => array( array( 'min' => 1, 'value' => 2 ) ) ) + $scope );
$p_prod = $promo( 'SMOKE mk prod', array( 'type' => 'spend_amount', 'tiers' => array( array( 'min' => 500, 'value' => 50 ) ), 'scope' => 'products', 'products' => array( $big ) ) );
$fill( array( $big => 1, $small => 1, $other => 1 ) );
$assign = Promotions::assign( $cart, Promotions::usable_ids( $cart ) );
$keys   = array();
foreach ( $cart->get_cart() as $k => $item ) {
	$keys[ $item['product_id'] ] = (string) $k;
}
$ok( 'product promotion owns Big, collection promotion owns Small, all-products owns Other', array( $keys[ $big ] ) === ( $assign[ $p_prod ] ?? null ) && array( $keys[ $small ] ) === ( $assign[ $p_cat ] ?? null ) && array( $keys[ $other ] ) === ( $assign[ $p_all ] ?? null ) );
$p    = Promotions::progress( $cart );
$fees = array_filter( array_keys( $cart->get_fees() ), static fn( $k ) => str_starts_with( (string) $k, 'pc-promo-' ) );
$ok( 'Big below its own threshold gets nothing (no fallback); the others add up', 0.0 === $discount_of( $p_prod ) && 2.0 === $discount_of( $p_cat ) && 3.0 === $discount_of( $p_all ) && 5.0 === (float) $p['discount'] && 2 === count( $fees ) );
$off( $p_prod );
$cart->calculate_totals();
$assign = Promotions::assign( $cart, Promotions::usable_ids( $cart ) );
$ok( 'without the product promotion, Big joins the collection promotion', in_array( $keys[ $big ], $assign[ $p_cat ] ?? array(), true ) && 2.0 === $discount_of( $p_cat ) );
$newer = $promo( 'SMOKE mk cat newer', array( 'type' => 'qty_amount', 'tiers' => array( array( 'min' => 1, 'value' => 1 ) ) ) + $scope );
$cart->calculate_totals();
$ok( 'two collection promotions: the most recently created one wins', 1.0 === $discount_of( $newer ) && 0.0 === $discount_of( $p_cat ) );
$off( $newer );
update_post_meta( $p_cat, Promotions::META, array_merge( Promotions::config( $p_cat ), array( 'public' => true ) ) );
$ok( 'product page shows the one promotion the product joins', array( $p_cat ) === array_column( Promotions::for_product( wc_get_product( $small ) ), 'id' ) );

// 4. 冲突检查。
$now  = time();
$base = array( 'type' => 'spend_amount', 'tiers' => array( array( 'min' => 10, 'value' => 1 ) ) );
$ok( 'another all-products promotion at the same time is refused', (bool) Promotions::conflicts( Promotions::clean( $base + array( 'start' => $now ) )[0] ) );
update_post_meta( $p_all, Promotions::META, array_merge( Promotions::config( $p_all ), array( 'start' => $now, 'end' => $now + DAY_IN_SECONDS ) ) );
$ok( 'an all-products promotion after it has ended is fine', ! Promotions::conflicts( Promotions::clean( $base + array( 'start' => $now + 2 * DAY_IN_SECONDS ) )[0] ) );
update_post_meta( $p_cat, Promotions::META, array_merge( Promotions::config( $p_cat ), array( 'start' => 0, 'end' => 0 ) ) );
$ok( 'the same collection in two promotions at the same time is refused', (bool) Promotions::conflicts( Promotions::clean( $base + $scope + array( 'start' => $now ) )[0] ) );
$ok( 'a product of that collection in a product promotion is allowed (priority decides)', ! Promotions::conflicts( Promotions::clean( $base + array( 'scope' => 'products', 'products' => array( $small ), 'start' => $now ) )[0] ) );
wp_update_post( array( 'ID' => $p_prod, 'post_status' => 'publish' ) );
$ok( 'the same product in two product promotions is refused', (bool) Promotions::conflicts( Promotions::clean( $base + array( 'scope' => 'products', 'products' => array( $big ), 'start' => $now ) )[0], 0 ) );
$off( $p_prod );
$dup = $promo( 'SMOKE mk dup', $base + $scope, 'draft' );
$ok( 'enabling a conflicting promotion is refused and it stays off', (bool) Promotions::set_enabled( $dup, true ) && 'draft' === get_post_status( $dup ) );
[ $nid, $errors ] = Promotions::store( 0, '', $base, true );
$ok( 'a promotion needs a name', 0 === $nid && $errors );
[ $title, $raw, $terr ] = Promotions_Page::from_form( array( 'name' => 'SMOKE mk form', 'type' => 'qty_amount', 'tiers' => array( array( 'min' => '2', 'value' => '3' ) ), 'time_mode' => 'range', 'start' => '2026-01-01T00:00:00', 'end' => '', 'scope' => 'products', 'products' => array( $other ) ) );
$ok( 'editor form: a time period needs an end time', 'SMOKE mk form' === $title && $terr );
[ , $raw, $terr ] = Promotions_Page::from_form( array( 'name' => 'x', 'type' => 'qty_amount', 'tiers' => array( array( 'min' => '2', 'value' => '3' ) ), 'time_mode' => 'long', 'start' => wp_date( 'Y-m-d\TH:i:s', $now ), 'scope' => 'products', 'products' => array( $other ) ) );
$ok( 'editor form: long-term has no end, start in store time', ! $terr && 0 === $raw['end'] && abs( $raw['start'] - $now ) <= 1 && true === $raw['public'] );

// 5. 优惠码：叠加策略。购物车 Small ×2 = $20，专辑活动（$p_cat 满 1 件减 $2，每件一次）给 $2。
$coupon = static function ( string $code, array $meta = array(), array $props = array() ) use ( &$made_posts ): WC_Coupon {
	$c = new WC_Coupon();
	$c->set_code( $code );
	$c->set_props( array_merge( array( 'discount_type' => 'percent', 'amount' => 10 ), $props ) );
	$id           = $c->save();
	$made_posts[] = $id;
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return new WC_Coupon( $id );
};
$valid = static function ( WC_Coupon $c ) use ( $cart ) {
	$r = ( new WC_Discounts( $cart ) )->is_coupon_valid( $c );
	return true === $r ? true : ( is_wp_error( $r ) ? $r->get_error_message() : false );
};
$fill( array( $small => 2 ) );
$ok( 'cart has the collection promotion', 2.0 === $discount_of( $p_cat ) );
$no_stack = $coupon( 'smkmk-nostack', array( Coupon_Rules::STRATEGY_META => 'no_stack' ) );
$msg      = $valid( $no_stack );
$ok( 'do not combine: refused while a promotion applies, with the promotion name', is_string( $msg ) && str_contains( $msg, 'SMOKE mk cat' ) );
$stack = $coupon( 'smkmk-stack', array( Coupon_Rules::STRATEGY_META => 'stack' ) );
$cart->apply_coupon( 'smkmk-stack' );
$cart->calculate_totals();
$ok( 'combine: coupon and promotion both apply', $cart->has_discount( 'smkmk-stack' ) && 2.0 === $discount_of( $p_cat ) && $cart->get_discount_total() > 0 );
$cart->remove_coupons();
$replace = $coupon( 'smkmk-replace', array( Coupon_Rules::STRATEGY_META => 'replace' ) );
$cart->apply_coupon( 'smkmk-replace' );
$cart->calculate_totals();
$ok( 'replace: with the code the promotions stop', $cart->has_discount( 'smkmk-replace' ) && null === Promotions::progress( $cart )['applied'] && ! array_filter( array_keys( $cart->get_fees() ), static fn( $k ) => str_starts_with( (string) $k, 'pc-promo-' ) ) );
$ok( 'do not combine is fine when a replace code already stops the promotions', true === $valid( $no_stack ) );
$cart->remove_coupons();
$ok( 'coupons without a strategy behave as before (combine)', 'stack' === Coupon_Rules::strategy( $coupon( 'smkmk-legacy' ) ) );

// 6. 优惠码：开始时间、按适用商品计算的门槛、全局首单。
$off( $p_cat );
$off( $p_all );
$fill( array( $small => 2, $other => 1 ) );
$later = $coupon( 'smkmk-later', array( Coupon_Rules::START_META => time() + HOUR_IN_SECONDS ) );
$ok( 'code before its start time is refused', is_string( $valid( $later ) ) && 'upcoming' === Coupon_Rules::status( $later ) && 'upcoming' === \PeptideCore\Coupons::unusable_reason( $later ) );
$qty = $coupon( 'smkmk-qty', array( Coupon_Rules::MIN_QTY_META => 3 ), array( 'product_categories' => array( $cat ) ) );
$ok( 'minimum items only counts eligible items (2 of 3)', is_string( $valid( $qty ) ) );
$cart->add_to_cart( $small, 1 );
$cart->calculate_totals();
$ok( 'minimum items reached', true === $valid( $qty ) );
$spend = $coupon( 'smkmk-spend', array( Coupon_Rules::MIN_SPEND_META => 40 ), array( 'product_categories' => array( $cat ) ) );
$ok( 'minimum amount only counts eligible items ($30 of $50)', is_string( $valid( $spend ) ) );
$first_email = 'pc-mk-first-' . wp_rand() . '@example.com';
$order       = wc_create_order();
$order->set_billing_email( $first_email );
$order->set_status( 'completed' );
$order->save();
$made_order[] = $order->get_id();
$ok( 'has ordered: by checkout email', Coupon_Rules::has_ordered( $first_email, 0 ) && ! Coupon_Rules::has_ordered( 'nobody-' . wp_rand() . '@example.com', 0 ) );
$first    = $coupon( 'smkmk-first', array( Coupon_Rules::FIRST_META => 'yes' ) );
$customer = WC()->customer;
$saved_em = $customer->get_billing_email();
wp_set_current_user( 0 );
$customer->set_billing_email( $first_email );
$ok( 'first order only: refused for a customer who has ordered', is_string( $valid( $first ) ) );
$customer->set_billing_email( 'pc-mk-new-' . wp_rand() . '@example.com' );
$ok( 'first order only: a new customer can use it', true === $valid( $first ) );
$customer->set_billing_email( $saved_em );
wp_set_current_user( $admin->ID );
$ok( 'rule text: condition and value', str_contains( Coupon_Rules::rule_text( $spend ), '40' ) && str_contains( Coupon_Rules::rule_text( $stack ), '10' ) );

// 7. 优惠码后台保存。
$form = array(
	'name'        => 'SMOKE mk coupon',
	'code'        => 'SMKMK-SAVE',
	'strategy'    => 'replace',
	'time_mode'   => 'range',
	'start'       => wp_date( 'Y-m-d\TH:i:s', $now - 60 ),
	'end'         => wp_date( 'Y-m-d\TH:i:s', $now + DAY_IN_SECONDS ),
	'limit_mode'  => 'some',
	'usage_limit' => '5',
	'user_mode'   => 'first',
	'public'      => 'no',
	'condition'   => 'qty',
	'cond_value'  => '2',
	'dtype'       => 'fixed_cart',
	'amount'      => '7.5',
	'customers'   => 'emails',
	'emails'      => "A@Example.com\nbad-email\nb@example.com",
	'scope'       => 'categories',
	'categories'  => array( $cat ),
	'sort'        => 'price_asc',
);
[ , $errors ] = Coupons_Page::store( 0, array_merge( $form, array( 'code' => 'bad code!' ) ) );
$ok( 'code with spaces or symbols is refused', (bool) $errors );
[ , $errors ] = Coupons_Page::store( 0, array_merge( $form, array( 'code' => 'smkmk-stack' ) ) );
$ok( 'code used by another coupon is refused', (bool) $errors );
[ , $errors ] = Coupons_Page::store( 0, array_merge( $form, array( 'dtype' => 'percent', 'amount' => '120' ) ) );
$ok( 'percentage above 100 is refused', (bool) $errors );
[ $cid, $errors ] = Coupons_Page::store( 0, $form );
$made_posts[]     = $cid;
$saved            = new WC_Coupon( $cid );
$ok( 'coupon saved with WooCommerce fields', ! $errors && 'SMKMK-SAVE' === strtoupper( $saved->get_code() ) && 'SMOKE mk coupon' === $saved->get_description() && 'fixed_cart' === $saved->get_discount_type() && 7.5 === (float) $saved->get_amount() && 5 === $saved->get_usage_limit() && 1 === $saved->get_usage_limit_per_user() && array( 'a@example.com', 'b@example.com' ) === $saved->get_email_restrictions() && array( $cat ) === $saved->get_product_categories() && $saved->get_date_expires() && abs( $saved->get_date_expires()->getTimestamp() - ( $now + DAY_IN_SECONDS ) ) <= 1 );
$ok( 'coupon saved with the extra rules', 'replace' === Coupon_Rules::strategy( $saved ) && 'yes' === get_post_meta( $cid, Coupon_Rules::FIRST_META, true ) && 2 === (int) get_post_meta( $cid, Coupon_Rules::MIN_QTY_META, true ) && 'price_asc' === get_post_meta( $cid, Coupon_Rules::SORT_META, true ) && 'active' === Coupon_Rules::status( $saved ) && 'publish' === get_post_status( $cid ) );
[ $cid2, $errors ] = Coupons_Page::store( $cid, array_merge( $form, array( 'time_mode' => 'long', 'limit_mode' => 'none', 'user_mode' => 'none', 'customers' => 'all', 'scope' => 'all', 'condition' => 'none' ) ) );
$saved             = new WC_Coupon( $cid );
$ok( 'editing clears the limits that were switched off', ! $errors && $cid === $cid2 && null === $saved->get_date_expires() && 0 === $saved->get_usage_limit() && 0 === $saved->get_usage_limit_per_user() && array() === $saved->get_email_restrictions() && array() === $saved->get_product_categories() && 'no' === get_post_meta( $cid, Coupon_Rules::FIRST_META, true ) );
wp_update_post( array( 'ID' => $cid, 'post_status' => 'draft' ) );
$ok( 'disabled coupon shows as disabled', 'off' === Coupon_Rules::status( new WC_Coupon( $cid ) ) );

// 8. 运费折扣。
[ , $e ] = Discounts::normalize( array( 'name' => '', 'value' => 5 ) );
$ok( 'shipping discount needs a name', (bool) $e );
[ , $e ] = Discounts::normalize( array( 'name' => 'x', 'kind' => 'percent', 'value' => 120 ) );
$ok( 'shipping percentage at most 100', (bool) $e );
[ , $e ] = Discounts::normalize( array( 'name' => 'x', 'mode' => 'stages', 'kind' => 'amount', 'stages' => array( array( 'start' => $now, 'end' => $now + 100, 'value' => 1 ), array( 'start' => $now + 50, 'end' => $now + 200, 'value' => 2 ) ) ) );
$ok( 'stages must not overlap', (bool) $e );
[ , $e ] = Discounts::normalize( array( 'name' => 'x', 'value' => 2, 'member_day' => true, 'member_days' => array() ) );
$ok( 'member day needs weekdays', (bool) $e );
[ $st, $e ] = Discounts::normalize( array( 'name' => 'SMOKE stages', 'mode' => 'stages', 'kind' => 'percent', 'stages' => array( array( 'start' => $now + 100, 'end' => $now + 200, 'value' => 20 ), array( 'start' => $now - 100, 'end' => $now + 50, 'value' => 50 ) ) ) );
$ok( 'stages sorted, overall time from first start to last end', ! $e && $now - 100 === $st['start'] && $now + 200 === $st['end'] && 50.0 === $st['stages'][0]['value'] );
$ok( 'stage value now, in a gap and after', 50.0 === Discounts::value_now( $st, $now ) && null === Discounts::value_now( $st, $now + 70 ) && 20.0 === Discounts::value_now( $st, $now + 150 ) && 'ended' === Discounts::status( $st, $now + 300 ) );
$today = (int) wp_date( 'N', $now );
[ $md ] = Discounts::normalize( array( 'name' => 'm', 'value' => 1, 'member_day' => true, 'member_days' => array( $today ) ) );
[ $md2 ] = Discounts::normalize( array( 'name' => 'm', 'value' => 1, 'member_day' => true, 'member_days' => array( $today % 7 + 1 ) ) );
$ok( 'member day: only signed-in customers on the chosen weekday', Discounts::applies_to( $md, '', 5, $now ) && ! Discounts::applies_to( $md, '', 0, $now ) && ! Discounts::applies_to( $md2, '', 5, $now ) );
[ $em ] = Discounts::normalize( array( 'name' => 'e', 'value' => 1, 'customers' => 'emails', 'emails' => "VIP@example.com" ) );
$ok( 'specific customers: by email, any letter case', Discounts::applies_to( $em, 'vip@example.com', 0, $now ) && ! Discounts::applies_to( $em, 'other@example.com', 0, $now ) );

update_option( Discounts::OPTION, array(), false );
$d1 = Discounts::save( Discounts::normalize( array( 'name' => 'SMOKE ship $3', 'kind' => 'amount', 'value' => 3, 'start' => $now - 60 ) )[0] );
$d2 = Discounts::save( Discounts::normalize( array( 'name' => 'SMOKE ship 50%', 'kind' => 'percent', 'value' => 50, 'start' => $now - 60 ) )[0] );
$d3 = Discounts::save( Discounts::normalize( array( 'name' => 'SMOKE ship VIP free', 'kind' => 'percent', 'value' => 100, 'start' => $now - 60, 'customers' => 'emails', 'emails' => 'vip-' . wp_rand() . '@example.com' ) )[0] );
$rate = static function ( string $id, float $cost, float $tax ): WC_Shipping_Rate {
	return new WC_Shipping_Rate( $id, 'Test ' . $id, $cost, array( 1 => $tax ), 'pc_plans' );
};
wp_set_current_user( 0 );
$customer->set_billing_email( 'someone-' . wp_rand() . '@example.com' );
$rates = Discounts::apply( array( 'a' => $rate( 'a', 10, 1 ), 'b' => $rate( 'b', 4, 0.4 ), 'c' => $rate( 'c', 0, 0 ) ), array() );
$ok( 'each rate gets the lowest price: $10 → $5 (50%), $4 → $1 ($3 off), free stays free', 5.0 === (float) $rates['a']->get_cost() && 1.0 === (float) $rates['b']->get_cost() && 0.0 === (float) $rates['c']->get_cost() );
$meta = $rates['a']->get_meta_data();
$ok( 'taxes follow and the order line records the discount', 0.5 === (float) $rates['a']->get_taxes()[1] && 'SMOKE ship 50%' === ( $meta[ Discounts::META_NAME ] ?? '' ) && '10.00' === ( $meta[ Discounts::META_COST ] ?? '' ) );
$sig = Discounts::tag_packages( array( array( 'contents' => array() ) ) )[0]['pc_ship_discounts'];
$ok( 'shipping cache key lists the discounts that apply now', str_contains( $sig, $d1['id'] . ':3' ) && str_contains( $sig, $d2['id'] . ':50' ) && ! str_contains( $sig, $d3['id'] . ':' ) );
Discounts::set_enabled( $d2['id'], false );
$rates = Discounts::apply( array( 'a' => $rate( 'a', 10, 1 ) ), array() );
$ok( 'disabled discount no longer applies', 7.0 === (float) $rates['a']->get_cost() && 'off' === Discounts::status( Discounts::get( $d2['id'] ) ) );
// 和结账一样算一次运费：在运费方式给出运费之后（这里补一个 $12 的运费），折扣在 woocommerce_package_rates 里生效。
$fake = static function ( $rates ) {
	$rates['pc_plans:smoke'] = new WC_Shipping_Rate( 'pc_plans:smoke', 'Smoke rate', 12, array(), 'pc_plans' );
	return $rates;
};
add_filter( 'woocommerce_package_rates', $fake, 50 );
$saved_ship = array( $customer->get_shipping_country(), $customer->get_shipping_state(), $customer->get_shipping_postcode() );
$customer->set_shipping_location( 'US', 'CA', '90001' );
$customer->set_calculated_shipping( true );
wp_set_current_user( $admin->ID ); // 测试商品是私密商品，只有管理员能加购。
$fill( array( $other => 1 ) );
$cost = static function () {
	foreach ( WC()->shipping()->get_packages() as $pkg ) {
		if ( isset( $pkg['rates']['pc_plans:smoke'] ) ) {
			return (float) $pkg['rates']['pc_plans:smoke']->get_cost();
		}
	}
	return -1.0;
};
$ok( 'checkout calculation: $12 shipping with $3 off → $9', 9.0 === $cost() );
$customer->set_billing_email( Discounts::get( $d3['id'] )['emails'][0] );
$cart->calculate_totals();
$ok( 'checkout calculation: the VIP customer gets free shipping (cache key changed with the email)', 0.0 === $cost() );
remove_filter( 'woocommerce_package_rates', $fake, 50 );
$customer->set_shipping_location( $saved_ship[0], $saved_ship[1], $saved_ship[2] );
$customer->set_billing_email( $saved_em );
wp_set_current_user( $admin->ID );
[ $raw, $terr ] = Shipping_Discounts_Page::from_form( array( 'name' => 'f', 'mode' => 'stages', 'kind' => 'amount', 'stages' => array( array( 'start' => wp_date( 'Y-m-d\TH:i:s', $now ), 'end' => wp_date( 'Y-m-d\TH:i:s', $now + 3600 ), 'value' => '2' ) ), 'customers' => 'all' ) );
$ok( 'editor form: stages read in store time, no time error for stages', ! $terr && abs( $raw['stages'][0]['start'] - $now ) <= 1 && abs( $raw['stages'][0]['end'] - $now - 3600 ) <= 1 );

// 9. 旧地址跳转、页面能打开（菜单见 smoke-admin-menus）。
$where = null;
$catch = static function ( $location ) use ( &$where ) {
	$where = $location;
	throw new RuntimeException( 'redirect' );
};
add_filter( 'wp_redirect', $catch, 1 );
$try = static function ( string $page, array $get ) use ( &$where ) {
	global $pagenow;
	$where   = null;
	$pagenow = $page;
	$_GET    = $get;
	try {
		Marketing::redirect_old_screens();
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	$_GET = array();
	return (string) $where;
};
$ok( 'old coupon list goes to the coupon page', str_contains( $try( 'edit.php', array( 'post_type' => 'shop_coupon' ) ), 'page=' . Coupons_Page::SLUG ) );
$ok( 'old coupon editor goes to the new editor', str_contains( $try( 'post.php', array( 'post' => $cid, 'action' => 'edit' ) ), 'edit=' . $cid ) );
$ok( 'old promotion list and WooCommerce marketing overview go to the promotions page', str_contains( $try( 'edit.php', array( 'post_type' => Promotions::CPT ) ), 'page=' . Promotions_Page::SLUG ) && str_contains( $try( 'admin.php', array( 'page' => 'wc-admin', 'path' => '/marketing' ) ), 'page=' . Promotions_Page::SLUG ) );
$ok( 'classic coupon editor still opens with pc_classic=1', '' === $try( 'post.php', array( 'post' => $cid, 'action' => 'edit', 'pc_classic' => '1' ) ) );
remove_filter( 'wp_redirect', $catch, 1 );
$pagenow = 'index.php';

$render = static function ( string $class, array $get ): string {
	$_GET = $get;
	ob_start();
	$class::render();
	$_GET = array();
	return (string) ob_get_clean();
};
$html = $render( Promotions_Page::class, array() );
$ok( 'promotions list shows a promotion with its rule and status', str_contains( $html, 'SMOKE mk cat' ) && str_contains( $html, 'pc-mk-badge' ) && str_contains( $html, 'pc-mk-pager' ) );
$html = $render( Promotions_Page::class, array( 'tab' => 'active', 's' => 'repeat hint' ) );
$ok( 'promotions list filters by tab and name', ! str_contains( $html, 'SMOKE mk repeat hint' ) );
$html = $render( Promotions_Page::class, array( 'edit' => $p_cat ) );
$ok( 'promotion editor shows its tiers, scope and the usage rules', str_contains( $html, 'name="tiers[0][min]"' ) && str_contains( $html, 'value="' . $cat . '" checked' ) && str_contains( $html, 'pc-mk-rules' ) );
$html = $render( Coupons_Page::class, array( 'edit' => $cid ) );
$ok( 'coupon editor shows the saved code and the classic editor link', str_contains( $html, 'value="SMKMK-SAVE"' ) && str_contains( $html, 'pc_classic=1' ) );
$html = $render( Coupons_Page::class, array() );
$ok( 'coupon list shows used / left', str_contains( $html, 'SMKMK-SAVE' ) && str_contains( $html, '0/' ) );
$html = $render( Shipping_Discounts_Page::class, array() );
$ok( 'shipping discount list shows the discounts and the lowest-fee note', str_contains( $html, 'SMOKE ship $3' ) && str_contains( $html, 'pc-mk-note' ) );
$html = $render( Shipping_Discounts_Page::class, array( 'new' => 1 ) );
$ok( 'shipping discount editor has stages and member days', str_contains( $html, 'pc-mk-stage-list' ) && str_contains( $html, 'member_days[]' ) );

// 10. 清理。
$cart->remove_coupons();
$cart->empty_cart();
foreach ( $made_order as $oid ) {
	$o = wc_get_order( $oid );
	if ( $o ) {
		$o->delete( true );
	}
}
foreach ( $made_posts as $id ) {
	if ( 'product' === get_post_type( $id ) ) {
		$pr = wc_get_product( $id );
		if ( $pr ) {
			$pr->delete( true );
		}
	} else {
		wp_delete_post( $id, true );
	}
}
foreach ( $made_terms as $tid ) {
	wp_delete_term( $tid, 'product_cat' );
}
if ( null === $had_disc ) {
	delete_option( Discounts::OPTION );
} else {
	update_option( Discounts::OPTION, $had_disc, false );
}
if ( ! $had_page && Promotions::page_id() ) {
	wp_delete_post( Promotions::page_id(), true );
	delete_option( Promotions::PAGE_OPTION );
}
if ( ! $had_cpage && \PeptideCore\Coupons::page_id() ) {
	wp_delete_post( \PeptideCore\Coupons::page_id(), true );
	delete_option( \PeptideCore\Coupons::PAGE_OPTION );
}
wc_clear_notices();
$ok( 'test data deleted', ! array_filter( array_map( 'get_post', $made_posts ) ) && ! term_exists( $cat, 'product_cat' ) );
