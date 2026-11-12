<?php
/**
 * M3 / M10 总后台冒烟测试（HQ-05、HQ-06、HQ-13、OR-03）：在真的总后台 WordPress 里跑一遍
 * 升级可重复执行 → 系列和归属 → 主商品库 → 运费 → 推送和差异确认 → 订单上报 → 退款标记 → 报表 → 三个后台页面能渲染。
 *
 * 会写总后台的系列、订单、退款、花费数据。发往店铺的请求全部被截住（假店铺），不出网。只能在一次性的本地副本上跑：
 *   PC_SMOKE_THROWAWAY=1 [PC_SMOKE_CATALOG=<wp peptide series export 的文件>] [PC_SMOKE_ORDERS=<店铺冒烟测试 PC_SMOKE_OUT 的文件>] \
 *     wp eval-file wp-content/plugins/peptide-hq/tests/m3-m10-hq-smoke.php
 * 两个文件都不给时用内置的小样本。
 */

use PeptideHq\Db;
use PeptideHq\Design_Push;
use PeptideHq\Orders;
use PeptideHq\Refunds;
use PeptideHq\Series;
use PeptideHq\Series_Catalog;
use PeptideHq\Series_Db;
use PeptideHq\Series_Push;
use PeptideHq\Sites;
use PeptideHq\Stats;

if ( 'local' !== wp_get_environment_type() || '1' !== getenv( 'PC_SMOKE_THROWAWAY' ) ) {
	WP_CLI::error( '这个测试会写总后台的数据，只能在一次性的本地副本上跑（WP_ENVIRONMENT_TYPE=local 并设 PC_SMOKE_THROWAWAY=1）。' );
}

$checks = 0;
$check  = static function ( string $name, bool $ok, $detail = null ) use ( &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		WP_CLI::error( 'FAIL ' . $name . ( null !== $detail ? ' — ' . wp_json_encode( $detail, JSON_UNESCAPED_UNICODE ) : '' ) );
	}
};
$mine = array( 'class-series', 'class-orders', 'class-refunds', 'class-design-push', 'class-store-clone', 'class-report-page' );
set_error_handler(
	static function ( int $no, string $msg, string $file, int $line ) use ( $mine ): bool {
		foreach ( $mine as $needle ) {
			if ( str_contains( $file, $needle ) ) {
				throw new ErrorException( $msg . ' @' . basename( $file ) . ':' . $line, 0, $no, $file, $line );
			}
		}
		return false;
	}
);
global $wpdb;

// 假店铺：记下总后台发来的请求；按接口回话；可以设为离线。
$store = array( 'offline' => false, 'calls' => array(), 'series' => array(), 'refunds' => array() );
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$store ) {
		$query = array();
		parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $query );
		$route = (string) ( $query['rest_route'] ?? '' );
		if ( ! str_starts_with( $route, '/peptide/v1/hq/' ) ) {
			return new WP_Error( 'smoke_blocked', 'Only store calls are emulated in this test: ' . $route );
		}
		$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		$body = is_array( $body ) ? $body : array();
		$store['calls'][] = array( 'host' => $host, 'route' => $route, 'body' => $body );
		if ( $store['offline'] ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7: store offline' );
		}
		$reply = array();
		if ( '/peptide/v1/hq/series/sync' === $route ) {
			$s = $store['series'][ $host ] ?? array( 'series_id' => 0, 'master' => false, 'catalog_ver' => 0, 'template_ver' => 0, 'campaign_ver' => 0, 'shipping_ver' => 0 );
			if ( ! empty( $body['dry_run'] ) ) {
				$reply = array( 'series' => $s, 'applied' => array(), 'complete' => true, 'diff' => array( 'catalog_ver' => (int) $body['catalog']['ver'], 'prices_only' => ! empty( $body['master'] ), 'summary' => array( 'same' => count( $body['catalog']['products'] ) - 1, 'changed' => 1, 'create' => 0, 'unpublish' => 0 ), 'create' => array(), 'unpublish' => array(), 'changed' => array( array( 'sku' => 'X', 'name' => 'X', 'id' => 1, 'fields' => array( array( 'field' => 'price', 'store' => '1.00', 'series' => '2.00' ) ) ) ) ) );
			} else {
				$s['series_id']    = (int) $body['series_id'];
				$s['master']       = ! empty( $body['master'] );
				$s['template_ver'] = (int) $body['template_ver'];
				foreach ( array( 'catalog' => 'catalog_ver', 'campaigns' => 'campaign_ver', 'shipping' => 'shipping_ver' ) as $section => $col ) {
					if ( isset( $body[ $section ] ) ) {
						$s[ $col ] = max( $s[ $col ], (int) $body[ $section ]['ver'] );
					}
				}
				$store['series'][ $host ] = $s;
				$reply                    = array( 'series' => $s, 'applied' => array(), 'complete' => true );
			}
		} elseif ( '/peptide/v1/hq/order-refund' === $route ) {
			$store['refunds'][ $host . ':' . $body['order_id'] ] = array( 'refunded_cents' => $body['refunded_cents'], 'state' => $body['state'] );
			$reply = array( 'order_id' => $body['order_id'], 'refunded_cents' => $body['refunded_cents'], 'state' => $body['state'], 'changed' => true );
		} else {
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.' ) ), 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null );
		}
		return array( 'headers' => array(), 'body' => wp_json_encode( $reply ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	},
	99,
	3
);

