<?php
/**
 * Buyer Context Freezer & Progressive Full-Funnel Checkout Collector (V3.9.3):
 * - 3-Tier Buyer Context + Progressive PII Persistence:
 *   First-Party Cookies & X-Capi-* Headers (`_fbp`/`_fbc`/`_capi_eid`)
 *   -> `WC()->session` (`scapi_live_buyer_ctx` + `scapi_checkout_locks`)
 *   -> HPOS Order Meta (`_capi_frozen_buyer_ctx`).
 * - Captures progressive buyer PII (`email`, `phone`, `first_name`, `last_name`, `address_1`, `city`, `state`, `postal_code`, `country_code`)
 *   across WooCommerce Block Checkout Store API (`cart/update-customer`, `checkout`), Classic AJAX (`update_order_review`), and first-party `/pulse`.
 * - Deduplicates checkout funnel milestones (`InitiateCheckout` on checkout entry + `AddPaymentInfo` on shipping-address/payment entry)
 *   across browser pulse and server hooks so every milestone fires reliably once without double-counting on order creation.
 * - Strictly normalizes `raw_page_url` to the store's authoritative A-site public domain (`Management_Host::public_url()`).
 */

namespace StealthCapiBridge;

defined( 'ABSPATH' ) || exit;

final class Context_Freezer {

	public const META_FROZEN_CTX      = '_capi_frozen_buyer_ctx';
	public const META_IC_SENT         = '_capi_ic_sent_lock';
	public const META_API_SENT        = '_capi_api_sent_lock';
	public const SESSION_LIVE_CTX     = 'scapi_live_buyer_ctx';
	public const SESSION_FUNNEL_LOCKS = 'scapi_checkout_locks';

