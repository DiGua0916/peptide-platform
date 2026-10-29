<?php
/**
 * 物流单号追踪（SHIP-TRACK-01）与客服按钮（SUPPORT-CHAT-01）冒烟测试。开发环境专用，需先运行
 * wp peptide demo seed 和 wp peptide shipping enable。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-shipping-support.php
 * 会临时创建测试顾客、测试商品和测试订单，并临时修改插件设置；结束时全部删除并恢复。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

// 测试中创建的订单和发货记录不发送通知邮件。
add_filter( 'pre_wp_mail', '__return_false' );

use Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment;
use PeptideCore\Catalog;
use PeptideCore\Launch_Check;
use PeptideCore\Meta;
use PeptideCore\Order_Batches;
use PeptideCore\Settings;
use PeptideCore\Shipping_Tracking;
use PeptideCore\Support_Contacts;
use PeptideCore\Workbench;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$original = get_option( Settings::OPTION, null );

// ---------- 客服按钮 ----------

$form  = array(
	'brand_name'       => 'VialCove',
	'support_enabled'  => '1',
	'support_whatsapp' => '+1 (555) 123-4567',
	'support_telegram' => 'https://t.me/vialcove_support/',
	'support_email'    => ' Support@Example.com ',
	'support_message'  => str_repeat( 'a', 400 ),
	'support_position' => 'middle',
);
$clean = Settings::sanitize( $form );
$sup   = $clean['support'];
$ok( 'whatsapp keeps digits only', '15551234567' === $sup['whatsapp'] );
$ok( 'telegram link reduced to username', 'vialcove_support' === $sup['telegram'] );
$ok( 'email cleaned', 'Support@Example.com' === $sup['email'] );
$ok( 'greeting truncated to 300 chars', 300 === strlen( $sup['message'] ) );
$ok( 'invalid position falls back to right', 'right' === $sup['position'] );
$ok( 'enabled with valid channels', true === $sup['enabled'] );
$ok( 'sanitize is idempotent (first save)', Settings::sanitize( $clean ) === $clean );

$bad = Settings::sanitize(
	array(
		'support_enabled'  => '1',
		'support_whatsapp' => '12345',
		'support_telegram' => '@ab',
		'support_email'    => 'not-an-email',
	)
)['support'];
$ok( 'invalid contacts dropped', '' === $bad['whatsapp'] && '' === $bad['telegram'] && '' === $bad['email'] );
$ok( 'cannot enable without a valid channel', false === $bad['enabled'] );

update_option( Settings::OPTION, Settings::sanitize( array_merge( $form, array( 'support_message' => 'Hi VialCove & team?' ) ) ) );
$site = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/site' ) )->get_data();
$ch   = $site['support']['channels'] ?? array();
$ok( '/site support enabled with 3 channels in order', true === $site['support']['enabled'] && array( 'whatsapp', 'telegram', 'email' ) === array_column( $ch, 'type' ) );
$ok( 'whatsapp url has encoded greeting', 'https://wa.me/15551234567?text=Hi%20VialCove%20%26%20team%3F' === $ch[0]['url'] && '+15551234567' === $ch[0]['value'] );
$ok( 'telegram and email urls', 'https://t.me/vialcove_support' === $ch[1]['url'] && 'mailto:Support@Example.com' === $ch[2]['url'] );
$ok( 'theme helper matches /site', peptide_core_get_support_contacts() === $site['support'] );

update_option( Settings::OPTION, Settings::sanitize( array_merge( $form, array( 'support_enabled' => '' ) ) ) );
$off = Support_Contacts::public_view();
$ok( 'disabled button exposes no channels', false === $off['enabled'] && array() === $off['channels'] );

// ---------- 物流单号追踪 ----------

$ok( 'native fulfillments enabled', Shipping_Tracking::native_enabled() );
update_option( Settings::OPTION, Settings::sanitize( array( 'brand_name' => 'VialCove', 'require_batch_to_ship' => '1' ) ) );

$a1        = Catalog::public_batch_id_by_no( 'DEMO-A-2401' );
$product_a = wc_get_product( (int) get_post_meta( $a1, Meta::BATCH_PRODUCT_ID, true ) );
$supply    = new WC_Product_Simple();
$supply->set_name( 'PC SMOKE supply (no batches)' );
$supply->set_regular_price( '5' );
$supply->set_status( 'private' );
$supply->save();

$customer = wp_insert_user(
	array(
		'user_login' => 'pc_smoke_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'customer',
	)
);
$order = wc_create_order( array( 'customer_id' => $customer ) );
$order->add_product( $product_a, 2 );
$order->add_product( $supply, 1 );
$order->set_status( 'processing' );
$order->save();
$ids       = array_keys( $order->get_items() );
$line_a    = $ids[0];
$line_sup  = $ids[1];

$ship = static function ( array $items, string $status = 'fulfilled', ?Fulfillment $f = null ) use ( &$order ) {
	$f = $f ?? new Fulfillment();
	$f->set_entity_type( WC_Order::class );
	$f->set_entity_id( (string) $order->get_id() );
	$f->set_status( $status );
	$f->set_items( $items );
	$f->set_tracking_number( '1Z999AA10123456784' );
	$f->set_shipment_provider( 'ups' );
	$f->set_tracking_url( 'https://www.ups.com/track?tracknum=1Z999AA10123456784' );
	try {
		$f->save();
		return $f;
	} catch ( \Throwable $e ) {
		return $e->getMessage();
	}
};

$r = $ship( array( array( 'item_id' => $line_a, 'qty' => 2 ) ) );
$ok( 'shipping a batch product without batch is blocked', is_string( $r ) && str_contains( $r, $product_a->get_name() ) );

$draft = $ship( array( array( 'item_id' => $line_a, 'qty' => 2 ) ), 'unfulfilled' );
$ok( 'unshipped draft is allowed without batch', $draft instanceof Fulfillment );

$r = $ship( array( array( 'item_id' => $line_a, 'qty' => 2 ) ), 'fulfilled', $draft );
$ok( 'turning the draft into shipped is blocked', is_string( $r ) );

$r = $ship( array( array( 'item_id' => $line_sup, 'qty' => 1 ) ) );
$ok( 'product without any batch can ship', $r instanceof Fulfillment );

$order = wc_get_order( $order->get_id() );
$wb    = Workbench::orders_awaiting_shipment();
$row   = current( array_filter( $wb['items'], static fn( $o ) => $o['id'] === $order->get_id() ) );
$ok( 'workbench lists partially shipped order', $row && true === $row['partial'] );

// 指定批次后可以发货。
$item = $order->get_item( $line_a );
$item->update_meta_data( Order_Batches::ITEM_META, $a1 );
$item->save();
$order = wc_get_order( $order->get_id() );
$draft = \WC_Data_Store::load( 'order-fulfillment' )->read_fulfillments( WC_Order::class, (string) $order->get_id() );
$draft = current( array_filter( $draft, static fn( $f ) => ! $f->get_is_fulfilled() ) );
$r     = $ship( array( array( 'item_id' => $line_a, 'qty' => 2 ) ), 'fulfilled', $draft );
$ok( 'ships after the batch is assigned', $r instanceof Fulfillment );

$order = wc_get_order( $order->get_id() );
$view  = Shipping_Tracking::customer_view( $order );
$ok( 'order fulfillment status is fulfilled', 'fulfilled' === $view['status'] );
$ok( 'two shipments for the customer', 2 === count( $view['shipments'] ) );
$s = $view['shipments'][0];
$ok( 'shipment has provider, number, url, date', 'ups' === $s['provider'] && 'UPS' === $s['provider_name'] && '1Z999AA10123456784' === $s['tracking_number'] && str_starts_with( (string) $s['tracking_url'], 'https://www.ups.com/' ) && 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', (string) $s['shipped_at'] ) );

// WooCommerce stores fulfillment dates in UTC. A non-UTC shop timezone must not shift that instant.
// Only change the fulfillment created above for this smoke order, then restore it and the shop timezone.
$timezone_before_utc_test = get_option( 'timezone_string', null );
$date_before_utc_test     = $r instanceof Fulfillment ? $r->get_date_fulfilled() : null;
try {
	update_option( 'timezone_string', 'America/Los_Angeles' );
	if ( $r instanceof Fulfillment ) {
		$r->set_date_fulfilled( '2026-01-15T12:34:56+00:00' );
		$r->save();
		$utc_fixture = new Fulfillment( $r->get_id() );
		$utc_view    = Shipping_Tracking::customer_view( wc_get_order( $order->get_id() ) );
		$ok( 'fixed UTC shipment time stays exact in a non-UTC shop timezone', '2026-01-15 12:34:56' === $utc_fixture->get_date_fulfilled() && in_array( '2026-01-15T12:34:56+00:00', array_column( $utc_view['shipments'], 'shipped_at' ), true ) );
	} else {
		$ok( 'fixed UTC shipment time stays exact in a non-UTC shop timezone', false );
	}
} finally {
	if ( null === $timezone_before_utc_test ) {
		delete_option( 'timezone_string' );
	} else {
		update_option( 'timezone_string', $timezone_before_utc_test );
	}
	if ( $r instanceof Fulfillment && null !== $date_before_utc_test ) {
		$r->set_date_fulfilled( $date_before_utc_test . ' UTC' );
		$r->save();
	}
}
$ok( 'UTC regression restores the shop timezone and its temporary shipment date', get_option( 'timezone_string', null ) === $timezone_before_utc_test && $r instanceof Fulfillment && ( new Fulfillment( $r->get_id() ) )->get_date_fulfilled() === $date_before_utc_test );

$wb = Workbench::orders_awaiting_shipment();
$ok( 'workbench no longer lists the shipped order', ! in_array( $order->get_id(), array_column( $wb['items'], 'id' ), true ) );

// 顾客接口。
wp_set_current_user( $customer );
$res = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/me/orders/' . $order->get_order_number() . '/batches' ) );
$data = $res->get_data();
$ok( '/me/orders includes fulfillment for the owner', 200 === $res->get_status() && 2 === count( $data['fulfillment']['shipments'] ?? array() ) );
wp_set_current_user( 0 );

// 关闭检查后，无批次也能发货。
update_option( Settings::OPTION, Settings::sanitize( array( 'brand_name' => 'VialCove' ) ) );
$order2 = wc_create_order( array( 'customer_id' => $customer ) );
$order2->add_product( $product_a, 1 );
$order2->set_status( 'processing' );
$order2->save();
$keep  = $order;
$order = $order2;
$r     = $ship( array( array( 'item_id' => array_key_first( $order2->get_items() ), 'qty' => 1 ) ) );
$ok( 'batch check can be turned off', $r instanceof Fulfillment );
$order = $keep;

// 上线体检。
$checks = array_column( Launch_Check::run(), 'level', 'check' );
$ok( 'launch check reports tracking and support', isset( $checks['Shipment tracking'], $checks['Support button'], $checks['Batch check before shipping'] ) );

// 功能关闭时接口返回 null。
update_option( Shipping_Tracking::FEATURE_OPTION, 'no' );
$ok( 'fulfillment is null when the feature is off', null === Order_Batches::customer_view( $order, $customer )['fulfillment'] );
update_option( Shipping_Tracking::FEATURE_OPTION, 'yes' );

// ---------- 清理 ----------
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( $order, $order2 ) as $o ) {
	foreach ( \WC_Data_Store::load( 'order-fulfillment' )->read_fulfillments( WC_Order::class, (string) $o->get_id() ) as $f ) {
		$f->delete();
	}
	$o->delete( true );
}
$supply->delete( true );
wp_delete_user( $customer );
if ( null === $original ) {
	delete_option( Settings::OPTION );
} else {
	update_option( Settings::OPTION, $original );
}
$ok( 'cleanup: settings restored', get_option( Settings::OPTION, null ) === $original );
