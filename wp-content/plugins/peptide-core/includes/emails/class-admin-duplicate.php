<?php
/**
 * 潜在重复支付（管理员）：同一顾客在一段时间内（默认 24 小时）付了金额相同的另一张订单时提醒，
 * 方便核对是否需要退款。默认发到财务邮箱（系统设置 → 基础配置），没有时发到管理员邮箱。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

use PeptideCore\Store_Config;

defined( 'ABSPATH' ) || exit;

class Admin_Duplicate extends Admin_Base {

	/** @var \WC_Order[] */
	public array $others = array();

	public function __construct() {
		$this->id          = 'pc_admin_duplicate';
		$this->title       = __( 'Possible duplicate payment (admin)', 'peptide-core' );
		$this->description = __( 'Sent when the same customer pays another order with the same total within the time window, so you can check whether a refund is needed.', 'peptide-core' );
		parent::__construct();
	}

	protected function default_recipient(): string {
		$finance = (string) Store_Config::get( 'finance_email' );
		return is_email( $finance ) ? $finance : (string) get_option( 'admin_email' );
	}

	public function get_default_subject() {
		return __( '[{site_title}] Possible duplicate payment: order #{order_number}', 'peptide-core' );
	}

	public function get_default_heading() {
		return __( 'Possible duplicate payment', 'peptide-core' );
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields['window'] = array(
			'title'             => __( 'Time window (hours)', 'peptide-core' ),
			'type'              => 'number',
			'default'           => '24',
			'custom_attributes' => array( 'min' => '1', 'max' => '720' ),
		);
	}

	public function window_hours(): int {
		return max( 1, min( 720, (int) $this->get_option( 'window', '24' ) ) );
	}

	/** @param \WC_Order[] $others */
	public function trigger( \WC_Order $order, array $others ): bool {
		$this->object                         = $order;
		$this->others                         = $others;
		$this->placeholders['{order_number}'] = $order->get_order_number();
		return $this->deliver();
	}

	protected function body_html(): string {
		$order = $this->object instanceof \WC_Order ? $this->object : null;
		if ( ! $order ) {
			return '';
		}
		$html = '<p>' . esc_html__( 'This customer paid more than one order with the same total in a short time. Please check whether one of them is a duplicate.', 'peptide-core' ) . '</p>';
		$rows = array();
		foreach ( array_merge( array( $order ), $this->others ) as $o ) {
			$paid = $o->get_date_paid() ? $o->get_date_paid() : $o->get_date_created();
			/* translators: %s: order number */
			$rows[ sprintf( __( 'Order #%s', 'peptide-core' ), $o->get_order_number() ) ] = wp_strip_all_tags( wc_price( (float) $o->get_total(), array( 'currency' => $o->get_currency() ) ) ) . ' · ' . $o->get_payment_method_title() . ' · ' . ( $paid ? wc_format_datetime( $paid, 'Y-m-d H:i' ) : '' ) . ( $o->get_transaction_id() ? ' · ' . $o->get_transaction_id() : '' );
		}
		$html .= $this->details( array( __( 'Customer', 'peptide-core' ) => $order->get_billing_email() ) + $rows );
		return $html . $this->button( $order->get_edit_order_url(), __( 'Open the order', 'peptide-core' ) );
	}

	public function preview(): void {
		if ( $this->object instanceof \WC_Order ) {
			$this->others = array( $this->object );
		}
	}
}
