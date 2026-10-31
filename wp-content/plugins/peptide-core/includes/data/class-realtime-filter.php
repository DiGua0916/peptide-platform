<?php
/** Typed entry-attribution filter. A cutoff observes received time, not MVCC/CAS time. */
namespace PeptideCore\Data;

use PeptideCore\Api\Errors;
use PeptideCore\Overview;
use PeptideCore\Tracking\Attribution;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;

defined( 'ABSPATH' ) || exit;

final class Realtime_Filter {

	private array $value;
	private int $cutoff;
	private array $visits = array();
	private ?array $orders = null;
	private array $order_links = array();
	private array $order_facts = array();
	private int $order_from = 0;
	private int $order_to = 0;

	private function __construct( array $value, int $cutoff = 0 ) { $this->value = $value; $this->cutoff = $cutoff; }

	/** Missing state means All; explicit null/empty/boolean is never a typed state. */
	public static function params( array $params ) {
		$clean = self::clean( $params );
		return $clean['ok'] ? new self( $clean['value'] ) : Errors::invalid( $clean['fields'] );
	}
	public static function clean( array $params ): array {
		$out = array(); $errors = array();
		foreach ( array( 'entry_domain' => array( 'all', 'unobserved', 'value' ), 'utm_source' => array( 'all', 'unobserved', 'missing', 'empty', 'value' ) ) as $key => $allowed ) {
			$state_key = $key . '_state'; $state = array_key_exists( $state_key, $params ) ? $params[ $state_key ] : 'all';
			if ( ! is_string( $state ) || ! in_array( $state, $allowed, true ) ) { $errors[ $state_key ] = 'invalid_format'; continue; }
			$out[ $state_key ] = $state;
			if ( 'value' !== $state ) { if ( array_key_exists( $key, $params ) ) { $errors[ $key ] = 'invalid_format'; } continue; }
			$raw = $params[ $key ] ?? null;
			if ( 'entry_domain' === $key ) { $clean = Attribution::host( $raw ); }
			else { $clean = self::source( $raw ) && '' !== $raw ? $raw : null; }
			if ( null === $clean ) { $errors[ $key ] = 'invalid_format'; } else { $out[ $key ] = $clean; }
		}
		return $errors ? array( 'ok' => false, 'fields' => $errors ) : array( 'ok' => true, 'value' => $out );
	}

