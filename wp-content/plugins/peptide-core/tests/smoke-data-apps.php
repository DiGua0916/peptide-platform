<?php
/**
 * 应用统计页（DATA-01 第 8 步）冒烟测试：离开意图增收一张卡片，七个指标（弹出次数、领取 / 抽奖、领取率、中奖次数、关闭次数、订单数、订单金额）的本期、对比期和变化
 * （次数和金额是百分比，领取率是百分点，对比期是 0 或任一边没有值时没有变化）、没有记录的一天、表没有安装时全是 0、GET /peptide/v1/data/apps 接口和权限、页面脚本的加载。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-data-apps.php
 * 测试数据都放在固定的历史日期（店铺时区 2020-10-10 为本期，2020-10-09 为对比期），写进表 pc_app_stats（app = exit_intent），不受站点上其他数据影响；
 * 开始前删掉这两天的旧记录，结束时再删一次，创建的临时用户也删掉。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Apps_Page;
use PeptideCore\Admin\Data_Pages;
use PeptideCore\Data\Apps;
use PeptideCore\Data\Range;
use PeptideCore\Exit_Intent;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};
// 金额和比例：差不到 0.01 就算相等；null 只等于 null。
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

if ( ! Exit_Intent::installed() ) {
	Exit_Intent::install();
}
$table = Exit_Intent::table();
$cur   = '2020-10-10';
$prev  = '2020-10-09';

// 删掉这两天可能留下的旧记录（上一次测试中途停了的话）。
$clear = static function () use ( $wpdb, $table, $cur, $prev ): void {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE app = %s AND day IN (%s, %s)", Exit_Intent::APP, $cur, $prev ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
};
$count_rows = static function () use ( $wpdb, $table, $cur, $prev ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE app = %s AND day IN (%s, %s)", Exit_Intent::APP, $cur, $prev ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
};
$clear();

// 记事件：同一天同一事件记 $times 次。
$record = static function ( string $event, int $times, string $day ): void {
	for ( $i = 0; $i < $times; $i++ ) {
		Exit_Intent::record( $event, 0.0, $day );
	}
};
// 本期 2020-10-10：弹出 10、关闭 4、领取 2、抽奖 1、中奖 1、订单 2（金额 50 和 30）。
$record( 'show', 10, $cur );
$record( 'close', 4, $cur );
$record( 'claim', 2, $cur );
$record( 'spin', 1, $cur );
$record( 'win', 1, $cur );
Exit_Intent::record( 'order', 50.0, $cur );
Exit_Intent::record( 'order', 30.0, $cur );
// 对比期 2020-10-09：弹出 5、领取 1。
$record( 'show', 5, $prev );
$record( 'claim', 1, $prev );

// 一天（或一段）的应用统计。
$summary = static function ( string $from, string $to ): array {
	$r = Range::from_params( array( 'from' => $from, 'to' => $to ) );
	return is_array( $r ) ? Apps::summary( $r ) : array();
};
$metrics_of = static function ( array $s ): array {
	return array_column( (array) ( $s['apps'][0]['metrics'] ?? array() ), null, 'key' );
};
// 一个指标的某个字段；字段不存在时返回 '__missing__'（不能用 ?? ：值是 null 时 ?? 也会换成默认值，null 就测不出来了）。
$field = static function ( array $m, string $key, string $name ) {
	$row = $m[ $key ] ?? array();
	return array_key_exists( $name, $row ) ? $row[ $name ] : '__missing__';
};

$s   = $summary( $cur, $cur );
$app = $s['apps'][0] ?? array();
$m   = $metrics_of( $s );

// 1. 一个应用：离开意图增收，名称、开关、设置页链接。
$known = Apps_Page::apps();
$ok( 'one app entry with key exit-intent', 1 === count( $s['apps'] ?? array() ) && 'exit-intent' === ( $app['key'] ?? '' ) );
$ok( 'name is the app center name, enabled follows the exit-intent setting', ( $known['exit-intent']['name'] ?? null ) === ( $app['name'] ?? '' ) && '' !== ( $app['name'] ?? '' ) && ( (bool) Exit_Intent::settings()['enabled'] ) === ( $app['enabled'] ?? null ) );
$ok( 'settings_url opens the app in the app center (page=pc-apps&app=exit-intent)', false !== strpos( (string) ( $app['settings_url'] ?? '' ), 'page=pc-apps&app=exit-intent' ) );

// 2. 指标的键和顺序、类型。
$keys  = array_column( (array) ( $app['metrics'] ?? array() ), 'key' );
$kinds = array_column( (array) ( $app['metrics'] ?? array() ), 'kind', 'key' );
$ok( 'metric keys equal Apps::EXIT_METRICS, in order', Apps::EXIT_METRICS === $keys );
$ok( 'kinds: claim_rate is rate, order_amount is money, the rest are counts', 'rate' === ( $kinds['claim_rate'] ?? '' ) && 'money' === ( $kinds['order_amount'] ?? '' ) && 'count' === ( $kinds['shown'] ?? '' ) && 'count' === ( $kinds['claims'] ?? '' ) && 'count' === ( $kinds['wins'] ?? '' ) && 'count' === ( $kinds['closed'] ?? '' ) && 'count' === ( $kinds['orders'] ?? '' ) );

// 3. 数值：本期、对比期、变化。
$ok( 'shown: 10 vs 5, change +100.0', 10 === ( $m['shown']['current'] ?? null ) && 5 === ( $m['shown']['previous'] ?? null ) && $eq( $m['shown']['change'] ?? 'x', 100.0 ) );
$ok( 'claims (claim + spin): 3 vs 1, change +200.0', 3 === ( $m['claims']['current'] ?? null ) && 1 === ( $m['claims']['previous'] ?? null ) && $eq( $m['claims']['change'] ?? 'x', 200.0 ) );
$ok( 'claim_rate: 30.0 vs 20.0, change +10.0 (points)', is_float( $m['claim_rate']['current'] ?? null ) && $eq( $m['claim_rate']['current'] ?? null, 30.0 ) && $eq( $m['claim_rate']['previous'] ?? null, 20.0 ) && $eq( $m['claim_rate']['change'] ?? 'x', 10.0 ) );
$ok( 'wins: 1 vs 0, no change (null)', 1 === $field( $m, 'wins', 'current' ) && 0 === $field( $m, 'wins', 'previous' ) && null === $field( $m, 'wins', 'change' ) );
$ok( 'closed: 4 vs 0, no change (null)', 4 === $field( $m, 'closed', 'current' ) && 0 === $field( $m, 'closed', 'previous' ) && null === $field( $m, 'closed', 'change' ) );
$ok( 'orders: 2 vs 0, no change (null)', 2 === $field( $m, 'orders', 'current' ) && 0 === $field( $m, 'orders', 'previous' ) && null === $field( $m, 'orders', 'change' ) );
$ok( 'order_amount: 80.0 vs 0.0, no change (null)', is_float( $field( $m, 'order_amount', 'current' ) ) && $eq( $field( $m, 'order_amount', 'current' ), 80.0 ) && $eq( $field( $m, 'order_amount', 'previous' ), 0.0 ) && null === $field( $m, 'order_amount', 'change' ) );

// 4. 没有记录的一天：弹出 0，领取率没有（null），领取率的变化也没有。
$mn = $metrics_of( $summary( '2020-10-11', '2020-10-11' ) );
$ok( 'a day with no rows: shown 0, claim_rate null, claim_rate change null', 0 === $field( $mn, 'shown', 'current' ) && null === $field( $mn, 'claim_rate', 'current' ) && null === $field( $mn, 'claim_rate', 'change' ) );

// 5. 表没有安装时（用选项的过滤器假装没装）：数字全是 0，领取率 null，页面不会去创建表。
// （pre_option_ 过滤器返回 false 表示“不覆盖”，所以要返回一个不是当前版本的值。）
$fake_old = static fn() => '0';
add_filter( 'pre_option_peptide_core_app_stats_db', $fake_old );
$mo = $metrics_of( $summary( $cur, $cur ) );
remove_filter( 'pre_option_peptide_core_app_stats_db', $fake_old );
$ok( 'table not installed: every value is 0 and claim_rate is null', 0 === $field( $mo, 'shown', 'current' ) && 0 === $field( $mo, 'orders', 'current' ) && $eq( $field( $mo, 'order_amount', 'current' ), 0.0 ) && null === $field( $mo, 'claim_rate', 'current' ) && Exit_Intent::installed() );

// 6. 接口（管理员）。
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/apps' );
$req->set_param( 'from', $cur );
$req->set_param( 'to', $cur );
$res = rest_do_request( $req );
$ok( 'admin can load the app statistics (200, shown 10)', 200 === $res->get_status() && 10 === ( $res->get_data()['apps'][0]['metrics'][0]['current'] ?? null ) );
$ok( 'app statistics response is not cached', 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/apps' );
$req->set_param( 'from', 'bad' );
$req->set_param( 'to', $cur );
$ok( 'from=bad returns 400', 400 === rest_do_request( $req )->get_status() );

// 7. 接口（顾客不能看）。
$customer = wp_insert_user(
	array(
		'user_login' => 'smoke_apps_customer',
		'user_email' => 'smoke-apps-customer@example.com',
		'user_pass'  => wp_generate_password(),
		'role'       => 'customer',
	)
);
$customer = is_wp_error( $customer ) ? 0 : (int) $customer;
wp_set_current_user( $customer );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/apps' );
$req->set_param( 'from', $cur );
$req->set_param( 'to', $cur );
$ok( 'customers cannot load the app statistics', $customer > 0 && in_array( rest_do_request( $req )->get_status(), array( 401, 403 ), true ) );

// 8. 应用统计页的脚本只在这个页面加载（数据页的脚本和样式都先摘掉，再切换页面）。
wp_set_current_user( $admin->ID );
$dequeue_all = static function (): void {
	foreach ( array_keys( wp_scripts()->registered ) as $handle ) {
		if ( 0 === strpos( (string) $handle, 'peptide-core-data' ) ) {
			wp_dequeue_script( $handle );
		}
	}
	foreach ( array_keys( wp_styles()->registered ) as $handle ) {
		if ( 0 === strpos( (string) $handle, 'peptide-core-data' ) ) {
			wp_dequeue_style( $handle );
		}
	}
};
$last = static function ( string $handle ): string {
	$before = (array) wp_scripts()->get_data( $handle, 'before' );
	return (string) end( $before );
};
$dequeue_all();
$_GET['page'] = 'pc-data-apps';
Data_Pages::enqueue();
$ok( 'app statistics script and style are enqueued on the app statistics page, its strings arrive before it loads', wp_script_is( 'peptide-core-data-apps', 'enqueued' ) && wp_style_is( 'peptide-core-data-apps', 'enqueued' ) && false !== strpos( $last( 'peptide-core-data-apps' ), 'window.pcDataApps' ) && false !== strpos( $last( 'peptide-core-data-apps' ), 'Times closed' ) );
$dequeue_all();
$_GET['page'] = 'pc-data-pages';
Data_Pages::enqueue();
$ok( 'app statistics script is not enqueued on the page visits page', ! wp_script_is( 'peptide-core-data-apps', 'enqueued' ) && ! wp_style_is( 'peptide-core-data-apps', 'enqueued' ) && wp_script_is( 'peptide-core-data-pages', 'enqueued' ) );
$dequeue_all();

// 清理：删掉这两天的记录，再删临时用户。
require_once ABSPATH . 'wp-admin/includes/user.php';
$clear();
if ( $customer > 0 ) {
	wp_delete_user( $customer );
}
$_GET = $get_backup;
wp_set_current_user( $admin->ID );

// 9. 测试数据都删掉了。
$ok( 'test data removed (both days, temporary user)', 0 === $count_rows() && ( 0 === $customer || ! get_userdata( $customer ) ) );
