<?php
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Order_Payload {
	public static function build( \WC_Order $order, array $config, string $reference, string $return_token ): array {
		$currency = $order->get_currency();
		$amount = (string) $order->get_total();
		if ( ! isset( get_woocommerce_currencies()[ $currency ] ) || ! preg_match( '/^[0-9]+(?:[.][0-9]+)?$/D', $amount ) || (float) $amount <= 0 ) {
			throw new Customer_Error( __( 'The order amount or currency is not supported.', 'peptide-panapay' ) );
		}
		$payload = array( 'site' => home_url( '/' ), 'order_id' => $reference, 'currency' => $currency, 'amount' => $amount );
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$address = $order->get_address( $type );
			if ( 'shipping' === $type && ! $order->needs_shipping_address()
				&& '' === trim( (string) ( $address['address_1'] ?? '' ) )
				&& '' === trim( (string) ( $address['country'] ?? '' ) ) ) {
				$address = $order->get_address( 'billing' );
			}
			foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'postcode', 'city', 'state', 'country' ) as $field ) {
				$key = $type . '_' . str_replace( array( 'first_name', 'last_name' ), array( 'firstname', 'lastname' ), $field );
				$payload[ $key ] = (string) ( $address[ $field ] ?? '' );
			}
			foreach ( array( 'firstname', 'lastname', 'address_1', 'city', 'country' ) as $field ) {
				if ( '' === trim( $payload[ $type . '_' . $field ] ) ) {
					throw new Customer_Error( __( 'Please complete your billing and shipping address before continuing to payment.', 'peptide-panapay' ) );
				}
			}
		}
		$payload['email'] = (string) $order->get_billing_email();
		$payload['phone'] = (string) $order->get_billing_phone();
		if ( ! is_email( $payload['email'] ) || '' === trim( $payload['phone'] ) || ! \WC_Validation::is_phone( $payload['phone'] ) ) {
			throw new Customer_Error( __( 'Please provide an email address and phone number for this payment method.', 'peptide-panapay' ) );
		}
		$payload['products'] = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || $item->get_quantity() <= 0 ) { continue; }
			$product = $item->get_product();
			$payload['products'][] = array(
				'name' => $item->get_name(), 'quantity' => $item->get_quantity(),
				'price' => wc_format_decimal( $order->get_item_total( $item, false, true ), wc_get_price_decimals() ),
				'total' => wc_format_decimal( $item->get_total(), wc_get_price_decimals() ),
				'image' => $product ? ( wp_get_attachment_image_url( $product->get_image_id(), 'full' ) ?: '' ) : '',
				'url' => $product ? $product->get_permalink() : '',
			);
		}
		if ( ! $payload['products'] ) { throw new Customer_Error( __( 'This order has no payable products.', 'peptide-panapay' ) ); }
		$payload['ip'] = (string) $order->get_customer_ip_address();
		$payload['accept_language'] = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '' ) ), 0, 255 );
		$payload['user_agent'] = (string) $order->get_customer_user_agent();
		$payload['return_url'] = add_query_arg( array( 'reference' => $reference, 'token' => $return_token ), WC()->api_request_url( 'pc_panapay_return' ) );
		$payload['notify_url'] = WC()->api_request_url( 'pc_panapay_notify' );
		$payload['platform_id'] = 6;
		$payload['mchid'] = $config['mchid'];
		$payload['tag'] = $config['tag'];
		$payload['mode'] = $config['mode'];
		$payload['sign'] = Signature::generate( array_intersect_key( $payload, array_flip( array( 'mchid', 'currency', 'amount', 'site', 'order_id' ) ) ), $config['secret'] );
		return $payload;
	}

	/** Order data only: excludes mutable domain/media URLs and browser request headers. */
	public static function fingerprint( \WC_Order $order ): string {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array( $item->get_id(), $item->get_product_id(), $item->get_variation_id(), $item->get_name(),
				(string) $item->get_quantity(), (string) $item->get_total(), $item->get_taxes() );
		}
		return hash( 'sha256', wp_json_encode( array(
			'currency' => $order->get_currency(), 'total' => (string) $order->get_total(), 'items' => $items,
			'billing' => $order->get_address( 'billing' ), 'shipping' => $order->get_address( 'shipping' ),
			'email' => $order->get_billing_email(), 'phone' => $order->get_billing_phone(),
		) ) );
	}
}
