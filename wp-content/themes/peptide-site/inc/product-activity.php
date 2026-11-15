<?php
/**
 * FE-03: "N people viewed this" / "N added to bag" in the product dialog — real numbers only.
 *
 * The numbers are not computed here. They come from the data module's endpoint
 * GET peptide/v1/product-activity?product_id=ID (requested from F4 in docs/CONTRACT.md).
 * This file only loads assets/product-activity.js, which asks that endpoint when a product
 * dialog opens and shows a line when a number reaches the store's minimum. Until the data
 * module says its endpoint is there (filter pc_product_activity_available) the script is not
 * even loaded, so the switch can be on early without a stream of 404 requests; and no
 * placeholder or sample number is ever used.
 *
 * Per-store switch, off by default: option pc_product_activity = 'on'.
 * Minimum to show a number: option pc_product_activity_min (whole number, default 5).
 */
defined( 'ABSPATH' ) || exit;

function vc_product_activity_enabled(): bool {
    return (bool) apply_filters( 'vc_product_activity_enabled', 'on' === (string) get_option( 'pc_product_activity', 'off' ) );
}

/** The smallest number worth showing: 1–1000, default 5 (also for anything that is not a whole number). */
function vc_product_activity_min( $raw = null ): int {
    $raw = null === $raw ? get_option( 'pc_product_activity_min', 5 ) : $raw;
    if ( is_string( $raw ) && preg_match( '/\A[0-9]{1,6}\z/D', $raw ) ) { $raw = (int) $raw; }
    if ( ! is_int( $raw ) || $raw < 1 ) { return 5; }
    return min( 1000, $raw );
}

/** Whether the data module's endpoint exists on this store. It turns this on itself when it registers the route. */
function vc_product_activity_available(): bool {
    return true === apply_filters( 'pc_product_activity_available', false );
}

/** Tell the master admin that this store's theme supports the switch (F1's pc_hq_capabilities). */
add_filter( 'pc_hq_capabilities', static fn( $caps ) => is_array( $caps ) ? $caps + array( 'm14.product_activity' => '1' ) : $caps );

add_action( 'wp_enqueue_scripts', static function (): void {
    // Only where the product dialog can open (its script is enqueued at priority 35 / 110).
    if ( ! vc_product_activity_enabled() || ! vc_product_activity_available() || is_admin() || ! wp_script_is( 'vc-home-quick-view', 'enqueued' ) ) { return; }
    $file = get_template_directory() . '/assets/product-activity.js';
    wp_enqueue_script( 'vc-product-activity', get_template_directory_uri() . '/assets/product-activity.js', array(), (string) filemtime( $file ), true );
    wp_localize_script( 'vc-product-activity', 'vcProductActivity', array(
        'enabled' => true,
        'url' => rest_url( 'peptide/v1/product-activity' ),
        'min' => vc_product_activity_min(),
    ) );
}, 125 );
