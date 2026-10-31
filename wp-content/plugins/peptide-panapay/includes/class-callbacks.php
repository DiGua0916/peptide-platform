<?php
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Callbacks {
	private static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		if ( headers_sent() ) { return; }
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'X-Content-Type-Options: nosniff', true );
	}

	public static function protect_payment_pages(): void {
		if ( is_wc_endpoint_url( 'order-pay' ) ) { self::no_cache(); }
	}

	private static function fields( array $source, array $keys ): array {
		$result = array();
		foreach ( $keys as $key ) {
			if ( ! isset( $source[ $key ] ) || ! is_string( $source[ $key ] ) || strlen( $source[ $key ] ) > 512 ) { throw new Protocol_Error( 'invalid_request' ); }
			$result[ $key ] = wp_unslash( $source[ $key ] );
		}
		if ( ! in_array( $result['status'] ?? null, array( 'SUCCEEDED', 'FAILED' ), true )
			|| ! preg_match( '/^[\x21-\x7e]{0,512}$/D', $result['transaction_id'] ?? '' )
			|| ( 'SUCCEEDED' === ( $result['status'] ?? '' ) && '' === ( $result['transaction_id'] ?? '' ) ) ) {
			throw new Protocol_Error( 'invalid_request' );
		}
		return $result;
	}

	public static function notify(): void {
		self::no_cache();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		try {
			if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { throw new Protocol_Error( 'method_not_allowed', 405 ); }
			if ( (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 8192 ) { throw new Protocol_Error( 'invalid_request', 413 ); }
			$data = self::fields( $_POST, array( 'order_number', 'status', 'transaction_id', 'sign' ) );
			Session_Service::apply_notification( $data );
			status_header( 200 );
			echo 'ok';
		} catch ( \Throwable $error ) {
			$code = $error instanceof Protocol_Error ? $error->getCode() : 503;
			status_header( in_array( $code, array( 400, 405, 409, 413, 503 ), true ) ? $code : 503 );
			if ( 503 === $code ) { header( 'Retry-After: 5' ); }
			echo 'notification_not_accepted';
		}
		exit;
	}

	public static function returned(): void {
		self::no_cache();
		try {
			if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { throw new Protocol_Error( 'method_not_allowed', 405 ); }
			$data = self::fields( $_GET, array( 'reference', 'token', 'status', 'transaction_id', 'sign' ) );
			$order = Session_Service::returned_order( $data );
			if ( $order->is_paid() && Persistence::is_paid( $order->get_id(), (string) $order->get_transaction_id() ) ) {
				wp_safe_redirect( $order->get_checkout_order_received_url() );
				exit;
			}
			if ( $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
				self::status_page( __( 'Please contact the store', 'peptide-panapay' ), __( 'This order is no longer awaiting payment. The store can help you reconcile the payment status.', 'peptide-panapay' ), $order->get_checkout_payment_url( false ), __( 'View order', 'peptide-panapay' ) );
				exit;
			}
			if ( $order->has_status( 'failed' ) ) {
				self::status_page( __( 'Payment was not completed', 'peptide-panapay' ),
					__( 'This order is currently unpaid. You can return to your order to try again or contact the store for help.', 'peptide-panapay' ),
					$order->get_checkout_payment_url( false ), __( 'Return to payment', 'peptide-panapay' ) );
				exit;
			}
			if ( $order->has_status( 'on-hold' ) ) {
				self::status_page( __( 'Payment needs review', 'peptide-panapay' ),
					__( 'The store is checking this order. Please contact the store before making another payment.', 'peptide-panapay' ),
					home_url( '/' ), __( 'Return to store', 'peptide-panapay' ) );
				exit;
			}
			$url = add_query_arg( $data, WC()->api_request_url( 'pc_panapay_return' ) );
			self::status_page( __( 'Confirming your payment', 'peptide-panapay' ),
				__( 'We are waiting for confirmation from the payment provider. Please check the status before making another payment. Your order is updated only after the store verifies the server notification.', 'peptide-panapay' ),
				$url, __( 'Check payment status', 'peptide-panapay' ) );
		} catch ( \Throwable $error ) {
			status_header( 400 );
			self::status_page( __( 'Unable to verify this return', 'peptide-panapay' ), __( 'Please open your order from the store or contact the store to check the payment. Do not pay again until the status is confirmed.', 'peptide-panapay' ), home_url( '/' ), __( 'Return to store', 'peptide-panapay' ) );
		}
		exit;
	}

	private static function status_page( string $title, string $message, string $url, string $label ): void {
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta charset="utf-8"><title>' . esc_html( $title ) . '</title></head><body><main style="max-width:40rem;margin:12vh auto;padding:24px;font:16px/1.6 system-ui,sans-serif;color:#171717"><h1 style="font-size:28px;line-height:1.25">' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p><p><a style="color:inherit;text-underline-offset:4px" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p></main></body></html>';
	}

	public static function receipt( $order_id ): void {
		self::no_cache();
		$order = wc_get_order( $order_id );
		$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) || Gateway::ID !== $order->get_payment_method() ) {
			echo '<p>' . esc_html__( 'Unable to open this payment. Please open your order from checkout.', 'peptide-panapay' ) . '</p>';
			return;
		}
		if ( $order->get_customer_id() && get_current_user_id() !== $order->get_customer_id() && ! current_user_can( 'manage_woocommerce' ) ) {
			echo '<p><a href="' . esc_url( wp_login_url( $order->get_checkout_payment_url( true ) ) ) . '">' . esc_html__( 'Sign in to open this payment.', 'peptide-panapay' ) . '</a></p>';
			return;
		}
		if ( $order->is_paid() ) {
			echo '<p><a href="' . esc_url( $order->get_checkout_order_received_url() ) . '">' . esc_html__( 'View order', 'peptide-panapay' ) . '</a></p>';
			return;
		}
		if ( ! $order->has_status( array( 'pending', 'failed' ) ) || ! $order->needs_payment() ) {
			echo '<p>' . esc_html__( 'This order is not awaiting payment. Please contact the store if you need help.', 'peptide-panapay' ) . '</p>';
			return;
		}
		try {
			$session = Session_Service::active_session( $order );
			if ( 'iframe' === $session['type'] ) {
				echo '<iframe title="' . esc_attr__( 'Secure payment', 'peptide-panapay' ) . '" src="' . esc_url( $session['url'] ) . '" style="display:block;width:100%;min-height:480px;border:0" referrerpolicy="no-referrer" allow="payment"></iframe>';
			}
			echo '<p><a href="' . esc_url( $session['url'] ) . '" rel="noreferrer">' . esc_html__( 'Open the secure payment page', 'peptide-panapay' ) . '</a></p>';
			echo '<p>' . esc_html__( 'Payment confirmation may take a moment after you return. Card details are entered on the payment provider’s page.', 'peptide-panapay' ) . '</p>';
		} catch ( \Throwable $error ) {
			echo '<p>' . esc_html__( 'The secure payment page is currently unavailable. Please contact the store before paying again.', 'peptide-panapay' ) . '</p>';
		}
	}
}
