<?php
/**
 * 税费方案（TAX-PLANS-01），对照 Shopname 的“新建税费方案”：方案名称 + 区域（国家，可细到州 / 省，每个区域一个税率）+ 适用商品。
 *
 * 方案保存时同步到 WooCommerce 自己的税率表，结账、订单税额、退款都用 WooCommerce 原有的税费计算：
 * - 适用“所有需收税商品”的方案写入标准税率类（standard）；
 * - 适用“特定商品”的方案建立自己的税率类 pc-tax-<id>，并把选中商品的税率类改成它。一个商品只属于一个特定商品方案。
 * - 每个区域写一条税率（选了州 / 省时每个州一条），名称为方案名称，优先级 1；“对运费征税”决定税率的 shipping 标记。
 * - 方案记录自己写入的税率 ID，修改或删除时只删除这些税率，不动手工在 WooCommerce 里建的税率。
 *
 * 税费是否生效由 WooCommerce 的“启用税费”（woocommerce_calc_taxes）决定，在税费设置页开关。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tax;

defined( 'ABSPATH' ) || exit;

final class Plans {

	public const OPTION       = 'peptide_core_tax_plans';
	public const CLASS_PREFIX = 'pc-tax-';

	/** @return array<int,array> */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $p ) {
			$p = self::normalize( (array) $p );
			if ( $p['id'] ) {
				$out[ $p['id'] ] = $p;
			}
		}
		return $out;
	}

	public static function get( int $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	public static function normalize( array $p ): array {
		$countries = WC()->countries->get_countries();
		$regions   = array();
		$seen      = array();
		foreach ( (array) ( $p['regions'] ?? array() ) as $r ) {
			$cc = strtoupper( sanitize_text_field( (string) ( $r['country'] ?? '' ) ) );
			if ( ! isset( $countries[ $cc ] ) || isset( $seen[ $cc ] ) ) {
				continue;
			}
			$seen[ $cc ] = true;
			$valid       = (array) WC()->countries->get_states( $cc );
			$states      = array_values( array_unique( array_filter( array_map( static fn( $s ) => strtoupper( sanitize_text_field( (string) $s ) ), (array) ( $r['states'] ?? array() ) ), static fn( $s ) => isset( $valid[ $s ] ) ) ) );
			$rate        = (float) str_replace( ',', '.', (string) ( $r['rate'] ?? 0 ) );
			$regions[]   = array( 'country' => $cc, 'states' => $states, 'rate' => round( min( 100, max( 0, $rate ) ), 4 ) );
		}
		$scope = 'products' === ( $p['scope'] ?? '' ) ? 'products' : 'all';
		return array(
			'id'       => absint( $p['id'] ?? 0 ),
			'name'     => sanitize_text_field( (string) ( $p['name'] ?? '' ) ),
			'scope'    => $scope,
			'products' => 'products' === $scope ? array_values( array_unique( array_filter( array_map( 'absint', (array) ( $p['products'] ?? array() ) ) ) ) ) : array(),
			'regions'  => $regions,
			'shipping' => ! empty( $p['shipping'] ) && 'false' !== $p['shipping'],
			'rate_ids' => array_values( array_filter( array_map( 'absint', (array) ( $p['rate_ids'] ?? array() ) ) ) ),
		);
	}

	public static function class_slug( array $plan ): string {
		return 'products' === $plan['scope'] ? self::CLASS_PREFIX . $plan['id'] : '';
	}

	/** 商品 ID => 特定商品方案 ID。 */
	public static function product_map(): array {
		$map = array();
		foreach ( self::all() as $p ) {
			foreach ( $p['products'] as $pid ) {
				$map[ $pid ] = $p['id'];
			}
		}
		return $map;
	}

	/** 保存方案并同步到 WooCommerce 税率表，返回保存后的方案。 */
	public static function save( array $plan ): array {
		$plan = self::normalize( $plan );
		$all  = self::all();
		$old  = $plan['id'] ? ( $all[ $plan['id'] ] ?? null ) : null;
		if ( ! $plan['id'] ) {
			$plan['id'] = $all ? max( array_keys( $all ) ) + 1 : 1;
		}
		$plan['rate_ids'] = $old ? $old['rate_ids'] : array();

		// 商品只属于一个特定商品方案：从其他方案移出。
		if ( $plan['products'] ) {
			foreach ( $all as $id => $other ) {
				if ( $id !== $plan['id'] && array_intersect( $other['products'], $plan['products'] ) ) {
					$all[ $id ]['products'] = array_values( array_diff( $other['products'], $plan['products'] ) );
				}
			}
		}
		$released = $old ? array_diff( $old['products'], $plan['products'] ) : array();
		$plan     = self::sync( $plan, $old );
		self::set_product_class( $released, '' );

		$all[ $plan['id'] ] = $plan;
		update_option( self::OPTION, array_values( $all ), false );
		return $plan;
	}

	public static function delete( int $id ): void {
		$all  = self::all();
		$plan = $all[ $id ] ?? null;
		if ( ! $plan ) {
			return;
		}
		self::delete_rates( $plan['rate_ids'] );
		self::set_product_class( $plan['products'], '' );
		if ( 'products' === $plan['scope'] ) {
			\WC_Tax::delete_tax_class_by( 'slug', self::class_slug( $plan ) );
		}
		unset( $all[ $id ] );
		update_option( self::OPTION, array_values( $all ), false );
	}

	/** 重写这个方案在 WooCommerce 里的税率类和税率。 */
	private static function sync( array $plan, ?array $old ): array {
		self::delete_rates( $plan['rate_ids'] );
		$class = self::class_slug( $plan );
		if ( '' !== $class && ! in_array( $class, \WC_Tax::get_tax_class_slugs(), true ) ) {
			\WC_Tax::create_tax_class( $plan['name'] ? $plan['name'] : $class, $class );
		}
		if ( $old && 'products' === $old['scope'] && 'all' === $plan['scope'] ) {
			\WC_Tax::delete_tax_class_by( 'slug', self::class_slug( $old ) );
		}
		$ids   = array();
		$order = 0;
		foreach ( $plan['regions'] as $r ) {
			foreach ( $r['states'] ? $r['states'] : array( '' ) as $state ) {
				$ids[] = (int) \WC_Tax::_insert_tax_rate(
					array(
						'tax_rate_country'  => $r['country'],
						'tax_rate_state'    => $state,
						'tax_rate'          => (string) $r['rate'],
						'tax_rate_name'     => $plan['name'] ? $plan['name'] : __( 'Tax', 'peptide-core' ),
						'tax_rate_priority' => 1,
						'tax_rate_compound' => 0,
						'tax_rate_shipping' => $plan['shipping'] ? 1 : 0,
						'tax_rate_order'    => $order++,
						'tax_rate_class'    => $class,
					)
				);
			}
		}
		$plan['rate_ids'] = array_values( array_filter( $ids ) );
		if ( '' !== $class ) {
			self::set_product_class( $plan['products'], $class );
		}
		if ( $old && 'products' === $old['scope'] && 'all' === $plan['scope'] ) {
			self::set_product_class( $old['products'], '' );
		}
		return $plan;
	}

	private static function delete_rates( array $ids ): void {
		foreach ( $ids as $id ) {
			\WC_Tax::_delete_tax_rate( (int) $id );
		}
	}

	/** 修改商品（含款式）的税率类；款式设为“同父商品”。 */
	private static function set_product_class( array $ids, string $class ): void {
		foreach ( $ids as $pid ) {
			$p = wc_get_product( (int) $pid );
			if ( ! $p || $p->get_tax_class( 'edit' ) === $class ) {
				continue;
			}
			$p->set_tax_class( $class );
			$p->save();
		}
	}

	/** 区域的文字说明，例如 “美国（加州、纽约）7.25%”。 */
	public static function region_text( array $r ): string {
		$name = WC()->countries->get_countries()[ $r['country'] ] ?? $r['country'];
		if ( $r['states'] ) {
			$states = (array) WC()->countries->get_states( $r['country'] );
			$name  .= ' (' . implode( ', ', array_map( static fn( $s ) => $states[ $s ] ?? $s, $r['states'] ) ) . ')';
		}
		return $name . ' ' . rtrim( rtrim( number_format( $r['rate'], 4, '.', '' ), '0' ), '.' ) . '%';
	}
}
