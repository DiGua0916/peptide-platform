<?php
/**
 * Cross-browser / FB WebView checkout resume token (72h TTL, HMAC-verified, encrypted at rest).
 * Restores cart items, applied coupons, and customer shipping/billing fields in external browsers (Safari/Chrome)
 * without exposing PII in the URL.
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Checkout_Resume {

	public const QUERY_ARG = 'pc_resume';
	public const ROUTE     = '/checkout-resume';
	private const TTL      = 3 * DAY_IN_SECONDS;
	private const PREFIX   = 'pc_chk_res_';

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'template_redirect', array( self::class, 'handle_resume_link' ), 2 );
	}

	public static function register_routes(): void {
		register_rest_route(
			Api\Rest_Controller::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'create_token' ),
			)
		);
	}

	private static function sign( string $id ): string {
		return substr( hash_hmac( 'sha256', 'pc-checkout-resume-v1|' . $id, wp_salt( 'auth' ) ), 0, 32 );
	}

	private static function encrypt( array $payload ): string {
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$key = hash( 'sha256', wp_salt( 'secure_auth' ), true );
			$iv  = random_bytes( 16 );
			$enc = openssl_encrypt( $json, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( is_string( $enc ) && '' !== $enc ) {
				return base64_encode( $iv . $enc );
			}
		}
		return base64_encode( $json );
	}

	private static function decrypt( string $blob ): ?array {
		$raw = base64_decode( $blob, true );
		if ( ! is_string( $raw ) || strlen( $raw ) < 2 ) {
			return null;
		}
		if ( function_exists( 'openssl_decrypt' ) && strlen( $raw ) > 16 ) {
			$key = hash( 'sha256', wp_salt( 'secure_auth' ), true );
			$iv  = substr( $raw, 0, 16 );
			$dec = openssl_decrypt( substr( $raw, 16 ), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( is_string( $dec ) ) {
				$arr = json_decode( $dec, true );
				if ( is_array( $arr ) ) {
					return $arr;
				}
			}
		}
		$arr = json_decode( $raw, true );
		return is_array( $arr ) ? $arr : null;
	}

	public static function create_token( \WP_REST_Request $request ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return new \WP_Error( 'pc_resume_unavailable', 'Cart unavailable.', array( 'status' => 503 ) );
		}
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		$items = array();
		foreach ( WC()->cart->get_cart() as $row ) {
			$pid = (int) ( $row['product_id'] ?? 0 );
			$vid = (int) ( $row['variation_id'] ?? 0 );
			$qty = (int) ( $row['quantity'] ?? 0 );
			$var = is_array( $row['variation'] ?? null ) ? $row['variation'] : array();
			if ( $pid > 0 && $qty > 0 ) {
				$items[] = array(
					'p' => $pid,
					'v' => $vid,
					'q' => min( 500, $qty ),
					'a' => array_map( 'sanitize_text_field', $var ),
				);
			}
		}
		if ( ! $items ) {
			return new \WP_Error( 'pc_resume_empty', 'Cart is empty.', array( 'status' => 400 ) );
		}

		$coupons = array();
		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$clean = wc_format_coupon_code( (string) $code );
			if ( '' !== $clean ) {
				$coupons[] = $clean;
			}
		}
		if ( ! empty( $body['coupons'] ) && is_array( $body['coupons'] ) ) {
			foreach ( $body['coupons'] as $code ) {
				$clean = wc_format_coupon_code( sanitize_text_field( (string) $code ) );
				if ( '' !== $clean && ! in_array( $clean, $coupons, true ) ) {
					$coupons[] = $clean;
				}
			}
		}

		$fields   = array( 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );
		$shipping = array();
		$raw_ship = is_array( $body['shipping'] ?? null ) ? $body['shipping'] : array();
		foreach ( $fields as $f ) {
			$val = isset( $raw_ship[ $f ] ) && is_scalar( $raw_ship[ $f ] ) ? sanitize_text_field( substr( (string) $raw_ship[ $f ], 0, 190 ) ) : '';
			if ( '' === $val && WC()->customer ) {
				$getter = 'get_shipping_' . $f;
				if ( is_callable( array( WC()->customer, $getter ) ) ) {
					$val = sanitize_text_field( (string) WC()->customer->$getter() );
				}
			}
			if ( '' !== $val ) {
				$shipping[ $f ] = $val;
			}
		}

		$email = isset( $body['email'] ) && is_scalar( $body['email'] ) ? sanitize_email( (string) $body['email'] ) : '';
		if ( '' === $email && WC()->customer ) {
			$email = sanitize_email( (string) WC()->customer->get_billing_email() );
		}
		$note = isset( $body['note'] ) && is_scalar( $body['note'] ) ? sanitize_textarea_field( substr( (string) $body['note'], 0, 500 ) ) : '';

		$id   = bin2hex( random_bytes( 10 ) );
		$blob = self::encrypt(
			array(
				'items'    => $items,
				'coupons'  => array_slice( $coupons, 0, 5 ),
				'email'    => $email,
				'shipping' => $shipping,
				'note'     => $note,
				'at'       => time(),
			)
		);
		if ( '' === $blob ) {
			return new \WP_Error( 'pc_resume_failed', 'Unable to save checkout state.', array( 'status' => 500 ) );
		}
		set_transient( self::PREFIX . $id, $blob, self::TTL );

		$token = $id . '.' . self::sign( $id );
		$url   = add_query_arg( self::QUERY_ARG, rawurlencode( $token ), wc_get_checkout_url() );
		$res   = rest_ensure_response( array( 'url' => $url, 'token' => $token ) );
		$res->header( 'Cache-Control', 'private, no-store, max-age=0' );
		return $res;
	}

	public static function handle_resume_link(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || empty( $_GET[ self::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$raw = sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$parts = explode( '.', $raw, 2 );
		if ( 2 !== count( $parts ) || ! preg_match( '/\A[a-f0-9]{20}\z/', $parts[0] ) || ! hash_equals( self::sign( $parts[0] ), $parts[1] ) ) {
			wp_safe_redirect( remove_query_arg( self::QUERY_ARG ) );
			exit;
		}

		$blob = get_transient( self::PREFIX . $parts[0] );
		$data = is_string( $blob ) ? self::decrypt( $blob ) : null;
		if ( ! is_array( $data ) || ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->customer ) {
			wp_safe_redirect( remove_query_arg( self::QUERY_ARG ) );
			exit;
		}

		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		if ( ! empty( $data['items'] ) && is_array( $data['items'] ) ) {
			WC()->cart->empty_cart();
			foreach ( $data['items'] as $item ) {
				$pid = (int) ( $item['p'] ?? 0 );
				$vid = (int) ( $item['v'] ?? 0 );
				$qty = (int) ( $item['q'] ?? 0 );
				$var = is_array( $item['a'] ?? null ) ? $item['a'] : array();
				if ( $pid > 0 && $qty > 0 ) {
					try {
						WC()->cart->add_to_cart( $pid, $qty, $vid, $var );
					} catch ( \Throwable $ignored ) {}
				}
			}
		}

		$ship = is_array( $data['shipping'] ?? null ) ? $data['shipping'] : array();
		foreach ( array( 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ) as $f ) {
			if ( ! empty( $ship[ $f ] ) && is_string( $ship[ $f ] ) ) {
				$s_set = 'set_shipping_' . $f;
				$b_set = 'set_billing_' . $f;
				if ( is_callable( array( WC()->customer, $s_set ) ) ) {
					WC()->customer->$s_set( $ship[ $f ] );
				}
				if ( is_callable( array( WC()->customer, $b_set ) ) ) {
					WC()->customer->$b_set( $ship[ $f ] );
				}
			}
		}
		if ( ! empty( $data['email'] ) && is_string( $data['email'] ) ) {
			WC()->customer->set_billing_email( $data['email'] );
		}
		WC()->customer->save();

		if ( ! empty( $data['coupons'] ) && is_array( $data['coupons'] ) ) {
			foreach ( $data['coupons'] as $code ) {
				$clean = wc_format_coupon_code( (string) $code );
				if ( '' !== $clean && ! WC()->cart->has_discount( $clean ) ) {
					try {
						WC()->cart->apply_coupon( $clean );
					} catch ( \Throwable $ignored ) {}
				}
			}
		}
		WC()->cart->calculate_totals();
		if ( WC()->session ) {
			if ( ! empty( $data['note'] ) ) {
				WC()->session->set( 'pc_resumed_note', (string) $data['note'] );
			}
			WC()->session->save_data();
		}

		wp_safe_redirect( add_query_arg( 'pc_resumed', '1', wc_get_checkout_url() ) );
		exit;
	}
}
