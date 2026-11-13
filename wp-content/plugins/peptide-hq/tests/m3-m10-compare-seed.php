<?php
/**
 * M3 / M10 发布前比对（总后台）的固定数据：只用线上版（11-10）就有的接口写入，这样线上版和发布版读的是同一份数据。
 *
 *   PC_SMOKE_THROWAWAY=1 [PC_COMPARE_CATALOG=<店铺 wp peptide series export 的文件>] \
 *   wp eval-file wp-content/plugins/peptide-hq/tests/m3-m10-compare-seed.php
 *
 * 会写数据：只能在一次性的本地副本上跑（WP_ENVIRONMENT_TYPE=local 并设 PC_SMOKE_THROWAWAY=1）。不发网络请求。
 * 日期固定在 2026-11-08 到 2026-11-12，比对时用这段日期。
 *
 * @package PeptideHq
 */

use PeptideHq\Orders;
use PeptideHq\Refunds;
use PeptideHq\Series;
use PeptideHq\Series_Catalog;
use PeptideHq\Sites;
use PeptideHq\Stats;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
if ( 'local' !== wp_get_environment_type() || '1' !== getenv( 'PC_SMOKE_THROWAWAY' ) ) {
	WP_CLI::error( '这个脚本会写总后台的数据，只能在一次性的本地副本上跑（WP_ENVIRONMENT_TYPE=local 并设 PC_SMOKE_THROWAWAY=1）。' );
}
add_filter( 'pre_http_request', static fn() => new WP_Error( 'seed_blocked', 'store offline during seed' ), 1 );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ) );
$admin  = (int) ( $admins[0] ?? 0 );
wp_set_current_user( $admin );
$ids = array_keys( Sites::all() );
if ( count( $ids ) < 3 || ! Orders::ready() ) {
	WP_CLI::error( '需要至少 3 家店，而且订单表已经装好。' );
}
[ $a, $b, $c ] = array( (int) $ids[0], (int) $ids[1], (int) $ids[2] );

// ---- 系列：默认系列 + 第二个系列；投手 ----
$default = Series::default_id();
$second  = Series::create( '对比系列 2' );
if ( ! is_int( $second ) ) {
	WP_CLI::error( '建不了第二个系列。' );
}
Series::assign( $c, $second );
Series::set_buyer( $a, 'Buyer A' );
Series::set_buyer( $b, 'Buyer B' );
Series::set_buyer( $c, 'Buyer A' );

$file = (string) getenv( 'PC_COMPARE_CATALOG' );
if ( '' !== $file && is_readable( $file ) ) {
	$export = json_decode( (string) file_get_contents( $file ), true );
	$res    = Series_Catalog::replace( $default, (array) $export['products'], (string) ( $export['source_host'] ?? '' ) );
	if ( is_wp_error( $res ) ) {
		WP_CLI::error( '导入商品失败：' . $res->get_error_message() );
	}
	$first = array_values( Series_Catalog::products( $default ) )[0];
	if ( 'variable' === ( $first['payload']['type'] ?? '' ) ) {
		$v = $first['payload']['variations'][0];
		Series_Catalog::set_prices( $default, $first['sku'], array( Series_Catalog::variation_key( $v ) => array( 'price_cents' => (int) $v['price_cents'] + 100, 'compare_cents' => 0 ) ) );
	} else {
		Series_Catalog::set_prices( $default, $first['sku'], array( '' => array( 'price_cents' => (int) $first['payload']['price_cents'] + 100, 'compare_cents' => 0 ) ) );
	}
}
Series::save_shipping( $default, Series::default_shipping() );

