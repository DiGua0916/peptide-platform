<?php
/** Actual new helper/replay/reducer/ranking/CSV with pure boundary doubles only. */
// 排行后来多了“收藏商品”一张表（Data\Product_Actions），单独运行这个用例时也要能加载到它。
require_once __DIR__ . '/product-actions-test-autoload.php';
spl_autoload_register( static function ( string $class ): void {
	$files = array( 'PeptideCore\\Data\\Search_Observations' => 'data/class-search-observations.php', 'PeptideCore\\Tracking\\Search_Context' => 'tracking/class-search-context.php', 'PeptideCore\\Tracking\\Client_Environment' => 'tracking/class-client-environment.php' );
	if ( isset( $files[$class] ) ) { require dirname( __DIR__ ) . '/includes/' . $files[$class]; }
} );
require __DIR__ . '/data-dimensions-pure.php';
use PeptideCore\Tracking\Search_Context as Search;
use PeptideCore\Tracking\Page_Context as Context;
use PeptideCore\Data\Search_Observations as Searches;
use PeptideCore\Data\Rankings as Ranks;
$n046 = 0;
$assert046 = static function ( string $label, bool $ok ) use ( &$n046 ): void { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$n046; };
$oracle046 = json_decode( file_get_contents( dirname(__DIR__,4) . '/tests/fixtures/data-observations/046/native-search-legacy19.json' ), true );
$first046 = array_slice( Ranks::TABLES, 0, 19 );
// 冻结的期望值是加“商品缩略图 / 国旗”之前导出的：每一行后来多了一个只给页面用的 image 字段（c466262）。
// 数字、名称、顺序仍然逐字比对；image 单独检查——每一行都有、而且是字符串。
$tables046 = array_intersect_key( $summary['tables'], array_flip( $first046 ) ); $images046 = 0; $rows046 = 0;
foreach ( $tables046 as &$table046 ) { foreach ( $table046['rows'] as &$row046 ) { ++$rows046; if ( array_key_exists( 'image', $row046 ) && is_string( $row046['image'] ) ) { ++$images046; } unset( $row046['image'] ); } unset( $row046 ); } unset( $table046 );
$assert046( 'original nineteen numerical JSON/labels/order unchanged', json_encode( $tables046 ) === json_encode( $oracle046['summary'] ) );
$assert046( 'every ranking row carries a string image field', $rows046 > 0 && $images046 === $rows046 );
foreach ( $first046 as $table046 ) { $args['table'] = $table046; $args['q'] = ''; $actual046 = iterator_to_array( $csv_method->invoke( null, $range, $args ) ); $assert046( 'original complete CSV unchanged: ' . $table046, json_encode( $actual046 ) === json_encode( $oracle046['csv'][$table046] ) ); }
foreach ( array( ' Demo  PEPTIDE ' => 'demo peptide', '  研究\u{3000}肽  ' => '研究 肽', 'ÉCOLE αβ' => 'école αβ', "o'hara + test-2.0" => "o'hara + test-2.0" ) as $raw046 => $expected046 ) {
	$raw046 = str_replace( '\u{3000}', "\u{3000}", $raw046 );
	$assert046( 'source multilingual normalization', Search::source( $raw046 ) === array( 'version' => 1, 'status' => 'known', 'term' => $expected046 ) );
}
$assert046( 'eighty Unicode characters retained without byte truncation', Search::source( str_repeat( '肽', 80 ) )['term'] === str_repeat( '肽', 80 ) );
$assert046( 'empty spaces get explicit empty fact', Search::source( " \u{3000} " ) === array( 'version' => 1, 'status' => 'empty' ) );
foreach ( array( str_repeat( '肽', 81 ), "private@example.test", 'https://private.test/', "safe\nprivate", '123-4567', '１ ２ ３ ４ ５ ６ ７', 'safe?query', 'private\\path', 'safe&other', 'hello%20x', 'safe#fragment', 'www.example.test', 'name.example.cn', '<private>', 'a=b', "bad\xff", "safe\u{0085}" ) as $raw046 ) {
	$assert046( 'filtered raw content never retained', Search::source( $raw046 ) === array( 'version' => 1, 'status' => 'filtered' ) );
}
$known046 = array( 'version' => 1, 'status' => 'known', 'term' => 'demo peptide' );
$assert046( 'unknown fields pruned from canonical saved facts', Search::saved( $known046 + array( 'raw' => 'PRIVATE' ) ) === $known046 );
foreach ( array( array( 'version' => '1', 'status' => 'empty' ), array( 'version' => 2, 'status' => 'empty' ), array( 'version' => 1, 'status' => 'empty', 'term' => null ), array( 'version' => 1, 'status' => 'known', 'term' => 'UPPER' ), array( 'version' => 1, 'status' => 'known', 'term' => ' private@example.test' ) ) as $bad046 ) {
	$assert046( 'malformed noncanonical saved observation rejected', Search::saved( $bad046 ) === null );
}
$context046 = array( 'version' => 1, 'kind' => 'search', 'search' => $known046 );
$assert046( 'search optional extension accepted atomically', Context::clean( $context046 ) === array( 'ok' => true, 'value' => $context046 ) );
$assert046( 'old search context remains byte-identical', Context::clean( array( 'version' => 1, 'kind' => 'search' ) )['value'] === array( 'version' => 1, 'kind' => 'search' ) );
$assert046( 'known term on nonsearch kind rejected atomically', Context::clean( array( 'version' => 1, 'kind' => 'home', 'search' => $known046 ) ) === array( 'ok' => false, 'code' => 'bad_page_context' ) );

