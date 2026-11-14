<?php
/**
 * 订单列表（店主 2026-11-11，参考 Shopname 后台）：
 * 列 = 操作（查看 / 发货）· 订单编号 · 商品 · 顾客邮箱 · 国家 · 配送方式 · 订单总价 · 支付方式 · 支付时间 · 流水号 · 订单状态 · 发货状态 · 物流单号 · 来源；
 * 状态名改成店主习惯的：正在处理 → 待发货，等待付款 → 待付款 ……（标签页跟着变）。
 *
 * 店主 2026-11-13：
 * - 列表上面的筛选（日期 / 发货记录 / 服务商 / 销售渠道 / 注册客户）和状态标签页去掉，搜索保留；
 * - “待处理订单”只放未发货的（已付款、处理中）；发了货的（已完成）在新的“已发货订单”里；弃单页不动；
 * - 发货 = 在列表里点货车图标，只填 FedEx 单号：建 WooCommerce 原生发货记录（承运商 FedEx + 单号 + 追踪链接，
 *   顾客在“我的账户”和右下角物流球里看到），订单改为已完成，给顾客发一封带单号的“已发货”邮件
 *   （WooCommerce 的“订单已完成”邮件这一次不再发，免得顾客收两封）。
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
		add_action( 'admin_menu', array( self::class, 'menu' ), 20000 );
		add_action( 'load-woocommerce_page_wc-orders', array( self::class, 'default_to_pending' ) );
		add_action( 'admin_post_' . self::SHIP_ACTION, array( self::class, 'ship' ) );
		add_action( 'admin_notices', array( self::class, 'ship_notice' ) );
	}

	public const SHIPPED     = 'admin.php?page=wc-orders&status=wc-completed';
	public const SHIP_ACTION = 'pc_ship_order';

	/** 能发货的人：店主 / 管理员（投手的订单页只读，不显示发货）。 */
	private static function can_ship(): bool {
		return current_user_can( 'edit_shop_orders' ) && ! ( class_exists( \PeptideCore\Staff_Buyer::class ) && \PeptideCore\Staff_Buyer::current() );
	}

	/** 订单菜单：待处理订单下面加“已发货订单”。 */
	public static function menu(): void {
		global $submenu;
		if ( ! isset( $submenu[ Admin_Menus::PENDING ] ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$item  = array( '已发货订单', 'edit_shop_orders', self::SHIPPED, '已发货订单' );
		$list  = array_values( $submenu[ Admin_Menus::PENDING ] );
		$first = array_search( Admin_Menus::PENDING, array_column( $list, 2 ), true );
		array_splice( $list, false === $first ? 0 : $first + 1, 0, array( $item ) );
		$submenu[ Admin_Menus::PENDING ] = $list; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/** 订单列表没带状态（或“所有”）时，直接打开待处理订单。打开单张订单、搜索不受影响。 */
	public static function default_to_pending(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! current_user_can( 'edit_shop_orders' ) || isset( $_GET['action'] ) || isset( $_GET['id'] ) || isset( $_GET['s'] ) ) {
			return;
		}
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $status || 'all' === $status ) {
			wp_safe_redirect( admin_url( Admin_Menus::PENDING ) );
			exit;
		}
	}

	/** FedEx 单号：去空格、大写，只收字母数字 8–34 位。不合格返回 ''。 */
	public static function clean_tracking( string $raw ): string {
		$n = strtoupper( (string) preg_replace( '/[\s-]+/', '', $raw ) );
		return 1 === preg_match( '/^[A-Z0-9]{8,34}$/', $n ) ? $n : '';
	}

	public static function fedex_url( string $number ): string {
		return 'https://www.fedex.com/fedextrack/?trknbr=' . rawurlencode( $number );
	}

	/** 列表里的发货：填 FedEx 单号 → 原生发货记录 + 订单已完成 + 一封带单号的已发货邮件。 */
	public static function ship(): void {
		$id   = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 下面校验。
		$back = wp_get_referer() ? (string) wp_get_referer() : admin_url( Admin_Menus::PENDING );
		if ( ! self::can_ship() ) {
			wp_die( esc_html( '没有权限。' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SHIP_ACTION . '_' . $id );
		$say = static function ( string $type, string $text ) use ( $back ): void {
			set_transient( 'pc_ship_notice_' . get_current_user_id(), array( $type, $text ), 120 );
			wp_safe_redirect( $back );
			exit;
		};
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order || ! $order->is_paid() || ! $order->has_status( 'processing' ) ) {
			$say( 'error', '这张订单不是待发货状态，没有发货。' );
		}
		$number = self::clean_tracking( isset( $_POST['tracking'] ) ? (string) wp_unslash( $_POST['tracking'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 上面校验，clean_tracking 只留字母数字。
		if ( '' === $number ) {
			$say( 'error', '#' . $order->get_order_number() . ' 没有发货：单号不对（FedEx 单号是 8–34 位字母或数字）。' );
		}
		$fulfillment = null;
		if ( \PeptideCore\Shipping_Tracking::native_enabled() ) {
			$items = array();
			foreach ( $order->get_items() as $item_id => $item ) {
				$qty = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id );
				if ( $qty > 0 ) {
					$items[] = array( 'item_id' => (int) $item_id, 'qty' => $qty );
				}
			}
			try {
				$fulfillment = new \Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment();
				$fulfillment->set_entity_type( \WC_Order::class );
				$fulfillment->set_entity_id( (string) $order->get_id() );
				$fulfillment->set_status( 'fulfilled' );
				$fulfillment->set_items( $items );
				$fulfillment->set_shipment_provider( 'fedex' );
				$fulfillment->set_tracking_number( $number );
				$fulfillment->set_tracking_url( self::fedex_url( $number ) );
				$fulfillment->save();
			} catch ( \Throwable $e ) {
				$say( 'error', '#' . $order->get_order_number() . ' 没有发货：' . wp_strip_all_tags( $e->getMessage() ) );
			}
			$order = wc_get_order( $id ); // 发货记录会改订单的发货状态，重新读一次再改订单状态。
		}
		$order->update_meta_data( '_pc_tracking_number', $number );
		if ( $fulfillment ) {
			add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false', 999 );
		}
		$order->update_status( 'completed', 'Shipped with FedEx, tracking number ' . $number . '.' );
		if ( $fulfillment ) {
			remove_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false', 999 );
			// WooCommerce 原生的“已发货”顾客邮件（带承运商、单号、追踪链接）；默认标题是“An item ... has been fulfilled!”，换成正式一点的。
			$subject = static function () use ( $order ): string {
				$brand = class_exists( \PeptideCore\Mail_Outbox::class ) ? \PeptideCore\Mail_Outbox::brand() : wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
				return 'Your ' . $brand . ' order #' . $order->get_order_number() . ' has shipped';
			};
			add_filter( 'woocommerce_email_subject_customer_fulfillment_created', $subject, 999 );
			do_action( 'woocommerce_fulfillment_created_notification', $order->get_id(), $fulfillment, $order );
			remove_filter( 'woocommerce_email_subject_customer_fulfillment_created', $subject, 999 );
		}
		$say( 'success', '#' . $order->get_order_number() . ' 已发货，FedEx 单号 ' . $number . '。订单移到“已发货订单”，顾客已收到发货邮件，登录账户也能查到单号。' );
	}

	public static function ship_notice(): void {
		$key = 'pc_ship_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( is_array( $msg ) && 2 === count( $msg ) ) {
			delete_transient( $key );
			echo '<div class="notice notice-' . ( 'error' === $msg[0] ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( (string) $msg[1] ) . '</p></div>';
		}
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
				if ( $order->is_paid() && $order->has_status( 'processing' ) && self::can_ship() ) {
					// 店主 2026-11-13：发货只填 FedEx 单号（点货车图标在这一行下面弹出填单号的小框）。
					echo '<button type="button" class="pc-ol-ship" data-pc-ship="' . (int) $order->get_id() . '" data-number="' . esc_attr( '#' . $order->get_order_number() ) . '" data-nonce="' . esc_attr( wp_create_nonce( self::SHIP_ACTION . '_' . $order->get_id() ) ) . '" title="发货" aria-label="发货"><span class="dashicons dashicons-car"></span></button>';
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
/* 店主 2026-11-13：去掉上面的筛选和状态标签页，只留搜索 */
.woocommerce_page_wc-orders ul.subsubsub,.woocommerce_page_wc-orders .tablenav .alignleft.actions:not(.bulkactions){display:none!important}
.pc-ol-ops .pc-ol-ship{display:inline-flex;width:28px;height:28px;align-items:center;justify-content:center;border-radius:6px;color:#2271b1;background:none;border:0;padding:0;cursor:pointer}
.pc-ol-ops .pc-ol-ship:hover{background:#f0f6fc}
.pc-ol-shipbox{position:absolute;z-index:50;background:#fff;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14);padding:12px;width:300px;max-width:calc(100vw - 32px)}
.pc-ol-shipbox h3{margin:0 0 8px;font-size:14px}
.pc-ol-shipbox label{display:block;font-size:12px;color:#50575e;margin-bottom:4px}
.pc-ol-shipbox input[type=text]{width:100%;font-family:monospace;font-size:14px;letter-spacing:.5px}
.pc-ol-shipbox .pc-ol-shipacts{display:flex;gap:8px;justify-content:flex-end;margin-top:10px}
.pc-ol-shipbox .pc-ol-shiperr{color:#b32d2e;font-size:12px;margin:6px 0 0;min-height:1em}
</style>';
		if ( self::can_ship() ) {
			echo '<script>document.addEventListener("click",function(e){var b=e.target.closest(".pc-ol-ship");var box=document.querySelector(".pc-ol-shipbox");
if(box&&!e.target.closest(".pc-ol-shipbox")){box.remove();if(!b)return;}
if(!b)return;e.preventDefault();var f=document.createElement("form");f.className="pc-ol-shipbox";f.method="post";f.action=' . wp_json_encode( admin_url( 'admin-post.php' ) ) . ';
f.innerHTML="<h3></h3><label>FedEx 单号</label><input type=\"text\" name=\"tracking\" required autocomplete=\"off\" inputmode=\"latin\" placeholder=\"例如 794612345678\"><p class=\"pc-ol-shiperr\"></p><div class=\"pc-ol-shipacts\"><button type=\"button\" class=\"button\" data-x>取消</button><button type=\"submit\" class=\"button button-primary\">确认发货</button></div><input type=\"hidden\" name=\"action\" value=\"' . esc_js( self::SHIP_ACTION ) . '\"><input type=\"hidden\" name=\"order_id\"><input type=\"hidden\" name=\"_wpnonce\">";
f.querySelector("h3").textContent="发货 "+b.getAttribute("data-number");f.elements.order_id.value=b.getAttribute("data-pc-ship");f.elements._wpnonce.value=b.getAttribute("data-nonce");
document.body.appendChild(f);var r=b.getBoundingClientRect();f.style.top=(window.scrollY+r.bottom+6)+"px";f.style.left=Math.max(16,Math.min(window.scrollX+r.left,window.scrollX+document.documentElement.clientWidth-f.offsetWidth-16))+"px";f.elements.tracking.focus();
f.querySelector("[data-x]").onclick=function(){f.remove();};
f.addEventListener("submit",function(ev){var v=f.elements.tracking.value.replace(/[\\s-]+/g,"").toUpperCase();f.elements.tracking.value=v;if(!/^[A-Z0-9]{8,34}$/.test(v)){ev.preventDefault();f.querySelector(".pc-ol-shiperr").textContent="FedEx 单号是 8–34 位字母或数字";return;}f.querySelector("[type=submit]").disabled=true;});});</script>';
		}
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
