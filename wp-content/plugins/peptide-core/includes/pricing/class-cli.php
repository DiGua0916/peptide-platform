<?php
/**
 * 命令行：价格引擎的档位和核对记录（M4 / NF-02）。
 *
 *   wp peptide price-engine status            现在的档位、最近 7 天的核对次数
 *   wp peptide price-engine mode shadow       改档位：off / shadow / on
 *   wp peptide price-engine log [--limit=20]  最近的不一致 / 出错 / 跳过明细
 *   wp peptide price-engine clear             清空核对记录（切换档位、重新观察之前）
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/** 现在的档位和最近 7 天的核对次数。 */
	public function status(): void {
		$s = Runtime::status();
		\WP_CLI::line( '档位：' . $s['mode'] . ( $s['locked'] ? '（被 wp-config.php 锁定）' : '' ) );
		if ( ! $s['summary']['totals'] ) {
			\WP_CLI::line( '最近 7 天没有核对记录。' );
			return;
		}
		foreach ( $s['summary']['totals'] as $mode => $t ) {
			\WP_CLI::line( sprintf( '%s：核对 %d 次，一致 %d，不一致 %d，跳过 %d，出错 %d', $mode, $t['compared'], $t['matched'], $t['mismatched'], $t['skipped'], $t['errors'] ) );
		}
	}

	/**
	 * 改档位。
	 *
	 * ## OPTIONS
	 *
	 * <mode>
	 * : off（关）、shadow（影子）或 on（开）。
	 */
	public function mode( array $args ): void {
		$done = Mode::set( (string) ( $args[0] ?? '' ) );
		if ( is_wp_error( $done ) ) {
			\WP_CLI::error( $done->get_error_message() );
		}
		\WP_CLI::success( '价格引擎档位：' . Mode::get() );
	}

	/**
	 * 最近的不一致 / 出错 / 跳过明细。
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : 条数，默认 20，最多 100。
	 */
	public function log( array $args, array $assoc ): void {
		$rows = Shadow::summary( 7, (int) ( $assoc['limit'] ?? 20 ) )['recent'];
		if ( ! $rows ) {
			\WP_CLI::line( '没有明细。' );
			return;
		}
		foreach ( $rows as $row ) {
			\WP_CLI::line( sprintf( '#%d %s UTC [%s/%s] 线上 %s 分 / 引擎 %s 分', $row['id'], $row['created_gmt'], $row['mode'], $row['kind'], $row['legacy_cents'], $row['engine_cents'] ) );
			\WP_CLI::line( '   ' . wp_json_encode( $row['detail'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		}
	}

	/** 清空核对记录。 */
	public function clear(): void {
		Shadow::clear();
		\WP_CLI::success( '核对记录已清空。' );
	}
}
