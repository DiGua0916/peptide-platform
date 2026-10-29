<?php
/** Local synthetic catalog fixtures. No orders, settings changes, mails or published layouts. */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local WP-CLI only.' ); }

use PeptideCore\Modules\Commerce_Blocks as C;
use PeptideCore\Modules\Registry;
use PeptideCore\Modules\Renderer;
use PeptideCore\Promotions;
use PeptideCore\Coupons;

if ( ! has_filter( 'peptide_core_layout_definitions', array( C::class, 'definitions' ) ) ) { C::register_hooks(); }
$pass = 0; $fail = 0; $warnings = array();
$ok = static function ( string $label, bool $yes ) use ( &$pass, &$fail ): void { $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS ' : 'FAIL ' ) . $label . "\n"; };
$run = 'pc-cb-' . substr( wp_generate_uuid4(), 0, 8 ); $posts = array(); $comments = array(); $terms = array();
$old_cookie = $_COOKIE; $old_user = get_current_user_id();
$handler = static function ( $no, $text, $file, $line ) use ( &$warnings ): bool { if ( str_contains( $file, 'class-commerce-blocks.php' ) || str_contains( $file, 'class-subscriptions.php' ) ) { $warnings[] = basename( $file ) . ':' . $line . ' ' . $text; } return false; };
set_error_handler( $handler );
$render = static fn( string $type, array $settings = array(), array $items = array(), string $context = 'public', array $extra = array() ): string => Renderer::render( $type, array_merge( array( 'moduleId' => 'commerce-fixture', 'settings' => $settings, 'items' => $items ), $extra ), $context );
try {
	wp_set_current_user( 0 );
	$term = wp_insert_term( $run, 'product_cat' ); if ( is_wp_error( $term ) ) { throw new RuntimeException( 'Fixture term failed.' ); } $category = (int) $term['term_id']; $terms[] = $category;
	foreach ( array( 'low' => 2, 'high' => 11, 'hidden' => 99 ) as $suffix => $sales ) {
		$p = new WC_Product_Simple(); $p->set_name( $run . '-' . $suffix ); $p->set_regular_price( (string) ( 10 + $sales ) ); $p->set_total_sales( $sales ); $p->set_status( 'hidden' === $suffix ? 'private' : 'publish' ); $p->set_catalog_visibility( 'visible' ); $p->set_category_ids( array( $category ) );
		$attribute = new WC_Product_Attribute(); $attribute->set_name( 'Synthetic size' ); $attribute->set_options( array( $suffix ) ); $attribute->set_visible( true ); $p->set_attributes( array( $attribute ) );
		$posts[] = $p->save(); $products[ $suffix ] = $p->get_id();
	}
	$topic = wp_insert_post( array( 'post_type' => 'pc_topic', 'post_status' => 'publish', 'post_title' => $run . '-topic', 'post_content' => 'Synthetic topic evidence.' ) ); $posts[] = $topic;
	$blog = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $run . '-blog' ) ); $posts[] = $blog;
	$approved = wp_insert_comment( array( 'comment_post_ID' => $products['low'], 'comment_author' => 'Synthetic approved reviewer', 'comment_content' => $run . '-approved', 'comment_type' => 'review', 'comment_approved' => '1' ) ); $comments[] = $approved;
	$pending = wp_insert_comment( array( 'comment_post_ID' => $products['low'], 'comment_author' => 'Synthetic pending reviewer', 'comment_content' => $run . '-pending', 'comment_type' => 'review', 'comment_approved' => '0' ) ); $comments[] = $pending;
	update_comment_meta( $approved, 'rating', 4 ); update_comment_meta( $approved, C::REVIEW_FEATURED, '1' );
	$image = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => $run . '-photo', 'guid' => home_url( '/synthetic-review-photo.png' ) ) ); $posts[] = $image;
	update_post_meta( $image, '_wp_attached_file', 'pc-synthetic/' . $run . '.png' );
	update_post_meta( $image, '_wp_attachment_metadata', array( 'width' => 1, 'height' => 1, 'file' => 'pc-synthetic/' . $run . '.png', 'sizes' => array() ) );
	$video = wp_insert_attachment( array( 'post_mime_type' => 'video/mp4', 'post_title' => $run . '-video', 'guid' => home_url( '/synthetic-review-video.mp4' ) ) ); $posts[] = $video;
	update_comment_meta( $approved, C::REVIEW_IMAGES, array( $image, $video, $products['low'] ) ); update_comment_meta( $approved, C::REVIEW_VIDEO, $video );
	$promo = wp_insert_post( array( 'post_type' => Promotions::CPT, 'post_status' => 'publish', 'post_title' => $run . '-promo' ) ); $posts[] = $promo;
	$config = Promotions::clean( array( 'type' => 'spend_amount', 'public' => true, 'text' => 'Synthetic real rule', 'scope' => 'products', 'products' => array( $products['low'] ), 'tiers' => array( array( 'min' => 100, 'value' => 5 ) ), 'end' => time() + HOUR_IN_SECONDS ) )[0]; update_post_meta( $promo, Promotions::META, $config );
	$coupon = new WC_Coupon(); $coupon->set_code( $run . '-coupon' ); $coupon->set_discount_type( 'fixed_cart' ); $coupon->set_amount( 5 ); $posts[] = $coupon->save(); update_post_meta( $coupon->get_id(), Coupons::PUBLIC_META, 'yes' ); update_post_meta( $coupon->get_id(), Coupons::TITLE_META, $run . '-coupon-title' );
	Registry::flush(); $defs = Registry::all();
	$new = C::definitions( array() );
	$ok( '34 commerce modules including real product offers registered with core device/general fields', 34 === count( $new ) && isset( $defs['product_offer'] ) && ! array_diff_key( $new, $defs ) && in_array( 'paddingTopPx', array_column( $defs['product_carousel']['settings'], 'key' ), true ) );
	$bad = array(); foreach ( $new as $type => $def ) { $preview = $render( $type, array(), array(), 'editor' ); if ( ! str_contains( $preview, 'vc-m--editor' ) ) { $bad[] = $type; } }
	$ok( 'all commerce default configurations render in the editor without errors', ! $bad );
	$source = array( 'source' => 'category', 'category' => $category, 'limit' => 2 );
	$rail = $render( 'product_carousel', $source );
	$ok( 'carousel uses published real products and working controls', str_contains( $rail, $run . '-low' ) && str_contains( $rail, $run . '-high' ) && ! str_contains( $rail, $run . '-hidden' ) && str_contains( $rail, 'data-pc-cb-prev' ) && str_contains( $rail, 'data-pc-cb-next' ) );
	$ranked = C::products( $source, true );
	$ok( 'sales ranking is bounded and sorts stored actual totals', 2 === count( $ranked ) && $products['high'] === $ranked[0]->get_id() && 11 === $ranked[0]->get_total_sales() );
	$compare = $render( 'product_comparison', $source );
	$ok( 'comparison renders real price and visible product attributes in table', str_contains( $compare, '<table' ) && str_contains( $compare, 'Synthetic size' ) && str_contains( $compare, $run . '-low' ) );
	$manual = array( array( 'id' => 'row-one', 'settings' => array( 'product' => $products['low'] ) ) );
	$tabs = $render( 'product_tabs', array(), array( array( 'id' => 'tab-one', 'settings' => array( 'title' => 'One', 'source' => 'manual', 'products' => $manual ) ), array( 'id' => 'tab-two', 'settings' => array( 'title' => 'Two', 'source' => 'category', 'category' => $category ) ) ) );
	$ok( 'product tabs render accessible linked controls and hidden second panel', 2 === substr_count( $tabs, 'role="tab"' ) && 2 === substr_count( $tabs, 'role="tabpanel"' ) && str_contains( $tabs, ' hidden' ) && str_contains( $tabs, $run . '-low' ) );
	$category_tabs = $render( 'category_tabs', array(), array( array( 'id' => 'tab-category', 'settings' => array( 'title' => 'Category', 'categories' => array( array( 'id' => 'cat-one', 'settings' => array( 'category' => $category ) ) ) ) ) ) );
	$ok( 'category tab uses the selected real collection', str_contains( $category_tabs, esc_url( get_term_link( $category, 'product_cat' ) ) ) );
	$topic_tabs = $render( 'topic_tabs', array(), array( array( 'id' => 'tab-topic', 'settings' => array( 'title' => 'Topic', 'pages' => array( array( 'id' => 'topic-one', 'settings' => array( 'page' => (string) $topic ) ) ) ) ) ) );
	$ok( 'topic tabs render selected published campaign pages', str_contains( $topic_tabs, $run . '-topic' ) );
	unset( $_COOKIE[ C::RECENT_COOKIE ] ); $ok( 'recent history without visits hides public block', '' === $render( 'recent_products' ) );
	$_COOKIE[ C::RECENT_COOKIE ] = $products['high'] . ',' . $products['hidden'] . ',' . $products['low'] . ',' . $products['high'];
	$recent = $render( 'recent_products', array(), array(), 'public', array( 'mobile' => array( 'settings' => array( 'title' => 'Mobile history' ) ) ) );
	$ok( 'recent history respects browser order and excludes private products', strpos( $recent, $run . '-high' ) < strpos( $recent, $run . '-low' ) && ! str_contains( $recent, $run . '-hidden' ) && 1 === substr_count( $recent, 'data-pc-commerce="recent_products"' ) );
	$review = $render( 'featured_reviews' ); $photo_review = $render( 'image_reviews' ); $video_review = $render( 'video_reviews' );
	$ok( 'review blocks show real approved comments only', str_contains( $review, $run . '-approved' ) && ! str_contains( $review, $run . '-pending' ) );
	$media_checks = array( 'images' => array( $image ) === C::review_image_ids( $approved ), 'video' => $video === C::review_video_id( $approved ), 'photo_block' => str_contains( $photo_review, $run . '-approved' ), 'video_block' => str_contains( $video_review, '<video' ) );
	$ok( 'review media validates image/video MIME and includes only actual metadata ' . wp_json_encode( $media_checks ), ! in_array( false, $media_checks, true ) );
	$timed = $render( 'promotion_card', array( 'promotion' => (string) $promo ) );
	$ok( 'timed card embeds existing promotion rule and real deadline', str_contains( $timed, $run . '-promo' ) && str_contains( $timed, 'data-pc-cb-countdown' ) && str_contains( $timed, esc_attr( gmdate( 'c', $config['end'] ) ) ) );
	$config['end'] = time() - 60; update_post_meta( $promo, Promotions::META, $config );
	$ok( 'ended promotion hides card instead of inventing a new deadline', '' === $render( 'promotion_card', array( 'promotion' => (string) $promo ) ) );
	$coupons = $render( 'coupon_list' ); $ok( 'coupon block uses real public coupon and copy control', str_contains( $coupons, $coupon->get_code() ) && str_contains( $coupons, 'data-pc-cb-copy' ) );
	$coupon->set_email_restrictions( array( 'synthetic-restricted@example.invalid' ) ); $coupon->save(); $ok( 'email-restricted coupon is excluded from public block', ! str_contains( $render( 'coupon_list' ), $coupon->get_code() ) );
	$subscribe = $render( 'subscribe', array(), array(), 'public', array( 'mobile' => array( 'settings' => array( 'title' => 'Mobile subscribe' ) ) ) );
	$ok( 'subscription form is rendered once despite mobile override', 1 === substr_count( $subscribe, 'data-pc-subscribe ' ) && 0 === substr_count( $render( 'subscribe', array(), array(), 'editor' ), 'data-pc-subscribe ' ) );
	$ok( 'unconfigured announcements and social links never invent store data', '' === $render( 'announcement' ) && '' === $render( 'social_links' ) );
	$ok( 'new code emits no PHP warnings', ! $warnings ); if ( $warnings ) { echo implode( "\n", $warnings ) . "\n"; }
} finally {
	foreach ( $comments as $id ) { wp_delete_comment( $id, true ); }
	foreach ( array_reverse( $posts ) as $id ) { if ( 'product' === get_post_type( $id ) ) { $p = wc_get_product( $id ); if ( $p ) { $p->delete( true ); } } else { wp_delete_post( $id, true ); } }
	foreach ( $terms as $id ) { wp_delete_term( $id, 'product_cat' ); }
	$_COOKIE = $old_cookie; wp_set_current_user( $old_user ); Registry::flush(); restore_error_handler();
}
echo "Commerce blocks: $pass passed, $fail failed.\n";
if ( $fail ) { WP_CLI::halt( 1 ); }
