<?php
/**
 * 弃单通知（管理员）：有顾客填了邮箱和地址却没付款时，延迟一段时间（默认 300 秒）通知管理员，每条弃单一次。
 * 由 PeptideCore\Recovery 的定时扫描调用 trigger()。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

defined( 'ABSPATH' ) || exit;

class Admin_Abandoned extends Admin_Base {

	public array $record = array();

	public function __construct() {
		$this->id          = 'pc_admin_abandoned';
		$this->title       = __( 'Abandoned cart (admin)', 'peptide-core' );
		$this->description = __( 'Tells the admin when a customer entered their email and address at checkout but did not pay, so you can follow up.', 'peptide-core' );
		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}] New abandoned checkout', 'peptide-core' );
	}

	public function get_default_heading() {
		return __( 'New abandoned checkout', 'peptide-core' );
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields['delay'] = array(
			'title'             => __( 'Send delay (seconds)', 'peptide-core' ),
			'type'              => 'number',
			'default'           => '300',
			'custom_attributes' => array( 'min' => '60', 'step' => '60' ),
			'description'       => __( 'Seconds after the customer last changed the cart.', 'peptide-core' ),
			'desc_tip'          => true,
		);
	}

	public function delay(): int {
		return max( 60, (int) $this->get_option( 'delay', '300' ) );
	}

	public function trigger( array $record ): bool {
		$this->record = $record;
		return $this->deliver();
	}

	protected function body_html(): string {
		$r     = $this->record;
		$items = json_decode( (string) ( $r['cart'] ?? '[]' ), true );
		$html  = '<p>' . esc_html__( 'A customer entered their contact details at checkout but has not paid yet. Reminder emails are sent automatically if they are turned on.', 'peptide-core' ) . '</p>';
		$html .= $this->details(
			array(
				__( 'Customer', 'peptide-core' ) => trim( (string) ( $r['name'] ?? '' ) . ' <' . (string) ( $r['email'] ?? '' ) . '>' ),
				__( 'Country', 'peptide-core' )  => (string) ( $r['country'] ?? '' ),
				__( 'Total', 'peptide-core' )    => wp_strip_all_tags( wc_price( (float) ( '' !== (string) ( $r['total'] ?? '' ) ? $r['total'] : ( $r['subtotal'] ?? 0 ) ) ) ),
			)
		);
		$html .= $this->products_table( is_array( $items ) ? $items : array() );
		return $html . $this->button( admin_url( 'admin.php?page=' . \PeptideCore\Admin\Recovery_Page::SLUG ), __( 'View abandoned checkouts', 'peptide-core' ) );
	}

	public function preview(): void {
		$items        = self::sample_items();
		$this->record = array( 'name' => 'John Smith', 'email' => 'customer@example.com', 'country' => 'US', 'cart' => wp_json_encode( $items ), 'subtotal' => array_sum( array_column( $items, 'line_subtotal' ) ), 'total' => '' );
	}
}
