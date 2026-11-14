<?php
/**
 * FE-03: trust strip at the top of the product dialog content — a "Secure checkout" line and
 * the payment marks this store's checkout really accepts.
 *
 * Nothing here is free text. The line is only shown when the storefront is served over HTTPS,
 * and the marks are the ones the payment gateway is configured to display at checkout, so the
 * strip cannot claim a certification, a partner or a guarantee the store does not have.
 *
 * Per-store switch, off by default: option pc_trust_badges = 'on'. While it is off nothing is
 * rendered and the dialog is exactly as before.
 */
defined( 'ABSPATH' ) || exit;

function vc_trust_badges_enabled(): bool {
    return (bool) apply_filters( 'vc_trust_badges_enabled', 'on' === (string) get_option( 'pc_trust_badges', 'off' ) );
}

/** Tell the master admin that this store's theme supports the switch (F1's pc_hq_capabilities). */
add_filter( 'pc_hq_capabilities', static fn( $caps ) => is_array( $caps ) ? $caps + array( 'm14.trust_badges' => '1' ) : $caps );

/**
 * Keep only usable payment marks: a known shape, an image on this site, no duplicates, at most 9.
 *
 * @param mixed $icons Rows of [ id, label, url ] as the payment plugin's Payment_Icons::data() returns.
 * @return array<int,array{id:string,label:string,url:string}>
 */
function vc_trust_clean_icons( $icons, string $home ): array {
    $out = array(); $seen = array();
    $site = wp_parse_url( $home );
    foreach ( is_array( $icons ) ? $icons : array() as $icon ) {
        if ( count( $out ) >= 9 || ! is_array( $icon ) ) { continue; }
        $id = $icon['id'] ?? null; $label = $icon['label'] ?? null; $url = $icon['url'] ?? null;
        if ( ! is_string( $id ) || ! preg_match( '/\A[a-z0-9_]{2,32}\z/D', $id ) || isset( $seen[ $id ] )
            || ! is_string( $label ) || '' === trim( $label ) || strlen( $label ) > 40 || ! is_string( $url ) ) { continue; }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || ! is_array( $site ) || empty( $parts['host'] ) || empty( $site['host'] )
            || strtolower( (string) $parts['host'] ) !== strtolower( (string) $site['host'] )
            || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
            || ! preg_match( '~\A/[A-Za-z0-9_./-]+\.(?:svg|png|webp)\z~D', (string) ( $parts['path'] ?? '' ) )
            || str_contains( (string) $parts['path'], '..' ) ) { continue; }
        $seen[ $id ] = true;
        $out[] = array( 'id' => $id, 'label' => trim( $label ), 'url' => $url );
    }
    return $out;
}

/** The marks the store's enabled payment gateways display at checkout (none when there are none). */
function vc_trust_payment_icons(): array {
    $icons = array();
    if ( function_exists( 'WC' ) && WC() && WC()->payment_gateways() && class_exists( '\VialCove\Panapay\Payment_Icons' ) ) {
        foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
            if ( is_object( $gateway ) && 'yes' === ( $gateway->enabled ?? '' ) && method_exists( $gateway, 'payment_icons' ) ) {
                $icons = array_merge( $icons, \VialCove\Panapay\Payment_Icons::data( (array) $gateway->payment_icons() ) );
            }
        }
    }
    return vc_trust_clean_icons( apply_filters( 'vc_trust_payment_icons', $icons ), home_url( '/' ) );
}

/** The strip. Empty when there is nothing true to show. */
function vc_trust_markup( bool $secure, array $icons ): string {
    if ( ! $secure && ! $icons ) { return ''; }
    $out = '<div class="vc-trust" data-vc-trust>';
    if ( $secure ) {
        $out .= '<p class="vc-trust__secure"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>'
            . '<span><strong>Secure checkout</strong> <small>Encrypted connection</small></span></p>';
    }
    if ( $icons ) {
        $out .= '<p class="vc-trust__pay" role="img" aria-label="' . esc_attr( 'Accepted payments: ' . implode( ', ', array_column( $icons, 'label' ) ) ) . '">';
        foreach ( $icons as $icon ) {
            $out .= '<img src="' . esc_url( $icon['url'] ) . '" alt="" width="40" height="25" loading="lazy" decoding="async">';
        }
        $out .= '</p>';
    }
    return $out . '</div>';
}

/**
 * Whether customers reach this store over HTTPS: this request is HTTPS, or the store's public
 * address is (PHP can sit behind a proxy that ends TLS and not see it on the request itself).
 */
function vc_trust_secure( bool $request_is_https, string $home ): bool {
    return $request_is_https || 'https' === strtolower( (string) wp_parse_url( $home, PHP_URL_SCHEME ) );
}

/** What the product dialog prints: nothing unless the store switched the strip on. */
function vc_trust_badges(): string {
    if ( ! vc_trust_badges_enabled() ) { return ''; }
    return vc_trust_markup( (bool) apply_filters( 'vc_trust_secure', vc_trust_secure( is_ssl(), home_url( '/' ) ) ), vc_trust_payment_icons() );
}
