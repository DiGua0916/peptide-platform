<?php
/** M1：/hq/status 的功能声明（pc_hq_capabilities）。纯逻辑，不加载 WordPress：php -n m1-hq-capabilities-pure.php */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['pc_filters'] = array();
	function add_filter( string $hook, callable $fn ): void { $GLOBALS['pc_filters'][ $hook ][] = $fn; }
	function apply_filters( string $hook, $value, ...$args ) { foreach ( $GLOBALS['pc_filters'][ $hook ] ?? array() as $fn ) { $value = $fn( $value, ...$args ); } return $value; }
	require dirname( __DIR__ ) . '/includes/class-hq-connector.php';
}
namespace {
	use PeptideCore\Hq_Connector;
	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }

	check( 'base capability always present', array( 'hq.capabilities' => '1' ) === Hq_Connector::capabilities() );

	add_filter( 'pc_hq_capabilities', static fn( array $caps ): array => $caps + array( 'series.sync' => '2', 'orders.report' => 1, 'flag' => true ) );
	$caps = Hq_Connector::capabilities();
	check( 'modules add capabilities', '2' === $caps['series.sync'] && '1' === $caps['orders.report'] && '1' === $caps['flag'] );
	check( 'keys sorted', array_keys( $caps ) === array( 'flag', 'hq.capabilities', 'orders.report', 'series.sync' ) );

	$dirty = Hq_Connector::clean_capabilities( array( 'Bad Key' => '1', 'ok' => '1.2.0', 'arr' => array( 1 ), 'empty' => '', 'off' => false, 'long' => str_repeat( 'x', 40 ), 3 => '1', 'a/b' => '1', '<script>' => '1', 'v' => '1 OR 1' ) );
	check( 'malformed entries dropped', array( 'ok' => '1.2.0' ) === $dirty );
	check( 'non-array input', array() === Hq_Connector::clean_capabilities( 'nope' ) );

	// 模块把过滤器写坏了：不能让 /hq/status 报错。
	$GLOBALS['pc_filters'] = array();
	add_filter( 'pc_hq_capabilities', static function () { throw new RuntimeException( 'broken module' ); } );
	check( 'throwing module falls back to base', array( 'hq.capabilities' => '1' ) === Hq_Connector::capabilities() );
	$GLOBALS['pc_filters'] = array();
	add_filter( 'pc_hq_capabilities', static fn() => null );
	check( 'module returning null keeps base', array( 'hq.capabilities' => '1' ) === Hq_Connector::capabilities() );
	$GLOBALS['pc_filters'] = array();
	add_filter( 'pc_hq_capabilities', static fn() => array( 'hq.capabilities' => '9', 'x' => '1' ) );
	check( 'module may bump but not remove base', array( 'hq.capabilities' => '9', 'x' => '1' ) === Hq_Connector::capabilities() );

	// /hq/summary 的弃单数：店铺没有 M6 时不加键；有时按天对上；读取出错不影响汇总。
	$rows = array( '2026-11-10' => array( 'day' => '2026-11-10', 'orders' => 2 ), '2026-11-11' => array( 'day' => '2026-11-11', 'orders' => 0 ) );
	check( 'no Metrics class: rows untouched', $rows === Hq_Connector::with_abandoned( $rows, 1, 2 ) );
	eval( 'namespace PeptideCore\\Data; final class Metrics { public static $throw = false; public static function abandoned_daily( int $from, int $to ): array { if ( self::$throw ) { throw new \\RuntimeException( "x" ); } return array( "2026-11-10" => array( "count" => 3, "open" => 1, "emailed" => 2, "recovered" => 1, "failed" => 1 ), "2026-11-09" => array( "count" => 9 ) ); } }' );
	$with = Hq_Connector::with_abandoned( $rows, 1, 2 );
	check( 'abandoned counts joined by day', 3 === $with['2026-11-10']['abandoned'] && 1 === $with['2026-11-10']['abandoned_open'] && 2 === $with['2026-11-10']['recall_emailed'] && 1 === $with['2026-11-10']['recall_recovered'] );
	check( 'days without abandoned carts report zero, other days ignored', 0 === $with['2026-11-11']['abandoned'] && 2 === count( $with ) && 2 === $with['2026-11-10']['orders'] );
	\PeptideCore\Data\Metrics::$throw = true;
	check( 'failing abandoned read keeps the summary', $rows === Hq_Connector::with_abandoned( $rows, 1, 2 ) );

	// /hq/status 的模块简报：过滤器加自己的键；格式不对的丢弃；抛错不影响。
	$GLOBALS['pc_filters'] = array();
	check( 'no modules deployed: empty', array() === Hq_Connector::module_status() );
	add_filter( 'pc_hq_status_modules', static fn( array $m ): array => $m + array( 'series' => array( 'ver' => 3 ), 'Bad Key' => 1, 'obj' => new \stdClass(), 'flag' => true ) );
	check( 'modules add briefs, malformed dropped', array( 'series' => array( 'ver' => 3 ), 'flag' => true ) === Hq_Connector::module_status() );
	$GLOBALS['pc_filters'] = array();
	add_filter( 'pc_hq_status_modules', static function () { throw new \RuntimeException( 'broken' ); } );
	check( 'throwing module brief ignored', array() === Hq_Connector::module_status() );

	echo "m1-hq-capabilities-pure: {$checks} checks passed\n";
}
