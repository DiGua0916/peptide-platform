<?php
/**
 * 广告像素（PIXELS-01）冒烟测试：设置清理（ID、域名、token 保留 / 清除）、谁不回传（员工、GPC、退出）、商品和订单事件、
 * 首单 / 回传比例、加购队列、服务端回传（Facebook Conversions API、TikTok Events API 的请求内容，拦截不真的发出）、
 * 订单付款时回传一次、前台配置不含 token、后台页面。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-pixels.php
 * 所有对外请求都被 pre_http_request 拦下；测试建的商品、订单和发送记录在结束时删除，设置恢复原样。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Pixels_Page;
use PeptideCore\Pixels;
use PeptideCore\Pixels\Server;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

global $wpdb;
add_filter( 'pre_wp_mail', '__return_false' );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$saved  = get_option( Pixels::OPTION, null );
$made   = array();
$orders = array();
if ( ! Server::installed() ) {
	Server::install();
}
$log_max = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Server::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

// 所有对外请求拦下来记住。
$requests = array();
$reply    = array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => '{"events_received":1,"code":0}', 'headers' => array(), 'cookies' => array() );
$catch    = static function ( $pre, $args, $url ) use ( &$requests, &$reply ) {
	$requests[] = array( 'url' => $url, 'args' => $args, 'body' => json_decode( (string) ( $args['body'] ?? '' ), true ) );
	return $reply;
};
add_filter( 'pre_http_request', $catch, 1, 3 );
Server::$sync = true;

// 1. 设置清理。
[ $s, $e ] = Pixels::clean(
	array(
		'gpc'       => 1,
		'match'     => 1,
		'platforms' => array(
			'ga4'      => array( 'ids' => "G-ABC123\nG-ABC123, G-XYZ9\nbad id!" ),
			'facebook' => array( 'pixels' => array( array( 'id' => '111222333', 'note' => 'Tom', 'domain' => 'https://www.Example.com/path', 'token' => 'EAAtok123' ) ), 'ratio' => 150 ),
			'gads'     => array( 'ids' => 'AW-123456', 'purchase' => "AW-123456/AbC\nnot-a-label" ),
			'yahoo'    => array( 'ids' => '10001' ),
		),
	),
	Pixels::defaults()
);
$ga = array_column( $s['platforms']['ga4']['pixels'], 'id' );
$ok( 'IDs: one per line or comma, duplicates and symbols removed', array( 'G-ABC123', 'G-XYZ9', 'badid' ) === $ga );
$ok( 'a GA4 ID that does not start with G- is flagged', (bool) array_filter( $e, static fn( $m ) => str_contains( $m, 'badid' ) ) );
$fb = $s['platforms']['facebook']['pixels'][0];
$ok( 'pixel row: domain kept as bare host, note and token stored', 'example.com' === $fb['domain'] && 'Tom' === $fb['note'] && 'EAAtok123' === $fb['token'] );
$ok( 'report ratio above 100% is flagged and capped', 100 === $s['platforms']['facebook']['ratio'] && (bool) array_filter( $e, static fn( $m ) => str_contains( $m, '1%' ) ) );
$ok( 'Google Ads purchase conversion must look like AW-…/label', array( 'AW-123456/AbC' ) === $s['platforms']['gads']['purchase'] && (bool) array_filter( $e, static fn( $m ) => str_contains( $m, 'not-a-label' ) ) );
$ok( 'Yahoo needs a project ID too', (bool) array_filter( $e, static fn( $m ) => str_contains( $m, 'Yahoo' ) ) );
[ $s2 ] = Pixels::clean( array( 'platforms' => array( 'facebook' => array( 'pixels' => array( array( 'id' => '111222333', 'token' => '' ) ) ) ) ), $s );
$ok( 'saving again without a token keeps the saved token', 'EAAtok123' === $s2['platforms']['facebook']['pixels'][0]['token'] );
[ $s3 ] = Pixels::clean( array( 'platforms' => array( 'facebook' => array( 'pixels' => array( array( 'id' => '111222333', 'token' => '', 'clear_token' => '1' ) ) ) ) ), $s );
$ok( '“remove the saved token” clears it', '' === $s3['platforms']['facebook']['pixels'][0]['token'] );
[ $s4 ] = Pixels::clean( array(), $s );
$ok( 'no rows posted = all pixels removed', array() === $s4['platforms']['facebook']['pixels'] && ! Pixels::any( $s4 ) );
[ $s5, $e5 ] = Pixels::clean( array( 'platforms' => array( 'twitter' => array( 'ids' => 'o1abc', 'event_ids' => array( 'purchase' => 'tw-o1abc-o2def, not-an-id', 'add_to_cart' => 'tw-o1abc-o2xyz' ) ) ) ), Pixels::defaults() );
$ok( 'X: event IDs per event, a wrong one is flagged', array( 'tw-o1abc-o2def' ) === $s5['platforms']['twitter']['event_ids']['purchase'] && array( 'tw-o1abc-o2xyz' ) === $s5['platforms']['twitter']['event_ids']['add_to_cart'] && (bool) array_filter( $e5, static fn( $m ) => str_contains( $m, 'not-an-id' ) ) );
$ok( 'every platform has its official event names; TikTok no longer sends the retired PlaceAnOrder', ! array_filter( Pixels::platforms(), static fn( $d ) => ! $d['events'] ) && ! isset( Pixels::platforms()['tiktok']['events']['place_order'] ) && 'contentView' === Pixels::platforms()['kwai']['events']['view_item'] && 'ec_add_cart' === Pixels::platforms()['bigo']['events']['add_to_cart'] && 'viewProduct' === Pixels::platforms()['vk']['events']['view_item'] && 'start_checkout' === Pixels::platforms()['mediago']['events']['begin_checkout'] );
$ok( 'phone numbers get the country code: (555) 010-0200 in the US = 15550100200', '15550100200' === Pixels::phone_digits( '(555) 010-0200', 'US' ) && '15550100200' === Pixels::phone_digits( '+1 555-010-0200' ) && '447911123456' === Pixels::phone_digits( '07911 123456', 'GB' ) && '' === Pixels::phone_digits( '12', 'US' ) );

// 2. 谁不回传。
delete_option( Pixels::OPTION );
$ok( 'no pixels: nothing is reported', ! Pixels::allowed() );
Pixels::save( array( 'gpc' => 1, 'match' => 1, 'platforms' => array( 'facebook' => array( 'pixels' => array( array( 'id' => '111222333', 'token' => 'EAAtok123' ) ), 'ratio' => 100 ), 'tiktok' => array( 'pixels' => array( array( 'id' => 'CTT123', 'token' => 'tttok' ), array( 'id' => 'CTTOTHER', 'token' => 'x', 'domain' => 'other-shop.com' ) ) ), 'ga4' => array( 'ids' => 'G-ABC123' ) ) ) );
$ok( 'staff are not reported by default', ! Pixels::allowed() );
wp_set_current_user( 0 );
$ok( 'a visitor is reported', Pixels::allowed() );
$_SERVER['HTTP_SEC_GPC'] = '1';
$ok( 'a browser with the GPC signal is not reported', ! Pixels::allowed() );
unset( $_SERVER['HTTP_SEC_GPC'] );
$_COOKIE[ Pixels::OPTOUT ] = '1';
$ok( 'a browser that opted out is not reported', ! Pixels::allowed() );
unset( $_COOKIE[ Pixels::OPTOUT ] );

// 3. 商品和事件。
$p = new WC_Product_Simple();
$p->set_name( 'PC px Simple' );
$p->set_regular_price( '25' );
$p->set_sku( 'PCPX-S' );
$p->set_status( 'publish' );
$p->update_meta_data( '_pc_spu', 'SPU-PX1' );
$simple = $p->save();
$made[] = $simple;
$item   = Pixels::item( wc_get_product( $simple ), 2 );
$ok( 'item: SPU, SKU, name, price and quantity', 'SPU-PX1' === $item['spu'] && 'PCPX-S' === $item['sku'] && 'PC px Simple' === $item['name'] && 25.0 === $item['price'] && 2 === $item['quantity'] );
$ok( 'in-ratio choice is fixed per order and about the ratio', Pixels::in_ratio( 7, 100 ) && Pixels::in_ratio( 7, 50 ) === Pixels::in_ratio( 7, 50 ) && abs( count( array_filter( range( 1, 2000 ), static fn( $i ) => Pixels::in_ratio( $i, 30 ) ) ) / 2000 - 0.3 ) < 0.05 );
query_posts( array( 'post_type' => 'product', 'p' => $simple ) );
$events = Pixels::page_events();
wp_reset_query();
$ok( 'product page: page_view + view_item with the product', 'page_view' === $events[0]['name'] && 'view_item' === ( $events[1]['name'] ?? '' ) && 'SPU-PX1' === $events[1]['items'][0]['spu'] && 25.0 === $events[1]['value'] );

// 4. 加购：放进队列，同时由服务端回传。
$requests = array();
wc_load_cart();
WC()->cart->empty_cart();
Pixels::pop_queue();
$_SERVER['HTTP_USER_AGENT'] = 'PC smoke UA';
$_COOKIE['_fbp']            = 'fb.1.1700000000000.123';
$_COOKIE['_ttp']            = 'ttp-smoke';
WC()->cart->add_to_cart( $simple, 2 );
$queue = Pixels::pop_queue();
$ok( 'add to cart is queued for the browser, then the queue is empty', 1 === count( $queue ) && 'add_to_cart' === $queue[0]['name'] && 50.0 === $queue[0]['value'] && array() === Pixels::pop_queue() );
$fbreq = array_values( array_filter( $requests, static fn( $r ) => str_contains( $r['url'], 'graph.facebook.com' ) ) );
$ttreq = array_values( array_filter( $requests, static fn( $r ) => str_contains( $r['url'], 'business-api.tiktok.com' ) ) );
$fbev  = $fbreq[0]['body']['data'][0] ?? array();
$ok( 'Facebook Conversions API: pixel URL with token, AddToCart, same event ID as the browser', $fbreq && str_contains( $fbreq[0]['url'], '/' . Server::GRAPH . '/111222333/events?access_token=EAAtok123' ) && 'AddToCart' === ( $fbev['event_name'] ?? '' ) && $queue[0]['id'] === ( $fbev['event_id'] ?? '' ) && 'website' === $fbev['action_source'] );
$ok( 'Facebook: browser data and product data', 'PC smoke UA' === $fbev['user_data']['client_user_agent'] && 'fb.1.1700000000000.123' === $fbev['user_data']['fbp'] && array( 'SPU-PX1' ) === $fbev['custom_data']['content_ids'] && 2 === $fbev['custom_data']['num_items'] && 'USD' === $fbev['custom_data']['currency'] );
$ttbody = $ttreq[0]['body'] ?? array();
$ok( 'TikTok Events API: header token, pixel, AddToCart, ttp cookie; the pixel bound to another domain is not used', 1 === count( $ttreq ) && 'tttok' === ( $ttreq[0]['args']['headers']['Access-Token'] ?? '' ) && 'CTT123' === $ttbody['event_source_id'] && 'AddToCart' === $ttbody['data'][0]['event'] && 'ttp-smoke' === $ttbody['data'][0]['user']['ttp'] && $queue[0]['id'] === $ttbody['data'][0]['event_id'] );
$logged = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Server::table() . ' WHERE id > %d AND ok = 1', $log_max ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( 'each send is written to the log', $logged >= 2 );
$reply = array( 'response' => array( 'code' => 400, 'message' => 'Bad' ), 'body' => '{"error":{"message":"Invalid OAuth access token"}}', 'headers' => array(), 'cookies' => array() );
Server::send( array( Pixels::make( 'view_item', array( $item ) ) ), array() );
$bad = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Server::table() . ' WHERE id > %d AND ok = 0 ORDER BY id DESC LIMIT 1', $log_max ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( 'a refused send is logged with the HTTP code and message', $bad && 400 === (int) $bad['code'] && str_contains( $bad['message'], 'Invalid OAuth' ) );
$reply = array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => '{"code":0}', 'headers' => array(), 'cookies' => array() );

// 5. 订单：付款时服务端回传一次，带下单时记下的顾客信息。
$order = wc_create_order();
$order->add_product( wc_get_product( $simple ), 2 );
$order->set_billing_email( 'PX-Buyer@Example.com' );
$order->set_billing_phone( '+1 (555) 010-0200' );
$ship = new WC_Order_Item_Shipping();
$ship->set_method_title( 'Smoke shipping' );
$ship->set_total( 5 );
$order->add_item( $ship );
$order->calculate_totals( false );
$order->save();
$orders[] = $order->get_id();
Server::capture_context( $order );
$requests = array();
$order    = wc_get_order( $order->get_id() );
$order->set_status( 'processing' );
$order->save();
$purchase = array_values( array_filter( $requests, static fn( $r ) => str_contains( $r['url'], 'graph.facebook.com' ) ) );
$pev      = $purchase[0]['body']['data'][0] ?? array();
$ok( 'paid order: Purchase sent once with the order total and order ID', 'Purchase' === ( $pev['event_name'] ?? '' ) && 'order_' . $order->get_id() === $pev['event_id'] && 55.0 === (float) $pev['custom_data']['value'] && (string) $order->get_order_number() === $pev['custom_data']['order_id'] );
$ok( 'customer email and phone are sent only as SHA-256 hashes', hash( 'sha256', 'px-buyer@example.com' ) === $pev['user_data']['em'][0] && hash( 'sha256', '15550100200' ) === $pev['user_data']['ph'][0] && ! str_contains( (string) wp_json_encode( $purchase[0]['body'] ), 'Example.com' ) );
$ok( 'Facebook uses Marketing API v26.0', 'v26.0' === Server::GRAPH && str_contains( $purchase[0]['url'], '/v26.0/' ) );
$ttpur = array_values( array_filter( $requests, static fn( $r ) => str_contains( $r['url'], 'business-api.tiktok.com' ) ) );
$ok( 'TikTok gets the phone as E.164 (+15550100200) before hashing', hash( 'sha256', '+15550100200' ) === ( $ttpur[0]['body']['data'][0]['user']['phone'] ?? '' ) && 'Purchase' === ( $ttpur[0]['body']['data'][0]['event'] ?? '' ) );
$old = Pixels::make( 'view_item', array( $item ) );
$old['time'] = time() - 10 * DAY_IN_SECONDS;
$ok( 'an event older than 7 days is sent with a recent time (Meta refuses the whole batch otherwise)', Server::facebook_body( array( $old ), array(), array(), Pixels::settings() )['data'][0]['event_time'] >= time() - 7 * DAY_IN_SECONDS );
$requests = array();
$order    = wc_get_order( $order->get_id() );
$order->set_status( 'completed' );
$order->save();
$ours = static fn( array $list ) => array_values( array_filter( $list, static fn( $r ) => str_contains( $r['url'], 'graph.facebook.com' ) || str_contains( $r['url'], 'business-api.tiktok.com' ) ) );
$ok( 'later status changes do not send it again', ! $ours( $requests ) );
$manual = wc_create_order();
$manual->add_product( wc_get_product( $simple ), 1 );
$manual->calculate_totals();
$manual->save();
$orders[] = $manual->get_id();
$requests = array();
$manual->set_status( 'processing' );
$manual->save();
$ok( 'an order without checkout data (made in the admin) is not reported', ! $ours( $requests ) );
$ev = Pixels::order_event( wc_get_order( $order->get_id() ) );
$ok( 'order event: total, product amount, tax and shipping', 55.0 === $ev['value'] && 50.0 === $ev['items_value'] && 5.0 === $ev['shipping'] && $ev['first_order'] );

// 首单：同一邮箱的第二单不回传给 Facebook。
$s = Pixels::settings();
$s['platforms']['facebook']['first_order_only'] = true;
update_option( Pixels::OPTION, $s, false );
$second = wc_create_order();
$second->add_product( wc_get_product( $simple ), 1 );
$second->set_billing_email( 'px-buyer@example.com' );
$second->calculate_totals();
$second->save();
$orders[] = $second->get_id();
$ev2      = Pixels::order_event( $second );
$ok( 'first order only: a repeat customer’s purchase skips Facebook', ! $ev2['first_order'] && in_array( 'facebook', $ev2['skip'], true ) );
Server::capture_context( $second );
$requests = array();
$second   = wc_get_order( $second->get_id() );
$second->set_status( 'processing' );
$second->save();
$ok( 'so only TikTok gets it', ! array_filter( $requests, static fn( $r ) => str_contains( $r['url'], 'graph.facebook.com' ) ) && array_filter( $requests, static fn( $r ) => str_contains( $r['url'], 'tiktok.com' ) ) );

// 6. 前台配置和后台页面。
$cfg = Pixels::browser_config( Pixels::settings(), array() );
$ok( 'browser config: IDs and event names, never the access tokens', isset( $cfg['platforms']['facebook'], $cfg['platforms']['tiktok'], $cfg['platforms']['ga4'] ) && ! str_contains( (string) wp_json_encode( $cfg ), 'EAAtok123' ) && ! str_contains( (string) wp_json_encode( $cfg ), 'tttok' ) && 'AddToCart' === $cfg['platforms']['facebook']['events']['add_to_cart'] );
wp_dequeue_script( 'peptide-core-pixels' );
Pixels::enqueue();
$ok( 'the pixel script loads for visitors', wp_script_is( 'peptide-core-pixels', 'enqueued' ) );
wp_dequeue_script( 'peptide-core-pixels' );
wp_set_current_user( $admin->ID );
Pixels::enqueue();
$ok( 'and not for staff', ! wp_script_is( 'peptide-core-pixels', 'enqueued' ) );
ob_start();
Pixels_Page::render();
$html = (string) ob_get_clean();
$ok( 'admin page: every platform card, masked token, notes and settings', 23 === substr_count( $html, 'class="pc-px-card" id="pc-px-' ) && str_contains( $html, 'EAAtok…' ) && ! str_contains( $html, 'EAAtok123' ) && str_contains( $html, 'pc-px-notes' ) && str_contains( $html, 'name="test_code"' ) );
$ok( 'admin page: X event ID fields and the per-platform notes', str_contains( $html, 'name="platforms[twitter][event_ids][purchase]"' ) && str_contains( $html, 'Event IDs' ) && str_contains( $html, 'only the first ID is used' ) );

// 7. 清理。
remove_filter( 'pre_http_request', $catch, 1 );
Server::$sync = false;
unset( $_COOKIE['_fbp'], $_COOKIE['_ttp'] );
WC()->cart->empty_cart();
Pixels::pop_queue();
foreach ( $orders as $oid ) {
	$o = wc_get_order( $oid );
	if ( $o ) {
		$o->delete( true );
	}
}
foreach ( $made as $id ) {
	wc_get_product( $id )->delete( true );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Server::table() . ' WHERE id > %d', $log_max ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
if ( null === $saved ) {
	delete_option( Pixels::OPTION );
} else {
	update_option( Pixels::OPTION, $saved, false );
}
$ok( 'test data removed and settings restored', ! wc_get_product( $simple ) && $saved === get_option( Pixels::OPTION, null ) );
