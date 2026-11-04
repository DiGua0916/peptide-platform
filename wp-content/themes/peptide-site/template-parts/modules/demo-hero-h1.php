<?php
defined( 'ABSPATH' ) || exit;
$module = $args['module']; $s = $module['settings'];
$compact = 'product_first' === ( $s['presentation'] ?? '' );
$product = ! $compact && ! empty( $s['featuredProduct'] ) && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $s['featuredProduct'] ) : null;
if ( $product && ! \PeptideCore\Modules\Products::public_catalog_product( $product ) ) { $product = null; }
$display = $product && function_exists( 'vc_card_display_product' ) ? vc_card_display_product( $product ) : $product;
$image = '';
if ( ! $compact && ! empty( $s['showMedia'] ) ) {
    if ( $display ) {
        $image_id = $display->get_image_id( $display->is_type( 'variation' ) ? 'edit' : 'view' );
        if ( $image_id ) { $image = wp_get_attachment_image( $image_id, 'large', false, array( 'loading' => 'eager', 'fetchpriority' => 'high', 'sizes' => '(max-width:767px) 90vw, 45vw' ) ); }
    } else { $image = vc_module_picture( $s['media'] ?? array(), '', '(max-width:767px) 90vw, 45vw', array( 'loading' => 'eager', 'fetchpriority' => 'high' ) ); }
}
$d = $s['mediaPosition']['desktop'] ?? 'right'; $m = $s['mediaPosition']['mobile'] ?? $d;
$style = 'text-align:' . ( $s['textAlign'] ?? 'left' );
foreach ( array( 'titleColor' => '--vc-demo-title', 'textColor' => '--vc-demo-muted', 'contentBgColor' => '--vc-demo-copy-bg', 'buttonBgColor' => '--vc-demo-accent', 'buttonTextColor' => '--vc-demo-button-fg' ) as $key => $variable ) { if ( ! empty( $s[ $key ] ) ) { $style .= ';' . $variable . ':' . $s[ $key ]; } }
?>
<div class="vc-demo-module vc-demo-wrap vc-demo-hero vc-demo-hero--d-<?php echo esc_attr( $d ); ?> vc-demo-hero--m-<?php echo esc_attr( $m ); ?><?php echo '' === $image ? ' vc-demo-hero--no-media' : ''; ?><?php echo $compact ? ' vc-demo-hero--product-first' : ''; ?>" style="<?php echo esc_attr( $style ); ?>">
    <div class="vc-demo-hero-copy">
        <?php if ( ! $compact && '' !== ( $s['eyebrow'] ?? '' ) ) : ?><p class="vc-demo-hero-intro"><span aria-hidden="true"></span><?php echo esc_html( $s['eyebrow'] ); ?></p><?php endif; ?>
        <?php if ( '' !== $s['title'] ) : ?><h1><?php echo nl2br( esc_html( $s['title'] ) ); ?></h1><?php endif; ?>
        <?php if ( '' !== $s['description'] ) : ?><p class="vc-demo-hero-description"><?php echo nl2br( esc_html( $s['description'] ) ); ?></p><?php endif; ?>
        <?php if ( $compact ) : ?>
        <div class="vc-demo-hero-actions"><?php echo str_replace( '</a>', vc_icon() . '</a>', vc_module_button( $s['button'], 'vc-demo-shop-link' ) ); ?></div>
        <?php else : ?>
        <div class="vc-demo-hero-actions"><?php echo vc_module_button( $s['button'], 'vc-demo-button' ); ?><?php echo vc_module_button( $s['secondaryLink'] ?? null, 'vc-demo-quiet-link' ); ?></div>
        <?php endif; ?>
        <?php if ( ! $compact && '' !== ( $s['catalogCaption'] ?? '' ) ) : ?><p class="vc-demo-hero-caption"><?php echo esc_html( str_replace( '{count}', number_format_i18n( vc_demo_home_catalog_counts()['products'] ), $s['catalogCaption'] ) ); ?></p><?php endif; ?>
    </div>
    <?php if ( '' !== $image ) : ?>
    <figure class="vc-demo-hero-media">
        <?php if ( $product ) : ?><a href="<?php echo esc_url( $display->get_permalink() ); ?>" data-vc-home-product="<?php echo (int) $product->get_id(); ?>" data-vc-quick-open="<?php echo (int) $product->get_id(); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Explore %s specifications', 'peptide-site' ), $product->get_name() ) ); ?>"><?php endif; ?>
        <?php echo $image; ?>
        <?php if ( '' !== ( $s['caption'] ?? '' ) ) : ?><span class="vc-demo-hero-media-caption"><?php echo esc_html( $s['caption'] ); ?><?php echo vc_icon(); ?></span><?php endif; ?>
        <?php if ( $product ) : ?></a><?php endif; ?>
    </figure>
    <?php endif; ?>
</div>
<?php if ( $product && function_exists( 'vc_home_quick_view_template' ) ) { echo vc_home_quick_view_template( $product ); } ?>
