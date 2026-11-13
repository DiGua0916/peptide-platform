<?php
/**
 * M4 价格引擎的三档开关（NF-02，开发文档 12.3）：关 / 影子 / 开。
 *
 * 验证：
 * - 默认是关；关着时什么都不挂，顾客看到的数字和没有引擎时一样；
 * - 影子：顾客看到的数字和关着时逐分相同；请求末尾的核对对全部样例和随机购物车都是“一致”；
 * - 开：满减、赠品 / 捆绑行价、运费折扣改由引擎给出，顾客看到的数字仍和关着时逐分相同，核对“一致”；
 * - 开着时引擎出错：自动退回旧逻辑，数字不变，记一条出错；
 * - 不一致会被记下来（统计 + 明细），明细里没有顾客邮箱；引擎算不了、购物车算完又被改过时记“跳过”，不算不一致。
 *
 * 运行：php -n tests/m4-engine-mode-pure.php   不连 WordPress、不连数据库。
 *
 * @package PeptideCore
 */

require __DIR__ . '/fixtures/m4-harness.php';
require __DIR__ . '/fixtures/m4-cases.php';
require __DIR__ . '/fixtures/m4-fuzz.php';

use PeptideCore\Pricing\Mode;
use PeptideCore\Pricing\Runtime;
use PeptideCore\Pricing\Shadow;

$checks = 0;
$failed = array();
$check  = static function ( string $label, bool $ok ) use ( &$checks, &$failed ): void {
	++$checks;
	if ( ! $ok ) {
		$failed[] = $label;
		echo 'FAIL  ' . $label . "\n";
	}
};
$numbers = static function ( WC_Cart $cart ): array {
	$r = m4_legacy( $cart );
	return $r;
};

// ---------- 档位 ----------
m4_reset();
$check( '默认是关', Mode::OFF === Mode::get() && ! Runtime::on() );
$check( '不认识的档位被拒绝，仍然是关', is_wp_error( Mode::set( 'maybe' ) ) && Mode::OFF === Mode::get() );
$check( '可以改成影子', true === Mode::set( 'shadow' ) && Mode::SHADOW === Mode::get() && ! Runtime::on() );
$check( '可以改成开', true === Mode::set( 'on' ) && Runtime::on() );
$check( '可以改回关', true === Mode::set( 'off' ) && Mode::OFF === Mode::get() );
update_option( Mode::OPTION, 'garbage' );
$check( '选项里是乱写的值时按关处理', Mode::OFF === Mode::get() );

m4_reset();
Runtime::register_hooks();
$check( '关：不挂请求末尾的核对', ! has_action( 'shutdown' ) );
m4_reset();
Mode::set( 'shadow' );
Runtime::register_hooks();
$check( '影子：挂上请求末尾的核对', has_action( 'shutdown' ) );
$caps = apply_filters( 'pc_hq_capabilities', array( 'hq.capabilities' => '1' ) );
$check( '向总后台声明支持的功能（功能键 => 版本号，不覆盖别人的）', '1' === ( $caps['pricing.engine'] ?? '' ) && '1' === ( $caps['hq.capabilities'] ?? '' ) );
$check( '静默开关、只读规则都没打开：不声明 marketing.quiet、campaigns.readonly', ! isset( $caps['marketing.quiet'] ) && ! isset( $caps['campaigns.readonly'] ) );
update_option( \PeptideCore\Pricing\Popups::MODE_OPTION, 'on' );
$caps = apply_filters( 'pc_hq_capabilities', array( 'hq.capabilities' => '1' ) );
$check( '打开静默开关以后才声明 marketing.quiet', '1' === ( $caps['marketing.quiet'] ?? '' ) );
$check( '按系列接收营销活动默认关：不向总后台声明 series.campaigns', ! isset( $caps['series.campaigns'] ) && 'off' === Runtime::status()['series_campaigns'] );
update_option( \PeptideCore\Pricing\Series_Campaigns::MODE_OPTION, 'on' );
$caps = apply_filters( 'pc_hq_capabilities', array( 'hq.capabilities' => '1' ) );
$check( '打开以后才声明 series.campaigns（只读规则随之生效，也声明 campaigns.readonly）', '1' === ( $caps['series.campaigns'] ?? '' ) && '1' === ( $caps['campaigns.readonly'] ?? '' ) && 'on' === Runtime::status()['series_campaigns'] );

