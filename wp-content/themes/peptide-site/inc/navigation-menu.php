<?php
defined( 'ABSPATH' ) || exit;

/** Null means unconfigured; an empty assigned menu intentionally stays empty. */
function vc_resolve_navigation_menu( ?array $menu, string $location ): array {
    if ( null !== $menu ) {
        return $menu;
    }
    if ( 'footer' === $location ) {
        return [
            [ 'title' => 'Explore the collection', 'url' => vc_url( 'shop' ) ],
            [ 'title' => 'Find a batch report', 'url' => vc_url( 'reports' ) ],
            [ 'title' => 'My account', 'url' => vc_url( 'account' ) ],
        ];
    }
    return [
        [ 'title' => 'Products', 'type' => 'shop', 'url' => vc_url( 'shop' ) ],
        [ 'title' => 'Research', 'type' => 'none', 'children' => [
            [ 'title' => 'Certificates & reports', 'description' => 'Browse documentation by product', 'url' => vc_url( 'certificates' ) ],
            [ 'title' => 'FAQ', 'description' => 'Answers to common questions', 'url' => vc_url( 'faq' ) ],
        ] ],
        [ 'title' => 'About', 'type' => 'none', 'children' => [
            [ 'title' => 'About us', 'description' => 'The people and places behind our vision', 'url' => vc_url( 'about-us' ) ],
            [ 'title' => 'Quality standards', 'description' => 'What to look for in the documentation', 'url' => vc_url( 'quality-standards' ) ],
            [ 'title' => 'Methodology', 'description' => 'Follow the record from product to batch', 'url' => vc_url( 'methodology' ) ],
        ] ],
    ];
}

function vc_site_menu( string $location ): array {
    static $menus = [];
    if ( ! array_key_exists( $location, $menus ) ) {
        $configured = function_exists( 'peptide_core_get_menu' ) ? peptide_core_get_menu( $location ) : null;
        $menus[ $location ] = vc_resolve_navigation_menu( $configured, $location );
    }
    return $menus[ $location ];
}

function vc_menu_attributes( array $item, bool $link = false ): string {
    $html = '';
    if ( ! empty( $item['attr_title'] ) ) {
        $html .= ' title="' . esc_attr( $item['attr_title'] ) . '"';
    }
    if ( ! empty( $item['style'] ) ) {
        $style = safecss_filter_attr( $item['style'] );
        if ( $style ) {
            $html .= ' style="' . esc_attr( $style ) . '"';
        }
    }
    if ( $link ) {
        $target = $item['target'] ?? '';
        if ( in_array( $target, [ '_blank', '_self' ], true ) ) {
            $html .= ' target="' . esc_attr( $target ) . '"';
        }
        $rels = array_intersect( preg_split( '/\s+/', trim( $item['rel'] ?? '' ) ), [ 'nofollow', 'noopener', 'noreferrer', 'sponsored', 'ugc' ] );
        if ( '_blank' === $target ) {
            $rels[] = 'noopener';
        }
        if ( $rels ) {
            $html .= ' rel="' . esc_attr( implode( ' ', array_unique( $rels ) ) ) . '"';
        }
        if ( ! empty( $item['current'] ) ) {
            $html .= ' aria-current="page"';
        }
    }
    return $html;
}

function vc_menu_label( array $item ): string {
    $html = '<span class="vc-menu-label">';
    $icon = $item['icon'] ?? null;
    if ( is_array( $icon ) && ! empty( $icon['url'] ) && esc_url( $icon['url'] ) ) {
        $html .= '<img class="vc-menu-icon" src="' . esc_url( $icon['url'] ) . '" alt="' . esc_attr( $icon['alt'] ?? '' ) . '"';
        if ( ! empty( $icon['width'] ) && ! empty( $icon['height'] ) ) {
            $html .= ' width="' . absint( $icon['width'] ) . '" height="' . absint( $icon['height'] ) . '"';
        }
        $html .= ' decoding="async">';
    }
    return $html . '<span class="vc-menu-title">' . esc_html( $item['title'] ?? '' ) . '</span></span>';
}

function vc_menu_link( array $item, string $class = '', bool $description = true ): string {
    $url = 'none' === ( $item['type'] ?? '' ) ? '' : esc_url( $item['url'] ?? '' );
    $tag = $url ? 'a' : 'span';
    $html = '<' . $tag . ' class="' . esc_attr( trim( 'vc-menu-entry ' . $class ) ) . '"';
    if ( $url ) {
        $html .= ' href="' . $url . '"';
    }
    $html .= vc_menu_attributes( $item, (bool) $url ) . '>' . vc_menu_label( $item );
    if ( $description && ! empty( $item['description'] ) ) {
        $html .= '<span class="vc-menu-description">' . esc_html( $item['description'] ) . '</span>';
    }
    return $html . '</' . $tag . '>';
}
