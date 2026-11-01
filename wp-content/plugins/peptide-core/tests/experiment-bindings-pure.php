<?php
/** Actual route/permission/handler against read-only in-memory native boundaries. */
namespace PeptideCore\Tracking {
 final class Schema { public static bool $available = true; public static int $checks = 0; public static function installed(): bool { ++self::$checks; return self::$available; } public static function table( $name ): string { return 'mem_' . $name; } }
}
namespace {
 define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
 define( 'MINUTE_IN_SECONDS', 60 );
 class WP_REST_Server { public const READABLE = 'GET'; }
 class WP_Error { public function __construct( public string $code, public string $message, public array $data ) {} }
 class WP_REST_Request { public int $reads = 0; public function __construct( private string $body ) {} public function get_body(): string { ++$this->reads; return $this->body; } }
 class WP_REST_Response { private array $headers = array(); public function __construct( private $data, private int $status = 200 ) {} public function get_data() { return $this->data; } public function get_status(): int { return $this->status; } public function header( $key, $value ): void { $this->headers[$key] = $value; } public function get_headers(): array { return $this->headers; } }
 $user = 5; $manager = true; $rate = array(); $rate_reads = 0; $rate_writes = array(); $hooks = array(); $routes = array();
 function __( $text, $domain = '' ) { return $text; }
 function get_current_user_id() { return $GLOBALS['user']; }
 function current_user_can( $cap ) { return $cap === 'manage_woocommerce' && $GLOBALS['manager']; }
 function rest_authorization_required_code() { return get_current_user_id() > 0 ? 403 : 401; }
 function is_admin(): bool { return false; }
 function get_transient( $key ) { ++$GLOBALS['rate_reads']; return $GLOBALS['rate'][$key] ?? false; }
 function set_transient( $key, $value, $ttl ) { $GLOBALS['rate'][$key] = $value; $GLOBALS['rate_writes'][] = array( $key, $ttl ); return true; }
 function add_action( $name, $callback ) { $GLOBALS['hooks'][$name][] = $callback; }
 function add_filter( $name, $callback ) { $GLOBALS['hooks'][$name][] = $callback; }
 function register_rest_route( $namespace, $route, $args ) { $GLOBALS['routes'][] = array( $namespace, $route, $args ); }
 final class BindingDB073 {
  public string $last_error = ''; public array $queries = array(); public array $events = array(); public array $visitors = array(); public array $visits = array(); public ?array $forced = null; public string $failure = ''; public int $writes = 0;
  public function prepare( $sql, ...$args ): string { return json_encode( array( $sql, $args ), JSON_THROW_ON_ERROR ); }
  public function get_results( $prepared, $format ) {
   list( $sql, $args ) = json_decode( $prepared, true ); $this->queries[] = array( 'sql' => $sql, 'args' => $args );
   if ( $this->failure === 'throw' ) { throw new RuntimeException( 'synthetic storage failure' ); }
   if ( $this->failure === 'sql' ) { $this->last_error = 'synthetic SQL failure'; return array(); }
   if ( $this->failure === 'null' ) { return null; }
   if ( null !== $this->forced ) { return $this->forced; }
   $out = array();
   foreach ( $this->events as $e ) {
    $v = $this->visitors[$e['visitor_id']] ?? null; $s = $this->visits[$e['visit_id']] ?? null;
    if ( ! $v || ! $s || $s['visitor_id'] !== $e['visitor_id'] || $e['event_uid'] !== $args[0] || $e['page_instance_id'] !== $args[1] || $e['path'] !== $args[2] || $e['type'] !== 'page_view' || $e['source'] !== 'client' || $e['is_test'] || $v['is_test'] || $s['is_test'] ) { continue; }
    $out[] = $e + array( 'matched_visitor_id' => $v['id'], 'matched_visit_id' => $s['id'], 'visit_visitor_id' => $s['visitor_id'], 'visitor_test' => $v['is_test'], 'visit_test' => $s['is_test'], 'visit_start' => $s['start_ms'] );
   }
   return array_slice( $out, 0, 2 );
  }
  public function query( ...$args ) { ++$this->writes; throw new RuntimeException( 'Forbidden data write.' ); }
  public function insert( ...$args ) { return $this->query( ...$args ); } public function update( ...$args ) { return $this->query( ...$args ); } public function delete( ...$args ) { return $this->query( ...$args ); }
 }
 require dirname( __DIR__ ) . '/includes/tracking/class-impressions.php';
 require dirname( __DIR__ ) . '/includes/tracking/class-experiment-observations.php';
 require dirname( __DIR__ ) . '/includes/tracking/class-experiment-bindings.php';
 require dirname( __DIR__ ) . '/includes/api/class-rest-controller.php';
 require dirname( __DIR__ ) . '/includes/data/class-dimension-details.php';
 require dirname( __DIR__ ) . '/includes/admin/class-data-pages.php';
 use PeptideCore\Tracking\Experiment_Bindings as Bindings073; use PeptideCore\Tracking\Experiment_Observations as Observations073; use PeptideCore\Tracking\Schema as Schema073;
 $passed = 0; $check = static function ( $name, $ok ) use ( &$passed ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } ++$passed; };
 $status = static fn( $response ) => $response instanceof WP_Error ? $response->data['status'] : $response->get_status();
 $reference = array( 'protocol' => 1, 'page_view' => array( 'event_id' => 'pv-reference-0001', 'page_instance_id' => 'page-instance-0001', 'path' => '/landing/' ) );
 $request = static fn( $body = null ) => new WP_REST_Request( json_encode( $body ?? $reference, JSON_PRESERVE_ZERO_FRACTION ) );
 $reset = static function () use ( &$wpdb, &$rate, &$rate_reads, &$rate_writes ): void {
  $wpdb = new BindingDB073(); $wpdb->visitors = array( 1 => array( 'id' => 1, 'is_test' => 0 ) ); $wpdb->visits = array( 11 => array( 'id' => 11, 'visitor_id' => 1, 'is_test' => 0, 'start_ms' => '10000' ) );
  $wpdb->events = array( array( 'visitor_id' => 1, 'visit_id' => 11, 'event_uid' => 'pv-reference-0001', 'page_instance_id' => 'page-instance-0001', 'path' => '/landing/', 'occurred_ms' => '11000', 'type' => 'page_view', 'source' => 'client', 'is_test' => 0 ) );
  $rate = array(); $rate_reads = 0; $rate_writes = array(); Schema073::$available = true; Schema073::$checks = 0;
 };
 $reset();
 $check( 'ordinary REST starts without admin-only hooks', ! is_admin() && $hooks === array() && $routes === array() );
 PeptideCore\Admin\Data_Pages::register_rest();
 $check( 'actual DataPages defers routes until rest_api_init without admin hooks', count( $hooks['rest_api_init'] ) === 1 && $routes === array() && ! isset( $hooks['admin_init'] ) && ! isset( $hooks['wp_privacy_personal_data_exporters'] ) );
 foreach ( $hooks['rest_api_init'] as $callback ) { call_user_func( $callback ); }
 $bindings = array_values( array_filter( $routes, static fn( $registered ) => $registered[0] === 'peptide/v1' && $registered[1] === '/data/experiments/binding' ) );
 $check( 'nonadmin callback registers binding exactly once among real Data routes', count( $bindings ) === 1 && count( $routes ) > 1 && ! isset( $hooks['admin_init'] ) && ! isset( $hooks['wp_privacy_personal_data_exporters'] ) );
 list( $namespace, $route, $config ) = $bindings[0];
 $registered_count = count( $routes ); Observations073::register_hooks();
 $check( 'separate admin privacy hooks remain and cannot duplicate REST authority', count( $hooks['rest_api_init'] ) === 1 && count( $routes ) === $registered_count && isset( $hooks['wp_privacy_personal_data_exporters'], $hooks['admin_init'] ) && ! method_exists( Bindings073::class, 'register_hooks' ) );
 $check( 'native POST route reuses exact058 permission', $namespace === 'peptide/v1' && $route === '/data/experiments/binding' && $config['methods'] === 'POST' && $config['permission_callback'] === array( Observations073::class, 'permission' ) && $config['callback'] === array( Bindings073::class, 'receive' ) );
 $boot = file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' ); $rest_at = strpos( $boot, 'Admin\\Data_Pages::register_rest();' ); $admin_at = strpos( $boot, 'if ( is_admin() ) {' );
 $check( 'native Plugin invokes Data REST registration before its admin-only branch', $rest_at !== false && $admin_at !== false && $rest_at < $admin_at );
 $user = 0; $manager = false; $check( 'registered permission callback returns native401 without storage', $status( call_user_func( $config['permission_callback'] ) ) === 401 && ! $wpdb->queries && $rate_reads === 0 );
 foreach ( array( array( 0, false, 401 ), array( 9, false, 403 ) ) as list( $user, $manager, $expected ) ) { $r = $request(); $check( 'permission precedes body/rate/storage', $status( Bindings073::receive( $r ) ) === $expected && $r->reads === 0 && $rate_reads === 0 && Schema073::$checks === 0 && ! $wpdb->queries ); }
 $user = 5; $manager = true;
 $invalid = array( array(), array( 'protocol' => '1', 'page_view' => $reference['page_view'] ), array( 'protocol' => 1.0, 'page_view' => $reference['page_view'] ), $reference + array( 'visitor_id' => 1 ) );
 foreach ( array( array( 'event_id', 'under_score-0001' ), array( 'page_instance_id', 'page_under_score-0001' ), array( 'event_id', 'short' ), array( 'event_id', str_repeat( 'a', 65 ) ), array( 'event_id', 'bad id 001' ), array( 'event_id', true ), array( 'page_instance_id', array() ), array( 'path', 'https://outside.test/a' ), array( 'path', '//outside.test/a' ), array( 'path', '/a?private=1' ), array( 'path', '/a#private' ), array( 'path', '' ), array( 'path', '/a<>b' ), array( 'visitor_id', 1 ) ) as list( $key, $value ) ) { $bad = $reference; $bad['page_view'][$key] = $value; $invalid[] = $bad; }
 foreach ( $invalid as $bad ) { $check( 'strict shape rejects before rate/storage', $status( Bindings073::receive( $request( $bad ) ) ) === 400 && $rate_reads === 0 && ! $wpdb->queries ); }
 foreach ( array( 'bad json', str_repeat( 'x', 8192 ) ) as $raw ) { $check( 'invalid JSON including exact limit is400 before rate', $status( Bindings073::receive( new WP_REST_Request( $raw ) ) ) === 400 && $rate_reads === 0 ); }
 $check( 'oversize8193 is413 before rate', $status( Bindings073::receive( new WP_REST_Request( str_repeat( 'x', 8193 ) ) ) ) === 413 && $rate_reads === 0 );
 $response = Bindings073::receive( $request() );
 $check( 'one native saved client PV returns minimal typed server binding', $response instanceof WP_REST_Response && $response->get_data() === array( 'protocol' => 1, 'binding' => array( 'visitor_id' => 1, 'visit_id' => 11, 'page_view_event_id' => 'pv-reference-0001', 'page_instance_id' => 'page-instance-0001', 'path' => '/landing/', 'page_view_occurred_ms' => 11000 ) ) );
 $check( 'successful read is no-store and transient bounded300 seconds', $response->get_headers()['Cache-Control'] === 'no-store' && $rate_writes === array( array( 'pc_exp_binding_rate_5', 300 ) ) );
 $sql = $wpdb->queries[0]; $check( 'exact query is prepared and joins original owner with LIMIT2', $sql['args'] === array( 'pv-reference-0001', 'page-instance-0001', '/landing/' ) && str_contains( $sql['sql'], 'BINARY e.event_uid=%s' ) && str_contains( $sql['sql'], 'BINARY e.page_instance_id=%s' ) && str_contains( $sql['sql'], 'BINARY e.path=%s' ) && str_contains( $sql['sql'], 'INNER JOIN mem_visits s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id' ) && str_ends_with( $sql['sql'], 'LIMIT 2' ) );
 foreach ( array( 'event_id', 'page_instance_id' ) as $uid_key ) { $reset(); $native = $reference; $native['page_view'][$uid_key] = '-' . substr( $native['page_view'][$uid_key], 1 ); $wpdb->events[0][$uid_key === 'event_id' ? 'event_uid' : 'page_instance_id'] = $native['page_view'][$uid_key]; $check( 'native leading hyphen accepted ' . $uid_key, $status( Bindings073::receive( $request( $native ) ) ) === 200 ); }
 foreach ( array( 'unknown', 'path', 'case', 'page', 'visitor-deleted', 'visit-deleted', 'wrong-owner', 'event-test', 'visitor-test', 'visit-test', 'server', 'nonPV' ) as $case ) {
  $reset();
  switch ( $case ) { case 'unknown': $wpdb->events = array(); break; case 'path': $wpdb->events[0]['path'] = '/other/'; break; case 'case': $wpdb->events[0]['event_uid'] = 'PV-reference-0001'; break; case 'page': $wpdb->events[0]['page_instance_id'] = 'page-instance-0002'; break; case 'visitor-deleted': $wpdb->visitors = array(); break; case 'visit-deleted': $wpdb->visits = array(); break; case 'wrong-owner': $wpdb->visits[11]['visitor_id'] = 2; break; case 'event-test': $wpdb->events[0]['is_test'] = 1; break; case 'visitor-test': $wpdb->visitors[1]['is_test'] = 1; break; case 'visit-test': $wpdb->visits[11]['is_test'] = 1; break; case 'server': $wpdb->events[0]['source'] = 'server'; break; case 'nonPV': $wpdb->events[0]['type'] = 'product_view'; break; }
  $check( 'no binding for ' . $case, $status( Bindings073::receive( $request() ) ) === 404 && $wpdb->writes === 0 );
 }
 $reset(); $wpdb->visitors[2] = array( 'id' => 2, 'is_test' => 0 ); $wpdb->visits[22] = array( 'id' => 22, 'visitor_id' => 2, 'is_test' => 0, 'start_ms' => 10000 ); $copy = $wpdb->events[0]; $copy['visitor_id'] = 2; $copy['visit_id'] = 22; $wpdb->events[] = $copy;
 $check( 'global opaque collision409 never chooses newest owner', $status( Bindings073::receive( $request() ) ) === 409 && $wpdb->writes === 0 );
 $reset(); Bindings073::receive( $request() ); $prepared = $wpdb->prepare( 'unused', array() ); $decorated = $wpdb->events[0] + array( 'matched_visitor_id' => 1, 'matched_visit_id' => 11, 'visit_visitor_id' => 1, 'visitor_test' => 0, 'visit_test' => 0, 'visit_start' => 10000 );
 foreach ( array( array( 'visitor_id', '01' ), array( 'visit_id', true ), array( 'occurred_ms', '9007199254740992' ), array( 'occurred_ms', '9999' ), array( 'matched_visitor_id', 2 ), array( 'matched_visit_id', 22 ), array( 'visit_visitor_id', 2 ), array( 'is_test', '00' ), array( 'path', '/bad?x' ), array( 'page_instance_id', 'different-page-0001' ) ) as list( $key, $value ) ) { $row = $decorated; $row[$key] = $value; $wpdb->forced = array( $row ); $check( 'malformed stored binding503 ' . $key, $status( Bindings073::receive( $request() ) ) === 503 ); }
 foreach ( array( 'sql', 'null', 'throw' ) as $failure ) { $reset(); $wpdb->failure = $failure; $check( 'storage failure503 ' . $failure, $status( Bindings073::receive( $request() ) ) === 503 && $wpdb->writes === 0 ); }
 $reset(); Schema073::$available = false; $check( 'missing schema503 no fallback/create', $status( Bindings073::receive( $request() ) ) === 503 && ! $wpdb->queries && $wpdb->writes === 0 );
 $reset(); for ( $i = 0; $i < 300; ++$i ) { if ( $status( Bindings073::receive( $request() ) ) !== 200 ) { throw new RuntimeException( 'Rate limit rejected accepted read.' ); } }
 $queries = count( $wpdb->queries ); $check( 'request301 returns429 without another SQL read or rate write', $status( Bindings073::receive( $request() ) ) === 429 && count( $wpdb->queries ) === $queries && count( $rate_writes ) === 300 );
 $rate['pc_exp_binding_rate_5']['until'] = 1; $check( 'expired window resets count and accepts fresh read', $status( Bindings073::receive( $request() ) ) === 200 && $rate['pc_exp_binding_rate_5']['count'] === 1 );
 $check( 'all lookups leave native visits/visitors/events unchanged', $wpdb->writes === 0 && $wpdb->events[0] === array( 'visitor_id' => 1, 'visit_id' => 11, 'event_uid' => 'pv-reference-0001', 'page_instance_id' => 'page-instance-0001', 'path' => '/landing/', 'occurred_ms' => '11000', 'type' => 'page_view', 'source' => 'client', 'is_test' => 0 ) );
 echo 'PASS ', $passed, " experiment binding route assertions, 0 FAIL\n";
}
