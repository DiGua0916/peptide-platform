<?php
/** Expanded editorial footer. All destinations remain merchant-owned fields. */
defined( 'ABSPATH' ) || exit;

add_filter( 'peptide_core_layout_definitions', 'vc_footer_details_definitions', 50 );

function vc_footer_details_definitions( array $defs ): array {
    if ( empty( $defs['site_footer'] ) || ! class_exists( '\PeptideCore\Modules\Fields' ) ) { return $defs; }
    $f = static fn( $type, $key, $label, $default, $extra = array() ) => \PeptideCore\Modules\Fields::f( $type, $key, $label, $default, $extra );
    $show = array( 'show_if' => array( 'presentation' => 'demo', 'footerExpanded' => true ) );
    $fields = array( $f( 'toggle', 'footerExpanded', 'Expanded footer with contact icons', false, array( 'show_if' => array( 'presentation' => 'demo' ) ) ) );
    foreach ( array( 'footerShopTitle' => 'Shop', 'footerHelpTitle' => 'Help', 'footerResourcesTitle' => 'Resources', 'footerContactTitle' => 'Connect with us' ) as $key => $default ) {
        $fields[] = $f( 'text', $key, $default . ' heading', $default, $show );
    }
    foreach ( array( 'footerServiceLinks' => 'Help links', 'footerResourceLinks' => 'Resource links' ) as $key => $label ) {
        $fields[] = $f( 'repeater', $key, $label, array(), $show + array( 'max' => 8, 'fields' => array( $f( 'text', 'label', 'Label', '' ), $f( 'url', 'url', 'URL', '' ) ) ) );
    }
    $fields[] = $f( 'toggle', 'footerUseSupport', 'Include enabled store WhatsApp contact', true, $show + array( 'help' => 'Uses the published store support settings. Social links below need your own official account URL; empty links stay hidden.' ) );
    $existing = array_column( $defs['site_footer']['settings'], 'key' );
    foreach ( $fields as $field ) { if ( ! in_array( $field['key'], $existing, true ) ) { $defs['site_footer']['settings'][] = $field; } }
    return $defs;
}

/** Shared navigation fields may use a local path; contacts require an explicit scheme. */
function vc_footer_details_url( $raw ): string {
    if ( ! is_string( $raw ) || '' === trim( $raw ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $raw ) ) { return ''; }
    $url = trim( $raw );
    if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) { return esc_url( $url ); }
    if ( str_starts_with( $url, '#' ) ) { return esc_url( $url ); }
    $parts = wp_parse_url( $url );
    if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return ''; }
    $scheme = strtolower( $parts['scheme'] ?? '' );
    if ( in_array( $scheme, array( 'https', 'http' ), true ) && ! empty( $parts['host'] ) ) { return esc_url( $url, array( 'https', 'http' ) ); }
    if ( 'mailto' === $scheme && empty( $parts['query'] ) && is_email( $parts['path'] ?? '' ) ) { return esc_url( $url, array( 'mailto' ) ); }
    if ( 'tel' === $scheme && preg_match( '/^\+?[0-9().-]{5,32}$/D', $parts['path'] ?? '' ) ) { return esc_url( $url, array( 'tel' ) ); }
    return '';
}

