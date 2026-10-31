<?php
/** Per-store configuration; no credentials leave this server-side class. */
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Configuration {
	private static array $public_hosts = array();

	public static function load(): array {
		$settings = get_option( 'woocommerce_pc_panapay_settings', array() );
		if ( ! is_array( $settings ) ) {
			throw new \RuntimeException( 'Panapay configuration is invalid.' );
		}
		$gateway = self::override( 'PC_PANAPAY_GATEWAY', self::text( $settings, 'gateway' ) );
		$mchid = self::override( 'PC_PANAPAY_MCHID', self::text( $settings, 'mchid' ) );
		if ( '' !== $mchid && ! preg_match( '/\A[A-Za-z0-9_-]{1,128}\z/', $mchid ) ) {
			throw new \RuntimeException( 'Panapay merchant configuration is invalid.' );
		}
		$secret = self::override( 'PC_PANAPAY_SECRET', null );
		if ( null === $secret ) {
			$stored = self::text( $settings, 'secret', false );
			$secret = '' === $stored ? '' : Crypto::decrypt( $stored, 'secret' );
		}
		if ( strlen( $secret ) > 1024 || preg_match( '/[\x00-\x1f\x7f]/', $secret ) ) {
			throw new \RuntimeException( 'Panapay signing configuration is invalid.' );
		}
		$tag = self::text( $settings, 'tag' );
		$mode = self::text( $settings, 'mode' );
		$mode = '' === $mode ? '2' : $mode;
		if ( strlen( $tag ) > 255 || ! in_array( $mode, array( '1', '2' ), true ) ) {
			throw new \RuntimeException( 'Panapay configuration is invalid.' );
		}
		$test = $settings['test_mode'] ?? 'yes';
		if ( ! in_array( $test, array( true, false, 'yes', 'no', '1', '0', 1, 0, '' ), true ) ) {
			throw new \RuntimeException( 'Panapay environment configuration is invalid.' );
		}
		return array(
			'gateway'       => self::origin( $gateway ),
			'mchid'         => $mchid,
			'secret'        => $secret,
			'tag'           => $tag,
			'mode'          => $mode,
			'test_mode'     => in_array( $test, array( true, 'yes', '1', 1 ), true ),
			'payment_hosts' => self::hosts( $settings['payment_hosts'] ?? array() ),
		);
	}

	/** HTTPS origin only, with an independent public-address check even if WP filters are permissive. */
	public static function origin( string $origin ): string {
		$origin = trim( $origin );
		if ( '' === $origin ) {
			return '';
		}
		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
			|| ( isset( $parts['port'] ) && 443 !== $parts['port'] ) || ! in_array( $parts['path'] ?? '', array( '', '/' ), true ) ) {
			throw new \RuntimeException( 'Panapay gateway must be a public HTTPS origin.' );
		}
		$host = strtolower( $parts['host'] );
		$canonical = 'https://' . $host;
		if ( ! self::hostname( $host ) || false === wp_http_validate_url( $canonical ) || ! self::public_host( $host ) ) {
			throw new \RuntimeException( 'Panapay gateway must be a public HTTPS origin.' );
		}
		return $canonical;
	}

	/** Only explicitly trusted provider hosts, never a host supplied by a checkout response alone. */
	public static function payment_url( string $url, array $config ): bool {
		try {
			if ( '' === $url || strlen( $url ) > 8192 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
				return false;
			}
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] )
				|| isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
				return false;
			}
			$host = strtolower( $parts['host'] );
			$trusted = self::hosts( $config['payment_hosts'] ?? array() );
			$origin = isset( $config['gateway'] ) && is_string( $config['gateway'] ) ? self::origin( $config['gateway'] ) : '';
			$gateway = '' !== $origin ? wp_parse_url( $origin ) : false;
			if ( is_array( $gateway ) && isset( $gateway['host'] ) ) {
				$trusted[] = strtolower( $gateway['host'] );
			}
			return self::hostname( $host ) && in_array( $host, $trusted, true )
				&& false !== wp_http_validate_url( $url ) && self::public_host( $host );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	private static function text( array $settings, string $key, bool $trim = true ): string {
		$value = $settings[ $key ] ?? '';
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			throw new \RuntimeException( 'Panapay configuration is invalid.' );
		}
		return $trim ? trim( (string) $value ) : (string) $value;
	}

	private static function override( string $name, ?string $fallback ): ?string {
		$value = defined( $name ) ? constant( $name ) : getenv( $name );
		if ( false === $value ) {
			return $fallback;
		}
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			throw new \RuntimeException( 'Panapay server configuration is invalid.' );
		}
		return 'PC_PANAPAY_SECRET' === $name ? (string) $value : trim( (string) $value );
	}

	private static function hosts( $input ): array {
		if ( is_string( $input ) ) {
			$input = preg_split( '/[\s,]+/', $input, -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( ! is_array( $input ) || count( $input ) > 32 ) {
			throw new \RuntimeException( 'Panapay payment hosts are invalid.' );
		}
		$hosts = array();
		foreach ( $input as $value ) {
			if ( ! is_string( $value ) ) {
				throw new \RuntimeException( 'Panapay payment hosts are invalid.' );
			}
			$host = strtolower( trim( $value ) );
			if ( ! self::hostname( $host ) ) {
				throw new \RuntimeException( 'Panapay payment hosts are invalid.' );
			}
			$hosts[] = $host;
		}
		return array_values( array_unique( $hosts ) );
	}

	private static function hostname( string $host ): bool {
		return strlen( $host ) <= 253 && false === filter_var( $host, FILTER_VALIDATE_IP )
			&& 1 === preg_match( '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host );
	}

	private static function public_host( string $host ): bool {
		if ( array_key_exists( $host, self::$public_hosts ) ) {
			return self::$public_hosts[ $host ];
		}
		$addresses = @gethostbynamel( $host );
		$addresses = is_array( $addresses ) ? $addresses : array();
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
						$addresses[] = $record['ipv6'];
					}
				}
			}
		}
		$public = array() !== $addresses;
		foreach ( $addresses as $ip ) {
			if ( ! self::public_ip( $ip ) ) {
				$public = false;
				break;
			}
		}
		self::$public_hosts[ $host ] = $public;
		return $public;
	}

	private static function public_ip( string $ip ): bool {
		$flags = defined( 'FILTER_FLAG_GLOBAL_RANGE' ) ? FILTER_FLAG_GLOBAL_RANGE : FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, $flags ) ) {
			return false;
		}
		// PHP 8.1 lacks GLOBAL_RANGE. Exclude additional non-global IPv4 ranges there too.
		$binary = inet_pton( $ip );
		if ( false === $binary ) {
			return false;
		}
		if ( 16 === strlen( $binary ) ) {
			if ( substr( $binary, 0, 12 ) === str_repeat( "\0", 10 ) . "\xff\xff" ) {
				$mapped = inet_ntop( substr( $binary, 12 ) );
				return is_string( $mapped ) && self::public_ip( $mapped );
			}
			// HTTP payment hosts must resolve to unicast, not multicast or transition ranges.
			return ( ord( $binary[0] ) & 0xe0 ) === 0x20
				&& substr( $binary, 0, 4 ) !== "\x20\x01\x0d\xb8"
				&& substr( $binary, 0, 2 ) !== "\x20\x02"
				&& ! ( substr( $binary, 0, 2 ) === "\x20\x01" && ( ord( $binary[2] ) & 0xfe ) === 0 );
		}
		$octets = array_values( unpack( 'C4', $binary ) );
		if ( 0 === $octets[0] || 10 === $octets[0] || 127 === $octets[0] || $octets[0] >= 224
			|| ( 169 === $octets[0] && 254 === $octets[1] )
			|| ( 172 === $octets[0] && $octets[1] >= 16 && $octets[1] <= 31 )
			|| ( 192 === $octets[0] && 168 === $octets[1] ) ) {
			return false;
		}
		if ( 100 === $octets[0] && $octets[1] >= 64 && $octets[1] <= 127 ) {
			return false;
		}
		if ( 192 === $octets[0] && ( ( 0 === $octets[1] && in_array( $octets[2], array( 0, 2 ), true ) ) || ( 88 === $octets[1] && 99 === $octets[2] ) ) ) {
			return false;
		}
		return ! ( ( 198 === $octets[0] && ( in_array( $octets[1], array( 18, 19 ), true ) || ( 51 === $octets[1] && 100 === $octets[2] ) ) )
			|| ( 203 === $octets[0] && 0 === $octets[1] && 113 === $octets[2] ) );
	}
}
