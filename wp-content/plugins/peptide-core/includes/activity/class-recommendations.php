<?php
/** Bounded, read-only quotes from the native promotion evaluator and Woo totals. */
namespace PeptideCore\Activity;

use PeptideCore\Modules\Products;
use PeptideCore\Product_Offers;
use PeptideCore\Promotions;

defined( 'ABSPATH' ) || exit;

final class Recommendations {
	private const MAX_TRIALS = 64;
	public static function error( string $reason, int $status = 400, array $extra = array() ): \WP_Error {
		return new \WP_Error( 'pc_recommendation_' . $reason, __( '推荐暂不可用，请刷新购物车后重试。', 'peptide-core' ), array_merge( array( 'status' => $status, 'reason' => $reason ), $extra ) );
	}

	/** Only freshness identifiers and bounded browser-ranking hints are accepted. */
	public static function validate( $input ) {
		$keys = array( 'cartStateKey', 'cartSnapshotKey', 'promotionRevision', 'configRevision', 'candidates' );
		if ( ! is_array( $input ) || array_is_list( $input ) || array_diff( array_keys( $input ), array_merge( $keys, array( 'supportsAutomaticCoupon' ) ) ) || array_diff( $keys, array_keys( $input ) ) ) { return self::error( 'invalid_input' ); }
		if ( array_key_exists( 'supportsAutomaticCoupon', $input ) && ! is_bool( $input['supportsAutomaticCoupon'] ) ) { return self::error( 'invalid_capability' ); }
		foreach ( array( 'cartStateKey', 'cartSnapshotKey', 'promotionRevision' ) as $key ) { if ( ! is_string( $input[ $key ] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $input[ $key ] ) ) { return self::error( 'invalid_' . $key ); } }
		if ( ! is_int( $input['configRevision'] ) || $input['configRevision'] < 0 || $input['configRevision'] > 2147483646 || ! is_array( $input['candidates'] ) || ! array_is_list( $input['candidates'] ) || count( $input['candidates'] ) > 20 ) { return self::error( 'invalid_candidates' ); }
		$parents = array();
		foreach ( $input['candidates'] as $entry ) {
			if ( ! is_array( $entry ) || array_diff( array_keys( $entry ), array( 'productId', 'variationId', 'viewCount', 'lastViewedAt' ) ) || count( $entry ) !== 4 ) { return self::error( 'invalid_candidate' ); }
			foreach ( array( 'productId', 'variationId', 'viewCount' ) as $key ) { $min = 'variationId' === $key ? 0 : 1; $max = 'viewCount' === $key ? 1000000 : 2147483647; if ( ! is_int( $entry[ $key ] ?? null ) || $entry[ $key ] < $min || $entry[ $key ] > $max ) { return self::error( 'invalid_candidate' ); } }
			$stamp = $entry['lastViewedAt'];
			if ( null !== $stamp ) {
				if ( ! is_string( $stamp ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $stamp ) ) { return self::error( 'invalid_timestamp' ); }
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $stamp, new \DateTimeZone( 'UTC' ) );
				if ( ! $date || $date->format( 'Y-m-d\TH:i:s\Z' ) !== $stamp || $date->getTimestamp() > time() + 60 || $date->getTimestamp() < 0 ) { return self::error( 'invalid_timestamp' ); }
			}
			if ( isset( $parents[ $entry['productId'] ] ) ) { return self::error( 'duplicate_product' ); }
			$parents[ $entry['productId'] ] = true;
		}
		return $input;
	}

	/** No calls to add_to_cart/calculate_totals/session setters or tracking hooks. */
	public static function quote( \WC_Cart $source, array $input, ?array $settings = null, ?array $persisted_totals = null, string $cart_identity = '' ) {
		$valid = self::validate( $input ); if ( is_wp_error( $valid ) ) { return $valid; }
		if ( \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return self::error( 'login_required', 401 ); }
		if ( ! in_array( get_class( $source ), array( \WC_Cart::class, Quote_Cart::class ), true ) || ! Quote_Cart::supported_hooks() || ! Catalog_Read::supported() ) { return self::error( 'unsupported_calculation', 409 ); }
		$settings = $settings ?? Store::effective();
		$progress = Progress::for_cart( $source, $settings );
		$out = self::base( $progress );
		if ( empty( $settings['enabled'] ) || empty( $settings['recommendation']['enabled'] ) ) { return self::hidden( $out, 'disabled' ); }
		if ( ! empty( $settings['recommendationCoupon']['enabled'] ) && ( true !== ( $input['supportsAutomaticCoupon'] ?? false ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $cart_identity ) ) ) { return self::hidden( $out, 'client_update_required' ); }
		// 顾客已用的优惠码不再挡住推荐（2026-11-08）；无法安全计算的优惠码会在报价时整体放弃。
		$coupon = Recommendation_Coupon::coupon( $settings );
		if ( ! empty( $settings['recommendationCoupon']['enabled'] ) && ! $coupon ) { return self::hidden( $out, 'coupon_unavailable' ); }
		// Unknown price integrations must not be partially simulated or have hooks removed.
		if ( ! Quote_Cart::supported( $source ) || Product_Offers::all( true ) ) { return self::hidden( $out, 'unsupported_calculation' ); }
		foreach ( Promotions::active_ids() as $id ) {
			$config = Promotions::config( $id );
			if ( 'all' !== ( $config['customer_rule']['customers'] ?? 'all' ) || 'spend_amount' !== $config['type'] ) { return self::hidden( $out, 'unsupported_calculation' ); }
		}
		$id = (int) $settings['promotionId'];
		if ( 'active' !== Promotions::status( $id ) ) { return self::hidden( $out, 'promotion_unavailable' ); }
		try {
			$before = Quote_Cart::copy( $source ); $before->calculate_quote();
			// Reprice the whole cart using fresh native products. A changed source requires a new native cart response.
			$totals = $persisted_totals ?? $source->get_totals();
			foreach ( array( 'subtotal', 'discount_total', 'fee_total' ) as $key ) {
				if ( ! isset( $totals[ $key ] ) || ! is_numeric( $totals[ $key ] ) || abs( (float) $totals[ $key ] - (float) $before->get_totals()[ $key ] ) > 0.00001 ) { return self::error( 'stale_cart', 409 ); }
			}
			$progress = Progress::for_cart( $before, $settings ); $out = self::base( $progress );
			foreach ( array( 'cartStateKey' => 'stateKey', 'cartSnapshotKey' => 'cartSnapshotKey', 'promotionRevision' => 'promotionRevision', 'configRevision' => 'revision' ) as $input_key => $progress_key ) {
				if ( $input[ $input_key ] !== $progress[ $progress_key ] ) { return self::error( 'stale_cart', 409, array( 'activity_listener' => $progress ) ); }
			}
			if ( ! $progress['visible'] ) { return self::hidden( $out, 'promotion_unavailable' ); }
			$next = $progress['next'];
			if ( null === $next ) { return self::hidden( $out, 'highest_tier' ); }
			$config = $settings['recommendation'];
			if ( 'spend' !== $progress['basis'] ) { return self::hidden( $out, 'unsupported_calculation' ); }
			if ( (float) $next['remaining'] > (float) $next['threshold'] * $config['maxGapRatio'] || ( null !== $config['maxGapMinor'] && (float) $next['remaining'] > (float) $config['maxGapMinor'] ) ) { return self::hidden( $out, 'far_from_tier' ); }
			if ( ! $input['candidates'] ) { return self::hidden( $out, 'no_history' ); }
			$results = array(); $trials = 0;
			foreach ( $input['candidates'] as $candidate ) {
				$selection = self::selection( $candidate ); if ( ! $selection ) { continue; }
				[ $parent, $product ] = $selection;
				// 加购券不适用这个商品时，仍推荐商品，只是不送券。
				$offer_coupon = $coupon && \PeptideCore\Coupon_Rules::covers( $coupon, $product ) ? $coupon : null;
				if ( $id !== Promotions::owner( $product, Promotions::usable_ids( $before ) ) ) { continue; }
				$existing = 0; $line_key = ''; $matches = 0;
				foreach ( $before->cart_contents as $key => $line ) { if ( $line['data']->get_id() === $product->get_id() ) { $existing += (int) $line['quantity']; $line_key = (string) $key; $matches++; } }
				if ( $matches > 1 ) { continue; } // Separate custom-price/custom-option rows cannot be merged safely.
				// User-confirmed sales unit: exactly ONE box, never silently increase it to hit a tier.
				for ( $add = 1; $add <= 1; $add++ ) {
					// A cheap bound only skips amounts that cannot reach the target. The native trial decides savings.
					if ( ( (float) $product->get_price() * $add * 10 ** wc_get_price_decimals() ) + (float) $progress['eligibleSubtotal']['minor'] + 0.000001 < (float) $next['threshold'] ) { continue; }
					if ( ! self::quantity_ok( $product, $existing + $add, $add, $before ) ) { continue; }
					$key = $line_key ?: 'pc-quote-' . $product->get_id();
					$line = array( 'key' => $key, 'product_id' => $parent->get_id(), 'variation_id' => $product->is_type( 'variation' ) ? $product->get_id() : 0, 'variation' => $product->is_type( 'variation' ) ? $product->get_variation_attributes() : array(), 'quantity' => $existing + $add, 'data' => clone $product );
					if ( ++$trials > self::MAX_TRIALS ) { return self::hidden( $out, 'unsupported_calculation' ); }
					$after = Quote_Cart::copy( $before ); $after->cart_contents[ $key ] = $line; $after->calculate_quote();
					if ( $offer_coupon && ! Recommendation_Coupon::attach_to_quote( $after, $offer_coupon ) ) {
						// 券叠加不了：重新算一份不带券的报价。
						$offer_coupon = null;
						$after = Quote_Cart::copy( $before ); $line['data'] = clone $product; $after->cart_contents[ $key ] = $line; $after->calculate_quote();
					}
					$after_progress = Progress::for_cart( $after, $settings );
					$measure = (float) $after_progress['eligibleSubtotal']['minor'];
					$before_discount = (float) $progress['actualDiscount']['minor']; $after_discount = (float) $after_progress['actualDiscount']['minor'];
					if ( $measure < (float) $next['threshold'] || $after_discount <= $before_discount ) { continue; }
					$dto = self::result( $out, $parent, $product, $add, $before, $after, $progress, $after_progress, $next );
					$dto['couponDiscount'] = self::money( $offer_coupon ? (float) $after->get_coupon_discount_amount( $offer_coupon->get_code() ) : 0.0 );
					$dto = Recommendation_Coupon::issue( $dto, $settings, $offer_coupon, $cart_identity );
					if ( (float) $dto['afterAdd']['incrementalSavings']['minor'] <= 0 ) { continue; }
					$results[] = array( 'count' => $candidate['viewCount'], 'seen' => $candidate['lastViewedAt'] ?? '', 'burden' => (float) $dto['linePrice']['minor'], 'dto' => $dto );
					break;
				}
			}
			if ( ! $results ) { return self::hidden( $out, 'no_eligible_candidate' ); }
			usort( $results, static fn( $a, $b ) => ( $b['count'] <=> $a['count'] ) ?: ( strcmp( $b['seen'], $a['seen'] ) ?: ( ( $a['burden'] <=> $b['burden'] ) ?: ( $a['dto']['productId'] <=> $b['dto']['productId'] ) ) ) );
			return array( 'recommendation' => $results[0]['dto'], 'recommendations' => array( $results[0]['dto'] ) );
		} catch ( \Throwable $error ) { return self::hidden( $out, 'unsupported_calculation' ); }
	}

	private static function base( array $progress ): array {
		return array( 'version' => 1, 'visible' => false, 'reason' => '', 'cartStateKey' => $progress['stateKey'], 'cartSnapshotKey' => $progress['cartSnapshotKey'], 'promotionRevision' => $progress['promotionRevision'], 'configRevision' => $progress['revision'], 'expiresAt' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 45 ), 'currency' => $progress['currency'], 'currencyMinorUnit' => $progress['currencyMinorUnit'] );
	}
	private static function hidden( array $out, string $reason ): array { $out['reason'] = $reason; return array( 'recommendation' => $out, 'recommendations' => array() ); }
	private static function money( float $amount ): array {
		if ( ! is_finite( $amount ) || abs( $amount ) * 10 ** wc_get_price_decimals() > 9007199254740991 ) { throw new \RuntimeException( 'unsafe_amount' ); }
		return array( 'minor' => sprintf( '%.0f', round( $amount * 10 ** wc_get_price_decimals() ) ), 'text' => html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ) );
	}
	private static function selection( array $candidate ): ?array {
		$parent = Catalog_Read::product( $candidate['productId'] );
		if ( ! Products::public_catalog_product( $parent ) || ! $parent->is_purchasable() || ! $parent->is_in_stock() ) { return null; }
		$product = $candidate['variationId'] ? Catalog_Read::product( $candidate['variationId'] ) : $parent;
		if ( ! $product instanceof \WC_Product || ! in_array( $product->get_type(), array( 'simple', 'variation' ), true ) || 'publish' !== $product->get_status() || ! $product->is_purchasable() || ! $product->is_in_stock() || '' === (string) $product->get_price() || (float) $product->get_price() <= 0 || '' !== $product->get_post_password() ) { return null; }
		if ( $candidate['variationId'] ) {
			if ( ! $parent->is_type( 'variable' ) || ! $product instanceof \WC_Product_Variation || $product->get_parent_id() !== $parent->get_id() || ! $product->variation_is_active() || ! $product->variation_is_visible() ) { return null; }
			$attributes = $product->get_variation_attributes();
			$required = array();
			foreach ( $parent->get_attributes() as $attribute ) { if ( $attribute->get_variation() ) { $required[] = wc_variation_attribute_name( $attribute->get_name() ); } }
			if ( ! $required ) { return null; }
			foreach ( $required as $name ) { if ( empty( $attributes[ $name ] ) ) { return null; } }
			foreach ( $attributes as $value ) { if ( ! is_string( $value ) || '' === trim( $value ) ) { return null; } }
		}
		return array( $parent, $product );
	}
	private static function quantity_ok( \WC_Product $product, int $total, int $add, \WC_Cart $cart ): bool {
		if ( $product->is_sold_individually() && $total > 1 ) { return false; }
		// QuantityLimits' draft-order helper initializes the global Woo session. Use its public
		// classic input source and the same audited Store API limit filters, with explicit stock reads.
		$args = wc_get_quantity_input_args( array(), $product );
		$limit = apply_filters( 'woocommerce_store_api_product_quantity_limit', (float) $args['max_value'] > 0 ? (float) $args['max_value'] : 9999, $product, null );
		$minimum = apply_filters( 'woocommerce_store_api_product_quantity_minimum', $args['min_value'], $product, null );
		$maximum = apply_filters( 'woocommerce_store_api_product_quantity_maximum', $limit, $product, null );
		$multiple = apply_filters( 'woocommerce_store_api_product_quantity_multiple_of', $args['step'], $product, null );
		foreach ( array( $minimum, $maximum, $multiple ) as $value ) { if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) ) { return false; } }
		if ( $total < max( (float) $minimum, (float) $multiple ) || $total > (float) $maximum || (float) $multiple <= 0 || abs( $total / (float) $multiple - round( $total / (float) $multiple ) ) > 0.00001 ) { return false; }
		if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
			$stock_id = $product->get_stock_managed_by_id(); $stock = Catalog_Read::product( $stock_id ); $needed = $add;
			foreach ( $cart->get_cart() as $line ) { if ( $line['data']->get_stock_managed_by_id() === $stock_id ) { $needed += (int) $line['quantity']; } }
			// ReserveStock is read-only; conservatively retain even this cart's own holds.
			$reserved = ( new \Automattic\WooCommerce\Checkout\Helpers\ReserveStock() )->get_reserved_stock( $product, 0 );
			if ( ! $stock || $needed > (float) $stock->get_stock_quantity() - $reserved ) { return false; }
		}
		return true;
	}
	private static function discounts( \WC_Cart $cart ): float {
		$discount = (float) $cart->get_discount_total();
		foreach ( $cart->get_fees() as $fee ) { if ( (float) $fee->total < 0 ) { $discount -= (float) $fee->total; } }
		return $discount;
	}
	private static function result( array $out, \WC_Product $parent, \WC_Product $product, int $add, \WC_Cart $before, \WC_Cart $after, array $progress, array $after_progress, array $next ): array {
		$image_id = $product->get_image_id() ?: $parent->get_image_id();
		$out = array_merge( $out, array( 'visible' => true, 'reason' => 'most_viewed', 'productId' => $parent->get_id(), 'variationId' => $product->is_type( 'variation' ) ? $product->get_id() : 0, 'purchasableId' => $product->get_id(), 'name' => wp_strip_all_tags( $parent->get_name() ), 'specification' => $product->is_type( 'variation' ) ? wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, false ) ) : '', 'image' => array( 'url' => $image_id ? ( wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) ?: '' ) : '', 'alt' => wp_strip_all_tags( $parent->get_name() ) ), 'quantity' => $add, 'unitLabel' => 1 === $add ? 'box' : 'boxes', 'unitPrice' => self::money( (float) $product->get_price() ), 'linePrice' => self::money( (float) $product->get_price() * $add ), 'reachesNextTier' => true, 'targetNodeKey' => $next['key'], 'projectedDiscount' => $after_progress['actualDiscount'], 'additionalSavings' => self::money( ( (float) $after_progress['actualDiscount']['minor'] - (float) $progress['actualDiscount']['minor'] ) / 10 ** wc_get_price_decimals() ) ) );
		$complete = true;
		foreach ( $after->get_cart() as $line ) { if ( $line['data']->needs_shipping() ) { $complete = false; break; } }
		$out['afterAdd'] = array( 'eligibleSubtotal' => $after_progress['eligibleSubtotal'], 'remaining' => '0', 'remainingText' => self::money( 0 )['text'], 'targetReached' => true, 'promotionDiscount' => $out['projectedDiscount'], 'allDiscountsBefore' => self::money( self::discounts( $before ) ), 'allDiscountsAfter' => self::money( self::discounts( $after ) ), 'incrementalSavings' => self::money( self::discounts( $after ) - self::discounts( $before ) ), 'cartTotalBefore' => $complete ? self::money( (float) $before->get_total( 'edit' ) ) : null, 'cartTotalAfter' => $complete ? self::money( (float) $after->get_total( 'edit' ) ) : null, 'additionalPayable' => $complete ? self::money( (float) $after->get_total( 'edit' ) - (float) $before->get_total( 'edit' ) ) : null, 'totalsScope' => array( 'taxesIncluded' => false, 'shippingIncluded' => false, 'complete' => $complete, 'basis' => 'current_sale_prices' ), 'calculationStatus' => 'quoted' );
		return $out;
	}
}
