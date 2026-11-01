<?php
/**
 * 页面访问统计页（DATA-01 第 5 步）：每个页面路径一行，访问量、访问用户、跳出率；可按路径搜索、按三个数字排序、分页。口径见 Shopname 帮助中心“页面访问统计”。
 *
 * 时间段是 [from, to)（Unix 秒，店铺时区由 Data\Range 换算好）；这个页面没有对比期。事件按 occurred_ms（毫秒）落在时间段内计算，访问按 start_ms 落在时间段内计算。
 * 路径 = 浏览器上报 page_view 事件时的 location.pathname（只有路径，没有参数；已经带上 WordPress 所在的子目录）；时间段内至少有一次 page_view 的路径才出现。
 *
 * 每个路径 3 个数字（再加一个中间值 bounced）：
 * - views 访问量：这个路径的 page_view 事件数；
 * - visitors 访问用户：这些事件里不同的访客数；
 * - bounced 跳出用户（不直接显示）：时间段内开始的访问里，落地页（landing_path）是这个路径、而且整个访问只看了 1 个页面（pageviews = 1）的不同访客数；
 * - bounce_rate 跳出率：min( 100, bounced / visitors × 100 )，保留 2 位；没有访问用户时为 null。
 *   Shopname 的说明：跳出率为仅访问该页面就离开的用户 / 访问该页面的所有用户。
 *
 * 排序按 sort（views、visitors、bounce_rate，默认 views）和 order（desc 默认 / asc）的值（null 当作 -1），相同的按路径从小到大（strcmp）；然后分页；
 * 可选 q 按路径包含的文字过滤（不分大小写），在分页之前。页码超过最后一页返回空的 rows。
 * 访客追踪的表没装时，tracking 为 false，total 是 0，rows 是空的。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

use PeptideCore\Api\Errors;
use PeptideCore\Tracking\Schema;

defined( 'ABSPATH' ) || exit;

final class Page_Visits {

	/** 可以排序的三个数字。 */
	public const METRICS = array( 'views', 'visitors', 'bounce_rate' );

	/**
	 * 页面访问统计页的全部数字：时间段内每个页面路径一行，排序、搜索、分页之后的一页。
	 *
	 * @param array $range A successful Range::from_params() result.
	 * @param array $args  sort, order, page, per_page, q (already validated by params()).
	 */
	public static function table( array $range, array $args ): array {
		$selection = self::selection( $range, $args );
		$total = count( $selection['paths'] );
		$page = $selection['page']; $per_page = $selection['per_page'];
		$slice = $page > (int) ceil( $total / $per_page ) ? array() : array_slice( $selection['paths'], ( $page - 1 ) * $per_page, $per_page );
		$rows = array();
		foreach ( $slice as $row ) { $rows[] = self::assemble_row( $row, $selection['origin'] ); }
		return array(
			'range'    => $range,
			'tracking' => Schema::installed(),
			'sort'     => $selection['sort'],
			'order'    => $selection['order'],
			'page'     => $selection['page'],
			'per_page' => $selection['per_page'],
			'q'        => $selection['q'],
			'total'    => $total,
			'rows'     => $rows,
		);
	}


	/** Same filtered/sorted paths as table(), without pagination. */
	public static function all_rows( array $range, array $args ): iterable {
		$selection = self::selection( $range, $args );
		foreach ( $selection['paths'] as $row ) { yield self::assemble_row( $row, $selection['origin'] ); }
	}

	private static function selection( array $range, array $args ): array {
		$sort     = in_array( $args['sort'] ?? '', self::METRICS, true ) ? (string) $args['sort'] : 'views';
		$order    = 'asc' === ( $args['order'] ?? 'desc' ) ? 'asc' : 'desc';
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$q        = (string) ( $args['q'] ?? '' );

		$paths = self::period( (int) $range['from'], (int) $range['to'] );

		if ( '' !== $q ) {
			$paths = array_values(
				array_filter(
					$paths,
					static fn( array $row ): bool => false !== mb_stripos( $row['path'], $q )
				)
			);
		}

		// 排序按值（null 当作 -1），相同的按路径从小到大。
		$value = static function ( array $row ) use ( $sort ) {
			return null === $row[ $sort ] ? -1 : $row[ $sort ];
		};
		usort(
			$paths,
			static function ( array $a, array $b ) use ( $value, $order ): int {
				$cmp = 'asc' === $order ? $value( $a ) <=> $value( $b ) : $value( $b ) <=> $value( $a );
				return 0 !== $cmp ? $cmp : strcmp( $a['path'], $b['path'] );
			}
		);

		// 链接 = 站点的协议 + 域名（+ 端口）+ 存下来的路径。存下来的路径是浏览器的 location.pathname，已经带着 WordPress 的子目录，所以不能再用 home_url( $path )。
		$home   = (array) wp_parse_url( home_url() );
		$origin = empty( $home['host'] ) ? '' : ( $home['scheme'] ?? 'http' ) . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		return compact( 'sort', 'order', 'page', 'per_page', 'q', 'paths', 'origin' );
	}

	private static function assemble_row( array $row, string $origin ): array {
		return array(
			'path'        => $row['path'],
			'url'         => $origin . $row['path'],
			'views'       => $row['views'],
			'visitors'    => $row['visitors'],
			'bounced'     => $row['bounced'],
			'bounce_rate' => $row['bounce_rate'],
		);
	}

	/**
	 * 校验并整理 sort / order / page / per_page / q。
	 *
	 * @param array<string,mixed> $params Request parameters.
	 * @return array{sort:string,order:string,page:int,per_page:int,q:string}|\WP_Error 参数不对时 pc_invalid_param（400）。
	 */
	public static function params( array $params ) {
		$errors = array();

		$sort = Params::word( $params, 'sort', 'views', self::METRICS );
		if ( null === $sort ) {
			$errors['sort'] = 'invalid_format';
		}
		$order = Params::word( $params, 'order', 'desc', array( 'asc', 'desc' ) );
		if ( null === $order ) {
			$errors['order'] = 'invalid_format';
		}
		$page = Params::whole( $params, 'page', 1, 1, 999999999 );
		if ( null === $page ) {
			$errors['page'] = 'invalid_format';
		}
		$per_page = Params::whole( $params, 'per_page', 20, 1, 100 );
		if ( null === $per_page ) {
			$errors['per_page'] = 'invalid_format';
		}
		if ( $errors ) {
			return Errors::invalid( $errors );
		}

		// 搜索词不会出错：去掉首尾空白，最多 100 个字符。
		$q = isset( $params['q'] ) && is_scalar( $params['q'] ) ? mb_substr( trim( (string) $params['q'] ), 0, 100 ) : '';

		return array(
			'sort'     => $sort,
			'order'    => $order,
			'page'     => $page,
			'per_page' => $per_page,
			'q'        => $q,
		);
	}

	/**
	 * 一个时间段 [from, to)（Unix 秒）里每个页面路径的数字。
	 *
	 * @return array<int,array{path:string,views:int,visitors:int,bounced:int,bounce_rate:?float}> 没有排序；追踪的表没装时是空数组。
	 */
	private static function period( int $from, int $to ): array {
		global $wpdb;
		if ( ! Schema::installed() ) {
			return array();
		}

		$events = Schema::table( 'events' );
		$visits = Schema::table( 'visits' );
		$fm     = $from * 1000;
		$tm     = $to * 1000;

		// 访问量和访问用户：每个路径的 page_view 事件数和不同访客数。
		$views = $wpdb->get_results( $wpdb->prepare( "SELECT path, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS visitors FROM {$events} WHERE type = 'page_view' AND occurred_ms >= %d AND occurred_ms < %d GROUP BY path", $fm, $tm ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		// 跳出用户：时间段内开始、只看了 1 个页面的访问，按落地页分组的不同访客数。
		$bounces = $wpdb->get_results( $wpdb->prepare( "SELECT landing_path, COUNT(DISTINCT visitor_id) AS bounced FROM {$visits} WHERE start_ms >= %d AND start_ms < %d AND pageviews = 1 GROUP BY landing_path", $fm, $tm ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		// 两个查询按路径在 PHP 里合并。MySQL 分组时按表的排序规则不分大小写（/A/ 和 /a/ 是同一组，只返回其中一个写法），
		// 所以两边都用 strtolower( 路径 ) 做键；显示用 page_view 那边 MySQL 返回的路径。落地页里有、page_view 里没有的路径忽略。
		$bounced = array();
		foreach ( (array) $bounces as $row ) {
			$bounced[ strtolower( (string) $row['landing_path'] ) ] = (int) $row['bounced'];
		}

		$out = array();
		foreach ( (array) $views as $row ) {
			$path     = (string) $row['path'];
			$visitors = (int) $row['visitors'];
			$count    = $bounced[ strtolower( $path ) ] ?? 0;
			$out[]    = array(
				'path'        => $path,
				'views'       => (int) $row['views'],
				'visitors'    => $visitors,
				'bounced'     => $count,
				'bounce_rate' => $visitors > 0 ? round( min( 100.0, $count / $visitors * 100 ), 2 ) : null,
			);
		}
		return $out;
	}
}