$sites = Sites::all();
$check( 'the HQ copy has at least three stores', count( $sites ) >= 3, count( $sites ) );
$ids                = array_keys( $sites );
[ $a, $b, $c_site ] = array( (int) $ids[0], (int) $ids[1], (int) $ids[2] );
$host_of            = static fn( int $id ): string => (string) wp_parse_url( PeptideHq\Site_Urls::management( Sites::get( $id ) ), PHP_URL_HOST );
$admins             = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin              = (int) ( $admins[0] ?? 0 );
$check( 'there is an administrator', $admin > 0 );

// ---- 1. 升级可重复执行：表结构、系列、店铺归属都不变 ----
$schema = static function () use ( $wpdb ): array {
	$out = array();
	foreach ( array( 'sites', 'daily', 'series', 'series_products', 'series_campaigns', 'orders', 'refunds', 'ad_spend' ) as $t ) {
		$table         = Db::table( $t );
		$out[ $t ]     = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore
		$out[ $t . '#' ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
	}
	$out['series_rows'] = $wpdb->get_results( 'SELECT id, slug, name, status FROM ' . Db::table( 'series' ) . ' ORDER BY id', ARRAY_A ); // phpcs:ignore
	$out['site_series'] = $wpdb->get_results( 'SELECT id, series_id, buyer_name, series_sync FROM ' . Db::table( 'sites' ) . ' ORDER BY id', ARRAY_A ); // phpcs:ignore
	return $out;
};
$check( 'upgrade already ran on boot', Series::ready() && Orders::ready() && Series::default_id() > 0 );
$default = Series::default_id();
$check( 'every store belongs to a series after the upgrade', 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'sites' ) . ' WHERE series_id = 0' ) ); // phpcs:ignore
foreach ( array( 'series_id', 'buyer_name', 'onboarding_state', 'series_sync' ) as $col ) {
	$check( 'sites column ' . $col, in_array( $col, $schema()['sites'], true ) );
}
$check( 'daily got its two columns', in_array( 'gross_cents', $schema()['daily'], true ) && in_array( 'refunded_cents', $schema()['daily'], true ) );
$before = $schema();
foreach ( array( 1, 2 ) as $round ) {
	delete_option( Series_Db::OPTION );
	delete_option( Orders::OPTION );
	$check( 'series upgrade round ' . $round, true === Series_Db::install() );
	$check( 'orders upgrade round ' . $round, true === Orders::install() );
	$check( 'nothing changed in round ' . $round, $before === $schema(), array( $before, $schema() ) );
}

