<?php
/**
 * Read-only aggregate contract for a future donation/tip payment integration.
 * No provider is installed here. This never creates a charge, fee, order or product.
 *
 * @package PeptideCore
 */

namespace PeptideCore\Modules;

defined( 'ABSPATH' ) || exit;

final class Donation_Stats {
	/**
	 * A trusted PHP provider may return status=ready, verified=true, matching days
	 * and currency, decimal-string amount, and an integer donors count. "Verified"
	 * is that integration's assertion, not an independent proof of transactions.
	 * Extra fields, including any personal records, are never returned to rendering.
	 * The request is this blog's recent N*24h UTC window; attrs cannot choose a blog,
	 * timestamp, amount, donors or currency. Different currencies are not converted.
	 *
	 * @return array<string,mixed>
	 */
	public static function read( int $days ): array {
		$unavailable = array( 'status' => 'unavailable' );
		if ( $days < 30 || $days > 360 || ! function_exists( 'apply_filters' ) || ! function_exists( 'get_current_blog_id' ) || ! function_exists( 'get_woocommerce_currency' ) || ! function_exists( 'get_woocommerce_currencies' ) || ! function_exists( 'wc_get_price_decimals' ) || ! function_exists( 'wc_price' ) ) {
			return $unavailable;
		}
		try {
			$currency = get_woocommerce_currency();
			$currencies = get_woocommerce_currencies();
			$decimals = wc_get_price_decimals();
			$blog = get_current_blog_id();
			if ( ! is_string( $currency ) || ! preg_match( '/^[A-Z]{3}$/D', $currency ) || ! is_array( $currencies ) || ! isset( $currencies[ $currency ] ) || ! is_int( $decimals ) || $decimals < 0 || $decimals > 12 || ! is_int( $blog ) || $blog < 1 ) { return $unavailable; }
			$to = time();
			$request = array( 'blog_id' => $blog, 'days' => $days, 'currency' => $currency, 'from' => $to - $days * DAY_IN_SECONDS, 'to' => $to );
			/** Filter is deliberately server-only; no browser endpoint or attr is a provider. */
			$value = apply_filters( 'peptide_core_donation_stats', null, $request );
			if ( ! is_array( $value ) || 'ready' !== ( $value['status'] ?? null ) || true !== ( $value['verified'] ?? null ) || $days !== ( $value['days'] ?? null ) || $currency !== ( $value['currency'] ?? null ) || ! isset( $value['amount'], $value['donors'] ) || ! is_string( $value['amount'] ) || ! is_int( $value['donors'] ) || $value['donors'] < 0 || $value['donors'] > 9007199254740991 ) { return $unavailable; }
			$amount = $value['amount']; $parts = array();
			// Bounded decimal input, no signs, exponents, grouping or locale conversion.
			if ( strlen( $amount ) > 32 || ! preg_match( '/^(?:0|[1-9][0-9]{0,11})(?:\.([0-9]+))?$/D', $amount, $parts ) || strlen( $parts[1] ?? '' ) > $decimals ) { return $unavailable; }
			return array( 'status' => 'ready', 'verified' => true, 'days' => $days, 'currency' => $currency, 'amount' => $amount, 'donors' => $value['donors'] );
		} catch ( \Throwable $error ) {
			// Never expose provider responses/errors or mistake a failed read for zero.
			return $unavailable;
		}
	}
}
