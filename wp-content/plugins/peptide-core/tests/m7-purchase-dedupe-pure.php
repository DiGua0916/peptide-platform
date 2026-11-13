<?php
/**
 * FE-09：购买事件（Purchase）不重复、不漏发。真正的 Pixels 和 Pixels\Server，只把 WordPress、WooCommerce 和网络换成桩。
 * 纯逻辑：php -n m7-purchase-dedupe-pure.php
 *
 * 一张订单的购买事件有四条路：
 *   A  付款时服务端发        Pixels::on_order_status()          订单上打 _pc_px_server
 *   B  每 5 分钟的补发任务   Pixels::purchase_catchup()         同一个标记
 *   C  付款成功弹窗页        Pixels::page_events()（?pc_paid=） 订单上打 _pc_px_browser
 *   D  “订单已收到”页      Pixels::page_events()              同一个标记
 * A、B 只会有一条发给 Conversions API；C、D 只会有一条交给浏览器；四条路的 event_id 都是 order_<订单内部编号>，
 * 所以 Meta 能把浏览器的那条和服务端的那条合成一次购买。
 *
 * 要证明的事：
 * 1. 正常付款：服务端 1 条、浏览器 1 条，event_id 相同；之后补发任务、刷新页面、状态再变都不会多发。
 * 2. 订单号显示成 hang- 之后，event_id 仍然是内部编号；hang- 号只出现在事件的 order_id 里。
 * 3. 付款流程被打断（A 没跑）：补发任务发 1 条，同一个 event_id；之后 A 再来也不发。
 * 4. 刚付款的订单补发任务不碰（留给 A），所以 A 和 B 不会同时各发一条。
 * 5. 测试单四条路都不发；顾客退出回传、后台建的订单（没有记下顾客信息）不发。
 */
require __DIR__ . '/m7-stubs.php';

use PeptideCore\Pixels;
use PeptideCore\Pixels\Server;

$wpdb                  = new T_DB();
$GLOBALS['t_uuid']     = 0;
$GLOBALS['t_requests'] = array();
$GLOBALS['t_http']     = array( 'code' => 200, 'body' => '{"events_received":1}' );
$GLOBALS['t_wc']       = new T_WC();
$GLOBALS['m6_options']['peptide_core_px_log_db'] = '1';
$GLOBALS['m6_options']['home']                   = 'https://www.store.test';
$GLOBALS['m6_options'][ Pixels::OPTION ]         = array(
	'platforms' => array( 'facebook' => array( 'pixels' => array( array( 'id' => '111', 'note' => 'main', 'domain' => '', 'token' => 'fbtoken' ) ) ) ),
);
Server::$sync = true; // 不进后台队列，直接发：每个请求都记在 $GLOBALS['t_requests'] 里。

$now = time();
/** 一张已付款的订单：$paid_ago 秒之前付的款；$ctx = 下单时有没有记下顾客信息。 */
$order = static function ( int $id, int $paid_ago, bool $ctx = true ) use ( $now ): WC_Order {
	$o        = m6_order( $id, 'processing', 59.0, $now - $paid_ago, $now - $paid_ago - 120 );
	$o->email = 'buyer' . $id . '@example.test';
	if ( $ctx ) {
		$o->meta[ Server::ORDER_CTX ] = array( 'ip' => '203.0.113.9', 'ua' => 'Mozilla/5.0 (iPhone) FBAN/FBIOS', 'fbp' => 'fb.1.1700000000000.123456', 'fbc' => '', 'ttp' => '', 'ttclid' => '', 'host' => 'store.test' );
	}
	return $o;
};
/** 发给 Conversions API 的购买事件：[ event_id, order_id ]。 */
$server = static function (): array {
	$out = array();
	foreach ( $GLOBALS['t_requests'] as $request ) {
		foreach ( (array) ( $request['body']['data'] ?? array() ) as $event ) {
			if ( 'Purchase' === $event['event_name'] ) { $out[] = array( $event['event_id'], $event['custom_data']['order_id'] ?? null ); }
		}
	}
	return $out;
};
/** 当前页面交给浏览器的购买事件的 event_id。 */
$browser = static function ( ?WC_Order $paid_popup = null, ?WC_Order $received = null ): array {
	$GLOBALS['t_paid_order'] = $paid_popup;
	$_GET                    = $paid_popup ? array( 'pc_paid' => (string) $paid_popup->get_id(), 'pc_key' => $paid_popup->get_order_key() ) : array();
	$GLOBALS['t_page']       = $received ? 'order-received' : '';
	if ( $received ) {
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-received' => $received->get_id() ) );
		$_GET['key']   = $received->get_order_key();
	}
	return array_column( array_values( array_filter( Pixels::page_events(), static fn( array $e ): bool => 'purchase' === $e['name'] ) ), 'id' );
};

