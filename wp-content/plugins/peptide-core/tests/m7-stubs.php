<?php
/**
 * M7 纯逻辑用例共用的桩（原来写在 m7-pixels-pure.php 开头，原样搬出来）：加载真正的 Pixels、Pixels\Server、Pixels\Acceptance，
 * 只把 WordPress、WooCommerce 和网络换成假的。自己不是用例。
 *
 * - 假网络 wp_remote_post()：每个请求记进 $GLOBALS['t_requests']，$GLOBALS['t_http'] 决定 Facebook 返回什么；
 * - 假 $wpdb（T_DB）：发送记录表放在内存里；
 * - 假订单、选项、钩子来自 m6-stubs.php。
 */
namespace PeptideCore {
	/** 员工角色（投手等）：$ids 里的用户编号算员工。 */
	final class Staff {
		public static array $ids = array();
		public static function is_staff( $user ): bool { return in_array( (int) ( is_object( $user ) ? $user->ID : $user ), self::$ids, true ); }
	}
	final class Access {
		public static bool $staff = false;
		public static function is_staff( $user_id ): bool { return self::$staff; }
	}
}

namespace PeptideCore {
	/** 付款成功弹窗页（?pc_paid=）：真的类按地址里的订单号和密钥取订单，这里直接给。 */
	final class Paid_Popup {
		public static function order_from_request(): ?\WC_Order { return $GLOBALS['t_paid_order'] ?? null; }
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
	// 页面判断：$GLOBALS['t_page'] 是当前页面的种类（'' / order-received）。
	function is_order_received_page() { return 'order-received' === ( $GLOBALS['t_page'] ?? '' ); }
	function is_search() { return false; }
	function is_front_page() { return false; }
	function wc_clean( $value ) { return is_string( $value ) ? trim( $value ) : $value; }
	function absint( $value ) { return abs( (int) $value ); }
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
