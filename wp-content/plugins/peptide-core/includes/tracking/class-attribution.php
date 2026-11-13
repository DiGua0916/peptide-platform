<?php
/**
 * Optional attribution v1: pure normalization, then event-bound immutable capture.
 *
 * CR 素材归因线 1（2026-11-11）：参数白名单放宽到 Contract::PARAMS。utm_source / utm_medium 仍按原规则严格校验
 * （不合格整条拒收）；其余广告参数（campaign / adset / ad 的 ID、placement、ad_name）是可选的，不合格的只丢掉那一项。
 * 另外可选带 has_fbclid（只报有没有，不报值）、timezone、language（浏览器端采集，校验在 Client_Environment）。
 * 入口快照仍然“每次访问第一页记一次、之后不改”：capture() 用同一条 compare-and-set 一起写 v5 新列。
 */
namespace PeptideCore\Tracking;

use PeptideCore\Creatives\Contract;

defined( 'ABSPATH' ) || exit;

final class Attribution {

	/** 严格校验的两项：不合格整条拒收（和放宽前一样）。 */
	private const STRICT = array( 'utm_source', 'utm_medium' );

	/** 可选广告参数的最长字符数（和访问表列宽一致）。 */
	private const EXTRA_MAX = array( 'utm_campaign' => 120, 'utm_term' => 120, 'utm_content' => 120, 'placement' => 64, 'ad_name' => 255, 'cr' => 16 );

	/** v5 访问表里由 capture() 写入的列（来自 Visit_Facts::columns()）。 */
	public const FACT_COLUMNS = array( 'utm_campaign', 'utm_term', 'utm_content', 'placement', 'ad_name', 'creative_code', 'has_fbclid', 'source_class', 'params_valid', 'ip', 'geo_region', 'geo_city', 'geo_lat', 'geo_lon', 'isp', 'asn', 'ip_type', 'timezone', 'language', 'in_app', 'ua', 'os', 'os_version', 'device_model', 'geo_country', 'screen' );

	/** 白名单的固定顺序（= Contract::PARAMS）。 */
	public static function params(): array {
		return Contract::PARAMS;
	}

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
		foreach ( self::params() as $key ) {
			if ( ! array_key_exists( $key, $query ) ) { continue; }
			$value  = $query[ $key ];
			$strict = in_array( $key, self::STRICT, true );
			$max    = $strict ? 120 : self::EXTRA_MAX[ $key ];
			if ( ! is_string( $value ) || ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x1f\x7f]/', $value ) || preg_match_all( '/./us', $value ) > $max || ( ! $strict && '' === $value ) ) {
				if ( $strict ) { return $bad; }
				continue; // 可选参数不合格：只丢这一项。
			}
			$allowed[ $key ] = $value;
		}
		$value = array( 'version' => 1, 'host' => $host, 'landing_query' => $allowed, 'referrer_host' => null === $ref || '' === $ref ? $ref : self::host( $ref ) );
		// 可选的浏览器事实：没带或不合格就不出现，旧客户端的快照保持原样。
		if ( true === ( $input['has_fbclid'] ?? null ) ) { $value['has_fbclid'] = true; }
		foreach ( array( 'timezone', 'language' ) as $key ) {
			$fact = array_key_exists( $key, $input ) ? ( 'timezone' === $key ? Client_Environment::timezone( $input[ $key ] ) : Client_Environment::language( $input[ $key ] ) ) : '';
			if ( '' !== $fact ) { $value[ $key ] = $fact; }
		}
		return array( 'ok' => true, 'value' => $value );
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
		foreach ( self::params() as $key ) {
			if ( array_key_exists( $key, $normalized['landing_query'] ) ) {
				$out[] = $key . '=' . rawurlencode( $normalized['landing_query'][ $key ] );
			}
		}
		return implode( '&', $out );
	}

	/** v5 新列的 SET 片段（只写 FACT_COLUMNS 里的列；整数列按整数，utm 类可空列按 NULL）。 */
	private static function fact_sql( array $facts, callable $nullable ): string {
		global $wpdb;
		$sql = '';
		foreach ( self::FACT_COLUMNS as $column ) {
			if ( ! array_key_exists( $column, $facts ) ) { continue; }
			$value = $facts[ $column ];
			if ( in_array( $column, array( 'has_fbclid', 'params_valid', 'asn' ), true ) ) {
				$sql .= ', ' . $column . ' = ' . $wpdb->prepare( '%d', (int) $value );
			} elseif ( in_array( $column, array( 'utm_campaign', 'utm_term', 'utm_content', 'placement', 'ad_name' ), true ) ) {
				$sql .= ', ' . $column . ' = ' . $nullable( null === $value ? null : (string) $value );
			} else {
				$sql .= ', ' . $column . ' = ' . $wpdb->prepare( '%s', (string) $value );
			}
		}
		return $sql;
	}

	public static function receipt( string $id, string $status, string $code, bool $retry = false ): array {
		return array( 'event_id' => $id, 'status' => $status, 'code' => $code, 'retry' => $retry );
	}

	/** Exact saved event is authoritative, including its original visit after rollover. */
	/**
	 * @param array<string,mixed> $facts v5 新列（Visit_Facts::columns()）；空数组 = 只写原来的快照列。
	 */
	public static function capture( int $visitor, array $event, array $metadata, array $facts = array() ): array {
		global $wpdb;
		$id    = (string) $event['event_uid'];
		$visit = (int) $event['visit_id'];
		if ( $visit <= 0 || (int) $event['visitor_id'] !== $visitor ) { return self::receipt( $id, 'rejected', 'original_visit_missing' ); }
		$nullable = static function ( $value ) use ( $wpdb ): string { return null === $value ? 'NULL' : $wpdb->prepare( '%s', $value ); };
		$query = self::query( $metadata );
		$sql = 'UPDATE ' . Schema::table( 'visits' ) . ' SET attribution_version = 1, ' . $wpdb->prepare( 'attribution_event_uid = %s, attribution_ms = %d, attribution_path = %s, attribution_host = %s, attribution_query = %s', $id, (int) $event['received_ms'], (string) $event['path'], $metadata['host'], $query ) . ', utm_source = ' . $nullable( $metadata['landing_query']['utm_source'] ?? null ) . ', utm_medium = ' . $nullable( $metadata['landing_query']['utm_medium'] ?? null ) . ', referrer_host = ' . $nullable( $metadata['referrer_host'] ) . self::fact_sql( $facts, $nullable ) . $wpdb->prepare( ' WHERE id = %d AND visitor_id = %d AND attribution_version = 0', $visit, $visitor );
		$changed = $wpdb->query( $sql ); // All snapshot fields are claimed by one atomic compare-and-set.
		if ( false === $changed ) { return self::receipt( $id, 'retry', 'server_error', true ); }
		if ( 1 === $changed ) {
			// M6 另存一份到 pc_track_ads：只在 M6 开关不是 off 时写（Ad_Params::writing()，默认关），存不上不影响归因。
			// 上面那条 UPDATE 已经把同样三项写进访问表的新列；两份数据最终用哪一份还没定。
			if ( class_exists( Ad_Params::class ) ) {
				try { Ad_Params::store( $visit, (array) ( $metadata['landing_query'] ?? array() ), (int) $event['received_ms'] ); } catch ( \Throwable $e ) { unset( $e ); }
			}
			return self::receipt( $id, 'applied', 'captured' );
		}
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
