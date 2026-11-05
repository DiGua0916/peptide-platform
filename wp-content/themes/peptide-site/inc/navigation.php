<?php
defined( 'ABSPATH' ) || exit;

/** Share the shop's saved product order with navigation and the report directory. */
function vc_prime_catalog_product_data( array $ids ): void {
    static $primed = array();
    $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
    $blog = get_current_blog_id();
    $ids = array_values( array_filter( $ids, static fn( $id ) => empty( $primed[ $blog ][ $id ] ) ) );
    if ( ! $ids ) { return; }
    foreach ( $ids as $id ) { $primed[ $blog ][ $id ] = true; }
    if ( is_callable( array( '\PeptideCore\Catalog', 'prime_public_batch_ids' ) ) ) { \PeptideCore\Catalog::prime_public_batch_ids( $ids ); }
    // Prime native caches only. Woo still hydrates, filters and prices each SKU.
    if ( function_exists( 'wp_prime_option_caches' ) && ! wp_using_ext_object_cache() ) {
        $options = array();
        foreach ( $ids as $id ) {
            foreach ( array( 'wc_var_prices_', 'wc_product_children_', 'wc_child_has_weight_', 'wc_child_has_dimensions_', 'wc_related_' ) as $name ) {
                $options[] = '_transient_' . $name . $id;
                $options[] = '_transient_timeout_' . $name . $id;
            }
        }
        wp_prime_option_caches( $options );
    }
    $children = get_posts( array( 'post_type' => 'product_variation', 'post_status' => array( 'publish', 'private' ), 'post_parent__in' => $ids, 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
    $all = array_values( array_unique( array_merge( $ids, array_map( 'absint', $children ) ) ) );
    _prime_post_caches( $all, true, true );
    $images = array();
    foreach ( $all as $id ) {
        $image = (int) get_post_meta( $id, '_thumbnail_id', true );
        if ( $image > 0 ) { $images[ $image ] = $image; }
    }
    // Native attachment formatting still decides sources, sizes and fallbacks.
    // Only its otherwise per-card post/meta reads are shared across this render.
    if ( $images ) { _prime_post_caches( array_values( $images ), false, true ); }
    // WC metadata includes meta_id, unlike WordPress's metadata cache. Prime its
    // supported raw cache only when it is request-local, retaining native filters.
    if ( ! wp_using_ext_object_cache() && is_callable( array( 'WC_Data', 'prime_raw_meta_data_cache' ) ) ) {
        global $wpdb;
        $raw = array_fill_keys( $all, array() );
        $placeholders = implode( ',', array_fill( 0, count( $all ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) ORDER BY meta_id", ...$all ) );
        foreach ( $rows as $row ) { $raw[ (int) $row->post_id ][] = $row; }
        WC_Data::prime_raw_meta_data_cache( $raw, 'products' );
    }
}

function vc_navigation_products(): array {
    static $products = null;
    if ( null === $products ) {
        $ids = function_exists( 'wc_get_products' ) ? wc_get_products( array(
            'status' => 'publish', 'visibility' => 'catalog', 'limit' => -1,
            'orderby' => 'menu_order title', 'order' => 'ASC', 'return' => 'ids',
        ) ) : array();
        if ( ! $ids ) { return $products = array(); }
        vc_prime_catalog_product_data( $ids );
        $products = array_values( array_filter( array_map( 'wc_get_product', $ids ) ) );
        if ( function_exists( 'vc_live_commerce_prime_product_documents' ) ) { vc_live_commerce_prime_product_documents( $ids ); }
    }
    return $products;
}

/** Normal WordPress page content remains editable above the themed sections. */
function vc_information_content(): void {
    if ( have_posts() ) {
        the_post();
        if ( trim( get_the_content() ) ) { echo '<div class="entry-content information-editor">'; the_content(); echo '</div>'; }
    }
}

function vc_faq_items(): array {
    return array(
        array( 'Where can I find a product’s certificates?', 'Open Certificates & reports under Research. Search by product name, then open its batch records. Available documents can be downloaded from the corresponding batch.' ),
        array( 'How do I match a report to my vial?', 'Use the full batch number on your label in Find your batch. Check the product name and batch number before opening the document.' ),
        array( 'Why does a product have no published report?', 'It means no report is currently published for that product in this directory. A missing report is not a quality result. Check again for updated documentation.' ),
        array( 'Why do some reports require sign-in?', 'Access is set for each report. Public reports are available to everyone; other reports may require an account or approval. Each batch record shows its current access status.' ),
        array( 'How do I choose a product specification?', 'Open the product’s detail page and choose an available Pack option when shown. The price and availability update for that selection.' ),
        array( 'Which version of a report is shown?', 'The directory displays the current published reports returned by the batch record. Review the version and any available issue date or issuer information in the document.' ),
        array( 'Where can I check U.S. shipping and payment options?', 'The current checkout is a local demonstration with test shipping and test payment. Confirmed service areas, dispatch times, charges and payment methods will be published before the store goes live.' ),
        array( 'Are these real products and test results?', 'This preview uses clearly marked demonstration products, prices, batches and watermarked reports. They are synthetic examples and do not represent real test results. The laboratory, manufacturing and warehouse images are labelled brand concepts.' ),
    );
}
