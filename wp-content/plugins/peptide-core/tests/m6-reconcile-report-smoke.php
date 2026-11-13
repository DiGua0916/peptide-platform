<?php
/**
 * NF-02 对账报告：在一家店（或脱敏快照）上，把旧口径和 Data\Metrics 的差异逐张订单归到原因里。只读，不改任何数据。
 *
 *   wp eval-file wp-content/plugins/peptide-core/tests/m6-reconcile-report-smoke.php            # 今天、昨天、近 7 天、近 30 天
 *   wp eval-file wp-content/plugins/peptide-core/tests/m6-reconcile-report-smoke.php 30d        # 只看一个时间段
 *
 * 有归不进任何原因的差异时退出码是 1（开发文档 12.5：“新旧报表差异都能解释”才能往下走）。
 * 同时打印：M6 开关现在是哪一档、影子模式的对账日志摘要、漏斗跳步的异常数。
 */

use PeptideCore\Data\Metrics;
use PeptideCore\Data\Metrics_Reconcile;
use PeptideCore\Data\Metrics_Switch;
use PeptideCore\Tracking\Funnel_Guard;

if ( ! defined( 'ABSPATH' ) || ! class_exists( Metrics_Reconcile::class ) ) {
	fwrite( STDERR, "请用 wp eval-file 在店铺目录里运行，并确认 peptide-core 已启用。\n" );
	exit( 1 );
}

$m6_say     = static function ( string $text ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		\WP_CLI::log( $text );
	} else {
		echo $text, "\n";
	}
};
$m6_money   = static fn( int $cents ): string => number_format( $cents / 100, 2 );
$m6_presets = isset( $args[0] ) && in_array( $args[0], array( 'today', 'yesterday', '7d', '30d' ), true ) ? array( $args[0] ) : array( 'today', 'yesterday', '7d', '30d' );
$m6_clean   = true;

$m6_say( 'M6 开关：' . Metrics_Switch::mode() . '　口径版本：' . Metrics::VERSION );
foreach ( $m6_presets as $m6_preset ) {
	$m6_report = Metrics_Reconcile::report( $m6_preset );
	$m6_orders = $m6_report['orders'];
	$m6_say( '' );
	$m6_say( '== ' . $m6_preset . '（' . $m6_report['from'] . ' ～ ' . $m6_report['to'] . '）==' );
	$m6_say( sprintf( '订单数    旧 %d　新 %d　差 %+d', $m6_orders['legacy']['orders'], $m6_orders['metrics']['orders'], $m6_orders['delta']['orders'] ) );
	$m6_say( sprintf( '销售额    旧 %s　新 %s　差 %s', $m6_money( $m6_orders['legacy']['sales_cents'] ), $m6_money( $m6_orders['metrics']['sales_cents'] ), $m6_money( $m6_orders['delta']['sales_cents'] ) ) );
	foreach ( $m6_orders['reasons'] as $m6_key => $m6_reason ) {
		$m6_say( sprintf( '  %s：订单 %+d，金额 %s。%s 订单号：%s', $m6_key, $m6_reason['orders'], $m6_money( $m6_reason['cents'] ), $m6_reason['why'], implode( ',', $m6_reason['ids'] ) ) );
	}
	$m6_visitors = $m6_report['visitors'];
	$m6_say( sprintf( '访客      旧 %d　新 %d　差 %+d（访客行已清掉的访问 %d，测试标记 %d，没解释的 %d）', $m6_visitors['legacy'], $m6_visitors['metrics'], $m6_visitors['delta'], -1 * ( $m6_visitors['reasons']['orphan_visits']['visitors'] ?? 0 ), -1 * ( $m6_visitors['reasons']['test_flag']['visitors'] ?? 0 ), $m6_visitors['unexplained'] ) );
	$m6_say( sprintf( '转化率    旧 %s　新 %s。%s', null === $m6_report['conversion']['legacy'] ? '-' : $m6_report['conversion']['legacy'] . '%', null === $m6_report['conversion']['metrics'] ? '-' : $m6_report['conversion']['metrics'] . '%', $m6_report['conversion']['why'] ) );
	$m6_say( sprintf( '弃单激活  旧 发信 %d / 成功 %d　新 发信 %d / 成功 %d。%s', $m6_report['recovery']['legacy']['emailed'], $m6_report['recovery']['legacy']['recovered'], $m6_report['recovery']['metrics']['emailed'], $m6_report['recovery']['metrics']['recovered'], $m6_report['recovery']['why'] ) );
	$m6_window  = Metrics::window( $m6_preset );
	$m6_skipped = Funnel_Guard::anomalies( $m6_window['from'], $m6_window['to'] );
	$m6_say( sprintf( '漏斗跳步  没有填地址记录却下单 / 付款的访问 %d 次（下单事件 %d，付款事件 %d）', $m6_skipped['visits'], $m6_skipped['placed'], $m6_skipped['paid'] ) );
	if ( ! $m6_report['explained'] ) {
		$m6_clean = false;
		$m6_say( '!! 还有没解释的差异，订单号：' . implode( ',', $m6_orders['unexplained']['ids'] ) );
	}
}

$m6_log = Metrics_Switch::log();
$m6_say( '' );
$m6_say( '影子对账日志（自 ' . wp_date( 'Y-m-d H:i', $m6_log['since'] ) . '）：' );
foreach ( $m6_log['compared'] as $m6_metric => $m6_count ) {
	$m6_say( sprintf( '  %s：比了 %d 次，不一致 %d 次', $m6_metric, $m6_count, (int) ( $m6_log['differed'][ $m6_metric ] ?? 0 ) ) );
}
if ( ! $m6_log['compared'] ) {
	$m6_say( '  还没有记录（开关是 off，或者切到 shadow 之后还没有人打开过概览页 / 数据页）。' );
}
foreach ( Funnel_Guard::counters( 7 ) as $m6_day => $m6_row ) {
	$m6_say( sprintf( '  %s 漏斗规则：下单时没地址 %d，付款时没地址 %d，事件晚到后补上 %d，测试单 %d', $m6_day, $m6_row['no_address_4'], $m6_row['no_address_5'], $m6_row['settled'], $m6_row['test_order'] ) );
}

$m6_say( '' );
$m6_say( $m6_clean ? '结果：订单和访客的差异都有解释。' : '结果：有没解释的差异，先不要切换开关。' );
if ( class_exists( 'WP_CLI' ) ) {
	\WP_CLI::halt( $m6_clean ? 0 : 1 );
}
exit( $m6_clean ? 0 : 1 );
