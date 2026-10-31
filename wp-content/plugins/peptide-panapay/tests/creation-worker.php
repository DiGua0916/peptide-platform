<?php
/**
 * Root-run disposable creation worker, for a true two-process lease-expiry test.
 * Private packet: {"schema":1,"run":"<12-64 lowercase hex>","order_id":123}.
 * The same fresh order must have _pc_panapay_creation_run=<run>, pc_panapay and @example.invalid.
 * Optional READY/RELEASE paths must be distinct files beside the private packet.
 */
use VialCove\Panapay\{Configuration, Database, Gateway};
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || WP_CLI !== true
	|| ! defined( 'PC_PANAPAY_TESTING' ) || PC_PANAPAY_TESTING !== true
	|| wp_get_environment_type() !== 'local' || ! defined( 'DB_NAME' ) || ! str_starts_with( DB_NAME, 'panapay_qa_' ) ) {
	throw new RuntimeException( 'Disposable Panapay CLI QA only.' );
}

function pp_panapay_creation_worker_path( string $value, bool $exists, ?string $same_dir = null ): string {
	$value = str_replace( '\\', '/', $value );
	if ( ! preg_match( '/\AD:\//i', $value ) || str_contains( $value, "\0" ) ) { throw new RuntimeException( 'scope_rejected' ); }
	$dir = realpath( dirname( $value ) ); $name = basename( $value );
	if ( false === $dir || ! preg_match( '/\A[A-Za-z0-9_.-]{1,120}\z/', $name ) || in_array( $name, array( '.', '..' ), true ) ) { throw new RuntimeException( 'scope_rejected' ); }
	$dir = str_replace( '\\', '/', $dir ); $lower = strtolower( $dir );
	if ( ! str_starts_with( $lower, 'd:/' ) || ( ! str_ends_with( $lower, '/private' ) && ! str_contains( $lower, '/private/' ) )
		|| ( null !== $same_dir && 0 !== strcasecmp( $dir, $same_dir ) ) ) { throw new RuntimeException( 'scope_rejected' ); }
	$path = $dir . '/' . $name;
	if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) || ( $exists && ! is_file( $path ) ) ) { throw new RuntimeException( 'scope_rejected' ); }
	return $path;
}

/** Read only this owned order's persisted book; no session URLs or credentials are returned. */
function pp_panapay_creation_worker_book( int $id ): array {
	$db = Database::open();
	try {
		$hpos = OrderUtil::custom_orders_table_usage_is_enabled();
		$table = $hpos ? OrdersTableDataStore::get_meta_table_name() : $db->postmeta;
		if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) ) { throw new RuntimeException( 'scope_rejected' ); }
		$sql = $hpos ? $db->prepare( "SELECT meta_value FROM `{$table}` WHERE order_id=%d AND meta_key=%s LIMIT 2", $id, '_pc_panapay_sessions' )
			: $db->prepare( "SELECT meta_value FROM `{$table}` WHERE post_id=%d AND meta_key=%s LIMIT 2", $id, '_pc_panapay_sessions' );
		$rows = $db->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || count( $rows ) > 1 || '' !== (string) $db->last_error ) { throw new RuntimeException( 'book_read_rejected' ); }
		if ( ! $rows ) { return array( 'present' => false, 'digest' => null, 'attemptCount' => 0, 'activeState' => null ); }
		$raw = $rows[0]['meta_value']; $book = maybe_unserialize( $raw );
		if ( ! is_string( $raw ) || ! is_array( $book ) || ! is_array( $book['attempts'] ?? null ) || ! is_string( $book['active'] ?? null ) ) { throw new RuntimeException( 'book_read_rejected' ); }
		$state = $book['attempts'][ $book['active'] ]['state'] ?? null;
		if ( ! in_array( $state, array( 'creating', 'created', 'unknown', 'failed', 'confirmed', 'paid' ), true ) ) { throw new RuntimeException( 'book_read_rejected' ); }
		return array( 'present' => true, 'digest' => hash( 'sha256', $raw ), 'attemptCount' => count( $book['attempts'] ), 'activeState' => $state );
	} finally { $db->close(); }
}

