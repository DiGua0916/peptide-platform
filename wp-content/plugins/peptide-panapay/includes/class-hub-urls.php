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
		$base = wp_parse_url( self::public_url( $site ) );
		if ( ! is_array( $base ) ) { throw new Protocol_Error( 'invalid_return_target', 400 ); }
		return self::validate_return_target( $url, $base, $reference );
	}

	/**
	 * An existing attempt retains its authenticated, original buyer target after a
	 * hostname change. The caller must first verify the browser token and provider
	 * signature. No request-supplied target or newly registered alias is accepted.
	 */
	public static function attempt_return_url( array $row, array $site ): string {
		$site_id = self::positive_id( $site['id'] ?? null );
		$order_id = self::positive_id( $row['order_id'] ?? null );
		$created = self::positive_id( $row['created_at'] ?? null );
		// Preserve same-origin legacy identities already allowed by Hub::permission.
		if ( null === $site_id || self::positive_id( $row['site_id'] ?? null ) !== $site_id || null === $order_id
			|| ! is_string( $row['site_uuid'] ?? null ) || ! is_string( $site['uuid'] ?? null )
			|| ! preg_match( '/\A[a-zA-Z0-9_-]{16,64}\z/D', $row['site_uuid'] )
			|| ! hash_equals( $row['site_uuid'], $site['uuid'] ) || null === $created || time() - $created > 7 * DAY_IN_SECONDS
			|| ! is_string( $row['local_reference'] ?? null ) || ! is_string( $row['return_box'] ?? null ) ) {
			throw new Protocol_Error( 'invalid_return_target', 400 );
		}
		$reference = $row['local_reference'];
		if ( Session_Service::parse_reference( $reference )['order_id'] !== $order_id ) { throw new Protocol_Error( 'invalid_return_target', 400 ); }
		$url = Crypto::decrypt( $row['return_box'], 'hub-return' );
		$base = wp_parse_url( self::public_url( $site ) ); $next = wp_parse_url( $url );
		if ( ! is_array( $base ) || ! is_array( $next ) || ! is_string( $next['host'] ?? null ) ) { throw new Protocol_Error( 'invalid_return_target', 400 ); }
		if ( strtolower( $next['host'] ) !== strtolower( $base['host'] ?? '' ) ) {
			// Only the hostname of a previously validated HTTPS root may differ.
			// This exception requires the newer complete UUID registration contract.
			// Scheme, port, installation path and callback structure stay strict.
			$old = \PeptideHq\Site_Urls::https_root( 'https://' . $next['host'] );
			if ( ! preg_match( '/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $row['site_uuid'] )
				|| empty( $site['dual_domain'] ) || 'https' !== ( $base['scheme'] ?? '' ) || 443 !== (int) ( $base['port'] ?? 443 )
				|| ! in_array( $base['path'] ?? '', array( '', '/' ), true ) || '' === $old ) { throw new Protocol_Error( 'invalid_return_target', 400 ); }
			$base['host'] = wp_parse_url( $old, PHP_URL_HOST );
		}
		return self::validate_return_target( $url, $base, $reference );
	}

	private static function positive_id( $value ): ?int {
		if ( ! is_int( $value ) && ! is_string( $value ) ) { return null; }
		$text = (string) $value; $id = (int) $value;
		return $id > 0 && preg_match( '/\A[1-9][0-9]*\z/D', $text ) && (string) $id === $text ? $id : null;
	}

	private static function validate_return_target( string $url, array $base, string $reference ): string {
		$next = wp_parse_url( $url );
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
