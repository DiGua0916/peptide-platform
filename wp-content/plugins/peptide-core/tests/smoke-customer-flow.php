<?php
/** Local disposable verification only. Unit fixtures exercise real handlers; HTTP proof uses native public Store API, not tracking injection. */
defined( 'ABSPATH' ) || exit;

use PeptideCore\Tracking\Tracker;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Ingest;
use PeptideCore\Tracking\Server_Events;
use PeptideCore\Tracking\Privacy;
use PeptideCore\Tracking\Checkout_Steps as Capture;
use PeptideCore\Data\Checkout_Steps as Report;
use PeptideCore\Data\Conversion;
use PeptideCore\Data\Range;
use PeptideCore\Payments\Local_Payments;
use PeptideCore\Payments\Local_Gateway;
use Automattic\WooCommerce\StoreApi\Payments\PaymentContext;

if ( wp_get_environment_type() !== 'local' || DB_NAME !== 'peptide_sync_20261029' ) { throw new RuntimeException( 'Run only in the isolated local verification database.' ); }
global $wpdb;
$checks = 0;
$ok = static function ( string $name, bool $condition ) use ( &$checks ) {
	++$checks; echo ( $condition ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	if ( ! $condition ) { throw new RuntimeException( $name ); }
};
$original_cookie = $_COOKIE; $original_get = $_GET; $original_server = $_SERVER; $original_user = get_current_user_id();
$keys = array( Tracker::OPTION, Local_Payments::OPTION, Schema::OPTION, 'peptide_core_settings', 'peptide_core_shipping_plans', 'peptide_core_shipping_rules', 'cron' );
$options = array(); foreach ( $keys as $key ) { $options[$key] = get_option( $key, null ); }
$orders = array(); $visitors = array(); $sessions = array(); $refunds = array(); $product = null;
$fresh_order = static function ( int $id ) {
	// The HTTP worker updates another process; invalidate only this fixture's local Woo caches before reading.
	wc_get_container()->get( Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id );
	wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class )->clear_cached_data( array( $id ) );
	clean_post_cache( $id ); return wc_get_order( $id );
};
$fresh_stock = static function ( int $id ): int { clean_post_cache( $id ); return (int) wc_get_product( $id )->get_stock_quantity(); };
$context = static function ( WC_Order $order, string $method = 'pc_local_card' ): PaymentContext { $c = new PaymentContext(); $c->set_order( $order ); $c->set_payment_method( $method ); return $c; };
$count = static fn( string $type, int $order ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . ' WHERE type=%s AND order_id=%d', $type, $order ) );
$make_order = static function ( string $method = 'pc_local_card', float $total = 40 ) use ( &$orders ): WC_Order {
	$o = wc_create_order(); $o->set_total( $total ); $o->set_payment_method( $method ); $o->set_payment_method_title( Local_Payments::title( $method ) ); $o->set_status( 'pending' ); $o->save(); $orders[] = $o->get_id(); return $o;
};
$unit_visitor = static function ( int $at ) use ( &$visitors ): array {
	$_COOKIE[Tracker::COOKIE] = bin2hex( random_bytes( 16 ) ); $v = Tracker::visitor( Tracker::current_vid(), $at ); $visitors[] = $v['id']; return $v;
};
$client = static function ( array $events, int $at ) use ( &$visitors ): array {
	$page = wp_generate_uuid4(); $list = array();
	foreach ( $events as $index => $entry ) { $list[] = array( 'event_id' => wp_generate_uuid4(), 'page_instance_id' => $page, 'seq' => $index + 1, 'type' => $entry[0], 'occurred_at' => $at, 'page' => array( 'page_type' => 'checkout', 'path' => '/owned-flow-smoke/' ), 'data' => $entry[1] ?? array() ); }
	$out = Ingest::handle( array( 'protocol' => 1, 'events' => $list ), $at );
	if ( is_wp_error( $out ) ) { throw new RuntimeException( 'Unit ingest failed: ' . $out->get_error_code() ); }
	if ( $out['visitor_id'] ) { $visitors[] = $out['visitor_id']; }
	return $out;
};

