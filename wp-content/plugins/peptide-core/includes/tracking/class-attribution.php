<?php
/** Optional attribution v1: pure normalization, then event-bound immutable capture. */
namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Attribution {

	public const COLUMNS = array( 'attribution_version', 'attribution_event_uid', 'attribution_ms', 'attribution_path', 'attribution_host', 'attribution_query', 'utm_source', 'utm_medium', 'referrer_host' );

	/** No decoding, truncation, WordPress calls, persistence or network in this cleaner. */
	public static function clean( $input ): array {
		$bad = array( 'ok' => false, 'code' => 'bad_attribution' );
		if ( ! is_array( $input ) || ! array_key_exists( 'version', $input ) || ! is_int( $input['version'] ) ) {
			return $bad;
		}
		if ( 1 !== $input['version'] ) {
			return array( 'ok' => false, 'code' => 'unsupported_version' );
		}
		foreach ( array( 'host', 'landing_query', 'referrer_host' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) { return $bad; }
		}
		$host = self::host( $input['host'] );
		$ref  = $input['referrer_host'];
		if ( null === $host || ( null !== $ref && '' !== $ref && null === self::host( $ref ) ) ) {
			return $bad;
		}
		$query = $input['landing_query'];
		if ( ! is_array( $query ) || ( $query && array_is_list( $query ) ) ) { return $bad; }
		$allowed = array();
		foreach ( array( 'utm_source', 'utm_medium' ) as $key ) {
			if ( ! array_key_exists( $key, $query ) ) { continue; }
			$value = $query[ $key ];
			if ( ! is_string( $value ) || ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x1f\x7f]/', $value ) || preg_match_all( '/./us', $value ) > 120 ) { return $bad; }
			$allowed[ $key ] = $value;
		}
		return array( 'ok' => true, 'value' => array( 'version' => 1, 'host' => $host, 'landing_query' => $allowed, 'referrer_host' => null === $ref || '' === $ref ? $ref : self::host( $ref ) ) );
	}

	/** ASCII IDNA input only; remove exactly one terminal dot, never resolve DNS. */
	public static function host( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 253 ) { return null; }
		$value = strtolower( $value );
		if ( str_ends_with( $value, '.' ) ) { $value = substr( $value, 0, -1 ); }
		foreach ( explode( '.', $value ) as $label ) {
			if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label ) ) { return null; }
		}
		return $value;
	}

	/** Fixed allowlist order and exactly one RFC3986 encoding pass; case is significant. */
	public static function query( array $normalized ): string {
		$out = array();
		foreach ( array( 'utm_source', 'utm_medium' ) as $key ) {
			if ( array_key_exists( $key, $normalized['landing_query'] ) ) {
				$out[] = $key . '=' . rawurlencode( $normalized['landing_query'][ $key ] );
			}
		}
		return implode( '&', $out );
	}

	public static function receipt( string $id, string $status, string $code, bool $retry = false ): array {
		return array( 'event_id' => $id, 'status' => $status, 'code' => $code, 'retry' => $retry );
	}

	/** Exact saved event is authoritative, including its original visit after rollover. */
	public static function capture( int $visitor, array $event, array $metadata ): array {
		global $wpdb;
		$id    = (string) $event['event_uid'];
		$visit = (int) $event['visit_id'];
		if ( $visit <= 0 || (int) $event['visitor_id'] !== $visitor ) { return self::receipt( $id, 'rejected', 'original_visit_missing' ); }
		$nullable = static function ( $value ) use ( $wpdb ): string { return null === $value ? 'NULL' : $wpdb->prepare( '%s', $value ); };
		$query = self::query( $metadata );
		$sql = 'UPDATE ' . Schema::table( 'visits' ) . ' SET attribution_version = 1, ' . $wpdb->prepare( 'attribution_event_uid = %s, attribution_ms = %d, attribution_path = %s, attribution_host = %s, attribution_query = %s', $id, (int) $event['received_ms'], (string) $event['path'], $metadata['host'], $query ) . ', utm_source = ' . $nullable( $metadata['landing_query']['utm_source'] ?? null ) . ', utm_medium = ' . $nullable( $metadata['landing_query']['utm_medium'] ?? null ) . ', referrer_host = ' . $nullable( $metadata['referrer_host'] ) . $wpdb->prepare( ' WHERE id = %d AND visitor_id = %d AND attribution_version = 0', $visit, $visitor );
		$changed = $wpdb->query( $sql ); // All snapshot fields are claimed by one atomic compare-and-set.
		if ( false === $changed ) { return self::receipt( $id, 'retry', 'server_error', true ); }
		if ( 1 === $changed ) { return self::receipt( $id, 'applied', 'captured' ); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT attribution_version, attribution_event_uid FROM ' . Schema::table( 'visits' ) . ' WHERE id = %d AND visitor_id = %d', $visit, $visitor ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::receipt( $id, 'retry', 'server_error', true ); }
		if ( ! $row ) { return self::receipt( $id, 'rejected', 'original_visit_missing' ); }
		if ( 1 === (int) $row['attribution_version'] ) {
			return self::receipt( $id, $id === $row['attribution_event_uid'] ? 'applied' : 'preserved', $id === $row['attribution_event_uid'] ? 'already_captured' : 'visit_already_captured' );
		}
		return self::receipt( $id, 'retry', 'server_error', true );
	}

	/** Normalized privacy/report projection; v0 is unknown, never inferred direct. */
	public static function snapshot( array $visit ): array {
		if ( 1 !== (int) ( $visit['attribution_version'] ?? 0 ) ) {
			return array( 'status' => 'unknown', 'version' => 0, 'path' => null, 'host' => null, 'query' => null, 'utm_source' => null, 'utm_medium' => null, 'referrer_host' => null );
		}
		return array( 'status' => 'captured', 'version' => 1, 'path' => (string) $visit['attribution_path'], 'host' => (string) $visit['attribution_host'], 'query' => (string) $visit['attribution_query'], 'utm_source' => $visit['utm_source'], 'utm_medium' => $visit['utm_medium'], 'referrer_host' => $visit['referrer_host'] );
	}
}
