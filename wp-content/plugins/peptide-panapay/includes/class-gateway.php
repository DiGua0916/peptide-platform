<?php
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Gateway extends \WC_Payment_Gateway {
	public const ID = 'pc_panapay';
	private ?array $central_config = null;

	public function __construct() {
		$this->id = self::ID;
		$this->has_fields = false;
		$this->supports = array( 'products' );
		$this->method_title = __( 'Panapay', 'peptide-panapay' );
		$this->method_description = __( 'Hosted payment with verified server notifications. Card details are entered on the payment provider’s page.', 'peptide-panapay' );
		$this->init_form_fields();
		$this->init_settings();
		$this->enabled = $this->get_option( 'enabled', 'no' );
		$this->title = $this->get_option( 'title', __( 'Credit card', 'peptide-panapay' ) );
		if ( '' === trim( (string) $this->title ) ) { $this->title = __( 'Credit card', 'peptide-panapay' ); }
		$this->description = $this->get_option( 'description', __( 'Continue to the secure payment page to pay for your order.', 'peptide-panapay' ) );
		if ( Bridge::enabled() ) {
			$this->enabled = 'no';
			$this->title = __( 'Credit card', 'peptide-panapay' );
			$this->description = __( 'Continue to the secure payment page to pay for your order.', 'peptide-panapay' );
			try {
				$this->central_config = Bridge::configuration();
				$this->enabled = $this->central_config['enabled'] ? 'yes' : 'no';
				$this->title = $this->central_config['title'];
				$this->description = $this->central_config['description'];
			} catch ( \Throwable $error ) { /* A missing HQ policy never enables local merchant fallback. */ }
		}
		$this->description .= ' ' . __( 'A contact phone number is required for this payment method.', 'peptide-panapay' );
		if ( $this->is_test_mode() ) {
			$this->title .= ' ' . __( '(test mode)', 'peptide-panapay' );
			$this->description .= ' ' . __( 'Test payments only.', 'peptide-panapay' );
		}
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		if ( Bridge::enabled() ) { $this->form_fields = array(); return; }
		$this->form_fields = array(
			'enabled' => array( 'title' => __( 'Enable', 'peptide-panapay' ), 'type' => 'checkbox', 'label' => __( 'Enable Panapay', 'peptide-panapay' ), 'default' => 'no' ),
			'title' => array( 'title' => __( 'Checkout title', 'peptide-panapay' ), 'type' => 'text', 'default' => __( 'Credit card', 'peptide-panapay' ) ),
			'description' => array( 'title' => __( 'Checkout description', 'peptide-panapay' ), 'type' => 'textarea', 'default' => __( 'Continue to the secure payment page to pay for your order.', 'peptide-panapay' ) ),
			'test_mode' => array( 'title' => __( 'Test mode', 'peptide-panapay' ), 'type' => 'checkbox', 'label' => __( 'These are test merchant credentials', 'peptide-panapay' ), 'default' => 'yes', 'description' => __( 'Test mode is unavailable on a production WordPress environment. Live mode requires HTTPS. This switch labels and restricts the integration; the provider determines whether a merchant account is a test account.', 'peptide-panapay' ) ),
			'gateway' => array( 'title' => __( 'Gateway origin', 'peptide-panapay' ), 'type' => 'text', 'placeholder' => 'https://payments.example.com', 'description' => __( 'Public HTTPS origin supplied by Panapay, without a path. Can also be set with PC_PANAPAY_GATEWAY.', 'peptide-panapay' ) ),
			'mchid' => array( 'title' => __( 'Merchant ID', 'peptide-panapay' ), 'type' => 'text', 'description' => __( 'Merchant account for this store. Can also be set with PC_PANAPAY_MCHID.', 'peptide-panapay' ) ),
			'secret' => array( 'title' => __( 'Signing secret', 'peptide-panapay' ), 'type' => 'panapay_secret', 'description' => __( 'Leave blank to keep the saved secret. Stored encrypted; PC_PANAPAY_SECRET can provide it from server configuration instead.', 'peptide-panapay' ) ),
			'forget_secret' => array( 'title' => __( 'Remove saved secret', 'peptide-panapay' ), 'type' => 'checkbox', 'label' => __( 'Remove the database copy on this save', 'peptide-panapay' ), 'default' => 'no' ),
			'tag' => array( 'title' => __( 'Tag', 'peptide-panapay' ), 'type' => 'text', 'default' => '' ),
			'mode' => array( 'title' => __( 'Provider request mode', 'peptide-panapay' ), 'type' => 'select', 'default' => '2', 'options' => array( '2' => __( '2 — asynchronous', 'peptide-panapay' ), '1' => __( '1 — synchronous', 'peptide-panapay' ) ), 'description' => __( 'Both modes require a verified server notification before an order can be marked paid.', 'peptide-panapay' ) ),
			'payment_hosts' => array( 'title' => __( 'Trusted hosted payment domains', 'peptide-panapay' ), 'type' => 'textarea', 'description' => __( 'One exact hostname per line. The gateway hostname is included automatically. Add other checkout domains only after confirming them with the provider; wildcards are not accepted.', 'peptide-panapay' ) ),
		);
	}

	public function admin_options() {
		if ( ! Bridge::enabled() ) { parent::admin_options(); return; }
		echo '<h2>' . esc_html__( 'Panapay', 'peptide-panapay' ) . '</h2><p>'
			. esc_html__( '付款方式由总后台统一管理。商户配置、支付开关和交易记录请在总后台的支付中心管理。', 'peptide-panapay' )
			. '</p><p>' . esc_html( 'yes' === $this->enabled
				? __( '当前站点已获总后台授权使用此付款方式。', 'peptide-panapay' )
				: __( '当前站点尚未启用，或暂时无法取得总后台的支付配置。', 'peptide-panapay' ) ) . '</p>';
	}

	/** Store staff cannot write a merchant configuration once HQ owns payments. */
	public function process_admin_options() {
		return Bridge::enabled() ? false : parent::process_admin_options();
	}

	public function generate_panapay_secret_html( $key, $data ) {
		$field = $this->get_field_key( $key );
		return '<tr><th scope="row"><label for="' . esc_attr( $field ) . '">' . esc_html( $data['title'] ) . '</label></th><td><input class="input-text regular-input" type="password" autocomplete="new-password" id="' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" value=""><p class="description">' . esc_html( $data['description'] ) . '</p></td></tr>';
	}

	public function validate_secret_field( $key, $value ) {
		$post = $this->get_post_data();
		$remove = in_array( $post[ $this->get_field_key( 'forget_secret' ) ] ?? '', array( '1', 'yes' ), true );
		if ( $remove ) { return ''; }
		if ( ! is_string( $value ) || '' === $value ) { return $this->settings['secret'] ?? ''; }
		$value = wp_unslash( $value );
		if ( strlen( $value ) > 1024 || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) { throw new \Exception( __( 'Invalid signing secret.', 'peptide-panapay' ) ); }
		return Crypto::encrypt( $value );
	}

	public function validate_forget_secret_field( $key, $value ) { return 'no'; }
	public function validate_gateway_field( $key, $value ) { return Configuration::origin( trim( wp_unslash( (string) $value ) ) ); }
	public function validate_title_field( $key, $value ) { return sanitize_text_field( wp_unslash( $value ) ); }
	public function validate_description_field( $key, $value ) { return sanitize_textarea_field( wp_unslash( $value ) ); }
	public function validate_mchid_field( $key, $value ) {
		$value = trim( wp_unslash( (string) $value ) );
		if ( '' !== $value && ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $value ) ) { throw new \Exception( __( 'Invalid merchant ID.', 'peptide-panapay' ) ); }
		return $value;
	}

	public function is_test_mode(): bool {
		if ( Bridge::enabled() ) { return $this->central_config['test_mode'] ?? true; }
		return in_array( $this->get_option( 'test_mode', 'yes' ), array( true, 'yes', '1', 1 ), true );
	}

	/** Display selection is supplied by HQ; it does not register additional gateways. */
	public function payment_icons(): array {
		return Bridge::enabled() && is_array( $this->central_config['payment_icons'] ?? null )
			? $this->central_config['payment_icons'] : array();
	}

	public function get_icon() {
		return apply_filters( 'woocommerce_gateway_icon', Payment_Icons::markup( $this->payment_icons() ), $this->id );
	}

	public function is_available() {
		if ( ! parent::is_available() || ! Store::installed() ) { return false; }
		try {
			$config = Configuration::load();
			if ( ! empty( $config['central'] ) ) {
				if ( empty( $config['enabled'] ) || '' === $config['secret'] ) { return false; }
			} elseif ( '' === $config['gateway'] || '' === $config['mchid'] || '' === $config['secret'] ) { return false; }
			if ( $config['test_mode'] && 'production' === wp_get_environment_type() ) { return false; }
			if ( ! $config['test_mode'] && 'https' !== wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ) { return false; }
			return true;
		} catch ( \Throwable $error ) { return false; }
	}

	public function process_payment( $order_id ) {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || self::ID !== $order->get_payment_method() ) { return $this->failure(); }
			if ( $order->is_paid() && Persistence::is_paid( $order->get_id(), (string) $order->get_transaction_id() ) ) {
				return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
			}
			if ( ! $this->is_available() && ! Session_Service::has_open_attempt( $order ) ) { return $this->failure(); }
			Checkout_Fields::apply_to_order( $order );
			$session = Session_Service::start( $order->get_id() );
			return array( 'result' => 'success', 'redirect' => 'redirect' === $session['type'] ? $session['url'] : $order->get_checkout_payment_url( true ) );
		} catch ( \Throwable $error ) {
			$message = $error instanceof Customer_Error ? $error->getMessage() : __( 'Payment could not be started. Please try again later or contact the store before paying again.', 'peptide-panapay' );
			wc_add_notice( $message, 'error' );
			return array( 'result' => 'failure' );
		}
	}

	private function failure(): array {
		wc_add_notice( __( 'This payment method is currently unavailable.', 'peptide-panapay' ), 'error' );
		return array( 'result' => 'failure' );
	}
}
