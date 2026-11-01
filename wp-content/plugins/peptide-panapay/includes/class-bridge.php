<?php
/** Authenticated store adapter. Provider credentials belong only to HQ. */
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Bridge {
	private const CACHE_TTL = 60;

	public static function register_hooks(): void {
		if ( defined( 'PEPTIDE_HQ_URL' ) && is_string( PEPTIDE_HQ_URL ) && '' !== trim( PEPTIDE_HQ_URL ) ) {
			add_option( 'pc_panapay_central_managed', 'yes', '', false );
		}
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/** A configured HQ never silently falls back to a local merchant. */
	public static function enabled(): bool {
		return ! defined( 'PEPTIDE_HQ_FILE' ) && ( ( defined( 'PEPTIDE_HQ_URL' ) && is_string( PEPTIDE_HQ_URL ) && '' !== trim( PEPTIDE_HQ_URL ) )
			|| 'yes' === get_option( 'pc_panapay_central_managed', 'no' ) );
	}

	private static function identity(): array {
		if ( ! self::enabled() || ! defined( 'PEPTIDE_HQ_URL' ) || ! is_string( PEPTIDE_HQ_URL ) || '' === trim( PEPTIDE_HQ_URL )
			|| ! class_exists( '\PeptideCore\Hq_Connector' ) ) { throw new Protocol_Error( 'hub_not_configured', 503 ); }
		$s = \PeptideCore\Hq_Connector::settings();
		$key = $s['key'] ?? null;
		$uuid = $s['uuid'] ?? null;
		if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{64}$/D', $key ) || ! is_string( $uuid )
			|| ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $uuid ) ) {
			throw new Protocol_Error( 'hub_store_not_registered', 503 );
		}
		return array( 'url' => Hub_Protocol::base_url( PEPTIDE_HQ_URL ), 'key' => $key, 'uuid' => $uuid );
	}

	public static function hq_url(): string { return self::identity()['url']; }
	public static function uuid(): string { return self::identity()['uuid']; }

	/** Explicit public policy only; no merchant configuration is requested or cached. */
	private static function methods_policy( array $identity ): array {
		$cache = 'pc_pay_methods_v1_' . substr( hash( 'sha256', $identity['url'] . '|' . $identity['uuid'] . '|' . $identity['key'] ), 0, 32 );
		$data = get_transient( $cache );
		if ( false === $data ) {
			// Deploy HQ first. Authentication, network or missing-route errors never
			// fall back to a different merchant or a singleton configuration.
			$data = Hub_Protocol::request( $identity['url'], $identity['key'], $identity['uuid'], Hub_Protocol::METHODS, array(), 5 );
			$data = self::validate_methods( $data );
			set_transient( $cache, $data, self::CACHE_TTL );
		}
		return self::validate_methods( $data );
	}

	private static function valid_account_id( $value ): bool {
		return is_string( $value ) && ( 'default' === $value || 1 === preg_match( '/^[a-f0-9]{32}$/D', $value ) );
	}

	private static function validate_methods( $data ): array {
		if ( ! is_array( $data ) || count( $data ) !== 2 || array_diff( array_keys( $data ), array( 'version', 'methods' ) )
			|| 1 !== ( $data['version'] ?? null ) || ! is_array( $data['methods'] ?? null ) || count( $data['methods'] ) > 20
			|| ( $data['methods'] && array_keys( $data['methods'] ) !== range( 0, count( $data['methods'] ) - 1 ) ) ) {
			throw new Protocol_Error( 'hub_policy_invalid', 503 );
		}
		$ids = array(); $methods = array();
		foreach ( $data['methods'] as $method ) {
			if ( ! is_array( $method ) || count( $method ) !== 8 || ! self::valid_account_id( $method['account_id'] ?? null )
				|| in_array( $method['account_id'], $ids, true ) || ! array_key_exists( 'payment_icons', $method ) || ! array_key_exists( 'iframe_height', $method ) ) {
				throw new Protocol_Error( 'hub_policy_invalid', 503 );
			}
			$id = $method['account_id']; unset( $method['account_id'] );
			$method = self::validate_policy( $method ); $method = array_merge( array( 'account_id' => $id ), $method );
			$ids[] = $id; $methods[] = $method;
		}
		return array( 'version' => 1, 'methods' => $methods );
	}

	private static function validate_policy( $data ): array {
		if ( ! is_array( $data ) || array_diff( array_keys( $data ), array( 'enabled', 'title', 'description', 'test_mode', 'payment_hosts', 'payment_icons', 'iframe_height' ) )
			|| ! is_bool( $data['enabled'] ?? null ) || ! is_bool( $data['test_mode'] ?? null )
			|| ! is_string( $data['title'] ?? null ) || '' === trim( $data['title'] ) || mb_strlen( $data['title'] ) > 120
			|| ! is_string( $data['description'] ?? null ) || mb_strlen( $data['description'] ) > 1000
			|| ! is_array( $data['payment_hosts'] ?? null ) || count( $data['payment_hosts'] ) > 32 ) {
			throw new Protocol_Error( 'hub_policy_invalid', 503 );
		}
		foreach ( $data['payment_hosts'] as $host ) {
			if ( ! is_string( $host ) || strlen( $host ) > 253
				|| ! preg_match( '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host ) ) {
				throw new Protocol_Error( 'hub_policy_invalid', 503 );
			}
		}
		// Accept the previous five-field policy during a rolling upgrade. A partially
		// upgraded shape, unknown field or malformed presentation value is rejected.
		if ( array_key_exists( 'payment_icons', $data ) !== array_key_exists( 'iframe_height', $data ) ) {
			throw new Protocol_Error( 'hub_policy_invalid', 503 );
		}
		if ( ! array_key_exists( 'payment_icons', $data ) ) { $data['payment_icons'] = array(); $data['iframe_height'] = 530; }
		if ( ! is_array( $data['payment_icons'] ) || count( $data['payment_icons'] ) > 6
			|| ! is_int( $data['iframe_height'] ) || $data['iframe_height'] < 320 || $data['iframe_height'] > 1600 ) {
			throw new Protocol_Error( 'hub_policy_invalid', 503 );
		}
		$allowed = array_keys( Payment_Icons::catalog() );
		$icons = array();
		foreach ( $data['payment_icons'] as $id ) {
			if ( ! is_string( $id ) || ! in_array( $id, $allowed, true ) ) { throw new Protocol_Error( 'hub_policy_invalid', 503 ); }
			if ( ! in_array( $id, $icons, true ) ) { $icons[] = $id; }
		}
		$data['payment_icons'] = $icons;
		return $data;
	}

	public static function configurations(): array {
		$identity = self::identity();
		$out = array();
		foreach ( self::methods_policy( $identity )['methods'] as $policy ) {
			$id = $policy['account_id'];
			$out[ $id ] = array(
				'account_id' => $id,
				'central' => true, 'enabled' => $policy['enabled'], 'title' => $policy['title'], 'description' => $policy['description'],
				'test_mode' => $policy['test_mode'], 'payment_hosts' => $policy['payment_hosts'],
				'payment_icons' => $policy['payment_icons'], 'iframe_height' => $policy['iframe_height'],
				'gateway' => '', 'mchid' => 'hub-' . $identity['uuid'] . ( 'default' === $id ? '' : '-' . $id ), 'tag' => '', 'mode' => '2',
				// A domain-separated store event key, never the payment provider's secret.
				'secret' => Hub_Protocol::event_key( $identity['key'] ),
			);
		}
		return $out;
	}

	public static function configuration( string $account_id = 'default' ): array {
		if ( ! self::valid_account_id( $account_id ) ) { throw new Protocol_Error( 'payment_method_unavailable', 503 ); }
		$configs = self::configurations();
		if ( ! isset( $configs[ $account_id ] ) ) { throw new Protocol_Error( 'payment_method_unavailable', 503 ); }
		return $configs[ $account_id ];
	}

	public static function create( array $payload, ?string $expected_event_key = null, string $account_id = 'default' ): array {
		if ( ! self::valid_account_id( $account_id ) ) { throw new Protocol_Error( 'payment_method_unavailable', 503 ); }
		$identity = self::identity();
		if ( null !== $expected_event_key && ! hash_equals( $expected_event_key, Hub_Protocol::event_key( $identity['key'] ) ) ) {
			// No HTTP has happened for this new reference. A connection-key rotation
			// must not create a session whose future inner signature this attempt cannot verify.
			return array( 'state' => 'failed' );
		}
		unset( $payload['mchid'], $payload['tag'], $payload['mode'], $payload['sign'] );
		$payload['account_id'] = $account_id;
		try {
			return self::session( Hub_Protocol::request( $identity['url'], $identity['key'], $identity['uuid'], Hub_Protocol::CREATE, $payload, 45 ), $account_id );
		} catch ( \Throwable $error ) {
			// An ambiguous transport result cannot authorize another provider creation.
			return array( 'state' => 'unknown' );
		}
	}

	/** This route only reads a previously reserved HQ session. It never creates one. */
	public static function recover( string $reference, string $account_id = 'default' ): array {
		if ( ! self::valid_account_id( $account_id ) ) { throw new Protocol_Error( 'payment_method_unavailable', 503 ); }
		$identity = self::identity();
		try {
			return self::session( Hub_Protocol::request( $identity['url'], $identity['key'], $identity['uuid'], Hub_Protocol::STATUS, array( 'reference' => $reference ), 10 ), $account_id );
		} catch ( \Throwable $error ) { return array( 'state' => 'unknown' ); }
	}

	private static function session( array $data, string $account_id ): array {
		if ( ( $data['account_id'] ?? 'default' ) !== $account_id
			|| ! in_array( $data['state'] ?? null, array( 'creating', 'created', 'unknown', 'failed' ), true ) ) {
			throw new Protocol_Error( 'hub_session_invalid', 503 );
		}
		if ( 'created' !== $data['state'] ) { return array( 'state' => 'failed' === $data['state'] ? 'failed' : 'unknown' ); }
		if ( ! in_array( $data['type'] ?? null, array( 'iframe', 'redirect' ), true )
			|| ! is_string( $data['url'] ?? null ) || '' === $data['url'] || strlen( $data['url'] ) > 8192 ) {
			throw new Protocol_Error( 'hub_session_invalid', 503 );
		}
		return array( 'state' => 'created', 'type' => $data['type'], 'url' => $data['url'] );
	}

	public static function routes(): void {
		register_rest_route( Hub_Protocol::NS, '/store/event', array(
			'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( self::class, 'receive' ),
		) );
	}

	public static function receive( \WP_REST_Request $request ) {
		try {
			$identity = self::identity();
			$auth = Hub_Protocol::verify_request( $request, $identity['key'], $identity['uuid'] );
			$data = $request->get_json_params();
			if ( ! is_array( $data ) || ! is_string( $data['event_id'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['event_id'] ) ) {
				throw new Protocol_Error( 'hub_event_invalid' );
			}
			Session_Service::apply_notification( $data, true );
			$disposition = Session_Service::notification_disposition( $data );
			return Hub_Protocol::response( array( 'accepted' => true, 'disposition' => $disposition ), $auth['nonce'], $identity['key'] );
		} catch ( \Throwable $error ) {
			$code = $error instanceof Protocol_Error ? $error->getCode() : 503;
			return new \WP_Error( 'pc_pay_event_rejected', 'Payment event was not accepted.', array(
				'status' => in_array( $code, array( 400, 401, 403, 409, 413, 503 ), true ) ? $code : 503,
			) );
		}
	}
}