// ---- 订单：三家店，五天，含 hang- 号、老数字号、测试单、没付款的 ----
$tz  = wp_timezone();
$at  = static fn( string $day, int $hour ): int => ( new DateTimeImmutable( "$day $hour:00:00", $tz ) )->getTimestamp();
$o   = static function ( int $id, string $number, string $day, int $cents, array $x = array() ) use ( $at ): array {
	return array_merge(
		array(
			'store_order_id' => $id, 'order_number' => $number, 'status' => 'processing', 'paid' => true, 'is_test' => false, 'currency' => 'USD',
			'total_cents' => $cents, 'created_at' => $at( $day, 10 ), 'paid_at' => $at( $day, 10 ) + 120, 'order_day' => $day, 'series_id' => 0,
			'email_hash' => hash( 'sha256', "buyer-$id@example.test" ), 'utm_source' => 'facebook', 'ad_id' => '1202' . $id,
		),
		$x
	);
};
$send = static function ( int $site_id, array $orders ) {
	$req = new WP_REST_Request( 'POST', '/peptide-hq/v1/orders/report' );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_body( (string) wp_json_encode( array( 'nonce' => 'seed', 'orders' => $orders ) ) );
	$req->set_param( '_pchq_site', $site_id );
	return Orders::handle_report( $req );
};
$batches = array(
	$a => array(
		$o( 1201, '1201', '2026-11-08', 12000 ),
		$o( 1202, 'hang-161188301', '2026-11-09', 25000 ),
		$o( 1203, 'hang-161188302', '2026-11-10', 8000, array( 'email_hash' => hash( 'sha256', 'buyer-1201@example.test' ) ) ),
		$o( 1204, 'hang-161188303', '2026-11-11', 100, array( 'is_test' => true ) ),
		$o( 1205, '1205', '2026-11-12', 4500, array( 'status' => 'pending', 'paid' => false, 'paid_at' => 0 ) ),
	),
	$b => array(
		$o( 2301, 'hang-161190001', '2026-11-09', 30000, array( 'utm_source' => 'google', 'ad_id' => '' ) ),
		$o( 2302, 'hang-161190002', '2026-11-10', 15000 ),
		$o( 2303, '2303', '2026-11-12', 9900, array( 'status' => 'completed' ) ),
	),
	$c => array(
		$o( 3401, 'hang-161195001', '2026-11-10', 50000 ),
		$o( 3402, 'hang-161195002', '2026-11-11', 7000, array( 'email_hash' => '' ) ),
	),
);
$accepted = 0;
foreach ( $batches as $site_id => $orders ) {
	$res = $send( (int) $site_id, $orders );
	if ( ! is_array( $res ) ) {
		WP_CLI::error( '上报失败：' . ( is_wp_error( $res ) ? $res->get_error_message() : '' ) );
	}
	$accepted += count( $res['accepted'] );
}

// ---- 退款标记：部分、全额、标了又撤销 ----
$m1 = Refunds::mark( $a, 1202, 5000, '2026-11-10', 'partial', $admin );
$m2 = Refunds::mark( $b, 2302, 15000, '2026-11-11', 'full', $admin );
$m3 = Refunds::mark( $c, 3401, 10000, '2026-11-11', 'voided later', $admin );
if ( is_array( $m3 ) ) {
	Refunds::void( (int) $m3['id'], $admin );
}

// ---- 每日访客（店铺汇总） ----
foreach ( array( $a => 40, $b => 25, $c => 10 ) as $site_id => $visitors ) {
	$days = array();
	foreach ( array( '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12' ) as $i => $day ) {
		$days[] = array( 'day' => $day, 'orders' => 1, 'revenue' => 100, 'visitors' => $visitors + $i );
	}
	Stats::store( (int) $site_id, $days, 'USD' );
}
if ( class_exists( Refunds::class ) && is_callable( array( Refunds::class, 'apply_daily' ) ) ) {
	Refunds::apply_daily();
}

// ---- 广告花费：手填和店铺推送（素材归因）各一种；推送想盖手填的那天 ----
Orders::set_spend( $a, '2026-11-10', 5000, $admin );
Orders::set_spend( $a, '2026-11-11', 2500, $admin );
if ( class_exists( PeptideHq\Creatives::class ) ) {
	$push = static function ( int $site_id, array $days ) {
		$req = new WP_REST_Request( 'POST', '/peptide-hq/v1/creatives/spend' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( (string) wp_json_encode( array( 'days' => $days ) ) );
		$req->set_param( '_pchq_site', $site_id );
		return PeptideHq\Creatives::handle_spend( $req );
	};
	$push( $b, array( array( 'day' => '2026-11-10', 'spend_cents' => 12000 ), array( 'day' => '2026-11-11', 'spend_cents' => 0 ) ) );
	$push( $a, array( array( 'day' => '2026-11-10', 'spend_cents' => 99900 ) ) );
}

WP_CLI::success( sprintf( '固定数据已写入：3 家店（%d、%d、%d），%d 笔订单，3 条退款标记（1 条已撤销），花费 %d 行。', $a, $b, $c, $accepted, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Orders::spend_table() ) ) ); // phpcs:ignore