final class Search_DB046 {
	public string $last_error = ''; public array $events = array(); public array $scans = array();
	public function __construct( public object $legacy ) {}
	public function prepare( $sql, ...$values ) { return $this->legacy->prepare( $sql, ...$values ); }
	public function get_var( $sql ) { return str_contains( $sql, 'WHERE id = 1 AND visitor_id = 1' ) ? 1 : null; }
	public function get_row( ...$args ) { return $this->legacy->get_row( ...$args ); }
	public function get_results( $sql, $mode ) {
		if ( ! str_contains( $sql, 'SELECT e.id,e.event_uid' ) ) { return $this->legacy->get_results( $sql, $mode ); }
		preg_match( '/e.id>(\d+).*e.occurred_ms>=(\d+) AND e.occurred_ms<(\d+)/', $sql, $m );
		$rows = array_values( array_filter( $this->events, static fn( $e ) => $e['id'] > (int) $m[1] && $e['occurred_ms'] >= (int) $m[2] && $e['occurred_ms'] < (int) $m[3] ) );
		usort( $rows, static fn( $a, $b ) => $a['id'] <=> $b['id'] ); $rows = array_slice( $rows, 0, 500 ); $this->scans[] = count( $rows ); return $rows;
	}
}
$wpdb = new Search_DB046( $wpdb );
$add046 = static function ( int $id, ?array $search, array $extra = array(), string $kind = 'search' ) use ( $wpdb ): void {
	$context = array( 'version' => 1, 'kind' => $kind ); if ( null !== $search ) { $context['search'] = $search; }
	$wpdb->events[] = array_merge( array( 'id' => $id, 'event_uid' => 'search-uid-' . $id, 'visitor_id' => 1, 'visit_id' => 1, 'owner' => 1, 'is_test' => 0, 'visit_test' => 0, 'visitor_test' => 0, 'type' => 'page_view', 'source' => 'client', 'occurred_ms' => 110000, 'data' => json_encode( array( 'page_context' => $context ) ) ), $extra );
};
for ( $i046 = 1; $i046 <= 502; ++$i046 ) { $add046( $i046, $known046 ); }
$scan046 = Searches::period( 100, 200 );
$assert046( 'all 502 equal-time events counted across keyset500', $scan046['rows'] === array( 'demo peptide' => 502 ) && $wpdb->scans === array( 500, 2 ) );
$add046( 503, array( 'version' => 1, 'status' => 'empty' ) ); $add046( 504, array( 'version' => 1, 'status' => 'filtered' ) ); $add046( 505, null );
foreach ( array( array( 'owner' => 99 ), array( 'source' => 'server' ), array( 'type' => 'product_view' ), array( 'is_test' => 1 ), array( 'visit_test' => 1 ), array( 'visitor_test' => 1 ), array( 'occurred_ms' => 99999 ), array( 'occurred_ms' => 200000 ), array( 'event_uid' => '' ) ) as $i046 => $bad046 ) { $add046( 506 + $i046, $known046, $bad046 ); }
$add046( 516, null, array(), 'home' ); $add046( 517, array( 'version' => 2, 'status' => 'empty' ) );
$scan046 = Searches::period( 100, 200 );
$assert046( 'owner/server/test/time/invalid exclusions and explicit source coverage', $scan046['rows'] === array( 'demo peptide' => 502 ) && $scan046['coverage'] === array( 'unit' => 'search_page_views', 'base_count' => 505, 'known' => 502, 'empty' => 1, 'filtered' => 1, 'unobserved' => 1 ) );
$incoming046 = array( 'event_id' => 'search-uid-one', 'page' => array( 'path' => '/', 'page_type' => 'content' ), 'data' => array( 'page_context' => $context046 ), 'page_instance_id' => 'instance-one', 'tab_id' => 'tab-one', 'seq' => 1 );
$saved046 = array( 'event_uid' => 'search-uid-one', 'visitor_id' => 1, 'visit_id' => 1, 'type' => 'page_view', 'source' => 'client', 'path' => '/', 'page_type' => 'content', 'product_id' => 0, 'variation_id' => 0, 'page_instance_id' => 'instance-one', 'tab_id' => 'tab-one', 'seq' => 1, 'data' => json_encode( array( 'page_context' => $context046 ) ) );
$assert046( 'same UID canonical retry remains already_saved', Context::replay( 1, $incoming046, $saved046 )['code'] === 'already_saved' );
$incoming046['data']['page_context']['search']['term'] = 'changed';
$assert046( 'same UID changed search rejects immutable identity', Context::replay( 1, $incoming046, $saved046 )['code'] === 'duplicate_payload_mismatch' );
for ( $i046 = 0; $i046 < 61; ++$i046 ) { $add046( 600 + $i046, Search::source( 'term ' . $i046 ) ); }
$add046( 700, Search::source( '+formula' ) ); $add046( 701, Search::source( 'previous only' ), array( 'occurred_ms' => 51000 ) );
$args = array( 'table' => 'search_terms', 'q' => '', 'page' => 1, 'per_page' => 100, 'format' => 'json' );
$result046 = Ranks::listing( $range, $args ); $full046 = iterator_to_array( Ranks::all_rows( $range, $args ) ); $summary046 = Ranks::summary( $range );
$assert046( '20th count ranking full>50 shares summary sorting and units', count( Ranks::TABLES ) === 21 && Ranks::TABLES[19] === 'search_terms' && count( $full046 ) === 64 && $result046['rows'] === $full046 && $summary046['tables']['search_terms']['rows'] === array_slice( $full046, 0, 50 ) && $result046['value_unit'] === 'count' );
$assert046( 'full listing includes source coverage and prior-side lower-bound probe', $result046['coverage']['current']['known'] === 564 && $result046['coverage']['previous']['known'] === 2 );
$args['q'] = $full046[50]['label']; $found046 = Ranks::listing( $range, $args );
$assert046( 'search beyond summary50 uses full selection', in_array( $full046[50]['key'], array_column( $found046['rows'], 'key' ), true ) );
$args['q'] = ''; $args['page'] = 2; $args['per_page'] = 20; $page046 = Ranks::listing( $range, $args );
$assert046( 'page2 uses exact complete slice', $page046['rows'] === array_slice( $full046, 20, 20 ) );
$csv046 = iterator_to_array( $csv_method->invoke( null, $range, $args ) );
$assert046( 'real13column CSV exports complete ordering notpage or50', count( $csv046 ) === 65 && count( $csv046[0] ) === 13 && array_column( array_slice( $csv046, 1 ), 1 ) === array_map( array( PeptideCore\Data\Csv_Response::class, 'text' ), array_column( $full046, 'key' ) ) );
$assert046( 'CSV formula-neutralizes admissible plus term', in_array( "'+formula", array_column( $csv046, 2 ), true ) );
$args['q'] = 'formula'; $csv046 = iterator_to_array( $csv_method->invoke( null, $range, $args ) );
$assert046( 'full CSV honors searchfilter', count( $csv046 ) === 2 && $csv046[1][1] === "'+formula" );
echo "PASS $n046 native search assertions\n";
