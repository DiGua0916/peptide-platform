<?php
/** Rehydrate only the authenticated current Woo session into a detached quote cart; never initialize a session. */
namespace PeptideCore\Activity;

use Automattic\WooCommerce\StoreApi\SessionHandler;
use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;
use PeptideCore\Modules\Products;

defined( 'ABSPATH' ) || exit;

final class Cart_Reader {
	private const MAX_ROWS = 100;
	private const ROW_KEYS = array( 'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax' );
	private const TOTAL_KEYS = array( 'subtotal', 'subtotal_tax', 'shipping_total', 'shipping_tax', 'shipping_taxes', 'discount_total', 'discount_tax', 'cart_contents_total', 'cart_contents_tax', 'cart_contents_taxes', 'fee_total', 'fee_tax', 'fee_taxes', 'total', 'total_tax' );

	/** No customer/cart/site selector is accepted. A provided bad token never falls back to another session. */
	public static function read( \WP_REST_Request $request ): array|\WP_Error {
		if ( \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return self::error( 'login_required', 401 ); }
		if ( ! class_exists( Quote_Cart::class ) || ! class_exists( '\WC_Session_Handler' ) || ! class_exists( CartTokenUtils::class ) || ! class_exists( SessionHandler::class ) ) { return self::error( 'cart_unavailable', 503 ); }
		try {
			$token = $request->get_header( 'Cart-Token' );
			if ( null !== $token ) {
				if ( ! is_string( $token ) || strlen( $token ) > 4096 || ! CartTokenUtils::validate_cart_token( $token ) ) { return self::error( 'invalid_cart_token', 401 ); }
				$payload = CartTokenUtils::get_cart_token_payload( $token );
				$id = $payload['user_id'] ?? null;
				if ( ! self::session_id( $id ) || ! is_int( $payload['exp'] ?? null ) || $payload['exp'] < time() || 'store-api' !== ( $payload['iss'] ?? null ) ) { return self::error( 'invalid_cart_token', 401 ); }
				// get_session() is a native read; init() would install shutdown saves and must never run here.
				$data = ( new SessionHandler() )->get_session( $id, null );
			} else {
				$handler = new \WC_Session_Handler();
				$cookie = $handler->get_session_cookie();
				if ( false === $cookie ) { return self::error( 'no_cart', 403 ); }
				if ( ! is_array( $cookie ) || 4 !== count( $cookie ) || ! self::session_id( $cookie[0] ?? null ) || ! self::timestamp( $cookie[1] ?? null ) || ! self::timestamp( $cookie[2] ?? null ) || (int) $cookie[1] < time() || (int) $cookie[2] > (int) $cookie[1] ) { return self::error( 'no_cart', 403 ); }
				$id = $cookie[0];
				$guest = str_starts_with( $id, 't_' );
				// Match native logout/account rules. A guest cookie remains a guest cart, never another account's cart.
				if ( ! $guest && ( ! is_user_logged_in() || (string) get_current_user_id() !== $id ) ) { return self::error( 'no_cart', 403 ); }
				$data = $handler->get_session( $id, null );
			}
			if ( ! is_array( $data ) ) { return self::error( 'cart_unavailable', 503 ); }
			$rows = self::unpack( $data['cart'] ?? null );
			if ( null === $rows || array() === $rows ) { return self::error( 'empty_cart', 409 ); }
			if ( ! is_array( $rows ) || count( $rows ) > self::MAX_ROWS || array_is_list( $rows ) ) { return self::error( 'unsupported_calculation', 409 ); }
			if ( ! Quote_Cart::supported_hooks() || ! Catalog_Read::supported() ) { return self::error( 'unsupported_calculation', 409 ); }
			$contents = array();
			foreach ( $rows as $key => $row ) {
				$validated = self::row( $key, $row );
				if ( is_wp_error( $validated ) ) { return $validated; }
				$contents[ $key ] = $validated;
			}
			$totals = self::unpack( $data['cart_totals'] ?? null );
			if ( ! is_array( $totals ) || ! isset( $totals['subtotal'], $totals['discount_total'], $totals['fee_total'] ) ) { return self::error( 'cart_unavailable', 503 ); }
			foreach ( $totals as $key => $value ) {
				if ( ! in_array( $key, self::TOTAL_KEYS, true ) || ( in_array( $key, array( 'shipping_taxes', 'cart_contents_taxes', 'fee_taxes' ), true ) ? ! self::amounts( $value, true ) : ! self::amount( $value, in_array( $key, array( 'fee_total', 'fee_tax', 'total_tax' ), true ) ) ) ) { return self::error( 'unsupported_calculation', 409 ); }
			}
			$coupons = self::unpack( $data['applied_coupons'] ?? null );
			$coupons = null === $coupons ? array() : $coupons;
			if ( ! is_array( $coupons ) || ! array_is_list( $coupons ) || count( $coupons ) > 20 ) { return self::error( 'unsupported_calculation', 409 ); }
			foreach ( $coupons as $coupon ) { if ( ! is_string( $coupon ) || '' === $coupon || strlen( $coupon ) > 200 || preg_match( '/[\x00-\x1f\x7f<>]/', $coupon ) || 1 !== preg_match( '//u', $coupon ) ) { return self::error( 'unsupported_calculation', 409 ); } }
			if ( count( array_unique( $coupons ) ) !== count( $coupons ) ) { return self::error( 'unsupported_calculation', 409 ); }
			$cart = new Quote_Cart( $contents, new \WC_Customer( get_current_user_id() ) );
			$cart->set_totals( $totals );
			$cart->set_applied_coupons( $coupons );
			foreach ( array( 'coupon_discount_totals', 'coupon_discount_tax_totals' ) as $key ) {
				$value = self::unpack( $data[ $key ] ?? null );
				if ( null !== $value ) {
					if ( ! self::amounts( $value ) ) { return self::error( 'unsupported_calculation', 409 ); }
					$setter = 'set_' . $key;
					$cart->$setter( $value );
				}
			}
			// Woo does not persist individual fees: do not invent pc-promo fees from aggregate fee_total.
			return array( 'cart' => $cart, 'persistedTotals' => $totals, 'rateKey' => self::identity( $id ) );
		} catch ( \Throwable $exception ) {
			return self::error( 'cart_unavailable', 503 );
		}
	}
	/** One-way native Woo identity for quote tickets; never exposes the session ID. */
	public static function identity( string $id ): string {
		return hash_hmac( 'sha256', 'pc-activity-recommendations:' . get_current_blog_id() . ':' . untrailingslashit( home_url( '/' ) ) . ':' . $id . ':' . get_current_user_id(), wp_salt( 'auth' ) );
	}

