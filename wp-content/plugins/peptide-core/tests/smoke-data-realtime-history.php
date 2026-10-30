<?php
/** Calendar-history integration tests. Only the disposable history clone is allowed. */
defined( 'ABSPATH' ) || exit;

use PeptideCore\Data\Realtime;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Privacy;

global $wpdb;
if ( DB_NAME !== 'peptide_history_20261030' || $wpdb->get_var( 'SELECT DATABASE()' ) !== DB_NAME || wp_get_environment_type() !== 'local' || wp_normalize_path( ABSPATH ) !== 'D:/Projects/peptide-sync-work/20261030-history/site/' || ! defined( 'PC_SYNC_ISOLATION_GUARD' ) ) {
	throw new RuntimeException( 'History tests require their owned isolated clone.' );
}
$passed = 0;
$check = static function ( string $label, bool $condition ) use ( &$passed ): void {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL ' . $label ); }
	++$passed;
	echo 'PASS ', $label, "\n";
};
$visitors = array();
$orders = array();
$product = null;
$refund = null;
$subscriber = 0;
$original_user = get_current_user_id();
$prefix = 'history018-' . wp_generate_uuid4();
$tz_filter = static fn() => 'America/New_York';
$off_filter = static fn() => '0';
$renamed = array();
$request = static function ( array $params = array() ) {
	$r = new WP_REST_Request( 'GET', '/peptide/v1/data/realtime/history' );
	$r->set_query_params( $params );
	return rest_do_request( $r );
};
$fixture_visit = static function ( int $start, int $stage, int $visitor = 0 ) use ( $wpdb, &$visitors, $prefix ): array {
	if ( ! $visitor ) {
		$wpdb->insert( Schema::table( 'visitors' ), array( 'vid' => md5( $prefix . count( $visitors ) ), 'first_ms' => $start, 'last_ms' => $start, 'is_test' => 1 ) );
		$visitor = (int) $wpdb->insert_id;
		$visitors[] = $visitor;
	}
	$wpdb->insert( Schema::table( 'visits' ), array( 'visitor_id' => $visitor, 'start_ms' => $start, 'last_ms' => $start + 1000, 'stage' => $stage, 'is_test' => 1 ) );
	return array( $visitor, (int) $wpdb->insert_id );
};
$fixture_event = static function ( array $visit, int $time, string $type, string $path = '', int $pid = 0 ) use ( $wpdb, $prefix ): void {
	$wpdb->insert( Schema::table( 'events' ), array( 'event_uid' => $prefix . '-' . wp_rand(), 'visitor_id' => $visit[0], 'visit_id' => $visit[1], 'type' => $type, 'occurred_ms' => $time, 'received_ms' => $time, 'path' => $path, 'product_id' => $pid, 'is_test' => 1 ) );
};
try {
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
	wp_set_current_user( $admin->ID );
	$check( 'tracking installed in owned clone', Schema::installed() );
	$now = ( new DateTimeImmutable( '2032-05-10 12:00:00', wp_timezone() ) )->getTimestamp();
	foreach ( array( 'today', 'yesterday', '7d', '30d' ) as $preset ) {
		$r = Realtime::history_params( array( 'range' => $preset ), $now );
		$check( 'valid preset ' . $preset, ! is_wp_error( $r ) && $r['preset'] === $preset );
	}
	$check( 'no date keys defaults today', Realtime::history_params( array(), $now )['preset'] === 'today' );
	$invalid = array(
		array( 'from' => '', 'to' => '' ), array( 'from' => '2032-05-10' ), array( 'to' => '2032-05-10' ),
		array( 'from' => '2032-05-11', 'to' => '2032-05-10' ), array( 'from' => '2032-02-30', 'to' => '2032-03-01' ),
		array( 'from' => '2031-01-01', 'to' => '2032-01-02' ), array( 'from' => array(), 'to' => '2032-05-10' ),
		array( 'range' => array( 'today' ) ), array( 'range' => '' ), array( 'range' => 'bogus' ), array( 'from' => null, 'to' => null ),
	);
	foreach ( $invalid as $i => $params ) {
		$bad = Realtime::history_params( $params, $now );
		$check( 'raw invalid range returns field error ' . $i, is_wp_error( $bad ) && $bad->get_error_data()['status'] === 400 && ! empty( $bad->get_error_data()['fields'] ) );
		$check( 'REST invalid range 400 ' . $i, $request( $params )->get_status() === 400 );
	}
	$r = Realtime::history_params( array( 'from' => '2032-05-10', 'to' => '2032-05-11' ) );
	$fm = $r['from'] * 1000;
	$tm = $r['to'] * 1000;
	$check( 'inclusive end date becomes next local midnight', wp_date( 'Y-m-d H:i:s', $r['to'] ) === '2032-05-12 00:00:00' && $r['days'] === 2 );
	$a = $fixture_visit( $fm, 1 );
	$b = $fixture_visit( $fm + 3600000, 5, $a[0] );
	$c = $fixture_visit( $fm + 90000000, 2, $a[0] );
	$d = $fixture_visit( $fm + 90000001, 3 );
	$outside = $fixture_visit( $fm - 1, 5 );
	$end = $fixture_visit( $tm, 5 );
	$fixture_event( $a, $fm, 'page_view', '/history-a/' );
	$fixture_event( $outside, $fm + 1, 'page_view', '/history-b/' );
	$fixture_event( $a, $fm - 1, 'page_view', '/outside/' );
	$fixture_event( $end, $tm, 'page_view', '/end/' );
	$product = new WC_Product_Simple();
	$product->set_name( 'Synthetic history 018 fixture' );
	$product->set_regular_price( '10' );
	$product->set_status( 'publish' );
	$product->save();
	$fixture_event( $a, $fm, 'product_view', '', $product->get_id() );
	$fixture_event( $a, $fm + 1, 'add_to_cart', '', $product->get_id() );
	$make_order = static function ( int $qty, string $status, int $time, bool $test = false ) use ( &$orders, $product ): WC_Order {
		$o = wc_create_order();
		$orders[] = $o->get_id();
		$o->add_product( $product, $qty );
		if ( $test ) { $o->set_payment_method( 'cod' ); $o->set_payment_method_title( 'Test payment – no money is charged' ); }
		$o->calculate_totals();
		$o->set_status( $status );
		$o->set_date_created( $time );
		$o->save();
		return $o;
	};
	$o1 = $make_order( 3, 'processing', $r['from'] );
	$o2 = $make_order( 1, 'completed', $r['from'] + 1 );
	$make_order( 7, 'pending', $r['from'] + 2 );
	$make_order( 7, 'failed', $r['from'] + 3 );
	$make_order( 7, 'processing', $r['from'] + 4, true );
	$make_order( 7, 'processing', $r['to'] );
	$item = array_values( $o1->get_items() )[0];
	$refund = wc_create_refund( array( 'order_id' => $o1->get_id(), 'amount' => 10, 'refund_payment' => false, 'restock_items' => false, 'line_items' => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => 10 ) ) ) );
	$check( 'native partial refund fixture', $refund instanceof WC_Order_Refund );
	$sql = array();
	$observe = static function ( $query ) use ( &$sql ) { $sql[] = $query; return $query; };
	add_filter( 'query', $observe );
	$h = Realtime::history( $r );
	remove_filter( 'query', $observe );
	$check( 'explicit history payload without live today/minute/window fields', $h['mode'] === 'history' && ! isset( $h['today'], $h['minutes'], $h['cards']['window'] ) );
	$check( 'whole-period UV deduplicates visits and buckets', $h['cards']['visitors'] === 2 );
	$check( 'funnel same start cohort; outside visits excluded', array_column( $h['funnel']['layers'], 'count' ) === array( 2, 2, 2, 2, 1 ) );
	$check( 'PV uses occurred_ms including outside-start visit and excludes both outer boundaries', $h['cards']['pageviews'] === 2 );
	$check( 'native paid, test, pending, failed and end-exclusive order policy', $h['cards']['orders'] === 2 );
	$check( 'sales subtract native refund', abs( $h['cards']['sales'] - 30 ) < 0.001 );
	$check( 'refunded quantities and average value', $h['cards']['units_per_order'] === 1.5 && $h['cards']['aov'] === 15.0 );
	$check( 'daily chart buckets distinct visitors independently', array_column( $h['series']['points'], 'visitors' ) === array( 1, 2 ) );
	$check( 'daily chart chronological local labels', array_column( $h['series']['points'], 'label' ) === array( '2032-05-10', '2032-05-11' ) );
	$check( 'period timezone and bounds explicit', $h['range']['timezone'] === wp_timezone_string() && $h['range']['from'] === $r['from'] && $h['range']['to'] === $r['to'] );
	$check( 'all product lists in period and sold net units', $h['lists']['viewed'][0]['count'] === 1 && $h['lists']['carted'][0]['count'] === 1 && $h['lists']['sold'][0]['count'] === 3 );
	$check( 'page list boundary and binary path tie policy', array_column( $h['lists']['pages'], 'path' ) === array( '/history-a/', '/history-b/' ) );
	$check( 'custom drilldowns preserve inclusive dates', strpos( $h['links']['products'], 'from=2032-05-10' ) !== false && strpos( $h['links']['pages'], 'to=2032-05-11' ) !== false && strpos( $h['links']['pages'], 'range=today' ) === false );
	$aggregates = array_filter( $sql, static fn( $q ) => strpos( $q, 'AS bucket' ) !== false );
	$check( 'one bounded distinct aggregate for complete chart', count( $aggregates ) === 1 && strpos( reset( $aggregates ), 'COUNT(DISTINCT visitor_id)' ) !== false && strpos( reset( $aggregates ), 'GROUP BY bucket' ) !== false );
	$check( 'history top page query limited', (bool) array_filter( $sql, static fn( $q ) => strpos( $q, 'GROUP BY path ORDER BY cnt DESC, BINARY path ASC LIMIT 5' ) !== false ) );
	$check( 'no buyer-history lookup', ! array_filter( $sql, static fn( $q ) => strpos( $q, '_billing_email' ) !== false ) );
	$one = Realtime::history( Realtime::history_params( array( 'from' => '2032-05-10', 'to' => '2032-05-10' ) ) );
	$check( 'one-day hourly chart counts duplicate visitor in separate hours', $one['series']['points'][0]['visitors'] === 1 && $one['series']['points'][1]['visitors'] === 1 && $one['cards']['visitors'] === 1 );
	$check( 'hours zero filled', $one['series']['points'][2]['visitors'] === 0 );
	$empty = Realtime::history( Realtime::history_params( array( 'from' => '2040-01-01', 'to' => '2040-01-01' ) ) );
	$check( 'empty history zero cards/funnel/chart/lists', $empty['cards']['visitors'] === 0 && $empty['cards']['orders'] === 0 && array_sum( array_column( $empty['series']['points'], 'visitors' ) ) === 0 && ! $empty['lists']['pages'] );
	add_filter( 'pre_option_timezone_string', $tz_filter );
	foreach ( array( '2032-03-14' => 23, '2032-11-07' => 25 ) as $date => $length ) {
		$dst = Realtime::history( Realtime::history_params( array( 'from' => $date, 'to' => $date ) ) );
		$starts = array_column( $dst['series']['points'], 'start' );
		$check( 'DST hourly bucket count ' . $length, count( $starts ) === $length );
		$check( 'DST actual chronological hour steps ' . $length, $starts[1] - $starts[0] === 3600 && count( array_unique( $starts ) ) === $length );
		$check( 'DST bucket bounds tile complete range ' . $length, $dst['series']['points'][0]['start'] === $dst['range']['from'] && end( $dst['series']['points'] )['end'] === $dst['range']['to'] );
	}
	$daily = Realtime::history( Realtime::history_params( array( 'from' => '2032-03-13', 'to' => '2032-03-15' ) ) );
	$check( 'calendar days cross DST with local midnight boundaries', count( $daily['series']['points'] ) === 3 && $daily['series']['points'][1]['end'] - $daily['series']['points'][1]['start'] === 23 * 3600 );
	remove_filter( 'pre_option_timezone_string', $tz_filter );
	$max = Realtime::history( Realtime::history_params( array( 'from' => '2032-01-01', 'to' => '2032-12-31' ) ) );
	$check( '366-day maximum chart bounded', count( $max['series']['points'] ) === 366 );
	foreach ( array( 'visitors', 'visits', 'events', 'tabs' ) as $name ) {
		$table = Schema::table( $name );
		$held = $table . '_history018_hold';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $held ) ) ) { throw new RuntimeException( 'Tracking hold collision' ); }
		if ( false === $wpdb->query( "RENAME TABLE `{$table}` TO `{$held}`" ) ) { throw new RuntimeException( 'Tracking hold failed' ); }
		$renamed[ $table ] = $held;
	}
	add_filter( 'pre_option_' . Schema::OPTION, $off_filter );
	$absent = Realtime::history( $r );
	$check( 'tables absent retains native orders with zero tracking data', ! $absent['tracking'] && $absent['cards']['orders'] === 2 && $absent['cards']['visitors'] === 0 && ! $absent['lists']['pages'] );
	remove_filter( 'pre_option_' . Schema::OPTION, $off_filter );
	foreach ( $renamed as $table => $held ) { $wpdb->query( "RENAME TABLE `{$held}` TO `{$table}`" ); }
	$renamed = array();
	$ok = $request( array( 'from' => '2032-05-10', 'to' => '2032-05-11' ) );
	$check( 'admin REST 200 and no-store', $ok->get_status() === 200 && $ok->get_headers()['Cache-Control'] === 'no-store' );
	wp_set_current_user( 0 );
	$check( 'anonymous REST 401', $request()->get_status() === 401 );
	$subscriber = wp_insert_user( array( 'user_login' => $prefix, 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	if ( is_wp_error( $subscriber ) ) { throw new RuntimeException( 'Own subscriber creation failed' ); }
	wp_set_current_user( $subscriber );
	$check( 'subscriber REST 403', $request()->get_status() === 403 );
	wp_set_current_user( $admin->ID );
	$live = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/data/realtime' ) )->get_data();
	$check( 'default realtime contract retained', isset( $live['today'], $live['now_ms'], $live['minutes'] ) && ! isset( $live['mode'], $live['series'] ) && $live['cards']['window'] === 10 && $live['funnel']['window'] === 10 && $live['minutes']['window'] === 10 );
} finally {
	remove_filter( 'pre_option_timezone_string', $tz_filter );
	remove_filter( 'pre_option_' . Schema::OPTION, $off_filter );
	foreach ( $renamed as $table => $held ) { if ( false === $wpdb->query( "RENAME TABLE `{$held}` TO `{$table}`" ) ) { throw new RuntimeException( 'Could not restore tracking table' ); } }
	if ( $refund instanceof WC_Order_Refund ) { $refund->delete( true ); }
	foreach ( $orders as $id ) { $o = wc_get_order( $id ); if ( $o ) { $o->delete( true ); } }
	if ( $product ) { $product->delete( true ); }
	Privacy::delete_visitors( $visitors );
	if ( $subscriber && ! is_wp_error( $subscriber ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $subscriber ); }
	wp_set_current_user( $original_user );
}
$check( 'owned tracking fixture cleanup', ! $visitors || 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) . ' WHERE id IN (' . implode( ',', array_map( 'intval', $visitors ) ) . ')' ) );
echo 'RESULT ', $passed, " passed, 0 failed\n";
