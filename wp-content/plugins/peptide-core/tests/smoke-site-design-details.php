<?php
/** Local design-details regression: only new unassigned templates, menus and sessions. */
defined( 'ABSPATH' ) || exit;
use PeptideCore\Templates;
use PeptideCore\Design_Pages;
use PeptideCore\Api\Layout_Controller;
use PeptideCore\Modules\Layout;
use PeptideCore\Modules\Renderer;
use PeptideCore\Modules\Site_Design;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { throw new RuntimeException( 'Local development CLI only.' ); }
$pass = 0; $fail = 0;
$ok = static function ( string $label, bool $yes ) use ( &$pass, &$fail ): void { $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS ' : 'FAIL ' ) . $label . "\n"; };
$call = static function ( string $method, int $id, string $suffix = '', array $body = array() ) { $r = new WP_REST_Request( $method, '/peptide/v1/layouts/' . $id . $suffix ); $r->set_header( 'content-type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); return rest_do_request( $r ); };
$save = static function ( int $id, array $modules ) use ( $call ) { $d = $call( 'GET', $id )->get_data(); return $call( 'POST', $id, '', array( 'modules' => $modules, 'revision' => $d['revision'] ) ); };
$publish = static function ( int $id ) use ( $call ) { $d = $call( 'GET', $id )->get_data(); return $call( 'POST', $id, '/publish', array( 'revision' => $d['revision'] ) ); };
$protected = static function (): string {
    $active = get_option( Templates::ACTIVE_OPTION ); $docs = array();
    foreach ( get_children( array( 'post_parent' => (int) $active, 'post_type' => Templates::LAYOUT, 'post_status' => 'any' ) ) as $post ) { clean_post_cache( $post->ID ); $meta = array_filter( get_post_meta( $post->ID ), static fn( $key ) => str_starts_with( (string) $key, '_pc_' ), ARRAY_FILTER_USE_KEY ); $docs[ $post->ID ] = array( get_post_field( 'post_content', $post->ID ), $meta ); }
    $orders = array(); foreach ( array( 54, 55 ) as $id ) { $order = wc_get_order( $id ); $orders[ $id ] = $order ? $order->get_data() : null; }
    return hash( 'sha256', wp_json_encode( array( $active, get_option( Templates::DOMAIN_OPTION ), $docs, $orders ) ) );
};
$before = $protected(); $old_user = get_current_user_id(); $old_get = $_GET; $old_cookie = $_COOKIE;
$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
if ( ! $admin ) { throw new RuntimeException( 'Administrator required.' ); }
$template = 0; $menu = 0; $token = ''; $docs = array();
try {
    wp_set_current_user( $admin ); $_GET = array();
    $normal_settings = Site_Design::settings();
    $defaults = Renderer::normalize( 'site_styles', array() )['settings'];
    $ok( 'legacy global font remains the default and independent fonts inherit', 'inherit' === $defaults['fontFamily'] && 'inherit' === $defaults['headingFontFamily'] && 'inherit' === $defaults['bodyFontFamily'] );
    $ok( 'unconfigured weights, tracking and detail title preserve theme values', 'inherit' === $defaults['headingWeight'] && 'inherit' === $defaults['bodyWeight'] && null === $defaults['headingLetterSpacing']['desktop'] && 0 === $defaults['detailTitleSize']['desktop'] );
    $ok( 'default side spacing preserves the original 48px total gutter', 24 === $defaults['pageGutter']['desktop'] );
    $ok( 'page transitions are opt-in and review display defaults preserve existing blocks', ! $defaults['transitionEnabled'] && 'inherit' === $defaults['reviewLayout'] && ! $defaults['reviewAutoplay'] && 0 === $defaults['reviewLimit'] && 'original' === $defaults['reviewVideoRatio'] );
    $invalid = Renderer::normalize( 'site_styles', array( 'settings' => array( 'bodyFontFamily' => 'url(https://example.invalid)', 'headingWeight' => '999; color:red', 'headingLetterSpacing' => array( 'desktop' => 99, 'mobile' => -100 ), 'pageGutter' => array( 'desktop' => -50, 'mobile' => 999 ), 'reviewMinimumRating' => 999, 'reviewVideoRatio' => '1/1;position:fixed', 'transitionDuration' => '10000' ) ) )['settings'];
    $ok( 'font, weight, ratio and transition timing reject unallowlisted values', 'inherit' === $invalid['bodyFontFamily'] && 'inherit' === $invalid['headingWeight'] && 'original' === $invalid['reviewVideoRatio'] && '500' === $invalid['transitionDuration'] );
    $ok( 'numeric typography and spacing values clamp to safe ranges', 12.0 === $invalid['headingLetterSpacing']['desktop'] && -2.0 === $invalid['headingLetterSpacing']['mobile'] && 0 === $invalid['pageGutter']['desktop'] && 160 === $invalid['pageGutter']['mobile'] && 5 === $invalid['reviewMinimumRating'] );
    $tag = 'pcdetails-' . substr( wp_generate_uuid4(), 0, 8 );
    $template = Templates::create( $tag, 'Synthetic design-details regression', $admin );
    foreach ( array( 'home', 'header', 'footer', 'global' ) as $key ) { $docs[ $key ] = Templates::document_id( $template, $key, false ); }
    $ok( 'fixture is a new unassigned template', $template > 0 && $template !== Templates::default_id( false ) && ! in_array( $template, Templates::domain_map(), true ) );
    $settings = array( 'fontFamily' => 'inherit', 'bodyFontFamily' => 'Manrope', 'headingFontFamily' => 'Geist', 'bodyWeight' => '400', 'headingWeight' => '700', 'headingLetterSpacing' => array( 'desktop' => 0.3, 'mobile' => 0.5 ), 'menuLetterSpacing' => array( 'desktop' => 1.1, 'mobile' => 0.7 ), 'detailTitleSize' => array( 'desktop' => 42, 'mobile' => 32 ), 'pageGutter' => array( 'desktop' => 48, 'mobile' => 16 ), 'transitionEnabled' => true, 'transitionDuration' => '700', 'reviewLayout' => 'carousel', 'reviewAutoplay' => true, 'reviewInterval' => 4000, 'reviewOrder' => 'rating_desc', 'reviewMinimumRating' => 3, 'reviewShowRating' => false, 'reviewLimit' => 7, 'reviewVideoRatio' => '9/16' );
    $global = array( 'block' => 'vialcove/site-styles', 'attrs' => array( 'settings' => $settings ) );
    $ok( 'global details save through the real REST route', 200 === $save( $docs['global'], array( $global ) )->get_status() );
    $ok( 'saving global details does not publish them', ! Layout::is_published( $docs['global'] ) && array() === Layout::published_modules( 'global', $template ) );
    $expires = time() + 600; $token = WP_Session_Tokens::get_instance( $admin )->create( $expires );
    $auth = wp_generate_auth_cookie( $admin, $expires, 'auth', $token ); $login = wp_generate_auth_cookie( $admin, $expires, 'logged_in', $token ); $_COOKIE[ LOGGED_IN_COOKIE ] = $login;
    $cookies = array( new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => $auth ) ), new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => $login ) ) );
    $preview = Design_Pages::preview_url( $template, 'home' ); parse_str( (string) wp_parse_url( $preview, PHP_URL_QUERY ), $args ); $_GET = $args;
    $s = Site_Design::settings();
    $ok( 'signed preview reads independent draft fonts and mobile sizing', 'Manrope' === $s['bodyFontFamily'] && 'Geist' === $s['headingFontFamily'] && 32 === $s['detailTitleSize']['mobile'] && 16 === $s['pageGutter']['mobile'] );
    $review = Site_Design::review_settings();
    $ok( 'review accessor supplies stable sanitized display keys', array( 'layout' => 'carousel', 'autoplay' => true, 'interval' => 4000, 'order' => 'rating_desc', 'minimum_rating' => 3, 'show_rating' => false, 'limit' => 7, 'video_ratio' => '9/16' ) === $review );
    ob_start(); Site_Design::styles(); $css = (string) ob_get_clean();
    $ok( 'CSS includes distinct body and heading families and weights', str_contains( $css, 'font-family:"Manrope",sans-serif;font-weight:400' ) && str_contains( $css, 'font-family:"Geist",sans-serif;font-weight:700' ) );
    $ok( 'CSS includes desktop and mobile gutters and fractional tracking', str_contains( $css, '--pc-pageGutter:48px' ) && str_contains( $css, '--pc-pageGutter:16px' ) && str_contains( $css, 'letter-spacing:0.3px' ) && str_contains( $css, 'letter-spacing:0.5px' ) );
    $ok( 'detail title size and review video ratio are isolated selectors', str_contains( $css, '.single-product' ) && str_contains( $css, 'font-size:42px' ) && str_contains( $css, 'font-size:32px' ) && str_contains( $css, 'aspect-ratio:9/16' ) );
    Site_Design::assets();
    $ok( 'independent local fonts and design-details script are enqueued', wp_style_is( 'peptide-site-fonts', 'enqueued' ) && wp_script_is( 'peptide-site-design-details', 'enqueued' ) );
    $ok( 'transition navigation stays disabled inside the signed editor preview', str_contains( implode( '', (array) wp_scripts()->get_data( 'peptide-site-design-details', 'before' ) ), '"transition":false' ) );
    $_GET = array();
    $ok( 'a draft in an unassigned template leaves normal public global settings unchanged', $normal_settings === Site_Design::settings() );
    $ok( 'global details publish into their own snapshot', 200 === $publish( $docs['global'] )->get_status() && Layout::is_published( $docs['global'] ) );
    $changed = $global; $changed['attrs']['settings']['bodyFontFamily'] = 'Poppins'; $save( $docs['global'], array( $changed ) );
    $published = Layout::published_modules( 'global', $template );
    $ok( 'later font edits do not overwrite the published snapshot', 'Manrope' === Renderer::normalize( 'site_styles', $published[0]['attrs'] )['settings']['bodyFontFamily'] );
    // Put the original settings back in the fixture draft for the HTTP check.
    $save( $docs['global'], array( $global ) );
    $menu = wp_create_nav_menu( $tag . '-secondary' ); if ( is_wp_error( $menu ) ) { throw new RuntimeException( 'Menu fixture failed.' ); }
    wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => $tag . '-secondary-link', 'menu-item-type' => 'custom', 'menu-item-url' => home_url( '/shop/' ), 'menu-item-status' => 'publish' ) );
    $header = array( 'block' => 'vialcove/site-header', 'attrs' => array( 'settings' => array( 'secondaryMenuId' => $menu ) ) );
    $footer = array( 'block' => 'vialcove/site-footer', 'attrs' => array( 'settings' => array( 'showPolicies' => false, 'mobileNav' => true, 'mobileNavHeight' => 120, 'mobileNavIconSize' => 32, 'mobileNavFontSize' => 18, 'mobileLinks' => array( array( 'settings' => array( 'label' => 'Synthetic home', 'url' => home_url( '/' ), 'icon' => 'box' ) ) ) ) ) );
    foreach ( array( 'header' => $header, 'footer' => $footer ) as $key => $row ) { $save( $docs[ $key ], array( $row ) ); $publish( $docs[ $key ] ); }
    $home = array( 'block' => 'vialcove/rich-text', 'attrs' => array( 'settings' => array( 'bodyHtml' => '<p>' . $tag . '-body</p>' ) ) ); $save( $docs['home'], array( $home ) ); $publish( $docs['home'] );
    $response = wp_remote_get( $preview, array( 'timeout' => 30, 'redirection' => 0, 'cookies' => $cookies ) ); $body = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
    $ok( 'real signed HTTP preview renders the temporary page', ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) && str_contains( $body, $tag . '-body' ) );
    $ok( 'secondary navigation resolves only the selected menu and has no duplicate primary id', str_contains( $body, $tag . '-secondary-link' ) && 1 === substr_count( $body, 'class="pc-secondary-nav container"' ) && 1 >= substr_count( $body, 'id="primary-nav"' ) );
    $ok( 'mobile navigation dimensions remain within the footer component', str_contains( $body, '--pc-mobile-nav-height:120px' ) && str_contains( $body, '--pc-mobile-nav-icon:32px' ) && str_contains( $body, '--pc-mobile-nav-font:18px' ) );
    $ok( 'HTTP stylesheet and JS use only published or scoped preview values', str_contains( $body, '--pc-pageGutter:16px' ) && str_contains( $body, 'site-design-details.js' ) && str_contains( $body, 'font-family:"Geist",sans-serif' ) && ! str_contains( $body, 'Fatal error' ) );
    $guest = wp_remote_get( home_url( '/' ), array( 'timeout' => 30 ) ); $guest_body = is_wp_error( $guest ) ? '' : wp_remote_retrieve_body( $guest );
    $ok( 'anonymous storefront does not receive the unassigned layout or secondary menu', ! str_contains( $guest_body, $tag ) );
    $ok( 'original orders, active template and complete real layout meta fingerprints are unchanged', $before === $protected() );
} finally {
    wp_set_current_user( $admin ); $_GET = array();
    if ( $template ) { foreach ( get_children( array( 'post_parent' => $template, 'post_type' => Templates::LAYOUT, 'post_status' => 'any', 'fields' => 'ids' ) ) as $doc ) { foreach ( wp_get_post_revisions( $doc, array( 'fields' => 'ids' ) ) as $rev ) { wp_delete_post_revision( $rev ); } wp_delete_post( $doc, true ); } wp_delete_post( $template, true ); }
    if ( $menu && ! is_wp_error( $menu ) ) { wp_delete_nav_menu( $menu ); }
    if ( $token ) { WP_Session_Tokens::get_instance( $admin )->destroy( $token ); }
    $_GET = $old_get; $_COOKIE = $old_cookie; wp_set_current_user( $old_user );
}
$ok( 'fixture template, documents, menu and session are cleaned up', ! get_post( $template ) && ! array_filter( $docs, static fn( $id ) => (bool) get_post( $id ) ) && ! wp_get_nav_menu_object( $menu ) && ! WP_Session_Tokens::get_instance( $admin )->verify( $token ) );
$ok( 'protected data remains unchanged after cleanup', $before === $protected() );
echo "RESULT {$pass} PASS / {$fail} FAIL\n";
if ( $fail ) { throw new RuntimeException( 'Site-design details regression failed.' ); }
