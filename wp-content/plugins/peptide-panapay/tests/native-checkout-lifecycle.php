<?php
/**
 * Disposable native Woo acceptance. All HTTP is preempted by synthetic fixtures.
 * wp eval-file .../tests/native-checkout-lifecycle.php
 */
use VialCove\Panapay\{Bridge, Checkout_Lifecycle, Configuration, Gateway, Hub_Protocol, Pending_Orders, Provider_Client, Signature, Store};
use Automattic\WooCommerce\StoreApi\Utilities\{DraftOrderTrait, OrderController};

if ( ! defined( 'ABSPATH' ) || ! defined( 'PC_PANAPAY_TESTING' ) || true !== PC_PANAPAY_TESTING
	|| 'local' !== wp_get_environment_type() || DB_NAME !== 'panapay_qa_cpt_b04c7b5abbca'
	|| str_replace( '\\', '/', rtrim( ABSPATH, '/\\' ) ) !== 'D:/Codex/Tasks/2026-10-31/panapay-integration/work/site-cpt'
	|| ! function_exists( 'panapay_cpt_disable_automatic_as' ) || ! Store::installed() ) {
	throw new RuntimeException( 'Exact disposable payment CPT QA only.' );
}
global $wpdb;
if ( DB_NAME !== $wpdb->get_var( 'SELECT DATABASE()' ) ) { throw new RuntimeException( 'Actual database identity rejected.' ); }

