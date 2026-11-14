<?php
/** Read-only country observation from an explicitly configured, existing local MMDB. */
namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Country_Provider {
	/** Independent native validation; a missing global-range capability fails closed. */
	public static function classify( $address, ?int $global_range_flag ): array {
		$public = null !== $global_range_flag && is_string( $address ) && false !== filter_var( $address, FILTER_VALIDATE_IP, $global_range_flag );
		if ( $public ) {
			$packed = inet_pton( $address );
			if ( false === $packed || ( 4 === strlen( $packed ) && ord( $packed[0] ) >= 224 ) || ( 16 === strlen( $packed ) && 255 === ord( $packed[0] ) ) ) { $public = false; }
		}
		return array( 'status' => $public ? 'unavailable' : 'unknown' );
	}

	/** Only absolute local files: no stream wrappers, URLs, UNC shares or relative paths.（Ip_Network 共用，CR 线 1） */
	public static function local_path( $path ): ?string {
		if ( ! is_string( $path ) || '' === $path || preg_match( '/[\x00-\x1f\x7f]/', $path ) || str_contains( $path, '://' ) || str_starts_with( $path, '//' ) || str_starts_with( $path, '\\' ) ) { return null; }
		if ( '/' === $path[0] ) { return $path; }
		if ( 1 === preg_match( '~\A[A-Za-z]:[\\\\/]~D', $path ) && ! str_contains( substr( $path, 2 ), ':' ) ) { return $path; }
		return null;
	}

	/**
	 * 2026-11-14：子店没有配置 PC_DATA_GEOIP_DATABASE、也没有 WooCommerce 的 MaxMind 国家库，国家一直是“无法判断”。
	 * 前两种都没有可读文件时，用访客追踪同一份 DB-IP 城市库（uploads/peptide-geoip/，每月自动更新，里面也有国家）。
	 */
	private static function database_path(): ?string {
		$path = self::configured_path();
		if ( null !== $path && is_file( $path ) && is_readable( $path ) ) { return $path; }
		if ( class_exists( Ip_Network::class ) ) {
			$city = Ip_Network::databases()['city'] ?? null;
			$city = self::local_path( $city );
			if ( null !== $city && is_file( $city ) && is_readable( $city ) ) { return $city; }
		}
		return $path;
	}

	/** Resolve configuration without invoking Woo's integration constructor/updater/getters. */
	private static function configured_path(): ?string {
		if ( defined( 'PC_DATA_GEOIP_DATABASE' ) ) {
			$explicit = constant( 'PC_DATA_GEOIP_DATABASE' );
			if ( '' !== $explicit ) { return self::local_path( $explicit ); }
		}
		if ( ! function_exists( 'wp_upload_dir' ) || ! function_exists( 'get_option' ) ) { return null; }
		$settings = get_option( 'woocommerce_maxmind_geolocation_settings', array() );
		if ( ! is_array( $settings ) ) { return null; }
		$prefix = array_key_exists( 'database_prefix', $settings ) ? $settings['database_prefix'] : '';
		if ( ! is_string( $prefix ) || ( '' !== $prefix && 1 !== preg_match( '/\A[A-Za-z0-9]{32}\z/D', $prefix ) ) ) { return null; }
		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) ) { return null; }
		$base = self::local_path( $uploads['basedir'] ?? null );
		if ( null === $base ) { return null; }
		$path = rtrim( $base, '/\\' ) . '/woocommerce_uploads/' . ( '' === $prefix ? '' : $prefix . '-' ) . 'GeoLite2-Country.mmdb';
		if ( function_exists( 'apply_filters' ) ) { $path = apply_filters( 'woocommerce_maxmind_geolocation_database_path', $path ); }
		return self::local_path( $path );
	}

	/** Reuse Woo's bundled Reader only. Includes define classes and never start integration services.（Ip_Network 共用，CR 线 1） */
	public static function reader_available(): bool {
		if ( class_exists( '\MaxMind\Db\Reader' ) ) { return true; }
		$base = defined( 'WC_ABSPATH' ) ? constant( 'WC_ABSPATH' ) : ( defined( 'WC_PLUGIN_FILE' ) && is_string( constant( 'WC_PLUGIN_FILE' ) ) ? dirname( constant( 'WC_PLUGIN_FILE' ) ) : null );
		$base = self::local_path( $base );
		if ( null === $base ) { return false; }
		$root = rtrim( $base, '/\\' ) . '/vendor/maxmind-db/reader/src/MaxMind/Db/';
		$files = array(
			'\MaxMind\Db\Reader\InvalidDatabaseException' => $root . 'Reader/InvalidDatabaseException.php',
			'\MaxMind\Db\Reader\Util' => $root . 'Reader/Util.php',
			'\MaxMind\Db\Reader\Decoder' => $root . 'Reader/Decoder.php',
			'\MaxMind\Db\Reader\Metadata' => $root . 'Reader/Metadata.php',
			'\MaxMind\Db\Reader' => $root . 'Reader.php',
		);
		foreach ( $files as $file ) { if ( ! is_file( $file ) || ! is_readable( $file ) ) { return false; } }
		foreach ( $files as $class => $file ) { if ( ! class_exists( $class ) ) { @require_once $file; } }
		return class_exists( '\MaxMind\Db\Reader' );
	}

	/** Fixed license credit only for an explicitly attributed, readable local resource. */
	public static function attribution(): ?array {
		try {
			$path = self::database_path();
			if ( null === $path || ! is_file( $path ) || ! is_readable( $path ) ) { return null; }
		} catch ( \Throwable $error ) { return null; }
		$declared = defined( 'PC_DATA_GEOIP_ATTRIBUTION' ) && 'db-ip' === constant( 'PC_DATA_GEOIP_ATTRIBUTION' ) && defined( 'PC_DATA_GEOIP_DATABASE' ) && '' !== constant( 'PC_DATA_GEOIP_DATABASE' );
		// 用的是 DB-IP 的库（包括上面退回的城市库）就按它的许可写出处。
		if ( ! $declared && false === stripos( basename( (string) realpath( $path ) ), 'dbip' ) ) { return null; }
		return array( 'label' => 'IP Geolocation by DB-IP', 'url' => 'https://db-ip.com' );
	}

	public static function render_attribution(): void {
		$source = self::attribution();
		if ( null === $source ) { return; }
		echo '<p class="pc-geoip-attribution"><a href="' . esc_url( $source['url'] ) . '">' . esc_html( $source['label'] ) . '</a></p>';
	}

	/** The address exists only in this call and the local Reader; errors return status only. */
	public static function lookup( $address ): array {
		$flag = defined( 'FILTER_FLAG_GLOBAL_RANGE' ) ? constant( 'FILTER_FLAG_GLOBAL_RANGE' ) : null;
		$status = self::classify( $address, is_int( $flag ) ? $flag : null );
		if ( 'unavailable' !== $status['status'] ) { return $status; }
		try {
			$path = self::database_path();
			if ( null === $path || ! is_file( $path ) || ! is_readable( $path ) || ! self::reader_available() ) { return $status; }
			$path = self::local_path( realpath( $path ) );
			if ( null === $path ) { return $status; }
			$reader = new \MaxMind\Db\Reader( $path );
			try {
				$data = $reader->get( $address );
				$code = is_array( $data ) && is_array( $data['country'] ?? null ) ? ( $data['country']['iso_code'] ?? null ) : null;
				if ( ! is_string( $code ) || 1 !== preg_match( '/\A[A-Z]{2}\z/D', $code ) || ! function_exists( 'WC' ) ) { return $status; }
				$woo = WC();
				if ( ! is_object( $woo ) || ! is_object( $woo->countries ?? null ) || ! is_callable( array( $woo->countries, 'get_countries' ) ) ) { return $status; }
				$countries = $woo->countries->get_countries();
				if ( ! is_array( $countries ) || ! array_key_exists( $code, $countries ) ) { return $status; }
				return array( 'status' => 'known', 'country_code' => $code );
			} finally { $reader->close(); }
		} catch ( \Throwable $error ) { return $status; }
	}
}
