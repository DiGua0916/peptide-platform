<?php
/**
 * 注册邮箱验证：系统设置 → 基础配置开启“注册邮箱验证”后，顾客在账户页注册时收到这封邮件，打开链接才能登录。
 * 链接由 Store_Config::send_verification() 生成（3 天有效），通过动作 peptide_core_send_registration_verification 交给这里发送。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

defined( 'ABSPATH' ) || exit;

class Register_Verify extends Base {

	public string $verify_url = '';

	public function __construct() {
		$this->id             = 'pc_register_verify';
		$this->customer_email = true;
		$this->title          = __( 'Registration email verification', 'peptide-core' );
		$this->description    = __( 'Sent when “Register email verification” is on in Basic settings. The customer must open the link before signing in.', 'peptide-core' );
		parent::__construct();
	}

	public function get_default_subject() {
		return 'Verify your email for {site_title}';
	}

	public function get_default_heading() {
		return 'Verify your email';
	}

	public function trigger( int $user_id, string $url ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$this->object     = $user;
		$this->recipient  = (string) $user->user_email;
		$this->verify_url = $url;
		return $this->deliver();
	}

	protected function body_html(): string {
		$html  = '<p>' . esc_html__( 'Welcome! Please confirm your email address to activate your account. The link is valid for 3 days.', 'peptide-core' ) . '</p>';
		$html .= $this->button( $this->verify_url ? $this->verify_url : home_url( '/' ), __( 'Verify my email', 'peptide-core' ) );
		return $html . '<p style="font-size:12px;color:#6b7280">' . esc_html__( 'If you did not create this account, you can ignore this email.', 'peptide-core' ) . '</p>';
	}

	public function preview(): void {
		$this->verify_url = home_url( '/' );
	}
}
