<?php
/**
 * Optional CDN for static files (front-end optimisation 2026-11-07, item 6). Off by default.
 *
 * When wp-config.php defines PEPTIDE_CORE_CDN_URL (an https origin such as
 * https://d111111abcdef8.cloudfront.net, whose origin is this site), storefront pages
 * reference stylesheets, scripts and media library images through it, so phones download
 * them from the nearest edge. HTML, REST, the cart and checkout are never served from it.
 *
 * Kept on this site on purpose:
 *  - module scripts and workers (.mjs): a worker must be same-origin;
 *  - admin, login, REST, cron, previews/editors, and logged-in staff browsing;
 *  - any URL outside wp-content / wp-includes.
 * Fonts referenced from stylesheets then load from the CDN too, so the distribution must
 * send Access-Control-Allow-Origin for font files (see docs/handoffs).
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Static_Cdn {

	/** Normalised CDN origin (https://host[/path]) or '' when off or invalid. */
	public static function base(): string {
		if ( ! defined( 'PEPTIDE_CORE_CDN_URL' ) || ! is_string( PEPTIDE_CORE_CDN_URL ) ) { return ''; }
		$url = rtrim( trim( PEPTIDE_CORE_CDN_URL ), '/' );
		$p   = wp_parse_url( $url );
		if ( ! is_array( $p ) || 'https' !== ( $p['scheme'] ?? '' ) || empty( $p['host'] ) || isset( $p['query'] ) || isset( $p['fragment'] ) || isset( $p['user'] ) || isset( $p['pass'] ) ) { return ''; }
		return $url;
	}

	public static function register_hooks(): void {
		if ( '' === self::base() ) { return; }
		add_action( 'template_redirect', array( self::class, 'start' ), 0 );
	}

	/** Storefront page views only. */
	public static function start(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_preview() || is_customize_preview()
			|| ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) || isset( $_GET['pc_template_preview'] ) || isset( $_GET['pc_frame'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		foreach ( array( 'style_loader_src', 'script_loader_src', 'wp_get_attachment_url' ) as $hook ) {
			add_filter( $hook, array( self::class, 'rewrite' ), PHP_INT_MAX );
		}
		add_filter( 'wp_calculate_image_srcset', array( self::class, 'srcset' ), PHP_INT_MAX );
	}

	/**
	 * Pure rewrite of one URL. $site is this site's origin (scheme://host[:port]).
	 */
	public static function rewrite_url( string $url, string $site, string $cdn ): string {
		if ( '' === $cdn || '' === $url ) { return $url; }
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '/\.mjs$/i', $path ) || ! preg_match( '#^/(?:[^?\#]*/)?wp-(?:content|includes)/#', $path ) ) { return $url; }
		if ( str_starts_with( $url, '//' ) ) { $url = 'https:' . $url; }
		$site = rtrim( $site, '/' );
		$host = static fn( string $u ): string => strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) . ( wp_parse_url( $u, PHP_URL_PORT ) ? ':' . wp_parse_url( $u, PHP_URL_PORT ) : '' );
		if ( str_starts_with( $url, '/' ) ) { return $cdn . $url; }
		if ( $host( $url ) !== $host( $site ) ) { return $url; }
		$rest = preg_replace( '#^https?://[^/]+#i', '', $url );
		return $cdn . $rest;
	}

	public static function rewrite( $url ) {
		return is_string( $url ) ? self::rewrite_url( $url, home_url(), self::base() ) : $url;
	}

	public static function srcset( $sources ) {
		if ( ! is_array( $sources ) ) { return $sources; }
		foreach ( $sources as &$source ) {
			if ( is_array( $source ) && isset( $source['url'] ) && is_string( $source['url'] ) ) { $source['url'] = self::rewrite( $source['url'] ); }
		}
		unset( $source );
		return $sources;
	}
}
