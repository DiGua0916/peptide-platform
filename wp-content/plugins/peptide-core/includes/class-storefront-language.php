<?php
/** Read-only storefront language selection. It does not translate, redirect or change WordPress locale. */
namespace PeptideCore;

use PeptideCore\Tracking\Country_Provider;

defined( 'ABSPATH' ) || exit;

final class Storefront_Language {
	public const OPTION = 'pc_storefront_language_settings';
	public const PREFERENCE_COOKIE = 'pc_storefront_language';
	public const VERSION = 1;
	public const KEYS = array( 'enabled', 'default_language', 'us_language', 'pr_language' );
	private const LOCALES = array( 'en' => 'en_US', 'es' => 'es_PR' );

	public static function defaults(): array {
		return array( 'enabled' => false, 'default_language' => 'en', 'us_language' => 'en', 'pr_language' => 'es' );
	}

	/** A complete replacement only. Validation never writes options, cookies, notices or request state. */
	public static function normalize( $input ) {
		if ( ! is_array( $input ) || array_is_list( $input ) || array_diff( array_keys( $input ), self::KEYS ) || array_diff( self::KEYS, array_keys( $input ) ) ) {
			return self::error( 'settings', '请提交完整的语言识别设置，不接受额外字段。' );
		}
		if ( ! is_bool( $input['enabled'] ) ) { return self::error( 'enabled', '语言识别开关必须为 true 或 false。' ); }
		foreach ( array( 'default_language', 'us_language', 'pr_language' ) as $key ) {
			if ( ! self::supported( $input[ $key ] ) ) { return self::error( $key, '语言仅支持 en 或 es。' ); }
		}
		$out = array();
		foreach ( self::KEYS as $key ) { $out[ $key ] = $input[ $key ]; }
		return $out;
	}

	/** Caller handles WP_Error before saving; this is not a write-on-read or coercive Settings API sanitizer. */
	public static function sanitize( $input ) { return self::normalize( $input ); }

	/** The only option read. Missing, malformed or unavailable storage stays disabled without repair writes. */
	public static function get(): array {
		try { return self::effective_settings( get_option( self::OPTION, self::defaults() ) ); }
		catch ( \Throwable $error ) { return self::defaults(); }
	}

	public static function public_config( $settings = null ): array {
		$settings = null === $settings ? self::get() : self::effective_settings( $settings );
		return array(
			'version' => self::VERSION,
			'enabled' => $settings['enabled'],
			'default_language' => $settings['default_language'],
			'country_languages' => array( 'US' => $settings['us_language'], 'PR' => $settings['pr_language'] ),
			'supported_languages' => array(
				array( 'code' => 'en', 'locale' => 'en_US', 'label' => 'English' ),
				array( 'code' => 'es', 'locale' => 'es_PR', 'label' => 'Español' ),
			),
			'preference_cookie' => self::PREFERENCE_COOKIE,
		);
	}

	/** Pure decision: null settings means defaults, never an option/geolocation/browser-language read. */
	public static function resolve( $settings = null, $preference = null, $country = null ): array {
		$settings = self::effective_settings( null === $settings ? self::defaults() : $settings );
		if ( self::supported( $preference ) ) { return self::selection( $preference, 'manual', null ); }
		if ( ! $settings['enabled'] ) { return self::selection( $settings['default_language'], 'disabled', null ); }
		$code = is_string( $country ) && 1 === preg_match( '/\A[A-Z]{2}\z/D', $country ) ? $country : null;
		$mapped = array( 'US' => $settings['us_language'], 'PR' => $settings['pr_language'] );
		return null !== $code && isset( $mapped[ $code ] )
			? self::selection( $mapped[ $code ], 'geoip', $code )
			: self::selection( $settings['default_language'], 'default', $code );
	}

	/** Only the server's canonical REMOTE_ADDR reaches the existing local, read-only country provider. */
	public static function current_request( $settings = null ): array {
		$settings = null === $settings ? self::get() : self::effective_settings( $settings );
		$preference = $_COOKIE[ self::PREFERENCE_COOKIE ] ?? null;
		if ( ! $settings['enabled'] || self::supported( $preference ) ) { return self::resolve( $settings, $preference ); }
		$country = null;
		$address = $_SERVER['REMOTE_ADDR'] ?? null;
		if ( is_string( $address ) && '' !== $address ) {
			try {
				$result = Country_Provider::lookup( $address );
				if ( 'known' === ( $result['status'] ?? null ) ) { $country = $result['country_code'] ?? null; }
			} catch ( \Throwable $error ) { /* Missing/invalid local GeoIP stays at the configured default. */ }
		}
		return self::resolve( $settings, null, $country );
	}

	private static function supported( $language ): bool { return is_string( $language ) && isset( self::LOCALES[ $language ] ); }
	private static function effective_settings( $settings ): array {
		$normalized = self::normalize( $settings );
		return $normalized instanceof \WP_Error ? self::defaults() : $normalized;
	}
	private static function selection( string $language, string $source, ?string $country ): array {
		return array( 'language' => $language, 'locale' => self::LOCALES[ $language ], 'source' => $source, 'country_code' => $country, 'translation_applied' => false );
	}
	private static function error( string $field, string $message ): \WP_Error {
		return new \WP_Error( 'pc_storefront_language_invalid', $message, array( 'status' => 400, 'field' => $field ) );
	}
}
