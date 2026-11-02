<?php
/** Pure configuration boundary checks; no files, database, process, HTTP or WordPress bootstrap. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { public function __construct( public string $code, public string $message = '', public array $data = array() ) {} }
function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
require dirname( __DIR__ ) . '/includes/class-provisioner.php';
$checks = 0;
function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }
function call_private( string $name, ...$args ) { return ( new ReflectionMethod( PeptideOps\Provisioner::class, $name ) )->invoke( null, ...$args ); }
$profile = array( 'driver' => 'webserver', 'allowed_domains' => array( '*.manager.example.test', 'shop.example.test' ) );
$mgmt = 'https://store-a.manager.example.test';
$urls = call_private( 'dual_urls', array( 'management_url' => $mgmt, 'public_url' => 'https://shop.example.test/' ), $profile );
check( 'explicit URL pair normalized', array( 'dual_domain' => true, 'management_url' => $mgmt, 'public_url' => 'https://shop.example.test' ) === $urls );
check( 'empty public uses management', $mgmt === call_private( 'dual_urls', array( 'management_url' => $mgmt ), $profile )['public_url'] );
check( 'same domains supported before customer domain exists', $mgmt === call_private( 'dual_urls', array( 'management_url' => $mgmt, 'public_url' => $mgmt ), $profile )['public_url'] );
check( 'legacy optional empty fields remain legacy', array( 'dual_domain' => false ) === call_private( 'dual_urls', array( 'management_url' => '', 'public_url' => '' ), $profile ) );
foreach ( array( 'https://localhost', 'https://127.0.0.1', 'https://[::1]' ) as $bad ) { check( 'explicit origin needs DNS hostname ' . $checks, '' === call_private( 'https_root_url', $bad ) ); }
foreach ( array( array( 'management_url' => 'http://store-a.manager.example.test' ), array( 'public_url' => 'https://shop.example.test' ), array( 'management_url' => $mgmt . '/path' ), array( 'management_url' => $mgmt . '?x=1' ), array( 'management_url' => $mgmt . '#x' ), array( 'management_url' => 'https://user@store-a.manager.example.test' ), array( 'management_url' => $mgmt . ':8443' ), array( 'management_url' => 'https://outside.example.test' ), array( 'management_url' => $mgmt, 'public_url' => 'https://outside.example.test' ), array( 'management_url' => array() ) ) as $bad ) { check( 'reject unsafe/untrusted pair ' . $checks, is_wp_error( call_private( 'dual_urls', $bad, $profile ) ) ); }
check( 'local runner cannot opt into dual origin', is_wp_error( call_private( 'dual_urls', array( 'management_url' => $mgmt ), array_replace( $profile, array( 'driver' => 'php-server' ) ) ) ) );
$uuid = '11111111-1111-4111-8111-111111111111';
$state = array( 'dual_domain' => true, 'url' => $mgmt, 'management_url' => $mgmt, 'public_url' => 'https://shop.example.test', 'uuid' => $uuid, 'db_name' => 'fixture_a', 'db_user' => 'fixture_a', 'private' => '/fixture/private' );
$p = array( 'db' => array( 'host' => '127.0.0.1', 'port' => 3306, 'create_user' => true ), 'hq_url' => 'https://hq.example.test', 'hq_secret' => str_repeat( 'a', 64 ) );
$credentials = array( 'db_password' => 'synthetic-test-only' );
$constants = call_private( 'config_constants', $p, $state, $credentials );
check( 'dual config leaves WP_HOME mutable', ! array_key_exists( 'WP_HOME', $constants ) );
check( 'siteurl remains on management', $mgmt === $constants['WP_SITEURL'] );
check( 'complete UUID and management constants paired', $uuid === $constants['PEPTIDE_CORE_MANAGEMENT_UUID'] && $mgmt === $constants['PEPTIDE_CORE_MANAGEMENT_URL'] && $uuid === $constants['PEPTIDE_OPS_SITE_UUID'] );
check( 'home initializes public while siteurl initializes management', array( 'siteurl' => $mgmt, 'home' => 'https://shop.example.test' ) === call_private( 'installation_urls', $state ) );
$other = call_private( 'config_constants', $p, array_replace( $state, array( 'uuid' => '22222222-2222-4222-8222-222222222222', 'db_user' => 'fixture_b', 'db_name' => 'fixture_b' ) ), $credentials );
check( 'other store gets its own UUID and database identity', $constants['PEPTIDE_CORE_MANAGEMENT_UUID'] !== $other['PEPTIDE_CORE_MANAGEMENT_UUID'] && $constants['DB_USER'] !== $other['DB_USER'] && $constants['DB_NAME'] !== $other['DB_NAME'] );
check( 'fresh authentication material is not cloned', $constants['AUTH_KEY'] !== $other['AUTH_KEY'] && $constants['AUTH_SALT'] !== $other['AUTH_SALT'] );
$legacy = call_private( 'config_constants', $p, array_replace( $state, array( 'dual_domain' => false ) ), $credentials );
check( 'legacy config retains original home/siteurl contract', $mgmt === $legacy['WP_HOME'] && $mgmt === $legacy['WP_SITEURL'] && ! array_key_exists( 'PEPTIDE_CORE_MANAGEMENT_URL', $legacy ) && ! array_key_exists( 'PEPTIDE_CORE_MANAGEMENT_UUID', $legacy ) );
check( 'legacy install does not override home after install', array() === call_private( 'installation_urls', array( 'url' => 'http://127.0.0.1:18080' ) ) );
echo 'PASS ', $checks, " pure Ops management checks; no side effects.\n";
