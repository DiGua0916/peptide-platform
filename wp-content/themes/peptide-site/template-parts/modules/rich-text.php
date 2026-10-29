<?php
defined( 'ABSPATH' ) || exit;
$vc_content_s = $args['module']['settings'];
$vc_content_padding = $vc_content_s['innerPaddingPx'];
$vc_content_vars = array(
    '--vc-content-width:' . (int) $vc_content_s['contentWidthPx'] . 'px',
    '--vc-content-padding:' . (int) $vc_content_padding['desktop'] . 'px',
    '--vc-content-padding-mobile:' . (int) ( null !== $vc_content_padding['mobile'] ? $vc_content_padding['mobile'] : $vc_content_padding['desktop'] ) . 'px',
    '--vc-content-radius:' . (int) $vc_content_s['radiusPx'] . 'px',
    '--vc-btn-duration:' . (int) $vc_content_s['effectDurationMs'] . 'ms',
    '--vc-btn-interval:' . (int) $vc_content_s['effectIntervalMs'] . 'ms',
);
foreach ( array( 'contentBgColor' => '--vc-content-bg', 'textColor' => '--vc-content-fg', 'buttonTextColor' => '--vc-btn-fg', 'buttonBgColor' => '--vc-btn-bg' ) as $vc_content_key => $vc_content_var ) {
    if ( '' !== $vc_content_s[ $vc_content_key ] ) { $vc_content_vars[] = $vc_content_var . ':' . $vc_content_s[ $vc_content_key ]; }
}
$vc_content_button_class = 'button vc-content-rich-button';
if ( 'none' !== $vc_content_s['buttonEffect'] ) { $vc_content_button_class .= ' vc-btn--' . $vc_content_s['buttonEffect']; }
?>
<div class="container">
    <div class="vc-content-rich vc-content-rich--<?php echo esc_attr( $vc_content_s['textAlign'] ); ?>" style="<?php echo esc_attr( implode( ';', $vc_content_vars ) ); ?>">
        <?php if ( '' !== $vc_content_s['title'] ) : ?><h2><?php echo esc_html( $vc_content_s['title'] ); ?></h2><?php endif; ?>
        <?php if ( '' !== $vc_content_s['bodyHtml'] ) : ?><div class="vc-content-rich-body"><?php echo wp_kses_post( wpautop( $vc_content_s['bodyHtml'] ) ); ?></div><?php endif; ?>
        <?php echo vc_module_button( $vc_content_s['button'], $vc_content_button_class ); ?>
    </div>
</div>
