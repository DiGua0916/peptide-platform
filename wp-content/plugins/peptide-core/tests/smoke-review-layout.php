<?php
/** Local integration of published/preview global review settings with commerce rendering. */
defined( 'ABSPATH' ) || exit;

use PeptideCore\Templates;
use PeptideCore\Reviews;
use PeptideCore\Api\Layout_Controller;
use PeptideCore\Modules\Layout;
use PeptideCore\Modules\Renderer;
use PeptideCore\Modules\Commerce_Blocks;
use PeptideCore\Modules\Site_Design;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { throw new RuntimeException( 'Local development CLI only.' ); }
$pass = 0; $fail = 0;
$ok = static function ( string $label, bool $yes ) use ( &$pass, &$fail ): void { $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS ' : 'FAIL ' ) . $label . "\n"; };
$protected = static function ( array $ids = array() ): array {
    if ( ! $ids ) { $ids = get_posts( array( 'post_type' => Templates::LAYOUT, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ); }
    $docs = array();
    foreach ( $ids as $id ) { clean_post_cache( $id ); $meta = array_filter( get_post_meta( $id ), static fn( $key ) => str_starts_with( (string) $key, '_pc_' ), ARRAY_FILTER_USE_KEY ); ksort( $meta ); $docs[ $id ] = array( get_post_field( 'post_content', $id ), $meta ); }
    ksort( $docs ); $orders = array(); foreach ( array( 54, 55 ) as $id ) { $order = wc_get_order( $id ); $orders[ $id ] = $order ? $order->get_data() : null; }
    return array( 'ids' => array_map( 'intval', array_keys( $docs ) ), 'hash' => hash( 'sha256', wp_json_encode( array( get_option( Templates::ACTIVE_OPTION ), get_option( Templates::DOMAIN_OPTION ), $docs, $orders ) ) ) );
};
$before = $protected(); $old_user = get_current_user_id(); $old_get = $_GET; $old_server = $_SERVER;
$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
if ( ! $admin ) { throw new RuntimeException( 'Administrator required.' ); }
$template = 0; $product = 0; $video = 0; $docs = array(); $comments = array(); $query_scope = null; $default_scope = null;
$tag = 'pcreviewlayout-' . substr( wp_generate_uuid4(), 0, 8 );
$base = array( 'reviewLayout' => 'grid', 'reviewAutoplay' => false, 'reviewInterval' => 5000, 'reviewOrder' => 'newest', 'reviewMinimumRating' => 0, 'reviewShowRating' => true, 'reviewLimit' => 0, 'reviewVideoRatio' => 'original' );
$call = static function ( string $method, int $id, string $suffix = '', array $body = array() ) { $r = new WP_REST_Request( $method, '/peptide/v1/layouts/' . $id . $suffix ); $r->set_header( 'content-type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); return rest_do_request( $r ); };
$configure = static function ( array $overrides = array(), bool $publish = false ) use ( &$docs, $base, $call ): bool {
    $id = $docs['global']; $read = $call( 'GET', $id )->get_data();
    $saved = $call( 'POST', $id, '', array( 'revision' => $read['revision'], 'modules' => array( array( 'block' => 'vialcove/site-styles', 'attrs' => array( 'settings' => array_replace( $base, $overrides ) ) ) ) ) );
    if ( 200 !== $saved->get_status() ) { return false; }
    if ( ! $publish ) { return true; }
    $read = $call( 'GET', $id )->get_data(); return 200 === $call( 'POST', $id, '/publish', array( 'revision' => $read['revision'] ) )->get_status();
};
$render = static fn( string $type = 'text_reviews', int $limit = 8 ): string => Renderer::render( $type, array( 'settings' => array( 'limit' => $limit ) ), 'public' );
$authors = static function ( string $html ): array { preg_match_all( '~<figcaption><strong>(.*?)</strong>~s', $html, $found ); return array_map( static fn( $value ) => html_entity_decode( strip_tags( $value ), ENT_QUOTES, 'UTF-8' ), $found[1] ); };
try {
    wp_set_current_user( $admin ); $_GET = array();
    $public_before = Site_Design::review_settings();
    $template = Templates::create( $tag, 'Synthetic global review layout regression', $admin );
    foreach ( array_keys( Templates::documents() ) as $key ) { $docs[ $key ] = Templates::document_id( $template, $key, false ); }
    $ok( 'new review-layout template remains unassigned', $template > 0 && Templates::default_id( false ) !== $template && ! in_array( $template, Templates::domain_map(), true ) );
    $p = new WC_Product_Simple(); $p->set_name( 'SYNTHETIC ' . $tag ); $p->set_status( 'publish' ); $p->set_regular_price( '1' ); $p->set_reviews_allowed( true ); $product = $p->save();
    // Metadata-only synthetic media is enough for renderer attribute tests; no file or playback claim.
    $video = wp_insert_attachment( array( 'post_title' => 'SYNTHETIC review layout video', 'post_status' => 'inherit', 'post_mime_type' => 'video/webm', 'guid' => home_url( '/synthetic-' . $tag . '.webm' ) ), false, $product );
    $sources = array( 1 => 'store', 3 => 'imported', 5 => 'buyer' ); $days = array( 1 => '03', 3 => '01', 5 => '02' ); $names = array();
    foreach ( array( 1, 3, 5 ) as $rating ) {
        $name = $tag . '-rating-' . $rating; $names[ $rating ] = $name;
        $id = wp_insert_comment( array( 'comment_post_ID' => $product, 'comment_author' => $name, 'comment_author_email' => $name . '@example.invalid', 'comment_content' => 'SYNTHETIC fixture body ' . $name, 'comment_type' => 'review', 'comment_approved' => '1', 'comment_date' => '2020-01-' . $days[ $rating ] . ' 12:00:00', 'comment_date_gmt' => '2020-01-' . $days[ $rating ] . ' 12:00:00' ) );
        $comments[] = $id; update_comment_meta( $id, 'rating', $rating ); update_comment_meta( $id, Reviews::SOURCE, $sources[ $rating ] ); update_comment_meta( $id, '_pc_review_reference', 'INTERNAL-' . $tag ); update_comment_meta( $id, Commerce_Blocks::REVIEW_VIDEO, $video ); update_comment_meta( $id, Commerce_Blocks::REVIEW_FEATURED, '1' );
        if ( 5 === $rating ) { update_comment_meta( $id, '_pc_review_store_edited', '1' ); }
    }
    $pending = wp_insert_comment( array( 'comment_post_ID' => $product, 'comment_author' => $tag . '-pending', 'comment_content' => 'SYNTHETIC pending fixture', 'comment_type' => 'review', 'comment_approved' => '0', 'comment_date' => '2020-02-01 12:00:00', 'comment_date_gmt' => '2020-02-01 12:00:00' ) ); $comments[] = $pending; update_comment_meta( $pending, 'rating', 5 ); update_comment_meta( $pending, Commerce_Blocks::REVIEW_VIDEO, $video );
    $ok( 'fixture has real approved one three five star comments and an excluded pending comment', count( get_comments( array( 'post_id' => $product, 'status' => 'approve' ) ) ) === 3 && '0' === get_comment( $pending )->comment_approved );
    // Scope the real renderer comment query to this fixture without changing saved site state.
    $query_scope = static function ( WP_Comment_Query $query ) use ( $product ): void { if ( 'product' === ( $query->query_vars['post_type'] ?? '' ) ) { $query->query_vars['post_id'] = $product; } };
    add_action( 'pre_get_comments', $query_scope );
    $ok( 'global settings save as a draft through actual layout REST', $configure() && ! Layout::is_published( $docs['global'] ) );
    $ok( 'global review settings publish through actual layout REST', $configure( array(), true ) && Layout::is_published( $docs['global'] ) );
    $snapshot = Layout::published_modules( 'global', $template );
    $ok( 'published snapshot records normalized review settings', 'newest' === ( $snapshot[0]['attrs']['settings']['reviewOrder'] ?? '' ) && 'grid' === ( $snapshot[0]['attrs']['settings']['reviewLayout'] ?? '' ) );
    $_GET = array( Templates::PREVIEW_PARAM => $template, Templates::NONCE_PARAM => wp_create_nonce( 'pc_template_preview_' . $template ) );
    $ok( 'valid preview selects only the unassigned fixture template', Templates::previewing_id() === $template );
    foreach ( array( 'newest' => array( 1, 5, 3 ), 'oldest' => array( 3, 5, 1 ), 'rating_desc' => array( 5, 3, 1 ), 'rating_asc' => array( 1, 3, 5 ) ) as $order => $expected ) {
        $saved = $configure( array( 'reviewOrder' => $order ) ); $html = $render();
        $ok( $order . ' global order reaches actual commerce review figures', $saved && $authors( $html ) === array_map( static fn( $rating ) => $names[ $rating ], $expected ) );
    }
    $configure( array( 'reviewOrder' => 'rating_asc', 'reviewMinimumRating' => 3 ) );
    $ok( 'minimum star filter excludes one star review before rendering', $authors( $render() ) === array( $names[3], $names[5] ) );
    $configure( array( 'reviewOrder' => 'rating_desc', 'reviewLimit' => 1 ) );
    $ok( 'global limit overrides the per-block review limit', $authors( $render( 'text_reviews', 3 ) ) === array( $names[5] ) );
    $configure( array( 'reviewOrder' => 'rating_desc', 'reviewLimit' => 0 ) );
    $ok( 'zero global limit preserves each blocks configured limit', $authors( $render( 'text_reviews', 2 ) ) === array( $names[5], $names[3] ) );
    $configure( array( 'reviewShowRating' => false ) );
    $ok( 'global hidden stars remove visible and accessible star labels', ! str_contains( $render(), '★' ) && ! str_contains( $render(), 'out of 5' ) );
    $configure(); $html = $render();
    $ok( 'global visible stars render all actual fixture ratings', substr_count( $html, 'aria-label=' ) === 3 && str_contains( $html, '★★★★★' ) );
    $ok( 'pending review never enters commerce output', ! str_contains( $html, $tag . '-pending' ) && count( $authors( $html ) ) === 3 );
    $configure( array( 'reviewLayout' => 'carousel', 'reviewAutoplay' => true, 'reviewInterval' => 4200, 'reviewOrder' => 'rating_desc', 'reviewLimit' => 2, 'reviewShowRating' => false ) );
    $html = $render();
    $ok( 'carousel global layout supplies actual rail and controls', str_contains( $html, 'data-pc-cb-rail' ) && str_contains( $html, 'pc-cb-rail' ) && str_contains( $html, 'data-pc-cb-prev' ) && str_contains( $html, 'data-pc-cb-next' ) );
    $ok( 'autoplay and interval reach the carousel runtime attributes', str_contains( $html, 'data-autoplay="1"' ) && str_contains( $html, 'data-interval="4200"' ) && str_contains( $html, 'data-pc-cb-toggle' ) );
    $ok( 'valid preview uses saved draft review settings', Site_Design::review_settings()['limit'] === 2 && count( $authors( $html ) ) === 2 && ! str_contains( $html, '★' ) );
    // Runtime-only default read filter exercises the fixture published snapshot, with no option write.
    $preview_args = $_GET; $_GET = array(); $_SERVER['HTTP_HOST'] = $tag . '.invalid';
    $default_scope = static fn() => $template; add_filter( 'pre_option_' . Templates::ACTIVE_OPTION, $default_scope );
    $published_html = $render();
    $ok( 'published rendering ignores newer carousel limit and hidden-star draft', Templates::current_id() === $template && Site_Design::review_settings()['order'] === 'newest' && count( $authors( $published_html ) ) === 3 && ! str_contains( $published_html, 'data-pc-cb-rail' ) && str_contains( $published_html, '★' ) );
    remove_filter( 'pre_option_' . Templates::ACTIVE_OPTION, $default_scope ); $default_scope = null; $_GET = $preview_args;
    $configure( array( 'reviewLayout' => 'carousel', 'reviewAutoplay' => false, 'reviewInterval' => 6000 ) ); $html = $render();
    $ok( 'disabled autoplay keeps manual carousel without a pause toggle', str_contains( $html, 'data-autoplay="0"' ) && str_contains( $html, 'data-interval="6000"' ) && ! str_contains( $html, 'data-pc-cb-toggle' ) );
    foreach ( array( 'grid', 'inherit' ) as $layout ) { $configure( array( 'reviewLayout' => $layout ) ); $ok( $layout . ' layout keeps ordinary review grid', ! str_contains( $render(), 'data-pc-cb-rail' ) && str_contains( $render(), 'pc-cb-grid' ) ); }
    foreach ( array( '16/9', '4/3', '1/1', '9/16' ) as $ratio ) { $configure( array( 'reviewVideoRatio' => $ratio ) ); $html = $render( 'video_reviews' ); $ok( 'video review ratio ' . $ratio . ' reaches all actual video elements', substr_count( $html, '<video ' ) === 3 && substr_count( $html, 'aspect-ratio:' . $ratio . ';object-fit:contain;width:100%' ) === 3 ); }
    $configure(); $html = $render( 'video_reviews' );
    $ok( 'original video ratio adds no aspect override', substr_count( $html, '<video ' ) === 3 && ! str_contains( $html, 'aspect-ratio:' ) );
    $ok( 'featured reviews consume the same global minimum star and limit settings', $configure( array( 'reviewMinimumRating' => 3, 'reviewLimit' => 1, 'reviewOrder' => 'rating_desc' ) ) && $authors( $render( 'featured_reviews' ) ) === array( $names[5] ) );
    $configure(); $html = $render( 'featured_reviews' );
    $ok( 'commerce public output contains no source email notes or edit provenance even for admin viewer', ! str_contains( $html, 'pc-review-source' ) && ! str_contains( $html, 'pc-review-provenance' ) && ! str_contains( $html, '@example.invalid' ) && ! str_contains( $html, 'INTERNAL-' ) && ! str_contains( $html, 'Store entry' ) && ! str_contains( $html, 'Imported review' ) && ! str_contains( $html, 'Edited by store' ) );
    $ok( 'same sources and edit provenance remain available in explicit admin context', str_contains( Reviews::source_html( $comments[0], true ), 'pc-review-source' ) && str_contains( Reviews::source_html( $comments[1], true ), 'pc-review-source' ) && substr_count( Reviews::source_html( $comments[2], true ), 'pc-review-source' ) === 2 );
    $_GET = array(); $_SERVER = $old_server;
    $ok( 'unassigned draft and published global settings do not change normal storefront settings', Site_Design::review_settings() === $public_before );
    $ok( 'default domains all original layout metadata and orders 54 55 remain unchanged', $before['hash'] === $protected( $before['ids'] )['hash'] );
} finally {
    if ( $default_scope ) { remove_filter( 'pre_option_' . Templates::ACTIVE_OPTION, $default_scope ); }
    if ( $query_scope ) { remove_action( 'pre_get_comments', $query_scope ); }
    wp_set_current_user( $admin ); $_GET = array(); $_SERVER = $old_server;
    foreach ( $comments as $id ) { wp_delete_comment( $id, true ); }
    if ( $video ) { wp_delete_attachment( $video, true ); }
    if ( $product ) { wp_delete_post( $product, true ); }
    if ( $template ) { foreach ( get_children( array( 'post_parent' => $template, 'post_type' => Templates::LAYOUT, 'post_status' => 'any', 'fields' => 'ids' ) ) as $id ) { foreach ( wp_get_post_revisions( $id, array( 'fields' => 'ids' ) ) as $revision ) { wp_delete_post_revision( $revision ); } wp_delete_post( $id, true ); } wp_delete_post( $template, true ); }
    $_GET = $old_get; $_SERVER = $old_server; wp_set_current_user( $old_user );
}
$ok( 'only own product comments media template and documents are cleaned', ! get_post( $product ) && ! get_post( $video ) && ! get_post( $template ) && ! array_filter( $comments, static fn( $id ) => (bool) get_comment( $id ) ) && ! array_filter( $docs, static fn( $id ) => (bool) get_post( $id ) ) );
$ok( 'original protected state is unchanged after exact fixture cleanup', $before['hash'] === $protected( $before['ids'] )['hash'] );
echo "RESULT {$pass} PASS / {$fail} FAIL\n";
if ( $fail ) { throw new RuntimeException( 'Review layout integration smoke failed.' ); }
