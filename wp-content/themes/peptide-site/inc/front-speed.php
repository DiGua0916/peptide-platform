<?php
/**
 * NF-07 (theme side): less to wait for before the first screen on the storefront's entry pages.
 *
 * Per-store switch, off by default: option pc_front_speed = 'on'. While it is off every page
 * is printed exactly as before. When it is on:
 *
 *  1. Home page and collection landings (/?vc_catalog_category=ID, where collection links are
 *     redirected to): stylesheets that style nothing visible there stop blocking the first
 *     paint. They only style the closed cart drawer and the closed product dialog (checked
 *     element by element in a browser on 2026-11-12, phone and desktop width: no visible
 *     element changes when they are absent). Each <link> stays where it is, so the cascade is
 *     unchanged; it starts as media="print" and switches back to its own media when loaded.
 *  2. Product landings (/?vc_product=ID, where /product/x/ links are redirected to): the
 *     product's dialog template is printed in the page itself. The dialog script already
 *     skips its request when the template is in the document, so the product opens without
 *     waiting for a second round trip to the server. The product's main image is preloaded
 *     with the same srcset and sizes the dialog uses. No stylesheet is deferred here: the
 *     dialog opens at once and needs all of them.
 *  3. WordPress's emoji detection script and its inline styles are not printed on the
 *     storefront. Emoji in content still show with the device's own font.
 *
 * No script is deferred, reordered or removed, so marketing components (spend-and-save,
 * stock hints, shipping preview, exit intent and its wheel, cart recommendations) load and
 * run exactly as before.
 */
defined( 'ABSPATH' ) || exit;

function vc_front_speed_enabled(): bool {
    return (bool) apply_filters( 'vc_front_speed_enabled', 'on' === (string) get_option( 'pc_front_speed', 'off' ) );
}

/** Tell the master admin that this store's theme supports the switch (F1's pc_hq_capabilities). */
add_filter( 'pc_hq_capabilities', static fn( $caps ) => is_array( $caps ) ? $caps + array( 'm14.front_speed' => '1' ) : $caps );

/** Stylesheets that only style the closed cart drawer and closed product dialog on entry pages. */
function vc_front_speed_style_handles(): array {
    return array(
        'woocommerce-general', 'woocommerce-layout', 'woocommerce-smallscreen',
        'vc-live-cart-experience', 'vc-live-cart-progress', 'vc-live-cart-recommendation', 'vc-live-cart-feedback',
        'vialcove-product-options', 'vialcove-product-preview', 'vialcove-product-images', 'vc-product-report-viewer',
    );
}

/**
 * The product dialog's own stylesheets. On the home page inc/offscreen-styles.php already
 * loads them without blocking; a collection landing keeps the dialog scripts eager, so that
 * file leaves them alone there, yet the dialog is just as closed at first paint.
 */
function vc_front_speed_dialog_style_handles(): array {
    return array( 'vc-home-quick-view', 'vialcove-product-dialog-interaction', 'vc-product-dialog-polish' );
}

/**
 * Which kind of entry page a storefront home request is (pure; the caller supplies the facts).
 *
 * @param array<int,string> $query_keys Names of the query parameters on the request.
 * @param bool              $lazy       vc_lazy_dialog_enabled(): a plain page, dialog scripts on demand.
 * @param bool              $preview    A preview, customizer or editor frame.
 * @return string 'home', 'collection', 'product' or '' (leave the page alone).
 */
function vc_front_speed_page_kind( array $query_keys, bool $lazy, bool $preview ): string {
    if ( $preview || array_intersect( array( 'pc_template_preview', 'pc_frame' ), $query_keys ) ) { return ''; }
    if ( in_array( 'vc_product', $query_keys, true ) ) { return 'product'; }
    // A search entry shows results the same way a collection entry does: dialog closed.
    if ( array_intersect( array( 'vc_catalog_category', 'vc_catalog_q' ), $query_keys ) ) { return 'collection'; }
    return $lazy ? 'home' : '';
}

/** The kind of entry page this request is, or '' when the switch is off or it is another page. */
function vc_front_speed_page(): string {
    if ( ! vc_front_speed_enabled() || is_admin() || ! is_front_page() ) { return ''; }
    $preview = ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) || ( function_exists( 'is_preview' ) && is_preview() )
        || ( class_exists( '\PeptideCore\Templates' ) && \PeptideCore\Templates::previewing_id() );
    $keys = array_map( 'strval', array_keys( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    return vc_front_speed_page_kind( $keys, function_exists( 'vc_lazy_dialog_enabled' ) && vc_lazy_dialog_enabled(), $preview );
}

/** Home page and collection landings: the product dialog and the cart drawer are closed at first paint. */
function vc_front_speed_applies(): bool {
    return in_array( vc_front_speed_page(), array( 'home', 'collection' ), true );
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
    if ( ! is_string( $tag ) ) { return $tag; }
    $kind = in_array( $handle, vc_front_speed_style_handles(), true ) ? 'entry' : ( in_array( $handle, vc_front_speed_dialog_style_handles(), true ) ? 'dialog' : '' );
    if ( '' === $kind ) { return $tag; }
    $page = vc_front_speed_page();
    // The dialog's own sheets are only ours to defer on a collection landing (see above).
    if ( 'collection' !== $page && ( 'home' !== $page || 'dialog' === $kind ) ) { return $tag; }
    return vc_front_speed_style_tag( $tag );
}, 21, 2 );

