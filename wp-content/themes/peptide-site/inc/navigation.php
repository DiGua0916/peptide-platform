<?php
defined( 'ABSPATH' ) || exit;

/** Share the shop's saved product order with navigation and the report directory. */
function vc_navigation_products(): array {
    static $products = null;
    if ( null === $products ) {
        $products = function_exists( 'wc_get_products' ) ? wc_get_products( array(
            'status' => 'publish', 'visibility' => 'catalog', 'limit' => -1,
            'orderby' => 'menu_order title', 'order' => 'ASC',
        ) ) : array();
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
