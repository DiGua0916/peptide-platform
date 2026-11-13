<?php
/**
 * 投手导出订单（M9，ST-03）。
 *
 * 订单列表上方一行：选付款日期范围，下载 CSV。只读，不改任何订单。
 * - 只导已付款的订单（按付款时间），测试单不导。
 * - 一次最多 92 天、5000 行；不填日期时是最近 30 天。
 * - 列：订单号、时间、状态、金额、商品、国家、来源广告、运单、退款。不含电话和地址。
 * - 顾客邮箱默认不导（平台所有者 2026-11-12 还没定）。要带上：这家店的选项 pc_buyer_export_email 设为 on
 *   （wp option update pc_buyer_export_email on），或用过滤器 pc_buyer_export_with_email。只有这一处开关。
 * - 以 = + - @ 开头的内容前面加一个单引号，表格软件不会把它当公式执行。
 * - 每次导出记一条操作日志（谁、哪段日期、多少行）。
 *
 * 走 admin-post.php?action=pc_buyer_orders_export（Staff_Buyer 的白名单里）。管理员也可以用同一个地址。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Activity_Log;
use PeptideCore\Staff_Buyer;

defined( 'ABSPATH' ) || exit;

final class Buyer_Order_Export {

	public const NONCE        = 'pc_buyer_orders_export';
	public const MAX_DAYS     = 92;
	public const MAX_ROWS     = 5000;
	public const DEFAULT_DAYS = 30;
	private const PAGE_SIZE   = 200;
	/** 导出里带不带顾客邮箱：值 on 才带。 */
	public const OPTION_EMAIL = 'pc_buyer_export_email';
	/** “顾客邮箱”一列的位置（件数后面、国家前面）。 */
	private const EMAIL_AT    = 8;

	public static function register_hooks(): void {
		add_action( 'admin_post_' . Staff_Buyer::ACTION_EXPORT, array( self::class, 'handle' ) );
		add_action( 'admin_notices', array( self::class, 'form' ), 20 );
	}

	/** 这家店的导出带不带顾客邮箱。默认不带。 */
	public static function with_email(): bool {
		return (bool) apply_filters( 'pc_buyer_export_with_email', 'on' === (string) get_option( self::OPTION_EMAIL, 'off' ) );
	}

	/** 表头。 */
	public static function columns( bool $with_email = false ): array {
		$columns = array( '订单号', '下单时间', '付款时间', '订单状态', '订单金额', '币种', '商品', '件数', '国家', '来源广告', 'utm_source', 'utm_campaign', 'utm_content', 'utm_term', '承运商', '运单号', '退款状态', '退款金额' );
		if ( $with_email ) {
			array_splice( $columns, self::EMAIL_AT, 0, array( '顾客邮箱' ) );
		}
		return $columns;
	}

	/**
	 * 日期范围（不读数据库，测试里直接调用）。
	 *
	 * @param string $from 开始日期 YYYY-MM-DD，可为空。
	 * @param string $to   结束日期 YYYY-MM-DD，可为空。
	 * @param string $today 店铺时区的今天 YYYY-MM-DD。
	 * @return array{from:string,to:string,clamped:bool} 整理后的范围：两头都含；顺序反了会调换；
	 *         结束不晚于今天；超过 MAX_DAYS 天时保留结束日期、把开始日期往后收（clamped = true）。
	 */
	public static function range( string $from, string $to, string $today ): array {
		$valid = static function ( string $day ): string {
			$day = trim( $day );
			if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				return '';
			}
			return $day;
		};
		$shift = static fn( string $day, int $days ): string => gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + $days * 86400 );
		$today = '' !== $valid( $today ) ? $today : gmdate( 'Y-m-d' );
		$to    = $valid( $to );
		$from  = $valid( $from );
		if ( '' !== $from && '' !== $to && $from > $to ) {
			[ $from, $to ] = array( $to, $from );
		}
		if ( '' === $to || $to > $today ) {
			$to = $today;
		}
		if ( '' === $from ) {
			$from = $shift( $to, 1 - self::DEFAULT_DAYS );
		}
		if ( $from > $to ) {
			$from = $to;
		}
		$clamped = false;
		$oldest  = $shift( $to, 1 - self::MAX_DAYS );
		if ( $from < $oldest ) {
			$from    = $oldest;
			$clamped = true;
		}
		return array( 'from' => $from, 'to' => $to, 'clamped' => $clamped );
	}

	/** 一个单元格的内容：去掉换行；以 = + - @ 或制表符开头时前面加单引号（不读数据库）。 */
	public static function cell( $value ): string {
		$text = trim( preg_replace( '/[\r\n\t]+/', ' ', is_scalar( $value ) ? (string) $value : '' ) ?? '' );
		return ( '' !== $text && in_array( $text[0], array( '=', '+', '-', '@' ), true ) ) ? "'" . $text : $text;
	}

	/**
	 * 一张订单的一行（不读数据库）。$f 的键见 facts()。
	 *
	 * @param array<string,mixed> $f
	 * @return string[]
	 */
	public static function row( array $f, bool $with_email = false ): array {
		$get = static fn( string $key ): string => self::cell( $f[ $key ] ?? '' );
		$row = array(
			$get( 'number' ),
			$get( 'created' ),
			$get( 'paid' ),
			$get( 'status' ),
			// 金额是数字，不走公式防护（负数不能变成文本）。
			number_format( (float) ( $f['total'] ?? 0 ), 2, '.', '' ),
			$get( 'currency' ),
			$get( 'items' ),
			(string) (int) ( $f['quantity'] ?? 0 ),
			$get( 'country' ),
			$get( 'source' ),
			$get( 'utm_source' ),
			$get( 'utm_campaign' ),
			$get( 'utm_content' ),
			$get( 'utm_term' ),
			$get( 'carrier' ),
			$get( 'tracking' ),
			$get( 'refund' ),
			number_format( max( 0.0, (float) ( $f['refund_amount'] ?? 0 ) ), 2, '.', '' ),
		);
		if ( $with_email ) {
			array_splice( $row, self::EMAIL_AT, 0, array( $get( 'email' ) ) );
		}
		return $row;
	}

	/** 从订单取出 row() 要的内容。 */
	private static function facts( \WC_Order $order, bool $with_email = false ): array {
		$time  = static fn( $date ): string => $date ? wp_date( 'Y-m-d H:i:s', $date->getTimestamp() ) : '';
		$names = array();
		$qty   = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$names[] = $item->get_name() . ' x' . (int) $item->get_quantity();
			$qty    += (int) $item->get_quantity();
		}
		$utm = array();
		foreach ( array( 'utm_source', 'utm_campaign', 'utm_content', 'utm_term' ) as $key ) {
			$utm[ $key ] = (string) $order->get_meta( '_wc_order_attribution_' . $key );
		}
		$shipment = is_callable( array( '\PeptideCore\Buyer_Status', 'shipment' ) ) ? \PeptideCore\Buyer_Status::shipment( $order ) : array();
		$refund   = Admin_Menus::refund_facts( $order );
		return $utm + array(
			'number'        => (string) $order->get_order_number(),
			'created'       => $time( $order->get_date_created() ),
			'paid'          => $time( $order->get_date_paid() ),
			'status'        => (string) wc_get_order_status_name( $order->get_status() ),
			'total'         => (float) $order->get_total(),
			'currency'      => (string) $order->get_currency(),
			'items'         => implode( '; ', $names ),
			'quantity'      => $qty,
			'email'         => $with_email ? (string) $order->get_billing_email() : '',
			'country'       => (string) ( $order->get_shipping_country() ?: $order->get_billing_country() ),
			'source'        => Admin_Menus::order_source( $utm + array( 'source_type' => (string) $order->get_meta( '_wc_order_attribution_source_type' ) ) ),
			'carrier'       => (string) ( $shipment['carrier'] ?? '' ),
			'tracking'      => (string) ( $shipment['number'] ?? '' ),
			'refund'        => $refund['label'],
			'refund_amount' => $refund['amount'],
		);
	}

	/** 店铺时区某一天的开始 / 结束（时间戳）。 */
	private static function day_edge( string $day, bool $end ): int {
		$date = date_create_immutable( $day . ( $end ? ' 23:59:59' : ' 00:00:00' ), wp_timezone() );
		return $date ? $date->getTimestamp() : 0;
	}

	/** 订单列表上方的导出表单（只给投手看；管理员的订单列表不变）。 */
	public static function form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只判断当前是不是订单列表。
		$page   = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$action = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:enable
		if ( 'wc-orders' !== $page || ! in_array( $action, array( '', '-1' ), true ) || ! Staff_Buyer::current() || ! Staff_Buyer::can_use() || ! Staff_Buyer::extras() ) {
			return;
		}
		$range = self::range( '', '', wp_date( 'Y-m-d' ) );
		// 放在 .notice 里：WooCommerce 的后台外壳会把 admin_notices 里不是提示条的内容整块藏起来。
		echo '<style>.pc-by-export-box{padding:10px 12px!important}.pc-by-export{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;margin:0}.pc-by-export label{display:inline-flex;align-items:center;gap:6px}.pc-by-export .description{color:#666}</style>';
		echo '<div class="notice notice-info pc-by-export-box"><form class="pc-by-export" method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( Staff_Buyer::ACTION_EXPORT ) . '">';
		wp_nonce_field( self::NONCE, '_wpnonce', false );
		echo '<strong>导出订单</strong>'
			. '<label>付款日期 <input type="date" name="from" value="' . esc_attr( $range['from'] ) . '" max="' . esc_attr( $range['to'] ) . '"></label>'
			. '<label>到 <input type="date" name="to" value="' . esc_attr( $range['to'] ) . '" max="' . esc_attr( $range['to'] ) . '"></label>'
			. '<button type="submit" class="button">下载表格</button>'
			. '<span class="description">只含已付款的订单，一次最多 ' . (int) self::MAX_DAYS . ' 天、' . (int) self::MAX_ROWS . ' 行；' . ( self::with_email() ? '含顾客邮箱，不含电话和地址。' : '不含顾客邮箱、电话和地址。' ) . '</span>'
			. '</form></div>';
	}

	/** admin-post.php?action=pc_buyer_orders_export&from=…&to=…&_wpnonce=… → CSV 下载。 */
	public static function handle(): void {
		if ( ! Staff_Buyer::can_use() || ! Staff_Buyer::extras() ) {
			Staff_Buyer::deny();
		}
		check_admin_referer( self::NONCE );
		if ( ! function_exists( 'wc_get_orders' ) ) {
			wp_die( esc_html( '订单功能没有启用。' ), '', array( 'response' => 503 ) );
		}
		$text  = static fn( string $key ): string => isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		$range = self::range( $text( 'from' ), $text( 'to' ), wp_date( 'Y-m-d' ) );
		$start = self::day_edge( $range['from'], false );
		$end   = self::day_edge( $range['to'], true );

		$email = self::with_email();
		$rows  = array();
		$page  = 1;
		while ( count( $rows ) < self::MAX_ROWS ) {
			$orders = wc_get_orders(
				array(
					'type'      => 'shop_order',
					'date_paid' => $start . '...' . $end,
					'limit'     => self::PAGE_SIZE,
					'page'      => $page,
					'orderby'   => 'date',
					'order'     => 'DESC',
					'return'    => 'objects',
				)
			);
			if ( ! $orders ) {
				break;
			}
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order || ! $order->get_date_paid() || \PeptideCore\Test_Orders::is_test_order( $order ) ) {
					continue;
				}
				$rows[] = self::row( self::facts( $order, $email ), $email );
				if ( count( $rows ) >= self::MAX_ROWS ) {
					break;
				}
			}
			if ( count( $orders ) < self::PAGE_SIZE ) {
				break;
			}
			++$page;
		}

		Activity_Log::log( 'order', 'export', array( 'type' => 'order', 'label' => $range['from'] . ' – ' . $range['to'] ), array( 'rows' => count( $rows ), 'email' => $email ? 'yes' : 'no' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="orders-' . str_replace( '-', '', $range['from'] ) . '-' . str_replace( '-', '', $range['to'] ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html( '导出失败，请再试一次。' ), '', array( 'response' => 500 ) );
		}
		fwrite( $out, "\xEF\xBB\xBF" ); // 让表格软件按 UTF-8 打开中文表头。
		fputcsv( $out, self::columns( $email ), ',', '"', '' );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row, ',', '"', '' );
		}
		fclose( $out );
		exit;
	}
}
