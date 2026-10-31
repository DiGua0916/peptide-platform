<?php
/** Public, privacy-reduced views of the store's existing native shipment facts. */
namespace PeptideCore\Modules;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;
use PeptideCore\Shipping_Tracking;
use PeptideCore\Store_Config;

defined( 'ABSPATH' ) || exit;

final class Order_Fulfillment {

	private const PAGE_SIZE = 100;
	private const PAGE_BUDGET = 20;
	private const TIME_BUDGET = 3.0;
	private const UNITS = 100000000;
	private const PROVIDERS = array( 'ups' => 'UPS', 'usps' => 'USPS', 'fedex' => 'FedEx', 'dhl' => 'DHL', 'royal-mail' => 'Royal Mail', 'canada-post' => 'Canada Post', 'australia-post' => 'Australia Post', 'dpd' => 'DPD', 'gls' => 'GLS' );
	private static ?int $now = null;

	public static function definition(): array {
		return array(
			'title' => '订单履约看板', 'category' => 'operation', 'icon' => 'archive', 'singleRender' => true,
			'editorDefaults' => array( 'descriptionHtml' => '<p>Follow recent shipments. Customer details and tracking numbers stay private. Order values are shown as ranges.</p>' ),
			'settings' => array(
				Fields::f( 'text', 'title', '标题', 'Real-Time Tracking', array( 'panel' => 'content' ) ),
				Fields::f( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/order-fulfillment', 'group' => 'general', 'panel' => 'content' ) ),
				Fields::f( 'number', 'days', '已发货订单抓取范围', 7, array( 'min' => 1, 'max' => 90, 'step' => 1, 'ui' => 'range', 'unit' => '天', 'panel' => 'orders', 'help' => '按实际发货时间读取最近若干个24小时；没有合格订单时，前台隐藏此积木。' ) ),
				Fields::f( 'number', 'limit', '订单数量', 24, array( 'min' => 8, 'max' => 40, 'step' => 1, 'ui' => 'range', 'panel' => 'orders' ) ),
				Fields::f( 'select', 'shipIcon', '物流图标', 'box', array( 'options' => array( 'box' => '包裹', 'truck' => '货车', 'plane' => '飞机', 'ship' => '船舶' ), 'ui' => 'fulfillment-icons', 'fulfillmentIcons' => true, 'panel' => 'orders' ) ),
			),
			'panels' => array(
				array( 'id' => 'content', 'label' => '标题与描述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
				array( 'id' => 'orders', 'label' => '履约设置', 'initialOpen' => true, 'fields' => array( 'days', 'limit', 'shipIcon' ) ),
			),
		);
	}

	/** Gate before any shipment query. Context strings never confer access. */
	private static function blocked(): bool {
		return (bool) Store_Config::get( 'force_login' ) && ! is_user_logged_in();
	}

	/**
	 * No persistent projection/cache, public cursor, collector, native feature change or business write.
	 * SQL LIMIT bounds returned candidates, not native metadata scans; actual EXPLAIN remains required.
	 */
	public static function read( int $days, int $limit ): array {
		$empty = array( 'state' => 'unavailable', 'cards' => array() );
		if ( self::blocked() ) { return array( 'state' => 'locked', 'cards' => array() ); }
		if ( $days < 1 || $days > 90 || $limit < 8 || $limit > 40 || ! Shipping_Tracking::native_enabled() ) { return $empty; }
		$started = microtime( true );
		try {
			$store = self::store();
			$now = self::$now ??= time(); $from = $now - $days * DAY_IN_SECONDS;
			$cursor = null; $ranked = array(); $seen = array(); $bounds_valid = true;
			for ( $page = 0; $page < self::PAGE_BUDGET; ++$page ) {
				self::within_budget( $started );
				$rows = self::rows( self::candidate_sql( $store, $from, $now, $cursor ) );
				self::within_budget( $started );
				if ( count( $rows ) > self::PAGE_SIZE ) { throw new \RuntimeException( 'Invalid candidate page.' ); }
				if ( ! $rows ) { return self::rank_result( $ranked ); }
				$ids = array(); $bounds = array();
				$previous = null === $cursor ? null : array( 'date' => self::date( $cursor[0] ), 'order_id' => $cursor[1] );
				foreach ( $rows as $row ) {
					$id = self::positive_id( $row['order_id'] ?? null );
					$date = self::date( $row['latest_shipped_at'] ?? null );
					if ( ! $id || null === $date || $date < $from || $date > $now || isset( $bounds[ $id ] ) ) { throw new \RuntimeException( 'Invalid candidate.' ); }
					$upper = array( 'date' => $date, 'order_id' => $id );
					if ( null !== $previous && self::rank_compare( $upper, $previous ) <= 0 ) { throw new \RuntimeException( 'Invalid candidate order.' ); }
					$ids[] = $id; $bounds[ $id ] = $upper; $previous = $upper;
				}
				$orders = wc_get_orders( array( 'include' => $ids, 'type' => 'shop_order', 'status' => array( 'processing', 'completed' ), 'limit' => count( $ids ), 'return' => 'objects' ) );
				if ( ! is_array( $orders ) ) { throw new \RuntimeException( 'Order data unavailable.' ); }
				$by_id = array();
				foreach ( $orders as $order ) { if ( $order instanceof \WC_Order ) { $by_id[ $order->get_id() ] = $order; } }
				foreach ( $ids as $id ) {
					self::within_budget( $started );
					// SQL MAX is only an upper bound. An invalid newer parcel may lower this order's real rank.
					if ( $bounds_valid && count( $ranked ) === $limit && self::rank_compare( $bounds[ $id ], $ranked[ $limit - 1 ] ) >= 0 ) { return self::rank_result( $ranked ); }
					if ( isset( $seen[ $id ] ) ) { continue; } $seen[ $id ] = true;
					$order = $by_id[ $id ] ?? null;
					if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() || ! $order->has_status( array( 'processing', 'completed' ) ) ) { continue; }
					$total = self::units( $order->get_total() ); $refund = self::units( $order->get_total_refunded() );
					$currency = $order->get_currency();
					if ( null === $total || null === $refund || ( $refund > 0 && $refund >= $total ) || ! is_string( $currency ) || ! preg_match( '/^[A-Z]{3}$/D', $currency ) || ! isset( get_woocommerce_currencies()[ $currency ] ) ) { continue; }
					$shipment = self::shipment( $store, $order, $from, $now, $started );
					if ( ! $shipment ) { continue; }
					// A concurrent update can invalidate an earlier upper bound. Then require exhaustion,
					// rather than using that stale bound to justify an early result.
					if ( $shipment['date'] > $bounds[ $id ]['date'] ) { $bounds_valid = false; }
					$ranked[] = array( 'date' => $shipment['date'], 'order_id' => $id, 'card' => array(
						'alias' => self::alias( 'order', $id, $now ), 'customer' => 'Customer', 'status' => 'Shipped',
						'carrier' => self::PROVIDERS[ $shipment['provider'] ] ?? 'Carrier',
						'parcel' => self::alias( 'parcel', $shipment['id'], $now ),
						'amount' => self::amount( max( 0, $total - $refund ), $currency ), 'images' => $shipment['images'],
					) );
					usort( $ranked, array( self::class, 'rank_compare' ) );
					$ranked = array_slice( $ranked, 0, $limit );
				}
				$last = end( $rows ); $cursor = array( (string) $last['latest_shipped_at'], (int) $last['order_id'] );
				self::within_budget( $started );
				if ( count( $rows ) < self::PAGE_SIZE || ( $bounds_valid && count( $ranked ) === $limit && self::rank_compare( $previous, $ranked[ $limit - 1 ] ) >= 0 ) ) { return self::rank_result( $ranked ); }
			}
			return array( 'state' => 'budget', 'cards' => array() );
		} catch ( \LengthException $error ) {
			return array( 'state' => 'budget', 'cards' => array() );
		} catch ( \Throwable $error ) {
			return $empty;
		}
	}

	/** Negative means a sorts first. These internal facts never become public DTO fields. */
	private static function rank_compare( array $a, array $b ): int {
		return ( $b['date'] <=> $a['date'] ) ?: ( $b['order_id'] <=> $a['order_id'] );
	}

	private static function rank_result( array $ranked ): array {
		return array( 'state' => $ranked ? 'ready' : 'empty', 'cards' => array_column( $ranked, 'card' ) );
	}

	private static function within_budget( float $started ): void {
		if ( microtime( true ) - $started > self::TIME_BUDGET ) { throw new \LengthException( 'Read budget exhausted.' ); }
	}

	/** Only the active authoritative Woo store; never use its asynchronously synced backup. */
	private static function store(): array {
		global $wpdb;
		$hpos = OrderUtil::custom_orders_table_usage_is_enabled();
		$actual = ltrim( (string) \WC_Data_Store::load( 'order' )->get_current_class_name(), '\\' );
		if ( $actual !== ( $hpos ? OrdersTableDataStore::class : 'WC_Order_Data_Store_CPT' ) ) { throw new \RuntimeException( 'Unsupported order store.' ); }
		$native = ltrim( (string) \WC_Data_Store::load( 'order-fulfillment' )->get_current_class_name(), '\\' );
		if ( 'Automattic\\WooCommerce\\Admin\\Features\\Fulfillments\\DataStore\\FulfillmentsDataStore' !== $native ) { throw new \RuntimeException( 'Unsupported fulfillment store.' ); }
		$store = array( 'hpos' => $hpos, 'orders' => $hpos ? OrdersTableDataStore::get_orders_table_name() : $wpdb->posts, 'meta' => $wpdb->postmeta, 'fulfillments' => $wpdb->prefix . 'wc_order_fulfillments', 'fulfillment_meta' => $wpdb->prefix . 'wc_order_fulfillment_meta' );
		foreach ( array( 'orders', 'meta', 'fulfillments', 'fulfillment_meta' ) as $key ) { if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $store[ $key ] ) ) { throw new \RuntimeException( 'Invalid table identifier.' ); } }
		// No schema installation or option update: missing native tables are an unavailable source.
		$previous = $wpdb->suppress_errors( true );
		try {
			foreach ( array( 'orders', 'fulfillments', 'fulfillment_meta' ) as $key ) {
				$table = $store[ $key ];
				if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || '' !== (string) $wpdb->last_error ) { throw new \RuntimeException( 'Native table unavailable.' ); }
			}
		} finally { $wpdb->suppress_errors( $previous ); }
		return $store;
	}

	private static function rows( string $sql ): array {
		global $wpdb; $previous = $wpdb->suppress_errors( true );
		try {
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) { throw new \RuntimeException( 'Shipment data unavailable.' ); }
			return $rows;
		} finally { $wpdb->suppress_errors( $previous ); }
	}

	/** Native meta strings are JSON encoded; old bare strings are explicitly supported by its reader. */
	private static function decoded( string $alias ): string {
		return "CASE WHEN JSON_VALID({$alias}.meta_value) THEN JSON_UNQUOTE({$alias}.meta_value) ELSE {$alias}.meta_value END";
	}

	private static function valid_date_sql( string $date ): string {
		// STR_TO_DATE alone accepts some impossible calendar dates in supported MySQL versions.
		return "({$date}) REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$' AND CAST(SUBSTRING(({$date}),6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(({$date}),9,2) AS UNSIGNED) BETWEEN 1 AND DAY(LAST_DAY(CONCAT(LEFT(({$date}),7),'-01'))) AND CAST(SUBSTRING(({$date}),12,2) AS UNSIGNED)<24 AND CAST(SUBSTRING(({$date}),15,2) AS UNSIGNED)<60 AND CAST(SUBSTRING(({$date}),18,2) AS UNSIGNED)<60";
	}

	/** Qualification and per-order grouping happen before candidate LIMIT, then CRUD verifies again. */
	private static function candidate_sql( array $s, int $from, int $now, ?array $cursor ): string {
		global $wpdb; $f = $s['fulfillments']; $m = $s['fulfillment_meta']; $o = $s['orders']; $pm = $s['meta'];
		$id = $s['hpos'] ? 'id' : 'ID'; $type = $s['hpos'] ? 'type' : 'post_type'; $status = $s['hpos'] ? 'status' : 'post_status';
		$date = self::decoded( 'd' ); $valid_date = self::valid_date_sql( $date );
		if ( $s['hpos'] ) {
			$total = 'o.total_amount';
			$refund = "COALESCE(-(SELECT SUM(r.total_amount) FROM {$o} r WHERE r.type='shop_order_refund' AND r.parent_order_id=o.id),0)";
			$money = "o.total_amount IS NOT NULL AND o.total_amount>=0 AND NOT EXISTS(SELECT 1 FROM {$o} r WHERE r.type='shop_order_refund' AND r.parent_order_id=o.id AND (r.total_amount IS NULL OR r.total_amount>0))";
			$join = '';
		} else {
			$total = 'CAST(ot.meta_value AS DECIMAL(28,8))';
			$refund = "COALESCE((SELECT SUM(CAST(rm.meta_value AS DECIMAL(28,8))) FROM {$o} r JOIN {$pm} rm ON rm.post_id=r.ID AND rm.meta_key='_refund_amount' WHERE r.post_type='shop_order_refund' AND r.post_parent=o.ID),0)";
			$numeric = "'^[0-9]{1,10}([.][0-9]{1,8})?$'";
			$join = "JOIN {$pm} ot ON ot.post_id=o.ID AND ot.meta_key='_order_total'";
			$money = "ot.meta_value REGEXP {$numeric} AND NOT EXISTS(SELECT 1 FROM {$pm} dup WHERE dup.post_id=o.ID AND dup.meta_key='_order_total' AND dup.meta_id<>ot.meta_id) AND NOT EXISTS(SELECT 1 FROM {$o} r WHERE r.post_type='shop_order_refund' AND r.post_parent=o.ID AND ((SELECT COUNT(*) FROM {$pm} rm WHERE rm.post_id=r.ID AND rm.meta_key='_refund_amount')<>1 OR EXISTS(SELECT 1 FROM {$pm} rm WHERE rm.post_id=r.ID AND rm.meta_key='_refund_amount' AND rm.meta_value NOT REGEXP {$numeric})))";
		}
		$sql = "SELECT f.entity_id AS order_id,MAX({$date}) AS latest_shipped_at FROM {$f} f JOIN {$m} d ON d.fulfillment_id=f.fulfillment_id AND d.meta_key='_date_fulfilled' JOIN {$o} o ON o.{$id}=f.entity_id {$join} WHERE f.entity_type=%s AND f.entity_id>0 AND f.is_fulfilled=1 AND f.date_deleted IS NULL AND NOT EXISTS(SELECT 1 FROM {$m} dup WHERE dup.fulfillment_id=f.fulfillment_id AND dup.meta_key='_date_fulfilled' AND dup.meta_id<>d.meta_id) AND ({$valid_date}) AND ({$date})>=%s AND ({$date})<=%s AND o.{$type}='shop_order' AND o.{$status} IN ('wc-processing','wc-completed') AND ({$money}) AND ({$refund})>=0 AND NOT (({$refund})>0 AND ({$refund})>=({$total})) GROUP BY f.entity_id";
		$values = array( \WC_Order::class, gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $now ) );
		if ( null !== $cursor ) { $sql .= ' HAVING latest_shipped_at<%s OR (latest_shipped_at=%s AND f.entity_id<%d)'; array_push( $values, $cursor[0], $cursor[0], $cursor[1] ); }
		$sql .= ' ORDER BY latest_shipped_at DESC,f.entity_id DESC LIMIT %d'; $values[] = self::PAGE_SIZE;
		return $wpdb->prepare( $sql, $values );
	}

	/** Read all active shipped history for allocation checks, not only parcels inside the display window. */
	private static function shipment( array $s, \WC_Order $order, int $from, int $now, float $started ): ?array {
		global $wpdb; $f = $s['fulfillments']; $m = $s['fulfillment_meta'];
		$one = static function( string $key ) use ( $m ): string {
			return "(SELECT CASE WHEN COUNT(*)=1 THEN MAX(sm.meta_value) ELSE NULL END FROM {$m} sm WHERE sm.fulfillment_id=f.fulfillment_id AND sm.meta_key='{$key}')";
		};
		$sql = 'SELECT f.fulfillment_id,' . $one( '_date_fulfilled' ) . ' AS shipped_at,' . $one( '_shipment_provider' ) . ' AS provider_value,' . $one( '_items' ) . " AS items_value FROM {$f} f WHERE f.entity_type=%s AND f.entity_id=%d AND f.is_fulfilled=1 AND f.date_deleted IS NULL ORDER BY f.fulfillment_id DESC LIMIT 401";
		$rows = self::rows( $wpdb->prepare( $sql, \WC_Order::class, $order->get_id() ) );
		if ( count( $rows ) > 400 ) { throw new \LengthException( 'Shipment budget exhausted.' ); }
		$history = array(); $item_facts = array(); $totals = array(); $overallocated = array(); $seen = array();
		foreach ( $rows as $row ) {
			self::within_budget( $started );
			$fid = self::positive_id( $row['fulfillment_id'] ?? null );
			if ( ! $fid ) { continue; }
			if ( isset( $seen[ $fid ] ) ) { if ( $seen[ $fid ] !== $row ) { throw new \RuntimeException( 'Conflicting fulfillment identity.' ); } continue; }
			$seen[ $fid ] = $row;
			$date = self::date( self::meta( $row['shipped_at'] ?? null ) );
			if ( null === $date || $date > $now ) { continue; }
			$items = self::meta( $row['items_value'] ?? null );
			if ( ! is_array( $items ) || ! array_is_list( $items ) || ! $items ) { continue; }
			if ( count( $items ) > 500 ) { throw new \LengthException( 'Item budget exhausted.' ); }
			$allocation = array(); $valid = true;
			foreach ( $items as $raw ) {
				self::within_budget( $started );
				if ( ! is_array( $raw ) ) { $valid = false; break; }
				$item_id = self::positive_id( $raw['item_id'] ?? null );
				// Native validate_items accepts numeric JSON numbers, not strings or arbitrary numeric grammar.
				$qty = self::quantity_units( $raw['qty'] ?? null, true );
				if ( ! $item_id || null === $qty ) { $valid = false; break; }
				if ( ! array_key_exists( $item_id, $item_facts ) ) {
					$item = $order->get_item( $item_id ); $fact = null;
					if ( $item instanceof \WC_Order_Item_Product && (int) $item->get_order_id() === (int) $order->get_id() ) {
						$ordered = self::quantity_units( $item->get_quantity() ); $refunded = $order->get_qty_refunded_for_item( $item_id );
						// A later partial refund changes net money, not the historical ordered-quantity ceiling.
						if ( null !== $ordered && is_numeric( $refunded ) && is_finite( (float) $refunded ) && (float) $refunded <= 0 && -(float) $refunded <= $ordered / self::UNITS ) { $fact = array( 'ordered' => $ordered, 'product' => (int) $item->get_product_id() ); }
					}
					$item_facts[ $item_id ] = $fact;
				}
				$fact = $item_facts[ $item_id ]; $allocated = $allocation[ $item_id ] ?? 0;
				if ( null === $fact || $qty > $fact['ordered'] || $allocated > $fact['ordered'] - $qty ) { $valid = false; break; }
				$allocation[ $item_id ] = $allocated + $qty;
			}
			// Never clip a bad line or cherry-pick a good line to make an invalid fulfillment truthful.
			if ( ! $valid ) { continue; }
			foreach ( $allocation as $item_id => $qty ) {
				$total = $totals[ $item_id ] ?? 0;
				if ( isset( $overallocated[ $item_id ] ) || $total > $item_facts[ $item_id ]['ordered'] - $qty ) { $overallocated[ $item_id ] = true; }
				else { $totals[ $item_id ] = $total + $qty; }
			}
			$provider = self::meta( $row['provider_value'] ?? null );
			$history[] = array( 'id' => $fid, 'date' => $date, 'provider' => is_string( $provider ) && isset( self::PROVIDERS[ $provider ] ) ? $provider : '', 'items' => $allocation );
		}
		// Native pending allocation sums repeated item IDs across parcels. Ambiguous excess is not assigned
		// to an invented "first real" parcel; every parcel containing that overallocated item is withheld.
		$history = array_values( array_filter( $history, static function( array $parcel ) use ( $overallocated, $from ): bool {
			return $parcel['date'] >= $from && ! array_intersect_key( $parcel['items'], $overallocated );
		} ) );
		usort( $history, static function( array $a, array $b ): int { return ( $b['date'] <=> $a['date'] ) ?: ( $b['id'] <=> $a['id'] ); } );
		if ( ! $history ) { return null; }
		$images = array(); $products = array();
		foreach ( $history as $parcel ) {
			self::within_budget( $started );
			foreach ( $parcel['items'] as $item_id => $qty ) {
				self::within_budget( $started );
				$pid = $item_facts[ $item_id ]['product'];
				if ( count( $images ) >= 4 || isset( $products[ $pid ] ) ) { continue; } $products[ $pid ] = true;
				$product = wc_get_product( $pid );
				if ( ! Products::public_catalog_product( $product ) ) { continue; }
				$image = self::image( (int) $product->get_image_id() ); if ( $image ) { $images[] = $image; }
			}
		}
		return array( 'id' => $history[0]['id'], 'date' => $history[0]['date'], 'provider' => $history[0]['provider'], 'images' => $images );
	}

	/** Exact positive stock units up to 1e9 with at most eight decimal places; no clipping or rounding. */
	private static function quantity_units( $raw, bool $native = false ): ?int {
		if ( $native && ! is_int( $raw ) && ! is_float( $raw ) ) { return null; }
		if ( is_float( $raw ) ) {
			if ( ! is_finite( $raw ) || $raw <= 0 || $raw > 1000000000 ) { return null; }
			$decimal = number_format( $raw, 8, '.', '' );
			if ( (float) $decimal !== $raw ) { return null; } $raw = $decimal;
		}
		$units = self::units( $raw );
		return null !== $units && $units > 0 && $units <= 1000000000 * self::UNITS ? $units : null;
	}

	private static function meta( $raw ) {
		if ( ! is_string( $raw ) || strlen( $raw ) > 131072 ) { return null; }
		$value = json_decode( $raw, true ); return JSON_ERROR_NONE === json_last_error() ? $value : $raw;
	}

	private static function positive_id( $id ): ?int {
		if ( ( is_int( $id ) || is_string( $id ) ) && preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $id ) && (int) $id > 0 ) { return (int) $id; } return null;
	}

	private static function date( $raw ): ?int {
		if ( ! is_string( $raw ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $raw ) ) { return null; }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $raw, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		return $date && ( false === $errors || ( ! $errors['warning_count'] && ! $errors['error_count'] ) ) && $date->format( 'Y-m-d H:i:s' ) === $raw ? $date->getTimestamp() : null;
	}

	private static function units( $raw ): ?int {
		if ( is_float( $raw ) ) { if ( ! is_finite( $raw ) ) { return null; } $raw = number_format( $raw, 8, '.', '' ); }
		if ( ! is_int( $raw ) && ! is_string( $raw ) ) { return null; }
		if ( ! preg_match( '/^([0-9]{1,10})(?:[.]([0-9]{1,8}))?$/D', (string) $raw, $m ) ) { return null; }
		return (int) $m[1] * self::UNITS + (int) str_pad( $m[2] ?? '', 8, '0' );
	}

	private static function amount( int $net, string $currency ): string {
		$edges = array( 0, 50, 100, 250, 500, 1000 ); $bucket = 0;
		foreach ( $edges as $i => $edge ) { if ( $net >= $edge * self::UNITS ) { $bucket = $i; } }
		return $currency . ' ' . $edges[ $bucket ] . ( isset( $edges[ $bucket + 1 ] ) ? ' to <' . $edges[ $bucket + 1 ] : '+' );
	}

	private static function alias( string $namespace, int $id, int $now ): string {
		return 'VC-' . strtoupper( substr( hash_hmac( 'sha256', 'vc-public-' . $namespace . ':v1|' . get_current_blog_id() . '|' . $id . '|' . gmdate( 'Y-m-d', $now ), wp_salt( 'auth' ) ), 0, 16 ) );
	}

	/** Current public catalog images only; historical order data never grants media access. */
	private static function image( int $id ): ?array {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type || ! in_array( $post->post_status, array( 'inherit', 'publish' ), true ) || '' !== $post->post_password || ! wp_attachment_is_image( $id ) || metadata_exists( 'post', $id, '_pc_review_owner' ) || metadata_exists( 'post', $id, '_pc_review_file' ) ) { return null; }
		if ( $post->post_parent ) {
			$parent = get_post( $post->post_parent );
			if ( ! $parent || 'product' !== $parent->post_type || '' !== $parent->post_password || ! is_post_publicly_viewable( $parent ) || ! Products::public_catalog_product( wc_get_product( $parent->ID ) ) ) { return null; }
		}
		$src = wp_get_attachment_image_src( $id, 'thumbnail' );
		if ( ! is_array( $src ) || ! is_string( $src[0] ?? null ) || ! filter_var( $src[0], FILTER_VALIDATE_URL ) || ! preg_match( '~^https?://~i', $src[0] ) || (int) ( $src[1] ?? 0 ) < 1 || (int) ( $src[2] ?? 0 ) < 1 ) { return null; }
		return array( 'src' => $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2] );
	}

	public static function icon( string $name ): string {
		$paths = array(
			'box' => 'M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10M7 5l10 4',
			'truck' => 'M2 5h12v12H2zM14 9h4l4 5v3h-8M5 17a2 2 0 1 0 4 0 2 2 0 1 0-4 0M16 17a2 2 0 1 0 4 0 2 2 0 1 0-4 0M18 9v5h4',
			'plane' => 'M3 12l7-2 1-7 2 0 1 7 7 2v2l-7-1-1 6 3 2v1l-4-1-4 1v-1l3-2-1-6-7 1z',
			'ship' => 'M3 13l9-3 9 3-3 6H6zM7 11V5h10v6M10 5V2h4v3M2 21c2-2 3 2 5 0s3 2 5 0 3 2 5 0 3 2 5 0',
			'anonymous' => 'M8 8a4 4 0 1 0 8 0 4 4 0 1 0-8 0M4 21v-2a8 8 0 0 1 16 0v2M3 12l3 2M21 12l-3 2',
		);
		$path = $paths[ $name ] ?? $paths['box'];
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . $path . '" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	}

	public static function render( array $module, string $context ): string {
		if ( self::blocked() ) { return ''; }
		$s = $module['settings']; $data = self::read( (int) ( $s['days'] ?? 7 ), (int) ( $s['limit'] ?? 24 ) );
		if ( 'ready' !== $data['state'] ) {
			if ( ! in_array( $context, array( 'editor', 'preview' ), true ) ) { return ''; }
			$messages = array( 'empty' => '所选范围内没有合格的已发货订单，前台将隐藏此积木。', 'budget' => '读取未在预算内完成。请缩短抓取范围，或稍后重试；前台暂不显示此积木。', 'locked' => '请登录后查看。', 'unavailable' => '当前无法读取原生履约数据。请确认订单履约功能和数据表可用；此积木不会自行启用或安装。' );
			return '<div class="pc-cb vc-m__inner pc-order-fulfillment"><p class="pc-order-fulfillment__notice" role="status">' . esc_html( $messages[ $data['state'] ] ?? $messages['unavailable'] ) . '</p></div>';
		}
		$icon = in_array( $s['shipIcon'] ?? '', array( 'box', 'truck', 'plane', 'ship' ), true ) ? $s['shipIcon'] : 'box';
		$cards = '';
		foreach ( $data['cards'] as $card ) {
			$images = '';
			foreach ( $card['images'] as $image ) { $images .= '<img src="' . esc_url( $image['src'] ) . '" width="' . $image['width'] . '" height="' . $image['height'] . '" alt="" loading="lazy">'; }
			if ( '' === $images ) { $images = '<span class="pc-order-fulfillment__placeholder">' . self::icon( 'box' ) . '</span>'; }
			$amount = '<span class="pc-order-fulfillment__amount-label">Amount range</span><span class="pc-order-fulfillment__amount">' . esc_html( $card['amount'] ) . '</span>';
			$cards .= '<article class="pc-order-fulfillment__card" data-pc-fulfillment-card><div class="pc-order-fulfillment__left"><div class="pc-order-fulfillment__mosaic" data-images="' . count( $card['images'] ) . '">' . $images . '</div><div class="pc-order-fulfillment__text"><div class="pc-order-fulfillment__title"><h3>Order ' . esc_html( $card['alias'] ) . '</h3><span class="pc-order-fulfillment__badge" aria-label="At least one parcel shipped">Shipped</span></div><p class="pc-order-fulfillment__customer">' . self::icon( 'anonymous' ) . '<span>Customer</span></p><p class="pc-order-fulfillment__parcel">' . self::icon( $icon ) . '<span>' . esc_html( $card['carrier'] ) . '<br>Parcel ' . esc_html( $card['parcel'] ) . '</span></p><div class="pc-order-fulfillment__mobile-amount">' . $amount . '</div></div></div><div class="pc-order-fulfillment__right">' . $amount . '</div></article>';
		}
		$id = wp_unique_id( 'pc-fulfillment-' ); $title = (string) ( $s['title'] ?? '' ); $description = $s['descriptionHtml'] ?? '';
		$head = ( '' !== trim( $title ) ? '<h2>' . esc_html( $title ) . '</h2>' : '' ) . ( '' !== $description ? '<div class="pc-order-fulfillment__description">' . wp_kses_post( $description ) . '</div>' : '' );
		return '<div class="pc-cb vc-m__inner pc-order-fulfillment" data-pc-fulfillment>' . ( $head ? '<header class="pc-order-fulfillment__head">' . $head . '</header>' : '' ) . '<div id="' . esc_attr( $id ) . '" class="pc-order-fulfillment__grid" data-pc-fulfillment-grid role="group" aria-label="Recent shipped orders">' . $cards . '</div><nav class="pc-order-fulfillment__controls" data-pc-fulfillment-controls aria-label="Shipment pages" hidden><button type="button" data-pc-fulfillment-prev aria-controls="' . esc_attr( $id ) . '" aria-label="Previous shipment page">Previous</button><span data-pc-fulfillment-page role="status" aria-live="polite"></span><button type="button" data-pc-fulfillment-next aria-controls="' . esc_attr( $id ) . '" aria-label="Next shipment page">Next</button></nav></div>';
	}
}
