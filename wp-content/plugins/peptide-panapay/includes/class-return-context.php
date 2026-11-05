<?php
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

/** A purpose-bound HQ proof grants read access to one order, never payment authority. */
final class Return_Context {

	private const PURPOSE = 'pc-panapay-return-check-v1';

	/** The caller must already authenticate the attempt and validate its frozen return target. */
	public static function check_url( string $validated_frozen_url, string $key ): string {
		$parts = wp_parse_url( $validated_frozen_url );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? null, array( 'https', 'http' ), true )
			|| ! is_string( $parts['host'] ?? null ) || isset( $parts['user'] ) || isset( $parts['pass'] )
			|| isset( $parts['fragment'] ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $validated_frozen_url ) ) {
			throw new Protocol_Error( 'invalid_return_target', 400 );
		}
		$args = array();
		foreach ( explode( '&', (string) ( $parts['query'] ?? '' ) ) as $part ) {
			$pair = explode( '=', $part, 2 ); $name = urldecode( $pair[0] );
			if ( ! in_array( $name, array( 'wc-api', 'reference', 'token' ), true ) || array_key_exists( $name, $args ) ) {
				throw new Protocol_Error( 'invalid_return_target', 400 );
			}
			$args[ $name ] = urldecode( $pair[1] ?? '' );
		}
		$path = (string) ( $parts['path'] ?? '/' );
		$pretty = 1 === preg_match( '~/(?:wc-api/pc_panapay_return)/?\z~D', $path );
		if ( ( $pretty && isset( $args['wc-api'] ) ) || ( ! $pretty && 'pc_panapay_return' !== ( $args['wc-api'] ?? null ) )
			|| ! self::identity( $args['reference'] ?? null, $args['token'] ?? null ) ) {
			throw new Protocol_Error( 'invalid_return_target', 400 );
		}
		$proof = array( 'purpose' => self::PURPOSE, 'reference' => $args['reference'], 'token' => $args['token'] );
		return add_query_arg( array( 'check' => '1', 'sign' => Signature::generate( $proof, $key ) ), $validated_frozen_url );
	}

	/** No provider result or additional field can be smuggled into the read-only proof. */
	public static function verify_check( array $data, string $key ): bool {
		if ( count( $data ) !== 4 || array_diff( array_keys( $data ), array( 'reference', 'token', 'check', 'sign' ) )
			|| '1' !== ( $data['check'] ?? null ) || ! is_string( $data['sign'] ?? null )
			|| ! self::identity( $data['reference'] ?? null, $data['token'] ?? null ) || '' === $key ) {
			return false;
		}
		return Signature::verify(
			array( 'purpose' => self::PURPOSE, 'reference' => $data['reference'], 'token' => $data['token'] ),
			$data['sign'], $key
		);
	}

	private static function identity( $reference, $token ): bool {
		if ( ! is_string( $reference ) || ! is_string( $token ) || ! preg_match( '/\A[a-f0-9]{48}\z/D', $token ) ) { return false; }
		try { Session_Service::parse_reference( $reference ); }
		catch ( \Throwable $error ) { return false; }
		return true;
	}
}
