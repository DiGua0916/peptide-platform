<?php
/**
 * 菜单导航（MENU-NAV-01）冒烟测试。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-menus.php
 * 第一次运行时会建好页头 / 页尾两份固定菜单（和打开“菜单导航”页面时一样）；
 * 测试自己新建的菜单、页面和用户在结束时彻底删除，菜单位置恢复原样。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Menu_Page;
use PeptideCore\Menus;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );

$menus_made     = array();
$posts_made     = array();
$users_made     = array();

// 1. 固定菜单。
$fixed = Menus::ensure_fixed();
// 固定菜单建好之后的位置设置，测试结束时恢复到这个状态。
$orig_locations = get_theme_mod( 'nav_menu_locations', array() );
$ok( 'header and footer menus exist', $fixed['header'] > 0 && $fixed['footer'] > 0 && $fixed['header'] !== $fixed['footer'] );
$ok( 'ensure_fixed is idempotent', Menus::ensure_fixed() === $fixed );
$ok( 'fixed keys recorded', 'header' === Menus::fixed_key( $fixed['header'] ) && 'footer' === Menus::fixed_key( $fixed['footer'] ) );
$ok( 'footer location registered', isset( get_registered_nav_menus()['footer'] ) );
$header = Menus::get( $fixed['header'] );
$titles = array_map( static fn( $i ) => $i['title'], $header['items'] );
$ok( 'header seeded from the theme links', in_array( 'Products', $titles, true ) && in_array( 'Research', $titles, true ) && in_array( 'About', $titles, true ) );
$ok( 'fixed menu cannot be deleted', is_wp_error( Menus::delete( $fixed['header'] ) ) && Menus::exists( $fixed['header'] ) );

// 2. 新建菜单并保存各种链接。
$m            = Menus::create( '  SMOKE <b>menu</b> ' );
$menus_made[] = $m;
$ok( 'create cleans name', ! is_wp_error( $m ) && 'SMOKE menu' === Menus::get( $m )['name'] );
$ok( 'create records author', (int) $admin->ID === (int) get_term_meta( $m, Menus::AUTHOR_META, true ) );

$page_pub     = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SMOKE menu page', 'post_name' => 'smoke-menu-page' ) );
$page_draft   = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'SMOKE draft page' ) );
$posts_made   = array( $page_pub, $page_draft );
$product      = (int) get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) )[0];
$cat          = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 1 ) )[0];

$items = array(
	array( 'title' => 'Shop all', 'link' => array( 'type' => 'shop' ), 'style' => array( 'color' => '#FF0000', 'bold' => 'bold', 'italic' => 'oblique', 'decoration' => 'underline' ), 'nofollow' => true, 'target' => '_blank' ),
	array(
		'title'    => 'Group',
		'link'     => array( 'type' => 'none' ),
		'children' => array(
			array( 'title' => '', 'link' => array( 'type' => 'page', 'id' => $page_pub ) ),
			array( 'title' => 'Draft', 'link' => array( 'type' => 'page', 'id' => $page_draft ) ),
			array( 'title' => 'Cat', 'link' => array( 'type' => 'category', 'id' => $cat->term_id ) ),
			array( 'title' => 'Prod', 'link' => array( 'type' => 'product', 'id' => $product ), 'children' => array( array( 'title' => 'Too deep', 'link' => array( 'type' => 'home' ) ) ) ),
		),
	),
	array( 'title' => 'External', 'link' => array( 'type' => 'custom', 'url' => 'javascript:alert(1)' ) ),
	array( 'title' => 'Relative', 'link' => array( 'type' => 'custom', 'url' => '/batch-reports/' ) ),
	array( 'title' => 'Orders', 'link' => array( 'type' => 'orders' ), 'attrTitle' => '<b>Track</b>', 'description' => 'Your orders' ),
);
$modified = Menus::modified( $m );
$saved    = Menus::save( $m, array( 'name' => 'SMOKE menu', 'location' => '', 'items' => $items ), $modified );
$ok( 'save returns the menu', is_array( $saved ) && 5 === count( $saved['items'] ) );
$ok( 'save changes the modified token', is_array( $saved ) && $saved['modified'] !== $modified );
$ok( 'stale token returns 409', 409 === ( Menus::save( $m, array( 'name' => 'SMOKE menu', 'items' => $items ), $modified )->get_error_data()['status'] ?? 0 ) );

$first = $saved['items'][0];
$ok( 'special link kept', 'shop' === $first['link']['type'] );
$ok( 'style cleaned (color lower-case, bad italic dropped)', '#ff0000' === strtolower( $first['style']['color'] ) && 'bold' === $first['style']['bold'] && '' === $first['style']['italic'] && 'underline' === $first['style']['decoration'] );
$ok( 'nofollow and target saved', $first['nofollow'] && '_blank' === $first['target'] );
$group = $saved['items'][1];
$ok( 'two levels kept, third level dropped', 4 === count( $group['children'] ) && array() === $group['children'][3]['children'] );
$ok( 'empty name takes the page title', 'SMOKE menu page' === $group['children'][0]['title'] );
$ok( 'draft page flagged in the editor', ! empty( $group['children'][1]['link']['broken'] ) );
$ok( 'unsafe custom URL becomes no link', 'none' === $saved['items'][2]['link']['type'] );
$ok( 'relative URL kept', '/batch-reports/' === $saved['items'][3]['link']['url'] );
$ok( 'attr title stripped of tags', 'Track' === $saved['items'][4]['attrTitle'] && 'Your orders' === $saved['items'][4]['description'] );

$bad = Menus::save( $m, array( 'name' => 'SMOKE menu', 'items' => array( array( 'title' => 'Gone', 'link' => array( 'type' => 'page', 'id' => 99999999 ) ) ) ) );
$ok( 'missing object rejected', is_wp_error( $bad ) && 'pc_menu_invalid' === $bad->get_error_code() );
$ok( 'nameless item rejected', is_wp_error( Menus::save( $m, array( 'name' => 'SMOKE menu', 'items' => array( array( 'title' => ' ', 'link' => array( 'type' => 'none' ) ) ) ) ) ) );
$ok( 'menu unchanged after rejected saves', 5 === count( Menus::get( $m )['items'] ) );

// 3. 前台菜单树。
$ok( 'no menu in a location gives null', null === Menus::public_tree( 'nope' ) );
$saved = Menus::save( $m, array( 'name' => 'SMOKE menu', 'location' => 'footer', 'items' => $saved['items'] ) );
$ok( 'location assigned', 'footer' === Menus::location_of( $m ) && $m === Menus::menu_for( 'footer' ) );
$tree = peptide_core_get_menu( 'footer' );
$ok( 'public tree via helper', is_array( $tree ) && 5 === count( $tree ) );
$ok( 'special URL resolved live', wc_get_page_permalink( 'shop' ) === $tree[0]['url'] );
$ok( 'rel and style string', 'nofollow noopener' === $tree[0]['rel'] && str_contains( $tree[0]['style'], 'color:#ff0000' ) && str_contains( $tree[0]['style'], 'font-weight:700' ) && str_contains( $tree[0]['style'], 'text-decoration:underline' ) );
$ok( 'heading item has no URL', '' === $tree[1]['url'] && 'none' === $tree[1]['type'] );
$child_titles = array_map( static fn( $c ) => $c['title'], $tree[1]['children'] );
$ok( 'draft page hidden on the storefront', ! in_array( 'Draft', $child_titles, true ) && 3 === count( $tree[1]['children'] ) );
$ok( 'page URL is the live permalink', get_permalink( $page_pub ) === $tree[1]['children'][0]['url'] );
wp_update_post( array( 'ID' => $page_pub, 'post_name' => 'smoke-menu-page-renamed' ) );
$ok( 'renamed page keeps working', str_contains( peptide_core_get_menu( 'footer' )[1]['children'][0]['url'], 'smoke-menu-page-renamed' ) );
$ok( 'category URL', get_term_link( $cat ) === $tree[1]['children'][1]['url'] );
$ok( 'orders URL', wc_get_account_endpoint_url( 'orders' ) === $tree[4]['url'] );

// 4. 导出 / 导入。
$export = Menus::export();
$ok( 'export format', 'peptide-core-menus' === $export['format'] && count( $export['menus'] ) >= 3 );
$mine = array_values( array_filter( $export['menus'], static fn( $x ) => 'SMOKE menu' === $x['name'] ) )[0];
$ok( 'export uses slugs, not ids', 'smoke-menu-page-renamed' === $mine['items'][1]['children'][0]['link']['slug'] && ! isset( $mine['items'][1]['children'][0]['link']['id'] ) );
$mine['items'][1]['children'][] = array( 'title' => 'Elsewhere', 'link' => array( 'type' => 'page', 'slug' => 'no-such-page-smoke' ) );
$result = Menus::import( array( 'format' => 'peptide-core-menus', 'version' => 1, 'menus' => array( $mine ) ) );
$menus_made = array_merge( $menus_made, is_array( $result ) ? $result['created'] : array() );
$ok( 'import creates a new menu', is_array( $result ) && 1 === count( $result['created'] ) && ! in_array( $m, $result['created'], true ) );
$imported = Menus::get( $result['created'][0] );
$ok( 'imported name made unique', 'SMOKE menu (2)' === $imported['name'] );
$ok( 'imported links found by slug', $page_pub === $imported['items'][1]['children'][0]['link']['id'] );
$ok( 'missing targets reported and unlinked', in_array( 'Elsewhere', $result['missing'], true ) && 'none' === end( $imported['items'][1]['children'] )['link']['type'] );
$ok( 'imported menu not placed anywhere', '' === Menus::location_of( $result['created'][0] ) );
$ok( 'bad import file rejected', is_wp_error( Menus::import( array( 'menus' => array() ) ) ) );

// 5. 接口与权限。
$req = new WP_REST_Request( 'GET', '/peptide/v1/menus/' . $m );
$res = rest_do_request( $req );
$ok( 'REST read', 200 === $res->get_status() && 'SMOKE menu' === $res->get_data()['name'] );
$req = new WP_REST_Request( 'POST', '/peptide/v1/menus/' . $m );
$req->set_header( 'Content-Type', 'application/json' );
$req->set_body( wp_json_encode( array( 'name' => 'SMOKE menu', 'location' => 'footer', 'items' => $res->get_data()['items'], 'modified' => $res->get_data()['modified'] ) ) );
$ok( 'REST save', 200 === rest_do_request( $req )->get_status() );
$ok( 'REST 404 for unknown menu', 404 === rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/menus/99999999' ) )->get_status() );

$editor_user  = wp_insert_user( array( 'user_login' => 'smoke_menu_editor', 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$sub          = wp_insert_user( array( 'user_login' => 'smoke_menu_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$users_made   = array( $editor_user, $sub );
wp_set_current_user( $sub );
$ok( 'subscriber cannot read menus', in_array( rest_do_request( new WP_REST_Request( 'GET', '/peptide/v1/menus/' . $m ) )->get_status(), array( 401, 403 ), true ) );
wp_set_current_user( $admin->ID );

// 6. 后台页面。
ob_start();
Menu_Page::render();
$html = ob_get_clean();
$ok( 'list page shows fixed tag and menus', str_contains( $html, 'Cannot be deleted' ) && str_contains( $html, 'SMOKE menu' ) && str_contains( $html, 'pc_menu_export' ) );
$_GET['menu'] = $m;
ob_start();
Menu_Page::render();
$html = ob_get_clean();
unset( $_GET['menu'] );
$ok( 'edit page mounts the editor', str_contains( $html, 'id="pc-menu-editor"' ) );
$opts = Menus::link_options();
$ok( 'link options list pages, products and categories', $opts['page'] && $opts['product'] && $opts['category'] && isset( $opts['page'][0]['name'] ) );

// 7. 清理。
foreach ( $menus_made as $id ) {
	if ( Menus::exists( $id ) ) {
		Menus::delete( $id );
	}
}
$ok( 'custom menus deleted', ! array_filter( $menus_made, array( Menus::class, 'exists' ) ) );
foreach ( $posts_made as $id ) {
	wp_delete_post( $id, true );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $users_made as $id ) {
	if ( ! is_wp_error( $id ) ) {
		wp_delete_user( $id );
	}
}
set_theme_mod( 'nav_menu_locations', $orig_locations );
$ok( 'menu locations restored', $orig_locations === get_theme_mod( 'nav_menu_locations', array() ) );
