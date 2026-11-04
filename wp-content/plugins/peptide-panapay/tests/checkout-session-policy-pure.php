<?php
/** Actual HQ/Bridge/session-policy protocol with synthetic HTTP and storage boundaries only. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'PEPTIDE_HQ_URL', 'https://hq.example.invalid/' );
	final class WP_REST_Response {
		public function __construct( public $data ) {}
		public function header( ...$args ): void {}
	}
	function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
	function wp_json_encode( $value ): string { return json_encode( $value, JSON_THROW_ON_ERROR ); }
	function wp_get_environment_type(): string { return 'production'; }
	function wp_http_validate_url( string $url ) { return $url; }
	function trailingslashit( string $url ): string { return rtrim( $url, '/' ) . '/'; }
	function get_option( string $name, $default = false ) { return $default; }
	function add_filter( ...$args ): void {}
	function remove_filter( ...$args ): void {}
	function is_wp_error( $result ): bool { return false; }
	function wp_remote_retrieve_response_code( array $result ): int { return $result['code']; }
	function wp_remote_retrieve_body( array $result ): string { return $result['body']; }
	function add_query_arg( string $key, string $value, string $url ): string { return $url . '?' . http_build_query( array( $key => $value ) ); }
	function wp_safe_remote_post( string $url, array $args ): array {
		$fields = array(); parse_str( parse_url( $url, PHP_URL_QUERY ), $fields );
		$route = $fields['rest_route'] ?? '';
		if ( ! in_array( $route, array( \VialCove\Panapay\Hub_Protocol::CREATE, \VialCove\Panapay\Hub_Protocol::STATUS ), true ) ) { throw new \RuntimeException( 'Unexpected synthetic HTTP route' ); }
		$GLOBALS['calls'][] = array( 'route' => $route, 'body' => json_decode( $args['body'], true, 20, JSON_THROW_ON_ERROR ), 'args' => $args );
		$nonce = $args['headers']['X-PC-Pay-Nonce'];
		$data = $GLOBALS['transport_data'];
		$mode = $GLOBALS['transport_mode'];
		if ( 'signed-foreign-reference' === $mode ) { $data['checkout_policy']['reference'] = $GLOBALS['other_reference']; }
		if ( 'signed-foreign-account' === $mode ) { $data['account_id'] = str_repeat( 'b', 32 ); }
		if ( 'legacy' === $mode ) { unset( $data['checkout_policy'] ); }
		$envelope = \VialCove\Panapay\Hub_Protocol::response( $data, $nonce, $GLOBALS['link_key'] )->data;
		$envelope = json_decode( json_encode( $envelope, JSON_THROW_ON_ERROR ), true, 20, JSON_THROW_ON_ERROR );
		if ( 'tamper-policy' === $mode ) { $envelope['data']['checkout_policy']['payment_hosts'][] = 'other.example.invalid'; }
		if ( 'tamper-url' === $mode ) { $envelope['data']['url'] = 'https://other.example.invalid/'; }
		if ( 'wrong-nonce' === $mode ) { $envelope['nonce'] = str_repeat( '0', 32 ); }
		if ( 'wrong-key' === $mode ) { $envelope['sign'] = str_repeat( '0', 64 ); }
		return array( 'code' => 'http-error' === $mode ? 503 : 200, 'body' => json_encode( $envelope, JSON_THROW_ON_ERROR ) );
	}
}
namespace PeptideCore {
	final class Hq_Connector { public static function settings(): array { return array( 'key' => $GLOBALS['link_key'], 'uuid' => '11111111-1111-4111-8111-111111111111' ); } }
}
namespace VialCove\Panapay {
	final class Crypto {
		public static function decrypt( string $box, string $purpose ): string {
			$GLOBALS['decryptions'][] = $purpose;
			return $box;
		}
	}
	final class Hub_Accounts {
		public static function load( string $account ): array { ++$GLOBALS['current_account_reads']; return $GLOBALS['current_account']; }
	}
	final class Hub_Store { public static function table( string $name ): string { return 'synthetic_' . $name; } }
	final class Gateway {
		public const ID = 'pc_panapay';
		public static function valid_account_id( string $id ): bool { return 'default' === $id || 1 === preg_match( '/^[a-f0-9]{32}$/D', $id ); }
		public static function native_id( string $id ): string { return 'default' === $id ? self::ID : self::ID . '_' . $id; }
	}
	final class Database {
		public static function open(): object {
			return new class {
				public string $last_error = '';
				public function prepare( string $sql, ...$args ): string { return $sql; }
				public function get_var( string $sql ) { return $GLOBALS['has_success'] ? 'synthetic-success' : null; }
				public function close(): void {}
			};
		}
	}
}
namespace {
	foreach ( array( 'protocol-error', 'configuration', 'hub-protocol', 'session-service', 'bridge', 'hub' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
	use VialCove\Panapay\{Bridge, Configuration, Gateway, Hub, Hub_Protocol, Protocol_Error, Session_Service};
	$checks = 0; $calls = array(); $decryptions = array(); $current_account_reads = 0; $has_success = false;
	$link_key = str_repeat( 'e', 64 ); $transport_mode = 'valid';
	$account = str_repeat( 'a', 32 );
	$reference = 'PP12345678-42-' . str_repeat( 'c', 32 );
	$other_reference = 'PP12345678-43-' . str_repeat( 'd', 32 );
	$url = 'https://cashier.example.invalid/pay?synthetic-token=fixture';
	$policy = array( 'version' => 1, 'reference' => $reference, 'payment_hosts' => array( 'cashier.example.invalid', 'gateway.example.invalid' ) );
	$session = array( 'state' => 'created', 'account_id' => $account, 'type' => 'redirect', 'url' => $url, 'checkout_policy' => $policy );
	$transport_data = $session;
	$current_account = array( 'gateway' => 'https://current.example.invalid', 'payment_hosts' => array( 'current.example.invalid' ) );
	$host_cache = new \ReflectionProperty( Configuration::class, 'public_hosts' );
	$host_cache->setValue( null, array_fill_keys( array( 'hq.example.invalid', 'cashier.example.invalid', 'gateway.example.invalid', 'other.example.invalid', 'current.example.invalid' ), true ) );
	$parse = new \ReflectionMethod( Bridge::class, 'session' );
	$hq_session = new \ReflectionMethod( Hub::class, 'session' );
	function check( string $label, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new \RuntimeException( 'FAIL: ' . $label ); } }
	function rejects( callable $call ): bool { try { $call(); return false; } catch ( Protocol_Error $error ) { return 503 === $error->getCode() && 'hub_session_invalid' === $error->getMessage(); } }
	function parse_session( array $value, ?string $expected_account = null, ?string $expected_reference = null ): array {
		return $GLOBALS['parse']->invoke( null, $value, $expected_account ?? $GLOBALS['account'], $expected_reference ?? $GLOBALS['reference'] );
	}
	$parsed = parse_session( $session );
	check( 'signed policy retained exactly, no URL-derived hosts', $policy === $parsed['checkout_policy'] && $url === $parsed['url'] );
	check( 'created iframe uses same authenticated trust as redirect', $policy === parse_session( array_replace( $session, array( 'type' => 'iframe' ) ) )['checkout_policy'] );
	$legacy = $session; unset( $legacy['checkout_policy'] );
	check( 'old HQ response remains compatible without manufacturing a policy', array( 'state' => 'created', 'type' => 'redirect', 'url' => $url ) === parse_session( $legacy ) );
	check( 'default account retains explicit reference-bound policy', $policy === parse_session( array_replace( $session, array( 'account_id' => 'default' ) ), 'default' )['checkout_policy'] );
	check( 'cross-account policy cannot switch the selected gateway', rejects( static fn() => parse_session( $session, str_repeat( 'b', 32 ) ) ) );
	check( 'cross-reference policy cannot switch order or attempt', rejects( static fn() => parse_session( $session, null, $other_reference ) ) );
	foreach ( array( null, false, '', array(), array( 'version' => 1 ), array_replace( $policy, array( 'version' => '1' ) ), array_replace( $policy, array( 'version' => 2 ) ), array_replace( $policy, array( 'reference' => false ) ), array_merge( $policy, array( 'gateway' => 'https://other.example.invalid' ) ) ) as $bad ) {
		check( 'malformed policy rejected ' . $checks, rejects( static fn() => parse_session( array_replace( $session, array( 'checkout_policy' => $bad ) ) ) ) );
	}
	foreach ( array( null, false, 'cashier.example.invalid', array(), array( 1 => 'cashier.example.invalid' ), array( 'host' => 'cashier.example.invalid' ), array( 'cashier.example.invalid', 'cashier.example.invalid' ), array_fill( 0, 33, 'cashier.example.invalid' ) ) as $bad ) {
		$bad_policy = array_replace( $policy, array( 'payment_hosts' => $bad ) );
		check( 'malformed hosts list rejected ' . $checks, rejects( static fn() => parse_session( array_replace( $session, array( 'checkout_policy' => $bad_policy ) ) ) ) );
	}
	foreach ( array( '', true, 1, null, 'CASHIER.example.invalid', ' cashier.example.invalid', 'cashier.example.invalid ', 'https://cashier.example.invalid', '*.example.invalid', 'cashier.example.invalid/path', 'cashier.example.invalid:443', '127.0.0.1', '[::1]', 'localhost', '-bad.example.invalid', 'cashier..example.invalid', 'cashier.example.invalid.', str_repeat( 'a', 254 ) . '.invalid' ) as $bad_host ) {
		$bad_policy = array_replace( $policy, array( 'payment_hosts' => array( 'cashier.example.invalid', $bad_host ) ) );
		check( 'non-exact host rejected ' . $checks, rejects( static fn() => parse_session( array_replace( $session, array( 'checkout_policy' => $bad_policy ) ) ) ) );
	}
	$foreign_policy = array_replace( $policy, array( 'payment_hosts' => array( 'other.example.invalid' ) ) );
	check( 'payment URL cannot self-enroll in a signed unrelated host list', rejects( static fn() => parse_session( array_replace( $session, array( 'checkout_policy' => $foreign_policy ) ) ) ) );
	foreach ( array( 'http://cashier.example.invalid/', 'https://cashier.example.invalid:444/', 'https://user@cashier.example.invalid/', 'https://cashier.example.invalid.attacker.invalid/', "https://cashier.example.invalid/\n", 'https://127.0.0.1/' ) as $bad_url ) {
		check( 'unsafe or unlisted URL rejected ' . $checks, rejects( static fn() => parse_session( array_replace( $session, array( 'url' => $bad_url ) ) ) ) );
	}
	$host_cache->setValue( null, array( 'cashier.example.invalid' => false ) );
	check( 'a signed host does not bypass public-address verification', rejects( static fn() => parse_session( $session ) ) );
	$host_cache->setValue( null, array_fill_keys( array( 'hq.example.invalid', 'cashier.example.invalid', 'gateway.example.invalid', 'other.example.invalid', 'current.example.invalid' ), true ) );
	foreach ( array( 'creating', 'unknown', 'failed' ) as $state ) {
		check( 'noncreated response may not carry new policy ' . $state, rejects( static fn() => parse_session( array_replace( $session, array( 'state' => $state ) ) ) ) );
		$bare = array( 'state' => $state, 'account_id' => $account );
		check( 'noncreated legacy response keeps state semantics ' . $state, array( 'state' => 'failed' === $state ? 'failed' : 'unknown' ) === parse_session( $bare ) );
	}
	$row = array( 'site_id' => 7, 'site_uuid' => '11111111-1111-4111-8111-111111111111', 'order_id' => 42, 'account_id' => $account, 'local_reference' => $reference, 'state' => 'created', 'checkout_box' => $url,
		'hosts_box' => json_encode( array( 'gateway' => 'https://gateway.example.invalid', 'payment_hosts' => array( 'cashier.example.invalid' ) ), JSON_THROW_ON_ERROR ), 'type' => 'redirect', 'test_mode' => 0 );
	$hq = $hq_session->invoke( null, $row );
	check( 'HQ returns original frozen hosts plus original implicit gateway host', $policy === $hq['checkout_policy'] );
	check( 'HQ policy is bound to stored local reference', $reference === $hq['checkout_policy']['reference'] );
	check( 'HQ does not read current account to construct a frozen policy', 0 === $current_account_reads );
	check( 'actual HQ envelope can be consumed by actual Bridge parser', $policy === parse_session( $hq )['checkout_policy'] );
	$dedup_row = array_replace( $row, array( 'hosts_box' => json_encode( array( 'gateway' => 'https://gateway.example.invalid', 'payment_hosts' => $policy['payment_hosts'] ), JSON_THROW_ON_ERROR ) ) );
	check( 'implicit gateway is not duplicated', $policy === $hq_session->invoke( null, $dedup_row )['checkout_policy'] );
	$gateway_only = array_replace( $row, array( 'checkout_box' => 'https://gateway.example.invalid/pay', 'hosts_box' => json_encode( array( 'gateway' => 'https://gateway.example.invalid', 'payment_hosts' => array() ), JSON_THROW_ON_ERROR ) ) );
	check( 'empty explicit list retains original implicit gateway trust', array( 'gateway.example.invalid' ) === $hq_session->invoke( null, $gateway_only )['checkout_policy']['payment_hosts'] );
	$removed = array_replace( $row, array( 'hosts_box' => json_encode( array( 'gateway' => 'https://gateway.example.invalid', 'payment_hosts' => array( 'other.example.invalid' ) ), JSON_THROW_ON_ERROR ) ) );
	$unavailable = $hq_session->invoke( null, $removed );
	check( 'HQ does not enroll returned cashier outside frozen trust', 'unknown' === $unavailable['state'] && ! isset( $unavailable['url'], $unavailable['checkout_policy'] ) );
	$has_success = true;
	$completed = $hq_session->invoke( null, $row );
	check( 'HQ success fence cannot expose checkout or policy', array( 'state' => 'unknown', 'account_id' => $account ) === $completed );
	$has_success = false;
	$payload = array( 'order_id' => $reference, 'mchid' => 'must-not-forward', 'tag' => 'must-not-forward', 'mode' => '1', 'sign' => 'must-not-forward', 'currency' => 'USD', 'amount' => '1.00' );
	$transport_data = $session; $transport_mode = 'valid'; $calls = array();
	$created = Bridge::create( $payload, Hub_Protocol::event_key( $link_key ), $account );
	check( 'real Bridge CREATE carries the signed frozen policy despite stale display policy', $policy === $created['checkout_policy'] );
	check( 'CREATE issued once and never retries provider indirectly', 1 === count( $calls ) && Hub_Protocol::CREATE === $calls[0]['route'] );
	check( 'selected account and original local reference retained in request', $account === $calls[0]['body']['account_id'] && $reference === $calls[0]['body']['order_id'] );
	check( 'merchant-only credentials and routing values do not leak from store request', ! array_intersect( array( 'mchid', 'tag', 'mode', 'sign' ), array_keys( $calls[0]['body'] ) ) );
	check( 'authenticated transport keeps TLS verification and disables redirect following', true === $calls[0]['args']['sslverify'] && 0 === $calls[0]['args']['redirection'] );
	$calls = array(); $recovered = Bridge::recover( $reference, $account );
	check( 'STATUS returns same exact authenticated policy', $policy === $recovered['checkout_policy'] );
	check( 'STATUS is a single read request not a new CREATE', 1 === count( $calls ) && Hub_Protocol::STATUS === $calls[0]['route'] && array( 'reference' => $reference ) === $calls[0]['body'] );
	foreach ( array( 'tamper-policy', 'tamper-url', 'wrong-nonce', 'wrong-key', 'http-error', 'signed-foreign-reference', 'signed-foreign-account' ) as $mode ) {
		$transport_mode = $mode; $calls = array();
		check( 'CREATE refuses altered or misbound policy: ' . $mode, array( 'state' => 'unknown' ) === Bridge::create( $payload, Hub_Protocol::event_key( $link_key ), $account ) && 1 === count( $calls ) );
		$calls = array();
		check( 'STATUS refuses altered or misbound policy: ' . $mode, array( 'state' => 'unknown' ) === Bridge::recover( $reference, $account ) && 1 === count( $calls ) );
	}
	$transport_mode = 'legacy'; $calls = array();
	$old_result = Bridge::create( $payload, Hub_Protocol::event_key( $link_key ), $account );
	check( 'rolling legacy HQ response remains a created session without expanded policy', 'created' === $old_result['state'] && ! isset( $old_result['checkout_policy'] ) && 1 === count( $calls ) );
	$transport_mode = 'valid'; $calls = array();
	check( 'connector key mismatch fails before any CREATE request', array( 'state' => 'failed' ) === Bridge::create( $payload, str_repeat( 'f', 64 ), $account ) && array() === $calls );
	$bind = new \ReflectionMethod( Session_Service::class, 'bind_checkout_policy' );
	$needs_policy = new \ReflectionMethod( Session_Service::class, 'needs_checkout_policy' );
	$attempt = array( 'reference' => $reference, 'state' => 'creating', 'central' => true, 'account_id' => $account, 'gateway_id' => Gateway::native_id( $account ),
		'payment_hosts' => array( 'old.example.invalid' ), 'test_mode' => false, 'secret_box' => 'synthetic-unchanged-secret-box', 'creation_reservation' => array( 'owner' => 'synthetic-owner' ) );
	$bound = $bind->invoke( null, $attempt, $parsed );
	$expected_bound = array_replace( $attempt, array( 'payment_hosts' => $policy['payment_hosts'], 'checkout_policy_version' => 1 ) );
	check( 'store binder replaces stale cache with exact session policy', $expected_bound === $bound );
	check( 'binder does not alter merchant secret, test flag, reference, gateway or reservation', array_diff_key( $attempt, array_flip( array( 'payment_hosts' ) ) ) === array_diff_key( $bound, array_flip( array( 'payment_hosts', 'checkout_policy_version' ) ) ) );
	check( 'legacy response cannot change frozen store trust', $attempt === $bind->invoke( null, $attempt, array( 'state' => 'created', 'type' => 'redirect', 'url' => $url ) ) );
	foreach ( array( array_replace( $parsed, array( 'checkout_policy' => null ) ), array_replace( $parsed, array( 'state' => 'unknown' ) ), array_replace( $parsed, array( 'url' => 'https://other.example.invalid/' ) ) ) as $bad_response ) {
		check( 'binder independently rejects malformed or unusable policy ' . $checks, rejects( static fn() => $bind->invoke( null, $attempt, $bad_response ) ) );
	}
	$foreign_response = $parsed; $foreign_response['checkout_policy']['reference'] = $other_reference;
	check( 'binder independently enforces exact local attempt reference', rejects( static fn() => $bind->invoke( null, $attempt, $foreign_response ) ) );
	check( 'direct-provider attempt cannot adopt a central policy', rejects( static fn() => $bind->invoke( null, array_replace( $attempt, array( 'central' => false ) ), $parsed ) ) );
	$old_created = array_replace( $attempt, array( 'state' => 'created', 'url_box' => $url, 'type' => 'redirect' ) );
	check( 'legacy created attempt with rejected saved URL requests policy repair', true === $needs_policy->invoke( null, $old_created ) );
	check( 'already authenticated policy never triggers repeated repair', false === $needs_policy->invoke( null, array_replace( $old_created, array( 'checkout_policy_version' => 1 ) ) ) );
	check( 'invalid marker does not silently authorize policy replacement', false === $needs_policy->invoke( null, array_replace( $old_created, array( 'checkout_policy_version' => false ) ) ) );
	check( 'already usable old frozen trust does not follow current account changes', false === $needs_policy->invoke( null, array_replace( $old_created, array( 'payment_hosts' => $policy['payment_hosts'] ) ) ) );
	foreach ( array( 'unknown', 'creating', 'failed' ) as $state ) {
		check( 'policy-only repair is limited to created attempts ' . $state, false === $needs_policy->invoke( null, array_replace( $old_created, array( 'state' => $state ) ) ) );
	}
	check( 'created attempt without URL is not a policy-only repair', false === $needs_policy->invoke( null, array_diff_key( $old_created, array( 'url_box' => true ) ) ) );
	check( 'legacy direct-provider attempt never requests central policy repair', false === $needs_policy->invoke( null, array_replace( $old_created, array( 'central' => false ) ) ) );
	echo json_encode( array( 'status' => 'passed', 'checks' => $checks, 'protocol' => 'actual Hub/Bridge/HMAC and URL validator', 'transport' => 'synthetic-only', 'database' => 'read-only in-memory boundary', 'real_http' => 0, 'financial_writes' => 0 ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}
