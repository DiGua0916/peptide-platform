<?php
/**
 * 运费折扣（SHIPPING-DISCOUNT-01），对照 Shopname 的“营销活动 → 运费折扣”。
 *
 * - 活动类型：固定折扣（一个时间段或长期，一个折扣）/ 限时阶段折扣（最多 5 个时间段，每段自己的折扣）。
 * - 折扣类型：运费减元（立减 X）/ 运费打折（X% off，100 = 免运费）。运费减到 0 为止。
 * - 仅会员日有效：只在选定的星期几（店铺时区）对已登录的注册顾客有效。
 * - 适用顾客：所有人 / 指定顾客（按结账邮箱或账户邮箱）。
 * - 同时满足多个折扣活动时，每个运费取折扣后最低的那个（Shopname：“取最低折扣值”）。
 *
 * 结账时在 woocommerce_package_rates 里改运费（在物流方案之后），税按比例同步减少；
 * 订单的运费行记下折扣名称和原运费。运费缓存的键里带上当前可用的折扣，换邮箱、活动开始或结束时会重新计算。
 *
 * 数据存在选项 peptide_core_shipping_discounts（不自动加载）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Shipping;

defined( 'ABSPATH' ) || exit;

final class Discounts {

	public const OPTION     = 'peptide_core_shipping_discounts';
	public const MAX_STAGES = 5;
	public const NAME_MAX   = 50;
	public const META_NAME  = '_pc_ship_discount';
	public const META_COST  = '_pc_ship_original_cost';

	public static function register_hooks(): void {
		add_filter( 'woocommerce_package_rates', array( self::class, 'apply' ), 120, 2 );
		add_filter( 'woocommerce_cart_shipping_packages', array( self::class, 'tag_packages' ), 20 );
	}

	// ---------- 数据 ----------

	public static function defaults(): array {
		return array(
			'id'          => 0,
			'name'        => '',
			'enabled'     => true,
			'mode'        => 'fixed',  // fixed：固定折扣；stages：限时阶段折扣
			'member_day'  => false,
			'member_days' => array(),  // ISO 星期：1 = 星期一 … 7 = 星期日
			'start'       => 0,        // UTC 时间戳
			'end'         => 0,        // 0 = 长期
			'kind'        => 'amount', // amount：运费减元；percent：运费打折
			'value'       => 0.0,
			'stages'      => array(),  // [{start, end, value}]
			'customers'   => 'all',    // all / emails
			'emails'      => array(),
			'created'     => 0,
		);
	}

	/** @return array<int,array> 新建的在前。 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $d ) {
			$d = array_merge( self::defaults(), (array) $d );
			if ( (int) $d['id'] ) {
				$out[ (int) $d['id'] ] = $d;
			}
		}
		krsort( $out );
		return $out;
	}

	public static function get( int $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * 清理后台提交的内容。返回 [discount, errors]。
	 *
	 * @return array{0:array,1:string[]}
	 */
	public static function normalize( array $raw ): array {
		$errors = array();
		$d      = self::defaults();
		$d['id']      = absint( $raw['id'] ?? 0 );
		$d['name']    = trim( sanitize_text_field( (string) ( $raw['name'] ?? '' ) ) );
		$d['enabled'] = ! array_key_exists( 'enabled', $raw ) || ! empty( $raw['enabled'] );
		$d['created'] = absint( $raw['created'] ?? 0 );
		if ( '' === $d['name'] ) {
			$errors[] = __( 'Enter a discount name.', 'peptide-core' );
		} elseif ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $d['name'] ) : strlen( $d['name'] ) ) > self::NAME_MAX ) {
			/* translators: %d: number of characters */
			$errors[] = sprintf( __( 'The name can have at most %d characters.', 'peptide-core' ), self::NAME_MAX );
		}
		$d['mode'] = 'stages' === ( $raw['mode'] ?? '' ) ? 'stages' : 'fixed';
		$d['kind'] = 'percent' === ( $raw['kind'] ?? '' ) ? 'percent' : 'amount';
		$check     = function ( $v ) use ( $d, &$errors ): float {
			$v = is_numeric( $v ) ? round( (float) $v, 2 ) : 0.0;
			if ( $v <= 0 ) {
				$errors[] = __( 'Enter a discount greater than zero.', 'peptide-core' );
			} elseif ( 'percent' === $d['kind'] && $v > 100 ) {
				$errors[] = __( 'A shipping discount can be at most 100% (free shipping).', 'peptide-core' );
			}
			return $v;
		};
		if ( 'fixed' === $d['mode'] ) {
			$d['value'] = $check( $raw['value'] ?? 0 );
			$d['start'] = max( 0, (int) ( $raw['start'] ?? 0 ) );
			$d['end']   = max( 0, (int) ( $raw['end'] ?? 0 ) );
			if ( $d['start'] && $d['end'] && $d['end'] <= $d['start'] ) {
				$errors[] = __( 'The end time must be after the start time.', 'peptide-core' );
			}
		} else {
			$stages = array();
			foreach ( is_array( $raw['stages'] ?? null ) ? $raw['stages'] : array() as $st ) {
				$s = max( 0, (int) ( $st['start'] ?? 0 ) );
				$e = max( 0, (int) ( $st['end'] ?? 0 ) );
				if ( ! $s && ! $e && ! is_numeric( $st['value'] ?? null ) ) {
					continue; // 空行
				}
				if ( ! $s || ! $e || $e <= $s ) {
					$errors[] = __( 'Each stage needs a start and an end time, and the end must be after the start.', 'peptide-core' );
					continue;
				}
				$stages[] = array( 'start' => $s, 'end' => $e, 'value' => $check( $st['value'] ?? 0 ) );
			}
			usort( $stages, static fn( $a, $b ) => $a['start'] <=> $b['start'] );
			for ( $i = 1; $i < count( $stages ); $i++ ) {
				if ( $stages[ $i ]['start'] < $stages[ $i - 1 ]['end'] ) {
					$errors[] = __( 'The stages must not overlap.', 'peptide-core' );
					break;
				}
			}
			if ( ! $stages ) {
				$errors[] = __( 'Add at least one stage.', 'peptide-core' );
			}
			if ( count( $stages ) > self::MAX_STAGES ) {
				/* translators: %d: number of stages */
				$errors[] = sprintf( __( 'At most %d stages.', 'peptide-core' ), self::MAX_STAGES );
			}
			$d['stages'] = array_slice( $stages, 0, self::MAX_STAGES );
			$d['start']  = $d['stages'] ? $d['stages'][0]['start'] : 0;
			$d['end']    = $d['stages'] ? max( array_column( $d['stages'], 'end' ) ) : 0;
		}
		$d['member_day']  = ! empty( $raw['member_day'] );
		$d['member_days'] = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $raw['member_days'] ?? array() ) ), static fn( $n ) => $n >= 1 && $n <= 7 ) ) );
		sort( $d['member_days'] );
		if ( $d['member_day'] && ! $d['member_days'] ) {
			$errors[] = __( 'Choose at least one member day.', 'peptide-core' );
		}
		$d['customers'] = 'emails' === ( $raw['customers'] ?? '' ) ? 'emails' : 'all';
		$d['emails']    = self::emails( $raw['emails'] ?? array() );
		if ( 'emails' === $d['customers'] && ! $d['emails'] ) {
			$errors[] = __( 'Enter at least one customer email.', 'peptide-core' );
		}
		return array( $d, array_values( array_unique( $errors ) ) );
	}

	/**
	 * 邮箱列表（每行一个，或逗号分隔）。
	 *
	 * @param string|string[] $value
	 * @return string[]
	 */
	public static function emails( $value ): array {
		$parts = is_array( $value ) ? $value : preg_split( '/[\s,;]+/', (string) $value );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$p = strtolower( trim( sanitize_email( (string) $p ) ) );
			if ( '' !== $p && is_email( $p ) ) {
				$out[] = $p;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** 保存（新建时分配 ID），返回保存后的折扣。 */
	public static function save( array $d ): array {
		$all = self::all();
		if ( ! $d['id'] || ! isset( $all[ $d['id'] ] ) ) {
			$d['id']      = $all ? max( array_keys( $all ) ) + 1 : 1;
			$d['created'] = time();
		} else {
			$d['created'] = $all[ $d['id'] ]['created'];
		}
		$all[ $d['id'] ] = $d;
		self::write( $all );
		return $d;
	}

	public static function delete( int $id ): void {
		$all = self::all();
		unset( $all[ $id ] );
		self::write( $all );
	}

	public static function set_enabled( int $id, bool $on ): void {
		$all = self::all();
		if ( isset( $all[ $id ] ) ) {
			$all[ $id ]['enabled'] = $on;
			self::write( $all );
		}
	}

	private static function write( array $all ): void {
		ksort( $all );
		update_option( self::OPTION, array_values( $all ), false );
		// 让购物车重新计算运费（WooCommerce 按这个版本号缓存运费）。
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	// ---------- 状态与计算 ----------

	/** off / upcoming / active / ended。 */
	public static function status( array $d, ?int $now = null ): string {
		$now = $now ?? time();
		if ( ! $d['enabled'] ) {
			return 'off';
		}
		if ( $d['start'] && $now < $d['start'] ) {
			return 'upcoming';
		}
		if ( $d['end'] && $now >= $d['end'] ) {
			return 'ended';
		}
		return 'active';
	}

	/** 现在的折扣值；不在活动时间（或阶段之间的空档）时为 null。 */
	public static function value_now( array $d, ?int $now = null ): ?float {
		$now = $now ?? time();
		if ( 'active' !== self::status( $d, $now ) ) {
			return null;
		}
		if ( 'fixed' === $d['mode'] ) {
			return (float) $d['value'];
		}
		foreach ( $d['stages'] as $st ) {
			if ( $now >= $st['start'] && $now < $st['end'] ) {
				return (float) $st['value'];
			}
		}
		return null;
	}

	/** 这位顾客现在能不能用（会员日、指定顾客）。 */
	public static function applies_to( array $d, string $email, int $user_id, ?int $now = null ): bool {
		$now = $now ?? time();
		if ( $d['member_day'] && ( ! $user_id || ! in_array( (int) wp_date( 'N', $now ), $d['member_days'], true ) ) ) {
			return false;
		}
		return 'emails' !== $d['customers'] || ( '' !== $email && in_array( strtolower( $email ), $d['emails'], true ) );
	}

	/** 当前顾客：[邮箱, 用户 ID]。结账里填的邮箱优先，登录顾客没填时用账户邮箱。 */
	private static function customer(): array {
		$user  = get_current_user_id();
		$email = function_exists( 'WC' ) && WC()->customer ? (string) WC()->customer->get_billing_email() : '';
		if ( '' === $email && $user ) {
			$email = (string) wp_get_current_user()->user_email;
		}
		return array( strtolower( trim( $email ) ), $user );
	}

	/**
	 * 当前顾客现在可以用的折扣。
	 *
	 * @return array<int,array{0:array,1:float}> [折扣, 折扣值]
	 */
	public static function applicable( ?int $now = null, ?string $email = null, ?int $user_id = null ): array {
		if ( null === $email || null === $user_id ) {
			[ $email, $user_id ] = self::customer();
		}
		$out = array();
		foreach ( self::all() as $d ) {
			$v = self::value_now( $d, $now );
			if ( null !== $v && self::applies_to( $d, $email, $user_id, $now ) ) {
				$out[] = array( $d, $v );
			}
		}
		return $out;
	}

	public static function discounted( float $cost, string $kind, float $value ): float {
		$new = 'percent' === $kind ? $cost * ( 1 - $value / 100 ) : $cost - $value;
		return max( 0.0, round( $new, wc_get_price_decimals() ) );
	}

	/** woocommerce_package_rates：每个运费取折扣后最低的那个。 */
	public static function apply( $rates, $package = array() ) {
		if ( ! is_array( $rates ) || ! $rates ) {
			return $rates;
		}
		$list = self::applicable();
		if ( ! $list ) {
			return $rates;
		}
		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof \WC_Shipping_Rate ) {
				continue;
			}
			$cost = (float) $rate->get_cost();
			if ( $cost <= 0 ) {
				continue;
			}
			$best = $cost;
			$by   = null;
			foreach ( $list as [ $d, $v ] ) {
				$new = self::discounted( $cost, $d['kind'], $v );
				if ( $new < $best ) {
					$best = $new;
					$by   = $d;
				}
			}
			if ( ! $by ) {
				continue;
			}
			$ratio = $best / $cost;
			$taxes = array();
			foreach ( (array) $rate->get_taxes() as $k => $tax ) {
				$taxes[ $k ] = round( (float) $tax * $ratio, wc_get_price_decimals() );
			}
			$rate->set_cost( (string) $best );
			$rate->set_taxes( $taxes );
			$rate->add_meta_data( self::META_NAME, $by['name'] );
			$rate->add_meta_data( self::META_COST, wc_format_decimal( $cost, wc_get_price_decimals() ) );
		}
		return $rates;
	}

	/** woocommerce_cart_shipping_packages：运费缓存的键带上现在可用的折扣。 */
	public static function tag_packages( $packages ) {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}
		$sig = array();
		foreach ( self::applicable() as [ $d, $v ] ) {
			$sig[] = $d['id'] . ':' . $v;
		}
		foreach ( $packages as $k => $p ) {
			if ( is_array( $p ) ) {
				$packages[ $k ]['pc_ship_discounts'] = implode( ',', $sig );
			}
		}
		return $packages;
	}

	// ---------- 文字 ----------

	public static function value_text( string $kind, float $value ): string {
		if ( 'percent' === $kind ) {
			/* translators: %s: percentage */
			return sprintf( __( '%s%% off shipping', 'peptide-core' ), wc_format_localized_decimal( $value ) );
		}
		/* translators: %s: amount */
		return sprintf( __( '%s off shipping', 'peptide-core' ), html_entity_decode( wp_strip_all_tags( wc_price( $value ) ) ) );
	}

	/** @return string[] 优惠规则，每行一条（阶段折扣每个阶段一行）。 */
	public static function rule_lines( array $d ): array {
		if ( 'fixed' === $d['mode'] ) {
			return array( self::value_text( $d['kind'], (float) $d['value'] ) );
		}
		$out = array();
		foreach ( $d['stages'] as $st ) {
			$out[] = wp_date( 'Y-m-d H:i', $st['start'] ) . ' ~ ' . wp_date( 'Y-m-d H:i', $st['end'] ) . ': ' . self::value_text( $d['kind'], (float) $st['value'] );
		}
		return $out;
	}
}
