<?php
/**
 * Serializes Store API cart writes per WooCommerce session.
 *
 * WooCommerce reads the whole session when a Store API cart route starts and
 * writes it back on shutdown (WC_Session_Handler::save_data, priority 20) with
 * INSERT … ON DUPLICATE KEY UPDATE. Two concurrent add-item requests for the
 * same session therefore both start from the same cart and the later save
 * overwrites the earlier one, losing quantity. Store API loads the session
 * lazily inside the route callback, so taking an advisory lock in
 * rest_pre_dispatch and releasing it after save_data makes each write start
 * from the previous write's saved cart. Cart contents, prices and WooCommerce
 * behaviour are unchanged.
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Cart_Write_Lock {
	public const WAIT_SECONDS = 10;
	private const ROUTE = '#\A/wc/store(?:/v\d+)?/(?:cart|batch|checkout)(?:/|\z)#i';

	/** @var string|null Lock currently held by this PHP request. */
	private static ?string $held = null;

	public static function register_hooks(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'before_dispatch' ), 5, 3 );
		// After WooCommerce's session save_data (shutdown priority 20).
		add_action( 'shutdown', array( self::class, 'release' ), 1000 );
	}

	/**
	 * @param mixed            $result  Pre-dispatch result.
	 * @param mixed            $server  REST server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function before_dispatch( $result, $server, $request ) {
		if ( null !== $result || null !== self::$held || ! $request instanceof \WP_REST_Request ) { return $result; }
		if ( in_array( strtoupper( $request->get_method() ), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) { return $result; }
		if ( ! preg_match( self::ROUTE, (string) $request->get_route() ) ) { return $result; }
		$identity = self::session_identity();
		if ( null === $identity ) { return $result; } // A brand-new session has nothing to overwrite yet.
		$name = self::lock_name( $identity );
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $name, self::WAIT_SECONDS ) );
		if ( '1' === (string) $got ) { self::$held = $name; return $result; }
		if ( '0' === (string) $got ) {
			return new \WP_Error( 'pc_cart_busy', __( 'Your bag is still updating. Please try again in a moment.', 'peptide-core' ), array( 'status' => 503, 'retry_after' => 2 ) );
		}
		// NULL/unsupported (for example the SQLite development database): keep native behaviour.
		return $result;
	}

	public static function release(): void {
		if ( null === self::$held ) { return; }
		global $wpdb;
		$name = self::$held;
		self::$held = null;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/** The key WooCommerce will load this request's cart session from, before it is loaded. */
	public static function session_identity(): ?string {
		$token = isset( $_SERVER['HTTP_CART_TOKEN'] ) && is_string( $_SERVER['HTTP_CART_TOKEN'] ) ? trim( wp_unslash( $_SERVER['HTTP_CART_TOKEN'] ) ) : '';
		if ( '' !== $token && strlen( $token ) <= 4096 ) { return 'token:' . $token; }
		$user = get_current_user_id();
		if ( $user > 0 ) { return 'user:' . $user; }
		$cookie_name = apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . ( defined( 'COOKIEHASH' ) ? COOKIEHASH : '' ) );
		$cookie = isset( $_COOKIE[ $cookie_name ] ) && is_string( $_COOKIE[ $cookie_name ] ) ? wp_unslash( $_COOKIE[ $cookie_name ] ) : '';
		$customer = explode( '||', $cookie )[0];
		return '' !== $customer && strlen( $customer ) <= 128 ? 'guest:' . $customer : null;
	}

	/** MySQL advisory locks are server-wide; scope them to this database and table prefix. Max 64 chars. */
	public static function lock_name( string $identity ): string {
		global $wpdb;
		$site = substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix ), 0, 12 );
		return 'pc-cart-' . $site . '-' . substr( hash( 'sha256', $identity ), 0, 32 );
	}

	/** Test-only state reset. */
	public static function reset_for_tests(): void { self::$held = null; }
}
