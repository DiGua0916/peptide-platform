<?php
/** In-memory HQ registration/routing only. Run with PHP 8.1+ and mbstring; no WP bootstrap. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	class WP_Error { public function __construct( public string $code, public string $message = '', public array $data = array() ) {} }
	class WP_REST_Request { public function __construct( private array $params ) {} public function get_json_params(): array { return $this->params; } }
	function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
	function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
	function trailingslashit( string $s ): string { return rtrim( $s, '/' ) . '/'; }
	function sanitize_key( string $s ): string { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $s ) ); }
	function sanitize_text_field( string $s ): string { return trim( strip_tags( $s ) ); }
	function esc_url_raw( string $s, array $protocols = array() ): string { return $s; }
	function __( string $s, string $domain = '' ): string { return $s; }
	function wp_generate_uuid4(): string { static $n = 0; return '00000000-0000-4000-8000-' . str_pad( (string) ++$n, 12, '0', STR_PAD_LEFT ); }
	function wp_json_encode( $v ): string { return json_encode( $v ); }
	function home_url( string $path = '' ): string { return 'https://hq.example.test' . $path; }
	function add_query_arg( array $query, string $url ): string { return $url . '?' . http_build_query( $query ); }
	function wp_remote_request( string $url, array $args ): array { $GLOBALS['captured_http'][] = array( $url, $args ); return array( 'code' => 200, 'body' => '{"ok":true}' ); }
	function wp_remote_retrieve_response_code( array $r ): int { return $r['code']; }
	function wp_remote_retrieve_body( array $r ): string { return $r['body']; }
	function wp_schedule_single_event( int $when, string $hook, array $args ): void { $GLOBALS['scheduled'][] = array( $hook, $args ); }
	final class Memory_Db {
		public array $rows = array(); public int $insert_id = 0;
		public function prepare( string $sql, ...$args ): string { return preg_replace_callback( '/%[sd]/', static function ( $m ) use ( &$args ) { $v = array_shift( $args ); return '%d' === $m[0] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'"; }, $sql ); }
		public function get_var( string $sql ) {
			if ( preg_match( '/SELECT (key_enc|key_pending) .*WHERE id = (\d+)/', $sql, $m ) ) { return $this->rows[ (int) $m[2] ][ $m[1] ] ?? null; }
			if ( preg_match( "/WHERE (uuid|url) = '([^']*)'/", $sql, $m ) ) {
				preg_match( '/AND id <> (\d+)/', $sql, $except );
				foreach ( $this->rows as $id => $row ) { if ( ( $row[ $m[1] ] ?? '' ) === $m[2] && $id !== (int) ( $except[1] ?? 0 ) ) { return $id; } }
				return null;
			}
			throw new \RuntimeException( 'Unhandled metadata query' );
		}
		public function get_row( string $sql, $format ): ?array { preg_match( '/WHERE id = (\d+)/', $sql, $m ); return $this->rows[ (int) $m[1] ] ?? null; }
		public function get_col( string $sql ): array { preg_match( "/WHERE uuid = '([^']*)'/", $sql, $m ); return array_slice( array_keys( array_filter( $this->rows, static fn( $r ) => ( $r['uuid'] ?? '' ) === $m[1] ) ), 0, 2 ); }
		public function insert( string $table, array $row ): void { $id = ++$this->insert_id; $this->rows[$id] = $row + array( 'id' => $id, 'status' => 'unknown', 'fails' => 0, 'error' => '', 'last_check' => 0, 'last_ok' => 0, 'last_full' => 0 ); }
		public function update( string $table, array $fields, array $where ): void { $this->rows[ $where['id'] ] = array_replace( $this->rows[ $where['id'] ], $fields ); }
	}
	define( 'ARRAY_A', 'ARRAY_A' );
	$GLOBALS['wpdb'] = new Memory_Db();
}
namespace PeptideHq {
	final class Db { public static function table( string $name ): string { return 'memory_' . $name; } }
	final class Crypto { public static function encrypt( string $s ): string { return 'fixture:' . $s; } public static function decrypt( string $s ): string { return substr( $s, 8 ); } }
	final class Monitor { public const POLL_ONE = 'fixture_poll'; }
}
namespace {
	foreach ( array( 'site-urls', 'sites', 'client', 'register' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
	use PeptideHq\Site_Urls;
	use PeptideHq\Sites;
	use PeptideHq\Client;
	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }
	$uuid = '11111111-1111-4111-8111-111111111111'; $key = str_repeat( 'a', 64 );
	$mgmt = 'https://store-a.manager.example.test/'; $public = 'https://shop-a.example.test/';
	foreach ( array( 'http://store.example.test', 'https://user@store.example.test', 'https://store.example.test:8443', 'https://store.example.test/path', 'https://store.example.test?x=1', 'https://store.example.test#x', "https://store.example.test\n", 'https://a..example.test', 'https://-a.example.test', 'https://a.example.test\\evil' ) as $bad ) { check( 'reject malformed root ' . $checks, '' === Site_Urls::https_root( $bad ) ); }
	check( 'canonical HTTPS root and 443', 'https://store.example.test/' === Site_Urls::https_root( 'https://Store.Example.Test:443/' ) );
	$new = Sites::register( $public, $key, 'Store A', $uuid, $mgmt, $public );
	check( 'dual store registers', array( 1, 'new' ) === $new );
	$site = Sites::get( 1 );
	check( 'legacy url remains machine address', $mgmt === $site['url'] && $mgmt === Site_Urls::management( $site ) );
	check( 'public address is independent', $public === Site_Urls::public_url( $site ) );
	check( 'public switch accepted with current key and UUID', array( 1, 'moved' ) === Sites::register( 'https://new-shop.example.test/', $key, 'Store A', $uuid, $mgmt, 'https://new-shop.example.test/' ) );
	$site = Sites::get( 1 );
	Client::status( 1 );
	check( 'HTTP still targets management after public switch', str_starts_with( $GLOBALS['captured_http'][0][0], $mgmt . '?' ) );
	check( 'public login target rejected', ! Site_Urls::allows_login( $site, 'https://new-shop.example.test/?pc_hq_login=fixture' ) );
	check( 'registered management login accepted', Site_Urls::allows_login( $site, $mgmt . '?pc_hq_login=fixture' ) );
	foreach ( array( 'http://store-a.manager.example.test/?x=1', 'https://store-a.manager.example.test:8443/', 'https://user@store-a.manager.example.test/', 'https://store-a.manager.example.test.evil.test/' ) as $bad ) { check( 'reject changed login origin ' . $checks, ! Site_Urls::allows_login( $site, $bad ) ); }
	$before = $GLOBALS['wpdb']->rows[1];
	check( 'wrong UUID rejected', is_wp_error( Sites::register( $mgmt, $key, 'Wrong', '22222222-2222-4222-8222-222222222222', $mgmt, $public ) ) );
	check( 'wrong UUID does not alter row', $before === $GLOBALS['wpdb']->rows[1] );
	check( 'changed key remains pending', array( 1, 'pending' ) === Sites::register( $mgmt, str_repeat( 'b', 64 ), 'Store A', $uuid, $mgmt, $public ) );
	check( 'pending key cannot update either address', $before['url'] === $GLOBALS['wpdb']->rows[1]['url'] && $before['public_url'] === $GLOBALS['wpdb']->rows[1]['public_url'] );
	check( 'legacy client cannot replace pinned manager', array( 1, 'same' ) === Sites::register( $public, $key, 'Store A', $uuid ) && $mgmt === Sites::get( 1 )['url'] );
	check( 'partial public-only registration rejected', is_wp_error( Sites::register( $public, $key, 'Store A', $uuid, null, $public ) ) );
	check( 'partial UUID rejected', is_wp_error( Sites::register( $public, $key, 'Store A', '1111', $mgmt, $public ) ) );
	$legacy = Sites::register( 'http://127.0.0.1:18080/shop/', str_repeat( 'c', 64 ), 'Legacy', 'legacy-id' );
	check( 'legacy registration preserved', array( 2, 'new' ) === $legacy );
	$old = Sites::get( 2 );
	check( 'old rows fall back to original address', ! $old['dual_domain'] && $old['url'] === $old['management_url'] && $old['url'] === $old['public_url'] );
	check( 'legacy installation path accepted', Site_Urls::allows_login( $old, 'http://127.0.0.1:18080/shop/?pc_hq_login=fixture' ) );
	check( 'legacy sibling path rejected', ! Site_Urls::allows_login( $old, 'http://127.0.0.1:18080/other/' ) );
	check( 'legacy path traversal rejected', ! Site_Urls::allows_login( $old, 'http://127.0.0.1:18080/shop/%2e%2e/other/' ) );
	check( 'legacy URL move still works', array( 2, 'moved' ) === Sites::register( 'http://127.0.0.1:18081/shop/', str_repeat( 'c', 64 ), 'Legacy', 'legacy-id' ) );
	$bad = PeptideHq\Register::handle( new WP_REST_Request( array( 'url' => $mgmt, 'key' => $key, 'uuid' => $uuid, 'management_url' => array() ) ) );
	check( 'payload non-string address rejected', is_wp_error( $bad ) );
	$GLOBALS['wpdb']->rows[3] = array_replace( $GLOBALS['wpdb']->rows[1], array( 'id' => 3, 'url' => 'https://duplicate.manager.example.test/' ) );
	$before_duplicates = $GLOBALS['wpdb']->rows;
	check( 'duplicate historical UUID rejects registration', is_wp_error( Sites::register( $mgmt, $key, 'Store A', $uuid, $mgmt, $public ) ) );
	check( 'duplicate UUID changes no row or pending key', $before_duplicates === $GLOBALS['wpdb']->rows );
	echo 'PASS ', $checks, " in-memory HQ management checks; no WordPress, database, or network.\n";
}
