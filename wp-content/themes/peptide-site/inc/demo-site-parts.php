<?php
/** Optional presentation for the approved homepage, using the existing site-part documents. */
defined( 'ABSPATH' ) || exit;

add_filter( 'peptide_core_layout_definitions', 'vc_demo_site_parts_definitions', 40 );
add_filter( 'peptide_core_module_html', 'vc_demo_site_parts_render', 30, 4 );
add_action( 'wp_enqueue_scripts', 'vc_demo_site_parts_assets', 40 );

function vc_demo_site_parts_definitions( array $defs ): array {
    if ( ! class_exists( '\PeptideCore\Modules\Fields' ) ) { return $defs; }
    $f = static fn( $type, $key, $label, $default = null, $extra = array() ) => \PeptideCore\Modules\Fields::f( $type, $key, $label, $default, $extra );
    foreach ( array( 'site_header', 'site_footer' ) as $type ) {
        if ( empty( $defs[ $type ] ) ) { continue; }
        array_unshift( $defs[ $type ]['settings'], $f( 'select', 'presentation', 'Presentation', 'standard', array( 'options' => array( 'standard' => 'Standard', 'demo' => 'VialCove editorial' ) ) ) );
        $defs[ $type ]['settings'][] = $f( 'repeater', 'demoLinks', 'Editorial navigation links', array(), array(
            'max' => 12, 'show_if' => array( 'presentation' => 'demo' ),
            'help' => 'Used when no navigation menu is selected. Links may point to homepage sections or full pages.',
            'fields' => array( $f( 'text', 'label', 'Label', '' ), $f( 'url', 'url', 'URL', '' ) ),
        ) );
    }
    if ( isset( $defs['site_footer'] ) ) {
        $defs['site_footer']['settings'][] = $f( 'text', 'demoNotice', 'Footer research notice', '', array( 'show_if' => array( 'presentation' => 'demo' ) ) );
        if ( ! in_array( 'logoWidth', array_column( $defs['site_footer']['settings'], 'key' ), true ) ) {
            $defs['site_footer']['settings'][] = $f( 'number', 'logoWidth', 'Logo width', 204, array( 'device' => true, 'min' => 48, 'max' => 400, 'show_if' => array( 'presentation' => 'demo' ) ) );
        }
    }
    return $defs;
}

function vc_demo_site_parts_assets(): void {
    $path = get_template_directory() . '/assets/';
    $url = get_template_directory_uri() . '/assets/';
    $deps = array();
    if ( defined( 'PEPTIDE_CORE_FILE' ) ) {
        wp_enqueue_style( 'peptide-site-fonts', plugins_url( 'assets/site-fonts.css', PEPTIDE_CORE_FILE ), array(), defined( 'PEPTIDE_CORE_VERSION' ) ? PEPTIDE_CORE_VERSION : null );
        $deps[] = 'peptide-site-fonts';
    }
    wp_enqueue_style( 'vialcove-demo-site-parts', $url . 'demo-site-parts.css', $deps, (string) filemtime( $path . 'demo-site-parts.css' ) );
    wp_enqueue_script( 'vialcove-demo-site-parts', $url . 'demo-site-parts.js', array(), (string) filemtime( $path . 'demo-site-parts.js' ), true );
}

/** The configured menu remains authoritative, including an intentionally empty menu. */
function vc_demo_site_part_menu( array $settings, string $location ): array {
    $id = absint( $settings['menuId'] ?? 0 );
    if ( $id && class_exists( '\PeptideCore\Menus' ) ) {
        $menu = \PeptideCore\Menus::public_menu( $id );
        return is_array( $menu ) ? $menu : array();
    }
    $rows = $settings['demoLinks'] ?? array();
    if ( $rows ) {
        $menu = array();
        foreach ( $rows as $row ) {
            if ( false === ( $row['enabled'] ?? true ) ) { continue; }
            $link = $row['settings'] ?? array();
            if ( empty( $link['label'] ) || empty( $link['url'] ) ) { continue; }
            $menu[] = array( 'title' => $link['label'], 'url' => $link['url'] );
        }
        return $menu;
    }
    return function_exists( 'vc_site_menu' ) ? vc_site_menu( $location ) : array();
}

