<?php
/** Session-free cart copies for the deliberately limited recommendation quote path. */
namespace PeptideCore\Activity;

use PeptideCore\Product_Offers;
use PeptideCore\Promotions;
use PeptideCore\Store_Config;

defined( 'ABSPATH' ) || exit;

final class Quote_Cart extends \WC_Cart {

	private ?\WC_Customer $quote_customer;

	/** Never call the parent constructor: it installs session, cookie and persistent-cart hooks. */
	public function __construct( array $contents = array(), ?\WC_Customer $customer = null ) {
		$this->fees_api       = new \WC_Cart_Fees();
		$this->totals         = $this->default_totals;
		$this->quote_customer = $customer ? clone $customer : null;
		$this->cart_context   = 'store-api';
		foreach ( $contents as $key => $item ) {
			if ( ! is_array( $item ) || ! ( ( $item['data'] ?? null ) instanceof \WC_Product ) ) {
				throw new \InvalidArgumentException( 'A quote requires native product objects.' );
			}
			$item['data'] = clone $item['data'];
			$this->cart_contents[ $key ] = $item;
		}
	}

	public static function copy( \WC_Cart $source, ?\WC_Customer $customer = null ): self {
		$customer = $customer ?? $source->get_customer();
		return new self( $source->cart_contents, $customer instanceof \WC_Customer ? $customer : null );
	}

	/** Fail closed when an omitted native/third-party pricing path could change a quote. */
	public static function supported( \WC_Cart $source ): bool {
		if ( ! in_array( get_class( $source ), array( \WC_Cart::class, self::class ), true ) || $source->applied_coupons || wc_tax_enabled() || wc_prices_include_tax() ) {
			return false;
		}
		if ( ! self::supported_hooks() ) { return false; }
		foreach ( $source->cart_contents as $item ) {
			$product = is_array( $item ) ? ( $item['data'] ?? null ) : null;
			if ( ! $product instanceof \WC_Product || array_key_exists( Product_Offers::CART, $item ) || ! in_array( $product->get_type(), array( 'simple', 'variation' ), true ) ) { return false; }
			$price = $product->get_price( 'edit' );
			$qty   = $item['quantity'] ?? null;
			if ( ! is_numeric( $price ) || ! is_finite( (float) $price ) || (float) $price < 0 || ! is_numeric( $qty ) || ! is_finite( (float) $qty ) || (float) $qty <= 0 || floor( (float) $qty ) !== (float) $qty ) { return false; }
		}
		foreach ( $source->get_fees() as $fee ) {
			if ( ! is_object( $fee ) || ! preg_match( '/^pc-promo-[1-9][0-9]*$/D', (string) ( $fee->id ?? '' ) ) || ! isset( $fee->amount ) || ! is_numeric( $fee->amount ) || ! is_finite( (float) $fee->amount ) || (float) $fee->amount > 0 || ! empty( $fee->taxable ) ) { return false; }
		}
		return true;
	}

	/** All callbacks here are inspected, never removed or temporarily replaced. */
	public static function supported_hooks(): bool {
		return null === self::unsupported_hook();
	}

