<?php
/**
 * CR 素材归因 线 2 的写库逻辑测试：用内存 SQLite 模拟 $wpdb（不需要 WordPress、MySQL 或网络）。
 * 覆盖：编号分配（含广告名先用掉的号、并发撞号重试）、补登记归位（广告表和访问一起改、触发 relinked 动作）、
 * 未登记广告列表、素材统计（花费、最后花费日、进站访客）、CSV 导入覆盖写和重复上传不重算、补广告表、
 * 每日合计推送的请求体和签名、推送失败留待重试、没接总后台时只记下、词表同步覆盖。
 * 运行：php -n cr-library-spend-db-pure.php（需要 pdo_sqlite）
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MINUTE_IN_SECONDS', 60 );

	final class WP_Error {
		public function __construct( public string $code = '', public string $message = '' ) {}
		public function get_error_message(): string { return $this->message; }
	}

	/** 只实现这几个类用到的 $wpdb 方法，SQL 交给 SQLite 执行。 */
	final class Fake_Wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public string $last_error = '';
		public $before_insert = null;
		private bool $quiet = false;
		public function __construct( public PDO $pdo ) {}
		public function suppress_errors( bool $s = true ): bool { $o = $this->quiet; $this->quiet = $s; return $o; }
		public function prepare( string $sql, ...$args ): string {
			$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
			$i    = 0;
			return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) { $v = $args[ $i++ ]; return '%d' === $m[0] ? (string) (int) $v : $this->pdo->quote( (string) $v ); }, $sql );
		}
		private function run( string $sql, array $params = array() ) {
			$sql = 'START TRANSACTION' === $sql ? 'BEGIN' : $sql;
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
		private function write( string $verb, string $table, array $data ) {
			$cols = array_keys( $data );
			$st   = $this->run( "{$verb} INTO {$table} (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_fill( 0, count( $cols ), '?' ) ) . ')', array_values( $data ) );
			if ( false === $st ) { return false; }
			$this->insert_id = (int) $this->pdo->lastInsertId();
			return 1;
		}
		public function insert( string $table, array $data ) {
			if ( $this->before_insert ) { $f = $this->before_insert; $this->before_insert = null; $f( $table, $data ); }
			return $this->write( 'INSERT', $table, $data );
		}
		public function replace( string $table, array $data ) { return $this->write( 'REPLACE', $table, $data ); }
		public function update( string $table, array $data, array $where ) {
			$set = implode( ',', array_map( static fn( $c ) => "{$c} = ?", array_keys( $data ) ) );
			$wh  = implode( ' AND ', array_map( static fn( $c ) => "{$c} = ?", array_keys( $where ) ) );
			$st  = $this->run( "UPDATE {$table} SET {$set} WHERE {$wh}", array_merge( array_values( $data ), array_values( $where ) ) );
			return false === $st ? false : $st->rowCount();
		}
	}

	$GLOBALS['opts']     = array();
	$GLOBALS['actions']  = array();
	$GLOBALS['requests'] = array();
	$GLOBALS['respond']  = null;
	function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
	function get_current_user_id() { return 7; }
	function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
	function do_action( $hook, ...$args ) { $GLOBALS['actions'][] = array( $hook, $args ); }
	function sanitize_file_name( $n ) { return preg_replace( '/[^A-Za-z0-9._-]/', '', $n ); }
	function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
	function wp_next_scheduled( $h ) { return $GLOBALS['opts'][ 'cron_' . $h ] ?? false; }
	function wp_schedule_single_event( $t, $h ) { $GLOBALS['opts'][ 'cron_' . $h ] = $t; return true; }
	function wp_generate_uuid4() { return 'nonce-' . count( $GLOBALS['requests'] ); }
	function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
	function add_query_arg( $k, $v, $url ) { return $url . '?' . $k . '=' . $v; }
	function is_wp_error( $x ) { return $x instanceof WP_Error; }
	function wp_remote_request( $url, $args ) {
		$GLOBALS['requests'][] = array( 'url' => $url ) + $args;
		$f = $GLOBALS['respond'];
		return $f ? $f( $url, $args ) : new WP_Error( 'http', 'no responder' );
	}
	function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
	function wp_remote_retrieve_body( $r ) { return $r['body']; }
}

namespace PeptideCore\Tracking {
	final class Schema {
		public static function installed(): bool { return true; }
		public static function table( string $n ): string { return 'wp_pc_track_' . $n; }
	}
}

