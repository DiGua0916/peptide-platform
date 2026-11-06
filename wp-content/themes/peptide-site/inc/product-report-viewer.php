<?php
/** Shared document presentation; all report authority remains in peptide-core. */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', static function (): void {
    if ( is_admin() ) { return; }
    $page = get_queried_object();
    $library = $page instanceof WP_Post && has_shortcode( (string) $page->post_content, 'pc_report_library' );
    $home = is_front_page();
    if ( ! $library && ! $home && ! wp_script_is( 'vc-product-dialog-report-viewer', 'enqueued' ) ) { return; }
    $base = get_template_directory_uri() . '/assets/';
    $path = get_template_directory() . '/assets/';
    wp_enqueue_style( 'vc-product-report-viewer', $base . 'product-dialog-report-viewer.css', array(), (string) filemtime( $path . 'product-dialog-report-viewer.css' ) );
    wp_enqueue_script( 'vc-product-dialog-report-viewer', $base . 'product-dialog-report-viewer.js', array(), (string) filemtime( $path . 'product-dialog-report-viewer.js' ), true );
    wp_localize_script( 'vc-product-dialog-report-viewer', 'vcProductReports', array( 'assetBase' => $base, 'reportLibraryBase' => rest_url( 'peptide/v1/report-library/' ), 'wpNonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '' ) );
    if ( ! $library && ! $home ) { return; }
    wp_enqueue_script( 'vc-product-report-library-viewer', $base . 'product-report-library-viewer.js', array( 'vc-product-dialog-report-viewer' ), (string) filemtime( $path . 'product-report-library-viewer.js' ), true );
    if ( ! wp_script_is( 'vc-live-commerce-navigation', 'enqueued' ) ) {
        wp_enqueue_script( 'vc-live-commerce-navigation', $base . 'live-commerce-navigation.js', array( 'vc-product-report-library-viewer' ), (string) filemtime( $path . 'live-commerce-navigation.js' ), true );
    } else {
        // Keep the existing cart/product dependencies and a single history owner.
        $scripts = wp_scripts();
        $scripts->registered['vc-live-commerce-navigation']->deps[] = 'vc-product-report-library-viewer';
        $scripts->registered['vc-live-commerce-navigation']->deps = array_values( array_unique( $scripts->registered['vc-live-commerce-navigation']->deps ) );
    }
}, 112 );
