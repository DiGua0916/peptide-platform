<?php
/**
 * NF-02：按店开关（关 → 影子 → 开）和对账日志。纯逻辑：php -n m6-metrics-switch-pure.php
 *
 * 最要紧的一条：开关关着时，新算法一次都不会被调用，返回的就是旧值本身。
 */
require __DIR__ . '/m6-stubs.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics.php';
require dirname( __DIR__ ) . '/includes/data/class-metrics-switch.php';

use PeptideCore\Data\Metrics;
use PeptideCore\Data\Metrics_Switch as Sw;

$calls  = 0;
$modern = static function () use ( &$calls ) {
	++$calls;
	return array( 'orders' => 3, 'sales_cents' => 5000 );
};
$legacy = array( 'orders' => 4, 'sales_cents' => 5000 );

// ---------- 关 ----------
m6_check( 'default mode is off', 'off' === Sw::mode() && Sw::off() && ! Sw::shadow() && ! Sw::on() );
m6_check( 'off returns the legacy value itself', $legacy === Sw::pick( 'overview.totals', $legacy, $modern ) );
m6_check( 'off never calls the new calculation', 0 === $calls );
m6_check( 'off writes nothing', array() === Sw::discard_pending() && ! isset( $GLOBALS['m6_options'][ Sw::LOG_OPTION ] ) && ! isset( $GLOBALS['m6_actions']['shutdown'] ) );
$GLOBALS['m6_options'][ Sw::OPTION ] = 'garbage';
m6_check( 'unknown stored mode means off', 'off' === Sw::mode() );
m6_check( 'set rejects unknown modes', ! Sw::set( 'maybe' ) && 'garbage' === $GLOBALS['m6_options'][ Sw::OPTION ] );

// ---------- 影子 ----------
m6_check( 'set stores shadow', Sw::set( 'shadow' ) && Sw::shadow() );
$picked = Sw::pick( 'overview.totals', $legacy, $modern, array( 'range' => 'today' ) );
m6_check( 'shadow still shows the legacy value', $legacy === $picked && 1 === $calls );
m6_check( 'shadow announces the difference', 'peptide_core_m6_shadow_diff' === $GLOBALS['m6_fired'][0][0] && 'overview.totals' === $GLOBALS['m6_fired'][0][1][0] );
m6_check( 'shadow flushes on shutdown', array( Sw::class, 'flush' ) === $GLOBALS['m6_actions']['shutdown'][0] );
Sw::pick( 'overview.totals', $legacy, static fn() => $legacy, array( 'range' => 'today' ) ); // 一致的一次。
Sw::pick( 'overview.totals', $legacy, $modern, array( 'range' => 'today' ) );              // 同一处不一致再出现一次。
Sw::flush();
$log = Sw::log();
m6_check( 'log counts comparisons and differences per metric', 3 === $log['compared']['overview.totals'] && 2 === $log['differed']['overview.totals'] );
m6_check( 'the same difference is stored once with a count', 1 === count( $log['entries'] ) && 2 === array_values( $log['entries'] )[0]['count'] );
$entry = array_values( $log['entries'] )[0];
m6_check( 'log entry names the path and both values', array( array( 'path' => 'orders', 'legacy' => 4, 'metrics' => 3 ) ) === $entry['diffs'] && array( 'range' => 'today' ) === $entry['context'] );
Sw::flush();
m6_check( 'flushing twice does not double count', 3 === Sw::log()['compared']['overview.totals'] );

$boom = Sw::pick( 'overview.totals', $legacy, static function () { throw new RuntimeException( 'new code broke' ); } );
m6_check( 'a failing new calculation falls back to the legacy value', $legacy === $boom );
$pending = Sw::discard_pending();
m6_check( 'the failure is logged, not thrown', 1 === count( $pending ) && 'new code broke' === $pending[0]['diffs'][0]['error'] );

// ---------- 开 ----------
Sw::set( 'on' );
m6_check( 'on shows the new value', array( 'orders' => 3, 'sales_cents' => 5000 ) === Sw::pick( 'overview.totals', $legacy, $modern ) && Sw::on() );
m6_check( 'on still falls back when the new calculation fails', $legacy === Sw::pick( 'x', $legacy, static function () { throw new RuntimeException( 'x' ); } ) );
Sw::discard_pending();
add_filter( 'peptide_core_m6_mode', static fn( $mode ) => 'off' );
m6_check( 'a filter can force the mode off', Sw::off() && $legacy === Sw::pick( 'overview.totals', $legacy, $modern ) );
$GLOBALS['m6_filters'] = array();

// ---------- 比较 ----------
m6_check( 'numbers compare by value, not by type', Sw::same( 5, 5.0 ) && Sw::same( '12.50', 12.5 ) && ! Sw::same( 5, 6 ) );
m6_check( 'tolerance applies to numbers only', Sw::same( 10.0, 10.004, 0.005 ) && ! Sw::same( 10.0, 10.02, 0.005 ) && ! Sw::same( 'a', 'b', 5.0 ) );
m6_check( 'null only equals null', Sw::same( null, null ) && ! Sw::same( null, 0 ) && ! Sw::same( 0.0, null ) );
m6_check( 'booleans are not numbers', Sw::same( true, true ) && ! Sw::same( true, 1 ) );
m6_check( 'identical values have no differences', array() === Sw::diff( array( 'a' => 1, 'b' => array( 'c' => 2.0 ) ), array( 'a' => 1.0, 'b' => array( 'c' => 2 ) ) ) );
$nested = Sw::diff( array( 'a' => 1, 'b' => array( 'c' => 2, 'd' => 3 ) ), array( 'a' => 1, 'b' => array( 'c' => 5 ), 'e' => 9 ) );
m6_check( 'differences carry dotted paths and missing keys', array( 'b.c', 'b.d', 'e' ) === array_column( $nested, 'path' ) && null === $nested[1]['metrics'] && null === $nested[2]['legacy'] );
m6_check( 'non-scalar values are shortened for the log', '[1,2]' === Sw::diff( array( 1, 2 ), 7 )[0]['legacy'] );

// ---------- 日志上限、清空、功能声明 ----------
Sw::set( 'shadow' );
for ( $i = 0; $i < Sw::MAX_ENTRIES + 20; $i++ ) {
	Sw::pick( 'metric.' . $i, 1, static fn() => 2 );
}
Sw::flush();
m6_check( 'log keeps the newest entries only', Sw::MAX_ENTRIES === count( Sw::log()['entries'] ) );
Sw::reset_log();
m6_check( 'reset clears the log', array() === Sw::log()['entries'] && array() === Sw::log()['compared'] );
Sw::register_hooks();
$caps = apply_filters( 'pc_hq_capabilities', array( 'other' => true ) );
m6_check( 'store declares its M6 mode to the head office', true === $caps['other'] && array( 'version' => Metrics::VERSION, 'mode' => 'shadow' ) === $caps['m6_metrics'] );
m6_check( 'capability filter tolerates a non-array', isset( apply_filters( 'pc_hq_capabilities', null )['m6_metrics'] ) );

echo 'PASS ', $GLOBALS['m6_passed'], " Metrics_Switch assertions\n";
