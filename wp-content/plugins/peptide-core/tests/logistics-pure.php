<?php
/**
 * 物流引擎（价格文档第三章）：算重、超重加收、陆联规避、路由标签、货代成本。
 *
 * 真的把 PeptideCore\Shipping\Logistics 载进来，只把 WordPress 换成桩。
 * 不碰数据库、不需要 WordPress、不发任何请求。
 *
 * 要证明的事：
 * 1. 总毛重 = 外箱包材 60g + Σ(数量 × 单盒重)，四个类别各自的默认重量正确。
 * 2. 超重附加费：重型水剂满 2 盒触发、总毛重过 1000g 触发、每 500g 加收 $10、
 *    不足一档也算一档、满 $500 豁免第一档、不触发时一分不收。
 * 3. 陆联规避：含 HGH 或任意水剂就不能走陆联；纯冻干粉可以。
 * 4. 路由标签：HGH > 重水剂 > 轻溶媒 > 普通，优先级不能错。
 * 5. 货代成本：计费重向上取整、陆联多算 200g 资料重、换货代算出来的成本跟着变、
 *    货代不走某条线时返回 0。
 * 6. 默认关闭：没打开时什么都不做。
 */
namespace {

	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['t_checks']  = 0;
	$GLOBALS['t_options'] = array();

	function check( string $label, bool $ok ): void {
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$GLOBALS['t_checks']++;
	}
	function get_option( $k, $d = false ) { return $GLOBALS['t_options'][ $k ] ?? $d; }
	function add_filter( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['t_hooks'][ $h ][] = $c; return true; }
	function add_action( $h, $c, $p = 10, $a = 1 ) { return add_filter( $h, $c, $p, $a ); }
	function __( $t, $d = '' ) { return $t; }

	class WC_Product {}
	class WC_Order {}
	class WC_Order_Item_Product {}
}

namespace PeptideCore\Shipping {

	require_once __DIR__ . '/../includes/shipping/class-logistics.php';

	$L = Logistics::class;
	$s = Logistics::settings();

	// ---------- 1. 算重 ----------
	$one_lyo = Logistics::summarize( array( array( 'class' => Logistics::LYO, 'weight' => 125, 'qty' => 1 ) ) );
	\check( '1 盒冻干粉 = 包材 60 + 125 = 185g', 185 === $one_lyo['weight'] );
	\check( '1 盒冻干粉：盒数 1，分类计数正确', 1 === $one_lyo['boxes'] && 1 === $one_lyo['counts'][ Logistics::LYO ] );

	$three = Logistics::summarize( array( array( 'class' => Logistics::LYO, 'weight' => 125, 'qty' => 3 ) ) );
	\check( '3 盒冻干粉 = 60 + 375 = 435g', 435 === $three['weight'] );

	$mixed = Logistics::summarize( array(
		array( 'class' => Logistics::LYO,   'weight' => 125, 'qty' => 2 ),
		array( 'class' => Logistics::LIQ10, 'weight' => 260, 'qty' => 2 ),
		array( 'class' => Logistics::LIQ3,  'weight' => 105, 'qty' => 1 ),
	) );
	\check( '混装 = 60 + 250 + 520 + 105 = 935g', 935 === $mixed['weight'] );
	\check( '混装：各类别计数正确', 2 === $mixed['counts'][ Logistics::LYO ] && 2 === $mixed['counts'][ Logistics::LIQ10 ] && 1 === $mixed['counts'][ Logistics::LIQ3 ] );
	\check( '混装：总盒数 5', 5 === $mixed['boxes'] );

