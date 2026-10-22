<?php
/**
 * 顾客注册邀请：游客下单付款后，如果这个邮箱还没有账户、账户页又开放注册，邀请他注册（每个邮箱一次）。
 * 账户页注册关闭时不发。触发见 Registry::on_paid()。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

defined( 'ABSPATH' ) || exit;

class Register_Invite extends Base {

	public function __construct() {
		$this->id             = 'pc_register_invite';
		$this->customer_email = true;
		$this->title          = __( 'Registration invitation', 'peptide-core' );
		$this->description    = __( 'Sent once to guests after a paid order when their email has no account yet. Only sent while registration on the account page is open.', 'peptide-core' );
		parent::__construct();
	}

	public function get_default_subject() {
		return 'Join {site_title} and keep track of your orders';
	}

	public function get_default_heading() {
		return 'Join {site_title}';
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields['message'] = array(
			'title'   => __( 'Message', 'peptide-core' ),
			'type'    => 'textarea',
			'css'     => 'width:400px; height: 90px;',
			'default' => 'Thank you for your order. Create an account with the same email to see your order history, track shipments and check out faster next time.',
		);
	}

	public function trigger( string $email ): bool {
		if ( 'yes' !== get_option( 'woocommerce_enable_myaccount_registration' ) ) {
			return false;
		}
		$this->recipient = $email;
		return $this->deliver();
	}

	protected function body_html(): string {
		return $this->paragraphs( (string) $this->get_option( 'message', '' ) ) . $this->button( wc_get_page_permalink( 'myaccount' ), __( 'Create an account', 'peptide-core' ) );
	}

	public function preview(): void {}
}
