<?php
/**
 * HQ request nonces and store replay protection; development checkout only.
 *
 * Run on the HQ WordPress: wp eval-file wp-content/plugins/peptide-hq/tests/smoke-client-nonce.php
 * HTTP is intercepted and verification uses a synthetic key. Only temporary replay markers are
 * written, then deleted; store settings writes are intercepted. No business fixtures are created.
 * PC_HQ_NONCE_REAL_READS=1 additionally repeats status/staff GETs against one connected store.
 *
 * @package PeptideHq
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Hq_Connector;
use PeptideHq\Client;
use PeptideHq\Sites;

if ( ! class_exists( Hq_Connector::class ) ) {
	$store_verifier = dirname( PEPTIDE_HQ_DIR ) . '/peptide-core/includes/class-hq-connector.php';
	if ( ! is_readable( $store_verifier ) ) {
		throw new RuntimeException( 'This check needs the sibling peptide-core source checkout.' );
	}
	require_once $store_verifier;
}

$failures = 0;
$ok = static function ( string $label, bool $condition ) use ( &$failures ): void {
	echo $condition ? 'PASS ' : 'FAIL ', $label, "\n";
	$failures += $condition ? 0 : 1;
};
$key = bin2hex( random_bytes( 32 ) ); // Synthetic fixture, never a connected store key.
$captured = array();
$markers = array();
$blocked_writes = 0;
$mock_http = static function ( $preempt, array $args, string $url ) use ( &$captured ) {
	if ( 'pc-smoke-client-nonce.invalid' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		return $preempt;
	}
	$query = array();
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	$captured[] = array( 'query' => $query, 'args' => $args );
	return array( 'headers' => array(), 'body' => '{"ok":true}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
$mock_settings = static fn() => array( 'key' => $key );
$block_settings_write = static function ( $value, $old_value ) use ( &$blocked_writes ) {
	++$blocked_writes;
	return $old_value;
};
add_filter( 'pre_http_request', $mock_http, 10, 3 );
add_filter( 'pre_option_' . Hq_Connector::OPTION, $mock_settings );
add_filter( 'pre_update_option_' . Hq_Connector::OPTION, $block_settings_write, 10, 2 );
try {
	$query = array( 'days' => '2', 'pc_hq_nonce' => 'caller-controlled' );
	$first = Client::request_raw( 'http://pc-smoke-client-nonce.invalid/', $key, 'GET', '/peptide/v1/hq/status', $query );
	$second = Client::request_raw( 'http://pc-smoke-client-nonce.invalid/', $key, 'GET', '/peptide/v1/hq/status', $query );
	$ok( 'two identical caller requests are intercepted without external HTTP', array( 'ok' => true ) === $first && $first === $second && 2 === count( $captured ) );
	if ( 2 === count( $captured ) ) {
		$nonce_one = $captured[0]['query']['pc_hq_nonce'] ?? '';
		$nonce_two = $captured[1]['query']['pc_hq_nonce'] ?? '';
		$ok( 'each call replaces the reserved caller nonce with a different UUID', wp_is_uuid( $nonce_one, 4 ) && wp_is_uuid( $nonce_two, 4 ) && $nonce_one !== $nonce_two && 'caller-controlled' !== $nonce_one && 'caller-controlled' !== $nonce_two );
		$ok( 'the caller query is unchanged and ordinary query values survive', 'caller-controlled' === $query['pc_hq_nonce'] && '2' === $captured[0]['query']['days'] && '2' === $captured[1]['query']['days'] );
		$requests = array();
		foreach ( $captured as $call ) {
			$request = new WP_REST_Request( 'GET', '/peptide/v1/hq/status' );
			$request->set_query_params( $call['query'] );
			foreach ( $call['args']['headers'] as $name => $value ) {
				$request->set_header( $name, $value );
			}
			$request->set_body( (string) ( $call['args']['body'] ?? '' ) );
			$requests[] = $request;
			$markers[] = 'pc_hq_n_' . substr( (string) $request->get_header( 'x_pc_hq_sign' ), 0, 40 );
		}
		$ok( 'both unique requests pass the unchanged store signature verifier', true === Hq_Connector::verify( $requests[0] ) && true === Hq_Connector::verify( $requests[1] ) );
		$replay = Hq_Connector::verify( $requests[0] );
		$ok( 'replaying the exact same signed request still returns 401', is_wp_error( $replay ) && 'pc_hq_replay' === $replay->get_error_code() && 401 === (int) $replay->get_error_data()['status'] );
		$tampered = clone $requests[0];
		$tampered->set_query_params( array_merge( $captured[0]['query'], array( 'pc_hq_nonce' => wp_generate_uuid4() ) ) );
		$invalid = Hq_Connector::verify( $tampered );
		$ok( 'changing only the nonce invalidates the signature', is_wp_error( $invalid ) && 'pc_hq_sign' === $invalid->get_error_code() );
	}
} finally {
	foreach ( $markers as $marker ) {
		delete_transient( $marker );
	}
	remove_filter( 'pre_http_request', $mock_http, 10 );
	remove_filter( 'pre_option_' . Hq_Connector::OPTION, $mock_settings );
	remove_filter( 'pre_update_option_' . Hq_Connector::OPTION, $block_settings_write, 10 );
}
$ok( 'synthetic settings writes were blocked and replay markers were removed', 2 === $blocked_writes && ! array_filter( $markers, static fn( $marker ) => false !== get_transient( $marker ) ) );

if ( '1' === getenv( 'PC_HQ_NONCE_REAL_READS' ) ) {
	$sites = Sites::all();
	$site = (int) array_key_first( $sites );
	$ok( 'a connected store is available for read-only integration checks', $site > 0 );
	if ( $site ) {
		foreach ( array( 'status', 'staff_list' ) as $method ) {
			for ( $i = 1; $i <= 3; ++$i ) {
				$result = Client::$method( $site );
				$label = "real $method GET $i succeeds";
				if ( is_wp_error( $result ) ) {
					$label .= ' (' . $result->get_error_code() . ')';
				}
				$ok( $label, is_array( $result ) );
			}
		}
	}
}

if ( $failures && defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::halt( 1 );
}
