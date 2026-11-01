<?php
/** Standalone rendering checks; no WordPress database or fixture writes. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	class WP_Term {
		public $term_id = 353;
		public $taxonomy = 'product_cat';
		public $name = 'Collection fallback';
		public $description = '<p>Fallback collection description</p>';
	}
	class WP_Error {}
	$state = array( 'tax' => 'product_cat', 'term' => new WP_Term(), 'meta' => array(), 'paged' => 1, 'missing' => false, 'attachment' => 'publish', 'link_error' => false );
	function is_front_page() { return false; }
	function is_singular( $types ) { return false; }
	function is_category() { return false; }
	function is_tax( $taxonomy ) { return $GLOBALS['state']['tax'] === $taxonomy; }
	function is_404() { return $GLOBALS['state']['missing']; }
	function get_queried_object() { return $GLOBALS['state']['term']; }
	function get_queried_object_id() { return $GLOBALS['state']['term']->term_id ?? 0; }
	function get_term_meta( $id, $key, $single ) { return $GLOBALS['state']['meta'][ $key ] ?? ''; }
	function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
	function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
	function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
	function strip_shortcodes( $value ) { return preg_replace( '/\[[^\]]*\]/', '', $value ); }
	function wp_unslash( $value ) { return stripslashes( $value ); }
	function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
	function home_url( $path = '' ) { return 'https://store.example' . $path; }
	function get_term_link( $term ) { return $GLOBALS['state']['link_error'] ? new WP_Error() : 'https://store.example/product-category/collection/'; }
	function get_query_var( $name ) { return 'paged' === $name ? $GLOBALS['state']['paged'] : ''; }
	function get_pagenum_link( $page, $escape = true ) { return 'https://store.example/product-category/collection/page/' . $page . '/'; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_get_document_title() { return 'Native collection title'; }
	function get_bloginfo( $key ) { return 'VialCove'; }
	function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_url( $value ) { return esc_attr( $value ); }
	function absint( $value ) { return abs( (int) $value ); }
	function wp_attachment_is_image( $id ) { return 77 === $id; }
	function get_post_status( $id ) { return $GLOBALS['state']['attachment']; }
	function wp_get_attachment_image_url( $id, $size ) { return 'https://store.example/uploads/collection.jpg'; }
}
namespace PeptideCore {
	class Blog { public const META_SEO = '_pc_seo'; }
	class Topics { public const POST_TYPE = 'pc_topic'; }
	class Templates { public static function normalize_host( $host ) { return $host; } }
}
namespace {
	require_once dirname( __DIR__ ) . '/includes/class-seo.php';
	use PeptideCore\Seo;
	$count = 0;
	$check = static function ( bool $condition, string $label ) use ( &$count ): void {
		if ( ! $condition ) { throw new \RuntimeException( $label ); }
		++$count;
	};
	$head = static function (): string { ob_start(); Seo::head(); return (string) ob_get_clean(); };
	$set = Seo::current();
	$check( 'Collection fallback' === $set['title'] && 'Fallback collection description' === $set['description'], 'Empty SEO falls back to the actual collection name and plain description.' );
	$state['meta']['_pc_seo'] = array( 'title' => 'Collection "own"', 'description' => 'Own description', 'keywords' => array( 'one', 'ONE', 'two' ) );
	$state['meta']['thumbnail_id'] = 77;
	$set = Seo::current();
	$check( 'Collection "own"' === $set['title'] && array( 'one', 'two' ) === $set['keywords'], 'Own metadata uses the shared cleaner.' );
	$check( 'Collection "own"' === Seo::document_title( 'Old' ), 'The collection SEO title reaches the document-title filter.' );
	$html = $head();
	$check( 1 === substr_count( $html, 'rel="canonical"' ) && str_contains( $html, 'href="https://store.example/product-category/collection/"' ), 'The collection has one taxonomy canonical.' );
	$check( str_contains( $html, 'property="og:url" content="https://store.example/product-category/collection/"' ), 'OG URL uses the same collection URL.' );
	$check( 1 === substr_count( $html, 'name="description"' ) && str_contains( $html, 'content="Own description"' ), 'The shared head emits one description.' );
	$check( str_contains( $html, 'content="Collection &quot;own&quot;"' ) && str_contains( $html, 'name="keywords" content="one, two"' ), 'Text is escaped and keywords are shared.' );
	$check( str_contains( $html, 'property="og:type" content="website"' ) && str_contains( $html, 'name="twitter:card" content="summary_large_image"' ) && str_contains( $html, 'property="og:image"' ), 'A public collection image reaches the existing social metadata.' );
	$check( ! str_contains( $html, 'robots' ), 'SEO metadata does not override existing indexing policy.' );
	$state['paged'] = 3;
	$html = $head();
	$check( 2 === substr_count( $html, 'https://store.example/product-category/collection/page/3/' ) && ! str_contains( $html, 'href="https://store.example/product-category/collection/"' ), 'Paged canonical and OG URL refer to the actual page, not page one.' );
	foreach ( array( 'draft', 'private', 'trash' ) as $status ) {
		$state['attachment'] = $status;
		$html = $head();
		$check( ! str_contains( $html, 'property="og:image"' ) && str_contains( $html, 'name="twitter:card" content="summary"' ), 'Nonpublic image parent is not disclosed: ' . $status );
	}
	$state['link_error'] = true;
	$check( ! str_contains( $head(), 'rel="canonical"' ), 'A failed term URL does not produce an invented canonical.' );
	$state['missing'] = true;
	$check( null === Seo::current() && '' === $head(), 'A missing archive does not expose its metadata.' );
	$state['missing'] = false;
	$state['term']->taxonomy = 'category';
	$check( null === Seo::current() && '' === $head(), 'A taxonomy mismatch does not expose collection metadata.' );
	$state['tax'] = 'product_tag';
	$check( null === Seo::current() && 'Native title' === Seo::document_title( 'Native title' ) && '' === $head(), 'Unrelated taxonomy behavior is unchanged.' );
	echo 'PASS ', $count, " product collection SEO rendering checks; no database opened.\n";
}
