<?php
/** Local development only. Synthetic records, intercepted mail, real REST and admin HTTP. */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local WP-CLI only.' ); }

use PeptideCore\Subscriptions as S;
use PeptideCore\Admin\Subscriptions_Page as Page;
use PeptideCore\Staff;

if ( ! has_action( 'rest_api_init', array( S::class, 'routes' ) ) ) { S::register_hooks(); }
S::ensure_schema();
$pass = 0; $fail = 0;
$ok = static function ( string $label, bool $yes ) use ( &$pass, &$fail ): void { $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS ' : 'FAIL ' ) . $label . "\n"; };
$run = 'pc-sub-smoke-' . wp_generate_uuid4(); $emails = array(); $users = array(); $sessions = array(); $mail = array(); $allow_mail = true;
$original_user = get_current_user_id(); $original_ip = $_SERVER['REMOTE_ADDR'] ?? null; $original_cookies = $_COOKIE;
$ip = 'synthetic-' . $run; $_SERVER['REMOTE_ADDR'] = $ip;
$intercept = static function ( $pre, $atts ) use ( &$mail, &$allow_mail ) { $mail[] = $atts; return $allow_mail; };
add_filter( 'pre_wp_mail', $intercept, 100, 2 );
$input = static function ( string $suffix ) use ( $run, &$emails ): array { $email = $run . '-' . $suffix . '@example.invalid'; $emails[] = $email; return array( 'email' => $email, 'nonce' => wp_create_nonce( S::NONCE ), 'context' => S::form_context( 'Synthetic explicit consent', '/synthetic-subscription-test' ), 'consent' => '1', 'website' => '' ); };
$tokens = static function ( array $message ): array {
	$out = array(); preg_match_all( '~https?://[^\s]+~', $message['message'], $matches );
	foreach ( $matches[0] as $url ) { parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args ); if ( isset( $args['pc_subscription'] ) ) { $out[ $args['pc_subscription'] ] = $args; } }
	return $out;
};
$row_for = static function ( string $email ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . S::table() . ' WHERE email=%s', $email ), ARRAY_A ); };
$http = static function ( int $user, string $url, array $args = array() ) use ( &$sessions ) {
	if ( ! isset( $sessions[ $user ] ) ) { $sessions[ $user ] = WP_Session_Tokens::get_instance( $user )->create( time() + HOUR_IN_SECONDS ); }
	$token = $sessions[ $user ];
	$args['cookies'] = array( new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user, time() + HOUR_IN_SECONDS, 'auth', $token ) ) ), new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user, time() + HOUR_IN_SECONDS, 'logged_in', $token ) ) ) );
	$args['timeout'] = 15; $args['redirection'] = 0;
	return wp_remote_request( $url, $args );
};

