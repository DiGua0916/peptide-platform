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
	/** A bounded, catalog-only page. Existing for_module queries remain unchanged. */
	public static function collection_page( int $category, int $page, int $per_page ): array {
		$empty = array( 'products' => array(), 'hasMore' => false );
		if ( ! function_exists( 'wc_get_products' ) || $category < 1 || $page < 1 || $page > 20 || $per_page < 1 || $per_page > 50 ) { return $empty; }
		$term = get_term( $category, 'product_cat' );
		if ( ! $term instanceof \WP_Term ) { return $empty; }
		global $wpdb;
		$queries = (int) ( $wpdb->num_queries ?? 0 );
		$suppress = is_object( $wpdb ) && method_exists( $wpdb, 'suppress_errors' );
		$previous = $suppress ? $wpdb->suppress_errors( true ) : null;
		try {
		$products = wc_get_products( array(
			'status' => 'publish', 'visibility' => 'visible', 'has_password' => false,
			'category' => array( $term->slug ), 'return' => 'objects', 'cache_results' => false,
			'limit' => $per_page + 1, 'offset' => ( $page - 1 ) * $per_page,
			'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC', 'ID' => 'ASC' ), 'order' => 'ASC',
		) );
			if ( (int) ( $wpdb->num_queries ?? 0 ) > $queries && '' !== (string) ( $wpdb->last_error ?? '' ) ) { throw new \RuntimeException( 'Product data is unavailable.' ); }
		} finally { if ( $suppress ) { $wpdb->suppress_errors( $previous ); } }
		if ( ! is_array( $products ) ) { throw new \UnexpectedValueException( 'Product query failed.' ); }
		$more = count( $products ) > $per_page;
		$products = array_values( array_filter( array_slice( $products, 0, $per_page ), array( self::class, 'public_catalog_product' ) ) );
		return array( 'products' => $products, 'hasMore' => $more );
	}

	/** A signature never grants access to a nonpublic or password-protected product. */
	public static function public_catalog_product( $product ): bool {
		return $product instanceof \WC_Product && 'product' === get_post_type( $product->get_id() )
			&& 'publish' === $product->get_status() && $product->is_publicly_viewable()
			&& '' === $product->get_post_password() && $product->is_visible();
	}

}