/** Exact official hosts prevent a similarly named website from borrowing a brand. */
function vc_footer_details_contact( array $link ): ?array {
    $url = vc_footer_details_url( $link['url'] ?? '' );
    if ( '' === $url ) { return null; }
    $parts = wp_parse_url( html_entity_decode( $url, ENT_QUOTES ) );
    $scheme = strtolower( $parts['scheme'] ?? '' );
    if ( 'mailto' === $scheme ) { $platform = 'email'; }
    elseif ( 'tel' === $scheme ) { $platform = 'phone'; }
    else {
        if ( 'https' !== $scheme || isset( $parts['port'] ) ) { return null; }
        $hosts = array(
            'wa.me' => 'whatsapp', 'api.whatsapp.com' => 'whatsapp', 'www.whatsapp.com' => 'whatsapp',
            'instagram.com' => 'instagram', 'www.instagram.com' => 'instagram',
            'facebook.com' => 'facebook', 'www.facebook.com' => 'facebook', 'm.facebook.com' => 'facebook',
            't.me' => 'telegram', 'telegram.me' => 'telegram',
            'x.com' => 'x', 'www.x.com' => 'x', 'twitter.com' => 'x', 'www.twitter.com' => 'x',
            'youtube.com' => 'youtube', 'www.youtube.com' => 'youtube', 'youtu.be' => 'youtube',
            'tiktok.com' => 'tiktok', 'www.tiktok.com' => 'tiktok',
            'linkedin.com' => 'linkedin', 'www.linkedin.com' => 'linkedin',
        );
        $platform = $hosts[ strtolower( $parts['host'] ?? '' ) ] ?? '';
        if ( '' === $platform ) { return null; }
    }
    $labels = array( 'whatsapp' => 'WhatsApp', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'telegram' => 'Telegram', 'x' => 'X', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'email' => 'Email', 'phone' => 'Phone' );
    $label = trim( (string) ( $link['label'] ?? '' ) );
    // Ignore the generic builder icon field; the verified destination chooses its brand.
    return array( 'url' => $url, 'platform' => $platform, 'label' => '' !== $label ? $label : $labels[ $platform ], 'key' => $platform . ':' . strtolower( $parts['host'] ?? $scheme ) . ':' . rtrim( $parts['path'] ?? '', '/' ) );
}

function vc_footer_details_icon( string $platform ): string {
    $paths = array(
        'whatsapp' => 'M20.5 3.5A11.8 11.8 0 0 0 2 17.7L.4 23.6l6-1.6a11.7 11.7 0 0 0 5.6 1.4h0A11.8 11.8 0 0 0 20.5 3.5ZM12 21.4a9.6 9.6 0 0 1-4.9-1.3l-.4-.2-3.6.9 1-3.5-.3-.4a9.7 9.7 0 1 1 8.2 4.5Zm5.3-7.2c-.3-.1-1.7-.8-2-.9-.3-.1-.5-.1-.7.2l-.9 1c-.1.2-.3.2-.6.1a8 8 0 0 1-3.8-3.3c-.2-.3 0-.4.1-.6l.5-.6.3-.5c.1-.2 0-.4 0-.5L9.3 7c-.2-.5-.4-.5-.6-.5h-.5c-.2 0-.5.1-.7.3-.3.3-1 1-1 2.3s1 2.6 1.2 2.8c.1.2 2 3 4.8 4.2 2.8 1.2 2.8.8 3.3.8s1.7-.7 2-1.4c.2-.7.2-1.3.2-1.4-.1-.1-.3-.2-.6-.4Z',
        'instagram' => 'M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5Zm0 2a3 3 0 0 0-3 3v10a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3H7Zm5 3a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Zm5.5-3.5a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4Z',
        'facebook' => 'M14 22v-9h3l.5-4H14V6.5c0-1.2.4-2 2-2H18V1.1A25 25 0 0 0 15 1c-3 0-5 1.8-5 5v3H7v4h3v9h4Z',
        'telegram' => 'm21.7 3.3-3.2 17.1c-.2 1.2-.9 1.5-1.9.9l-4.9-3.6-2.4 2.3c-.3.3-.5.5-1 .5l.4-5 9.1-8.2c.4-.4-.1-.6-.6-.3L6 14.2 1.1 12.7c-1.1-.3-1.1-1.1.2-1.6L20 3.9c.9-.4 1.9-.2 1.7-.6Z',
        'x' => 'M18.9 2H22l-6.8 7.8L23.2 22h-6.3l-5-7.6L5.3 22H2.1l8.3-9.5L.8 2h6.4l4.5 6.7L18.9 2Zm-1.1 18h1.8L6.1 3.9H4.2L17.8 20Z',
        'youtube' => 'M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 0 0 .5 6.2 31 31 0 0 0 0 12a31 31 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 0 0 2.1-2.1A31 31 0 0 0 24 12a31 31 0 0 0-.5-5.8ZM9.6 15.6V8.4l6.3 3.6-6.3 3.6Z',
        'tiktok' => 'M16.7 1c.4 2.6 1.9 4.2 4.3 4.4v3.9a9.5 9.5 0 0 1-4.3-1.2v7.8A7.1 7.1 0 1 1 10.6 9v4a3.2 3.2 0 1 0 2.1 3V1h4Z',
        'linkedin' => 'M3 1a2 2 0 1 1 0 4 2 2 0 0 1 0-4ZM1 7h4v15H1V7Zm7 0h4v2a5 5 0 0 1 4.5-2.4c4 0 4.5 2.6 4.5 6V22h-4v-8.3c0-2-.1-3.7-2.5-3.7S12 12 12 13.5V22H8V7Z',
        'email' => 'M2 4h20v16H2V4Zm2 2v.8l8 5.5 8-5.5V6H4Zm16 3.2-8 5.5-8-5.5V18h16V9.2Z',
        'phone' => 'm6 2 4 5-2.4 2.4a15 15 0 0 0 7 7L17 14l5 4-1 4C10 24 0 14 2 3l4-1Z',
    );
    return isset( $paths[ $platform ] ) ? '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true" focusable="false"><path d="' . esc_attr( $paths[ $platform ] ) . '"/></svg>' : '';
}

