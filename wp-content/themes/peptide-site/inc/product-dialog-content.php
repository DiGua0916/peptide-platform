<?php
/** Read-only product information inside the native Woo purchase dialog. */
defined( 'ABSPATH' ) || exit;

/** Capture existing tab callbacks; reviews are published, read-only content. */
function vc_product_dialog_tabs( WC_Product $product ): string {
    $tabs = apply_filters( 'woocommerce_product_tabs', array() );
    $html = '';
    $short = apply_filters( 'woocommerce_short_description', $product->get_short_description() );
    if ( is_string( $short ) && '' !== trim( $short ) ) {
        $html .= '<details class="vc-product-dialog-panel" data-vc-dialog-tab="overview"><summary>'
            . esc_html__( 'Overview', 'peptide-site' ) . '</summary><div class="vc-product-dialog-panel__body">' . $short . '</div></details>';
    }
    foreach ( is_array( $tabs ) ? $tabs : array() as $key => $tab ) {
        if ( ! is_array( $tab ) || ! is_callable( $tab['callback'] ?? null ) ) { continue; }
        if ( 'reviews' === $key || 'comments_template' === $tab['callback'] ) {
            $comments = get_comments( array( 'post_id' => $product->get_id(), 'status' => 'approve', 'type__in' => array( 'review', 'comment', '' ), 'orderby' => 'comment_date_gmt', 'order' => 'DESC' ) );
            $body = '';
            foreach ( $comments as $comment ) {
                // Author names and approved text are public; email/IP and forms are never emitted.
                $rating = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
                $body .= '<article class="vc-product-dialog-review"><p><strong>' . esc_html( get_comment_author( $comment ) )
                    . '</strong> <time datetime="' . esc_attr( get_comment_date( 'c', $comment ) ) . '">'
                    . esc_html( get_comment_date( '', $comment ) ) . '</time></p>'
                    . ( $rating > 0 && $rating <= 5 ? wc_get_rating_html( $rating ) : '' ) . '<div>'
                    . apply_filters( 'comment_text', $comment->comment_content, $comment, array() ) . '</div></article>';
            }
            if ( '' === $body ) { $body = '<p>' . esc_html__( 'There are no reviews yet.', 'woocommerce' ) . '</p>'; }
            $html .= '<details class="vc-product-dialog-panel" data-vc-dialog-tab="reviews"><summary>'
                . esc_html( wp_strip_all_tags( $tab['title'] ?? __( 'Reviews', 'woocommerce' ) ) )
                . '</summary><div class="vc-product-dialog-panel__body">' . $body . '</div></details>';
            continue;
        }
        $level = ob_get_level();
        ob_start();
        try {
            call_user_func( $tab['callback'], $key, $tab );
            $body = (string) ob_get_contents();
        } finally {
            while ( ob_get_level() > $level ) { ob_end_clean(); }
        }
        if ( '' === trim( $body ) ) { continue; }
        $title = wp_strip_all_tags( (string) ( $tab['title'] ?? $key ) );
        $html .= '<details class="vc-product-dialog-panel" data-vc-dialog-tab="' . esc_attr( (string) $key ) . '"><summary>' . esc_html( $title )
            . '</summary><div class="vc-product-dialog-panel__body">' . $body . '</div></details>';
    }
    return $html;
}

