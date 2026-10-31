<?php
/** Native report verification, restricted to the already-owned disposable clone. */
defined( 'ABSPATH' ) || exit;

use PeptideCore\Data\Visitor_Funnel;
use PeptideCore\Data\Operations;
use PeptideCore\Data\Conversion;
use PeptideCore\Data\Realtime;
use PeptideCore\Data\Range;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Privacy;
use PeptideCore\Tracking\Server_Events;

global $wpdb;
if ( DB_NAME !== 'peptide_history_20261030' || $wpdb->get_var( 'SELECT DATABASE()' ) !== DB_NAME || wp_get_environment_type() !== 'local' || wp_normalize_path( ABSPATH ) !== 'D:/Projects/peptide-sync-work/20261030-history/site/' || ! defined( 'PC_SYNC_ISOLATION_GUARD' ) ) {
	throw new RuntimeException( '022 requires its existing isolated clone.' );
}
$passed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed ): void {
	if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $label ); }
	++$passed; echo 'PASS ', $label, "\n";
};
$ids = array(); $orders = array(); $prefix = 'funnel022-' . wp_generate_uuid4();
$range = Range::from_params( array( 'from' => '2022-06-10', 'to' => '2022-06-10' ) );
$fm = $range['from'] * 1000; $tm = $range['to'] * 1000; $now = $fm + 12 * 3600000;
$visit = static function ( int $at, string $device = 'desktop', int $stage = 3, int $visitor = 0, string $browser = 'Chrome', ?int $last = null, ?int $first = null ) use ( $wpdb, &$ids, $prefix ): array {
	if ( ! $visitor ) {
		$wpdb->insert( Schema::table( 'visitors' ), array( 'vid' => md5( $prefix . count( $ids ) ), 'first_ms' => $first ?? $at, 'last_ms' => $last ?? $at, 'browser' => $browser, 'device' => $device ) );
		$visitor = (int) $wpdb->insert_id; $ids[] = $visitor;
	}
	$wpdb->insert( Schema::table( 'visits' ), array( 'visitor_id' => $visitor, 'start_ms' => $at, 'last_ms' => $last ?? $at, 'device' => $device, 'stage' => $stage ) );
	return array( $visitor, (int) $wpdb->insert_id );
};
$event = static function ( array $v, string $type, string $source, int $at, int $order = 0, array $data = array() ) use ( $wpdb, $prefix ): void {
	if ( false === $wpdb->insert( Schema::table( 'events' ), array( 'event_uid' => 'f022-' . md5( $prefix . wp_generate_uuid4() ), 'visitor_id' => $v[0], 'visit_id' => $v[1], 'type' => $type, 'source' => $source, 'occurred_ms' => $at, 'received_ms' => $at, 'order_id' => $order, 'data' => wp_json_encode( $data ) ) ) ) { throw new RuntimeException( 'Fixture event insert failed.' ); }
};
$order = static function ( array $v, string $status = 'processing', bool $paid = true, bool $test = false ) use ( &$orders, $range ): WC_Order {
	$o = wc_create_order(); $orders[] = $o->get_id();
	$o->set_total( 10 ); $o->set_currency( 'USD' ); $o->set_date_created( $range['from'] + 100 );
	$o->set_payment_method( 'pc_local_card' );
	$o->set_payment_method_title( $test ? 'Test payment fixture 022' : 'Local synthetic 022' );
	$o->update_meta_data( Server_Events::ORDER_VISITOR_META, $v[0] );
	$o->update_meta_data( Server_Events::ORDER_VISIT_META, $v[1] );
	$o->set_status( $status ); $o->set_date_paid( $paid ? $range['from'] + 101 : null ); $o->save();
	return $o;
};
$counts = static fn( array $block ) => array_column( $block['layers'], 'count', 'key' );
$single = static function ( array $v, int $at ) use ( $counts ): array { return $counts( ( new Visitor_Funnel() )->period( $at, $at + 1 )['overall'] ); };
$strip = static function ( array $data ) use ( &$strip ): array {
	unset( $data['visitor_funnel'], $data['returning_visitor_funnel'], $data['updated'] );
	foreach ( $data as $key => $value ) { if ( is_array( $value ) ) { $data[ $key ] = $strip( $value ); } }
	return $data;
};
try {
	$check( 'schema v4 installed after additive attribution migration', Schema::installed() && Schema::DB_VERSION === '4' );
	$a = $visit( $fm + 1, 'desktop', 5, 0, 'Chrome', $now, $fm - 365 * DAY_IN_SECONDS * 1000 );
	$b = $visit( $fm + 2, 'mobile', 3, $a[0], 'Chrome', $now );
	$o1 = $order( $a ); $o2 = $order( $a );
	foreach ( array( $a, $b ) as $v ) {
		foreach ( array( 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected' ) as $type ) { $event( $v, $type, 'client', $now - 1000 ); }
	}
	$event( $a, 'payment_success', 'server', $now - 1000, $o1->get_id() );
	$event( $a, 'payment_success', 'server', $now - 900, $o2->get_id() );
	$detail = ( new Visitor_Funnel() )->period( $fm, $tm, 'start_ms', 'device' );
	$c = $counts( $detail['overall'] );
	$check( 'eight stable keys', array_keys( $c ) === Visitor_Funnel::KEYS );
	$check( 'same visitor multiple visits and two orders counts once', array_values( $c ) === array( 1, 1, 1, 1, 1, 1, 1, 1 ) );
	$check( 'visitor can count once in each device group', $counts( $detail['groups']['desktop'] )['visitors'] === 1 && $counts( $detail['groups']['mobile'] )['visitors'] === 1 );
	$check( 'overall is distinct not sum of groups', $c['visitors'] === 1 && array_sum( array_map( static fn( $g ) => $g['layers'][0]['count'], $detail['groups'] ) ) === 2 );
	$check( 'returning visitor counted once across visits', $counts( $detail['returning'] )['paid'] === 1 );
	$check( 'accumulated event after cohort timestamp counted', $counts( ( new Visitor_Funnel() )->period( $fm + 1, $fm + 2 )['overall'] )['address'] === 1 );
	$browser = ( new Visitor_Funnel() )->period( $fm, $tm, 'start_ms', 'browser' );
	$check( 'browser identity grouped consistently', $counts( $browser['groups']['Chrome'] )['paid'] === 1 );
	$historical = $visit( $fm + 100, '', 5, 0, '' );
	$hc = $single( $historical, $fm + 100 );
	$check( 'stage5 never fabricates middle or paid observations', $hc['checkout'] === 1 && $hc['address'] === 0 && $hc['shipping'] === 0 && $hc['payment'] === 0 && $hc['paid'] === 0 );
	$unknown = ( new Visitor_Funnel() )->period( $fm + 100, $fm + 101, 'start_ms', 'browser' );
	$check( 'blank group maps to unknown', isset( $unknown['groups']['unknown'] ) );
	$abandoned = $visit( $fm + 200 );
	$event( $abandoned, 'checkout_shipping_selected', 'client', $fm + 300 );
	$event( $abandoned, 'checkout_step_applicability', 'client', $fm + 300, 0, array( 'address_required' => false, 'shipping_required' => false ) );
	$ac = $single( $abandoned, $fm + 200 );
	$check( 'abandoned client visit valid and not-required steps not invented', $ac['address'] === 0 && $ac['shipping'] === 1 && $ac['payment'] === 0 );
	$nonmono = Visitor_Funnel::layers( $ac );
	$check( 'zero previous denominator null with nonmonotonic counts', $nonmono[5]['step_rate'] === null && $nonmono[5]['visitor_rate'] === 100.0 );
	$event( $abandoned, 'payment_success', 'client', $fm + 300, $o1->get_id() );
	$event( $abandoned, 'checkout_address_completed', 'server', $fm + 300 );
	$event( array( $a[0], $abandoned[1] ), 'checkout_address_completed', 'client', $fm + 300 );
	$event( array( $abandoned[0], 999999999 ), 'checkout_payment_selected', 'client', $fm + 300 );
	$ac = $single( $abandoned, $fm + 200 );
	$check( 'spoofed sources and mismatched/orphan visit ignored', $ac['paid'] === 0 && $ac['address'] === 0 && $ac['payment'] === 0 );
	$at = $fm + 1000;
	foreach ( array( array( 'pending', false, false, 0 ), array( 'failed', false, false, 0 ), array( 'processing', true, true, 0 ), array( 'refunded', true, false, 1 ), array( 'failed', true, false, 1 ), array( 'pending', true, false, 1 ) ) as $case ) {
		$v = $visit( $at ); $o = $order( $v, $case[0], $case[1], $case[2] );
		$event( $v, 'payment_success', 'server', $at + 100, $o->get_id() );
		$sc = $single( $v, $at );
		$check( 'native paid history ' . implode( '-', array_map( 'intval', array_slice( $case, 1 ) ) ) . '-' . $case[0], $sc['paid'] === $case[3] );
		$at += 1000;
	}
	$conflict = $visit( $at ); $real = $order( $conflict ); $test = $order( $conflict, 'processing', true, true );
	$wpdb->update( Schema::table( 'visits' ), array( 'order_id' => $test->get_id() ), array( 'id' => $conflict[1] ) );
	$event( $conflict, 'payment_success', 'server', $at + 1, $real->get_id() );
	$event( $conflict, 'checkout_address_completed', 'client', $at + 1, $real->get_id() );
	$event( $conflict, 'checkout_shipping_selected', 'client', $at + 1 );
	$cc = $single( $conflict, $at );
	$check( 'paid event order authoritative over later test link', $cc['paid'] === 1 );
	$check( 'explicit middle order authoritative, fallback test excluded', $cc['address'] === 1 && $cc['shipping'] === 0 );
	$wpdb->update( Schema::table( 'visits' ), array( 'order_id' => 999999999 ), array( 'id' => $conflict[1] ) );
	$check( 'later deleted current visit order does not erase earlier paid fact', $single( $conflict, $at )['paid'] === 1 );
	$other = $visit( $at + 1000 );
	$event( $other, 'payment_success', 'server', $at + 1001, $real->get_id() );
	$check( 'native order ownership blocks cross-visitor paid attribution', $single( $other, $at + 1000 )['paid'] === 0 );
	$event( $other, 'payment_success', 'server', $at + 1001 );
	$event( $other, 'checkout_payment_selected', 'client', $at + 1001, 999999999 );
	$check( 'missing positive order and zero paid order ignored', $single( $other, $at + 1000 )['payment'] === 0 && $single( $other, $at + 1000 )['paid'] === 0 );
	$deleted = $order( $other ); $deleted_id = $deleted->get_id(); $deleted->delete( true );
	$event( $other, 'payment_success', 'server', $at + 1001, $deleted_id );
	$event( $other, 'checkout_address_completed', 'client', $at + 1001, $deleted_id );
	$check( 'deleted order signals excluded', $single( $other, $at + 1000 )['paid'] === 0 && $single( $other, $at + 1000 )['address'] === 0 );
	$owner = $visit( $at + 3000 ); $same_visitor = $visit( $at + 3001, 'desktop', 3, $owner[0] ); $owned = $order( $owner );
	$event( $same_visitor, 'payment_success', 'server', $at + 3002, $owned->get_id() );
	$check( 'same visitor wrong native visit cannot claim paid fact', $single( $same_visitor, $at + 3001 )['paid'] === 0 );
	$owned->delete_meta_data( Server_Events::ORDER_VISIT_META ); $owned->save_meta_data();
	$event( $owner, 'payment_success', 'server', $at + 3003, $owned->get_id() );
	$check( 'partial native tracking ownership cannot claim paid fact', $single( $owner, $at + 3000 )['paid'] === 0 );
	$gap = max( 1, (int) \PeptideCore\Store_Config::get( 'returning_hours' ) ) * HOUR_IN_SECONDS * 1000;
	$off_ms = wp_timezone()->getOffset( new DateTimeImmutable() ) * 1000;
	$first = $fm - 30 * DAY_IN_SECONDS * 1000;
	$boundary = (int) ( floor( ( $first + $off_ms ) / 86400000 ) + 1 ) * 86400000 - $off_ms + $gap;
	$early_return = $visit( $boundary - 1, 'desktop', 3, 0, 'Chrome', null, $first );
	$on_return = $visit( $boundary, 'desktop', 3, $early_return[0] );
	$check( 'returning boundary before gap excluded', $counts( ( new Visitor_Funnel() )->period( $boundary - 1, $boundary )['returning'] )['visitors'] === 0 );
	$check( 'returning boundary at gap included', $counts( ( new Visitor_Funnel() )->period( $boundary, $boundary + 1 )['returning'] )['visitors'] === 1 );
	// More than one event page, but only a single native order classification.
	for ( $i = 0; $i < 505; ++$i ) { $event( $a, 'checkout_address_completed', 'client', $now - 500, $o1->get_id() ); }
	$sql = array(); $observer = static function ( $query ) use ( &$sql ) { $sql[] = $query; return $query; };
	add_filter( 'query', $observer );
	$batch = ( new Visitor_Funnel() )->period( $fm + 1, $fm + 3 );
	remove_filter( 'query', $observer );
	$check( 'event keyset spans multiple bounded pages', count( array_filter( $sql, static fn( $q ) => str_contains( $q, 'ORDER BY e.id LIMIT 500' ) ) ) === 2 );
	$check( 'duplicates do not inflate visitor observations', $counts( $batch['overall'] )['address'] === 1 );
	$check( 'queries bounded not per event', count( $sql ) < 50 && ! array_filter( $sql, static fn( $q ) => str_contains( $q, '_billing_email' ) ) );
	$start = $visit( $fm ); $end = $visit( $tm );
	$check( 'history start included and end excluded', $counts( ( new Visitor_Funnel() )->period( $fm, $fm + 1 )['overall'] )['visitors'] === 1 && $counts( ( new Visitor_Funnel() )->period( $tm - 1, $tm )['overall'] )['visitors'] === 0 );
	$lower = $visit( $fm - 10, 'desktop', 3, 0, 'Chrome', $now - 600000 );
	$upper = $visit( $fm - 11, 'desktop', 3, 0, 'Chrome', $now );
	$future = $visit( $fm - 12, 'desktop', 3, 0, 'Chrome', $now + 1 );
	$event( $upper, 'checkout_payment_selected', 'client', $now + 1 );
	$live = ( new Visitor_Funnel() )->period( $now - 600000 + 1, $now + 1, 'last_ms', '', $now );
	$check( 'live lower-open upper-closed activity cohort', $counts( $live['overall'] )['visitors'] === 2 );
	$check( 'future live observation excluded', $counts( $live['overall'] )['payment'] === 1 );
	$empty = Visitor_Funnel::layers( Visitor_Funnel::zero() );
	$check( 'zero denominators render null', $empty[0]['visitor_rate'] === null && $empty[7]['step_rate'] === null );
	$off = static fn() => '0'; add_filter( 'pre_option_' . Schema::OPTION, $off );
	$missing = ( new Visitor_Funnel() )->period( $fm, $tm );
	remove_filter( 'pre_option_' . Schema::OPTION, $off );
	$check( 'uninstalled tracking returns explicit eight zero layers', $counts( $missing['overall'] ) === Visitor_Funnel::zero() );
	$op = Operations::summary( $range ); $cv = Conversion::summary( $range, 'device' ); $rt = Realtime::summary( $now, 10, 10 ); $history = Realtime::history( $range );
	$check( 'all reports expose visitor-only siblings', isset( $op['visitor_funnel'], $op['returning_visitor_funnel'], $cv['visitor_funnel'], $rt['visitor_funnel'], $history['visitor_funnel'] ) );
	$native_orders = array_filter( Operations::paid_orders( $range['from'], $range['to'] ), static fn( $o ) => ! \PeptideCore\Overview::is_test_order( $o ) );
	$check( 'legacy paid order card uses native order policy independently', $op['current']['orders'] === count( $native_orders ) && $counts( $batch['overall'] )['paid'] === 1 );
	$check( 'every group gets explicit detailed eight layers', ! array_filter( $cv['rows'], static fn( $row ) => ! isset( $row['visitor_funnel'] ) || count( $row['visitor_funnel']['layers'] ) !== 8 ) );
	$check( 'no visitor identity or event payload in detailed output', ! preg_match( '/visitor_id|visit_id|order_id|event_uid|"data"/', wp_json_encode( $op['visitor_funnel'] ) ) );
	$oracle = getenv( 'PC022_ORACLE_DIR' );
	if ( $oracle ) {
		foreach ( array( 'Range', 'Params', 'Checkout_Steps' ) as $name ) { class_alias( 'PeptideCore\\Data\\' . $name, 'PeptideCore\\Baseline022\\' . $name ); }
		foreach ( array( 'operations', 'realtime', 'conversion' ) as $name ) { require_once $oracle . '/class-' . $name . '.php'; }
		$check( 'baseline oracle Operations old fields identical', $strip( $op ) === $strip( \PeptideCore\Baseline022\Operations::summary( $range ) ) );
		$check( 'baseline oracle Conversion device old fields identical', $strip( $cv ) === $strip( \PeptideCore\Baseline022\Conversion::summary( $range, 'device' ) ) );
		$check( 'baseline oracle Conversion browser old fields identical', $strip( Conversion::summary( $range, 'browser' ) ) === $strip( \PeptideCore\Baseline022\Conversion::summary( $range, 'browser' ) ) );
		$check( 'baseline oracle Realtime old fields identical', $strip( $rt ) === $strip( \PeptideCore\Baseline022\Realtime::summary( $now, 10, 10 ) ) );
		$check( 'baseline oracle history old fields identical', $strip( $history ) === $strip( \PeptideCore\Baseline022\Realtime::history( $range ) ) );
	} else { echo "SKIP baseline oracle (set PC022_ORACLE_DIR to reviewed private namespace copies)\n"; }
} finally {
	if ( isset( $observer ) ) { remove_filter( 'query', $observer ); }
	if ( isset( $off ) ) { remove_filter( 'pre_option_' . Schema::OPTION, $off ); }
	foreach ( $orders as $id ) { $o = wc_get_order( $id ); if ( $o ) { $o->delete( true ); } }
	Privacy::delete_visitors( $ids );
}
$check( 'owned visitor cleanup complete', 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) . ' WHERE id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' ) );
echo 'TOTAL ', $passed, " PASS 0 FAIL\n";
