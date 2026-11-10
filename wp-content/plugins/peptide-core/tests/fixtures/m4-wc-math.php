<?php
/**
 * 测试用：WooCommerce 购物车金额算法的精简副本（不含税）。
 *
 * 来源：WooCommerce（GPL-3.0-or-later）trunk，2026-11-10 取自
 * plugins/woocommerce/includes/class-wc-cart-fees.php、class-wc-discounts.php、class-wc-cart-totals.php。
 * 只保留不含税时会走到的语句，语句顺序和四舍五入位置不变。它在对照测试里扮演“线上 WooCommerce”，
 * 让旧逻辑（本插件挂在钩子上的那几个类）有一个真实的计算环境。
 *
 * 想换成真的 WooCommerce 源码来跑：设置环境变量 PC_WC_SRC=<woocommerce 插件目录>，m4-harness.php 会改为加载原文件。
 *
 * @package PeptideCore
 */

/** NumberUtil::round：浮点数先归整到 6 位小数。 */
function m4_wc_round( $val, int $precision = 0, int $mode = PHP_ROUND_HALF_UP ): float {
	return round( is_float( $val ) ? round( $val, WC_ROUNDING_PRECISION ) : $val, $precision, $mode );
}

final class WC_Cart_Fees {
	private $fees = array();
	public function add_fee( $args = array() ) {
		$fee          = (object) array_merge( array( 'id' => '', 'name' => '', 'tax_class' => '', 'taxable' => false, 'amount' => 0, 'total' => 0 ), (array) $args );
		$fee->taxable = wc_string_to_bool( $fee->taxable );
		$fee->amount  = wc_format_decimal( $fee->amount );
		if ( array_key_exists( $fee->id, $this->fees ) ) {
			return new WP_Error( 'fee_exists', 'Fee has already been added.' );
		}
		$this->fees[ $fee->id ] = $fee;
		return $fee;
	}
	public function get_fees() {
		uasort( $this->fees, static fn( $a, $b ) => apply_filters( 'woocommerce_sort_fees_callback', $a->amount > $b->amount ? -1 : 1, $a, $b ) );
		return $this->fees;
	}
	public function set_fees( $raw = array() ) {
		$this->fees = array();
		foreach ( $raw as $fee ) {
			$this->add_fee( $fee );
		}
	}
	public function remove_all_fees() {
		$this->set_fees();
	}
}

class WC_Discounts {
	protected $object;
	protected $items     = array();
	protected $discounts = array();

