<?php
/** The plugin chooses the effective mode and authorizes the report metadata. */
defined( 'ABSPATH' ) || exit;
$vc_hover = $args['hover'] ?? array( 'mode' => 'placeholder' );
$vc_hover_mode = $vc_hover['mode'] ?? 'placeholder';
if ( 'none' === $vc_hover_mode ) { return; }
if ( in_array( $vc_hover_mode, array( 'image', 'gallery' ), true ) && ! empty( $vc_hover['image']['src'] ) ) :
    $vc_hover_image = $vc_hover['image'];
    ?>
    <span class="product-report-preview product-hover-image" aria-hidden="true"><img src="<?php echo esc_url( $vc_hover_image['src'] ); ?>" width="<?php echo (int) $vc_hover_image['width']; ?>" height="<?php echo (int) $vc_hover_image['height']; ?>" alt="<?php echo esc_attr( $vc_hover_image['alt'] ); ?>"<?php if ( ! empty( $vc_hover_image['srcset'] ) ) : ?> srcset="<?php echo esc_attr( $vc_hover_image['srcset'] ); ?>" sizes="(max-width: 767px) 50vw, 33vw"<?php endif; ?> loading="lazy" decoding="async"></span>
<?php elseif ( 'report' === $vc_hover_mode && is_array( $vc_hover['report'] ?? null ) ) :
    $vc_hover_report = $vc_hover['report'];
    ?>
    <span class="product-report-preview product-hover-report" aria-hidden="true">
        <span class="product-report-sheet">
            <span class="product-report-logo"><?php vc_brand_logo( true ); ?></span>
            <span class="product-report-title"><?php echo esc_html( $vc_hover_report['title'] ); ?></span>
            <span class="product-report-name"><?php echo esc_html( $args['name'] ?? '' ); ?></span>
            <span class="product-hover-fields">
                <?php foreach ( array( 'batch_no' => 'Batch', 'version' => 'Version', 'issued_on' => 'Issued', 'issuer_name' => 'Issuer' ) as $vc_hover_key => $vc_hover_label ) : ?>
                    <?php if ( null !== ( $vc_hover_report[ $vc_hover_key ] ?? null ) && '' !== (string) $vc_hover_report[ $vc_hover_key ] ) : ?>
                        <span><span><?php echo esc_html( $vc_hover_label ); ?></span><strong><?php echo esc_html( (string) $vc_hover_report[ $vc_hover_key ] ); ?></strong></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </span>
            <span class="product-report-disclosure"><?php if ( ! empty( $vc_hover_report['is_demo'] ) ) : ?><strong>Demo report</strong><?php endif; ?><span>View batch documentation on the product page.</span></span>
        </span>
        <span class="product-report-caption">Batch report summary</span>
    </span>
<?php else :
    get_template_part( 'template-parts/product-report-preview', null, array( 'name' => $args['name'] ?? '' ) );
endif;
