<?php
/** Pure presentation guards. No WP database/session/cart writes. */
define( 'ABSPATH', __DIR__ );
function add_action( ...$args ) {}
function is_front_page() { return $GLOBALS['home'] ?? true; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function sanitize_hex_color( $value ) { return preg_match( '/^#[a-f0-9]{6}$/iD', $value ) ? $value : null; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function wp_kses_post( $value ) { return $value; }
function peptide_core_get_campaign_announcement( $settings, $home ) { return $GLOBALS['campaign'] ?? array(); }
require dirname( __DIR__ ) . '/inc/storefront-utilities.php';
$passed = 0;
function check( $value, $message ) { global $passed; if ( ! $value ) { throw new RuntimeException( $message ); } $passed++; }
$url = 'https://wa.me/15551234567?text=Hello';
check( vc_storefront_whatsapp_url( array( 'enabled' => true, 'channels' => array( array( 'type' => 'whatsapp', 'url' => $url ) ) ) ) === $url, 'actual WhatsApp link' );
foreach ( array( 'http://wa.me/15551234567', 'https://wa.me.evil.test/15551234567', 'https://user@wa.me/15551234567', 'https://wa.me:444/15551234567', 'https://wa.me/not-configured', 'javascript:alert(1)' ) as $bad ) {
    check( '' === vc_storefront_whatsapp_url( array( 'enabled' => true, 'channels' => array( array( 'type' => 'whatsapp', 'url' => $bad ) ) ) ), 'reject invalid contact' );
}
check( '' === vc_storefront_whatsapp_url( array( 'enabled' => false, 'channels' => array( array( 'type' => 'whatsapp', 'url' => $url ) ) ) ), 'disabled hides' );
$settings = array( 'announcement' => true, 'announcementSource' => 'promotion', 'announcementPromotion' => 42 );
check( '' === vc_storefront_announcement_html( $settings ), 'no binding hides rather than inventing a campaign' );
$GLOBALS['campaign'] = array( 'visible' => true, 'source' => 'promotion', 'promotionId' => 42, 'status' => 'active', 'html' => '<strong>Existing offer</strong><span> Native tiers</span>' );
check( str_contains( vc_storefront_announcement_html( $settings ), 'Existing offer' ), 'published DTO renders' );
foreach ( array( array( 'promotionId' => 43 ), array( 'status' => 'expired' ), array( 'visible' => false ), array( 'source' => 'manual' ) ) as $bad ) {
    $saved = $GLOBALS['campaign']; $GLOBALS['campaign'] = array_merge( $saved, $bad );
    check( '' === vc_storefront_announcement_html( $settings ), 'reject stale/wrong activity' ); $GLOBALS['campaign'] = $saved;
}
$GLOBALS['home'] = false;
check( '' === vc_storefront_announcement_html( $settings + array( 'announcementHomeOnly' => true ) ), 'home only respected' );
echo "$passed presentation guards passed\n";
