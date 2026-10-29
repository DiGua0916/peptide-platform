<?php
/** Real local HTTP/multipart regression. Never use real customers or existing review media. */
defined( 'ABSPATH' ) || exit;

use PeptideCore\Reviews;
use PeptideCore\Staff;
use PeptideCore\Private_Files;
use PeptideCore\Modules\Commerce_Blocks;
use PeptideCore\Admin\Reviews_Page;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local CLI only.' ); }
if ( ! extension_loaded( 'curl' ) || ! class_exists( 'CURLFile' ) ) { throw new RuntimeException( 'PHP cURL is required.' ); }
$work = 'D:/Codex/Tasks/2026-10-28/new-chat/work/review-media-fixtures';
$webm = $work . '/synthetic-blue-20261028.webm';
if ( ! is_file( $webm ) || ! preg_match( '~^D:/~i', wp_normalize_path( WPMU_PLUGIN_DIR ) ) || ! preg_match( '~^D:/~i', wp_normalize_path( Private_Files::dir() ) ) ) { throw new RuntimeException( 'D-drive local fixtures/storage are required.' ); }
$pass = 0; $fail = 0;
$ok = static function ( string $label, bool $yes ) use ( &$pass, &$fail ): void { $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS ' : 'FAIL ' ) . $label . "\n"; };
$tag = 'pcrm-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 14 );
$header = bin2hex( random_bytes( 20 ) ); $users = array(); $sessions = array(); $files = array(); $media = array(); $private_paths = array();
$product = 0; $order_id = 0; $comments = array(); $mu = ''; $mu_body = ''; $fatal = null;
$before_user = get_current_user_id(); $before_cookie = $_COOKIE; $before_post = $_POST;
$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ) )[0] ?? 0 );
if ( ! $admin ) { throw new RuntimeException( 'A local administrator is required.' ); }

