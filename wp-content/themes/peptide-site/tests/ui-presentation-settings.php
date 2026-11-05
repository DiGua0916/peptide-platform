<?php
/** Native schema compatibility checks: no WordPress, database or customer state. */
define( 'ABSPATH', __DIR__ );
function add_filter( ...$args ) {}
require dirname( __DIR__, 3 ) . '/plugins/peptide-core/includes/modules/class-fields.php';
require dirname( __DIR__ ) . '/inc/ui-presentation-settings.php';
$passed = 0;
function check( $condition, $label ) { global $passed; if ( ! $condition ) { throw new RuntimeException( $label ); } ++$passed; }
$f = static fn( $key, $extra = array() ) => array_merge( array( 'key' => $key, 'type' => 'text', 'default' => 'keep-' . $key ), $extra );
$general = array_map( $f, array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) );
$original = array(
    'site_footer' => array( 'settings' => array_merge( array_map( $f, array( 'presentation', 'footerExpanded', 'logo', 'logoWidth', 'brandName', 'description', 'footerShopTitle', 'menuId', 'demoLinks', 'footerHelpTitle', 'footerServiceLinks', 'footerResourcesTitle', 'footerResourceLinks', 'footerContactTitle', 'footerUseSupport', 'demoNotice', 'copyright', 'showPolicies', 'showSubscribe', 'subscribeTitle', 'mobileNav', 'mobileNavSize', 'mobileNavHeight', 'mobileNavIconSize', 'mobileNavFontSize', 'mobileLinks', 'footerBackground', 'footerText', 'peerFutureSetting' ) ), $general ), 'items' => array( 'fields' => array_map( $f, array( 'label', 'url', 'icon' ) ), 'max' => 12, 'min' => 0 ), 'panels' => array( array( 'id' => 'peer-footer', 'fields' => array( 'peerFutureSetting' ), 'label' => 'Keep peer panel' ) ) ),
    'product_collection' => array( 'settings' => array( $f( 'cardPresentation', array( 'type' => 'select', 'options' => array( 'standard' => 'Original', 'demo' => 'Editorial' ) ) ), $f( 'source' ) ), 'panels' => array( array( 'id' => 'original-catalog', 'fields' => array( 'cardPresentation', 'source' ) ) ) ),
    'product_experience' => array( 'settings' => array_merge( array_map( $f, array( 'mobileDialog', 'reportGalleryEnabled', 'stickyPurchaseEnabled', 'broadcastEnabled', 'whatsappEnabled', 'whatsappNumber', 'whatsappLabel', 'whatsappMessage' ) ), $general ) ),
    'context_content' => array( 'settings' => array_merge( array_map( $f, array( 'cart_experience_enabled', 'cart_progress_enabled', 'cart_recommendations_enabled', 'cart_recent_enabled', 'cart_title', 'cart_continue_label' ) ), $general ) ),
    'untouched-peer-module' => array( 'settings' => array( $f( 'amount' ) ), 'panels' => array( array( 'id' => 'keep-this' ) ) ),
);
$next = vc_ui_presentation_definitions( $original );
check( $original['untouched-peer-module'] === $next['untouched-peer-module'], 'unrelated native module is byte-equivalent' );
foreach ( $original as $type => $def ) {
    $by_key = array_column( $next[ $type ]['settings'], null, 'key' );
    foreach ( $def['settings'] as $before ) { check( $before === $by_key[ $before['key'] ], 'existing setting and defaults remain authoritative' ); }
}
check( $next === vc_ui_presentation_definitions( $next ), 'repeated schema filters are idempotent' );
check( 12 === $next['site_footer']['items']['max'] && 0 === $next['site_footer']['items']['min'], 'native contact row limits remain' );
check( array_column( $original['site_footer']['items']['fields'], 'key' ) === array_column( $next['site_footer']['items']['fields'], 'key' ), 'all contact row fields retained including standard footer icon' );
check( 'peer-footer' === end( $next['site_footer']['panels'] )['id'], 'peer panel retained after familiar task groups' );
foreach ( array( 'site_footer', 'product_experience', 'context_content' ) as $type ) {
    $assigned = array_merge( ...array_column( $next[ $type ]['panels'], 'fields' ) );
    foreach ( array_column( $next[ $type ]['settings'], 'key' ) as $key ) { check( in_array( $key, $assigned, true ), 'presentation fields reachable from native panels' ); }
}
$quick = array_column( $next['product_collection']['settings'], null, 'key' )['catalogQuickAdd'];
$subtotal = array_column( $next['context_content']['settings'], null, 'key' )['checkout_subtotal_savings_enabled'];
check( 'toggle' === $quick['type'] && true === $quick['default'] && array( 'cardPresentation' => 'demo' ) === $quick['show_if'], 'quick add default preserves the existing editorial catalog' );
check( 'toggle' === $subtotal['type'] && true === $subtotal['default'], 'checkout control preserves the existing subtotal presentation' );
check( false === PeptideCore\Modules\Fields::sanitize( $quick, false ) && false === PeptideCore\Modules\Fields::sanitize( $subtotal, false ), 'explicit merchant disable survives native sanitization' );
check( ! isset( $next['cart-price-rules'] ) && count( $next ) === count( $original ), 'no parallel pricing or cart module introduced' );
echo "$passed native presentation schema checks passed\n";
