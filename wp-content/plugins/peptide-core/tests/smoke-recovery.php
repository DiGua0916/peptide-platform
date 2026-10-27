<?php
/**
 * 弃单召回（CART-RECOVERY-01 / 02）冒烟测试：会话解析、只记录填了邮箱和地址的结账、三阶段提醒（默认 300 / 1800 / 86400 秒）、
 * 关闭某个阶段时跳过、改车重新计时、五种状态（待召回 / 召回中 / 召回成功 / 召回失败 / 已作废）、点击链接恢复购物车、
 * 付款结束、清空 / 过期、退订、员工不记录、管理员弃单通知、手动作废、列表筛选、v1 数据升级、后台页面。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-recovery.php
 * 邮件用 pre_wp_mail 拦截，不会真的发出。测试建的会话、记录、订单、优惠券在结束时删除，邮件设置、店铺地址和退订名单恢复原样。
 * 不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Recovery;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

global $wpdb;
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );

$email_ids  = array( 'pc_cart_recovery_1', 'pc_cart_recovery_2', 'pc_cart_recovery_3', 'pc_admin_abandoned' );
$orig_email = array();
foreach ( $email_ids as $id ) {
	$orig_email[ $id ] = get_option( 'woocommerce_' . $id . '_settings', null );
}
$orig_optout = get_option( Recovery::OPTOUT, null );
$orig_opts   = array();
foreach ( array( 'woocommerce_store_address', 'woocommerce_store_city', 'woocommerce_store_postcode', 'woocommerce_email_from_address' ) as $k ) {
	$orig_opts[ $k ] = get_option( $k, null );
}
$sessions = array();
$orders   = array();
$mails    = array();
add_filter(
	'pre_wp_mail',
	static function ( $null, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	10,
	2
);
$to_of = static fn( array $m ): string => strtolower( is_array( $m['to'] ) ? (string) reset( $m['to'] ) : (string) $m['to'] );

// 邮件设置：同时写选项并刷新已经加载的邮件对象（它们在构造时读取设置）。
$set_email = static function ( string $key, array $values ): void {
	$e = WC()->mailer()->get_emails()[ $key ];
	update_option( 'woocommerce_' . $e->id . '_settings', array_merge( (array) get_option( 'woocommerce_' . $e->id . '_settings', array() ), $values ) );
	$e->init_settings();
	$e->enabled = $e->get_option( 'enabled' );
};

$product = null;
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => 20 ) ) as $p ) {
	if ( $p->is_purchasable() && (float) $p->get_price() > 0 ) {
		$product = $p;
		break;
	}
}
$ok( 'found a test product', (bool) $product );
if ( ! $product ) {
	return;
}
$price = (float) $product->get_price();

$session = static function ( string $key, string $email, int $qty, bool $address = true ) use ( $wpdb, $product, $price, &$sessions ) {
	$lines = $qty ? array( md5( $key ) => array( 'key' => md5( $key ), 'product_id' => $product->get_id(), 'variation_id' => 0, 'variation' => array(), 'quantity' => $qty, 'line_subtotal' => $price * $qty, 'line_total' => $price * $qty ) ) : array();
	$cust  = array( 'email' => $email, 'first_name' => 'Smoke', 'last_name' => 'Tester', 'country' => 'US', 'shipping_country' => 'US' );
	if ( $address ) {
		$cust += array( 'address_1' => '1 Test Way', 'shipping_address_1' => '1 Test Way' );
	}
	$value = maybe_serialize(
		array(
			'cart'                    => maybe_serialize( $lines ),
			'cart_totals'             => maybe_serialize( array( 'subtotal' => (string) ( $price * $qty ), 'total' => (string) ( $price * $qty + 10 ) ) ),
			'customer'                => maybe_serialize( $cust ),
			'chosen_shipping_methods' => maybe_serialize( array( 'flat_rate:1' ) ),
		)
	);
	$wpdb->replace( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $key, 'session_value' => $value, 'session_expiry' => time() + DAY_IN_SECONDS * 3 ) ); // phpcs:ignore
	$sessions[] = $key;
};
$record = static fn( string $key ) => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Recovery::table() . ' WHERE session_key = %s ORDER BY id DESC LIMIT 1', $key ), ARRAY_A ); // phpcs:ignore
$now    = (int) floor( microtime( true ) * 1000 );
$sfx    = substr( md5( (string) $now ), 0, 8 );

// 1. 邮件与设置。
foreach ( array( 1, 2, 3 ) as $s ) {
	$set_email( 'PC_Email_Cart_Recovery_' . $s, array( 'enabled' => 'yes', 'delay' => '', 'coupon' => '' ) );
}
$set_email( 'PC_Email_Admin_Abandoned', array( 'enabled' => 'no' ) );
$ok( 'three stages with Shopname default delays 300 / 1800 / 86400 s', 300 === Recovery::email( 1 )->delay() && 1800 === Recovery::email( 2 )->delay() && 86400 === Recovery::email( 3 )->delay() );
foreach ( array( 1, 2, 3 ) as $s ) {
	$set_email( 'PC_Email_Cart_Recovery_' . $s, array( 'enabled' => 'no' ) );
}
$ok( 'all reminders off: nothing is scanned', ! Recovery::enabled() && 'disabled' === Recovery::scan( null, 'smoke_rc_' )['blocked'] );
foreach ( array( 1, 2, 3 ) as $s ) {
	$set_email( 'PC_Email_Cart_Recovery_' . $s, array( 'enabled' => 'yes' ) );
}
Recovery::schedule();
$event = wp_get_scheduled_event( Recovery::CRON );
$ok( 'reminders on: scan scheduled every 5 minutes', $event && 'pc_five_minutes' === $event->schedule );

// 2. 会话解析。
$p = Recovery::parse_session( 't_x', maybe_serialize( array( 'cart' => maybe_serialize( array( 'a' => array( 'product_id' => 5, 'quantity' => 2, 'line_subtotal' => 10 ) ) ), 'customer' => maybe_serialize( array( 'email' => 'Shopper@Example.com', 'shipping_country' => 'ca', 'shipping_address_1' => 'x' ) ), 'store_api_draft_order' => 77 ) ) );
$ok( 'session parsed: email, address flag, country, draft order', 'shopper@example.com' === $p['email'] && $p['has_address'] && 'CA' === $p['country'] && 77 === $p['draft_id'] && 2.0 === $p['items'][0]['quantity'] );

// 3. 只记录填了邮箱和地址的结账。
$keyN = 'smoke_rc_n_' . $sfx;
$session( $keyN, 'smoke-rc-n@example.com', 1, false );
Recovery::scan( $now, 'smoke_rc_' );
$ok( 'email without an address is not an abandoned checkout yet', ! $record( $keyN ) );
$keyA = 'smoke_rc_a_' . $sfx;
$session( $keyA, 'smoke-rc-a@example.com', 2 );
$r = Recovery::scan( $now, 'smoke_rc_' );
$a = $record( $keyA );
$ok( 'with email and address: recorded as waiting to recall', $r['tracked'] >= 1 && 'pending' === $a['status'] && 2 === (int) $a['items'] && 'US' === $a['country'] && 'Smoke Tester' === $a['name'] && '' !== $a['total'] );

// 4. 没有店铺地址时不发。
foreach ( array( 'woocommerce_store_address', 'woocommerce_store_city', 'woocommerce_store_postcode' ) as $k ) {
	update_option( $k, '' );
}
update_option( 'woocommerce_email_from_address', 'support@vialcove-test.example' );
$mails = array();
$r     = Recovery::scan( $now + 301000, 'smoke_rc_' );
$ok( 'no store address: nothing sent', 'address' === $r['blocked'] && ! $mails && 'pending' === $record( $keyA )['status'] );
update_option( 'woocommerce_store_address', '100 Test Street' );
update_option( 'woocommerce_store_city', 'Los Angeles' );
update_option( 'woocommerce_store_postcode', '90001' );

// 5. 三阶段。
$ok( 'not due before 300 s', 0 === Recovery::scan( $now + 200000, 'smoke_rc_' )['sent'] );
$mails = array();
Recovery::scan( $now + 301000, 'smoke_rc_' );
$a     = $record( $keyA );
$mailA = array_values( array_filter( $mails, static fn( $m ) => 'smoke-rc-a@example.com' === $to_of( $m ) ) );
$ok( 'stage 1 sent after 300 s: recalling, 1 email', 'recalling' === $a['status'] && 1 === (int) $a['stage'] && 1 === (int) $a['emails'] && 1 === count( $mailA ) && (int) $a['last_sent_ms'] === $now + 301000 );
$msg = $mailA[0]['message'] ?? '';
$ok( 'email has the recover link, unsubscribe link, store address and List-Unsubscribe', str_contains( $msg, 'pc_recover=' . $a['id'] . '.' ) && str_contains( $msg, 'pc_unsub=' ) && str_contains( $msg, '100 Test Street' ) && str_contains( is_array( $mailA[0]['headers'] ) ? implode( "\n", $mailA[0]['headers'] ) : (string) $mailA[0]['headers'], 'List-Unsubscribe' ) );
$ok( 'stage 1 not sent twice', 0 === Recovery::scan( $now + 600000, 'smoke_rc_' )['sent'] );
$mails = array();
Recovery::scan( $now + 1801000, 'smoke_rc_' );
$ok( 'stage 2 after 1800 s', 2 === (int) $record( $keyA )['stage'] && 2 === (int) $record( $keyA )['emails'] && 1 === count( $mails ) );
$mails = array();
Recovery::scan( $now + 86401000, 'smoke_rc_' );
$a = $record( $keyA );
$ok( 'stage 3 after 86400 s: 3 emails', 3 === (int) $a['stage'] && 3 === (int) $a['emails'] && 1 === count( $mails ) && 'recalling' === $a['status'] );
Recovery::scan( $now + 86401000 + 3600000, 'smoke_rc_' );
$ok( 'still recalling within 24 h of the last email', 'recalling' === $record( $keyA )['status'] );
Recovery::scan( $now + 86401000 + DAY_IN_SECONDS * 1000, 'smoke_rc_' );
$ok( 'no response 24 h after the last email: recall failed', 'failed' === $record( $keyA )['status'] && 'no response' === $record( $keyA )['note'] );

// 6. 关闭第二阶段：从第一封直接到第三封。
$set_email( 'PC_Email_Cart_Recovery_2', array( 'enabled' => 'no' ) );
$keyS = 'smoke_rc_s_' . $sfx;
$session( $keyS, 'smoke-rc-s@example.com', 1 );
Recovery::scan( $now, 'smoke_rc_' );
Recovery::scan( $now + 301000, 'smoke_rc_' );
$mails = array();
Recovery::scan( $now + 1801000, 'smoke_rc_' );
$ok( 'disabled stage 2 is skipped (nothing at 1800 s)', ! $mails && 1 === (int) $record( $keyS )['stage'] );
Recovery::scan( $now + 86401000, 'smoke_rc_' );
$ok( 'then stage 3 is sent', 3 === (int) $record( $keyS )['stage'] && 2 === (int) $record( $keyS )['emails'] );
$set_email( 'PC_Email_Cart_Recovery_2', array( 'enabled' => 'yes' ) );

// 7. 改车重新计时。
$keyB = 'smoke_rc_b_' . $sfx;
$session( $keyB, 'smoke-rc-b@example.com', 1 );
Recovery::scan( $now, 'smoke_rc_' );
$session( $keyB, 'smoke-rc-b@example.com', 3 );
Recovery::scan( $now + 250000, 'smoke_rc_' );
Recovery::scan( $now + 400000, 'smoke_rc_' );
$ok( 'cart change restarts the timer (no email 400 s after the first sighting)', $now + 250000 === (int) $record( $keyB )['changed_ms'] && 3 === (int) $record( $keyB )['items'] && 0 === (int) $record( $keyB )['emails'] );

// 8. 付款：提醒之后 → 召回成功；提醒之前 → 已作废。
$keyP = 'smoke_rc_p_' . $sfx;
$session( $keyP, 'smoke-rc-p@example.com', 2 );
Recovery::scan( $now, 'smoke_rc_' );
$rp = $record( $keyP );
$wpdb->update( Recovery::table(), array( 'sent1_ms' => $now - 60000, 'emails' => 1, 'stage' => 1, 'status' => 'recalling', 'created_ms' => $now - 120000 ), array( 'id' => (int) $rp['id'] ) ); // phpcs:ignore
$order = wc_create_order();
$order->add_product( $product, 2 );
$order->set_billing_email( 'smoke-rc-p@example.com' );
$order->set_transaction_id( 'SMOKE-TX-1' );
$order->calculate_totals();
$order->set_status( 'processing' );
$order->save();
$orders[] = $order->get_id();
$rp       = $record( $keyP );
$ok( 'paid after a reminder: recalled, order and payment time kept', 'success' === $rp['status'] && 'paid' === $rp['note'] && $order->get_id() === (int) $rp['order_id'] && (int) $rp['paid_ms'] > 0 );
$order2 = wc_create_order();
$order2->add_product( $product, 1 );
$order2->set_billing_email( 'smoke-rc-b@example.com' );
$order2->calculate_totals();
$order2->set_status( 'processing' );
$order2->save();
$orders[] = $order2->get_id();
$ok( 'paid before any reminder: voided', 'void' === $record( $keyB )['status'] && 'paid before reminder' === $record( $keyB )['note'] );

// 9. 清空、过期、员工、退订。
$keyC = 'smoke_rc_c_' . $sfx;
$session( $keyC, 'smoke-rc-c@example.com', 1 );
Recovery::scan( $now, 'smoke_rc_' );
$session( $keyC, 'smoke-rc-c@example.com', 0 );
Recovery::scan( $now + 60000, 'smoke_rc_' );
$ok( 'emptied cart: voided', 'void' === $record( $keyC )['status'] && 'cart emptied' === $record( $keyC )['note'] );
$keyD = 'smoke_rc_d_' . $sfx;
$session( $keyD, 'smoke-rc-d@example.com', 1 );
Recovery::scan( $now, 'smoke_rc_' );
$wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $keyD ) ); // phpcs:ignore
Recovery::scan( $now + 60000, 'smoke_rc_' );
$ok( 'session gone before any email: voided', 'void' === $record( $keyD )['status'] );
$keyX = 'smoke_rc_x_' . $sfx;
$session( $keyX, 'smoke-rc-x@example.com', 1 );
Recovery::scan( $now, 'smoke_rc_' );
Recovery::scan( $now + 301000, 'smoke_rc_' );
$wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $keyX ) ); // phpcs:ignore
Recovery::scan( $now + 400000, 'smoke_rc_' );
$ok( 'session gone after an email: recall failed', 'failed' === $record( $keyX )['status'] && 'session expired' === $record( $keyX )['note'] );

$staff = wp_insert_user( array( 'user_login' => 'smoke_rc_staff_' . $sfx, 'user_email' => 'smoke-rc-staff-' . $sfx . '@example.com', 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$session( (string) $staff, 'smoke-rc-staff-' . $sfx . '@example.com', 1 );
Recovery::scan( $now, (string) $staff ); // 会话键是用户 ID，只扫描这一个会话。
$ok( 'staff accounts are never recorded', ! $record( (string) $staff ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $staff );

$keyE = 'smoke_rc_e_' . $sfx;
$session( $keyE, 'smoke-rc-e@example.com', 1 );
Recovery::scan( $now, 'smoke_rc_' );
Recovery::unsubscribe( 'smoke-rc-e@example.com' );
$ok( 'unsubscribe voids open records', 'void' === $record( $keyE )['status'] && 'unsubscribed' === $record( $keyE )['note'] );
$keyF = 'smoke_rc_f_' . $sfx;
$session( $keyF, 'SMOKE-RC-E@example.com', 1 );
Recovery::scan( $now, 'smoke_rc_' );
$mails = array();
Recovery::scan( $now + 301000, 'smoke_rc_' );
$ok( 'unsubscribed email is never reminded again', ! array_filter( $mails, static fn( $m ) => 'smoke-rc-e@example.com' === $to_of( $m ) ) && 'void' === $record( $keyF )['status'] );

// 10. 点击链接回来：召回成功，购物车恢复一次，优惠码按最近一封邮件的设置。
$coupon = new WC_Coupon();
$coupon->set_code( 'smokercpn' . $sfx );
$coupon->set_amount( 5 );
$coupon->save();
$set_email( 'PC_Email_Cart_Recovery_1', array( 'coupon' => 'smokercpn' . $sfx ) );
wp_set_current_user( 0 );
wc_load_cart();
WC()->cart->empty_cart();
$keyG = 'smoke_rc_g_' . $sfx;
$session( $keyG, 'smoke-rc-g@example.com', 2 );
Recovery::scan( $now, 'smoke_rc_' );
$mails = array();
Recovery::scan( $now + 301000, 'smoke_rc_' );
$ok( 'coupon shown in the stage email', str_contains( (string) ( $mails[0]['message'] ?? '' ), strtoupper( 'smokercpn' . $sfx ) ) );
$rg    = $record( $keyG );
$added = Recovery::restore( $rg );
$again = Recovery::restore( $record( $keyG ) );
$ok( 'link restores the cart once, without duplicates', 1 === $added && 0 === $again && 2 === (int) WC()->cart->get_cart_contents_count() );
$ok( 'clicking back counts as recalled', 'success' === $record( $keyG )['status'] && 'clicked' === $record( $keyG )['note'] && (int) $record( $keyG )['clicked_ms'] > 0 );
$ok( 'stage coupon applied or kept for checkout', WC()->cart->has_discount( 'smokercpn' . $sfx ) || 'smokercpn' . $sfx === wc_format_coupon_code( (string) WC()->session->get( 'pc_pending_coupon' ) ) );
WC()->cart->empty_cart();
wc_clear_notices();
wp_set_current_user( $admin->ID );
$set_email( 'PC_Email_Cart_Recovery_1', array( 'coupon' => '' ) );
$order3 = wc_create_order();
$order3->add_product( $product, 2 );
$order3->set_billing_email( 'smoke-rc-g@example.com' );
$order3->calculate_totals();
$order3->set_status( 'processing' );
$order3->save();
$orders[] = $order3->get_id();
$ok( 'a clicked record keeps the order paid afterwards', 'success' === $record( $keyG )['status'] && $order3->get_id() === (int) $record( $keyG )['order_id'] );

// 11. 管理员弃单通知。
$set_email( 'PC_Email_Admin_Abandoned', array( 'enabled' => 'yes', 'recipient' => 'smoke-admin@vialcove-test.example', 'delay' => '300' ) );
$keyH = 'smoke_rc_h_' . $sfx;
$session( $keyH, 'smoke-rc-h@example.com', 1 );
Recovery::scan( $now, 'smoke_rc_' );
$mails = array();
Recovery::scan( $now + 301000, 'smoke_rc_' );
$admin_mails = array_filter( $mails, static fn( $m ) => 'smoke-admin@vialcove-test.example' === $to_of( $m ) && str_contains( (string) $m['message'], 'smoke-rc-h@example.com' ) );
$ok( 'admin notified once about the new abandoned checkout', 1 === count( $admin_mails ) && (int) $record( $keyH )['admin_ms'] > 0 );
$mails = array();
Recovery::scan( $now + 600000, 'smoke_rc_' );
$ok( 'admin notice not repeated', ! array_filter( $mails, static fn( $m ) => 'smoke-admin@vialcove-test.example' === $to_of( $m ) ) );
$set_email( 'PC_Email_Admin_Abandoned', array( 'enabled' => 'no' ) );

// 12. 手动作废、列表、统计。
$ok( 'manual void', Recovery::void( (int) $record( $keyH )['id'] ) && 'void' === $record( $keyH )['status'] && 'manual' === $record( $keyH )['note'] );
$counts = Recovery::counts();
$ok( 'status counts', $counts['success'] >= 2 && $counts['failed'] >= 2 && $counts['void'] >= 5 && $counts['recalling'] >= 1 );
$f = Recovery::records( array( 'status' => 'failed', 'q' => 'smoke-rc-a@' ) );
$ok( 'list filters by status and email', 1 === $f['total'] && 'smoke-rc-a@example.com' === $f['items'][0]['email'] );
$by_order = Recovery::records( array( 'q' => (string) $order->get_id() ) )['items'];
$ok( 'list filters by order number', in_array( $order->get_id(), array_map( 'intval', array_column( $by_order, 'order_id' ) ), true ) && ! in_array( 'smoke-rc-a@example.com', array_column( $by_order, 'email' ), true ) );
$st = Recovery::stats( $now - DAY_IN_SECONDS * 1000, $now + DAY_IN_SECONDS * 1000 * 3 );
$ok( 'stats count abandoned, emailed, recalled, paid amount', $st['abandoned'] >= 8 && $st['emailed'] >= 4 && $st['recovered'] >= 2 && $st['recovered_total'] > 0 );

$_GET = array( 'page' => \PeptideCore\Admin\Recovery_Page::SLUG );
ob_start();
\PeptideCore\Admin\Recovery_Page::render();
$html = ob_get_clean();
$ok( 'admin list shows the status tabs, records, details and transaction id', str_contains( $html, 'smoke-rc-a@example.com' ) && str_contains( $html, 'Waiting to recall' ) && str_contains( $html, 'Recalling' ) && str_contains( $html, 'Recall failed' ) && str_contains( $html, 'Recalled' ) && str_contains( $html, 'Voided' ) && str_contains( $html, 'pc-rc-detail' ) && str_contains( $html, 'SMOKE-TX-1' ) );
$_GET = array( 'page' => \PeptideCore\Admin\Recovery_Page::SLUG, 'status' => 'success' );
ob_start();
\PeptideCore\Admin\Recovery_Page::render();
$html = ob_get_clean();
$ok( 'status tab shows only that status', str_contains( $html, 'smoke-rc-p@example.com' ) && ! str_contains( $html, 'smoke-rc-a@example.com' ) );
$_GET = array();

// 13. v1 数据升级。
$t = Recovery::table();
$wpdb->insert( $t, array( 'session_key' => 'smoke_rc_v1a_' . $sfx, 'email' => 'smoke-rc-v1@example.com', 'status' => 'open', 'stage' => 1, 'sent1_ms' => 5 ) ); // phpcs:ignore
$wpdb->insert( $t, array( 'session_key' => 'smoke_rc_v1b_' . $sfx, 'email' => 'smoke-rc-v1@example.com', 'status' => 'recovered', 'stage' => 1 ) ); // phpcs:ignore
$wpdb->insert( $t, array( 'session_key' => 'smoke_rc_v1c_' . $sfx, 'email' => 'smoke-rc-v1@example.com', 'status' => 'expired', 'stage' => 0 ) ); // phpcs:ignore
update_option( Recovery::DB_OPTION, '1', false );
Recovery::install();
$ok( 'v1 statuses upgraded (open → recalling, recovered → success, expired → void)', 'recalling' === $record( 'smoke_rc_v1a_' . $sfx )['status'] && 1 === (int) $record( 'smoke_rc_v1a_' . $sfx )['emails'] && 'success' === $record( 'smoke_rc_v1b_' . $sfx )['status'] && 'void' === $record( 'smoke_rc_v1c_' . $sfx )['status'] && Recovery::installed() );
$sessions = array_merge( $sessions, array( 'smoke_rc_v1a_' . $sfx, 'smoke_rc_v1b_' . $sfx, 'smoke_rc_v1c_' . $sfx ) );

// 14. 清理。
foreach ( array_unique( $sessions ) as $k ) {
	$wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $k ) ); // phpcs:ignore
	$wpdb->delete( Recovery::table(), array( 'session_key' => $k ) ); // phpcs:ignore
}
foreach ( $orders as $id ) {
	$o = wc_get_order( $id );
	if ( $o ) {
		$o->delete( true );
	}
}
$coupon->delete( true );
foreach ( $orig_opts as $k => $v ) {
	null === $v ? delete_option( $k ) : update_option( $k, $v );
}
foreach ( $orig_email as $id => $v ) {
	null === $v ? delete_option( 'woocommerce_' . $id . '_settings' ) : update_option( 'woocommerce_' . $id . '_settings', $v );
}
null === $orig_optout ? delete_option( Recovery::OPTOUT ) : update_option( Recovery::OPTOUT, $orig_optout, false );
$ok( 'test data removed and settings restored', 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Recovery::table() . " WHERE email LIKE 'smoke-rc-%'" ) && $orig_opts['woocommerce_store_address'] === get_option( 'woocommerce_store_address', null ) ); // phpcs:ignore
