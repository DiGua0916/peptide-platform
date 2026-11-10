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

	echo "m1-hq-capabilities-pure: {$checks} checks passed\n";
}
