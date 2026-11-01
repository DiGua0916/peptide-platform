<?php
/** Authenticated external assignment observations. No routing, visitor creation or activity mutation. */
namespace PeptideCore\Tracking;
defined( 'ABSPATH' ) || exit;

final class Experiment_Observations {
	public static function permission() {
		return get_current_user_id() > 0 && current_user_can( 'manage_woocommerce' ) ? true : new \WP_Error( 'pc_forbidden', __( 'Experiment observations require an authenticated store manager.', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) );
	}
	public static function register_hooks(): void {
		Experiment_Bindings::register_hooks();
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_action( 'admin_init', array( self::class, 'policy' ) );
	}
	public static function opaque( $v, int $min = 8, int $max = 64 ): bool { return is_string( $v ) && strlen( $v ) >= $min && strlen( $v ) <= $max && preg_match( '/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', $v ); }
	/** Only this canonical payload survives; unknown source fields are discarded. */
	public static function clean( $input ): ?array {
		if ( ! is_array( $input ) || ! self::opaque( $input['event_id'] ?? null ) || ! self::opaque( $input['page_instance_id'] ?? null ) ) { return null; }
		foreach ( array( 'visitor_id', 'visit_id', 'occurred_ms' ) as $key ) { if ( ! Impressions::input_id( $input[$key] ?? null ) ) { return null; } }
		$d = $input['data'] ?? null;
		if ( ! is_array( $d ) || ( $d['version'] ?? null ) !== 1 || ! self::opaque( $d['experiment_id'] ?? null, 1, 40 ) || ! self::opaque( $d['variant_id'] ?? null, 1, 40 ) || Impressions::path( $d['landing_path'] ?? null ) === null ) { return null; }
		return array( 'event_id' => $input['event_id'], 'visitor_id' => $input['visitor_id'], 'visit_id' => $input['visit_id'], 'page_instance_id' => $input['page_instance_id'], 'occurred_ms' => $input['occurred_ms'], 'data' => array( 'version' => 1, 'experiment_id' => $d['experiment_id'], 'variant_id' => $d['variant_id'], 'landing_path' => $d['landing_path'] ) );
	}
	public static function check_db(): void { global $wpdb; if ( $wpdb->last_error ) { throw new \RuntimeException( 'Experiment observations unavailable.' ); } }
	/** Query aliases prove native owner existence and non-test facts, including during privacy export. */
	public static function saved( array $row ): ?array {
		if ( ( $row['type'] ?? '' ) !== 'experiment_exposure' || ( $row['source'] ?? '' ) !== 'server' || (int) ( $row['is_test'] ?? 1 ) !== 0 || (int) ( $row['visitor_test'] ?? 1 ) !== 0 || (int) ( $row['visit_test'] ?? 1 ) !== 0 ) { return null; }
		$v = Impressions::column_id( $row['visitor_id'] ?? null ); $s = Impressions::column_id( $row['visit_id'] ?? null );
		if ( ! $v || ! $s || Impressions::column_id( $row['matched_visitor_id'] ?? null ) !== $v || Impressions::column_id( $row['matched_visit_id'] ?? null ) !== $s ) { return null; }
		foreach ( array( 'product_id', 'variation_id', 'order_id' ) as $key ) { if ( Impressions::column_id( $row[$key] ?? null, true ) !== 0 ) { return null; } }
		$at = Impressions::column_id( $row['occurred_ms'] ?? null ); $start = Impressions::column_id( $row['visit_start'] ?? null );
		if ( ! $at || ! $start || $at < $start ) { return null; }
		$out = self::clean( array( 'event_id' => $row['event_uid'] ?? null, 'visitor_id' => $v, 'visit_id' => $s, 'page_instance_id' => $row['page_instance_id'] ?? null, 'occurred_ms' => $at, 'data' => is_array( $row['data'] ?? null ) ? $row['data'] : json_decode( (string) ( $row['data'] ?? '' ), true ) ) );
		return $out && ( $row['path'] ?? null ) === $out['data']['landing_path'] ? $out : null;
	}
	public static function joined(): string {
		$e = Schema::table( 'events' ); $v = Schema::table( 'visitors' ); $s = Schema::table( 'visits' );
		return "SELECT e.*,v.id AS matched_visitor_id,s.id AS matched_visit_id,v.is_test AS visitor_test,s.is_test AS visit_test,s.start_ms AS visit_start FROM {$e} e LEFT JOIN {$v} v ON v.id=e.visitor_id LEFT JOIN {$s} s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id";
	}
	private static function winner( int $visitor, string $uid ): ?array {
		global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( self::joined() . ' WHERE e.visitor_id=%d AND e.event_uid=%s', $visitor, $uid ), ARRAY_A ); self::check_db(); return $row ?: null;
	}
	private static function replay( array $input, array $winner ): string {
		// Compare original fields before deciding whether the original owner/visit still exists.
		foreach ( array( 'visitor_id', 'visit_id', 'occurred_ms' ) as $key ) { if ( Impressions::column_id( $winner[$key] ?? null ) !== $input[$key] ) { return 'duplicate_conflict'; } }
		$d = json_decode( (string) ( $winner['data'] ?? '' ), true );
		if ( ( $winner['event_uid'] ?? '' ) !== $input['event_id'] || ( $winner['page_instance_id'] ?? '' ) !== $input['page_instance_id'] || ( $winner['path'] ?? '' ) !== $input['data']['landing_path'] || ( $winner['type'] ?? '' ) !== 'experiment_exposure' || ( $winner['source'] ?? '' ) !== 'server' || $d !== $input['data'] ) { return 'duplicate_conflict'; }
		return self::saved( $winner ) ? 'ack' : 'original_visit_missing';
	}
	public static function receive( \WP_REST_Request $request ) {
		$permission = self::permission(); if ( true !== $permission ) { return $permission; }
		$raw = $request->get_body();
		if ( strlen( $raw ) > 65536 ) { return new \WP_Error( 'pc_experiment_body', __( 'Experiment request is too large.', 'peptide-core' ), array( 'status' => 413 ) ); }
		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) || ( $body['protocol'] ?? null ) !== 1 || ! is_array( $body['events'] ?? null ) || ! array_is_list( $body['events'] ) || count( $body['events'] ) < 1 || count( $body['events'] ) > 20 ) { return new \WP_Error( 'pc_experiment_body', __( 'Invalid experiment observation batch.', 'peptide-core' ), array( 'status' => 400 ) ); }
		$now = (int) floor( microtime( true ) * 1000 ); $key = 'pc_exp_rate_' . get_current_user_id(); $rate = get_transient( $key );
		if ( ! is_array( $rate ) || (int) ( $rate['until'] ?? 0 ) <= $now ) { $rate = array( 'until' => $now + 300000, 'count' => 0 ); }
		if ( (int) $rate['count'] + count( $body['events'] ) > 600 ) { return new \WP_Error( 'pc_experiment_rate', __( 'Experiment observation limit reached. Retry later.', 'peptide-core' ), array( 'status' => 429 ) ); }
		$rate['count'] += count( $body['events'] ); set_transient( $key, $rate, 300 );
		if ( ! Schema::installed() ) { return new \WP_Error( 'pc_experiment_unavailable', __( 'Experiment observations are unavailable.', 'peptide-core' ), array( 'status' => 503 ) ); }
		global $wpdb; $ack = array(); $rejected = array(); $retry = array();
		foreach ( $body['events'] as $index => $raw_event ) {
			$c = self::clean( $raw_event ); if ( ! $c ) { $rejected[] = array( 'index' => $index, 'code' => 'invalid_event' ); continue; }
			try {
				$winner = self::winner( $c['visitor_id'], $c['event_id'] );
				if ( $winner ) { $code = self::replay( $c, $winner ); }
				else {
					$s = Schema::table( 'visits' ); $v = Schema::table( 'visitors' ); $e = Schema::table( 'events' );
					$visit = $wpdb->get_row( $wpdb->prepare( "SELECT s.id,s.start_ms FROM {$s} s INNER JOIN {$v} v ON v.id=s.visitor_id WHERE s.id=%d AND s.visitor_id=%d AND s.is_test=0 AND v.is_test=0", $c['visit_id'], $c['visitor_id'] ), ARRAY_A ); self::check_db();
					if ( ! $visit ) { $code = 'invalid_owner'; }
					elseif ( $c['occurred_ms'] < (int) $visit['start_ms'] || $c['occurred_ms'] > $now || $c['occurred_ms'] < $now - 86400000 ) { $code = 'invalid_time'; }
					else {
						$pv = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$e} WHERE visitor_id=%d AND visit_id=%d AND path=%s AND page_instance_id=%s AND type='page_view' AND source='client' AND is_test=0 AND occurred_ms >= %d AND occurred_ms <= %d LIMIT 1", $c['visitor_id'], $c['visit_id'], $c['data']['landing_path'], $c['page_instance_id'], (int) $visit['start_ms'], $c['occurred_ms'] ) ); self::check_db();
						if ( ! $pv ) { $code = 'page_view_required'; }
						else {
							$written = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$e} (event_uid,visitor_id,visit_id,page_instance_id,type,source,occurred_ms,received_ms,page_type,path,product_id,variation_id,order_id,data,is_test) VALUES (%s,%d,%d,%s,'experiment_exposure','server',%d,%d,'landing',%s,0,0,0,%s,0)", $c['event_id'], $c['visitor_id'], $c['visit_id'], $c['page_instance_id'], $c['occurred_ms'], $now, $c['data']['landing_path'], wp_json_encode( $c['data'] ) ) ); self::check_db();
							if ( false === $written ) { throw new \RuntimeException( 'Insert failed' ); }
							$winner = self::winner( $c['visitor_id'], $c['event_id'] ); if ( ! $winner ) { throw new \RuntimeException( 'No immutable winner' ); } $code = self::replay( $c, $winner );
						}
					}
				}
				if ( 'ack' === $code ) { $ack[] = $c['event_id']; } else { $rejected[] = array( 'index' => $index, 'code' => $code ); }
			} catch ( \Throwable $error ) { $retry[] = $c['event_id']; }
		}
		$response = new \WP_REST_Response( array( 'protocol' => 1, 'ack' => $ack, 'rejected' => $rejected, 'retry' => $retry ) ); $response->header( 'Cache-Control', 'no-store' ); return $response;
	}
	public static function exporters( $items ): array { $items = is_array( $items ) ? $items : array(); $items['pc-experiment-observations'] = array( 'exporter_friendly_name' => __( 'Landing A/B observations', 'peptide-core' ), 'callback' => array( self::class, 'export' ) ); return $items; }
	public static function export( string $email, int $page = 1 ): array {
		global $wpdb; if ( $page < 1 || $page - 1 > intdiv( PHP_INT_MAX, 500 ) || ! Schema::installed() ) { return array( 'data' => array(), 'done' => true ); }
		$ids = Privacy::visitors_for_email( $email ); if ( ! $ids ) { return array( 'data' => array(), 'done' => true ); }
		$ids = array_filter( array_map( array( Impressions::class, 'column_id' ), $ids ) ); if ( ! $ids ) { return array( 'data' => array(), 'done' => true ); }
		$rows = $wpdb->get_results( $wpdb->prepare( self::joined() . " WHERE e.visitor_id IN (" . implode( ',', $ids ) . ") AND e.type='experiment_exposure' AND e.source='server' ORDER BY e.id LIMIT 501 OFFSET %d", ( $page - 1 ) * 500 ), ARRAY_A ); self::check_db(); if ( ! is_array( $rows ) ) { throw new \RuntimeException( 'Experiment export unavailable' ); }
		$out = array(); foreach ( array_slice( $rows, 0, 500 ) as $row ) { $c = self::saved( $row ); if ( ! $c ) { continue; } $fields = array(); foreach ( array( 'experiment_id' => 'Experiment ID', 'variant_id' => 'Variant ID', 'landing_path' => 'Landing path' ) as $key => $label ) { $fields[] = array( 'name' => __( $label, 'peptide-core' ), 'value' => $c['data'][$key] ); } $fields[] = array( 'name' => __( 'Event ID', 'peptide-core' ), 'value' => $c['event_id'] ); $fields[] = array( 'name' => __( 'Page instance ID', 'peptide-core' ), 'value' => $c['page_instance_id'] ); $fields[] = array( 'name' => __( 'Observed at (UTC)', 'peptide-core' ), 'value' => gmdate( 'Y-m-d H:i:s', intdiv( $c['occurred_ms'], 1000 ) ) . ' UTC' ); $out[] = array( 'group_id' => 'pc-experiments', 'group_label' => __( 'Landing A/B observations', 'peptide-core' ), 'item_id' => 'experiment-' . $row['id'], 'data' => $fields ); }
		return array( 'data' => $out, 'done' => count( $rows ) <= 500 );
	}
	public static function policy(): void { if ( function_exists( 'wp_add_privacy_policy_content' ) ) { wp_add_privacy_policy_content( __( 'Landing A/B observations', 'peptide-core' ), '<p>Authenticated store sources may report opaque experiment/variant identifiers and a local landing path for an existing observed visit and page instance. Reports use received exposure cohorts and native linked orders; they do not assign traffic or establish causation. These events follow existing visitor retention and whole-visitor erasure and have a separate email-bound personal-data export. No new IP, contact text, message body or source-provided order amounts are collected.</p>' ); } }
}
