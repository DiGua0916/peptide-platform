<?php
/**
 * Configurable image-and-text rows inside peptide-core's module wrapper.
 *
 * @var array $args Normalized module data and public/preview/editor context.
 */
defined( 'ABSPATH' ) || exit;

$vc_story_module   = $args['module'];
$vc_story_settings = $vc_story_module['settings'];
$vc_story_editor   = 'editor' === ( $args['context'] ?? 'public' );
$vc_story_width    = in_array( $vc_story_settings['container'], array( 'content', 'wide', 'full' ), true )
    ? $vc_story_settings['container']
    : 'content';
$vc_story_gap      = $vc_story_settings['itemGapPx'];
$vc_story_gap_d    = (int) $vc_story_gap['desktop'];
$vc_story_gap_m    = null !== $vc_story_gap['mobile'] ? (int) $vc_story_gap['mobile'] : $vc_story_gap_d;
$vc_story_heading = '' !== $vc_story_settings['eyebrow']
    || '' !== $vc_story_settings['title']
    || '' !== $vc_story_settings['titleAccent'];
$vc_story_intro = '' !== $vc_story_settings['descriptionHtml'];
?>
<div class="vc-story vc-story--<?php echo esc_attr( $vc_story_width ); ?>" style="<?php echo esc_attr( '--vc-story-gap:' . $vc_story_gap_d . 'px;--vc-story-gap-mobile:' . $vc_story_gap_m . 'px' ); ?>">
    <?php if ( $vc_story_heading || $vc_story_intro ) : ?>
        <header class="vc-story__heading<?php echo $vc_story_heading && $vc_story_intro ? '' : ' vc-story__heading--single'; ?>">
            <?php if ( $vc_story_heading ) : ?>
                <div class="vc-story__heading-copy">
                    <?php if ( '' !== $vc_story_settings['eyebrow'] ) : ?>
                        <span class="vc-story__eyebrow"><?php echo esc_html( $vc_story_settings['eyebrow'] ); ?></span>
                    <?php endif; ?>
                    <?php if ( '' !== $vc_story_settings['title'] || '' !== $vc_story_settings['titleAccent'] ) : ?>
                        <h2 class="vc-story__title"><?php echo esc_html( $vc_story_settings['title'] ); ?><?php if ( '' !== $vc_story_settings['titleAccent'] ) : ?><?php if ( '' !== $vc_story_settings['title'] ) : ?><br><?php endif; ?><em><?php echo esc_html( $vc_story_settings['titleAccent'] ); ?></em><?php endif; ?></h2>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ( $vc_story_intro ) : ?>
                <div class="vc-story__intro"><?php echo wp_kses_post( $vc_story_settings['descriptionHtml'] ); ?></div>
            <?php endif; ?>
        </header>
    <?php endif; ?>
    <div class="vc-story__rows">
        <?php
        foreach ( $vc_story_module['items'] as $vc_story_item ) :
            if ( ! $vc_story_item['enabled'] && ! $vc_story_editor ) {
                continue;
            }
            $vc_story_row      = $vc_story_item['settings'];
            $vc_story_pos      = $vc_story_row['imagePosition'];
            $vc_story_pos_d    = $vc_story_pos['desktop'];
            $vc_story_pos_m    = null !== $vc_story_pos['mobile'] ? $vc_story_pos['mobile'] : $vc_story_pos_d;
            $vc_story_sizes_d  = in_array( $vc_story_pos_d, array( 'left', 'right' ), true ) ? '50vw' : '100vw';
            $vc_story_sizes_m  = in_array( $vc_story_pos_m, array( 'left', 'right' ), true ) ? '50vw' : '100vw';
            $vc_story_picture = vc_module_picture( $vc_story_row['media'], 'vc-story__picture', '(max-width: 767px) ' . $vc_story_sizes_m . ', ' . $vc_story_sizes_d );
            $vc_story_button  = vc_module_button( $vc_story_row['button'], 'button vc-story__button' );
            $vc_story_copy    = '' !== $vc_story_row['eyebrow']
                || '' !== $vc_story_row['label']
                || '' !== $vc_story_row['title']
                || '' !== $vc_story_row['bodyHtml']
                || '' !== $vc_story_button;
            $vc_story_media = '' !== $vc_story_picture || '' !== $vc_story_row['caption'];
            if ( ! $vc_story_media && ! $vc_story_copy && ! $vc_story_editor ) {
                continue;
            }
            $vc_story_row_classes = array(
                'vc-story__row',
                'vc-story__row--d-' . $vc_story_pos_d,
                'vc-story__row--m-' . $vc_story_pos_m,
            );
            if ( ! $vc_story_media || ! $vc_story_copy ) {
                $vc_story_row_classes[] = 'vc-story__row--single';
            }
            if ( ! $vc_story_item['enabled'] ) {
                $vc_story_row_classes[] = 'is-hidden';
            }
            ?>
            <article class="<?php echo esc_attr( implode( ' ', $vc_story_row_classes ) ); ?>" data-module-item="<?php echo esc_attr( $vc_story_item['id'] ); ?>"<?php if ( $vc_story_settings['animation'] && ! $vc_story_editor ) : ?> data-vc-module-reveal<?php endif; ?>>
                <?php if ( $vc_story_media || $vc_story_editor ) : ?>
                    <figure class="vc-story__media">
                        <?php echo $vc_story_picture; // Escaped by the shared picture helper. ?>
                        <?php if ( '' === $vc_story_picture && $vc_story_editor ) : ?>
                            <div class="vc-story__placeholder"><?php esc_html_e( 'Choose an image', 'peptide-site' ); ?></div>
                        <?php endif; ?>
                        <?php if ( '' !== $vc_story_row['caption'] ) : ?>
                            <figcaption><?php echo esc_html( $vc_story_row['caption'] ); ?></figcaption>
                        <?php endif; ?>
                    </figure>
                <?php endif; ?>
                <?php if ( $vc_story_copy || $vc_story_editor ) : ?>
                    <div class="vc-story__copy">
                        <?php if ( '' !== $vc_story_row['eyebrow'] ) : ?>
                            <span class="vc-story__number"><?php echo esc_html( $vc_story_row['eyebrow'] ); ?></span>
                        <?php endif; ?>
                        <?php if ( '' !== $vc_story_row['label'] ) : ?>
                            <span class="vc-story__label"><?php echo esc_html( $vc_story_row['label'] ); ?></span>
                        <?php endif; ?>
                        <?php if ( '' !== $vc_story_row['title'] ) : ?>
                            <h3 class="vc-story__row-title"><?php echo esc_html( $vc_story_row['title'] ); ?></h3>
                        <?php endif; ?>
                        <?php if ( '' !== $vc_story_row['bodyHtml'] ) : ?>
                            <div class="vc-story__body"><?php echo wp_kses_post( $vc_story_row['bodyHtml'] ); ?></div>
                        <?php endif; ?>
                        <?php echo $vc_story_button; // Escaped by the shared link helper. ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if ( '' !== $vc_story_settings['footnote'] ) : ?>
        <p class="vc-story__note"><?php echo nl2br( esc_html( $vc_story_settings['footnote'] ) ); ?></p>
    <?php endif; ?>
</div>
