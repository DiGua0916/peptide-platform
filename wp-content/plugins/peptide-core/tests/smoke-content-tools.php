<?php
/** Local-only search protocol/permission regression. Every HTTP request is intercepted; configuration is virtualized. */
defined( 'ABSPATH' ) || exit;

use PeptideCore\Content_Tools as Tools;
use PeptideCore\Admin\Content_Tools_Page as Page;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { throw new RuntimeException( 'Local WP-CLI only.' ); }
foreach ( array( 'PEPTIDE_GSC_CLIENT_ID', 'PEPTIDE_GSC_CLIENT_SECRET', 'PEPTIDE_GSC_REFRESH_TOKEN' ) as $name ) {
	if ( defined( $name ) || getenv( $name ) ) { throw new RuntimeException( 'Use a test process without GSC environment overrides.' ); }
}
$pass = 0; $fail = 0;
$ok = static function ( string $label, bool $yes ) use ( &$pass, &$fail ): void { $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS ' : 'FAIL ' ) . $label . "\n"; };
$old_user = get_current_user_id(); $old_get = $_GET; $old_post = $_POST; $old_request = $_REQUEST; $old_page = $GLOBALS['pagenow'] ?? null;
$users = array(); $posts = array(); $marker = 'pc-search-smoke-' . wp_generate_uuid4();
$memory = array( Tools::OPTION => array(), Tools::HISTORY => array() ); $option_filters = array();
foreach ( array_keys( $memory ) as $option ) {
	$read = static function () use ( &$memory, $option ) { return $memory[ $option ]; };
	$write = static function ( $value, $old ) use ( &$memory, $option ) { $memory[ $option ] = $value; return $old; };
	add_filter( 'pre_option_' . $option, $read ); add_filter( 'pre_update_option_' . $option, $write, 10, 2 ); $option_filters[] = array( $option, $read, $write );
}
$old_wait = get_transient( 'pc_ct_indexnow_wait' ); $calls = array(); $http_status = 200;
$http = static function ( $pre, $args, $url ) use ( &$calls, &$http_status ) {
	$calls[] = array( 'url' => $url, 'args' => $args ); $body = array();
	if ( Tools::INDEXNOW_ENDPOINT === $url ) { $body = array(); }
	elseif ( 'https://oauth2.googleapis.com/token' === $url ) { $body = array( 'access_token' => 'synthetic-oauth-access', 'expires_in' => 3600 ); }
	elseif ( 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect' === $url ) { $body = array( 'inspectionResult' => array( 'indexStatusResult' => array( 'verdict' => 'PASS', 'coverageState' => 'Synthetic stored index status', 'lastCrawlTime' => '2026-01-01T00:00:00Z' ) ) ); }
	else { return new WP_Error( 'test_blocked', 'Unexpected external request blocked by test.' ); }
	return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $http_status, 'message' => 'Synthetic mock' ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 ); $public_host = static fn() => true;
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ) );
if ( ! $admin ) { throw new RuntimeException( 'An administrator is required.' ); }
$die_handler = static fn() => static function ( $message, $title, $args ) { throw new RuntimeException( 'synthetic_die_' . (int) ( $args['response'] ?? 500 ) ); };
try {
	wp_set_current_user( (int) $admin[0] ); Page::register_hooks();
	$ok( 'AI is explicitly deferred even for administrators', ! Tools::ai_enabled() && ! Tools::configured() && ! Tools::can_generate( 'home_seo' ) && ! Tools::can_generate( 'post' ) );
	$generate = Tools::generate( array( 'context' => 'post', 'brief' => 'Synthetic' ) ); $apply = Tools::apply_draft( 'old-draft-id', array() );
	$ok( 'generation and application services return deferred 403 without HTTP', is_wp_error( $generate ) && 'pc_ct_ai_deferred' === $generate->get_error_code() && 403 === $generate->get_error_data()['status'] && is_wp_error( $apply ) && 403 === $apply->get_error_data()['status'] && 0 === count( $calls ) );
	$ok( 'no AI generation or application action is registered', false === has_action( 'admin_post_pc_ct_generate' ) && false === has_action( 'admin_post_pc_ct_apply' ) && false === has_action( 'admin_post_nopriv_pc_ct_generate' ) );
	add_filter( 'wp_die_handler', $die_handler, PHP_INT_MAX ); $GLOBALS['pagenow'] = 'admin-post.php';
	foreach ( array( 'pc_ct_generate', 'pc_ct_apply' ) as $action ) {
		$_REQUEST = array( 'action' => $action ); $rejected = false;
		try { Page::reject_deferred_ai_request(); } catch ( RuntimeException $e ) { $rejected = 'synthetic_die_403' === $e->getMessage(); }
		$ok( 'legacy ' . $action . ' request is explicitly rejected with 403', $rejected && 0 === count( $calls ) );
	}
	remove_filter( 'wp_die_handler', $die_handler, PHP_INT_MAX );
	$ok( 'all old page-level AI links are empty', '' === Page::entry_link( 'home_seo' ) && '' === Page::entry_link( 'post' ) && '' === Page::entry_link( 'page' ) );
	$config = array( 'enable_indexnow' => 1, 'google_verification' => 'synthetic-google', 'bing_verification' => 'synthetic-bing', 'gsc_site' => home_url( '/' ), 'gsc_client_id' => 'synthetic-client', 'gsc_client_secret' => 'synthetic-client-secret', 'gsc_refresh_token' => 'synthetic-refresh', 'ai_key' => 'ignored-ai-key', 'ai_model' => 'ignored-ai-model', 'ai_endpoint' => 'https://api.openai.com/v1/chat/completions' );
	$ok( 'admin saves encrypted GSC credentials while submitted AI fields are ignored', true === Tools::save_settings( $config ) && Tools::configured( 'gsc' ) && ! str_contains( wp_json_encode( $memory[ Tools::OPTION ] ), 'synthetic-client-secret' ) && ! isset( $memory[ Tools::OPTION ]['ai_model'], $memory[ Tools::OPTION ]['ai_key_enc'] ) );
	$ok( 'public configuration contains no credential ciphertext', ! str_contains( wp_json_encode( Tools::settings() ), '_enc' ) && ! str_contains( wp_json_encode( Tools::settings() ), 'synthetic-refresh' ) );
	$user = wp_insert_user( array( 'user_login' => $marker, 'user_email' => $marker . '@example.invalid', 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
	if ( is_wp_error( $user ) ) { throw new RuntimeException( 'User fixture failed.' ); } $users[] = $user; wp_set_current_user( $user );
	$ok( 'unprivileged user cannot configure or submit search URLs', is_wp_error( Tools::save_settings( $config ) ) && is_wp_error( Tools::submit_indexnow( array( home_url( '/' ) ) ) ) );
	wp_set_current_user( (int) $admin[0] );
	$post_id = wp_insert_post( array( 'post_title' => $marker, 'post_type' => 'post', 'post_status' => 'draft', 'post_content' => 'Synthetic', 'post_author' => get_current_user_id() ), true );
	if ( is_wp_error( $post_id ) ) { throw new RuntimeException( 'Draft fixture failed.' ); } $posts[] = $post_id;
	$ok( 'public ownership file serves only its exact generated path', Tools::indexnow_key() === Tools::key_file_content( wp_parse_url( Tools::indexnow_key_url(), PHP_URL_PATH ) ) && null === Tools::key_file_content( '/unknown-key.txt' ) );
	$ok( 'foreign and draft URLs cannot be submitted or inspected', is_wp_error( Tools::published_url( 'https://example.invalid/' ) ) && is_wp_error( Tools::published_url( get_permalink( $post_id ) ) ) );
	add_filter( 'peptide_core_content_tools_public_host', $public_host ); delete_transient( 'pc_ct_indexnow_wait' );
	$index = Tools::submit_indexnow( array( home_url( '/' ), home_url( '/' ) ) ); $call = end( $calls ); $body = json_decode( $call['args']['body'], true );
	$ok( 'IndexNow sends real protocol with own-domain deduplicated URLs and ownership file', ! is_wp_error( $index ) && 'received' === $index['state'] && 1 === count( $body['urlList'] ) && Tools::indexnow_key_url() === $body['keyLocation'] && Tools::INDEXNOW_ENDPOINT === $call['url'] );
	$ok( 'IndexNow repeat batch is rate limited', is_wp_error( Tools::submit_indexnow( array( home_url( '/' ) ) ) ) );
	delete_transient( 'pc_ct_indexnow_wait' ); $http_status = 202; $index = Tools::submit_indexnow( array( home_url( '/' ) ) );
	$ok( 'IndexNow 202 remains ownership pending rather than indexed', ! is_wp_error( $index ) && 'key_pending' === $index['state'] );
	delete_transient( 'pc_ct_indexnow_wait' ); $http_status = 403;
	$ok( 'IndexNow ownership failure is recorded honestly', is_wp_error( Tools::submit_indexnow( array( home_url( '/' ) ) ) ) && 'failed' === Tools::history()[0]['state'] );
	$http_status = 200; $inspection = Tools::inspect_url( home_url( '/' ) ); $call = end( $calls );
	$ok( 'GSC uses OAuth refresh and official stored-index inspection protocols', ! is_wp_error( $inspection ) && 'PASS' === $inspection['verdict'] && 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect' === $call['url'] && 'Bearer synthetic-oauth-access' === $call['args']['headers']['Authorization'] );
	$ok( 'history excludes OAuth credentials and tokens', ! preg_match( '/synthetic-client-secret|synthetic-refresh|synthetic-oauth-access/', wp_json_encode( Tools::history() ) ) );
	$memory[ Tools::OPTION ]['gsc_refresh_token_enc'] = '';
	$ok( 'unconfigured GSC never invents an index status', ! Tools::configured( 'gsc' ) && is_wp_error( Tools::inspect_url( home_url( '/' ) ) ) );
	foreach ( array( 'index', 'settings', 'history', 'write' ) as $tab ) {
		$_GET = array( 'page' => Page::SLUG, 'tab' => $tab, 'draft' => 'legacy-draft' ); ob_start(); Page::render(); $html = ob_get_clean();
		$ok( $tab . ' UI exposes no AI button, provider key field or generation form', ! preg_match( '/name="ai_|pc_ct_generate|pc_ct_apply|Generate an AI|Request AI|Provider settings|PEPTIDE_AI_/', $html ) && ! str_contains( $html, 'synthetic-client-secret' ) && ! str_contains( $html, 'synthetic-refresh' ) );
	}
	$_GET = array( 'page' => Page::SLUG, 'tab' => 'settings' ); ob_start(); Page::render(); $html = ob_get_clean();
	$ok( 'search settings retain nonce-protected ownership and GSC configuration', str_contains( $html, 'name="_wpnonce"' ) && str_contains( $html, 'name="gsc_refresh_token"' ) && str_contains( $html, 'name="enable_indexnow"' ) );
	ob_start(); Tools::verification_meta(); $meta = ob_get_clean();
	$ok( 'public Google and Bing verification metadata remains available', str_contains( $meta, 'google-site-verification' ) && str_contains( $meta, 'msvalidate.01' ) );
	$ok( 'only authenticated search mutation handlers remain registered', false !== has_action( 'admin_post_pc_ct_settings', array( Page::class, 'handle' ) ) && false !== has_action( 'admin_post_pc_ct_indexnow', array( Page::class, 'handle' ) ) && false === has_action( 'admin_post_nopriv_pc_ct_indexnow' ) );
} finally {
	wp_set_current_user( (int) $admin[0] ); foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
	if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; } foreach ( $users as $id ) { wp_delete_user( $id ); }
	delete_transient( 'pc_ct_indexnow_wait' ); if ( false !== $old_wait ) { set_transient( 'pc_ct_indexnow_wait', $old_wait, MINUTE_IN_SECONDS ); }
	foreach ( $option_filters as [ $option, $read, $write ] ) { remove_filter( 'pre_option_' . $option, $read ); remove_filter( 'pre_update_option_' . $option, $write ); }
	remove_filter( 'pre_http_request', $http, PHP_INT_MAX ); remove_filter( 'peptide_core_content_tools_public_host', $public_host ); remove_filter( 'wp_die_handler', $die_handler, PHP_INT_MAX );
	wp_set_current_user( $old_user ); $_GET = $old_get; $_POST = $old_post; $_REQUEST = $old_request; $GLOBALS['pagenow'] = $old_page;
}
echo "RESULT {$pass} PASS / {$fail} FAIL (search protocols mocked; AI deferred)\n";
if ( $fail ) { throw new RuntimeException( 'Content-tools regression failed.' ); }
