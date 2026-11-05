<?php
/**
 * Read-only, reconciled shipping savings for the current Store API cart.
 *
 * @package PeptideCore
 */

namespace PeptideCore\Shipping;

defined( 'ABSPATH' ) || exit;

final class Savings_Trace {

	private const MAX_MINOR = 9007199254740991;

	/**
	 * Never chooses a shipping method or recalculates a cart. A missing/stale
	 * package or legacy discount cache is deliberately represented by null.
	 */
	public static function for_cart( $cart ): ?array {
		try {
			return self::read( $cart );
		} catch ( \Throwable $error ) {
			// Optional explanatory data must not interrupt native checkout.
			return null;
		}
	}

	private static function read( $cart ): ?array {
		if ( ! $cart instanceof \WC_Cart || ! $cart->needs_shipping() || true !== $cart->has_calculated_shipping() || ! function_exists( 'WC' ) ) {
			return null;
		}
		$woo = WC();
		if ( ! $woo || ! $woo->session ) {
			return null;
		}
		$shipping = $woo->shipping();
		$packages = $shipping ? $shipping->get_packages() : null;
		$chosen   = $woo->session->get( 'chosen_shipping_methods', array() );
		$decimals = wc_get_price_decimals();
		$currency = get_woocommerce_currency();
		if ( ! is_array( $packages ) || ! $packages || count( $packages ) > 100 || ! is_array( $chosen ) || ! is_int( $decimals ) || $decimals < 0 || $decimals > 8 || ! is_string( $currency ) || ! preg_match( '/^[A-Z]{3}$/D', $currency ) ) {
			return null;
		}
		$total = self::minor( $cart->get_shipping_total(), $decimals );
		if ( null === $total ) {
			return null;
		}
		$rows = array();
		$names = array();
		$seen = array();
		$original_sum = 0;
		$payable_sum = 0;
		$discount_sum = 0;
		foreach ( $packages as $package ) {
			$package_id = is_array( $package ) ? ( $package['package_id'] ?? null ) : null;
			if ( ( ! is_int( $package_id ) && ! is_string( $package_id ) ) || ( is_int( $package_id ) && ( $package_id < 0 || $package_id > self::MAX_MINOR ) ) || ( is_string( $package_id ) && ( '' === $package_id || strlen( $package_id ) > 100 || 1 !== preg_match( '//u', $package_id ) ) ) || ! is_array( $package ) || ! isset( $package['rates'] ) || ! is_array( $package['rates'] ) || isset( $seen[ (string) $package_id ] ) ) {
				return null;
			}
			$seen[ (string) $package_id ] = true;
			$rate_id = $chosen[ $package_id ] ?? null;
			if ( ! is_string( $rate_id ) || '' === $rate_id || strlen( $rate_id ) > 200 || ! isset( $package['rates'][ $rate_id ] ) ) {
				return null;
			}
			$rate = $package['rates'][ $rate_id ];
			if ( ! $rate instanceof \WC_Shipping_Rate || $rate_id !== $rate->get_id() ) {
				return null;
			}
			$payable = self::minor( $rate->get_cost(), $decimals );
			$meta = $rate->get_meta_data();
			if ( null === $payable || ! is_array( $meta ) ) {
				return null;
			}
			$marked = false;
			foreach ( array( Discounts::META_COST, Discounts::META_NAME, Discounts::META_ID, Discounts::META_PAYABLE ) as $key ) {
				$marked = $marked || array_key_exists( $key, $meta );
			}
			$original = $payable;
			$discount_id = 0;
			$name = '';
			if ( $marked ) {
				// All provenance fields are required. A downstream filter changing
				// the rate after our discount invalidates this explanatory trace.
				$original = self::minor( $meta[ Discounts::META_COST ] ?? null, $decimals );
				$recorded = self::minor( $meta[ Discounts::META_PAYABLE ] ?? null, $decimals );
				$discount_id = self::discount_id( $meta[ Discounts::META_ID ] ?? null );
				$raw_name = $meta[ Discounts::META_NAME ] ?? null;
				if ( null === $original || null === $recorded || $recorded !== $payable || $original <= $payable || null === $discount_id || ! is_string( $raw_name ) ) {
					return null;
				}
				$name = trim( wp_strip_all_tags( $raw_name ) );
				if ( '' === $name || strlen( $name ) > 500 || 1 !== preg_match( '//u', $name ) || ( isset( $names[ $discount_id ] ) && $names[ $discount_id ] !== $name ) ) {
					return null;
				}
				$names[ $discount_id ] = $name;
			}
			$discount = $original - $payable;
			if ( $original_sum > self::MAX_MINOR - $original || $payable_sum > self::MAX_MINOR - $payable || $discount_sum > self::MAX_MINOR - $discount ) {
				return null;
			}
			$original_sum += $original;
			$payable_sum += $payable;
			$discount_sum += $discount;
			$rows[] = array(
				'package_id' => $package_id,
				'rate_id' => $rate_id,
				'original_shipping' => (string) $original,
				'shipping_discount' => (string) $discount,
				'payable_shipping' => (string) $payable,
				'discount_id' => $discount_id,
				'discount_name' => $name,
			);
		}
		if ( $payable_sum !== $total || $discount_sum <= 0 || $original_sum - $discount_sum !== $payable_sum ) {
			return null;
		}
		return array(
			'version' => 1,
			'status' => 'applied',
			'complete' => true,
			'tax_basis' => 'exclusive',
			'currency_code' => $currency,
			'currency_minor_unit' => $decimals,
			'original_shipping' => (string) $original_sum,
			'shipping_discount' => (string) $discount_sum,
			'payable_shipping' => (string) $payable_sum,
			'packages' => $rows,
		);
	}

	/** Same multiply/half-up/format pipeline as Store API MoneyFormatter. */
	private static function minor( $value, int $decimals ): ?int {
		if ( ! is_int( $value ) && ! is_float( $value ) && ( ! is_string( $value ) || ! preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value ) ) ) {
			return null;
		}
		$number = (float) $value;
		if ( ! is_finite( $number ) || $number < 0 ) {
			return null;
		}
		$rounded = round( $number * pow( 10, $decimals ), 0, PHP_ROUND_HALF_UP );
		if ( ! is_finite( $rounded ) || $rounded > self::MAX_MINOR ) {
			return null;
		}
		$formatted = wc_format_decimal( $rounded, 0, true );
		return is_string( $formatted ) && preg_match( '/^(?:0|[1-9][0-9]*)$/D', $formatted ) ? (int) $formatted : null;
	}

	private static function discount_id( $id ): ?int {
		if ( ! is_int( $id ) && ( ! is_string( $id ) || ! preg_match( '/^[1-9][0-9]*$/D', $id ) ) ) {
			return null;
		}
		if ( strlen( (string) $id ) > 16 || (float) $id > self::MAX_MINOR || (int) $id < 1 ) {
			return null;
		}
		return (int) $id;
	}
}
