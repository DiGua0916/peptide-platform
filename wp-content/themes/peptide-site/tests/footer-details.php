<?php
/** Production footer render/field guards, isolated from WordPress and customer data. */
namespace PeptideCore\Modules {
    class Registry { public static function get( $name ) { return 'subscribe' === $name; } }
    class Renderer { public static function render( $name, $module, $context ) { return '<section data-native-subscribe>' . htmlspecialchars( $module['settings']['title'] ) . '</section>'; } }
}
namespace {
define( 'ABSPATH', __DIR__ );
function add_filter( ...$args ) {}
function add_action( ...$args ) {}
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_url( $value, ...$rest ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function wp_kses_post( $value ) { return strip_tags( $value, '<p><b><strong><br>' ); }
function sanitize_hex_color( $value ) { return preg_match( '/^#[a-f0-9]{6}$/iD', $value ) ? $value : null; }
function home_url( $path ) { return 'https://store.test' . $path; }
function wp_date( $format ) { return '2026'; }
function get_bloginfo( $key ) { return 'VialCove'; }
function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function get_option( $key ) { return $GLOBALS['privacy_id'] ?? 0; }
function wc_get_page_id( $key ) { return $GLOBALS['terms_id'] ?? 0; }
function get_post_status( $id ) { return $GLOBALS['pages'][ $id ]['status'] ?? false; }
function get_post_type( $id ) { return $GLOBALS['pages'][ $id ]['type'] ?? false; }
function get_permalink( $id ) { return $GLOBALS['pages'][ $id ]['url'] ?? ''; }
function peptide_core_get_support_contacts() { return $GLOBALS['support'] ?? array(); }
function vc_brand_logo( $compact ) { echo '<img alt="VialCove" src="logo.svg">'; }
function vc_icon( $name ) { return '<svg aria-hidden="true"></svg>'; }
function vc_menu_link( $item, ...$rest ) { return '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['title'] ) . '</a>'; }
require dirname( __DIR__, 3 ) . '/plugins/peptide-core/includes/modules/class-fields.php';
require dirname( __DIR__ ) . '/inc/demo-site-parts.php';
require dirname( __DIR__ ) . '/inc/storefront-utilities.php';
require_once dirname( __DIR__ ) . '/inc/footer-details.php';
$passed = 0;
function check( $truth, $label ) { global $passed; if ( ! $truth ) { throw new \RuntimeException( $label ); } ++$passed; }
$fields = vc_footer_details_definitions( array( 'site_footer' => array( 'settings' => array() ) ) );
$settings = array_column( $fields['site_footer']['settings'], null, 'key' );
check( 8 === count( $settings ), 'exact native field keys' );
check( false === $settings['footerExpanded']['default'] && true === $settings['footerUseSupport']['default'], 'existing layouts opt in; support enabled only when configured' );
check( 8 === $settings['footerServiceLinks']['max'] && 8 === $settings['footerResourceLinks']['max'], 'repeaters bounded' );
check( 8 === count( vc_footer_details_definitions( $fields )['site_footer']['settings'] ), 'definitions do not duplicate' );
foreach ( array( 'javascript:alert(1)', '//evil.test/x', 'https://user:password@instagram.com/brand', "https://instagram.com/brand\n", 'mailto:invalid', 'mailto:support@example.test?subject=malicious', 'tel:abc' ) as $url ) {
    check( '' === vc_footer_details_url( $url ), 'unsafe URL rejected' );
}
foreach ( array( 'https://instagram.com.evil.test/brand', 'https://fake.test/whatsapp', 'http://instagram.com/brand', 'https://instagram.com:8443/brand', '/#whatsapp', 'https://store.test/' ) as $url ) {
    check( null === vc_footer_details_contact( array( 'label' => 'Instagram', 'url' => $url ) ), 'unknown or misleading host has no brand icon' );
}
foreach ( array( 'https://wa.me/15551234567' => 'whatsapp', 'https://www.instagram.com/vialcove' => 'instagram', 'https://facebook.com/vialcove' => 'facebook', 'https://t.me/vialcove' => 'telegram', 'https://x.com/vialcove' => 'x', 'https://www.youtube.com/@vialcove' => 'youtube', 'https://www.tiktok.com/@vialcove' => 'tiktok', 'https://www.linkedin.com/company/vialcove' => 'linkedin', 'mailto:team@example.test' => 'email', 'tel:+15551234567' => 'phone' ) as $url => $platform ) {
    $contact = vc_footer_details_contact( array( 'url' => $url ) );
    check( $platform === $contact['platform'] && str_contains( vc_footer_details_icon( $platform ), 'aria-hidden="true"' ), 'official host/scheme maps to real icon' );
}
$row = static fn( $label, $url, $enabled = true ) => array( 'enabled' => $enabled, 'settings' => array( 'label' => $label, 'url' => $url ) );
$GLOBALS['support'] = array( 'enabled' => true, 'channels' => array( array( 'type' => 'whatsapp', 'url' => 'https://wa.me/15551234567?text=Hello' ) ) );
$module = array( 'settings' => array( 'brandName' => 'VialCove', 'description' => '<p>A clearer view.</p>', 'copyright' => '© {year} {store_name}', 'demoLinks' => array( $row( 'Collection', '/#vc-collection' ) ), 'footerServiceLinks' => array( $row( 'Delivery details', '/#vc-delivery-details' ), $row( 'Hidden', '/hidden', false ) ), 'footerResourceLinks' => array( $row( 'About us', '/about-us/' ) ), 'showPolicies' => true, 'demoNotice' => 'Existing notice' ), 'items' => array( $row( 'Official Instagram', 'https://instagram.com/vialcove' ), $row( 'Not configured', '' ), $row( 'Invisible FB', 'https://facebook.com/vialcove', false ) ) );
$html = vc_footer_details_render( $module, 'footer' );
check( str_contains( $html, 'vc-footer-details__columns' ) && str_contains( $html, 'Delivery details' ) && str_contains( $html, 'About us' ), 'native editable groups render' );
check( ! str_contains( $html, '>Hidden<' ) && ! str_contains( $html, 'Invisible FB' ) && ! str_contains( $html, 'Not configured' ), 'disabled and empty social/navigation links stay hidden' );
check( 1 === substr_count( $html, 'data-platform="whatsapp"' ) && 1 === substr_count( $html, 'data-platform="instagram"' ), 'only actual accounts render' );
check( ! str_contains( $html, '>Terms<' ) && ! str_contains( $html, '>Privacy policy<' ), 'unconfigured policies have no fallback home link' );
check( str_contains( $html, '© 2025 VialCove' ) && ! str_contains( $html, '© 2026' ) && str_contains( $html, 'Existing notice' ), 'user-selected fixed copyright year and native notice preserved' );
$module['items'][] = $row( 'My WA', 'https://wa.me/15551234567' );
$html = vc_footer_details_render( $module, 'footer' );
check( 1 === substr_count( $html, 'data-platform="whatsapp"' ) && str_contains( $html, 'My WA' ), 'manual contact wins and deduplicates support message query' );
$module['items'][3]['enabled'] = false;
check( ! str_contains( vc_footer_details_render( $module, 'footer' ), 'data-platform="whatsapp"' ), 'explicit same contact disable cannot be resurrected by support merge' );
$module['settings']['footerUseSupport'] = false; $module['items'] = array();
check( ! str_contains( vc_footer_details_render( $module, 'footer' ), 'vc-footer-details__contacts' ), 'turning support reuse off leaves no dummy contact section' );
$GLOBALS['privacy_id'] = 10; $GLOBALS['terms_id'] = 11;
$GLOBALS['pages'] = array( 10 => array( 'status' => 'draft', 'type' => 'page', 'url' => 'https://store.test/privacy/' ), 11 => array( 'status' => 'publish', 'type' => 'page', 'url' => 'https://store.test/' ) );
check( '' === vc_footer_details_policies(), 'draft and homepage policy URLs rejected' );
$GLOBALS['pages'][10]['status'] = 'publish'; $GLOBALS['pages'][11]['url'] = 'https://store.test/terms/';
check( str_contains( vc_footer_details_policies(), '>Terms<' ) && str_contains( vc_footer_details_policies(), '>Privacy policy<' ), 'only real published policy pages render' );
$module['settings']['showSubscribe'] = true; $module['settings']['subscribeTitle'] = 'Native updates';
$module['settings']['mobileNav'] = true; $module['settings']['mobileLinks'] = array( $row( 'Account', '/my-account/' ) );
$html = vc_footer_details_render( $module, 'footer' );
check( str_contains( $html, 'data-native-subscribe' ) && str_contains( $html, 'Native updates' ) && str_contains( $html, 'pc-mobile-fixed' ) && str_contains( $html, '>Account<' ), 'native subscription and optional fixed navigation preserved' );
$module['settings']['footerHelpTitle'] = '<script>alert(1)</script>';
check( ! str_contains( vc_footer_details_render( $module, 'footer' ), '<script>' ), 'editable heading escaped' );
echo "$passed footer checks passed\n";
}
