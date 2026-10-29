<?php
/** Local fixtures only: all mail and scheduling intercepted, no business options changed. */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local WP-CLI only.' ); }
use PeptideCore\Email_Campaigns as C;
use PeptideCore\Subscriptions as S;
use PeptideCore\Staff;

$pass = 0; $fail = 0; $ok = static function( string $label, bool $yes ) use ( &$pass, &$fail ): void { $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS ' : 'FAIL ' ) . $label . "\n"; };
$run = 'pc-campaign-test-' . wp_generate_uuid4(); $campaigns = array(); $subscribers = array(); $users = array(); $mail = array(); $mail_success = true; $throw_mail = false;
$original_user = get_current_user_id(); $original_cookies = $_COOKIE; $filters = array(); $sessions = array();
foreach ( array( C::ENABLED => true, 'woocommerce_email_from_address' => 'synthetic-sender@example.invalid', 'woocommerce_email_from_name' => 'Synthetic store', 'woocommerce_store_address' => 'Synthetic test address', 'woocommerce_store_city' => 'Synthetic city' ) as $option => $value ) {
	$filters[ $option ] = static fn() => $value; add_filter( 'pre_option_' . $option, $filters[ $option ] );
}
$no_schedule = static function( $pre, $event ) { return C::HOOK === $event->hook ? true : $pre; }; add_filter( 'pre_schedule_event', $no_schedule, 10, 2 );
$no_mail = static function( $pre, $atts ) use ( &$mail, &$mail_success, &$throw_mail, $run ) {
	$to = implode( ',', (array) $atts['to'] );
	if ( ! str_contains( $to, $run ) || ! str_contains( $to, '@example.invalid' ) ) { throw new RuntimeException( 'Refusing non-fixture recipient.' ); }
	$mail[] = $atts; if ( $throw_mail ) { throw new RuntimeException( 'Synthetic uncertain mail failure.' ); } return $mail_success;
}; add_filter( 'pre_wp_mail', $no_mail, 999, 2 );
$admin = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) );
$source = '/' . $run;
$create = static function( string $suffix, string $status = 'active', bool $consent = true, bool $confirmed = true ) use ( &$subscribers, $run, $source ): array {
	global $wpdb; $now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( S::table(), array( 'email' => $run . '-' . $suffix . '@example.invalid', 'status' => $status, 'source' => $source, 'consent_text' => $consent ? 'Synthetic explicit campaign consent' : '', 'consent_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'confirmed_at' => $confirmed ? $now : null, 'unsubscribe_hash' => hash( 'sha256', wp_generate_password( 40 ) ) ) );
	$id = (int) $wpdb->insert_id; $subscribers[] = $id; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . S::table() . ' WHERE id=%d', $id ), ARRAY_A );
};
$draft = static function( string $suffix ) use ( &$campaigns, $source, $run ) { $id = C::save( 0, array( 'title' => $run . '-' . $suffix, 'subject' => 'Synthetic campaign', 'body' => '<p>Only a synthetic message.</p><script>unsafe()</script>', 'audience_source' => $source ) ); if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_code() ); } $campaigns[] = $id; return $id; };
$http = static function( int $user, string $url, array $args = array() ) use ( &$sessions ) {
	if ( ! isset( $sessions[ $user ] ) ) { $sessions[ $user ] = WP_Session_Tokens::get_instance( $user )->create( time() + 3600 ); }
	$token = $sessions[ $user ]; $args['cookies'] = array( new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user, time() + 3600, 'auth', $token ) ) ), new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user, time() + 3600, 'logged_in', $token ) ) ) );
	$args['timeout'] = 15; $args['redirection'] = 0; return wp_remote_request( $url, $args );
};
try {
	C::install(); S::ensure_schema(); wp_set_current_user( (int) $admin[0] );
	$ok( 'valid sender and physical address satisfy ready guard through memory-only option filters', true === C::ready() );
	// pre_option false means continue default: use an empty scalar to force an explicit disabled result.
	$disabled = static fn() => ''; add_filter( 'pre_option_' . C::ENABLED, $disabled, 30 );
	$ok( 'sending disabled by default preference is refused', is_wp_error( C::ready() ) ); remove_filter( 'pre_option_' . C::ENABLED, $disabled, 30 );
	$empty_sender = static fn() => 'invalid'; add_filter( 'pre_option_woocommerce_email_from_address', $empty_sender, 30 ); $ok( 'invalid sender cannot schedule', is_wp_error( C::ready() ) ); remove_filter( 'pre_option_woocommerce_email_from_address', $empty_sender, 30 );
	$a = $create( 'a' ); $b = $create( 'b' ); $withdraw = $create( 'withdraw' ); $create( 'pending', 'pending' ); $create( 'no-consent', 'active', false ); $create( 'no-confirmation', 'active', true, false );
	$id = $draft( 'one' ); $row = C::get( $id );
	$ok( 'draft is saved with sanitized HTML and no outgoing mail', 'draft' === $row['status'] && ! str_contains( $row['body'], '<script' ) && ! $mail );
	$preview = C::message( $row ); $ok( 'preview contains mandatory footer and never sends', str_contains( $preview, 'Synthetic test address' ) && str_contains( $preview, '#unsubscribe-preview' ) && ! $mail );
	$queued = C::schedule( $id, time() + 3600 ); $ok( 'scheduling snapshots only confirmed and consenting audience', ! is_wp_error( $queued ) && 'scheduled' === $queued['status'] && 3 === C::stats( $id )['pending'] );
	$ok( 'queued content cannot be silently edited', is_wp_error( C::save( $id, array( 'title' => 'Changed', 'subject' => 'Changed', 'body' => 'Changed' ) ) ) );
	C::run_batch( $id ); $ok( 'scheduled future batch does not send early', ! $mail );
	C::control( $id, 'pause' ); C::run_batch( $id ); $ok( 'pause blocks queued work', 'paused' === C::get( $id )['status'] && ! $mail );
	C::control( $id, 'resume' ); C::run_batch( $id ); $ok( 'resuming a future campaign preserves its scheduled time', (int) C::get( $id )['scheduled_at'] > time() && ! $mail ); C::control( $id, 'pause' ); global $wpdb; $wpdb->update( C::table(), array( 'scheduled_at' => time() - 1 ), array( 'id' => $id ) );
	$unsubscribe = C::unsubscribe_url( $withdraw ); parse_str( wp_parse_url( $unsubscribe, PHP_URL_QUERY ), $args );
	$ok( 'wrong unsubscribe token cannot revoke another subscription', is_wp_error( C::unsubscribe( (int) $withdraw['id'], 'wrong' ) ) );
	$unsub_get = wp_remote_get( $unsubscribe, array( 'timeout' => 15 ) ); $unsub_body = wp_remote_retrieve_body( $unsub_get );
	$ok( 'real unsubscribe GET asks for confirmation without changing consent', 200 === wp_remote_retrieve_response_code( $unsub_get ) && str_contains( $unsub_body, 'method="post"' ) && 'active' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . S::table() . ' WHERE id=%d', $withdraw['id'] ) ) );
	$unsub_bad = wp_remote_post( $unsubscribe, array( 'timeout' => 15, 'body' => array( '_wpnonce' => 'invalid' ) ) );
	$ok( 'real unsubscribe POST requires its confirmation nonce', 403 === wp_remote_retrieve_response_code( $unsub_bad ) );
	preg_match( '/name="_wpnonce" value="([^"]+)"/', $unsub_body, $unsub_match );
	$unsub_done = wp_remote_post( $unsubscribe, array( 'timeout' => 15, 'body' => array( '_wpnonce' => $unsub_match[1] ?? '' ) ) );
	$ok( 'real confirmed unsubscribe revokes active consent for pending campaigns', 200 === wp_remote_retrieve_response_code( $unsub_done ) && 'unsubscribed' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . S::table() . ' WHERE id=%d', $withdraw['id'] ) ) );
	$ok( 'unsubscribe replay is refused', is_wp_error( C::unsubscribe( (int) $withdraw['id'], $args['token'] ) ) );
	C::control( $id, 'resume' ); C::run_batch( $id ); $stats = C::stats( $id );
	$ok( 'resume sends remaining active subscriptions and skips withdrawn address', 2 === count( $mail ) && 2 === $stats['sent'] && 1 === $stats['skipped'] && 'completed' === C::get( $id )['status'] );
	$ok( 'accepted status uses transport response and no delivery claim', str_contains( implode( "\n", $mail[0]['headers'] ), 'List-Unsubscribe:' ) && str_contains( $mail[0]['message'], 'Synthetic test address' ) );
	C::run_batch( $id ); $ok( 'completed campaign and repeated worker cannot duplicate messages', 2 === count( $mail ) && 2 === C::stats( $id )['sent'] );
	$failed_id = $draft( 'failure' ); C::schedule( $failed_id ); $mail_success = false; C::run_batch( $failed_id );
	$ok( 'known mail transport failure is counted as failed, never sent', 2 === C::stats( $failed_id )['failed'] && 0 === C::stats( $failed_id )['sent'] );
	$mail_success = true; C::control( $failed_id, 'retry' ); C::run_batch( $failed_id );
	$ok( 'explicit retry sends failed recipients once and preserves deduplication', 2 === C::stats( $failed_id )['sent'] && 0 === C::stats( $failed_id )['failed'] );
	$unknown_id = $draft( 'unknown' ); C::schedule( $unknown_id ); $throw_mail = true; C::run_batch( $unknown_id ); $throw_mail = false;
	$ok( 'exception with unknown transport outcome is isolated from retries', 2 === C::stats( $unknown_id )['uncertain'] && 0 === C::stats( $unknown_id )['failed'] );
	$count = count( $mail ); C::control( $unknown_id, 'retry' ); C::run_batch( $unknown_id ); $ok( 'uncertain sends are not automatically sent again', count( $mail ) === $count );
	$cancel = $draft( 'cancel' ); C::schedule( $cancel ); C::control( $cancel, 'cancel' ); C::run_batch( $cancel ); $ok( 'cancel prevents all subsequent sends', count( $mail ) === $count && 'cancelled' === C::get( $cancel )['status'] );
	for ( $i = 0; $i < 23; $i++ ) { $create( 'batch-' . $i ); }
	$batch = $draft( 'batch' ); C::schedule( $batch ); C::run_batch( $batch );
	$ok( 'one worker processes at most 20 recipients', 20 === C::stats( $batch )['sent'] && 5 === C::stats( $batch )['pending'] );
	C::run_batch( $batch ); $ok( 'next batch completes remaining recipients without duplicates', 25 === C::stats( $batch )['sent'] && 'completed' === C::get( $batch )['status'] );
	$lock = $draft( 'lock' ); C::schedule( $lock ); global $wpdb; $wpdb->update( C::table(), array( 'lock_token' => 'synthetic-worker-lock', 'lock_until' => time() + 300 ), array( 'id' => $lock ) ); $count = count( $mail ); C::run_batch( $lock );
	$ok( 'concurrent worker lease prevents duplicate work', count( $mail ) === $count && 25 === C::stats( $lock )['pending'] );
	$uid = wp_insert_user( array( 'user_login' => $run, 'user_pass' => wp_generate_password( 32 ), 'role' => Staff::ROLE ) ); if ( is_wp_error( $uid ) ) { throw new RuntimeException( 'Staff fixture failed.' ); } $users[] = $uid; Staff::set_modules( $uid, array( 'data' ) ); wp_set_current_user( $uid ); Staff::flush_cache();
	$ok( 'data employee cannot edit or schedule campaigns', is_wp_error( C::save( 0, array( 'title' => 'Denied', 'subject' => 'Denied', 'body' => 'Denied' ) ) ) && is_wp_error( C::schedule( $lock ) ) );
	Staff::set_modules( $uid, array( 'marketing' ) ); Staff::flush_cache(); $ok( 'marketing employee owns the campaign capability and menu scope', current_user_can( C::CAP ) && 'marketing' === Staff::module_for( 'admin.php', 'pc-promotions-campaigns' ) );
	$page = admin_url( 'admin.php?page=pc-promotions-campaigns&new=1' ); $res = $http( $uid, $page );
	$ok( 'actual marketing campaign editor opens with draft and preview actions', ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) && str_contains( wp_remote_retrieve_body( $res ), 'pc_campaign_save' ) && str_contains( wp_remote_retrieve_body( $res ), 'pc_campaign_preview' ) );
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $uid, time() + 3600, 'logged_in', $sessions[ $uid ] ); $nonce = wp_create_nonce( 'pc_campaign_save' );
	$preview_response = $http( $uid, admin_url( 'admin-post.php' ), array( 'method' => 'POST', 'body' => array( 'action' => 'pc_campaign_preview', '_wpnonce' => $nonce, 'subject' => 'Synthetic preview', 'body' => '<p>Preview only</p><script>blocked()</script>' ) ) );
	$ok( 'actual preview endpoint returns sandboxed content without sending', 200 === wp_remote_retrieve_response_code( $preview_response ) && str_contains( wp_remote_retrieve_body( $preview_response ), 'sandbox=""' ) && ! str_contains( wp_remote_retrieve_body( $preview_response ), '&lt;script&gt;' ) );
	$saved = $http( $uid, admin_url( 'admin-post.php' ), array( 'method' => 'POST', 'body' => array( 'action' => 'pc_campaign_save', '_wpnonce' => $nonce, 'title' => $run . '-http', 'subject' => 'Synthetic HTTP draft', 'body' => '<p>Never sent</p>', 'audience_source' => $source ) ) );
	parse_str( (string) wp_parse_url( wp_remote_retrieve_header( $saved, 'location' ), PHP_URL_QUERY ), $saved_args ); $http_id = absint( $saved_args['edit'] ?? 0 ); if ( $http_id ) { $campaigns[] = $http_id; }
	$ok( 'actual marketing save creates an unscheduled draft', 302 === wp_remote_retrieve_response_code( $saved ) && $http_id && 'draft' === C::get( $http_id )['status'] );
	foreach ( array( 'data', 'settings' ) as $module ) { Staff::set_modules( $uid, array( $module ) ); Staff::flush_cache(); $denied = $http( $uid, $page ); $write_denied = $http( $uid, admin_url( 'admin-post.php' ), array( 'method' => 'POST', 'body' => array( 'action' => 'pc_campaign_save', '_wpnonce' => $nonce, 'title' => $run, 'subject' => 'Denied', 'body' => 'Denied' ) ) ); $ok( 'actual ' . $module . ' employee cannot read or save campaigns', 403 === wp_remote_retrieve_response_code( $denied ) && 403 === wp_remote_retrieve_response_code( $write_denied ) ); }
} finally {
	global $wpdb; foreach ( $campaigns as $id ) { $wpdb->delete( C::table( 'deliveries' ), array( 'campaign_id' => $id ) ); $wpdb->delete( C::table(), array( 'id' => $id ) ); }
	foreach ( $subscribers as $id ) { $wpdb->delete( S::table(), array( 'id' => $id ) ); }
	foreach ( $sessions as $id => $token ) { WP_Session_Tokens::get_instance( $id )->destroy( $token ); }
	require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { wp_delete_user( $id ); }
	foreach ( $filters as $option => $callback ) { remove_filter( 'pre_option_' . $option, $callback ); }
	remove_filter( 'pre_schedule_event', $no_schedule, 10 ); remove_filter( 'pre_wp_mail', $no_mail, 999 ); $_COOKIE = $original_cookies; wp_set_current_user( $original_user ); Staff::flush_cache();
}
echo "Email campaigns: $pass passed, $fail failed.\n";
if ( $fail ) { WP_CLI::halt( 1 ); }
