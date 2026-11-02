<?php
/** Native Woo product hydration with read-time catalogue repairs deliberately suppressed. */
namespace PeptideCore\Activity;

defined( 'ABSPATH' ) || exit;

final class Catalog_Read {

	/** Unknown product classes/stores/read callbacks cannot safely be invoked for a read-only quote. */
	public static function supported(): bool {
		global $wp_filter;
		$allow = array(
			'woocommerce_data_stores' => array(
				'Automattic\\WooCommerce\\Admin\\API\\Init::add_data_stores',
				'Automattic\\WooCommerce\\Internal\\StockNotifications\\StockNotifications::register_data_stores',
				'Automattic\\WooCommerce\\Admin\\Features\\Fulfillments\\FulfillmentsController::register_data_stores',
			),
		);
		$hooks = array(
			'woocommerce_data_stores', 'woocommerce_product_data_store', 'woocommerce_product-simple_data_store',
			'woocommerce_product-variable_data_store', 'woocommerce_product-variation_data_store',
			'woocommerce_product_class', 'woocommerce_product_type_query', 'woocommerce_product_read',
			'woocommerce_product_read_attribute', 'woocommerce_file_download_path', 'woocommerce_product_read_download',
			'woocommerce_load_product_cogs_value', 'woocommerce_load_product_cogs_is_additive_flag',
		);
		foreach ( $hooks as $hook ) {
			foreach ( ( $wp_filter[ $hook ]->callbacks ?? array() ) as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'] ?? null;
					$id = is_array( $fn ) && 2 === count( $fn ) ? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . $fn[1] : ( is_string( $fn ) ? $fn : '' );
					if ( ! in_array( $id, $allow[ $hook ] ?? array(), true ) ) { return false; }
				}
			}
		}
		try {
			foreach ( array( 'product' => 'WC_Product_Data_Store_CPT', 'product-simple' => 'WC_Product_Data_Store_CPT', 'product-variable' => 'WC_Product_Variable_Data_Store_CPT', 'product-variation' => 'WC_Product_Variation_Data_Store_CPT' ) as $type => $class ) {
				if ( $class !== \WC_Data_Store::load( $type )->get_current_class_name() ) { return false; }
			}
		} catch ( \Throwable $error ) { return false; }
		return true;
	}

	/** No global factory/data-store filters, persistence, title repair or child-price transient creation. */
	public static function product( int $id ): ?\WC_Product {
		if ( $id < 1 || ! Quote_Cart::supported_hooks() || ! self::supported() ) { return null; }
		try {
			$post = get_post( $id );
			if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) { return null; }
			$type = \WC_Product_Factory::get_product_type( $id );
			switch ( $type ) {
				case 'simple':
					return new \WC_Product_Simple( $id );
				case 'variable':
					return new \WC_Product_Variable( $id );
				case 'variation':
					$product = new \WC_Product_Variation();
					$product->set_id( $id );
					$store = new Variation_Read_Store();
					$store->read( $product );
					return $product;
				default:
					return null;
			}
		} catch ( \Throwable $error ) { return null; }
	}
}

/** Hydrates all native variation/parent properties, suppressing only Woo's read-time title UPDATE. */
final class Variation_Read_Store extends \WC_Product_Variation_Data_Store_CPT {
	protected function generate_product_title( $product ) {
		$post = get_post( $product->get_id() );
		return $post ? (string) $post->post_title : (string) $product->get_name( 'edit' );
	}
}
