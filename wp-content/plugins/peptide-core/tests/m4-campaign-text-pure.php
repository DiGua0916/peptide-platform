<?php
/**
 * HQ-07：只读页面“当前在跑的活动”上的大白话（Pricing\Campaign_Text）。
 *
 * 投手照着这些文字写广告文案，所以每一种活动的条件和优惠都要写对、写清楚；
 * 不能出现顾客的邮箱、访客编号、转盘的中奖概率。
 *
 * 运行：php -n tests/m4-campaign-text-pure.php   不连 WordPress、不连数据库。
 *
 * @package PeptideCore
 */

define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/pricing/class-campaign-text.php';

use PeptideCore\Pricing\Campaign_Text as T;

$checks = 0;
$failed = array();
$check  = static function ( string $label, bool $ok, $got = null ) use ( &$checks, &$failed ): void {
	++$checks;
	if ( ! $ok ) {
		$failed[] = $label;
		echo 'FAIL  ' . $label . ( null !== $got ? '  实际：' . json_encode( $got, JSON_UNESCAPED_UNICODE ) : '' ) . "\n";
	}
};
$promo = static fn( string $type, array $tiers, bool $repeat = false ): array => array(
	'type'      => $type,
	'unlimited' => $repeat,
	'tiers'     => array_map( static fn( $t ) => array( 'min' => $t[0], 'value' => $t[1] ), $tiers ),
);

