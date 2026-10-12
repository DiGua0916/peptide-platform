<?php
defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', function () {
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
    add_theme_support( 'woocommerce' );
    add_theme_support( 'wc-product-gallery-lightbox' );
    register_nav_menus( array( 'primary' => 'Primary navigation' ) );
    remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
    remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
    remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );
} );

add_filter( 'document_title_parts', function ( $parts ) { $parts['site'] = 'VialCove'; if ( is_front_page() ) { $parts['title'] = 'VialCove — A clearer view of peptides'; unset( $parts['tagline'] ); } return $parts; } );

add_action( 'wp_enqueue_scripts', function () {
    $root = get_template_directory_uri();
    wp_enqueue_style( 'vialcove-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Inter+Tight:wght@400;500;600&display=swap', array(), null );
    wp_enqueue_style( 'vialcove', $root . '/assets/site.css', array(), (string) filemtime( __DIR__ . '/assets/site.css' ) );
    wp_enqueue_script( 'vialcove', $root . '/assets/site.js', array(), (string) filemtime( __DIR__ . '/assets/site.js' ), true );
    wp_localize_script( 'vialcove', 'VialCove', array(
        'lookupUrl' => rest_url( 'peptide/v1/batches/lookup' ),
        'nonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
        'accountUrl' => vc_url( 'account' ),
    ) );
} );

function vc_url( string $page ): string {
    if ( 'reports' === $page ) {
        $reports = get_page_by_path( 'batch-reports' );
        return $reports ? get_permalink( $reports ) : home_url( '/?vc_reports=1' );
    }
    if ( function_exists( 'wc_get_page_permalink' ) ) {
        return wc_get_page_permalink( 'account' === $page ? 'myaccount' : $page );
    }
    return home_url( '/' );
}

function vc_site_config(): array {
    return function_exists( 'peptide_core_get_site_config' ) ? peptide_core_get_site_config() : array();
}

/** Use the documented Store API extension; never depend on plugin meta keys. */
function vc_product_data( $product ): array {
    static $cache = array();
    $id = $product->get_id();
    if ( ! isset( $cache[ $id ] ) ) {
        $result = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/products/' . $id ) );
        // Store API can return stdClass for extensions before JSON serialization.
        $data = json_decode( wp_json_encode( $result->get_data() ), true );
        $cache[ $id ] = $result->is_error() ? array() : ( $data['extensions']['peptide_core'] ?? array() );
    }
    return $cache[ $id ];
}

function vc_report_label( string $state ): string {
    return array( 'available' => 'Report available', 'restricted' => 'Report access required', 'pending' => 'Report pending', 'none' => 'No published report' )[ $state ] ?? 'Report data unavailable';
}

function vc_icon( string $name = 'arrow' ): string {
    $paths = array(
        'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'bag' => '<path d="M5 7h14l1 14H4L5 7Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>',
        'file' => '<path d="M14 2H5v20h14V7l-5-5Zm0 0v6h5M8 12h8M8 16h6"/>',
        'user' => '<circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/>',
    );
    return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $paths[ $name ] ?? $paths['arrow'] ) . '</svg>';
}

/** A deliberately labelled packaging concept when the backend has no product photo. */
function vc_vial( string $label = 'PEPTIDE COLLECTION', string $sku = 'DESIGN CONCEPT' ): void {
    ?>
    <div class="vial-scene" role="img" aria-label="Illustrative VialCove vial packaging; not a product photograph">
        <div class="vial-shadow"></div><div class="vial"><div class="vial-cap"></div><div class="vial-neck"></div><div class="vial-glass"><div class="vial-label"><span class="vial-wordmark">VialCove</span><span class="vial-product"><?php echo esc_html( $label ); ?></span><span class="vial-line"></span><span class="vial-sku"><?php echo esc_html( $sku ); ?></span></div></div></div>
        <span class="concept-label">Packaging concept</span>
    </div>
    <?php
}

function vc_product_card( $product ): void {
    $extra = vc_product_data( $product );
    ?>
    <article class="product-card">
        <a class="product-visual" href="<?php echo esc_url( $product->get_permalink() ); ?>" aria-label="<?php echo esc_attr( 'View ' . $product->get_name() ); ?>">
            <?php if ( $product->get_image_id() ) { echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); } else { vc_vial( $product->get_name(), $product->get_sku() ); } ?>
            <?php if ( ! empty( $extra['is_demo'] ) ) : ?><span class="demo-badge">Demo product</span><?php endif; ?>
            <span class="product-open"><?php echo vc_icon(); ?></span>
        </a>
        <div class="product-meta"><span><?php echo esc_html( $product->get_sku() ?: 'PEPTIDE COLLECTION' ); ?></span><span><?php echo esc_html( vc_report_label( $extra['report_state'] ?? '' ) ); ?></span></div>
        <div class="product-heading"><h3><a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></h3><span class="product-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span></div>
        <div class="product-card-bottom"><span><?php echo isset( $extra['batch_count'] ) ? esc_html( sprintf( _n( '%d published batch', '%d published batches', $extra['batch_count'], 'vialcove' ), $extra['batch_count'] ) ) : 'Batch data unavailable'; ?></span><a href="<?php echo esc_url( $product->get_permalink() ); ?>">View details <?php echo vc_icon(); ?></a></div>
    </article>
    <?php
}

