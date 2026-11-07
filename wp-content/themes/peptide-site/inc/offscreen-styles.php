<?php
/**
 * Stylesheets that cannot affect the first screen load without blocking it
 * (front-end optimisation 2026-11-07, item 1).
 *
 * Every stylesheet is still enqueued and printed in the same place, so the cascade is
 * unchanged; only its <link> uses the media="print" → "all" swap, which lets a phone
 * paint before these files arrive. Two groups:
 *  - product dialog styles, on every page (the dialog is closed at first paint);
 *  - homepage section styles, on pages that render no page layout of their own
 *    (checkout, cart, account, plain pages): they only style module sections those
 *    pages do not show, plus the product dialog.
 * Requests that keep the old behaviour: previews/editors and product landings
 * (vc_lazy_dialog_enabled() is false there), and every page with a published layout.
 */
defined( 'ABSPATH' ) || exit;

function vc_offscreen_dialog_styles(): array {
    return array( 'vc-home-quick-view', 'vialcove-product-dialog-interaction', 'vc-product-dialog-polish' );
}

/** Module styles used only by page-layout sections (modules-shared/-content stay blocking). */
function vc_offscreen_section_styles(): array {
    return array( 'vialcove-modules-media-text', 'vialcove-modules-carousel', 'vialcove-modules-home', 'vialcove-modules-editorial', 'vialcove-demo-home', 'vialcove-demo-catalog' );
}

/** Whether this request renders a page layout (homepage or a designed page) in its body. */
function vc_request_has_body_layout(): bool {
    if ( is_front_page() ) { return true; }
    if ( ! class_exists( '\PeptideCore\Design_Pages' ) || ! function_exists( 'peptide_core_has_layout' ) ) { return true; }
    $doc = \PeptideCore\Design_Pages::route();
    return '' !== $doc && peptide_core_has_layout( $doc );
}

function vc_offscreen_style_handles(): array {
    static $handles = null;
    if ( null === $handles ) {
        $handles = array();
        if ( function_exists( 'vc_lazy_dialog_enabled' ) && vc_lazy_dialog_enabled() && apply_filters( 'vc_offscreen_styles', true ) ) {
            $handles = vc_offscreen_dialog_styles();
            if ( ! vc_request_has_body_layout() ) { $handles = array_merge( $handles, vc_offscreen_section_styles() ); }
        }
        $handles = array_fill_keys( $handles, true );
    }
    return $handles;
}

/** media="all" link → non-blocking link plus a <noscript> fallback. */
function vc_offscreen_style_tag( string $tag ): string {
    if ( ! preg_match( '/\smedia=(["\'])(all|screen)?\1/i', $tag ) && preg_match( '/\smedia=/i', $tag ) ) { return $tag; }
    $async = preg_match( '/\smedia=(["\'])[^"\']*\1/i', $tag )
        ? preg_replace( '/\smedia=(["\'])[^"\']*\1/i', ' media="print" onload="this.media=\'all\';this.onload=null"', $tag, 1 )
        : preg_replace( '/<link\b/i', '<link media="print" onload="this.media=\'all\';this.onload=null"', $tag, 1 );
    return $async . '<noscript>' . trim( $tag ) . "</noscript>\n";
}

add_filter( 'style_loader_tag', static function ( $tag, $handle ) {
    if ( ! is_string( $tag ) || is_admin() || ! isset( vc_offscreen_style_handles()[ $handle ] ) ) { return $tag; }
    return vc_offscreen_style_tag( $tag );
}, 20, 2 );
