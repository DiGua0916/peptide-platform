<?php
/**
 * M6 / M7 纯逻辑测试共用的桩：不启动 WordPress、WooCommerce，也不连数据库。只被 m6-*-pure.php / m7-*-pure.php 引用，自己不是用例。
 *
 * - 选项、瞬态、钩子都存在 $GLOBALS 里，测试可以直接读写；
 * - WC_Order 是一个只带测试要用的字段的假订单，wc_get_orders() 按付款时间 / 创建时间 / 状态 / 订单号筛 $GLOBALS['m6_orders']；
 * - M6_DB 是假的 $wpdb：按 SQL 里的关键片段认出是哪一条查询，从内存里的访问 / 访客 / 事件算结果，认不出的查询直接抛异常。
 *   认得 Data\Metrics、Funnel_Guard 和 Data\Visitor_Funnel（八层漏斗，含按设备分组）的查询。
 */

namespace PeptideCore\Tracking {
	final class Schema {
		public static bool $installed = true;
		public static function installed(): bool { return self::$installed; }
		public static function table( string $name ): string { return 'test_' . $name; }
	}
	final class Server_Events {
		public const ORDER_VISITOR_META = '_pc_track_visitor';
		public const ORDER_VISIT_META   = '_pc_track_visit';
	}
}

namespace PeptideCore {
	final class Overview {
		public const RANGES = array( 'today', 'yesterday', '7d', '30d' );
		public static function is_test_order( $order ): bool { return (bool) $order->legacy_test; }
		public static function window( string $range, ?int $now = null ): array {
			return '7d' === $range ? array( 0, 700, 'day' ) : array( 100, 200, 'hour' );
		}
	}
	final class Test_Orders {
		public const META = '_pc_test_order';
		public static function is_test_order( $order ): bool { return $order instanceof \WC_Order && (bool) $order->ones; }
	}
	final class Recovery_Items {
		public static array $items = array();
		public static int $calls   = 0;
		public static bool $fail   = false;
		public static function list( array $f, int $page = 1, int $per = 50 ): array {
			++self::$calls;
			if ( self::$fail ) { throw new \RuntimeException( 'synthetic recovery failure' ); }
			return array( 'items' => self::$items, 'total' => count( self::$items ), 'counts' => array() );
		}
	}
	final class Store_Config {
		public static function get( string $key ) { return 'returning_hours' === $key ? 24 : null; }
	}
	final class Recovery {
		public static array $stats = array( 'emailed' => 0, 'recovered' => 0 );
		public static function stats( int $from_ms, int $to_ms ): array { return self::$stats; }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );

	$GLOBALS['m6_options']    = array();
	$GLOBALS['m6_transients'] = array();
	$GLOBALS['m6_orders']     = array();
	$GLOBALS['m6_filters']    = array();
	$GLOBALS['m6_actions']    = array();
	$GLOBALS['m6_fired']      = array();

