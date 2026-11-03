<?php
/** Actual HQ Menu/lifecycle and payment Hub code with synthetic memory/file boundaries only. No WP bootstrap, DB, HTTP or payment. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'PC_PANAPAY_ENCRYPTION_KEY', base64_encode( str_repeat( "\x43", 32 ) ) );
	define( 'PEPTIDE_HQ_PROVISION_QUEUE', 'D:/Codex/Tasks/2026-11-03/site-lifecycle/work/payment-fixtures/run-' . bin2hex( random_bytes( 6 ) ) );
	mkdir( PEPTIDE_HQ_PROVISION_QUEUE . '/lifecycle/requests', 0777, true );
	mkdir( PEPTIDE_HQ_PROVISION_QUEUE . '/lifecycle/results', 0777, true );
	final class WP_Error { public function __construct( public string $code, public string $message = '', public array $data = array() ) {} public function get_error_message(): string { return $this->message; } }
	final class WP_REST_Request {
		public function __construct( private array $query = array(), private array $body = array() ) {}
		public function get_query_params(): array { return $this->query; }
		public function get_body_params(): array { return $this->body; }
		public function get_body(): string { return http_build_query( $this->body ); }
	}
	final class WP_REST_Response {
		public function __construct( private $data = null, private int $status = 200 ) {}
		public function get_data() { return $this->data; }
		public function get_status(): int { return $this->status; }
	}
	final class Redirect_Observed extends \RuntimeException {}
	final class Gate_Denied extends \RuntimeException {}
	function check( string $label, bool $pass ): void { $GLOBALS['checks'][] = array( 'name' => $label, 'pass' => $pass ); if ( ! $pass ) { throw new \RuntimeException( 'FAIL ' . $label ); } }
	function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
	function get_option( $k, $default = false ) { return $GLOBALS['opts'][ $k ] ?? $default; }
	function add_option( $k, $v, $deprecated = '', $autoload = false ): bool { if ( array_key_exists( $k, $GLOBALS['opts'] ) ) { return false; } $GLOBALS['opts'][ $k ] = $v; return true; }
	function maybe_serialize( $v ): string { return serialize( $v ); }
	function maybe_unserialize( $v ) { return is_string( $v ) ? unserialize( $v ) : $v; }
	function wp_cache_delete( ...$args ): void {}
	function wp_generate_uuid4(): string { return sprintf( '11111111-2222-4333-8444-%012x', ++$GLOBALS['sequence'] ); }
	function wp_parse_url( string $u, int $part = -1 ) { return parse_url( $u, $part ); }
	function trailingslashit( string $s ): string { return rtrim( $s, '/' ) . '/'; }
	function wp_get_environment_type(): string { return 'local'; }
	function wp_salt( string $scheme ): string { return 'synthetic-lifecycle-gate-key-' . $scheme; }
	function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
	function nocache_headers(): void {}
	function add_filter( ...$args ): void {}
	function remove_filter( ...$args ): void {}
	function add_query_arg( $k, $v, ?string $url = null ): string { if ( is_array( $k ) ) { $a = $k; $url = $v; } else { $a = array( $k => $v ); } return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $a, '', '&', PHP_QUERY_RFC3986 ); }
	function wp_redirect( string $url, int $status = 302, string $by = '' ): void { $GLOBALS['redirects'][] = array( 'url' => $url, 'status' => $status ); throw new Redirect_Observed(); }
	function wp_safe_redirect( string $url ): void { $GLOBALS['admin_redirects'][] = $url; throw new Redirect_Observed(); }
	function current_user_can( string $cap ): bool { return $GLOBALS['can']; }
	function get_current_user_id(): int { return 7; }
	function current_action(): string { return 'admin_post_' . $GLOBALS['admin_action']; }
	function check_admin_referer( string $action ): void { $GLOBALS['nonces'][] = $action; if ( ! $GLOBALS['nonce_ok'] ) { throw new Gate_Denied( 'nonce', 403 ); } }
	function absint( $n ): int { return abs( (int) $n ); }
	function wp_unslash( $s ) { return $s; }
	function sanitize_text_field( $s ): string { return trim( strip_tags( (string) $s ) ); }
	function admin_url( string $s = '' ): string { return 'https://hq.local/wp-admin/' . $s; }
	function set_transient( $k, $v, $ttl ): void { $GLOBALS['flashes'][] = $v; }
	function esc_html__( $s, $domain ): string { return $s; }
	function esc_html( $s ): string { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function wp_die( $message, $title = '', $args = array() ): void { throw new Gate_Denied( (string) $message, is_int( $title ) ? $title : (int) ( $args['response'] ?? 500 ) ); }
	function wp_mail( ...$args ): void { ++$GLOBALS['mail_calls']; throw new \RuntimeException( 'Forbidden mail.' ); }
	function wp_safe_remote_post( string $url, array $args ): array {
		// No socket is opened: observe the real protocol's only HTTP boundary and return a signed synthetic ACK.
		$GLOBALS['http'][] = array( 'url' => $url, 'args' => $args );
		$nonce = $args['headers']['X-PC-Pay-Nonce']; $data = array( 'accepted' => true, 'disposition' => 'paid' );
		$sign = hash_hmac( 'sha256', "pc-pay-response-v1\n" . $nonce . "\n" . hash( 'sha256', VialCove\Panapay\Hub_Protocol::json( $data ) ), $GLOBALS['current_key'] );
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'data' => $data, 'nonce' => $nonce, 'sign' => $sign ) ) );
	}
	function wp_remote_retrieve_response_code( array $r ): int { return $r['response']['code']; }
	function wp_remote_retrieve_body( array $r ): string { return $r['body']; }
	final class Model_Options_DB {
		public string $options = 'synthetic_options'; private array $queries = array();
		public function prepare( string $q, ...$args ): string { $id = (string) count( $this->queries ); $this->queries[ $id ] = array( $q, $args ); return $id; }
		public function get_var( string $id ) { [ $q, $args ] = $this->queries[ $id ]; return isset( $GLOBALS['opts'][ $args[0] ] ) ? serialize( $GLOBALS['opts'][ $args[0] ] ) : null; }
		public function query( string $id ): int { [ $q, $args ] = $this->queries[ $id ]; if ( serialize( $GLOBALS['opts'][ $args[1] ] ?? null ) !== $args[2] ) { return 0; } $GLOBALS['opts'][ $args[1] ] = unserialize( $args[0] ); return 1; }
	}
	$wpdb = new Model_Options_DB();
	$GLOBALS['opts'] = $GLOBALS['checks'] = $GLOBALS['http'] = $GLOBALS['received'] = $GLOBALS['delivery'] = $GLOBALS['redirects'] = $GLOBALS['flashes'] = $GLOBALS['nonces'] = $GLOBALS['admin_redirects'] = array();
	$GLOBALS['can'] = $GLOBALS['nonce_ok'] = true; $GLOBALS['sequence'] = $GLOBALS['key_reads'] = $GLOBALS['hard_deletes'] = $GLOBALS['mail_calls'] = $GLOBALS['created_payments'] = 0;
}
namespace PeptideHq {
	final class Sites {
		public static function get( int $id ): ?array { return $id === ( $GLOBALS['current_site']['id'] ?? 0 ) ? $GLOBALS['current_site'] : null; }
		public static function key( int $id ): string { ++$GLOBALS['key_reads']; return $GLOBALS['current_key']; }
		public static function delete( int $id ): void { ++$GLOBALS['hard_deletes']; throw new \RuntimeException( 'Forbidden hard delete.' ); }
	}
}
namespace PeptideHq\Admin { final class Sites_Page { public const SLUG = 'pchq-sites'; } }
namespace VialCove\Panapay {
	final class Hub_Store {
		public static function get( string $ref ): ?array { return $ref === $GLOBALS['row']['ref'] ? $GLOBALS['row'] : null; }
		public static function pending( int $limit ): array { return array( $GLOBALS['event'] ); }
		public static function claim_delivery( string $id ): ?array { return $id === $GLOBALS['event']['event_id'] ? $GLOBALS['event'] : null; }
		public static function delivery_result( ...$args ): void { $GLOBALS['delivery'][] = $args; }
		public static function receive( ...$args ): void { $GLOBALS['received'][] = $args; }
		public static function reserve( ...$args ): void { ++$GLOBALS['created_payments']; throw new \RuntimeException( 'Forbidden creation.' ); }
		public static function finish_create( ...$args ): void { ++$GLOBALS['created_payments']; throw new \RuntimeException( 'Forbidden creation.' ); }
	}
}
namespace {
	$hq = dirname( __DIR__, 2 ) . '/peptide-hq/includes/';
	foreach ( array( 'class-site-urls.php', 'class-site-lifecycle.php', 'admin/class-menu.php', 'admin/class-lifecycle-page.php' ) as $file ) { require $hq . $file; }
	foreach ( array( 'protocol-error', 'hub-protocol', 'session-service', 'signature', 'crypto', 'hub-urls', 'hub' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
	use PeptideHq\Site_Lifecycle as Lifecycle;
	use PeptideHq\Admin\Menu;
	use VialCove\Panapay\{Crypto, Hub, Hub_Protocol, Signature};
	$uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-111111111111';
	$site = array( 'id' => 2, 'uuid' => $uuid, 'name' => 'Synthetic lifecycle store', 'url' => 'https://s0001.manager.local/', 'management_url' => 'https://s0001.manager.local/', 'public_url' => 'https://www.shop.local/', 'dual_domain' => true );
	$GLOBALS['current_site'] = $site; $GLOBALS['current_key'] = str_repeat( 'k', 64 );
	$provider_secret = str_repeat( 'p', 64 ); $frozen_link = str_repeat( 'l', 64 ); $hq_token = str_repeat( 't', 48 );
	$local_ref = 'PP1234abcd-42-' . str_repeat( 'b', 32 );
	$frozen_return = 'https://s0001.shop.local/?wc-api=pc_panapay_return&reference=' . $local_ref . '&token=' . str_repeat( 'c', 48 );
	$GLOBALS['row'] = array( 'ref' => 'PH' . str_repeat( 'a', 32 ), 'site_id' => '2', 'site_uuid' => $uuid, 'order_id' => '42', 'local_reference' => $local_ref, 'account_id' => 'default',
		'created_at' => time(), 'return_box' => Crypto::encrypt( $frozen_return, 'hub-return' ), 'return_token_hash' => hash( 'sha256', $hq_token ),
		'secret_box' => Crypto::encrypt( $provider_secret, 'hub-provider' ), 'link_key_box' => Crypto::encrypt( $frozen_link, 'hub-link' ), 'test_mode' => 0 );
	$GLOBALS['event'] = array( 'ref' => $GLOBALS['row']['ref'], 'event_id' => str_repeat( 'e', 32 ), 'status' => 'SUCCEEDED', 'transaction_box' => Crypto::encrypt( 'synthetic-transaction', 'hub-transaction' ) );
	$baseline_row = serialize( $GLOBALS['row'] );
	function state( string $state ): void {
		$at = time() - 60;
		$r = array( 'site_id' => 2, 'uuid' => $GLOBALS['current_site']['uuid'], 'management_url' => $GLOBALS['current_site']['management_url'], 'public_url' => $GLOBALS['current_site']['public_url'], 'name' => 'Synthetic lifecycle store',
			'operation_id' => '11111111-2222-4333-8444-111111111111', 'retirement_id' => '11111111-2222-4333-8444-222222222222', 'actor_id' => 7, 'action' => 'active' === $state ? 'restore' : 'retire',
			'state' => $state, 'requested_at' => $at, 'retired_at' => $at, 'purge_at' => $at + Lifecycle::RETENTION, 'message' => '', 'error_code' => '' );
		$GLOBALS['opts'][ Lifecycle::OPTION ] = array( 'version' => 1, 'revision' => 1, 'records' => array( 2 => $r ) );
		$GLOBALS['http'] = $GLOBALS['delivery'] = $GLOBALS['redirects'] = array(); $GLOBALS['key_reads'] = 0;
	}
	$query = array( 'reference' => $GLOBALS['row']['ref'], 'token' => $hq_token, 'status' => 'SUCCEEDED', 'transaction_id' => 'synthetic-transaction' );
	$query['sign'] = Signature::generate( array_intersect_key( $query, array_flip( array( 'transaction_id', 'status' ) ) ), $provider_secret );
	foreach ( array( 'retained', 'failed', 'offlining', 'restoring', 'purging', 'purged' ) as $s ) {
		state( $s );
		check( $s . ' fixture is closed by actual lifecycle', ! Lifecycle::active( 2 ) && Lifecycle::record( 2 )['state'] === $s );
		check( $s . ' pending relay processed without exception', 1 === Hub::retry_pending() );
		check( $s . ' prevents event HTTP and current-key lookup', array() === $GLOBALS['http'] && 0 === $GLOBALS['key_reads'] );
		if ( 'purged' === $s ) { check( 'purged event becomes manual review rather than permanent retry', array_slice( $GLOBALS['delivery'][0], 1 ) === array( 'review', 'review', 'store_purged' ) ); }
		else { check( $s . ' event stays pending without financial rewrite', array_slice( $GLOBALS['delivery'][0], 1 ) === array( 'pending', '', 'store_retained' ) ); }
		$return = Hub::returned( new WP_REST_Request( $query ) );
		check( $s . ' buyer return never redirects', is_wp_error( $return ) && array() === $GLOBALS['redirects'] );
	}
	state( 'active' );
	check( 'active relay follows existing delivery path', 1 === Hub::retry_pending() && count( $GLOBALS['http'] ) === 1 && array_slice( $GLOBALS['delivery'][0], 1 ) === array( 'delivered', 'paid' ) );
	$sent = $GLOBALS['http'][0]; $body = json_decode( $sent['args']['body'], true );
	check( 'active relay uses registered management origin', str_starts_with( $sent['url'], $site['management_url'] . '?rest_route=' ) );
	check( 'active relay retains frozen attempt signing key', Signature::verify( array_intersect_key( $body, array_flip( array( 'order_number', 'status', 'transaction_id' ) ) ), $body['sign'], $frozen_link ) );
	check( 'active relay preserves local reference and event identity', $body['order_number'] === $local_ref && $body['event_id'] === $GLOBALS['event']['event_id'] && $body['account_id'] === 'default' );
	Hub::returned( new WP_REST_Request( $query ) );
	check( 'active return reaches original frozen URL', count( $GLOBALS['redirects'] ) === 1 && str_starts_with( $GLOBALS['redirects'][0]['url'], $frozen_return . '&status=SUCCEEDED&' ) );
	check( 'relay and browser return do not mutate frozen attempt', $baseline_row === serialize( $GLOBALS['row'] ) );
	$notify = array( 'order_number' => $GLOBALS['row']['ref'], 'status' => 'SUCCEEDED', 'transaction_id' => 'synthetic-transaction' );
	$notify['sign'] = Signature::generate( $notify, $provider_secret );
	foreach ( array( 'retained', 'failed', 'purged', 'active' ) as $s ) {
		state( $s ); $GLOBALS['received'] = array(); $GLOBALS['current_key'] = 'active' === $s ? str_repeat( 'r', 64 ) : '';
		$ack = Hub::notify( new WP_REST_Request( array(), $notify ) );
		check( $s . ' accepts authentic late notification using frozen provider secret', 200 === $ack->get_status() && 'ok' === $ack->get_data() && count( $GLOBALS['received'] ) === 1 );
		check( $s . ' late notify never performs relay or reads cleared current key', array() === $GLOBALS['http'] && 0 === $GLOBALS['key_reads'] );
		$invalid = array_replace( $notify, array( 'sign' => str_repeat( '0', 32 ) ) ); $before = count( $GLOBALS['received'] );
		$ack = Hub::notify( new WP_REST_Request( array(), $invalid ) );
		check( $s . ' invalid frozen signature never reaches ledger boundary', 400 === $ack->get_status() && $before === count( $GLOBALS['received'] ) );
	}
	// Exercise the actual admin handler. All persistence below is the isolated option model plus private D: fixture files.
	function admin_call( string $action, string $method, bool $nonce = true, bool $can = true, $posted_site = 2, $mixed_site = 2 ): ?Gate_Denied {
		$GLOBALS['admin_action'] = $action; $GLOBALS['nonce_ok'] = $nonce; $GLOBALS['can'] = $can;
		$_SERVER['REQUEST_METHOD'] = $method; $_POST = array( 'site' => $posted_site ); $_REQUEST = array( 'site' => $mixed_site );
		try { Menu::handle(); } catch ( Gate_Denied $e ) { return $e; } catch ( Redirect_Observed $e ) { return null; }
		throw new \RuntimeException( 'Handler did not stop at a boundary.' );
	}
	$GLOBALS['opts'] = array();
	check( 'retire GET rejected with 405 and no lifecycle write', 405 === admin_call( 'pchq_site_retire', 'GET' )?->getCode() && array() === $GLOBALS['opts'] );
	check( 'retire POST requires nonce and leaves lifecycle unchanged', 403 === admin_call( 'pchq_site_retire', 'POST', false )?->getCode() && array() === $GLOBALS['opts'] );
	check( 'retire POST requires administrator and leaves lifecycle unchanged', 403 === admin_call( 'pchq_site_retire', 'POST', true, false )?->getCode() && array() === $GLOBALS['opts'] );
	check( 'legacy delete GET only displays disabled notice', null === admin_call( 'pchq_site_delete', 'GET' ) && array() === $GLOBALS['opts'] && 0 === $GLOBALS['hard_deletes'] );
	check( 'legacy delete POST also cannot delete or retire', null === admin_call( 'pchq_site_delete', 'POST' ) && array() === $GLOBALS['opts'] && 0 === $GLOBALS['hard_deletes'] );
	check( 'retire refuses nonscalar POST identity despite valid mixed source', null === admin_call( 'pchq_site_retire', 'POST', true, true, array(), 2 ) && array() === $GLOBALS['opts'] );
	check( 'retire refuses empty POST identity despite valid mixed source', null === admin_call( 'pchq_site_retire', 'POST', true, true, 0, 2 ) && array() === $GLOBALS['opts'] );
	check( 'explicit nonce-protected POST uses posted identity and queues synthetic store', null === admin_call( 'pchq_site_retire', 'POST', true, true, 2, 99 ) && ! Lifecycle::active( 2 ) && count( glob( PEPTIDE_HQ_PROVISION_QUEUE . '/lifecycle/requests/*.json' ) ) === 1 );
	check( 'retire nonce action is bound to requested action', end( $GLOBALS['nonces'] ) === 'pchq_site_retire' );
	function recovery_call( string $handler, string $method, bool $nonce = true, bool $can = true, $site = 2 ): ?Gate_Denied {
		$GLOBALS['nonce_ok'] = $nonce; $GLOBALS['can'] = $can; $_SERVER['REQUEST_METHOD'] = $method; $_POST = array( 'site' => $site, 'action' => 'retire' );
		try { PeptideHq\Admin\Lifecycle_Page::$handler(); } catch ( Gate_Denied $e ) { return $e; } catch ( Redirect_Observed $e ) { return null; }
		throw new \RuntimeException( 'Recovery handler did not stop at a boundary.' );
	}
	state( 'failed' ); $snapshot = serialize( $GLOBALS['opts'] );
	check( 'retry GET rejected without changing lifecycle', 405 === recovery_call( 'retry', 'GET' )?->getCode() && $snapshot === serialize( $GLOBALS['opts'] ) );
	check( 'retry POST requires action nonce', 403 === recovery_call( 'retry', 'POST', false )?->getCode() && $snapshot === serialize( $GLOBALS['opts'] ) );
	check( 'retry POST requires administrator', 403 === recovery_call( 'retry', 'POST', true, false )?->getCode() && $snapshot === serialize( $GLOBALS['opts'] ) );
	check( 'retry POST derives original retire action from stored record', null === recovery_call( 'retry', 'POST' ) && Lifecycle::record( 2 )['operation_id'] === '11111111-2222-4333-8444-111111111111' && Lifecycle::record( 2 )['state'] === 'offlining' );
	check( 'retry uses its own nonce and success URL', end( $GLOBALS['nonces'] ) === 'pchq_site_retry' && str_contains( end( $GLOBALS['admin_redirects'] ), 'retry_requested=1' ) );
	state( 'failed' ); $GLOBALS['opts'][ Lifecycle::OPTION ]['records'][2]['action'] = 'restore';
	$GLOBALS['opts'][ Lifecycle::OPTION ]['records'][2]['operation_id'] = '11111111-2222-4333-8444-333333333333'; $original = Lifecycle::record( 2 );
	check( 'retry handler resumes failed restore despite untrusted posted action', null === recovery_call( 'retry', 'POST' ) && Lifecycle::record( 2 )['action'] === 'restore' && Lifecycle::record( 2 )['operation_id'] === $original['operation_id'] && Lifecycle::record( 2 )['purge_at'] === $original['purge_at'] );
	$GLOBALS['opts'][ Lifecycle::OPTION ]['records'][2]['state'] = 'failed';
	$GLOBALS['opts'][ Lifecycle::OPTION ]['records'][2]['retired_at'] = time() - Lifecycle::RETENTION - 2; $GLOBALS['opts'][ Lifecycle::OPTION ]['records'][2]['purge_at'] = time() - 2;
	$snapshot = serialize( $GLOBALS['opts'] );
	check( 'expired failed restore POST is rejected without state or deadline changes', 409 === recovery_call( 'retry', 'POST' )?->getCode() && $snapshot === serialize( $GLOBALS['opts'] ) );
	check( 'restore GET is rejected before state changes', 405 === recovery_call( 'restore', 'GET' )?->getCode() && $snapshot === serialize( $GLOBALS['opts'] ) );
	check( 'no real payment creation, hard delete or mail', 0 === $GLOBALS['created_payments'] && 0 === $GLOBALS['hard_deletes'] && 0 === $GLOBALS['mail_calls'] );
	$report = array( 'complete' => true, 'checks' => count( $GLOBALS['checks'] ), 'modelOnly' => true, 'actualClasses' => array( 'HQ Menu', 'HQ Site_Lifecycle', 'Panapay Hub', 'Hub_Protocol', 'Hub_Urls', 'Crypto', 'Signature' ), 'transportIntercepted' => true, 'databaseStubbed' => true, 'cases' => $GLOBALS['checks'] );
	if ( isset( $argv[1] ) ) { file_put_contents( $argv[1], json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ); }
	echo 'PASS ', $report['checks'], " actual lifecycle/payment/admin checks; synthetic memory/files, HTTP intercepted, no WP bootstrap or transaction.\n";
}