	public static function source( $value ): bool {
		return is_string( $value ) && preg_match( '//u', $value ) && ! preg_match( '/[\x00-\x1f\x7f]/', $value ) && preg_match_all( '/./us', $value ) <= 120;
	}
	public function normalized(): array { return $this->value; }
	public function is_all(): bool { return 'all' === $this->value['entry_domain_state'] && 'all' === $this->value['utm_source_state']; }
	public function at( int $exclusive_cutoff ): self { if ( $exclusive_cutoff <= 0 ) { throw new \InvalidArgumentException( 'Invalid attribution cutoff.' ); } return new self( $this->value, $exclusive_cutoff ); }
	public function require_ready(): void { if ( ! Schema::installed() ) { throw new \RuntimeException( 'Attribution storage unavailable.' ); } }
	public static function positive_id( $value ): ?int {
		if ( is_int( $value ) ) { return $value > 0 ? $value : null; }
		if ( ! is_string( $value ) || ! preg_match( '/\A[0-9]+\z/', $value ) ) { return null; }
		$digits = ltrim( $value, '0' ); $max = (string) PHP_INT_MAX;
		return '' !== $digits && ( strlen( $digits ) < strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) <= 0 ) ) ? (int) $digits : null;
	}

	/** Pure normalized classification; no visitor-table requirement, no current-navigation inference. */
	public function classify( ?array $visit ): array {
		$reason = ! $visit || (int) ( $visit['visitor_id'] ?? 0 ) <= 0 ? 'missing_association' : ( 1 !== (int) ( $visit['attribution_version'] ?? 0 ) ? 'v0' : ( (int) $visit['attribution_ms'] >= $this->cutoff ? 'late_capture' : null ) );
		if ( null !== $reason ) { return array( 'captured' => false, 'reason' => $reason, 'entry_domain_state' => 'unobserved', 'utm_source_state' => 'unobserved' ); }
		$source = $visit['utm_source'];
		return array( 'captured' => true, 'reason' => null, 'entry_domain_state' => 'value', 'entry_domain' => (string) $visit['attribution_host'], 'utm_source_state' => null === $source ? 'missing' : ( '' === $source ? 'empty' : 'value' ), 'utm_source' => $source );
	}
	public function matches( array $fact, ?string $only = null ): bool {
		foreach ( array( 'entry_domain', 'utm_source' ) as $key ) {
			if ( null !== $only && $only !== $key ) { continue; }
			$state = $this->value[ $key . '_state' ];
			if ( 'all' !== $state && ( $state !== $fact[ $key . '_state' ] || ( 'value' === $state && $this->value[ $key ] !== $fact[ $key ] ) ) ) { return false; }
		}
		return true;
	}

	/** SQL aliases are internal fixed tokens, never request values or column callbacks. */
	public function visit_sql( string $alias = '' ): string {
		global $wpdb;
		if ( ! in_array( $alias, array( '', 's', 'rt_visit' ), true ) || $this->cutoff <= 0 ) { throw new \InvalidArgumentException( 'Invalid attribution predicate.' ); }
		$prefix = ( '' === $alias ? Schema::table( 'visits' ) : $alias ) . '.';
		$captured = $wpdb->prepare( "({$prefix}visitor_id > 0 AND {$prefix}attribution_version=1 AND {$prefix}attribution_ms < %d)", $this->cutoff );
		$parts = array();
		foreach ( array( 'entry_domain' => 'attribution_host', 'utm_source' => 'utm_source' ) as $key => $column ) {
			$state = $this->value[ $key . '_state' ]; if ( 'all' === $state ) { continue; }
			if ( 'unobserved' === $state ) { $parts[] = 'NOT ' . $captured; }
			elseif ( 'missing' === $state ) { $parts[] = "({$captured} AND {$prefix}{$column} IS NULL)"; }
			else { $parts[] = '(' . $captured . ' AND ' . $wpdb->prepare( "BINARY {$prefix}{$column} = BINARY %s", 'empty' === $state ? '' : $this->value[ $key ] ) . ')'; }
		}
		return $parts ? '(' . implode( ' AND ', $parts ) . ')' : '1=1';
	}
	public function event_sql( string $alias = '' ): string {
		if ( ! in_array( $alias, array( '', 'e' ), true ) ) { throw new \InvalidArgumentException( 'Invalid event predicate.' ); }
		$prefix = ( $alias ?: Schema::table( 'events' ) ) . '.'; $table = Schema::table( 'visits' );
		$link = "rt_visit.id={$prefix}visit_id AND rt_visit.visitor_id={$prefix}visitor_id AND {$prefix}visitor_id>0";
		$missing_matches = $this->matches( $this->classify( null ) );
		return '(EXISTS (SELECT 1 FROM ' . $table . ' rt_visit WHERE ' . $link . ' AND ' . $this->visit_sql( 'rt_visit' ) . ')' . ( $missing_matches ? ' OR NOT EXISTS (SELECT 1 FROM ' . $table . ' rt_visit WHERE ' . $link . ')' : '' ) . ')';
	}
	public function prime_visits( array $ids ): void {
		global $wpdb; $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $id ) => $id > 0 ) ) );
		$need = array_values( array_filter( $ids, fn( $id ) => ! array_key_exists( $id, $this->visits ) ) );
		foreach ( array_chunk( $need, 500 ) as $chunk ) {
			foreach ( $chunk as $id ) { $this->visits[ $id ] = null; }
			$marks = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,visitor_id,attribution_version,attribution_ms,attribution_host,utm_source FROM ' . Schema::table( 'visits' ) . " WHERE id IN ({$marks})", $chunk ), ARRAY_A );
			if ( $wpdb->last_error ) { throw new \RuntimeException( 'Attribution association unavailable.' ); }
			foreach ( (array) $rows as $row ) { $this->visits[ (int) $row['id'] ] = $row; }
		}
	}
	public function linked_visit( int $visit, int $visitor ): ?array { $row = $this->visits[ $visit ] ?? null; return $visitor > 0 && $row && (int) $row['visitor_id'] === $visitor ? $row : null; }

	/** One union hydration per report, using native HPOS-aware getters and unchanged paid eligibility. */
	public function prime_orders( int $from, int $to ): void {
		if ( null !== $this->orders && $from >= $this->order_from && $to <= $this->order_to ) { return; }
		if ( null !== $this->orders ) { throw new \LogicException( 'Prime the complete order union before reading panels.' ); }
		$this->require_ready(); $this->order_from = $from; $this->order_to = $to; $this->orders = array(); $ids = array();
		foreach ( Operations::paid_orders( $from, $to ) as $order ) {
			if ( ! $order instanceof \WC_Order || Overview::is_test_order( $order ) ) { continue; }
			$this->orders[] = $order;
			$link = array( self::positive_id( $order->get_meta( Server_Events::ORDER_VISIT_META ) ), self::positive_id( $order->get_meta( Server_Events::ORDER_VISITOR_META ) ) );
			$this->order_links[ $order->get_id() ] = $link; if ( $link[0] && $link[1] ) { $ids[] = $link[0]; }
		}
		$this->prime_visits( $ids );
		foreach ( $this->orders as $order ) { $this->order_facts[ $order->get_id() ] = $this->classify( $this->order_visit( $order ) ); }
	}
	public function order_visit( \WC_Order $order ): ?array { $link = $this->order_links[ $order->get_id() ] ?? array( null, null ); return $link[0] && $link[1] ? $this->linked_visit( $link[0], $link[1] ) : null; }
	public function orders( int $from, int $to, bool $matching = true ): array {
		$this->prime_orders( $from, $to ); $out = array();
		foreach ( $this->orders as $order ) { $created = $order->get_date_created(); if ( $created && $created->getTimestamp() >= $from && $created->getTimestamp() < $to && ( ! $matching || $this->matches( $this->order_facts[ $order->get_id() ] ) ) ) { $out[] = $order; } }
		return $out;
	}
}
