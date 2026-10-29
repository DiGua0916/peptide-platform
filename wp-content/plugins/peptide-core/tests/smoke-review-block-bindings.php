<?php
/** Real native records verify review block bindings without publishing the active store template. */
defined( 'ABSPATH' ) || exit;

use PeptideCore\Templates;
use PeptideCore\Reviews;
use PeptideCore\Api\Layout_Controller;
use PeptideCore\Modules\Commerce_Blocks;
use PeptideCore\Modules\Layout;
use PeptideCore\Modules\Registry;
use PeptideCore\Modules\Renderer;
use PeptideCore\Modules\Review_Blocks;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { throw new RuntimeException( 'Local CLI only.' ); }
$pass = 0; $fail = 0;
$ok = static function ( string $label, bool $yes ) use ( &$pass, &$fail ): void { $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS ' : 'FAIL ' ) . $label . "\n"; };
$protected = static function ( array $ids = array() ): array {
	if ( ! $ids ) { $ids = get_posts( array( 'post_type' => 'pc_layout', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ); }
	$docs = array(); foreach ( $ids as $id ) { clean_post_cache( $id ); $meta = array_filter( get_post_meta( $id ), static fn( $key ) => str_starts_with( (string) $key, '_pc_' ), ARRAY_FILTER_USE_KEY ); ksort( $meta ); $docs[ $id ] = array( get_post_field( 'post_content', $id ), $meta ); } ksort( $docs );
	$orders = array(); foreach ( array( 54, 55 ) as $id ) { $order = wc_get_order( $id ); $orders[ $id ] = $order ? $order->get_data() : null; }
	$options = array(); foreach ( array( 'timezone_string', 'gmt_offset', 'peptide_core_settings', 'peptide_core_tracking', 'peptide_core_media_queue', 'sticky_posts', Templates::ACTIVE_OPTION, Templates::DOMAIN_OPTION ) as $key ) { $options[ $key ] = get_option( $key, null ); }
	return array( 'ids' => array_map( 'intval', array_keys( $docs ) ), 'hash' => hash( 'sha256', wp_json_encode( array( $docs, $orders, $options ) ) ) );
};
$before = $protected(); $old_user = get_current_user_id(); $old_get = $_GET; $old_server = $_SERVER; $old_query = $GLOBALS['wp_query']; $old_product = $GLOBALS['product'] ?? null;
$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
$tag = 'pcrbindings-' . substr( wp_generate_uuid4(), 0, 10 ); $products = array(); $comments = array(); $bulk_comments = array(); $attachments = array(); $files = array(); $template = 0; $page = 0; $scope = null; $default = null; $docs = array();
$no_mail = static fn() => true; add_filter( 'pre_wp_mail', $no_mail );
$render = static function ( string $type = 'text_reviews', array $settings = array() ) use ( &$products ): string { return Renderer::render( $type, array( 'settings' => array_replace( array( 'reviewSource' => 'product', 'reviewProduct' => $products['A'] ?? 0, 'limit' => 24 ), $settings ) ), 'public' ); };
$call = static function ( string $method, int $id, string $suffix = '', array $body = array() ) { $request = new WP_REST_Request( $method, '/peptide/v1/layouts/' . $id . $suffix ); $request->set_header( 'content-type', 'application/json' ); $request->set_body( wp_json_encode( $body ) ); return rest_do_request( $request ); };
$configure = static function ( array $settings, bool $publish ) use ( &$docs, $call ): bool {
	$id = $docs['global']; $read = $call( 'GET', $id )->get_data(); $saved = $call( 'POST', $id, '', array( 'revision' => $read['revision'], 'modules' => array( array( 'block' => 'vialcove/site-styles', 'attrs' => array( 'settings' => $settings ) ) ) ) );
	if ( 200 !== $saved->get_status() ) { return false; } if ( ! $publish ) { return true; } $read = $call( 'GET', $id )->get_data(); return 200 === $call( 'POST', $id, '/publish', array( 'revision' => $read['revision'] ) )->get_status();
};
$insert = static function ( int $product, string $marker, string $status = '1', string $type = 'review', int $parent = 0, string $date = '2020-01-01 12:00:00' ) use ( &$comments, $tag ): int {
	$id = wp_insert_comment( array( 'comment_post_ID' => $product, 'comment_author' => 'SYNTHETIC ' . $marker, 'comment_author_email' => $tag . '@example.invalid', 'comment_content' => 'SYNTHETIC ' . $tag . ' ' . $marker, 'comment_type' => $type, 'comment_parent' => $parent, 'comment_approved' => $status, 'comment_date' => $date, 'comment_date_gmt' => $date ) );
	$comments[] = $id; update_comment_meta( $id, 'rating', 4 ); update_comment_meta( $id, Reviews::SOURCE, 'imported' ); update_comment_meta( $id, '_pc_review_reference', 'INTERNAL-' . $tag ); update_comment_meta( $id, '_pc_review_store_edited', '1' ); return (int) $id;
};
try {
	if ( ! $admin ) { throw new RuntimeException( 'Administrator required.' ); }
	wp_set_current_user( $admin ); $_GET = array();
	$template = Templates::create( 'SYNTHETIC ' . $tag, 'Isolated review binding regression', $admin ); foreach ( array_keys( Templates::documents() ) as $key ) { $docs[ $key ] = Templates::document_id( $template, $key, false ); }
	$base = array( 'reviewLayout' => 'grid', 'reviewLimit' => 0, 'reviewOrder' => 'newest', 'reviewMinimumRating' => 0, 'reviewShowRating' => true, 'reviewVideoRatio' => 'original' );
	$ok( 'isolated global fixture saves and publishes without assigning the store template', $configure( $base, true ) && Templates::default_id( false ) !== $template );
	$default = static fn() => $template; add_filter( 'pre_option_' . Templates::ACTIVE_OPTION, $default ); $_SERVER['HTTP_HOST'] = $tag . '.invalid';
	foreach ( array( 'A', 'B', 'draft', 'private', 'hidden', 'password' ) as $name ) {
		$product = 'A' === $name ? new WC_Product_Variable() : new WC_Product_Simple(); $product->set_name( 'SYNTHETIC ' . $tag . ' ' . $name ); $product->set_status( in_array( $name, array( 'draft', 'private' ), true ) ? $name : 'publish' ); $product->set_catalog_visibility( 'hidden' === $name ? 'hidden' : 'visible' ); $product->set_reviews_allowed( true );
		if ( 'A' === $name ) { $attribute = new WC_Product_Attribute(); $attribute->set_name( 'size' ); $attribute->set_options( array( '15', '20' ) ); $attribute->set_variation( true ); $product->set_attributes( array( $attribute ) ); } else { $product->set_regular_price( '1' ); }
		$products[ $name ] = $product->save(); if ( 'password' === $name ) { wp_update_post( array( 'ID' => $product->get_id(), 'post_password' => 'SYNTHETIC-' . $tag ) ); }
	}
	foreach ( array( '15', '20' ) as $size ) { $variation = new WC_Product_Variation(); $variation->set_parent_id( $products['A'] ); $variation->set_attributes( array( 'size' => $size ) ); $variation->set_regular_price( '1' ); $products[ 'variation' . $size ] = $variation->save(); } WC_Product_Variable::sync( $products['A'] );
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SYNTHETIC ' . $tag ) );
	$a = $insert( $products['A'], 'A-APPROVED' ); $b = $insert( $products['B'], 'B-APPROVED' ); $legacy = $insert( $products['A'], 'A-LEGACY' );
	global $wpdb; $wpdb->update( $wpdb->comments, array( 'comment_type' => '' ), array( 'comment_ID' => $legacy, 'comment_post_ID' => $products['A'] ) ); clean_comment_cache( $legacy );
	$insert( $products['A'], 'A-PENDING', '0' ); $insert( $products['A'], 'A-REPLY', '1', 'review', $a ); $insert( $products['A'], 'A-ORDINARY', '1', 'comment' ); $insert( $products['variation15'], 'VARIATION-ANOMALY' );
	foreach ( array( 'draft', 'private', 'hidden', 'password' ) as $name ) { $insert( $products[ $name ], 'EXCLUDE-' . strtoupper( $name ) ); }
	$scope = static function ( WP_Comment_Query $query ) use ( &$products ): void { if ( ! empty( $query->query_vars['pc_review_block'] ) ) { $query->query_vars['post__in'] = array_values( $products ); } }; add_action( 'pre_get_comments', $scope );
	$definition = Registry::get( 'text_reviews' ); $fields = array_column( $definition['settings'], null, 'key' );
	$ok( 'all four review blocks expose compatible source and selected product settings', isset( $fields['reviewSource'], $fields['reviewProduct'] ) && 'all' === $fields['reviewSource']['default'] && 'product' === $fields['reviewProduct']['type'] && ! array_filter( array( 'image_reviews', 'video_reviews', 'featured_reviews' ), static fn( $type ) => ! in_array( 'reviewSource', array_column( Registry::get( $type )['settings'], 'key' ), true ) ) );
	$html = $render();
	$ok( 'selected A uses real approved and legacy top-level reviews without B pending replies or ordinary comments', str_contains( $html, 'A-APPROVED' ) && str_contains( $html, 'A-LEGACY' ) && ! str_contains( $html, 'B-APPROVED' ) && ! str_contains( $html, 'A-PENDING' ) && ! str_contains( $html, 'A-REPLY' ) && ! str_contains( $html, 'A-ORDINARY' ) && ! str_contains( $html, 'VARIATION-ANOMALY' ) );
	$other = $render( 'text_reviews', array( 'reviewProduct' => $products['B'] ) ); $ok( 'selected B never falls back to A', str_contains( $other, 'B-APPROVED' ) && ! str_contains( $other, 'A-APPROVED' ) );
	$all = $render( 'text_reviews', array( 'reviewSource' => 'all' ) ); $ok( 'default all mode includes both eligible products but excludes unpublished hidden and protected products', str_contains( $all, 'A-APPROVED' ) && str_contains( $all, 'B-APPROVED' ) && ! str_contains( $all, 'EXCLUDE-' ) );
	$missing = Review_Blocks::render( 'text_reviews', array( 'reviewSource' => 'product', 'reviewProduct' => 0 ) ); $ok( 'missing explicit product binding is empty rather than a whole-store fallback', '' === $missing );
	$ineligible = array(); foreach ( array( 'draft', 'private', 'hidden', 'password' ) as $name ) { $ineligible[] = $render( 'text_reviews', array( 'reviewProduct' => $products[ $name ] ) ); } $ok( 'explicit ineligible products also render no public reviews', ! array_filter( $ineligible ) );
	$GLOBALS['wp_query'] = new WP_Query( array( 'post_type' => 'product', 'p' => $products['A'] ) ); $_GET['product'] = $products['B']; $current = $render( 'text_reviews', array( 'reviewSource' => 'current' ) );
	$ok( 'current product uses the real queried A object and ignores a forged product GET parameter', str_contains( $current, 'A-APPROVED' ) && ! str_contains( $current, 'B-APPROVED' ) );
	$GLOBALS['wp_query'] = new WP_Query( array( 'post_type' => 'product_variation', 'p' => $products['variation15'] ) ); $current = $render( 'text_reviews', array( 'reviewSource' => 'current' ) ); $ok( 'queried variation maps to its main product review records', str_contains( $current, 'A-APPROVED' ) && ! str_contains( $current, 'VARIATION-ANOMALY' ) );
	$direct = Review_Blocks::render( 'text_reviews', array( 'reviewSource' => 'product', 'reviewProduct' => $products['variation20'] ) ); $ok( 'direct variation binding normalizes to the parent without relocating a comment', str_contains( $direct, 'A-APPROVED' ) && (int) get_comment( $a )->comment_post_ID === $products['A'] );
	$GLOBALS['wp_query'] = new WP_Query( array( 'post_type' => 'page', 'p' => $page ) ); $ok( 'current mode on a non-product page is empty even if GET names a product', '' === Review_Blocks::render( 'text_reviews', array( 'reviewSource' => 'current' ) ) );
	$GLOBALS['wp_query'] = $old_query; $_GET = array();
	$media = $insert( $products['A'], 'A-OLDER-MEDIA', '1', 'review', 0, '2019-01-01 12:00:00' );
	if ( ! preg_match( '~^D:/~i', wp_normalize_path( wp_upload_dir()['basedir'] ) ) ) { throw new RuntimeException( 'Synthetic media must stay on drive D.' ); }
	$png = wp_upload_bits( 'synthetic-' . $tag . '.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWuQAAAAASUVORK5CYII=' ) ); if ( $png['error'] ) { throw new RuntimeException( 'Synthetic image fixture failed.' ); } $files[] = $png['file'];
	$image = wp_insert_attachment( array( 'post_title' => 'SYNTHETIC ' . $tag . ' image', 'post_status' => 'inherit', 'post_mime_type' => 'image/png', 'guid' => $png['url'] ), wp_normalize_path( $png['file'] ), $products['A'] ); $attachments[] = $image; wp_update_attachment_metadata( $image, array( 'width' => 1, 'height' => 1, 'file' => get_post_meta( $image, '_wp_attached_file', true ) ) );
	$video_bytes = base64_decode( 'GkXfo59ChoEBQveBAULygQRC84EIQoKEd2VibUKHgQJChYECGFOAZwEAAAAAAAI2EU2bdLpNu4tTq4QVSalmU6yBoU27i1OrhBZUrmtTrIHWTbuMU6uEElTDZ1OsggEjTbuMU6uEHFO7a1OsggIg7AEAAAAAAABZAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAVSalmsCrXsYMPQkBNgIxMYXZmNjEuNy4xMDBXQYxMYXZmNjEuNy4xMDBEiYhAgsAAAAAAABZUrmvIrgEAAAAAAAA/14EBc8WIhsY8Ay6BFNicgQAitZyDdW5kiIEAhoVWX1ZQOIOBASPjg4QL68IA4JCwgUC6gUCagQJVsIRVuYEBElTDZ/tzc59jwIBnyJlFo4dFTkNPREVSRIeMTGF2ZjYxLjcuMTAwc3PWY8CLY8WIhsY8Ay6BFNhnyKFFo4dFTkNPREVSRIeUTGF2YzYxLjE5LjEwMCBsaWJ2cHhnyKFFo4hEVVJBVElPTkSHkzAwOjAwOjAwLjYwMDAwMDAwMAAfQ7Z1+OeBAKPDgQAAgJADAJ0BKkAAQAAARwiFhYiFhIgCAgJ1qgP4AgboQVwx0hMAVVgA/v9NEv/8WFfxYV/FhX/xYV/8/M7txfzmAKOWgQDIANEBAAUQrAAYABhYL/QACI6AAKOWgQGQANEBAAUQrAAYABhYL/QACI6AABxTu2uRu4+zgQC3iveBAfGCAaPwgQM=' );
	$webm = wp_upload_bits( 'synthetic-' . $tag . '.webm', null, $video_bytes ); if ( $webm['error'] ) { throw new RuntimeException( 'Synthetic video fixture failed.' ); } $files[] = $webm['file'];
	$video = wp_insert_attachment( array( 'post_title' => 'SYNTHETIC ' . $tag . ' video', 'post_status' => 'inherit', 'post_mime_type' => 'video/webm', 'guid' => $webm['url'] ), wp_normalize_path( $webm['file'] ), $products['A'] ); $attachments[] = $video;
	update_comment_meta( $media, Commerce_Blocks::REVIEW_IMAGES, array( $image ) ); update_comment_meta( $media, Commerce_Blocks::REVIEW_VIDEO, $video ); update_comment_meta( $media, Commerce_Blocks::REVIEW_FEATURED, '1' );
	// These are real candidate rows, inserted directly to avoid 205 irrelevant Woo aggregate recalculations.
	for ( $i = 0; $i < 205; ++$i ) {
		$saved = $wpdb->insert( $wpdb->comments, array( 'comment_post_ID' => $products['A'], 'comment_author' => 'SYNTHETIC newer ' . $i, 'comment_author_email' => $tag . '@example.invalid', 'comment_content' => 'SYNTHETIC ' . $tag . ' A-NEWER-TEXT-' . $i, 'comment_type' => 'review', 'comment_parent' => 0, 'comment_approved' => '1', 'comment_date' => '2020-02-01 12:00:00', 'comment_date_gmt' => '2020-02-01 12:00:00' ) );
		if ( ! $saved ) { throw new RuntimeException( 'Synthetic candidate insert failed.' ); } $id = (int) $wpdb->insert_id; $bulk_comments[] = $id; $comments[] = $id; $wpdb->insert( $wpdb->commentmeta, array( 'comment_id' => $id, 'meta_key' => 'rating', 'meta_value' => '4' ) );
	}
	clean_comment_cache( $bulk_comments );
	$ok( 'fixture has more than two hundred newer real text reviews before the older media review', (int) get_comments( array( 'post_id' => $products['A'], 'type' => 'review', 'status' => 'approve', 'parent' => 0, 'count' => true ) ) > 200 );
	foreach ( array( 'image_reviews' => '<img', 'video_reviews' => '<video', 'featured_reviews' => 'A-OLDER-MEDIA' ) as $type => $needle ) { $html = $render( $type ); $ok( $type . ' finds qualifying old media beyond the former newest-200 cutoff', str_contains( $html, 'A-OLDER-MEDIA' ) && str_contains( $html, $needle ) && ! str_contains( $html, 'A-NEWER-TEXT-' ) ); }
	$limited = $render( 'text_reviews', array( 'limit' => 100 ) ); $ok( 'renderer returns at most twenty-four figures even if called with a larger untrusted limit', substr_count( $limited, '<figure ' ) === 24 );
	$ok( 'existing global limit of one hundred is respected while candidate fetches stay paginated', $configure( array_replace( $base, array( 'reviewLimit' => 100 ) ), true ) && substr_count( $render(), '<figure ' ) === 100 );
	$published = array_replace( $base, array( 'reviewLayout' => 'carousel', 'reviewAutoplay' => true, 'reviewInterval' => 4200, 'reviewLimit' => 2, 'reviewShowRating' => false, 'reviewVideoRatio' => '1/1' ) );
	$ok( 'global review settings publish through the real layout REST without changing a real template', $configure( $published, true ) );
	$draft = array_replace( $published, array( 'reviewLayout' => 'grid', 'reviewLimit' => 1, 'reviewShowRating' => true, 'reviewVideoRatio' => '16/9' ) ); $ok( 'new global draft saves separately from its published snapshot', $configure( $draft, false ) );
	$html = $render(); $ok( 'normal public review binding consumes published carousel count autoplay interval and hidden stars', substr_count( $html, '<figure ' ) === 2 && str_contains( $html, 'data-pc-cb-rail' ) && str_contains( $html, 'data-autoplay="1"' ) && str_contains( $html, 'data-interval="4200"' ) && ! str_contains( $html, '★' ) );
	$public_video = $render( 'video_reviews' ); $ok( 'published global video ratio reaches the bound real review video', str_contains( $public_video, 'aspect-ratio:1/1;' ) && ! str_contains( $public_video, 'aspect-ratio:16/9;' ) );
	$_GET = array( Templates::PREVIEW_PARAM => $template, Templates::NONCE_PARAM => wp_create_nonce( 'pc_template_preview_' . $template ) ); $preview = $render(); $ok( 'signed preview consumes draft layout limit and stars while retaining the product binding', substr_count( $preview, '<figure ' ) === 1 && ! str_contains( $preview, 'data-pc-cb-rail' ) && str_contains( $preview, '★' ) && ! str_contains( $preview, 'B-APPROVED' ) );
	$preview_video = $render( 'video_reviews' ); $ok( 'signed preview consumes draft video ratio without altering published data', str_contains( $preview_video, 'aspect-ratio:16/9;' ) );
	$ok( 'public and preview outputs disclose no review source email notes or store-edit provenance to an administrator', ! str_contains( $preview_video . $preview . $all, '@example.invalid' ) && ! str_contains( $preview_video . $preview . $all, 'INTERNAL-' ) && ! str_contains( $preview_video . $preview . $all, 'pc-review-source' ) && ! str_contains( $preview_video . $preview . $all, 'pc-review-provenance' ) );
	remove_filter( 'pre_option_' . Templates::ACTIVE_OPTION, $default ); $default = null; $_GET = array(); $_SERVER = $old_server;
	$ok( 'original orders layouts and saved options remain unchanged before fixture cleanup', $before['hash'] === $protected( $before['ids'] )['hash'] );
} finally {
	if ( $scope ) { remove_action( 'pre_get_comments', $scope ); } if ( $default ) { remove_filter( 'pre_option_' . Templates::ACTIVE_OPTION, $default ); }
	wp_set_current_user( $admin ); $_GET = array(); $_SERVER = $old_server; $GLOBALS['wp_query'] = $old_query; $GLOBALS['product'] = $old_product;
	if ( $bulk_comments && ! empty( $products['A'] ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $bulk_comments ), '%d' ) ); $values = array_merge( array( $products['A'] ), $bulk_comments );
		$wpdb->query( $wpdb->prepare( "DELETE m FROM {$wpdb->commentmeta} m INNER JOIN {$wpdb->comments} c ON c.comment_ID=m.comment_id WHERE c.comment_post_ID=%d AND c.comment_ID IN ($placeholders)", $values ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->comments} WHERE comment_post_ID=%d AND comment_ID IN ($placeholders)", $values ) ); clean_comment_cache( $bulk_comments );
	}
	foreach ( array_diff( $comments, $bulk_comments ) as $id ) { wp_delete_comment( $id, true ); } foreach ( $attachments as $id ) { wp_delete_attachment( $id, true ); }
	foreach ( array_reverse( $products ) as $id ) { if ( get_post( $id ) ) { wp_delete_post( $id, true ); } } if ( $page ) { wp_delete_post( $page, true ); }
	foreach ( $files as $file ) { if ( is_file( $file ) && str_contains( basename( $file ), $tag ) ) { unlink( $file ); } }
	if ( $template ) { foreach ( get_children( array( 'post_parent' => $template, 'post_type' => Templates::LAYOUT, 'post_status' => 'any', 'fields' => 'ids' ) ) as $id ) { foreach ( wp_get_post_revisions( $id, array( 'fields' => 'ids' ) ) as $revision ) { wp_delete_post_revision( $revision ); } wp_delete_post( $id, true ); } wp_delete_post( $template, true ); }
	remove_filter( 'pre_wp_mail', $no_mail ); $_GET = $old_get; $_SERVER = $old_server; wp_set_current_user( $old_user );
}
$ok( 'only own review binding fixtures media products and unassigned documents are fully removed', ! array_filter( $comments, static fn( $id ) => (bool) get_comment( $id ) ) && ! array_filter( $products, static fn( $id ) => (bool) get_post( $id ) ) && ! array_filter( $attachments, static fn( $id ) => (bool) get_post( $id ) ) && ! get_post( $template ) && ! get_post( $page ) && ! array_filter( $files, 'is_file' ) );
$ok( 'protected original orders all layout metadata and eight options still match after cleanup', $before['hash'] === $protected( $before['ids'] )['hash'] );
echo "Result: {$pass} passed, {$fail} failed\n";
if ( $fail ) { throw new RuntimeException( 'Review block binding smoke failed.' ); }
