<?php
/** Transport address checks; no WordPress, filesystem, network, tickets or customer data. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'PEPTIDE_OPS_VERSION', 'test' );
	class WP_Error { public function __construct( public string $code, public string $message = '', public array $data = array() ) {} }
	class WP_REST_Request {}
	function home_url( string $path = '' ): string { return rtrim( $GLOBALS['public_home'], '/' ) . '/' . ltrim( $path, '/' ); }
	function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ); }
	$GLOBALS['public_home'] = 'https://shop.example.test';
}
namespace PeptideCore {
	final class Management_Host {
		public static bool $on = false;
		public static string $url = 'https://store-a.manager.example.test/';
		public static function enabled(): bool { return self::$on; }
		public static function management_url(): string { return self::$url; }
	}
}
namespace PeptideOps {
	final class Storage {
		public static function site_uuid(): string { return '11111111-1111-4111-8111-111111111111'; }
		public static function config(): array { return array(); }
	}
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-runtime.php';
	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new \RuntimeException( 'FAIL ' . $name ); } }
	function download_url(): string { return ( new \ReflectionMethod( \PeptideOps\Runtime::class, 'download_url' ) )->invoke( null, str_repeat( 'a', 64 ) ); }
	check( 'legacy transport uses original home', home_url( '/' ) === \PeptideOps\Runtime::management_url() );
	check( 'legacy ticket stays on original origin', str_starts_with( download_url(), 'https://shop.example.test/?' ) );
	\PeptideCore\Management_Host::$on = true;
	check( 'explicit transport uses registered manager', \PeptideCore\Management_Host::$url === \PeptideOps\Runtime::management_url() );
	check( 'status reports machine management address', \PeptideCore\Management_Host::$url === \PeptideOps\Runtime::status()['site_url'] );
	check( 'backup download uses management origin and Ops route', str_starts_with( download_url(), 'https://store-a.manager.example.test/?rest_route=%2Fpeptide-ops%2Fv1%2Fdownload&ticket=' ) );
	$before = download_url(); $GLOBALS['public_home'] = 'https://new-shop.example.test';
	check( 'public change cannot retarget backup download', $before === download_url() );
	\PeptideCore\Management_Host::$url = '';
	check( 'invalid managed config cannot issue a public URL', '' === download_url() );
	check( 'invalid managed config blocks before ticket storage', \PeptideOps\Runtime::ticket( new WP_REST_Request() ) instanceof WP_Error );
	define( 'PEPTIDE_CORE_MANAGEMENT_URL', 'https://store-a.manager.example.test/' );
	\PeptideCore\Management_Host::$on = false;
	check( 'explicit constants cannot silently fall back without core management', '' === \PeptideOps\Runtime::management_url() );
	echo 'PASS ', $checks, " pure Ops transport checks; no side effects.\n";
}
