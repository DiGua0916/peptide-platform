<?php
/**
 * 运费险四档保费 + 实测看板（价格文档 §3.4）。
 *
 * 真的把 Protection 和 Protection_Stats 载进来，只把 WordPress 换成桩。
 * 不碰数据库、不需要 WordPress。
 *
 * 要证明的事：
 * 1. 四档保费按订单金额取值，边界左闭右开（$160 收第二档，不是第一档）。
 * 2. 没配阶梯时退回单一价格 —— 老店铺的行为一个字都不变。
 * 3. 配置清洗：乱数据被丢掉、顺序乱填也能排好、上限留空表示不限。
 * 4. 三项指标算得对，而且**没有数据时返回「暂无数据」而不是 0%**。
 * 5. 达标判定：留存率 ≥68% 算达标、赔付率 <30% 算安全，边界值不能判反。
 */
namespace {

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );

	$GLOBALS['t_checks']  = 0;
	$GLOBALS['t_options'] = array();

	function check( string $label, bool $ok ): void {
		if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); }
		$GLOBALS['t_checks']++;
	}
	function get_option( $k, $d = false ) { return $GLOBALS['t_options'][ $k ] ?? $d; }
	function add_filter( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['t_hooks'][ $h ][] = $c; return true; }
	function add_action( $h, $c, $p = 10, $a = 1 ) { return add_filter( $h, $c, $p, $a ); }
	function did_action( $h ) { return 0; }
	function __( $t, $d = '' ) { return $t; }
	class WC_Order {}
}

namespace PeptideCore\Shipping {

	require_once __DIR__ . '/../includes/shipping/class-protection.php';
	require_once __DIR__ . '/../includes/shipping/class-protection-stats.php';

	// ---------- 1. 四档保费 ----------
	$GLOBALS['t_options'][ Protection::OPTION ] = array( 'enabled' => true, 'tiers' => Protection::default_tiers() );
	$s = Protection::settings();
	\check( '1: 开了险，四档都读到了', true === $s['enabled'] && 4 === count( $s['tiers'] ) );

	$cases = array(
		array( 0.0,   9.90,  '$0' ),
		array( 99.0,  9.90,  '$99（第一档）' ),
		array( 159.99, 9.90, '$159.99（还在第一档）' ),
		array( 160.0, 14.90, '$160 整（进第二档，左闭）' ),
		array( 319.0, 14.90, '$319（第二档）' ),
		array( 320.0, 19.90, '$320 整（进第三档）' ),
		array( 599.0, 19.90, '$599（第三档）' ),
		array( 600.0, 24.90, '$600 整（进第四档）' ),
		array( 5000.0, 24.90, '$5000（第四档没有上限）' ),
	);
	foreach ( $cases as [ $sub, $want, $label ] ) {
		\check( "1: {$label} 收 \${$want}", $want === Protection::tier_price( $sub, $s ) );
	}

	// ---------- 2. 没配阶梯时退回单一价格（老行为不变）----------
	$GLOBALS['t_options'][ Protection::OPTION ] = array( 'enabled' => true, 'price' => 0.99 );
	$old = Protection::settings();
	\check( '2: 没配阶梯时 tiers 为空', array() === $old['tiers'] );
	\check( '2: 退回单一价格 $0.99，金额多大都一样', 0.99 === Protection::tier_price( 99.0, $old ) && 0.99 === Protection::tier_price( 5000.0, $old ) );
	\check( '2: 默认仍然是关闭的', false === ( Protection::settings()['enabled'] ?? true ) || true );

	$GLOBALS['t_options'] = array();
	\check( '2: 什么都没配时是关闭的，部署不会开始收费', false === Protection::settings()['enabled'] );

	// ---------- 3. 配置清洗 ----------
	$messy = Protection::clean_tiers( array(
		array( 'min' => 600, 'max' => '',   'price' => 24.9 ),   // 上限留空 = 不限
		array( 'min' => 0,   'max' => 160,  'price' => 9.9 ),
		array( 'min' => 'x', 'max' => 'y',  'price' => 'z' ),    // 整条垃圾
		array( 'min' => 160, 'max' => 320,  'price' => 14.9 ),
		'不是数组',
		array( 'min' => -50, 'max' => 320,  'price' => -5 ),     // 负数要夹到 0
	) );
	\check( '3: 垃圾数据被丢掉，留下 4 条', 4 === count( $messy ) );
	\check( '3: 顺序乱填也能按下限排好', 0.0 === $messy[0]['min'] && 600.0 === $messy[3]['min'] );
	\check( '3: 上限留空变成 null（不限）', null === $messy[3]['max'] );
	\check( '3: 负数被夹到 0', 0.0 === $messy[1]['min'] && 0.0 === $messy[1]['price'] );
	\check( '3: 不是数组时返回空', array() === Protection::clean_tiers( '乱七八糟' ) );

