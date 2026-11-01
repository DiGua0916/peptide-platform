<?php
/** Pure actual classifier/report/CSV gates; reuse only existing in-memory native boundaries. */
$boundary = file_get_contents( __DIR__ . '/data-sources-standalone.php' );
$end = strpos( $boundary, "\t\$passed = 0;" );
if ( false === $end ) { throw new RuntimeException( 'Missing source fixture boundary.' ); }
eval( '?>' . substr( $boundary, 0, $end ) . "\n}" );
require dirname( __DIR__ ) . '/includes/data/class-dimension-reports.php';
use PeptideCore\Data\Report_Dimensions as Channels071;
use PeptideCore\Data\Dimension_Reports as Dimensions071;
use PeptideCore\Data\Sources as Sources071;
use PeptideCore\Data\Report_Exports as Exports071;
use PeptideCore\Data\Csv_Response as Csv071;
use PeptideCore\Data\Operations as Operations071;
$n = 0;
$check = static function ( string $name, bool $value ) use ( &$n ): void { if ( ! $value ) { throw new RuntimeException( 'FAIL ' . $name ); } ++$n; };
$snapshot = static fn( $source = null, $medium = null, $referrer = null, $host = 'shop.test' ): array => array( 'attribution_version' => 1, 'attribution_host' => $host, 'utm_source' => $source, 'utm_medium' => $medium, 'referrer_host' => $referrer );
$rule = static function ( $source, $medium, $referrer, string $expected ) use ( $snapshot, $check ): void { $input = $snapshot( $source, $medium, $referrer ); $original = $input; $check( 'rule ' . $expected . ':' . (string) $source . ':' . (string) $medium, Channels071::channel( $input ) === array( 'state' => 'value', 'value' => $expected ) && $input === $original ); };
foreach ( array(
 array( 'GOOGLE', ' CPC ', 'facebook.com', 'paid_search' ), array( 'FACEBOOK', 'cpc', 'google.com', 'paid_social' ), array( 'meta', 'ppc', null, 'paid_social' ), array( 'vendor', 'paidsearch', null, 'paid_other' ),
 array( 'ads.bing.com', 'paid_search', null, 'paid_search' ), array( 'google.com.evil.test', 'cpc', null, 'paid_other' ),
 array( 'Any', 'email', 'google.com', 'email' ), array( null, 'paid_social', null, 'paid_social' ), array( null, 'social_paid', null, 'paid_social' ), array( null, 'paidsocial', null, 'paid_social' ),
 array( null, 'organic', null, 'organic_search' ), array( null, 'social', null, 'organic_social' ), array( null, 'social-network', null, 'organic_social' ), array( null, 'social-media', null, 'organic_social' ), array( null, 'sm', null, 'organic_social' ),
 array( null, 'referral', null, 'referral' ), array( null, 'app', null, 'referral' ), array( null, 'link', null, 'referral' ),
 array( null, 'display', null, 'display' ), array( null, 'cpm', null, 'display' ), array( null, 'banner', null, 'display' ),
 array( null, 'affiliate', null, 'affiliate' ), array( null, 'affiliates', null, 'affiliate' ), array( null, 'paid', null, 'paid_other' ), array( null, 'retargeting', null, 'paid_other' ),
 array( 'Google', null, 'google.com', 'tagged_other' ), array( null, 'custom', 'google.com', 'tagged_other' ), array( '(DIRECT)', '(NONE)', null, 'direct_or_hidden' ),
 array( '', ' ', 'www.google.co.uk', 'organic_search' ), array( null, null, 'm.facebook.com', 'organic_social' ), array( null, null, 'news.other.test', 'referral' ),
 array( null, null, 'google.com.evil.test', 'referral' ), array( null, null, 'evilgoogle.com', 'referral' ), array( null, null, 'evilfacebook.com', 'referral' ),
 array( null, null, 'shop.test', 'internal_referral' ), array( null, null, 'sub.shop.test', 'internal_referral' ), array( null, null, '', 'direct_or_hidden' ),
) as $case ) { $rule( ...$case ); }
foreach ( array( 'google.com', 'google.co.uk', 'google.com.hk', 'google.cn', 'bing.com', 'search.yahoo.com', 'duckduckgo.com', 'baidu.com', 'yandex.ru' ) as $host ) { $rule( null, null, $host, 'organic_search' ); }
foreach ( array( 'facebook.com', 'fb.com', 'instagram.com', 'tiktok.com', 'x.com', 't.co', 'pinterest.com', 'reddit.com', 'linkedin.com', 'youtube.com' ) as $host ) { $rule( null, null, $host, 'organic_social' ); }
$check( 'null referrer stays unknown', Channels071::channel( $snapshot() ) === array( 'state' => 'unknown', 'value' => null ) );
$check( 'invalid referrer is not inferred direct', Channels071::channel( $snapshot( null, null, 'https://google.com/private' ) )['state'] === 'unknown' );
$check( 'uncaptured is unobserved', Channels071::channel( array() ) === array( 'state' => 'unobserved', 'value' => null ) );
$check( 'ambiguous and unknown labels are explicit', Channels071::channel_label( array( 'state' => 'value', 'value' => 'direct_or_hidden' ) ) === 'Direct / source hidden' && Channels071::channel_label( array( 'state' => 'unknown', 'value' => null ) ) === 'Traffic source unknown' );
$make = static function ( int $id, int $owner, $source = null, $medium = null, $referrer = null ) use ( $snapshot ): array { return $snapshot( $source, $medium, $referrer ) + array( 'id' => $id, 'visitor_id' => $owner, 'user_id' => 0, 'stage' => 2, 'start_ms' => 10000, 'is_test' => 0, 'attribution_ms' => 11000 ); };
$wpdb = new Memory_DB();
$wpdb->visits = array( 1 => $make( 1, 7, 'Google', 'CPC' ), 2 => $make( 2, 7, 'google', 'cpc' ), 3 => $make( 3, 7, 'Mail', 'email' ), 4 => $make( 4, 8, '', '', '' ), 5 => $make( 5, 9 ), 6 => $make( 6, 10, 'Facebook', 'cpc' ), 7 => $make( 7, 11, null, null, 'google.com.evil.test' ), 8 => $make( 8, 12, null, null, 'google.com' ) );
$wpdb->visits[6]['attribution_ms'] = 20000; $wpdb->visits[8]['start_ms'] = 1000;
$good = new WC_Order( 1, 1, 7, 40, 10 ); $wrong = new WC_Order( 2, 1, 8 ); $missing = new WC_Order( 3, 99, 7 ); $late = new WC_Order( 4, 6, 10 ); $old = new WC_Order( 5, 8, 12 ); $test = new WC_Order( 6, 1, 7 ); $test->test = true;
Operations071::$orders = array( $good, $wrong, $missing, $late, $old, $test );
$range = array( 'from' => 10, 'to' => 20, 'dates' => array( 'from' => '1970-01-01', 'to' => '1970-01-02' ) );
$facts = Channels071::visit_facts( array_keys( $wpdb->visits ), 20000 );
$check( 'exclusive cutoff remains unobserved and exact owner rejects channel', $facts[6]['channel']['state'] === 'unobserved' && Channels071::for_visit( $facts, 1, 8 )['channel']['state'] === 'unobserved' );
$check( 'raw UTM case preserved', $facts[1]['utm_source']['value'] === 'Google' && $facts[1]['utm_medium']['value'] === 'CPC' );
$mapping = Dimensions071::mapping( $facts, 'channel' );
$check( 'common conversion dimension uses channel and typed identity', Dimensions071::GROUPS['channel'] === 'channel' && $mapping['map'][1]['key'] === $mapping['map'][2]['key'] && $mapping['map'][1]['key'] !== $mapping['map'][3]['key'] );
$args = Sources071::params( array( 'group_by' => 'channel' ) ); $report = Sources071::table( $range, $args ); $by = array(); foreach ( $report['rows'] as $row ) { $by[$row['channel']['value'] ?? $row['channel']['state']] = $row; }
$check( 'new mode is explicit policy and legacy identities absent', $report['group_by'] === 'channel' && $report['channel_policy'] === 'traffic-v1' && ! isset( $by['paid_search']['utm_source'] ) );
$check( 'same visitor rededuplicated within channel and may occupy another', $by['paid_search']['uv'] === 1 && $by['email']['uv'] === 1 && $by['paid_search']['orders'] === 1 && $by['paid_search']['sales'] === 30.0 );
$check( 'original owner, late and missing orders remain unobserved', $by['unobserved']['orders'] === 3 && $by['organic_search']['uv'] === 0 && $by['organic_search']['orders'] === 1 && $by['organic_search']['conversion'] === null );
$check( 'cohort coverage counts visits separately from UV', $report['coverage']['visits'] === array( 'unit' => 'visits', 'base_count' => 7, 'value' => 5, 'unknown' => 1, 'unobserved' => 1 ) && $report['coverage']['orders']['base_count'] === 5 && $report['coverage']['orders']['unobserved'] === 3 );
foreach ( array( null, true, array(), '', 'CHANNEL', 'other' ) as $invalid ) { $check( 'strict group parameter', is_wp_error( Sources071::params( array( 'group_by' => $invalid ) ) ) ); }
$check( 'channel medium split is rejected', is_wp_error( Sources071::params( array( 'group_by' => 'channel', 'split_medium' => 1 ) ) ) );
foreach ( array( 'Sources', 'Report_Exports' ) as $class ) { $file = $class === 'Sources' ? 'class-sources.php' : 'class-report-exports.php'; $source = file_get_contents( __DIR__ . '/fixtures/071/' . $file ); $source = str_replace( 'final class ' . $class, 'final class Legacy_' . $class . '071', $source ); if ( $class === 'Report_Exports' ) { $source = str_replace( 'Sources::', 'Legacy_Sources071::', $source ); } eval( '?>' . $source ); }
$rows_property = new ReflectionProperty( Csv071::class, 'rows' );
foreach ( array( array(), array( 'split_medium' => 1 ), array( 'q' => 'Google', 'page' => 2, 'per_page' => 1 ) ) as $params ) {
 $current = Sources071::params( $params ); $before = PeptideCore\Data\Legacy_Sources071::table( $range, $current ); $after = Sources071::table( $range, $current );
 $check( 'legacy UTM default JSON exact', $before === $after && ! isset( $after['group_by'], $after['channel_policy'], $after['coverage'] ) );
 $request = new WP_REST_Request( array( 'format' => 'csv' ) ); $old_csv = PeptideCore\Data\Legacy_Report_Exports071::sources_response( $request, $range, $current ); $new_csv = Exports071::sources_response( $request, $range, $current );
 $check( 'legacy UTM CSV exact rows and schema', iterator_to_array( $rows_property->getValue( $old_csv ) ) === iterator_to_array( $rows_property->getValue( $new_csv ) ) );
}
$page_args = Sources071::params( array( 'group_by' => 'channel', 'page' => 2, 'per_page' => 1 ) ); $page = Sources071::table( $range, $page_args ); $response = Exports071::sources_response( new WP_REST_Request( array( 'format' => 'csv' ) ), $range, $page_args ); $csv = iterator_to_array( $rows_property->getValue( $response ) );
$check( 'full channel CSV ignores page and has aligned schema', count( $page['rows'] ) === 1 && count( $csv ) === $report['total'] + 1 && count( $csv[0] ) === 25 && count( array_unique( array_map( 'count', $csv ) ) ) === 1 );
$csv_row = array_combine( $csv[0], $csv[1] ); $check( 'CSV policy, labels and coverage agree with report', $csv_row['channel_policy'] === 'traffic-v1' && $csv_row['channel_label'] !== '' && $csv_row['coverage_visits_base_count'] === '7' && $csv_row['coverage_orders_unobserved'] === '3' );
$searched = Sources071::table( $range, Sources071::params( array( 'group_by' => 'channel', 'q' => 'Paid search' ) ) ); $check( 'search and coverage use same complete matched groups', $searched['total'] === 1 && $searched['coverage']['visits']['base_count'] === 2 && $searched['coverage']['orders']['base_count'] === 1 );
for ( $id = 100; $id <= 600; ++$id ) { $wpdb->visits[$id] = $make( $id, $id + 2000, 'Google', 'cpc' ); }
$many = Sources071::table( $range, $args ); $row = array_values( array_filter( $many['rows'], static fn( $row ) => 'paid_search' === $row['channel']['value'] ) )[0]; $check( '501+ visit batches preserve final channel and exact distinct UV', $row['uv'] === 502 && $many['coverage']['visits']['base_count'] === 508 );
$wpdb->last_error = 'synthetic'; $check( 'query errors never become zero-channel success', is_wp_error( Exports071::sources_response( new WP_REST_Request(), $range, $args ) ) );
echo 'PASS ', $n, " traffic channel assertions, 0 FAIL\n";
