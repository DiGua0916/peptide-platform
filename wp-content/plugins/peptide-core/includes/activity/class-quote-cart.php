<?php
/** Session-free cart copies for the deliberately limited recommendation quote path. */
namespace PeptideCore\Activity;

use PeptideCore\Product_Offers;
use PeptideCore\Promotions;
use PeptideCore\Store_Config;

defined( 'ABSPATH' ) || exit;

final class Quote_Cart extends \WC_Cart {

	private ?\WC_Customer $quote_customer;
	private array $quote_coupons = array();

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
		$copy = new self( $source->cart_contents, $customer instanceof \WC_Customer ? $customer : null );
		if ( $source instanceof self ) {
			foreach ( $source->quote_coupons as $coupon ) { $copy->carry_coupon( $coupon ); }
		} else {
			// 顾客已用的优惠码（如 WELCOME5）一并带入报价，按 WooCommerce 原生规则计算（2026-11-08）。
			foreach ( self::existing_coupons( $source ) as $coupon ) { $copy->carry_coupon( $coupon ); }
		}
		return $copy;
	}

	/** 顾客购物车里已用的优惠码；任何一个无法安全计算就整体放弃报价。 @return \WC_Coupon[] */
	public static function existing_coupons( \WC_Cart $source ): array {
		$codes = $source->get_applied_coupons();
		if ( ! $codes ) { return array(); }
		$loaded = $source->get_coupons();
		$out = array();
		foreach ( $codes as $code ) {
			$coupon = $loaded[ $code ] ?? new \WC_Coupon( $code );
			if ( ! $coupon instanceof \WC_Coupon || ! Recommendation_Coupon::supported_existing_coupon( $coupon ) ) { throw new \RuntimeException( 'unsupported_existing_coupon' ); }
			$out[ $code ] = $coupon;
		}
		return $out;
	}

	/** 由会话重建的报价购物车：按已用的优惠码代码载入优惠码对象。 */
	public function hydrate_applied_coupons(): void {
		$codes = $this->applied_coupons;
		$this->quote_coupons = array();
		$this->applied_coupons = array();
		foreach ( $codes as $code ) {
			$coupon = new \WC_Coupon( (string) $code );
			if ( ! $coupon->get_id() || ! Recommendation_Coupon::supported_existing_coupon( $coupon ) ) { throw new \RuntimeException( 'unsupported_existing_coupon' ); }
			$this->carry_coupon( $coupon );
		}
		if ( array_keys( $this->quote_coupons ) !== array_values( $codes ) ) { throw new \RuntimeException( 'unsupported_existing_coupon' ); }
	}

	private function carry_coupon( \WC_Coupon $coupon ): void {
		$code = $coupon->get_code();
		if ( isset( $this->quote_coupons[ $code ] ) ) { throw new \RuntimeException( 'duplicate_quote_coupon' ); }
		$this->quote_coupons[ $code ] = clone $coupon;
		$this->applied_coupons = array_keys( $this->quote_coupons );
	}

	/** Called only after native WC_Discounts validation on this detached cart. */
	public function attach_quote_coupon( \WC_Coupon $coupon ): void {
		if ( isset( $this->quote_coupons[ $coupon->get_code() ] ) || ! Recommendation_Coupon::supported_coupon( $coupon ) ) { throw new \RuntimeException( 'unsupported_quote_coupon' ); }
		// 只能和可叠加的优惠码一起用；任何一方是“单独使用”就不加。
		if ( $coupon->get_individual_use() && $this->quote_coupons ) { throw new \RuntimeException( 'unsupported_quote_coupon' ); }
		foreach ( $this->quote_coupons as $existing ) { if ( $existing->get_individual_use() ) { throw new \RuntimeException( 'unsupported_quote_coupon' ); } }
		$this->carry_coupon( $coupon );
	}
	public function get_coupons( $deprecated = null ) { return 'order' === $deprecated ? array() : $this->quote_coupons; }

	/** Fail closed when an omitted native/third-party pricing path could change a quote. */
	public static function supported( \WC_Cart $source ): bool {
		if ( ! in_array( get_class( $source ), array( \WC_Cart::class, self::class ), true ) || ( $source instanceof self && array_keys( $source->quote_coupons ) !== array_values( $source->applied_coupons ) ) || wc_tax_enabled() || wc_prices_include_tax() ) {
			return false;
		}
		if ( ! $source instanceof self ) {
			try { self::existing_coupons( $source ); } catch ( \Throwable $error ) { return false; }
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
			'woocommerce_is_purchasable'          => array( Store_Config::class . '::offline_purchasable', 'WC_Payments_Subscriptions_Disabler::make_subscription_products_unpurchasable' ),
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
			'woocommerce_currency', 'wc_get_price_decimals', 'wc_get_price_decimal_separator',
			'wc_get_price_thousand_separator', 'woocommerce_price_format', 'option_woocommerce_currency_pos',
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
			foreach ( ( $wp_filter[ $hook ]->callbacks ?? array() ) as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'] ?? null;
					if ( self::default_only_wcpay_callback( $hook, $priority, $callback ) ) { continue; }
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

	/**
	 * WooPayments installs price filters even with only the store's base currency enabled.
	 * Accept only that already-initialized, read-only no-conversion path. Never initialize its
	 * currency service, inspect a shopper's selection, remove hooks or enable conversions.
	 */
	private static function default_only_wcpay_callback( string $hook, $priority, array $callback ): bool {
		$fn = $callback['function'] ?? null;
		if ( ! is_array( $fn ) || 2 !== count( $fn ) || ! is_object( $fn[0] ) ) { return false; }
		$class = get_class( $fn[0] );
		$prices = array( 'woocommerce_product_get_price', 'woocommerce_product_get_regular_price', 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_variation_get_regular_price', 'woocommerce_product_variation_get_sale_price' );
		$formatters = array( 'woocommerce_currency' => 'get_woocommerce_currency', 'wc_get_price_decimals' => 'get_price_decimals', 'wc_get_price_decimal_separator' => 'get_price_decimal_separator', 'wc_get_price_thousand_separator' => 'get_price_thousand_separator', 'woocommerce_price_format' => 'get_woocommerce_price_format', 'option_woocommerce_currency_pos' => 'get_woocommerce_currency_pos' );
		$is_price = 'WCPay\\MultiCurrency\\FrontendPrices' === $class && 'get_product_price_string' === $fn[1] && in_array( $hook, $prices, true ) && 99 === (int) $priority && 2 === ( $callback['accepted_args'] ?? null );
		$coupon_methods = array( 'woocommerce_coupon_get_amount' => array( 'get_coupon_amount', 2 ), 'woocommerce_coupon_get_minimum_amount' => array( 'get_coupon_min_max_amount', 1 ), 'woocommerce_coupon_get_maximum_amount' => array( 'get_coupon_min_max_amount', 1 ) );
		$is_coupon = 'WCPay\\MultiCurrency\\FrontendPrices' === $class && isset( $coupon_methods[ $hook ] ) && $coupon_methods[ $hook ][0] === $fn[1] && 99 === (int) $priority && $coupon_methods[ $hook ][1] === ( $callback['accepted_args'] ?? null );
		$is_formatter = 'WCPay\\MultiCurrency\\FrontendCurrencies' === $class && ( $formatters[ $hook ] ?? null ) === $fn[1] && 900 === (int) $priority && 1 === ( $callback['accepted_args'] ?? null );
		if ( ! $is_price && ! $is_formatter && ! $is_coupon ) { return false; }
		global $wp_filter;
		foreach ( array( 'pre_option_woocommerce_currency', 'option_woocommerce_currency', 'default_option_woocommerce_currency' ) as $name ) { if ( has_filter( $name ) ) { return false; } }
		try {
			$read = static function ( object $object, string $name ) {
				$property = new \ReflectionProperty( $object, $name );
				$property->setAccessible( true );
				return $property->getValue( $object );
			};
			$multi = $read( $fn[0], 'multi_currency' ); $compatibility = $read( $fn[0], 'compatibility' );
			if ( ! is_object( $multi ) || 'WCPay\\MultiCurrency\\MultiCurrency' !== get_class( $multi ) || ! is_object( $compatibility ) || 'WCPay\\MultiCurrency\\Compatibility' !== get_class( $compatibility ) || $read( $multi, 'compatibility' ) !== $compatibility ) { return false; }
			if ( ! self::wcpay_rest_base_filters( $multi ) ) { return false; }
			$default = $read( $multi, 'default_currency' ); $enabled = $read( $multi, 'enabled_currencies' );
			$base = get_option( 'woocommerce_currency', '' );
			if ( ! is_string( $base ) || ! preg_match( '/^[A-Z]{3}$/D', $base ) || ! is_object( $default ) || 'WCPay\\MultiCurrency\\Currency' !== get_class( $default ) || ! is_array( $enabled ) || array_keys( $enabled ) !== array( $base ) ) { return false; }
			foreach ( array( $default, $enabled[ $base ] ) as $currency ) {
				if ( ! is_object( $currency ) || 'WCPay\\MultiCurrency\\Currency' !== get_class( $currency ) || $currency->get_code() !== $base || true !== $currency->get_is_default() || 1.0 !== (float) $currency->get_rate() ) { return false; }
			}
			if ( $is_formatter ) {
				if ( null !== $read( $fn[0], 'order_currency' ) ) { return false; }
				$store = $read( $fn[0], 'store_currency' );
				if ( null !== $store && ( ! is_object( $store ) || 'WCPay\\MultiCurrency\\Currency' !== get_class( $store ) || $store->get_code() !== $base || true !== $store->get_is_default() ) ) { return false; }
				foreach ( array( 'woocommerce_currency', 'selected_currency_code' ) as $name ) { $cached = $read( $fn[0], $name ); if ( null !== $cached && '' !== $cached && $cached !== $base ) { return false; } }
				if ( true === $read( $fn[0], 'has_explicit_currency_selection' ) ) { return false; }
			}
			return true;
		} catch ( \Throwable $error ) { return false; }
	}
	public static function native_default_coupon_callback( string $hook, $priority, array $callback ): bool {
		return in_array( $hook, array( 'woocommerce_coupon_get_amount', 'woocommerce_coupon_get_minimum_amount', 'woocommerce_coupon_get_maximum_amount' ), true ) && self::default_only_wcpay_callback( $hook, $priority, $callback );
	}

	/**
	 * WooPayments installs this exact no-conversion trio on non-Store REST requests.
	 * Identity is inspected, never invoked. Any additional/replaced/foreign override still fails.
	 */
	private static function wcpay_rest_base_filters( object $multi ): bool {
		global $wp_filter;
		$active = array();
		foreach ( array_keys( (array) $wp_filter ) as $name ) { if ( str_starts_with( $name, 'wcpay_multi_currency_' ) && has_filter( $name ) ) { $active[] = $name; } }
		if ( ! $active ) { return true; }
		$expected = array( 'wcpay_multi_currency_should_return_store_currency' => '__return_true', 'wcpay_multi_currency_should_convert_product_price' => '__return_false', 'wcpay_multi_currency_override_selected_currency' => null );
		if ( array_diff( $active, array_keys( $expected ) ) || array_diff( array_keys( $expected ), $active ) || ! function_exists( 'WC' ) || ! WC()->is_rest_api_request() || isset( $_GET['currency'] ) || ! class_exists( '\\WCPay\\MultiCurrency\\Utils' ) || \WCPay\MultiCurrency\Utils::is_store_api_request() || \WCPay\MultiCurrency\Utils::is_store_batch_request() ) { return false; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reject query-selected currency; no mutation.
		foreach ( $expected as $name => $function ) {
			$priorities = $wp_filter[ $name ]->callbacks ?? array();
			if ( array_keys( $priorities ) !== array( 10 ) || 1 !== count( $priorities[10] ) ) { return false; }
			$callback = array_values( $priorities[10] )[0];
			if ( 1 !== ( $callback['accepted_args'] ?? null ) ) { return false; }
			$actual = $callback['function'] ?? null;
			if ( null !== $function ) { if ( $actual !== $function ) { return false; } continue; }
			if ( ! $actual instanceof \Closure ) { return false; }
			$reflection = new \ReflectionFunction( $actual );
			$class = new \ReflectionClass( $multi );
			$file = $class->getFileName();
			$method = $class->getMethod( 'init_hooks' );
			$expected_file = defined( 'WP_PLUGIN_DIR' ) ? realpath( WP_PLUGIN_DIR . '/woocommerce-payments/includes/multi-currency/MultiCurrency.php' ) : false;
			if ( ! is_string( $file ) || ! $expected_file || realpath( $file ) !== $expected_file || $reflection->getFileName() !== $file || $reflection->getClosureThis() !== $multi || ( $reflection->getClosureScopeClass()?->getName() ) !== 'WCPay\\MultiCurrency\\MultiCurrency' || 0 !== $reflection->getNumberOfParameters() || $reflection->getStaticVariables() || $reflection->getStartLine() < $method->getStartLine() || $reflection->getEndLine() > $method->getEndLine() ) { return false; }
			// Release source is immutable. Cache bytes/hash only for this PHP request, never a verdict,
			// filter list, object state, selected currency, quote, cart or promotion across requests.
			static $sources = array();
			if ( ! array_key_exists( $expected_file, $sources ) ) {
				$source = file_get_contents( $file );
				$source = is_string( $source ) ? str_replace( "\r\n", "\n", $source ) : null;
				$sources[ $expected_file ] = null !== $source && hash_equals( '925ea6363029cd689be370d572544d823cc31b59209f172e136d2b839c7dea3b', hash( 'sha256', $source ) ) ? explode( "\n", $source ) : false;
			}
			$lines = $sources[ $expected_file ];
			if ( false === $lines ) { return false; }
			$body = trim( implode( "\n", array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) ) );
			if ( ! hash_equals( '733e7622cec4ee19fbf7a0a8d24d32d7b297eb2086cfed305ddecdd0d729641d', hash( 'sha256', $body ) ) ) { return false; }
		}
		return true;
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
