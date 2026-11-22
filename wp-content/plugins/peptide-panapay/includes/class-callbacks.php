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
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			self::no_cache();
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			if ( $order instanceof \WC_Order ) {
				$gateway = Session_Service::bound_gateway( $order );
				if ( null !== $gateway ) { add_action( 'woocommerce_receipt_' . $gateway, array( self::class, 'receipt' ) ); }
			}
		}
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
			if ( array_key_exists( 'check', $_GET ) ) {
				// This is an HQ-authenticated read, not an additional provider result.
				// Do not extend fields(): the notification route still accepts only
				// the documented, provider-signed success/failure result.
				if ( array_key_exists( 'status', $_GET ) || array_key_exists( 'transaction_id', $_GET ) ) { throw new Protocol_Error( 'invalid_return' ); }
				$data = array();
				foreach ( array( 'reference', 'token', 'check', 'sign' ) as $key ) {
					if ( ! is_string( $_GET[ $key ] ?? null ) || strlen( $_GET[ $key ] ) > 512 ) { throw new Protocol_Error( 'invalid_return' ); }
					$data[ $key ] = wp_unslash( $_GET[ $key ] );
				}
			} else {
				$data = self::fields( $_GET, array( 'reference', 'token', 'status', 'transaction_id', 'sign' ) );
			}
			$order = Session_Service::returned_order( $data );
			$state = Session_Service::return_status( $order, $data['reference'] );
			// 2026-11-10：付款已经落库（订单已付款、流水号一致），但完成流程里的记账没走完时，状态会是 review。
			// 顾客的钱已经付了，给顾客看“付款成功”；对账问题留给店铺后台处理。
			if ( 'review' === $state && $order->is_paid() && '' !== (string) $order->get_transaction_id()
				&& Persistence::is_paid( $order->get_id(), (string) $order->get_transaction_id() ) ) {
				$state = 'paid';
			}
			if ( 'paid' === $state ) {
				self::clear_verified_return_cart( $order, $data['reference'] );
				// 店铺可以接管付款成功后的去处（peptide-core 的付款成功弹窗：回到首页弹出下单成功）。
				$to = (string) apply_filters( 'pc_panapay_paid_redirect', '', $order );
				if ( '' !== $to ) {
					wp_safe_redirect( $to );
					exit;
				}
				// Woo's order-received shortcode can empty a later, unrelated cart.
				// Keep this authenticated result here; never route through that effect.
				self::status_page( __( 'Payment confirmed', 'peptide-panapay' ),
					__( 'Your payment has been confirmed and your order has been received.', 'peptide-panapay' ),
					home_url( '/' ), __( 'Continue shopping', 'peptide-panapay' ), $order );
				exit;
			}
			if ( 'failed' === $state ) {
				self::status_page( __( 'Payment was not completed', 'peptide-panapay' ),
					__( 'The payment provider confirmed that this payment was not completed. Your order is kept. You can return to checkout to place a new order or contact the store.', 'peptide-panapay' ),
					wc_get_checkout_url(), __( 'Return to checkout', 'peptide-panapay' ), $order );
				exit;
			}
			if ( 'review' === $state ) {
				self::status_page( __( 'Payment needs review', 'peptide-panapay' ),
					__( 'The store is checking this order. Please contact the store before making another payment.', 'peptide-panapay' ),
					home_url( '/' ), __( 'Return to store', 'peptide-panapay' ), $order );
				exit;
			}
			$url = Order_Payload::customer_url( add_query_arg( $data, WC()->api_request_url( 'pc_panapay_return' ) ) );
			self::status_page( __( 'Confirming your payment', 'peptide-panapay' ),
				__( 'We are waiting for confirmation from the payment provider. Please check the status before making another payment. Your order is updated only after the store verifies the server notification.', 'peptide-panapay' ),
				$url, __( 'Check payment status', 'peptide-panapay' ), $order );
		} catch ( \Throwable $error ) {
			status_header( 400 );
			self::status_page( __( 'Unable to verify this return', 'peptide-panapay' ), __( 'Please open your order from the store or contact the store to check the payment. Do not pay again until the status is confirmed.', 'peptide-panapay' ), home_url( '/' ), __( 'Return to store', 'peptide-panapay' ) );
		}
		exit;
	}

	/** No session creation, no guessed ownership and no clearing a later cart. */
	public static function clear_verified_return_cart( \WC_Order $order, string $reference ): bool {
		return Return_Cart::clear( $order, $reference );
	}

	public static function whatsapp_rescue_url( ?\WC_Order $order ): string {
		$base = '';
		if ( class_exists( '\\PeptideCore\\Emails\\Chase_Copy' ) ) {
			$base = \PeptideCore\Emails\Chase_Copy::whatsapp_link();
		}
		if ( '' === $base ) {
			return '';
		}
		if ( ! $order instanceof \WC_Order ) {
			return $base;
		}
		$items = array();
		foreach ( array_slice( array_values( $order->get_items() ), 0, 8 ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$items[] = $item->get_name() . ' x' . $item->get_quantity();
			}
		}
		$total = html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' );
		$loc   = trim( implode( ' ', array_filter( array( $order->get_shipping_city() ?: $order->get_billing_city(), $order->get_shipping_state() ?: $order->get_billing_state(), $order->get_shipping_postcode() ?: $order->get_billing_postcode() ) ) ) );
		$lines = array_filter(
			array(
				'Hi, my payment did not go through for Order #' . $order->get_order_number() . ':',
				$items ? '• Items: ' . implode( ', ', $items ) : '',
				'• Order total: ' . $total,
				'' !== $loc ? '• Ship to: ' . $loc : '',
				'Please help me complete this order with an alternative payment link.',
			)
		);
		return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . 'text=' . rawurlencode( implode( "\n", $lines ) );
	}

	public static function status_page( string $title, string $message, string $url, string $label, ?\WC_Order $order = null ): void {
		header( 'Content-Type: text/html; charset=UTF-8' );
		$is_paid = $order instanceof \WC_Order && $order->is_paid();
		$wa_url  = ! $is_paid ? self::whatsapp_rescue_url( $order ) : '';
		$brand   = class_exists( '\\PeptideCore\\Emails\\Chase_Copy' ) ? \PeptideCore\Emails\Chase_Copy::brand() : 'VialCove';
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow,noarchive"><title>' . esc_html( $title ) . '</title>';
		echo '<style>body{margin:0;background:#f4f7f8;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#102a32}main{max-width:34rem;margin:7vh auto;padding:0 16px}.card{background:#fff;border:1px solid #d5e2e5;border-radius:18px;padding:28px 24px;box-shadow:0 12px 32px rgba(15,40,50,.08)}.brand{font-weight:750;font-size:18px;color:#0e7484;margin-bottom:14px}.badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12.5px;font-weight:700;background:' . ( $is_paid ? '#dcfce7;color:#166534' : '#fef3c7;color:#92400e' ) . ';margin-bottom:10px}h1{margin:0 0 10px;font-size:24px;line-height:1.25}p{margin:0 0 14px;color:#355059;font-size:15px}.ord{padding:10px 12px;border-radius:10px;background:#f7f9fa;border:1px solid #e2ecef;font-size:14px;color:#102a32;margin-bottom:18px}.wa{display:block;text-align:center;padding:14px 18px;border-radius:10px;background:#16a34a;color:#fff;font-weight:700;text-decoration:none;margin-bottom:10px}.wa:hover{background:#15803d}.sec{display:block;text-align:center;padding:12px 16px;border-radius:10px;border:1px solid #c5d7dc;color:#102a32;font-weight:650;text-decoration:none;background:#fff}</style></head><body><main><div class="card">';
		echo '<div class="brand">' . esc_html( $brand ) . '</div>';
		if ( ! $is_paid ) {
			echo '<span class="badge">' . esc_html__( 'Not Charged · Order Details Reserved', 'peptide-panapay' ) . '</span>';
		}
		echo '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p>';
		if ( $order ) {
			$total = html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' );
			echo '<div class="ord">' . esc_html__( 'Order number:', 'peptide-panapay' ) . ' <strong>#' . esc_html( $order->get_order_number() ) . '</strong> · <strong>' . esc_html( $total ) . '</strong></div>';
		}
		if ( '' !== $wa_url ) {
			echo '<p style="font-size:14px;color:#1e5635;font-weight:600">' . esc_html__( 'Need an instant alternative payment link? Tap below to complete your saved order directly with our specialist on WhatsApp:', 'peptide-panapay' ) . '</p>';
			echo '<a class="wa" href="' . esc_url( $wa_url ) . '" target="_blank" rel="noopener noreferrer">💬 ' . esc_html__( 'Complete My Order on WhatsApp Now →', 'peptide-panapay' ) . '</a>';
		}
		echo '<a class="' . ( '' !== $wa_url ? 'sec' : 'wa' ) . '" href="' . esc_url( $url ) . '" rel="noreferrer">' . esc_html( $label ) . '</a>';
		echo '</div></main></body></html>';
	}

	public static function receipt( $order_id ): void {
		self::no_cache();
		$order = wc_get_order( $order_id );
		$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key )
			|| Session_Service::bound_gateway( $order ) !== $order->get_payment_method() ) {
			echo '<p>' . esc_html__( 'Unable to open this payment. Please open your order from checkout.', 'peptide-panapay' ) . '</p>';
			return;
		}
		if ( $order->get_customer_id() && get_current_user_id() !== $order->get_customer_id() && ! current_user_can( 'manage_woocommerce' ) ) {
			echo '<p><a href="' . esc_url( wp_login_url( $order->get_checkout_payment_url( true ) ) ) . '">' . esc_html__( 'Sign in to open this payment.', 'peptide-panapay' ) . '</a></p>';
			return;
		}
		if ( $order->is_paid() ) {
			$confirmed = false;
			try {
				$book = $order->get_meta( '_pc_panapay_sessions', true );
				$reference = is_array( $book ) && is_string( $book['active'] ?? null )
					? ( $book['attempts'][ $book['active'] ]['reference'] ?? null ) : null;
				$confirmed = is_string( $reference ) && 'paid' === Session_Service::return_status( $order, $reference );
				if ( $confirmed ) { self::clear_verified_return_cart( $order, $reference ); }
			} catch ( \Throwable $ignored ) { /* A paid status alone does not confirm this attempt. */ }
			echo '<p>' . ( $confirmed
				? esc_html__( 'Your payment has been confirmed and your order has been received.', 'peptide-panapay' )
				: esc_html__( 'The store is checking this order. Please contact the store before making another payment.', 'peptide-panapay' ) ) . '</p>';
			echo '<p><a href="' . esc_url( home_url( '/' ) ) . '" rel="noreferrer">' . esc_html__( 'Continue shopping', 'peptide-panapay' ) . '</a></p>';
			return;
		}
		if ( ! $order->has_status( array( 'pending', 'failed' ) ) || ! $order->needs_payment() ) {
			echo '<p>' . esc_html__( 'This order is not awaiting payment. Please contact the store if you need help.', 'peptide-panapay' ) . '</p>';
			return;
		}
		try {
			$session = Session_Service::active_session( $order );
			if ( 'iframe' === $session['type'] ) {
				$height = $session['iframe_height'];
				echo '<iframe title="' . esc_attr__( 'Secure payment', 'peptide-panapay' ) . '" src="' . esc_url( $session['url'] ) . '" style="display:block;width:100%;min-height:' . esc_attr( (string) $height ) . 'px;border:0" referrerpolicy="no-referrer" allow="payment"></iframe>';
			}
			echo '<p><a href="' . esc_url( $session['url'] ) . '" rel="noreferrer">' . esc_html__( 'Open the secure payment page', 'peptide-panapay' ) . '</a></p>';
			echo '<p>' . esc_html__( 'Payment confirmation may take a moment after you return. Card details are entered on the payment provider’s page.', 'peptide-panapay' ) . '</p>';
		} catch ( \Throwable $error ) {
			$wa_url = self::whatsapp_rescue_url( $order );
			echo '<div style="margin:18px 0;padding:18px 20px;border-radius:14px;background:#f0fdf4;border:1.5px solid #86efac;color:#14532d">';
			echo '<p style="margin:0 0 10px;font-weight:700">' . esc_html__( 'The card payment channel is temporarily busy — your order details are safely reserved (not charged).', 'peptide-panapay' ) . '</p>';
			if ( '' !== $wa_url ) {
				echo '<p style="margin:0 0 12px;font-size:14px">' . esc_html__( 'Tap below to get an instant alternative payment link from our specialist on WhatsApp:', 'peptide-panapay' ) . '</p>';
				echo '<p style="margin:0"><a href="' . esc_url( $wa_url ) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:12px 18px;border-radius:10px;background:#16a34a;color:#fff;font-weight:700;text-decoration:none">💬 ' . esc_html__( 'Complete My Order on WhatsApp Now →', 'peptide-panapay' ) . '</a></p>';
			}
			echo '</div>';
		}
	}
}
