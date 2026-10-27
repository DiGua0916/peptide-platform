<?php
/**
 * 邮件通知（EMAIL-CENTER-01）冒烟测试：插件邮件注册到 WooCommerce、预览、开关、编辑链接、发送记录与统计、
 * 潜在重复支付、线下付款订单通知（以及不再重复发“新订单”）、注册邀请、注册邮箱验证、购物发现、退订链接、后台页面与权限。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-emails.php
 * 邮件用 pre_wp_mail 拦截，不会真的发出。测试建的订单、用户和发送记录在结束时删除，邮件设置和店铺设置恢复原样。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Email_Center;
use PeptideCore\Emails\Log;
use PeptideCore\Emails\Registry;
use PeptideCore\Store_Config;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

global $wpdb;
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
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
$to_of   = static fn( array $m ): string => strtolower( is_array( $m['to'] ) ? (string) reset( $m['to'] ) : (string) $m['to'] );
$emails  = WC()->mailer()->get_emails();
$ids     = array( 'pc_admin_duplicate', 'pc_admin_offline', 'pc_register_invite', 'pc_browse_reminder', 'pc_register_verify', 'customer_note' );
$orig    = array();
foreach ( $ids as $id ) {
	$orig[ 'woocommerce_' . $id . '_settings' ] = get_option( 'woocommerce_' . $id . '_settings', null );
}
foreach ( array( 'woocommerce_enable_myaccount_registration', 'woocommerce_store_address', 'woocommerce_store_city', 'woocommerce_store_postcode', 'woocommerce_email_from_address', Registry::INVITED, Store_Config::OPTION, 'peptide_core_recovery_optout' ) as $k ) {
	$orig[ $k ] = get_option( $k, null );
}
$set_email = static function ( string $key, array $values ) use ( $emails ): void {
	$e = $emails[ $key ];
	update_option( 'woocommerce_' . $e->id . '_settings', array_merge( (array) get_option( 'woocommerce_' . $e->id . '_settings', array() ), $values ) );
	$e->init_settings();
	$e->enabled = $e->get_option( 'enabled' );
};
update_option( 'woocommerce_email_from_address', 'support@vialcove-test.example' );
update_option( 'woocommerce_store_address', '100 Test Street' );
update_option( 'woocommerce_store_city', 'Los Angeles' );
update_option( 'woocommerce_store_postcode', '90001' );
$orders  = array();
$users   = array();
$log_min = (int) floor( microtime( true ) * 1000 );

$product = null;
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => 20 ) ) as $p ) {
	if ( $p->is_purchasable() && (float) $p->get_price() > 0 ) {
		$product = $p;
		break;
	}
}
$new_order = static function ( string $email, string $status, int $qty = 1, string $method = 'bacs' ) use ( $product, &$orders ): WC_Order {
	$o = wc_create_order();
	$o->add_product( $product, $qty );
	$o->set_billing_email( $email );
	$o->set_payment_method( $method );
	$o->set_payment_method_title( 'bacs' === $method ? 'Direct bank transfer' : 'Card' );
	$o->calculate_totals();
	$o->save();
	$orders[] = $o->get_id();
	$o->update_status( $status );
	return wc_get_order( $o->get_id() );
};

// 1. 注册与预览。
$ok( 'all nine plugin emails are registered in WooCommerce', 9 === count( array_intersect( Registry::CLASSES, array_keys( $emails ) ) ) );
$ok( 'reminders on by default, admin notices off by default', $emails['PC_Email_Browse_Reminder']->form_fields['enabled']['default'] === 'yes' && 'no' === $emails['PC_Email_Admin_Offline']->form_fields['enabled']['default'] );
$preview = wc_get_container()->get( Automattic\WooCommerce\Internal\Admin\EmailPreview\EmailPreview::class );
$broken  = array();
foreach ( Registry::CLASSES as $k ) {
	try {
		$preview->set_email_type( $k );
		if ( strlen( $preview->render() ) < 2000 ) {
			$broken[] = $k;
		}
	} catch ( Throwable $t ) {
		$broken[] = $k . ':' . $t->getMessage();
	}
}
$ok( 'every plugin email renders a preview', array() === $broken );
$ok( 'edit link opens the WooCommerce email section', str_ends_with( Email_Center::edit_url( 'PC_Email_Cart_Recovery_1' ), 'section=pc_email_cart_recovery_1' ) && sanitize_title( 'pc_email_cart_recovery_1' ) === strtolower( 'PC_Email_Cart_Recovery_1' ) );
$ok( 'preview link uses a class name without backslashes', str_contains( Email_Center::preview_url( 'PC_Email_Browse_Reminder' ), 'type=PC_Email_Browse_Reminder' ) && str_contains( Email_Center::preview_url( 'X' ), '_wpnonce=' ) );

// 2. 开关。
$ok( 'toggle writes the WooCommerce email setting', Email_Center::set_enabled( 'WC_Email_Customer_Note', false ) && 'no' === get_option( 'woocommerce_customer_note_settings' )['enabled'] );
$ok( 'unknown emails cannot be toggled', ! Email_Center::set_enabled( 'WC_Email_Not_Real', true ) );

// 3. 线下付款订单通知：开启后“新订单”不再为保留订单另发。
$set_email( 'PC_Email_Admin_Offline', array( 'enabled' => 'yes', 'recipient' => 'smoke-ops@vialcove-test.example' ) );
Registry::adjust_new_order( WC()->mailer() );
$ok( 'WooCommerce new order no longer fires for on-hold orders', false === has_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $emails['WC_Email_New_Order'], 'trigger' ) ) && false !== has_action( 'woocommerce_order_status_pending_to_processing_notification', array( $emails['WC_Email_New_Order'], 'trigger' ) ) );
$mails = array();
$off   = $new_order( 'smoke-em-offline@example.com', 'on-hold' );
$ops   = array_values( array_filter( $mails, static fn( $m ) => 'smoke-ops@vialcove-test.example' === $to_of( $m ) ) );
$ok( 'offline payment order notifies the admin once', 1 === count( $ops ) && str_contains( (string) $ops[0]['message'], 'Direct bank transfer' ) && str_contains( (string) $ops[0]['subject'], (string) $off->get_order_number() ) );

// 4. 潜在重复支付。
$set_email( 'PC_Email_Admin_Duplicate', array( 'enabled' => 'yes', 'recipient' => '', 'window' => '24' ) );
Store_Config::update( array( 'finance_email' => 'smoke-finance@vialcove-test.example' ) );
$mails = array();
$first = $new_order( 'smoke-em-dup@example.com', 'processing', 2, 'card' );
$ok( 'first payment: no duplicate alert', ! array_filter( $mails, static fn( $m ) => 'smoke-finance@vialcove-test.example' === $to_of( $m ) ) );
$second = $new_order( 'smoke-em-dup@example.com', 'processing', 2, 'card' );
$fin    = array_values( array_filter( $mails, static fn( $m ) => 'smoke-finance@vialcove-test.example' === $to_of( $m ) ) );
$ok( 'same customer, same total within 24 h: alert to the finance email', 1 === count( $fin ) && str_contains( (string) $fin[0]['message'], (string) $first->get_order_number() ) && str_contains( (string) $fin[0]['message'], (string) $second->get_order_number() ) );
$mails = array();
$new_order( 'smoke-em-dup@example.com', 'processing', 3, 'card' );
$ok( 'different total: no alert', ! array_filter( $mails, static fn( $m ) => 'smoke-finance@vialcove-test.example' === $to_of( $m ) ) );
$second->update_status( 'completed' );
$ok( 'each order is checked only once', 1 === count( $fin ) && 1 === (int) wc_get_order( $second->get_id() )->get_meta( '_pc_paid_emails' ) );

// 5. 注册邀请（只在账户页开放注册时发，每个邮箱一次）。
update_option( 'woocommerce_enable_myaccount_registration', 'no' );
$mails = array();
$new_order( 'smoke-em-guest@example.com', 'processing' );
$ok( 'registration closed: no invitation', ! array_filter( $mails, static fn( $m ) => 'smoke-em-guest@example.com' === $to_of( $m ) && str_contains( (string) $m['subject'], 'Join' ) ) );
update_option( 'woocommerce_enable_myaccount_registration', 'yes' );
$set_email( 'PC_Email_Register_Invite', array( 'enabled' => 'yes' ) );
$mails = array();
$new_order( 'smoke-em-guest2@example.com', 'processing' );
$new_order( 'smoke-em-guest2@example.com', 'processing', 2 );
$inv = array_filter( $mails, static fn( $m ) => 'smoke-em-guest2@example.com' === $to_of( $m ) && str_contains( (string) $m['subject'], 'Join' ) );
$ok( 'guest invited once after paying', 1 === count( $inv ) );

// 6. 注册邮箱验证。
$set_email( 'PC_Email_Register_Verify', array( 'enabled' => 'yes' ) );
$cust    = wc_create_new_customer( 'smoke-em-verify-' . wp_rand() . '@example.com', '', wp_generate_password() );
$users[] = $cust;
$mails   = array();
$url     = Store_Config::send_verification( $cust );
$ver     = array_values( array_filter( $mails, static fn( $m ) => get_userdata( $cust )->user_email === $to_of( $m ) ) );
$ok( 'verification email carries the link', 1 === count( $ver ) && str_contains( html_entity_decode( (string) $ver[0]['message'] ), $url ) );

// 7. 购物发现。
$set_email( 'PC_Email_Browse_Reminder', array( 'enabled' => 'yes', 'delay' => '1800' ) );
$buyer   = wc_create_new_customer( 'smoke-em-browse-' . wp_rand() . '@example.com', '', wp_generate_password() );
$users[] = $buyer;
$ok( 'registration schedules the discovery check', (bool) wp_next_scheduled( Registry::BROWSE_EVENT, array( $buyer ) ) );
wp_clear_scheduled_hook( Registry::BROWSE_EVENT, array( $buyer ) );
$ok( 'nothing to show: no email', ! Registry::browse_check( $buyer ) );
update_user_meta( $buyer, '_woocommerce_persistent_cart_' . get_current_blog_id(), array( 'cart' => array( 'k' => array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 1 ) ) ) );
$mails = array();
$ok( 'new customer without orders gets the products they added', Registry::browse_check( $buyer ) && 1 === count( $mails ) && str_contains( (string) $mails[0]['message'], esc_html( $product->get_name() ) ) && str_contains( (string) $mails[0]['message'], 'pc_optout=' ) );
$ok( 'sent only once', ! Registry::browse_check( $buyer ) );
$cust2   = wc_create_new_customer( 'smoke-em-browse2-' . wp_rand() . '@example.com', '', wp_generate_password() );
$users[] = $cust2;
wp_clear_scheduled_hook( Registry::BROWSE_EVENT, array( $cust2 ) );
update_user_meta( $cust2, '_woocommerce_persistent_cart_' . get_current_blog_id(), array( 'cart' => array( 'k' => array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 1 ) ) ) );
$new_order( get_userdata( $cust2 )->user_email, 'processing' );
$ok( 'customers who already ordered get nothing', ! Registry::browse_check( $cust2 ) );

// 8. 退订链接。
$link  = Registry::optout_url( 'Smoke-Em-Out@Example.com' );
parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $q );
$ok( 'unsubscribe link is signed', 'smoke-em-out@example.com' === Registry::optout_email( (string) $q['pc_optout'] ) && '' === Registry::optout_email( 'c21va2VAZXhhbXBsZS5jb20.deadbeef' ) );
\PeptideCore\Recovery::unsubscribe( 'smoke-em-out@example.com' );
$ok( 'unsubscribed customers are skipped', \PeptideCore\Recovery::is_opted_out( 'SMOKE-EM-OUT@example.com' ) );

// 9. 发送记录。
$st = Log::stats( $log_min );
$ok( 'sent emails are logged by type', ( $st['pc_admin_offline']['sent'] ?? 0 ) >= 1 && ( $st['pc_admin_duplicate']['sent'] ?? 0 ) >= 1 && ( $st['pc_browse_reminder']['sent'] ?? 0 ) >= 1 );
$recent = Log::recent( 1, 5 );
$ok( 'latest log entries have recipient and subject', $recent['items'] && '' !== $recent['items'][0]['recipient'] && '' !== $recent['items'][0]['subject'] );

// 10. 页面。
foreach ( array( 'customer' => 'WC_Email_Customer_Processing_Order', 'abandoned' => 'PC_Email_Cart_Recovery_3', 'browse' => 'PC_Email_Browse_Reminder', 'admin' => 'PC_Email_Admin_Duplicate' ) as $tab => $key ) {
	$_GET = array( 'page' => 'pc-system', 'section' => 'email', 'tab' => $tab );
	ob_start();
	Email_Center::render();
	$html = ob_get_clean();
	$ok( "tab {$tab} lists its emails with preview and switch", str_contains( $html, 'type=' . $key ) && str_contains( $html, 'pc_email_toggle' ) );
}
$_GET = array( 'page' => 'pc-system', 'section' => 'email', 'tab' => 'log' );
ob_start();
Email_Center::render();
$html = ob_get_clean();
$ok( 'delivery data tab shows counts and the latest emails', str_contains( $html, 'smoke-ops@vialcove-test.example' ) || str_contains( $html, 'smoke-finance@vialcove-test.example' ) );
$_GET = array();

// 清理。
foreach ( $orders as $id ) {
	$o = wc_get_order( $id );
	if ( $o ) {
		$o->delete( true );
	}
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $users as $id ) {
	wp_clear_scheduled_hook( Registry::BROWSE_EVENT, array( $id ) );
	wp_delete_user( $id );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Log::table() . " WHERE sent_ms >= %d AND ( recipient LIKE %s OR recipient LIKE %s OR recipient LIKE %s )", $log_min, '%smoke-%', '%@example.com', '%vialcove-test.example' ) ); // phpcs:ignore
foreach ( $orig as $k => $v ) {
	null === $v ? delete_option( $k ) : update_option( $k, $v );
}
$ok( 'settings restored', $orig['woocommerce_email_from_address'] === get_option( 'woocommerce_email_from_address', null ) && $orig['woocommerce_customer_note_settings'] === get_option( 'woocommerce_customer_note_settings', null ) );
