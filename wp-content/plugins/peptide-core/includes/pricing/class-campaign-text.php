<?php
/**
 * 把营销活动的设置写成大白话（HQ-07，给投手看的只读页面用）。
 *
 * 每个活动拆成几项：条件（顾客要做到什么）、优惠（顾客能得到什么）、哪些商品、哪些顾客、怎么用、
 * 能不能和别的优惠一起用。投手照着写广告文案，所以：
 * - 只写顾客能感受到的规则，不出现内部编号、字段名、后台术语；
 * - 不显示顾客的邮箱（指定顾客的活动只说“指定的 N 位顾客”）；
 * - 转盘只列奖项名称，不显示中奖概率。
 *
 * 这个类只做文字，不读数据库：商品名、专辑名、货币符号都由调用方（Campaigns）查好传进来，
 * 所以可以不连 WordPress 直接测试（tests/m4-campaign-text-pure.php）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

defined( 'ABSPATH' ) || exit;

final class Campaign_Text {

	/** 列商品名、专辑名时最多写几个，其余写“等 N 个”。 */
	public const MAX_NAMES = 6;

	// ---------- 数字的写法 ----------

	/** 金额：整数不带小数（$100），有零头保留两位（$29.98）。 */
	public static function money( float $amount, string $symbol = '$' ): string {
		$cents = (int) round( $amount * 100 );
		$text  = 0 === $cents % 100 ? number_format( $cents / 100, 0, '.', ',' ) : number_format( $cents / 100, 2, '.', ',' );
		return $symbol . $text;
	}

	/** 百分比：去掉多余的 0（10、12.5）。 */
	public static function number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/** “减 10%（相当于 9 折）”；100% 写成“免费”。 */
	public static function percent_off( float $percent ): string {
		if ( $percent >= 100 ) {
			return '免费';
		}
		return '减 ' . self::number( $percent ) . '%（相当于 ' . self::number( ( 100 - $percent ) / 10 ) . ' 折）';
	}

	/** 名字列表：“A、B、C”，太多时“A、B、C 等 12 个”。 */
	public static function names( array $names, string $unit = '个' ): string {
		$names = array_values( array_filter( array_map( 'strval', $names ), static fn( $n ) => '' !== trim( $n ) ) );
		if ( count( $names ) <= self::MAX_NAMES ) {
			return implode( '、', $names );
		}
		return implode( '、', array_slice( $names, 0, self::MAX_NAMES ) ) . ' 等 ' . count( $names ) . ' ' . $unit;
	}

	// ---------- 共用的几项 ----------

	/**
	 * 哪些商品。$scope：all / categories / products。
	 *
	 * @param string[] $category_names
	 * @param string[] $product_names
	 */
	public static function scope( string $scope, array $category_names, array $product_names, bool $exclude_sale = false ): string {
		if ( 'categories' === $scope && $category_names ) {
			$text = '只有这些专辑里的商品：' . self::names( $category_names, '个专辑' );
		} elseif ( 'products' === $scope && $product_names ) {
			$text = '只有这些商品：' . self::names( $product_names, '个商品' );
		} elseif ( 'all' === $scope ) {
			$text = '全店商品';
		} else {
			$text = '指定的商品（后台里选的商品现在都不在售）';
		}
		return $text . ( $exclude_sale ? '。已经在打折的商品不参加' : '' );
	}

	/**
	 * 哪些顾客。$rule 是 Customer_Segments 的规则；$labels = [ 'tags' => [代号 => 名称], 'levels' => [...] ]。
	 * 不输出邮箱和访客编号，只说人数。
	 */
	public static function who( array $rule, array $labels = array() ): string {
		$mode = (string) ( $rule['customers'] ?? 'all' );
		$pick = static function ( array $slugs, array $map ): array {
			$out = array();
			foreach ( $slugs as $slug ) {
				$label = $map[ $slug ] ?? '';
				$out[] = is_array( $label ) ? (string) ( $label['label'] ?? $label['name'] ?? $slug ) : ( '' !== (string) $label ? (string) $label : (string) $slug );
			}
			return $out;
		};
		switch ( $mode ) {
			case 'emails':
				return '只给指定的 ' . count( (array) ( $rule['emails'] ?? array() ) ) . ' 位顾客（按邮箱），其他人用不了';
			case 'visitor':
				return '只给指定的 ' . count( (array) ( $rule['visitor_ids'] ?? array() ) ) . ' 位访客，其他人用不了';
			case 'tags':
				return '只给带这些标签的顾客：' . self::names( $pick( (array) ( $rule['segment_tags'] ?? array() ), (array) ( $labels['tags'] ?? array() ) ) );
			case 'levels':
				return '只给这些等级的顾客：' . self::names( $pick( (array) ( $rule['segment_levels'] ?? array() ), (array) ( $labels['levels'] ?? array() ) ) );
		}
		return '所有顾客';
	}

	/** 有效期。$format：把时间戳写成店铺时间的函数。 */
	public static function period( int $start, int $end, int $now, callable $format ): string {
		$text = '';
		if ( $start && $start > $now ) {
			$text = $format( $start ) . ' 开始，';
		}
		return $text . ( $end ? '到 ' . $format( $end ) . ' 结束' : '长期有效，没有结束时间' );
	}

	// ---------- 满减活动 ----------

	/**
	 * 满减活动的每一档：[条件, 优惠]。$c 是 Promotions::config() 的结果。
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function promotion_tiers( array $c, string $symbol = '$' ): array {
		$type   = (string) ( $c['type'] ?? '' );
		$repeat = ! empty( $c['unlimited'] );
		$out    = array();
		foreach ( (array) ( $c['tiers'] ?? array() ) as $tier ) {
			$min   = (float) ( $tier['min'] ?? 0 );
			$value = (float) ( $tier['value'] ?? 0 );
			$count = (int) round( $min );
			switch ( $type ) {
				case 'spend_amount':
					$out[] = $repeat
						? array( '每买满 ' . self::money( $min, $symbol ), '减 ' . self::money( $value, $symbol ) . '，买得越多减得越多，不封顶' )
						: array( '一单买满 ' . self::money( $min, $symbol ), '减 ' . self::money( $value, $symbol ) );
					break;
				case 'spend_percent':
					$out[] = array( '一单买满 ' . self::money( $min, $symbol ), self::percent_off( $value ) );
					break;
				case 'qty_amount':
					$out[] = $repeat
						? array( '每买满 ' . $count . ' 件', '减 ' . self::money( $value, $symbol ) . '，不封顶' )
						: array( '一单买满 ' . $count . ' 件', '减 ' . self::money( $value, $symbol ) );
					break;
				case 'qty_percent':
					$out[] = array( '一单买满 ' . $count . ' 件', self::percent_off( $value ) );
					break;
				case 'nth_percent':
					$off   = $value >= 100 ? '免费' : self::percent_off( $value );
					$out[] = $repeat
						? array( '每买到第 ' . $count . ' 件（第 ' . $count . '、' . ( 2 * $count ) . '、' . ( 3 * $count ) . '…件）', '这一件' . $off )
						: array( '买到第 ' . $count . ' 件', '第 ' . $count . ' 件' . $off );
					break;
				case 'bundle_price':
					$out[] = $repeat
						? array( '每任选 ' . $count . ' 件', '这 ' . $count . ' 件一共只要 ' . self::money( $value, $symbol ) . '，可以重复' )
						: array( '任选 ' . $count . ' 件', '一共只要 ' . self::money( $value, $symbol ) );
					break;
				case 'buy_x_free_y':
					$free  = (int) round( $value );
					$out[] = $repeat
						? array( '每买 ' . $count . ' 件', '其中最便宜的 ' . $free . ' 件免费，可以重复' )
						: array( '买 ' . $count . ' 件', '其中最便宜的 ' . $free . ' 件免费' );
					break;
				default:
					// 旧数据没有类型：按“满金额 / 满件数”和“减金额 / 打折”推。
					$need  = 'quantity' === ( $c['basis'] ?? 'spend' ) ? '一单买满 ' . $count . ' 件' : '一单买满 ' . self::money( $min, $symbol );
					$get   = 'percent' === ( $c['reward'] ?? 'amount' ) ? self::percent_off( $value ) : '减 ' . self::money( $value, $symbol );
					$out[] = array( $need, $get );
			}
		}
		return $out;
	}

	/** 满减活动能不能和优惠码一起用。 */
	public static function promotion_stack( array $c ): string {
		return ! empty( $c['with_coupons'] )
			? '可以和优惠码一起用（有的优惠码自己规定了不能叠加，以那张优惠码的说明为准）'
			: '不能和优惠码一起用：顾客输了优惠码，这个活动就不算';
	}

	// ---------- 优惠码 ----------

	/**
	 * 优惠码。$f 是 Campaigns 从 WC_Coupon 取出来的要素：
	 * type（percent / fixed_cart / fixed_product）、amount、free_shipping、min_spend、min_qty、first_order、
	 * limit_items、per_user、total、used、individual、strategy（stack / no_stack / replace）、
	 * has_bundle / has_gift（店里现在有没有在跑的捆绑 / 赠品，有才写对应的那句）。
	 *
	 * @return array{need:string[],get:string[],stack:string[],limits:string[]}
	 */
	public static function coupon( array $f, string $symbol = '$' ): array {
		$need = array();
		if ( ! empty( $f['first_order'] ) ) {
			$need[] = '只限第一次下单的新顾客（按账号和邮箱查，以前下过单的用不了）';
		}
		if ( (float) ( $f['min_spend'] ?? 0 ) > 0 ) {
			$need[] = '一单买满 ' . self::money( (float) $f['min_spend'], $symbol );
		}
		if ( (int) ( $f['min_qty'] ?? 0 ) > 0 ) {
			$need[] = '一单买满 ' . (int) $f['min_qty'] . ' 件';
		}
		if ( ! $need ) {
			$need[] = '没有门槛，输入就能用';
		}

		$amount = (float) ( $f['amount'] ?? 0 );
		$type   = (string) ( $f['type'] ?? 'percent' );
		$get    = array();
		if ( 'percent' === $type ) {
			$get[] = '商品金额' . self::percent_off( $amount );
		} elseif ( 'fixed_product' === $type ) {
			$limit = (int) ( $f['limit_items'] ?? 0 );
			$get[] = '参加的商品每件减 ' . self::money( $amount, $symbol ) . ( $limit > 0 ? '，一单最多减 ' . $limit . ' 件' : '' );
		} else {
			$get[] = '整单减 ' . self::money( $amount, $symbol );
		}
		if ( ! empty( $f['free_shipping'] ) ) {
			$get[] = '另外免运费';
		}

		$stack = array();
		switch ( (string) ( $f['strategy'] ?? 'stack' ) ) {
			case 'no_stack':
				$stack[] = '不能和满减活动一起用：购物车已经享受满减时，这张优惠码用不了';
				break;
			case 'replace':
				$stack[] = '和满减活动二选一：用了这张优惠码，满减就不算';
				break;
			default:
				$stack[] = '可以和满减活动一起用（个别满减活动自己规定了不能和优惠码叠加，以那个活动的说明为准）';
		}
		if ( ! empty( $f['individual'] ) ) {
			$stack[] = '不能和别的优惠码一起用';
		}
		if ( ! empty( $f['has_bundle'] ) ) {
			$stack[] = '购物车里有捆绑组合时，任何优惠码都用不了';
		}
		if ( ! empty( $f['has_gift'] ) ) {
			$stack[] = '用了优惠码，满额赠品就不送了';
		}

		$limits = array();
		if ( (int) ( $f['per_user'] ?? 0 ) > 0 ) {
			$limits[] = '每位顾客最多用 ' . (int) $f['per_user'] . ' 次';
		}
		if ( (int) ( $f['total'] ?? 0 ) > 0 ) {
			$left     = max( 0, (int) $f['total'] - (int) ( $f['used'] ?? 0 ) );
			$limits[] = '全店一共 ' . (int) $f['total'] . ' 次，还剩 ' . $left . ' 次';
		}
		return array( 'need' => $need, 'get' => $get, 'stack' => $stack, 'limits' => $limits );
	}

	// ---------- 运费折扣 ----------

	/** 运费折扣现在这一档：“运费减 50%”/“运费减 $5”。 */
	public static function shipping_get( string $kind, float $value, string $symbol = '$' ): string {
		if ( 'percent' === $kind ) {
			return $value >= 100 ? '免运费' : '运费' . self::percent_off( $value );
		}
		return '运费减 ' . self::money( $value, $symbol ) . '（运费不够减时就是免运费）';
	}

	/** 会员日：“只在每周三、周五生效（按店铺时间）”。$days：ISO 星期，1 = 周一。 */
	public static function member_days( array $days ): string {
		$names = array( 1 => '周一', 2 => '周二', 3 => '周三', 4 => '周四', 5 => '周五', 6 => '周六', 7 => '周日' );
		$days  = array_values( array_unique( array_filter( array_map( 'intval', $days ), static fn( $d ) => isset( $names[ $d ] ) ) ) );
		sort( $days );
		return $days ? '只在每' . implode( '、', array_map( static fn( $d ) => $names[ $d ], $days ) ) . '生效（按店铺时间），其他日子没有这个折扣' : '';
	}

	// ---------- 赠品 / 捆绑 ----------

	/**
	 * 赠品。$offer 是 Product_Offers::get() 的结果；$qualifier_names 是算门槛的商品名，$gift_name 是赠品名。
	 *
	 * @return array{need:string,get:string}
	 */
	public static function gift( array $offer, array $qualifier_names, string $gift_name, string $symbol = '$' ): array {
		$which = $qualifier_names ? '这些商品（' . self::names( $qualifier_names, '个商品' ) . '）' : '全店商品';
		$need  = 'quantity' === ( $offer['basis'] ?? 'spend' )
			? $which . '一单买满 ' . (int) round( (float) ( $offer['threshold'] ?? 0 ) ) . ' 件'
			: $which . '一单买满 ' . self::money( (float) ( $offer['threshold'] ?? 0 ), $symbol );
		$qty   = max( 1, (int) ( $offer['gift_qty'] ?? 1 ) );
		return array(
			'need' => $need,
			'get'  => '免费送 ' . ( '' !== $gift_name ? $gift_name : '一件赠品' ) . ' × ' . $qty . '，自动加进购物车',
		);
	}

	/**
	 * 捆绑。$slots：[ [ 'label' => 组名, 'qty' => 件数, 'choices' => [商品名…] ] … ]。
	 *
	 * @return array{need:string[],get:string}
	 */
	public static function bundle( array $offer, array $slots, string $symbol = '$' ): array {
		$need = array();
		foreach ( $slots as $slot ) {
			$choices = array_values( array_filter( array_map( 'strval', (array) ( $slot['choices'] ?? array() ) ) ) );
			$qty     = max( 1, (int) ( $slot['qty'] ?? 1 ) );
			$label   = trim( (string) ( $slot['label'] ?? '' ) );
			$pick    = count( $choices ) > 1 ? '从 ' . self::names( $choices, '个商品' ) . ' 里选' : ( $choices[0] ?? '指定商品' );
			$need[]  = ( '' !== $label ? $label . '：' : '' ) . $pick . ' × ' . $qty;
		}
		$value = (float) ( $offer['price_value'] ?? 0 );
		$get   = 'fixed' === ( $offer['price_type'] ?? 'fixed' )
			? '整组一共只要 ' . self::money( $value, $symbol )
			: '整组' . self::percent_off( $value );
		return array( 'need' => $need ? $need : array( '按后台设好的组合一起买' ), 'get' => $get );
	}

	/** 赠品 / 捆绑和别的优惠的关系（代码里固定的规则，后台不能改）。 */
	public static function offer_stack( string $kind ): string {
		return 'bundle' === $kind
			? '不和别的优惠叠加：购物车里有这个组合时，任何优惠码都用不了，组合里的商品也不参加满减，也不再送赠品'
			: '不和别的优惠叠加：顾客用了优惠码，或买的商品正在参加满减活动，就不送；一单只送一种赠品';
	}

	// ---------- 离开挽回 / 转盘 ----------

	/** 弹窗出现在哪些页面。 */
	public static function pages( array $pages ): string {
		$names = array( 'home' => '首页', 'shop' => '商品列表', 'product' => '商品页', 'cart' => '购物车', 'checkout' => '结账页', 'other' => '其他页面' );
		$known = array_values( array_intersect( array_keys( $names ), array_map( 'strval', $pages ) ) );
		if ( count( $known ) === count( $names ) ) {
			return '全站所有页面';
		}
		return implode( '、', array_map( static fn( $p ) => $names[ $p ], $known ) );
	}

	/**
	 * 离开挽回弹窗。$s 是 Exit_Intent::settings() 的结果。
	 *
	 * @return array{kind:string,need:string[],get:string[],how:string}
	 */
	public static function exit_intent( array $s ): array {
		$need = array( '访客要离开页面时弹出（电脑上是鼠标移出页面，手机上是按返回键、往回滑等）' );
		$aud  = (string) ( $s['audience'] ?? 'all' );
		if ( 'cart' === $aud ) {
			$need[] = '只对购物车里已经有商品的访客弹';
		} elseif ( 'new' === $aud ) {
			$need[] = '只对还没下过单的访客弹';
		}
		$where = self::pages( (array) ( $s['pages'] ?? array() ) );
		if ( '' !== $where ) {
			$need[] = '出现在：' . $where;
		}
		$days   = (int) ( $s['frequency_days'] ?? 1 );
		$need[] = $days > 0 ? '同一位访客 ' . $days . ' 天内最多弹一次' : '同一位访客每次打开浏览器最多弹一次';

		$type = (string) ( $s['type'] ?? 'coupon' );
		if ( 'wheel' === $type ) {
			$prizes = array();
			foreach ( (array) ( $s['prizes'] ?? array() ) as $prize ) {
				$label = trim( (string) ( $prize['label'] ?? '' ) );
				if ( '' === $label ) {
					continue;
				}
				$code     = strtoupper( trim( (string) ( $prize['coupon'] ?? '' ) ) );
				$prizes[] = '' !== $code ? $label . '（中了发优惠码 ' . $code . '）' : $label . '（这一格没有奖）';
			}
			return array(
				'kind' => '抽奖转盘',
				'need' => $need,
				'get'  => array_merge( array( '每位访客抽一次，转到哪一格就得哪一格：' ), $prizes, array( '中奖概率在后台设置，这里不显示；文案里不要承诺一定中奖' ) ),
				'how'  => '访客在弹窗里点一下转盘；中奖的优惠码自动用到购物车',
			);
		}
		if ( 'message' === $type ) {
			return array(
				'kind' => '离开挽回弹窗',
				'need' => $need,
				'get'  => array( '只是一段提示文字，不带优惠' ),
				'how'  => '访客看到弹窗后自己决定要不要继续逛',
			);
		}
		$code = strtoupper( trim( (string) ( $s['coupon'] ?? '' ) ) );
		return array(
			'kind' => '离开挽回弹窗',
			'need' => $need,
			'get'  => array( '发一张优惠码' . ( '' !== $code ? ' ' . $code : '' ) . '；能减多少、有什么条件，看本页里同名的那张优惠码' ),
			'how'  => '访客在弹窗里点一下按钮，优惠码自动用到购物车',
		);
	}
}