/** Only published content blocks are portable; commerce/global blocks keep their native route. */
function vc_product_dialog_layout( WC_Product $product, string $tabs ): array {
    $result = array( 'html' => $tabs, 'document' => '', 'omitted' => array() );
    if ( ! class_exists( '\PeptideCore\Modules\Layout' ) || ! class_exists( '\PeptideCore\Templates' ) ) { return $result; }
    $template = \PeptideCore\Templates::current_id();
    if ( ! $template ) { return $result; }
    $modules = array(); $document_id = 0;
    foreach ( array( 'product_' . $product->get_id(), 'product' ) as $document ) {
        $modules = \PeptideCore\Modules\Layout::published_modules( $document, $template );
        if ( $modules ) {
            $result['document'] = $document;
            $document_id = \PeptideCore\Templates::document_id( $template, $document, false );
            break;
        }
    }
    if ( ! $modules ) { return $result; }
    // These are content-only renderers. Do not duplicate cart forms, global menus,
    // raw custom HTML, account widgets or modules needing a second modal player.
    $portable = array( 'image', 'image_row', 'image_grid', 'mosaic_3', 'mosaic_4', 'mosaic_5',
        'media_text', 'section_heading', 'media_text_list', 'card_list', 'slogan',
        'timeline', 'rich_text', 'faq', 'info_list', 'logo_list', 'spacer', 'text_block', 'cta' );
    // Rich media approval is checked against the complete published snapshot,
    // not a one-row subset (which would discard approved layout-owned images).
    $media = \PeptideCore\Modules\Rich_Media::published( $document_id, $modules );
    \PeptideCore\Modules\Renderer::$document_id = $document_id;
    $html = ''; $has_context = false;
    foreach ( $modules as $row ) {
        $type = \PeptideCore\Modules\Registry::type_for_block( (string) ( $row['block'] ?? '' ) );
        if ( 'context_content' === $type ) {
            if ( $has_context ) { continue; }
            $has_context = true;
            // Do not call Design_Pages::native_content(): it includes the whole
            // original template, which would add another gallery/purchase form.
            $module = \PeptideCore\Modules\Renderer::normalize( $type, (array) ( $row['attrs'] ?? array() ) );
            if ( ! $module || ! $module['enabled'] || ( ! $module['visibility']['desktop'] && ! $module['visibility']['mobile'] ) ) { continue; }
            $classes = 'vc-product-dialog-native';
            if ( ! $module['visibility']['desktop'] ) { $classes .= ' vc-hide-desktop'; }
            if ( ! $module['visibility']['mobile'] ) { $classes .= ' vc-hide-mobile'; }
            $html .= '<div class="' . esc_attr( $classes ) . '">' . $tabs . '</div>';
        } elseif ( in_array( $type, $portable, true ) ) {
            $html .= \PeptideCore\Modules\Renderer::render( $type, (array) ( $row['attrs'] ?? array() ), 'public', $media );
        } else {
            $result['omitted'][] = $type ?: (string) ( $row['block'] ?? 'unknown' );
        }
    }
    $result['html'] = ( $has_context ? '' : $tabs ) . $html;
    $result['omitted'] = array_values( array_unique( $result['omitted'] ) );
    return $result;
}

