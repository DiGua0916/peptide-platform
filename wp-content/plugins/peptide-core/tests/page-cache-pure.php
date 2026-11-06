<?php
/**
 * Stress test item 2: opt-in homepage cache. Real Page_Cache with WordPress,
 * language and tracker boundaries stubbed; storage uses a temporary directory.
 * Child processes run the serve path because a hit ends the request with exit.
 */
namespace {
	$mode = $argv[1] ?? 'main';
	$dir = $argv[2] ?? ( sys_get_temp_dir() . '/pc-page-cache-' . bin2hex( random_bytes( 4 ) ) );
	define( 'ABSPATH', __DIR__ . '/' ); define( 'WP_CONTENT_DIR', $dir ); define( 'AUTH_SALT', 'synthetic-salt' );
	define( 'PEPTIDE_CORE_PAGE_CACHE', true ); define( 'PEPTIDE_CORE_PAGE_CACHE_TTL', 300 );
	$GLOBALS['options'] = array(); $GLOBALS['hooks'] = array(); $GLOBALS['front'] = true; $GLOBALS['mobile'] = false; $GLOBALS['pixels'] = false; $GLOBALS['lang'] = array( 'active' => false, 'language' => 'en', 'country_code' => 'US' );
	function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][ $hook ][] = array( $cb, $p ); }
	function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
	function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
	function home_url( $p = '' ) { return 'https://shop.example/' . ltrim( $p, '/' ); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function is_ssl() { return true; } function get_locale() { return 'en_US'; } function wp_is_mobile() { return $GLOBALS['mobile']; }
	function is_front_page() { return $GLOBALS['front']; } function is_paged() { return false; } function is_user_logged_in() { return false; }
	function is_admin() { return false; } function is_preview() { return false; } function is_customize_preview() { return false; }
	function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
	function wp_script_is( $h, $s ) { return 'peptide-core-pixels' === $h && $GLOBALS['pixels']; }
	function WC() { return (object) array( 'customer' => null ); }
	function check( string $label, bool $ok ): void { global $checks; if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } $checks = ( $checks ?? 0 ) + 1; }
}
namespace PeptideCore {
	final class Storefront_Language { public const PREFERENCE_COOKIE = 'pc_storefront_language'; }
	final class Storefront_Translation { public static function selected(): array { return $GLOBALS['lang']; } public static function active(): bool { return $GLOBALS['lang']['active']; } public static function code(): string { return $GLOBALS['lang']['language']; } }
}
namespace PeptideCore\Tracking { final class Tracker { public const COOKIE = 'pc_vid'; public static function declined(): bool { return ! empty( $GLOBALS['gpc'] ); } } }
namespace {
	require dirname( __DIR__ ) . '/includes/class-page-cache.php';
	use PeptideCore\Page_Cache as Cache;
	$page = '<!doctype html><html><head><title>Home</title></head><body>' . str_repeat( 'catalogue ', 80 ) . '</body></html>';
	$ok_headers = array( 'Content-Type: text/html; charset=UTF-8', 'Cache-Control: private, no-store, max-age=0', 'Vary: Cookie', 'Link: </font.woff2>; rel=preload', 'Set-Cookie: pc_vid=abc; path=/; HttpOnly', 'X-Powered-By: PHP' );
	$_SERVER = array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'QUERY_STRING' => '', 'HTTP_HOST' => 'shop.example' );

	if ( 'serve' === $mode ) {
		// A cookie-less first visit: Tracker (template_redirect 1) has already issued pc_vid into $_COOKIE.
		$_COOKIE = array( 'pc_vid' => 'issued-this-request' );
		Cache::reset_for_tests( array() );
		Cache::serve_or_capture();
		echo "NO-HIT\n"; exit( 0 );
	}

	$base = array( 'method' => 'GET', 'path' => '/', 'home_path' => '/', 'query' => '', 'cookies' => array(), 'front' => true );
	check( 'cookie-less anonymous GET / eligible', Cache::eligible( $base ) );
	check( 'HEAD eligible', Cache::eligible( array( 'method' => 'HEAD' ) + $base ) );
	check( 'language preference cookie alone eligible', Cache::eligible( array( 'cookies' => array( 'pc_storefront_language' ) ) + $base ) );
	foreach ( array(
		'POST' => array( 'method' => 'POST' ), 'query string (utm/gclid/pc_lang)' => array( 'query' => 'gclid=x' ), 'other path' => array( 'path' => '/shop/' ),
		'not front page' => array( 'front' => false ), 'paged' => array( 'paged' => true ), 'logged in' => array( 'logged_in' => true ), 'preview' => array( 'preview' => true ),
		'DONOTCACHEPAGE' => array( 'donotcache' => true ), 'returning visitor id' => array( 'cookies' => array( 'pc_vid' ) ),
		'woo session' => array( 'cookies' => array( 'wp_woocommerce_session_abc' ) ), 'cart' => array( 'cookies' => array( 'woocommerce_items_in_cart' ) ),
		'login' => array( 'cookies' => array( 'wordpress_logged_in_abc' ) ), 'recent products' => array( 'cookies' => array( 'pc_recent_products' ) ),
		'pixel opt-out' => array( 'cookies' => array( 'pc_px_optout' ) ), 'analytics' => array( 'cookies' => array( '_ga' ) ),
	) as $label => $change ) { check( "bypass: $label", ! Cache::eligible( $change + $base ) ); }

	$h = Cache::storable_headers( $ok_headers, 200, $page );
	check( 'complete 200 HTML storable', is_array( $h ) );
	$names = array_map( fn( $row ) => strtolower( $row[0] ), $h );
	check( 'replays content headers and private no-store', in_array( 'content-type', $names, true ) && in_array( 'cache-control', $names, true ) && in_array( 'link', $names, true ) );
	check( 'never replays Set-Cookie or X-Powered-By', ! in_array( 'set-cookie', $names, true ) && ! in_array( 'x-powered-by', $names, true ) );
	check( 'other cookie set → not stored', null === Cache::storable_headers( array_merge( $ok_headers, array( 'Set-Cookie: pc_recent_products=1' ) ), 200, $page ) );
	check( 'non-200 not stored', null === Cache::storable_headers( $ok_headers, 404, $page ) );
	check( 'non-HTML not stored', null === Cache::storable_headers( array( 'Content-Type: application/json' ), 200, $page ) );
	check( 'truncated HTML not stored', null === Cache::storable_headers( $ok_headers, 200, substr( $page, 0, -10 ) ) );
	$GLOBALS['pixels'] = true; check( 'ad pixels enqueued → not stored (per-render event IDs)', null === Cache::storable_headers( $ok_headers, 200, $page ) ); $GLOBALS['pixels'] = false;

	$v = Cache::variant(); $k = Cache::key( $v );
	check( 'key is deterministic', $k === Cache::key( Cache::variant() ) && 64 === strlen( $k ) );
	$GLOBALS['mobile'] = true; check( 'device changes key', $k !== Cache::key( Cache::variant() ) ); $GLOBALS['mobile'] = false;
	$GLOBALS['lang'] = array( 'active' => true, 'language' => 'es', 'country_code' => 'PR' ); check( 'language/country change key', $k !== Cache::key( Cache::variant() ) );
	$GLOBALS['lang'] = array( 'active' => false, 'language' => 'en', 'country_code' => 'US' );
	$GLOBALS['gpc'] = true; check( 'GPC/declined tracking changes key', $k !== Cache::key( Cache::variant() ) ); $GLOBALS['gpc'] = false;
	$_SERVER['HTTP_HOST'] = 'other.example'; check( 'host changes key', $k !== Cache::key( Cache::variant() ) ); $_SERVER['HTTP_HOST'] = 'shop.example';

	Cache::option_changed( '_transient_feed' ); Cache::option_changed( 'cron' ); Cache::option_changed( 'pc_tracking_lock' ); Cache::option_changed( 'woocommerce_session_x' );
	check( 'transient/cron/lock/session writes do not flush', '0' === (string) get_option( Cache::GENERATION, '0' ) );
	Cache::option_changed( 'pc_home_layout' ); check( 'storefront option flushes', '1' === get_option( Cache::GENERATION ) );
	Cache::option_changed( 'theme_mods_peptide-site' ); Cache::option_changed( 'woocommerce_currency' ); check( 'theme/woo options flush', '3' === get_option( Cache::GENERATION ) );
	check( 'flush changes key', $k !== Cache::key( Cache::variant() ) );
	$GLOBALS['options'] = array();

	Cache::register_hooks();
	$serve = $GLOBALS['hooks']['template_redirect'][0] ?? null;
	check( 'serves after all existing template_redirect hooks (max 99)', is_array( $serve ) && $serve[1] > 99 );
	check( 'product/stock changes flush', isset( $GLOBALS['hooks']['woocommerce_product_set_stock'], $GLOBALS['hooks']['save_post'], $GLOBALS['hooks']['woocommerce_update_product'] ) );

	// Miss → capture stores the page; a later cookie-less visit is served it (child process: hit exits).
	$_COOKIE = array( 'pc_vid' => 'issued-this-request' );
	Cache::reset_for_tests( array(), array( $ok_headers, 200 ) );
	$level = ob_get_level(); Cache::serve_or_capture(); check( 'miss opens one capture buffer', ob_get_level() === $level + 1 );
	echo $page; $returned = ob_get_clean(); // ob_get_clean does not call the handler; call it as PHP would at flush:
	check( 'capture returns page unchanged', $page === Cache::capture( $returned, PHP_OUTPUT_HANDLER_FINAL ) );
	$files = glob( $dir . '/cache/peptide-core-pages/*.php' );
	check( 'one stored copy plus silence index', 2 === count( $files ) && file_exists( $dir . '/cache/peptide-core-pages/index.php' ) );
	$stored = array_values( array_filter( $files, fn( $f ) => ! str_ends_with( $f, 'index.php' ) ) )[0];
	check( 'stored copy starts with PHP exit guard', str_starts_with( file_get_contents( $stored ), "<?php exit; ?>\n" ) );

	$run = static function ( array $env = array() ) use ( $dir ) {
		$p = proc_open( array( PHP_BINARY, __FILE__, 'serve', $dir ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env ?: null );
		$out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); proc_close( $p ); return array( $out, $err );
	};
	[ $out, $err ] = $run();
	check( 'next cookie-less visit is served the stored page and stops rendering', $page === $out && '' === $err );

	// Expired copies are ignored.
	$raw = file_get_contents( $stored ); $entry = unserialize( substr( $raw, strlen( "<?php exit; ?>\n" ) ) ); $entry['created'] = time() - 301;
	file_put_contents( $stored, "<?php exit; ?>\n" . serialize( $entry ) );
	[ $out ] = $run(); check( 'expired copy is not served', "NO-HIT\n" === $out );
	// Corrupt copies are ignored.
	file_put_contents( $stored, 'garbage' ); [ $out ] = $run(); check( 'corrupt copy is not served', "NO-HIT\n" === $out );

	// A page that set another cookie is never stored.
	array_map( 'unlink', glob( $dir . '/cache/peptide-core-pages/*' ) );
	Cache::reset_for_tests( array(), array( array_merge( $ok_headers, array( 'Set-Cookie: pc_recent_history_v1=x' ) ), 200 ) );
	Cache::serve_or_capture(); echo $page; $returned = ob_get_clean(); Cache::capture( $returned, PHP_OUTPUT_HANDLER_FINAL );
	check( 'personalised response not stored', array() === array_filter( glob( $dir . '/cache/peptide-core-pages/*.php' ), fn( $f ) => ! str_ends_with( $f, 'index.php' ) ) );

	// A returning visitor (browser sent pc_vid) never opens a capture.
	Cache::reset_for_tests( array( 'pc_vid' ), array( $ok_headers, 200 ) ); $level = ob_get_level(); Cache::serve_or_capture();
	check( 'returning visitor bypasses (no capture buffer)', ob_get_level() === $level );

	array_map( 'unlink', array_filter( glob( $dir . '/cache/peptide-core-pages/{,.}*', GLOB_BRACE ) ?: array(), 'is_file' ) ); @rmdir( $dir . '/cache/peptide-core-pages' ); @rmdir( $dir . '/cache' ); @rmdir( $dir );
	echo "PASS {$checks} page cache checks\n";
}
