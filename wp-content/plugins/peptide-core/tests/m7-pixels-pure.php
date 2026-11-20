<?php
/**
 * FE-09 / ST-06：真正的 Pixels、Pixels\Server、Pixels\Acceptance，只把 WordPress、WooCommerce 和网络换成桩。
 * 纯逻辑：php -n m7-pixels-pure.php
 *
 * 要证明的事：
 * 2026-11-17 起 facebook 不在 Server::SERVER_PLATFORMS 里（0a649df6）：Meta 的服务端回传改由
 * wp-capi-stealth-bridge 承担，peptide-core 不再直连 graph.facebook.com。服务端那条路用仍然走服务端的
 * tiktok 验证，同时断言带令牌、域名也对得上的 facebook 像素一个请求都不发。
 *
 * 1. 结账页“选支付”：服务端生成的 add_payment_info 事件，发给平台的 event_id 和交给浏览器的是同一个；
 *    一分钟内不重复回传；购物车空、顾客退出回传时不发。
 * 2. 像素页对投手只提交 Facebook 时，其他平台已保存的像素和令牌不会被清空。
 * 3. 浏览器配置：普通访客拿不到验收上报地址，带验收码的浏览器才有。
 * 4. “发送测试事件”按钮：Meta 已改走桥，这里不再直连（返回格式开店向导第 4 步要读 ok / pixel / message）。
 */
require __DIR__ . '/m7-stubs.php';

use PeptideCore\Pixels;
use PeptideCore\Pixels\Acceptance;
use PeptideCore\Pixels\Server;

$wpdb                    = new T_DB();
$GLOBALS['t_uuid']       = 0;
$GLOBALS['t_requests']   = array();
$GLOBALS['t_cart_loads'] = 0;
$GLOBALS['t_http']       = array( 'code' => 200, 'body' => '{"events_received":1}' );
$GLOBALS['t_wc']         = new T_WC();
$GLOBALS['t_cart']       = new T_Cart();
$GLOBALS['t_session']    = new T_Session();
$GLOBALS['t_cart']->lines = array( array( 'data' => new WC_Product( 501 ), 'quantity' => 2, 'line_subtotal' => 39.98 ) );
$GLOBALS['m6_options']['peptide_core_px_log_db'] = '1'; // 发送记录表当作已经建好。
$GLOBALS['m6_options']['home']                   = 'https://www.store.test'; // 店铺绑定的域名（像素的关联域名默认就是它，不含 www.）。
$GLOBALS['m6_options'][ Pixels::OPTION ]         = array(
	'test_code' => 'TEST123',
	'platforms' => array(
		'facebook' => array( 'pixels' => array( array( 'id' => '111', 'note' => 'main', 'domain' => '', 'token' => 'fbtoken' ) ) ),
		'tiktok'   => array( 'pixels' => array( array( 'id' => 'TT1', 'note' => '', 'domain' => '', 'token' => 'tttoken' ) ) ),
	),
);
$post  = static fn(): WP_REST_Response => Pixels::rest_event( new WP_REST_Request( array( 'name' => 'add_payment_info' ) ) );
$to_fb = static fn(): array => array_values( array_filter( $GLOBALS['t_requests'], static fn( array $r ): bool => str_contains( $r['url'], 'graph.facebook.com' ) ) );
$to_tt = static fn(): array => array_values( array_filter( $GLOBALS['t_requests'], static fn( array $r ): bool => str_contains( $r['url'], 'business-api.tiktok.com' ) ) );

