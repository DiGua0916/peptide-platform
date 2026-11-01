<?php
/** Pure production callbacks + in-memory SQL spy; no WordPress, database or network bootstrap. */
namespace PeptideCore\Tracking {
	final class Schema { public static function table( string $name ): string { return 'fixture_' . $name; } }
	final class Tracker {
		public static int $allocations = 0;
		public static int $visits = 0;
		public static array $stages = array();
		public static bool $staff = false;
		public static bool $declined = false;
		public static function current_vid(): string { return 'anonymous-fixture'; }
		public static function cookie_user_id(): int { return 0; }
		public static function is_staff( int $id ): bool { return self::$staff; }
		public static function declined(): bool { return self::$declined; }
		public static function now_ms(): int { return 1000; }
		public static function visitor( string $vid, int $now ): array { ++self::$allocations; return array( 'id' => 10, 'user_id' => 0 ); }
		public static function visit( int $visitor, int $now ): int { ++self::$visits; return 20; }
		public static function raise_stage( int $visit, int $stage ): void { self::$stages[] = array( $visit, $stage ); }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	class WP_REST_Request {
		public array $params = array( 'private' => 'fixture' );
		public function __construct( private string $method = 'POST', private string $route = '/wc/store/v1/cart/add-item' ) {}
		public function get_method(): string { return $this->method; }
		public function get_route(): string { return $this->route; }
	}
	class WC_Product { public function __construct( protected int $id ) {} public function get_id(): int { return $this->id; } }
	class WC_Product_Variation extends WC_Product { public function __construct( int $id, private int $parent ) { parent::__construct( $id ); } public function get_parent_id(): int { return $this->parent; } }
	$hooks = array();
	function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ) { global $hooks; $hooks[$hook][$priority][] = array( $callback, $accepted ); }
	function add_action( ...$args ) { add_filter( ...$args ); }
	function apply_filters( $hook, $value, ...$args ) { global $hooks; $callbacks = $hooks[$hook] ?? array(); ksort( $callbacks ); foreach ( $callbacks as $group ) { foreach ( $group as [ $callback, $accepted ] ) { $value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted ) ); } } return $value; }
	function do_action( $hook, ...$args ) { global $hooks; $callbacks = $hooks[$hook] ?? array(); ksort( $callbacks ); foreach ( $callbacks as $group ) { foreach ( $group as [ $callback, $accepted ] ) { $callback( ...array_slice( $args, 0, $accepted ) ); } } }
	function wc_get_product( $id ) { global $products, $lookup_throws; if ( $lookup_throws ) { throw new \RuntimeException( 'Synthetic product lookup failure' ); } return $products[$id] ?? false; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wp_generate_uuid4() { static $id = 0; return 'fixture-uuid-' . ++$id; }
	function get_the_title( $id ) { return 'Product ' . $id; }
	final class Cart_SQL_Spy {
		public array $events = array();
		public array $queries = array();
		public bool $insert_throws = false;
		private array $bindings = array();
		public function prepare( string $sql, ...$args ): string { $id = count( $this->bindings ); $this->bindings[$id] = $args; return '/*spy:' . $id . '*/' . $sql; }
		public function query( string $sql ): int {
			$this->queries[] = $sql;
			if ( str_contains( $sql, 'INSERT IGNORE INTO fixture_events' ) ) {
				if ( $this->insert_throws ) { throw new \RuntimeException( 'Synthetic Data insert failure' ); }
				preg_match( '/\A\/\*spy:(\d+)\*\//', $sql, $binding ); $args = $this->bindings[(int) $binding[1]];
				preg_match( '/\((event_uid,.*?)\) VALUES \((.*?)\)/', $sql, $match );
				$columns = array_map( 'trim', explode( ',', $match[1] ) ); $tokens = array_map( 'trim', explode( ',', $match[2] ) ); $row = array(); $i = 0;
				foreach ( $columns as $j => $column ) { $token = $tokens[$j]; $row[$column] = str_starts_with( $token, '%' ) ? $args[$i++] : ( is_numeric( $token ) ? (int) $token : trim( $token, "'" ) ); }
				$this->events[] = $row;
			} elseif ( ! str_contains( $sql, 'UPDATE fixture_visits' ) && ! str_contains( $sql, 'UPDATE fixture_visitors' ) ) { throw new \RuntimeException( 'Unexpected SQL shape' ); }
			return 1;
		}
	}
	$source = isset( $argv[1], $argv[2] ) && '--source' === $argv[1] ? $argv[2] : dirname( __DIR__ ) . '/includes/tracking/class-server-events.php';
	require $source;
	use PeptideCore\Tracking\Server_Events;
	use PeptideCore\Tracking\Tracker;
	Server_Events::register_hooks();
	$n = 0;
	$check = static function( string $name, bool $ok ): void { global $n; if ( ! $ok ) { throw new \RuntimeException( 'FAIL ' . $name ); } ++$n; };
	$reset = static function(): void { global $wpdb, $products, $lookup_throws; $wpdb = new Cart_SQL_Spy(); $lookup_throws = false; $products = array( 206 => new WC_Product( 206 ), 207 => new WC_Product_Variation( 207, 206 ), 208 => new WC_Product_Variation( 208, 999 ) ); Tracker::$allocations = 0; Tracker::$visits = 0; Tracker::$stages = array(); Tracker::$staff = false; Tracker::$declined = false; };
	$begin = static function( $request ) use ( $check ) { $before = clone $request; $response = new \stdClass(); $check( 'before response identity', apply_filters( 'rest_request_before_callbacks', $response, array(), $request ) === $response ); $data = array( 'id' => 206, 'quantity' => 1, 'cart_item_data' => new \stdClass() ); $check( 'filter exact data identity', apply_filters( 'woocommerce_store_api_add_to_cart_data', $data, $request ) === $data ); $check( 'request unchanged', $request == $before ); return $data; };
	$end = static function( $request ) use ( $check ): void { $response = new \stdClass(); $response->failed = true; $before = clone $request; $check( 'after response identity', apply_filters( 'rest_request_after_callbacks', $response, array(), $request ) === $response ); $check( 'cleanup request unchanged', $request == $before ); };
	$success = static function( $id = 206, $quantity = 1 ): void { do_action( 'internal_woocommerce_cart_item_added_from_user_request', $id, $quantity ); };
	$legacy = static function( $id = 206, $quantity = 1, $variation = 0 ): void { do_action( 'woocommerce_add_to_cart', 'fixture-cart', $id, $quantity, $variation, array(), array() ); };
	$reset(); $request = new WP_REST_Request(); $begin( $request ); $success( 206, 2 );
	$check( 'existing-item Store API success expected 1 server add, actual ' . count( $wpdb->events ), count( $wpdb->events ) === 1 );
	$row = $wpdb->events[0]; $data = json_decode( $row['data'], true );
	$check( 'canonical owner/product/source and this request delta', $row['visitor_id'] === 10 && $row['visit_id'] === 20 && $row['product_id'] === 206 && $row['variation_id'] === 0 && $row['source'] === 'server' && $row['type'] === 'add_to_cart' && $data === array( 'product_id' => 206, 'variation_id' => 0, 'quantity' => 2, 'product_name' => 'Product 206' ) );
	$check( 'stage two once', Tracker::$stages === array( array( 20, 2 ) ) ); $success(); $check( 'successful scope ended', count( $wpdb->events ) === 1 ); $end( $request );
	$reset(); $request = new WP_REST_Request(); $begin( $request ); $legacy(); $success(); $end( $request ); $check( 'new-item legacy plus internal once', count( $wpdb->events ) === 1 && Tracker::$allocations === 1 );
	$reset(); foreach ( array( 1, 3 ) as $quantity ) { $request = new WP_REST_Request(); $begin( $request ); $legacy( 206, $quantity ); $success( 206, $quantity ); $end( $request ); } $check( 'sequential batch with legacy each once', count( $wpdb->events ) === 2 && Tracker::$allocations === 2 );
	$reset(); $request = new WP_REST_Request(); $begin( $request ); $legacy( 206, 3 ); $success( 206, 1 ); $end( $request ); $check( 'normalized native quantity three raw request one records actual legacy once', count( $wpdb->events ) === 1 && json_decode( $wpdb->events[0]['data'], true )['quantity'] === 3 );
	$reset(); $request = new WP_REST_Request(); $begin( $request ); $legacy( 206, 1 ); $success( 207, 1 ); $end( $request ); $check( 'different variation legacy cannot suppress success', count( $wpdb->events ) === 2 );
	$reset(); foreach ( array( 1, 3 ) as $quantity ) { $request = new WP_REST_Request(); $begin( $request ); $success( 206, $quantity ); $end( $request ); } $check( 'sequential batch same item each once', count( $wpdb->events ) === 2 && json_decode( $wpdb->events[1]['data'], true )['quantity'] === 3 );
	$reset(); $request = new WP_REST_Request(); $begin( $request ); $end( $request ); $success(); $check( 'failed no success no fallback or identity', count( $wpdb->events ) === 0 && Tracker::$allocations === 0 ); $request = new WP_REST_Request(); $begin( $request ); $success(); $end( $request ); $check( 'clean next request after failure', count( $wpdb->events ) === 1 );
	$reset(); foreach ( array( new WP_REST_Request( 'GET' ), new WP_REST_Request( 'POST', '/wc/store/v1/cart/update-item' ), new WP_REST_Request( 'POST', '/wc/store/v1/batch' ), new WP_REST_Request( 'POST', '/wc/store/v2/cart/add-item' ) ) as $request ) { $begin( $request ); do_action( 'woocommerce_after_cart_item_quantity_update', 'key', 5, 2, null ); do_action( 'woocommerce_after_cart_item_quantity_update', 'key', 1, 5, null ); $success(); $end( $request ); } $check( 'unrelated route method quantity edit decrease ignored', count( $wpdb->events ) === 0 && Tracker::$allocations === 0 );
	$reset(); apply_filters( 'woocommerce_store_api_add_to_cart_data', array(), new \stdClass() ); $success(); $check( 'non-request cannot open scope', Tracker::$allocations === 0 );
	$legacy(); $legacy( 206, 3 ); $check( 'classic and programmatic legacy unchanged', count( $wpdb->events ) === 2 ); $request = new WP_REST_Request(); $begin( $request ); $success(); $end( $request ); $check( 'unpaired outside legacy never suppresses later Store API', count( $wpdb->events ) === 3 );
	$reset(); $request = new WP_REST_Request(); $begin( $request ); $success( 207, 0.25 ); $end( $request ); $row = $wpdb->events[0]; $check( 'variation real parent child fractional increment', $row['product_id'] === 206 && $row['variation_id'] === 207 && json_decode( $row['data'], true )['quantity'] === 0.25 );
	$reset(); $request = new WP_REST_Request(); $begin( $request ); $legacy( 206, 0.25, 207 ); $success( 207, 0.25 ); $end( $request ); $check( 'variation legacy paired exactly once', count( $wpdb->events ) === 1 );
	$reset(); foreach ( array( array( 999, 1 ), array( 0, 1 ), array( 208, 1 ), array( 206, 0 ), array( 206, -1 ), array( 206, INF ), array( 206, NAN ), array( 206, 'bad' ), array( 206, null ) ) as [ $id, $quantity ] ) { $request = new WP_REST_Request(); $begin( $request ); $success( $id, $quantity ); $end( $request ); } $check( 'invalid product quantity no fallback identity SQL', Tracker::$allocations === 0 && Tracker::$visits === 0 && $wpdb->queries === array() );
	foreach ( array( 'staff', 'declined' ) as $privacy ) { $reset(); Tracker::${$privacy} = true; $request = new WP_REST_Request(); $begin( $request ); $success(); $end( $request ); $check( $privacy . ' remains untracked', $wpdb->events === array() && Tracker::$allocations === 0 ); }
	$reset(); $outer = new WP_REST_Request(); $inner = new WP_REST_Request(); $begin( $outer ); $begin( $inner ); $legacy(); $success(); $end( $inner ); $success(); $end( $outer ); $check( 'nested exact requests own their legacy markers', count( $wpdb->events ) === 2 );
	$reset(); $outer = new WP_REST_Request(); $inner = new WP_REST_Request( 'POST', '/unrelated' ); $begin( $outer ); $begin( $inner ); $success(); $end( $inner ); $check( 'unrelated nested route cannot borrow outer scope', Tracker::$allocations === 0 ); $success(); $end( $outer ); $check( 'outer scope restored after nested unrelated request', count( $wpdb->events ) === 1 );
	$reset(); $outer = new WP_REST_Request(); $inner = new WP_REST_Request(); $begin( $outer ); $begin( $inner ); $end( $inner ); $success(); $end( $outer ); $check( 'failed nested child does not remove outer scope', count( $wpdb->events ) === 1 ); $success(); $check( 'no stale scope after cleanup', count( $wpdb->events ) === 1 );
	foreach ( array( 'lookup', 'Data SQL' ) as $failure ) {
		$reset(); $request = new WP_REST_Request(); $begin( $request );
		if ( 'lookup' === $failure ) { $lookup_throws = true; } else { $wpdb->insert_throws = true; }
		$success(); $check( $failure . ' observation exception does not escape successful cart hook', $wpdb->events === array() );
		$lookup_throws = false; $wpdb->insert_throws = false; $allocations = Tracker::$allocations; $success();
		$check( $failure . ' exception consumes scope before cleanup', $wpdb->events === array() && Tracker::$allocations === $allocations ); $end( $request );
		$request = new WP_REST_Request(); $begin( $request ); $success(); $end( $request ); $check( $failure . ' clean subsequent request records once', count( $wpdb->events ) === 1 );
	}
	echo 'PASS ', $n, " Store API native cart success assertions, 0 FAIL\n";
}
