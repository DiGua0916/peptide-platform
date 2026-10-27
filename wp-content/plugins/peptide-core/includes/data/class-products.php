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

	/** 12 个指标，顺序 = 页面“编辑表头”里的顺序（页面和测试都依赖这个顺序）。 */
	public const METRICS = array( 'views', 'view_users', 'carts', 'cart_users', 'cart_units', 'cart_rate', 'paid_orders', 'paid_users', 'paid_units', 'conversion', 'sales', 'net_sales' );

	/** 默认显示的列（Shopname 的默认列里我们有数据的那些）。 */
	public const DEFAULTS = array( 'views', 'carts', 'cart_rate', 'cart_units', 'paid_units', 'conversion', 'sales' );

	/** 两个比率：变化用百分点差。 */
	public const RATES = array( 'cart_rate', 'conversion' );

	/**
	 * 商品详情数据页的全部数字：每个商品（和它的款式）的本期、对比期和变化，排序、搜索、分页之后的一页。
	 *
	 * @param array $range A successful Range::from_params() result.
	 * @param array $args  sort, order, page, per_page, q (already validated by params()).
	 */
	public static function table( array $range, array $args ): array {
		$sort     = in_array( $args['sort'] ?? '', self::METRICS, true ) ? (string) $args['sort'] : 'views';
		$order    = 'asc' === ( $args['order'] ?? 'desc' ) ? 'asc' : 'desc';
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$q        = (string) ( $args['q'] ?? '' );

		$current  = self::period( (int) $range['from'], (int) $range['to'] );
		$previous = self::period( (int) $range['compare_from'], (int) $range['compare_to'] );

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
		$value = static function ( int $id ) use ( $current, $zero, $sort ) {
			$v = ( $current['products'][ $id ] ?? $zero )[ $sort ];
			return null === $v ? -1 : $v;
		};
		usort(
			$ids,
			static function ( int $a, int $b ) use ( $value, $order ): int {
				$cmp = 'asc' === $order ? $value( $a ) <=> $value( $b ) : $value( $b ) <=> $value( $a );
				return 0 !== $cmp ? $cmp : $a <=> $b;
			}
		);

		$total = count( $ids );
		$slice = $page > (int) ceil( $total / $per_page ) ? array() : array_slice( $ids, ( $page - 1 ) * $per_page, $per_page );

		$rows = array();
		foreach ( $slice as $id ) {
			$product = $load( $id );
			$cur     = $current['products'][ $id ] ?? self::metrics( array(), false );
			$prev    = $previous['products'][ $id ] ?? self::metrics( array(), false );
			$rows[]  = array(
				'product'    => self::product_info( $id, $product ),
				'current'    => $cur,
				'previous'   => $prev,
				'change'     => self::change( $cur, $prev ),
				'variations' => self::variation_rows( $id, $current['variations'][ $id ] ?? array(), $previous['variations'][ $id ] ?? array() ),
			);
		}

		return array(
			'range'    => $range,
			'tracking' => Schema::installed(),
			'metrics'  => self::METRICS,
			'defaults' => self::DEFAULTS,
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

		$sort = self::word( $params, 'sort', 'views', self::METRICS );
		if ( null === $sort ) {
			$errors['sort'] = 'invalid_format';
		}
		$order = self::word( $params, 'order', 'desc', array( 'asc', 'desc' ) );
		if ( null === $order ) {
			$errors['order'] = 'invalid_format';
		}
		$page = self::whole( $params, 'page', 1, 1, 999999999 );
		if ( null === $page ) {
			$errors['page'] = 'invalid_format';
		}
		$per_page = self::whole( $params, 'per_page', 20, 1, 100 );
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

	/** 取一个必须在 $allowed 里的文字参数：没传或为空用默认值；不在里面返回 null。 */
	private static function word( array $params, string $key, string $default, array $allowed ): ?string {
		$raw = $params[ $key ] ?? '';
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return null;
		}
		$text = trim( (string) $raw );
		if ( '' === $text ) {
			return $default;
		}
		return in_array( $text, $allowed, true ) ? $text : null;
	}

	/** 取一个整数参数：没传或为空用默认值；不是整数或超出 $min ~ $max 返回 null。 */
	private static function whole( array $params, string $key, int $default, int $min, int $max ): ?int {
		$raw = $params[ $key ] ?? '';
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return null;
		}
		$text = trim( (string) $raw );
		if ( '' === $text ) {
			return $default;
		}
		if ( 1 !== preg_match( '/^[+-]?\d{1,9}$/', $text ) ) {
			return null;
		}
		$n = (int) $text;
		return ( $n < $min || $n > $max ) ? null : $n;
	}

	/**
	 * 一个时间段 [from, to)（Unix 秒）的数字，每个商品和每个款式一份。
	 *
	 * @return array{products:array<int,array>,variations:array<int,array<int,array>>} 商品 ID => 指标；商品 ID => 款式 ID => 指标。
	 */
	private static function period( int $from, int $to ): array {
		global $wpdb;
		$p = array();
		$v = array();

		if ( Schema::installed() ) {
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

		// 订单：一遍过时间段内的已付款（非测试）订单，商品和款式各累加一份。
		foreach ( Operations::paid_orders( $from, $to ) as $order ) {
			if ( Overview::is_test_order( $order ) ) {
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
		return array(
			'products'   => $p,
			'variations' => $v,
		);
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
		$carts      = (int) ( $raw['carts'] ?? 0 );
		$paid_units = (int) ( $raw['paid_units'] ?? 0 );
		return array(
			'views'       => $views,
			'view_users'  => $variation ? null : (int) ( $raw['view_users'] ?? 0 ),
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
		);
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
				),
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
			);
		}
		$image = $product->get_image_id() ? wp_get_attachment_image_url( (int) $product->get_image_id(), 'thumbnail' ) : '';
		return array(
			'id'       => $id,
			'name'     => $product->get_name(),
			'sku'      => (string) $product->get_sku(),
			'image'    => $image ? (string) $image : '',
			'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
			'view_url' => (string) get_permalink( $id ),
		);
	}
}
