<?php
/**
 * 应用统计页（DATA-01 第 8 步）：每个已安装的应用一张卡片，卡片里是它的各项数字，本期和对比期（VS）一起显示，口径参照 Shopname 的“应用统计”。
 *
 * 本店现在只有一个应用：离开意图增收（PeptideCore\Exit_Intent），它每天的事件次数和订单金额记在表 {prefix}pc_app_stats（app = exit_intent）。
 * 时间段按 Data\Range 换算好的店铺日期取：本期是 dates.from ～ dates.to，对比期是 dates.compare_from ～ dates.compare_to（都包含当天，按 day 列比较）。
 *
 * 离开意图的七个数字（顺序见 EXIT_METRICS）：
 * - shown 弹出次数：show 事件的次数；
 * - claims 领取 / 抽奖：claim 和 spin 两种事件的次数之和；
 * - claim_rate 领取率：claims / shown × 100，保留 2 位；弹出次数是 0 时为 null。领取是不经过弹出也会记的，所以可能大于 100%，这里和应用中心一样不封顶；
 * - wins 中奖次数：win 事件的次数；
 * - closed 关闭次数：close 事件的次数；
 * - orders 订单数：order 事件的次数（用了弹窗优惠码的订单，下单时记一次）；
 * - order_amount 订单金额：order 事件记下的订单合计，保留 2 位。
 *
 * 变化：次数和金额用 Range::change()（百分比，对比期是 0 时为 null）；领取率是两个百分比之差（百分点，保留 2 位），任一边是 null 时为 null。
 * 表没有安装时所有数字都是 0、领取率是 null；这个页面只读数据，永远不会去创建表。
 *
 * 这个类是数据类里第一个用到 Admin\* 类的（应用的名称、开关、设置页链接来自 Admin\Apps_Page），是有意这样做的：应用的清单只有一份。
 * 以后新增的应用在 summary() 里加自己的一项和它的指标清单。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

use PeptideCore\Admin\Apps_Page;
use PeptideCore\Exit_Intent;

defined( 'ABSPATH' ) || exit;

final class Apps {

	/** 离开意图应用的指标，顺序就是页面上的顺序。 */
	public const EXIT_METRICS = array( 'shown', 'claims', 'claim_rate', 'wins', 'closed', 'orders', 'order_amount' );

	/**
	 * 应用统计页的全部数字：每个应用一项，每项里是它的指标（本期、对比期、变化）。
	 *
	 * @param array $range A successful Range::from_params() result.
	 */
	public static function summary( array $range ): array {
		$dates = (array) $range['dates'];
		$apps  = array();
		$known = Apps_Page::apps();

		if ( isset( $known['exit-intent'] ) ) {
			$current  = self::exit_intent_period( (string) $dates['from'], (string) $dates['to'] );
			$previous = self::exit_intent_period( (string) $dates['compare_from'], (string) $dates['compare_to'] );

			$metrics = array();
			foreach ( self::EXIT_METRICS as $key ) {
				$kind = 'claim_rate' === $key ? 'rate' : ( 'order_amount' === $key ? 'money' : 'count' );
				$c    = $current[ $key ];
				$p    = $previous[ $key ];
				if ( 'rate' === $kind ) {
					// 领取率的变化是百分点之差，任一边没有值就没有变化。
					$change = null === $c || null === $p ? null : round( $c - $p, 2 );
				} else {
					$change = Range::change( (float) $c, (float) $p );
				}
				$metrics[] = array(
					'key'      => $key,
					'kind'     => $kind,
					'current'  => $c,
					'previous' => $p,
					'change'   => $change,
				);
			}

			$apps[] = array(
				'key'          => 'exit-intent',
				'name'         => (string) $known['exit-intent']['name'],
				'enabled'      => (bool) $known['exit-intent']['on'],
				'settings_url' => Apps_Page::url( array( 'app' => 'exit-intent' ) ),
				'metrics'      => $metrics,
			);
		}

		return array(
			'range' => $range,
			'apps'  => $apps,
		);
	}

	/**
	 * 离开意图在 [from, to]（店铺日期 Y-m-d，都包含）里的七个数字，键是 EXIT_METRICS。表没装时全是 0（领取率 null）。
	 *
	 * @return array{shown:int,claims:int,claim_rate:?float,wins:int,closed:int,orders:int,order_amount:float}
	 */
	private static function exit_intent_period( string $from, string $to ): array {
		global $wpdb;

		$count  = array_fill_keys( Exit_Intent::EVENTS, 0 );
		$amount = array_fill_keys( Exit_Intent::EVENTS, 0.0 );

		if ( Exit_Intent::installed() ) {
			$table = Exit_Intent::table();
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT event, SUM(count) AS c, SUM(amount) AS a FROM {$table} WHERE app = %s AND day >= %s AND day <= %s GROUP BY event", Exit_Intent::APP, $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$event = (string) $row['event'];
				if ( isset( $count[ $event ] ) ) {
					$count[ $event ]  = (int) $row['c'];
					$amount[ $event ] = (float) $row['a'];
				}
			}
		}

		$claims = $count['claim'] + $count['spin'];

		return array(
			'shown'        => $count['show'],
			'claims'       => $claims,
			'claim_rate'   => $count['show'] > 0 ? round( $claims / $count['show'] * 100, 2 ) : null,
			'wins'         => $count['win'],
			'closed'       => $count['close'],
			'orders'       => $count['order'],
			'order_amount' => round( $amount['order'], 2 ),
		);
	}
}
