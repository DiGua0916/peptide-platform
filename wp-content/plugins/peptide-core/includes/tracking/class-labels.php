<?php
/**
 * 访客数据页面的中文名称（2026-11-09）：把追踪记录里的内部标识翻成店主看得懂的话。
 *
 * - 模块：装修积木类型（product_collection → 商品区）和固定区域（header → 页头导航）；
 * - 配送方式：pc_plans:<方案编号>-<费率> → 方案名称；
 * - 支付方式：网关编号 → 结账页显示的名称；
 * - 商品：编号 → 商品名（一次查询，带缓存）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

use PeptideCore\Modules\Registry;
use PeptideCore\Shipping\Plans;

defined( 'ABSPATH' ) || exit;

final class Labels {

	/** 常见积木类型的中文名；没列到的用积木注册表里的标题。 */
	private const MODULES = array(
		'site_header'        => '页头导航',
		'header'             => '页头导航',
		'site_footer'        => '页脚',
		'footer'             => '页脚',
		'product_collection' => '商品区',
		'product_grid'       => '商品区',
		'demo-catalog'       => '商品目录',
		'hero'               => '首屏大图',
		'carousel'           => '轮播',
		'faq'                => '常见问题',
		'media-text'         => '图文介绍',
		'report-feature'     => '检测报告',
		'first-order-offer'  => '首单优惠弹窗',
		'promo_bar'          => '促销条',
		'announcement'       => '公告条',
		'video'              => '视频',
		'reviews'            => '顾客评价',
		'newsletter'         => '订阅栏',
	);

	/** @var array<int,string> */
	private static array $products = array();

	/** 积木类型 → 中文名。 */
	public static function module( string $type, string $id = '' ): string {
		$type = strtolower( trim( $type ) );
		if ( isset( self::MODULES[ $type ] ) ) {
			return self::MODULES[ $type ];
		}
		if ( '' !== $type && class_exists( Registry::class ) ) {
			$def = Registry::get( $type );
			if ( is_array( $def ) && '' !== (string) ( $def['title'] ?? '' ) ) {
				return (string) $def['title'];
			}
		}
		return '' !== $type ? $type : ( '' !== $id ? $id : '模块' );
	}

	/** 全部已知积木类型的中文名，给前端时间线用。 */
	public static function modules(): array {
		$out = self::MODULES;
		if ( class_exists( Registry::class ) ) {
			foreach ( Registry::all() as $type => $def ) {
				if ( ! isset( $out[ $type ] ) && is_array( $def ) && '' !== (string) ( $def['title'] ?? '' ) ) {
					$out[ (string) $type ] = (string) $def['title'];
				}
			}
		}
		return $out;
	}

	/** 配送费率编号 → 方案名（pc_plans:3-r86cf → 第 3 个方案的名称）。 */
	public static function shipping(): array {
		$out = array();
		if ( class_exists( Plans::class ) ) {
			foreach ( Plans::all() as $id => $plan ) {
				$name = trim( (string) ( $plan['name'] ?? '' ) );
				if ( '' !== $name ) {
					$out[ 'pc_plans:' . (int) $id ] = $name;
				}
			}
		}
		return $out;
	}

	/** 支付网关编号 → 结账页上的名称。 */
	public static function payments(): array {
		$out = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				$title = trim( wp_strip_all_tags( (string) $gateway->get_title() ) );
				$out[ (string) $id ] = '' !== $title ? $title : (string) $id;
			}
		}
		return $out;
	}

	/**
	 * 商品名（含规格）。查不到的返回 '#编号'。
	 *
	 * @param int[] $ids
	 * @return array<int,string>
	 */
	public static function products( array $ids ): array {
		$ids  = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $i ) => $i > 0 ) ) );
		$need = array_diff( $ids, array_keys( self::$products ) );
		if ( $need && function_exists( 'wc_get_products' ) ) {
			foreach ( array_chunk( $need, 100 ) as $chunk ) {
				foreach ( $chunk as $id ) {
					self::$products[ $id ] = '#' . $id;
				}
				foreach ( wc_get_products( array( 'include' => $chunk, 'limit' => count( $chunk ), 'type' => array( 'simple', 'variable', 'variation', 'grouped', 'external' ), 'status' => 'any' ) ) as $product ) {
					$name = trim( wp_strip_all_tags( (string) $product->get_name() ) );
					if ( $product->is_type( 'variation' ) ) {
						$attrs = array_filter( array_map( 'strval', (array) $product->get_attributes() ) );
						if ( $attrs ) { $name .= ' · ' . implode( ' / ', $attrs ); }
					}
					self::$products[ $product->get_id() ] = '' !== $name ? mb_substr( $name, 0, 120 ) : '#' . $product->get_id();
				}
			}
		}
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = self::$products[ $id ] ?? ( '#' . $id );
		}
		return $out;
	}
}
