<?php
/**
 * M4 对照测试：随机购物车。每一轮用固定的随机种子搭一份购物车（商品、数量、满减活动、优惠码、赠品、运费折扣随机组合），
 * 旧逻辑和新引擎各算一遍，必须逐分相同。失败时打印种子，可以用 m4_fuzz_case( 种子 ) 重现。
 *
 * @package PeptideCore
 */

use PeptideCore\Coupon_Rules;

function m4_fuzz_case( int $seed ): WC_Cart {
	mt_srand( $seed );
	$pick = static fn( array $a ) => $a[ mt_rand( 0, count( $a ) - 1 ) ];
	$chance = static fn( int $percent ): bool => mt_rand( 1, 100 ) <= $percent;
	m4_reset();
	$prices = array( '9.99', '19.99', '24.50', '29.98', '37.98', '44.98', '59.98', '89.00', '120.00', '3.33', '0.99', '15.00', '9.995', '0.333', '12.345' );
	$ids    = array();
	for ( $i = 1; $i <= 8; $i++ ) {
		$price = $pick( $prices );
		m4_product( 100 + $i, $price, array( 'cats' => array( $pick( array( 10, 11, 12 ) ) ), 'regular' => $chance( 25 ) ? (string) ( (float) $price * 2 ) : $price ) );
		$ids[] = 100 + $i;
	}
	m4_product( 200, '0', array( 'cats' => array( 13 ) ) );
	m4_product( 201, $pick( $prices ), array( 'parent' => 200 ) );
	m4_product( 202, $pick( $prices ), array( 'parent' => 200 ) );
	$ids[] = 201;
	$ids[] = 202;
	if ( $chance( 20 ) ) {
		update_option( 'woocommerce_calc_discounts_sequentially', 'yes' );
	}
	if ( $chance( 10 ) ) {
		update_option( 'woocommerce_tax_round_at_subtotal', 'yes' );
	}

	// 满减活动
	$types = array( 'spend_amount', 'spend_percent', 'qty_amount', 'qty_percent', 'nth_percent', 'bundle_price', 'buy_x_free_y' );
	for ( $n = mt_rand( 0, 3 ), $i = 0; $i < $n; $i++ ) {
		$type      = $pick( $types );
		$unlimited = $chance( 30 ) && in_array( $type, array( 'spend_amount', 'qty_amount', 'nth_percent', 'bundle_price', 'buy_x_free_y' ), true );
		$tiers     = array();
		$min       = 0;
		$value     = 0;
		for ( $t = 0, $count = $unlimited ? 1 : mt_rand( 1, 3 ); $t < $count; $t++ ) {
			switch ( $type ) {
				case 'spend_amount':
					$min  += mt_rand( 20, 90 );
					$value = min( $min - 1, $value + mt_rand( 3, 15 ) );
					break;
				case 'spend_percent':
					$min  += mt_rand( 20, 90 );
					$value = min( 90, $value + mt_rand( 5, 20 ) );
					break;
				case 'qty_amount':
					$min  += mt_rand( 1, 3 );
					$value = $value + mt_rand( 2, 12 );
					break;
				case 'qty_percent':
					$min  += mt_rand( 1, 3 );
					$value = min( 90, $value + mt_rand( 5, 20 ) );
					break;
				case 'nth_percent':
					$min   = max( 2, $min + mt_rand( 1, 2 ) );
					$value = min( 100, $value + mt_rand( 10, 50 ) );
					break;
				case 'bundle_price':
					$min   = max( 2, $min + mt_rand( 1, 2 ) );
					$value = $value + mt_rand( 20, 80 );
					break;
				case 'buy_x_free_y':
					$min   = max( 2, $min + mt_rand( 1, 2 ) );
					$value = min( $min - 1, max( 1, $value + mt_rand( 0, 1 ) ) );
					break;
			}
			$tiers[] = array( 'min' => (float) $min, 'value' => (float) $value );
		}
		$scope  = $pick( array( 'all', 'all', 'categories', 'products' ) );
		$config = array_merge(
			\PeptideCore\Promotions::defaults(),
			array(
				'type'         => $type,
				'basis'        => in_array( $type, array( 'spend_amount', 'spend_percent' ), true ) ? 'spend' : 'quantity',
				'reward'       => in_array( $type, array( 'spend_percent', 'qty_percent', 'nth_percent' ), true ) ? 'percent' : 'amount',
				'tiers'        => $tiers,
				'unlimited'    => $unlimited,
				'scope'        => $scope,
				'categories'   => 'categories' === $scope ? array( $pick( array( 10, 11, 12, 13 ) ) ) : array(),
				'products'     => 'products' === $scope ? array( $pick( $ids ), $pick( array( 200, 101, 102 ) ) ) : array(),
				'with_coupons' => ! $chance( 20 ),
				'exclude_sale' => $chance( 15 ),
			)
		);
		m4_promotion( 500 + $i, 'Promo ' . $i, $config );
	}

	// 优惠码
	$codes = array();
	for ( $n = mt_rand( 0, 3 ), $i = 0; $i < $n; $i++ ) {
		$type  = $pick( array( 'percent', 'percent', 'fixed_cart', 'fixed_product' ) );
		$props = array(
			'type'           => $type,
			'amount'         => 'percent' === $type ? (string) $pick( array( 5, 10, 12.5, 15, 33, 50, 100 ) ) : (string) $pick( array( 1, 2.5, 5, 7.77, 10, 20, 60 ) ),
			'limit_x'        => 'fixed_cart' !== $type && $chance( 20 ) ? mt_rand( 1, 3 ) : null,
			'exclude_sale'   => $chance( 15 ),
			'product_ids'    => $chance( 15 ) ? array( $pick( $ids ), 200 ) : array(),
			'cats'           => $chance( 15 ) ? array( $pick( array( 10, 11, 12, 13 ) ) ) : array(),
			'excluded_ids'   => $chance( 10 ) ? array( $pick( $ids ) ) : array(),
			'excluded_cats'  => $chance( 10 ) ? array( $pick( array( 10, 11, 12 ) ) ) : array(),
			'min'            => $chance( 15 ) ? (string) mt_rand( 20, 150 ) : '',
			'max'            => $chance( 5 ) ? (string) mt_rand( 60, 300 ) : '',
			'individual_use' => $chance( 10 ),
		);
		$meta = array( Coupon_Rules::STRATEGY_META => $pick( array( 'stack', 'stack', 'no_stack', 'replace', '' ) ) );
		if ( $chance( 15 ) ) {
			$meta[ Coupon_Rules::MIN_SPEND_META ] = (string) mt_rand( 20, 120 );
		}
		if ( $chance( 15 ) ) {
			$meta[ Coupon_Rules::MIN_QTY_META ] = (string) mt_rand( 1, 5 );
		}
		m4_coupon( 900 + $i, 'code' . $i, $props, $meta );
		$codes[] = 'code' . $i;
	}

	// 首单优惠
	if ( $chance( 25 ) ) {
		m4_coupon( 950, 'WELCOME5', array( 'type' => 'percent', 'amount' => '5', 'limit_per_user' => 1 ), array( Coupon_Rules::FIRST_META => 'yes', Coupon_Rules::STRATEGY_META => 'stack' ) );
		update_option( \PeptideCore\First_Order_Offer::OPTION, array( 'enabled' => true, 'coupon_id' => 950 ) );
		if ( $chance( 60 ) ) {
			$codes[] = 'WELCOME5';
		}
		if ( $chance( 30 ) ) {
			$GLOBALS['m4']['billing'] = 'old@example.com';
			$GLOBALS['m4']['orders'][] = array( 'user' => 0, 'email' => 'old@example.com' );
		}
	}

	// 赠品
	if ( $chance( 25 ) ) {
		m4_offer( 700, 'Gift', array( 'kind' => 'gift', 'basis' => $pick( array( 'spend', 'quantity' ) ), 'threshold' => (float) $pick( array( 2, 3, 40, 80 ) ), 'gift_product' => $pick( $ids ), 'gift_qty' => mt_rand( 1, 2 ), 'qualifiers' => $chance( 30 ) ? array( $pick( $ids ), 200 ) : array() ) );
	}

	// 运费和运费折扣
	if ( $chance( 60 ) ) {
		$GLOBALS['m4']['rates'] = array( array( 'id' => 'plan:a', 'cost' => $pick( array( '15.00', '45.00', '6.95', '0' ) ) ) );
		for ( $n = mt_rand( 0, 2 ), $i = 1; $i <= $n; $i++ ) {
			$kind = $pick( array( 'amount', 'percent' ) );
			m4_shipping_discount( array( 'id' => $i, 'name' => 'Ship ' . $i, 'kind' => $kind, 'value' => 'percent' === $kind ? (float) $pick( array( 10, 33, 50, 100 ) ) : (float) $pick( array( 2, 5, 9.99, 20 ) ), 'enabled' => ! $chance( 10 ) ) );
		}
	}

	// 购物车
	$lines = array();
	$used  = array();
	for ( $n = mt_rand( 1, 5 ), $i = 0; $i < $n; $i++ ) {
		$id = $pick( $ids );
		if ( isset( $used[ $id ] ) ) {
			continue;
		}
		$used[ $id ] = true;
		$lines[]     = array( $id, mt_rand( 1, 6 ) );
	}
	// 手动订单链接：改价、可选的自定义运费和自定义优惠、可选开店铺满减（选其中几个活动）和首单优惠。
	if ( $chance( 20 ) ) {
		$items = array();
		foreach ( $lines as [ $id, $qty ] ) {
			$native  = (float) $GLOBALS['m4']['products'][ $id ]['price'];
			$items[] = array( $id, $qty, round( $native * $pick( array( 0.5, 0.8, 1.0, 1.0 ) ), 2 ), (float) $GLOBALS['m4']['products'][ $id ]['regular'] );
		}
		$promo_ids = array();
		foreach ( array( 500, 501, 502 ) as $pid ) {
			if ( isset( $GLOBALS['m4']['posts'][ $pid ] ) && $chance( 50 ) ) {
				$promo_ids[] = $pid;
			}
		}
		$discounts = array();
		for ( $n = mt_rand( 0, 2 ), $i = 0; $i < $n; $i++ ) {
			$discounts[] = array( 'label' => 'Manual ' . $i, 'amount' => (float) $pick( array( 1, 2.5, 10, 75 ) ) );
		}
		return m4_manual_cart(
			array(
				'items'        => $items,
				'shipping'     => $chance( 40 ) ? array( 'label' => 'Courier', 'amount' => (float) $pick( array( 0, 5, 12.5 ) ) ) : null,
				'discounts'    => $discounts,
				'promo_ids'    => $promo_ids,
				'store_promos' => $chance( 15 ),
				'first_order'  => $chance( 40 ),
			),
			$codes
		);
	}
	return m4_cart( $lines, $codes );
}

/** @return int 不一致的轮数。 */
function m4_fuzz( int $rounds, array $fields ): int {
	$bad = 0;
	for ( $seed = 1; $seed <= $rounds; $seed++ ) {
		$cart   = m4_fuzz_case( $seed );
		$legacy = m4_legacy( $cart );
		$engine = m4_engine( $cart );
		$diff   = array();
		foreach ( $fields as $key ) {
			if ( $legacy[ $key ] != $engine[ $key ] ) { // phpcs:ignore Universal.Operators.StrictComparisons
				$diff[] = $key . ' 旧 ' . json_encode( $legacy[ $key ] ) . ' / 新 ' . json_encode( $engine[ $key ] );
			}
		}
		if ( ! $engine['result']['supported'] ) {
			$diff[] = 'unsupported ' . implode( ',', $engine['result']['unsupported'] );
		}
		if ( $diff ) {
			++$bad;
			if ( $bad <= 8 ) {
				echo 'FAIL  随机购物车 #' . $seed . "\n        " . implode( "\n        ", $diff ) . "\n";
			}
		}
	}
	return $bad;
}
