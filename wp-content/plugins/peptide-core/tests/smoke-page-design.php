<?php
/**
 * Local page-design integration regression. Uses only new, unassigned templates/resources.
 * wp eval-file wp-content/plugins/peptide-core/tests/smoke-page-design.php
 * Does not assign defaults/domains, publish a real layout, create an order, or send email.
 */
defined( 'ABSPATH' ) || exit;

use PeptideCore\Templates;
use PeptideCore\Design_Pages;
use PeptideCore\Api\Layout_Controller;
use PeptideCore\Modules\Layout;
use PeptideCore\Modules\Renderer;
use PeptideCore\Modules\Site_Design;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { throw new RuntimeException( 'Local WP-CLI only.' ); }
$pass = 0; $fail = 0;
$ok = static function ( string $label, bool $yes ) use ( &$pass, &$fail ): void { $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS ' : 'FAIL ' ) . $label . "\n"; };
$call = static function ( string $method, int $id, string $suffix = '', array $body = array() ): WP_REST_Response {
	$r = new WP_REST_Request( $method, '/peptide/v1/layouts/' . $id . $suffix ); $r->set_header( 'content-type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); return rest_do_request( $r );
};
$save = static function ( int $id, array $modules ) use ( $call ) { $d = $call( 'GET', $id )->get_data(); return $call( 'POST', $id, '', array( 'modules' => $modules, 'modified' => $d['modified'], 'revision' => $d['revision'] ) ); };
$publish = static function ( int $id ) use ( $call ) { $d = $call( 'GET', $id )->get_data(); return $call( 'POST', $id, '/publish', array( 'modified' => $d['modified'], 'revision' => $d['revision'] ) ); };
$rich = static fn( string $text ) => array( 'block' => 'vialcove/rich-text', 'attrs' => array( 'settings' => array( 'bodyHtml' => '<p>' . esc_html( $text ) . '</p>' ) ) );
$native = array( 'block' => 'vialcove/context-content', 'attrs' => array() );
$tag = 'pcdesign-' . substr( wp_generate_uuid4(), 0, 12 );
$old_user = get_current_user_id(); $old_get = $_GET; $old_cookie = $_COOKIE;
$posts = array(); $templates = array(); $users = array(); $terms = array(); $menus = array(); $sessions = array(); $preview_tokens = array();
$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ) )[0] ?? 0 );
if ( ! $admin ) { throw new RuntimeException( 'Administrator required.' ); }
$fresh_option = static function ( string $name ) { wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( $name, 'options' ); return get_option( $name, null ); };
$protected = static function () use ( $fresh_option ): string {
	$active = $fresh_option( Templates::ACTIVE_OPTION ); $domains = $fresh_option( Templates::DOMAIN_OPTION ); $docs = array();
	foreach ( get_children( array( 'post_parent' => (int) $active, 'post_type' => Templates::LAYOUT, 'post_status' => 'any' ) ) as $p ) {
		clean_post_cache( $p->ID ); $meta = array_filter( get_post_meta( $p->ID ), static fn( $key ) => str_starts_with( (string) $key, '_pc_' ), ARRAY_FILTER_USE_KEY );
		$docs[ $p->ID ] = array( get_post_field( 'post_content', $p->ID ), $meta );
	}
	ksort( $docs ); return hash( 'sha256', wp_json_encode( array( $active, $domains, $docs ) ) );
};
$protected_before = $protected();
$no_mail = static fn() => true; add_filter( 'pre_wp_mail', $no_mail );
$jar = static function ( int $user ) use ( &$sessions ): array {
	$expires = time() + HOUR_IN_SECONDS; wp_cache_delete( $user, 'user_meta' );
	$token = WP_Session_Tokens::get_instance( $user )->create( $expires ); $sessions[] = array( $user, $token );
	$auth = wp_generate_auth_cookie( $user, $expires, 'auth', $token ); $login = wp_generate_auth_cookie( $user, $expires, 'logged_in', $token );
	return array( 'cookies' => array( new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => $auth ) ), new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => $login ) ) ), 'login' => $login );
};
$http = static function ( string $url, array $cookies = array() ): array {
	$r = wp_remote_get( $url, array( 'cookies' => $cookies, 'timeout' => 45, 'redirection' => 0, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
	return array( 'status' => is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r ), 'body' => is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r ), 'cache' => is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_header( $r, 'cache-control' ), 'location' => is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_header( $r, 'location' ) );
};

