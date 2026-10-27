<?php
/**
 * 弃单通知（顾客）三个阶段的公共部分（CART-RECOVERY-02），对照 Shopname“弃单通知”标签：
 * 每个阶段一封邮件，有自己的开关、标题、正文、按钮文字、优惠券和延迟发送秒数（从顾客最后一次改动购物车算起）。
 * 默认延迟 300 / 1800 / 86400 秒，和 Shopname 一致。
 *
 * 邮件里有：正文、商品清单、小计、优惠码（可选）、“完成订单”按钮（恢复购物车的链接）、实体地址和退订链接。
 * 发送由 PeptideCore\Recovery 的定时扫描调用 trigger()。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

use PeptideCore\Recovery;

defined( 'ABSPATH' ) || exit;

abstract class Cart_Recovery extends Base {

	public const STAGE = 1;

	/** @var array 弃单记录（Recovery 表的一行）。 */
	public array $record = array();

	abstract protected function defaults(): array;

	public function __construct() {
		$this->id             = 'pc_cart_recovery_' . static::STAGE;
		$this->customer_email = true;
		/* translators: %d: stage number 1-3 */
		$this->title       = sprintf( __( 'Abandoned cart: stage %d', 'peptide-core' ), static::STAGE );
		$this->description = __( 'Sent to customers who entered their email and address at checkout but did not pay. The delay counts from the last change to the cart.', 'peptide-core' );
		$this->placeholders = array( '{customer_email}' => '' );
		parent::__construct();
	}

	public function get_default_subject() {
		return $this->defaults()['subject'];
	}

	public function get_default_heading() {
		return $this->defaults()['heading'];
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$d     = $this->defaults();
		$extra = array(
			'message' => array(
				'title'       => __( 'Message', 'peptide-core' ),
				'type'        => 'textarea',
				'css'         => 'width:400px; height: 90px;',
				'default'     => $d['message'],
				'description' => __( 'Shown above the product list. Keep it factual: no health claims and no fake urgency.', 'peptide-core' ),
				'desc_tip'    => true,
			),
			'button'  => array(
				'title'   => __( 'Button text', 'peptide-core' ),
				'type'    => 'text',
				'default' => 'Complete your order',
			),
			'coupon'  => array(
				'title'       => __( 'Coupon', 'peptide-core' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => __( 'Optional coupon code', 'peptide-core' ),
				'description' => __( 'Shown in the email and applied automatically when the customer comes back through the link (if the cart meets the coupon conditions).', 'peptide-core' ),
				'desc_tip'    => true,
			),
			'delay'   => array(
				'title'             => __( 'Send delay (seconds)', 'peptide-core' ),
				'type'              => 'number',
				'default'           => (string) $d['delay'],
				'custom_attributes' => array( 'min' => '60', 'step' => '60' ),
				'description'       => __( 'Seconds after the customer last changed the cart. The store checks every 5 minutes, so emails go out up to 5 minutes later.', 'peptide-core' ),
				'desc_tip'          => true,
			),
		);
		$fields = array();
		foreach ( $this->form_fields as $k => $f ) {
			$fields[ $k ] = $f;
			if ( 'heading' === $k ) {
				$fields += $extra;
			}
		}
		$this->form_fields = $fields;
	}

	public function delay(): int {
		return max( 60, (int) $this->get_option( 'delay', (string) $this->defaults()['delay'] ) );
	}

	/** 设置里的优惠码（存在时才返回）。 */
	public function coupon(): string {
		$code = wc_format_coupon_code( (string) $this->get_option( 'coupon', '' ) );
		return '' !== $code && wc_get_coupon_id_by_code( $code ) ? $code : '';
	}

	public function get_headers() {
		$headers = parent::get_headers();
		if ( $this->record ) {
			$headers .= 'List-Unsubscribe: <' . Recovery::unsubscribe_url( $this->record ) . ">\r\n";
		}
		return $headers;
	}

	public function trigger( array $record ): bool {
		$this->record                         = $record;
		$this->recipient                      = (string) $record['email'];
		$this->placeholders['{customer_email}'] = (string) $record['email'];
		return $this->deliver();
	}

	protected function body_html(): string {
		$r     = $this->record;
		$items = json_decode( (string) ( $r['cart'] ?? '[]' ), true );
		$html  = $this->paragraphs( (string) $this->get_option( 'message', $this->defaults()['message'] ) );
		$html .= $this->products_table( is_array( $items ) ? $items : array() );
		$html .= '<p style="text-align:right"><strong>' . esc_html__( 'Subtotal', 'peptide-core' ) . ': ' . wp_kses_post( wc_price( (float) ( $r['subtotal'] ?? 0 ) ) ) . '</strong></p>';
		$code  = $this->coupon();
		if ( '' !== $code ) {
			/* translators: %s: coupon code */
			$html .= '<p>' . sprintf( esc_html__( 'Use code %s at checkout.', 'peptide-core' ), '<strong>' . esc_html( strtoupper( $code ) ) . '</strong>' ) . '</p>';
		}
		$html .= $this->button( $r ? Recovery::recover_url( $r ) : home_url( '/' ), (string) $this->get_option( 'button', 'Complete your order' ) );
		/* translators: %s: store name */
		$why   = sprintf( __( 'You are receiving this because you started a checkout at %s.', 'peptide-core' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		return $html . $this->marketing_footer( $why, $r ? Recovery::unsubscribe_url( $r ) : home_url( '/' ) );
	}

	public function preview(): void {
		$items        = self::sample_items();
		$this->record = array(
			'id'       => 0,
			'token'    => str_repeat( '0', 32 ),
			'email'    => 'customer@example.com',
			'cart'     => wp_json_encode( $items ),
			'subtotal' => array_sum( array_column( $items, 'line_subtotal' ) ),
		);
		$this->placeholders['{customer_email}'] = 'customer@example.com';
	}
}
