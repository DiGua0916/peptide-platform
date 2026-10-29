<?php
/**
 * 博客、素材管理、操作记录、员工管理（照 YY 后台，2026-10-27）冒烟测试。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-backend.php
 * 外部下载用 pre_http_request 模拟；员工权限用生成的登录 Cookie 真实请求后台页面。结束时删掉测试数据。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Activity_Log;
use PeptideCore\Admin\Staff_Page;
use PeptideCore\Blog;
use PeptideCore\Media_Library;
use PeptideCore\Staff;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

global $wpdb;
add_filter( 'peptide_core_activity_log_cli', '__return_true' ); // 操作记录平时不记命令行，这里要测。
$posts     = array();
$terms     = array();
$users     = array();
$media     = array();
$groups    = array();
$orig_q    = get_option( Media_Library::QUEUE_OPTION, null );
$orig_st   = get_option( 'sticky_posts', array() );
Activity_Log::query( array() ); // 表不存在时建好。
$log_max   = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . Activity_Log::table() ); // phpcs:ignore WordPress.DB
$admin_id  = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ) )[0] ?? 0 );
$product   = (int) ( get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) )[0] ?? 0 );
$http      = static fn( string $url, array $args = array() ) => wp_remote_get( $url, $args + array( 'timeout' => 30, 'redirection' => 0 ) );
$orig_last = get_user_meta( $admin_id, Staff::META_LOGIN, true ); // 下面模拟管理员登录会改“最后登录”，结束时还原。

// ===== 1. 博客 =====
$cat = wp_insert_term( 'Smoke Research Notes', 'category', array( 'slug' => 'smoke-research-notes', 'description' => 'Smoke collection description' ) );
$cid = (int) $cat['term_id'];
$terms[] = $cid;
$old = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Smoke Older Pinned', 'post_name' => 'smoke-older-pinned', 'post_date' => '2026-01-01 10:00:00', 'post_category' => array( $cid ) ) );
$new = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Smoke Newer Post', 'post_name' => 'smoke-newer-post', 'post_excerpt' => 'Smoke short description', 'post_content' => '<p>Smoke blog body</p>', 'post_category' => array( $cid ) ) );
$posts = array( $old, $new );
stick_post( $old );
update_post_meta( $new, Blog::META_SEO, PeptideCore\Seo::clean_set( array( 'title' => 'Smoke Blog SEO', 'keywords' => 'peptide, research' ) ) );
update_post_meta( $new, Blog::META_AUTHOR, 'Dr. Smoke' );
update_post_meta( $new, Blog::META_PRODUCTS, Blog::clean_products( array( $product, $product, 999999999 ) ) );
update_term_meta( $cid, Blog::META_SEO, PeptideCore\Seo::clean_set( array( 'title' => 'Smoke Collection SEO' ) ) );

$ok( 'blog addresses: /blogs/<slug>/, list /blogs/, collection /news/<slug>/', str_ends_with( (string) get_permalink( $new ), '/blogs/smoke-newer-post/' ) && str_ends_with( get_post_type_archive_link( 'post' ), '/blogs/' ) && str_ends_with( (string) get_term_link( $cid ), '/news/smoke-research-notes/' ) );
$ok( 'related products keep only existing products, no duplicates', array( $product ) === get_post_meta( $new, Blog::META_PRODUCTS, true ) );

$list = (string) wp_remote_retrieve_body( $http( home_url( '/blogs/' ) ) );
$ok( 'the blog list opens and the pinned post comes first', str_contains( $list, 'Smoke Newer Post' ) && strpos( $list, 'Smoke Older Pinned' ) < strpos( $list, 'Smoke Newer Post' ) );
$res  = $http( (string) get_permalink( $new ) );
$body = (string) wp_remote_retrieve_body( $res );
$ok( 'a blog post opens with its own SEO title, keywords and description from the short description', 200 === (int) wp_remote_retrieve_response_code( $res ) && str_contains( $body, '<title>Smoke Blog SEO</title>' ) && str_contains( $body, 'name="keywords" content="peptide, research"' ) && str_contains( $body, 'content="Smoke short description"' ) );
$ok( 'products chosen for the post are shown under it', str_contains( $body, 'pc-blog-products' ) && str_contains( $body, esc_html( get_the_title( $product ) ) ) );
$old_link = $http( home_url( '/smoke-newer-post/' ) );
$ok( 'the old address /<slug>/ redirects to /blogs/<slug>/ (301)', 301 === (int) wp_remote_retrieve_response_code( $old_link ) && str_contains( (string) wp_remote_retrieve_header( $old_link, 'location' ), '/blogs/smoke-newer-post/' ) );
$coll = (string) wp_remote_retrieve_body( $http( (string) get_term_link( $cid ) ) );
$ok( 'a blog collection opens at /news/ with its SEO title', str_contains( $coll, '<title>Smoke Collection SEO</title>' ) && str_contains( $coll, 'Smoke Newer Post' ) );
wp_cache_delete( $new, 'post_meta' );
wp_cache_delete( $cid, 'term_meta' );
$ok( 'visits are counted for posts and collections', (int) get_post_meta( $new, Blog::META_VIEWS, true ) >= 1 && (int) get_term_meta( $cid, Blog::META_VIEWS, true ) >= 1 );
$GLOBALS['post'] = get_post( $new );
$ok( 'the author name typed in “Author” is shown instead of the account', 'Dr. Smoke' === Blog::author_name( 'admin' ) );
unset( $GLOBALS['post'] );
$seo = Blog::seo_term( $cid );
$ok( 'collection SEO falls back to its description', 'Smoke Collection SEO' === $seo['title'] && 'Smoke collection description' === $seo['description'] );

update_post_meta( $new, Blog::META_SEO, PeptideCore\Seo::clean_set( array( 'title' => '=HYPERLINK("x")' ) ) );
$csv = Blog::export_csv();
$ok( 'export: header and rows, sticky and collections, formula cells neutralised', str_contains( $csv, implode( ',', Blog::CSV_COLUMNS ) ) && str_contains( $csv, 'smoke-older-pinned,"Smoke Older Pinned",publish,1' ) && str_contains( $csv, 'smoke-research-notes' ) && str_contains( $csv, "'=HYPERLINK" ) );
wp_set_current_user( $admin_id );
$import = "slug,title,status,sticky,collections,seo_title\nsmoke-newer-post,Smoke Newer Post Renamed,publish,0,smoke-research-notes,'=keep\nsmoke-imported-post,Smoke Imported Post,draft,1,smoke-new-collection,\n,,,\n";
[ $created, $updated, $errs ] = Blog::import_csv( $import );
$imported = get_page_by_path( 'smoke-imported-post', OBJECT, 'post' );
if ( $imported ) {
	$posts[] = $imported->ID;
}
$newcat = get_term_by( 'slug', 'smoke-new-collection', 'category' );
if ( $newcat ) {
	$terms[] = (int) $newcat->term_id;
}
$ok( 'import: same slug updates, new slug creates, missing collection is created', 1 === $created && 1 === $updated && 'Smoke Newer Post Renamed' === get_the_title( $new ) && $imported && 'draft' === $imported->post_status && $newcat && has_term( (int) $newcat->term_id, 'category', $imported->ID ) );
$ok( 'import keeps a leading = as text (the export neutraliser is undone)', '=keep' === Blog::seo_post( $new )['title'] && 1 === count( $errs ) );
$cols = PeptideCore\Admin\Blog_Admin::columns( array( 'cb' => '', 'title' => '', 'author' => '', 'date' => '' ) );
$ok( 'blog list columns like YY', array( 'cb', 'title', 'pc_cover', 'pc_status', 'pc_sticky', 'categories', 'pc_views', 'pc_modified' ) === array_keys( $cols ) );

// ===== 2. 素材管理 =====
$g1 = Media_Library::create_group( 'Smoke Vials' );
$groups[] = $g1;
$ok( 'groups: create, empty name refused, rename', is_int( $g1 ) && is_wp_error( Media_Library::create_group( '  ' ) ) && true === Media_Library::rename_group( $g1, 'Smoke Vial Photos' ) && 'Smoke Vial Photos' === Media_Library::groups()[ $g1 ]['name'] );
delete_option( Media_Library::QUEUE_OPTION );
$ok( 'collecting: only public http(s) addresses', is_wp_error( Media_Library::enqueue( array( 'ftp://example.com/a.png' ), 0 ) ) && is_wp_error( Media_Library::enqueue( array( 'not a url' ), 0 ) ) );
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDK+mmyAAAAABJRU5ErkJggg==' );
$mock = static function ( $pre, $args, $url ) use ( $png ) {
	if ( str_contains( $url, 'pc-smoke-media.example' ) ) {
		if ( str_contains( $url, 'missing' ) ) {
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
		}
		if ( ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return array( 'headers' => array( 'content-type' => 'image/png' ), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
	}
	return $pre;
};
add_filter( 'pre_http_request', $mock, 10, 3 );
add_filter( 'http_request_host_is_external', static fn( $ext, $host ) => str_contains( $host, 'pc-smoke-media.example' ) ? true : $ext, 10, 2 );
$added = Media_Library::enqueue( array( 'https://pc-smoke-media.example/vial-photo.png', 'https://pc-smoke-media.example/vial-photo.png', 'https://pc-smoke-media.example/image?id=7', 'https://pc-smoke-media.example/missing.png', 'http://127.0.0.1/private.png' ), $g1 );
$ok( 'addresses are queued once each and show under “Being collected”', 4 === $added && 4 === count( array_filter( Media_Library::queue(), static fn( $it ) => 'collecting' === Media_Library::queue_tab( $it ) ) ) );
$r = Media_Library::process( 10 );
remove_filter( 'pre_http_request', $mock, 10 );
$queue = Media_Library::queue();
$found = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 5, 'meta_key' => Media_Library::META_SOURCE, 'meta_value' => 'https://pc-smoke-media.example/vial-photo.png', 'fields' => 'ids' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
$noext = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 5, 'meta_key' => Media_Library::META_SOURCE, 'meta_value' => 'https://pc-smoke-media.example/image?id=7', 'fields' => 'ids' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
$media = array_merge( $found, $noext );
$ok( 'collected images become media files in the chosen group, with the source address', 2 === $r['done'] && 1 === count( $found ) && $g1 === Media_Library::group_of( (int) $found[0] ) );
$ok( 'an address without a file extension is recognised by its content (PNG)', 1 === count( $noext ) && str_ends_with( (string) get_attached_file( (int) $noext[0] ), '.png' ) );
$failed = array_values( array_filter( $queue, static fn( $it ) => 'failed' === $it['status'] ) );
$ok( 'a 404 and a private-network address fail with a reason (the private one is refused by the safe download)', 2 === $r['failed'] && 2 === count( $failed ) && '' !== $failed[0]['error'] && '' !== $failed[1]['error'] );
$ids = array_column( $failed, 'id' );
$ok( 'retry puts failed addresses under “Retrying”; remove takes them out', 2 === Media_Library::retry( $ids ) && 'retrying' === Media_Library::queue_tab( Media_Library::queue()[ $ids[0] ] ) && 2 === Media_Library::dequeue( $ids ) && ! Media_Library::queue() );
wp_set_current_user( $admin_id );
$ok( 'change group / no group', 1 === Media_Library::set_group( array( (int) $noext[0] ), $g1 ) && $g1 === Media_Library::group_of( (int) $noext[0] ) && 1 === Media_Library::set_group( array( (int) $noext[0] ), 0 ) && 0 === Media_Library::group_of( (int) $noext[0] ) );
[ $in_group, $n_group ] = Media_Library::query( array( 'group' => $g1 ) );
[ $ungrouped ]         = Media_Library::query( array( 'group' => -1, 'per_page' => 200 ) );
$ok( 'filter by group and by “Ungrouped”; item shows size, address and source', 1 === $n_group && 2 === $in_group[0]['width'] && str_starts_with( $in_group[0]['url'], 'http' ) && 'https://pc-smoke-media.example/vial-photo.png' === $in_group[0]['source'] && in_array( (int) $noext[0], array_column( $ungrouped, 'id' ), true ) );
$ok( 'export lists media with group and source', str_contains( Media_Library::export_csv(), 'https://pc-smoke-media.example/vial-photo.png' ) );
$ok( 'deleting a group keeps its media (ungrouped)', Media_Library::delete_group( $g1 ) && 0 === Media_Library::group_of( (int) $found[0] ) && 'attachment' === get_post_type( (int) $found[0] ) );

// ===== 3. 操作记录 =====
$rows_after = static function () use ( $wpdb, &$log_max ): array {
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Activity_Log::table() . ' WHERE id > %d ORDER BY id', $log_max ), ARRAY_A ); // phpcs:ignore WordPress.DB
};
$log_start = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . Activity_Log::table() ); // phpcs:ignore WordPress.DB
wp_set_current_user( $admin_id );
$pid = wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Smoke Log Product' ) );
$posts[] = $pid;
wp_update_post( array( 'ID' => $pid, 'post_content' => 'edited once' ) );
wp_update_post( array( 'ID' => $pid, 'post_content' => 'edited twice' ) );
wp_publish_post( $pid );
wp_trash_post( $pid );
$events = array_map( static fn( $r ) => $r['module'] . ':' . $r['event'], array_filter( $rows_after(), static fn( $r ) => (int) $r['object_id'] === $pid ) );
$ok( 'product: create, edit (twice within 10 s counted once), put on sale, trash', array( 'product:create', 'product:edit', 'product:publish', 'product:trash' ) === array_values( $events ) );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Activity_Log::table() . " WHERE object_id = %d AND event = 'publish'", $pid ), ARRAY_A ); // phpcs:ignore WordPress.DB
$ok( 'a record has operator, type, Trace ID and time', $row && (int) $row['user_id'] === $admin_id && 'administrator' === $row['user_role'] && 36 === strlen( $row['trace_id'] ) && '' !== $row['created_at'] );
$ok( 'products “publish” reads as “Put on sale”', 'Put on sale' === Activity_Log::event_label( 'publish', 'product' ) || '上架' === Activity_Log::event_label( 'publish', 'product' ) );
$customer = wp_insert_user( array( 'user_login' => 'pc_smoke_customer', 'user_email' => 'pc-smoke-customer@example.com', 'user_pass' => wp_generate_password( 20 ), 'role' => 'customer' ) );
$users[]  = $customer;
$before   = count( $rows_after() );
wp_set_current_user( $customer );
wp_update_post( array( 'ID' => $new, 'post_content' => 'customer should not be logged' ) );
do_action( 'wp_login_failed', 'pc_smoke_customer' );
$ok( 'customers are not recorded (not even failed logins)', count( $rows_after() ) === $before );
wp_set_current_user( $admin_id );
$admin_user = get_userdata( $admin_id );
do_action( 'wp_login', $admin_user->user_login, $admin_user );
do_action( 'wp_login_failed', $admin_user->user_login );
$last2 = array_slice( array_map( static fn( $r ) => $r['module'] . ':' . $r['event'], $rows_after() ), -2 );
$ok( 'admin logins and failed logins are recorded', array( 'login:login', 'login:login_failed' ) === $last2 );
[ $q_rows, $q_total ] = Activity_Log::query( array( 'module' => 'login', 'user' => $admin_user->user_login ) );
$ok( 'filter by module and operator', $q_total >= 2 && 'login' === $q_rows[0]['module'] );
$ok( 'browser and device are read from the user agent', 'Chrome 156' === Activity_Log::browser( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36' ) && 'Windows 10' === Activity_Log::device( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' ) && 'Safari 18' === Activity_Log::browser( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1' ) && 'iPhone' === Activity_Log::device( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)' ) );
$ok( 'plugin forms are put under the right module and event', 'domain' === Activity_Log::action_module( 'pc_domain_primary' ) && 'status' === Activity_Log::action_event( 'pc_domain_primary' ) && 'coupon' === Activity_Log::action_module( 'pc_coupon_save' ) && 'save' === Activity_Log::action_event( 'pc_coupon_save' ) && 'delete' === Activity_Log::action_event( 'pc_shipping_delete' ) && 'settings' === Activity_Log::action_module( 'pc_terms_save' ) );
$wpdb->insert( Activity_Log::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ), 'module' => 'login', 'event' => 'login', 'user_login' => 'pc-smoke-old' ) ); // phpcs:ignore WordPress.DB
$ok( 'records older than 365 days are pruned', Activity_Log::prune() >= 1 && ! $wpdb->get_var( "SELECT id FROM " . Activity_Log::table() . " WHERE user_login = 'pc-smoke-old'" ) ); // phpcs:ignore WordPress.DB

// ===== 4. 员工管理 =====
$ok( 'staff: invalid email and short password are refused', is_wp_error( Staff_Page::save( 0, array( 'login' => 'pc_smoke_staff', 'email' => 'nope', 'password' => wp_generate_password( 12 ) ) ) ) && is_wp_error( Staff_Page::save( 0, array( 'login' => 'pc_smoke_staff', 'email' => 'pc-smoke-staff@example.com', 'password' => 'short' ) ) ) );
$sid = Staff_Page::save( 0, array( 'login' => 'pc_smoke_staff', 'email' => 'pc-smoke-staff@example.com', 'name' => 'Smoke Staff', 'password' => wp_generate_password( 16 ), 'modules' => array( 'products', 'nonsense' ) ) );
$users[] = $sid;
$staff   = new WP_User( $sid );
$ok( 'a staff account is created with only the ticked menus', is_int( $sid ) && Staff::is_staff( $sid ) && array( 'products' ) === Staff::granted( $sid ) );
$ok( 'ticked menu gives its abilities; others are not given', $staff->has_cap( 'edit_products' ) && $staff->has_cap( 'upload_files' ) && ! $staff->has_cap( 'manage_woocommerce' ) && ! $staff->has_cap( 'edit_posts' ) && ! $staff->has_cap( 'manage_options' ) );
$ok( 'admin pages map to menus', 'products' === Staff::module_for( 'edit.php', '', 'product' ) && 'products' === Staff::module_for( 'edit-tags.php', '', '', 'pa_strength' ) && 'blog' === Staff::module_for( 'edit.php' ) && 'design' === Staff::module_for( 'admin.php', 'pc-store-design-seo' ) && 'data' === Staff::module_for( 'admin.php', 'pc-visitors-archive' ) && 'customers' === Staff::module_for( 'admin.php', 'wc-admin', '', '', '/customers' ) && 'backend' === Staff::module_for( 'admin.php', 'pc-activity' ) && null === Staff::module_for( 'admin.php', 'pc-staff' ) && null === Staff::module_for( 'users.php' ) && 'always' === Staff::module_for( 'profile.php' ) );
$ok( 'menu slugs map the same way', 'data' === Staff::module_for_url( 'wc-admin&path=/analytics/overview' ) && 'settings' === Staff::module_for_url( 'admin.php?page=wc-settings&page=pc-shipping' ) && null === Staff::module_for_url( admin_url() ) );
$ok( 'platform pages belong to no menu: system status, WooCommerce general/advanced, WooCommerce home and marketplace', null === Staff::module_for( 'admin.php', 'wc-status' ) && null === Staff::module_for( 'admin.php', 'pc-settings' ) && null === Staff::module_for( 'admin.php', 'wc-settings' ) && null === Staff::module_for( 'admin.php', 'wc-settings', '', '', '', 'advanced' ) && null === Staff::module_for_url( 'admin.php?page=wc-settings&tab=general' ) && null === Staff::module_for( 'admin.php', 'wc-admin' ) && null === Staff::module_for( 'admin.php', 'wc-admin', '', '', '/extensions' ) );
$ok( 'store tabs of WooCommerce settings stay under Store settings (shipping, tax, payments, email)', 'settings' === Staff::module_for_url( 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM' ) && 'settings' === Staff::module_for_url( 'admin.php?page=wc-settings&tab=email&section=wc_email_new_order' ) && 'settings' === Staff::module_for( 'admin.php', 'wc-settings', '', '', '', 'tax' ) && 'settings' === Staff::module_for_url( 'wc-admin&path=/payments/overview' ) );

$cookies = static function ( int $uid ): array {
	$exp = time() + HOUR_IN_SECONDS;
	return array(
		new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $uid, $exp, 'auth' ) ) ),
		new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $uid, $exp, 'logged_in' ) ) ),
	);
};
$jar      = $cookies( $sid );
$products = $http( admin_url( 'edit.php?post_type=product' ), array( 'cookies' => $jar ) );
$pbody    = (string) wp_remote_retrieve_body( $products );
$ok( 'staff with “Products” can open the product list (real request)', 200 === (int) wp_remote_retrieve_response_code( $products ) && str_contains( $pbody, 'id="adminmenu"' ) );
$ok( 'their menu shows Products but not Store settings, Orders or Blog', str_contains( $pbody, 'edit.php?post_type=product' ) && ! str_contains( $pbody, 'page=pc-shipping' ) && ! str_contains( $pbody, 'page=wc-orders' ) && ! preg_match( '#href=["\']edit\.php["\']#', $pbody ) );
$denied = $http( admin_url( 'admin.php?page=pc-shipping' ), array( 'cookies' => $jar ) );
$denied_blog = $http( admin_url( 'edit.php' ), array( 'cookies' => $jar ) );
$ok( 'pages of unticked menus are refused (403)', 403 === (int) wp_remote_retrieve_response_code( $denied ) && 403 === (int) wp_remote_retrieve_response_code( $denied_blog ) );
$home = $http( admin_url(), array( 'cookies' => $jar ) );
$ok( 'opening the dashboard goes to their first menu', 302 === (int) wp_remote_retrieve_response_code( $home ) && str_contains( (string) wp_remote_retrieve_header( $home, 'location' ), 'edit.php?post_type=product' ) );
$ok( 'after login they land on their first menu', str_contains( (string) Staff::login_redirect( admin_url(), admin_url(), $staff ), 'edit.php?post_type=product' ) && str_contains( (string) Staff::login_redirect( admin_url(), admin_url( 'edit.php?post_type=product&orderby=title' ), $staff ), 'orderby=title' ) );

$other_media = (int) $found[0];
$ok( 'without “Admin settings” staff cannot delete others’ media', ! user_can( $sid, 'delete_post', $other_media ) );
wp_set_current_user( $admin_id );
Staff_Page::save( $sid, array( 'email' => 'pc-smoke-staff@example.com', 'name' => 'Smoke Staff', 'modules' => array( 'products', 'backend' ) ) );
Staff::flush_cache();
$ok( 'with “Admin settings” they can manage all media and see the activity log', user_can( $sid, 'delete_post', $other_media ) && user_can( $sid, Activity_Log::CAP ) );
$perm = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Activity_Log::table() . " WHERE object_id = %d AND module = 'staff' AND event = 'permissions'", $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB
$ok( 'permission changes are written to the activity log with before / after', $perm && str_contains( (string) $perm['details'], 'from' ) && str_contains( (string) $perm['details'], 'to' ) );

// 勾了“店铺设置”的销售：店铺用到的 WooCommerce 设置标签能打开，平台页面（系统状态、常规、高级）不能。
Staff_Page::save( $sid, array( 'email' => 'pc-smoke-staff@example.com', 'name' => 'Smoke Staff', 'modules' => array( 'products', 'backend', 'settings', 'customers' ) ) );
Staff::flush_cache();
$code = static fn( string $p ): int => (int) wp_remote_retrieve_response_code( $http( admin_url( $p ), array( 'cookies' => $cookies( $sid ) ) ) );
$tax  = $http( admin_url( 'admin.php?page=wc-settings&tab=tax' ), array( 'cookies' => $cookies( $sid ) ) );
$ok( 'with “Store settings” the tax tab opens and its tab bar has no platform tabs (real request)', 200 === (int) wp_remote_retrieve_response_code( $tax ) && ! str_contains( (string) wp_remote_retrieve_body( $tax ), 'tab=advanced' ) && ! str_contains( (string) wp_remote_retrieve_body( $tax ), 'tab=general' ) );
$ok( 'platform pages are refused even with “Store settings”: system status, WooCommerce general and advanced (403)', 403 === $code( 'admin.php?page=wc-status' ) && 403 === $code( 'admin.php?page=wc-settings' ) && 403 === $code( 'admin.php?page=wc-settings&tab=advanced' ) && 403 === $code( 'admin.php?page=wc-admin' ) );
$ok( 'WooCommerce pages of ticked menus still open: customers, payments (200)', 200 === $code( 'admin.php?page=wc-admin&path=/customers' ) && 200 === $code( 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM' ) );

// 营销独立授权：真实请求跑过 WordPress 的菜单注册和页面权限检查，再提交一个无效表单走保存权限与验证。
// 表单故意缺少必填信息，不创建活动；跟随返回的编辑页读取并清掉临时错误/表单内容。
Staff::set_modules( $sid, array( 'marketing' ) );
$ok( 'Marketing alone does not grant the shared store-management capability', user_can( $sid, 'edit_shop_coupons' ) && ! user_can( $sid, 'manage_woocommerce' ) );
$marketing_pages = array(
	'pc-promotions'         => 'pc_promo_save',
	'pc-coupons'            => 'pc_coupon_save',
	'pc-shipping-discounts' => 'pc_shipdisc_save',
);
$marketing_jar = $cookies( $sid ); // 读取表单和提交时复用同一会话，WordPress nonce 绑定登录令牌。
foreach ( $marketing_pages as $page => $action ) {
	$list = $http( admin_url( 'admin.php?page=' . $page ), array( 'cookies' => $marketing_jar ) );
	$form = $http( admin_url( 'admin.php?page=' . $page . '&new=1' ), array( 'cookies' => $marketing_jar ) );
	$body = (string) wp_remote_retrieve_body( $form );
	$ok( "Marketing alone opens $page and its new-item form (real requests)", 200 === (int) wp_remote_retrieve_response_code( $list ) && str_contains( (string) wp_remote_retrieve_body( $list ), 'id="adminmenu"' ) && 200 === (int) wp_remote_retrieve_response_code( $form ) && str_contains( $body, 'name="action" value="' . $action . '"' ) );
	$matched = preg_match( '#name="action" value="' . preg_quote( $action, '#' ) . '".*?name="_wpnonce" value="([^"]+)"#s', $body, $nonce_match );
	$submitted = $matched ? wp_remote_post(
		admin_url( 'admin-post.php' ),
		array(
			'timeout'     => 30,
			'redirection' => 0,
			'cookies'     => $marketing_jar,
			'body'        => array( 'action' => $action, '_wpnonce' => html_entity_decode( $nonce_match[1] ), 'name' => '' ),
		)
	) : new WP_Error( 'pc_smoke_nonce_missing' );
	$returned = $http( admin_url( 'admin.php?page=' . $page . '&new=1' ), array( 'cookies' => $marketing_jar ) );
	$ok( "Marketing alone can submit $action and receives field validation instead of 403", 302 === (int) wp_remote_retrieve_response_code( $submitted ) && str_contains( (string) wp_remote_retrieve_header( $submitted, 'location' ), 'page=' . $page ) && 200 === (int) wp_remote_retrieve_response_code( $returned ) && str_contains( (string) wp_remote_retrieve_body( $returned ), 'notice-error' ) );
}
$home = $http( admin_url(), array( 'cookies' => $cookies( $sid ) ) );
$ok( 'Marketing-only login lands on its usable promotions page (real request)', 302 === (int) wp_remote_retrieve_response_code( $home ) && str_contains( (string) wp_remote_retrieve_header( $home, 'location' ), 'page=pc-promotions' ) );
$marketing_access = array( 'orders' => $code( 'admin.php?page=wc-orders' ), 'data' => $code( 'admin.php?page=pc-overview' ), 'settings' => $code( 'admin.php?page=pc-shipping' ) );
$ok( 'Marketing alone cannot open Orders, Data or Store settings (403): ' . wp_json_encode( $marketing_access ), array( 'orders' => 403, 'data' => 403, 'settings' => 403 ) === $marketing_access );

Staff::set_modules( $sid, array( 'data' ) );
$data_access = array( 'data' => $code( 'admin.php?page=pc-overview' ), 'marketing' => $code( 'admin.php?page=pc-promotions' ), 'orders' => $code( 'admin.php?page=wc-orders' ), 'settings' => $code( 'admin.php?page=pc-shipping' ) );
$ok( 'Data alone keeps its overview and cannot gain Marketing, Orders or Store settings (real requests): ' . wp_json_encode( $data_access ), array( 'data' => 200, 'marketing' => 403, 'orders' => 403, 'settings' => 403 ) === $data_access );
Staff::set_modules( $sid, array( 'settings' ) );
$settings_access = array( 'settings' => $code( 'admin.php?page=pc-shipping' ), 'marketing' => $code( 'admin.php?page=pc-promotions' ), 'orders' => $code( 'admin.php?page=wc-orders' ), 'data' => $code( 'admin.php?page=pc-overview' ) );
$ok( 'Store settings alone keeps shipping and cannot gain Marketing, Orders or Data (real requests): ' . wp_json_encode( $settings_access ), array( 'settings' => 200, 'marketing' => 403, 'orders' => 403, 'data' => 403 ) === $settings_access );

Staff_Page::save( $sid, array( 'email' => 'pc-smoke-staff@example.com', 'name' => 'Smoke Staff', 'modules' => array( 'products', 'backend' ) ) );
Staff::flush_cache();

Staff::set_disabled( $sid, true );
Staff::flush_cache();
$ok( 'a disabled account has no permissions and cannot log in', array() === Staff::granted( $sid ) && is_wp_error( Staff::block_disabled( new WP_User( $sid ) ) ) );
$after_off = $http( admin_url( 'edit.php?post_type=product' ), array( 'cookies' => $cookies( $sid ) ) );
$ok( 'a disabled staff member is logged out of the admin (real request)', 302 === (int) wp_remote_retrieve_response_code( $after_off ) && str_contains( (string) wp_remote_retrieve_header( $after_off, 'location' ), 'wp-login.php' ) );
Staff::set_disabled( $sid, false );

// 后台页面能打开。
wp_set_current_user( $admin_id );
$render = static function ( callable $fn, array $get ): string {
	$_GET = $get;
	ob_start();
	$fn();
	return (string) ob_get_clean();
};
$html = $render( array( PeptideCore\Admin\Staff_Page::class, 'render' ), array( 'page' => 'pc-staff' ) );
$ok( 'staff list shows administrators and staff with their menus', str_contains( $html, 'pc_smoke_staff' ) && str_contains( $html, 'pc-dm-switch is-on' ) && str_contains( $html, $admin_user->user_login ) );
$html = $render( array( PeptideCore\Admin\Staff_Page::class, 'render' ), array( 'page' => 'pc-staff', 'edit' => (string) $sid ) );
$ok( 'staff form: account read-only, menus ticked, password optional', str_contains( $html, 'readonly' ) && 2 === substr_count( $html, 'checked=\'checked\'' ) && ! str_contains( $html, 'name="password" class="regular-text" minlength="8" autocomplete="new-password" required' ) );
$html = $render( array( PeptideCore\Admin\Media_Page::class, 'render' ), array( 'page' => 'pc-media' ) );
$ok( 'media center renders groups, tools and the list', str_contains( $html, 'pc-media-groups' ) && str_contains( $html, 'pc_media_upload' ) && str_contains( $html, 'pc_media_collect' ) && str_contains( $html, 'pc-media-table' ) );
$html = $render( array( PeptideCore\Admin\Activity_Page::class, 'render' ), array( 'page' => 'pc-activity', 'view' => (string) $perm['id'] ) );
$ok( 'activity log renders the list and the detail view', str_contains( $html, 'pc-activity-table' ) && str_contains( $html, 'pc-activity-detail' ) && str_contains( $html, (string) $perm['trace_id'] ) );
$_GET = array();

// ===== 清理 =====
wp_set_current_user( $admin_id );
foreach ( $posts as $p ) {
	wp_delete_post( (int) $p, true );
}
foreach ( $terms as $t ) {
	wp_delete_term( (int) $t, 'category' );
}
foreach ( $media as $m ) {
	wp_delete_attachment( (int) $m, true );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $users as $u ) {
	if ( is_int( $u ) ) {
		wp_delete_user( $u, $admin_id );
	}
}
update_option( 'sticky_posts', $orig_st );
if ( null === $orig_q ) {
	delete_option( Media_Library::QUEUE_OPTION );
} else {
	update_option( Media_Library::QUEUE_OPTION, $orig_q, false );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Activity_Log::table() . ' WHERE id > %d', $log_max ) ); // phpcs:ignore WordPress.DB
if ( '' === $orig_last ) {
	delete_user_meta( $admin_id, Staff::META_LOGIN );
} else {
	update_user_meta( $admin_id, Staff::META_LOGIN, $orig_last );
}
wp_set_current_user( 0 );
$ok( 'test posts, collections, media, accounts and records removed', ! get_post( $new ) && ! get_term( $cid, 'category' ) && ! get_userdata( (int) $sid ) && ! get_post( (int) $found[0] ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Activity_Log::table() . ' WHERE id > %d', $log_max ) ) ); // phpcs:ignore WordPress.DB
