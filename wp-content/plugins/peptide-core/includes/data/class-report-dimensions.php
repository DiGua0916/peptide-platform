<?php
/** Read-only, request-local entry/country facts. Cutoffs are not historical snapshots. */
namespace PeptideCore\Data;

use PeptideCore\Tracking\Attribution;
use PeptideCore\Tracking\Page_Context;
use PeptideCore\Tracking\Schema;

defined( 'ABSPATH' ) || exit;

final class Report_Dimensions {

	public const CHANNEL_POLICY = 'traffic-v1';

	public static function positive_id( $value ): ?int {
		if ( is_int( $value ) ) { return $value > 0 ? $value : null; }
		if ( ! is_string( $value ) || ! preg_match( '/\A[0-9]+\z/', $value ) ) { return null; }
		$digits = ltrim( $value, '0' ); $max = (string) PHP_INT_MAX;
		return '' !== $digits && ( strlen( $digits ) < strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) <= 0 ) ) ? (int) $digits : null;
	}

	public static function unknown(): array {
		$out = array( 'visitor_id' => 0 );
		foreach ( array( 'entry_domain', 'utm_source', 'utm_medium', 'country', 'operating_system', 'screen_size', 'channel' ) as $key ) { $out[ $key ] = array( 'state' => 'unobserved', 'value' => null ); }
		return $out;
	}

	/** The state participates in identity: missing, empty and literal labels never collide. */
	public static function key( array $typed ): string { return serialize( array( $typed['state'], $typed['value'] ) ); }

	public static function for_visit( array $facts, int $visit_id, int $visitor_id ): array {
		return $visit_id > 0 && $visitor_id > 0 && isset( $facts[ $visit_id ] ) && (int) $facts[ $visit_id ]['visitor_id'] === $visitor_id ? $facts[ $visit_id ] : self::unknown();
	}

	/** IDs are chunked; observations are keyset-scanned, never truncated to the first batch. */
	public static function visit_facts( array $visit_ids, int $cutoff_ms ): array {
		global $wpdb;
		if ( $cutoff_ms <= 0 || ! Schema::installed() ) { throw new \RuntimeException( 'Report dimensions unavailable.' ); }
		$ids = array(); foreach ( $visit_ids as $id ) { $id = self::positive_id( $id ); if ( $id ) { $ids[ $id ] = $id; } }
		$out = array(); $visits = Schema::table( 'visits' ); $events = Schema::table( 'events' );
		foreach ( array_chunk( array_values( $ids ), 500 ) as $chunk ) {
			$marks = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, visitor_id, ' . implode( ', ', Attribution::COLUMNS ) . " FROM {$visits} WHERE id IN ({$marks})", $chunk ), ARRAY_A ); self::check_db();
			$owners = array();
			foreach ( (array) $rows as $row ) {
				$id = (int) $row['id']; $fact = self::unknown(); $fact['visitor_id'] = (int) $row['visitor_id'];
				if ( $fact['visitor_id'] > 0 && 1 === (int) $row['attribution_version'] && (int) $row['attribution_ms'] < $cutoff_ms ) {
					$fact['channel'] = self::channel( $row );
					$fact['entry_domain'] = array( 'state' => 'value', 'value' => (string) $row['attribution_host'] );
					foreach ( array( 'utm_source', 'utm_medium' ) as $key ) { $value = $row[ $key ]; $fact[ $key ] = array( 'state' => null === $value ? 'missing' : ( '' === $value ? 'empty' : 'value' ), 'value' => $value ); }
				}
				$out[ $id ] = $fact; $owners[ $id ] = $fact['visitor_id'];
			}
			if ( ! $owners ) { continue; }
			$in = implode( ',', array_keys( $owners ) ); $at = $cutoff_ms; $cursor = PHP_INT_MAX; $seen = array();
			do {
				$observations = $wpdb->get_results( $wpdb->prepare( "SELECT id, visit_id, visitor_id, source, occurred_ms, data FROM {$events} WHERE visit_id IN ({$in}) AND type = 'page_view' AND occurred_ms < %d AND (occurred_ms < %d OR (occurred_ms = %d AND id < %d)) ORDER BY occurred_ms DESC, id DESC LIMIT 500", $cutoff_ms, $at, $at, $cursor ), ARRAY_A ); self::check_db();
				foreach ( (array) $observations as $event ) {
					$id = (int) $event['visit_id']; if ( ! isset( $owners[ $id ] ) || $owners[ $id ] <= 0 || $owners[ $id ] !== (int) $event['visitor_id'] ) { continue; }
					$data = json_decode( (string) $event['data'], true ); $context = Page_Context::saved( is_array( $data ) ? ( $data['page_context'] ?? null ) : null );
					if ( ! isset( $seen[$id]['country'] ) && isset( $context['geo'] ) ) {
						$geo = $context['geo']; $out[$id]['country'] = array( 'state' => 'known' === $geo['status'] ? 'value' : $geo['status'], 'value' => $geo['country_code'] ?? null ); $seen[$id]['country'] = true;
					}
					if ( 'client' !== ( $event['source'] ?? null ) || ! isset( $context['environment'] ) ) { continue; }
					foreach ( array( 'os' => 'operating_system', 'screen' => 'screen_size' ) as $field => $dimension ) {
						if ( isset( $seen[$id][$dimension] ) || ! isset( $context['environment'][$field] ) ) { continue; }
						$fact = $context['environment'][$field];
						$value = 'known' !== $fact['status'] ? null : ( 'os' === $field ? $fact['family'] : $fact['width'] . 'x' . $fact['height'] );
						$out[$id][$dimension] = array( 'state' => 'known' === $fact['status'] ? 'value' : $fact['status'], 'value' => $value ); $seen[$id][$dimension] = true;
					}
				}
				if ( $observations ) { $last = end( $observations ); $at = (int) $last['occurred_ms']; $cursor = (int) $last['id']; }
			} while ( count( (array) $observations ) === 500 );
		}
		return $out;
	}

	/** Project policy v1, derived only from a saved entry snapshot; never rewrites tags. */
	public static function channel( array $saved ): array {
		$result = static fn( string $state, ?string $value = null ): array => array( 'state' => $state, 'value' => $value );
		if ( 1 !== (int) ( $saved['attribution_version'] ?? 0 ) ) { return $result( 'unobserved' ); }
		$source = is_string( $saved['utm_source'] ?? null ) ? strtolower( trim( $saved['utm_source'] ) ) : '';
		$medium = is_string( $saved['utm_medium'] ?? null ) ? strtolower( trim( $saved['utm_medium'] ) ) : '';
		$search = array( 'google.com', 'google.co.uk', 'google.com.hk', 'google.cn', 'bing.com', 'search.yahoo.com', 'duckduckgo.com', 'baidu.com', 'yandex.ru' );
		$social = array( 'facebook.com', 'fb.com', 'instagram.com', 'tiktok.com', 'x.com', 't.co', 'pinterest.com', 'reddit.com', 'linkedin.com', 'youtube.com' );
		$matches = static function ( string $host, array $domains ): bool { foreach ( $domains as $domain ) { if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) { return true; } } return false; };
		if ( '' !== $source || '' !== $medium ) {
			if ( '(direct)' === $source && '(none)' === $medium ) { return $result( 'value', 'direct_or_hidden' ); }
			$channel = null;
			if ( 'email' === $medium ) { $channel = 'email'; }
			elseif ( in_array( $medium, array( 'paid_social', 'social_paid', 'paidsocial' ), true ) ) { $channel = 'paid_social'; }
			elseif ( in_array( $medium, array( 'cpc', 'ppc', 'paidsearch', 'paid_search' ), true ) ) {
				$is_social = in_array( $source, array( 'facebook', 'fb', 'instagram', 'tiktok', 'x', 'twitter', 'pinterest', 'reddit', 'linkedin', 'youtube', 'meta' ), true ) || $matches( $source, $social );
				$is_search = in_array( $source, array( 'google', 'bing', 'yahoo', 'duckduckgo', 'baidu', 'yandex' ), true ) || $matches( $source, $search );
				$channel = $is_social ? 'paid_social' : ( $is_search ? 'paid_search' : 'paid_other' );
			}
			elseif ( 'organic' === $medium ) { $channel = 'organic_search'; }
			elseif ( in_array( $medium, array( 'social', 'social-network', 'social-media', 'sm' ), true ) ) { $channel = 'organic_social'; }
			elseif ( in_array( $medium, array( 'referral', 'app', 'link' ), true ) ) { $channel = 'referral'; }
			elseif ( in_array( $medium, array( 'display', 'cpm', 'banner' ), true ) ) { $channel = 'display'; }
			elseif ( in_array( $medium, array( 'affiliates', 'affiliate' ), true ) ) { $channel = 'affiliate'; }
			elseif ( in_array( $medium, array( 'paid', 'retargeting' ), true ) ) { $channel = 'paid_other'; }
			return $result( 'value', $channel ?? 'tagged_other' );
		}
		$referrer = $saved['referrer_host'] ?? null;
		if ( null === $referrer ) { return $result( 'unknown' ); }
		if ( '' === $referrer ) { return $result( 'value', 'direct_or_hidden' ); }
		$referrer = self::channel_host( $referrer );
		if ( null === $referrer ) { return $result( 'unknown' ); }
		$entry = self::channel_host( $saved['attribution_host'] ?? null );
		if ( null !== $entry && $matches( $referrer, array( $entry ) ) ) { return $result( 'value', 'internal_referral' ); }
		return $result( 'value', $matches( $referrer, $search ) ? 'organic_search' : ( $matches( $referrer, $social ) ? 'organic_social' : 'referral' ) );
	}

	private static function channel_host( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 253 ) { return null; }
		$value = strtolower( $value ); if ( str_ends_with( $value, '.' ) ) { $value = substr( $value, 0, -1 ); }
		foreach ( explode( '.', $value ) as $label ) { if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label ) ) { return null; } }
		return $value;
	}

	public static function channel_label( array $typed ): string {
		if ( 'value' !== $typed['state'] ) { return 'unknown' === $typed['state'] ? __( 'Traffic source unknown', 'peptide-core' ) : __( 'Not observed', 'peptide-core' ); }
		$labels = array(
			'email' => __( 'Email', 'peptide-core' ), 'paid_social' => __( 'Paid social', 'peptide-core' ),
			'paid_search' => __( 'Paid search', 'peptide-core' ), 'paid_other' => __( 'Other paid traffic', 'peptide-core' ),
			'organic_search' => __( 'Organic search', 'peptide-core' ), 'organic_social' => __( 'Organic social', 'peptide-core' ),
			'referral' => __( 'Referral', 'peptide-core' ), 'display' => __( 'Display advertising', 'peptide-core' ),
			'affiliate' => __( 'Affiliate', 'peptide-core' ), 'tagged_other' => __( 'Other tagged traffic', 'peptide-core' ),
			'direct_or_hidden' => __( 'Direct / source hidden', 'peptide-core' ), 'internal_referral' => __( 'Internal referral', 'peptide-core' ),
		);
		return $labels[$typed['value']] ?? __( 'Traffic source unknown', 'peptide-core' );
	}

	public static function check_db(): void { global $wpdb; if ( $wpdb->last_error ) { throw new \RuntimeException( 'Report dimension query failed.' ); } }
}