// ---------- 数字 ----------
$check( '金额：整数不带小数，有零头两位，千位加逗号', '$100' === T::money( 100.0 ) && '$29.98' === T::money( 29.98 ) && '$1,250' === T::money( 1250.0 ) && '€5.50' === T::money( 5.5, '€' ) );
$check( '打折：写出减多少、相当于几折；100% 写免费', '减 10%（相当于 9 折）' === T::percent_off( 10 ) && '减 15%（相当于 8.5 折）' === T::percent_off( 15 ) && '减 12.5%（相当于 8.75 折）' === T::percent_off( 12.5 ) && '免费' === T::percent_off( 100 ) );
$check( '名字太多时只写前 6 个和总数', 'A、B' === T::names( array( 'A', '', 'B' ) ) && 'A、B、C、D、E、F 等 8 个商品' === T::names( array( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H' ), '个商品' ) );

// ---------- 满减活动：7 种类型 ----------
$t = T::promotion_tiers( $promo( 'spend_amount', array( array( 100, 10 ), array( 200, 25 ) ) ) );
$check( '满额减元：每一档一行，条件和优惠分开', array( array( '一单买满 $100', '减 $10' ), array( '一单买满 $200', '减 $25' ) ) === $t, $t );
$t = T::promotion_tiers( $promo( 'spend_amount', array( array( 30, 4 ) ), true ) );
$check( '满额减元上不封顶：每满多少减多少', array( array( '每买满 $30', '减 $4，买得越多减得越多，不封顶' ) ) === $t, $t );
$t = T::promotion_tiers( $promo( 'spend_percent', array( array( 50, 10 ), array( 80, 20 ) ) ) );
$check( '满额打折', array( array( '一单买满 $50', '减 10%（相当于 9 折）' ), array( '一单买满 $80', '减 20%（相当于 8 折）' ) ) === $t, $t );
$t = T::promotion_tiers( $promo( 'qty_amount', array( array( 3, 7 ) ) ) );
$check( '满件减元', array( array( '一单买满 3 件', '减 $7' ) ) === $t, $t );
$t = T::promotion_tiers( $promo( 'qty_amount', array( array( 2, 5 ) ), true ) );
$check( '满件减元上不封顶', array( array( '每买满 2 件', '减 $5，不封顶' ) ) === $t, $t );
$t = T::promotion_tiers( $promo( 'qty_percent', array( array( 4, 25 ) ) ) );
$check( '满件打折', array( array( '一单买满 4 件', '减 25%（相当于 7.5 折）' ) ) === $t, $t );
$t = T::promotion_tiers( $promo( 'nth_percent', array( array( 2, 50 ), array( 3, 100 ) ) ) );
$check( '第 N 件打折：第 2 件半价、第 3 件免费', array( array( '买到第 2 件', '第 2 件减 50%（相当于 5 折）' ), array( '买到第 3 件', '第 3 件免费' ) ) === $t, $t );
$t = T::promotion_tiers( $promo( 'nth_percent', array( array( 2, 50 ) ), true ) );
$check( '每逢第 N 件打折', array( array( '每买到第 2 件（第 2、4、6…件）', '这一件减 50%（相当于 5 折）' ) ) === $t, $t );
$t = T::promotion_tiers( $promo( 'bundle_price', array( array( 3, 50 ) ) ) );
$check( '任选 N 件一口价', array( array( '任选 3 件', '一共只要 $50' ) ) === $t, $t );
$t = T::promotion_tiers( $promo( 'bundle_price', array( array( 2, 25 ) ), true ) );
$check( '每 N 件一口价', array( array( '每任选 2 件', '这 2 件一共只要 $25，可以重复' ) ) === $t, $t );
$t = T::promotion_tiers( $promo( 'buy_x_free_y', array( array( 4, 2 ) ) ) );
$check( '买 X 件、最便宜的 Y 件免费', array( array( '买 4 件', '其中最便宜的 2 件免费' ) ) === $t, $t );
$t = T::promotion_tiers( $promo( 'buy_x_free_y', array( array( 2, 1 ) ), true ) );
$check( '买 X 免 Y 可重复', array( array( '每买 2 件', '其中最便宜的 1 件免费，可以重复' ) ) === $t, $t );
$t = T::promotion_tiers( array( 'type' => '', 'basis' => 'quantity', 'reward' => 'percent', 'tiers' => array( array( 'min' => 2, 'value' => 10 ) ) ) );
$check( '没有类型的旧活动：按满件数 / 打折推出来', array( array( '一单买满 2 件', '减 10%（相当于 9 折）' ) ) === $t, $t );
$check( '能不能和优惠码一起用：两种写法', false !== strpos( T::promotion_stack( array( 'with_coupons' => true ) ), '可以和优惠码一起用' ) && false !== strpos( T::promotion_stack( array( 'with_coupons' => false ) ), '不能和优惠码一起用' ) );

// ---------- 哪些商品、哪些顾客、有效期 ----------
$check( '全店商品', '全店商品' === T::scope( 'all', array(), array() ) );
$check( '指定专辑；排除促销商品', '只有这些专辑里的商品：Peptides、Kits。已经在打折的商品不参加' === T::scope( 'categories', array( 'Peptides', 'Kits' ), array(), true ) );
$check( '指定商品', '只有这些商品：BPC-157' === T::scope( 'products', array(), array( 'BPC-157' ) ) );
$check( '指定的商品都不在售时不写成“全店”', false === strpos( T::scope( 'products', array(), array() ), '全店' ) );
$who = T::who( array( 'customers' => 'emails', 'emails' => array( 'a@example.com', 'b@example.com' ) ) );
$check( '指定顾客：只说人数，不出现邮箱', '只给指定的 2 位顾客（按邮箱），其他人用不了' === $who && false === strpos( $who, '@' ), $who );
$who = T::who( array( 'customers' => 'visitor', 'visitor_ids' => array( 88123, 88124, 88125 ) ) );
$check( '指定访客：只说人数，不出现访客编号', false !== strpos( $who, '3 位访客' ) && false === strpos( $who, '88123' ), $who );
$who = T::who( array( 'customers' => 'tags', 'segment_tags' => array( 'vip', 'gone' ) ), array( 'tags' => array( 'vip' => array( 'label' => 'VIP 老客' ) ) ) );
$check( '按标签：写标签的名字', '只给带这些标签的顾客：VIP 老客、gone' === $who, $who );
$check( '所有顾客', '所有顾客' === T::who( array() ) && '所有顾客' === T::who( array( 'customers' => 'all', 'emails' => array( 'x@example.com' ) ) ) );
$fmt = static fn( int $ts ): string => gmdate( 'Y-m-d H:i', $ts );
$now = 1794000000;
$check( '有效期：长期', '长期有效，没有结束时间' === T::period( 0, 0, $now, $fmt ) && '长期有效，没有结束时间' === T::period( $now - 500, 0, $now, $fmt ) );
$check( '有效期：有结束时间；还没开始的写开始时间', '到 ' . $fmt( $now + 86400 ) . ' 结束' === T::period( $now - 60, $now + 86400, $now, $fmt ) && $fmt( $now + 3600 ) . ' 开始，到 ' . $fmt( $now + 86400 ) . ' 结束' === T::period( $now + 3600, $now + 86400, $now, $fmt ) );

// ---------- 优惠码 ----------
$c = T::coupon( array( 'type' => 'percent', 'amount' => 10, 'strategy' => 'stack' ) );
$check( '按比例的券：没有门槛、减 10%、可以和满减一起用', array( '没有门槛，输入就能用' ) === $c['need'] && array( '商品金额减 10%（相当于 9 折）' ) === $c['get'] && 1 === count( $c['stack'] ) && 0 === strpos( $c['stack'][0], '可以和满减活动一起用' ) && array() === $c['limits'], $c );
$c = T::coupon( array( 'type' => 'fixed_cart', 'amount' => 5, 'min_spend' => 60, 'strategy' => 'no_stack', 'individual' => true, 'per_user' => 1, 'total' => 100, 'used' => 63 ) );
$check( '整单减元：门槛、不叠加、单独使用、次数', array( '一单买满 $60' ) === $c['need'] && array( '整单减 $5' ) === $c['get'] && 0 === strpos( $c['stack'][0], '不能和满减活动一起用' ) && '不能和别的优惠码一起用' === $c['stack'][1] && array( '每位顾客最多用 1 次', '全店一共 100 次，还剩 37 次' ) === $c['limits'], $c );
$c = T::coupon( array( 'type' => 'fixed_product', 'amount' => 3, 'limit_items' => 2, 'min_qty' => 2, 'free_shipping' => true, 'strategy' => 'replace', 'first_order' => true, 'has_bundle' => true, 'has_gift' => true ) );
$check( '每件减元：首单、满件、最多几件、免运费、替换满减；店里有捆绑和赠品时写上那两句', array( '只限第一次下单的新顾客（按账号和邮箱查，以前下过单的用不了）', '一单买满 2 件' ) === $c['need'] && array( '参加的商品每件减 $3，一单最多减 2 件', '另外免运费' ) === $c['get'] && 0 === strpos( $c['stack'][0], '和满减活动二选一' ) && in_array( '购物车里有捆绑组合时，任何优惠码都用不了', $c['stack'], true ) && in_array( '用了优惠码，满额赠品就不送了', $c['stack'], true ), $c );
$c = T::coupon( array( 'type' => 'percent', 'amount' => 5 ) );
$check( '没写叠加策略的券按“可以叠加”（和代码里的默认值一致）', 0 === strpos( $c['stack'][0], '可以和满减活动一起用' ), $c['stack'] );
$c = T::coupon( array( 'type' => 'percent', 'amount' => 5, 'total' => 10, 'used' => 12 ) );
$check( '用完的券：还剩 0 次，不出现负数', array( '全店一共 10 次，还剩 0 次' ) === $c['limits'], $c['limits'] );

// ---------- 运费折扣 ----------
$check( '运费打折 / 减元 / 免运费', '运费减 50%（相当于 5 折）' === T::shipping_get( 'percent', 50 ) && '免运费' === T::shipping_get( 'percent', 100 ) && '运费减 $5（运费不够减时就是免运费）' === T::shipping_get( 'amount', 5 ) );
$check( '会员日：写星期几，乱序和重复的整理好', '只在每周三、周五生效（按店铺时间），其他日子没有这个折扣' === T::member_days( array( 5, 3, 3, 9 ) ) && '' === T::member_days( array() ) );

// ---------- 赠品 / 捆绑 ----------
$g = T::gift( array( 'basis' => 'spend', 'threshold' => 60, 'gift_qty' => 1 ), array( 'BPC-157', 'TB-500' ), 'Bac Water' );
$check( '满额赠品', array( 'need' => '这些商品（BPC-157、TB-500）一单买满 $60', 'get' => '免费送 Bac Water × 1，自动加进购物车' ) === $g, $g );
$g = T::gift( array( 'basis' => 'quantity', 'threshold' => 3, 'gift_qty' => 2 ), array(), 'Bac Water' );
$check( '满件赠品；没选商品时是全店', array( 'need' => '全店商品一单买满 3 件', 'get' => '免费送 Bac Water × 2，自动加进购物车' ) === $g, $g );
$b = T::bundle( array( 'price_type' => 'fixed', 'price_value' => 40 ), array( array( 'label' => 'Peptide', 'qty' => 1, 'choices' => array( 'BPC-157', 'TB-500' ) ), array( 'label' => '', 'qty' => 2, 'choices' => array( 'Bac Water' ) ) ) );
$check( '捆绑一口价：每一组怎么选', array( 'need' => array( 'Peptide：从 BPC-157、TB-500 里选 × 1', 'Bac Water × 2' ), 'get' => '整组一共只要 $40' ) === $b, $b );
$b = T::bundle( array( 'price_type' => 'percent', 'price_value' => 15 ), array() );
$check( '捆绑打折；没有组合明细时也有一句话', array( 'need' => array( '按后台设好的组合一起买' ), 'get' => '整组减 15%（相当于 8.5 折）' ) === $b, $b );
$check( '赠品 / 捆绑和别的优惠的关系', false !== strpos( T::offer_stack( 'gift' ), '一单只送一种赠品' ) && false !== strpos( T::offer_stack( 'bundle' ), '任何优惠码都用不了' ) );

// ---------- 离开挽回 / 转盘 ----------
$check( '弹窗出现的页面', '全站所有页面' === T::pages( array( 'home', 'shop', 'product', 'cart', 'checkout', 'other' ) ) && '首页、购物车' === T::pages( array( 'cart', 'home', 'bogus' ) ) );
$e = T::exit_intent( array( 'type' => 'coupon', 'coupon' => 'comeback10', 'audience' => 'cart', 'pages' => array( 'cart', 'checkout' ), 'frequency_days' => 7 ) );
$check( '弹窗发优惠码：谁会看到、多久一次、发哪张券', '离开挽回弹窗' === $e['kind'] && in_array( '只对购物车里已经有商品的访客弹', $e['need'], true ) && in_array( '出现在：购物车、结账页', $e['need'], true ) && in_array( '同一位访客 7 天内最多弹一次', $e['need'], true ) && false !== strpos( $e['get'][0], 'COMEBACK10' ), $e );
$e = T::exit_intent( array( 'type' => 'wheel', 'audience' => 'new', 'pages' => array( 'home' ), 'frequency_days' => 0, 'prizes' => array( array( 'label' => '10% OFF', 'coupon' => 'save10', 'weight' => 1 ), array( 'label' => 'Try again', 'coupon' => '', 'weight' => 97 ), array( 'label' => '', 'coupon' => 'x', 'weight' => 2 ) ) ) );
$all = implode( ' ', array_merge( $e['need'], $e['get'], array( $e['how'] ) ) );
$check( '转盘：列出奖项和对应的优惠码，没有奖的格子写明', '抽奖转盘' === $e['kind'] && in_array( '10% OFF（中了发优惠码 SAVE10）', $e['get'], true ) && in_array( 'Try again（这一格没有奖）', $e['get'], true ) && in_array( '只对还没下过单的访客弹', $e['need'], true ) && in_array( '同一位访客每次打开浏览器最多弹一次', $e['need'], true ), $e );
$check( '转盘：不显示中奖概率，并提醒文案里不要承诺中奖', false === strpos( $all, '97' ) && false === strpos( $all, '%（' ) && false !== strpos( $all, '不要承诺一定中奖' ), $all );
$e = T::exit_intent( array( 'type' => 'message', 'pages' => array( 'home' ) ) );
$check( '只有提示文字的弹窗：写明不带优惠', array( '只是一段提示文字，不带优惠' ) === $e['get'], $e );

printf( "RESULT %d 项检查：%s\n", $checks, $failed ? count( $failed ) . ' 项不通过' : '全部通过' );
exit( $failed ? 1 : 0 );
