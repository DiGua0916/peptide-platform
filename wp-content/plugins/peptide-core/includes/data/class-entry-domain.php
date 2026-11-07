<?php
/**
 * 运营数据页的“入口域名”筛选（对照参考后台的“请选择域名”）。
 *
 * 口径：一次访问的入口域名 = visits.attribution_host，只认 attribution_version = 1 且在时间段结束前（cutoff）已保存的值，
 * 和 Report_Filter / Report_Dimensions::visit_facts() 的 entry_domain 判断一致。订单按订单上记录的访问（ORDER_VISIT_META）归属；
 * 没有关联访问、或访问没有入口域名的订单，不属于任何域名。
 *
 * 只在请求里明确带了 entry_domain 时生效；不带时各报表走原来的查询，结果不变。
 */
namespace PeptideCore\Data;

use PeptideCore\Api\Errors;
use PeptideCore\Tracking\Attribution;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;

defined( 'ABSPATH' ) || exit;

final class Entry_Domain {

	/** 下拉里最多列出的域名个数。 */
	public const OPTIONS_LIMIT = 50;

	/**
	 * 请求参数 entry_domain：没带或空字符串 = 不筛选（null）；合法域名 = 规范化后的小写域名；其他 = 400。
	 *
	 * @return string|null|\WP_Error
	 */
	public static function param( array $params ) {
		if ( ! array_key_exists( 'entry_domain', $params ) || '' === $params['entry_domain'] ) {
			return null;
		}
		$host = Attribution::host( $params['entry_domain'] );
		return null === $host ? Errors::invalid( array( 'entry_domain' => 'invalid_format' ) ) : $host;
	}

	/**
	 * 追加在 visits 查询 WHERE 后面的条件和参数。$alias 是表别名（例如 's.'），没有别名传空字符串。
	 *
	 * @return array{0:string,1:array}
	 */
	public static function sql( string $alias, string $host, int $cutoff_ms ): array {
		return array(
			" AND {$alias}attribution_version = 1 AND {$alias}attribution_host = %s AND {$alias}attribution_ms < %d",
			array( $host, $cutoff_ms ),
		);
	}

	/**
	 * 只留下归属这个入口域名的订单（按订单记录的访问）。顺序不变。
	 *
	 * @param \WC_Order[] $orders
	 * @return \WC_Order[]
	 */
	public static function orders( array $orders, string $host, int $cutoff_ms ): array {
		if ( ! $orders || ! Schema::installed() ) {
			return array();
		}
		$visit_of = array();
		foreach ( $orders as $order ) {
			$visit = (int) $order->get_meta( Server_Events::ORDER_VISIT_META );
			if ( $visit > 0 ) {
				$visit_of[ (int) $order->get_id() ] = $visit;
			}
		}
		$matched = self::matching_visits( array_values( array_unique( $visit_of ) ), $host, $cutoff_ms );
		return array_values(
			array_filter(
				$orders,
				static function ( $order ) use ( $visit_of, $matched ): bool {
					$visit = $visit_of[ (int) $order->get_id() ] ?? 0;
					return $visit > 0 && isset( $matched[ $visit ] );
				}
			)
		);
	}

	/**
	 * 一组访问里入口域名是 $host 的那些（id => true）。
	 *
	 * @param int[] $visit_ids
	 * @return array<int,bool>
	 */
	public static function matching_visits( array $visit_ids, string $host, int $cutoff_ms ): array {
		global $wpdb;
		$out    = array();
		$visits = Schema::table( 'visits' );
		foreach ( array_chunk( array_values( array_filter( array_map( 'intval', $visit_ids ) ) ), 500 ) as $chunk ) {
			$marks = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			list( $where, $args ) = self::sql( '', $host, $cutoff_ms );
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$visits} WHERE id IN ({$marks}){$where}", array_merge( $chunk, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $ids as $id ) {
				$out[ (int) $id ] = true;
			}
		}
		return $out;
	}

	/**
	 * 下拉选项：时间段 [from, to)（Unix 秒）里出现过的入口域名，按访问次数从多到少，最多 OPTIONS_LIMIT 个。
	 *
	 * @return array<int,array{value:string,visits:int}>
	 */
	public static function options( int $from, int $to ): array {
		global $wpdb;
		if ( ! Schema::installed() ) {
			return array();
		}
		$visits = Schema::table( 'visits' );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT attribution_host AS h, COUNT(*) AS n FROM {$visits} WHERE start_ms >= %d AND start_ms < %d AND attribution_version = 1 AND attribution_host IS NOT NULL AND attribution_host <> '' AND attribution_ms < %d GROUP BY attribution_host ORDER BY n DESC, attribution_host ASC LIMIT %d", $from * 1000, $to * 1000, $to * 1000, self::OPTIONS_LIMIT ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out    = array();
		foreach ( (array) $rows as $row ) {
			$host = Attribution::host( (string) $row['h'] );
			if ( null !== $host ) {
				$out[] = array( 'value' => $host, 'visits' => (int) $row['n'] );
			}
		}
		return $out;
	}
}