m4_reset();
$brief = Runtime::status_brief();
$check( '给总后台状态用的简报：关着时只报档位，不查表', 'off' === $brief['mode'] && 0 === $brief['today']['compared'] && array() === $GLOBALS['wpdb']->writes );

// ---------- 样例：关 / 影子 / 开 三档的顾客看到的数字 ----------
$cases = m4_cases();
$run   = static function ( callable $build, string $mode ) use ( $numbers ): array {
	m4_reset();
	Mode::set( $mode );
	catalog();
	$cart  = $build();
	$seen  = $numbers( $cart );
	$check = Mode::OFF === $mode ? null : Shadow::check( $cart );
	return array( $seen, $check, Runtime::on() );
};
$bad = array( 'shadow' => 0, 'on' => 0, 'check' => 0, 'takeover' => 0 );
foreach ( $cases as $name => $case ) {
	[ $off ]                      = $run( $case['cart'], 'off' );
	[ $shadow, $shadow_check ]    = $run( $case['cart'], 'shadow' );
	[ $on, $on_check, $still_on ] = $run( $case['cart'], 'on' );
	$bad['shadow']               += $off == $shadow ? 0 : 1; // phpcs:ignore Universal.Operators.StrictComparisons
	$bad['on']                   += $off == $on ? 0 : 1; // phpcs:ignore Universal.Operators.StrictComparisons
	$bad['check']                += 'matched' === $shadow_check['kind'] && 'matched' === $on_check['kind'] ? 0 : 1;
	$bad['takeover']             += $still_on ? 0 : 1;
	if ( $off != $on || 'matched' !== $on_check['kind'] ) { // phpcs:ignore Universal.Operators.StrictComparisons
		echo '      ' . $name . ' ' . json_encode( $on_check['diff'] ?: $on_check['reason'], JSON_UNESCAPED_UNICODE ) . "\n";
	}
}
$check( count( $cases ) . ' 个样例：影子档顾客看到的数字和关着时逐分相同', 0 === $bad['shadow'] );
$check( count( $cases ) . ' 个样例：开档顾客看到的数字和关着时逐分相同', 0 === $bad['on'] );
$check( count( $cases ) . ' 个样例：影子、开两档的核对结果都是“一致”', 0 === $bad['check'] );
$check( count( $cases ) . ' 个样例：开档全程由引擎接管，没有退回旧逻辑', 0 === $bad['takeover'] );

// ---------- 随机购物车：开档 ----------
$rounds = 1500;
$bad    = 0;
for ( $seed = 1; $seed <= $rounds; $seed++ ) {
	$off = $numbers( m4_fuzz_case( $seed ) );
	$cart = m4_fuzz_case( $seed );
	Mode::set( 'on' );
	$on    = $numbers( $cart );
	$after = Shadow::check( $cart );
	if ( $off != $on || 'matched' !== $after['kind'] || ! Runtime::on() ) { // phpcs:ignore Universal.Operators.StrictComparisons
		++$bad;
		if ( $bad <= 5 ) {
			echo '      随机购物车 #' . $seed . ' ' . json_encode( $after['diff'] ?: $after['reason'], JSON_UNESCAPED_UNICODE ) . "\n";
		}
	}
}
$check( $rounds . ' 份随机购物车：开档和关档逐分相同，核对一致', 0 === $bad );

// ---------- 开着时引擎出错：退回旧逻辑 ----------
[ $off ] = $run( $cases['12 活动优先级：指定商品 > 专辑 > 全部商品，三个活动各管各的']['cart'], 'off' );
m4_reset();
Mode::set( 'on' );
catalog();
m4_user( 5, 'five@example.com' );
$GLOBALS['m4']['throw'] = 'user_meta';
$cart = $cases['12 活动优先级：指定商品 > 专辑 > 全部商品，三个活动各管各的']['cart']();
$seen = $numbers( $cart );
$GLOBALS['m4']['throw'] = '';
$check( '开档引擎出错：顾客看到的数字和旧逻辑一样', $seen == $off ); // phpcs:ignore Universal.Operators.StrictComparisons
$check( '开档引擎出错：这一次请求之内不再接管', ! Runtime::on() && Mode::ON === Mode::get() );
$logged = array_values( array_filter( $GLOBALS['wpdb']->writes, static fn( $w ) => 'insert' === $w[0] ) );
$check( '开档引擎出错：记了一条出错明细', 1 === count( $logged ) && 'errors' === $logged[0][2]['kind'] && false !== strpos( $logged[0][2]['detail'], '"where":"offers"' ) );
$check( '第一次写记录时建了两张表', 2 === count( $GLOBALS['m4']['dbdelta'] ) && Shadow::DB_VERSION === get_option( Shadow::DB_OPTION ) );

