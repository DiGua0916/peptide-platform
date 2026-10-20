<?php
/**
 * 店铺装修：模板库、默认模板、域名指定模板、预览（EDIT-BLOCKS-02）冒烟测试。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-store-design.php
 * 会临时创建模板和测试用户，结束时彻底删除，并恢复默认模板和域名设置。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Layout_Editor;
use PeptideCore\Admin\Store_Design;
use PeptideCore\Templates;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$orig_active  = get_option( Templates::ACTIVE_OPTION, null );
$orig_domains = get_option( Templates::DOMAIN_OPTION, null );
$created      = array();

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );

// 1. 默认模板与文档。
$default = Templates::default_id();
$ok( 'store has a default template', Templates::exists( $default ) );
$ok( 'default template has 4 design documents', 4 === count( array_filter( array_map( static fn( $d ) => Templates::document_id( $default, $d ), array_keys( Templates::documents() ) ) ) ) );
$ok( 'document lookup is stable', Templates::document_id( $default, 'home' ) === Templates::document_id( $default, 'home' ) );
$ok( 'unknown document key rejected', 0 === Templates::document_id( $default, 'nope' ) );

// 2. 新建、修改信息、复制。
$a         = Templates::create( '  SMOKE <b>Template</b> A ', str_repeat( 'n', 300 ) );
$created[] = $a;
$ok( 'create cleans name', 'SMOKE Template A' === get_the_title( $a ) );
$ok( 'note limited to 200 chars', 200 === strlen( (string) get_post_meta( $a, Templates::NOTE_META, true ) ) );
$ok( 'template records the active theme', get_stylesheet() === get_post_meta( $a, Templates::THEME_META, true ) );

$home_a = Templates::document_id( $a, 'home' );
wp_update_post( array( 'ID' => $home_a, 'post_content' => '<!-- smoke draft -->' ) );
update_post_meta( $home_a, '_pc_published_content', '<!-- smoke published -->' );

$ok( 'update info', Templates::update_info( $a, 'SMOKE A renamed', 'note' ) && 'SMOKE A renamed' === get_the_title( $a ) );
$b         = Templates::duplicate( $a );
$created[] = $b;
$home_b    = Templates::document_id( $b, 'home' );
$ok( 'duplicate gets a new id and copy name', $b && $b !== $a && str_contains( get_the_title( $b ), 'SMOKE A renamed' ) );
$ok( 'duplicate copies draft content', '<!-- smoke draft -->' === get_post_field( 'post_content', $home_b ) );
$ok( 'duplicate copies plugin meta', '<!-- smoke published -->' === get_post_meta( $home_b, '_pc_published_content', true ) );
$ok( 'duplicate documents are separate posts', $home_b !== $home_a && $b === wp_get_post_parent_id( $home_b ) );

// 3. 默认模板与删除保护。
$ok( 'set default', Templates::set_default( $a ) && $a === Templates::default_id() );
$r = Templates::trash( $a );
$ok( 'default template cannot be deleted', is_wp_error( $r ) && 'pc_template_default' === $r->get_error_code() );
Templates::set_default( $default );

// 4. 域名指定。
$ok( 'normalize host strips scheme/path/port/case', 'shop.example.com' === Templates::normalize_host( 'HTTPS://Shop.Example.com:8443/path?x=1' ) );
$ok( 'invalid domain rejected', null === Templates::normalize_host( 'not a domain' ) && null === Templates::normalize_host( '-bad-.com' ) && is_wp_error( Templates::assign_domain( 'bad domain', $b ) ) );
$ok( 'assign domain', true === Templates::assign_domain( 'promo.example.com', $b ) );
$ok( 'host resolves to its template', $b === Templates::for_host( 'promo.example.com' ) );
$ok( 'www prefix resolves the same', $b === Templates::for_host( 'www.promo.example.com:443' ) );
$ok( 'other hosts use the default', $default === Templates::for_host( 'vialcovepeptidescom.local' ) );
$r = Templates::trash( $b );
$ok( 'domain-assigned template cannot be deleted', is_wp_error( $r ) && 'pc_template_domain' === $r->get_error_code() );
Templates::remove_domain( 'promo.example.com' );
$ok( 'remove domain', $default === Templates::for_host( 'promo.example.com' ) );

// 5. 预览：需要编辑权限和有效 nonce。
$_GET[ Templates::PREVIEW_PARAM ] = (string) $b;
$_GET[ Templates::NONCE_PARAM ]   = wp_create_nonce( 'pc_template_preview_' . $b );
$ok( 'staff preview selects the template', $b === Templates::previewing_id() && $b === Templates::current_id() );
$_GET[ Templates::NONCE_PARAM ] = wp_create_nonce( 'pc_template_preview_' . $a );
$ok( 'nonce for another template is rejected', 0 === Templates::previewing_id() );
$_GET[ Templates::NONCE_PARAM ] = wp_create_nonce( 'pc_template_preview_' . $b );
$customer = wp_insert_user( array( 'user_login' => 'pc_smoke_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 24 ), 'role' => 'customer' ) );
wp_set_current_user( $customer );
$_GET[ Templates::NONCE_PARAM ] = wp_create_nonce( 'pc_template_preview_' . $b );
$ok( 'customer cannot preview', 0 === Templates::previewing_id() );
$ok( 'customer cannot open store design', ! current_user_can( Templates::CAP_EDIT ) );
wp_set_current_user( 0 );
$ok( 'visitor without preview gets the default', 0 === Templates::previewing_id() );
unset( $_GET[ Templates::PREVIEW_PARAM ], $_GET[ Templates::NONCE_PARAM ] );
wp_set_current_user( $admin->ID );

$url = Templates::preview_url( $b, true );
$ok( 'preview url has template, nonce and frame flag', str_contains( $url, Templates::PREVIEW_PARAM . '=' . $b ) && str_contains( $url, Templates::NONCE_PARAM . '=' ) && str_contains( $url, Templates::FRAME_PARAM . '=1' ) );

// 6. 能力。
$sm = get_role( 'shop_manager' );
$ok( 'shop manager can edit but not publish', $sm && $sm->has_cap( Templates::CAP_EDIT ) && ! $sm->has_cap( Templates::CAP_MANAGE ) );
$ok( 'administrator can publish', get_role( 'administrator' )->has_cap( Templates::CAP_MANAGE ) );

// 7. 编辑器只允许 vialcove/* 区块。
$ctx       = new WP_Block_Editor_Context( array( 'post' => get_post( $home_b ) ) );
$allowed   = Layout_Editor::allowed_blocks( true, $ctx );
$ok( 'design document allows only vialcove blocks', is_array( $allowed ) && ! in_array( 'core/paragraph', $allowed, true ) && array() === array_filter( $allowed, static fn( $n ) => ! str_starts_with( $n, 'vialcove/' ) ) );
$any_page = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => 1 ) );
$ok( 'other post types keep their blocks', $any_page && true === Layout_Editor::allowed_blocks( true, new WP_Block_Editor_Context( array( 'post' => $any_page[0] ) ) ) );

// 8. 后台页面能渲染。
set_current_screen( 'toplevel_page_' . Store_Design::SLUG );
ob_start();
Store_Design::render_theme_design();
$html = ob_get_clean();
$ok( 'theme design page lists the library', str_contains( $html, 'SMOKE A renamed' ) && str_contains( $html, 'pc-sd-stage' ) );
$_GET['view'] = 'domains';
ob_start();
Store_Design::render_theme_design();
$html = ob_get_clean();
unset( $_GET['view'] );
$ok( 'domain view renders the form', str_contains( $html, 'pc_design_domain_add' ) );

// 9. 装修文档不能单独删除；删除模板时连同文档移到回收站。
$ok( 'design document cannot be deleted on its own', ! current_user_can( 'delete_post', $home_b ) );
$ok( 'trash template and documents', true === Templates::trash( $b ) && 'trash' === get_post_status( $b ) && 'trash' === get_post_status( $home_b ) );

// ---------- 清理 ----------
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $created as $id ) {
	foreach ( get_children( array( 'post_parent' => $id, 'post_type' => Templates::LAYOUT, 'post_status' => 'any', 'fields' => 'ids' ) ) as $doc ) {
		wp_delete_post( (int) $doc, true );
	}
	wp_delete_post( $id, true );
}
wp_delete_user( $customer );
foreach ( array( Templates::ACTIVE_OPTION => $orig_active, Templates::DOMAIN_OPTION => $orig_domains ) as $key => $value ) {
	null === $value ? delete_option( $key ) : update_option( $key, $value );
}
$ok( 'cleanup: default template restored', ( null === $orig_active ? 0 : (int) $orig_active ) === (int) get_option( Templates::ACTIVE_OPTION, 0 ) && null === get_post( $a ) );
