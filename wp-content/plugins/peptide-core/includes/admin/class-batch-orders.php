<?php
/**
 * 批次反查订单（溯源、召回）：批次编辑页的“收到此批次的订单”面板、CSV 导出、批次列表的订单数列。
 *
 * 结果含顾客联系信息，需要同时具备 WooCommerce 的订单管理权限（edit_shop_orders）和该批次的编辑权限。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Meta;
use PeptideCore\Order_Batches;
use PeptideCore\Post_Types;

defined( 'ABSPATH' ) || exit;

final class Batch_Orders {

	public const EXPORT_ACTION = 'pc_export_batch_orders';

	/** 面板里最多直接列出的订单数；更多的请导出 CSV。 */
	private const PANEL_LIMIT = 50;

	public static function register_hooks(): void {
		add_action( 'add_meta_boxes_' . Post_Types::BATCH, array( self::class, 'add' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( self::class, 'export' ) );
		add_filter( 'manage_' . Post_Types::BATCH . '_posts_columns', array( self::class, 'add_column' ), 20 );
		add_action( 'manage_' . Post_Types::BATCH . '_posts_custom_column', array( self::class, 'render_column' ), 10, 2 );
	}

	public static function can_view( int $batch_id ): bool {
		return current_user_can( 'edit_shop_orders' ) && current_user_can( 'edit_post', $batch_id );
	}

	public static function add( \WP_Post $post ): void {
		if ( 'auto-draft' === $post->post_status || ! self::can_view( $post->ID ) ) {
			return;
		}
		add_meta_box( 'pc_batch_orders', __( 'Orders with this batch', 'peptide-core' ), array( self::class, 'render' ), Post_Types::BATCH, 'normal', 'default' );
	}

	public static function render( \WP_Post $post ): void {
		$rows = Order_Batches::orders_for_batch( $post->ID );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No order has received this batch yet. Orders appear here after a batch is assigned to an order item.', 'peptide-core' ) . '</p>';
			return;
		}

		$customers = count( array_unique( array_map( static fn( $r ) => strtolower( $r['email'] ?: '#' . $r['order_id'] ), $rows ) ) );
		$quantity  = array_sum( array_column( $rows, 'quantity' ) );
		printf(
			'<p><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: 1: number of orders, 2: number of customers, 3: total quantity */
					__( '%1$d orders, %2$d customers, %3$d units in total.', 'peptide-core' ),
					count( $rows ),
					$customers,
					$quantity
				)
			)
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Order', 'peptide-core' ), __( 'Date', 'peptide-core' ), __( 'Status', 'peptide-core' ), __( 'Customer', 'peptide-core' ), __( 'Email', 'peptide-core' ), __( 'Items', 'peptide-core' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, self::PANEL_LIMIT ) as $r ) {
			$order = wc_get_order( $r['order_id'] );
			printf(
				'<tr><td><a href="%1$s">#%2$s</a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
				esc_url( $order ? $order->get_edit_order_url() : '' ),
				esc_html( $r['number'] ),
				esc_html( $r['date'] ),
				esc_html( $r['status'] ),
				esc_html( '' !== $r['customer'] ? $r['customer'] : __( '(guest)', 'peptide-core' ) ),
				esc_html( $r['email'] ),
				esc_html( implode( '; ', array_map( static fn( $i ) => $i['name'] . ' × ' . $i['quantity'], $r['items'] ) ) )
			);
		}
		echo '</tbody></table>';
		if ( count( $rows ) > self::PANEL_LIMIT ) {
			/* translators: %d: number of orders shown */
			echo '<p class="description">' . esc_html( sprintf( __( 'Showing the latest %d orders. Export CSV to see all.', 'peptide-core' ), self::PANEL_LIMIT ) ) . '</p>';
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::EXPORT_ACTION,
					'batch_id' => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_ACTION . '_' . $post->ID
		);
		printf(
			'<p><a class="button" href="%1$s">%2$s</a> <span class="description">%3$s</span></p>',
			esc_url( $url ),
			esc_html__( 'Export CSV (for recall notices)', 'peptide-core' ),
			esc_html__( 'Contains customer contact details. Handle according to your privacy policy.', 'peptide-core' )
		);
	}

	/**
	 * admin-post.php?action=pc_export_batch_orders&batch_id=…&_wpnonce=…
	 */
	public static function export(): void {
		$batch_id = isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 下面校验。
		check_admin_referer( self::EXPORT_ACTION . '_' . $batch_id );
		if ( Post_Types::BATCH !== get_post_type( $batch_id ) || ! self::can_view( $batch_id ) ) {
			wp_die( esc_html__( 'You do not have permission to export these orders.', 'peptide-core' ), '', array( 'response' => 403 ) );
		}

		$batch_no = (string) get_post_meta( $batch_id, Meta::BATCH_NO, true );
		$csv      = Order_Batches::orders_csv( $batch_no, Order_Batches::orders_for_batch( $batch_id ) );
		$name     = sanitize_file_name( 'batch-' . $batch_no . '-orders-' . gmdate( 'Ymd' ) . '.csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV 文件内容，已在 orders_csv() 中处理公式注入。
		exit;
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function add_column( array $columns ): array {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return $columns;
		}
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'pc_reports' === $key ) {
				$out['pc_orders'] = __( 'Orders', 'peptide-core' );
			}
		}
		return isset( $out['pc_orders'] ) ? $out : $columns + array( 'pc_orders' => __( 'Orders', 'peptide-core' ) );
	}

	public static function render_column( string $column, int $post_id ): void {
		if ( 'pc_orders' === $column ) {
			// 列表里只需要数量：只按订单索引计数，不逐行整理顾客和商品信息。
			echo esc_html( (string) count( Order_Batches::order_ids_with_batch( $post_id ) ) );
		}
	}
}
