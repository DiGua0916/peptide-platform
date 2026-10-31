<?php
/**
 * Disposable native creation-reservation cases. Prepare/review first; run with WP-CLI only.
 * Woo CRUD and this installed plugin are real. Only createPayment HTTP transport is simulated.
 */
use VialCove\Panapay\{Configuration, Crypto, Database, Gateway, Order_Payload, Persistence, Protocol_Error, Session_Service, Signature, Store};
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || WP_CLI !== true
	|| ! defined( 'PC_PANAPAY_TESTING' ) || PC_PANAPAY_TESTING !== true
	|| wp_get_environment_type() !== 'local' || ! defined( 'DB_NAME' ) || ! str_starts_with( DB_NAME, 'panapay_qa_' ) ) {
	throw new RuntimeException( 'Disposable Panapay CLI QA only.' );
}

global $ppcr_run, $ppcr_secret, $ppcr_results, $ppcr_orders, $ppcr_products, $ppcr_requests, $ppcr_http_calls, $ppcr_mode, $ppcr_complete;
$ppcr_run = bin2hex( random_bytes( 6 ) ); $ppcr_secret = 'synthetic-creation-reservation-key';
$ppcr_results = $ppcr_orders = $ppcr_products = $ppcr_requests = $ppcr_complete = array();
$ppcr_http_calls = 0; $ppcr_mode = 'redirect';

function pp_panapay_cr_assert( bool $yes, string $name ): void {
	$GLOBALS['ppcr_results'][] = array( 'name' => $name, 'passed' => $yes );
	if ( ! $yes ) { throw new RuntimeException( 'assertion:' . $name ); }
}

function pp_panapay_cr_order(): WC_Order {
	$p = new WC_Product_Simple(); $p->set_name( 'Synthetic creation item ' . $GLOBALS['ppcr_run'] );
	$p->set_status( 'publish' ); $p->set_regular_price( '19.90' ); $p->set_manage_stock( true ); $p->set_stock_quantity( 10 );
	$p->update_meta_data( '_pc_panapay_creation_run', $GLOBALS['ppcr_run'] ); $p->save();
	$GLOBALS['ppcr_products'][] = $p->get_id();
	$o = wc_create_order(); $o->set_payment_method( Gateway::ID ); $o->set_currency( 'USD' );
	$a = array( 'first_name' => 'Synthetic', 'last_name' => 'Creation QA', 'address_1' => '123 Synthetic Test Street',
		'city' => 'Test City', 'state' => 'NY', 'postcode' => '10001', 'country' => 'US',
		'email' => 'qa-panapay-creation@example.invalid', 'phone' => '+12025550123' );
	$o->set_address( $a, 'billing' ); $o->set_address( $a, 'shipping' ); $o->add_product( $p, 1 );
	$o->update_meta_data( '_pc_panapay_creation_run', $GLOBALS['ppcr_run'] ); $o->calculate_totals(); $o->save();
	$GLOBALS['ppcr_orders'][] = $o->get_id();
	return new WC_Order( $o->get_id() );
}

function pp_panapay_cr_book( int $id ): array {
	$o = new WC_Order( $id ); $book = $o->get_meta( '_pc_panapay_sessions', true );
	if ( ! is_array( $book ) || ! is_array( $book['attempts'] ?? null ) || ! is_string( $book['active'] ?? null ) ) { throw new RuntimeException( 'assertion:book_not_available' ); }
	Persistence::assert_book( $o, $book );
	return $book;
}

function pp_panapay_cr_creation( int $id ): array {
	$db = Database::open();
	try {
		$table = Store::creations_table();
		if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) ) { throw new RuntimeException( 'assertion:invalid_creation_table' ); }
		$rows = $db->get_results( $db->prepare( "SELECT attempt_id,state,owner_token FROM `{$table}` WHERE order_id=%d LIMIT 2", $id ), ARRAY_A );
		if ( ! is_array( $rows ) || count( $rows ) !== 1 || '' !== (string) $db->last_error ) { throw new RuntimeException( 'assertion:creation_row_not_available' ); }
		return $rows[0];
	} finally { $db->close(); }
}

