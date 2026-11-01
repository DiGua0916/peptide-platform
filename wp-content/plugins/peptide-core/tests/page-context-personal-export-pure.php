<?php
/** Actual exporter with original048 oracle and memory-only identity/DB/translation boundaries. */
namespace PeptideCore\Tracking {
	final class Privacy { public static array $ids = array( 7, 9 ); public static function visitors_for_email( string $email ): array { return self::$ids; } }
	final class Schema { public static function table( string $name ): string { return 'memory_' . $name; } }
}
namespace {
define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' );
function __( $text, $domain = '' ) { return $GLOBALS['chinese049'] ? ( $GLOBALS['translations049'][$text] ?? $text ) : $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( __( $text, $domain ), ENT_QUOTES, 'UTF-8' ); }
function wp_hash( $text ) { return hash_hmac( 'sha256', $text, 'memory-boundary-only' ); }
function wp_add_privacy_policy_content( $label, $html ) { $GLOBALS['policies049'][$label] = $html; }
$chinese049 = false; $policies049 = array();
$root049 = dirname( __DIR__, 4 ); $includes049 = dirname( __DIR__ ) . '/includes/tracking/';
foreach ( array( 'class-search-context.php', 'class-client-environment.php', 'class-page-context.php', 'class-page-context-privacy.php' ) as $file049 ) { require $includes049 . $file049; }
$old049 = file_get_contents( $root049 . '/tests/fixtures/data-observations/049/class-page-context-privacy.php' );
$old049 = str_replace( 'final class Page_Context_Privacy {', 'final class Page_Context_Privacy048Oracle {', $old049 ); eval( substr( $old049, 5 ) );
use PeptideCore\Tracking\Page_Context_Privacy as Exporter;
use PeptideCore\Tracking\Page_Context_Privacy048Oracle as Oracle;
use PeptideCore\Tracking\Privacy;
final class Export_DB049 {
	public array $rows = array(); public array $queries = array();
	public function prepare( $sql, ...$values ) { return (object) array( 'sql' => $sql, 'values' => $values ); }
	public function get_results( $query, $mode ): array {
		$this->queries[] = array( $query->sql, $query->values, $mode ); preg_match( '/visitor_id IN \(([^)]+)\)/', $query->sql, $matches ); $ids = array_map( 'intval', explode( ',', $matches[1] ) );
		$rows = array_values( array_filter( $this->rows, static fn( $row ) => in_array( $row['visitor_id'], $ids, true ) && 'page_view' === $row['type'] ) );
		usort( $rows, static fn( $a, $b ) => $a['id'] <=> $b['id'] ); return array_slice( $rows, $query->values[0], 501 );
	}
}
function row049( int $id, $context, array $extra = array() ): array { return array_merge( array( 'id' => $id, 'visitor_id' => 7, 'type' => 'page_view', 'path' => '/native/', 'occurred_ms' => 1700000000123, 'data' => json_encode( array( 'page_context' => $context, 'raw_other' => 'PRIVATE_OTHER' ) ) ), $extra ); }
function fields049( array $result ): array { return array_column( $result['data'][0]['data'] ?? array(), 'value', 'name' ); }
$passed049 = 0; $check049 = static function ( string $label, bool $yes ) use ( &$passed049 ): void { if ( ! $yes ) { throw new \RuntimeException( $label ); } ++$passed049; };
$wpdb = new Export_DB049();
foreach ( array( array( 'version' => 1, 'kind' => 'home' ), array( 'version' => 1, 'kind' => 'collection', 'term_id' => 123, 'geo' => array( 'status' => 'known', 'country_code' => 'US' ) ), array( 'version' => 1, 'kind' => 'search', 'geo' => array( 'status' => 'unavailable' ) ), array( 'version' => 1, 'kind' => 'content', 'geo' => array( 'status' => 'unknown' ) ) ) as $context049 ) {
	$wpdb->rows = array( row049( 1, $context049 ), row049( 2, $context049, array( 'visitor_id' => 999 ) ), row049( 3, $context049, array( 'type' => 'product_view' ) ) );
	$oldResult049 = Oracle::export( 'synthetic@example.test' ); $oldQuery049 = $wpdb->queries[array_key_last( $wpdb->queries )]; $newResult049 = Exporter::export( 'synthetic@example.test' );
	$check049( 'old context export JSON remains byte-identical', json_encode( $oldResult049 ) === json_encode( $newResult049 ) );
	$check049( 'native owner/type query projection unchanged', $oldQuery049 === $wpdb->queries[array_key_last( $wpdb->queries )] && count( $newResult049['data'] ) === 1 );
}
$base049 = array( 'version' => 1, 'kind' => 'search' );
foreach ( array( 'known', 'unknown', 'unavailable' ) as $os049 ) {
	foreach ( array( 'known', 'unknown', 'unavailable' ) as $screen049 ) {
		$osFact049 = array( 'status' => $os049 ); if ( 'known' === $os049 ) { $osFact049['family'] = 'Windows'; }
		$screenFact049 = array( 'status' => $screen049 ); if ( 'known' === $screen049 ) { $screenFact049 += array( 'width' => 1920, 'height' => 1080 ); }
		$context049 = $base049 + array( 'environment' => array( 'version' => 1, 'os' => $osFact049, 'screen' => $screenFact049 ) ); $wpdb->rows = array( row049( 1, $context049 ) ); $facts049 = fields049( Exporter::export( 'synthetic@example.test' ) );
		$check049( 'independent OS status and known-only family', $facts049['Operating system observation'] === $os049 && ( 'known' === $os049 ? ( $facts049['Operating system family'] ?? null ) === 'Windows' : ! isset( $facts049['Operating system family'] ) ) );
		$check049( 'independent screen status and known-only CSS dimensions', $facts049['Screen size observation'] === $screen049 && ( 'known' === $screen049 ? ( $facts049['Screen width (CSS pixels)'] ?? null ) === '1920' && ( $facts049['Screen height (CSS pixels)'] ?? null ) === '1080' : ! array_key_exists( 'Screen width (CSS pixels)', $facts049 ) && ! array_key_exists( 'Screen height (CSS pixels)', $facts049 ) ) );
	}
}
foreach ( array( 'os', 'screen' ) as $only049 ) {
	$context049 = $base049 + array( 'environment' => array( 'version' => 1, $only049 => array( 'status' => 'unknown' ) ) ); $wpdb->rows = array( row049( 1, $context049 ) ); $facts049 = fields049( Exporter::export( 'synthetic@example.test' ) );
	$check049( 'absent independent environment fact not fabricated', $only049 === 'os' ? ! isset( $facts049['Screen size observation'] ) : ! isset( $facts049['Operating system observation'] ) );
}
foreach ( array( 'known', 'empty', 'filtered' ) as $status049 ) {
	$search049 = array( 'version' => 1, 'status' => $status049, 'raw' => 'PRIVATE_REJECTED' ); if ( 'known' === $status049 ) { $search049['term'] = '研究 肽'; }
	$wpdb->rows = array( row049( 1, $base049 + array( 'search' => $search049 ) ) ); $result049 = Exporter::export( 'synthetic@example.test' ); $facts049 = fields049( $result049 );
	$check049( 'known/empty/filtered canonical search status and known-only term', $facts049['Search observation'] === $status049 && ( 'known' === $status049 ? ( $facts049['Observed search term'] ?? null ) === '研究 肽' : ! isset( $facts049['Observed search term'] ) ) );
	$check049( 'raw rejected keys/other event data never exported', ! str_contains( json_encode( $result049 ), 'PRIVATE' ) );
}
$all049 = $base049 + array( 'environment' => array( 'version' => 1, 'os' => array( 'status' => 'known', 'family' => 'iOS', 'raw' => 'PRIVATE_UA' ), 'screen' => array( 'status' => 'known', 'width' => 1, 'height' => 16384, 'raw' => 'PRIVATE_SCREEN' ) ), 'search' => array( 'version' => 1, 'status' => 'known', 'term' => 'demo peptide' ) );
$wpdb->rows = array( row049( 12, $all049 ) ); $result049 = Exporter::export( 'synthetic@example.test' ); $facts049 = fields049( $result049 );
$check049( 'all seven optional fields and existing item identity retained', count( $facts049 ) === 12 && $result049['data'][0]['item_id'] === 'page-context-' . wp_hash( 'page-context|12' ) && ! str_contains( json_encode( $result049 ), 'PRIVATE' ) );
foreach ( array( null, array( 'version' => 2, 'kind' => 'search' ), $base049 + array( 'search' => array( 'version' => 1, 'status' => 'empty', 'term' => 'PRIVATE' ) ), $base049 + array( 'search' => array( 'version' => 1, 'status' => 'known', 'term' => 'UPPER' ) ), $base049 + array( 'environment' => array( 'version' => 1, 'os' => array( 'status' => 'unknown', 'family' => 'Windows' ) ) ), array( 'version' => 1, 'kind' => 'home', 'search' => array( 'version' => 1, 'status' => 'filtered' ) ) ) as $bad049 ) {
	$wpdb->rows = array( row049( 1, $bad049 ) ); $check049( 'noncanonical context skipped atomically', Exporter::export( 'synthetic@example.test' ) === array( 'data' => array(), 'done' => true ) );
}
$wpdb->rows = array(); for ( $i049 = 1; $i049 <= 501; ++$i049 ) { $wpdb->rows[] = row049( $i049, $i049 === 501 ? $all049 : null ); }
$first049 = Exporter::export( 'synthetic@example.test', 1 ); $second049 = Exporter::export( 'synthetic@example.test', 2 );
$check049( '501 raw rows paginate even when first500 emit no items', $first049 === array( 'data' => array(), 'done' => false ) && $second049['done'] === true && count( $second049['data'] ) === 1 && $second049['data'][0]['item_id'] === 'page-context-' . wp_hash( 'page-context|501' ) );
$check049( 'same raw offset/done oracle despite new optional fields', Oracle::export( 'synthetic@example.test', 1 ) === $first049 && Oracle::export( 'synthetic@example.test', 2 )['done'] === $second049['done'] );
$wpdb->rows = array_slice( $wpdb->rows, 0, 500 ); $check049( 'exact500 raw records mark completion', Exporter::export( 'synthetic@example.test', 1 )['done'] === true );
Privacy::$ids = array(); $before049 = count( $wpdb->queries ); $check049( 'empty account association emits nothing and does not query', Exporter::export( 'synthetic@example.test' ) === Oracle::export( 'synthetic@example.test' ) && count( $wpdb->queries ) === $before049 ); Privacy::$ids = array( 7, 9 );
$translations049 = require dirname( __DIR__ ) . '/languages/customer-data-observations-zh_CN.php'; $oldTranslations049 = require $root049 . '/tests/fixtures/data-observations/049/customer-data-observations-zh_CN.php';
$check049( 'all048 catalogue values retained', array_intersect_key( $translations049, $oldTranslations049 ) === $oldTranslations049 );
$messages049 = json_decode( file_get_contents( $root049 . '/tests/fixtures/data-observations/049/new-messages.json' ), true );
foreach ( $messages049 as $name049 => $chineseValue049 ) { $check049( 'all new export/policy messages translated', ( $translations049[$name049] ?? null ) === $chineseValue049 ); }
Exporter::policy_text(); $policy049 = array_key_last( $messages049 ); $check049( 'actual policy includes optional observations and existing export/retention rules', $policies049['Store page context'] === '<p>' . htmlspecialchars( $policy049, ENT_QUOTES, 'UTF-8' ) . '</p>' );
$chinese049 = true; Exporter::policy_text(); $check049( 'actual policy can use scoped Chinese supplement', $policies049['Store page context'] === '<p>' . htmlspecialchars( $messages049[$policy049], ENT_QUOTES, 'UTF-8' ) . '</p>' );
echo json_encode( array( 'passed' => $passed049, 'failed' => 0, 'old_export_oracle' => '048', 'database' => 'memory', 'wordpress_bootstrap' => false, 'payload_output' => false ) ), "\n";
}
