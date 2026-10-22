<?php
/**
 * 管理员通知的公共部分（EMAIL-CENTER-01）：收件人设置、默认关闭、简单的“标签：内容”表格。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

defined( 'ABSPATH' ) || exit;

abstract class Admin_Base extends Base {

	public function __construct() {
		$this->customer_email = false;
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', $this->default_recipient() );
	}

	protected function default_enabled(): string {
		return 'no';
	}

	protected function default_recipient(): string {
		return (string) get_option( 'admin_email' );
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$fields = array();
		foreach ( $this->form_fields as $k => $f ) {
			$fields[ $k ] = $f;
			if ( 'enabled' === $k ) {
				$fields['recipient'] = array(
					'title'       => __( 'Recipient(s)', 'peptide-core' ),
					'type'        => 'text',
					/* translators: %s: default address */
					'description' => sprintf( __( 'Separate several addresses with commas. Defaults to %s.', 'peptide-core' ), '<code>' . esc_html( $this->default_recipient() ) . '</code>' ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => true,
				);
			}
		}
		$this->form_fields = $fields;
	}

	public function get_recipient() {
		$this->recipient = $this->get_option( 'recipient', '' ) ? $this->get_option( 'recipient' ) : $this->default_recipient();
		return parent::get_recipient();
	}

	/** @param array<string,string> $rows 标签 => 内容（纯文本）。 */
	protected function details( array $rows ): string {
		$html = '<table cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;margin:12px 0;border:1px solid #e5e7eb">';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th style="text-align:left;background:#f9fafb;border:1px solid #e5e7eb;width:35%">' . esc_html( $label ) . '</th><td style="border:1px solid #e5e7eb">' . esc_html( $value ) . '</td></tr>';
		}
		return $html . '</table>';
	}
}
