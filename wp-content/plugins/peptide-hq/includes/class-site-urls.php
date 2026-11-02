<?php
/** Registered management and public addresses have separate purposes. */
namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Site_Urls {

	/** Explicit dual-domain addresses are HTTPS origins, never arbitrary request URLs. */
	public static function https_root( string $url ): string {
		if ( '' === $url || trim( $url ) !== $url || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) { return ''; }
		$u = wp_parse_url( $url );
		if ( ! is_array( $u ) || 'https' !== ( $u['scheme'] ?? '' ) || empty( $u['host'] ) || isset( $u['user'] ) || isset( $u['pass'] ) || isset( $u['query'] ) || isset( $u['fragment'] ) || isset( $u['port'] ) && 443 !== $u['port'] || ! in_array( $u['path'] ?? '', array( '', '/' ), true ) ) { return ''; }
		$host = strtolower( $u['host'] );
		if ( strlen( $host ) > 253 || ! str_contains( $host, '.' ) || filter_var( $host, FILTER_VALIDATE_IP ) || ! preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host ) ) { return ''; }
		foreach ( explode( '.', $host ) as $label ) { if ( strlen( $label ) > 63 || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label ) ) { return ''; } }
		return 'https://' . $host . '/';
	}

	public static function management( array $site ): string {
		return ! empty( $site['dual_domain'] ) ? self::https_root( (string) ( $site['management_url'] ?? '' ) ) : (string) ( $site['url'] ?? '' );
	}

	public static function public_url( array $site ): string {
		return ! empty( $site['dual_domain'] ) ? self::https_root( (string) ( $site['public_url'] ?? '' ) ) : (string) ( $site['url'] ?? '' );
	}

	/** Only the registered management origin (and legacy installation path) may receive a login. */
	public static function allows_login( array $site, string $url ): bool {
		$base = wp_parse_url( self::management( $site ) );
		$next = wp_parse_url( $url );
		if ( ! is_array( $base ) || ! is_array( $next ) || empty( $base['host'] ) || empty( $next['host'] ) || isset( $next['user'] ) || isset( $next['pass'] ) || isset( $next['fragment'] ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) { return false; }
		$port = static fn( array $u ): int => (int) ( $u['port'] ?? ( 'https' === ( $u['scheme'] ?? '' ) ? 443 : 80 ) );
		$path = trailingslashit( (string) ( $base['path'] ?? '/' ) );
		if ( preg_match( '~(?:^|/)(?:\.{1,2}|%2e(?:%2e)?)(?:/|$)~i', rawurldecode( (string) ( $next['path'] ?? '/' ) ) ) ) { return false; }
		return ( $base['scheme'] ?? '' ) === ( $next['scheme'] ?? '' ) && strtolower( $base['host'] ) === strtolower( $next['host'] ) && $port( $base ) === $port( $next ) && str_starts_with( (string) ( $next['path'] ?? '/' ), $path );
	}
}
