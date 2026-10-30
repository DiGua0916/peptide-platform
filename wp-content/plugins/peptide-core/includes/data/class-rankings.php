<?php
/**
 * 运营数据页排行表（DATA-01 第 7b 步）：十四张排行表，每张有名称、本期值、对比期值和变化；口径见 Shopname 帮助中心“运营数据”。
 *
 * 时间段是 [from, to)（Unix 秒，店铺时区由 Data\Range 换算好）；本期和对比期用同一套规则各算一遍。
 * 每个时间段只查一次订单（类型 shop_order、状态 any、创建时间在时间段内；HPOS 的 any 已经不含 checkout-draft 和回收站），去掉测试订单（Overview::is_test_order()）后：
 * - 已付款订单 = 状态在 wc_get_is_paid_statuses() 里；支付尝试 = 全部订单；支付成功 = 已付款订单 + 状态为 refunded 的订单（付过款）。
 *
 * 十四张表（顺序 = TABLES = Shopname 的页面顺序）。栏目标题和帮助中心的文字不一致时，以栏目标题为准
 * （热卖商品帮助中心写“按已付款订单数”，栏目是销量，所以按件数；订单来源国家帮助中心写“按金额”，栏目是订单数，所以按订单数）：
 * - products_units 热卖商品：父商品 ID（> 0）的已付款件数，件数 = 订单行数量 - 已退数量；
 * - products_sales 热门商品：父商品 ID 的 订单行小计 - 已退金额 之和，保留 2 位（和商品详情数据页的付款金额同一个口径）；
 * - products_viewed 浏览商品：product_view 事件数（product_id > 0）；
 * - products_carted 加购商品：add_to_cart 事件数（product_id > 0）；
 * - categories_units 商品类型：父商品的每个 product_cat 分类各记一份已付款件数；商品已被删除时没有分类行；
 * - categories_sales 商品类型金额：同上，记小计 - 已退金额；
 * - order_countries 订单来源国家：账单国家代码（空 = unknown）的已付款订单数；
 * - customer_countries 顾客来源国家：账单国家代码的不同买家数（有账号 'u'+账号 ID，游客 'e'+小写去空格的账单邮箱，游客没有邮箱的不算）；
 * - visitor_browsers 访客浏览器/APP：时间段内访问所属访客的 browser（访客行缺失或空 = unknown）的不同访客数；
 * - order_browsers 订单浏览器/APP：已付款订单 _pc_track_visitor 对应访客的 browser（没有 meta、行缺失或空 = unknown）的订单数；
 * - payment_methods 下单支付方式：支付方式 ID（空 = unknown）的已付款订单数；
 * - payment_success 支付成功率：每个支付方式 支付成功 / 支付尝试 × 100，保留 2 位，这个时间段没有尝试时是 null；
 * - coupons 优惠券使用：订单用到的优惠券代码（已是小写；一个订单用两张就各记一次）的已付款订单数；
 * - devices 设备：时间段内访问的 device（空 = unknown）的不同访客数。
 * 访客追踪的表没装时，事件和访问的表是空的，订单浏览器全是 unknown。
 *
 * 每张表：本期或对比期的值大于 0 的行（payment_success 是任何一边有支付尝试的支付方式）。排序：本期值从大到小（null 当作 -1），
 * 再对比期值从大到小，再 key 从小到大（商品和分类 ID 按数字，其余 strcmp）；最多 MAX_ROWS 行，total_rows 是截断前的行数。
 * 变化用 Range::change()；payment_success 的变化是百分点差 round( 本期 - 对比期, 2 )；任何一边是 null 时变化是 null。
 *
 * 名称：商品是商品名（商品已删除时 #ID，edit_url 为空）；分类是分类名；国家是 WooCommerce 的国家名（不认识的代码用代码本身）；
 * 支付方式是两个时间段里最新的一个订单（先按创建时间，再按订单 ID）的支付方式标题，标题为空时用方式 ID；
 * 优惠券是代码；设备和浏览器是原始值（unknown 由页面脚本翻译）。只有商品行有 edit_url。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

use PeptideCore\Overview;
use PeptideCore\Api\Errors;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;

defined( 'ABSPATH' ) || exit;

final class Rankings {

	/** 十四张表，顺序 = Shopname 的页面顺序（页面和测试都依赖这个顺序）。 */
	public const TABLES = array( 'products_units', 'products_sales', 'products_viewed', 'products_carted', 'categories_units', 'categories_sales', 'order_countries', 'customer_countries', 'visitor_browsers', 'order_browsers', 'payment_methods', 'payment_success', 'coupons', 'devices' );

	/** 每张表最多返回的行数。 */
	public const MAX_ROWS = 50;

	/** key 是商品 ID 或分类 ID 的表（排序按数字）。 */
	private const NUMERIC_KEYS = array( 'products_units', 'products_sales', 'products_viewed', 'products_carted', 'categories_units', 'categories_sales' );

	/** key 是商品 ID 的表（名称和编辑链接取自商品）。 */
	private const PRODUCT_TABLES = array( 'products_units', 'products_sales', 'products_viewed', 'products_carted' );

	/**
	 * 十四张排行表：每张表的行（名称、本期、对比期、变化）和截断前的行数。
	 *
	 * @param array $range A successful Range::from_params() result.
	 * @return array{range:array,tracking:bool,tables:array<string,array{rows:array,total_rows:int}>}
	 */
	public static function summary( array $range ): array {
		// 两个时间段共用的缓存：商品的分类、分类名、支付方式的最新标题、已取过的商品。
		$cache = array(
			'product_terms' => array(),
			'term_names'    => array(),
			'methods'       => array(),
			'products'      => array(),
		);

		$current  = self::period( (int) $range['from'], (int) $range['to'], $cache );
		$previous = self::period( (int) $range['compare_from'], (int) $range['compare_to'], $cache );

		$tables = array();
		foreach ( self::TABLES as $name ) {
			$tables[ $name ] = self::table( $name, $current[ $name ], $previous[ $name ], $cache );
		}

		return array(
			'range'    => $range,
			'tracking' => Schema::installed(),
			'tables'   => $tables,
		);
	}


	/** Strict additive full-table query contract; old summary parameters are untouched. */
	public static function query_params( array $params ) {
		$errors = array();
		$table = $params['table'] ?? null;
		if ( ! is_string( $table ) || ! in_array( $table, self::TABLES, true ) ) { $errors['table'] = 'invalid_format'; }
		$q = $params['q'] ?? '';
		if ( ( array_key_exists( 'q', $params ) && ! is_string( $params['q'] ) ) || mb_strlen( trim( is_string( $q ) ? $q : '' ) ) > 100 ) { $errors['q'] = 'invalid_format'; }
		$page = Params::whole( $params, 'page', 1, 1, 999999999 );
		$per_page = Params::whole( $params, 'per_page', 20, 1, 100 );
		foreach ( array( 'page' => $page, 'per_page' => $per_page ) as $key => $value ) { if ( null === $value || ( array_key_exists( $key, $params ) && null === $params[$key] ) ) { $errors[$key] = 'invalid_format'; } }
		$format = $params['format'] ?? 'json';
		if ( array_key_exists( 'format', $params ) && ( ! is_string( $params['format'] ) || ! in_array( $format, array( 'json', 'csv' ), true ) ) ) { $errors['format'] = 'invalid_format'; }
		if ( $errors ) { return Errors::invalid( $errors ); }
		return array( 'table' => $table, 'q' => trim( $q ), 'page' => $page, 'per_page' => $per_page, 'format' => $format );
	}

	public static function units( string $table ): array {
		$money = in_array( $table, array( 'products_sales', 'categories_sales' ), true );
		return array( 'value_unit' => $money ? 'currency' : ( 'payment_success' === $table ? 'percent' : 'count' ), 'change_unit' => 'payment_success' === $table ? 'percentage_points' : 'percent', 'currency' => $money ? get_woocommerce_currency() : '' );
	}

	public static function listing( array $range, array $args ): array {
		$selected = self::selection( $range, $args );
		$total = count( $selected['rows'] ); $page = $args['page']; $per_page = $args['per_page'];
		$slice = $page > (int) ceil( $total / $per_page ) ? array() : array_slice( $selected['rows'], ( $page - 1 ) * $per_page, $per_page );
		if ( '' === $args['q'] ) { self::prime_products( $args['table'], $slice, $selected['cache'] ); }
		$units = self::units( $args['table'] );
		return array( 'range' => $range, 'tracking' => Schema::installed(), 'table' => $args['table'], 'q' => $args['q'], 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'value_unit' => $units['value_unit'], 'change_unit' => $units['change_unit'], 'currency' => $units['currency'], 'rows' => self::name_rows( $args['table'], $slice, $selected['cache'] ) );
	}

	/** A single complete selection, never repeated paginated summary calls. */
	public static function all_rows( array $range, array $args ): iterable {
		$selected = self::selection( $range, $args, true );
		foreach ( $selected['rows'] as $row ) { yield self::name_rows( $args['table'], array( $row ), $selected['cache'] )[0]; }
	}

	private static function selection( array $range, array $args, bool $complete = false ): array {
		$cache = array( 'product_terms' => array(), 'term_names' => array(), 'methods' => array(), 'products' => array() );
		$current = self::period( (int) $range['from'], (int) $range['to'], $cache );
		$previous = self::period( (int) $range['compare_from'], (int) $range['compare_to'], $cache );
		$table = $args['table'];
		$rows = self::candidates( $table, $current[$table], $previous[$table] );
		if ( $complete || '' !== $args['q'] ) { self::prime_products( $table, $rows, $cache ); }
		if ( '' !== $args['q'] ) {
			$rows = array_values( array_filter( $rows, static function ( array $row ) use ( $table, $args, &$cache ): bool {
				$label = self::named( $table, $row['key'], $cache )['label'];
				foreach ( array( $row['key'], $label, self::display_alias( $table, $label ) ) as $text ) { if ( false !== mb_stripos( $text, $args['q'] ) ) { return true; } }
				return false;
			} ) );
		}
		return array( 'rows' => $rows, 'cache' => $cache );
	}

	/** New full-table paths batch native post/term/meta caches before resolving many product labels. */
	private static function prime_products( string $table, array $rows, array &$cache ): void {
		if ( ! $rows || ! in_array( $table, self::PRODUCT_TABLES, true ) ) { return; }
		$ids = array_map( static fn( array $row ): int => (int) $row['key'], $rows );
		_prime_post_caches( $ids, true, true );
		// Native caches do not cache misses. One ID-only query proves absence without one miss per deleted row.
		global $wpdb;
		$present = array_fill_keys( array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', $ids ) . ')' ) ), true ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( $ids as $id ) { if ( ! isset( $present[$id] ) ) { $cache['products'][$id] = false; } }
	}

	/** Only aliases already displayed by Operations; literal product/category/coupon labels are unchanged. */
	private static function display_alias( string $table, string $label ): string {
		if ( 'unknown' === $label && in_array( $table, array( 'order_countries', 'customer_countries', 'visitor_browsers', 'order_browsers', 'payment_methods', 'payment_success', 'devices' ), true ) ) { return __( 'Unknown', 'peptide-core' ); }
		if ( 'devices' === $table ) {
			$names = array( 'desktop' => __( 'Desktop', 'peptide-core' ), 'mobile' => __( 'Mobile', 'peptide-core' ), 'tablet' => __( 'Tablet', 'peptide-core' ) );
			return $names[$label] ?? '';
		}
		return 'Other' === $label && in_array( $table, array( 'visitor_browsers', 'order_browsers' ), true ) ? __( 'Other', 'peptide-core' ) : '';
	}

	/**
	 * 一个时间段 [from, to) 的十四张表的原始值：key => 值。payment_success 只有这个时间段有支付尝试的支付方式。
	 *
	 * @param array $cache summary() 里的共用缓存。
	 * @return array<string,array>
	 */
	private static function period( int $from, int $to, array &$cache ): array {
		$out = array();
		foreach ( self::TABLES as $name ) {
			$out[ $name ] = array();
		}

		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => 'any',
				'date_created' => $from . '...' . ( $to - 1 ),
				'limit'        => -1,
				'return'       => 'objects',
			)
		);

		$paid_statuses = wc_get_is_paid_statuses();
		$attempts      = array();
		$successes     = array();
		$buyers        = array();
		$visitor_ids   = array();
		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( ! $order instanceof \WC_Order || Overview::is_test_order( $order ) ) {
				continue;
			}
			$status = (string) $order->get_status();
			$paid   = in_array( $status, $paid_statuses, true );
			$method = self::group_key( (string) $order->get_payment_method() );

			// 支付方式的名称：两个时间段里最新的订单（先创建时间，再订单 ID）的标题。
			$created = $order->get_date_created();
			$stamp   = array( $created ? $created->getTimestamp() : 0, (int) $order->get_id() );
			if ( ! isset( $cache['methods'][ $method ] ) || $stamp > $cache['methods'][ $method ]['stamp'] ) {
				$cache['methods'][ $method ] = array(
					'stamp' => $stamp,
					'title' => (string) $order->get_payment_method_title(),
				);
			}

			// 支付成功率：全部订单是尝试，已付款和已退款（付过款）的是成功。
			self::add( $attempts, $method, 1 );
			if ( $paid || 'refunded' === $status ) {
				self::add( $successes, $method, 1 );
			}
			if ( ! $paid ) {
				continue;
			}

			self::add( $out['payment_methods'], $method, 1 );

			$country = self::group_key( (string) $order->get_billing_country() );
			self::add( $out['order_countries'], $country, 1 );

			// 买家：有账号按账号，游客按账单邮箱（不分大小写）；游客没有邮箱的不算。
			$customer_id = (int) $order->get_customer_id();
			if ( $customer_id > 0 ) {
				$buyers[ $country ][ 'u' . $customer_id ] = true;
			} else {
				$email = strtolower( trim( (string) $order->get_billing_email() ) );
				if ( '' !== $email ) {
					$buyers[ $country ][ 'e' . $email ] = true;
				}
			}

			foreach ( $order->get_coupon_codes() as $code ) {
				self::add( $out['coupons'], (string) $code, 1 );
			}

			$visitor_ids[] = (int) $order->get_meta( Server_Events::ORDER_VISITOR_META );

			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$pid = (int) $item->get_product_id();
				if ( $pid <= 0 ) {
					continue;
				}
				$units = (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
				$sales = (float) $item->get_subtotal() - (float) $order->get_total_refunded_for_item( $item->get_id() );
				self::add( $out['products_units'], $pid, $units );
				self::add( $out['products_sales'], $pid, $sales );
				foreach ( self::term_ids( $pid, $cache ) as $term_id ) {
					self::add( $out['categories_units'], $term_id, $units );
					self::add( $out['categories_sales'], $term_id, $sales );
				}
			}
		}

		foreach ( $buyers as $country => $set ) {
			$out['customer_countries'][ $country ] = count( $set );
		}
		foreach ( $attempts as $method => $count ) {
			$out['payment_success'][ $method ] = round( ( $successes[ $method ] ?? 0 ) / $count * 100, 2 );
		}
		foreach ( array( 'products_sales', 'categories_sales' ) as $name ) {
			foreach ( $out[ $name ] as $key => $value ) {
				$out[ $name ][ $key ] = round( (float) $value, 2 );
			}
		}

		// 订单浏览器：订单带的访客的浏览器，没有就是 unknown。
		$browsers = self::browsers( $visitor_ids );
		foreach ( $visitor_ids as $visitor_id ) {
			self::add( $out['order_browsers'], self::group_key( $visitor_id > 0 ? ( $browsers[ $visitor_id ] ?? '' ) : '' ), 1 );
		}

		// 事件和访问：追踪的表没装时全部为空。
		if ( Schema::installed() ) {
			$out = self::tracked( $out, $from, $to );
		}
		return $out;
	}

	/**
	 * 事件和访问的四张表：浏览商品、加购商品（事件数）、访客浏览器、设备（不同访客数）。
	 *
	 * @param array<string,array> $out period() 的结果。
	 * @return array<string,array>
	 */
	private static function tracked( array $out, int $from, int $to ): array {
		global $wpdb;
		$events   = Schema::table( 'events' );
		$visits   = Schema::table( 'visits' );
		$visitors = Schema::table( 'visitors' );
		$fm       = $from * 1000;
		$tm       = $to * 1000;

		foreach ( array(
			'product_view' => 'products_viewed',
			'add_to_cart'  => 'products_carted',
		) as $type => $name ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, COUNT(*) AS n FROM {$events} WHERE type = %s AND occurred_ms >= %d AND occurred_ms < %d AND product_id > 0 GROUP BY product_id", $type, $fm, $tm ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				self::add( $out[ $name ], (int) $row['product_id'], (int) $row['n'] );
			}
		}

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE( v.browser, '' ) AS g, COUNT(DISTINCT s.visitor_id) AS n FROM {$visits} s LEFT JOIN {$visitors} v ON v.id = s.visitor_id WHERE s.start_ms >= %d AND s.start_ms < %d GROUP BY COALESCE( v.browser, '' )", $fm, $tm ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $row ) {
			self::add( $out['visitor_browsers'], self::group_key( (string) $row['g'] ), (int) $row['n'] );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT device AS g, COUNT(DISTINCT visitor_id) AS n FROM {$visits} WHERE start_ms >= %d AND start_ms < %d GROUP BY device", $fm, $tm ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $row ) {
			self::add( $out['devices'], self::group_key( (string) $row['g'] ), (int) $row['n'] );
		}
		return $out;
	}

	/** 分组值：空字符串算 unknown（设备、浏览器、国家和支付方式的原始值里不会有 'unknown'，所以换名不会把两行合并）。 */
	private static function group_key( string $raw ): string {
		return '' === $raw ? 'unknown' : $raw;
	}

	/** 把 $by 加到 $map[ $key ] 上（没有就从 0 开始）。 */
	private static function add( array &$map, $key, $by ): void {
		$map[ $key ] = ( $map[ $key ] ?? 0 ) + $by;
	}

	/**
	 * 父商品的 product_cat 分类 ID。每个商品只查一次；商品已删除或查询出错时没有分类。顺便记下分类名。
	 *
	 * @param array $cache summary() 里的共用缓存。
	 * @return int[]
	 */
	private static function term_ids( int $product_id, array &$cache ): array {
		if ( isset( $cache['product_terms'][ $product_id ] ) ) {
			return $cache['product_terms'][ $product_id ];
		}
		$ids   = array();
		$terms = wp_get_post_terms( $product_id, 'product_cat' );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$ids[] = (int) $term->term_id;

			$cache['term_names'][ (int) $term->term_id ] = wp_specialchars_decode( (string) $term->name, ENT_QUOTES );
		}
		$cache['product_terms'][ $product_id ] = $ids;
		return $ids;
	}

	/**
	 * 按访客 ID 取 browser：id => 值（没有 ID 或追踪的表没装时不查询）。IN 列表只由 intval 过的 id 组成。
	 *
	 * @param int[] $ids 访客 ID，0 表示没有。
	 * @return array<int,string>
	 */
	private static function browsers( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids || ! Schema::installed() ) {
			return array();
		}
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( 'SELECT id, browser FROM ' . Schema::table( 'visitors' ) . " WHERE id IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = (string) $row['browser'];
		}
		return $out;
	}

	/**
	 * 一张表的行：合并两个时间段的 key，过滤、排序、截断，最后才取名称（只取留下来的行）。
	 *
	 * @param array $current  这张表本期的 key => 值。
	 * @param array $previous 这张表对比期的 key => 值。
	 * @param array $cache    summary() 里的共用缓存。
	 * @return array{rows:array,total_rows:int}
	 */
	private static function table( string $name, array $current, array $previous, array &$cache ): array {
		$rows = self::candidates( $name, $current, $previous );
		return array( 'rows' => self::name_rows( $name, array_slice( $rows, 0, self::MAX_ROWS ), $cache ), 'total_rows' => count( $rows ) );
	}

	/** Eligibility, values and comparator are shared; the legacy caller still names only its first 50. */
	private static function candidates( string $name, array $current, array $previous ): array {
		$rate = 'payment_success' === $name;
		$rows = array();
		foreach ( array_keys( $current + $previous ) as $key ) {
			if ( $rate ) {
				// 成功率：某个时间段没有支付尝试时没有这个 key，值是 null；任何一边有尝试就留下。
				$c      = $current[ $key ] ?? null;
				$p      = $previous[ $key ] ?? null;
				$change = ( null === $c || null === $p ) ? null : round( (float) $c - (float) $p, 2 );
			} else {
				$c = $current[ $key ] ?? 0;
				$p = $previous[ $key ] ?? 0;
				if ( ! ( $c > 0 || $p > 0 ) ) {
					continue;
				}
				$change = Range::change( (float) $c, (float) $p );
			}
			$rows[] = array(
				'key'      => (string) $key,
				'current'  => $c,
				'previous' => $p,
				'change'   => $change,
			);
		}

		$numeric = in_array( $name, self::NUMERIC_KEYS, true );
		usort(
			$rows,
			static function ( array $a, array $b ) use ( $numeric ): int {
				$cmp = ( null === $b['current'] ? -1 : $b['current'] ) <=> ( null === $a['current'] ? -1 : $a['current'] );
				if ( 0 === $cmp ) {
					$cmp = ( null === $b['previous'] ? -1 : $b['previous'] ) <=> ( null === $a['previous'] ? -1 : $a['previous'] );
				}
				if ( 0 === $cmp ) {
					$cmp = $numeric ? (int) $a['key'] <=> (int) $b['key'] : strcmp( $a['key'], $b['key'] );
				}
				return $cmp;
			}
		);

		return $rows;
	}

	private static function name_rows( string $name, array $rows, array &$cache ): array {
		foreach ( $rows as $i => $row ) {
			$named      = self::named( $name, $row['key'], $cache );
			$rows[ $i ] = array(
				'key'      => $row['key'],
				'label'    => $named['label'],
				'edit_url' => $named['edit_url'],
				'current'  => $row['current'],
				'previous' => $row['previous'],
				'change'   => $row['change'],
			);
		}
		return array_values( $rows );
	}

	/**
	 * 一行的名称和编辑链接（只有商品行有链接）。
	 *
	 * @param array $cache summary() 里的共用缓存。
	 * @return array{label:string,edit_url:string}
	 */
	private static function named( string $table, string $key, array &$cache ): array {
		if ( in_array( $table, self::PRODUCT_TABLES, true ) ) {
			$id = (int) $key;
			if ( ! array_key_exists( $id, $cache['products'] ) ) {
				$cache['products'][ $id ] = wc_get_product( $id );
			}
			$product = $cache['products'][ $id ];
			if ( ! $product instanceof \WC_Product ) {
				return array(
					'label'    => sprintf( '#%d', $id ),
					'edit_url' => '',
				);
			}
			return array(
				'label'    => (string) $product->get_name(),
				'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
			);
		}

		$label = $key;
		if ( 'categories_units' === $table || 'categories_sales' === $table ) {
			$label = $cache['term_names'][ (int) $key ] ?? sprintf( '#%d', (int) $key );
		} elseif ( 'order_countries' === $table || 'customer_countries' === $table ) {
			// 国家名：WooCommerce 的名字里有 &#197; 这样的字符实体，转成普通字符（页面脚本会转义）；不认识的代码用代码本身。
			$countries = ( function_exists( 'WC' ) && WC()->countries ) ? WC()->countries->get_countries() : array();
			if ( isset( $countries[ $key ] ) && is_string( $countries[ $key ] ) && '' !== $countries[ $key ] ) {
				$label = html_entity_decode( $countries[ $key ], ENT_QUOTES, 'UTF-8' );
			}
		} elseif ( 'payment_methods' === $table || 'payment_success' === $table ) {
			$title = (string) ( $cache['methods'][ $key ]['title'] ?? '' );
			$label = '' !== $title ? $title : $key;
		}
		return array(
			'label'    => $label,
			'edit_url' => '',
		);
	}
}
