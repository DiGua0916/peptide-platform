<?php
/** Private server-to-server protocol. No connector credentials enter checkout JavaScript. */
namespace VialCove\Panapay;
defined( 'ABSPATH' ) || exit;

final class Hub_Protocol {
	public const NS = 'pc-panapay/v1';
	public const CONFIG = '/pc-panapay/v1/hub/config';
	public const METHODS = '/pc-panapay/v1/hub/methods';
	public const CREATE = '/pc-panapay/v1/hub/session';
	public const STATUS = '/pc-panapay/v1/hub/session/status';
	public const EVENT = '/pc-panapay/v1/store/event';

	public static function event_key( string $key ): string { return hash_hmac( 'sha256', 'pc-panapay-event-v1', $key ); }
	public static function json( array $data ): string {
		$json = wp_json_encode( (object) $data );
		if ( ! is_string( $json ) || strlen( $json ) > 131072 ) { throw new Protocol_Error( 'invalid_message', 413 ); }
		return $json;
	}
	public static function signature( string $key, string $method, string $route, int $time, string $nonce, string $body ): string {
		return hash_hmac( 'sha256', "pc-pay-v1\n" . strtoupper( $method ) . "\n" . $route . "\n" . $time . "\n" . $nonce . "\n" . hash( 'sha256', $body ), $key );
	}
	public static function verify_request( \WP_REST_Request $request, string $key, string $uuid ): array {
		$time = (string) $request->get_header( 'x_pc_pay_time' );
		$nonce = (string) $request->get_header( 'x_pc_pay_nonce' );
		$sign = (string) $request->get_header( 'x_pc_pay_sign' );
		$body = (string) $request->get_body();
		$query = $request->get_query_params(); unset( $query['rest_route'] );
		if ( strlen( $key ) !== 64 || '' === $uuid || ! hash_equals( $uuid, (string) $request->get_header( 'x_pc_pay_store' ) )
			|| ! preg_match( '/^[0-9]{10}$/D', $time ) || abs( time() - (int) $time ) > 300
			|| ! preg_match( '/^[a-f0-9]{32}$/D', $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/D', $sign )
			|| strlen( $body ) > 131072 || $query || 'POST' !== $request->get_method()
			|| ! hash_equals( self::signature( $key, $request->get_method(), $request->get_route(), (int) $time, $nonce, $body ), $sign ) ) {
			throw new Protocol_Error( 'connection_not_authorized', 401 );
		}
		// A committed UNIQUE insert, independent of caller transactions and object caches.
		$db = Database::open();
		try {
			$name = '_pc_pay_nonce_' . hash( 'sha256', $uuid . '|' . $key . '|' . $nonce );
			$inserted = $db->query( $db->prepare( "INSERT IGNORE INTO {$db->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", $name, (string) ( time() + 600 ) ) );
			if ( false === $inserted || '' !== (string) $db->last_error ) { throw new Protocol_Error( 'connection_storage_unavailable', 503 ); }
			if ( 1 !== $inserted ) { throw new Protocol_Error( 'connection_request_replayed', 409 ); }
			$db->query( $db->prepare( "DELETE FROM {$db->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 100", $db->esc_like( '_pc_pay_nonce_' ) . '%', time() ) );
		} finally { $db->close(); }
		return array( 'nonce' => $nonce, 'uuid' => $uuid );
	}
	public static function response( array $data, string $nonce, string $key ): \WP_REST_Response {
		$response = new \WP_REST_Response( array( 'data' => (object) $data, 'nonce' => $nonce,
			'sign' => hash_hmac( 'sha256', "pc-pay-response-v1\n" . $nonce . "\n" . hash( 'sha256', self::json( $data ) ), $key ) ) );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		return $response;
	}
	public static function unwrap( array $envelope, string $nonce, string $key ): array {
		if ( ! is_array( $envelope['data'] ?? null ) || ! is_string( $envelope['nonce'] ?? null ) || ! hash_equals( $nonce, $envelope['nonce'] )
			|| ! is_string( $envelope['sign'] ?? null ) || ! hash_equals( hash_hmac( 'sha256', "pc-pay-response-v1\n" . $nonce . "\n" . hash( 'sha256', self::json( $envelope['data'] ) ), $key ), $envelope['sign'] ) ) {
			throw new Protocol_Error( 'connection_response_invalid', 503 );
		}
		return $envelope['data'];
	}
	public static function base_url( string $url ): string {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) || isset( $p['user'], $p['pass'] ) || isset( $p['user'] ) || isset( $p['pass'] )
			|| isset( $p['query'] ) || isset( $p['fragment'] ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url )
			|| str_contains( rawurldecode( $p['path'] ?? '' ), '..' ) ) { throw new Protocol_Error( 'connection_url_invalid', 503 ); }
		$host = strtolower( $p['host'] );
		$local = 'local' === wp_get_environment_type() && ( in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true ) || str_ends_with( $host, '.local' ) );
		if ( $local ) {
			if ( ! in_array( $p['scheme'] ?? '', array( 'http', 'https' ), true ) || ( isset( $p['port'] ) && ( $p['port'] < 1 || $p['port'] > 65535 ) ) ) { throw new Protocol_Error( 'connection_url_invalid', 503 ); }
		} else {
			Configuration::origin( ( $p['scheme'] ?? '' ) . '://' . $host . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) );
		}
		return trailingslashit( ( $p['scheme'] ?? '' ) . '://' . $host . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . ( $p['path'] ?? '' ) );
	}
	public static function request( string $base, string $key, string $uuid, string $route, array $data, int $timeout = 20 ): array {
		if ( ! in_array( $route, array( self::CONFIG, self::METHODS, self::CREATE, self::STATUS, self::EVENT ), true ) ) { throw new Protocol_Error( 'connection_route_invalid', 503 ); }
		$base = self::base_url( $base );
		$body = self::json( $data ); $time = time(); $nonce = bin2hex( random_bytes( 16 ) );
		$url = add_query_arg( 'rest_route', $route, $base );
		$host = (string) wp_parse_url( $base, PHP_URL_HOST ); $port = (int) wp_parse_url( $base, PHP_URL_PORT );
		$allow_host = static function ( $allowed, $candidate ) use ( $host ) { return 'local' === wp_get_environment_type() && $candidate === $host ? true : $allowed; };
		$allow_port = static function ( $ports ) use ( $port ) { if ( 'local' === wp_get_environment_type() && $port ) { $ports[] = $port; } return array_unique( $ports ); };
		add_filter( 'http_request_host_is_external', $allow_host, 10, 2 ); add_filter( 'http_allowed_safe_ports', $allow_port );
		try {
			$res = wp_safe_remote_post( $url, array( 'timeout' => min( 40, max( 2, $timeout ) ), 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536,
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-PC-Pay-Store' => $uuid,
					'X-PC-Pay-Time' => (string) $time, 'X-PC-Pay-Nonce' => $nonce, 'X-PC-Pay-Sign' => self::signature( $key, 'POST', $route, $time, $nonce, $body ) ), 'body' => $body ) );
		} finally { remove_filter( 'http_request_host_is_external', $allow_host, 10 ); remove_filter( 'http_allowed_safe_ports', $allow_port ); }
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) { throw new Protocol_Error( 'payment_connection_unavailable', 503 ); }
		$result = json_decode( (string) wp_remote_retrieve_body( $res ), true, 20 );
		if ( ! is_array( $result ) ) { throw new Protocol_Error( 'connection_response_invalid', 503 ); }
		return self::unwrap( $result, $nonce, $key );
	}
}
