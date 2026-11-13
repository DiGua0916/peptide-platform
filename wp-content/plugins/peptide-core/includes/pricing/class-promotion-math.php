<?php
/**
 * 价格引擎 · 满减活动（M4 / NF-02）。纯计算：不读数据库、不碰购物车对象。
 *
 * 和 Promotions（class-promotions.php）的归属、门槛、优惠金额算法一一对应，运算顺序也保持一致
 * （同样用浮点数、同样的四舍五入位置），所以结果逐分相同。对照见 tests/m4-price-engine-pure.php。
 *
 * 输入的活动是“已发布、有层级、在活动时间内”的活动设置，顺序 = 创建时间最近的在前（Promotions::active_ids()）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

defined( 'ABSPATH' ) || exit;

final class Promotion_Math {

	/** 适用顾客规则（Customer_Segments::matches 的纯计算版）。$email 由调用方给出，各处用的邮箱略有不同。 */
	public static function segment_matches( $rule, array $customer, string $email ): bool {
		$rule = is_array( $rule ) ? $rule : array();
		$mode = $rule['customers'] ?? 'all';
		if ( 'all' === $mode ) {
			return true;
		}
		if ( 'emails' === $mode ) {
			return '' !== $email && in_array( strtolower( trim( $email ) ), (array) ( $rule['emails'] ?? array() ), true );
		}
		if ( 'visitor' === $mode ) {
			$id = (int) ( $customer['visitor_id'] ?? 0 );
			return $id > 0 && in_array( $id, array_map( 'intval', (array) ( $rule['visitor_ids'] ?? array() ) ), true );
		}
		if ( empty( $customer['user_id'] ) ) {
			return false;
		}
		if ( 'tags' === $mode ) {
			return (bool) array_intersect( (array) ( $customer['tags'] ?? array() ), (array) ( $rule['segment_tags'] ?? array() ) );
		}
		$level = (string) ( $customer['level'] ?? '' );
		return 'levels' === $mode && '' !== $level && in_array( $level, (array) ( $rule['segment_levels'] ?? array() ), true );
	}

	/** 门槛是件数（除了满额减元、满额打折）。 */
	public static function by_quantity( array $c ): bool {
		return ! in_array( $c['type'] ?? '', array( 'spend_amount', 'spend_percent' ), true );
	}

	/** 商品行是否在活动范围内（变体按父商品判断）。 */
	public static function covers( array $c, array $line ): bool {
		$id     = $line['product_id'];
		$parent = ! empty( $line['parent_id'] ) ? $line['parent_id'] : $id;
		if ( ! empty( $c['exclude_sale'] ) && ! empty( $line['on_sale'] ) ) {
			return false;
		}
		if ( 'products' === $c['scope'] ) {
			return in_array( $parent, (array) $c['products'], true ) || in_array( $id, (array) $c['products'], true );
		}
		if ( 'categories' === $c['scope'] ) {
			return (bool) array_intersect( (array) ( $line['cats'] ?? array() ), (array) $c['categories'] );
		}
		return true;
	}

	/**
	 * 商品行参加哪个活动：指定商品 > 指定专辑 > 全部商品，同一级别里取创建时间最近的。没有时返回 0。
	 *
	 * @param int[] $ids 候选活动（顺序 = 输入的顺序）。
	 */
	public static function owner( array $line, array $ids, array $promotions, array $customer ): int {
		foreach ( array( 'products', 'categories', 'all' ) as $level ) {
			foreach ( $ids as $id ) {
				$c = $promotions[ $id ];
				if ( $c['scope'] === $level && self::segment_matches( $c['customer_rule'] ?? array(), $customer, (string) ( $customer['segment_email'] ?? '' ) ) && self::covers( $c, $line ) ) {
					return (int) $id;
				}
			}
		}
		return 0;
	}

	/**
	 * 这份购物车里可以生效的活动。$skip_code：当作没有用这张优惠码。
	 * 用了“替换满减活动”的优惠码 → 全部不生效；购物车里有任何优惠码 → 只留“可以和优惠码同时使用”的活动。
	 * 这里看的是“购物车里挂着的优惠码”，不管它这一次有没有通过校验（和旧逻辑一致）。
	 *
	 * @return int[]
	 */
	public static function usable_ids( array $input, string $skip_code = '' ): array {
		$codes = array();
		foreach ( $input['coupons'] as $coupon ) {
			if ( (string) $coupon['code'] !== $skip_code ) {
				$codes[] = $coupon;
			}
		}
		foreach ( $codes as $coupon ) {
			if ( 'replace' === ( $coupon['strategy'] ?? 'stack' ) ) {
				return array();
			}
		}
		$out = array();
		foreach ( $input['promotions'] as $id => $c ) {
			if ( ( ! $codes || ! empty( $c['with_coupons'] ) ) && self::segment_matches( $c['customer_rule'] ?? array(), $input['customer'], (string) ( $input['customer']['segment_email'] ?? '' ) ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * 把商品行分给活动（每行只参加一个活动）。
	 *
	 * @return array<int,string[]> 活动 ID => 行 key（按购物车顺序）
	 */
	public static function assign( array $input, array $ids ): array {
		$out = array();
		foreach ( $input['lines'] as $key => $line ) {
			$owner = self::owner( $line, $ids, $input['promotions'], $input['customer'] );
			if ( $owner ) {
				$out[ $owner ][] = (string) $key;
			}
		}
		return $out;
	}

	/**
	 * 一个活动在这些商品行上的情况：小计、件数、达到的层级、优惠金额、下一档。
	 * $subtotals：行 key => 行小计（元，和 WooCommerce 的 line_subtotal 相同）。
	 */
	public static function evaluate( int $id, array $c, array $lines, array $subtotals, array $keys, int $decimals ): array {
		$subtotal = 0.0;
		$qty      = 0;
		$units    = array();
		foreach ( $lines as $key => $line ) {
			if ( ! in_array( (string) $key, $keys, true ) ) {
				continue;
			}
			$amount    = (float) ( $subtotals[ $key ] ?? 0 );
			$n         = (int) $line['qty'];
			$subtotal += $amount;
			$qty      += $n;
			for ( $i = 0; $i < min( $n, 500 ); $i++ ) {
				$units[] = $n ? $amount / $n : 0.0;
			}
		}
		rsort( $units, SORT_NUMERIC );
		$measure = self::by_quantity( $c ) ? (float) $qty : $subtotal;
		$reached = null;
		$next    = null;
		foreach ( $c['tiers'] as $t ) {
			if ( $measure >= $t['min'] ) {
				$reached = $t;
			} elseif ( null === $next ) {
				$next = $t;
			}
		}
		$discount = 0.0;
		if ( $reached && $subtotal > 0 ) {
			$discount = round( min( self::discount_for( $c, $measure, $units ), $subtotal ), $decimals );
		}
		if ( ! empty( $c['unlimited'] ) && $c['tiers'] ) {
			$t    = $c['tiers'][0];
			$next = array( 'min' => $t['min'] * ( floor( $measure / $t['min'] ) + 1 ), 'value' => $t['value'] );
		}
		return array(
			'id'       => $id,
			'config'   => $c,
			'subtotal' => $subtotal,
			'quantity' => $qty,
			'measure'  => $measure,
			'reached'  => $reached,
			'next'     => $next,
			'discount' => $discount,
			'keys'     => $keys,
		);
	}

	/**
	 * 优惠金额（单价从高到低排好）。七种类型的算法见 Promotions::discount_for 的说明。
	 *
	 * @param float[] $units
	 */
	public static function discount_for( array $c, float $measure, array $units ): float {
		$reached = array_values( array_filter( $c['tiers'], static fn( $t ) => $measure >= $t['min'] ) );
		if ( ! $reached ) {
			return 0.0;
		}
		$top    = end( $reached );
		$min    = (float) $top['min'];
		$value  = (float) $top['value'];
		$groups = ! empty( $c['unlimited'] ) ? (int) floor( $measure / $min ) : 1;
		$sum    = array_sum( $units );
		switch ( $c['type'] ) {
			case 'spend_percent':
			case 'qty_percent':
				return $sum * $value / 100;
			case 'spend_amount':
			case 'qty_amount':
				return $value * max( 1, $groups );
			case 'nth_percent':
				$off = 0.0;
				if ( ! empty( $c['unlimited'] ) ) {
					for ( $g = 1; $g <= $groups; $g++ ) {
						$off += ( $units[ (int) ( $g * $min ) - 1 ] ?? 0.0 ) * $value / 100;
					}
					return $off;
				}
				foreach ( $reached as $t ) {
					$off += ( $units[ (int) $t['min'] - 1 ] ?? 0.0 ) * (float) $t['value'] / 100;
				}
				return $off;
			case 'bundle_price':
			case 'buy_x_free_y':
				$off  = 0.0;
				$size = (int) $min;
				for ( $g = 0; $g < $groups; $g++ ) {
					$group = array_slice( $units, $g * $size, $size );
					if ( count( $group ) < $size ) {
						break;
					}
					$off += 'bundle_price' === $c['type'] ? max( 0.0, array_sum( $group ) - $value ) : array_sum( array_slice( $group, -1 * (int) $value ) );
				}
				return $off;
		}
		return 0.0;
	}

	/**
	 * 生效的活动和各自的优惠，优惠多的在前（和 Promotions::discounts 的返回顺序一致）。
	 *
	 * @return array{applied:array[],evals:array[],usable:int[]}
	 */
	public static function discounts( array $input, array $subtotals, string $skip_code = '' ): array {
		$applied = array();
		$evals   = array();
		$usable  = self::usable_ids( $input, $skip_code );
		foreach ( self::assign( $input, $usable ) as $id => $keys ) {
			$e       = self::evaluate( (int) $id, $input['promotions'][ $id ], $input['lines'], $subtotals, $keys, (int) $input['decimals'] );
			$evals[] = $e;
			if ( $e['discount'] > 0 ) {
				$applied[] = array( 'id' => $e['id'], 'discount' => $e['discount'], 'reached' => $e['reached'] );
			}
		}
		usort( $applied, static fn( $a, $b ) => $b['discount'] <=> $a['discount'] );
		return array( 'applied' => $applied, 'evals' => $evals, 'usable' => $usable );
	}
}
