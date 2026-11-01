<?php
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Session_Service {
	private const META = '_pc_panapay_sessions';
	private const MAX_ATTEMPTS = 12;

	private static function book( \WC_Order $order ): array {
		$book = $order->get_meta( self::META, true );
		if ( '' === $book ) { return array( 'active' => '', 'attempts' => array() ); }
		if ( ! is_array( $book ) || ! is_string( $book['active'] ?? null ) || ! is_array( $book['attempts'] ?? null )
			|| count( $book['attempts'] ) > self::MAX_ATTEMPTS ) { throw new \RuntimeException( 'payment_metadata_invalid' ); }
		return $book;
	}

	private static function save( \WC_Order $order, array $book ): void {
		if ( strlen( maybe_serialize( $book ) ) > 60000 ) { throw new \RuntimeException( 'payment_storage_capacity' ); }
		$order->update_meta_data( self::META, $book );
		$order->save();
		// WC_Abstract_Order::save can catch persistence exceptions and return its old ID.
		Persistence::assert_book( $order, $book );
	}

	public static function parse_reference( string $reference ): array {
		if ( ! preg_match( '/^PP[a-f0-9]{8}-([1-9][0-9]{0,18})-([a-f0-9]{32})$/D', $reference, $match )
			|| (int) $match[1] <= 0 || (string) (int) $match[1] !== $match[1] ) {
			throw new Protocol_Error( 'invalid_notification' );
		}
		return array( 'order_id' => (int) $match[1], 'attempt_id' => $match[2] );
	}

	private static function attempt( \WC_Order $order, string $reference ): array {
		$parsed = self::parse_reference( $reference );
		$book = self::book( $order );
		$attempt = $book['attempts'][ $parsed['attempt_id'] ] ?? null;
		if ( ! is_array( $attempt ) || ! is_string( $attempt['reference'] ?? null )
			|| ! hash_equals( $reference, $attempt['reference'] ) ) { throw new Protocol_Error( 'invalid_notification' ); }
		return $attempt;
	}

	private static function attempt_account( array $attempt ): string {
		if ( array_key_exists( 'account_id', $attempt ) !== array_key_exists( 'gateway_id', $attempt ) ) { throw new Protocol_Error( 'payment_channel_invalid', 409 ); }
		$account = $attempt['account_id'] ?? 'default';
		$gateway = $attempt['gateway_id'] ?? Gateway::ID;
		if ( ! is_string( $account ) || ! Gateway::valid_account_id( $account ) || ! is_string( $gateway ) || Gateway::native_id( $account ) !== $gateway ) {
			throw new Protocol_Error( 'payment_channel_invalid', 409 );
		}
		return $account;
	}

	private static function attempt_gateway( array $attempt ): string { return Gateway::native_id( self::attempt_account( $attempt ) ); }

	/** Syntax never grants access: receipts must match this order's persisted attempt. */
	public static function bound_gateway( \WC_Order $order ): ?string {
		try {
			$book = self::book( $order ); $attempt = $book['attempts'][ $book['active'] ] ?? null;
			if ( ! is_array( $attempt ) || ! is_string( $attempt['reference'] ?? null )
				|| self::parse_reference( $attempt['reference'] )['order_id'] !== $order->get_id() ) { return null; }
			$gateway = self::attempt_gateway( $attempt );
			return $order->get_payment_method() === $gateway && Persistence::has_gateway( $order->get_id(), $gateway ) ? $gateway : null;
		} catch ( \Throwable $error ) { return null; }
	}

	/** Allows only continuing an existing attempt when new HQ payments are disabled. */
	public static function has_open_attempt( \WC_Order $order, ?string $gateway_id = null ): bool {
		try {
			$book = self::book( $order );
			$attempt = $book['attempts'][ $book['active'] ] ?? null;
			$bound = self::bound_gateway( $order );
			return is_array( $attempt ) && 'failed' !== ( $attempt['state'] ?? '' ) && null !== $bound
				&& ( null === $gateway_id || $bound === $gateway_id ) && $order->has_status( array( 'pending', 'failed' ) ) && $order->needs_payment();
		} catch ( \Throwable $error ) { return false; }
	}

	private static function attempt_config( array $attempt ): array {
		$account = self::attempt_account( $attempt );
		if ( empty( $attempt['central'] ) ) {
			if ( 'default' !== $account ) { throw new Protocol_Error( 'payment_channel_invalid', 409 ); }
			return Configuration::legacy();
		}
		// Continuing an old session uses its frozen trust and presentation settings,
		// independently of edits, disabling or archiving any current HQ account.
		return array( 'account_id' => $account, 'gateway' => '', 'payment_hosts' => $attempt['payment_hosts'] ?? array(),
			'test_mode' => ! empty( $attempt['test_mode'] ) );
	}

	/** A read-only HQ status request can recover its saved result, never recreate remotely. */
	private static function recover_central( \WC_Order $order, array $book, array $lock, array $config ): array {
		$id = $book['active'];
		$attempt = $book['attempts'][ $id ];
		if ( ! Bridge::enabled() || ! is_string( $attempt['hub_url'] ?? null )
			|| ! hash_equals( $attempt['hub_url'], Bridge::hq_url() ) || ! isset( $attempt['creation_reservation'] ) ) {
			throw new Customer_Error( __( 'Please contact the store to reconcile this payment.', 'peptide-panapay' ) );
		}
		Store::assert_owner( $lock );
		$response = Bridge::recover( $attempt['reference'], self::attempt_account( $attempt ) );
		Store::assert_owner( $lock );
		if ( ! in_array( $response['state'], array( 'created', 'failed' ), true ) ) { return $book; }
		if ( 'created' === $response['state'] && ! Configuration::payment_url( $response['url'], $config ) ) { return $book; }
		$attempt['state'] = $response['state'];
		if ( 'created' === $response['state'] ) {
			$attempt['type'] = $response['type'];
			$attempt['url_box'] = Crypto::encrypt( $response['url'], 'checkout-url' );
		} else { unset( $attempt['type'], $attempt['url_box'] ); }
		$book['attempts'][ $id ] = $attempt;
		self::save( $order, $book );
		if ( 'failed' === $response['state'] ) {
			Store::set_creation_state( $attempt['creation_reservation'], 'failed' );
		} elseif ( ! Store::creation_ready( $order->get_id() ) ) {
			Store::set_creation_state( $attempt['creation_reservation'], 'created' );
		}
		// An unknown reservation remains unknown: recovery updates its persisted book,
		// not the original creation authority, and never makes a second provider call.
		return $book;
	}

	public static function start( int $order_id, ?string $expected_gateway = null ): array {
		$lock = Store::acquire( $order_id );
		try {
			$order = new \WC_Order( $order_id );
			$gateway_id = $order->get_payment_method();
			$account_id = Gateway::account_from_id( $gateway_id );
			if ( null === $account_id || ( null !== $expected_gateway && $gateway_id !== $expected_gateway )
				|| ! $order->has_status( array( 'pending', 'failed' ) ) || ! $order->needs_payment()
				|| ! Persistence::has_gateway( $order_id, $gateway_id ) ) {
				throw new Customer_Error( __( 'This order cannot start another payment.', 'peptide-panapay' ) );
			}
			Store::assert_no_completion( $order_id );
			$book = self::book( $order );
			$existing = $book['attempts'][ $book['active'] ] ?? null;
			if ( is_array( $existing ) && 'failed' !== ( $existing['state'] ?? '' ) ) {
				if ( self::attempt_gateway( $existing ) !== $gateway_id ) {
					throw new Customer_Error( __( 'This order already has a payment in progress with another method. Please contact the store before paying again.', 'peptide-panapay' ) );
				}
				Persistence::assert_book( $order, $book );
				if ( ! hash_equals( $existing['fingerprint'], Order_Payload::fingerprint( $order ) ) ) {
					throw new Customer_Error( __( 'This order changed after payment was started. Please contact the store before paying again.', 'peptide-panapay' ) );
				}
				$config = self::attempt_config( $existing );
				if ( ! empty( $existing['central'] ) && in_array( $existing['state'], array( 'creating', 'unknown' ), true ) ) {
					$book = self::recover_central( $order, $book, $lock, $config );
					$existing = $book['attempts'][ $book['active'] ];
				}
				if ( ! Store::creation_ready( $order_id ) ) { throw new Customer_Error( __( 'Payment setup has not been confirmed. Please contact the store before paying again.', 'peptide-panapay' ) ); }
				return self::session_url( $existing, $config );
			}
			$config = Configuration::load( $account_id );
			if ( ( ! empty( $config['central'] ) && empty( $config['enabled'] ) )
				|| ( $config['test_mode'] && 'production' === wp_get_environment_type() )
				|| ( ! $config['test_mode'] && 'https' !== wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ) ) {
				throw new Customer_Error( __( 'Payment is not configured for this environment.', 'peptide-panapay' ) );
			}
			if ( count( $book['attempts'] ) >= self::MAX_ATTEMPTS ) {
				throw new Customer_Error( __( 'Please contact the store to continue payment for this order.', 'peptide-panapay' ) );
			}
			$id = bin2hex( random_bytes( 16 ) );
			$reference = 'PP' . Store::site_id() . '-' . $order_id . '-' . $id;
			$token = bin2hex( random_bytes( 24 ) );
			$payload = Order_Payload::build( $order, $config, $reference, $token );
			$attempt = array(
				'reference' => $reference, 'state' => 'creating', 'created_at' => time(),
				'fingerprint' => Order_Payload::fingerprint( $order ),
				'amount' => (string) $order->get_total(), 'currency' => $order->get_currency(),
				'secret_box' => Crypto::encrypt( $config['secret'] ),
				'return_token_hash' => hash( 'sha256', $token ), 'test_mode' => $config['test_mode'],
				'merchant_scope' => hash( 'sha256', $config['gateway'] . '|' . $config['mchid'] ),
				'iframe_height' => self::iframe_height( $config['iframe_height'] ?? 530 ),
				'account_id' => $account_id, 'gateway_id' => $gateway_id,
			);
			if ( ! empty( $config['central'] ) ) {
				$attempt['central'] = true;
				$attempt['hub_url'] = Bridge::hq_url();
				$attempt['payment_hosts'] = $config['payment_hosts'];
			}
			$book['active'] = $id;
			$book['attempts'][ $id ] = $attempt;
			// Reserve space for the largest accepted hosted URL before creating a remote session.
			if ( strlen( maybe_serialize( $book ) ) > 45000 ) {
				throw new Customer_Error( __( 'Please contact the store to continue payment for this order.', 'peptide-panapay' ) );
			}
			$reservation = Store::reserve_creation( $order_id, $id );
			$book['attempts'][ $id ]['creation_reservation'] = $reservation;
			// Save before the HTTP call: a crash or timeout must not cause a blind new request.
			Store::assert_owner( $lock );
			self::save( $order, $book );
			Store::assert_owner( $lock );
			Store::assert_creation( $reservation );
			$response = ! empty( $config['central'] ) ? Bridge::create( $payload, $config['secret'], $account_id ) : Provider_Client::create( $payload, $config );
			unset( $payload, $token );
			Store::assert_owner( $lock );
			Store::assert_creation( $reservation );
			$attempt['state'] = $response['state'];
			if ( 'created' === $response['state'] ) {
				$attempt['type'] = $response['type'];
				$attempt['url_box'] = Crypto::encrypt( $response['url'], 'checkout-url' );
			}
			$book['attempts'][ $id ] = $attempt;
			$book['attempts'][ $id ]['creation_reservation'] = $reservation;
			self::save( $order, $book );
			Store::set_creation_state( $reservation, $response['state'] );
			return self::session_url( $attempt, $config );
		} finally { Store::release( $lock ); }
	}

	private static function session_url( array $attempt, array $config ): array {
		if ( ! empty( $attempt['test_mode'] ) && 'production' === wp_get_environment_type() ) {
			throw new Customer_Error( __( 'Test payments cannot be continued on this store.', 'peptide-panapay' ) );
		}
		if ( empty( $attempt['test_mode'] ) && 'https' !== wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ) {
			throw new Customer_Error( __( 'Live payments require this store to use HTTPS.', 'peptide-panapay' ) );
		}
		if ( 'failed' === ( $attempt['state'] ?? '' ) ) {
			throw new Customer_Error( __( 'The payment provider could not start this payment. Please check your details and try again.', 'peptide-panapay' ) );
		}
		if ( 'created' !== ( $attempt['state'] ?? '' ) || ! is_string( $attempt['url_box'] ?? null ) ) {
			throw new Customer_Error( __( 'The payment session has not been confirmed. Please contact the store before paying again.', 'peptide-panapay' ) );
		}
		$url = Crypto::decrypt( $attempt['url_box'], 'checkout-url' );
		if ( ! Configuration::payment_url( $url, $config ) ) {
			throw new Customer_Error( __( 'The secure payment page is not yet available. Please contact the store.', 'peptide-panapay' ) );
		}
		return array( 'type' => $attempt['type'], 'url' => $url, 'iframe_height' => self::iframe_height( $attempt['iframe_height'] ?? 530 ) );
	}

	private static function iframe_height( $value ): int {
		return is_int( $value ) && $value >= 320 && $value <= 1600 ? $value : 530;
	}

	public static function active_session( \WC_Order $order ): array {
		$book = self::book( $order );
		Persistence::assert_book( $order, $book );
		Store::assert_no_completion( $order->get_id() );
		if ( ! Store::creation_ready( $order->get_id() ) ) { throw new Customer_Error( __( 'Payment setup has not been confirmed. Please contact the store.', 'peptide-panapay' ) ); }
		$attempt = $book['attempts'][ $book['active'] ] ?? null;
		if ( ! is_array( $attempt ) ) { throw new Customer_Error( __( 'Please start payment from checkout.', 'peptide-panapay' ) ); }
		if ( self::attempt_gateway( $attempt ) !== $order->get_payment_method()
			|| ! Persistence::has_gateway( $order->get_id(), self::attempt_gateway( $attempt ) ) ) { throw new Protocol_Error( 'payment_channel_mismatch', 409 ); }
		if ( ! hash_equals( $attempt['fingerprint'], Order_Payload::fingerprint( $order ) ) ) {
			throw new Customer_Error( __( 'This order changed after payment was started. Please contact the store.', 'peptide-panapay' ) );
		}
		return self::session_url( $attempt, self::attempt_config( $attempt ) );
	}

	/** Signed server notification only. The browser-return path never calls this method. */
	public static function apply_notification( array $data, bool $central = false ): void {
		foreach ( array( 'order_number', 'status', 'transaction_id', 'sign' ) as $key ) {
			if ( ! is_string( $data[ $key ] ?? null ) ) { throw new Protocol_Error( 'invalid_notification' ); }
		}
		if ( ! in_array( $data['status'], array( 'SUCCEEDED', 'FAILED' ), true )
			|| ! preg_match( '/^[\x21-\x7e]{0,512}$/D', $data['transaction_id'] )
			|| ( 'SUCCEEDED' === $data['status'] && '' === $data['transaction_id'] ) ) {
			throw new Protocol_Error( 'invalid_notification' );
		}
		$reference = $data['order_number'];
		$parsed = self::parse_reference( $reference );
		$order = wc_get_order( $parsed['order_id'] );
		if ( ! $order instanceof \WC_Order ) { throw new Protocol_Error( 'invalid_notification' ); }
		$attempt = self::attempt( $order, $reference );
		if ( ! empty( $attempt['central'] ) !== $central ) { throw new Protocol_Error( 'notification_route_mismatch' ); }
		$expected_gateway = self::attempt_gateway( $attempt );
		if ( array_key_exists( 'account_id', $data ) && $data['account_id'] !== self::attempt_account( $attempt ) ) { throw new Protocol_Error( 'notification_channel_mismatch', 409 ); }
		$fields = array_intersect_key( $data, array_flip( array( 'order_number', 'status', 'transaction_id' ) ) );
		if ( ! Signature::verify( $fields, $data['sign'], Crypto::decrypt( $attempt['secret_box'] ) ) ) {
			throw new Protocol_Error( 'invalid_notification' );
		}
		if ( ! empty( $attempt['test_mode'] ) && 'production' === wp_get_environment_type() ) {
			throw new Protocol_Error( 'test_notification_not_allowed', 409 );
		}
		try { $lock = Store::acquire( $order->get_id() ); }
		catch ( \RuntimeException $error ) { throw new Protocol_Error( 'notification_busy', 503 ); }
		try {
			$order = new \WC_Order( $order->get_id() );
			$attempt = self::attempt( $order, $reference );
			if ( ! empty( $attempt['central'] ) !== $central ) { throw new Protocol_Error( 'notification_route_mismatch' ); }
			$expected_gateway = self::attempt_gateway( $attempt );
			if ( array_key_exists( 'account_id', $data ) && $data['account_id'] !== self::attempt_account( $attempt ) ) { throw new Protocol_Error( 'notification_channel_mismatch', 409 ); }
			if ( ! Store::creation_ready( $order->get_id() ) ) { throw new Protocol_Error( 'notification_creation_in_progress', 503 ); }
			if ( ! Signature::verify( $fields, $data['sign'], Crypto::decrypt( $attempt['secret_box'] ) ) ) {
				throw new Protocol_Error( 'invalid_notification' );
			}
			$book = self::book( $order );
			$id = $parsed['attempt_id'];
			$transaction = $data['transaction_id'];
			if ( 'FAILED' === $data['status'] ) {
				// A late failure cannot reverse a paid, refunded, cancelled or different-method order.
				if ( $order->is_paid() || ! $order->has_status( array( 'pending', 'failed' ) )
					|| $expected_gateway !== $order->get_payment_method() || ! Persistence::has_gateway( $order->get_id(), $expected_gateway ) || $book['active'] !== $id
					|| in_array( $attempt['state'], array( 'confirmed', 'paid' ), true ) ) { return; }
				if ( 'failed' !== $attempt['state'] ) {
					Store::assert_owner( $lock );
					$book['attempts'][ $id ]['state'] = 'failed';
					unset( $book['attempts'][ $id ]['url_box'], $book['attempts'][ $id ]['type'] );
					self::save( $order, $book );
					$order->update_status( 'failed', __( 'Panapay reported that this payment attempt failed.', 'peptide-panapay' ) );
				}
				Store::fail_creation( $order->get_id(), $id );
				return;
			}
			$native_transaction = self::native_transaction_id( $transaction );
			$scope = $attempt['merchant_scope'];
			$same_gateway = $expected_gateway === $order->get_payment_method() && Persistence::has_gateway( $order->get_id(), $expected_gateway );
			$persisted_paid = $same_gateway && Persistence::is_paid( $order->get_id(), $native_transaction );
			$eligible = ! $order->is_paid() && $same_gateway
				&& $order->has_status( array( 'pending', 'failed' ) )
				&& hash_equals( $attempt['fingerprint'], Order_Payload::fingerprint( $order ) )
				&& (string) $order->get_total() === $attempt['amount'] && $order->get_currency() === $attempt['currency'];
			$claim = Store::claim( $transaction, $order->get_id(), $id, $scope, $persisted_paid || $eligible );
			if ( 'applied' === $claim['state'] ) { return; }
			if ( 'review' === $claim['state'] ) {
				if ( $claim['fresh'] ) {
					$order->add_order_note( __( 'A verified Panapay notification requires manual reconciliation. No additional payment completion or fulfilment was triggered.', 'peptide-panapay' ) );
					if ( ! $eligible && ! $persisted_paid && $expected_gateway === $order->get_payment_method() && $order->has_status( array( 'pending', 'failed' ) ) ) {
						$order->update_status( 'on-hold', __( 'Payment details differ from the saved payment attempt; please reconcile with the provider.', 'peptide-panapay' ) );
					}
				}
				return;
			}
			if ( $persisted_paid ) {
				$state = $claim['fresh'] ? 'applied' : 'review';
				Store::set_transaction_state( $transaction, $order->get_id(), $state, $scope );
				if ( 'review' === $state ) {
					$order->add_order_note( __( 'The payment is recorded, but a previous completion worker did not confirm its full outcome. Review inventory and notifications; native completion was not repeated.', 'peptide-panapay' ) );
				}
				return;
			}
			// A previous worker may still be inside a native hook. Never take over its financial claim.
			if ( ! $claim['can_complete'] ) { throw new Protocol_Error( 'notification_completion_unconfirmed', 503 ); }
			Store::assert_owner( $lock );
			$book['active'] = $id;
			$book['attempts'][ $id ]['state'] = 'confirmed';
			$book['attempts'][ $id ]['provider_transaction_box'] = Crypto::encrypt( $transaction, 'provider-transaction' );
			self::save( $order, $book );
			if ( ! Persistence::has_gateway( $order->get_id(), $expected_gateway ) ) {
				Store::set_transaction_state( $transaction, $order->get_id(), 'review', $scope );
				return;
			}
			try { Completion_Guard::complete( $order, $native_transaction ); }
			catch ( \Throwable $error ) { throw new Protocol_Error( 'notification_persistence_unconfirmed', 503 ); }
			if ( ! Persistence::has_gateway( $order->get_id(), $expected_gateway ) || ! Persistence::is_paid( $order->get_id(), $native_transaction ) ) {
				// Unknown effects need reconciliation; another request cannot repeat native completion.
				throw new Protocol_Error( 'notification_persistence_unconfirmed', 503 );
			}
			$book['attempts'][ $id ]['state'] = 'paid';
			foreach ( $book['attempts'] as &$stored_attempt ) { unset( $stored_attempt['url_box'], $stored_attempt['type'] ); }
			unset( $stored_attempt );
			self::save( $order, $book );
			if ( isset( $attempt['creation_reservation'] ) ) { Store::set_creation_state( $attempt['creation_reservation'], 'paid' ); }
			Store::set_transaction_state( $transaction, $order->get_id(), 'applied', $scope );
		} finally { Store::release( $lock ); }
	}

	/** Called only after the authenticated bridge has applied this exact notification. */
	public static function notification_disposition( array $data ): string {
		$parsed = self::parse_reference( $data['order_number'] );
		$order = new \WC_Order( $parsed['order_id'] );
		$attempt = self::attempt( $order, $data['order_number'] );
		if ( empty( $attempt['central'] ) ) { throw new Protocol_Error( 'notification_route_mismatch' ); }
		$book = self::book( $order );
		Persistence::assert_book( $order, $book );
		if ( self::attempt_gateway( $attempt ) !== $order->get_payment_method()
			|| ! Persistence::has_gateway( $order->get_id(), self::attempt_gateway( $attempt ) ) ) { return 'review'; }
		if ( 'SUCCEEDED' === $data['status'] && 'paid' === $attempt['state']
			&& Persistence::is_paid( $order->get_id(), self::native_transaction_id( $data['transaction_id'] ) ) ) {
			// Do not call a recovered, uncertain native completion "fully applied" just
			// because the native paid status was written before its worker interrupted.
			foreach ( Store::admin_transactions( $order->get_id() ) as $row ) {
				if ( $row['attempt_id'] === $parsed['attempt_id'] && $row['state'] === 'applied'
					&& hash_equals( $data['transaction_id'], $row['transaction'] ) ) { return 'paid'; }
			}
		}
		if ( 'FAILED' === $data['status'] && $book['active'] === $parsed['attempt_id'] && 'failed' === $attempt['state']
			&& Persistence::has_status( $order->get_id(), array( 'failed' ) ) ) { return 'failed'; }
		return 'review';
	}

	public static function native_transaction_id( string $transaction ): string {
		return strlen( $transaction ) <= 100 && sanitize_text_field( $transaction ) === $transaction
			? $transaction : 'PPH-' . hash( 'sha256', $transaction );
	}

	/** Staff-only caller; never include this DTO in checkout or a public endpoint. */
	public static function admin_details( \WC_Order $order ): array {
		$book = self::book( $order );
		$a = $book['attempts'][ $book['active'] ] ?? null;
		if ( ! is_array( $a ) ) { return array(); }
		return array(
			'reference' => $a['reference'], 'state' => $a['state'],
			'transaction' => isset( $a['provider_transaction_box'] ) ? Crypto::decrypt( $a['provider_transaction_box'], 'provider-transaction' ) : '',
		);
	}

	/** Return signatures do not bind an order; a separate opaque browser token binds read access. */
	public static function returned_order( array $data ): \WC_Order {
		$parsed = self::parse_reference( $data['reference'] );
		$order = wc_get_order( $parsed['order_id'] );
		if ( ! $order instanceof \WC_Order ) { throw new Protocol_Error( 'invalid_return' ); }
		$attempt = self::attempt( $order, $data['reference'] );
		if ( ! preg_match( '/^[a-f0-9]{48}$/D', $data['token'] ) || ! hash_equals( $attempt['return_token_hash'], hash( 'sha256', $data['token'] ) )
			|| time() - $attempt['created_at'] > 7 * DAY_IN_SECONDS
			|| ! Signature::verify( array( 'transaction_id' => $data['transaction_id'], 'status' => $data['status'] ), $data['sign'], Crypto::decrypt( $attempt['secret_box'] ) ) ) {
			throw new Protocol_Error( 'invalid_return' );
		}
		return $order;
	}
}
