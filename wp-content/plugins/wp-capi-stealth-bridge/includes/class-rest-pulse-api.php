<?php
/**
 * Same-Origin Shallow Funnel Collector (`/wp-json/stealth-capi/v1/pulse`)
 * + Dual-Path Native Server Hooks (Classic + WooCommerce Store API Block Cart & Registration).
 */

namespace StealthCapiBridge;

defined( 'ABSPATH' ) || exit;

final class REST_Pulse_API {

	private const ALLOWED_PULSE_EVENTS = array(
		'Contact',
		'CompleteRegistration',
		'AddToCart',
		'ViewContent',
		'PageView',
	);

	private static array $fired_keys = array();

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );

		$cfg = Config_Manager::get_config();
		if ( ! empty( $cfg['enable_server_shallow'] ) && Config_Manager::is_connector_ready() ) {
			// Registration server hook.
			add_action( 'user_register', array( self::class, 'on_user_register' ), 25, 1 );

			// Classic + Block Store API AddToCart server hooks (matching peptide-core handover).
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
		$tenant = Config_Manager::resolve_current_tenant();
		if ( ! $tenant ) {
			return new \WP_REST_Response( array( 'accepted' => false, 'reason' => 'inactive' ), 202 );
		}
		if ( Config_Manager::is_organic_retention_hub( $tenant ) ) {
			return new \WP_REST_Response( array( 'accepted' => false, 'reason' => 'organic_retention_hub_zero_pixel' ), 202 );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$raw    = $request->get_body();
			$params = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		}
		if ( ! is_array( $params ) ) {
			return new \WP_REST_Response( array( 'accepted' => false, 'reason' => 'invalid_json' ), 400 );
		}

		$event_name = isset( $params['event_name'] ) ? trim( (string) $params['event_name'] ) : '';
		if ( ! in_array( $event_name, self::ALLOWED_PULSE_EVENTS, true ) ) {
			return new \WP_REST_Response( array( 'accepted' => false, 'reason' => 'unsupported_event' ), 400 );
		}

		$live_ctx = Context_Freezer::capture_live_browser_context();

		// Per-IP burst rate limit (max 90 shallow events / 60s per IP).
		if ( '' !== $live_ctx['client_ip'] && ! self::allow_rate_limit( $live_ctx['client_ip'] ) ) {
			return new \WP_REST_Response( array( 'accepted' => false, 'reason' => 'rate_limited' ), 429 );
		}

		$fbp = ! empty( $params['fbp'] ) ? Config_Manager::sanitize_fb_cookie( (string) $params['fbp'] ) : $live_ctx['fbp'];
		$fbc = ! empty( $params['fbc'] ) ? Config_Manager::sanitize_fb_cookie( (string) $params['fbc'] ) : $live_ctx['fbc'];

		$user_fields = self::extract_logged_in_user_fields();
		$email       = ! empty( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : $user_fields['email'];
		$phone       = ! empty( $params['phone'] ) ? sanitize_text_field( (string) $params['phone'] ) : $user_fields['phone'];

		$action_uuid = ! empty( $params['action_uuid'] )
			? preg_replace( '/[^a-zA-Z0-9_\-:.]/', '', (string) $params['action_uuid'] )
			: sprintf( 'pls_%d_%s', (int) $tenant['blog_id'], wp_generate_uuid4() );

		$payload = array(
			'site_key'          => (string) $tenant['site_key'],
			'blog_id'           => (int) $tenant['blog_id'],
			'action_uuid'       => $action_uuid,
			'order_reference'   => '',
			'consent_granted'   => isset( $params['consent_granted'] ) ? (bool) $params['consent_granted'] : $live_ctx['consent_granted'],
			'event_name'        => $event_name,
			'occurred_at'       => time(),
			'raw_page_url'      => ! empty( $params['raw_page_url'] ) ? esc_url_raw( (string) $params['raw_page_url'] ) : $live_ctx['raw_page_url'],
			'currency'          => ! empty( $params['currency'] ) ? sanitize_text_field( (string) $params['currency'] ) : 'USD',
			'total_value'       => isset( $params['total_value'] ) ? round( (float) $params['total_value'], 2 ) : 0.0,
			'client_ip'         => $live_ctx['client_ip'],
			'client_user_agent' => $live_ctx['client_user_agent'],
			'fbp'               => $fbp,
			'fbc'               => $fbc,
			'email'             => $email,
			'phone'             => $phone,
			'first_name'        => $user_fields['first_name'],
			'last_name'         => $user_fields['last_name'],
			'city'              => $user_fields['city'],
			'state'             => $user_fields['state'],
			'postal_code'       => $user_fields['postal_code'],
			'country_code'      => $user_fields['country_code'],
			'items'             => isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array(),
		);

		$res = Config_Manager::dispatch_to_gateway( $payload, $tenant );
		if ( ! $res['ok'] ) {
			Retry_Reconciler::enqueue_event_retry( $payload, $tenant );
		}

		return new \WP_REST_Response(
			array(
				'accepted'    => true,
				'action_uuid' => $action_uuid,
				'relayed'     => $res['ok'],
			),
			202
		);
	}

	/**
	 * Server-side CompleteRegistration hook (`user_register`).
	 */
	public static function on_user_register( int $user_id ): void {
		$tenant = Config_Manager::resolve_current_tenant();
		if ( ! $tenant || $user_id <= 0 ) {
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

		$live_ctx    = Context_Freezer::capture_live_browser_context();
		$action_uuid = sprintf( 'reg_%d_%d', (int) $tenant['blog_id'], $user_id );
		$payload     = array(
			'site_key'          => (string) $tenant['site_key'],
			'blog_id'           => (int) $tenant['blog_id'],
			'action_uuid'       => $action_uuid,
			'order_reference'   => '',
			'consent_granted'   => $live_ctx['consent_granted'],
			'event_name'        => 'CompleteRegistration',
			'occurred_at'       => time(),
			'raw_page_url'      => $live_ctx['raw_page_url'],
			'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'total_value'       => 0.0,
			'client_ip'         => $live_ctx['client_ip'],
			'client_user_agent' => $live_ctx['client_user_agent'],
			'fbp'               => $live_ctx['fbp'],
			'fbc'               => $live_ctx['fbc'],
			'email'             => (string) $user->user_email,
			'phone'             => (string) get_user_meta( $user_id, 'billing_phone', true ),
			'first_name'        => (string) ( $user->first_name ?: get_user_meta( $user_id, 'billing_first_name', true ) ),
			'last_name'         => (string) ( $user->last_name ?: get_user_meta( $user_id, 'billing_last_name', true ) ),
			'city'              => (string) get_user_meta( $user_id, 'billing_city', true ),
			'state'             => (string) get_user_meta( $user_id, 'billing_state', true ),
			'postal_code'       => (string) get_user_meta( $user_id, 'billing_postcode', true ),
			'country_code'      => (string) get_user_meta( $user_id, 'billing_country', true ),
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
		$tenant = Config_Manager::resolve_current_tenant();
		if ( ! $tenant || $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$dedupe_key = 'atc_' . $product_id . '_' . $quantity;
		if ( isset( self::$fired_keys[ $dedupe_key ] ) ) {
			return;
		}
		self::$fired_keys[ $dedupe_key ] = true;

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$raw_sku    = (string) $product->get_sku();
		$raw_sku    = '' !== $raw_sku ? $raw_sku : 'WC_' . $product_id;
		$unit_price = round( (float) $product->get_price(), 2 );
		$live_ctx   = Context_Freezer::capture_live_browser_context();
		$user_info  = self::extract_logged_in_user_fields();

		$payload = array(
			'site_key'          => (string) $tenant['site_key'],
			'blog_id'           => (int) $tenant['blog_id'],
			'action_uuid'       => sprintf( 'atc_%d_%d_%s', (int) $tenant['blog_id'], $product_id, substr( wp_generate_uuid4(), 0, 8 ) ),
			'order_reference'   => '',
			'consent_granted'   => $live_ctx['consent_granted'],
			'event_name'        => 'AddToCart',
			'occurred_at'       => time(),
			'raw_page_url'      => $live_ctx['raw_page_url'],
			'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'total_value'       => round( $unit_price * $quantity, 2 ),
			'client_ip'         => $live_ctx['client_ip'],
			'client_user_agent' => $live_ctx['client_user_agent'],
			'fbp'               => $live_ctx['fbp'],
			'fbc'               => $live_ctx['fbc'],
			'email'             => $user_info['email'],
			'phone'             => $user_info['phone'],
			'first_name'        => $user_info['first_name'],
			'last_name'         => $user_info['last_name'],
			'city'              => $user_info['city'],
			'state'             => $user_info['state'],
			'postal_code'       => $user_info['postal_code'],
			'country_code'      => $user_info['country_code'],
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

	private static function extract_logged_in_user_fields(): array {
		$empty = array(
			'email'        => '',
			'phone'        => '',
			'first_name'   => '',
			'last_name'    => '',
			'city'         => '',
			'state'        => '',
			'postal_code'  => '',
			'country_code' => '',
		);
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return $empty;
		}
		$uid  = get_current_user_id();
		$user = get_userdata( $uid );
		if ( ! $user instanceof \WP_User ) {
			return $empty;
		}
		return array(
			'email'        => (string) $user->user_email,
			'phone'        => (string) get_user_meta( $uid, 'billing_phone', true ),
			'first_name'   => (string) ( get_user_meta( $uid, 'billing_first_name', true ) ?: $user->first_name ),
			'last_name'    => (string) ( get_user_meta( $uid, 'billing_last_name', true ) ?: $user->last_name ),
			'city'         => (string) get_user_meta( $uid, 'billing_city', true ),
			'state'        => (string) get_user_meta( $uid, 'billing_state', true ),
			'postal_code'  => (string) get_user_meta( $uid, 'billing_postcode', true ),
			'country_code' => (string) get_user_meta( $uid, 'billing_country', true ),
		);
	}

	private static function allow_rate_limit( string $ip ): bool {
		$key   = 'scapi_rl_' . substr( md5( $ip ), 0, 16 );
		$count = (int) get_transient( $key );
		if ( $count >= 90 ) {
			return false;
		}
		set_transient( $key, $count + 1, 60 );
		return true;
	}
}
