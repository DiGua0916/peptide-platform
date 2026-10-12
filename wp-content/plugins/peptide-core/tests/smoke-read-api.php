<?php
/**
 * 只读接口与报告权限冒烟测试（开发环境专用，需先运行 wp peptide demo seed）。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-read-api.php
 * 会临时创建一个测试顾客账号和测试报告，结束时删除并恢复演示数据原状。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Access;
use PeptideCore\Catalog;
use PeptideCore\Meta;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};
$get = static function ( string $route, array $params = array() ) {
	$request = new WP_REST_Request( 'GET', $route );
	foreach ( $params as $k => $v ) {
		$request->set_param( $k, $v );
	}
	return rest_do_request( $request );
};
$report_in = static function ( array $batch ): ?array {
	return $batch['reports'][0] ?? null;
};

$a1 = Catalog::public_batch_id_by_no( 'DEMO-A-2401' );
$a2 = Catalog::public_batch_id_by_no( 'DEMO-A-2402' );
$r2 = Catalog::batch( $a2, 0 )['reports'][0]['id'];

// 临时顾客账号（随机密码，不对外使用）。
$customer = wp_insert_user(
	array(
		'user_login' => 'pc_smoke_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'customer',
	)
);

// 1. 访客 / 顾客 / 已审核 / 员工 对 registered 报告的权限。
$ok( 'guest: registered report locked (login_required)', 'login_required' === $report_in( Catalog::batch( $a2, 0 ) )['locked_reason'] );
$ok( 'customer: registered report downloadable', true === $report_in( Catalog::batch( $a2, $customer ) )['can_download'] );
$ok( 'customer: batch state available', 'available' === Catalog::batch( $a2, $customer )['report_state'] );
$ok( 'customer: download_url carries nonce', str_contains( (string) $report_in( Catalog::batch( $a2, $customer ) )['download_url'], '_wpnonce=' ) );

update_post_meta( $r2, Meta::REPORT_ACCESS, 'approved' );
$ok( 'customer: approved report locked (approval_required)', 'approval_required' === $report_in( Catalog::batch( $a2, $customer ) )['locked_reason'] );
update_user_meta( $customer, Access::APPROVED_META, '1' );
$ok( 'approved customer: approved report downloadable', 'approved' === $report_in( Catalog::batch( $a2, $customer ) )['access_reason'] );
delete_user_meta( $customer, Access::APPROVED_META );

update_post_meta( $r2, Meta::REPORT_ACCESS, 'admin' );
$ok( 'customer: staff-only report hidden', array() === Catalog::batch( $a2, $customer )['reports'] );
$ok( 'admin user: staff-only report downloadable (staff)', 'staff' === $report_in( Catalog::batch( $a2, 1 ) )['access_reason'] );
update_post_meta( $r2, Meta::REPORT_ACCESS, 'registered' );

// 2. 旧版本：默认不出现，include_history=1 时出现。
$r1   = Catalog::batch( $a1, 0 )['reports'][0]['id'];
$file = get_post_meta( $r1, Meta::REPORT_FILE, true );
$v2   = wp_insert_post(
	array(
		'post_type'   => 'pc_report',
		'post_status' => 'publish',
		'post_title'  => 'Smoke v2',
		'meta_input'  => array(
			Meta::REPORT_BATCH_ID => $a1,
			Meta::REPORT_TYPE     => 'coa',
			Meta::REPORT_VERSION  => 2,
			Meta::REPORT_STATUS   => 'published',
			Meta::REPORT_ACCESS   => 'public',
			Meta::REPORT_FILE     => $file,
		),
	)
);
\PeptideCore\Reports::supersede_previous( $v2 );
$default = Catalog::batch( $a1, 0 )['reports'];
$history = Catalog::batch( $a1, 0, true )['reports'];
$ok( 'superseded hidden by default', 1 === count( $default ) && $v2 === $default[0]['id'] );
$ok( 'include_history shows old version', 2 === count( $history ) );
$old = array_values( array_filter( $history, static fn( $r ) => $r['id'] === $r1 ) )[0] ?? null;
$ok( 'old version flagged not current, points to new', $old && false === $old['is_current'] && $v2 === $old['superseded_by'] );

// 3. 下载接口的错误分支（成功分支会直接输出文件并退出，用 HTTP 单独测）。
wp_set_current_user( 0 );
update_post_meta( $r1, Meta::REPORT_STATUS, 'withdrawn' );
$ok( 'withdrawn report download -> 410', 410 === $get( '/peptide/v1/reports/' . $r1 . '/file' )->get_status() );
update_post_meta( $r1, Meta::REPORT_STATUS, 'draft' );
$ok( 'draft report download (guest) -> 404', 404 === $get( '/peptide/v1/reports/' . $r1 . '/file' )->get_status() );
$ok( 'registered report download (guest) -> 401', 401 === $get( '/peptide/v1/reports/' . $r2 . '/file' )->get_status() );
update_post_meta( $r2, Meta::REPORT_ACCESS, 'approved' );
wp_set_current_user( $customer );
$ok( 'approved report download (customer) -> 403', 403 === $get( '/peptide/v1/reports/' . $r2 . '/file' )->get_status() );
update_post_meta( $r2, Meta::REPORT_ACCESS, 'registered' );
wp_set_current_user( 0 );

// 4. 隐藏批次：查询与商品批次列表都看不到。
update_post_meta( $a2, Meta::BATCH_VISIBILITY, 'hidden' );
$ok( 'hidden batch lookup -> 404', 404 === $get( '/peptide/v1/batches/lookup', array( 'batch_no' => 'DEMO-A-2402' ) )->get_status() );
$ok( 'hidden batch excluded from product batches', ! in_array( $a2, array_column( $get( '/peptide/v1/products/demo-peptide-a/batches' )->get_data()['batches'], 'id' ), true ) );
update_post_meta( $a2, Meta::BATCH_VISIBILITY, 'public' );

// 还原。
delete_post_meta( $v2, Meta::REPORT_FILE );
wp_delete_post( $v2, true );
update_post_meta( $r1, Meta::REPORT_STATUS, 'published' );
delete_post_meta( $r1, Meta::REPORT_SUPERSEDED_BY );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $customer );
$ok( 'cleanup: demo state restored', 'available' === Catalog::batch( $a1, 0 )['report_state'] && 'restricted' === Catalog::batch( $a2, 0 )['report_state'] );
