<?php
/**
 * 交易条款（TERMS-01）冒烟测试：设置清理（长度限制、内容过滤、空方案、编号）、开关与显示页面、区块结账的必选附加字段、
 * 默认勾选、订单上的条款快照、注册页 / 登录页的复选框和校验、后台页面、预览、清空。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-terms.php
 * 测试结束时恢复原来的条款设置，测试订单删除。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\Blocks\Package;
use PeptideCore\Admin\Terms_Page;
use PeptideCore\Terms;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

add_filter( 'pre_wp_mail', '__return_true' );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$orig = get_option( Terms::OPTION, null );

// 1. 清理。
$n = Terms::normalize(
	array(
		'enabled' => true,
		'title'   => str_repeat( 'T', 60 ),
		'plans'   => array(
			array( 'id' => 7, 'description' => str_repeat( 'd', 150 ), 'pages' => array( 'checkout', 'bogus', 'login' ), 'checked' => 'false', 'terms' => array( array( 'name' => str_repeat( 'n', 50 ), 'content' => '<p onclick="x()">Hi <strong>there</strong><script>alert(1)</script></p>' ), array( 'name' => '', 'content' => '' ) ) ),
			array( 'description' => '', 'terms' => array() ),
			array( 'id' => 7, 'description' => 'Second', 'terms' => array( array( 'name' => 'Refunds', 'content' => 'x' ) ) ),
		),
	)
);
$ok( 'length limits: title 40, text 100, term name 30', 40 === mb_strlen( $n['title'] ) && 100 === mb_strlen( $n['plans'][0]['description'] ) && 30 === mb_strlen( $n['plans'][0]['terms'][0]['name'] ) );
$ok( 'term content filtered (no scripts or event handlers), empty terms and plans dropped', ! str_contains( $n['plans'][0]['terms'][0]['content'], 'script' ) && ! str_contains( $n['plans'][0]['terms'][0]['content'], 'onclick' ) && str_contains( $n['plans'][0]['terms'][0]['content'], '<strong>there</strong>' ) && 1 === count( $n['plans'][0]['terms'] ) && 2 === count( $n['plans'] ) );
$ok( 'unknown pages removed, "false" means not ticked, duplicate id renumbered', array( 'checkout', 'login' ) === $n['plans'][0]['pages'] && false === $n['plans'][0]['checked'] && 7 === $n['plans'][0]['id'] && 8 === $n['plans'][1]['id'] );

// 2. 开关和显示页面。
$s = Terms::save(
	array(
		'enabled' => false,
		'title'   => 'Terms & Conditions',
		'plans'   => array(
			array( 'description' => 'I agree to the Terms of Service', 'pages' => array( 'checkout', 'register' ), 'checked' => false, 'terms' => array( array( 'name' => 'Terms of Service', 'content' => '<p>Research use only.</p>' ), array( 'name' => 'Refund Policy', 'content' => '<p>30 days.</p>' ) ) ),
			array( 'description' => 'I am over 21', 'pages' => array( 'login', 'checkout' ), 'checked' => true, 'terms' => array() ),
		),
	)
);
$ok( 'turned off: no checkboxes anywhere', array() === Terms::plans_for( 'checkout' ) && '' === Terms::checkboxes_html( 'register' ) );
$s['enabled'] = true;
$s            = Terms::save( $s );
[ $p1, $p2 ]  = $s['plans'];
$ok( 'turned on: each page gets its plans', 2 === count( Terms::plans_for( 'checkout' ) ) && array( $p1['id'] ) === array_column( Terms::plans_for( 'register' ), 'id' ) && array( $p2['id'] ) === array_column( Terms::plans_for( 'login' ), 'id' ) );

// 3. 区块结账：必选附加字段、默认勾选。
Terms::register_fields();
$fields = Package::container()->get( CheckoutFields::class )->get_additional_fields();
$f1     = $fields[ Terms::FIELD . $p1['id'] ] ?? null;
$ok( 'checkout plans become required checkout checkboxes', $f1 && 'checkbox' === $f1['type'] && true === $f1['required'] && 'I agree to the Terms of Service' === $f1['label'] && 'order' === $f1['location'] && isset( $fields[ Terms::FIELD . $p2['id'] ] ) );
$ok( 'ticked by default only where set', true === apply_filters( 'woocommerce_get_default_value_for_' . Terms::FIELD . $p2['id'], null, 'other', null ) && null === apply_filters( 'woocommerce_get_default_value_for_' . Terms::FIELD . $p1['id'], null, 'other', null ) );

// 4. 订单快照。
$order = wc_create_order();
Terms::save_snapshot( Terms::FIELD . $p1['id'], true, 'other', $order );
Terms::save_snapshot( Terms::FIELD . $p2['id'], false, 'other', $order );
$order->save();
$snap = wc_get_order( $order->get_id() )->get_meta( Terms::META );
$ok( 'order keeps what was accepted (text, term names, time)', is_array( $snap ) && 1 === count( $snap ) && 'I agree to the Terms of Service' === $snap[ $p1['id'] ]['description'] && array( 'Terms of Service', 'Refund Policy' ) === $snap[ $p1['id'] ]['terms'] && '' !== $snap[ $p1['id'] ]['accepted_at'] );
ob_start();
Terms::render_admin( wc_get_order( $order->get_id() ) );
$ok( 'admin order page shows the accepted terms', str_contains( (string) ob_get_clean(), 'Refund Policy' ) );
$order->delete( true );

// 5. 注册页、登录页。
$_POST = array();
$html  = Terms::checkboxes_html( 'register' );
$ok( 'registration form shows the checkbox, term links and dialogs', str_contains( $html, 'name="pc_terms[' . $p1['id'] . ']"' ) && 2 === substr_count( $html, 'class="pc-terms__link"' ) && 2 === substr_count( $html, '<dialog' ) && str_contains( $html, 'Research use only.' ) && str_contains( $html, 'Terms &amp; Conditions' ) );
$ok( 'login form shows its own plan, ticked by default', str_contains( Terms::checkboxes_html( 'login' ), 'name="pc_terms[' . $p2['id'] . ']" value="1" checked' ) );
$_POST = array();
$e     = Terms::validate_register( new WP_Error() );
$ok( 'registering without ticking is refused', $e->has_errors() && str_contains( $e->get_error_message(), 'Terms of Service' ) );
$_POST = array( 'pc_terms' => array( $p1['id'] => '1' ) );
$ok( 'registering with the box ticked passes', ! Terms::validate_register( new WP_Error() )->has_errors() );
$_POST = array();
$ok( 'login without ticking is refused', Terms::validate_login( new WP_Error() )->has_errors() );
$_POST = array( 'pc_terms' => array( $p2['id'] => '1' ) );
$ok( 'login with the box ticked passes', ! Terms::validate_login( new WP_Error() )->has_errors() );
$_POST = array();

// 6. 后台。
$_GET = array( 'page' => 'pc-system', 'section' => 'terms' );
ob_start();
Terms_Page::render();
$html = ob_get_clean();
$ok( 'admin page has the editor data, preview and clear button', str_contains( $html, 'pc-tm-data' ) && str_contains( $html, '"title":"Terms & Conditions"' ) && str_contains( $html, 'pc-tm-preview' ) && str_contains( $html, 'pc_terms_reset' ) && str_contains( $html, 'pc-terms__link' ) );
$_GET = array();
Terms::reset();
$ok( 'clear all data', ! get_option( Terms::OPTION ) && array() === Terms::all()['plans'] );
$customer = wc_create_new_customer( 'pc-terms-' . wp_rand() . '@example.com', '', wp_generate_password() );
wp_set_current_user( $customer );
ob_start();
Terms_Page::render();
$ok( 'customers see nothing', '' === ob_get_clean() );
wp_set_current_user( $admin->ID );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $customer );

// 恢复。
null === $orig ? delete_option( Terms::OPTION ) : update_option( Terms::OPTION, $orig );
$ok( 'settings restored', $orig === get_option( Terms::OPTION, null ) );
