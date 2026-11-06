<?php
/**
 * Stress test item 5 (owner decision 2026-11-06): newly added HQ payment methods
 * start off, in provider test mode, authorized for no store. Real Hub_Accounts
 * save/snapshot/commit with an in-memory options row; WordPress boundaries stubbed.
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['option'] = null; $GLOBALS['env'] = 'production'; $checks = 0;
	function check( string $label, bool $ok ): void { global $checks; if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } ++$checks; }
	function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
	function wp_get_environment_type(): string { return $GLOBALS['env']; }
	function home_url( string $path = '' ): string { return 'https://hq.example.invalid/' . ltrim( $path, '/' ); }
	function wp_cache_delete( ...$args ): bool { return true; }
	function wp_strip_all_tags( string $text, bool $breaks = false ): string { return strip_tags( $text ); }
	function wp_http_validate_url( string $url ) { return $url; }
}
namespace PeptideHq { final class Sites { public static function all(): array { return array( 3 => array( 'id' => 3 ), 4 => array( 'id' => 4 ) ); } } }
namespace VialCove\Panapay {
	final class Hub_Settings { public static function raw(): array { return array(); } public static function server_override( string $name ): bool { return false; } }
	final class Crypto { public static function encrypt( string $value, string $purpose ): string { return 'box:' . base64_encode( $value ); } public static function decrypt( string $box, string $purpose ): string { return base64_decode( substr( $box, 4 ) ); } }
	final class Fake_Options_DB {
		public string $options = 'wp_options'; public string $last_error = '';
		public function prepare( string $sql, ...$args ): array { return array( $sql, $args ); }
		public function get_var( array $q ) { return $GLOBALS['option']; }
		public function query( array $q ) {
			[ $sql, $args ] = $q;
			if ( str_starts_with( $sql, 'INSERT IGNORE' ) ) { if ( null !== $GLOBALS['option'] ) { return 0; } $GLOBALS['option'] = $args[1]; return 1; }
			if ( str_starts_with( $sql, 'UPDATE' ) ) { if ( $GLOBALS['option'] !== $args[2] ) { return 0; } $GLOBALS['option'] = $args[0]; return 1; }
			return false;
		}
		public function close(): void {}
	}
	final class Database { public static function open(): Fake_Options_DB { return new Fake_Options_DB(); } }
	require dirname( __DIR__ ) . '/includes/class-configuration.php';
	// Offline: seed the real per-host public-address cache instead of resolving DNS.
	(new \ReflectionProperty( Configuration::class, 'public_hosts' ))->setValue( null, array( 'pay.example.com' => true ) ); require dirname( __DIR__ ) . '/includes/class-hub-accounts.php';
	$enabled_for = static function ( string $id, int $site ): bool { foreach ( Hub_Accounts::policy( $site )['methods'] as $m ) { if ( $id === $m['account_id'] ) { return true === $m['enabled']; } } throw new \RuntimeException( 'missing method' ); };

	$defaults = Hub_Accounts::new_account_defaults( str_repeat( 'a', 32 ) );
	check( 'new account default is off', 'no' === $defaults['enabled'] );
	check( 'new account default is provider test mode', 'yes' === $defaults['test_mode'] );
	check( 'new account default authorizes no store', 'no' === $defaults['all_sites'] && array() === $defaults['sites'] );
	check( 'stored/legacy fallback defaults unchanged', array( 'no', 'yes', 'yes' ) === array( Hub_Accounts::defaults()['enabled'], Hub_Accounts::defaults()['test_mode'], Hub_Accounts::defaults()['all_sites'] ) );

	$accounts = static function (): array { return unserialize( $GLOBALS['option'], array( 'allowed_classes' => false ) )['accounts']; };
	$base = array( 'protocol' => 'panapay', 'title' => 'Card', 'gateway' => 'https://pay.example.com', 'mchid' => 'synthetic', 'secret' => 'synthetic-secret' );

	// A save that omits the switches (API/import style) stays closed.
	$id = Hub_Accounts::save( $base + array( 'revision' => 0 ) );
	$row = $accounts()[ $id ];
	check( 'omitted switches saved off/test/no stores', 'no' === $row['enabled'] && 'yes' === $row['test_mode'] && 'no' === $row['all_sites'] && array() === $row['sites'] );
	$method = array( 'enabled' => $enabled_for( $id, 3 ) );
	check( 'no store receives the new method as enabled', false === $method['enabled'] );

	// The add form posts exactly what it was pre-filled with: still closed.
	$id2 = Hub_Accounts::save( $base + array( 'revision' => 1, 'enabled' => 'no', 'all_sites' => 'no', 'test_mode' => 'yes' ) );
	check( 'untouched add form saves closed', 'no' === $accounts()[ $id2 ]['enabled'] );

	// Going live stays an explicit, validated choice.
	$threw = static function ( callable $fn, string $needle ): bool { try { $fn(); } catch ( \InvalidArgumentException $e ) { return str_contains( $e->getMessage(), $needle ); } return false; };
	check( 'enabling without a store is rejected', $threw( fn() => Hub_Accounts::save( $base + array( 'revision' => 2, 'enabled' => 'yes', 'all_sites' => 'no', 'test_mode' => 'no' ) ), 'Select at least one store' ) );
	check( 'enabling test mode in production is rejected', $threw( fn() => Hub_Accounts::save( $base + array( 'revision' => 2, 'enabled' => 'yes', 'sites' => array( 3 ), 'test_mode' => 'yes' ) ), 'Test payments cannot be enabled in production' ) );
	$id3 = Hub_Accounts::save( $base + array( 'revision' => 2, 'enabled' => 'yes', 'all_sites' => 'no', 'sites' => array( 3 ), 'test_mode' => 'no' ) );
	check( 'explicit live enable for one store works', 'yes' === $accounts()[ $id3 ]['enabled'] && array( 3 ) === array_map( 'intval', $accounts()[ $id3 ]['sites'] ) );
	check( 'only the selected store receives it', $enabled_for( $id3, 3 ) && ! $enabled_for( $id3, 4 ) );

	echo "PASS {$checks} new payment account default checks\n";
}
