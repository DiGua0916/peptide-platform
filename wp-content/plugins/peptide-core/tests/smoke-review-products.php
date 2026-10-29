<?php
/** Local regression for parent-product review navigation, scoped actions and permissions. */
defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Reviews_Page;
use PeptideCore\Reviews;
use PeptideCore\Staff;
use PeptideCore\Templates;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { throw new RuntimeException( 'Local development CLI only.' ); }
$pass = 0; $fail = 0;
$ok = static function ( string $label, bool $yes ) use ( &$pass, &$fail ): void { $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS ' : 'FAIL ' ) . $label . "\n"; };
$protected = static function ( ?array $ids = null ): array {
    if ( null === $ids ) { $ids = get_posts( array( 'post_type' => Templates::LAYOUT, 'post_status' => array_keys( get_post_stati() ), 'numberposts' => -1, 'fields' => 'ids' ) ); }
    $docs = array();
    foreach ( $ids as $id ) { clean_post_cache( $id ); $meta = array_filter( get_post_meta( $id ), static fn( $key ) => str_starts_with( (string) $key, '_pc_' ), ARRAY_FILTER_USE_KEY ); ksort( $meta ); $docs[ $id ] = array( get_post_field( 'post_content', $id ), $meta ); }
    ksort( $docs ); $orders = array(); foreach ( array( 54, 55 ) as $id ) { $order = wc_get_order( $id ); $orders[ $id ] = $order ? $order->get_data() : null; }
    $settings = array(); foreach ( array( 'timezone_string', 'gmt_offset', 'peptide_core_settings', 'peptide_core_tracking', 'peptide_core_media_queue', 'sticky_posts', Templates::ACTIVE_OPTION, Templates::DOMAIN_OPTION ) as $key ) { $settings[ $key ] = get_option( $key, null ); }
    return array( 'ids' => array_map( 'intval', array_keys( $docs ) ), 'hash' => hash( 'sha256', wp_json_encode( array( $docs, $orders, $settings ) ) ) );
};
$before = $protected(); $old_user = get_current_user_id(); $old_get = $_GET; $old_post = $_POST; $old_request = $_REQUEST; $old_files = $_FILES; $old_server = $_SERVER;
$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
if ( ! $admin ) { throw new RuntimeException( 'Administrator required.' ); }
$tag = 'pcrproducts-' . substr( wp_generate_uuid4(), 0, 8 );
$posts = array(); $comments = array(); $users = array(); $variations = array(); $permission_filter = null; $mail_calls = 0;
$no_mail = static function () use ( &$mail_calls ) { ++$mail_calls; return true; };
add_filter( 'pre_wp_mail', $no_mail );
// Scoped test handlers stop before wp_die/redirect exits, so finally always removes fixtures.
$die_filter = static fn() => static function ( $message, $title = '', $args = array() ): void { throw new RuntimeException( 'PCRPRODUCTS_DIE', is_array( $args ) ? (int) ( $args['response'] ?? 500 ) : 500 ); };
$redirect_filter = static function ( $location, $status ) { throw new RuntimeException( 'PCRPRODUCTS_REDIRECT:' . $location, (int) $status ); };
add_filter( 'wp_die_handler', $die_filter, PHP_INT_MAX );
add_filter( 'wp_redirect', $redirect_filter, -PHP_INT_MAX, 2 );
$invoke = static function ( callable $callback, array $get = array(), array $post = array() ): array {
    $_GET = array_merge( array( 'page' => Reviews_Page::SLUG ), $get ); $_POST = wp_slash( $post ); $_REQUEST = array_merge( $_GET, $_POST ); $_FILES = array(); $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $level = ob_get_level(); ob_start();
    try { $callback(); return array( 'kind' => 'html', 'html' => (string) ob_get_contents() ); }
    catch ( RuntimeException $e ) {
        if ( 'PCRPRODUCTS_DIE' === $e->getMessage() ) { return array( 'kind' => 'die', 'status' => $e->getCode() ); }
        if ( str_starts_with( $e->getMessage(), 'PCRPRODUCTS_REDIRECT:' ) ) { $url = substr( $e->getMessage(), strlen( 'PCRPRODUCTS_REDIRECT:' ) ); parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query ); return array( 'kind' => 'redirect', 'status' => $e->getCode(), 'query' => $query, 'url' => $url ); }
        throw $e;
    } finally { while ( ob_get_level() > $level ) { ob_end_clean(); } }
};
$render = static fn( array $get = array() ): array => $invoke( array( Reviews_Page::class, 'render' ), $get );
$ids_from = static function ( string $html, string $attribute ): array { preg_match_all( '~\b' . preg_quote( $attribute, '~' ) . '="(\d+)"~', $html, $matches ); return array_map( 'intval', $matches[1] ); };
$same_ids = static function ( array $a, array $b ): bool { sort( $a ); sort( $b ); return $a === $b; };
$product_row = static function ( string $html, int $id ): array { if ( ! preg_match( '~<tr\b[^>]*data-product-id="' . $id . '"[^>]*>(.*?)</tr>~s', $html, $row ) ) { return array(); } preg_match_all( '~<td\b[^>]*>(.*?)</td>~s', $row[1], $cells ); return array_map( static fn( $cell ) => trim( html_entity_decode( strip_tags( $cell ), ENT_QUOTES, 'UTF-8' ) ), $cells[1] ); };
$make_product = static function ( string $suffix, string $status = 'publish' ) use ( &$posts, $tag ): int {
    $p = new WC_Product_Simple(); $p->set_name( $tag . ' ' . $suffix ); $p->set_regular_price( '1' ); $p->set_reviews_allowed( true );
    $p->set_status( in_array( $status, array( 'trash', 'auto-draft' ), true ) ? 'draft' : $status );
    if ( 'future' === $status ) { $p->set_date_created( time() + DAY_IN_SECONDS ); }
    $id = $p->save(); $posts[] = $id;
    if ( in_array( $status, array( 'trash', 'auto-draft' ), true ) ) { wp_update_post( array( 'ID' => $id, 'post_status' => $status ) ); }
    return $id;
};
$make_comment = static function ( int $product, string $suffix, string $status = '1', string $type = 'review', int $parent = 0 ) use ( &$comments, $tag ): int {
    $id = wp_insert_comment( array( 'comment_post_ID' => $product, 'comment_author' => $tag . '-' . $suffix, 'comment_author_email' => $tag . '-' . $suffix . '@example.invalid', 'comment_content' => 'SYNTHETIC ' . $tag . ' ' . $suffix, 'comment_type' => $type, 'comment_parent' => $parent, 'comment_approved' => $status, 'comment_date' => '2020-01-01 12:00:00', 'comment_date_gmt' => '2020-01-01 12:00:00' ) );
    if ( ! $id ) { throw new RuntimeException( 'Fixture review creation failed.' ); }
    $comments[] = $id; update_comment_meta( $id, 'rating', 4 ); update_comment_meta( $id, Reviews::SOURCE, 'store' );
    if ( '' === $type ) { global $wpdb; $wpdb->update( $wpdb->comments, array( 'comment_type' => '' ), array( 'comment_ID' => $id, 'comment_post_ID' => $product ) ); clean_comment_cache( $id ); }
    return $id;
};
try {
    wp_set_current_user( $admin );
    $p = new WC_Product_Variable(); $p->set_name( $tag . ' main variable' ); $p->set_description( 'SYNTHETIC ' . $tag . ' hidden-body' ); $p->set_status( 'publish' ); $p->set_reviews_allowed( true );
    $attribute = new WC_Product_Attribute(); $attribute->set_name( 'Synthetic format' ); $attribute->set_options( array_map( static fn( $n ) => 'format-' . $n, range( 1, 20 ) ) ); $attribute->set_variation( true ); $attribute->set_visible( true ); $p->set_attributes( array( $attribute ) ); $variable = $p->save(); $posts[] = $variable;
    for ( $i = 1; $i <= 20; ++$i ) { $v = new WC_Product_Variation(); $v->set_parent_id( $variable ); $v->set_attributes( array( 'synthetic-format' => 'format-' . $i ) ); $v->set_status( 'publish' ); $v->set_regular_price( '1' ); $id = $v->save(); $posts[] = $id; $variations[] = $id; }
    WC_Product_Variable::sync( $variable );
    $zero = $make_product( 'main zero' ); $other = $make_product( 'main other' );
    $states = array(); foreach ( array( 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft' ) as $status ) { $states[ $status ] = $make_product( 'state ' . $status, $status ); }
    $page_products = array(); for ( $i = 1; $i <= 21; ++$i ) { $page_products[] = $make_product( 'pages ' . sprintf( '%02d', $i ) ); }
    $approved = $make_comment( $variable, 'approved' ); $legacy = $make_comment( $variable, 'legacy', '1', '' ); $pending = $make_comment( $variable, 'pending', '0' );
    $spam = $make_comment( $variable, 'spam', 'spam' ); $trash = $make_comment( $variable, 'trash', 'trash' );
    $reply = $make_comment( $variable, 'reply', '1', 'review', $approved ); $pingback = $make_comment( $variable, 'pingback', '1', 'pingback' ); $ordinary = $make_comment( $variable, 'ordinary-comment', '1', 'comment' );
    $other_review = $make_comment( $other, 'other-product' ); $child_review = $make_comment( $variations[0], 'legacy-child' );
    $paged_reviews = array(); for ( $i = 1; $i <= 21; ++$i ) { $paged_reviews[] = $make_comment( $page_products[0], 'paged-review-' . sprintf( '%02d', $i ) ); }
    $html = $render( array( 's' => $tag . ' main' ) )['html'] ?? '';
    $main_ids = $ids_from( $html, 'data-product-id' );
    $ok( 'catalog lists variable parent ordinary product and zero-review product once', $same_ids( $main_ids, array( $variable, $zero, $other ) ) );
    $ok( 'twenty actual variations collapse under one parent without changing their posts', count( wc_get_product( $variable )->get_children() ) === 20 && ! array_intersect( $main_ids, $variations ) && ! $ids_from( $html, 'data-review-id' ) );
    $row = $product_row( $html, $variable ); $zero_row = $product_row( $html, $zero );
    $ok( 'parent counts include native legacy reviews and exclude spam trash replies other comment types and child anomalies', array_slice( $row, 1, 3 ) === array( '3', '2', '1' ) );
    $ok( 'zero-review product has zero totals and a usable product link', array_slice( $zero_row, 1, 3 ) === array( '0', '0', '0' ) && str_contains( $html, 'product=' . $zero ) );
    $state_html = $render( array( 's' => $tag . ' state' ) )['html'] ?? '';
    $ok( 'catalog includes draft pending private future and excludes trashed or auto-draft products', $same_ids( $ids_from( $state_html, 'data-product-id' ), array( $states['draft'], $states['pending'], $states['private'], $states['future'] ) ) );
    $one = $render( array( 's' => $tag . ' pages' ) )['html'] ?? ''; $two = $render( array( 's' => $tag . ' pages', 'paged' => 2 ) )['html'] ?? '';
    $one_ids = $ids_from( $one, 'data-product-id' ); $two_ids = $ids_from( $two, 'data-product-id' );
    $ok( 'twenty-one search-scoped parent products paginate twenty plus one without loss or duplicate', count( $one_ids ) === 20 && count( $two_ids ) === 1 && ! array_intersect( $one_ids, $two_ids ) && $same_ids( array_merge( $one_ids, $two_ids ), $page_products ) );
    preg_match_all( '~<a\b[^>]*href="([^"]*)"[^>]*>~', $one, $page_links ); $next_query = array();
    foreach ( $page_links[1] as $link ) { parse_str( (string) wp_parse_url( html_entity_decode( $link, ENT_QUOTES, 'UTF-8' ), PHP_URL_QUERY ), $query ); if ( 2 === (int) ( $query['paged'] ?? 0 ) ) { $next_query = $query; break; } }
    $ok( 'product pagination preserves the name search and points back to catalog', ( $next_query['s'] ?? '' ) === $tag . ' pages' && ! isset( $next_query['product'] ) );
    $beyond = $render( array( 's' => $tag . ' pages', 'paged' => 999 ) )['html'] ?? '';
    $ok( 'out-of-range catalog page clamps to the final existing page', $ids_from( $beyond, 'data-product-id' ) === $two_ids );
    $none = $render( array( 's' => $tag . ' missing-name' ) )['html'] ?? '';
    $ok( 'unknown product name gives a genuine empty catalog rather than review content matches', ! $ids_from( $none, 'data-product-id' ) && str_contains( $none, '没有找到符合条件的商品' ) );
    $description_only = $render( array( 's' => $tag . ' hidden-body' ) )['html'] ?? '';
    $ok( 'product search matches names rather than a description-only keyword', ! $ids_from( $description_only, 'data-product-id' ) );
    $detail = $render( array( 'product' => $variable, 'new' => 1 ) )['html'] ?? '';
    $ok( 'product detail contains only its top-level approved legacy and pending reviews', $same_ids( $ids_from( $detail, 'data-review-id' ), array( $approved, $legacy, $pending ) ) );
    $ok( 'detail create form binds a hidden parent product and has no product selector', preg_match( '~<input\b[^>]*type="hidden"[^>]*name="product"[^>]*value="' . $variable . '"~', $detail ) === 1 && ! preg_match( '~<select\b[^>]*name="product"~', $detail ) && str_contains( $detail, get_the_title( $variable ) ) );
    $variation_detail = $render( array( 'product' => $variations[0] ) )['html'] ?? '';
    $ok( 'direct variation navigation resolves to the parent scope without moving any review', $same_ids( $ids_from( $variation_detail, 'data-review-id' ), array( $approved, $legacy, $pending ) ) && str_contains( $variation_detail, 'name="product" value="' . $variable . '"' ) && (int) get_comment( $child_review )->comment_post_ID === $variations[0] );
    $edit_detail = $render( array( 'edit' => $approved ) )['html'] ?? '';
    $ok( 'legacy edit-only link infers the exact parent product', str_contains( $edit_detail, '编辑评价 #' . $approved ) && str_contains( $edit_detail, 'name="product" value="' . $variable . '"' ) );
    $mismatch = $render( array( 'product' => $other, 'edit' => $approved ) ); $missing = $render( array( 'product' => PHP_INT_MAX ) );
    $ok( 'cross-product edit link and nonexistent product are rejected before rendering content', 'die' === $mismatch['kind'] && 404 === $mismatch['status'] && 'die' === $missing['kind'] && 404 === $missing['status'] );
    $empty_detail = $render( array( 'product' => $zero ) )['html'] ?? '';
    $ok( 'zero-review detail retains add-review entry and a scoped empty state', ! $ids_from( $empty_detail, 'data-review-id' ) && str_contains( $empty_detail, '暂无符合条件的评价' ) && str_contains( $empty_detail, 'product=' . $zero ) && str_contains( $empty_detail, 'new=1' ) );
    $only_pending = $render( array( 'product' => $variable, 'status' => 'hold' ) )['html'] ?? ''; $only_spam = $render( array( 'product' => $variable, 'status' => 'spam' ) )['html'] ?? '';
    $searched = $render( array( 'product' => $variable, 's' => $tag . '-approved' ) )['html'] ?? '';
    $ok( 'review status and text searches stay inside the chosen parent product', $ids_from( $only_pending, 'data-review-id' ) === array( $pending ) && $ids_from( $only_spam, 'data-review-id' ) === array( $spam ) && $ids_from( $searched, 'data-review-id' ) === array( $approved ) );
    $review_one = $render( array( 'product' => $page_products[0], 's' => $tag . '-paged-review' ) )['html'] ?? ''; $review_two = $render( array( 'product' => $page_products[0], 's' => $tag . '-paged-review', 'paged' => 2 ) )['html'] ?? '';
    $review_one_ids = $ids_from( $review_one, 'data-review-id' ); $review_two_ids = $ids_from( $review_two, 'data-review-id' );
    $ok( 'twenty-one same-date reviews paginate deterministically within one selected product', count( $review_one_ids ) === 20 && count( $review_two_ids ) === 1 && ! array_intersect( $review_one_ids, $review_two_ids ) && $same_ids( array_merge( $review_one_ids, $review_two_ids ), $paged_reviews ) && $review_two_ids === array( $paged_reviews[0] ) );
    preg_match_all( '~<a\b[^>]*href="([^"]*)"[^>]*>~', $review_one, $review_links ); $review_next = array();
    foreach ( $review_links[1] as $link ) { parse_str( (string) wp_parse_url( html_entity_decode( $link, ENT_QUOTES, 'UTF-8' ), PHP_URL_QUERY ), $query ); if ( 2 === (int) ( $query['paged'] ?? 0 ) ) { $review_next = $query; break; } }
    $ok( 'review pagination retains parent product status and review search', (int) ( $review_next['product'] ?? 0 ) === $page_products[0] && ( $review_next['s'] ?? '' ) === $tag . '-paged-review' && ( $review_next['status'] ?? '' ) === 'all' );
    foreach ( array( 'products', 'marketing', 'data' ) as $module ) {
        $user = wp_insert_user( array( 'user_login' => $tag . '-' . $module, 'user_pass' => wp_generate_password( 32 ), 'role' => Staff::ROLE ) ); if ( is_wp_error( $user ) ) { throw new RuntimeException( 'Fixture user creation failed.' ); } $users[] = $user; Staff::set_modules( $user, array( $module ) ); wp_set_current_user( $user );
        $overview_result = $render( array( 's' => $tag . ' main' ) ); $detail_result = $render( array( 'product' => $variable ) );
        $allowed = 'products' === $module;
        $ok( $module . ' employee catalog and product access match module permission', $allowed ? 'html' === $overview_result['kind'] && 'html' === $detail_result['kind'] && $same_ids( $ids_from( $overview_result['html'], 'data-product-id' ), array( $variable, $zero, $other ) ) : 'die' === $overview_result['kind'] && 403 === $overview_result['status'] && 'die' === $detail_result['kind'] && 403 === $detail_result['status'] );
    }
    wp_set_current_user( 0 ); $guest = $render( array( 'product' => $variable ) );
    $ok( 'guest cannot open the product review management detail', 'die' === $guest['kind'] && 403 === $guest['status'] );
    wp_set_current_user( $admin );
    $permission_filter = static function ( array $caps, string $cap, int $user, array $args ) use ( $other ): array { return 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $other ? array( 'do_not_allow' ) : $caps; };
    add_filter( 'map_meta_cap', $permission_filter, PHP_INT_MAX, 4 );
    $restricted = $render( array( 's' => $tag . ' main' ) )['html'] ?? ''; $restricted_detail = $render( array( 'product' => $other ) );
    $ok( 'individual product edit permission is enforced for catalog rows and direct detail access', ! in_array( $other, $ids_from( $restricted, 'data-product-id' ), true ) && 'die' === $restricted_detail['kind'] && 404 === $restricted_detail['status'] );
    remove_filter( 'map_meta_cap', $permission_filter, PHP_INT_MAX ); $permission_filter = null;
    $base = array( 'product' => $variable, 'author' => $tag . '-new', 'email' => $tag . '-new@example.invalid', 'rating' => '5', 'content' => 'SYNTHETIC scoped save ' . $tag, 'source' => 'store', 'status' => '0', 'return_status' => 'hold', 'return_search' => $tag . '-new', 'return_paged' => '2', '_wpnonce' => wp_create_nonce( 'pc_review_save' ) );
    $saved = $invoke( array( Reviews_Page::class, 'save' ), array(), $base ); $new_id = (int) ( $saved['query']['edit'] ?? 0 );
    if ( $new_id ) { $comments[] = $new_id; }
    $ok( 'actual admin create action saves on the selected parent and returns to that product with filters', 'redirect' === $saved['kind'] && $new_id > 0 && (int) get_comment( $new_id )->comment_post_ID === $variable && (int) ( $saved['query']['product'] ?? 0 ) === $variable && ( $saved['query']['status'] ?? '' ) === 'hold' && ( $saved['query']['s'] ?? '' ) === $base['return_search'] && (int) ( $saved['query']['paged'] ?? 0 ) === 2 );
    if ( ! $new_id ) { throw new RuntimeException( 'Scoped create action did not return a fixture review ID.' ); }
    $cross_save = $base; $cross_save['id'] = $new_id; $cross_save['product'] = $other; $cross_save['content'] = 'SYNTHETIC forbidden cross-product move ' . $tag;
    $blocked_save = $invoke( array( Reviews_Page::class, 'save' ), array(), $cross_save );
    $ok( 'forged edit product cannot move or overwrite a review owned by another parent', 'die' === $blocked_save['kind'] && 403 === $blocked_save['status'] && (int) get_comment( $new_id )->comment_post_ID === $variable && get_comment( $new_id )->comment_content === $base['content'] );
    $edit = $base; $edit['id'] = $new_id; $edit['content'] = 'SYNTHETIC edited in scope ' . $tag; $edited = $invoke( array( Reviews_Page::class, 'save' ), array(), $edit );
    $ok( 'actual admin edit action preserves product context and updates only its review', 'redirect' === $edited['kind'] && (int) ( $edited['query']['product'] ?? 0 ) === $variable && (int) ( $edited['query']['edit'] ?? 0 ) === $new_id && get_comment( $new_id )->comment_content === $edit['content'] && (int) get_comment( $other_review )->comment_post_ID === $other );
    $moderation = array( 'product' => $variable, 'review' => $new_id, 'status' => 'approve', 'return_status' => 'hold', 'return_search' => $tag . '-new', 'return_paged' => '2', '_wpnonce' => wp_create_nonce( 'pc_review_moderate' ) );
    $cross_moderation = $moderation; $cross_moderation['product'] = $other; $blocked_moderation = $invoke( array( Reviews_Page::class, 'moderate' ), array(), $cross_moderation );
    $ok( 'forged moderation product is rejected without changing review status', 'die' === $blocked_moderation['kind'] && 403 === $blocked_moderation['status'] && '0' === get_comment( $new_id )->comment_approved );
    $moderated = $invoke( array( Reviews_Page::class, 'moderate' ), array(), $moderation );
    $ok( 'actual moderation updates review and audit then returns to the exact product and filters', 'redirect' === $moderated['kind'] && '1' === get_comment( $new_id )->comment_approved && (int) ( $moderated['query']['product'] ?? 0 ) === $variable && ( $moderated['query']['status'] ?? '' ) === 'hold' && ( $moderated['query']['s'] ?? '' ) === $moderation['return_search'] && (int) ( $moderated['query']['paged'] ?? 0 ) === 2 && count( (array) get_comment_meta( $new_id, '_pc_review_changes', true ) ) === 3 );
    $bad_nonce = $moderation; $bad_nonce['_wpnonce'] = 'invalid'; $bad_nonce['status'] = 'hold'; $denied_nonce = $invoke( array( Reviews_Page::class, 'moderate' ), array(), $bad_nonce );
    $ok( 'moderation nonce rejection leaves approved fixture unchanged', 'die' === $denied_nonce['kind'] && 403 === $denied_nonce['status'] && '1' === get_comment( $new_id )->comment_approved );
    $ok( 'original orders all layout metadata and selected settings remain unchanged during scoped navigation', $before['hash'] === $protected( $before['ids'] )['hash'] );
} finally {
    if ( $permission_filter ) { remove_filter( 'map_meta_cap', $permission_filter, PHP_INT_MAX ); }
    wp_set_current_user( $admin );
    foreach ( array_unique( $comments ) as $id ) { wp_delete_comment( $id, true ); }
    foreach ( array_reverse( $posts ) as $id ) { if ( get_post( $id ) ) { wp_delete_post( $id, true ); } }
    require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { wp_delete_user( $id ); }
    remove_filter( 'pre_wp_mail', $no_mail ); remove_filter( 'wp_die_handler', $die_filter, PHP_INT_MAX ); remove_filter( 'wp_redirect', $redirect_filter, -PHP_INT_MAX );
    $_GET = $old_get; $_POST = $old_post; $_REQUEST = $old_request; $_FILES = $old_files; $_SERVER = $old_server; wp_set_current_user( $old_user );
}
$ok( 'only this fixture products variations reviews users and sessions are fully removed', ! array_filter( $posts, static fn( $id ) => (bool) get_post( $id ) ) && ! array_filter( $comments, static fn( $id ) => (bool) get_comment( $id ) ) && ! array_filter( $users, static fn( $id ) => (bool) get_userdata( $id ) ) );
$ok( 'protected original state still matches after exact fixture cleanup', $before['hash'] === $protected( $before['ids'] )['hash'] );
echo 'Result: ' . $pass . ' passed, ' . $fail . " failed\n";
if ( $fail ) { throw new RuntimeException( 'Product-scoped review regression failed.' ); }
