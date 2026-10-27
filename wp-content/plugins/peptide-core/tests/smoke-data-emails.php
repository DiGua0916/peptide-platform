<?php
/**
 * 邮件详情数据页（DATA-01 第 8 步）冒烟测试：时间段内的发送数、成功率（本期、对比期、相对百分比的变化）、每种邮件一行的表格（发送数从大到小、名称来自
 * Email_Center::names()）、没有记录的一天、发送记录表没有安装时全是 0、GET /peptide/v1/data/emails 接口和权限、页面脚本的加载。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-data-emails.php
 * 测试数据都放在固定的历史日期（店铺时区 2020-10-10 为本期，2020-10-09 为对比期），直接写进发送记录表 pc_email_log（收件人 smoke-email@example.com），
 * 不受站点上其他邮件影响；开始前删掉这个收件人的旧记录，结束时按编号删掉写进去的记录，创建的临时用户也删掉。发送记录表没装时测试先装上。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Data_Pages;
use PeptideCore\Admin\Email_Center;
use PeptideCore\Data\Emails;
use PeptideCore\Data\Range;
use PeptideCore\Emails\Log;

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

// 店铺时区的某个时刻 => Unix 秒。
$at = static fn( string $local ) => ( new DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();

// 1. 发送记录表：没装就先装上（并说明）；删掉这个收件人上次测试可能留下的记录。
if ( ! Log::installed() ) {
	Log::install();
	echo "NOTE email log table was not installed, the test installed it\n";
}
$table     = Log::table();
$recipient = 'smoke-email@example.com';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE recipient = %s", $recipient ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

// 2. 直接写记录：每条的时间是这一天 12:00 再加 n 秒，保证都落在这一天里。
$ids = array();
$n   = 0;
$add = static function ( string $email_id, int $delivered, string $day, int $times ) use ( $wpdb, $table, $recipient, $at, &$ids, &$n ): void {
	for ( $i = 0; $i < $times; $i++ ) {
		++$n;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'email_id'  => $email_id,
				'recipient' => $recipient,
				'subject'   => 'smoke',
				'ok'        => $delivered,
				'object_id' => 0,
				'sent_ms'   => $at( $day . ' 12:00' ) * 1000 + $n * 1000,
			)
		);
		$ids[] = (int) $wpdb->insert_id;
	}
};
// 本期 2020-10-10：订单已付款邮件 成功 3 次、失败 1 次；订单已送达邮件 成功 1 次。
$add( 'customer_processing_order', 1, '2020-10-10', 3 );
$add( 'customer_processing_order', 0, '2020-10-10', 1 );
$add( 'customer_completed_order', 1, '2020-10-10', 1 );
// 对比期 2020-10-09：订单已付款邮件 成功 2 次。
$add( 'customer_processing_order', 1, '2020-10-09', 2 );

// 一天（或一段）的邮件详情数据。
$summary = static function ( string $from, string $to ): array {
	$r = Range::from_params( array( 'from' => $from, 'to' => $to ) );
	return is_array( $r ) ? Emails::summary( $r ) : array();
};
// 一个字段；字段不存在时返回 '__missing__'（不能用 ?? ：值是 null 时 ?? 也会换成默认值，null 就测不出来了）。
$field = static function ( array $data, string $group, string $name ) {
	$row = $data[ $group ] ?? array();
	return is_array( $row ) && array_key_exists( $name, $row ) ? $row[ $name ] : '__missing__';
};

$s    = $summary( '2020-10-10', '2020-10-10' );
$rows = (array) ( $s['rows'] ?? array() );

// 3. 合计：本期 5 封、4 封成功、80%；对比期 2 封、100%。
$ok( 'current: sent 5, delivered 4, success_rate 80.0', 5 === $field( $s, 'current', 'sent' ) && 4 === $field( $s, 'current', 'delivered' ) && is_float( $field( $s, 'current', 'success_rate' ) ) && $eq( $field( $s, 'current', 'success_rate' ), 80.0 ) );
$ok( 'previous: sent 2, delivered 2, success_rate 100.0', 2 === $field( $s, 'previous', 'sent' ) && 2 === $field( $s, 'previous', 'delivered' ) && $eq( $field( $s, 'previous', 'success_rate' ), 100.0 ) );
$ok( 'the send log is on (logging true)', true === ( $s['logging'] ?? null ) );

// 4. 变化：发送数 +150%；成功率是相对变化 (80 - 100) / 100 = -20%（和 Shopname 一样，不是百分点之差）。
$ok( 'change: sent +150.0', $eq( $field( $s, 'change', 'sent' ), 150.0 ) );
$ok( 'change: success_rate -20.0 (relative, (80 - 100) / 100)', $eq( $field( $s, 'change', 'success_rate' ), -20.0 ) );

// 5. 表格：每种邮件一行，发送数从大到小。
$ok( 'two rows: customer_processing_order first, then customer_completed_order', 2 === count( $rows ) && 'customer_processing_order' === ( $rows[0]['email_id'] ?? '' ) && 'customer_completed_order' === ( $rows[1]['email_id'] ?? '' ) );
$names = Email_Center::names();
$ok( 'processing row: sent 4, delivered 3, rate 75.0, name from Email_Center::names()', 4 === ( $rows[0]['sent'] ?? null ) && 3 === ( $rows[0]['delivered'] ?? null ) && $eq( $rows[0]['success_rate'] ?? 'x', 75.0 ) && ( $names['customer_processing_order'] ?? null ) === ( $rows[0]['name'] ?? '' ) );
$ok( 'completed row: sent 1, delivered 1, rate 100.0, name from Email_Center::names()', 1 === ( $rows[1]['sent'] ?? null ) && 1 === ( $rows[1]['delivered'] ?? null ) && $eq( $rows[1]['success_rate'] ?? 'x', 100.0 ) && ( $names['customer_completed_order'] ?? null ) === ( $rows[1]['name'] ?? '' ) );

// 6. 邮件名称：就是邮件中心卡片上的名称。
$ok( 'names(): customer_processing_order is "Order paid" and customer_completed_order is "Order delivered"', __( 'Order paid', 'peptide-core' ) === ( $names['customer_processing_order'] ?? null ) && __( 'Order delivered', 'peptide-core' ) === ( $names['customer_completed_order'] ?? null ) );

// 7. 发送记录页的链接。
$ok( 'log_url opens the send log tab (tab=log)', false !== strpos( (string) ( $s['log_url'] ?? '' ), 'tab=log' ) );

// 8. 没有记录的一天：发送数 0、成功率 null、表格是空的。
$sn = $summary( '2020-10-11', '2020-10-11' );
$ok( 'a day with no rows: sent 0, success_rate null, rows empty', 0 === $field( $sn, 'current', 'sent' ) && null === $field( $sn, 'current', 'success_rate' ) && array() === ( $sn['rows'] ?? null ) );

// 9. 发送记录表没有安装时（用选项的过滤器假装没装）：数字全是 0，成功率 null，表格是空的，页面不会去创建表。
// （pre_option_ 过滤器返回 false 表示“不覆盖”，所以要返回一个不是当前版本的值。）
$fake_old = static fn() => '0';
add_filter( 'pre_option_' . Log::DB_OPTION, $fake_old );
$so = $summary( '2020-10-10', '2020-10-10' );
remove_filter( 'pre_option_' . Log::DB_OPTION, $fake_old );
$ok( 'log table not installed: zeros, success_rate null, rows empty, logging false', 0 === $field( $so, 'current', 'sent' ) && null === $field( $so, 'current', 'success_rate' ) && array() === ( $so['rows'] ?? null ) && false === ( $so['logging'] ?? null ) && Log::installed() );

// 10. 接口（管理员）。
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/emails' );
$req->set_param( 'from', '2020-10-10' );
$req->set_param( 'to', '2020-10-10' );
$res = rest_do_request( $req );
$ok( 'admin can load the email details (200, sent 5)', 200 === $res->get_status() && 5 === ( $res->get_data()['current']['sent'] ?? null ) );
$ok( 'email details response is not cached', 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/emails' );
$req->set_param( 'from', 'bad' );
$req->set_param( 'to', '2020-10-10' );
$ok( 'from=bad returns 400', 400 === rest_do_request( $req )->get_status() );

// 11. 接口（顾客不能看）。
$customer = wp_insert_user(
	array(
		'user_login' => 'smoke_emails_customer',
		'user_email' => 'smoke-emails-customer@example.com',
		'user_pass'  => wp_generate_password(),
		'role'       => 'customer',
	)
);
$customer = is_wp_error( $customer ) ? 0 : (int) $customer;
wp_set_current_user( $customer );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/emails' );
$req->set_param( 'from', '2020-10-10' );
$req->set_param( 'to', '2020-10-10' );
$ok( 'customers cannot load the email details', $customer > 0 && in_array( rest_do_request( $req )->get_status(), array( 401, 403 ), true ) );

// 12. 邮件详情数据页的脚本只在这个页面加载（数据页的脚本和样式都先摘掉，再切换页面）。
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
$_GET['page'] = 'pc-data-emails';
Data_Pages::enqueue();
$ok( 'email details script and style are enqueued on the email details page, its strings arrive before it loads', wp_script_is( 'peptide-core-data-emails', 'enqueued' ) && wp_style_is( 'peptide-core-data-emails', 'enqueued' ) && false !== strpos( $last( 'peptide-core-data-emails' ), 'window.pcDataEmails' ) && false !== strpos( $last( 'peptide-core-data-emails' ), 'Send attempts' ) );
$dequeue_all();
$_GET['page'] = 'pc-data-apps';
Data_Pages::enqueue();
$ok( 'email details script is not enqueued on the app statistics page', ! wp_script_is( 'peptide-core-data-emails', 'enqueued' ) && ! wp_style_is( 'peptide-core-data-emails', 'enqueued' ) && wp_script_is( 'peptide-core-data-apps', 'enqueued' ) );
$dequeue_all();

// 清理：按编号删掉写进去的记录，再删临时用户。
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( $ids ) {
	$in = implode( ',', array_map( 'intval', $ids ) );
	$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
}
if ( $customer > 0 ) {
	wp_delete_user( $customer );
}
$_GET = $get_backup;
wp_set_current_user( $admin->ID );

// 13. 测试数据都删掉了（按编号数、按收件人数都是 0）。
$left = 0;
if ( $ids ) {
	$in    = implode( ',', array_map( 'intval', $ids ) );
	$left += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
}
$left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE recipient = %s", $recipient ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
$ok( 'test data removed (log rows, temporary user)', 0 === $left && 7 === count( $ids ) && ( 0 === $customer || ! get_userdata( $customer ) ) );
