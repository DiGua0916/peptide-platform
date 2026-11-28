<?php
/**
 * Same-Origin Full-Funnel Collector (`/wp-json/stealth-capi/v1/pulse`)
 * + Dual-Path Native Server Hooks (Classic + WooCommerce Store API Block Cart, QuickView ViewContent, Checkout Entry & Address/Payment Milestones).
 */

namespace StealthCapiBridge;

defined( 'ABSPATH' ) || exit;

final class REST_Pulse_API {

	private const ALLOWED_PULSE_EVENTS = array(
		'PageView',
		'ViewContent',
		'Search',
		'Contact',
		'CompleteRegistration',
		'AddToCart',
		'InitiateCheckout',
		'AddPaymentInfo',
		'ContextSync',
	);

	private static array $fired_keys = array();

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );

		$cfg = Config_Manager::get_config();
		if ( ! empty( $cfg['enable_server_shallow'] ) ) {
			// Registration server hook.
			add_action( 'user_register', array( self::class, 'on_user_register' ), 25, 1 );

			// Classic + Block Store API AddToCart server hooks.
			add_action( 'woocommerce_add_to_cart', array( self::class, 'on_classic_add_to_cart' ), 25, 6 );
			add_action( 'internal_woocommerce_cart_item_added_from_user_request', array( self::class, 'on_store_api_add_to_cart' ), 25, 2 );
		}
	}

	public static function register_routes(): void {
		register_rest_route(
			'stealth-capi/v1',
			'/pulse',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_pulse' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle first-party browser pulse from `fp-pulse.js`.
	 */
	public static function handle_pulse( \WP_REST_Request $request ): \WP_REST_Response {
		if ( Config_Manager::is_safe_or_bot_request( true ) ) {
			return new \WP_REST_Response( array( 'accepted' => false, 'reason' => 'safe_or_bot_filtered', 'deliveries' => 0 ), 202 );
		}
		$tenant = Config_Manager::resolve_current_tenant();
		if ( ! $tenant ) {
			return new \WP_REST_Response( array( 'accepted' => false, 'reason' => 'inactive', 'deliveries' => 0 ), 202 );
		}
		if ( Config_Manager::is_organic_retention_hub( $tenant ) ) {
			return new \WP_REST_Response( array( 'accepted' => false, 'reason' => 'organic_retention_hub_zero_pixel', 'deliveries' => 0 ), 202 );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$raw    = $request->get_body();
			$params = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		}
		if ( ! is_array( $params ) ) {
			return new \WP_REST_Response( array( 'accepted' => false, 'reason' => 'invalid_json', 'deliveries' => 0 ), 400 );
		}

		$event_name = isset( $params['event_name'] ) ? trim( (string) $params['event_name'] ) : '';
		if ( ! in_array( $event_name, self::ALLOWED_PULSE_EVENTS, true ) ) {
			return new \WP_REST_Response( array( 'accepted' => false, 'reason' => 'unsupported_event', 'deliveries' => 0 ), 400 );
		}

		$cand_url = ! empty( $params['raw_page_url'] ) ? esc_url_raw( (string) $params['raw_page_url'] ) : '';
		$cand_ua  = ! empty( $params['client_user_agent'] ) ? sanitize_text_field( (string) $params['client_user_agent'] ) : '';
		if ( Config_Manager::is_safe_or_bot_request( false, $cand_url, $cand_ua ) ) {
			return new \WP_REST_Response( array( 'accepted' => false, 'reason' => 'safe_or_bot_filtered', 'deliveries' => 0 ), 202 );
		}

		$live_ctx = Context_Freezer::capture_live_browser_context();
		if ( '' === trim( $live_ctx['client_user_agent'] ) ) {
			return new \WP_REST_Response( array( 'accepted' => false, 'reason' => 'empty_user_agent', 'deliveries' => 0 ), 202 );
		}

		// Per-IP burst rate limit (max 160 shallow/pulse events / 60s per IP).
		if ( '' !== $live_ctx['client_ip'] && ! self::allow_rate_limit( $live_ctx['client_ip'] ) ) {
			return new \WP_REST_Response( array( 'accepted' => false, 'reason' => 'rate_limited', 'deliveries' => 0 ), 429 );
		}

		$fbp = ! empty( $params['fbp'] ) ? Config_Manager::sanitize_fbp_cookie( (string) $params['fbp'] ) : $live_ctx['fbp'];
		$fbc = ! empty( $params['fbc'] ) ? Config_Manager::sanitize_fbc_cookie( (string) $params['fbc'] ) : $live_ctx['fbc'];
		$eid = ! empty( $params['external_id'] ) ? Config_Manager::sanitize_external_id( (string) $params['external_id'] ) : $live_ctx['external_id'];

		$incoming_pii = Context_Freezer::sanitize_pii_fields(
			array(
				'email'        => (string) ( $params['email'] ?? '' ),
				'phone'        => (string) ( $params['phone'] ?? '' ),
				'first_name'   => (string) ( $params['first_name'] ?? '' ),
				'last_name'    => (string) ( $params['last_name'] ?? '' ),
				'address_1'    => (string) ( $params['address_1'] ?? '' ),
				'city'         => (string) ( $params['city'] ?? '' ),
				'state'        => (string) ( $params['state'] ?? '' ),
				'postal_code'  => (string) ( $params['postal_code'] ?? '' ),
				'country_code' => (string) ( $params['country_code'] ?? '' ),
			)
		);

		// Persist latest valid browser context + progressive PII into WooCommerce session.
		$merged_ctx = Context_Freezer::save_context_to_session(
			array_merge(
				$live_ctx,
				$incoming_pii,
				array(
					'fbp'         => $fbp ?: $live_ctx['fbp'],
					'fbc'         => $fbc ?: $live_ctx['fbc'],
					'external_id' => $eid ?: $live_ctx['external_id'],
				)
			)
		);

		// Internal silent progressive PII lock (`ContextSync` does not dispatch an ad event).
		if ( 'ContextSync' === $event_name ) {
			return new \WP_REST_Response(
				array(
					'accepted' => true,
					'synced'   => true,
				),
				202
			);
		}

		$best_pii = Context_Freezer::extract_best_buyer_pii( $incoming_pii );
		$fbp      = $merged_ctx['fbp'] ?: $fbp;
		$fbc      = $merged_ctx['fbc'] ?: $fbc;
		$eid      = $merged_ctx['external_id'] ?: ( $eid ?: $best_pii['external_id'] );

		$currency    = ! empty( $params['currency'] ) ? sanitize_text_field( (string) $params['currency'] ) : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' );
		$total_value = isset( $params['total_value'] ) ? round( (float) $params['total_value'], 2 ) : 0.0;
		$items       = isset( $params['items'] ) && is_array( $params['items'] ) ? self::sanitize_pulse_items( $params['items'] ) : array();

		// Server-side SKU & price enrichment for QuickView / modal `ViewContent` (`product_id`).
		if ( 'ViewContent' === $event_name && empty( $items ) && ! empty( $params['product_id'] ) && function_exists( 'wc_get_product' ) ) {
			$pid     = (int) $params['product_id'];
			$product = $pid > 0 ? wc_get_product( $pid ) : null;
			if ( $product instanceof \WC_Product && 'publish' === $product->get_status() && '' === (string) $product->get_post_password() ) {
				$raw_sku = trim( (string) $product->get_sku() );
				if ( '' === $raw_sku ) {
					$raw_sku = 'WC_' . $product->get_id();
				}
				$u_price = $product->is_type( 'variable' )
					? round( (float) $product->get_variation_price( 'min' ), 2 )
					: round( (float) $product->get_price(), 2 );
				$items   = array(
					array(
						'raw_sku'    => $raw_sku,
						'quantity'   => 1,
						'unit_price' => $u_price,
					),
				);
				if ( $total_value <= 0.0 ) {
					$total_value = $u_price;
				}
			}
		}

		$is_test_traffic = (
			false !== stripos( $cand_url, 'capi_test=' )
			|| false !== stripos( $cand_url, 'test_event_code=' )
			|| false !== stripos( $cand_url, 'scapi_test=' )
			|| false !== stripos( $fbc, '_test' )
			|| 0 === stripos( (string) ( $params['action_uuid'] ?? '' ), 'test_' )
		);

		$action_uuid = ! empty( $params['action_uuid'] )
			? preg_replace( '/[^a-zA-Z0-9_\-:.]/', '', (string) $params['action_uuid'] )
			: sprintf( 'pls_%d_%s', (int) $tenant['blog_id'], wp_generate_uuid4() );

		// Server-authoritative cart hydration & session deduplication for `InitiateCheckout` and `AddPaymentInfo`.
		if ( in_array( $event_name, array( 'InitiateCheckout', 'AddPaymentInfo' ), true ) ) {
			$cart = Context_Freezer::extract_cart_snapshot();
			if ( $cart['has_items'] ) {
				$items       = $cart['items'];
				$total_value = $cart['total_value'];
				$currency    = $cart['currency'];
			}
			if ( empty( $items ) && $total_value <= 0.0 ) {
				return new \WP_REST_Response( array( 'accepted' => false, 'reason' => 'empty_cart', 'deliveries' => 0 ), 202 );
			}
			if ( ! $is_test_traffic ) {
				$seed      = $eid ?: ( $best_pii['email'] ?: $live_ctx['client_ip'] );
				$lock_uuid = Context_Freezer::acquire_session_funnel_lock( $event_name, (int) $tenant['blog_id'], $cart['cart_sig'], (string) $seed );
				if ( '' === $lock_uuid ) {
					return new \WP_REST_Response(
						array(
							'accepted'     => true,
							'deduplicated' => true,
							'deliveries'   => 0,
						),
						202
					);
				}
				$action_uuid = $lock_uuid;
			}
		}

		$default_path = in_array( $event_name, array( 'InitiateCheckout', 'AddPaymentInfo' ), true ) ? '/checkout/' : '/';
		$raw_page_url = Context_Freezer::normalize_to_public_a_site_url( '' !== $cand_url ? $cand_url : $live_ctx['raw_page_url'], $default_path );
		// Preserve URL test query flags if present on explicit test link so Gateway's test-traffic isolator recognizes it.
		if ( $is_test_traffic && '' !== $cand_url ) {
			$qs = (string) wp_parse_url( $cand_url, PHP_URL_QUERY );
			if ( '' !== $qs ) {
				$raw_page_url .= ( false === strpos( $raw_page_url, '?' ) ? '?' : '&' ) . $qs;
			}
		}

		$payload = array(
			'site_key'          => (string) $tenant['site_key'],
			'blog_id'           => (int) $tenant['blog_id'],
			'action_uuid'       => $action_uuid,
			'order_reference'   => '',
			'consent_granted'   => isset( $params['consent_granted'] ) ? (bool) $params['consent_granted'] : $live_ctx['consent_granted'],
			'event_name'        => $event_name,
			'occurred_at'       => time(),
			'raw_page_url'      => $raw_page_url,
			'currency'          => $currency,
			'total_value'       => $total_value,
			'client_ip'         => $merged_ctx['client_ip'] ?: $live_ctx['client_ip'],
			'client_user_agent' => $merged_ctx['client_user_agent'] ?: $live_ctx['client_user_agent'],
			'fbp'               => $fbp,
			'fbc'               => $fbc,
			'external_id'       => $eid,
			'email'             => $best_pii['email'],
			'phone'             => $best_pii['phone'],
			'first_name'        => $best_pii['first_name'],
			'last_name'         => $best_pii['last_name'],
			'city'              => $best_pii['city'],
			'state'             => $best_pii['state'],
			'postal_code'       => $best_pii['postal_code'],
			'country_code'      => $best_pii['country_code'],
			'items'             => $items,
		);

		$res = Config_Manager::dispatch_to_gateway( $payload, $tenant );
		if ( ! $res['ok'] ) {
			Retry_Reconciler::enqueue_event_retry( $payload, $tenant );
		}

		return new \WP_REST_Response(
			array(
				'accepted'    => true,
				'action_uuid' => $action_uuid,
				'event_id'    => (string) ( $res['data']['event_id'] ?? '' ),
				'relayed'     => $res['ok'],
			),
			202
		);
	}

	private static function sanitize_pulse_items( array $raw_items ): array {
		$clean = array();
		foreach ( array_slice( $raw_items, 0, 100 ) as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$sku = isset( $it['raw_sku'] ) ? trim( sanitize_text_field( (string) $it['raw_sku'] ) ) : '';
			$qty = isset( $it['quantity'] ) ? max( 1, (int) $it['quantity'] ) : 1;
			if ( '' === $sku ) {
				continue;
			}
			$row = array(
				'raw_sku'  => substr( $sku, 0, 160 ),
				'quantity' => $qty,
			);
			if ( isset( $it['unit_price'] ) && is_numeric( $it['unit_price'] ) ) {
				$row['unit_price'] = max( 0.0, round( (float) $it['unit_price'], 2 ) );
			}
			$clean[] = $row;
		}
		return $clean;
	}

	/**
	 * Server-side CompleteRegistration hook (`user_register`).
	 */
	public static function on_user_register( int $user_id ): void {
		if ( Config_Manager::is_safe_or_bot_request() ) {
			return;
		}
		$tenant = Config_Manager::resolve_current_tenant();
		if ( ! $tenant || $user_id <= 0 || Config_Manager::is_organic_retention_hub( $tenant ) ) {
			return;
		}
		$dedupe_key = 'reg_' . $user_id;
		if ( isset( self::$fired_keys[ $dedupe_key ] ) ) {
			return;
		}
		self::$fired_keys[ $dedupe_key ] = true;

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$live_ctx    = Context_Freezer::capture_and_merge_session_context(
			array(
				'email' => (string) $user->user_email,
			)
		);
		$best_pii    = Context_Freezer::extract_best_buyer_pii(
			array(
				'email'        => (string) $user->user_email,
				'phone'        => (string) get_user_meta( $user_id, 'billing_phone', true ),
				'first_name'   => (string) ( $user->first_name ?: get_user_meta( $user_id, 'billing_first_name', true ) ),
				'last_name'    => (string) ( $user->last_name ?: get_user_meta( $user_id, 'billing_last_name', true ) ),
				'city'         => (string) get_user_meta( $user_id, 'billing_city', true ),
				'state'        => (string) get_user_meta( $user_id, 'billing_state', true ),
				'postal_code'  => (string) get_user_meta( $user_id, 'billing_postcode', true ),
				'country_code' => (string) get_user_meta( $user_id, 'billing_country', true ),
			)
		);
		$action_uuid = sprintf( 'reg_%d_%d', (int) $tenant['blog_id'], $user_id );
		$payload     = array(
			'site_key'          => (string) $tenant['site_key'],
			'blog_id'           => (int) $tenant['blog_id'],
			'action_uuid'       => $action_uuid,
			'order_reference'   => '',
			'consent_granted'   => $live_ctx['consent_granted'],
			'event_name'        => 'CompleteRegistration',
			'occurred_at'       => time(),
			'raw_page_url'      => Context_Freezer::normalize_to_public_a_site_url( $live_ctx['raw_page_url'], '/' ),
			'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'total_value'       => 0.0,
			'client_ip'         => $live_ctx['client_ip'],
			'client_user_agent' => $live_ctx['client_user_agent'],
			'fbp'               => $live_ctx['fbp'],
			'fbc'               => $live_ctx['fbc'],
			'external_id'       => '' !== $live_ctx['external_id'] ? $live_ctx['external_id'] : ( 'wc_u_' . $user_id ),
			'email'             => $best_pii['email'],
			'phone'             => $best_pii['phone'],
			'first_name'        => $best_pii['first_name'],
			'last_name'         => $best_pii['last_name'],
			'city'              => $best_pii['city'],
			'state'             => $best_pii['state'],
			'postal_code'       => $best_pii['postal_code'],
			'country_code'      => $best_pii['country_code'],
			'items'             => array(),
		);

		$res = Config_Manager::dispatch_to_gateway( $payload, $tenant );
		if ( ! $res['ok'] ) {
			Retry_Reconciler::enqueue_event_retry( $payload, $tenant );
		}
	}

	/**
	 * Classic WooCommerce AddToCart hook (`woocommerce_add_to_cart`).
	 */
	public static function on_classic_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0 ): void {
		$target_id = (int) $variation_id > 0 ? (int) $variation_id : (int) $product_id;
		self::emit_server_add_to_cart( $target_id, max( 1, (int) $quantity ) );
	}

	/**
	 * WooCommerce Store API Block AddToCart hook (`internal_woocommerce_cart_item_added_from_user_request`).
	 */
	public static function on_store_api_add_to_cart( $product_id, $quantity = 1 ): void {
		self::emit_server_add_to_cart( (int) $product_id, max( 1, (int) $quantity ) );
	}

	private static function emit_server_add_to_cart( int $product_id, int $quantity ): void {
		if ( Config_Manager::is_safe_or_bot_request() ) {
			return;
		}
		$tenant = Config_Manager::resolve_current_tenant();
		if ( ! $tenant || $product_id <= 0 || ! function_exists( 'wc_get_product' ) || Config_Manager::is_organic_retention_hub( $tenant ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$parent_key = $product->get_parent_id() > 0 ? ( $product->get_parent_id() . '_' . $product->get_id() ) : ( '0_' . $product->get_id() );
		$dedupe_key = 'atc_' . $parent_key . '_' . $quantity;
		if ( isset( self::$fired_keys[ $dedupe_key ] ) ) {
			return;
		}
		self::$fired_keys[ $dedupe_key ] = true;

		$raw_sku    = (string) $product->get_sku();
		$raw_sku    = '' !== $raw_sku ? $raw_sku : 'WC_' . $product_id;
		$unit_price = round( (float) $product->get_price(), 2 );
		$live_ctx   = Context_Freezer::capture_and_merge_session_context();
		$best_pii   = Context_Freezer::extract_best_buyer_pii();
		$eid        = '' !== $live_ctx['external_id'] ? $live_ctx['external_id'] : $best_pii['external_id'];

		$payload = array(
			'site_key'          => (string) $tenant['site_key'],
			'blog_id'           => (int) $tenant['blog_id'],
			'action_uuid'       => sprintf( 'atc_%d_%d_%s', (int) $tenant['blog_id'], $product_id, substr( wp_generate_uuid4(), 0, 8 ) ),
			'order_reference'   => '',
			'consent_granted'   => $live_ctx['consent_granted'],
			'event_name'        => 'AddToCart',
			'occurred_at'       => time(),
			'raw_page_url'      => Context_Freezer::normalize_to_public_a_site_url( $live_ctx['raw_page_url'], '/cart' ),
			'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'total_value'       => round( $unit_price * $quantity, 2 ),
			'client_ip'         => $live_ctx['client_ip'],
			'client_user_agent' => $live_ctx['client_user_agent'],
			'fbp'               => $live_ctx['fbp'],
			'fbc'               => $live_ctx['fbc'],
			'external_id'       => $eid,
			'email'             => $best_pii['email'],
			'phone'             => $best_pii['phone'],
			'first_name'        => $best_pii['first_name'],
			'last_name'         => $best_pii['last_name'],
			'city'              => $best_pii['city'],
			'state'             => $best_pii['state'],
			'postal_code'       => $best_pii['postal_code'],
			'country_code'      => $best_pii['country_code'],
			'items'             => array(
				array(
					'raw_sku'    => $raw_sku,
					'quantity'   => $quantity,
					'unit_price' => $unit_price,
				),
			),
		);

		$res = Config_Manager::dispatch_to_gateway( $payload, $tenant );
		if ( ! $res['ok'] ) {
			Retry_Reconciler::enqueue_event_retry( $payload, $tenant );
		}
	}

	private static function allow_rate_limit( string $ip ): bool {
		$key   = 'scapi_rl_' . substr( md5( $ip ), 0, 16 );
		$count = (int) get_transient( $key );
		if ( $count >= 160 ) {
			return false;
		}
		set_transient( $key, $count + 1, 60 );
		return true;
	}
}
