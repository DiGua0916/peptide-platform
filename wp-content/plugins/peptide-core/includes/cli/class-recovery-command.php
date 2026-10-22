<?php
/**
 * wp peptide recovery scan|status（CART-RECOVERY-01 / 02）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Cli;

use PeptideCore\Recovery;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Recovery_Command {

	/**
	 * 立即扫描弃单并发送到期的提醒（和每 5 分钟的定时任务相同）。
	 */
	public function scan(): void {
		$r = Recovery::scan();
		if ( 'disabled' === $r['blocked'] ) {
			WP_CLI::warning( 'All abandoned cart reminders are turned off (System settings → Email notifications → Abandoned cart).' );
			return;
		}
		$why = array(
			'address' => ' No reminders sent: store address is missing.',
			'sender'  => ' No reminders sent: the support (sender) email is not valid.',
		);
		WP_CLI::success( sprintf( 'New: %d, reminders sent: %d, admin notices: %d, failed: %d, voided: %d.%s', $r['tracked'], $r['sent'], $r['admin'], $r['failed'], $r['voided'], $why[ $r['blocked'] ] ?? '' ) );
	}

	/**
	 * 显示提醒开关、下一次定时扫描、各状态数量和最近 30 天的统计。
	 */
	public function status(): void {
		$now = (int) floor( microtime( true ) * 1000 );
		$st  = Recovery::stats( $now - 30 * DAY_IN_SECONDS * 1000, $now + 1000 );
		foreach ( array( 1, 2, 3 ) as $s ) {
			$e = Recovery::email( $s );
			WP_CLI::log( sprintf( 'Stage %d: %s, delay %ds', $s, $e && $e->is_enabled() ? 'on' : 'off', $e ? $e->delay() : 0 ) );
		}
		$next = wp_next_scheduled( Recovery::CRON );
		WP_CLI::log( 'Store address: ' . ( '' !== Recovery::store_address() ? Recovery::store_address() : '(missing — reminders blocked)' ) );
		WP_CLI::log( 'Next scan: ' . ( $next ? wp_date( 'Y-m-d H:i', $next ) : '-' ) );
		WP_CLI::log( 'Records: ' . wp_json_encode( Recovery::counts() ) );
		WP_CLI::log( sprintf( '30 days: %d abandoned, %d emailed, %d recovered (%s)', $st['abandoned'], $st['emailed'], $st['recovered'], null === $st['rate'] ? '-' : $st['rate'] . '%' ) );
	}
}
