<?php
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Admin {
	public static function order_details( $order ): void {
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'edit_shop_orders' ) ) { return; }
		try {
			$details = Session_Service::admin_details( $order );
			if ( ! $details ) { return; }
			echo '<p class="form-field form-field-wide"><strong>Panapay</strong><br>';
			echo esc_html__( 'Merchant reference', 'peptide-panapay' ) . ': <code>' . esc_html( $details['reference'] ) . '</code><br>';
			echo esc_html__( 'Session state', 'peptide-panapay' ) . ': ' . esc_html( $details['state'] );
			if ( '' !== $details['transaction'] ) {
				echo '<br>' . esc_html__( 'Provider transaction', 'peptide-panapay' ) . ': <code>' . esc_html( $details['transaction'] ) . '</code>';
			}
			echo '</p>';
			foreach ( Store::admin_transactions( $order->get_id() ) as $record ) {
				if ( 'review' !== $record['state'] && 'processing' !== $record['state'] ) { continue; }
				echo '<p class="form-field form-field-wide">' . esc_html__( 'Payment requiring reconciliation', 'peptide-panapay' )
					. ': <code>' . esc_html( $record['transaction'] ) . '</code> (' . esc_html( $record['state'] ) . ')</p>';
			}
			global $wpdb;
			$table = Store::table();
			$unresolved = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND state IN ('processing','review')", $order->get_id() ) );
			if ( (int) $unresolved > 0 ) {
				echo '<p class="form-field form-field-wide">' . esc_html__( 'Payment reconciliation is required. Check the merchant reference in the provider portal before completing or refunding this order. Automatic query and refund APIs have not been supplied.', 'peptide-panapay' ) . '</p>';
			}
		} catch ( \Throwable $error ) {
			echo '<p class="form-field form-field-wide">' . esc_html__( 'Panapay payment details are unavailable. Check the server configuration before changing the payment status.', 'peptide-panapay' ) . '</p>';
		}
	}
}
