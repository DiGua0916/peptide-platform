<?php
/** Disposable native Woo session-policy acceptance. Every HTTP request is preempted. */
use VialCove\Panapay\{Bridge, Configuration, Crypto, Customer_Error, Gateway, Hub_Protocol, Session_Service, Store};

if ( ! defined( 'ABSPATH' ) || ! defined( 'PC_PANAPAY_TESTING' ) || true !== PC_PANAPAY_TESTING
	|| 'local' !== wp_get_environment_type() || DB_NAME !== 'panapay_qa_cpt_b04c7b5abbca'
	|| str_replace( '\\', '/', rtrim( ABSPATH, '/\\' ) ) !== 'D:/Codex/Tasks/2026-10-31/panapay-integration/work/site-cpt'
	|| ! function_exists( 'panapay_cpt_disable_automatic_as' ) || ! Store::installed() ) {
	throw new RuntimeException( 'Exact disposable payment CPT QA only.' );
}
global $wpdb;
if ( DB_NAME !== $wpdb->get_var( 'SELECT DATABASE()' ) ) { throw new RuntimeException( 'Actual database identity rejected.' ); }

$qa = array( 'run' => bin2hex( random_bytes( 6 ) ), 'checks' => 0, 'requests' => array(), 'create_calls' => 0, 'status_calls' => 0,
	'method_calls' => 0, 'other_http' => 0, 'complete_calls' => 0, 'fixture_processing_changes' => 0, 'mode' => 'valid', 'sessions' => array(), 'owned_orders' => array() );
