<?php
/** Local-only notification leases/retries and shipping-readiness regression; no outgoing mail or existing orders. */
defined( 'ABSPATH' ) || exit;
use PeptideCore\Loyalty;
use PeptideCore\Launch_Check;
use PeptideCore\Shipping\Plans;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { throw new RuntimeException( 'Local WP-CLI only.' ); }
$pass = 0; $fail = 0;
$ok = static function ( string $name, bool $value ) use ( &$pass, &$fail ) { $value ? ++$pass : ++$fail; echo ( $value ? 'PASS ' : 'FAIL ' ) . $name . "\n"; };
$original_user = get_current_user_id(); $user = 0; $ids = array(); $calls = 0; $result = true; $scheduled = array(); $reentrant = 0; $in_mail = false;
$had_recovery = wp_next_scheduled( 'pc_loyalty_notice_recovery' );
wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( Loyalty::OPTION, 'options' );
$sentinel = new stdClass(); $original = get_option( Loyalty::OPTION, $sentinel ); $existed = $original !== $sentinel;
$block_async = static function ( $pre, $hook, $args ) use ( &$scheduled ) { if ( 'pc_loyalty_points_notice' === $hook ) { $scheduled[] = array( $args[0], time() ); return 0; } return $pre; };
$block_future = static function ( $pre, $when, $hook, $args ) use ( &$scheduled ) { if ( 'pc_loyalty_points_notice' === $hook ) { $scheduled[] = array( $args[0], $when ); return 0; } return $pre; };
add_filter( 'pre_as_enqueue_async_action', $block_async, 10, 3 ); add_filter( 'pre_as_schedule_single_action', $block_future, 10, 4 );
$mail = static function () use ( &$calls, &$result, &$reentrant, &$in_mail ) {
	++$calls;
	if ( $reentrant && ! $in_mail ) {
		$in_mail = true; global $wpdb; $wpdb->update( Loyalty::table( 'ledger' ), array( 'notice_lease' => time() - 1 ), array( 'id' => $reentrant ) );
		Loyalty::recover_notices(); Loyalty::send_notice( $reentrant ); $in_mail = false;
	}
	if ( 'throw' === $result ) { throw new RuntimeException( 'Synthetic mail transport exception' ); }
	return $result;
};
add_filter( 'pre_wp_mail', $mail );
global $wpdb; $table = Loyalty::table( 'ledger' );
$row = static fn( int $id ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A );
$set = static fn( int $id, array $values ) => $wpdb->update( $table, $values, array( 'id' => $id ) );
$new = static function () use ( &$user, &$ids ) { $r = Loyalty::adjust( $user, 1, 'Synthetic notification regression', wp_generate_uuid4() ); if ( is_wp_error( $r ) ) { throw new RuntimeException( 'Synthetic points entry failed.' ); } $ids[] = (int) $r['id']; return (int) $r['id']; };
$baseline_shipping = Launch_Check::shipping_status(); $mock_plans = array();
$plans_filter = static function () use ( &$mock_plans ) { return $mock_plans; };
try {
	Loyalty::install();
	$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 ); wp_set_current_user( $admin );
	$user = wp_insert_user( array( 'user_login' => 'pcnotice-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'pcnotice-' . wp_generate_uuid4() . '@example.invalid', 'role' => 'customer' ) );
	if ( is_wp_error( $user ) ) { throw new RuntimeException( 'Synthetic customer failed.' ); } $user = (int) $user;
	Loyalty::save_settings( array_merge( Loyalty::settings(), array( 'notify_enabled' => true ) ) );
	$ok( 'notification schema migrates without altering the points account model', '3' === get_option( Loyalty::SCHEMA ) && 0 === Loyalty::account( $user )['balance'] );
	Loyalty::ensure_notice_recovery(); $ok( 'enabled notifications arm a bounded recovery cron', (bool) wp_next_scheduled( 'pc_loyalty_notice_recovery' ) );
	$result = false; $id = $new(); $before = $calls; Loyalty::send_notice( $id ); $r = $row( $id );
	$ok( 'mail failure queues attempt one with backoff and releases its lease', $before + 1 === $calls && 'queued' === $r['notice_status'] && 1 === (int) $r['notice_attempts'] && (int) $r['notice_next'] >= time() + 50 && 0 === (int) $r['notice_lease'] );
	Loyalty::send_notice( $id ); $ok( 'duplicate callback during backoff neither sends nor consumes an attempt', $before + 1 === $calls && 1 === (int) $row( $id )['notice_attempts'] );
	$set( $id, array( 'notice_next' => time() - 1 ) ); $result = true; Loyalty::send_notice( $id ); Loyalty::send_notice( $id );
	$ok( 'due retry succeeds once and completed callbacks are idempotent', $before + 2 === $calls && 'sent' === $row( $id )['notice_status'] && 2 === (int) $row( $id )['notice_attempts'] );
	$id = $new(); $result = false; $before = $calls;
	for ( $attempt = 0; $attempt < Loyalty::NOTICE_ATTEMPTS; ++$attempt ) { $set( $id, array( 'notice_next' => time() - 1 ) ); Loyalty::send_notice( $id ); }
	Loyalty::recover_notices(); Loyalty::send_notice( $id );
	$ok( 'permanent failures stop after the configured maximum attempts', $before + Loyalty::NOTICE_ATTEMPTS === $calls && 'failed' === $row( $id )['notice_status'] && Loyalty::NOTICE_ATTEMPTS === (int) $row( $id )['notice_attempts'] );
	$id = $new(); $scheduled = array(); Loyalty::recover_notices();
	$ok( 'orphan queued ledger row is rediscovered and rescheduled', (bool) array_filter( $scheduled, static fn( $s ) => $s[0] === $id ) );
	$result = true; Loyalty::send_notice( $id );
	$id = $new(); $set( $id, array( 'notice_status' => 'sending', 'notice_attempts' => 1, 'notice_lease' => time() - 10, 'notice_claim' => 'synthetic-abandoned-lease' ) );
	Loyalty::recover_notices(); $ok( 'expired abandoned lease returns to queued without resetting its attempt count', 'queued' === $row( $id )['notice_status'] && 1 === (int) $row( $id )['notice_attempts'] ); Loyalty::send_notice( $id );
	$ok( 'recovered lease sends through the next bounded attempt', 'sent' === $row( $id )['notice_status'] && 2 === (int) $row( $id )['notice_attempts'] );
	$id = $new(); $set( $id, array( 'notice_status' => 'sending', 'notice_attempts' => 1, 'notice_lease' => time() + 200 ) ); $before = $calls; Loyalty::recover_notices(); Loyalty::send_notice( $id );
	$ok( 'unexpired live lease cannot be claimed by another callback', 'sending' === $row( $id )['notice_status'] && $before === $calls );
	$set( $id, array( 'notice_attempts' => Loyalty::NOTICE_ATTEMPTS, 'notice_lease' => time() - 1 ) ); Loyalty::recover_notices();
	$ok( 'abandoned final attempt becomes failed instead of looping forever', 'failed' === $row( $id )['notice_status'] );
	$id = $new(); $set( $id, array( 'notice_status' => 'failed', 'notice_attempts' => 0, 'notice_next' => 0 ) ); Loyalty::recover_notices(); Loyalty::send_notice( $id );
	$ok( 'legacy failed rows can recover within the new attempt limit', 'sent' === $row( $id )['notice_status'] );
	$id = $new(); $reentrant = $id; $before = $calls; Loyalty::send_notice( $id ); $reentrant = 0;
	$ok( 'reentrant recovery cannot steal a running worker even after lease expiry', $before + 1 === $calls && 'sent' === $row( $id )['notice_status'] && 1 === (int) $row( $id )['notice_attempts'] );
	$id = $new(); $result = 'throw'; Loyalty::send_notice( $id ); $result = true;
	$ok( 'transport exception is retryable without changing the points entry', 'queued' === $row( $id )['notice_status'] && 1 === (int) $row( $id )['delta'] );
	$id = $new(); Loyalty::save_settings( array_merge( Loyalty::settings(), array( 'notify_enabled' => false ) ) ); Loyalty::send_notice( $id );
	$ok( 'turning notifications off safely skips a queued notice', 'skipped' === $row( $id )['notice_status'] );
	$ok( 'all retry and recovery operations leave balances and ledger deltas intact', count( $ids ) === Loyalty::account( $user )['balance'] && count( $ids ) === (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(delta) FROM {$table} WHERE user_id=%d", $user ) ) );
	$allowed = array_keys( WC()->countries->get_shipping_countries() ); $country = $allowed[0];
	$plan = array( 'id' => 7654321, 'name' => 'Configured delivery', 'type' => 'general', 'regions' => array( array( 'country' => $country, 'states' => array() ) ), 'rates' => array( array( 'id' => 'r1', 'name' => 'Delivery', 'enabled' => true, 'fee' => 12, 'min' => 0, 'max' => '' ) ) );
	add_filter( 'pre_option_' . Plans::OPTION, $plans_filter );
	$mock_plans = array( $plan ); $ok( 'valid enabled plan rates supersede the old native test-zone warning', 'ok' === Launch_Check::shipping_status()['level'] );
	$mock_plans[0]['rates'][0]['enabled'] = false; $ok( 'active plans with no enabled rate fail readiness', 'fail' === Launch_Check::shipping_status()['level'] );
	$mock_plans = array( $plan ); $mock_plans[0]['name'] = 'Test shipping'; $ok( 'only test-labelled plan rates do not count as production configuration', 'fail' === Launch_Check::shipping_status()['level'] );
	$mock_plans = array( $plan ); $mock_plans[0]['rates'][0]['fee'] = 0; $ok( 'intentional free shipping is a confirmation warning rather than missing functionality', 'warn' === Launch_Check::shipping_status()['level'] );
	$mock_plans = array( $plan ); $mock_plans[0]['rates'][0]['min'] = 50; $mock_plans[0]['rates'][0]['max'] = 10; $ok( 'impossible rate ranges fail readiness', 'fail' === Launch_Check::shipping_status()['level'] );
	$mock_plans = array( $plan ); $mock_plans[0]['type'] = 'custom'; $mock_plans[0]['products'] = array(); $ok( 'empty custom-product plan does not pretend to provide a usable rate', 'fail' === Launch_Check::shipping_status()['level'] );
	$foreign = array_values( array_diff( array_keys( WC()->countries->get_countries() ), $allowed ) );
	if ( $foreign ) { $mock_plans = array( $plan ); $mock_plans[0]['regions'][0]['country'] = $foreign[0]; $ok( 'plans outside the allowed shipping countries fail readiness', 'fail' === Launch_Check::shipping_status()['level'] ); }
	$mock_plans = array( $plan ); $extra = $plan['rates'][0]; $extra['id'] = 'r2'; $extra['name'] = 'Demo rate'; $mock_plans[0]['rates'][] = $extra; $ok( 'mixed real and test plan rates require cleanup confirmation', 'warn' === Launch_Check::shipping_status()['level'] );
	remove_filter( 'pre_option_' . Plans::OPTION, $plans_filter );
	$ok( 'shipping inspection tests never rewrite the real zones or plan settings', $baseline_shipping === Launch_Check::shipping_status() );
} finally {
	remove_filter( 'pre_option_' . Plans::OPTION, $plans_filter );
	foreach ( $ids as $id ) { if ( function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( 'pc_loyalty_points_notice', array( $id ), 'peptide-points' ); } wp_clear_scheduled_hook( 'pc_loyalty_points_notice', array( $id ) ); }
	if ( ! $had_recovery ) { wp_clear_scheduled_hook( 'pc_loyalty_notice_recovery' ); }
	if ( $user ) { $wpdb->delete( $table, array( 'user_id' => $user ) ); $wpdb->delete( Loyalty::table( 'accounts' ), array( 'user_id' => $user ) ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user ); }
	wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( Loyalty::OPTION, 'options' ); $existed ? update_option( Loyalty::OPTION, $original, false ) : delete_option( Loyalty::OPTION );
	remove_filter( 'pre_wp_mail', $mail ); remove_filter( 'pre_as_enqueue_async_action', $block_async, 10 ); remove_filter( 'pre_as_schedule_single_action', $block_future, 10 ); wp_set_current_user( $original_user );
}
$ok( 'cleanup removes only the synthetic account/ledger/user and restores the notification option', ! get_userdata( $user ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id=%d", $user ) ) && ( $existed ? get_option( Loyalty::OPTION ) == $original : false === get_option( Loyalty::OPTION, false ) ) );
echo "RESULT {$pass} passed, {$fail} failed\n"; if ( $fail ) { throw new RuntimeException( 'Notification/readiness regression failed.' ); }
