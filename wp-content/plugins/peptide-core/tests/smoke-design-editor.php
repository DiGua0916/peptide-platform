<?php
/**
 * 装修编辑器接口冒烟测试（EDIT-BLOCKS-04，开发环境专用）。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-design-editor.php
 * 会临时创建一个模板和一个顾客，结束时彻底删除。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Modules\Layout;
use PeptideCore\Templates;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};
$call = static function ( string $method, string $route, array $body = array() ) {
	$req = new WP_REST_Request( $method, $route );
	if ( $body ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $req );
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$template = Templates::create( 'SMOKE editor template', '' );
$doc      = Templates::document_id( $template, 'home' );
$route    = '/peptide/v1/layouts/' . $doc;

// 1. 读取。
$res = $call( 'GET', $route );
$d   = $res->get_data();
$ok( 'GET returns an empty document', 200 === $res->get_status() && array() === $d['modules'] && 'home' === $d['document'] && $template === $d['template']['id'] );
$ok( 'GET includes a preview base url', str_contains( (string) $d['previewBase'], Templates::PREVIEW_PARAM . '=' . $template ) );

// 2. 保存：清理、去重 ID、丢弃未知区块。
$modules = array(
	array(
		'block' => 'vialcove/faq',
		'attrs' => array(
			'moduleId' => 'same-id-1',
			'settings' => array( 'title' => '<b>Questions</b>' ),
			'items'    => array(
				array(
					'id'       => 'q-1111',
					'settings' => array( 'question' => 'Q1' ),
				),
			),
		),
	),
	array(
		'block' => 'vialcove/spacer',
		'attrs' => array( 'moduleId' => 'same-id-1' ),
	),
	array(
		'block' => 'core/paragraph',
		'attrs' => array(),
	),
);
$res = $call(
	'POST',
	$route,
	array(
		'modules'  => $modules,
		'modified' => $d['modified'],
	)
);
$saved = $res->get_data();
$ok( 'save succeeds', 200 === $res->get_status() );
$ok( 'unknown blocks are dropped', 2 === count( $saved['modules'] ) );
$ok( 'duplicate module ids are replaced', $saved['modules'][0]['attrs']['moduleId'] !== $saved['modules'][1]['attrs']['moduleId'] );
$ok( 'fields are cleaned on save', 'Questions' === $saved['modules'][0]['attrs']['settings']['title'] );
$content = (string) get_post_field( 'post_content', $doc );
$ok( 'content is stored as blocks', str_contains( $content, '<!-- wp:vialcove/faq' ) && 2 === count( Layout::modules_from_content( $content ) ) );
$ok( 'save creates a revision', count( wp_get_post_revisions( $doc ) ) >= 1 );

// 3. 冲突：用旧的修改时间保存。
$stale = $call(
	'POST',
	$route,
	array(
		'modules'  => array(),
		'modified' => '2000-01-01 00:00:00',
	)
);
$ok( 'stale save returns 409', 409 === $stale->get_status() && 'pc_layout_conflict' === $stale->get_data()['code'] );
$ok( 'conflict does not overwrite', 2 === count( Layout::modules_from_content( (string) get_post_field( 'post_content', $doc ) ) ) );

// 4. 实时预览令牌。
$res = $call( 'POST', $route . '/preview', array( 'modules' => array( $modules[0] ) ) );
$url = (string) $res->get_data()['url'];
$ok( 'preview returns a url with a token', 200 === $res->get_status() && str_contains( $url, Layout::PREVIEW_PARAM . '=' ) );
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
$_GET = $q;
$draft = Layout::preview_draft( $template, 'home' );
$ok( 'preview draft resolves for the same user', $draft && 1 === count( $draft['modules'] ) );
$ok( 'preview does not change the saved content', 2 === count( Layout::modules_from_content( (string) get_post_field( 'post_content', $doc ) ) ) );
$html = Layout::render_preview( 'home' );
$ok( 'preview page renders the draft modules', str_contains( $html, 'vc-m--faq' ) && ! str_contains( $html, 'vc-m--spacer' ) );

$customer = wp_insert_user(
	array(
		'user_login' => 'pc_smoke_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'customer',
	)
);
wp_set_current_user( $customer );
$ok( 'preview draft is not available to another user', null === Layout::preview_draft( $template, 'home' ) );
$_GET = array();

// 5. 权限。
$ok( 'customer cannot read the document', 403 === $call( 'GET', $route )->get_status() || 401 === $call( 'GET', $route )->get_status() );
$ok( 'customer cannot save', in_array( $call( 'POST', $route, array( 'modules' => array() ) )->get_status(), array( 401, 403 ), true ) );
wp_set_current_user( $admin->ID );
$ok( 'unknown document returns 404', 404 === $call( 'GET', '/peptide/v1/layouts/99999999' )->get_status() );

// 6. 预览模板：只在员工预览模板时接管首页。
$ok( 'public front page keeps the theme template', '/theme/front-page.php' === Layout::preview_template( '/theme/front-page.php' ) );

// ---------- 清理 ----------
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( get_children( array( 'post_parent' => $template, 'post_type' => Templates::LAYOUT, 'post_status' => 'any', 'fields' => 'ids' ) ) as $id ) {
	foreach ( wp_get_post_revisions( (int) $id, array( 'fields' => 'ids' ) ) as $rev ) {
		wp_delete_post_revision( $rev );
	}
	wp_delete_post( (int) $id, true );
}
wp_delete_post( $template, true );
wp_delete_user( $customer );
$ok( 'cleanup: template removed', null === get_post( $template ) && null === get_post( $doc ) );
