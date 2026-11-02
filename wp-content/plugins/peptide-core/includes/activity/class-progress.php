<?php
/** Read-only cart guidance from the existing promotion engine, never a second discount engine. */
namespace PeptideCore\Activity;

use PeptideCore\Coupon_Rules;
use PeptideCore\Product_Offers;
use PeptideCore\Promotions;

defined( 'ABSPATH' ) || exit;

final class Progress {

	/** No totals recalculation, cart/session mutation, fee application or customer identifier output. */
	public static function for_cart( ?\WC_Cart $cart = null, ?array $settings = null ): array {
		$settings = $settings ?? Store::effective();
		$out = self::base( $settings );
		if ( ! $out['enabled'] ) { return self::finish( $out, 'disabled', 'plugin_disabled' ); }
		try {
			$cart = $cart ?? ( function_exists( 'WC' ) ? WC()->cart : null );
			if ( ! $cart instanceof \WC_Cart ) { return self::finish( $out, 'unavailable', 'cart_unavailable' ); }
			$totals = self::cart_totals( $cart );
			if ( null === $totals ) { return self::finish( $out, 'unavailable', 'cart_totals_unavailable' ); }
			$out['cartSubtotal'] = self::money( $totals['subtotal'] );
			if ( $cart->is_empty() || $totals['quantity'] < 1 ) { return self::finish( $out, 'empty', 'cart_empty' ); }
			$id = $settings['promotionId'] ?? 0;
			if ( ! is_int( $id ) || $id < 1 || Promotions::CPT !== get_post_type( $id ) ) { return self::finish( $out, 'unavailable', 'promotion_unavailable' ); }
			$status = Promotions::status( $id );
			if ( 'active' !== $status ) { return self::finish( $out, 'unavailable', 'promotion_' . $status ); }
			if ( ! self::valid_config( Promotions::config( $id ) ) ) { return self::finish( $out, 'unavailable', 'invalid_promotion' ); }
			// This is the same eligible assignment/evaluation used by the existing fee engine.
			$discounts = Promotions::discounts( $cart );
			$evaluation = null;
			foreach ( $discounts['evals'] as $entry ) { if ( (int) $entry['id'] === $id ) { $evaluation = $entry; break; } }
			if ( ! $evaluation || (int) $evaluation['quantity'] < 1 ) { return self::finish( $out, 'not_applicable', 'not_eligible' ); }
			$config = $evaluation['config'];
			if ( ! self::valid_config( $config ) || ! self::safe_amount( (float) $evaluation['measure'] ) || ! self::safe_amount( (float) $evaluation['subtotal'] ) ) { return self::finish( $out, 'unavailable', 'invalid_promotion' ); }
			foreach ( $cart->get_applied_coupons() as $code ) {
				// no_stack is normally rejected during Woo totals validation; never celebrate an unresolved combination.
				if ( 'no_stack' === Coupon_Rules::strategy( new \WC_Coupon( $code ) ) ) { return self::finish( $out, 'not_applicable', 'coupon_conflict' ); }
			}
			if ( self::offer_conflict( $cart, $id ) ) { return self::finish( $out, 'not_applicable', 'product_offer_conflict' ); }
			$actual = 0.0;
			// Woo may cap a negative promotion fee after coupons. Only its final total is an applied saving.
			foreach ( ( (float) $evaluation['discount'] > 0 ? $cart->get_fees() : array() ) as $fee ) {
				if ( is_object( $fee ) && 'pc-promo-' . $id === ( $fee->id ?? '' ) && isset( $fee->total ) && is_numeric( $fee->total ) && (float) $fee->total < 0 ) { $actual = -1 * (float) $fee->total; break; }
			}
			if ( ! self::safe_amount( $actual ) ) { return self::finish( $out, 'unavailable', 'invalid_promotion' ); }
			return self::build( $out, $id, $config, $evaluation, $actual, false );
		} catch ( \Throwable $error ) {
			// Guidance must not prevent shopping if a third-party cart/rule integration fails.
			return self::finish( $out, 'unavailable', 'calculation_unavailable' );
		}
	}

