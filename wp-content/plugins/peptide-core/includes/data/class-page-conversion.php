<?php
/** Exposure-cohort observations. Episodes and same-visit actions are memberships, never raw fanout joins. */
namespace PeptideCore\Data;

use PeptideCore\Api\Errors;
use PeptideCore\Overview;
use PeptideCore\Tracking\Impressions;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;

defined( 'ABSPATH' ) || exit;

final class Page_Conversion {
	public const METRICS = array( 'exposed_users', 'viewed_users', 'view_user_rate', 'cart_users', 'cart_user_rate', 'paid_users', 'paid_user_rate', 'impressions', 'views', 'view_rate', 'carts', 'cart_rate', 'paid_units', 'paid_rate' );
	public const DEFAULTS = array( 'exposed_users', 'view_user_rate', 'viewed_users', 'cart_user_rate', 'cart_users', 'paid_users', 'paid_user_rate', 'impressions', 'views', 'view_rate' );
	private array $orders = array();

	public static function params( array $raw ) {
		$errors = array(); $out = array();
		foreach ( array( 'group' => array( 'type', array( 'type', 'page' ) ), 'sort' => array( 'exposed_users', self::METRICS ), 'order' => array( 'desc', array( 'asc', 'desc' ) ) ) as $key => $spec ) {
			$out[$key] = Params::word( $raw, $key, $spec[0], $spec[1] ); if ( $out[$key] === null || ( array_key_exists( $key, $raw ) && ! is_string( $raw[$key] ) ) ) { $errors[$key] = 'invalid_format'; }
		}
		foreach ( array( 'page' => array( 1, 999999999 ), 'per_page' => array( 20, 100 ) ) as $key => $spec ) {
			$out[$key] = Params::whole( $raw, $key, $spec[0], 1, $spec[1] ); if ( $out[$key] === null || ( array_key_exists( $key, $raw ) && ! is_string( $raw[$key] ) && ! is_int( $raw[$key] ) ) ) { $errors[$key] = 'invalid_format'; }
		}
		$out['type'] = array_key_exists( 'type', $raw ) ? $raw['type'] : ''; $out['q'] = array_key_exists( 'q', $raw ) ? $raw['q'] : '';
		if ( ! is_string( $out['type'] ) || ( $out['group'] === 'page' ? ! in_array( $out['type'], Impressions::KINDS, true ) : $out['type'] !== '' ) ) { $errors['type'] = 'invalid_format'; }
		if ( ! is_string( $out['q'] ) || ! preg_match( '//u', $out['q'] ) || mb_strlen( trim( $out['q'] ), 'UTF-8' ) > 100 ) { $errors['q'] = 'invalid_format'; }
		else { $out['q'] = trim( $out['q'] ); }
		if ( array_key_exists( 'format', $raw ) && ( ! is_string( $raw['format'] ) || $raw['format'] !== 'json' ) ) { $errors['format'] = 'invalid_format'; }
		$filter = Report_Filter::params( $raw ); if ( is_wp_error( $filter ) ) { return $filter; } if ( null !== $filter ) { $out['filter'] = $filter; }
		return $errors ? Errors::invalid( $errors ) : $out;
	}

	public static function measurement(): array { return array( 'version' => 1, 'minimum_visible_fraction' => 0.4, 'minimum_visible_ms' => 500, 'attribution' => 'same_visit_parent_after_impression_within_period', 'source' => 'pushed_observations' ); }

