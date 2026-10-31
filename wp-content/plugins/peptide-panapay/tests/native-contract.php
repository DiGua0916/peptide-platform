<?php
/**
 * wp eval-file .../tests/native-contract.php
 * Run only in a disposable payment QA database, with PC_PANAPAY_TESTING=true.
 * Uses real WordPress/Woo CRUD and gateway methods. Only the provider HTTP transport is simulated.
 */
use VialCove\Panapay\{Gateway, Crypto, Signature, Session_Service, Store, Persistence};
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) || ! defined( 'PC_PANAPAY_TESTING' ) || PC_PANAPAY_TESTING !== true
	|| wp_get_environment_type() !== 'local' || ! str_starts_with( DB_NAME, 'panapay_qa_' ) ) {
	throw new RuntimeException( 'Disposable Panapay QA only.' );
}
// WP-CLI eval-file executes in a method scope, so explicitly bind callback state.
global $qa_results, $qa_owned_orders, $qa_run, $qa_secret, $qa_requests, $qa_mode, $qa_http_calls, $qa_complete_calls;
$qa_results = array();
$qa_owned_orders = array();
$qa_run = bin2hex( random_bytes( 6 ) );
$qa_secret = 'synthetic-contract-key-no-real-merchant';
$qa_requests = array();
$qa_mode = 'redirect';
$qa_http_calls = 0;
$qa_complete_calls = array();

function panapay_qa_assert( bool $value, string $name ): void {
	$GLOBALS['qa_results'][] = array( 'name' => $name, 'passed' => $value );
	if ( ! $value ) { throw new RuntimeException( $name ); }
}
function panapay_qa_order(): WC_Order {
	$product = new WC_Product_Simple();
	$product->set_name( 'Panapay synthetic item ' . $GLOBALS['qa_run'] );
	$product->set_status( 'publish' );
	$product->set_regular_price( '19.90' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 10 );
	$product->save();
	$order = wc_create_order();
	$order->set_payment_method( Gateway::ID );
	$order->set_currency( 'USD' );
	$address = array( 'first_name' => 'Synthetic', 'last_name' => 'Payment QA',
		'address_1' => '123 Synthetic Test Street', 'city' => 'Test City', 'state' => 'NY',
		'postcode' => '10001', 'country' => 'US', 'email' => 'qa-panapay@example.invalid', 'phone' => '+12025550123' );
	$order->set_address( $address, 'billing' );
	$order->set_address( $address, 'shipping' );
	$order->add_product( $product, 1 );
	$order->calculate_totals();
	$order->save();
	$GLOBALS['qa_owned_orders'][] = $order->get_id();
	return new WC_Order( $order->get_id() );
}
function panapay_qa_request( int $order_id ): array {
	foreach ( array_reverse( $GLOBALS['qa_requests'] ) as $request ) {
		if ( Session_Service::parse_reference( $request['order_id'] )['order_id'] === $order_id ) { return $request; }
	}
	throw new RuntimeException( 'Missing captured synthetic request.' );
}
function panapay_qa_notification( array $request, string $status, string $transaction ): array {
	$data = array( 'order_number' => $request['order_id'], 'status' => $status, 'transaction_id' => $transaction );
	$data['sign'] = Signature::generate( $data, $GLOBALS['qa_secret'] );
	return $data;
}
function panapay_qa_return( array $request, string $status, string $transaction ): array {
	parse_str( wp_parse_url( $request['return_url'], PHP_URL_QUERY ), $args );
	$args['status'] = $status; $args['transaction_id'] = $transaction;
	$args['sign'] = Signature::generate( array( 'status' => $status, 'transaction_id' => $transaction ), $GLOBALS['qa_secret'] );
	return $args;
}