// ---- 2. 系列和归属 ----
$second = Series::create( 'Smoke 系列 ' . wp_generate_password( 4, false ) );
$check( 'second series created', is_int( $second ) && $second > $default, is_wp_error( $second ) ? $second->get_error_message() : $second );
$check( 'move a store to the second series', true === Series::assign( $c_site, $second ) && $second === Series::site( $c_site )['series_id'] && false === Series::site( $c_site )['series_sync'] );
delete_option( Series_Db::OPTION );
Series_Db::install();
$check( 'upgrade again keeps the manual assignment', $second === Series::site( $c_site )['series_id'] && $default === Series::site( $a )['series_id'] );
Series::set_buyer( $a, 'Buyer A' );
Series::set_buyer( $b, 'Buyer B' );
Series::set_buyer( $c_site, 'Buyer A' );
$check( 'buyer saved', 'Buyer A' === Series::site( $a )['buyer_name'] );
Series::update( $default, array( 'master_site_id' => $a ) );
$check( 'master store set', $a === Series::get( $default )['master_site_id'] );

// ---- 3. 主商品库：导入、重复导入、改价只动本系列 ----
$file = (string) getenv( 'PC_SMOKE_CATALOG' );
if ( '' !== $file && is_readable( $file ) ) {
	$export = json_decode( (string) file_get_contents( $file ), true );
} else {
	$v      = static fn( string $sku, int $price ): array => array( 'key' => $sku, 'sku' => $sku, 'attributes' => array( 'specifications' => $sku ), 'price_cents' => $price, 'compare_cents' => 0, 'stock_status' => 'instock', 'enabled' => true, 'image' => null, 'meta' => array() );
	$export = array( 'source_host' => 'master.example.test', 'products' => array(
		array( 'sku' => 'SMOKE-A', 'wc_sku' => 'SMOKE-A', 'type' => 'variable', 'name' => 'Smoke A', 'slug' => 'smoke-a', 'status' => 'publish', 'description' => '', 'short_description' => '', 'categories' => array(), 'menu_order' => 0, 'attributes' => array(), 'default_attributes' => array(), 'images' => array(), 'meta' => array(), 'price_cents' => 0, 'compare_cents' => 0, 'stock_status' => 'instock', 'variations' => array( $v( 'SMOKE-A-1', 4999 ), $v( 'SMOKE-A-2', 8999 ) ) ),
		array( 'sku' => 'SMOKE-B', 'wc_sku' => 'SMOKE-B', 'type' => 'simple', 'name' => 'Smoke B', 'slug' => 'smoke-b', 'status' => 'publish', 'description' => '', 'short_description' => '', 'categories' => array(), 'menu_order' => 1, 'attributes' => array(), 'default_attributes' => array(), 'images' => array(), 'meta' => array(), 'price_cents' => 1500, 'compare_cents' => 0, 'stock_status' => 'instock', 'variations' => array() ),
	) );
}
$check( 'catalog input readable', is_array( $export ) && ! empty( $export['products'] ) );
$n   = count( $export['products'] );
$imp = Series_Catalog::replace( $default, $export['products'], (string) ( $export['source_host'] ?? '' ) );
$check( 'catalog imported', is_array( $imp ) && $n === $imp['added'] && 1 === $imp['ver'] && $n === count( Series_Catalog::products( $default ) ), is_wp_error( $imp ) ? $imp->get_error_message() : $imp );
$imp2 = Series_Catalog::replace( $default, $export['products'], (string) ( $export['source_host'] ?? '' ) );
$check( 'identical import changes nothing', false === $imp2['changed'] && $n === $imp2['same'] && 1 === Series::get( $default )['catalog_ver'], $imp2 );
$first  = array_values( Series_Catalog::products( $default ) )[0];
$is_var = 'variable' === ( $first['payload']['type'] ?? '' );
$key    = $is_var ? Series_Catalog::variation_key( $first['payload']['variations'][0] ) : '';
$old    = $is_var ? (int) $first['payload']['variations'][0]['price_cents'] : (int) $first['payload']['price_cents'];
$ver    = Series_Catalog::set_prices( $default, $first['sku'], array( $key => array( 'price_cents' => $old + 100, 'compare_cents' => 0 ) ) );
$check( 'price edit bumps this series only', 2 === $ver && 2 === Series::get( $default )['catalog_ver'] && 0 === Series::get( $second )['catalog_ver'] );
$check( 'same price again keeps the version', 2 === Series_Catalog::set_prices( $default, $first['sku'], array( $key => array( 'price_cents' => $old + 100, 'compare_cents' => 0 ) ) ) );
$payload = Series_Catalog::payload( $default );
$check( 'payload has every product and the new price', $n === count( $payload['products'] ) && 2 === $payload['ver'] && ( $old + 100 ) === (int) ( $is_var ? $payload['products'][0]['variations'][0]['price_cents'] : $payload['products'][0]['price_cents'] ) );

