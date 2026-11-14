<?php
/**
 * 价格引擎 · 优惠码（M4 / NF-02）。纯计算。
 *
 * 两部分：
 * 1. 这张优惠码这一次能不能用（invalid_reason）：WooCommerce 原生的检查（WC_Discounts::is_coupon_valid），
 *    加上本插件挂在 woocommerce_coupon_is_valid 上的四处检查——
 *    Coupon_Rules::validate（开始时间、适用顾客、满金额 / 满件数、全局首单、不叠加满减）、
 *    Loyalty::validate_coupon（积分券只能本人用、未退回、未关联订单）、
 *    First_Order_Offer::validate_stack（首单券不能顶掉别的优惠）、
 *    Product_Offers::coupon_valid（购物车里有捆绑时不能用优惠码）。
 * 2. 能用的优惠码各减多少（apply）：照 WooCommerce 的 WC_Discounts 逐行计算（单位：分，浮点数，和原生一致）。
 *
 * 需要查数据库才知道的事实（用过几次、有没有下过单、积分流水）由读取购物车的一方放进输入里。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

defined( 'ABSPATH' ) || exit;

final class Coupon_Math {

	/** WooCommerce 的商品型 / 购物车型优惠码（wc_get_product_coupon_types / wc_get_cart_coupon_types 的默认值）。 */
	private const PRODUCT_TYPES = array( 'fixed_product', 'percent' );
	private const CART_TYPES    = array( 'fixed_cart' );

	/** 货币小数位（apply() 运行期间）。 */
	private static int $decimals = 2;

	/** 输入里一张优惠码的默认值。 */
	public static function defaults(): array {
		return array(
			'code'           => '',
			'id'             => 0,
			'virtual'        => false,
			'status'         => 'publish',
			'type'           => 'fixed_cart',
			'amount'         => 0.0,
			'limit_x'        => null,   // 最多用于几件商品（limit_usage_to_x_items）
			'individual_use' => false,
			'free_shipping'  => false,
			'product_ids'    => array(),
			'excluded_ids'   => array(),
			'cats'           => array(),
			'excluded_cats'  => array(),
			'exclude_sale'   => false,
			'min'            => 0.0,
			'max'            => 0.0,
			'expires'        => 0,
			'usage_limit'    => 0,
			'usage_count'    => 0,
			'tentative'      => 0,
			'limit_per_user' => 0,
			'used_by_user'   => 0,
			'emails'         => array(),
			// 本插件的规则（Coupon_Rules）
			'strategy'       => 'stack', // no_stack / stack / replace
			'start'          => 0,
			'first_order'    => false,
			'min_spend'      => 0.0,
			'min_qty'        => 0,
			'customer_rule'  => null,
			// 来源：coupon / loyalty / first_order / recommendation / exit_intent
			'origin'         => 'coupon',
			'loyalty'        => null,    // 积分券：[user, ledger_ok, linked]
			'first_offer'    => null,    // 首单券：[issue]（空字符串 = 设置合规）
		);
	}

	/** WC_Coupon::is_valid_for_product，加上 Product_Offers::coupon_product_valid（赠品 / 捆绑行不参加商品型优惠码）。 */
	public static function valid_for_product( array $coupon, array $line ): bool {
		if ( ! in_array( $coupon['type'], self::PRODUCT_TYPES, true ) || ! empty( $line['offer'] ) ) {
			return false;
		}
		$ids   = array( $line['product_id'], $line['parent_id'] ?? 0 );
		$cats  = (array) ( $line['cats'] ?? array() );
		$valid = false;
		if ( $coupon['product_ids'] && array_intersect( $ids, $coupon['product_ids'] ) ) {
			$valid = true;
		}
		if ( $coupon['cats'] && array_intersect( $cats, $coupon['cats'] ) ) {
			$valid = true;
		}
		if ( ! $coupon['product_ids'] && ! $coupon['cats'] ) {
			$valid = true;
		}
		if ( $coupon['excluded_ids'] && array_intersect( $ids, $coupon['excluded_ids'] ) ) {
			$valid = false;
		}
		if ( $coupon['excluded_cats'] && array_intersect( $cats, $coupon['excluded_cats'] ) ) {
			$valid = false;
		}
		if ( $coupon['exclude_sale'] && ! empty( $line['on_sale'] ) ) {
			$valid = false;
		}
		return $valid;
	}

	/** Coupon_Rules::covers：按“满金额 / 满件数”算适用商品时用的范围。 */
	public static function rule_covers( array $coupon, array $line ): bool {
		$id     = $line['product_id'];
		$parent = ! empty( $line['parent_id'] ) ? $line['parent_id'] : $id;
		$cats   = (array) ( $line['cats'] ?? array() );
		if ( $coupon['product_ids'] && ! array_intersect( array( $id, $parent ), $coupon['product_ids'] ) ) {
			return false;
		}
		if ( $coupon['cats'] && ! array_intersect( $cats, $coupon['cats'] ) ) {
			return false;
		}
		if ( array_intersect( array( $id, $parent ), $coupon['excluded_ids'] ) || array_intersect( $cats, $coupon['excluded_cats'] ) ) {
			return false;
		}
		return ! ( $coupon['exclude_sale'] && ! empty( $line['on_sale'] ) );
	}

	/** DiscountsUtil::is_coupon_emails_allowed（支持 *@example.com 这样的通配）。 */
	private static function emails_allowed( array $check, array $restrictions ): bool {
		foreach ( $check as $email ) {
			if ( in_array( $email, $restrictions, true ) ) {
				return true;
			}
			foreach ( $restrictions as $restriction ) {
				if ( preg_match( '/^' . str_replace( '*', '(.+)?', (string) $restriction ) . '$/', $email ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * 这张优惠码这一次不能用的原因；能用时返回空字符串。检查顺序和线上一致，返回第一个不满足的。
	 *
	 * @param array $input     引擎输入。
	 * @param array $subtotals 行 key => 行小计（元）。
	 * @param float $subtotal  购物车小计（元），WooCommerce 检查最低 / 最高消费用的数。
	 */
	public static function invalid_reason( array $coupon, array $input, array $subtotals, float $subtotal ): string {
		$lines    = $input['lines'];
		$customer = $input['customer'];
		$now      = (int) $input['now'];

		// ---- WooCommerce 原生 ----
		if ( ( ! $coupon['id'] && ! $coupon['virtual'] ) || 'trash' === $coupon['status'] ) {
			return 'not_found';
		}
		if ( $coupon['usage_limit'] && $coupon['usage_count'] + $coupon['tentative'] >= $coupon['usage_limit'] ) {
			return 'usage_limit';
		}
		if ( ! empty( $customer['user_id'] ) && $coupon['limit_per_user'] > 0 && $coupon['id'] && $coupon['used_by_user'] >= $coupon['limit_per_user'] ) {
			return 'user_usage_limit';
		}
		if ( $coupon['expires'] && $now > $coupon['expires'] ) {
			return 'expired';
		}
		if ( $coupon['min'] > 0 && $coupon['min'] > $subtotal ) {
			return 'minimum_amount';
		}
		if ( $coupon['max'] > 0 && $coupon['max'] < $subtotal ) {
			return 'maximum_amount';
		}
		if ( $coupon['product_ids'] ) {
			$found = false;
			foreach ( $lines as $line ) {
				if ( in_array( $line['product_id'], $coupon['product_ids'], true ) || in_array( $line['parent_id'] ?? 0, $coupon['product_ids'], true ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return 'product_ids';
			}
		}
		if ( $coupon['cats'] ) {
			$found = false;
			foreach ( $lines as $line ) {
				if ( $coupon['exclude_sale'] && ! empty( $line['on_sale'] ) ) {
					continue;
				}
				if ( array_intersect( (array) ( $line['cats'] ?? array() ), $coupon['cats'] ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return 'product_categories';
			}
		}
		$product_type = in_array( $coupon['type'], self::PRODUCT_TYPES, true );
		if ( $lines && $product_type ) {
			$found = false;
			foreach ( $lines as $line ) {
				if ( self::valid_for_product( $coupon, $line ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return 'no_eligible_items';
			}
		}
		if ( ! $product_type ) {
			foreach ( $lines as $line ) {
				if ( $coupon['exclude_sale'] && ! empty( $line['on_sale'] ) ) {
					return 'sale_items';
				}
			}
			foreach ( $lines as $line ) {
				if ( $coupon['excluded_ids'] && ( in_array( $line['product_id'], $coupon['excluded_ids'], true ) || in_array( $line['parent_id'] ?? 0, $coupon['excluded_ids'], true ) ) ) {
					return 'excluded_product_ids';
				}
			}
			foreach ( $lines as $line ) {
				if ( $coupon['excluded_cats'] && array_intersect( (array) ( $line['cats'] ?? array() ), $coupon['excluded_cats'] ) ) {
					return 'excluded_product_categories';
				}
			}
		}
		if ( $coupon['emails'] ) {
			$check = array_unique( array_filter( array_map( 'strtolower', array( (string) ( $customer['account_email'] ?? '' ), (string) ( $customer['billing_email'] ?? '' ) ) ) ) );
			if ( ! self::emails_allowed( $check, $coupon['emails'] ) ) {
				return 'not_yours';
			}
		}

		// ---- Coupon_Rules::validate（优先级 10）----
		if ( $coupon['id'] ) {
			if ( $coupon['start'] && $now < $coupon['start'] ) {
				return 'not_started';
			}
			if ( is_array( $coupon['customer_rule'] ) && ! Promotion_Math::segment_matches( $coupon['customer_rule'], $customer, (string) ( $customer['segment_email'] ?? '' ) ) ) {
				return 'customer_group';
			}
			$qty   = 0;
			$spend = 0.0;
			foreach ( $lines as $key => $line ) {
				if ( self::rule_covers( $coupon, $line ) ) {
					$qty   += (int) $line['qty'];
					$spend += (float) ( $subtotals[ $key ] ?? 0 );
				}
			}
			if ( $coupon['min_qty'] > 0 && $qty < $coupon['min_qty'] ) {
				return 'min_qty';
			}
			if ( $coupon['min_spend'] > 0 && $spend + 0.0001 < $coupon['min_spend'] ) {
				return 'min_spend';
			}
			if ( $coupon['first_order'] && ! empty( $customer['has_ordered'] ) ) {
				return 'first_order_only';
			}
			if ( 'no_stack' === $coupon['strategy'] && Promotion_Math::discounts( $input, $subtotals, (string) $coupon['code'] )['applied'] ) {
				return 'no_stack_with_promotion';
			}
		}

		// ---- Loyalty::validate_coupon（优先级 20）----
		if ( is_array( $coupon['loyalty'] ) ) {
			$l = $coupon['loyalty'];
			if ( empty( $l['ledger_ok'] ) || (int) ( $l['user'] ?? 0 ) !== (int) ( $customer['user_id'] ?? 0 ) ) {
				return 'points_coupon_unavailable';
			}
			if ( ! empty( $l['linked'] ) ) {
				return 'points_coupon_assigned';
			}
		}

		// ---- Product_Offers::coupon_valid（优先级 30，注册在前）----
		foreach ( $lines as $line ) {
			if ( ! empty( $line['offer'] ) && 'bundle' === ( $line['offer']['kind'] ?? '' ) ) {
				return 'bundle_in_cart';
			}
		}

		// ---- First_Order_Offer::validate_stack（优先级 30）----
		if ( is_array( $coupon['first_offer'] ) ) {
			if ( '' !== (string) ( $coupon['first_offer']['issue'] ?? '' ) ) {
				return 'first_offer_unavailable';
			}
			foreach ( $input['coupons'] as $other ) {
				if ( (string) $other['code'] !== (string) $coupon['code'] && ! empty( $other['individual_use'] ) ) {
					return 'first_offer_conflict';
				}
			}
			foreach ( Promotion_Math::discounts( $input, $subtotals, (string) $coupon['code'] )['applied'] as $promotion ) {
				if ( empty( $input['promotions'][ $promotion['id'] ]['with_coupons'] ) ) {
					return 'first_offer_conflict';
				}
			}
		}
		return '';
	}

	/**
	 * WooCommerce 计算时的优惠码顺序（WC_Cart_Totals::sort_coupons_callback）：每件减 → 按比例 → 整单减；
	 * 同类里，开了“依次计算优惠码”且不止一张时按添加顺序，否则金额小的在前、再按 ID 大的在前。
	 */
	public static function sort( array $coupons, bool $sequential = false ): array {
		$rank     = array( 'fixed_product' => 1, 'percent' => 2, 'fixed_cart' => 3 );
		$position = $sequential && count( $coupons ) > 1 ? array_flip( array_keys( $coupons ) ) : array();
		uasort(
			$coupons,
			static function ( $a, $b ) use ( $rank, $position ) {
				$sa = $rank[ $a['type'] ] ?? 0;
				$sb = $rank[ $b['type'] ] ?? 0;
				if ( $sa === $sb ) {
					if ( $a['limit_x'] === $b['limit_x'] ) {
						$pa = $position[ (string) $a['code'] ] ?? null;
						$pb = $position[ (string) $b['code'] ] ?? null;
						if ( null !== $pa && null !== $pb && $pa !== $pb ) {
							return $pa <=> $pb;
						}
						if ( $a['amount'] === $b['amount'] ) {
							return $b['id'] - $a['id'];
						}
						return ( $a['amount'] < $b['amount'] ) ? -1 : 1;
					}
					return ( $a['limit_x'] < $b['limit_x'] ) ? -1 : 1;
				}
				return ( $sa < $sb ) ? -1 : 1;
			}
		);
		return $coupons;
	}

	/**
	 * 把通过校验的优惠码依次用到商品行上（WC_Discounts 的算法）。
	 *
	 * @param array $items   行 key => [key, price（分，行金额）, qty, line（输入里的商品行）]，购物车顺序。
	 * @param array $coupons 已按 sort() 排好、已通过校验的优惠码。
	 * @return array<string,array<string,float>> 优惠码 => 行 key => 优惠（分）
	 */
	public static function apply( array $items, array $coupons, bool $sequential, int $decimals = 2 ): array {
		self::$decimals = $decimals;
		// WC_Discounts::set_items：按单价从高到低（相同时保持购物车顺序）。
		uasort(
			$items,
			static function ( $a, $b ) {
				$p1 = $a['qty'] > 1 ? $a['price'] / $a['qty'] : $a['price'];
				$p2 = $b['qty'] > 1 ? $b['price'] / $b['qty'] : $b['price'];
				if ( $p1 === $p2 ) {
					return 0;
				}
				return ( $p1 < $p2 ) ? 1 : -1;
			}
		);
		$d = array();
		foreach ( $coupons as $coupon ) {
			$code = (string) $coupon['code'];
			if ( ! isset( $d[ $code ] ) ) {
				$d[ $code ] = array_fill_keys( array_keys( $items ), 0 );
			}
			$apply = array();
			foreach ( $items as $item ) {
				if ( 0 === self::left( $item, $d ) || 0 >= $item['qty'] ) {
					continue;
				}
				if ( ! self::valid_for_product( $coupon, $item['line'] ) && ! in_array( $coupon['type'], self::CART_TYPES, true ) ) {
					continue;
				}
				$apply[] = $item;
			}
			switch ( $coupon['type'] ) {
				case 'percent':
					self::percent( $coupon, $apply, $d, $sequential );
					break;
				case 'fixed_product':
					self::fixed_product( $coupon, $apply, $d, null );
					break;
				case 'fixed_cart':
					self::fixed_cart( $coupon, $apply, $d, null );
					break;
			}
		}
		return $d;
	}

	/** 这一行还剩多少可以减（分，整数）：WC_Discounts::get_discounted_price_in_cents。 */
	private static function left( array $item, array $d ): int {
		$used = 0;
		foreach ( $d as $by_item ) {
			$used += $by_item[ $item['key'] ] ?? 0;
		}
		return (int) abs( Engine::round( $item['price'] - $used ) );
	}

	/** wc_add_number_precision：元 → 分（保留 4 位小数的分）。 */
	private static function cents( float $amount ): float {
		return Engine::cents( $amount, self::$decimals );
	}

	private static function percent( array $coupon, array $apply, array &$d, bool $sequential ): float {
		$total      = 0;
		$cart_total = 0;
		$limit      = null !== $coupon['limit_x'] ? $coupon['limit_x'] : 0;
		$applied    = 0;
		$amount     = (float) $coupon['amount'];
		$code       = (string) $coupon['code'];
		foreach ( $apply as $item ) {
			$left     = self::left( $item, $d );
			$price    = $sequential ? $left : Engine::round( $item['price'] );
			$qty      = $limit && ( $limit - $applied ) < $item['qty'] ? $limit - $applied : $item['qty'];
			$qty      = max( 0, $qty );
			$price    = ( $price / $item['qty'] ) * $qty;
			$discount = floor( $price * ( $amount / 100 ) );
			$discount = Engine::round( min( $left, $discount ), 0, PHP_ROUND_HALF_DOWN );
			$cart_total += $price;
			$total      += $discount;
			$applied    += $qty;
			$d[ $code ][ $item['key'] ] += $discount;
		}
		$cart_discount = Engine::round( $cart_total * ( $amount / 100 ), 0, PHP_ROUND_HALF_DOWN );
		if ( $total < $cart_discount ) {
			$total += self::remainder( $coupon, $apply, $d, $cart_discount - $total );
		}
		return $total;
	}

	private static function fixed_product( array $coupon, array $apply, array &$d, $amount ): float {
		$total   = 0;
		$amount  = $amount ? $amount : self::cents( (float) $coupon['amount'] );
		$limit   = null !== $coupon['limit_x'] ? $coupon['limit_x'] : 0;
		$applied = 0;
		$code    = (string) $coupon['code'];
		foreach ( $apply as $item ) {
			$left = self::left( $item, $d );
			if ( $limit ) {
				$qty      = $limit - $applied < $item['qty'] ? $limit - $applied : $item['qty'];
				$qty      = max( 0, $qty );
				$discount = min( $amount, $item['price'] / $item['qty'] ) * $qty;
			} else {
				$qty      = $item['qty'];
				$discount = $amount * $qty;
			}
			$discount = min( $left, $discount );
			$total   += $discount;
			$applied += $qty;
			$d[ $code ][ $item['key'] ] += $discount;
		}
		return $total;
	}

	private static function fixed_cart( array $coupon, array $apply, array &$d, $amount ): float {
		$total  = 0;
		$amount = $amount ? $amount : self::cents( (float) $coupon['amount'] );
		$apply  = array_filter( $apply, static fn( $item ) => self::left( $item, $d ) > 0 );
		$count  = array_sum( array_column( $apply, 'qty' ) );
		if ( ! $count ) {
			return $total;
		}
		if ( ! $amount ) {
			return self::fixed_product( $coupon, $apply, $d, 0 );
		}
		$per_item = (int) abs( $amount / $count ); // 向下取整到分
		if ( $per_item > 0 ) {
			$total = self::fixed_product( $coupon, $apply, $d, $per_item );
			if ( $total > 0 && $total < $amount ) {
				$total += self::fixed_cart( $coupon, $apply, $d, $amount - $total );
			}
		} elseif ( $amount > 0 ) {
			$total += self::remainder( $coupon, $apply, $d, $amount );
		}
		return $total;
	}

	/** 剩下的零头一分一分地分给商品行。 */
	private static function remainder( array $coupon, array $apply, array &$d, $amount ): float {
		$total = 0;
		$code  = (string) $coupon['code'];
		foreach ( $apply as $item ) {
			$n = ceil( is_float( $item['qty'] ) ? round( $item['qty'], 6 ) : $item['qty'] );
			for ( $i = 0; $i < $n; $i++ ) {
				$discount = min( self::left( $item, $d ), 1 );
				$total   += $discount;
				$d[ $code ][ $item['key'] ] += $discount;
				if ( $total >= $amount ) {
					break 2;
				}
			}
			if ( $total >= $amount ) {
				break;
			}
		}
		return $total;
	}
}
