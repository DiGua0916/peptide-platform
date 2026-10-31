<?php
/**
 * Disposable native fault tests. Root must inspect before `wp eval-file` execution.
 * Real WordPress/Woo objects are used; only the provider HTTP response is simulated.
 */
use VialCove\Panapay\{Configuration, Crypto, Database, Gateway, Persistence, Protocol_Error, Session_Service, Signature, Store};
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || WP_CLI !== true
	|| ! defined( 'PC_PANAPAY_TESTING' ) || PC_PANAPAY_TESTING !== true
	|| wp_get_environment_type() !== 'local' || ! defined( 'DB_NAME' ) || ! str_starts_with( DB_NAME, 'panapay_qa_' ) ) {
	throw new RuntimeException( 'Disposable Panapay CLI QA only.' );
}

// WP-CLI eval-file runs in a method scope; callbacks deliberately share prefixed globals.
global $wpdb, $ppfault_results, $ppfault_orders, $ppfault_products, $ppfault_run, $ppfault_secret,
	$ppfault_requests, $ppfault_http_calls, $ppfault_pre_calls, $ppfault_complete_calls;
$ppfault_results = $ppfault_orders = $ppfault_products = $ppfault_requests = $ppfault_pre_calls = $ppfault_complete_calls = array();
$ppfault_run = bin2hex( random_bytes( 6 ) );
$ppfault_secret = 'synthetic-fault-key-no-real-merchant';
$ppfault_http_calls = 0;

function pp_panapay_fault_assert( bool $value, string $name ): void {
	$GLOBALS['ppfault_results'][] = array( 'name' => $name, 'passed' => $value );
	if ( ! $value ) { throw new RuntimeException( 'assertion:' . $name ); }
}

function pp_panapay_fault_order(): WC_Order {
	$product = new WC_Product_Simple();
	$product->set_name( 'Panapay synthetic fault item ' . $GLOBALS['ppfault_run'] );
	$product->set_status( 'publish' );
	$product->set_regular_price( '19.90' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 10 );
	$product->update_meta_data( '_pc_panapay_faults_run', $GLOBALS['ppfault_run'] );
	$product->save();
	$GLOBALS['ppfault_products'][] = $product->get_id();
	$order = wc_create_order();
	$order->set_payment_method( Gateway::ID );
	$order->set_currency( 'USD' );
	$address = array( 'first_name' => 'Synthetic', 'last_name' => 'Fault QA', 'address_1' => '123 Synthetic Test Street',
		'city' => 'Test City', 'state' => 'NY', 'postcode' => '10001', 'country' => 'US',
		'email' => 'qa-panapay-faults@example.invalid', 'phone' => '+12025550123' );
	$order->set_address( $address, 'billing' );
	$order->set_address( $address, 'shipping' );
	$order->add_product( $product, 1 );
	$order->update_meta_data( '_pc_panapay_faults_run', $GLOBALS['ppfault_run'] );
	$order->calculate_totals();
	$order->save();
	$GLOBALS['ppfault_orders'][] = $order->get_id();
	return new WC_Order( $order->get_id() );
}

function pp_panapay_fault_request( int $id ): array {
	foreach ( array_reverse( $GLOBALS['ppfault_requests'] ) as $request ) {
		if ( Session_Service::parse_reference( $request['order_id'] )['order_id'] === $id ) { return $request; }
	}
	throw new RuntimeException( 'assertion:missing_synthetic_request' );
}

function pp_panapay_fault_notification( array $request, string $transaction ): array {
	$data = array( 'order_number' => $request['order_id'], 'status' => 'SUCCEEDED', 'transaction_id' => $transaction );
	$data['sign'] = Signature::generate( $data, $GLOBALS['ppfault_secret'] );
	return $data;
}

/** Only the exact synthetic claim hash is read; the independent writer connection is always closed. */
function pp_panapay_fault_claim_visible( string $hash, int $order_id, string $attempt_id ): bool {
	$db = Database::open();
	try {
		$table = Store::table();
		if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) ) { throw new RuntimeException( 'assertion:invalid_test_table' ); }
		$rows = $db->get_results( $db->prepare(
			"SELECT order_id, attempt_id, completion_order_id FROM `{$table}` WHERE transaction_hash = %s LIMIT 2", $hash
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $db->last_error ) { throw new RuntimeException( 'assertion:independent_claim_read_failed' ); }
		return 1 === count( $rows ) && (int) $rows[0]['order_id'] === $order_id
			&& (int) $rows[0]['completion_order_id'] === $order_id && hash_equals( $attempt_id, (string) $rows[0]['attempt_id'] );
	} finally { $db->close(); }
}

