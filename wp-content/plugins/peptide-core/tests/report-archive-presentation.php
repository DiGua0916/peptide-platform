<?php
/** Candidate shortcode + date grouping, isolated public DTO fixtures. No WordPress bootstrap/DB/files. */
namespace PeptideCore {
    final class Storefront_Translation { public static string $language = 'en'; public static function active(): bool { return 'es' === self::$language; } public static function code(): string { return self::$language; } }
    final class Report_Archive {
        public static array $calls = array(); public static $result = null;
        public static function query( array $args ) { self::$calls[] = $args; return is_callable( self::$result ) ? ( self::$result )( $args ) : self::$result; }
    }
}
namespace {
    define( 'ABSPATH', __DIR__ . '/' ); define( 'PEPTIDE_CORE_DIR', dirname( __DIR__ ) ); define( 'PEPTIDE_CORE_FILE', PEPTIDE_CORE_DIR . '/peptide-core.php' );
    class WP_Error { public function __construct( public string $code, public string $message, public array $data = array() ) {} public function get_error_data() { return $this->data; } public function get_error_message() { return $this->message; } }
    class WP_Post { public string $post_content = '[pc_report_library]'; }
    function get_option( ...$unused ) { throw new \RuntimeException( 'DB/option access forbidden' ); }
    function update_option( ...$unused ) { throw new \RuntimeException( 'DB mutation forbidden' ); }
    function __( $text, $domain = '' ) { static $es = null; $es ??= json_decode( file_get_contents( PEPTIDE_CORE_DIR . '/languages/storefront-es-ui.json' ), true )['strings']; return \PeptideCore\Storefront_Translation::$language === 'es' ? ( $es[$text] ?? $text ) : $text; }
    function _n( $one, $many, $n, $domain = '' ) { if ( \PeptideCore\Storefront_Translation::$language === 'es' && $one === '%d report' ) { return $n === 1 ? '%d informe' : '%d informes'; } return $n === 1 ? $one : $many; }
    function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
    function esc_attr( $value ) { return esc_html( $value ); } function esc_url( $value ) { return esc_html( $value ); }
    function esc_html__( $text, $domain = '' ) { return esc_html( __( $text, $domain ) ); } function esc_attr__( $text, $domain = '' ) { return esc_attr( __( $text, $domain ) ); }
    function esc_html_e( $text, $domain = '' ) { echo esc_html__( $text, $domain ); } function esc_attr_e( $text, $domain = '' ) { echo esc_attr__( $text, $domain ); }
    function is_wp_error( $value ) { return $value instanceof WP_Error; } function wp_unslash( $value ) { return stripslashes( $value ); } function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
    function shortcode_atts( $defaults, $atts, $tag ) { return array_merge( $defaults, array_intersect_key( $atts, $defaults ) ); }
    function selected( $one, $two ) { if ( $one === $two ) { echo 'selected'; } }
    function wp_unique_id( $prefix ) { static $id = 0; return $prefix . ++$id; } function absint( $n ) { return abs( (int) $n ); }
    function get_queried_object_id() { return 11710; } function get_permalink( $id ) { return $GLOBALS['permalink'] ?? 'https://shop.example/coa-library/'; } function home_url( $path ) { return 'https://shop.example' . $path; }
    function wp_parse_url( $url, $part = -1 ) { return parse_url( $url, $part ); }
    function add_query_arg( $args, $url ) { $parts = parse_url( $url ); $query = array(); parse_str( $parts['query'] ?? '', $query ); $query = array_merge( $query, $args ); return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? 'shop.example' ) . ( $parts['path'] ?? '/' ) . '?' . http_build_query( $query ); }
    function size_format( $bytes, $digits ) { return round( $bytes / 1048576, $digits ) . ' MB'; }
    function wp_date( $format, $timestamp, $timezone ) { $text = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( $format ); if ( 'es' === \PeptideCore\Storefront_Translation::$language ) { $text = strtr( $text, array( 'October' => 'octubre', 'September' => 'septiembre', 'August' => 'agosto', 'Oct' => 'oct', 'Sep' => 'sept', 'Aug' => 'ago' ) ); } return $text; }
    function wp_style_is( $handle, $state ) { return 'vialcove-brand' === $handle; }
    function plugins_url( $path, $file ) { return 'https://shop.example/wp-content/plugins/peptide-core/' . $path; }
    function wp_enqueue_style( $handle, $url, $deps, $ver ) { $GLOBALS['assets'][$handle . '-style'] = compact( 'url', 'deps', 'ver' ); }
    function wp_enqueue_script( $handle, $url, $deps, $ver, $footer ) { $GLOBALS['assets'][$handle . '-script'] = compact( 'url', 'deps', 'ver', 'footer' ); }
    function add_shortcode( $name, $callback ) { $GLOBALS['shortcodes'][$name] = $callback; } function add_action( $name, $callback, $priority ) { $GLOBALS['hooks'][$name] = $callback; }
    function is_admin() { return $GLOBALS['admin'] ?? false; } function is_singular() { return true; } function get_queried_object() { return $GLOBALS['page'] ?? new WP_Post(); } function has_shortcode( $content, $tag ) { return str_contains( $content, '[' . $tag ); }
    require PEPTIDE_CORE_DIR . '/includes/class-report-archive-page.php';
    use PeptideCore\Report_Archive_Page as Page;
    use PeptideCore\Report_Archive as Archive;
    function row( int $id, $date ): array { return array( 'id' => $id, 'title' => 'Report title ' . $id, 'source_product' => 'Retatrutide', 'product' => array( 'id' => 11006, 'name' => 'Retatrutide', 'url' => 'https://shop.example/product/retatrutide/' ), 'specification' => '20mg*10vials', 'report_number' => 'COA-' . $id, 'source_lot' => 'LOT-' . $id, 'issued_on' => $date, 'issuer_name' => 'Original lab', 'file' => array( 'size_bytes' => 2097152, 'mime' => 'application/pdf' ), 'thumbnail_url' => 'https://shop.example/thumb/' . $id, 'view_url' => 'https://shop.example/file/' . $id . '?download=0', 'download_url' => 'https://shop.example/file/' . $id . '?download=1' ); }
    function dataset( array $items, int $page = 1, int $pages = 1, ?int $total = null ): array { return array( 'items' => $items, 'page' => $page, 'total_pages' => $pages, 'total' => $total ?? count( $items ), 'products' => array( array( 'id' => 11006, 'name' => 'Retatrutide', 'count' => 4 ) ) ); }
    $checks = array();
    function check( bool $pass, string $name ): void { $GLOBALS['checks'][] = array( 'name' => $name, 'passed' => $pass ); }
    if ( ( $argv[1] ?? '' ) === '--render' ) {
        $fixture = json_decode( file_get_contents( $argv[2] ), true );
        \PeptideCore\Storefront_Translation::$language = $argv[3] ?? 'en'; $_GET = array();
        Archive::$result = $fixture; echo Page::shortcode( array( 'per_page' => 24 ) ); exit;
    }
    $input = array( row( 1, '2026-10-21' ), row( 2, '2026-10-02' ), row( 3, '2026-09-30' ), row( 4, null ), row( 5, '' ), row( 6, '2026-02-30' ) );
    $original = serialize( $input ); $groups = Page::group_items( $input );
    check( array_column( $groups, 'key' ) === array( '2026-10', '2026-09', 'unknown' ), 'Date-sorted public reports group by real issue year/month; null, blank and invalid dates stay unknown' );
    check( array_map( static fn( $g ) => count( $g['items'] ), $groups ) === array( 2, 1, 3 ), 'No undated report disappears or acquires a made-up date' );
    check( serialize( $input ) === $original && array_column( array_merge( ...array_column( $groups, 'items' ) ), 'id' ) === array_column( $input, 'id' ), 'Grouping preserves input DTOs and exact service order without mutation' );
    $oldest = array_reverse( array_slice( $input, 0, 3 ) );
    check( array_column( Page::group_items( $oldest, 'oldest' ), 'key' ) === array( '2026-09', '2026-10' ), 'Oldest-first order stays chronological rather than being rewritten newest-first' );
    $mixed = array( $input[0], $input[2], $input[1] );
    check( array_column( Page::group_items( $mixed ), 'key' ) === array( '2026-10', '2026-09', '2026-10' ), 'Unexpected noncontiguous dates never silently reorder authority results' );
    check( Page::group_items( $mixed, 'product' ) === array( array( 'key' => 'results', 'month' => null, 'items' => $mixed ) ), 'Nondate sorting keeps one honest flat result group instead of inventing chronological order' );
    check( Page::group_items( array() ) === array(), 'Empty group input creates no fake date section' );
    Page::register_hooks(); check( isset( $GLOBALS['shortcodes'][Page::SHORTCODE], $GLOBALS['hooks']['wp_enqueue_scripts'] ), 'Existing shortcode and scoped frontend enqueue are registered' );
    Page::enqueue_assets(); check( count( $GLOBALS['assets'] ) === 2 && ! str_contains( implode( ' ', array_column( $GLOBALS['assets'], 'url' ) ), '/assets/report-library.css' ), 'Only the two distinct public assets load, never the admin report-library stylesheet' );
    $GLOBALS['assets'] = array(); $GLOBALS['admin'] = true; Page::enqueue_assets(); check( !$GLOBALS['assets'], 'Public styling is not injected into admin' ); $GLOBALS['admin'] = false;
    $page = new WP_Post(); $page->post_content = 'Ordinary content'; $GLOBALS['page'] = $page; Page::enqueue_assets(); check( !$GLOBALS['assets'], 'Unrelated public pages do not enqueue report assets' ); unset( $GLOBALS['page'] );
    $_GET = array(); Archive::$result = dataset( $input, 1, 19, 219 ); $html = Page::shortcode();
    check( str_contains( $html, '219 reports' ) && substr_count( $html, 'data-report-id=' ) === 6, 'The true matching total and all current-page reports remain represented' );
    check( str_contains( $html, '<time datetime="2026-10">October 2026</time>' ) && str_contains( $html, '<time datetime="2026-09">September 2026</time>' ) && str_contains( $html, 'Issue date not provided' ), 'Rendered month groups carry real datetime and an explicit missing-date section' );
    check( !str_contains( $html, 'datetime="2026-02-30"' ) && str_contains( $html, 'datetime="2026-10-21"' ), 'Cards expose valid specific dates, never invalid or invented time attributes' );
    check( str_contains( $html, 'download=0' ) && str_contains( $html, 'download=1' ) && str_contains( $html, 'target="_blank" rel="noopener noreferrer"' ), 'Original public PDF view/download endpoints and safe new-tab fallback are retained' );
    check( str_contains( $html, 'width="640" height="829" loading="lazy"' ) && str_contains( $html, '<h3 class="pc-coa-library__title"' ), 'Preview reserves layout, lazy-loads and follows month h2 with report h3' );
    foreach ( array( 'coa_q','coa_product','coa_from','coa_to','coa_sort' ) as $field ) { check( str_contains( $html, 'name="' . $field . '"' ), 'Native no-JS GET filter remains: ' . $field ); }
    check( str_contains( $html, '<details class="pc-coa-library__date-filter"' ) && str_contains( $html, 'Report details' ) && str_contains( $html, 'Original lab' ) && str_contains( $html, 'Report title 1' ), 'Native date and source details remain available without JavaScript' );
    $_GET = array( 'coa_q' => 'COA-2', 'coa_product' => '11006', 'coa_from' => '2026-09-01', 'coa_to' => '2026-10-21', 'coa_sort' => 'oldest', 'coa_page' => '2', 'pc_lang' => 'es', 'pc_lang_auto' => '1' );
    Archive::$calls = array(); Archive::$result = dataset( array( $input[1] ), 2, 19, 219 ); $html = Page::shortcode();
    check( Archive::$calls[0] === array( 'q'=>'COA-2','product_id'=>11006,'date_from'=>'2026-09-01','date_to'=>'2026-10-21','sort'=>'oldest','page'=>2,'per_page'=>12 ), 'Query/specification/report-number/dated filters and oldest sorting reach the original service unchanged' );
    check( str_contains( $html, 'name="pc_lang" value="es"' ) && str_contains( $html, 'name="pc_lang_auto" value="1"' ), 'Native filter submission preserves explicit/automatic language context without writing cookies' );
    check( str_contains( $html, 'coa_q=COA-2' ) && str_contains( $html, 'coa_product=11006' ) && str_contains( $html, 'coa_page=3' ) && str_contains( $html, 'pc_lang=es' ) && str_contains( $html, 'pc_lang_auto=1' ) && str_contains( $html, 'aria-current="page"' ), 'Pagination preserves all active filters/language and exposes the true current page' );
    $_GET = array( 'coa_from' => '2026-10-21', 'coa_to' => '2026-09-01' ); Archive::$calls = array(); $html = Page::shortcode();
    check( !Archive::$calls && str_contains( $html, 'role="alert"' ) && str_contains( $html, 'Clear filters' ), 'Invalid date ranges stop the query and offer a real reset link' );
    foreach ( array( array( 'coa_q' => array('invalid') ), array( 'coa_sort' => 'unrecognized' ), array( 'coa_from' => '2026-02-30' ) ) as $bad ) { $_GET = $bad; Archive::$calls = array(); $html = Page::shortcode(); check( !Archive::$calls && str_contains( $html, 'role="alert"' ), 'Invalid arrays, unsupported sort or invalid calendar date do not invoke the report service' ); }
    $_GET = array( 'coa_q' => 'no-match' ); Archive::$result = dataset( array() ); $html = Page::shortcode(); check( str_contains( $html, 'No reports match these filters' ) && str_contains( $html, 'Clear filters' ), 'Filtered empty state explains recovery without rendering fake cards' );
    $_GET = array(); Archive::$result = new WP_Error( 'unavailable', 'sensitive backend detail', array('status'=>500) ); $html = Page::shortcode(); check( str_contains( $html, 'could not be loaded' ) && !str_contains( $html, 'sensitive backend detail' ), 'Service errors keep private failure details out of the public page' );
    $malicious = row( 7, null ); $malicious['source_product'] = '<script>bad()</script>'; $malicious['product'] = null; $malicious['title'] = '<img src=x onerror=bad()>'; $malicious['thumbnail_url'] = ''; Archive::$result = dataset( array( $malicious ) ); $html = Page::shortcode();
    check( !str_contains( $html, '<script>' ) && !str_contains( $html, '<img src=x' ) && str_contains( $html, '&lt;script&gt;' ) && str_contains( $html, 'PDF report' ), 'Public title/source text is escaped and a genuinely missing thumbnail uses the real file fallback' );
    $GLOBALS['permalink'] = 'https://shop.example/?page_id=11710'; $_GET = array(); Archive::$result = dataset( array( $input[0] ) ); $html = Page::shortcode(); check( str_contains( $html, 'name="page_id" value="11710"' ), 'Plain native WordPress permalink hidden field survives the form redesign' ); unset( $GLOBALS['permalink'] );
    \PeptideCore\Storefront_Translation::$language = 'es'; Archive::$result = dataset( array( $input[0], $input[3] ) ); $html = Page::shortcode();
    check( str_contains( $html, 'octubre 2026' ) && str_contains( $html, 'Fecha de emisión no proporcionada' ) && str_contains( $html, 'Detalles del informe' ) && str_contains( $html, 'Buscar informes' ), 'Existing Spanish locale plus scoped new labels cover month/missing-date/details/filter UI' );
    check( str_contains( $html, '20mg*10vials' ) && str_contains( $html, 'COA-1' ) && str_contains( $html, 'LOT-1' ) && str_contains( $html, 'translate="no"' ), 'Native specification, report identifiers and product names are preserved across language presentation' );
    $failed = array_filter( $checks, static fn( $x ) => !$x['passed'] );
    echo json_encode( array( 'passed' => count($checks)-count($failed), 'total'=>count($checks), 'tests'=>$checks, 'scope'=>'Candidate shortcode and group logic with synthetic public DTO/native-function boundaries; no WP bootstrap, DB, real PDF or HTTP' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
    exit( $failed ? 1 : 0 );
}
