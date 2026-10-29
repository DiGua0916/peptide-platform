<?php
/**
 * Standalone boundary tests. Loads the real installed Requests classes and WordPress hook bridge,
 * but not wp-load.php/wp-config.php. No HTTP, sockets, database, cron or sessions are used.
 * Usage: php -n tools/localwp/test-local-cron-transport.php --wp-root=D:/path/to/wordpress
 */

if ( defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this standalone, not through WordPress eval-file.\n" );
	exit( 2 );
}
$test_wp_root = '';
foreach ( array_slice( $argv, 1 ) as $test_arg ) {
	if ( str_starts_with( $test_arg, '--wp-root=' ) ) {
		$test_wp_root = rtrim( substr( $test_arg, strlen( '--wp-root=' ) ), '/\\' );
	}
}
if ( ! is_file( $test_wp_root . '/wp-includes/Requests/src/Autoload.php' )
	|| ! is_file( $test_wp_root . '/wp-includes/class-wp-http-requests-hooks.php' ) ) {
	fwrite( STDERR, "Pass --wp-root to an installed WordPress containing Requests and its hook bridge.\n" );
	exit( 2 );
}

$GLOBALS['cron_transport_test_actions'] = array();
$GLOBALS['cron_transport_test_env']     = 'local';
$GLOBALS['cron_transport_test_own_url'] = 'http://store.local/wp-cron.php';
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['cron_transport_test_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );
}
function do_action_ref_array( $hook, $parameters ) {
	foreach ( $GLOBALS['cron_transport_test_actions'][ $hook ] ?? array() as [ $callback, $priority, $accepted_args ] ) {
		call_user_func_array( $callback, array_slice( $parameters, 0, $accepted_args ) );
	}
}
function wp_get_environment_type() {
	return $GLOBALS['cron_transport_test_env'];
}
function site_url( $path = '' ) {
	if ( 'wp-cron.php' !== $path ) {
		throw new RuntimeException( 'Unexpected site_url lookup in isolated test.' );
	}
	return $GLOBALS['cron_transport_test_own_url'];
}
function wp_parse_url( $url ) {
	return parse_url( $url );
}

define( 'ABSPATH', $test_wp_root . '/' );
require_once $test_wp_root . '/wp-includes/Requests/src/Autoload.php';
\WpOrg\Requests\Autoload::register();
require_once $test_wp_root . '/wp-includes/class-wp-http-requests-hooks.php';
require __DIR__ . '/local-cron-transport.php';

$passed = 0;
$failed = 0;
$ok = static function ( string $label, bool $condition ) use ( &$passed, &$failed ): void {
	$condition ? ++$passed : ++$failed;
	echo ( $condition ? 'PASS ' : 'FAIL ' ), $label, "\n";
};
$callbacks = $GLOBALS['cron_transport_test_actions']['requests-requests.before_request'] ?? array();
$ok( 'the real Requests hook bridge receives one five-argument callback', 1 === count( $callbacks ) && 5 === $callbacks[0][2] );
$available = static function ( bool $ssl ): bool {
	return function_exists( 'stream_socket_client' )
		&& \WpOrg\Requests\Transport\Fsockopen::test( array( \WpOrg\Requests\Capability::SSL => $ssl ) );
};
$ok( 'installed Requests Fsockopen transport is available for HTTP', $available( false ) );
$base_options = array(
	'blocking'        => false,
	'timeout'         => 0.01,
	'connect_timeout' => 10,
	'transport'       => null,
	'verify'          => false,
	'verifyname'      => false,
	'proxy'           => false,
	'follow_redirects' => false,
);
$run = static function ( string $label, string $url, bool $select, array $overrides = array(), string $type = 'POST', string $env = 'local', string $own = 'http://store.local/wp-cron.php', array $server_overrides = array(), ?string $expected_socket = 'tcp://127.0.0.1:10005' ) use ( $base_options, $available, $ok ): void {
	$GLOBALS['cron_transport_test_env']     = $env;
	$GLOBALS['cron_transport_test_own_url'] = $own;
	$_SERVER = array_replace(
		array( 'SERVER_SOFTWARE' => 'Apache/fixture mod_fcgid', 'SERVER_ADDR' => '127.0.0.1', 'SERVER_PORT' => '10005', 'DOCUMENT_ROOT' => ABSPATH ),
		$server_overrides
	);
	$headers = array( 'X-Fixture' => 'preserve headers', 'Host' => 'preserve-host.local' );
	$data    = 'doing_wp_cron=body-only-marker&fixture=preserve-body';
	$options = array_replace( $base_options, $overrides );
	$hooks = new WP_HTTP_Requests_Hooks( $url, array( 'blocking' => $options['blocking'] ) );
	$options['hooks'] = $hooks;
	$before  = array( $url, $headers, $data, $type, $options );
	$expected_options = $options;
	$selected = $select && $available( 'https' === parse_url( $url, PHP_URL_SCHEME ) );
	if ( $selected ) {
		$expected_options['transport'] = \WpOrg\Requests\Transport\Fsockopen::class;
	}
	// Exercise WordPress's actual Requests-to-action adapter and mutable options, without sending.
	$hooks->dispatch( 'requests.before_request', array( &$url, &$headers, &$data, &$type, &$options ) );
	$socket = 'tcp://unmodified-fixture.invalid:1';
	$hooks->dispatch( 'fsockopen.remote_socket', array( &$socket ) );
	$wanted_socket = $selected && null !== $expected_socket ? $expected_socket : 'tcp://unmodified-fixture.invalid:1';
	$ok( $label, $before[0] === $url && $before[1] === $headers && $before[2] === $data && $before[3] === $type && $expected_options === $options && $wanted_socket === $socket );
};

