<?php
/** Checkout editor previews stay on native checkout; business content is never replaced. */
defined( 'ABSPATH' ) || exit;

/** Resolve only the authorized checkout document, without creating/seeding it. */
function vc_checkout_layout_document_template( int $document_id ): int {
    if ( ! class_exists( '\\PeptideCore\\Templates' ) || $document_id < 1
        || ! current_user_can( \PeptideCore\Templates::CAP_EDIT ) || ! current_user_can( 'edit_post', $document_id )
        || \PeptideCore\Templates::LAYOUT !== get_post_type( $document_id )
        || 'checkout' !== get_post_meta( $document_id, \PeptideCore\Templates::DOC_META, true ) ) { return 0; }
    $template = (int) wp_get_post_parent_id( $document_id );
    return \PeptideCore\Templates::exists( $template )
        && $document_id === \PeptideCore\Templates::document_id( $template, 'checkout', false ) ? $template : 0;
}

/** Canonical same-store checkout, retaining the store's configured permalink/query. */
function vc_checkout_layout_native_url(): string {
    if ( ! function_exists( 'wc_get_checkout_url' ) ) { return ''; }
    $url = wc_get_checkout_url();
    if ( ! is_string( $url ) || '' === $url ) { return ''; }
    $parts = wp_parse_url( $url ); $home = wp_parse_url( home_url( '/' ) );
    if ( ! is_array( $parts ) || ! is_array( $home ) || isset( $parts['user'] ) || isset( $parts['pass'] )
        || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true )
        || strtolower( $parts['host'] ?? '' ) !== strtolower( $home['host'] ?? '' )
        || (int) ( $parts['port'] ?? 0 ) !== (int) ( $home['port'] ?? 0 ) ) { return ''; }
    return $url;
}

/** Move a native preview URL to checkout while preserving its auth and draft parameters. */
function vc_checkout_layout_preview_url( int $template, string $source ): string {
    if ( ! class_exists( '\\PeptideCore\\Design_Pages' ) || ! class_exists( '\\PeptideCore\\Management_Host' ) ) { return ''; }
    $checkout = vc_checkout_layout_native_url();
    if ( '' === $checkout ) { return ''; }
    $target = \PeptideCore\Management_Host::enabled() ? \PeptideCore\Management_Host::management_link( $checkout ) : $checkout;
    $expected = \PeptideCore\Design_Pages::preview_url( $template, 'checkout' );
    $old = wp_parse_url( $source ); $native = wp_parse_url( $expected ); $new = wp_parse_url( $target );
    if ( ! is_array( $old ) || ! is_array( $native ) || ! is_array( $new ) || isset( $old['user'] ) || isset( $old['pass'] ) ) { return ''; }
    foreach ( array( 'scheme', 'host', 'port' ) as $part ) {
        if ( strtolower( (string) ( $old[ $part ] ?? '' ) ) !== strtolower( (string) ( $native[ $part ] ?? '' ) )
            || strtolower( (string) ( $old[ $part ] ?? '' ) ) !== strtolower( (string) ( $new[ $part ] ?? '' ) ) ) { return ''; }
    }
    if ( ! in_array( $old['path'] ?? '/', array( $native['path'] ?? '/', $new['path'] ?? '/' ), true ) ) { return ''; }
    parse_str( (string) ( $old['query'] ?? '' ), $args );
    if ( (string) $template !== ( $args[ \PeptideCore\Templates::PREVIEW_PARAM ] ?? null )
        || 'checkout' !== ( $args[ \PeptideCore\Design_Pages::PREVIEW_DOC ] ?? null )
        || '1' !== ( $args[ \PeptideCore\Templates::FRAME_PARAM ] ?? null )
        || ! is_string( $args[ \PeptideCore\Templates::NONCE_PARAM ] ?? null )
        || ! wp_verify_nonce( $args[ \PeptideCore\Templates::NONCE_PARAM ], 'pc_template_preview_' . $template ) ) { return ''; }
    parse_str( (string) ( $new['query'] ?? '' ), $target_args );
    foreach ( $target_args as $key => $value ) {
        if ( array_key_exists( $key, $args ) && $value !== $args[ $key ] ) { return ''; }
    }
    $result = add_query_arg( $args, $target );
    if ( isset( $old['fragment'] ) ) {
        $result = explode( '#', $result, 2 )[0] . '#' . $old['fragment'];
    }
    return $result;
}

