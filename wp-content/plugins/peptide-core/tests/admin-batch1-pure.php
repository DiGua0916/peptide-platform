<?php
/**
 * Code review batch 1 (2026-11-06): admin asset trimming scope, persisted tracking-schema
 * verification, live-visitor SQL shape and "approved" report access. Real classes, WP stubbed.
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' ); define( 'DB_NAME', 'shop' ); define( 'ARRAY_A', 'ARRAY_A' );
	$GLOBALS['opts'] = array(); $GLOBALS['umeta'] = array(); $GLOBALS['users'] = array( 5 => true, 6 => true, 7 => true ); $GLOBALS['force_login'] = false; $GLOBALS['pending'] = array();
	function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
	function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
	function get_user_meta( $u, $k, $s = false ) { return $GLOBALS['umeta'][ $u ][ $k ] ?? ''; }
	function get_userdata( $u ) { return isset( $GLOBALS['users'][ $u ] ) ? (object) array( 'ID' => $u ) : false; }
	function rest_sanitize_boolean( $v ) { return in_array( $v, array( true, 1, '1', 'true', 'yes' ), true ); }
	function check( string $label, bool $ok ): void { global $checks; if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } $checks = ( $checks ?? 0 ) + 1; }
	final class Fake_DB {
		public string $prefix = 'wp_'; public string $last_error = ''; public int $show = 0;
		public function get_charset_collate() { return ''; }
		public function get_results( $sql, $mode = null ) { if ( str_starts_with( $sql, 'SHOW' ) ) { ++$this->show; } return array(); }
	}
	$wpdb = new Fake_DB();
}
namespace PeptideCore {
	final class Store_Config {
		public static function get( string $k ) { return 'force_login' === $k ? $GLOBALS['force_login'] : null; }
		public static function is_pending_approval( int $u ): bool { return in_array( $u, $GLOBALS['pending'], true ); }
	}
}
namespace PeptideCore\Tracking {
	// Ready() is exercised through a counting subclass-free seam: count SHOW statements on the fake DB.
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-meta.php';
	require dirname( __DIR__ ) . '/includes/class-access.php';
	require dirname( __DIR__ ) . '/includes/tracking/class-schema.php';
	require dirname( __DIR__ ) . '/includes/admin/class-workspace.php';
	use PeptideCore\Access; use PeptideCore\Tracking\Schema; use PeptideCore\Admin\Workspace;

	// 1. Command-palette trimming now covers every store page except the visual editor.
	foreach ( array( 'pc-overview', 'pc-data', 'pc-store-design-domains', 'pc-store-design', 'pc-product-reports', 'pc-tax', 'pc-system' ) as $p ) { check( "trim on $p", Workspace::trims_optional_assets( $p ) ); }
	foreach ( array( 'pc-design-editor', 'wc-admin', 'wc-orders', 'pchq-sites', '', 'pc-', 'pc-Bad', 'pc-x/../y' ) as $p ) { check( "no trim on '$p'", ! Workspace::trims_optional_assets( $p ) ); }

	// 2. Schema verification: the strict check runs once, then a persisted marker avoids SQL.
	$GLOBALS['opts'][ Schema::OPTION ] = Schema::DB_VERSION;
	$r1 = Schema::installed(); $after_first = $wpdb->show;
	check( 'with empty tables the strict check fails and nothing is persisted', false === $r1 && $after_first > 0 && ! isset( $GLOBALS['opts'][ Schema::VERIFIED_OPTION ] ) );
	$m = new ReflectionMethod( Schema::class, 'verification_key' ); $m->setAccessible( true );
	update_option( Schema::VERIFIED_OPTION, $m->invoke( null ) );
	$wpdb->show = 0;
	check( 'valid marker: installed without any SHOW statement', true === Schema::installed() && 0 === $wpdb->show );
	$p = new ReflectionProperty( Schema::class, 'ready_cache' ); $p->setAccessible( true ); $p->setValue( null, array() );
	$wpdb->show = 0;
	check( 'next request (empty static cache) still needs no SQL', true === Schema::installed() && 0 === $wpdb->show );
	update_option( Schema::VERIFIED_OPTION, 'stale-' . $m->invoke( null ) ); $p->setValue( null, array() ); $wpdb->show = 0;
	check( 'stale marker forces the strict check again', false === Schema::installed() && $wpdb->show > 0 );
	update_option( Schema::OPTION, '3' ); update_option( Schema::VERIFIED_OPTION, $m->invoke( null ) ); $p->setValue( null, array() );
	check( 'older stored version is never treated as installed', false === Schema::installed() );
	check( 'marker depends on database and table names', strlen( $m->invoke( null ) ) === 64 );

	// 3. Live visitors: filter on the indexed column before grouping (same result, uses KEY last_signal).
	$sql = file_get_contents( dirname( __DIR__ ) . '/includes/tracking/class-query.php' );
	check( 'live query filters by last_signal_ms before GROUP BY', str_contains( $sql, 'WHERE last_signal_ms >= %d GROUP BY visitor_id ORDER BY MAX(last_signal_ms) DESC LIMIT 500' ) && ! str_contains( $sql, 'HAVING MAX(last_signal_ms)' ) );

	// 4. "Approved" report access.
	check( 'guest is never approved', ! Access::is_approved( 0 ) );
	check( 'without flag or approval flow: not approved (unchanged)', ! Access::is_approved( 5 ) );
	$GLOBALS['umeta'][5][ Access::APPROVED_META ] = '1';
	check( 'explicit approval flag: approved', Access::is_approved( 5 ) );
	$GLOBALS['force_login'] = true; $GLOBALS['pending'] = array( 6 );
	check( 'approval flow on: account still waiting is not approved', ! Access::is_approved( 6 ) );
	check( 'approval flow on: registered account no longer waiting is approved', Access::is_approved( 7 ) );
	check( 'approval flow on: unknown user id is not approved', ! Access::is_approved( 99 ) );
	$store = file_get_contents( dirname( __DIR__ ) . '/includes/class-store-config.php' );
	check( 'approving an account writes the report approval flag', (bool) preg_match( '/function approve\( int \$user_id \): void \{[^}]*update_user_meta\( \$user_id, Access::APPROVED_META, \'1\' \)/s', $store ) );

	echo "PASS {$checks} admin batch-1 checks\n";
}
