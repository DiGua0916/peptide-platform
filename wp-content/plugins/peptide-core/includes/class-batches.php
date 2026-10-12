<?php
/**
 * 批次的规则与查询。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Batches {

	public const VISIBILITIES = array( 'public', 'hidden' );
	public const STATUSES     = array( 'active', 'depleted' );

	/**
	 * 批次号统一转为大写并去掉首尾空白，查询和唯一性比较都基于规范化后的值。
	 */
	public static function normalize_batch_no( string $batch_no ): string {
		return strtoupper( trim( $batch_no ) );
	}

	/**
	 * 3–40 位：大写字母、数字和连字符，首尾不能是连字符。
	 */
	public static function is_valid_batch_no( string $batch_no ): bool {
		return 1 === preg_match( '/^[A-Z0-9][A-Z0-9-]{1,38}[A-Z0-9]$/', $batch_no );
	}

	/**
	 * 按批次号查找批次 ID（包括草稿和回收站中的，用于唯一性检查）。
	 */
	public static function find_id_by_batch_no( string $batch_no, int $exclude_id = 0 ): int {
		$ids = get_posts(
			array(
				'post_type'        => Post_Types::BATCH,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'meta_key'         => Meta::BATCH_NO, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $batch_no, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'post__not_in'     => $exclude_id ? array( $exclude_id ) : array(),
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * 商品是否可以作为批次的所属商品：存在、且不是变体。
	 */
	public static function is_valid_product( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		return $product && ! $product->is_type( 'variation' );
	}

	/**
	 * 只保留确实属于该商品的变体 ID。
	 *
	 * @param int[] $variation_ids
	 * @return int[]
	 */
	public static function filter_variation_ids( int $product_id, array $variation_ids ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return array();
		}
		$children = array_map( 'intval', $product->get_children() );
		return array_values( array_intersect( array_map( 'intval', $variation_ids ), $children ) );
	}

	/**
	 * 某商品的批次 ID 列表（后台用，不做访问过滤）。
	 *
	 * @return int[]
	 */
	public static function ids_for_product( int $product_id ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Post_Types::BATCH,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'meta_key'       => Meta::BATCH_PRODUCT_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $product_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			)
		);
	}
}