	public static function register_hooks(): void {
		// Stage 1: Landing & Storefront navigation session persistence.
		add_action( 'wp', array( self::class, 'persist_live_context_to_session' ), 5 );

		// Stage 2: Add-to-Cart session persistence (Classic + Store API).
		add_action( 'woocommerce_add_to_cart', array( self::class, 'persist_live_context_to_session' ), 5 );
		add_action( 'internal_woocommerce_cart_item_added_from_user_request', array( self::class, 'persist_live_context_to_session' ), 5 );

		// Stage 3: Checkout address / email / phone entry persistence + AddPaymentInfo milestone
		// (Covers BOTH WooCommerce Block Checkout Store API `/wc/store/v1/cart/update-customer` & `/batch` AND Classic AJAX `update_order_review`).
		add_action( 'woocommerce_checkout_update_order_review', array( self::class, 'on_checkout_update_order_review' ), 5, 1 );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( self::class, 'on_store_api_customer_updated' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( self::class, 'on_store_api_customer_updated' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'on_store_api_update_order' ), 5, 2 );

		// Stage 4: Final WooCommerce order creation & before AB cashier redirect.
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'on_order_created_object' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'on_classic_checkout_processed' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'on_order_created_object' ), 10, 1 );
	}

	/**
	 * Resolve authoritative A-site public base URL (never returns `*-admin.vialcoveresearch.com` or B-site cashier host).
	 */
	public static function get_authoritative_public_origin(): string {
		if ( class_exists( '\PeptideCore\Management_Host' ) && method_exists( '\PeptideCore\Management_Host', 'public_url' ) ) {
			$pub = trim( (string) \PeptideCore\Management_Host::public_url() );
			if ( '' !== $pub ) {
				return rtrim( $pub, '/' );
			}
		}
		return rtrim( (string) home_url(), '/' );
	}

	/**
	 * Normalize any candidate URL onto the store's verified A-site public origin so management hosts,
	 * IP addresses, or external B-site cashier domains can never leak into `event_source_url`.
	 */
	public static function normalize_to_public_a_site_url( string $raw_url, string $default_path = '/checkout/' ): string {
		$origin   = self::get_authoritative_public_origin();
		$pub_host = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		if ( '' === $pub_host ) {
			return $origin . $default_path;
		}
		$pub_bare = preg_replace( '/^www\./', '', $pub_host );

		$parsed = '' !== trim( $raw_url ) ? wp_parse_url( trim( $raw_url ) ) : false;
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return 'https://' . $pub_host . $default_path;
		}

		$cand_host = strtolower( (string) $parsed['host'] );
		$cand_bare = preg_replace( '/^www\./', '', $cand_host );
		$is_unsafe = (
			false !== filter_var( $cand_host, FILTER_VALIDATE_IP )
			|| false !== strpos( $cand_host, '-admin.' )
			|| false !== strpos( $cand_host, 'vialcoveresearch.com' )
			|| false !== strpos( $cand_host, 'panapay' )
		);

		$final_host = ( ! $is_unsafe && $cand_bare === $pub_bare ) ? $cand_host : $pub_host;
		$path       = isset( $parsed['path'] ) && '' !== $parsed['path'] ? (string) $parsed['path'] : $default_path;
		if ( 0 === strpos( $path, '/wp-json/' ) || 0 === strpos( $path, '/wp-admin' ) || false !== strpos( $path, 'wc-ajax=' ) ) {
			$path = $default_path;
		}
		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		return 'https://' . $final_host . $path;
	}

	/**
	 * Capture live browser request context (ONLY call during real buyer HTTP requests).
	 */
	public static function capture_live_browser_context(): array {
		$ip = Config_Manager::extract_buyer_ip();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		// Priority: POST hidden input -> Store API / AJAX custom header -> First-party cookie -> Standard Meta cookie.
		$fbp_candidates = array(
			isset( $_POST['_capi_fbp'] ) ? sanitize_text_field( wp_unslash( $_POST['_capi_fbp'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			isset( $_SERVER['HTTP_X_CAPI_FBP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CAPI_FBP'] ) ) : '',
			isset( $_COOKIE['_capi_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_capi_fbp'] ) ) : '',
			isset( $_COOKIE['_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) : '',
		);
		$fbp = '';
		foreach ( $fbp_candidates as $cand ) {
			$clean = Config_Manager::sanitize_fbp_cookie( $cand );
			if ( '' !== $clean ) {
				$fbp = $clean;
				break;
			}
		}

		$fbc_candidates = array(
			isset( $_POST['_capi_fbc'] ) ? trim( (string) wp_unslash( $_POST['_capi_fbc'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			isset( $_SERVER['HTTP_X_CAPI_FBC'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_X_CAPI_FBC'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			isset( $_COOKIE['_capi_fbc'] ) ? trim( (string) wp_unslash( $_COOKIE['_capi_fbc'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			isset( $_COOKIE['_fbc'] ) ? trim( (string) wp_unslash( $_COOKIE['_fbc'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		);
		$fbc = '';
		foreach ( $fbc_candidates as $cand ) {
			$clean = Config_Manager::sanitize_fbc_cookie( $cand );
			if ( '' !== $clean ) {
				$fbc = $clean;
				break;
			}
		}

		$eid_candidates = array(
			isset( $_POST['_capi_eid'] ) ? sanitize_text_field( wp_unslash( $_POST['_capi_eid'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			isset( $_SERVER['HTTP_X_CAPI_EID'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CAPI_EID'] ) ) : '',
			isset( $_COOKIE['_capi_eid'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_capi_eid'] ) ) : '',
			isset( $_COOKIE['pc_vid'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['pc_vid'] ) ) : '',
		);
		$external_id = '';
		foreach ( $eid_candidates as $cand ) {
			$clean = Config_Manager::sanitize_external_id( $cand );
			if ( '' !== $clean ) {
				$external_id = $clean;
				break;
			}
		}

		$raw_url = '';
		if ( ! empty( $_POST['_capi_raw_url'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_url = esc_url_raw( wp_unslash( $_POST['_capi_raw_url'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( ! empty( $_SERVER['HTTP_X_CAPI_PAGE_URL'] ) ) {
			$raw_url = esc_url_raw( wp_unslash( $_SERVER['HTTP_X_CAPI_PAGE_URL'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$raw_url = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		} else {
			$scheme  = is_ssl() ? 'https' : 'http';
			$host    = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( self::get_authoritative_public_origin(), PHP_URL_HOST );
			$req_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw_url = esc_url_raw( $scheme . '://' . $host . $req_uri );
		}

		// Losslessly synthesize _fbc if fbclid is present in current query or raw_url (preserving 100% original case!).
		$raw_fbclid = Config_Manager::extract_raw_fbclid();
		if ( '' === $raw_fbclid && '' !== $raw_url ) {
			$url_qs = (string) wp_parse_url( $raw_url, PHP_URL_QUERY );
			if ( '' !== $url_qs ) {
				$raw_fbclid = Config_Manager::extract_raw_fbclid( $url_qs );
			}
		}
		if ( '' !== $raw_fbclid ) {
			$fbc_parts  = '' !== $fbc ? explode( '.', $fbc, 4 ) : array();
			$cur_suffix = isset( $fbc_parts[3] ) ? $fbc_parts[3] : '';
			if ( $cur_suffix !== $raw_fbclid ) {
				$ms  = (int) round( microtime( true ) * 1000 );
				$fbc = Config_Manager::sanitize_fbc_cookie( 'fb.1.' . $ms . '.' . $raw_fbclid );
			}
		}

		$gpc             = isset( $_SERVER['HTTP_SEC_GPC'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) ) : '';
		$consent_granted = ( '1' !== $gpc );

		return array(
			'client_ip'         => $ip,
			'client_user_agent' => $ua,
			'fbp'               => $fbp,
			'fbc'               => $fbc,
			'external_id'       => $external_id,
			'raw_page_url'      => self::normalize_to_public_a_site_url( $raw_url, '/' ),
			'landing_query'     => isset( $_SERVER['QUERY_STRING'] ) ? substr( (string) $_SERVER['QUERY_STRING'], 0, 1024 ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'consent_granted'   => $consent_granted,
			'frozen_at'         => time(),
		);
	}

	/**
	 * Sanitize candidate progressive buyer PII fields.
	 */
	public static function sanitize_pii_fields( array $raw ): array {
		$out = array();
		if ( ! empty( $raw['email'] ) ) {
			$em = sanitize_email( trim( (string) $raw['email'] ) );
			if ( '' !== $em && false !== strpos( $em, '@' ) ) {
				$out['email'] = $em;
			}
		}
		if ( ! empty( $raw['phone'] ) ) {
			$ph     = sanitize_text_field( trim( (string) $raw['phone'] ) );
			$digits = preg_replace( '/[^0-9]/', '', $ph );
			if ( strlen( (string) $digits ) >= 7 && strlen( (string) $digits ) <= 15 ) {
				$out['phone'] = $ph;
			}
		}
		foreach ( array( 'first_name', 'last_name', 'address_1', 'city', 'state', 'postal_code', 'country_code' ) as $k ) {
			if ( isset( $raw[ $k ] ) ) {
				$v = trim( sanitize_text_field( (string) $raw[ $k ] ) );
				if ( '' === $v ) {
					continue;
				}
				if ( 'country_code' === $k ) {
					if ( preg_match( '/^[A-Za-z]{2}$/', $v ) ) {
						$out[ $k ] = strtoupper( $v );
					}
				} elseif ( strlen( $v ) <= 160 ) {
					$out[ $k ] = $v;
				}
			}
		}
		return $out;
	}

	/**
	 * Non-destructively merge older persisted session/order context with newer live context
	 * (including progressive buyer PII) so early `_fbc` or verified public IP is never lost.
	 */
	public static function merge_contexts( array $older, array $newer ): array {
		$merged = array_merge(
			array(
				'client_ip'         => '',
				'client_user_agent' => '',
				'fbp'               => '',
				'fbc'               => '',
				'external_id'       => '',
				'raw_page_url'      => '',
				'landing_query'     => '',
				'consent_granted'   => true,
				'email'             => '',
				'phone'             => '',
				'first_name'        => '',
				'last_name'         => '',
				'address_1'         => '',
				'city'              => '',
				'state'             => '',
				'postal_code'       => '',
				'country_code'      => '',
				'frozen_at'         => time(),
			),
			$older
		);

		if ( ! empty( $newer['client_ip'] ) && Config_Manager::is_valid_public_buyer_ip( (string) $newer['client_ip'] ) ) {
			$merged['client_ip'] = (string) $newer['client_ip'];
		}
		if ( ! empty( $newer['client_user_agent'] ) && ! Config_Manager::is_safe_or_bot_request( false, '', (string) $newer['client_user_agent'] ) ) {
			$merged['client_user_agent'] = (string) $newer['client_user_agent'];
		}
		if ( ! empty( $newer['fbp'] ) && '' !== Config_Manager::sanitize_fbp_cookie( (string) $newer['fbp'] ) ) {
			$merged['fbp'] = (string) $newer['fbp'];
		}
		if ( ! empty( $newer['fbc'] ) && '' !== Config_Manager::sanitize_fbc_cookie( (string) $newer['fbc'] ) ) {
			$merged['fbc'] = (string) $newer['fbc'];
		}
		if ( ! empty( $newer['external_id'] ) && '' !== Config_Manager::sanitize_external_id( (string) $newer['external_id'] ) ) {
			$merged['external_id'] = (string) $newer['external_id'];
		}
		if ( ! empty( $newer['raw_page_url'] ) ) {
			$merged['raw_page_url'] = self::normalize_to_public_a_site_url( (string) $newer['raw_page_url'], '/checkout/' );
		}
		if ( empty( $merged['landing_query'] ) && ! empty( $newer['landing_query'] ) ) {
			$merged['landing_query'] = (string) $newer['landing_query'];
		}
		if ( isset( $newer['consent_granted'] ) ) {
			$merged['consent_granted'] = (bool) $newer['consent_granted'];
		}

		$clean_pii = self::sanitize_pii_fields( $newer );
		foreach ( $clean_pii as $pk => $pv ) {
			$merged[ $pk ] = $pv;
		}

		$merged['frozen_at'] = time();
		return $merged;
	}

	public static function read_session_context(): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->session ) || ! is_object( WC()->session ) ) {
			return array();
		}
		$raw = WC()->session->get( self::SESSION_LIVE_CTX );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $raw ) ? $raw : array();
	}

	public static function save_context_to_session( array $ctx ): array {
		$existing = self::read_session_context();
		$merged   = self::merge_contexts( $existing, $ctx );
		if ( function_exists( 'WC' ) && WC() && isset( WC()->session ) && is_object( WC()->session ) ) {
			$has_cookie = method_exists( WC()->session, 'has_session' ) && WC()->session->has_session();
			$has_pii    = ! empty( $merged['email'] ) || ! empty( $merged['phone'] ) || ! empty( $merged['address_1'] );
			$should_set = $has_cookie || $has_pii || ! empty( $merged['fbc'] ) || ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_cart' ) && is_cart() );
			if ( $should_set ) {
				if ( ! $has_cookie && method_exists( WC()->session, 'set_customer_session_cookie' ) && ( $has_pii || ! empty( $merged['fbc'] ) ) ) {
					WC()->session->set_customer_session_cookie( true );
				}
				WC()->session->set( self::SESSION_LIVE_CTX, $merged );
			}
		}
		return $merged;
	}

	public static function capture_and_merge_session_context( array $extra_pii = array() ): array {
		$live = self::capture_live_browser_context();
		if ( ! empty( $extra_pii ) ) {
			$live = array_merge( $live, self::sanitize_pii_fields( $extra_pii ) );
		}
		return self::save_context_to_session( $live );
	}

	public static function persist_live_context_to_session(): void {
		if ( is_admin() || Config_Manager::is_safe_or_bot_request() ) {
			return;
		}
		$tenant = Config_Manager::resolve_current_tenant();
		if ( ! $tenant || Config_Manager::is_organic_retention_hub( $tenant ) ) {
			return;
		}
		self::capture_and_merge_session_context();
	}

	/**
	 * Merge best-available buyer PII across Logged-in User -> WC_Customer -> Session Progressive Context -> Explicit Override.
	 */
	public static function extract_best_buyer_pii( array $override = array() ): array {
		$pii = array(
			'external_id'  => '',
			'email'        => '',
			'phone'        => '',
			'first_name'   => '',
			'last_name'    => '',
			'address_1'    => '',
			'city'         => '',
			'state'        => '',
			'postal_code'  => '',
			'country_code' => '',
		);

		// 1. Logged-in WordPress user fallback
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$uid  = (int) get_current_user_id();
			$user = $uid > 0 ? get_userdata( $uid ) : null;
			if ( $user instanceof \WP_User ) {
				$pii['external_id'] = 'wc_u_' . $uid;
				$user_cand          = self::sanitize_pii_fields(
					array(
						'email'        => (string) $user->user_email,
						'phone'        => (string) get_user_meta( $uid, 'billing_phone', true ),
						'first_name'   => (string) ( get_user_meta( $uid, 'billing_first_name', true ) ?: $user->first_name ),
						'last_name'    => (string) ( get_user_meta( $uid, 'billing_last_name', true ) ?: $user->last_name ),
						'address_1'    => (string) ( get_user_meta( $uid, 'shipping_address_1', true ) ?: get_user_meta( $uid, 'billing_address_1', true ) ),
						'city'         => (string) ( get_user_meta( $uid, 'shipping_city', true ) ?: get_user_meta( $uid, 'billing_city', true ) ),
						'state'        => (string) ( get_user_meta( $uid, 'shipping_state', true ) ?: get_user_meta( $uid, 'billing_state', true ) ),
						'postal_code'  => (string) ( get_user_meta( $uid, 'shipping_postcode', true ) ?: get_user_meta( $uid, 'billing_postcode', true ) ),
						'country_code' => (string) ( get_user_meta( $uid, 'shipping_country', true ) ?: get_user_meta( $uid, 'billing_country', true ) ),
					)
				);
				$pii = array_merge( $pii, $user_cand );
			}
		}

		// 2. WooCommerce Customer session object (populated by Store API `cart/update-customer` & checkout)
		if ( function_exists( 'WC' ) && WC() && isset( WC()->customer ) && WC()->customer instanceof \WC_Customer ) {
			$cust      = WC()->customer;
			$cust_cand = self::sanitize_pii_fields(
				array(
					'email'        => (string) $cust->get_billing_email(),
					'phone'        => (string) ( $cust->get_billing_phone() ?: ( method_exists( $cust, 'get_shipping_phone' ) ? $cust->get_shipping_phone() : '' ) ),
					'first_name'   => (string) ( $cust->get_shipping_first_name() ?: $cust->get_billing_first_name() ),
					'last_name'    => (string) ( $cust->get_shipping_last_name() ?: $cust->get_billing_last_name() ),
					'address_1'    => (string) ( $cust->get_shipping_address_1() ?: $cust->get_billing_address_1() ),
					'city'         => (string) ( $cust->get_shipping_city() ?: $cust->get_billing_city() ),
					'state'        => (string) ( $cust->get_shipping_state() ?: $cust->get_billing_state() ),
					'postal_code'  => (string) ( $cust->get_shipping_postcode() ?: $cust->get_billing_postcode() ),
					'country_code' => (string) ( $cust->get_shipping_country() ?: $cust->get_billing_country() ),
				)
			);
			$pii = array_merge( $pii, $cust_cand );
		}

		// 3. Persisted `scapi_live_buyer_ctx` progressive session PII
		$sess_ctx  = self::read_session_context();
		$sess_cand = self::sanitize_pii_fields( $sess_ctx );
		$pii       = array_merge( $pii, $sess_cand );
		if ( '' === $pii['external_id'] && ! empty( $sess_ctx['external_id'] ) ) {
			$pii['external_id'] = (string) $sess_ctx['external_id'];
		}

		// 4. Live explicit override
		if ( ! empty( $override ) ) {
			$ov_cand = self::sanitize_pii_fields( $override );
			$pii     = array_merge( $pii, $ov_cand );
		}

		return $pii;
	}

	/**
	 * Safely read current WooCommerce cart items, currency, total value, and deterministic cart signature.
	 */
	public static function extract_cart_snapshot(): array {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		$empty    = array(
			'has_items'   => false,
			'currency'    => $currency,
			'total_value' => 0.0,
			'items'       => array(),
			'cart_sig'    => '',
		);
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return $empty;
		}
		try {
			if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
				wc_load_cart();
			}
			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				return $empty;
			}
			$items     = array();
			$items_sum = 0.0;
			$sig_parts = array();
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				$qty     = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
				$product = $cart_item['data'] ?? null;
				if ( ! $product instanceof \WC_Product ) {
					$vid     = (int) ( $cart_item['variation_id'] ?? 0 );
					$pid     = (int) ( $cart_item['product_id'] ?? 0 );
					$product = function_exists( 'wc_get_product' ) ? wc_get_product( $vid > 0 ? $vid : $pid ) : null;
				}
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				$raw_sku = trim( (string) $product->get_sku() );
				if ( '' === $raw_sku ) {
					$raw_sku = 'WC_' . $product->get_id();
				}
				$line_total = isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : ( (float) $product->get_price() * $qty );
				$unit_price = round( $line_total > 0 ? ( $line_total / $qty ) : (float) $product->get_price(), 2 );
				$items_sum += $unit_price * $qty;
				$items[]    = array(
					'raw_sku'    => $raw_sku,
					'quantity'   => $qty,
					'unit_price' => $unit_price,
				);
				$sig_parts[] = $raw_sku . ':' . $qty;
			}
			if ( empty( $items ) ) {
				return $empty;
			}
			sort( $sig_parts );
			$cart_total = (float) WC()->cart->get_total( 'edit' );
			if ( $cart_total <= 0.0 ) {
				$cart_total = $items_sum;
			}
			return array(
				'has_items'   => true,
				'currency'    => $currency,
				'total_value' => round( $cart_total, 2 ),
				'items'       => $items,
				'cart_sig'    => substr( md5( implode( '|', $sig_parts ) ), 0, 12 ),
			);
		} catch ( \Throwable $err ) {
			return $empty;
		}
	}

	/**
	 * Session + transient deduplication lock for checkout milestones (`InitiateCheckout` & `AddPaymentInfo`).
	 * Returns deterministic `action_uuid` on first trigger, or `''` if already emitted for this checkout session.
	 */
	public static function acquire_session_funnel_lock( string $event_name, int $blog_id, string $cart_sig = '', string $identity_seed = '' ): string {
		$now        = time();
		$prefix     = 'AddPaymentInfo' === $event_name ? 'api' : 'ic';
		$locks      = array();
		$has_wc_ses = function_exists( 'WC' ) && WC() && isset( WC()->session ) && is_object( WC()->session );

		if ( $has_wc_ses ) {
			$raw_locks = WC()->session->get( self::SESSION_FUNNEL_LOCKS );
			$locks     = is_array( $raw_locks ) ? $raw_locks : array();
		}

		$sig_key  = $prefix . '_sig_' . ( '' !== $cart_sig ? $cart_sig : 'any' );
		$last_key = $prefix . '_last_at';

		if ( ! empty( $locks[ $sig_key ] ) && ( $now - (int) $locks[ $sig_key ] ) < 3600 ) {
			return '';
		}
		if ( ! empty( $locks[ $last_key ] ) && ( $now - (int) $locks[ $last_key ] ) < 600 && '' === $cart_sig ) {
			return '';
		}

		$seed = $identity_seed;
		if ( '' === $seed && $has_wc_ses && method_exists( WC()->session, 'get_customer_id' ) ) {
			$seed = (string) WC()->session->get_customer_id();
		}
		if ( '' !== $seed ) {
			$tr_key = 'scapi_flk_' . substr( md5( $prefix . '|' . $blog_id . '|' . $seed . '|' . ( $cart_sig ?: 'any' ) ), 0, 20 );
			if ( false !== get_transient( $tr_key ) ) {
				return '';
			}
			set_transient( $tr_key, $now, 3600 );
		}

		$locks[ $sig_key ]  = $now;
		$locks[ $last_key ] = $now;
		if ( $has_wc_ses ) {
			WC()->session->set( self::SESSION_FUNNEL_LOCKS, $locks );
		}

		$uniq = substr( md5( ( $seed ?: wp_generate_uuid4() ) . '|' . ( $cart_sig ?: 'c' ) . '|' . floor( $now / 3600 ) ), 0, 12 );
		return sprintf( '%s_%d_%s', $prefix, $blog_id, $uniq );
	}

	/**
	 * Check whether the current shopper session already emitted `InitiateCheckout` or `AddPaymentInfo` recently.
	 */
	public static function was_checkout_step_sent_recently( string $event_name, int $max_age_sec = 7200 ): bool {
		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->session ) || ! is_object( WC()->session ) ) {
			return false;
		}
		$locks = WC()->session->get( self::SESSION_FUNNEL_LOCKS );
		if ( ! is_array( $locks ) ) {
			return false;
		}
		$prefix   = 'AddPaymentInfo' === $event_name ? 'api' : 'ic';
		$last_key = $prefix . '_last_at';
		if ( ! empty( $locks[ $last_key ] ) && ( time() - (int) $locks[ $last_key ] ) <= $max_age_sec ) {
			return true;
		}
		return false;
	}

	/**
	 * Determine whether buyer PII contains a genuine shipping/billing address (excluding all-`1` dummy inputs).
	 */
	public static function is_meaningful_address_filled( array $pii ): bool {
		$addr = trim( (string) ( $pii['address_1'] ?? '' ) );
		$city = trim( (string) ( $pii['city'] ?? '' ) );
		$zip  = trim( (string) ( $pii['postal_code'] ?? '' ) );
		if ( strlen( $addr ) < 4 || ( strlen( $city ) < 2 && strlen( $zip ) < 3 ) ) {
			return false;
		}
		// Ignore store owner's all-`1` quick test dummy inputs (`1111` / `11111`).
		if ( preg_match( '/^1+$/', preg_replace( '/\s+/', '', $addr ) ) && preg_match( '/^1+$/', preg_replace( '/\s+/', '', $zip ?: $city ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Emit server-side `AddPaymentInfo` once per checkout session when the shopper completes shipping/billing address on `/checkout/`.
	 */
	private static function maybe_emit_server_add_payment_info( array $merged_ctx ): void {
		$tenant = Config_Manager::resolve_current_tenant();
		if ( ! $tenant || Config_Manager::is_organic_retention_hub( $tenant ) ) {
			return;
		}
		$pii = self::extract_best_buyer_pii( $merged_ctx );
		if ( ! self::is_meaningful_address_filled( $pii ) ) {
			return;
		}
		$cart = self::extract_cart_snapshot();
		if ( ! $cart['has_items'] ) {
			return;
		}
		$seed        = $merged_ctx['external_id'] ?: ( $pii['email'] ?: $merged_ctx['client_ip'] );
		$action_uuid = self::acquire_session_funnel_lock( 'AddPaymentInfo', (int) $tenant['blog_id'], $cart['cart_sig'], (string) $seed );
		if ( '' === $action_uuid ) {
			return;
		}

		$payload = array(
			'site_key'          => (string) $tenant['site_key'],
			'blog_id'           => (int) $tenant['blog_id'],
			'action_uuid'       => $action_uuid,
			'order_reference'   => '',
			'consent_granted'   => isset( $merged_ctx['consent_granted'] ) ? (bool) $merged_ctx['consent_granted'] : true,
			'event_name'        => 'AddPaymentInfo',
			'occurred_at'       => time(),
			'raw_page_url'      => self::normalize_to_public_a_site_url( (string) ( $merged_ctx['raw_page_url'] ?? '' ), '/checkout/' ),
			'currency'          => $cart['currency'],
			'total_value'       => $cart['total_value'],
			'client_ip'         => (string) ( $merged_ctx['client_ip'] ?? '' ),
			'client_user_agent' => (string) ( $merged_ctx['client_user_agent'] ?? '' ),
			'fbp'               => (string) ( $merged_ctx['fbp'] ?? '' ),
			'fbc'               => (string) ( $merged_ctx['fbc'] ?? '' ),
			'external_id'       => (string) ( $merged_ctx['external_id'] ?: $pii['external_id'] ),
			'email'             => $pii['email'],
			'phone'             => $pii['phone'],
			'first_name'        => $pii['first_name'],
			'last_name'         => $pii['last_name'],
			'city'              => $pii['city'],
			'state'             => $pii['state'],
			'postal_code'       => $pii['postal_code'],
			'country_code'      => $pii['country_code'],
			'items'             => $cart['items'],
		);

		$res = Config_Manager::dispatch_to_gateway( $payload, $tenant );
		if ( ! $res['ok'] ) {
			Retry_Reconciler::enqueue_event_retry( $payload, $tenant );
		}
	}

	/**
	 * WooCommerce Block Checkout Store API customer address/contact update
	 * (`woocommerce_store_api_cart_update_customer_from_request` & `woocommerce_store_api_checkout_update_customer_from_request`).
	 */
	public static function on_store_api_customer_updated( $customer, $request = null ): void {
		if ( Config_Manager::is_safe_or_bot_request() ) {
			return;
		}
		$extra = array();
		if ( $request instanceof \WP_REST_Request ) {
			$params = $request->get_json_params();
			if ( is_array( $params ) ) {
				$ship  = isset( $params['shipping_address'] ) && is_array( $params['shipping_address'] ) ? $params['shipping_address'] : array();
				$bill  = isset( $params['billing_address'] ) && is_array( $params['billing_address'] ) ? $params['billing_address'] : array();
				$extra = array(
					'email'        => (string) ( $bill['email'] ?? $params['email'] ?? '' ),
					'phone'        => (string) ( $ship['phone'] ?? $bill['phone'] ?? '' ),
					'first_name'   => (string) ( $ship['first_name'] ?? $bill['first_name'] ?? '' ),
					'last_name'    => (string) ( $ship['last_name'] ?? $bill['last_name'] ?? '' ),
					'address_1'    => (string) ( $ship['address_1'] ?? $bill['address_1'] ?? '' ),
					'city'         => (string) ( $ship['city'] ?? $bill['city'] ?? '' ),
					'state'        => (string) ( $ship['state'] ?? $bill['state'] ?? '' ),
					'postal_code'  => (string) ( $ship['postcode'] ?? $bill['postcode'] ?? '' ),
					'country_code' => (string) ( $ship['country'] ?? $bill['country'] ?? '' ),
				);
			}
		}
		if ( $customer instanceof \WC_Customer ) {
			$extra = array_merge(
				array(
					'email'        => (string) $customer->get_billing_email(),
					'phone'        => (string) ( $customer->get_billing_phone() ?: ( method_exists( $customer, 'get_shipping_phone' ) ? $customer->get_shipping_phone() : '' ) ),
					'first_name'   => (string) ( $customer->get_shipping_first_name() ?: $customer->get_billing_first_name() ),
					'last_name'    => (string) ( $customer->get_shipping_last_name() ?: $customer->get_billing_last_name() ),
					'address_1'    => (string) ( $customer->get_shipping_address_1() ?: $customer->get_billing_address_1() ),
					'city'         => (string) ( $customer->get_shipping_city() ?: $customer->get_billing_city() ),
					'state'        => (string) ( $customer->get_shipping_state() ?: $customer->get_billing_state() ),
					'postal_code'  => (string) ( $customer->get_shipping_postcode() ?: $customer->get_billing_postcode() ),
					'country_code' => (string) ( $customer->get_shipping_country() ?: $customer->get_billing_country() ),
				),
				array_filter( $extra, static fn( $v ) => '' !== trim( (string) $v ) )
			);
		}
		$merged = self::capture_and_merge_session_context( $extra );
		self::maybe_emit_server_add_payment_info( $merged );
	}

	/**
	 * Classic Checkout AJAX address/email/phone update (`woocommerce_checkout_update_order_review`).
	 */
	public static function on_checkout_update_order_review( $post_data = '' ): void {
		if ( Config_Manager::is_safe_or_bot_request() ) {
			return;
		}
		$ctx    = self::capture_live_browser_context();
		$parsed = array();
		if ( is_string( $post_data ) && '' !== $post_data ) {
			wp_parse_str( $post_data, $parsed );
			if ( '' === $ctx['fbc'] && ! empty( $parsed['_capi_fbc'] ) ) {
				$ctx['fbc'] = Config_Manager::sanitize_fbc_cookie( (string) $parsed['_capi_fbc'] );
			}
			if ( '' === $ctx['fbp'] && ! empty( $parsed['_capi_fbp'] ) ) {
				$ctx['fbp'] = Config_Manager::sanitize_fbp_cookie( (string) $parsed['_capi_fbp'] );
			}
			if ( '' === $ctx['external_id'] && ! empty( $parsed['_capi_eid'] ) ) {
				$ctx['external_id'] = Config_Manager::sanitize_external_id( (string) $parsed['_capi_eid'] );
			}
		}
		$ship_diff = ! empty( $parsed['ship_to_different_address'] );
		$pii_cand  = self::sanitize_pii_fields(
			array(
				'email'        => (string) ( $parsed['billing_email'] ?? $_POST['billing_email'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'phone'        => (string) ( $parsed['billing_phone'] ?? $parsed['shipping_phone'] ?? $_POST['billing_phone'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'first_name'   => (string) ( ( $ship_diff ? ( $parsed['shipping_first_name'] ?? '' ) : '' ) ?: ( $parsed['billing_first_name'] ?? '' ) ),
				'last_name'    => (string) ( ( $ship_diff ? ( $parsed['shipping_last_name'] ?? '' ) : '' ) ?: ( $parsed['billing_last_name'] ?? '' ) ),
				'address_1'    => (string) ( ( $ship_diff ? ( $parsed['shipping_address_1'] ?? '' ) : '' ) ?: ( $parsed['billing_address_1'] ?? $_POST['s_address'] ?? $_POST['address'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'city'         => (string) ( ( $ship_diff ? ( $parsed['shipping_city'] ?? '' ) : '' ) ?: ( $parsed['billing_city'] ?? $_POST['s_city'] ?? $_POST['city'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'state'        => (string) ( ( $ship_diff ? ( $parsed['shipping_state'] ?? '' ) : '' ) ?: ( $parsed['billing_state'] ?? $_POST['s_state'] ?? $_POST['state'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'postal_code'  => (string) ( ( $ship_diff ? ( $parsed['shipping_postcode'] ?? '' ) : '' ) ?: ( $parsed['billing_postcode'] ?? $_POST['s_postcode'] ?? $_POST['postcode'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'country_code' => (string) ( ( $ship_diff ? ( $parsed['shipping_country'] ?? '' ) : '' ) ?: ( $parsed['billing_country'] ?? $_POST['s_country'] ?? $_POST['country'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);
		$merged = self::save_context_to_session( array_merge( $ctx, $pii_cand ) );
		self::maybe_emit_server_add_payment_info( $merged );
	}

	/**
	 * Block Checkout Store API draft order update (`woocommerce_store_api_checkout_update_order_from_request`).
	 */
	public static function on_store_api_update_order( $order, $request = null ): void {
		if ( Config_Manager::is_safe_or_bot_request() ) {
			return;
		}
		$merged = self::capture_and_merge_session_context();
		if ( $order instanceof \WC_Order ) {
			$existing_raw              = $order->get_meta( self::META_FROZEN_CTX, true );
			$existing                  = is_string( $existing_raw ) && '' !== $existing_raw ? json_decode( $existing_raw, true ) : ( is_array( $existing_raw ) ? $existing_raw : array() );
			$final_ctx                 = self::merge_contexts( is_array( $existing ) ? $existing : array(), $merged );
			$final_ctx['raw_page_url'] = self::normalize_to_public_a_site_url( (string) $final_ctx['raw_page_url'], '/checkout/' );
			$order->update_meta_data( self::META_FROZEN_CTX, wp_json_encode( $final_ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
	}

	/**
	 * Classic checkout callback (`woocommerce_checkout_order_processed`).
	 */
	public static function on_classic_checkout_processed( $order_id, $posted_data = array(), $order = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		}
		if ( $order instanceof \WC_Order ) {
			self::freeze_and_emit_checkout( $order );
		}
	}

	/**
	 * Order object callback (`woocommerce_checkout_order_created` & Store API).
	 */
	public static function on_order_created_object( $order ): void {
		if ( $order instanceof \WC_Order ) {
			self::freeze_and_emit_checkout( $order );
		}
	}

	/**
	 * Freeze buyer context into HPOS Order Meta and emit fallback InitiateCheckout / AddPaymentInfo if not already sent during checkout browsing.
	 */
	public static function freeze_and_emit_checkout( \WC_Order $order ): void {
		$existing_raw = $order->get_meta( self::META_FROZEN_CTX, true );
		$existing_ctx = is_string( $existing_raw ) && '' !== $existing_raw ? json_decode( $existing_raw, true ) : ( is_array( $existing_raw ) ? $existing_raw : array() );
		if ( ! is_array( $existing_ctx ) ) {
			$existing_ctx = array();
		}

		$session_ctx = self::read_session_context();
		$live_ctx    = self::capture_live_browser_context();
		$order_pii   = self::sanitize_pii_fields(
			array(
				'email'        => (string) $order->get_billing_email(),
				'phone'        => (string) ( $order->get_billing_phone() ?: $order->get_shipping_phone() ),
				'first_name'   => (string) ( $order->get_billing_first_name() ?: $order->get_shipping_first_name() ),
				'last_name'    => (string) ( $order->get_billing_last_name() ?: $order->get_shipping_last_name() ),
				'address_1'    => (string) ( $order->get_shipping_address_1() ?: $order->get_billing_address_1() ),
				'city'         => (string) ( $order->get_billing_city() ?: $order->get_shipping_city() ),
				'state'        => (string) ( $order->get_billing_state() ?: $order->get_shipping_state() ),
				'postal_code'  => (string) ( $order->get_billing_postcode() ?: $order->get_shipping_postcode() ),
				'country_code' => (string) ( $order->get_billing_country() ?: $order->get_shipping_country() ),
			)
		);
		$frozen_ctx  = self::merge_contexts( self::merge_contexts( $session_ctx, $existing_ctx ), array_merge( $live_ctx, $order_pii ) );

		if ( empty( $frozen_ctx['client_ip'] ) && method_exists( $order, 'get_customer_ip_address' ) ) {
			$ord_ip = (string) $order->get_customer_ip_address();
			if ( Config_Manager::is_valid_public_buyer_ip( $ord_ip ) ) {
				$frozen_ctx['client_ip'] = $ord_ip;
			}
		}
		if ( empty( $frozen_ctx['client_user_agent'] ) && method_exists( $order, 'get_customer_user_agent' ) ) {
			$frozen_ctx['client_user_agent'] = (string) $order->get_customer_user_agent();
		}
		if ( empty( $frozen_ctx['external_id'] ) && method_exists( $order, 'get_customer_id' ) && (int) $order->get_customer_id() > 0 ) {
			$frozen_ctx['external_id'] = 'wc_u_' . (int) $order->get_customer_id();
		}
		$frozen_ctx['raw_page_url'] = self::normalize_to_public_a_site_url( (string) $frozen_ctx['raw_page_url'], '/checkout/' );

		self::save_context_to_session( $frozen_ctx );
		$order->update_meta_data( self::META_FROZEN_CTX, wp_json_encode( $frozen_ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$order->save();

		if ( class_exists( '\PeptideCore\Test_Orders' ) && method_exists( '\PeptideCore\Test_Orders', 'is_test_order' ) && \PeptideCore\Test_Orders::is_test_order( $order ) ) {
			return;
		}

		$tenant = Config_Manager::resolve_current_tenant();
		if ( ! $tenant || Config_Manager::is_organic_retention_hub( $tenant ) ) {
			return;
		}

		$meta_changed = false;

		// 1. Emit fallback InitiateCheckout only if the shopper session did not already emit it when opening `/checkout/`.
		if ( '1' !== (string) $order->get_meta( self::META_IC_SENT, true ) ) {
			$order->update_meta_data( self::META_IC_SENT, '1' );
			$meta_changed = true;
			if ( ! self::was_checkout_step_sent_recently( 'InitiateCheckout', 7200 ) ) {
				$ic_uuid    = sprintf( 'ord_%d_%d_ic', (int) $tenant['blog_id'], (int) $order->get_id() );
				$ic_payload = self::build_order_payload( $order, 'InitiateCheckout', $ic_uuid, $frozen_ctx, $tenant );
				$res_ic     = Config_Manager::dispatch_to_gateway( $ic_payload, $tenant );
				if ( ! $res_ic['ok'] ) {
					Retry_Reconciler::enqueue_event_retry( $ic_payload, $tenant );
				}
			}
		}

		// 2. Emit fallback AddPaymentInfo if the shopper session did not already emit it during address/payment entry.
		if ( '1' !== (string) $order->get_meta( self::META_API_SENT, true ) ) {
			$order->update_meta_data( self::META_API_SENT, '1' );
			$meta_changed = true;
			if ( ! self::was_checkout_step_sent_recently( 'AddPaymentInfo', 7200 ) ) {
				$api_uuid    = sprintf( 'ord_%d_%d_api', (int) $tenant['blog_id'], (int) $order->get_id() );
				$api_payload = self::build_order_payload( $order, 'AddPaymentInfo', $api_uuid, $frozen_ctx, $tenant );
				$res_api     = Config_Manager::dispatch_to_gateway( $api_payload, $tenant );
				if ( ! $res_api['ok'] ) {
					Retry_Reconciler::enqueue_event_retry( $api_payload, $tenant );
				}
			}
		}

		if ( $meta_changed ) {
			$order->save();
		}
	}

	/**
	 * Read frozen buyer context strictly from Order Meta (NEVER reads live webhook $_SERVER!).
	 */
	public static function read_frozen_context( \WC_Order $order ): array {
		$raw = $order->get_meta( self::META_FROZEN_CTX, true );
		$ctx = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : array() );
		if ( ! is_array( $ctx ) ) {
			$ctx = array();
		}

		// Strictly fallback ONLY to WC_Order persisted checkout fields, NEVER $_SERVER, and reject server/private IPs!
		$cand_ip = ! empty( $ctx['client_ip'] ) ? (string) $ctx['client_ip'] : (string) $order->get_customer_ip_address();
		$ip      = Config_Manager::is_valid_public_buyer_ip( $cand_ip ) ? $cand_ip : '';
		$ua      = ! empty( $ctx['client_user_agent'] ) ? (string) $ctx['client_user_agent'] : (string) $order->get_customer_user_agent();
		$eid     = isset( $ctx['external_id'] ) ? Config_Manager::sanitize_external_id( (string) $ctx['external_id'] ) : '';
		if ( '' === $eid && method_exists( $order, 'get_customer_id' ) && (int) $order->get_customer_id() > 0 ) {
			$eid = 'wc_u_' . (int) $order->get_customer_id();
		}

		$raw_url = ! empty( $ctx['raw_page_url'] ) ? (string) $ctx['raw_page_url'] : '';

		return array(
			'client_ip'         => $ip,
			'client_user_agent' => $ua,
			'fbp'               => isset( $ctx['fbp'] ) ? Config_Manager::sanitize_fbp_cookie( (string) $ctx['fbp'] ) : '',
			'fbc'               => isset( $ctx['fbc'] ) ? Config_Manager::sanitize_fbc_cookie( (string) $ctx['fbc'] ) : '',
			'external_id'       => $eid,
			'raw_page_url'      => self::normalize_to_public_a_site_url( $raw_url, '/checkout/' ),
			'consent_granted'   => isset( $ctx['consent_granted'] ) ? (bool) $ctx['consent_granted'] : true,
			'email'             => (string) ( $ctx['email'] ?? '' ),
			'phone'             => (string) ( $ctx['phone'] ?? '' ),
			'first_name'        => (string) ( $ctx['first_name'] ?? '' ),
			'last_name'         => (string) ( $ctx['last_name'] ?? '' ),
			'city'              => (string) ( $ctx['city'] ?? '' ),
			'state'             => (string) ( $ctx['state'] ?? '' ),
			'postal_code'       => (string) ( $ctx['postal_code'] ?? '' ),
			'country_code'      => (string) ( $ctx['country_code'] ?? '' ),
			'frozen_at'         => isset( $ctx['frozen_at'] ) ? (int) $ctx['frozen_at'] : time(),
		);
	}

	/**
	 * Shared HPOS-safe builder for Order events (InitiateCheckout, AddPaymentInfo & Purchase).
	 * Strictly preserves authentic `event_time` (`date_paid` -> `date_created` -> real event timestamp).
	 */
	public static function build_order_payload( \WC_Order $order, string $event_name, string $action_uuid, array $frozen_ctx, array $tenant ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$qty     = max( 1, (int) $item->get_quantity() );
			$product = $item->get_product();
			$raw_sku = '';
			if ( $product instanceof \WC_Product ) {
				$raw_sku = (string) $product->get_sku();
			}
			if ( '' === $raw_sku ) {
				$vid     = (int) $item->get_variation_id();
				$pid     = (int) $item->get_product_id();
				$raw_sku = 'WC_' . ( $vid > 0 ? $vid : $pid );
			}
			$line_total = (float) $item->get_total();
			$unit_price = round( $line_total / $qty, 2 );

			$items[] = array(
				'raw_sku'    => $raw_sku,
				'quantity'   => $qty,
				'unit_price' => $unit_price,
			);
		}

		$occurred_at = time();
		if ( 'Purchase' === $event_name ) {
			if ( $order->get_date_paid() ) {
				$paid_ts = (int) $order->get_date_paid()->getTimestamp();
				if ( $paid_ts > 0 ) {
					$occurred_at = $paid_ts;
				}
			} elseif ( $order->get_date_created() ) {
				$created_ts = (int) $order->get_date_created()->getTimestamp();
				if ( $created_ts > 0 ) {
					$occurred_at = $created_ts;
				}
			}
		}

		$safe_page_url = self::normalize_to_public_a_site_url(
			! empty( $frozen_ctx['raw_page_url'] ) ? (string) $frozen_ctx['raw_page_url'] : '',
			'/checkout/'
		);

		return array(
			'site_key'          => (string) $tenant['site_key'],
			'blog_id'           => (int) $tenant['blog_id'],
			'action_uuid'       => $action_uuid,
			'order_reference'   => (string) $order->get_order_number(),
			'consent_granted'   => isset( $frozen_ctx['consent_granted'] ) ? (bool) $frozen_ctx['consent_granted'] : true,
			'event_name'        => $event_name,
			'occurred_at'       => $occurred_at,
			'raw_page_url'      => $safe_page_url,
			'currency'          => (string) ( $order->get_currency() ?: 'USD' ),
			'total_value'       => round( (float) $order->get_total(), 2 ),
			'client_ip'         => (string) ( $frozen_ctx['client_ip'] ?? '' ),
			'client_user_agent' => (string) ( $frozen_ctx['client_user_agent'] ?? '' ),
			'fbp'               => (string) ( $frozen_ctx['fbp'] ?? '' ),
			'fbc'               => (string) ( $frozen_ctx['fbc'] ?? '' ),
			'external_id'       => (string) ( $frozen_ctx['external_id'] ?? '' ),
			'email'             => (string) ( $order->get_billing_email() ?: ( $frozen_ctx['email'] ?? '' ) ),
			'phone'             => (string) ( $order->get_billing_phone() ?: $order->get_shipping_phone() ?: ( $frozen_ctx['phone'] ?? '' ) ),
			'first_name'        => (string) ( $order->get_billing_first_name() ?: $order->get_shipping_first_name() ?: ( $frozen_ctx['first_name'] ?? '' ) ),
			'last_name'         => (string) ( $order->get_billing_last_name() ?: $order->get_shipping_last_name() ?: ( $frozen_ctx['last_name'] ?? '' ) ),
			'city'              => (string) ( $order->get_billing_city() ?: $order->get_shipping_city() ?: ( $frozen_ctx['city'] ?? '' ) ),
			'state'             => (string) ( $order->get_billing_state() ?: $order->get_shipping_state() ?: ( $frozen_ctx['state'] ?? '' ) ),
			'postal_code'       => (string) ( $order->get_billing_postcode() ?: $order->get_shipping_postcode() ?: ( $frozen_ctx['postal_code'] ?? '' ) ),
			'country_code'      => (string) ( $order->get_billing_country() ?: $order->get_shipping_country() ?: ( $frozen_ctx['country_code'] ?? '' ) ),
			'items'             => $items,
		);
	}
}
