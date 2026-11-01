<?php
/** Standalone production cleaner/canonical/ingest with an in-memory SQL spy; no WordPress or DB bootstrap. */
namespace PeptideCore\Tracking {
	final class Schema { public static function table( string $name ): string { return 'fixture_' . $name; } }
	final class Tracker {
		public static int $allocations = 0;
		public static int $visit_calls = 0;
		public static bool $fail_visit = false;
		public static function cookie_user_id(): int { return 0; }
		public static function is_staff( int $id ): bool { return false; }
		public static function current_vid(): string { return str_repeat( 'a', 32 ); }
		public static function issue_vid(): string { return self::current_vid(); }
		public static function visitor( string $vid, int $now ): array { global $wpdb; ++self::$allocations; if ( ! isset( $wpdb->visitors[10] ) ) { $wpdb->visitors[10] = array( 'id' => 10, 'user_id' => 0 ); } return $wpdb->visitors[10]; }
		public static function visit( int $v, int $now, string $path ): int { global $wpdb; ++self::$visit_calls; if ( self::$fail_visit ) { return 0; } $wpdb->visits[20] = $wpdb->visits[20] ?? array( 'id' => 20, 'visitor_id' => $v, 'events' => 0, 'pageviews' => 0, 'stage' => 0 ); return 20; }
		public static function link_user( int $v, int $user, int $visit ): void { throw new \RuntimeException( 'Anonymous fixture must not link a user' ); }
		public static function raise_stage( int $visit, int $stage ): void { global $wpdb; $wpdb->visits[$visit]['stage'] = max( $stage, $wpdb->visits[$visit]['stage'] ); }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' ); define( 'DAY_IN_SECONDS', 86400 ); define( 'ARRAY_A', 'ARRAY_A' );
	function sanitize_text_field( $v ) { return strip_tags( $v ); }
	function wp_parse_url( $v, $part = -1 ) { return parse_url( $v, $part ); }
	function wp_unslash( $v ) { return stripslashes( $v ); }
	function wp_hash( $v ) { return hash( 'sha256', $v ); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function get_transient( $key ) { return false; }
	function set_transient( $key, $value, $expiry ) { return true; }
	function __( $s, $domain = '' ) { return $s; }
	class WP_Error { private array $args; public function __construct( ...$args ) { $this->args = $args; } public function get_error_code() { return $this->args[0]; } public function get_error_data() { return $this->args[2]; } }

	final class Impression_SQL_Spy {
		public string $posts = 'fixture_posts';
		public string $last_error = '';
		public array $events = array();
		public array $visitors = array( 10 => array( 'id' => 10, 'user_id' => 0 ) );
		public array $visits = array( 20 => array( 'id' => 20, 'visitor_id' => 10, 'events' => 0, 'pageviews' => 0, 'stage' => 0 ) );
		public array $tabs = array();
		public array $native = array( 123 => array( 'ID' => 123, 'post_type' => 'product', 'post_status' => 'publish', 'post_parent' => 0 ), 124 => array( 'ID' => 124, 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => 123 ) );
		public array $counts = array( 'native' => 0, 'winners' => 0, 'inserts' => 0 );
		public bool $fail_native = false;
		public bool $fail_winners = false;
		public bool $fail_insert = false;
		public bool $lose_insert = false;
		public bool $fail_identity_reread = false;
		public bool $fail_visit_read = false;
		public int $identity_reads = 0;
		public $before_native = null;
		public $before_insert = null;
		private array $bindings = array();
		public function prepare( string $sql, ...$args ): string { if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; } $id = count( $this->bindings ); $this->bindings[$id] = $args; return '/*spy:' . $id . '*/' . $sql; }
		private function args( string $sql ): array { preg_match( '/\A\/\*spy:(\d+)\*\//', $sql, $m ); return isset( $m[1] ) ? $this->bindings[(int) $m[1]] : array(); }
		public function get_row( string $sql, $format ): ?array {
			$this->last_error = ''; $a = $this->args( $sql );
			if ( str_contains( $sql, 'fixture_visitors' ) ) { ++$this->identity_reads; if ( $this->identity_reads > 1 && $this->fail_identity_reread ) { $this->last_error = 'synthetic identity reread failure'; return null; } return $this->visitors[10] ?? null; }
			if ( str_contains( $sql, 'fixture_events' ) ) { return $this->events[$a[1]] ?? null; }
			throw new RuntimeException( 'Unexpected read shape in pure spy' );
		}
		public function get_results( string $sql, $format ): ?array {
			$this->last_error = ''; $a = $this->args( $sql );
			if ( str_contains( $sql, 'fixture_posts' ) ) {
				++$this->counts['native']; if ( $this->before_native ) { ( $this->before_native )( $this ); }
				if ( $this->fail_native ) { $this->last_error = 'synthetic native failure'; return null; }
				return array_values( $this->native );
			}
			if ( str_contains( $sql, 'fixture_events' ) ) {
				++$this->counts['winners']; if ( $this->fail_winners ) { $this->last_error = 'synthetic saved read failure'; return null; }
				$visitor = array_shift( $a ); $out = array();
				foreach ( $a as $uid ) { if ( isset( $this->events[$uid] ) && $this->events[$uid]['visitor_id'] === $visitor ) { $r = $this->events[$uid]; $r['matched_visitor_id'] = isset( $this->visitors[$visitor] ) ? $visitor : null; $r['matched_visit_id'] = isset( $this->visits[$r['visit_id']] ) && $this->visits[$r['visit_id']]['visitor_id'] === $visitor ? $r['visit_id'] : null; $out[] = $r; } }
				return $out;
			}
			throw new RuntimeException( 'Unexpected batch read shape in pure spy' );
		}
		public function get_var( string $sql ) {
			$this->last_error = ''; $a = $this->args( $sql );
			if ( str_contains( $sql, 'SELECT s.id FROM fixture_visits' ) ) { if ( $this->fail_visit_read ) { $this->last_error = 'synthetic visit read failure'; return null; } return isset( $this->visits[$a[0]], $this->visitors[$a[1]] ) && $this->visits[$a[0]]['visitor_id'] === $a[1] ? $a[0] : null; }
			throw new RuntimeException( 'Unexpected scalar read in pure spy' );
		}
		public function query( string $sql ) {
			$this->last_error = ''; $a = $this->args( $sql );
			if ( str_contains( $sql, 'INSERT IGNORE INTO fixture_events' ) ) {
				++$this->counts['inserts']; if ( $this->before_insert ) { ( $this->before_insert )( $this, $a ); }
				if ( $this->fail_insert ) { $this->last_error = 'synthetic insert failure'; return false; }
				if ( $this->lose_insert || isset( $this->events[$a[0]] ) ) { return 0; }
				preg_match( '/\((event_uid,.*?)\) VALUES \((.*?)\)/', $sql, $m );
				$columns = array_map( 'trim', explode( ',', $m[1] ) ); $tokens = array_map( 'trim', explode( ',', $m[2] ) ); $row = array(); $i = 0;
				foreach ( $columns as $j => $column ) { $token = $tokens[$j]; $row[$column] = str_starts_with( $token, '%' ) ? $a[$i++] : ( is_numeric( $token ) ? (int) $token : trim( $token, "'" ) ); }
				$this->events[$a[0]] = $row; return 1;
			}
			if ( str_contains( $sql, 'INSERT IGNORE INTO fixture_tabs' ) ) { $this->tabs[$a[2]] = $this->tabs[$a[2]] ?? array( 'visitor_id' => $a[0], 'visit_id' => $a[1], 'tab_id' => $a[2], 'last_signal_ms' => $a[3] ); return 1; }
			if ( str_contains( $sql, 'UPDATE fixture_tabs' ) ) { $this->tabs[$a[3]]['visit_id'] = $a[0]; $this->tabs[$a[3]]['last_signal_ms'] = max( $this->tabs[$a[3]]['last_signal_ms'], $a[1] ); return 1; }
			if ( str_contains( $sql, 'UPDATE fixture_visits' ) ) { $id = $a[count( $a ) - 1]; $this->visits[$id]['events'] += $a[0]; if ( count( $a ) === 4 ) { $this->visits[$id]['pageviews'] += $a[1]; } return 1; }
			if ( str_contains( $sql, 'UPDATE fixture_visitors' ) ) { return 1; }
			throw new RuntimeException( 'Unexpected mutation shape in pure spy' );
		}
	}