function vc_demo_site_part_nav( array $items, int $depth = 0 ): string {
    if ( ! $items || $depth > 5 ) { return ''; }
    $out = '<ul class="vc-demo-nav-list">';
    foreach ( $items as $item ) {
        $children = is_array( $item['children'] ?? null ) ? $item['children'] : array();
        $out .= '<li>';
        if ( $children ) {
            $out .= '<details class="vc-demo-nav-group"><summary>' . esc_html( $item['title'] ?? '' ) . '<span aria-hidden="true">⌄</span></summary><div class="vc-demo-nav-children">';
            if ( ! empty( $item['url'] ) && 'none' !== ( $item['type'] ?? '' ) ) { $out .= vc_menu_link( $item, 'vc-demo-nav-overview', false ); }
            $out .= vc_demo_site_part_nav( $children, $depth + 1 ) . '</div></details>';
        } else {
            $out .= vc_menu_link( $item, '', false );
        }
        $out .= '</li>';
    }
    return $out . '</ul>';
}

function vc_demo_site_part_logo( array $s ): string {
    $picture = function_exists( 'vc_module_picture' ) ? vc_module_picture( (array) ( $s['logo'] ?? array() ), 'vc-demo-logo', '204px', array( 'loading' => 'eager' ) ) : '';
    if ( '' !== $picture ) { return $picture; }
    if ( function_exists( 'vc_brand_logo' ) ) { ob_start(); vc_brand_logo( true ); return (string) ob_get_clean(); }
    return esc_html( $s['brandName'] ?? get_bloginfo( 'name' ) );
}

function vc_demo_site_part_color( string $value, string $fallback ): string {
    return sanitize_hex_color( $value ) ?: $fallback;
}

function vc_demo_site_part_widths( array $s ): string {
    $width = $s['logoWidth'] ?? 204;
    $desktop = is_array( $width ) ? ( $width['desktop'] ?? 204 ) : $width;
    $mobile = is_array( $width ) ? ( $width['mobile'] ?? $desktop ) : $width;
    return '--vc-demo-logo-width:' . max( 48, min( 400, (int) $desktop ) ) . 'px;--vc-demo-logo-mobile:' . max( 48, min( 400, (int) $mobile ) ) . 'px;';
}

function vc_demo_site_part_icon( string $name ): string {
    if ( '' === $name ) { return ''; }
    if ( 'close' === $name ) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m5 5 14 14M19 5 5 19"/></svg>'; }
    // The builder's existing icon choices must also work on optional social / fixed links.
    $paths = array(
        'check' => 'm5 12 4 4L19 6', 'mail' => 'M3 5h18v14H3z M3 5l9 8 9-8',
        'phone' => 'M5 3h4l2 5-3 2c2 3 3 4 6 6l2-3 5 2v4c-1 4-5 3-10-1S2 8 5 3',
        'location' => 'M12 22S4 15 4 9a8 8 0 0 1 16 0c0 6-8 13-8 13z M9 9a3 3 0 1 0 6 0 3 3 0 0 0-6 0',
        'star' => 'm12 2 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z',
        'clock' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M12 7v5l4 2',
        'box' => 'm3 7 9-5 9 5v10l-9 5-9-5z M3 7l9 5 9-5 M12 12v10',
        'chat' => 'M3 3h18v14H8l-5 4z',
        'globe' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M3 12h18 M12 3c5 5 5 13 0 18-5-5-5-13 0-18',
        'shield' => 'm12 2 9 4v6c0 6-9 10-9 10S3 18 3 12V6z',
        'lock' => 'M5 10h14v12H5z M8 10V6a4 4 0 0 1 8 0v4',
        'truck' => 'M2 5h13v12H2z M15 9h4l3 4v4h-7 M5 17a2 2 0 1 0 4 0 M16 17a2 2 0 1 0 4 0',
        'flask' => 'M8 2h8 M10 2v7l-6 11c-1 2 17 2 16 0L14 9V2 M7 15h10',
        'document' => 'M5 2h10l4 4v16H5z M8 11h8 M8 15h8',
    );
    if ( isset( $paths[ $name ] ) ) { return '<svg class="pc-design-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="' . esc_attr( $paths[ $name ] ) . '"/></svg>'; }
    return vc_icon( $name );
}

