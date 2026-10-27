<?php
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', static function () {
    $root = get_template_directory_uri();
    wp_enqueue_style( 'vialcove-storefront-content', $root . '/assets/storefront-content.css', [ 'vialcove-brand' ], (string) filemtime( __DIR__ . '/../assets/storefront-content.css' ) );
    $page = get_queried_object();
    if ( is_singular() && $page instanceof WP_Post && has_shortcode( $page->post_content, 'pc_coupons' ) ) {
        wp_enqueue_style( 'vialcove-coupons', $root . '/assets/coupons.css', [ 'vialcove-brand' ], (string) filemtime( __DIR__ . '/../assets/coupons.css' ) );
        wp_enqueue_script( 'vialcove-coupons', $root . '/assets/coupons.js', [], (string) filemtime( __DIR__ . '/../assets/coupons.js' ), true );
    }
}, 30 );

/** Consume Woo's notice queue once, including redirects to non-commerce pages. */
function vc_storefront_notices(): void {
    if ( ! function_exists( 'wc_print_notices' ) ) { return; }
    $notices = wc_print_notices( true );
    if ( $notices ) {
        echo '<div class="container vc-storefront-notices" aria-label="Store notifications">' . $notices . '</div>';
    }
}

/** Optional policy links only; Woo owns acknowledgement and checkout validation. */
function vc_checkout_terms_html( ?array $terms = null ): string {
    if ( null === $terms ) {
        $terms = function_exists( 'peptide_core_get_checkout_terms' ) ? peptide_core_get_checkout_terms() : [];
    }
    $links = [];
    foreach ( $terms as $term ) {
        $url = esc_url( $term['url'] ?? '' );
        $title = trim( $term['title'] ?? '' );
        if ( $url && $title ) {
            $links[] = '<li><a href="' . $url . '" target="_blank" rel="noopener">' . esc_html( $title ) . '<span class="screen-reader-text"> (opens in a new tab)</span></a></li>';
        }
    }
    return $links ? '<nav class="vc-checkout-policies" aria-label="Checkout policies"><span>Order policies</span><ul>' . implode( '', $links ) . '</ul></nav>' : '';
}
