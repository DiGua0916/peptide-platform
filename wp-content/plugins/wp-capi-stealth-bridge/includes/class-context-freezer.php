<?php
/**
 * Buyer Context Freezer: Freezes real US residential IP, UA, _fbp, _fbc & URL
 * into HPOS Order Meta at the exact second before redirecting to external cashier.
 */

namespace StealthCapiBridge;

defined( 'ABSPATH' ) || exit;

final class Context_Freezer {

	public const META_FROZEN_CTX = '_capi_frozen_buyer_ctx';
	public const META_IC_SENT    = '_capi_ic_sent_lock';

	public static function register_hooks(): void {
		// Classic WooCommerce checkout hooks.
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'on_order_created_object' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'on_classic_checkout_processed' ), 10, 3 );

		// Block / WooCommerce Store API checkout hook.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'on_order_created_object' ), 10, 1 );
	}

	/**
	 * Capture live browser request context (ONLY call during real buyer HTTP requests).
	 */
	public static function capture_live_browser_context(): array {
		$ip = Config_Manager::extract_buyer_ip();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		// Priority: POST hidden input -> Store API custom header -> First-party cookie -> Legacy Meta cookie.
		$fbp_candidates = array(
			isset( $_POST['_capi_fbp'] ) ? sanitize_text_field( wp_unslash( $_POST['_capi_fbp'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			isset( $_SERVER['HTTP_X_CAPI_FBP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CAPI_FBP'] ) ) : '',
			isset( $_COOKIE['_capi_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_capi_fbp'] ) ) : '',
			isset( $_COOKIE['_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) : '',
		);
		$fbp = '';
		foreach ( $fbp_candidates as $cand ) {
			$clean = Config_Manager::sanitize_fb_cookie( $cand );
			if ( '' !== $clean ) {
				$fbp = $clean;
				break;
			}
		}

		$fbc_candidates = array(
			isset( $_POST['_capi_fbc'] ) ? sanitize_text_field( wp_unslash( $_POST['_capi_fbc'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			isset( $_SERVER['HTTP_X_CAPI_FBC'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CAPI_FBC'] ) ) : '',
			isset( $_COOKIE['_capi_fbc'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_capi_fbc'] ) ) : '',
			isset( $_COOKIE['_fbc'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) ) : '',
		);
		$fbc = '';
		foreach ( $fbc_candidates as $cand ) {
			$clean = Config_Manager::sanitize_fb_cookie( $cand );
			if ( '' !== $clean ) {
				$fbc = $clean;
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
			$host    = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : parse_url( home_url(), PHP_URL_HOST );
			$req_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
			$raw_url = $scheme . '://' . $host . $req_uri;
		}

		// Synthesize _fbc on the fly if fbclid is present in GET or raw_url and cookie wasn't set yet.
		if ( '' === $fbc ) {
			$fbclid = '';
			if ( ! empty( $_GET['fbclid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$fbclid = trim( sanitize_text_field( wp_unslash( $_GET['fbclid'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			} elseif ( $raw_url ) {
				$query = (string) wp_parse_url( $raw_url, PHP_URL_QUERY );
				if ( $query ) {
					parse_str( $query, $qp );
					if ( ! empty( $qp['fbclid'] ) && is_string( $qp['fbclid'] ) ) {
						$fbclid = trim( $qp['fbclid'] );
					}
				}
			}
			if ( '' !== $fbclid ) {
				$ms  = (int) round( microtime( true ) * 1000 );
				$fbc = Config_Manager::sanitize_fb_cookie( 'fb.1.' . $ms . '.' . $fbclid );
			}
		}

		$gpc             = isset( $_SERVER['HTTP_SEC_GPC'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) ) : '';
		$consent_granted = ( '1' !== $gpc );

		return array(
			'client_ip'         => $ip,
			'client_user_agent' => $ua,
			'fbp'               => $fbp,
			'fbc'               => $fbc,
			'raw_page_url'      => $raw_url ?: home_url( '/checkout/' ),
			'consent_granted'   => $consent_granted,
			'frozen_at'         => time(),
		);
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
	 * Freeze buyer context into HPOS Order Meta (immutable once captured) and emit InitiateCheckout.
	 */
	public static function freeze_and_emit_checkout( \WC_Order $order ): void {
		$tenant = Config_Manager::resolve_current_tenant();
		if ( ! $tenant ) {
			return;
		}

		$existing_raw = $order->get_meta( self::META_FROZEN_CTX, true );
		$frozen_ctx   = is_string( $existing_raw ) && '' !== $existing_raw ? json_decode( $existing_raw, true ) : ( is_array( $existing_raw ) ? $existing_raw : null );

		if ( ! is_array( $frozen_ctx ) || empty( $frozen_ctx['client_ip'] ) ) {
			$frozen_ctx = self::capture_live_browser_context();
			// Fallback to WC order customer IP/UA if captured via custom REST layer.
			if ( empty( $frozen_ctx['client_ip'] ) && method_exists( $order, 'get_customer_ip_address' ) ) {
				$frozen_ctx['client_ip'] = (string) $order->get_customer_ip_address();
			}
			if ( empty( $frozen_ctx['client_user_agent'] ) && method_exists( $order, 'get_customer_user_agent' ) ) {
				$frozen_ctx['client_user_agent'] = (string) $order->get_customer_user_agent();
			}
			$order->update_meta_data( self::META_FROZEN_CTX, wp_json_encode( $frozen_ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			$order->save();
		}

		// Emit InitiateCheckout once per order.
		if ( '1' === (string) $order->get_meta( self::META_IC_SENT, true ) ) {
			return;
		}

		$order->update_meta_data( self::META_IC_SENT, '1' );
		$order->save();

		$action_uuid = sprintf( 'ord_%d_%d_ic', (int) $tenant['blog_id'], (int) $order->get_id() );
		$payload     = self::build_order_payload( $order, 'InitiateCheckout', $action_uuid, $frozen_ctx, $tenant );
		$res         = Config_Manager::dispatch_to_gateway( $payload, $tenant );
		if ( ! $res['ok'] ) {
			Retry_Reconciler::enqueue_event_retry( $payload, $tenant );
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

		// Strictly fallback ONLY to WC_Order persisted checkout fields, NEVER $_SERVER!
		$ip = ! empty( $ctx['client_ip'] ) ? (string) $ctx['client_ip'] : (string) $order->get_customer_ip_address();
		$ua = ! empty( $ctx['client_user_agent'] ) ? (string) $ctx['client_user_agent'] : (string) $order->get_customer_user_agent();

		return array(
			'client_ip'         => $ip,
			'client_user_agent' => $ua,
			'fbp'               => isset( $ctx['fbp'] ) ? (string) $ctx['fbp'] : '',
			'fbc'               => isset( $ctx['fbc'] ) ? (string) $ctx['fbc'] : '',
			'raw_page_url'      => ! empty( $ctx['raw_page_url'] ) ? (string) $ctx['raw_page_url'] : home_url( '/checkout/' ),
			'consent_granted'   => isset( $ctx['consent_granted'] ) ? (bool) $ctx['consent_granted'] : true,
			'frozen_at'         => isset( $ctx['frozen_at'] ) ? (int) $ctx['frozen_at'] : time(),
		);
	}

	/**
	 * Shared HPOS-safe builder for Order events (InitiateCheckout & Purchase).
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
		if ( 'Purchase' === $event_name && $order->get_date_paid() ) {
			$paid_ts = $order->get_date_paid()->getTimestamp();
			// Ensure within Gateway's 6.5-day window.
			if ( $paid_ts > 0 && ( time() - $paid_ts ) < 550000 ) {
				$occurred_at = $paid_ts;
			}
		}

		return array(
			'site_key'          => (string) $tenant['site_key'],
			'blog_id'           => (int) $tenant['blog_id'],
			'action_uuid'       => $action_uuid,
			'order_reference'   => (string) $order->get_order_number(),
			'consent_granted'   => isset( $frozen_ctx['consent_granted'] ) ? (bool) $frozen_ctx['consent_granted'] : true,
			'event_name'        => $event_name,
			'occurred_at'       => $occurred_at,
			'raw_page_url'      => ! empty( $frozen_ctx['raw_page_url'] ) ? (string) $frozen_ctx['raw_page_url'] : home_url( '/checkout/' ),
			'currency'          => (string) ( $order->get_currency() ?: 'USD' ),
			'total_value'       => round( (float) $order->get_total(), 2 ),
			'client_ip'         => (string) ( $frozen_ctx['client_ip'] ?? '' ),
			'client_user_agent' => (string) ( $frozen_ctx['client_user_agent'] ?? '' ),
			'fbp'               => (string) ( $frozen_ctx['fbp'] ?? '' ),
			'fbc'               => (string) ( $frozen_ctx['fbc'] ?? '' ),
			'email'             => (string) $order->get_billing_email(),
			'phone'             => (string) ( $order->get_billing_phone() ?: $order->get_shipping_phone() ),
			'first_name'        => (string) ( $order->get_billing_first_name() ?: $order->get_shipping_first_name() ),
			'last_name'         => (string) ( $order->get_billing_last_name() ?: $order->get_shipping_last_name() ),
			'city'              => (string) ( $order->get_billing_city() ?: $order->get_shipping_city() ),
			'state'             => (string) ( $order->get_billing_state() ?: $order->get_shipping_state() ),
			'postal_code'       => (string) ( $order->get_billing_postcode() ?: $order->get_shipping_postcode() ),
			'country_code'      => (string) ( $order->get_billing_country() ?: $order->get_shipping_country() ),
			'items'             => $items,
		);
	}
}
