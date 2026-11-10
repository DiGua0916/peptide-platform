<?php
/**
 * M3 系列（HQ-05、NF-12）纯逻辑测试：升级脚本可重复执行、“系列 1”回填、系列代号、运费整理、推送计划。
 * 内存里的假数据库，不需要 WordPress、数据库或网络。运行：php -n -d extension=mbstring m3-series-pure.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'MINUTE_IN_SECONDS', 60 );
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public $data = array() ) {}
		public function get_error_message(): string { return $this->message; }
		public function get_error_code(): string { return $this->code; }
	}
	function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
	function get_option( string $k, $default = false ) { return $GLOBALS['options'][ $k ] ?? $default; }
	function update_option( string $k, $v, $autoload = null ): bool { $GLOBALS['options'][ $k ] = $v; return true; }
	function sanitize_text_field( $s ): string { return trim( strip_tags( (string) $s ) ); }
	function wp_strip_all_tags( $s ): string { return trim( strip_tags( (string) $s ) ); }
	function sanitize_key( $s ): string { return strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', (string) $s ) ); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
	function add_action( ...$a ): void { $GLOBALS['actions'][] = $a; }
	function is_admin(): bool { return false; }
	function wp_schedule_single_event( int $when, string $hook, array $args = array() ): void { $GLOBALS['scheduled'][] = array( $hook, $args ); }
	function wp_next_scheduled( string $hook, array $args = array() ) { return false; }
	function dbDelta( string $sql ): void {
		preg_match_all( '/CREATE TABLE (\S+) \(/', $sql, $m );
		foreach ( $m[1] as $table ) {
			$GLOBALS['wpdb']->tables[ $table ] ??= array( 'columns' => array(), 'rows' => array(), 'indexes' => array() );
			++$GLOBALS['dbdelta_calls'];
		}
	}

	/** 只认识本模块升级和回填用到的那几条 SQL；其他 SQL 直接报错，避免测试悄悄漏掉。 */
	final class Memory_Db {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public array $tables  = array();
		public function get_charset_collate(): string { return ''; }
		public function esc_like( string $s ): string { return addcslashes( $s, '_%\\' ); }
		public function prepare( string $sql, ...$args ): string {
			return (string) preg_replace_callback( '/%[sd]/', static function ( $m ) use ( &$args ) { $v = array_shift( $args ); return '%d' === $m[0] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'"; }, $sql );
		}
		public function get_var( string $sql ) {
			if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $sql, $m ) ) { $t = stripslashes( $m[1] ); return isset( $this->tables[ $t ] ) ? $t : null; }
			if ( preg_match( "/^SHOW INDEX FROM (\S+) WHERE Key_name = '(\w+)'$/", $sql, $m ) ) { return in_array( $m[2], $this->tables[ $m[1] ]['indexes'], true ) ? $m[1] : null; }
			if ( preg_match( '/^SELECT id FROM (\S+) ORDER BY id ASC LIMIT 1$/', $sql, $m ) ) { $ids = array_keys( $this->tables[ $m[1] ]['rows'] ); return $ids ? min( $ids ) : null; }
			if ( preg_match( '/^SELECT (\w+_ver) FROM (\S+) WHERE id = (\d+)$/', $sql, $m ) ) { return $this->tables[ $m[2] ]['rows'][ (int) $m[3] ][ $m[1] ] ?? null; }
			throw new \RuntimeException( 'Unhandled get_var: ' . $sql );
		}
		public function get_col( string $sql, int $x = 0 ): array {
			if ( preg_match( '/^SHOW COLUMNS FROM (\S+)$/', $sql, $m ) ) { return $this->tables[ $m[1] ]['columns']; }
			throw new \RuntimeException( 'Unhandled get_col: ' . $sql );
		}
		public function get_results( string $sql, $format = null ): array {
			if ( preg_match( '/^SELECT \* FROM (\S+) ORDER BY id ASC$/', $sql, $m ) ) { $rows = $this->tables[ $m[1] ]['rows']; ksort( $rows ); return array_values( $rows ); }
			if ( preg_match( '/^SELECT id, series_id, buyer_name, onboarding_state, series_sync FROM (\S+)$/', $sql, $m ) ) { return array_values( $this->tables[ $m[1] ]['rows'] ); }
			throw new \RuntimeException( 'Unhandled get_results: ' . $sql );
		}
		public function get_row( string $sql, $format = null ): ?array {
			if ( preg_match( '/^SELECT \* FROM (\S+) WHERE id = (\d+)$/', $sql, $m ) ) { return $this->tables[ $m[1] ]['rows'][ (int) $m[2] ] ?? null; }
			throw new \RuntimeException( 'Unhandled get_row: ' . $sql );
		}
		public function query( string $sql ) {
			if ( preg_match( '/^ALTER TABLE (\S+) ADD COLUMN (\w+) (.+)$/', $sql, $m ) ) {
				if ( in_array( $m[2], $this->tables[ $m[1] ]['columns'], true ) ) { throw new \RuntimeException( 'Duplicate column ' . $m[2] ); }
				$this->tables[ $m[1] ]['columns'][] = $m[2];
				$default = str_contains( $m[3], 'varchar' ) ? '' : 0;
				foreach ( $this->tables[ $m[1] ]['rows'] as $id => $row ) { $this->tables[ $m[1] ]['rows'][ $id ][ $m[2] ] = $default; }
				return 1;
			}
			if ( preg_match( '/^ALTER TABLE (\S+) ADD KEY (\w+) \(([\w,]+)\)$/', $sql, $m ) ) {
				if ( in_array( $m[2], $this->tables[ $m[1] ]['indexes'], true ) ) { throw new \RuntimeException( 'Duplicate index ' . $m[2] ); }
				$this->tables[ $m[1] ]['indexes'][] = $m[2];
				return 1;
			}
			if ( preg_match( "/^INSERT IGNORE INTO (\S+) \(slug, name, status, created_at, updated_at\) VALUES \('([^']*)', '([^']*)', '([^']*)', (\d+), (\d+)\)$/u", $sql, $m ) ) {
				foreach ( $this->tables[ $m[1] ]['rows'] as $row ) { if ( $row['slug'] === $m[2] ) { return 0; } }
				$id = ++$this->insert_id;
				$this->tables[ $m[1] ]['rows'][ $id ] = array( 'id' => $id, 'slug' => $m[2], 'name' => $m[3], 'status' => $m[4], 'master_site_id' => 0, 'catalog_ver' => 0, 'template_ver' => 0, 'campaign_ver' => 0, 'shipping_ver' => 0, 'shipping' => null, 'created_at' => (int) $m[5], 'updated_at' => (int) $m[6] );
				return 1;
			}
			if ( preg_match( '/^UPDATE (\S+) SET series_id = (\d+) WHERE series_id = 0$/', $sql, $m ) ) {
				$n = 0;
				foreach ( $this->tables[ $m[1] ]['rows'] as $id => $row ) { if ( 0 === (int) $row['series_id'] ) { $this->tables[ $m[1] ]['rows'][ $id ]['series_id'] = (int) $m[2]; ++$n; } }
				return $n;
			}
			if ( preg_match( '/^UPDATE (\S+) SET (\w+_ver) = \2 \+ 1, updated_at = (\d+) WHERE id = (\d+)$/', $sql, $m ) ) {
				++$this->tables[ $m[1] ]['rows'][ (int) $m[4] ][ $m[2] ];
				return 1;
			}
			throw new \RuntimeException( 'Unhandled query: ' . $sql );
		}
		public function insert( string $table, array $row ) { $id = ++$this->insert_id; $this->tables[ $table ]['rows'][ $id ] = $row + array( 'id' => $id, 'master_site_id' => 0, 'catalog_ver' => 0, 'template_ver' => 0, 'campaign_ver' => 0, 'shipping_ver' => 0, 'shipping' => null ); return 1; }
		public function update( string $table, array $fields, array $where ) { $this->tables[ $table ]['rows'][ $where['id'] ] = array_replace( $this->tables[ $table ]['rows'][ $where['id'] ], $fields ); return 1; }
	}
	$GLOBALS['wpdb']          = new Memory_Db();
	$GLOBALS['options']       = array();
	$GLOBALS['scheduled']     = array();
	$GLOBALS['dbdelta_calls'] = 0;
}
namespace PeptideHq {
	final class Db { public static function table( string $name ): string { return 'wp_pchq_' . $name; } }
	final class Monitor { public const CRON = 'pchq_poll'; }
	final class Site_Lifecycle { public static function active( int $id ): bool { return true; } }
	final class Design_Push { public static function friendly( \WP_Error $e ): string { return $e->get_error_message(); } }
	final class Client { public static function request( int $site_id, string $method, string $route, array $query = array(), ?array $body = null, int $timeout = 20 ) { $GLOBALS['requests'][] = array( $site_id, $route, $body ); return $GLOBALS['client_reply'] ?? new \WP_Error( 'pchq_offline', 'offline' ); } }
	final class Sites {
		public static function all(): array { $out = array(); foreach ( $GLOBALS['wpdb']->tables['wp_pchq_sites']['rows'] as $id => $r ) { $out[ $id ] = array( 'id' => $id, 'name' => $r['name'], 'url' => $r['url'], 'management_url' => $r['url'], 'public_url' => $r['url'], 'status' => 'online' ); } return $out; }
		public static function get( int $id ): ?array { return self::all()[ $id ] ?? null; }
		public static function update( int $id, array $fields ): void { $GLOBALS['wpdb']->update( 'wp_pchq_sites', $fields, array( 'id' => $id ) ); }
	}
}
namespace {
	foreach ( array( 'series-db', 'series', 'series-campaigns', 'series-push' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
	use PeptideHq\Series;
	use PeptideHq\Series_Db;
	use PeptideHq\Series_Campaigns;
	use PeptideHq\Series_Push;

	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }
	$db = $GLOBALS['wpdb'];

	// ---- 升级：总后台自己的表还没建时不动，也不写版本号 ----
	check( 'install waits for the sites table', false === Series_Db::install() && ! isset( $GLOBALS['options'][ Series_Db::OPTION ] ) && array() === $db->tables );

	// ---- 升级：6 家现有店铺全部归入“系列 1” ----
	$db->tables['wp_pchq_sites'] = array( 'columns' => array( 'id', 'name', 'url', 'uuid' ), 'rows' => array(), 'indexes' => array() );
	foreach ( array( 's0001', 's0006', 's0007', 's0008', 's0009', 's0010' ) as $i => $slug ) {
		$db->tables['wp_pchq_sites']['rows'][ $i + 1 ] = array( 'id' => $i + 1, 'name' => $slug, 'url' => 'https://' . $slug . '.manager.example.test/', 'uuid' => 'u-' . $slug );
	}
	$db->insert_id = 0;
	check( 'install succeeds', true === Series_Db::install() );
	check( 'version option written', Series_Db::VERSION === get_option( Series_Db::OPTION ) && Series::ready() );
	check( 'one default series', 1 === count( $db->tables['wp_pchq_series']['rows'] ) && Series::DEFAULT_SLUG === $db->tables['wp_pchq_series']['rows'][1]['slug'] && Series::DEFAULT_NAME === $db->tables['wp_pchq_series']['rows'][1]['name'] );
	check( 'site columns added once', array( 'id', 'name', 'url', 'uuid', 'series_id', 'buyer_name', 'onboarding_state', 'series_sync' ) === $db->tables['wp_pchq_sites']['columns'] );
	check( 'series index added', array( 'series_id' ) === $db->tables['wp_pchq_sites']['indexes'] );
	check( 'all six stores in series 1', array( 1 ) === array_values( array_unique( array_column( $db->tables['wp_pchq_sites']['rows'], 'series_id' ) ) ) && 6 === count( Series::sites_of( 1 ) ) );
	check( 'existing stores do not accept sync by default', array( 0 ) === array_values( array_unique( array_column( $db->tables['wp_pchq_sites']['rows'], 'series_sync' ) ) ) );
	check( 'default id', 1 === Series::default_id() );

	// ---- 升级脚本重复执行，结果不变 ----
	$before = serialize( $db->tables );
	unset( $GLOBALS['options'][ Series_Db::OPTION ] );
	check( 'second install succeeds', true === Series_Db::install() );
	check( 'second install changes nothing', $before === serialize( $db->tables ) );
	Series_Db::maybe_upgrade();
	check( 'maybe_upgrade is a no-op when current', $before === serialize( $db->tables ) );

	// ---- 新系列；手工改过归属的店铺，重复升级时保持不变；新店（series_id = 0）补归默认系列 ----
	$second = Series::create( '系列 2' );
	check( 'second series created with generated slug', 2 === $second && 'series-2' === Series::get( 2 )['slug'] );
	check( 'duplicate name refused', is_wp_error( Series::create( '系列 2' ) ) );
	check( 'empty name refused', is_wp_error( Series::create( '   ' ) ) );
	check( 'assign store 6 to series 2', true === Series::assign( 6, 2 ) && 2 === Series::site( 6 )['series_id'] && 5 === count( Series::sites_of( 1 ) ) && 1 === count( Series::sites_of( 2 ) ) );
	check( 'assignment queues a header push', array( Series_Push::HOOK, array( 6, 0, 0 ) ) === end( $GLOBALS['scheduled'] ) );
	check( 'assign to missing series refused', is_wp_error( Series::assign( 5, 99 ) ) );
	$db->tables['wp_pchq_sites']['rows'][7] = array( 'id' => 7, 'name' => 's0011', 'url' => 'https://s0011.manager.example.test/', 'uuid' => 'u-s0011', 'series_id' => 0, 'buyer_name' => '', 'onboarding_state' => '', 'series_sync' => 0 );
	unset( $GLOBALS['options'][ Series_Db::OPTION ] );
	check( 'third install succeeds', true === Series_Db::install() );
	check( 'reassigned store stays in series 2', 2 === $db->tables['wp_pchq_sites']['rows'][6]['series_id'] );
	check( 'store without a series joins series 1', 1 === $db->tables['wp_pchq_sites']['rows'][7]['series_id'] );
	check( 'still two series', 2 === count( $db->tables['wp_pchq_series']['rows'] ) );

	// ---- 投手、同步开关、向导进度 ----
	Series::set_buyer( 2, '  Buyer A <b>x</b> ' );
	check( 'buyer name saved clean', 'Buyer A x' === Series::site( 2 )['buyer_name'] );
	Series::set_onboarding( 2, 'Step-2!' );
	check( 'onboarding state sanitized', 'step-2' === Series::site( 2 )['onboarding_state'] );
	Series::set_sync( 2, true );
	check( 'sync switch on', true === Series::site( 2 )['series_sync'] );

	// ---- 新店注册：按预留归入系列，默认接受同步 ----
	check( 'reserve needs a real series', false === Series::reserve( 's0012.manager.example.test', 99 ) );
	check( 'reserve by management host', true === Series::reserve( 'https://S0012.manager.example.test/', 2, 'Buyer B' ) );
	$db->tables['wp_pchq_sites']['rows'][8] = array( 'id' => 8, 'name' => 's0012', 'url' => 'https://s0012.manager.example.test/', 'uuid' => 'u-s0012', 'series_id' => 0, 'buyer_name' => '', 'onboarding_state' => '', 'series_sync' => 0 );
	Series::on_site_registered( 8 );
	check( 'reserved store joins series 2 with buyer', array( 'series_id' => 2, 'buyer_name' => 'Buyer B', 'onboarding_state' => '', 'series_sync' => true ) === Series::site( 8 ) );
	check( 'reservation consumed', array() === get_option( Series::RESERVED ) );
	$db->tables['wp_pchq_sites']['rows'][9] = array( 'id' => 9, 'name' => 's0013', 'url' => 'https://s0013.manager.example.test/', 'uuid' => 'u-s0013', 'series_id' => 0, 'buyer_name' => '', 'onboarding_state' => '', 'series_sync' => 0 );
	Series::on_site_registered( 9 );
	check( 'unreserved store joins the default series', 1 === Series::site( 9 )['series_id'] && true === Series::site( 9 )['series_sync'] );

	// ---- 版本号 ----
	check( 'bump catalog', 1 === Series::bump( 1, 'catalog' ) && 2 === Series::bump( 1, 'catalog' ) && 2 === Series::get( 1 )['catalog_ver'] );
	check( 'bump rejects unknown section', 0 === Series::bump( 1, 'slug' ) && 0 === Series::bump( 99, 'catalog' ) );
	check( 'other series untouched', 0 === Series::get( 2 )['catalog_ver'] );

	// ---- 系列代号（纯函数） ----
	check( 'slug from latin name', 'peptide-line-a' === Series::slugify( 'Peptide Line A' ) );
	check( 'slug from chinese name', 'series-2' === Series::slugify( '系列 2' ) && 'series' === Series::slugify( '减脂系列' ) );
	check( 'slug avoids taken', 'series-3' === Series::slugify( '减脂系列', array( 'series', 'series-2' ) ) );

	// ---- 运费两档（FE-07） ----
	$default = Series::default_shipping();
	$clean   = Series::clean_shipping( $default );
	check( 'default shipping has two tiers', 2 === count( $clean['plans'][0]['rates'] ) && 4500 === $clean['plans'][0]['rates'][0]['fee_cents'] && 1500 === $clean['plans'][0]['rates'][1]['fee_cents'] );
	check( 'tiers meet at the threshold', 1000.0 === $clean['plans'][0]['rates'][0]['max'] && 1000.0 === $clean['plans'][0]['rates'][1]['min'] && null === $clean['plans'][0]['rates'][1]['max'] );
	check( 'clean is stable through JSON', $clean === Series::clean_shipping( json_decode( json_encode( $clean ), true ) ) );
	check( 'shipping without plans refused', null === Series::clean_shipping( array( 'plans' => array( array( 'name' => 'x', 'regions' => array(), 'rates' => array() ) ) ) ) );
	check( 'negative fee floors at zero', 0 === Series::clean_shipping( array( 'plans' => array( array( 'name' => 'P', 'regions' => array( array( 'country' => 'us' ) ), 'rates' => array( array( 'name' => 'R', 'fee_cents' => -5 ) ) ) ) ) )['plans'][0]['rates'][0]['fee_cents'] );
	check( 'save shipping bumps version once', 1 === Series::save_shipping( 1, $default ) && 1 === Series::save_shipping( 1, $default ) && 2 === Series::save_shipping( 1, Series::default_shipping( 500 ) ) );
	check( 'series 2 shipping untouched', 0 === Series::get( 2 )['shipping_ver'] && array() === Series::get( 2 )['shipping'] );

	// ---- 活动条目（内容由 M4 定义，这里只校验外形） ----
	check( 'campaign item accepted', array( 'kind' => 'coupon', 'key' => 'welcome10', 'active' => true, 'config' => array( 'code' => 'WELCOME10' ) ) === Series_Campaigns::clean_item( array( 'kind' => 'coupon', 'key' => 'WELCOME10', 'config' => array( 'code' => 'WELCOME10' ) ) ) );
	check( 'campaign unknown kind refused', null === Series_Campaigns::clean_item( array( 'kind' => 'raffle', 'key' => 'a', 'config' => array() ) ) );
	check( 'campaign bad key refused', null === Series_Campaigns::clean_item( array( 'kind' => 'coupon', 'key' => 'a b', 'config' => array() ) ) );
	check( 'campaign needs config array', null === Series_Campaigns::clean_item( array( 'kind' => 'coupon', 'key' => 'a', 'config' => 'x' ) ) );
	check( 'campaign inactive flag kept', false === Series_Campaigns::clean_item( array( 'kind' => 'wheel', 'key' => 'w', 'active' => false, 'config' => array() ) )['active'] );

	// ---- 推送计划（纯函数）：没确认差异不发商品；不接受同步的店只发基本信息 ----
	$series = array( 'id' => 1, 'slug' => 'series-1', 'name' => '系列 1', 'catalog_ver' => 3, 'template_ver' => 2, 'campaign_ver' => 1, 'shipping_ver' => 1, 'shipping' => $clean );
	$none   = Series_Push::versions( array() );
	check( 'unknown store needs header only when sync is off', array( 'header' => true, 'catalog' => false, 'campaigns' => false, 'shipping' => false ) === Series_Push::plan( $series, array( 'series_sync' => false ), $none, true ) );
	check( 'accepting store without confirmed diff gets no catalog', array( 'header' => true, 'catalog' => false, 'campaigns' => true, 'shipping' => true ) === Series_Push::plan( $series, array( 'series_sync' => true ), $none, false ) );
	check( 'accepting confirmed store gets everything', array( 'header' => true, 'catalog' => true, 'campaigns' => true, 'shipping' => true ) === Series_Push::plan( $series, array( 'series_sync' => true ), $none, true ) );
	$synced = Series_Push::versions( array( 'series_id' => 1, 'catalog_ver' => 3, 'template_ver' => 2, 'campaign_ver' => 1, 'shipping_ver' => 1 ) );
	check( 'up-to-date store needs nothing', array() === array_filter( Series_Push::plan( $series, array( 'series_sync' => true ), $synced, true ) ) );
	$behind = array_merge( $synced, array( 'catalog_ver' => 2 ) );
	check( 'price change sends catalog only', array( 'catalog' => true ) === array_filter( Series_Push::plan( $series, array( 'series_sync' => true ), $behind, true ) ) );
	$other = array_merge( $synced, array( 'series_id' => 2, 'catalog_ver' => 9 ) );
	check( 'store from another series resyncs even with higher old versions', 4 === count( array_filter( Series_Push::plan( $series, array( 'series_sync' => true ), $other, true ) ) ) );
	$body = Series_Push::body( $series, array( 'catalog' => false, 'campaigns' => false, 'shipping' => true ) );
	check( 'body carries header and shipping', 1 === $body['series_id'] && 'series-1' === $body['slug'] && 2 === $body['template_ver'] && false === $body['dry_run'] && 1 === $body['shipping']['ver'] && ! isset( $body['catalog'] ) && ! isset( $body['campaigns'] ) );

	// ---- 推送失败：记录原因并按 1 / 5 / 30 分钟重试；店铺程序太旧时不重试 ----
	$GLOBALS['scheduled'] = array();
	Series_Push::run( 1, 0, 0 );
	$state = Series_Push::site_state( 1 );
	check( 'offline store failure recorded', false === $state['ok'] && 'offline' === $state['message'] && true === $state['supported'] );
	check( 'first retry scheduled', array( Series_Push::HOOK, array( 1, 1, 0 ) ) === end( $GLOBALS['scheduled'] ) );
	$GLOBALS['scheduled'] = array();
	Series_Push::run( 1, 3, 0 );
	check( 'no fourth retry', array() === $GLOBALS['scheduled'] );
	$GLOBALS['client_reply'] = new \WP_Error( 'pchq_error', 'No route was found matching the URL and request method.' );
	Series_Push::run( 1, 0, 0 );
	check( 'old store marked unsupported without retry', false === Series_Push::site_state( 1 )['supported'] && array() === $GLOBALS['scheduled'] );

	// ---- 推送成功：记下店铺回报的版本，之后不再落后 ----
	$GLOBALS['client_reply'] = array( 'series' => array( 'series_id' => 1, 'catalog_ver' => 0, 'template_ver' => 2, 'campaign_ver' => 0, 'shipping_ver' => 2 ), 'complete' => true, 'applied' => array() );
	Series_Push::run( 2, 0, 0 );
	$state = Series_Push::site_state( 2 );
	check( 'success recorded with store versions', true === $state['ok'] && 2 === $state['synced']['shipping_ver'] && 1 === $state['synced']['series_id'] );
	check( 'sent to the sync route', '/peptide/v1/hq/series/sync' === end( $GLOBALS['requests'] )[1] && 2 === end( $GLOBALS['requests'] )[0] );
	check( 'store 2 no longer lags', ! isset( Series_Push::lagging()[2] ) );
	check( 'store never contacted still lags on header', in_array( 'header', Series_Push::lagging()[3] ?? array(), true ) );
	$GLOBALS['client_reply'] = array( 'series' => array( 'series_id' => 7 ), 'complete' => true );
	check( 'reply for another series rejected', is_wp_error( Series_Push::push( 3 ) ) );

	echo 'PASS ', $checks, " in-memory M3 series checks; no WordPress, database, or network.\n";
}