global $ppcreationworker;
$ppcreationworker = array( 'stages' => array(), 'providerHTTPCalls' => 0, 'otherHTTPAttemptsBlocked' => 0,
	'creatingSaveEntries' => 0, 'barrierEntered' => false, 'barrierReleased' => false, 'barrierFailure' => null );
$ppcw_hook = $ppcw_http = $ppcw_mail = null;
$ppcw_output_level = ob_get_level(); ob_start();
try {
	$input = getenv( 'PC_PANAPAY_TEST_PACKET' );
	if ( ! is_string( $input ) || '' === $input ) { throw new RuntimeException( 'packet_required' ); }
	$path = pp_panapay_creation_worker_path( $input, true );
	if ( ! str_ends_with( strtolower( $path ), '.json' ) || filesize( $path ) > 2048 ) { throw new RuntimeException( 'packet_rejected' ); }
	$packet = json_decode( file_get_contents( $path ), true, 8, JSON_THROW_ON_ERROR );
	if ( ! is_array( $packet ) ) { throw new RuntimeException( 'packet_rejected' ); }
	$keys = array_keys( $packet ); sort( $keys );
	if ( $keys !== array( 'order_id', 'run', 'schema' ) || ( $packet['schema'] ?? null ) !== 1
		|| ! is_string( $packet['run'] ?? null ) || ! preg_match( '/\A[a-f0-9]{12,64}\z/', $packet['run'] )
		|| ! is_int( $packet['order_id'] ?? null ) || $packet['order_id'] <= 0 ) { throw new RuntimeException( 'packet_rejected' ); }
	$id = $packet['order_id']; $run = $packet['run']; $order = wc_get_order( $id );
	if ( ! $order instanceof WC_Order || Gateway::ID !== $order->get_payment_method() || ! $order->has_status( array( 'pending', 'failed' ) )
		|| ! is_string( $order->get_meta( '_pc_panapay_creation_run', true ) ) || ! hash_equals( $run, $order->get_meta( '_pc_panapay_creation_run', true ) )
		|| ! str_ends_with( strtolower( $order->get_billing_email() ), '@example.invalid' ) ) { throw new RuntimeException( 'owned_order_required' ); }
	$config = Configuration::load();
	if ( $config['gateway'] !== 'https://example.com' || $config['test_mode'] !== true ) { throw new RuntimeException( 'synthetic_configuration_required' ); }
	unset( $config );
	$before = pp_panapay_creation_worker_book( $id );
	$ready_input = getenv( 'PC_PANAPAY_TEST_READY' ); $release_input = getenv( 'PC_PANAPAY_TEST_RELEASE' );
	$has_ready = is_string( $ready_input ) && '' !== $ready_input; $has_release = is_string( $release_input ) && '' !== $release_input;
	if ( $has_ready !== $has_release ) { throw new RuntimeException( 'barrier_pair_required' ); }
	$ready = $release = '';
	if ( $has_ready ) {
		$dir = str_replace( '\\', '/', dirname( $path ) );
		$ready = pp_panapay_creation_worker_path( $ready_input, false, $dir );
		$release = pp_panapay_creation_worker_path( $release_input, false, $dir );
		if ( $ready === $release || $ready === $path || $release === $path ) { throw new RuntimeException( 'barrier_paths_rejected' ); }
	}
	$ppcw_hook = static function ( $object ) use ( $id, $run, $ready, $release ) {
		if ( ! $object instanceof WC_Order || $object->get_id() !== $id ) { return; }
		$book = $object->get_meta( '_pc_panapay_sessions', true );
		if ( ! is_array( $book ) || 'creating' !== ( $book['attempts'][ $book['active'] ?? '' ]['state'] ?? null ) ) { return; }
		$GLOBALS['ppcreationworker']['creatingSaveEntries']++;
		if ( 1 !== $GLOBALS['ppcreationworker']['creatingSaveEntries'] || '' === $ready ) { return; }
		$GLOBALS['ppcreationworker']['barrierEntered'] = true;
		$GLOBALS['ppcreationworker']['stages'][] = 'before_creating_book_save';
		$handle = @fopen( $ready, 'xb' );
		if ( false === $handle ) { $GLOBALS['ppcreationworker']['barrierFailure'] = 'ready_unavailable'; throw new RuntimeException( 'barrier_rejected' ); }
		try {
			$bytes = wp_json_encode( array( 'stage' => 'before_creating_book_save', 'run' => $run, 'order_id' => $id, 'pid' => getmypid() ) );
			if ( ! is_string( $bytes ) || fwrite( $handle, $bytes ) !== strlen( $bytes ) || ! fflush( $handle ) ) {
				$GLOBALS['ppcreationworker']['barrierFailure'] = 'ready_write_failed'; throw new RuntimeException( 'barrier_rejected' );
			}
		} finally { fclose( $handle ); }
		$deadline = microtime( true ) + 30;
		do {
			clearstatcache( true, $release );
			if ( is_file( $release ) && ! is_link( $release ) ) {
				$GLOBALS['ppcreationworker']['barrierReleased'] = true;
				$GLOBALS['ppcreationworker']['stages'][] = 'release_seen'; return;
			}
			usleep( 100000 );
		} while ( microtime( true ) < $deadline );
		$GLOBALS['ppcreationworker']['barrierFailure'] = 'timeout'; throw new RuntimeException( 'barrier_timeout' );
	};
	$ppcw_http = static function ( $pre, $args, $url ) use ( $id ) {
		if ( $url !== 'https://example.com/index.php/admin/Payments/createPayment' ) {
			$GLOBALS['ppcreationworker']['otherHTTPAttemptsBlocked']++;
			return new WP_Error( 'synthetic_creation_http_blocked', 'Non-provider HTTP blocked in synthetic creation worker.' );
		}
		$body = json_decode( $args['body'] ?? '', true, 32, JSON_THROW_ON_ERROR );
		if ( ! is_array( $body ) || ! is_string( $body['order_id'] ?? null )
			|| \VialCove\Panapay\Session_Service::parse_reference( $body['order_id'] )['order_id'] !== $id ) {
			throw new RuntimeException( 'provider_scope_rejected' );
		}
		$GLOBALS['ppcreationworker']['providerHTTPCalls']++;
		return array( 'headers' => array( 'content-type' => 'application/json' ), 'cookies' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body' => wp_json_encode( array( 'status' => 'SUCCEEDED', 'type' => 'redirect', 'payment_url' => 'https://example.com/synthetic-creation?session=' . bin2hex( random_bytes( 12 ) ) ) ) );
	};
	$ppcw_mail = static fn() => false;
	add_action( 'woocommerce_before_order_object_save', $ppcw_hook, PHP_INT_MAX );
	add_filter( 'pre_http_request', $ppcw_http, 5, 3 );
	add_filter( 'pre_wp_mail', $ppcw_mail, PHP_INT_MAX );
	$ppcreationworker['stages'][] = 'gateway_started';
	$result = ( new Gateway() )->process_payment( $id );
	$ppcreationworker['stages'][] = 'gateway_returned';
	$after = pp_panapay_creation_worker_book( $id );
	$report = array( 'workerCompleted' => true, 'run' => $run, 'ownedOrderId' => $id, 'gatewayResult' => $result['result'] ?? 'missing',
		'bookBefore' => $before, 'bookAfter' => $after, 'bookUnchanged' => $before === $after, 'state' => $ppcreationworker,
		'providerTransport' => 'simulated only', 'actualProviderOrSettlementTested' => false, 'automaticRollbackClaimed' => false );
} catch ( Throwable $error ) {
	$report = array( 'workerCompleted' => false, 'failureClass' => get_class( $error ), 'reason' => 'worker_scope_or_execution_rejected',
		'state' => $ppcreationworker, 'providerTransport' => 'simulated only', 'actualProviderOrSettlementTested' => false );
} finally {
	if ( $ppcw_hook ) { remove_action( 'woocommerce_before_order_object_save', $ppcw_hook, PHP_INT_MAX ); }
	if ( $ppcw_http ) { remove_filter( 'pre_http_request', $ppcw_http, 5 ); }
	if ( $ppcw_mail ) { remove_filter( 'pre_wp_mail', $ppcw_mail, PHP_INT_MAX ); }
	$discarded = 0; while ( ob_get_level() > $ppcw_output_level ) { $discarded += strlen( (string) ob_get_clean() ); }
	$report['suppressedOutputBytes'] = $discarded;
	unset( $packet, $config, $result );
}
echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( ! $report['workerCompleted'] ) { throw new RuntimeException( 'Panapay creation worker stopped.' ); }
