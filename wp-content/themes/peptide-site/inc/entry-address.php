<?php
/**
 * FE-02: shareable product and collection addresses (platform owner's answer to Q5,
 * 2026-11-10: keep the dialogs, change the address).
 *
 * Nothing changes on the server side of an entry: /product/x/ and collection links are
 * still redirected to the home document (inc/storefront-entry.php), and /?vc_product=ID
 * links already used in ads keep working. This file only loads assets/entry-address.js,
 * which rewrites the address bar to the product's or collection's own permalink.
 *
 * Per-store switch, off by default: option pc_entry_address = 'on'. While it is off no
 * script is added and the storefront behaves exactly as before.
 */
defined( 'ABSPATH' ) || exit;

function vc_entry_address_enabled(): bool {
    return (bool) apply_filters( 'vc_entry_address_enabled', 'on' === (string) get_option( 'pc_entry_address', 'off' ) );
}

/** Tell the master admin that this store's theme supports the switch (F1's pc_hq_capabilities). */
add_filter( 'pc_hq_capabilities', static fn( $caps ) => is_array( $caps ) ? $caps + array( 'm14.entry_address' => '1' ) : $caps );

/** Path of a same-site URL (no query, no fragment); '' for other hosts and unusable values. */
function vc_entry_address_path( $url, string $home ): string {
    if ( ! is_string( $url ) || '' === $url ) { return ''; }
    $parts = wp_parse_url( $url );
    $site = wp_parse_url( $home );
    if ( ! is_array( $parts ) || ! is_array( $site ) || empty( $parts['host'] ) || empty( $site['host'] )
        || strtolower( (string) $parts['host'] ) !== strtolower( (string) $site['host'] ) ) { return ''; }
    $path = (string) ( $parts['path'] ?? '/' );
    return ( '' === $path || '/' !== $path[0] || preg_match( '/[\s<>"\'\\\\]/', $path ) ) ? '' : $path;
}

/** Permalink path of the collection this request was entered through, or ''. */
function vc_entry_address_category(): string {
    $raw = $_GET['vc_catalog_category'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! is_string( $raw ) || ! preg_match( '/\A[1-9][0-9]{0,9}\z/D', $raw ) ) { return ''; }
    $link = get_term_link( (int) $raw, 'product_cat' );
    return is_wp_error( $link ) ? '' : vc_entry_address_path( (string) $link, home_url( '/' ) );
}

/**
 * Id and permalink path of the product this request was entered through (/?vc_product=ID,
 * which is also where /product/x/ links are redirected to), or null. The product dialog does
 * not always carry a link to the product page and the product's card may not be on the page,
 * so the script gets the permalink from here.
 */
function vc_entry_address_product(): ?array {
    $raw = $_GET['vc_product'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! is_string( $raw ) || ! preg_match( '/\A[1-9][0-9]{0,9}\z/D', $raw ) ) { return null; }
    $id = (int) $raw;
    if ( 'product' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) { return null; }
    $path = vc_entry_address_path( (string) get_permalink( $id ), home_url( '/' ) );
    return ( '' === $path || '/' === $path ) ? null : array( 'id' => $id, 'path' => $path );
}

add_action( 'wp_enqueue_scripts', static function (): void {
    // Only where the dialog layers are active (their script is enqueued at priority 95).
    if ( ! vc_entry_address_enabled() || ! wp_script_is( 'vc-live-commerce-navigation', 'enqueued' )
        || ! function_exists( 'vc_storefront_entry_public_request' ) || ! vc_storefront_entry_public_request() ) { return; }
    $file = get_template_directory() . '/assets/entry-address.js';
    wp_enqueue_script( 'vc-entry-address', get_template_directory_uri() . '/assets/entry-address.js', array(), (string) filemtime( $file ), true );
    wp_localize_script( 'vc-entry-address', 'vcEntryAddress', array(
        'enabled' => true,
        'home' => vc_entry_address_path( home_url( '/' ), home_url( '/' ) ) ?: '/',
        'category' => vc_entry_address_category(),
        'product' => vc_entry_address_product(),
    ) );
}, 120 );