/** A separate authoritative status check also avoids mistaking a mismatched transaction for an unpaid status. */
function pp_panapay_fault_raw_paid_status( int $order_id ): bool {
	$db = Database::open();
	try {
		$hpos = OrderUtil::custom_orders_table_usage_is_enabled();
		$table = $hpos ? OrdersTableDataStore::get_orders_table_name() : $db->posts;
		if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) ) { throw new RuntimeException( 'assertion:invalid_native_test_table' ); }
		$sql = $hpos ? $db->prepare( "SELECT status FROM `{$table}` WHERE id = %d AND type = %s LIMIT 2", $order_id, 'shop_order' )
			: $db->prepare( "SELECT post_status AS status FROM `{$table}` WHERE ID = %d AND post_type = %s LIMIT 2", $order_id, 'shop_order' );
		$rows = $db->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || 1 !== count( $rows ) || '' !== (string) $db->last_error ) { throw new RuntimeException( 'assertion:independent_status_read_failed' ); }
		return in_array( $rows[0]['status'], array_map( static fn( $status ) => 'wc-' . $status, wc_get_is_paid_statuses() ), true );
	} finally { $db->close(); }
}

$ppfault_mock = static function ( $pre, $args, $url ) {
	if ( 'https://example.com/index.php/admin/Payments/createPayment' !== $url ) {
		return false === $pre ? new WP_Error( 'panapay_faults_no_external_http', 'Synthetic fault tests block other HTTP.' ) : $pre;
	}
	$GLOBALS['ppfault_http_calls']++;
	$GLOBALS['ppfault_requests'][] = json_decode( $args['body'], true, 32, JSON_THROW_ON_ERROR );
	return array( 'headers' => array( 'content-type' => 'application/json' ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(),
		'body' => wp_json_encode( array( 'status' => 'SUCCEEDED', 'type' => 'redirect', 'payment_url' => 'https://example.com/synthetic-payment?session=' . bin2hex( random_bytes( 12 ) ) ) ) );
};
$ppfault_pre_hook = static function ( $id ) {
	if ( in_array( (int) $id, $GLOBALS['ppfault_orders'], true ) ) { $GLOBALS['ppfault_pre_calls'][ $id ] = ( $GLOBALS['ppfault_pre_calls'][ $id ] ?? 0 ) + 1; }
};
$ppfault_complete_hook = static function ( $id ) {
	if ( in_array( (int) $id, $GLOBALS['ppfault_orders'], true ) ) { $GLOBALS['ppfault_complete_calls'][ $id ] = ( $GLOBALS['ppfault_complete_calls'][ $id ] ?? 0 ) + 1; }
};
$ppfault_no_mail = static fn() => false;
$ppfault_settings_before = get_option( 'woocommerce_pc_panapay_settings', false );
$ppfault_currency_before = get_option( 'woocommerce_currency', false );
$ppfault_detail = array();
add_filter( 'pre_http_request', $ppfault_mock, 5, 3 );
add_filter( 'pre_wp_mail', $ppfault_no_mail, PHP_INT_MAX );
add_action( 'woocommerce_pre_payment_complete', $ppfault_pre_hook );
add_action( 'woocommerce_payment_complete', $ppfault_complete_hook );

try {
	update_option( 'woocommerce_currency', 'USD' );
	update_option( 'woocommerce_pc_panapay_settings', array( 'enabled' => 'yes', 'title' => 'Synthetic fault card', 'description' => 'Synthetic fault test only.',
		'test_mode' => 'yes', 'gateway' => 'https://example.com', 'mchid' => 'qa-faults', 'secret' => Crypto::encrypt( $ppfault_secret ),
		'mode' => '2', 'tag' => '', 'payment_hosts' => 'example.com' ), false );
	$fault_config = Configuration::load();
	pp_panapay_fault_assert( $fault_config['secret'] === $ppfault_secret && $fault_config['gateway'] === 'https://example.com'
		&& $fault_config['mchid'] === 'qa-faults' && $fault_config['test_mode'] === true && Store::installed(), 'synthetic_configuration_and_installed_store' );
	$gateway = new Gateway();
	pp_panapay_fault_assert( $gateway->is_available(), 'native_gateway_available' );

	// Case 1: WC catches an Exception on the first creating-book save. No provider request is safe yet.
	$creating = pp_panapay_fault_order();
	$creating_id = $creating->get_id(); $creating_faults = 0; $attempted_book = null;
	$creating_fault = static function ( $object ) use ( $creating_id, &$creating_faults, &$attempted_book ) {
		if ( ! $object instanceof WC_Order || $object->get_id() !== $creating_id || 0 !== $creating_faults ) { return; }
		$book = $object->get_meta( '_pc_panapay_sessions', true );
		if ( is_array( $book ) && 'creating' === ( $book['attempts'][ $book['active'] ?? '' ]['state'] ?? '' ) ) {
			$attempted_book = $book; $creating_faults++;
			throw new Exception( 'synthetic_creating_save_fault' );
		}
	};
	$calls_before = $ppfault_http_calls;
	add_action( 'woocommerce_before_order_object_save', $creating_fault, PHP_INT_MAX );
	try { $creating_result = $gateway->process_payment( $creating_id ); }
	finally { remove_action( 'woocommerce_before_order_object_save', $creating_fault, PHP_INT_MAX ); }
	pp_panapay_fault_assert( 1 === $creating_faults && is_array( $attempted_book ), 'creating_save_exception_injected_once' );
	pp_panapay_fault_assert( 'failure' === ( $creating_result['result'] ?? '' ) && $ppfault_http_calls === $calls_before, 'unpersisted_creating_book_blocks_provider_http' );
	$rejected_book = false;
	try { Persistence::assert_book( new WC_Order( $creating_id ), $attempted_book ); }
	catch ( RuntimeException $error ) {
		if ( 'payment_persistence_unconfirmed' !== $error->getMessage() ) { throw $error; }
		$rejected_book = true;
	}
	pp_panapay_fault_assert( $rejected_book && ! pp_panapay_fault_raw_paid_status( $creating_id ), 'raw_store_does_not_confirm_unsaved_book_or_payment' );
	unset( $attempted_book );

	// Case 2: hold native paid-status writes in failure for one completion invocation.
	// Native notes/stock/third-party hooks may already have run; this test claims no automatic rollback.
	$completion = pp_panapay_fault_order(); $completion_id = $completion->get_id();
	pp_panapay_fault_assert( 'success' === ( $gateway->process_payment( $completion_id )['result'] ?? '' ), 'completion_fault_has_persisted_session' );
	$request = pp_panapay_fault_request( $completion_id );
	$transaction = 'qa-fault-completion-' . $ppfault_run;
	$native_transaction = Session_Service::native_transaction_id( $transaction );
	$packet = pp_panapay_fault_notification( $request, $transaction );
	$paid_save_faults = 0;
	$completion_fault = static function ( $object ) use ( $completion_id, $native_transaction, &$paid_save_faults ) {
		if ( $object instanceof WC_Order && $object->get_id() === $completion_id
			&& in_array( $object->get_status( 'edit' ), wc_get_is_paid_statuses(), true )
			&& $native_transaction === $object->get_transaction_id( 'edit' ) ) {
			$paid_save_faults++;
			throw new Exception( 'synthetic_payment_complete_save_fault' );
		}
	};
	add_action( 'woocommerce_before_order_object_save', $completion_fault, PHP_INT_MAX );
	$first_error = null;
	try { Session_Service::apply_notification( $packet ); }
	catch ( Throwable $error ) { $first_error = $error; }
	finally { remove_action( 'woocommerce_before_order_object_save', $completion_fault, PHP_INT_MAX ); }
	pp_panapay_fault_assert( $paid_save_faults >= 1 && ( $ppfault_pre_calls[ $completion_id ] ?? 0 ) === 1, 'native_completion_invoked_and_paid_save_failed' );
	pp_panapay_fault_assert( $first_error instanceof Protocol_Error && 503 === $first_error->getCode(), 'unconfirmed_native_completion_requests_retry_without_ack' );
	pp_panapay_fault_assert( ! Persistence::is_paid( $completion_id, $native_transaction ) && ! pp_panapay_fault_raw_paid_status( $completion_id ), 'raw_authoritative_payment_still_unpaid' );
	pp_panapay_fault_assert( 0 === ( $ppfault_complete_calls[ $completion_id ] ?? 0 ), 'unsaved_payment_does_not_emit_downstream_native_complete_hook' );
	$fault_items = ( new WC_Order( $completion_id ) )->get_items();
	$fault_item = reset( $fault_items );
	pp_panapay_fault_assert( (float) wc_get_product( $fault_item->get_product_id() )->get_stock_quantity() === 10.0, 'unsaved_paid_status_does_not_reduce_core_product_stock' );
	$pre_before_retry = $ppfault_pre_calls[ $completion_id ] ?? 0;
	$complete_before_retry = $ppfault_complete_calls[ $completion_id ] ?? 0;
	$retry_error = null;
	try { Session_Service::apply_notification( $packet ); }
	catch ( Throwable $error ) { $retry_error = $error; }
	pp_panapay_fault_assert( $retry_error instanceof Protocol_Error && 503 === $retry_error->getCode()
		&& ( $ppfault_pre_calls[ $completion_id ] ?? 0 ) === $pre_before_retry
		&& ( $ppfault_complete_calls[ $completion_id ] ?? 0 ) === $complete_before_retry, 'durable_claim_prevents_second_native_completion_after_failure' );
	pp_panapay_fault_assert( ! Persistence::is_paid( $completion_id, $native_transaction ), 'retry_does_not_fake_persisted_payment' );
	$ppfault_detail['completionFailure'] = array( 'blockedPaidSaveAttempts' => $paid_save_faults,
		'nativePrePaymentCompleteCalls' => $pre_before_retry, 'nativePaymentCompleteCalls' => $complete_before_retry,
		'automaticRollbackProven' => false, 'manualReconciliationRequired' => true );

	// Case 3: a financial claim must commit independently of an enclosing caller transaction.
	$claim_order = pp_panapay_fault_order(); $claim_order_id = $claim_order->get_id();
	$attempt_id = bin2hex( random_bytes( 16 ) ); $scope = hash( 'sha256', 'synthetic-fault-scope|' . $ppfault_run );
	$claim_transaction = 'qa-fault-claim-' . $ppfault_run;
	$hash = Store::transaction_hash( $claim_transaction, $scope );
	$lease = Store::acquire( $claim_order_id ); $successor = null; $outer_transaction = false;
	try {
		// MySQL 8 has no MariaDB @@in_transaction variable. Reset only this disposable
		// test connection before starting the deliberately enclosing transaction.
		pp_panapay_fault_assert( false !== $wpdb->query( 'ROLLBACK' ), 'owned_claim_case_starts_with_no_open_transaction' );
		$prior_autocommit = $wpdb->get_var( 'SELECT @@autocommit' );
		pp_panapay_fault_assert( '' === (string) $wpdb->last_error && '1' === (string) $prior_autocommit, 'caller_connection_uses_autocommit_before_outer_transaction' );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new RuntimeException( 'assertion:outer_transaction_unavailable' ); }
		$outer_transaction = true;
		$claim = Store::claim( $claim_transaction, $claim_order_id, $attempt_id, $scope );
		pp_panapay_fault_assert( $claim['can_complete'] === true && $claim['fresh'] === true, 'first_claim_has_one_completion_permission' );
		pp_panapay_fault_assert( pp_panapay_fault_claim_visible( $hash, $claim_order_id, $attempt_id ), 'claim_visible_from_independent_connection_before_caller_commit' );
		if ( false === $wpdb->query( 'ROLLBACK' ) ) { throw new RuntimeException( 'assertion:outer_rollback_failed' ); }
		$outer_transaction = false;
		pp_panapay_fault_assert( pp_panapay_fault_claim_visible( $hash, $claim_order_id, $attempt_id ), 'financial_claim_survives_caller_rollback' );

		pp_panapay_fault_assert( $lease['name'] === 'pc_panapay_lock_' . $claim_order_id, 'lease_targets_exact_owned_order' );
		$pieces = explode( '|', $lease['value'], 2 );
		if ( count( $pieces ) !== 2 || ! preg_match( '/\A[a-f0-9]{32}\z/', $pieces[1] ) ) { throw new RuntimeException( 'assertion:invalid_owned_lease_shape' ); }
		$expired = sprintf( '%.6F', microtime( true ) - 1 ) . '|' . $pieces[1];
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $expired, $lease['name'], $lease['value'] ) );
		pp_panapay_fault_assert( 1 === $changed, 'only_owned_lease_expired_by_value_cas_without_sleep' );
		$successor = Store::acquire( $claim_order_id );
		$old_rejected = false;
		try { Store::assert_owner( $lease ); } catch ( RuntimeException $error ) { $old_rejected = 'payment_lock_expired' === $error->getMessage(); }
		pp_panapay_fault_assert( $old_rejected, 'old_lease_has_no_ownership_after_takeover' );
		Store::release( $lease );
		Store::assert_owner( $successor );
		pp_panapay_fault_assert( true, 'old_owner_release_cannot_delete_successor_lease' );
		$again = Store::claim( $claim_transaction, $claim_order_id, $attempt_id, $scope );
		pp_panapay_fault_assert( $again['can_complete'] === false && $again['fresh'] === false, 'same_transaction_after_lease_expiry_has_no_second_completion_permission' );
		$another = Store::claim( 'qa-fault-other-' . $ppfault_run, $claim_order_id, bin2hex( random_bytes( 16 ) ), $scope );
		pp_panapay_fault_assert( $another['can_complete'] === false && $another['state'] === 'review', 'different_transaction_after_lease_expiry_cannot_reuse_order_completion_slot' );
	} finally {
		if ( $outer_transaction ) { $wpdb->query( 'ROLLBACK' ); }
		if ( is_array( $successor ) ) { Store::release( $successor ); }
		Store::release( $lease );
	}
	$ppfault_report = array( 'complete' => true, 'run' => $ppfault_run, 'nativeHPOS' => OrderUtil::custom_orders_table_usage_is_enabled(),
		'nativeDatastore' => WC_Data_Store::load( 'order' )->get_current_class_name(), 'results' => $ppfault_results, 'details' => $ppfault_detail,
		'nativeHookCounts' => array( 'prePaymentComplete' => $ppfault_pre_calls, 'paymentComplete' => $ppfault_complete_calls ),
		'ownedOrderIds' => $ppfault_orders, 'ownedProductIds' => $ppfault_products, 'providerTransport' => 'simulated only',
		'actualProviderOrSettlementTested' => false, 'automaticRollbackClaimed' => false );
} catch ( Throwable $error ) {
	$message = $error->getMessage();
	$reason = preg_match( '/\Aassertion:[a-z0-9_]{1,120}\z/', $message ) ? $message : 'unexpected_exception_details_withheld';
	$ppfault_report = array( 'complete' => false, 'run' => $ppfault_run, 'failureClass' => get_class( $error ), 'reason' => $reason,
		'results' => $ppfault_results, 'details' => $ppfault_detail, 'ownedOrderIds' => $ppfault_orders, 'ownedProductIds' => $ppfault_products,
		'nativeHookCounts' => array( 'prePaymentComplete' => $ppfault_pre_calls, 'paymentComplete' => $ppfault_complete_calls ),
		'providerTransport' => 'simulated only', 'actualProviderOrSettlementTested' => false, 'automaticRollbackClaimed' => false );
} finally {
	remove_filter( 'pre_http_request', $ppfault_mock, 5 );
	remove_filter( 'pre_wp_mail', $ppfault_no_mail, PHP_INT_MAX );
	remove_action( 'woocommerce_pre_payment_complete', $ppfault_pre_hook );
	remove_action( 'woocommerce_payment_complete', $ppfault_complete_hook );
	if ( false === $ppfault_settings_before ) { delete_option( 'woocommerce_pc_panapay_settings' ); }
	else { update_option( 'woocommerce_pc_panapay_settings', $ppfault_settings_before, false ); }
	if ( false === $ppfault_currency_before ) { delete_option( 'woocommerce_currency' ); }
	else { update_option( 'woocommerce_currency', $ppfault_currency_before ); }
	unset( $ppfault_secret, $ppfault_requests, $ppfault_settings_before, $fault_config );
}
echo wp_json_encode( $ppfault_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( ! $ppfault_report['complete'] ) { throw new RuntimeException( 'Native Panapay fault test stopped.' ); }
