<?php
/** The HQ owns provider credentials, session creation and durable result delivery. */
namespace VialCove\Panapay;
defined( 'ABSPATH' ) || exit;

final class Hub {
	public const CRON = 'pc_panapay_hub_relay';
	private static array $auth = array();
	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_notification' ), 10, 4 );
		add_filter( 'cron_schedules', static function ( $s ) { $s['pc_pay_minute'] = array( 'interval' => 60, 'display' => 'Payment result delivery' ); return $s; } );
		add_action( self::CRON, array( self::class, 'retry_pending' ) );
		add_action( 'admin_init', static function () {
			if ( current_user_can( 'manage_options' ) && ! Hub_Store::installed() ) { Hub_Store::install(); }
		} );
		if ( Hub_Store::installed() && ! wp_next_scheduled( self::CRON ) ) { wp_schedule_event( time() + 60, 'pc_pay_minute', self::CRON ); }
	}
	public static function routes(): void {
		foreach ( array( '/hub/config' => 'configuration', '/hub/methods' => 'methods', '/hub/session' => 'create', '/hub/session/status' => 'status' ) as $route => $method ) {
			register_rest_route( Hub_Protocol::NS, $route, array( 'methods' => 'POST', 'permission_callback' => array( self::class, 'permission' ), 'callback' => array( self::class, $method ) ) );
		}
		register_rest_route( Hub_Protocol::NS, '/hub/notify', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( self::class, 'notify' ) ) );
		register_rest_route( Hub_Protocol::NS, '/hub/return', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( self::class, 'returned' ) ) );
	}
	public static function permission( \WP_REST_Request $request ) {
		try {
			if ( ! Hub_Store::installed() ) { throw new Protocol_Error( 'payment_center_unavailable', 503 ); }
			$uuid = (string) $request->get_header( 'x_pc_pay_store' );
			if ( ! preg_match( '/^[a-zA-Z0-9_-]{16,64}$/D', $uuid ) ) { throw new Protocol_Error( 'store_not_authorized', 401 ); }
			$matches = array_filter( \PeptideHq\Sites::all(), static fn( $s ) => $s['uuid'] === $uuid );
			if ( count( $matches ) !== 1 ) { throw new Protocol_Error( 'store_not_authorized', 401 ); }
			$site = reset( $matches ); $key = \PeptideHq\Sites::key( $site['id'] );
			$verified = Hub_Protocol::verify_request( $request, $key, $uuid );
			self::$auth[ spl_object_id( $request ) ] = array( 'site' => $site, 'key' => $key, 'nonce' => $verified['nonce'] );
			return true;
		} catch ( \Throwable $e ) { return self::error( $e ); }
	}
	private static function auth( \WP_REST_Request $request ): array {
		$a = self::$auth[ spl_object_id( $request ) ] ?? null;
		if ( ! is_array( $a ) ) { throw new Protocol_Error( 'store_not_authorized', 401 ); }
		return $a;
	}
	private static function error( \Throwable $e ): \WP_Error {
		$status = $e instanceof Protocol_Error ? $e->getCode() : 503;
		if ( ! in_array( $status, array( 400, 401, 403, 404, 409, 413, 503 ), true ) ) { $status = 503; }
		return new \WP_Error( 'payment_center_unavailable', 'Payment request could not be accepted. Please contact the store before paying again.', array( 'status' => $status ) );
	}
	public static function configuration( \WP_REST_Request $request ) {
		try { $a = self::auth( $request ); return Hub_Protocol::response( Hub_Accounts::legacy_policy( $a['site']['id'] ), $a['nonce'], $a['key'] ); }
		catch ( \Throwable $e ) { return self::error( $e ); }
	}
	public static function methods( \WP_REST_Request $request ) {
		try { $a = self::auth( $request ); return Hub_Protocol::response( Hub_Accounts::policy( $a['site']['id'] ), $a['nonce'], $a['key'] ); }
		catch ( \Throwable $e ) { return self::error( $e ); }
	}
	private static function account_id( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^(?:default|[a-f0-9]{32})$/D', $value ) ) { throw new Protocol_Error( 'invalid_payment_account', 400 ); }
		return $value;
	}
	private static function payload( array $p, array $site ): array {
		$allowed = array( 'order_id', 'currency', 'amount', 'email', 'phone', 'ip', 'accept_language', 'user_agent', 'return_url', 'products' );
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( array( 'firstname', 'lastname', 'company', 'address_1', 'address_2', 'postcode', 'city', 'state', 'country' ) as $field ) { $allowed[] = $type . '_' . $field; }
		}
		$p = array_intersect_key( $p, array_flip( $allowed ) );
		foreach ( $allowed as $key ) {
			if ( 'products' === $key ) { continue; }
			if ( ! is_string( $p[ $key ] ?? null ) || strlen( $p[ $key ] ) > ( 'return_url' === $key ? 4096 : 1024 ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $p[ $key ] ) ) { throw new Protocol_Error( 'invalid_order', 400 ); }
		}
		Session_Service::parse_reference( $p['order_id'] );
		if ( ! preg_match( '/^[A-Z]{3}$/D', $p['currency'] ) || ! preg_match( '/^[0-9]{1,14}(?:\.[0-9]{1,8})?$/D', $p['amount'] )
			|| (float) $p['amount'] <= 0 || ! is_email( $p['email'] ) || '' === trim( $p['phone'] ) ) { throw new Protocol_Error( 'invalid_order', 400 ); }
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( array( 'firstname', 'lastname', 'address_1', 'city', 'country' ) as $field ) {
				if ( '' === trim( $p[ $type . '_' . $field ] ) ) { throw new Protocol_Error( 'invalid_order', 400 ); }
			}
			if ( ! preg_match( '/^[A-Z]{2}$/D', $p[ $type . '_country' ] ) ) { throw new Protocol_Error( 'invalid_order', 400 ); }
		}
		$base = Hub_Protocol::base_url( $site['url'] ); $r = wp_parse_url( $p['return_url'] ); $b = wp_parse_url( $base );
		if ( ! is_array( $r ) || isset( $r['user'] ) || isset( $r['pass'] ) || isset( $r['fragment'] )
			|| ( $r['scheme'] ?? '' ) !== $b['scheme'] || strtolower( $r['host'] ?? '' ) !== strtolower( $b['host'] ) || ( $r['port'] ?? null ) !== ( $b['port'] ?? null )
			|| ! str_starts_with( $r['path'] ?? '/', $b['path'] ?? '/' ) || str_contains( rawurldecode( $r['path'] ?? '' ), '..' ) ) { throw new Protocol_Error( 'invalid_return_target', 400 ); }
		if ( ! is_array( $p['products'] ?? null ) || ! $p['products'] || count( $p['products'] ) > 100 ) { throw new Protocol_Error( 'invalid_order', 400 ); }
		foreach ( $p['products'] as &$item ) {
			if ( ! is_array( $item ) ) { throw new Protocol_Error( 'invalid_order', 400 ); }
			$item = array_intersect_key( $item, array_flip( array( 'name', 'quantity', 'price', 'total', 'image', 'url' ) ) );
			foreach ( array( 'name', 'price', 'total', 'image', 'url' ) as $key ) {
				if ( ! is_string( $item[ $key ] ?? null ) || strlen( $item[ $key ] ) > 2048 ) { throw new Protocol_Error( 'invalid_order', 400 ); }
			}
			foreach ( array( 'price', 'total' ) as $key ) { if ( ! preg_match( '/^[0-9]{1,14}(?:\.[0-9]{1,8})?$/D', $item[ $key ] ) ) { throw new Protocol_Error( 'invalid_order', 400 ); } }
			if ( ! is_numeric( $item['quantity'] ?? null ) || ! is_finite( (float) $item['quantity'] ) || $item['quantity'] <= 0 || $item['quantity'] > 100000 ) { throw new Protocol_Error( 'invalid_order', 400 ); }
		} unset( $item );
		return $p;
	}
	public static function create( \WP_REST_Request $request ) {
		try {
			$a = self::auth( $request ); $site = $a['site']; $input = $request->get_json_params();
			if ( ! is_array( $input ) ) { throw new Protocol_Error( 'invalid_order', 400 ); }
			$account = self::account_id( array_key_exists( 'account_id', $input ) ? $input['account_id'] : 'default' );
			try { $config = Hub_Accounts::load( $account ); }
			catch ( \InvalidArgumentException $error ) { throw new Protocol_Error( 'store_payment_disabled', 403 ); }
			$allowed = $config['all_sites'] || in_array( $site['id'], $config['sites'], true );
			if ( ! $allowed || ! $config['enabled'] || $config['archived'] || '' === $config['gateway'] || '' === $config['mchid'] || '' === $config['secret'] ) { throw new Protocol_Error( 'store_payment_disabled', 403 ); }
			if ( ( $config['test_mode'] && 'production' === wp_get_environment_type() ) || ( ! $config['test_mode'] && ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) || 'https' !== wp_parse_url( $site['url'], PHP_URL_SCHEME ) ) ) ) { throw new Protocol_Error( 'environment_not_ready', 503 ); }
			$p = self::payload( $input, $site );
			$reference = $p['order_id']; $parsed = Session_Service::parse_reference( $reference );
			$legacy_hash = hash( 'sha256', Hub_Protocol::json( $p ) );
			$hash = hash( 'sha256', Hub_Protocol::json( array( 'account_id' => $account, 'payload' => $p ) ) );
			// A completed central order can never acquire a new provider session.
			$summary = self::has_success( $site['id'], $site['uuid'], $parsed['order_id'] );
			if ( $summary ) { throw new Protocol_Error( 'order_already_confirmed', 409 ); }
			$ref = 'PH' . bin2hex( random_bytes( 16 ) ); $token = bin2hex( random_bytes( 24 ) );
			$record = array( 'ref' => $ref, 'site_id' => $site['id'], 'site_uuid' => $site['uuid'], 'order_id' => $parsed['order_id'], 'local_reference' => $reference, 'account_id' => $account,
				'request_hash' => $hash, 'state' => 'creating', 'owner_token' => bin2hex( random_bytes( 16 ) ), 'amount' => $p['amount'], 'currency' => $p['currency'],
				'secret_box' => Crypto::encrypt( $config['secret'], 'hub-provider' ), 'link_key_box' => Crypto::encrypt( Hub_Protocol::event_key( $a['key'] ), 'hub-link' ),
				'scope_hash' => hash( 'sha256', $config['gateway'] . '|' . $config['mchid'] ), 'checkout_box' => '',
				'hosts_box' => Crypto::encrypt( Hub_Protocol::json( array( 'gateway' => $config['gateway'], 'payment_hosts' => $config['payment_hosts'] ) ), 'hub-hosts' ),
				'return_box' => Crypto::encrypt( $p['return_url'], 'hub-return' ),
				'return_token_hash' => hash( 'sha256', $token ), 'type' => '', 'test_mode' => $config['test_mode'] ? 1 : 0, 'created_at' => time(), 'updated_at' => time() );
			$reserved = Hub_Store::reserve( $record, $legacy_hash );
			if ( $reserved['winner'] ) {
				$p['site'] = $site['url']; $p['order_id'] = $ref;
				$p['notify_url'] = add_query_arg( 'rest_route', '/' . Hub_Protocol::NS . '/hub/notify', home_url( '/' ) );
				$p['return_url'] = add_query_arg( array( 'rest_route' => '/' . Hub_Protocol::NS . '/hub/return', 'reference' => $ref, 'token' => $token ), home_url( '/' ) );
				$p['mchid'] = $config['mchid']; $p['tag'] = $config['tag']; $p['mode'] = $config['mode']; $p['platform_id'] = 6;
				$p['sign'] = Signature::generate( array_intersect_key( $p, array_flip( array( 'mchid', 'currency', 'amount', 'site', 'order_id' ) ) ), $config['secret'] );
				$response = Provider_Client::create( $p, $config );
				Hub_Store::finish_create( $record, $response );
			}
			$row = Hub_Store::find( $site['id'], $reference );
			return Hub_Protocol::response( self::session( $row ), $a['nonce'], $a['key'] );
		} catch ( \Throwable $e ) { return self::error( $e ); }
	}
	private static function has_success( int $site, string $uuid, int $order ): bool {
		$db = Database::open(); $a = Hub_Store::table( 'attempts' ); $e = Hub_Store::table( 'events' );
		try {
			$v = $db->get_var( $db->prepare( "SELECT e.event_id FROM {$e} e INNER JOIN {$a} a ON a.ref=e.ref WHERE a.site_id=%d AND a.site_uuid=%s AND a.order_id=%d AND e.status='SUCCEEDED' LIMIT 1", $site, $uuid, $order ) );
			if ( '' !== (string) $db->last_error ) { throw new \RuntimeException( 'hub_storage_unavailable' ); }
			return $v !== null;
		} finally { $db->close(); }
	}
	private static function session( ?array $row ): array {
		if ( ! $row ) { throw new Protocol_Error( 'payment_not_found', 404 ); }
		$account = self::account_id( $row['account_id'] ?? 'default' );
		if ( self::has_success( (int) $row['site_id'], $row['site_uuid'], (int) $row['order_id'] ) ) { return array( 'state' => 'unknown', 'account_id' => $account ); }
		$result = array( 'state' => $row['state'], 'account_id' => $account );
		if ( 'created' === $row['state'] ) {
			$url = Crypto::decrypt( $row['checkout_box'], 'hub-checkout' );
			if ( Configuration::payment_url( $url, self::checkout_trust( $row ) ) && ! ( $row['test_mode'] && 'production' === wp_get_environment_type() ) ) {
				$result['type'] = $row['type']; $result['url'] = $url;
			} else { $result['state'] = 'unknown'; }
		}
		return $result;
	}
	private static function checkout_trust( array $row ): array {
		if ( is_string( $row['hosts_box'] ?? null ) && '' !== $row['hosts_box'] ) {
			$trust = json_decode( Crypto::decrypt( $row['hosts_box'], 'hub-hosts' ), true, 8, JSON_THROW_ON_ERROR );
			if ( ! is_array( $trust ) || array_diff( array_keys( $trust ), array( 'gateway', 'payment_hosts' ) )
				|| ! is_string( $trust['gateway'] ?? null ) || ! is_array( $trust['payment_hosts'] ?? null ) ) { throw new \RuntimeException( 'hub_checkout_trust_unavailable' ); }
			return $trust;
		}
		if ( 'default' !== self::account_id( $row['account_id'] ?? 'default' ) ) { throw new \RuntimeException( 'hub_checkout_trust_unavailable' ); }
		// Only singleton attempts created before schema 3 may lack a host snapshot.
		return Hub_Accounts::load( 'default' );
	}
	public static function status( \WP_REST_Request $request ) {
		try {
			$a = self::auth( $request ); $p = $request->get_json_params(); $ref = $p['reference'] ?? null;
			if ( ! is_string( $ref ) ) { throw new Protocol_Error( 'invalid_reference', 400 ); }
			$row = Hub_Store::find( $a['site']['id'], $ref );
			if ( ! $row || ! hash_equals( $row['site_uuid'], $a['site']['uuid'] ) ) { throw new Protocol_Error( 'payment_not_found', 404 ); }
			return Hub_Protocol::response( self::session( $row ), $a['nonce'], $a['key'] );
		} catch ( \Throwable $e ) { return self::error( $e ); }
	}
	public static function notify( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			if ( strlen( $request->get_body() ) > 8192 ) { throw new Protocol_Error( 'invalid_notification', 413 ); }
			$p = $request->get_body_params();
			foreach ( array( 'order_number', 'status', 'transaction_id', 'sign' ) as $key ) { if ( ! is_string( $p[ $key ] ?? null ) || strlen( $p[ $key ] ) > 512 ) { throw new Protocol_Error( 'invalid_notification', 400 ); } }
			if ( ! in_array( $p['status'], array( 'SUCCEEDED', 'FAILED' ), true ) || ! preg_match( '/^[\x21-\x7e]{0,512}$/D', $p['transaction_id'] ) || ( 'SUCCEEDED' === $p['status'] && '' === $p['transaction_id'] ) ) { throw new Protocol_Error( 'invalid_notification', 400 ); }
			$row = Hub_Store::get( $p['order_number'] );
			if ( ! $row || ( $row['test_mode'] && 'production' === wp_get_environment_type() ) || ! Signature::verify( array_intersect_key( $p, array_flip( array( 'order_number', 'status', 'transaction_id' ) ) ), $p['sign'], Crypto::decrypt( $row['secret_box'], 'hub-provider' ) ) ) { throw new Protocol_Error( 'invalid_notification', 400 ); }
			Hub_Store::receive( $row, $p['status'], $p['transaction_id'] );
			// Cron/system scheduler reads the durable outbox; no outbound call while returning vendor ACK.
			return new \WP_REST_Response( 'ok', 200 );
		} catch ( \Throwable $e ) { return new \WP_REST_Response( 'notification_not_accepted', $e instanceof Protocol_Error ? $e->getCode() : 503 ); }
	}
	public static function serve_notification( $served, $result, $request, $server ) {
		if ( '/' . Hub_Protocol::NS . '/hub/notify' !== $request->get_route() ) { return $served; }
		if ( ! headers_sent() ) { header( 'Content-Type: text/plain; charset=UTF-8' ); header( 'Cache-Control: no-store, private' ); header( 'X-Robots-Tag: noindex, nofollow' ); }
		$data = $result->get_data(); echo is_string( $data ) ? $data : 'notification_not_accepted';
		return true;
	}
	public static function retry_pending( int $limit = 10 ): int {
		$count = 0;
		foreach ( Hub_Store::pending( $limit ) as $candidate ) {
			$event = Hub_Store::claim_delivery( $candidate['event_id'] ); if ( ! $event ) { continue; } ++$count;
			try {
				$row = Hub_Store::get( $event['ref'] ); $site = $row ? \PeptideHq\Sites::get( (int) $row['site_id'] ) : null;
				if ( ! $row || ! $site || ! hash_equals( $row['site_uuid'], $site['uuid'] ) ) { Hub_Store::delivery_result( $event['event_id'], 'review', 'review', 'store_identity_changed' ); continue; }
				$transaction = Crypto::decrypt( $event['transaction_box'], 'hub-transaction' );
				$data = array( 'order_number' => $row['local_reference'], 'status' => $event['status'], 'transaction_id' => $transaction );
				$data['sign'] = Signature::generate( $data, Crypto::decrypt( $row['link_key_box'], 'hub-link' ) ); $data['event_id'] = $event['event_id'];
				$data['account_id'] = self::account_id( $row['account_id'] ?? 'default' );
				$result = Hub_Protocol::request( $site['url'], \PeptideHq\Sites::key( $site['id'] ), $site['uuid'], Hub_Protocol::EVENT, $data, 15 );
				if ( true !== ( $result['accepted'] ?? null ) || ! in_array( $result['disposition'] ?? '', array( 'paid', 'failed', 'review' ), true ) ) { throw new \RuntimeException( 'store_not_confirmed' ); }
				Hub_Store::delivery_result( $event['event_id'], 'delivered', $result['disposition'] );
			} catch ( \Throwable $e ) { Hub_Store::delivery_result( $event['event_id'], 'pending', '', 'store_unavailable' ); }
		}
		return $count;
	}
	public static function returned( \WP_REST_Request $request ) {
		try {
			$p = $request->get_query_params();
			foreach ( array( 'reference', 'token', 'status', 'transaction_id', 'sign' ) as $key ) { if ( ! is_string( $p[ $key ] ?? null ) || strlen( $p[ $key ] ) > 512 ) { throw new Protocol_Error( 'invalid_return', 400 ); } }
			$row = Hub_Store::get( $p['reference'] );
			if ( ! $row || time() - (int) $row['created_at'] > 7 * DAY_IN_SECONDS || ! hash_equals( $row['return_token_hash'], hash( 'sha256', $p['token'] ) )
				|| ! in_array( $p['status'], array( 'SUCCEEDED', 'FAILED' ), true ) || ! preg_match( '/^[\x21-\x7e]{0,512}$/D', $p['transaction_id'] ) || ( $p['status'] === 'SUCCEEDED' && '' === $p['transaction_id'] )
				|| ! Signature::verify( array( 'transaction_id' => $p['transaction_id'], 'status' => $p['status'] ), $p['sign'], Crypto::decrypt( $row['secret_box'], 'hub-provider' ) ) ) { throw new Protocol_Error( 'invalid_return', 400 ); }
			$site = \PeptideHq\Sites::get( (int) $row['site_id'] );
			if ( ! $site || ! hash_equals( $row['site_uuid'], $site['uuid'] ) ) { throw new Protocol_Error( 'store_not_found', 400 ); }
			$fields = array( 'status' => $p['status'], 'transaction_id' => $p['transaction_id'] );
			$fields['sign'] = Signature::generate( $fields, Crypto::decrypt( $row['link_key_box'], 'hub-link' ) );
			$url = Crypto::decrypt( $row['return_box'], 'hub-return' );
			// Retain the exact original store return binding; never accept a browser-supplied target.
			nocache_headers(); header( 'Referrer-Policy: no-referrer' ); header( 'X-Robots-Tag: noindex, nofollow' );
			wp_redirect( add_query_arg( $fields, $url ), 302, 'VialCove payment center' ); exit;
		} catch ( \Throwable $e ) { return new \WP_Error( 'invalid_payment_return', 'Please return to your store order to check payment status.', array( 'status' => 400 ) ); }
	}
}