// ---- 4. 运费两档：保存、原样再保存不加版本 ----
$check( 'shipping saved once', 1 === Series::save_shipping( $default, Series::default_shipping() ) && 1 === Series::save_shipping( $default, Series::default_shipping() ) && 2 === Series::save_shipping( $default, Series::default_shipping( 500 ) ) );
$check( 'the other series has no shipping', 0 === Series::get( $second )['shipping_ver'] );

// ---- 4b. 系列活动的存放：空的不建版本；内容没变不加版本；没改的那条保留自己的版本号 ----
$camp = static fn( string $code, int $off ): array => array( 'kind' => 'coupon', 'key' => strtolower( $code ), 'active' => true, 'config' => array( 'code' => $code, 'percent' => $off ) );
$check( 'an empty first list creates no version', 0 === PeptideHq\Series_Campaigns::replace( $second, array() ) && 0 === Series::get( $second )['campaign_ver'] );
$check( 'campaigns saved', 1 === PeptideHq\Series_Campaigns::replace( $second, array( $camp( 'WELCOME10', 10 ), $camp( 'VIP20', 20 ) ) ) && 1 === PeptideHq\Series_Campaigns::replace( $second, array( $camp( 'VIP20', 20 ), $camp( 'WELCOME10', 10 ) ) ) );
$check( 'one changed campaign bumps the list version', 2 === PeptideHq\Series_Campaigns::replace( $second, array( $camp( 'WELCOME10', 15 ), $camp( 'VIP20', 20 ) ) ) );
$sent_items = array_column( PeptideHq\Series_Campaigns::payload( $second )['items'], 'ver', 'key' );
$check( 'the unchanged campaign keeps its own version', array( 'vip20' => 1, 'welcome10' => 2 ) === $sent_items && 2 === PeptideHq\Series_Campaigns::payload( $second )['ver'], $sent_items );
$check( 'invalid campaign refused as a whole', is_wp_error( PeptideHq\Series_Campaigns::replace( $second, array( $camp( 'OK', 1 ), array( 'kind' => 'raffle', 'key' => 'x', 'config' => array() ) ) ) ) && 2 === Series::get( $second )['campaign_ver'] );

// ---- 5. 推送：没确认差异不发商品；母版店只发价格；别的系列的店收不到 ----
$last = static function () use ( &$store ) {
	return end( $store['calls'] );
};
Series_Push::run( $b, 0, 0 );
$check( 'unconfirmed store gets the header only', '/peptide/v1/hq/series/sync' === $last()['route'] && ! isset( $last()['body']['catalog'] ) && ! isset( $last()['body']['shipping'] ) && $default === $last()['body']['series_id'] && true === Series_Push::site_state( $b )['ok'], $last() );
$report = Series_Push::diff( $b );
$check( 'diff report saved for the current catalog version', is_array( $report ) && 2 === $report['ver'] && true === $last()['body']['dry_run'] && $n === count( $last()['body']['catalog']['products'] ), is_wp_error( $report ) ? $report->get_error_message() : $report['summary'] );
$check( 'confirm opens the switch', true === Series_Catalog::confirm( $default, $b, $admin ) && Series_Catalog::confirmed( $default, $b ) && true === Series::site( $b )['series_sync'] );
Series_Push::run( $b, 0, 0 );
$check( 'confirmed store gets catalog and shipping', 2 === $last()['body']['catalog']['ver'] && 2 === $last()['body']['shipping']['ver'] && false === $last()['body']['master'] && 4500 === $last()['body']['shipping']['plans'][0]['rates'][0]['fee_cents'] );
Series_Push::run( $a, 0, 0 );
$check( 'master store gets the catalog flagged as master', true === $last()['body']['master'] && isset( $last()['body']['catalog'] ) && ! isset( $last()['body']['campaigns'] ) );
Series_Push::run( $c_site, 0, 0 );
$check( 'store of the other series never gets this catalog', $second === $last()['body']['series_id'] && ! isset( $last()['body']['catalog'] ) && ! isset( $last()['body']['shipping'] ) );
$lag = Series_Push::lagging();
$check( 'pushed stores no longer lag', ! isset( $lag[ $a ] ) && ! isset( $lag[ $b ] ) && ! isset( $lag[ $c_site ] ), $lag );
Series_Catalog::set_prices( $default, $first['sku'], array( $key => array( 'price_cents' => $old + 200, 'compare_cents' => 0 ) ) );
$lag = Series_Push::lagging();
$check( 'a price change makes only this series lag', array( 'catalog' ) === ( $lag[ $a ] ?? null ) && array( 'catalog' ) === ( $lag[ $b ] ?? null ) && ! isset( $lag[ $c_site ] ), $lag );
$store['offline'] = true;
Series_Push::run( $b, 0, 0 );
$check( 'offline store: failure recorded with a retry', false === Series_Push::site_state( $b )['ok'] && '' !== Series_Push::site_state( $b )['message'] && false !== wp_next_scheduled( Series_Push::HOOK, array( $b, 1, 0 ) ) );
$store['offline'] = false;
// 改价时已经给这两家店排过同步；先清掉，单看巡检补齐会不会排。
wp_clear_scheduled_hook( Series_Push::HOOK, array( $a, 0, 0 ) );
wp_clear_scheduled_hook( Series_Push::HOOK, array( $b, 0, 0 ) );
Series_Push::reconcile();
$check( 'reconcile leaves a store alone until its retry time', false === wp_next_scheduled( Series_Push::HOOK, array( $b, 0, 0 ) ) && false !== wp_next_scheduled( Series_Push::HOOK, array( $a, 0, 0 ) ) );
Series_Push::run( $b, 1, 0 );
$check( 'retry catches the store up', true === Series_Push::site_state( $b )['ok'] && 3 === Series_Push::site_state( $b )['synced']['catalog_ver'] );

