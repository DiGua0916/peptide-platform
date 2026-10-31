<?php
/**
 * Root-run WP-CLI notification worker; never load on a storefront.
 * PC_PANAPAY_TEST_PACKET is a private D JSON file:
 * {"schema":1,"run":"<12-64 lowercase hex>","order_id":123,"notification":{...signed POST fields...}}
 * The same owned order must carry _pc_panapay_concurrency_run=<run> and an @example.invalid billing email.
 * Optional READY and RELEASE environment paths must both be in that packet's private directory.
 * Only the notification service is invoked: the reported HTTP status/body models Callbacks::notify().
 */
use VialCove\Panapay\{Gateway, Persistence, Protocol_Error, Session_Service};

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || WP_CLI !== true
	|| ! defined( 'PC_PANAPAY_TESTING' ) || PC_PANAPAY_TESTING !== true
	|| wp_get_environment_type() !== 'local' || ! defined( 'DB_NAME' ) || ! str_starts_with( DB_NAME, 'panapay_qa_' ) ) {
	throw new RuntimeException( 'Disposable Panapay CLI QA only.' );
}

/** Resolve only a named private D file; never accept a URL, relative path, link or arbitrary output directory. */
function pp_panapay_worker_path( string $value, bool $must_exist, ?string $same_directory = null ): string {
	$value = str_replace( '\\', '/', $value );
	if ( ! preg_match( '/\AD:\//i', $value ) || str_contains( $value, "\0" ) ) { throw new RuntimeException( 'worker_path_rejected' ); }
	$directory = realpath( dirname( $value ) );
	$name = basename( $value );
	if ( false === $directory || ! preg_match( '/\A[A-Za-z0-9_.-]{1,120}\z/', $name ) || in_array( $name, array( '.', '..' ), true ) ) {
		throw new RuntimeException( 'worker_path_rejected' );
	}
	$directory = str_replace( '\\', '/', $directory );
	$lower = strtolower( $directory );
	if ( ! str_starts_with( $lower, 'd:/' ) || ( ! str_ends_with( $lower, '/private' ) && ! str_contains( $lower, '/private/' ) )
		|| ( null !== $same_directory && strcasecmp( $directory, $same_directory ) !== 0 ) ) {
		throw new RuntimeException( 'worker_path_rejected' );
	}
	$path = $directory . '/' . $name;
	if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) || ( $must_exist && ! is_file( $path ) ) ) {
		throw new RuntimeException( 'worker_path_rejected' );
	}
	return $path;
}

global $ppworker_state;
$ppworker_state = array( 'stages' => array(), 'hooks' => array( 'prePaymentComplete' => 0, 'paymentComplete' => 0, 'statusChanged' => 0 ),
	'outboundHTTPAttemptsBlocked' => 0, 'barrierEntered' => false, 'barrierReleased' => false, 'barrierFailure' => null );
$ppworker_order_id = 0;
$ppworker_pre = $ppworker_complete = $ppworker_status = $ppworker_http = $ppworker_mail = null;
$ppworker_output_level = ob_get_level();
ob_start();

