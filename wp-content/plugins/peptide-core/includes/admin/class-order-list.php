<?php
/**
 * 订单列表（店主 2026-11-11，参考 Shopname 后台）：
 * 列 = 操作（查看 / 发货）· 订单编号 · 商品 · 顾客邮箱 · 国家 · 配送方式 · 订单总价 · 支付方式 · 支付时间 · 流水号 · 订单状态 · 发货状态 · 物流单号 · 来源；
 * 状态名改成店主习惯的：正在处理 → 待发货，等待付款 → 待付款 ……（标签页跟着变）。
 *
 * @package PeptideCore\Admin
 */

namespace PeptideCore\Admin;

defined( 'ABSPATH' ) || exit;

final class Order_List {

	public static function register_hooks(): void {
		add_filter( 'wc_order_statuses', array( self::class, 'status_names' ), 99 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( self::class, 'columns' ), 999 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( self::class, 'cell' ), 20, 2 );
		add_action( 'admin_head', array( self::class, 'css' ) );
	}

	/** 订单状态名（后台、列表标签页、状态徽章都用这个）。 */
	public static function status_names( $statuses ) {
		if ( ! is_array( $statuses ) || ! is_admin() ) {
			return $statuses;
		}
		$names = array(
			'wc-pending'        => '待付款',
			'wc-processing'     => '待发货',
			'wc-on-hold'        => '待确认',
			'wc-completed'      => '已完成',
			'wc-cancelled'      => '已取消',
			'wc-refunded'       => '已退款',
			'wc-failed'         => '支付失败',
			'wc-checkout-draft' => '未完成',
		);
		foreach ( $names as $k => $v ) {
			if ( isset( $statuses[ $k ] ) ) {
				$statuses[ $k ] = $v;
			}
		}
		return $statuses;
	}

	public static function columns( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$out = array( 'cb' => $columns['cb'] ?? '<input type="checkbox">' );
		$out['pc_ops']       = '操作';
		$out['order_number'] = '订单编号';
		$out['pc_items']     = '商品';
		$out['pc_email']     = '顾客邮箱';
		$out['pc_country']   = '国家';
		$out['pc_ship']      = '配送方式';
		$out['order_total']  = '订单总价';
		$out['pc_pay']       = '支付方式';
		$out['pc_paid']      = '支付时间';
		$out['pc_tid']       = '流水号';
		$out['order_status'] = '订单状态';
		if ( isset( $columns['fulfillment_status'] ) ) {
			$out['fulfillment_status'] = '发货状态';
		}
		if ( isset( $columns['shipment_tracking'] ) ) {
			$out['shipment_tracking'] = '物流单号';
		}
		if ( isset( $columns['origin'] ) ) {
			$out['origin'] = '来源';
		}
		return $out;
	}

	public static function cell( $column, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		switch ( $column ) {
			case 'pc_ops':
				$edit = $order->get_edit_order_url();
				echo '<span class="pc-ol-ops"><a href="' . esc_url( $edit ) . '" title="查看订单" aria-label="查看订单"><span class="dashicons dashicons-visibility"></span></a>';
				if ( $order->is_paid() && ! $order->has_status( array( 'completed', 'refunded', 'cancelled' ) ) ) {
					echo '<a href="' . esc_url( $edit . '#woocommerce-order-fulfillments' ) . '" title="发货" aria-label="发货"><span class="dashicons dashicons-car"></span></a>';
				}
				echo '</span>';
				break;
			case 'pc_items':
				$names = array();
				$qty   = 0;
				foreach ( $order->get_items() as $it ) {
					$names[] = $it->get_name() . ' × ' . $it->get_quantity();
					$qty    += (int) $it->get_quantity();
				}
				echo '<span class="pc-ol-items" title="' . esc_attr( implode( "\n", $names ) ) . '">' . esc_html( $qty . ' 件' ) . '<small>' . esc_html( $names ? wp_html_excerpt( $names[0], 28, '…' ) . ( count( $names ) > 1 ? ' 等' : '' ) : '' ) . '</small></span>';
				break;
			case 'pc_email':
				echo esc_html( (string) $order->get_billing_email() ?: '—' );
				break;
			case 'pc_country':
				echo esc_html( (string) ( $order->get_shipping_country() ?: $order->get_billing_country() ) ?: '—' );
				break;
			case 'pc_ship':
				echo esc_html( (string) $order->get_shipping_method() ?: '—' );
				break;
			case 'pc_pay':
				echo esc_html( (string) $order->get_payment_method_title() ?: '—' );
				break;
			case 'pc_paid':
				$p = $order->get_date_paid();
				echo $p ? esc_html( wp_date( 'Y-m-d H:i:s', $p->getTimestamp() ) ) : '<span class="pc-ol-muted">未付款</span>';
				break;
			case 'pc_tid':
				$t = (string) $order->get_transaction_id();
				echo '' !== $t ? '<code class="pc-ol-tid">' . esc_html( $t ) . '</code>' : '—';
				break;
		}
	}

	public static function css(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'woocommerce_page_wc-orders' !== $screen->id ) {
			return;
		}
		echo '<style>
.wp-list-table .column-pc_ops{width:64px}.pc-ol-ops{display:inline-flex;gap:8px}.pc-ol-ops a{color:#475569;text-decoration:none}.pc-ol-ops a:hover{color:#111827}
.wp-list-table .column-pc_items{width:150px}.pc-ol-items{display:grid;line-height:1.35}.pc-ol-items small{color:#6b7280;font-size:12px}
.wp-list-table .column-pc_country{width:56px}.wp-list-table .column-pc_paid{width:150px}
.pc-ol-tid{font-size:11px;background:#f3f4f6;padding:2px 4px;border-radius:4px;word-break:break-all}
.pc-ol-muted{color:#9ca3af}
/* 像 Shopname 一样每行不折行，表格太宽就横向滚动 */
.woocommerce_page_wc-orders table.wp-list-table{display:block;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;table-layout:auto}
.woocommerce_page_wc-orders table.wp-list-table td,.woocommerce_page_wc-orders table.wp-list-table th{white-space:nowrap;vertical-align:middle}
.woocommerce_page_wc-orders table.wp-list-table .column-order_number{min-width:170px}
.woocommerce_page_wc-orders table.wp-list-table .column-pc_items{white-space:normal;min-width:170px}
.woocommerce_page_wc-orders table.wp-list-table .pc-ol-tid{white-space:nowrap;word-break:normal}
.wp-list-table .column-order_status mark.order-status.status-processing{background:#fff4e5;color:#b45309}
</style>';
	}
}
