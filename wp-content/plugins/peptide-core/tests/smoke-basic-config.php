<?php
/**
 * 系统设置 → 基础配置（BASIC-CONFIG-01）冒烟测试：保存与校验、货币锁定、管理员邮箱确认流程、SPU / SKU 自动编码、
 * 爬虫拦截、登录验证码、前台搜索字段、排序字段、默认款式与售罄款式、注册邮箱验证、打烊、商品链接、页面权限。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-basic-config.php
 * 邮件用 pre_wp_mail 拦截。测试改动的设置在结束时全部恢复，测试商品和用户删除。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Basic_Config_Page;
use PeptideCore\Store_Config;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );

$keys = array(
	Store_Config::OPTION, 'blogname', 'admin_email', 'new_admin_email', 'adminhash', 'site_icon', 'timezone_string', 'WPLANG',
	'woocommerce_email_from_address', 'woocommerce_email_from_name', 'woocommerce_currency', 'woocommerce_price_thousand_sep',
	'woocommerce_price_decimal_sep', 'woocommerce_price_num_decimals', 'woocommerce_coming_soon', 'woocommerce_store_pages_only',
	'woocommerce_permalinks', 'woocommerce_default_country', 'woocommerce_store_city', 'woocommerce_store_address',
	'woocommerce_store_postcode', 'peptide_core_flush_rewrites',
);
$orig = array();
foreach ( $keys as $k ) {
	$orig[ $k ] = get_option( $k, null );
}
$mails = array();
add_filter(
	'pre_wp_mail',
	static function ( $null, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	10,
	2
);
$cleanup_products = array();
$cleanup_users    = array();

$form = array(
	'blogname'         => (string) get_option( 'blogname' ),
	'admin_email'      => (string) get_option( 'admin_email' ),
	'support_email'    => 'support@vialcove-test.example',
	'support_name'     => 'VialCove Support',
	'finance_email'    => 'finance@vialcove-test.example',
	'site_icon'        => (string) get_option( 'site_icon' ),
	'timezone_string'  => 'America/Los_Angeles',
	'WPLANG'           => (string) get_option( 'WPLANG' ),
	'currency'         => 'EUR',
	'price_format'     => '123.456,78',
	'spu_mode'         => 'random',
	'sku_auto'         => '1',
	'sku_pattern'      => '{SPU}-{SKU_CODE}',
	'returning_hours'  => '72',
	'bot_system'       => '1',
	'bot_custom'       => "evilbot\nab\nSuperScraper",
	'admin_captcha'    => '0',
	'search_fields'    => array( 'sku', 'category' ),
	'force_login'      => '0',
	'register_verify'  => '1',
	'sorts'            => array( 'price', 'menu_order', 'title-desc' ),
	'allow_iframe'     => '0',
	'store_open'       => '1',
	'product_link'     => 'product',
	'offline_product'  => 'shop',
	'default_variant'  => 'first',
	'soldout_variants' => 'hide',
	'company_name'     => 'VialCove Test LLC',
	'phone'            => '+1 555 0100',
	'country'          => 'US',
	'state'            => 'CA',
	'city'             => 'Los Angeles',
	'address'          => '1 Test Way',
	'postcode'         => '90001',
);

// 1. 保存与校验。
$errors = Basic_Config_Page::apply( $form );
$c      = Store_Config::all();
$ok( 'valid form saves without errors', array() === $errors );
$ok( 'support email and name go to the WooCommerce sender', 'support@vialcove-test.example' === get_option( 'woocommerce_email_from_address' ) && 'VialCove Support' === get_option( 'woocommerce_email_from_name' ) );
$ok( 'price format preset applied', '.' === get_option( 'woocommerce_price_thousand_sep' ) && ',' === get_option( 'woocommerce_price_decimal_sep' ) && 2 === (int) get_option( 'woocommerce_price_num_decimals' ) && '123.456,78' === Store_Config::price_format_key() );
$ok( 'store currency locked because orders exist', Store_Config::currency_locked() && $orig['woocommerce_currency'] === get_option( 'woocommerce_currency' ) );
$ok( 'custom crawler keywords cleaned (short ones dropped, lowercase)', array( 'evilbot', 'superscraper' ) === $c['bot_custom'] );
$ok( 'store config values saved', 72 === $c['returning_hours'] && $c['register_verify'] && 'hide' === $c['soldout_variants'] && 'shop' === $c['offline_product'] && 'finance@vialcove-test.example' === $c['finance_email'] );
$ok( 'contact goes to the WooCommerce store address', 'US:CA' === get_option( 'woocommerce_default_country' ) && 'Los Angeles' === get_option( 'woocommerce_store_city' ) && '90001' === get_option( 'woocommerce_store_postcode' ) );
$ok( 'address line for emails and [pc_var]', str_contains( Store_Config::address_line(), '1 Test Way, Los Angeles, California 90001' ) && 'VialCove Test LLC' === do_shortcode( '[pc_var name="company_name"]' ) );
$ok( 'empty sender name falls back to the store name', get_bloginfo( 'name' ) === Store_Config::from_name_fallback( '' ) );

$bad = Basic_Config_Page::apply( array_merge( $form, array( 'support_email' => 'not-an-email', 'blogname' => '', 'sorts' => array() ) ) );
$ok( 'invalid support email, empty name and no sort option are rejected', 3 === count( $bad ) && 'support@vialcove-test.example' === get_option( 'woocommerce_email_from_address' ) && '' !== get_option( 'blogname' ) && array( 'price', 'menu_order', 'title-desc' ) === Store_Config::get( 'sorts' ) );

// 2. 管理员邮箱：只发确认邮件，不直接替换。
$mails = array();
Basic_Config_Page::apply( array_merge( $form, array( 'admin_email' => 'new-admin@vialcove-test.example' ) ) );
$ok( 'admin email change waits for confirmation', 'new-admin@vialcove-test.example' === get_option( 'new_admin_email' ) && $orig['admin_email'] === get_option( 'admin_email' ) && $mails && 'new-admin@vialcove-test.example' === ( is_array( $mails[0]['to'] ) ? $mails[0]['to'][0] : $mails[0]['to'] ) );

// 3. 店长不能改 WordPress 自身的设置。
$manager = wp_insert_user( array( 'user_login' => 'pc_bc_manager_' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'pc-bc-manager-' . wp_rand() . '@example.com', 'role' => 'shop_manager' ) );
$cleanup_users[] = $manager;
wp_set_current_user( $manager );
Basic_Config_Page::apply( array_merge( $form, array( 'blogname' => 'Hijacked name' ) ) );
$ok( 'shop managers cannot change the store name', 'Hijacked name' !== get_option( 'blogname' ) );
ob_start();
Basic_Config_Page::render();
$html = ob_get_clean();
$ok( 'shop managers see WordPress fields read-only', str_contains( $html, 'name="blogname"' ) && preg_match( '/name="blogname"[^>]*disabled/', $html ) );
wp_set_current_user( $admin->ID );

// 4. SPU / SKU 自动编码。
$simple = new WC_Product_Simple();
$simple->set_name( 'PC basic-config test simple' );
$simple->set_status( 'private' );
$simple->set_regular_price( '10' );
$simple->save();
$cleanup_products[] = $simple->get_id();
$simple = wc_get_product( $simple->get_id() );
$spu    = (string) $simple->get_meta( '_pc_spu' );
$ok( 'simple product gets an 18-digit SPU as SKU', 1 === preg_match( '/^[1-9]\d{17}$/', $spu ) && $spu === $simple->get_sku() );

$var = new WC_Product_Variable();
$var->set_name( 'PC basic-config test variable' );
$var->set_status( 'private' );
$attr = new WC_Product_Attribute();
$attr->set_name( 'Size' );
$attr->set_options( array( '5mg', '10mg' ) );
$attr->set_variation( true );
$attr->set_visible( true );
$var->set_attributes( array( $attr ) );
$var->save();
$cleanup_products[] = $var->get_id();
$vids = array();
foreach ( array( '5mg' => 'instock', '10mg' => 'outofstock' ) as $size => $stock ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $var->get_id() );
	$v->set_attributes( array( 'size' => $size ) );
	$v->set_regular_price( '20' );
	$v->set_stock_status( $stock );
	$v->set_status( 'publish' );
	$v->save();
	$vids[]             = $v->get_id();
	$cleanup_products[] = $v->get_id();
}
$var     = wc_get_product( $var->get_id() );
$var_spu = (string) $var->get_meta( '_pc_spu' );
$ok( 'variations get SPU-01 / SPU-02', $var_spu . '-01' === wc_get_product( $vids[0] )->get_sku() && $var_spu . '-02' === wc_get_product( $vids[1] )->get_sku() );

$keep = new WC_Product_Simple();
$keep->set_name( 'PC basic-config keep sku' );
$keep->set_status( 'private' );
$keep->set_sku( 'PC-KEEP-' . wp_rand() );
$keep->save();
$cleanup_products[] = $keep->get_id();
$ok( 'existing SKU is kept', str_starts_with( wc_get_product( $keep->get_id() )->get_sku(), 'PC-KEEP-' ) );

Store_Config::update( array( 'spu_mode' => 'id' ) );
$byid = new WC_Product_Simple();
$byid->set_name( 'PC basic-config spu id' );
$byid->set_status( 'private' );
$byid->save();
$cleanup_products[] = $byid->get_id();
$ok( 'SPU can follow the product ID', (string) $byid->get_id() === wc_get_product( $byid->get_id() )->get_sku() );
Store_Config::update( array( 'sku_auto' => false ) );
$none = new WC_Product_Simple();
$none->set_name( 'PC basic-config no auto' );
$none->set_status( 'private' );
$none->save();
$cleanup_products[] = $none->get_id();
$ok( 'no SKU when automatic codes are off', '' === wc_get_product( $none->get_id() )->get_sku() );
Store_Config::update( array( 'sku_auto' => true, 'spu_mode' => 'random' ) );

// 5. 默认款式、售罄款式。
$defaults = Store_Config::default_attributes( array(), $var );
$ok( 'first in-stock variation is selected by default', array( 'size' => '5mg' ) === $defaults );
$ok( 'a product’s own default is kept', array( 'size' => '10mg' ) === Store_Config::default_attributes( array( 'size' => '10mg' ), $var ) );
$ok( 'sold-out variation hidden', false === Store_Config::variation_visible( true, $vids[1], $var->get_id(), wc_get_product( $vids[1] ) ) && true === Store_Config::variation_visible( true, $vids[0], $var->get_id(), wc_get_product( $vids[0] ) ) );
Store_Config::update( array( 'soldout_variants' => 'show', 'default_variant' => 'none' ) );
$ok( 'show / none keep WooCommerce behaviour', true === Store_Config::variation_visible( true, $vids[1], $var->get_id(), wc_get_product( $vids[1] ) ) && array() === Store_Config::default_attributes( array(), $var ) );

// 6. 前台搜索：SKU 和分类。
$sku_product = wc_get_product( $vids[0] );
$search      = static function ( string $s ): array {
	$q = new WP_Query( array( 's' => $s, 'post_type' => 'product', 'post_status' => array( 'publish', 'private' ), 'fields' => 'ids', 'posts_per_page' => 20 ) );
	return array_map( 'intval', $q->posts );
};
$ok( 'search finds a product by a variation SKU', in_array( $var->get_id(), $search( $sku_product->get_sku() ), true ) );
$ok( 'search finds a product by its own SKU', in_array( $simple->get_id(), $search( $spu ), true ) );
Store_Config::update( array( 'search_fields' => array() ) );
$ok( 'without the SKU field, SKUs are not searched', ! in_array( $simple->get_id(), $search( $spu ), true ) );
Store_Config::update( array( 'search_fields' => array( 'sku', 'category' ) ) );
$ok( 'title search still works', in_array( $simple->get_id(), $search( 'basic-config test simple' ), true ) );

// 7. 排序字段。
$opts = Store_Config::catalog_orderby( array( 'menu_order' => 'Default', 'price' => 'Price' ) );
$ok( 'sort menu follows the configured order', array( 'price', 'menu_order', 'title-desc' ) === array_keys( $opts ) && 'price' === Store_Config::default_orderby( 'menu_order' ) );

// 8. 爬虫拦截、登录验证码。
$ok( 'built-in scanners blocked, search engines allowed', Store_Config::is_blocked_agent( 'sqlmap/1.7 (https://sqlmap.org)' ) && ! Store_Config::is_blocked_agent( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) && ! Store_Config::is_blocked_agent( '' ) );
$ok( 'custom keyword blocked', Store_Config::is_blocked_agent( 'Mozilla/5.0 EvilBot/3' ) );
Store_Config::update( array( 'bot_system' => false ) );
$ok( 'built-in list can be turned off', ! Store_Config::is_blocked_agent( 'sqlmap/1.7' ) );
Store_Config::update( array( 'admin_captcha' => true ) );
ob_start();
Store_Config::captcha_field();
$field = ob_get_clean();
preg_match( '/what is (\d) \+ (\d)/', $field, $m );
preg_match( '/name="pc_captcha_token" value="([^"]+)"/', $field, $t );
$GLOBALS['pagenow'] = 'wp-login.php';
$_POST              = array( 'log' => 'x', 'pc_login_captcha' => (string) ( (int) $m[1] + (int) $m[2] ), 'pc_captcha_token' => $t[1] );
$ok( 'correct captcha answer passes', ! is_wp_error( Store_Config::check_captcha( null ) ) );
$_POST['pc_login_captcha'] = (string) ( (int) $m[1] + (int) $m[2] + 1 );
$ok( 'wrong captcha answer is refused', is_wp_error( Store_Config::check_captcha( null ) ) );
$_POST = array();
$ok( 'requests without the login form are not checked', ! is_wp_error( Store_Config::check_captcha( null ) ) );
$GLOBALS['pagenow'] = 'index.php';
Store_Config::update( array( 'admin_captcha' => false ) );

// 9. 注册邮箱验证。
$customer        = wc_create_new_customer( 'pc-bc-verify-' . wp_rand() . '@example.com', '', wp_generate_password() );
$cleanup_users[] = $customer;
$_POST           = array( 'register' => '1' );
$ok( 'account-page registration is not signed in until verified', false === Store_Config::hold_new_customer( true, $customer ) && Store_Config::is_unverified( $customer ) );
$_POST = array();
$ok( 'unverified customer cannot sign in', is_wp_error( Store_Config::block_unverified( get_user_by( 'id', $customer ) ) ) );
$url = Store_Config::send_verification( $customer );
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
$ok( 'wrong token rejected', ! Store_Config::verify_token( $customer . '.wrongtoken' ) && Store_Config::is_unverified( $customer ) );
$ok( 'link from the email verifies the account', Store_Config::verify_token( (string) $q['pc_verify'] ) && ! Store_Config::is_unverified( $customer ) && ! is_wp_error( Store_Config::block_unverified( get_user_by( 'id', $customer ) ) ) );
$ok( 'checkout accounts are not held', true === Store_Config::hold_new_customer( true, $customer ) );

// 10. 打烊、商品链接。
Basic_Config_Page::apply( array_merge( $form, array( 'store_open' => '0' ) ) );
$ok( 'closed store turns on WooCommerce coming soon for the whole site', 'yes' === get_option( 'woocommerce_coming_soon' ) && 'no' === get_option( 'woocommerce_store_pages_only' ) );
Basic_Config_Page::apply( array_merge( $form, array( 'store_open' => '1', 'product_link' => 'shop_cat' ) ) );
$ok( 'open again and product links include the category', 'no' === get_option( 'woocommerce_coming_soon' ) && 'shop_cat' === Store_Config::current_product_link() && get_option( 'peptide_core_flush_rewrites' ) );

// 11. 页面。
ob_start();
Basic_Config_Page::render();
$html = ob_get_clean();
$ok( 'page has the four sections', 4 === substr_count( $html, 'class="pc-ss-panel"' ) && str_contains( $html, 'name="support_email"' ) && str_contains( $html, 'name="sorts[]"' ) && str_contains( $html, 'id="pc-bc-country"' ) );

// 清理。
foreach ( array_reverse( $cleanup_products ) as $id ) {
	$p = wc_get_product( $id );
	if ( $p ) {
		$p->delete( true );
	}
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $cleanup_users as $id ) {
	wp_delete_user( $id );
}
foreach ( $orig as $k => $v ) {
	if ( null === $v ) {
		delete_option( $k );
	} else {
		update_option( $k, $v );
	}
}
$ok( 'settings restored', $orig['woocommerce_email_from_address'] === get_option( 'woocommerce_email_from_address' ) && $orig['woocommerce_permalinks'] === get_option( 'woocommerce_permalinks' ) );
