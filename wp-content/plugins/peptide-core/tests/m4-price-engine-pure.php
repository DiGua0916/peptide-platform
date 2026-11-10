<?php
/**
 * M4 价格引擎对照测试（NF-02）：同一份购物车，旧逻辑和新引擎的每一项金额必须逐分相同。
 *
 * 旧逻辑 = 现在线上挂在 WooCommerce 钩子上的那几个类，真实运行；新引擎 = Cart_Reader + Engine。
 * 比较：应付总额、商品小计、优惠码合计、每张优惠码的金额、每个满减活动的金额、运费、每行单价。
 * 部分样例另外写了手算的应付总额（expect），防止两边错成一样。
 *
 * 运行：php -n tests/m4-price-engine-pure.php            （52 个样例 + 3000 份随机购物车）
 *       php -n tests/m4-price-engine-pure.php --fuzz=20000 （加大随机量）
 * 不连 WordPress、不连数据库、不写任何文件。
 *
 * @package PeptideCore
 */

require __DIR__ . '/fixtures/m4-harness.php';

require __DIR__ . '/fixtures/m4-cases.php';

$cases = m4_cases();

// ---------- 运行 ----------
$fields = array( 'total' => '应付总额', 'subtotal' => '商品小计', 'discount' => '优惠码合计', 'coupons' => '各优惠码', 'fees' => '各满减活动', 'shipping' => '运费', 'units' => '各行单价' );
$failed = 0;
$sources = array();
foreach ( $cases as $name => $case ) {
	m4_reset();
	catalog();
	$cart   = $case['cart']();
	$legacy = m4_legacy( $cart );
	$engine = m4_engine( $cart );
	$diff   = array();
	foreach ( $fields as $key => $label ) {
		if ( $legacy[ $key ] != $engine[ $key ] ) { // phpcs:ignore Universal.Operators.StrictComparisons -- 数字相等即可（2998 和 2998.0）。
			$diff[] = $label . '：旧 ' . json_encode( $legacy[ $key ] ) . ' / 新 ' . json_encode( $engine[ $key ] );
		}
	}
	if ( isset( $case['expect'] ) && $legacy['total'] !== $case['expect'] ) {
		$diff[] = '手算的应付总额是 ' . $case['expect'] . '，旧逻辑算出 ' . $legacy['total'];
	}
	if ( ! $engine['result']['supported'] ) {
		$diff[] = '引擎报告不支持：' . implode( ',', $engine['result']['unsupported'] );
	}
	foreach ( $engine['result']['entries'] as $entry ) {
		$sources[ $entry['source'] ] = true;
	}
	$failed += $diff ? 1 : 0;
	printf( "%s  %s  总额 %s\n", $diff ? 'FAIL' : 'ok  ', $name, number_format( $legacy['total'] / 100, 2 ) );
	foreach ( $diff as $line ) {
		echo '        ' . $line . "\n";
	}
}

// 七处优惠的明细都要出现过。
$need = array( 'promotion', 'coupon', 'first_order', 'loyalty', 'recommendation', 'exit_intent', 'gift', 'bundle', 'shipping_discount' );
$miss = array_diff( $need, array_keys( $sources ) );
if ( $miss ) {
	++$failed;
	echo 'FAIL  优惠明细里没有出现过的来源：' . implode( ', ', $miss ) . "\n";
}

require __DIR__ . '/fixtures/m4-fuzz.php';
$rounds = 3000;
foreach ( $argv as $arg ) {
	if ( preg_match( '/^--fuzz=(\d+)$/', $arg, $m ) ) {
		$rounds = (int) $m[1];
	}
}
$bad = m4_fuzz( $rounds, array_keys( $fields ) );
$failed += $bad ? 1 : 0;

printf( "RESULT %d 个样例 + %d 份随机购物车：%s\n", count( $cases ), $rounds, $failed ? $failed . ' 处不一致' : '全部逐分一致' );
exit( $failed ? 1 : 0 );