function vc_footer_details_links( array $rows ): string {
    $out = '';
    foreach ( array_slice( $rows, 0, 8 ) as $row ) {
        $link = $row['settings'] ?? array();
        $url = vc_footer_details_url( $link['url'] ?? '' );
        if ( false === ( $row['enabled'] ?? true ) || '' === $url || '' === trim( $link['label'] ?? '' ) ) { continue; }
        $out .= '<li><a href="' . $url . '">' . esc_html( $link['label'] ) . '</a></li>';
    }
    return '' === $out ? '' : '<ul>' . $out . '</ul>';
}

/** Never advertise a missing/draft policy or Woo's fallback home URL as a policy. */
function vc_footer_details_policies(): string {
    $rows = array();
    $ids = array( 'Privacy policy' => (int) get_option( 'wp_page_for_privacy_policy' ), 'Terms' => function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'terms' ) : 0 );
    foreach ( $ids as $label => $id ) {
        if ( $id <= 0 || 'publish' !== get_post_status( $id ) || 'page' !== get_post_type( $id ) ) { continue; }
        $url = vc_footer_details_url( get_permalink( $id ) );
        if ( '' === $url || rtrim( $url, '/' ) === rtrim( home_url( '/' ), '/' ) ) { continue; }
        $rows[] = array( 'settings' => array( 'label' => $label, 'url' => $url ) );
    }
    return vc_footer_details_links( $rows );
}

