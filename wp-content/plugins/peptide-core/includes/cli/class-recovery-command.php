<?php
/**
 * wp peptide recovery scan|status（CART-RECOVERY-01）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Cli;

use PeptideCore\Recovery;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Recovery_Command {

	/**
	 * 立即扫描弃单并按设置发送到期的提醒（和每 10 分钟的定时任务相同）。
	 */
	public function scan(): void {
		$r = Recovery::scan();
		if ( 'disabled' === $r['blocked'] ) {
			WP_CLI::warning( 'Recovery is turned off (Orders → Abandoned carts).' );
			return;
		}
		WP_CLI::success( sprintf( 'New: %d, reminders sent: %d, closed: %d, expired: %d.%s', $r['tracked'], $r['sent'], $r['closed'], $r['expired'], 'address' === $r['blocked'] ? ' No reminders sent: store address is missing.' : '' ) );
	}

	/**
	 * 显示开关、下一次定时扫描和最近 30 天的统计。
	 */
	public function status(): void {
		$s   = Recovery::settings();
		$now = (int) floor( microtime( true ) * 1000 );
		$st  = Recovery::stats( $now - 30 * DAY_IN_SECONDS * 1000, $now + 1000 );
		$next = wp_next_scheduled( Recovery::CRON );
		WP_CLI::log( 'Enabled: ' . ( $s['enabled'] ? 'yes' : 'no' ) );
		WP_CLI::log( 'Store address: ' . ( '' !== Recovery::store_address() ? Recovery::store_address() : '(missing — reminders blocked)' ) );
		WP_CLI::log( 'Next scan: ' . ( $next ? wp_date( 'Y-m-d H:i', $next ) : '-' ) );
		WP_CLI::log( sprintf( '30 days: %d abandoned, %d emailed, %d recovered (%s)', $st['abandoned'], $st['emailed'], $st['recovered'], null === $st['rate'] ? '-' : $st['rate'] . '%' ) );
	}
}