// ---- 6. 模板按系列：默认系列沿用原来的设置和店铺范围 ----
$check( 'template push is scoped to the series', count( Design_Push::sites( $default ) ) + count( Design_Push::sites( $second ) ) === count( Design_Push::sites( 0 ) ) || count( Design_Push::sites( $default ) ) < count( Sites::all() ) );
$check( 'second series has its own studio store', in_array( Design_Push::master_id( $second ), array( 0, $c_site ), true ) && $c_site !== Design_Push::master_id( $default ) );
$check( 'default series keeps the legacy option', get_option( Design_Push::OPTION, array() ) === get_option( 'pchq_design', array() ) && null === Design_Push::job( $default ) );

// ---- 7. 订单上报：重复上报不变；合计等于店铺自己的合计 ----
$orders_file = (string) getenv( 'PC_SMOKE_ORDERS' );
if ( '' !== $orders_file && is_readable( $orders_file ) ) {
	$in = json_decode( (string) file_get_contents( $orders_file ), true );
} else {
	$now = time();
	$o   = static fn( int $id, int $cents, array $x = array() ): array => array_merge( array( 'store_order_id' => $id, 'order_number' => (string) $id, 'status' => 'processing', 'paid' => true, 'is_test' => false, 'currency' => 'USD', 'total_cents' => $cents, 'created_at' => $now - 600, 'paid_at' => $now - 500, 'order_day' => wp_date( 'Y-m-d', $now - 600 ), 'series_id' => 0, 'email_hash' => str_repeat( 'a', 64 ), 'utm_source' => 'facebook', 'ad_id' => '' ), $x );
	$in  = array( 'orders' => array( $o( 9001, 15000, array( 'order_number' => 'hang-161188301' ) ), $o( 9002, 8000 ), $o( 9003, 100, array( 'is_test' => true ) ) ), 'digest' => array( 'count' => 2, 'total_cents' => 23000, 'refunded_cents' => 0, 'test_count' => 1 ) );
}
$send = static function ( int $site_id, array $orders ) {
	$req = new WP_REST_Request( 'POST', '/peptide-hq/v1/orders/report' );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_body( (string) wp_json_encode( array( 'nonce' => wp_generate_uuid4(), 'orders' => $orders ) ) );
	$req->set_param( '_pchq_site', $site_id );
	return Orders::handle_report( $req );
};
$rows = static fn( int $site_id ): array => $wpdb->get_results( $wpdb->prepare( 'SELECT site_id, store_order_id, order_number, series_id, status, paid, is_test, currency, total_cents, refunded_cents, refund_state, created_at, paid_at, order_day, email_hash, utm_source, ad_id FROM ' . Orders::table() . ' WHERE site_id = %d ORDER BY store_order_id', $site_id ), ARRAY_A ); // phpcs:ignore
$res  = $send( $b, $in['orders'] );
$check( 'report accepted', is_array( $res ) && count( $in['orders'] ) === count( $res['accepted'] ) && array() === $res['rejected'], is_wp_error( $res ) ? $res->get_error_message() : $res );
$stored = $rows( $b );
$check( 'one row per order', count( $in['orders'] ) === count( $stored ) );
$send( $b, $in['orders'] );
$check( 'repeat report changes nothing', $stored === $rows( $b ) );
$tot = Orders::site_totals()[ $b ] ?? array( 'orders' => 0, 'gross_cents' => 0 );
$check( 'HQ total equals the store digest', (int) $in['digest']['count'] === $tot['orders'] && (int) $in['digest']['total_cents'] === $tot['gross_cents'], array( $in['digest'], $tot ) );
$check( 'orders carry the series of the store when the store sent none or an unknown one', array() === array_diff( array_map( 'intval', array_column( $stored, 'series_id' ) ), array_keys( Series::all( true ) ) ) );
$check( 'a batch of 51 is refused', is_wp_error( $send( $b, array_fill( 0, 51, $in['orders'][0] ) ) ) );
$hang = null;
foreach ( $in['orders'] as $row ) {
	if ( str_starts_with( (string) $row['order_number'], 'hang-' ) ) {
		$hang = $row;
	}
}
if ( $hang ) {
	$found = Orders::search( array( 'q' => strtoupper( (string) $hang['order_number'] ) ) );
	$check( 'search by the hang- number the customer sees', 1 === $found['total'] && (int) $hang['store_order_id'] === $found['rows'][0]['store_order_id'] );
	$found = Orders::search( array( 'q' => '#' . $hang['store_order_id'], 'site_id' => $b ) );
	$check( 'search by the store order id still works', 1 === $found['total'] );
}
$paid_rows = array_values( array_filter( $in['orders'], static fn( $r ) => ! empty( $r['paid'] ) && empty( $r['is_test'] ) ) );
$check( 'there is a paid, non-test order to refund', (bool) $paid_rows );
$target = $paid_rows[0];
$oid    = (int) $target['store_order_id'];
$total  = (int) $target['total_cents'];

