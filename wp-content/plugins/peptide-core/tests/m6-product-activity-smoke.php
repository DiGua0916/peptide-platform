<?php
/**
 * FE-03“看过 / 已加购”接口的冒烟：在真正的 WordPress + WooCommerce + 数据库上，让几位访客真的看商品、加购，
 * 再从公开接口（GET /peptide/v1/product-activity）把数字读出来。不用浏览器。
 *
 *   wp eval-file wp-content/plugins/peptide-core/tests/m6-product-activity-smoke.php run
 *
 * 做的事：临时建一个简单商品；7 位访客通过事件接收接口上报“看了这件商品”，其中 6 位用真正的购物车加购；
 * 第 7 位再下一张收件信息全“1”的测试单。然后核对接口返回的数字、下限、开关、404、缓存头和响应里有哪些字段。
 * 结束时删掉这 7 位访客、那张订单和临时商品。按店开关 pc_product_activity 和下限用这一次运行里的过滤器设定，
 * 选项不改；会留下接口自己的一条 60 秒缓存。不带 run 只打印说明。只在开发站 / 沙盒运行，不要在正式站点运行。
 *
 * 通过标准（退出码 0）：最后一行是“结果：通过（N 项检查）”。
 *
 * @package PeptideCore
 */

use PeptideCore\Tracking\Privacy;
use PeptideCore\Tracking\Product_Activity;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;
use PeptideCore\Tracking\Tracker;

if ( ! defined( 'ABSPATH' ) || ! class_exists( Product_Activity::class ) || ! function_exists( 'rest_do_request' ) || ! function_exists( 'wc_get_product' ) ) {
	fwrite( STDERR, "请用 wp eval-file 在店铺目录里运行，并确认 peptide-core 和 WooCommerce 已启用。\n" );
	exit( 1 );
}
require_once __DIR__ . '/m6-smoke-fixtures.php';

$m6p_say  = static function ( string $text ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		\WP_CLI::log( $text );
	} else {
		echo $text, "\n";
	}
};
$m6p_stop = static function ( int $code ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		\WP_CLI::halt( $code );
	}
	exit( $code );
};

if ( 'run' !== ( $args[0] ?? '' ) ) {
	$m6p_say( '这个用例会临时建一个商品、写入 7 位测试访客和一张测试单，结束时都删掉。确认是开发站或沙盒之后，在命令最后加上 run 再运行：' );
	$m6p_say( '  wp eval-file wp-content/plugins/peptide-core/tests/m6-product-activity-smoke.php run' );
	return;
}

global $wpdb;

$m6p_fail   = array();
$m6p_checks = 0;
$m6p_check  = static function ( string $label, bool $ok, string $detail = '' ) use ( &$m6p_fail, &$m6p_checks, $m6p_say ): void {
	++$m6p_checks;
	$m6p_say( ( $ok ? '  通过  ' : '  没过  ' ) . $label . ( $ok || '' === $detail ? '' : '　实际：' . $detail ) );
	if ( ! $ok ) {
		$m6p_fail[] = $label;
	}
};

if ( ! Schema::installed() || ! Tracker::enabled() ) {
	$m6p_say( sprintf( '前提不满足：追踪表 %s，访客追踪 %s。', Schema::installed() ? '在' : '不在', Tracker::enabled() ? '开着' : '关着' ) );
	$m6p_say( '结果：没有通过（前提不满足，什么都没有写入）。' );
	$m6p_stop( 1 );
}

// 这一次运行里的设定：开关、下限都用过滤器，选项不动；邮件和对外请求一律拦下。
$m6p_on      = true;
$m6p_minimum = 5;
$m6p_switch  = static function () use ( &$m6p_on ) {
	return $m6p_on;
};
$m6p_min     = static function () use ( &$m6p_minimum ) {
	return $m6p_minimum;
};
$m6p_no_mail = static fn() => true;
$m6p_no_http = static fn( $pre, $args, $url ) => new \WP_Error( 'm6_smoke_blocked', 'blocked during the smoke run' );
add_filter( 'peptide_core_product_activity_enabled', $m6p_switch, PHP_INT_MAX );
add_filter( 'pre_option_' . Product_Activity::MIN_OPTION, $m6p_min, PHP_INT_MAX );
add_filter( 'pre_wp_mail', $m6p_no_mail, PHP_INT_MAX );
add_filter( 'pre_http_request', $m6p_no_http, PHP_INT_MAX, 3 );

