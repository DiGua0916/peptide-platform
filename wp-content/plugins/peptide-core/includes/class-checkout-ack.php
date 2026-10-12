<?php
/**
 * 结账确认项（BE-05）。设置中启用并填写文案后，在 WooCommerce 区块结账页加一个必选复选框。
 *
 * - 使用 WooCommerce 官方的 Additional Checkout Fields API：前端由结账区块自动渲染，
 *   服务端（Store API）自动校验必选，未勾选时下单失败。
 * - 勾选后在订单上另存一份快照 _pc_checkout_ack：当时的确认文案和勾选时间（UTC），以后修改文案也不影响旧订单。
 * - 只支持区块结账。若改用经典结账短代码，这个复选框不会出现（需要另行适配）。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Checkout_Ack {

	public const FIELD_ID      = 'peptide-core/checkout-ack';
	public const SNAPSHOT_META = '_pc_checkout_ack';

	public static function register_hooks(): void {
		add_action( 'woocommerce_init', array( self::class, 'register_field' ) );
		add_action( 'woocommerce_set_additional_field_value', array( self::class, 'save_snapshot' ), 10, 4 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'render_admin' ) );
	}

	public static function register_field(): void {
		$label = Settings::checkout_ack_label();
		if ( null === $label || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'            => self::FIELD_ID,
				'label'         => $label,
				'location'      => 'order',
				'type'          => 'checkbox',
				'required'      => true,
				'error_message' => __( 'Please tick the confirmation box before placing your order.', 'peptide-core' ),
			)
		);
	}

	/**
	 * @param mixed $value
	 * @param mixed $wc_object
	 */
	public static function save_snapshot( string $key, $value, string $group, $wc_object ): void {
		if ( self::FIELD_ID !== $key || ! $wc_object instanceof \WC_Order || ! rest_sanitize_boolean( $value ) ) {
			return;
		}
		$wc_object->update_meta_data(
			self::SNAPSHOT_META,
			array(
				'label'       => (string) Settings::checkout_ack_label(),
				'accepted_at' => gmdate( 'c' ),
			)
		);
	}

	public static function render_admin( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$snap = $order->get_meta( self::SNAPSHOT_META );
		if ( ! is_array( $snap ) || empty( $snap['label'] ) ) {
			return;
		}
		echo '<p class="form-field form-field-wide pc-checkout-ack"><strong>' . esc_html__( 'Checkout confirmation', 'peptide-core' ) . '</strong><br>';
		echo esc_html( $snap['label'] ) . '<br>';
		/* translators: %s: date and time */
		echo '<small>' . esc_html( sprintf( __( 'Accepted at %s (UTC)', 'peptide-core' ), (string) $snap['accepted_at'] ) ) . '</small></p>';
	}
}
