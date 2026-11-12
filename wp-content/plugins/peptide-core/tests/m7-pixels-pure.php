<?php
/**
 * FE-09 / ST-06：真正的 Pixels、Pixels\Server、Pixels\Acceptance，只把 WordPress、WooCommerce 和网络换成桩。
 * 纯逻辑：php -n m7-pixels-pure.php
 *
 * 要证明的事：
 * 1. 结账页“选支付”：服务端生成的 add_payment_info 事件，发给 Conversions API 的 event_id 和交给浏览器的是同一个；
 *    一分钟内不重复回传；购物车空、顾客退出回传时不发。
 * 2. 像素页对投手只提交 Facebook 时，其他平台已保存的像素和令牌不会被清空。
 * 3. 浏览器配置：普通访客拿不到验收上报地址，带验收码的浏览器才有。
 * 4. “发送测试事件”的返回格式（开店向导第 4 步读 ok / pixel / message）。
 */
namespace PeptideCore {
	final class Access {
		public static bool $staff = false;
		public static function is_staff( $user_id ): bool { return self::$staff; }
	}
}

namespace {
	require __DIR__ . '/m6-stubs.php';

	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'YEAR_IN_SECONDS', 31536000 );
	define( 'COOKIEPATH', '/' );
	$_SERVER['HTTP_HOST']       = 'store.test';
	$_SERVER['REMOTE_ADDR']     = '203.0.113.9';
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone) FBAN/FBIOS';
	$_SERVER['REQUEST_URI']     = '/wp-json/peptide/v1/pixel-event';
	$_SERVER['HTTP_REFERER']    = 'https://store.test/checkout/';
	$_COOKIE['_fbp']            = 'fb.1.1700000000000.123456';
	$_COOKIE['_fbc']            = 'fb.1.1700000000000.AbCdEf';

	function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
	function wp_unslash( $value ) { return $value; }
	function wp_generate_uuid4() { return sprintf( '%08x-0000-4000-8000-%012x', ++$GLOBALS['t_uuid'], $GLOBALS['t_uuid'] ); }
	function wp_generate_password( $length = 12, $special = true ) { return substr( 'AbCdEfGh1234XyZ9', 0, (int) $length ); }
	function home_url( $path = '' ) { return 'https://store.test' . $path; }
	function rest_url( $path = '' ) { return 'https://store.test/wp-json/' . ltrim( (string) $path, '/' ); }
	function add_query_arg( $key, $value, $url ) { return $url . '?' . $key . '=' . $value; }
	function esc_url_raw( $url ) { return (string) $url; }
	function is_email( $email ) { return false !== strpos( (string) $email, '@' ); }
	function is_admin() { return false; }
	function doing_action( $name = null ) { return false; }
	function is_ssl() { return true; }
	function is_user_logged_in() { return false; }
	function wp_create_nonce( $action ) { return 'nonce'; }
	function current_user_can( $cap ) { return false; }
	function get_current_user_id() { return 0; }
	function wp_get_current_user() { return (object) array( 'ID' => 0, 'user_email' => '' ); }
	function wc_get_price_excluding_tax( $product ) { return 19.99; }
	function wc_get_product_cat_ids( $id ) { return array(); }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	function wp_strip_all_tags( $text ) { return trim( strip_tags( (string) $text ) ); }
	function wp_rand( $min, $max ) { return 2; }
	function is_wp_error( $thing ) { return false; }
	function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
	function wp_remote_retrieve_body( $response ) { return $response['body']; }
	/** 假网络：记下每个请求；$GLOBALS['t_http'] 决定 Facebook 返回什么。 */
	function wp_remote_post( $url, $args ) {
		$GLOBALS['t_requests'][] = array( 'url' => (string) $url, 'body' => json_decode( (string) $args['body'], true ) );
		if ( str_contains( (string) $url, 'graph.facebook.com' ) ) { return $GLOBALS['t_http']; }
		return array( 'code' => 200, 'body' => '{"code":0}' );
	}

	final class WC_AJAX { public static function get_endpoint( $name ) { return '/?wc-ajax=' . $name; } }
	class WP_REST_Request implements ArrayAccess {
		public function __construct( private array $params = array() ) {}
		public function offsetExists( mixed $key ): bool { return isset( $this->params[ $key ] ); }
		public function offsetGet( mixed $key ): mixed { return $this->params[ $key ] ?? null; }
		public function offsetSet( mixed $key, mixed $value ): void { $this->params[ $key ] = $value; }
		public function offsetUnset( mixed $key ): void { unset( $this->params[ $key ] ); }
	}
	class WP_REST_Response {
		public array $headers = array();
		public function __construct( public $data = null ) {}
		public function header( $name, $value ) { $this->headers[ $name ] = $value; }
	}
	class WC_Product {
		public function __construct( private int $id, private string $sku = 'SKU-1' ) {}
		public function get_id() { return $this->id; }
		public function get_parent_id() { return 0; }
		public function get_meta( $key ) { return ''; }
		public function get_sku() { return $this->sku; }
		public function get_name() { return 'BPC-157 10mg'; }
		public function get_slug() { return 'bpc-157'; }
	}
	/** 假购物车和购物会话。set() 被调用说明有人往会话里写了东西（这个接口不应该写）。 */
	final class T_Cart {
		public array $lines = array();
		public function is_empty() { return ! $this->lines; }
		public function get_cart() { return $this->lines; }
		public function get_total( $context = 'view' ) { return '54.98'; }
	}
	final class T_Session {
		public int $writes = 0;
		public function get_customer_id() { return 't_customer_1'; }
		public function get( $key, $default = null ) { return $default; }
		public function set( $key, $value ) { ++$this->writes; }
	}
	final class T_WC { public $cart = null; public $session = null; public $customer = null; public $countries = null; }
	function WC() { return $GLOBALS['t_wc']; }
	function wc_load_cart() { ++$GLOBALS['t_cart_loads']; $GLOBALS['t_wc']->cart = $GLOBALS['t_cart']; $GLOBALS['t_wc']->session = $GLOBALS['t_session']; }

	/** 假 $wpdb：发送记录表放在内存里。 */
	final class T_DB {
		public string $prefix = 'test_';
		public array $log     = array();
		public function insert( $table, array $row ) { $this->log[] = $row; return 1; }
		public function prepare( string $sql, ...$args ): string { foreach ( $args as $arg ) { $sql = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 ); } return $sql; }
		// 11-11 起 Server::installed() 每次请求用 SHOW TABLES 确认一次发送记录表在不在：这里表一直在。
		public function get_var( string $sql ) { return str_contains( $sql, 'SHOW TABLES LIKE' ) ? $this->prefix . 'pc_px_log' : 0; }
	}

	require dirname( __DIR__ ) . '/includes/class-pixels.php';
	require dirname( __DIR__ ) . '/includes/pixels/class-server.php';
	require dirname( __DIR__ ) . '/includes/pixels/class-acceptance.php';
}

