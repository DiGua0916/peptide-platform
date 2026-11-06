<?php
/** Small public utilities, driven by the plugin's published store configuration. */
defined( 'ABSPATH' ) || exit;

/**
 * Expensive, visitor-independent section HTML (catalogue grid, product menu) through the
 * plugin's section cache when it is enabled; otherwise, or for personalised visitors, render.
 */
function vc_cached_fragment( string $name, array $inputs, callable $render ): string {
    return class_exists( '\PeptideCore\Fragment_Cache' ) ? \PeptideCore\Fragment_Cache::remember( $name, $inputs, $render ) : (string) $render();
}

/** Editorial header presentation; the plugin remains the promotion authority. */
function vc_storefront_announcement_html( array $settings ): string {
    if ( empty( $settings['announcement'] ) || ( ! empty( $settings['announcementHomeOnly'] ) && ! is_front_page() ) ) { return ''; }
    if ( 'promotion' === ( $settings['announcementSource'] ?? 'manual' ) ) {
        // An old plugin or an unavailable/expired binding must not show stale manual copy.
        if ( ! function_exists( 'peptide_core_get_campaign_announcement' ) ) { return ''; }
        $item = peptide_core_get_campaign_announcement( $settings, is_front_page() );
        if ( ! is_array( $item ) || true !== ( $item['visible'] ?? false ) || 'promotion' !== ( $item['source'] ?? '' )
            || (int) ( $item['promotionId'] ?? 0 ) !== (int) ( $settings['announcementPromotion'] ?? 0 )
            || ! in_array( $item['status'] ?? '', array( 'active', 'upcoming' ), true ) || empty( $item['html'] ) ) { return ''; }
        $copy = wp_kses_post( $item['html'] );
        // Localize the existing campaign name for this English storefront;
        // tier amounts, dates and eligibility still come unchanged from the DTO.
        if ( '黑色星期五' === ( $item['title'] ?? '' ) ) { $copy = str_replace( '<strong>黑色星期五</strong>', '<strong>Black Friday</strong>', $copy ); }
        return '<aside class="vc-campaign-announcement" aria-label="Store offer" data-campaign-status="' . esc_attr( $item['status'] ) . '"><div class="vc-demo-part-wrap">' . $copy . '</div></aside>';
    }
    $background = sanitize_hex_color( $settings['announcementBackground'] ?? '' ) ?: '#17343e';
    $color = sanitize_hex_color( $settings['announcementColor'] ?? '' ) ?: '#ffffff';
    $copy = wp_kses_post( $settings['announcementText'] ?? '' );
    return '' === trim( $copy ) ? '' : '<div class="vc-demo-announcement" style="background:' . esc_attr( $background ) . ';color:' . esc_attr( $color ) . '">' . $copy . '</div>';
}

/** Accept only the actual enabled WhatsApp channel, never a placeholder phone. */
function vc_storefront_whatsapp_url( array $support ): string {
    if ( true !== ( $support['enabled'] ?? false ) || ! is_array( $support['channels'] ?? null ) ) { return ''; }
    foreach ( $support['channels'] as $channel ) {
        if ( ! is_array( $channel ) || 'whatsapp' !== ( $channel['type'] ?? '' ) || ! is_string( $channel['url'] ?? null ) ) { continue; }
        $url = $channel['url'];
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || 'wa.me' !== strtolower( $parts['host'] ?? '' )
            || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['fragment'] )
            || ! preg_match( '~^/[1-9][0-9]{6,14}/?$~D', $parts['path'] ?? '' ) ) { continue; }
        return $url;
    }
    return '';
}

function vc_storefront_utilities_assets(): void {
    if ( is_admin() ) { return; }
    $dir = get_template_directory() . '/assets/';
    $uri = get_template_directory_uri() . '/assets/';
    wp_enqueue_style( 'vc-storefront-utilities', $uri . 'storefront-utilities.css', array( 'vialcove-brand', 'vialcove-demo-site-parts' ), (string) filemtime( $dir . 'storefront-utilities.css' ) );
    if ( is_front_page() ) {
        wp_enqueue_style( 'vc-inline-delivery', $uri . 'inline-delivery.css', array( 'vialcove-demo-home' ), (string) filemtime( $dir . 'inline-delivery.css' ) );
        wp_enqueue_style( 'vc-floating-cart', $uri . 'floating-cart.css', array( 'vialcove-demo-site-parts' ), (string) filemtime( $dir . 'floating-cart.css' ) );
        wp_enqueue_script( 'vc-floating-cart', $uri . 'floating-cart.js', array(), (string) filemtime( $dir . 'floating-cart.js' ), true );
    }
    wp_enqueue_script( 'vc-storefront-utilities', $uri . 'storefront-utilities.js', array(), (string) filemtime( $dir . 'storefront-utilities.js' ), true );
}
add_action( 'wp_enqueue_scripts', 'vc_storefront_utilities_assets', 95 );

add_action( 'wp_footer', function (): void {
    // Checkout has its own support and payment surface. Keep that surface clear.
    if ( is_admin() || ( function_exists( 'is_checkout' ) && is_checkout() ) || ! function_exists( 'peptide_core_get_support_contacts' ) ) { return; }
    $support = peptide_core_get_support_contacts();
    $url = is_array( $support ) ? vc_storefront_whatsapp_url( $support ) : '';
    if ( '' === $url ) { return; }
    echo '<div class="vc-help-widget" id="vc-contact-support" data-vc-help>';
    echo '<a class="vc-help-contact" id="vc-whatsapp-contact" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" aria-label="Contact support now on WhatsApp (opens in a new tab)" tabindex="-1" aria-hidden="true"><strong>Contact support now</strong><span>Add us on WhatsApp <span aria-hidden="true">↗</span></span></a>';
    echo '<button class="vc-help-float" type="button" aria-label="Open WhatsApp support" aria-expanded="false" aria-controls="vc-whatsapp-contact"><img src="' . esc_url( get_template_directory_uri() . '/assets/product-dialog-support.png' ) . '" width="48" height="48" alt="" decoding="async"></button>';
    echo '</div>';
}, 15 );