function pp_panapay_cr_request( int $id, bool $first = false ): array {
	$requests = $first ? $GLOBALS['ppcr_requests'] : array_reverse( $GLOBALS['ppcr_requests'] );
	foreach ( $requests as $r ) { if ( Session_Service::parse_reference( $r['order_id'] )['order_id'] === $id ) { return $r; } }
	throw new RuntimeException( 'assertion:missing_synthetic_request' );
}

function pp_panapay_cr_notify( string $reference, string $status, string $tx ): array {
	$d = array( 'order_number' => $reference, 'status' => $status, 'transaction_id' => $tx );
	$d['sign'] = Signature::generate( $d, $GLOBALS['ppcr_secret'] ); return $d;
}

function pp_panapay_cr_reserve_rejected( int $id, string $attempt ): bool {
	try { Store::reserve_creation( $id, $attempt ); }
	catch ( RuntimeException $error ) { return 'payment_creation_busy' === $error->getMessage(); }
	return false;
}

$ppcr_mock = static function ( $pre, $args, $url ) {
	if ( 'https://example.com/index.php/admin/Payments/createPayment' !== $url ) {
		return new WP_Error( 'panapay_creation_other_http_blocked', 'Synthetic creation test blocks other HTTP.' );
	}
	$body = json_decode( $args['body'], true, 32, JSON_THROW_ON_ERROR );
	if ( ! in_array( Session_Service::parse_reference( $body['order_id'] )['order_id'], $GLOBALS['ppcr_orders'], true ) ) {
		throw new RuntimeException( 'assertion:provider_request_not_owned' );
	}
	$GLOBALS['ppcr_http_calls']++; $GLOBALS['ppcr_requests'][] = $body;
	if ( 'timeout' === $GLOBALS['ppcr_mode'] ) { return new WP_Error( 'synthetic_creation_timeout', 'Synthetic timeout.' ); }
	$response = 'failed' === $GLOBALS['ppcr_mode'] ? array( 'status' => 'FAILED', 'message' => 'Synthetic decline.' )
		: array( 'status' => 'SUCCEEDED', 'type' => 'redirect', 'payment_url' => 'https://example.com/synthetic-creation?session=' . bin2hex( random_bytes( 12 ) ) );
	return array( 'headers' => array( 'content-type' => 'application/json' ), 'cookies' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $response ) );
};
$ppcr_hook = static function ( $id ) {
	if ( in_array( (int) $id, $GLOBALS['ppcr_orders'], true ) ) { $GLOBALS['ppcr_complete'][ $id ] = ( $GLOBALS['ppcr_complete'][ $id ] ?? 0 ) + 1; }
};
$ppcr_nomail = static fn() => false;
$ppcr_previous_settings = get_option( 'woocommerce_pc_panapay_settings', false ); $ppcr_previous_currency = get_option( 'woocommerce_currency', false );
$ppcr_output_level = ob_get_level(); ob_start();
add_filter( 'pre_http_request', $ppcr_mock, 5, 3 ); add_filter( 'pre_wp_mail', $ppcr_nomail, PHP_INT_MAX );
add_action( 'woocommerce_payment_complete', $ppcr_hook );
try {
	update_option( 'woocommerce_currency', 'USD' );
	update_option( 'woocommerce_pc_panapay_settings', array( 'enabled' => 'yes', 'title' => 'Synthetic creation card', 'description' => 'Synthetic creation test.',
		'test_mode' => 'yes', 'gateway' => 'https://example.com', 'mchid' => 'qa-creation', 'secret' => Crypto::encrypt( $ppcr_secret ),
		'mode' => '2', 'tag' => '', 'payment_hosts' => 'example.com' ), false );
	$config = Configuration::load();
	pp_panapay_cr_assert( Store::installed() && get_option( 'pc_panapay_db_version' ) === '3' && $config['gateway'] === 'https://example.com'
		&& $config['mchid'] === 'qa-creation' && $config['secret'] === $ppcr_secret && $config['test_mode'] === true, 'schema3_and_synthetic_configuration' );
	$gateway = new Gateway(); pp_panapay_cr_assert( $gateway->is_available(), 'native_gateway_available' );

	// A definite remote FAILED can be replaced, unlike an uncertain in-flight creation.
	$failed = pp_panapay_cr_order(); $failed_id = $failed->get_id(); $ppcr_mode = 'failed';
	$calls = $ppcr_http_calls; $result = $gateway->process_payment( $failed_id );
	$oldbook = pp_panapay_cr_book( $failed_id ); $oldid = $oldbook['active']; $oldreservation = $oldbook['attempts'][ $oldid ]['creation_reservation'];
	pp_panapay_cr_assert( ( $result['result'] ?? '' ) === 'failure' && $ppcr_http_calls === $calls + 1
		&& $oldbook['attempts'][ $oldid ]['state'] === 'failed' && pp_panapay_cr_creation( $failed_id )['state'] === 'failed', 'definite_remote_failure_persists_failed_reservation' );
	$oldrequest = pp_panapay_cr_request( $failed_id, true ); $ppcr_mode = 'redirect';
	$result = $gateway->process_payment( $failed_id ); $newbook = pp_panapay_cr_book( $failed_id ); $newid = $newbook['active'];
	pp_panapay_cr_assert( ( $result['result'] ?? '' ) === 'success' && $newid !== $oldid && count( $newbook['attempts'] ) === 2
		&& $newbook['attempts'][ $newid ]['state'] === 'created' && pp_panapay_cr_creation( $failed_id )['attempt_id'] === $newid
		&& $ppcr_http_calls === $calls + 2, 'only_confirmed_failure_allows_new_remote_attempt' );
	$stable = pp_panapay_cr_creation( $failed_id ); $stable_book = hash( 'sha256', maybe_serialize( $newbook ) );
	$stale_rejected = false;
	try { Store::set_creation_state( $oldreservation, 'unknown' ); }
	catch ( RuntimeException $error ) { $stale_rejected = 'payment_creation_busy' === $error->getMessage(); }
	pp_panapay_cr_assert( $stale_rejected && pp_panapay_cr_creation( $failed_id ) === $stable, 'stale_owner_cannot_update_new_attempt_reservation' );
	Store::fail_creation( $failed_id, $oldid );
	pp_panapay_cr_assert( pp_panapay_cr_creation( $failed_id ) === $stable, 'old_attempt_failure_cannot_release_new_reservation' );
	Session_Service::apply_notification( pp_panapay_cr_notify( $oldrequest['order_id'], 'FAILED', 'qa-late-old-failure-' . $ppcr_run ) );
	pp_panapay_cr_assert( hash( 'sha256', maybe_serialize( pp_panapay_cr_book( $failed_id ) ) ) === $stable_book
		&& pp_panapay_cr_creation( $failed_id ) === $stable, 'signed_late_old_failure_preserves_new_session_book_and_reservation' );
	pp_panapay_cr_assert( ! ( new WC_Order( $failed_id ) )->is_paid(), 'remote_creation_and_old_failure_do_not_pay_order' );

	// Unknown remote outcome cannot be taken over or cause a blind second HTTP request.
	$unknown = pp_panapay_cr_order(); $unknown_id = $unknown->get_id(); $ppcr_mode = 'timeout';
	$result = $gateway->process_payment( $unknown_id ); $unknownbook = pp_panapay_cr_book( $unknown_id ); $unknownrow = pp_panapay_cr_creation( $unknown_id );
	pp_panapay_cr_assert( ( $result['result'] ?? '' ) === 'failure' && $unknownrow['state'] === 'unknown'
		&& $unknownbook['attempts'][ $unknownbook['active'] ]['state'] === 'unknown', 'unknown_remote_result_stays_unconfirmed' );
	$calls = $ppcr_http_calls; $ppcr_mode = 'redirect'; $result = $gateway->process_payment( $unknown_id );
	pp_panapay_cr_assert( ( $result['result'] ?? '' ) === 'failure' && $ppcr_http_calls === $calls
		&& pp_panapay_cr_creation( $unknown_id ) === $unknownrow && pp_panapay_cr_book( $unknown_id ) === $unknownbook, 'unknown_attempt_has_no_blind_http_retry_or_book_rewrite' );
	pp_panapay_cr_assert( pp_panapay_cr_reserve_rejected( $unknown_id, bin2hex( random_bytes( 16 ) ) ), 'unknown_reservation_has_no_clock_based_takeover' );

	// Explicit native fixture: a fresh own order has a fully persisted creating book and its real reservation.
	// No fake Woo method or provider success is used to create the in-flight state.
	$creating = pp_panapay_cr_order(); $creating_id = $creating->get_id(); $attempt_id = bin2hex( random_bytes( 16 ) );
	$reservation = Store::reserve_creation( $creating_id, $attempt_id ); $reference = 'PP' . Store::site_id() . '-' . $creating_id . '-' . $attempt_id;
	$token = bin2hex( random_bytes( 24 ) ); $payload = Order_Payload::build( $creating, $config, $reference, $token );
	$book = array( 'active' => $attempt_id, 'attempts' => array( $attempt_id => array(
		'reference' => $reference, 'state' => 'creating', 'created_at' => time(), 'fingerprint' => Order_Payload::fingerprint( $creating ),
		'amount' => (string) $creating->get_total(), 'currency' => $creating->get_currency(), 'secret_box' => Crypto::encrypt( $config['secret'] ),
		'return_token_hash' => hash( 'sha256', $token ), 'test_mode' => true,
		'merchant_scope' => hash( 'sha256', $config['gateway'] . '|' . $config['mchid'] ), 'creation_reservation' => $reservation,
	) ) );
	unset( $payload, $token ); $creating->update_meta_data( '_pc_panapay_sessions', $book ); $creating->save(); Persistence::assert_book( $creating, $book );
	pp_panapay_cr_assert( ! Store::creation_ready( $creating_id ) && pp_panapay_cr_creation( $creating_id )['state'] === 'creating', 'fresh_creating_reservation_is_not_ready' );
	pp_panapay_cr_assert( pp_panapay_cr_reserve_rejected( $creating_id, bin2hex( random_bytes( 16 ) ) ), 'creating_reservation_cannot_be_replaced' );
	$calls = $ppcr_http_calls; $result = $gateway->process_payment( $creating_id );
	pp_panapay_cr_assert( ( $result['result'] ?? '' ) === 'failure' && $ppcr_http_calls === $calls
		&& pp_panapay_cr_book( $creating_id ) === $book, 'creating_attempt_cannot_send_second_http_or_rewrite_book' );
	$tx = 'qa-creating-notify-' . $ppcr_run; $packet = pp_panapay_cr_notify( $reference, 'SUCCEEDED', $tx ); $deferred = null;
	try { Session_Service::apply_notification( $packet ); } catch ( Throwable $error ) { $deferred = $error; }
	pp_panapay_cr_assert( $deferred instanceof Protocol_Error && 503 === $deferred->getCode()
		&& 'notification_creation_in_progress' === $deferred->getMessage(), 'signed_notification_while_creating_is_deferred_503' );
	pp_panapay_cr_assert( pp_panapay_cr_book( $creating_id ) === $book && ! Persistence::is_paid( $creating_id, Session_Service::native_transaction_id( $tx ) )
		&& ( $ppcr_complete[ $creating_id ] ?? 0 ) === 0, 'deferred_notification_preserves_book_and_does_not_complete' );

	// Only after the owning creation worker commits its ready result may that same signed notification complete.
	$book['attempts'][ $attempt_id ]['state'] = 'created'; $book['attempts'][ $attempt_id ]['type'] = 'redirect';
	$book['attempts'][ $attempt_id ]['url_box'] = Crypto::encrypt( 'https://example.com/synthetic-ready', 'checkout-url' );
	$creating = new WC_Order( $creating_id ); $creating->update_meta_data( '_pc_panapay_sessions', $book ); $creating->save(); Persistence::assert_book( $creating, $book );
	Store::set_creation_state( $reservation, 'created' );
	pp_panapay_cr_assert( Store::creation_ready( $creating_id ), 'own_creation_result_is_now_ready' );
	Session_Service::apply_notification( $packet );
	pp_panapay_cr_assert( Persistence::is_paid( $creating_id, Session_Service::native_transaction_id( $tx ) )
		&& ( $ppcr_complete[ $creating_id ] ?? 0 ) === 1, 'same_notification_after_ready_completes_native_payment_once' );
	pp_panapay_cr_assert( pp_panapay_cr_creation( $creating_id )['state'] === 'paid', 'successful_native_payment_closes_creation_reservation' );
	Session_Service::apply_notification( $packet );
	pp_panapay_cr_assert( ( $ppcr_complete[ $creating_id ] ?? 0 ) === 1, 'ready_payment_duplicate_does_not_repeat_completion' );
	$report = array( 'complete' => true, 'run' => $ppcr_run, 'nativeHPOS' => OrderUtil::custom_orders_table_usage_is_enabled(),
		'nativeDatastore' => WC_Data_Store::load( 'order' )->get_current_class_name(), 'results' => $ppcr_results,
		'ownedOrderIds' => $ppcr_orders, 'ownedProductIds' => $ppcr_products, 'providerHTTPCalls' => $ppcr_http_calls,
		'providerTransport' => 'simulated only', 'actualProviderOrSettlementTested' => false, 'automaticRollbackClaimed' => false );
} catch ( Throwable $error ) {
	$reason = preg_match( '/\Aassertion:[a-z0-9_]{1,120}\z/', $error->getMessage() ) ? $error->getMessage() : 'unexpected_exception_details_withheld';
	$report = array( 'complete' => false, 'run' => $ppcr_run, 'failureClass' => get_class( $error ), 'reason' => $reason,
		'results' => $ppcr_results, 'ownedOrderIds' => $ppcr_orders, 'ownedProductIds' => $ppcr_products, 'providerHTTPCalls' => $ppcr_http_calls,
		'providerTransport' => 'simulated only', 'actualProviderOrSettlementTested' => false );
} finally {
	remove_filter( 'pre_http_request', $ppcr_mock, 5 ); remove_filter( 'pre_wp_mail', $ppcr_nomail, PHP_INT_MAX );
	remove_action( 'woocommerce_payment_complete', $ppcr_hook );
	if ( false === $ppcr_previous_settings ) { delete_option( 'woocommerce_pc_panapay_settings' ); } else { update_option( 'woocommerce_pc_panapay_settings', $ppcr_previous_settings, false ); }
	if ( false === $ppcr_previous_currency ) { delete_option( 'woocommerce_currency' ); } else { update_option( 'woocommerce_currency', $ppcr_previous_currency ); }
	$discarded = 0; while ( ob_get_level() > $ppcr_output_level ) { $discarded += strlen( (string) ob_get_clean() ); }
	$report['suppressedOutputBytes'] = $discarded;
	unset( $config, $ppcr_secret, $ppcr_requests, $ppcr_previous_settings, $book, $packet );
}
echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( ! $report['complete'] ) { throw new RuntimeException( 'Native Panapay creation reservation test stopped.' ); }
