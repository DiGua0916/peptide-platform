<?php
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Provider_Client {
	/**
	 * No automatic HTTP retry: a timeout can occur after a session was created.
	 * Returned payment URLs remain untrusted until the exact host is approved.
	 */
	public static function create( array $payload, array $config ): array {
		$endpoint = Configuration::origin( $config['gateway'] ) . '/index.php/admin/Payments/createPayment';
		// This local policy decision precedes transport, so no provider session was created.
		if ( _wp_http_get_object()->block_request( $endpoint ) ) {
			return self::observe( array( 'state' => 'failed' ), 'local_policy_blocked', $config );
		}
		$response = wp_safe_remote_post( $endpoint, array(
			'timeout' => 30, 'redirection' => 0, 'sslverify' => true, 'reject_unsafe_urls' => true,
			'limit_response_size' => 65536,
			'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
			'body' => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $response ) ) {
			return self::observe( array( 'state' => 'unknown' ), 'transport_error', $config, $response );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return self::observe( array( 'state' => 'unknown' ), 'http_status', $config, $response );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) ) { return self::observe( array( 'state' => 'unknown' ), 'response_body_invalid', $config, $response ); }
		if ( strlen( $body ) > 65536 ) { return self::observe( array( 'state' => 'unknown' ), 'response_too_large', $config, $response ); }
		try { $data = json_decode( $body, true, 16, JSON_THROW_ON_ERROR ); }
		catch ( \Throwable $error ) { return self::observe( array( 'state' => 'unknown' ), 'response_json_invalid', $config, $response ); }
		if ( ! is_array( $data ) ) { return self::observe( array( 'state' => 'unknown' ), 'response_shape_invalid', $config, $response, $data ); }
		if ( 'FAILED' === ( $data['status'] ?? null ) ) { return self::observe( array( 'state' => 'failed' ), 'provider_failed', $config, $response, $data ); }
		$valid = 'SUCCEEDED' === ( $data['status'] ?? null );
		$containers = array( $data );
		if ( is_array( $data['data'] ?? null ) ) { $containers[] = $data['data']; }
		$session = null;
		// Each supported level must supply its own complete pair; never combine levels.
		foreach ( $containers as $container ) {
			$has_type = array_key_exists( 'type', $container );
			$has_url = array_key_exists( 'payment_url', $container );
			if ( ! $has_type && ! $has_url ) { continue; }
			if ( ! $has_type || ! $has_url || ! in_array( $container['type'], array( 'iframe', 'redirect' ), true )
				|| ! is_string( $container['payment_url'] ) || '' === $container['payment_url'] || strlen( $container['payment_url'] ) > 8192 ) {
				$valid = false; break;
			}
			$pair = array( 'type' => $container['type'], 'url' => $container['payment_url'] );
			if ( null !== $session && $session !== $pair ) { $valid = false; break; }
			$session = $pair;
		}
		if ( ! $valid || null === $session ) {
			return self::observe( array( 'state' => 'unknown' ), 'response_contract_invalid', $config, $response, $data );
		}
		return self::observe( array_merge( array( 'state' => 'created' ), $session ), 'created', $config, $response, $data );
	}

	/** Optional fixed-shape diagnostics; observers cannot change the payment result. */
	private static function observe( array $result, string $reason, array $config, $response = null, $data = null ): array {
		try {
			if ( ! has_action( 'pc_panapay_provider_diagnostic' ) ) { return $result; }
			$event = array( 'reason' => $reason );
			if ( is_wp_error( $response ) ) {
				$code = $response->get_error_code();
				$event['wp_error_code'] = in_array( $code, array( 'http_request_failed', 'http_request_not_executed', 'http_request_rejected', 'http_no_url', 'http_no_request' ), true ) ? $code : 'other';
				if ( preg_match( '/\bcURL error ([0-9]{1,3})(?:[:\s]|$)/i', substr( $response->get_error_message(), 0, 4096 ), $match ) && (int) $match[1] > 0 ) {
					$event['curl_error_number'] = (int) $match[1];
				}
			} elseif ( null !== $response ) {
				$status = (int) wp_remote_retrieve_response_code( $response );
				$event['http_status'] = $status >= 100 && $status <= 599 ? $status : 0;
			}
			$decoded = in_array( $reason, array( 'response_shape_invalid', 'provider_failed', 'response_contract_invalid', 'created' ), true );
			if ( 'http_status' === $reason ) {
				$body = wp_remote_retrieve_body( $response );
				if ( is_string( $body ) && strlen( $body ) <= 65536 ) {
					try { $data = json_decode( $body, true, 16, JSON_THROW_ON_ERROR ); $decoded = true; }
					catch ( \Throwable $ignored ) { /* Keep the HTTP diagnostic without body text. */ }
				}
			}
			if ( $decoded ) {
				$event['response_root'] = self::response_shape( $data, $config );
				$event['response_data'] = is_array( $data ) && array_key_exists( 'data', $data )
					? self::response_shape( $data['data'], $config ) : array( 'shape' => 'missing' );
			}
			do_action( 'pc_panapay_provider_diagnostic', $event );
		} catch ( \Throwable $ignored ) { /* No diagnostic failure may affect collection. */ }
		return $result;
	}

	private static function value_shape( $value ): string {
		if ( is_array( $value ) ) { return 'array'; }
		if ( is_string( $value ) ) { return 'string'; }
		if ( is_bool( $value ) ) { return 'boolean'; }
		if ( is_int( $value ) || is_float( $value ) ) { return 'number'; }
		return null === $value ? 'null' : 'other';
	}

	/** Never expose unknown field names, scalar text or a checkout URL's path/query. */
	private static function response_shape( $data, array $config ): array {
		$out = array( 'shape' => self::value_shape( $data ) );
		if ( ! is_array( $data ) ) { return $out; }
		foreach ( array( 'status', 'type', 'payment_url', 'data' ) as $field ) {
			$out['fields'][ $field ] = array_key_exists( $field, $data ) ? self::value_shape( $data[ $field ] ) : 'missing';
		}
		$out['status'] = ! array_key_exists( 'status', $data ) ? 'missing' : ( in_array( $data['status'], array( 'SUCCEEDED', 'FAILED' ), true ) ? $data['status'] : 'other' );
		$out['type'] = ! array_key_exists( 'type', $data ) ? 'missing' : ( in_array( $data['type'], array( 'iframe', 'redirect' ), true ) ? $data['type'] : 'other' );
		if ( array_key_exists( 'payment_url', $data ) ) {
			$out['payment_url_host'] = self::trusted_diagnostic_host( $data['payment_url'], $config );
		}
		return $out;
	}

	private static function trusted_diagnostic_host( $url, array $config ): string {
		if ( ! is_string( $url ) || strlen( $url ) > 8192 ) { return 'untrusted'; }
		$parts = parse_url( $url );
		$host = is_array( $parts ) && is_string( $parts['host'] ?? null ) ? strtolower( $parts['host'] ) : '';
		if ( 'https' !== ( $parts['scheme'] ?? null ) || isset( $parts['user'] ) || isset( $parts['pass'] )
			|| ( isset( $parts['port'] ) && 443 !== $parts['port'] ) || strlen( $host ) > 253
			|| ! preg_match( '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host ) ) { return 'untrusted'; }
		$trusted = $config['payment_hosts'] ?? array();
		$gateway_host = parse_url( $config['gateway'] ?? '', PHP_URL_HOST );
		if ( is_string( $gateway_host ) ) { $trusted[] = strtolower( $gateway_host ); }
		return in_array( $host, $trusted, true ) ? $host : 'untrusted';
	}
}
