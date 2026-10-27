<?php
/**
 * 首页SEO 和活动专题页（照 YY 后台，2026-10-27）冒烟测试。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-seo.php
 * 结束时恢复 SEO 设置，删除测试专题页。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Seo;
use PeptideCore\Topics;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$orig_seo  = get_option( Seo::OPTION, null );
$orig_host = $_SERVER['HTTP_HOST'] ?? null;
$topics    = array();
$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

// 1. 设置清理。
$set = Seo::clean_set( array( 'title' => '  <b>Store</b> title ', 'description' => "Line one\nline two", 'keywords' => "peptides, COA\npeptides\n\n" ) );
$ok( 'a set is cleaned: tags removed, keywords split by comma or line, duplicates dropped', 'Store title' === $set['title'] && "Line one\nline two" === $set['description'] && array( 'peptides', 'COA' ) === $set['keywords'] );
$ok( 'title and description are capped at 2048 characters', Seo::MAX_TEXT === mb_strlen( Seo::clean_set( array( 'title' => str_repeat( 'a', 3000 ) ) )['title'] ) );

// 2. 默认模板和域名指定模板。
Seo::save_home( array( 'title' => 'Home {current_domain}', 'description' => 'Default description', 'keywords' => array( 'one', 'two' ) ) );
$ok( 'domain must be valid', is_wp_error( Seo::save_domain( 'not a domain', array( 'title' => 'x' ) ) ) );
$saved = Seo::save_domain( 'https://WWW.Shop-Example.com/path', array( 'title' => 'Domain title', 'description' => 'Domain description', 'keywords' => 'alpha|beta' ) );
$ok( 'a domain is saved by its host name', 'www.shop-example.com' === $saved && isset( Seo::settings()['domains']['www.shop-example.com'] ) );
$_SERVER['HTTP_HOST'] = 'www.shop-example.com';
$ok( 'the domain’s own entry is used on that domain', 'Domain title' === Seo::home_set()['title'] );
$_SERVER['HTTP_HOST'] = 'other-example.com';
$ok( 'other domains use the default template', 'Home {current_domain}' === Seo::home_set()['title'] );
$ok( '{current_domain} is replaced by the domain the visitor opened', 'Home other-example.com' === Seo::vars( 'Home {current_domain}' ) );
$_SERVER['HTTP_HOST'] = '192.168.0.13:17850';
$ok( 'an IP address falls back to the default template and the site domain', 'Home {current_domain}' === Seo::home_set()['title'] && 'Home ' . $site_host === Seo::vars( 'Home {current_domain}' ) );
$ok( 'saved domains are offered in the domain list', in_array( 'www.shop-example.com', Seo::known_domains(), true ) );
Seo::save_domain( 'www.shop-example.com', array( 'title' => '', 'description' => '', 'keywords' => '' ) );
$ok( 'emptying all three fields removes the domain entry', ! isset( Seo::settings()['domains']['www.shop-example.com'] ) );

// 3. 导出 / 导入 CSV。
Seo::save_domain( 'a-example.com', array( 'title' => '=cmd', 'description' => 'Desc, with comma', 'keywords' => array( 'k1', 'k2' ) ) );
$csv = Seo::export_csv();
$ok( 'export: header, domain row, keywords joined with |, formula cells neutralised', str_contains( $csv, 'domain,title,description,keywords' ) && str_contains( $csv, "a-example.com,'=cmd,\"Desc, with comma\",k1|k2" ) );
Seo::save_domain( 'a-example.com', array() );
[ $count, $errs ] = Seo::import_csv( $csv . "bad domain!,x,y,z\n" );
$imported = Seo::settings()['domains']['a-example.com'] ?? null;
$ok( 'import restores the exported rows and reports bad lines', 1 === $count && 1 === count( $errs ) && $imported && '=cmd' === $imported['title'] && array( 'k1', 'k2' ) === $imported['keywords'] );

// 4. 首页输出。
$_SERVER['HTTP_HOST'] = $site_host;
query_posts( array( 'page_id' => (int) get_option( 'page_on_front' ) ) );
$GLOBALS['wp_query']->is_home       = true;
$GLOBALS['wp_query']->is_front_page = true;
add_filter( 'pre_option_show_on_front', static fn() => 'posts', 99 );
ob_start();
Seo::head();
$head = (string) ob_get_clean();
$ok( 'home page: title replaced, description, keywords and social tags printed', 'Home ' . $site_host === Seo::document_title( 'Old' ) && str_contains( $head, 'name="description" content="Default description"' ) && str_contains( $head, 'name="keywords" content="one, two"' ) && str_contains( $head, 'og:title' ) && str_contains( $head, 'twitter:card' ) );
wp_reset_query();

// 5. 活动专题页。
$ok( 'campaign page type is registered with the /topics/ address', post_type_exists( Topics::POST_TYPE ) && 'topics' === get_post_type_object( Topics::POST_TYPE )->rewrite['slug'] );
$id = wp_insert_post( array( 'post_type' => Topics::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Smoke Summer Sale', 'post_name' => 'smoke-summer-sale', 'post_excerpt' => 'Short smoke description', 'post_content' => '<p>Smoke body</p>' ) );
$topics[] = $id;
$ok( 'a campaign page lives at /topics/<slug>/', str_contains( (string) get_permalink( $id ), '/topics/smoke-summer-sale/' ) );
$seo = Topics::seo( $id );
$ok( 'without its own SEO it uses the title and the short description', 'Smoke Summer Sale' === $seo['title'] && 'Short smoke description' === $seo['description'] );
update_post_meta( $id, Topics::META_SEO, Seo::clean_set( array( 'title' => 'Own SEO title', 'keywords' => 'sale, summer' ) ) );
$seo = Topics::seo( $id );
$ok( 'its own SEO title and keywords win', 'Own SEO title' === $seo['title'] && array( 'sale', 'summer' ) === $seo['keywords'] && 'Short smoke description' === $seo['description'] );
$ok( 'the title shows by default', Topics::shows_title( $id ) );
update_post_meta( $id, Topics::META_TITLE, '0' );
$ok( 'and can be hidden', ! Topics::shows_title( $id ) );
$ok( 'the page template is used when the theme has no campaign template', in_array( 'page.php', ( static function () use ( $id ) {
	query_posts( array( 'p' => $id, 'post_type' => Topics::POST_TYPE ) );
	$t = Topics::template_hierarchy( array( 'single-pc_topic.php', 'single.php', 'singular.php' ) );
	wp_reset_query();
	return $t;
} )(), true ) );

// 前台真实请求：页面能打开、SEO 标签、访问量、改链接后旧链接跳转。
flush_rewrite_rules( false );
$res  = wp_remote_get( get_permalink( $id ), array( 'timeout' => 20 ) );
$body = (string) wp_remote_retrieve_body( $res );
$ok( 'the campaign page opens on the storefront with its SEO tags', 200 === (int) wp_remote_retrieve_response_code( $res ) && str_contains( $body, '<title>Own SEO title</title>' ) && str_contains( $body, 'Smoke body' ) && str_contains( $body, 'name="keywords" content="sale, summer"' ) );
$ok( 'a hidden title is not printed above the content', ! str_contains( $body, '>Smoke Summer Sale</h1>' ) );
wp_cache_delete( $id, 'post_meta' ); // 访问量是另一个请求写的，读之前清掉这里的缓存。
$ok( 'visits are counted', (int) get_post_meta( $id, Topics::META_VIEWS, true ) >= 1 );
wp_update_post( array( 'ID' => $id, 'post_name' => 'smoke-winter-sale' ) );
$old = wp_remote_get( home_url( '/topics/smoke-summer-sale/' ), array( 'timeout' => 20, 'redirection' => 0 ) );
$ok( 'after the link changes, the old link redirects to the new one', 301 === (int) wp_remote_retrieve_response_code( $old ) && str_contains( (string) wp_remote_retrieve_header( $old, 'location' ), '/topics/smoke-winter-sale/' ) );
wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
$draft = wp_remote_get( home_url( '/topics/smoke-winter-sale/' ), array( 'timeout' => 20, 'redirection' => 0 ) );
$ok( 'a draft campaign page is not visible', 404 === (int) wp_remote_retrieve_response_code( $draft ) );

// 6. 后台：菜单入口、列表列。
$cols = Topics::columns( array( 'cb' => '', 'title' => 'Title', 'date' => 'Date' ) );
$ok( 'list shows cover, views and last modified like YY', array( 'cb', 'pc_cover', 'title', 'pc_views', 'pc_modified', 'date' ) === array_keys( $cols ) );
$ok( 'store design menu points to the campaign page list', array_key_exists( 'edit.php?post_type=pc_topic', \PeptideCore\Admin\Store_Design::pages() ) );

// 清理。
foreach ( $topics as $t ) {
	wp_delete_post( $t, true );
}
if ( null === $orig_seo ) {
	delete_option( Seo::OPTION );
} else {
	update_option( Seo::OPTION, $orig_seo, false );
}
if ( null === $orig_host ) {
	unset( $_SERVER['HTTP_HOST'] );
} else {
	$_SERVER['HTTP_HOST'] = $orig_host;
}
$ok( 'settings restored and test pages deleted', $orig_seo === get_option( Seo::OPTION, null ) && ! get_post( $id ) );