	public function __construct( $object = null ) {
		if ( is_a( $object, 'WC_Cart' ) ) {
			$this->object = $object;
		}
	}
	public function set_items( $items ) {
		$this->items     = $items;
		$this->discounts = array();
		uasort( $this->items, array( $this, 'sort_by_price' ) );
	}
	public function get_object() {
		return $this->object;
	}
	public function get_items_to_validate() {
		return apply_filters( 'woocommerce_coupon_get_items_to_validate', $this->items, $this );
	}
	public function get_discount( $key, $in_cents = false ) {
		$totals = $this->get_discounts_by_item( $in_cents );
		return isset( $totals[ $key ] ) ? $totals[ $key ] : 0;
	}
	public function get_discounts( $in_cents = false ) {
		return $in_cents ? $this->discounts : wc_remove_number_precision_deep( $this->discounts );
	}
	public function get_discounts_by_item( $in_cents = false ) {
		$discounts = $this->discounts;
		$totals    = (array) array_shift( $discounts );
		foreach ( $discounts as $item_discounts ) {
			foreach ( $item_discounts as $item_key => $item_discount ) {
				$totals[ $item_key ] += $item_discount;
			}
		}
		return $in_cents ? $totals : wc_remove_number_precision_deep( $totals );
	}
	public function get_discounts_by_coupon( $in_cents = false ) {
		$totals = array_map( 'array_sum', $this->discounts );
		return $in_cents ? $totals : wc_remove_number_precision_deep( $totals );
	}
	public function get_discounted_price_in_cents( $item ) {
		return absint( m4_wc_round( $item->price - $this->get_discount( $item->key, true ) ) );
	}
	public function apply_coupon( $coupon, $validate = true ) {
		$valid = $validate ? $this->is_coupon_valid( $coupon ) : true;
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$code = $coupon->get_code();
		if ( ! isset( $this->discounts[ $code ] ) || ! is_array( $this->discounts[ $code ] ) ) {
			$this->discounts[ $code ] = array_fill_keys( array_keys( $this->items ), 0 );
		}
		$items = $this->get_items_to_apply_coupon( $coupon );
		switch ( $coupon->get_discount_type() ) {
			case 'percent':
				$this->apply_coupon_percent( $coupon, $items );
				break;
			case 'fixed_product':
				$this->apply_coupon_fixed_product( $coupon, $items );
				break;
			case 'fixed_cart':
				$this->apply_coupon_fixed_cart( $coupon, $items );
				break;
		}
		return true;
	}
	protected function sort_by_price( $a, $b ) {
		$price_1 = $a->quantity > 1 ? $a->price / $a->quantity : $a->price;
		$price_2 = $b->quantity > 1 ? $b->price / $b->quantity : $b->price;
		if ( $price_1 === $price_2 ) {
			return 0;
		}
		return ( $price_1 < $price_2 ) ? 1 : -1;
	}
	protected function filter_products_with_price( $item ) {
		return $this->get_discounted_price_in_cents( $item ) > 0;
	}
	protected function get_items_to_apply_coupon( $coupon ) {
		$items_to_apply = array();
		foreach ( $this->get_items_to_validate() as $item ) {
			$item_to_apply = clone $item;
			if ( 0 === $this->get_discounted_price_in_cents( $item_to_apply ) || 0 >= $item_to_apply->quantity ) {
				continue;
			}
			if ( ! $coupon->is_valid_for_product( $item_to_apply->product, $item_to_apply->object ) && ! $coupon->is_valid_for_cart() ) {
				continue;
			}
			$items_to_apply[] = $item_to_apply;
		}
		return apply_filters( 'woocommerce_coupon_get_items_to_apply', $items_to_apply, $coupon, $this );
	}
	protected function apply_coupon_percent( $coupon, $items_to_apply ) {
		$total_discount  = 0;
		$cart_total      = 0;
		$limit_usage_qty = 0;
		$applied_count   = 0;
		if ( null !== $coupon->get_limit_usage_to_x_items() ) {
			$limit_usage_qty = $coupon->get_limit_usage_to_x_items();
		}
		$coupon_amount = $coupon->get_amount();
		foreach ( $items_to_apply as $item ) {
			$discounted_price  = $this->get_discounted_price_in_cents( $item );
			$price_to_discount = ( 'yes' === get_option( 'woocommerce_calc_discounts_sequentially', 'no' ) ) ? $discounted_price : m4_wc_round( $item->price );
			$apply_quantity    = $limit_usage_qty && ( $limit_usage_qty - $applied_count ) < $item->quantity ? $limit_usage_qty - $applied_count : $item->quantity;
			$apply_quantity    = max( 0, apply_filters( 'woocommerce_coupon_get_apply_quantity', $apply_quantity, $item, $coupon, $this ) );
			$price_to_discount = ( $price_to_discount / $item->quantity ) * $apply_quantity;
			$discount          = floor( $price_to_discount * ( $coupon_amount / 100 ) );
			$discount          = wc_round_discount( min( $discounted_price, $discount ), 0 );
			$cart_total        = $cart_total + $price_to_discount;
			$total_discount    = $total_discount + $discount;
			$applied_count     = $applied_count + $apply_quantity;
			$this->discounts[ $coupon->get_code() ][ $item->key ] += $discount;
		}
		$cart_total_discount = wc_round_discount( $cart_total * ( $coupon_amount / 100 ), 0 );
		if ( $total_discount < $cart_total_discount ) {
			$total_discount += $this->apply_coupon_remainder( $coupon, $items_to_apply, $cart_total_discount - $total_discount );
		}
		return $total_discount;
	}
	protected function apply_coupon_fixed_product( $coupon, $items_to_apply, $amount = null ) {
		$total_discount  = 0;
		$amount          = $amount ? $amount : wc_add_number_precision( (float) $coupon->get_amount() );
		$limit_usage_qty = 0;
		$applied_count   = 0;
		if ( null !== $coupon->get_limit_usage_to_x_items() ) {
			$limit_usage_qty = $coupon->get_limit_usage_to_x_items();
		}
		foreach ( $items_to_apply as $item ) {
			$discounted_price = $this->get_discounted_price_in_cents( $item );
			if ( $limit_usage_qty ) {
				$apply_quantity = $limit_usage_qty - $applied_count < $item->quantity ? $limit_usage_qty - $applied_count : $item->quantity;
				$apply_quantity = max( 0, apply_filters( 'woocommerce_coupon_get_apply_quantity', $apply_quantity, $item, $coupon, $this ) );
				$discount       = min( $amount, $item->price / $item->quantity ) * $apply_quantity;
			} else {
				$apply_quantity = apply_filters( 'woocommerce_coupon_get_apply_quantity', $item->quantity, $item, $coupon, $this );
				$discount       = $amount * $apply_quantity;
			}
			$discount       = min( $discounted_price, $discount );
			$total_discount = $total_discount + $discount;
			$applied_count  = $applied_count + $apply_quantity;
			$this->discounts[ $coupon->get_code() ][ $item->key ] += $discount;
		}
		return $total_discount;
	}
	protected function apply_coupon_fixed_cart( $coupon, $items_to_apply, $amount = null ) {
		$total_discount = 0;
		$amount         = $amount ? $amount : wc_add_number_precision( (float) $coupon->get_amount() );
		$items_to_apply = array_filter( $items_to_apply, array( $this, 'filter_products_with_price' ) );
		$item_count     = array_sum( wp_list_pluck( $items_to_apply, 'quantity' ) );
		if ( ! $item_count ) {
			return $total_discount;
		}
		if ( ! $amount ) {
			$total_discount = $this->apply_coupon_fixed_product( $coupon, $items_to_apply, 0 );
		} else {
			$per_item_discount = absint( $amount / $item_count );
			if ( $per_item_discount > 0 ) {
				$total_discount = $this->apply_coupon_fixed_product( $coupon, $items_to_apply, $per_item_discount );
				if ( $total_discount > 0 && $total_discount < $amount ) {
					$total_discount += $this->apply_coupon_fixed_cart( $coupon, $items_to_apply, $amount - $total_discount );
				}
			} elseif ( $amount > 0 ) {
				$total_discount += $this->apply_coupon_remainder( $coupon, $items_to_apply, $amount );
			}
		}
		return $total_discount;
	}
	protected function apply_coupon_remainder( $coupon, $items_to_apply, $amount ) {
		$total_discount = 0;
		foreach ( $items_to_apply as $item ) {
			$quantity = ceil( is_float( $item->quantity ) ? round( $item->quantity, WC_ROUNDING_PRECISION ) : $item->quantity );
			for ( $i = 0; $i < $quantity; $i++ ) {
				$price_to_discount = $this->get_discounted_price_in_cents( $item );
				$discount          = min( $price_to_discount, 1 );
				$total_discount   += $discount;
				$this->discounts[ $coupon->get_code() ][ $item->key ] += $discount;
				if ( $total_discount >= $amount ) {
					break 2;
				}
			}
			if ( $total_discount >= $amount ) {
				break;
			}
		}
		return $total_discount;
	}
	protected function get_object_subtotal() {
		return wc_add_number_precision( (float) $this->object->get_displayed_subtotal() );
	}