	/** Authorized callers only. Synthetic amount/quantity are never passed to a real cart or discount calculation. */
	public static function preview( array $settings, float $amount, int $quantity ): array {
		$out = self::base( $settings );
		if ( ! $out['enabled'] ) { return self::finish( $out, 'disabled', 'plugin_disabled' ); }
		if ( ! self::safe_amount( $amount ) || $quantity < 0 || $quantity > 10000 ) { return self::finish( $out, 'unavailable', 'invalid_preview' ); }
		$id = $settings['promotionId'] ?? 0;
		if ( ! is_int( $id ) || $id < 1 || Promotions::CPT !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) { return self::finish( $out, 'unavailable', 'promotion_unavailable' ); }
		$config = Promotions::config( $id );
		if ( ! self::valid_config( $config ) ) { return self::finish( $out, 'unavailable', 'invalid_promotion' ); }
		$measure = Promotions::by_quantity( $config ) ? (float) $quantity : $amount;
		$evaluation = array( 'measure' => $measure, 'subtotal' => $amount, 'quantity' => $quantity );
		$out['cartSubtotal'] = self::money( $amount );
		return self::build( $out, $id, $config, $evaluation, 0.0, true );
	}

	private static function base( array $settings ): array {
		return array(
			'version' => 1, 'enabled' => true === ( $settings['enabled'] ?? false ), 'visible' => false,
			'revision' => is_int( $settings['revision'] ?? null ) ? $settings['revision'] : 0,
			'title' => is_string( $settings['title'] ?? null ) ? $settings['title'] : '',
			'promotionRevision' => '', 'stateKey' => '', 'status' => 'unavailable', 'reason' => '', 'message' => '',
			'currency' => get_woocommerce_currency(), 'currencyMinorUnit' => wc_get_price_decimals(),
			'cartSubtotal' => self::money( 0 ), 'eligibleSubtotal' => self::money( 0 ), 'actualDiscount' => self::money( 0 ),
			'basis' => 'spend', 'measure' => '0', 'measureText' => '', 'progressPercent' => 0.0,
			'completed' => false, 'discountApplied' => false, 'next' => null, 'achievedKeys' => array(), 'nodes' => array(), 'promotion' => null,
			'celebration' => array( 'enabled' => true === ( $settings['celebrationEnabled'] ?? false ), 'smileEnabled' => true === ( $settings['smileEnabled'] ?? false ) ),
		);
	}

	private static function safe_amount( float $value ): bool {
		$decimals = wc_get_price_decimals();
		return $decimals >= 0 && $decimals <= 8 && is_finite( $value ) && $value >= 0 && $value * ( 10 ** $decimals ) <= 9007199254740991;
	}

