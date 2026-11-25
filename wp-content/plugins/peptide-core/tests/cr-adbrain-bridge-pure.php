<?php
/**
 * Ad Brain 双桥接口测试：内存 SQLite 模拟 $wpdb，假的 REST 请求 / WooCommerce 订单，不需要 WordPress、MySQL 或网络。
 * 覆盖：未配密钥 503、签名正确 / 篡改 / 过期 / Nonce 重放 401、错误格式 {ok:false}、options、issue 幂等重放不增号、
 * 属性按中文名匹配和回退、落地页不在列表时回退首页、图片写媒体库、update-landing、relink-ad 联动访问表、
 * delete（没用过的真删、编号作废；有数据的默认归档或 409；重复调用幂等）、pull 的结构（区间汇总、15 维画像、按天明细、脱敏订单不含明文个人信息）。
 * 运行：php -n cr-adbrain-bridge-pure.php（需要 pdo_sqlite）
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MINUTE_IN_SECONDS', 60 );

	final class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public $data = null ) {}
		public function get_error_message(): string { return $this->message; }
		public function get_error_code(): string { return $this->code; }
		public function get_error_data() { return $this->data; }
	}

	final class WP_REST_Response {
		public function __construct( public $data = null, public int $status = 200 ) {}
		public function get_status(): int { return $this->status; }
		public function get_data() { return $this->data; }
		public function set_data( $d ): void { $this->data = $d; }
	}

	final class WP_REST_Request {
		public array $headers = array();
		public function __construct( public string $method, public string $route, public string $body = '', public array $query = array() ) {}
		public function get_header( string $k ) { return $this->headers[ strtolower( str_replace( '-', '_', $k ) ) ] ?? null; }
		public function get_method(): string { return $this->method; }
		public function get_route(): string { return $this->route; }
		public function get_body(): string { return $this->body; }
		public function get_json_params() { $d = json_decode( $this->body, true ); return is_array( $d ) ? $d : array(); }
		public function get_query_params(): array { return $this->query; }
	}

	/** 只实现用到的 $wpdb 方法，SQL 交给 SQLite。 */
	final class Fake_Wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public string $last_error = '';
		private bool $quiet = false;
		public function __construct( public PDO $pdo ) {}
		public function suppress_errors( bool $s = true ): bool { $o = $this->quiet; $this->quiet = $s; return $o; }
		public function prepare( string $sql, ...$args ): string {
			$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
			$i    = 0;
			return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) { $v = $args[ $i++ ]; return '%d' === $m[0] ? (string) (int) $v : $this->pdo->quote( (string) $v ); }, $sql );
		}
		private function run( string $sql, array $params = array() ) {
			$sql = (string) preg_replace( "/^SHOW TABLES LIKE ('[^']*')$/", "SELECT name FROM sqlite_master WHERE type = 'table' AND name = $1", $sql );
			try {
				$st = $this->pdo->prepare( $sql );
				$st->execute( $params );
				$this->last_error = '';
				return $st;
			} catch ( PDOException $e ) {
				$this->last_error = $e->getMessage();
				if ( ! $this->quiet && ! str_contains( $e->getMessage(), 'UNIQUE' ) ) {
					throw $e;
				}
				return false;
			}
		}
		public function query( string $sql ) { $st = $this->run( $sql ); return false === $st ? false : $st->rowCount(); }
		public function get_results( string $sql, $o = null ): array { $st = $this->run( $sql ); return $st ? $st->fetchAll( PDO::FETCH_ASSOC ) : array(); }
		public function get_row( string $sql, $o = null ) { $r = $this->get_results( $sql ); return $r[0] ?? null; }
		public function get_col( string $sql ): array { $st = $this->run( $sql ); return $st ? $st->fetchAll( PDO::FETCH_COLUMN, 0 ) : array(); }
		public function get_var( string $sql ) { $st = $this->run( $sql ); $v = $st ? $st->fetchColumn() : null; return false === $v ? null : $v; }
		public function insert( string $table, array $data ) {
			$cols = array_keys( $data );
			$st   = $this->run( "INSERT INTO {$table} (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_fill( 0, count( $cols ), '?' ) ) . ')', array_values( $data ) );
			if ( false === $st ) { return false; }
			$this->insert_id = (int) $this->pdo->lastInsertId();
			return 1;
		}
		public function replace( string $table, array $data ) { return $this->insert( $table, $data ); }
		public function update( string $table, array $data, array $where ) {
			$set = implode( ',', array_map( static fn( $c ) => "{$c} = ?", array_keys( $data ) ) );
			$wh  = implode( ' AND ', array_map( static fn( $c ) => "{$c} = ?", array_keys( $where ) ) );
			$st  = $this->run( "UPDATE {$table} SET {$set} WHERE {$wh}", array_merge( array_values( $data ), array_values( $where ) ) );
			return false === $st ? false : $st->rowCount();
		}
		public function delete( string $table, array $where ) {
			$wh = implode( ' AND ', array_map( static fn( $c ) => "{$c} = ?", array_keys( $where ) ) );
			$st = $this->run( "DELETE FROM {$table} WHERE {$wh}", array_values( $where ) );
			return false === $st ? false : $st->rowCount();
		}
	}

	// ---------- WordPress / WooCommerce 替身 ----------
	$GLOBALS['opts']       = array();
	$GLOBALS['transients'] = array();
	$GLOBALS['actions']    = array();
	$GLOBALS['uploads']    = array();
	$GLOBALS['orders']     = array();
	function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
	function add_option( $k, $v = '', $dep = '', $a = null ) { if ( array_key_exists( $k, $GLOBALS['opts'] ) ) { return false; } $GLOBALS['opts'][ $k ] = $v; return true; }
	function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
	function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
	function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
	function get_current_user_id() { return 0; }
	function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
	function do_action( $hook, ...$args ) { $GLOBALS['actions'][] = array( $hook, $args ); }
	function add_action( ...$a ) { return true; }
	function add_filter( ...$a ) { return true; }
	function home_url( $p = '' ) { return 'https://shop.test' . $p; }
	function wp_timezone_string() { return 'America/New_York'; }
	function is_wp_error( $x ) { return $x instanceof WP_Error; }
	function get_terms( $a ) { return array( (object) array( 'name' => 'Best Sellers', 'slug' => 'best' ) ); }
	function get_term_link( $t ) { return 'https://shop.test/?vc_catalog_category=' . $t->slug; }
	function wc_get_products( $a ) { return array( new class() { public function get_name() { return 'BPC-157'; } public function get_permalink() { return 'https://shop.test/product/bpc-157/'; } } ); }
	function sanitize_file_name( $n ) { return preg_replace( '/[^A-Za-z0-9._-]/', '', $n ); }
	function wp_upload_bits( $name, $dep, $bytes ) { $GLOBALS['uploads'][] = array( $name, strlen( $bytes ) ); return array( 'file' => '/uploads/' . $name, 'url' => 'https://shop.test/uploads/' . $name, 'error' => false ); }
	function wp_insert_attachment( $a, $file ) { return 500 + count( $GLOBALS['uploads'] ); }
	function wp_generate_attachment_metadata( $id, $file ) { return array( 'file' => $file ); }
	function wp_update_attachment_metadata( $id, $m ) { return true; }
	function wp_get_attachment_image_url( $id, $size ) { return 'https://shop.test/uploads/thumb-' . $id . '.jpg'; }
	function wc_get_order( $id ) { foreach ( $GLOBALS['orders'] as $o ) { if ( $o->get_id() === (int) $id ) { return $o; } } return false; }
	function wc_get_orders( $args ) {
		$GLOBALS['last_order_query'] = $args;
		[ $a, $b ] = array_map( 'intval', explode( '...', $args['date_created'] ) );
		return array_values( array_filter( $GLOBALS['orders'], static fn( $o ) => in_array( $o->get_status(), $args['status'], true ) && $o->ts >= $a && $o->ts <= $b ) );
	}

	class WC_Order {
		public function __construct( public int $id, public int $ts, public array $d ) {}
		public function get_id() { return $this->id; }
		public function get_date_created() { return new DateTimeImmutable( '@' . $this->ts ); }
		public function __call( $m, $a ) { return $this->d[ substr( $m, 4 ) ] ?? ''; }
		public function get_items( $t ) { return $this->d['items'] ?? array(); }
	}
	final class Fake_Item {
		public function __construct( public string $sku, public int $pid, public string $name, public int $qty, public string $total ) {}
		public function get_product() { $sku = $this->sku; return new class( $sku ) { public function __construct( public string $s ) {} public function get_sku() { return $this->s; } }; }
		public function get_product_id() { return $this->pid; }
		public function get_name() { return $this->name; }
		public function get_quantity() { return $this->qty; }
		public function get_total() { return $this->total; }
	}
}