// ---------- 1、2：正常付款，订单号显示成 hang- ----------
$a         = $order( 9001, 30 );
$a->number = 'hang-161188301';
Pixels::on_order_status( 9001, 'pending', 'processing', $a );
m6_check( '1: paying sends exactly one server Purchase', 1 === count( $server() ) );
m6_check( '2: its event_id is the internal order id, the hang- number only travels as order_id', array( 'order_9001', 'hang-161188301' ) === $server()[0] && ! str_contains( $server()[0][0], 'hang' ) );
m6_check( '1: the order is marked as sent', '' !== (string) $a->get_meta( Server::ORDER_SENT ) );
Pixels::on_order_status( 9001, 'processing', 'completed', $a );
m6_check( '1: a later status change does not send again', 1 === count( $server() ) );
$a->paid = $now - 1200; // 过了 20 分钟，补发任务来了。
Pixels::purchase_catchup();
m6_check( '1: the catch-up job leaves an order that was already sent alone', 1 === count( $server() ) && array() === $a->notes );
m6_check( '1: the paid popup page hands the browser one Purchase with the same event_id', array( 'order_9001' ) === $browser( $a ) );
m6_check( '1: the order is marked as shown', '' !== (string) $a->get_meta( Pixels::ORDER_SHOWN ) );
m6_check( '1: reloading the popup page does not hand out a second one', array() === $browser( $a ) );
m6_check( '1: nor does the order-received page afterwards', array() === $browser( null, $a ) );
m6_check( '1: one server event and one browser event in total', 1 === count( $server() ) );

// “订单已收到”页先到的情况（没有弹窗的店）：同一个标记、同一个 event_id。
$b = $order( 9002, 30 );
m6_check( '1: the order-received page hands out the Purchase once', array( 'order_9002' ) === $browser( null, $b ) && array() === $browser( null, $b ) && array() === $browser( $b ) );

// ---------- 3：付款流程被打断，付款时那一次没跑 ----------
$c         = $order( 9003, 1200 );
$c->number = 'hang-161188399';
$before    = count( $server() );
Pixels::purchase_catchup();
m6_check( '3: the catch-up job sends the missing Purchase once', $before + 1 === count( $server() ) && array( 'order_9003', 'hang-161188399' ) === $server()[ $before ] );
m6_check( '3: it leaves a note on the order and marks it as sent', 1 === count( $c->notes ) && '' !== (string) $c->get_meta( Server::ORDER_SENT ) );
Pixels::purchase_catchup();
Pixels::on_order_status( 9003, 'on-hold', 'processing', $c ); // 状态变化的通知晚到了。
m6_check( '3: neither a second run nor a late status change sends again', $before + 1 === count( $server() ) );
m6_check( '3: the browser event of that order still pairs with it', array( 'order_9003' ) === $browser( $c ) );

// ---------- 4：刚付款的订单，补发任务不碰 ----------
$d      = $order( 9004, 120 ); // 2 分钟前付的款，还没打“已回传”的标记（付款请求正在处理）。
$before = count( $server() );
Pixels::purchase_catchup();
m6_check( '4: an order paid two minutes ago is left to the payment request', $before === count( $server() ) && '' === (string) $d->get_meta( Server::ORDER_SENT ) );
Pixels::on_order_status( 9004, 'pending', 'processing', $d );
m6_check( '4: the payment request sends it', $before + 1 === count( $server() ) && 'order_9004' === $server()[ $before ][0] );
$d->paid = $now - Pixels::CATCHUP_GRACE - 60;
Pixels::purchase_catchup();
m6_check( '4: and the catch-up job never adds a second one', $before + 1 === count( $server() ) );

// ---------- 5：不发的情况 ----------
$before  = count( $server() );
$t       = $order( 9005, 1200 );
$t->ones = true; // 收件信息全 1 的测试单。
Pixels::on_order_status( 9005, 'pending', 'processing', $t );
Pixels::purchase_catchup();
m6_check( '5: a test order sends no server Purchase, at payment or later', $before === count( $server() ) && '' === (string) $t->get_meta( Server::ORDER_SENT ) );
m6_check( '5: a test order hands no Purchase to the browser on either page', array() === $browser( $t ) && array() === $browser( null, $t ) && '' === (string) $t->get_meta( Pixels::ORDER_SHOWN ) );

$o                         = $order( 9006, 1200 );
$o->meta['_pc_px_optout'] = '1'; // 下单时这位顾客退出了回传（GPC 等）。
Pixels::on_order_status( 9006, 'pending', 'processing', $o );
Pixels::purchase_catchup();
m6_check( '5: an opted-out order is never sent', $before === count( $server() ) );

$m = $order( 9007, 1200, false ); // 后台建的订单：没有记下顾客信息。
Pixels::purchase_catchup();
m6_check( '5: an order without customer context is marked, not sent, and gets no note', $before === count( $server() ) && '' !== (string) $m->get_meta( Server::ORDER_SENT ) && array() === $m->notes );

// 每张真实订单最多一条服务端购买：把发出去的 event_id 数一遍。
$ids = array_column( $server(), 0 );
sort( $ids );
m6_check( 'overall: every server Purchase has a distinct event_id', array( 'order_9001', 'order_9003', 'order_9004' ) === $ids );
m6_check( 'overall: the send log has one accepted Purchase row per order', array( 'order_9001', 'order_9003', 'order_9004' ) === array_column( array_values( array_filter( $wpdb->log, static fn( array $row ): bool => 'Purchase' === $row['event'] && 1 === $row['ok'] ) ), 'event_id' ) );

echo 'PASS ', $GLOBALS['m6_passed'], " purchase de-duplication (FE-09) assertions\n";
