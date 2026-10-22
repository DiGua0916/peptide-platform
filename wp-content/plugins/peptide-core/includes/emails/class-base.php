<?php
/**
 * 插件自己的 WooCommerce 邮件的公共部分（EMAIL-CENTER-01）。
 *
 * 继承 WC_Email，所以和 WooCommerce 原有邮件一样：在“WooCommerce 设置 → 邮件”里有开关和编辑页，用同一套页眉页脚和颜色，
 * 支持预览（woocommerce_prepare_email_for_preview 时调用 preview() 填示例数据），发送后触发 woocommerce_email_sent。
 * 正文由 body_html() 生成；营销类邮件（弃单提醒、购物发现）在页脚加实体地址和退订链接（美国 CAN-SPAM）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

use PeptideCore\Store_Config;

defined( 'ABSPATH' ) || exit;

abstract class Base extends \WC_Email {

	/** 正文 HTML（放在页眉和页脚之间）。 */
	abstract protected function body_html(): string;

	/** 预览时填入示例数据。 */
	abstract public function preview(): void;

	/** 默认是否开启（子类覆盖）。 */
	protected function default_enabled(): string {
		return 'yes';
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields['enabled']['default'] = $this->default_enabled();
	}

	public function get_content_html() {
		ob_start();
		do_action( 'woocommerce_email_header', $this->get_heading(), $this );
		echo $this->body_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 各子类已转义。
		$extra = $this->get_additional_content();
		if ( $extra ) {
			echo wp_kses_post( wpautop( wptexturize( $extra ) ) );
		}
		do_action( 'woocommerce_email_footer', $this );
		return (string) ob_get_clean();
	}

	public function get_content_plain() {
		$html = preg_replace( '/<(br|\/p|\/tr|\/h\d|\/div)[^>]*>/i', "\n", $this->body_html() );
		$text = trim( html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' ) );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
		$extra = $this->get_additional_content();
		return $text . ( $extra ? "\n\n" . wp_strip_all_tags( wptexturize( $extra ) ) : '' );
	}

	/** 发送：只在开启且有收件人时发，返回是否发出。 */
	protected function deliver(): bool {
		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}
		$this->setup_locale();
		$ok = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		$this->restore_locale();
		return $ok;
	}

	// ---------- 片段 ----------

	protected function paragraphs( string $text ): string {
		return wp_kses_post( wpautop( wptexturize( esc_html( $this->format_string( $text ) ) ) ) );
	}

	protected function button( string $url, string $label ): string {
		$color = (string) get_option( 'woocommerce_email_base_color', '#177e9b' );
		return '<p style="margin:24px 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 24px;background:' . esc_attr( $color ? $color : '#177e9b' ) . ';color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * 商品清单。
	 *
	 * @param array[] $items 每项 {product_id, variation_id, quantity, line_subtotal?}
	 */
	protected function products_table( array $items, bool $prices = true ): string {
		$rows = '';
		foreach ( $items as $i ) {
			$product = wc_get_product( ! empty( $i['variation_id'] ) ? (int) $i['variation_id'] : (int) $i['product_id'] );
			if ( ! $product ) {
				continue;
			}
			$image = wp_get_attachment_image_src( (int) $product->get_image_id(), 'thumbnail' );
			$qty   = isset( $i['quantity'] ) ? ' &times; ' . esc_html( (string) wc_stock_amount( $i['quantity'] ) ) : '';
			$price = $prices ? wp_kses_post( wc_price( isset( $i['line_subtotal'] ) ? (float) $i['line_subtotal'] : (float) $product->get_price() ) ) : '';
			$rows .= '<tr><td style="padding:8px 0;width:64px">' . ( $image ? '<img src="' . esc_url( $image[0] ) . '" alt="" width="56" height="56" style="display:block;border-radius:4px">' : '' ) . '</td>'
				. '<td style="padding:8px"><a href="' . esc_url( $product->get_permalink() ) . '" style="color:inherit;text-decoration:none">' . esc_html( $product->get_name() ) . '</a>' . $qty . '</td>'
				. '<td style="padding:8px 0;text-align:right;white-space:nowrap">' . $price . '</td></tr>';
		}
		return $rows ? '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:16px 0">' . $rows . '</table>' : '';
	}

	/** 营销邮件页脚：为什么收到、实体地址、退订。 */
	protected function marketing_footer( string $why, string $unsubscribe_url ): string {
		$company = (string) Store_Config::get( 'company_name' );
		$address = Store_Config::address_line();
		$line    = trim( ( $company ? $company . ', ' : '' ) . $address, ', ' );
		return '<p style="font-size:12px;color:#6b7280;margin-top:24px">' . esc_html( $why ) . ( $line ? '<br>' . esc_html( $line ) : '' ) . '<br><a href="' . esc_url( $unsubscribe_url ) . '" style="color:#6b7280">' . esc_html__( 'Unsubscribe from these emails', 'peptide-core' ) . '</a></p>';
	}

	/** 预览用的示例商品（店里前两个已发布、有价格的商品）。 */
	protected static function sample_items(): array {
		$out = array();
		foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => 6, 'orderby' => 'date', 'order' => 'DESC' ) ) as $p ) {
			if ( (float) $p->get_price() > 0 ) {
				$out[] = array( 'product_id' => $p->get_id(), 'variation_id' => 0, 'variation' => array(), 'quantity' => 1, 'line_subtotal' => (float) $p->get_price() );
			}
			if ( count( $out ) >= 2 ) {
				break;
			}
		}
		return $out;
	}
}