// ---------- 1. 结账页“选支付” ----------
$response = $post();
$events   = $response->data['events'];
m6_check( 'the server hands the browser exactly one add_payment_info event', 1 === count( $events ) && 'add_payment_info' === $events[0]['name'] );
m6_check( 'the event carries the cart: total, currency, items', 54.98 === $events[0]['value'] && 'USD' === $events[0]['currency'] && 2 === $events[0]['items'][0]['quantity'] && '501' === $events[0]['items'][0]['id'] );
m6_check( 'the event points at the checkout page the request came from', 'https://store.test/checkout/' === $events[0]['url'] );
$tt = $to_tt();
m6_check( 'one events API request for the TikTok pixel, its token in the header and never in the body', 1 === count( $tt ) && 'TT1' === $tt[0]['body']['event_source_id'] && 'tttoken' === $tt[0]['args']['headers']['Access-Token'] && ! str_contains( json_encode( $tt[0]['body'] ), 'tttoken' ) );
m6_check( 'nothing goes to Meta directly, although the Facebook pixel has a token and matches the domain', array() === $to_fb() );
m6_check( 'server event is AddPaymentInfo', 'AddPaymentInfo' === $tt[0]['body']['data'][0]['event'] && 'web' === $tt[0]['body']['event_source'] );
m6_check( 'FE-09: the server event_id is the id given to the browser', $events[0]['id'] === $tt[0]['body']['data'][0]['event_id'] && str_starts_with( $events[0]['id'], 'add_' ) );
m6_check( 'server event keeps the platform cookies, IP and user agent', 'tt.1.1700000000000.987654' === $tt[0]['body']['data'][0]['user']['ttp'] && 'TTCLID-abc123' === $tt[0]['body']['data'][0]['user']['ttclid'] && '203.0.113.9' === $tt[0]['body']['data'][0]['user']['ip'] && str_contains( $tt[0]['body']['data'][0]['user']['user_agent'], 'FBIOS' ) );
m6_check( 'the server event carries the cart total and the page it happened on', 54.98 === $tt[0]['body']['data'][0]['properties']['value'] && 'USD' === $tt[0]['body']['data'][0]['properties']['currency'] && 'https://store.test/checkout/' === $tt[0]['body']['data'][0]['page']['url'] );
m6_check( 'the send is written to the send log with the same id', 'AddPaymentInfo' === $wpdb->log[0]['event'] && $events[0]['id'] === $wpdb->log[0]['event_id'] && 1 === $wpdb->log[0]['ok'] && 'tiktok' === $wpdb->log[0]['platform'] );
m6_check( 'the cart is loaded for the REST request and the shopping session is never written', 1 === $GLOBALS['t_cart_loads'] && 0 === $GLOBALS['t_session']->writes );
m6_check( 'responses are never cached', 'private, no-store, max-age=0' === $response->headers['Cache-Control'] );

$again = $post();
m6_check( 'a second request within a minute sends nothing and returns no event', array() === $again->data['events'] && 1 === count( $to_tt() ) );
$GLOBALS['m6_transients'] = array();
$third                    = $post();
m6_check( 'after the minute a new event has a new id', 1 === count( $third->data['events'] ) && $third->data['events'][0]['id'] !== $events[0]['id'] && 2 === count( $to_tt() ) );

$GLOBALS['m6_transients']  = array();
$GLOBALS['t_cart']->lines = array();
m6_check( 'an empty cart sends nothing', array() === $post()->data['events'] && 2 === count( $to_tt() ) );
$GLOBALS['t_cart']->lines = array( array( 'data' => new WC_Product( 501 ), 'quantity' => 1, 'line_subtotal' => 19.99 ) );
$_COOKIE[ Pixels::OPTOUT ] = '1';
m6_check( 'a browser that opted out gets no event and nothing is sent', array() === $post()->data['events'] && 2 === count( $to_tt() ) );
unset( $_COOKIE[ Pixels::OPTOUT ] );
$_SERVER['HTTP_SEC_GPC'] = '1';
m6_check( 'a GPC browser gets no event and nothing is sent', array() === $post()->data['events'] && 2 === count( $to_tt() ) );
unset( $_SERVER['HTTP_SEC_GPC'] );
\PeptideCore\Access::$staff = true;
m6_check( 'staff get no event and nothing is sent', array() === $post()->data['events'] && 2 === count( $to_tt() ) );
\PeptideCore\Access::$staff = false;
// 员工角色（投手）在前台没有 manage_woocommerce，也不触发像素。桩里的当前用户编号是 0。
\PeptideCore\Staff::$ids = array( 0 );
m6_check( 'a staff-role user (media buyer) fires no pixel either', false === Pixels::allowed() );
\PeptideCore\Staff::$ids = array();
m6_check( 'ordinary visitors are still allowed', true === Pixels::allowed() );
m6_check( 'only add_payment_info is served by this endpoint', array() === Pixels::rest_event( new WP_REST_Request( array( 'name' => 'purchase' ) ) )->data['events'] );

