<?php
/** Real fingerprint/start/notification/completion code; memory-only Woo/storage/transport boundaries. */
namespace {
    define( 'ABSPATH', __DIR__ . '/' );
    $wp_root = getenv( 'PANAPAY_MODEL_WP_ROOT' ) ?: 'D:/Codex/Sites/vialcove-hq/public';
    if ( ! is_readable( $wp_root . '/wp-includes/plugin.php' ) ) { throw new \RuntimeException( 'Set PANAPAY_MODEL_WP_ROOT to local WordPress source.' ); }
    require $wp_root . '/wp-includes/plugin.php';
    function __( $text, $domain = 'default' ) { return $text; }
    function wp_json_encode( $data ) { return json_encode( $data ); }
    function maybe_serialize( $data ) { return serialize( $data ); }
    function wp_get_environment_type() { return 'production'; }
    function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
    function wp_unslash( $value ) { return $value; }
    function wc_get_is_paid_statuses() { return array( 'processing', 'completed' ); }
    function wc_get_order( $id ) { return isset( $GLOBALS['orders'][$id] ) ? new WC_Order( $id ) : false; }
    function home_url( $path = '/' ) { return 'https://shop.example.invalid' . $path; }
    function wp_parse_url( $value, $part = -1 ) { return parse_url( $value, $part ); }
    function get_woocommerce_currencies() { return array( 'USD' => 'USD', 'EUR' => 'EUR' ); }
    function is_email( $email ) { return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ); }
    function wc_get_price_decimals() { return 2; }
    function wc_format_decimal( $amount, $places = 2 ) { return number_format( (float) $amount, $places, '.', '' ); }
    function wp_get_attachment_image_url( ...$args ) { return ''; }
    function add_query_arg( $args, $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
    function WC() { return new class { public function api_request_url( $name ) { return 'https://shop.example.invalid/?wc-api=' . $name; } }; }
    function wp_remote_post( ...$args ) { ++$GLOBALS['http']; throw new \RuntimeException( 'No live HTTP.' ); }
    function wp_safe_remote_post( ...$args ) { ++$GLOBALS['http']; throw new \RuntimeException( 'No live HTTP.' ); }
    final class WC_Validation { public static function is_phone( $value ) { return true; } }
    final class WC_Order_Item_Product {
        public function __construct( public array $row ) {}
        public function get_id() { return $this->row['id']; } public function get_product_id() { return $this->row['product']; }
        public function get_variation_id() { return $this->row['variation']; } public function get_name() { return $this->row['name']; }
        public function get_quantity() { return $this->row['quantity']; } public function get_total() { return $this->row['total']; }
        public function get_taxes() { return $this->row['taxes']; } public function get_product() { return null; }
    }
    final class WC_Order {
        private array $data;
        public function __construct( private int $id ) { $this->data = unserialize( serialize( $GLOBALS['orders'][$id] ) ); }
        public function get_id() { return $this->id; } public function get_total() { return $this->data['total']; }
        public function get_currency() { return $this->data['currency']; } public function get_items( $type = 'line_item' ) { return $this->data['items']; }
        public function get_address( $type ) { return $this->data[$type]; } public function get_billing_email() { return $this->data['email']; }
        public function get_billing_phone() { return $this->data['phone']; } public function get_payment_method() { return $this->data['gateway']; }
        public function get_meta( $key, $single = true ) { return $this->data['meta'][$key] ?? ''; }
        public function update_meta_data( $key, $value ) { $this->data['meta'][$key] = $value; }
        public function save() { ++$GLOBALS['saves']; $GLOBALS['orders'][$this->id] = unserialize( serialize( $this->data ) ); return $this->id; }
        public function has_status( $statuses ) { return in_array( $this->data['status'], (array) $statuses, true ); }
        public function is_paid() { return $this->has_status( array( 'processing', 'completed' ) ); }
        public function needs_payment() { return ! $this->is_paid() && $this->has_status( array( 'pending', 'failed' ) ); }
        public function update_status( $status, $note = '' ) { $this->data['status'] = $status; if ( $note ) { $this->data['notes'][] = $note; } $this->save(); }
        public function add_order_note( $note ) { $GLOBALS['orders'][$this->id]['notes'][] = $note; }
        public function needs_shipping_address() { return true; } public function get_item_total( $item, ...$args ) { return (float) $item->get_total() / (float) $item->get_quantity(); }
        public function get_customer_ip_address() { return '192.0.2.1'; } public function get_customer_user_agent() { return 'Synthetic memory model'; }
        public function payment_complete( $transaction ) {
            ++$GLOBALS['completion_calls']; $old = $this->data['status']; $this->data['status'] = 'processing'; $this->data['transaction'] = $transaction;
            if ( $GLOBALS['persist_completion'] ) { $this->save(); }
            do_action( 'woocommerce_order_status_processing', $this->id ); do_action( 'woocommerce_order_status_changed', $this->id, $old, 'processing' ); do_action( 'woocommerce_payment_complete', $this->id );
        }
    }
}
namespace VialCove\Panapay {
    final class Gateway {
        public const ID = 'pc_panapay';
        public static function valid_account_id( $id ) { return is_string( $id ) && ( $id === 'default' || preg_match( '/\A[a-f0-9]{32}\z/', $id ) ); }
        public static function native_id( $id ) { return $id === 'default' ? self::ID : self::ID . '_' . $id; }
        public static function account_from_id( $id ) { return $id === self::ID ? 'default' : null; }
    }
    final class Crypto { public static function encrypt( $plain, $purpose = '' ) { return 'MODEL:' . base64_encode( $plain ); } public static function decrypt( $box, $purpose = '' ) { if ( ! str_starts_with( $box, 'MODEL:' ) ) { throw new \RuntimeException( 'model decrypt rejected' ); } return base64_decode( substr( $box, 6 ), true ); } }
    final class Persistence {
        public static function has_gateway( $id, $expected ) { return $GLOBALS['orders'][$id]['gateway'] === $expected; }
        public static function is_paid( $id, $transaction ) { return in_array( $GLOBALS['orders'][$id]['status'], array( 'processing', 'completed' ), true ) && $GLOBALS['orders'][$id]['transaction'] === $transaction; }
        public static function has_status( $id, $statuses ) { return in_array( $GLOBALS['orders'][$id]['status'], $statuses, true ); }
        public static function assert_book( $order, $book ) { if ( $GLOBALS['orders'][$order->get_id()]['meta']['_pc_panapay_sessions'] !== $book ) { throw new \RuntimeException( 'model book mismatch' ); } }
    }
    final class Store {
        public static function acquire( $id ) { ++$GLOBALS['leases']; if ( $GLOBALS['lease_fail'] ) { throw new \RuntimeException( 'lease unavailable' ); } return array( 'id' => $id ); }
        public static function release( $lock ) { ++$GLOBALS['releases']; } public static function assert_owner( $lock ) {}
        public static function creation_ready( $id ) { return $GLOBALS['creation_ready']; } public static function assert_no_completion( $id ) {}
        public static function site_id() { return '1234abcd'; }
        public static function reserve_creation( $id, $attempt ) { return array( 'order_id' => $id, 'attempt_id' => $attempt, 'owner_token' => str_repeat( 'a', 32 ) ); }
        public static function assert_creation( $reservation ) {} public static function set_creation_state( $reservation, $state ) { $GLOBALS['creation_state'] = $state; }
        public static function fail_creation( ...$args ) { $GLOBALS['creation_state'] = 'failed'; }
        public static function claim( $tx, $id, $attempt, $scope = '', $reserve = true ) {
            $GLOBALS['claim_reserves'][] = $reserve; $key = $scope . '|' . $tx;
            if ( $GLOBALS['claim_conflict'] ) { throw new \RuntimeException( 'transaction_reused' ); }
            if ( isset( $GLOBALS['claims'][$key] ) ) { return array( 'state' => $GLOBALS['claims'][$key], 'fresh' => false, 'can_complete' => false ); }
            $state = $reserve && ! $GLOBALS['force_review'] ? 'processing' : 'review'; $GLOBALS['claims'][$key] = $state;
            return array( 'state' => $state, 'fresh' => true, 'can_complete' => $reserve && $state === 'processing' );
        }
        public static function set_transaction_state( $tx, $id, $state, $scope = '' ) { $GLOBALS['claims'][$scope . '|' . $tx] = $state; }
        public static function admin_transactions( $id ) { return array(); }
    }
    final class Configuration {
        public static function load( $account = 'default' ) { return array( 'account_id' => 'default', 'gateway' => 'https://gateway.example.invalid', 'mchid' => 'model', 'secret' => 'model-secret', 'test_mode' => false, 'tag' => '', 'mode' => '2', 'iframe_height' => 530 ); }
        public static function payment_url( $url, $config ) { return $url === 'https://gateway.example.invalid/model-checkout'; }
    }
    final class Provider_Client {
        public static function create( $payload, $config ) {
            ++$GLOBALS['provider_stub_calls']; $book = $GLOBALS['orders'][42]['meta']['_pc_panapay_sessions']; $a = $book['attempts'][$book['active']];
            $GLOBALS['frozen_before_provider'] = isset( $a['payment_fingerprint'] ) && $a['payment_fingerprint'] === Order_Payload::payment_fingerprint( new \WC_Order( 42 ) ) && $a['state'] === 'creating';
            return array( 'state' => 'created', 'type' => 'redirect', 'url' => 'https://gateway.example.invalid/model-checkout' );
        }
    }
}
namespace {
    foreach ( array( 'protocol-error', 'customer-error', 'signature', 'order-payload', 'completion-guard', 'session-service' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
    use VialCove\Panapay\{Order_Payload, Session_Service, Signature, Crypto};
    $GLOBALS['cases'] = array(); $GLOBALS['http'] = 0;
    function check( string $name, bool $pass ): void { $GLOBALS['cases'][] = array( 'name' => $name, 'pass' => $pass ); if ( ! $pass ) { throw new \RuntimeException( 'FAIL ' . $name ); } }
    function fresh( bool $legacy = false, bool $seed = true ): void {
        $address = array( 'first_name' => 'Model', 'last_name' => 'Customer', 'company' => '', 'address_1' => '123 Example', 'address_2' => '', 'city' => 'Example City', 'state' => 'CA', 'postcode' => '90001', 'country' => 'US' );
        $GLOBALS['orders'] = array( 42 => array( 'total' => '80.00', 'currency' => 'USD', 'items' => array( new WC_Order_Item_Product( array( 'id' => 7, 'product' => 11, 'variation' => 13, 'name' => 'Model product', 'quantity' => '2', 'total' => '80.00', 'taxes' => array( 'total' => array( 1 => '0.00' ), 'subtotal' => array( 1 => '0.00' ) ) ) ) ), 'billing' => $address, 'shipping' => $address, 'email' => 'before@example.invalid', 'phone' => '+15550000000', 'gateway' => 'pc_panapay', 'status' => 'pending', 'transaction' => '', 'notes' => array(), 'meta' => array() ) );
        $GLOBALS['completion_calls'] = $GLOBALS['saves'] = $GLOBALS['leases'] = $GLOBALS['releases'] = $GLOBALS['provider_stub_calls'] = 0;
        $GLOBALS['claims'] = $GLOBALS['claim_reserves'] = array(); $GLOBALS['persist_completion'] = $GLOBALS['creation_ready'] = true; $GLOBALS['claim_conflict'] = $GLOBALS['force_review'] = $GLOBALS['lease_fail'] = $GLOBALS['frozen_before_provider'] = false; $GLOBALS['creation_state'] = 'created';
        $GLOBALS['attempt_id'] = str_repeat( 'b', 32 ); $GLOBALS['reference'] = 'PP1234abcd-42-' . $GLOBALS['attempt_id'];
        if ( ! $seed ) { return; }
        $order = new WC_Order( 42 );
        $a = array( 'reference' => $GLOBALS['reference'], 'state' => 'created', 'fingerprint' => Order_Payload::fingerprint( $order ), 'amount' => '80.00', 'currency' => 'USD', 'secret_box' => Crypto::encrypt( 'model-secret' ), 'merchant_scope' => 'model-scope', 'central' => true, 'account_id' => 'default', 'gateway_id' => 'pc_panapay', 'test_mode' => false );
        if ( ! $legacy ) { $a['payment_fingerprint'] = Order_Payload::payment_fingerprint( $order ); }
        $GLOBALS['orders'][42]['meta']['_pc_panapay_sessions'] = array( 'active' => $GLOBALS['attempt_id'], 'attempts' => array( $GLOBALS['attempt_id'] => $a ) );
    }
    function packet( string $status = 'SUCCEEDED', string $transaction = 'MODEL-TRANSACTION' ): array { $data = array( 'order_number' => $GLOBALS['reference'], 'status' => $status, 'transaction_id' => $transaction ); $data['sign'] = Signature::generate( $data, 'model-secret' ); $data['account_id'] = 'default'; return $data; }
    function apply( ?array $data = null ): ?\Throwable { try { Session_Service::apply_notification( $data ?? packet(), true ); return null; } catch ( \Throwable $error ) { return $error; } }
    function attempt(): array { return $GLOBALS['orders'][42]['meta']['_pc_panapay_sessions']['attempts'][$GLOBALS['attempt_id']]; }
    function change_attempt( string $key, $value ): void { $GLOBALS['orders'][42]['meta']['_pc_panapay_sessions']['attempts'][$GLOBALS['attempt_id']][$key] = $value; }
    function paid( string $name ): void { check( $name, null === apply() && $GLOBALS['completion_calls'] === 1 && $GLOBALS['orders'][42]['status'] === 'processing' && attempt()['state'] === 'paid' && in_array( 'applied', $GLOBALS['claims'], true ) ); }
    function held( string $name ): void { $error = apply(); check( $name, null === $error && $GLOBALS['completion_calls'] === 0 && $GLOBALS['orders'][42]['status'] === 'on-hold' && in_array( 'review', $GLOBALS['claims'], true ) && $GLOBALS['claim_reserves'] === array( false ) ); }

    fresh(); $full = Order_Payload::fingerprint( new WC_Order( 42 ) ); $financial = Order_Payload::payment_fingerprint( new WC_Order( 42 ) );
    check( 'both fingerprints are strict SHA256 outputs', preg_match( '/\A[a-f0-9]{64}\z/', $full ) && preg_match( '/\A[a-f0-9]{64}\z/', $financial ) );
    foreach ( array( 'billing', 'shipping' ) as $type ) {
        foreach ( array( 'country' => 'PR', 'state' => '', 'address_1' => '456 Correction', 'city' => 'San Juan', 'postcode' => '00901', 'first_name' => 'Corrected' ) as $field => $value ) {
            fresh(); $before = new WC_Order( 42 ); $old_full = Order_Payload::fingerprint( $before ); $old_fin = Order_Payload::payment_fingerprint( $before ); $GLOBALS['orders'][42][$type][$field] = $value; $after = new WC_Order( 42 );
            check( $type . ' ' . $field . ' affects full but not financial fingerprint', Order_Payload::fingerprint( $after ) !== $old_full && Order_Payload::payment_fingerprint( $after ) === $old_fin ); paid( 'verified new attempt accepts ' . $type . ' ' . $field . ' correction' );
        }
    }
    foreach ( array( 'email' => 'after@example.invalid', 'phone' => '+15551111111' ) as $field => $value ) { fresh(); $GLOBALS['orders'][42][$field] = $value; paid( 'new attempt accepts corrected ' . $field ); }
    fresh(); $GLOBALS['orders'][42]['billing']['country'] = $GLOBALS['orders'][42]['shipping']['country'] = 'PR'; $GLOBALS['orders'][42]['billing']['state'] = $GLOBALS['orders'][42]['shipping']['state'] = ''; $GLOBALS['orders'][42]['email'] = 'corrected@example.invalid'; paid( 'combined US-to-PR and contact correction accepts one verified payment' );
    foreach ( array( 'total' => '81.00', 'currency' => 'EUR' ) as $field => $value ) { fresh(); $before = Order_Payload::payment_fingerprint( new WC_Order( 42 ) ); $GLOBALS['orders'][42][$field] = $value; check( 'financial fingerprint directly binds ' . $field, $before !== Order_Payload::payment_fingerprint( new WC_Order( 42 ) ) ); held( 'new attempt rejects changed order ' . $field ); }
    foreach ( array( 'id' => 8, 'product' => 12, 'variation' => 14, 'name' => 'Different product', 'quantity' => '3', 'total' => '79.00', 'taxes' => array( 'total' => array( 1 => '1.00' ), 'subtotal' => array( 1 => '1.00' ) ) ) as $field => $value ) { fresh(); $GLOBALS['orders'][42]['items'][0]->row[$field] = $value; held( 'new attempt rejects changed item ' . $field ); }
    fresh(); $GLOBALS['orders'][42]['items'][] = new WC_Order_Item_Product( $GLOBALS['orders'][42]['items'][0]->row ); held( 'new attempt rejects added item even when order total unchanged' );
    fresh(); $GLOBALS['orders'][42]['items'] = array(); held( 'new attempt rejects removed items' );
    fresh(); change_attempt( 'amount', '79.00' ); held( 'explicit frozen amount still required independently of matching hash' );
    fresh(); change_attempt( 'currency', 'EUR' ); held( 'explicit frozen currency still required independently of matching hash' );
    fresh( true ); paid( 'legacy attempt without new field accepts unchanged original full fingerprint' );
    fresh( true ); $GLOBALS['orders'][42]['billing']['country'] = 'PR'; held( 'legacy changed address retains original full-fingerprint failclosed policy' );
    fresh( true ); $GLOBALS['orders'][42]['items'][0]->row['quantity'] = '3'; held( 'legacy changed items remain failclosed' );
    foreach ( array( null, '', false, 0, array(), str_repeat( 'A', 64 ), str_repeat( 'a', 63 ), str_repeat( '0', 64 ) ) as $index => $hash ) { fresh(); change_attempt( 'payment_fingerprint', $hash ); held( 'present invalid or mismatched new hash never falls back to valid legacy hash #' . $index ); }
    fresh(); paid( 'initial verified payment completes once' ); check( 'repeat same verified transaction is idempotent', null === apply() && $GLOBALS['completion_calls'] === 1 ); check( 'late failure cannot reverse paid order', null === apply( packet( 'FAILED' ) ) && $GLOBALS['orders'][42]['status'] === 'processing' && $GLOBALS['completion_calls'] === 1 );
    fresh(); $bad = packet(); $bad['sign'] = str_repeat( '0', 32 ); check( 'financial hash does not bypass signature authority', apply( $bad ) instanceof \Throwable && $GLOBALS['completion_calls'] === 0 && $GLOBALS['claim_reserves'] === array() );
    fresh(); $GLOBALS['creation_ready'] = false; check( 'financial hash does not bypass in-flight creation fence', apply() instanceof \Throwable && $GLOBALS['completion_calls'] === 0 && $GLOBALS['claim_reserves'] === array() );
    fresh(); $GLOBALS['claim_conflict'] = true; check( 'financial hash does not bypass transaction ownership conflict', apply() instanceof \Throwable && $GLOBALS['completion_calls'] === 0 );
    fresh(); $GLOBALS['force_review'] = true; check( 'existing ledger review fence cannot grant completion', null === apply() && $GLOBALS['completion_calls'] === 0 );
    fresh(); $GLOBALS['persist_completion'] = false; $error = apply(); check( 'actual Completion_Guard rejects unpersisted native paid state', $error instanceof \Throwable && $error->getCode() === 503 && $GLOBALS['completion_calls'] === 1 && $GLOBALS['orders'][42]['status'] === 'pending' && attempt()['state'] === 'confirmed' );
    check( 'retry after uncertain completion never invokes native completion twice', apply() instanceof \Throwable && $GLOBALS['completion_calls'] === 1 );
    fresh( false, false ); $session = Session_Service::start( 42 ); $book = $GLOBALS['orders'][42]['meta']['_pc_panapay_sessions']; $new = $book['attempts'][$book['active']];
    check( 'actual start freezes new financial hash durably before provider boundary', $GLOBALS['frozen_before_provider'] && $GLOBALS['provider_stub_calls'] === 1 && isset( $new['payment_fingerprint'] ) && $new['payment_fingerprint'] === Order_Payload::payment_fingerprint( new WC_Order( 42 ) ) && $new['fingerprint'] === Order_Payload::fingerprint( new WC_Order( 42 ) ) && $new['state'] === 'created' && $session['url'] === 'https://gateway.example.invalid/model-checkout' );
    check( 'model performs no HTTP or real persistence', $GLOBALS['http'] === 0 );
    $report = array( 'complete' => true, 'checks' => count( $GLOBALS['cases'] ), 'failed' => 0, 'modelOnly' => true, 'realHttp' => false, 'realDatabase' => false, 'realOrders' => false, 'realFinancialWrite' => false, 'actualProductionCode' => array( 'Order_Payload::fingerprint/payment_fingerprint', 'Session_Service::start/apply_notification', 'Signature', 'Completion_Guard', 'WordPress hook dispatcher' ), 'isolatedBoundaries' => array( 'WC_Order/items and native persistence', 'Store lease/transaction-fence contract', 'Crypto', 'Provider creation returns static fixture only' ), 'cases' => $GLOBALS['cases'], 'sourceHashes' => array() );
    foreach ( array( 'order-payload', 'session-service', 'signature', 'completion-guard' ) as $file ) { $report['sourceHashes'][$file] = hash_file( 'sha256', dirname( __DIR__ ) . '/includes/class-' . $file . '.php' ); }
    if ( isset( $argv[1] ) ) { file_put_contents( $argv[1], json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ); }
    echo 'PASS ', $report['checks'], " actual financial-fingerprint/start/notification/guard checks; memory only.\n";
}
