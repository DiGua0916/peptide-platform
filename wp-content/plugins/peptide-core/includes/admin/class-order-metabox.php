<?php
/**
 * 订单编辑页的“批次分配”面板：发货时为每个商品行指定实际发出的批次。
 * 同时支持 WooCommerce 高性能订单存储（HPOS）和旧版订单页面。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Admin_Notices;
use PeptideCore\Meta;
use PeptideCore\Order_Batches;

defined( 'ABSPATH' ) || exit;

final class Order_Metabox {

	private const NONCE_ACTION = 'pc_assign_batches';
	private const NONCE_FIELD  = 'pc_assign_batches_nonce';
	public const CAP           = 'pc_assign_batches';

	public static function register_hooks(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( self::class, 'save' ), 50, 1 );
	}

	public static function add(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$screen = wc_get_page_screen_id( 'shop-order' );
		add_meta_box( 'pc_order_batches', __( 'Batch assignment', 'peptide-core' ), array( self::class, 'render' ), $screen, 'normal', 'default' );
	}

	/**
	 * @param \WP_Post|\WC_Order $post_or_order 旧版页面传 WP_Post，HPOS 页面传 WC_Order。
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$items = array_filter( $order->get_items(), static fn( $i ) => $i instanceof \WC_Order_Item_Product );
		if ( ! $items ) {
			echo '<p>' . esc_html__( 'This order has no product items.', 'peptide-core' ) . '</p>';
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<p class="description">' . esc_html__( 'When shipping, choose the batch actually sent for each item. Customers will see the batch number in their order and can download that batch\'s published reports.', 'peptide-core' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Item', 'peptide-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Qty', 'peptide-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Batch', 'peptide-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $items as $item_id => $item ) {
			$current    = (int) $item->get_meta( Order_Batches::ITEM_META );
			$candidates = Order_Batches::candidates( $item );
			echo '<tr><td>' . esc_html( $item->get_name() ) . '</td>';
			echo '<td>' . esc_html( (string) $item->get_quantity() ) . '</td><td>';

			if ( ! $candidates && ! $current ) {
				esc_html_e( 'No active batch for this product yet.', 'peptide-core' );
				printf( '<input type="hidden" name="pc_item_batch[%d]" value="0">', (int) $item_id );
			} else {
				printf( '<select name="pc_item_batch[%d]">', (int) $item_id );
				echo '<option value="0">' . esc_html__( '— Not assigned —', 'peptide-core' ) . '</option>';
				foreach ( $candidates as $batch_id ) {
					$label = (string) get_post_meta( $batch_id, Meta::BATCH_NO, true );
					if ( 'depleted' === get_post_meta( $batch_id, Meta::BATCH_STATUS, true ) ) {
						$label .= ' ' . __( '(depleted)', 'peptide-core' );
					}
					if ( 'hidden' === get_post_meta( $batch_id, Meta::BATCH_VISIBILITY, true ) ) {
						$label .= ' ' . __( '(hidden)', 'peptide-core' );
					}
					printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $batch_id, selected( $current, $batch_id, false ), esc_html( $label ) );
				}
				echo '</select>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function save( $order_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) || ! isset( $_POST['pc_item_batch'] ) || ! is_array( $_POST['pc_item_batch'] ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$assignments = array();
		foreach ( wp_unslash( $_POST['pc_item_batch'] ) as $item_id => $batch_id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 逐项 absint。
			$assignments[ absint( $item_id ) ] = absint( $batch_id );
		}

		$errors = Order_Batches::save_assignments( $order, $assignments );
		foreach ( $errors as $name ) {
			/* translators: %s: product name */
			Admin_Notices::add( sprintf( __( 'The selected batch does not match "%s" and was not saved.', 'peptide-core' ), $name ) );
		}
	}
}