function vc_footer_details_render( array $module, string $context ): string {
    $s = $module['settings'] ?? array();
    $brand = $s['brandName'] ?? get_bloginfo( 'name' );
    $style = vc_demo_site_part_widths( $s ) . '--vc-demo-part-bg:' . vc_demo_site_part_color( $s['footerBackground'] ?? '', '#eff5f5' ) . ';--vc-demo-part-ink:' . vc_demo_site_part_color( $s['footerText'] ?? '', '#17343e' ) . ';';
    $out = '<footer class="vc-demo-footer vc-footer-details" style="' . esc_attr( $style ) . '"><div class="vc-demo-part-wrap"><div class="vc-footer-details__brand"><a class="vc-demo-brand" href="' . esc_url( home_url( '/' ) ) . '" aria-label="' . esc_attr( $brand . ' home' ) . '">' . vc_demo_site_part_logo( $s ) . '</a><div class="vc-demo-footer-description">' . wp_kses_post( $s['description'] ?? '' ) . '</div></div><div class="vc-footer-details__columns">';
    $sections = array(
        array( $s['footerShopTitle'] ?? 'Shop', vc_demo_site_part_nav( vc_demo_site_part_menu( $s, 'footer' ) ) ),
        array( $s['footerHelpTitle'] ?? 'Help', vc_footer_details_links( $s['footerServiceLinks'] ?? array() ) ),
        array( $s['footerResourcesTitle'] ?? 'Resources', vc_footer_details_links( $s['footerResourceLinks'] ?? array() ) ),
    );
    foreach ( $sections as [ $title, $links ] ) {
        if ( '' !== $links ) { $out .= '<nav class="vc-footer-details__column" aria-label="' . esc_attr( $title ) . '"><h2>' . esc_html( $title ) . '</h2>' . $links . '</nav>'; }
    }
    $contacts = array(); $disabled_contacts = array();
    foreach ( array_slice( $module['items'] ?? array(), 0, 12 ) as $item ) {
        $contact = vc_footer_details_contact( $item['settings'] ?? array() );
        if ( false === ( $item['enabled'] ?? true ) ) { if ( $contact ) { $disabled_contacts[ $contact['key'] ] = true; } continue; }
        if ( $contact ) { $contacts[ $contact['key'] ] = $contact; }
    }
    if ( false !== ( $s['footerUseSupport'] ?? true ) && function_exists( 'peptide_core_get_support_contacts' ) && function_exists( 'vc_storefront_whatsapp_url' ) ) {
        $support = peptide_core_get_support_contacts();
        $url = is_array( $support ) ? vc_storefront_whatsapp_url( $support ) : '';
        $contact = vc_footer_details_contact( array( 'label' => 'WhatsApp', 'url' => $url ) );
        if ( $contact && ! isset( $contacts[ $contact['key'] ] ) && ! isset( $disabled_contacts[ $contact['key'] ] ) ) { $contacts[ $contact['key'] ] = $contact; }
    }
    if ( $contacts ) {
        $out .= '<nav class="vc-footer-details__column vc-footer-details__contacts' . ( count( $contacts ) > 3 ? ' has-many-contacts' : '' ) . '" aria-label="' . esc_attr( $s['footerContactTitle'] ?? 'Connect with us' ) . '"><h2>' . esc_html( $s['footerContactTitle'] ?? 'Connect with us' ) . '</h2><ul>';
        foreach ( $contacts as $contact ) {
            $external = in_array( $contact['platform'], array( 'email', 'phone' ), true ) ? '' : ' target="_blank" rel="noopener noreferrer"';
            $out .= '<li><a href="' . $contact['url'] . '"' . $external . '><span class="vc-footer-details__orb" data-platform="' . esc_attr( $contact['platform'] ) . '">' . vc_footer_details_icon( $contact['platform'] ) . '</span><span>' . esc_html( $contact['label'] ) . '</span>' . ( $external ? '<span class="screen-reader-text"> (opens in a new tab)</span>' : '' ) . '</a></li>';
        }
        $out .= '</ul></nav>';
    }
    $out .= '</div>';
    if ( ! empty( $s['showSubscribe'] ) && class_exists( '\PeptideCore\Modules\Registry' ) && \PeptideCore\Modules\Registry::get( 'subscribe' ) ) {
        $out .= \PeptideCore\Modules\Renderer::render( 'subscribe', array( 'settings' => array( 'title' => $s['subscribeTitle'] ?? '' ) ), $context );
    }
    $out .= '<div class="vc-footer-details__bottom">';
    if ( ! empty( $s['showPolicies'] ) ) { $out .= '<nav aria-label="Policies">' . vc_footer_details_policies() . '</nav>'; }
    if ( '' !== trim( $s['demoNotice'] ?? '' ) ) { $out .= '<p class="vc-footer-details__notice">' . esc_html( $s['demoNotice'] ) . '</p>'; }
    $out .= '<p>' . esc_html( strtr( $s['copyright'] ?? '', array( '{year}' => wp_date( 'Y' ), '{store_name}' => $brand ) ) ) . '</p></div></div></footer>';
    if ( ! empty( $s['mobileNav'] ) && ! empty( $s['mobileLinks'] ) ) {
        $out .= '<nav class="pc-mobile-fixed' . ( 'tablet' === ( $s['mobileNavSize'] ?? 'phone' ) ? ' includes-tablet' : '' ) . '" style="--pc-mobile-nav-height:' . (int) ( $s['mobileNavHeight'] ?? 0 ) . 'px;--pc-mobile-nav-icon:' . (int) ( $s['mobileNavIconSize'] ?? 20 ) . 'px;--pc-mobile-nav-font:' . (int) ( $s['mobileNavFontSize'] ?? 14 ) . 'px" aria-label="Mobile navigation">';
        foreach ( $s['mobileLinks'] as $row ) {
            $link = $row['settings'] ?? array(); $url = vc_footer_details_url( $link['url'] ?? '' );
            if ( false === ( $row['enabled'] ?? true ) || '' === $url ) { continue; }
            $out .= '<a href="' . $url . '">' . vc_demo_site_part_icon( $link['icon'] ?? 'arrow' ) . '<span>' . esc_html( $link['label'] ?? '' ) . '</span></a>';
        }
        $out .= '</nav>';
    }
    return $out;
}
