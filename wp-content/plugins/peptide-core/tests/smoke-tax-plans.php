<?php
/**
 * 税费设置（TAX-PLANS-01）冒烟测试：方案清理、同步到 WooCommerce 税率表、特定商品的税率类、商品移动与释放、
 * 修改范围、删除方案、手工税率不受影响、真实购物车税额、后台页面与权限。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-tax-plans.php
 * 购物车测试以游客身份进行。测试结束时删除测试方案、税率和税率类，恢复“启用税费”和商品税率类。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Tax_Page;
use PeptideCore\Tax\Plans;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

global $wpdb;
add_filter( 'pre_wp_mail', '__return_true' );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$orig_plans = get_option( Plans::OPTION, null );
$orig_calc  = get_option( 'woocommerce_calc_taxes' );
delete_option( Plans::OPTION );

$products = array();
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => 30 ) ) as $p ) {
	if ( $p->is_purchasable() && (float) $p->get_price() > 0 && 'taxable' === $p->get_tax_status() ) {
		$products[] = $p;
	}
	if ( count( $products ) >= 2 ) {
		break;
	}
}
$ok( 'found two taxable products', 2 === count( $products ) );
if ( count( $products ) < 2 ) {
	return;
}
[ $pa, $pb ]  = $products;
$orig_classes = array( $pa->get_id() => $pa->get_tax_class( 'edit' ), $pb->get_id() => $pb->get_tax_class( 'edit' ) );
$rates_of     = static fn( string $class ): array => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = %s AND tax_rate_name LIKE %s ORDER BY tax_rate_order", $class, 'PC test%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$manual       = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'GB', 'tax_rate' => '20', 'tax_rate_name' => 'Manual VAT', 'tax_rate_priority' => 1, 'tax_rate_class' => '' ) );

// 1. 清理。
$n = Plans::normalize( array( 'name' => ' PC test <i>x</i> ', 'scope' => 'weird', 'products' => array( 5 ), 'regions' => array( array( 'country' => 'us', 'states' => array( 'ca', 'zz' ), 'rate' => '7,25' ), array( 'country' => 'US' ), array( 'country' => 'DE', 'rate' => 150 ) ), 'shipping' => 'false' ) );
$ok( 'scope falls back to all products (no product list)', 'all' === $n['scope'] && array() === $n['products'] && false === $n['shipping'] );
$ok( 'regions cleaned: states checked, comma decimals, rate capped at 100', array( 'country' => 'US', 'states' => array( 'CA' ), 'rate' => 7.25 ) === $n['regions'][0] && 2 === count( $n['regions'] ) && 100.0 === $n['regions'][1]['rate'] );

// 2. 所有商品：写入标准税率类。
$all = Plans::save( array( 'name' => 'PC test sales tax', 'regions' => array( array( 'country' => 'US', 'states' => array( 'CA', 'NY' ), 'rate' => 7.25 ), array( 'country' => 'CA', 'rate' => 5 ) ), 'shipping' => true ) );
$std = $rates_of( '' );
$ok( 'one WooCommerce rate per state / country in the standard class', 3 === count( $std ) && array( 'CA', 'NY', '' ) === array_column( $std, 'tax_rate_state' ) && '7.2500' === $std[0]['tax_rate'] && '1' === $std[0]['tax_rate_shipping'] );
$ok( 'plan remembers its rate ids', 3 === count( Plans::get( $all['id'] )['rate_ids'] ) );
Plans::save( array_merge( Plans::get( $all['id'] ), array( 'regions' => array( array( 'country' => 'US', 'states' => array( 'CA' ), 'rate' => 8 ) ), 'shipping' => false ) ) );
$std = $rates_of( '' );
$ok( 'editing replaces the old rates', 1 === count( $std ) && '8.0000' === $std[0]['tax_rate'] && '0' === $std[0]['tax_rate_shipping'] );

// 3. 特定商品：自己的税率类。
$sp    = Plans::save( array( 'name' => 'PC test reduced', 'scope' => 'products', 'products' => array( $pb->get_id() ), 'regions' => array( array( 'country' => 'US', 'states' => array( 'CA' ), 'rate' => 2.5 ) ) ) );
$class = Plans::CLASS_PREFIX . $sp['id'];
$ok( 'specific plan creates its own tax class and moves the product into it', in_array( $class, WC_Tax::get_tax_class_slugs(), true ) && $class === wc_get_product( $pb->get_id() )->get_tax_class( 'edit' ) && 1 === count( $rates_of( $class ) ) );
$sp2 = Plans::save( array( 'name' => 'PC test other', 'scope' => 'products', 'products' => array( $pb->get_id() ), 'regions' => array( array( 'country' => 'US', 'rate' => 1 ) ) ) );
$ok( 'a product moves to the plan that picked it last', Plans::CLASS_PREFIX . $sp2['id'] === wc_get_product( $pb->get_id() )->get_tax_class( 'edit' ) && array() === Plans::get( $sp['id'] )['products'] && $sp2['id'] === Plans::product_map()[ $pb->get_id() ] );
Plans::save( array_merge( Plans::get( $sp['id'] ), array( 'products' => array( $pb->get_id() ) ) ) );
Plans::delete( $sp2['id'] );
$ok( 'deleting a plan removes its rates and class', ! in_array( Plans::CLASS_PREFIX . $sp2['id'], WC_Tax::get_tax_class_slugs(), true ) && array() === $rates_of( Plans::CLASS_PREFIX . $sp2['id'] ) && $class === wc_get_product( $pb->get_id() )->get_tax_class( 'edit' ) );

// 4. 真实购物车（游客，临时开启税费）。
update_option( 'woocommerce_calc_taxes', 'yes' );
wp_set_current_user( 0 );
wc_load_cart();
WC()->cart->empty_cart();
WC()->customer->set_shipping_location( 'US', 'CA', '90012', 'Los Angeles' );
WC()->customer->set_billing_location( 'US', 'CA', '90012', 'Los Angeles' );
WC()->cart->add_to_cart( $pa->get_id(), 1 );
WC()->cart->calculate_totals();
$expect_a = round( (float) $pa->get_price() * 0.08, 2 );
$ok( 'standard product taxed at 8% in California', abs( (float) WC()->cart->get_cart_contents_tax() - $expect_a ) < 0.011 );
WC()->cart->add_to_cart( $pb->get_id(), 1 );
WC()->cart->calculate_totals();
$expect_b = round( (float) $pb->get_price() * 0.025, 2 );
$ok( 'specific product taxed at its own 2.5%', abs( (float) WC()->cart->get_cart_contents_tax() - ( $expect_a + $expect_b ) ) < 0.021 );
WC()->customer->set_shipping_location( 'US', 'TX', '73301', 'Austin' );
WC()->customer->set_billing_location( 'US', 'TX', '73301', 'Austin' );
WC()->cart->calculate_totals();
$ok( 'no tax outside the plan regions', 0.0 === (float) WC()->cart->get_cart_contents_tax() );
WC()->cart->empty_cart();
update_option( 'woocommerce_calc_taxes', $orig_calc );
wp_set_current_user( $admin->ID );

// 5. 释放商品、改范围。
Plans::save( array_merge( Plans::get( $sp['id'] ), array( 'products' => array() ) ) );
$ok( 'a product removed from a plan goes back to the standard class', '' === wc_get_product( $pb->get_id() )->get_tax_class( 'edit' ) );
Plans::save( array_merge( Plans::get( $sp['id'] ), array( 'products' => array( $pb->get_id() ) ) ) );
Plans::save( array_merge( Plans::get( $sp['id'] ), array( 'scope' => 'all' ) ) );
$ok( 'switching to all products removes the class and releases products', ! in_array( $class, WC_Tax::get_tax_class_slugs(), true ) && '' === wc_get_product( $pb->get_id() )->get_tax_class( 'edit' ) && 1 === count( Plans::get( $sp['id'] )['rate_ids'] ) );

// 6. 后台页面。
$_GET = array( 'page' => Tax_Page::SLUG );
ob_start();
Tax_Page::render();
$html = ob_get_clean();
$ok( 'list page shows the plans, regions and the tax switch', str_contains( $html, 'PC test sales tax' ) && str_contains( $html, '8%' ) && str_contains( $html, 'pc_enable_tax' ) );
$_GET = array( 'page' => Tax_Page::SLUG, 'plan' => (string) $all['id'] );
ob_start();
Tax_Page::render();
$html = ob_get_clean();
$ok( 'edit page has name, regions with rates and product scope', str_contains( $html, 'name="scope"' ) && str_contains( $html, '"rate":8' ) && str_contains( $html, 'pc-pl-regions' ) );
$form = Tax_Page::from_form( array( 'name' => 'PC test form', 'scope' => 'products', 'products' => array( (string) $pa->get_id() ), 'regions' => wp_json_encode( array( array( 'country' => 'US', 'states' => array( 'NY' ), 'rate' => '4' ) ) ), 'shipping' => '1' ) );
$fp   = Plans::save( $form );
$ok( 'form data saves as a plan', 'PC test form' === $fp['name'] && array( $pa->get_id() ) === $fp['products'] && $fp['shipping'] && 4.0 === $fp['regions'][0]['rate'] );
$_GET     = array();
$customer = wc_create_new_customer( 'pc-tax-' . wp_rand() . '@example.com', '', wp_generate_password() );
wp_set_current_user( $customer );
ob_start();
Tax_Page::render();
$ok( 'customers see nothing', '' === ob_get_clean() );
wp_set_current_user( $admin->ID );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $customer );

// 清理。
foreach ( array_keys( Plans::all() ) as $id ) {
	Plans::delete( $id );
}
$ok( 'all test rates and classes removed', array() === $rates_of( '' ) && ! array_filter( WC_Tax::get_tax_class_slugs(), static fn( $s ) => str_starts_with( $s, Plans::CLASS_PREFIX ) ) );
$ok( 'manual WooCommerce rate untouched', (bool) WC_Tax::_get_tax_rate( $manual ) );
WC_Tax::_delete_tax_rate( $manual );
foreach ( $orig_classes as $pid => $cls ) {
	$p = wc_get_product( $pid );
	if ( $p->get_tax_class( 'edit' ) !== $cls ) {
		$p->set_tax_class( $cls );
		$p->save();
	}
}
if ( null === $orig_plans ) {
	delete_option( Plans::OPTION );
} else {
	update_option( Plans::OPTION, $orig_plans, false );
}
$ok( 'tax switch and products restored', $orig_calc === get_option( 'woocommerce_calc_taxes' ) && $orig_classes[ $pb->get_id() ] === wc_get_product( $pb->get_id() )->get_tax_class( 'edit' ) );
