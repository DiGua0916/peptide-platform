<?php
/**
 * NF-07 (theme side): a lighter first paint on the storefront home page.
 *
 * Per-store switch, off by default: option pc_front_speed = 'on'. While it is off every page
 * is printed exactly as before. When it is on:
 *
 *  1. On the home page, stylesheets that style nothing visible there stop blocking the first
 *     paint: WooCommerce's three base stylesheets and the cart drawer, product option,
 *     product preview and report viewer styles. On the home page they only style the closed
 *     cart drawer and the closed product dialog (checked element by element in a browser on
 *     2026-11-12, phone and desktop width: no visible element changes when they are absent).
 *     Each <link> stays where it is, so the cascade is unchanged; it starts as media="print"
 *     and switches back to its own media when it has loaded.
 *     Not on product or collection landings (the dialog opens at once and needs them), nor
 *     in previews: the same condition as the on-demand dialog scripts (inc/lazy-dialog.php).
 *  2. WordPress's emoji detection script and its inline styles are not printed on the
 *     storefront. Emoji in content still show with the device's own font.
 *
 * Marketing components are not touched: spend-and-save, cart recommendations, coupons,
 * exit intent and the wheel load and run exactly as before.
 */
defined( 'ABSPATH' ) || exit;

function vc_front_speed_enabled(): bool {
    return (bool) apply_filters( 'vc_front_speed_enabled', 'on' === (string) get_option( 'pc_front_speed', 'off' ) );
}

/** Tell the master admin that this store's theme supports the switch (F1's pc_hq_capabilities). */
add_filter( 'pc_hq_capabilities', static fn( $caps ) => is_array( $caps ) ? $caps + array( 'm14.front_speed' => '1' ) : $caps );

/** Stylesheets that only style closed surfaces on the home page. */
function vc_front_speed_style_handles(): array {
    return array(
        'woocommerce-general', 'woocommerce-layout', 'woocommerce-smallscreen',
        'vc-live-cart-experience', 'vc-live-cart-progress', 'vc-live-cart-recommendation', 'vc-live-cart-feedback',
        'vialcove-product-options', 'vialcove-product-preview', 'vialcove-product-images', 'vc-product-report-viewer',
    );
}

/** Home page requests where the product dialog and the cart drawer are closed at first paint. */
function vc_front_speed_applies(): bool {
    return vc_front_speed_enabled() && ! is_admin() && is_front_page()
        && function_exists( 'vc_lazy_dialog_enabled' ) && vc_lazy_dialog_enabled();
}

/**
 * One stylesheet <link> → the same link, loaded without blocking, plus a <noscript> copy.
 * Its own media (all, screen, or a media query such as WooCommerce's small-screen sheet) is
 * kept in data-vc-media and put back when the file has loaded. Anything unexpected — no
 * <link>, an onload of its own, already print-only — is returned untouched.
 */
function vc_front_speed_style_tag( string $tag ): string {
    if ( 1 !== preg_match_all( '/<link\b[^>]*>/i', $tag ) || preg_match( '/\sonload=/i', $tag ) ) { return $tag; }
    $media = 'all';
    if ( preg_match( '/\smedia=(["\'])(.*?)\1/i', $tag, $m ) ) {
        $media = trim( $m[2] );
        if ( '' === $media ) { $media = 'all'; }
        if ( 'print' === strtolower( $media ) || str_contains( $media, '<' ) || str_contains( $media, '>' ) ) { return $tag; }
    }
    $swap = ' media="print" data-vc-media="' . str_replace( '"', '&quot;', $media ) . '" onload="this.media=this.dataset.vcMedia;this.onload=null"';
    $async = preg_match( '/\smedia=(["\']).*?\1/i', $tag )
        ? preg_replace( '/\smedia=(["\']).*?\1/i', $swap, $tag, 1 )
        : preg_replace( '/<link\b/i', '<link' . $swap, $tag, 1 );
    return is_string( $async ) ? $async . '<noscript>' . trim( $tag ) . "</noscript>\n" : $tag;
}

add_filter( 'style_loader_tag', static function ( $tag, $handle ) {
    if ( ! is_string( $tag ) || ! in_array( $handle, vc_front_speed_style_handles(), true ) || ! vc_front_speed_applies() ) { return $tag; }
    return vc_front_speed_style_tag( $tag );
}, 21, 2 );

add_action( 'init', static function (): void {
    if ( is_admin() || ! vc_front_speed_enabled() ) { return; }
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
} );
