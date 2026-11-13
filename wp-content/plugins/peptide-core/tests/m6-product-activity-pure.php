<?php
/**
 * FE-03“看过 / 已加购”接口（Tracking\Product_Activity）：参数、开关、404、统计窗口、访客口径、下限、缓存、响应格式。
 * 纯逻辑，加载真正的类：php -n m6-product-activity-pure.php
 *
 * 同一组数据跑两遍：一遍用按语义实现的假数据库（任何 PHP 都能跑）；PHP 带 pdo_sqlite 时再用内存里的 SQLite
 * 把类里真正的 SQL 执行一遍，两遍的期望值相同。MySQL 上的行为由 m6-product-activity-smoke.php 在沙盒验证。
 */

namespace PeptideCore\Tracking {
	final class Schema {
		public static bool $installed = true;
		public static function installed(): bool { return self::$installed; }
		public static function table( string $name ): string { return 'test_' . $name; }
	}
	final class Tracker {
		public static bool $enabled = true;
		public static int $now_ms   = 1800000000000;
		public static function enabled(): bool { return self::$enabled; }
		public static function now_ms(): int { return self::$now_ms; }
	}
}

namespace PeptideCore\Api {
	final class Errors {
		public static function invalid( array $fields ): \WP_Error { return new \WP_Error( 'pc_invalid_param', 'Invalid request parameters.', array( 'status' => 400, 'fields' => $fields ) ); }
	}
}

namespace PeptideCore\Data {
	final class Metrics {
		public static function is_test_order( \WC_Order $order ): bool { return $order->test; }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );

	use PeptideCore\Tracking\Product_Activity;
	use PeptideCore\Tracking\Schema;
	use PeptideCore\Tracking\Tracker;

	$GLOBALS['t_options']    = array();
	$GLOBALS['t_transients'] = array();
	$GLOBALS['t_filters']    = array();
	$GLOBALS['t_posts']      = array();
	$GLOBALS['t_orders']     = array();
	$GLOBALS['t_passed']     = 0;

