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
		$out['pc_number']    = '订单编号';
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
				// 店主 2026-11-11：操作按钮和“弃单”页一样（蓝色小方块图标 + 写邮件）。
				echo '<span class="pc-ol-ops"><a href="' . esc_url( Order_Detail_Page::url( $order->get_id() ) ) . '" title="查看订单" aria-label="查看订单"><span class="dashicons dashicons-visibility"></span></a>';
				if ( $order->is_paid() && ! $order->has_status( array( 'completed', 'refunded', 'cancelled' ) ) ) {
					echo '<a href="' . esc_url( $edit . '#woocommerce-order-fulfillments' ) . '" title="发货" aria-label="发货"><span class="dashicons dashicons-car"></span></a>';
				}
				if ( '' !== (string) $order->get_billing_email() && class_exists( Mail_Page::class ) ) {
					echo '<a href="' . esc_url( Mail_Page::compose_url( array( 'order_id' => $order->get_id(), 'to' => $order->get_billing_email() ) ) ) . '" title="写邮件" aria-label="写邮件"><span class="dashicons dashicons-email"></span></a>';
				}
				echo '</span>';
				break;
			case 'pc_number':
				// 店主 2026-11-11：订单编号只显示编号，不带顾客名、不带预览小眼睛。
				// 和支付平台对账用后面的“流水号”（支付平台的交易号）。
				echo '<a class="pc-ol-num" href="' . esc_url( Order_Detail_Page::url( $order->get_id() ) ) . '">#' . esc_html( $order->get_order_number() ) . '</a>';
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
.wp-list-table .column-pc_ops{width:104px}.pc-ol-ops{display:inline-flex;gap:2px}.pc-ol-ops a{display:inline-flex;width:28px;height:28px;align-items:center;justify-content:center;border-radius:6px;color:#2271b1;text-decoration:none}.pc-ol-ops a:hover{background:#f0f6fc;color:#2271b1}.pc-ol-ops .dashicons{font-size:18px;width:18px;height:18px}
.wp-list-table .column-pc_items{width:150px}.pc-ol-items{display:grid;line-height:1.35}.pc-ol-items small{color:#6b7280;font-size:12px}
.wp-list-table .column-pc_country{width:56px}.wp-list-table .column-pc_paid{width:150px}
.pc-ol-tid{font-size:11px;background:#f3f4f6;padding:2px 4px;border-radius:4px;word-break:break-all}
.pc-ol-muted{color:#9ca3af}
/* 像 Shopname 一样每行不折行，表格太宽就横向滚动 */
.woocommerce_page_wc-orders table.wp-list-table{display:block;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;table-layout:auto}
.woocommerce_page_wc-orders table.wp-list-table td,.woocommerce_page_wc-orders table.wp-list-table th{white-space:nowrap;vertical-align:middle}
.woocommerce_page_wc-orders table.wp-list-table .column-pc_number{min-width:90px}
.pc-ol-num{font-weight:600;font-variant-numeric:tabular-nums}
/* 左右滚动条一直显示（Mac 默认会藏起来） */
.woocommerce_page_wc-orders table.wp-list-table{scrollbar-width:auto;scrollbar-color:#94a3b8 #eef0f3;padding-bottom:2px}
.woocommerce_page_wc-orders table.wp-list-table::-webkit-scrollbar{height:12px;-webkit-appearance:none}
.woocommerce_page_wc-orders table.wp-list-table::-webkit-scrollbar-track{background:#eef0f3;border-radius:6px}
.woocommerce_page_wc-orders table.wp-list-table::-webkit-scrollbar-thumb{background:#94a3b8;border-radius:6px;border:2px solid #eef0f3}
.woocommerce_page_wc-orders table.wp-list-table::-webkit-scrollbar-thumb:hover{background:#64748b}
".pc-ol-bar{display:flex;align-items:center;gap:10px;margin:0 0 6px}
.pc-ol-bar.is-bottom{position:sticky;bottom:0;z-index:5;margin:0;padding:8px 0;background:linear-gradient(to top,#f6f7f9 70%,rgba(246,247,249,0))}
.pc-ol-bar__track{position:relative;flex:1;height:12px;border-radius:999px;background:#e5e7eb;cursor:pointer}
.pc-ol-bar__thumb{position:absolute;left:0;top:0;height:12px;border-radius:999px;background:#94a3b8;cursor:grab;touch-action:none}
.pc-ol-bar__thumb:hover,.pc-ol-bar__thumb:active{background:#64748b}
.pc-ol-bar__hint{font-size:12px;color:#9ca3af;white-space:nowrap}
.woocommerce_page_wc-orders table.wp-list-table .column-pc_items{white-space:normal;min-width:170px}
.woocommerce_page_wc-orders table.wp-list-table .pc-ol-tid{white-space:nowrap;word-break:normal}
.wp-list-table .column-order_status mark.order-status.status-processing{background:#fff4e5;color:#b45309}
</style>';
		// 店主 2026-11-11：左右滚动条要一直看得见（Mac 会藏原生滚动条）。自己画一条：灰色轨道 + 可拖动的滑块，
		// 店主 2026-11-11：只留表格下方这一条（固定在屏幕底部，往下翻也能拖），不要上面那条和“左右拖动查看更多列”文字。和表格左右位置同步。
		echo '<script>document.addEventListener("DOMContentLoaded",function(){var t=document.querySelector(".woocommerce_page_wc-orders table.wp-list-table");if(!t)return;
function bar(cls){var w=document.createElement("div");w.className="pc-ol-bar "+cls;w.innerHTML="<div class=\\"pc-ol-bar__track\\"><div class=\\"pc-ol-bar__thumb\\"></div></div>";return w;}
var bottom=bar("is-bottom");t.parentNode.insertBefore(bottom,t.nextSibling);var bars=[bottom];
function max(){return Math.max(0,t.scrollWidth-t.clientWidth);}
function paint(){var m=max();bars.forEach(function(b){b.style.display=m>2?"":"none";var tr=b.firstChild,th=tr.firstChild,w=tr.clientWidth,tw=Math.max(40,w*t.clientWidth/t.scrollWidth);th.style.width=tw+"px";th.style.transform="translateX("+(m?(w-tw)*t.scrollLeft/m:0)+"px)";});}
bars.forEach(function(b){var tr=b.firstChild,th=tr.firstChild,drag=null;
th.addEventListener("pointerdown",function(e){drag={x:e.clientX,s:t.scrollLeft};th.setPointerCapture(e.pointerId);e.preventDefault();});
th.addEventListener("pointermove",function(e){if(!drag)return;var w=tr.clientWidth-th.offsetWidth;t.scrollLeft=drag.s+(e.clientX-drag.x)*(w?max()/w:0);});
th.addEventListener("pointerup",function(){drag=null;});
tr.addEventListener("pointerdown",function(e){if(e.target!==tr)return;var r=tr.getBoundingClientRect();t.scrollLeft=max()*Math.min(1,Math.max(0,(e.clientX-r.left-th.offsetWidth/2)/(r.width-th.offsetWidth)));});});
t.addEventListener("scroll",paint);window.addEventListener("resize",paint);paint();setTimeout(paint,400);});</script>';
	}
}
