<?php
/**
 * DATA-01 与主干 0.27.1 的同步检查：前台 / 后台启动、数据路由、员工菜单归属和现有共享能力规则。
 * 开发环境专用。分别用普通 WP-CLI 和 --exec="define('WP_ADMIN', true);" 运行本文件，覆盖两个启动入口。
 * 临时员工只创建在当前测试数据库，结束时删除；不修改现有账号。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Data_Pages;
use PeptideCore\Staff;

$failures = 0;
$ok = static function ( string $label, bool $condition ) use ( &$failures ): void {
	echo ( $condition ? 'PASS ' : 'FAIL ' ), $label, "\n";
	if ( ! $condition ) {
		++$failures;
	}
};

$original_user = get_current_user_id();
$temporary_user = 0;

try {
	$ok( 'synchronized core meets the minimum supported version', version_compare( PEPTIDE_CORE_VERSION, '0.27.1', '>=' ) );
	$ok( 'upstream staff capability hook is registered', 20 === has_filter( 'user_has_cap', array( Staff::class, 'grant' ) ) );
	$ok( 'data menu hook matches the current boot context', is_admin() ? 60 === has_action( 'admin_menu', array( Data_Pages::class, 'add_menu' ) ) : false === has_action( 'admin_menu', array( Data_Pages::class, 'add_menu' ) ) );
	$ok( 'data asset hook matches the current boot context', is_admin() ? 10 === has_action( 'admin_enqueue_scripts', array( Data_Pages::class, 'enqueue' ) ) : false === has_action( 'admin_enqueue_scripts', array( Data_Pages::class, 'enqueue' ) ) );

	$routes = rest_get_server()->get_routes();
	foreach ( array( 'range', 'realtime', 'operations', 'trends', 'rankings', 'products', 'pages', 'conversion', 'apps', 'emails' ) as $route ) {
		$ok( 'data route registered: ' . $route, isset( $routes[ '/peptide/v1/data/' . $route ] ) );
	}
	$ok( 'upstream HQ status route remains registered', isset( $routes['/peptide/v1/hq/status'] ) );
	foreach ( Data_Pages::PAGES as $page => $slug ) {
		$ok( 'staff mapping includes data page: ' . $page, 'data' === Staff::module_for( 'admin.php', $slug ) );
	}

	$admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	if ( ! $admin_ids ) {
		throw new RuntimeException( 'The disposable test site needs an administrator.' );
	}
	wp_set_current_user( (int) $admin_ids[0] );
	$response = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/data/range' ) );
	$ok( 'administrator can read data range', 200 === $response->get_status() );
	$ok( 'data range prevents shared caching', 'no-store' === ( $response->get_headers()['Cache-Control'] ?? '' ) );

	wp_set_current_user( 0 );
	$response = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/data/range' ) );
	$ok( 'unauthenticated data access is rejected', 401 === $response->get_status() );

	$created = wp_insert_user( array( 'user_login' => 'pc-sync-' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password( 24, true ), 'role' => Staff::ROLE ) );
	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( 'Could not create disposable employee: ' . $created->get_error_code() );
	}
	$temporary_user = (int) $created;
	Staff::set_modules( $temporary_user, array() );
	wp_set_current_user( $temporary_user );
	$ok( 'employee without granted modules lacks the shared capability', ! current_user_can( Data_Pages::CAP ) );
	$response = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/data/range' ) );
	$ok( 'employee without granted modules cannot read data', 403 === $response->get_status() );

	Staff::set_modules( $temporary_user, array( 'data' ) );
	$ok( 'data module grants the configured shared capability', current_user_can( Data_Pages::CAP ) );
	$response = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/data/range' ) );
	$ok( 'data employee can read data range', 200 === $response->get_status() );

	// 主干现有规则：多个模块共用 manage_woocommerce，页面菜单另按模块检查；同步时不改此契约。
	Staff::set_modules( $temporary_user, array( 'settings' ) );
	$ok( 'store settings retain the existing shared capability policy', current_user_can( Data_Pages::CAP ) );
	Staff::set_disabled( $temporary_user, true );
	$ok( 'disabled employee loses granted capabilities', ! current_user_can( Data_Pages::CAP ) );
	$response = rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/data/range' ) );
	$ok( 'disabled employee cannot read data range', 403 === $response->get_status() );
} finally {
	wp_set_current_user( $original_user );
	if ( $temporary_user ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $temporary_user );
	}
	Staff::flush_cache();
}

if ( $failures ) {
	WP_CLI::halt( 1 );
}