function vc_render_batches( array $batches ): void {
    if ( ! $batches ) { echo '<p class="empty-note">No public batches have been published for this product.</p>'; return; }
    foreach ( $batches as $batch ) {
        ?>
        <section class="batch-card"><div class="batch-heading"><div><span class="eyebrow">Batch<?php echo ! empty( $batch['is_demo'] ) ? ' · Demo record' : ''; ?></span><h3><?php echo esc_html( $batch['batch_no'] ); ?></h3></div><span class="status-pill"><?php echo esc_html( vc_report_label( $batch['report_state'] ) ); ?></span></div>
        <?php if ( ! empty( $batch['manufactured_on'] ) ) : ?><p>Manufactured: <?php echo esc_html( $batch['manufactured_on'] ); ?></p><?php endif; ?>
        <?php if ( ! empty( $batch['retest_or_expiry_on'] ) ) : ?><p>Retest / expiry: <?php echo esc_html( $batch['retest_or_expiry_on'] ); ?></p><?php endif; ?>
        <?php if ( ! empty( $batch['origin_note'] ) ) : ?><p><?php echo esc_html( $batch['origin_note'] ); ?></p><?php endif; ?>
        <?php foreach ( $batch['reports'] as $report ) : ?>
            <div class="report-row"><div class="report-name"><?php echo vc_icon( 'file' ); ?><div><strong><?php echo esc_html( $report['title'] ); ?></strong><span>Version <?php echo esc_html( (string) $report['version'] ); ?><?php echo ! empty( $report['is_demo'] ) ? ' · Synthetic demonstration report' : ''; ?></span></div></div>
            <?php if ( $report['can_download'] && $report['download_url'] ) : ?><a class="text-link" href="<?php echo esc_url( $report['download_url'] ); ?>">Download <?php echo vc_icon(); ?></a>
            <?php elseif ( 'login_required' === ( $report['locked_reason'] ?? '' ) ) : ?><a class="text-link" href="<?php echo esc_url( vc_url( 'account' ) ); ?>">Sign in to view <?php echo vc_icon(); ?></a>
            <?php else : ?><span class="muted">Access requires approval</span><?php endif; ?></div>
        <?php endforeach; ?>
        <?php if ( empty( $batch['reports'] ) ) : ?><p class="muted"><?php echo 'pending' === $batch['report_state'] ? 'The report is being prepared. Check back for the published document.' : 'No report is currently available.'; ?></p><?php endif; ?>
        </section>
        <?php
    }
}

add_filter( 'woocommerce_product_tabs', function ( $tabs ) {
    $tabs['vialcove_details'] = array( 'title' => 'Specifications & batch reports', 'priority' => 5, 'callback' => 'vc_product_details' );
    return $tabs;
} );

function vc_product_details(): void {
    global $product;
    $extra = vc_product_data( $product );
    echo '<div id="batch-reports"><h2>Product documentation</h2>';
    if ( ! empty( $extra['specs'] ) ) {
        echo '<dl class="spec-table">';
        foreach ( $extra['specs'] as $spec ) { echo '<div><dt>' . esc_html( $spec['label'] ) . '</dt><dd>' . esc_html( $spec['value'] . ( empty( $spec['unit'] ) ? '' : ' ' . $spec['unit'] ) ) . '</dd></div>'; }
        echo '</dl>';
    } else { echo '<p class="muted">Specifications have not been published.</p>'; }
    echo '<h3 class="section-subtitle">Batch reports</h3>';
    if ( function_exists( 'peptide_core_get_product_batches' ) ) {
        $result = peptide_core_get_product_batches( $product->get_slug() );
        if ( is_wp_error( $result ) ) { echo '<p class="empty-note">Batch information could not be loaded. Please try again later.</p>'; }
        else { vc_render_batches( $result['batches'] ); }
    } else { echo '<p class="empty-note">Batch data is currently unavailable.</p>'; }
    echo '</div>';
}

add_action( 'woocommerce_single_product_summary', function () {
    global $product;
    $extra = vc_product_data( $product );
    if ( ! empty( $extra['is_demo'] ) ) { echo '<div class="inline-demo">Demonstration product · The product, price and documentation are synthetic.</div>'; }
}, 6 );

add_action( 'wp', function () {
    if ( function_exists( 'is_product' ) && is_product() ) {
        global $post;
        $product = wc_get_product( $post );
        if ( $product && ! $product->get_image_id() ) {
            remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
            add_action( 'woocommerce_before_single_product_summary', function () {
                global $product;
                echo '<div class="woocommerce-product-gallery images vc-product-gallery">'; vc_vial( $product->get_name(), $product->get_sku() ); echo '</div>';
            }, 20 );
        }
    }
} );

add_filter( 'woocommerce_add_to_cart_fragments', function ( $fragments ) {
    $fragments['span.vc-cart-count'] = '<span class="vc-cart-count">' . esc_html( (string) WC()->cart->get_cart_contents_count() ) . '</span>';
    return $fragments;
} );

add_filter( 'body_class', function ( $classes ) { $classes[] = 'vialcove-site'; return $classes; } );

// The fallback route keeps the report tool reachable before its WordPress page is created.
add_filter( 'template_include', function ( $template ) {
    return isset( $_GET['vc_reports'] ) && '1' === $_GET['vc_reports'] ? __DIR__ . '/page-batch-reports.php' : $template;
} );
