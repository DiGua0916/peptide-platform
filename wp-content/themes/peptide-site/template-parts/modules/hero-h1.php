<?php
/** Homepage headline presentation; all content and device choices belong to the layout. */
defined( 'ABSPATH' ) || exit;

$vc_home_module = $args['module'];
$vc_home_s = $vc_home_module['settings'];
$vc_home_context = $args['context'] ?? 'public';
$vc_home_editor = 'editor' === $vc_home_context;
$vc_home_staff = in_array( $vc_home_context, array( 'editor', 'preview' ), true );
$vc_home_desktop = $vc_home_s['mediaPosition']['desktop'];
$vc_home_mobile = null !== $vc_home_s['mediaPosition']['mobile'] ? $vc_home_s['mediaPosition']['mobile'] : $vc_home_desktop;
$vc_home_sizes = '(max-width: 767px) ' . ( in_array( $vc_home_mobile, array( 'left', 'right' ), true ) ? '50vw' : '100vw' )
    . ', ' . ( in_array( $vc_home_desktop, array( 'left', 'right' ), true ) ? '50vw' : '100vw' );
$vc_home_picture = $vc_home_s['showMedia']
    ? vc_module_picture( $vc_home_s['media'], 'vc-home-hero__picture', $vc_home_sizes, array( 'loading' => 'eager', 'fetchpriority' => 'high' ) )
    : '';
$vc_home_has_media = $vc_home_s['showMedia'] && ( '' !== $vc_home_picture || $vc_home_staff );
$vc_home_button = vc_module_button( $vc_home_s['button'], 'button vc-home-hero__button' );
$vc_home_has_copy = $vc_home_staff || '' !== $vc_home_s['title'] || '' !== $vc_home_s['description'] || '' !== $vc_home_button;
$vc_home_styles = array( 'text-align:' . $vc_home_s['textAlign'] );
foreach ( array(
    'titleColor' => '--vc-home-title-color',
    'textColor' => '--vc-home-text-color',
    'contentBgColor' => '--vc-home-copy-bg',
    'buttonTextColor' => '--vc-home-button-color',
    'buttonBgColor' => '--vc-home-button-bg',
) as $vc_home_key => $vc_home_var ) {
    if ( '' !== $vc_home_s[ $vc_home_key ] ) {
        $vc_home_styles[] = $vc_home_var . ':' . $vc_home_s[ $vc_home_key ];
    }
}
?>
<div class="vc-home-hero vc-home-hero--<?php echo $vc_home_s['fullWidth'] ? 'full' : 'content'; ?> vc-home-hero--align-<?php echo esc_attr( $vc_home_s['textAlign'] ); ?> vc-home-hero--d-<?php echo esc_attr( $vc_home_desktop ); ?> vc-home-hero--m-<?php echo esc_attr( $vc_home_mobile ); ?><?php echo ! $vc_home_has_media || ! $vc_home_has_copy ? ' vc-home-hero--single' : ''; ?>" style="<?php echo esc_attr( implode( ';', $vc_home_styles ) ); ?>">
    <?php if ( $vc_home_has_copy ) : ?>
        <div class="vc-home-hero__copy<?php echo '' !== $vc_home_s['contentBgColor'] ? ' vc-home-hero__copy--colored' : ''; ?>">
            <?php if ( '' !== $vc_home_s['title'] ) : ?>
                <h1 class="vc-home-hero__title"><?php echo esc_html( $vc_home_s['title'] ); ?></h1>
            <?php elseif ( $vc_home_staff ) : ?>
                <?php echo \PeptideCore\Modules\Renderer::notice( __( 'Add the headline.', 'peptide-site' ) ); ?>
            <?php endif; ?>
            <?php if ( '' !== $vc_home_s['description'] ) : ?><p class="vc-home-hero__description"><?php echo nl2br( esc_html( $vc_home_s['description'] ) ); ?></p><?php endif; ?>
            <?php if ( '' !== $vc_home_button ) : ?>
                <span class="vc-home-hero__button-effect"<?php if ( ! $vc_home_editor && 'none' !== $vc_home_s['buttonEffect'] ) : ?> data-vc-button-effect="<?php echo esc_attr( $vc_home_s['buttonEffect'] ); ?>" data-vc-effect-duration="<?php echo (int) $vc_home_s['effectDurationMs']; ?>" data-vc-effect-interval="<?php echo (int) $vc_home_s['effectIntervalMs']; ?>"<?php endif; ?>><?php echo $vc_home_button; // Escaped by the shared link helper. ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ( $vc_home_has_media ) : ?>
        <figure class="vc-home-hero__media">
            <?php echo $vc_home_picture; // Escaped by the shared responsive media helper. ?>
            <?php if ( '' === $vc_home_picture && $vc_home_staff ) : ?><div class="vc-home-hero__placeholder"><?php esc_html_e( 'Choose an image', 'peptide-site' ); ?></div><?php endif; ?>
        </figure>
    <?php endif; ?>
</div>