	function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['m6_options'] ) ? $GLOBALS['m6_options'][ $name ] : $default; }
	function update_option( $name, $value, $autoload = null ) { $GLOBALS['m6_options'][ $name ] = $value; return true; }
	function delete_option( $name ) { unset( $GLOBALS['m6_options'][ $name ] ); return true; }
	function get_transient( $name ) { return $GLOBALS['m6_transients'][ $name ] ?? false; }
	function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['m6_transients'][ $name ] = $value; return true; }
	function add_filter( $tag, $fn, $priority = 10, $accepted = 1 ) { $GLOBALS['m6_filters'][ $tag ][] = $fn; return true; }
	function apply_filters( $tag, $value, ...$args ) {
		foreach ( $GLOBALS['m6_filters'][ $tag ] ?? array() as $fn ) { $value = call_user_func( $fn, $value, ...$args ); }
		return $value;
	}
	function add_action( $tag, $fn, $priority = 10, $accepted = 1 ) { $GLOBALS['m6_actions'][ $tag ][] = $fn; return true; }
	function do_action( $tag, ...$args ) {
		$GLOBALS['m6_fired'][] = array( $tag, $args );
		foreach ( $GLOBALS['m6_actions'][ $tag ] ?? array() as $fn ) { call_user_func( $fn, ...$args ); }
	}
	function __( $text, $domain = '' ) { return $text; }
	function wc_get_is_paid_statuses() { return array( 'processing', 'completed' ); }
	function wc_get_order_statuses() {
		return array( 'wc-pending' => 'Pending', 'wc-processing' => 'Processing', 'wc-on-hold' => 'On hold', 'wc-completed' => 'Completed', 'wc-cancelled' => 'Cancelled', 'wc-refunded' => 'Refunded', 'wc-failed' => 'Failed', 'wc-checkout-draft' => 'Draft' );
	}
	function get_woocommerce_currency() { return 'USD'; }
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
	function wp_date( $format, $timestamp = null ) { return gmdate( $format, null === $timestamp ? time() : (int) $timestamp ); }
	function wc_get_order( $id ) {
		$id = $id instanceof WC_Order ? $id->id : (int) $id;
		return $GLOBALS['m6_orders'][ $id ] ?? false;
	}
	function wc_get_orders( array $args ) {
		$GLOBALS['m6_order_queries'][] = $args;
		$range    = static function ( $spec ): array {
			$parts = explode( '...', (string) $spec );
			return array( (int) $parts[0], isset( $parts[1] ) ? (int) $parts[1] : PHP_INT_MAX );
		};
		$statuses = isset( $args['status'] ) ? array_map( static fn( $s ) => preg_replace( '/^wc-/', '', (string) $s ), (array) $args['status'] ) : null;
		$ids      = isset( $args['post__in'] ) ? array_map( 'intval', (array) $args['post__in'] ) : null;
		$out      = array();
		foreach ( $GLOBALS['m6_orders'] as $order ) {
			if ( null !== $statuses && ! in_array( 'any', $statuses, true ) && ! in_array( $order->status, $statuses, true ) ) { continue; }
			if ( null !== $ids && ! in_array( $order->id, $ids, true ) ) { continue; }
			if ( isset( $args['date_paid'] ) ) {
				[ $a, $b ] = $range( $args['date_paid'] );
				if ( null === $order->paid || $order->paid < $a || $order->paid > $b ) { continue; }
			}
			if ( isset( $args['date_created'] ) ) {
				[ $a, $b ] = $range( $args['date_created'] );
				if ( $order->created < $a || $order->created > $b ) { continue; }
			}
			$out[] = 'ids' === ( $args['return'] ?? 'objects' ) ? $order->id : $order;
		}
		return $out;
	}

	/** 假订单：只有指标用到的字段。paid = 付款时间（null 表示没付过款）。 */
	final class WC_Order {
		public bool $legacy_test = false; // 测试付款方式 / 演示商品（Overview 的旧规则）。
		public bool $ones        = false; // 收件信息全 1（Test_Orders）。
		public float $wc_refund  = 0.0;
		public string $email     = '';
		public int $customer     = 0;
		public array $meta       = array();
		public array $notes      = array();
		// 排行（Data\Rankings）要读的：支付方式、账单国家、优惠券、订单行。
		public string $method       = 'card';
		public string $method_title = 'Card';
		public string $country      = 'US';
		public array $coupons       = array();
		public array $items         = array();
		public function __construct( public int $id, public string $status = 'processing', public float $total = 20.0, public ?int $paid = 150, public int $created = 150 ) {}
		public function get_id() { return $this->id; }
		public function get_status() { return $this->status; }
		public function has_status( $status ) { return in_array( $this->status, (array) $status, true ); }
		public function is_paid() { return in_array( $this->status, wc_get_is_paid_statuses(), true ); }
		public function get_total() { return $this->total; }
		public function get_total_refunded() { return $this->wc_refund; }
		public function get_date_paid() { return null === $this->paid ? null : new DateTimeImmutable( '@' . $this->paid ); }
		public function get_date_created() { return new DateTimeImmutable( '@' . $this->created ); }
		public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function save_meta_data() {}
		public function get_customer_id() { return $this->customer; }
		public function get_billing_email() { return $this->email; }
		public function get_currency() { return 'USD'; }
		public function get_order_number() { return (string) $this->id; }
		public function get_payment_method() { return $this->method; }
		public function get_payment_method_title() { return $this->method_title; }
		public function get_billing_country() { return $this->country; }
		public function get_coupon_codes() { return $this->coupons; }
		public function get_items() { return $this->items; }
		public function get_qty_refunded_for_item( $item_id ) { return 0; }
		public function get_total_refunded_for_item( $item_id ) { return 0.0; }
	}

	/** 假订单行：商品、数量、小计。 */
	final class WC_Order_Item_Product {
		public function __construct( public int $id, public int $product, public int $quantity, public float $subtotal ) {}
		public function get_id() { return $this->id; }
		public function get_product_id() { return $this->product; }
		public function get_quantity() { return $this->quantity; }
		public function get_subtotal() { return $this->subtotal; }
	}

	/** 给订单挂上访客 / 访问（下单时 Server_Events 写的两条元数据）。 */
	function m6_order( int $id, string $status = 'processing', float $total = 20.0, ?int $paid = 150, int $created = 150, int $visitor = 0, int $visit = 0 ): WC_Order {
		$order = new WC_Order( $id, $status, $total, $paid, $created );
		if ( $visitor ) { $order->meta['_pc_track_visitor'] = $visitor; }
		if ( $visit ) { $order->meta['_pc_track_visit'] = $visit; }
		$GLOBALS['m6_orders'][ $id ] = $order;
		return $order;
	}

	/** 假 $wpdb。visits：id => [visitor_id, stage, order_id, start_ms, is_test]；visitors：id => [is_test]；events：[visit_id, type, source, order_id]。 */
	final class M6_DB {
		public string $last_error = '';
		public string $prefix     = 'test_';
		public array $visits      = array();
		public array $visitors    = array();
		public array $events      = array();
		public array $queries     = array();
		public array $devices     = array(); // 访问编号 => 设备（Visitor_Funnel 按设备分组时用；没填就是空字符串）。

		public function visit( int $id, int $visitor, int $stage = 0, int $order = 0, int $start_ms = 150000, int $is_test = 0 ): void {
			$this->visits[ $id ] = array( 'visitor_id' => $visitor, 'stage' => $stage, 'order_id' => $order, 'start_ms' => $start_ms, 'is_test' => $is_test );
			if ( ! isset( $this->visitors[ $visitor ] ) && $visitor < 90 ) { $this->visitors[ $visitor ] = array( 'is_test' => 0 ); }
		}
		public function event( int $visit, string $type, string $source = 'client', int $order = 0 ): void {
			$this->events[] = array( 'visit_id' => $visit, 'type' => $type, 'source' => $source, 'order_id' => $order );
		}
		public function prepare( string $sql, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
			foreach ( $args as $arg ) {
				$sql = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 );
			}
			return $sql;
		}
		private function window( string $sql ): array {
			return preg_match( '/start_ms >= (\d+) AND (?:s\.)?start_ms < (\d+)/', $sql, $m ) ? array( (int) $m[1], (int) $m[2] ) : array( 0, PHP_INT_MAX );
		}
		/** 时间段内的访问；$real 为 true 时只要 is_test = 0 且访客行还在的。 */
		private function cohort( string $sql, bool $real ): array {
			[ $from, $to ] = $this->window( $sql );
			return array_filter(
				$this->visits,
				function ( array $v ) use ( $from, $to, $real ): bool {
					if ( $v['start_ms'] < $from || $v['start_ms'] >= $to ) { return false; }
					if ( ! $real ) { return true; }
					$visitor = $this->visitors[ $v['visitor_id'] ] ?? null;
					return null !== $visitor && 0 === (int) $v['is_test'] && 0 === (int) $visitor['is_test'];
				}
			);
		}
		private function ids( array $visits ): array {
			return array_values( array_unique( array_column( $visits, 'visitor_id' ) ) );
		}
		public function get_var( string $sql ) {
			$this->queries[] = $sql;
			if ( str_contains( $sql, 'NOT IN' ) ) { // 只有测试标记访问的访客。
				$real = $this->ids( $this->cohort( $sql, true ) );
				$flag = array_filter( $this->cohort( $sql, false ), fn( array $v ): bool => isset( $this->visitors[ $v['visitor_id'] ] ) && ( 0 !== (int) $v['is_test'] || 0 !== (int) $this->visitors[ $v['visitor_id'] ]['is_test'] ) );
				return count( array_diff( $this->ids( $flag ), $real ) );
			}
			if ( str_contains( $sql, 'v.id IS NULL' ) ) { // 访客行已经不在的访问。
				return count( $this->ids( array_filter( $this->cohort( $sql, false ), fn( array $v ): bool => ! isset( $this->visitors[ $v['visitor_id'] ] ) ) ) );
			}
			if ( str_contains( $sql, 'COUNT(DISTINCT s.visitor_id)' ) && str_contains( $sql, 's.is_test = 0 AND v.is_test = 0' ) ) {
				return count( $this->ids( $this->cohort( $sql, true ) ) );
			}
			if ( str_contains( $sql, 'SELECT COUNT(DISTINCT visitor_id) FROM test_visits WHERE start_ms' ) ) {
				return count( $this->ids( $this->cohort( $sql, false ) ) );
			}
			if ( str_contains( $sql, "type = 'checkout_address_completed'" ) && preg_match( '/visit_id = (\d+)/', $sql, $m ) ) { // Funnel_Guard：这次访问有没有填地址 / 有几条。
				$n = count( array_filter( $this->events, static fn( array $e ): bool => $e['visit_id'] === (int) $m[1] && 'checkout_address_completed' === $e['type'] ) );
				return str_contains( $sql, 'COUNT(*)' ) ? $n : ( $n ? 1 : null );
			}
			throw new RuntimeException( 'Unexpected get_var: ' . $sql );
		}
		/** Visitor_Funnel 的时间段：带 s.is_test = 0 时只要正式访问，否则和旧报表一样全算。 */
		private function funnel_cohort( string $sql ): array {
			return $this->cohort( $sql, str_contains( $sql, 'AND s.is_test = 0' ) );
		}
		/** 前四层：stage ≥ 0 / 1 / 2 / 3 的不同访客数；回访一律当作 0。 */
		private function stage_row( array $visits ): array {
			$row = array();
			foreach ( array( 'visitors' => 0, 'viewed' => 1, 'carted' => 2, 'checkout' => 3 ) as $key => $stage ) {
				$row[ $key ]        = count( $this->ids( array_filter( $visits, static fn( array $v ): bool => $v['stage'] >= $stage ) ) );
				$row[ 'r_' . $key ] = 0;
			}
			return $row;
		}
		public function get_row( string $sql, $mode = null ) {
			$this->queries[] = $sql;
			if ( str_contains( $sql, 'COUNT(DISTINCT CASE WHEN s.stage >= 0 THEN s.visitor_id END) AS visitors' ) ) { // Visitor_Funnel：前四层。
				return $this->stage_row( $this->funnel_cohort( $sql ) );
			}
			throw new RuntimeException( 'Unexpected get_row: ' . $sql );
		}
		public function get_results( string $sql, $mode = null ) {
			$this->queries[] = $sql;
			if ( str_contains( $sql, 'GROUP BY s.device' ) ) { // Visitor_Funnel：前四层按设备分组。
				$groups = array();
				foreach ( $this->funnel_cohort( $sql ) as $id => $v ) { $groups[ $this->devices[ $id ] ?? '' ][ $id ] = $v; }
				$rows = array();
				foreach ( $groups as $device => $visits ) { $rows[] = array( 'g' => (string) $device ) + $this->stage_row( $visits ); }
				return $rows;
			}
			if ( str_contains( $sql, 'SELECT e.id,e.type,e.source,e.visit_id,e.visitor_id,e.order_id,s.order_id AS linked_order' ) ) { // Visitor_Funnel：后四层的事件，每页 500 条。
				$cohort = $this->funnel_cohort( $sql );
				$cursor = preg_match( '/e\.id > (\d+)/', $sql, $m ) ? (int) $m[1] : 0;
				$rows   = array();
				foreach ( $this->events as $index => $e ) {
					$v    = $cohort[ $e['visit_id'] ] ?? null;
					$step = 'client' === $e['source'] ? in_array( $e['type'], array( 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected' ), true ) : ( 'server' === $e['source'] && 'payment_success' === $e['type'] );
					if ( ! $v || ! $step || $index + 1 <= $cursor || ! isset( $this->visitors[ $v['visitor_id'] ] ) ) { continue; }
					$rows[] = array( 'id' => $index + 1, 'type' => $e['type'], 'source' => $e['source'], 'visit_id' => $e['visit_id'], 'visitor_id' => $v['visitor_id'], 'order_id' => $e['order_id'], 'linked_order' => $v['order_id'], 'g' => (string) ( $this->devices[ $e['visit_id'] ] ?? '' ), 'returning_visit' => 0 );
				}
				return array_slice( $rows, 0, 500 );
			}
			if ( str_contains( $sql, 'SELECT s.id, s.visitor_id, s.stage, s.order_id' ) ) {
				$cursor = preg_match( '/s\.id > (\d+)/', $sql, $m ) ? (int) $m[1] : 0;
				$rows   = array();
				foreach ( $this->cohort( $sql, true ) as $id => $v ) {
					if ( $id > $cursor ) { $rows[] = array( 'id' => $id, 'visitor_id' => $v['visitor_id'], 'stage' => $v['stage'], 'order_id' => $v['order_id'] ); }
				}
				usort( $rows, static fn( $a, $b ) => $a['id'] <=> $b['id'] );
				return array_slice( $rows, 0, 1000 );
			}
			// 只认 Metrics 的“三种步骤事件”查询。Funnel_Guard::anomalies() 的查询开头也是 SELECT DISTINCT e.visit_id, e.type，
			// 但带 NOT EXISTS，由下面的分支处理（第一次运行时它被这里误认，返回了没有 order_id 的行）。
			if ( str_contains( $sql, 'SELECT DISTINCT e.visit_id, e.type FROM' ) && ! str_contains( $sql, 'NOT EXISTS' ) ) {
				$cohort = $this->cohort( $sql, false );
				$rows   = array();
				foreach ( $this->events as $e ) {
					$v = $cohort[ $e['visit_id'] ] ?? null;
					if ( ! $v || $v['stage'] < 3 || ! in_array( $e['type'], array( 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected' ), true ) ) { continue; }
					$rows[ $e['visit_id'] . '|' . $e['type'] ] = array( 'visit_id' => $e['visit_id'], 'type' => $e['type'] );
				}
				return array_values( $rows );
			}
			if ( str_contains( $sql, 'SELECT DISTINCT e.visit_id, e.order_id FROM' ) ) { // Metrics：走到已付款的访问，付款事件里的订单号。
				$cohort = $this->cohort( $sql, false );
				$rows   = array();
				foreach ( $this->events as $e ) {
					$v = $cohort[ $e['visit_id'] ] ?? null;
					if ( ! $v || $v['stage'] < 5 || 'server' !== $e['source'] || 'payment_success' !== $e['type'] || $e['order_id'] <= 0 ) { continue; }
					$rows[ $e['visit_id'] . '|' . $e['order_id'] ] = array( 'visit_id' => $e['visit_id'], 'order_id' => $e['order_id'] );
				}
				return array_values( $rows );
			}
			if ( str_contains( $sql, "source = 'server'" ) && preg_match( '/visit_id = (\d+)/', $sql, $m ) ) { // Funnel_Guard::settle()：这次访问的下单 / 付款事件。
				return array_values( array_map( static fn( array $e ): array => array( 'type' => $e['type'], 'order_id' => $e['order_id'] ), array_filter( $this->events, static fn( array $e ): bool => $e['visit_id'] === (int) $m[1] && 'server' === $e['source'] && in_array( $e['type'], array( 'order_placed', 'payment_success' ), true ) ) ) );
			}
			if ( str_contains( $sql, 'NOT EXISTS' ) ) { // Funnel_Guard::anomalies()：有下单 / 付款事件、却没有填地址的访问。
				$cohort = $this->cohort( $sql, false );
				$out    = array();
				foreach ( $this->events as $e ) {
					if ( 'server' !== $e['source'] || ! in_array( $e['type'], array( 'order_placed', 'payment_success' ), true ) || ! isset( $cohort[ $e['visit_id'] ] ) ) { continue; }
					$has = array_filter( $this->events, static fn( array $x ): bool => $x['visit_id'] === $e['visit_id'] && 'checkout_address_completed' === $x['type'] );
					if ( ! $has ) { $out[ $e['visit_id'] . '|' . $e['type'] ] = array( 'visit_id' => $e['visit_id'], 'type' => $e['type'], 'order_id' => $e['order_id'] ); }
				}
				return array_values( $out );
			}
			throw new RuntimeException( 'Unexpected get_results: ' . $sql );
		}
		public function query( string $sql ) {
			$this->queries[] = $sql;
			if ( preg_match( '/SET stage = GREATEST\(stage, (\d+)\), order_id = (\d+) WHERE id = (\d+)/', $sql, $m ) ) {
				$this->visits[ (int) $m[3] ]['stage']    = max( (int) $this->visits[ (int) $m[3] ]['stage'], (int) $m[1] );
				$this->visits[ (int) $m[3] ]['order_id'] = (int) $m[2];
				return 1;
			}
			if ( preg_match( '/SET stage = GREATEST\(stage, (\d+)\) WHERE id = (\d+)/', $sql, $m ) ) {
				$this->visits[ (int) $m[2] ]['stage'] = max( (int) $this->visits[ (int) $m[2] ]['stage'], (int) $m[1] );
				return 1;
			}
			if ( preg_match( '/SET order_id = (\d+) WHERE id = (\d+)/', $sql, $m ) ) {
				$this->visits[ (int) $m[2] ]['order_id'] = (int) $m[1];
				return 1;
			}
			throw new RuntimeException( 'Unexpected query: ' . $sql );
		}
	}

	/** 断言：失败就抛异常（进程以非 0 退出），成功计数。 */
	$GLOBALS['m6_passed'] = 0;
	function m6_check( string $name, $ok ): void {
		if ( ! $ok ) { throw new RuntimeException( 'FAILED: ' . $name ); }
		++$GLOBALS['m6_passed'];
	}
}