	\check( '没写重量时按类别兜底（重水剂 260g）', 320 === Logistics::summarize( array( array( 'class' => Logistics::LIQ10, 'weight' => 0, 'qty' => 1 ) ) )['weight'] );
	\check( '类别写错时按冻干粉算', 185 === Logistics::summarize( array( array( 'class' => 'NONSENSE', 'weight' => 0, 'qty' => 1 ) ) )['weight'] );
	\check( '数量 0 的行不算', 60 === Logistics::summarize( array( array( 'class' => Logistics::LYO, 'weight' => 125, 'qty' => 0 ) ) )['weight'] );
	\check( '空车只有包材重', 60 === Logistics::summarize( array() )['weight'] && 0 === Logistics::summarize( array() )['boxes'] );

	// ---------- 2. 超重附加费 ----------
	\check( '2: 1 盒冻干粉不收超重费', 0.0 === Logistics::surcharge( 185, 0, 39.0, $s ) );
	\check( '2: 1 盒重水剂（320g）不收', 0.0 === Logistics::surcharge( 320, 1, 68.0, $s ) );
	\check( '2: 2 盒重水剂（580g，没过 1000g）按盒数触发，收 1 档 $10', 10.0 === Logistics::surcharge( 580, 2, 124.0, $s ) );
	\check( '2: 总重 1200g（超出 200g，不足一档也算一档）收 $10', 10.0 === Logistics::surcharge( 1200, 0, 300.0, $s ) );
	\check( '2: 总重 1600g（超出 600g = 2 档）收 $20', 20.0 === Logistics::surcharge( 1600, 0, 300.0, $s ) );
	\check( '2: 总重正好 1000g 不收（要超过才算）', 0.0 === Logistics::surcharge( 1000, 0, 300.0, $s ) );
	\check( '2: 满 $500 豁免第一档：1200g 变成不收', 0.0 === Logistics::surcharge( 1200, 0, 500.0, $s ) );
	\check( '2: 满 $500 豁免第一档：1600g 从 $20 降到 $10', 10.0 === Logistics::surcharge( 1600, 0, 650.0, $s ) );
	\check( '2: 满 $500 但只因盒数触发时，直接免掉', 0.0 === Logistics::surcharge( 580, 2, 520.0, $s ) );

	$no_waive = array_merge( $s, array( 'vip_waiver_subtotal' => 0.0 ) );
	\check( '2: 关掉豁免后，$650 的单照样收 $20', 20.0 === Logistics::surcharge( 1600, 0, 650.0, $no_waive ) );

	// ---------- 3. 陆联规避 ----------
	$c = static fn( array $o ): array => array_merge( array( Logistics::LYO => 0, Logistics::LIQ3 => 0, Logistics::LIQ10 => 0, Logistics::HGH => 0 ), $o );
	\check( '3: 纯冻干粉可以走陆联', true === Logistics::ground_allowed( $c( array( Logistics::LYO => 3 ) ) ) );
	\check( '3: 含生长激素不能走陆联', false === Logistics::ground_allowed( $c( array( Logistics::LYO => 2, Logistics::HGH => 1 ) ) ) );
	\check( '3: 含 1 盒重水剂就不能走陆联', false === Logistics::ground_allowed( $c( array( Logistics::LYO => 5, Logistics::LIQ10 => 1 ) ) ) );
	\check( '3: 含轻溶媒也不能走陆联', false === Logistics::ground_allowed( $c( array( Logistics::LIQ3 => 1 ) ) ) );
	\check( '3: 空车可以走', true === Logistics::ground_allowed( $c( array() ) ) );

	$kw = $s['ground_keywords'];
	\check( '3: 「FedEx Ultra-Express 3-5d」认得出是陆联', true === Logistics::is_ground( 'FedEx Ultra-Express 3-5d', $kw ) );
	\check( '3: 「3–5 天」用中文破折号也认得出', true === Logistics::is_ground( '大陆联邦 3–5 天', $kw ) );
	\check( '3: 「FedEx Priority Air 5-7d」不是陆联', false === Logistics::is_ground( 'FedEx Priority Air 5-7d', $kw ) );
	\check( '3: 关键词大小写不敏感', true === Logistics::is_ground( 'GROUND SHIPPING', $kw ) );
	\check( '3: 关键词为空时不误伤', false === Logistics::is_ground( 'FedEx Ultra', array( '', '  ' ) ) );

