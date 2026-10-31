<?php
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Signature {
	/** The supplied contract uses PHP's RFC1738 query encoding, not JSON signing. */
	public static function generate( array $fields, string $secret ): string {
		if ( '' === $secret ) { throw new \InvalidArgumentException( 'Missing signing key.' ); }
		foreach ( $fields as $key => $value ) {
			if ( ! is_string( $key ) || ( ! is_string( $value ) && ! is_int( $value ) ) ) {
				throw new \InvalidArgumentException( 'Invalid signing fields.' );
			}
		}
		ksort( $fields );
		return md5( http_build_query( $fields, '', '&', PHP_QUERY_RFC1738 ) . '&key=' . $secret );
	}

	public static function verify( array $fields, string $signature, string $secret ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/Di', $signature )
			&& hash_equals( self::generate( $fields, $secret ), strtolower( $signature ) );
	}
}
