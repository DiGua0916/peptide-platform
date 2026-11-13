<?php
/**
 * M11 已购回访（FE-10）的纯逻辑（不加载 WordPress）。
 * 2026-11-14 平台所有者定 Q20 方案 A 之后，M11 只做“认出已购顾客、静默营销弹窗”：
 * cookie 的签名和有效期、已登录账户名下的已付款订单、线上物流面板核对成功后记住、开关关着时什么都不做；
 * 原来的查订单页、短代码、两个接口和物流卡片已经下线。
 *
 * 运行：php -n wp-content/plugins/peptide-core/tests/m11-buyer-status-pure.php
 */
namespace PeptideCore {
	final class Test_Orders {
		public static array $test = array();
		public static function is_test_order( $order ): bool { return in_array( (int) $order->get_id(), self::$test, true ); }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'PEPTIDE_CORE_VERSION', '9.9.9' );
	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}

	class WC_Order {
		public function __construct( private int $id, private bool $paid, private string $key = 'wc_order_k', private string $type = 'shop_order' ) {}
		public function get_id() { return $this->id; }
		public function get_type() { return $this->type; }
		public function is_paid() { return $this->paid; }
		public function get_date_paid() { return $this->paid ? new DateTimeImmutable( '2026-11-12' ) : null; }
		public function get_order_key() { return $this->key; }
	}

	$GLOBALS['m11'] = array( 'options' => array(), 'filters' => array(), 'actions' => array(), 'shortcodes' => array(), 'routes' => 0, 'user' => 0, 'admin' => false, 'orders' => array(), 'queries' => array(), 'scripts' => array(), 'inline' => array() );
	function get_option( $name, $default = false ) { return $GLOBALS['m11']['options'][ $name ] ?? $default; }
	function apply_filters( $hook, $value ) { return $value; }
	function add_filter( $hook, $cb ) { $GLOBALS['m11']['filters'][] = $hook; return true; }
	function add_action( $hook, $cb ) { $GLOBALS['m11']['actions'][] = $hook; return true; }
	function add_shortcode( $tag, $cb ) { $GLOBALS['m11']['shortcodes'][ $tag ] = $cb; }
	function register_rest_route() { ++$GLOBALS['m11']['routes']; }
	function wp_salt( $scheme ) { return 'store-secret'; }
	function sanitize_text_field( $v ) { return trim( (string) $v ); }
	function wp_unslash( $v ) { return $v; }
	function is_ssl() { return true; }
	function is_admin() { return $GLOBALS['m11']['admin']; }
	function is_user_logged_in() { return $GLOBALS['m11']['user'] > 0; }
	function get_current_user_id() { return $GLOBALS['m11']['user']; }
	function wc_get_is_paid_statuses() { return array( 'processing', 'completed' ); }
	function wc_get_orders( array $args ) { $GLOBALS['m11']['queries'][] = $args; return $GLOBALS['m11']['orders'][ (int) $args['customer_id'] ] ?? array(); }
	function wp_register_script( $handle ) { $GLOBALS['m11']['scripts'][] = 'register ' . $handle; }
	function wp_enqueue_script( $handle ) { $GLOBALS['m11']['scripts'][] = 'enqueue ' . $handle; }
	function wp_enqueue_style( $handle ) { $GLOBALS['m11']['scripts'][] = 'style ' . $handle; }
	function wp_add_inline_script( $handle, $js ) { $GLOBALS['m11']['inline'][ $handle ] = $js; }

	$inc = dirname( __DIR__ ) . '/includes';
	require $inc . '/class-buyer-status.php';
	use PeptideCore\Buyer_Status;

	// 每个场景开始前清掉这次请求里缓存的结果，模拟一次新的请求。
	$fresh = static function ( array $state = array() ): void {
		foreach ( array( 'cookie', 'accounts' ) as $prop ) {
			$r = new ReflectionProperty( Buyer_Status::class, $prop );
			$r->setValue( null, 'cookie' === $prop ? null : array() );
		}
		$_COOKIE = array();
		$GLOBALS['m11'] = array_merge( $GLOBALS['m11'], array( 'options' => array( 'pc_buyer_recognition' => 'on' ), 'user' => 0, 'admin' => false, 'queries' => array(), 'scripts' => array(), 'inline' => array() ), $state );
	};

	// ---------- cookie（格式和线上旧版相同，旧 cookie 照样认） ----------
	$secret = 'store-secret';
	$issued = 1800000000;
	$encode = static fn( array $ids, int $at = 1800000000, string $key = 'store-secret' ): string => Buyer_Status::encode( $ids, $at, $key );
	$decode = static fn( string $value, int $now = 1800000100, string $key = 'store-secret' ): ?array => Buyer_Status::decode( $value, $now, $key );

	$cookie = $encode( array( 5, 9 ) );
	check( 'cookie format', 1 === preg_match( '/^v1\.5-9\.1800000000\.[a-f0-9]{64}$/', $cookie ) );
	check( 'round trip', array( 'orders' => array( 5, 9 ), 'issued' => $issued ) === $decode( $cookie ) );
	check( 'wrong secret', null === $decode( $cookie, 1800000100, 'other-store' ) );
	check( 'changed order number', null === $decode( str_replace( 'v1.5-9.', 'v1.5-10.', $cookie ) ) );
	check( 'changed issue time', null === $decode( str_replace( '.1800000000.', '.1800000001.', $cookie ) ) );
	check( 'changed signature', null === $decode( substr( $cookie, 0, -1 ) . ( 'a' === substr( $cookie, -1 ) ? 'b' : 'a' ) ) );
	check( 'valid on the last second of the year', null !== $decode( $cookie, $issued + Buyer_Status::LIFETIME ) );
	check( 'expired after one year', null === $decode( $cookie, $issued + Buyer_Status::LIFETIME + 1 ) );
	check( 'issued in the future', null === $decode( $cookie, $issued - 301 ) );
	check( 'small clock difference tolerated', null !== $decode( $cookie, $issued - 60 ) );
	foreach ( array( '', 'v1', 'v1.5.1800000000.abc', 'v2.5.1800000000.' . str_repeat( 'a', 64 ), 'v1.5-.1800000000.' . str_repeat( 'a', 64 ), "v1.5.1800000000." . str_repeat( 'a', 64 ) . "\n", 'v1.<script>.1.' . str_repeat( 'a', 64 ) ) as $junk ) {
		check( 'junk cookie rejected', null === $decode( $junk ) );
	}
	check( 'duplicates and bad ids dropped', 1 === preg_match( '/^v1\.3-7\./', $encode( array( 3, 3, 0, -1, 7 ) ) ) );
	check( 'nothing to remember', '' === $encode( array() ) && '' === $encode( array( 0 ) ) );
	check( 'keeps the latest ten orders', array( 6, 7, 8, 9, 10, 11, 12, 13, 14, 15 ) === $decode( $encode( range( 1, 15 ) ) )['orders'] );
	check( 'more than ten ids is not a valid cookie', null === $decode( 'v1.' . implode( '-', range( 1, 11 ) ) . '.1800000000.' . hash_hmac( 'sha256', 'pc-buyer-v1|' . implode( '-', range( 1, 11 ) ) . '|1800000000', $secret ) ) );

	check( 'new order goes last', array( 1, 3, 2 ) === Buyer_Status::merge_ids( array( 1, 2, 3 ), 2 ) );
	check( 'first order', array( 5 ) === Buyer_Status::merge_ids( array(), 5 ) );
	check( 'oldest order drops off', range( 2, 11 ) === Buyer_Status::merge_ids( range( 1, 10 ), 11 ) );

	// ---------- 已付款订单（账户这一条用） ----------
	\PeptideCore\Test_Orders::$test = array( 40 );
	check( 'a paid order counts', Buyer_Status::has_paid_order( array( new WC_Order( 41, true ) ) ) );
	check( 'unpaid orders do not count', ! Buyer_Status::has_paid_order( array( new WC_Order( 42, false ), new WC_Order( 43, false ) ) ) );
	check( 'test orders do not count', ! Buyer_Status::has_paid_order( array( new WC_Order( 40, true ) ) ) );
	check( 'any one real paid order is enough', Buyer_Status::has_paid_order( array( new WC_Order( 40, true ), new WC_Order( 42, false ), new WC_Order( 44, true ) ) ) );
	check( 'no orders', ! Buyer_Status::has_paid_order( array() ) );

	// ---------- 认人：开关关着时什么都不认（和线上一样） ----------
	$valid = $encode( array( 7 ), time() );
	$fresh( array( 'options' => array(), 'user' => 3, 'orders' => array( 3 => array( new WC_Order( 50, true ) ) ) ) );
	$_COOKIE['pc_buyer'] = $valid;
	check( 'switch off: a valid cookie is not recognised', ! Buyer_Status::known() );
	check( 'switch off: a logged-in buyer is not recognised, and no order query runs', ! Buyer_Status::known() && array() === $GLOBALS['m11']['queries'] );
	check( 'switch off: popups are not muted', false === Buyer_Status::mute( false ) );
	Buyer_Status::enqueue();
	check( 'switch off: nothing added to the page', array() === $GLOBALS['m11']['scripts'] && array() === $GLOBALS['m11']['inline'] );
	Buyer_Status::verified( new WC_Order( 60, true ) );
	check( 'switch off: the verified hook does not write a cookie', $valid === $_COOKIE['pc_buyer'] );

	// ---------- 认人：cookie ----------
	$fresh();
	$_COOKIE['pc_buyer'] = $valid;
	check( 'switch on: a valid cookie is recognised', Buyer_Status::known() );
	check( 'cookie visitors need no order query', array() === $GLOBALS['m11']['queries'] );
	$fresh();
	$_COOKIE['pc_buyer'] = substr( $valid, 0, -1 ) . ( 'a' === substr( $valid, -1 ) ? 'b' : 'a' );
	check( 'a forged cookie is not recognised', ! Buyer_Status::known() );
	$fresh();
	check( 'a new visitor is not recognised', ! Buyer_Status::known() );

	// ---------- 认人：已登录、账户名下有已付款订单 ----------
	$GLOBALS['m11']['orders'] = array( 3 => array( new WC_Order( 50, true ) ), 4 => array(), 5 => array( new WC_Order( 40, true ) ) );
	$fresh( array( 'user' => 3 ) );
	check( 'logged in with a paid order is recognised without any cookie', Buyer_Status::known() );
	$q = $GLOBALS['m11']['queries'][0] ?? array();
	check( 'the query looks only at this account\'s paid shop orders', 3 === $q['customer_id'] && 'shop_order' === $q['type'] && array( 'wc-processing', 'wc-completed' ) === $q['status'] && $q['limit'] <= 10 );
	Buyer_Status::known();
	Buyer_Status::mute( false );
	check( 'one query per request however often it is asked', 1 === count( $GLOBALS['m11']['queries'] ) );
	$fresh( array( 'user' => 4 ) );
	check( 'logged in without orders is not recognised', ! Buyer_Status::known() );
	$fresh( array( 'user' => 5 ) );
	check( 'logged in with only a test order is not recognised', ! Buyer_Status::known() );

	// ---------- 静默营销弹窗 ----------
	$fresh( array( 'user' => 3 ) );
	check( 'recognised buyers mute marketing popups', true === Buyer_Status::mute( false ) );
	check( 'muting asked for elsewhere is kept', true === Buyer_Status::mute( true ) );
	$GLOBALS['m11']['admin'] = true;
	check( 'never in the admin', false === Buyer_Status::mute( false ) );
	$fresh( array( 'user' => 3 ) );
	Buyer_Status::enqueue();
	check( 'recognised buyers get only the flag for the marketing scripts', array( 'register peptide-core-buyer-flag', 'enqueue peptide-core-buyer-flag' ) === $GLOBALS['m11']['scripts'] );
	check( 'the flag tells F2\'s switch to re-read', str_contains( $GLOBALS['m11']['inline']['peptide-core-buyer-flag'] ?? '', 'window.pcBuyer={known:true,suppressPopups:true}' ) && str_contains( $GLOBALS['m11']['inline']['peptide-core-buyer-flag'], 'pcMarketingQuiet.refresh()' ) );

	// ---------- 线上物流面板核对成功（pc_track_order_verified）→ 记住这个浏览器 ----------
	$fresh();
	Buyer_Status::verified( new WC_Order( 70, true ) );
	check( 'a verified paid order is remembered in the cookie', array( 70 ) === ( $decode( (string) ( $_COOKIE['pc_buyer'] ?? '' ), time() )['orders'] ?? null ) );
	check( 'and the visitor is recognised in the same request', Buyer_Status::known() );
	Buyer_Status::verified( new WC_Order( 71, true ) );
	check( 'a second order is added after the first', array( 70, 71 ) === ( $decode( (string) $_COOKIE['pc_buyer'], time() )['orders'] ?? null ) );
	$fresh();
	Buyer_Status::verified( new WC_Order( 72, false ) );
	Buyer_Status::verified( new WC_Order( 40, true ) );
	Buyer_Status::verified( new WC_Order( 73, true, 'k', 'shop_order_refund' ) );
	Buyer_Status::verified( null );
	Buyer_Status::verified( 'hang-1' );
	check( 'unpaid, test, refund records and junk are not remembered', ! isset( $_COOKIE['pc_buyer'] ) );

	// ---------- 下线的部分 ----------
	$fresh();
	$GLOBALS['m11']['filters'] = $GLOBALS['m11']['actions'] = $GLOBALS['m11']['shortcodes'] = array();
	$GLOBALS['m11']['routes'] = 0;
	Buyer_Status::register_hooks();
	check( 'no REST routes any more (order-lookup and buyer/status are retired)', 0 === $GLOBALS['m11']['routes'] && ! in_array( 'rest_api_init', $GLOBALS['m11']['actions'], true ) );
	check( 'listens for the tracking panel\'s verified action', in_array( 'pc_track_order_verified', $GLOBALS['m11']['actions'], true ) );
	check( 'still writes the cookie on the paid landing and mutes popups', in_array( 'template_redirect', $GLOBALS['m11']['actions'], true ) && in_array( 'pc_marketing_popups_suppressed', $GLOBALS['m11']['filters'], true ) );
	check( 'the old shortcode prints nothing', '__return_empty_string' === ( $GLOBALS['m11']['shortcodes']['pc_track_order'] ?? null ) );
	$src = (string) file_get_contents( $inc . '/class-buyer-status.php' );
	check( 'no order card, look-up page or status endpoint left in the class', false === strpos( $src, 'register_rest_route' ) && false === strpos( $src, 'wp_insert_post' ) && false === strpos( $src, 'buyer-status.js' ) && false === strpos( $src, 'function card(' ) );
	check( 'the retired files are gone', ! file_exists( $inc . '/class-order-lookup.php' ) && ! file_exists( dirname( __DIR__ ) . '/assets/buyer-status.js' ) && ! file_exists( dirname( __DIR__ ) . '/assets/buyer-status.css' ) );
	check( 'capabilities tell the master admin which version this is', array( 'm11.buyer_status' => '1', 'm11.plan_a' => '1' ) === Buyer_Status::capabilities( array() ) );

	echo "PASS {$checks} returning-buyer checks\n";
}
