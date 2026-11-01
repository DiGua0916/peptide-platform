<?php
/** Actual PHP theme capability and normalization; no WordPress bootstrap. */
namespace {
	$mode046 = $argv[1] ?? '';
	if ( '' === $mode046 ) {
		$total046 = 0;
		foreach ( array( 'enabled', 'no_helper', 'old_context', 'no_source', 'no_clean', 'no_saved', 'wrong_version', 'private', 'nonsearch' ) as $variant046 ) {
			$process046 = proc_open( array( PHP_BINARY, '-c', 'D:/Projects/peptide-test2/bin/php.ini', __FILE__, $variant046 ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes046 );
			if ( ! is_resource( $process046 ) ) { throw new \RuntimeException( 'Cannot start capability boundary.' ); }
			fclose( $pipes046[0] ); $out046 = stream_get_contents( $pipes046[1] ); $err046 = stream_get_contents( $pipes046[2] ); fclose( $pipes046[1] ); fclose( $pipes046[2] ); $exit046 = proc_close( $process046 );
			if ( $exit046 || $err046 || ! preg_match( '/RESULT (\d+) PASS/', $out046, $match046 ) ) { fwrite( STDERR, $out046 . $err046 ); exit( 1 ); } $total046 += (int) $match046[1];
		} echo "PASS $total046 native search capability assertions\n"; exit;
	}
	define( 'ABSPATH', __DIR__ . '/' );
}
namespace PeptideCore\Tracking {
	if ( $GLOBALS['mode046'] === 'old_context' ) { final class Page_Context { public static function clean() {} public static function saved() {} public static function replay() {} } }
	elseif ( $GLOBALS['mode046'] === 'wrong_version' ) { final class Page_Context { public static function clean() {} public static function saved() {} public static function replay() {} public static function search_protocol() { return 2; } } }
	else { require dirname( __DIR__, 3 ) . '/plugins/peptide-core/includes/tracking/class-page-context.php'; }
	if ( $GLOBALS['mode046'] === 'no_source' ) { final class Search_Context { public static function clean() {} public static function saved() {} } }
	elseif ( $GLOBALS['mode046'] === 'no_clean' ) { final class Search_Context { public static function source() {} public static function saved() {} } }
	elseif ( $GLOBALS['mode046'] === 'no_saved' ) { final class Search_Context { public static function clean() {} public static function source() {} } }
	elseif ( $GLOBALS['mode046'] !== 'no_helper' ) { require dirname( __DIR__, 3 ) . '/plugins/peptide-core/includes/tracking/class-search-context.php'; }
	require dirname( __DIR__, 3 ) . '/plugins/peptide-core/includes/tracking/class-client-environment.php';
	final class Impressions { public static function clean() {} public static function saved() {} public static function replay_code() {} }
	final class Country_Provider { public static function lookup( $address ) { return array( 'status' => 'known', 'country_code' => 'US' ); } }
}
namespace {
	$hooks046 = array(); $inline046 = array(); $reads046 = 0; $enqueued046 = array();
	function add_action( $key, $fn, $priority = 10 ) { $GLOBALS['hooks046'][$key] = $fn; }
	function apply_filters( $key, $value ) { return array( 'enabled' => true, 'protocol' => 1, 'endpoint' => 'https://shop.test/events' ); }
	function is_admin() { return false; } function is_preview() { return false; } function is_customize_preview() { return false; } function current_user_can( $cap ) { return $GLOBALS['mode046'] === 'private'; }
	function is_wc_endpoint_url( $value ) { return false; } function is_account_page() { return false; } function is_product() { return false; } function is_cart() { return false; } function is_checkout() { return false; } function is_front_page() { return false; } function is_shop() { return false; } function is_product_taxonomy() { return false; } function is_singular() { return false; } function is_archive() { return false; } function is_search() { return $GLOBALS['mode046'] !== 'nonsearch'; }
	function get_search_query( $escaped = true ) { ++$GLOBALS['reads046']; if ( $escaped ) { throw new \RuntimeException( 'Native query must be unescaped before normalization.' ); } return " Demo\u{3000}Peptide "; }
	function wp_parse_url( $url ) { return parse_url( $url ); } function home_url( $path = '' ) { return 'https://shop.test' . $path; }
	function wp_script_is( ...$args ) { return false; } function get_template_directory() { return dirname( __DIR__ ); } function get_template_directory_uri() { return 'https://shop.test/theme'; }
	function esc_url_raw( $url ) { return $url; } function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	function wp_enqueue_script( $handle, ...$args ) { $GLOBALS['enqueued046'][] = $handle; } function wp_add_inline_script( $handle, $code, $position ) { $GLOBALS['inline046'][$handle] = $code; }
	$_SERVER = array( 'REMOTE_ADDR' => '8.8.8.8' ); $_GET = array();
	require dirname( __DIR__ ) . '/inc/visitor-tracking.php'; ( $hooks046['wp_enqueue_scripts'] )();
	$code046 = $inline046['vialcove-visitor-adapter'] ?? ''; $config046 = $code046 ? json_decode( substr( $code046, strlen( 'window.VialCoveVisitorConfig = ' ), -1 ), true ) : array();
	$enabled046 = in_array( $mode046, array( 'enabled', 'nonsearch' ), true );
	if ( $enabled046 ? ( $config046['search_terms_protocol'] ?? null ) !== 1 : isset( $config046['search_terms_protocol'] ) ) { throw new \RuntimeException( 'Search capability mismatch.' ); }
	if ( $reads046 !== ( $mode046 === 'enabled' ? 1 : 0 ) ) { throw new \RuntimeException( 'Native query read without both capability and real search.' ); }
	if ( $mode046 === 'enabled' && ( $config046['page_observation']['context']['search'] ?? null ) !== array( 'version' => 1, 'status' => 'known', 'term' => 'demo peptide' ) ) { throw new \RuntimeException( 'Native term not normalized before JSON.' ); }
	if ( $mode046 === 'private' ? $inline046 : ( $config046['page_observation']['context']['geo'] ?? null ) !== array( 'status' => 'known', 'country_code' => 'US' ) || ( $config046['product_impression_protocol'] ?? null ) !== 1 || ! in_array( 'vialcove-visitor-products', $enqueued046, true ) ) { throw new \RuntimeException( 'Privacy/product/country source regression.' ); }
	if ( $mode046 === 'enabled' && ( $config046['client_environment_protocol'] ?? null ) !== 1 ) { throw new \RuntimeException( 'Environment capability lost.' ); }
	echo "RESULT 5 PASS\n";
}
