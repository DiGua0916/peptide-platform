<?php
/**
 * Disposable native Woo acceptance. All HTTP is preempted by synthetic fixtures.
 * wp eval-file .../tests/native-checkout-method-handoff.php
 */
use VialCove\Panapay\{Blocks, Bridge, Checkout_Handoff, Checkout_Lifecycle, Configuration, Gateway, Hub_Protocol, Pending_Orders, Provider_Client, Session_Service, Signature, Store};
use Automattic\WooCommerce\StoreApi\Utilities\{DraftOrderTrait, OrderController};

if ( ! defined( 'ABSPATH' ) || ! defined( 'PC_PANAPAY_TESTING' ) || true !== PC_PANAPAY_TESTING
	|| 'local' !== wp_get_environment_type() || DB_NAME !== 'panapay_qa_cpt_b04c7b5abbca'
	|| str_replace( '\\', '/', rtrim( ABSPATH, '/\\' ) ) !== 'D:/Codex/Tasks/2026-10-31/panapay-integration/work/site-cpt'
	|| ! function_exists( 'panapay_cpt_disable_automatic_as' ) || ! Store::installed() ) {
	throw new RuntimeException( 'Exact disposable payment CPT QA only.' );
}
global $wpdb;
if ( DB_NAME !== $wpdb->get_var( 'SELECT DATABASE()' ) ) { throw new RuntimeException( 'Actual database identity rejected.' ); }

final class Pc_Handoff_Native_Draft {
	use DraftOrderTrait;
	public function current(): ?WC_Order { return $this->get_draft_order(); }
	public function next(): WC_Order {
		$order = $this->get_draft_order();
		if ( ! $order ) {
			$order = ( new OrderController() )->create_order_from_cart();
			$order->save();
			$this->set_draft_order_id( $order->get_id() );
		}
		return $order;
	}
}

global $pc_handoff_native;
$pc_handoff_native = array( 'run' => bin2hex( random_bytes( 6 ) ), 'checks' => array(), 'requests' => array(),
	'provider_calls' => 0, 'central_create_calls' => 0, 'blocked_other_http' => 0, 'complete_calls' => array(), 'owned_orders' => array() );
function pc_handoff_native_check( bool $passed, string $name ): void {
	$GLOBALS['pc_handoff_native']['checks'][] = array( 'name' => $name, 'passed' => $passed );
	if ( ! $passed ) {
		echo wp_json_encode( array( 'complete' => false, 'assertions' => $GLOBALS['pc_handoff_native']['checks'], 'last_native_response' => $GLOBALS['pc_handoff_native']['last_response'] ?? null ) ) . "\n";
		throw new RuntimeException( $name );
	}
}
function pc_handoff_native_snapshot( WC_Order $order ): string {
	global $wpdb;
	$native = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID=%d", $order->get_id() ), ARRAY_A );
	$meta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id", $order->get_id() ), ARRAY_A );
	$items_table = $wpdb->prefix . 'woocommerce_order_items'; $itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
	$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$items_table} WHERE order_id=%d ORDER BY order_item_id", $order->get_id() ), ARRAY_A );
	$itemmeta = $wpdb->get_results( $wpdb->prepare( "SELECT m.* FROM {$itemmeta_table} m INNER JOIN {$items_table} i ON i.order_item_id=m.order_item_id WHERE i.order_id=%d ORDER BY m.meta_id", $order->get_id() ), ARRAY_A );
	$fence = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Store::creations_table() . ' WHERE order_id=%d', $order->get_id() ), ARRAY_A );
	if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Native snapshot read failed.' ); }
	return hash( 'sha256', serialize( array( $native, $meta, $items, $itemmeta, $fence ) ) );
}
function pc_handoff_native_request( int $order_id ): array {
	foreach ( $GLOBALS['pc_handoff_native']['requests'] as $payload ) {
		if ( \VialCove\Panapay\Session_Service::parse_reference( $payload['order_id'] )['order_id'] === $order_id ) { return $payload; }
	}
	throw new RuntimeException( 'Synthetic captured order request missing.' );
}
function pc_handoff_native_session(): WC_Session_Handler {
	foreach ( array_keys( $_COOKIE ) as $cookie ) { if ( str_starts_with( $cookie, 'wp_woocommerce_session_' ) ) { unset( $_COOKIE[ $cookie ] ); } }
	$session = new WC_Session_Handler(); $session->init(); $session->set_customer_session_cookie( true );
	return $session;
}

$saved = array( 'connector' => get_option( 'peptide_core_hq', null ), 'checkout_page' => get_option( 'woocommerce_checkout_page_id', null ),
	'currency' => get_option( 'woocommerce_currency', null ), 'user' => get_current_user_id(), 'session' => WC()->session,
	'cart' => WC()->cart, 'customer' => WC()->customer, 'cookies' => $_COOKIE, 'get' => $_GET, 'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
 'wp_query' => $GLOBALS['wp_query'], 'query_vars' => $GLOBALS['wp']->query_vars, 'post' => $GLOBALS['post'] ?? null, 'rest_server' => $GLOBALS['wp_rest_server'] ?? null );
