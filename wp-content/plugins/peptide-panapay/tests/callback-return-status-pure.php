<?php
/** Real buyer callback/controller rendering; order reads/cart boundary are synthetic. No WP, HTTP or DB. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' ); define( 'DAY_IN_SECONDS', 86400 );
	final class WC_Order {
		public function get_id(): int { return 42; }
		public function get_order_number(): string { return 'SYNTH-42'; }
		public function get_customer_id(): int { return $GLOBALS['fixture_customer'] ?? 0; }
		public function get_order_key(): string { return 'synthetic-order-key'; }
		public function get_payment_method(): string { return 'pc_panapay'; }
		public function is_paid(): bool { return $GLOBALS['fixture_paid'] ?? false; }
		public function get_meta( string $name, bool $single = true ) {
			if ( '_pc_panapay_sessions' !== $name ) { return ''; }
			if ( 'receipt-paid-missing-attempt' === $GLOBALS['scenario'] ) { return array( 'active' => '', 'attempts' => array() ); }
			$id = str_repeat( 'a', 32 ); return array( 'active' => $id, 'attempts' => array( $id => array( 'reference' => $GLOBALS['reference'] ) ) );
		}
		public function has_status( $statuses ): bool { return in_array( $GLOBALS['fixture_paid'] ? 'processing' : 'pending', (array) $statuses, true ); }
		public function needs_payment(): bool { return ! $this->is_paid(); }
		public function get_checkout_payment_url( bool $on_checkout = false ): string { return 'https://www.merchant.example.com/checkout/order-pay/42/?key=synthetic-order-key'; }
		public function get_checkout_order_received_url(): string { ++$GLOBALS['order_received_calls']; throw new \RuntimeException( 'Must not visit Woo order-received' ); }
		public function payment_complete( ...$args ): void { ++$GLOBALS['financial_calls']; throw new \RuntimeException( 'Forbidden payment completion' ); }
	}
	function __( string $text, string $domain = '' ): string { return $text; }
	function esc_html( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_html__( string $text, string $domain = '' ): string { return esc_html( $text ); }
	function esc_attr( $text ): string { return esc_html( $text ); }
	function esc_url( $url ): string { return esc_html( $url ); }
	function get_bloginfo( string $name ): string { return 'en-US'; }
	function wp_unslash( string $text ): string { return stripslashes( $text ); }
	function wp_parse_url( string $url, int $part = -1 ) { return parse_url( $url, $part ); }
	function home_url( string $path = '' ): string { return 'https://www.merchant.example.com' . $path; }
	function wc_get_checkout_url(): string { return 'https://www.merchant.example.com/checkout/'; }
	function wc_get_order( $id ) { return 42 === (int) $id ? new WC_Order() : false; }
	function wc_clean( $value ) { return $value; }
	function get_current_user_id(): int { return $GLOBALS['fixture_user'] ?? 0; }
	function current_user_can( string $cap ): bool { return (bool) ( $GLOBALS['fixture_admin'] ?? false ); }
	function wp_login_url( string $redirect ): string { return 'https://www.merchant.example.com/wp-login.php?redirect_to=' . rawurlencode( $redirect ); }
	function status_header( int $status ): void { $GLOBALS['http_status'] = $status; }
	function add_query_arg( array $fields, string $url ): string { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ); }
	function wp_safe_redirect( ...$args ): void { ++$GLOBALS['redirects']; throw new \RuntimeException( 'Unexpected callback redirect' ); }
	function wp_redirect( ...$args ): void { ++$GLOBALS['redirects']; throw new \RuntimeException( 'Unexpected callback redirect' ); }
	function wp_safe_remote_post( ...$args ) { ++$GLOBALS['http_calls']; throw new \RuntimeException( 'Forbidden HTTP' ); }
	function wp_safe_remote_get( ...$args ) { ++$GLOBALS['http_calls']; throw new \RuntimeException( 'Forbidden HTTP' ); }
	function wp_remote_post( ...$args ) { ++$GLOBALS['http_calls']; throw new \RuntimeException( 'Forbidden HTTP' ); }
	function wp_remote_get( ...$args ) { ++$GLOBALS['http_calls']; throw new \RuntimeException( 'Forbidden HTTP' ); }
	function WC(): object { return new class { public function api_request_url( string $name ): string { return 'https://manager.example.com/wc-api/' . $name . '/'; } }; }
}
namespace VialCove\Panapay {
	function headers_sent(): bool { return false; }
	function nocache_headers(): void { ++$GLOBALS['no_cache_calls']; }
	function header( string $value, bool $replace = true ): void { $GLOBALS['headers'][] = $value; }
	final class Return_Cart {
		public static function clear( \WC_Order $order, string $reference ): bool {
			++$GLOBALS['cart_calls'];
			if ( 42 !== $order->get_id() || $GLOBALS['reference'] !== $reference || 'paid' !== $GLOBALS['read_state'] ) { throw new \RuntimeException( 'Unsafe synthetic cart boundary' ); }
			if ( \get_current_user_id() !== $order->get_customer_id() ) { return false; }
			++$GLOBALS['cart_cleared']; return true;
		}
	}
	final class Order_Payload {
		public static function customer_url( string $url ): string { return str_replace( 'https://manager.example.com/', 'https://www.merchant.example.com/', $url ); }
	}
	final class Session_Service {
		public static function parse_reference( string $reference ): array {
			if ( ! preg_match( '/\APP[a-f0-9]{8}-([1-9][0-9]*)-([a-f0-9]{32})\z/D', $reference, $m ) ) { throw new Protocol_Error( 'invalid_return' ); }
			return array( 'order_id' => (int) $m[1], 'attempt_id' => $m[2] );
		}
		/** Stub persistence ownership/read decision only; exercise the real purpose proof. */
		public static function returned_order( array $data ): \WC_Order {
			++$GLOBALS['read_calls']; $GLOBALS['read_fields'] = array_keys( $data );
			if ( $GLOBALS['reference'] !== ( $data['reference'] ?? null ) || $GLOBALS['browser_token'] !== ( $data['token'] ?? null ) ) { throw new Protocol_Error( 'invalid_return' ); }
			if ( isset( $data['check'] ) ) {
				if ( ! Return_Context::verify_check( $data, 'synthetic-link-key' ) ) { throw new Protocol_Error( 'invalid_return' ); }
			} elseif ( ! Signature::verify( array( 'transaction_id' => $data['transaction_id'], 'status' => $data['status'] ), $data['sign'], 'synthetic-link-key' ) ) { throw new Protocol_Error( 'invalid_return' ); }
			return new \WC_Order();
		}
		public static function return_status( \WC_Order $order, string $reference ): string {
			++$GLOBALS['status_reads'];
			if ( 42 !== $order->get_id() || $GLOBALS['reference'] !== $reference ) { throw new Protocol_Error( 'invalid_return' ); }
			if ( 'receipt-paid-ledger-error' === $GLOBALS['scenario'] ) { throw new \RuntimeException( 'Synthetic ledger unavailable' ); }
			return $GLOBALS['read_state'];
		}
		public static function bound_gateway( \WC_Order $order ): ?string { return 'receipt-wrong-gateway' === $GLOBALS['scenario'] ? null : $order->get_payment_method(); }
		public static function active_session( \WC_Order $order ): array {
			++$GLOBALS['active_session_reads']; return array( 'type' => 'redirect', 'url' => 'https://cashier.example.com/secure', 'iframe_height' => 530 );
		}
		public static function start( ...$args ): never { ++$GLOBALS['create_calls']; throw new \RuntimeException( 'Forbidden CREATE on callback' ); }
		public static function apply_notification( array $data ): void {
			++$GLOBALS['notification_calls'];
			$fields = array_intersect_key( $data, array_flip( array( 'order_number', 'status', 'transaction_id' ) ) );
			if ( ! Signature::verify( $fields, $data['sign'], 'synthetic-provider-key' ) ) { throw new Protocol_Error( 'invalid_notification' ); }
			++$GLOBALS['financial_calls']; throw new \RuntimeException( 'Unexpected actual notification authority' );
		}
	}
}
namespace {
	foreach ( array( 'protocol-error', 'signature', 'return-context', 'callbacks' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
	use VialCove\Panapay\{Callbacks, Signature};
	$scenario = null; $html_dir = null;
	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( str_starts_with( $arg, '--scenario=' ) ) { $scenario = substr( $arg, 11 ); }
		elseif ( str_starts_with( $arg, '--html-dir=' ) ) { $html_dir = substr( $arg, 11 ); }
	}
	$reference = 'PPab12cd34-42-' . str_repeat( 'a', 32 ); $browser_token = str_repeat( 'b', 48 );
	if ( null !== $scenario ) {
		$http_calls = $financial_calls = $redirects = $cart_calls = $cart_cleared = $create_calls = $notification_calls = $read_calls = $status_reads = $order_received_calls = $no_cache_calls = $active_session_reads = 0;
		$headers = $read_fields = array(); $http_status = 200; $read_state = $scenario;
		$fixture_customer = $fixture_user = 0; $fixture_paid = false; $fixture_admin = false;
		$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = array( 'reference' => $reference, 'token' => $browser_token, 'check' => '1' );
		$_GET['sign'] = Signature::generate( array( 'purpose' => 'pc-panapay-return-check-v1', 'reference' => $reference, 'token' => $browser_token ), 'synthetic-link-key' );
		switch ( $scenario ) {
			case 'paid': case 'unknown': case 'failed': case 'review': break;
			case 'legacy-unknown':
				$read_state = 'unknown'; unset( $_GET['check'] ); $_GET['status'] = 'SUCCEEDED'; $_GET['transaction_id'] = 'synthetic-transaction';
				$_GET['sign'] = Signature::generate( array( 'transaction_id' => $_GET['transaction_id'], 'status' => $_GET['status'] ), 'synthetic-link-key' ); break;
			case 'invalid-token': $_GET['token'] = str_repeat( 'f', 48 ); break;
			case 'invalid-reference': $_GET['reference'] = str_replace( '-42-', '-43-', $reference ); break;
			case 'invalid-sign': $_GET['sign'] = str_repeat( '0', 32 ); break;
			case 'check-with-status': $_GET['status'] = 'SUCCEEDED'; break;
			case 'check-with-transaction': $_GET['transaction_id'] = 'synthetic-transaction'; break;
			case 'check-array': $_GET['check'] = array( '1' ); break;
			case 'wrong-method': $_SERVER['REQUEST_METHOD'] = 'POST'; break;
			case 'notify-check': $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $_GET; break;
			case 'notify-smuggled-proof':
				$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array( 'order_number' => $reference, 'status' => 'SUCCEEDED', 'transaction_id' => 'synthetic-transaction', 'check' => '1', 'sign' => $_GET['sign'] ); break;
			case 'receipt-paid': case 'receipt-paid-review': case 'receipt-paid-missing-attempt': case 'receipt-paid-ledger-error':
			case 'receipt-wrong-key': case 'receipt-array-key': case 'receipt-wrong-owner': case 'receipt-customer-owner': case 'receipt-admin': case 'receipt-wrong-gateway': case 'receipt-unpaid':
				$_GET = array( 'key' => 'synthetic-order-key' ); $fixture_paid = 'receipt-unpaid' !== $scenario; $read_state = 'receipt-paid-review' === $scenario ? 'review' : 'paid';
				if ( 'receipt-wrong-key' === $scenario ) { $_GET['key'] = 'wrong'; }
				if ( 'receipt-array-key' === $scenario ) { $_GET['key'] = array( 'synthetic-order-key' ); }
				if ( in_array( $scenario, array( 'receipt-wrong-owner', 'receipt-customer-owner', 'receipt-admin' ), true ) ) { $fixture_customer = 88; $fixture_user = 'receipt-customer-owner' === $scenario ? 88 : 77; }
				$fixture_admin = 'receipt-admin' === $scenario; break;
			default: throw new \RuntimeException( 'Unknown callback scenario' );
		}
		ob_start();
		register_shutdown_function( static function () use ( $scenario ): void {
			$body = ob_get_clean();
			if ( error_get_last() && in_array( error_get_last()['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) { fwrite( STDERR, $body ); return; }
			echo json_encode( array( 'scenario' => $scenario, 'body_b64' => base64_encode( $body ), 'status' => $GLOBALS['http_status'], 'headers' => $GLOBALS['headers'],
				'http' => $GLOBALS['http_calls'], 'financial_writes' => $GLOBALS['financial_calls'], 'redirects' => $GLOBALS['redirects'], 'cart_calls' => $GLOBALS['cart_calls'],
				'cart_cleared' => $GLOBALS['cart_cleared'], 'active_session_reads' => $GLOBALS['active_session_reads'],
				'create_calls' => $GLOBALS['create_calls'], 'notification_calls' => $GLOBALS['notification_calls'], 'read_calls' => $GLOBALS['read_calls'],
				'status_reads' => $GLOBALS['status_reads'], 'read_fields' => $GLOBALS['read_fields'], 'order_received_calls' => $GLOBALS['order_received_calls'], 'no_cache_calls' => $GLOBALS['no_cache_calls'] ), JSON_THROW_ON_ERROR );
		} );
		if ( str_starts_with( $scenario, 'notify-' ) ) { Callbacks::notify(); }
		if ( str_starts_with( $scenario, 'receipt-' ) ) { Callbacks::receipt( 42 ); exit; }
		Callbacks::returned();
		throw new \RuntimeException( 'Expected real callback exit' );
	}
	$checks = 0;
	function check( string $label, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new \RuntimeException( 'FAIL ' . $label ); } }
	$cases = array( 'paid', 'unknown', 'failed', 'review', 'legacy-unknown', 'invalid-token', 'invalid-reference', 'invalid-sign', 'check-with-status', 'check-with-transaction', 'check-array', 'wrong-method', 'notify-check', 'notify-smuggled-proof',
		'receipt-paid', 'receipt-paid-review', 'receipt-paid-missing-attempt', 'receipt-paid-ledger-error', 'receipt-wrong-key', 'receipt-array-key', 'receipt-wrong-owner', 'receipt-customer-owner', 'receipt-admin', 'receipt-wrong-gateway', 'receipt-unpaid' );
	$observed = array(); $artifacts = array();
	foreach ( $cases as $case ) {
		$process = proc_open( array( PHP_BINARY, __FILE__, '--scenario=' . $case ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) { throw new \RuntimeException( 'Unable to run callback child' ); }
		fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[2] ); $exit = proc_close( $process );
		if ( 0 !== $exit ) { throw new \RuntimeException( 'Callback child failed: ' . $case . "\n" . $stdout . $stderr ); }
		$r = json_decode( $stdout, true, 16, JSON_THROW_ON_ERROR ); $body = base64_decode( $r['body_b64'], true ); unset( $r['body_b64'] );
		check( $case . ': same scenario', $case === $r['scenario'] && is_string( $body ) );
		check( $case . ': no HTTP, financial write, CREATE or Woo received redirect', 0 === $r['http'] && 0 === $r['financial_writes'] && 0 === $r['create_calls'] && 0 === $r['redirects'] && 0 === $r['order_received_calls'] );
		check( $case . ': no-cache path applied', $r['no_cache_calls'] > 0 && in_array( 'Referrer-Policy: no-referrer', $r['headers'], true ) );
		if ( str_starts_with( $case, 'receipt-' ) ) {
			check( $case . ': receipt remains an inline fragment', ! str_contains( $body, 'order-received' ) && ! str_contains( $body, '<!doctype' ) && 200 === $r['status'] );
			$confirmed = in_array( $case, array( 'receipt-paid', 'receipt-customer-owner', 'receipt-admin' ), true );
			check( $case . ': native applied decision controls confirmation', str_contains( $body, 'Your payment has been confirmed' ) === $confirmed );
			check( $case . ': no order-key link leads to Woo received page', ! str_contains( $body, 'order-received' ) && 0 === $r['order_received_calls'] );
			check( $case . ': only confirmed paid receipt requests scoped clear', ( $confirmed ? 1 : 0 ) === $r['cart_calls'] );
			check( $case . ': another owner never clears even with admin read access', ( in_array( $case, array( 'receipt-paid', 'receipt-customer-owner' ), true ) ? 1 : 0 ) === $r['cart_cleared'] );
			if ( $confirmed ) { check( $case . ': confirmed fragment offers safe public store home', str_contains( $body, 'href="https://www.merchant.example.com/"' ) && str_contains( $body, 'Continue shopping' ) ); }
			elseif ( in_array( $case, array( 'receipt-paid-review', 'receipt-paid-missing-attempt', 'receipt-paid-ledger-error' ), true ) ) { check( $case . ': paid native flag alone remains review', str_contains( $body, 'The store is checking this order' ) && ! str_contains( $body, 'Open the secure payment page' ) ); }
			elseif ( 'receipt-wrong-owner' === $case ) { check( 'receipt: other customer must sign in without ledger read', str_contains( $body, 'Sign in to open this payment' ) && 0 === $r['status_reads'] ); }
			elseif ( 'receipt-unpaid' === $case ) { check( 'receipt: unpaid uses existing session read without CREATE', str_contains( $body, 'https://cashier.example.com/secure' ) && 1 === $r['active_session_reads'] && 0 === $r['create_calls'] ); }
			else { check( $case . ': wrong key or binding cannot open payment', str_contains( $body, 'Unable to open this payment' ) && 0 === $r['status_reads'] && 0 === $r['active_session_reads'] ); }
		} elseif ( str_starts_with( $case, 'notify-' ) ) {
			check( $case . ': check proof is not accepted as provider notification', 400 === $r['status'] && 'notification_not_accepted' === $body );
			check( $case . ': no cart or order status read', 0 === $r['cart_calls'] && 0 === $r['read_calls'] && 0 === $r['status_reads'] );
			check( $case . ': exact notification parse boundary', ( 'notify-check' === $case ? 0 : 1 ) === $r['notification_calls'] );
		} else {
			$valid = in_array( $case, array( 'paid', 'unknown', 'failed', 'review', 'legacy-unknown' ), true );
			check( $case . ': correct response status', ( $valid ? 200 : 400 ) === $r['status'] );
			check( $case . ': HTML served without JSON dump', str_starts_with( $body, '<!doctype html>' ) && str_contains( $body, 'name="viewport"' ) && str_contains( $body, 'noindex,nofollow,noarchive' ) );
			check( $case . ': cart invoked only after paid read', ( 'paid' === $case ? 1 : 0 ) === $r['cart_calls'] );
			check( $case . ': order number only for authenticated result', str_contains( $body, 'SYNTH-42' ) === $valid );
			if ( 'paid' === $case ) { check( 'paid: confirmed HTML stays on callback', str_contains( $body, 'Payment confirmed' ) && str_contains( $body, 'Continue shopping' ) && ! str_contains( $body, 'order-received' ) ); }
			elseif ( in_array( $case, array( 'unknown', 'legacy-unknown' ), true ) ) {
				check( $case . ': pending does not claim paid or failed', str_contains( $body, 'Confirming your payment' ) && str_contains( $body, 'Check payment status' ) && ! str_contains( $body, 'Payment confirmed' ) );
				preg_match( '/href="([^"]+)"/', $body, $match ); $url = html_entity_decode( $match[1] ?? '', ENT_QUOTES, 'UTF-8' ); $parts = parse_url( $url ); $fields = array(); parse_str( $parts['query'] ?? '', $fields );
				check( $case . ': refresh checks only original public callback', 'www.merchant.example.com' === ( $parts['host'] ?? null ) && '/wc-api/pc_panapay_return/' === ( $parts['path'] ?? null ) && $reference === ( $fields['reference'] ?? null ) && $browser_token === ( $fields['token'] ?? null ) );
				if ( 'unknown' === $case ) { check( 'unknown: purpose proof preserved without provider status', \VialCove\Panapay\Return_Context::verify_check( $fields, 'synthetic-link-key' ) && ! isset( $fields['status'] ) && ! isset( $fields['transaction_id'] ) ); }
			} elseif ( 'failed' === $case ) { check( 'failed: failure page retains native checkout choice', str_contains( $body, 'Payment was not completed' ) && str_contains( $body, 'https://www.merchant.example.com/checkout/' ) && ! str_contains( $body, 'Payment confirmed' ) ); }
			elseif ( 'review' === $case ) { check( 'review: no automatic retry or success', str_contains( $body, 'Payment needs review' ) && ! str_contains( $body, 'Payment confirmed' ) && ! str_contains( $body, 'Check payment status' ) ); }
			else { check( $case . ': invalid binding gives generic HTML', str_contains( $body, 'Unable to verify this return' ) && ! str_contains( $body, 'Payment confirmed' ) && ! str_contains( $body, 'Check payment status' ) ); }
			if ( null !== $html_dir && in_array( $case, array( 'paid', 'unknown', 'failed', 'review', 'invalid-token' ), true ) ) {
				if ( ! is_dir( $html_dir ) && ! mkdir( $html_dir, 0777, true ) && ! is_dir( $html_dir ) ) { throw new \RuntimeException( 'Unable to create HTML artifact directory' ); }
				// The fixtures contain no real identity; still redact opaque synthetic
				// values so exported examples cannot be mistaken for usable pay links.
				$redacted = preg_replace( '/(token|sign)=([a-zA-Z0-9]+)/', '$1=SYNTHETIC', $body );
				$file = rtrim( $html_dir, '/\\' ) . DIRECTORY_SEPARATOR . 'return-' . $case . '-synthetic.html';
				file_put_contents( $file, $redacted ); $artifacts[] = $file;
			}
		}
		$observed[] = $r;
	}
	echo json_encode( array( 'passed' => true, 'checks' => $checks, 'scenarios' => $observed, 'html_artifacts' => $artifacts,
		'scope' => 'real Callbacks controller/rendering; synthetic Session_Service read decisions and Return_Cart boundary', 'wordpress_bootstrapped' => false, 'http' => 0, 'financial_writes' => 0 ), JSON_THROW_ON_ERROR ) . "\n";
}
