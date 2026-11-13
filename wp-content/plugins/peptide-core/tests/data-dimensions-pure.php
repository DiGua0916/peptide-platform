<?php
/** Standalone actual report classes with explicit WordPress/database boundaries; no bootstrap/network. */
namespace PeptideCore\Tracking {
	final class Schema { public static function installed(): bool { return true; } public static function table( string $name ): string { return 'test_' . $name; } }
	final class Server_Events { public const ORDER_VISITOR_META = 'visitor'; public const ORDER_VISIT_META = 'visit'; }
}
namespace PeptideCore {
	final class Store_Config { public static function get( string $key ) { return 24; } }
	final class Overview { public static function is_test_order( $order ): bool { return $order->test; } }
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 );
	function __( $text, $domain = '' ) { return $text; }
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
	function wc_get_is_paid_statuses() { return array( 'processing', 'completed' ); }
	function wc_get_order_statuses() { return array( 'wc-processing' => 'Processing', 'wc-completed' => 'Completed', 'wc-pending' => 'Pending', 'wc-refunded' => 'Refunded' ); }
	function get_woocommerce_currency() { return 'USD'; }
	if ( ! function_exists( 'mb_stripos' ) ) { function mb_stripos( $text, $needle ) { return stripos( $text, $needle ); } }
	function WC() { return (object) array( 'countries' => new class { public function get_countries() { return array( 'US' => 'United States', 'CA' => 'Canada', 'AX' => '&#197;land' ); } } ); }
	function wc_get_orders( array $args ) {
		$out = array(); foreach ( $GLOBALS['test_orders'] as $order ) {
			if ( isset( $args['post__in'] ) && ! in_array( $order->get_id(), $args['post__in'], true ) ) { continue; }
			if ( isset( $args['date_created'] ) ) { $bounds = array_map( 'intval', explode( '...', $args['date_created'] ) ); if ( $order->created < $bounds[0] || $order->created > $bounds[1] ) { continue; } }
			if ( isset( $args['status'] ) && is_array( $args['status'] ) && ! in_array( $order->status, array_map( static fn( $s ) => preg_replace( '/^wc-/', '', $s ), $args['status'] ), true ) ) { continue; }
			$out[] = $order;
		} return $out;
	}
	final class WC_Order {
		public bool $test = false; public string $status = 'processing';
		public function __construct( public int $id, public int $visitor, public int $visit, public int $created = 110, public float $total = 20, public float $refund = 5 ) {}
		public function get_id() { return $this->id; } public function get_meta( $key ) { return $this->$key ?? ''; }
		public function get_total() { return $this->total; } public function get_total_refunded() { return $this->refund; }
		public function get_status() { return $this->status; } public function is_paid() { return $this->status === 'processing'; }
		public function get_date_paid() { return $this->is_paid() ? new DateTimeImmutable( '@111' ) : null; }
		public function get_date_created() { return new DateTimeImmutable( '@' . $this->created ); }
		public function get_type() { return 'shop_order'; } public function get_payment_method() { return 'local'; }
		public function get_payment_method_title() { return 'Local'; } public function get_billing_country() { return 'US'; }
		public function get_customer_id() { return 0; } public function get_billing_email() { return ''; }
		public function get_coupon_codes() { return array(); } public function get_items() { return array(); }
	}
	class WP_REST_Response {}
	final class Dimension_Test_DB {
		public string $last_error = ''; public array $visits = array(); public array $events = array(); public array $queries = array();
		public function prepare( string $sql, ...$args ): string { if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; } foreach ( $args as $arg ) { $sql = preg_replace( '/%[ds]/', (string) (int) $arg, $sql, 1 ); } return $sql; }
		private function ids( string $sql ): array { preg_match( '/(?:id|visit_id) IN \(([^)]*)\)/', $sql, $m ); return isset( $m[1] ) ? array_map( 'intval', explode( ',', $m[1] ) ) : array(); }
		private function cohort( string $sql ): array {
			preg_match( '/(?:s\.)?start_ms >= (\d+) AND (?:s\.)?start_ms < (\d+)/', $sql, $m ); $from = (int) ( $m[1] ?? 0 ); $to = (int) ( $m[2] ?? PHP_INT_MAX );
			return array_filter( $this->visits, static fn( $v ) => $v['start_ms'] >= $from && $v['start_ms'] < $to );
		}
		public function get_results( string $sql, $mode ): array {
			$this->queries[] = $sql;
			if ( str_contains( $sql, 'FROM test_visits WHERE id IN' ) ) { $ids = $this->ids( $sql ); return array_values( array_filter( $this->visits, static fn( $v ) => in_array( $v['id'], $ids, true ) ) ); }
			if ( str_contains( $sql, "type = 'page_view'" ) ) {
				$ids = $this->ids( $sql ); preg_match( '/occurred_ms < (\d+) AND \(occurred_ms < (\d+) OR \(occurred_ms = \d+ AND id < (\d+)\)\)/', $sql, $m );
				$cutoff = (int) $m[1]; $at = (int) $m[2]; $id = (int) $m[3];
				$rows = array_values( array_filter( $this->events, static fn( $e ) => $e['type'] === 'page_view' && in_array( $e['visit_id'], $ids, true ) && $e['occurred_ms'] < $cutoff && ( $e['occurred_ms'] < $at || ( $e['occurred_ms'] === $at && $e['id'] < $id ) ) ) );
				usort( $rows, static fn( $a, $b ) => ( $b['occurred_ms'] <=> $a['occurred_ms'] ) ?: ( $b['id'] <=> $a['id'] ) ); return array_slice( $rows, 0, 500 );
			}
			if ( str_contains( $sql, 'SELECT id,visitor_id FROM test_visits WHERE' ) || str_contains( $sql, 'SELECT s.id,s.visitor_id,s.stage' ) ) {
				preg_match( '/(?:s\.)?id > (\d+)/', $sql, $m ); return array_slice( array_values( array_filter( $this->cohort( $sql ), static fn( $v ) => $v['id'] > (int) $m[1] ) ), 0, 500 );
			}
			if ( str_contains( $sql, 'SELECT e.id,e.type,e.source' ) ) {
				preg_match( '/e.id > (\d+)/', $sql, $m ); $cohort = $this->cohort( $sql ); $rows = array();
				foreach ( $this->events as $event ) { $visit = $cohort[$event['visit_id']] ?? null; if ( ! $visit || $visit['visitor_id'] !== $event['visitor_id'] || $event['id'] <= (int) $m[1] || ! in_array( $event['type'], array( 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected', 'payment_success' ), true ) ) { continue; } $rows[] = $event + array( 'linked_order' => 0, 'g' => 'desktop', 'returning_visit' => false ); } return array_slice( $rows, 0, 500 );
			}
			return array(); // Unchanged legacy product/browser ranking queries have no fixture rows.
		}
		public function get_row( string $sql, $mode ): array { $out = array(); foreach ( array( 'visitors' => 0, 'viewed' => 1, 'carted' => 2, 'checkout' => 3 ) as $key => $stage ) { $set = array(); foreach ( $this->cohort( $sql ) as $visit ) { if ( $visit['stage'] >= $stage ) { $set[$visit['visitor_id']] = true; } } $out[$key] = count( $set ); $out['r_' . $key] = 0; } return $out; }
	}
	$root = dirname( __DIR__ );
	foreach ( array( 'tracking/class-attribution.php', 'tracking/class-page-context.php', 'data/class-report-dimensions.php', 'data/class-range.php', 'data/class-params.php', 'data/class-operations.php', 'data/class-visitor-funnel.php', 'data/class-dimension-reports.php', 'data/class-conversion.php', 'data/class-dimension-conversion.php', 'data/class-rankings.php', 'data/class-csv-response.php', 'data/class-report-exports.php' ) as $file ) { require $root . '/includes/' . $file; }
	use PeptideCore\Data\Report_Dimensions as Facts;
	use PeptideCore\Data\Dimension_Reports as Dimensions;
	use PeptideCore\Data\Dimension_Conversion as Conversion;
	use PeptideCore\Data\Visitor_Funnel;
	use PeptideCore\Data\Rankings;
	$passed = 0; $check = static function ( string $label, bool $condition ) use ( &$passed ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } ++$passed; };
	$wpdb = new Dimension_Test_DB(); $GLOBALS['test_orders'] = array();
	$visit = static function ( int $id, int $visitor, $source, int $start = 101000, int $version = 1, int $captured = 102000 ) use ( $wpdb ): void { $wpdb->visits[$id] = array( 'id' => $id, 'visitor_id' => $visitor, 'start_ms' => $start, 'stage' => 3, 'attribution_version' => $version, 'attribution_ms' => $captured, 'attribution_host' => 'entry.test', 'utm_source' => $source, 'utm_medium' => null ); };
	$event = static function ( int $id, int $visit, int $visitor, string $type, int $at, array $data = array(), int $order = 0 ) use ( $wpdb ): void { $wpdb->events[] = array( 'id' => $id, 'visit_id' => $visit, 'visitor_id' => $visitor, 'type' => $type, 'source' => $type === 'payment_success' ? 'server' : 'client', 'occurred_ms' => $at, 'order_id' => $order, 'data' => json_encode( $data ) ); };
	$visit( 1, 1, 'unknown' ); $visit( 2, 1, 'Other' ); $visit( 3, 2, null ); $visit( 4, 3, '' ); $visit( 5, 4, 'late', 101000, 1, 200000 ); $visit( 6, 5, 'UNKNOWN' );
	$event( 1, 1, 1, 'page_view', 110000, array( 'page_context' => array( 'version' => 1, 'kind' => 'home', 'geo' => array( 'status' => 'known', 'country_code' => 'US' ) ) ) );
	$event( 2, 1, 1, 'page_view', 120000, array( 'page_context' => array( 'version' => 1, 'kind' => 'home', 'geo' => array( 'status' => 'known', 'country_code' => 'CA' ) ) ) );
	$event( 3, 1, 1, 'page_view', 120000, array( 'page_context' => array( 'version' => 1, 'kind' => 'home', 'geo' => array( 'status' => 'unknown' ) ) ) );
	$event( 4, 1, 1, 'page_view', 130000, array( 'page_context' => array( 'version' => 1, 'kind' => 'home' ) ) );
	$event( 5, 2, 999, 'page_view', 140000, array( 'page_context' => array( 'version' => 1, 'kind' => 'home', 'geo' => array( 'status' => 'known', 'country_code' => 'US' ) ) ) );
	$event( 6, 2, 1, 'page_view', 200000, array( 'page_context' => array( 'version' => 1, 'kind' => 'home', 'geo' => array( 'status' => 'known', 'country_code' => 'US' ) ) ) );
	$facts = Facts::visit_facts( array_keys( $wpdb->visits ), 200000 );
	$check( 'country equal-time ID tie, no-geo does not erase; exact cutoff excluded', $facts[1]['country']['state'] === 'unknown' && $facts[2]['country']['state'] === 'unobserved' );
	$check( 'late capture stays unobserved and exact foreign-owner association rejected', $facts[5]['utm_source']['state'] === 'unobserved' && Facts::for_visit( $facts, 1, 999 )['visitor_id'] === 0 );
	$mapping = Dimensions::mapping( Dimensions::cohort( 100, 200 ), 'utm_source' );
	$check( 'typed missing empty unobserved and literal source names have distinct keys', count( $mapping['typed'] ) === 6 && count( array_unique( array_keys( $mapping['typed'] ) ) ) === 6 );
	$check( 'source case and literal sentinel labels preserved', Dimensions::label( $facts[1]['utm_source'], 'utm_source' ) === 'unknown' && Dimensions::label( $facts[2]['utm_source'], 'utm_source' ) === 'Other' && Dimensions::label( $facts[6]['utm_source'], 'utm_source' ) === 'UNKNOWN' );
	$event( 7, 1, 1, 'checkout_address_completed', 140000 ); $event( 8, 2, 1, 'checkout_shipping_selected', 140000 ); $event( 9, 1, 1, 'checkout_payment_selected', 150000 ); $event( 10, 1, 1, 'payment_success', 160000, array(), 10 ); $event( 11, 2, 1, 'payment_success', 160000, array(), 10 );
	// 2026-11-08 起（1b5b594）选物流 / 选支付只在同一次访问里填过地址才算，选支付还要先选过物流：两次访问各补一条前置事件，断言的意思不变（每一步都归到事件自己那次访问的分组）。
	$event( 12, 1, 1, 'checkout_shipping_selected', 145000 ); $event( 13, 2, 1, 'checkout_address_completed', 139000 );
	$GLOBALS['test_orders'] = array( new WC_Order( 10, 1, 1 ), new WC_Order( 11, 999, 1 ), new WC_Order( 12, 0, 0 ) );
	$reader = new Visitor_Funnel(); $funnel = $reader->period( 100000, 200000, 'start_ms', '', null, null, $mapping['map'] );
	$key = Facts::key( $facts[1]['utm_source'] ); $other = Facts::key( $facts[2]['utm_source'] );
	$counts = array_column( $funnel['groups'][$key]['layers'], 'count', 'key' ); $other_counts = array_column( $funnel['groups'][$other]['layers'], 'count', 'key' );
	$check( 'all eight steps use the owner-bound per-visit map', $counts['visitors'] === 1 && $counts['address'] === 1 && $counts['payment'] === 1 && $counts['paid'] === 1 && $other_counts['shipping'] === 1 && $other_counts['paid'] === 0 );
	$check( 'same visitor in multiple groups is not summed for overall UV', $funnel['overall']['layers'][0]['count'] === 5 && array_sum( array_map( static fn( $g ) => $g['layers'][0]['count'], $funnel['groups'] ) ) === 6 );
	$paid = \PeptideCore\Data\Conversion::paid_groups( 100, 200, 'utm' );
	$check( 'sales uses existing total minus refunds; foreign and missing association stay unobserved', $paid[$key]['sales'] === 15.0 && $paid[Facts::key( array( 'state' => 'unobserved', 'value' => null ) )]['paid'] === 2 );
	$rows = Conversion::rows( $funnel['groups'], $paid, $mapping['typed'], 'utm_source' ); $by_key = array_column( $rows, null, 'key' );
	$check( 'financial order counts never replace observed payment visitor counts', $by_key[$key]['paid'] === 1 && $by_key[$key]['visitor_funnel']['layers'][7]['count'] === 1 && $by_key[$key]['label'] === 'unknown' );
	$cache = array(); $ranks = Dimensions::rankings( 100, 200, array( array( 'visitor' => 1, 'visit' => 1 ), array( 'visitor' => 999, 'visit' => 1 ) ), $cache );
	$check( 'five rankings use distinct visitors and exact order owners', count( $ranks ) === 5 && $ranks['visitor_source'][$key] === 1 && $ranks['order_source'][$key] === 1 && array_sum( $ranks['visitor_source'] ) === 6 );
	$check( 'country coverage records all statuses and uses visits as base', $cache['dimension_coverage']['visitor_country'][0]['base_count'] === 6 && $cache['dimension_coverage']['visitor_country'][0]['unknown'] === 1 && $cache['dimension_coverage']['visitor_country'][0]['unobserved'] === 5 );
	// Complete ranking selection, row 51 search, previous-only and summary use the actual existing pipeline.
	for ( $i = 7; $i <= 66; ++$i ) { $visit( $i, $i, 'Source-' . $i ); }
	$visit( 66, 66, '=formula' );
	$visit( 67, 67, 'Previous-only', 51000, 1, 52000 );
	$range = array( 'from' => 100, 'to' => 200, 'compare_from' => 50, 'compare_to' => 100 ); $args = array( 'table' => 'visitor_source', 'q' => '', 'page' => 1, 'per_page' => 100, 'format' => 'json' );
	$summary = Rankings::summary( $range ); $listing = Rankings::listing( $range, $args ); $complete = iterator_to_array( Rankings::all_rows( $range, $args ) );
	$check( 'complete new rankings retain all >50 groups and summary projects the same rows', $listing['total'] === 67 && count( $complete ) === 67 && $summary['tables']['visitor_source']['rows'] === array_slice( $complete, 0, 50 ) && $listing['rows'] === $complete );
	$previous = array_values( array_filter( $complete, static fn( $r ) => $r['label'] === 'Previous-only' ) )[0];
	$check( 'previous-only remains visible with current zero and negative change', $previous['current'] === 0 && $previous['previous'] === 1 && $previous['change'] === -100.0 );
	$args['q'] = $complete[50]['label']; $found = Rankings::listing( $range, $args );
	$check( 'row51 is searchable beyond summary cutoff', in_array( $complete[50]['key'], array_column( $found['rows'], 'key' ), true ) );
	$args['q'] = ''; $args['page'] = 2; $args['per_page'] = 20; $page = Rankings::listing( $range, $args );
	$check( 'page2 is exact complete-selection slice and typed units stay count/percent', $page['rows'] === array_slice( $complete, 20, 20 ) && $page['value_unit'] === 'count' && $page['change_unit'] === 'percent' );
	$range['dates'] = array( 'from' => '1970-01-01', 'to' => '1970-01-01' );
	$csv_method = new ReflectionMethod( \PeptideCore\Data\Report_Exports::class, 'rankings' ); $csv = iterator_to_array( $csv_method->invoke( null, $range, $args ) );
	$check( 'actual CSV mapper exports complete rows regardless of selected page', count( $csv ) === count( $complete ) + 1 && array_column( array_slice( $csv, 1 ), 1 ) === array_column( $complete, 'key' ) && count( $csv[0] ) === 13 );
	$check( 'actual CSV mapper neutralizes literal formula source labels', in_array( "'=formula", array_column( $csv, 2 ), true ) );
	// 2026-11 起分组多了 channel（流量渠道），断言跟上现有常量；原来的七个分组顺序不变。
	$check( 'seven advertised groups (plus channel) and twenty-one ranking kinds', \PeptideCore\Data\Conversion::GROUPS === array( 'device', 'browser', 'utm', 'domain', 'country', 'os', 'screen', 'channel' ) && count( Rankings::TABLES ) === 21 );
	$before_queries = count( $wpdb->queries ); $cache = array(); Dimensions::rankings( 100, 200, array(), $cache );
	$check( 'sixty-six visitor groups use one cohort/facts/geo scan rather than one query per group', count( $wpdb->queries ) - $before_queries === 3 );
	echo 'PASS ', $passed, " standalone dimension report assertions\n";
}