	private static function unsupported_hook(): ?string {
		global $wp_filter;
		$allow = array(
			'woocommerce_cart_calculate_fees'      => array( Promotions::class . '::apply_to_cart' ),
			'woocommerce_before_calculate_totals' => array( Product_Offers::class . '::sync_cart' ),
			'woocommerce_after_calculate_totals'  => array( 'WC_Cart_Session::set_session' ),
			'woocommerce_product_get_price'       => array( 'Automattic\\WooCommerce\\Internal\\ScheduledSalePriceReconciler::reconcile_price' ),
			'woocommerce_is_purchasable'          => array( Store_Config::class . '::offline_purchasable' ),
			'woocommerce_variation_is_purchasable' => array( Store_Config::class . '::offline_variation_purchasable' ),
			'woocommerce_variation_is_visible'     => array( Store_Config::class . '::variation_visible' ),
			'woocommerce_add_to_cart_validation'  => array( 'wc_protected_product_add_to_cart' ),
			'woocommerce_stock_amount'           => array( 'intval' ),
		);
		$hooks = array(
			'woocommerce_get_cart_contents', 'woocommerce_get_discounted_price',
			'woocommerce_adjust_non_base_location_prices', 'woocommerce_cart_totals_get_item_tax_rates',
			'woocommerce_calculate_item_totals_taxes', 'woocommerce_cart_totals_get_fees_from_cart_taxes',
			'woocommerce_calculate_totals', 'woocommerce_calculated_total', 'woocommerce_sort_fees_callback',
			'woocommerce_product_is_on_sale', 'woocommerce_product_is_taxable',
			'woocommerce_add_to_cart_quantity', 'woocommerce_add_to_cart_sold_individually_quantity',
			'woocommerce_add_to_cart_sold_individually_found_in_cart', 'woocommerce_store_api_validate_add_to_cart',
			'woocommerce_store_api_cart_item_quantity_validation', 'woocommerce_is_sold_individually',
			'woocommerce_product_backorders_allowed', 'woocommerce_product_is_in_stock',
			'woocommerce_variation_is_active', 'woocommerce_variation_is_visible', 'woocommerce_product_is_visible',
			'woocommerce_query_for_reserved_stock', 'woocommerce_product_title',
			'woocommerce_is_virtual', 'woocommerce_product_needs_shipping',
		);
		$hooks = array_merge( $hooks, array_keys( $allow ) );
		foreach ( array( 'price', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'parent_id', 'tax_class', 'tax_status', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'sold_individually', 'status', 'catalog_visibility', 'name', 'virtual' ) as $property ) {
			$hooks[] = 'woocommerce_product_get_' . $property;
			$hooks[] = 'woocommerce_product_variation_get_' . $property;
		}
		foreach ( array_keys( (array) $wp_filter ) as $hook ) {
			if ( str_starts_with( $hook, 'woocommerce_cart_get_' ) || str_starts_with( $hook, 'woocommerce_quantity_input_' ) || str_starts_with( $hook, 'woocommerce_store_api_product_quantity_' ) || in_array( $hook, array( 'woocommerce_customer_get_is_vat_exempt', 'woocommerce_customer_get_billing_email' ), true ) ) { $hooks[] = $hook; }
		}
		foreach ( array_unique( $hooks ) as $hook ) {
			foreach ( ( $wp_filter[ $hook ]->callbacks ?? array() ) as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'] ?? null;
					// Woo's adapter is a no-op only while none of its legacy pricing hooks are in use.
					if ( is_array( $fn ) && 2 === count( $fn ) && is_object( $fn[0] ) && 'WC_Deprecated_Filter_Hooks' === get_class( $fn[0] ) && 'maybe_handle_deprecated_hook' === $fn[1] ) {
						foreach ( $fn[0]->get_old_hooks( $hook ) as $legacy ) { if ( has_filter( $legacy ) ) { return $legacy; } }
						continue;
					}
					$id = is_array( $fn ) && 2 === count( $fn ) ? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . $fn[1] : ( is_string( $fn ) ? $fn : '' );
					if ( ! in_array( $id, $allow[ $hook ] ?? array(), true ) ) { return $hook; }
				}
			}
		}
		return null;
	}

	/** No session hydration, global cart getter filters or loading events. */
	public function get_cart() { return array_filter( $this->cart_contents ); }
	public function get_customer() { return $this->quote_customer; }

	/** A merchandise quote excludes shipping; it never asks the global shipping/session service. */
	public function calculate_shipping() { return array(); }

	/** Native ownership/evaluation is reused; apply_to_cart's request-global $applied is untouched. */
	public function calculate_fees() {
		foreach ( Promotions::discounts( $this )['applied'] as $promotion ) {
			$this->fees_api->add_fee( array( 'id' => 'pc-promo-' . $promotion['id'], 'name' => $promotion['title'], 'amount' => -1 * $promotion['discount'], 'taxable' => false ) );
		}
	}

	/** Only the isolated object is changed; native rounding, negative-fee caps and totals stay authoritative. */
	public function calculate_quote(): void {
		if ( ! self::supported( $this ) ) { throw new \RuntimeException( 'unsupported_calculation' ); }
		$this->totals = $this->default_totals;
		$this->fees_api->remove_all_fees();
		new \WC_Cart_Totals( $this );
	}
}
