<?php
/** Public product selection from existing facts; no collectors, order imports or public analytics API. */
namespace PeptideCore\Modules;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;
use PeptideCore\Data\Range;
use PeptideCore\Meta;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class Product_Behavior_Rankings {

	/** Request-local IDs only. No persistent cache can outlive privacy deletion or refund changes. */
	private static array $memo = array();

	/**
	 * Public cards only; diagnostic codes are for the editor, never a public analytics payload.
	 * A window means N shop-calendar days including today. Sales use order creation, not payment time.
	 * @return array{products:array,state:string,notes:array}
	 */
	public static function get( string $metric, int $days, int $limit ): array {
		$empty = array( 'products' => array(), 'state' => 'unavailable', 'notes' => array( 'unavailable' ) );
		if ( ! in_array( $metric, array( 'views', 'sales', 'cart' ), true ) || $days < 1 || $days > 40 || $limit < 1 || $limit > 100 || ! function_exists( 'wc_get_product' ) ) { return $empty; }
		try {
			$range = self::window( $days );
			$notes = array();
			if ( 'sales' !== $metric ) {
				if ( ! Schema::installed() ) { return array( 'products' => array(), 'state' => 'unavailable', 'notes' => array( 'tracking_unavailable' ) ); }
				$settings = Tracker::settings();
				$notes[] = 'sampled_events';
				if ( empty( $settings['enabled'] ) ) { $notes[] = 'tracking_disabled'; }
				if ( $days > (int) $settings['retention_days'] ) { $notes[] = 'retention_limited'; }
			}
			global $wpdb;
			$key = (string) ( $wpdb->prefix ?? '' ) . ':' . $metric . ':' . $range['from'] . ':' . $range['to'];
			if ( ! array_key_exists( $key, self::$memo ) ) {
				self::$memo[ $key ] = self::read_ids( $metric, (int) $range['from'], (int) $range['to'] );
			}
			$products = array();
			// Recheck now: cached selection never authorizes private/password/catalog-hidden cards.
			foreach ( self::$memo[ $key ] as $id ) {
				$product = wc_get_product( $id );
				if ( Products::public_catalog_product( $product ) ) { $products[] = $product; }
				if ( count( $products ) === $limit ) { break; }
			}
			if ( ! $products ) { $notes[] = 'empty_sample'; }
			return array( 'products' => $products, 'state' => $products ? 'ready' : 'empty', 'notes' => $notes );
		} catch ( \Throwable $error ) {
			if ( $error instanceof \DomainException && 'unsupported_order_store' === $error->getMessage() ) { $empty['notes'] = array( 'unsupported_order_store' ); }
			return $empty;
		}
	}

	/** Same calendar/DST rules as the existing data pages; optional clock is private pure-test input. */
	private static function window( int $days, ?int $now = null ): array {
		$today = ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( wp_timezone() )->setTime( 0, 0 );
		$range = Range::from_params( array( 'from' => $today->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' ), 'to' => $today->format( 'Y-m-d' ) ) );
		if ( is_wp_error( $range ) ) { throw new \RuntimeException( 'Ranking window unavailable.' ); }
		return $range;
	}

	/** Select only aggregated public IDs. No visitor, order ID, quantity or revenue leaves this method. */
	private static function read_ids( string $metric, int $from, int $to ): array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) { throw new \RuntimeException( 'Ranking data unavailable.' ); }
		$sql = 'sales' === $metric ? self::sales_sql( $from, $to ) : self::events_sql( $metric, $from, $to );
		$queries = (int) ( $wpdb->num_queries ?? 0 );
		$suppress = method_exists( $wpdb, 'suppress_errors' ); $previous = $suppress ? $wpdb->suppress_errors( true ) : null;
		try {
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! is_array( $rows ) || ( (int) ( $wpdb->num_queries ?? 0 ) > $queries && '' !== (string) ( $wpdb->last_error ?? '' ) ) ) { throw new \RuntimeException( 'Ranking query failed.' ); }
		} finally { if ( $suppress ) { $wpdb->suppress_errors( $previous ); } }
		$ids = array();
		foreach ( $rows as $row ) {
			$id = (int) ( $row['product_id'] ?? 0 );
			if ( $id > 0 ) { $ids[ $id ] = $id; }
		}
		return array_values( $ids );
	}

	/** Fixed identifier/alias strings only. Values are always prepare() parameters. */
	private static function first_meta( string $table, string $id_column, string $owner, string $key, array &$values ): string {
		$values[] = $key;
		return "(SELECT m.meta_value FROM {$table} m WHERE m.{$id_column} = {$owner} AND m.meta_key = %s ORDER BY m.meta_id ASC LIMIT 1)";
	}

	/** SQL narrows to catalog candidates; current Woo filters/visibility are checked again on selection. */
	private static function catalog_where( string $alias, array &$values ): string {
		global $wpdb;
		$terms = wc_get_product_visibility_term_ids();
		$exclude = (int) ( $terms['exclude-from-catalog'] ?? 0 );
		$where = "{$alias}.post_type = 'product' AND {$alias}.post_status = 'publish' AND {$alias}.post_password = ''";
		if ( $exclude > 0 ) {
			$values[] = $exclude;
			$where .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = {$alias}.ID AND tt.taxonomy = 'product_visibility' AND tt.term_taxonomy_id = %d)";
		}
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			$stock = self::first_meta( $wpdb->postmeta, 'post_id', "{$alias}.ID", '_stock_status', $values );
			$where .= " AND COALESCE({$stock}, '') <> 'outofstock'";
		}
		return $where;
	}

	/** Full indexed time window aggregation; LIMIT applies only after parent grouping and ordering. */
	private static function events_sql( string $metric, int $from, int $to ): string {
		global $wpdb;
		$events = Schema::table( 'events' );
		$values = array( 'views' === $metric ? 'product_view' : 'add_to_cart', 'views' === $metric ? 'client' : 'server', $from * 1000, $to * 1000 );
		$where = self::catalog_where( 'p', $values );
		$sql = "SELECT p.ID AS product_id FROM {$events} e JOIN {$wpdb->posts} raw ON raw.ID = e.product_id AND raw.post_type IN ('product','product_variation') AND raw.post_status = 'publish' AND raw.post_password = '' JOIN {$wpdb->posts} p ON p.ID = CASE WHEN raw.post_type = 'product_variation' THEN raw.post_parent ELSE raw.ID END WHERE e.type = %s AND e.source = %s AND e.is_test = 0 AND e.occurred_ms >= %d AND e.occurred_ms < %d AND {$where} GROUP BY p.ID HAVING COUNT(*) > 0 ORDER BY COUNT(*) DESC, p.ID ASC LIMIT 100";
		return $wpdb->prepare( $sql, $values );
	}

	/**
	 * Authoritative CPT/HPOS facts, not asynchronously imported Analytics lookup tables.
	 * The original order window is creation GMT and current paid statuses. Refunds from any
	 * date reduce their original line, only while their current refund order exists/has a
	 * normal Woo status. A refund linked to a line from a different parent never counts.
	 */
	private static function sales_sql( int $from, int $to ): string {
		global $wpdb;
		$hpos = OrderUtil::custom_orders_table_usage_is_enabled();
		$store = ltrim( (string) \WC_Data_Store::load( 'order' )->get_current_class_name(), '\\' );
		if ( $store !== ( $hpos ? OrdersTableDataStore::class : 'WC_Order_Data_Store_CPT' ) ) { throw new \DomainException( 'unsupported_order_store' ); }
		$orders = $hpos ? OrdersTableDataStore::get_orders_table_name() : $wpdb->posts;
		$id = $hpos ? 'id' : 'ID'; $type = $hpos ? 'type' : 'post_type'; $status = $hpos ? 'status' : 'post_status';
		$created = $hpos ? 'date_created_gmt' : 'post_date_gmt'; $parent = $hpos ? 'parent_order_id' : 'post_parent';
		$items = $wpdb->prefix . 'woocommerce_order_items'; $meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$paid = array_values( array_unique( array_map( static fn( $s ) => 'wc-' . preg_replace( '/^wc-/', '', (string) $s ), wc_get_is_paid_statuses() ) ) );
		$refund_statuses = array_values( array_diff( array_keys( wc_get_order_statuses() ), array( 'trash', 'wc-trash', 'auto-draft', 'wc-auto-draft' ) ) ); // WC_Order_Query defaults, with trash/drafts explicitly excluded even if a filter registers them.
		if ( ! $paid || ! $refund_statuses ) { throw new \RuntimeException( 'Order statuses unavailable.' ); }
		$values = array();
		$product = self::first_meta( $meta, 'order_item_id', 'oi.order_item_id', '_product_id', $values );
		$qty = self::first_meta( $meta, 'order_item_id', 'oi.order_item_id', '_qty', $values );
		$refund_qty = self::first_meta( $meta, 'order_item_id', 'ri.order_item_id', '_qty', $values );
		$refund_marks = implode( ',', array_fill( 0, count( $refund_statuses ), '%s' ) );
		$values = array_merge( $values, $refund_statuses );
		$original_line = self::first_meta( $meta, 'order_item_id', 'ri.order_item_id', '_refunded_item_id', $values );
		$refund = "COALESCE((SELECT SUM(CAST({$refund_qty} AS DECIMAL(28,8))) FROM {$orders} ro JOIN {$items} ri ON ri.order_id = ro.{$id} AND ri.order_item_type = 'line_item' WHERE ro.{$type} = 'shop_order_refund' AND ro.{$parent} = oi.order_id AND ro.{$status} IN ({$refund_marks}) AND CAST({$original_line} AS UNSIGNED) = oi.order_item_id),0)";
		$paid_marks = implode( ',', array_fill( 0, count( $paid ), '%s' ) );
		$values = array_merge( $values, $paid, array( gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $to ) ) );
		$payment = $hpos ? 'o.payment_method_title' : self::first_meta( $wpdb->postmeta, 'post_id', 'o.ID', '_payment_method_title', $values );
		$values[] = $wpdb->esc_like( 'Test payment' ) . '%';
		$test_product = self::first_meta( $meta, 'order_item_id', 'ti.order_item_id', '_product_id', $values );
		$values[] = Meta::DEMO_SEED;
		$source = "SELECT CAST({$product} AS UNSIGNED) AS raw_product, CAST(TRUNCATE(CAST({$qty} AS DECIMAL(28,8)),0) AS SIGNED) - ABS(CAST(TRUNCATE({$refund},0) AS SIGNED)) AS net_units FROM {$orders} o JOIN {$items} oi ON oi.order_id = o.{$id} AND oi.order_item_type = 'line_item' WHERE o.{$type} = 'shop_order' AND o.{$status} IN ({$paid_marks}) AND o.{$created} >= %s AND o.{$created} < %s AND COALESCE({$payment},'') NOT LIKE BINARY %s AND NOT EXISTS (SELECT 1 FROM {$items} ti WHERE ti.order_id = o.{$id} AND ti.order_item_type = 'line_item' AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} demo WHERE demo.post_id = CAST({$test_product} AS UNSIGNED) AND demo.meta_key = %s AND demo.meta_value = '1'))";
		$where = self::catalog_where( 'p', $values );
		$sql = "SELECT p.ID AS product_id FROM ({$source}) facts JOIN {$wpdb->posts} raw ON raw.ID = facts.raw_product AND raw.post_type IN ('product','product_variation') JOIN {$wpdb->posts} p ON p.ID = CASE WHEN raw.post_type = 'product_variation' THEN raw.post_parent ELSE raw.ID END WHERE {$where} GROUP BY p.ID HAVING SUM(facts.net_units) > 0 ORDER BY SUM(facts.net_units) DESC, p.ID ASC LIMIT 100";
		return $wpdb->prepare( $sql, $values );
	}
}
