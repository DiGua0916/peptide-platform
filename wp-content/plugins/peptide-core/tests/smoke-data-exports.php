<?php
/** Guarded native/real-HTTP export integration tests. Never run against a user database. */
defined( 'ABSPATH' ) || exit;

use PeptideCore\Data\Csv_Response;
use PeptideCore\Data\Products;
use PeptideCore\Data\Page_Visits;
use PeptideCore\Data\Range;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Privacy;

global $wpdb;
if ( DB_NAME !== 'peptide_history_20261030' || $wpdb->get_var( 'SELECT DATABASE()' ) !== DB_NAME || wp_get_environment_type() !== 'local' || wp_normalize_path( ABSPATH ) !== 'D:/Projects/peptide-sync-work/20261030-history/site/' || ! defined( 'PC_SYNC_ISOLATION_GUARD' ) || home_url() !== 'http://localhost:8093' ) { throw new RuntimeException( '025 requires the existing private clone.' ); }
$passed = 0;
$check = static function ( string $name, bool $ok ) use ( &$passed ): void { if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } ++$passed; echo 'PASS ', $name, "\n"; };
$products = array(); $orders = array(); $users = array(); $visitors = array(); $sessions = array();
$original_user = get_current_user_id(); $original_cookie = $_COOKIE;
$prefix = 'csv025-' . wp_generate_uuid4();
$range = Range::from_params( array( 'from' => '2018-06-10', 'to' => '2018-06-10' ) );
$args = Products::params( array( 'page' => '2', 'per_page' => '20' ) );
$path_args = Page_Visits::params( array( 'page' => '2', 'per_page' => '20' ) );
$no_mail = static fn() => false; $no_cron = static fn() => true; $no_as = static fn() => 0;
add_filter( 'pre_wp_mail', $no_mail ); add_filter( 'pre_schedule_event', $no_cron );
$as_hooks = array( 'pre_as_enqueue_async_action', 'pre_as_schedule_single_action', 'pre_as_schedule_recurring_action', 'pre_as_schedule_cron_action' );
foreach ( $as_hooks as $hook ) { add_filter( $hook, $no_as ); }
$parse = static function ( string $text ): array { $file = fopen( 'php://temp', 'w+' ); fwrite( $file, substr( $text, 0, 3 ) === "\xEF\xBB\xBF" ? substr( $text, 3 ) : $text ); rewind( $file ); $rows = array(); while ( false !== ( $row = fgetcsv( $file, null, ',', '"', '' ) ) ) { $rows[] = $row; } fclose( $file ); return $rows; };
$serve = static function ( $response, string $method = 'GET', string $route = '/peptide/v1/data/products', $already = false ): array { $request = new WP_REST_Request( $method, $route ); ob_start(); $served = Csv_Response::serve( $already, $response, $request, rest_get_server() ); return array( $served, ob_get_clean() ); };
$request = static function ( string $report, array $params = array(), string $method = 'GET' ) use ( $range ) { $r = new WP_REST_Request( $method, '/peptide/v1/data/' . $report ); $r->set_query_params( array_merge( array( 'from' => '2018-06-10', 'to' => '2018-06-10', 'format' => 'csv', 'page' => '2', 'per_page' => '20' ), $params ) ); return rest_do_request( $r ); };
try {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) ); $check( 'private administrator exists', ! empty( $admins ) ); wp_set_current_user( $admins[0]->ID );
	$wpdb->insert( Schema::table( 'visitors' ), array( 'vid' => md5( $prefix ), 'first_ms' => $range['from'] * 1000, 'last_ms' => $range['from'] * 1000, 'is_test' => 1 ) ); $visitor = (int) $wpdb->insert_id; $visitors[] = $visitor;
	$wpdb->insert( Schema::table( 'visits' ), array( 'visitor_id' => $visitor, 'start_ms' => $range['from'] * 1000, 'last_ms' => $range['from'] * 1000, 'landing_path' => '/' . $prefix . '/000/', 'pageviews' => 1, 'is_test' => 1 ) ); $visit = (int) $wpdb->insert_id;
	$event = static function ( string $type, int $product, string $path = '', int $variation = 0, bool $previous = false ) use ( $wpdb, $prefix, $visitor, $visit, $range ): void { $ms = ( $previous ? $range['compare_from'] : $range['from'] ) * 1000 + 1000; if ( false === $wpdb->insert( Schema::table( 'events' ), array( 'event_uid' => 'c025-' . md5( wp_generate_uuid4() ), 'visitor_id' => $visitor, 'visit_id' => $visit, 'type' => $type, 'occurred_ms' => $ms, 'received_ms' => $ms, 'product_id' => $product, 'variation_id' => $variation, 'path' => $path, 'data' => '{"quantity":2}', 'is_test' => 1 ) ) ) { throw new RuntimeException( 'Fixture event failed.' ); } };
	for ( $i = 0; $i < 105; ++$i ) {
		$p = new WC_Product_Simple(); $p->set_name( ( 0 === $i ? " \t=" : '' ) . $prefix . '-' . $i . ( 0 === $i ? ",中文\"\nline" : '' ) ); $p->set_regular_price( '10' ); $p->set_manage_stock( false ); $p->set_status( 'publish' ); $p->save(); $products[] = $p->get_id();
		$event( 'product_view', $p->get_id(), '', 0, 104 === $i ); $event( 'page_view', 0, '/' . $prefix . '/' . sprintf( '%03d', $i ) . '/' );
	}
	$variable = new WC_Product_Variable(); $variable->set_name( $prefix . '-variable' ); $variable->set_status( 'publish' ); $variable->set_manage_stock( false ); $variable->save(); $products[] = $variable->get_id();
	$v = new WC_Product_Variation(); $v->set_parent_id( $variable->get_id() ); $v->set_regular_price( '10' ); $v->set_manage_stock( false ); $v->set_sku( '@' . $prefix ); $v->save(); $products[] = $v->get_id(); $event( 'add_to_cart', $variable->get_id(), '', $v->get_id() );
	$deleted = new WC_Product_Simple(); $deleted->set_name( $prefix . '-deleted' ); $deleted->set_manage_stock( false ); $deleted->save(); $deleted_id = $deleted->get_id(); $products[] = $deleted_id; $event( 'product_view', $deleted_id ); $deleted->delete( true );
	$o = wc_create_order(); $orders[] = $o->get_id(); $o->add_product( wc_get_product( $products[1] ), 2 ); $o->set_customer_id( 0 ); $o->set_billing_email( $prefix . '@example.test' ); $o->calculate_totals(); $o->set_status( 'processing' ); $o->set_date_created( $range['from'] + 100 ); $o->save();
	$item = current( $o->get_items() ); $refund = wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => '5', 'line_items' => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => 5 ) ), 'restock_items' => false ) ); $check( 'owned refund fixture created', $refund instanceof WC_Order_Refund ); $orders[] = $refund->get_id();
	$all = iterator_to_array( Products::all_rows( $range, $args ) ); $all_paths = iterator_to_array( Page_Visits::all_rows( $range, $path_args ) );
	$check( 'more than 100 parent rows and paths without raising pagination cap', count( $all ) === 107 && count( $all_paths ) === 105 && count( Products::table( $range, $args )['rows'] ) === 20 && count( Page_Visits::table( $range, $path_args )['rows'] ) === 20 );
	$check( 'selection contains each parent and path once', count( array_unique( array_column( array_column( $all, 'product' ), 'id' ) ) ) === 107 && count( array_unique( array_column( $all_paths, 'path' ) ) ) === 105 );
	$queries = 0; $count_queries = static function ( $sql ) use ( &$queries ) { if ( str_contains( $sql, Schema::table( 'events' ) ) && str_contains( $sql, 'GROUP BY' ) ) { ++$queries; } return $sql; }; add_filter( 'query', $count_queries );
	Products::table( $range, $args ); $paged_queries = $queries; $queries = 0; iterator_to_array( Products::all_rows( $range, $args ) ); $full_queries = $queries; $queries = 0; Page_Visits::table( $range, $path_args ); $page_queries = $queries; $queries = 0; iterator_to_array( Page_Visits::all_rows( $range, $path_args ) ); $full_page_queries = $queries; remove_filter( 'query', $count_queries );
	$check( 'full exports aggregate once rather than querying every result page', 4 === $paged_queries && $full_queries === $paged_queries && 1 === $page_queries && $full_page_queries === $page_queries );
	$oracle = getenv( 'PC025_ORACLE_DIR' );
	if ( $oracle ) {
		foreach ( array( 'Range', 'Params', 'Operations' ) as $class ) { class_alias( 'PeptideCore\\Data\\' . $class, 'Baseline025\\' . $class ); }
		require $oracle . '/class-products.php'; require $oracle . '/class-page-visits.php';
		foreach ( array( array(), array( 'sort' => 'sales', 'order' => 'asc' ), array( 'sort' => 'cart_rate' ), array( 'q' => $prefix ), array( 'q' => '#', 'page' => '1' ), array( 'page' => '999' ) ) as $case ) { $a = Products::params( $case ); $check( 'exact baseline product JSON ' . wp_json_encode( $case ), Products::table( $range, $a ) === Baseline025\Products::table( $range, $a ) ); }
		foreach ( array( array(), array( 'sort' => 'bounce_rate', 'order' => 'asc' ), array( 'q' => '000' ), array( 'page' => '999' ) ) as $case ) { $a = Page_Visits::params( $case ); $check( 'exact baseline page JSON ' . wp_json_encode( $case ), Page_Visits::table( $range, $a ) === Baseline025\Page_Visits::table( $range, $a ) ); }
	} else { echo "SKIP private baseline oracle (PC025_ORACLE_DIR not set)\n"; }
	$response = $request( 'products', array( 'columns' => 'sales,views,sales,cart_rate' ) );
	$check( 'dispatch preserves CSV subtype and data stays null', $response instanceof Csv_Response && rest_ensure_response( $response ) === $response && null === $response->get_data() );
	$check( 'one-day filename uses inclusive selected dates', str_contains( $response->get_headers()['Content-Disposition'], 'data-products-2018-06-10-2018-06-10.csv' ) );
	list( $served, $csv ) = $serve( $response ); $rows = $parse( $csv );
	$check( 'GET raw one BOM and all parents plus collapsed data variation', true === $served && substr_count( $csv, "\xEF\xBB\xBF" ) === 1 && count( $rows ) === 109 );
	$check( 'ordered deduplicated metric triplets and half-open metadata', array_slice( $rows[0], 11 ) === array( 'current_sales', 'previous_sales', 'change_sales_pct', 'current_views', 'previous_views', 'change_views_pct', 'current_cart_rate_pct', 'previous_cart_rate_pct', 'change_cart_rate_pp' ) && $rows[1][7] === (string) $range['from'] && $rows[1][8] === (string) $range['to'] );
	$by_id = array(); foreach ( array_slice( $rows, 1 ) as $row ) { $by_id[ $row[0] . ':' . ( $row[2] ?: $row[1] ) ] = $row; }
	$check( 'formula-safe Unicode comma quotes/newline text remains one cell', $by_id[ 'product:' . $products[0] ][3] === "'" . wc_get_product( $products[0] )->get_name() );
	$check( 'refund money is raw fixed decimal and counts retain zero', $by_id[ 'product:' . $products[1] ][11] === '15.00' && $by_id[ 'product:' . $products[1] ][15] === '0' );
	$check( 'previous-only parent and deleted fallback remain', $by_id[ 'product:' . $products[104] ][14] === '0' && $by_id[ 'product:' . $products[104] ][15] === '1' && $by_id[ 'product:' . $deleted_id ][3] === '#' . $deleted_id );
	$check( 'variation null views/rates and formula-safe SKU', $by_id[ 'variation:' . $v->get_id() ][14] === '' && $by_id[ 'variation:' . $v->get_id() ][17] === '' && $by_id[ 'variation:' . $v->get_id() ][5] === "'@" . $prefix );
	list( , $csv ) = $serve( $request( 'products', array( 'columns' => '' ) ) ); $check( 'explicit empty columns preserves only 11 identity/metadata cells', count( $parse( $csv )[0] ) === 11 );
	list( , $csv ) = $serve( $request( 'products', array( 'q' => $prefix, 'sort' => 'sales', 'order' => 'asc' ) ) ); $check( 'CSV search/sort retains all matching parents', count( $parse( $csv ) ) === 108 );
	list( , $csv ) = $serve( $request( 'pages' ), 'GET', '/peptide/v1/data/pages' ); $page_rows = $parse( $csv ); $check( 'page2 exports every sorted path with origin URL and current-only schema', count( $page_rows ) === 106 && $page_rows[0] === array( 'path', 'url', 'views', 'visitors', 'bounce_rate_pct', 'current_from_epoch_s', 'current_to_epoch_s' ) && $page_rows[1][1] === 'http://localhost:8093' . $page_rows[1][0] );
	list( , $csv ) = $serve( $request( 'pages', array( 'q' => 'does-not-exist-' . $prefix ) ), 'GET', '/peptide/v1/data/pages' ); $check( 'empty export has valid header only', count( $parse( $csv ) ) === 1 );
	$consumed = 0; $generator = static function () use ( &$consumed ): iterable { ++$consumed; yield array( Csv_Response::text( ' =1' ), -2, null, 0 ); };
	$marker = new Csv_Response( $generator(), 'safe.csv' ); $check( 'HEAD serves headers with no BOM and does not consume iterable', $serve( $marker, 'HEAD' ) === array( true, '' ) && 0 === $consumed );
	foreach ( array( array( $marker, 'POST', '/peptide/v1/data/products', false ), array( $marker, 'GET', '/other', false ), array( new WP_REST_Response( null ), 'GET', '/peptide/v1/data/products', false ), array( new WP_REST_Response( array( 'code' => 'error' ), 400 ), 'GET', '/peptide/v1/data/products', false ), array( $marker, 'GET', '/peptide/v1/data/products', true ) ) as $case ) { $check( 'raw bridge respects final marker/route/method/already-served authority', $serve( ...$case ) === array( $case[3], '' ) ); }
	list( , $csv ) = $serve( $marker ); $check( 'typed negative/null/zero remain numeric or blank', $parse( $csv )[0] === array( "' =1", '-2', '', '0' ) );
	$bad_marker = new Csv_Response( array( array( 'must not stream' ) ), 'bad.csv' ); $bad_marker->set_status( 400 ); $check( 'marker with final error status does not stream', $serve( $bad_marker ) === array( false, '' ) );
	$multiline = "中文,\"quoted\"\nsecond line"; list( , $csv ) = $serve( new Csv_Response( array( array( Csv_Response::text( $multiline ), Csv_Response::text( "\t+formula" ) ) ), 'text.csv' ) ); $check( 'literal multiline comma/quote text roundtrips in one CSV cell', $parse( $csv ) === array( array( $multiline, "'\t+formula" ) ) );
	foreach ( array( array( 'format' => 'xml' ), array( 'format' => array( 'csv' ) ), array( 'columns' => array() ), array( 'columns' => 'views,bogus' ), array( '_fields' => 'rows' ), array( '_jsonp' => 'callback' ), array( '_envelope' => '' ), array( 'sort' => 'bogus' ), array( 'from' => 'invalid' ), array( 'per_page' => '101' ) ) as $bad ) { $r = $request( 'products', $bad ); $check( 'invalid export parameters stay JSON errors without attachment', 400 === $r->get_status() && ! $r instanceof Csv_Response && ! isset( $r->get_headers()['Content-Disposition'] ) ); }
	$check( 'omitted/explicit JSON remain exactly the same table payload', $request( 'products', array( 'format' => 'json' ) )->get_data() === Products::table( $range, $args ) );
	// Real requests use native per-user session tokens, matching cookies and nonce, never query credentials.
	foreach ( array( 'admin', 'staff', 'nocap', 'disabled' ) as $kind ) {
		$id = wp_insert_user( array( 'user_login' => $prefix . '-' . $kind, 'user_email' => $prefix . '-' . $kind . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'admin' === $kind ? 'administrator' : ( 'nocap' === $kind ? 'subscriber' : PeptideCore\Staff::ROLE ) ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Temporary user failed.' ); } $users[ $kind ] = $id;
		if ( 'staff' === $kind ) { ( new WP_User( $id ) )->add_cap( 'manage_woocommerce' ); }
		if ( 'disabled' === $kind ) { PeptideCore\Staff::set_modules( $id, array( 'orders' ) ); }
		if ( 'staff' === $kind ) { $check( 'staff fixture has capability without an Orders module grant', user_can( $id, 'manage_woocommerce' ) && ! user_can( $id, 'edit_shop_orders' ) ); }
		$token = WP_Session_Tokens::get_instance( $id )->create( time() + 1800 ); $logged = wp_generate_auth_cookie( $id, time() + 1800, 'logged_in', $token ); $_COOKIE[LOGGED_IN_COOKIE] = $logged; wp_set_current_user( $id );
		$sessions[ $kind ] = array( 'id' => $id, 'token' => $token, 'cookie' => AUTH_COOKIE . '=' . wp_generate_auth_cookie( $id, time() + 1800, 'auth', $token ) . '; ' . LOGGED_IN_COOKIE . '=' . $logged, 'nonce' => wp_create_nonce( 'wp_rest' ) );
		// Native disabling revokes the previously active session; never mint a new session after disabling.
		if ( 'disabled' === $kind ) { PeptideCore\Staff::set_disabled( $id, true ); }
	}
	$http = static function ( string $report, array $params, string $kind = 'admin', string $method = 'GET', string $nonce = 'valid' ) use ( $sessions ) { $headers = array(); if ( isset( $sessions[ $kind ] ) ) { $headers['Cookie'] = $sessions[ $kind ]['cookie']; if ( 'missing' !== $nonce ) { $headers['X-WP-Nonce'] = 'valid' === $nonce ? $sessions[ $kind ]['nonce'] : 'invalid'; } } $r = wp_remote_request( add_query_arg( array_merge( array( 'from' => '2018-06-10', 'to' => '2018-06-10', 'format' => 'csv', 'page' => '2' ), $params ), home_url( '/wp-json/peptide/v1/data/' . $report ) ), array( 'method' => $method, 'headers' => $headers, 'timeout' => 30, 'redirection' => 0 ) ); if ( is_wp_error( $r ) ) { throw new RuntimeException( 'Real localhost HTTP request failed: ' . $r->get_error_code() ); } return $r; };
	foreach ( array( 'products', 'pages' ) as $report ) {
		foreach ( array( 'admin', 'staff' ) as $kind ) { $r = $http( $report, array(), $kind ); $check( 'real HTTP ' . $report . ' permits existing capability ' . $kind . ' with complete CSV/headers', 200 === wp_remote_retrieve_response_code( $r ) && str_starts_with( wp_remote_retrieve_header( $r, 'content-type' ), 'text/csv' ) && str_contains( wp_remote_retrieve_header( $r, 'content-disposition' ), 'attachment;' ) && 'nosniff' === wp_remote_retrieve_header( $r, 'x-content-type-options' ) && str_contains( wp_remote_retrieve_header( $r, 'cache-control' ), 'no-store' ) && count( $parse( wp_remote_retrieve_body( $r ) ) ) === ( 'products' === $report ? 109 : 106 ) ); }
		$r = $http( $report, array(), 'admin', 'HEAD' ); $check( 'real HEAD is empty with CSV headers ' . $report, 200 === wp_remote_retrieve_response_code( $r ) && '' === wp_remote_retrieve_body( $r ) && str_starts_with( wp_remote_retrieve_header( $r, 'content-type' ), 'text/csv' ) );
		foreach ( array( array( 'guest', 'valid' ), array( 'nocap', 'valid' ), array( 'disabled', 'valid' ), array( 'admin', 'missing' ), array( 'admin', 'invalid' ) ) as $case ) { $r = $http( $report, array(), $case[0], 'GET', $case[1] ); $check( 'real auth error never downloads CSV ' . $report . '/' . implode( '/', $case ), wp_remote_retrieve_response_code( $r ) >= 400 && ! str_starts_with( wp_remote_retrieve_header( $r, 'content-type' ), 'text/csv' ) && ! wp_remote_retrieve_header( $r, 'content-disposition' ) && ! str_contains( wp_remote_retrieve_body( $r ), "\xEF\xBB\xBF" ) ); }
		foreach ( array( array( 'from' => 'bad' ), array( 'sort' => 'bad' ), array( 'format' => 'bad' ), array( 'format' => array( 'csv' ) ), array( '_fields' => 'rows' ), array( '_jsonp' => 'callback' ), array( '_envelope' => '1' ) ) as $bad ) { $r = $http( $report, $bad ); $body = wp_remote_retrieve_body( $r ); $envelope = isset( $bad['_envelope'] ); $data = $envelope ? json_decode( $body, true ) : null; $check( 'native HTTP export validation/envelope semantics ' . $report . '/' . implode( ',', array_keys( $bad ) ), ( $envelope ? 200 === wp_remote_retrieve_response_code( $r ) && 400 === ( $data['status'] ?? 0 ) && 'pc_invalid_param' === ( $data['body']['code'] ?? '' ) : 400 === wp_remote_retrieve_response_code( $r ) ) && ! wp_remote_retrieve_header( $r, 'content-disposition' ) && ! str_contains( $body, "\xEF\xBB\xBF" ) ); }
	}
	$r = $http( 'products', array( 'columns' => array( 'views' ) ) ); $check( 'HTTP invalid columns rejected before attachment', 400 === wp_remote_retrieve_response_code( $r ) && ! wp_remote_retrieve_header( $r, 'content-disposition' ) );
	echo 'TOTAL PASS ', $passed, "\n";
} finally {
	foreach ( $sessions as $session ) { WP_Session_Tokens::get_instance( $session['id'] )->destroy( $session['token'] ); }
	foreach ( array_reverse( $orders ) as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
	Privacy::delete_visitors( $visitors );
	foreach ( array_reverse( $products ) as $id ) { $product = wc_get_product( $id ); if ( $product ) { $product->delete( true ); } }
	require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { wp_delete_user( $id ); }
	$_COOKIE = $original_cookie; wp_set_current_user( $original_user );
	remove_filter( 'pre_wp_mail', $no_mail ); remove_filter( 'pre_schedule_event', $no_cron ); foreach ( $as_hooks as $hook ) { remove_filter( $hook, $no_as ); }
	$left = 0; foreach ( $orders as $id ) { $left += wc_get_order( $id ) ? 1 : 0; } foreach ( $products as $id ) { $left += get_post( $id ) ? 1 : 0; } foreach ( $users as $id ) { $left += get_userdata( $id ) ? 1 : 0; } foreach ( $visitors as $id ) { $left += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) . ' WHERE id=%d', $id ) ); }
	if ( $left ) { throw new RuntimeException( 'FAIL owned025 fixture cleanup; inspect exact IDs privately.' ); }
	echo "PASS exact owned025 orders/products/users/visitors/session cleanup\n";
}