// Only digests enter PHP; no option contents, credentials or customer data are printed.
$protected = static function (): string {
	global $wpdb; $out = array();
	$out['options'] = $wpdb->get_results( "SELECT option_name, MD5(option_value) AS digest FROM {$wpdb->options} WHERE option_name REGEXP '^(peptide_core_|woocommerce_|theme_mods_|comment_)' OR option_name IN ('active_plugins','template','stylesheet','show_on_front','page_on_front','page_for_posts','blog_public') ORDER BY option_name", ARRAY_A );
	$out['templates'] = $wpdb->get_results( "SELECT ID,MD5(CONCAT_WS('|',post_title,post_content,post_status,post_parent,post_name,post_modified_gmt)) AS digest FROM {$wpdb->posts} WHERE post_type IN ('pc_template','pc_tpl','pc_layout') ORDER BY ID", ARRAY_A );
	$out['template_meta'] = $wpdb->get_results( "SELECT pm.meta_id,MD5(pm.meta_value) AS digest FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN ('pc_template','pc_tpl','pc_layout') ORDER BY pm.meta_id", ARRAY_A );
	foreach ( array( 'posts' => array( $wpdb->posts, 'ID IN (54,55)', 'ID' ), 'postmeta' => array( $wpdb->postmeta, 'post_id IN (54,55)', 'meta_id' ), 'items' => array( $wpdb->prefix . 'woocommerce_order_items', 'order_id IN (54,55)', 'order_item_id' ), 'itemmeta' => array( $wpdb->prefix . 'woocommerce_order_itemmeta', 'order_item_id IN (SELECT order_item_id FROM ' . $wpdb->prefix . 'woocommerce_order_items WHERE order_id IN (54,55))', 'meta_id' ), 'orders' => array( $wpdb->prefix . 'wc_orders', 'id IN (54,55)', 'id' ), 'addresses' => array( $wpdb->prefix . 'wc_order_addresses', 'order_id IN (54,55)', 'id' ), 'operational' => array( $wpdb->prefix . 'wc_order_operational_data', 'order_id IN (54,55)', 'id' ), 'ordermeta' => array( $wpdb->prefix . 'wc_orders_meta', 'order_id IN (54,55)', 'id' ) ) as $key => [ $table, $where, $sort ] ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { $rows = $wpdb->get_results( "SELECT * FROM `$table` WHERE $where ORDER BY $sort", ARRAY_A ); $out[ $key ] = hash( 'sha256', serialize( $rows ) ); }
	}
	return hash( 'sha256', serialize( $out ) );
};
$protected_before = $protected();
$no_mail = static fn() => true; add_filter( 'pre_wp_mail', $no_mail );
$jar = static function ( int $user ) use ( &$sessions ): array {
	wp_cache_delete( $user, 'user_meta' ); $expires = time() + HOUR_IN_SECONDS;
	$token = WP_Session_Tokens::get_instance( $user )->create( $expires ); $sessions[] = array( $user, $token );
	$auth = wp_generate_auth_cookie( $user, $expires, 'auth', $token ); $login = wp_generate_auth_cookie( $user, $expires, 'logged_in', $token );
	return array( 'user' => $user, 'login' => $login, 'cookie' => AUTH_COOKIE . '=' . rawurlencode( $auth ) . '; ' . LOGGED_IN_COOKIE . '=' . rawurlencode( $login ) );
};
$nonce = static function ( array $identity, string $action ): string { wp_set_current_user( $identity['user'] ); $_COOKIE[ LOGGED_IN_COOKIE ] = $identity['login']; return wp_create_nonce( $action ); };
$http = static function ( string $url, string $method = 'GET', array $fields = array(), array $identity = array(), array $extra = array() ) use ( $header ): array {
	$ch = curl_init( $url ); $headers = array();
	curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => array_merge( array( 'Accept: application/json', 'Expect:', 'Cache-Control: no-cache', 'X-PC-Review-Smoke: ' . $header ), $extra ), CURLOPT_HEADERFUNCTION => static function ( $ch, string $line ) use ( &$headers ): int { if ( str_contains( $line, ':' ) ) { [ $name, $value ] = explode( ':', $line, 2 ); $headers[ strtolower( trim( $name ) ) ] = trim( $value ); } return strlen( $line ); } ) );
	if ( $identity ) { curl_setopt( $ch, CURLOPT_COOKIE, $identity['cookie'] ); }
	if ( 'POST' === $method ) { curl_setopt( $ch, CURLOPT_POST, true ); curl_setopt( $ch, CURLOPT_POSTFIELDS, $fields ); }
	elseif ( 'HEAD' === $method ) { curl_setopt( $ch, CURLOPT_NOBODY, true ); }
	$body = curl_exec( $ch ); $status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ); $error = curl_errno( $ch ); curl_close( $ch );
	if ( $error ) { throw new RuntimeException( 'Fixture HTTP transport error ' . $error ); }
	return array( 'status' => $status, 'headers' => $headers, 'body' => (string) $body, 'json' => json_decode( (string) $body, true ) );
};
$fresh = static function ( int $id ): ?WP_Comment { clean_comment_cache( $id ); wp_cache_delete( $id, 'comment_meta' ); return get_comment( $id ); };
$fixture_count = static function ( int $pid ): int { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID=%d", $pid ) ); };
$attachment_count = static function ( int $uid ): int { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_author=%d", $uid ) ); };
$reject = static function ( string $label, array $fields, array $identity ) use ( $http, $ok, $fixture_count, $attachment_count, &$product ): void {
	$count = $fixture_count( $product ); $attachments = $attachment_count( $identity['user'] ); $r = $http( admin_url( 'admin-post.php' ), 'POST', $fields, $identity );
	$ok( $label . ' rejects real multipart without persistence', 400 === $r['status'] && false === ( $r['json']['success'] ?? null ) && $count === $fixture_count( $product ) && $attachments === $attachment_count( $identity['user'] ) );
	if ( 400 !== $r['status'] ) { echo 'DETAIL negative status=' . $r['status'] . ' code=' . sanitize_key( (string) ( $r['json']['code'] ?? '' ) ) . "\n"; }
};

try {
	wp_set_current_user( $admin );
	$p = new WC_Product_Simple(); $p->set_name( $tag ); $p->set_slug( $tag ); $p->set_status( 'publish' ); $p->set_catalog_visibility( 'visible' ); $p->set_regular_price( '12' ); $p->set_virtual( true ); $p->set_reviews_allowed( true ); $product = $p->save(); update_post_meta( $product, '_pc_review_http_fixture', $tag );
	foreach ( array( 'buyer' => 'customer', 'other' => 'customer', 'products' => Staff::ROLE, 'marketing' => Staff::ROLE ) as $name => $role ) { $u = wp_insert_user( array( 'user_login' => $tag . '-' . $name, 'user_email' => $tag . '-' . $name . '@example.invalid', 'user_pass' => wp_generate_password( 40 ), 'role' => $role ) ); if ( is_wp_error( $u ) ) { throw new RuntimeException( 'Synthetic account creation failed.' ); } $users[ $name ] = (int) $u; if ( Staff::ROLE === $role ) { Staff::set_modules( $u, array( $name ) ); } }
	$identities = array( 'admin' => $jar( $admin ) ); foreach ( $users as $name => $uid ) { $identities[ $name ] = $jar( $uid ); }
	// A paid fixture is created without gateway/payment calls; real purchase-only policy remains unchanged.
	$order = wc_create_order( array( 'customer_id' => $users['buyer'], 'created_via' => 'pc-review-media-smoke' ) ); $order_id = $order->get_id(); $order->set_billing_email( '' ); $order->add_product( wc_get_product( $product ), 1 ); $order->calculate_totals(); $order->set_date_paid( time() ); $order->set_status( 'completed' ); $order->save();
	$ok( 'synthetic purchaser satisfies the existing verified-purchase policy', wc_customer_bought_product( get_userdata( $users['buyer'] )->user_email, $users['buyer'], $product ) );
	// Only the random header AND this tagged product/comment can suppress mail in HTTP requests.
	$mu = wp_normalize_path( WPMU_PLUGIN_DIR ) . '/' . $tag . '-mail.php'; if ( file_exists( $mu ) ) { throw new RuntimeException( 'Fixture MU collision.' ); }
	$mu_body = "<?php\n/* Temporary review HTTP fixture; exact header/product only. */\nadd_action('plugins_loaded', static function () {\n";
	$mu_body .= 'if (!hash_equals(' . var_export( $header, true ) . ", (string)(\$_SERVER['HTTP_X_PC_REVIEW_SMOKE']??''))) return;\n";
	$mu_body .= '$pid=' . $product . '; if (get_post_type($pid)!==\'product\' || get_post_meta($pid,\'_pc_review_http_fixture\',true)!==' . var_export( $tag, true ) . ") return;\n";
	$mu_body .= '$target=absint($_POST[\'comment_post_ID\']??$_POST[\'product\']??0); $cid=absint($_POST[\'review\']??$_POST[\'id\']??0); if($cid){$c=get_comment($cid); if(!$c || (int)$c->comment_post_ID!==$pid) return; $target=$pid;} if($target!==$pid) return;' . "\nadd_filter('pre_wp_mail', static fn()=>true, PHP_INT_MAX);\n}, -999);\n";
	if ( false === file_put_contents( $mu, $mu_body, LOCK_EX ) ) { throw new RuntimeException( 'Fixture mail guard creation failed.' ); }
	$png = $work . '/' . $tag . '.png'; $files[] = $png; file_put_contents( $png, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=' ) );
	$fake = $work . '/' . $tag . '-fake.png'; $files[] = $fake; file_put_contents( $fake, '<html><script>invalid fixture</script></html>' );
	$large_png = $work . '/' . $tag . '-large.png'; $files[] = $large_png; copy( $png, $large_png ); $f = fopen( $large_png, 'c+' ); ftruncate( $f, 6 * MB_IN_BYTES ); fclose( $f );
	$large_video = $work . '/' . $tag . '-large.webm'; $files[] = $large_video; copy( $webm, $large_video ); $f = fopen( $large_video, 'c+' ); ftruncate( $f, 26 * MB_IN_BYTES ); fclose( $f );
	$ok( 'generated upload samples are genuine PNG and short WebM', ( new finfo( FILEINFO_MIME_TYPE ) )->file( $png ) === 'image/png' && ( new finfo( FILEINFO_MIME_TYPE ) )->file( $webm ) === 'video/webm' && false !== getimagesize( $png ) );
	$base = array( 'action' => 'pc_review_submit', 'comment_post_ID' => (string) $product, 'comment' => 'Synthetic multipart buyer review ' . $tag, 'rating' => '5', 'pc_review_nonce' => $nonce( $identities['buyer'], 'pc_review_' . $product ), 'media_consent' => '1' );
	$bad = $base; $bad['pc_review_photos[0]'] = new CURLFile( $fake, 'image/png', 'forged.png' ); $reject( 'forged PNG MIME', $bad, $identities['buyer'] );
	$bad = $base; for ( $n = 0; $n < 6; ++$n ) { $bad[ 'pc_review_photos[' . $n . ']' ] = new CURLFile( $png, 'image/png', 'tiny-' . $n . '.png' ); } $reject( 'six-photo count', $bad, $identities['buyer'] );
	$bad = $base; $bad['pc_review_photos[0]'] = new CURLFile( $large_png, 'image/png', 'large.png' ); $reject( 'image over five MB', $bad, $identities['buyer'] );
	$bad = $base; $bad['pc_review_video'] = new CURLFile( $large_video, 'video/webm', 'large.webm' ); $reject( 'video over twenty-five MB', $bad, $identities['buyer'] );
	$bad = $base; unset( $bad['media_consent'] ); $bad['pc_review_photos[0]'] = new CURLFile( $png, 'image/png', 'tiny.png' ); $reject( 'missing publication consent', $bad, $identities['buyer'] );
	$local = array( 'pc_review_photos' => array( 'name' => array( 'tiny.png' ), 'tmp_name' => array( $png ), 'size' => array( filesize( $png ) ), 'error' => array( UPLOAD_ERR_OK ) ) );
	$ok( 'ordinary local paths cannot impersonate multipart temporary uploads', is_wp_error( Reviews::validate_uploads( $local ) ) && 0 === $fixture_count( $product ) && 0 === $attachment_count( $users['buyer'] ) );
	$good = $base; $good['pc_review_photos[0]'] = new CURLFile( $png, 'image/png', 'tiny.png' ); $good['pc_review_video'] = new CURLFile( $webm, 'video/webm', 'short.webm' );
	$r = $http( admin_url( 'admin-post.php' ), 'POST', $good, $identities['buyer'] ); $id = (int) ( $r['json']['id'] ?? 0 ); if ( $id ) { $comments[] = $id; }
	$ok( 'real buyer PNG plus WebM multipart enters pending review', 200 === $r['status'] && true === ( $r['json']['success'] ?? false ) && $id > 0 && '0' === $fresh( $id )->comment_approved );
	if ( ! $id ) { echo 'DETAIL submit status=' . $r['status'] . ' code=' . sanitize_key( (string) ( $r['json']['code'] ?? '' ) ) . "\n"; throw new RuntimeException( 'Multipart fixture could not be created.' ); }
	$ok( 'admin-post buyer submission is a real Woo review recognized by management', 'review' === $fresh( $id )->comment_type && Reviews::is_review( $id ) );
	$images = array_map( 'intval', (array) get_comment_meta( $id, Commerce_Blocks::REVIEW_IMAGES, true ) ); $video = (int) get_comment_meta( $id, Commerce_Blocks::REVIEW_VIDEO, true ); $media = array_merge( $images, array( $video ) );
	$ok( 'both uploaded assets are private and owned by their exact review', 1 === count( $images ) && $video > 0 && count( array_filter( $media, static fn( $aid ) => 'private' === get_post_status( $aid ) && (int) get_post_meta( $aid, Reviews::MEDIA_OWNER, true ) === $id ) ) === 2 );
	$ok( 'buyer provenance consent and actual-purchase badge persisted', 'buyer' === get_comment_meta( $id, Reviews::SOURCE, true ) && '' !== get_comment_meta( $id, '_pc_review_media_consent', true ) && wc_review_is_from_verified_owner( $id ) );
	$urls = array(); $native_paths = array(); foreach ( $media as $aid ) { clean_post_cache( $aid ); $urls[ $aid ] = wp_get_attachment_url( $aid ); $private_paths[] = Private_Files::dir() . '/review-media/' . basename( (string) get_post_meta( $aid, '_pc_review_file', true ) ); $native_paths[] = get_attached_file( $aid, true ); }
	$ok( 'media bytes live outside webroot and public URLs contain no filesystem path', count( array_filter( $private_paths, static fn( $path ) => is_file( $path ) && ! str_starts_with( strtolower( wp_normalize_path( $path ) ), strtolower( wp_normalize_path( ABSPATH ) ) ) ) ) === 2 && count( array_filter( $urls, static fn( $url ) => str_contains( $url, 'pc_review_media=' ) && ! str_contains( $url, 'review-media/' ) && ! str_contains( $url, 'D:' ) ) ) === 2 );
	$ok( 'native WordPress attachment paths resolve to the same private files on Windows', count( array_filter( $native_paths, 'is_file' ) ) === 2 && array_map( 'wp_normalize_path', $native_paths ) === array_map( 'wp_normalize_path', $private_paths ) );
	foreach ( $media as $aid ) {
		$guest = $http( $urls[ $aid ] ); $author = $http( $urls[ $aid ], 'GET', array(), $identities['buyer'] ); $other = $http( $urls[ $aid ], 'GET', array(), $identities['other'] );
		$ok( 'pending ' . ( $aid === $video ? 'video' : 'photo' ) . ' permits its author and hides bytes from guests and other buyers', 404 === $guest['status'] && '' === $guest['body'] && 200 === $author['status'] && strlen( $author['body'] ) > 0 && 404 === $other['status'] );
	}
	$moderate = static function ( string $status, array $identity ) use ( $nonce, $http, $id ): array { return $http( admin_url( 'admin-post.php' ), 'POST', array( 'action' => 'pc_review_moderate', 'review' => (string) $id, 'status' => $status, '_wpnonce' => $nonce( $identity, 'pc_review_moderate' ) ), $identity ); };
	$r = $moderate( 'approve', $identities['admin'] ); $ok( 'administrator approves through actual admin-post', 302 === $r['status'] && '1' === $fresh( $id )->comment_approved ); if ( 302 !== $r['status'] ) { echo 'DETAIL approval status=' . $r['status'] . "\n"; }
	foreach ( $media as $aid ) {
		$full = $http( $urls[ $aid ] ); $expected = file_get_contents( $private_paths[ array_search( $aid, $media, true ) ] );
		$ok( 'approved ' . ( $aid === $video ? 'video' : 'photo' ) . ' serves exact original bytes with no-store and nosniff', 200 === $full['status'] && $expected === $full['body'] && str_contains( $full['headers']['cache-control'] ?? '', 'no-store' ) && ( $full['headers']['x-content-type-options'] ?? '' ) === 'nosniff' );
		$range = $http( $urls[ $aid ], 'GET', array(), array(), array( 'Range: bytes=0-9' ) ); $ok( 'approved asset supports exact byte ranges', 206 === $range['status'] && substr( $expected, 0, 10 ) === $range['body'] && ( $range['headers']['content-length'] ?? '' ) === '10' && str_starts_with( $range['headers']['content-range'] ?? '', 'bytes 0-9/' ) );
		$head = $http( $urls[ $aid ], 'HEAD' ); $ok( 'HEAD emits metadata without a response body', 200 === $head['status'] && '' === $head['body'] && (int) ( $head['headers']['content-length'] ?? 0 ) === strlen( $expected ) && str_contains( $head['headers']['cache-control'] ?? '', 'no-store' ) );
	}
	$suffix = $http( $urls[ $video ], 'GET', array(), array(), array( 'Range: bytes=-7' ) ); $ok( 'video suffix range returns the actual tail', 206 === $suffix['status'] && substr( file_get_contents( $webm ), -7 ) === $suffix['body'] );
	$bad_range = $http( $urls[ $video ], 'GET', array(), array(), array( 'Range: bytes=999999-' ) ); $ok( 'out-of-bounds range returns 416', 416 === $bad_range['status'] );
	$forged_url = add_query_arg( 'key', 'invalid-fixture-signature', $urls[ $video ] ); $ok( 'invalid media signature returns 404', 404 === $http( $forged_url )['status'] );
	$ok( 'media POST never exposes file bytes', 404 === $http( $urls[ $video ], 'POST', array( 'fixture' => '1' ) )['status'] );
	$entry = $fresh( $id ); $edit = array( 'action' => 'pc_review_save', 'id' => (string) $id, 'product' => (string) $product, 'author' => $entry->comment_author, 'email' => $entry->comment_author_email, 'rating' => '4', 'content' => 'Synthetic staff edited review ' . $tag, 'status' => '1', 'date' => $entry->comment_date, 'image_ids' => implode( ',', $images ), 'video_id' => (string) $video, 'reference' => $tag . '-private-reference', 'source' => 'store' );
	$edit['_wpnonce'] = $nonce( $identities['products'], 'pc_review_save' ); $r = $http( admin_url( 'admin-post.php' ), 'POST', $edit, $identities['products'] ); $entry = $fresh( $id );
	$ok( 'product employee edits review while retaining buyer-owned photo and video', 302 === $r['status'] && $entry->comment_content === $edit['content'] && $images === array_map( 'intval', (array) get_comment_meta( $id, Commerce_Blocks::REVIEW_IMAGES, true ) ) && $video === (int) get_comment_meta( $id, Commerce_Blocks::REVIEW_VIDEO, true ) );
	$ok( 'employee edit preserves buyer origin and records internal change history', get_comment_meta( $id, Reviews::SOURCE, true ) === 'buyer' && get_comment_meta( $id, '_pc_review_store_edited', true ) === '1' && count( (array) get_comment_meta( $id, '_pc_review_changes', true ) ) > 0 );
	$front = $http( get_permalink( $product ) ); $admin_page = $http( Reviews_Page::url( array( 'edit' => $id ) ), 'GET', array(), $identities['products'] );
	$ok( 'public product shows approved review media without backend provenance or notes', 200 === $front['status'] && str_contains( $front['body'], $edit['content'] ) && str_contains( $front['body'], 'pc-review-media' ) && ! str_contains( $front['body'], 'pc-review-provenance' ) && ! str_contains( $front['body'], $edit['reference'] ) );
	$ok( 'product employee backend can inspect internal origin and reference', 200 === $admin_page['status'] && str_contains( $admin_page['body'], 'pc-review-provenance' ) && str_contains( $admin_page['body'], $edit['reference'] ) );
	$denied = $edit; $denied['content'] = 'Forbidden marketing edit'; $denied['_wpnonce'] = $nonce( $identities['marketing'], 'pc_review_save' ); $r = $http( admin_url( 'admin-post.php' ), 'POST', $denied, $identities['marketing'] );
	$ok( 'non-product employee receives HTTP403 and cannot change review or media', 403 === $r['status'] && $fresh( $id )->comment_content === $edit['content'] && $video === (int) get_comment_meta( $id, Commerce_Blocks::REVIEW_VIDEO, true ) );
	$r = $moderate( 'hold', $identities['marketing'] ); $ok( 'non-product employee moderation also returns HTTP403 without approval change', 403 === $r['status'] && '1' === $fresh( $id )->comment_approved );
	$second = $edit; unset( $second['id'] ); $second['author'] = 'Synthetic second reviewer'; $second['email'] = ''; $second['content'] = 'Independent synthetic review ' . $tag; $second['source'] = 'store'; $second['image_ids'] = ''; $second['video_id'] = '0'; $second['_wpnonce'] = $nonce( $identities['admin'], 'pc_review_save' );
	$r = $http( admin_url( 'admin-post.php' ), 'POST', $second, $identities['admin'] ); parse_str( (string) wp_parse_url( $r['headers']['location'] ?? '', PHP_URL_QUERY ), $query ); $second_id = (int) ( $query['edit'] ?? 0 ); if ( $second_id ) { $comments[] = $second_id; }
	$ok( 'independent backend fixture review is created through admin-post', 302 === $r['status'] && $second_id > 0 );
	if ( $second_id ) {
		foreach ( array( 'image' => array( implode( ',', $images ), '0' ), 'video' => array( '', (string) $video ) ) as $kind => [ $image_ids, $video_id ] ) {
			$borrow = $second; $borrow['id'] = (string) $second_id; $borrow['image_ids'] = $image_ids; $borrow['video_id'] = $video_id; $r = $http( admin_url( 'admin-post.php' ), 'POST', $borrow, $identities['admin'] ); $fresh( $second_id );
			$ok( 'another review cannot borrow an owned ' . $kind . ' reference', 400 === $r['status'] && empty( get_comment_meta( $second_id, Commerce_Blocks::REVIEW_IMAGES, true ) ) && ! get_comment_meta( $second_id, Commerce_Blocks::REVIEW_VIDEO, true ) );
		}
	}
	$r = $moderate( 'hold', $identities['admin'] ); $fresh( $id ); $ok( 'revoking approval immediately hides both existing public media URLs', 302 === $r['status'] && 404 === $http( $urls[ $images[0] ] )['status'] && 404 === $http( $urls[ $video ] )['status'] );
	$ok( 'held review still lets its authenticated author inspect original media', 200 === $http( $urls[ $video ], 'GET', array(), $identities['buyer'] )['status'] );
	$moderate( 'approve', $identities['admin'] ); $fresh( $id );
	$remove = $edit; $remove['image_ids'] = ''; $remove['video_id'] = '0'; $remove['_wpnonce'] = $nonce( $identities['products'], 'pc_review_save' ); $r = $http( admin_url( 'admin-post.php' ), 'POST', $remove, $identities['products'] ); $fresh( $id );
	$ok( 'product employee can remove buyer media without deleting review', 302 === $r['status'] && empty( get_comment_meta( $id, Commerce_Blocks::REVIEW_IMAGES, true ) ) && ! get_comment_meta( $id, Commerce_Blocks::REVIEW_VIDEO, true ) && 'buyer' === get_comment_meta( $id, Reviews::SOURCE, true ) );
	foreach ( $media as $aid ) { $ok( 'removed asset is immediately 404 for guest author and product employee', 404 === $http( $urls[ $aid ] )['status'] && 404 === $http( $urls[ $aid ], 'GET', array(), $identities['buyer'] )['status'] && 404 === $http( $urls[ $aid ], 'GET', array(), $identities['products'] )['status'] ); }
} catch ( Throwable $e ) {
	$fatal = $e; $ok( 'HTTP fixture sequence completes', false ); echo 'DETAIL ' . ( $e instanceof RuntimeException ? $e->getMessage() : get_class( $e ) ) . "\n";
} finally {
	wp_set_current_user( $admin );
	if ( $product ) { global $wpdb; $comments = array_unique( array_merge( $comments, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID=%d", $product ) ) ) ) ); }
	foreach ( $comments as $cid ) { $owned = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => Reviews::MEDIA_OWNER, 'meta_value' => $cid ) ); foreach ( $owned as $aid ) { $media[] = (int) $aid; $private_paths[] = get_attached_file( $aid, true ); } wp_delete_comment( $cid, true ); }
	// HTTP staff removal may already have deleted these rows; discard this CLI process's old caches.
	foreach ( array_unique( $media ) as $aid ) { clean_post_cache( $aid ); wp_cache_delete( $aid, 'post_meta' ); if ( get_post( $aid ) && in_array( (int) get_post_meta( $aid, Reviews::MEDIA_OWNER, true ), $comments, true ) ) { wp_delete_attachment( $aid, true ); } }
	if ( $order_id ) { $o = wc_get_order( $order_id ); if ( $o ) { $o->delete( true ); } }
	if ( $product ) { $p = wc_get_product( $product ); if ( $p ) { $p->delete( true ); } }
	foreach ( $sessions as [ $uid, $token ] ) { wp_cache_delete( $uid, 'user_meta' ); WP_Session_Tokens::get_instance( $uid )->destroy( $token ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $uid ) { foreach ( array( '127.0.0.1', '::1' ) as $ip ) { delete_transient( 'pc_review_rate_' . hash_hmac( 'sha256', $ip . '|' . $uid, wp_salt() ) ); } wp_delete_user( $uid ); }
	if ( $mu && is_file( $mu ) && hash_equals( hash( 'sha256', $mu_body ), hash_file( 'sha256', $mu ) ) ) { unlink( $mu ); }
	foreach ( $files as $path ) { if ( is_file( $path ) && str_starts_with( wp_normalize_path( $path ), $work . '/' . $tag ) ) { unlink( $path ); } }
	remove_filter( 'pre_wp_mail', $no_mail ); $_COOKIE = $before_cookie; $_POST = $before_post; wp_set_current_user( $before_user );
}
$cleanup_counts = array( 'comments' => count( array_filter( $comments, static fn( $id ) => null !== get_comment( $id ) ) ), 'attachments' => count( array_filter( array_unique( $media ), static fn( $id ) => null !== get_post( $id ) ) ), 'files' => count( array_filter( array_unique( $private_paths ), 'is_file' ) ), 'users' => count( array_filter( $users, static fn( $id ) => false !== get_userdata( $id ) ) ) );
$ok( 'all fixture comments attachments private bytes and users are deleted', 0 === array_sum( $cleanup_counts ) );
if ( array_sum( $cleanup_counts ) ) { echo 'DETAIL cleanup remaining counts ' . wp_json_encode( $cleanup_counts ) . "\n"; }
$ok( 'synthetic order product temporary MU and scratch uploads are removed', ( ! $order_id || ! wc_get_order( $order_id ) ) && ( ! $product || ! get_post( $product ) ) && ( ! $mu || ! file_exists( $mu ) ) && ! array_filter( $files, 'file_exists' ) );
$sessions_gone = true; foreach ( $sessions as [ $uid, $token ] ) { wp_cache_delete( $uid, 'user_meta' ); if ( WP_Session_Tokens::get_instance( $uid )->verify( $token ) ) { $sessions_gone = false; } }
$ok( 'only this test authentication sessions are destroyed', $sessions_gone );
$ok( 'original orders54/55 templates layouts and real settings fingerprints are unchanged', hash_equals( $protected_before, $protected() ) );
echo "Result: $pass passed, $fail failed\n";
if ( $fail || $fatal ) { throw new RuntimeException( 'Review media HTTP smoke failed; fixture cleanup completed.' ); }