// ---------- 不一致会被记下来 ----------
m4_reset();
Mode::set( 'shadow' );
catalog();
$GLOBALS['m4']['billing'] = 'private.person@example.com';
welcome5();
m4_promotion( 500, 'Spend & Save', promo( 'spend_amount', array( array( 100, 10 ) ) ) );
$cart = m4_cart( array( array( 106, 2 ) ), array( 'WELCOME5' ) );
$numbers( $cart );
$cart->set_total( $cart->get_total( 'edit' ) + 0.01 ); // 模拟线上多收了一分钱
$result = Shadow::check( $cart );
$check( '总额差一分：判为不一致，并指出是应付总额', 'mismatched' === $result['kind'] && array( 'total' ) === array_keys( $result['diff'] ) && 8501 === $result['diff']['total']['legacy'] && 8500 === $result['diff']['total']['engine'] );
Shadow::record( 'shadow', $result['kind'], $result );
$writes = $GLOBALS['wpdb']->writes;
$stat   = array_values( array_filter( $writes, static fn( $w ) => 'query' === $w[0] && false !== strpos( $w[1][0], 'pc_m4_shadow_stats' ) ) );
$rows   = array_values( array_filter( $writes, static fn( $w ) => 'insert' === $w[0] ) );
$check( '不一致：统计加一', 1 === count( $stat ) && false !== strpos( $stat[0][1][0], 'mismatched = mismatched + 1' ) && 'shadow' === $stat[0][1][1][1] );
$check( '不一致：写了一条明细，带新旧总额', 1 === count( $rows ) && 'mismatched' === $rows[0][2]['kind'] && 8501 === $rows[0][2]['legacy_cents'] && 8500 === $rows[0][2]['engine_cents'] );
$detail = $rows ? (string) $rows[0][2]['detail'] : '';
$check( '明细里有优惠明细，没有顾客邮箱', false !== strpos( $detail, '"first_order"' ) && false !== strpos( $detail, '"promotion"' ) && false === stripos( $detail, 'private.person' ) && false === strpos( $detail, '@' ) );

// 一致的只计数，不写明细。
m4_reset();
Mode::set( 'shadow' );
catalog();
$cart = m4_cart( array( array( 101, 1 ) ) );
$numbers( $cart );
$result = Shadow::check( $cart );
Shadow::record( 'shadow', $result['kind'], $result );
$check( '一致：只计数，不写明细', 'matched' === $result['kind'] && ! array_filter( $GLOBALS['wpdb']->writes, static fn( $w ) => 'insert' === $w[0] ) );

// 积分券的码是个人的：明细里只记 ID。
m4_reset();
Mode::set( 'shadow' );
catalog();
m4_user( 7, 'seven@example.com' );
loyalty_coupon( 920, 7, '7.5' );
$cart = m4_cart( array( array( 101, 1 ) ), array( 'pcp-920' ) );
$numbers( $cart );
$cart->set_total( 1 );
$result = Shadow::check( $cart );
$detail = (string) json_encode( Shadow::detail( $result ) );
$check( '积分券：明细里不出现券码，只记 ID', 'mismatched' === $result['kind'] && false === strpos( $detail, 'pcp-920' ) && false !== strpos( $detail, 'loyalty#920' ) );

// ---------- 跳过，不算不一致 ----------
m4_reset();
Mode::set( 'shadow' );
catalog();
add_action( 'woocommerce_cart_calculate_fees', static function ( $cart ) { $cart->fees_api()->add_fee( array( 'id' => 'other-plugin-fee', 'name' => 'Handling', 'amount' => 2 ) ); }, 30 );
$cart = m4_cart( array( array( 101, 1 ) ) );
$numbers( $cart );
$result = Shadow::check( $cart );
$check( '别的插件加了费用：跳过，原因写明', 'skipped' === $result['kind'] && array( 'foreign_fee' ) === $result['reason'] );

