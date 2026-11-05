<?php
/** Native disposable CPT return authentication/status checks. All HTTP is signed synthetic data. */
use VialCove\Panapay\{Bridge, Gateway, Hub_Protocol, Order_Payload, Persistence, Return_Context, Session_Service, Signature, Store};

if ( ! defined( 'ABSPATH' ) || ! defined( 'PC_PANAPAY_TESTING' ) || true !== PC_PANAPAY_TESTING
	|| 'local' !== wp_get_environment_type() || DB_NAME !== 'panapay_qa_cpt_b04c7b5abbca'
	|| str_replace( '\\', '/', rtrim( ABSPATH, '/\\' ) ) !== 'D:/Codex/Tasks/2026-10-31/panapay-integration/work/site-cpt'
	|| ! function_exists( 'panapay_cpt_disable_automatic_as' ) || ! Store::installed() ) {
	throw new RuntimeException( 'Exact disposable native CPT payment QA only.' );
}
global $wpdb;
if ( DB_NAME !== $wpdb->get_var( 'SELECT DATABASE()' ) ) { throw new RuntimeException( 'Actual native database identity rejected.' ); }

$qa = array( 'run' => bin2hex( random_bytes( 6 ) ), 'checks' => 0, 'methods' => 0, 'creates' => 0, 'other_http' => 0,
	'completions' => 0, 'blocked_mail' => 0, 'requests' => array(), 'orders' => array() );
$GLOBALS['pc_native_return_qa'] =& $qa;
function pc_native_return_check( string $name, bool $ok ): void {
	++$GLOBALS['pc_native_return_qa']['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $name ); }
}
function pc_native_return_rejects( callable $fn ): bool { try { $fn(); } catch ( Throwable $error ) { return true; } return false; }
function pc_native_return_order( WC_Product $product ): WC_Order {
	$qa =& $GLOBALS['pc_native_return_qa'];
	$address = array( 'first_name' => 'Synthetic', 'last_name' => 'Return QA', 'address_1' => '123 Synthetic Test Street',
		'city' => 'Test City', 'state' => 'NY', 'postcode' => '10001', 'country' => 'US', 'email' => 'native-return@example.invalid', 'phone' => '+12025550123' );
	$o = new WC_Order(); $o->set_customer_id( 0 ); $o->set_created_via( 'synthetic-return-qa' ); $o->set_status( 'pending' );
	$o->set_currency( 'USD' ); $o->set_payment_method( Gateway::ID ); $o->set_address( $address, 'billing' ); $o->set_address( $address, 'shipping' );
	$o->add_product( $product, 1 ); $o->calculate_totals(); $o->update_meta_data( '_pc_synthetic_return_status_qa', $qa['run'] ); $o->save();
	$qa['orders'][] = $o->get_id(); return $o;
}
function pc_native_return_book( int $id ): array {
	$o = new WC_Order( $id ); $b = $o->get_meta( '_pc_panapay_sessions', true );
	if ( ! is_array( $b ) || ! isset( $b['attempts'][ $b['active'] ] ) ) { throw new RuntimeException( 'Synthetic native book unavailable.' ); }
	Persistence::assert_book( $o, $b ); return $b;
}
function pc_native_return_sql_book( int $id ): string {
	global $wpdb;
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_pc_panapay_sessions' ORDER BY meta_id LIMIT 2", $id ) );
	if ( count( $rows ) !== 1 || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Synthetic CPT SQL book unavailable.' ); }
	return $rows[0];
}
function pc_native_return_proof( int $id, string $event_key ): array {
	$book = pc_native_return_book( $id ); $reference = $book['attempts'][ $book['active'] ]['reference'];
	$payload = $GLOBALS['pc_native_return_qa']['requests'][ $reference ] ?? null;
	if ( ! is_array( $payload ) ) { throw new RuntimeException( 'Synthetic request identity unavailable.' ); }
	$url = Return_Context::check_url( $payload['return_url'], $event_key ); $fields = array(); parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $fields ); unset( $fields['wc-api'] );
	if ( ! Return_Context::verify_check( $fields, $event_key ) ) { throw new RuntimeException( 'Synthetic internal check proof invalid.' ); }
	return $fields;
}
function pc_native_return_notification( int $id, string $status, string $event_key, string $tx ): array {
	$book = pc_native_return_book( $id );
	$data = array( 'order_number' => $book['attempts'][ $book['active'] ]['reference'], 'status' => $status, 'transaction_id' => $tx );
	$data['sign'] = Signature::generate( $data, $event_key ); $data['account_id'] = 'default'; return $data;
}

$saved_connector = get_option( 'peptide_core_hq', null ); $saved_currency = get_option( 'woocommerce_currency', null );
$link_key = hash( 'sha256', 'native-return-context|' . $qa['run'] ); $event_key = Hub_Protocol::event_key( $link_key ); $uuid = wp_generate_uuid4();
$methods_cache = ''; $product = null; $restore_orders = array();
$mock = static function ( $pre, $args, $url ) use ( &$qa, $link_key ) {
	$query = array(); parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query ); $route = $query['rest_route'] ?? null;
	if ( ! str_starts_with( $url, Bridge::hq_url() ) || ! in_array( $route, array( Hub_Protocol::METHODS, Hub_Protocol::CREATE ), true ) ) {
		++$qa['other_http']; return new WP_Error( 'native_return_http_blocked', 'Every native return QA request must be mocked.' );
	}
	if ( Hub_Protocol::METHODS === $route ) {
		++$qa['methods']; $data = array( 'version' => 1, 'methods' => array( array( 'account_id' => 'default', 'enabled' => true,
			'title' => 'Synthetic return method', 'description' => 'Isolated fixture only.', 'test_mode' => true,
			'payment_hosts' => array( 'example.com' ), 'payment_icons' => array(), 'iframe_height' => 530 ) ) );
	} else {
		$input = json_decode( $args['body'], true, 32, JSON_THROW_ON_ERROR ); ++$qa['creates']; $qa['requests'][ $input['order_id'] ] = $input;
		$data = array( 'state' => 'created', 'account_id' => 'default', 'type' => 'redirect', 'url' => 'https://example.com/native-return/' . $qa['run'] . '/' . $qa['creates'],
			'checkout_policy' => array( 'version' => 1, 'reference' => $input['order_id'], 'payment_hosts' => array( 'example.com' ) ) );
	}
	$body = Hub_Protocol::response( $data, (string) $args['headers']['X-PC-Pay-Nonce'], $link_key )->get_data();
	return array( 'headers' => array( 'content-type' => 'application/json', 'x-native-return-fixture' => $qa['run'] ), 'body' => wp_json_encode( $body ),
		'response' => array( 'code' => 200, 'message' => 'Synthetic' ), 'cookies' => array() );
};
$guard = static fn( $pre ) => is_array( $pre ) && ( $pre['headers']['x-native-return-fixture'] ?? null ) === $GLOBALS['pc_native_return_qa']['run']
	? $pre : new WP_Error( 'native_return_no_external_http', 'Unmocked HTTP forbidden in isolated return QA.' );
