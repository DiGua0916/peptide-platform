<?php
/**
 * Native WooCommerce Blocks presentation for the hosted Panapay gateway.
 *
 * Checkout and payment processing remain owned by WooCommerce and Gateway.
 */
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Blocks extends AbstractPaymentMethodType {

	protected $name = 'pc_panapay';

	/** @var Gateway|null */
	private $gateway = null;

	public function initialize() {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways[ $this->name ] ?? null;
		$this->gateway = $gateway instanceof Gateway ? $gateway : null;
	}

	public function is_active() {
		return $this->gateway instanceof Gateway
			&& 'yes' === $this->gateway->enabled
			&& $this->gateway->is_available();
	}

	public function get_payment_method_script_handles() {
		if ( ! $this->is_active() ) {
			return array();
		}
		wp_register_script(
			'peptide-panapay-checkout',
			plugins_url( 'assets/checkout.js', PEPTIDE_PANAPAY_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
			PEPTIDE_PANAPAY_VERSION,
			true
		);
		return array( 'peptide-panapay-checkout' );
	}

	public function get_supported_features() {
		// Refunds and other provider capabilities are not part of this API contract.
		return array( 'products' );
	}

	public function get_payment_method_data() {
		if ( ! $this->is_active() ) {
			return array();
		}
		// This explicit DTO must never include gateway settings or order data.
		return array(
			'title'       => $this->text( $this->gateway->get_title() ),
			'description' => $this->text( $this->gateway->get_description() ),
			'test_mode'   => $this->gateway->is_test_mode(),
			'supports'    => $this->get_supported_features(),
		);
	}

	private function text( $value ): string {
		return html_entity_decode( wp_strip_all_tags( (string) $value, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