// ---- 8. 退款标记：部分、全额、撤销；每日净额；离线重试 ----
Stats::store( $b, array( array( 'day' => $target['order_day'], 'orders' => count( $paid_rows ), 'revenue' => array_sum( array_column( $paid_rows, 'total_cents' ) ) / 100, 'visitors' => 50 ) ), 'USD' );
$daily = static fn() => $wpdb->get_row( $wpdb->prepare( 'SELECT revenue, gross_cents, refunded_cents FROM ' . Db::table( 'daily' ) . ' WHERE site_id = %d AND day = %s', $b, $target['order_day'] ), ARRAY_A ); // phpcs:ignore
$gross = (int) round( (float) $daily()['revenue'] * 100 );
$today = wp_date( 'Y-m-d' );
$part  = (int) floor( $total / 3 );
$check( 'a non-admin cannot mark', is_wp_error( Refunds::mark( $b, $oid, $part, $today, '', 0 ) ) && 0 === Orders::get( $b, $oid )['refunded_cents'] );
$m1 = Refunds::mark( $b, $oid, $part, $today, 'chargeback', $admin );
$check( 'partial refund marked and written back', is_array( $m1 ) && true === $m1['pushed'] && $part === Orders::get( $b, $oid )['refunded_cents'] && 'partial' === Orders::get( $b, $oid )['refund_state'] && 0 === Orders::get( $b, $oid )['refund_sync'] && array( 'refunded_cents' => $part, 'state' => 'partial' ) === $store['refunds'][ $host_of( $b ) . ':' . $oid ], is_wp_error( $m1 ) ? $m1->get_error_message() : $m1 );
$check( 'daily revenue is net', $gross - $part === (int) round( (float) $daily()['revenue'] * 100 ) && $gross === (int) $daily()['gross_cents'] && $part === (int) $daily()['refunded_cents'], $daily() );
$check( 'over-refund refused', is_wp_error( Refunds::mark( $b, $oid, $total - $part + 1, $today, '', $admin ) ) );
$m2 = Refunds::mark( $b, $oid, $total - $part, $today, 'rest', $admin );
$check( 'second mark makes it full', is_array( $m2 ) && $total === Orders::get( $b, $oid )['refunded_cents'] && 'full' === Orders::get( $b, $oid )['refund_state'] && 'full' === $store['refunds'][ $host_of( $b ) . ':' . $oid ]['state'] );
Stats::store( $b, array( array( 'day' => $target['order_day'], 'orders' => count( $paid_rows ), 'revenue' => $gross / 100, 'visitors' => 50 ) ), 'USD' );
$check( 'a poll overwrites the day with gross again', $gross === (int) round( (float) $daily()['revenue'] * 100 ) && 0 === (int) $daily()['refunded_cents'] );
Refunds::apply_daily();
Refunds::apply_daily( $b );
$check( 'net is restored after the poll, repeatably', $gross - $total === (int) round( (float) $daily()['revenue'] * 100 ) && $total === (int) $daily()['refunded_cents'], $daily() );
$from = wp_date( 'Y-m-d', min( array_column( $in['orders'], 'paid_at' ) ) - DAY_IN_SECONDS );
$rep  = Orders::report( $from, $today );
$sum  = static function ( array $rows ): array {
	$t = array( 'orders' => 0, 'gross_cents' => 0, 'refunded_cents' => 0, 'net_cents' => 0, 'spend_cents' => 0 );
	foreach ( $rows as $r ) {
		foreach ( $t as $k => $x ) {
			$t[ $k ] += $r[ $k ];
		}
	}
	return $t;
};
$pick   = static fn( array $m ): array => array_intersect_key( $m, array( 'orders' => 1, 'gross_cents' => 1, 'refunded_cents' => 1, 'net_cents' => 1, 'spend_cents' => 1 ) );
$levels = static function ( array $rep ) use ( $sum, $pick ): bool {
	$stores = array();
	foreach ( $rep['series'] as $s ) {
		if ( $pick( $s['metrics'] ) != $sum( array_column( $s['buyers'], 'metrics' ) ) ) { // phpcs:ignore
			return false;
		}
		foreach ( $s['buyers'] as $buyer ) {
			if ( $pick( $buyer['metrics'] ) != $sum( $buyer['stores'] ) ) { // phpcs:ignore
				return false;
			}
			$stores = array_merge( $stores, $buyer['stores'] );
		}
	}
	return $pick( $rep['total'] ) == $sum( array_column( $rep['series'], 'metrics' ) ) && $pick( $rep['total'] ) == $sum( $stores ); // phpcs:ignore
};
$check( 'report nets the full refund and the three levels add up', $total === $rep['total']['refunded_cents'] && $rep['total']['net_cents'] === $rep['total']['gross_cents'] - $total && $levels( $rep ), $rep['total'] );
$check( 'void needs an admin', is_wp_error( Refunds::void( $m1['id'], 0 ) ) );
Refunds::void( $m1['id'], $admin );
$check( 'void one mark: partial again, record kept', $total - $part === Orders::get( $b, $oid )['refunded_cents'] && 'partial' === Orders::get( $b, $oid )['refund_state'] && 2 === count( Refunds::for_order( $b, $oid ) ) );
Refunds::void( $m2['id'], $admin );
$check( 'void all: order and day are back to gross', 0 === Orders::get( $b, $oid )['refunded_cents'] && 'none' === Orders::get( $b, $oid )['refund_state'] && $gross === (int) round( (float) $daily()['revenue'] * 100 ) && 0 === (int) $daily()['refunded_cents'] && array( 'refunded_cents' => 0, 'state' => 'none' ) === $store['refunds'][ $host_of( $b ) . ':' . $oid ] );
$check( 'void twice refused', is_wp_error( Refunds::void( $m2['id'], $admin ) ) );
$store['offline'] = true;
$m3 = Refunds::mark( $b, $oid, $part, $today, 'offline', $admin );
$check( 'store offline: marked at HQ, write-back pending', is_array( $m3 ) && false === $m3['pushed'] && 1 === Orders::get( $b, $oid )['refund_sync'] && Refunds::pending_count() >= 1 );
$store['offline'] = false;
Refunds::retry_pending();
$check( 'write-back succeeds on retry', 0 === Orders::get( $b, $oid )['refund_sync'] && $part === $store['refunds'][ $host_of( $b ) . ':' . $oid ]['refunded_cents'] );
$send( $b, $in['orders'] );
$check( 'a new report from the store does not wipe the refund', $part === Orders::get( $b, $oid )['refunded_cents'] && 'partial' === Orders::get( $b, $oid )['refund_state'] );

