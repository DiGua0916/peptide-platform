<?php
/**
 * 统一价格引擎（M4 / NF-02）。输入一份购物车，输出“优惠明细”和最终总价。纯计算，不读写数据库和购物车。
 *
 * 七处优惠在这里按线上实际的先后顺序走一遍：
 *   1. 赠品 / 捆绑（Offer_Math）      → 决定每个商品行的单价；
 *   2. 优惠码（Coupon_Math）          → 普通优惠码、首单券、积分券、加购推荐券、离开挽回 / 转盘发的券，
 *                                        先判断能不能用（含“不叠加 / 替换满减”等规则），再按 WooCommerce 的算法逐行减；
 *   3. 运费折扣（Shipping_Math）      → 每个运费取折后最低；
 *   4. 满减活动（Promotion_Math）     → 每个生效的活动一笔负数费用，合计不超过“商品折后金额 + 运费”；
 *   5. 合计。
 *
 * 税：引擎不算税。启用税费且价格不含税时，税额由 WooCommerce 算好后原样带入（tax_cents）；价格含税时引擎不支持（supported=false）。
 *
 * 输入的格式见 defaults()；从 WooCommerce 购物车生成输入用 Cart_Reader::read()。
 * 金额：输入用“元”（和 WooCommerce 一样的浮点数），输出一律是“分”。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

defined( 'ABSPATH' ) || exit;

final class Engine {

	public const VERSION = 1;

	/** 输入的默认值。 */
	public static function defaults(): array {
		return array(
			'now'               => 0,
			'decimals'          => 2,
			'sequential'        => false, // WooCommerce“依次计算优惠码”
			'round_at_subtotal' => false, // WooCommerce“在小计层面四舍五入”
			'unsupported'       => array(), // 读取购物车时发现的、引擎算不了的情况
			'tax_cents'         => 0.0,   // 由 WooCommerce 算好的税额（价格不含税时），原样带入
			'customer'          => array(
				'user_id'       => 0,
				'email'         => '',  // 结账邮箱，没有时用账户邮箱（小写、去空格）
				'segment_email' => '',  // Customer_Segments::current_email()
				'account_email' => '',
				'billing_email' => '',
				'has_ordered'   => false,
				'tags'          => array(),
				'level'         => '',
				'visitor_id'    => 0,
			),
			// 行 key => [product_id, parent_id, cats, on_sale, qty, price（原价单价）, sellable, offer（赠品 / 捆绑标记或 null）]
			'lines'             => array(),
			// 已挂在购物车上的优惠码（Coupon_Math::defaults()），按添加顺序
			'coupons'           => array(),
			// 进行中的满减活动：ID => 设置，创建时间最近的在前
			'promotions'        => array(),
			// 赠品 / 捆绑活动：ID => 设置 + active / version / gift_* 事实，ID 大的在前
			'offers'            => array(),
			'offer_groups'      => array(), // 捆绑组 => [stock_ok]
			// 手动订单链接（Manual_Orders）：null = 普通购物车；否则 [store_promos, promo_ids, discounts, first_order, first_code]
			'manual'            => null,
			'shipping'          => array(
				'rates'     => array(), // 已选的运费：[[id, cost（折扣前）]]
				'discounts' => array(), // 运费折扣，新建的在前
				'weekday'   => 1,       // 店铺时区的星期几（1–7）
			),
		);
	}

	private static function normalize( array $input ): array {
		$d                 = self::defaults();
		$input             = array_merge( $d, $input );
		$input['customer'] = array_merge( $d['customer'], (array) $input['customer'] );
		$input['shipping'] = array_merge( $d['shipping'], (array) $input['shipping'] );
		$coupons           = array();
		foreach ( (array) $input['coupons'] as $coupon ) {
			$coupon                              = array_merge( Coupon_Math::defaults(), (array) $coupon );
			$coupons[ (string) $coupon['code'] ] = $coupon;
		}
		$input['coupons'] = $coupons;
		foreach ( $input['lines'] as $key => $line ) {
			$input['lines'][ $key ] = array_merge( array( 'product_id' => 0, 'parent_id' => 0, 'cats' => array(), 'on_sale' => false, 'qty' => 0, 'price' => 0.0, 'sellable' => true, 'offer' => null ), (array) $line );
		}
		return $input;
	}

	/** WooCommerce 的四舍五入（NumberUtil::round）：浮点数先归整到 6 位小数，再按要求的位数舍入。 */
	public static function round( $value, int $precision = 0, int $mode = PHP_ROUND_HALF_UP ): float {
		return round( is_float( $value ) ? round( $value, 6 ) : $value, $precision, $mode );
	}

	/** 元 → 分（wc_add_number_precision：先乘再四舍五入到 4 位小数的分）。 */
	public static function cents( float $amount, int $decimals ): float {
		return $amount ? self::round( $amount * ( 10 ** $decimals ), max( 6, $decimals + 2 ) - $decimals ) : 0.0;
	}

	/**
	 * 计算。
	 *
	 * @return array{
	 *   supported:bool, unsupported:string[], entries:array[], rejected:array[], actions:array[],
	 *   lines:array, coupons:array, promotions:array, shipping:array, totals:array
	 * }
	 */
	public static function price( array $input ): array {
		$input    = self::normalize( $input );
		$decimals = (int) $input['decimals'];
		$scale    = 10 ** $decimals;
		$round    = static fn( $v ) => $input['round_at_subtotal'] ? $v : self::round( $v );
		$entries  = array();
		$rejected = array();

		// 1. 赠品 / 捆绑决定单价；被移除的赠品行不参加后面的计算。
		$offers = Offer_Math::resolve( $input );
		foreach ( $offers['removed'] as $key ) {
			unset( $input['lines'][ $key ] );
		}
		$entries = array_merge( $entries, $offers['entries'] );

		// 2. 行金额和小计。
		$items          = array();
		$subtotals      = array();
		$items_subtotal = 0;
		foreach ( $input['lines'] as $key => $line ) {
			$price             = self::cents( (float) $offers['unit'][ $key ] * (float) $line['qty'], $decimals );
			$items[ $key ]     = array( 'key' => $key, 'price' => $price, 'qty' => $line['qty'], 'line' => $line );
			$subtotals[ $key ] = $price ? $price / $scale : 0.0;
			$items_subtotal   += $round( $price );
		}
		$subtotal = $items_subtotal ? $items_subtotal / $scale : 0.0;

		// 3. 优惠码：按 WooCommerce 的顺序，先校验再计算。
		$valid = array();
		foreach ( Coupon_Math::sort( $input['coupons'], (bool) $input['sequential'] ) as $code => $coupon ) {
			$reason = Coupon_Math::invalid_reason( $coupon, $input, $subtotals, $subtotal );
			// 手动订单链接不叠加优惠码（Manual_Orders::block_coupons，最后执行）：只有这一单开了首单优惠时放行首单券。
			if ( '' === $reason && $input['manual'] && ! ( $input['manual']['first_order'] && '' !== $input['manual']['first_code'] && (string) $code === $input['manual']['first_code'] ) ) {
				$reason = 'manual_order';
			}
			if ( '' === $reason ) {
				$valid[ $code ] = $coupon;
			} else {
				$rejected[] = array( 'source' => $coupon['origin'], 'code' => (string) $code, 'id' => (int) $coupon['id'], 'reason' => $reason );
			}
		}
		$by_coupon     = Coupon_Math::apply( $items, $valid, (bool) $input['sequential'], $decimals );
		$line_discount = array_fill_keys( array_keys( $items ), 0 );
		$coupon_totals = array();
		foreach ( $by_coupon as $code => $by_item ) {
			$coupon_totals[ $code ] = array_sum( $by_item );
			foreach ( $by_item as $key => $amount ) {
				$line_discount[ $key ] += $amount;
			}
		}
		$items_total = 0;
		$lines_out   = array();
		foreach ( $items as $key => $item ) {
			$total             = $item['price'] - $line_discount[ $key ];
			$items_total      += $round( $total );
			$lines_out[ $key ] = array( 'unit' => (float) $offers['unit'][ $key ], 'subtotal_cents' => $item['price'], 'discount_cents' => $line_discount[ $key ], 'total_cents' => $total );
		}
		foreach ( $valid as $code => $coupon ) {
			$entries[] = array(
				'source'       => $coupon['origin'],
				'id'           => (int) $coupon['id'],
				'code'         => (string) $code,
				'label'        => (string) $code,
				'amount_cents' => (int) round( $coupon_totals[ $code ] ?? 0 ),
				'stacking'     => array(
					'with_coupons'    => empty( $coupon['individual_use'] ),
					'with_promotions' => 'stack' === $coupon['strategy'] ? true : $coupon['strategy'], // true / no_stack / replace
					'with_bundle'     => false,
					'with_gift'       => false,
				),
			);
		}

		// 4. 运费折扣。
		$applicable     = Shipping_Math::applicable( (array) $input['shipping']['discounts'], $input['customer'], (int) $input['now'], (int) $input['shipping']['weekday'] );
		$shipping_total = 0;
		$shipping_full  = 0;
		$rates_out      = array();
		foreach ( (array) $input['shipping']['rates'] as $rate ) {
			$cost            = (float) $rate['cost'];
			$best            = empty( $rate['fixed'] ) ? Shipping_Math::best( $cost, $applicable, $decimals ) : array( 'cost' => $cost, 'by' => null );
			$shipping_full  += self::cents( $cost, $decimals );
			$shipping_total += self::cents( (float) $best['cost'], $decimals );
			$rates_out[]     = array( 'id' => (string) ( $rate['id'] ?? '' ), 'original_cents' => self::cents( $cost, $decimals ), 'cost_cents' => self::cents( (float) $best['cost'], $decimals ), 'discount_id' => $best['by'] ? (int) $best['by']['id'] : 0 );
			if ( $best['by'] ) {
				$entries[] = array(
					'source'       => 'shipping_discount',
					'id'           => (int) $best['by']['id'],
					'label'        => (string) ( $best['by']['name'] ?? '' ),
					'amount_cents' => (int) round( self::cents( $cost, $decimals ) - self::cents( (float) $best['cost'], $decimals ) ),
					'stacking'     => array( 'with_coupons' => true, 'with_promotions' => true, 'with_other_shipping_discounts' => false ),
				);
			}
		}

		// 5. 满减活动：每个生效的活动一笔负数费用。合计不超过“商品折后金额 + 运费”（WooCommerce 对负数费用的上限）。
		$promos = Promotion_Math::discounts( $input, $subtotals );
		$manual = $input['manual'];
		$fees   = array();
		foreach ( $promos['applied'] as $a ) {
			// 手动订单链接：这一单没开店铺满减就一个都不留，开了只留店主选的活动（没选具体活动的旧链接全留）。
			if ( $manual && ( ! $manual['store_promos'] || ( $manual['promo_ids'] && ! in_array( (int) $a['id'], $manual['promo_ids'], true ) ) ) ) {
				$rejected[] = array( 'source' => 'promotion', 'id' => (int) $a['id'], 'reason' => 'manual_order' );
				continue;
			}
			$fees[ 'pc-promo-' . $a['id'] ] = array( 'kind' => 'promotion', 'id' => (int) $a['id'], 'amount' => -1 * $a['discount'] );
		}
		foreach ( $manual ? $manual['discounts'] : array() as $n => $d ) {
			$fees[ 'pc-manual-' . $n ] = array( 'kind' => 'manual', 'id' => (int) $n, 'label' => (string) $d['label'], 'amount' => -1 * (float) $d['amount'] );
		}
		uasort( $fees, static fn( $a, $b ) => $a['amount'] > $b['amount'] ? -1 : 1 ); // WC_Cart_Fees::sort_fees_callback
		$running    = 0;
		$fees_total = 0;
		foreach ( $fees as $fee_id => $fee ) {
			$total = self::cents( (float) $fee['amount'], $decimals );
			$max   = self::round( $items_total + $running + $shipping_total ) * -1;
			if ( $total < $max ) {
				$total = $max;
			}
			$running                    += $total;
			$fees_total                 += $total;
			$fees[ $fee_id ]['total_cents'] = $total;
			if ( 'manual' === $fee['kind'] ) {
				$entries[] = array(
					'source'       => 'manual_discount',
					'id'           => (int) $fee['id'],
					'label'        => (string) $fee['label'],
					'amount_cents' => (int) round( -1 * $total ),
					'stacking'     => array( 'with_coupons' => false, 'with_promotions' => (bool) $manual['store_promos'] ),
				);
				continue;
			}
			$c         = $input['promotions'][ $fee['id'] ];
			$entries[] = array(
				'source'       => 'promotion',
				'id'           => (int) $fee['id'],
				'label'        => (string) ( $c['title'] ?? '' ),
				'amount_cents' => (int) round( -1 * $total ),
				'stacking'     => array( 'with_coupons' => ! empty( $c['with_coupons'] ), 'with_promotions' => true, 'with_bundle' => false, 'with_gift' => false ),
			);
		}
		foreach ( $input['promotions'] as $id => $c ) {
			if ( in_array( (int) $id, $promos['usable'], true ) ) {
				continue;
			}
			$reason = 'customer_group';
			if ( $input['coupons'] ) {
				$reason = 'coupon_in_cart';
				foreach ( $input['coupons'] as $coupon ) {
					if ( 'replace' === $coupon['strategy'] ) {
						$reason = 'replaced_by_coupon';
					}
				}
				if ( 'coupon_in_cart' === $reason && ! empty( $c['with_coupons'] ) ) {
					$reason = 'customer_group';
				}
			}
			$rejected[] = array( 'source' => 'promotion', 'id' => (int) $id, 'reason' => $reason );
		}

		// 6. 合计。
		$tax   = (float) $input['tax_cents'];
		$total = max( 0, self::round( $items_total + $fees_total + $shipping_total + $tax ) );

		return array(
			'version'     => self::VERSION,
			'supported'   => ! $input['unsupported'],
			'unsupported' => array_values( (array) $input['unsupported'] ),
			'entries'     => $entries,
			'rejected'    => $rejected,
			'actions'     => $offers['actions'],
			'lines'       => $lines_out,
			'coupons'     => array_map( static fn( $v ) => (int) round( $v ), $coupon_totals ),
			'promotions'  => array( 'applied' => $promos['applied'], 'evals' => $promos['evals'], 'fees' => $fees ),
			'shipping'    => $rates_out,
			'totals'      => array(
				'items_subtotal_cents'    => $items_subtotal,
				'coupon_discount_cents'   => array_sum( $line_discount ),
				'items_total_cents'       => $items_total,
				'fees_cents'              => $fees_total,
				'shipping_original_cents' => $shipping_full,
				'shipping_cents'          => $shipping_total,
				'tax_cents'               => $tax,
				'total_cents'             => (int) $total,
			),
		);
	}
}
