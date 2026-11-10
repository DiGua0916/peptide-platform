<?php
/**
 * M4 对照测试的购物车样例（m4-price-engine-pure.php 和 m4-engine-mode-pure.php 共用）。
 * 每个样例：cart = 搭好场景并返回购物车的函数；expect = 手算的应付总额（分，可选）。
 *
 * @package PeptideCore
 */

use PeptideCore\Coupon_Rules;
use PeptideCore\Customer_Segments;
use PeptideCore\First_Order_Offer;
use PeptideCore\Product_Offers;
use PeptideCore\Promotions;

/** 满减活动的设置。 */
function promo( string $type, array $tiers, array $extra = array() ): array {
	$rows = array();
	foreach ( $tiers as [ $min, $value ] ) {
		$rows[] = array( 'min' => (float) $min, 'value' => (float) $value );
	}
	return array_merge(
		Promotions::defaults(),
		array(
			'type'   => $type,
			'basis'  => in_array( $type, array( 'spend_amount', 'spend_percent' ), true ) ? 'spend' : 'quantity',
			'reward' => in_array( $type, array( 'spend_percent', 'qty_percent', 'nth_percent' ), true ) ? 'percent' : 'amount',
			'tiers'  => $rows,
		),
		$extra
	);
}

/** 常用商品。 */
function catalog(): void {
	m4_product( 101, '29.98', array( 'cats' => array( 10 ) ) );
	m4_product( 102, '37.98', array( 'cats' => array( 10 ) ) );
	m4_product( 103, '44.98', array( 'cats' => array( 11 ) ) );
	m4_product( 104, '59.98', array( 'cats' => array( 11 ), 'regular' => '119.98' ) ); // 促销中
	m4_product( 105, '9.99', array( 'cats' => array( 12 ) ) );
	m4_product( 106, '50.00', array( 'cats' => array( 12 ) ) );
	m4_product( 107, '49.99', array( 'cats' => array( 12 ) ) );
	m4_product( 108, '9.995', array( 'cats' => array( 12 ) ) ); // 半分钱
	m4_product( 200, '0', array( 'cats' => array( 13 ) ) );     // 可变商品（父）
	m4_product( 201, '19.99', array( 'parent' => 200 ) );
	m4_product( 202, '24.99', array( 'parent' => 200 ) );
}

function welcome5( int $id = 900 ): void {
	m4_coupon( $id, 'WELCOME5', array( 'type' => 'percent', 'amount' => '5', 'limit_per_user' => 1 ), array( Coupon_Rules::FIRST_META => 'yes', Coupon_Rules::STRATEGY_META => 'stack' ) );
	update_option( First_Order_Offer::OPTION, array( 'enabled' => true, 'coupon_id' => $id ) );
}

function loyalty_coupon( int $id, int $user, string $amount, array $over = array() ): void {
	$request = 'req-' . $id . '-0000000000';
	m4_coupon( $id, 'pcp-' . $id, array( 'type' => 'fixed_cart', 'amount' => $amount, 'individual_use' => true, 'usage_limit' => 1, 'limit_per_user' => 1 ), array_merge( array( '_pc_loyalty_user' => $user, '_pc_loyalty_points' => 750, '_pc_loyalty_request' => $request, '_pc_loyalty_currency' => 'USD' ), $over ) );
	$GLOBALS['m4']['ledger'][ 'redeem:' . $request ] = array( 'reference_id' => $id, 'user_id' => $user, 'delta' => -750 );
}

/** 加一套捆绑；加不进去说明样例本身搭错了。 */
function add_bundle( int $offer, array $choices, int $units, WC_Cart $cart ): void {
	$r = Product_Offers::add_bundle( $offer, $choices, $units, $cart );
	if ( is_wp_error( $r ) ) {
		throw new RuntimeException( '样例里的捆绑加不进购物车：' . $r->get_error_message() );
	}
}