/** Core has already set pcDesignEditor; append this before the same factory runs. */
function vc_checkout_layout_preview_editor_assets(): void {
    if ( ! is_admin() || 'pc-design-editor' !== ( $_GET['page'] ?? null )
        || ! is_scalar( $_GET['document'] ?? null ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $_GET['document'] )
        || ! wp_script_is( 'peptide-core-design-editor', 'enqueued' ) ) { return; }
    $document_id = (int) $_GET['document'];
    $template = vc_checkout_layout_document_template( $document_id );
    if ( ! $template ) { return; }
    $preview = vc_checkout_layout_preview_url( $template, \PeptideCore\Design_Pages::preview_url( $template, 'checkout' ) );
    if ( '' === $preview ) { return; }
    $path = get_template_directory() . '/assets/checkout-layout-preview.js';
    if ( ! is_readable( $path ) ) { return; }
    $json = wp_json_encode( array( 'documentId' => $document_id, 'templateId' => $template, 'previewBase' => $preview, 'storeUrl' => vc_checkout_layout_native_url() ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
    if ( false === $json ) { return; }
    wp_add_inline_script( 'peptide-core-design-editor', 'window.vcCheckoutLayoutPreview = ' . $json . ';' . "\n" . file_get_contents( $path ), 'before' );
}
add_action( 'admin_enqueue_scripts', 'vc_checkout_layout_preview_editor_assets', 99 );

/** Live draft URLs must not switch back to home after the first editor frame. */
function vc_checkout_layout_preview_response( $response, $server, $request ) {
    if ( ! $request instanceof WP_REST_Request || 'POST' !== $request->get_method()
        || ! preg_match( '~^/peptide/v1/layouts/([1-9][0-9]*)/preview$~D', $request->get_route(), $match )
        || ! $response instanceof WP_REST_Response || $response->get_status() < 200 || $response->get_status() >= 300 ) { return $response; }
    $template = vc_checkout_layout_document_template( (int) $match[1] );
    if ( ! $template ) { return $response; }
    $data = $response->get_data();
    if ( ! is_array( $data ) || ! is_string( $data['url'] ?? null ) ) { return $response; }
    $url = vc_checkout_layout_preview_url( $template, $data['url'] );
    if ( '' !== $url && $url !== $data['url'] ) { $data['url'] = $url; $response->set_data( $data ); }
    return $response;
}
add_filter( 'rest_post_dispatch', 'vc_checkout_layout_preview_response', 99, 3 );

/** Read only this staff member's accepted preview; public requests stay published. */
function vc_checkout_preview_module(): ?array {
    $published = static fn() => function_exists( 'vc_live_commerce_published_module' ) ? vc_live_commerce_published_module( array( 'checkout' ), 'context_content' ) : null;
    if ( ! class_exists( '\\PeptideCore\\Templates' ) || ! class_exists( '\\PeptideCore\\Modules\\Layout' ) || ! class_exists( '\\PeptideCore\\Modules\\Renderer' )
        || 'checkout' !== ( $_GET['pc_design_document'] ?? null ) ) { return $published(); }
    $template = \PeptideCore\Templates::previewing_id();
    if ( ! $template ) { return $published(); }
    $document_id = \PeptideCore\Templates::document_id( $template, 'checkout', false );
    if ( ! $document_id || $template !== vc_checkout_layout_document_template( $document_id ) ) { return $published(); }
    $draft = \PeptideCore\Modules\Layout::preview_draft( $template, 'checkout' );
    $rows = $draft ? $draft['modules'] : \PeptideCore\Modules\Layout::modules_from_content( (string) get_post_field( 'post_content', $document_id ) );
    foreach ( $rows as $row ) {
        if ( 'vialcove/context-content' !== ( $row['block'] ?? '' ) ) { continue; }
        $module = \PeptideCore\Modules\Renderer::normalize( 'context_content', (array) ( $row['attrs'] ?? array() ) );
        if ( $module ) { $module['document'] = 'checkout'; }
        return $module;
    }
    return null;
}