$saved['original_cart_updates'] = $saved['cart'] instanceof WC_Cart ? WC_Cart_Session::are_updates_enabled_for_cart( $saved['cart'] ) : null;
$connector_key = hash( 'sha256', 'synthetic-native-connector|' . $pc_handoff_native['run'] );
$connector_uuid = wp_generate_uuid4();
$provider_secret = 'synthetic-native-provider-no-real-account';
$provider_settings = array( 'gateway' => 'https://example.com', 'mchid' => 'synthetic-native', 'secret' => '', 'mode' => '2', 'tag' => '', 'test_mode' => 'yes', 'payment_hosts' => array( 'example.com' ) );
$provider_config = Configuration::from_settings( $provider_settings, false ); $provider_config['secret'] = $provider_secret;
$mock = static function ( $pre, $args, $url ) use ( $connector_key, $provider_config, $provider_secret ) {
	$qa =& $GLOBALS['pc_handoff_native'];
	$fixture = static fn( array $data ) => array( 'headers' => array( 'content-type' => 'application/json', 'x-panapay-native-fixture' => $qa['run'] ),
		'body' => wp_json_encode( $data ), 'response' => array( 'code' => 200, 'message' => 'Synthetic' ), 'cookies' => array() );
	if ( 'https://example.com/index.php/admin/Payments/createPayment' === $url ) {
		++$qa['provider_calls'];
		if ( ! empty( $qa['next_provider_unknown'] ) ) { return $fixture( array( 'status' => 'PENDING' ) ); }
		return $fixture( array( 'status' => 'SUCCEEDED', 'data' => array( 'type' => 'iframe', 'payment_url' => 'https://example.com/synthetic-session/' . $qa['run'] . '/' . $qa['provider_calls'] ) ) );
	}
	$query = array(); parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query ); $route = $query['rest_route'] ?? null;
	if ( ! in_array( $route, array( Hub_Protocol::METHODS, Hub_Protocol::CREATE, Hub_Protocol::STATUS ), true )
		|| ! str_starts_with( $url, Bridge::hq_url() ) ) { ++$qa['blocked_other_http']; return new WP_Error( 'native_qa_http_forbidden', 'Synthetic QA rejects other HTTP.' ); }
	$body = json_decode( $args['body'], true, 32, JSON_THROW_ON_ERROR );
	if ( Hub_Protocol::METHODS === $route ) {
		$data = array( 'version' => 1, 'methods' => array_map( static fn( $account ) => array( 'account_id' => $account, 'enabled' => true, 'title' => 'Synthetic method', 'description' => 'Isolated QA only.', 'test_mode' => true, 'payment_hosts' => array( 'example.com' ), 'payment_icons' => array(), 'iframe_height' => 530 ), array( 'default', str_repeat( 'a', 32 ) ) ) );
	} elseif ( Hub_Protocol::CREATE === $route ) {
		++$qa['central_create_calls']; $qa['requests'][] = $body;
		$payload = $body; unset( $payload['account_id'] ); $payload['mchid'] = 'synthetic-native'; $payload['mode'] = '2'; $payload['tag'] = '';
		$payload['sign'] = Signature::generate( array_intersect_key( $payload, array_flip( array( 'mchid', 'currency', 'amount', 'site', 'order_id' ) ) ), $provider_secret );
		$qa['next_provider_unknown'] = 'default' === $body['account_id'] || ! empty( $qa['force_target_unknown'] );
		$data = Provider_Client::create( $payload, $provider_config ); $data['account_id'] = $body['account_id'];
		unset( $qa['next_provider_unknown'] );
	} else { $data = array( 'state' => 'unknown', 'account_id' => $body['account_id'] ?? 'default' ); }
	$nonce = (string) $args['headers']['X-PC-Pay-Nonce'];
	return $fixture( Hub_Protocol::response( $data, $nonce, $connector_key )->get_data() );
};
$final_http_guard = static function ( $pre ) {
	return is_array( $pre ) && ( $pre['headers']['x-panapay-native-fixture'] ?? null ) === $GLOBALS['pc_handoff_native']['run']
		? $pre : new WP_Error( 'native_qa_external_disabled', 'Every native QA HTTP request must be preempted.' );
};
add_filter( 'pre_http_request', $mock, -1000, 3 ); add_filter( 'pre_http_request', $final_http_guard, PHP_INT_MAX );
add_action( 'woocommerce_payment_complete', static function ( $id ) { $GLOBALS['pc_handoff_native']['complete_calls'][ $id ] = ( $GLOBALS['pc_handoff_native']['complete_calls'][ $id ] ?? 0 ) + 1; } );


