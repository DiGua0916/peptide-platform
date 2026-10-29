<?php
/** One independently editable image-and-text row. */
defined( 'ABSPATH' ) || exit;

$vc_media_module = $args['module'];
$vc_media_s      = $vc_media_module['settings'];
$vc_media_editor = 'editor' === ( $args['context'] ?? 'public' );
$vc_media_d      = $vc_media_s['imagePosition']['desktop'];
$vc_media_m      = null !== $vc_media_s['imagePosition']['mobile'] ? $vc_media_s['imagePosition']['mobile'] : $vc_media_d;
$vc_media_sizes  = '(max-width: 767px) ' . ( in_array( $vc_media_m, array( 'left', 'right' ), true ) ? '50vw' : '100vw' ) . ', '
    . ( in_array( $vc_media_d, array( 'left', 'right' ), true ) ? '50vw' : '100vw' );
$vc_media_picture = vc_module_picture( $vc_media_s['media'], 'vc-story__picture', $vc_media_sizes );
$vc_media_button  = vc_module_button( $vc_media_s['button'], 'button vc-story__button' );
$vc_media_has_copy = '' !== $vc_media_s['eyebrow'] || '' !== $vc_media_s['label'] || '' !== $vc_media_s['title']
    || '' !== $vc_media_s['bodyHtml'] || '' !== $vc_media_button;
$vc_media_has_image = '' !== $vc_media_picture || '' !== $vc_media_s['caption'];
$vc_media_vars = array( 'text-align:' . $vc_media_s['textAlign'] );
foreach ( array( 'titleColor' => '--vc-story-title-color', 'textColor' => '--vc-story-text-color', 'contentBgColor' => '--vc-story-copy-bg', 'buttonTextColor' => '--vc-story-button-color', 'buttonBgColor' => '--vc-story-button-bg' ) as $vc_media_key => $vc_media_var ) {
    if ( isset( $vc_media_s[ $vc_media_key ] ) && '' !== $vc_media_s[ $vc_media_key ] ) {
        $vc_media_vars[] = $vc_media_var . ':' . $vc_media_s[ $vc_media_key ];
    }
}
?>
<div class="vc-story vc-story--<?php echo $vc_media_s['fullWidth'] ? 'full' : 'content'; ?> vc-media-text" style="<?php echo esc_attr( implode( ';', $vc_media_vars ) ); ?>">
    <div class="vc-story__row vc-story__row--d-<?php echo esc_attr( $vc_media_d ); ?> vc-story__row--m-<?php echo esc_attr( $vc_media_m ); ?><?php echo ! $vc_media_has_copy || ! $vc_media_has_image ? ' vc-story__row--single' : ''; ?>"<?php if ( $vc_media_s['animation'] && ! $vc_media_editor ) : ?> data-vc-module-reveal<?php endif; ?>>
        <?php if ( $vc_media_has_image || $vc_media_editor ) : ?>
            <figure class="vc-story__media">
                <?php echo $vc_media_picture; // Escaped by the shared media helper. ?>
                <?php if ( '' === $vc_media_picture && $vc_media_editor ) : ?><div class="vc-story__placeholder"><?php esc_html_e( 'Choose an image', 'peptide-site' ); ?></div><?php endif; ?>
                <?php if ( '' !== $vc_media_s['caption'] ) : ?><figcaption><?php echo esc_html( $vc_media_s['caption'] ); ?></figcaption><?php endif; ?>
            </figure>
        <?php endif; ?>
        <?php if ( $vc_media_has_copy || $vc_media_editor ) : ?>
            <div class="vc-story__copy<?php echo '' !== $vc_media_s['contentBgColor'] ? ' vc-story__copy--colored' : ''; ?>">
                <?php if ( '' !== $vc_media_s['eyebrow'] ) : ?><span class="vc-story__number"><?php echo esc_html( $vc_media_s['eyebrow'] ); ?></span><?php endif; ?>
                <?php if ( '' !== $vc_media_s['label'] ) : ?><span class="vc-story__label"><?php echo esc_html( $vc_media_s['label'] ); ?></span><?php endif; ?>
                <?php if ( '' !== $vc_media_s['title'] ) : ?><h2 class="vc-story__row-title"><?php echo esc_html( $vc_media_s['title'] ); ?></h2><?php endif; ?>
                <?php if ( '' !== $vc_media_s['bodyHtml'] ) : ?><div class="vc-story__body"><?php echo wp_kses_post( wpautop( $vc_media_s['bodyHtml'] ) ); ?></div><?php endif; ?>
                <?php if ( '' !== $vc_media_button ) : ?>
                    <span class="vc-media-text__button-effect"<?php if ( ! $vc_media_editor && 'none' !== $vc_media_s['buttonEffect'] ) : ?> data-vc-button-effect="<?php echo esc_attr( $vc_media_s['buttonEffect'] ); ?>" data-vc-effect-duration="<?php echo (int) $vc_media_s['effectDurationMs']; ?>" data-vc-effect-interval="<?php echo (int) $vc_media_s['effectIntervalMs']; ?>"<?php endif; ?>><?php echo $vc_media_button; // Escaped by the shared link helper. ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
