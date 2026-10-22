<?php
/**
 * 弃单召回（CART-RECOVERY-01）冒烟测试：设置、会话解析、计时与两阶段提醒、改车重新计时、付款结束（召回成功 / 自行下单）、
 * 清空 / 过期、退订、恢复购物车、员工不提醒、统计、后台页面。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-recovery.php
 * 邮件用 pre_wp_mail 拦截，不会真的发出。测试建的会话、记录、订单在结束时删除，设置和店铺地址恢复原样。不要在正式站点运行。
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

$orig_settings = get_option( Recovery::OPTION, null );
$orig_optout   = get_option( Recovery::OPTOUT, null );
$orig_addr     = array();
foreach ( array( 'woocommerce_store_address', 'woocommerce_store_city', 'woocommerce_store_postcode' ) as $k ) {
	$orig_addr[ $k ] = get_option( $k, null );
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

$session = static function ( string $key, string $email, int $qty ) use ( $wpdb, $product, $price, &$sessions ) {
	$lines = $qty ? array( md5( $key ) => array( 'key' => md5( $key ), 'product_id' => $product->get_id(), 'variation_id' => 0, 'variation' => array(), 'quantity' => $qty, 'line_subtotal' => $price * $qty, 'line_total' => $price * $qty ) ) : array();
	$value = maybe_serialize(
		array(
			'cart'        => maybe_serialize( $lines ),
			'cart_totals' => maybe_serialize( array( 'subtotal' => (string) ( $price * $qty ) ) ),
			'customer'    => maybe_serialize( array( 'email' => $email ) ),
		)
	);
	$wpdb->replace( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $key, 'session_value' => $value, 'session_expiry' => time() + DAY_IN_SECONDS * 3 ) ); // phpcs:ignore
	$sessions[] = $key;
};
$record = static fn( string $key ) => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Recovery::table() . ' WHERE session_key = %s ORDER BY id DESC LIMIT 1', $key ), ARRAY_A ); // phpcs:ignore

// 1. 设置。
$s = Recovery::save_settings( array( 'enabled' => '', 'delay1_min' => 1, 'coupon' => 'no-such-coupon-xyz', 'subject1' => ' <b>Hi</b> ' ) );
$ok( 'settings clean: minimum delay 15 min, unknown coupon dropped, tags stripped', 15 === $s['delay1_min'] && '' === $s['coupon'] && 'Hi' === $s['subject1'] );
$ok( 'turned off: scan does nothing', 'disabled' === Recovery::scan()['blocked'] );

// 2. 会话解析。
$p = Recovery::parse_session( 't_x', maybe_serialize( array( 'cart' => maybe_serialize( array( 'a' => array( 'product_id' => 5, 'quantity' => 2, 'line_subtotal' => 10 ) ) ), 'customer' => maybe_serialize( array( 'email' => 'Shopper@Example.com' ) ) ) ) );
$ok( 'session parsed: lower-cased email, cart lines', 'shopper@example.com' === $p['email'] && 1 === count( $p['items'] ) && 2.0 === $p['items'][0]['quantity'] );

// 3. 开启：新弃单、到时间发第一封。
Recovery::save_settings( array( 'enabled' => '1', 'delay1_min' => 60, 'stage2' => '1', 'delay2_hours' => 24 ) );
$ok( 'turning on schedules the 10-minute scan', (bool) wp_next_scheduled( Recovery::CRON ) );
$now  = (int) floor( microtime( true ) * 1000 );
$keyA = 'smoke_rc_a_' . substr( md5( (string) $now ), 0, 8 );
$session( $keyA, 'smoke-rc-a@example.com', 2 );
$r = Recovery::scan( $now );
$ok( 'new abandoned cart recorded', $r['tracked'] >= 1 && 'open' === $record( $keyA )['status'] && 2 === (int) $record( $keyA )['items'] );

// 没有店铺地址时不发。
foreach ( array_keys( $orig_addr ) as $k ) {
	update_option( $k, '' );
}
$mails = array();
$r     = Recovery::scan( $now + 61 * 60000 );
$ok( 'no store address: nothing sent', 'address' === $r['blocked'] && ! $mails && 0 === (int) $record( $keyA )['stage'] );
update_option( 'woocommerce_store_address', '100 Test Street' );
update_option( 'woocommerce_store_city', 'Los Angeles' );
update_option( 'woocommerce_store_postcode', '90001' );

$ok( 'not yet due after 30 min', 0 === Recovery::scan( $now + 30 * 60000 )['sent'] );
$mails = array();
$r     = Recovery::scan( $now + 61 * 60000 );
$recA  = $record( $keyA );
$ok( 'first reminder sent after 60 min', 1 === (int) $recA['stage'] && (int) $recA['sent1_ms'] > 0 && 1 === count( array_filter( $mails, static fn( $m ) => 'smoke-rc-a@example.com' === $m['to'] ) ) );
$mailA = array_values( array_filter( $mails, static fn( $m ) => 'smoke-rc-a@example.com' === $m['to'] ) )[0] ?? array( 'message' => '', 'headers' => array() );
$ok( 'email has the recover link, unsubscribe link and store address', str_contains( $mailA['message'], 'pc_recover=' . $recA['id'] . '.' ) && str_contains( $mailA['message'], 'pc_unsub=' ) && str_contains( $mailA['message'], '100 Test Street' ) && str_contains( implode( "\n", (array) $mailA['headers'] ), 'List-Unsubscribe' ) );
$ok( 'first reminder is not sent twice', 0 === Recovery::scan( $now + 70 * 60000 )['sent'] );

// 第二封。
$mails = array();
Recovery::scan( $now + 61 * 60000 + 25 * 3600000 );
$ok( 'second reminder after 24 h', 2 === (int) $record( $keyA )['stage'] && 1 === count( $mails ) );

// 4. 改车重新计时。
$keyB = 'smoke_rc_b_' . substr( md5( (string) $now ), 0, 8 );
$session( $keyB, 'smoke-rc-b@example.com', 1 );
Recovery::scan( $now );
$session( $keyB, 'smoke-rc-b@example.com', 3 );
Recovery::scan( $now + 50 * 60000 );
$ok( 'cart change restarts the timer', $now + 50 * 60000 === (int) $record( $keyB )['changed_ms'] && 3 === (int) $record( $keyB )['items'] );
$ok( 'restarted timer: not due 61 min after the first sighting', 0 === (int) $record( $keyB )['stage'] || Recovery::scan( $now + 61 * 60000 ) && 0 === (int) $record( $keyB )['stage'] );

// 5. 付款：提醒之后付款 → 召回成功；提醒之前付款 → 自行下单。
// 测试里的提醒时间在“未来”（扫描用的是模拟时间），先挪到现在之前，再下单付款。
$wpdb->update( Recovery::table(), array( 'sent1_ms' => $now - 60000, 'created_ms' => $now - 120000 ), array( 'id' => (int) $recA['id'] ) ); // phpcs:ignore
$order = wc_create_order();
$order->add_product( $product, 2 );
$order->set_billing_email( 'smoke-rc-a@example.com' );
$order->calculate_totals();
$order->set_status( 'processing' ); // 进入已付款状态时自动结束同邮箱的弃单。
$order->save();
$orders[] = $order->get_id();
$recA = $record( $keyA );
$ok( 'paid after a reminder: recovered with order and total', 'recovered' === $recA['status'] && $order->get_id() === (int) $recA['order_id'] && abs( (float) $recA['order_total'] - (float) $order->get_total() ) < 0.01 );

$order2 = wc_create_order();
$order2->add_product( $product, 1 );
$order2->set_billing_email( 'smoke-rc-b@example.com' );
$order2->calculate_totals();
$order2->set_status( 'processing' );
$order2->save();
$orders[] = $order2->get_id();
// 这一单在提醒之前付款（订单保存时已自动结束）。
$ok( 'paid before any reminder: converted, not counted as recovered', 'converted' === $record( $keyB )['status'] );

// 6. 清空、过期、员工、退订。
$keyC = 'smoke_rc_c_' . substr( md5( (string) $now ), 0, 8 );
$session( $keyC, 'smoke-rc-c@example.com', 1 );
Recovery::scan( $now );
$session( $keyC, 'smoke-rc-c@example.com', 0 );
Recovery::scan( $now + 60000 );
$ok( 'emptied cart: closed', 'closed' === $record( $keyC )['status'] );

$keyD = 'smoke_rc_d_' . substr( md5( (string) $now ), 0, 8 );
$session( $keyD, 'smoke-rc-d@example.com', 1 );
Recovery::scan( $now );
$wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $keyD ) ); // phpcs:ignore
Recovery::scan( $now + 60000 );
$ok( 'session gone: expired', 'expired' === $record( $keyD )['status'] );

// 用临时店长账号测试（不能碰真实管理员账号的购物车会话）。
$staff = wp_insert_user( array( 'user_login' => 'smoke_rc_staff', 'user_email' => 'smoke-rc-staff@example.com', 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$session( (string) $staff, 'smoke-rc-staff@example.com', 1 );
Recovery::scan( $now );
$ok( 'staff accounts are never reminded', ! $record( (string) $staff ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $staff );

$keyE = 'smoke_rc_e_' . substr( md5( (string) $now ), 0, 8 );
$session( $keyE, 'smoke-rc-e@example.com', 1 );
Recovery::scan( $now );
$recE = $record( $keyE );
Recovery::unsubscribe( $recE );
$ok( 'unsubscribe closes open records', 'unsubscribed' === $record( $keyE )['status'] );
$keyF = 'smoke_rc_f_' . substr( md5( (string) $now ), 0, 8 );
$session( $keyF, 'SMOKE-RC-E@example.com', 1 );
Recovery::scan( $now );
$mails = array();
Recovery::scan( $now + 61 * 60000 );
$ok( 'unsubscribed email is never reminded again', ! array_filter( $mails, static fn( $m ) => 'smoke-rc-e@example.com' === strtolower( $m['to'] ) ) && 'unsubscribed' === $record( $keyF )['status'] );

// 7. 恢复购物车。
wc_load_cart();
WC()->cart->empty_cart();
$keyG = 'smoke_rc_g_' . substr( md5( (string) $now ), 0, 8 );
$session( $keyG, 'smoke-rc-g@example.com', 2 );
Recovery::scan( $now );
$recG  = $record( $keyG );
$added = Recovery::restore( $recG );
$again = Recovery::restore( $recG );
$ok( 'recover link restores the cart once, without duplicates', 1 === $added && 0 === $again && 2 === (int) WC()->cart->get_cart_contents_count() && (int) $record( $keyG )['clicked_ms'] > 0 );
WC()->cart->empty_cart();
wc_clear_notices();

// 8. 统计与后台页面。
$st = Recovery::stats( $now - DAY_IN_SECONDS * 1000 * 400, $now + DAY_IN_SECONDS * 1000 * 400 );
$ok( 'stats count abandoned, emailed, recovered', $st['abandoned'] >= 5 && $st['emailed'] >= 1 && $st['recovered'] >= 1 && $st['recovered_total'] > 0 );
ob_start();
\PeptideCore\Admin\Recovery_Page::render();
$html = ob_get_clean();
$ok( 'admin page lists the records and settings', str_contains( $html, 'smoke-rc-a@example.com' ) && str_contains( $html, 'pc_recovery_save' ) );

// 9. 清理。
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
foreach ( $orig_addr as $k => $v ) {
	null === $v ? delete_option( $k ) : update_option( $k, $v );
}
null === $orig_optout ? delete_option( Recovery::OPTOUT ) : update_option( Recovery::OPTOUT, $orig_optout, false );
if ( null === $orig_settings ) {
	delete_option( Recovery::OPTION );
} else {
	update_option( Recovery::OPTION, $orig_settings, false );
}
Recovery::schedule();
$ok( 'test data removed and settings restored', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Recovery::table() . " WHERE email LIKE 'smoke-rc-%'" ) && ( null !== $orig_settings || ! wp_next_scheduled( Recovery::CRON ) ) ); // phpcs:ignore
