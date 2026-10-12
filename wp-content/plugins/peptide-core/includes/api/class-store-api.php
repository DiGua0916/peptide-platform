<?php
/**
 * 在 WooCommerce Store API 的商品数据里附加 extensions.peptide_core（方案 v2 D'1）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Api;

use PeptideCore\Admin\Product_Panel;
use PeptideCore\Catalog;
use PeptideCore\Meta;

defined( 'ABSPATH' ) || exit;

final class Store_Api {

	public const EXT_NAMESPACE = 'peptide_core';

	public static function register_hooks(): void {
		// WooCommerce 可能在本插件启动前就已触发这个钩子。
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( self::class, 'register' ) );
		}
	}

	public static function register(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\ProductSchema::IDENTIFIER,
				'namespace'       => self::EXT_NAMESPACE,
				'data_callback'   => array( self::class, 'data' ),
				'schema_callback' => array( self::class, 'schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function data( \WC_Product $product ): array {
		// 变体沿用父商品的附加数据。
		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		if ( ! $parent ) {
			$parent = $product;
		}
		$product_id = $parent->get_id();

		return array(
			'is_demo'      => Meta::bool( $parent->get_meta( Meta::PRODUCT_IS_DEMO ) ),
			'specs'        => Product_Panel::get_specs( $parent ),
			'batch_count'  => count( Catalog::public_batch_ids( $product_id ) ),
			'report_state' => Catalog::best_report_state( $product_id, get_current_user_id() ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function schema(): array {
		return array(
			'is_demo'      => array(
				'description' => __( 'Synthetic demonstration record.', 'peptide-core' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'specs'        => array(
				'description' => __( 'Specification table.', 'peptide-core' ),
				'type'        => 'array',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'key'   => array( 'type' => 'string' ),
						'label' => array( 'type' => 'string' ),
						'value' => array( 'type' => 'string' ),
						'unit'  => array( 'type' => array( 'string', 'null' ) ),
					),
				),
			),
			'batch_count'  => array(
				'description' => __( 'Number of public batches.', 'peptide-core' ),
				'type'        => 'integer',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'report_state' => array(
				'description' => __( 'Best report state across public batches for the current user.', 'peptide-core' ),
				'type'        => 'string',
				'enum'        => array( 'available', 'restricted', 'pending', 'none' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}
}
