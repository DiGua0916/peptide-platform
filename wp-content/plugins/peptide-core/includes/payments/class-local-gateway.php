<?php
namespace PeptideCore\Payments;

defined( 'ABSPATH' ) || exit;

final class Local_Gateway extends \WC_Payment_Gateway {
	public function __construct( string $id = 'pc_local_card' ) {
		if ( ! in_array( $id, Local_Payments::IDS, true ) ) { throw new \InvalidArgumentException( 'Unknown local payment channel.' ); }
		$this->id = $id;
		$this->enabled = 'yes';
		$this->has_fields = false;
		$this->supports = array( 'products' );
		$this->title = Local_Payments::title( $id );
		$this->method_title = $this->title;
		$this->description = Local_Payments::description();
		$this->method_description = $this->description;
	}

	public function is_available() {
		return Local_Payments::allowed() && parent::is_available();
	}

	public function process_payment( $order_id ) {
		$failure = array( 'result' => 'failure', 'messages' => __( 'Local payment is unavailable for this order.', 'peptide-core' ) );
		if ( ! Local_Payments::allowed() ) { return $failure; }
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order->get_payment_method() !== $this->id ) { return $failure; }
		if ( $order->is_paid() ) {
			return 'yes' === $order->get_meta( '_pc_payment_simulated' ) && $this->id === $order->get_meta( '_pc_payment_simulated_channel' ) ? $this->success( $order ) : $failure;
		}
		if ( ! $order->has_status( array( 'pending', 'failed' ) ) || ! $order->needs_payment() ) { return $failure; }
		$order->update_meta_data( '_pc_payment_simulated', 'yes' );
		$order->update_meta_data( '_pc_payment_simulated_channel', $this->id );
		$order->set_payment_method_title( $this->title );
		$order->save();
		$order->add_order_note( __( 'Local simulation completed; no money was charged.', 'peptide-core' ) );
		$order->payment_complete( 'pc-local-' . $order->get_id() );
		if ( ! $order->is_paid() ) { return $failure; }
		if ( WC()->cart instanceof \WC_Cart ) { WC()->cart->empty_cart(); }
		return $this->success( $order );
	}

	private function success( \WC_Order $order ): array {
		return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
	}
}
