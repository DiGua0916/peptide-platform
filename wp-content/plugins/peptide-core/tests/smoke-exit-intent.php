<?php
/**
 * 应用：离开意图增收（APP-EXIT-01）冒烟测试：设置校验、按权重抽奖、弹窗请求（领取、抽奖、每个会话只抽一次、员工不计数）、
 * 订单归因、数据统计、前台加载条件、后台页面。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-exit-intent.php
 * 测试建的商品、优惠码、订单在结束时彻底删除；设置、今天的统计数据、购物车和会话恢复原样。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Apps_Page;
use PeptideCore\Exit_Intent;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

global $wpdb;
add_filter( 'pre_wp_mail', '__return_false' );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$saved_settings = get_option( Exit_Intent::OPTION, null );
$made           = array();
$orders         = array();

// 今天的统计先存起来，测试完放回去。
if ( ! Exit_Intent::installed() ) {
	Exit_Intent::install();
}
$table = Exit_Intent::table();
$today = wp_date( 'Y-m-d' );
$stats = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE day = %s AND app = %s", $today, Exit_Intent::APP ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$count = static function ( string $event ) use ( $wpdb, $table, $today ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT count FROM {$table} WHERE day = %s AND app = %s AND event = %s", $today, Exit_Intent::APP, $event ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};

$coupon = static function ( string $code, array $props = array() ) use ( &$made ): string {
	$c = new WC_Coupon();
	$c->set_code( $code );
	$c->set_props( array_merge( array( 'discount_type' => 'percent', 'amount' => 10 ), $props ) );
	$made[] = $c->save();
	return $code;
};
$good    = $coupon( 'smkexit-good' );
$expired = $coupon( 'smkexit-old', array( 'date_expires' => time() - DAY_IN_SECONDS ) );

$p = new WC_Product_Simple();
$p->set_name( 'PC exit smoke' );
$p->set_regular_price( '20' );
$p->set_status( 'publish' );
$product = $p->save();
$made[]  = $product;

// 1. 设置校验。
[ , $e ] = Exit_Intent::clean( array( 'type' => 'coupon', 'coupon' => $expired, 'title' => 'x', 'pages' => array( 'home' ), 'desktop_exit' => 1 ) );
$ok( 'coupon popup needs a usable code (expired one refused)', (bool) $e );
[ $s, $e ] = Exit_Intent::clean( array( 'type' => 'coupon', 'coupon' => 'SMKEXIT-GOOD', 'title' => 'Wait', 'pages' => array( 'home', 'bogus' ), 'desktop_exit' => 1, 'color' => 'red' ) );
$ok( 'valid coupon popup; unknown page types dropped; bad color falls back', ! $e && array( 'home' ) === $s['pages'] && '#1d4ed8' === $s['color'] && 'smkexit-good' === $s['coupon'] );
[ , $e ] = Exit_Intent::clean( array( 'type' => 'wheel', 'title' => 'x', 'pages' => array( 'home' ), 'desktop_exit' => 1, 'prizes' => array( array( 'label' => 'A', 'coupon' => '', 'weight' => 5 ) ) ) );
$ok( 'wheel needs at least 2 prizes', (bool) $e );
[ , $e ] = Exit_Intent::clean( array( 'type' => 'wheel', 'title' => 'x', 'pages' => array( 'home' ), 'desktop_exit' => 1, 'prizes' => array( array( 'label' => 'A', 'weight' => 0 ), array( 'label' => 'B', 'weight' => 0 ) ) ) );
$ok( 'wheel needs a chance above zero', (bool) $e );
[ , $e ] = Exit_Intent::clean( array( 'type' => 'wheel', 'title' => 'x', 'pages' => array( 'home' ), 'desktop_exit' => 1, 'prizes' => array( array( 'label' => 'A', 'coupon' => $expired, 'weight' => 1 ), array( 'label' => 'B', 'weight' => 1 ) ) ) );
$ok( 'a prize with an unusable code is refused', (bool) $e && str_contains( implode( ' ', $e ), 'SMKEXIT-OLD' ) );
$many    = array_fill( 0, 10, array( 'label' => 'P', 'weight' => 1 ) );
[ $s, ] = Exit_Intent::clean( array( 'type' => 'wheel', 'title' => 'x', 'pages' => array( 'home' ), 'desktop_exit' => 1, 'prizes' => $many ) );
$ok( 'at most 8 prizes', 8 === count( $s['prizes'] ) );
[ , $e ] = Exit_Intent::clean( array( 'type' => 'message', 'title' => 'x', 'pages' => array( 'home' ), 'mobile' => 'off', 'button' => 'Go' ) );
$ok( 'needs a trigger, and a button needs a link', 2 <= count( $e ) );
update_option( Exit_Intent::OPTION, array( 'mobile' => 'idle' ), false );
$ok( 'old single phone trigger is read as a list', array( 'idle' ) === Exit_Intent::settings()['mobile'] );
[ $s, ] = Exit_Intent::clean( array( 'type' => 'message', 'title' => 'x', 'pages' => array( 'home' ), 'mobile' => array( 'return', 'bogus', 'bottom' ) ) );
$ok( 'phone triggers: several can be ticked, unknown ones dropped', array( 'return', 'bottom' ) === $s['mobile'] );
delete_option( Exit_Intent::OPTION );
$ok( 'new settings catch the back button on phones by default', in_array( 'back', Exit_Intent::settings()['mobile'], true ) );
$errors = Exit_Intent::save( array( 'enabled' => 1, 'type' => 'coupon', 'coupon' => '', 'title' => 'x', 'pages' => array( 'home' ), 'desktop_exit' => 1 ) );
$ok( 'settings with errors are saved but stay off', $errors && false === Exit_Intent::settings()['enabled'] );

// 2. 按权重抽奖（权重就是概率）。
$prizes = array( array( 'weight' => 1 ), array( 'weight' => 0 ), array( 'weight' => 3 ) );
$ok( 'draw by weight: roll 1 → first, 2 and 4 → third, zero weight never', 0 === Exit_Intent::draw( $prizes, 1 ) && 2 === Exit_Intent::draw( $prizes, 2 ) && 2 === Exit_Intent::draw( $prizes, 4 ) );
$hits = array( 0, 0, 0 );
for ( $i = 0; $i < 4000; $i++ ) {
	++$hits[ Exit_Intent::draw( $prizes ) ];
}
$ok( 'random draws follow the weights (about 25% / 0% / 75%)', 0 === $hits[1] && abs( $hits[0] / 4000 - 0.25 ) < 0.04 );

// 3. 弹窗请求：抽奖。
$wheel = array( 'enabled' => 1, 'type' => 'wheel', 'title' => 'Spin', 'pages' => Exit_Intent::PAGES, 'desktop_exit' => 1, 'prizes' => array( array( 'label' => '10% OFF', 'coupon' => $good, 'weight' => 1 ), array( 'label' => 'Try again', 'coupon' => '', 'weight' => 0 ) ) );
$ok( 'wheel settings saved and on', ! Exit_Intent::save( $wheel ) && Exit_Intent::settings()['enabled'] );
$cfg = Exit_Intent::public_config( Exit_Intent::settings() );
$ok( 'browser config has prize names only (no weights, no prize codes)', array( '10% OFF', 'Try again' ) === $cfg['prizes'] && ! str_contains( (string) wp_json_encode( $cfg ), 'smkexit' ) );

add_filter( 'wp_doing_ajax', '__return_true' );
$die = static function () {
	return static function () {
		throw new RuntimeException( 'json' );
	};
};
add_filter( 'wp_die_ajax_handler', $die );
$call = static function ( string $method, array $post = array() ): array {
	$_POST = $post;
	ob_start();
	try {
		Exit_Intent::$method();
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	$_POST = array();
	return (array) json_decode( (string) ob_get_clean(), true );
};

wc_load_cart();
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product, 1 );
WC()->cart->calculate_totals();
$r = $call( 'ajax_spin' );
$ok( 'staff spin is a preview: real draw, nothing applied or counted', ! empty( $r['success'] ) && 'preview' === ( $r['data']['state'] ?? '' ) && ! WC()->cart->has_discount( $good ) && 0 === $count( 'spin' ) - (int) ( array_column( $stats, 'count', 'event' )['spin'] ?? 0 ) );

wp_set_current_user( 0 );
WC()->session->set( Exit_Intent::SESSION_KEY . '_spin', null );
$before_spin = $count( 'spin' );
$before_win  = $count( 'win' );
$r           = $call( 'ajax_spin' );
$ok( 'visitor spin: wins the only prize with a chance, code applied to the cart', ! empty( $r['success'] ) && 0 === $r['data']['index'] && true === $r['data']['won'] && 'SMKEXIT-GOOD' === $r['data']['code'] && WC()->cart->has_discount( $good ) );
$ok( 'spin and win counted', $before_spin + 1 === $count( 'spin' ) && $before_win + 1 === $count( 'win' ) );
$again = $call( 'ajax_spin' );
$ok( 'only one spin per visit: the same result comes back', ! empty( $again['data']['repeat'] ) && 0 === $again['data']['index'] && $before_spin + 1 === $count( 'spin' ) );
$before_show = $count( 'show' );
$call( 'ajax_event', array( 'event' => 'show' ) );
$call( 'ajax_event', array( 'event' => 'bogus' ) );
$ok( 'show counted, unknown events ignored', $before_show + 1 === $count( 'show' ) );

// 4. 订单归因：用了弹窗给的优惠码。
$order = wc_create_order();
$order->add_product( wc_get_product( $product ), 1 );
$order->apply_coupon( $good );
$order->calculate_totals();
$order->save();
$orders[]     = $order->get_id();
$before_order = $count( 'order' );
Exit_Intent::attribute_order( $order->get_id() );
$order = wc_get_order( $order->get_id() );
$ok( 'order with the popup code is tagged and counted', 'SMKEXIT-GOOD' === $order->get_meta( Exit_Intent::ORDER_META ) && $before_order + 1 === $count( 'order' ) );
$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
$ok( 'order note mentions the code and the search tag', (bool) array_filter( $notes, static fn( $n ) => str_contains( $n->content, 'SMKEXIT-GOOD' ) && str_contains( $n->content, 'exitintent' ) ) );
$args = Exit_Intent::orders_search( array( 's' => ' ExitIntent ', 'search_filter' => 'all', 'status' => 'all' ) );
$ok( 'searching “exitintent” in the orders list lists the tagged orders', ! isset( $args['s'] ) && 'all' === $args['status'] && Exit_Intent::ORDER_META === $args['meta_query'][0]['key'] && in_array( $order->get_id(), wc_get_orders( $args + array( 'return' => 'ids', 'limit' => 20 ) ), true ) );
$ok( 'other searches are left alone', array( 's' => 'smith' ) === Exit_Intent::orders_search( array( 's' => 'smith' ) ) );
Exit_Intent::attribute_order( $order );
$ok( 'the same order is not counted twice', $before_order + 1 === $count( 'order' ) );
$other = wc_create_order();
$other->save();
$orders[] = $other->get_id();
WC()->session->set( Exit_Intent::SESSION_KEY . '_code', $good );
Exit_Intent::attribute_order( $other );
$ok( 'an order without the code is not tagged', '' === wc_get_order( $other->get_id() )->get_meta( Exit_Intent::ORDER_META ) );

// 5. 优惠券弹窗：领取。
WC()->cart->remove_coupons();
WC()->cart->calculate_totals();
Exit_Intent::save( array( 'enabled' => 1, 'type' => 'coupon', 'coupon' => $good, 'title' => 'Wait', 'pages' => Exit_Intent::PAGES, 'desktop_exit' => 1 ) );
$before_claim = $count( 'claim' );
$r            = $call( 'ajax_claim' );
$ok( 'claim applies the code to the cart and is counted', ! empty( $r['success'] ) && 'applied' === $r['data']['state'] && WC()->cart->has_discount( $good ) && $before_claim + 1 === $count( 'claim' ) );
remove_filter( 'wp_die_ajax_handler', $die );
remove_filter( 'wp_doing_ajax', '__return_true' );

// 6. 统计合计。
$t = Exit_Intent::totals( 30 );
$ok( 'totals include today’s claims and order amount', $t['claim']['count'] >= 1 && $t['order']['amount'] >= 18 );

// 7. 前台加载条件。
$loaded = static function (): bool {
	wp_dequeue_script( 'peptide-core-exit-intent' );
	wp_deregister_script( 'peptide-core-exit-intent' );
	Exit_Intent::enqueue();
	return wp_script_is( 'peptide-core-exit-intent', 'enqueued' );
};
$ok( 'loads for a visitor when on and the page type is chosen', $loaded() );
Exit_Intent::save( array_merge( Exit_Intent::settings(), array( 'enabled' => 0 ) ) );
$ok( 'does not load when off', ! $loaded() );
Exit_Intent::save( array_merge( Exit_Intent::settings(), array( 'enabled' => 1, 'pages' => array( 'checkout' ) ) ) );
$ok( 'does not load on a page type that is not chosen', ! $loaded() );
Exit_Intent::save( array_merge( Exit_Intent::settings(), array( 'pages' => Exit_Intent::PAGES, 'audience' => 'cart' ) ) );
$ok( 'only with items in the cart: loads with items', $loaded() );
WC()->cart->empty_cart();
$ok( 'only with items in the cart: not with an empty cart', ! $loaded() );
wp_set_current_user( $admin->ID );
Exit_Intent::save( array_merge( Exit_Intent::settings(), array( 'audience' => 'all' ) ) );
$ok( 'staff never get the popup', ! $loaded() );

// 8. 后台页面。
$_GET = array( 'page' => Apps_Page::SLUG );
ob_start();
Apps_Page::render();
$list = (string) ob_get_clean();
$_GET = array( 'page' => Apps_Page::SLUG, 'app' => 'exit-intent' );
ob_start();
Apps_Page::render();
$page = (string) ob_get_clean();
$_GET = array();
$ok( 'app center lists the app with its status', str_contains( $list, 'pc-apps__card' ) && str_contains( $list, 'app=exit-intent' ) );
$ok( 'settings page has stats, prizes, triggers and the preview', str_contains( $page, 'pc-exit-admin__tiles' ) && str_contains( $page, 'pc-exit-prize' ) && str_contains( $page, 'name="frequency_days"' ) && str_contains( $page, 'pc_exit_preview' ) );

// 9. 清理。
WC()->cart->empty_cart();
WC()->session->set( Exit_Intent::SESSION_KEY . '_spin', null );
WC()->session->set( Exit_Intent::SESSION_KEY . '_code', null );
wc_clear_notices();
foreach ( $orders as $oid ) {
	$o = wc_get_order( $oid );
	if ( $o ) {
		$o->delete( true );
	}
}
foreach ( $made as $id ) {
	if ( 'product' === get_post_type( $id ) ) {
		wc_get_product( $id )->delete( true );
	} else {
		wp_delete_post( $id, true );
	}
}
if ( null === $saved_settings ) {
	delete_option( Exit_Intent::OPTION );
} else {
	update_option( Exit_Intent::OPTION, $saved_settings, false );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day = %s AND app = %s", $today, Exit_Intent::APP ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( $stats as $row ) {
	$wpdb->insert( $table, $row );
}
$ok( 'test data removed and today’s stats restored', ! array_filter( array_map( 'get_post', $made ) ) && count( $stats ) === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE day = %s AND app = %s", $today, Exit_Intent::APP ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