	require_once dirname( __DIR__ ) . '/includes/tracking/class-impressions.php';
	require_once dirname( __DIR__ ) . '/includes/tracking/class-attribution.php';
	require_once dirname( __DIR__ ) . '/includes/tracking/class-page-context.php';
	require_once dirname( __DIR__ ) . '/includes/tracking/class-ingest.php';
	use PeptideCore\Tracking\Impressions;
	use PeptideCore\Tracking\Ingest;
	use PeptideCore\Tracking\Tracker;
	$pass = 0;
	$check = static function( string $name, bool $ok ) use ( &$pass ): void { if ( ! $ok ) { fwrite( STDERR, 'FAIL ' . $name . "\n" ); exit( 1 ); } ++$pass; echo 'PASS ', $name, "\n"; };
	$wire = array( 'version' => 1, 'product_id' => 123, 'visible_fraction' => 0.4, 'visible_ms' => 500, 'context' => array( 'kind' => 'product', 'entity_id' => 999, 'template_id' => 7, 'module_id' => 'm-example', 'private' => 'discard' ), 'private' => 'discard' );
	$canonical = array( 'version' => 1, 'product_id' => 123, 'variation_id' => 0, 'visible_fraction' => 0.4, 'visible_ms' => 500, 'context' => array( 'kind' => 'product', 'entity_id' => 999, 'template_id' => 7, 'module_id' => 'm-example' ) );
	$check( 'literal canonical shape and extra removal', Impressions::clean( $wire ) === array( 'ok' => true, 'value' => $canonical ) );
	$check( 'normalization leaves caller input unchanged', ! isset( $wire['variation_id'] ) && isset( $wire['private'] ) );
	foreach ( array( null, false, true, '1', 1.0, array() ) as $i => $v ) { $d = $wire; $d['version'] = $v; $check( 'version strict type ' . $i, Impressions::clean( $d ) === array( 'ok' => false, 'code' => 'bad_product_impression' ) ); }
	foreach ( array( -1, 0, 2 ) as $v ) { $d = $wire; $d['version'] = $v; $check( 'unsupported integer version ' . $v, Impressions::clean( $d )['code'] === 'unsupported_impression_version' ); }
	foreach ( array( 'product_id', 'variation_id', 'entity_id', 'template_id' ) as $key ) {
		foreach ( array( null, true, false, '123', 1.0, -1, Impressions::MAX_ID + 1, PHP_INT_MAX ) as $i => $v ) { $d = $wire; if ( in_array( $key, array( 'entity_id', 'template_id' ), true ) ) { $d['context'][$key] = $v; } else { $d[$key] = $v; } $check( $key . ' strict safe boundary ' . $i, ! Impressions::clean( $d )['ok'] ); }
		$d = $wire; if ( in_array( $key, array( 'entity_id', 'template_id' ), true ) ) { $d['context'][$key] = Impressions::MAX_ID; } else { $d[$key] = Impressions::MAX_ID; } $check( $key . ' max safe integer accepted', Impressions::clean( $d )['ok'] );
	}
	foreach ( array( 0.399999, -1, 1.000001, INF, NAN, '0.4', true, null ) as $i => $v ) { $d = $wire; $d['visible_fraction'] = $v; $check( 'fraction invalid without round-up ' . $i, ! Impressions::clean( $d )['ok'] ); }
	foreach ( array( 499, 0, -1, 2147483648, 500.0, '500', true, null ) as $i => $v ) { $d = $wire; $d['visible_ms'] = $v; $check( 'duration strict boundary ' . $i, ! Impressions::clean( $d )['ok'] ); }
	foreach ( array( 0.4, 1, 1.0 ) as $v ) { $d = $wire; $d['visible_fraction'] = $v; $check( 'fraction valid ' . $v, is_float( Impressions::clean( $d )['value']['visible_fraction'] ) ); }
	$d = $wire; $d['visible_ms'] = 2147483647; $check( 'duration max accepted', Impressions::clean( $d )['ok'] );
	foreach ( Impressions::KINDS as $kind ) { $d = $wire; $d['context'] = array( 'kind' => $kind ); $check( 'explicit context kind ' . $kind, Impressions::clean( $d )['ok'] ); }
	foreach ( array( array(), null, array( 'kind' => 'Shop' ), array( 'kind' => 'unknown', 'entity_id' => 1 ), array( 'kind' => 'product', 'entity_id' => 0 ), array( 'kind' => 'product', 'module_id' => 'bad_value' ), array( 'kind' => 'product', 'module_id' => str_repeat( 'm', 41 ) ), array( 'kind' => 'product', 'module_id' => "m\n" ) ) as $i => $context ) { $d = $wire; $d['context'] = $context; $check( 'context map/support validation ' . $i, ! Impressions::clean( $d )['ok'] ); }
	$check( 'SQL safe IDs do not coerce booleans/floats/unsafe strings', Impressions::column_id( true ) === null && Impressions::column_id( 1.0 ) === null && Impressions::column_id( '9007199254740992' ) === null && Impressions::column_id( '9007199254740991' ) === Impressions::MAX_ID );
	foreach ( array( '//foreign.example/path', '/path?query=private', '/path#fragment', "/bad\x00path", "/bad\xffpath", 'https://foreign.example/path' ) as $i => $path ) { $check( 'saved path requires clean relative UTF8 snapshot ' . $i, Impressions::path( $path ) === null ); }
	$check( 'clean relative path preserves case and Unicode', Impressions::path( '/Case/页面/' ) === '/Case/页面/' );
	$incoming = array( 'index' => 0, 'event_id' => 'episode-0001', 'page_instance_id' => 'page-0001', 'tab_id' => 'tab-0001', 'seq' => 1, 'at' => 1000, 'type' => 'product_impression', 'page' => array( 'page_type' => 'product', 'path' => '/CrossSell/', 'product_id' => 777, 'product_name' => 'Ignored page name' ), 'data' => $canonical );
	$stored = array( 'event_uid' => 'episode-0001', 'visitor_id' => '10', 'visit_id' => '20', 'matched_visitor_id' => '10', 'matched_visit_id' => '20', 'type' => 'product_impression', 'source' => 'client', 'path' => '/CrossSell/', 'page_type' => 'product', 'product_id' => '123', 'variation_id' => '0', 'page_instance_id' => 'page-0001', 'tab_id' => 'tab-0001', 'seq' => '1', 'data' => json_encode( $canonical ) );
	$check( 'cross-sell parent differs from explicit page entity and page legacy extras', Impressions::equal( 10, $incoming, $stored ) );
	$check( 'shared saved observation accepts exact identity and both ID representations', Impressions::saved( $stored ) === $canonical );
	foreach ( array( 'source' => 'server', 'type' => 'product_view', 'product_id' => '999', 'variation_id' => '1', 'matched_visit_id' => null, 'matched_visitor_id' => null, 'visitor_id' => 0, 'product_id:unsafe' => '9007199254740992' ) as $key => $v ) { $d = $stored; $d[explode( ':', $key )[0]] = $v; $check( 'saved malformed/source/orphan/ID mismatch ' . $key, Impressions::saved( $d ) === null ); }
	foreach ( array( 'event_uid' => 'episode-other', 'visitor_id' => '11', 'page_type' => 'shop', 'path' => '/crosssell/', 'page_instance_id' => 'page-other', 'tab_id' => 'tab-other', 'seq' => '2', 'product_id' => '124', 'variation_id' => '124' ) as $key => $v ) { $d = $stored; $d[$key] = $v; $check( 'canonical frozen core conflict ' . $key, ! Impressions::equal( 10, $incoming, $d ) ); }
	$d = $stored; $d['occurred_ms'] = 999; $d['received_ms'] = 999; $check( 'canonical ignores corrected times only', Impressions::equal( 10, $incoming, $d ) );
	foreach ( array( 'visible_ms' => 501, 'visible_fraction' => 0.5, 'context' => array( 'kind' => 'unknown' ) ) as $key => $v ) { $d = $stored; $data = $canonical; $data[$key] = $v; $d['data'] = json_encode( $data ); $check( 'canonical frozen observation conflict ' . $key, ! Impressions::equal( 10, $incoming, $d ) ); }

