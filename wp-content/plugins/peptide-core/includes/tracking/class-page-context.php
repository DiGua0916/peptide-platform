<?php
/** Optional source-reported page context. Syntax is immutable; catalog checks apply only to new facts. */
namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Page_Context {
	public const KINDS = array( 'home', 'shop', 'product', 'cart', 'checkout', 'account', 'order_received', 'order_pay', 'content', 'collection', 'search', 'unknown' );

	/** Pure, atomic allowlist normalization. No WordPress/database/network calls. */
	public static function clean( $input ): array {
		$bad = array( 'ok' => false, 'code' => 'bad_page_context' );
		if ( ! is_array( $input ) || ! isset( $input['version'] ) || ! is_int( $input['version'] ) ) { return $bad; }
		if ( 1 !== $input['version'] ) { return array( 'ok' => false, 'code' => 'unsupported_version' ); }
		if ( ! is_string( $input['kind'] ?? null ) || ! in_array( $input['kind'], self::KINDS, true ) ) { return $bad; }
		$out = array( 'version' => 1, 'kind' => $input['kind'] );
		if ( 'collection' === $input['kind'] ) {
			if ( ! is_int( $input['term_id'] ?? null ) || $input['term_id'] <= 0 ) { return $bad; }
			$out['term_id'] = $input['term_id'];
		} elseif ( array_key_exists( 'term_id', $input ) ) { return $bad; }
		if ( array_key_exists( 'geo', $input ) ) {
			$geo = $input['geo'];
			if ( ! is_array( $geo ) || ! is_string( $geo['status'] ?? null ) || ! in_array( $geo['status'], array( 'known', 'unknown', 'unavailable' ), true ) ) { return $bad; }
			$out['geo'] = array( 'status' => $geo['status'] );
			if ( 'known' === $geo['status'] ) {
				if ( ! is_string( $geo['country_code'] ?? null ) || 1 !== preg_match( '/\A[A-Za-z]{2}\z/', $geo['country_code'] ) ) { return $bad; }
				$out['geo']['country_code'] = strtoupper( $geo['country_code'] );
			} elseif ( array_key_exists( 'country_code', $geo ) ) { return $bad; }
		}
		return array( 'ok' => true, 'value' => $out );
	}

	/** Saved facts are syntax-only: deleted terms or later catalog changes do not rewrite them. */
	public static function saved( $input ): ?array {
		$clean = self::clean( $input );
		return $clean['ok'] ? $clean['value'] : null;
	}

	/** True-new-UID validation against native product_cat and the global Woo country catalog. */
	public static function valid_new( array $context ): bool {
		if ( 'collection' === $context['kind'] ) {
			$term = get_term( $context['term_id'], 'product_cat' );
			if ( ! $term instanceof \WP_Term || is_wp_error( $term ) ) { return false; }
		}
		if ( isset( $context['geo'] ) && 'known' === $context['geo']['status'] ) {
			if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->countries ) { return false; }
			$countries = WC()->countries->get_countries();
			if ( ! array_key_exists( $context['geo']['country_code'], $countries ) ) { return false; }
		}
		return true;
	}

	public static function receipt( string $id, string $status, string $code, bool $retry = false ): array {
		return array( 'event_id' => preg_match( '/\A[A-Za-z0-9-]{8,64}\z/', $id ) ? $id : '', 'status' => $status, 'code' => $code, 'retry' => $retry );
	}

	/** Compare only the frozen core identity fields, never re-clocked occurrence/receipt time. */
	public static function replay( int $visitor, array $incoming, array $event ): array {
		global $wpdb;
		$id = $incoming['event_id'];
		$data = json_decode( (string) $event['data'], true );
		if ( ! is_array( $data ) || ! array_key_exists( 'page_context', $data ) ) { return self::receipt( $id, 'rejected', 'duplicate_without_context' ); }
		$saved = self::saved( $data['page_context'] );
		$fixed = array( 'event_uid' => $id, 'visitor_id' => $visitor, 'type' => 'page_view', 'source' => 'client', 'path' => $incoming['page']['path'], 'page_type' => $incoming['page']['page_type'], 'product_id' => (int) ( $incoming['data']['product_id'] ?? ( $incoming['page']['product_id'] ?? 0 ) ), 'variation_id' => (int) ( $incoming['data']['variation_id'] ?? 0 ), 'page_instance_id' => $incoming['page_instance_id'], 'tab_id' => $incoming['tab_id'], 'seq' => $incoming['seq'] );
		foreach ( $fixed as $key => $value ) {
			if ( is_int( $value ) ? (int) $event[ $key ] !== $value : $event[ $key ] !== $value ) { return self::receipt( $id, 'rejected', 'duplicate_payload_mismatch' ); }
		}
		if ( null === $saved || $saved !== $incoming['data']['page_context'] ) { return self::receipt( $id, 'rejected', 'duplicate_payload_mismatch' ); }
		$visit = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'visits' ) . ' WHERE id = %d AND visitor_id = %d', (int) $event['visit_id'], $visitor ) );
		if ( $wpdb->last_error ) { return self::receipt( $id, 'retry', 'server_error', true ); }
		return $visit ? self::receipt( $id, 'applied', 'already_saved' ) : self::receipt( $id, 'rejected', 'original_visit_missing' );
	}
}
