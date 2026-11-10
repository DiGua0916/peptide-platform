<?php
/**
 * M3 系列主商品库（HQ-05）纯逻辑测试：从母版店导入、重复导入不加版本、改价只影响本系列、
 * 没确认差异报告不下发商品、母版店只收价格、别的系列的店收不到这个系列的商品。
 * 内存里的假数据库和假店铺，不需要 WordPress、数据库或网络。运行：php -n -d extension=mbstring m3-series-catalog-pure.php
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
	function add_action( ...$a ): void {}
	function add_filter( ...$a ): void {}
	function is_admin(): bool { return false; }
	function wp_schedule_single_event( int $when, string $hook, array $args = array() ): void { $GLOBALS['scheduled'][] = array( $hook, $args ); }
	function wp_next_scheduled( string $hook, array $args = array() ) { return false; }

	final class Memory_Db {
		public int $insert_id = 0;
		public array $tables  = array( 'wp_pchq_series' => array(), 'wp_pchq_sites' => array(), 'wp_pchq_series_products' => array(), 'wp_pchq_series_campaigns' => array() );
		public function prepare( string $sql, ...$args ): string {
			return (string) preg_replace_callback( '/%[sd]/', static function ( $m ) use ( &$args ) { $v = array_shift( $args ); return '%d' === $m[0] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'"; }, $sql );
		}
		public function get_var( string $sql ) {
			if ( preg_match( '/^SELECT id FROM (\S+) ORDER BY id ASC LIMIT 1$/', $sql, $m ) ) { $ids = array_keys( $this->tables[ $m[1] ] ); return $ids ? min( $ids ) : null; }
			if ( preg_match( '/^SELECT (\w+_ver) FROM (\S+) WHERE id = (\d+)$/', $sql, $m ) ) { return $this->tables[ $m[2] ][ (int) $m[3] ][ $m[1] ] ?? null; }
			throw new \RuntimeException( 'Unhandled get_var: ' . $sql );
		}
		public function get_row( string $sql, $format = null ): ?array {
			if ( preg_match( '/^SELECT \* FROM (\S+) WHERE id = (\d+)$/', $sql, $m ) ) { return $this->tables[ $m[1] ][ (int) $m[2] ] ?? null; }
			throw new \RuntimeException( 'Unhandled get_row: ' . $sql );
		}
		public function get_results( string $sql, $format = null ): array {
			if ( preg_match( '/^SELECT \* FROM (\S+) ORDER BY id ASC$/', $sql, $m ) ) { $rows = $this->tables[ $m[1] ]; ksort( $rows ); return array_values( $rows ); }
			if ( preg_match( '/^SELECT id, series_id, buyer_name, onboarding_state, series_sync FROM (\S+)$/', $sql, $m ) ) { return array_values( $this->tables[ $m[1] ] ); }
			if ( preg_match( '/^SELECT \* FROM (\S+) WHERE series_id = (\d+) ORDER BY position ASC, id ASC$/', $sql, $m ) ) {
				$rows = array_values( array_filter( $this->tables[ $m[1] ], static fn( $r ) => (int) $r['series_id'] === (int) $m[2] ) );
				usort( $rows, static fn( $a, $b ) => array( $a['position'], $a['id'] ) <=> array( $b['position'], $b['id'] ) );
				return $rows;
			}
			if ( preg_match( '/^SELECT kind, item_key, config, active, ver FROM (\S+) WHERE series_id = (\d+) ORDER BY kind ASC, item_key ASC$/', $sql, $m ) ) { return array(); }
			throw new \RuntimeException( 'Unhandled get_results: ' . $sql );
		}
		public function query( string $sql ) {
			if ( preg_match( '/^UPDATE (\S+) SET (\w+_ver) = \2 \+ 1, updated_at = (\d+) WHERE id = (\d+)$/', $sql, $m ) ) { ++$this->tables[ $m[1] ][ (int) $m[4] ][ $m[2] ]; return 1; }
			throw new \RuntimeException( 'Unhandled query: ' . $sql );
		}
		public function insert( string $table, array $row ) { $id = ++$this->insert_id; $this->tables[ $table ][ $id ] = array( 'id' => $id ) + $row; return 1; }
		public function update( string $table, array $fields, array $where ) { $this->tables[ $table ][ $where['id'] ] = array_replace( $this->tables[ $table ][ $where['id'] ], $fields ); return 1; }
	}
	$GLOBALS['wpdb']      = new Memory_Db();
	$GLOBALS['options']   = array( 'pchq_m3_db' => '1' );
	$GLOBALS['scheduled'] = array();
	/** 假店铺：记下每次同步收到了哪些部分，并像真店铺一样回报已同步的版本。 */
	$GLOBALS['stores']    = array();
	$GLOBALS['export']    = array();
}
namespace PeptideHq {
	final class Db { public static function table( string $name ): string { return 'wp_pchq_' . $name; } }
	final class Series_Db { public const VERSION = '1'; public const OPTION = 'pchq_m3_db'; public static function maybe_upgrade(): void {} }
	final class Monitor { public const CRON = 'pchq_poll'; }
	final class Site_Lifecycle { public static function active( int $id ): bool { return true; } }
	final class Design_Push { public static function friendly( \WP_Error $e ): string { return $e->get_error_message(); } }
	final class Sites {
		public static function all(): array { $out = array(); foreach ( $GLOBALS['wpdb']->tables['wp_pchq_sites'] as $id => $r ) { $out[ $id ] = array( 'id' => $id, 'name' => 'Store ' . $id, 'url' => 'https://s' . $id . '.example.test/', 'management_url' => '', 'public_url' => '', 'status' => 'online' ); } return $out; }
		public static function get( int $id ): ?array { return self::all()[ $id ] ?? null; }
		public static function update( int $id, array $fields ): void { $GLOBALS['wpdb']->update( 'wp_pchq_sites', $fields, array( 'id' => $id ) ); }
	}
	final class Client {
		public static function request( int $site_id, string $method, string $route, array $query = array(), ?array $body = null, int $timeout = 20 ) {
			if ( '/peptide/v1/hq/catalog-export' === $route ) { return $GLOBALS['export']; }
			if ( '/peptide/v1/hq/series/sync' !== $route ) { throw new \RuntimeException( 'Unexpected route ' . $route ); }
			$store = $GLOBALS['stores'][ $site_id ] ?? array( 'series_id' => 0, 'master' => false, 'catalog_ver' => 0, 'template_ver' => 0, 'campaign_ver' => 0, 'shipping_ver' => 0, 'log' => array() );
			$got   = array_values( array_intersect( array( 'catalog', 'campaigns', 'shipping' ), array_keys( $body ) ) );
			if ( ! empty( $body['dry_run'] ) ) {
				return array( 'series' => $store, 'applied' => array(), 'complete' => true, 'diff' => array( 'catalog_ver' => $body['catalog']['ver'], 'prices_only' => $body['master'], 'summary' => array( 'same' => 1, 'changed' => 1, 'create' => 0, 'unpublish' => 0 ), 'create' => array(), 'unpublish' => array(), 'changed' => array( array( 'sku' => 'VC-BPC157', 'name' => 'BPC-157', 'id' => 11, 'fields' => array( array( 'field' => 'price', 'store' => '49.99', 'series' => '44.99' ) ) ) ) ) );
			}
			$store['series_id']    = $body['series_id'];
			$store['master']       = $body['master'];
			$store['template_ver'] = $body['template_ver'];
			foreach ( array( 'catalog' => 'catalog_ver', 'campaigns' => 'campaign_ver', 'shipping' => 'shipping_ver' ) as $section => $col ) {
				if ( isset( $body[ $section ] ) ) { $store[ $col ] = max( $store[ $col ], $body[ $section ]['ver'] ); }
			}
			$store['log'][] = array( 'sections' => $got, 'products' => isset( $body['catalog'] ) ? array_column( $body['catalog']['products'], 'sku' ) : array(), 'master' => $body['master'], 'series_id' => $body['series_id'] );
			$GLOBALS['stores'][ $site_id ] = $store;
			return array( 'series' => array_diff_key( $store, array( 'log' => 1 ) ), 'applied' => array(), 'complete' => true );
		}
	}
}
namespace {
	foreach ( array( 'series', 'series-campaigns', 'series-catalog', 'series-push' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
	use PeptideHq\Series;
	use PeptideHq\Series_Catalog as C;
	use PeptideHq\Series_Push;

	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }
	$db = $GLOBALS['wpdb'];
	foreach ( array( 1 => 'series-1', 2 => 'series-2' ) as $id => $slug ) {
		$db->tables['wp_pchq_series'][ $id ] = array( 'id' => $id, 'slug' => $slug, 'name' => 'Series ' . $id, 'status' => 'active', 'master_site_id' => 0, 'catalog_ver' => 0, 'template_ver' => 0, 'campaign_ver' => 0, 'shipping_ver' => 0, 'shipping' => null, 'created_at' => 1, 'updated_at' => 1 );
	}
	foreach ( array( 1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2 ) as $site => $series ) {
		$db->tables['wp_pchq_sites'][ $site ] = array( 'id' => $site, 'series_id' => $series, 'buyer_name' => '', 'onboarding_state' => '', 'series_sync' => 0 );
	}
	$db->insert_id = 100;

	$var  = static fn( string $sku, string $spec, int $price, int $compare = 0, array $meta = array() ): array => array( 'key' => $sku, 'sku' => $sku, 'attributes' => array( 'specifications' => $spec ), 'price_cents' => $price, 'compare_cents' => $compare, 'stock_status' => 'instock', 'enabled' => true, 'image' => null, 'meta' => $meta );
	$prod = static fn( string $sku, string $name, array $vars ): array => array( 'sku' => $sku, 'wc_sku' => $sku, 'type' => 'variable', 'name' => $name, 'slug' => strtolower( $sku ), 'status' => 'publish', 'description' => '<p>x</p>', 'short_description' => '', 'categories' => array( 'Peptides' ), 'menu_order' => 0, 'attributes' => array(), 'default_attributes' => array(), 'images' => array(), 'meta' => array( '_pc_spu' => $sku ), 'price_cents' => 0, 'compare_cents' => 0, 'stock_status' => 'instock', 'variations' => $vars );
	$bpc  = $prod( 'VC-BPC157', 'BPC-157', array( $var( 'VC-BPC157-5', '5mg', 4999, 5999, array( '_pc_pricing' => array( 'base' => '49.99', 'compare' => '59.99', 'source' => 'documented' ) ) ), $var( 'VC-BPC157-10', '10mg', 8999 ) ) );
	$tb   = $prod( 'VC-TB500', 'TB-500', array( $var( 'VC-TB500-5', '5mg', 3999 ) ) );
	$GLOBALS['export'] = array( 'version' => 1, 'source_host' => 'store1.example.test', 'products' => array( $bpc, $tb ), 'shipping' => Series::default_shipping(), 'campaigns' => array() );

	// ---- 纯函数 ----
	check( 'summary shows the cheapest enabled variation', array( 'sku' => 'VC-BPC157', 'name' => 'BPC-157', 'price_cents' => 4999, 'compare_cents' => 5999, 'variations' => 2 ) === C::summarize( $bpc ) );
	check( 'invalid products refused', null === C::summarize( array( 'sku' => '', 'name' => 'x' ) ) && null === C::summarize( array( 'sku' => 'x', 'name' => 'x', 'type' => 'variable', 'variations' => array() ) ) && null === C::summarize( array( 'sku' => 'x', 'name' => 'x', 'type' => 'variable', 'variations' => array( array( 'attributes' => array(), 'price_cents' => 1 ) ) ) ) && null === C::summarize( 'x' ) );
	check( 'simple product summary', 1500 === C::summarize( array( 'sku' => 's', 'name' => 'S', 'type' => 'simple', 'price_cents' => 1500 ) )['price_cents'] );
	[ $changed_payload, $did ] = C::with_prices( $bpc, array( 'VC-BPC157-5' => array( 'price_cents' => 4499, 'compare_cents' => 5999 ) ) );
	check( 'price change touches only that variation and drops the stale price record', true === $did && 4499 === $changed_payload['variations'][0]['price_cents'] && ! isset( $changed_payload['variations'][0]['meta']['_pc_pricing'] ) && $bpc['variations'][1] === $changed_payload['variations'][1] );
	check( 'same price is not a change', false === C::with_prices( $bpc, array( 'VC-BPC157-5' => array( 'price_cents' => 4999, 'compare_cents' => 5999 ) ) )[1] && false === C::with_prices( $bpc, array( 'UNKNOWN' => array( 'price_cents' => 1 ) ) )[1] );
	check( 'compare price not above price is dropped', 0 === C::with_prices( $bpc, array( 'VC-BPC157-10' => array( 'price_cents' => 8000, 'compare_cents' => 7000 ) ) )[0]['variations'][1]['compare_cents'] );

	// ---- 从母版店导入：母版店必须在系列里；重复导入不加版本 ----
	check( 'master must belong to the series', is_wp_error( C::import_from_store( 1, 4, array( 'products' ) ) ) && 0 === Series::get( 1 )['catalog_ver'] );
	$first = C::import_from_store( 1, 1, array( 'products', 'shipping' ) );
	check( 'import fills the master catalog', is_array( $first ) && 2 === $first['products']['added'] && 1 === $first['products']['ver'] && 1 === $first['shipping'] && null === $first['campaigns'] );
	check( 'series 1 versions bumped, master recorded, series 2 untouched', 1 === Series::get( 1 )['catalog_ver'] && 1 === Series::get( 1 )['shipping_ver'] && 1 === Series::get( 1 )['master_site_id'] && 0 === Series::get( 2 )['catalog_ver'] && 0 === Series::get( 2 )['shipping_ver'] );
	check( 'catalog readable', array( 'VC-BPC157', 'VC-TB500' ) === array_keys( C::products( 1 ) ) && array() === C::products( 2 ) && 'store1.example.test' === C::source_host( 1 ) );
	$again = C::import_from_store( 1, 1, array( 'products', 'shipping' ) );
	check( 'identical import changes nothing', false === $again['products']['changed'] && 2 === $again['products']['same'] && 1 === Series::get( 1 )['catalog_ver'] && 1 === Series::get( 1 )['shipping_ver'] );
	$bad = $GLOBALS['export'];
	$bad['products'][] = array( 'sku' => 'BROKEN', 'name' => '' );
	$GLOBALS['export'] = $bad;
	check( 'one invalid product rejects the whole import', is_wp_error( C::import_from_store( 1, 1, array( 'products' ) ) ) && 2 === count( C::products( 1 ) ) && 1 === Series::get( 1 )['catalog_ver'] );
	array_pop( $bad['products'] );
	$GLOBALS['export'] = $bad;

	// ---- 改一个系列的价格：只有这个系列的版本号变 ----
	$ver = C::set_prices( 1, 'VC-BPC157', array( 'VC-BPC157-5' => array( 'price_cents' => 4499, 'compare_cents' => 5999 ) ) );
	check( 'price edit bumps series 1 only', 2 === $ver && 2 === Series::get( 1 )['catalog_ver'] && 0 === Series::get( 2 )['catalog_ver'] );
	check( 'same price again keeps the version', 2 === C::set_prices( 1, 'VC-BPC157', array( 'VC-BPC157-5' => array( 'price_cents' => 4499, 'compare_cents' => 5999 ) ) ) );
	check( 'unknown product refused', is_wp_error( C::set_prices( 1, 'NOPE', array() ) ) && is_wp_error( C::set_prices( 2, 'VC-BPC157', array() ) ) );
	$payload = C::payload( 1 );
	check( 'payload carries the new price and version', 2 === $payload['ver'] && 'store1.example.test' === $payload['source_host'] && 4499 === $payload['products'][0]['variations'][0]['price_cents'] && 4499 === C::products( 1 )['VC-BPC157']['price_cents'] );
	check( 'series 2 payload is empty', array() === C::payload( 2 )['products'] && 0 === C::payload( 2 )['ver'] );

	// ---- 差异报告和确认：没有报告或报告过期都不能确认 ----
	check( 'cannot confirm without a report', is_wp_error( C::confirm( 1, 2, 9 ) ) && ! C::confirmed( 1, 2 ) );
	C::save_diff( 1, 2, 1, array( 'summary' => array( 'same' => 1 ) ) );
	check( 'stale report cannot be confirmed', is_wp_error( C::confirm( 1, 2, 9 ) ) && ! C::confirmed( 1, 2 ) && false === Series::site( 2 )['series_sync'] );
	$report = Series_Push::diff( 2 );
	check( 'dry run report saved for the current version', is_array( $report ) && 2 === $report['ver'] && 1 === $report['summary']['changed'] && 'price' === $report['changed'][0]['fields'][0]['field'] && array() === ( $GLOBALS['stores'][2]['log'] ?? array() ) );
	check( 'store of another series cannot be confirmed here', is_wp_error( C::confirm( 1, 4, 9 ) ) );
	check( 'confirm opens the sync switch', true === C::confirm( 1, 2, 9 ) && C::confirmed( 1, 2 ) && true === Series::site( 2 )['series_sync'] && 9 === C::confirmation( 1, 2 )['by'] );

	// ---- 推送：商品只发给已确认的店和母版店；别的系列的店收不到 ----
	foreach ( array( 1, 2, 3, 4, 5 ) as $site ) {
		Series_Push::run( $site, 0, 0 );
	}
	$log = static fn( int $site ): array => end( $GLOBALS['stores'][ $site ]['log'] );
	check( 'master store gets the catalog as master, no shipping until it accepts', array( 'catalog' ) === $log( 1 )['sections'] && true === $log( 1 )['master'] );
	check( 'confirmed store gets catalog and shipping', array( 'catalog', 'shipping' ) === $log( 2 )['sections'] && false === $log( 2 )['master'] && array( 'VC-BPC157', 'VC-TB500' ) === $log( 2 )['products'] );
	check( 'unconfirmed store gets the header only', array() === $log( 3 )['sections'] && 1 === $log( 3 )['series_id'] );
	check( 'stores of series 2 never receive series 1 products', array() === $log( 4 )['sections'] && array() === $log( 5 )['sections'] && 2 === $log( 4 )['series_id'] && 0 === $GLOBALS['stores'][4]['catalog_ver'] );
	check( 'nothing lags after the push', array() === Series_Push::lagging() );

	// ---- 再改一次价格：只有系列 1 里该收商品的店落后 ----
	C::set_prices( 1, 'VC-TB500', array( 'VC-TB500-5' => array( 'price_cents' => 3499, 'compare_cents' => 0 ) ) );
	check( 'only series 1 stores that take the catalog lag', array( 1 => array( 'catalog' ), 2 => array( 'catalog' ) ) === Series_Push::lagging() );
	$queued = array_map( static fn( $e ) => $e[1][0], array_filter( $GLOBALS['scheduled'], static fn( $e ) => Series_Push::HOOK === $e[0] ) );
	check( 'series 2 stores were never queued by series 1 edits', ! in_array( 4, $queued, true ) && ! in_array( 5, $queued, true ) && in_array( 2, $queued, true ) );
	foreach ( array( 1, 2, 3, 4, 5 ) as $site ) {
		Series_Push::run( $site, 0, 0 );
	}
	check( 'stores catch up to version 3', 3 === $GLOBALS['stores'][2]['catalog_ver'] && 3 === $GLOBALS['stores'][1]['catalog_ver'] && 0 === $GLOBALS['stores'][3]['catalog_ver'] && 0 === $GLOBALS['stores'][4]['catalog_ver'] && array() === Series_Push::lagging() );

	// ---- 关闭同步、移出商品 ----
	C::unconfirm( 1, 2 );
	C::set_prices( 1, 'VC-TB500', array( 'VC-TB500-5' => array( 'price_cents' => 3999, 'compare_cents' => 0 ) ) );
	check( 'switched-off store no longer lags', array( 1 => array( 'catalog' ) ) === Series_Push::lagging() && ! C::confirmed( 1, 2 ) );
	$v = C::set_status( 1, 'VC-TB500', false );
	check( 'removed product leaves the payload but stays on record', 5 === $v && array( 'VC-BPC157' ) === array_column( C::payload( 1 )['products'], 'sku' ) && 'removed' === C::products( 1, true )['VC-TB500']['status'] );
	check( 'last product cannot be removed', is_wp_error( C::set_status( 1, 'VC-BPC157', false ) ) );
	check( 'product can be put back', 6 === C::set_status( 1, 'VC-TB500', true ) && 2 === count( C::payload( 1 )['products'] ) );
	$back = C::import_from_store( 1, 1, array( 'products' ) );
	check( 're-import takes the master prices again', true === $back['products']['changed'] && 4999 === C::products( 1 )['VC-BPC157']['payload']['variations'][0]['price_cents'] && 7 === Series::get( 1 )['catalog_ver'] );

	echo 'PASS ', $checks, " in-memory M3 series catalog checks; no WordPress, database, or network.\n";
}