$cron = 'http://store.local/wp-cron.php?doing_wp_cron=1793200000.123456';
$run( 'own local non-blocking cron selects sockets; every other option/header/body stays exact', $cron, true );
$run( 'same origin with explicit default port is accepted', 'http://store.local:80/wp-cron.php?doing_wp_cron=1', true );
$run( 'host case is origin-insensitive', 'http://STORE.LOCAL/wp-cron.php?doing_wp_cron=1', true );
$run( 'another host stays untouched', 'http://other.local/wp-cron.php?doing_wp_cron=1', false );
$run( 'a host with a matching suffix stays untouched', 'http://store.local.other.invalid/wp-cron.php?doing_wp_cron=1', false );
$run( 'another port stays untouched', 'http://store.local:8080/wp-cron.php?doing_wp_cron=1', false );
$run( 'another scheme stays untouched', 'https://store.local/wp-cron.php?doing_wp_cron=1', false );
$run( 'another path stays untouched', 'http://store.local/wp-admin/wp-cron.php?doing_wp_cron=1', false );
$run( 'a trailing path stays untouched', 'http://store.local/wp-cron.php/extra?doing_wp_cron=1', false );
$run( 'a missing cron marker stays untouched', 'http://store.local/wp-cron.php', false );
$run( 'an empty cron marker stays untouched', 'http://store.local/wp-cron.php?doing_wp_cron=', false );
$run( 'an array cron marker stays untouched', 'http://store.local/wp-cron.php?doing_wp_cron[]=1', false );
$run( 'a body-only marker cannot select transport', 'http://store.local/wp-cron.php?other=1', false );
$run( 'GET stays untouched', $cron, false, array(), 'GET' );
$run( 'a blocking request stays untouched', $cron, false, array( 'blocking' => true ) );
$run( 'production stays untouched', $cron, false, array(), 'POST', 'production' );
$run( 'staging stays untouched', $cron, false, array(), 'POST', 'staging' );
$run( 'development is not local and stays untouched', $cron, false, array(), 'POST', 'development' );
$run( 'an existing transport class stays untouched', $cron, false, array( 'transport' => 'FixtureTransport' ) );
$run( 'an existing transport object stays untouched', $cron, false, array( 'transport' => (object) array( 'fixture' => true ) ) );
$run( 'an explicitly non-null transport value stays untouched', $cron, false, array( 'transport' => false ) );
$run( 'a configured proxy is preserved and prevents bypass', $cron, false, array( 'proxy' => (object) array( 'fixture' => true ) ) );
$run( 'userinfo stays untouched', 'http://fixture@store.local/wp-cron.php?doing_wp_cron=1', false );
$run( 'a fragment stays untouched', $cron . '#fixture', false );
$run( 'own subdirectory and custom port are supported', 'http://store.local:8081/sub/wp-cron.php?doing_wp_cron=1', true, array(), 'POST', 'local', 'http://store.local:8081/sub/wp-cron.php' );
$run( 'the root cron is not a subdirectory site cron', $cron, false, array(), 'POST', 'local', 'http://store.local/sub/wp-cron.php' );
$run( 'hostname HTTPS never bypasses to plain TCP', 'https://store.local/wp-cron.php?doing_wp_cron=1', false, array( 'verify' => '/fixture/ca.pem', 'verifyname' => true ), 'POST', 'local', 'https://store.local/wp-cron.php' );
$run( 'hostname without Apache information keeps cURL', $cron, false, array(), 'POST', 'local', 'http://store.local/wp-cron.php', array( 'SERVER_SOFTWARE' => '' ) );
$run( 'an unverified server implementation keeps cURL', $cron, false, array(), 'POST', 'local', 'http://store.local/wp-cron.php', array( 'SERVER_SOFTWARE' => 'nginx' ) );
$run( 'a non-loopback server address cannot redirect the socket', $cron, false, array(), 'POST', 'local', 'http://store.local/wp-cron.php', array( 'SERVER_ADDR' => '192.0.2.1' ) );
$run( 'a zero Apache port keeps cURL', $cron, false, array(), 'POST', 'local', 'http://store.local/wp-cron.php', array( 'SERVER_PORT' => '0' ) );
$run( 'an out-of-range Apache port keeps cURL', $cron, false, array(), 'POST', 'local', 'http://store.local/wp-cron.php', array( 'SERVER_PORT' => '65536' ) );
$run( 'an invalid Apache port keeps cURL', $cron, false, array(), 'POST', 'local', 'http://store.local/wp-cron.php', array( 'SERVER_PORT' => '10005x' ) );
$run( 'an unresolved document root keeps cURL', $cron, false, array(), 'POST', 'local', 'http://store.local/wp-cron.php', array( 'DOCUMENT_ROOT' => ABSPATH . '/does-not-exist-fixture' ) );
$run( 'another resolved document root keeps cURL', $cron, false, array(), 'POST', 'local', 'http://store.local/wp-cron.php', array( 'DOCUMENT_ROOT' => ABSPATH . '/wp-includes' ) );
$run( 'the Apache port is discovered rather than hardcoded', $cron, true, array(), 'POST', 'local', 'http://store.local/wp-cron.php', array( 'SERVER_PORT' => '12345' ), 'tcp://127.0.0.1:12345' );
$run( 'IPv6 Apache loopback is bracketed correctly', $cron, true, array(), 'POST', 'local', 'http://store.local/wp-cron.php', array( 'SERVER_ADDR' => '::1' ), 'tcp://[::1]:10005' );
$run( 'direct loopback IP site keeps its original socket path without Apache information', 'http://127.0.0.1:10090/wp-cron.php?doing_wp_cron=1', true, array(), 'POST', 'local', 'http://127.0.0.1:10090/wp-cron.php', array( 'SERVER_SOFTWARE' => '' ), null );
$run( 'direct IPv6 site keeps its original socket path', 'http://[::1]:10090/wp-cron.php?doing_wp_cron=1', true, array(), 'POST', 'local', 'http://[::1]:10090/wp-cron.php', array( 'SERVER_SOFTWARE' => '' ), null );
$run( 'direct HTTPS IP preserves TLS transport without TCP bypass', 'https://127.0.0.1:10090/wp-cron.php?doing_wp_cron=1', true, array( 'verify' => '/fixture/ca.pem', 'verifyname' => true ), 'POST', 'local', 'https://127.0.0.1:10090/wp-cron.php', array( 'SERVER_SOFTWARE' => '' ), null );
if ( 'Windows' === PHP_OS_FAMILY ) {
	$run( 'Windows document-root case and slash differences resolve to the same root', $cron, true, array(), 'POST', 'local', 'http://store.local/wp-cron.php', array( 'DOCUMENT_ROOT' => strtoupper( str_replace( '/', '\\', ABSPATH ) ) ) );
}

echo 'SUMMARY ', $passed, ' PASS, ', $failed, " FAIL\n";
exit( $failed ? 1 : 0 );
