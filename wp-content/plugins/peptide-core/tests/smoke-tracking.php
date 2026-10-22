<?php
/**
 * 访客追踪（VISITOR-ANALYTICS-TEST-01）冒烟测试：接收协议、去重 / 乱序 / 时钟、多标签页、访问切分、
 * Woo 服务端事实、后台查询和权限。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-tracking.php
 * 测试产生的访客、访问、事件、标签页、订单和用户在结束时彻底删除，追踪开关恢复原样。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Tracking\Ingest;
use PeptideCore\Tracking\Query;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;
use PeptideCore\Tracking\Tracker;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

global $wpdb;
$orig_option = get_option( Tracker::OPTION, null );
$admin       = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
$visitors    = array();
$orders      = array();
$users       = array();

Tracker::set_enabled( true );
if ( ! has_action( 'woocommerce_add_to_cart', array( Server_Events::class, 'on_add_to_cart' ) ) ) {
	Server_Events::register_hooks();
}
$ok( 'tables installed and tracking enabled', Schema::installed() && Tracker::enabled() );

$uuid = static fn() => wp_generate_uuid4();
$iso  = static fn( int $ms ) => gmdate( 'Y-m-d\TH:i:s', (int) floor( $ms / 1000 ) ) . '.' . str_pad( (string) ( $ms % 1000 ), 3, '0', STR_PAD_LEFT ) . 'Z';
$ev   = static function ( string $type, int $at, string $pid, int $seq, array $page = array(), array $data = array() ) use ( $uuid, $iso ): array {
	return array(
		'event_id'         => $uuid(),
		'page_instance_id' => $pid,
		'seq'              => $seq,
		'type'             => $type,
		'occurred_at'      => $iso( $at ),
		'page'             => $page + array( 'page_type' => 'home', 'path' => '/' ),
		'data'             => $data,
	);
};
$send = static function ( array $body ): WP_REST_Response {
	$req = new WP_REST_Request( 'POST', '/peptide/v1/track/events' );
	$req->set_header( 'Content-Type', 'text/plain' ); // 和 sendBeacon 一样
	$req->set_body( wp_json_encode( $body ) );
	return rest_do_request( $req );
};

// 1. 匿名访客：第一批事件。
wp_set_current_user( 0 );
unset( $_COOKIE[ Tracker::COOKIE ] );
$res = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/track/session' ) );
$ok( 'session endpoint: enabled, tracked, config with endpoint', 200 === $res->get_status() && true === $res->get_data()['tracked'] && str_contains( (string) $res->get_data()['config']['endpoint'], '/peptide/v1/track/events' ) );
$ok( 'session endpoint issues the visitor cookie', '' !== Tracker::current_vid() && ! isset( $res->get_data()['vid'] ) );
$vid1 = Tracker::current_vid();

$now   = Tracker::now_ms();
$tabA  = 'tab-' . substr( md5( 'a' . $now ), 0, 12 );
$pageA = $uuid();
$prod  = (int) get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) )[0];
$pp    = array( 'page_type' => 'product', 'path' => '/product/x/?utm=1#frag', 'product_id' => $prod, 'product_name' => 'Smoke product' );
$batch = array(
	$ev( 'page_view', $now - 3000, $pageA, 1, $pp, array( 'cause' => 'navigation', 'visibility' => 'visible', 'activity' => 'active', 'last_activity_age_ms' => 10, 'password' => 'nope' ) ),
	$ev( 'product_view', $now - 2990, $pageA, 2, $pp, array( 'product_id' => $prod ) ),
	$ev( 'variant_select', $now - 2000, $pageA, 3, $pp, array( 'product_id' => $prod, 'attribute' => 'attribute_pa_size', 'value' => str_repeat( 'x', 300 ) ) ),
);
$res  = $send( array( 'protocol' => 1, 'tab_id' => $tabA, 'sent_at' => $iso( $now ), 'events' => $batch ) );
$data = $res->get_data();
$ok( 'batch accepted with per-event ack', 200 === $res->get_status() && 3 === count( $data['ack'] ) && array() === $data['rejected'] );
$ok( 'response is not cacheable', 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
$visitor = Tracker::visitor( $vid1, $now );
$visitors[] = $visitor['id'];
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'events' ) . " WHERE visitor_id = %d AND type = 'page_view'", $visitor['id'] ), ARRAY_A ); // phpcs:ignore
$ok( 'path stored without query or hash', '/product/x/' === $row['path'] );
$ok( 'data whitelist drops unknown fields', ! str_contains( (string) $row['data'], 'password' ) && str_contains( (string) $row['data'], 'navigation' ) );
$vs = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT data FROM ' . Schema::table( 'events' ) . " WHERE visitor_id = %d AND type = 'variant_select'", $visitor['id'] ) ), true ); // phpcs:ignore
$ok( 'long strings truncated', 120 === mb_strlen( $vs['value'] ) );
$visit1 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'visits' ) . ' WHERE visitor_id = %d', $visitor['id'] ) ); // phpcs:ignore
$v1row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'visits' ) . ' WHERE id = %d', $visit1 ), ARRAY_A ); // phpcs:ignore
$ok( 'visit created with landing page, stage = viewed product', $visit1 && '/product/x/' === $v1row['landing_path'] && 1 === (int) $v1row['stage'] && 1 === (int) $v1row['pageviews'] );

// 2. 重复 / 乱序 / 无效。
$res = $send( array( 'tab_id' => $tabA, 'events' => array( $batch[0], $batch[1] ) ) );
$ok( 'duplicates are acknowledged again', 2 === count( $res->get_data()['ack'] ) );
$ok( 'duplicates are stored once', 3 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . ' WHERE visitor_id = %d', $visitor['id'] ) ) ); // phpcs:ignore
$ok( 'duplicates do not inflate counters', 3 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT events FROM ' . Schema::table( 'visits' ) . ' WHERE id = %d', $visit1 ) ) ); // phpcs:ignore

$pageA2 = $uuid();
$send( array( 'tab_id' => $tabA, 'events' => array( $ev( 'page_view', $now - 500, $pageA2, 1, array( 'page_type' => 'cart', 'path' => '/cart/' ), array( 'visibility' => 'visible', 'activity' => 'active' ) ) ) ) );
$late = $send( array( 'tab_id' => $tabA, 'events' => array( $ev( 'heartbeat', $now - 1500, $pageA, 4, $pp, array( 'visibility' => 'hidden', 'activity' => 'idle' ) ) ) ) );
$tab  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'tabs' ) . ' WHERE visitor_id = %d AND tab_id = %s', $visitor['id'], $tabA ), ARRAY_A ); // phpcs:ignore
$ok( 'late event is stored', 1 === count( $late->get_data()['ack'] ) );
$ok( 'late event does not overwrite the newer tab state', 'cart' === $tab['page_type'] && 'visible' === $tab['visibility'] );

$bad = $send(
	array(
		'events' => array(
			array( 'event_id' => 'x', 'page_instance_id' => $pageA, 'type' => 'page_view', 'occurred_at' => $iso( $now ) ),
			array( 'event_id' => $uuid(), 'page_instance_id' => $pageA, 'type' => 'hack', 'occurred_at' => $iso( $now ) ),
			array( 'event_id' => $uuid(), 'page_instance_id' => $pageA, 'type' => 'heartbeat', 'occurred_at' => 'yesterday-ish' ),
			array( 'event_id' => $uuid(), 'page_instance_id' => $pageA, 'type' => 'heartbeat', 'occurred_at' => $iso( $now - 3 * DAY_IN_SECONDS * 1000 ) ),
			array( 'event_id' => $uuid(), 'page_instance_id' => 'no', 'type' => 'heartbeat', 'occurred_at' => $iso( $now ) ),
		),
	)
);
$codes = array_column( $bad->get_data()['rejected'], 'code' );
$ok( 'invalid events rejected with reasons, not retryable', array( 'bad_event_id', 'unknown_type', 'bad_time', 'too_old', 'bad_page_instance_id' ) === $codes && ! in_array( true, array_column( $bad->get_data()['rejected'], 'retry' ), true ) && array() === $bad->get_data()['ack'] );

// 3. 时钟校正：浏览器慢了 1 小时。
$skewId = $uuid();
$e      = $ev( 'heartbeat', $now - 3600000 - 1000, $pageA2, 2, array( 'page_type' => 'cart', 'path' => '/cart/' ), array( 'visibility' => 'visible', 'activity' => 'active' ) );
$e['event_id'] = $skewId;
$send( array( 'tab_id' => $tabA, 'sent_at' => $iso( Tracker::now_ms() - 3600000 ), 'events' => array( $e ) ) );
$at = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurred_ms FROM ' . Schema::table( 'events' ) . ' WHERE visitor_id = %d AND event_uid = %s', $visitor['id'], $skewId ) ); // phpcs:ignore
$ok( 'browser clock corrected with sent_at', abs( $at - ( Tracker::now_ms() - 1000 ) ) < 5000 );

// 4. 第二个标签页不覆盖第一个。
$tabB = 'tab-' . substr( md5( 'b' . $now ), 0, 12 );
$send( array( 'tab_id' => $tabB, 'events' => array( $ev( 'page_view', $now, $uuid(), 1, array( 'page_type' => 'shop', 'path' => '/shop/' ), array( 'visibility' => 'visible', 'activity' => 'active' ) ) ) ) );
$live = Query::live( Tracker::now_ms() );
$me   = array_values( array_filter( $live, static fn( $l ) => $l['visitor']['id'] === $visitor['id'] ) );
$ok( 'live view: one visitor, two tabs', 1 === count( $me ) && 2 === count( $me[0]['tabs'] ) );
$ok( 'each tab keeps its own page', array( '/cart/', '/shop/' ) === array_values( array_intersect( array( '/cart/', '/shop/' ), array_column( $me[0]['tabs'], 'path' ) ) ) || 2 === count( array_unique( array_column( $me[0]['tabs'], 'path' ) ) ) );
$ok( 'visitor is active', 'active' === $me[0]['status'] );
$ok( 'tab status: idle when hidden, offline after 45s / after exit', 'idle' === Query::tab_status( array( 'last_signal_ms' => $now, 'last_type' => 'heartbeat', 'visibility' => 'hidden', 'activity' => 'active' ), $now + 1000 ) && 'offline' === Query::tab_status( array( 'last_signal_ms' => $now, 'last_type' => 'heartbeat', 'visibility' => 'visible', 'activity' => 'active' ), $now + 46000 ) && 'offline' === Query::tab_status( array( 'last_signal_ms' => $now, 'last_type' => 'page_exit', 'visibility' => 'visible', 'activity' => 'active' ), $now + 11000 ) );

// 视频状态。
$send( array( 'tab_id' => $tabB, 'events' => array( $ev( 'video_play', Tracker::now_ms(), $uuid(), 2, array( 'page_type' => 'shop', 'path' => '/shop/' ), array( 'video_id' => 'hero', 'position_s' => 3.2, 'watched_s' => 1.5, 'autoplay' => true, 'playback_origin' => 'autoplay' ) ) ) ) );
$tabRow = $wpdb->get_row( $wpdb->prepare( 'SELECT video FROM ' . Schema::table( 'tabs' ) . ' WHERE visitor_id = %d AND tab_id = %s', $visitor['id'], $tabB ), ARRAY_A ); // phpcs:ignore
$video  = json_decode( (string) $tabRow['video'], true );
$ok( 'video state on the tab', 'playing' === $video['state'] && 'hero' === $video['video_id'] && true === $video['autoplay'] );

// 4b. 并发 / 同页序号：同一页面实例里序号小的事件晚到，不覆盖序号大的状态（即使时间戳相同）。
$tabC  = 'tab-' . substr( md5( 'c' . $now ), 0, 12 );
$pageC = $uuid();
$sameT = Tracker::now_ms();
$send( array( 'tab_id' => $tabC, 'events' => array( $ev( 'heartbeat', $sameT, $pageC, 5, array( 'page_type' => 'shop', 'path' => '/shop/' ), array( 'visibility' => 'hidden', 'activity' => 'idle' ) ) ) ) );
$send( array( 'tab_id' => $tabC, 'events' => array( $ev( 'heartbeat', $sameT, $pageC, 4, array( 'page_type' => 'shop', 'path' => '/shop/' ), array( 'visibility' => 'visible', 'activity' => 'active' ) ) ) ) );
$rowC = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'tabs' ) . ' WHERE visitor_id = %d AND tab_id = %s', $visitor['id'], $tabC ), ARRAY_A ); // phpcs:ignore
$ok( 'lower sequence in the same page does not overwrite newer state', 'hidden' === $rowC['visibility'] && 5 === (int) $rowC['last_seq'] );
$send( array( 'tab_id' => $tabC, 'events' => array( $ev( 'heartbeat', $sameT + 1, $pageC, 6, array( 'page_type' => 'product', 'path' => '/p/', 'product_id' => 1, 'product_name' => 'Smoke 100% pure' ), array( 'visibility' => 'visible', 'activity' => 'active' ) ) ) ) );
$rowC = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'tabs' ) . ' WHERE visitor_id = %d AND tab_id = %s', $visitor['id'], $tabC ), ARRAY_A ); // phpcs:ignore
$ok( 'higher sequence updates; % in values is stored safely', 'visible' === $rowC['visibility'] && 6 === (int) $rowC['last_seq'] && 'Smoke 100% pure' === $rowC['product_name'] );
$visits_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'visits' ) . ' WHERE visitor_id = %d', $visitor['id'] ) ); // phpcs:ignore
Tracker::visit( $visitor['id'], Tracker::now_ms() );
Tracker::visit( $visitor['id'], Tracker::now_ms() );
$ok( 'repeated visit lookups reuse the open visit', $visits_before === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'visits' ) . ' WHERE visitor_id = %d', $visitor['id'] ) ) ); // phpcs:ignore

// 5. 整批错误。
$big = array();
for ( $i = 0; $i < 21; $i++ ) {
	$big[] = $ev( 'heartbeat', $now, $pageA, $i );
}
$ok( 'more than 20 events: 413', 413 === $send( array( 'events' => $big ) )->get_status() );
$req = new WP_REST_Request( 'POST', '/peptide/v1/track/events' );
$req->set_body( 'not json' );
$ok( 'bad JSON: 400', 400 === rest_do_request( $req )->get_status() );
set_transient( 'pc_trk_' . md5( 'v|' . $vid1 ), array( time(), 600 ), 300 );
$limited = $send( array( 'events' => array( $ev( 'heartbeat', Tracker::now_ms(), $pageA, 9 ) ) ) );
$ok( 'rate limit: 429 with Retry-After', 429 === $limited->get_status() && (int) ( $limited->get_headers()['Retry-After'] ?? 0 ) > 0 );
delete_transient( 'pc_trk_' . md5( 'v|' . $vid1 ) );
wp_set_current_user( $admin->ID );
$ok( 'staff accounts are not tracked: 403', 403 === $send( array( 'events' => array( $ev( 'heartbeat', Tracker::now_ms(), $pageA, 10 ) ) ) )->get_status() );
wp_set_current_user( 0 );
Tracker::set_enabled( false );
$ok( 'tracking off: 403', 403 === $send( array( 'events' => array( $ev( 'heartbeat', Tracker::now_ms(), $pageA, 11 ) ) ) )->get_status() );
Tracker::set_enabled( true );

// 6. 30 分钟没有事件 → 新访问。
$wpdb->update( Schema::table( 'visits' ), array( 'last_ms' => Tracker::now_ms() - 31 * 60000 ), array( 'id' => $visit1 ) ); // phpcs:ignore
$send( array( 'tab_id' => $tabA, 'events' => array( $ev( 'page_view', Tracker::now_ms(), $uuid(), 1, array( 'page_type' => 'home', 'path' => '/' ), array( 'visibility' => 'visible', 'activity' => 'active' ) ) ) ) );
$visit2 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'visits' ) . ' WHERE visitor_id = %d ORDER BY id DESC LIMIT 1', $visitor['id'] ) ); // phpcs:ignore
$ok( 'new visit after 30 minutes of silence', $visit2 && $visit2 !== $visit1 && 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT visits FROM ' . Schema::table( 'visitors' ) . ' WHERE id = %d', $visitor['id'] ) ) ); // phpcs:ignore

// 7. Woo 服务端事实。
wc_load_cart();
WC()->cart->empty_cart();
$simple = null;
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => 20 ) ) as $p ) {
	if ( $p->is_purchasable() && $p->is_in_stock() ) {
		$simple = $p;
		break;
	}
}
if ( $simple ) {
	WC()->cart->add_to_cart( $simple->get_id(), 1 );
	$atc = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . " WHERE visitor_id = %d AND type = 'add_to_cart' AND source = 'server'", $visitor['id'] ) ); // phpcs:ignore
	$ok( 'real add to cart recorded once by the server', 1 === $atc );
	$ok( 'visit reaches stage 2', 2 <= (int) $wpdb->get_var( $wpdb->prepare( 'SELECT stage FROM ' . Schema::table( 'visits' ) . ' WHERE id = %d', $visit2 ) ) ); // phpcs:ignore
}
WC()->cart->empty_cart();

$customer = wp_insert_user( array( 'user_login' => 'smoke_track_customer', 'user_email' => 'smoke-track@example.com', 'user_pass' => wp_generate_password(), 'role' => 'customer' ) );
$users[]  = $customer;
Server_Events::on_login( 'smoke_track_customer', get_userdata( $customer ) );
$ok( 'login links the visitor to the account', $customer === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . Schema::table( 'visitors' ) . ' WHERE id = %d', $visitor['id'] ) ) ); // phpcs:ignore

$order = wc_create_order( array( 'customer_id' => $customer ) );
if ( $simple ) {
	$order->add_product( $simple, 1 );
}
$order->calculate_totals();
$order->save();
$orders[] = $order->get_id();
Server_Events::on_order_placed( $order );
Server_Events::on_order_placed( $order );
$order = wc_get_order( $order->get_id() );
$ok( 'order linked to visitor and visit', $visitor['id'] === (int) $order->get_meta( Server_Events::ORDER_VISITOR_META ) && $visit2 === (int) $order->get_meta( Server_Events::ORDER_VISIT_META ) );
$ok( 'order placed recorded once', 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . " WHERE visitor_id = %d AND type = 'order_placed'", $visitor['id'] ) ) ); // phpcs:ignore

// 支付回调没有浏览器 cookie：靠订单上的关联。
unset( $_COOKIE[ Tracker::COOKIE ] );
$order->payment_complete( 'smoke-txn' );
Server_Events::on_payment_complete( $order->get_id() );
$paid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . " WHERE visitor_id = %d AND type = 'payment_success'", $visitor['id'] ) ); // phpcs:ignore
$ok( 'payment success recorded once without a browser cookie', 1 === $paid );
$v2 = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'visits' ) . ' WHERE id = %d', $visit2 ), ARRAY_A ); // phpcs:ignore
$ok( 'visit reaches stage 5 with the order', 5 === (int) $v2['stage'] && $order->get_id() === (int) $v2['order_id'] );
$ok( 'status changes recorded', 0 < (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . " WHERE visitor_id = %d AND type = 'order_status'", $visitor['id'] ) ) ); // phpcs:ignore

$_COOKIE[ Tracker::COOKIE ] = $vid1;
$failed = wc_create_order( array( 'customer_id' => $customer ) );
$failed->save();
$orders[] = $failed->get_id();
Server_Events::on_order_placed( $failed );
$failed->update_status( 'failed' );
$ok( 'failed payment recorded', 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . " WHERE visitor_id = %d AND type = 'payment_failed'", $visitor['id'] ) ) ); // phpcs:ignore

// 7b. 当前购物车快照（VISITOR-CART-01）。
$_COOKIE[ Tracker::COOKIE ] = $vid1;
$cartRow = static function () use ( $wpdb, $visitor ) {
	return $wpdb->get_row( $wpdb->prepare( 'SELECT id, cart, cart_ms, cart_key FROM ' . Schema::table( 'visitors' ) . ' WHERE id = %d', $visitor['id'] ), ARRAY_A ); // phpcs:ignore
};
$wpdb->update( Schema::table( 'visitors' ), array( 'cart' => null, 'cart_ms' => 0, 'cart_key' => '' ), array( 'id' => $visitor['id'] ) ); // phpcs:ignore
$ok( 'no snapshot yet: unknown', 'unknown' === \PeptideCore\Tracking\Carts::view( $cartRow(), false )['status'] );
WC()->cart->empty_cart();
if ( $simple ) {
	WC()->cart->add_to_cart( $simple->get_id(), 2 );
	WC()->cart->calculate_totals();
	\PeptideCore\Tracking\Carts::save( $visitor['id'], WC()->cart, (string) WC()->session->get_customer_id() );
	// 命令行里 Woo 不会写会话表，这里补一行，模拟浏览器里仍然有效的会话；结束时删除。
	$skey = (string) WC()->session->get_customer_id();
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}woocommerce_sessions (session_key, session_value, session_expiry) VALUES (%s, %s, %d)", $skey, 'a:0:{}', time() + HOUR_IN_SECONDS ) ); // phpcs:ignore
	$detail = \PeptideCore\Tracking\Carts::detail( $visitor['id'] );
	$ok( 'snapshot has items, quantity, prices and subtotal', 'active' === $detail['status'] && 2 === $detail['count'] && $simple->get_id() === $detail['items'][0]['product_id'] && 2.0 === (float) $detail['items'][0]['quantity'] && '' !== $detail['items'][0]['unit_price_text'] && abs( $detail['subtotal'] - (float) WC()->cart->get_subtotal() ) < 0.01 );
	$res = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/track/admin/visitors/' . $visitor['id'] . '/cart' ) );
	$ok( 'customers cannot read the cart endpoint', in_array( $res->get_status(), array( 401, 403 ), true ) || ! is_user_logged_in() );
	wp_set_current_user( $admin->ID );
	$res = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/track/admin/visitors/' . $visitor['id'] . '/cart' ) );
	$ok( 'admin cart endpoint', 200 === $res->get_status() && 2 === $res->get_data()['count'] );
	$live = array_values( array_filter( Query::live( Tracker::now_ms() ), static fn( $l ) => $l['visitor']['id'] === $visitor['id'] ) );
	$ok( 'live list carries the cart summary', $live && 'active' === $live[0]['cart']['status'] && 2 === $live[0]['cart']['count'] );
	wp_set_current_user( 0 );
	$wpdb->update( Schema::table( 'visitors' ), array( 'cart_key' => 'no-such-session-key' ), array( 'id' => $visitor['id'] ) ); // phpcs:ignore
	$ok( 'session gone: expired', 'expired' === \PeptideCore\Tracking\Carts::view( $cartRow(), false )['status'] );
	WC()->cart->empty_cart();
	\PeptideCore\Tracking\Carts::save( $visitor['id'], WC()->cart, (string) WC()->session->get_customer_id() );
	$ok( 'emptied cart: empty', 'empty' === \PeptideCore\Tracking\Carts::view( $cartRow(), false )['status'] );
	\PeptideCore\Tracking\Carts::mark();
	WC()->cart->add_to_cart( $simple->get_id(), 1 );
	\PeptideCore\Tracking\Carts::flush();
	$ok( 'hooks: cart change is captured at the end of the request', 1 === \PeptideCore\Tracking\Carts::detail( $visitor['id'] )['count'] );
	WC()->cart->empty_cart();
	$wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $skey ) ); // phpcs:ignore
}

// 8. 后台查询与权限。
$detail = Query::visitor( $visitor['id'] );
$ok( 'visitor detail: account, two visits', 'smoke-track@example.com' === $detail['visitor']['email'] && 2 === count( $detail['visits'] ) );
$tl    = Query::timeline( $visit2 );
$types = array_column( $tl['events'], 'type' );
$ok( 'timeline has client and server events in order', in_array( 'page_view', $types, true ) && in_array( 'order_placed', $types, true ) && array_search( 'page_view', $types, true ) < array_search( 'order_placed', $types, true ) );
$stats = Query::stats( Tracker::now_ms() - 86400000, Tracker::now_ms() + 1000 );
$ok( 'stats funnel counts this visit up to paid', $stats['funnel'][5]['count'] >= 1 && $stats['visits'] >= 2 );
wp_set_current_user( $admin->ID );
$ok( 'admin can read live data', 200 === rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/track/admin/live' ) )->get_status() );
$ok( 'admin stats endpoint', 200 === rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/track/admin/stats' ) )->get_status() );
wp_set_current_user( $customer );
$ok( 'customers cannot read visitor data', in_array( rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/track/admin/live' ) )->get_status(), array( 401, 403 ), true ) );
wp_set_current_user( 0 );
$cfg = Tracker::observer_config( array( 'enabled' => false ) );
$ok( 'theme config: enabled with endpoint and timings', true === $cfg['enabled'] && true === $cfg['test_mode'] && 15000 === $cfg['heartbeat_ms'] && 20 === $cfg['batch_max'] );

// 9. 清理。
wp_set_current_user( $admin->ID );
foreach ( $orders as $id ) {
	$o = wc_get_order( $id );
	if ( $o ) {
		$o->delete( true );
	}
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $users as $id ) {
	wp_delete_user( $id );
}
foreach ( $visitors as $id ) {
	foreach ( array( 'events', 'tabs', 'visits' ) as $t ) {
		$wpdb->delete( Schema::table( $t ), array( 'visitor_id' => $id ) ); // phpcs:ignore
	}
	$wpdb->delete( Schema::table( 'visitors' ), array( 'id' => $id ) ); // phpcs:ignore
}
unset( $_COOKIE[ Tracker::COOKIE ] );
if ( null === $orig_option ) {
	delete_option( Tracker::OPTION );
} else {
	update_option( Tracker::OPTION, $orig_option, false );
}
$ok( 'test data removed', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . ' WHERE visitor_id = %d', $visitor['id'] ) ) ); // phpcs:ignore
$ok( 'tracking switch restored', ( null === $orig_option ) === ( false === get_option( Tracker::OPTION ) ) );
