<?php
/**
 * M10（HQ-13、OR-03）店铺端纯逻辑测试：订单摘要不含个人信息、上报幂等、补报、历史回填（试运行 / 正式 / 重复执行）、
 * 退款标记只写订单元和备注（全额 / 部分 / 撤销），不创建 WooCommerce 退款对象。
 * 假订单和假总后台，不需要 WordPress、WooCommerce、数据库或网络。运行：php -n -d extension=mbstring m10-order-report-pure.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'PEPTIDE_HQ_URL', 'https://hq.example.test/' );
	date_default_timezone_set( 'UTC' );
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public $data = array() ) {}
		public function get_error_message(): string { return $this->message; }
	}
	class WP_REST_Request {
		public function __construct( private array $json = array(), private array $params = array() ) {}
		public function get_json_params() { return $this->json; }
		public function get_param( string $k ) { return $this->params[ $k ] ?? null; }
	}
	final class Stamp { public function __construct( private int $ts ) {} public function getTimestamp(): int { return $this->ts; } }
	class WC_Order {
		public array $notes = array();
		public int $saves   = 0;
		public function __construct( public int $id, public string $status, public string $total, public int $created, public int $paid_ts = 0, public string $email = '', public array $meta = array() ) {}
		public function get_id(): int { return $this->id; }
		public function get_type(): string { return 'shop_order'; }
		public function get_status(): string { return $this->status; }
		public function get_total() { return $this->total; }
		public function get_currency(): string { return 'USD'; }
		public function get_order_number(): string { return (string) $this->id; }
		public function get_billing_email(): string { return $this->email; }
		public function get_date_created() { return $this->created ? new Stamp( $this->created ) : null; }
		public function get_date_paid() { return $this->paid_ts ? new Stamp( $this->paid_ts ) : null; }
		public function get_meta( string $k ) { return $this->meta[ $k ] ?? ''; }
		public function update_meta_data( string $k, $v ): void { $this->meta[ $k ] = $v; }
		public function save_meta_data(): void { ++$this->saves; }
		public function add_order_note( string $note, $customer = 0, $by_user = false ): int { $this->notes[] = $note; return count( $this->notes ); }
	}
	function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
	function get_option( string $k, $default = false ) { return $GLOBALS['options'][ $k ] ?? $default; }
	function update_option( string $k, $v, $autoload = null ): bool { $GLOBALS['options'][ $k ] = $v; return true; }
	function add_action( ...$a ): void {}
	function add_filter( ...$a ): void {}
	function do_action( ...$a ): void { $GLOBALS['did'][] = $a; }
	function apply_filters( string $hook, $value, ...$rest ) { return $value; }
	function wp_next_scheduled( string $hook ) { return false; }
	function sanitize_text_field( $s ): string { return trim( strip_tags( (string) $s ) ); }
	function wp_strip_all_tags( $s ): string { return trim( strip_tags( (string) $s ) ); }
	function wp_json_encode( $v, int $flags = 0 ) { return json_encode( $v, $flags ); }
	function wp_generate_uuid4(): string { static $n = 0; return '00000000-0000-4000-8000-' . str_pad( (string) ++$n, 12, '0', STR_PAD_LEFT ); }
	function wp_date( string $format, ?int $ts = null ): string { return gmdate( $format, $ts ?? time() ); }
	function wp_timezone(): \DateTimeZone { return new \DateTimeZone( 'UTC' ); }
	function trailingslashit( string $s ): string { return rtrim( $s, '/' ) . '/'; }
	function add_query_arg( string $k, string $v, string $url ): string { return $url . '?' . $k . '=' . $v; }
	function get_woocommerce_currency(): string { return 'USD'; }
	function wc_get_is_paid_statuses(): array { return array( 'processing', 'completed' ); }
	function wc_get_order( $id ) { return $GLOBALS['orders'][ (int) $id ] ?? false; }
	function wc_get_orders( array $args ): array {
		$orders = array_values( $GLOBALS['orders'] );
		if ( isset( $args['status'] ) ) { $orders = array_values( array_filter( $orders, static fn( $o ) => in_array( 'wc-' . $o->get_status(), (array) $args['status'], true ) ) ); }
		if ( isset( $args['date_created'] ) ) { $min = (int) ltrim( (string) $args['date_created'], '>=' ); $orders = array_values( array_filter( $orders, static fn( $o ) => $o->created >= $min ) ); }
		$limit  = (int) ( $args['limit'] ?? 10 );
		$orders = array_slice( $orders, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $limit, $limit );
		return 'ids' === ( $args['return'] ?? '' ) ? array_map( static fn( $o ) => $o->get_id(), $orders ) : $orders;
	}
	/** 假总后台：记下每次请求；可以设为连不上，或拒收某些订单。 */
	function wp_remote_post( string $url, array $args ) {
		$body                = json_decode( $args['body'], true );
		$GLOBALS['posts'][]  = array( 'url' => $url, 'headers' => $args['headers'], 'orders' => $body['orders'], 'nonce' => $body['nonce'] );
		if ( $GLOBALS['hq_down'] ) { return new WP_Error( 'http', 'HQ unreachable' ); }
		$ids = array_column( $body['orders'], 'store_order_id' );
		return array( 'code' => 200, 'body' => json_encode( array( 'accepted' => array_values( array_diff( $ids, $GLOBALS['hq_reject'] ) ), 'rejected' => array() ) ) );
	}
	function wp_remote_retrieve_response_code( array $r ): int { return $r['code']; }
	function wp_remote_retrieve_body( array $r ): string { return $r['body']; }
	$GLOBALS['options']   = array();
	$GLOBALS['orders']    = array();
	$GLOBALS['posts']     = array();
	$GLOBALS['hq_down']   = false;
	$GLOBALS['hq_reject'] = array();
	$GLOBALS['did']       = array();
}
namespace PeptideCore {
	final class Hq_Connector {
		public const NS = 'peptide/v1';
		public static function configured(): bool { return true; }
		public static function settings(): array { return array( 'key' => str_repeat( 'k', 64 ), 'uuid' => '11111111-1111-4111-8111-111111111111' ); }
		public static function sign( string $key, string $method, string $route, string $query, int $time, string $body ): string { return hash_hmac( 'sha256', $method . "\n" . $route . "\n" . $query . "\n" . $time . "\n" . hash( 'sha256', $body ), $key ); }
		public static function verify() { return true; }
	}
	final class Test_Orders { public static function is_test_order( $order ): bool { return '1' === (string) $order->get_meta( '_pc_test_order' ); } }
	final class Overview { public static function is_test_order( $order ): bool { return false; } }
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-series-sync.php';
	require dirname( __DIR__ ) . '/includes/class-order-report.php';
	use PeptideCore\Order_Report as R;
	use PeptideCore\Series_Sync;

	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }
	$t0 = 1794300000;

	// ---- 纯函数 ----
	check( 'cents rounds', 1999 === R::cents( '19.99' ) && 15000 === R::cents( 150 ) && 0 === R::cents( '' ) && 2990 === R::cents( '29.9' ) );
	check( 'fingerprint ignores key order', R::fingerprint( array( 'a' => 1, 'b' => 'x' ) ) === R::fingerprint( array( 'b' => 'x', 'a' => 1 ) ) && R::fingerprint( array( 'a' => 1 ) ) !== R::fingerprint( array( 'a' => 2 ) ) );
	check( 'reported meta parses', array( 'fingerprint' => str_repeat( 'a', 32 ), 'at' => 55 ) === R::parse_reported( str_repeat( 'a', 32 ) . '|55' ) && array( 'fingerprint' => '', 'at' => 0 ) === R::parse_reported( '' ) && '' === R::parse_reported( 'junk|9' )['fingerprint'] );
	check( 'reportable rule', R::reportable( true, 0, false ) && R::reportable( false, 5, false ) && R::reportable( false, 0, true ) && ! R::reportable( false, 0, false ) );
	check( 'refund state', 'none' === R::refund_state( 1000, 0 ) && 'partial' === R::refund_state( 1000, 999 ) && 'full' === R::refund_state( 1000, 1000 ) );
	check( 'refund payload cleaned', array( 'refunded_cents' => 500, 'state' => 'partial', 'note' => 'a b' ) === R::clean_refund( array( 'refunded_cents' => 500, 'state' => 'full', 'note' => " a \n <b>b</b> " ), 1000 ) );
	check( 'refund payload refuses bad amounts', null === R::clean_refund( array( 'refunded_cents' => 1001 ), 1000 ) && null === R::clean_refund( array( 'refunded_cents' => -1 ), 1000 ) && null === R::clean_refund( array( 'refunded_cents' => '5.5' ), 1000 ) && null === R::clean_refund( array(), 1000 ) );
	check( 'ad id from landing query', '1202' === R::ad_id_from_query( '?utm_source=facebook&ad_id=1202&utm_content=x' ) && 'ad-name_1' === R::ad_id_from_query( 'utm_content=ad-name_1' ) && '' === R::ad_id_from_query( 'utm_source=fb' ) && 'ab' === R::ad_id_from_query( 'ad_id=a%20b' ) );

	// ---- 订单带上所属系列（Series_Sync） ----
	update_option( Series_Sync::OPTION, array( 'series_id' => 3, 'slug' => 'series-3', 'name' => 'S3' ) );
	check( 'series id read from the option', 3 === Series_Sync::series_id() );
	$new = new WC_Order( 0, 'pending', '10.00', $t0 );
	Series_Sync::stamp_order( $new );
	check( 'new order stamped once', '3' === $new->meta['_pc_series_id'] );
	$old = new WC_Order( 9, 'processing', '10.00', $t0, $t0, '', array( '_pc_series_id' => '1' ) );
	Series_Sync::stamp_order( $old );
	check( 'saved order keeps its series', '1' === $old->meta['_pc_series_id'] );

	// ---- 摘要：只有汇总字段，没有个人信息 ----
	$GLOBALS['orders'] = array(
		101 => new WC_Order( 101, 'processing', '149.99', $t0, $t0 + 100, ' Buyer@Example.Test ', array( '_pc_series_id' => '3' ) ),
		102 => new WC_Order( 102, 'completed', '80.00', $t0 + 10, $t0 + 200, 'b@example.test' ),
		103 => new WC_Order( 103, 'pending', '55.00', $t0 + 20 ),
		104 => new WC_Order( 104, 'processing', '50.00', $t0 + 30, $t0 + 300, '1', array( '_pc_test_order' => '1' ) ),
		105 => new WC_Order( 105, 'cancelled', '20.00', $t0 + 40 ),
	);
	$s = R::summary( $GLOBALS['orders'][101] );
	check( 'summary fields are exactly the contract', array( 'store_order_id', 'order_number', 'status', 'paid', 'is_test', 'currency', 'total_cents', 'created_at', 'paid_at', 'order_day', 'series_id', 'email_hash', 'utm_source', 'ad_id' ) === array_keys( $s ) );
	check( 'summary values', 101 === $s['store_order_id'] && true === $s['paid'] && false === $s['is_test'] && 14999 === $s['total_cents'] && $t0 + 100 === $s['paid_at'] && gmdate( 'Y-m-d', $t0 ) === $s['order_day'] && 3 === $s['series_id'] );
	check( 'email only as a hash of the lower-cased address', hash( 'sha256', 'buyer@example.test' ) === $s['email_hash'] && false === strpos( json_encode( $s ), 'xample' ) );
	check( 'order without series meta uses the store series', 3 === R::summary( $GLOBALS['orders'][102] )['series_id'] );
	check( 'test order flagged', true === R::summary( $GLOBALS['orders'][104] )['is_test'] );

	// ---- 上报：付过款的才报；成功后记指纹；内容没变不再报 ----
	$first = R::report( array( 101, 102, 103, 104, 105, 999 ) );
	check( 'paid orders sent, unpaid skipped', array( 'sent' => 3, 'accepted' => 3, 'skipped' => 3, 'error' => '' ) === $first );
	$post = $GLOBALS['posts'][0];
	check( 'one signed request to the HQ route', 1 === count( $GLOBALS['posts'] ) && 'https://hq.example.test/?rest_route=%2Fpeptide-hq%2Fv1%2Forders%2Freport' === $post['url'] && '11111111-1111-4111-8111-111111111111' === $post['headers']['X-PC-HQ-Site'] && 64 === strlen( $post['headers']['X-PC-HQ-Sign'] ) && array( 101, 102, 104 ) === array_column( $post['orders'], 'store_order_id' ) );
	check( 'reported fingerprint saved on the order', '' !== R::parse_reported( $GLOBALS['orders'][101]->meta['_pc_hq_reported'] )['fingerprint'] && 1 === $GLOBALS['orders'][101]->saves && ! isset( $GLOBALS['orders'][103]->meta['_pc_hq_reported'] ) );
	check( 'repeat report sends nothing', array( 'sent' => 0, 'accepted' => 0, 'skipped' => 6, 'error' => '' ) === R::report( array( 101, 102, 103, 104, 105, 999 ) ) && 1 === count( $GLOBALS['posts'] ) );

	// ---- 状态变化要让总后台知道（即使不再是已付款状态）；总后台连不上时下次补报 ----
	$GLOBALS['orders'][102]->status = 'cancelled';
	$GLOBALS['hq_down']             = true;
	$down = R::report( array( 102 ) );
	check( 'HQ down: sent but not accepted, error kept', 1 === $down['sent'] && 0 === $down['accepted'] && 'HQ unreachable' === $down['error'] );
	$GLOBALS['hq_down'] = false;
	$again = R::reconcile();
	check( 'reconcile re-sends only the changed order', 1 === $again['sent'] && 1 === $again['accepted'] && array( 102 ) === array_column( end( $GLOBALS['posts'] )['orders'], 'store_order_id' ) && false === end( $GLOBALS['posts'] )['orders'][0]['paid'] );
	check( 'second reconcile is quiet', 0 === R::reconcile()['sent'] );
	check( 'every request carries a fresh nonce', count( $GLOBALS['posts'] ) === count( array_unique( array_column( $GLOBALS['posts'], 'nonce' ) ) ) );
	$GLOBALS['hq_reject'] = array( 101 );
	$GLOBALS['orders'][101]->total = '159.99';
	$partial = R::report( array( 101 ) );
	check( 'order the HQ did not accept stays unreported', 1 === $partial['sent'] && 0 === $partial['accepted'] && 1 === $GLOBALS['orders'][101]->saves );
	$GLOBALS['hq_reject'] = array();
	check( 'accepted on the next try', 1 === R::report( array( 101 ) )['accepted'] );

	// ---- 分批：每批最多 50 条 ----
	$GLOBALS['orders'] = array();
	for ( $i = 1; $i <= 120; $i++ ) {
		$GLOBALS['orders'][ $i ] = new WC_Order( $i, $i % 10 ? 'processing' : 'pending', '10.00', $t0 + $i, $i % 10 ? $t0 + $i : 0, 'c' . $i . '@example.test', 7 === $i ? array( '_pc_test_order' => '1' ) : array() );
	}
	$GLOBALS['posts'] = array();

	// ---- 回填：先试运行（不改订单、不上报），再正式运行，重复运行没有新动作 ----
	$dry = R::backfill( true );
	check( 'dry run counts paid orders only', 108 === $dry['orders'] && 1 === $dry['test_orders'] && 107000 === $dry['total_cents'] && 108 === $dry['missing_series'] && 0 === $dry['stamped'] && 108 === $dry['to_report'] && 0 === $dry['already_reported'] );
	check( 'dry run writes and sends nothing', array() === $GLOBALS['posts'] && 0 === array_sum( array_map( static fn( $o ) => $o->saves, $GLOBALS['orders'] ) ) && ! isset( $GLOBALS['orders'][1]->meta['_pc_series_id'] ) );
	check( 'dry run is repeatable', $dry === R::backfill( true ) );
	check( 'months listed', array( gmdate( 'Y-m', $t0 ) => array( 'orders' => 107, 'total_cents' => 107000 ) ) === $dry['months'] );
	$real = R::backfill( false );
	check( 'real run stamps series and reports everything', 108 === $real['stamped'] && 108 === $real['sent'] && 108 === $real['accepted'] && array() === $real['errors'] && '3' === $GLOBALS['orders'][1]->meta['_pc_series_id'] );
	check( 'real run totals equal the dry run', $dry['orders'] === $real['orders'] && $dry['total_cents'] === $real['total_cents'] && $dry['test_orders'] === $real['test_orders'] );
	check( 'batches of at most 50', array( 50, 50, 8 ) === array_map( static fn( $p ) => count( $p['orders'] ), $GLOBALS['posts'] ) );
	$sent_before = count( $GLOBALS['posts'] );
	$third = R::backfill( false );
	check( 'repeat run has nothing left to do', 0 === $third['to_report'] && 108 === $third['already_reported'] && 0 === $third['missing_series'] && 0 === $third['sent'] && $sent_before === count( $GLOBALS['posts'] ) );
	check( 'since filter', 27 === R::backfill( true, $t0 + 91 )['orders'] );
	$digest = R::digest();
	check( 'digest matches the backfill report', 107 === $digest['count'] && 107000 === $digest['total_cents'] && 1 === $digest['test_count'] && 0 === $digest['refunded_cents'] );

	// ---- 退款标记：只写两个订单元和一条备注 ----
	$o      = $GLOBALS['orders'][1];
	$refund = static fn( array $p ) => R::refund_route( new WP_REST_Request( $p ) );
	$saves  = $o->saves;
	$res    = $refund( array( 'order_id' => 1, 'refunded_cents' => 400, 'state' => 'partial', 'note' => 'chargeback' ) );
	check( 'partial refund written', array( 'order_id' => 1, 'refunded_cents' => 400, 'state' => 'partial', 'changed' => true ) === $res && '400' === $o->meta['_pc_refund_cents'] && 'partial' === $o->meta['_pc_refund_state'] );
	check( 'one private note, order status untouched', 1 === count( $o->notes ) && false !== strpos( $o->notes[0], '$4.00' ) && false !== strpos( $o->notes[0], 'chargeback' ) && 'processing' === $o->status && $saves + 1 === $o->saves );
	$refund( array( 'order_id' => 1, 'refunded_cents' => 400, 'state' => 'partial', 'note' => 'chargeback' ) );
	check( 'same mark again adds no second note', 1 === count( $o->notes ) && $saves + 1 === $o->saves );
	$res = $refund( array( 'order_id' => 1, 'refunded_cents' => 1000, 'state' => 'full', 'note' => '' ) );
	check( 'full refund written', 'full' === $res['state'] && '1000' === $o->meta['_pc_refund_cents'] && 'full' === $o->meta['_pc_refund_state'] && 2 === count( $o->notes ) );
	check( 'digest reflects the marked refund', 1000 === R::digest()['refunded_cents'] && 107 === R::digest()['count'] );
	$res = $refund( array( 'order_id' => 1, 'refunded_cents' => 0, 'state' => 'none', 'note' => '' ) );
	check( 'void restores the order', 'none' === $res['state'] && '0' === $o->meta['_pc_refund_cents'] && 'none' === $o->meta['_pc_refund_state'] && 3 === count( $o->notes ) && false !== strpos( $o->notes[2], '$10.00' ) );
	check( 'over-refund and unknown order refused', is_wp_error( $refund( array( 'order_id' => 1, 'refunded_cents' => 1001 ) ) ) && is_wp_error( $refund( array( 'order_id' => 99999, 'refunded_cents' => 1 ) ) ) && '0' === $o->meta['_pc_refund_cents'] );
	check( 'refund marking leaves the report fingerprint alone', R::fingerprint( R::summary( $o ) ) === R::parse_reported( $o->meta['_pc_hq_reported'] )['fingerprint'] );
	$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-order-report.php' );
	check( 'never creates a WooCommerce refund or changes status', false === strpos( $source, 'wc_create_refund' ) && false === strpos( $source, 'update_status' ) && false === strpos( $source, 'set_status' ) );

	echo 'PASS ', $checks, " store-side M10 order report and refund checks; no WordPress, database, or network.\n";
}
