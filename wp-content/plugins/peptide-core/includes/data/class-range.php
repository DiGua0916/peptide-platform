<?php
/**
 * 数据页面共用的时间段（DATA-01，后台“数据”板块）：店铺时区、结束日期包含当天、默认对比上一个同样长度的时间段。
 *
 * - 时间段可以是预设（today / yesterday / 7d / 30d，和概览页 Overview::window() 完全一致），
 *   也可以是自定义的 from + to（Y-m-d，都包含在内，最多 366 天）。什么都不传就是今天。
 * - 对比时间段（页面上的“VS”）：不传就是紧挨在开始日期之前、天数相同的一段（今天 → 昨天；7 天 → 之前的 7 天）；
 *   也可以用 compare_from + compare_to 指定，长度可以和主时间段不同。
 * - 所有时间戳都是 Unix 秒，开始包含、结束不包含；日期是店铺时区的 Y-m-d，其中 to / compare_to 是包含在内的最后一天。
 * - 按日历天数计算，不用 86400 秒相加：店铺时区有夏令时，一天可能是 23 或 25 小时。
 *
 * 纯计算，不挂任何钩子；九个数据页面和它们的接口都用这里的结果，不要各自再算一遍。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

use PeptideCore\Api\Errors;
use PeptideCore\Overview;

defined( 'ABSPATH' ) || exit;

final class Range {

	public const PRESETS  = array( 'today', 'yesterday', '7d', '30d' );
	public const MAX_DAYS = 366;

	/**
	 * 把请求参数变成时间段。
	 *
	 * @param array<string,mixed> $params range | from + to (Y-m-d, inclusive), optional compare_from + compare_to.
	 * @param int|null            $now    当前时间（测试用），默认 time()。
	 * @return array|\WP_Error array{preset:?string,from:int,to:int,compare_from:int,compare_to:int,days:int,bucket:string,dates:array<string,string>}，参数不对时 pc_invalid_param（400）。
	 */
	public static function from_params( array $params, ?int $now = null ) {
		$tz = wp_timezone();

		$range        = self::text( $params, 'range' );
		$from         = self::text( $params, 'from' );
		$to           = self::text( $params, 'to' );
		$compare_from = self::text( $params, 'compare_from' );
		$compare_to   = self::text( $params, 'compare_to' );

		$preset = null;
		if ( '' !== $from || '' !== $to ) {
			// 自定义时间段（同时给了 range 时以 from / to 为准）。
			$period = self::pair( $from, $to, 'from', 'to', $tz );
			if ( is_wp_error( $period ) ) {
				return $period;
			}
			list( $start, $end, $days, $from_date, $to_date ) = $period;
		} else {
			$preset = '' === $range ? 'today' : $range;
			if ( ! in_array( $preset, self::PRESETS, true ) ) {
				return Errors::invalid( array( 'range' => 'invalid_format' ) );
			}
			$window    = Overview::window( $preset, $now );
			$start     = (int) $window[0];
			$end       = (int) $window[1];
			$start_day = ( new \DateTimeImmutable( '@' . $start ) )->setTimezone( $tz );
			$end_day   = ( new \DateTimeImmutable( '@' . $end ) )->setTimezone( $tz );
			$from_date = $start_day->format( 'Y-m-d' );
			$to_date   = $end_day->modify( '-1 day' )->format( 'Y-m-d' );
			$days      = self::days_between( $from_date, $end_day->format( 'Y-m-d' ) );
		}

		if ( '' !== $compare_from || '' !== $compare_to ) {
			$compare = self::pair( $compare_from, $compare_to, 'compare_from', 'compare_to', $tz );
			if ( is_wp_error( $compare ) ) {
				return $compare;
			}
			list( $cmp_start, $cmp_end, , $cmp_from_date, $cmp_to_date ) = $compare;
		} else {
			// 默认对比：开始日期之前、天数相同的一段。
			$start_day     = ( new \DateTimeImmutable( '@' . $start ) )->setTimezone( $tz );
			$cmp_start     = $start_day->modify( '-' . $days . ' days' )->getTimestamp();
			$cmp_end       = $start;
			$cmp_from_date = $start_day->modify( '-' . $days . ' days' )->format( 'Y-m-d' );
			$cmp_to_date   = $start_day->modify( '-1 day' )->format( 'Y-m-d' );
		}

		return array(
			'preset'       => $preset,
			'from'         => $start,
			'to'           => $end,
			'compare_from' => $cmp_start,
			'compare_to'   => $cmp_end,
			'days'         => $days,
			'bucket'       => 1 === $days ? 'hour' : 'day',
			'dates'        => array(
				'from'         => $from_date,
				'to'           => $to_date,
				'compare_from' => $cmp_from_date,
				'compare_to'   => $cmp_to_date,
			),
		);
	}

	/** 变化百分比，保留 2 位小数；上一个时间段是 0 时没有意义，返回 null（页面显示“-”）。 */
	public static function change( float $current, float $previous ): ?float {
		if ( 0.0 === $previous ) {
			return null;
		}
		return round( ( $current - $previous ) * 100 / $previous, 2 );
	}

	/** 取一个文字参数：没传或为空都是 ''；只接受标量。 */
	private static function text( array $params, string $key ): string {
		$value = $params[ $key ] ?? '';
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * 校验并换算一对日期（都包含在内）。
	 *
	 * @return array{0:int,1:int,2:int,3:string,4:string}|\WP_Error 开始时间戳、结束时间戳（不含）、天数、开始日期、结束日期。
	 */
	private static function pair( string $from, string $to, string $from_key, string $to_key, \DateTimeZone $tz ) {
		$errors = array();
		if ( '' === $from ) {
			$errors[ $from_key ] = 'required';
		}
		if ( '' === $to ) {
			$errors[ $to_key ] = 'required';
		}
		if ( $errors ) {
			return Errors::invalid( $errors );
		}

		$first = self::day( $from, $tz );
		$last  = self::day( $to, $tz );
		if ( null === $first ) {
			$errors[ $from_key ] = 'invalid_format';
		}
		if ( null === $last ) {
			$errors[ $to_key ] = 'invalid_format';
		}
		if ( $errors ) {
			return Errors::invalid( $errors );
		}
		if ( $first > $last ) {
			return Errors::invalid( array( $from_key => 'invalid_format' ) );
		}

		$days = self::days_between( $from, $to ) + 1;
		if ( $days > self::MAX_DAYS ) {
			return Errors::invalid( array( $to_key => 'invalid_format' ) );
		}

		// 结束 = 最后一天的第二天 0 点（店铺时区）。
		return array( $first->getTimestamp(), $last->modify( '+1 day' )->getTimestamp(), $days, $from, $to );
	}

	/** Y-m-d 且是真实存在的日期，返回店铺时区当天 0 点；否则 null。 */
	private static function day( string $value, \DateTimeZone $tz ): ?\DateTimeImmutable {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $tz );
		return ( false !== $day && $day->format( 'Y-m-d' ) === $value ) ? $day : null;
	}

	/** 两个日期（Y-m-d）相差的日历天数。用 UTC 算，不受夏令时影响。 */
	private static function days_between( string $from, string $to ): int {
		$utc = new \DateTimeZone( 'UTC' );
		return (int) ( new \DateTimeImmutable( $from, $utc ) )->diff( new \DateTimeImmutable( $to, $utc ) )->days;
	}
}
