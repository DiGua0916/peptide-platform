<?php
/** Actual receiver/report classes; reuse the accepted 036 native-boundary harness once. */
require __DIR__ . '/data-dimensions-pure.php';
require dirname( __DIR__ ) . '/includes/tracking/class-client-environment.php';
use PeptideCore\Tracking\Client_Environment as Env043;
use PeptideCore\Tracking\Page_Context as Context043;
use PeptideCore\Data\Report_Dimensions as Facts043;
use PeptideCore\Data\Dimension_Reports as Dimensions043;
use PeptideCore\Data\Conversion as Financial043;
use PeptideCore\Data\Dimension_Conversion as Rows043;
use PeptideCore\Data\Visitor_Funnel as Funnel043;
$new043 = 0;
$test043 = static function( string $name, bool $ok ) use ( &$new043 ): void { if ( ! $ok ) { throw new RuntimeException( $name ); } ++$new043; };
$os043 = static fn( string $family ) => array( 'status' => 'known', 'family' => $family );
$screen043 = static fn( int $width, int $height ) => array( 'status' => 'known', 'width' => $width, 'height' => $height );
foreach ( Env043::OS as $family ) { $e = array( 'version' => 1, 'os' => $os043( $family ) ); $test043( 'exact supported family survives clean/saved', Env043::clean( $e ) === array( 'ok' => true, 'value' => $e ) && Env043::saved( $e ) === $e ); }
foreach ( array( array( 1, 1 ), array( 16384, 16384 ), array( 1920, 1080 ) ) as $size ) { $e = array( 'version' => 1, 'screen' => $screen043( ...$size ) ); $test043( 'strict CSS bounds preserved', Env043::saved( $e ) === $e ); }
$wire043 = array( 'version' => 1, 'os' => $os043( 'Windows' ) + array( 'userAgent' => 'PRIVATE' ), 'screen' => $screen043( 1920, 1080 ) + array( 'pixel_ratio' => 3 ), 'contact' => 'PRIVATE' );
$clean043 = Env043::saved( $wire043 ); $test043( 'unknown identifying fields are pruned', $clean043 === array( 'version' => 1, 'os' => $os043( 'Windows' ), 'screen' => $screen043( 1920, 1080 ) ) && ! str_contains( json_encode( $clean043 ), 'PRIVATE' ) );
$invalid043 = array( null, false, array(), array( 'version' => 1 ), array( 'version' => '1', 'os' => $os043( 'Windows' ) ), array( 'version' => 2, 'os' => $os043( 'Windows' ) ), array( 'version' => 1, 'os' => null ), array( 'version' => 1, 'os' => $os043( 'windows' ) ), array( 'version' => 1, 'os' => $os043( 'Other' ) ), array( 'version' => 1, 'os' => array( 'status' => 'unknown', 'family' => null ) ), array( 'version' => 1, 'screen' => array( 'status' => 'unavailable', 'width' => 1 ) ) );
foreach ( array( 0, 16385, -1, 1.1, '1920', true, null ) as $width ) { $invalid043[] = array( 'version' => 1, 'screen' => array( 'status' => 'known', 'width' => $width, 'height' => 1080 ) ); }
foreach ( $invalid043 as $bad ) { $test043( 'malformed supported fields atomically reject', false === Env043::clean( $bad )['ok'] && null === Env043::saved( $bad ) ); $context = array( 'version' => 1, 'kind' => 'home', 'environment' => $bad ); $test043( 'invalid environment uses the recognized context rejection', Context043::clean( $context ) === array( 'ok' => false, 'code' => 'bad_page_context' ) ); }
foreach ( array( 'unknown', 'unavailable' ) as $status ) { $e = array( 'version' => 1, 'os' => array( 'status' => $status ), 'screen' => array( 'status' => $status ) ); $test043( 'explicit nonknown facts remain separate', Env043::saved( $e ) === $e ); }
$old043 = array( 'version' => 1, 'kind' => 'collection', 'term_id' => 42, 'geo' => array( 'status' => 'known', 'country_code' => 'US' ) );
$test043( 'old metadata stays byte-identical', json_encode( Context043::saved( $old043 ) ) === json_encode( $old043 ) && 1 === Context043::client_environment_protocol() );
$with043 = $old043 + array( 'environment' => $clean043 ); $test043( 'context retains only canonical environment', Context043::saved( $with043 ) === $with043 );
$original_db043 = $wpdb;
$wpdb = new class( $original_db043 ) {
	public string $last_error = '';
	public function __construct( private $database ) {}
	public function prepare( $sql, ...$args ) { return $this->database->prepare( $sql, ...$args ); }
	public function get_var( $sql ) { preg_match( '/id = (\d+) AND visitor_id = (\d+)/', $sql, $matches ); $row = $this->database->visits[(int) ( $matches[1] ?? 0 )] ?? null; return $row && $row['visitor_id'] === (int) $matches[2] ? $row['id'] : null; }
};
try {
	$incoming043 = array( 'event_id' => 'event-043-replay', 'page' => array( 'path' => '/', 'page_type' => 'home' ), 'data' => array( 'page_context' => array( 'version' => 1, 'kind' => 'home', 'environment' => $clean043 ) ), 'page_instance_id' => 'page-043', 'tab_id' => 'tab-043', 'seq' => 1 );
	$saved043 = array( 'event_uid' => $incoming043['event_id'], 'visitor_id' => 1, 'visit_id' => 1, 'type' => 'page_view', 'source' => 'client', 'path' => '/', 'page_type' => 'home', 'product_id' => 0, 'variation_id' => 0, 'page_instance_id' => 'page-043', 'tab_id' => 'tab-043', 'seq' => 1, 'data' => json_encode( $incoming043['data'] ) );
	$receipt043 = Context043::replay( 1, $incoming043, $saved043 ); $test043( 'exact environment replay uses existing terminal receipt', $receipt043['status'] === 'applied' && $receipt043['code'] === 'already_saved' && false === $receipt043['retry'] );
	$incoming043['data']['page_context']['environment']['screen']['width'] = 1921; $receipt043 = Context043::replay( 1, $incoming043, $saved043 ); $test043( 'same UID cannot replace a frozen environment snapshot', $receipt043['status'] === 'rejected' && $receipt043['code'] === 'duplicate_payload_mismatch' && false === $receipt043['retry'] );
} finally { $wpdb = $original_db043; }

