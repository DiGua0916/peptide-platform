<?php
/** Authenticated read-only binding of an opaque reference to an existing original client PV. */
namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Experiment_Bindings {
	public static function register_routes(): void {
		register_rest_route( 'peptide/v1', '/data/experiments/binding', array( 'methods' => 'POST', 'callback' => array( self::class, 'receive' ), 'permission_callback' => array( Experiment_Observations::class, 'permission' ) ) );
	}

	/** Same native PV identity syntax as Ingest::ID_PATTERN. */
	private static function pv_id( $value ): bool { return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9-]{8,64}\z/D', $value ); }

	/** No database IDs or source extras are accepted by this lookup. */
	public static function clean( $body ): ?array {
		if ( ! is_array( $body ) || ( $body['protocol'] ?? null ) !== 1 || count( $body ) !== 2 || ! array_key_exists( 'page_view', $body ) ) { return null; }
		$pv = $body['page_view'];
		if ( ! is_array( $pv ) || count( $pv ) !== 3 || ! self::pv_id( $pv['event_id'] ?? null ) || ! self::pv_id( $pv['page_instance_id'] ?? null ) || Impressions::path( $pv['path'] ?? null ) === null ) { return null; }
		return array( 'event_id' => $pv['event_id'], 'page_instance_id' => $pv['page_instance_id'], 'path' => $pv['path'] );
	}

	private static function error( string $code, string $message, int $status ): \WP_Error { return new \WP_Error( $code, $message, array( 'status' => $status ) ); }

	private static function binding( array $row, array $pv ): ?array {
		if ( ( $row['type'] ?? null ) !== 'page_view' || ( $row['source'] ?? null ) !== 'client' ) { return null; }
		foreach ( array( 'is_test', 'visitor_test', 'visit_test' ) as $key ) { if ( Impressions::column_id( $row[$key] ?? null, true ) !== 0 ) { return null; } }
		$v = Impressions::column_id( $row['visitor_id'] ?? null ); $s = Impressions::column_id( $row['visit_id'] ?? null );
		$at = Impressions::column_id( $row['occurred_ms'] ?? null ); $start = Impressions::column_id( $row['visit_start'] ?? null );
		if ( ! $v || ! $s || ! $at || ! $start || $at < $start || Impressions::column_id( $row['matched_visitor_id'] ?? null ) !== $v || Impressions::column_id( $row['matched_visit_id'] ?? null ) !== $s || Impressions::column_id( $row['visit_visitor_id'] ?? null ) !== $v ) { return null; }
		if ( ( $row['event_uid'] ?? null ) !== $pv['event_id'] || ( $row['page_instance_id'] ?? null ) !== $pv['page_instance_id'] || ( $row['path'] ?? null ) !== $pv['path'] || ! self::pv_id( $row['event_uid'] ) || ! self::pv_id( $row['page_instance_id'] ) || Impressions::path( $row['path'] ) === null ) { return null; }
		return array( 'visitor_id' => $v, 'visit_id' => $s, 'page_view_event_id' => $pv['event_id'], 'page_instance_id' => $pv['page_instance_id'], 'path' => $pv['path'], 'page_view_occurred_ms' => $at );
	}

	public static function receive( \WP_REST_Request $request ) {
		$permission = Experiment_Observations::permission(); if ( true !== $permission ) { return $permission; }
		$raw = $request->get_body();
		if ( strlen( $raw ) > 8192 ) { return self::error( 'pc_experiment_binding_body', __( 'Experiment binding request is too large.', 'peptide-core' ), 413 ); }
		$pv = self::clean( json_decode( $raw, true ) );
		if ( ! $pv ) { return self::error( 'pc_experiment_binding_body', __( 'Invalid experiment page-view reference.', 'peptide-core' ), 400 ); }
		$now = (int) floor( microtime( true ) * 1000 ); $key = 'pc_exp_binding_rate_' . get_current_user_id(); $rate = get_transient( $key );
		if ( ! is_array( $rate ) || (int) ( $rate['until'] ?? 0 ) <= $now ) { $rate = array( 'until' => $now + 300000, 'count' => 0 ); }
		if ( (int) ( $rate['count'] ?? 0 ) >= 300 ) { return self::error( 'pc_experiment_binding_rate', __( 'Experiment binding limit reached. Retry later.', 'peptide-core' ), 429 ); }
		$rate['count'] = (int) ( $rate['count'] ?? 0 ) + 1; set_transient( $key, $rate, 300 );
		try {
			if ( ! Schema::installed() ) { throw new \RuntimeException( 'Tracking storage unavailable.' ); }
			global $wpdb; $e = Schema::table( 'events' ); $v = Schema::table( 'visitors' ); $s = Schema::table( 'visits' );
			$sql = "SELECT e.visitor_id,e.visit_id,e.event_uid,e.page_instance_id,e.path,e.occurred_ms,e.type,e.source,e.is_test,v.id AS matched_visitor_id,s.id AS matched_visit_id,s.visitor_id AS visit_visitor_id,v.is_test AS visitor_test,s.is_test AS visit_test,s.start_ms AS visit_start FROM {$e} e INNER JOIN {$v} v ON v.id=e.visitor_id INNER JOIN {$s} s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id WHERE BINARY e.event_uid=%s AND BINARY e.page_instance_id=%s AND BINARY e.path=%s AND e.type='page_view' AND e.source='client' AND e.is_test=0 AND v.is_test=0 AND s.is_test=0 LIMIT 2";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $pv['event_id'], $pv['page_instance_id'], $pv['path'] ), ARRAY_A );
			if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new \RuntimeException( 'Binding query unavailable.' ); }
			if ( ! $rows ) { return self::error( 'pc_experiment_binding_missing', __( 'The original page view was not found.', 'peptide-core' ), 404 ); }
			if ( count( $rows ) !== 1 ) { return self::error( 'pc_experiment_binding_ambiguous', __( 'The page-view reference is ambiguous.', 'peptide-core' ), 409 ); }
			$binding = self::binding( $rows[0], $pv ); if ( ! $binding ) { throw new \RuntimeException( 'Invalid stored binding.' ); }
			$response = new \WP_REST_Response( array( 'protocol' => 1, 'binding' => $binding ) ); $response->header( 'Cache-Control', 'no-store' ); return $response;
		} catch ( \Throwable $error ) { return self::error( 'pc_experiment_binding_unavailable', __( 'Experiment bindings are unavailable.', 'peptide-core' ), 503 ); }
	}
}
