<?php
/** Local synthetic regression for real blog/category/topic block bindings; no layouts are published. */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { throw new RuntimeException( 'Local WP-CLI only.' ); }

use PeptideCore\Modules\Content_Blocks;
use PeptideCore\Modules\Fields;
use PeptideCore\Modules\Registry;
use PeptideCore\Modules\Renderer;

$pass = 0; $fail = 0;
$ok = static function ( string $label, bool $yes ) use ( &$pass, &$fail ): void { $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS ' : 'FAIL ' ) . $label . "\n"; };
$snapshot = static function (): string {
	$layouts = array();
	foreach ( get_posts( array( 'post_type' => 'pc_layout', 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) {
		$meta = array_filter( get_post_meta( $p->ID ), static fn( $key ) => str_starts_with( (string) $key, '_pc_' ), ARRAY_FILTER_USE_KEY ); ksort( $meta ); $layouts[ $p->ID ] = array( $p->post_content, $meta );
	}
	ksort( $layouts ); $orders = array(); foreach ( array( 54, 55 ) as $id ) { $o = wc_get_order( $id ); $orders[ $id ] = $o ? $o->get_data() : null; }
	$options = array(); foreach ( array( 'timezone_string', 'gmt_offset', 'peptide_core_settings', 'peptide_core_tracking', 'peptide_core_media_queue', 'sticky_posts', 'peptide_core_active_template', 'peptide_core_template_domains' ) as $key ) { $options[ $key ] = get_option( $key, null ); }
	return hash( 'sha256', wp_json_encode( array( $layouts, $orders, $options ) ) );
};
$before = $snapshot(); $old_user = get_current_user_id(); $posts = array(); $terms = array(); $tag = 'SYNTHETIC-content-' . substr( wp_generate_uuid4(), 0, 8 );
$no_mail = static fn() => true; add_filter( 'pre_wp_mail', $no_mail );
$make_post = static function ( string $type, string $suffix, string $status = 'publish', string $password = '' ) use ( &$posts, $tag ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => $status, 'post_title' => $tag . '-' . $suffix, 'post_content' => 'Synthetic binding fixture only.', 'post_password' => $password ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Synthetic content fixture failed.' ); }
	$posts[] = (int) $id; return (int) $id;
};
$row = static fn( string $key, int $id, bool $enabled = true ) => array( 'id' => 'bound-' . $id, 'enabled' => $enabled, 'settings' => array( $key => $id ) );
$render = static fn( string $type, array $settings = array() ): string => Renderer::render( $type, array( 'moduleId' => 'content-binding-fixture', 'settings' => $settings ), 'public' );
try {
	wp_set_current_user( 0 );
	foreach ( array( 'a', 'b', 'empty', 'removed' ) as $suffix ) { $term = wp_insert_term( $tag . '-category-' . $suffix, 'category' ); if ( is_wp_error( $term ) ) { throw new RuntimeException( 'Synthetic category failed.' ); } $cats[ $suffix ] = (int) $term['term_id']; $terms[] = $cats[ $suffix ]; }
	$a = $make_post( 'post', 'post-a' ); $a2 = $make_post( 'post', 'post-a2' ); $b = $make_post( 'post', 'post-b' );
	foreach ( array( $a, $a2 ) as $id ) { wp_set_post_categories( $id, array( $cats['a'] ) ); } wp_set_post_categories( $b, array( $cats['b'] ) );
	$private = $make_post( 'post', 'post-private', 'private' ); $draft = $make_post( 'post', 'post-draft', 'draft' ); $password = $make_post( 'post', 'post-password', 'publish', 'synthetic-only' );
	foreach ( array( $private, $draft, $password ) as $id ) { wp_set_post_categories( $id, array( $cats['a'] ) ); }
	$wrong_type = $make_post( 'page', 'wrong-post-type' );
	$deleted_post = $make_post( 'post', 'post-deleted' ); wp_delete_post( $deleted_post, true );
	$topics = array();
	for ( $i = 1; $i <= 101; ++$i ) { $topics[] = $make_post( 'pc_topic', 'z-topic-' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ) ); }
	$topic_private = $make_post( 'pc_topic', 'topic-private', 'private' ); $topic_draft = $make_post( 'pc_topic', 'topic-draft', 'draft' ); $topic_password = $make_post( 'pc_topic', 'topic-password', 'publish', 'synthetic-only' );
	$deleted_topic = $make_post( 'pc_topic', 'topic-deleted' ); wp_delete_post( $deleted_topic, true );
	Registry::flush(); $defs = Content_Blocks::definitions( array() );
	$field = static function ( array $list, string $key ): ?array { foreach ( $list as $value ) { if ( $key === $value['key'] ) { return $value; } } return null; };
	$blog_field = $field( $defs['blog_posts']['settings'], 'blogCategory' ); $list_field = $field( $defs['blog_categories']['settings'], 'blogCategory' ); $campaign_field = $field( $defs['campaign_pages']['settings'], 'pages' );
	$ok( 'blog and category-list selectors use live blog-category record fields without fixed option lists', 'record' === $blog_field['type'] && 'blog_category' === $blog_field['resource'] && 'record' === $list_field['type'] && ! isset( $blog_field['options'] ) );
	$ok( 'campaign pages remove the irrelevant blog-category field and use manual topic records', null === $field( $defs['campaign_pages']['settings'], 'blogCategory' ) && 'record' === $campaign_field['fields'][0]['type'] && 'topic' === $campaign_field['fields'][0]['resource'] );
	$ok( 'legacy campaign default remains automatic all-published topics', 'all' === $field( $defs['campaign_pages']['settings'], 'source' )['default'] );
	$ok( 'positive stale record bindings are retained so they cannot silently become automatic', 2147483000 === Fields::sanitize( $blog_field, 2147483000 ) );

	$html = $render( 'blog_categories', array( 'blogCategory' => $cats['a'] ) );
	$ok( 'actual category-list renderer honors the selected real category', str_contains( $html, $tag . '-category-a' ) && ! str_contains( $html, $tag . '-category-b' ) && 1 === substr_count( $html, '<a class="pc-cb-card"' ) );
	$ok( 'a valid selected empty category is still a single deliberate category card', str_contains( $render( 'blog_categories', array( 'blogCategory' => $cats['empty'] ) ), $tag . '-category-empty' ) );
	$ok( 'missing selected category does not fall back to all real categories', '' === $render( 'blog_categories', array( 'blogCategory' => 2147483000 ) ) );
	wp_delete_term( $cats['removed'], 'category' );
	$ok( 'deleted category binding remains empty instead of showing other collections', '' === $render( 'blog_categories', array( 'blogCategory' => $cats['removed'] ) ) );
	$auto_categories = $render( 'blog_categories', array( 'limit' => 24 ) );
	$ok( 'legacy empty category selection still automatically shows published nonempty collections', str_contains( $auto_categories, $tag . '-category-a' ) && str_contains( $auto_categories, $tag . '-category-b' ) );

	foreach ( array( 'blog_posts', 'category_blog' ) as $type ) {
		$html = $render( $type, array( 'blogCategory' => $cats['a'] ) );
		$ok( $type . ' automatic posts really filter the chosen category and exclude private draft and password posts', str_contains( $html, $tag . '-post-a' ) && ! str_contains( $html, $tag . '-post-b' ) && ! str_contains( $html, $tag . '-post-private' ) && ! str_contains( $html, $tag . '-post-draft' ) && ! str_contains( $html, $tag . '-post-password' ) );
		$manual = array( $row( 'post', $b ), $row( 'post', $a ) ); $html = $render( $type, array( 'blogCategory' => $cats['a'], 'posts' => $manual ) );
		$ok( $type . ' manual post choices intersect the chosen category instead of widening results', str_contains( $html, $tag . '-post-a' ) && ! str_contains( $html, $tag . '-post-b' ) && ! str_contains( $html, $tag . '-post-a2' ) );
		$ok( $type . ' stale or nonpublic explicit post choices never return unrelated articles', '' === $render( $type, array( 'posts' => array( $row( 'post', $private ), $row( 'post', $draft ), $row( 'post', $password ), $row( 'post', $wrong_type ), $row( 'post', $deleted_post ) ) ) ) );
		$ok( $type . ' an explicitly disabled manual list stays empty', '' === $render( $type, array( 'posts' => array( $row( 'post', $a, false ) ) ) ) );
	}
	$html = $render( 'blog_posts', array( 'posts' => array( $row( 'post', $a2 ), $row( 'post', $a ) ), 'limit' => 1 ) );
	$ok( 'manual posts preserve chosen order while respecting the block item limit', str_contains( $html, $tag . '-post-a2' ) && 1 === substr_count( $html, '<article class="pc-cb-card"' ) );
	$ok( 'invalid selected blog category does not widen an automatic article query', '' === $render( 'blog_posts', array( 'blogCategory' => 2147483000 ) ) );

	$html = $render( 'campaign_pages', array( 'source' => 'manual', 'pages' => array( $row( 'page', $topics[100] ) ) ) );
	$ok( 'the 101st real topic can be bound and rendered without the old 100-option ceiling', str_contains( $html, $tag . '-z-topic-101' ) && ! str_contains( $html, $tag . '-z-topic-001' ) && 1 === substr_count( $html, '<article class="pc-cb-card"' ) );
	$normalized = Renderer::normalize( 'campaign_pages', array( 'settings' => array( 'source' => 'manual', 'pages' => array( $row( 'page', $topics[100] ) ) ) ) );
	$ok( 'normalization retains the actual topic ID beyond the former option list', $topics[100] === $normalized['settings']['pages'][0]['settings']['page'] );
	$ok( 'empty explicit topic list never falls back to all topics', '' === $render( 'campaign_pages', array( 'source' => 'manual' ) ) );
	$ok( 'deleted and missing topic bindings do not show other published topics', '' === $render( 'campaign_pages', array( 'source' => 'manual', 'pages' => array( $row( 'page', $deleted_topic ), $row( 'page', 2147483000 ) ) ) ) );
	$ok( 'private draft password and wrong-type manual topics are excluded', '' === $render( 'campaign_pages', array( 'source' => 'manual', 'pages' => array( $row( 'page', $topic_private ), $row( 'page', $topic_draft ), $row( 'page', $topic_password ), $row( 'page', $a ) ) ) ) );
	$ok( 'disabled explicit topic list remains empty', '' === $render( 'campaign_pages', array( 'source' => 'manual', 'pages' => array( $row( 'page', $topics[0], false ) ) ) ) );
	$html = $render( 'campaign_pages', array( 'source' => 'manual', 'pages' => array( $row( 'page', $topics[1] ), $row( 'page', $topics[0] ) ), 'limit' => 1 ) );
	$ok( 'manual topic order and item limit both apply', str_contains( $html, $tag . '-z-topic-002' ) && 1 === substr_count( $html, '<article class="pc-cb-card"' ) );
	$html = $render( 'campaign_pages' );
	$ok( 'legacy automatic topic block still shows recent public content with its default limit', 8 === substr_count( $html, '<article class="pc-cb-card"' ) && ! str_contains( $html, $tag . '-topic-private' ) && ! str_contains( $html, $tag . '-topic-password' ) );
	$ok( 'all new public blog and topic renders preserve original orders layouts and settings', $before === $snapshot() );
} finally {
	foreach ( array_reverse( $posts ) as $id ) { if ( get_post( $id ) ) { wp_delete_post( $id, true ); } }
	foreach ( $terms as $id ) { if ( term_exists( $id, 'category' ) ) { wp_delete_term( $id, 'category' ); } }
	wp_set_current_user( $old_user ); remove_filter( 'pre_wp_mail', $no_mail ); Registry::flush();
}
$ok( 'only synthetic content and categories are removed with protected state unchanged', ! array_filter( $posts, static fn( $id ) => (bool) get_post( $id ) ) && ! array_filter( $terms, static fn( $id ) => (bool) term_exists( $id, 'category' ) ) && $before === $snapshot() );
echo "Content block bindings: $pass passed, $fail failed.\n";
if ( $fail ) { WP_CLI::halt( 1 ); }