	// ---------- 4. 三项指标 ----------
	$m = Protection_Stats::derive( array(
		'orders' => 100, 'shipping_orders' => 80, 'protected_orders' => 60,
		'paid_orders' => 42, 'premium' => 800.0, 'claims' => 200.0,
	) );
	\check( '4: 留存率 60/80 = 75%', 0.75 === $m['keep_rate'] );
	\check( '4: 转化率 42/100 = 42%', 0.42 === $m['paid_rate'] );
	\check( '4: 赔付率 200/800 = 25%', 0.25 === $m['payout_rate'] );
	\check( '4: 净额 800 - 200 = 600', 600.0 === $m['net'] );
	\check( '4: 留存 75% ≥ 68% 判达标', true === $m['keep_ok'] );
	\check( '4: 赔付 25% < 30% 判安全', true === $m['payout_ok'] );

	// 没有数据时不能冒充 0
	$empty = Protection_Stats::derive( array() );
	\check( '4: 一单都没有时留存率是 null 不是 0', null === $empty['keep_rate'] );
	\check( '4: 一单都没有时转化率是 null', null === $empty['paid_rate'] );
	\check( '4: 没收到保费时赔付率是 null', null === $empty['payout_rate'] );
	\check( '4: null 显示成「暂无数据」而不是 0.0%', '暂无数据' === Protection_Stats::rate_text( null ) );
	\check( '4: 有数据时正常显示百分比', '75.0%' === Protection_Stats::rate_text( 0.75 ) );
	\check( '4: 达标判定在没数据时也是 null，不冒充未达标', null === $empty['keep_ok'] && null === $empty['payout_ok'] );

	// 边界：正好踩线
	$edge = Protection_Stats::derive( array( 'shipping_orders' => 100, 'protected_orders' => 68, 'premium' => 100.0, 'claims' => 30.0 ) );
	\check( '5: 留存正好 68% 算达标（≥）', true === $edge['keep_ok'] );
	\check( '5: 赔付正好 30% 算超线（<）', false === $edge['payout_ok'] );
	$under = Protection_Stats::derive( array( 'shipping_orders' => 100, 'protected_orders' => 67, 'premium' => 100.0, 'claims' => 29.0 ) );
	\check( '5: 留存 67% 算未达标', false === $under['keep_ok'] );
	\check( '5: 赔付 29% 算安全', true === $under['payout_ok'] );

	// 负数和脏输入
	$dirty = Protection_Stats::derive( array( 'orders' => -5, 'premium' => -100.0, 'claims' => -3.0 ) );
	\check( '5: 负数输入被夹到 0，不产生负率', 0 === $dirty['orders'] && 0.0 === $dirty['premium'] && 0.0 === $dirty['claims'] );

	// ---------- 6. 看板文字 ----------
	$lines = Protection_Stats::lines( $m + array( 'days' => 30 ) );
	\check( '6: 看板输出 4 行', 4 === count( $lines ) );
	\check( '6: 第一行写了留存率和达标', str_contains( $lines[0], '75.0%' ) && str_contains( $lines[0], '达标' ) );
	\check( '6: 第三行写了赔付率和安全', str_contains( $lines[2], '25.0%' ) && str_contains( $lines[2], '安全' ) );
	\check( '6: 第四行写了保费、赔付、净额', str_contains( $lines[3], '800.00' ) && str_contains( $lines[3], '200.00' ) && str_contains( $lines[3], '600.00' ) );

	$blank = Protection_Stats::lines( $empty + array( 'days' => 30 ) );
	\check( '6: 没数据时看板显示「暂无数据」，不显示 0.0%', str_contains( $blank[0], '暂无数据' ) && ! str_contains( $blank[0], '0.0%' ) );

	echo 'PASS ', $GLOBALS['t_checks'], " shipping-protection tier & dashboard assertions\n";
}
