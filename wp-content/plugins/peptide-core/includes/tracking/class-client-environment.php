<?php
/** Optional browser-reported facts; no device identity or native hardware claims. */
namespace PeptideCore\Tracking;
defined( 'ABSPATH' ) || exit;
final class Client_Environment {
	public const OS = array( 'Windows', 'macOS', 'Linux', 'ChromeOS', 'Android', 'iOS' );
	public static function clean( $input ): array {
		$bad = array( 'ok' => false );
		if ( ! is_array( $input ) || ! is_int( $input['version'] ?? null ) || 1 !== $input['version'] ) { return $bad; }
		$out = array( 'version' => 1 );
		foreach ( array( 'os', 'screen' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) { continue; }
			$fact = $input[$key];
			if ( ! is_array( $fact ) || ! is_string( $fact['status'] ?? null ) || ! in_array( $fact['status'], array( 'known', 'unknown', 'unavailable' ), true ) ) { return $bad; }
			$normalized = array( 'status' => $fact['status'] );
			if ( 'os' === $key ) {
				if ( 'known' === $fact['status'] ) {
					if ( ! is_string( $fact['family'] ?? null ) || ! in_array( $fact['family'], self::OS, true ) ) { return $bad; }
					$normalized['family'] = $fact['family'];
				} elseif ( array_key_exists( 'family', $fact ) ) { return $bad; }
			} else {
				if ( 'known' === $fact['status'] ) {
					foreach ( array( 'width', 'height' ) as $size ) { if ( ! is_int( $fact[$size] ?? null ) || $fact[$size] < 1 || $fact[$size] > 16384 ) { return $bad; } $normalized[$size] = $fact[$size]; }
				} elseif ( array_key_exists( 'width', $fact ) || array_key_exists( 'height', $fact ) ) { return $bad; }
			}
			$out[$key] = $normalized;
		}
		return count( $out ) > 1 ? array( 'ok' => true, 'value' => $out ) : $bad;
	}
	public static function saved( $input ): ?array { $clean = self::clean( $input ); return $clean['ok'] ? $clean['value'] : null; }
}
