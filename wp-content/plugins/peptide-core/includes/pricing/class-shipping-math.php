<?php
/**
 * 价格引擎 · 运费折扣（M4 / NF-02）。纯计算，对应 Shipping\Discounts::apply：
 * 当前顾客可用的折扣里，每个运费取折扣后最低的那个；运费减到 0 为止；不和别的运费折扣叠加。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

defined( 'ABSPATH' ) || exit;

final class Shipping_Math {

	/** 现在的折扣值；不在活动时间（或阶段之间的空档）、已停用时为 null。 */
	public static function value_now( array $d, int $now ): ?float {
		if ( empty( $d['enabled'] ) || ( ! empty( $d['start'] ) && $now < $d['start'] ) || ( ! empty( $d['end'] ) && $now >= $d['end'] ) ) {
			return null;
		}
		if ( 'fixed' === ( $d['mode'] ?? 'fixed' ) ) {
			return (float) $d['value'];
		}
		foreach ( (array) ( $d['stages'] ?? array() ) as $st ) {
			if ( $now >= $st['start'] && $now < $st['end'] ) {
				return (float) $st['value'];
			}
		}
		return null;
	}

	/**
	 * 当前顾客现在可以用的折扣（会员日、适用顾客）。
	 *
	 * @return array<int,array{0:array,1:float}>
	 */
	public static function applicable( array $discounts, array $customer, int $now, int $weekday ): array {
		$out = array();
		foreach ( $discounts as $d ) {
			$v = self::value_now( $d, $now );
			if ( null === $v ) {
				continue;
			}
			if ( ! empty( $d['member_day'] ) && ( empty( $customer['user_id'] ) || ! in_array( $weekday, (array) ( $d['member_days'] ?? array() ), true ) ) ) {
				continue;
			}
			if ( Promotion_Math::segment_matches( $d, $customer, (string) ( $customer['email'] ?? '' ) ) ) {
				$out[] = array( $d, $v );
			}
		}
		return $out;
	}

	public static function discounted( float $cost, string $kind, float $value, int $decimals ): float {
		$new = 'percent' === $kind ? $cost * ( 1 - $value / 100 ) : $cost - $value;
		return max( 0.0, round( $new, $decimals ) );
	}

	/**
	 * 一个运费用上最划算的折扣之后是多少。
	 *
	 * @return array{cost:float,by:?array}
	 */
	public static function best( float $cost, array $applicable, int $decimals ): array {
		$best = $cost;
		$by   = null;
		if ( $cost > 0 ) {
			foreach ( $applicable as [ $d, $v ] ) {
				$new = self::discounted( $cost, (string) $d['kind'], (float) $v, $decimals );
				if ( $new < $best ) {
					$best = $new;
					$by   = $d;
				}
			}
		}
		return array( 'cost' => $best, 'by' => $by );
	}
}