$completion = static function () use ( &$qa ) { ++$qa['completions']; };
$mail = static function ( $pre ) use ( &$qa ) { ++$qa['blocked_mail']; return false; };
add_filter( 'pre_http_request', $mock, -1000, 3 ); add_filter( 'pre_http_request', $guard, PHP_INT_MAX );
add_action( 'woocommerce_payment_complete', $completion ); add_filter( 'pre_wp_mail', $mail, PHP_INT_MAX );
try {
	update_option( 'peptide_core_hq', array( 'key' => $link_key, 'uuid' => $uuid ), false ); update_option( 'woocommerce_currency', 'USD' );
	$identity = \PeptideCore\Hq_Connector::settings();
	$methods_cache = 'pc_pay_methods_v1_' . substr( hash( 'sha256', Hub_Protocol::base_url( PEPTIDE_HQ_URL ) . '|' . $identity['uuid'] . '|' . $identity['key'] ), 0, 32 );
	delete_transient( $methods_cache );
	$product = new WC_Product_Simple(); $product->set_name( 'Synthetic return-status item ' . $qa['run'] ); $product->set_status( 'publish' );
	$product->set_regular_price( '19.90' ); $product->set_manage_stock( true ); $product->set_stock_quantity( 10 ); $product->save();
	$pending = pc_native_return_order( $product ); $session = Session_Service::start( $pending->get_id(), Gateway::ID ); $proof = pc_native_return_proof( $pending->get_id(), $event_key );
	$before = pc_native_return_sql_book( $pending->get_id() ); $fresh = Session_Service::returned_order( $proof );
	pc_native_return_check( 'native exact proof resolves a distinct fresh order object', $fresh->get_id() === $pending->get_id() && $fresh !== $pending );
	pc_native_return_check( 'native pending session shows unknown', 'unknown' === Session_Service::return_status( $fresh, $proof['reference'] ) );
	pc_native_return_check( 'native return status keeps SQL book byte-identical', $before === pc_native_return_sql_book( $pending->get_id() ) );
	pc_native_return_check( 'SQL book matches actual fresh native book', maybe_unserialize( $before ) === $fresh->get_meta( '_pc_panapay_sessions', true ) );
	$bad = $proof; $bad['sign'] = str_repeat( '0', 32 ); pc_native_return_check( 'native bad check proof rejected', pc_native_return_rejects( static fn() => Session_Service::returned_order( $bad ) ) );
	$bad = $proof; $bad['token'] = str_repeat( 'e', 48 ); pc_native_return_check( 'native wrong browser token rejected', pc_native_return_rejects( static fn() => Session_Service::returned_order( $bad ) ) );
	$bad = $proof; $bad['status'] = 'SUCCEEDED'; pc_native_return_check( 'native mixed result and check proof rejected', pc_native_return_rejects( static fn() => Session_Service::returned_order( $bad ) ) );
	$browser = array( 'reference' => $proof['reference'], 'token' => $proof['token'], 'status' => 'SUCCEEDED', 'transaction_id' => 'synthetic-browser-hint-' . $qa['run'] );
	$browser['sign'] = Signature::generate( array_intersect_key( $browser, array_flip( array( 'status', 'transaction_id' ) ) ), $event_key );
	pc_native_return_check( 'native valid signed browser success remains unknown without notification', 'unknown' === Session_Service::return_status( Session_Service::returned_order( $browser ), $proof['reference'] ) );
	pc_native_return_check( 'native browser success triggers no completion or inventory write', 0 === $qa['completions'] && 10 === (int) wc_get_product( $product->get_id() )->get_stock_quantity() );
	// This intentionally cached factory instance predates an address save. A fresh
	// constructor in returned_order must use the committed native values instead.
	$cached = wc_get_order( $pending->get_id() ); $changed = new WC_Order( $pending->get_id() ); $original_city = $changed->get_billing_city();
	$restore_orders[ $changed->get_id() ] = array( 'billing_city' => $original_city, 'gateway' => $changed->get_payment_method() );
	$changed->set_billing_city( 'Synthetic Corrected City' ); $changed->save();
	$fresh = Session_Service::returned_order( $proof );
	pc_native_return_check( 'contact correction retains payment financial fingerprint read access', 'Synthetic Corrected City' === $fresh->get_billing_city() && 'unknown' === Session_Service::return_status( $fresh, $proof['reference'] ) );
	$changed->set_billing_city( $original_city ); $changed->save(); unset( $restore_orders[ $changed->get_id() ] );
	$failed = pc_native_return_order( $product ); Session_Service::start( $failed->get_id(), Gateway::ID ); $failed_proof = pc_native_return_proof( $failed->get_id(), $event_key );
	Session_Service::apply_notification( pc_native_return_notification( $failed->get_id(), 'FAILED', $event_key, '' ), true );
	$failed_fresh = Session_Service::returned_order( $failed_proof );
	pc_native_return_check( 'native verified failure produces failed display', 'failed' === Session_Service::return_status( $failed_fresh, $failed_proof['reference'] ) && Persistence::has_status( $failed->get_id(), array( 'failed' ) ) );
	pc_native_return_check( 'native failure produces no payment completion or stock reduction', 0 === $qa['completions'] && 10 === (int) wc_get_product( $product->get_id() )->get_stock_quantity() );
	$cross = $proof; $cross['reference'] = $failed_proof['reference'];
	pc_native_return_check( 'native check proof cannot be replayed to another order', pc_native_return_rejects( static fn() => Session_Service::returned_order( $cross ) ) );
	pc_native_return_check( 'native status method rejects another order reference', pc_native_return_rejects( static fn() => Session_Service::return_status( new WC_Order( $pending->get_id() ), $failed_proof['reference'] ) ) );
	$paid = pc_native_return_order( $product ); Session_Service::start( $paid->get_id(), Gateway::ID ); $paid_proof = pc_native_return_proof( $paid->get_id(), $event_key );
	$notice = pc_native_return_notification( $paid->get_id(), 'SUCCEEDED', $event_key, 'synthetic-return-paid-' . $qa['run'] );
	Session_Service::apply_notification( $notice, true ); $paid_fresh = Session_Service::returned_order( $paid_proof );
	$paid_sql = pc_native_return_sql_book( $paid->get_id() ); $ledger = Store::admin_transactions( $paid->get_id() );
	pc_native_return_check( 'native verified notification commits paid status', Persistence::is_paid( $paid->get_id(), Session_Service::native_transaction_id( $notice['transaction_id'] ) ) );
	pc_native_return_check( 'native applied ledger binds this paid attempt', 1 === count( $ledger ) && $ledger[0]['state'] === 'applied' && $ledger[0]['transaction'] === $notice['transaction_id'] );
	pc_native_return_check( 'native paid display requires the actual verified attempt and ledger', 'paid' === Session_Service::return_status( $paid_fresh, $paid_proof['reference'] ) );
	pc_native_return_check( 'native return reads do not repeat stock or completion', 1 === $qa['completions'] && 9 === (int) wc_get_product( $product->get_id() )->get_stock_quantity() && $paid_sql === pc_native_return_sql_book( $paid->get_id() ) );
	Session_Service::apply_notification( $notice, true );
	pc_native_return_check( 'native duplicate notification remains idempotent', 1 === $qa['completions'] && 9 === (int) wc_get_product( $product->get_id() )->get_stock_quantity() );
	$review = new WC_Order( $paid->get_id() ); $restore_orders[ $review->get_id() ] = array( 'billing_city' => $review->get_billing_city(), 'gateway' => $review->get_payment_method() );
	$review->set_payment_method( 'cod' ); $review->save();
	pc_native_return_check( 'native changed persisted paid gateway needs review', 'review' === Session_Service::return_status( new WC_Order( $paid->get_id() ), $paid_proof['reference'] ) );
	pc_native_return_check( 'native changed gateway also refuses return read authentication', pc_native_return_rejects( static fn() => Session_Service::returned_order( $paid_proof ) ) );
	$review->set_payment_method( Gateway::ID ); $review->save(); unset( $restore_orders[ $review->get_id() ] );
	pc_native_return_check( 'native restored owned gateway recovers exact paid display', 'paid' === Session_Service::return_status( Session_Service::returned_order( $paid_proof ), $paid_proof['reference'] ) );
	pc_native_return_check( 'all native network remained signed synthetic HTTP', 0 === $qa['other_http'] && 3 === $qa['creates'] );
	pc_native_return_check( 'all three native SQL books remain independently bound', pc_native_return_sql_book( $pending->get_id() ) !== pc_native_return_sql_book( $failed->get_id() ) && pc_native_return_sql_book( $failed->get_id() ) !== pc_native_return_sql_book( $paid->get_id() ) );
	echo wp_json_encode( array( 'status' => 'passed', 'checks' => $qa['checks'], 'method_calls' => $qa['methods'], 'create_calls' => $qa['creates'],
		'unmocked_http' => $qa['other_http'], 'native_payment_complete_calls' => $qa['completions'], 'real_payments' => 0,
		'stock_after_one_verified_fixture_payment' => (int) wc_get_product( $product->get_id() )->get_stock_quantity(),
		'owned_orders' => $qa['orders'], 'owned_product' => $product->get_id(), 'native_db' => 'CPT', 'provider_transport' => 'synthetic only' ) ) . "\n";
} finally {
	// Restore only negative-case mutations to orders this exact fixture owns.
	try {
		foreach ( $restore_orders as $id => $values ) {
			$o = new WC_Order( $id );
			if ( $o->get_meta( '_pc_synthetic_return_status_qa', true ) !== $qa['run'] ) { throw new RuntimeException( 'Owned fixture restore identity changed.' ); }
			$o->set_billing_city( $values['billing_city'] ); $o->set_payment_method( $values['gateway'] ); $o->save();
		}
	} finally {
		try {
			if ( '' !== $methods_cache ) { delete_transient( $methods_cache ); }
			if ( null === $saved_connector ) { delete_option( 'peptide_core_hq' ); } else { update_option( 'peptide_core_hq', $saved_connector, false ); }
			if ( null === $saved_currency ) { delete_option( 'woocommerce_currency' ); } else { update_option( 'woocommerce_currency', $saved_currency ); }
		} finally {
			remove_filter( 'pre_http_request', $mock, -1000 ); remove_filter( 'pre_http_request', $guard, PHP_INT_MAX );
			remove_action( 'woocommerce_payment_complete', $completion ); remove_filter( 'pre_wp_mail', $mail, PHP_INT_MAX );
			unset( $GLOBALS['pc_native_return_qa'] );
		}
	}
}