namespace {
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

	// ---------- 1. 结账页“选支付” ----------
	$response = $post();
	$events   = $response->data['events'];
	m6_check( 'the server hands the browser exactly one add_payment_info event', 1 === count( $events ) && 'add_payment_info' === $events[0]['name'] );
	m6_check( 'the event carries the cart: total, currency, items', 54.98 === $events[0]['value'] && 'USD' === $events[0]['currency'] && 2 === $events[0]['items'][0]['quantity'] && '501' === $events[0]['items'][0]['id'] );
	m6_check( 'the event points at the checkout page the request came from', 'https://store.test/checkout/' === $events[0]['url'] );
	$fb = $to_fb();
	m6_check( 'one Conversions API request for the Facebook pixel with its token', 1 === count( $fb ) && str_contains( $fb[0]['url'], '/111/events?access_token=fbtoken' ) );
	m6_check( 'server event is AddPaymentInfo', 'AddPaymentInfo' === $fb[0]['body']['data'][0]['event_name'] && 'website' === $fb[0]['body']['data'][0]['action_source'] );
	m6_check( 'FE-09: the server event_id is the id given to the browser', $events[0]['id'] === $fb[0]['body']['data'][0]['event_id'] && str_starts_with( $events[0]['id'], 'add_' ) );
	m6_check( 'server event keeps fbp, fbc, IP and user agent', 'fb.1.1700000000000.123456' === $fb[0]['body']['data'][0]['user_data']['fbp'] && 'fb.1.1700000000000.AbCdEf' === $fb[0]['body']['data'][0]['user_data']['fbc'] && '203.0.113.9' === $fb[0]['body']['data'][0]['user_data']['client_ip_address'] && str_contains( $fb[0]['body']['data'][0]['user_data']['client_user_agent'], 'FBIOS' ) );
	m6_check( 'the test event code travels with the server event', 'TEST123' === $fb[0]['body']['test_event_code'] );
	m6_check( 'the send is written to the send log with the same id', 'AddPaymentInfo' === $wpdb->log[0]['event'] && $events[0]['id'] === $wpdb->log[0]['event_id'] && 1 === $wpdb->log[0]['ok'] && 'facebook' === $wpdb->log[0]['platform'] );
	m6_check( 'the cart is loaded for the REST request and the shopping session is never written', 1 === $GLOBALS['t_cart_loads'] && 0 === $GLOBALS['t_session']->writes );
	m6_check( 'responses are never cached', 'private, no-store, max-age=0' === $response->headers['Cache-Control'] );

