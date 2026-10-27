<?php
/**
 * 购物发现（顾客）：对照 Shopname“购物发现”标签。新注册的顾客在一段时间内（默认 1800 秒）没有下单时，
 * 发一封邮件，列出他最近加购或浏览过的商品。没有可列出的商品时不发。每位顾客只发一次。
 * 属于营销邮件：页脚有实体地址和退订链接（和弃单提醒共用退订名单）。触发见 Registry::browse_check()。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

defined( 'ABSPATH' ) || exit;

class Browse_Reminder extends Base {

	/** @var array[] 商品 {product_id, variation_id, quantity?} */
	public array $items = array();

	public string $unsubscribe = '';

	public function __construct() {
		$this->id             = 'pc_browse_reminder';
		$this->customer_email = true;
		$this->title          = __( 'Shopping discovery', 'peptide-core' );
		$this->description    = __( 'Sent to newly registered customers who have not ordered after the delay, with the products they recently added to the cart or viewed.', 'peptide-core' );
		parent::__construct();
	}

	public function get_default_subject() {
		return "Don't miss out! Discover more with {site_title}";
	}

	public function get_default_heading() {
		return "Don't miss out";
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$extra  = array(
			'message' => array(
				'title'   => __( 'Message', 'peptide-core' ),
				'type'    => 'textarea',
				'css'     => 'width:400px; height: 90px;',
				'default' => 'We noticed your interest in our store and the products you looked at. Here they are again, in case you want to take another look.',
			),
			'coupon'  => array(
				'title'       => __( 'Coupon', 'peptide-core' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => __( 'Optional coupon code', 'peptide-core' ),
			),
			'delay'   => array(
				'title'             => __( 'Send delay (seconds)', 'peptide-core' ),
				'type'              => 'number',
				'default'           => '1800',
				'custom_attributes' => array( 'min' => '300', 'step' => '60' ),
				'description'       => __( 'Seconds after the customer registered.', 'peptide-core' ),
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
		return max( 300, (int) $this->get_option( 'delay', '1800' ) );
	}

	public function coupon(): string {
		$code = wc_format_coupon_code( (string) $this->get_option( 'coupon', '' ) );
		return '' !== $code && wc_get_coupon_id_by_code( $code ) ? $code : '';
	}

	public function get_headers() {
		return parent::get_headers() . ( $this->unsubscribe ? 'List-Unsubscribe: <' . $this->unsubscribe . ">\r\n" : '' );
	}

	public function trigger( string $email, array $items, string $unsubscribe ): bool {
		$this->recipient   = $email;
		$this->items       = $items;
		$this->unsubscribe = $unsubscribe;
		return $this->deliver();
	}

	protected function body_html(): string {
		$html = $this->paragraphs( (string) $this->get_option( 'message', '' ) );
		$html .= $this->products_table( $this->items );
		$code  = $this->coupon();
		if ( '' !== $code ) {
			/* translators: %s: coupon code */
			$html .= '<p>' . sprintf( esc_html__( 'Use code %s at checkout.', 'peptide-core' ), '<strong>' . esc_html( strtoupper( $code ) ) . '</strong>' ) . '</p>';
		}
		$html .= $this->button( wc_get_page_permalink( 'shop' ), __( 'Continue shopping', 'peptide-core' ) );
		/* translators: %s: store name */
		$why = sprintf( __( 'You are receiving this because you created an account at %s.', 'peptide-core' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		return $html . $this->marketing_footer( $why, $this->unsubscribe ? $this->unsubscribe : home_url( '/' ) );
	}

	public function preview(): void {
		$this->items = array_map( static fn( $i ) => array_diff_key( $i, array( 'quantity' => 1, 'line_subtotal' => 1 ) ), self::sample_items() );
	}
}