try {
	wp_set_current_user( $admin ); $admin_jar = $jar( $admin ); $_COOKIE[ LOGGED_IN_COOKIE ] = $admin_jar['login'];
	$template = Templates::create( $tag, 'Synthetic page-design regression', $admin ); $templates[] = $template;
	$ok( 'new unassigned template has 17 independent page documents', $template > 0 && 17 === count( Templates::documents() ) && 17 === count( get_children( array( 'post_parent' => $template, 'post_type' => Templates::LAYOUT, 'post_status' => 'any' ) ) ) );
	$home_doc = Templates::document_id( $template, 'home', false );
	$first_preview = $call( 'POST', $home_doc, '/preview', array( 'modules' => array( $rich( $tag . '-first-home-token' ) ) ) )->get_data()['url'];
	parse_str( (string) wp_parse_url( $first_preview, PHP_URL_QUERY ), $home_args ); $preview_tokens[] = $home_args[ Layout::PREVIEW_PARAM ];
	$first_live = $http( $first_preview, $admin_jar['cookies'] );
	$ok( 'first unsaved homepage draft renders via its token without saving or publishing', 200 === $first_live['status'] && str_contains( $first_live['body'], $tag . '-first-home-token' ) && '' === get_post_field( 'post_content', $home_doc ) && ! Layout::is_published( $home_doc ) );
	foreach ( array( 'one', 'two' ) as $suffix ) { $posts[] = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $tag . '-' . $suffix, 'post_name' => $tag . '-' . $suffix, 'post_content' => '<p>' . $tag . '-native-' . $suffix . '</p>' ) ); }
	[ $page1, $page2 ] = $posts;
	$draft_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $tag . '-menu-draft', 'post_name' => $tag . '-menu-draft', 'post_content' => '<p>Synthetic unpublished menu target</p>' ) ); $posts[] = $draft_page;
	$topic = wp_insert_post( array( 'post_type' => 'pc_topic', 'post_status' => 'publish', 'post_title' => $tag . '-topic', 'post_name' => $tag . '-topic', 'post_content' => '<p>' . $tag . '-topic-native</p>' ) ); $posts[] = $topic;
	$article = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $tag . '-article', 'post_name' => $tag . '-article', 'post_content' => '<p>' . $tag . '-article-native</p>' ) ); $posts[] = $article;
	$term = wp_insert_term( $tag, 'product_cat' ); if ( is_wp_error( $term ) ) { throw new RuntimeException( 'Synthetic collection creation failed.' ); } $terms[] = (int) $term['term_id'];
	$product = new WC_Product_Variable(); $product->set_name( $tag . '-variable' ); $product->set_slug( $tag . '-variable' ); $product->set_status( 'publish' ); $product->set_category_ids( $terms );
	$attribute = new WC_Product_Attribute(); $attribute->set_name( 'Size' ); $attribute->set_options( array( 'Small', 'Large' ) ); $attribute->set_visible( true ); $attribute->set_variation( true ); $product->set_attributes( array( $attribute ) ); $product_id = $product->save(); $posts[] = $product_id;
	foreach ( array( 'Small', 'Large' ) as $size ) { $v = new WC_Product_Variation(); $v->set_parent_id( $product_id ); $v->set_attributes( array( 'size' => $size ) ); $v->set_regular_price( '12' ); $v->set_status( 'publish' ); $v->set_virtual( true ); $posts[] = $v->save(); } WC_Product_Variable::sync( $product_id );
	$missing_key = 'custom_' . $page2; $before = count( get_children( array( 'post_parent' => $template, 'post_type' => Templates::LAYOUT, 'post_status' => 'any' ) ) );
	$ok( 'read-only document lookup does not create a missing resource document', 0 === Templates::document_id( $template, $missing_key, false ) && array() === Layout::published_modules( $missing_key, $template ) && $before === count( get_children( array( 'post_parent' => $template, 'post_type' => Templates::LAYOUT, 'post_status' => 'any' ) ) ) );
	$keys = array( 'custom_' . $page1, 'custom_' . $page2, 'topic_' . $topic, 'article_' . $article, 'category_' . $terms[0], 'product_' . $product_id ); $docs = array();
	foreach ( $keys as $key ) { $docs[ $key ] = Templates::document_id( $template, $key ); }
	$ok( 'resource-specific page/topic/article/collection/product documents are distinct', 6 === count( array_unique( array_values( $docs ) ) ) && ! in_array( 0, $docs, true ) );
	$id = $docs[ 'custom_' . $page1 ]; $d0 = $call( 'GET', $id )->get_data();
	$ok( 'REST document includes revision hash and resource-specific signed preview base', 64 === strlen( $d0['revision'] ) && str_contains( $d0['previewBase'], wp_parse_url( get_permalink( $page1 ), PHP_URL_PATH ) ) && str_contains( $d0['previewBase'], Design_Pages::PREVIEW_DOC . '=custom_' . $page1 ) );
	$saved = $save( $id, array( $rich( $tag . '-published-a' ), $native ) );
	$ok( 'resource document saves and publishes through the real REST routes', 200 === $saved->get_status() && 200 === $publish( $id )->get_status() );
	$first = $call( 'GET', $id )->get_data(); $save( $id, array( $rich( $tag . '-draft-b' ), $native ) );
	$ok( 'draft edits leave the published snapshot unchanged', str_contains( wp_json_encode( Layout::published_modules( 'custom_' . $page1, $template ) ), $tag . '-published-a' ) && ! str_contains( wp_json_encode( Layout::published_modules( 'custom_' . $page1, $template ) ), $tag . '-draft-b' ) );
	// Force equal timestamp on this synthetic document: content hash must still catch every stale mutation.
	global $wpdb; $wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $first['modified'] ), array( 'ID' => $id ) ); clean_post_cache( $id );
	$before_conflict = hash( 'sha256', get_post_field( 'post_content', $id ) . wp_json_encode( get_post_meta( $id, Layout::PUBLISHED_META, true ) ) );
	foreach ( array( '' => 'save', '/publish' => 'publish', '/discard' => 'discard', '/unpublish' => 'unpublish' ) as $suffix => $label ) { $r = $call( 'POST', $id, $suffix, array( 'modules' => array(), 'modified' => $first['modified'], 'revision' => $first['revision'] ) ); $ok( 'same-second stale ' . $label . ' is rejected with 409', 409 === $r->get_status() ); }
	$ok( 'conflicted mutations preserve both draft and published snapshot', $before_conflict === hash( 'sha256', get_post_field( 'post_content', $id ) . wp_json_encode( get_post_meta( $id, Layout::PUBLISHED_META, true ) ) ) );
	$preview = $call( 'POST', $id, '/preview', array( 'modules' => array( $rich( $tag . '-token-only' ), $native ) ) )->get_data()['url']; parse_str( (string) wp_parse_url( $preview, PHP_URL_QUERY ), $q ); $preview_tokens[] = $q[ Layout::PREVIEW_PARAM ];
	$_GET = $q; $ok( 'preview token resolves for its issuing user and exact resource only', null !== Layout::preview_draft( $template, 'custom_' . $page1 ) && null === Layout::preview_draft( $template, 'custom_' . $page2 ) ); $_GET = array();
	foreach ( array( 'customer', 'shop_manager' ) as $role ) { $u = wp_insert_user( array( 'user_login' => $tag . '-' . $role, 'user_pass' => wp_generate_password( 32 ), 'role' => $role ) ); if ( is_wp_error( $u ) ) { throw new RuntimeException( 'Synthetic user creation failed.' ); } $users[ $role ] = (int) $u; }
	wp_set_current_user( $users['customer'] ); $_GET = $q;
	$ok( 'customer cannot read/save/publish designs or resolve another persons preview token', in_array( $call( 'GET', $id )->get_status(), array( 401, 403 ), true ) && in_array( $call( 'POST', $id )->get_status(), array( 401, 403 ), true ) && in_array( $call( 'POST', $id, '/publish' )->get_status(), array( 401, 403 ), true ) && null === Layout::preview_draft( $template, 'custom_' . $page1 ) ); $_GET = array();
	wp_set_current_user( $users['shop_manager'] ); $ok( 'shop manager can edit but cannot publish/unpublish', 200 === $call( 'GET', $id )->get_status() && in_array( $call( 'POST', $id, '/publish' )->get_status(), array( 401, 403 ), true ) && in_array( $call( 'POST', $id, '/unpublish' )->get_status(), array( 401, 403 ), true ) );
	wp_set_current_user( $admin ); $_COOKIE[ LOGGED_IN_COOKIE ] = $admin_jar['login'];
	$doc2 = $docs[ 'custom_' . $page2 ]; $save( $doc2, array( $rich( $tag . '-page-two' ), $native ) ); $publish( $doc2 );
	$ok( 'per-resource published documents do not overwrite each other', str_contains( wp_json_encode( Layout::published_modules( 'custom_' . $page1, $template ) ), '-published-a' ) && str_contains( wp_json_encode( Layout::published_modules( 'custom_' . $page2, $template ) ), '-page-two' ) );
	$copy = Templates::duplicate( $template ); $templates[] = $copy;
	$ok( 'duplicating a template preserves independent resource drafts and published snapshots', $copy > 0 && Templates::document_id( $copy, 'custom_' . $page1, false ) !== $id && str_contains( wp_json_encode( Layout::published_modules( 'custom_' . $page1, $copy ) ), '-published-a' ) && str_contains( get_post_field( 'post_content', Templates::document_id( $copy, 'custom_' . $page1, false ) ), '-draft-b' ) );
	foreach ( array( '' => 'save', '/publish' => 'publish', '/discard' => 'discard', '/unpublish' => 'unpublish' ) as $suffix => $label ) {
		$publish( $doc2 ); $old_state = $call( 'GET', $doc2 )->get_data();
		$call( 'POST', $doc2, '/unpublish', array( 'revision' => $old_state['revision'] ) );
		$r = $call( 'POST', $doc2, $suffix, array( 'modules' => $old_state['modules'], 'revision' => $old_state['revision'] ) );
		$ok( 'publication-state change invalidates a stale ' . $label . ' revision', 409 === $r->get_status() );
	}
	$product_doc = $docs[ 'product_' . $product_id ]; $account_doc = Templates::document_id( $template, 'account', false );
	foreach ( array( $product_doc => 'product', $account_doc => 'account' ) as $doc => $label ) {
		$save( $doc, array( $rich( $tag . '-invalid-no-native' ) ) ); $ok( $label . ' cannot publish without native purchasing/account content', 400 === $publish( $doc )->get_status() );
		$hidden = $native; $hidden['attrs']['settings']['showMobile'] = false; $save( $doc, array( $hidden ) ); $ok( $label . ' requires native content visible on both devices', 400 === $publish( $doc )->get_status() );
		$save( $doc, array( $native, $native ) ); $ok( $label . ' rejects duplicate native singleton blocks', 400 === $publish( $doc )->get_status() );
		$save( $doc, array( $rich( $tag . '-' . $label . '-design' ), $native ) ); $ok( $label . ' publishes with exactly one visible native content block', 200 === $publish( $doc )->get_status() );
	}
	$header_doc = Templates::document_id( $template, 'header', false ); $footer_doc = Templates::document_id( $template, 'footer', false ); $global_doc = Templates::document_id( $template, 'global', false );
	foreach ( array( $header_doc, $footer_doc, $global_doc ) as $part ) { $save( $part, array( $native ) ); $ok( get_post_meta( $part, Templates::DOC_META, true ) . ' refuses an embedded native page-content block', 400 === $publish( $part )->get_status() ); }
	$menu = PeptideCore\Menus::create( $tag . '-menu' ); if ( is_wp_error( $menu ) ) { throw new RuntimeException( 'Synthetic menu creation failed.' ); } $menus[] = $menu;
	$menu_saved = PeptideCore\Menus::save( $menu, array( 'name' => $tag . '-menu', 'items' => array( array( 'title' => $tag . '-published-link', 'link' => array( 'type' => 'page', 'id' => $page2 ) ), array( 'title' => $tag . '-draft-link', 'link' => array( 'type' => 'page', 'id' => $draft_page ) ) ) ) );
	$ok( 'document-specific menu can store a public and a draft target without assigning a theme location', ! is_wp_error( $menu_saved ) && '' === PeptideCore\Menus::location_of( $menu ) );
	$header = array( 'block' => 'vialcove/site-header', 'attrs' => array( 'settings' => array( 'brandName' => $tag . '-header', 'showCart' => false, 'menuId' => $menu, 'announcement' => true, 'announcementText' => '<p>' . $tag . '-announcement</p>' ) ) );
	$footer = array( 'block' => 'vialcove/site-footer', 'attrs' => array( 'settings' => array( 'brandName' => $tag . '-footer', 'copyright' => $tag . '-copyright', 'showPolicies' => false ) ) );
	$global = array( 'block' => 'vialcove/site-styles', 'attrs' => array( 'settings' => array( 'contentWidth' => 1111, 'background' => '#123456', 'bodySize' => array( 'desktop' => 19, 'mobile' => 17 ) ) ) );
	foreach ( array( $header_doc => $header, $footer_doc => $footer, $global_doc => $global ) as $doc => $row ) { $save( $doc, array( $row, $row ) ); $ok( 'site part ' . get_post_meta( $doc, Templates::DOC_META, true ) . ' rejects duplicate singleton blocks', 400 === $publish( $doc )->get_status() ); $save( $doc, array( $row ) ); $publish( $doc ); }
	$_GET = $q; $html_single = Layout::render_modules( Layout::clean_modules( array( $header ) ), 'public' ); $_GET = array();
	$ok( 'singleRender header emits a single desktop/mobile DOM instance', 1 === substr_count( $html_single, 'pc-design-header' ) && 1 === substr_count( $html_single, 'pc-design-announcement' ) );
	$live = $http( $preview, $admin_jar['cookies'] );
	$ok( 'authenticated live preview renders token content and original page content', 200 === $live['status'] && str_contains( $live['body'], $tag . '-token-only' ) && 1 === substr_count( $live['body'], $tag . '-native-one' ) );
	$ok( 'live preview uses real header/footer/global settings', 1 === substr_count( $live['body'], 'pc-design-header' ) && 1 === substr_count( $live['body'], 'pc-design-footer' ) && str_contains( $live['body'], $tag . '-copyright' ) && str_contains( $live['body'], '--pc-width:1111px' ) && str_contains( $live['body'], '--pc-background:#123456' ) );
	$ok( 'document-scoped header resolves its published menu link without exposing a draft target', str_contains( $live['body'], $tag . '-published-link' ) && str_contains( $live['body'], esc_url( get_permalink( $page2 ) ) ) && ! str_contains( $live['body'], esc_url( get_permalink( $draft_page ) ) ) );
	if ( 1 !== substr_count( $live['body'], 'pc-design-header' ) || 1 !== substr_count( $live['body'], 'pc-design-footer' ) || ! str_contains( $live['body'], '--pc-width:1111px' ) || ! str_contains( $live['body'], '--pc-background:#123456' ) ) { echo 'DIAGNOSTIC custom-preview ' . wp_json_encode( array( 'headers' => substr_count( $live['body'], 'pc-design-header' ), 'footers' => substr_count( $live['body'], 'pc-design-footer' ), 'width' => str_contains( $live['body'], '--pc-width:1111px' ), 'background' => str_contains( $live['body'], '--pc-background:#123456' ), 'copyright' => str_contains( $live['body'], $tag . '-copyright' ) ) ) . "\n"; }
	$ok( 'live preview contains one document shell and one main landmark', 1 === preg_match_all( '/<!doctype\s+html/i', $live['body'] ) && 1 === preg_match_all( '/<body\b/i', $live['body'] ) && 1 === preg_match_all( '/<head\b/i', $live['body'] ) && 1 === preg_match_all( '/id=["\x27]main-content["\x27]/', $live['body'] ) );
	$ok( 'live preview closes the document and runs footer asset output exactly once', 1 === preg_match_all( '/<\/body>/i', $live['body'] ) && 1 === preg_match_all( '/<\/html>/i', $live['body'] ) && 1 === substr_count( $live['body'], 'id="peptide-site-design-js"' ) );
	$ok( 'signed previews explicitly prevent shared caching', str_contains( strtolower( $live['cache'] ), 'no-cache' ) || str_contains( strtolower( $live['cache'] ), 'no-store' ) );
	$product_preview = Design_Pages::preview_url( $template, 'product_' . $product_id ); $live_product = $http( $product_preview, $admin_jar['cookies'] );
	$ok( 'variable-product preview keeps exactly one native variations purchasing form', 200 === $live_product['status'] && 1 === preg_match_all( '/<form\b[^>]*class=["\x27][^"\x27]*variations_form/', $live_product['body'] ) && str_contains( $live_product['body'], $tag . '-product-design' ) );
	$ok( 'variable-product preview has no duplicate head/body/main/header/footer', 1 === preg_match_all( '/<body\b/i', $live_product['body'] ) && 1 === preg_match_all( '/<head\b/i', $live_product['body'] ) && 1 === preg_match_all( '/id=["\x27]main-content["\x27]/', $live_product['body'] ) && 1 === substr_count( $live_product['body'], 'pc-design-header' ) && 1 === substr_count( $live_product['body'], 'pc-design-footer' ) );
	if ( 1 !== substr_count( $live_product['body'], 'pc-design-header' ) || 1 !== substr_count( $live_product['body'], 'pc-design-footer' ) ) { echo 'DIAGNOSTIC product-preview ' . wp_json_encode( array( 'body' => preg_match_all( '/<body\b/i', $live_product['body'] ), 'head' => preg_match_all( '/<head\b/i', $live_product['body'] ), 'main' => preg_match_all( '/id=["\x27]main-content["\x27]/', $live_product['body'] ), 'headers' => substr_count( $live_product['body'], 'pc-design-header' ), 'footers' => substr_count( $live_product['body'], 'pc-design-footer' ) ) ) . "\n"; }
	$account_preview = Design_Pages::preview_url( $template, 'account' ); $live_account = $http( $account_preview, $admin_jar['cookies'] );
	$ok( 'account preview keeps exactly one original account content area', 200 === $live_account['status'] && 1 === preg_match_all( '/class=["\x27]woocommerce-MyAccount-content["\x27]/', $live_account['body'] ) && str_contains( $live_account['body'], $tag . '-account-design' ) );
	foreach ( array( 'topic_' . $topic => array( get_permalink( $topic ), $tag . '-topic-native' ), 'article_' . $article => array( get_permalink( $article ), $tag . '-article-native' ), 'category_' . $terms[0] => array( get_term_link( $terms[0], 'product_cat' ), $tag . '-variable' ) ) as $key => [ $resource_url, $native_marker ] ) {
		$save( $docs[ $key ], array( $rich( $tag . '-resource-' . $key ), $native ) ); $publish( $docs[ $key ] );
		$resource_preview = Design_Pages::preview_url( $template, $key );
		$r = $http( $resource_preview, $admin_jar['cookies'] );
		$ok( explode( '_', $key )[0] . ' preview targets its actual resource and retains original content', wp_parse_url( $resource_preview, PHP_URL_PATH ) === wp_parse_url( $resource_url, PHP_URL_PATH ) && 200 === $r['status'] && str_contains( $r['body'], $tag . '-resource-' . $key ) && str_contains( $r['body'], $native_marker ) && 1 === substr_count( $r['body'], 'pc-design-footer' ) );
	}
	$customer_jar = $jar( $users['customer'] ); $cross = $http( $preview, $customer_jar['cookies'] ); $guest = $http( $preview );
	$ok( 'other-user and guest HTTP requests cannot see another users token draft', ! str_contains( $cross['body'], $tag . '-token-only' ) && ! str_contains( $guest['body'], $tag . '-token-only' ) );
	$public = $http( get_permalink( $page1 ) ); $ok( 'publishing an unassigned test template does not affect ordinary visitors', 200 === $public['status'] && ! str_contains( $public['body'], $tag . '-published-a' ) && ! str_contains( $public['body'], $tag . '-draft-b' ) && ! str_contains( $public['body'], $tag . '-header' ) );
	parse_str( (string) wp_parse_url( Templates::preview_url( $template, true ), PHP_URL_QUERY ), $base_args );
	foreach ( array( 'cart', 'checkout' ) as $kind ) { $url = wc_get_page_permalink( $kind ); $r = $http( add_query_arg( $base_args, $url ), $admin_jar['cookies'] ); $redirects_to_cart = 302 === $r['status'] && wp_parse_url( $r['location'], PHP_URL_PATH ) === wp_parse_url( wc_get_cart_url(), PHP_URL_PATH ); $ok( $kind . ' remains the native WooCommerce Blocks flow', in_array( $r['status'], array( 200, 302 ), true ) && ( $redirects_to_cart || str_contains( $r['body'], 'wp-block-woocommerce-' . $kind ) || str_contains( $r['body'], 'wc-block-' . $kind ) ) && ! str_contains( $r['body'], 'pc-page-design' ) ); }
	foreach ( array( 'cart', 'checkout' ) as $kind ) { $page = get_post( (int) get_option( 'woocommerce_' . $kind . '_page_id' ) ); $actions = $page ? Design_Pages::row_actions( array(), $page ) : array(); $ok( $kind . ' is not an independently editable design target or row action', $page && ! Design_Pages::can_target( 'custom_' . $page->ID ) && ! isset( $actions['pc_design'] ) ); }
	$ok( 'real default template, domain assignments and original layouts were not changed', $protected_before === $protected() );
} finally {
	wp_set_current_user( $admin ); $_GET = array();
	foreach ( $preview_tokens as $token ) { delete_transient( 'pc_layout_preview_' . $token ); }
	foreach ( $templates as $template_id ) { foreach ( get_children( array( 'post_parent' => $template_id, 'post_type' => Templates::LAYOUT, 'post_status' => 'any', 'fields' => 'ids' ) ) as $doc ) { foreach ( wp_get_post_revisions( $doc, array( 'fields' => 'ids' ) ) as $rev ) { wp_delete_post_revision( $rev ); } wp_delete_post( $doc, true ); } wp_delete_post( $template_id, true ); }
	foreach ( $menus as $menu ) { wp_delete_nav_menu( $menu ); }
	foreach ( array_reverse( $posts ) as $id ) { if ( in_array( get_post_type( $id ), array( 'product', 'product_variation' ), true ) ) { $p = wc_get_product( $id ); if ( $p ) { $p->delete( true ); } } else { foreach ( wp_get_post_revisions( $id, array( 'fields' => 'ids' ) ) as $rev ) { wp_delete_post_revision( $rev ); } wp_delete_post( $id, true ); } }
	foreach ( $terms as $id ) { wp_delete_term( $id, 'product_cat' ); }
	foreach ( $sessions as [ $user, $token ] ) { wp_cache_delete( $user, 'user_meta' ); WP_Session_Tokens::get_instance( $user )->destroy( $token ); }
	require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { wp_delete_user( $id ); }
	remove_filter( 'pre_wp_mail', $no_mail ); wp_set_current_user( $old_user ); $_GET = $old_get; $_COOKIE = $old_cookie;
}
$remaining = array_filter( array_merge( $posts, $templates ), static fn( $id ) => (bool) get_post( $id ) );
$remaining_sessions = 0; foreach ( $sessions as [ $user, $token ] ) { wp_cache_delete( $user, 'user_meta' ); if ( WP_Session_Tokens::get_instance( $user )->verify( $token ) ) { ++$remaining_sessions; } }
$ok( 'cleanup removes synthetic resources/templates and only its temporary sessions, keeping real template state unchanged', ! $remaining && 0 === $remaining_sessions && $protected_before === $protected() && ! array_filter( $users, static fn( $id ) => (bool) get_userdata( $id ) ) );
echo "RESULT {$pass} passed, {$fail} failed\n";
if ( $fail ) { throw new RuntimeException( 'Page design integration regression failed.' ); }