namespace PeptideCore\Tracking {
	final class Schema {
		public static function installed(): bool { return true; }
		public static function table( string $n ): string { return 'wp_pc_track_' . $n; }
	}
	final class Visit_Classifications {
		public static function for_visits( array $owners, int $now ): array { return array(); }
	}
	final class Tracker {
		public static function now_ms(): int { return time() * 1000; }
	}
}

namespace {
	$inc = dirname( __DIR__ ) . '/includes/';
	foreach ( array( 'contract', 'schema', 'creatives', 'intent-tiers', 'audience', 'board', 'daily-report', 'ads-panel', 'audience-ai', 'adbrain-bridge' ) as $f ) {
		require $inc . 'creatives/class-' . $f . '.php';
	}
	require $inc . 'admin/class-onboarding.php';
	require $inc . 'class-staff-buyer.php';
	require $inc . 'class-page-cache.php';

	use PeptideCore\Creatives\AdBrain_Bridge as B;
	use PeptideCore\Creatives\Ads_Panel;
	use PeptideCore\Creatives\Creatives as Cr;
	use PeptideCore\Creatives\Schema;

	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }

	$pdo = new PDO( 'sqlite::memory:' );
	$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$pdo->exec( "CREATE TABLE wp_pc_ad_creatives (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, attachment_id INTEGER NOT NULL DEFAULT 0, video_id INTEGER NOT NULL DEFAULT 0, attrs TEXT, custom TEXT, lang TEXT NOT NULL DEFAULT 'en', note TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'active', created_by INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0)" );
	$pdo->exec( "CREATE TABLE wp_pc_ad_ads (ad_id TEXT PRIMARY KEY, code TEXT NOT NULL DEFAULT '', ad_name TEXT NOT NULL DEFAULT '', adset_id TEXT NOT NULL DEFAULT '', campaign_id TEXT NOT NULL DEFAULT '', first_seen_ms INTEGER NOT NULL DEFAULT 0, last_seen_ms INTEGER NOT NULL DEFAULT 0)" );
	$pdo->exec( "CREATE TABLE wp_pc_ad_daily_spend (ad_id TEXT NOT NULL, day TEXT NOT NULL, spend_cents INTEGER NOT NULL DEFAULT 0, impressions INTEGER NOT NULL DEFAULT 0, link_clicks INTEGER NOT NULL DEFAULT 0, ad_name TEXT NOT NULL DEFAULT '', import_id INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (ad_id, day))" );
	$pdo->exec( "CREATE TABLE wp_pc_ad_spend_imports (id INTEGER PRIMARY KEY AUTOINCREMENT)" );
	$pdo->exec( "CREATE TABLE wp_pc_ad_attribute_vocab (attr TEXT NOT NULL, value TEXT NOT NULL, label TEXT NOT NULL DEFAULT '', required INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, position INTEGER NOT NULL DEFAULT 0, ver INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (attr, value))" );
	$vcols = 'id INTEGER PRIMARY KEY AUTOINCREMENT, visitor_id INTEGER NOT NULL, start_ms INTEGER NOT NULL DEFAULT 0, last_ms INTEGER NOT NULL DEFAULT 0, utm_source TEXT, utm_medium TEXT, utm_term TEXT, utm_content TEXT, placement TEXT, ad_name TEXT, lp_ver TEXT, landing_path TEXT, creative_code TEXT NOT NULL DEFAULT \'\', source_class TEXT, ip_type TEXT, timezone TEXT, language TEXT, geo_region TEXT, geo_country TEXT, in_app TEXT, device TEXT, pageviews INTEGER DEFAULT 0, stage INTEGER DEFAULT 0, order_id INTEGER DEFAULT 0, ua TEXT, os TEXT, os_version TEXT, device_model TEXT, screen TEXT, isp TEXT, geo_city TEXT';
	$pdo->exec( "CREATE TABLE wp_pc_track_visits ({$vcols})" );
	$pdo->exec( 'CREATE TABLE wp_pc_track_visitors (id INTEGER PRIMARY KEY, first_visit_id INTEGER DEFAULT 0, last_paid_visit_id INTEGER DEFAULT 0)' );
	$pdo->exec( 'CREATE TABLE wp_pc_track_events (id INTEGER PRIMARY KEY AUTOINCREMENT, visit_id INTEGER, type TEXT, page_type TEXT, path TEXT, product_id INTEGER, data TEXT, occurred_ms INTEGER)' );
	$GLOBALS['wpdb'] = $wpdb = new Fake_Wpdb( $pdo );
	update_option( Schema::OPTION, Schema::VERSION );
	Schema::seed_vocab();

	// ---------- 请求工具 ----------
	$secret = str_repeat( 'k', 40 );
	$nonce_n = 0;
	$routes = array(
		'GET /peptide/v1/adbrain/options'                    => 'rest_options',
		'POST /peptide/v1/adbrain/creatives/issue'           => 'rest_issue',
		'POST /peptide/v1/adbrain/creatives/update-landing'  => 'rest_update_landing',
		'POST /peptide/v1/adbrain/creatives/relink-ad'       => 'rest_relink_ad',
		'POST /peptide/v1/adbrain/creatives/delete'          => 'rest_delete',
		'GET /peptide/v1/adbrain/pull'                       => 'rest_pull',
	);
	/** 发一个请求（先过 permission，再进接口；出错时经 format_error 改写）。 */
	$call = function ( string $method, string $route, $body = '', array $query = array(), array $over = array() ) use ( &$secret, &$nonce_n, $routes ) {
		$raw   = is_array( $body ) ? json_encode( $body ) : (string) $body;
		$req   = new WP_REST_Request( $method, $route, $raw, $query );
		$time  = (string) ( $over['time'] ?? time() );
		$nonce = (string) ( $over['nonce'] ?? sprintf( 'nonce%016d', ++$nonce_n ) );
		$sign  = $over['sign'] ?? B::sign( '' !== $secret ? $secret : 'x', $method, $route, $time, $nonce, $raw );
		$req->headers = array( 'x_adbrain_site' => $over['site'] ?? 's0001', 'x_adbrain_time' => $time, 'x_adbrain_nonce' => $nonce, 'x_adbrain_sign' => $sign );
		$ok = B::permission( $req );
		if ( $ok instanceof WP_Error ) {
			$res = new WP_REST_Response( array( 'code' => $ok->code, 'message' => $ok->message, 'data' => $ok->data ), (int) $ok->data['status'] );
		} else {
			$res = B::{$routes[ $method . ' ' . $route ]}( $req );
			if ( $res instanceof WP_Error ) {
				$res = new WP_REST_Response( array( 'code' => $res->code, 'message' => $res->message, 'data' => $res->data ), (int) $res->data['status'] );
			}
		}
		$res = B::format_error( $res, null, $req );
		return array( $res->get_status(), $res->get_data() );
	};

	// ---------- 签名原文 ----------
	check( 'empty body sha256 constant', B::EMPTY_SHA === hash( 'sha256', '' ) );
	check( 'canonical: 5 lines joined by \\n', "GET\n/peptide/v1/adbrain/pull\n1700000000\nabcdefghijklmnop\n" . B::EMPTY_SHA === B::canonical( 'get', '/peptide/v1/adbrain/pull', '1700000000', 'abcdefghijklmnop', '' ) );
	check( 'sign = lowercase hex hmac', B::sign( 's', 'POST', '/r', '1', 'n', '{}' ) === hash_hmac( 'sha256', "POST\n/r\n1\nn\n" . hash( 'sha256', '{}' ), 's' ) );
	check( 'is_ours: adbrain routes only', B::is_ours( '/peptide/v1/adbrain/pull' ) && ! B::is_ours( '/peptide/v1/adbrainx' ) && ! B::is_ours( '/peptide/v1/hq/x' ) );

	// ---------- 没配密钥 ----------
	check( 'no secret configured -> secret() empty', '' === B::secret() );
	[ $st, $d ] = $call( 'GET', '/peptide/v1/adbrain/options' );
	check( 'no secret -> 503 bridge_not_configured', 503 === $st && false === $d['ok'] && 'bridge_not_configured' === $d['code'] );
	update_option( B::SECRET_OPT, 'short-secret' );
	[ $st, $d ] = $call( 'GET', '/peptide/v1/adbrain/options' );
	check( 'secret shorter than 32 chars counts as not configured', 503 === $st && 'bridge_not_configured' === $d['code'] );
	update_option( B::SECRET_OPT, '  ' . $secret . '  ' );
	check( 'option secret trimmed', $secret === B::secret() );

	// ---------- 验签 ----------
	[ $st, $d ] = $call( 'GET', '/peptide/v1/adbrain/options' );
	check( 'valid signature -> 200', 200 === $st && true === $d['ok'] );
	[ $st, $d ] = $call( 'GET', '/peptide/v1/adbrain/options', '', array(), array( 'sign' => str_repeat( '0', 64 ) ) );
	check( 'tampered signature -> 401 bad_sign', 401 === $st && 'bad_sign' === $d['code'] && false === $d['ok'] );
	[ $st, $d ] = $call( 'GET', '/peptide/v1/adbrain/options', '', array(), array( 'sign' => strtoupper( B::sign( $secret, 'GET', '/peptide/v1/adbrain/options', (string) time(), 'nonceUPPERCASE00001', '' ) ), 'nonce' => 'nonceUPPERCASE00001' ) );
	check( 'uppercase hex signature accepted', 200 === $st );
	$req  = new WP_REST_Request( 'POST', '/peptide/v1/adbrain/creatives/relink-ad', '{"ad_id":"1","code":"C0001"}' );
	$t    = (string) time();
	$sig  = B::sign( $secret, 'POST', $req->route, $t, 'nonce-body-tamper-1', $req->body );
	$req->body = '{"ad_id":"2","code":"C0001"}';
	$req->headers = array( 'x_adbrain_site' => 's1', 'x_adbrain_time' => $t, 'x_adbrain_nonce' => 'nonce-body-tamper-1', 'x_adbrain_sign' => $sig );
	$r = B::permission( $req );
	check( 'body changed after signing -> 401', $r instanceof WP_Error && 'bad_sign' === $r->code && 401 === $r->data['status'] );
	[ $st, $d ] = $call( 'GET', '/peptide/v1/adbrain/pull', '', array(), array() );
	check( 'signature bound to route (pull ok with own route)', 200 === $st );
	$req = new WP_REST_Request( 'GET', '/peptide/v1/adbrain/pull' );
	$req->headers = array( 'x_adbrain_site' => 's1', 'x_adbrain_time' => $t, 'x_adbrain_nonce' => 'nonce-route-swap-01', 'x_adbrain_sign' => B::sign( $secret, 'GET', '/peptide/v1/adbrain/options', $t, 'nonce-route-swap-01', '' ) );
	check( 'signature for another route -> 401', B::permission( $req ) instanceof WP_Error );
	$req->method = 'POST';
	$req->route  = '/peptide/v1/adbrain/options';
	check( 'method is part of the signature', B::permission( $req ) instanceof WP_Error );
	[ $st, $d ] = $call( 'GET', '/peptide/v1/adbrain/options', '', array(), array( 'time' => time() - 301 ) );
	check( 'timestamp 301s old -> 401 bad_time', 401 === $st && 'bad_time' === $d['code'] );
	[ $st, $d ] = $call( 'GET', '/peptide/v1/adbrain/options', '', array(), array( 'time' => time() + 301 ) );
	check( 'timestamp 301s ahead -> 401', 401 === $st && 'bad_time' === $d['code'] );
	[ $st ] = $call( 'GET', '/peptide/v1/adbrain/options', '', array(), array( 'time' => time() - 290 ) );
	check( 'timestamp within 300s -> 200', 200 === $st );
	[ $st, $d ] = $call( 'GET', '/peptide/v1/adbrain/options', '', array(), array( 'time' => 'abc' ) );
	check( 'non-numeric timestamp -> 401', 401 === $st && 'bad_time' === $d['code'] );
	[ $st, $d ] = $call( 'GET', '/peptide/v1/adbrain/options', '', array(), array( 'nonce' => 'short' ) );
	check( 'nonce under 16 chars -> 401 bad_nonce', 401 === $st && 'bad_nonce' === $d['code'] );
	[ $st, $d ] = $call( 'GET', '/peptide/v1/adbrain/options', '', array(), array( 'nonce' => str_repeat( 'a', 65 ) ) );
	check( 'nonce over 64 chars -> 401', 401 === $st && 'bad_nonce' === $d['code'] );
	[ $st, $d ] = $call( 'GET', '/peptide/v1/adbrain/options', '', array(), array( 'site' => '' ) );
	check( 'missing site header -> 401', 401 === $st && 'bad_site' === $d['code'] );
	[ $st ] = $call( 'GET', '/peptide/v1/adbrain/options', '', array(), array( 'nonce' => 'replay-nonce-00000001' ) );
	[ $st2, $d2 ] = $call( 'GET', '/peptide/v1/adbrain/options', '', array(), array( 'nonce' => 'replay-nonce-00000001' ) );
	check( 'same nonce twice -> first 200, second 401 replay', 200 === $st && 401 === $st2 && 'replay' === $d2['code'] );
	$before = count( $GLOBALS['transients'] );
	$call( 'GET', '/peptide/v1/adbrain/options', '', array(), array( 'sign' => str_repeat( '1', 64 ), 'nonce' => 'unsigned-nonce-00001' ) );
	check( 'nonce not burned by a request with a bad signature', count( $GLOBALS['transients'] ) === $before );
	$ok_resp = new WP_REST_Response( array( 'code' => 'x' ), 200 );
	check( 'format_error leaves successful responses alone', $ok_resp === B::format_error( $ok_resp, null, new WP_REST_Request( 'GET', '/peptide/v1/adbrain/options' ) ) && array( 'code' => 'x' ) === $ok_resp->data );
	$other = new WP_REST_Response( array( 'code' => 'nope', 'message' => 'm' ), 403 );
	B::format_error( $other, null, new WP_REST_Request( 'GET', '/peptide/v1/data' ) );
	check( 'format_error leaves other routes alone', ! isset( $other->data['ok'] ) );

	// ---------- 不被缓存 / 投手放行拦截 ----------
	check( 'page cache never caches REST (path is not the home page)', ! \PeptideCore\Page_Cache::eligible( array( 'method' => 'GET', 'path' => '/wp-json/peptide/v1/adbrain/pull', 'home_path' => '/', 'query' => '', 'front' => false ) ) );
	check( 'page cache never caches ?rest_route= (query is not tracking only)', ! \PeptideCore\Page_Cache::eligible( array( 'method' => 'GET', 'path' => '/', 'home_path' => '/', 'query' => 'rest_route=/peptide/v1/adbrain/pull', 'front' => true ) ) );

	// ---------- 1. options ----------
	[ $st, $d ] = $call( 'GET', '/peptide/v1/adbrain/options' );
	check( 'options: timezone / home / template', 'America/New_York' === $d['store_timezone'] && 'https://shop.test/' === $d['home_url'] && \PeptideCore\Creatives\Contract::URL_TEMPLATE === $d['url_template'] );
	check( 'options: vocab rows with required flag', count( $d['vocab'] ) >= 14 && array( 'attr' => 'hook', 'value' => 'lab', 'label' => '检测报告', 'required' => true ) === $d['vocab'][0] );
	check( 'options: lang is optional', false === array_values( array_filter( $d['vocab'], static fn( $v ) => 'lang' === $v['attr'] ) )[0]['required'] );
	check( 'options: landing pages home / collection / product', array( '', '专辑页', '商品页' ) === array_column( $d['landing_pages'], 'group' ) && 'https://shop.test/' === $d['landing_pages'][0]['url'] );

	// ---------- resolve_attrs ----------
	$vocab = Schema::vocab();
	$r     = B::resolve_attrs( array( 'hook' => 'LAB', 'fmt' => '单图', 'angle' => 'nonsense' ), $vocab );
	check( 'resolve: value case-insensitive, label match, fallback', array( 'hook' => 'lab', 'fmt' => 'img', 'angle' => 'purity' ) === $r['attrs'] );
	check( 'resolve: notes explain label + fallback', array( array( 'attr' => 'fmt', 'given' => '单图', 'used' => 'img', 'how' => 'label' ), array( 'attr' => 'angle', 'given' => 'nonsense', 'used' => 'purity', 'how' => 'fallback' ) ) === $r['notes'] );
	check( 'resolve: lang omitted stays omitted', ! isset( $r['attrs']['lang'] ) );
	check( 'resolve: missing required uses first value', 'lab' === B::resolve_attrs( array(), $vocab )['attrs']['hook'] );
	check( 'resolve: lang by label', 'es' === B::resolve_attrs( array( 'lang' => '西班牙文' ), $vocab )['attrs']['lang'] );

	// ---------- idem map ----------
	$m = array();
	for ( $i = 0; $i < 505; $i++ ) {
		$m = B::idem_put( $m, 'k' . $i, array( 'code' => 'C' . $i ) );
	}
	check( 'idem map keeps the latest 500', 500 === count( $m ) && ! isset( $m['k4'] ) && isset( $m['k5'], $m['k504'] ) );
	check( 'idem_get', array( 'code' => 'C9' ) === B::idem_get( $m, 'k9' ) && null === B::idem_get( $m, 'nope' ) );
	check( 'idem key rules', B::valid_idem_key( 'abc-123:x' ) && ! B::valid_idem_key( '' ) && ! B::valid_idem_key( str_repeat( 'a', 129 ) ) && ! B::valid_idem_key( "a b" ) );

	// ---------- 2. issue ----------
	$png = base64_encode( (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ) );
	$body = array(
		'idempotency_key' => 'job-1',
		'attrs'           => array( 'hook' => 'lab', 'fmt' => 'img', 'angle' => 'purity', 'lang' => 'en' ),
		'landing_url'     => 'https://shop.test/?vc_catalog_category=best',
		'lp_ver'          => 'cat_best',
		'note'            => 'from ad brain',
		'image_base64'    => 'data:image/png;base64,' . $png,
		'image_filename'  => 'my creative.png',
	);
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/issue', $body );
	check( 'issue: 200 and first code', 200 === $st && true === $d['ok'] && 'C0001' === $d['code'] && false === $d['idempotent_replay'] );
	check( 'issue: tracking url has landing, cr, template, lp_ver', 'https://shop.test/?vc_catalog_category=best&cr=C0001&' . \PeptideCore\Creatives\Contract::URL_TEMPLATE . '&lp_ver=cat_best' === $d['tracking_url'] );
	check( 'issue: suggested ad name', 'C0001_hook-lab_fmt-img_angle-purity_lang-en' === $d['suggested_ad_name'] );
	check( 'issue: landing label', '专辑页：Best Sellers' === $d['landing_label'] );
	check( 'issue: image saved to media library', 'https://shop.test/uploads/thumb-501.jpg' === $d['thumb_url'] && 'mycreative.png' === $GLOBALS['uploads'][0][0] );
	check( 'issue: landing + lp_ver saved', 'https://shop.test/?vc_catalog_category=best' === Ads_Panel::landing_of( 'C0001' ) && 'cat_best' === Ads_Panel::lp_ver_of( 'C0001' ) );
	$c1 = Cr::get( 'C0001' );
	check( 'issue: creative row (attachment, note, attrs)', 501 === $c1['attachment_id'] && 'from ad brain' === $c1['note'] && 'img' === $c1['attrs']['fmt'] );
	[ $st, $d2 ] = $call( 'POST', '/peptide/v1/adbrain/creatives/issue', $body );
	check( 'issue replay: same result, flagged, no new code', 200 === $st && true === $d2['idempotent_replay'] && 'C0001' === $d2['code'] && $d['tracking_url'] === $d2['tracking_url'] && 1 === count( Cr::codes() ) );
	check( 'issue replay: no second upload', 1 === count( $GLOBALS['uploads'] ) );
	[ $st, $d3 ] = $call( 'POST', '/peptide/v1/adbrain/creatives/issue', array( 'idempotency_key' => 'job-2', 'attrs' => array( 'hook' => '价格', 'fmt' => 'vid', 'angle' => '??' ), 'landing_url' => 'https://evil.test/', 'lp_ver' => 'bad ver!', 'image_base64' => base64_encode( 'not an image' ) ) );
	check( 'issue: next code C0002', 'C0002' === $d3['code'] );
	check( 'issue: bad landing -> home + warning', 'https://shop.test/' === $d3['landing_url'] && in_array( 'landing_url_not_allowed', $d3['warnings'], true ) );
	check( 'issue: bad lp_ver ignored + warning', '' === $d3['lp_ver'] && in_array( 'lp_ver_invalid', $d3['warnings'], true ) && ! str_contains( $d3['tracking_url'], 'lp_ver' ) );
	check( 'issue: non-image bytes not saved, code still issued', 0 === Cr::get( 'C0002' )['attachment_id'] && in_array( 'image_not_saved', $d3['warnings'], true ) && '' === $d3['thumb_url'] );
	check( 'issue: label + fallback noted', 'price' === $d3['attrs']['hook'] && 'purity' === $d3['attrs']['angle'] && 2 === count( $d3['attr_notes'] ) );
	check( 'issue: home landing not stored as an override', ! isset( get_option( Ads_Panel::LANDINGS, array() )['C0002'] ) );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/issue', array( 'attrs' => array() ) );
	check( 'issue: missing idempotency_key -> 400', 400 === $st && 'bad_idempotency_key' === $d['code'] && false === $d['ok'] );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/issue', array( 'idempotency_key' => 'job-3', 'attrs' => 'x' ) );
	check( 'issue: attrs not an object -> 400', 400 === $st && 'bad_attrs' === $d['code'] );
	add_option( 'pc_adbrain_lock_' . md5( 'job-4' ), time() );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/issue', array( 'idempotency_key' => 'job-4', 'attrs' => array() ) );
	check( 'issue: same key in flight -> 409, no code', 409 === $st && 'in_progress' === $d['code'] && 2 === count( Cr::codes() ) );
	update_option( 'pc_adbrain_lock_' . md5( 'job-4' ), time() - 500 );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/issue', array( 'idempotency_key' => 'job-4', 'attrs' => array(), 'want_code' => 'C0042' ) );
	check( 'issue: stale lock cleared; want_code honoured', 200 === $st && 'C0042' === $d['code'] && ! isset( $GLOBALS['opts'][ 'pc_adbrain_lock_' . md5( 'job-4' ) ] ) );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/issue', array( 'idempotency_key' => 'job-5', 'attrs' => array(), 'want_code' => 'C0042' ) );
	check( 'issue: want_code taken -> next free code + warning', 'C0043' === $d['code'] && in_array( 'want_code_taken', $d['warnings'], true ) );

	// from_ad_id 补登记
	$ad_x = '120210000000009001';
	$wpdb->insert( 'wp_pc_ad_ads', array( 'ad_id' => $ad_x, 'code' => '', 'ad_name' => 'untitled', 'first_seen_ms' => 1 ) );
	$wpdb->insert( 'wp_pc_track_visits', array( 'visitor_id' => 9, 'utm_content' => $ad_x, 'creative_code' => '' ) );
	$GLOBALS['actions'] = array();
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/issue', array( 'idempotency_key' => 'job-6', 'attrs' => array(), 'from_ad_id' => $ad_x ) );
	check( 'issue + from_ad_id: ad and visits moved to new code', true === $d['relinked'] && $d['code'] === Cr::ad( $ad_x )['code'] && $d['code'] === $wpdb->get_var( "SELECT creative_code FROM wp_pc_track_visits WHERE utm_content = '{$ad_x}'" ) );
	check( 'issue + from_ad_id: relinked action fired', array( 'pc_creatives_relinked', array( $ad_x, $d['code'] ) ) === $GLOBALS['actions'][0] );
	$issued = $d['code'];

	// ---------- 3. update-landing ----------
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/update-landing', array( 'code' => 'C0002', 'landing_url' => 'https://shop.test/product/bpc-157/', 'lp_ver' => 'pdp_v2' ) );
	check( 'update-landing: saved and new link', 200 === $st && 'https://shop.test/product/bpc-157/?cr=C0002&' . \PeptideCore\Creatives\Contract::URL_TEMPLATE . '&lp_ver=pdp_v2' === $d['tracking_url'] && '商品页：BPC-157' === $d['landing_label'] );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/update-landing', array( 'code' => 'C0002', 'lp_ver' => '' ) );
	check( 'update-landing: only lp_ver cleared, landing kept', 'https://shop.test/product/bpc-157/' === $d['landing_url'] && '' === $d['lp_ver'] && ! str_contains( $d['tracking_url'], 'lp_ver' ) );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/update-landing', array( 'code' => 'C0002', 'landing_url' => 'https://evil.test/' ) );
	check( 'update-landing: foreign url -> 400, unchanged', 400 === $st && 'landing_url_not_allowed' === $d['code'] && 'https://shop.test/product/bpc-157/' === Ads_Panel::landing_of( 'C0002' ) );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/update-landing', array( 'code' => 'C0002', 'lp_ver' => 'bad ver' ) );
	check( 'update-landing: bad lp_ver -> 400', 400 === $st && 'lp_ver_invalid' === $d['code'] );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/update-landing', array( 'code' => 'C0999' ) );
	check( 'update-landing: unknown code -> 404', 404 === $st && 'creative_not_found' === $d['code'] );

	// ---------- 4. relink-ad ----------
	$ad_y = '120210000000009002';
	$wpdb->insert( 'wp_pc_ad_ads', array( 'ad_id' => $ad_y, 'code' => 'C0777', 'ad_name' => 'C0777_x', 'first_seen_ms' => 2 ) );
	$wpdb->insert( 'wp_pc_track_visits', array( 'visitor_id' => 10, 'utm_content' => $ad_y, 'creative_code' => '' ) );
	check( 'relink: ad starts unregistered', in_array( $ad_y, array_column( Cr::unregistered_ads(), 'ad_id' ), true ) );
	$GLOBALS['actions'] = array();
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/relink-ad', array( 'ad_id' => $ad_y, 'code' => 'C0001' ) );
	check( 'relink: 200 body', 200 === $st && array( 'ok' => true, 'ad_id' => $ad_y, 'code' => 'C0001' ) === $d );
	check( 'relink: ads table + visits + action', 'C0001' === Cr::ad( $ad_y )['code'] && 'C0001' === $wpdb->get_var( "SELECT creative_code FROM wp_pc_track_visits WHERE utm_content = '{$ad_y}'" ) && 'pc_creatives_relinked' === $GLOBALS['actions'][0][0] );
	check( 'relink: no longer unregistered', ! in_array( $ad_y, array_column( Cr::unregistered_ads(), 'ad_id' ), true ) );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/relink-ad', array( 'ad_id' => 'abc', 'code' => 'C0001' ) );
	check( 'relink: bad ad id -> 400', 400 === $st && 'bad_ad_id' === $d['code'] );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/relink-ad', array( 'ad_id' => $ad_y, 'code' => 'C0999' ) );
	check( 'relink: unknown code -> 404, ad unchanged', 404 === $st && 'C0001' === Cr::ad( $ad_y )['code'] );

	// ---------- 4b. delete ----------
	check( 'delete_plan: unused -> delete', array( 'action' => 'delete', 'status' => 200, 'result' => 'deleted' ) === B::delete_plan( true, false, 'active', '', 'archive' ) );
	check( 'delete_plan: in use -> archive / 409 / already archived', 'archive' === B::delete_plan( true, false, 'active', 'x', 'archive' )['action'] && 409 === B::delete_plan( true, false, 'active', 'x', 'fail' )['status'] && 'already_archived' === B::delete_plan( true, false, 'archived', 'x', 'archive' )['result'] );
	check( 'delete_plan: missing / retired / bad option', 404 === B::delete_plan( false, false, '', '', 'archive' )['status'] && 'already_deleted' === B::delete_plan( false, true, '', '', 'archive' )['result'] && 'bad_if_in_use' === B::delete_plan( true, false, 'active', '', 'purge' )['error'] );
	check( 'in_use_reason', 'used_by_ads' === B::in_use_reason( '已经有广告用了这个素材' ) && 'has_data' === B::in_use_reason( '已经有访客或花费数据' ) && '' === B::in_use_reason( '' ) );
	$fresh = Cr::create( array( 'hook' => 'lab' ), 0, 'to delete' );
	Ads_Panel::set_landing( $fresh, home_url( '/' ) );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/delete', array( 'code' => strtolower( $fresh ) ) );
	check( 'delete: unused -> 200 deleted', 200 === $st && array( 'ok' => true, 'code' => $fresh, 'result' => 'deleted', 'reason' => '' ) === $d );
	check( 'delete: row gone, code retired, landing cleared', null === Cr::get( $fresh ) && in_array( $fresh, Cr::retired_codes(), true ) && ! isset( get_option( Ads_Panel::LANDINGS, array() )[ $fresh ] ) );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/delete', array( 'code' => $fresh ) );
	check( 'delete: repeat -> 200 already_deleted', 200 === $st && 'already_deleted' === $d['result'] );
	$next = Cr::create( array( 'hook' => 'lab' ), 0, 'after delete' );
	check( 'delete: deleted code is never reissued', $next !== $fresh && (int) substr( $next, 1 ) > (int) substr( $fresh, 1 ) );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/delete', array( 'code' => 'C0001', 'if_in_use' => 'fail' ) );
	check( 'delete: in use + fail -> 409 with reason, untouched', 409 === $st && false === $d['ok'] && 'creative_in_use' === $d['code'] && 'has_data' === $d['reason'] && 'active' === Cr::get( 'C0001' )['status'] );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/delete', array( 'code' => 'C0001' ) );
	check( 'delete: in use -> archived, data kept', 200 === $st && 'archived' === $d['result'] && 'has_data' === $d['reason'] && 'archived' === Cr::get( 'C0001' )['status'] && 'C0001' === Cr::ad( $ad_y )['code'] );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/delete', array( 'code' => 'C0001' ) );
	check( 'delete: repeat -> already_archived', 200 === $st && 'already_archived' === $d['result'] );
	$wa = B::with_archived( array( 'C0002' => array( 'attrs' => array(), 'custom' => array(), 'thumb' => '' ) ), array( 'C0001' => Cr::get( 'C0001' ), 'C0002' => Cr::get( 'C0002' ) ) + ( Cr::get( $next ) ? array( $next => Cr::get( $next ) ) : array() ) );
	check( 'with_archived: archived added with board shape, active-only list kept, other active not added', array( 'C0002', 'C0001' ) === array_keys( $wa ) && array( 'attrs', 'custom', 'thumb' ) === array_keys( $wa['C0001'] ) );
	Cr::set_status( 'C0001', Cr::STATUS_ACTIVE ); // 后面的 pull 测试照旧用 C0001。
	$by_ad = Cr::create( array( 'hook' => 'lab' ), 0, 'ad only' );
	$wpdb->insert( 'wp_pc_ad_ads', array( 'ad_id' => '120210000000009999', 'code' => $by_ad ) );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/delete', array( 'code' => $by_ad ) );
	check( 'delete: linked ad but no visits -> archived, reason used_by_ads', 200 === $st && 'archived' === $d['result'] && 'used_by_ads' === $d['reason'] && null !== Cr::get( $by_ad ) );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/delete', array( 'code' => 'C0999' ) );
	check( 'delete: unknown -> 404 {ok:false}', 404 === $st && 'creative_not_found' === $d['code'] && false === $d['ok'] );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/delete', array( 'code' => 'abc' ) );
	check( 'delete: malformed code -> 404', 404 === $st );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/delete', array( 'code' => $next, 'if_in_use' => 'purge' ) );
	check( 'delete: bad if_in_use -> 400, nothing deleted', 400 === $st && 'bad_if_in_use' === $d['code'] && null !== Cr::get( $next ) );
	[ $st, $d ] = $call( 'POST', '/peptide/v1/adbrain/creatives/delete', array( 'code' => $next ), array(), array( 'sign' => str_repeat( '0', 64 ) ) );
	check( 'delete: bad signature -> 401, nothing deleted', 401 === $st && null !== Cr::get( $next ) );

	// ---------- 5. pull：纯函数 ----------
	check( 'pull_range defaults: last 7 days, mode last', array( 'from' => '2026-11-15', 'to' => '2026-11-21', 'mode' => 'last' ) === B::pull_range( array(), '2026-11-21' ) );
	check( 'pull_range: 62 days ok, 63 too long', ! isset( B::pull_range( array( 'from' => '2026-09-01', 'to' => '2026-11-01' ), 'x' )['error'] ) && 'range_too_long' === B::pull_range( array( 'from' => '2026-08-31', 'to' => '2026-11-01' ), 'x' )['error'] );
	check( 'pull_range: bad inputs', 'bad_range' === B::pull_range( array( 'from' => '2026-11-10', 'to' => '2026-11-01' ), 'x' )['error'] && 'bad_range' === B::pull_range( array( 'from' => '2026-02-30' ), '2026-11-01' )['error'] && 'bad_mode' === B::pull_range( array( 'mode' => 'avg' ), '2026-11-01' )['error'] );
	check( 'days()', array( '2026-10-31', '2026-11-01', '2026-11-02' ) === B::days( '2026-10-31', '2026-11-02' ) );
	check( 'customer_hash: email lowercased, spaces removed, 24 hex', substr( hash( 'sha256', 'jane@x.com' ), 0, 24 ) === B::customer_hash( ' Jane@X.com ', '' ) );
	check( 'customer_hash: phone last 10 digits', substr( hash( 'sha256', '2125550100' ), 0, 24 ) === B::customer_hash( '', '+1 (212) 555-0100' ) && '' === B::customer_hash( '', '' ) );
	check( 'test_hint', B::test_hint( 'qa+TEST@x.com', '' ) && B::test_hint( '', 'Test User' ) && ! B::test_hint( 'a@b.c', 'Ann' ) );
	check( 'postcode_prefix', '009' === B::postcode_prefix( '00901-1234' ) && 'SW1' === B::postcode_prefix( 'sw1a 1aa' ) );
	$paid_v = array( array( 'start_ms' => 100, 'utm_content' => '111111', 'creative_code' => 'C0001' ), array( 'start_ms' => 300, 'utm_content' => '222222', 'creative_code' => 'C0002' ), array( 'start_ms' => 900, 'utm_content' => '333333', 'creative_code' => 'C0001' ) );
	$crs    = array( 'C0001' => array(), 'C0002' => array() );
	check( 'order_attribution last = latest paid visit before the order', array( 'code' => 'C0002', 'ad_id' => '222222' ) === B::order_attribution( $paid_v, 500, 'last', array(), $crs ) );
	check( 'order_attribution first', array( 'code' => 'C0001', 'ad_id' => '111111' ) === B::order_attribution( $paid_v, 500, 'first', array(), $crs ) );
	check( 'order_attribution unknown code -> UNREG; none -> empty', 'UNREG' === B::order_attribution( $paid_v, 500, 'last', array(), array() )['code'] && array( 'code' => '', 'ad_id' => '' ) === B::order_attribution( $paid_v, 50, 'last', array(), $crs ) );

	// ---------- 5. pull：整条接口 ----------
	$tz   = 'America/New_York';
	$day  = \PeptideCore\Creatives\Board::day( time() * 1000, $tz );
	$prev = gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) - 86400 );
	$d0   = \PeptideCore\Creatives\Board::range_ms( $prev, $prev, $tz )[0] + 3600000; // 昨天店铺时区 1 点
	$d1   = \PeptideCore\Creatives\Board::range_ms( $day, $day, $tz )[0] + 3600000;
	$ad1  = '120210000000001111';
	$wpdb->insert( 'wp_pc_ad_ads', array( 'ad_id' => $ad1, 'code' => 'C0001', 'ad_name' => 'C0001_hook-lab_fmt-img_angle-purity', 'adset_id' => '2200', 'campaign_id' => '3300', 'first_seen_ms' => $d0 ) );
	$v = static fn( array $x ) => $x + array( 'last_ms' => $x['start_ms'] + 60000, 'source_class' => 'paid', 'utm_source' => 'fb', 'utm_medium' => 'paid', 'utm_term' => '2200', 'utm_content' => $ad1, 'placement' => 'Facebook_Mobile_Feed', 'ad_name' => '', 'lp_ver' => 'cat_best', 'landing_path' => '/', 'creative_code' => 'C0001', 'ip_type' => 'residential', 'timezone' => $tz, 'language' => 'en-US', 'geo_region' => 'New York', 'geo_country' => 'US', 'in_app' => 'fb', 'device' => 'mobile', 'pageviews' => 3, 'stage' => 0, 'order_id' => 0, 'ua' => 'Mozilla/5.0 (iPhone)', 'os' => 'iOS', 'os_version' => '18.1', 'device_model' => 'iPhone', 'screen' => '390x844', 'isp' => 'Verizon', 'geo_city' => 'Brooklyn' );
	foreach ( array(
		$v( array( 'visitor_id' => 201, 'start_ms' => $d0 ) ),
		$v( array( 'visitor_id' => 201, 'start_ms' => $d0 + 600000, 'stage' => 5, 'order_id' => 7001 ) ),
		$v( array( 'visitor_id' => 202, 'start_ms' => $d1, 'creative_code' => 'C0002', 'utm_content' => '120210000000002222', 'stage' => 2 ) ),
		$v( array( 'visitor_id' => 203, 'start_ms' => $d1 + 1000, 'source_class' => 'fb_untagged', 'utm_content' => '', 'creative_code' => '' ) ),
	) as $row ) {
		$wpdb->insert( 'wp_pc_track_visits', $row );
	}
	foreach ( array( 201, 202, 203 ) as $vid ) {
		$wpdb->insert( 'wp_pc_track_visitors', array( 'id' => $vid ) );
	}
	$wpdb->insert( 'wp_pc_ad_daily_spend', array( 'ad_id' => $ad1, 'day' => $prev, 'spend_cents' => 4000, 'impressions' => 3000, 'link_clicks' => 30, 'ad_name' => 'C0001_hook-lab_fmt-img_angle-purity' ) );
	$wpdb->insert( 'wp_pc_ad_daily_spend', array( 'ad_id' => '120210000000003333', 'day' => $day, 'spend_cents' => 900, 'impressions' => 800, 'link_clicks' => 25, 'ad_name' => 'C0003_x' ) );
	$pii = array( 'billing_email' => 'Jane.Doe@Example.com', 'billing_first_name' => 'Jane', 'billing_last_name' => 'Doe', 'billing_phone' => '+1 212 555 0100', 'shipping_country' => 'PR', 'shipping_state' => 'PR', 'shipping_postcode' => '00901', 'shipping_address_1' => '1 Calle Sol' );
	$GLOBALS['orders'] = array(
		new WC_Order( 7001, intdiv( $d0, 1000 ) + 700, $pii + array( 'status' => 'processing', 'currency' => 'USD', 'subtotal' => '159.00', 'discount_total' => '10.00', 'shipping_total' => '5.00', 'total' => '154.00', 'total_refunded' => '0', 'payment_method' => 'panapay', 'items' => array( new Fake_Item( 'BPC-5', 11, 'BPC-157 5mg', 2, '149.00' ) ) ) ),
		new WC_Order( 7002, intdiv( $d1, 1000 ) + 50, array( 'billing_email' => 'test@shop.test', 'billing_first_name' => 'QA', 'status' => 'failed', 'currency' => 'USD', 'total' => '20.00', 'billing_country' => 'US', 'billing_postcode' => '10001' ) ),
		new WC_Order( 7003, intdiv( $d1, 1000 ) + 60, array( 'status' => 'cancelled', 'total' => '9.00' ) ),
		new WC_Order( 7004, intdiv( $d0, 1000 ) - 86400 * 30, array( 'status' => 'completed', 'total' => '9.00' ) ),
	);
	[ $st, $p ] = $call( 'GET', '/peptide/v1/adbrain/pull', '', array( 'from' => $prev, 'to' => $day, 'mode' => 'last' ) );
	check( 'pull: 200 with top-level blocks', 200 === $st && true === $p['ok'] && array( 'ok', 'store_timezone', 'from', 'to', 'mode', 'partial', 'creatives_catalog', 'ads_map', 'unregistered_ads', 'range_board', 'daily_rows', 'orders_ledger', 'orders_truncated' ) === array_keys( $p ) );
	check( 'pull: not partial', false === $p['partial'] && $prev === $p['from'] && $day === $p['to'] );
	$cat = array_column( $p['creatives_catalog'], null, 'code' );
	check( 'pull: catalog has issued creatives with landing + lp_ver', isset( $cat['C0001'], $cat['C0002'], $cat[ $issued ] ) && 'cat_best' === $cat['C0001']['lp_ver'] && 'https://shop.test/?vc_catalog_category=best' === $cat['C0001']['landing_url'] && 'https://shop.test/uploads/thumb-501.jpg' === $cat['C0001']['thumb_url'] );
	check( 'pull: catalog fields', array( 'code', 'status', 'attrs', 'note', 'thumb_url', 'landing_url', 'lp_ver', 'created_at' ) === array_keys( $cat['C0001'] ) );
	$ads = array_column( $p['ads_map'], null, 'ad_id' );
	check( 'pull: ads_map', '3300' === $ads[ $ad1 ]['campaign_id'] && 'C0001' === $ads[ $ad1 ]['code'] && array( 'ad_id', 'code', 'ad_name', 'adset_id', 'campaign_id', 'first_seen_ms', 'last_seen_ms' ) === array_keys( $ads[ $ad1 ] ) );
	$rb   = $p['range_board'];
	$rows = array_column( $rb['rows'], null, 'code' );
	check( 'pull: range rows for C0001 / C0002 / UNREG spend', isset( $rows['C0001'], $rows['C0002'], $rows['UNREG'] ) );
	check( 'pull: C0001 counts', 1 === $rows['C0001']['visitors'] && 1 === $rows['C0001']['orders'] && 15400 === $rows['C0001']['sales_cents'] && 4000 === $rows['C0001']['spend_cents'] && 30 === $rows['C0001']['link_clicks'] );
	check( 'pull: row fields', array( 'code', 'visitors', 'valid_visitors', 'intent', 'add_to_cart', 'checkout', 'cashier', 'orders', 'sales_cents', 'spend_cents', 'impressions', 'link_clicks', 'behavior' ) === array_keys( $rows['C0001'] ) && array( 'high', 'mid', 'low', 'invalid' ) === array_keys( $rows['C0001']['intent'] ) );
	check( 'pull: behavior block', array( 'avg_dwell_s', 'avg_pv', 'scroll_75_share' ) === array_keys( $rows['C0001']['behavior'] ) );
	check( 'pull: C0002 add to cart', 1 === $rows['C0002']['add_to_cart'] );
	check( 'pull: total row', '*' === $rb['total']['code'] && 2 === $rb['total']['visitors'] && 4900 === $rb['total']['spend_cents'] );
	$dims = array( 'os', 'model', 'browser', 'region', 'city', 'timezone', 'tier', 'os_version', 'device', 'screen', 'country', 'isp', 'ip_type', 'language', 'placement' );
	check( 'pull: profiles for each code and *', isset( $rb['profiles']['C0001'], $rb['profiles']['C0002'], $rb['profiles']['*'] ) );
	check( 'pull: profiles have the 15 dimensions', $dims === array_keys( $rb['profiles']['C0001']['dims'] ) && $dims === array_keys( $rb['profiles']['*']['dims'] ) );
	check( 'pull: profile values', 'Brooklyn' === $rb['profiles']['C0001']['dims']['city'][0]['value'] && 'US' === $rb['profiles']['*']['dims']['country'][0]['value'] && 2 === $rb['profiles']['*']['total']['visitors'] );
	check( 'pull: untagged / paid', 1 === $rb['untagged_visitors'] && 2 === $rb['paid_visitors'] );
	check( 'pull: missing params flags the ad with clicks but no visits', array( '120210000000003333' ) === array_column( $rb['missing_param_ads'], 'ad_id' ) );
	$daily = array();
	foreach ( $p['daily_rows'] as $r ) {
		$daily[ $r['day'] . '|' . $r['code'] ] = $r;
	}
	check( 'pull: daily rows split by day', isset( $daily[ $prev . '|C0001' ], $daily[ $day . '|C0002' ], $daily[ $day . '|UNREG' ] ) && ! isset( $daily[ $day . '|C0001' ] ) );
	check( 'pull: daily row = report_row shape + day', array( 'day', 'code', 'attrs', 'custom', 'thumb', 'intent', 'visitors', 'valid_visitors', 'add_to_cart', 'checkout', 'cashier', 'orders', 'sales_cents', 'spend_cents', 'impressions', 'link_clicks' ) === array_keys( $daily[ $prev . '|C0001' ] ) );
	check( 'pull: daily spend only from that day', 4000 === $daily[ $prev . '|C0001' ]['spend_cents'] && 900 === $daily[ $day . '|UNREG' ]['spend_cents'] );
	check( 'pull: order query asks for the listed statuses only', B::ORDER_STATUSES === $GLOBALS['last_order_query']['status'] );
	$led = array_column( $p['orders_ledger'], null, 'order_id' );
	check( 'pull: ledger has in-range listed statuses only', array( 7001, 7002 ) === array_keys( $led ) && false === $p['orders_truncated'] );
	$o = $led[7001];
	check( 'pull: ledger money in cents', 15900 === $o['subtotal_cents'] && 1000 === $o['discount_cents'] && 500 === $o['shipping_cents'] && 15400 === $o['total_cents'] && 0 === $o['refunded_cents'] );
	check( 'pull: ledger geo', 'PR' === $o['country_code'] && 'PR' === $o['state_code'] && '009' === $o['postcode_prefix'] && $prev === $o['day_store_tz'] );
	check( 'pull: ledger hash + attribution', substr( hash( 'sha256', 'jane.doe@example.com' ), 0, 24 ) === $o['customer_hash'] && 'C0001' === $o['attributed_cr_code'] && $ad1 === $o['attributed_ad_id'] && false === $o['is_test_hint'] );
	check( 'pull: ledger items', array( array( 'sku' => 'BPC-5', 'product_id' => 11, 'name' => 'BPC-157 5mg', 'qty' => 2, 'line_total_cents' => 14900 ) ) === $o['items'] );
	check( 'pull: ledger test hint + no attribution', true === $led[7002]['is_test_hint'] && '' === $led[7002]['attributed_cr_code'] && '100' === $led[7002]['postcode_prefix'] );
	check( 'pull: ledger fields', array( 'order_id', 'created_at_gmt', 'day_store_tz', 'status', 'currency', 'subtotal_cents', 'discount_cents', 'shipping_cents', 'total_cents', 'refunded_cents', 'payment_method', 'country_code', 'state_code', 'postcode_prefix', 'customer_hash', 'is_test_hint', 'attributed_cr_code', 'attributed_ad_id', 'items' ) === array_keys( $o ) );
	$json = (string) json_encode( $p['orders_ledger'] );
	foreach ( array( 'Jane', 'Doe', 'Example.com', 'example.com', '555', 'Calle', 'test@shop.test', 'QA' ) as $needle ) {
		check( 'pull: no plain PII in ledger (' . $needle . ')', ! str_contains( $json, $needle ) );
	}
	[ $st, $d ] = $call( 'GET', '/peptide/v1/adbrain/pull', '', array( 'from' => '2026-01-01', 'to' => '2026-12-31' ) );
	check( 'pull: range too long -> 400 {ok:false}', 400 === $st && 'range_too_long' === $d['code'] && false === $d['ok'] );
	[ $st, $p2 ] = $call( 'GET', '/peptide/v1/adbrain/pull', '', array( 'from' => $prev, 'to' => $day, 'mode' => 'first' ) );
	check( 'pull: mode first works', 200 === $st && 'first' === $p2['mode'] );

	echo "cr-adbrain-bridge-pure: {$checks} checks passed\n";
}