$qa_mock = static function ( $pre, $args, $url ) {
	if ( $url !== 'https://example.com/index.php/admin/Payments/createPayment' ) { return $pre; }
	$GLOBALS['qa_http_calls']++;
	$body = json_decode( $args['body'], true, 32, JSON_THROW_ON_ERROR );
	$GLOBALS['qa_requests'][] = $body;
	$fields = array_intersect_key( $body, array_flip( array( 'mchid', 'currency', 'amount', 'site', 'order_id' ) ) );
	ksort( $fields );
	panapay_qa_assert( hash_equals( md5( http_build_query( $fields, '', '&', PHP_QUERY_RFC1738 ) . '&key=' . $GLOBALS['qa_secret'] ), $body['sign'] ), 'actual_request_signature_matches_original_contract' );
	panapay_qa_assert( ! str_contains( $args['body'], $GLOBALS['qa_secret'] ), 'secret_not_transmitted_in_request_body' );
	panapay_qa_assert( $body['platform_id'] === 6 && $body['mode'] === '2' && count( $body['products'] ) === 1, 'native_payload_platform_mode_products' );
	if ( 'timeout' === $GLOBALS['qa_mode'] ) { return new WP_Error( 'qa_timeout', 'Synthetic transport timeout.' ); }
	$result = 'failed' === $GLOBALS['qa_mode'] ? array( 'status' => 'FAILED', 'message' => '<script>untrusted provider message</script>' )
		: array( 'status' => 'SUCCEEDED', 'type' => $GLOBALS['qa_mode'], 'payment_url' => 'https://example.com/secure-payment?session=' . bin2hex( random_bytes( 12 ) ) );
	return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( $result ),
		'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $qa_mock, 5, 3 );
add_action( 'woocommerce_payment_complete', static function ( $id ) {
	$GLOBALS['qa_complete_calls'][ $id ] = ( $GLOBALS['qa_complete_calls'][ $id ] ?? 0 ) + 1;
} );
try {
	update_option( 'woocommerce_currency', 'USD' );
	update_option( 'woocommerce_pc_panapay_settings', array(
		'enabled' => 'yes', 'title' => 'Credit card', 'description' => 'Synthetic payment test.',
		'test_mode' => 'yes', 'gateway' => 'https://example.com', 'mchid' => 'qa123',
		'secret' => Crypto::encrypt( $qa_secret ), 'mode' => '2', 'tag' => '', 'payment_hosts' => 'example.com',
	), false );
	$gateway = new Gateway();
	panapay_qa_assert( Store::installed() && $gateway->is_available(), 'installed_gateway_available_in_local_qa' );
	$order = panapay_qa_order();
	$first = $gateway->process_payment( $order->get_id() );
	panapay_qa_assert( $first['result'] === 'success' && str_starts_with( $first['redirect'], 'https://example.com/secure-payment?' ), 'native_gateway_redirect_session' );
	$request = panapay_qa_request( $order->get_id() );
	panapay_qa_assert( ! ( new WC_Order( $order->get_id() ) )->is_paid(), 'create_succeeded_is_not_payment_succeeded' );
	$returned = Session_Service::returned_order( panapay_qa_return( $request, 'SUCCEEDED', 'qa-browser-return' ) );
	panapay_qa_assert( ! $returned->is_paid(), 'signed_browser_return_cannot_mark_paid' );
	$bad = panapay_qa_notification( $request, 'SUCCEEDED', 'qa-invalid' ); $bad['sign'] = str_repeat( '0', 32 );
	try { Session_Service::apply_notification( $bad ); $rejected = false; } catch ( Throwable $e ) { $rejected = true; }
	panapay_qa_assert( $rejected && ! ( new WC_Order( $order->get_id() ) )->is_paid(), 'invalid_signature_rejected_before_payment' );
	$notification = panapay_qa_notification( $request, 'SUCCEEDED', 'qa-paid-' . $qa_run );
	Session_Service::apply_notification( $notification );
	panapay_qa_assert( Persistence::is_paid( $order->get_id(), $notification['transaction_id'] ), 'verified_notification_paid_in_authoritative_native_store' );
	$paid = new WC_Order( $order->get_id() );
	$item = current( $paid->get_items() );
	$stock = wc_get_product( $item->get_product_id() )->get_stock_quantity();
	Session_Service::apply_notification( $notification );
	panapay_qa_assert( ( $qa_complete_calls[ $order->get_id() ] ?? 0 ) === 1
		&& wc_get_product( $item->get_product_id() )->get_stock_quantity() === $stock, 'duplicate_notification_no_duplicate_native_completion_or_stock' );
	Session_Service::apply_notification( panapay_qa_notification( $request, 'FAILED', $notification['transaction_id'] ) );
	panapay_qa_assert( Persistence::is_paid( $order->get_id(), $notification['transaction_id'] ), 'late_failure_does_not_demote_paid_order' );

	$qa_mode = 'iframe'; $iframe = panapay_qa_order();
	$iframe_result = $gateway->process_payment( $iframe->get_id() );
	panapay_qa_assert( $iframe_result['result'] === 'success' && $iframe_result['redirect'] === $iframe->get_checkout_payment_url( true ), 'iframe_response_uses_native_key_bound_receipt' );
	$iframe_request = panapay_qa_request( $iframe->get_id() );
	$cross_return = panapay_qa_return( $request, 'SUCCEEDED', 'qa-browser-return' );
	$cross_return['reference'] = $iframe_request['order_id'];
	try { Session_Service::returned_order( $cross_return ); $blocked = false; } catch ( Throwable $e ) { $blocked = true; }
	panapay_qa_assert( $blocked && ! ( new WC_Order( $iframe->get_id() ) )->is_paid(), 'cross_order_return_token_replay_rejected' );
	try { Session_Service::apply_notification( panapay_qa_notification( $iframe_request, 'SUCCEEDED', $notification['transaction_id'] ) ); $blocked = false; } catch ( Throwable $e ) { $blocked = true; }
	panapay_qa_assert( $blocked && ! ( new WC_Order( $iframe->get_id() ) )->is_paid(), 'same_transaction_cannot_pay_another_order' );

	$qa_mode = 'redirect'; $cancelled = panapay_qa_order(); $gateway->process_payment( $cancelled->get_id() );
	$cancel_request = panapay_qa_request( $cancelled->get_id() ); $cancelled = new WC_Order( $cancelled->get_id() ); $cancelled->update_status( 'cancelled' );
	Session_Service::apply_notification( panapay_qa_notification( $cancel_request, 'SUCCEEDED', 'qa-cancelled-' . $qa_run ) );
	panapay_qa_assert( ( new WC_Order( $cancelled->get_id() ) )->has_status( 'cancelled' ), 'late_success_does_not_reopen_cancelled_order' );

	$changed = panapay_qa_order(); $gateway->process_payment( $changed->get_id() ); $changed_request = panapay_qa_request( $changed->get_id() );
	$changed = new WC_Order( $changed->get_id() ); $changed->set_total( '23.90' ); $changed->save();
	Session_Service::apply_notification( panapay_qa_notification( $changed_request, 'SUCCEEDED', 'qa-changed-' . $qa_run ) );
	panapay_qa_assert( ! ( new WC_Order( $changed->get_id() ) )->is_paid(), 'changed_amount_requires_reconciliation' );

	$qa_mode = 'failed'; $failed = panapay_qa_order(); $failed_result = $gateway->process_payment( $failed->get_id() );
	panapay_qa_assert( $failed_result['result'] === 'failure' && ! ( new WC_Order( $failed->get_id() ) )->is_paid(), 'provider_create_failure_does_not_pay' );
	$qa_mode = 'timeout'; $unknown = panapay_qa_order(); $gateway->process_payment( $unknown->get_id() ); $calls = $qa_http_calls;
	$gateway->process_payment( $unknown->get_id() );
	panapay_qa_assert( $qa_http_calls === $calls && ! ( new WC_Order( $unknown->get_id() ) )->is_paid(), 'unknown_creation_never_blindly_retried' );

	$qa_mode = 'redirect'; $long = panapay_qa_order(); $gateway->process_payment( $long->get_id() ); $long_request = panapay_qa_request( $long->get_id() );
	$long_tx = 'qa-long-' . $qa_run . '-' . str_repeat( 'a', 120 );
	Session_Service::apply_notification( panapay_qa_notification( $long_request, 'SUCCEEDED', $long_tx ) );
	panapay_qa_assert( Persistence::is_paid( $long->get_id(), Session_Service::native_transaction_id( $long_tx ) )
		&& Store::admin_transactions( $long->get_id() )[0]['transaction'] === $long_tx, 'long_provider_transaction_preserved_without_native_truncation' );

	$secret = "qa'quoted\\secret"; $gateway->set_post_data( array() );
	$encrypted = $gateway->validate_secret_field( 'secret', wp_slash( $secret ) );
	panapay_qa_assert( Crypto::decrypt( $encrypted ) === $secret, 'native_settings_magic_quotes_do_not_change_secret' );
	$html = $gateway->generate_panapay_secret_html( 'secret', $gateway->form_fields['secret'] );
	panapay_qa_assert( ! str_contains( $html, $qa_secret ) && ! str_contains( $html, $gateway->settings['secret'] ), 'secret_input_never_returns_plaintext_or_ciphertext' );
	$qa_report = array( 'complete' => true, 'run' => $qa_run, 'nativeHPOS' => OrderUtil::custom_orders_table_usage_is_enabled(),
		'nativeDatastore' => WC_Data_Store::load( 'order' )->get_current_class_name(), 'results' => $qa_results,
		'ownedOrderIds' => $qa_owned_orders, 'providerTransport' => 'simulated only', 'actualProviderOrSettlementTested' => false );
} catch ( Throwable $error ) {
	$qa_report = array( 'complete' => false, 'run' => $qa_run, 'failure' => get_class( $error ), 'reason' => $error->getMessage(),
		'results' => $qa_results, 'ownedOrderIds' => $qa_owned_orders, 'providerTransport' => 'simulated only' );
} finally { remove_filter( 'pre_http_request', $qa_mock, 5 ); }
echo wp_json_encode( $qa_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( ! $qa_report['complete'] ) { throw new RuntimeException( 'Native Panapay contract test stopped.' ); }
