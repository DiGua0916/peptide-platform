<?php
/**
 * 邮件详情数据页（DATA-01 第 8 步）：时间段内的邮件发送数和成功率，本期和对比期（VS）一起显示，下面按邮件列出每封邮件的发送数和成功率，口径参照 Shopname 的“邮件详情数据”。
 *
 * 数据来自邮件发送记录（Emails\Log，表 {prefix}pc_email_log，每次交给邮件服务器的尝试一行）。时间段是 [from, to)（Unix 秒，店铺时区由 Data\Range 换算好），
 * 记录按 sent_ms（毫秒）落在时间段内计算；记录只保留 180 天，所以更早的时间段（或对比期）会少算。
 *
 * 合计（current / previous）：
 * - sent 发送数：记录的行数（每次尝试一行）；
 * - delivered 邮件服务器接收的数量：ok = 1 的行数（只是交给了邮件服务器，不等于对方已经收到）；
 * - success_rate 成功率：delivered / sent × 100，保留 2 位；发送数是 0 时为 null。
 *
 * 变化（change）：发送数和成功率都用 Range::change()，也就是相对百分比（和 Shopname 一样：成功率 96.77% 对 89.29% 显示 ↑ 8.38%，不是百分点）；任一边是 null、或对比期是 0 时为 null。
 *
 * 表格（rows）只有本期，和 Shopname 一样：每种邮件（email_id）一行，只列时间段内发送过的；先按发送数从大到小，相同的按名称（strcmp）。
 * 名称来自 Admin\Email_Center::names()，不在其中的邮件显示原来的 id。
 *
 * 邮件的打开数、打开率、点击数、点击率现在没有统计（发送记录只有“是否交给了邮件服务器”），所以这里没有这四项，页面上说明了。
 * 发送记录的表没装时：数字都是 0、成功率是 null、rows 是空的；这个页面只读数据，永远不会去创建表。
 *
 * 这个类是数据类里第一个用到 Admin\* 类的（邮件名称、发送记录页的链接来自 Admin\Email_Center），是有意这样做的：邮件的名称只有一份。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

use PeptideCore\Admin\Email_Center;
use PeptideCore\Emails\Log;

defined( 'ABSPATH' ) || exit;

final class Emails {

	/**
	 * 邮件详情数据页的全部数字：本期和对比期的合计、变化、本期每封邮件的发送数和成功率。
	 *
	 * @param array $range A successful Range::from_params() result.
	 */
	public static function summary( array $range ): array {
		$logging  = Log::installed();
		$current  = $logging ? self::period( (int) $range['from'], (int) $range['to'] ) : array();
		$previous = $logging ? self::period( (int) $range['compare_from'], (int) $range['compare_to'] ) : array();

		$cur_totals  = self::totals( $current );
		$prev_totals = self::totals( $previous );

		$rows = array();
		if ( $current ) {
			$names = Email_Center::names();
			foreach ( $current as $email_id => $counts ) {
				$rows[] = array(
					'email_id'     => (string) $email_id,
					'name'         => (string) ( $names[ $email_id ] ?? $email_id ),
					'sent'         => $counts['sent'],
					'delivered'    => $counts['delivered'],
					'success_rate' => self::rate( $counts['delivered'], $counts['sent'] ),
				);
			}
			// 发送数从大到小，相同的按名称。
			usort(
				$rows,
				static function ( array $a, array $b ): int {
					$cmp = $b['sent'] <=> $a['sent'];
					return 0 !== $cmp ? $cmp : strcmp( $a['name'], $b['name'] );
				}
			);
		}

		return array(
			'range'    => $range,
			'logging'  => $logging,
			'current'  => $cur_totals,
			'previous' => $prev_totals,
			'change'   => array(
				'sent'         => Range::change( (float) $cur_totals['sent'], (float) $prev_totals['sent'] ),
				'success_rate' => null === $cur_totals['success_rate'] || null === $prev_totals['success_rate'] ? null : Range::change( $cur_totals['success_rate'], $prev_totals['success_rate'] ),
			),
			'rows'     => $rows,
			'log_url'  => Email_Center::url( 'log' ),
		);
	}

	/** 成功率：delivered / sent × 100，保留 2 位；发送数是 0 时没有（null）。 */
	private static function rate( int $delivered, int $sent ): ?float {
		return $sent > 0 ? round( $delivered / $sent * 100, 2 ) : null;
	}

	/**
	 * 把每封邮件的数字加起来，得到一个时间段的合计。
	 *
	 * @param array<string,array{sent:int,delivered:int}> $period
	 * @return array{sent:int,delivered:int,success_rate:?float}
	 */
	private static function totals( array $period ): array {
		$sent      = 0;
		$delivered = 0;
		foreach ( $period as $counts ) {
			$sent      += $counts['sent'];
			$delivered += $counts['delivered'];
		}
		return array(
			'sent'         => $sent,
			'delivered'    => $delivered,
			'success_rate' => self::rate( $delivered, $sent ),
		);
	}

	/**
	 * 一个时间段 [from, to)（Unix 秒）里每封邮件的发送数和接收数。
	 *
	 * @return array<string,array{sent:int,delivered:int}> 邮件 id => 数字；没有记录的邮件不出现。
	 */
	private static function period( int $from, int $to ): array {
		global $wpdb;

		$table = Log::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT email_id, COUNT(*) AS sent, SUM(ok = 1) AS delivered FROM {$table} WHERE sent_ms >= %d AND sent_ms < %d GROUP BY email_id", $from * 1000, $to * 1000 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['email_id'] ] = array(
				'sent'      => (int) $row['sent'],
				'delivered' => (int) $row['delivered'],
			);
		}
		return $out;
	}
}