	$again = $post();
	m6_check( 'a second request within a minute sends nothing and returns no event', array() === $again->data['events'] && 1 === count( $to_fb() ) );
	$GLOBALS['m6_transients'] = array();
	$third                    = $post();
	m6_check( 'after the minute a new event has a new id', 1 === count( $third->data['events'] ) && $third->data['events'][0]['id'] !== $events[0]['id'] && 2 === count( $to_fb() ) );

	$GLOBALS['m6_transients']  = array();
	$GLOBALS['t_cart']->lines = array();
	m6_check( 'an empty cart sends nothing', array() === $post()->data['events'] && 2 === count( $to_fb() ) );
	$GLOBALS['t_cart']->lines = array( array( 'data' => new WC_Product( 501 ), 'quantity' => 1, 'line_subtotal' => 19.99 ) );
	$_COOKIE[ Pixels::OPTOUT ] = '1';
	m6_check( 'a browser that opted out gets no event and nothing is sent', array() === $post()->data['events'] && 2 === count( $to_fb() ) );
	unset( $_COOKIE[ Pixels::OPTOUT ] );
	$_SERVER['HTTP_SEC_GPC'] = '1';
	m6_check( 'a GPC browser gets no event and nothing is sent', array() === $post()->data['events'] && 2 === count( $to_fb() ) );
	unset( $_SERVER['HTTP_SEC_GPC'] );
	\PeptideCore\Access::$staff = true;
	m6_check( 'staff get no event and nothing is sent', array() === $post()->data['events'] && 2 === count( $to_fb() ) );
	\PeptideCore\Access::$staff = false;
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

	// ---------- 4. 发送测试事件 ----------
	$GLOBALS['t_requests'] = array();
	$wpdb->log             = array();
	$GLOBALS['m6_options'][ Pixels::OPTION ] = array( 'test_code' => 'TEST123', 'platforms' => array( 'facebook' => array( 'pixels' => array( array( 'id' => '111', 'note' => '', 'domain' => '', 'token' => 'fbtoken' ) ) ) ) );
	$test = Server::test_event();
	m6_check( 'test event: connected', true === $test['ok'] && '111' === $test['pixel'] && '' === $test['message'] && '' === $test['reason'] && true === $test['test_code'] );
	m6_check( 'test event: one PageView sent synchronously with the test code and logged', 1 === count( $to_fb() ) && 'PageView' === $to_fb()[0]['body']['data'][0]['event_name'] && 'TEST123' === $to_fb()[0]['body']['test_event_code'] && 'PageView' === $wpdb->log[0]['event'] && 1 === $wpdb->log[0]['ok'] );
	$GLOBALS['t_http'] = array( 'code' => 400, 'body' => '{"error":{"message":"Invalid OAuth access token"}}' );
	$test              = Server::test_event();
	m6_check( 'test event: rejected by Facebook', false === $test['ok'] && '111' === $test['pixel'] && str_contains( $test['message'], 'HTTP 400' ) && str_contains( $test['message'], 'Invalid OAuth access token' ) && 400 === $test['results'][0]['code'] );
	$GLOBALS['m6_options'][ Pixels::OPTION ]['platforms']['facebook']['pixels'][0]['token'] = '';
	$test = Server::test_event();
	m6_check( 'test event: pixel without a token', false === $test['ok'] && 'no_token' === $test['results'][0]['message'] && str_contains( $test['message'], 'CAPI' ) && 2 === count( $to_fb() ) );
	$GLOBALS['m6_options'][ Pixels::OPTION ]['platforms']['facebook']['pixels'] = array();
	$test = Server::test_event();
	m6_check( 'test event: no pixel saved', false === $test['ok'] && 'no_pixel' === $test['reason'] && '' === $test['pixel'] && '' !== $test['message'] && array() === $test['results'] );

	echo 'PASS ', $GLOBALS['m6_passed'], " pixels (FE-09 / ST-06) assertions\n";
}
