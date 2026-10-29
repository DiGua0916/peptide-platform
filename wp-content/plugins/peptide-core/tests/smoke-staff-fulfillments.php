<?php
/**
 * Staff order-module permissions on WooCommerce's actual fulfillment REST routes. Development only.
 *
 * Run: wp eval-file wp-content/plugins/peptide-core/tests/smoke-staff-fulfillments.php
 * Requires native fulfillments to be enabled. No demo seed or existing order/customer is used.
 * Creates its own users, private product, order and fulfillments; disables mail and removes only
 * those fixtures in finally. Does not modify feature options, shop settings or existing orders.
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment;
use PeptideCore\Shipping_Tracking;
use PeptideCore\Staff;

$passed = 0;
$failed = 0;
$ok     = static function ( string $label, bool $condition ) use ( &$passed, &$failed ): void {
	$condition ? ++$passed : ++$failed;
	echo ( $condition ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

// The Woo controller index is called order_fulfillments; its real route namespace is wc/v3.
$routes             = rest_get_server()->get_routes();
$collection_pattern = '/wc/v3/orders/(?P<order_id>[\d]+)/fulfillments';
$registered         = isset( $routes[ $collection_pattern ], $routes[ $collection_pattern . '/(?P<fulfillment_id>[\d]+)/metadata' ] );
$ok( 'WooCommerce registers fulfillment and metadata routes under wc/v3', $registered );
$ok( 'native fulfillments are enabled', Shipping_Tracking::native_enabled() );
if ( ! $registered || ! Shipping_Tracking::native_enabled() ) {
	echo "STOP no fixtures created; enable the existing fulfillment feature before running this test.\n";
	return;
}

$original_user = get_current_user_id();
$mail_added    = false === has_filter( 'pre_wp_mail', '__return_false' );
if ( $mail_added ) {
	add_filter( 'pre_wp_mail', '__return_false' );
}
$users   = array();
$product = null;
$order   = null;
$product_id = 0;
$order_id   = 0;
$store   = WC_Data_Store::load( 'order-fulfillment' );
$admin   = 0;

$make_user = static function ( string $role ) use ( &$users ): int {
	$id = wp_insert_user(
		array(
			'user_login' => 'pc_smoke_fulfill_' . strtolower( wp_generate_password( 12, false, false ) ),
			'user_pass'  => wp_generate_password( 32 ),
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Could not create fixture user: ' . $id->get_error_code() );
	}
	$users[] = (int) $id;
	return (int) $id;
};
$request = static function ( int $user, string $method, string $route, ?array $body = null ) {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $req );
};

try {
	$admin    = $make_user( 'administrator' );
	$customer = $make_user( 'customer' );
	$limited  = $make_user( Staff::ROLE );
	$operator = $make_user( Staff::ROLE );
	Staff::set_modules( $limited, array( 'settings' ) );
	Staff::set_modules( $operator, array( 'orders' ) );
	wp_set_current_user( $admin );

	$product = new WC_Product_Simple();
	$product->set_name( 'PC SMOKE fulfillment permission fixture' );
	$product->set_status( 'private' );
	$product->set_regular_price( '5' );
	$product->set_manage_stock( false );
	$product->save();
	$product_id = $product->get_id();
	$order = wc_create_order( array( 'customer_id' => $customer ) );
	if ( is_wp_error( $order ) ) {
		throw new RuntimeException( 'Could not create fixture order.' );
	}
	$order_id = $order->get_id();
	$item_id = $order->add_product( $product, 2 );
	$order->set_status( 'processing' );
	$order->save();
	$seed = new Fulfillment();
	$seed->set_entity_type( WC_Order::class );
	$seed->set_entity_id( (string) $order->get_id() );
	$seed->set_status( 'unfulfilled' );
	$seed->set_items( array( array( 'item_id' => $item_id, 'qty' => 1 ) ) );
	$seed->update_meta_data( '_pc_staff_smoke', 'keep' );
	$seed->save();
	$seed_id    = $seed->get_id();
	$collection = '/wc/v3/orders/' . $order->get_id() . '/fulfillments';
	$detail     = $collection . '/' . $seed_id;
	$items_meta = array( 'key' => '_items', 'value' => array( array( 'item_id' => $item_id, 'qty' => 1 ) ) );
	$write      = array(
		'status'          => 'unfulfilled',
		'is_fulfilled'    => false,
		'notify_customer' => false,
		'meta_data'       => array( $items_meta, array( 'key' => '_pc_staff_smoke', 'value' => 'changed' ) ),
	);
	$state = static function () use ( $store, $order ): string {
		$fulfillments = $store->read_fulfillments( WC_Order::class, (string) $order->get_id(), true );
		usort( $fulfillments, static fn( $a, $b ) => $a->get_id() <=> $b->get_id() );
		return hash( 'sha256', serialize( array( wc_get_order( $order->get_id() )->get_data(), array_map( static fn( $f ) => $f->get_raw_data(), $fulfillments ) ) ) );
	};

	wp_set_current_user( $limited );
	$ok( 'fixture staff has Woo management capability but no Orders module', current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) && ! in_array( 'orders', Staff::granted( $limited ), true ) );
	$denied = array(
		array( 'GET', $collection, null, 'read' ),
		array( 'POST', $collection, $write, 'create' ),
		array( 'PATCH', $detail, $write, 'update' ),
		array( 'PATCH', $detail . '/metadata', array( 'meta_data' => $write['meta_data'] ), 'update metadata' ),
		array( 'DELETE', $detail . '/metadata', array( 'meta_key' => '_pc_staff_smoke' ), 'delete metadata' ),
		array( 'DELETE', $detail, array( 'notify_customer' => false ), 'delete' ),
	);
	foreach ( $denied as [ $method, $route, $body, $label ] ) {
		$before = $state();
		$res    = $request( $limited, $method, $route, $body );
		$data   = $res->get_data();
		$ok( 'without Orders: ' . $label . ' is refused by the module guard (403)', 403 === $res->get_status() && 'pc_staff_orders_required' === ( $data['code'] ?? '' ) );
		$ok( 'refused ' . $label . ' leaves the order and all fulfillment rows unchanged', $before === $state() );
	}

	$prior_error    = new WP_Error( 'pc_smoke_existing_error', 'fixture', array( 'status' => 409 ) );
	$prior_response = new WP_REST_Response( array( 'fixture' => true ), 202 );
	$guard_request  = new WP_REST_Request( 'PATCH', $detail );
	$ok( 'existing errors and responses are preserved', $prior_error === Staff::guard_fulfillment_rest( $prior_error, array(), $guard_request ) && $prior_response === Staff::guard_fulfillment_rest( $prior_response, array(), $guard_request ) && false === Staff::guard_fulfillment_rest( false, array(), $guard_request ) );
	$ok( 'other Woo order routes are not intercepted', null === Staff::guard_fulfillment_rest( null, array(), new WP_REST_Request( 'PATCH', '/wc/v3/orders/' . $order->get_id() ) ) );

	$res     = $request( $operator, 'POST', $collection, $write );
	$data    = $res->get_data();
	$created = (int) ( $data['id'] ?? 0 );
	$ok( 'Orders staff can create through the real REST route (201)', 201 === $res->get_status() && $created > 0 );
	if ( 201 !== $res->get_status() || ! $created ) {
		throw new RuntimeException( 'Authorized REST create failed: ' . $res->get_status() . ' ' . ( $data['code'] ?? '' ) );
	}
	$created_route = $collection . '/' . $created;
	$updated_write = $write;
	$updated_write['meta_data'][] = array( 'key' => '_tracking_number', 'value' => 'PC-SMOKE-ORDERS' );
	$res = $request( $operator, 'PATCH', $created_route, $updated_write );
	$ok( 'Orders staff can update and persist fulfillment fields (200)', 200 === $res->get_status() && 'PC-SMOKE-ORDERS' === ( new Fulfillment( $created ) )->get_tracking_number() );
	$res = $request( $operator, 'PATCH', $created_route . '/metadata', array( 'meta_data' => array( $items_meta, array( 'key' => '_pc_staff_smoke', 'value' => 'orders-metadata' ) ) ) );
	$ok( 'Orders staff can update metadata (200)', 200 === $res->get_status() && 'orders-metadata' === ( new Fulfillment( $created ) )->get_meta( '_pc_staff_smoke' ) );
	$res = $request( $operator, 'DELETE', $created_route . '/metadata', array( 'meta_key' => '_pc_staff_smoke' ) );
	$ok( 'Orders staff can delete metadata (200)', 200 === $res->get_status() && ! ( new Fulfillment( $created ) )->meta_exists( '_pc_staff_smoke' ) );
	$res = $request( $operator, 'DELETE', $created_route, array( 'notify_customer' => false ) );
	$ok( 'Orders staff can delete a fulfillment (200)', 200 === $res->get_status() && null !== ( new Fulfillment( $created ) )->get_date_deleted() );

	$admin_write = $write;
	$admin_write['meta_data'][] = array( 'key' => '_tracking_number', 'value' => 'PC-SMOKE-ADMIN' );
	$res = $request( $admin, 'PATCH', $detail, $admin_write );
	$ok( 'administrator permissions remain unchanged (200)', 200 === $res->get_status() && 'PC-SMOKE-ADMIN' === ( new Fulfillment( $seed_id ) )->get_tracking_number() );
	$res = $request( $customer, 'GET', $detail );
	$ok( 'native order-owner customer can still read their fulfillment (200)', 200 === $res->get_status() );
	foreach ( array( array( 'POST', $collection, $write ), array( 'PATCH', $detail, $write ), array( 'DELETE', $detail, array( 'notify_customer' => false ) ) ) as [ $method, $route, $body ] ) {
		$before = $state();
		$res    = $request( $customer, $method, $route, $body );
		$data   = $res->get_data();
		$ok( 'native customer ' . $method . ' remains denied without changing data', 403 === $res->get_status() && 'pc_staff_orders_required' !== ( $data['code'] ?? '' ) && $before === $state() );
	}
	Staff::set_modules( $operator, array( 'settings' ) );
	$res = $request( $operator, 'GET', $collection );
	$ok( 'revoking Orders takes effect immediately (403)', 403 === $res->get_status() && 'pc_staff_orders_required' === ( $res->get_data()['code'] ?? '' ) );
} catch ( Throwable $e ) {
	$ok( 'REST fixture run completed: ' . $e->getMessage(), false );
} finally {
	wp_set_current_user( $admin ?: $original_user );
	try {
		if ( $order instanceof WC_Order ) {
			// This ID was created in this run; include soft-deleted fulfillments so no fixture rows remain.
			$store->delete_by_entity( WC_Order::class, (string) $order_id );
			$order->delete( true );
		}
		if ( $product instanceof WC_Product ) {
			$product->delete( true );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( array_reverse( $users ) as $user_id ) {
			wp_delete_user( $user_id );
		}
	} finally {
		wp_set_current_user( $original_user );
		Staff::flush_cache();
		if ( $mail_added ) {
			remove_filter( 'pre_wp_mail', '__return_false' );
		}
	}
}
$clean = ( ! $order_id || ( ! wc_get_order( $order_id ) && ! $store->read_fulfillments( WC_Order::class, (string) $order_id, true ) ) )
	&& ( ! $product_id || ! wc_get_product( $product_id ) );
foreach ( $users as $user_id ) {
	$clean = $clean && ! get_userdata( $user_id );
}
$ok( 'only this run\'s product, order, fulfillment rows and users were removed; current user restored', $clean && get_current_user_id() === $original_user );
echo 'SUMMARY ', $passed, ' PASS, ', $failed, " FAIL\n";
