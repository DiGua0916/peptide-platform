<?php
/**
 * 商品详情数据页（DATA-01 第 3 步）：每个商品一行（可展开到款式），查看、加购、付款的数字，本期和对比期一起算；口径见 Shopname 帮助中心“商品详情数据”。
 *
 * 时间段是 [from, to)（Unix 秒，店铺时区由 Data\Range 换算好）；事件按 occurred_ms（毫秒）落在时间段内计算。
 * “已付款订单”= Operations::paid_orders()（已付款状态、时间段内创建）再去掉测试订单（Overview::is_test_order()）；测试订单一律不计。
 *
 * 12 个指标（顺序 = 页面“编辑表头”里的顺序，见 METRICS）。款式行只统计该款式自己，下面标“仅商品行”的指标款式行是 null：
 * - views 查看次数：product_view 事件数（仅商品行，商品页浏览不按款式记录）；
 * - view_users 查看用户数：这些事件里不同的访客数（仅商品行）；
 * - carts 加购次数：add_to_cart 事件数；
 * - cart_users 加购用户数：add_to_cart 事件里不同的访客数；
 * - cart_units 加购件数：add_to_cart 事件 data.quantity 之和（整数）；
 * - cart_rate 加购转化率：加购次数 / 查看次数 × 100，保留 2 位；没有查看次数（0 或 null）时为 null；
 * - paid_orders 付款订单数：包含这个商品（款式）的不同已付款订单数；
 * - paid_users 付款用户数：这些订单的不同买家（有账号按账号，游客按账单邮箱，不分大小写）；
 * - paid_units 付款件数：订单行 数量 - 已退数量 之和；
 * - conversion 转化率：付款件数 / 查看次数 × 100，保留 2 位；没有查看次数时为 null；
 * - sales 付款金额：订单行 小计（单价 × 数量，不含优惠券）- 已退金额 之和，保留 2 位；
 * - net_sales 净销售额：订单行 实付（扣掉优惠券之后）- 已退金额 之和，保留 2 位。
 *   满减之类的促销是整个购物车的负费用行，不拆到单个商品上，所以这里不体现。
 *
 * 对比：本期和对比期各算一遍；变化用 Range::change()（百分比），两个比率（cart_rate、conversion）的变化是百分点差；
 * 任何一边是 null，变化就是 null。
 *
 * 一个商品在本期或对比期任何一个指标不为 0 就出现；款式行同理（看款式自己的指标）。
 * 排序按本期的值（null 当作 -1），相同的按商品 ID 从小到大；然后分页；可选 q 按商品名（不分大小写）过滤，在分页之前。
 * 访客追踪的表没装时，事件指标都是 0 或 null，tracking 为 false；订单指标照常。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

use PeptideCore\Api\Errors;
use PeptideCore\Overview;
use PeptideCore\Tracking\Schema;

defined( 'ABSPATH' ) || exit;

final class Products {

	/** 原26项保序，末尾追加两个现有用户计数的比率；顺序也是CSV/列配置契约。 */
	public const METRICS = array( 'views', 'view_users', 'carts', 'cart_users', 'cart_units', 'cart_rate', 'paid_orders', 'paid_users', 'paid_units', 'conversion', 'sales', 'net_sales', 'exposed_users', 'impressions', 'exposure_viewed_users', 'exposure_view_user_rate', 'exposure_views', 'exposure_view_rate', 'exposure_cart_users', 'exposure_cart_user_rate', 'exposure_carts', 'exposure_cart_rate', 'exposure_paid_users', 'exposure_paid_user_rate', 'exposure_paid_units', 'exposure_paid_rate', 'cart_user_rate', 'paid_user_rate', 'favorite_count', 'share_count', 'share_users', 'intent_count', 'intent_users' );

	/** 默认显示的列（Shopname 的默认列里我们有数据的那些）。 */
	public const DEFAULTS = array( 'views', 'carts', 'cart_rate', 'cart_units', 'paid_units', 'conversion', 'sales', 'exposed_users', 'impressions', 'exposure_view_user_rate' );

	/** 所有比率的变化用百分点差。 */
	public const RATES = array( 'cart_rate', 'conversion', 'exposure_view_user_rate', 'exposure_view_rate', 'exposure_cart_user_rate', 'exposure_cart_rate', 'exposure_paid_user_rate', 'exposure_paid_rate', 'cart_user_rate', 'paid_user_rate' );

	/** Ordered selectable columns; creation time is metadata, never a numeric metric. */
	public static function columns(): array { return array_merge( self::METRICS, array( 'created_at' ) ); }

	/**
	 * 商品详情数据页的全部数字：每个商品（和它的款式）的本期、对比期和变化，排序、搜索、分页之后的一页。
	 *
	 * @param array $range A successful Range::from_params() result.
	 * @param array $args  sort, order, page, per_page, q (already validated by params()).
	 */
	public static function table( array $range, array $args ): array {
		$selection = self::selection( $range, $args );
		$total = count( $selection['ids'] );
		$page = $selection['page']; $per_page = $selection['per_page'];
		$slice = $page > (int) ceil( $total / $per_page ) ? array() : array_slice( $selection['ids'], ( $page - 1 ) * $per_page, $per_page );
		$rows = array();
		foreach ( $slice as $id ) { $rows[] = self::assemble_row( $id, $selection ); }
		$out = array(
			'range'    => $range,
			'tracking' => Schema::installed(),
			'metrics'  => self::METRICS,
			'columns'  => self::columns(),
			'report_timezone' => wp_timezone()->getName(),
			'defaults' => self::DEFAULTS,
			'sort'     => $selection['sort'],
			'order'    => $selection['order'],
			'page'     => $selection['page'],
			'per_page' => $selection['per_page'],
			'q'        => $selection['q'],
			'total'    => $total,
			'rows'     => $rows,
		);
		if ( isset( $args['filter'] ) ) { $out['filter'] = $args['filter']; $out['filter_coverage'] = array( 'current' => $selection['current']['filter_coverage'] ?? array(), 'previous' => $selection['previous']['filter_coverage'] ?? array() ); }
		$out['action_measurement'] = array( 'current' => $selection['current']['action_coverage']['coverage'], 'previous' => $selection['previous']['action_coverage']['coverage'] );
		$out['exposure_measurement'] = Page_Conversion::measurement() + array( 'scope' => 'parent_product', 'availability' => Schema::installed() ? 'received_observations' : 'unavailable' );
		return $out;
	}


	/**
	 * 款式详情数据（对照参考后台的“款式详情数据”页）：每个款式一行（款式所属商品 + 款式名称 / 货号），
	 * 指标和商品详情里展开的款式行完全相同（同一份 period() 结果）；按本期的 sort 指标排序（null 排最后），
	 * q 同时搜商品名和款式名，分页。没有款式的简单商品不出现。
	 *
	 * @param array $range A successful Range::from_params() result.
	 * @param array $args  Products::params() 的结果（sort, order, page, per_page, q, 可选 filter）。
	 */
	public static function variant_table( array $range, array $args ): array {
		$sort     = in_array( $args['sort'] ?? '', self::METRICS, true ) ? (string) $args['sort'] : 'views';
		$order    = 'asc' === ( $args['order'] ?? 'desc' ) ? 'asc' : 'desc';
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$q        = (string) ( $args['q'] ?? '' );
		$current  = self::period( (int) $range['from'], (int) $range['to'], Report_Filter::active( $args, (int) $range['to'] * 1000 ) );
		$previous = self::period( (int) $range['compare_from'], (int) $range['compare_to'], Report_Filter::active( $args, (int) $range['compare_to'] * 1000 ) );

		$parents = array();
		foreach ( array( $current['variations'], $previous['variations'] ) as $by_parent ) {
			foreach ( $by_parent as $pid => $vars ) {
				$parents[ (int) $pid ] = true;
			}
		}
		$items   = array();
		$objects = array();
		foreach ( array_keys( $parents ) as $pid ) {
			foreach ( self::variation_rows( $pid, $current['variations'][ $pid ] ?? array(), $previous['variations'][ $pid ] ?? array() ) as $row ) {
				if ( ! array_key_exists( $pid, $objects ) ) {
					$objects[ $pid ] = wc_get_product( $pid );
				}
				$product = $objects[ $pid ];
				$name    = $product instanceof \WC_Product ? $product->get_name() : sprintf( '#%d', $pid );
				if ( '' !== $q && false === mb_stripos( $name, $q ) && false === mb_stripos( (string) $row['variation']['name'], $q ) && false === mb_stripos( (string) $row['variation']['sku'], $q ) ) {
					continue;
				}
				$items[] = array( 'product' => self::product_info( $pid, $product ) ) + $row;
			}
		}
		usort(
			$items,
			static function ( array $a, array $b ) use ( $sort, $order ): int {
				$x = $a['current'][ $sort ] ?? null;
				$y = $b['current'][ $sort ] ?? null;
				if ( null === $x || null === $y ) {
					$cmp = (int) ( null === $x ) <=> (int) ( null === $y );
				} else {
					$cmp = 'asc' === $order ? $x <=> $y : $y <=> $x;
				}
				return 0 !== $cmp ? $cmp : array( $a['product']['id'], $a['variation']['id'] ) <=> array( $b['product']['id'], $b['variation']['id'] );
			}
		);
		$total = count( $items );
		$rows  = $page > (int) ceil( max( 1, $total ) / $per_page ) ? array() : array_slice( $items, ( $page - 1 ) * $per_page, $per_page );
		$out   = array(
			'range'    => $range,
			'tracking' => Schema::installed(),
			'mode'     => 'variations',
			'metrics'  => self::METRICS,
			'columns'  => self::columns(),
			'report_timezone' => wp_timezone()->getName(),
			'defaults' => self::DEFAULTS,
			'sort'     => $sort,
			'order'    => $order,
			'page'     => $page,
			'per_page' => $per_page,
			'q'        => $q,
			'total'    => $total,
			'rows'     => array_values( $rows ),
		);
		if ( isset( $args['filter'] ) ) { $out['filter'] = $args['filter']; }
		return $out;
	}

	/** Every matching parent, in table order, with the same variation rows. Pagination alone is omitted. */
	public static function all_rows( array $range, array $args ): iterable {
		$selection = self::selection( $range, $args );
		foreach ( $selection['ids'] as $id ) { yield self::assemble_row( $id, $selection ); }
	}

	/** One shared selection/calculation per report request; product cache remains request-local. */
	private static function selection( array $range, array $args ): array {
		$sort     = in_array( $args['sort'] ?? '', self::METRICS, true ) ? (string) $args['sort'] : 'views';
		$order    = 'asc' === ( $args['order'] ?? 'desc' ) ? 'asc' : 'desc';
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$q        = (string) ( $args['q'] ?? '' );

		$current  = self::period( (int) $range['from'], (int) $range['to'], Report_Filter::active( $args, (int) $range['to'] * 1000 ) );
		$previous = self::period( (int) $range['compare_from'], (int) $range['compare_to'], Report_Filter::active( $args, (int) $range['compare_to'] * 1000 ) );

		// 商品 = 本期和对比期里有数据的商品的并集。
		$ids = array();
		foreach ( array_merge( array_keys( $current['products'] ), array_keys( $previous['products'] ) ) as $id ) {
			$ids[ (int) $id ] = true;
		}
		$ids = array_keys( $ids );

		// 商品对象只取一次（搜索和输出都用）；已删除的商品是 false。
		$objects = array();
		$load    = static function ( int $id ) use ( &$objects ) {
			if ( ! array_key_exists( $id, $objects ) ) {
				$objects[ $id ] = wc_get_product( $id );
			}
			return $objects[ $id ];
		};

		if ( '' !== $q ) {
			$ids = array_values(
				array_filter(
					$ids,
					static function ( int $id ) use ( $load, $q ): bool {
						$product = $load( $id );
						$name    = $product instanceof \WC_Product ? $product->get_name() : sprintf( '#%d', $id );
						return false !== mb_stripos( $name, $q );
					}
				)
			);
		}

		// 本期的值排序（null 当作 -1；只在对比期有数据的商品，本期是全 0），相同的按商品 ID 从小到大。
		$zero  = self::metrics( array(), false );
		$exposure_sort = array_key_exists( $sort, Product_Exposure::MAP ) || in_array( $sort, array_merge( array( 'cart_user_rate', 'paid_user_rate' ), Product_Actions::METRICS ), true );
		$value = static function ( int $id ) use ( $current, $zero, $sort, $exposure_sort ) {
			$v = ( $current['products'][ $id ] ?? $zero )[ $sort ];
			return ! $exposure_sort && null === $v ? -1 : $v;
		};
		usort(
			$ids,
			static function ( int $a, int $b ) use ( $value, $order, $exposure_sort ): int {
				$x = $value( $a ); $y = $value( $b );
				$cmp = $exposure_sort && ( null === $x || null === $y ) ? (int) ( null === $x ) <=> (int) ( null === $y ) : ( 'asc' === $order ? $x <=> $y : $y <=> $x );
				return 0 !== $cmp ? $cmp : $a <=> $b;
			}
		);

		return compact( 'sort', 'order', 'page', 'per_page', 'q', 'current', 'previous', 'ids', 'load' );
	}

	private static function assemble_row( int $id, array $selection ): array {
		$product = $selection['load']( $id );
		$cur     = $selection['current']['products'][ $id ] ?? self::metrics( array(), false );
		$prev    = $selection['previous']['products'][ $id ] ?? self::metrics( array(), false );
		return array(
			'product'    => self::product_info( $id, $product ),
			'current'    => $cur,
			'previous'   => $prev,
			'change'     => self::change( $cur, $prev ),
			'action_coverage' => array( 'current' => self::action_coverage( $selection['current'], $id ), 'previous' => self::action_coverage( $selection['previous'], $id ) ),
			'variations' => self::variation_rows( $id, $selection['current']['variations'][ $id ] ?? array(), $selection['previous']['variations'][ $id ] ?? array() ),
		);
	}

	private static function action_coverage( array $period, int $id ): array {
		return $period['action_coverage']['product_coverage'][$id] ?? array_fill_keys( array( 'favorite', 'share', 'intent' ), Product_Actions::coverage( 0, Schema::installed() ) );
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

		$out = array(
			'sort'     => $sort,
			'order'    => $order,
			'page'     => $page,
			'per_page' => $per_page,
			'q'        => $q,
		);
		$filter = Report_Filter::params( $params ); if ( is_wp_error( $filter ) ) { return $filter; } if ( null !== $filter ) { $out['filter'] = $filter; }
		return $out;
	}

	/**
	 * 一个时间段 [from, to)（Unix 秒）的数字，每个商品和每个款式一份。
	 *
	 * @return array{products:array<int,array>,variations:array<int,array<int,array>>} 商品 ID => 指标；商品 ID => 款式 ID => 指标。
	 */
	private static function period( int $from, int $to, ?Report_Filter $filter = null ): array {
		global $wpdb;
		$p = array();
		$v = array();

		if ( Schema::installed() && ! $filter ) {
			$events = Schema::table( 'events' );
			$fm     = $from * 1000;
			$tm     = $to * 1000;

			// 商品级：查看和加购的次数、不同访客数。
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, type, COUNT(*) AS n, COUNT(DISTINCT visitor_id) AS users FROM {$events} WHERE type IN ( 'product_view', 'add_to_cart' ) AND occurred_ms >= %d AND occurred_ms < %d AND product_id > 0 GROUP BY product_id, type", $fm, $tm ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$pid                    = (int) $row['product_id'];
				$viewed                 = 'product_view' === $row['type'];
				$p[ $pid ]              = $p[ $pid ] ?? array();
				$p[ $pid ][ $viewed ? 'views' : 'carts' ]           = (int) $row['n'];
				$p[ $pid ][ $viewed ? 'view_users' : 'cart_users' ] = (int) $row['users'];
			}

			// 加购按商品 + 款式：次数、不同访客数、件数（data.quantity 之和）。
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT product_id, variation_id, COUNT(*) AS n, COUNT(DISTINCT visitor_id) AS users, COALESCE( SUM( CAST( JSON_UNQUOTE( JSON_EXTRACT( data, \'$.quantity\' ) ) AS DECIMAL( 12, 2 ) ) ), 0 ) AS units FROM ' . $events . " WHERE type = 'add_to_cart' AND occurred_ms >= %d AND occurred_ms < %d AND product_id > 0 GROUP BY product_id, variation_id", $fm, $tm ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$pid                     = (int) $row['product_id'];
				$vid                     = (int) $row['variation_id'];
				$units                   = (int) round( (float) $row['units'] );
				$p[ $pid ]               = $p[ $pid ] ?? array();
				$p[ $pid ]['cart_units'] = (int) ( $p[ $pid ]['cart_units'] ?? 0 ) + $units;
				if ( $vid > 0 ) {
					$v[ $pid ][ $vid ] = array(
						'carts'      => (int) $row['n'],
						'cart_users' => (int) $row['users'],
						'cart_units' => $units,
					);
				}
			}
		}

		if ( $filter ) {
			$counts = array(); $carts = array();
			foreach ( $filter->events( $from, $to, array( 'product_view', 'add_to_cart' ) ) as $event ) {
				$pid = (int) $event['product_id']; if ( $pid <= 0 ) { continue; }
				$type = $event['type']; $visitor = (int) $event['visitor_id'];
				$counts[ $pid ][ $type ]['n'] = ( $counts[ $pid ][ $type ]['n'] ?? 0 ) + 1;
				$counts[ $pid ][ $type ]['visitors'][ $visitor ] = true;
				if ( 'add_to_cart' === $type ) {
					$vid = (int) $event['variation_id']; $data = json_decode( (string) $event['data'], true ); $quantity = $data['quantity'] ?? 0;
					$carts[ $pid ][ $vid ]['n'] = ( $carts[ $pid ][ $vid ]['n'] ?? 0 ) + 1;
					$carts[ $pid ][ $vid ]['visitors'][ $visitor ] = true;
					$carts[ $pid ][ $vid ]['units'] = ( $carts[ $pid ][ $vid ]['units'] ?? 0 ) + ( is_numeric( $quantity ) ? round( (float) $quantity, 2 ) : 0 );
				}
			}
			foreach ( $counts as $pid => $types ) { foreach ( $types as $type => $raw ) { $viewed = 'product_view' === $type; $p[ $pid ][ $viewed ? 'views' : 'carts' ] = $raw['n']; $p[ $pid ][ $viewed ? 'view_users' : 'cart_users' ] = count( $raw['visitors'] ); } }
			foreach ( $carts as $pid => $variants ) { foreach ( $variants as $vid => $raw ) { $units = (int) round( $raw['units'] ); $p[ $pid ]['cart_units'] = ( $p[ $pid ]['cart_units'] ?? 0 ) + $units; if ( $vid > 0 ) { $v[ $pid ][ $vid ] = array( 'carts' => $raw['n'], 'cart_users' => count( $raw['visitors'] ), 'cart_units' => $units ); } } }
		}

		// 订单：一遍过时间段内的已付款（非测试）订单，商品和款式各累加一份。
		$native_orders = Operations::paid_orders( $from, $to );
		if ( $filter ) { $native_orders = $filter->orders( $native_orders ); }
		foreach ( $native_orders as $order ) {
			if ( self::is_test( $order ) ) {
				continue;
			}
			$order_id = (int) $order->get_id();
			// 买家：有账号按账号，游客按账单邮箱（不分大小写）。
			$customer_id = (int) $order->get_customer_id();
			$buyer       = $customer_id > 0 ? 'u' . $customer_id : 'e' . strtolower( trim( (string) $order->get_billing_email() ) );
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$pid = (int) $item->get_product_id();
				if ( $pid <= 0 ) {
					continue;
				}
				$vid      = (int) $item->get_variation_id();
				$refunded = (float) $order->get_total_refunded_for_item( $item->get_id() );
				$line     = array(
					'units' => (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) ),
					'sales' => (float) $item->get_subtotal() - $refunded,
					'net'   => (float) $item->get_total() - $refunded,
				);
				$p[ $pid ] = self::add_line( $p[ $pid ] ?? array(), $order_id, $buyer, $line );
				if ( $vid > 0 ) {
					$v[ $pid ][ $vid ] = self::add_line( $v[ $pid ][ $vid ] ?? array(), $order_id, $buyer, $line );
				}
			}
		}

		// 原始累加值 => 12 个指标。
		foreach ( $p as $pid => $raw ) {
			$p[ $pid ] = self::metrics( $raw, false );
		}
		foreach ( $v as $pid => $list ) {
			foreach ( $list as $vid => $raw ) {
				$v[ $pid ][ $vid ] = self::metrics( $raw, true );
			}
		}
		if ( Schema::installed() ) {
			foreach ( Page_Conversion::product_period( $from * 1000, $to * 1000, $filter ) as $pid => $metrics ) { $p[$pid] = array_replace( $p[$pid] ?? self::metrics( array(), false ), Product_Exposure::metrics( $metrics ) ); }
		}
		$actions = Product_Actions::period( $from, $to, $filter );
		foreach ( $actions['products'] as $pid => $metrics ) { $p[$pid] = array_replace( $p[$pid] ?? self::metrics( array(), false ), $metrics ); }
		return array(
			'action_coverage' => $actions,
			'products'   => $p,
			'variations' => $v,
		) + ( $filter ? array( 'filter_coverage' => $filter->coverage() ) : array() );
	}

	/**
	 * 把一个订单行累加到商品（或款式）的原始数字里。
	 *
	 * @param array $raw   已有的原始数字。
	 * @param array $line  units 付款件数、sales 付款金额、net 净销售额（都已扣掉退款）。
	 */
	private static function add_line( array $raw, int $order_id, string $buyer, array $line ): array {
		$raw['orders'][ $order_id ] = true;
		if ( 'e' !== $buyer ) { // 游客没有邮箱时不算付款用户。
			$raw['buyers'][ $buyer ] = true;
		}
		$raw['paid_units'] = (int) ( $raw['paid_units'] ?? 0 ) + (int) $line['units'];
		$raw['sales']      = (float) ( $raw['sales'] ?? 0 ) + (float) $line['sales'];
		$raw['net_sales']  = (float) ( $raw['net_sales'] ?? 0 ) + (float) $line['net'];
		return $raw;
	}

	/**
	 * 原始数字 => 12 个指标（按 METRICS 的顺序）。款式行没有查看数据：views / view_users 是 null，两个比率也是 null。
	 *
	 * @return array<string,int|float|null>
	 */
	private static function metrics( array $raw, bool $variation ): array {
		$views      = $variation ? null : (int) ( $raw['views'] ?? 0 );
		$view_users = $variation ? null : (int) ( $raw['view_users'] ?? 0 );
		$carts      = (int) ( $raw['carts'] ?? 0 );
		$paid_units = (int) ( $raw['paid_units'] ?? 0 );
		return array(
			'views'       => $views,
			'view_users'  => $view_users,
			'carts'       => $carts,
			'cart_users'  => (int) ( $raw['cart_users'] ?? 0 ),
			'cart_units'  => (int) ( $raw['cart_units'] ?? 0 ),
			'cart_rate'   => $views ? round( $carts / $views * 100, 2 ) : null,
			'paid_orders' => count( $raw['orders'] ?? array() ),
			'paid_users'  => count( $raw['buyers'] ?? array() ),
			'paid_units'  => $paid_units,
			'conversion'  => $views ? round( $paid_units / $views * 100, 2 ) : null,
			'sales'       => round( (float) ( $raw['sales'] ?? 0 ), 2 ),
			'net_sales'   => round( (float) ( $raw['net_sales'] ?? 0 ), 2 ),
		) + Product_Exposure::metrics( $variation || ! Schema::installed() ? null : Page_Conversion::metrics( array() ) ) + array(
			// Existing cart visitors and native buyers have distinct identity/time-window definitions.
			'cart_user_rate' => $view_users ? round( (int) ( $raw['cart_users'] ?? 0 ) / $view_users * 100, 2 ) : null,
			'paid_user_rate' => $view_users ? round( count( $raw['buyers'] ?? array() ) / $view_users * 100, 2 ) : null,
		) + Product_Actions::empty_metrics();
	}

	/**
	 * 每个指标的变化：百分比（Range::change()），两个比率是百分点差；任何一边是 null 就是 null。
	 *
	 * @param array<string,int|float|null> $current  本期。
	 * @param array<string,int|float|null> $previous 对比期。
	 * @return array<string,float|null>
	 */
	private static function change( array $current, array $previous ): array {
		$out = array();
		foreach ( self::METRICS as $key ) {
			$c = $current[ $key ] ?? null;
			$p = $previous[ $key ] ?? null;
			if ( null === $c || null === $p ) {
				$out[ $key ] = null;
			} elseif ( in_array( $key, self::RATES, true ) ) {
				$out[ $key ] = round( (float) $c - (float) $p, 2 );
			} else {
				$out[ $key ] = Range::change( (float) $c, (float) $p );
			}
		}
		return $out;
	}

	/** 任何一个指标不为 0（null 不算）。 */
	private static function has_data( array $metrics ): bool {
		foreach ( $metrics as $v ) {
			if ( null !== $v && 0.0 !== (float) $v ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 商品下的款式行：本期或对比期任何一个指标不为 0 的款式，按款式 ID 从小到大。
	 *
	 * @param array<int,array> $current  款式 ID => 本期指标。
	 * @param array<int,array> $previous 款式 ID => 对比期指标。
	 */
	private static function variation_rows( int $product_id, array $current, array $previous ): array {
		$ids = array_unique( array_merge( array_keys( $current ), array_keys( $previous ) ) );
		sort( $ids, SORT_NUMERIC );
		$rows = array();
		foreach ( $ids as $vid ) {
			$cur  = $current[ $vid ] ?? self::metrics( array(), true );
			$prev = $previous[ $vid ] ?? self::metrics( array(), true );
			if ( ! self::has_data( $cur ) && ! self::has_data( $prev ) ) {
				continue;
			}
			$variation = wc_get_product( (int) $vid );
			$name      = '';
			$sku       = '';
			if ( $variation instanceof \WC_Product_Variation ) {
				$name = wc_get_formatted_variation( $variation, true, false );
				$sku  = (string) $variation->get_sku();
			}
			$rows[] = array(
				'variation' => array(
					'id'   => (int) $vid,
					'name' => '' !== $name ? $name : sprintf( '#%d', $vid ),
					'sku'  => $sku,
				) + self::created_info( $variation ),
				'current'   => $cur,
				'previous'  => $prev,
				'change'    => self::change( $cur, $prev ),
			);
		}
		return $rows;
	}

	/**
	 * 商品信息；已删除的商品名字显示成 #ID，其他为空。
	 *
	 * @param \WC_Product|false|null $product wc_get_product() 的结果。
	 */
	private static function product_info( int $id, $product ): array {
		if ( ! $product instanceof \WC_Product ) {
			return array(
				'id'       => $id,
				'name'     => sprintf( '#%d', $id ),
				'sku'      => '',
				'image'    => '',
				'edit_url' => '',
				'view_url' => '',
			) + self::created_info( null );
		}
		$image = $product->get_image_id() ? wp_get_attachment_image_url( (int) $product->get_image_id(), 'thumbnail' ) : '';
		return array(
			'id'       => $id,
			'name'     => $product->get_name(),
			'sku'      => (string) $product->get_sku(),
			'image'    => $image ? (string) $image : '',
			'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
			'view_url' => (string) get_permalink( $id ),
		) + self::created_info( $product );
	}

	/** Native cached product metadata; display in store timezone, serialize/export in UTC. */
	private static function created_info( $product ): array {
		$date = $product instanceof \WC_Product ? $product->get_date_created() : null;
		if ( ! $date instanceof \DateTimeInterface ) { return array( 'created_at' => null, 'created_at_label' => null ); }
		$epoch = $date->getTimestamp();
		return array( 'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', $epoch ), 'created_at_label' => wp_date( 'Y-m-d H:i:s', $epoch, wp_timezone() ) );
	}

	/** M6（NF-02）：测试单规则按开关走 Metrics_Overlay::is_test_order()（关着时就是 Overview 的旧规则）；不带自动加载的纯逻辑测试里没有这个类，直接用旧规则。 */
	private static function is_test( $order ): bool {
		return class_exists( Metrics_Overlay::class ) ? Metrics_Overlay::is_test_order( $order ) : \PeptideCore\Overview::is_test_order( $order );
	}
}
