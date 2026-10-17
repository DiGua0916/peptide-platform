<?php
/**
 * 促销对照价冒烟测试（PRICE-SALE-01，开发环境专用）。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-pricing.php
 * 只操作本测试临时创建的商品、优惠券和订单，结束时全部删除；不改动现有商品价格。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

// 测试中创建的订单不发送通知邮件。
add_filter( 'pre_wp_mail', '__return_false' );

use PeptideCore\Pricing;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};
$fresh = static fn( int $id ) => wc_get_product( $id );

// 1. 公式与百分比。
$ok( 'formula 29.98 -> 59.98, 37.98 -> 75.98, 44.98 -> 89.98', 5998 === Pricing::demo_compare_cents( 2998 ) && 7598 === Pricing::demo_compare_cents( 3798 ) && 8998 === Pricing::demo_compare_cents( 4498 ) );
$ok( 'percent from real difference, floored (50.0167% -> 50)', 50 === Pricing::percent_off( 5998, 2998 ) && 33 === Pricing::percent_off( 3000, 2000 ) );
$ok( 'percent 0 for invalid pairs (no divide by zero)', 0 === Pricing::percent_off( 0, 0 ) && 0 === Pricing::percent_off( 2998, 2998 ) && 0 === Pricing::percent_off( 2000, 3000 ) );

// 临时商品：simple 29.98；variable 两个规格 44.98 / 37.98。
$simple = new WC_Product_Simple();
$simple->set_name( 'PC Pricing Smoke Simple' );
$simple->set_status( 'publish' );
$simple->set_regular_price( '29.98' );
$simple_id = $simple->save();

$parent = new WC_Product_Variable();
$parent->set_name( 'PC Pricing Smoke Variable' );
$parent->set_status( 'publish' );
$attr = new WC_Product_Attribute();
$attr->set_name( 'Pack' );
$attr->set_options( array( 'Big', 'Small' ) );
$attr->set_visible( true );
$attr->set_variation( true );
$parent->set_attributes( array( $attr ) );
$parent_id = $parent->save();
$vids      = array();
foreach ( array( 'Big' => '44.98', 'Small' => '37.98' ) as $opt => $price ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $parent_id );
	$v->set_attributes( array( 'pack' => $opt ) );
	$v->set_regular_price( $price );
	$v->set_status( 'publish' );
	$vids[ $opt ] = $v->save();
}
WC_Product_Variable::sync( $parent_id );

// 2. 没有促销时：价格对只有一个价格，没有优惠标签。
$p = Pricing::pair( $fresh( $simple_id ) );
$ok( 'no sale: single price, no badge, no source', false === $p['on_sale'] && '2998' === $p['price'] && '2998' === $p['regular_price'] && 0 === $p['percent_off'] && null === $p['compare_source'] );

// 3. 开启演示对照价：成交价不变，常规价 = 对照价；与基准不一致时跳过；重复执行不再翻倍。
$ok( 'baseline mismatch skipped', 'skipped:baseline-mismatch' === Pricing::apply_demo_compare( $fresh( $simple_id ), '30.00' ) );
$ok( 'apply simple', 'applied' === Pricing::apply_demo_compare( $fresh( $simple_id ), '29.98' ) );
$s = $fresh( $simple_id );
$ok( 'simple: price stays 29.98, regular 59.98, sale 29.98', '29.98' === $s->get_price() && '59.98' === $s->get_regular_price() && '29.98' === $s->get_sale_price() && $s->is_on_sale() );
$ok( 'simple: no sale dates (cannot expire into doubled price)', null === $s->get_date_on_sale_from() && null === $s->get_date_on_sale_to() );
$ok( 're-apply is a no-op', 'skipped:already-applied' === Pricing::apply_demo_compare( $fresh( $simple_id ) ) && '29.98' === $fresh( $simple_id )->get_price() && '59.98' === $fresh( $simple_id )->get_regular_price() );
foreach ( $vids as $vid ) {
	Pricing::apply_demo_compare( $fresh( $vid ) );
}
WC_Product_Variable::sync( $parent_id );
$p = Pricing::pair( $fresh( $simple_id ) );
$ok( 'pair: 5998 / 2998, savings 3000, 50%, demo source', true === $p['on_sale'] && '5998' === $p['regular_price'] && '2998' === $p['price'] && '3000' === $p['savings'] && 50 === $p['percent_off'] && true === $p['compare_is_demo'] && Pricing::SOURCE_DEMO === $p['compare_source'] );

// 4. 卡片：取成交价最低的规格，并用这个规格自己的常规价（不跨规格拼凑）。
$card = Pricing::card_pair( $fresh( $parent_id ) );
$ok( 'card pair = lowest current price variation (Small 37.98 / 75.98)', $card && (int) $vids['Small'] === $card['variation_id'] && '3798' === $card['price'] && '7598' === $card['regular_price'] );
$big = Pricing::pair( $fresh( $vids['Big'] ) );
$ok( 'non-lowest variation has its own pair (44.98 / 89.98)', '4498' === $big['price'] && '8998' === $big['regular_price'] && 50 === $big['percent_off'] );

// 5. 购物车：数量 2 → 常规合计 119.96、促销小计 59.96、节省 60.00；优惠券单独计算，不并入节省。
WC()->frontend_includes();
wc_load_cart();
WC()->cart->empty_cart();
$key = WC()->cart->add_to_cart( $simple_id, 2 );
WC()->cart->calculate_totals();
$line = Pricing::cart_line( WC()->cart->get_cart_item( $key ) );
$ok( 'cart line qty 2: 11996 / 5996 / saved 6000', '11996' === $line['regular_subtotal'] && '5996' === $line['sale_subtotal'] && '6000' === $line['sale_savings'] );
$coupon = new WC_Coupon();
$coupon->set_code( 'pcsmoke10' );
$coupon->set_discount_type( 'percent' );
$coupon->set_amount( 10 );
$coupon_id = $coupon->save();
WC()->cart->apply_coupon( 'pcsmoke10' );
WC()->cart->calculate_totals();
$sum = Pricing::cart_summary( WC()->cart );
$ok( 'coupon reported separately (Woo discount 6.00), sale savings unchanged 60.00', 600 === Pricing::to_cents( WC()->cart->get_discount_total() ) && '6000' === $sum['sale_savings'] );
$ok( 'Woo payable total stays authoritative (59.96 - 6.00 = 53.96)', 5396 === Pricing::to_cents( WC()->cart->get_total( 'edit' ) ) );

// 6. 下单快照：保存下单时的每行价格；以后改价不影响。
$order = wc_create_order();
WC()->checkout()->create_order_line_items( $order, WC()->cart );
$order->save();
$item = array_values( $order->get_items() )[0] ?? null;
$snap = $item ? Pricing::snapshot( $item ) : null;
$ok( 'order line snapshot saved (unit 5998/2998, saved 6000, demo)', is_array( $snap ) && '5998' === $snap['regular_unit'] && '2998' === $snap['price_unit'] && '6000' === $snap['sale_savings'] && true === $snap['compare_is_demo'] );
$ok( 'my-order view exposes the snapshot', is_array( \PeptideCore\Order_Batches::customer_view( $order, 0 )['items'][0]['pricing'] ?? null ) );
WC()->cart->empty_cart();

// 7. 结束促销的保护：后台清空促销价时，常规价自动还原为成交价，绝不变成对照价。
$s = $fresh( $simple_id );
$s->set_sale_price( '' );
$s->save();
$s = $fresh( $simple_id );
$ok( 'clearing sale price never doubles the price (29.98)', '29.98' === $s->get_price() && '29.98' === $s->get_regular_price() && null === Pricing::record( $s ) );
$snap_after = Pricing::snapshot( $order->get_item( $item->get_id() ) );
$ok( 'old order snapshot unchanged after price change', '2998' === $snap_after['price_unit'] );

// 8. 改了常规价（不再是插件写入的对照价）→ 不再标记为演示来源。
$v = $fresh( $vids['Big'] );
$v->set_regular_price( '50.00' );
$v->save();
$ok( 'regular changed by hand: demo record removed', null === Pricing::record( $fresh( $vids['Big'] ) ) );

// 9. 撤销：常规价还原为成交价，清空促销价。
$ok( 'revert variation', 'reverted' === Pricing::revert( $fresh( $vids['Small'] ) ) && '37.98' === $fresh( $vids['Small'] )->get_price() && '' === $fresh( $vids['Small'] )->get_sale_price() && '37.98' === $fresh( $vids['Small'] )->get_regular_price() );

// 10. 别人已经设了促销价的商品不碰。
$v = $fresh( $vids['Small'] );
$v->set_sale_price( '35.00' );
$v->save();
$ok( 'existing sale left alone', 'skipped:existing-sale' === Pricing::apply_demo_compare( $fresh( $vids['Small'] ) ) && '35.00' === $fresh( $vids['Small'] )->get_price() );

// 还原。
$order->delete( true );
wp_delete_post( $coupon_id, true );
foreach ( $vids as $vid ) {
	$fresh( $vid )->delete( true );
}
$fresh( $parent_id )->delete( true );
$fresh( $simple_id )->delete( true );
$ok( 'cleanup', ! wc_get_product( $simple_id ) && ! wc_get_product( $parent_id ) );
