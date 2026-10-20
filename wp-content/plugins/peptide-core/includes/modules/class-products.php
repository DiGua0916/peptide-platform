<?php
/**
 * 商品区积木的商品查询（SYNC-HOME-01）。主题模板和默认外观共用，保证同一份设置得到同一批商品。
 *
 * 只保存“取哪些商品、怎么排序”，名称、价格、库存、图片都实时来自 WooCommerce。
 * 目录顺序与现有首页一致：menu_order + title 升序（即报价单顺序）。
 *
 * 主题里用法：$products = \PeptideCore\Modules\Products::for_module( $args['module'] );
 *
 * @package PeptideCore
 */

namespace PeptideCore\Modules;

defined( 'ABSPATH' ) || exit;

final class Products {

	/**
	 * @param array<string,mixed> $module 规范化后的 product_collection 模块
	 * @return \WC_Product[]
	 */
	public static function for_module( array $module ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$s     = $module['settings'] ?? array();
		$limit = max( 1, min( 24, (int) ( $s['limit'] ?? 4 ) ) );
		$args  = array(
			'status'     => 'publish',
			'visibility' => 'visible',
			'limit'      => $limit,
		);

		$source = (string) ( $s['source'] ?? 'catalog' );
		if ( 'manual' === $source ) {
			$ids = array();
			foreach ( (array) ( $s['products'] ?? array() ) as $row ) {
				$id = (int) ( $row['settings']['product'] ?? 0 );
				if ( $id && ( ! array_key_exists( 'enabled', $row ) || $row['enabled'] ) ) {
					$ids[] = $id;
				}
			}
			$ids = array_values( array_unique( $ids ) );
			if ( ! $ids ) {
				return array();
			}
			$args['include'] = array_slice( $ids, 0, $limit );
			$args['limit']   = count( $args['include'] );
			$args['orderby'] = 'include';
			// 手动选择时按选择的顺序显示，不再套用排序设置。
			return array_values( wc_get_products( $args ) );
		}

		if ( 'category' === $source ) {
			$term = ! empty( $s['category'] ) ? get_term( (int) $s['category'], 'product_cat' ) : null;
			if ( ! $term instanceof \WP_Term ) {
				return array();
			}
			$args['category'] = array( $term->slug );
		}

		switch ( (string) ( $s['sort'] ?? 'catalog' ) ) {
			case 'newest':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
			case 'title':
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;
			case 'price_asc':
				$args['orderby'] = 'price';
				$args['order']   = 'ASC';
				break;
			case 'price_desc':
				$args['orderby'] = 'price';
				$args['order']   = 'DESC';
				break;
			default:
				$args['orderby'] = 'menu_order title';
				$args['order']   = 'ASC';
		}
		return array_values( wc_get_products( $args ) );
	}
}