$GLOBALS['pc_session_policy_qa'] =& $qa;
function pc_session_policy_check( string $label, bool $ok, array $context = array() ): void {
	++$GLOBALS['pc_session_policy_qa']['checks'];
	if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label . ( $context ? ' ' . wp_json_encode( $context ) : '' ) ); }
}
function pc_session_policy_book( WC_Order $order ): array {
	$book = $order->get_meta( '_pc_panapay_sessions', true );
	if ( ! is_array( $book ) || ! isset( $book['attempts'][ $book['active'] ] ) ) { throw new RuntimeException( 'Owned attempt missing.' ); }
	return $book;
}
function pc_session_policy_fence( int $id ): string {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Store::creations_table() . ' WHERE order_id=%d', $id ), ARRAY_A );
	if ( ! is_array( $row ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Owned creation row missing.' ); }
	return hash( 'sha256', serialize( $row ) );
}
function pc_session_policy_unpaid( WC_Order $order ): bool {
	return $order->has_status( 'pending' ) && $order->needs_payment() && '' === $order->get_transaction_id()
		&& ! $order->get_date_paid() && array() === $order->get_refunds() && ! $order->get_meta( '_order_stock_reduced', true );
}
function pc_session_policy_new_order( WC_Product $product ): WC_Order {
	$qa =& $GLOBALS['pc_session_policy_qa'];
	$address = array( 'first_name' => 'Synthetic', 'last_name' => 'Policy QA', 'address_1' => '123 Synthetic Test Street', 'city' => 'Test City', 'state' => 'NY', 'postcode' => '10001', 'country' => 'US', 'email' => 'native-policy@example.invalid', 'phone' => '+12025550123' );
	$order = new WC_Order(); $order->set_status( 'pending' ); $order->set_currency( 'USD' ); $order->set_payment_method( Gateway::ID );
	$order->set_address( $address, 'billing' ); $order->set_address( $address, 'shipping' ); $order->add_product( $product, 1 ); $order->calculate_totals();
	$order->update_meta_data( '_pc_synthetic_policy_qa', $qa['run'] ); $order->save();
	$qa['owned_orders'][] = $order->get_id(); return $order;
}
function pc_session_policy_expect_initial_rejection( WC_Order $order ): array {
	$GLOBALS['pc_session_policy_qa']['mode'] = 'legacy';
	$thrown = false;
	try { Session_Service::start( $order->get_id(), Gateway::ID ); }
	catch ( Customer_Error $error ) { $thrown = str_contains( $error->getMessage(), 'secure payment page' ); }
	pc_session_policy_check( 'legacy-created cashier rejected by stale local cache', $thrown );
	$book = pc_session_policy_book( new WC_Order( $order->get_id() ) ); $attempt = $book['attempts'][ $book['active'] ];
	pc_session_policy_check( 'legacy attempt is created and has saved cashier but no authenticated-policy marker', 'created' === $attempt['state'] && isset( $attempt['url_box'] ) && ! isset( $attempt['checkout_policy_version'] ) );
	return $book;
}

$saved_connector = get_option( 'peptide_core_hq', null ); $saved_currency = get_option( 'woocommerce_currency', null );
$link_key = hash( 'sha256', 'synthetic-session-policy|' . $qa['run'] ); $uuid = wp_generate_uuid4();
$methods_cache = '';
$mock = static function ( $pre, $args, $url ) use ( &$qa, $link_key ) {
	$query = array(); parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query ); $route = $query['rest_route'] ?? null;
	$fixture = static fn( array $data ) => array( 'headers' => array( 'content-type' => 'application/json', 'x-policy-fixture' => $qa['run'] ),
		'body' => wp_json_encode( $data ), 'response' => array( 'code' => 200, 'message' => 'Synthetic' ), 'cookies' => array() );
	if ( ! str_starts_with( $url, Bridge::hq_url() ) || ! in_array( $route, array( Hub_Protocol::METHODS, Hub_Protocol::CREATE, Hub_Protocol::STATUS ), true ) ) {
		++$qa['other_http']; return new WP_Error( 'policy_native_http_blocked', 'Every native QA HTTP request must be mocked.' );
	}
	$input = json_decode( $args['body'], true, 32, JSON_THROW_ON_ERROR ); $qa['requests'][] = $route;
	if ( Hub_Protocol::METHODS === $route ) {
		++$qa['method_calls'];
		$data = array( 'version' => 1, 'methods' => array( array( 'account_id' => 'default', 'enabled' => true, 'title' => 'Synthetic policy method',
			'description' => 'Disposable QA only.', 'test_mode' => true, 'payment_hosts' => array( 'old.example.invalid' ), 'payment_icons' => array(), 'iframe_height' => 530 ) ) );
	} else {
		$reference = Hub_Protocol::CREATE === $route ? $input['order_id'] : $input['reference'];
		if ( Hub_Protocol::CREATE === $route ) {
			++$qa['create_calls'];
			$qa['sessions'][ $reference ] = array( 'state' => 'created', 'account_id' => 'default', 'type' => 'redirect',
				'url' => 'https://example.com/synthetic-session-policy/' . $qa['run'] . '/' . $qa['create_calls'],
				'checkout_policy' => array( 'version' => 1, 'reference' => $reference, 'payment_hosts' => array( 'example.com' ) ) );
		} else { ++$qa['status_calls']; }
		if ( ! isset( $qa['sessions'][ $reference ] ) ) { throw new RuntimeException( 'Unexpected reference in synthetic HQ.' ); }
		$data = $qa['sessions'][ $reference ];
		if ( 'legacy' === $qa['mode'] || 'missing-policy' === $qa['mode'] ) { unset( $data['checkout_policy'] ); }
		if ( 'different-url' === $qa['mode'] ) { $data['url'] .= '/changed'; }
		if ( 'different-type' === $qa['mode'] ) { $data['type'] = 'iframe'; }
		if ( 'foreign-reference' === $qa['mode'] ) { $data['checkout_policy']['reference'] = 'PP12345678-999-' . str_repeat( 'c', 32 ); }
		if ( 'foreign-account' === $qa['mode'] ) { $data['account_id'] = str_repeat( 'b', 32 ); }
		if ( 'unlisted-url' === $qa['mode'] ) { $data['checkout_policy']['payment_hosts'] = array( 'other.example.invalid' ); }
		if ( Hub_Protocol::STATUS === $route && in_array( $qa['mode'], array( 'paid-during-status', 'changed-during-status', 'gateway-during-status' ), true ) ) {
			$id = Session_Service::parse_reference( $reference )['order_id']; $concurrent = new WC_Order( $id );
			if ( 'paid-during-status' === $qa['mode'] ) { $concurrent->set_status( 'processing' ); ++$qa['fixture_processing_changes']; }
			if ( 'changed-during-status' === $qa['mode'] ) { $concurrent->set_billing_city( 'Synthetic Concurrent Change' ); }
			if ( 'gateway-during-status' === $qa['mode'] ) { $concurrent->set_payment_method( 'cod' ); }
			// The test, not the gateway, synthesizes a concurrent processing status.
			// Suppress Woo's normal stock action for only this fixture save so the
			// final inventory assertion measures the payment implementation itself.
			$fixture_stock_guard = static fn( $allowed, $order ) => $order instanceof WC_Order && $order->get_id() === $id ? false : $allowed;
			if ( 'paid-during-status' === $qa['mode'] ) { add_filter( 'woocommerce_can_reduce_order_stock', $fixture_stock_guard, PHP_INT_MAX, 2 ); }
			try { $concurrent->save(); }
			finally { if ( 'paid-during-status' === $qa['mode'] ) { remove_filter( 'woocommerce_can_reduce_order_stock', $fixture_stock_guard, PHP_INT_MAX ); } }
		}
	}
	$envelope = Hub_Protocol::response( $data, (string) $args['headers']['X-PC-Pay-Nonce'], $link_key )->get_data();
	if ( 'bad-signature' === $qa['mode'] ) { $envelope['sign'] = str_repeat( '0', 64 ); }
	return $fixture( $envelope );
};
$final_guard = static fn( $pre ) => is_array( $pre ) && ( $pre['headers']['x-policy-fixture'] ?? null ) === $GLOBALS['pc_session_policy_qa']['run']
	? $pre : new WP_Error( 'policy_native_no_external_http', 'Disposable QA rejects unmocked HTTP.' );