	$reset = static function(): void { global $wpdb; $wpdb = new Impression_SQL_Spy(); Tracker::$allocations = 0; Tracker::$visit_calls = 0; Tracker::$fail_visit = false; };
	$event = static function( string $uid, array $data = array() ) use ( $wire ): array { return array( 'event_id' => $uid, 'page_instance_id' => 'page-0001', 'seq' => 1, 'type' => 'product_impression', 'occurred_at' => 1000, 'page' => array( 'page_type' => 'product', 'path' => '/CrossSell/', 'product_id' => 777, 'product_name' => 'Never store this' ), 'data' => $data ?: $wire ); };
	$run = static fn( array $events ) => Ingest::handle( array( 'events' => $events, 'tab_id' => 'tab-0001' ), 2000 );
	$reset(); $r = $run( array( $event( 'episode-0001' ) ) );
	$check( 'real ingest new episode ACK without invented extensions', $r === array( 'ack' => array( 'episode-0001' ), 'rejected' => array(), 'visitor_id' => 10, 'visit_id' => 20 ) );
	$check( 'new episode increments activity once without PV or stage', $wpdb->visits[20]['events'] === 1 && $wpdb->visits[20]['pageviews'] === 0 && $wpdb->visits[20]['stage'] === 0 );
	$check( 'stored data excludes page legacy names and product ID', json_decode( $wpdb->events['episode-0001']['data'], true ) === $canonical && $wpdb->events['episode-0001']['product_id'] === 123 );
	$wpdb->native = array(); $tab = $wpdb->tabs; Tracker::$visit_calls = 0; $r = $run( array( $event( 'episode-0001' ) ) );
	$check( 'retry after catalog deletion ACKs original without visit or tab signal changes', $r['ack'] === array( 'episode-0001' ) && Tracker::$visit_calls === 0 && $wpdb->tabs === $tab && $wpdb->visits[20]['events'] === 1 );
	$reset(); $d = $wire; $d['product_id'] = 999; unset( $wpdb->visitors[10] ); $r = $run( array( $event( 'episode-bad1', $d ) ) );
	$check( 'catalog-invalid impression-only does not allocate visitor or visit', $r['rejected'] === array( array( 'event_id' => 'episode-bad1', 'code' => 'bad_product_impression', 'retry' => false ) ) && Tracker::$allocations === 0 && Tracker::$visit_calls === 0 && ! $wpdb->events );
	$reset(); $d = $wire; $d['visible_ms'] = 499; $d['attribution'] = null; $d['page_context'] = null; $r = $run( array( $event( 'episode-bad2', $d ) ) );
	$check( 'strict invalid precedes both attempted sibling receipts', array_column( $r['extensions']['attribution']['results'], 'code' ) === array( 'core_rejected' ) && array_column( $r['extensions']['page_context']['results'], 'code' ) === array( 'core_rejected' ) && Tracker::$visit_calls === 0 );
	$reset(); $d = $wire; $d['attribution'] = null; $d['page_context'] = null; $a = $event( 'episode-0001', $d ); $b = $a; $b['data']['visible_ms'] = 600; $r = $run( array( $a, $a, $b ) );
	$check( 'same-batch same UID preserves every INDEX and immutable winner', $r['ack'] === array( 'episode-0001', 'episode-0001' ) && $r['rejected'] === array( array( 'event_id' => 'episode-0001', 'code' => 'duplicate_conflict', 'retry' => false ) ) && count( $r['extensions']['attribution']['results'] ) === 3 && count( $r['extensions']['page_context']['results'] ) === 3 && $wpdb->visits[20]['events'] === 1 );
	$check( 'valid newtype siblings are wrong_event_type in original attempt order', array_column( $r['extensions']['page_context']['results'], 'code' ) === array( 'wrong_event_type', 'wrong_event_type', 'wrong_event_type' ) );
	$reset(); $r = $run( array( $event( 'episode-0001' ), $event( 'episode-0002' ) ) ); $check( 'reentry with new UID is a second episode and one bounded catalog read', count( $r['ack'] ) === 2 && $wpdb->visits[20]['events'] === 2 && $wpdb->counts['native'] === 1 );
	$reset(); Tracker::$fail_visit = true; $r = $run( array( $event( 'episode-0001' ) ) ); $check( 'visit zero returns retry without orphan event', $r['rejected'][0] === array( 'event_id' => 'episode-0001', 'code' => 'server_error', 'retry' => true ) && ! $wpdb->events );
	$reset(); $wpdb->fail_visit_read = true; $r = $run( array( $event( 'episode-0001' ) ) ); $check( 'new fact visit identity lookup failure retries before any insert', $r['rejected'][0]['code'] === 'server_error' && $r['rejected'][0]['retry'] && ! $wpdb->events && ! $wpdb->tabs );
	$reset(); $wpdb->fail_insert = true; $r = $run( array( $event( 'episode-0001' ) ) ); $check( 'false INSERT is retry not ACK or bad input', $r['ack'] === array() && $r['rejected'][0]['code'] === 'server_error' && $r['rejected'][0]['retry'] );
	$reset(); $wpdb->lose_insert = true; $r = $run( array( $event( 'episode-0001' ) ) ); $check( 'lost INSERT with successful empty reread is retry', $r['ack'] === array() && $r['rejected'][0]['code'] === 'server_error' && ! $wpdb->tabs );
	$reset(); $wpdb->fail_native = true; $r = $run( array( $event( 'episode-0001' ) ) ); $check( 'native SQL error is retry without visit allocation', $r['rejected'][0]['retry'] && Tracker::$visit_calls === 0 );
	$reset(); $wpdb->fail_winners = true; $r = $run( array( $event( 'episode-0001' ) ) ); $check( 'saved SQL error is retry without insert or visit', $r['rejected'][0]['retry'] && $wpdb->counts['inserts'] === 0 && Tracker::$visit_calls === 0 );
	$reset(); $saved = $stored; $saved['visitor_id'] = 10; $saved['visit_id'] = 20; $wpdb->before_native = static function( $db ) use ( $saved ): void { $db->events['episode-0001'] = $saved; $db->native = array(); }; $r = $run( array( $event( 'episode-0001' ) ) );
	$check( 'native-invalid preterminal reread sees concurrent equal winner', $r['ack'] === array( 'episode-0001' ) && Tracker::$visit_calls === 0 && $wpdb->counts['winners'] === 2 );
	$reset(); $wpdb->before_insert = static function( $db, $args ) use ( $saved ): void { $db->events['episode-0001'] = $saved; }; $r = $run( array( $event( 'episode-0001' ), $event( 'episode-0001' ) ) );
	$check( 'lost INSERT winner reread coalesces same-batch attempts and avoids counters', $r['ack'] === array( 'episode-0001', 'episode-0001' ) && $wpdb->visits[20]['events'] === 0 && $wpdb->counts['winners'] === 2 );
	$reset(); $wpdb->events['episode-0001'] = $saved; unset( $wpdb->visits[20] ); $r = $run( array( $event( 'episode-0001' ) ) ); $check( 'saved original visit missing rejects without repair', $r['rejected'][0]['code'] === 'original_visit_missing' && Tracker::$visit_calls === 0 );
	$reset(); $wpdb->events['episode-0001'] = array_replace( $saved, array( 'type' => 'page_view' ) ); $r = $run( array( $event( 'episode-0001' ) ) ); $check( 'shared UID legacy winner is terminal duplicate conflict', $r['rejected'][0]['code'] === 'duplicate_conflict' && Tracker::$visit_calls === 0 );
	$reset(); $wpdb->tabs['tab-0001'] = array( 'visitor_id' => 10, 'visit_id' => 20, 'tab_id' => 'tab-0001', 'last_signal_ms' => 900, 'product_id' => 777, 'product_name' => 'Retained old name', 'visibility' => 'hidden', 'activity' => 'idle', 'video' => '{old video}', 'last_seq' => 99, 'last_type' => 'video_play', 'path' => '/Old/' ); $old = $wpdb->tabs['tab-0001']; $run( array( $event( 'episode-0001' ) ) ); $want = $old; $want['last_signal_ms'] = 2000;
	$check( 'signal-only preserves every existing product/video/visibility/ordering/name field', $wpdb->tabs['tab-0001'] === $want );
	foreach ( array( 'equal' => 'ack', 'conflict' => 'duplicate_conflict', 'missing_original' => 'original_visit_missing', 'winner_read_failure' => 'server_error', 'identity_read_failure' => 'server_error' ) as $case => $expected_code ) {
		$reset(); unset( $wpdb->visitors[10] );
		$wpdb->before_native = static function( $db ) use ( $saved, $case ): void {
			$db->visitors[10] = array( 'id' => 10, 'user_id' => 0 ); $db->events['episode-0001'] = $saved; $db->native = array();
			if ( $case === 'conflict' ) { $db->events['episode-0001']['path'] = '/Other/'; }
			if ( $case === 'missing_original' ) { unset( $db->visits[20] ); }
			if ( $case === 'winner_read_failure' ) { $db->fail_winners = true; }
			if ( $case === 'identity_read_failure' ) { $db->fail_identity_reread = true; }
		};
		$d = $wire; $d['attribution'] = null; $d['page_context'] = null; $r = $run( array( $event( 'episode-0001', $d ) ) );
		$retry = $expected_code === 'server_error';
		$check( 'concurrent first identity appeared during catalog lookup ' . $case, $r['ack'] === ( $expected_code === 'ack' ? array( 'episode-0001' ) : array() ) && $r['rejected'] === ( $expected_code === 'ack' ? array() : array( array( 'event_id' => 'episode-0001', 'code' => $expected_code, 'retry' => $retry ) ) ) );
		$sibling = array( 'version' => 1, 'results' => array( array( 'event_id' => 'episode-0001', 'status' => 'rejected', 'code' => 'wrong_event_type', 'retry' => false ) ) );
		$check( 'concurrent first identity exact ordered siblings ' . $case, $r['extensions'] === array( 'attribution' => $sibling, 'page_context' => $sibling ) );
		$check( 'concurrent first identity does not allocate/insert/touch signals ' . $case, Tracker::$allocations === 0 && Tracker::$visit_calls === 0 && $wpdb->counts['inserts'] === 0 && ! $wpdb->tabs && $wpdb->identity_reads === 2 );
	}
	require_once dirname( __DIR__ ) . '/includes/api/class-errors.php';
	require_once dirname( __DIR__ ) . '/includes/data/class-params.php';
	require_once dirname( __DIR__ ) . '/includes/data/class-range.php';
	require_once dirname( __DIR__ ) . '/includes/data/class-page-conversion.php';
	$report = \PeptideCore\Data\Page_Conversion::class;
	$check( 'report fourteen metrics and ten defaults are independently fixed', $report::METRICS === array( 'exposed_users', 'viewed_users', 'view_user_rate', 'cart_users', 'cart_user_rate', 'paid_users', 'paid_user_rate', 'impressions', 'views', 'view_rate', 'carts', 'cart_rate', 'paid_units', 'paid_rate' ) && $report::DEFAULTS === array( 'exposed_users', 'view_user_rate', 'viewed_users', 'cart_user_rate', 'cart_users', 'paid_users', 'paid_user_rate', 'impressions', 'views', 'view_rate' ) );
	$raw = array( 'exposed_users' => array( 10 => true, 11 => true ), 'viewed_users' => array( 10 => true ), 'cart_users' => array( 10 => true, 11 => true ), 'paid_users' => array( 10 => true ), 'impressions' => array( 'a' => true, 'b' => true ), 'views' => array( 1 => true, 2 => true, 3 => true ), 'carts' => array( 4 => true, 5 => true, 6 => true, 7 => true ), 'paid_units' => -1 );
	$expected = array( 'exposed_users' => 2, 'viewed_users' => 1, 'view_user_rate' => 50.0, 'cart_users' => 2, 'cart_user_rate' => 100.0, 'paid_users' => 1, 'paid_user_rate' => 50.0, 'impressions' => 2, 'views' => 3, 'view_rate' => 150.0, 'carts' => 4, 'cart_rate' => 200.0, 'paid_units' => -1, 'paid_rate' => -50.0 );
	$check( 'golden fourteen metrics preserve uncapped rates and negative refund units', $report::metrics( $raw ) === $expected );
	$zero = $report::metrics( array() );
	foreach ( $zero as $key => $value ) { $check( 'zero denominator metric ' . $key, str_ends_with( $key, '_rate' ) ? $value === null : $value === 0 ); }
	$prior = $expected; $prior['exposed_users'] = 1; $prior['view_rate'] = 50.0; $prior['views'] = 1;
	$change = $report::change( $expected, $prior ); $check( 'rate changes are percentage points while counts remain relative', $change['view_rate'] === 100.0 && $change['exposed_users'] === 100.0 && $change['views'] === 200.0 );
	$change = $report::change( $expected, $zero ); $check( 'zero prior counts and undefined prior rates have null change', count( array_filter( $change, static fn( $v ) => $v !== null ) ) === 0 );
	$rows = array(); $membership = array();
	$episode = array( 'id' => 1, 'visitor_id' => 10, 'visit_id' => 20, 'event_uid' => 'episode-one', 'occurred_ms' => 1000, 'path' => '/Case/' );
	$report::episode( $rows, $membership, $episode, $canonical, 'page' );
	$report::episode( $rows, $membership, $episode, $canonical, 'page' );
	$episode['id'] = 2; $episode['event_uid'] = 'episode-two'; $episode['occurred_ms'] = 1100; $episode['path'] = '/OtherCase/'; $report::episode( $rows, $membership, $episode, $canonical, 'page' );
	$key = array_key_first( $rows ); $check( 'entity combines paths while retry UID does not collapse reentry episodes', count( $rows ) === 1 && $report::metrics( $rows[$key]['raw'] )['impressions'] === 2 && $rows[$key]['path'] === '/Case/' );
	$check( 'membership stores earliest per row visitor visit impressed parent', $membership[20][10][123][$key] === 1000 );
	$check( 'page keys preserve exact path case and tuple boundaries', $report::key( 'page', 'search', null, '/Case/' ) !== $report::key( 'page', 'search', null, '/case/' ) && $report::key( 'page', 'product', 123, '/123/' ) !== $report::key( 'page', 'product', null, '/123/' ) );
	$check( 'default new report params', $report::params( array() ) === array( 'group' => 'type', 'sort' => 'exposed_users', 'order' => 'desc', 'page' => 1, 'per_page' => 20, 'type' => '', 'q' => '' ) );
	foreach ( array( array( 'group' => 'page' ), array( 'type' => 'shop' ), array( 'group' => 'page', 'type' => 'Shop' ), array( 'q' => null ), array( 'q' => true ), array( 'q' => array() ), array( 'q' => str_repeat( '中', 101 ) ), array( 'q' => "\xff" ), array( 'format' => 'csv' ), array( 'format' => '' ), array( 'format' => null ), array( 'page' => 0 ), array( 'page' => 1000000000 ), array( 'per_page' => 101 ), array( 'per_page' => true ), array( 'per_page' => 1.0 ), array( 'sort' => array() ), array( 'order' => false ), array( 'group' => null ) ) as $i => $params ) { $bad = $report::params( $params ); $check( 'strict new report parameter failure ' . $i, $bad instanceof WP_Error && $bad->get_error_code() === 'pc_invalid_param' && $bad->get_error_data()['status'] === 400 ); }
	$p = $report::params( array( 'group' => 'page', 'type' => 'unknown', 'q' => '  ' . str_repeat( '中', 100 ) . '  ', 'page' => 999999999, 'per_page' => 100, 'format' => 'json' ) );
	$check( 'unknown kind, max page, Unicode 100 and trimming are valid', is_array( $p ) && $p['type'] === 'unknown' && mb_strlen( $p['q'] ) === 100 && $p['page'] === 999999999 );
	$check( 'measurement is pushed observations with exact thresholds and cohort name', $report::measurement() === array( 'version' => 1, 'minimum_visible_fraction' => 0.4, 'minimum_visible_ms' => 500, 'attribution' => 'same_visit_parent_after_impression_within_period', 'source' => 'pushed_observations' ) );
	echo 'TOTAL ', $pass, " PASS, 0 FAIL\n";
}