add_action( 'init', static function (): void {
    if ( is_admin() || ! vc_front_speed_enabled() ) { return; }
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
} );

// ---------- Product landings ----------

/** The product a landing request was entered through, when its dialog is about to open. */
function vc_front_speed_entry_product() {
    static $done = false, $product = null;
    if ( $done ) { return $product; }
    $done = true;
    if ( 'product' !== vc_front_speed_page() || ! function_exists( 'vc_storefront_entry_public_request' ) || ! vc_storefront_entry_public_request()
        || ! function_exists( 'vc_storefront_entry_product' ) ) { return null; }
    $raw = $_GET['vc_product'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! is_string( $raw ) || ! preg_match( '/\A[1-9][0-9]{0,9}\z/D', $raw ) || (int) $raw > 2147483647 ) { return null; }
    $found = vc_storefront_entry_product( (int) $raw );
    return $product = ( $found instanceof WC_Product ? $found : null );
}

/**
 * <link rel="preload"> for the dialog's product image, from the same data the dialog uses
 * (vc_home_quick_view_image()), so the browser picks the same file. '' when there is no
 * usable same-site image.
 *
 * Phone widths only (media="(max-width: 767px)"): there the dialog's <img> carries exactly this
 * srcset and sizes. On wider screens the dialog gallery chooses a larger file of its own, and
 * a preload would fetch a second image nobody shows (seen in a browser on 2026-11-12).
 *
 * @param mixed $image array{src,srcset,sizes} or null.
 */
function vc_front_speed_image_preload( $image, string $home ): string {
    if ( ! is_array( $image ) || ! is_string( $image['src'] ?? null ) || '' === $image['src'] ) { return ''; }
    $src = wp_parse_url( $image['src'] );
    $site = wp_parse_url( $home );
    if ( ! is_array( $src ) || ! is_array( $site ) || empty( $src['host'] ) || empty( $site['host'] )
        || strtolower( (string) $src['host'] ) !== strtolower( (string) $site['host'] )
        || ! in_array( strtolower( (string) ( $src['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) { return ''; }
    $out = '<link rel="preload" as="image" href="' . esc_url( $image['src'] ) . '"';
    $srcset = is_string( $image['srcset'] ?? null ) ? trim( $image['srcset'] ) : '';
    $sizes = is_string( $image['sizes'] ?? null ) ? trim( $image['sizes'] ) : '';
    if ( '' !== $srcset ) {
        $out .= ' imagesrcset="' . esc_attr( $srcset ) . '"';
        if ( '' !== $sizes ) { $out .= ' imagesizes="' . esc_attr( $sizes ) . '"'; }
    }
    return $out . ' media="(max-width: 767px)" fetchpriority="high">' . "\n";
}

/** The inline template is only printed when it is the one the dialog script will look for. */
function vc_front_speed_template_ok( string $html, int $id ): bool {
    $html = trim( $html );
    return $id > 0 && str_starts_with( $html, '<template data-vc-quick-product="' . $id . '"' ) && str_ends_with( $html, '</template>' );
}

add_action( 'wp_head', static function (): void {
    $product = vc_front_speed_entry_product();
    if ( ! $product || ! function_exists( 'vc_home_quick_view_image' ) ) { return; }
    $shown = function_exists( 'vc_card_display_product' ) ? vc_card_display_product( $product ) : $product;
    echo vc_front_speed_image_preload( vc_home_quick_view_image( $shown ), home_url( '/' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
}, 2 );

add_action( 'wp_footer', static function (): void {
    $product = vc_front_speed_entry_product();
    if ( ! $product || ! function_exists( 'vc_home_quick_view_template' ) ) { return; }
    // The same markup GET vialcove/v1/home-product/<id> returns; home-quick-view.js skips that
    // request when template[data-vc-quick-product="<id>"] is already in the document.
    $html = vc_home_quick_view_template( $product );
    if ( vc_front_speed_template_ok( $html, $product->get_id() ) ) {
        echo $html . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own renderer, as served by the REST route.
    }
}, 5 );
