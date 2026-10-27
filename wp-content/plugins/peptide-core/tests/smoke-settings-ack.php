<?php
/**
 * 设置页与结账确认项冒烟测试（BE-05，开发环境专用）。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-settings-ack.php
 * 会临时修改插件设置并创建一个测试订单，结束时恢复原设置并删除订单。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

// 测试中创建的订单不发送通知邮件。
add_filter( 'pre_wp_mail', '__return_false' );

use PeptideCore\Checkout_Ack;
use PeptideCore\Settings;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$original = get_option( Settings::OPTION, null );

// 1. 清理与校验。
$clean = Settings::sanitize(
	array(
		'brand_name'           => '',
		'demo_mode'            => '1',
		'demo_notice'          => ' Demo <b>notice</b> ',
		'use_statement_html'   => 'For <strong>testing</strong><script>alert(1)</script>',
		'checkout_ack_enabled' => '1',
		'checkout_ack_label'   => '',
	)
);
$ok( 'empty brand keeps previous brand', 'VialCove' === $clean['brand_name'] );
$ok( 'script stripped from use statement', ! str_contains( (string) $clean['use_statement_html'], '<script' ) && str_contains( (string) $clean['use_statement_html'], '<strong>' ) );
$ok( 'demo notice is plain text', 'Demo notice' === $clean['demo_notice'] );
$ok( 'ack not enabled without label', false === $clean['checkout_ack']['enabled'] );

$clean = Settings::sanitize(
	array(
		'brand_name'           => 'VialCove',
		'demo_mode'            => '',
		'checkout_ack_enabled' => '1',
		'checkout_ack_label'   => "SMOKE TEST: I confirm <b>this</b>.",
	)
);
$ok( 'ack enabled with label', true === $clean['checkout_ack']['enabled'] );
$ok( 'ack label is plain text', 'SMOKE TEST: I confirm this.' === $clean['checkout_ack']['label_html'] );
$ok( 'demo mode can be turned off', false === $clean['demo_mode'] );

// 2. 保存后：/site 与结账字段。
update_option( Settings::OPTION, $clean );
$site = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/site' ) )->get_data();
$ok( '/site reports ack enabled with label', true === $site['checkout_ack']['enabled'] && 'SMOKE TEST: I confirm this.' === $site['checkout_ack']['label_html'] );
$ok( '/site hides demo notice when demo mode off', false === $site['demo_mode'] && null === $site['demo_notice'] );

Checkout_Ack::register_field();
$fields = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class )->get_additional_fields();
$field  = $fields[ Checkout_Ack::FIELD_ID ] ?? null;
$ok( 'checkout field registered', is_array( $field ) );
$ok( 'checkout field is required checkbox in order location', $field && 'checkbox' === $field['type'] && true === $field['required'] && 'order' === $field['location'] );

// 3. 勾选后订单保存文案快照。
$order = wc_create_order();
do_action( 'woocommerce_set_additional_field_value', Checkout_Ack::FIELD_ID, true, 'other', $order );
$order->save();
$snap = wc_get_order( $order->get_id() )->get_meta( Checkout_Ack::SNAPSHOT_META );
$ok( 'snapshot stores label', is_array( $snap ) && 'SMOKE TEST: I confirm this.' === $snap['label'] );
$ok( 'snapshot stores UTC time', is_array( $snap ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $snap['accepted_at'] ) );

ob_start();
Checkout_Ack::render_admin( wc_get_order( $order->get_id() ) );
$ok( 'admin order page shows confirmation', str_contains( (string) ob_get_clean(), 'SMOKE TEST: I confirm this.' ) );

// 4. 权限：管理员有设置权限，店铺经理没有。
$ok( 'administrator can manage settings', get_role( 'administrator' )->has_cap( 'pc_manage_settings' ) );
$ok( 'shop_manager cannot manage settings', ! get_role( 'shop_manager' )->has_cap( 'pc_manage_settings' ) );

// 还原。
$order->delete( true );
if ( null === $original ) {
	delete_option( Settings::OPTION );
} else {
	update_option( Settings::OPTION, $original );
}
$ok( 'cleanup: settings restored', false === Settings::public_config()['checkout_ack']['enabled'] && true === Settings::public_config()['demo_mode'] );
