<?php
namespace PeptideCore\Payments;

defined( 'ABSPATH' ) || exit;

final class Local_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
	public function __construct( string $id ) { $this->name = $id; }
	public function initialize() {}
	public function is_active() { return Local_Payments::allowed() && in_array( $this->name, Local_Payments::IDS, true ); }
	public function get_payment_method_script_handles() {
		if ( ! $this->is_active() ) { return array(); }
		wp_register_script( 'peptide-core-local-payments', plugins_url( 'assets/local-payments.js', PEPTIDE_CORE_FILE ), array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/local-payments.js' ), true );
		return array( 'peptide-core-local-payments' );
	}
	public function get_payment_method_data() {
		return $this->is_active() ? array( 'enabled' => true, 'title' => Local_Payments::title( $this->name ), 'description' => Local_Payments::description(), 'supports' => array( 'products' ) ) : array();
	}
}
