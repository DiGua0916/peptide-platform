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

	/**
	 * 浏览器报的时区（Intl.DateTimeFormat().resolvedOptions().timeZone，CR 线 1，访问表 timezone 列）。
	 * 只认 IANA 名字的形状（America/New_York、UTC、Etc/GMT+5），最长 64；不认识的返回空字符串，不报错。
	 */
	public static function timezone( $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 64 ) { return ''; }
		return 1 === preg_match( '~\A[A-Za-z][A-Za-z0-9_+\-]{0,31}(?:/[A-Za-z0-9_+\-]{1,31}){0,2}\z~D', $value ) ? $value : '';
	}

	/**
	 * 浏览器的首选语言（navigator.language，CR 线 1，访问表 language 列），BCP 47 形状，最长 35。
	 * 语言部分转小写、两位字母的地区转大写（en-us → en-US，es-419、zh-Hant 不变）；不认识的返回空字符串。
	 */
	public static function language( $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 35 || 1 !== preg_match( '/\A[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8}){0,4}\z/D', $value ) ) { return ''; }
		$parts    = explode( '-', $value );
		$parts[0] = strtolower( $parts[0] );
		foreach ( $parts as $i => $part ) {
			if ( $i > 0 && 2 === strlen( $part ) && ctype_alpha( $part ) ) { $parts[ $i ] = strtoupper( $part ); }
		}
		return implode( '-', $parts );
	}
}