	private static function session_id( $id ): bool { return is_string( $id ) && 1 === preg_match( '/\A[A-Za-z0-9_-]{1,128}\z/', $id ); }
	private static function timestamp( $value ): bool { return ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/\A[0-9]{1,12}\z/', $value ) ) ) && (int) $value > 0; }
	private static function unpack( $value ) { return is_string( $value ) ? maybe_unserialize( $value ) : $value; }
	private static function integer( $value, int $min, int $max ): ?int {
		if ( is_bool( $value ) || ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && preg_match( '/\A(?:0|[1-9][0-9]{0,9})\z/', $value ) ) ) || ! is_numeric( $value ) || ! is_finite( (float) $value ) || floor( (float) $value ) !== (float) $value || $value < $min || $value > $max ) { return null; }
		return (int) $value;
	}
	private static function amount( $value, bool $negative = false ): bool {
		$decimals = wc_get_price_decimals();
		return ! is_bool( $value ) && is_scalar( $value ) && is_numeric( $value ) && is_finite( (float) $value ) && ( $negative || (float) $value >= 0 ) && $decimals >= 0 && $decimals <= 8 && abs( (float) $value ) * ( 10 ** $decimals ) <= 9007199254740991;
	}
	private static function amounts( $values, bool $negative = false ): bool {
		if ( ! is_array( $values ) || count( $values ) > 100 ) { return false; }
		foreach ( $values as $key => $value ) { if ( ( ! is_int( $key ) && ! is_string( $key ) ) || ! self::amount( $value, $negative ) ) { return false; } }
		return true;
	}
	private static function row( $key, $row ): array|\WP_Error {
		if ( ! is_string( $key ) || ! preg_match( '/\A[A-Za-z0-9_-]{1,64}\z/', $key ) || ! is_array( $row ) || array_diff( array_keys( $row ), self::ROW_KEYS ) || ( isset( $row['key'] ) && $row['key'] !== $key ) ) { return self::error( 'unsupported_calculation', 409 ); }
		$product_id = self::integer( $row['product_id'] ?? null, 1, 2147483647 );
		$variation_id = self::integer( $row['variation_id'] ?? 0, 0, 2147483647 );
		$quantity = self::integer( $row['quantity'] ?? null, 1, 10000 );
		if ( null === $product_id || null === $variation_id || null === $quantity ) { return self::error( 'unsupported_calculation', 409 ); }
		$product = Catalog_Read::product( $product_id );
		if ( ! Products::public_catalog_product( $product ) || ! $product->is_purchasable() || ! in_array( $product->get_type(), array( 'simple', 'variable' ), true ) ) { return self::error( 'unsupported_calculation', 409 ); }
		$attributes = $row['variation'] ?? array();
		if ( ! is_array( $attributes ) || count( $attributes ) > 50 ) { return self::error( 'unsupported_calculation', 409 ); }
		$priced = $product;
		if ( $variation_id ) {
			$priced = Catalog_Read::product( $variation_id );
			if ( ! $product->is_type( 'variable' ) || ! $priced instanceof \WC_Product_Variation || 'product_variation' !== get_post_type( $variation_id ) || $priced->get_parent_id() !== $product_id || 'publish' !== $priced->get_status() || '' !== $priced->get_post_password() || '' !== (string) get_post_field( 'post_password', $variation_id, 'raw' ) || ! $priced->variation_is_active() || ! $priced->variation_is_visible() || ! $priced->is_purchasable() ) { return self::error( 'unsupported_calculation', 409 ); }
			$required = array(); $allowed = array();
			foreach ( $product->get_attributes() as $attribute ) { if ( $attribute->get_variation() ) { $name = wc_variation_attribute_name( $attribute->get_name() ); $required[] = $name; $allowed[ $name ] = $attribute; } }
			$keys = array_keys( $attributes );sort( $keys );sort( $required );
			if ( ! $required || $required !== $keys ) { return self::error( 'unsupported_calculation', 409 ); }
			$fixed = $priced->get_variation_attributes();
			foreach ( $attributes as $name => $value ) { if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 2000 || ! array_key_exists( $name, $fixed ) || ( '' !== $fixed[ $name ] && $fixed[ $name ] !== $value ) || ( '' === $fixed[ $name ] && ! in_array( $value, $allowed[ $name ]->get_slugs(), true ) ) ) { return self::error( 'unsupported_calculation', 409 ); } }
		} elseif ( ! $product->is_type( 'simple' ) || $attributes ) { return self::error( 'unsupported_calculation', 409 ); }
		if ( ! $priced->is_in_stock() || ! $priced->has_enough_stock( $quantity ) || ( $priced->is_sold_individually() && 1 !== $quantity ) || $quantity < $priced->get_min_purchase_quantity() || ( $priced->get_max_purchase_quantity() >= 0 && $quantity > $priced->get_max_purchase_quantity() ) || ! self::amount( $priced->get_price() ) ) { return self::error( 'unsupported_calculation', 409 ); }
		if ( isset( $row['data_hash'] ) && ( ! is_string( $row['data_hash'] ) || ! hash_equals( wc_get_cart_item_data_hash( $priced ), $row['data_hash'] ) ) ) { return self::error( 'unsupported_calculation', 409 ); }
		foreach ( array( 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax' ) as $field ) { if ( ! isset( $row[ $field ] ) || ! self::amount( $row[ $field ] ) ) { return self::error( 'cart_unavailable', 503 ); } }
		$tax = $row['line_tax_data'] ?? array();
		if ( ! is_array( $tax ) || array_diff( array_keys( $tax ), array( 'subtotal', 'total' ) ) ) { return self::error( 'unsupported_calculation', 409 ); }
		foreach ( $tax as $values ) { if ( ! self::amounts( $values ) ) { return self::error( 'unsupported_calculation', 409 ); } }
		$row['key'] = $key; $row['product_id'] = $product_id; $row['variation_id'] = $variation_id; $row['quantity'] = $quantity; $row['variation'] = $attributes; $row['data'] = clone $priced;
		return $row;
	}
	private static function error( string $reason, int $status ): \WP_Error {
		return new \WP_Error( 'pc_activity_cart_' . $reason, '当前购物车暂不可用于凑单试算。请重新读取购物车后重试。', array( 'status' => $status, 'reason' => $reason ) );
	}
}
