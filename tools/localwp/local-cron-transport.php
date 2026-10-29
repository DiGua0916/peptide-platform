<?php
/**
 * Plugin Name: VialCove Local Cron Transport
 * Description: Use the Requests socket transport only for this local WordPress site's non-blocking cron spawn.
 * Version: 1.0.0
 *
 * Local development utility. Copy this file to a site's mu-plugins directory only when explicitly needed.
 * Does not disable cron or change its schedule, lock, HTTP payload, TLS or proxy options.
 */

namespace VialCove\LocalWp\CronTransport;

defined( 'ABSPATH' ) || exit;

final class Local_Cron_Transport {

	public static function register(): void {
		add_action( 'requests-requests.before_request', array( self::class, 'select_transport' ), 10, 5 );
	}

	/** Select a transport for this one request; preserve all other request data and options. */
	public static function select_transport( &$url, &$headers, &$data, &$type, &$options ): void {
		if ( ! function_exists( 'wp_get_environment_type' ) || 'local' !== wp_get_environment_type()
			|| 'POST' !== $type || ! is_string( $url ) || ! is_array( $options )
			|| false !== ( $options['blocking'] ?? null ) || null !== ( $options['transport'] ?? null )
			|| ! empty( $options['proxy'] ) ) {
			return;
		}
		$actual   = wp_parse_url( $url );
		$expected = wp_parse_url( site_url( 'wp-cron.php' ) );
		if ( ! is_array( $actual ) || ! is_array( $expected )
			|| isset( $actual['user'] ) || isset( $actual['pass'] ) || isset( $actual['fragment'] ) ) {
			return;
		}
		$scheme = strtolower( (string) ( $actual['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true )
			|| $scheme !== strtolower( (string) ( $expected['scheme'] ?? '' ) )
			|| empty( $actual['host'] ) || empty( $expected['host'] )
			|| strtolower( $actual['host'] ) !== strtolower( $expected['host'] )
			|| self::port( $actual ) !== self::port( $expected )
			|| ( $actual['path'] ?? '/' ) !== ( $expected['path'] ?? '/' ) ) {
			return;
		}
		$query = array();
		parse_str( (string) ( $actual['query'] ?? '' ), $query );
		if ( ! isset( $query['doing_wp_cron'] ) || ! is_string( $query['doing_wp_cron'] ) || '' === $query['doing_wp_cron'] ) {
			return;
		}
		$transport = \WpOrg\Requests\Transport\Fsockopen::class;
		if ( ! function_exists( 'stream_socket_client' ) || ! class_exists( $transport )
			|| ! $transport::test( array( \WpOrg\Requests\Capability::SSL => 'https' === $scheme ) ) ) {
			return;
		}
		$socket = self::apache_socket( $scheme );
		if ( null === $socket && ! self::loopback( $actual['host'] ) ) {
			// A local hostname can still point through a proxy/router that cancels a closed request.
			return;
		}
		if ( null !== $socket ) {
			if ( ! isset( $options['hooks'] ) || ! $options['hooks'] instanceof \WpOrg\Requests\Hooks ) {
				return;
			}
			// Keep the canonical URL, Host header and request path. Bypass only this site's router hop.
			$options['hooks']->register(
				'fsockopen.remote_socket',
				static function ( &$remote_socket ) use ( $socket ): void {
					$remote_socket = $socket;
				}
			);
		}
		$options['transport'] = $transport;
	}

	/** Only trust server-provided information for the Apache currently serving this WordPress root. */
	private static function apache_socket( string $scheme ): ?string {
		if ( 'http' !== $scheme || ! preg_match( '#\bApache(?:/|\b)#i', (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ) ) ) {
			return null;
		}
		$address = (string) ( $_SERVER['SERVER_ADDR'] ?? '' );
		$port    = (string) ( $_SERVER['SERVER_PORT'] ?? '' );
		$docroot = (string) ( $_SERVER['DOCUMENT_ROOT'] ?? '' );
		if ( ! self::loopback( $address ) || ! preg_match( '/^[0-9]{1,5}$/D', $port )
			|| (int) $port < 1 || (int) $port > 65535 || '' === $docroot ) {
			return null;
		}
		$root = realpath( ABSPATH );
		$doc  = realpath( $docroot );
		if ( false === $root || false === $doc ) {
			return null;
		}
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		$doc  = rtrim( str_replace( '\\', '/', $doc ), '/' );
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$root = strtolower( $root );
			$doc  = strtolower( $doc );
		}
		if ( $root !== $doc ) {
			return null;
		}
		$address = str_contains( $address, ':' ) ? '[' . trim( $address, '[]' ) . ']' : $address;
		return 'tcp://' . $address . ':' . (int) $port;
	}

	private static function loopback( string $host ): bool {
		$host = trim( $host, '[]' );
		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return '127' === explode( '.', $host )[0];
		}
		return filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && inet_pton( '::1' ) === inet_pton( $host );
	}

	/** An explicit default port and its implicit equivalent identify the same origin. */
	private static function port( array $parts ): int {
		return isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) ? 443 : 80 );
	}
}

Local_Cron_Transport::register();