function pc_handoff_native_response( string $method, array $data, bool $nonce = true ): WP_REST_Response {
 $request = new WP_REST_Request( $method, '/wc/store/v1/checkout' );
 $request->set_header( 'Content-Type', 'application/json' );
 $request->set_header( 'Nonce', $nonce ? wp_create_nonce( 'wc_store_api' ) : 'synthetic-invalid-nonce' );
 $request->set_body( wp_json_encode( $data ) );
 $response = rest_get_server()->dispatch( $request );
 $result = $response->get_data();
 $GLOBALS['pc_handoff_native']['last_response'] = array( 'status' => $response->get_status(), 'code' => $result['code'] ?? null, 'fixture_message' => $result['message'] ?? null, 'fixture_error_codes' => array_map( static fn( $e ) => $e['code'] ?? null, (array) ( $result['data']['errors'] ?? array() ) ), 'invalid_param_names' => array_keys( (array) ( $result['data']['params'] ?? array() ) ) );
 return $response;
}
function pc_handoff_native_slots( int $old ): array {
 global $wpdb;
 $rows = $wpdb->get_results( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'pc_panapay_handoff_%'", ARRAY_A );
 if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Synthetic slot inspection failed.' ); }
 return array_values( array_filter( $rows, static fn( $r ) => ( json_decode( $r['option_value'], true )['old'] ?? 0 ) === $old ) );
}
function pc_handoff_native_write_slot( array $slot, string $next ): void {
 global $wpdb;
 if ( 1 !== $wpdb->update( $wpdb->options, array( 'option_value' => $next ), array( 'option_name' => $slot['option_name'] ) ) ) { throw new RuntimeException( 'Synthetic slot fixture update failed.' ); }
 wp_cache_delete( $slot['option_name'], 'options' );
}
function pc_handoff_native_order_count(): int {
 global $wpdb;
 return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_placehold')" );
}
function pc_handoff_native_restore_old_pointer( int $old ): void {
 WC()->session->set( 'store_api_draft_order', $old );
 WC()->session->set( 'order_awaiting_payment', $old );
 WC()->session->save_data();
}

try {
 wp_set_current_user( 0 );
 update_option( 'peptide_core_hq', array( 'key' => $connector_key, 'uuid' => $connector_uuid ), false );
 update_option( 'woocommerce_currency', 'USD' );
 $checkout_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Synthetic method checkout ' . $pc_handoff_native['run'], 'post_content' => '<!-- wp:woocommerce/checkout /-->' ) );
 if ( ! is_int( $checkout_page ) || $checkout_page < 1 ) { throw new RuntimeException( 'Synthetic page creation failed.' ); }
 update_option( 'woocommerce_checkout_page_id', $checkout_page );
 $GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $checkout_page ) ); $GLOBALS['wp']->query_vars = array(); $_GET = array(); $_SERVER['REQUEST_METHOD'] = 'GET'; $GLOBALS['post'] = get_post( $checkout_page );
 do_action( 'wp', $GLOBALS['wp'] );
 // CLI shares one hook registry across modeled requests. Keep the bootstrap's
 // abandoned empty cart from clearing this fixture's live draft pointer.
 if ( $saved['cart'] instanceof WC_Cart ) { WC_Cart_Session::set_updates_enabled_for_cart( $saved['cart'], false ); }
 WC()->session = pc_handoff_native_session(); WC()->customer = new WC_Customer( 0, true ); WC()->cart = new WC_Cart();
 $address = array( 'first_name' => 'Synthetic', 'last_name' => 'Method QA', 'address_1' => '123 Synthetic Fixture Street', 'address_2' => '', 'city' => 'New York', 'state' => 'NY', 'postcode' => '10001', 'country' => 'US', 'email' => 'method-handoff@example.invalid', 'phone' => '+12025550123' );
 foreach ( array( 'billing', 'shipping' ) as $type ) { foreach ( $address as $field => $value ) { $setter = 'set_' . $type . '_' . $field; if ( is_callable( array( WC()->customer, $setter ) ) ) { WC()->customer->$setter( $value ); } } }
 // This suite isolates payment continuity, not native stock-reservation timing.
 // A virtual unlimited fixture avoids core treating a same-second unchanged
 // reservation UPDATE (affected_rows=0) as an unrelated stock failure.
 $product = new WC_Product_Simple(); $product->set_name( 'Synthetic method handoff ' . $pc_handoff_native['run'] ); $product->set_status( 'publish' ); $product->set_regular_price( '19.90' ); $product->set_virtual( true ); $product->set_manage_stock( false ); $product->save();
 pc_handoff_native_check( false !== WC()->cart->add_to_cart( $product->get_id(), 1 ), 'native_cart_contains_own_virtual_synthetic_product' );
 WC()->payment_gateways()->init();
 $paypal = Gateway::native_id(); $card = Gateway::native_id( str_repeat( 'a', 32 ) );
 pc_handoff_native_check( in_array( $card, WC()->payment_gateways()->get_payment_gateway_ids(), true ), 'native_gateway_registry_contains_second_synthetic_method' );
 // The CLI bootstrap may already have materialized a REST schema before the
 // synthetic policy was installed. A new native server models the next request.
 $GLOBALS['wp_rest_server'] = null; rest_get_server();
 $drafts = new Pc_Handoff_Native_Draft(); $old = $drafts->next(); $old->set_status( 'pending' ); $old->set_payment_method( $paypal ); $old->set_address( $address, 'billing' ); $old->set_address( $address, 'shipping' ); $old->set_cart_hash( WC()->cart->get_cart_hash() ); $old->save();
 $pc_handoff_native['owned_orders'][] = $old->get_id(); pc_handoff_native_restore_old_pointer( $old->get_id() );
 wc_clear_notices(); $first = ( new Gateway() )->process_payment( $old->get_id() );
 pc_handoff_native_check( 'failure' === ( $first['result'] ?? null ) && 1 === $pc_handoff_native['provider_calls'], 'synthetic_provider_unknown_keeps_original_order_pending' );
 $old = new WC_Order( $old->get_id() ); $before = pc_handoff_native_snapshot( $old );
 pc_handoff_native_check( $paypal === Session_Service::frozen_gateway( $old ), 'unknown_order_has_independent_frozen_gateway_identity' );
 $notice = wp_json_encode( wc_get_notices( 'error' ) );
 pc_handoff_native_check( str_contains( $notice, 'Start a new order' ) && str_contains( $notice, 'pc_panapay_new_checkout' ), 'same_method_unknown_gateway_failure_offers_new_checkout_link' );
 // The direct gateway fixture above bypasses native NoticeHandler's response
 // conversion. Consume its captured notice before modeling the next request.
 wc_clear_notices();
 $url = Checkout_Lifecycle::new_checkout_url(); parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET );
 Checkout_Lifecycle::on_checkout_navigation();
 pc_handoff_native_check( null === WC()->session->get( 'store_api_draft_order' ) && null === WC()->session->get( 'order_awaiting_payment' ), 'native_nonce_bound_new_checkout_link_detaches_old_pointers' );
 pc_handoff_native_check( $before === pc_handoff_native_snapshot( new WC_Order( $old->get_id() ) ), 'new_checkout_cta_navigation_preserves_old_order_items_book_and_fence' );
 pc_handoff_native_check( in_array( $old->get_id(), array_map( static fn( $o ) => $o->get_id(), Pending_Orders::visible_orders() ), true ), 'unpaid_old_order_stays_visible_in_same_guest_session' );
 $_GET = array(); pc_handoff_native_restore_old_pointer( $old->get_id() );
 $get_count = pc_handoff_native_order_count(); $bad_get = pc_handoff_native_response( 'GET', array(), false );
 pc_handoff_native_check( 403 === $bad_get->get_status() && $before === pc_handoff_native_snapshot( new WC_Order( $old->get_id() ) ) && $old->get_id() === WC()->session->get( 'store_api_draft_order' ), 'native_bad_nonce_get_preserves_frozen_order_and_pointer' );
 $get = pc_handoff_native_response( 'GET', array() );
 pc_handoff_native_check( 200 === $get->get_status() && null === WC()->session->get( 'store_api_draft_order' ) && $before === pc_handoff_native_snapshot( new WC_Order( $old->get_id() ) ) && $get_count === pc_handoff_native_order_count(), 'native_authenticated_get_detaches_without_resaving_frozen_order_or_creating_order' );
 pc_handoff_native_restore_old_pointer( $old->get_id() );
 $next_address = array_merge( $address, array( 'address_1' => '456 Synthetic Changed Street' ) );
 $payload = array( 'payment_method' => $card, 'payment_data' => array(), 'billing_address' => $next_address, 'shipping_address' => array_diff_key( $next_address, array_flip( array( 'email', 'phone' ) ) ), 'additional_fields' => array( 'peptide-core/checkout-ack' => true, 'peptide-panapay/phone' => '+12025550123' ), 'customer_note' => 'Synthetic checkout handoff only.' );
 $count = pc_handoff_native_order_count(); $bad = pc_handoff_native_response( 'PATCH', $payload, false );
 pc_handoff_native_check( 403 === $bad->get_status(), 'native_bad_nonce_rejected_before_handoff' );
 pc_handoff_native_check( $before === pc_handoff_native_snapshot( new WC_Order( $old->get_id() ) ) && $old->get_id() === WC()->session->get( 'store_api_draft_order' ) && array() === pc_handoff_native_slots( $old->get_id() ) && $count === pc_handoff_native_order_count(), 'bad_nonce_preserves_old_native_rows_pointers_and_no_slots_or_orders' );
 $bad_post = pc_handoff_native_response( 'POST', $payload, false );
 pc_handoff_native_check( 403 === $bad_post->get_status() && $before === pc_handoff_native_snapshot( new WC_Order( $old->get_id() ) ) && $old->get_id() === WC()->session->get( 'store_api_draft_order' ) && array() === pc_handoff_native_slots( $old->get_id() ) && $count === pc_handoff_native_order_count() && 1 === $pc_handoff_native['provider_calls'], 'native_bad_nonce_post_cannot_mutate_old_order_or_create_handoff_or_payment' );
 $patch = pc_handoff_native_response( 'PATCH', $payload );
 pc_handoff_native_check( 200 === $patch->get_status(), 'native_same_page_patch_switches_method_and_address' );
 pc_handoff_native_check( $before === pc_handoff_native_snapshot( new WC_Order( $old->get_id() ) ), 'same_page_patch_preserves_old_native_rows_and_payment_fence' );
 pc_handoff_native_check( null === WC()->session->get( 'store_api_draft_order' ) && $count === pc_handoff_native_order_count() && array() === pc_handoff_native_slots( $old->get_id() ), 'patch_uses_native_no_order_session_flow_without_new_order' );
 pc_handoff_native_check( $card === WC()->session->get( 'chosen_payment_method' ), 'native_no_order_patch_keeps_selected_method_in_live_session' );
 $post = pc_handoff_native_response( 'POST', $payload );
 pc_handoff_native_check( 200 === $post->get_status(), 'native_cross_method_post_creates_new_order_and_payment' );
 $new_id = (int) ( $post->get_data()['order_id'] ?? 0 );
 pc_handoff_native_check( $new_id > 0 && $new_id !== $old->get_id(), 'native_new_order_id_is_independent_from_old_unknown_order' );
 $pc_handoff_native['owned_orders'][] = $new_id; $new = new WC_Order( $new_id );
 pc_handoff_native_check( '456 Synthetic Changed Street' === $new->get_billing_address_1() && '456 Synthetic Changed Street' === $new->get_shipping_address_1(), 'native_post_copies_current_checkout_address_into_new_order_only' );
 pc_handoff_native_check( $card === $new->get_payment_method() && 2 === $pc_handoff_native['provider_calls'] && 2 === $pc_handoff_native['central_create_calls'], 'new_order_uses_new_method_and_exactly_one_additional_mocked_creation' );
 pc_handoff_native_check( $before === pc_handoff_native_snapshot( new WC_Order( $old->get_id() ) ), 'new_native_post_does_not_modify_original_native_order' );
 $slots = pc_handoff_native_slots( $old->get_id() );
 pc_handoff_native_check( 1 === count( $slots ) && $new_id === json_decode( $slots[0]['option_value'], true )['new'], 'unique_native_sql_slot_binds_exact_new_order_before_payment' );
 $slot = $slots[0]; $book_before = serialize( $new->get_meta( '_pc_panapay_sessions', true ) );
 pc_handoff_native_restore_old_pointer( $old->get_id() );
 $repeat = pc_handoff_native_response( 'POST', $payload );
 pc_handoff_native_check( 200 === $repeat->get_status() && $new_id === (int) ( $repeat->get_data()['order_id'] ?? 0 ) && 2 === $pc_handoff_native['provider_calls'] && 1 === count( pc_handoff_native_slots( $old->get_id() ) ), 'stale_duplicate_post_reuses_same_native_new_order_and_creation' );
 pc_handoff_native_check( $book_before === serialize( ( new WC_Order( $new_id ) )->get_meta( '_pc_panapay_sessions', true ) ) && $before === pc_handoff_native_snapshot( new WC_Order( $old->get_id() ) ), 'duplicate_post_keeps_both_saved_payment_books_and_old_rows' );
 $slot_record = json_decode( $slot['option_value'], true );
 foreach ( array( 'expired', 'ambiguous_unbound' ) as $case ) {
  $changed = $slot_record; if ( 'expired' === $case ) { $changed['created'] = time() - 604801; } else { $changed['new'] = 0; }
  pc_handoff_native_write_slot( $slot, wp_json_encode( $changed ) ); pc_handoff_native_restore_old_pointer( $old->get_id() );
  $n = pc_handoff_native_order_count(); $denied = pc_handoff_native_response( 'POST', $payload );
  pc_handoff_native_check( 409 === $denied->get_status() && $n === pc_handoff_native_order_count() && 2 === $pc_handoff_native['provider_calls'] && $before === pc_handoff_native_snapshot( new WC_Order( $old->get_id() ) ), 'native_' . $case . '_slot_fails_closed_without_takeover_or_new_payment' );
  pc_handoff_native_write_slot( $slot, $slot['option_value'] );
 }
 $lease = Store::acquire( $old->get_id() );
 try { pc_handoff_native_restore_old_pointer( $old->get_id() ); $busy = pc_handoff_native_response( 'POST', $payload ); }
 finally { Store::release( $lease ); }
 pc_handoff_native_check( $busy->get_status() >= 400 && 2 === $pc_handoff_native['provider_calls'] && $before === pc_handoff_native_snapshot( new WC_Order( $old->get_id() ) ), 'native_old_order_exclusive_lease_blocks_overlapping_handoff_without_creation' );
 foreach ( array( 'paid', 'foreign_owner' ) as $case ) {
  $candidate = new WC_Order(); $candidate->set_status( 'paid' === $case ? 'completed' : 'checkout-draft' ); $candidate->set_customer_id( 'foreign_owner' === $case ? 999991 : 0 ); $candidate->save(); $pc_handoff_native['owned_orders'][] = $candidate->get_id();
  $changed = $slot_record; $changed['new'] = $candidate->get_id(); pc_handoff_native_write_slot( $slot, wp_json_encode( $changed ) ); pc_handoff_native_restore_old_pointer( $old->get_id() );
  $n = pc_handoff_native_order_count(); $denied = pc_handoff_native_response( 'POST', $payload );
  pc_handoff_native_check( 409 === $denied->get_status() && $n === pc_handoff_native_order_count() && 2 === $pc_handoff_native['provider_calls'], 'native_' . $case . '_replacement_order_fails_closed' );
  pc_handoff_native_write_slot( $slot, $slot['option_value'] );
 }
 pc_handoff_native_restore_old_pointer( $old->get_id() ); $foreign = new WC_Order( $old->get_id() ); $foreign->set_customer_id( 999991 ); $foreign->save(); $foreign_before = pc_handoff_native_snapshot( $foreign ); WC()->session->__unset( 'pc_panapay_checkout_handoff' );
 $denied = pc_handoff_native_response( 'PATCH', $payload );
 pc_handoff_native_check( $denied->get_status() >= 400 && $foreign_before === pc_handoff_native_snapshot( new WC_Order( $old->get_id() ) ) && 2 === $pc_handoff_native['provider_calls'], 'foreign_owner_frozen_old_order_rejected_without_native_mutation' );
 $foreign->set_customer_id( 0 ); $foreign->save();
 $pc_handoff_native['force_target_unknown'] = true; $unknown_payload = $payload; $unknown_payload['customer_note'] .= ' Unknown replacement case.';
 pc_handoff_native_restore_old_pointer( $old->get_id() ); $unknown_response = pc_handoff_native_response( 'POST', $unknown_payload ); unset( $pc_handoff_native['force_target_unknown'] );
 $unknown_id = (int) WC()->session->get( 'store_api_draft_order', 0 );
 pc_handoff_native_check( $unknown_response->get_status() >= 400 && $unknown_id > 0 && ! in_array( $unknown_id, array( $old->get_id(), $new_id ), true ), 'native_new_cross_method_unknown_keeps_its_independent_order' );
 $pc_handoff_native['owned_orders'][] = $unknown_id;
 $unknown_body = wp_json_encode( $unknown_response->get_data() );
 pc_handoff_native_check( str_contains( $unknown_body, 'Start a new order' ) && 3 === $pc_handoff_native['provider_calls'], 'native_new_payment_unknown_keeps_original_gateway_error_and_action_text' );
 wc_clear_notices();
 $unknown_book = serialize( ( new WC_Order( $unknown_id ) )->get_meta( '_pc_panapay_sessions', true ) ); $unknown_count = pc_handoff_native_order_count();
 $unknown_retry = pc_handoff_native_response( 'POST', $unknown_payload );
 pc_handoff_native_check( $unknown_retry->get_status() >= 400 && 3 === $pc_handoff_native['provider_calls'] && $unknown_count === pc_handoff_native_order_count() && $unknown_id === WC()->session->get( 'store_api_draft_order' ) && $unknown_book === serialize( ( new WC_Order( $unknown_id ) )->get_meta( '_pc_panapay_sessions', true ) ), 'unchanged_same_method_unknown_retry_keeps_one_order_one_book_and_no_new_payment' );
 wc_clear_notices();
 $same_payload = $payload; $same_payload['payment_method'] = $paypal; $same_payload['billing_address']['address_1'] = '789 Synthetic Direct POST Street'; $same_payload['shipping_address']['address_1'] = '789 Synthetic Direct POST Street';
 pc_handoff_native_restore_old_pointer( $old->get_id() ); WC()->session->__unset( 'pc_panapay_checkout_handoff' );
 $same_before = pc_handoff_native_snapshot( new WC_Order( $old->get_id() ) ); $same = pc_handoff_native_response( 'POST', $same_payload );
 $same_id = (int) WC()->session->get( 'store_api_draft_order', 0 );
 pc_handoff_native_check( $same->get_status() >= 400 && $same_id > 0 && ! in_array( $same_id, array( $old->get_id(), $new_id, $unknown_id ), true ) && 4 === $pc_handoff_native['provider_calls'], 'native_same_method_direct_post_changed_address_creates_independent_order' );
 $pc_handoff_native['owned_orders'][] = $same_id;
 pc_handoff_native_check( $same_before === pc_handoff_native_snapshot( new WC_Order( $old->get_id() ) ) && '789 Synthetic Direct POST Street' === ( new WC_Order( $same_id ) )->get_billing_address_1(), 'same_method_changed_address_preserves_original_raw_rows_and_uses_new_address' );
 wc_clear_notices();
 $invalid = new WC_Order(); $invalid->set_status( 'pending' ); $invalid->set_customer_id( 0 ); $invalid->set_cart_hash( WC()->cart->get_cart_hash() ); $invalid->set_payment_method( $paypal ); $invalid->set_address( $address, 'billing' ); $invalid->set_address( $address, 'shipping' ); $invalid->add_product( wc_get_product( $product->get_id() ), 1 ); $invalid->calculate_totals(); $invalid->set_total( '19.90' ); $invalid->update_meta_data( '_pc_panapay_sessions', array( 'malformed' => true ) ); $invalid->save(); $pc_handoff_native['owned_orders'][] = $invalid->get_id();
 pc_handoff_native_restore_old_pointer( $invalid->get_id() ); WC()->session->__unset( 'pc_panapay_checkout_handoff' ); $invalid_before = pc_handoff_native_snapshot( $invalid ); $invalid_count = pc_handoff_native_order_count();
 pc_handoff_native_check( $invalid->get_id() === ( $drafts->current()?->get_id() ?? 0 ), 'malformed_book_fixture_is_current_native_payable_draft' );
 $invalid_probe = static function () use ( $invalid, $drafts ) { $current = $drafts->current(); $GLOBALS['pc_handoff_native']['invalid_probe'][] = array( 'pointer_matches' => $invalid->get_id() === WC()->session->get( 'store_api_draft_order' ), 'native_draft_matches' => $invalid->get_id() === ( $current?->get_id() ?? 0 ), 'native_draft_null' => null === $current, 'raw_needs_payment' => ( new WC_Order( $invalid->get_id() ) )->needs_payment(), 'raw_cart_matches' => ( new WC_Order( $invalid->get_id() ) )->has_cart_hash( WC()->cart->get_cart_hash() ) ); };
 add_action( 'woocommerce_after_calculate_totals', $invalid_probe, PHP_INT_MAX );
 $invalid_response = pc_handoff_native_response( 'POST', $payload );
 remove_action( 'woocommerce_after_calculate_totals', $invalid_probe, PHP_INT_MAX );
 $GLOBALS['pc_handoff_native']['last_response']['fixture_projection'] = array( 'same_response_order' => $invalid->get_id() === (int) ( $invalid_response->get_data()['order_id'] ?? 0 ), 'same_session_pointer' => $invalid->get_id() === WC()->session->get( 'store_api_draft_order' ), 'cart_hash_still_matches' => $invalid->has_cart_hash( WC()->cart->get_cart_hash() ), 'book_nonempty' => '' !== ( new WC_Order( $invalid->get_id() ) )->get_meta( '_pc_panapay_sessions', true ), 'provider_calls' => $pc_handoff_native['provider_calls'], 'new_order_count' => pc_handoff_native_order_count() - $invalid_count, 'native_calculation_probes' => $pc_handoff_native['invalid_probe'] ?? array() );
 pc_handoff_native_check( 409 === $invalid_response->get_status() && $invalid_before === pc_handoff_native_snapshot( new WC_Order( $invalid->get_id() ) ) && $invalid_count === pc_handoff_native_order_count() && 4 === $pc_handoff_native['provider_calls'], 'native_nonempty_unconfirmed_book_never_falls_through_to_native_mutation_or_payment' );
 $request = pc_handoff_native_request( $old->get_id() ); $transaction = 'synthetic-method-old-' . $pc_handoff_native['run'];
 $event = array( 'account_id' => 'default', 'event_id' => hash( 'sha256', $transaction ), 'order_number' => $request['order_id'], 'status' => 'SUCCEEDED', 'transaction_id' => $transaction );
 $event['sign'] = Signature::generate( array_intersect_key( $event, array_flip( array( 'order_number', 'status', 'transaction_id' ) ) ), Hub_Protocol::event_key( $connector_key ) );
 $event_body = Hub_Protocol::json( $event ); $nonce = bin2hex( random_bytes( 16 ) ); $time = time(); $notification = new WP_REST_Request( 'POST', Hub_Protocol::EVENT ); $notification->set_header( 'Content-Type', 'application/json' ); $notification->set_body( $event_body );
 $notification->set_header( 'X-PC-Pay-Store', $connector_uuid ); $notification->set_header( 'X-PC-Pay-Time', (string) $time ); $notification->set_header( 'X-PC-Pay-Nonce', $nonce ); $notification->set_header( 'X-PC-Pay-Sign', Hub_Protocol::signature( $connector_key, 'POST', Hub_Protocol::EVENT, $time, $nonce, $event_body ) );
 $notified = Bridge::receive( $notification );
 pc_handoff_native_check( $notified instanceof WP_REST_Response && true === ( $notified->get_data()['data']->accepted ?? false ), 'original_signed_notification_still_matches_original_order' );
 pc_handoff_native_check( ( new WC_Order( $old->get_id() ) )->is_paid() && ! ( new WC_Order( $new_id ) )->is_paid() && 1 === ( $pc_handoff_native['complete_calls'][ $old->get_id() ] ?? 0 ), 'old_unknown_order_completes_once_without_paying_new_order' );
 pc_handoff_native_check( 4 === $pc_handoff_native['provider_calls'] && 4 === $pc_handoff_native['central_create_calls'], 'all_native_retries_guards_and_notification_add_zero_extra_creation' );
 // Native NoticeHandler deliberately strips HTML from REST errors. Blocks has
 // its own non-order DTO action, tested separately in the JS hook fixture.
 $blocks = new Blocks( $card ); $blocks->initialize(); $dto = $blocks->get_payment_method_data();
 $dto_url = $dto['new_checkout_url'] ?? ''; $dto_query = array(); parse_str( (string) wp_parse_url( $dto_url, PHP_URL_QUERY ), $dto_query );
 pc_handoff_native_check( is_string( $dto_url ) && wp_parse_url( $dto_url, PHP_URL_HOST ) === wp_parse_url( wc_get_checkout_url(), PHP_URL_HOST ) && preg_match( '/\A[a-f0-9]{32}\z/', $dto_query['pc_panapay_new_checkout'] ?? '' ) && is_string( $dto_query['pc_panapay_checkout_nonce'] ?? null ), 'native_blocks_dto_has_same_origin_nonce_bound_separate_checkout_action' );
 pc_handoff_native_check( ! array_intersect( array_keys( $dto ), array( 'secret', 'sign', 'gateway', 'order_id', 'order_key', 'transaction_id' ) ), 'native_blocks_dto_contains_no_gateway_credentials_or_order_identity' );
 pc_handoff_native_restore_old_pointer( $unknown_id ); $unknown_before = pc_handoff_native_snapshot( new WC_Order( $unknown_id ) ); $_GET = $dto_query;
 Checkout_Lifecycle::on_checkout_navigation();
 pc_handoff_native_check( null === WC()->session->get( 'store_api_draft_order' ) && null === WC()->session->get( 'order_awaiting_payment' ) && $unknown_before === pc_handoff_native_snapshot( new WC_Order( $unknown_id ) ), 'native_blocks_dto_action_nonce_is_valid_and_detaches_only_checkout_pointers' );
 $report = array( 'complete' => true, 'nativeDatastore' => WC_Data_Store::load( 'order' )->get_current_class_name(), 'assertions' => $pc_handoff_native['checks'], 'providerTransport' => 'Fully preempted synthetic WordPress HTTP fixture', 'centralTransport' => 'Synthetic signed response fixture', 'providerCalls' => $pc_handoff_native['provider_calls'], 'actualProviderOrSettlementTested' => false, 'HTTPServerStarted' => false, 'productionAccessed' => false, 'ownedSyntheticOrdersCreated' => count( $pc_handoff_native['owned_orders'] ), 'otherHTTPBlocked' => $pc_handoff_native['blocked_other_http'], 'simultaneous_processes_exercised' => false, 'concurrency_scope' => 'Native SQL exclusive old-order lease and stale repeated POST mapped to one permanent slot; no simultaneous OS workers claimed.' );
} finally {
	foreach ( array( 'peptide_core_hq' => $saved['connector'], 'woocommerce_checkout_page_id' => $saved['checkout_page'], 'woocommerce_currency' => $saved['currency'] ) as $name => $value ) {
		if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value ); }
	}
	wp_set_current_user( $saved['user'] ); WC()->session = $saved['session']; WC()->cart = $saved['cart']; WC()->customer = $saved['customer'];
 if ( $saved['cart'] instanceof WC_Cart ) { WC_Cart_Session::set_updates_enabled_for_cart( $saved['cart'], $saved['original_cart_updates'] ); }
 $_COOKIE = $saved['cookies']; $_GET = $saved['get']; $GLOBALS['wp_query'] = $saved['wp_query']; $GLOBALS['wp']->query_vars = $saved['query_vars']; $GLOBALS['post'] = $saved['post']; $GLOBALS['wp_rest_server'] = $saved['rest_server'];
	if ( null === $saved['request_method'] ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $saved['request_method']; }
	remove_filter( 'pre_http_request', $mock, -1000 );
	// Keep the final no-external-HTTP guard active through native shutdown hooks.
}
$restored = true;
foreach ( array( 'peptide_core_hq' => $saved['connector'], 'woocommerce_checkout_page_id' => $saved['checkout_page'], 'woocommerce_currency' => $saved['currency'] ) as $name => $value ) {
	$restored = $restored && serialize( get_option( $name, null ) ) === serialize( $value );
}
pc_handoff_native_check( $restored, 'qa_connector_currency_and_checkout_options_restored_exactly' );
pc_handoff_native_check( WC()->session === $saved['session'] && WC()->cart === $saved['cart'] && WC()->customer === $saved['customer'], 'qa_original_native_session_cart_and_customer_objects_restored' );
$report['assertions'] = $pc_handoff_native['checks']; $report['temporaryOptionsRestored'] = true;
echo wp_json_encode( $report ) . "\n";