	/** 校验顺序同 WooCommerce；不通过时抛异常，is_coupon_valid 把它变成 WP_Error。 */
	public function is_coupon_valid( $coupon ) {
		try {
			if ( ( ! $coupon->get_id() && ! $coupon->get_virtual() ) || 'trash' === $coupon->get_status() ) {
				throw new Exception( 'not_found', 105 );
			}
			if ( $coupon->get_usage_limit() ) {
				$store     = $coupon->get_data_store();
				$tentative = is_callable( array( $store, 'get_tentative_usage_count' ) ) ? $store->get_tentative_usage_count( $coupon->get_id() ) : 0;
				if ( $coupon->get_usage_count() + $tentative >= $coupon->get_usage_limit() ) {
					throw new Exception( 'usage_limit', 106 );
				}
			}
			$user_id = get_current_user_id();
			if ( $coupon && $user_id && apply_filters( 'woocommerce_coupon_validate_user_usage_limit', $coupon->get_usage_limit_per_user() > 0, $user_id, $coupon, $this ) && $coupon->get_id() && $coupon->get_data_store() ) {
				if ( $coupon->get_data_store()->get_usage_by_user_id( $coupon, $user_id ) >= $coupon->get_usage_limit_per_user() ) {
					throw new Exception( 'user_usage_limit', 106 );
				}
			}
			if ( $coupon->get_date_expires() && apply_filters( 'woocommerce_coupon_validate_expiry_date', time() > $coupon->get_date_expires()->getTimestamp(), $coupon, $this ) ) {
				throw new Exception( 'expired', 107 );
			}
			$subtotal = wc_remove_number_precision( $this->get_object_subtotal() );
			if ( $coupon->get_minimum_amount() > 0 && apply_filters( 'woocommerce_coupon_validate_minimum_amount', $coupon->get_minimum_amount() > $subtotal, $coupon, $subtotal ) ) {
				throw new Exception( 'minimum_amount', 108 );
			}
			if ( $coupon->get_maximum_amount() > 0 && apply_filters( 'woocommerce_coupon_validate_maximum_amount', $coupon->get_maximum_amount() < $subtotal, $coupon ) ) {
				throw new Exception( 'maximum_amount', 112 );
			}
			if ( count( $coupon->get_product_ids() ) > 0 ) {
				$valid = false;
				foreach ( $this->get_items_to_validate() as $item ) {
					if ( $item->product && ( in_array( $item->product->get_id(), $coupon->get_product_ids(), true ) || in_array( $item->product->get_parent_id(), $coupon->get_product_ids(), true ) ) ) {
						$valid = true;
						break;
					}
				}
				if ( ! $valid ) {
					throw new Exception( 'product_ids', 109 );
				}
			}
			if ( count( $coupon->get_product_categories() ) > 0 ) {
				$valid = false;
				foreach ( $this->get_items_to_validate() as $item ) {
					if ( $coupon->get_exclude_sale_items() && $item->product && $item->product->is_on_sale() ) {
						continue;
					}
					$cats = wc_get_product_cat_ids( $item->product->get_id() );
					if ( $item->product->get_parent_id() ) {
						$cats = array_merge( $cats, wc_get_product_cat_ids( $item->product->get_parent_id() ) );
					}
					if ( count( array_intersect( $cats, $coupon->get_product_categories() ) ) > 0 ) {
						$valid = true;
						break;
					}
				}
				if ( ! $valid ) {
					throw new Exception( 'product_categories', 109 );
				}
			}
			$items = $this->get_items_to_validate();
			if ( ! empty( $items ) && $coupon->is_type( wc_get_product_coupon_types() ) ) {
				$valid = false;
				foreach ( $items as $item ) {
					if ( $item->product && $coupon->is_valid_for_product( $item->product, $item->object ) ) {
						$valid = true;
						break;
					}
				}
				if ( ! $valid ) {
					throw new Exception( 'no_eligible_items', 109 );
				}
			}
			if ( ! $coupon->is_type( wc_get_product_coupon_types() ) ) {
				if ( $coupon->get_exclude_sale_items() ) {
					foreach ( $items as $item ) {
						if ( $item->product && $item->product->is_on_sale() ) {
							throw new Exception( 'sale_items', 110 );
						}
					}
				}
				if ( count( $coupon->get_excluded_product_ids() ) > 0 ) {
					foreach ( $items as $item ) {
						if ( $item->product && in_array( $item->product->get_id(), $coupon->get_excluded_product_ids(), true ) || in_array( $item->product->get_parent_id(), $coupon->get_excluded_product_ids(), true ) ) {
							throw new Exception( 'excluded_product_ids', 113 );
						}
					}
				}
				if ( count( $coupon->get_excluded_product_categories() ) > 0 ) {
					foreach ( $items as $item ) {
						$cats = wc_get_product_cat_ids( $item->product->get_id() );
						if ( $item->product->get_parent_id() ) {
							$cats = array_merge( $cats, wc_get_product_cat_ids( $item->product->get_parent_id() ) );
						}
						if ( count( array_intersect( $cats, $coupon->get_excluded_product_categories() ) ) > 0 ) {
							throw new Exception( 'excluded_product_categories', 114 );
						}
					}
				}
			}
			$restrictions = $coupon->get_email_restrictions();
			if ( is_array( $restrictions ) && ! empty( $restrictions ) ) {
				$check   = array_unique( array_filter( array_map( 'strtolower', array_map( 'sanitize_email', array( wp_get_current_user()->user_email, $this->object->get_customer()->get_billing_email() ) ) ) ) );
				$allowed = false;
				foreach ( $check as $email ) {
					if ( in_array( $email, $restrictions, true ) ) {
						$allowed = true;
					}
					foreach ( $restrictions as $restriction ) {
						if ( preg_match( '/^' . str_replace( '*', '(.+)?', $restriction ) . '$/', $email ) ) {
							$allowed = true;
						}
					}
				}
				if ( ! $allowed ) {
					throw new Exception( 'not_yours', 102 );
				}
			}
			if ( ! apply_filters( 'woocommerce_coupon_is_valid', true, $coupon, $this ) ) {
				throw new Exception( 'invalid', 100 );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'invalid_coupon', $e->getMessage() );
		}
		return true;
	}
}

