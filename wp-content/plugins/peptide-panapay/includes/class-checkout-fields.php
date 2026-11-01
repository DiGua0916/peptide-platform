<?php
namespace VialCove\Panapay;

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields as Native_Checkout_Fields;
use Automattic\WooCommerce\Blocks\Package;

defined( 'ABSPATH' ) || exit;

/** A gateway-specific fallback when the merchant hides Woo's native phone field. */
final class Checkout_Fields {

	public const FIELD_ID = 'peptide-panapay/phone';

	private static bool $hooks_registered = false;
	private static bool $registered = false;
	private static bool $registration_failed = false;

	public static function register_hooks(): void {
		if ( self::$hooks_registered ) { return; }
		self::$hooks_registered = true;
		add_action( 'admin_notices', array( self::class, 'admin_notice' ) );
		if ( did_action( 'woocommerce_init' ) ) {
			self::register_field();
		} else {
			add_action( 'woocommerce_init', array( self::class, 'register_field' ), 20 );
		}
	}

	public static function register_field(): void {
		if ( ! self::native_phone_hidden() || self::$registered ) { return; }
		if ( ! did_action( 'woocommerce_blocks_loaded' ) ) {
			add_action( 'woocommerce_blocks_loaded', array( self::class, 'register_field' ), 20 );
			return;
		}
		if ( ! self::supported() ) {
			self::$registration_failed = true;
			return;
		}

		// Require the full path so a missing checkout/payment_method cannot match by omission.
		$selected = array(
			'type'       => 'object',
			'properties' => array(
				'checkout' => array(
					'type'       => 'object',
					'properties' => array( 'payment_method' => array( 'enum' => Gateway::known_ids() ) ),
					'required'   => array( 'payment_method' ),
				),
			),
			'required'   => array( 'checkout' ),
		);

		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => self::FIELD_ID,
					'label'                      => __( 'Phone number for card payment', 'peptide-panapay' ),
					'location'                   => 'order',
					'type'                       => 'text',
					'attributes'                 => array( 'autocomplete' => 'tel', 'maxLength' => 64 ),
					'required'                   => $selected,
					'hidden'                     => array( 'not' => $selected ),
					'show_in_order_confirmation' => false,
					'sanitize_callback'          => array( self::class, 'sanitize_phone' ),
					'validate_callback'          => array( self::class, 'validate_phone' ),
				)
			);
			$fields = self::service();
			self::$registered = $fields && $fields->is_field( self::FIELD_ID );
			self::$registration_failed = ! self::$registered;
		} catch ( \Throwable $error ) {
			// Do not expose native exception text, request fields, or customer data.
			self::$registration_failed = true;
		}
	}

	/** Copy only a field already persisted on this same order by native checkout. */
	public static function apply_to_order( \WC_Order $order ): void {
		if ( ! in_array( $order->get_payment_method(), Gateway::known_ids(), true )
			&& Session_Service::bound_gateway( $order ) !== $order->get_payment_method() ) { return; }
		if ( '' !== trim( $order->get_billing_phone() ) ) { return; }
		if ( ! self::native_phone_hidden() ) { return; } // Existing native validation remains authoritative.

		$fields = self::service();
		if ( ! $fields || ! $fields->is_field( self::FIELD_ID ) ) {
			throw new Customer_Error( __( 'This checkout cannot collect the phone number required for this payment method. Please choose another payment method or contact the store.', 'peptide-panapay' ) );
		}

		// The official getter can supply a default filter value when no value was saved.
		// Use Woo's public prefix API only to establish presence; never guess private meta names.
		$key = Native_Checkout_Fields::get_group_key( 'other' ) . self::FIELD_ID;
		$stored = $order->meta_exists( $key ) ? $order->get_meta( $key, true, 'edit' ) : null;
		if ( ! is_string( $stored ) || '' === trim( $stored ) ) {
			throw new Customer_Error( __( 'Please enter a phone number for this payment method.', 'peptide-panapay' ) );
		}
		$value = $fields->get_field_from_object( self::FIELD_ID, $order, 'other' );
		$phone = self::normalize_phone( $value, $order->get_billing_country() );
		if ( '' === $phone ) {
			throw new Customer_Error( __( 'Please enter a valid phone number for this payment method.', 'peptide-panapay' ) );
		}
		$order->set_billing_phone( $phone );
		$order->save();
	}

	/** Sanitization never turns HTML or arbitrary text into an accepted number. */
	public static function sanitize_phone( $value ): string {
		return self::normalize_phone( $value );
	}

	public static function validate_phone( $value, array $field = array() ) {
		if ( '' === $value && true !== ( $field['required'] ?? false ) ) { return; }
		if ( '' === self::normalize_phone( $value ) ) {
			return new \WP_Error( 'pc_panapay_phone_invalid', __( 'Please enter a valid phone number for card payment.', 'peptide-panapay' ) );
		}
	}

	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::native_phone_hidden() ) { return; }
		if ( Bridge::enabled() ) {
			try { $enabled = array_filter( Bridge::configurations(), static fn( array $config ): bool => ! empty( $config['enabled'] ) ); }
			catch ( \Throwable $error ) { return; }
			if ( ! $enabled ) { return; }
		} else {
			$settings = get_option( 'woocommerce_pc_panapay_settings', array() );
			if ( ! is_array( $settings ) || 'yes' !== ( $settings['enabled'] ?? 'no' ) ) { return; }
		}
		$fields = self::service();
		if ( ! self::$registration_failed && $fields && $fields->is_field( self::FIELD_ID ) ) { return; }
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Panapay needs a phone number, but the native phone field is hidden and the conditional checkout field is unavailable. Show the native phone field in the Checkout block settings, or use a supported WooCommerce version (11.2 or newer). No checkout setting has been changed automatically.', 'peptide-panapay' ) . '</p></div>';
	}

	private static function native_phone_hidden(): bool {
		return 'hidden' === get_option( 'woocommerce_checkout_phone_field', 'optional' );
	}

	private static function supported(): bool {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '11.2.0', '>=' )
			&& function_exists( 'woocommerce_register_additional_checkout_field' )
			&& function_exists( 'wc_sanitize_phone_number' )
			&& class_exists( Package::class ) && class_exists( Native_Checkout_Fields::class )
			&& is_callable( array( Native_Checkout_Fields::class, 'get_group_key' ) )
			&& method_exists( Native_Checkout_Fields::class, 'get_field_from_object' )
			&& method_exists( Native_Checkout_Fields::class, 'is_conditional_field' )
			&& is_callable( array( '\WC_Validation', 'is_phone_format' ) )
			&& is_callable( array( '\WC_Validation', 'is_phone' ) );
	}

	private static function service(): ?Native_Checkout_Fields {
		if ( ! self::supported() ) { return null; }
		try {
			$fields = Package::container()->get( Native_Checkout_Fields::class );
			return $fields instanceof Native_Checkout_Fields ? $fields : null;
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	private static function normalize_phone( $value, ?string $country = null ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 64 || ! is_callable( array( '\WC_Validation', 'is_phone_format' ) )
			|| ! is_callable( array( '\WC_Validation', 'is_phone' ) ) || ! function_exists( 'wc_sanitize_phone_number' ) ) { return ''; }
		$value = trim( $value );
		if ( '' === $value || ! \WC_Validation::is_phone_format( $value ) || ! \WC_Validation::is_phone( $value, $country ) ) { return ''; }
		$phone = wc_sanitize_phone_number( $value );
		return is_string( $phone ) && preg_match( '/^\+?[0-9]+$/D', $phone ) ? $phone : '';
	}
}