// ---- 9. 广告花费和 ROAS ----
$check( 'spend saved and replaced', Orders::set_spend( $b, $today, 5000, $admin ) && Orders::set_spend( $b, $today, 10000, $admin ) && 10000 === ( Orders::spend_by_site( $today, $today )[ $b ] ?? 0 ) );
$rep = Orders::report( $from, $today );
$check( 'ROAS uses net sales', 10000 === $rep['total']['spend_cents'] && round( $rep['total']['net_cents'] / 10000, 2 ) === $rep['total']['roas'] && $levels( $rep ) );
$check( 'zero removes the spend row', Orders::set_spend( $b, $today, 0, $admin ) && ! isset( Orders::spend_by_site( $today, $today )[ $b ] ) );

// ---- 10. 三个后台页面能渲染，没有警告 ----
wp_set_current_user( $admin );
$render = static function ( string $class, array $get ) use ( $mine ): string {
	$_GET = $get;
	ob_start();
	try {
		call_user_func( array( $class, 'render' ) );
	} finally {
		$html = (string) ob_get_clean();
	}
	return $html;
};
$mine[] = 'class-series-page';
$mine[] = 'class-orders-page';
$html   = $render( PeptideHq\Admin\Series_Page::class, array( 'page' => 'pchq-series', 'series' => (string) $default ) );
$check( 'series page renders', str_contains( $html, '系列管理' ) && str_contains( $html, '主商品库' ) && str_contains( $html, '两档运费' ) && str_contains( $html, 'Buyer A' ) && str_contains( $html, 'pchq_series_price' ) && ! str_contains( $html, '<b>Warning</b>' ) && ! str_contains( $html, '<b>Deprecated</b>' ), strlen( $html ) );
$html = $render( PeptideHq\Admin\Series_Page::class, array( 'page' => 'pchq-series', 'series' => (string) $default, 'report' => (string) $b ) );
$check( 'diff report view renders', str_contains( $html, '有差异的商品' ) && str_contains( $html, '成交价' ) );
$html = $render( PeptideHq\Admin\Orders_Page::class, array( 'page' => 'pchq-orders' ) );
$check( 'orders page renders', str_contains( $html, '订单与退款' ) && str_contains( $html, '各店合计' ) && str_contains( $html, '部分退款' ) && ( ! $hang || str_contains( $html, (string) $hang['order_number'] ) ) && ! str_contains( $html, '<b>Warning</b>' ), strlen( $html ) );
$html = $render( PeptideHq\Admin\Orders_Page::class, array( 'page' => 'pchq-orders', 'order' => (string) Orders::get( $b, $oid )['id'] ) );
$check( 'order detail renders with the refund form and history', str_contains( $html, 'pchq_refund_mark' ) && str_contains( $html, 'pchq_refund_void' ) && str_contains( $html, '已撤销' ) && str_contains( $html, '店内订单编号' ) );
$html = $render( PeptideHq\Admin\Report_Page::class, array( 'page' => 'pchq-report', 'from' => $from, 'to' => $today ) );
$check( 'report page renders three levels', str_contains( $html, '全部系列合计' ) && str_contains( $html, 'Buyer B' ) && str_contains( $html, 'pchq_spend_save' ) && ! str_contains( $html, '<b>Warning</b>' ), strlen( $html ) );
$_GET = array();

WP_CLI::success( 'PASS ' . $checks . ' M3 / M10 HQ smoke checks on a real HQ WordPress copy (' . count( $sites ) . ' stores, ' . $n . ' catalog products, ' . count( $in['orders'] ) . ' reported orders).' );