namespace {
	$inc = dirname( __DIR__ ) . '/includes/';
	require $inc . 'creatives/class-contract.php';
	require $inc . 'creatives/class-schema.php';
	require $inc . 'creatives/class-creatives.php';
	require $inc . 'creatives/class-spend-import.php';
	require $inc . 'creatives/class-vocab-sync.php';
	require $inc . 'class-hq-connector.php';
	require $inc . 'class-order-report.php';

	use PeptideCore\Creatives\Contract as C;
	use PeptideCore\Creatives\Creatives as Cr;
	use PeptideCore\Creatives\Schema;
	use PeptideCore\Creatives\Spend_Import as S;
	use PeptideCore\Creatives\Vocab_Sync as V;

	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }

	$pdo = new PDO( 'sqlite::memory:' );
	$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$pdo->exec( "CREATE TABLE wp_pc_ad_creatives (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, attachment_id INTEGER NOT NULL DEFAULT 0, video_id INTEGER NOT NULL DEFAULT 0, attrs TEXT, custom TEXT, lang TEXT NOT NULL DEFAULT 'en', note TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'active', created_by INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0)" );
	$pdo->exec( "CREATE TABLE wp_pc_ad_ads (ad_id TEXT PRIMARY KEY, code TEXT NOT NULL DEFAULT '', ad_name TEXT NOT NULL DEFAULT '', adset_id TEXT NOT NULL DEFAULT '', campaign_id TEXT NOT NULL DEFAULT '', first_seen_ms INTEGER NOT NULL DEFAULT 0, last_seen_ms INTEGER NOT NULL DEFAULT 0)" );
	$pdo->exec( "CREATE TABLE wp_pc_ad_daily_spend (ad_id TEXT NOT NULL, day TEXT NOT NULL, spend_cents INTEGER NOT NULL DEFAULT 0, impressions INTEGER NOT NULL DEFAULT 0, link_clicks INTEGER NOT NULL DEFAULT 0, ad_name TEXT NOT NULL DEFAULT '', import_id INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (ad_id, day))" );
	$pdo->exec( "CREATE TABLE wp_pc_ad_spend_imports (id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT NOT NULL DEFAULT '', user_id INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL DEFAULT 0, day_from TEXT NULL, day_to TEXT NULL, rows_total INTEGER NOT NULL DEFAULT 0, rows_saved INTEGER NOT NULL DEFAULT 0, errors TEXT NULL)" );
	$pdo->exec( "CREATE TABLE wp_pc_ad_attribute_vocab (attr TEXT NOT NULL, value TEXT NOT NULL, label TEXT NOT NULL DEFAULT '', required INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, position INTEGER NOT NULL DEFAULT 0, ver INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (attr, value))" );
	$pdo->exec( "CREATE TABLE wp_pc_track_visits (id INTEGER PRIMARY KEY AUTOINCREMENT, visitor_id INTEGER NOT NULL, utm_content TEXT NULL, creative_code TEXT NOT NULL DEFAULT '')" );
	$GLOBALS['wpdb'] = $wpdb = new Fake_Wpdb( $pdo );
	update_option( Schema::OPTION, Schema::VERSION );
	Schema::seed_vocab();

	$attrs = array( 'hook' => 'lab', 'fmt' => 'vid', 'angle' => 'purity' );

	// ---------- 编号分配 ----------
	check( 'vocab seeded', 14 === count( Schema::vocab() ) && 0 === V::local_ver() );
	check( 'first code', 'C0001' === Cr::create( $attrs, 0, 'first' ) );
	check( 'second code', 'C0002' === Cr::create( $attrs + array( 'lang' => 'es' ), 5, '' ) );
	$pdo->exec( "INSERT INTO wp_pc_ad_ads (ad_id, code, ad_name, first_seen_ms) VALUES ('120210000000000020', 'C0020', 'C0020_hook-lab', 1000)" );
	check( 'code already used in an ad name is skipped', 'C0021' === Cr::create( $attrs, 0, '' ) );
	// 并发：取完号、插入前，别人先用掉了 C0022。
	$wpdb->before_insert = static function ( $t, $d ) use ( $pdo ) {
		$pdo->exec( "INSERT INTO wp_pc_ad_creatives (code, attrs, created_at) VALUES ('" . $d['code'] . "', '{}', 1)" );
	};
	check( 'collision retried with next number', 'C0023' === Cr::create( $attrs, 0, 'race' ) );
	check( 'both rows kept, codes unique', array( 'C0001', 'C0002', 'C0021', 'C0022', 'C0023' ) === array_map( 'strval', $pdo->query( 'SELECT code FROM wp_pc_ad_creatives ORDER BY code' )->fetchAll( PDO::FETCH_COLUMN ) ) );
	check( 'wanted code used when free', 'C0050' === Cr::create( $attrs, 0, '', 'C0050' ) );
	check( 'wanted code taken -> next number', 'C0051' === Cr::create( $attrs, 0, '', 'C0050' ) );
	$c = Cr::get( 'C0002' );
	check( 'stored creative decoded', 'es' === $c['attrs']['lang'] && 5 === $c['attachment_id'] && 'active' === $c['status'] );
	Cr::update( 'C0002', array( 'hook' => 'price', 'fmt' => 'img', 'angle' => 'price' ), 'edited' );
	check( 'update keeps thumbnail when not given', 5 === Cr::get( 'C0002' )['attachment_id'] && 'price' === Cr::get( 'C0002' )['attrs']['hook'] && 'edited' === Cr::get( 'C0002' )['note'] );
	check( 'video stored with creative', 'C0052' === Cr::create( $attrs, 7, '', '', 9 ) && 9 === Cr::get( 'C0052' )['video_id'] && 7 === Cr::get( 'C0052' )['attachment_id'] );
	Cr::update( 'C0052', $attrs, '' );
	check( 'update keeps video when not given', 9 === Cr::get( 'C0052' )['video_id'] );
	Cr::update( 'C0052', $attrs, '', null, 0 );
	check( 'update can clear video', 0 === Cr::get( 'C0052' )['video_id'] );
	Cr::set_status( 'C0002', 'archived' );
	check( 'archive', 'archived' === Cr::get( 'C0002' )['status'] );

	// ---------- 未登记、补登记归位 ----------
	$pdo->exec( "INSERT INTO wp_pc_ad_ads (ad_id, code, ad_name, first_seen_ms) VALUES ('120210000000000030', '', 'summer sale', 3000), ('120210000000000031', 'C0099', 'C0099_hook-lab', 2000), ('120210000000000032', 'C0001', 'C0001_hook-lab', 1500)" );
	$pdo->exec( "INSERT INTO wp_pc_track_visits (visitor_id, utm_content, creative_code) VALUES (1, '120210000000000030', 'UNREG'), (2, '120210000000000030', 'UNREG'), (2, '120210000000000030', 'UNREG'), (3, '120210000000000032', 'C0001')" );
	$un = array_column( Cr::unregistered_ads(), 'ad_id' );
	check( 'unregistered: empty code and unknown code, newest first', array( '120210000000000030', '120210000000000031', '120210000000000020' ) === $un );
	// 补登记 C0099：广告名里的号空着，沿用。
	$ad   = Cr::ad( '120210000000000031' );
	$want = Cr::code_from_ad( $ad['code'], $ad['ad_name'], Cr::codes() );
	check( 'relink wants code from ad', 'C0099' === Cr::create( $attrs, 0, '', $want ) );
	check( 'registering the code fixes that ad automatically', ! in_array( '120210000000000031', array_column( Cr::unregistered_ads(), 'ad_id' ), true ) );
	// 没编号的广告归到 C0001。
	$GLOBALS['actions'] = array();
	check( 'link ad', Cr::link_ad( '120210000000000030', 'C0001' ) );
	check( 'ad table relinked', 'C0001' === Cr::ad( '120210000000000030' )['code'] );
	check( 'visits relinked', 3 === (int) $pdo->query( "SELECT COUNT(*) FROM wp_pc_track_visits WHERE utm_content = '120210000000000030' AND creative_code = 'C0001'" )->fetchColumn() );
	check( 'relinked action fired', array( array( 'pc_creatives_relinked', array( '120210000000000030', 'C0001' ) ) ) === $GLOBALS['actions'] );
	check( 'link refuses bad ids', ! Cr::link_ad( '{{ad.id}}', 'C0001' ) && ! Cr::link_ad( '120210000000000030', 'UNREG' ) );
	check( 'only C0020 left unregistered', array( '120210000000000020' ) === array_column( Cr::unregistered_ads(), 'ad_id' ) );

	// ---------- CSV 导入 ----------
	$csv = "Day,Ad ID,Ad name,Amount spent (USD),Impressions,Link clicks\n"
		. "2026-11-09,120210000000000030,summer sale,10.00,1000,10\n"
		. "2026-11-10,120210000000000030,summer sale,5.50,500,4\n"
		. "2026-11-10,120210000000000040,C0021_hook-lab_fmt-vid_angle-purity,2.25,90,1\n"
		. "2026-11-10,120210000000000041,new ad without code,1.00,10,0\n";
	update_option( S::PENDING, array() );
	$r1 = S::import( S::parse( $csv ), 'meta report.csv' );
	check( 'import: rows saved', 4 === $r1['saved'] && array( '2026-11-09', '2026-11-10' ) === $r1['days'] );
	check( 'import: new ads added with code from name', 2 === $r1['ads_added'] && 'C0021' === Cr::ad( '120210000000000040' )['code'] && '' === Cr::ad( '120210000000000041' )['code'] );
	check( 'import: existing ad not duplicated, code kept', 'C0001' === Cr::ad( '120210000000000030' )['code'] );
	check( 'import: first seen = first day midnight in store tz', ( new DateTimeImmutable( '2026-11-09 00:00:00', wp_timezone() ) )->getTimestamp() * 1000 === (int) Cr::ad( '120210000000000041' )['first_seen_ms'] );
	check( 'import: not connected -> kept pending, no request', 'not_connected' === $r1['push']['status'] && array() === $GLOBALS['requests'] && array( '2026-11-09', '2026-11-10' ) === S::pending() );
	check( 'import: not connected -> checks again in a day', wp_next_scheduled( S::RETRY ) > time() + 3600 );
	unset( $GLOBALS['opts'][ 'cron_' . S::RETRY ] );
	check( 'totals per day', array( '2026-11-09' => 1000, '2026-11-10' => 875 ) === S::totals( array( '2026-11-09', '2026-11-10' ) ) );
	$r2 = S::import( S::parse( $csv ), 'meta report.csv' );
	check( 'reimport: same totals, no extra rows', array( '2026-11-09' => 1000, '2026-11-10' => 875 ) === S::totals( array( '2026-11-09', '2026-11-10' ) ) && 4 === (int) $pdo->query( 'SELECT COUNT(*) FROM wp_pc_ad_daily_spend' )->fetchColumn() && 0 === $r2['ads_added'] );
	$fix = S::parse( "Day,Ad ID,Ad name,Amount spent (USD)\n2026-11-10,120210000000000040,C0021_hook-lab_fmt-vid_angle-purity,3.00\n" );
	S::import( $fix, 'fix.csv' );
	check( 'correction overwrites one ad-day', 950 === S::totals( array( '2026-11-10' ) )['2026-11-10'] );
	$imports = $pdo->query( 'SELECT * FROM wp_pc_ad_spend_imports ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );
	check( 'import log rows', 3 === count( $imports ) && 'metareport.csv' === $imports[0]['filename'] && '2026-11-09' === $imports[0]['day_from'] && '2026-11-10' === $imports[0]['day_to'] && 4 === (int) $imports[0]['rows_saved'] && 7 === (int) $imports[0]['user_id'] );
	check( 'spend rows point at latest import', (int) $imports[2]['id'] === (int) $pdo->query( "SELECT import_id FROM wp_pc_ad_daily_spend WHERE ad_id = '120210000000000040'" )->fetchColumn() );

	// ---------- 统计 ----------
	$pdo->exec( "INSERT INTO wp_pc_ad_daily_spend (ad_id, day, spend_cents) VALUES ('120210000000000032', '2026-11-01', 400), ('120210000000000032', '2026-11-11', 0)" );
	$st = Cr::stats();
	check( 'stats: spend summed across ads of a creative', 1000 + 550 + 400 === $st['C0001']['spend_cents'] );
	check( 'stats: last day with spend > 0', '2026-11-10' === $st['C0001']['last_day'] );
	check( 'stats: distinct visitors by creative', 2 + 1 === $st['C0001']['visitors'] - 0 && 3 === $st['C0001']['visitors'] );
	check( 'stats: status from last day', 'live' === Cr::status_for( $st['C0001']['last_day'], '2026-11-11' ) && 'stopped' === Cr::status_for( $st['C0001']['last_day'], '2026-11-14' ) );

	// ---------- 推送到总后台 ----------
	define( 'PEPTIDE_HQ_URL', 'https://hq.example.test' );
	define( 'PEPTIDE_HQ_SECRET', str_repeat( 'a', 64 ) );
	$key = str_repeat( 'b', 64 );
	update_option( 'peptide_core_hq', array( 'key' => $key, 'uuid' => '0b7e1c1e-1111-4222-8333-944444444444' ) );
	check( 'hq enabled', Cr::hq_enabled() );
	unset( $GLOBALS['opts'][ 'cron_' . S::RETRY ] );
	$GLOBALS['respond'] = static fn() => array( 'code' => 503, 'body' => '{"message":"busy"}' );
	$r = S::push( array( '2026-11-08' ) );
	check( 'push failure: all days kept for retry, retry scheduled', 'failed' === $r['status'] && str_contains( $r['message'], 'HTTP 503 busy' ) && array( '2026-11-08', '2026-11-09', '2026-11-10' ) === S::pending() && false !== wp_next_scheduled( S::RETRY ) );
	$GLOBALS['requests'] = array();
	$GLOBALS['respond']  = static fn( $u, $a ) => array( 'code' => 200, 'body' => '{"saved":["2026-11-09","2026-11-10"]}' );
	S::retry();
	check( 'retry sent one request', 1 === count( $GLOBALS['requests'] ) && array() === S::pending() );
	$req  = $GLOBALS['requests'][0];
	$body = json_decode( $req['body'], true );
	check( 'push url and method', 'https://hq.example.test/?rest_route=' . rawurlencode( S::ROUTE ) === $req['url'] && 'POST' === $req['method'] );
	check( 'push body: nonce + day totals incl. zero day', array( 'nonce' => 'nonce-0', 'days' => array( array( 'day' => '2026-11-08', 'spend_cents' => 0 ), array( 'day' => '2026-11-09', 'spend_cents' => 1000 ), array( 'day' => '2026-11-10', 'spend_cents' => 950 ) ) ) === $body );
	$h = $req['headers'];
	check( 'push signature matches HQ rule', '0b7e1c1e-1111-4222-8333-944444444444' === $h['X-PC-HQ-Site'] && hash_hmac( 'sha256', "POST\n" . S::ROUTE . "\n\n" . $h['X-PC-HQ-Time'] . "\n" . hash( 'sha256', $req['body'] ), $key ) === $h['X-PC-HQ-Sign'] );
	check( 'nothing pending -> no request', 'none' === S::push()['status'] && 1 === count( $GLOBALS['requests'] ) );

	// ---------- 词表同步 ----------
	$GLOBALS['requests'] = array();
	$items               = array(
		array( 'attr' => 'hook', 'value' => 'lab', 'label' => '检测报告', 'required' => true, 'position' => 0 ),
		array( 'attr' => 'hook', 'value' => 'review', 'label' => '测评', 'required' => true, 'position' => 1 ),
		array( 'attr' => 'fmt', 'value' => 'vid', 'label' => '视频', 'required' => true, 'position' => 2 ),
		array( 'attr' => 'angle', 'value' => 'purity', 'label' => '纯度', 'required' => true, 'position' => 3 ),
	);
	$GLOBALS['respond'] = static fn() => array( 'code' => 200, 'body' => json_encode( array( 'ver' => 2, 'items' => $items ) ) );
	$r                  = V::sync();
	check( 'vocab: updated to ver 2', 'updated' === $r['status'] && 2 === V::local_ver() );
	check( 'vocab: table replaced as a whole', array( 'lab', 'review', 'vid', 'purity' ) === array_column( Schema::vocab(), 'value' ) );
	$g = $GLOBALS['requests'][0];
	check( 'vocab: signed GET without body', 'GET' === $g['method'] && ! isset( $g['body'] ) && hash_hmac( 'sha256', "GET\n" . V::ROUTE . "\n\n" . $g['headers']['X-PC-HQ-Time'] . "\n" . hash( 'sha256', '' ), $key ) === $g['headers']['X-PC-HQ-Sign'] );
	check( 'vocab: sync state saved', V::state()['ok'] && 2 === V::state()['ver'] && null === V::maybe_sync() );
	$GLOBALS['respond'] = static fn() => array( 'code' => 200, 'body' => json_encode( array( 'ver' => 2, 'items' => array_slice( $items, 0, 1 ) ) ) );
	check( 'vocab: incomplete remote ignored', 'failed' === V::sync()['status'] && 4 === count( Schema::vocab() ) );
	$GLOBALS['respond'] = static fn() => array( 'code' => 200, 'body' => json_encode( array( 'ver' => 1, 'items' => $items ) ) );
	check( 'vocab: older ver ignored', 'same' === V::sync()['status'] && 2 === V::local_ver() );
	$GLOBALS['respond'] = static fn() => new WP_Error( 'http', 'timeout' );
	check( 'vocab: network error keeps local', 'failed' === V::sync()['status'] && 4 === count( Schema::vocab() ) );
	update_option( 'peptide_core_hq', array() );
	check( 'vocab: not connected', 'not_connected' === V::sync()['status'] );
	check( 'archived value still shown via stored attrs', 'price' === Cr::get( 'C0002' )['attrs']['hook'] );

	echo "PASS {$checks} CR line 2 database checks (SQLite stand-in for \$wpdb; no WordPress, MySQL, or network).\n";
}