$m6p_backup  = pc_m6_smoke_browser();
$m6p_product = pc_m6_smoke_temp_product();
if ( ! $m6p_product ) {
	pc_m6_smoke_browser_restore( $m6p_backup );
	$m6p_say( '结果：没有通过（建不出临时商品）。' );
	$m6p_stop( 1 );
}
$m6p_pid = (int) $m6p_product->get_id();

/** 以匿名访客的身份问接口：[ 状态码, 数据, 响应头 ]。 */
$m6p_get = static function ( $product_id ): array {
	$request = new \WP_REST_Request( 'GET', '/peptide/v1/product-activity' );
	$request->set_query_params( array( 'product_id' => $product_id ) );
	$response = rest_do_request( $request );
	return array( (int) $response->get_status(), $response->get_data(), $response->get_headers() );
};

if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
	wc_load_cart();
}

$m6p_say( 'FE-03“看过 / 已加购”接口冒烟　临时商品 #' . $m6p_pid );
$m6p_say( '' );
$m6p_say( '1. 七位访客看这件商品，其中六位加购' );
$m6p_page     = array( 'page_type' => 'product', 'path' => '/product/m6-smoke/', 'product_id' => $m6p_pid, 'product_name' => 'M6 smoke fixture' );
$m6p_visitors = array();
$m6p_cookies  = array();
$m6p_sent     = 0;
$m6p_carted   = 0;
for ( $m6p_i = 1; $m6p_i <= 7; $m6p_i++ ) {
	pc_m6_smoke_new_visitor();
	[ $m6p_status, $m6p_ack ] = pc_m6_smoke_send(
		array(
			array( 'page_view', array( 'page_type' => 'home', 'path' => '/' ), array( 'visibility' => 'visible', 'activity' => 'active' ) ),
			array( 'product_view', $m6p_page, array( 'product_id' => $m6p_pid ) ),
		)
	);
	$m6p_sent += ( 200 === $m6p_status && 2 === $m6p_ack ) ? 1 : 0;
	[ $m6p_visitor ] = pc_m6_smoke_current_visit();
	if ( $m6p_visitor > 0 ) {
		$m6p_visitors[ $m6p_i ] = $m6p_visitor;
		$m6p_cookies[ $m6p_i ]  = $_COOKIE[ Tracker::COOKIE ] ?? '';
	}
	// 第 1 位只看不买；其余六位把它加进购物车（真正的购物车，服务端自己记加购事件）。
	if ( $m6p_i >= 2 && WC()->cart ) {
		WC()->cart->empty_cart();
		$m6p_carted += WC()->cart->add_to_cart( $m6p_pid, 1 ) ? 1 : 0;
	}
}
if ( WC()->cart ) {
	WC()->cart->empty_cart();
}
$m6p_check( '七位访客的两条事件都被接口确认', 7 === $m6p_sent, (string) $m6p_sent );
$m6p_check( '七位访客各是各的', 7 === count( array_unique( $m6p_visitors ) ), wp_json_encode( $m6p_visitors ) );
$m6p_check( '六位访客加购成功', 6 === $m6p_carted, (string) $m6p_carted );
$m6p_in     = implode( ',', array_map( 'intval', $m6p_visitors ?: array( 0 ) ) );
$m6p_events = $wpdb->get_results( 'SELECT type, source, COUNT(*) AS n, COUNT(DISTINCT visitor_id) AS visitors FROM ' . Schema::table( 'events' ) . " WHERE visitor_id IN ({$m6p_in}) AND product_id = " . $m6p_pid . ' GROUP BY type, source ORDER BY type', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
$m6p_by     = array();
foreach ( (array) $m6p_events as $m6p_row ) {
	$m6p_by[ $m6p_row['type'] . '/' . $m6p_row['source'] ] = (int) $m6p_row['visitors'];
}
$m6p_check( '事件表里：7 位访客有浏览器上报的 product_view，6 位有服务端记的 add_to_cart', 7 === ( $m6p_by['product_view/client'] ?? 0 ) && 6 === ( $m6p_by['add_to_cart/server'] ?? 0 ), wp_json_encode( $m6p_by ) );

$m6p_say( '' );
$m6p_say( '2. 接口读数（匿名、不带 cookie）' );
pc_m6_smoke_new_visitor();
Product_Activity::flush();
[ $m6p_status, $m6p_data, $m6p_headers ] = $m6p_get( (string) $m6p_pid );
$m6p_check( '200；看过 7、已加购 6', 200 === $m6p_status && 7 === ( $m6p_data['viewing'] ?? null ) && 6 === ( $m6p_data['added'] ?? null ), $m6p_status . ' ' . wp_json_encode( $m6p_data ) );
$m6p_check( '响应里只有约定的六个字段，product_id 是请求的那个，数字是整数', is_array( $m6p_data ) && array( 'product_id', 'viewing', 'added', 'window', 'generated_at', 'ttl' ) === array_keys( $m6p_data ) && $m6p_pid === $m6p_data['product_id'] && is_int( $m6p_data['viewing'] ) && is_int( $m6p_data['added'] ), wp_json_encode( is_array( $m6p_data ) ? array_keys( $m6p_data ) : $m6p_data ) );
$m6p_check( '窗口如实返回（10 分钟 / 24 小时），ttl 60，generated_at 是 UTC 时间', is_array( $m6p_data ) && array( 'viewing_minutes' => Product_Activity::VIEWING_MINUTES, 'added_hours' => Product_Activity::ADDED_HOURS ) === ( $m6p_data['window'] ?? null ) && Product_Activity::TTL === ( $m6p_data['ttl'] ?? null ) && 1 === preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', (string) ( $m6p_data['generated_at'] ?? '' ) ) && abs( time() - (int) strtotime( (string) $m6p_data['generated_at'] ) ) < 120, wp_json_encode( $m6p_data ) );
$m6p_check( '响应头 Cache-Control: public, max-age=60', 'public, max-age=60' === ( $m6p_headers['Cache-Control'] ?? '' ), wp_json_encode( $m6p_headers ) );
$m6p_json = (string) wp_json_encode( $m6p_data );
$m6p_leak = false;
foreach ( $m6p_cookies as $m6p_cookie ) {
	$m6p_leak = $m6p_leak || ( '' !== $m6p_cookie && false !== strpos( $m6p_json, (string) $m6p_cookie ) );
}
$m6p_check( '响应里没有任何访客的标识', ! $m6p_leak && false === strpos( $m6p_json, 'visitor' ) && false === strpos( $m6p_json, '198.51.100.' ) );

$m6p_say( '' );
$m6p_say( '3. 下限：低于下限的数接口给 0' );
$m6p_minimum = 7;
[ , $m6p_data ] = $m6p_get( (string) $m6p_pid );
$m6p_check( '下限 7：看过 7 照给，已加购 6 给 0', 7 === ( $m6p_data['viewing'] ?? null ) && 0 === ( $m6p_data['added'] ?? null ), wp_json_encode( $m6p_data ) );
$m6p_minimum = 8;
[ , $m6p_data ] = $m6p_get( (string) $m6p_pid );
$m6p_check( '下限 8：两个数都给 0', 0 === ( $m6p_data['viewing'] ?? null ) && 0 === ( $m6p_data['added'] ?? null ), wp_json_encode( $m6p_data ) );
$m6p_minimum = 5;

$m6p_say( '' );
$m6p_say( '4. 下过测试单的访客不算' );
$m6p_order = null;
if ( isset( $m6p_cookies[7] ) ) {
	$_COOKIE[ Tracker::COOKIE ] = $m6p_cookies[7];
	$m6p_order                  = wc_create_order( array( 'status' => 'pending' ) );
	$m6p_order->add_product( $m6p_product, 1 );
	$m6p_ones = array( 'first_name' => '1', 'last_name' => '1', 'address_1' => '1', 'city' => '1', 'state' => 'CA', 'postcode' => '90001', 'country' => 'US', 'email' => 'm6-smoke@example.test' );
	$m6p_order->set_address( $m6p_ones, 'billing' );
	$m6p_order->set_address( $m6p_ones, 'shipping' );
	$m6p_order->set_payment_method( 'bacs' );
	$m6p_order->calculate_totals();
	$m6p_order->save();
	Server_Events::on_order_placed( $m6p_order ); // 下单时追踪模块挂在 WooCommerce 上的同一个处理函数：把订单挂到这位访客的访问上。
	pc_m6_smoke_new_visitor();
}
$m6p_linked = 0;
if ( $m6p_order ) {
	$m6p_linked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'visits' ) . ' WHERE visitor_id = %d AND order_id = %d', $m6p_visitors[7] ?? 0, $m6p_order->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
}
$m6p_check( '第 7 位访客的访问上挂上了那张全“1”的订单，订单被认成测试单', 1 === $m6p_linked && $m6p_order && \PeptideCore\Data\Metrics::is_test_order( $m6p_order ), (string) $m6p_linked );
[ , $m6p_data ] = $m6p_get( (string) $m6p_pid );
$m6p_check( '缓存没过期之前数字不变（仍是 7 / 6）', 7 === ( $m6p_data['viewing'] ?? null ) && 6 === ( $m6p_data['added'] ?? null ), wp_json_encode( $m6p_data ) );
Product_Activity::flush();
[ , $m6p_data ] = $m6p_get( (string) $m6p_pid );
$m6p_check( '重新算之后：看过 6、已加购 5', 6 === ( $m6p_data['viewing'] ?? null ) && 5 === ( $m6p_data['added'] ?? null ), wp_json_encode( $m6p_data ) );

$m6p_say( '' );
$m6p_say( '5. 开关和 404' );
$m6p_on = false;
[ $m6p_status, $m6p_data, $m6p_headers ] = $m6p_get( (string) $m6p_pid );
$m6p_check( '开关关着：404，正文里没有数字，同样带缓存头', 404 === $m6p_status && ! isset( $m6p_data['viewing'] ) && ! isset( $m6p_data['added'] ) && 'public, max-age=60' === ( $m6p_headers['Cache-Control'] ?? '' ), $m6p_status . ' ' . wp_json_encode( $m6p_data ) );
$m6p_on = true;
[ $m6p_status ] = $m6p_get( '999999999' );
$m6p_check( '不存在的商品：404', 404 === $m6p_status, (string) $m6p_status );
[ $m6p_status ] = $m6p_get( 'abc' );
$m6p_check( '商品编号不是数字：400', 400 === $m6p_status, (string) $m6p_status );
$m6p_check( '主题用的过滤器 pc_product_activity_available 返回 true', true === apply_filters( 'pc_product_activity_available', false ) );
$m6p_check( '全程没有数据库报错', '' === (string) $wpdb->last_error, (string) $wpdb->last_error );

$m6p_say( '' );
$m6p_say( '6. 清理' );
if ( $m6p_order ) {
	$m6p_order->delete( true );
}
if ( $m6p_visitors ) {
	Privacy::delete_visitors( array_values( array_unique( $m6p_visitors ) ) );
}
Product_Activity::flush();
[ , $m6p_data ] = $m6p_get( (string) $m6p_pid );
$m6p_left = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) . " WHERE id IN ({$m6p_in})" ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	+ (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . " WHERE visitor_id IN ({$m6p_in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
$m6p_check( '访客和事件删干净了，订单删了，接口读数回到 0 / 0', 0 === $m6p_left && ( ! $m6p_order || ! wc_get_order( $m6p_order->get_id() ) ) && 0 === ( $m6p_data['viewing'] ?? null ) && 0 === ( $m6p_data['added'] ?? null ), $m6p_left . ' ' . wp_json_encode( $m6p_data ) );

remove_filter( 'peptide_core_product_activity_enabled', $m6p_switch, PHP_INT_MAX );
remove_filter( 'pre_option_' . Product_Activity::MIN_OPTION, $m6p_min, PHP_INT_MAX );
remove_filter( 'pre_wp_mail', $m6p_no_mail, PHP_INT_MAX );
remove_filter( 'pre_http_request', $m6p_no_http, PHP_INT_MAX );
Product_Activity::flush();
pc_m6_smoke_browser_restore( $m6p_backup );

$m6p_say( '' );
if ( $m6p_fail ) {
	$m6p_say( '结果：没有通过（' . count( $m6p_fail ) . ' / ' . $m6p_checks . ' 项）。' );
	$m6p_stop( 1 );
}
$m6p_say( '结果：通过（' . $m6p_checks . ' 项检查）。接口给的是事件表里真实的访客数，下限、测试单、开关、404 和缓存头都对。' );
