<?php
/**
 * M4 营销弹窗静默开关（服务端部分）：过滤器 pc_marketing_popups_suppressed。
 *
 * 验证：默认不静默；静默时首单券条不输出、离开挽回 / 转盘不加载、活动倒计时的接口返回“不显示”；
 * 不静默时这三样照常；不管静默与否，价格优惠（首单券、满减、运费折扣）逐分不变。
 *
 * 运行：php -n tests/m4-marketing-quiet-pure.php   不连 WordPress、不连数据库。
 *
 * @package PeptideCore
 */

namespace PeptideCore {
	/** 员工判断的替身（真的类要查权限表）。 */
	final class Access {
		public static function is_staff( $user ): bool {
			return false;
		}
	}
}

namespace {
	require __DIR__ . '/fixtures/m4-harness.php';
	require __DIR__ . '/fixtures/m4-cases.php';

	use PeptideCore\Exit_Intent;
	use PeptideCore\First_Order_Offer;
	use PeptideCore\Pricing\Popups;

	define( 'PEPTIDE_CORE_DIR', dirname( __DIR__ ) );
	define( 'PEPTIDE_CORE_FILE', dirname( __DIR__ ) . '/peptide-core.php' );
	define( 'PEPTIDE_CORE_VERSION', 'test' );
	$GLOBALS['enqueued'] = array();
	function is_admin() { return false; }
	function current_user_can( $cap ) { return false; }
	function is_front_page() { return true; }
	function is_search() { return false; }
	function plugins_url( $path, $file = '' ) { return 'https://shop.example/' . $path; }
	function wp_enqueue_style( $handle, ...$a ) { $GLOBALS['enqueued'][] = 'style:' . $handle; }
	function wp_enqueue_script( $handle, ...$a ) { $GLOBALS['enqueued'][] = 'script:' . $handle; }
	function wp_localize_script( $handle, $name, $data ) { $GLOBALS['enqueued'][] = 'data:' . $name; }
	function wp_get_attachment_image_url( ...$a ) { return ''; }
	function wc_get_cart_url() { return 'https://shop.example/cart/'; }
	function wc_get_coupon_id_by_code( $code ) { return ( new WC_Coupon( $code ) )->get_id(); }
	function wp_unique_id( $prefix = '' ) { return $prefix . '1'; }
	final class WC_AJAX {
		public static function get_endpoint( $name ) { return '/?wc-ajax=' . $name; }
	}
	final class WP_REST_Request {
		public function __construct( private string $route ) {}
		public function get_route() { return $this->route; }
	}
	final class WP_REST_Response {
		public function __construct( private $data ) {}
		public function get_data() { return $this->data; }
		public function set_data( $data ) { $this->data = $data; }
	}

	$checks = 0;
	$failed = array();
	$check  = static function ( string $label, bool $ok ) use ( &$checks, &$failed ): void {
		++$checks;
		if ( ! $ok ) {
			$failed[] = $label;
			echo 'FAIL  ' . $label . "\n";
		}
	};
	/** 搭一家开了首单券条、离开挽回弹窗的店。 */
	$store = static function (): void {
		m4_reset();
		catalog();
		welcome5();
		m4_coupon( 950, 'COMEBACK', array( 'type' => 'percent', 'amount' => '10' ) );
		update_option( Exit_Intent::OPTION, array( 'enabled' => true, 'type' => 'coupon', 'coupon' => 'comeback' ) );
		$GLOBALS['enqueued'] = array();
		$_COOKIE             = array();
	};

	// ---------- 开关本身 ----------
	$store();
	$check( '默认不静默', false === Popups::suppressed() );
	$_COOKIE[ Popups::COOKIE ] = '1';
	$check( '浏览器带着 pc_marketing_quiet=1：静默', true === Popups::suppressed() );
	$_COOKIE[ Popups::COOKIE ] = 'yes';
	$check( 'cookie 是别的值：不静默', false === Popups::suppressed() );
	add_filter( Popups::FILTER, '__return_true_m4' );
	function __return_true_m4() { return true; } // phpcs:ignore
	$check( '过滤器返回真：静默', true === Popups::suppressed() );
	$store();
	$_COOKIE[ Popups::COOKIE ] = '1';
	add_filter( Popups::FILTER, static fn( $on ) => false );
	$check( '过滤器可以否决 cookie', false === Popups::suppressed() );

	// ---------- 页头的内联脚本 ----------
	$store();
	ob_start();
	Popups::print_script();
	$head = (string) ob_get_clean();
	$check( '不静默、也没有模块要用前台脚本：页头什么都不输出（前台和合入之前一样）', '' === $head );
	add_filter( Popups::SCRIPT, static fn() => true );
	ob_start();
	Popups::print_script();
	$head = (string) ob_get_clean();
	$check( '有模块声明要用前台脚本（pc_marketing_quiet_script）：不静默也输出，不带服务端标记、没有注释、不到 2KB', 0 === strpos( $head, '<script id="pc-marketing-quiet">' ) && false !== strpos( $head, 'pcMarketingQuiet' ) && false === strpos( $head, 'pcMarketingQuietServer=true' ) && false === strpos( $head, '/*' ) && strlen( $head ) < 2048 );
	$store();
	$_COOKIE[ Popups::COOKIE ] = '1';
	ob_start();
	Popups::print_script();
	$head = (string) ob_get_clean();
	$check( '浏览器带着静默的 cookie：输出脚本并带服务端标记', 0 === strpos( $head, '<script id="pc-marketing-quiet">window.pcMarketingQuietServer=true;' ) );
	$store();
	add_filter( Popups::FILTER, static fn() => true );
	ob_start();
	Popups::print_script();
	$head = (string) ob_get_clean();
	$check( '静默时内联脚本带上服务端标记', false !== strpos( $head, '>window.pcMarketingQuietServer=true;' ) );

