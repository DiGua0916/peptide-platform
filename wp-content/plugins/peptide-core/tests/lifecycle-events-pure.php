<?php
/**
 * 生命周期事件总线（价格文档第四章的地基）。
 *
 * 真的把 PeptideCore\Lifecycle_Events 载进来，只把 WordPress 换成桩。
 * 不碰数据库、不需要 WordPress、不发任何请求。
 *
 * 要证明的事：
 * 1. 默认关闭 —— 部署这份代码不会开始记录任何顾客邮箱。对高危品类这一条最要紧。
 * 2. 关着的时候连钩子都不挂，record() 一律拒绝。
 * 3. 订单状态 → 事件的映射正确，尤其「已付款之间互转」不能重复记 order_paid。
 * 4. 上下文：波多黎各识别（US/PR 和 PR 两种写法）、西语轨判定、金额取整、明细保留。
 * 5. 没有邮箱的事件不记 —— 召唤不回来的联系人不该占队列。
 */
namespace {

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );

	$GLOBALS['t_checks']  = 0;
	$GLOBALS['t_options'] = array();
	$GLOBALS['t_hooks']   = array();

	function check( string $label, bool $ok ): void {
		if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); }
		$GLOBALS['t_checks']++;
	}
	function get_option( $k, $d = false ) { return $GLOBALS['t_options'][ $k ] ?? $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
	function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['t_hooks'][ $h ][] = $c; return true; }
	function add_filter( $h, $c, $p = 10, $a = 1 ) { return add_action( $h, $c, $p, $a ); }
	function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
	function absint( $v ) { return abs( (int) $v ); }
	function __( $t, $d = '' ) { return $t; }
	class WC_Order {}
	class WC_Order_Item_Product {}
	class WC_Product {}
}

namespace PeptideCore {

	require_once __DIR__ . '/../includes/class-lifecycle-events.php';

	// ---------- 1. 默认关闭 ----------
	\check( '1: 默认关闭 —— 部署代码不会开始囤积顾客邮箱', false === Lifecycle_Events::enabled() );
	\check( '1: 关着的时候 record() 直接拒绝', false === Lifecycle_Events::record( 'order_paid', array( 'email' => 'a@b.test' ), 1 ) );

	Lifecycle_Events::register_hooks();
	\check( '2: 关着的时候一个钩子都不挂', array() === $GLOBALS['t_hooks'] );

	$GLOBALS['t_options'][ Lifecycle_Events::OPTION ] = array( 'enabled' => true );
	\check( '1: 显式打开后才生效', true === Lifecycle_Events::enabled() );
	Lifecycle_Events::register_hooks();
	foreach ( array( 'woocommerce_checkout_create_order', 'woocommerce_order_status_changed', 'pc_lifecycle_cleanup' ) as $h ) {
		\check( "2: 打开后挂上了 {$h}", ! empty( $GLOBALS['t_hooks'][ $h ] ) );
	}

	// ---------- 3. 状态 → 事件 ----------
	$map = array(
		array( 'pending',    'failed',     'payment_failed', '刷卡失败' ),
		array( 'pending',    'processing', 'order_paid',     '付款成功' ),
		array( 'on-hold',    'completed',  'order_paid',     '从挂起直接完成也算付款' ),
		array( 'processing', 'completed',  'order_shipped',  '已付款 → 完成 = 发货' ),
		array( 'pending',    'cancelled',  null,             '取消不记' ),
		array( 'pending',    'on-hold',    null,             '挂起不记' ),
	);
	foreach ( $map as [ $from, $to, $want, $label ] ) {
		\check( "3: {$label}（{$from} → {$to}）", $want === Lifecycle_Events::event_for_status( $from, $to ) );
	}
	\check( '3: 已付款之间互转不重复记 order_paid', 'order_shipped' === Lifecycle_Events::event_for_status( 'processing', 'completed' ) );

	// ---------- 4. 上下文 ----------
	$pr1 = Lifecycle_Events::context( array( 'email' => ' Buyer@Example.TEST ', 'country' => 'us', 'state' => 'pr', 'total' => 149.004, 'number' => 'hang-1', 'items' => array( array( 'sku' => 'TR30', 'qty' => '2' ) ) ) );
	\check( '4: 邮箱去空格转小写', 'buyer@example.test' === $pr1['email'] );
	\check( '4: US/PR 认出是波多黎各', true === $pr1['is_pr'] );
	\check( '4: 波多黎各自动进西语轨', true === $pr1['spanish'] );
	\check( '4: 金额四舍五入到分', 149.0 === $pr1['total'] );
	\check( '4: 商品明细保留，数量转成整数', 'TR30' === $pr1['items'][0]['sku'] && 2 === $pr1['items'][0]['qty'] );

	$pr2 = Lifecycle_Events::context( array( 'email' => 'a@b.test', 'country' => 'PR' ) );
	\check( '4: 老写法 country=PR 也认出是波多黎各', true === $pr2['is_pr'] );

	$es = Lifecycle_Events::context( array( 'email' => 'a@b.test', 'country' => 'US', 'state' => 'CA', 'lang' => 'es-MX' ) );
	\check( '4: 美国本土但浏览器是西语，也进西语轨', true === $es['spanish'] && false === $es['is_pr'] );

	$us = Lifecycle_Events::context( array( 'email' => 'a@b.test', 'country' => 'US', 'state' => 'CA', 'lang' => 'en-US' ) );
	\check( '4: 普通美国英语顾客不进西语轨', false === $us['spanish'] );
	\check( '4: 币种缺省是 USD', 'USD' === $us['currency'] );
	\check( '4: 负数金额被夹到 0', 0.0 === Lifecycle_Events::context( array( 'email' => 'a@b.test', 'total' => -50 ) )['total'] );

	// ---------- 5. 该不该记 ----------
	\check( '5: 认识的事件 + 有邮箱 = 记', true === Lifecycle_Events::should_record( 'order_paid', array( 'email' => 'a@b.test' ) ) );
	\check( '5: 没有邮箱不记（召唤不回来）', false === Lifecycle_Events::should_record( 'order_paid', array( 'email' => '' ) ) );
	\check( '5: 不认识的事件不记', false === Lifecycle_Events::should_record( '乱写的', array( 'email' => 'a@b.test' ) ) );
	foreach ( Lifecycle_Events::EVENTS as $e ) {
		\check( "5: 支持事件 {$e}", true === Lifecycle_Events::should_record( $e, array( 'email' => 'a@b.test' ) ) );
	}
	\check( '5: 一共 6 个事件，够 6 条流水线用', 6 === count( Lifecycle_Events::EVENTS ) );

	echo 'PASS ', $GLOBALS['t_checks'], " lifecycle event-bus assertions\n";
}
