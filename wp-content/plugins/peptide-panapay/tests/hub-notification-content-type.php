<?php
/** Real Hub, Signature and WordPress REST parsers. Memory-only storage/crypto boundaries; no WP bootstrap, DB or HTTP. */
namespace {
    define( 'ABSPATH', __DIR__ . '/' );
    $wp_root = getenv( 'PANAPAY_MODEL_WP_ROOT' ) ?: 'D:/Codex/Sites/vialcove-hq/public';
    foreach ( array( '/wp-includes/rest-api/class-wp-rest-request.php', '/wp-includes/class-wp-http-response.php', '/wp-includes/rest-api/class-wp-rest-response.php' ) as $relative ) {
        if ( ! is_readable( $wp_root . $relative ) ) { throw new \RuntimeException( 'Set PANAPAY_MODEL_WP_ROOT to an existing local WordPress source tree.' ); }
    }
    function absint( $value ): int { return abs( (int) $value ); }
    function __( $value, $domain = 'default' ) { return $value; }
    function wp_get_environment_type(): string { return $GLOBALS['environment'] ?? 'production'; }
    function wp_is_json_media_type( $media_type ): bool { return (bool) preg_match( '/(^|\s|,)application\/([\w!#\$&-\^\.\+]+\+)?json(\+oembed)?($|\s|;|,)/i', $media_type ); }
    final class WP_Http { public const BAD_REQUEST = 400; }
    final class WP_Error { public function __construct( public $code, public $message = '', public $data = array() ) {} }
    function wp_safe_remote_post( ...$args ) { ++$GLOBALS['outbound']; throw new \RuntimeException( 'No transport permitted in model.' ); }
    function wp_remote_post( ...$args ) { ++$GLOBALS['outbound']; throw new \RuntimeException( 'No transport permitted in model.' ); }
    require $wp_root . '/wp-includes/class-wp-http-response.php';
    require $wp_root . '/wp-includes/rest-api/class-wp-rest-response.php';
    require $wp_root . '/wp-includes/rest-api/class-wp-rest-request.php';
    final class Observed_Request extends \WP_REST_Request {
        public int $form_reads = 0; public int $json_reads = 0;
        public function get_body_params() { ++$this->form_reads; return parent::get_body_params(); }
        public function get_json_params() { ++$this->json_reads; return parent::get_json_params(); }
    }
}
namespace VialCove\Panapay {
    final class Hub_Store {
        public static function get( string $ref ): ?array { ++$GLOBALS['lookups']; if ( $GLOBALS['get_throws'] ) { throw new \RuntimeException( 'synthetic database unavailable' ); } return $ref === $GLOBALS['target_ref'] ? $GLOBALS['frozen_row'] : null; }
        public static function receive( array $row, string $status, string $transaction ): array { ++$GLOBALS['receive_calls']; if ( $GLOBALS['receive_throws'] ) { throw new \RuntimeException( 'synthetic durable receive failed' ); } $GLOBALS['received'][] = array( 'row' => $row, 'status' => $status, 'transaction' => $transaction ); return array( 'persisted' => true ); }
        public static function pending( ...$args ) { throw new \RuntimeException( 'Forbidden delivery call.' ); }
        public static function reserve( ...$args ) { throw new \RuntimeException( 'Forbidden creation.' ); }
    }
    final class Crypto { public static function decrypt( string $box, string $purpose ): string { ++$GLOBALS['decrypts']; if ( $GLOBALS['crypto_throws'] || $purpose !== 'hub-provider' || $box !== 'synthetic-frozen-secret-box' ) { throw new \RuntimeException( 'synthetic decryption unavailable' ); } return 'synthetic-frozen-secret'; } }
    final class Hub_Accounts { public static function load( ...$args ) { throw new \RuntimeException( 'Current settings must not replace frozen notification credentials.' ); } }
}
namespace {
    foreach ( array( 'protocol-error', 'hub-protocol', 'signature', 'hub' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
    use VialCove\Panapay\{Hub, Signature};
    $GLOBALS['cases'] = array(); $GLOBALS['outbound'] = 0; $GLOBALS['target_ref'] = 'PH' . str_repeat( 'a', 32 );
    function check( string $name, bool $pass ): void { $GLOBALS['cases'][] = array( 'name' => $name, 'pass' => $pass ); if ( ! $pass ) { throw new \RuntimeException( 'FAIL ' . $name ); } }
    function reset_model(): void { $GLOBALS['environment'] = 'production'; $GLOBALS['frozen_row'] = array( 'ref' => $GLOBALS['target_ref'], 'order_id' => '11737', 'test_mode' => '0', 'state' => 'created', 'secret_box' => 'synthetic-frozen-secret-box' ); $GLOBALS['lookups'] = $GLOBALS['decrypts'] = $GLOBALS['receive_calls'] = 0; $GLOBALS['received'] = array(); $GLOBALS['get_throws'] = $GLOBALS['receive_throws'] = $GLOBALS['crypto_throws'] = false; }
    function payload( string $status = 'SUCCEEDED', string $transaction = 'MODEL-TX-123' ): array { $p = array( 'order_number' => $GLOBALS['target_ref'], 'status' => $status, 'transaction_id' => $transaction ); $p['sign'] = Signature::generate( $p, 'synthetic-frozen-secret' ); return $p; }
    function request( array $data, string $kind = 'json', ?string $raw = null ): Observed_Request { $r = new Observed_Request( 'POST', '/pc-panapay/v1/hub/notify' ); $r->set_header( 'Content-Type', $kind === 'form' ? 'application/x-www-form-urlencoded' : 'application/json' ); $r->set_body( $raw ?? ( $kind === 'form' ? http_build_query( $data, '', '&', PHP_QUERY_RFC1738 ) : json_encode( $data ) ) ); if ( $kind === 'form' ) { parse_str( $r->get_body(), $post ); $r->set_body_params( $post ); } return $r; }
    function expect( string $name, Observed_Request $r, int $status, int $receives = 0 ): \WP_REST_Response { $body_before = $r->get_body(); $headers_before = $r->get_headers(); $out = Hub::notify( $r ); check( $name . ' [status=' . $out->get_status() . ',receive=' . $GLOBALS['receive_calls'] . ',lookups=' . $GLOBALS['lookups'] . ']', $out->get_status() === $status && $out->get_data() === ( $status === 200 ? 'ok' : 'notification_not_accepted' ) && $GLOBALS['receive_calls'] === $receives ); check( $name . ' preserves raw body and headers', $r->get_body() === $body_before && $r->get_headers() === $headers_before ); return $out; }

    foreach ( array( 'json', 'form' ) as $kind ) {
        reset_model(); $r = request( payload(), $kind ); expect( $kind . ' valid success accepted', $r, 200, 1 );
        check( $kind . ' reaches exactly one native parser and durable receive', $r->json_reads === (int) ( $kind === 'json' ) && $r->form_reads === (int) ( $kind === 'form' ) && $GLOBALS['lookups'] === 1 && $GLOBALS['decrypts'] === 1 && $GLOBALS['received'][0]['status'] === 'SUCCEEDED' );
        reset_model(); expect( $kind . ' valid failure with empty transaction accepted', request( payload( 'FAILED', '' ), $kind ), 200, 1 );
        foreach ( array( 'order_number', 'status', 'transaction_id', 'sign' ) as $field ) {
            reset_model(); $p = payload(); unset( $p[$field] ); expect( $kind . ' missing ' . $field . ' rejected', request( $p, $kind ), 400 ); check( $kind . ' missing ' . $field . ' never reads ledger', $GLOBALS['lookups'] === 0 );
            reset_model(); $p = payload(); $p[$field] = array( 'unexpected' ); expect( $kind . ' array field ' . $field . ' rejected', request( $p, $kind ), 400 );
        }
        reset_model(); $p = payload(); $p['sign'] = str_repeat( '0', 32 ); expect( $kind . ' bad frozen signature rejected', request( $p, $kind ), 400 );
        reset_model(); $p = payload(); $p['sign'] = strtoupper( $p['sign'] ); expect( $kind . ' upper hex signature remains backward-compatible', request( $p, $kind ), 200, 1 );
        reset_model(); expect( $kind . ' empty success transaction rejected', request( payload( 'SUCCEEDED', '' ), $kind ), 400 );
        reset_model(); expect( $kind . ' whitespace transaction rejected', request( payload( 'SUCCEEDED', 'has space' ), $kind ), 400 );
        reset_model(); expect( $kind . ' non-ASCII transaction rejected', request( payload( 'SUCCEEDED', '流水' ), $kind ), 400 );
        reset_model(); expect( $kind . ' UI status alias is not payment authority', request( payload( 'Successed' ), $kind ), 400 );
        reset_model(); $p = payload(); $p['transaction_id'] = str_repeat( 'x', 513 ); expect( $kind . ' overlong field rejected', request( $p, $kind ), 400 );
        reset_model(); $p = payload(); $p['order_number'] = 'PH' . str_repeat( 'b', 32 ); $p['sign'] = Signature::generate( array_diff_key( $p, array( 'sign' => true ) ), 'synthetic-frozen-secret' ); expect( $kind . ' unknown reference rejected', request( $p, $kind ), 400 );
        reset_model(); $GLOBALS['frozen_row']['test_mode'] = '1'; expect( $kind . ' frozen test attempt rejected in production', request( payload(), $kind ), 400 );
        reset_model(); $GLOBALS['frozen_row']['test_mode'] = '1'; $GLOBALS['environment'] = 'local'; expect( $kind . ' frozen test attempt remains allowed locally', request( payload(), $kind ), 200, 1 );
        reset_model(); $raw = $kind === 'json' ? str_pad( json_encode( payload() ), 8192, ' ' ) : http_build_query( payload() ) . '&ignored='; $raw = str_pad( $raw, 8192, 'x' ); expect( $kind . ' exact8192-byte body accepted', request( payload(), $kind, $raw ), 200, 1 );
        reset_model(); $r = request( payload(), $kind, str_repeat( 'x', 8193 ) ); expect( $kind . ' over8192-byte body rejected413 before parsing', $r, 413 ); check( $kind . ' oversized notification performs no parser or ledger call', $r->json_reads === 0 && $r->form_reads === 0 && $GLOBALS['lookups'] === 0 );
    }
    foreach ( array( 'application/json; charset=UTF-8', 'Application/JSON', 'application/problem+json' ) as $content_type ) { reset_model(); $r = request( payload() ); $r->set_header( 'Content-Type', $content_type ); expect( 'native WordPress JSON media detection: ' . $content_type, $r, 200, 1 ); }
    foreach ( array( '{broken', 'null', 'true', '123', '"text"', '[]' ) as $raw ) { reset_model(); $r = request( payload(), 'json', $raw ); $r->set_body_params( payload() ); expect( 'invalid/shape-mismatched JSON never falls back to valid FORM: ' . $raw, $r, 400 ); check( 'selected JSON path never reads fallback FORM', $r->json_reads === 1 && $r->form_reads === 0 ); }
    reset_model(); $r = request( payload(), 'json', http_build_query( payload() ) ); $r->set_body_params( payload() ); expect( 'JSON declaration with form body does not fallback', $r, 400 );
    reset_model(); $r = request( payload(), 'form', json_encode( payload() ) ); expect( 'FORM declaration with JSON body does not fallback', $r, 400 ); check( 'FORM mismatch never consults JSON parser', $r->json_reads === 0 && $r->form_reads === 1 );
    reset_model(); $r = request( payload() ); $r->set_header( 'Content-Type', 'application/octet-stream' ); expect( 'nonJSON arbitrary content type cannot opportunistically decode JSON', $r, 400 );
    reset_model(); $r = request( payload(), 'form' ); $r->remove_header( 'Content-Type' ); expect( 'legacy missing content type retains native FORM behavior', $r, 200, 1 );
    foreach ( array( 'get_throws', 'receive_throws', 'crypto_throws' ) as $failure ) { reset_model(); $GLOBALS[$failure] = true; expect( $failure . ' returns503 and never false-ACKs', request( payload() ), 503, $failure === 'receive_throws' ? 1 : 0 ); }
    reset_model(); $r = request( payload() ); $out = expect( 'successful durable receive precedes ACK', $r, 200, 1 ); ob_start(); $served = Hub::serve_notification( false, $out, $r, null ); $ack = ob_get_clean(); check( 'actual serve_notification emits exact plaintext ok, not JSON', $served === true && $ack === 'ok' );
    reset_model(); $r = request( payload(), 'json', '{broken' ); $out = Hub::notify( $r ); ob_start(); $served = Hub::serve_notification( false, $out, $r, null ); $ack = ob_get_clean(); check( 'actual serve_notification retains rejection plaintext', $served === true && $ack === 'notification_not_accepted' );
    reset_model(); $r = request( payload() ); $r->set_route( '/other' ); ob_start(); $served = Hub::serve_notification( false, new \WP_REST_Response( 'private-data' ), $r, null ); $ack = ob_get_clean(); check( 'ACK override never serves unrelated route', $served === false && $ack === '' );
    check( 'entire fixture has no outbound request', $GLOBALS['outbound'] === 0 );
    $report = array( 'complete' => true, 'checks' => count( $GLOBALS['cases'] ), 'failed' => 0, 'modelOnly' => true, 'nativeWordPressRestRequest' => true, 'nativeWordPressRestResponse' => true, 'wordPressBootstrap' => false, 'realHttp' => false, 'realDatabase' => false, 'realFinancialWrite' => false, 'isolatedBoundaries' => array( 'Hub_Store', 'Crypto', 'WordPress environment/media helper/errors' ), 'cases' => $GLOBALS['cases'], 'sourceHashes' => array( 'hub' => hash_file( 'sha256', dirname( __DIR__ ) . '/includes/class-hub.php' ), 'signature' => hash_file( 'sha256', dirname( __DIR__ ) . '/includes/class-signature.php' ), 'wpRequest' => hash_file( 'sha256', $wp_root . '/wp-includes/rest-api/class-wp-rest-request.php' ) ) );
    if ( isset( $argv[1] ) ) { file_put_contents( $argv[1], json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ); }
    echo 'PASS ', $report['checks'], " real Hub/Signature/native REST parser checks; no HTTP, DB or payment.\n";
}