// ---------- 2. 像素页对投手只提交 Facebook ----------
$form = array( 'gpc' => '1', 'match' => '1', 'timing' => 'load', 'test_code' => 'TEST123', 'platforms' => array( 'facebook' => array( 'pixels' => array( array( 'id' => '111', 'note' => 'main', 'domain' => '', 'token' => '' ), array( 'id' => '222', 'note' => 'second', 'domain' => '', 'token' => 'newtoken' ) ), 'content_id' => 'spu', 'ratio' => '100' ) ) );
$keep = array_values( array_diff( array_keys( Pixels::platforms() ), array( 'facebook' ) ) );
$errors = Pixels::save( $form, $keep );
$saved  = Pixels::settings();
m6_check( 'buyer save: no errors', array() === $errors );
m6_check( 'buyer save: Facebook pixels follow the form, an untouched token is kept', array( '111', '222' ) === array_column( $saved['platforms']['facebook']['pixels'], 'id' ) && 'fbtoken' === $saved['platforms']['facebook']['pixels'][0]['token'] && 'newtoken' === $saved['platforms']['facebook']['pixels'][1]['token'] );
m6_check( 'buyer save: platforms that were not in the form keep their pixels and tokens', 'TT1' === $saved['platforms']['tiktok']['pixels'][0]['id'] && 'tttoken' === $saved['platforms']['tiktok']['pixels'][0]['token'] );
Pixels::save( $form );
m6_check( 'without the keep list a missing platform is emptied (why the keep list exists)', array() === Pixels::settings()['platforms']['tiktok']['pixels'] );

// ---------- 3. 浏览器配置 ----------
$config = Pixels::browser_config( Pixels::settings(), array() );
m6_check( 'browser config names the pixel-event endpoint', 'https://store.test/wp-json/peptide/v1/pixel-event' === $config['pixelEvent'] );
m6_check( 'ordinary visitors get no acceptance endpoint', ! isset( $config['check'] ) && ! isset( $config['checkCode'] ) );
m6_check( 'access tokens never reach the browser', ! str_contains( json_encode( $config ), 'fbtoken' ) && ! str_contains( json_encode( $config ), 'newtoken' ) );
$started                       = Acceptance::start( 30 );
$_COOKIE[ Acceptance::COOKIE ] = $started['code'];
$config                        = Pixels::browser_config( Pixels::settings(), array() );
m6_check( 'a browser carrying the acceptance code gets the report endpoint', 'https://store.test/wp-json/peptide/v1/pixel-check' === $config['check'] && $started['code'] === $config['checkCode'] );
unset( $_COOKIE[ Acceptance::COOKIE ] );
Acceptance::stop();

// ---------- 4. “发送测试事件”按钮：Meta 已改走隐身桥 ----------
// 2026-11-17（0a649df6）起 facebook 不在 Server::SERVER_PLATFORMS 里，这个按钮不再直连 Meta：
// 不发请求、不写发送记录，只告诉操作的人该去哪里测。开店向导第 4 步仍然读 ok / pixel / message。
$GLOBALS['t_requests'] = array();
$wpdb->log             = array();
$GLOBALS['m6_options'][ Pixels::OPTION ] = array( 'test_code' => 'TEST123', 'platforms' => array( 'facebook' => array( 'pixels' => array( array( 'id' => '111', 'note' => '', 'domain' => '', 'token' => 'fbtoken' ) ) ) ) );
$test = Server::test_event();
m6_check( 'test event: the button reports Meta server-side as disabled', false === $test['ok'] && 'meta_server_disabled' === $test['reason'] && '' === $test['pixel'] && array() === $test['results'] );
m6_check( 'test event: it points the operator at the bridge instead of failing silently', '' !== $test['message'] && str_contains( $test['message'], '桥' ) );
m6_check( 'test event: it still reports whether a test code is saved', true === $test['test_code'] );
m6_check( 'test event: no request is made and nothing is written to the send log', array() === $GLOBALS['t_requests'] && array() === $wpdb->log );
$GLOBALS['m6_options'][ Pixels::OPTION ]['platforms']['facebook']['pixels'] = array();
m6_check( 'test event: with no pixel saved it is still the disabled answer, not no_pixel', 'meta_server_disabled' === Server::test_event()['reason'] && array() === $to_fb() );

echo 'PASS ', $GLOBALS['m6_passed'], " pixels (FE-09 / ST-06) assertions\n";