// Reuse the actual owner/cutoff/keyset boundary; no source records are seeded outside memory.
$visit( 101, 101, 'client-env' ); $visit( 102, 102, 'client-env' ); $visit( 103, 101, 'other-visit' );
$context043 = static fn( array $extra ) => array( 'page_context' => array( 'version' => 1, 'kind' => 'home' ) + $extra );
$event( 2001, 101, 101, 'page_view', 110000, $context043( array( 'environment' => array( 'version' => 1, 'os' => $os043( 'Windows' ), 'screen' => $screen043( 1920, 1080 ) ) ) ) );
$event( 2002, 101, 101, 'page_view', 120000, $context043( array( 'geo' => array( 'status' => 'known', 'country_code' => 'US' ) ) ) );
$event( 2003, 101, 101, 'page_view', 130000, $context043( array( 'environment' => array( 'version' => 1, 'os' => array( 'status' => 'unknown' ) ) ) ) );
$event( 2004, 101, 101, 'page_view', 140000, $context043( array( 'environment' => array( 'version' => 1, 'screen' => array( 'status' => 'unavailable' ) ) ) ) );
$event( 2005, 101, 101, 'page_view', 150000, $context043( array() ) );
$event( 2006, 101, 999, 'page_view', 190000, $context043( array( 'environment' => $clean043 ) ) );
$event( 2007, 103, 101, 'page_view', 190000, $context043( array( 'environment' => array( 'version' => 1, 'os' => $os043( 'macOS' ) ) ) ) );
$event( 2008, 101, 101, 'page_view', 200000, $context043( array( 'environment' => $clean043 ) ) );
$event( 2009, 101, 101, 'page_view', 195000, $context043( array( 'environment' => $clean043 ) ) ); $wpdb->events[array_key_last( $wpdb->events )]['source'] = 'server';
$event( 2010, 102, 102, 'page_view', 110000, $context043( array( 'environment' => array( 'version' => 1, 'os' => $os043( 'Android' ), 'screen' => $screen043( 360, 800 ) ) ) ) );
$event( 2011, 102, 102, 'page_view', 120000, $context043( array( 'environment' => array( 'version' => 1, 'screen' => $screen043( 800, 360 ) ) ) ) );
$event( 2012, 102, 102, 'page_view', 120000, $context043( array( 'environment' => array( 'version' => 1, 'os' => array( 'status' => 'unavailable' ) ) ) ) );
$event( 2013, 102, 102, 'page_view', 190000, $context043( array( 'geo' => array( 'status' => 'unknown' ) ) ) );
$facts043 = Facts043::visit_facts( array( 101, 102, 103 ), 200000 );
$test043( 'latest independent OS/screen do not erase or short-circuit geo', $facts043[101]['country'] === array( 'state' => 'value', 'value' => 'US' ) && $facts043[101]['operating_system']['state'] === 'unknown' && $facts043[101]['screen_size']['state'] === 'unavailable' );
$test043( 'latest geo does not short-circuit older env or equal-time independent fields', $facts043[102]['country']['state'] === 'unknown' && $facts043[102]['operating_system']['state'] === 'unavailable' && $facts043[102]['screen_size']['value'] === '800x360' );
$test043( 'original visit owner is mandatory and no same-visitor fallback', Facts043::for_visit( $facts043, 101, 999 )['operating_system']['state'] === 'unobserved' && $facts043[103]['operating_system']['value'] === 'macOS' && $facts043[101]['operating_system']['state'] === 'unknown' );
$test043( 'labels are dimension-specific and screen display has CSS unit', Dimensions043::label( $facts043[101]['operating_system'], 'operating_system' ) === 'Operating system unknown' && Dimensions043::label( $facts043[101]['screen_size'], 'screen_size' ) === 'Screen size source unavailable' && Dimensions043::label( $facts043[102]['screen_size'], 'screen_size' ) === '800 × 360 CSS pixels' && Dimensions043::label( array( 'state' => 'value', 'value' => 'unknown' ), 'operating_system' ) === 'unknown' );