final class Pc_Lifecycle_Native_Draft {
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

global $pc_lifecycle_native;
$pc_lifecycle_native = array( 'run' => bin2hex( random_bytes( 6 ) ), 'checks' => array(), 'requests' => array(),
	'provider_calls' => 0, 'central_create_calls' => 0, 'blocked_other_http' => 0, 'complete_calls' => array(), 'owned_orders' => array() );
function pc_lifecycle_native_check( bool $passed, string $name ): void {
	$GLOBALS['pc_lifecycle_native']['checks'][] = array( 'name' => $name, 'passed' => $passed );
	if ( ! $passed ) { throw new RuntimeException( $name ); }
}
function pc_lifecycle_native_snapshot( WC_Order $order ): string {
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
function pc_lifecycle_native_request( int $order_id ): array {
	foreach ( $GLOBALS['pc_lifecycle_native']['requests'] as $payload ) {
		if ( \VialCove\Panapay\Session_Service::parse_reference( $payload['order_id'] )['order_id'] === $order_id ) { return $payload; }
	}
	throw new RuntimeException( 'Synthetic captured order request missing.' );
}
function pc_lifecycle_native_session(): WC_Session_Handler {
	foreach ( array_keys( $_COOKIE ) as $cookie ) { if ( str_starts_with( $cookie, 'wp_woocommerce_session_' ) ) { unset( $_COOKIE[ $cookie ] ); } }
	$session = new WC_Session_Handler(); $session->init(); $session->set_customer_session_cookie( true );
	return $session;
}

$saved = array( 'connector' => get_option( 'peptide_core_hq', null ), 'checkout_page' => get_option( 'woocommerce_checkout_page_id', null ),
	'currency' => get_option( 'woocommerce_currency', null ), 'user' => get_current_user_id(), 'session' => WC()->session,
	'cart' => WC()->cart, 'customer' => WC()->customer, 'cookies' => $_COOKIE, 'get' => $_GET, 'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
	'wp_query' => $GLOBALS['wp_query'], 'query_vars' => $GLOBALS['wp']->query_vars, 'post' => $GLOBALS['post'] ?? null );
$connector_key = hash( 'sha256', 'synthetic-native-connector|' . $pc_lifecycle_native['run'] );
$connector_uuid = wp_generate_uuid4();
$provider_secret = 'synthetic-native-provider-no-real-account';
$provider_settings = array( 'gateway' => 'https://example.com', 'mchid' => 'synthetic-native', 'secret' => '', 'mode' => '2', 'tag' => '', 'test_mode' => 'yes', 'payment_hosts' => array( 'example.com' ) );
$provider_config = Configuration::from_settings( $provider_settings, false ); $provider_config['secret'] = $provider_secret;
$mock = static function ( $pre, $args, $url ) use ( $connector_key, $provider_config, $provider_secret ) {
	$qa =& $GLOBALS['pc_lifecycle_native'];
	$fixture = static fn( array $data ) => array( 'headers' => array( 'content-type' => 'application/json', 'x-panapay-native-fixture' => $qa['run'] ),
		'body' => wp_json_encode( $data ), 'response' => array( 'code' => 200, 'message' => 'Synthetic' ), 'cookies' => array() );
	if ( 'https://example.com/index.php/admin/Payments/createPayment' === $url ) {
		++$qa['provider_calls'];
		return $fixture( array( 'status' => 'SUCCEEDED', 'data' => array( 'type' => 'iframe', 'payment_url' => 'https://example.com/synthetic-session/' . $qa['run'] . '/' . $qa['provider_calls'] ) ) );
	}
	$query = array(); parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query ); $route = $query['rest_route'] ?? null;
	if ( ! in_array( $route, array( Hub_Protocol::METHODS, Hub_Protocol::CREATE, Hub_Protocol::STATUS ), true )
		|| ! str_starts_with( $url, Bridge::hq_url() ) ) { ++$qa['blocked_other_http']; return new WP_Error( 'native_qa_http_forbidden', 'Synthetic QA rejects other HTTP.' ); }
	$body = json_decode( $args['body'], true, 32, JSON_THROW_ON_ERROR );
	if ( Hub_Protocol::METHODS === $route ) {
		$data = array( 'version' => 1, 'methods' => array( array( 'account_id' => 'default', 'enabled' => true, 'title' => 'Synthetic card',
			'description' => 'Isolated QA only.', 'test_mode' => true, 'payment_hosts' => array( 'example.com' ), 'payment_icons' => array(), 'iframe_height' => 530 ) ) );
	} elseif ( Hub_Protocol::CREATE === $route ) {
		++$qa['central_create_calls']; $qa['requests'][] = $body;
		$payload = $body; unset( $payload['account_id'] ); $payload['mchid'] = 'synthetic-native'; $payload['mode'] = '2'; $payload['tag'] = '';
		$payload['sign'] = Signature::generate( array_intersect_key( $payload, array_flip( array( 'mchid', 'currency', 'amount', 'site', 'order_id' ) ) ), $provider_secret );
		$data = Provider_Client::create( $payload, $provider_config ); $data['account_id'] = 'default';
	} else { $data = array( 'state' => 'unknown', 'account_id' => 'default' ); }
	$nonce = (string) $args['headers']['X-PC-Pay-Nonce'];
	return $fixture( Hub_Protocol::response( $data, $nonce, $connector_key )->get_data() );
};
$final_http_guard = static function ( $pre ) {
	return is_array( $pre ) && ( $pre['headers']['x-panapay-native-fixture'] ?? null ) === $GLOBALS['pc_lifecycle_native']['run']
		? $pre : new WP_Error( 'native_qa_external_disabled', 'Every native QA HTTP request must be preempted.' );
};
add_filter( 'pre_http_request', $mock, -1000, 3 ); add_filter( 'pre_http_request', $final_http_guard, PHP_INT_MAX );
add_action( 'woocommerce_payment_complete', static function ( $id ) { $GLOBALS['pc_lifecycle_native']['complete_calls'][ $id ] = ( $GLOBALS['pc_lifecycle_native']['complete_calls'][ $id ] ?? 0 ) + 1; } );

try {
	wp_set_current_user( 0 );
	update_option( 'peptide_core_hq', array( 'key' => $connector_key, 'uuid' => $connector_uuid ), false );
	update_option( 'woocommerce_currency', 'USD' );
	$checkout_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Synthetic checkout ' . $pc_lifecycle_native['run'], 'post_content' => '<!-- wp:woocommerce/checkout /-->' ) );
	if ( ! is_int( $checkout_page ) || $checkout_page < 1 ) { throw new RuntimeException( 'Synthetic checkout page creation failed.' ); }
	update_option( 'woocommerce_checkout_page_id', $checkout_page );
	$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $checkout_page ) ); $GLOBALS['wp']->query_vars = array(); $_GET = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
	$GLOBALS['post'] = get_post( $checkout_page );
	// WP-CLI does not run the frontend wp action; Woo deliberately returns an
	// unknown checkout context until that real WordPress lifecycle point.
	do_action( 'wp', $GLOBALS['wp'] );
	WC()->session = pc_lifecycle_native_session(); WC()->customer = new WC_Customer( 0, true ); WC()->cart = new WC_Cart();
	$address = array( 'first_name' => 'Synthetic', 'last_name' => 'Lifecycle QA', 'address_1' => '123 Synthetic Test Street', 'city' => 'Test City', 'state' => 'NY', 'postcode' => '10001', 'country' => 'US', 'email' => 'native-lifecycle@example.invalid', 'phone' => '+12025550123' );
	foreach ( array( 'billing', 'shipping' ) as $type ) { foreach ( $address as $field => $value ) { $setter = 'set_' . $type . '_' . $field; if ( is_callable( array( WC()->customer, $setter ) ) ) { WC()->customer->$setter( $value ); } } }
	$product = new WC_Product_Simple(); $product->set_name( 'Synthetic lifecycle item ' . $pc_lifecycle_native['run'] ); $product->set_status( 'publish' ); $product->set_regular_price( '19.90' ); $product->set_manage_stock( true ); $product->set_stock_quantity( 10 ); $product->save();
	pc_lifecycle_native_check( false !== WC()->cart->add_to_cart( $product->get_id(), 1 ), 'real_native_cart_contains_owned_synthetic_product' );
	$drafts = new Pc_Lifecycle_Native_Draft(); $old = $drafts->next(); $old->set_status( 'pending' ); $old->set_payment_method( Gateway::ID ); $old->set_address( $address, 'billing' ); $old->set_address( $address, 'shipping' ); $old->set_cart_hash( WC()->cart->get_cart_hash() ); $old->save();
	$pc_lifecycle_native['owned_orders'][] = $old->get_id();
	WC()->session->set( 'order_awaiting_payment', $old->get_id() );
	$gateway = new Gateway(); $result = $gateway->process_payment( $old->get_id() );
	pc_lifecycle_native_check( 'success' === ( $result['result'] ?? null ) && 1 === $pc_lifecycle_native['provider_calls'], 'actual_gateway_creates_old_hosted_session_with_preempted_provider_http' );
	pc_lifecycle_native_check( $drafts->current()->get_id() === $old->get_id(), 'official_draft_trait_reuses_pending_order_for_same_native_cart' );
	$old = new WC_Order( $old->get_id() ); $before = pc_lifecycle_native_snapshot( $old );
	$cart_hash = WC()->cart->get_cart_hash(); $cart_data = serialize( WC()->cart->get_cart() ); $native_customer = serialize( WC()->customer->get_data() );
	pc_lifecycle_native_check( is_checkout() && ! is_admin(), 'native_wordpress_query_is_normal_checkout_navigation' );
	Pending_Orders::register_hooks(); Checkout_Lifecycle::on_checkout_navigation();
	pc_lifecycle_native_check( null === WC()->session->get( 'store_api_draft_order' ) && null === WC()->session->get( 'order_awaiting_payment' ), 'native_session_detaches_only_old_order_pointers' );
	pc_lifecycle_native_check( $before === pc_lifecycle_native_snapshot( new WC_Order( $old->get_id() ) ), 'old_native_posts_meta_items_frozen_book_and_creation_row_unchanged' );
	pc_lifecycle_native_check( WC()->cart->get_cart_hash() === $cart_hash && serialize( WC()->cart->get_cart() ) === $cart_data && serialize( WC()->customer->get_data() ) === $native_customer, 'native_cart_and_customer_addresses_preserved' );
	$visible = Pending_Orders::visible_orders();
	pc_lifecycle_native_check( in_array( $old->get_id(), array_map( static fn( $o ) => $o->get_id(), $visible ), true ), 'same_guest_native_session_can_find_detached_unpaid_order' );
	pc_lifecycle_native_check( null !== Pending_Orders::continuation( new WC_Order( $old->get_id() ) ), 'unchanged_saved_native_order_has_key_bound_continue_payment_link' );
	pc_lifecycle_native_check( null === $drafts->current(), 'official_draft_trait_no_longer_reuses_detached_order' );
	$new = $drafts->next(); $pc_lifecycle_native['owned_orders'][] = $new->get_id();
	pc_lifecycle_native_check( $new->get_id() !== $old->get_id() && $new->has_status( 'checkout-draft' ), 'official_order_controller_creates_independent_native_checkout_draft' );
	$new->set_status( 'pending' ); $new->set_payment_method( Gateway::ID ); $new->set_address( array_merge( $address, array( 'address_1' => '456 Synthetic New Checkout Street' ) ), 'billing' ); $new->set_address( array_merge( $address, array( 'address_1' => '456 Synthetic New Checkout Street' ) ), 'shipping' ); $new->set_cart_hash( WC()->cart->get_cart_hash() ); $new->save();
	WC()->session->set( 'order_awaiting_payment', $new->get_id() );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$new_result = $gateway->process_payment( $new->get_id() );
	pc_lifecycle_native_check( 'success' === ( $new_result['result'] ?? null ) && 2 === $pc_lifecycle_native['provider_calls'], 'new_native_order_creates_its_own_hosted_payment_after_address_change' );
	$new_before = pc_lifecycle_native_snapshot( new WC_Order( $new->get_id() ) ); $calls = $pc_lifecycle_native['provider_calls'];
	Checkout_Lifecycle::on_checkout_navigation(); $again = $gateway->process_payment( $new->get_id() );
	pc_lifecycle_native_check( $new->get_id() === (int) WC()->session->get( 'store_api_draft_order' ) && $calls === $pc_lifecycle_native['provider_calls'] && 'success' === $again['result'], 'same_post_retry_keeps_native_order_and_never_creates_second_provider_session' );
	pc_lifecycle_native_check( $new_before === pc_lifecycle_native_snapshot( new WC_Order( $new->get_id() ) ), 'duplicate_post_preserves_native_book_and_creation_fence' );
	pc_lifecycle_native_check( $before === pc_lifecycle_native_snapshot( new WC_Order( $old->get_id() ) ), 'new_order_payment_does_not_rewrite_old_order_or_attempt' );
	$own_session = WC()->session; WC()->session = pc_lifecycle_native_session();
	pc_lifecycle_native_check( $own_session->get_customer_id() !== WC()->session->get_customer_id() && array() === Pending_Orders::visible_orders(), 'another_real_guest_session_cannot_discover_old_order' );
	WC()->session->set( 'pc_panapay_pending_orders', array( array( 'id' => $old->get_id(), 'key' => 'synthetic-wrong-key', 'at' => time() ) ) );
	pc_lifecycle_native_check( array() === Pending_Orders::visible_orders(), 'wrong_order_key_in_another_native_session_is_rejected' );
	WC()->session = $own_session;
	$request = pc_lifecycle_native_request( $old->get_id() ); $transaction = 'synthetic-lifecycle-old-' . $pc_lifecycle_native['run'];
	$event = array( 'account_id' => 'default', 'event_id' => hash( 'sha256', $transaction ), 'order_number' => $request['order_id'], 'status' => 'SUCCEEDED', 'transaction_id' => $transaction );
	$event['sign'] = Signature::generate( array_intersect_key( $event, array_flip( array( 'order_number', 'status', 'transaction_id' ) ) ), Hub_Protocol::event_key( $connector_key ) );
	$event_body = Hub_Protocol::json( $event ); $nonce = bin2hex( random_bytes( 16 ) ); $time = time();
	$notification = new WP_REST_Request( 'POST', Hub_Protocol::EVENT ); $notification->set_header( 'Content-Type', 'application/json' ); $notification->set_body( $event_body );
	$notification->set_header( 'X-PC-Pay-Store', $connector_uuid ); $notification->set_header( 'X-PC-Pay-Time', (string) $time ); $notification->set_header( 'X-PC-Pay-Nonce', $nonce ); $notification->set_header( 'X-PC-Pay-Sign', Hub_Protocol::signature( $connector_key, 'POST', Hub_Protocol::EVENT, $time, $nonce, $event_body ) );
	$notified = Bridge::receive( $notification );
	pc_lifecycle_native_check( $notified instanceof WP_REST_Response && true === ( $notified->get_data()['data']->accepted ?? false ), 'original_native_signed_event_accepted_after_new_order_exists' );
	pc_lifecycle_native_check( ( new WC_Order( $old->get_id() ) )->is_paid() && ! ( new WC_Order( $new->get_id() ) )->is_paid(), 'original_notification_pays_only_original_order' );
	pc_lifecycle_native_check( 1 === ( $pc_lifecycle_native['complete_calls'][ $old->get_id() ] ?? 0 ) && 0 === ( $pc_lifecycle_native['complete_calls'][ $new->get_id() ] ?? 0 ), 'native_completion_called_once_for_original_order_only' );
	pc_lifecycle_native_check( 2 === $pc_lifecycle_native['provider_calls'] && 2 === $pc_lifecycle_native['central_create_calls'], 'all_lifecycle_navigation_and_notification_steps_add_no_provider_creation' );
	$report = array( 'complete' => true, 'nativeDatastore' => WC_Data_Store::load( 'order' )->get_current_class_name(), 'assertions' => $pc_lifecycle_native['checks'],
		'providerTransport' => 'fully preempted synthetic WordPress HTTP fixture', 'centralTransport' => 'synthetic signed response fixture', 'providerCalls' => $pc_lifecycle_native['provider_calls'], 'actualProviderOrSettlementTested' => false,
		'HTTPServerStarted' => false, 'productionAccessed' => false, 'ownedSyntheticOrdersCreated' => count( $pc_lifecycle_native['owned_orders'] ), 'otherHTTPBlocked' => $pc_lifecycle_native['blocked_other_http'] );
} finally {
	foreach ( array( 'peptide_core_hq' => $saved['connector'], 'woocommerce_checkout_page_id' => $saved['checkout_page'], 'woocommerce_currency' => $saved['currency'] ) as $name => $value ) {
		if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value ); }
	}
	wp_set_current_user( $saved['user'] ); WC()->session = $saved['session']; WC()->cart = $saved['cart']; WC()->customer = $saved['customer'];
	$_COOKIE = $saved['cookies']; $_GET = $saved['get']; $GLOBALS['wp_query'] = $saved['wp_query']; $GLOBALS['wp']->query_vars = $saved['query_vars']; $GLOBALS['post'] = $saved['post'];
	if ( null === $saved['request_method'] ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $saved['request_method']; }
	remove_filter( 'pre_http_request', $mock, -1000 );
	// Keep the final no-external-HTTP guard active through native shutdown hooks.
}
$restored = true;
foreach ( array( 'peptide_core_hq' => $saved['connector'], 'woocommerce_checkout_page_id' => $saved['checkout_page'], 'woocommerce_currency' => $saved['currency'] ) as $name => $value ) {
	$restored = $restored && serialize( get_option( $name, null ) ) === serialize( $value );
}
pc_lifecycle_native_check( $restored, 'qa_connector_currency_and_checkout_options_restored_exactly' );
pc_lifecycle_native_check( WC()->session === $saved['session'] && WC()->cart === $saved['cart'] && WC()->customer === $saved['customer'], 'qa_original_native_session_cart_and_customer_objects_restored' );
$report['assertions'] = $pc_lifecycle_native['checks']; $report['temporaryOptionsRestored'] = true;
echo wp_json_encode( $report ) . "\n";
