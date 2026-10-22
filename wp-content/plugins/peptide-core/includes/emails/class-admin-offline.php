<?php
/**
 * 线下付款订单通知（管理员）：顾客选了线下付款方式（银行转账、支票、货到付款等，订单进入“保留”）时通知管理员，
 * 提醒尽快联系顾客、确认收款。开启后 WooCommerce “新订单”邮件不再为保留状态的订单另发一封（见 Registry）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

defined( 'ABSPATH' ) || exit;

class Admin_Offline extends Admin_Base {

	public function __construct() {
		$this->id          = 'pc_admin_offline';
		$this->title       = __( 'Offline payment order (admin)', 'peptide-core' );
		$this->description = __( 'Sent when a customer places an order with an offline payment method (the order is on hold until you confirm the payment).', 'peptide-core' );
		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}] Offline payment order #{order_number}', 'peptide-core' );
	}

	public function get_default_heading() {
		return __( 'New offline payment order', 'peptide-core' );
	}

	public function trigger( $order_id, $order = null ): bool {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}
		$this->object                         = $order;
		$this->placeholders['{order_number}'] = $order->get_order_number();
		return $this->deliver();
	}

	protected function body_html(): string {
		$order = $this->object instanceof \WC_Order ? $this->object : null;
		if ( ! $order ) {
			return '';
		}
		$html  = '<p>' . esc_html__( 'A new order is waiting for an offline payment. Contact the customer as soon as possible and confirm the payment.', 'peptide-core' ) . '</p>';
		$html .= $this->details(
			array(
				__( 'Order', 'peptide-core' )          => '#' . $order->get_order_number(),
				__( 'Customer', 'peptide-core' )       => trim( $order->get_formatted_billing_full_name() . ' <' . $order->get_billing_email() . '>' ),
				__( 'Payment method', 'peptide-core' ) => $order->get_payment_method_title(),
				__( 'Total', 'peptide-core' )          => wp_strip_all_tags( wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) ),
			)
		);
		return $html . $this->button( $order->get_edit_order_url(), __( 'Open the order', 'peptide-core' ) );
	}

	public function preview(): void {}
}