try {
	$packet_input = getenv( 'PC_PANAPAY_TEST_PACKET' );
	if ( ! is_string( $packet_input ) || '' === $packet_input ) { throw new RuntimeException( 'worker_packet_required' ); }
	$packet_path = pp_panapay_worker_path( $packet_input, true );
	if ( ! str_ends_with( strtolower( $packet_path ), '.json' ) || filesize( $packet_path ) > 8192 ) { throw new RuntimeException( 'worker_packet_rejected' ); }
	$packet = json_decode( file_get_contents( $packet_path ), true, 16, JSON_THROW_ON_ERROR );
	if ( ! is_array( $packet ) || ( $packet['schema'] ?? null ) !== 1 || ! is_string( $packet['run'] ?? null )
		|| ! preg_match( '/\A[a-f0-9]{12,64}\z/', $packet['run'] ) || ! is_int( $packet['order_id'] ?? null ) || $packet['order_id'] <= 0
		|| ! is_array( $packet['notification'] ?? null ) ) { throw new RuntimeException( 'worker_packet_rejected' ); }
	$top_keys = array_keys( $packet ); sort( $top_keys );
	if ( $top_keys !== array( 'notification', 'order_id', 'run', 'schema' ) ) { throw new RuntimeException( 'worker_packet_rejected' ); }
	$data = $packet['notification'];
	$keys = array_keys( $data ); sort( $keys );
	if ( $keys !== array( 'order_number', 'sign', 'status', 'transaction_id' ) ) { throw new RuntimeException( 'worker_packet_rejected' ); }
	foreach ( $data as $value ) { if ( ! is_string( $value ) || strlen( $value ) > 512 ) { throw new RuntimeException( 'worker_packet_rejected' ); } }
	$parsed = Session_Service::parse_reference( $data['order_number'] );
	$ppworker_order_id = $packet['order_id'];
	if ( $parsed['order_id'] !== $ppworker_order_id ) { throw new RuntimeException( 'worker_owned_order_required' ); }
	$order = wc_get_order( $ppworker_order_id );
	if ( ! $order instanceof WC_Order || Gateway::ID !== $order->get_payment_method()
		|| ! is_string( $order->get_meta( '_pc_panapay_concurrency_run', true ) )
		|| ! hash_equals( $packet['run'], $order->get_meta( '_pc_panapay_concurrency_run', true ) )
		|| ! str_ends_with( strtolower( $order->get_billing_email() ), '@example.invalid' ) ) {
		throw new RuntimeException( 'worker_owned_order_required' );
	}
	$ppworker_state['stages'][] = 'owned_packet_checked';
	$ready_input = getenv( 'PC_PANAPAY_TEST_READY' ); $release_input = getenv( 'PC_PANAPAY_TEST_RELEASE' );
	$has_ready = is_string( $ready_input ) && '' !== $ready_input;
	$has_release = is_string( $release_input ) && '' !== $release_input;
	if ( $has_ready !== $has_release ) { throw new RuntimeException( 'worker_barrier_pair_required' ); }
	$ready_path = $release_path = '';
	if ( $has_ready ) {
		$directory = str_replace( '\\', '/', dirname( $packet_path ) );
		$ready_path = pp_panapay_worker_path( $ready_input, false, $directory );
		$release_path = pp_panapay_worker_path( $release_input, false, $directory );
		if ( $ready_path === $release_path || $ready_path === $packet_path || $release_path === $packet_path ) { throw new RuntimeException( 'worker_barrier_paths_rejected' ); }
	}
	$run = $packet['run'];
	$ppworker_pre = static function ( $id ) use ( $ppworker_order_id, $ready_path, $release_path, $run ) {
		if ( (int) $id !== $ppworker_order_id ) { return; }
		$GLOBALS['ppworker_state']['hooks']['prePaymentComplete']++;
		$GLOBALS['ppworker_state']['stages'][] = 'native_pre_payment_complete';
		if ( '' === $ready_path ) { return; }
		$GLOBALS['ppworker_state']['barrierEntered'] = true;
		$handle = @fopen( $ready_path, 'xb' );
		if ( false === $handle ) {
			$GLOBALS['ppworker_state']['barrierFailure'] = 'ready_file_unavailable';
			throw new RuntimeException( 'worker_ready_file_unavailable' );
		}
		try {
			$bytes = wp_json_encode( array( 'run' => $run, 'stage' => 'native_pre_payment_complete', 'order_id' => $ppworker_order_id, 'pid' => getmypid() ) );
			if ( ! is_string( $bytes ) || fwrite( $handle, $bytes ) !== strlen( $bytes ) || ! fflush( $handle ) ) {
				$GLOBALS['ppworker_state']['barrierFailure'] = 'ready_write_failed';
				throw new RuntimeException( 'worker_ready_write_failed' );
			}
		} finally { fclose( $handle ); }
		$deadline = microtime( true ) + 30;
		do {
			clearstatcache( true, $release_path );
			if ( is_file( $release_path ) && ! is_link( $release_path ) ) {
				$GLOBALS['ppworker_state']['barrierReleased'] = true;
				$GLOBALS['ppworker_state']['stages'][] = 'release_seen';
				return;
			}
			usleep( 100000 );
		} while ( microtime( true ) < $deadline );
		$GLOBALS['ppworker_state']['barrierFailure'] = 'timeout';
		throw new RuntimeException( 'worker_barrier_timeout' );
	};
	$ppworker_complete = static function ( $id ) use ( $ppworker_order_id ) {
		if ( (int) $id === $ppworker_order_id ) { $GLOBALS['ppworker_state']['hooks']['paymentComplete']++; }
	};
	$ppworker_status = static function ( $id ) use ( $ppworker_order_id ) {
		if ( (int) $id === $ppworker_order_id ) { $GLOBALS['ppworker_state']['hooks']['statusChanged']++; }
	};
	$ppworker_http = static function ( $pre ) {
		$GLOBALS['ppworker_state']['outboundHTTPAttemptsBlocked']++;
		return false === $pre ? new WP_Error( 'panapay_worker_no_external_http', 'Synthetic notification worker blocks outbound HTTP.' ) : $pre;
	};
	$ppworker_mail = static fn() => false;
	add_action( 'woocommerce_pre_payment_complete', $ppworker_pre, PHP_INT_MAX );
	add_action( 'woocommerce_payment_complete', $ppworker_complete );
	add_action( 'woocommerce_order_status_changed', $ppworker_status );
	add_filter( 'pre_http_request', $ppworker_http, PHP_INT_MAX );
	add_filter( 'pre_wp_mail', $ppworker_mail, PHP_INT_MAX );
	$ppworker_state['stages'][] = 'notification_started';
	$http_status = 200; $body = 'ok'; $exception_class = null;
	try { Session_Service::apply_notification( $data ); $ppworker_state['stages'][] = 'notification_returned'; }
	catch ( Throwable $error ) {
		$code = $error instanceof Protocol_Error ? $error->getCode() : 503;
		$http_status = in_array( $code, array( 400, 405, 409, 413, 503 ), true ) ? $code : 503;
		$body = 'notification_not_accepted'; $exception_class = get_class( $error );
		$ppworker_state['stages'][] = 'notification_rejected';
	}
	$paid = Persistence::is_paid( $ppworker_order_id, Session_Service::native_transaction_id( $data['transaction_id'] ) );
	$ppworker_report = array( 'workerCompleted' => true, 'run' => $run, 'ownedOrderId' => $ppworker_order_id,
		'notificationHTTPResponseIsModel' => true, 'notificationHTTPStatus' => $http_status, 'notificationBody' => $body,
		'exceptionClass' => $exception_class, 'rawPersistedPaid' => $paid, 'state' => $ppworker_state,
		'actualProviderOrSettlementTested' => false, 'automaticRollbackClaimed' => false );
} catch ( Throwable $error ) {
	$ppworker_report = array( 'workerCompleted' => false, 'failureClass' => get_class( $error ),
		'reason' => 'worker_scope_or_execution_rejected', 'state' => $ppworker_state,
		'actualProviderOrSettlementTested' => false, 'automaticRollbackClaimed' => false );
} finally {
	if ( $ppworker_pre ) { remove_action( 'woocommerce_pre_payment_complete', $ppworker_pre, PHP_INT_MAX ); }
	if ( $ppworker_complete ) { remove_action( 'woocommerce_payment_complete', $ppworker_complete ); }
	if ( $ppworker_status ) { remove_action( 'woocommerce_order_status_changed', $ppworker_status ); }
	if ( $ppworker_http ) { remove_filter( 'pre_http_request', $ppworker_http, PHP_INT_MAX ); }
	if ( $ppworker_mail ) { remove_filter( 'pre_wp_mail', $ppworker_mail, PHP_INT_MAX ); }
	$discarded_bytes = 0;
	while ( ob_get_level() > $ppworker_output_level ) { $discarded_bytes += strlen( (string) ob_get_clean() ); }
	$ppworker_report['suppressedOutputBytes'] = $discarded_bytes;
	unset( $packet, $data, $packet_input, $packet_path );
}
echo wp_json_encode( $ppworker_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( ! $ppworker_report['workerCompleted'] ) { throw new RuntimeException( 'Panapay notification worker stopped.' ); }
