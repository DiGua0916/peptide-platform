<?php
/**
 * 满减活动（PROMO-01 / PROMO-02）冒烟测试：规则校验、状态、购物车优惠（每个商品一个活动、和优惠码的关系、排除促销商品）、
 * 进度提示、订单费用行、活动页面、菜单链接。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-promotions.php
 * 测试创建的活动、优惠码、菜单和（原来没有的）活动页面在结束时彻底删除，购物车清空。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Menus;
use PeptideCore\Promotions;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$had_page = Promotions::page_id();
$made     = array();

// 1. 规则校验。
[ $c, $e ] = Promotions::clean( array( 'basis' => 'spend', 'reward' => 'amount', 'tiers' => array( array( 'min' => 200, 'value' => 25 ), array( 'min' => 100, 'value' => 10 ), array( 'min' => '', 'value' => '' ) ) ) );
$ok( 'tiers sorted, empty rows ignored', ! $e && array( 100.0, 200.0 ) === array_column( $c['tiers'], 'min' ) );
[ , $e ] = Promotions::clean( array( 'basis' => 'spend', 'reward' => 'amount', 'tiers' => array( array( 'min' => 50, 'value' => 60 ) ) ) );
$ok( 'fixed discount must be below the spend threshold', (bool) $e );
[ , $e ] = Promotions::clean( array( 'reward' => 'percent', 'tiers' => array( array( 'min' => 50, 'value' => 100 ) ) ) );
$ok( 'percentage must be below 100', (bool) $e );
[ , $e ] = Promotions::clean( array( 'tiers' => array( array( 'min' => 50, 'value' => 5 ) ), 'start' => 2000, 'end' => 1000 ) );
$ok( 'end must be after start', (bool) $e );
[ , $e ] = Promotions::clean( array( 'tiers' => array( array( 'min' => 50, 'value' => 5 ) ), 'scope' => 'categories' ) );
$ok( 'category scope needs categories', (bool) $e );
[ , $e ] = Promotions::clean( array( 'tiers' => array( array( 'min' => 100, 'value' => 20 ), array( 'min' => 200, 'value' => 10 ) ) ) );
$ok( 'higher tier cannot give less', (bool) $e );
[ $c, $e ] = Promotions::clean( array( 'basis' => 'quantity', 'tiers' => array( array( 'min' => 2.6, 'value' => 5 ) ) ) );
$ok( 'item thresholds are whole numbers', ! $e && 3.0 === $c['tiers'][0]['min'] );

$make = static function ( string $title, array $raw, string $status = 'publish' ) use ( &$made ): int {
	$id     = wp_insert_post( array( 'post_type' => Promotions::CPT, 'post_status' => $status, 'post_title' => $title ) );
	$made[] = $id;
	update_post_meta( $id, Promotions::META, Promotions::clean( $raw )[0] );
	return $id;
};

// 找一个能购买的商品（演示数据的商品都带对照价，所以不要求“没有促销”）。
$product = null;
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => 50 ) ) as $p ) {
	if ( $p->is_purchasable() && $p->is_in_stock() && (float) $p->get_price() >= 5 ) {
		$product = $p;
		break;
	}
}
$ok( 'found a test product', (bool) $product );
if ( ! $product ) {
	return;
}
$price = (float) $product->get_price();
$cat   = wc_get_product_cat_ids( $product->get_id() )[0] ?? 0;

// 2. 状态。
$a = $make( 'SMOKE Spend save', array( 'basis' => 'spend', 'reward' => 'amount', 'tiers' => array( array( 'min' => round( $price * 2, 2 ), 'value' => 5 ), array( 'min' => round( $price * 4, 2 ), 'value' => 12 ) ), 'public' => true, 'text' => 'Test offer' ) );
$upcoming = $make( 'SMOKE Later', array( 'tiers' => array( array( 'min' => 10, 'value' => 1 ) ), 'start' => time() + DAY_IN_SECONDS, 'public' => true ) );
$ended    = $make( 'SMOKE Over', array( 'tiers' => array( array( 'min' => 10, 'value' => 1 ) ), 'end' => time() - 60, 'public' => true ) );
$draft    = $make( 'SMOKE Draft', array( 'tiers' => array( array( 'min' => 1, 'value' => 99 ) ) ), 'draft' );
$ok( 'status: running / scheduled / ended / off', 'active' === Promotions::status( $a ) && 'upcoming' === Promotions::status( $upcoming ) && 'ended' === Promotions::status( $ended ) && 'off' === Promotions::status( $draft ) );
$ok( 'only running promotions apply', in_array( $a, Promotions::active_ids(), true ) && ! array_intersect( array( $upcoming, $ended, $draft ), Promotions::active_ids() ) );

// 3. 购物车。
wc_load_cart();
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product->get_id(), 1 );
WC()->cart->calculate_totals();
$p = Promotions::progress();
$ok( 'below the first tier: nothing applied', null === $p['applied'] || $p['applied']['id'] !== $a );
$ok( 'next-tier message', $p['next'] && $a === $p['next']['id'] && str_contains( $p['next']['message'], 'more to get' ) && abs( $p['next']['remaining'] - $price ) < 0.011 );

WC()->cart->add_to_cart( $product->get_id(), 1 );
WC()->cart->calculate_totals();
$p    = Promotions::progress();
$fees = WC()->cart->get_fees();
$ok( 'first tier reached: $5 off', $p['applied'] && $a === $p['applied']['id'] && 5.0 === (float) $p['applied']['discount'] );
$ok( 'discount added as a negative fee', isset( $fees[ 'pc-promo-' . $a ] ) && -5.0 === (float) $fees[ 'pc-promo-' . $a ]->amount );
$ok( 'cart total reduced', abs( (float) WC()->cart->get_total( 'edit' ) - ( $price * 2 + (float) WC()->cart->get_shipping_total() + (float) WC()->cart->get_total_tax() - 5 ) ) < 0.02 );
$ok( 'next tier still offered', $p['next'] && $a === $p['next']['id'] && str_contains( $p['next']['reward_text'], '12' ) );

// 每个商品只参加一个活动：指定专辑的活动优先于全部商品的活动（没有分类时两个都是全部商品，用创建时间最近的）。
$b = $make( 'SMOKE Buy three', array( 'basis' => 'quantity', 'reward' => 'percent', 'tiers' => array( array( 'min' => 3, 'value' => 30 ) ), 'scope' => $cat ? 'categories' : 'all', 'categories' => $cat ? array( $cat ) : array(), 'public' => true ) );
WC()->cart->add_to_cart( $product->get_id(), 1 );
WC()->cart->calculate_totals();
$p        = Promotions::progress();
$expectB  = round( $price * 3 * 0.30, 2 );
$fees     = WC()->cart->get_fees();
$ok( 'collection promotion takes the item over the all-products one, only one applies', $p['applied'] && $b === $p['applied']['id'] && abs( $p['applied']['discount'] - $expectB ) < 0.011 && 1 === count( array_filter( array_keys( $fees ), static fn( $k ) => str_starts_with( (string) $k, 'pc-promo-' ) ) ) );

// 不能和优惠码同时用。
update_post_meta( $b, Promotions::META, array_merge( Promotions::config( $b ), array( 'with_coupons' => false ) ) );
update_post_meta( $a, Promotions::META, array_merge( Promotions::config( $a ), array( 'with_coupons' => false ) ) );
$coupon = new WC_Coupon();
$coupon->set_code( 'SMOKEPROMO1' );
$coupon->set_props( array( 'discount_type' => 'fixed_cart', 'amount' => 1 ) );
$cid    = $coupon->save();
WC()->cart->apply_coupon( 'smokepromo1' );
WC()->cart->calculate_totals();
$ok( 'promotions that cannot combine are skipped when a coupon is used', null === Promotions::progress()['applied'] && ! array_filter( array_keys( WC()->cart->get_fees() ), static fn( $k ) => str_starts_with( (string) $k, 'pc-promo-' ) ) );
WC()->cart->remove_coupon( 'smokepromo1' );
wp_delete_post( $cid, true );
update_post_meta( $a, Promotions::META, array_merge( Promotions::config( $a ), array( 'with_coupons' => true ) ) );
wp_delete_post( $b, true );

// 只算参加活动的商品。
update_post_meta( $a, Promotions::META, array_merge( Promotions::config( $a ), array( 'scope' => 'products', 'products' => array( 999999 ) ) ) );
WC()->cart->calculate_totals();
$ok( 'items outside the scope do not count', null === Promotions::progress()['applied'] );
update_post_meta( $a, Promotions::META, array_merge( Promotions::config( $a ), array( 'scope' => 'products', 'products' => array( $product->get_id() ) ) ) );
WC()->cart->calculate_totals();
$ok( 'selected product counts', $a === ( Promotions::progress()['applied']['id'] ?? 0 ) );
update_post_meta( $a, Promotions::META, array_merge( Promotions::config( $a ), array( 'scope' => 'all', 'products' => array() ) ) );

// 订单费用行记下活动 ID。
$item = new WC_Order_Item_Fee();
Promotions::tag_fee_item( $item, 'k', (object) array( 'id' => 'pc-promo-' . $a ), null );
$ok( 'order fee line records the promotion', $a === (int) $item->get_meta( Promotions::FEE_META ) );

// 区块购物车的数据。
$data = \PeptideCore\Api\Store_Api::class;
$ok( 'Store API cart extension includes promotion', str_contains( (string) file_get_contents( PEPTIDE_CORE_DIR . '/includes/api/class-store-api.php' ), "'promotion'    => \\PeptideCore\\Promotions::progress()" ) );
WC()->cart->empty_cart();
$ok( 'empty cart: no promotion data', array( 'applied' => null, 'next' => null ) === Promotions::progress() );

// 4. 公开页面与商品提示。
$raw          = array( 'type' => 'spend_amount', 'tiers' => array( array( 'min' => round( $price * 2, 2 ), 'value' => 5 ) ), 'public' => true, 'scope' => 'all', 'start' => time() - 60, 'end' => 0 );
[ , $errors ] = Promotions::store( $a, 'SMOKE Spend save', $raw, true );
$ok( 'saving is refused while another all-products promotion overlaps', $errors && str_contains( implode( ' ', $errors ), 'SMOKE Later' ) );
wp_update_post( array( 'ID' => $upcoming, 'post_status' => 'draft' ) );
[ $sid, $errors ] = Promotions::store( $a, 'SMOKE Spend save', $raw, true );
wp_update_post( array( 'ID' => $upcoming, 'post_status' => 'publish' ) );
$ok( 'saving a public promotion creates the offers page', ! $errors && $a === $sid && Promotions::page_id() && 'publish' === get_post_status( Promotions::page_id() ) );
$codes = array_column( peptide_core_get_promotions(), 'id' );
$ok( 'public list: running and scheduled, not ended or draft', in_array( $a, $codes, true ) && in_array( $upcoming, $codes, true ) && ! in_array( $ended, $codes, true ) && ! in_array( $draft, $codes, true ) );
$list = do_shortcode( '[pc_promotions]' );
$ok( 'offers page lists the promotion with tier text', str_contains( $list, 'SMOKE Spend save' ) && str_contains( $list, 'Spend ' ) );
$_GET['promo'] = $ended;
$gone          = do_shortcode( '[pc_promotions]' );
$_GET['promo'] = $draft;
$hidden        = do_shortcode( '[pc_promotions]' );
unset( $_GET['promo'] );
$ok( 'ended promotion detail says it has ended', str_contains( $gone, 'ended' ) );
$ok( 'draft promotion is not shown', ! str_contains( $hidden, 'SMOKE Draft' ) );
$ok( 'product shows its running promotions', in_array( $a, array_column( peptide_core_get_product_promotions( $product ), 'id' ), true ) && ! in_array( $upcoming, array_column( peptide_core_get_product_promotions( $product ), 'id' ), true ) );

// 规则有错时不保存。
$bad          = $make( 'SMOKE Bad', array( 'tiers' => array( array( 'min' => 10, 'value' => 1 ) ) ), 'draft' );
[ , $errors ] = Promotions::store( $bad, 'SMOKE Bad', array( 'tiers' => array( array( 'min' => 10, 'value' => 50 ) ) ), false );
$ok( 'invalid rules are refused and the saved rules stay', $errors && 1.0 === (float) Promotions::config( $bad )['tiers'][0]['value'] );

// 5. 菜单链接。
$m     = Menus::create( 'SMOKE promo menu' );
$saved = Menus::save( $m, array( 'name' => 'SMOKE promo menu', 'items' => array( array( 'title' => 'Offers', 'link' => array( 'type' => 'promotions' ) ), array( 'title' => '', 'link' => array( 'type' => 'promotion', 'id' => $a ) ), array( 'title' => 'Old', 'link' => array( 'type' => 'promotion', 'id' => $ended ) ) ) ) );
$ok( 'promotion links saved, name defaults to the title', is_array( $saved ) && 'SMOKE Spend save' === $saved['items'][1]['title'] );
$prev = get_theme_mod( 'nav_menu_locations', array() );
Menus::set_location( $m, 'footer' );
$tree = Menus::public_tree( 'footer' );
$ok( 'offers page and detail links resolve, ended one hidden', Promotions::list_url() === $tree[0]['url'] && str_contains( $tree[1]['url'], 'promo=' . $a ) && 2 === count( $tree ) );
set_theme_mod( 'nav_menu_locations', $prev );
Menus::delete( $m );

// 6. 清理。
foreach ( $made as $id ) {
	wp_delete_post( $id, true );
}
if ( ! $had_page && Promotions::page_id() ) {
	wp_delete_post( Promotions::page_id(), true );
	delete_option( Promotions::PAGE_OPTION );
}
wc_clear_notices();
$ok( 'test promotions deleted', ! array_filter( array_map( 'get_post', $made ) ) );
