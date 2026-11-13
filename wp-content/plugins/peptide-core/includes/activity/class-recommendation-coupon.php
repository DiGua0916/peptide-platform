<?php
/** Native one-box add + optional existing coupon. Quotes never mutate a shopper's cart. */
namespace PeptideCore\Activity;

use PeptideCore\Coupon_Rules;
use PeptideCore\Coupons;
use PeptideCore\Customer_Segments;

defined( 'ABSPATH' ) || exit;

final class Recommendation_Coupon {
	public const EXTENSION = 'activityRecommendation';
	private const CLAIM_PREFIX = '_pc_activity_offer_';
	private const CLEANUP_HOOK = 'pc_activity_offer_cleanup';
	private static array $requests = array();
	private static array $pending = array();
	private static array $results = array();

	public static function register_hooks(): void {
		add_filter( 'rest_request_before_callbacks', array( self::class, 'before_request' ), 30, 3 );
		add_filter( 'woocommerce_store_api_add_to_cart_data', array( self::class, 'prepare_add' ), 90, 2 );
		add_action( 'internal_woocommerce_cart_item_added_from_user_request', array( self::class, 'after_add' ), 90, 2 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'after_request' ), 90, 3 );
		add_action( self::CLEANUP_HOOK, array( self::class, 'cleanup_claims' ) );
	}

	/** Only a conservative native coupon subset is eligible for a read-only savings promise. */
	public static function coupon( array $settings ): ?\WC_Coupon {
		$config = $settings['recommendationCoupon'] ?? array();
		if ( empty( $config['enabled'] ) || empty( $config['couponId'] ) || ! wc_coupons_enabled() || ! self::coupon_hooks_supported() || 'WC_Coupon_Data_Store_CPT' !== \WC_Data_Store::load( 'coupon' )->get_current_class_name() ) { return null; }
		$coupon = new \WC_Coupon( (int) $config['couponId'] );
		return self::supported_coupon( $coupon ) ? $coupon : null;
	}

	public static function supported_coupon( \WC_Coupon $coupon ): bool {
		if ( ! self::coupon_hooks_supported() || get_class( $coupon ) !== \WC_Coupon::class || ! $coupon->get_id() || 'shop_coupon' !== get_post_type( $coupon->get_id() ) || '' !== Coupons::unusable_reason( $coupon ) || ! in_array( $coupon->get_discount_type(), array( 'percent', 'fixed_product', 'fixed_cart' ), true ) || ! is_numeric( $coupon->get_amount() ) || ! is_finite( (float) $coupon->get_amount() ) || (float) $coupon->get_amount() <= 0 || ( 'percent' === $coupon->get_discount_type() && (float) $coupon->get_amount() > 100 ) || $coupon->get_free_shipping() || $coupon->get_email_restrictions() || $coupon->get_meta( '_pc_loyalty_user' ) || 'stack' !== Coupon_Rules::strategy( $coupon ) || 'yes' === get_post_meta( $coupon->get_id(), Coupon_Rules::FIRST_META, true ) ) { return false; }
		$segment = get_post_meta( $coupon->get_id(), Customer_Segments::COUPON_META, true );
		return ! is_array( $segment ) || 'all' === ( $segment['customers'] ?? 'all' );
	}

	/**
	 * 顾客已经用上的优惠码（2026-11-08）：它们已在真实购物车里通过校验，报价只需按原生规则算出折扣。
	 * 只接受原生的百分比/固定金额类型；未知的优惠码插件或类型一律放弃报价。
	 */
	public static function supported_existing_coupon( \WC_Coupon $coupon ): bool {
		return self::coupon_hooks_supported() && get_class( $coupon ) === \WC_Coupon::class && $coupon->get_id() > 0 && 'shop_coupon' === get_post_type( $coupon->get_id() )
			&& in_array( $coupon->get_discount_type(), array( 'percent', 'fixed_product', 'fixed_cart' ), true ) && is_numeric( $coupon->get_amount() ) && is_finite( (float) $coupon->get_amount() )
			&& (float) $coupon->get_amount() >= 0 && ! ( 'percent' === $coupon->get_discount_type() && (float) $coupon->get_amount() > 100 );
	}

	/** Known callbacks are read-only; an unknown coupon integration cannot be guessed. */
	private static function coupon_hooks_supported(): bool {
		global $wp_filter;
		$allowed = array( 'woocommerce_coupon_code' => array( 'wc_sanitize_coupon_code', 'wc_strtolower' ), 'woocommerce_coupon_error' => array( 'WC_Brands_Coupons::brand_exclusion_error' ), 'woocommerce_coupon_is_valid' => array( Coupon_Rules::class . '::validate', \PeptideCore\First_Order_Offer::class . '::validate_stack', 'WC_Brands_Coupons::is_coupon_valid', \PeptideCore\Loyalty::class . '::validate_coupon', \PeptideCore\Product_Offers::class . '::coupon_valid', \PeptideCore\Manual_Orders::class . '::block_coupons' ), 'woocommerce_coupon_is_valid_for_product' => array( 'WC_Brands_Coupons::is_valid_for_product', \PeptideCore\Product_Offers::class . '::coupon_product_valid' ) );
		foreach ( (array) $wp_filter as $name => $hook ) {
			if ( ! str_starts_with( $name, 'woocommerce_coupon_' ) && ! str_starts_with( $name, 'woocommerce_get_shop_coupon_' ) ) { continue; }
			// Analytics registers this native SAVE action. Detached quote never saves a coupon.
			if ( 'woocommerce_coupon_updated_props' === $name ) { continue; }
			foreach ( ( $hook->callbacks ?? array() ) as $priority => $callbacks ) { foreach ( $callbacks as $callback ) {
				if ( Quote_Cart::native_default_coupon_callback( $name, $priority, $callback ) ) { continue; }
				$fn = $callback['function'] ?? null;
				$id = is_array( $fn ) && 2 === count( $fn ) ? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . $fn[1] : ( is_string( $fn ) ? $fn : '' );
				if ( ! in_array( $id, $allowed[ $name ] ?? array(), true ) ) { return false; }
			} }
		}
		return true;
	}

	public static function fingerprint( \WC_Coupon $coupon ): string {
		$meta = array();
		foreach ( array( Coupon_Rules::STRATEGY_META, Coupon_Rules::START_META, Coupon_Rules::FIRST_META, Coupon_Rules::MIN_SPEND_META, Coupon_Rules::MIN_QTY_META, Customer_Segments::COUPON_META ) as $key ) { $meta[ $key ] = get_post_meta( $coupon->get_id(), $key, true ); }
		return hash_hmac( 'sha256', wp_json_encode( array( $coupon->get_data(), $meta ) ), wp_salt( 'auth' ) );
	}

	/** Private management choices; the public configuration/quote never contains a coupon code. */
	public static function choices( int $selected = 0 ): array {
		$ids = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 300, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'DESC' ) );
		if ( $selected && 'shop_coupon' === get_post_type( $selected ) && ! in_array( $selected, array_map( 'intval', $ids ), true ) ) { $ids[] = $selected; }
		$out = array();
		foreach ( $ids as $id ) {
			$coupon = new \WC_Coupon( (int) $id );
			$out[] = array( 'id' => (int) $id, 'label' => Coupon_Rules::display_code( $coupon ), 'ruleText' => Coupon_Rules::rule_text( $coupon ), 'status' => Coupon_Rules::status( $coupon ), 'supported' => self::supported_coupon( $coupon ) );
		}
		return $out;
	}

	/** Native validation against the detached after-add cart; no apply_coupon/session calls. */
	public static function attach_to_quote( Quote_Cart $cart, \WC_Coupon $coupon ): bool {
		if ( ! self::supported_coupon( $coupon ) || in_array( $coupon->get_code(), $cart->get_applied_coupons(), true ) ) { return false; }
		$valid = ( new \WC_Discounts( $cart ) )->is_coupon_valid( $coupon );
		if ( is_wp_error( $valid ) || true !== $valid ) { return false; }
		try { $cart->attach_quote_coupon( $coupon ); } catch ( \RuntimeException $error ) { return false; }
		$cart->calculate_quote();
		return (float) $cart->get_coupon_discount_amount( $coupon->get_code() ) > 0;
	}

	/** Signed freshness ticket, deliberately no coupon code, customer ID, address or amount. */
	public static function issue( array $dto, array $settings, ?\WC_Coupon $coupon, string $identity = '' ): array {
		if ( 1 !== ( $dto['quantity'] ?? null ) ) { throw new \RuntimeException( 'one_box_only' ); }
		$claims = array( 'v' => 1, 'expires' => time() + 45, 'identity' => $identity, 'snapshot' => $dto['cartSnapshotKey'], 'activity' => $dto['promotionRevision'], 'config' => $dto['configRevision'], 'product' => $dto['purchasableId'], 'coupon' => $coupon ? $coupon->get_id() : 0, 'couponHash' => $coupon ? self::fingerprint( $coupon ) : '', 'nonce' => bin2hex( random_bytes( 16 ) ) );
		$payload = rtrim( strtr( base64_encode( wp_json_encode( $claims ) ), '+/', '-_' ), '=' );
		$token = $payload . '.' . hash_hmac( 'sha256', self::site_context() . $payload, wp_salt( 'auth' ) );
		$dto['offerToken'] = preg_match( '/\A[a-f0-9]{64}\z/', $identity ) ? $token : null;
		$dto['automaticCoupon'] = array( 'enabled' => null !== $coupon, 'message' => $coupon ? (string) ( $settings['recommendationCoupon']['message'] ?? '' ) : '', 'discount' => $dto['couponDiscount'] ?? array( 'minor' => '0', 'text' => '' ), 'requiresCodeEntry' => false );
		return $dto;
	}

	private static function site_context(): string { return get_current_blog_id() . '|' . home_url( '/' ) . '|activity-recommendation|'; }
	private static function ticket( $token ): ?array {
		if ( ! is_string( $token ) || strlen( $token ) > 2048 || 1 !== preg_match( '/\A([a-zA-Z0-9_-]+)\.([a-f0-9]{64})\z/', $token, $matches ) || ! hash_equals( hash_hmac( 'sha256', self::site_context() . $matches[1], wp_salt( 'auth' ) ), $matches[2] ) ) { return null; }
		$json = base64_decode( strtr( $matches[1], '-_', '+/' ), true );
		$claims = is_string( $json ) ? json_decode( $json, true, 8 ) : null;
		$keys = array( 'v', 'expires', 'identity', 'snapshot', 'activity', 'config', 'product', 'coupon', 'couponHash', 'nonce' );
		if ( ! is_array( $claims ) || array_keys( $claims ) !== $keys || 1 !== $claims['v'] || ! is_int( $claims['expires'] ) || $claims['expires'] <= time() || $claims['expires'] > time() + 45 || ! is_int( $claims['config'] ) || $claims['config'] < 0 || ! is_int( $claims['product'] ) || $claims['product'] < 1 || ! is_int( $claims['coupon'] ) || $claims['coupon'] < 0 || ! is_string( $claims['nonce'] ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $claims['nonce'] ) ) { return null; }
		foreach ( array( 'identity', 'snapshot', 'activity' ) as $key ) { if ( ! is_string( $claims[ $key ] ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $claims[ $key ] ) ) { return null; } }
		if ( ! is_string( $claims['couponHash'] ) || ( $claims['coupon'] ? ! preg_match( '/\A[a-f0-9]{64}\z/', $claims['couponHash'] ) : '' !== $claims['couponHash'] ) ) { return null; }
		return $claims;
	}

	public static function before_request( $response, $handler, $request ) {
		if ( $request instanceof \WP_REST_Request ) { self::$requests[] = $request; }
		return $response;
	}

	private static function reject(): void { throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'pc_activity_offer_stale', __( 'This offer has changed. Refresh your cart and select the current offer.', 'peptide-core' ), 409 ); }

	/** Runs inside Woo's nonce/token-checked add route, before its native stock/variation checks. */
	public static function prepare_add( $data, $request ) {
		if ( ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() || '/wc/store/v1/cart/add-item' !== $request->get_route() ) { return $data; }
		$extensions = $request->get_param( 'extensions' );
		if ( ! is_array( $extensions ) || ! isset( $extensions['peptide_core'][ self::EXTENSION ] ) ) { return $data; }
		$offer = $extensions['peptide_core'][ self::EXTENSION ];
		if ( end( self::$requests ) !== $request || ! is_array( $offer ) || array_keys( $offer ) !== array( 'offerToken' ) || ! is_array( $data ) || ! empty( $data['cart_item_data'] ) || 1.0 !== (float) ( $data['quantity'] ?? 0 ) || ! is_numeric( $data['id'] ?? null ) ) { self::reject(); }
		$claims = self::ticket( $offer['offerToken'] );
		if ( ! $claims || (int) $data['id'] !== $claims['product'] || (float) $data['id'] !== (float) $claims['product'] || ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) { self::reject(); }
		$cart = WC()->cart;
		$session_id = WC()->session ? WC()->session->get_customer_id() : null;
		if ( ! is_string( $session_id ) || '' === $session_id || ! hash_equals( $claims['identity'], Cart_Reader::identity( $session_id ) ) ) { self::reject(); }
		$settings = Store::effective();
		if ( empty( $settings['enabled'] ) || empty( $settings['recommendation']['enabled'] ) || ( $settings['revision'] ?? -1 ) !== $claims['config'] ) { self::reject(); }
		$progress = Progress::for_cart( $cart, $settings );
		if ( ! hash_equals( $claims['snapshot'], (string) $progress['cartSnapshotKey'] ) || ! hash_equals( $claims['activity'], (string) $progress['promotionRevision'] ) ) { self::reject(); }
		$coupon = self::coupon( $settings );
		if ( ! empty( $settings['recommendationCoupon']['enabled'] ) && ! $coupon ) { self::reject(); }
		// 报价时加购券叠加不了（顾客已有不可叠加的码等），票据里券为 0：只加商品，不加券。
		if ( 0 === $claims['coupon'] ) { $coupon = null; }
		elseif ( ! $coupon || $claims['coupon'] !== $coupon->get_id() || ! hash_equals( $claims['couponHash'], self::fingerprint( $coupon ) ) ) { self::reject(); }
		$before = self::product_quantity( $cart, $claims['product'] );
		// Unique primary-key claim stops the SAME ticket adding twice, including simultaneous POSTs.
		if ( ! self::claim( $offer['offerToken'], $claims['expires'] ) ) { self::reject(); }
		self::$pending[ spl_object_id( $request ) ] = array( 'claims' => $claims, 'coupon' => $coupon, 'before' => $before );
		return $data;
	}

	private static function product_quantity( \WC_Cart $cart, int $id ): float {
		$quantity = 0.0;
		foreach ( $cart->get_cart() as $line ) { if ( ( $line['data'] ?? null ) instanceof \WC_Product && $line['data']->get_id() === $id ) { $quantity += (float) $line['quantity']; } }
		return $quantity;
	}

	/** Only the native success hook can apply a coupon; failed native adds never reach here. */
	public static function after_add( $product_id, $quantity ): void {
		$request = end( self::$requests );
		$scope = $request instanceof \WP_REST_Request ? spl_object_id( $request ) : 0;
		$pending = self::$pending[ $scope ] ?? null;
		if ( ! $pending ) { return; }
		unset( self::$pending[ $scope ] );
		$result = array( 'requested' => true, 'added' => false, 'couponRequested' => null !== $pending['coupon'], 'couponApplied' => false, 'reason' => 'add_mismatch' );
		self::$results[ $scope ] = $result;
		try {
			$cart = WC()->cart;
			if ( ! $cart instanceof \WC_Cart || (int) $product_id !== $pending['claims']['product'] || 1.0 !== (float) $quantity || abs( self::product_quantity( $cart, (int) $product_id ) - $pending['before'] - 1.0 ) > 0.000001 ) { return; }
			$result['added'] = true;
			$result['reason'] = 'added';
			$coupon = $pending['coupon'];
			if ( $coupon ) {
				// New cart rows have no line subtotals until Woo calculates them. Do this only AFTER success.
				$cart->calculate_totals();
				if ( $cart->has_discount( $coupon->get_code() ) ) { $result['couponApplied'] = (float) $cart->get_coupon_discount_amount( $coupon->get_code() ) > 0; $result['reason'] = $result['couponApplied'] ? 'applied' : 'coupon_not_applied'; }
				else {
					$settings = Store::effective(); $current = self::coupon( $settings );
					if ( ! $current || $settings['revision'] !== $pending['claims']['config'] || ! hash_equals( $pending['claims']['couponHash'], self::fingerprint( $current ) ) ) { $result['reason'] = 'coupon_changed'; }
					else {
						// Reuse native Store API coupon validation, individual-use and actual customer eligibility.
						( new \Automattic\WooCommerce\StoreApi\Utilities\CartController() )->apply_coupon( $current->get_code() );
						$cart->calculate_totals();
						$result['couponApplied'] = $cart->has_discount( $current->get_code() ) && (float) $cart->get_coupon_discount_amount( $current->get_code() ) > 0;
						$result['reason'] = $result['couponApplied'] ? 'applied' : 'coupon_not_applied';
					}
				}
			}
		} catch ( \Throwable $error ) { $result['reason'] = 'coupon_not_applied'; }
		finally { self::$results[ $scope ] = $result; }
	}

	/** Native response totals stay untouched; append an honest, code-free action result. */
	public static function after_request( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request ) { return $response; }
		$scope = spl_object_id( $request );
		$result = self::$results[ $scope ] ?? null;
		unset( self::$pending[ $scope ], self::$results[ $scope ] );
		self::$requests = array_values( array_filter( self::$requests, static fn( $active ) => $active !== $request ) );
		if ( $result && $response instanceof \WP_REST_Response && 201 === $response->get_status() ) {
			$data = $response->get_data();
			if ( is_array( $data ) && isset( $data['items'], $data['totals'] ) ) {
				$extensions = $data['extensions'] ?? array();
				if ( $extensions instanceof \stdClass ) { $extensions = get_object_vars( $extensions ); }
				if ( ! is_array( $extensions ) ) { return $response; }
				$core = $extensions['peptide_core'] ?? array();
				if ( $core instanceof \stdClass ) { $core = get_object_vars( $core ); }
				if ( ! is_array( $core ) ) { return $response; }
				$core['activity_recommendation'] = $result;
				$extensions['peptide_core'] = $core;
				$data['extensions'] = $extensions;
				$response->set_data( $data );
				$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			}
		}
		return $response;
	}

	/** Security-only ticket keys and expiry, never a cart/session/customer record. */
	private static function claim( string $token, int $expires ): bool {
		global $wpdb;
		$name = self::CLAIM_PREFIX . hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", $name, (string) $expires ) );
		if ( 1 !== $inserted || $wpdb->last_error ) { return false; }
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) { wp_schedule_single_event( $expires + 60, self::CLEANUP_HOOK ); }
		return true;
	}
	public static function cleanup_claims(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED)<%d LIMIT 1000", $wpdb->esc_like( self::CLAIM_PREFIX ) . '%', time() ) );
		$left = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", $wpdb->esc_like( self::CLAIM_PREFIX ) . '%' ) );
		if ( $left && ! wp_next_scheduled( self::CLEANUP_HOOK ) ) { wp_schedule_single_event( time() + 300, self::CLEANUP_HOOK ); }
	}
}
