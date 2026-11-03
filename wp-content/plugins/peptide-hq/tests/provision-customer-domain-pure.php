<?php
/** Native form/queue/result helpers with temporary synthetic files only; no WP bootstrap, DB or HTTP. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$fixture_parent = getenv( 'VIALCOVE_TEST_TMP' ) ?: dirname( __DIR__, 4 ) . '/.test-work';
	$fixture = $fixture_parent . '/provision-form-' . bin2hex( random_bytes( 6 ) );
	if ( ! is_dir( $fixture ) && ! mkdir( $fixture, 0700, true ) ) { throw new \RuntimeException( 'Cannot create fixture directory' ); }
	mkdir( $fixture . '/requests', 0700 ); mkdir( $fixture . '/results', 0700 );
	define( 'PEPTIDE_HQ_PROVISION_QUEUE', $fixture );
	final class Redirect_Reached extends \RuntimeException {}
	final class Permission_Denied extends \RuntimeException {}
	function current_user_can( string $cap ): bool { return $GLOBALS['can_create'] ?? true; }
	function check_admin_referer( string $action ): void { ++$GLOBALS['nonce_checks']; if ( ! ( $GLOBALS['nonce_ok'] ?? true ) ) { throw new Permission_Denied( 'nonce' ); } }
	function wp_unslash( $value ) { return $value; }
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function sanitize_email( string $value ): string { return $value; }
	function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function wp_die( string $message, ...$args ): void { throw new Permission_Denied( $message ); }
	function get_current_user_id(): int { return 7; }
	function wp_json_encode( $value, int $flags = 0 ): string { return json_encode( $value, $flags | JSON_THROW_ON_ERROR ); }
	function admin_url( string $path ): string { return 'https://hq.example.com/wp-admin/' . $path; }
	function wp_safe_redirect( string $url ): void { $GLOBALS['redirect'] = $url; throw new Redirect_Reached( 'redirect' ); }
	function wp_parse_url( string $url ) { return parse_url( $url ); }
	function esc_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( string $value ): string { return esc_html( $value ); }
	function esc_url( string $value ): string { return esc_html( $value ); }
	function wp_create_nonce( string $action ): string { return 'fixture-nonce'; }
	function wp_generate_uuid4(): string { return '11111111-1111-4111-8111-111111111111'; }
	function wp_nonce_field( string $action ): void { echo '<input name="_wpnonce" value="fixture-nonce">'; }
	function submit_button( ...$args ): void { echo '<button>创建店铺</button>'; }
	function is_admin(): bool { return false; }
	function wp_doing_ajax(): bool { return false; }
	function wp_doing_cron(): bool { return false; }
	function is_preview(): bool { return false; }
	function is_customize_preview(): bool { return false; }
	function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
	function get_option( ...$args ) { throw new \RuntimeException( 'Non-GET redirect touched domain data' ); }
	function wp_redirect( ...$args ) { throw new \RuntimeException( 'Non-GET redirect attempted a redirect' ); }
}
namespace PeptideHq\Admin { final class Menu { public const CAP = 'manage_options'; } }
namespace PeptideCore { final class Management_Host { public static function enabled(): bool { return false; } } }
namespace {
	require dirname( __DIR__ ) . '/includes/admin/class-provision-page.php';
	require dirname( __DIR__, 2 ) . '/peptide-core/includes/class-domains.php';
	use PeptideHq\Admin\Provision_Page;
	$checks = 0; $GLOBALS['nonce_checks'] = 0;
	function check_domain( string $name, bool $value ): void { ++$GLOBALS['checks']; if ( ! $value ) { throw new \RuntimeException( 'FAIL ' . $name ); } }
	function rejected_submit( array $post ): bool { $_POST = $post; try { Provision_Page::submit(); } catch ( Permission_Denied $e ) { return true; } return false; }
	function submitted( array $post ): void { $_POST = $post; try { Provision_Page::submit(); } catch ( Redirect_Reached $e ) { return; } throw new \RuntimeException( 'Expected redirect' ); }
	function fixture_remove( string $path ): void {
		foreach ( scandir( $path ) as $name ) { if ( '.' === $name || '..' === $name ) { continue; } $p = $path . '/' . $name; if ( is_dir( $p ) && ! is_link( $p ) ) { fixture_remove( $p ); } else { unlink( $p ); } } rmdir( $path );
	}
	try {
		foreach ( array( 'example.com' => 'example.com', 'WWW.Example.COM' => 'example.com', ' example.co.uk ' => 'example.co.uk', 'www.example.co.uk' => 'example.co.uk', 'xn--bcher-kva.de' => 'xn--bcher-kva.de', 'a.example.com' => 'a.example.com' ) as $input => $expected ) {
			check_domain( 'normalise customer input ' . $input, $expected === Provision_Page::customer_domain( $input ) );
		}
		$max = str_repeat( 'a', 63 ) . '.' . str_repeat( 'b', 63 ) . '.' . str_repeat( 'c', 63 ) . '.' . str_repeat( 'd', 57 );
		check_domain( 'www maximum 253 byte DNS name accepted', 249 === strlen( $max ) && $max === Provision_Page::customer_domain( 'www.' . $max ) );
		$bad = array( '', 'localhost', 'www', 'www.www.example.com', 'WWW.www.example.com', 'example.com.', 'https://example.com', '//example.com', 'example.com/path', 'example.com:443', 'user@example.com', 'example.com?x=1', 'example.com#x', '127.0.0.1', '[::1]', '*.example.com', 'foo_bar.com', '-example.com', 'example-.com', 'example..com', 'ex ample.com', "example.com\n", "\texample.com", "ex\0ample.com", '例子.com', str_repeat( 'a', 64 ) . '.com', $max . 'x' );
		foreach ( $bad as $i => $input ) { check_domain( 'reject non-domain input ' . $i, '' === Provision_Page::customer_domain( $input ) ); }
		$id = '11111111-1111-4111-8111-111111111111';
		$post = array( 'operation_id' => $id, 'store_title' => 'Synthetic store', 'customer_domain' => 'WWW.Example.COM', 'owner_login' => 'fixture_owner', 'owner_email' => 'owner@example.com', 'owner_password' => 'fixture-only-password' );
		submitted( $post );
		$queued = json_decode( file_get_contents( $fixture . '/requests/' . $id . '.json' ), true, 512, JSON_THROW_ON_ERROR );
		check_domain( 'submit serialises exact version2 contract', array( 'version', 'operation_id', 'actor_id', 'title', 'login', 'email', 'password', 'customer_domain' ) === array_keys( $queued ) && 2 === $queued['version'] && 'example.com' === $queued['customer_domain'] && 7 === $queued['actor_id'] );
		check_domain( 'submit returns only operation id URL', 'https://hq.example.com/wp-admin/admin.php?page=pchq-create-store&job=' . $id === $GLOBALS['redirect'] );
		$original = file_get_contents( $fixture . '/requests/' . $id . '.json' );
		submitted( array_replace( $post, array( 'customer_domain' => 'other.example.com' ) ) );
		check_domain( 'browser replay never overwrites queued request', $original === file_get_contents( $fixture . '/requests/' . $id . '.json' ) );
		$next = '22222222-2222-4222-8222-222222222222';
		foreach ( array( '', 'https://example.com', array( 'example.com' ) ) as $invalid ) {
			check_domain( 'new request requires a scalar valid customer domain', rejected_submit( array_replace( $post, array( 'operation_id' => $next, 'customer_domain' => $invalid ) ) ) && ! file_exists( $fixture . '/requests/' . $next . '.json' ) );
		}
		$legacy = array( 'state' => 'complete', 'message' => 'Store ready.', 'slug' => 's0002', 'management_url' => 'https://s0002.manager.example.com/wp-admin/', 'public_url' => 'https://s0002.shop.example.com/', 'site_id' => 3 );
		file_put_contents( $fixture . '/results/' . $next . '.json', json_encode( $legacy ) );
		check_domain( 'completed version1 public result remains unchanged', $legacy == Provision_Page::safe_result( $legacy ) );
		$before_nonce = $GLOBALS['nonce_checks'];
		submitted( array( 'operation_id' => $next ) );
		check_domain( 'completed v1 browser replay checks nonce and never writes queue', $GLOBALS['nonce_checks'] === $before_nonce + 1 && ! file_exists( $fixture . '/requests/' . $next . '.json' ) && str_ends_with( $GLOBALS['redirect'], $next ) );
		$GLOBALS['can_create'] = false;
		check_domain( 'completed replay still requires capability', rejected_submit( array( 'operation_id' => $next ) ) );
		$GLOBALS['can_create'] = true; $GLOBALS['nonce_ok'] = false;
		check_domain( 'completed replay still requires nonce', rejected_submit( array( 'operation_id' => $next ) ) );
		$GLOBALS['nonce_ok'] = true;
		$records = array( array( 'type' => 'A', 'name' => '@', 'value' => '203.0.113.10', 'ttl' => 600 ), array( 'type' => 'A', 'name' => 'www', 'value' => '203.0.113.10', 'ttl' => 600 ) );
		$progress = array( 'state' => 'waiting_dns', 'message' => 'Add two records.', 'customer_domain' => 'example.com', 'dns_records' => $records, 'password' => 'fixture-secret', 'registration_secret' => 'fixture-key', 'private_path' => '/fixture/private' );
		$safe = Provision_Page::safe_result( $progress );
		check_domain( 'waiting DNS exposes two bounded manual A records', $records === $safe['dns_records'] && 'example.com' === $safe['customer_domain'] );
		check_domain( 'private fields never leave result queue', ! isset( $safe['password'], $safe['registration_secret'], $safe['private_path'] ) && false === strpos( json_encode( $safe ), 'fixture-secret' ) && false === strpos( json_encode( $safe ), 'fixture-key' ) );
		foreach ( array( array_replace( $records[1], array( 'type' => 'CNAME' ) ), array_replace( $records[1], array( 'value' => '203.0.113.11' ) ), array_replace( $records[1], array( 'value' => '::1' ) ), array_replace( $records[1], array( 'ttl' => '600' ) ), array_replace( $records[1], array( 'name' => '*' ) ) ) as $bad_record ) {
			check_domain( 'invalid DNS instruction cannot reach browser', ! isset( Provision_Page::safe_result( array_replace( $progress, array( 'dns_records' => array( $records[0], $bad_record ) ) ) )['dns_records'] ) );
		}
		foreach ( array( 'javascript:alert(1)', 'http://example.com/', 'https://user@example.com/', 'https://example.com/?secret=1', 'https://example.com/#fragment', 'https://example.com:8443/', 'https://example.com/private/', 'https://example.com/\\evil' ) as $unsafe ) {
			check_domain( 'unsafe completed public link suppressed', ! isset( Provision_Page::safe_result( array_replace( $legacy, array( 'public_url' => $unsafe ) ) )['public_url'] ) );
		}
		check_domain( 'management link must be admin path', ! isset( Provision_Page::safe_result( array_replace( $legacy, array( 'management_url' => 'https://manager.example.com/' ) ) )['management_url'] ) );
		$_GET = array(); ob_start(); Provision_Page::render(); $form = ob_get_clean();
		check_domain( 'new form requires domain and explains manual records', str_contains( $form, 'name="customer_domain"' ) && str_contains( $form, '顾客地址将使用 www' ) && str_contains( $form, '@ 和 www 两条 A 记录' ) && str_contains( $form, 'spellcheck="false" required' ) );
		$_GET = array( 'job' => $next ); ob_start(); Provision_Page::render(); $status = ob_get_clean();
		check_domain( 'legacy completed progress still renders and DNS uses textContent', str_contains( $status, 'Store ready.' ) && str_contains( $status, 'pchq-provision-dns' ) && str_contains( $status, 'td.textContent=String(value)' ) && ! str_contains( $status, 'innerHTML' ) );
		// Execute the actual redirect method, with forbidden storage/redirect boundaries above.
		$_GET = array();
		$body = array( 'reference' => 'synthetic-reference', 'status' => 'SUCCEEDED', 'body_marker' => 'preserve-exact-body' );
		foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
			$_POST = $body;
			$_SERVER['HTTP_HOST'] = 'example.com';
			$_SERVER['REQUEST_METHOD'] = $method;
			$_SERVER['REQUEST_URI'] = '/wc-api/pc_panapay_notify/';
			\PeptideCore\Domains::redirect();
			check_domain( 'actual Domains redirect preserves ' . $method . ' without reading domain data', $_POST === $body && $method === $_SERVER['REQUEST_METHOD'] );
		}
		echo 'PASS ', $checks, " pure provisioning form checks; synthetic queue only, no WP/DB/HTTP.\n";
	} finally { fixture_remove( $fixture ); }
}
