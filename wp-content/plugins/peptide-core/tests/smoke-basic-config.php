<?php
/**
 * 系统设置 → 基础配置（BASIC-CONFIG-01）冒烟测试：保存与校验、货币锁定、管理员邮箱确认流程、SPU / SKU 自动编码、
 * 爬虫拦截、登录验证码、前台搜索字段、排序字段、默认款式与售罄款式、注册邮箱验证、打烊、商品链接、页面权限，
 * 以及智能搜索、默认款式的几种选法、已下架商品可见 / 可购买、新账户审核、前台验证码、robots.txt、JSON-LD、手动 SPU。
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
	'woocommerce_store_postcode', 'peptide_core_flush_rewrites', 'woocommerce_pc_register_verify_settings',
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

// 12. 商品标题搜索方式（智能搜索 / 全部包含）。
$ok( 'smart search word counts: 1 → 1, 2 → 2, 3 → 3, 5 → 4, 8 → 7', 1 === Store_Config::words_needed( 1, 'smart' ) && 2 === Store_Config::words_needed( 2, 'smart' ) && 3 === Store_Config::words_needed( 3, 'smart' ) && 4 === Store_Config::words_needed( 5, 'smart' ) && 7 === Store_Config::words_needed( 8, 'smart' ) && 5 === Store_Config::words_needed( 5, 'all' ) );
$smart = new WC_Product_Simple();
$smart->set_name( 'PC smart Alpha Beta Gamma Delta Epsilon' );
$smart->set_status( 'private' );
$smart->save();
$cleanup_products[] = $smart->get_id();
Store_Config::update( array( 'search_mode' => 'smart' ) );
$ok( 'smart: 2 words must both be in the title', in_array( $smart->get_id(), $search( 'Alpha Beta' ), true ) && ! in_array( $smart->get_id(), $search( 'Alpha Zeta' ), true ) );
$ok( 'smart: with more than 2 words, 80% is enough (4 of 5)', in_array( $smart->get_id(), $search( 'Alpha Beta Gamma Delta Zeta' ), true ) );
$ok( 'smart: below 80% does not match (2 of 5)', ! in_array( $smart->get_id(), $search( 'Alpha Beta Zeta Eta Theta' ), true ) );
Store_Config::update( array( 'search_mode' => 'all' ) );
$ok( 'all words mode: one missing word means no match', ! in_array( $smart->get_id(), $search( 'Alpha Beta Gamma Delta Zeta' ), true ) && in_array( $smart->get_id(), $search( 'Gamma Alpha' ), true ) );
Store_Config::update( array( 'search_mode' => 'smart' ) );
$admin_ids = Store_Config::admin_product_search( false, 'Alpha Beta Gamma Delta Zeta', '', false, true );
$ok( 'admin product search uses the same smart matching (and SKU)', is_array( $admin_ids ) && in_array( $smart->get_id(), $admin_ids, true ) && in_array( $simple->get_id(), (array) Store_Config::admin_product_search( false, $spu, '', false, true ), true ) );
Store_Config::update( array( 'search_mode' => 'front_smart' ) );
$ok( 'front-only smart search leaves the admin search to WooCommerce', false === Store_Config::admin_product_search( false, 'Alpha', '', false, true ) );
Store_Config::update( array( 'search_mode' => 'smart' ) );

// 13. 多款式商品默认选中：价格最低 / 最高 / 库存最多。
$v3 = new WC_Product_Variable();
$v3->set_name( 'PC basic-config variants' );
$v3->set_status( 'private' );
$attr3 = new WC_Product_Attribute();
$attr3->set_name( 'Size' );
$attr3->set_options( array( 'A', 'B', 'C' ) );
$attr3->set_variation( true );
$attr3->set_visible( true );
$v3->set_attributes( array( $attr3 ) );
$v3->save();
$cleanup_products[] = $v3->get_id();
foreach ( array( 'A' => array( 30, 5 ), 'B' => array( 10, 2 ), 'C' => array( 20, 9 ) ) as $size => [ $price, $qty ] ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $v3->get_id() );
	$v->set_attributes( array( 'size' => $size ) );
	$v->set_regular_price( (string) $price );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( $qty );
	$v->set_status( 'publish' );
	$v->save();
	$cleanup_products[] = $v->get_id();
}
$v3 = wc_get_product( $v3->get_id() );
$pick_with = static function ( string $mode ) use ( $v3 ) {
	Store_Config::update( array( 'default_variant' => $mode ) );
	return Store_Config::default_attributes( array(), $v3 );
};
$ok( 'default variation: lowest price, highest price, most stock, first', array( 'size' => 'B' ) === $pick_with( 'lowest' ) && array( 'size' => 'A' ) === $pick_with( 'highest' ) && array( 'size' => 'C' ) === $pick_with( 'stock' ) && array( 'size' => 'A' ) === $pick_with( 'first' ) );
Store_Config::update( array( 'default_variant' => 'first' ) );

// 14. 已下架（草稿）商品：可见但不可购买 / 可见且可购买。
$draft = new WC_Product_Simple();
$draft->set_name( 'PC basic-config draft' );
$draft->set_regular_price( '12' );
$draft->set_status( 'draft' );
$draft->save();
$cleanup_products[] = $draft->get_id();
wp_set_current_user( 0 );
$draft = wc_get_product( $draft->get_id() );
Store_Config::update( array( 'offline_product' => 'visible' ) );
$ok( 'visible but not purchasable', ! $draft->is_purchasable() );
Store_Config::update( array( 'offline_product' => 'visible_buy' ) );
$ok( 'visible and purchasable', $draft->is_purchasable() );
$saved_q = $GLOBALS['wp_the_query'] ?? null;
$q       = new WP_Query();
$q->set( 'product', 'pc-basic-config-draft' );
$GLOBALS['wp_the_query'] = $q;
Store_Config::show_offline_products( $q );
$ok( 'the product page query also finds drafts', array( 'publish', 'draft' ) === $q->get( 'post_status' ) );
Store_Config::update( array( 'offline_product' => '404' ) );
$q2 = new WP_Query();
$q2->set( 'product', 'pc-basic-config-draft' );
$GLOBALS['wp_the_query'] = $q2;
Store_Config::show_offline_products( $q2 );
$ok( '404 mode: drafts stay hidden and cannot be bought', '' === $q2->get( 'post_status' ) && ! $draft->is_purchasable() );
$GLOBALS['wp_the_query'] = $saved_q;
wp_set_current_user( $admin->ID );

// 15. 强制注册 / 登录：新账户等管理员批准。
Store_Config::update( array( 'force_login' => true, 'register_verify' => false ) );
$member          = wc_create_new_customer( 'pc-bc-approve-' . wp_rand() . '@example.com', '', wp_generate_password() );
$cleanup_users[] = $member;
$_POST           = array( 'register' => '1' );
$ok( 'new account waits for approval and is not signed in', false === Store_Config::hold_new_customer( true, $member ) && Store_Config::is_pending_approval( $member ) );
$_POST = array();
$blocked = Store_Config::block_unverified( get_user_by( 'id', $member ) );
$ok( 'waiting accounts cannot sign in', is_wp_error( $blocked ) && 'pc_pending' === $blocked->get_error_code() );
$ok( 'users list shows the status and an Approve action', str_contains( (string) Store_Config::users_column_value( '', 'pc_status', $member ), 'Waiting' ) && isset( Store_Config::user_row_actions( array(), get_user_by( 'id', $member ) )['pc_approve'] ) );
Store_Config::handle_bulk( admin_url( 'users.php' ), 'pc_approve', array( $member ) );
$ok( 'bulk approve activates the account', ! Store_Config::is_pending_approval( $member ) && ! is_wp_error( Store_Config::block_unverified( get_user_by( 'id', $member ) ) ) );
Store_Config::update( array( 'force_login' => false ) );

// 16. 注册邮箱验证：验证邮件关闭时不要求验证（否则顾客永远收不到链接）。
update_option( 'woocommerce_pc_register_verify_settings', array( 'enabled' => 'no' ) );
Store_Config::update( array( 'register_verify' => true ) );
$nov             = wc_create_new_customer( 'pc-bc-noverify-' . wp_rand() . '@example.com', '', wp_generate_password() );
$cleanup_users[] = $nov;
$_POST           = array( 'register' => '1' );
$ok( 'verification email off: registration is not held', true === Store_Config::hold_new_customer( true, $nov ) && ! Store_Config::is_unverified( $nov ) );
$_POST = array();
update_option( 'woocommerce_pc_register_verify_settings', array( 'enabled' => 'yes' ) );

// 17. 前台图形验证码（我的账户登录 / 注册）。
Store_Config::update( array( 'front_captcha' => true ) );
ob_start();
Store_Config::front_captcha_field();
$field = ob_get_clean();
preg_match( '/what is (\d) \+ (\d)/', $field, $m );
preg_match( '/name="pc_captcha_token" value="([^"]+)"/', $field, $t );
$_POST = array( 'pc_login_captcha' => (string) ( (int) $m[1] + (int) $m[2] ), 'pc_captcha_token' => $t[1] );
$ok( 'storefront security check: right answer passes', ! Store_Config::check_front_captcha( new WP_Error() )->has_errors() );
$_POST['pc_login_captcha'] = '99';
$ok( 'storefront security check: wrong answer is refused', Store_Config::check_front_captcha( new WP_Error() )->has_errors() );
$_POST = array();
Store_Config::update( array( 'front_captcha' => false ) );

// 18. 禁止爬虫访问：勾选常见爬虫、robots.txt。
Store_Config::update( array( 'bot_known' => array( 'googlebot' ), 'bot_custom' => array( 'evilbot' ), 'bot_system' => true ) );
$robots = Store_Config::robots_txt( "User-agent: *\nDisallow: /wp-admin/\n" );
$ok( 'ticked crawler is blocked and listed in robots.txt', Store_Config::is_blocked_agent( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) && str_contains( $robots, "User-agent: Googlebot\nDisallow: /" ) && str_contains( $robots, "User-agent: evilbot\nDisallow: /" ) && str_contains( $robots, 'Disallow: /wp-admin/' ) );
Store_Config::update( array( 'bot_known' => array() ) );
$ok( 'unticked again: Googlebot allowed', ! Store_Config::is_blocked_agent( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) );

// 19. 公开联系方式的 JSON-LD。
$ld = Store_Config::json_ld_data();
$ok( 'JSON-LD Organization with name, phone and postal address', 'Organization' === ( $ld['@type'] ?? '' ) && 'VialCove Test LLC' === $ld['name'] && '+1 555 0100' === $ld['telephone'] && '1 Test Way' === $ld['address']['streetAddress'] && 'CA' === $ld['address']['addressRegion'] );

// 20. SPU：复制商品不带 SPU，可以手动填写，不能重复。
$ok( 'duplicated products do not copy the SPU', in_array( '_pc_spu', (array) apply_filters( 'woocommerce_duplicate_product_exclude_meta', array() ), true ) );
$manual = wc_get_product( $simple->get_id() );
$_POST  = array( '_pc_spu' => 'my-spu 1!' );
Store_Config::save_spu_field( $manual );
$manual->save();
$ok( 'manual SPU cleaned and saved', 'MY-SPU1' === wc_get_product( $simple->get_id() )->get_meta( '_pc_spu' ) );
$other = wc_get_product( $keep->get_id() );
Store_Config::save_spu_field( $other );
$other->save();
$ok( 'an SPU already used by another product is refused', 'MY-SPU1' !== wc_get_product( $keep->get_id() )->get_meta( '_pc_spu' ) );
$_POST = array();

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
// 手动 SPU 重复的测试会留下一条 WooCommerce 商品编辑提示（关闭时写进选项），不然下次打开后台会看到“SPU 已被使用”。
if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
	WC_Admin_Meta_Boxes::$meta_box_errors = array();
}
delete_option( 'woocommerce_meta_box_errors' );