$visit( 104, 104, 'sparse' );
$event( 3000, 104, 104, 'page_view', 105000, $context043( array( 'environment' => array( 'version' => 1, 'os' => $os043( 'Linux' ) ) ) ) );
for ( $i043 = 0; $i043 < 501; ++$i043 ) { $event( 3100 + $i043, 104, 104, 'page_view', 190000, $context043( array( 'geo' => array( 'status' => 'unknown' ) ) ) ); }
$sparse043 = Facts043::visit_facts( array( 104 ), 200000 ); $test043( 'sparse501 reverse scan retains older OS after geo is seen', $sparse043[104]['operating_system']['value'] === 'Linux' && $sparse043[104]['country']['state'] === 'unknown' );
for ( $i043 = 1000; $i043 <= 1500; ++$i043 ) { $visit( $i043, $i043, 'chunk' ); }
$event( 4000, 1500, 1500, 'page_view', 110000, $context043( array( 'environment' => $clean043 ) ) ); $query_start043 = count( $wpdb->queries ); $chunk043 = Facts043::visit_facts( range( 1000, 1500 ), 200000 );
$test043( '501 owner visits are chunked rather than N+1 and final chunk retains facts', count( $chunk043 ) === 501 && $chunk043[1500]['operating_system']['value'] === 'Windows' && count( $wpdb->queries ) - $query_start043 <= 5 );
$GLOBALS['test_orders'] = array( new WC_Order( 1010, 101, 101 ), new WC_Order( 1011, 999, 101 ), new WC_Order( 1012, 0, 0 ) );
foreach ( array( 'os' => 'operating_system', 'screen' => 'screen_size' ) as $group043 => $dimension043 ) {
	$paid043 = Financial043::paid_groups( 100, 200, $group043 ); $own043 = Facts043::key( $facts043[101][$dimension043] ); $missing043 = Facts043::key( array( 'state' => 'unobserved', 'value' => null ) );
	$test043( 'new groups retain native paid money and exact order owner', $paid043[$own043]['paid'] === 1 && $paid043[$own043]['sales'] === 15.0 && $paid043[$missing043]['paid'] === 2 && array_sum( array_column( $paid043, 'sales' ) ) === 45.0 );
	$mapping043 = Dimensions043::mapping( array( 101 => $facts043[101], 102 => $facts043[102] ), $dimension043 );
	$funnel043 = ( new Funnel043() )->period( 100000, 200000, 'start_ms', '', null, null, $mapping043['map'] );
	$rows043 = Rows043::rows( $funnel043['groups'], $paid043, $mapping043['typed'], $dimension043 );
	$test043( 'bulk owner-bound funnels merge with native financial facts', array_sum( array_column( $rows043, 'paid' ) ) === 3 && $mapping043['coverage']['unit'] === 'visits' && $mapping043['coverage']['base_count'] === 2 && count( $rows043[0]['visitor_funnel']['layers'] ) === 8 );
	$summary043 = array( 'group' => $group043, 'range' => array( 'from' => 100, 'to' => 200, 'compare_from' => 50, 'compare_to' => 100 ), 'visitor_funnel' => Funnel043::compare( $funnel043['overall'], $funnel043['overall'] ), 'rows' => $rows043, 'checkout_steps' => array( 'local_simulation' => false, 'labels' => array(), 'channels' => array() ) );
	$mapper043 = new ReflectionMethod( \PeptideCore\Data\Report_Exports::class, 'conversion_rows' ); $csv043 = iterator_to_array( $mapper043->invoke( null, $summary043, 'USD' ) );
	$sales043 = array_values( array_filter( $csv043, static fn( $row ) => 'visitor_groups' === $row[0] && 'sales' === $row[3] ) );
	$test043( 'actual CSV mapper preserves selected group, typed keys and native money', array_unique( array_column( $sales043, 1 ) ) === array( $group043 ) && array_column( $sales043, 2 ) === array_column( $rows043, 'key' ) && array_column( $sales043, 5 ) === array_map( static fn( $row ) => number_format( $row['sales'], 2, '.', '' ), $rows043 ) && count( $csv043[0] ) === 17 );
}
$test043( 'seven conversion groups preserve old enum order', Financial043::GROUPS === array( 'device', 'browser', 'utm', 'domain', 'country', 'os', 'screen' ) && array_keys( Dimensions043::GROUPS ) === array( 'utm', 'domain', 'country', 'os', 'screen' ) );
echo 'PASS ', $new043, " client environment receiver/report assertions; 036 suite included once\n";
