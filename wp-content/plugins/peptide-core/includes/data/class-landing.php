<?php
/**
 * 落地页转化页（DATA-01 第 9 步）：每个落地页路径一行，UV、顾客数、查看 / 加购 / 付款 UV、订单数、转化率、销售额、客单价、UV 价值；可按路径搜索、按十个数字排序、分页。
 * Shopname 的帮助中心没有这个页面的说明，口径按列名和我们其他数据页已经用的规则定。这个页面没有对比期。
 *
 * 还没做：Shopname 可以显示落地页的链接参数（“显示 URL 参数”）。我们的访问只存了落地页的路径（landing_path，没有参数），所以现在不能显示；以后要存访问的查询串或 UTM 字段。
 *
 * 时间段是 [from, to)（Unix 秒，店铺时区由 Data\Range 换算好）。
 *
 * 访问 = start_ms 落在时间段内的访问。行的键 = strtolower( landing_path )，所以 /A/ 和 /a/ 是同一行；
 * - 行的名称（path）= 这一行在时间段内 id 最小的访问存下来的路径（按 id 顺序读）；只通过订单出现的键（访问在时间段之前开始），用这些订单的访问里 id 最小的那个访问的路径。
 *
 * 已付款订单 = Operations::paid_orders() 去掉测试订单（Overview::is_test_order）。每个订单归到它 _pc_track_visit 对应访问的 landing_path 那一行（即使那次访问在时间段之前开始）；
 * 没有这个 meta、访问行不存在、或访问的落地页是空字符串（以服务端事件开始的访问，例如 Store API 加购）的订单归到路径为 '' 的那一行。
 *
 * 每行的十个数字（顺序见 METRICS）：
 * - uv：这一行的访问里不同的 visitor_id 数；
 * - customers 顾客数：不同的键数——访问里 user_id > 0 的 'u' + user_id，加上这一行已付款订单的买家键（和运营数据的顾客数同一个规则：有账号 'u' + 账号 id，游客 'e' + 小写账单邮箱，没有邮箱的游客订单没有键）；
 * - viewed_uv / carted_uv：这一行的访问里 stage ≥ 1 / ≥ 2 的不同访客数；
 * - paid_uv：这一行已付款订单 _pc_track_visitor 里不同的访客 id（> 0）；
 * - orders：这一行的已付款订单数；
 * - conversion：orders / uv × 100，保留 2 位，uv 为 0 时为 null（和运营数据的转化率同一个规则，所以可以超过 100）；
 * - sales：订单金额 - 已退款金额 的和（和运营数据的销售额一样），保留 2 位；
 * - aov：sales / orders，保留 2 位，没有订单时是 0.0；
 * - uv_value：sales / uv，保留 2 位，uv 为 0 时为 null。
 * uv 或 orders 大于 0 的行才出现。路径为 '' 的行页面上显示“未知落地页”，接口里 path 仍然是 ''，url 也是 ''。
 *
 * 排序按 sort（METRICS 里的一个，默认 uv）和 order（desc 默认 / asc）的值（null 当作 -1），相同的按路径从小到大（strcmp，所以 '' 排最前）；然后分页；
 * 可选 q 按路径包含的文字过滤（不分大小写），在分页之前；路径为 '' 的行只在 q 为空时出现。页码超过最后一页返回空的 rows。
 * 访客追踪的表没装时，tracking 为 false，每个已付款订单都在 '' 那一行（uv 是 0）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

use PeptideCore\Api\Errors;
use PeptideCore\Overview;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;

defined( 'ABSPATH' ) || exit;

final class Landing {

	/** 每行的十个数字，顺序就是表格列的顺序，也是可以排序的值。 */
	public const METRICS = array( 'uv', 'customers', 'viewed_uv', 'carted_uv', 'paid_uv', 'orders', 'conversion', 'sales', 'aov', 'uv_value' );

	/**
	 * 落地页转化页的全部数字：时间段内每个落地页路径一行，搜索、排序、分页之后的一页。
	 *
	 * @param array $range A successful Range::from_params() result.
	 * @param array $args  sort, order, page, per_page, q (already validated by params()).
	 */
	public static function table( array $range, array $args ): array {
		$sort     = in_array( $args['sort'] ?? '', self::METRICS, true ) ? (string) $args['sort'] : 'uv';
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

		$total = count( $paths );
		$slice = $page > (int) ceil( $total / $per_page ) ? array() : array_slice( $paths, ( $page - 1 ) * $per_page, $per_page );

		// 链接 = 站点的协议 + 域名（+ 端口）+ 存下来的路径。存下来的路径是浏览器的 location.pathname，已经带着 WordPress 的子目录，所以不能再用 home_url( $path )。
		$home   = (array) wp_parse_url( home_url() );
		$origin = empty( $home['host'] ) ? '' : ( $home['scheme'] ?? 'http' ) . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );

		$rows = array();
		foreach ( $slice as $row ) {
			$out = array(
				'path' => $row['path'],
				'url'  => '' === $row['path'] ? '' : $origin . $row['path'],
			);
			foreach ( self::METRICS as $key ) {
				$out[ $key ] = $row[ $key ];
			}
			$rows[] = $out;
		}

		return array(
			'range'    => $range,
			'tracking' => Schema::installed(),
			'sort'     => $sort,
			'order'    => $order,
			'page'     => $page,
			'per_page' => $per_page,
			'q'        => $q,
			'total'    => $total,
			'rows'     => $rows,
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

		$sort = Params::word( $params, 'sort', 'uv', self::METRICS );
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
	 * 一个时间段 [from, to)（Unix 秒）里每个落地页路径的数字。
	 *
	 * 时间段内的访问一次全部读出来，在 PHP 里按路径分组。访问特别多的店铺以后要改成在 SQL 里分组（GROUP BY LOWER( landing_path )）并只取当前页的行；现在先不做。
	 *
	 * @return array<int,array<string,mixed>> 每行 path 加 METRICS 的十个数字；没有排序，没有搜索。
	 */
	private static function period( int $from, int $to ): array {
		global $wpdb;
		$tracking = Schema::installed();

		// 键 => 一行的中间数据。名称取最小 id 的访问，所以访问按 id 顺序读。
		$groups = array();

		if ( $tracking ) {
			$visits = Schema::table( 'visits' );
			$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, visitor_id, user_id, landing_path, stage FROM {$visits} WHERE start_ms >= %d AND start_ms < %d ORDER BY id", $from * 1000, $to * 1000 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$path = (string) $row['landing_path'];
				$key  = strtolower( $path );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = self::blank( $path, (int) $row['id'], true );
				}
				$visitor = (int) $row['visitor_id'];
				$stage   = (int) $row['stage'];
				$user    = (int) $row['user_id'];

				$groups[ $key ]['visitors'][ $visitor ] = true;
				if ( $stage >= 1 ) {
					$groups[ $key ]['viewed'][ $visitor ] = true;
				}
				if ( $stage >= 2 ) {
					$groups[ $key ]['carted'][ $visitor ] = true;
				}
				if ( $user > 0 ) {
					$groups[ $key ]['customers'][ 'u' . $user ] = true;
				}
			}
		}

		// 已付款的非测试订单；meta 值 0 表示“没有”（没带追踪信息的订单）。
		$orders = array();
		foreach ( Operations::paid_orders( $from, $to ) as $order ) {
			if ( Overview::is_test_order( $order ) ) {
				continue;
			}
			// 买家：有账号按账号，游客按账单邮箱（不分大小写）；没有邮箱的游客订单没有键。
			$customer_id = (int) $order->get_customer_id();
			$buyer       = '';
			if ( $customer_id > 0 ) {
				$buyer = 'u' . $customer_id;
			} else {
				$email = strtolower( trim( (string) $order->get_billing_email() ) );
				if ( '' !== $email ) {
					$buyer = 'e' . $email;
				}
			}
			$orders[] = array(
				'sales'   => (float) $order->get_total() - (float) $order->get_total_refunded(),
				'visitor' => (int) $order->get_meta( Server_Events::ORDER_VISITOR_META ),
				'visit'   => (int) $order->get_meta( Server_Events::ORDER_VISIT_META ),
				'buyer'   => $buyer,
			);
		}

		// 订单对应的访问行一次查出来（最多一条查询）：id => 落地页路径。
		$landing = array();
		if ( $orders && $tracking ) {
			$landing = self::lookup( array_column( $orders, 'visit' ) );
		}

		foreach ( $orders as $o ) {
			// 没有访问 meta、访问行不存在、或落地页是空字符串的订单归在 '' 那一行。
			$path     = ( $o['visit'] > 0 && isset( $landing[ $o['visit'] ] ) ) ? $landing[ $o['visit'] ] : '';
			$visit_id = '' === $path ? 0 : $o['visit'];
			$key      = strtolower( $path );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = self::blank( $path, $visit_id, false );
			} elseif ( ! $groups[ $key ]['has_visits'] && $visit_id > 0 && $visit_id < $groups[ $key ]['label_id'] ) {
				// 只通过订单出现的键：名称取这些订单的访问里 id 最小的那个。
				$groups[ $key ]['path']     = $path;
				$groups[ $key ]['label_id'] = $visit_id;
			}

			++$groups[ $key ]['orders'];
			$groups[ $key ]['sales'] += $o['sales'];
			if ( $o['visitor'] > 0 ) {
				$groups[ $key ]['paid_visitors'][ $o['visitor'] ] = true;
			}
			if ( '' !== $o['buyer'] ) {
				$groups[ $key ]['customers'][ $o['buyer'] ] = true;
			}
		}

		$out = array();
		foreach ( $groups as $g ) {
			$uv    = count( $g['visitors'] );
			$count = (int) $g['orders'];
			if ( $uv <= 0 && $count <= 0 ) {
				continue;
			}
			$sales = round( (float) $g['sales'], 2 );
			$out[] = array(
				'path'       => $g['path'],
				'uv'         => $uv,
				'customers'  => count( $g['customers'] ),
				'viewed_uv'  => count( $g['viewed'] ),
				'carted_uv'  => count( $g['carted'] ),
				'paid_uv'    => count( $g['paid_visitors'] ),
				'orders'     => $count,
				'conversion' => $uv > 0 ? round( $count / $uv * 100, 2 ) : null,
				'sales'      => $sales,
				'aov'        => $count > 0 ? round( $sales / $count, 2 ) : 0.0,
				'uv_value'   => $uv > 0 ? round( $sales / $uv, 2 ) : null,
			);
		}
		return $out;
	}

	/** 一行的空的中间数据；label_id 是名称来自哪个访问（0 = 没有），has_visits 表示这一行有时间段内开始的访问（名称就不再被订单改）。 */
	private static function blank( string $path, int $label_id, bool $has_visits ): array {
		return array(
			'path'          => $path,
			'label_id'      => $label_id,
			'has_visits'    => $has_visits,
			'visitors'      => array(),
			'viewed'        => array(),
			'carted'        => array(),
			'customers'     => array(),
			'paid_visitors' => array(),
			'orders'        => 0,
			'sales'         => 0.0,
		);
	}

	/**
	 * 按 id 取访问的落地页路径：id => 路径。IN 列表只由 intval 过的 id 组成；没有 id 时不查询。
	 *
	 * @param int[] $ids 访问 id（0 表示没有，会被去掉）。
	 * @return array<int,string>
	 */
	private static function lookup( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( 'SELECT id, landing_path FROM ' . Schema::table( 'visits' ) . " WHERE id IN ({$in}) ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = (string) $row['landing_path'];
		}
		return $out;
	}
}
