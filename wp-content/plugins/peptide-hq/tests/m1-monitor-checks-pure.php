<?php
/** M1：总后台巡检钩子（pchq_monitor_checks）和功能声明读取（Client::supports）。纯逻辑：php -n m1-monitor-checks-pure.php */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	class WP_Error { public function __construct( public string $code = '', public string $message = '' ) {} }
	$GLOBALS['pc_filters'] = array();
	function add_filter( string $hook, callable $fn, int $priority = 10, int $args = 1 ): void { $GLOBALS['pc_filters'][ $hook ][] = $fn; }
	function apply_filters( string $hook, $value, ...$args ) { foreach ( $GLOBALS['pc_filters'][ $hook ] ?? array() as $fn ) { $value = $fn( $value, ...$args ); } return $value; }
	function __( string $s, string $domain = '' ): string { return $s; }
}
namespace PeptideHq {
	/** 内存版告警表：只记录 raise / resolve 的效果。 */
	final class Alerts {
		public static array $open = array();
		public static function raise( int $site, string $type, string $ref, string $message ): bool { $k = "$site|$type|$ref"; $new = ! isset( self::$open[ $k ] ); self::$open[ $k ] = $message; return $new; }
		public static function resolve( int $site, string $type, array $keep = array() ): void { foreach ( array_keys( self::$open ) as $k ) { list( $s, $t, $r ) = explode( '|', $k, 3 ); if ( (int) $s === $site && $t === $type && ! in_array( $r, $keep, true ) ) { unset( self::$open[ $k ] ); } } }
	}
	final class Sites { public static array $rows = array(); public static function get( int $id ): ?array { return self::$rows[ $id ] ?? null; } }
	final class Site_Lifecycle { public static function active( int $id ): bool { return true; } }
	final class Site_Urls { public static function management( array $site ): string { return ''; } }
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-monitor.php';
	require dirname( __DIR__ ) . '/includes/class-client.php';
}
namespace {
	use PeptideHq\Alerts;
	use PeptideHq\Client;
	use PeptideHq\Monitor;
	use PeptideHq\Sites;
	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }

	// 1. 巡检项：失败开告警，恢复关告警。
	$r = Monitor::apply_checks( 7, array( array( 'type' => 'code_drift', 'ok' => false, 'ref' => 'peptide-core', 'message' => 'core differs' ), array( 'type' => 'code_drift', 'ok' => false, 'ref' => 'peptide-site', 'message' => 'theme differs' ) ) );
	check( 'two alerts raised', 2 === $r['raised'] && 2 === count( Alerts::$open ) );
	Monitor::apply_checks( 7, array( array( 'type' => 'code_drift', 'ok' => false, 'ref' => 'peptide-core', 'message' => 'core differs' ), array( 'type' => 'code_drift', 'ok' => true, 'ref' => 'peptide-site' ) ) );
	check( 'recovered ref closed, failing ref kept', array( '7|code_drift|peptide-core' ) === array_keys( Alerts::$open ) );
	Monitor::apply_checks( 7, array( array( 'type' => 'other_check', 'ok' => true ) ) );
	check( 'unreported type untouched', isset( Alerts::$open['7|code_drift|peptide-core'] ) );
	Monitor::apply_checks( 8, array( array( 'type' => 'code_drift', 'ok' => true ) ) );
	check( 'other store untouched', isset( Alerts::$open['7|code_drift|peptide-core'] ) );
	Monitor::apply_checks( 7, array( array( 'type' => 'code_drift', 'ok' => true ) ) );
	check( 'all clear closes the type', array() === Alerts::$open );

	// 2. 格式不对或想冒用核心告警类型的条目被跳过。
	Alerts::$open = array( '7|offline|' => 'core alert' );
	$r = Monitor::apply_checks( 7, array( array( 'type' => 'offline', 'ok' => true ), array( 'type' => 'Bad Type', 'ok' => false ), array( 'type' => 'no_ok_field' ), 'string', array( 'type' => 'x', 'ok' => false ) ) );
	check( 'reserved and malformed skipped', 5 === $r['skipped'] && 0 === $r['raised'] && array( '7|offline|' ) === array_keys( Alerts::$open ) );
	Monitor::apply_checks( 7, array( array( 'type' => 'empty_msg', 'ok' => false ) ) );
	check( 'empty message falls back to type', 'empty_msg' === ( Alerts::$open['7|empty_msg|'] ?? '' ) );

	// 3. 过滤器入口：收到店铺号、状态、时间；模块抛错不影响轮询。
	Alerts::$open = array();
	add_filter( 'pchq_monitor_checks', static function ( array $list, int $site, array $status, int $now ): array { $list[] = array( 'type' => 'version_old', 'ok' => '0.32.0' === ( $status['versions']['plugin'] ?? '' ), 'message' => "site $site at $now" ); return $list; }, 10, 4 );
	Monitor::module_checks( 3, array( 'versions' => array( 'plugin' => '0.31.0' ) ), 1000 );
	check( 'filter receives context', 'site 3 at 1000' === ( Alerts::$open['3|version_old|'] ?? '' ) );
	Monitor::module_checks( 3, array( 'versions' => array( 'plugin' => '0.32.0' ) ), 1001 );
	check( 'filter result resolves', array() === Alerts::$open );
	add_filter( 'pchq_monitor_checks', static function () { throw new RuntimeException( 'broken module' ); } );
	ini_set( 'error_log', '/dev/null' );
	Monitor::module_checks( 3, array(), 1002 );
	check( 'throwing module does not break polling', true );
	$GLOBALS['pc_filters'] = array();
	add_filter( 'pchq_monitor_checks', static fn() => 'not an array' );
	Monitor::module_checks( 3, array(), 1003 );
	check( 'non-array result ignored', array() === Alerts::$open );

	// 3b. 价格引擎巡检（内置）：不适用时不报；影子档有不一致时告警；改回 off 或恢复一致时关闭。
	$GLOBALS['pc_filters'] = array();
	$caps = array( 'capabilities' => array( 'pricing.engine' => '1' ) );
	$brief = static fn( string $mode, int $bad, int $err = 0 ) => $caps + array( 'modules' => array( 'pricing_engine' => array( 'mode' => $mode, 'today' => array( 'compared' => 9, 'matched' => 9 - $bad, 'mismatched' => $bad, 'skipped' => 0, 'errors' => $err ) ) ) );
	check( 'no brief: nothing reported', array() === Monitor::pricing_engine_check( array(), 5, array( 'capabilities' => array( 'pricing.engine' => '1' ) ), 1 ) );
	check( 'no capability: nothing reported', array() === Monitor::pricing_engine_check( array(), 5, array( 'modules' => $brief( 'shadow', 2 )['modules'] ), 1 ) );
	$r = Monitor::pricing_engine_check( array( array( 'type' => 'other', 'ok' => true ) ), 5, $brief( 'shadow', 2 ), 1 );
	check( 'shadow mismatch raises and keeps earlier checks', 2 === count( $r ) && 'pricing_mismatch' === $r[1]['type'] && false === $r[1]['ok'] && str_contains( $r[1]['message'], '不一致 2 次' ) );
	check( 'errors alone raise', false === Monitor::pricing_engine_check( array(), 5, $brief( 'on', 0, 1 ), 1 )[0]['ok'] );
	check( 'clean shadow is ok', true === Monitor::pricing_engine_check( array(), 5, $brief( 'shadow', 0 ), 1 )[0]['ok'] );
	check( 'off clears even with old mismatches', true === Monitor::pricing_engine_check( array(), 5, $brief( 'off', 3 ), 1 )[0]['ok'] );
	Alerts::$open = array();
	Monitor::apply_checks( 5, Monitor::pricing_engine_check( array(), 5, $brief( 'shadow', 2 ), 1 ) );
	check( 'alert opened through apply_checks', isset( Alerts::$open['5|pricing_mismatch|'] ) );
	Monitor::apply_checks( 5, Monitor::pricing_engine_check( array(), 5, $brief( 'shadow', 0 ), 1 ) );
	check( 'alert closed when consistent again', array() === Alerts::$open );
	check( 'alert label registered', '价格引擎核对不一致' === Monitor::module_alert_types( array() )['pricing_mismatch'] );

	// 4. 功能声明：只对声明了的店调用新接口。
	Sites::$rows = array(
		1 => array( 'info' => array( 'capabilities' => array( 'hq.capabilities' => '1', 'series.sync' => '2' ) ) ),
		2 => array( 'info' => array( 'versions' => array( 'plugin' => '0.32.0' ) ) ), // 旧版店铺：没有 capabilities
		3 => array( 'info' => array( 'capabilities' => 'broken' ) ),
	);
	check( 'declared capability', Client::supports( 1, 'series.sync' ) );
	check( 'minimum version met', Client::supports( 1, 'series.sync', '2' ) && Client::supports( 1, 'series.sync', '1.5' ) );
	check( 'minimum version not met', ! Client::supports( 1, 'series.sync', '3' ) );
	check( 'undeclared capability', ! Client::supports( 1, 'orders.report' ) );
	check( 'old store supports nothing', ! Client::supports( 2, 'hq.capabilities' ) && array() === Client::capabilities( 2 ) );
	check( 'malformed info and unknown store', ! Client::supports( 3, 'series.sync' ) && ! Client::supports( 99, 'series.sync' ) );

	echo "m1-monitor-checks-pure: {$checks} checks passed\n";
}
