<?php
/** Opaque cart identity for quote freshness; never a customer identifier or a stock lock. */
namespace PeptideCore\Activity;

use PeptideCore\Promotions;

defined( 'ABSPATH' ) || exit;

final class Cart_Snapshot {
	public static function key( \WC_Cart $cart ): string {
		$rows = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			$product = $item['data'] ?? null;
			$rows[ (string) $key ] = array(
				(int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ),
				$item['variation'] ?? array(), $item['quantity'] ?? null,
				(string) ( $item['line_subtotal'] ?? '' ), (string) ( $item['line_total'] ?? '' ),
				$product instanceof \WC_Product ? (string) $product->get_price() : '',
				$product instanceof \WC_Product ? $product->get_stock_status() : '',
				$product instanceof \WC_Product ? $product->get_stock_quantity() : null,
				$product instanceof \WC_Product ? array( $product->get_min_purchase_quantity(), $product->get_max_purchase_quantity(), $product->is_sold_individually(), $product->get_backorders() ) : null,
			);
		}
		ksort( $rows );
		$rules = array();
		foreach ( Promotions::active_ids() as $id ) { $rules[ $id ] = Promotions::config( $id ); }
		$settings = Store::effective();
		$context = array( get_current_blog_id(), home_url( '/' ), $rows, array_values( $cart->get_applied_coupons() ), $rules, $settings['revision'] ?? 0,
			get_woocommerce_currency(), wc_get_price_decimals(), wc_tax_enabled(), wc_prices_include_tax(),
			// HMAC hides the current authorization context; no reversible identity leaves the server.
			get_current_user_id() );
		return hash_hmac( 'sha256', wp_json_encode( $context ), wp_salt( 'auth' ) );
	}
}
