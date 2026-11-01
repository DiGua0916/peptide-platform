<?php
/** No WP/bootstrap/network. Execute actual report classes against bounded in-memory facts. */
namespace PeptideCore\Tracking {
	class Schema { public static bool $installed = true; public static function installed(): bool { return self::$installed; } public static function table( string $name ): string { return 'track_' . $name; } }
	class Server_Events { public const ORDER_VISIT_META = 'visit'; public const ORDER_VISITOR_META = 'visitor'; }
}
namespace PeptideCore { class Overview { public static function is_test_order( $order ): bool { return $order->test; } } }
namespace PeptideCore\Api { class Errors { public static function invalid( array $fields ) { return new \WP_Error( 'invalid', 'invalid', $fields ); } } }
namespace PeptideCore\Data {
	class Operations { public static array $orders = array(); public static function paid_orders( int $from, int $to ): array { return array_values( array_filter( self::$orders, static fn( $o ) => $o->created >= $from && $o->created < $to ) ); } }
	class Range { public static function change( float $a, float $b ): ?float { return $b ? round( ( $a - $b ) / $b * 100, 2 ) : null; } }
}
namespace {
	define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
	class WP_Error { public function __construct( ...$args ) {} }
	class WP_REST_Response { protected $data; private int $status; private array $headers; public function __construct( $data = null, int $status = 200, array $headers = array() ) { $this->data = $data; $this->status = $status; $this->headers = $headers; } public function get_data() { return $this->data; } public function get_status(): int { return $this->status; } public function get_headers(): array { return $this->headers; } public function header( $key, $value ): void { $this->headers[ $key ] = $value; } }
	class WP_REST_Request { private array $params; private string $method; public function __construct( array $params = array(), string $method = 'GET' ) { $this->params = $params; $this->method = $method; } public function get_params(): array { return $this->params; } public function get_route(): string { return '/peptide/v1/data/sources'; } public function get_method(): string { return $this->method; } }
	class WC_Order_Item_Product { public function get_id() { return 1; } public function get_product_id() { return 10; } public function get_variation_id() { return 11; } public function get_quantity() { return 3; } public function get_subtotal() { return 30; } public function get_total() { return 27; } }
	class WC_Order {
		public bool $test = false; public int $created = 15;
		public function __construct( public int $id, public $visit, public $visitor, public float $total = 20, public float $refund = 5, public int $customer = 0, public string $email = 'synthetic@example.test' ) {}
		public function get_id() { return $this->id; } public function get_meta( $key ) { return $this->$key; } public function get_total() { return $this->total; } public function get_total_refunded() { return $this->refund; } public function get_customer_id() { return $this->customer; } public function get_billing_email() { return $this->email; }
		public function get_items() { return array( new WC_Order_Item_Product() ); } public function get_total_refunded_for_item( $id ) { return $this->refund; } public function get_qty_refunded_for_item( $id ) { return -1; }
	}
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function __( $value, $domain = '' ) { return $value; }
	function home_url() { return 'https://example.test'; }
	function wp_parse_url( $url, $part = -1 ) { return parse_url( $url, $part ); }
	function sanitize_text_field( $value ) { return strip_tags( $value ); }
	function wc_get_product( $id ) { return false; }
	function get_woocommerce_currency() { return 'USD'; }
	function WC() { return (object) array( 'countries' => new class { public function get_countries() { return array( 'US' => 'United States', 'DE' => 'Germany', 'BV' => 'Bouvet Island' ); } } ); }
	if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $value, $offset, $length ) { return implode( '', array_slice( preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY ), $offset, $length ) ); } }
	if ( ! function_exists( 'mb_stripos' ) ) { function mb_stripos( $haystack, $needle ) { return stripos( $haystack, $needle ); } }
	final class Memory_DB {
		public string $last_error = ''; public array $visits = array(); public array $events = array(); public array $queries = array();
		public function prepare( string $sql, ...$args ): string { if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; } $i = 0; return preg_replace_callback( '/%[ds]/', static function ( $m ) use ( &$i, $args ) { $v = $args[ $i++ ]; return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'"; }, $sql ); }
		public function get_results( string $sql, $format ): array {
			$this->queries[] = $sql; if ( $this->last_error ) { return array(); }
			$is_event = str_contains( $sql, 'FROM track_events' ); $rows = array_values( $is_event ? $this->events : $this->visits );
			if ( preg_match( '/(?:visit_id|(?<![a-z_])id) IN \(([0-9,]+)\)/', $sql, $m ) ) { $ids = array_map( 'intval', explode( ',', $m[1] ) ); $key = $is_event ? 'visit_id' : 'id'; $rows = array_values( array_filter( $rows, static fn( $r ) => in_array( (int) $r[ $key ], $ids, true ) ) ); }
			if ( preg_match( '/(?:s\.)?start_ms >= ([0-9]+) AND (?:s\.)?start_ms < ([0-9]+)/', $sql, $m ) ) { $rows = array_values( array_filter( $rows, static fn( $r ) => $r['start_ms'] >= (int) $m[1] && $r['start_ms'] < (int) $m[2] ) ); }
			if ( preg_match( '/occurred_ms >= ([0-9]+) AND occurred_ms < ([0-9]+)/', $sql, $m ) ) { $rows = array_values( array_filter( $rows, static fn( $r ) => $r['occurred_ms'] >= (int) $m[1] && $r['occurred_ms'] < (int) $m[2] ) ); }
			if ( preg_match( '/occurred_ms < ([0-9]+) AND \(occurred_ms < ([0-9]+) OR \(occurred_ms = ([0-9]+) AND id < ([0-9]+)\)\)/', $sql, $m ) ) { $rows = array_values( array_filter( $rows, static fn( $r ) => $r['occurred_ms'] < (int) $m[1] && ( $r['occurred_ms'] < (int) $m[2] || ( $r['occurred_ms'] === (int) $m[3] && $r['id'] < (int) $m[4] ) ) ) ); }
			if ( preg_match( '/(?:s\.)?id > ([0-9]+)/', $sql, $m ) ) { $rows = array_values( array_filter( $rows, static fn( $r ) => $r['id'] > (int) $m[1] ) ); }
			if ( str_contains( $sql, 's.is_test=0' ) ) { $rows = array_values( array_filter( $rows, static fn( $r ) => !$r['is_test'] && $r['visitor_id'] > 0 ) ); }
			if ( str_contains( $sql, 'pageviews = 1' ) ) { $rows = array_values( array_filter( $rows, static fn( $r ) => $r['pageviews'] === 1 ) ); }
			if ( preg_match( "/type = '([^']+)'/", $sql, $m ) ) { $rows = array_values( array_filter( $rows, static fn( $r ) => $r['type'] === $m[1] ) ); }
			elseif ( preg_match( '/type IN \(([^)]+)\)/', $sql, $m ) ) { preg_match_all( "/'([^']+)'/", $m[1], $types ); $rows = array_values( array_filter( $rows, static fn( $r ) => in_array( $r['type'], $types[1], true ) ) ); }
			if ( str_contains( $sql, 'product_id > 0' ) ) { $rows = array_values( array_filter( $rows, static fn( $r ) => $r['product_id'] > 0 ) ); }
			if ( str_contains( $sql, 'GROUP BY' ) ) {
				$groups = array(); $product = str_contains( $sql, 'GROUP BY product_id' ); $variation = str_contains( $sql, 'GROUP BY product_id, variation_id' ); $bounce = str_contains( $sql, 'GROUP BY landing_path' );
				foreach ( $rows as $r ) { $key = $product ? $r['product_id'] . '|' . ( $variation ? $r['variation_id'] : $r['type'] ) : strtolower( $r[ $bounce ? 'landing_path' : 'path' ] ); if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = $r + array( 'n' => 0, 'views' => 0, 'units' => 0, '_visitors' => array() ); } ++$groups[ $key ]['n']; ++$groups[ $key ]['views']; $groups[ $key ]['_visitors'][ $r['visitor_id'] ] = true; $data = json_decode( $r['data'] ?? '{}', true ); $groups[ $key ]['units'] += round( (float) ( $data['quantity'] ?? 0 ), 2 ); }
				foreach ( $groups as &$g ) { $n = count( $g['_visitors'] ); $g['users'] = $n; $g['visitors'] = $n; $g['bounced'] = $n; unset( $g['_visitors'] ); } unset( $g ); return array_values( $groups );
			}
			usort( $rows, static fn( $a, $b ) => str_contains( $sql, 'occurred_ms DESC' ) ? ( $b['occurred_ms'] <=> $a['occurred_ms'] ?: $b['id'] <=> $a['id'] ) : $a['id'] <=> $b['id'] );
			return str_contains( $sql, 'LIMIT 500' ) ? array_slice( $rows, 0, 500 ) : $rows;
		}
	}
	$plugin = dirname( __DIR__ );
	foreach ( array( 'tracking/class-attribution.php', 'tracking/class-page-context.php', 'data/class-params.php', 'data/class-report-dimensions.php', 'data/class-report-filter.php', 'data/class-visit-metrics.php', 'data/class-landing.php', 'data/class-products.php', 'data/class-page-visits.php', 'data/class-sources.php', 'data/class-csv-response.php', 'data/class-report-exports.php' ) as $file ) { require $plugin . '/includes/' . $file; }
	use PeptideCore\Data\Report_Dimensions as D; use PeptideCore\Data\Report_Filter as F; use PeptideCore\Data\Sources; use PeptideCore\Data\Landing; use PeptideCore\Data\Products; use PeptideCore\Data\Page_Visits; use PeptideCore\Data\Operations; use PeptideCore\Data\Report_Exports; use PeptideCore\Data\Csv_Response;
	$passed = 0; $check = static function ( string $name, bool $ok ) use ( &$passed ): void { if ( ! $ok ) { throw new \RuntimeException( 'FAIL ' . $name ); } ++$passed; echo 'PASS ', $name, "\n"; };
	$wpdb = new Memory_DB();
	$visit = static function ( int $id, int $visitor, $source = 'A', $medium = 'one', string $host = 'entry.test' ): array { return array( 'id' => $id, 'visitor_id' => $visitor, 'user_id' => 1, 'stage' => 2, 'start_ms' => 10000, 'landing_path' => '/P' . $id, 'pageviews' => 1, 'is_test' => 0, 'attribution_version' => 1, 'attribution_ms' => 10001, 'attribution_host' => $host, 'attribution_path' => '/P' . $id, 'attribution_query' => 'utm_source=' . rawurlencode( (string) $source ), 'utm_source' => $source, 'utm_medium' => $medium, 'referrer_host' => null ); };
	$event = static function ( int $id, int $visit, int $visitor, int $at, $geo = null, string $type = 'page_view' ): array { $context = array( 'version' => 1, 'kind' => 'content' ); if ( null !== $geo ) { $context['geo'] = $geo; } return array( 'id' => $id, 'visit_id' => $visit, 'visitor_id' => $visitor, 'occurred_ms' => $at, 'type' => $type, 'path' => '/P' . $visit, 'product_id' => 10, 'variation_id' => 11, 'data' => json_encode( array( 'quantity' => 2, 'page_context' => $context ) ) ); };
	$wpdb->visits = array( 1 => $visit( 1, 1 ), 2 => $visit( 2, 1, 'A', 'two' ), 3 => $visit( 3, 2, 'a' ), 4 => $visit( 4, 3, null ), 5 => $visit( 5, 4, '' ), 6 => $visit( 6, 5, 'unobserved' ), 7 => $visit( 7, 6, 'late' ) ); $wpdb->visits[7]['attribution_ms'] = 20000;
	$wpdb->events = array( $event( 1, 1, 1, 11000, array( 'status' => 'known', 'country_code' => 'DE' ) ), $event( 2, 1, 1, 12000 ), $event( 3, 1, 1, 13000, array( 'status' => 'unknown' ) ), $event( 4, 2, 1, 11000, array( 'status' => 'known', 'country_code' => 'US' ) ), $event( 5, 2, 1, 11000, array( 'status' => 'unavailable' ) ), $event( 6, 2, 999, 19000, array( 'status' => 'known', 'country_code' => 'DE' ) ), $event( 7, 3, 2, 20000, array( 'status' => 'known', 'country_code' => 'US' ) ), $event( 8, 1, 1, 15000, null, 'product_view' ), $event( 9, 1, 1, 15000, null, 'add_to_cart' ) );
	$facts = D::visit_facts( array_keys( $wpdb->visits ), 20000 );
	$check( 'latest unknown replaces known; no-geo does not erase before replacement', $facts[1]['country']['state'] === 'unknown' && D::visit_facts( array( 1 ), 12500 )[1]['country']['value'] === 'DE' );
	$check( 'same-ms larger ID wins; foreign visitor ignored', $facts[2]['country']['state'] === 'unavailable' );
	$check( 'exclusive cutoff rejects country and entry capture at exact end', $facts[3]['country']['state'] === 'unobserved' && $facts[7]['utm_source']['state'] === 'unobserved' );
	$check( 'exact owner required', D::for_visit( $facts, 1, 2 ) === D::unknown() );
	$check( 'typed missing empty literal unobserved and case distinct', count( array_unique( array_map( static fn( $id ) => D::key( $facts[ $id ]['utm_source'] ), array( 1, 3, 4, 5, 6, 7 ) ) ) ) === 6 );
	$check( 'strict IDs reject malformed overflow boolean', D::positive_id( '01' ) === 1 && D::positive_id( '1x' ) === null && D::positive_id( true ) === null && D::positive_id( (string) PHP_INT_MAX . '0' ) === null );
	$check( 'omitted filter preserves null; explicit All canonical', F::params( array() ) === null && F::params( array( 'country_state' => 'all' ) ) === array( 'entry_domain_state' => 'all', 'country_state' => 'all' ) );
	$check( 'global catalog country and canonical host', F::params( array( 'entry_domain_state' => 'value', 'entry_domain' => 'ENTRY.TEST.', 'country_state' => 'value', 'country' => 'bv' ) ) === array( 'entry_domain_state' => 'value', 'entry_domain' => 'entry.test', 'country_state' => 'value', 'country' => 'BV' ) );
	foreach ( array( array( 'country_state' => null ), array( 'country_state' => 'value', 'country' => 'ZZ' ), array( 'country' => 'US' ), array( 'entry_domain_state' => 'value', 'entry_domain' => 'https://entry.test' ) ) as $bad ) { $check( 'invalid typed request fails', is_wp_error( F::params( $bad ) ) ); }
	Operations::$orders = array( new WC_Order( 1, 1, 1 ), new WC_Order( 2, 2, 1 ), new WC_Order( 3, 1, 999 ), new WC_Order( 4, 9999, 5 ), new WC_Order( 5, '1junk', 1 ) );
	$wpdb->visits[8] = $visit( 8, 7, 'Order only' ); $wpdb->visits[8]['start_ms'] = 0; $wpdb->visits[8]['attribution_ms'] = 1;
	$wpdb->visits[9] = $visit( 9, 8, 'Test visit' ); $wpdb->visits[9]['is_test'] = 1;
	Operations::$orders[] = new WC_Order( 6, 8, 7 ); $test_order = new WC_Order( 7, 1, 1 ); $test_order->test = true; Operations::$orders[] = $test_order;
	$range = array( 'from' => 10, 'to' => 20, 'compare_from' => 0, 'compare_to' => 10, 'dates' => array( 'from' => 'synthetic', 'to' => 'synthetic' ) );
	$source = Sources::table( $range, Sources::params( array() ) ); $by = array(); foreach ( $source['rows'] as $row ) { $by[ D::key( $row['utm_source'] ) ] = $row; }
	$a = $by[ D::key( array( 'state' => 'value', 'value' => 'A' ) ) ]; $unknown = $by[ D::key( array( 'state' => 'unobserved', 'value' => null ) ) ];
	$check( 'source-only recomputes UV and buyer sets across paths/media', $a['uv'] === 1 && $a['customers'] === 2 && $a['orders'] === 2 && $a['viewed_uv'] === 1 && $a['carted_uv'] === 1 && $a['paid_uv'] === 1 );
	$check( 'source refund math and conversion over 100', $a['sales'] === 30.0 && $a['conversion'] === 200.0 && $a['aov'] === 15.0 && $a['uv_value'] === 30.0 );
	$check( 'foreign missing malformed original links stay unobserved', $unknown['orders'] === 3 );
	$only = $by[ D::key( array( 'state' => 'value', 'value' => 'Order only' ) ) ]; $check( 'old-visit current-order zero UV preserves null denominators', $only['uv'] === 0 && $only['orders'] === 1 && $only['conversion'] === null && $only['uv_value'] === null && $only['aov'] === 15.0 );
	$check( 'new source excludes test visit and native test order', ! isset( $by[ D::key( array( 'state' => 'value', 'value' => 'Test visit' ) ) ] ) && $a['orders'] === 2 );
	$split = Sources::table( $range, Sources::params( array( 'split_medium' => 1 ) ) ); $as = array_values( array_filter( $split['rows'], static fn( $r ) => $r['utm_source']['value'] === 'A' ) );
	$check( 'split recomputes independent distinct sets', count( $as ) === 2 && $as[0]['uv'] === 1 && $as[1]['uv'] === 1 );
	$filter = array( 'entry_domain_state' => 'value', 'entry_domain' => 'entry.test', 'country_state' => 'unknown' );
	$product = Products::table( $range, Products::params( $filter ) ); $m = $product['rows'][0]['current'];
	$check( 'product intersection filters counts UV units native parent and variation', $m['views'] === 1 && $m['view_users'] === 1 && $m['carts'] === 1 && $m['cart_units'] === 2 && $m['paid_orders'] === 1 && $m['paid_units'] === 2 && $m['net_sales'] === 22.0 && $product['rows'][0]['variations'][0]['current']['paid_orders'] === 1 );
	$pages = Page_Visits::table( $range, Page_Visits::params( $filter ) );
	$check( 'page event PV UV and visit-start bounce all filtered', $pages['total'] === 1 && $pages['rows'][0]['views'] === 3 && $pages['rows'][0]['visitors'] === 1 && $pages['rows'][0]['bounced'] === 1 );
	$check( 'coverage separates candidate event and visit units', $pages['filter_coverage']['current']['events']['base_unit'] === 'events' && $pages['filter_coverage']['current']['events']['matched_count'] === 3 && $pages['filter_coverage']['current']['visits']['matched_count'] === 1 );
	foreach ( array( 0, 1 ) as $mode ) { $landing = Landing::table( $range, Landing::params( $filter + array( 'show_params' => $mode ) ) ); $check( 'Landing both grouping modes filter visits and orders', $landing['total'] === 1 && $landing['rows'][0]['uv'] === 1 && $landing['rows'][0]['orders'] === 1 && $landing['filter'] === $filter ); }
	$unobserved_landing = Landing::table( $range, Landing::params( array( 'entry_domain_state' => 'unobserved', 'show_params' => 1 ) ) ); $missing_path = array_values( array_filter( $unobserved_landing['rows'], static fn( $r ) => $r['path'] === '' ) ); $check( 'filtered Landing keeps foreign malformed links in unknown path', $missing_path[0]['orders'] === 3 && $missing_path[0]['attribution_status'] === 'unknown' );
	$late_path = array_values( array_filter( $unobserved_landing['rows'], static fn( $r ) => $r['path'] === '/P7' ) ); $check( 'filtered Landing show_params honors attribution cutoff', $late_path[0]['attribution_status'] === 'unknown' );
	$none = Products::table( $range, Products::params( array( 'entry_domain_state' => 'value', 'entry_domain' => 'other.test', 'country_state' => 'unknown' ) ) ); $check( 'AND zero intersection is empty', $none['total'] === 0 );
	$oracle = $argv[1] ?? null;
	if ( $oracle ) {
		foreach ( array( 'landing' => 'Landing', 'products' => 'Products', 'page-visits' => 'Page_Visits', 'report-exports' => 'Report_Exports' ) as $file => $class ) { $source_text = file_get_contents( $oracle . '/class-' . $file . '.php' ); $source_text = str_replace( 'final class ' . $class, 'final class Legacy_' . $class, $source_text ); if ( $class === 'Report_Exports' ) { $source_text = str_replace( array( 'Products::', 'Page_Visits::' ), array( 'Legacy_Products::', 'Legacy_Page_Visits::' ), $source_text ); } eval( '?>' . $source_text ); }
		foreach ( array( 'Landing', 'Products', 'Page_Visits' ) as $class ) { $new = 'PeptideCore\\Data\\' . $class; $old = 'PeptideCore\\Data\\Legacy_' . $class; foreach ( array( array(), array( 'sort' => $class === 'Products' ? 'sales' : 'uv', 'q' => 'P', 'page' => 2, 'per_page' => 1 ) ) as $params ) { if ( $class === 'Page_Visits' ) { unset( $params['sort'] ); } $args = $new::params( $params ); $check( 'legacy standalone JSON oracle ' . $class, $new::table( $range, $args ) === $old::table( $range, $args ) ); } }
		foreach ( array( 'products' => Products::class, 'pages' => Page_Visits::class ) as $report => $class ) { $request = new WP_REST_Request( array( 'format' => 'csv' ) ); $args = $class::params( array() ); $new = Report_Exports::response( $request, $report, $range, $args ); $old = \PeptideCore\Data\Legacy_Report_Exports::response( $request, $report, $range, $args ); $rows = new \ReflectionProperty( Csv_Response::class, 'rows' ); $check( 'legacy standalone CSV full rows oracle ' . $report, iterator_to_array( $rows->getValue( $new ) ) === iterator_to_array( $rows->getValue( $old ) ) ); }
		$mode_args = Landing::params( array( 'show_params' => 1 ) ); $check( 'legacy standalone show_params strict oracle', Landing::table( $range, $mode_args ) === \PeptideCore\Data\Legacy_Landing::table( $range, $mode_args ) );
	}
	for ( $i = 20; $i <= 540; ++$i ) { $wpdb->visits[ $i ] = $visit( $i, $i, 'Source ' . $i ); $wpdb->events[] = $event( 1000 + $i, $i, $i, 15000, array( 'status' => 'known', 'country_code' => 'US' ) ); }
	$many = D::visit_facts( array_keys( $wpdb->visits ), 20000 ); $check( '501+ visit batch reads final country without cap', count( $many ) === count( $wpdb->visits ) && $many[540]['country']['value'] === 'US' );
	for ( $i = 1; $i <= 505; ++$i ) { $wpdb->events[] = $event( 2000 + $i, 4, 3, 16000 + $i ); }
	$wpdb->events[] = $event( 1999, 4, 3, 15000, array( 'status' => 'known', 'country_code' => 'DE' ) ); $check( '501 sparse no-geo observations do not hide earlier known', D::visit_facts( array( 4 ), 20000 )[4]['country']['value'] === 'DE' );
	$wpdb->visits[20]['utm_source'] = ' =SUM(A1)';
	$response = Report_Exports::sources_response( new WP_REST_Request( array( 'format' => 'csv' ) ), $range, Sources::params( array( 'page' => 2, 'per_page' => 1 ) ) );
	$rows_property = new \ReflectionProperty( Csv_Response::class, 'rows' ); $csv_rows = iterator_to_array( $rows_property->getValue( $response ) );
	$check( 'source CSV ignores pagination and protects formulas', count( $csv_rows ) > 500 && in_array( "' =SUM(A1)", array_column( $csv_rows, 1 ), true ) && count( $csv_rows[0] ) === 18 );
	ob_start(); $head = Csv_Response::serve( false, $response, new WP_REST_Request( array(), 'HEAD' ), null ); $body = ob_get_clean(); $check( 'source HEAD empty with marker authority', $head === true && $body === '' );
	$response = Report_Exports::sources_response( new WP_REST_Request( array( 'format' => 'csv' ) ), $range, Sources::params( array() ) ); ob_start(); Csv_Response::serve( false, $response, new WP_REST_Request(), null ); $body = ob_get_clean(); $check( 'source GET emits one BOM with full CSV rows', substr_count( $body, "\xEF\xBB\xBF" ) === 1 && substr_count( $body, "\n" ) > 500 );
	$wpdb->last_error = 'synthetic failure'; $failed = Report_Exports::sources_response( new WP_REST_Request(), $range, Sources::params( array() ) ); $check( 'query failure is error, not zero success', is_wp_error( $failed ) ); $wpdb->last_error = '';
	echo 'TOTAL ', $passed, " PASS, 0 FAIL\n";
}
