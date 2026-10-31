<?php
/** Authenticated encryption for server-side gateway credentials and session URLs. */
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Crypto {
	private const CIPHER = 'aes-256-gcm';
	private const PREFIX = 'v1:';
	private const IV_BYTES = 12;
	private const TAG_BYTES = 16;

	public static function encrypt( string $plain, string $context = 'secret' ): string {
		try {
			self::available();
			$iv = random_bytes( self::IV_BYTES );
			$tag = '';
			$cipher = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, self::aad( $context ), self::TAG_BYTES );
			if ( false === $cipher || self::TAG_BYTES !== strlen( $tag ) ) {
				throw new \RuntimeException();
			}
			return self::PREFIX . base64_encode( $iv . $tag . $cipher );
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Panapay encryption is unavailable.' );
		}
	}

	public static function decrypt( string $encoded, string $context = 'secret' ): string {
		try {
			self::available();
			if ( ! str_starts_with( $encoded, self::PREFIX ) || strlen( $encoded ) > 262144 ) {
				throw new \RuntimeException();
			}
			$body = substr( $encoded, strlen( self::PREFIX ) );
			$raw = base64_decode( $body, true );
			if ( false === $raw || base64_encode( $raw ) !== $body || strlen( $raw ) < self::IV_BYTES + self::TAG_BYTES ) {
				throw new \RuntimeException();
			}
			$plain = openssl_decrypt(
				substr( $raw, self::IV_BYTES + self::TAG_BYTES ),
				self::CIPHER,
				self::key(),
				OPENSSL_RAW_DATA,
				substr( $raw, 0, self::IV_BYTES ),
				substr( $raw, self::IV_BYTES, self::TAG_BYTES ),
				self::aad( $context )
			);
			if ( false === $plain ) {
				throw new \RuntimeException();
			}
			return $plain;
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Panapay encrypted configuration could not be read.' );
		}
	}

	private static function available(): void {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) || ! in_array( self::CIPHER, openssl_get_cipher_methods(), true ) ) {
			throw new \RuntimeException();
		}
	}

	private static function aad( string $context ): string {
		if ( '' === $context || strlen( $context ) > 128 ) {
			throw new \RuntimeException();
		}
		return 'vialcove-panapay:v1|' . $context;
	}

	private static function key(): string {
		if ( defined( 'PC_PANAPAY_ENCRYPTION_KEY' ) ) {
			$encoded = constant( 'PC_PANAPAY_ENCRYPTION_KEY' );
		} else {
			$encoded = getenv( 'PC_PANAPAY_ENCRYPTION_KEY' );
		}
		if ( false !== $encoded ) {
			if ( ! is_string( $encoded ) ) {
				throw new \RuntimeException();
			}
			$key = base64_decode( $encoded, true );
			if ( false === $key || 32 !== strlen( $key ) || base64_encode( $key ) !== $encoded ) {
				throw new \RuntimeException();
			}
			return $key;
		}
		$salt = wp_salt( 'auth' );
		if ( ! is_string( $salt ) || '' === $salt ) {
			throw new \RuntimeException();
		}
		return hash( 'sha256', $salt, true );
	}
}
