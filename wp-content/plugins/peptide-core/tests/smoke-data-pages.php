<?php
/**
 * 页面访问统计页（DATA-01 第 5 步）冒烟测试：每个页面路径的访问量、访问用户、跳出率（只算时间段内的 page_view 和访问，前一天不计入）、
 * 排序、按路径搜索（不分大小写）、分页、sort / per_page / page 参数校验、GET /peptide/v1/data/pages 接口和权限、页面脚本的加载。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-data-pages.php
 * 测试数据都放在固定的历史日期（店铺时区 2020-05-10 为本期，2020-05-09 单独查前一天），不受站点上其他数据影响；
 * 需要访客追踪的表已安装；测试创建的用户、访客、访问和事件在结束时彻底删除（用 Privacy::delete_visitors() 清理）。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Data_Pages;
use PeptideCore\Data\Page_Visits;
use PeptideCore\Data\Range;
use PeptideCore\Tracking\Privacy;
use PeptideCore\Tracking\Schema;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};
// 比例：差不到 0.01 就算相等；null 只等于 null。
$eq = static function ( $got, $want ): bool {
	if ( null === $want || null === $got ) {
		return null === $want && null === $got;
	}
	return abs( (float) $got - (float) $want ) < 0.01;
};

global $wpdb;
$get_backup = $_GET;
$admin      = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );

// 店铺时区的某个时刻 => Unix 秒。
$at = static fn( string $local ) => ( new DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();

if ( ! Schema::installed() ) {
	echo "FAIL tracking tables not installed\n";
	return;
}

// 访客 W1–W3。
$visitor_ids = array();
$visitor     = static function ( int $n ) use ( $wpdb, $at, &$visitor_ids ): int {
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'visitors' ),
		array(
			'vid'      => md5( 'smoke-pv-' . $n . microtime() ),
			'first_ms' => $at( '2020-05-09 08:00' ) * 1000,
			'last_ms'  => $at( '2020-05-10 20:00' ) * 1000,
			'visits'   => 1,
			'device'   => 'desktop',
			'browser'  => 'Chrome',
			'is_test'  => 1,
		)
	);
	$visitor_ids[] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
};
$w1 = $visitor( 1 );
$w2 = $visitor( 2 );
$w3 = $visitor( 3 );

// 访问：落地页和页面数（pageviews）按需要设置。
$visit_ids = array();
$visit     = static function ( int $visitor_id, string $local, string $landing, int $pageviews ) use ( $wpdb, $at, &$visit_ids ): int {
	$start = $at( $local ) * 1000;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'visits' ),
		array(
			'visitor_id'   => $visitor_id,
			'start_ms'     => $start,
			'last_ms'      => $start + 60000,
			'landing_path' => $landing,
			'device'       => 'desktop',
			'pageviews'    => $pageviews,
			'is_test'      => 1,
		)
	);
	$visit_ids[] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
};

// 页面浏览事件（page_view）。
$event_ids = array();
$event     = static function ( int $visitor_id, int $visit_id, string $local, string $path ) use ( $wpdb, $at, &$event_ids ): void {
	$ms = $at( $local ) * 1000;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'events' ),
		array(
			'event_uid'   => 'smoke-pv-' . count( $event_ids ) . '-' . md5( microtime() . $local . $path ),
			'visitor_id'  => $visitor_id,
			'visit_id'    => $visit_id,
			'type'        => 'page_view',
			'source'      => 'client',
			'occurred_ms' => $ms,
			'received_ms' => $ms,
			'page_type'   => 'content',
			'path'        => $path,
			'is_test'     => 1,
		)
	);
	$event_ids[] = (int) $wpdb->insert_id;
};

$a = '/smoke-pv-a/';
$b = '/smoke-pv-b/';

// W1：2020-05-10 10:00 访问，落地页 A，看了 2 个页面（A、B）。
$v1 = $visit( $w1, '2020-05-10 10:00', $a, 2 );
$event( $w1, $v1, '2020-05-10 10:00', $a );
$event( $w1, $v1, '2020-05-10 10:01', $b );
// W2：2020-05-10 11:00 访问，落地页 A，只看了 A（跳出）。
$v2 = $visit( $w2, '2020-05-10 11:00', $a, 1 );
$event( $w2, $v2, '2020-05-10 11:00', $a );
// W3：2020-05-10 12:00 访问，落地页 B，看了 3 个页面（B、B、A）。
$v3 = $visit( $w3, '2020-05-10 12:00', $b, 3 );
$event( $w3, $v3, '2020-05-10 12:00', $b );
$event( $w3, $v3, '2020-05-10 12:05', $b );
$event( $w3, $v3, '2020-05-10 12:10', $a );
// W1：前一天（2020-05-09）10:00 访问，落地页 A，只看了 A（本期不计入）。
$v4 = $visit( $w1, '2020-05-09 10:00', $a, 1 );
$event( $w1, $v4, '2020-05-09 10:00', $a );

// 查一天的表（$extra 是 sort / order / page / per_page / q）。
$table = static function ( string $day, array $extra = array() ): array {
	$r = Range::from_params( array( 'from' => $day, 'to' => $day ) );
	$p = Page_Visits::params( $extra );
	return is_array( $r ) && is_array( $p ) ? Page_Visits::table( $r, $p ) : array();
};
$pick = static function ( array $t, string $path ): array {
	foreach ( (array) ( $t['rows'] ?? array() ) as $row ) {
		if ( $path === $row['path'] ) {
			return $row;
		}
	}
	return array();
};
$paths = static fn( array $t ): array => array_column( (array) ( $t['rows'] ?? array() ), 'path' );

$t  = $table( '2020-05-10' );
$ra = $pick( $t, $a );
$rb = $pick( $t, $b );

// 1. 路径 A：访问量 3、访问用户 3、跳出用户 1、跳出率 33.33。
$ok( 'A: views 3, visitors 3, bounced 1, bounce_rate 33.33', 3 === ( $ra['views'] ?? null ) && 3 === ( $ra['visitors'] ?? null ) && 1 === ( $ra['bounced'] ?? null ) && $eq( $ra['bounce_rate'] ?? null, 33.33 ) );

// 2. 路径 B：访问量 3、访问用户 2、跳出用户 0、跳出率 0.0（不是 null）。
$ok( 'B: views 3, visitors 2, bounced 0, bounce_rate 0.0 (a float, not null)', 3 === ( $rb['views'] ?? null ) && 2 === ( $rb['visitors'] ?? null ) && 0 === ( $rb['bounced'] ?? null ) && array_key_exists( 'bounce_rate', $rb ) && is_float( $rb['bounce_rate'] ) && $eq( $rb['bounce_rate'], 0.0 ) );

// 3. 总数和默认顺序：访问量相同按路径从小到大。
$ok( 'total is 2 and the default order is A, B (views tie, then path)', 2 === ( $t['total'] ?? null ) && array( $a, $b ) === $paths( $t ) && 'views' === ( $t['sort'] ?? null ) && 'desc' === ( $t['order'] ?? null ) && true === ( $t['tracking'] ?? null ) );

// 4. 排序。
$ok( 'sort=visitors puts A first', $a === ( $paths( $table( '2020-05-10', array( 'sort' => 'visitors' ) ) )[0] ?? '' ) );
$ok( 'sort=bounce_rate&order=asc puts B first', $b === ( $paths( $table( '2020-05-10', array( 'sort' => 'bounce_rate', 'order' => 'asc' ) ) )[0] ?? '' ) );

// 5. 搜索：不分大小写，在分页之前。
$tq = $table( '2020-05-10', array( 'q' => 'PV-B' ) );
$ok( 'q=PV-B (case-insensitive) keeps only B and total is 1', array( $b ) === $paths( $tq ) && 1 === ( $tq['total'] ?? null ) && 'PV-B' === ( $tq['q'] ?? null ) );

// 6. 分页。
$tp2 = $table( '2020-05-10', array( 'per_page' => 1, 'page' => 2 ) );
$tp3 = $table( '2020-05-10', array( 'per_page' => 1, 'page' => 3 ) );
$ok( 'per_page=1&page=2 returns only B', array( $b ) === $paths( $tp2 ) && 2 === ( $tp2['page'] ?? null ) && 1 === ( $tp2['per_page'] ?? null ) );
$ok( 'a page past the end has no rows and total stays 2', array() === ( $tp3['rows'] ?? null ) && 2 === ( $tp3['total'] ?? null ) );

// 7. 链接：协议 + 域名 + 存下来的路径。
$ok( 'url of A starts with http and ends with the stored path', 0 === strpos( (string) ( $ra['url'] ?? '' ), 'http' ) && '/smoke-pv-a/' === substr( (string) ( $ra['url'] ?? '' ), -12 ) );

// 8. 前一天单独查：A 访问量 1，跳出率 100。
$t9 = $table( '2020-05-09' );
$r9 = $pick( $t9, $a );
$ok( '2020-05-09 alone: A views 1, visitors 1, bounced 1, bounce_rate 100.0', 1 === ( $r9['views'] ?? null ) && 1 === ( $r9['visitors'] ?? null ) && 1 === ( $r9['bounced'] ?? null ) && is_float( $r9['bounce_rate'] ?? null ) && $eq( $r9['bounce_rate'] ?? null, 100.0 ) );
$ok( '2020-05-09 alone: B has no row (not viewed that day)', array() === $pick( $t9, $b ) );

// 9. 参数校验：三次分开的调用，每次一个错误的字段。
$bad_sort = Page_Visits::params( array( 'sort' => 'bogus' ) );
$bad_per  = Page_Visits::params( array( 'per_page' => 0 ) );
$bad_page = Page_Visits::params( array( 'page' => 'abc' ) );
$ok( 'params: sort=bogus is pc_invalid_param with fields.sort invalid_format', is_wp_error( $bad_sort ) && 'pc_invalid_param' === $bad_sort->get_error_code() && 'invalid_format' === ( $bad_sort->get_error_data()['fields']['sort'] ?? '' ) );
$ok( 'params: per_page=0 is pc_invalid_param with fields.per_page invalid_format', is_wp_error( $bad_per ) && 'pc_invalid_param' === $bad_per->get_error_code() && 'invalid_format' === ( $bad_per->get_error_data()['fields']['per_page'] ?? '' ) );
$ok( 'params: page=abc is pc_invalid_param with fields.page invalid_format', is_wp_error( $bad_page ) && 'pc_invalid_param' === $bad_page->get_error_code() && 'invalid_format' === ( $bad_page->get_error_data()['fields']['page'] ?? '' ) );
$defaults = Page_Visits::params( array() );
$ok( 'params: defaults are views / desc / page 1 / 20 per page / empty q', array( 'sort' => 'views', 'order' => 'desc', 'page' => 1, 'per_page' => 20, 'q' => '' ) === $defaults );

// 10. 接口（管理员）。
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/pages' );
$req->set_param( 'from', '2020-05-10' );
$req->set_param( 'to', '2020-05-10' );
$res = rest_do_request( $req );
$ok( 'admin can load the page visits (200, total 2)', 200 === $res->get_status() && 2 === ( $res->get_data()['total'] ?? null ) );
$ok( 'page visits response is not cached', 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/pages' );
$req->set_param( 'from', '2020-05-10' );
$req->set_param( 'to', '2020-05-10' );
$req->set_param( 'sort', 'bogus' );
$ok( 'sort=bogus returns 400', 400 === rest_do_request( $req )->get_status() );

// 11. 接口（顾客不能看）。
$customer = wp_insert_user(
	array(
		'user_login' => 'smoke_pv_customer',
		'user_email' => 'smoke-pv-customer@example.com',
		'user_pass'  => wp_generate_password(),
		'role'       => 'customer',
	)
);
$customer = is_wp_error( $customer ) ? 0 : (int) $customer;
wp_set_current_user( $customer );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/pages' );
$req->set_param( 'from', '2020-05-10' );
$req->set_param( 'to', '2020-05-10' );
$ok( 'customers cannot load the page visits', $customer > 0 && in_array( rest_do_request( $req )->get_status(), array( 401, 403 ), true ) );

// 12. 页面访问统计页的脚本只在这个页面加载。
wp_set_current_user( $admin->ID );
$_GET['page'] = 'pc-data-pages';
Data_Pages::enqueue();
$before = implode( '', (array) wp_scripts()->get_data( 'peptide-core-data-pages', 'before' ) );
$ok( 'page visits script and style are enqueued on the page visits page', wp_script_is( 'peptide-core-data-pages', 'enqueued' ) && wp_style_is( 'peptide-core-data-pages', 'enqueued' ) );
$ok( 'page visits script gets its strings before it loads', false !== strpos( $before, 'window.pcDataPages' ) && false !== strpos( $before, 'Page path' ) );
foreach ( array( 'peptide-core-data-pages', Data_Pages::SCRIPT ) as $handle ) {
	wp_dequeue_script( $handle );
}
$_GET['page'] = 'pc-data-conversion';
Data_Pages::enqueue();
$ok( 'page visits script is not enqueued on the conversion page', ! wp_script_is( 'peptide-core-data-pages', 'enqueued' ) && wp_script_is( 'peptide-core-data-conversion', 'enqueued' ) );
foreach ( array( 'peptide-core-data', 'peptide-core-data-operations', 'peptide-core-data-products', 'peptide-core-data-pages', 'peptide-core-data-conversion' ) as $handle ) {
	wp_dequeue_script( $handle );
	wp_dequeue_style( $handle );
}

// 清理：先删用户，再用 Privacy::delete_visitors() 删访客和他们的事件、访问。
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( $customer > 0 ) {
	wp_delete_user( $customer );
}
Privacy::delete_visitors( $visitor_ids );
$_GET = $get_backup;
wp_set_current_user( $admin->ID );

// 13. 测试数据都删掉了。
$left = 0;
if ( $visitor_ids ) {
	$in    = implode( ',', array_map( 'intval', $visitor_ids ) );
	$left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	foreach ( array( 'visits', 'events' ) as $name ) {
		$left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $name ) . " WHERE visitor_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
$ok( 'test data removed (visitors, visits, events, temporary user)', 0 === $left && ( 0 === $customer || ! get_userdata( $customer ) ) && 3 === count( $visitor_ids ) && 4 === count( $visit_ids ) && 7 === count( $event_ids ) );