function vc_demo_site_parts_render( $html, array $module, string $context, string $device ) {
    $s = $module['settings'] ?? array();
    if ( 'demo' !== ( $s['presentation'] ?? 'standard' ) ) { return $html; }
    if ( 'site_header' === $module['type'] ) { return vc_demo_site_header( $s ); }
    if ( 'site_footer' === $module['type'] ) { return vc_demo_site_footer( $module, $context ); }
    return $html;
}

function vc_demo_site_header( array $s ): string {
    $home = home_url( '/' );
    $shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : $home;
    $account = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
    $menu = vc_demo_site_part_menu( $s, 'header' );
    $secondary = array();
    if ( ! empty( $s['secondaryMenuId'] ) && class_exists( '\PeptideCore\Menus' ) ) { $secondary = \PeptideCore\Menus::public_menu( (int) $s['secondaryMenuId'] ) ?? array(); }
    $id = wp_unique_id( 'vc-demo-header-' );
    $styles = vc_demo_site_part_widths( $s ) . '--vc-demo-part-bg:' . vc_demo_site_part_color( $s['headerBackground'] ?? '', '#ffffff' ) . ';--vc-demo-part-ink:' . vc_demo_site_part_color( $s['headerText'] ?? '', '#17343e' ) . ';';
    $out = '';
    if ( ! empty( $s['announcement'] ) && ( empty( $s['announcementHomeOnly'] ) || is_front_page() ) ) {
        $out .= '<div class="vc-demo-announcement" style="background:' . esc_attr( vc_demo_site_part_color( $s['announcementBackground'] ?? '', '#17343e' ) ) . ';color:' . esc_attr( vc_demo_site_part_color( $s['announcementColor'] ?? '', '#ffffff' ) ) . '">' . wp_kses_post( $s['announcementText'] ?? '' ) . '</div>';
    }
    $out .= '<header class="vc-demo-header' . ( ! empty( $s['sticky'] ) ? ' vc-demo-header--sticky' : '' ) . '" style="' . esc_attr( $styles ) . '" data-vc-demo-header><div class="vc-demo-part-wrap vc-demo-header-inner">';
    $out .= '<a class="vc-demo-brand" href="' . esc_url( $home ) . '" aria-label="' . esc_attr( ( $s['brandName'] ?? 'VialCove' ) . ' home' ) . '">' . vc_demo_site_part_logo( $s ) . '</a>';
    $out .= '<nav class="vc-demo-desktop-nav" aria-label="Main navigation">' . vc_demo_site_part_nav( $menu ) . '</nav><div class="vc-demo-header-actions">';
    if ( ! empty( $s['showSearch'] ) ) { $out .= '<a class="vc-demo-icon-button" href="' . esc_url( $shop ) . '" data-vc-demo-search aria-label="Search products" aria-haspopup="dialog" aria-controls="' . esc_attr( $id . '-search' ) . '">' . vc_demo_site_part_icon( 'search' ) . '</a>'; }
    if ( ! empty( $s['showAccount'] ) ) { $out .= '<a class="vc-demo-icon-button vc-demo-account" href="' . esc_url( $account ) . '" aria-label="My account">' . vc_demo_site_part_icon( 'user' ) . '</a>'; }
    if ( ! empty( $s['showCart'] ) ) {
        $count = function_exists( 'WC' ) && WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0;
        $out .= '<a class="cart-link vc-demo-icon-button vc-demo-bag" href="' . esc_url( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : $home ) . '" aria-label="Open shopping bag">' . vc_demo_site_part_icon( 'bag' ) . '<span class="vc-cart-count">' . $count . '</span></a>';
    }
    $out .= '<button class="vc-demo-icon-button vc-demo-menu-trigger" type="button" data-vc-demo-menu aria-label="Open menu" aria-expanded="false" aria-haspopup="dialog" aria-controls="' . esc_attr( $id . '-menu' ) . '"><span class="vc-demo-menu-lines" aria-hidden="true"></span></button></div></div>';
    if ( $secondary ) { $out .= '<nav class="vc-demo-secondary vc-demo-part-wrap" aria-label="Secondary navigation">' . vc_demo_site_part_nav( $secondary ) . '</nav>'; }
    $out .= '<dialog id="' . esc_attr( $id . '-menu' ) . '" class="vc-demo-part-dialog vc-demo-menu-dialog" aria-labelledby="' . esc_attr( $id . '-menu-title' ) . '"><div class="vc-demo-dialog-head"><span id="' . esc_attr( $id . '-menu-title' ) . '">Menu</span><button type="button" class="vc-demo-icon-button" data-vc-demo-close aria-label="Close menu">' . vc_demo_site_part_icon( 'close' ) . '</button></div><nav aria-label="Mobile navigation">' . vc_demo_site_part_nav( $menu ) . '</nav>';
    if ( $secondary ) { $out .= '<nav class="vc-demo-menu-secondary" aria-label="Secondary mobile navigation">' . vc_demo_site_part_nav( $secondary ) . '</nav>'; }
    if ( ! empty( $s['showAccount'] ) ) { $out .= '<a class="vc-demo-menu-account" href="' . esc_url( $account ) . '">My account ' . vc_demo_site_part_icon( 'user' ) . '</a>'; }
    $out .= '</dialog>';
    if ( ! empty( $s['showSearch'] ) ) {
        $out .= '<dialog id="' . esc_attr( $id . '-search' ) . '" class="vc-demo-part-dialog vc-demo-search-dialog" aria-labelledby="' . esc_attr( $id . '-search-title' ) . '"><div class="vc-demo-dialog-head"><h2 id="' . esc_attr( $id . '-search-title' ) . '">Find your peptide.</h2><button type="button" class="vc-demo-icon-button" data-vc-demo-close aria-label="Close search">' . vc_demo_site_part_icon( 'close' ) . '</button></div><form role="search" method="get" action="' . esc_url( $home ) . '"><label class="screen-reader-text" for="' . esc_attr( $id . '-query' ) . '">Search products</label><input id="' . esc_attr( $id . '-query' ) . '" type="search" name="s" placeholder="' . esc_attr( $s['searchPlaceholder'] ?? 'Search products' ) . '" required><input type="hidden" name="post_type" value="product"><button type="submit">Search ' . vc_demo_site_part_icon( 'arrow' ) . '</button></form></dialog>';
    }
    return $out . '<noscript><nav class="vc-demo-no-script vc-demo-part-wrap" aria-label="Navigation">' . vc_demo_site_part_nav( $menu ) . '</nav></noscript></header>';
}

