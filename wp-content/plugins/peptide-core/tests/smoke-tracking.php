<?php
/**
 * 访客追踪（VISITOR-ANALYTICS-TEST-01，TRACKING-LIVE-01 正式版）冒烟测试：接收协议、去重 / 乱序 / 时钟、多标签页、访问切分、
 * Woo 服务端事实、后台查询和权限；正式版的不要追踪、保存期限、隐私请求、设置。开发环境专用。
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

// 4c. 离站判断（VISITOR-ARCHIVE-01）。
$T = static fn( string $type, int $signal ) => array( 'last_signal_ms' => $signal, 'last_type' => $type, 'visibility' => 'visible', 'activity' => 'active' );
$n = Tracker::now_ms();
$ok( 'presence: one tab left, another still beating -> active', 'active' === Query::presence( array( $T( 'page_exit', $n - 20000 ), $T( 'heartbeat', $n - 5000 ) ), $n ) );
$ok( 'presence: hidden tab still beating -> idle, not archived', 'idle' === Query::presence( array( array( 'last_signal_ms' => $n - 5000, 'last_type' => 'heartbeat', 'visibility' => 'hidden', 'activity' => 'idle' ) ), $n ) );
$ok( 'presence: every recent tab left 10s+ ago -> left', 'left' === Query::presence( array( $T( 'page_exit', $n - 20000 ), $T( 'page_exit', $n - 15000 ), $T( 'heartbeat', $n - 40 * 60000 ) ), $n ) );
$ok( 'presence: exit within the 10s grace (in-site navigation) -> not left', 'left' !== Query::presence( array( $T( 'page_exit', $n - 3000 ) ), $n ) );
$ok( 'presence: no signal for 50s without exit -> away (still live)', 'away' === Query::presence( array( $T( 'heartbeat', $n - 50000 ) ), $n ) );
$ok( 'presence: nothing for 30 min -> gone', 'gone' === Query::presence( array( $T( 'heartbeat', $n - 31 * 60000 ) ), $n ) );

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

// 7b. 当前购物车（VISITOR-CART-01）：读 Woo 会话本身；会话失效时退回最后的快照。
$_COOKIE[ Tracker::COOKIE ] = $vid1;
$C       = \PeptideCore\Tracking\Carts::class;
$cartRow = static function ( int $id ) use ( $wpdb ) {
	return $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, cart, cart_ms, cart_key FROM ' . Schema::table( 'visitors' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
};
// 命令行里 Woo 不会写会话表：按 Woo 的格式把当前购物车写成一行会话，模拟浏览器里有效的会话。
$sessions     = array();
$writeSession = static function ( string $key ) use ( $wpdb, &$sessions ) {
	$value = maybe_serialize(
		array(
			'cart'            => maybe_serialize( WC()->cart->get_cart_for_session() ),
			'cart_totals'     => maybe_serialize( WC()->cart->get_totals() ),
			'applied_coupons' => maybe_serialize( WC()->cart->get_applied_coupons() ),
		)
	);
	$wpdb->replace( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $key, 'session_value' => $value, 'session_expiry' => time() + HOUR_IN_SECONDS ) ); // phpcs:ignore
	$sessions[] = $key;
	\PeptideCore\Tracking\Carts::forget();
};
$wpdb->update( Schema::table( 'visitors' ), array( 'cart' => null, 'cart_ms' => 0, 'cart_key' => '' ), array( 'id' => $visitor['id'] ) ); // phpcs:ignore
$ok( 'no binding yet: unknown', 'unknown' === $C::view( $cartRow( $visitor['id'] ), false )['status'] );
WC()->cart->empty_cart();
if ( $simple ) {
	$skey = 'smoke_sess_' . substr( md5( (string) $now ), 0, 10 );
	WC()->cart->add_to_cart( $simple->get_id(), 2 );
	WC()->cart->calculate_totals();
	$C::save( $visitor['id'], WC()->cart, $skey );
	$writeSession( $skey );
	$detail = $C::detail( $visitor['id'] );
	$ok( 'cart read from the Woo session: items, quantity, prices, subtotal', 'active' === $detail['status'] && 2 === $detail['count'] && $simple->get_id() === $detail['items'][0]['product_id'] && 2.0 === (float) $detail['items'][0]['quantity'] && '' !== $detail['items'][0]['unit_price_text'] && abs( $detail['subtotal'] - (float) WC()->cart->get_subtotal() ) < 0.01 && $detail['updated_ms'] > 0 );
	wp_set_current_user( $admin->ID );
	$res = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/track/admin/visitors/' . $visitor['id'] . '/cart' ) );
	$ok( 'admin cart endpoint', 200 === $res->get_status() && 2 === $res->get_data()['count'] );
	$C::forget();
	$live = array_values( array_filter( Query::live( Tracker::now_ms() ), static fn( $l ) => $l['visitor']['id'] === $visitor['id'] ) );
	$ok( 'live list carries the cart summary', $live && 'active' === $live[0]['cart']['status'] && 2 === $live[0]['cart']['count'] );
	wp_set_current_user( 0 );

	// 同一个 Woo 会话被另一个访客编号共用：另一边改了车，两边看到的都是当前内容，不是各自的旧副本。
	$other_vid = md5( 'smoke-other-' . $now );
	$other     = Tracker::visitor( $other_vid, Tracker::now_ms() );
	$visitors[] = $other['id'];
	WC()->cart->set_quantity( array_key_first( WC()->cart->get_cart() ), 5 );
	WC()->cart->calculate_totals();
	$C::save( $other['id'], WC()->cart, $skey );
	$writeSession( $skey );
	$ok( 'shared session: both visitors see the current cart', 5 === $C::detail( $visitor['id'] )['count'] && 5 === $C::detail( $other['id'] )['count'] );

	// 游客登录后 Woo 把会话迁到账号：绑定跟着改。
	$user_key = (string) $customer;
	$C::migrate( $skey, $user_key );
	$ok( 'guest-to-account session migration moves the binding', $user_key === $cartRow( $visitor['id'] )['cart_key'] && $user_key === $cartRow( $other['id'] )['cart_key'] );
	$writeSession( $user_key );
	$ok( 'cart follows the migrated account session', 5 === $C::detail( $visitor['id'] )['count'] );

	// 会话不在了：退回最后的快照，状态 expired。
	$wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $user_key ) ); // phpcs:ignore
	$C::forget();
	$ok( 'session gone: expired with the last snapshot', 'expired' === $C::view( $cartRow( $visitor['id'] ), false )['status'] );

	// 空车。
	WC()->cart->empty_cart();
	$C::save( $visitor['id'], WC()->cart, $skey );
	$writeSession( $skey );
	$ok( 'emptied cart: empty', 'empty' === $C::view( $cartRow( $visitor['id'] ), false )['status'] );

	// 请求结束时记录；没有访客行且车是空的时不建行（爬虫等）。
	$C::mark();
	WC()->cart->add_to_cart( $simple->get_id(), 1 );
	$C::flush();
	$ok( 'hooks: cart change is captured at the end of the request', (string) WC()->session->get_customer_id() === $cartRow( $visitor['id'] )['cart_key'] );
	WC()->cart->empty_cart();
	$_COOKIE[ Tracker::COOKIE ] = md5( 'smoke-bot-' . $now );
	$before_rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) ); // phpcs:ignore
	$C::mark();
	$C::flush();
	$ok( 'empty cart with no visitor row creates nothing', $before_rows === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) ) ); // phpcs:ignore
	$_COOKIE[ Tracker::COOKIE ] = $vid1;
	foreach ( array_unique( $sessions ) as $k ) {
		$wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $k ) ); // phpcs:ignore
	}
}

// 7c. 归档列表、回访、分页。
$arch_vid = md5( 'smoke-archive-' . $now );
$_COOKIE[ Tracker::COOKIE ] = $arch_vid;
$archTab  = 'tab-' . substr( md5( 'arch' . $now ), 0, 12 );
$archPage = $uuid();
$t0       = Tracker::now_ms();
$shopPage = array( 'page_type' => 'shop', 'path' => '/shop/' );
$send(
	array(
		'tab_id' => $archTab,
		'events' => array(
			$ev( 'page_view', $t0 - 2000, $archPage, 1, $shopPage, array( 'visibility' => 'visible', 'activity' => 'active' ) ),
			$ev( 'heartbeat', $t0 - 1500, $archPage, 2, $shopPage, array( 'visibility' => 'visible', 'activity' => 'active' ) ),
			$ev( 'page_exit', $t0 - 1000, $archPage, 3, $shopPage, array( 'persisted' => false, 'visibility' => 'hidden', 'activity' => 'active' ) ),
		),
	)
);
$arch       = Tracker::visitor( $arch_vid, $t0 );
$visitors[] = $arch['id'];
$liveIds    = static fn() => array_map( static fn( $l ) => $l['visitor']['id'], Query::live( Tracker::now_ms() ) );
$ok( 'just left: still live during the 10s grace', in_array( $arch['id'], $liveIds(), true ) );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'tabs' ) . ' SET last_signal_ms = last_signal_ms - 15000 WHERE visitor_id = %d', $arch['id'] ) ); // phpcs:ignore
$ok( 'after the grace: removed from live', ! in_array( $arch['id'], $liveIds(), true ) );
$a1 = Query::archive( Tracker::now_ms(), array( 'q' => '#' . $arch['id'] ) );
$ok( 'archived with leave-signal reason and last page', 1 === count( $a1['items'] ) && 'exit_signal' === $a1['items'][0]['reason'] && '/shop/' === $a1['items'][0]['last_page']['path'] );
$archVisit = $a1['items'][0]['visit']['id'];
// 30 分钟内回来：回到实时列表，仍是同一次访问。
$send( array( 'tab_id' => $archTab, 'events' => array( $ev( 'page_view', Tracker::now_ms(), $uuid(), 1, array( 'page_type' => 'home', 'path' => '/' ), array( 'visibility' => 'visible', 'activity' => 'active' ) ) ) ) );
$visitCount = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'visits' ) . ' WHERE visitor_id = %d', $arch['id'] ) ); // phpcs:ignore
$ok( 'returning visitor is live again, same visit', in_array( $arch['id'], $liveIds(), true ) && ! Query::archive( Tracker::now_ms(), array( 'q' => '#' . $arch['id'] ) )['items'] && 1 === $visitCount );
// 那次访问变成 31 分钟前：超时推定进归档，事件还在。
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'visits' ) . ' SET last_ms = %d, start_ms = %d WHERE id = %d', Tracker::now_ms() - 31 * 60000, Tracker::now_ms() - 40 * 60000, $archVisit ) ); // phpcs:ignore
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'tabs' ) . ' SET last_signal_ms = %d WHERE visitor_id = %d', Tracker::now_ms() - 31 * 60000, $arch['id'] ) ); // phpcs:ignore
$a2     = Query::archive( Tracker::now_ms(), array( 'q' => '#' . $arch['id'] ) );
$events = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . ' WHERE visit_id = %d', $archVisit ) ); // phpcs:ignore
$ok( 'old visit archived by timeout, events kept', 1 === count( $a2['items'] ) && 'timeout' === $a2['items'][0]['reason'] && $events > 0 );
$ok( 'archive filters: device and date', ! Query::archive( Tracker::now_ms(), array( 'q' => '#' . $arch['id'], 'device' => 'tablet' ) )['items'] && ! Query::archive( Tracker::now_ms(), array( 'q' => '#' . $arch['id'], 'from_ms' => Tracker::now_ms() + 86400000 ) )['items'] );
// 分页：时间线一页 1 条，往后翻不重复；心跳在服务器端过滤。
$p1 = Query::timeline( $archVisit, null, 1 );
$p2 = Query::timeline( $archVisit, $p1['next_cursor'], 1 );
$ok( 'timeline pages forward without repeats', $p1['has_more'] && 1 === count( $p1['events'] ) && $p2['events'] && $p1['events'][0]['id'] !== $p2['events'][0]['id'] );
$no_hb = array_column( Query::timeline( $archVisit, null, 500 )['events'], 'type' );
$hb    = array_column( Query::timeline( $archVisit, null, 500, true )['events'], 'type' );
$ok( 'heartbeats filtered on the server', ! in_array( 'heartbeat', $no_hb, true ) && in_array( 'heartbeat', $hb, true ) );
$vp1 = Query::visits_page( $visitor['id'], null, 1 );
$vp2 = Query::visits_page( $visitor['id'], $vp1['next_cursor'], 1 );
$ok( 'visit history pages', $vp1['has_more'] && $vp2['items'] && $vp1['items'][0]['id'] !== $vp2['items'][0]['id'] );
$_COOKIE[ Tracker::COOKIE ] = $vid1;

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
$req = new WP_REST_Request( 'GET', '/peptide/v1/track/admin/archive' );
$req->set_param( 'limit', 2 );
$res = rest_do_request( $req );
$ok( 'admin archive endpoint pages', 200 === $res->get_status() && count( $res->get_data()['items'] ) <= 2 && array_key_exists( 'next_cursor', $res->get_data() ) );
$ok( 'admin visits endpoint', 200 === rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/track/admin/visitors/' . $visitor['id'] . '/visits' ) )->get_status() );
wp_set_current_user( $customer );
$ok( 'customers cannot read visitor data', in_array( rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/track/admin/live' ) )->get_status(), array( 401, 403 ), true ) );
$ok( 'customers cannot read the archive', in_array( rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/track/admin/archive' ) )->get_status(), array( 401, 403 ), true ) );
wp_set_current_user( 0 );
$cfg = Tracker::observer_config( array( 'enabled' => false ) );
$ok( 'theme config: enabled with endpoint and timings', true === $cfg['enabled'] && 15000 === $cfg['heartbeat_ms'] && 20 === $cfg['batch_max'] );

// 8b. 正式版（TRACKING-LIVE-01）。
$ok( 'live version: theme config says test_mode false, mode live', false === $cfg['test_mode'] && 'live' === $cfg['mode'] );
$ok( 'new data is not marked as test data', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT is_test FROM ' . Schema::table( 'visitors' ) . ' WHERE id = %d', $visitor['id'] ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . ' WHERE visitor_id = %d AND is_test = 1', $visitor['id'] ) ) ); // phpcs:ignore
$_SERVER['HTTP_SEC_GPC'] = '1';
$cfg_gpc = Tracker::observer_config( array( 'enabled' => false ) );
$req     = new WP_REST_Request( 'POST', '/peptide/v1/track/events' );
$req->set_body( '{}' );
$ok( 'a browser with GPC is not tracked: the theme stays off and events are refused', false === $cfg_gpc['enabled'] && 403 === rest_do_request( $req )->get_status() );
Tracker::save_settings( 180, false );
$ok( 'GPC can be switched off in the settings', ! Tracker::declined() );
Tracker::save_settings( 180, true );
unset( $_SERVER['HTTP_SEC_GPC'] );
$_COOKIE[ \PeptideCore\Pixels::OPTOUT ] = '1';
$ok( 'a browser that used the opt-out link is not tracked', Tracker::declined() );
unset( $_COOKIE[ \PeptideCore\Pixels::OPTOUT ] );
Tracker::save_settings( 45, true );
$ok( 'retention must be 30 / 90 / 180 / 365 days (otherwise 180)', 180 === Tracker::settings()['retention_days'] );
Tracker::save_settings( 90, true );
$ok( 'retention saved, switch untouched', 90 === Tracker::settings()['retention_days'] && Tracker::enabled() );
$ok( 'the daily cleanup is scheduled while tracking is on', (bool) wp_next_scheduled( \PeptideCore\Tracking\Privacy::PRUNE_HOOK ) );

// 保存期限：200 天前的访客和访问被删掉，近期的不动。
$old_ms    = Tracker::now_ms() - 200 * DAY_IN_SECONDS * 1000;
$old       = Tracker::visitor( bin2hex( random_bytes( 16 ) ), $old_ms );
$visitors[] = $old['id'];
$old_visit = Tracker::visit( $old['id'], $old_ms, '/old-page' );
\PeptideCore\Tracking\Privacy::prune( 180, Tracker::now_ms() );
$count = static fn( string $table, string $col, int $id ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( $table ) . " WHERE {$col} = %d", $id ) ); // phpcs:ignore
$ok( 'data older than the retention period is deleted', 0 === $count( 'visitors', 'id', $old['id'] ) && 0 === $count( 'visits', 'id', $old_visit ) );
$ok( 'recent data is kept', 1 === $count( 'visitors', 'id', $visitor['id'] ) && $count( 'events', 'visitor_id', $visitor['id'] ) > 0 );

// 顾客的隐私请求：导出 / 抹除个人数据。
$email   = 'trk-privacy-' . strtolower( wp_generate_password( 6, false ) ) . '@example.com';
// 新建用户会触发注册事件，把当前浏览器的访客关联过去；这里先去掉测试浏览器的访客 cookie。
$saved_vid = $_COOKIE[ Tracker::COOKIE ] ?? null;
unset( $_COOKIE[ Tracker::COOKIE ] );
$pu      = wp_create_user( 'trkprivacy' . wp_rand( 1000, 9999 ), wp_generate_password(), $email );
if ( null !== $saved_vid ) {
	$_COOKIE[ Tracker::COOKIE ] = $saved_vid;
}
$users[] = $pu;
$pv      = Tracker::visitor( bin2hex( random_bytes( 16 ) ), Tracker::now_ms() );
$visitors[] = $pv['id'];
Tracker::link_user( $pv['id'], $pu );
Tracker::visit( $pv['id'], Tracker::now_ms(), '/privacy-landing' );
$exported = \PeptideCore\Tracking\Privacy::export( $email );
$ok( 'export personal data includes the customer’s visits', 1 === count( $exported['data'] ) && in_array( '/privacy-landing', array_column( $exported['data'][0]['data'], 'value' ), true ) );
$erased = \PeptideCore\Tracking\Privacy::erase( $email );
$ok( 'erase personal data deletes the customer’s tracking data', $erased['items_removed'] && 0 === $count( 'visitors', 'id', $pv['id'] ) && 0 === $count( 'visits', 'visitor_id', $pv['id'] ) );
$ok( 'exporter and eraser are registered with WordPress', isset( apply_filters( 'wp_privacy_personal_data_exporters', array() )['peptide-core-visits'] ) && isset( apply_filters( 'wp_privacy_personal_data_erasers', array() )['peptide-core-visits'] ) );

// 删除测试版数据只删 is_test = 1 的行。
$tv = Tracker::visitor( bin2hex( random_bytes( 16 ) ), Tracker::now_ms() );
$visitors[] = $tv['id'];
$wpdb->update( Schema::table( 'visitors' ), array( 'is_test' => 1 ), array( 'id' => $tv['id'] ) ); // phpcs:ignore
$ok( 'test-version visitors are counted', Schema::test_visitors() >= 1 );
Schema::purge_test();
$ok( 'deleting test data removes only the test-version rows', 0 === $count( 'visitors', 'id', $tv['id'] ) && 1 === $count( 'visitors', 'id', $visitor['id'] ) );

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
\PeptideCore\Tracking\Privacy::schedule( Tracker::enabled() );
$ok( 'test data removed', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . ' WHERE visitor_id = %d', $visitor['id'] ) ) ); // phpcs:ignore
$ok( 'tracking switch restored', ( null === $orig_option ) === ( false === get_option( Tracker::OPTION ) ) );
