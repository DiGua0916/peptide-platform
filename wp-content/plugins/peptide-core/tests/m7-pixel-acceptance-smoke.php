<?php
/**
 * FE-09 验收脚本：一家店跑一遍完整下单，核对 pc_px_log 里六类事件都由服务端发送成功，并且和浏览器事件 event_id 一致。
 * 在店铺所在的服务器上、店铺目录里执行（不是纯逻辑测试；会读写这家店的选项和发送记录表，不改订单和商品）。
 *
 *   wp eval-file wp-content/plugins/peptide-core/tests/m7-pixel-acceptance-smoke.php start      # 开始验收，给出带验收码的链接（30 分钟有效）
 *   …用这个链接在手机上打开店铺：看商品 → 加购 → 结账 → 选支付 → 付款 → 回到下单成功页…
 *   wp eval-file wp-content/plugins/peptide-core/tests/m7-pixel-acceptance-smoke.php check      # 核对，全部通过时退出码 0，否则 1
 *   wp eval-file wp-content/plugins/peptide-core/tests/m7-pixel-acceptance-smoke.php stop       # 结束验收
 *   wp eval-file wp-content/plugins/peptide-core/tests/m7-pixel-acceptance-smoke.php test       # 只发一条测试 PageView，看像素 ID 和令牌通不通
 *
 * 逐店操作清单见 docs/guides/m7-pixel-acceptance-checklist.md。像素 ID 和 CAPI 令牌由投手或平台所有者在后台自己填，这个脚本不经手。
 */

use PeptideCore\Pixels\Acceptance;
use PeptideCore\Pixels\Server;

if ( ! defined( 'ABSPATH' ) || ! class_exists( Acceptance::class ) ) {
	fwrite( STDERR, "请用 wp eval-file 在店铺目录里运行，并确认 peptide-core 已启用。\n" );
	exit( 1 );
}

$m7_action = isset( $args[0] ) ? (string) $args[0] : 'check';
$m7_say    = static function ( string $text ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		\WP_CLI::log( $text );
	} else {
		echo $text, "\n";
	}
};
$m7_exit   = static function ( int $code ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		\WP_CLI::halt( $code );
	}
	exit( $code );
};

if ( 'start' === $m7_action ) {
	$m7_started = Acceptance::start( isset( $args[1] ) ? (int) $args[1] : 30 );
	$m7_say( '验收已开始，验收码有效到 ' . wp_date( 'Y-m-d H:i', $m7_started['until'] ) . '（店铺时区）。' );
	$m7_say( '用下面这个链接打开店铺（最好在手机上，从 Facebook 里点开更接近真实情况），然后走完：看商品 → 加购 → 结账 → 选支付 → 付款 → 回到下单成功页。' );
	$m7_say( $m7_started['url'] );
	$m7_config = Acceptance::config();
	foreach ( $m7_config['problems'] as $m7_problem ) {
		$m7_say( '先处理配置：' . $m7_problem );
	}
	$m7_exit( 0 );
}

if ( 'stop' === $m7_action ) {
	Acceptance::stop();
	$m7_say( '验收已结束。统计设置里如果填了“测试事件代码”，记得清空。' );
	$m7_exit( 0 );
}

if ( 'test' === $m7_action ) {
	$m7_test = Server::test_event();
	if ( 'no_pixel' === $m7_test['reason'] ) {
		$m7_say( '未通：这家店还没有保存 Facebook 像素。' );
		$m7_exit( 1 );
	}
	foreach ( $m7_test['results'] as $m7_row ) {
		$m7_say( sprintf( '%s  %s', $m7_row['pixel'], $m7_row['ok'] ? '已通' : ( 'no_token' === $m7_row['message'] ? '没有填 CAPI 令牌' : '未通（HTTP ' . $m7_row['code'] . '）' . $m7_row['message'] ) ) );
	}
	$m7_say( $m7_test['ok'] ? '结果：已通。' . ( $m7_test['test_code'] ? '在事件管理器 → 测试事件里能看到这条 PageView。' : '' ) : '结果：未通。' );
	$m7_exit( $m7_test['ok'] ? 0 : 1 );
}

if ( 'check' !== $m7_action ) {
	$m7_say( '用法：start [分钟] | check | stop | test' );
	$m7_exit( 1 );
}

// 服务端回传是放进后台队列发的：先把到期的任务跑掉，再核对。
if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
	try {
		\ActionScheduler_QueueRunner::instance()->run();
	} catch ( \Throwable $m7_error ) {
		$m7_say( '提醒：后台任务没有跑起来（' . $m7_error->getMessage() . '），如果下面显示“服务端没发”，等一分钟再核对一次。' );
	}
}
$m7_result = Acceptance::check();
$m7_say( Acceptance::report( $m7_result ) );
$m7_exit( empty( $m7_result['pass'] ) ? 1 : 0 );