	final class WP_Error {
		public function __construct( public string $code, public string $message = '', public array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_data(): array { return $this->data; }
	}
	final class WP_REST_Request {
		public function __construct( private array $params = array() ) {}
		public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
	}
	final class WP_REST_Response {
		private array $headers = array();
		public function __construct( private $data = null, private int $status = 200 ) {}
		public function header( string $key, string $value ): void { $this->headers[ $key ] = $value; }
		public function get_headers(): array { return $this->headers; }
		public function get_status(): int { return $this->status; }
		public function get_data() { return $this->data; }
	}
	final class WC_Order {
		public function __construct( public bool $test ) {}
	}
	function __( $text, $domain = '' ) { return $text; }
	function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default; }
	function get_transient( $name ) { return $GLOBALS['t_transients'][ $name ] ?? false; }
	function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['t_transients'][ $name ] = $value; return true; }
	function delete_transient( $name ) { unset( $GLOBALS['t_transients'][ $name ] ); return true; }
	function add_filter( $tag, $fn, $priority = 10, $accepted = 1 ) { $GLOBALS['t_filters'][ $tag ][] = $fn; return true; }
	function add_action( $tag, $fn, $priority = 10, $accepted = 1 ) { return true; }
	function apply_filters( $tag, $value, ...$args ) {
		foreach ( $GLOBALS['t_filters'][ $tag ] ?? array() as $fn ) { $value = $fn( $value, ...$args ); }
		return $value;
	}
	function get_post( $id ) { return $GLOBALS['t_posts'][ (int) $id ] ?? null; }
	function wc_get_order( $id ) { return $GLOBALS['t_orders'][ (int) $id ] ?? false; }
	function t_check( string $name, $ok ): void {
		if ( ! $ok ) { fwrite( STDERR, "FAIL {$name}\n" ); exit( 1 ); }
		++$GLOBALS['t_passed'];
	}

	/** 两种数据库共用的部分：prepare、报错开关、查询计数。 */
	abstract class T_DB {
		public string $last_error = '';
		public string $prefix     = 'test_';
		public int $queries       = 0;
		public bool $broken       = false;
		private bool $suppressed  = false;
		public function suppress_errors( $on = true ) { $old = $this->suppressed; $this->suppressed = (bool) $on; return $old; }
		public function prepare( string $sql, ...$args ): string {
			foreach ( $args as $arg ) {
				$sql = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $sql, 1 );
			}
			return $sql;
		}
		abstract public function seed( array $visitors, array $visits, array $events ): void;
	}

	/** 按语义实现的假数据库：只认类里那两种查询，认不出来就报错。 */
	final class T_Fake_DB extends T_DB {
		private array $visitors = array();
		private array $visits   = array();
		private array $events   = array();
		public function seed( array $visitors, array $visits, array $events ): void { $this->visitors = $visitors; $this->visits = $visits; $this->events = $events; }
		public function get_results( string $sql, $output = null ) {
			++$this->queries;
			if ( $this->broken ) { $this->last_error = 'forced failure'; return null; }
			if ( preg_match( "/^SELECT e\\.product_id, COUNT\\(DISTINCT e\\.visitor_id\\) AS n FROM test_events e INNER JOIN test_visitors v ON v\\.id = e\\.visitor_id WHERE e\\.type = '(\\w+)' AND e\\.occurred_ms > (\\d+) AND e\\.occurred_ms <= (\\d+) AND e\\.is_test = 0 AND e\\.product_id > 0 AND v\\.is_test = 0(?: AND e\\.visitor_id NOT IN \\(([\\d,]+)\\))? GROUP BY e\\.product_id$/", $sql, $m ) ) {
				$skip = isset( $m[4] ) && '' !== $m[4] ? array_map( 'intval', explode( ',', $m[4] ) ) : array();
				$seen = array();
				foreach ( $this->events as $e ) {
					$visitor = $this->visitors[ $e['visitor_id'] ] ?? null;
					if ( $e['type'] !== $m[1] || $e['occurred_ms'] <= (int) $m[2] || $e['occurred_ms'] > (int) $m[3] || 0 !== $e['is_test'] || $e['product_id'] <= 0 || ! $visitor || 0 !== $visitor['is_test'] || in_array( $e['visitor_id'], $skip, true ) ) { continue; }
					$seen[ $e['product_id'] ][ $e['visitor_id'] ] = true;
				}
				$rows = array();
				foreach ( $seen as $product => $set ) { $rows[] = array( 'product_id' => (string) $product, 'n' => (string) count( $set ) ); }
				return $rows;
			}
			if ( preg_match( '/^SELECT visitor_id, order_id FROM test_visits WHERE last_ms >= (\d+) AND last_ms <= (\d+) AND order_id > 0 ORDER BY last_ms DESC, id DESC LIMIT (\d+)$/', $sql, $m ) ) {
				$rows = array_values( array_filter( $this->visits, static fn( $s ) => $s['last_ms'] >= (int) $m[1] && $s['last_ms'] <= (int) $m[2] && $s['order_id'] > 0 ) );
				usort( $rows, static fn( $a, $b ) => array( $b['last_ms'], $b['id'] ) <=> array( $a['last_ms'], $a['id'] ) );
				return array_map( static fn( $s ) => array( 'visitor_id' => (string) $s['visitor_id'], 'order_id' => (string) $s['order_id'] ), array_slice( $rows, 0, (int) $m[3] ) );
			}
			$this->last_error = 'unexpected query: ' . $sql;
			return null;
		}
	}

	/** 内存里的 SQLite：把类里真正的 SQL 执行一遍。 */
	final class T_Sqlite_DB extends T_DB {
		private \PDO $pdo;
		public function __construct() {
			$this->pdo = new \PDO( 'sqlite::memory:' );
			$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
			$this->pdo->exec( 'CREATE TABLE test_visitors (id INTEGER PRIMARY KEY, is_test INTEGER NOT NULL)' );
			$this->pdo->exec( 'CREATE TABLE test_visits (id INTEGER PRIMARY KEY, visitor_id INTEGER NOT NULL, last_ms INTEGER NOT NULL, order_id INTEGER NOT NULL)' );
			$this->pdo->exec( 'CREATE TABLE test_events (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, visitor_id INTEGER NOT NULL, product_id INTEGER NOT NULL, occurred_ms INTEGER NOT NULL, is_test INTEGER NOT NULL)' );
		}
		public function seed( array $visitors, array $visits, array $events ): void {
			foreach ( array( 'test_events', 'test_visits', 'test_visitors' ) as $table ) { $this->pdo->exec( "DELETE FROM {$table}" ); }
			foreach ( $visitors as $id => $v ) { $this->pdo->exec( sprintf( 'INSERT INTO test_visitors (id, is_test) VALUES (%d, %d)', $id, $v['is_test'] ) ); }
			foreach ( $visits as $s ) { $this->pdo->exec( sprintf( 'INSERT INTO test_visits (id, visitor_id, last_ms, order_id) VALUES (%d, %d, %d, %d)', $s['id'], $s['visitor_id'], $s['last_ms'], $s['order_id'] ) ); }
			foreach ( $events as $e ) { $this->pdo->exec( sprintf( "INSERT INTO test_events (type, visitor_id, product_id, occurred_ms, is_test) VALUES ('%s', %d, %d, %d, %d)", $e['type'], $e['visitor_id'], $e['product_id'], $e['occurred_ms'], $e['is_test'] ) ); }
		}
		public function get_results( string $sql, $output = null ) {
			++$this->queries;
			if ( $this->broken ) { $this->last_error = 'forced failure'; return null; }
			try {
				return $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_ASSOC );
			} catch ( \Throwable $e ) {
				$this->last_error = $e->getMessage();
				return null;
			}
		}
	}

	require dirname( __DIR__ ) . '/includes/tracking/class-product-activity.php';

	// ---------- 不碰数据库的部分 ----------
	t_check( 'product_id: a decimal string or a positive integer', 5 === Product_Activity::product_id( '5' ) && 5 === Product_Activity::product_id( 5 ) && 123456 === Product_Activity::product_id( '123456' ) );
	foreach ( array( '05', '5.0', '-1', '0', 'abc', '', ' 5', '5 ', "5\n", '1e3', 0, -3, 5.0, null, true, array( 5 ), str_repeat( '9', 19 ) ) as $bad ) {
		t_check( 'product_id: ' . json_encode( $bad ) . ' is not accepted', 0 === Product_Activity::product_id( $bad ) );
	}
	t_check( 'minimum: default 5; a whole number 1–1000 is kept; above 1000 is capped', 5 === Product_Activity::minimum() && 12 === Product_Activity::minimum( '12' ) && 1 === Product_Activity::minimum( 1 ) && 1000 === Product_Activity::minimum( 5000 ) );
	foreach ( array( 0, -2, 'x', '3.5', 3.5, '', null, array(), true ) as $bad ) {
		t_check( 'minimum: ' . json_encode( $bad ) . ' falls back to 5', 5 === Product_Activity::minimum( $bad ?? 'null' ) );
	}
	t_check( 'shown: a count below the minimum is reported as 0, at or above it as is', 0 === Product_Activity::shown( 4, 5 ) && 5 === Product_Activity::shown( 5, 5 ) && 31 === Product_Activity::shown( 31, 5 ) && 0 === Product_Activity::shown( 0, 1 ) );
	t_check( 'windows are inside the agreed ranges (1–60 minutes, 1–168 hours) and the cache is 60 seconds', Product_Activity::VIEWING_MINUTES >= 1 && Product_Activity::VIEWING_MINUTES <= 60 && Product_Activity::ADDED_HOURS >= 1 && Product_Activity::ADDED_HOURS <= 168 && 60 === Product_Activity::TTL );

	// ---------- 数据 ----------
	$now    = Tracker::$now_ms;
	$minute = 60000;
	$hour   = 3600000;
	// 访客 9 是测试版留下的（is_test = 1）；访客 10 的访客行已经被清掉；其余是正常访客。
	$visitors = array();
	foreach ( array( 1, 2, 3, 4, 5, 6, 7, 8, 11, 12, 13 ) as $id ) { $visitors[ $id ] = array( 'is_test' => 0 ); }
	$visitors[9] = array( 'is_test' => 1 );
	$visits = array(
		array( 'id' => 1, 'visitor_id' => 12, 'last_ms' => $now - 5 * $minute, 'order_id' => 900 ), // 测试单，窗口内。
		array( 'id' => 2, 'visitor_id' => 1, 'last_ms' => $now - 30 * $minute, 'order_id' => 901 ),  // 正常订单。
		array( 'id' => 3, 'visitor_id' => 2, 'last_ms' => $now - 48 * $hour, 'order_id' => 902 ),    // 测试单，但那次访问在窗口外。
		array( 'id' => 4, 'visitor_id' => 3, 'last_ms' => $now - 2 * $minute, 'order_id' => 0 ),     // 没有订单。
		array( 'id' => 5, 'visitor_id' => 13, 'last_ms' => $now - 3 * $minute, 'order_id' => 903 ),  // 订单已经被删掉。
	);
	$GLOBALS['t_orders'] = array( 900 => new WC_Order( true ), 901 => new WC_Order( false ), 902 => new WC_Order( true ) );
	$event  = static fn( string $type, int $visitor, int $product, int $ago_ms, int $is_test = 0 ): array => array( 'type' => $type, 'visitor_id' => $visitor, 'product_id' => $product, 'occurred_ms' => $now - $ago_ms, 'is_test' => $is_test );
	$events = array();
	// 商品 5：六个正常访客在 10 分钟内看过（访客 1 看了两次）。
	foreach ( array( 1, 2, 3, 4, 5, 6 ) as $i => $visitor ) { $events[] = $event( 'product_view', $visitor, 5, ( $i + 1 ) * $minute ); }
	$events[] = $event( 'product_view', 1, 5, 30000 );              // 同一个人再看一次：不重复数。
	$events[] = $event( 'product_view', 7, 5, 11 * $minute );       // 11 分钟前：窗口外。
	$events[] = $event( 'product_view', 8, 5, 10 * $minute );       // 正好 10 分钟前：窗口是左开右闭，不算。
	$events[] = $event( 'product_view', 9, 5, $minute );            // 测试版访客。
	$events[] = $event( 'product_view', 10, 5, $minute );           // 访客行已经没了。
	$events[] = $event( 'product_view', 11, 5, $minute, 1 );        // 事件本身是测试版的。
	$events[] = $event( 'product_view', 12, 5, $minute );           // 下过测试单的访客。
	$events[] = $event( 'product_view', 13, 5, -5000 );             // 时间在“现在”之后（时钟不准）：不算。
	$events[] = $event( 'page_view', 13, 5, $minute );              // 不是看商品的事件。
	$events[] = $event( 'product_view', 13, 0, $minute );           // 没有商品编号。
	// 商品 6：两个人看过（低于默认下限 5）。
	$events[] = $event( 'product_view', 1, 6, $minute );
	$events[] = $event( 'product_view', 2, 6, 2 * $minute );
	// 商品 5 的加购：五个正常访客在 24 小时内（访客 1 加了两次）。
	foreach ( array( 1, 2, 3, 4, 5 ) as $i => $visitor ) { $events[] = $event( 'add_to_cart', $visitor, 5, ( $i * 4 + 1 ) * $hour ); }
	$events[] = $event( 'add_to_cart', 1, 5, 20 * $hour );
	$events[] = $event( 'add_to_cart', 6, 5, 25 * $hour );          // 25 小时前：窗口外。
	$events[] = $event( 'add_to_cart', 12, 5, $hour );              // 下过测试单的访客。
	$events[] = $event( 'add_to_cart', 9, 5, $hour );               // 测试版访客。
	// 商品 7：一个人加购。
	$events[] = $event( 'add_to_cart', 3, 7, 2 * $hour );

	$post = static fn( string $type, string $status, string $password = '' ): object => (object) array( 'post_type' => $type, 'post_status' => $status, 'post_password' => $password );
	$GLOBALS['t_posts'] = array(
		5  => $post( 'product', 'publish' ),
		6  => $post( 'product', 'publish' ),
		7  => $post( 'product', 'publish' ),
		8  => $post( 'product', 'draft' ),
		9  => $post( 'page', 'publish' ),
		10 => $post( 'product', 'publish', 'secret' ),
		11 => $post( 'product_variation', 'publish' ),
		12 => $post( 'product', 'private' ),
	);

	$get    = static fn( $id ) => Product_Activity::rest( new WP_REST_Request( null === $id ? array() : array( 'product_id' => $id ) ) );
	$is_404 = static fn( $r, string $code ): bool => $r instanceof WP_REST_Response && 404 === $r->get_status() && $code === ( $r->get_data()['code'] ?? '' ) && array( 'code', 'message', 'data' ) === array_keys( $r->get_data() ) && 'public, max-age=60' === ( $r->get_headers()['Cache-Control'] ?? '' );
	$reset  = static function (): void {
		$GLOBALS['t_options']    = array( 'pc_product_activity' => 'on' );
		$GLOBALS['t_transients'] = array();
		$GLOBALS['t_filters']    = array();
		Schema::$installed       = true;
		Tracker::$enabled        = true;
		Tracker::$now_ms         = 1800000000000;
	};

	$engines = array( '假数据库' => new T_Fake_DB() );
	if ( extension_loaded( 'pdo_sqlite' ) ) {
		$engines['SQLite'] = new T_Sqlite_DB();
	}
	foreach ( $engines as $label => $db ) {
		$GLOBALS['wpdb'] = $db;
		$db->seed( $visitors, $visits, $events );
		$reset();
		$name = static fn( string $text ): string => "[{$label}] {$text}";

		// ---------- 开关和可用性 ----------
		$GLOBALS['t_options'] = array();
		$r                    = $get( '5' );
		t_check( $name( 'switch off (the default): 404, nothing is queried, nothing is cached' ), $is_404( $r, 'pc_product_activity_off' ) && 0 === $db->queries && array() === $GLOBALS['t_transients'] );
		$GLOBALS['t_options'] = array( 'pc_product_activity' => 'yes' );
		t_check( $name( 'only the value "on" switches it on' ), $is_404( $get( '5' ), 'pc_product_activity_off' ) );
		add_filter( 'peptide_core_product_activity_enabled', static fn() => true );
		t_check( $name( 'the filter can switch it on' ), 200 === $get( '5' )->get_status() );
		$reset();
		Tracker::$enabled = false;
		t_check( $name( 'visitor tracking off: 404 and the theme is told the endpoint is not available' ), $is_404( $get( '5' ), 'pc_product_activity_off' ) && false === Product_Activity::available() && false === Product_Activity::available( false ) );
		$reset();
		Schema::$installed = false;
		t_check( $name( 'tracking tables missing: 404, not available' ), $is_404( $get( '5' ), 'pc_product_activity_off' ) && false === Product_Activity::available() && null === Product_Activity::compute( $now ) );
		$reset();
		t_check( $name( 'available when the tables are there and tracking is on; an earlier true is kept' ), true === Product_Activity::available() && true === Product_Activity::available( true ) );
		Product_Activity::register_hooks();
		t_check( $name( 'register_hooks answers the theme filter pc_product_activity_available with true' ), true === apply_filters( 'pc_product_activity_available', false ) );
		$reset();

		// ---------- 参数和商品 ----------
		$before = $db->queries;
		foreach ( array( null, '', 'abc', '0', '-5', '5.5', array( '5' ) ) as $bad ) {
			$r = $get( $bad );
			t_check( $name( 'product_id ' . json_encode( $bad ) . ': 400 pc_invalid_param' ), $r instanceof WP_Error && 'pc_invalid_param' === $r->get_error_code() && 400 === $r->get_error_data()['status'] && 'invalid_format' === $r->get_error_data()['fields']['product_id'] );
		}
		foreach ( array( '99' => 'does not exist', '8' => 'is a draft', '9' => 'is not a product', '10' => 'is password protected', '11' => 'is a variation', '12' => 'is private' ) as $id => $why ) {
			t_check( $name( "product {$id} {$why}: 404" ), $is_404( $get( (string) $id ), 'pc_product_not_found' ) );
		}
		t_check( $name( 'bad parameters and unknown products never reach the events table' ), $before === $db->queries );

		// ---------- 数字 ----------
		$r    = $get( '5' );
		$want = array(
			'product_id'   => 5,
			'viewing'      => 6,
			'added'        => 5,
			'window'       => array( 'viewing_minutes' => 10, 'added_hours' => 24 ),
			'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z', intdiv( $now, 1000 ) ),
			'ttl'          => 60,
		);
		t_check( $name( 'product 5: 6 viewed in 10 minutes, 5 added in 24 hours, exact response and types' ), $r instanceof WP_REST_Response && 200 === $r->get_status() && $want === $r->get_data() );
		t_check( $name( 'the JSON has numbers, not strings, and only the agreed fields' ), '{"product_id":5,"viewing":6,"added":5,"window":{"viewing_minutes":10,"added_hours":24},"generated_at":"' . $want['generated_at'] . '","ttl":60}' === json_encode( $r->get_data() ) );
		t_check( $name( 'Cache-Control: public, max-age=60' ), array( 'Cache-Control' => 'public, max-age=60' ) === $r->get_headers() );
		t_check( $name( 'generated_at is an ISO 8601 UTC time' ), 1 === preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $r->get_data()['generated_at'] ) );
		$raw = Product_Activity::counts();
		t_check( $name( 'raw counts: only products 5, 6 and 7 have rows; test-version, cleared, future and test-order visitors are left out' ), array( 5 => 6, 6 => 2 ) == $raw['viewing'] && array( 5 => 5, 7 => 1 ) == $raw['added'] && 2 === count( $raw['viewing'] ) && 2 === count( $raw['added'] ) );
		$r = $get( '6' );
		t_check( $name( 'product 6: 2 viewers is below the default minimum of 5, so 0 is reported' ), 0 === $r->get_data()['viewing'] && 0 === $r->get_data()['added'] && 6 === $r->get_data()['product_id'] );
		$GLOBALS['t_options']['pc_product_activity_min'] = '2';
		t_check( $name( 'with the store minimum set to 2 the same product reports 2' ), 2 === $get( '6' )->get_data()['viewing'] && 0 === $get( '7' )->get_data()['added'] );
		$GLOBALS['t_options']['pc_product_activity_min'] = 1;
		t_check( $name( 'with the minimum at 1 a single add-to-cart is reported; a product with no activity is 0, not missing' ), 1 === $get( '7' )->get_data()['added'] && 0 === $get( '7' )->get_data()['viewing'] );
		unset( $GLOBALS['t_options']['pc_product_activity_min'] );

		// ---------- 缓存 ----------
		$queries = $db->queries;
		$get( '5' );
		$get( '6' );
		$get( '7' );
		t_check( $name( 'within 60 seconds every product is answered from the one cached map (no queries)' ), $queries === $db->queries );
		Tracker::$now_ms = $now + 59000;
		$get( '5' );
		t_check( $name( 'still cached after 59 seconds' ), $queries === $db->queries && $want['generated_at'] === $get( '5' )->get_data()['generated_at'] );
		Tracker::$now_ms = $now + 61000;
		$r               = $get( '5' );
		t_check( $name( 'recomputed after 60 seconds: three queries, a new generated_at' ), $queries + 3 === $db->queries && gmdate( 'Y-m-d\TH:i:s\Z', intdiv( $now + 61000, 1000 ) ) === $r->get_data()['generated_at'] );
		t_check( $name( 'a minute later the six viewers are still inside the window and the event that was 5 seconds in the future now counts too' ), 7 === Product_Activity::counts()['viewing'][5] );
		Tracker::$now_ms = $now + 5 * $minute;
		Product_Activity::flush();
		t_check( $name( 'five minutes later the viewers from 6 and 5 minutes ago (now 11 and 10 minutes) have left the window' ), 5 === Product_Activity::counts()['viewing'][5] );
		Tracker::$now_ms = $now;
		Product_Activity::flush();

		// ---------- 测试单 ----------
		$GLOBALS['t_orders'][900]->test = false;
		t_check( $name( 'when order 900 is not a test order its visitor counts again (7 viewed, 6 added)' ), 7 === $get( '5' )->get_data()['viewing'] && 6 === $get( '5' )->get_data()['added'] );
		$GLOBALS['t_orders'][900]->test = true;
		$GLOBALS['t_orders'][901]->test = true;
		Product_Activity::flush();
		t_check( $name( 'a visitor whose order in the window is a test order is left out of both numbers' ), 5 === Product_Activity::counts()['viewing'][5] && 4 === Product_Activity::counts()['added'][5] && 1 === Product_Activity::counts()['viewing'][6] );
		$GLOBALS['t_orders'][901]->test = false;
		Product_Activity::flush();

		// ---------- 数据库出错 ----------
		$db->broken = true;
		t_check( $name( 'database error: 404, and the failure is not cached' ), $is_404( $get( '5' ), 'pc_product_activity_unavailable' ) && array() === $GLOBALS['t_transients'] && null === Product_Activity::counts() );
		$db->broken     = false;
		$db->last_error = '';
		t_check( $name( 'works again once the database answers' ), 6 === $get( '5' )->get_data()['viewing'] );
		Product_Activity::flush();
	}

	echo 'PASS ', $GLOBALS['t_passed'], ' product activity (FE-03) assertions', isset( $engines['SQLite'] ) ? ' (fake database + SQLite)' : ' (fake database only: this PHP has no pdo_sqlite)', "\n";
}
