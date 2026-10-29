<?php
defined( 'ABSPATH' ) || exit;
$vc_content_s = $args['module']['settings'];
$vc_content_colors = array();
if ( '' !== $vc_content_s['bgColor'] ) { $vc_content_colors[] = '--vc-content-bg:' . $vc_content_s['bgColor']; }
if ( '' !== $vc_content_s['textColor'] ) { $vc_content_colors[] = '--vc-content-fg:' . $vc_content_s['textColor']; }
?>
<div class="container vc-content-cta vc-content-cta--<?php echo esc_attr( $vc_content_s['align'] ); ?>"<?php if ( $vc_content_colors ) { echo ' style="' . esc_attr( implode( ';', $vc_content_colors ) ) . '"'; } ?>>
    <?php if ( '' !== $vc_content_s['eyebrow'] ) : ?><p class="eyebrow"><?php echo esc_html( $vc_content_s['eyebrow'] ); ?></p><?php endif; ?>
    <?php if ( '' !== $vc_content_s['title'] ) : ?><h2><?php echo esc_html( $vc_content_s['title'] ); ?></h2><?php endif; ?>
    <?php if ( '' !== $vc_content_s['description'] ) : ?><p class="vc-content-cta-description"><?php echo nl2br( esc_html( $vc_content_s['description'] ) ); ?></p><?php endif; ?>
    <?php echo vc_module_button( $vc_content_s['button'] ); ?>
</div>
