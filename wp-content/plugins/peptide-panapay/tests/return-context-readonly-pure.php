<?php
/** Real return proof and Session_Service code; synthetic native reads only, no HTTP or writes. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' ); define( 'DAY_IN_SECONDS', 86400 );
	final class WC_Order {
		public array $book; public string $gateway = 'pc_panapay'; public string $status = 'pending'; public string $transaction = '';
		public string $financial = ''; public string $full = ''; public int $id = 42;
		public string $type = 'shop_order';
		public function __construct( int $id = 0 ) {
			if ( 0 === $id ) { return; }
			++$GLOBALS['fresh_reads'];
			if ( ! $GLOBALS['order_exists'] || 42 !== $id ) { throw new \RuntimeException( 'Unknown native order' ); }
			foreach ( get_object_vars( $GLOBALS['order'] ) as $key => $value ) { $this->$key = $value; }
		}
		public function get_id(): int { return $this->id; }
		public function get_type(): string { return $this->type; }
		public function get_meta( string $key, bool $single = true ) { return '_pc_panapay_sessions' === $key ? $this->book : ''; }
		public function get_payment_method(): string { return $this->gateway; }
		public function get_transaction_id(): string { return $this->transaction; }
		public function is_paid(): bool { return in_array( $this->status, array( 'processing', 'completed' ), true ); }
		public function has_status( $status ): bool { return in_array( $this->status, (array) $status, true ); }
		public function save(): void { ++$GLOBALS['writes']; throw new \RuntimeException( 'No order writes allowed' ); }
		public function payment_complete( ...$args ): void { ++$GLOBALS['writes']; throw new \RuntimeException( 'No payment completion allowed' ); }
		public function update_status( ...$args ): void { ++$GLOBALS['writes']; throw new \RuntimeException( 'No status writes allowed' ); }
	}
	function wc_get_order( int $id ) { ++$GLOBALS['cached_reads']; throw new \RuntimeException( 'Do not use a potentially stale factory object' ); }
	function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
	function sanitize_text_field( string $text ): string { return strip_tags( $text ); }
	function add_query_arg( array $args, string $url ): string {
		$parts = parse_url( $url ); $query = array(); parse_str( $parts['query'] ?? '', $query );
		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '/' )
			. '?' . http_build_query( array_replace( $query, $args ), '', '&', PHP_QUERY_RFC3986 );
	}
	function wp_remote_post( ...$args ) { ++$GLOBALS['http']; throw new \RuntimeException( 'No HTTP allowed' ); }
	function wp_safe_remote_post( ...$args ) { ++$GLOBALS['http']; throw new \RuntimeException( 'No HTTP allowed' ); }
}
namespace VialCove\Panapay {
	function time(): int { return $GLOBALS['clock']; }
	final class Gateway {
		public const ID = 'pc_panapay';
		public static function valid_account_id( string $id ): bool { return 'default' === $id || 1 === preg_match( '/\A[a-f0-9]{32}\z/', $id ); }
		public static function native_id( string $id ): string { return 'default' === $id ? self::ID : self::ID . '_' . $id; }
	}
	final class Order_Payload {
		public static function payment_fingerprint( \WC_Order $o ): string { return $o->financial; }
		public static function fingerprint( \WC_Order $o ): string { return $o->full; }
	}
	final class Persistence {
		public static function assert_book( \WC_Order $o, array $book ): void { ++$GLOBALS['book_reads']; if ( ! $GLOBALS['book_persisted'] ) { throw new \RuntimeException( 'Uncommitted book' ); } }
		public static function has_gateway( int $id, string $gateway ): bool { return 42 === $id && $GLOBALS['gateway_persisted'] && $gateway === $GLOBALS['order']->gateway; }
		public static function has_status( int $id, array $statuses ): bool { return 42 === $id && $GLOBALS['status_persisted'] && $GLOBALS['order']->has_status( $statuses ); }
		public static function is_paid( int $id, string $transaction ): bool { return 42 === $id && $GLOBALS['paid_persisted'] && $transaction === $GLOBALS['order']->transaction; }
	}
	final class Store {
		public static function admin_transactions( int $id ): array { ++$GLOBALS['ledger_reads']; if ( 42 !== $id ) { throw new \RuntimeException( 'Wrong ledger order' ); } return $GLOBALS['ledger']; }
		public static function claim( ...$args ): void { ++$GLOBALS['writes']; throw new \RuntimeException( 'No financial claim allowed' ); }
	}
	final class Crypto {
		public static function decrypt( string $box, string $purpose = 'payment-secret' ): string {
			if ( 'secret-box' === $box ) { return $GLOBALS['secret']; }
			if ( 'provider-transaction' === $purpose && str_starts_with( $box, 'tx:' ) ) { return substr( $box, 3 ); }
			throw new \RuntimeException( 'Wrong cipher context' );
		}
	}
}
namespace {
	foreach ( array( 'protocol-error', 'signature', 'return-context', 'session-service' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
	use VialCove\Panapay\{Return_Context, Session_Service, Signature};
	$checks = 0; $writes = 0; $http = 0; $book_reads = 0; $ledger_reads = 0; $fresh_reads = 0; $cached_reads = 0; $clock = 2000000000;
	$reference = 'PPab12cd34-42-' . str_repeat( 'a', 32 ); $token = str_repeat( 'b', 48 ); $secret = 'synthetic-hq-link-key';
	function check( string $label, bool $condition ): void { ++$GLOBALS['checks']; if ( ! $condition ) { throw new \RuntimeException( 'FAIL: ' . $label ); } }
	function rejected( callable $fn ): bool { try { $fn(); } catch ( \Throwable $error ) { return true; } return false; }
	function reset_order(): void {
		$o = new WC_Order(); $o->financial = str_repeat( 'e', 64 ); $o->full = str_repeat( 'f', 64 );
		$o->book = array( 'active' => str_repeat( 'a', 32 ), 'attempts' => array( str_repeat( 'a', 32 ) => array(
			'reference' => $GLOBALS['reference'], 'state' => 'created', 'central' => true, 'created_at' => $GLOBALS['clock'] - 30,
			'return_token_hash' => hash( 'sha256', $GLOBALS['token'] ), 'secret_box' => 'secret-box',
			'fingerprint' => $o->full, 'payment_fingerprint' => $o->financial,
		) ) );
		$GLOBALS['order'] = $o; $GLOBALS['order_exists'] = true; $GLOBALS['book_persisted'] = true; $GLOBALS['gateway_persisted'] = true;
		$GLOBALS['status_persisted'] = true; $GLOBALS['paid_persisted'] = true; $GLOBALS['ledger'] = array();
	}
	function check_data(): array {
		$data = array( 'reference' => $GLOBALS['reference'], 'token' => $GLOBALS['token'], 'check' => '1' );
		$data['sign'] = Signature::generate( array( 'purpose' => 'pc-panapay-return-check-v1', 'reference' => $data['reference'], 'token' => $data['token'] ), $GLOBALS['secret'] );
		return $data;
	}
	function provider_data( string $status = 'SUCCEEDED', string $tx = 'transaction-sentinel' ): array {
		$data = array( 'reference' => $GLOBALS['reference'], 'token' => $GLOBALS['token'], 'status' => $status, 'transaction_id' => $tx );
		$data['sign'] = Signature::generate( array( 'status' => $status, 'transaction_id' => $tx ), $GLOBALS['secret'] ); return $data;
	}
	reset_order(); $valid = check_data();
	check( 'valid purpose-bound check proof', Return_Context::verify_check( $valid, $secret ) );
	foreach ( array(
		array( 'check' => 1 ), array( 'check' => '0' ), array( 'check' => true ), array( 'sign' => str_repeat( '0', 32 ) ),
		array( 'token' => str_repeat( 'c', 48 ) ), array( 'reference' => str_replace( '-42-', '-43-', $reference ) ),
		array( 'reference' => 'bad' ), array( 'token' => array() ), array( 'status' => 'SUCCEEDED' ), array( 'transaction_id' => 'x' ),
		array( 'sign' => Signature::generate( array( 'reference' => $reference, 'token' => $token ), $secret ) ),
		array( 'sign' => Signature::generate( array( 'status' => 'SUCCEEDED', 'transaction_id' => 'tx' ), $secret ) ),
	) as $change ) { check( 'check proof rejects altered/mixed shape ' . $checks, ! Return_Context::verify_check( array_replace( $valid, $change ), $secret ) ); }
	foreach ( array_keys( $valid ) as $key ) { $missing = $valid; unset( $missing[ $key ] ); check( 'check proof requires ' . $key, ! Return_Context::verify_check( $missing, $secret ) ); }
	check( 'check proof rejects another key', ! Return_Context::verify_check( $valid, 'other-key' ) );
	check( 'empty check key rejected', ! Return_Context::verify_check( $valid, '' ) );
	foreach ( array( 'https://shop.example.com/?wc-api=pc_panapay_return', 'https://shop.example.com/wc-api/pc_panapay_return/', 'https://shop.example.com/subdir/?wc-api=pc_panapay_return', 'http://127.0.0.1:8080/subdir/?wc-api=pc_panapay_return' ) as $base ) {
		$url = $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . 'reference=' . $reference . '&token=' . $token;
		$target = Return_Context::check_url( $url, $secret ); $args = array(); parse_str( parse_url( $target, PHP_URL_QUERY ), $args ); unset( $args['wc-api'] );
		check( 'frozen return URL becomes exact valid check proof ' . $checks, Return_Context::verify_check( $args, $secret ) );
		check( 'frozen callback origin/path unchanged ' . $checks, parse_url( $url, PHP_URL_HOST ) === parse_url( $target, PHP_URL_HOST ) && parse_url( $url, PHP_URL_PATH ) === parse_url( $target, PHP_URL_PATH ) );
	}
	$base = 'https://shop.example.com/wc-api/pc_panapay_return/?reference=' . $reference . '&token=' . $token;
	foreach ( array( $base . '&token=' . $token, $base . '&status=SUCCEEDED', $base . '&check=1', $base . '#secret', $base . "\n", str_replace( 'https://', 'https://user:pass@', $base ), str_replace( $token, 'bad', $base ), str_replace( 'pc_panapay_return/', 'other/', $base ) ) as $url ) {
		check( 'check URL rejects malformed or previously mixed target ' . $checks, rejected( static fn() => Return_Context::check_url( $url, $secret ) ) );
	}
	$returned = Session_Service::returned_order( $valid );
	check( 'check grants only a freshly read copy of the existing order', $returned !== $order && 42 === $returned->get_id() && $returned->book === $order->book );
	check( 'check reads committed book', $book_reads > 0 );
	check( 'browser SUCCESS cannot make pending order paid', 42 === Session_Service::returned_order( provider_data() )->get_id() && 'unknown' === Session_Service::return_status( $order, $reference ) );
	check( 'browser FAILURE cannot make pending order failed', 42 === Session_Service::returned_order( provider_data( 'FAILED', '' ) )->get_id() && 'unknown' === Session_Service::return_status( $order, $reference ) );
	foreach ( array( array( 'check' => '0' ), array( 'sign' => str_repeat( '0', 32 ) ), array( 'token' => str_repeat( 'c', 48 ) ), array( 'status' => 'SUCCEEDED' ), array( 'transaction_id' => '' ), array( 'reference' => str_replace( '-42-', '-43-', $reference ) ) ) as $change ) {
		check( 'returned check rejects tampering ' . $checks, rejected( static fn() => Session_Service::returned_order( array_replace( $valid, $change ) ) ) );
	}
	foreach ( array( array( 'status' => 'COMPLETED' ), array( 'transaction_id' => '' ), array( 'check' => '1' ), array( 'extra' => 'x' ), array( 'transaction_id' => "x\n" ) ) as $change ) {
		check( 'old provider return rejects bad or mixed fields ' . $checks, rejected( static fn() => Session_Service::returned_order( array_replace( provider_data(), $change ) ) ) );
	}
	foreach ( array( 'order_exists', 'book_persisted', 'gateway_persisted' ) as $switch ) {
		reset_order(); $GLOBALS[ $switch ] = false; check( 'returned check rejects absent native proof ' . $switch, rejected( static fn() => Session_Service::returned_order( $valid ) ) );
	}
	reset_order(); $order->gateway = 'cod'; check( 'changed gateway rejected', rejected( static fn() => Session_Service::returned_order( $valid ) ) );
	reset_order(); $order->id = 43; check( 'native object cannot cross order identity', rejected( static fn() => Session_Service::returned_order( $valid ) ) );
	reset_order(); $order->type = 'shop_order_refund'; check( 'refund or other post type cannot act as the order', rejected( static fn() => Session_Service::returned_order( $valid ) ) );
	reset_order(); $order->book['attempts'][str_repeat( 'a', 32 )]['account_id'] = str_repeat( 'c', 32 );
	$order->book['attempts'][str_repeat( 'a', 32 )]['gateway_id'] = 'pc_panapay';
	check( 'account and frozen gateway pair cannot be inconsistent', rejected( static fn() => Session_Service::returned_order( $valid ) ) );
	reset_order(); $order->financial = str_repeat( 'd', 64 ); check( 'changed financial contents rejected', rejected( static fn() => Session_Service::returned_order( $valid ) ) );
	reset_order(); $order->full = str_repeat( 'd', 64 ); check( 'new financial fingerprint allows contact-only change', 42 === Session_Service::returned_order( $valid )->get_id() );
	reset_order(); unset( $order->book['attempts'][str_repeat( 'a', 32 )]['payment_fingerprint'] ); check( 'old full fingerprint remains accepted', 42 === Session_Service::returned_order( $valid )->get_id() );
	$order->full = str_repeat( 'd', 64 ); check( 'legacy full fingerprint mismatch rejected', rejected( static fn() => Session_Service::returned_order( $valid ) ) );
	reset_order(); $order->book['attempts'][str_repeat( 'a', 32 )]['payment_fingerprint'] = ''; check( 'malformed new fingerprint never falls back', rejected( static fn() => Session_Service::returned_order( $valid ) ) );
	reset_order(); $order->book['attempts'][str_repeat( 'a', 32 )]['created_at'] = $clock - 7 * DAY_IN_SECONDS - 1; check( 'expired check binding rejected', rejected( static fn() => Session_Service::returned_order( $valid ) ) );
	reset_order(); $order->book['attempts'][str_repeat( 'a', 32 )]['central'] = false; check( 'standalone attempt cannot accept HQ-only check proof', rejected( static fn() => Session_Service::returned_order( $valid ) ) );
	check( 'standalone original signed provider return remains accepted', 42 === Session_Service::returned_order( provider_data() )->get_id() );
	reset_order(); $order->book['attempts'][str_repeat( 'a', 32 )]['state'] = 'failed'; $order->status = 'failed'; check( 'persisted active failure displayed', 'failed' === Session_Service::return_status( $order, $reference ) );
	$status_persisted = false; check( 'uncommitted failure never displayed', 'review' === Session_Service::return_status( $order, $reference ) );
	reset_order(); $order->book['attempts'][str_repeat( 'a', 32 )]['state'] = 'failed'; $order->status = 'failed'; $order->book['active'] = str_repeat( 'c', 32 ); check( 'inactive old failure never displayed', 'review' === Session_Service::return_status( $order, $reference ) );
	foreach ( array( 'on-hold', 'cancelled', 'refunded', 'processing', 'completed' ) as $status ) {
		reset_order(); $order->status = $status; check( 'nonpending/uncorroborated native status needs review ' . $status, 'review' === Session_Service::return_status( $order, $reference ) );
	}
	reset_order(); $order->status = 'processing'; $order->transaction = 'transaction-sentinel';
	$order->book['attempts'][str_repeat( 'a', 32 )]['state'] = 'paid'; $order->book['attempts'][str_repeat( 'a', 32 )]['provider_transaction_box'] = 'tx:transaction-sentinel';
	$ledger = array( array( 'attempt_id' => str_repeat( 'a', 32 ), 'state' => 'applied', 'transaction' => 'transaction-sentinel' ) );
	check( 'exact paid attempt plus committed native and applied ledger displayed paid', 'paid' === Session_Service::return_status( $order, $reference ) );
	$paid_persisted = false; check( 'native object paid without writer proof rejected', 'review' === Session_Service::return_status( $order, $reference ) ); $paid_persisted = true;
	foreach ( array( array( 'attempt_id' => str_repeat( 'c', 32 ) ), array( 'state' => 'review' ), array( 'state' => 'processing' ), array( 'transaction' => 'other-transaction' ) ) as $change ) {
		$ledger = array( array_replace( array( 'attempt_id' => str_repeat( 'a', 32 ), 'state' => 'applied', 'transaction' => 'transaction-sentinel' ), $change ) );
		check( 'mismatched or incomplete ledger never paid ' . $checks, 'review' === Session_Service::return_status( $order, $reference ) );
	}
	$ledger = array(); check( 'missing ledger never paid', 'review' === Session_Service::return_status( $order, $reference ) );
	$order->transaction = 'other-transaction'; check( 'different native transaction never paid', 'review' === Session_Service::return_status( $order, $reference ) );
	$long = str_repeat( 'long-provider-transaction-', 12 ); $order->transaction = Session_Service::native_transaction_id( $long );
	$order->book['attempts'][str_repeat( 'a', 32 )]['provider_transaction_box'] = 'tx:' . $long;
	$ledger = array( array( 'attempt_id' => str_repeat( 'a', 32 ), 'state' => 'applied', 'transaction' => $long ) );
	check( 'long provider transaction requires its exact native hashed ID and original ledger value', 'paid' === Session_Service::return_status( $order, $reference ) );
	$ledger[0]['transaction'] .= 'altered'; check( 'same native hash does not substitute for exact provider ledger transaction', 'review' === Session_Service::return_status( $order, $reference ) );
	$order->financial = str_repeat( 'd', 64 ); check( 'changed paid financial contents need review', 'review' === Session_Service::return_status( $order, $reference ) );
	$gateway_persisted = false; check( 'paid status does not override changed persisted gateway', 'review' === Session_Service::return_status( $order, $reference ) ); $gateway_persisted = true;
	check( 'other order reference rejected', rejected( static fn() => Session_Service::return_status( $order, str_replace( '-42-', '-43-', $reference ) ) ) );
	$book_persisted = false; check( 'status lookup requires committed book too', rejected( static fn() => Session_Service::return_status( $order, $reference ) ) );
	check( 'no HTTP or mutation', 0 === $http && 0 === $writes );
	check( 'all return authentication uses fresh native reads and never the cached factory', $fresh_reads > 0 && 0 === $cached_reads );
	echo json_encode( array( 'passed' => true, 'checks' => $checks, 'http' => $http, 'financial_writes' => $writes, 'native_db' => false ), JSON_THROW_ON_ERROR ) . "\n";
}