	private static function money( float $value ): array {
		if ( wc_get_price_decimals() < 0 || wc_get_price_decimals() > 8 ) { return array( 'minor' => '0', 'text' => '' ); }
		if ( ! self::safe_amount( $value ) ) { $value = 0.0; }
		return array( 'minor' => sprintf( '%.0f', round( $value * ( 10 ** wc_get_price_decimals() ) ) ), 'text' => html_entity_decode( wp_strip_all_tags( wc_price( $value ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	private static function measure( float $value, bool $quantity, bool $remaining = false ): string|int {
		if ( $quantity ) { return (int) ( $remaining ? ceil( $value ) : $value ); }
		$scaled = $value * ( 10 ** wc_get_price_decimals() );
		// Keep a positive engine gap positive, even below one currency minor unit.
		return sprintf( '%.0f', $remaining && $value > 0 ? max( 1, round( $scaled ) ) : round( $scaled ) );
	}

	private static function measure_text( string|int $measure, bool $quantity ): string {
		if ( ! $quantity ) { return self::money( (float) $measure / ( 10 ** wc_get_price_decimals() ) )['text']; }
		return sprintf( _n( '%s item', '%s items', (int) $measure, 'peptide-core' ), number_format_i18n( (int) $measure ) );
	}

	private static function cart_totals( \WC_Cart $cart ): ?array {
		$subtotal = 0.0; $quantity = 0;
		foreach ( $cart->get_cart() as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['line_subtotal'], $item['quantity'] ) || ! is_numeric( $item['line_subtotal'] ) || ! is_numeric( $item['quantity'] ) ) { return null; }
			$line = (float) $item['line_subtotal']; $count = (float) $item['quantity'];
			if ( ! self::safe_amount( $line ) || ! is_finite( $count ) || $count < 0 || $count !== floor( $count ) || $count > 10000000 ) { return null; }
			$subtotal += $line; $quantity += (int) $count;
		}
		return self::safe_amount( $subtotal ) ? array( 'subtotal' => $subtotal, 'quantity' => $quantity ) : null;
	}

	private static function valid_config( array $config ): bool {
		if ( ! in_array( $config['type'] ?? '', Promotions::TYPES, true ) || ! is_array( $config['tiers'] ?? null ) || ! $config['tiers'] || count( $config['tiers'] ) > Promotions::MAX_TIERS || ( ! empty( $config['unlimited'] ) && count( $config['tiers'] ) !== 1 ) ) { return false; }
		if ( ! empty( $config['unlimited'] ) && ! in_array( $config['type'], Promotions::REPEATABLE, true ) ) { return false; }
		$quantity = Promotions::by_quantity( $config ); $previous = 0.0;
		foreach ( $config['tiers'] as $tier ) {
			if ( ! is_array( $tier ) || ! is_numeric( $tier['min'] ?? null ) || ! is_numeric( $tier['value'] ?? null ) ) { return false; }
			$min = (float) $tier['min']; $value = (float) $tier['value'];
			if ( ! self::safe_amount( $min ) || ! self::safe_amount( $value ) || $min <= $previous || $value <= 0 || ( $quantity && floor( $min ) !== $min ) ) { return false; }
			$previous = $min;
		}
		return true;
	}

	/** Do not independently exclude offer rows and invent a different eligible amount. Hide unresolved combinations instead. */
	private static function offer_conflict( \WC_Cart $cart, int $id ): bool {
		$marked = array();
		foreach ( $cart->get_cart() as $key => $item ) { if ( isset( $item[ Product_Offers::CART ] ) ) { $marked[] = (string) $key; } }
		if ( ! $marked ) { return false; }
		if ( Product_Offers::validate_cart( $cart ) ) { return true; }
		$assignment = Promotions::assign( $cart, Promotions::usable_ids( $cart ) );
		return (bool) array_intersect( $marked, $assignment[ $id ] ?? array() );
	}

	private static function build( array $out, int $id, array $config, array $evaluation, float $actual, bool $preview ): array {
		$quantity = Promotions::by_quantity( $config );
		$measure = (float) $evaluation['measure'];
		$out['basis'] = $quantity ? 'quantity' : 'spend';
		$out['measure'] = self::measure( $measure, $quantity );
		$out['measureText'] = self::measure_text( $out['measure'], $quantity );
		$out['eligibleSubtotal'] = self::money( (float) $evaluation['subtotal'] );
		$out['actualDiscount'] = self::money( $actual );
		$out['discountApplied'] = ! $preview && (float) $out['actualDiscount']['minor'] > 0;
		$out['promotion'] = array( 'id' => $id, 'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ), 'type' => $config['type'], 'status' => Promotions::status( $id ), 'url' => Promotions::detail_url( $id ) );
		// Set order does not change eligibility. Private targeting is keyed, so public hashes cannot expose customer identifiers.
		$rules = array_intersect_key( $config, array_flip( array( 'type', 'basis', 'reward', 'tiers', 'unlimited', 'start', 'end', 'scope', 'products', 'categories', 'exclude_sale', 'with_coupons' ) ) );
		foreach ( array( 'products', 'categories' ) as $set ) { $rules[ $set ] = array_values( array_unique( array_map( 'intval', (array) ( $rules[ $set ] ?? array() ) ) ) ); sort( $rules[ $set ], SORT_NUMERIC ); }
		$private = (array) ( $config['customer_rule'] ?? array() );
		foreach ( $private as &$value ) { if ( is_array( $value ) ) { $value = array_values( array_unique( $value ) ); sort( $value ); } } unset( $value );
		ksort( $private ); ksort( $rules );
		$rules['eligibility'] = hash_hmac( 'sha256', wp_json_encode( $private ), wp_salt( 'auth' ) );
		$out['promotionRevision'] = hash( 'sha256', wp_json_encode( array( $id, $out['currency'], $out['currencyMinorUnit'], $rules ) ) );
		$tiers = $config['tiers']; $cycle = 0;
		if ( ! empty( $config['unlimited'] ) ) {
			$base = $tiers[0]; $cycle = (int) floor( $measure / (float) $base['min'] );
			$tiers = array();
			if ( $cycle > 0 ) { $tiers[] = array( 'min' => $base['min'] * $cycle, 'value' => $base['value'], 'cycle' => $cycle ); }
			$tiers[] = array( 'min' => $base['min'] * ( $cycle + 1 ), 'value' => $base['value'], 'cycle' => $cycle + 1 );
		}
		$max = (float) end( $tiers )['min'];
		if ( ! self::safe_amount( $max ) || $max <= 0 ) { return self::finish( self::base( $out ), 'unavailable', 'invalid_promotion' ); }
		foreach ( $tiers as $index => $tier ) {
			$threshold = self::measure( (float) $tier['min'], $quantity );
			$reached = $measure >= (float) $tier['min']; // Exactly the canonical engine comparison, not rounded display money.
			$key = $id . ':' . substr( $out['promotionRevision'], 0, 16 ) . ':' . ( isset( $tier['cycle'] ) ? 'cycle' . $tier['cycle'] : 'tier' . $index ) . ':' . $threshold;
			$reward = Promotions::reward_text( $config, $tier );
			if ( isset( $tier['cycle'] ) ) { $reward = $reached ? sprintf( __( '%1$d completed sets · %2$s per set', 'peptide-core' ), $tier['cycle'], $reward ) : sprintf( __( 'Next set · %s', 'peptide-core' ), $reward ); }
			$node = array( 'key' => $key, 'index' => $index, 'threshold' => $threshold, 'thresholdText' => self::measure_text( $threshold, $quantity ), 'rewardText' => $reward, 'reached' => $reached, 'positionPercent' => round( 100 * (float) $tier['min'] / $max, 4 ), 'celebrationEligible' => $reached && $out['discountApplied'] && $out['celebration']['enabled'] );
			$out['nodes'][] = $node;
			if ( $reached && $out['discountApplied'] ) { $out['achievedKeys'][] = $key; }
			if ( ! $reached && null === $out['next'] ) {
				$remaining = self::measure( (float) $tier['min'] - $measure, $quantity, true );
				$out['next'] = array_merge( $node, array( 'remaining' => $remaining, 'remainingText' => self::measure_text( $remaining, $quantity ), 'message' => sprintf( __( 'Add %1$s more to get %2$s', 'peptide-core' ), self::measure_text( $remaining, $quantity ), $reward ) ) );
			}
		}
		$out['progressPercent'] = round( max( 0, min( 100, 100 * $measure / $max ) ), 4 );
		$out['completed'] = empty( $config['unlimited'] ) && null === $out['next'] && ( $preview || $out['discountApplied'] );
		$out['visible'] = true;
		$out['message'] = $out['next']['message'] ?? ( $out['completed'] ? __( 'You have reached the highest offer tier.', 'peptide-core' ) : __( 'This threshold is reached, but no promotion savings apply at the current prices.', 'peptide-core' ) );
		$status = $preview ? 'preview' : ( $out['discountApplied'] ? ( $out['completed'] ? 'complete' : 'tier_reached' ) : ( $measure >= (float) $config['tiers'][0]['min'] ? 'no_savings' : 'tracking' ) );
		return self::finish( $out, $status, $preview ? 'synthetic_preview' : ( 'no_savings' === $status ? 'no_discount_at_current_prices' : '' ) );
	}

	private static function finish( array $out, string $status, string $reason ): array {
		$out['status'] = $status; $out['reason'] = $reason;
		$out['stateKey'] = hash( 'sha256', wp_json_encode( array( $out['revision'], $out['promotionRevision'], $out['visible'], $status, $reason, $out['cartSubtotal']['minor'], $out['eligibleSubtotal']['minor'], $out['measure'], $out['actualDiscount']['minor'], $out['discountApplied'], $out['completed'], $out['nodes'], $out['celebration'] ) ) );
		return $out;
	}
}
