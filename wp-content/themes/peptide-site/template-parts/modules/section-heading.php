<?php
/** Independently configurable section heading. */
defined( 'ABSPATH' ) || exit;

$vc_section_s = $args['module']['settings'];
$vc_section_link = vc_module_button( $vc_section_s['link'], 'vc-section-heading__link' );
if ( '' === $vc_section_s['eyebrow'] && '' === $vc_section_s['title'] && '' === $vc_section_s['titleAccent']
    && '' === $vc_section_s['description'] && '' === $vc_section_link ) {
    return;
}
$vc_section_vars = array( 'text-align:' . $vc_section_s['align'] );
foreach ( array( 'titleColor' => '--vc-section-title-color', 'textColor' => '--vc-section-text-color' ) as $vc_section_key => $vc_section_var ) {
    if ( '' !== $vc_section_s[ $vc_section_key ] ) {
        $vc_section_vars[] = $vc_section_var . ':' . $vc_section_s[ $vc_section_key ];
    }
}
?>
<div class="vc-section-heading vc-section-heading--<?php echo esc_attr( $vc_section_s['align'] ); ?>" style="<?php echo esc_attr( implode( ';', $vc_section_vars ) ); ?>">
    <?php if ( '' !== $vc_section_s['eyebrow'] ) : ?><span class="vc-section-heading__eyebrow"><?php echo esc_html( $vc_section_s['eyebrow'] ); ?></span><?php endif; ?>
    <?php if ( '' !== $vc_section_s['title'] || '' !== $vc_section_s['titleAccent'] ) : ?>
        <h2 class="vc-section-heading__title"><?php echo esc_html( $vc_section_s['title'] ); ?><?php if ( '' !== $vc_section_s['titleAccent'] ) : ?><?php if ( '' !== $vc_section_s['title'] ) : ?><br><?php endif; ?><em><?php echo esc_html( $vc_section_s['titleAccent'] ); ?></em><?php endif; ?></h2>
    <?php endif; ?>
    <?php if ( '' !== $vc_section_s['description'] ) : ?><p class="vc-section-heading__description"><?php echo nl2br( esc_html( $vc_section_s['description'] ) ); ?></p><?php endif; ?>
    <?php echo $vc_section_link; // Escaped by the shared link helper. ?>
</div>
