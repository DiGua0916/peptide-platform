<?php
/**
 * 优惠码的 Shopname 规则（COUPON-02）。优惠码仍然是 WooCommerce 的优惠券，这里补上 WooCommerce 没有的规则：
 *
 * - 优惠规则策略（_pc_strategy）：
 *   no_stack 不叠加满减活动：购物车里有满减活动的优惠时，这个优惠码不能用；
 *   stack    叠加满减活动：两种优惠都生效；
 *   replace  替换满减活动：用了这个优惠码，满减活动就不生效。
 *   没有这个设置的旧优惠码按“叠加”处理（和改版前一样）。
 * - 开始时间（_pc_start）：WooCommerce 只有到期时间。
 * - 全局首单（_pc_first_order）：只有在本店没有下过单（按账户和结账邮箱）的顾客能用。
 * - 优惠条件：满金额（_pc_min_spend）/ 满件数（_pc_min_qty），只算这个优惠码适用的商品。
 * - 活动商品默认排序（_pc_sort）：优惠码详情页里商品的排序。
 * - 显示用的优惠码（_pc_code_label）：WooCommerce 把优惠码存成小写，这里记下商家输入的大小写（例如 VialCove），后台和前台按这个显示。
 *
 * 检查都在 woocommerce_coupon_is_valid 里，加入购物车、每次计算总价和结账（经典结账和区块结账）时都会检查。
 * 结账时顾客邮箱已经填好，所以“全局首单”在结账时一定会按邮箱再查一次。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Coupon_Rules {

	public const STRATEGY_META  = '_pc_strategy';
	public const START_META     = '_pc_start';
	public const FIRST_META     = '_pc_first_order';
	public const MIN_SPEND_META = '_pc_min_spend';
	public const MIN_QTY_META   = '_pc_min_qty';
	public const SORT_META      = '_pc_sort';
	public const LABEL_META     = '_pc_code_label';

	public const STRATEGIES = array( 'no_stack', 'stack', 'replace' );

	public static function register_hooks(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( self::class, 'validate' ), 10, 3 );
	}

	public static function strategy( \WC_Coupon $coupon ): string {
		$s = $coupon->get_id() ? (string) get_post_meta( $coupon->get_id(), self::STRATEGY_META, true ) : '';
		return in_array( $s, self::STRATEGIES, true ) ? $s : 'stack';
	}

	/** 显示用的优惠码：商家输入的大小写；没有记下时用大写。 */
	public static function display_code( \WC_Coupon $coupon ): string {
		$label = $coupon->get_id() ? (string) get_post_meta( $coupon->get_id(), self::LABEL_META, true ) : '';
		return '' !== $label && wc_strtolower( $label ) === wc_strtolower( $coupon->get_code() ) ? $label : strtoupper( $coupon->get_code() );
	}

	public static function start( \WC_Coupon $coupon ): int {
		return $coupon->get_id() ? (int) get_post_meta( $coupon->get_id(), self::START_META, true ) : 0;
	}

	/** off（停用）/ upcoming / active / ended（过期或次数用完）。 */
	public static function status( \WC_Coupon $coupon, ?int $now = null ): string {
		$now = $now ?? time();
		if ( 'publish' !== get_post_status( $coupon->get_id() ) ) {
			return 'off';
		}
		$start = self::start( $coupon );
		if ( $start && $now < $start ) {
			return 'upcoming';
		}
		$expires = $coupon->get_date_expires();
		if ( $expires && $expires->getTimestamp() <= $now ) {
			return 'ended';
		}
		if ( $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
			return 'ended';
		}
		return 'active';
	}

	/** 商品是否在优惠码的适用范围内（指定商品 / 专辑、排除的商品 / 专辑、促销商品）。 */
	public static function covers( \WC_Coupon $coupon, \WC_Product $product ): bool {
		$id     = $product->get_id();
		$parent = $product->get_parent_id() ? $product->get_parent_id() : $id;
		$cats   = wc_get_product_cat_ids( $parent );
		if ( $coupon->get_product_ids() && ! array_intersect( array( $id, $parent ), $coupon->get_product_ids() ) ) {
			return false;
		}
		if ( $coupon->get_product_categories() && ! array_intersect( $cats, $coupon->get_product_categories() ) ) {
			return false;
		}
		if ( array_intersect( array( $id, $parent ), $coupon->get_excluded_product_ids() ) || array_intersect( $cats, $coupon->get_excluded_product_categories() ) ) {
			return false;
		}
		return ! ( $coupon->get_exclude_sale_items() && $product->is_on_sale() );
	}

	/** @return array{0:int,1:float} 购物车里适用这个优惠码的商品件数和小计。 */
	public static function eligible( \WC_Coupon $coupon, \WC_Cart $cart ): array {
		$qty      = 0;
		$subtotal = 0.0;
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof \WC_Product && self::covers( $coupon, $product ) ) {
				$qty      += (int) ( $item['quantity'] ?? 0 );
				$subtotal += (float) ( $item['line_subtotal'] ?? 0 );
			}
		}
		return array( $qty, $subtotal );
	}

	/** 这位顾客（账户或邮箱）以前有没有下过单（待付款、已取消、失败的不算）。 */
	public static function has_ordered( string $email, int $user_id ): bool {
		$statuses = array_unique( array_merge( wc_get_is_paid_statuses(), array( 'on-hold', 'refunded' ) ) );
		if ( $user_id && wc_get_orders( array( 'customer_id' => $user_id, 'status' => $statuses, 'limit' => 1, 'return' => 'ids' ) ) ) {
			return true;
		}
		return '' !== $email && (bool) wc_get_orders( array( 'billing_email' => $email, 'status' => $statuses, 'limit' => 1, 'return' => 'ids' ) );
	}

	/** 当前顾客：[邮箱, 用户 ID]。邮箱优先用结账里填的，登录顾客没填时用账户邮箱。 */
	private static function customer(): array {
		$user  = get_current_user_id();
		$email = function_exists( 'WC' ) && WC()->customer ? (string) WC()->customer->get_billing_email() : '';
		if ( '' === $email && $user ) {
			$email = (string) wp_get_current_user()->user_email;
		}
		return array( strtolower( trim( $email ) ), $user );
	}

	private static function money( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ) );
	}

	/**
	 * woocommerce_coupon_is_valid：抛出的异常信息会作为提示显示给顾客（WooCommerce 会把优惠码从购物车里去掉）。
	 *
	 * @param bool              $valid
	 * @param \WC_Coupon        $coupon
	 * @param \WC_Discounts|null $discounts
	 * @throws \Exception 优惠码不能用时。
	 */
	public static function validate( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || ! $coupon->get_id() ) {
			return $valid;
		}
		$start = self::start( $coupon );
		if ( $start && time() < $start ) {
			/* translators: %s: date and time */
			throw new \Exception( sprintf( __( 'This code can be used from %s.', 'peptide-core' ), wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $start ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WooCommerce 显示时会转义。
		}
		$cart = $discounts instanceof \WC_Discounts ? $discounts->get_object() : null;
		if ( ! $cart instanceof \WC_Cart ) {
			return $valid; // 后台给订单加优惠码：只检查时间。
		}
		$id                = $coupon->get_id();
		[ $qty, $subtotal ] = self::eligible( $coupon, $cart );
		$min_qty           = (int) get_post_meta( $id, self::MIN_QTY_META, true );
		if ( $min_qty > 0 && $qty < $min_qty ) {
			/* translators: %d: number of items */
			throw new \Exception( sprintf( _n( 'This code needs at least %d eligible item in the cart.', 'This code needs at least %d eligible items in the cart.', $min_qty, 'peptide-core' ), $min_qty ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WooCommerce 显示时会转义。
		}
		$min_spend = (float) get_post_meta( $id, self::MIN_SPEND_META, true );
		if ( $min_spend > 0 && $subtotal + 0.0001 < $min_spend ) {
			/* translators: %s: amount */
			throw new \Exception( sprintf( __( 'This code needs at least %s of eligible items in the cart.', 'peptide-core' ), self::money( $min_spend ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WooCommerce 显示时会转义。
		}
		if ( 'yes' === get_post_meta( $id, self::FIRST_META, true ) ) {
			[ $email, $user ] = self::customer();
			if ( self::has_ordered( $email, $user ) ) {
				throw new \Exception( __( 'This code is for a first order only.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
		if ( 'no_stack' === self::strategy( $coupon ) ) {
			$applied = Promotions::discounts( $cart, $coupon->get_code() )['applied'];
			if ( $applied ) {
				/* translators: %s: promotion name */
				throw new \Exception( sprintf( __( 'This code cannot be combined with the promotion “%s” in your cart.', 'peptide-core' ), $applied[0]['title'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WooCommerce 显示时会转义。
			}
		}
		return $valid;
	}

	/** 后台列表里的“优惠规则”，例如“无门槛，5% off”。 */
	public static function rule_text( \WC_Coupon $coupon ): string {
		$id        = $coupon->get_id();
		$min_spend = (float) get_post_meta( $id, self::MIN_SPEND_META, true );
		$min_qty   = (int) get_post_meta( $id, self::MIN_QTY_META, true );
		if ( $min_spend > 0 ) {
			/* translators: %s: amount */
			$cond = sprintf( __( 'Spend %s', 'peptide-core' ), self::money( $min_spend ) );
		} elseif ( $min_qty > 0 ) {
			/* translators: %d: number of items */
			$cond = sprintf( _n( 'Buy %d item', 'Buy %d items', $min_qty, 'peptide-core' ), $min_qty );
		} elseif ( (float) $coupon->get_minimum_amount() > 0 ) {
			/* translators: %s: amount */
			$cond = sprintf( __( 'Spend %s', 'peptide-core' ), self::money( (float) $coupon->get_minimum_amount() ) );
		} else {
			$cond = __( 'No minimum', 'peptide-core' );
		}
		$amount = (float) $coupon->get_amount();
		if ( 'percent' === $coupon->get_discount_type() ) {
			/* translators: %s: percentage */
			$value = sprintf( __( '%s%% off', 'peptide-core' ), wc_format_localized_decimal( $amount ) );
		} elseif ( 'fixed_product' === $coupon->get_discount_type() ) {
			/* translators: %s: amount */
			$value = sprintf( __( '%s off each eligible item', 'peptide-core' ), self::money( $amount ) );
		} else {
			/* translators: %s: amount */
			$value = sprintf( __( '%s off', 'peptide-core' ), self::money( $amount ) );
		}
		if ( $coupon->get_free_shipping() ) {
			$value .= ' + ' . __( 'free shipping', 'peptide-core' );
		}
		return $cond . ', ' . $value;
	}
}