/** Passive, sanitized content with document-local identifiers and references. */
function vc_product_dialog_safe_html( string $html, string $prefix ): string {
    if ( ! class_exists( 'DOMDocument' ) ) { return ''; }
    $document = new DOMDocument( '1.0', 'UTF-8' );
    $previous = libxml_use_internal_errors( true );
    try {
        $document->loadHTML( '<?xml encoding="UTF-8"><!doctype html><html><body>' . $html . '</body></html>', LIBXML_NONET );
        $xpath = new DOMXPath( $document );
        foreach ( $xpath->query( '//script|//style|//iframe|//object|//embed|//form|//input|//button|//select|//textarea|//meta|//base|//link' ) as $node ) {
            $node->parentNode->removeChild( $node );
        }
        $ids = array(); $used = array();
        foreach ( $xpath->query( '//*[@id]' ) as $node ) {
            $old = $node->getAttribute( 'id' );
            $new = $prefix . sanitize_html_class( $old, 'item' );
            $base = $new; $suffix = 1;
            while ( isset( $used[ $new ] ) ) { $new = $base . '-' . ++$suffix; }
            $used[ $new ] = true;
            if ( ! isset( $ids[ $old ] ) ) { $ids[ $old ] = $new; }
            $node->setAttribute( 'id', $new );
        }
        $refs = array( 'for', 'headers', 'list', 'aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns', 'aria-activedescendant', 'aria-details', 'aria-errormessage' );
        foreach ( $xpath->query( '//*' ) as $node ) {
            $node->removeAttribute( 'autofocus' ); $node->removeAttribute( 'autoplay' );
            if ( 'details' === $node->nodeName && $node->hasAttribute( 'name' ) ) { $node->setAttribute( 'name', $prefix . $node->getAttribute( 'name' ) ); }
            foreach ( $refs as $attr ) {
                if ( ! $node->hasAttribute( $attr ) ) { continue; }
                $values = preg_split( '/\s+/', trim( $node->getAttribute( $attr ) ) );
                $node->setAttribute( $attr, implode( ' ', array_map( static fn( $id ) => $ids[ $id ] ?? $id, $values ) ) );
            }
            $href = $node->getAttribute( 'href' );
            if ( str_starts_with( $href, '#' ) && isset( $ids[ substr( $href, 1 ) ] ) ) { $node->setAttribute( 'href', '#' . $ids[ substr( $href, 1 ) ] ); }
            if ( '_blank' === $node->getAttribute( 'target' ) ) { $node->setAttribute( 'rel', 'noopener noreferrer' ); }
        }
        $out = ''; $body = $document->getElementsByTagName( 'body' )->item( 0 );
        if ( $body ) { foreach ( $body->childNodes as $child ) { $out .= $document->saveHTML( $child ); } }
        return wp_kses_post( $out );
    } finally {
        libxml_clear_errors(); libxml_use_internal_errors( $previous );
    }
}

/** Public product content only; no mutations, draft layouts, cart forms or checkout. */
function vc_product_dialog_content( $product ): string {
    if ( ! $product instanceof WC_Product || ! class_exists( '\PeptideCore\Modules\Products' )
        || ! \PeptideCore\Modules\Products::public_catalog_product( $product ) ) { return ''; }
    $keys = array( 'post', 'product', 'wp_query', 'wp_the_query', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );
    $saved = array();
    foreach ( $keys as $key ) { $saved[ $key ] = array( array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null ); }
    $renderer_document = class_exists( '\PeptideCore\Modules\Renderer' ) ? \PeptideCore\Modules\Renderer::$document_id : null;
    try {
        $post = get_post( $product->get_id() );
        if ( ! $post instanceof WP_Post ) { return ''; }
        // Build a read-only singular context without executing another main query.
        $query = new WP_Query();
        $query->posts = array( $post ); $query->post = $post;
        $query->post_count = 1; $query->found_posts = 1; $query->current_post = 0;
        $query->queried_object = $post; $query->queried_object_id = $post->ID;
        $query->is_single = true; $query->is_singular = true; $query->in_the_loop = true;
        $query->query_vars = array( 'post_type' => 'product', 'p' => $post->ID );
        $GLOBALS['wp_query'] = $query; $GLOBALS['wp_the_query'] = $query;
        $GLOBALS['post'] = $post; $GLOBALS['product'] = $product;
        setup_postdata( $post ); $GLOBALS['more'] = 1;
        $layout = vc_product_dialog_layout( $product, vc_product_dialog_tabs( $product ) );
        $prefix = wp_unique_id( 'vc-dialog-' . $product->get_id() . '-' ) . '-';
        $html = vc_product_dialog_safe_html( $layout['html'], $prefix );
        if ( '' === trim( $html ) ) { return ''; }
        return '<div class="vc-product-dialog-content" data-vc-dialog-document="' . esc_attr( $layout['document'] )
            . '" data-vc-dialog-omitted="' . esc_attr( wp_json_encode( $layout['omitted'] ) ) . '">' . $html . '</div>';
    } finally {
        if ( null !== $renderer_document ) { \PeptideCore\Modules\Renderer::$document_id = $renderer_document; }
        foreach ( $saved as $key => [ $existed, $value ] ) {
            if ( $existed ) { $GLOBALS[ $key ] = $value; } else { unset( $GLOBALS[ $key ] ); }
        }
    }
}
