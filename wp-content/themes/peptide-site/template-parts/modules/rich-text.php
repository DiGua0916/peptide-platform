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
if ( 'percent' === ( $vc_content_s['contentWidthMode'] ?? 'pixels' ) ) {
    $vc_content_percent = $vc_content_s['contentWidthPercent'] ?? array( 'desktop' => 100, 'mobile' => 100 );
    $vc_content_percent_desktop = $vc_content_percent['desktop'] ?? 100;
    $vc_content_percent_mobile = $vc_content_percent['mobile'] ?? $vc_content_percent_desktop;
    $vc_content_vars[0] = '--vc-content-width:' . (int) $vc_content_percent_desktop . '%';
    $vc_content_vars[] = '--vc-content-width-mobile:' . (int) $vc_content_percent_mobile . '%';
}
foreach ( array( 'Top' => 't', 'Right' => 'r', 'Bottom' => 'b', 'Left' => 'l' ) as $vc_content_side => $vc_content_suffix ) {
    $vc_content_side_values = $vc_content_s[ 'contentPadding' . $vc_content_side . 'Px' ] ?? array();
    $vc_content_side_desktop = $vc_content_side_values['desktop'] ?? null;
    $vc_content_side_mobile = $vc_content_side_values['mobile'] ?? $vc_content_side_desktop;
    if ( null !== $vc_content_side_desktop ) { $vc_content_vars[] = '--vc-content-pad-' . $vc_content_suffix . ':' . (int) $vc_content_side_desktop . 'px'; }
    if ( null !== $vc_content_side_mobile ) { $vc_content_vars[] = '--vc-content-pad-' . $vc_content_suffix . '-mobile:' . (int) $vc_content_side_mobile . 'px'; }
}
foreach ( array( 'contentBgColor' => '--vc-content-bg', 'textColor' => '--vc-content-fg', 'buttonTextColor' => '--vc-btn-fg', 'buttonBgColor' => '--vc-btn-bg' ) as $vc_content_key => $vc_content_var ) {
    if ( '' !== $vc_content_s[ $vc_content_key ] ) { $vc_content_vars[] = $vc_content_var . ':' . $vc_content_s[ $vc_content_key ]; }
}
$vc_content_button_class = 'button vc-content-rich-button';
if ( 'none' !== $vc_content_s['buttonEffect'] ) { $vc_content_button_class .= ' vc-btn--' . $vc_content_s['buttonEffect']; }
$vc_content_button = vc_module_button( $vc_content_s['button'], $vc_content_button_class );
?>
<div class="container">
    <div class="vc-content-rich vc-content-rich--<?php echo esc_attr( $vc_content_s['textAlign'] ); ?>" style="<?php echo esc_attr( implode( ';', $vc_content_vars ) ); ?>">
        <?php if ( '' !== $vc_content_s['title'] ) : ?><h2><?php echo esc_html( $vc_content_s['title'] ); ?></h2><?php endif; ?>
        <?php if ( '' !== $vc_content_s['bodyHtml'] ) : ?><div class="vc-content-rich-body"><?php echo wp_kses_post( wpautop( $vc_content_s['bodyHtml'] ) ); ?></div><?php endif; ?>
        <?php echo $vc_content_button; if ( 'editor' === ( $args['context'] ?? 'public' ) && '' === $vc_content_s['title'] && '' === $vc_content_s['bodyHtml'] && '' === $vc_content_button ) { echo \PeptideCore\Modules\Renderer::notice( __( '请在侧栏添加标题或正文。', 'peptide-core' ) ); } ?>
    </div>
</div>