final class WC_Cart_Totals {
	protected $cart;
	protected $items                  = array();
	protected $fees                   = array();
	protected $shipping               = array();
	protected $coupons                = array();
	protected $coupon_discount_totals = array();
	protected $totals                 = array( 'fees_total' => 0, 'items_subtotal' => 0, 'items_total' => 0, 'total' => 0, 'shipping_total' => 0, 'discounts_total' => 0 );

	public function __construct( &$cart = null ) {
		if ( ! is_a( $cart, 'WC_Cart' ) ) {
			throw new Exception( 'A valid WC_Cart object is required' );
		}
		$this->cart = $cart;
		// calculate_item_totals
		foreach ( $this->cart->get_cart() as $key => $cart_item ) {
			$item                = new stdClass();
			$item->key           = $key;
			$item->object        = $cart_item;
			$item->quantity      = $cart_item['quantity'];
			$item->price         = wc_add_number_precision_deep( (float) $cart_item['data']->get_price() * (float) $cart_item['quantity'] );
			$item->product       = $cart_item['data'];
			$item->subtotal      = 0;
			$item->total         = 0;
			$this->items[ $key ] = $item;
		}
		foreach ( $this->items as $key => $item ) {
			$item->subtotal                                    = $item->price;
			$this->cart->cart_contents[ $key ]['line_subtotal'] = wc_remove_number_precision( $item->subtotal );
		}
		$this->totals['items_subtotal'] = $this->get_rounded_items_total( array_values( wp_list_pluck( $this->items, 'subtotal' ) ) );
		$this->cart->set_subtotal( $this->get_total( 'items_subtotal' ) );
		$this->calculate_discounts();
		foreach ( $this->items as $key => $item ) {
			$item->total                                    = isset( $this->coupon_discount_totals[ $key ] ) ? $item->price - $this->coupon_discount_totals[ $key ] : $item->price;
			$this->cart->cart_contents[ $key ]['line_total'] = wc_remove_number_precision( $item->total );
		}
		$this->totals['items_total'] = $this->get_rounded_items_total( array_values( wp_list_pluck( $this->items, 'total' ) ) );
		$this->cart->set_cart_contents_total( $this->get_total( 'items_total' ) );
		// calculate_shipping_totals
		foreach ( $this->cart->calculate_shipping() as $rate ) {
			$this->shipping[] = wc_add_number_precision_deep( $rate->cost );
		}
		$this->totals['shipping_total'] = array_sum( $this->shipping );
		$this->cart->set_shipping_total( $this->get_total( 'shipping_total' ) );
		// calculate_fee_totals
		$this->cart->calculate_fees();
		$running = 0;
		foreach ( $this->cart->get_fees() as $fee_key => $fee_object ) {
			$fee         = new stdClass();
			$fee->object = $fee_object;
			$fee->total  = wc_add_number_precision_deep( $fee->object->amount );
			if ( 0 > $fee->total ) {
				$max_discount = m4_wc_round( $this->get_total( 'items_total', true ) + $running + $this->get_total( 'shipping_total', true ) ) * -1;
				if ( $fee->total < $max_discount ) {
					$fee->total = $max_discount;
				}
			}
			$running               += $fee->total;
			$fee->object->total     = wc_remove_number_precision_deep( $fee->total );
			$this->fees[ $fee_key ] = $fee;
		}
		$this->totals['fees_total'] = array_sum( wp_list_pluck( $this->fees, 'total' ) );
		$this->cart->fees_api()->set_fees( wp_list_pluck( $this->fees, 'object' ) );
		$this->cart->set_fee_total( wc_remove_number_precision_deep( array_sum( wp_list_pluck( $this->fees, 'total' ) ) ) );
		// calculate_totals
		$this->totals['total'] = m4_wc_round( $this->get_total( 'items_total', true ) + $this->get_total( 'fees_total', true ) + $this->get_total( 'shipping_total', true ), 0 );
		$this->cart->set_total( max( 0, apply_filters( 'woocommerce_calculated_total', $this->get_total( 'total' ), $this->cart ) ) );
	}
	public function get_total( $key = 'total', $in_cents = false ) {
		$totals = $in_cents ? $this->totals : wc_remove_number_precision_deep( $this->totals );
		return isset( $totals[ $key ] ) ? $totals[ $key ] : 0;
	}
	protected function get_rounded_items_total( $values ) {
		return array_sum( array_map( static fn( $v ) => 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ? $v : m4_wc_round( $v ), $values ) );
	}
	protected function calculate_discounts() {
		$this->coupons = $this->cart->get_coupons();
		$positions     = array();
		$sequential    = count( $this->coupons ) > 1 && 'yes' === get_option( 'woocommerce_calc_discounts_sequentially' );
		$position      = 0;
		foreach ( $this->coupons as $coupon ) {
			if ( $sequential ) {
				$positions[ spl_object_id( $coupon ) ] = $position++;
			}
			$sort         = array( 'fixed_product' => 1, 'percent' => 2, 'fixed_cart' => 3 );
			$coupon->sort = apply_filters( 'woocommerce_coupon_sort', $sort[ $coupon->get_discount_type() ] ?? 0, $coupon );
		}
		uasort(
			$this->coupons,
			static function ( $a, $b ) use ( $positions ) {
				if ( $a->sort === $b->sort ) {
					if ( $a->get_limit_usage_to_x_items() === $b->get_limit_usage_to_x_items() ) {
						$a_position = $positions[ spl_object_id( $a ) ] ?? null;
						$b_position = $positions[ spl_object_id( $b ) ] ?? null;
						if ( null !== $a_position && null !== $b_position && $a_position !== $b_position ) {
							return $a_position <=> $b_position;
						}
						if ( $a->get_amount() === $b->get_amount() ) {
							return $b->get_id() - $a->get_id();
						}
						return ( $a->get_amount() < $b->get_amount() ) ? -1 : 1;
					}
					return ( $a->get_limit_usage_to_x_items() < $b->get_limit_usage_to_x_items() ) ? -1 : 1;
				}
				return ( $a->sort < $b->sort ) ? -1 : 1;
			}
		);
		$discounts = new WC_Discounts( $this->cart );
		$discounts->set_items( $this->items );
		foreach ( $this->coupons as $coupon ) {
			$discounts->apply_coupon( $coupon );
		}
		$this->coupon_discount_totals    = (array) $discounts->get_discounts_by_item( true );
		$this->totals['discounts_total'] = array_sum( $this->coupon_discount_totals );
		$this->cart->set_coupon_discount_totals( wc_remove_number_precision_deep( $discounts->get_discounts_by_coupon( true ) ) );
		$this->cart->set_discount_total( $this->get_total( 'discounts_total' ) );
	}
}
