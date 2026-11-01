<?php
/** Immutable, source-reported product visibility episodes. No pixel/continuity inference. */
namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Impressions {
	public const KINDS = array( 'home', 'shop', 'product', 'cart', 'checkout', 'account', 'order_received', 'order_pay', 'content', 'collection', 'search', 'unknown' );
	public const MAX_ID = 9007199254740991;

	public static function input_id( $value, bool $zero = false ): bool {
		return is_int( $value ) && $value >= ( $zero ? 0 : 1 ) && $value <= min( PHP_INT_MAX, self::MAX_ID );
	}

	/** SQL drivers return integer columns as strings; never round an unsafe column. */
	public static function column_id( $value, bool $zero = false ): ?int {
		if ( is_string( $value ) ) {
			if ( ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ) { return null; }
			$max = (string) min( PHP_INT_MAX, self::MAX_ID );
			if ( strlen( $value ) > strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) { return null; }
			$value = (int) $value;
		}
		return self::input_id( $value, $zero ) ? $value : null;
	}

	/** Pure syntax only. Unknown keys are omitted, supported invalid values reject atomically. */
	public static function clean( $input ): array {
		$bad = array( 'ok' => false, 'code' => 'bad_product_impression' );
		if ( ! is_array( $input ) || ! is_int( $input['version'] ?? null ) ) { return $bad; }
		if ( $input['version'] !== 1 ) { return array( 'ok' => false, 'code' => 'unsupported_impression_version' ); }
		if ( ! self::input_id( $input['product_id'] ?? null ) || ( array_key_exists( 'variation_id', $input ) && ! self::input_id( $input['variation_id'], true ) ) ) { return $bad; }
		$f = $input['visible_fraction'] ?? null; $ms = $input['visible_ms'] ?? null;
		if ( ( ! is_int( $f ) && ! is_float( $f ) ) || ! is_finite( (float) $f ) || $f < 0.4 || $f > 1 || ! is_int( $ms ) || $ms < 500 || $ms > 2147483647 ) { return $bad; }
		$c = $input['context'] ?? null;
		if ( ! is_array( $c ) || ! is_string( $c['kind'] ?? null ) || ! in_array( $c['kind'], self::KINDS, true ) ) { return $bad; }
		$context = array( 'kind' => $c['kind'] );
		foreach ( array( 'entity_id', 'template_id' ) as $key ) {
			if ( array_key_exists( $key, $c ) ) {
				if ( ! self::input_id( $c[$key] ) || ( $key === 'entity_id' && ! in_array( $c['kind'], array( 'product', 'collection' ), true ) ) ) { return $bad; }
				$context[$key] = $c[$key];
			}
		}
		if ( array_key_exists( 'module_id', $c ) ) {
			if ( ! is_string( $c['module_id'] ) || ! preg_match( '/\A[A-Za-z0-9][A-Za-z0-9-]{0,39}\z/', $c['module_id'] ) ) { return $bad; }
			$context['module_id'] = $c['module_id'];
		}
		return array( 'ok' => true, 'value' => array( 'version' => 1, 'product_id' => $input['product_id'], 'variation_id' => $input['variation_id'] ?? 0, 'visible_fraction' => (float) $f, 'visible_ms' => $ms, 'context' => $context ) );
	}

	public static function path( $path ): ?string {
		return is_string( $path ) && strlen( $path ) <= 500 && str_starts_with( $path, '/' ) && preg_match( '//u', $path ) === 1 && ! preg_match( '/[\x00-\x1f\x7f<>?#]/', $path ) && parse_url( $path, PHP_URL_PATH ) === $path ? $path : null;
	}

	/** Shared historical report/export eligibility. JOIN aliases prove both identities, not catalog state. */
	public static function saved( array $row ): ?array {
		if ( ( $row['type'] ?? '' ) !== 'product_impression' || ( $row['source'] ?? '' ) !== 'client' || self::path( $row['path'] ?? null ) === null ) { return null; }
		$v = self::column_id( $row['visitor_id'] ?? null ); $s = self::column_id( $row['visit_id'] ?? null );
		if ( ! $v || ! $s || self::column_id( $row['matched_visitor_id'] ?? null ) !== $v || self::column_id( $row['matched_visit_id'] ?? null ) !== $s ) { return null; }
		$clean = self::clean( is_array( $row['data'] ?? null ) ? $row['data'] : json_decode( (string) ( $row['data'] ?? '' ), true ) );
		if ( ! $clean['ok'] || self::column_id( $row['product_id'] ?? null ) !== $clean['value']['product_id'] || self::column_id( $row['variation_id'] ?? null, true ) !== $clean['value']['variation_id'] ) { return null; }
		return $clean['value'];
	}

	/** Frozen core comparison deliberately ignores re-clocked times and page product/name extras. */
	public static function equal( int $visitor, array $incoming, array $row ): bool {
		$fixed = array( 'event_uid' => $incoming['event_id'], 'visitor_id' => $visitor, 'type' => 'product_impression', 'source' => 'client', 'path' => $incoming['page']['path'], 'page_type' => $incoming['page']['page_type'], 'product_id' => $incoming['data']['product_id'], 'variation_id' => $incoming['data']['variation_id'], 'page_instance_id' => $incoming['page_instance_id'], 'tab_id' => $incoming['tab_id'], 'seq' => $incoming['seq'] );
		foreach ( $fixed as $key => $value ) {
			if ( is_int( $value ) ? self::column_id( $row[$key] ?? null, true ) !== $value : ( $row[$key] ?? null ) !== $value ) { return false; }
		}
		$saved = self::clean( json_decode( (string) ( $row['data'] ?? '' ), true ) );
		return $saved['ok'] && $saved['value'] === $incoming['data'];
	}

	/** One bounded/coalesced read, including original visitor/visit presence. */
	public static function winners( int $visitor, array $uids ): ?array {
		global $wpdb;
		if ( ! $visitor || ! $uids ) { return array(); }
		$uids = array_values( array_unique( $uids ) );
		$e = Schema::table( 'events' ); $v = Schema::table( 'visitors' ); $s = Schema::table( 'visits' );
		$sql = "SELECT e.*, v.id AS matched_visitor_id, s.id AS matched_visit_id FROM {$e} e LEFT JOIN {$v} v ON v.id=e.visitor_id LEFT JOIN {$s} s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id WHERE e.visitor_id=%d AND e.event_uid IN (" . implode( ',', array_fill( 0, count( $uids ), '%s' ) ) . ')';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $visitor ), $uids ) ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) { return null; }
		$out = array(); foreach ( $rows as $row ) { $out[$row['event_uid']] = $row; } return $out;
	}

	/** Native published parent/variation relationship, one query for all true-new candidates. */
	public static function catalog( array $events ): ?array {
		global $wpdb;
		$ids = array(); foreach ( $events as $c ) { $ids[$c['data']['product_id']] = true; if ( $c['data']['variation_id'] ) { $ids[$c['data']['variation_id']] = true; } }
		if ( ! $ids ) { return array(); }
		$rows = $wpdb->get_results( 'SELECT ID,post_type,post_status,post_parent FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',', array_keys( $ids ) ) . ')', ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) { return null; }
		$native = array(); foreach ( $rows as $row ) { $native[(int) $row['ID']] = $row; }
		$out = array(); foreach ( $events as $c ) {
			$d = $c['data']; $p = $native[$d['product_id']] ?? null; $v = $native[$d['variation_id']] ?? null;
			$out[$c['index']] = $p && $p['post_type'] === 'product' && $p['post_status'] === 'publish' && ( ! $d['variation_id'] || ( $v && $v['post_type'] === 'product_variation' && $v['post_status'] === 'publish' && (int) $v['post_parent'] === $d['product_id'] ) );
		}
		return $out;
	}

	public static function replay_code( int $visitor, array $incoming, array $row ): string {
		if ( ! self::equal( $visitor, $incoming, $row ) ) { return 'duplicate_conflict'; }
		return self::saved( $row ) === null ? 'original_visit_missing' : 'ack';
	}

	/** Only signal fields; existing page/video/ordering/name metadata is never overwritten. */
	public static function signal( int $visitor, int $visit, string $tab, int $now ): void {
		global $wpdb; $table = Schema::table( 'tabs' );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (visitor_id,visit_id,tab_id,last_signal_ms) VALUES (%d,%d,%s,%d)", $visitor, $visit, $tab, $now ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET visit_id=%d,last_signal_ms=GREATEST(last_signal_ms,%d) WHERE visitor_id=%d AND tab_id=%s", $visit, $now, $visitor, $tab ) );
	}
}
