<?php
/** Actual provider/theme helper, isolated WordPress/filesystem/Reader boundaries; no bootstrap. */
declare(strict_types=1);
namespace {
	if ( PHP_SAPI !== 'cli' ) { exit( 2 ); }
	$mode040 = $argv[1] ?? '';
	if ( '' === $mode040 ) {
		$total040 = 0; $skipped040 = 0;
		foreach ( array( 'fallback', 'provider', 'missing_library', 'constant_valid', 'constant_bad', 'constant_empty', 'real_reader' ) as $variant040 ) {
			$process040 = proc_open( array( PHP_BINARY, '-n', __FILE__, $variant040 ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes040 );
			if ( ! is_resource( $process040 ) ) { throw new \RuntimeException( 'Cannot start focused country test.' ); }
			fclose( $pipes040[0] ); $out040 = stream_get_contents( $pipes040[1] ); $error040 = stream_get_contents( $pipes040[2] ); fclose( $pipes040[1] ); fclose( $pipes040[2] ); $exit040 = proc_close( $process040 );
			echo $out040;
			if ( 0 !== $exit040 || '' !== $error040 || ! preg_match( '/RESULT (\d+) PASS, (\d+) SKIP/', $out040, $counts040 ) ) { fwrite( STDERR, $error040 ); exit( 1 ); }
			$total040 += (int) $counts040[1]; $skipped040 += (int) $counts040[2];
		}
		echo "TOTAL {$total040} PASS, 0 FAIL, {$skipped040} SKIP\n"; exit;
	}
	if ( 'real_reader' === $mode040 ) { echo "SKIP actual MMDB lookup: no existing trusted fixture was supplied or adopted.\nRESULT 0 PASS, 1 SKIP\n"; exit; }
	define( 'ABSPATH', __DIR__ . '/' );
	if ( 'constant_valid' === $mode040 ) { define( 'PC_DATA_GEOIP_DATABASE', 'D:/SyntheticCountry/Configured.mmdb' ); }
	if ( 'constant_bad' === $mode040 ) { define( 'PC_DATA_GEOIP_DATABASE', 'https://unconfigured.example.invalid/country.mmdb' ); }
	if ( 'constant_empty' === $mode040 ) { define( 'PC_DATA_GEOIP_DATABASE', '' ); }
	if ( 'missing_library' === $mode040 ) { define( 'WC_ABSPATH', 'D:/SyntheticCountry/Woo/' ); }
	$fixture040 = array(); $calls040 = array(); $checks040 = 0; $forbidden_total040 = 0;
	function reset040( array $changes = array() ): void {
		$GLOBALS['fixture040'] = array_merge( array( 'settings' => array(), 'uploads' => array( 'basedir' => 'D:/SyntheticCountry/Uploads', 'error' => false ), 'exists' => true, 'readable' => true, 'record' => array( 'country' => array( 'iso_code' => 'US' ) ), 'countries' => array( 'US' => 'United States', 'CN' => 'China', 'DE' => 'Germany' ) ), $changes );
		$GLOBALS['calls040'] = array( 'options' => array(), 'uploads' => array(), 'filters' => array(), 'files' => 0, 'constructed' => 0, 'get' => 0, 'close' => 0, 'catalog' => 0, 'forbidden' => 0 );
		$_SERVER = array( 'REMOTE_ADDR' => '8.8.8.8' );
	}
	function check040( string $label, bool $yes ): void { if ( ! $yes ) { fwrite( STDERR, 'FAIL ' . $label . "\n" ); exit( 1 ); } ++$GLOBALS['checks040']; }
	function get_option( $name, $default = false ) { $GLOBALS['calls040']['options'][] = $name; if ( 'woocommerce_maxmind_geolocation_settings' !== $name ) { throw new \RuntimeException( 'Unexpected settings read.' ); } return $GLOBALS['fixture040']['settings']; }
	function wp_upload_dir( $time = null, $create = true ) { $GLOBALS['calls040']['uploads'][] = array( $time, $create ); if ( $create ) { ++$GLOBALS['calls040']['forbidden']; ++$GLOBALS['forbidden_total040']; throw new \RuntimeException( 'Directory creation forbidden.' ); } return $GLOBALS['fixture040']['uploads']; }
	function apply_filters( $name, $value ) { $GLOBALS['calls040']['filters'][] = array( $name, $value ); return $GLOBALS['fixture040']['filter_path'] ?? $value; }
	function forbidden040() { ++$GLOBALS['calls040']['forbidden']; ++$GLOBALS['forbidden_total040']; throw new \RuntimeException( 'Forbidden side effect.' ); }
	function update_option( ...$args ) { return forbidden040(); }
	function add_option( ...$args ) { return forbidden040(); }
	function wp_mkdir_p( ...$args ) { return forbidden040(); }
	function wp_remote_get( ...$args ) { return forbidden040(); }
	function wp_remote_post( ...$args ) { return forbidden040(); }
	function wp_schedule_event( ...$args ) { return forbidden040(); }
	function wc_get_logger() { return forbidden040(); }
	class WC_Geolocation { public static function geolocate_ip( ...$args ) { return forbidden040(); } }
	class WC_Integration_MaxMind_Geolocation { public function __construct() { forbidden040(); } }
	function WC() { return (object) array( 'countries' => new class {
		public function get_countries() { ++$GLOBALS['calls040']['catalog']; if ( ! empty( $GLOBALS['fixture040']['catalog_throw'] ) ) { throw new \RuntimeException( 'Synthetic catalog failure.' ); } return $GLOBALS['fixture040']['countries']; }
		public function get_allowed_countries() { return forbidden040(); }
	} ); }
	function add_action( ...$args ): void {}
	function is_wc_endpoint_url( $value ): bool { return false; }
	function is_account_page(): bool { return false; }
	function is_product(): bool { return false; }
	function is_cart(): bool { return false; }
	function is_checkout(): bool { return false; }
	function is_front_page(): bool { return true; }
	function is_singular(): bool { return false; }
	function is_shop(): bool { return false; }
	function is_product_taxonomy(): bool { return false; }
	function is_archive(): bool { return false; }
	function is_search(): bool { return false; }
}
namespace PeptideCore\Tracking {
	// Only read-side filesystem boundaries are doubled; production has no test hooks.
	function is_file( $path ): bool { ++$GLOBALS['calls040']['files']; return str_ends_with( $path, '.mmdb' ) && $GLOBALS['fixture040']['exists']; }
	function is_readable( $path ): bool { return $GLOBALS['fixture040']['readable']; }
	function realpath( $path ) { return $GLOBALS['fixture040']['resolved_path'] ?? $path; }
}
namespace MaxMind\Db {
	if ( 'missing_library' !== $GLOBALS['mode040'] ) {
		final class Reader {
			public function __construct( $path ) { ++$GLOBALS['calls040']['constructed']; $GLOBALS['calls040']['database'] = $path; if ( ! empty( $GLOBALS['fixture040']['constructor_throw'] ) ) { throw new \RuntimeException( 'Synthetic corrupt database.' ); } }
			public function get( $address ) { ++$GLOBALS['calls040']['get']; if ( ! empty( $GLOBALS['fixture040']['lookup_throw'] ) ) { throw new \RuntimeException( 'Synthetic lookup failure.' ); } return $GLOBALS['fixture040']['record']; }
			public function close(): void { ++$GLOBALS['calls040']['close']; if ( ! empty( $GLOBALS['fixture040']['close_throw'] ) ) { throw new \RuntimeException( 'Synthetic close failure.' ); } }
		}
	}
}
namespace {
	use PeptideCore\Tracking\Country_Provider as C;
	reset040();
	require dirname( __DIR__, 3 ) . '/themes/peptide-site/inc/visitor-tracking.php';
	if ( 'fallback' === $mode040 ) {
		check040( 'absent provider keeps exact public fallback', array( 'status' => 'unavailable' ) === vc_visitor_observation_geo() );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8'; $_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
		check040( 'absent provider never trusts forwarded headers', array( 'status' => 'unknown' ) === vc_visitor_observation_geo() && 0 === $calls040['constructed'] );
	} else {
		require dirname( __DIR__ ) . '/includes/tracking/class-country-provider.php';
		if ( 'missing_library' === $mode040 ) {
			check040( 'missing library is a public unavailable status', array( 'status' => 'unavailable' ) === C::lookup( '8.8.8.8' ) );
			$broken040 = static function ( $class ): void { if ( 'MaxMind\\Db\\Reader' === $class ) { throw new \Error( 'Synthetic broken library autoload.' ); } }; spl_autoload_register( $broken040 );
			check040( 'broken library autoload is unavailable without leakage', array( 'status' => 'unavailable' ) === C::lookup( '8.8.8.8' ) ); spl_autoload_unregister( $broken040 );
		} elseif ( 'constant_bad' === $mode040 ) {
			check040( 'invalid explicit remote path never falls back', array( 'status' => 'unavailable' ) === C::lookup( '8.8.8.8' ) && array() === $calls040['options'] && array() === $calls040['uploads'] && 0 === $calls040['files'] );
		} elseif ( 'constant_valid' === $mode040 ) {
			check040( 'valid explicit local path gives an exact known shape', array( 'status' => 'known', 'country_code' => 'US' ) === C::lookup( '8.8.8.8' ) );
			check040( 'explicit constant takes precedence without settings or filters', 'D:/SyntheticCountry/Configured.mmdb' === $calls040['database'] && array() === $calls040['options'] && array() === $calls040['uploads'] && array() === $calls040['filters'] );
		} else {
			foreach ( array( null, false, array(), '', 'bad', '127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.0.1', '169.254.0.1', '100.64.0.1', '192.0.2.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255', '::1', 'fc00::1', 'fe80::1', 'ff0e::1', '2001:db8::1' ) as $address ) { reset040(); check040( 'nonpublic input never reaches storage or Reader', array( 'status' => 'unknown' ) === C::lookup( $address ) && 0 === $calls040['constructed'] && array() === $calls040['options'] ); }
			foreach ( array( '8.8.8.8', '1.1.1.1', '2606:4700:4700::1111' ) as $address ) {
				reset040(); check040( 'public IPv4/IPv6 gets only a country status', array( 'status' => 'known', 'country_code' => 'US' ) === C::lookup( $address ) && 1 === $calls040['get'] && 1 === $calls040['close'] );
				check040( 'missing native global-range capability stays unknown', array( 'status' => 'unknown' ) === C::classify( $address, null ) );
			}
			reset040(); C::lookup( '8.8.8.8' ); check040( 'Woo upload lookup explicitly forbids directory creation', array( array( null, false ) ) === $calls040['uploads'] );
			check040( 'only MaxMind settings option and local path filter are read', array( 'woocommerce_maxmind_geolocation_settings' ) === $calls040['options'] && 'woocommerce_maxmind_geolocation_database_path' === $calls040['filters'][0][0] );
			check040( 'empty prefix uses conventional existing filename', 'D:/SyntheticCountry/Uploads/woocommerce_uploads/GeoLite2-Country.mmdb' === $calls040['database'] );
			reset040( array( 'settings' => array( 'database_prefix' => str_repeat( 'a', 32 ) ) ) ); C::lookup( '8.8.8.8' ); check040( 'existing prefix is honored without generating or saving one', str_ends_with( $calls040['database'], '/' . str_repeat( 'a', 32 ) . '-GeoLite2-Country.mmdb' ) );
			foreach ( array( '../escape', '0', null, 12, array(), str_repeat( 'a', 31 ), str_repeat( '-', 32 ) ) as $prefix ) { reset040( array( 'settings' => array( 'database_prefix' => $prefix ) ) ); check040( 'malformed prefix is unavailable before filesystem lookup', array( 'status' => 'unavailable' ) === C::lookup( '8.8.8.8' ) && 0 === $calls040['files'] ); }
			foreach ( array( 'https://unconfigured.example.invalid/db.mmdb', 'php://memory', 'phar://archive/db.mmdb', '//server/share/db.mmdb', '\\\\server\\share\\db.mmdb', 'relative/db.mmdb' ) as $path ) { reset040( array( 'filter_path' => $path ) ); check040( 'path filter cannot activate remote/wrapper/relative sources', array( 'status' => 'unavailable' ) === C::lookup( '8.8.8.8' ) && 0 === $calls040['files'] ); }
			reset040( array( 'filter_path' => '/synthetic/local/Country.mmdb' ) ); check040( 'POSIX absolute local path is supported', array( 'status' => 'known', 'country_code' => 'US' ) === C::lookup( '8.8.8.8' ) );
			reset040( array( 'resolved_path' => '\\\\server\\share\\Country.mmdb' ) ); check040( 'resolved nonlocal path is unavailable', array( 'status' => 'unavailable' ) === C::lookup( '8.8.8.8' ) && 0 === $calls040['constructed'] );
			foreach ( array( array( 'exists' => false ), array( 'readable' => false ), array( 'uploads' => array( 'error' => 'Synthetic error' ) ) ) as $change ) { reset040( $change ); check040( 'missing/unreadable/error path never constructs Reader', array( 'status' => 'unavailable' ) === C::lookup( '8.8.8.8' ) && 0 === $calls040['constructed'] ); }
			reset040( array( 'constructor_throw' => true ) ); check040( 'corrupt database is unavailable without fabricated close', array( 'status' => 'unavailable' ) === C::lookup( '8.8.8.8' ) && 0 === $calls040['close'] );
			foreach ( array( 'lookup_throw', 'close_throw', 'catalog_throw' ) as $fault ) { reset040( array( $fault => true ) ); check040( 'Reader is closed even when lookup/catalog/close fails', array( 'status' => 'unavailable' ) === C::lookup( '8.8.8.8' ) && 1 === $calls040['close'] ); }
			foreach ( array( null, array(), array( 'registered_country' => array( 'iso_code' => 'US' ) ), array( 'country' => array( 'iso_code' => 'us' ) ), array( 'country' => array( 'iso_code' => 'USA' ) ), array( 'country' => array( 'iso_code' => 'ZZ' ) ) ) as $record ) { reset040( array( 'record' => $record ) ); check040( 'malformed/unsupported country does not become known', array( 'status' => 'unavailable' ) === C::lookup( '8.8.8.8' ) && 1 === $calls040['close'] ); }
			reset040(); $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8'; $_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE'; check040( 'theme private REMOTE_ADDR never invokes provider despite headers', array( 'status' => 'unknown' ) === vc_visitor_observation_geo() && 0 === $calls040['constructed'] );
			reset040( array( 'exists' => false ) ); $_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE'; check040( 'missing public source is unavailable rather than a header guess', array( 'status' => 'unavailable' ) === vc_visitor_observation_geo() );
			reset040(); $observation = vc_visitor_page_observation(); check040( 'normal theme observation carries the exact existing known geo shape', array( 'status' => 'known', 'country_code' => 'US' ) === $observation['context']['geo'] && 1 === $observation['context']['version'] );
			check040( 'normal observation does not serialize request IP or proxy headers', ! str_contains( json_encode( $observation ), '8.8.8.8' ) && ! str_contains( json_encode( $observation ), 'REMOTE_ADDR' ) );
		}
	}
	check040( 'no network/settings/directory/cron/logger/integration side effects across all fixtures', 0 === $forbidden_total040 );
	echo 'RESULT ' . $checks040 . " PASS, 0 SKIP\n";
}
