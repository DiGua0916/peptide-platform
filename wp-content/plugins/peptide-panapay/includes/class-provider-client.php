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
		$response = wp_safe_remote_post( $endpoint, array(
			'timeout' => 30, 'redirection' => 0, 'sslverify' => true, 'reject_unsafe_urls' => true,
			'limit_response_size' => 65536,
			'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
			'body' => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array( 'state' => 'unknown' );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || strlen( $body ) > 65536 ) { return array( 'state' => 'unknown' ); }
		try { $data = json_decode( $body, true, 16, JSON_THROW_ON_ERROR ); }
		catch ( \Throwable $error ) { return array( 'state' => 'unknown' ); }
		if ( ! is_array( $data ) ) { return array( 'state' => 'unknown' ); }
		if ( 'FAILED' === ( $data['status'] ?? null ) ) { return array( 'state' => 'failed' ); }
		if ( 'SUCCEEDED' !== ( $data['status'] ?? null ) || ! in_array( $data['type'] ?? null, array( 'iframe', 'redirect' ), true )
			|| ! is_string( $data['payment_url'] ?? null ) || '' === $data['payment_url'] || strlen( $data['payment_url'] ) > 8192 ) {
			return array( 'state' => 'unknown' );
		}
		return array( 'state' => 'created', 'type' => $data['type'], 'url' => $data['payment_url'] );
	}
}
