<?php
/** Actual theme capability/enqueue boundary, no WordPress bootstrap. */
namespace {
	$mode043 = $argv[1] ?? '';
	if ( '' === $mode043 ) {
		$total043 = 0;
		foreach ( array( 'enabled', 'no_helper', 'old_context', 'no_clean', 'no_saved', 'wrong_version', 'private' ) as $variant043 ) {
			$p043 = proc_open( array( PHP_BINARY, '-n', __FILE__, $variant043 ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes043 ); if ( ! is_resource( $p043 ) ) { throw new \RuntimeException( 'Cannot start capability fixture.' ); }
			fclose( $pipes043[0] ); $out043 = stream_get_contents( $pipes043[1] ); $err043 = stream_get_contents( $pipes043[2] ); fclose( $pipes043[1] ); fclose( $pipes043[2] ); $exit043 = proc_close( $p043 ); echo $out043;
			if ( 0 !== $exit043 || '' !== $err043 || ! preg_match( '/RESULT (\d+) PASS/', $out043, $m043 ) ) { fwrite( STDERR, $err043 ); exit( 1 ); } $total043 += (int) $m043[1];
		} echo "TOTAL {$total043} PASS, 0 FAIL\n"; exit;
	}
	define( 'ABSPATH', __DIR__ . '/' );
}
namespace PeptideCore\Tracking {
	if ( in_array( $GLOBALS['mode043'], array( 'enabled', 'private', 'no_helper', 'no_clean', 'no_saved' ), true ) ) { require dirname( __DIR__, 3 ) . '/plugins/peptide-core/includes/tracking/class-page-context.php'; }
	elseif ( 'old_context' === $GLOBALS['mode043'] ) { final class Page_Context { public static function clean() {} public static function replay() {} } }
	else { final class Page_Context { public static function clean() {} public static function replay() {} public static function client_environment_protocol() { return 2; } } }
	if ( in_array( $GLOBALS['mode043'], array( 'enabled', 'private', 'old_context', 'wrong_version' ), true ) ) { require dirname( __DIR__, 3 ) . '/plugins/peptide-core/includes/tracking/class-client-environment.php'; }
	elseif ( 'no_clean' === $GLOBALS['mode043'] ) { final class Client_Environment { public static function saved() {} } }
	elseif ( 'no_saved' === $GLOBALS['mode043'] ) { final class Client_Environment { public static function clean() {} } }
}
namespace {
	$hooks043 = array(); $inline043 = array();
	function add_action( $key, $fn, $priority = 10 ): void { $GLOBALS['hooks043'][$key] = $fn; }
	function apply_filters( $key, $value ) { return array( 'enabled' => true, 'protocol' => 1, 'endpoint' => 'https://shop.test/wp-json/peptide/v1/track/events' ); }
	function is_admin(): bool { return false; } function is_preview(): bool { return false; } function is_customize_preview(): bool { return false; }
	function current_user_can( $cap ): bool { return 'private' === $GLOBALS['mode043']; }
	function is_wc_endpoint_url( $value ): bool { return false; } function is_account_page(): bool { return false; } function is_product(): bool { return false; } function is_cart(): bool { return false; } function is_checkout(): bool { return false; } function is_front_page(): bool { return true; } function is_shop(): bool { return false; } function is_product_taxonomy(): bool { return false; } function is_singular(): bool { return false; } function is_search(): bool { return false; } function is_archive(): bool { return false; }
	function wp_script_is( ...$args ): bool { return false; }
	function get_template_directory(): string { return dirname( __DIR__ ); } function get_template_directory_uri(): string { return 'https://shop.test/theme'; }
	function esc_url_raw( $url ) { return $url; } function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	function wp_enqueue_script( ...$args ): void {} function wp_add_inline_script( $handle, $code, $position ): void { $GLOBALS['inline043'][$handle] = $code; }
	$_SERVER = array( 'REMOTE_ADDR' => '127.0.0.1' ); $_GET = array();
	require dirname( __DIR__ ) . '/inc/visitor-tracking.php'; ( $hooks043['wp_enqueue_scripts'] )();
	$code043 = $inline043['vialcove-visitor-adapter'] ?? ''; $transport043 = $code043 ? json_decode( substr( $code043, strlen( 'window.VialCoveVisitorConfig = ' ), -1 ), true ) : array();
	$expected043 = 'enabled' === $mode043;
	if ( $expected043 ? 1 !== ( $transport043['client_environment_protocol'] ?? null ) : array_key_exists( 'client_environment_protocol', $transport043 ) ) { throw new \RuntimeException( 'Capability gate mismatch.' ); }
	if ( 'private' === $mode043 && $inline043 ) { throw new \RuntimeException( 'Private document started tracking.' ); }
	echo "RESULT 1 PASS\n";
}