try {
	wp_set_current_user( 0 );
	$form = S::render_form();
	$ok( 'form has unchecked explicit consent, nonce, context and accessible feedback', str_contains( $form, 'name="consent" value="1" required' ) && ! str_contains( $form, 'checked' ) && str_contains( $form, 'name="nonce"' ) && str_contains( $form, 'name="context"' ) && str_contains( $form, 'role="status"' ) );
	$a = $input( 'a' ); $bad = $a; $bad['nonce'] = 'invalid';
	$ok( 'invalid nonce refused without sending', is_wp_error( S::request( $bad ) ) && ! $mail );
	$bad = $a; $bad['consent'] = '0'; $ok( 'missing consent refused without storage', is_wp_error( S::request( $bad ) ) && ! $row_for( $a['email'] ) );
	$bad = $a; $bad['context'] .= 'tampered'; $ok( 'source and consent tampering refused', is_wp_error( S::request( $bad ) ) );
	$bad = $a; $bad['website'] = 'bot'; $ok( 'honeypot refused', is_wp_error( S::request( $bad ) ) );
	$req = new WP_REST_Request( 'POST', '/peptide/v1/subscriptions' ); $req->set_body_params( $a ); $response = rest_do_request( $req );
	$row = $row_for( $a['email'] ); $links = $tokens( end( $mail ) );
	$ok( 'real REST submission stores pending and sends only a confirmation request', 200 === $response->get_status() && $row && 'pending' === $row['status'] && count( $mail ) === 1 && isset( $links['confirm'], $links['unsubscribe'] ) );
	$ok( 'consent evidence and source persisted, confirmation secret stored only as hash', 'Synthetic explicit consent' === $row['consent_text'] && '/synthetic-subscription-test' === $row['source'] && $row['consent_at'] && ! str_contains( wp_json_encode( $row ), $links['confirm']['token'] ) );
	$ok( 'repeated send is rate limited', 'pc_subscription_rate' === S::request( $a )->get_error_code() && count( $mail ) === 1 );
	$c = $links['confirm']; $u = $links['unsubscribe'];
	$preview = wp_remote_get( S::token_url( 'confirm', (int) $c['subscriber'], $c['token'] ), array( 'timeout' => 15, 'redirection' => 0 ) );
	$ok( 'actual confirmation GET shows form and never activates', ! is_wp_error( $preview ) && 200 === wp_remote_retrieve_response_code( $preview ) && str_contains( wp_remote_retrieve_body( $preview ), 'method="post"' ) && 'pending' === $row_for( $a['email'] )['status'] );
	$ok( 'wrong token cannot activate', is_wp_error( S::consume_token( 'confirm', (int) $c['subscriber'], str_repeat( '0', 64 ) ) ) );
	$confirmed = S::consume_token( 'confirm', (int) $c['subscriber'], $c['token'] );
	$ok( 'valid explicit confirmation activates with timestamp', ! is_wp_error( $confirmed ) && 'active' === $row_for( $a['email'] )['status'] && $row_for( $a['email'] )['confirmed_at'] );
	$ok( 'confirmation cannot be replayed', is_wp_error( S::consume_token( 'confirm', (int) $c['subscriber'], $c['token'] ) ) );
	$ok( 'unsubscribe token revokes confirmed subscription', ! is_wp_error( S::consume_token( 'unsubscribe', (int) $u['subscriber'], $u['token'] ) ) && 'unsubscribed' === $row_for( $a['email'] )['status'] );
	$b = $input( 'b' ); S::request( $b ); $blinks = $tokens( end( $mail ) );
	global $wpdb; $wpdb->update( S::table(), array( 'token_expires' => time() - 1 ), array( 'email' => $b['email'] ) );
	$ok( 'expired confirmation remains pending', is_wp_error( S::consume_token( 'confirm', (int) $blinks['confirm']['subscriber'], $blinks['confirm']['token'] ) ) && 'pending' === $row_for( $b['email'] )['status'] );
	$allow_mail = false; $d = $input( 'mail-fails' ); $failed = S::request( $d );
	$ok( 'mail transport failure is explicit and never activates address', is_wp_error( $failed ) && 'pc_subscription_mail' === $failed->get_error_code() && 'pending' === $row_for( $d['email'] )['status'] );
	$ok( 'CSV formula cells are escaped', "'=1+1" === S::csv_cell( '=1+1' ) && "' @cmd" === S::csv_cell( ' @cmd' ) && 'plain' === S::csv_cell( 'plain' ) );
	foreach ( array( 'marketing', 'data', 'settings' ) as $module ) {
		$id = wp_insert_user( array( 'user_login' => $run . '-' . $module, 'user_pass' => wp_generate_password( 32 ), 'role' => Staff::ROLE ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Synthetic staff creation failed.' ); }
		$users[] = (int) $id; Staff::set_modules( $id, array( $module ) );
	}
	[ $marketing, $data, $settings ] = $users;
	$page = admin_url( 'admin.php?page=' . Page::SLUG );
	foreach ( array( 'marketing' => $marketing, 'data' => $data, 'settings' => $settings ) as $module => $id ) {
		$res = $http( $id, $page ); $status = is_wp_error( $res ) ? 0 : wp_remote_retrieve_response_code( $res );
		$ok( 'actual admin page permission ' . $module . ' status=' . $status, 'marketing' === $module ? 200 === $status && str_contains( wp_remote_retrieve_body( $res ), 'pc_subscribers_export' ) : 403 === $status );
	}
	wp_set_current_user( $marketing ); Staff::flush_cache(); $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $marketing, time() + HOUR_IN_SECONDS, 'logged_in', $sessions[ $marketing ] ); $export_nonce = wp_create_nonce( 'pc_subscribers_export' ); $delete_nonce = wp_create_nonce( 'pc_subscribers_delete' );
	$export = $http( $marketing, admin_url( 'admin-post.php' ), array( 'method' => 'POST', 'body' => array( 'action' => 'pc_subscribers_export', '_wpnonce' => $export_nonce, 'search' => $run ) ) );
	$csv = wp_remote_retrieve_body( $export );
	$ok( 'actual marketing CSV export includes evidence without token columns', 200 === wp_remote_retrieve_response_code( $export ) && str_contains( $csv, $a['email'] ) && str_contains( $csv, 'consent_at_utc' ) && ! str_contains( $csv, 'token_hash' ) );
	$deny = $http( $data, admin_url( 'admin-post.php' ), array( 'method' => 'POST', 'body' => array( 'action' => 'pc_subscribers_delete', '_wpnonce' => $delete_nonce, 'confirm_delete' => '1', 'ids' => array( $row['id'] ) ) ) );
	$ok( 'actual nonmarketing delete is denied and preserves record', 403 === wp_remote_retrieve_response_code( $deny ) && (bool) $row_for( $a['email'] ) );
	$remove = $http( $marketing, admin_url( 'admin-post.php' ), array( 'method' => 'POST', 'body' => array( 'action' => 'pc_subscribers_delete', '_wpnonce' => $delete_nonce, 'confirm_delete' => '1', 'ids' => array( $row['id'] ) ) ) );
	$ok( 'actual consented marketing delete removes only selected fixture', 302 === wp_remote_retrieve_response_code( $remove ) && ! $row_for( $a['email'] ) && (bool) $row_for( $b['email'] ) );
} finally {
	remove_filter( 'pre_wp_mail', $intercept, 100 ); global $wpdb;
	foreach ( array_unique( $emails ) as $email ) { $wpdb->delete( S::table(), array( 'email' => $email ) ); delete_transient( 'pc_sub_e_' . substr( hash_hmac( 'sha256', $email, wp_salt( 'auth' ) ), 0, 32 ) ); }
	delete_transient( 'pc_sub_i_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 ) );
	foreach ( $sessions as $id => $token ) { WP_Session_Tokens::get_instance( $id )->destroy( $token ); }
	require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { wp_delete_user( $id ); }
	wp_set_current_user( $original_user ); $_COOKIE = $original_cookies; if ( null === $original_ip ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $original_ip; }
}
echo "Subscriptions: $pass passed, $fail failed.\n";
if ( $fail ) { WP_CLI::halt( 1 ); }
