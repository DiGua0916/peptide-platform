<?php
/**
 * 物流方案（SHIPPING-PLANS-01），对照 Shopname 的“物流管理”：通用物流 + 自定义物流。
 *
 * - 方案 = 名称 + 区域（国家，可细到州 / 省）+ 运费列表；自定义方案另外指定商品。
 * - 一个商品最多属于一个自定义方案；不在任何自定义方案里的商品走通用方案。
 * - 运费 = 名称 + 启用 + 图标 + 邮编范围 + 费用规则（订单金额 / 件数 / 重量区间，左闭右开，上限为空表示不限）+ 费用。
 * - 结账时由 Plan_Method（不分区域的全局运费方式）计算：
 *   购物车按方案分组，每组取区域覆盖收货地址的方案里满足条件的运费；
 *   只有一组时列出全部可选运费，多组时每组取最便宜的一项，按“运费计算规则”相加或取最高，合成一项。
 *   任意一组没有可用运费时不给出运费（结账页提示无法配送），和 Shopname 一样。
 * - 建好至少一个方案后，方案就是唯一的运费来源：WooCommerce 原有运费区域给出的运费会被去掉。没有方案时原有区域照常使用。
 *
 * 数据存在选项 peptide_core_shipping_plans（不自动加载），规则存在 peptide_core_shipping_rules。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Shipping;

defined( 'ABSPATH' ) || exit;

final class Plans {

	public const OPTION    = 'peptide_core_shipping_plans';
	public const RULES     = 'peptide_core_shipping_rules';
	public const METHOD_ID = 'pc_plans';

	public const RULE_TYPES = array( 'subtotal', 'quantity', 'weight' );

	public static function register_hooks(): void {
		add_filter( 'woocommerce_shipping_methods', array( self::class, 'register_method' ) );
		add_filter( 'woocommerce_package_rates', array( self::class, 'only_plan_rates' ), 100, 2 );
	}

	/** @param array $methods */
	public static function register_method( $methods ): array {
		$methods[ self::METHOD_ID ] = Plan_Method::class;
		return $methods;
	}

	// ---------- 数据 ----------

	/** @return array<int,array> 按 id 排列的全部方案。 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $plan ) {
			$plan = self::normalize( (array) $plan );
			if ( $plan['id'] ) {
				$out[ $plan['id'] ] = $plan;
			}
		}
		return $out;
	}

	public static function get( int $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/** @return array<int,array> 某一类方案。 */
	public static function of_type( string $type ): array {
		return array_filter( self::all(), static fn( $p ) => $type === $p['type'] );
	}

	public static function rules(): array {
		$r = get_option( self::RULES, array() );
		$r = is_array( $r ) ? $r : array();
		return array(
			'combine' => in_array( $r['combine'] ?? '', array( 'sum', 'max' ), true ) ? $r['combine'] : 'sum',
			'sort'    => in_array( $r['sort'] ?? '', array( 'price', 'manual' ), true ) ? $r['sort'] : 'price',
		);
	}

	public static function save_rules( array $rules ): void {
		update_option( self::RULES, array( 'combine' => 'max' === ( $rules['combine'] ?? '' ) ? 'max' : 'sum', 'sort' => 'manual' === ( $rules['sort'] ?? '' ) ? 'manual' : 'price' ), false );
		// Refresh WooCommerce's package-rate cache after a plan or calculation-rule change.
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	/** 方案的标准形式（同时用于清理后台提交的内容）。 */
	public static function normalize( array $p ): array {
		$regions = array();
		$seen    = array();
		$all     = WC()->countries->get_countries();
		foreach ( (array) ( $p['regions'] ?? array() ) as $r ) {
			$cc = strtoupper( sanitize_text_field( (string) ( $r['country'] ?? '' ) ) );
			if ( ! isset( $all[ $cc ] ) || isset( $seen[ $cc ] ) ) {
				continue;
			}
			$seen[ $cc ] = true;
			$valid       = (array) WC()->countries->get_states( $cc );
			$states      = array();
			foreach ( (array) ( $r['states'] ?? array() ) as $s ) {
				$s = strtoupper( sanitize_text_field( (string) $s ) );
				if ( isset( $valid[ $s ] ) ) {
					$states[] = $s;
				}
			}
			$regions[] = array( 'country' => $cc, 'states' => array_values( array_unique( $states ) ) );
		}
		$rates = array();
		foreach ( array_values( (array) ( $p['rates'] ?? array() ) ) as $i => $r ) {
			$name = sanitize_text_field( (string) ( $r['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$max     = $r['max'] ?? '';
			$rates[] = array(
				'id'        => sanitize_key( (string) ( $r['id'] ?? '' ) ) ? sanitize_key( (string) $r['id'] ) : 'r' . substr( md5( $name . $i . wp_rand() ), 0, 8 ),
				'enabled'   => ! empty( $r['enabled'] ) && 'false' !== $r['enabled'],
				'name'      => $name,
				'icon'      => absint( $r['icon'] ?? 0 ),
				'postcodes' => self::clean_postcodes( (string) ( $r['postcodes'] ?? '' ) ),
				'rule'      => in_array( $r['rule'] ?? '', self::RULE_TYPES, true ) ? $r['rule'] : 'subtotal',
				'min'       => max( 0.0, (float) ( $r['min'] ?? 0 ) ),
				'max'       => '' === $max || null === $max ? null : max( 0.0, (float) $max ),
				'fee'       => max( 0.0, round( (float) ( $r['fee'] ?? 0 ), 2 ) ),
			);
		}
		$type = 'custom' === ( $p['type'] ?? '' ) ? 'custom' : 'general';
		return array(
			'id'       => absint( $p['id'] ?? 0 ),
			'type'     => $type,
			'name'     => sanitize_text_field( (string) ( $p['name'] ?? '' ) ),
			'products' => 'custom' === $type ? array_values( array_unique( array_filter( array_map( 'absint', (array) ( $p['products'] ?? array() ) ) ) ) ) : array(),
			'regions'  => $regions,
			'rates'    => $rates,
		);
	}

	/** 邮编范围：每行或逗号分隔一项，支持 90001、900*、90000-90999。 */
	public static function clean_postcodes( string $raw ): string {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', $raw ) as $item ) {
			$item = strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( $item ) ) );
			if ( '' !== $item && strlen( $item ) <= 40 ) {
				$out[] = $item;
			}
		}
		return implode( ', ', array_slice( array_unique( $out ), 0, 200 ) );
	}

	/**
	 * 保存方案，返回保存后的方案。自定义方案选中的商品会从其他自定义方案里移出（一个商品只属于一个自定义方案）。
	 */
	public static function save( array $plan ): array {
		$plan = self::normalize( $plan );
		$all  = self::all();
		if ( ! $plan['id'] ) {
			$plan['id'] = $all ? max( array_keys( $all ) ) + 1 : 1;
		}
		if ( 'custom' === $plan['type'] && $plan['products'] ) {
			foreach ( $all as $id => $other ) {
				if ( $id !== $plan['id'] && 'custom' === $other['type'] ) {
					$all[ $id ]['products'] = array_values( array_diff( $other['products'], $plan['products'] ) );
				}
			}
		}
		$all[ $plan['id'] ] = $plan;
		update_option( self::OPTION, array_values( $all ), false );
		// Refresh WooCommerce's package-rate cache after a plan or calculation-rule change.
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
		return $plan;
	}

	public static function delete( int $id ): void {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, array_values( $all ), false );
		// Refresh WooCommerce's package-rate cache after a plan or calculation-rule change.
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	/** 有方案（至少一个方案选了区域）时，方案是唯一的运费来源。 */
	public static function active(): bool {
		foreach ( self::all() as $p ) {
			if ( $p['regions'] ) {
				return true;
			}
		}
		return false;
	}

	/** 商品 ID => 自定义方案 ID。 */
	public static function product_map(): array {
		$map = array();
		foreach ( self::of_type( 'custom' ) as $p ) {
			foreach ( $p['products'] as $pid ) {
				$map[ $pid ] = $p['id'];
			}
		}
		return $map;
	}

	// ---------- 计算 ----------

	public static function covers( array $plan, string $country, string $state ): bool {
		foreach ( $plan['regions'] as $r ) {
			if ( $r['country'] === $country ) {
				return ! $r['states'] || in_array( strtoupper( $state ), $r['states'], true );
			}
		}
		return false;
	}

	public static function postcode_matches( string $list, string $postcode ): bool {
		if ( '' === trim( $list ) ) {
			return true;
		}
		$postcode = strtoupper( preg_replace( '/\s+/', '', $postcode ) );
		if ( '' === $postcode ) {
			return false;
		}
		foreach ( explode( ',', $list ) as $item ) {
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			if ( str_ends_with( $item, '*' ) ) {
				if ( str_starts_with( $postcode, rtrim( $item, '*' ) ) ) {
					return true;
				}
			} elseif ( preg_match( '/^(\w+)(?:-|\.\.\.)(\w+)$/', $item, $m ) && ctype_digit( $m[1] ) && ctype_digit( $m[2] ) ) {
				$digits = preg_replace( '/\D.*$/', '', $postcode );
				if ( '' !== $digits && (int) $digits >= (int) $m[1] && (int) $digits <= (int) $m[2] ) {
					return true;
				}
			} elseif ( $item === $postcode ) {
				return true;
			}
		}
		return false;
	}

	/** 费用规则是否满足：左闭右开，上限为空表示不限。 */
	public static function rule_matches( array $rate, float $value ): bool {
		return $value >= $rate['min'] && ( null === $rate['max'] || $value < $rate['max'] );
	}

	/**
	 * 一组商品可用的运费。
	 *
	 * @param array[] $plans 覆盖收货地址的方案。
	 * @param array   $group {subtotal, quantity, weight}
	 * @return array[] 每项 {plan, rate}
	 */
	public static function rates_for_group( array $plans, array $group, string $postcode ): array {
		$out = array();
		foreach ( $plans as $plan ) {
			foreach ( $plan['rates'] as $rate ) {
				if ( $rate['enabled'] && self::postcode_matches( $rate['postcodes'], $postcode ) && self::rule_matches( $rate, (float) $group[ $rate['rule'] ] ) ) {
					$out[] = array( 'plan' => $plan, 'rate' => $rate );
				}
			}
		}
		return $out;
	}

	/**
	 * 计算一个包裹的运费选项。
	 *
	 * @param array $package WooCommerce 包裹（contents、destination）。
	 * @return array[] 每项 {id, label, cost, plan_ids, icon}
	 */
	public static function quote( array $package ): array {
		$all = self::all();
		if ( ! $all ) {
			return array();
		}
		$dest     = (array) ( $package['destination'] ?? array() );
		$country  = strtoupper( (string) ( $dest['country'] ?? '' ) );
		$state    = strtoupper( (string) ( $dest['state'] ?? '' ) );
		$postcode = (string) ( $dest['postcode'] ?? '' );
		$map      = self::product_map();
		$groups   = array();
		foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product || ! $product->needs_shipping() ) {
				continue;
			}
			$pid = (int) ( $item['product_id'] ?? $product->get_id() );
			$key = isset( $map[ $pid ] ) ? 'c' . $map[ $pid ] : 'general';
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'subtotal' => 0.0, 'quantity' => 0, 'weight' => 0.0 );
			}
			$qty                          = (float) ( $item['quantity'] ?? 1 );
			$groups[ $key ]['subtotal'] += (float) ( $item['line_subtotal'] ?? 0 );
			$groups[ $key ]['quantity'] += (int) $qty;
			$groups[ $key ]['weight']   += (float) $product->get_weight() * $qty;
		}
		if ( ! $groups ) {
			return array();
		}
		$options = array();
		foreach ( $groups as $key => $g ) {
			$plans = 'general' === $key
				? array_filter( self::of_type( 'general' ), static fn( $p ) => self::covers( $p, $country, $state ) )
				: array_filter( array( $all[ (int) substr( $key, 1 ) ] ?? null ), static fn( $p ) => $p && self::covers( $p, $country, $state ) );
			$found = self::rates_for_group( $plans, $g, $postcode );
			if ( ! $found ) {
				return array();
			}
			$options[ $key ] = $found;
		}
		$rules = self::rules();
		if ( 1 === count( $options ) ) {
			$out = array();
			foreach ( reset( $options ) as $o ) {
				$out[] = array(
					'id'       => $o['plan']['id'] . '-' . $o['rate']['id'],
					'label'    => $o['rate']['name'],
					'cost'     => $o['rate']['fee'],
					'plan_ids' => array( $o['plan']['id'] ),
					'icon'     => $o['rate']['icon'] ? (string) wp_get_attachment_image_url( $o['rate']['icon'], 'thumbnail' ) : '',
				);
			}
			if ( 'price' === $rules['sort'] ) {
				usort( $out, static fn( $a, $b ) => $a['cost'] <=> $b['cost'] );
			}
			return $out;
		}
		// 多个方案：每组取最便宜的一项，再相加或取最高。
		$costs  = array();
		$labels = array();
		$ids    = array();
		foreach ( $options as $found ) {
			usort( $found, static fn( $a, $b ) => $a['rate']['fee'] <=> $b['rate']['fee'] );
			$costs[]  = $found[0]['rate']['fee'];
			$labels[] = $found[0]['rate']['name'];
			$ids[]    = $found[0]['plan']['id'];
		}
		return array(
			array(
				'id'       => 'combined',
				'label'    => implode( ' + ', array_unique( $labels ) ),
				'cost'     => 'max' === $rules['combine'] ? max( $costs ) : array_sum( $costs ),
				'plan_ids' => array_values( array_unique( $ids ) ),
				'icon'     => '',
			),
		);
	}

	/**
	 * 方案生效时，只保留方案算出的运费。
	 *
	 * @param array $rates
	 */
	public static function only_plan_rates( $rates, $package = array() ) {
		if ( ! self::active() || ! is_array( $rates ) ) {
			return $rates;
		}
		return array_filter( $rates, static fn( $r ) => $r instanceof \WC_Shipping_Rate && self::METHOD_ID === $r->get_method_id() );
	}

	// ---------- 显示 ----------

	public static function rule_text( array $rate ): string {
		$max = null === $rate['max'] ? __( 'no limit', 'peptide-core' ) : self::num( $rate['max'], $rate['rule'] );
		$min = self::num( $rate['min'], $rate['rule'] );
		switch ( $rate['rule'] ) {
			case 'quantity':
				/* translators: 1: minimum, 2: maximum */
				return sprintf( __( 'Items %1$s–%2$s', 'peptide-core' ), $min, $max );
			case 'weight':
				/* translators: 1: minimum, 2: maximum, 3: weight unit */
				return sprintf( __( 'Weight %1$s–%2$s %3$s', 'peptide-core' ), $min, $max, get_option( 'woocommerce_weight_unit', 'kg' ) );
			default:
				/* translators: 1: minimum, 2: maximum, 3: currency code */
				return sprintf( __( 'Order amount %1$s–%2$s %3$s', 'peptide-core' ), $min, $max, get_woocommerce_currency() );
		}
	}

	private static function num( float $v, string $rule ): string {
		return 'quantity' === $rule ? (string) (int) $v : rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' );
	}
}