	// ---------- 不静默：三样照常 ----------
	$store();
	$html = First_Order_Offer::render();
	$check( '不静默：首单券条照常输出', false !== strpos( $html, 'data-pc-first-order-offer' ) && false !== strpos( $html, 'WELCOME5' ) );
	Exit_Intent::enqueue();
	$check( '不静默：离开挽回弹窗照常加载', in_array( 'script:peptide-core-exit-intent', $GLOBALS['enqueued'], true ) && in_array( 'data:pcExitIntent', $GLOBALS['enqueued'], true ) );
	$dto      = array( 'version' => 1, 'checkout' => array( 'visible' => true, 'state' => 'active' ), 'product' => array( 'visible' => true, 'state' => 'active' ) );
	$response = Popups::quiet_countdown( new WP_REST_Response( $dto ), null, new WP_REST_Request( Popups::ROUTE ) );
	$check( '不静默：倒计时接口原样返回', $dto === $response->get_data() );

	// ---------- 静默：三样都不出现 ----------
	$store();
	add_filter( Popups::FILTER, static fn() => true );
	$check( '静默：首单券条不输出', '' === First_Order_Offer::render() );
	$check( '静默：后台预览仍然能看到券条', false !== strpos( First_Order_Offer::render( true ), 'data-pc-first-order-offer' ) );
	First_Order_Offer::assets();
	Exit_Intent::enqueue();
	$check( '静默：不加载首单券条和离开挽回 / 转盘的脚本', array() === $GLOBALS['enqueued'] );
	$response = Popups::quiet_countdown( new WP_REST_Response( $dto ), null, new WP_REST_Request( Popups::ROUTE ) );
	$data     = $response->get_data();
	$check( '静默：倒计时接口两处位置都返回不显示，其余内容不动', false === $data['checkout']['visible'] && false === $data['product']['visible'] && 'active' === $data['checkout']['state'] && 1 === $data['version'] );
	$other = Popups::quiet_countdown( new WP_REST_Response( $dto ), null, new WP_REST_Request( '/wc/store/v1/cart' ) );
	$check( '静默：别的接口不受影响', $dto === $other->get_data() );

	// 转盘也是同一个入口。
	$store();
	update_option( Exit_Intent::OPTION, array( 'enabled' => true, 'type' => 'wheel', 'prizes' => array( array( 'label' => 'A', 'coupon' => 'comeback', 'weight' => 1 ), array( 'label' => 'B', 'coupon' => '', 'weight' => 1 ) ) ) );
	Exit_Intent::enqueue();
	$shown = in_array( 'data:pcExitIntent', $GLOBALS['enqueued'], true );
	$GLOBALS['enqueued'] = array();
	add_filter( Popups::FILTER, static fn() => true );
	Exit_Intent::enqueue();
	$check( '转盘：不静默时加载，静默时不加载', $shown && array() === $GLOBALS['enqueued'] );

	// ---------- 价格优惠照常生效 ----------
	$priced = static function ( bool $quiet ): array {
		m4_reset();
		catalog();
		welcome5();
		if ( $quiet ) {
			add_filter( Popups::FILTER, static fn() => true );
		}
		m4_promotion( 500, 'Spend & Save', promo( 'spend_amount', array( array( 100, 10 ) ) ) );
		m4_coupon( 950, 'COMEBACK', array( 'type' => 'fixed_cart', 'amount' => '3' ) );
		$GLOBALS['m4']['rates'] = array( array( 'id' => 'plan:std', 'cost' => '15.00' ) );
		m4_shipping_discount( array( 'id' => 1, 'name' => 'Half', 'kind' => 'percent', 'value' => 50.0 ) );
		return m4_legacy( m4_cart( array( array( 106, 2 ), array( 101, 1 ) ), array( 'WELCOME5', 'COMEBACK' ) ) );
	};
	$normal = $priced( false );
	$quiet  = $priced( true );
	$check( '价格：静默与否，首单券、挽回券、满减、运费折扣逐分相同', $normal === $quiet && 12998 - 650 - 300 - 1000 + 750 === $quiet['total'] && array( 'welcome5' => 650, 'comeback' => 300 ) === $quiet['coupons'] && array( 'pc-promo-500' => -1000.0 ) === $quiet['fees'] && 750.0 === $quiet['shipping'] );

	printf( "RESULT %d 项检查：%s\n", $checks, $failed ? count( $failed ) . ' 项不通过' : '全部通过' );
	exit( $failed ? 1 : 0 );
}