	public static function key( string $group, string $kind, ?int $entity, string $path ): string {
		return rawurlencode( json_encode( $group === 'type' ? array( 'type', $kind ) : array( 'page', $kind, $entity === null ? 'path' : 'entity', $entity ?? $path ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/** Pure reducer. Keep episode distincts and earliest memberships, not raw episode rows. */
	public static function episode( array &$rows, array &$membership, array $event, array $data, string $group ): void {
		$kind = $data['context']['kind']; $entity = in_array( $kind, array( 'product', 'collection' ), true ) ? ( $data['context']['entity_id'] ?? null ) : null;
		$key = $group === 'product' ? (string) $data['product_id'] : self::key( $group, $kind, $entity, $event['path'] );
		$rank = array( (int) $event['occurred_ms'], (int) $event['id'] );
		if ( ! isset( $rows[$key] ) ) { $rows[$key] = array( 'kind' => $kind, 'entity_id' => $group === 'type' ? null : $entity, 'path' => $group === 'type' ? '' : $event['path'], 'rank' => $rank, 'raw' => array() ); }
		if ( $rank < $rows[$key]['rank'] ) { $rows[$key]['rank'] = $rank; if ( $group !== 'type' ) { $rows[$key]['path'] = $event['path']; } }
		$v = (int) $event['visitor_id']; $s = (int) $event['visit_id']; $p = $data['product_id']; $at = (int) $event['occurred_ms'];
		$rows[$key]['raw']['exposed_users'][$v] = true;
		$rows[$key]['raw']['impressions'][json_encode( array( $v, $event['event_uid'] ) )] = true;
		$old = $membership[$s][$v][$p][$key] ?? null;
		$membership[$s][$v][$p][$key] = $old === null ? $at : min( $at, $old );
	}

	public static function metrics( array $raw ): array {
		$exposed = count( $raw['exposed_users'] ?? array() ); $episodes = count( $raw['impressions'] ?? array() );
		$viewed = count( $raw['viewed_users'] ?? array() ); $cart = count( $raw['cart_users'] ?? array() ); $paid = count( $raw['paid_users'] ?? array() );
		$views = count( $raw['views'] ?? array() ); $carts = count( $raw['carts'] ?? array() ); $units = (int) ( $raw['paid_units'] ?? 0 );
		$rate = static fn( $n, $d ) => $d ? round( $n / $d * 100, 2 ) : null;
		return array( 'exposed_users' => $exposed, 'viewed_users' => $viewed, 'view_user_rate' => $rate( $viewed, $exposed ), 'cart_users' => $cart, 'cart_user_rate' => $rate( $cart, $exposed ), 'paid_users' => $paid, 'paid_user_rate' => $rate( $paid, $exposed ), 'impressions' => $episodes, 'views' => $views, 'view_rate' => $rate( $views, $episodes ), 'carts' => $carts, 'cart_rate' => $rate( $carts, $episodes ), 'paid_units' => $units, 'paid_rate' => $rate( $units, $episodes ) );
	}

	public static function change( array $current, array $previous ): array {
		$out = array(); foreach ( self::METRICS as $key ) { $out[$key] = str_ends_with( $key, '_rate' ) ? ( $current[$key] !== null && $previous[$key] !== null ? round( $current[$key] - $previous[$key], 2 ) : null ) : Range::change( $current[$key], $previous[$key] ); } return $out;
	}

	public static function table( array $range, array $args ): array {
		$service = new self(); $tracking = Schema::installed();
		$current_filter = isset( $args['filter'] ) ? new Report_Filter( $args['filter'], $range['to'] * 1000 ) : null;
		$previous_filter = isset( $args['filter'] ) ? new Report_Filter( $args['filter'], $range['compare_to'] * 1000 ) : null;
		$current = $tracking ? $service->period( $range['from'] * 1000, $range['to'] * 1000, $args, $current_filter ) : array();
		$previous = $tracking ? $service->period( $range['compare_from'] * 1000, $range['compare_to'] * 1000, $args, $previous_filter ) : array();
		$union = array_replace( $previous, $current );
		foreach ( $union as $key => &$row ) {
			if ( isset( $current[$key], $previous[$key] ) && $previous[$key]['rank'] < $current[$key]['rank'] ) { $row['path'] = $previous[$key]['path']; }
			$row['current'] = self::metrics( $current[$key]['raw'] ?? array() ); $row['previous'] = self::metrics( $previous[$key]['raw'] ?? array() ); $row['change'] = self::change( $row['current'], $row['previous'] );
		} unset( $row );
		$labels = self::labels( $union ); $origin = self::origin(); $rows = array();
		foreach ( $union as $key => $row ) {
			$label = $labels[$key];
			if ( $args['q'] !== '' && mb_stripos( $label . ' ' . $row['path'], $args['q'], 0, 'UTF-8' ) === false ) { continue; }
			$rows[] = array( 'key' => $key, 'kind' => $row['kind'], 'entity_id' => $row['entity_id'], 'path' => $row['path'], 'label' => $label, 'url' => $row['path'] !== '' && $origin !== '' ? $origin . $row['path'] : '', 'can_drill' => $args['group'] === 'type', 'current' => $row['current'], 'previous' => $row['previous'], 'change' => $row['change'] );
		}
		usort( $rows, static function( $a, $b ) use ( $args ): int { $x = $a['current'][$args['sort']]; $y = $b['current'][$args['sort']]; if ( $x === null || $y === null ) { $cmp = (int) ( $x === null ) <=> (int) ( $y === null ); } else { $cmp = $args['order'] === 'asc' ? $x <=> $y : $y <=> $x; } return $cmp ?: strcmp( $a['key'], $b['key'] ); } );
		$total = count( $rows ); $rows = array_slice( $rows, ( $args['page'] - 1 ) * $args['per_page'], $args['per_page'] );
		$out = array( 'range' => $range, 'tracking' => $tracking, 'group' => $args['group'], 'type' => $args['type'], 'q' => $args['q'], 'sort' => $args['sort'], 'order' => $args['order'], 'page' => $args['page'], 'per_page' => $args['per_page'], 'total' => $total, 'metrics' => self::METRICS, 'defaults' => self::DEFAULTS, 'rows' => $rows, 'measurement' => self::measurement() );
		if ( isset( $args['filter'] ) ) { $out['filter'] = $args['filter']; $out['filter_coverage'] = array( 'current' => $current_filter->coverage(), 'previous' => $previous_filter->coverage() ); }
		return $out;
	}

	/** Internal parent mode: collapse identity before the same raw visitor/action/item deduplication. */
	public static function product_period( int $from, int $to, ?Report_Filter $filter = null ): array {
		if ( ! Schema::installed() ) { return array(); }
		$service = new self(); $rows = $service->period( $from, $to, array( 'group' => 'product', 'type' => '' ), $filter ); $out = array();
		foreach ( $rows as $parent => $row ) { $out[(int) $parent] = self::metrics( $row['raw'] ); }
		return $out;
	}

	private function period( int $from, int $to, array $args, ?Report_Filter $filter = null ): array {
		global $wpdb;
		$e = Schema::table( 'events' ); $s = Schema::table( 'visits' ); $v = Schema::table( 'visitors' );
		$join = " FROM {$e} e JOIN {$s} s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id JOIN {$v} v ON v.id=e.visitor_id ";
		$flags = ' AND e.is_test=0 AND s.is_test=0 AND v.is_test=0';
		$rows = array(); $membership = array(); $last_at = $from; $last_id = 0;
		do {
			$page = $wpdb->get_results( $wpdb->prepare( 'SELECT e.*,v.id AS matched_visitor_id,s.id AS matched_visit_id' . $join . "WHERE e.type='product_impression' AND e.source='client' AND e.occurred_ms>=%d AND e.occurred_ms<%d AND (e.occurred_ms>%d OR (e.occurred_ms=%d AND e.id>%d))" . $flags . ' ORDER BY e.occurred_ms,e.id LIMIT 500', $from, $to, $last_at, $last_at, $last_id ), ARRAY_A );
			if ( $wpdb->last_error || ! is_array( $page ) ) { throw new \RuntimeException( 'Product observation read unavailable' ); }
			$facts = $filter ? Report_Dimensions::visit_facts( array_column( $page, 'visit_id' ), $to ) : array();
			foreach ( $page as $event ) {
				$last_at = (int) $event['occurred_ms']; $last_id = (int) $event['id']; $data = Impressions::saved( $event );
				if ( ! $data || ( $args['group'] === 'page' && $data['context']['kind'] !== $args['type'] ) ) { continue; }
				if ( $filter && ! $filter->episode( Report_Dimensions::for_visit( $facts, (int) $event['visit_id'], (int) $event['visitor_id'] ) ) ) { continue; }
				self::episode( $rows, $membership, $event, $data, $args['group'] );
			}
		} while ( count( $page ) === 500 );
		foreach ( array_chunk( array_keys( $membership ), 100 ) as $visit_ids ) {
			$last_at = $from; $last_id = 0;
			do {
				$sql = 'SELECT e.*' . $join . 'WHERE e.visit_id IN (' . implode( ',', array_map( 'intval', $visit_ids ) ) . ") AND ((e.type='product_view' AND e.source='client') OR (e.type IN ('add_to_cart','payment_success') AND e.source='server')) AND e.occurred_ms>=%d AND e.occurred_ms<%d AND (e.occurred_ms>%d OR (e.occurred_ms=%d AND e.id>%d))" . $flags . ' ORDER BY e.occurred_ms,e.id LIMIT 500';
				$actions = $wpdb->get_results( $wpdb->prepare( $sql, $from, $to, $last_at, $last_at, $last_id ), ARRAY_A );
				if ( $wpdb->last_error || ! is_array( $actions ) ) { throw new \RuntimeException( 'Product action read unavailable' ); }
				$order_ids = array(); foreach ( $actions as $action ) { if ( $action['type'] === 'payment_success' && ( $id = Impressions::column_id( $action['order_id'] ) ) ) { $order_ids[] = $id; } }
				$this->load_orders( $order_ids );
				foreach ( $actions as $action ) {
					$last_at = (int) $action['occurred_ms']; $last_id = (int) $action['id']; $visitor = (int) $action['visitor_id']; $visit = (int) $action['visit_id'];
					if ( $action['type'] === 'payment_success' ) {
						$order = $this->orders[(int) $action['order_id']] ?? null;
						if ( ! $order || $order['visitor'] !== $visitor || $order['visit'] !== $visit ) { continue; }
						foreach ( $order['lines'] as $line ) { foreach ( $membership[$visit][$visitor][$line['parent']] ?? array() as $key => $first ) { if ( $last_at < $first ) { continue; } $rows[$key]['raw']['paid_users'][$visitor] = true; if ( ! isset( $rows[$key]['raw']['paid_items'][$line['id']] ) ) { $rows[$key]['raw']['paid_items'][$line['id']] = true; $rows[$key]['raw']['paid_units'] = ( $rows[$key]['raw']['paid_units'] ?? 0 ) + $line['units']; } } }
					} else {
						$parent = Impressions::column_id( $action['product_id'] ); if ( ! $parent ) { continue; }
						foreach ( $membership[$visit][$visitor][$parent] ?? array() as $key => $first ) { if ( $last_at < $first ) { continue; } $view = $action['type'] === 'product_view'; $rows[$key]['raw'][$view ? 'viewed_users' : 'cart_users'][$visitor] = true; $rows[$key]['raw'][$view ? 'views' : 'carts'][(int) $action['id']] = true; }
					}
				}
			} while ( count( $actions ) === 500 );
		}
		return $rows;
	}

	private function load_orders( array $ids ): void {
		global $wpdb;
		$ids = array_values( array_filter( array_unique( $ids ), fn( $id ) => ! array_key_exists( $id, $this->orders ) ) );
		foreach ( array_chunk( $ids, 100 ) as $batch ) {
			foreach ( $batch as $id ) { $this->orders[$id] = null; }
			$orders = wc_get_orders( array( 'type' => 'shop_order', 'post__in' => $batch, 'limit' => count( $batch ), 'return' => 'objects' ) );
			if ( ! is_array( $orders ) || $wpdb->last_error ) { throw new \RuntimeException( 'Native paid order read unavailable' ); }
			foreach ( $orders as $order ) {
				$id = (int) $order->get_id(); if ( ! in_array( $id, $batch, true ) || $order->get_type() !== 'shop_order' || Overview::is_test_order( $order ) || $order->get_payment_method() === 'cod' || ( ! $order->is_paid() && $order->get_date_paid() === null ) ) { continue; }
				$visitor = Impressions::column_id( $order->get_meta( Server_Events::ORDER_VISITOR_META, true ) ); $visit = Impressions::column_id( $order->get_meta( Server_Events::ORDER_VISIT_META, true ) );
				if ( ! $visitor || ! $visit ) { continue; } $lines = array();
				foreach ( $order->get_items() as $item ) { if ( ! $item instanceof \WC_Order_Item_Product || ! ( $parent = Impressions::column_id( $item->get_product_id() ) ) ) { continue; } $lines[] = array( 'id' => (int) $item->get_id(), 'parent' => $parent, 'units' => (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) ) ); }
				$this->orders[$id] = compact( 'visitor', 'visit', 'lines' );
			}
		}
	}

	public static function kind_labels(): array {
		return array( 'home' => _x( 'Home', 'Page conversion kind', 'peptide-core' ), 'shop' => _x( 'Shop', 'Page conversion kind', 'peptide-core' ), 'product' => _x( 'Product', 'Page conversion kind', 'peptide-core' ), 'cart' => _x( 'Cart', 'Page conversion kind', 'peptide-core' ), 'checkout' => _x( 'Checkout', 'Page conversion kind', 'peptide-core' ), 'account' => _x( 'Account', 'Page conversion kind', 'peptide-core' ), 'order_received' => _x( 'Order received', 'Page conversion kind', 'peptide-core' ), 'order_pay' => _x( 'Order payment', 'Page conversion kind', 'peptide-core' ), 'content' => _x( 'Content', 'Page conversion kind', 'peptide-core' ), 'collection' => _x( 'Collection', 'Page conversion kind', 'peptide-core' ), 'search' => _x( 'Search', 'Page conversion kind', 'peptide-core' ), 'unknown' => _x( 'Unknown', 'Page conversion kind', 'peptide-core' ) );
	}

	private static function labels( array $rows ): array {
		global $wpdb;
		$products = array(); $terms = array(); $names = array(); $kinds = self::kind_labels();
		foreach ( $rows as $row ) { if ( $row['entity_id'] ) { if ( $row['kind'] === 'product' ) { $products[$row['entity_id']] = true; } elseif ( $row['kind'] === 'collection' ) { $terms[$row['entity_id']] = true; } } }
		if ( $products ) {
			// Resolve existence in one native batch before getters: priming alone does not
			// negative-cache missing post IDs. Prime product-type terms as well as metadata.
			$found = get_posts( array( 'post_type' => 'product', 'post_status' => array_values( get_post_stati() ), 'post__in' => array_keys( $products ), 'posts_per_page' => count( $products ), 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
			if ( $wpdb->last_error ) { throw new \RuntimeException( 'Page product labels unavailable' ); }
			_prime_post_caches( $found, true, true );
			if ( $wpdb->last_error ) { throw new \RuntimeException( 'Page product cache read unavailable' ); }
			foreach ( $found as $id ) { $p = wc_get_product( $id ); if ( $p && $p->get_parent_id() === 0 ) { $names['product'][$id] = $p->get_name(); } }
		}
		if ( $terms ) { $found = get_terms( array( 'taxonomy' => 'product_cat', 'include' => array_keys( $terms ), 'hide_empty' => false ) ); if ( is_wp_error( $found ) ) { throw new \RuntimeException( 'Page labels unavailable' ); } foreach ( $found as $term ) { $names['collection'][(int) $term->term_id] = $term->name; } }
		$out = array(); foreach ( $rows as $key => $row ) { $out[$key] = $row['entity_id'] ? ( $names[$row['kind']][$row['entity_id']] ?? sprintf( __( 'Unavailable #%d', 'peptide-core' ), $row['entity_id'] ) ) : ( $row['path'] !== '' ? $row['path'] : $kinds[$row['kind']] ); } return $out;
	}

	private static function origin(): string {
		$parts = wp_parse_url( home_url() ); if ( ! is_array( $parts ) || ! isset( $parts['host'], $parts['scheme'] ) || ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) { return ''; }
		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}
}
