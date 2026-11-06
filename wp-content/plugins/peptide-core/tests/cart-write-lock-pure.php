<?php
/**
 * Stress test item 1: concurrent Store API cart writes for one session are
 * serialized. Pure model with WordPress/MySQL boundaries stubbed; the real
 * GET_LOCK concurrency must still be verified on the MySQL sandbox.
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' ); define( 'DB_NAME', 'shop_db' ); define( 'COOKIEHASH', 'abc' );
	$checks = 0; $hooks = array(); $user = 0;
	function check( string $label, bool $ok ): void { global $checks; if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } ++$checks; }
	function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ] = array( $cb, $priority ); }
	function add_action( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ] = array( $cb, $priority ); }
	function apply_filters( $hook, $value ) { return $value; }
	function get_current_user_id() { return $GLOBALS['user']; }
	function wp_unslash( $value ) { return $value; }
	function __( $text, $domain = '' ) { return $text; }
	class WP_Error { public function __construct( public $code = '', public $message = '', public $data = array() ) {} }
	class WP_REST_Request { public function __construct( private string $method, private string $route ) {} public function get_method() { return $this->method; } public function get_route() { return $this->route; } }
	final class Lock_DB {
		public string $prefix = 'wp_'; public array $queries = array(); public array $held = array(); public $reply = null;
		public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
		public function get_var( $prepared ) {
			[ $sql, $args ] = $prepared; $this->queries[] = $sql;
			if ( str_starts_with( $sql, 'SELECT GET_LOCK' ) ) {
				if ( null !== $this->reply ) { return $this->reply; }
				if ( isset( $this->held[ $args[0] ] ) ) { return '0'; } // held by another connection until timeout
				$this->held[ $args[0] ] = true; return '1';
			}
			if ( str_starts_with( $sql, 'SELECT RELEASE_LOCK' ) ) { unset( $this->held[ $args[0] ] ); return '1'; }
			return null;
		}
	}
	$wpdb = new Lock_DB();
	require dirname( __DIR__ ) . '/includes/class-cart-write-lock.php';
	use PeptideCore\Cart_Write_Lock as Lock;
	$cookie = 'wp_woocommerce_session_abc';
	$reset = static function () use ( $cookie ) { global $wpdb, $user; Lock::reset_for_tests(); $wpdb->queries = array(); $wpdb->held = array(); $wpdb->reply = null; $user = 0; unset( $_SERVER['HTTP_CART_TOKEN'] ); $_COOKIE = array( $cookie => 'guest123||1999999999||1999990000||hash' ); };
	$dispatch = static fn( string $method, string $route ) => Lock::before_dispatch( null, null, new WP_REST_Request( $method, $route ) );

	Lock::register_hooks();
	check( 'pre-dispatch hook registered', array( array( Lock::class, 'before_dispatch' ), 5 ) === $hooks['rest_pre_dispatch'] );
	check( 'release runs after WooCommerce session save (shutdown 20)', $hooks['shutdown'][1] > 20 );

	foreach ( array( '/wc/store/v1/cart/add-item', '/wc/store/cart/add-item', '/wc/store/v1/cart/update-item', '/wc/store/v1/batch', '/wc/store/v1/checkout', '/WC/Store/V1/Cart/remove-item' ) as $route ) {
		$reset(); $r = $dispatch( 'POST', $route );
		check( "write locked: $route", null === $r && 1 === count( $wpdb->held ) );
		Lock::release(); check( "released: $route", array() === $wpdb->held );
	}
	foreach ( array( array( 'GET', '/wc/store/v1/cart' ), array( 'POST', '/wc/store/v1/products' ), array( 'POST', '/peptide/v1/track/events' ), array( 'POST', '/wc/store/v1/cartx' ), array( 'OPTIONS', '/wc/store/v1/cart/add-item' ) ) as [ $method, $route ] ) {
		$reset(); $dispatch( $method, $route ); check( "not locked: $method $route", array() === $wpdb->queries );
	}

	$reset(); $_COOKIE = array(); $dispatch( 'POST', '/wc/store/v1/cart/add-item' ); check( 'new session without identity is not locked', array() === $wpdb->queries );

	// Identity: Cart-Token, then user, then guest cookie.
	$reset(); $_SERVER['HTTP_CART_TOKEN'] = 'tok.en'; $user = 7; check( 'cart token wins', 'token:tok.en' === Lock::session_identity() );
	$reset(); $user = 7; check( 'logged-in user next', 'user:7' === Lock::session_identity() );
	$reset(); check( 'guest cookie customer id', 'guest:guest123' === Lock::session_identity() );
	$name = Lock::lock_name( 'guest:guest123' );
	check( 'lock name within MySQL 64 chars', strlen( $name ) <= 64 && str_starts_with( $name, 'pc-cart-' ) );
	$wpdb->prefix = 'wp2_'; check( 'lock name scoped by table prefix', Lock::lock_name( 'guest:guest123' ) !== $name ); $wpdb->prefix = 'wp_';
	check( 'different sessions do not share a lock', Lock::lock_name( 'guest:other' ) !== $name );

	// Interleaving: request B for the same session cannot start until A released after its session save.
	$reset(); $a = $dispatch( 'POST', '/wc/store/v1/cart/add-item' );
	Lock::reset_for_tests(); // simulate a second PHP request (separate static state, same MySQL server)
	$b = $dispatch( 'POST', '/wc/store/v1/cart/add-item' );
	check( 'second concurrent write for same session is refused after waiting', $b instanceof WP_Error && 503 === $b->data['status'] && 'pc_cart_busy' === $b->code );
	$_COOKIE = array( $cookie => 'someoneelse||1||1||h' ); $c = $dispatch( 'POST', '/wc/store/v1/cart/add-item' );
	check( 'other customer unaffected', null === $c );

	// Batch: nested sub-requests in the same PHP request do not re-acquire.
	$reset(); $dispatch( 'POST', '/wc/store/v1/batch' ); $count = count( $wpdb->queries ); $dispatch( 'POST', '/wc/store/v1/cart/add-item' );
	check( 'nested batch sub-request reuses held lock', count( $wpdb->queries ) === $count );
	Lock::release(); Lock::release(); check( 'release is idempotent', 1 === count( array_filter( $wpdb->queries, fn( $q ) => str_contains( $q, 'RELEASE_LOCK' ) ) ) );

	// Unsupported advisory locks (SQLite dev site) keep native behaviour.
	$reset();
	$GLOBALS['wpdb'] = new class { public string $prefix = 'wp_'; public array $queries = array(); public function prepare( $sql, ...$args ) { return array( $sql, $args ); } public function get_var( $p ) { $this->queries[] = $p[0]; return null; } };
	Lock::reset_for_tests(); $r = $dispatch( 'POST', '/wc/store/v1/cart/add-item' ); check( 'NULL lock result fails open', null === $r );
	Lock::release(); check( 'nothing to release when lock unsupported', 1 === count( $GLOBALS['wpdb']->queries ) );

	// Existing short-circuit results are not altered.
	Lock::reset_for_tests(); check( 'prior pre-dispatch result passes through', 'prior' === Lock::before_dispatch( 'prior', null, new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' ) ) );

	echo "PASS {$checks} cart write lock checks\n";
}