	// ---------- 4. 路由标签 ----------
	\check( '4: 普通单 = STANDARD', 'STANDARD' === Logistics::route_label( $c( array( Logistics::LYO => 3 ) ) ) );
	\check( '4: 带轻溶媒 = 拆单或港联直飞', 'SPLIT_BA3_TO_HK_OR_HK_VIP_FLIGHT' === Logistics::route_label( $c( array( Logistics::LYO => 5, Logistics::LIQ3 => 1 ) ) ) );
	\check( '4: 带重水剂 = 锁港联', 'HK_ONLY_HEAVY_LIQUID' === Logistics::route_label( $c( array( Logistics::LYO => 1, Logistics::LIQ10 => 2 ) ) ) );
	\check( '4: 带生长激素 = 锁港联（优先级最高）', 'HK_ONLY_HGH' === Logistics::route_label( $c( array( Logistics::HGH => 1, Logistics::LIQ10 => 2, Logistics::LIQ3 => 1 ) ) ) );

	// ---------- 5. 货代成本 ----------
	\check( '5: 185g 算 1 档计费重', 1 === Logistics::billable_steps( 185 ) );
	\check( '5: 500g 算 1 档（正好一档）', 1 === Logistics::billable_steps( 500 ) );
	\check( '5: 501g 算 2 档（向上取整）', 2 === Logistics::billable_steps( 501 ) );
	\check( '5: 陆联多算 200g 资料重：435g + 200 = 635g → 2 档', 2 === Logistics::billable_steps( 435, 200 ) );

	$cost_a = Logistics::forwarder_cost( 435, 'hk', $s );
	\check( '5: 主力货代 A 发港联 1 档 = ¥365 / 6.45 = $56.59', 56.59 === $cost_a );
	$s_b   = array_merge( $s, array( 'active_forwarder' => 'B' ) );
	$cost_b = Logistics::forwarder_cost( 435, 'hk', $s_b );
	\check( '5: 切到货代 B，同一票成本变贵', $cost_b > $cost_a );
	\check( '5: 货代 A 不走陆联，返回 0（代表这条线不通）', 0.0 === Logistics::forwarder_cost( 435, 'cn', $s ) );
	\check( '5: 货代 B 走陆联，算得出成本', Logistics::forwarder_cost( 435, 'cn', $s_b ) > 0 );
	\check( '5: 续重真的加钱：1200g 比 435g 贵', Logistics::forwarder_cost( 1200, 'hk', $s_b ) > Logistics::forwarder_cost( 435, 'hk', $s_b ) );
	\check( '5: 货代编号不存在时返回 0，不报错', 0.0 === Logistics::forwarder_cost( 435, 'hk', array_merge( $s, array( 'active_forwarder' => 'ZZ' ) ) ) );

	// ---------- 6. 默认关闭 ----------
	\check( '6: 默认是关闭的，部署代码不改变任何现有行为', false === Logistics::enabled() );
	$GLOBALS['t_options'][ Logistics::OPTION ] = array( 'enabled' => true );
	\check( '6: 在设置里打开后才生效', true === Logistics::enabled() );
	\check( '6: 打开后其他设置仍取默认值', 10.0 === Logistics::settings()['heavy_fee'] );
	$GLOBALS['t_options'] = array();

	// ---------- 7. 钩子挂载 ----------
	Logistics::register_hooks();
	foreach ( array( 'woocommerce_package_rates', 'woocommerce_checkout_create_order', 'woocommerce_store_api_checkout_update_order_from_request' ) as $h ) {
		\check( "7: 挂上了钩子 {$h}", ! empty( $GLOBALS['t_hooks'][ $h ] ) );
	}

	echo 'PASS ', $GLOBALS['t_checks'], " logistics engine assertions\n";
}
