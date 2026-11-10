<?php
/**
 * 价格引擎 · 赠品和捆绑（M4 / NF-02）。纯计算，对应 Product_Offers::sync_cart 里决定价格的部分：
 *
 * - 赠品：购物车里没有优惠码、没有捆绑、参加计算的商品都不在满减活动里，且达到门槛时，
 *   第一个满足条件的赠品活动（ID 大的在前）生效，赠品行按 0 元算。一份购物车只有一个赠品活动。
 * - 捆绑：整组完整（每个槽位都在、数量成套、活动没改过、库存够、没有优惠码）时，
 *   按一口价或折扣算出整组目标价（分），再按各行原价比例分摊，零头按小数部分大的先给。
 *
 * 引擎不改购物车。旧逻辑会移除的赠品行在这里记成 actions 里的 remove，并且不参加后面的计算；
 * 应该自动加上的赠品记成 add_gift（0 元，不影响总价）。
 *
 * 库存、商品是否可购买这类要查数据库的事实由读取购物车的一方放进输入（见 Product_Offers::pricing_facts）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

defined( 'ABSPATH' ) || exit;

final class Offer_Math {

	/** 商品（行）是否属于某个进行中的满减活动（Product_Offers::conflict）。 */
	private static function conflict( array $line, array $input ): bool {
		return (bool) Promotion_Math::owner( $line, array_keys( $input['promotions'] ), $input['promotions'], $input['customer'] );
	}

	/** Product_Offers::gift_qualifies。 */
	public static function gift_qualifies( array $offer, array $input ): bool {
		if ( $input['coupons'] ) {
			return false;
		}
		$measure = 0.0;
		foreach ( $input['lines'] as $line ) {
			if ( ! empty( $line['offer'] ) ) {
				if ( 'bundle' === ( $line['offer']['kind'] ?? '' ) ) {
					return false;
				}
				continue;
			}
			if ( empty( $line['sellable'] ) ) {
				continue;
			}
			if ( $offer['qualifiers'] && ! array_intersect( $offer['qualifiers'], array( $line['product_id'], $line['parent_id'] ?? 0 ) ) ) {
				continue;
			}
			if ( self::conflict( $line, $input ) ) {
				return false;
			}
			$measure += 'quantity' === $offer['basis'] ? (int) $line['qty'] : (float) $line['price'] * (int) $line['qty'];
		}
		return $measure + 0.000001 >= $offer['threshold']
			&& ! empty( $offer['gift_product_ok'] )
			&& ! self::conflict( (array) ( $offer['gift_line'] ?? array( 'product_id' => (int) $offer['gift_product'], 'parent_id' => 0, 'cats' => array(), 'on_sale' => false ) ), $input )
			&& ! empty( $offer['gift_stock_ok'] );
	}

	/** 捆绑组是否完整有效（Product_Offers::group_state + selections）；有效时返回 [offer, units]，否则返回 null。 */
	private static function bundle_state( array $items, array $input ): ?array {
		$first = reset( $items );
		$meta  = $first['offer'];
		$offer = $input['offers'][ (int) $meta['offer'] ] ?? null;
		if ( ! $offer || empty( $offer['active'] ) || $meta['version'] !== $offer['version'] || $input['coupons'] || 'bundle' !== $offer['kind'] ) {
			return null;
		}
		$choices = array();
		$units   = null;
		foreach ( $items as $item ) {
			$m    = $item['offer'];
			$slot = null;
			foreach ( $offer['slots'] as $s ) {
				if ( $s['id'] === $m['slot'] ) {
					$slot = $s;
					break;
				}
			}
			if ( ! $slot || isset( $choices[ $slot['id'] ] ) || $m['version'] !== $meta['version'] || (int) $m['offer'] !== (int) $offer['id'] ) {
				return null;
			}
			$n = (float) $item['qty'] / (float) $slot['qty'];
			if ( $n < 1 || floor( $n ) !== $n || ( null !== $units && $units !== $n ) ) {
				return null;
			}
			$units                  = $n;
			$choices[ $slot['id'] ] = array( 'product' => (int) $item['product_id'], 'item' => $item );
		}
		if ( count( $choices ) !== count( $offer['slots'] ) ) {
			return null;
		}
		$units = (int) $units;
		if ( $units < 1 || $units > 10 ) {
			return null;
		}
		$total = 0.0;
		foreach ( $offer['slots'] as $slot ) {
			$choice = $choices[ $slot['id'] ];
			if ( ! in_array( $choice['product'], $slot['choices'], true ) || empty( $choice['item']['sellable'] ) || self::conflict( $choice['item'], $input ) ) {
				return null;
			}
			$total += $slot['qty'] * (float) $choice['item']['price'];
		}
		if ( ! is_finite( $total ) || $total <= 0 || $total > 1000000000 || ( 'fixed' === $offer['price_type'] && $offer['price_value'] > $total ) ) {
			return null;
		}
		if ( empty( $input['offer_groups'][ (string) $meta['group'] ]['stock_ok'] ) ) {
			return null;
		}
		return array( $offer, $units );
	}

	/**
	 * 每个商品行这次按什么单价算。
	 *
	 * @return array{unit:array<string,float>,removed:string[],actions:array[],entries:array[]}
	 */
	public static function resolve( array $input ): array {
		$decimals = (int) $input['decimals'];
		$scale    = 10 ** $decimals;
		$unit     = array();
		$removed  = array();
		$actions  = array();
		$entries  = array();
		foreach ( $input['lines'] as $key => $line ) {
			$unit[ $key ] = (float) $line['price'];
		}

		// 赠品：第一个满足条件的赠品活动。
		$gift = null;
		foreach ( $input['offers'] as $offer ) {
			if ( 'gift' === $offer['kind'] && ! empty( $offer['active'] ) && self::gift_qualifies( $offer, $input ) ) {
				$gift = $offer;
				break;
			}
		}
		$gift_key = null;
		foreach ( $input['lines'] as $key => $line ) {
			if ( 'gift' !== ( $line['offer']['kind'] ?? '' ) ) {
				continue;
			}
			if ( ! $gift || (int) $line['offer']['offer'] !== (int) $gift['id'] || $line['offer']['version'] !== $gift['version'] || null !== $gift_key ) {
				$removed[] = (string) $key;
				$actions[] = array( 'do' => 'remove', 'key' => (string) $key );
				continue;
			}
			$gift_key     = (string) $key;
			$unit[ $key ] = 0.0;
			if ( (float) $line['qty'] !== (float) $gift['gift_qty'] ) {
				$actions[] = array( 'do' => 'set_quantity', 'key' => (string) $key, 'qty' => $gift['gift_qty'] );
			}
			$entries[] = array(
				'source'       => 'gift',
				'id'           => (int) $gift['id'],
				'label'        => (string) ( $gift['title'] ?? '' ),
				'amount_cents' => (int) round( (float) $line['price'] * (float) $gift['gift_qty'] * $scale ),
				'lines'        => array( (string) $key ),
				'stacking'     => array( 'with_coupons' => false, 'with_promotions' => false, 'with_bundle' => false ),
			);
		}
		if ( $gift && null === $gift_key ) {
			$actions[] = array( 'do' => 'add_gift', 'offer' => (int) $gift['id'], 'product' => (int) $gift['gift_product'], 'qty' => $gift['gift_qty'] );
		}

		// 捆绑：按组定价。
		$groups = array();
		foreach ( $input['lines'] as $key => $line ) {
			if ( ! empty( $line['offer'] ) && ! in_array( (string) $key, $removed, true ) ) {
				$groups[ (string) $line['offer']['group'] ][ $key ] = $line;
			}
		}
		foreach ( $groups as $items ) {
			$state = self::bundle_state( $items, $input );
			if ( ! $state ) {
				continue;
			}
			[ $offer ] = $state;
			$total     = 0.0;
			foreach ( $items as $item ) {
				$total += (float) $item['price'] * $item['qty'];
			}
			$first = reset( $items );
			$slot  = null;
			foreach ( $offer['slots'] as $s ) {
				if ( $s['id'] === $first['offer']['slot'] ) {
					$slot = $s;
					break;
				}
			}
			$units        = $first['qty'] / $slot['qty'];
			$target       = 'fixed' === $offer['price_type'] ? $offer['price_value'] * $units : $total * ( 1 - $offer['price_value'] / 100 );
			$target_minor = (int) round( $target * $scale );
			$allocated    = array();
			$fractions    = array();
			$used         = 0;
			foreach ( $items as $key => $item ) {
				$share             = $target_minor * ( (float) $item['price'] * $item['qty'] / $total );
				$allocated[ $key ] = (int) floor( $share );
				$fractions[ $key ] = $share - $allocated[ $key ];
				$used             += $allocated[ $key ];
			}
			arsort( $fractions, SORT_NUMERIC );
			$remainder = $target_minor - $used;
			foreach ( array_keys( $fractions ) as $key ) {
				if ( $remainder <= 0 ) {
					break;
				}
				++$allocated[ $key ];
				--$remainder;
			}
			foreach ( $items as $key => $item ) {
				$unit[ $key ] = $allocated[ $key ] / $scale / $item['qty'];
			}
			$entries[] = array(
				'source'       => 'bundle',
				'id'           => (int) $offer['id'],
				'label'        => (string) ( $offer['title'] ?? '' ),
				'amount_cents' => (int) round( $total * $scale ) - $target_minor,
				'lines'        => array_map( 'strval', array_keys( $items ) ),
				'stacking'     => array( 'with_coupons' => false, 'with_promotions' => false, 'with_gift' => false ),
			);
		}
		return array( 'unit' => $unit, 'removed' => $removed, 'actions' => $actions, 'entries' => $entries );
	}
}
