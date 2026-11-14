<?php
/**
 * Cart and checkout render first (site 1 P2-2, signed off 2026-11-14).
 *
 * Woo's cart/checkout block script prints last in the footer, so every blocking storefront script before
 * it (product dialogs, homepage modules, tracking, pixels) had to download and run before the bag or the
 * form appeared. On the cart and checkout pages only, this marks our own theme/plugin scripts "defer"
 * when nothing in their dependency tree is Woo's blocks/data code or wp.data. They still run, in order,
 * before DOMContentLoaded, just after Woo has rendered. WordPress keeps a script blocking whenever a
 * blocking script depends on it, and the filter below can keep any handle blocking.
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Commerce_Speed {

	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'defer' ), PHP_INT_MAX );
		add_action( 'wp_footer', array( self::class, 'defer' ), 1 );
	}

	/**
	 * Handles a script must not run after: the ones that let it hook into how Woo's cart/checkout renders
	 * (checkout filters, slot fills, payment method registration). Reading wp.data / the cart store is fine
	 * later: those stores load first anyway, as dependencies of Woo's own block script.
	 */
	public static function hooks_into_render( string $dep ): bool {
		return in_array( $dep, array( 'wc-blocks-checkout', 'wc-blocks-registry', 'wc-blocks-components', 'wp-plugins', 'wc-blocks' ), true )
			|| 0 === strpos( $dep, 'wc-cart-checkout' )
			|| (bool) preg_match( '/^wc-.*-block(?:-frontend)?$/', $dep );
	}

	public static function touches_blocks( array $closure ): bool {
		foreach ( $closure as $dep ) {
			if ( self::hooks_into_render( (string) $dep ) ) {
				return true;
			}
		}
		return false;
	}

	/** True for script files that belong to this site (theme or this plugin). */
	public static function ours( string $src ): bool {
		return (bool) preg_match( '#/wp-content/(?:themes/peptide-site|plugins/peptide-core)/#', $src );
	}

	public static function defer(): void {
		if ( is_admin() || ! function_exists( 'is_cart' ) || ! ( is_cart() || ( is_checkout() && ! is_wc_endpoint_url() ) ) ) {
			return;
		}
		$scripts = wp_scripts();
		/** Handles that must stay blocking on cart/checkout. */
		$keep = (array) apply_filters( 'peptide_core_commerce_blocking_scripts', array() );
		foreach ( $scripts->registered as $handle => $script ) {
			if ( in_array( $handle, $keep, true ) || ! is_string( $script->src ) || ! self::ours( $script->src ) ) {
				continue;
			}
			if ( '' !== (string) $scripts->get_data( $handle, 'strategy' ) ) {
				continue; // Already defer/async by its owner.
			}
			if ( self::touches_blocks( self::closure( $scripts, $handle ) ) ) {
				continue;
			}
			$scripts->add_data( $handle, 'strategy', 'defer' );
		}
	}

	/**
	 * Dependencies of a handle. Recurses only through our own scripts: a third-party dependency stays
	 * blocking itself, so what it needs has already run; only its own name matters here.
	 */
	private static function closure( \WP_Scripts $scripts, string $handle, array &$seen = array() ): array {
		$deps = isset( $scripts->registered[ $handle ] ) ? (array) $scripts->registered[ $handle ]->deps : array();
		foreach ( $deps as $dep ) {
			if ( isset( $seen[ $dep ] ) ) {
				continue;
			}
			$seen[ $dep ] = true;
			$src = isset( $scripts->registered[ $dep ] ) ? (string) $scripts->registered[ $dep ]->src : '';
			if ( self::ours( $src ) ) {
				self::closure( $scripts, $dep, $seen );
			}
		}
		return array_keys( $seen );
	}
}
