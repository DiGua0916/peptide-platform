<?php
/**
 * 本地站的基础配置：销售国家、测试运费、货到付款（改名为“本地测试付款”）、HPOS。
 * 由 setup.sh 用 wp eval-file 执行，可重复执行。商品等演示数据用 `wp peptide demo seed`。
 */
if ( ! defined( 'VIALCOVE_LOCAL_ENV' ) || ! VIALCOVE_LOCAL_ENV ) {
	fwrite( STDERR, "只能在本地测试站执行。\n" );
	exit( 1 );
}
update_option( 'woocommerce_allowed_countries', 'specific' );
update_option( 'woocommerce_specific_allowed_countries', array( 'US', 'PR' ) );
update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
$cod            = (array) get_option( 'woocommerce_cod_settings', array() );
$cod['enabled'] = 'yes';
$cod['title']   = 'Local test payment — no charge';
update_option( 'woocommerce_cod_settings', $cod );
if ( class_exists( 'WC_Shipping_Zones' ) ) {
	$zone = WC_Shipping_Zones::get_zone( 0 );
	if ( $zone && ! $zone->get_shipping_methods() ) {
		$id = $zone->add_shipping_method( 'flat_rate' );
		update_option( 'woocommerce_flat_rate_' . $id . '_settings', array( 'title' => 'Local test shipping', 'tax_status' => 'none', 'cost' => '0' ) );
	}
}
echo "本地基础配置已写入。\n";
