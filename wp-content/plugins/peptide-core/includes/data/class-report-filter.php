<?php
/** Typed domain/country filtering for reports; absent parameters retain legacy paths. */
namespace PeptideCore\Data;
use PeptideCore\Api\Errors;
use PeptideCore\Tracking\Attribution;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;
defined( 'ABSPATH' ) || exit;

final class Report_Filter {
	private array $value;
	private int $cutoff;
	private array $coverage = array();
	public function __construct( array $value, int $cutoff ) { $this->value = $value; $this->cutoff = $cutoff; }
	public static function params( array $params ) {
		$explicit = false; foreach ( array( 'entry_domain_state', 'entry_domain', 'country_state', 'country' ) as $key ) { if ( array_key_exists( $key, $params ) ) { $explicit = true; } }
		if ( ! $explicit ) { return null; }
		$out = array(); $errors = array();
		foreach ( array( 'entry_domain' => array( 'all', 'unobserved', 'value' ), 'country' => array( 'all', 'unobserved', 'unknown', 'unavailable', 'value' ) ) as $key => $allowed ) {
			$state = $params[ $key . '_state' ] ?? 'all';
			if ( ! is_string( $state ) || ! in_array( $state, $allowed, true ) || ( array_key_exists( $key . '_state', $params ) && null === $params[ $key . '_state' ] ) ) { $errors[ $key . '_state' ] = 'invalid_format'; continue; }
			$out[ $key . '_state' ] = $state;
			if ( 'value' !== $state ) { if ( array_key_exists( $key, $params ) ) { $errors[ $key ] = 'invalid_format'; } continue; }
			$value = $params[ $key ] ?? null;
			if ( 'entry_domain' === $key ) { $value = Attribution::host( $value ); }
			else {
				$value = is_string( $value ) && preg_match( '/\A[A-Za-z]{2}\z/', $value ) ? strtoupper( $value ) : null;
				$countries = function_exists( 'WC' ) && WC() && WC()->countries ? WC()->countries->get_countries() : array();
				if ( null !== $value && ! array_key_exists( $value, $countries ) ) { $value = null; }
			}
			if ( null === $value ) { $errors[ $key ] = 'invalid_format'; } else { $out[ $key ] = $value; }
		}
		return $errors ? Errors::invalid( $errors ) : $out;
	}
	public static function active( array $args, int $cutoff ): ?self {
		$v = $args['filter'] ?? null;
		return $v && ( 'all' !== $v['entry_domain_state'] || 'all' !== $v['country_state'] ) ? new self( $v, $cutoff ) : null;
	}
	public function matches( array $fact ): bool {
		foreach ( array( 'entry_domain', 'country' ) as $key ) { $state = $this->value[ $key . '_state' ]; if ( 'all' !== $state && ( $state !== $fact[ $key ]['state'] || ( 'value' === $state && $this->value[ $key ] !== $fact[ $key ]['value'] ) ) ) { return false; } }
		return true;
	}
	public function coverage(): array { return $this->coverage; }
	/** Exposure readers supply an already owner-checked fact; other report readers keep their existing paths. */
	public function episode( array $fact ): bool { return $this->observe( 'episodes', $fact ); }
	private function observe( string $unit, array $fact ): bool {
		if ( ! isset( $this->coverage[ $unit ] ) ) { $this->coverage[ $unit ] = array( 'base_unit' => $unit, 'base_count' => 0, 'matched_count' => 0, 'entry_domain' => array( 'unobserved' => 0, 'value' => 0 ), 'country' => array( 'unobserved' => 0, 'unknown' => 0, 'unavailable' => 0, 'value' => 0 ) ); }
		++$this->coverage[ $unit ]['base_count'];
		foreach ( array( 'entry_domain', 'country' ) as $key ) { ++$this->coverage[ $unit ][ $key ][ $fact[ $key ]['state'] ]; }
		$matches = $this->matches( $fact ); if ( $matches ) { ++$this->coverage[ $unit ]['matched_count']; } return $matches;
	}
	public function orders( array $orders ): array {
		$orders = array_values( array_filter( $orders, static fn( $order ) => ! self::is_test( $order ) ) );
		$ids = array(); $links = array();
		foreach ( $orders as $order ) { $id = $order->get_id(); $visit = Report_Dimensions::positive_id( $order->get_meta( Server_Events::ORDER_VISIT_META ) ) ?? 0; $visitor = Report_Dimensions::positive_id( $order->get_meta( Server_Events::ORDER_VISITOR_META ) ) ?? 0; $links[ $id ] = array( $visit, $visitor ); if ( $visit && $visitor ) { $ids[] = $visit; } }
		$facts = Report_Dimensions::visit_facts( $ids, $this->cutoff ); $out = array();
		foreach ( $orders as $order ) { $link = $links[ $order->get_id() ]; if ( $this->observe( 'orders', Report_Dimensions::for_visit( $facts, $link[0], $link[1] ) ) ) { $out[] = $order; } }
		return $out;
	}
	/** A bounded batch reader for active filters; legacy aggregation SQL is never changed. */
	public function events( int $from, int $to, array $types ): iterable {
		global $wpdb; $cursor = 0; $table = Schema::table( 'events' );
		if ( ! Schema::installed() ) { throw new \RuntimeException( 'Report dimensions unavailable.' ); }
		if ( ! $types || array_diff( $types, array( 'page_view', 'product_view', 'add_to_cart' ) ) ) { throw new \InvalidArgumentException( 'Invalid report event types.' ); }
		$type_sql = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,visit_id,visitor_id,type,path,product_id,variation_id,data FROM {$table} WHERE occurred_ms >= %d AND occurred_ms < %d AND id > %d AND type IN ({$type_sql}) ORDER BY id LIMIT 500", array_merge( array( $from * 1000, $to * 1000, $cursor ), $types ) ), ARRAY_A ); Report_Dimensions::check_db();
			$facts = Report_Dimensions::visit_facts( array_column( (array) $rows, 'visit_id' ), $this->cutoff );
			foreach ( (array) $rows as $row ) { $cursor = (int) $row['id']; if ( $this->observe( 'events', Report_Dimensions::for_visit( $facts, (int) $row['visit_id'], (int) $row['visitor_id'] ) ) ) { yield $row; } }
		} while ( count( (array) $rows ) === 500 );
	}
	public function visits( int $from, int $to ): iterable {
		global $wpdb; $cursor = 0; $table = Schema::table( 'visits' );
		if ( ! Schema::installed() ) { throw new \RuntimeException( 'Report dimensions unavailable.' ); }
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE start_ms >= %d AND start_ms < %d AND id > %d ORDER BY id LIMIT 500", $from * 1000, $to * 1000, $cursor ), ARRAY_A ); Report_Dimensions::check_db();
			$facts = Report_Dimensions::visit_facts( array_column( (array) $rows, 'id' ), $this->cutoff );
			foreach ( (array) $rows as $row ) { $cursor = (int) $row['id']; if ( $this->observe( 'visits', Report_Dimensions::for_visit( $facts, $cursor, (int) $row['visitor_id'] ) ) ) { yield $row; } }
		} while ( count( (array) $rows ) === 500 );
	}

	/** M6（NF-02）：测试单规则按开关走 Metrics_Overlay::is_test_order()（关着时就是 Overview 的旧规则）；不带自动加载的纯逻辑测试里没有这个类，直接用旧规则。 */
	private static function is_test( $order ): bool {
		return class_exists( Metrics_Overlay::class ) ? Metrics_Overlay::is_test_order( $order ) : \PeptideCore\Overview::is_test_order( $order );
	}
}