function vc_demo_site_footer( array $module, string $context ): string {
    $s = $module['settings'];
    $styles = vc_demo_site_part_widths( $s ) . '--vc-demo-part-bg:' . vc_demo_site_part_color( $s['footerBackground'] ?? '', '#f7f9f9' ) . ';--vc-demo-part-ink:' . vc_demo_site_part_color( $s['footerText'] ?? '', '#17343e' ) . ';';
    $out = '<footer class="vc-demo-footer" style="' . esc_attr( $styles ) . '"><div class="vc-demo-part-wrap"><div class="vc-demo-footer-top"><a class="vc-demo-brand" href="' . esc_url( home_url( '/' ) ) . '" aria-label="' . esc_attr( ( $s['brandName'] ?? 'VialCove' ) . ' home' ) . '">' . vc_demo_site_part_logo( $s ) . '</a><div class="vc-demo-footer-description">' . wp_kses_post( $s['description'] ?? '' ) . '</div><nav class="vc-demo-footer-nav" aria-label="Footer navigation">' . vc_demo_site_part_nav( vc_demo_site_part_menu( $s, 'footer' ) );
    if ( ! empty( $s['showPolicies'] ) ) {
        $privacy = get_privacy_policy_url();
        $terms = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'terms' ) : '';
        if ( $privacy || $terms ) {
            $out .= '<ul class="vc-demo-nav-list vc-demo-policies">';
            if ( $privacy ) { $out .= '<li><a href="' . esc_url( $privacy ) . '">Privacy policy</a></li>'; }
            if ( $terms ) { $out .= '<li><a href="' . esc_url( $terms ) . '">Terms</a></li>'; }
            $out .= '</ul>';
        }
    }
    $out .= '</nav></div>';
    if ( ! empty( $s['showSubscribe'] ) && class_exists( '\PeptideCore\Modules\Registry' ) && \PeptideCore\Modules\Registry::get( 'subscribe' ) ) { $out .= \PeptideCore\Modules\Renderer::render( 'subscribe', array( 'settings' => array( 'title' => $s['subscribeTitle'] ?? '' ) ), $context ); }
    $social = '';
    foreach ( $module['items'] ?? array() as $item ) {
        $link = $item['settings'] ?? array();
        if ( false === ( $item['enabled'] ?? true ) || empty( $link['url'] ) ) { continue; }
        $social .= '<a rel="noopener noreferrer" href="' . esc_url( $link['url'] ) . '">' . vc_demo_site_part_icon( $link['icon'] ?? '' ) . esc_html( $link['label'] ?? '' ) . '</a>';
    }
    if ( $social ) { $out .= '<nav class="vc-demo-social" aria-label="Social links">' . $social . '</nav>'; }
    $out .= '<div class="vc-demo-footer-bottom"><p>' . esc_html( $s['demoNotice'] ?? '' ) . '</p><p>' . esc_html( strtr( $s['copyright'] ?? '', array( '{year}' => wp_date( 'Y' ), '{store_name}' => $s['brandName'] ?? get_bloginfo( 'name' ) ) ) ) . '</p></div></div></footer>';
    // Keep the native optional fixed navigation and its editable sizing contract.
    if ( ! empty( $s['mobileNav'] ) && ! empty( $s['mobileLinks'] ) ) {
        $out .= '<nav class="pc-mobile-fixed' . ( 'tablet' === ( $s['mobileNavSize'] ?? 'phone' ) ? ' includes-tablet' : '' ) . '" style="--pc-mobile-nav-height:' . (int) ( $s['mobileNavHeight'] ?? 0 ) . 'px;--pc-mobile-nav-icon:' . (int) ( $s['mobileNavIconSize'] ?? 20 ) . 'px;--pc-mobile-nav-font:' . (int) ( $s['mobileNavFontSize'] ?? 14 ) . 'px" aria-label="Mobile navigation">';
        foreach ( $s['mobileLinks'] as $row ) {
            $link = $row['settings'] ?? array();
            if ( false === ( $row['enabled'] ?? true ) || empty( $link['url'] ) ) { continue; }
            $out .= '<a href="' . esc_url( $link['url'] ) . '">' . vc_demo_site_part_icon( $link['icon'] ?? 'arrow' ) . '<span>' . esc_html( $link['label'] ?? '' ) . '</span></a>';
        }
        $out .= '</nav>';
    }
    return $out;
}