$completion_observer = static function () use ( &$qa ) { ++$qa['complete_calls']; };
add_filter( 'pre_http_request', $mock, -1000, 3 ); add_filter( 'pre_http_request', $final_guard, PHP_INT_MAX );
add_action( 'woocommerce_payment_complete', $completion_observer );
try {
	update_option( 'peptide_core_hq', array( 'key' => $link_key, 'uuid' => $uuid ), false ); update_option( 'woocommerce_currency', 'USD' );
	$identity = \PeptideCore\Hq_Connector::settings();
	$methods_cache = 'pc_pay_methods_v1_' . substr( hash( 'sha256', Hub_Protocol::base_url( PEPTIDE_HQ_URL ) . '|' . $identity['uuid'] . '|' . $identity['key'] ), 0, 32 );
	delete_transient( $methods_cache );
	pc_session_policy_check( 'METHODS intentionally reports stale whitelist', array( 'old.example.invalid' ) === Bridge::configuration()['payment_hosts'] );
	$product = new WC_Product_Simple(); $product->set_name( 'Synthetic session-policy item ' . $qa['run'] ); $product->set_status( 'publish' );
	$product->set_regular_price( '19.90' ); $product->set_manage_stock( true ); $product->set_stock_quantity( 10 ); $product->save();
	$first = pc_session_policy_new_order( $product ); $qa['mode'] = 'valid'; $creates = $qa['create_calls']; $statuses = $qa['status_calls'];
	$result = Session_Service::start( $first->get_id(), Gateway::ID ); $book = pc_session_policy_book( new WC_Order( $first->get_id() ) ); $attempt = $book['attempts'][ $book['active'] ];
	pc_session_policy_check( 'new creation succeeds using HQ session policy despite stale METHODS', 'redirect' === $result['type'] && str_starts_with( $result['url'], 'https://example.com/' ) );
	pc_session_policy_check( 'new native book freezes HQ-confirmed hosts and marker', array( 'example.com' ) === $attempt['payment_hosts'] && 1 === $attempt['checkout_policy_version'] );
	pc_session_policy_check( 'new creation issues exactly one CREATE and no STATUS', $creates + 1 === $qa['create_calls'] && $statuses === $qa['status_calls'] );
	pc_session_policy_check( 'new creation leaves order unpaid and stock untouched', pc_session_policy_unpaid( new WC_Order( $first->get_id() ) ) && 10 === (int) wc_get_product( $product->get_id() )->get_stock_quantity() );
	$before_book = serialize( $book ); $before_fence = pc_session_policy_fence( $first->get_id() );
	Session_Service::start( $first->get_id(), Gateway::ID );
	pc_session_policy_check( 'repeat confirmed attempt does not refetch policy or recreate', $creates + 1 === $qa['create_calls'] && $statuses === $qa['status_calls'] && $before_book === serialize( pc_session_policy_book( new WC_Order( $first->get_id() ) ) ) && $before_fence === pc_session_policy_fence( $first->get_id() ) );
	$legacy_order = pc_session_policy_new_order( $product ); $legacy_book = pc_session_policy_expect_initial_rejection( $legacy_order );
	$legacy_attempt = $legacy_book['attempts'][ $legacy_book['active'] ]; $legacy_fence = pc_session_policy_fence( $legacy_order->get_id() );
	$qa['mode'] = 'valid'; $creates = $qa['create_calls']; $statuses = $qa['status_calls'];
	$repaired_result = Session_Service::start( $legacy_order->get_id(), Gateway::ID ); $repaired_book = pc_session_policy_book( new WC_Order( $legacy_order->get_id() ) );
	$expected_book = $legacy_book; $expected_book['attempts'][ $legacy_book['active'] ]['payment_hosts'] = array( 'example.com' ); $expected_book['attempts'][ $legacy_book['active'] ]['checkout_policy_version'] = 1;
	pc_session_policy_check( 'legacy created attempt repaired via exact signed STATUS', $expected_book === $repaired_book && 'redirect' === $repaired_result['type'] );
	pc_session_policy_check( 'repair preserves exact encrypted cashier and attempt identity', $legacy_attempt['url_box'] === $repaired_book['attempts'][ $legacy_book['active'] ]['url_box'] && $legacy_book['active'] === $repaired_book['active'] );
	pc_session_policy_check( 'repair makes one STATUS and zero new CREATE', $creates === $qa['create_calls'] && $statuses + 1 === $qa['status_calls'] );
	pc_session_policy_check( 'repair preserves creation owner row, financial state and stock', $legacy_fence === pc_session_policy_fence( $legacy_order->get_id() ) && pc_session_policy_unpaid( new WC_Order( $legacy_order->get_id() ) ) && 10 === (int) wc_get_product( $product->get_id() )->get_stock_quantity() );
	$receipt_order = pc_session_policy_new_order( $product ); $receipt_book = pc_session_policy_expect_initial_rejection( $receipt_order );
	$receipt_fence = pc_session_policy_fence( $receipt_order->get_id() ); $qa['mode'] = 'valid'; $creates = $qa['create_calls']; $statuses = $qa['status_calls'];
	$receipt = Session_Service::active_session( new WC_Order( $receipt_order->get_id() ) ); $receipt_after = pc_session_policy_book( new WC_Order( $receipt_order->get_id() ) );
	$receipt_expected = $receipt_book; $receipt_expected['attempts'][ $receipt_book['active'] ]['payment_hosts'] = array( 'example.com' ); $receipt_expected['attempts'][ $receipt_book['active'] ]['checkout_policy_version'] = 1;
	pc_session_policy_check( 'receipt active_session entry repairs exactly the old created policy', $receipt_expected === $receipt_after && 'redirect' === $receipt['type'] && $receipt_book['attempts'][ $receipt_book['active'] ]['url_box'] === $receipt_after['attempts'][ $receipt_after['active'] ]['url_box'] );
	pc_session_policy_check( 'receipt entry makes one STATUS, no CREATE, and keeps owner and unpaid order', $creates === $qa['create_calls'] && $statuses + 1 === $qa['status_calls'] && $receipt_fence === pc_session_policy_fence( $receipt_order->get_id() ) && pc_session_policy_unpaid( new WC_Order( $receipt_order->get_id() ) ) );
	$inflight_order = pc_session_policy_new_order( $product ); $inflight_book = pc_session_policy_expect_initial_rejection( $inflight_order );
	// This is an explicitly owned synthetic row; model a worker still holding its
	// creation slot after a created book was persisted. Recovery must not finish it.
	$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Store::creations_table() . " SET state='creating' WHERE order_id=%d AND state='created'", $inflight_order->get_id() ) );
	if ( 1 !== $changed || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Owned synthetic in-flight fixture unavailable.' ); }
	$inflight_fence = pc_session_policy_fence( $inflight_order->get_id() ); $qa['mode'] = 'valid'; $creates = $qa['create_calls']; $statuses = $qa['status_calls']; $inflight_rejected = false;
	try { Session_Service::start( $inflight_order->get_id(), Gateway::ID ); } catch ( Customer_Error $error ) { $inflight_rejected = str_contains( $error->getMessage(), 'Payment setup has not been confirmed' ); }
	pc_session_policy_check( 'created-book policy recovery cannot finish an in-flight creation owner', $inflight_rejected && $inflight_fence === pc_session_policy_fence( $inflight_order->get_id() ) );
	pc_session_policy_check( 'in-flight policy recovery only reads STATUS and preserves financial state', $creates === $qa['create_calls'] && $statuses + 1 === $qa['status_calls'] && pc_session_policy_unpaid( new WC_Order( $inflight_order->get_id() ) ) );
	$statuses = $qa['status_calls']; $inflight_receipt_rejected = false;
	try { Session_Service::active_session( new WC_Order( $inflight_order->get_id() ) ); } catch ( Customer_Error $error ) { $inflight_receipt_rejected = str_contains( $error->getMessage(), 'Payment setup has not been confirmed' ); }
	pc_session_policy_check( 'receipt refuses in-flight owner before any STATUS or rewrite', $inflight_receipt_rejected && $statuses === $qa['status_calls'] && $inflight_fence === pc_session_policy_fence( $inflight_order->get_id() ) );
	foreach ( array( 'missing-policy', 'different-url', 'different-type', 'foreign-reference', 'foreign-account', 'unlisted-url', 'bad-signature', 'paid-during-status', 'changed-during-status', 'gateway-during-status' ) as $mode ) {
		$order = pc_session_policy_new_order( $product ); $old_book = pc_session_policy_expect_initial_rejection( $order ); $old_fence = pc_session_policy_fence( $order->get_id() );
		$qa['mode'] = $mode; $creates = $qa['create_calls']; $statuses = $qa['status_calls']; $rejected = false;
		try { Session_Service::start( $order->get_id(), Gateway::ID ); } catch ( Throwable $error ) { $rejected = true; }
		pc_session_policy_check( 'unsafe repair blocked: ' . $mode, $rejected );
		pc_session_policy_check( 'blocked repair preserves book and creation owner: ' . $mode, $old_book === pc_session_policy_book( new WC_Order( $order->get_id() ) ) && $old_fence === pc_session_policy_fence( $order->get_id() ) );
		pc_session_policy_check( 'blocked repair makes only STATUS: ' . $mode, $creates === $qa['create_calls'] && $statuses + 1 === $qa['status_calls'] );
	}
	$components = array( 'unmocked_http' => $qa['other_http'], 'payment_complete_calls' => $qa['complete_calls'], 'actual_stock' => (int) wc_get_product( $product->get_id() )->get_stock_quantity(), 'expected_stock' => 10, 'fixture_processing_changes' => $qa['fixture_processing_changes'] );
	pc_session_policy_check( 'all HTTP remained synthetic', 0 === $components['unmocked_http'], $components );
	pc_session_policy_check( 'payment implementation did not emit payment_complete', 0 === $components['payment_complete_calls'], $components );
	pc_session_policy_check( 'payment implementation did not reduce stock', $components['expected_stock'] === $components['actual_stock'], $components );
	echo wp_json_encode( array( 'status' => 'passed', 'checks' => $qa['checks'], 'method_calls' => $qa['method_calls'], 'create_calls' => $qa['create_calls'], 'status_calls' => $qa['status_calls'],
		'other_http' => $qa['other_http'], 'payment_complete_calls' => $qa['complete_calls'], 'components' => $components, 'owned_orders' => $qa['owned_orders'], 'owned_product' => $product->get_id(), 'provider_transport' => 'synthetic only', 'real_payments' => 0 ) ) . "\n";
} finally {
	remove_filter( 'pre_http_request', $mock, -1000 ); remove_filter( 'pre_http_request', $final_guard, PHP_INT_MAX ); remove_action( 'woocommerce_payment_complete', $completion_observer );
	if ( '' !== $methods_cache ) { delete_transient( $methods_cache ); }
	if ( null === $saved_connector ) { delete_option( 'peptide_core_hq' ); } else { update_option( 'peptide_core_hq', $saved_connector, false ); }
	if ( null === $saved_currency ) { delete_option( 'woocommerce_currency' ); } else { update_option( 'woocommerce_currency', $saved_currency ); }
	unset( $GLOBALS['pc_session_policy_qa'] );
}
