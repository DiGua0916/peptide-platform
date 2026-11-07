<?php
/**
 * Product dialog scripts on demand (front-end optimisation 2026-11-07, item 2).
 *
 * The product dialog bundle (about 17 scripts) used to execute on every page load even
 * though the dialog only opens when a shopper taps a product. On phones that work
 * competes with the first paint. These scripts are still enqueued exactly as before (same
 * handles, order, inline data); in the footer their tags are printed inert
 * (type="text/plain") and a small loader runs them, in the original order, on the first
 * tap/press/focus, or once the page has finished loading and the browser is idle.
 *
 * A tap on a product before the bundle has run is held and replayed after it has. Pages that
 * must open a dialog at once keep the old eager behaviour: product ad landings
 * (?vc_product=), catalogue entries, editor previews, and back/forward navigation that
 * restores a dialog layer (detected in the browser). Pixel and Conversions API scripts are
 * never deferred.
 */
defined( 'ABSPATH' ) || exit;

/** Scripts that only serve the product dialog and its history layers. */
function vc_lazy_dialog_candidates(): array {
    return array(
        'peptide-showcase-quick-view', 'vc-home-quick-view', 'vc-product-purchase-dock', 'vc-product-purchase-return',
        'vc-product-unit-pricing', 'vc-product-dialog-meta', 'vc-product-dialog-ticker-model', 'vc-product-dialog-ticker',
        'vc-product-dialog-report-viewer', 'vc-product-dialog-feedback', 'vc-product-dialog-experience', 'vc-product-dialog-offer-follow',
        'vc-product-dialog-recent', 'vc-product-image-warmup', 'vc-product-template-prefetch', 'vc-product-report-library-viewer',
        'vc-live-commerce-navigation', 'vc-product-dialog-polish',
    );
}

/** Requests that open (or may open) a dialog straight away keep eager scripts. */
function vc_lazy_dialog_enabled(): bool {
    if ( is_admin() || is_customize_preview() || is_preview() ) { return false; }
    foreach ( array( 'vc_product', 'vc_catalog_q', 'vc_catalog_category', 'pc_template_preview', 'pc_frame' ) as $key ) {
        if ( isset( $_GET[ $key ] ) ) { return false; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }
    if ( class_exists( '\PeptideCore\Templates' ) && \PeptideCore\Templates::previewing_id() ) { return false; }
    return (bool) apply_filters( 'vc_lazy_product_dialog', true );
}

/**
 * Candidates that no eagerly printed script depends on (directly or indirectly).
 *
 * @param array<string,array<int,string>> $deps    handle => dependency handles (registered scripts).
 * @param string[]                        $printed handles printed on this page.
 */
function vc_lazy_dialog_resolve( array $deps, array $printed, array $candidates ): array {
    $lazy = array_fill_keys( array_values( array_intersect( $candidates, $printed ) ), true );
    $seen = array();
    $walk = static function ( string $handle ) use ( &$walk, &$lazy, &$seen, $deps ): void {
        foreach ( $deps[ $handle ] ?? array() as $dep ) {
            unset( $lazy[ $dep ] );
            if ( ! isset( $seen[ $dep ] ) ) { $seen[ $dep ] = true; $walk( $dep ); }
        }
    };
    foreach ( $printed as $handle ) {
        if ( ! isset( $lazy[ $handle ] ) ) { $walk( $handle ); }
    }
    // A candidate made eager above makes its own dependencies eager too.
    foreach ( array_keys( array_diff_key( array_fill_keys( $candidates, true ), $lazy ) ) as $handle ) {
        if ( in_array( $handle, $printed, true ) ) { $walk( $handle ); }
    }
    return array_keys( $lazy );
}

/** Turns every <script> in one handle's printed output into an inert tag the loader runs later. */
function vc_lazy_dialog_inert_tag( string $tag ): string {
    if ( preg_match( '/<script\b[^>]*\btype=["\']module["\']/i', $tag ) ) { return $tag; }
    return (string) preg_replace_callback( '/<script\b([^>]*)>/i', static function ( array $m ): string {
        $attrs = preg_replace( '/\s(?:type=("|\')[^"\']*\1|defer(?:=("|\')[^"\']*\2)?|async(?:=("|\')[^"\']*\3)?)(?=[\s>\/]|$)/i', '', $m[1] );
        $attrs = preg_replace( '/\ssrc=/i', ' data-vc-src=', (string) $attrs );
        return '<script type="text/plain" data-vc-lazy="dialog"' . $attrs . '>';
    }, $tag );
}

$GLOBALS['vc_lazy_dialog_handles'] = array();

add_action( 'wp_print_footer_scripts', static function (): void {
    if ( ! vc_lazy_dialog_enabled() ) { return; }
    $scripts = wp_scripts();
    $deps = array(); $printed = array();
    foreach ( $scripts->registered as $handle => $item ) {
        $deps[ $handle ] = array_values( (array) $item->deps );
        if ( wp_script_is( $handle, 'enqueued' ) || wp_script_is( $handle, 'done' ) ) { $printed[] = $handle; }
    }
    $done = (array) $scripts->done;
    // Never defer something already printed in the head.
    $GLOBALS['vc_lazy_dialog_handles'] = array_fill_keys( array_diff( vc_lazy_dialog_resolve( $deps, $printed, vc_lazy_dialog_candidates() ), $done ), true );
}, 1 );

add_filter( 'script_loader_tag', static function ( $tag, $handle ) {
    return is_string( $tag ) && isset( $GLOBALS['vc_lazy_dialog_handles'][ $handle ] ) ? vc_lazy_dialog_inert_tag( $tag ) : $tag;
}, 20, 2 );

add_action( 'wp_footer', static function (): void {
    if ( ! $GLOBALS['vc_lazy_dialog_handles'] ) { return; }
    $path = get_template_directory() . '/assets/lazy-dialog.js';
    if ( ! is_readable( $path ) ) { return; }
    wp_print_inline_script_tag( (string) file_get_contents( $path ), array( 'id' => 'vc-lazy-dialog-loader' ) );
}, 25 );
