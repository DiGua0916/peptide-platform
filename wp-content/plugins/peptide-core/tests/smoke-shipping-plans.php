<?php
/**
 * 物流设置（SHIPPING-PLANS-01）冒烟测试：方案清理、保存与商品移动、邮编 / 区间匹配、按方案分组报价、
 * 多方案合并（相加 / 取最高）、区域不覆盖时无运费、方案接管 WooCommerce 原有运费、真实购物车运费、后台页面与权限。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-shipping-plans.php
 * 购物车测试以游客身份进行（命令行里不保存会话），不会碰到真实顾客或管理员的购物车。方案和规则在结束时恢复原样。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Shipping_Page;
use PeptideCore\Shipping\Plans;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

add_filter( 'pre_wp_mail', '__return_true' );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$orig_plans = get_option( Plans::OPTION, null );
$orig_rules = get_option( Plans::RULES, null );
delete_option( Plans::OPTION );
delete_option( Plans::RULES );

$products = array();
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => 30 ) ) as $p ) {
	if ( $p->is_purchasable() && $p->needs_shipping() && (float) $p->get_price() > 0 ) {
		$products[] = $p;
	}
	if ( count( $products ) >= 2 ) {
		break;
	}
}
$ok( 'found two shippable products', 2 === count( $products ) );
if ( count( $products ) < 2 ) {
	return;
}
[ $pa, $pb ] = $products;

// 1. 清理。
$n = Plans::normalize(
	array(
		'type'     => 'general',
		'name'     => ' <b>Worldwide</b> ',
		'products' => array( 1, 2 ),
		'regions'  => array( array( 'country' => 'us', 'states' => array( 'ca', 'XX' ) ), array( 'country' => 'ZZ' ), array( 'country' => 'US' ) ),
		'rates'    => array(
			array( 'name' => 'Standard', 'enabled' => true, 'rule' => 'subtotal', 'min' => '0', 'max' => '', 'fee' => '45.555', 'postcodes' => " 900* ,\n90001 " ),
			array( 'name' => '', 'fee' => 1 ),
			array( 'name' => 'Bad rule', 'rule' => 'volume', 'min' => -5, 'max' => '10', 'fee' => -3, 'enabled' => 'false' ),
		),
	)
);
$ok( 'name cleaned, general plans have no products', 'Worldwide' === $n['name'] && array() === $n['products'] );
$ok( 'invalid and duplicate countries / states dropped', array( array( 'country' => 'US', 'states' => array( 'CA' ) ) ) === $n['regions'] );
$ok( 'rates cleaned (empty name dropped, fee rounded, open upper limit)', 2 === count( $n['rates'] ) && 45.56 === $n['rates'][0]['fee'] && null === $n['rates'][0]['max'] && '900*, 90001' === $n['rates'][0]['postcodes'] );
$ok( 'unknown rule, negative values and "false" fixed', 'subtotal' === $n['rates'][1]['rule'] && 0.0 === $n['rates'][1]['min'] && 0.0 === $n['rates'][1]['fee'] && false === $n['rates'][1]['enabled'] && '' !== $n['rates'][1]['id'] );

// 2. 匹配规则。
$ok( 'ZIP: exact, wildcard, range, empty list', Plans::postcode_matches( '90001', '90001' ) && Plans::postcode_matches( '900*', '90012' ) && Plans::postcode_matches( '90000-90999', '90210' ) && Plans::postcode_matches( '', 'anything' ) );
$ok( 'ZIP: no match and missing postcode', ! Plans::postcode_matches( '900*', '10001' ) && ! Plans::postcode_matches( '90000-90999', '91000' ) && ! Plans::postcode_matches( '900*', '' ) );
$ok( 'ZIP+4 matches a 5-digit range', Plans::postcode_matches( '90000-90999', '90210-1234' ) );
$rate = array( 'min' => 40.0, 'max' => 50.0 );
$ok( 'fee rule ranges include the lower bound, exclude the upper', Plans::rule_matches( $rate, 40 ) && Plans::rule_matches( $rate, 49.99 ) && ! Plans::rule_matches( $rate, 50 ) && Plans::rule_matches( array( 'min' => 50.0, 'max' => null ), 9999 ) );

// 3. 保存、商品只属于一个自定义方案。
$general = Plans::save(
	array(
		'type'    => 'general',
		'name'    => 'Worldwide Express Service',
		'regions' => array( array( 'country' => 'US' ), array( 'country' => 'GB' ) ),
		'rates'   => array(
			array( 'name' => 'UPS • FedEx • USPS', 'enabled' => true, 'rule' => 'subtotal', 'min' => 0, 'max' => 999, 'fee' => 45 ),
			array( 'name' => 'Free Shipping', 'enabled' => true, 'rule' => 'subtotal', 'min' => 999, 'max' => '', 'fee' => 0 ),
			array( 'name' => 'Overnight (LA only)', 'enabled' => true, 'rule' => 'subtotal', 'min' => 0, 'max' => '', 'fee' => 30, 'postcodes' => '900*' ),
			array( 'name' => 'Disabled rate', 'enabled' => false, 'rule' => 'subtotal', 'min' => 0, 'max' => '', 'fee' => 1 ),
		),
	)
);
$custom1 = Plans::save( array( 'type' => 'custom', 'name' => 'Priority Mail Express', 'products' => array( $pb->get_id() ), 'regions' => array( array( 'country' => 'US', 'states' => array( 'CA', 'NY' ) ) ), 'rates' => array( array( 'name' => 'USPS Priority Mail Express', 'enabled' => true, 'rule' => 'quantity', 'min' => 1, 'max' => '', 'fee' => 80 ) ) ) );
$ok( 'plans get ids', $general['id'] > 0 && $custom1['id'] > $general['id'] && Plans::active() );
$custom2 = Plans::save( array( 'type' => 'custom', 'name' => 'Second custom', 'products' => array( $pb->get_id() ), 'regions' => array(), 'rates' => array() ) );
$ok( 'a product moves to the plan that selected it last', array() === Plans::get( $custom1['id'] )['products'] && array( $pb->get_id() ) === Plans::get( $custom2['id'] )['products'] && $custom2['id'] === Plans::product_map()[ $pb->get_id() ] );
Plans::save( array_merge( Plans::get( $custom1['id'] ), array( 'products' => array( $pb->get_id() ) ) ) );
Plans::delete( $custom2['id'] );
$ok( 'deleted plan is gone', null === Plans::get( $custom2['id'] ) && $custom1['id'] === Plans::product_map()[ $pb->get_id() ] );

// 4. 报价。
$pkg = static function ( array $items, string $country = 'US', string $state = 'CA', string $postcode = '10001' ): array {
	$contents = array();
	foreach ( $items as $i => [ $product, $qty ] ) {
		$contents[ 'k' . $i ] = array( 'data' => $product, 'product_id' => $product->get_id(), 'quantity' => $qty, 'line_subtotal' => (float) $product->get_price() * $qty );
	}
	return array( 'contents' => $contents, 'destination' => array( 'country' => $country, 'state' => $state, 'postcode' => $postcode ) );
};
$labels = static fn( array $q ): array => array_column( $q, 'cost', 'label' );
$qa     = Plans::quote( $pkg( array( array( $pa, 1 ) ) ) );
$ok( 'general product: matching amount range only, disabled rate hidden', array( 'UPS • FedEx • USPS' => 45.0 ) === $labels( $qa ) );
$qla = Plans::quote( $pkg( array( array( $pa, 1 ) ), 'US', 'CA', '90012' ) );
$ok( 'ZIP-limited rate appears for matching ZIPs, cheapest first', array( 'Overnight (LA only)', 'UPS • FedEx • USPS' ) === array_column( $qla, 'label' ) );
$big = Plans::quote( $pkg( array( array( $pa, (int) ceil( 1000 / (float) $pa->get_price() ) ) ) ) );
$ok( 'free shipping from 999', array( 'Free Shipping' => 0.0 ) === $labels( $big ) );
$ok( 'custom product uses its own plan', array( 'USPS Priority Mail Express' => 80.0 ) === $labels( Plans::quote( $pkg( array( array( $pb, 2 ) ) ) ) ) );
$mix = Plans::quote( $pkg( array( array( $pa, 1 ), array( $pb, 1 ) ) ) );
$ok( 'mixed cart: one combined rate, cheapest of each plan added up', 1 === count( $mix ) && 125.0 === (float) $mix[0]['cost'] && 'combined' === $mix[0]['id'] );
Plans::save_rules( array( 'combine' => 'max', 'sort' => 'price' ) );
$ok( 'mixed cart: highest rate when the rule says so', 80.0 === (float) Plans::quote( $pkg( array( array( $pa, 1 ), array( $pb, 1 ) ) ) )[0]['cost'] );
Plans::save_rules( array( 'combine' => 'sum', 'sort' => 'price' ) );
$ok( 'state outside the custom plan: no shipping at all', array() === Plans::quote( $pkg( array( array( $pa, 1 ), array( $pb, 1 ) ), 'US', 'TX' ) ) );
$ok( 'country outside every plan: no shipping', array() === Plans::quote( $pkg( array( array( $pa, 1 ) ), 'FR', '' ) ) );
$ok( 'another country in the general plan works', array( 'UPS • FedEx • USPS' => 45.0 ) === $labels( Plans::quote( $pkg( array( array( $pa, 1 ) ), 'GB', '' ) ) ) );

// 5. 方案接管原有运费。
$flat  = new WC_Shipping_Rate( 'flat_rate:1', 'Flat', 5, array(), 'flat_rate', 1 );
$plan  = new WC_Shipping_Rate( 'pc_plans:1-x', 'Plan', 45, array(), Plans::METHOD_ID );
$kept  = Plans::only_plan_rates( array( 'flat_rate:1' => $flat, 'pc_plans:1-x' => $plan ) );
$ok( 'with plans, only plan rates remain', array( 'pc_plans:1-x' ) === array_keys( $kept ) );
$saved = get_option( Plans::OPTION );
delete_option( Plans::OPTION );
$ok( 'without plans, WooCommerce zones are untouched', 2 === count( Plans::only_plan_rates( array( 'flat_rate:1' => $flat, 'pc_plans:1-x' => $plan ) ) ) && array() === Plans::quote( $pkg( array( array( $pa, 1 ) ) ) ) );
update_option( Plans::OPTION, $saved, false );

// 6. 真实购物车（游客）。
wp_set_current_user( 0 );
wc_load_cart();
WC()->cart->empty_cart();
WC()->customer->set_shipping_location( 'US', 'CA', '90012', 'Los Angeles' );
WC()->customer->set_billing_location( 'US', 'CA', '90012', 'Los Angeles' );
WC()->cart->add_to_cart( $pa->get_id(), 1 );
WC()->cart->calculate_totals();
$rates = array();
foreach ( WC()->shipping()->get_packages() as $package ) {
	foreach ( $package['rates'] as $id => $r ) {
		$rates[ $id ] = (float) $r->get_cost();
	}
}
$ok( 'checkout offers the plan rates and nothing from the old zone', $rates && ! array_filter( array_keys( $rates ), static fn( $id ) => ! str_starts_with( $id, 'pc_plans:' ) ) && in_array( 30.0, $rates, true ) && in_array( 45.0, $rates, true ) );
WC()->cart->add_to_cart( $pb->get_id(), 1 );
WC()->cart->calculate_totals();
$ok( 'mixed real cart charges 30 + 80 = 110 (cheapest general rate + custom rate)', 110.0 === (float) WC()->cart->get_shipping_total() );
WC()->customer->set_shipping_location( 'US', 'TX', '73301', 'Austin' );
WC()->cart->calculate_totals();
$ok( 'real cart to an uncovered state has no shipping rates', ! array_filter( array_map( static fn( $p ) => $p['rates'], WC()->shipping()->get_packages() ) ) );
WC()->cart->empty_cart();
wp_set_current_user( $admin->ID );

// 7. 后台页面与权限。
$_GET = array( 'page' => Shipping_Page::SLUG, 'tab' => 'custom' );
ob_start();
Shipping_Page::render();
$html = ob_get_clean();
$ok( 'list page shows the custom plan and its rate', str_contains( $html, 'Priority Mail Express' ) && str_contains( $html, 'USPS Priority Mail Express' ) && str_contains( $html, 'pc-pl-rules' ) );
$_GET = array( 'page' => Shipping_Page::SLUG, 'plan' => (string) $custom1['id'] );
ob_start();
Shipping_Page::render();
$html = ob_get_clean();
$ok( 'edit page has products, regions, rates and the plan data', str_contains( $html, 'name="products[]" value="' . $pb->get_id() . '"' ) && str_contains( $html, 'pc-pl-regions' ) && str_contains( $html, 'pc-pl-rates' ) && str_contains( $html, '"country":"US"' ) );
$form = Shipping_Page::from_form( array( 'id' => '', 'type' => 'custom', 'name' => 'From form', 'products' => array( (string) $pa->get_id() ), 'regions' => wp_json_encode( array( array( 'country' => 'CA' ) ) ), 'rates' => wp_json_encode( array( array( 'name' => 'Canada Post', 'enabled' => true, 'fee' => 12 ) ) ) ) );
$saved_form = Plans::save( $form );
$ok( 'form data saves as a plan', 'From form' === $saved_form['name'] && array( $pa->get_id() ) === $saved_form['products'] && 'CA' === $saved_form['regions'][0]['country'] && 12.0 === $saved_form['rates'][0]['fee'] );
$_GET = array();
$customer = wc_create_new_customer( 'pc-ship-' . wp_rand() . '@example.com', '', wp_generate_password() );
wp_set_current_user( $customer );
ob_start();
Shipping_Page::render();
$ok( 'customers see nothing', '' === ob_get_clean() );
wp_set_current_user( $admin->ID );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $customer );

// 恢复。
foreach ( array( Plans::OPTION => $orig_plans, Plans::RULES => $orig_rules ) as $k => $v ) {
	if ( null === $v ) {
		delete_option( $k );
	} else {
		update_option( $k, $v, false );
	}
}
$ok( 'plans restored', $orig_plans === get_option( Plans::OPTION, null ) );
