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

		// 促销节省（PRICE-SALE-01）：购物车每一行和购物车汇总。只做说明，WooCommerce 原生金额仍是权威。
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
				'namespace'       => self::EXT_NAMESPACE,
				'data_callback'   => static fn( $cart_item ) => array( 'pricing' => \PeptideCore\Pricing::cart_line( (array) $cart_item ) ),
				'schema_callback' => static fn() => array(
					'pricing' => array(
						'description' => __( 'Sale savings for this cart line (minor units). Excludes coupons.', 'peptide-core' ),
						'type'        => 'object',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
				),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => self::EXT_NAMESPACE,
				'data_callback'   => static fn() => array(
					'sale_savings' => \PeptideCore\Pricing::cart_summary( function_exists( 'WC' ) && WC()->cart ? WC()->cart : null ),
					// 满减（PROMO-01）：已用上的活动和下一档提示，结构同 peptide_core_get_promotion_progress()。
					'promotion'    => \PeptideCore\Promotions::progress(),
					// 广告像素（PIXELS-01）：这次请求里发生的加购等事件，交给浏览器里的 assets/pixels.js。取出后清空。
					'pixel_events' => \PeptideCore\Pixels::allowed() ? \PeptideCore\Pixels::pop_queue() : array(),
				),
				'schema_callback' => static fn() => array(
					'sale_savings' => array(
						'description' => __( 'Total sale savings in the cart (minor units). Informational; excludes coupons.', 'peptide-core' ),
						'type'        => 'object',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'promotion'    => array(
						'description' => __( 'Spend-and-save promotion applied to the cart, and the next tier the customer can reach.', 'peptide-core' ),
						'type'        => 'object',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'pixel_events' => array(
						'description' => __( 'Ad pixel events that happened in this request (for example add to cart), for the storefront pixel script.', 'peptide-core' ),
						'type'        => 'array',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
				),
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
			// 价格对（PRICE-SALE-01）：请求的是规格或简单商品时为它自己的价格对；请求父商品时为“当前成交价最低”那个规格的价格对。
			'price_pair'   => $product->is_type( 'variable' ) ? \PeptideCore\Pricing::card_pair( $product ) : ( '' === $product->get_price() ? null : \PeptideCore\Pricing::pair( $product ) ),
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
			'price_pair'   => array(
				'description' => __( 'Paired regular/current price of one purchasable item (minor units), with sale state and comparison-price source.', 'peptide-core' ),
				'type'        => array( 'object', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}
}