m4_reset();
Mode::set( 'shadow' );
catalog();
$cart = m4_cart( array( array( 101, 1 ) ) );
$numbers( $cart );
$cart->set_quantity( array_key_first( $cart->cart_contents ), 3 ); // 算完之后又改了数量，还没重算
$result = Shadow::check( $cart );
$check( '购物车算完又被改过：跳过', 'skipped' === $result['kind'] && array( 'stale_cart' ) === $result['reason'] );
Shadow::record( 'shadow', $result['kind'], $result );
Shadow::record( 'shadow', $result['kind'], $result );
$check( '同一种跳过原因一小时内只写一条明细，统计照常计数', 1 === count( array_filter( $GLOBALS['wpdb']->writes, static fn( $w ) => 'insert' === $w[0] ) ) && 2 === count( array_filter( $GLOBALS['wpdb']->writes, static fn( $w ) => 'query' === $w[0] ) ) );

m4_reset();
Mode::set( 'shadow' );
catalog();
add_filter( 'woocommerce_coupon_get_discount_amount', static fn( $v ) => $v, 10, 1 );
$cart = m4_cart( array( array( 101, 1 ) ) );
$numbers( $cart );
$check( '别的代码改了优惠码金额的算法：跳过', 'skipped' === Shadow::check( $cart )['kind'] );

// ---------- 请求末尾的入口 ----------
m4_reset();
Mode::set( 'shadow' );
catalog();
$cart = m4_cart( array( array( 106, 2 ) ) );
$cart->calculate_totals();
Shadow::compare();
$after_first = count( $GLOBALS['wpdb']->writes );
Shadow::reset();
Shadow::compare();
$check( '请求末尾核对一次并计数；同一份购物车同样的结果不重复核对', 1 === $after_first && 1 === count( $GLOBALS['wpdb']->writes ) );
m4_reset();
catalog();
$cart = m4_cart( array( array( 106, 2 ) ) );
$cart->calculate_totals();
Shadow::compare();
$check( '关着时请求末尾什么都不做', array() === $GLOBALS['wpdb']->writes && false === get_option( Shadow::DB_OPTION ) );

// ---------- 版本号在、表不在（整店复制出来的新店：版本号跟着设置过来了，表没有） ----------
m4_reset();
Mode::set( 'shadow' );
catalog();
update_option( Shadow::DB_OPTION, Shadow::DB_VERSION );
$GLOBALS['wpdb']->missing = array( Shadow::table( 'stats' ) => true, Shadow::table( 'log' ) => true );
$GLOBALS['m4']['dbdelta'] = array();
$check( '表不在时查看核对结果：当作还没有记录，不去查不存在的表', array() === Shadow::summary( 7, 20 )['totals'] && array() === Shadow::summary( 7, 20 )['recent'] && 0 === Runtime::status_brief()['today']['compared'] );
Shadow::clear();
$check( '表不在时清空：什么都不做', array() === $GLOBALS['wpdb']->writes );
Shadow::record( 'shadow', 'mismatched', array( 'legacy' => array( 'total' => 100 ), 'engine' => array( 'total' => 101 ) ) );
$kinds = array_column( $GLOBALS['wpdb']->writes, 0 );
$check( '表不在时第一次记录：补建两张表，统计和明细都记上', 2 === count( $GLOBALS['m4']['dbdelta'] ) && array( 'query', 'insert' ) === $kinds && array() === $GLOBALS['wpdb']->missing );
$GLOBALS['m4']['dbdelta'] = array();
Shadow::record( 'shadow', 'matched', array() );
$check( '表在时照常只写一次，不重复建表', array() === $GLOBALS['m4']['dbdelta'] && 3 === count( $GLOBALS['wpdb']->writes ) );

// ---------- 常量锁定（放最后：常量定义后不能撤销） ----------
m4_reset();
Mode::set( 'on' );
define( 'PC_PRICING_ENGINE_MODE', 'off' );
$check( 'wp-config.php 的常量优先：一键关闭', Mode::OFF === Mode::get() && ! Runtime::on() && Mode::locked() );
$check( '被常量锁定时后台改不了档位', is_wp_error( Mode::set( 'on' ) ) );

printf( "RESULT %d 项检查：%s\n", $checks, $failed ? count( $failed ) . ' 项不通过' : '全部通过' );
exit( $failed ? 1 : 0 );
