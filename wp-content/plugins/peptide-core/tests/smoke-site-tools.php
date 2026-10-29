<?php
/** Local-only site tools regression. Uses new empty tool options, synthetic pages/users/files and exact cleanup. */
defined( 'ABSPATH' ) || exit;
use PeptideCore\Site_Tools;
use PeptideCore\Admin\Site_Tools_Page;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { throw new RuntimeException( 'Local WP-CLI only.' ); }
if ( false === has_action( 'template_redirect', array( Site_Tools::class, 'serve_file' ) ) ) { throw new RuntimeException( 'Integrate Site_Tools hooks before this live HTTP regression.' ); }
$pass = 0; $fail = 0;
$ok = static function ( string $label, bool $yes ) use ( &$pass, &$fail ) { $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS ' : 'FAIL ' ) . $label . "\n"; };
$tag = 'pctools-' . substr( wp_generate_uuid4(), 0, 12 ); $posts = array(); $users = array(); $sessions = array(); $snapshot = array();
$original_user = get_current_user_id(); $original_cookie = $_COOKIE; $original_get = $_GET; $original_post = $_POST;
foreach ( array( Site_Tools::OPTION, Site_Tools::FILES, Site_Tools::PHRASES ) as $option ) {
	wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( $option, 'options' ); $none = new stdClass(); $v = get_option( $option, $none );
	$snapshot[ $option ] = array( $v !== $none, $v );
	if ( $v !== $none && ( ( Site_Tools::OPTION === $option && ( ! empty( $v['enabled'] ) || ! empty( $v['snippets'] ) ) ) || ( Site_Tools::OPTION !== $option && $v ) ) ) { throw new RuntimeException( 'Test requires empty tool options; existing operator content is protected.' ); }
}
$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ) )[0] ?? 0 );
$jar = static function ( int $id ) use ( &$sessions ): array { $expires = time() + HOUR_IN_SECONDS; wp_cache_delete( $id, 'user_meta' ); $token = WP_Session_Tokens::get_instance( $id )->create( $expires ); $sessions[] = array( $id, $token ); $login = wp_generate_auth_cookie( $id, $expires, 'logged_in', $token ); return array( 'login' => $login, 'cookies' => array( new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $id, $expires, 'auth', $token ) ) ), new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => $login ) ) ) ); };
$http = static function ( string $url, array $args = array() ): array { $r = wp_remote_request( $url, $args + array( 'timeout' => 30, 'redirection' => 0 ) ); return array( 'code' => is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r ), 'body' => is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r ), 'type' => is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_header( $r, 'content-type' ), 'etag' => is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_header( $r, 'etag' ), 'nosniff' => is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_header( $r, 'x-content-type-options' ) ); };
$no_mail = static fn() => true; add_filter( 'pre_wp_mail', $no_mail );
try {
	wp_set_current_user( $admin ); $admin_jar = $jar( $admin ); $_COOKIE[ LOGGED_IN_COOKIE ] = $admin_jar['login'];
	$ok( 'site-code tools are restricted to a capable administrator', Site_Tools::can_manage() );
	$ok( 'global code output defaults off and no legacy code is imported automatically', false === Site_Tools::settings()['enabled'] && array() === Site_Tools::settings()['snippets'] );
	foreach ( array( 'customer', 'shop_manager', 'pc_staff' ) as $role ) { $u = wp_insert_user( array( 'user_login' => $tag . '-' . $role, 'user_pass' => wp_generate_password( 32 ), 'role' => $role ) ); if ( is_wp_error( $u ) ) { throw new RuntimeException( 'Synthetic user creation failed.' ); } $users[ $role ] = (int) $u; }
	PeptideCore\Staff::set_modules( $users['pc_staff'], array( 'settings', 'design' ) );
	foreach ( $users as $role => $u ) { wp_set_current_user( $u ); $ok( $role . ' cannot change code, files or phrases through the service API', ! Site_Tools::can_manage() && is_wp_error( Site_Tools::save_settings( array( 'enabled' => true ) ) ) && is_wp_error( Site_Tools::save_file( array() ) ) && is_wp_error( Site_Tools::save_phrase( array() ) ) ); }
	wp_set_current_user( $admin );
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $tag, 'post_name' => $tag, 'post_content' => '<p>Synthetic site tools regression page</p>' ) ); $posts[] = $page;
	$revision = Site_Tools::revision( Site_Tools::OPTION );
	$head = Site_Tools::save_snippet( array( 'name' => $tag . ' head', 'type' => 'js', 'code' => 'window.__pcToolsHead = "' . $tag . '-head";', 'location' => 'head', 'scope' => 'selected', 'targets' => array( $page ), 'enabled' => true ), $revision );
	$ok( 'snippets persist valid JS and explicit target pages while the global gate stays off', ! is_wp_error( $head ) && ! Site_Tools::settings()['enabled'] && 1 === count( $head['snippets'] ) );
	$conflict = Site_Tools::save_settings( array( 'enabled' => true ), $revision );
	$ok( 'stale option revision cannot overwrite a newer code change', is_wp_error( $conflict ) && 409 === $conflict->get_error_data()['status'] && ! Site_Tools::settings()['enabled'] );
	$ok( 'script tag breakout and invalid scope are rejected', is_wp_error( Site_Tools::save_snippet( array( 'name' => 'bad', 'type' => 'js', 'code' => '</script><img src=x>', 'scope' => 'all' ) ) ) && is_wp_error( Site_Tools::save_snippet( array( 'name' => 'bad', 'type' => 'js', 'code' => '1;', 'scope' => 'unknown' ) ) ) );
	$ok( 'selected-page scope requires at least one existing supported page', is_wp_error( Site_Tools::save_snippet( array( 'name' => 'bad', 'type' => 'js', 'code' => '1;', 'scope' => 'selected' ) ) ) );
	Site_Tools::save_snippet( array( 'name' => $tag . ' footer', 'type' => 'js', 'code' => 'window.__pcToolsFoot = "' . $tag . '-foot";', 'location' => 'footer', 'scope' => 'selected', 'targets' => array( $page ), 'enabled' => true ) );
	Site_Tools::save_snippet( array( 'name' => $tag . ' CSS', 'type' => 'css', 'code' => '/* ' . $tag . '-css */ :root { --pc-tools-smoke:1; }', 'location' => 'head', 'scope' => 'selected', 'targets' => array( $page ), 'enabled' => true ) );
	Site_Tools::save_snippet( array( 'name' => $tag . ' disabled', 'type' => 'js', 'code' => 'window.__pcToolsDisabled="' . $tag . '-disabled";', 'scope' => 'selected', 'targets' => array( $page ) ) );
	Site_Tools::save_settings( array( 'enabled' => true ) );
	$live = $http( get_permalink( $page ) );
	$ok( 'selected frontend page outputs enabled JS/CSS in the requested head and footer positions', 200 === $live['code'] && strpos( $live['body'], $tag . '-head' ) < strpos( $live['body'], '</head>' ) && strpos( $live['body'], $tag . '-foot' ) > strpos( $live['body'], '</main>' ) && str_contains( $live['body'], $tag . '-css' ) );
	$ok( 'single snippet instances are not duplicated and disabled snippets remain absent', 1 === substr_count( $live['body'], $tag . '-head' ) && 1 === substr_count( $live['body'], $tag . '-foot' ) && ! str_contains( $live['body'], $tag . '-disabled' ) );
	$home = $http( home_url( '/' ) ); $login = $http( wp_login_url() );
	$ok( 'page-scoped code does not leak to the homepage or login screen', ! str_contains( $home['body'], $tag . '-head' ) && ! str_contains( $login['body'], $tag . '-head' ) );
	$admin_page = $http( admin_url( 'admin.php?page=' . Site_Tools_Page::SLUG ), array( 'cookies' => $admin_jar['cookies'] ) );
	$ok( 'administrator tool page renders nonce-protected forms and actual saved records', 200 === $admin_page['code'] && str_contains( $admin_page['body'], 'pc_site_tools_save' ) && str_contains( $admin_page['body'], 'name="_wpnonce"' ) && str_contains( $admin_page['body'], $tag . ' head' ) );
	$ok( 'frontend snippets never execute in the administrator page head', ! str_contains( $admin_page['body'], '<script id="pc-site-snippet-' ) );
	$bad_nonce = $http( admin_url( 'admin-post.php' ), array( 'method' => 'POST', 'cookies' => $admin_jar['cookies'], 'body' => array( 'action' => 'pc_site_tools_save', 'operation' => 'settings', 'enabled' => 1, 'revision' => Site_Tools::revision( Site_Tools::OPTION ) ) ) );
	$ok( 'actual admin save rejects a missing nonce without changing settings', 403 === $bad_nonce['code'] && Site_Tools::settings()['enabled'] );
	$valid_nonce = wp_create_nonce( 'pc_site_tools_save' );
	$saved = $http( admin_url( 'admin-post.php' ), array( 'method' => 'POST', 'cookies' => $admin_jar['cookies'], 'body' => array( 'action' => 'pc_site_tools_save', 'operation' => 'settings', 'enabled' => 1, '_wpnonce' => $valid_nonce, 'revision' => Site_Tools::revision( Site_Tools::OPTION ) ) ) );
	$ok( 'actual administrator save accepts its own valid nonce', 302 === $saved['code'] );
	wp_cache_delete( Site_Tools::OPTION, 'options' ); Site_Tools::save_settings( array( 'enabled' => false ) ); $off = $http( get_permalink( $page ) );
	$ok( 'global off suppresses output without deleting the four saved snippets', ! str_contains( $off['body'], $tag . '-head' ) && 4 === count( Site_Tools::settings()['snippets'] ) );
	foreach ( array( '/wp-admin/index.php', '/wp-json/peptide/v1', '/wp-login.php', '/checkout/pay', '/site-files/../wp-config.php', '/site-files/%2e%2e.txt', '/site-files/x.php', 'https://example.invalid/file.txt', '/robots.txt', '/site-files/evil\\x.txt' ) as $index => $path ) { $ok( 'protected or non-owned virtual path boundary ' . ( $index + 1 ), ! Site_Tools::valid_path( $path ) ); }
	$ok( 'owned text namespace and explicit text endpoints are allowed', Site_Tools::valid_path( '/site-files/store-info.txt' ) && Site_Tools::valid_path( '/ads.txt' ) && Site_Tools::valid_path( '/.well-known/security.txt' ) );
	$path = '/site-files/' . $tag . '.txt';
	$f = Site_Tools::save_file( array( 'path' => $path, 'content' => $tag . '-text' ) ); $file_id = (int) array_key_last( $f );
	$ok( 'new virtual text stays unavailable until explicitly published', null === Site_Tools::resolve_file( $path ) );
	$ok( 'virtual files reject PHP/HTML script content and malformed JSON', is_wp_error( Site_Tools::save_file( array( 'path' => '/site-files/' . $tag . '-bad.txt', 'content' => '<?php echo 1;' ) ) ) && is_wp_error( Site_Tools::save_file( array( 'path' => '/site-files/' . $tag . '-bad.json', 'content' => '{bad}' ) ) ) );
	Site_Tools::save_file( array( 'id' => $file_id, 'path' => $path, 'content' => $tag . '-text', 'enabled' => true ) );
	$ok( 'virtual file cannot collide with another owned file record', is_wp_error( Site_Tools::save_file( array( 'path' => $path, 'content' => 'duplicate' ) ) ) );
	$file = $http( home_url( $path ) );
	$ok( 'actual virtual file is served as text with nosniff without creating a disk file', 200 === $file['code'] && $tag . '-text' === $file['body'] && str_starts_with( $file['type'], 'text/plain' ) && 'nosniff' === $file['nosniff'] && ! file_exists( ABSPATH . ltrim( $path, '/' ) ) );
	$head = $http( home_url( $path ), array( 'method' => 'HEAD' ) ); $cached = $http( home_url( $path ), array( 'headers' => array( 'If-None-Match' => $file['etag'] ) ) ); $post = $http( home_url( $path ), array( 'method' => 'POST' ) );
	$ok( 'virtual file HEAD, ETag and unsupported-method boundaries work over HTTP', 200 === $head['code'] && '' === $head['body'] && 304 === $cached['code'] && 405 === $post['code'] );
	Site_Tools::save_file( array( 'id' => $file_id, 'path' => $path, 'content' => $tag . '-updated', 'enabled' => true ) ); $changed = $http( home_url( $path ), array( 'headers' => array( 'If-None-Match' => $file['etag'] ) ) );
	$ok( 'editing a virtual file changes its content and ETag', 200 === $changed['code'] && $changed['etag'] !== $file['etag'] && $tag . '-updated' === $changed['body'] );
	$phrase = Site_Tools::save_phrase( array( 'title' => $tag . '-reply', 'category' => '测试回复', 'text' => "<b>Hello</b>\nWe will check the order's details." ) ); $phrase_id = (int) array_key_last( $phrase );
	$ok( 'support phrase preserves multiline plain text and strips HTML', "Hello\nWe will check the order's details." === Site_Tools::phrases()[ $phrase_id ]['text'] );
	Site_Tools::save_phrase( array( 'id' => $phrase_id, 'title' => $tag . '-reply-updated', 'text' => 'Updated synthetic reply', 'category' => '测试回复' ) );
	$ok( 'support phrase update retains its identity', $tag . '-reply-updated' === Site_Tools::phrases()[ $phrase_id ]['title'] );
	$_GET = array( 'page' => Site_Tools_Page::SLUG, 'tab' => 'phrases' ); ob_start(); Site_Tools_Page::render(); $html = ob_get_clean();
	$ok( 'phrase UI exposes real copy controls, edit forms and deletion forms', str_contains( $html, 'data-copy-phrase="pc-phrase-' . $phrase_id . '"' ) && str_contains( $html, 'pc_site_tools_delete' ) && str_contains( $html, 'Updated synthetic reply' ) );
	Site_Tools::delete_phrase( $phrase_id ); Site_Tools::delete_file( $file_id ); Site_Tools::delete_snippet( 1 );
	$ok( 'owned snippet/file/phrase deletion is real and keeps unrelated records', ! isset( Site_Tools::phrases()[ $phrase_id ] ) && null === Site_Tools::resolve_file( $path ) && 3 === count( Site_Tools::settings()['snippets'] ) );
} finally {
	wp_set_current_user( $admin );
	foreach ( $posts as $id ) { foreach ( wp_get_post_revisions( $id, array( 'fields' => 'ids' ) ) as $rev ) { wp_delete_post_revision( $rev ); } wp_delete_post( $id, true ); }
	foreach ( $sessions as [ $u, $token ] ) { wp_cache_delete( $u, 'user_meta' ); WP_Session_Tokens::get_instance( $u )->destroy( $token ); }
	require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { wp_delete_user( $id ); }
	foreach ( $snapshot as $option => [ $existed, $value ] ) { wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( $option, 'options' ); $existed ? update_option( $option, $value, false ) : delete_option( $option ); }
	remove_filter( 'pre_wp_mail', $no_mail ); wp_set_current_user( $original_user ); $_COOKIE = $original_cookie; $_GET = $original_get; $_POST = $original_post;
}
$restored = true; foreach ( $snapshot as $option => [ $existed, $value ] ) { wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( $option, 'options' ); $restored = $restored && ( $existed ? get_option( $option ) == $value : false === get_option( $option, false ) ); }
$session_count = 0; foreach ( $sessions as [ $u, $token ] ) { wp_cache_delete( $u, 'user_meta' ); if ( WP_Session_Tokens::get_instance( $u )->verify( $token ) ) { ++$session_count; } }
$ok( 'cleanup restores all three tool options and removes only synthetic pages/users/sessions', $restored && 0 === $session_count && ! array_filter( $posts, static fn( $id ) => (bool) get_post( $id ) ) && ! array_filter( $users, static fn( $id ) => (bool) get_userdata( $id ) ) );
echo "RESULT {$pass} passed, {$fail} failed\n"; if ( $fail ) { throw new RuntimeException( 'Site tools regression failed.' ); }