function m4_cases(): array {
$cases = array();

// ---------- 单品、多品 ----------
$cases['01 单品，没有任何优惠'] = array( 'expect' => 2998, 'cart' => static fn() => m4_cart( array( array( 101, 1 ) ) ) );
$cases['02 多品，没有任何优惠'] = array( 'expect' => 2 * 2998 + 4498, 'cart' => static fn() => m4_cart( array( array( 101, 2 ), array( 103, 1 ) ) ) );

// ---------- 满减边界 ----------
$spend = static fn() => m4_promotion( 500, 'Spend & Save', promo( 'spend_amount', array( array( 100, 10 ), array( 200, 25 ) ) ) );
$cases['03 满额减元：刚好到第一档（$100.00）'] = array( 'expect' => 9000, 'cart' => static function () use ( $spend ) { $spend(); return m4_cart( array( array( 106, 2 ) ) ); } );
$cases['04 满额减元：差一分不到门槛（$99.99）'] = array( 'expect' => 9999, 'cart' => static function () use ( $spend ) { $spend(); return m4_cart( array( array( 106, 1 ), array( 107, 1 ) ) ); } );
$cases['05 满额减元：刚好到第二档（$200.00）'] = array( 'expect' => 17500, 'cart' => static function () use ( $spend ) { $spend(); return m4_cart( array( array( 106, 4 ) ) ); } );
$cases['06 满额打折：15%，结果有半分以下的零头'] = array( 'expect' => 8994 - 1349, 'cart' => static function () { m4_promotion( 501, '15% off $80+', promo( 'spend_percent', array( array( 80, 15 ) ) ) ); return m4_cart( array( array( 101, 3 ) ) ); } );
$cases['07 满件减元，上不封顶：每 3 件减 $12，买 7 件'] = array( 'expect' => 7 * 2998 - 2400, 'cart' => static function () { m4_promotion( 502, 'Every 3', promo( 'qty_amount', array( array( 3, 12 ) ), array( 'unlimited' => true ) ) ); return m4_cart( array( array( 101, 7 ) ) ); } );
$cases['08 第 2 件 7 折、第 3 件 5 折'] = array( 'cart' => static function () { m4_promotion( 503, 'Nth item', promo( 'nth_percent', array( array( 2, 30 ), array( 3, 50 ) ) ) ); return m4_cart( array( array( 103, 1 ), array( 101, 2 ) ) ); } );
$cases['09 满件一口价：任意 3 件 $99，买 4 件'] = array( 'cart' => static function () { m4_promotion( 504, 'Any 3 for 99', promo( 'bundle_price', array( array( 3, 99 ) ) ) ); return m4_cart( array( array( 102, 2 ), array( 103, 2 ) ) ); } );
$cases['10 满 3 免 1，上不封顶，买 6 件'] = array( 'cart' => static function () { m4_promotion( 505, 'Buy 3 get 1', promo( 'buy_x_free_y', array( array( 3, 1 ) ), array( 'unlimited' => true ) ) ); return m4_cart( array( array( 101, 4 ), array( 105, 2 ) ) ); } );
$cases['11 上不封顶的满额减元：每满 $100 减 $10，小计 $359.76'] = array( 'expect' => 35976 - 3000, 'cart' => static function () { m4_promotion( 506, 'Every 100', promo( 'spend_amount', array( array( 100, 10 ) ), array( 'unlimited' => true ) ) ); return m4_cart( array( array( 101, 12 ) ) ); } );
$cases['12 活动优先级：指定商品 > 专辑 > 全部商品，三个活动各管各的'] = array(
	'cart' => static function () {
		m4_promotion( 510, 'All', promo( 'spend_percent', array( array( 20, 5 ) ) ) );
		m4_promotion( 511, 'Collection 10', promo( 'qty_amount', array( array( 2, 6 ) ), array( 'scope' => 'categories', 'categories' => array( 10 ) ) ) );
		m4_promotion( 512, 'Product 103', promo( 'spend_amount', array( array( 40, 4 ) ), array( 'scope' => 'products', 'products' => array( 103 ) ) ) );
		return m4_cart( array( array( 101, 1 ), array( 102, 1 ), array( 103, 1 ), array( 106, 1 ) ) );
	},
);
$cases['13 活动不含促销商品：促销中的商品不参加'] = array( 'cart' => static function () { m4_promotion( 513, 'No sale items', promo( 'spend_percent', array( array( 30, 10 ) ), array( 'exclude_sale' => true ) ) ); return m4_cart( array( array( 104, 1 ), array( 102, 1 ) ) ); } );
$cases['14 可变商品 + 专辑活动（按父商品的专辑）'] = array( 'cart' => static function () { m4_promotion( 514, 'Collection 13', promo( 'qty_percent', array( array( 2, 10 ), array( 4, 20 ) ), array( 'scope' => 'categories', 'categories' => array( 13 ) ) ) ); return m4_cart( array( array( 201, 2 ), array( 202, 3 ), array( 101, 1 ) ) ); } );

// ---------- 券叠加和互斥 ----------
$cases['15 优惠码 10% 叠加满减活动'] = array(
	'expect' => 10000 - 1000 - 1000,
	'cart'   => static function () use ( $spend ) { $spend(); m4_coupon( 901, 'SAVE10', array( 'type' => 'percent', 'amount' => '10' ), array( Coupon_Rules::STRATEGY_META => 'stack' ) ); return m4_cart( array( array( 106, 2 ) ), array( 'SAVE10' ) ); },
);
$cases['16 “不叠加满减”的优惠码遇到生效的活动：优惠码不能用'] = array(
	'expect' => 9000,
	'cart'   => static function () use ( $spend ) { $spend(); m4_coupon( 902, 'SOLO', array( 'type' => 'percent', 'amount' => '20' ), array( Coupon_Rules::STRATEGY_META => 'no_stack' ) ); return m4_cart( array( array( 106, 2 ) ), array( 'SOLO' ) ); },
);
$cases['17 “不叠加满减”的优惠码，活动没达到门槛：优惠码照常用'] = array(
	'expect' => 5000 - 1000,
	'cart'   => static function () use ( $spend ) { $spend(); m4_coupon( 902, 'SOLO', array( 'type' => 'percent', 'amount' => '20' ), array( Coupon_Rules::STRATEGY_META => 'no_stack' ) ); return m4_cart( array( array( 106, 1 ) ), array( 'SOLO' ) ); },
);
$cases['18 “替换满减”的优惠码：活动不生效，只算优惠码'] = array(
	'expect' => 10000 - 1500,
	'cart'   => static function () use ( $spend ) { $spend(); m4_coupon( 903, 'SWAP15', array( 'type' => 'percent', 'amount' => '15' ), array( Coupon_Rules::STRATEGY_META => 'replace' ) ); return m4_cart( array( array( 106, 2 ) ), array( 'SWAP15' ) ); },
);
$cases['19 活动设了“不能和优惠码同时使用”：购物车有优惠码时活动不生效'] = array(
	'expect' => 10000 - 1000,
	'cart'   => static function () { m4_promotion( 520, 'No coupons', promo( 'spend_amount', array( array( 100, 30 ) ), array( 'with_coupons' => false ) ) ); m4_coupon( 901, 'SAVE10', array( 'type' => 'percent', 'amount' => '10' ) ); return m4_cart( array( array( 106, 2 ) ), array( 'SAVE10' ) ); },
);
$cases['20 两张优惠码：先按比例，再减固定金额'] = array(
	'cart' => static function () { m4_coupon( 901, 'SAVE10', array( 'type' => 'percent', 'amount' => '10' ) ); m4_coupon( 904, 'FIVE', array( 'type' => 'fixed_cart', 'amount' => '5' ) ); return m4_cart( array( array( 101, 1 ), array( 102, 1 ), array( 105, 3 ) ), array( 'FIVE', 'SAVE10' ) ); },
);
$cases['21 固定金额券 $20 分摊到三种商品，有零头'] = array( 'cart' => static function () { m4_coupon( 905, 'TWENTY', array( 'type' => 'fixed_cart', 'amount' => '20' ) ); return m4_cart( array( array( 101, 1 ), array( 102, 1 ), array( 105, 3 ) ), array( 'TWENTY' ) ); } );
$cases['22 每件减 $3 的券，最多 2 件，不含促销商品'] = array( 'cart' => static function () { m4_coupon( 906, 'EACH3', array( 'type' => 'fixed_product', 'amount' => '3', 'limit_x' => 2, 'exclude_sale' => true ) ); return m4_cart( array( array( 104, 1 ), array( 101, 3 ) ), array( 'EACH3' ) ); } );
$cases['23 固定金额券比商品还贵：减到 0 为止'] = array( 'expect' => 0, 'cart' => static function () { m4_coupon( 907, 'BIG', array( 'type' => 'fixed_cart', 'amount' => '50' ) ); return m4_cart( array( array( 101, 1 ) ), array( 'BIG' ) ); } );
$cases['24 优惠码的满金额条件：适用商品刚好 $75.96（门槛 $75.96）'] = array( 'cart' => static function () { m4_coupon( 908, 'MIN', array( 'type' => 'percent', 'amount' => '12', 'cats' => array( 10 ) ), array( Coupon_Rules::MIN_SPEND_META => '75.96' ) ); return m4_cart( array( array( 102, 2 ), array( 103, 1 ) ), array( 'MIN' ) ); } );
$cases['25 优惠码的满件数条件：差一件，不能用'] = array( 'expect' => 2 * 3798, 'cart' => static function () { m4_coupon( 909, 'QTY3', array( 'type' => 'percent', 'amount' => '12' ), array( Coupon_Rules::MIN_QTY_META => '3' ) ); return m4_cart( array( array( 102, 2 ) ), array( 'QTY3' ) ); } );
$cases['26 过期的优惠码、用完的优惠码、没到最低消费的优惠码：都不生效'] = array(
	'expect' => 2998,
	'cart'   => static function () {
		m4_coupon( 910, 'OLD', array( 'type' => 'percent', 'amount' => '50', 'expires' => time() - 3600 ) );
		m4_coupon( 911, 'USED', array( 'type' => 'percent', 'amount' => '50', 'usage_limit' => 5, 'usage_count' => 5 ) );
		m4_coupon( 912, 'MIN100', array( 'type' => 'percent', 'amount' => '50', 'min' => '100' ) );
		return m4_cart( array( array( 101, 1 ) ), array( 'OLD', 'USED', 'MIN100' ) );
	},
);
$cases['27 限定邮箱的优惠码：邮箱对得上才生效'] = array(
	'cart' => static function () { $GLOBALS['m4']['billing'] = 'Buyer@Example.com'; m4_coupon( 913, 'VIP', array( 'type' => 'percent', 'amount' => '10', 'emails' => array( '*@example.com' ) ) ); m4_coupon( 914, 'OTHER', array( 'type' => 'percent', 'amount' => '10', 'emails' => array( 'someone@else.test' ) ) ); return m4_cart( array( array( 103, 2 ) ), array( 'VIP', 'OTHER' ) ); },
);
$cases['28 半分钱的价格（$9.995）+ 10% 优惠码'] = array( 'cart' => static function () { m4_coupon( 901, 'SAVE10', array( 'type' => 'percent', 'amount' => '10' ) ); return m4_cart( array( array( 108, 1 ), array( 108, 3 ) ), array( 'SAVE10' ) ); } );

// ---------- 首单 ----------
$cases['29 首单券 WELCOME5 + 满减活动：新顾客，两样都生效'] = array(
	'expect' => 10000 - 500 - 1000,
	'cart'   => static function () use ( $spend ) { $spend(); welcome5(); $GLOBALS['m4']['billing'] = 'new@example.com'; return m4_cart( array( array( 106, 2 ) ), array( 'WELCOME5' ) ); },
);
$cases['30 首单券：老顾客（下过单）不能用'] = array(
	'expect' => 10000,
	'cart'   => static function () { welcome5(); $GLOBALS['m4']['billing'] = 'old@example.com'; $GLOBALS['m4']['orders'][] = array( 'user' => 0, 'email' => 'old@example.com' ); return m4_cart( array( array( 106, 2 ) ), array( 'WELCOME5' ) ); },
);
$cases['31 首单券遇到“不能和优惠码同时使用”的活动：券不生效，活动这一次也不算（线上现状）'] = array(
	'cart' => static function () { m4_promotion( 520, 'No coupons', promo( 'spend_amount', array( array( 100, 30 ) ), array( 'with_coupons' => false ) ) ); welcome5(); return m4_cart( array( array( 106, 2 ) ), array( 'WELCOME5' ) ); },
);
$cases['32 首单券（登录顾客已经用过一次）'] = array( 'expect' => 4498, 'cart' => static function () { welcome5(); m4_user( 7, 'seven@example.com' ); $GLOBALS['m4']['coupons'][900]['used_by'] = array( 7 => 1 ); return m4_cart( array( array( 103, 1 ) ), array( 'WELCOME5' ) ); } );

// ---------- 积分券 ----------
$cases['33 积分券 $7.50：本人使用'] = array( 'expect' => 2998 + 3798 - 750, 'cart' => static function () { m4_user( 7, 'seven@example.com' ); loyalty_coupon( 920, 7, '7.5' ); return m4_cart( array( array( 101, 1 ), array( 102, 1 ) ), array( 'pcp-920' ) ); } );
$cases['34 积分券：别人的券、已退回的券都不能用'] = array(
	'expect' => 2998,
	'cart'   => static function () { m4_user( 8, 'eight@example.com' ); loyalty_coupon( 920, 7, '7.5' ); loyalty_coupon( 921, 8, '5', array( '_pc_loyalty_released' => 'yes' ) ); return m4_cart( array( array( 101, 1 ) ), array( 'pcp-920', 'pcp-921' ) ); },
);
$cases['35 积分券 + 满减活动'] = array( 'cart' => static function () use ( $spend ) { $spend(); m4_user( 7, 'seven@example.com' ); loyalty_coupon( 920, 7, '7.5' ); return m4_cart( array( array( 106, 3 ) ), array( 'pcp-920' ) ); } );

// ---------- 赠品、捆绑 ----------
$gift = static fn( array $over = array() ) => m4_offer( 700, 'Free vial', array_merge( array( 'kind' => 'gift', 'basis' => 'spend', 'threshold' => 60.0, 'gift_product' => 105, 'gift_qty' => 1 ), $over ) );
$cases['36 赠品：满 $60 自动送一件（0 元）'] = array( 'expect' => 2 * 2998 + 3798, 'cart' => static function () use ( $gift ) { $gift(); return m4_cart( array( array( 101, 2 ), array( 102, 1 ) ) ); } );
$cases['37 赠品：差一点不到门槛，不送'] = array( 'expect' => 2 * 2998, 'cart' => static function () use ( $gift ) { $gift(); return m4_cart( array( array( 101, 2 ) ) ); } );
$cases['38 赠品遇到优惠码：赠品不送，优惠码生效'] = array( 'cart' => static function () use ( $gift ) { $gift(); m4_coupon( 901, 'SAVE10', array( 'type' => 'percent', 'amount' => '10' ) ); return m4_cart( array( array( 101, 2 ), array( 102, 1 ) ), array( 'SAVE10' ) ); } );
$cases['39 赠品遇到满减活动里的商品：赠品不送，满减照算'] = array( 'cart' => static function () use ( $gift, $spend ) { $gift(); $spend(); return m4_cart( array( array( 106, 2 ) ) ); } );
$bundle = static function ( array $over = array() ): void {
	m4_offer( 710, 'Starter kit', array_merge( array( 'kind' => 'bundle', 'price_type' => 'fixed', 'price_value' => 40.0, 'slots' => array( array( 'id' => 'slot-0', 'label' => 'A', 'qty' => 1, 'choices' => array( 101, 102 ) ), array( 'id' => 'slot-1', 'label' => 'B', 'qty' => 2, 'choices' => array( 105 ) ) ) ), $over ) );
};
$cases['40 捆绑一口价 $40（1 + 2 件，原价 $57.96），按原价比例分摊到各行'] = array( 'expect' => 4000, 'cart' => static function () use ( $bundle ) { $bundle(); $cart = m4_cart( array() ); add_bundle( 710, array( 'slot-0' => 102 ), 1, $cart ); return $cart; } );
$cases['41 捆绑打 85 折，买 2 套，再加一件普通商品'] = array( 'cart' => static function () use ( $bundle ) { $bundle( array( 'price_type' => 'percent', 'price_value' => 15.0 ) ); $cart = m4_cart( array( array( 103, 1 ) ) ); add_bundle( 710, array( 'slot-0' => 101 ), 2, $cart ); return $cart; } );
$cases['42 捆绑 + 别的商品参加满减（捆绑里的商品不在活动里）'] = array(
	'expect' => 4000 + 10000 - 1500,
	'cart' => static function () use ( $bundle ) { $bundle(); m4_promotion( 530, 'Product 106', promo( 'spend_amount', array( array( 100, 15 ) ), array( 'scope' => 'products', 'products' => array( 106 ) ) ) ); $cart = m4_cart( array( array( 106, 2 ) ) ); add_bundle( 710, array( 'slot-0' => 101 ), 1, $cart ); return $cart; },
);
$cases['43 捆绑之后挂上优惠码：捆绑恢复原价，优惠码不能用（线上现状）'] = array( 'expect' => 2998 + 2 * 999, 'cart' => static function () use ( $bundle ) { $bundle(); m4_coupon( 901, 'SAVE10', array( 'type' => 'percent', 'amount' => '10' ) ); $cart = m4_cart( array() ); add_bundle( 710, array( 'slot-0' => 101 ), 1, $cart ); $cart->applied_coupons = array( 'save10' ); return $cart; } );

// ---------- 运费折扣 ----------
$cases['44 运费 5 折：$15 → $7.50'] = array( 'expect' => 2998 + 750, 'cart' => static function () { $GLOBALS['m4']['rates'] = array( array( 'id' => 'plan:std', 'cost' => '15.00' ) ); m4_shipping_discount( array( 'id' => 1, 'name' => 'Half shipping', 'kind' => 'percent', 'value' => 50.0 ) ); return m4_cart( array( array( 101, 1 ) ) ); } );
$cases['45 两个运费折扣取折后最低：$45 减 $5 对比 6 折'] = array(
	'expect' => 2998 + 2700,
	'cart'   => static function () { $GLOBALS['m4']['rates'] = array( array( 'id' => 'plan:fast', 'cost' => '45.00' ) ); m4_shipping_discount( array( 'id' => 1, 'name' => '$5 off', 'kind' => 'amount', 'value' => 5.0 ) ); m4_shipping_discount( array( 'id' => 2, 'name' => '40% off', 'kind' => 'percent', 'value' => 40.0 ) ); return m4_cart( array( array( 101, 1 ) ) ); },
);
$cases['46 会员日运费折扣：没登录的顾客用不了；没到时间的阶段折扣不算'] = array(
	'expect' => 2998 + 1500,
	'cart'   => static function () {
		$GLOBALS['m4']['rates'] = array( array( 'id' => 'plan:std', 'cost' => '15.00' ) );
		m4_shipping_discount( array( 'id' => 1, 'name' => 'Members', 'kind' => 'percent', 'value' => 100.0, 'member_day' => true, 'member_days' => array( 1, 2, 3, 4, 5, 6, 7 ) ) );
		m4_shipping_discount( array( 'id' => 2, 'name' => 'Later', 'mode' => 'stages', 'kind' => 'amount', 'start' => time() + 3600, 'end' => time() + 7200, 'stages' => array( array( 'start' => time() + 3600, 'end' => time() + 7200, 'value' => 10.0 ) ) ) );
		return m4_cart( array( array( 101, 1 ) ) );
	},
);
$cases['47 免运费（运费 100% 折扣）+ 指定邮箱的顾客'] = array(
	'expect' => 2998,
	'cart'   => static function () { $GLOBALS['m4']['billing'] = 'vip@example.com'; $GLOBALS['m4']['rates'] = array( array( 'id' => 'plan:std', 'cost' => '15.00' ) ); m4_shipping_discount( array( 'id' => 1, 'name' => 'VIP free', 'kind' => 'percent', 'value' => 100.0, 'customers' => 'emails', 'emails' => array( 'vip@example.com' ) ) ); return m4_cart( array( array( 101, 1 ) ) ); },
);

// ---------- 组合 ----------
$cases['48 加购推荐券（8%，只限商品 103）+ 首单券 + 满减 + 运费折扣'] = array(
	'cart' => static function () use ( $spend ) {
		$spend();
		welcome5();
		m4_coupon( 930, 'ADDON8', array( 'type' => 'percent', 'amount' => '8', 'product_ids' => array( 103 ) ), array( Coupon_Rules::STRATEGY_META => 'stack' ) );
		$GLOBALS['m4']['activity'] = array( 'recommendationCoupon' => array( 'enabled' => true, 'couponId' => 930 ) );
		$GLOBALS['m4']['rates']    = array( array( 'id' => 'plan:std', 'cost' => '15.00' ) );
		m4_shipping_discount( array( 'id' => 1, 'name' => '$4 off', 'kind' => 'amount', 'value' => 4.0 ) );
		return m4_cart( array( array( 101, 2 ), array( 103, 1 ), array( 106, 1 ) ), array( 'WELCOME5', 'ADDON8' ) );
	},
);
$cases['49 100% 优惠码 + 满减 + 运费：满减的负数费用以“商品折后金额 + 运费”为上限'] = array(
	'cart' => static function () {
		m4_promotion( 540, 'Minus 20', promo( 'spend_amount', array( array( 30, 20 ) ) ) );
		m4_coupon( 940, 'FREE', array( 'type' => 'percent', 'amount' => '100' ) );
		$GLOBALS['m4']['rates'] = array( array( 'id' => 'plan:std', 'cost' => '15.00' ) );
		return m4_cart( array( array( 103, 1 ) ), array( 'FREE' ) );
	},
);
$cases['50 两个满减活动合计超过商品金额、没有运费：封顶到 0'] = array(
	'expect' => 0,
	'cart'   => static function () {
		m4_promotion( 541, 'A', promo( 'buy_x_free_y', array( array( 2, 1 ) ), array( 'scope' => 'products', 'products' => array( 105 ) ) ) );
		m4_promotion( 542, 'B', promo( 'qty_percent', array( array( 1, 99 ) ), array( 'scope' => 'products', 'products' => array( 101 ) ) ) );
		m4_coupon( 941, 'ALMOST', array( 'type' => 'fixed_cart', 'amount' => '39' ) );
		return m4_cart( array( array( 105, 2 ), array( 101, 1 ) ), array( 'ALMOST' ) );
	},
);
$cases['51 指定标签的满减活动：有标签的登录顾客才有'] = array(
	'cart' => static function () {
		update_option( Customer_Segments::OPTION, array( 'tags' => array( 'vip' => 'VIP' ), 'levels' => array() ) );
		m4_user( 9, 'nine@example.com', array( Customer_Segments::TAGS => array( 'vip' ) ) );
		m4_promotion( 550, 'VIP only', promo( 'spend_percent', array( array( 10, 20 ) ), array( 'customer_rule' => array( 'customers' => 'tags', 'segment_tags' => array( 'vip' ) ) ) ) );
		m4_promotion( 551, 'Everyone', promo( 'spend_percent', array( array( 10, 5 ) ), array( 'created' => 1 ) ) );
		return m4_cart( array( array( 102, 1 ) ) );
	},
);
$cases['52 离开挽回 / 转盘发的优惠码（指定专辑、每件减 $2）+ 满件打折'] = array(
	'cart' => static function () {
		m4_promotion( 560, 'Qty', promo( 'qty_percent', array( array( 3, 10 ) ) ) );
		m4_coupon( 950, 'COMEBACK', array( 'type' => 'fixed_product', 'amount' => '2', 'cats' => array( 10 ) ) );
		$GLOBALS['m4']['session']['pc_exit_intent_code'] = 'comeback';
		return m4_cart( array( array( 101, 2 ), array( 103, 1 ) ), array( 'COMEBACK' ) );
	},
);

return $cases;
}
