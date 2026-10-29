<?php
/** WooCommerce remains the source of product order, media and prices. */
defined( 'ABSPATH' ) || exit;
$vc_content_module = $args['module'];
$vc_content_s = $vc_content_module['settings'];
$vc_content_products = class_exists( '\PeptideCore\Modules\Products' )
    ? \PeptideCore\Modules\Products::for_module( $vc_content_module ) : array();
$vc_content_cols = $vc_content_s['columns'];
$vc_content_desktop = max( 1, min( 6, (int) $vc_content_cols['desktop'] ) );
$vc_content_mobile = max( 1, min( 6, (int) ( null !== $vc_content_cols['mobile'] ? $vc_content_cols['mobile'] : $vc_content_cols['desktop'] ) ) );
?>
<div class="container vc-content-collection">
    <header class="vc-content-heading">
        <div>
            <?php if ( '' !== $vc_content_s['eyebrow'] ) : ?><p class="eyebrow"><?php echo esc_html( $vc_content_s['eyebrow'] ); ?></p><?php endif; ?>
            <?php if ( '' !== $vc_content_s['title'] ) : ?><h2><?php echo esc_html( $vc_content_s['title'] ); ?></h2><?php endif; ?>
        </div>
        <div class="vc-content-intro">
            <?php if ( '' !== $vc_content_s['description'] ) : ?><p><?php echo nl2br( esc_html( $vc_content_s['description'] ) ); ?></p><?php endif; ?>
            <?php echo vc_module_button( $vc_content_s['link'], 'text-link' ); ?>
        </div>
    </header>
    <?php if ( $vc_content_products ) : ?>
        <div class="home-product-grid vc-content-products" style="<?php echo esc_attr( '--vc-content-cols:' . $vc_content_desktop . ';--vc-content-cols-mobile:' . $vc_content_mobile ); ?>">
            <?php foreach ( $vc_content_products as $vc_content_product ) { vc_product_card( $vc_content_product, (bool) $vc_content_s['reportPreview'] ); } ?>
        </div>
    <?php elseif ( '' !== $vc_content_s['emptyText'] ) : ?>
        <p class="vc-content-empty"><?php echo esc_html( $vc_content_s['emptyText'] ); ?></p>
    <?php endif; ?>
</div>
