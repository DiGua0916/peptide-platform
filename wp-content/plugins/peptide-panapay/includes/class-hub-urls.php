<?php
/** Registered store addresses separate private delivery from public buyer returns. */
namespace VialCove\Panapay;
defined( 'ABSPATH' ) || exit;

final class Hub_Urls {
	public static function management( array $site ): string {
		return Hub_Protocol::base_url( \PeptideHq\Site_Urls::management( $site ) );
	}

	public static function public_url( array $site ): string {
		return Hub_Protocol::base_url( \PeptideHq\Site_Urls::public_url( $site ) );
	}

	/** Accept only the registered buyer origin and the native Woo callback for this local reference. */
	public static function return_url( string $url, array $site, string $reference ): string {
		$base = wp_parse_url( self::public_url( $site ) ); $next = wp_parse_url( $url );
		if ( ! is_array( $next ) || isset( $next['user'] ) || isset( $next['pass'] ) || isset( $next['fragment'] ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) { throw new Protocol_Error( 'invalid_return_target', 400 ); }
		$port = static fn( array $u ): int => (int) ( $u['port'] ?? ( 'https' === ( $u['scheme'] ?? '' ) ? 443 : 80 ) );
		if ( ( $next['scheme'] ?? '' ) !== $base['scheme'] || strtolower( $next['host'] ?? '' ) !== strtolower( $base['host'] ) || $port( $next ) !== $port( $base ) ) { throw new Protocol_Error( 'invalid_return_target', 400 ); }
		$root = trailingslashit( (string) ( $base['path'] ?? '/' ) );
		$path = $next['path'] ?? '/'; $args = array();
		foreach ( explode( '&', (string) ( $next['query'] ?? '' ) ) as $part ) {
			$pair = explode( '=', $part, 2 ); $key = urldecode( $pair[0] ); $value = urldecode( $pair[1] ?? '' );
			if ( ! in_array( $key, array( 'wc-api', 'reference', 'token' ), true ) || array_key_exists( $key, $args ) ) { throw new Protocol_Error( 'invalid_return_target', 400 ); }
			$args[ $key ] = $value;
		}
		$pretty = in_array( $path, array( $root . 'wc-api/pc_panapay_return', $root . 'wc-api/pc_panapay_return/' ), true );
		if ( ( ! $pretty && ( $path !== $root || 'pc_panapay_return' !== ( $args['wc-api'] ?? '' ) ) ) || ( $pretty && isset( $args['wc-api'] ) )
			|| ! isset( $args['reference'], $args['token'] ) || ! hash_equals( $reference, $args['reference'] ) || ! preg_match( '/^[a-f0-9]{48}$/D', $args['token'] ) ) { throw new Protocol_Error( 'invalid_return_target', 400 ); }
		Session_Service::parse_reference( $args['reference'] );
		return $url;
	}
}
