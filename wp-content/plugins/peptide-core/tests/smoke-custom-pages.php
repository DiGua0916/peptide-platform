<?php
/**
 * 自定义页面补充功能（CUSTOM-PAGES-01）冒烟测试：结账条款、内容变量、生成常见条款。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-custom-pages.php
 * 测试创建的页面在结束时彻底删除。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Custom_Pages;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$made = array();

// 1. 内容变量。
$ok( 'store name variable', esc_html( get_bloginfo( 'name' ) ) === do_shortcode( '[pc_var name="store_name"]' ) );
$ok( 'store url as link', str_contains( do_shortcode( '[pc_var name="store_url" link="1"]' ), '<a href="' . esc_url( home_url( '/' ) ) . '"' ) );
$ok( 'unknown variable is empty', '' === do_shortcode( '[pc_var name="nope"]' ) );
$ok( 'variable values are escaped', ! str_contains( Custom_Pages::shortcode( array( 'name' => 'store_name' ) ), '<script' ) );
$ok( 'variables listed for the editor', isset( Custom_Pages::variables()['support_email'], Custom_Pages::variables()['year'] ) );

// 2. 结账条款。
$pub    = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SMOKE terms A' ) );
$draft  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'SMOKE terms B' ) );
$made[] = $pub;
$made[] = $draft;
$_POST  = array(
	'pc_page_terms_nonce' => wp_create_nonce( 'pc_page_terms_nonce' ),
	'pc_checkout_terms'   => '1',
);
Custom_Pages::save_meta( $pub, get_post( $pub ) );
Custom_Pages::save_meta( $draft, get_post( $draft ) );
$ok( 'checkbox saves the flag', Custom_Pages::is_checkout_terms( $pub ) && Custom_Pages::is_checkout_terms( $draft ) );
$ids = array_column( peptide_core_get_checkout_terms(), 'id' );
$ok( 'only published terms pages are listed', in_array( $pub, $ids, true ) && ! in_array( $draft, $ids, true ) );
$ok( 'list shows the checkout terms state', isset( Custom_Pages::post_states( array(), get_post( $pub ) )['pc_checkout_terms'] ) );
$_POST = array( 'pc_page_terms_nonce' => wp_create_nonce( 'pc_page_terms_nonce' ) );
Custom_Pages::save_meta( $pub, get_post( $pub ) );
$ok( 'unticking removes the flag', ! Custom_Pages::is_checkout_terms( $pub ) );
$_POST = array( 'pc_checkout_terms' => '1' );
Custom_Pages::save_meta( $pub, get_post( $pub ) );
$ok( 'no nonce, no change', ! Custom_Pages::is_checkout_terms( $pub ) );
$_POST = array();

// 3. 生成常见条款。
$before_privacy = get_page_by_path( 'privacy-policy' );
$result         = Custom_Pages::generate( array( 'shipping', 'privacy', 'contact' ) );
$made           = array_merge( $made, $result['created'] );
$shipping       = get_page_by_path( 'shipping-policy' );
$ok( 'shipping policy draft created', $shipping && in_array( (int) $shipping->ID, $result['created'], true ) && 'draft' === $shipping->post_status );
$ok( 'policy draft is checkout terms', $shipping && Custom_Pages::is_checkout_terms( (int) $shipping->ID ) );
$ok( 'draft has placeholders, not finished text', $shipping && str_contains( $shipping->post_content, '[Draft' ) && str_contains( $shipping->post_content, 'pc_var name="support_email"' ) );
if ( $before_privacy ) {
	$ok( 'existing privacy page kept', in_array( (int) $before_privacy->ID, $result['existing'], true ) );
}
$again = Custom_Pages::generate( array( 'shipping' ) );
$ok( 'running again does not duplicate', ! $again['created'] && $shipping && in_array( (int) $shipping->ID, $again['existing'], true ) );
$contact = get_page_by_path( 'contact-us' );
$ok( 'contact page is not checkout terms', ! $contact || ! in_array( (int) $contact->ID, $result['created'], true ) || ! Custom_Pages::is_checkout_terms( (int) $contact->ID ) );

$views = Custom_Pages::add_generator( array() );
$ok( 'generator has its own form', isset( $views['pc_policies'] ) && str_contains( $views['pc_policies'], 'value="pc_generate_policies"' ) && str_contains( $views['pc_policies'], '_pc_policy_nonce' ) );

// 4. 清理。
foreach ( $made as $id ) {
	wp_delete_post( $id, true );
}
$ok( 'test pages deleted', ! array_filter( array_map( 'get_post', $made ) ) );