try {
	wp_set_current_user( 0 ); $_GET = array(); unset( $_SERVER['HTTP_SEC_GPC'], $_COOKIE[PeptideCore\Pixels::OPTOUT] );
	Tracker::set_enabled( true ); Tracker::save_settings( 180, true ); Tracker::register_hooks();
	$ok( 'schema remains version3', Schema::DB_VERSION === '3' && Schema::installed() );
	$ok( 'eligible live protocol1 anonymous capture', Capture::eligible() );
	$bad_protocol = static fn( $config ) => array_merge( (array) $config, array( 'protocol' => 2 ) );
	add_filter( 'vialcove_visitor_observer_config', $bad_protocol, 99 ); $ok( 'wrong protocol disables bridge', ! Capture::eligible() ); remove_filter( 'vialcove_visitor_observer_config', $bad_protocol, 99 );
	Tracker::set_enabled( false ); $ok( 'tracking disabled disables bridge', ! Capture::eligible() ); Tracker::set_enabled( true );
	$_SERVER['HTTP_SEC_GPC'] = '1'; $ok( 'GPC disables bridge', ! Capture::eligible() ); unset( $_SERVER['HTTP_SEC_GPC'] );
	$_COOKIE[PeptideCore\Pixels::OPTOUT] = '1'; $ok( 'optout disables bridge', ! Capture::eligible() ); unset( $_COOKIE[PeptideCore\Pixels::OPTOUT] );
	foreach ( array( 'pc_frame', 'preview', 'pc_preview_token' ) as $key ) { $_GET[$key] = ''; $ok( 'preview flag disables bridge: ' . $key, ! Capture::eligible() ); unset( $_GET[$key] ); }
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]; wp_set_current_user( $admin->ID ); $ok( 'staff disables bridge', ! Capture::eligible() ); wp_set_current_user( 0 );
	$ok( 'payment attempt native hook runs priority1', has_action( 'woocommerce_rest_checkout_process_payment_with_context', array( Capture::class, 'payment_attempt' ) ) === 1 );
	$ok( 'installed Woo dependency handle exists', wp_script_is( 'wc-blocks-data-store', 'registered' ) );
	$ok( 'address payload strips PII and unsupported source', Ingest::clean_data( 'checkout_address_completed', array( 'source' => 'native_blocks', 'email' => 'private@example.invalid', 'address' => array( 'secret' ) ) ) === array( 'source' => 'native_blocks' ) && Ingest::clean_data( 'checkout_address_completed', array( 'source' => 'fake' ) ) === array() );
	$ok( 'applicability only accepts actual booleans', Ingest::clean_data( 'checkout_step_applicability', array( 'address_required' => false, 'shipping_required' => true, 'postcode' => 'SECRET' ) ) === array( 'address_required' => false, 'shipping_required' => true ) && Ingest::clean_data( 'checkout_step_applicability', array( 'address_required' => 'false', 'shipping_required' => 1 ) ) === array() );
	$ok( 'selection keeps only bounded method ID and known origin', Ingest::clean_data( 'checkout_payment_selected', array( 'method' => 'pc_local_card', 'origin' => 'user', 'payment_data' => array( 'SECRET' ), 'token' => 'SECRET' ) ) === array( 'method' => 'pc_local_card', 'origin' => 'user' ) );
	$ok( 'hostile method and origin cannot store address/card data', Ingest::clean_data( 'checkout_payment_selected', array( 'method' => 'private@example.invalid', 'origin' => 'SECRET' ) ) === array() );
	$now = Tracker::now_ms(); $v = $unit_visitor( $now );
	$ingested = $client( array( array( 'checkout_address_completed', array( 'source' => 'native_blocks' ) ), array( 'checkout_payment_selected', array( 'method' => 'pc_local_card', 'origin' => 'initial' ) ), array( 'checkout_step_applicability', array( 'address_required' => true, 'shipping_required' => false ) ) ), $now );
	$ok( 'three new observation types ingest through real whitelist', count( $ingested['ack'] ) === 3 && ! $ingested['rejected'] );
	$ok( 'new checkout signals do not advance existing stage', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT stage FROM ' . Schema::table( 'visits' ) . ' WHERE id=%d', $ingested['visit_id'] ) ) );
	$spoof = $client( array( array( 'payment_success' ), array( 'order_placed' ), array( 'payment_attempt' ), array( 'add_to_cart' ) ), $now );
	$ok( 'client cannot assert any server business fact', ! $spoof['ack'] && count( $spoof['rejected'] ) === 4 );

	$order = $make_order(); Server_Events::on_order_placed( $order );
	$link = Server_Events::order_link( $order );
	$ok( 'first native placement links original browser and visit', $link === array( $v['id'], $ingested['visit_id'] ) );
	$private = $make_order(); $private->update_meta_data( Server_Events::ORDER_VISITOR_META, $link[0] ); $private->update_meta_data( Server_Events::ORDER_VISIT_META, $link[1] ); $private->save_meta_data();
	wp_set_current_user( $admin->ID ); Server_Events::on_order_placed( $private );
	$ok( 'prelinked retry preserves current staff exclusion', $count( 'order_placed', $private->get_id() ) === 0 ); wp_set_current_user( 0 );
	$_SERVER['HTTP_SEC_GPC'] = '1'; Server_Events::on_order_placed( $private );
	$ok( 'prelinked retry preserves current browser privacy exclusion', $count( 'order_placed', $private->get_id() ) === 0 ); unset( $_SERVER['HTTP_SEC_GPC'] );
	$later = Tracker::visit( $v['id'], $now + Tracker::VISIT_GAP_MS + 1000, '/another-landing/' ); Server_Events::on_order_placed( $order );
	$ok( 'same visitor retry in a later visit keeps original attribution', $later !== $link[1] && Server_Events::order_link( $order ) === $link );
	$other = $unit_visitor( $now ); Server_Events::on_order_placed( $order );
	$ok( 'different visitor retry never reassigns original attribution', Server_Events::order_link( $order ) === $link );
	$ok( 'repeated placement still records one deterministic fact', $count( 'order_placed', $order->get_id() ) === 1 );
	$c = $context( $order ); $before_context = serialize( $c ); Capture::payment_attempt( $c ); Capture::payment_attempt( $c );
	$ok( 'same native context deduplicates within request', $count( 'payment_attempt', $order->get_id() ) === 1 );
	$ok( 'passive listener never mutates context or order business status', serialize( $c ) === $before_context && $order->has_status( 'pending' ) );
	Capture::payment_attempt( $context( $order ) );
	$ok( 'new native processing invocation remains a retry attempt', $count( 'payment_attempt', $order->get_id() ) === 2 );
	$ok( 'payment attempts remain attached to original visit', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . " WHERE type='payment_attempt' AND order_id=%d AND visit_id=%d", $order->get_id(), $link[1] ) ) === 2 );
	unset( $_COOKIE[Tracker::COOKIE] ); $order->payment_complete(); Server_Events::on_payment_complete( $order->get_id() ); Server_Events::on_order_placed( $order );
	$ok( 'cookie-free native completion links original visit exactly once', $count( 'payment_success', $order->get_id() ) === 1 && Server_Events::order_link( $order ) === $link );
	$ok( 'paid deterministic UID is preserved', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT event_uid FROM ' . Schema::table( 'events' ) . " WHERE type='payment_success' AND order_id=%d", $order->get_id() ) ) === 'srv-order-' . $order->get_id() . '-paid' );
	$ok( 'native success alone advances existing stage to5', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT stage FROM ' . Schema::table( 'visits' ) . ' WHERE id=%d', $link[1] ) ) === 5 );
	$zero = $make_order( 'pc_local_card', 0 ); Capture::payment_attempt( $context( $zero ) ); $ok( 'zero-total no-payment branch records no attempt', $count( 'payment_attempt', $zero->get_id() ) === 0 );
	$unlinked = $make_order(); Capture::payment_attempt( $context( $unlinked ) ); $ok( 'missing association does not fabricate a visitor or attempt', $count( 'payment_attempt', $unlinked->get_id() ) === 0 );
	$unit_visitor( $now ); $partial = $make_order(); $partial->update_meta_data( Server_Events::ORDER_VISITOR_META, $other['id'] ); $partial->save_meta_data(); Server_Events::on_order_placed( $partial );
	$ok( 'partial original association never adopts current browser', ! $partial->meta_exists( Server_Events::ORDER_VISIT_META ) && $count( 'order_placed', $partial->get_id() ) === 0 );
	$deleted_v = $unit_visitor( $now ); $deleted = $make_order(); Server_Events::on_order_placed( $deleted ); Privacy::delete_visitors( array( $deleted_v['id'] ) ); $unit_visitor( $now ); Server_Events::on_order_placed( $deleted ); $deleted->payment_complete();
	$ok( 'deleted attribution stays deleted and cookie-free facts do not recreate it', Server_Events::order_link( $deleted ) === array( 0, 0 ) && $count( 'payment_success', $deleted->get_id() ) === 0 && (int) $deleted->get_meta( Server_Events::ORDER_VISITOR_META ) === $deleted_v['id'] );

	delete_option( Local_Payments::OPTION ); $ok( 'local provider defaults off', ! Local_Payments::allowed() );
	foreach ( array( true, '1', 'no', 'YES', 'development' ) as $value ) { update_option( Local_Payments::OPTION, $value ); $ok( 'only literal yes enables local mode: ' . var_export( $value, true ), ! Local_Payments::allowed() ); }
	$gateway = new Local_Gateway(); $ok( 'direct gateway call rejects optout and cannot tag/pay order', $gateway->process_payment( $unlinked->get_id() )['result'] === 'failure' && $unlinked->get_meta( '_pc_payment_simulated' ) === '' );
	$off_map = array( 'cod' => 'existing', 'bacs' => 'existing' ); $ok( 'off mode preserves existing gateway list', Local_Payments::available( $off_map ) === $off_map );
	update_option( Local_Payments::OPTION, 'yes' ); $ok( 'strict local plus explicit yes enables providers', Local_Payments::allowed() );
	$enabled = Local_Payments::gateways( array() ); $ok( 'two distinct native WC gateway instances and IDs', count( $enabled ) === 2 && $enabled[0] instanceof WC_Payment_Gateway && $enabled[1] instanceof WC_Payment_Gateway && $enabled[0]->id !== $enabled[1]->id );
	$ok( 'enabled PHP availability hides real providers', array_keys( Local_Payments::available( array( 'cod' => 'existing', 'pc_local_card' => $enabled[0], 'pc_local_wallet' => $enabled[1] ) ) ) === Local_Payments::IDS );
	$registry = new Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry(); $registry->initialize();
	$ok( 'native Blocks registry contains only two local providers', array_keys( $registry->get_all_registered() ) === Local_Payments::IDS );
	foreach ( $registry->get_all_registered() as $method ) {
		$ok( 'native Blocks integration active: ' . $method->get_name(), $method->is_active() && $method->get_payment_method_data()['enabled'] === true && $method->get_payment_method_script_handles() === array( 'peptide-core-local-payments' ) );
	}
	foreach ( array( 'production', 'development' ) as $environment ) {
		$code = 'define("WP_ENVIRONMENT_TYPE",' . var_export( $environment, true ) . ');define("WP_USE_THEMES",false);require ' . var_export( ABSPATH . 'wp-load.php', true ) . ';$g=new PeptideCore\\Payments\\Local_Gateway();$b=new PeptideCore\\Payments\\Local_Blocks("pc_local_card");echo "\nPC_BOOT:".json_encode(array("environment"=>wp_get_environment_type(),"persisted"=>get_option("peptide_core_local_payments"),"allowed"=>PeptideCore\\Payments\\Local_Payments::allowed(),"ids"=>array_keys(WC()->payment_gateways()->payment_gateways()),"result"=>$g->process_payment(' . $unlinked->get_id() . ')["result"],"blocks"=>$b->is_active(),"scripts"=>$b->get_payment_method_script_handles()));';
		$process = proc_open( array( PHP_BINARY, '-c', php_ini_loaded_file(), '-d', 'disable_functions=dns_get_record', '-r', $code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
		preg_match( '/PC_BOOT:(\{[^\r\n]+\})/', $stdout, $match ); $boot = isset( $match[1] ) ? json_decode( $match[1], true ) : null;
		$ok( 'actual ' . $environment . ' bootstrap denies persisted opt-in and forged direct call', $exit === 0 && is_array( $boot ) && $boot['environment'] === $environment && $boot['persisted'] === 'yes' && $boot['allowed'] === false && $boot['result'] === 'failure' && ! array_intersect( Local_Payments::IDS, $boot['ids'] ) && $boot['blocks'] === false && $boot['scripts'] === array() );
	}
	$wrong = $make_order( 'pc_local_wallet' ); $ok( 'gateway rejects another native method', $gateway->process_payment( $wrong->get_id() )['result'] === 'failure' );
	$cancelled = $make_order(); $cancelled->update_status( 'cancelled' ); $ok( 'gateway rejects disallowed unpaid status', $gateway->process_payment( $cancelled->get_id() )['result'] === 'failure' );
	$ok( 'gateway rejects zero-total direct call', $gateway->process_payment( $zero->get_id() )['result'] === 'failure' );

	// Historical cohort fixtures go through the actual ingest and native server callbacks.
	$at = ( new DateTimeImmutable( '2019-06-19 12:00:00', wp_timezone() ) )->getTimestamp() * 1000;
	$previous_at = ( new DateTimeImmutable( '2019-06-18 12:00:00', wp_timezone() ) )->getTimestamp() * 1000;
	$range = Range::from_params( array( 'from' => '2019-06-19', 'to' => '2019-06-19' ) );
	$baseline = Report::summary( $range ); $ok( 'dedicated historical checkout window is empty', array_sum( $baseline['current'] ) === 0 && array_sum( $baseline['previous'] ) === 0 );
	$unit_visitor( $at ); $a = $client( array( array( 'checkout_view' ), array( 'checkout_address_completed', array( 'source' => 'native_blocks' ) ), array( 'checkout_shipping_selected', array( 'method' => 'pc_plans:1-standard', 'origin' => 'initial' ) ), array( 'checkout_payment_selected', array( 'method' => 'pc_local_card', 'origin' => 'initial' ) ), array( 'checkout_submit_attempt' ) ), $at );
	$paid = $make_order(); $paid->update_meta_data( Server_Events::ORDER_VISITOR_META, $a['visitor_id'] ); $paid->update_meta_data( Server_Events::ORDER_VISIT_META, $a['visit_id'] ); $paid->save_meta_data(); Server_Events::on_order_placed( $paid ); Capture::payment_attempt( $context( $paid ) ); Capture::payment_attempt( $context( $paid ) ); $paid->payment_complete(); Server_Events::on_payment_complete( $paid->get_id() );
	$unit_visitor( $at ); $abandoned = $client( array( array( 'checkout_payment_selected', array( 'method' => 'pc_local_wallet', 'origin' => 'initial' ) ), array( 'checkout_payment_selected', array( 'method' => 'pc_local_wallet', 'origin' => 'user' ) ), array( 'checkout_payment_selected', array( 'origin' => 'user' ) ) ), $at );
	$unit_visitor( $previous_at ); $client( array( array( 'checkout_view' ), array( 'checkout_payment_selected', array( 'method' => 'pc_local_wallet', 'origin' => 'initial' ) ) ), $previous_at );
	$unit_visitor( $at ); $f = $client( array( array( 'checkout_view' ) ), $at ); $failed = $make_order( 'pc_local_wallet' ); $failed->update_meta_data( Server_Events::ORDER_VISITOR_META, $f['visitor_id'] ); $failed->update_meta_data( Server_Events::ORDER_VISIT_META, $f['visit_id'] ); $failed->save_meta_data(); Server_Events::on_order_placed( $failed ); Capture::payment_attempt( $context( $failed, 'pc_local_wallet' ) ); $failed->update_status( 'failed' );
	$unit_visitor( $at ); $test = $client( array( array( 'checkout_view' ) ), $at ); $test_order = $make_order(); $test_order->set_payment_method_title( 'Test payment excluded fixture' ); $test_order->update_meta_data( Server_Events::ORDER_VISITOR_META, $test['visitor_id'] ); $test_order->update_meta_data( Server_Events::ORDER_VISIT_META, $test['visit_id'] ); $test_order->save(); Server_Events::on_order_placed( $test_order );
	$unit_visitor( $at ); $missing = $client( array( array( 'checkout_view' ) ), $at ); $wpdb->update( Schema::table( 'visits' ), array( 'order_id' => 999999999 ), array( 'id' => $missing['visit_id'] ) );
	$report = Report::summary( $range ); $by_channel = array_column( $report['channels'], null, 'method' );
	$ok( 'cohort counts observed checkout visits excluding test/deleted links', $report['current']['checkout_view'] === 2 );
	$ok( 'genuine abandoned client-only selections still count', $report['current']['checkout_payment_selected'] === 2 && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM ' . Schema::table( 'visits' ) . ' WHERE id=%d', $abandoned['visit_id'] ) ) === 0 );
	$ok( 'readiness/shipping/submit counts are observed distinct visits', $report['current']['checkout_address_completed'] === 1 && $report['current']['checkout_shipping_selected'] === 1 && $report['current']['checkout_submit_attempt'] === 1 );
	$ok( 'processing visits differ from attempt event counts', $report['current']['payment_attempt'] === 2 && $by_channel['pc_local_card']['current']['attempts'] === 2 && $by_channel['pc_local_card']['current']['attempt_visits'] === 1 );
	$ok( 'repeated success callbacks do not double paid orders/visits', $report['current']['payment_success'] === 1 && $by_channel['pc_local_card']['current']['attempt_orders'] === 1 && $by_channel['pc_local_card']['current']['succeeded'] === 1 );
	$ok( 'channel success uses processing-order denominator', $by_channel['pc_local_card']['current']['success_rate'] === 100.0 );
	$ok( 'explicit and initial selections remain separate distinct visits', $by_channel['pc_local_wallet']['current']['initial'] === 1 && $by_channel['pc_local_wallet']['current']['user'] === 1 );
	$ok( 'failed outcome comes from native Woo order', $by_channel['pc_local_wallet']['current']['failed'] === 1 && $by_channel['pc_local_wallet']['current']['success_rate'] === 0.0 );
	$ok( 'unknown method is bounded and denominator-free without processing', $by_channel['unknown']['current']['user'] === 1 && $by_channel['unknown']['current']['success_rate'] === null );
	$ok( 'previous window and percent change use visit-start cohort', $report['previous']['checkout_view'] === 1 && $report['change']['checkout_view'] === 100.0 );
	$outside = Report::period( (int) ( $at / 1000 ) + 1, (int) $range['to'] ); $ok( 'cohort lower date edge excludes earlier visit even when paid later', $outside['counts']['payment_success'] === 0 );
	$inside = Report::period( (int) ( $at / 1000 ), (int) ( $at / 1000 ) + 1 ); $ok( 'cohort boundary is inclusive start and exclusive end', $inside['counts']['payment_success'] === 1 );
	Server_Events::record( $a['visitor_id'], $a['visit_id'], 'srv-wrong-' . wp_generate_uuid4(), 'checkout_address_completed' );
	$ok( 'wrong event source never counts as browser readiness', Report::summary( $range )['current']['checkout_address_completed'] === 1 );
	$before_unrelated = Report::summary( $range );
	// More unrelated orders than any requested linked-ID batch; strictly newer dates expose unfiltered query fallback.
	for ( $n = 0; $n < 8; ++$n ) {
		$unrelated = $make_order( 'pc_local_wallet' );
		$unrelated->set_date_created( time() + 3600 + $n ); $unrelated->save();
	}
	$ok( 'newer unrelated native orders cannot displace requested linked IDs', Report::summary( $range ) === $before_unrelated );
	$wpdb->update( Schema::table( 'visits' ), array( 'order_id' => $paid->get_id() ), array( 'id' => $a['visit_id'] ) );
	$refund = wc_create_refund( array( 'order_id' => $paid->get_id(), 'amount' => 40, 'refund_payment' => false, 'restock_items' => false, 'reason' => 'Owned checkout report fixture' ) ); if ( is_wp_error( $refund ) ) { throw new RuntimeException( 'Native report refund failed' ); } $refunds[] = $refund->get_id();
	$ok( 'fully refunded native paid order retains historical payment success', Report::summary( $range )['current']['payment_success'] === 1 );
	$after_refund = Report::summary( $range );
	$ok( 'refund plus unrelated orders retains all cohort counts and channels', $after_refund === $before_unrelated );
	delete_option( Schema::OPTION ); $empty = Report::summary( $range ); update_option( Schema::OPTION, Schema::DB_VERSION ); $ok( 'tracking unavailable produces bounded empty payload', array_sum( $empty['current'] ) === 0 && $empty['channels'] === array() );
	wp_set_current_user( 0 ); $response = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/data/conversion' ) ); $ok( 'conversion report still enforces permissions', in_array( $response->get_status(), array( 401, 403 ), true ) );
	wp_set_current_user( $admin->ID ); $request = new WP_REST_Request( 'GET', '/peptide/v1/data/conversion' ); $request->set_param( 'from', '2019-06-19' ); $request->set_param( 'to', '2019-06-19' ); $response = rest_do_request( $request ); $payload = $response->get_data();
	$ok( 'checkout steps is additive sibling on existing no-store report', $response->get_status() === 200 && isset( $payload['checkout_steps'] ) && $response->get_headers()['Cache-Control'] === 'no-store' );
	$ok( 'old overall keys/groups remain exact', array_keys( $payload['overall']['current'] ) === Conversion::OVERALL && $payload['groups'] === array( 'device', 'browser' ) ); wp_set_current_user( 0 );

	// Two public native checkouts. Only the provider is substituted; no tracking POST, cart swap, pricing/stock/shipping/mail filters.
	$product = new WC_Product_Simple(); $product->set_name( 'Owned native checkout smoke fixture' ); $product->set_status( 'publish' ); $product->set_regular_price( '40' ); $product->set_manage_stock( true ); $product->set_stock_quantity( 12 ); $product->set_virtual( false ); $product->set_tax_status( 'none' ); $product->set_weight( '1' ); $product->save();
	$plan = PeptideCore\Shipping\Plans::save( array( 'type' => 'custom', 'name' => 'Owned native checkout smoke shipping', 'products' => array( $product->get_id() ), 'regions' => array( array( 'country' => 'US', 'states' => array() ) ), 'rates' => array( array( 'id' => 'owned-standard', 'name' => 'Owned test shipping', 'enabled' => true, 'fee' => 6 ) ) ) );
	$settings = (array) get_option( 'peptide_core_settings', array() ); $settings['checkout_ack'] = array( 'enabled' => true, 'label_html' => 'I confirm this is an isolated native checkout test; no charge.' ); update_option( 'peptide_core_settings', $settings );
	$http_cookie_before = $_COOKIE;
	foreach ( Local_Payments::IDS as $index => $channel ) {
		$jar = array(); $cart_token = '';
		$http = static function ( string $method, string $route, ?array $body = null ) use ( &$jar, &$cart_token, &$visitors, &$sessions, $wpdb ): array {
			$headers = array( 'Content-Type' => 'application/json' ); if ( $cart_token ) { $headers['Cart-Token'] = $cart_token; }
			$args = array( 'method' => $method, 'timeout' => 30, 'headers' => $headers, 'cookies' => array_values( $jar ) ); if ( null !== $body ) { $args['body'] = wp_json_encode( $body ); }
			$res = wp_remote_request( home_url( '/wp-json/' . $route ), $args ); if ( is_wp_error( $res ) ) { throw new RuntimeException( 'Native HTTP transport: ' . $res->get_error_code() ); }
			foreach ( wp_remote_retrieve_cookies( $res ) as $cookie ) { $jar[$cookie->name] = $cookie; }
			if ( isset( $jar[Tracker::COOKIE] ) ) {
				$v = $wpdb->get_row( $wpdb->prepare( 'SELECT id,cart_key FROM ' . Schema::table( 'visitors' ) . ' WHERE vid=%s', $jar[Tracker::COOKIE]->value ), ARRAY_A );
				if ( $v ) { $visitors[] = (int) $v['id']; if ( $v['cart_key'] ) { $sessions[] = $v['cart_key']; } }
			}
			$token = wp_remote_retrieve_header( $res, 'cart-token' ); if ( $token ) { $cart_token = (string) $token; }
			return array( wp_remote_retrieve_response_code( $res ), json_decode( wp_remote_retrieve_body( $res ), true ) );
		};
		[ $status, $session ] = $http( 'GET', 'peptide/v1/track/session' ); $ok( 'HTTP guest session eligible: ' . $channel, $status === 200 && $session['tracked'] === true );
		[ $status, $cart ] = $http( 'GET', 'wc/store/v1/cart' ); $ok( 'HTTP native cart token issued: ' . $channel, $status === 200 && $cart_token !== '' );
		[ $status, $cart ] = $http( 'POST', 'wc/store/v1/cart/add-item', array( 'id' => $product->get_id(), 'quantity' => 1 ) ); $ok( 'HTTP native add-to-cart preserves product price: ' . $channel, $status === 201 && $cart['items'][0]['prices']['price'] === '4000' );
		$address = array( 'first_name' => 'Isolated', 'last_name' => 'Fixture', 'address_1' => '100 Test Street', 'city' => 'Los Angeles', 'state' => 'CA', 'postcode' => '90001', 'country' => 'US' );
		$billing = $address + array( 'email' => 'native-flow-' . $index . '@example.invalid', 'phone' => '5551234567' );
		[ $status, $cart ] = $http( 'POST', 'wc/store/v1/cart/update-customer', array( 'billing_address' => $billing, 'shipping_address' => $address ) );
		$rate = $cart['shipping_rates'][0]['shipping_rates'][0] ?? array(); $ok( 'HTTP real configured shipping rate6 available: ' . $channel, $status === 200 && ( $rate['price'] ?? '' ) === '600' );
		[ $status, $cart ] = $http( 'POST', 'wc/store/v1/cart/select-shipping-rate', array( 'package_id' => 0, 'rate_id' => $rate['rate_id'] ) ); $ok( 'HTTP native cart total40 plus6 shipping: ' . $channel, $status === 200 && $cart['totals']['total_price'] === '4600' && $cart['totals']['total_shipping'] === '600' );
		[ $status, $draft ] = $http( 'GET', 'wc/store/v1/checkout' );
		$ok( 'HTTP native GET defers order creation until POST: ' . $channel, $status === 200 && (int) ( $draft['order_id'] ?? 0 ) === 0 );
		$facts_before_invalid = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . " WHERE type IN ('payment_attempt','payment_success')" );
		$checkout = array( 'billing_address' => $billing, 'shipping_address' => $address, 'payment_method' => $channel, 'payment_data' => array(), 'additional_fields' => array( PeptideCore\Checkout_Ack::FIELD_ID => false ) );
		[ $status, $invalid ] = $http( 'POST', 'wc/store/v1/checkout', $checkout ); $ok( 'HTTP native acknowledgement validation still rejects: ' . $channel, $status === 400 && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . " WHERE type IN ('payment_attempt','payment_success')" ) === $facts_before_invalid );
		$checkout['additional_fields'][PeptideCore\Checkout_Ack::FIELD_ID] = true;
		[ $status, $result ] = $http( 'POST', 'wc/store/v1/checkout', $checkout );
		$draft_id = (int) ( $result['order_id'] ?? 0 ); if ( $draft_id ) { $orders[] = $draft_id; }
		$ok( 'HTTP native local payment succeeds: ' . $channel, $status === 200 && ( $result['payment_result']['payment_status'] ?? '' ) === 'success' );
		$paid_order = $fresh_order( $draft_id ); $paid_order->read_meta_data( true );
		$ok( 'HTTP native order retains chosen distinct method and simulated marker: ' . $channel, $paid_order->get_payment_method() === $channel && $paid_order->get_meta( '_pc_payment_simulated' ) === 'yes' && $paid_order->get_meta( '_pc_payment_simulated_channel' ) === $channel );
		$ok( 'HTTP native order preserves ordinary local report classification: ' . $channel, ! PeptideCore\Overview::is_test_order( $paid_order ) && (float) $paid_order->get_total() === 46.0 && (float) $paid_order->get_shipping_total() === 6.0 );
		$ok( 'HTTP native acknowledgement snapshot and paid lifecycle retained: ' . $channel, is_array( $paid_order->get_meta( PeptideCore\Checkout_Ack::SNAPSHOT_META ) ) && $paid_order->is_paid() && $paid_order->get_data_store()->get_stock_reduced( $draft_id ) );
		$ok( 'HTTP native stock decreases once: ' . $channel, $fresh_stock( $product->get_id() ) === 11 - $index );
		[ $http_visitor, $http_visit ] = Server_Events::order_link( $paid_order ); $visitors[] = $http_visitor;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id,cart_key FROM ' . Schema::table( 'visitors' ) . ' WHERE id=%d', $http_visitor ), ARRAY_A ); if ( ! empty( $row['cart_key'] ) ) { $sessions[] = $row['cart_key']; }
		$ok( 'HTTP native anonymous identity/order association retained: ' . $channel, $http_visitor > 0 && $http_visit > 0 && (int) $row['user_id'] === 0 && $paid_order->get_customer_id() === 0 );
		$events = $wpdb->get_results( $wpdb->prepare( 'SELECT id,type,data FROM ' . Schema::table( 'events' ) . " WHERE order_id=%d AND type IN ('payment_attempt','payment_success') ORDER BY id", $draft_id ), ARRAY_A );
		$ok( 'HTTP passive attempt precedes exactly one authoritative success: ' . $channel, count( $events ) === 2 && $events[0]['type'] === 'payment_attempt' && $events[1]['type'] === 'payment_success' && json_decode( $events[0]['data'], true )['method'] === $channel );
		$replay_gateway = new Local_Gateway( $channel ); $replayed = $replay_gateway->process_payment( $draft_id );
		$ok( 'HTTP paid replay remains stable without second completion/stock reduction: ' . $channel, $replayed['result'] === 'success' && $count( 'payment_success', $draft_id ) === 1 && $fresh_stock( $product->get_id() ) === 11 - $index );
		echo 'NATIVE_CHECKOUT_PROOF=' . wp_json_encode( array( 'order_id' => $draft_id, 'method' => $channel, 'visitor_id' => $http_visitor, 'visit_id' => $http_visit, 'total' => (float) $paid_order->get_total(), 'shipping' => (float) $paid_order->get_shipping_total(), 'stock_after' => $fresh_stock( $product->get_id() ), 'facts' => array_map( static fn( $event ) => array( 'id' => (int) $event['id'], 'type' => $event['type'] ), $events ) ) ) . "\n";
	}
	$ok( 'HTTP work did not replace current staff/guest PHP cookie state', $_COOKIE === $http_cookie_before && get_current_user_id() === 0 );
} finally {
	// Self-owned native inventory cleanup uses normal refunds, never stock flags/filters.
	if ( $product ) {
		// POST can fail after creating a native draft but before returning its ID. Recover only orders containing this owned product.
		foreach ( wc_get_orders( array( 'limit' => -1, 'status' => array_merge( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ) ) ) as $candidate ) {
			foreach ( $candidate->get_items() as $item ) { if ( $item->get_product_id() === $product->get_id() ) { $orders[] = $candidate->get_id(); } }
		}
	}
	foreach ( array_unique( $orders ) as $id ) {
		$o = $fresh_order( $id ); if ( ! $o ) { continue; }
		if ( $product && $o->get_item_count() && $o->is_paid() && $o->get_remaining_refund_amount() > 0 ) {
			$lines = array(); foreach ( $o->get_items() as $item ) { if ( $item->get_product_id() !== $product->get_id() ) { throw new RuntimeException( 'Cleanup found unowned product' ); } $lines[$item->get_id()] = array( 'qty' => $item->get_quantity(), 'refund_total' => $item->get_total(), 'refund_tax' => $item->get_taxes()['total'] ); }
			$r = wc_create_refund( array( 'order_id' => $id, 'amount' => $o->get_remaining_refund_amount(), 'refund_payment' => false, 'restock_items' => true, 'line_items' => $lines, 'reason' => 'Owned native checkout smoke cleanup' ) );
			if ( is_wp_error( $r ) ) { throw new RuntimeException( 'Native cleanup refund failed' ); } $refunds[] = $r->get_id();
		} elseif ( $product && $o->get_item_count() && ! $o->is_paid() ) { $o->update_status( 'cancelled' ); }
		foreach ( $o->get_refunds() as $r ) { $refunds[] = $r->get_id(); $r->delete( true ); } $o->delete( true );
	}
	if ( $product ) { $stock = $fresh_stock( $product->get_id() ); $product->delete( true ); $ok( 'native refund cleanup restored owned product stock12', $stock === 12 ); }
	Privacy::delete_visitors( array_unique( array_map( 'intval', $visitors ) ) );
	$handler = new Automattic\WooCommerce\StoreApi\SessionHandler(); foreach ( array_unique( $sessions ) as $key ) { $handler->delete_session( $key ); }
	$owned = array_unique( array_merge( $orders, $refunds ) ); if ( $owned ) { $wpdb->query( 'DELETE FROM ' . PeptideCore\Emails\Log::table() . ' WHERE object_id IN (' . implode( ',', array_map( 'intval', $owned ) ) . ')' ); }
	foreach ( $options as $key => $value ) { null === $value ? delete_option( $key ) : update_option( $key, $value ); }
	$_COOKIE = $original_cookie; $_GET = $original_get; $_SERVER = $original_server; wp_set_current_user( $original_user );
	$left = array_filter( $orders, static fn( $id ) => (bool) wc_get_order( $id ) ); $ok( 'all self-owned native orders removed', ! $left );
	if ( $visitors ) { $ok( 'all self-owned tracking removed', 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) . ' WHERE id IN (' . implode( ',', array_unique( array_map( 'intval', $visitors ) ) ) . ')' ) ); }
}
echo 'CUSTOMER_FLOW_ASSERTIONS=' . $checks . "\n";
