<?php
/* Template Name: VialCove information */
defined( 'ABSPATH' ) || exit;
$vc_slug = get_post_field( 'post_name', get_queried_object_id() );
$vc_pages = array(
    'about-us' => array( 'About', 'Three places. One vision.', 'Israeli research. Chinese manufacturing. U.S. warehousing. Three distinct roles at the heart of the VialCovePeptides brand vision.' ),
    'quality-standards' => array( 'About', 'Quality, with documentation.', 'A clear record begins with a specific product, an identifiable batch and supporting documents you can review.' ),
    'methodology' => array( 'About', 'From product to batch record.', 'Follow the documentation step by step. Match the product, find its batch and review the available source document.' ),
    'faq' => array( 'Research', 'Questions, answered.', 'Find a direct answer about products, documentation, accounts and the current store preview.' ),
);
$vc_info = $vc_pages[ $vc_slug ] ?? array( 'VialCove', get_the_title(), '' );
get_header();
?>
<main id="main-content" class="container information-page">
    <div class="page-intro"><span class="eyebrow"><?php echo esc_html( $vc_info[0] ); ?></span><h1><?php echo esc_html( $vc_info[1] ); ?></h1><p><?php echo esc_html( $vc_info[2] ); ?></p></div>
    <?php vc_information_content(); ?>
    <?php if ( 'about-us' === $vc_slug ) : ?>
        <?php get_template_part( 'template-parts/brand-network' ); ?>
        <div class="information-links"><a class="button" href="<?php echo esc_url( vc_url( 'quality-standards' ) ); ?>">Quality standards</a><a class="text-link" href="<?php echo esc_url( vc_url( 'methodology' ) ); ?>">Our methodology <?php echo vc_icon(); ?></a></div>
    <?php elseif ( 'quality-standards' === $vc_slug ) : ?>
        <div class="information-steps">
            <article><h2>Product specifications</h2><p>Look for the product name, selected specification and SKU. Any published product specifications appear on its detail page.</p></article>
            <article><h2>Batch traceability</h2><p>Match the batch number on the label to the published record. Where available, the record includes manufacture and retest or expiry dates.</p></article>
            <article><h2>Source documentation</h2><p>Review the current report, its version and the available issuer information. The directory distinguishes available documents from restricted or pending reports.</p></article>
        </div>
        <p class="document-note">Product-specific acceptance criteria, verified laboratory methods and supporting credentials are awaiting source documents. This preview does not claim a certified quality system or a universal purity standard.</p>
        <div class="information-links"><a class="button" href="<?php echo esc_url( vc_url( 'certificates' ) ); ?>">Browse certificates &amp; reports</a><a class="text-link" href="<?php echo esc_url( vc_url( 'methodology' ) ); ?>">How to review a batch <?php echo vc_icon(); ?></a></div>
    <?php elseif ( 'methodology' === $vc_slug ) : ?>
        <div class="information-steps">
            <article><h2>1. Identify the product</h2><p>Start with the product name and specification on the label. Open its detail page or find it in the documentation directory.</p></article>
            <article><h2>2. Match the batch</h2><p>Enter the full batch number in Find your batch. Check that the returned record matches the product you are looking for.</p></article>
            <article><h2>3. Read the source</h2><p>Open the available report and review its version, issuer and contents. If access is restricted, follow the sign-in or approval status shown.</p></article>
        </div>
        <p class="document-note">This page explains the documentation review workflow. Product-specific analytical methods, instruments and acceptance criteria will be added when verified laboratory documentation is available.</p>
        <div class="information-links"><a class="button" href="<?php echo esc_url( vc_url( 'reports' ) ); ?>">Find your batch</a><a class="text-link" href="<?php echo esc_url( vc_url( 'faq' ) ); ?>">Read the FAQ <?php echo vc_icon(); ?></a></div>
    <?php elseif ( 'faq' === $vc_slug ) : ?>
        <div class="information-faq"><div data-faq-search hidden><label class="faq-search-label" for="faq-search">Search questions and answers</label><input type="search" id="faq-search" placeholder="Try reports, shipping or sign-in"><p class="document-count" data-faq-status role="status" aria-live="polite"><?php echo count( vc_faq_items() ); ?> answers</p></div>
        <div class="faq-list"><?php foreach ( vc_faq_items() as $vc_faq ) : ?><details data-faq-answer><summary><?php echo esc_html( $vc_faq[0] ); ?><span>+</span></summary><p><?php echo esc_html( $vc_faq[1] ); ?></p></details><?php endforeach; ?></div></div>
        <div class="information-links"><a class="text-link" href="<?php echo esc_url( vc_url( 'certificates' ) ); ?>">Browse product documentation <?php echo vc_icon(); ?></a><a class="text-link" href="<?php echo esc_url( vc_url( 'reports' ) ); ?>">Find a batch <?php echo vc_icon(); ?></a></div>
    <?php endif; ?>
</main>
<?php get_footer(); ?>
