<?php
/** Actual handoff/frozen-identity code; synthetic native/db/lease boundaries, no database or network. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' );
	class WC_Payment_Gateway {}
	final class WP_REST_Request {
		public function __construct( private string $method, private array $params = array() ) {}
		public function get_method(): string { return $this->method; }
		public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
		public function get_params(): array { return $this->params; }
	}
	final class WC_Order {
		public array $data;
		public function __construct( public int $id ) { $this->data = $GLOBALS['orders'][ $id ]; }
		public function get_id(): int { return $this->id; }
		public function get_type(): string { return 'shop_order'; }
		public function get_customer_id(): int { return $this->data['owner']; }
		public function get_payment_method(): string { return $this->data['method']; }
		public function get_currency( $context = 'view' ): string { return 'USD'; }
		public function get_total( $context = 'view' ): string { return '10.00'; }
		public function get_customer_note( $context = 'view' ): string { return ''; }
		public function get_address( $group, $context = 'view' ): array { return array( 'city' => 'Synthetic', 'country' => 'US', 'phone' => 'billing' === $group ? $GLOBALS['stored_phone'] : '' ); }
		public function meta_exists( $key ): bool { return '' !== $GLOBALS['stored_phone'] && '_wc_other/peptide-panapay/phone' === $key; }
		public function get_meta( $key, $single = true ) { return $this->data['book']; }
		public function has_status( $statuses ): bool { return in_array( $this->data['status'], (array) $statuses, true ); }
		public function is_paid(): bool { return 'processing' === $this->data['status']; }
		public function get_date_paid() { return $this->data['paid']; }
		public function get_transaction_id(): string { return $this->data['transaction']; }
		public function has_cart_hash( string $hash ): bool { return $this->data['cart'] === $hash; }
	}
	final class Model_Session {
		public array $data = array(); public int $saves = 0;
		public function get( $key, $default = null ) { return $this->data[ $key ] ?? $default; }
		public function set( $key, $value ): void { $this->data[ $key ] = $value; }
		public function __unset( $key ): void { unset( $this->data[ $key ] ); }
		public function save_data(): void { ++$this->saves; }
		public function get_customer_id(): string { return $GLOBALS['session_id']; }
	}
	class wpdb {
		public string $options = 'synthetic_options'; public string $last_error = '';
		public function prepare( string $sql, ...$args ): string { return json_encode( array( $sql, $args ), JSON_THROW_ON_ERROR ); }
		public function query( string $prepared ) {
			[ $sql, $args ] = json_decode( $prepared, true, 32, JSON_THROW_ON_ERROR );
			if ( str_starts_with( $sql, 'INSERT IGNORE' ) ) {
				if ( isset( $GLOBALS['slots'][ $args[0] ] ) ) { return 0; }
				$GLOBALS['slots'][ $args[0] ] = $args[1]; return 1;
			}
			if ( str_starts_with( $sql, 'UPDATE' ) && ! $GLOBALS['cas_fail'] && ( $GLOBALS['slots'][ $args[1] ] ?? null ) === $args[2] ) { $GLOBALS['slots'][ $args[1] ] = $args[0]; return 1; }
			return 0;
		}
		public function get_results( string $prepared, $format ): array {
			[ , $args ] = json_decode( $prepared, true, 32, JSON_THROW_ON_ERROR );
			return isset( $GLOBALS['slots'][ $args[0] ] ) ? array( array( 'option_value' => $GLOBALS['slots'][ $args[0] ] ) ) : array();
		}
		public function close(): void {}
	}
	function WC(): object { return $GLOBALS['wc']; }
	function get_current_user_id(): int { return $GLOBALS['user']; }
	function wp_json_encode( $data ) { return json_encode( $data ); }
	function get_woocommerce_currency() { return 'USD'; }
	function wc_format_decimal( $value, $decimals ) { return number_format( (float) $value, $decimals, '.', '' ); }
	function wc_get_price_decimals() { return 2; }
	function wc_sanitize_textarea( $value ) { return trim( $value ); }
	function get_option( $name, $default = false ) { return 'woocommerce_checkout_phone_field' === $name && $GLOBALS['phone_hidden'] ? 'hidden' : $default; }
	function __( $text, $domain = null ): string { return $text; }
	function do_action( string $name, $order ): void { $GLOBALS['events'][] = array( $name, $order->get_id() ); if ( $GLOBALS['replacement'] ) { WC()->session->set( 'store_api_draft_order', 999 ); } }
}
namespace Automattic\WooCommerce\Blocks {
	final class Package { public static function container() { return new class { public function get( $name ) { return new \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields(); } }; } }
}
namespace Automattic\WooCommerce\Blocks\Domain\Services {
	final class CheckoutFields {
		public function get_fields_for_location( $location ): array { return array(); }
		public function is_field( $key ): bool { return in_array( $key, array( 'synthetic/ack', 'peptide-panapay/phone' ), true ); }
		public function get_field_from_object( $key, $order, $group ) { return 'peptide-panapay/phone' === $key ? $GLOBALS['stored_phone'] : true; }
		public static function get_group_key( $group ) { return '_wc_' . $group . '/'; }
	}
}
namespace Automattic\WooCommerce\StoreApi\Exceptions { class RouteException extends \RuntimeException {} }
namespace VialCove\Panapay {
	final class Persistence {
		public static function assert_book( \WC_Order $order, array $book ): void {
			if ( $GLOBALS['book_mismatch'] || $book !== $GLOBALS['orders'][ $order->get_id() ]['book'] ) { throw new \RuntimeException( 'unpersisted book' ); }
		}
		public static function has_gateway( int $id, string $expected ): bool { return $GLOBALS['orders'][ $id ]['method'] === $expected; }
		public static function has_status( int $id, array $statuses ): bool { return in_array( $GLOBALS['orders'][ $id ]['status'], $statuses, true ); }
	}
	final class Store {
		public static array $locks = array();
		public static function acquire( int $id ): array {
			if ( isset( self::$locks[ $id ] ) ) { throw new \RuntimeException( 'payment_busy' ); }
			return self::$locks[ $id ] = array( 'id' => $id, 'owner' => bin2hex( random_bytes( 8 ) ) );
		}
		public static function assert_owner( array $lock ): void { if ( $GLOBALS['expired'] || ( self::$locks[ $lock['id'] ] ?? null ) !== $lock ) { throw new \RuntimeException( 'expired lease' ); } }
		public static function release( array $lock ): void { unset( self::$locks[ $lock['id'] ] ); }
		public static function assert_no_completion( int $id ): void { if ( in_array( $id, $GLOBALS['claimed'], true ) ) { throw new \RuntimeException( 'completion claimed' ); } }
		public static function creation_ready( int $id ): bool { return ! $GLOBALS['creating']; }
	}
	final class Database { public static function open(): \wpdb { return new \wpdb(); } }
	final class Order_Payload { public static function fingerprint( \WC_Order $order ): string { return hash( 'sha256', 'synthetic-order-' . $order->get_id() ); } }
	final class Checkout_Fields { public const FIELD_ID = 'peptide-panapay/phone'; public static function sanitize_phone( $value ): string { return is_string( $value ) ? trim( $value ) : ''; } }
}
namespace {
	use VialCove\Panapay\Checkout_Handoff as Handoff;
	use VialCove\Panapay\Session_Service as Sessions;
	use VialCove\Panapay\Gateway;
	use VialCove\Panapay\Store;
	$root = dirname( __DIR__ ) . '/includes/';
	require $root . 'class-gateway.php'; require $root . 'class-session-service.php'; require $root . 'class-checkout-handoff.php';
	$checks = 0;
	function verify( bool $ok, string $label ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new \RuntimeException( 'FAIL: ' . $label ); } }
	function native_order( int $id, string $method = 'pc_panapay', string $state = 'unknown', string $status = 'pending' ): void {
		$account = Gateway::account_from_id( $method ); $attempt = str_pad( dechex( $id ), 32, 'a', STR_PAD_LEFT );
		$GLOBALS['orders'][ $id ] = array( 'owner' => 0, 'method' => $method, 'status' => $status, 'paid' => null, 'transaction' => '', 'cart' => 'native-cart-a',
			'book' => array( 'active' => $attempt, 'attempts' => array( $attempt => array( 'reference' => 'PP1234abcd-' . $id . '-' . $attempt,
				'account_id' => $account, 'gateway_id' => $method, 'state' => $state, 'fingerprint' => hash( 'sha256', 'synthetic-order-' . $id ) ) ) ) );
	}
	function reset_model(): void {
		Handoff::finish(); Store::$locks = array(); $GLOBALS['slots'] = array(); $GLOBALS['orders'] = array();
		$GLOBALS['user'] = 0; $GLOBALS['session_id'] = 'synthetic-owner-a'; $GLOBALS['events'] = array(); $GLOBALS['claimed'] = array();
		$GLOBALS['book_mismatch'] = false; $GLOBALS['expired'] = false; $GLOBALS['cas_fail'] = false; $GLOBALS['creating'] = false;
		$GLOBALS['replacement'] = false; $GLOBALS['customer_changed'] = false; $GLOBALS['stored_phone'] = ''; $GLOBALS['phone_hidden'] = false;
		$GLOBALS['wc'] = (object) array( 'session' => new Model_Session(), 'cart' => new class { public function get_cart_hash(): string { return 'native-cart-a'; } public function get_total( $context ) { return '10.00'; } },
			'customer' => new class { public function __call( $name, $args ) { return str_ends_with( $name, '_city' ) ? ( $GLOBALS['customer_changed'] ? 'Changed City' : 'Synthetic' ) : ( str_ends_with( $name, '_country' ) ? 'US' : '' ); } } );
		native_order( 101 ); WC()->session->data = array( 'store_api_draft_order' => 101, 'order_awaiting_payment' => 101, 'cart' => 'keep-cart', 'customer' => 'keep-address' );
	}
	function fails( callable $call, string $label ): void {
		$failed = false; try { $call(); } catch ( \Throwable $expected ) { $failed = true; } finally { Handoff::finish(); }
		verify( $failed, $label );
	}
	$other = 'pc_panapay_' . str_repeat( 'b', 32 );
	$post = new WP_REST_Request( 'POST', array( 'payment_method' => $other, 'billing_address' => array( 'city' => 'Synthetic', 'country' => 'US' ) ) );
	reset_model(); verify( 'pc_panapay' === Sessions::frozen_gateway( new WC_Order( 101 ) ), 'valid persisted frozen identity' );
	$GLOBALS['orders'][101]['method'] = $other;
	verify( null === Sessions::bound_gateway( new WC_Order( 101 ) ), 'changed native method still fails payable binding' );
	verify( 'pc_panapay' === Sessions::frozen_gateway( new WC_Order( 101 ) ), 'frozen recognition survives changed native method' );
	$GLOBALS['book_mismatch'] = true; verify( null === Sessions::frozen_gateway( new WC_Order( 101 ) ), 'uncommitted book fails frozen identity' );
	reset_model(); $id = $GLOBALS['orders'][101]['book']['active']; $GLOBALS['orders'][101]['book']['attempts'][$id]['reference'] = 'PP1234abcd-102-' . $id;
	verify( null === Sessions::frozen_gateway( new WC_Order( 101 ) ), 'another order reference rejected' );
	reset_model(); $before = serialize( $GLOBALS['orders'] );
	$result = Handoff::resolve( new WC_Order( 101 ), new WP_REST_Request( 'PATCH', array( 'payment_method' => $other ) ) );
	verify( null === $result, 'PATCH separates into native no-order flow' );
	verify( null === WC()->session->get( 'store_api_draft_order' ) && null === WC()->session->get( 'order_awaiting_payment' ), 'PATCH detaches both matching pointers' );
	verify( serialize( $GLOBALS['orders'] ) === $before && 0 === count( $GLOBALS['slots'] ), 'PATCH does not alter old order or reserve a payment/order' );
	verify( 'keep-cart' === WC()->session->get( 'cart' ) && 'keep-address' === WC()->session->get( 'customer' ), 'cart and addresses retained' );
	verify( isset( Store::$locks[101] ), 'old order lease held through route' ); Handoff::finish();
	verify( array() === Store::$locks, 'route finish releases lease' );
	$result = Handoff::resolve( null, $post );
	verify( null === $result && 1 === count( $GLOBALS['slots'] ), 'POST after PATCH reserves one handoff slot' );
	verify( false === str_contains( serialize( $GLOBALS['slots'] ), 'Synthetic' ), 'only request hash stored, not address' );
	native_order( 102, $other ); $GLOBALS['orders'][102]['book'] = ''; $GLOBALS['orders'][102]['status'] = 'checkout-draft'; $GLOBALS['orders'][102]['method'] = '';
	Handoff::created( new WC_Order( 102 ) );
	$slot = json_decode( reset( $GLOBALS['slots'] ), true ); verify( 102 === $slot['new'], 'created hook binds native ID with CAS' );
	Handoff::finish(); native_order( 102, $other, 'created' );
	$first = serialize( $GLOBALS['orders'] );
	$result = Handoff::resolve( new WC_Order( 101 ), $post ); // another request loaded its old Woo session before first response.
	verify( 102 === $result->get_id() && 1 === count( $GLOBALS['slots'] ), 'late duplicate stale-pointer POST reuses bound native order' );
	verify( serialize( $GLOBALS['orders'] ) === $first, 'reusing slot changes no old or new order' ); Handoff::finish();
	$reordered = new WP_REST_Request( 'POST', array( 'billing_address' => array( 'country' => 'US', 'city' => 'Synthetic' ), 'payment_method' => $other ) );
	verify( 102 === Handoff::resolve( new WC_Order( 101 ), $reordered )->get_id() && 1 === count( $GLOBALS['slots'] ), 'equivalent JSON key order retains the same handoff identity' ); Handoff::finish();
	$GLOBALS['orders'][102]['status'] = 'processing';
	fails( static fn() => Handoff::resolve( new WC_Order( 101 ), $post ), 'paid slot cannot be reset to pending' );
	$GLOBALS['orders'][102]['status'] = 'pending'; $id = $GLOBALS['orders'][102]['book']['active']; $GLOBALS['orders'][102]['book']['attempts'][$id]['state'] = 'unknown';
	fails( static fn() => Handoff::resolve( new WC_Order( 101 ), $post ), 'unknown slot cannot be reused as fresh checkout' );
	reset_model(); Handoff::resolve( new WC_Order( 101 ), $post ); Handoff::finish();
	fails( static fn() => Handoff::resolve( new WC_Order( 101 ), $post ), 'unbound slot after crash never takes over and creates again' );
	verify( 1 === count( $GLOBALS['slots'] ), 'unconfirmed slot count remains one' );
	reset_model(); $same = Handoff::resolve( new WC_Order( 101 ), new WP_REST_Request( 'POST', array( 'payment_method' => 'pc_panapay' ) ) );
	verify( 101 === $same->get_id() && array() === $GLOBALS['slots'] && 101 === WC()->session->get( 'store_api_draft_order' ), 'same-method POST keeps native order and permanent payment fence' );
	reset_model(); $GLOBALS['stored_phone'] = '+12025550123'; $GLOBALS['phone_hidden'] = true;
	$same = Handoff::resolve( new WC_Order( 101 ), new WP_REST_Request( 'POST', array( 'payment_method' => 'pc_panapay', 'additional_fields' => array( 'peptide-panapay/phone' => '+12025550123' ) ) ) );
	verify( 101 === $same->get_id() && array() === $GLOBALS['slots'], 'same conditional phone copied late by gateway is still an unchanged retry' );
	verify( null === Handoff::resolve( new WC_Order( 101 ), new WP_REST_Request( 'POST', array( 'payment_method' => 'pc_panapay', 'additional_fields' => array( 'peptide-panapay/phone' => '+12025550999' ) ) ) ), 'changed conditional phone splits before native overwrite' ); Handoff::finish();
	reset_model(); $GLOBALS['user'] = 8; $before = serialize( $GLOBALS['orders'] );
	fails( static fn() => Handoff::resolve( new WC_Order( 101 ), $post ), 'another owner cannot mutate a frozen order' );
	verify( $before === serialize( $GLOBALS['orders'] ) && array() === $GLOBALS['slots'], 'owner rejection writes no order or handoff' );
	reset_model(); $before = serialize( WC()->session->data );
	verify( null === Handoff::resolve( new WC_Order( 101 ), new WP_REST_Request( 'GET' ) ) && null === WC()->session->get( 'store_api_draft_order' ) && array() === $GLOBALS['slots'], 'authenticated Store API GET separates old frozen order without native mutation or new order' ); Handoff::finish();
	reset_model(); Handoff::resolve( new WC_Order( 101 ), $post ); $GLOBALS['cas_fail'] = true;
	native_order( 102, $other ); $GLOBALS['orders'][102]['book'] = ''; $GLOBALS['orders'][102]['status'] = 'checkout-draft';
	fails( static fn() => Handoff::created( new WC_Order( 102 ) ), 'failed CAS cannot claim new order binding' );
	$slot = json_decode( reset( $GLOBALS['slots'] ), true ); verify( 0 === $slot['new'], 'failed bind keeps unconfirmed permanent slot' );
	reset_model(); Handoff::resolve( new WC_Order( 101 ), $post ); $GLOBALS['expired'] = true;
	fails( static fn() => Handoff::created( new WC_Order( 101 ) ), 'expired lease cannot bind order' );
	reset_model(); $GLOBALS['claimed'] = array( 101 );
	fails( static fn() => Handoff::resolve( new WC_Order( 101 ), $post ), 'completion claim prevents stale handoff mutation' );
	verify( array() === $GLOBALS['slots'], 'completion claim creates no slot' );
	reset_model(); $GLOBALS['customer_changed'] = true; $before = serialize( $GLOBALS['orders'] );
	verify( null === Handoff::resolve( new WC_Order( 101 ), new WP_REST_Request( 'POST', array( 'payment_method' => 'pc_panapay' ) ) ), 'same-method direct POST changed address receives independent native draft' );
	verify( $before === serialize( $GLOBALS['orders'] ) && 1 === count( $GLOBALS['slots'] ), 'changed address handoff preserves original frozen order' ); Handoff::finish();
	reset_model(); $GLOBALS['book_mismatch'] = true; $before = serialize( $GLOBALS['orders'] );
	fails( static fn() => Handoff::resolve( new WC_Order( 101 ), $post ), 'nonempty book without persistence confirmation cannot downgrade to mutable draft' );
	verify( $before === serialize( $GLOBALS['orders'] ) && array() === $GLOBALS['slots'], 'unconfirmed identity writes neither old order nor slot' );
	reset_model(); $GLOBALS['orders'][101]['book'] = array( 'broken' ); $GLOBALS['orders'][101]['method'] = 'cod';
	fails( static fn() => Handoff::resolve( new WC_Order( 101 ), $post ), 'malformed book with a non-Panapay native method still fails closed' );
	reset_model(); $lock = Store::acquire( 101 );
	fails( static fn() => Handoff::resolve( new WC_Order( 101 ), $post ), 'concurrent old-order lease refuses a second handoff' );
	verify( array() === $GLOBALS['slots'] && ( Store::$locks[101] ?? null ) === $lock, 'busy request cannot steal or release another request lease' );
	reset_model(); $GLOBALS['replacement'] = true;
	fails( static fn() => Handoff::resolve( new WC_Order( 101 ), $post ), 'observer pointer replacement prevents fresh draft overwrite' );
	verify( 999 === WC()->session->get( 'store_api_draft_order' ), 'observer replacement pointer preserved' );
	reset_model();
	fails( static fn() => Handoff::resolve( new WC_Order( 101 ), new WP_REST_Request( 'POST' ) ), 'missing method cannot reach native frozen-order mutation' );
	reset_model(); Handoff::resolve( new WC_Order( 101 ), $post ); Handoff::finish();
	$name = array_key_first( $GLOBALS['slots'] ); $record = json_decode( $GLOBALS['slots'][$name], true ); $record['created'] = time()-604801; $GLOBALS['slots'][$name] = json_encode( $record );
	fails( static fn() => Handoff::resolve( new WC_Order( 101 ), $post ), 'expired slot is never replaced' );
	reset_model(); Handoff::resolve( new WC_Order( 101 ), new WP_REST_Request( 'PATCH' ) ); Handoff::finish(); Handoff::clear_navigation();
	verify( null === WC()->session->get( 'pc_panapay_checkout_handoff' ), 'new page navigation clears previous handoff intent' );
	echo json_encode( array( 'passed' => true, 'checks' => $checks, 'real_http' => 0, 'real_database' => 0 ), JSON_THROW_ON_ERROR ) . "\n";
}
