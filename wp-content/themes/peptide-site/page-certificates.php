<?php
/* Template Name: Certificates and reports */
defined( 'ABSPATH' ) || exit;
// Report access depends on the visitor. Never cache this directory across users.
if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
nocache_headers();
$vc_search = isset( $_GET['doc_search'] ) && is_string( $_GET['doc_search'] ) ? sanitize_text_field( wp_unslash( $_GET['doc_search'] ) ) : '';
$vc_requested = isset( $_GET['doc_product'] ) && is_string( $_GET['doc_product'] ) ? sanitize_title( wp_unslash( $_GET['doc_product'] ) ) : '';
$vc_catalogue = vc_navigation_products();
$vc_matches = array_values( array_filter( $vc_catalogue, fn( $p ) => '' === $vc_search || false !== stripos( $p->get_name() . ' ' . $p->get_sku(), $vc_search ) ) );
$vc_total_pages = max( 1, (int) ceil( count( $vc_matches ) / 24 ) );
$vc_page = isset( $_GET['doc_page'] ) && is_scalar( $_GET['doc_page'] ) ? max( 1, min( $vc_total_pages, absint( $_GET['doc_page'] ) ) ) : 1;
$vc_selection = null;
foreach ( $vc_catalogue as $vc_candidate ) { if ( $vc_candidate->get_slug() === $vc_requested ) { $vc_selection = $vc_candidate; break; } }
get_header();
?>
<main id="main-content" class="container information-page">
    <div class="page-intro"><span class="eyebrow">Research</span><h1>Certificates &amp; reports.</h1><p>Explore documentation by product, then find the report for the batch on your label.</p></div>
    <?php vc_information_content(); ?>
    <div class="document-tools">
        <form class="document-search" method="get" action="<?php echo esc_url( vc_url( 'certificates' ) ); ?>"><label for="document-search">Search products by name</label><div><input id="document-search" name="doc_search" type="search" value="<?php echo esc_attr( $vc_search ); ?>" placeholder="Product name"><button class="button" type="submit">Search</button></div></form>
        <a class="text-link" href="<?php echo esc_url( vc_url( 'reports' ) ); ?>">Have a batch number? <?php echo vc_icon(); ?></a>
    </div>
    <?php if ( ! empty( vc_site_config()['demo_mode'] ) ) : ?><p class="document-note">This preview contains synthetic demonstration records. Documents marked as demo are not real certificates or laboratory results.</p><?php endif; ?>
    <?php if ( $vc_requested ) : ?>
    <section class="document-detail" id="product-documents" aria-label="Selected product documentation">
        <?php if ( $vc_selection ) : ?>
        <h2><?php echo esc_html( $vc_selection->get_name() ); ?></h2>
        <a class="text-link" href="<?php echo esc_url( $vc_selection->get_permalink() ); ?>">View product details <?php echo vc_icon(); ?></a>
        <?php
        if ( function_exists( 'peptide_core_get_product_batches' ) ) {
            $vc_batch_data = peptide_core_get_product_batches( $vc_selection->get_slug() );
            if ( is_wp_error( $vc_batch_data ) ) { echo '<p class="empty-note">The batch records could not be loaded. Please try again.</p>'; }
            else { vc_render_batches( $vc_batch_data['batches'] ?? array() ); }
        } else { echo '<p class="empty-note">Batch documentation is temporarily unavailable.</p>'; }
        ?>
        <?php else : ?><h2>Product not found</h2><p>Choose an available product from the directory below.</p><?php endif; ?>
    </section>
    <?php endif; ?>
    <p class="document-count"><?php echo esc_html( sprintf( '%d products · Page %d of %d', count( $vc_matches ), $vc_page, $vc_total_pages ) ); ?><?php if ( $vc_search ) : ?> · <a href="<?php echo esc_url( vc_url( 'certificates' ) ); ?>">Clear search</a><?php endif; ?></p>
    <div class="certificate-grid">
    <?php foreach ( array_slice( $vc_matches, ( $vc_page - 1 ) * 24, 24 ) as $vc_document_product ) : $vc_extra = vc_product_data( $vc_document_product ); ?>
        <article class="certificate-card">
            <h2><?php echo esc_html( $vc_document_product->get_name() ); ?></h2>
            <p class="certificate-sku"><?php echo esc_html( $vc_document_product->get_sku() ?: 'No SKU published' ); ?><?php echo ! empty( $vc_extra['is_demo'] ) ? ' · Demo product' : ''; ?></p>
            <span class="status-pill"><?php echo esc_html( vc_report_label( $vc_extra['report_state'] ?? '' ) ); ?></span>
            <p><?php echo isset( $vc_extra['batch_count'] ) ? esc_html( sprintf( _n( '%d published batch', '%d published batches', $vc_extra['batch_count'], 'vialcove' ), $vc_extra['batch_count'] ) ) : 'Batch data is temporarily unavailable.'; ?></p>
            <a class="text-link" href="<?php echo esc_url( add_query_arg( array( 'doc_product' => $vc_document_product->get_slug(), 'doc_search' => $vc_search, 'doc_page' => $vc_page ), vc_url( 'certificates' ) ) . '#product-documents' ); ?>">View batch records <?php echo vc_icon(); ?></a>
        </article>
    <?php endforeach; ?>
    </div>
    <?php if ( ! $vc_matches ) : ?><p class="empty-note">No products match this search. Try another product name, or <a href="<?php echo esc_url( vc_url( 'certificates' ) ); ?>">browse all products</a>.</p><?php endif; ?>
    <?php if ( $vc_total_pages > 1 ) : ?><nav class="document-pagination" aria-label="Report directory pages"><?php for ( $vc_i = 1; $vc_i <= $vc_total_pages; $vc_i++ ) : ?><?php if ( $vc_i === $vc_page ) : ?><span aria-current="page"><?php echo $vc_i; ?></span><?php else : ?><a aria-label="<?php echo esc_attr( 'Page ' . $vc_i ); ?>" href="<?php echo esc_url( add_query_arg( array( 'doc_search' => $vc_search, 'doc_page' => $vc_i ), vc_url( 'certificates' ) ) ); ?>"><?php echo $vc_i; ?></a><?php endif; ?><?php endfor; ?></nav><?php endif; ?>
</main>
<?php get_footer(); ?>
