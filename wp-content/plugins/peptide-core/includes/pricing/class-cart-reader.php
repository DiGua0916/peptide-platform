<?php
/**
 * 价格引擎 · 读取 WooCommerce 购物车（M4 / NF-02）。只读：把购物车现在的状态整理成 Engine::price() 的输入，
 * 不改购物车、不写会话、不发通知。需要查数据库的事实（优惠码用过几次、有没有下过单、积分流水、库存）在这里查好。
 *
 * 引擎算不了的情况记在 unsupported 里（价格含税、别的插件加的费用或价格过滤器、非原生的优惠码类型），
 * 这时影子模式只记“跳过”，开启模式退回旧逻辑。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

use PeptideCore\Coupon_Rules;
use PeptideCore\Customer_Segments;
use PeptideCore\First_Order_Offer;
use PeptideCore\Loyalty;
use PeptideCore\Product_Offers;
use PeptideCore\Promotions;
use PeptideCore\Shipping\Discounts;

defined( 'ABSPATH' ) || exit;

final class Cart_Reader {

	/** 这些过滤器被别的代码挂上时，原生的金额算法已经不是引擎照着写的那一套。 */
	private const FOREIGN_FILTERS = array(
		'woocommerce_coupon_get_discount_amount',
		'woocommerce_coupon_get_apply_quantity',
		'woocommerce_coupon_get_items_to_apply',
		'woocommerce_coupon_get_items_to_validate',
		'woocommerce_coupon_sort',
		'woocommerce_coupon_validate_minimum_amount',
		'woocommerce_coupon_validate_maximum_amount',
		'woocommerce_coupon_validate_expiry_date',
		'woocommerce_coupon_validate_user_usage_limit',
		'woocommerce_coupon_is_valid_for_cart',
		'woocommerce_get_discounted_price',
		'woocommerce_calculated_total',
		'woocommerce_sort_fees_callback',
	);

	public static function read( \WC_Cart $cart, ?int $now = null ): array {
		$now         = $now ?? time();
		$unsupported = array();
		if ( wc_prices_include_tax() ) {
			$unsupported[] = 'prices_include_tax';
		}
		if ( wc_tax_enabled() && $cart->display_prices_including_tax() ) {
			$unsupported[] = 'display_including_tax';
		}
		foreach ( self::FOREIGN_FILTERS as $hook ) {
			if ( has_filter( $hook ) ) {
				$unsupported[] = 'filter:' . $hook;
			}
		}
		foreach ( $cart->get_fees() as $fee ) {
			if ( ! is_object( $fee ) || ! preg_match( '/^pc-promo-[1-9][0-9]*$/D', (string) ( $fee->id ?? '' ) ) ) {
				$unsupported[] = 'foreign_fee';
				break;
			}
		}

		// ---- 商品行 ----
		$facts = Product_Offers::pricing_facts( $cart );
		$lines = self::lines( $cart, $facts );
		if ( count( $lines ) !== count( $cart->get_cart() ) ) {
			$unsupported[] = 'line';
		}

		// ---- 顾客 ----
		$wc       = function_exists( 'WC' ) ? WC() : null;
		$customer = self::customer( $cart );
		$user_id  = (int) $customer['user_id'];
		$email    = (string) $customer['email'];

		// ---- 优惠码 ----
		$coupons    = array();
		$first      = First_Order_Offer::settings();
		$recommend  = self::recommendation_coupon_id();
		$exit_code  = $wc && $wc->session ? wc_format_coupon_code( (string) $wc->session->get( \PeptideCore\Exit_Intent::SESSION_KEY . '_code', '' ) ) : '';
		$need_order = false;
		$rules      = array();
		$loaded     = $cart->get_coupons();
		foreach ( $cart->get_applied_coupons() as $code ) {
			$c = $loaded[ $code ] ?? new \WC_Coupon( $code );
			if ( ! in_array( $c->get_discount_type(), array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ) {
				$unsupported[] = 'coupon_type';
			}
			$id      = (int) $c->get_id();
			$expires = $c->get_date_expires();
			$store   = $id ? $c->get_data_store() : null;
			$row     = array(
				'code'           => (string) $c->get_code(),
				'id'             => $id,
				'virtual'        => method_exists( $c, 'get_virtual' ) ? (bool) $c->get_virtual() : false,
				'status'         => method_exists( $c, 'get_status' ) ? (string) $c->get_status() : 'publish',
				'type'           => (string) $c->get_discount_type(),
				'amount'         => $c->get_amount(),
				'limit_x'        => $c->get_limit_usage_to_x_items(),
				'individual_use' => (bool) $c->get_individual_use(),
				'free_shipping'  => (bool) $c->get_free_shipping(),
				'product_ids'    => (array) $c->get_product_ids(),
				'excluded_ids'   => (array) $c->get_excluded_product_ids(),
				'cats'           => (array) $c->get_product_categories(),
				'excluded_cats'  => (array) $c->get_excluded_product_categories(),
				'exclude_sale'   => (bool) $c->get_exclude_sale_items(),
				'min'            => (float) $c->get_minimum_amount(),
				'max'            => (float) $c->get_maximum_amount(),
				'expires'        => $expires ? (int) $expires->getTimestamp() : 0,
				'usage_limit'    => (int) $c->get_usage_limit(),
				'usage_count'    => (int) $c->get_usage_count(),
				'tentative'      => $c->get_usage_limit() && $store && is_callable( array( $store, 'get_tentative_usage_count' ) ) ? (int) $store->get_tentative_usage_count( $id ) : 0,
				'limit_per_user' => (int) $c->get_usage_limit_per_user(),
				'used_by_user'   => $user_id && $id && $c->get_usage_limit_per_user() > 0 && $store && is_callable( array( $store, 'get_usage_by_user_id' ) ) ? (int) $store->get_usage_by_user_id( $c, $user_id ) : 0,
				'emails'         => (array) $c->get_email_restrictions(),
				'strategy'       => Coupon_Rules::strategy( $c ),
				'start'          => Coupon_Rules::start( $c ),
				'first_order'    => $id && 'yes' === get_post_meta( $id, Coupon_Rules::FIRST_META, true ),
				'min_spend'      => $id ? (float) get_post_meta( $id, Coupon_Rules::MIN_SPEND_META, true ) : 0.0,
				'min_qty'        => $id ? (int) get_post_meta( $id, Coupon_Rules::MIN_QTY_META, true ) : 0,
				'customer_rule'  => null,
				'origin'         => 'coupon',
				'loyalty'        => null,
				'first_offer'    => null,
			);
			if ( $id ) {
				$rule = get_post_meta( $id, Customer_Segments::COUPON_META, true );
				if ( is_array( $rule ) ) {
					$row['customer_rule'] = $rule;
					$rules[]              = $rule;
				}
			}
			if ( $c->get_meta( '_pc_loyalty_user' ) ) {
				$row['loyalty'] = Loyalty::coupon_facts( $c );
				$row['origin']  = 'loyalty';
			} elseif ( $first['enabled'] && $first['coupon_id'] === $id ) {
				$row['first_offer'] = array( 'issue' => First_Order_Offer::coupon_issue( $c ) );
				$row['origin']      = 'first_order';
			} elseif ( $recommend && $recommend === $id ) {
				$row['origin'] = 'recommendation';
			} elseif ( '' !== $exit_code && $exit_code === $row['code'] ) {
				$row['origin'] = 'exit_intent';
			}
			// 不带“首单券”设置的券也可能勾了“全局首单”。
			$need_order = $need_order || $row['first_order'];
			$coupons[]  = $row;
		}
		if ( $need_order ) {
			$customer['has_ordered'] = Coupon_Rules::has_ordered( $email, $user_id );
		}

		// ---- 满减活动 ----
		$promotions = self::promotions( $now );
		foreach ( $promotions as $c ) {
			$rules[] = (array) ( $c['customer_rule'] ?? array() );
		}

		// ---- 运费 ----
		$discounts = array_values( Discounts::all() );
		$rules     = array_merge( $rules, $discounts );
		$rates     = array();
		if ( $cart->needs_shipping() && $cart->show_shipping() && $wc && $wc->session && is_callable( array( $wc, 'shipping' ) ) ) {
			$chosen = (array) $wc->session->get( 'chosen_shipping_methods', array() );
			foreach ( (array) $wc->shipping()->get_packages() as $i => $package ) {
				$rate = isset( $chosen[ $i ] ) ? ( $package['rates'][ $chosen[ $i ] ] ?? null ) : null;
				if ( $rate instanceof \WC_Shipping_Rate ) {
					$meta    = (array) $rate->get_meta_data();
					$rates[] = array( 'id' => (string) $rate->get_id(), 'cost' => (float) ( $meta[ Discounts::META_COST ] ?? $rate->get_cost() ), 'charged' => (float) $rate->get_cost() );
				}
			}
		}

		$customer['visitor_id'] = self::visitor_id( $rules );

		return array(
			'now'               => $now,
			'decimals'          => wc_get_price_decimals(),
			'sequential'        => 'yes' === get_option( 'woocommerce_calc_discounts_sequentially', 'no' ),
			'round_at_subtotal' => 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ),
			'tax_enabled'       => wc_tax_enabled(),
			'unsupported'       => array_values( array_unique( $unsupported ) ),
			'customer'          => $customer,
			'lines'             => $lines,
			'coupons'           => $coupons,
			'promotions'        => $promotions,
			'offers'            => $facts['offers'],
			'offer_groups'      => $facts['groups'],
			'shipping'          => array( 'rates' => $rates, 'discounts' => $discounts, 'weekday' => (int) wp_date( 'N', $now ) ),
		);
	}

	/**
	 * 商品行。$facts 是 Product_Offers::pricing_facts() 的结果。
	 *
	 * @return array<string,array>
	 */
	public static function lines( \WC_Cart $cart, array $facts ): array {
		$lines = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$fact          = $facts['lines'][ $key ] ?? array( 'has_meta' => false, 'offer' => null, 'native' => null, 'sellable' => false );
			$parent        = $product->get_parent_id();
			$lines[ $key ] = array(
				'product_id' => $product->get_id(),
				'parent_id'  => $parent,
				'cats'       => wc_get_product_cat_ids( $parent ? $parent : $product->get_id() ),
				'on_sale'    => $product->is_on_sale(),
				'qty'        => $item['quantity'],
				// 有赠品 / 捆绑标记的行，旧逻辑每次都从商品的原价重新算起。
				'price'      => (float) ( $fact['has_meta'] && null !== $fact['native'] ? $fact['native'] : $product->get_price() ),
				'sellable'   => $fact['sellable'],
				'offer'      => $fact['offer'],
				'has_meta'   => $fact['has_meta'],
			);
		}
		return $lines;
	}

	/** 当前顾客（不含“有没有下过单”“访客编号”，这两项要查库，用到时再查）。 */
	public static function customer( ?\WC_Cart $cart ): array {
		$user_id = get_current_user_id();
		$wc      = function_exists( 'WC' ) ? WC() : null;
		$billing = $wc && $wc->customer ? (string) $wc->customer->get_billing_email() : '';
		$account = $user_id ? (string) wp_get_current_user()->user_email : '';
		$profile = $user_id ? Customer_Segments::profile( $user_id ) : array( 'tags' => array(), 'level' => '' );
		$clean   = static fn( string $v ): string => strtolower( function_exists( 'sanitize_email' ) ? (string) sanitize_email( $v ) : $v );
		$cart_c  = $cart ? $cart->get_customer() : null;
		return array(
			'user_id'       => $user_id,
			'email'         => strtolower( trim( '' !== $billing ? $billing : $account ) ),
			'segment_email' => Customer_Segments::current_email(),
			'account_email' => $clean( $account ),
			'billing_email' => $clean( $cart_c ? (string) $cart_c->get_billing_email() : $billing ),
			'has_ordered'   => false,
			'tags'          => $profile['tags'],
			'level'         => $profile['level'],
			'visitor_id'    => 0,
		);
	}

	/** 有规则按“指定访客”匹配时才去查访客编号。 */
	public static function visitor_id( array $rules ): int {
		foreach ( $rules as $rule ) {
			if ( 'visitor' === ( $rule['customers'] ?? 'all' ) ) {
				return Customer_Segments::current_visitor_id();
			}
		}
		return 0;
	}

	/** 进行中的满减活动：ID => 设置（带给顾客看的标题），创建时间最近的在前。 */
	public static function promotions( ?int $now = null ): array {
		$out = array();
		foreach ( Promotions::active_ids( $now ) as $id ) {
			$c            = Promotions::config( $id );
			$c['title']   = Promotions::customer_title( html_entity_decode( get_the_title( $id ) ) );
			$out[ $id ]   = $c;
		}
		return $out;
	}

	/** 只要“挂了哪些码、各自的叠加策略”时用（满减的归属和可用性只看这两样）。 */
	public static function coupon_strategies( \WC_Cart $cart ): array {
		$out = array();
		foreach ( $cart->get_applied_coupons() as $code ) {
			$code  = wc_format_coupon_code( $code );
			$out[] = array( 'code' => $code, 'strategy' => Coupon_Rules::strategy( new \WC_Coupon( $code ) ) );
		}
		return $out;
	}

	/** 加购推荐配置里的优惠码 ID（没开时为 0）。 */
	private static function recommendation_coupon_id(): int {
		try {
			if ( ! class_exists( \PeptideCore\Activity\Store::class ) ) {
				return 0;
			}
			$config = (array) ( \PeptideCore\Activity\Store::effective()['recommendationCoupon'] ?? array() );
			return ! empty( $config['enabled'] ) ? (int) ( $config['couponId'] ?? 0 ) : 0;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}
}
