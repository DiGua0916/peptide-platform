<?php
/**
 * VialCove carousel, rendered from the normalized layout module.
 * peptide-core owns the outer section, its visibility, spacing and background.
 */
defined( 'ABSPATH' ) || exit;

$vc_carousel_module = $args['module'];
$vc_carousel_s      = $vc_carousel_module['settings'];
$vc_carousel_editor = 'editor' === ( $args['context'] ?? 'public' );
$vc_carousel_device = in_array( $args['device'] ?? 'all', array( 'all', 'desktop', 'mobile' ), true ) ? ( $args['device'] ?? 'all' ) : 'all';
$vc_carousel_items  = array_values(
    array_filter(
        $vc_carousel_module['items'],
        static fn( $item ) => $item['enabled'] || $vc_carousel_editor
    )
);
if ( ! $vc_carousel_items ) {
    return;
}
$vc_carousel_id    = wp_unique_id( 'vc-carousel-' );
$vc_carousel_vars = array(
    '--vc-carousel-title:' . (int) $vc_carousel_s['titleSizePx'] . 'px',
    '--vc-carousel-height:' . (int) $vc_carousel_s['heightPx']['desktop'] . 'px',
    '--vc-carousel-height-mobile:' . (int) ( null !== $vc_carousel_s['heightPx']['mobile'] ? $vc_carousel_s['heightPx']['mobile'] : $vc_carousel_s['heightPx']['desktop'] ) . 'px',
);
$vc_carousel_icons = array(
    'check'    => 'M5 12l5 5L20 7',
    'truck'    => 'M3 6h11v9H3zM14 9h4l3 3v3h-7zM7 18a2 2 0 1 0 0-.01M17 18a2 2 0 1 0 0-.01',
    'shield'   => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z',
    'chat'     => 'M4 5h16v10H8l-4 4z',
    'mail'     => 'M3 6h18v12H3zM3 7l9 6 9-6',
    'phone'    => 'M6 3h4l2 5-2.5 1.5a11 11 0 0 0 5 5L16 12l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 4 5a2 2 0 0 1 2-2z',
    'location' => 'M12 21s-7-6.5-7-12a7 7 0 0 1 14 0c0 5.5-7 12-7 12zM12 11a2 2 0 1 0 0-.01',
    'star'     => 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z',
    'clock'    => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v5l3 3',
    'flask'    => 'M9 3h6M10 3v6l-6 10a1.5 1.5 0 0 0 1.3 2h13.4a1.5 1.5 0 0 0 1.3-2L14 9V3',
    'box'      => 'M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10',
    'globe'    => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18',
    'lock'     => 'M6 11h12v10H6zM8 11V7a4 4 0 0 1 8 0v4',
    'document' => 'M6 3h8l4 4v14H6zM14 3v4h4M9 12h6M9 16h6',
);
$vc_carousel_icon = static function ( string $name ) use ( $vc_carousel_icons ): string {
    if ( ! isset( $vc_carousel_icons[ $name ] ) ) {
        return '';
    }
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . esc_attr( $vc_carousel_icons[ $name ] ) . '"/></svg>';
};
?>
<div class="vc-module-carousel vc-module-carousel--<?php echo esc_attr( $vc_carousel_s['height'] ); ?><?php echo $vc_carousel_s['fullWidth'] ? ' vc-module-carousel--full' : ''; ?><?php echo $vc_carousel_editor ? ' vc-module-carousel--editor' : ''; ?>" data-vc-module-carousel data-vc-device="<?php echo esc_attr( $vc_carousel_device ); ?>" data-autoplay="<?php echo $vc_carousel_s['autoplay'] ? '1' : '0'; ?>" data-interval="<?php echo (int) $vc_carousel_s['intervalMs']; ?>" data-editor="<?php echo $vc_carousel_editor ? '1' : '0'; ?>" style="<?php echo esc_attr( implode( ';', $vc_carousel_vars ) ); ?>" role="region" aria-roledescription="<?php esc_attr_e( 'carousel', 'peptide-site' ); ?>" aria-label="<?php esc_attr_e( 'Featured content', 'peptide-site' ); ?>" aria-describedby="<?php echo esc_attr( $vc_carousel_id . '-help' ); ?>" tabindex="0">
    <p class="vc-carousel-sr" id="<?php echo esc_attr( $vc_carousel_id . '-help' ); ?>"><?php esc_html_e( 'Use the left and right arrow keys to change slides. Press Space on the carousel to pause or resume automatic playback.', 'peptide-site' ); ?></p>
    <div class="vc-carousel-track">
        <?php
        foreach ( $vc_carousel_items as $vc_carousel_index => $vc_carousel_item ) :
            $vc_slide = $vc_carousel_item['settings'];
            $vc_slide_position_m = null !== $vc_slide['position']['mobile'] ? $vc_slide['position']['mobile'] : $vc_slide['position']['desktop'];
            $vc_slide_vars       = array( '--vc-slide-overlay-opacity:' . ( (int) $vc_slide['overlayOpacity'] / 100 ) );
            foreach ( array( 'overlayColor' => '--vc-slide-overlay', 'textColor' => '--vc-slide-color', 'buttonTextColor' => '--vc-slide-button-color', 'buttonBgColor' => '--vc-slide-button-bg' ) as $vc_color_key => $vc_color_var ) {
                if ( '' !== $vc_slide[ $vc_color_key ] ) {
                    $vc_slide_vars[] = $vc_color_var . ':' . $vc_slide[ $vc_color_key ];
                }
            }
            $vc_slide_label = sprintf(
                /* translators: 1: slide number, 2: total slides, 3: optional slide name. */
                __( '%1$d of %2$d%3$s', 'peptide-site' ),
                $vc_carousel_index + 1,
                count( $vc_carousel_items ),
                '' !== $vc_slide['name'] ? ': ' . $vc_slide['name'] : ''
            );
            $vc_slide_facts = array_values( array_filter( $vc_slide['facts'], static fn( $item ) => $item['enabled'] || $vc_carousel_editor ) );
            $vc_slide_cards = array_values( array_filter( $vc_slide['highlights'], static fn( $item ) => $item['enabled'] || $vc_carousel_editor ) );
            $vc_card_layout = $vc_slide['cardLayout'] ?? array( 'desktop' => 'overlay', 'mobile' => 'hidden' );
            $vc_card_layout_d = $vc_card_layout['desktop'] ?? 'overlay';
            $vc_card_layout_m = $vc_card_layout['mobile'] ?? $vc_card_layout_d;
            $vc_card_layout_d = in_array( $vc_card_layout_d, array( 'overlay', 'below', 'hidden' ), true ) ? $vc_card_layout_d : 'overlay';
            $vc_card_layout_m = in_array( $vc_card_layout_m, array( 'overlay', 'below', 'hidden' ), true ) ? $vc_card_layout_m : 'hidden';
            $vc_slide_button = vc_module_button( $vc_slide['button'], 'button vc-slide-button' );
            $vc_slide_link   = vc_module_button( $vc_slide['secondaryLink'], 'vc-slide-link' );
            $vc_slide_picture = vc_module_picture(
                $vc_slide['media'],
                'vc-slide-picture',
                'split' === $vc_slide['layout'] ? '(max-width: 767px) 100vw, 55vw' : '100vw',
                0 === $vc_carousel_index ? array( 'loading' => 'eager', 'fetchpriority' => 'high' ) : array( 'loading' => 'lazy' )
            );
            ?>
            <article id="<?php echo esc_attr( $vc_carousel_id . '-slide-' . $vc_carousel_index ); ?>" class="vc-slide-panel vc-slide-panel--<?php echo esc_attr( $vc_slide['layout'] ); ?><?php echo 0 === $vc_carousel_index ? ' is-active' : ''; ?><?php echo ! $vc_carousel_item['enabled'] ? ' is-hidden' : ''; ?>" data-module-item="<?php echo esc_attr( $vc_carousel_item['id'] ); ?>" data-slide-label="<?php echo esc_attr( $vc_slide_label ); ?>" role="group" aria-roledescription="<?php esc_attr_e( 'slide', 'peptide-site' ); ?>" aria-label="<?php echo esc_attr( $vc_slide_label ); ?>" style="<?php echo esc_attr( implode( ';', $vc_slide_vars ) ); ?>"<?php if ( 0 !== $vc_carousel_index && ! $vc_carousel_editor ) : ?> hidden inert aria-hidden="true"<?php endif; ?>>
                <div class="vc-slide-copy vc-slide-position-d-<?php echo esc_attr( $vc_slide['position']['desktop'] ); ?> vc-slide-position-m-<?php echo esc_attr( $vc_slide_position_m ); ?>">
                    <?php if ( '' !== $vc_slide['eyebrow'] ) : ?><span class="vc-slide-eyebrow"><?php echo esc_html( $vc_slide['eyebrow'] ); ?></span><?php endif; ?>
                    <?php if ( '' !== $vc_slide['title'] || '' !== $vc_slide['titleAccent'] ) : ?>
                        <?php $vc_slide_heading = 0 === $vc_carousel_index ? 'h1' : 'h2'; ?>
                        <<?php echo $vc_slide_heading; ?> class="vc-slide-title"><?php echo esc_html( $vc_slide['title'] ); ?><?php if ( '' !== $vc_slide['titleAccent'] ) : ?><?php if ( '' !== $vc_slide['title'] ) : ?><br><?php endif; ?><em><?php echo esc_html( $vc_slide['titleAccent'] ); ?></em><?php endif; ?></<?php echo $vc_slide_heading; ?>>
                    <?php endif; ?>
                    <?php if ( '' !== $vc_slide['description'] ) : ?><p class="vc-slide-description"><?php echo nl2br( esc_html( $vc_slide['description'] ) ); ?></p><?php endif; ?>
                    <?php if ( '' !== $vc_slide_button || '' !== $vc_slide_link ) : ?><div class="vc-slide-actions"><?php echo $vc_slide_button . $vc_slide_link; // Escaped by the link helper. ?></div><?php endif; ?>
                    <?php if ( $vc_slide_facts ) : ?>
                        <ul class="vc-slide-facts">
                            <?php foreach ( $vc_slide_facts as $vc_fact ) : ?>
                                <li<?php echo ! $vc_fact['enabled'] ? ' class="is-hidden"' : ''; ?> data-module-item="<?php echo esc_attr( $vc_fact['id'] ); ?>">
                                    <?php if ( '' !== $vc_fact['settings']['label'] ) : ?><strong><?php echo esc_html( $vc_fact['settings']['label'] ); ?></strong><?php endif; ?>
                                    <?php if ( '' !== $vc_fact['settings']['text'] ) : ?><span><?php echo esc_html( $vc_fact['settings']['text'] ); ?></span><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ( '' !== $vc_slide['footnote'] ) : ?><p class="vc-slide-footnote"><?php echo esc_html( $vc_slide['footnote'] ); ?></p><?php endif; ?>
                </div>
                <figure class="vc-slide-figure">
                    <div class="vc-slide-media-area" data-pc-drag-area>
                    <div class="vc-slide-stage">
                        <?php echo $vc_slide_picture; // Escaped by the shared media helper. ?>
                        <?php if ( '' === $vc_slide_picture && $vc_carousel_editor ) : ?><div class="vc-slide-placeholder"><?php esc_html_e( 'Choose an image', 'peptide-site' ); ?></div><?php endif; ?>
                        <div class="vc-slide-tint" aria-hidden="true"></div>
                        <?php if ( '' !== $vc_slide['sceneNumber'] ) : ?><span class="vc-slide-number"><?php echo esc_html( $vc_slide['sceneNumber'] ); ?></span><?php endif; ?>
                    </div>
                    <?php if ( $vc_slide_cards ) : ?>
                        <div class="vc-slide-cards vc-cards-d-<?php echo esc_attr( $vc_card_layout_d ); ?> vc-cards-m-<?php echo esc_attr( $vc_card_layout_m ); ?>" style="--vc-card-count:<?php echo (int) count( $vc_slide_cards ); ?>">
                            <?php foreach ( $vc_slide_cards as $vc_card ) :
                                $vc_card_s = $vc_card['settings'];
                                $vc_card_tag = '' !== $vc_card_s['href'] ? 'a' : 'div';
                                $vc_card_vars = array();
                                $vc_card_classes = 'vc-slide-card' . ( ! $vc_card['enabled'] ? ' is-hidden' : '' );
                                foreach ( array( 'desktop' => 'd', 'mobile' => 'm' ) as $vc_card_device => $vc_card_suffix ) {
                                    $vc_card_x = $vc_card_s['x'][ $vc_card_device ] ?? null;
                                    $vc_card_y = $vc_card_s['y'][ $vc_card_device ] ?? null;
                                    // Match the plugin's device fields: unset mobile values inherit desktop.
                                    if ( 'mobile' === $vc_card_device ) {
                                        $vc_card_x = $vc_card_x ?? ( $vc_card_s['x']['desktop'] ?? null );
                                        $vc_card_y = $vc_card_y ?? ( $vc_card_s['y']['desktop'] ?? null );
                                    }
                                    // With no complete position, keep automatic layout; zero is a valid position.
                                    if ( is_numeric( $vc_card_x ) && is_numeric( $vc_card_y ) ) {
                                        $vc_card_classes .= ' vc-card-placed-' . $vc_card_suffix;
                                        $vc_card_vars[] = '--vc-card-x-' . $vc_card_suffix . ':' . max( 0, min( 100, (float) $vc_card_x ) ) . '%';
                                        $vc_card_vars[] = '--vc-card-y-' . $vc_card_suffix . ':' . max( 0, min( 100, (float) $vc_card_y ) ) . '%';
                                    }
                                }
                            ?>
                                <<?php echo $vc_card_tag; ?> class="<?php echo esc_attr( $vc_card_classes ); ?>"<?php if ( 'a' === $vc_card_tag ) : ?> href="<?php echo esc_url( $vc_card_s['href'] ); ?>"<?php endif; ?> data-module-item="<?php echo esc_attr( $vc_card['id'] ); ?>" data-vc-card data-pc-drag<?php if ( $vc_card_vars ) : ?> style="<?php echo esc_attr( implode( ';', $vc_card_vars ) ); ?>"<?php endif; ?>>
                                    <?php if ( '' !== $vc_card_s['icon'] || '' !== $vc_card_s['label'] ) : ?><span class="vc-slide-card-label"><?php echo $vc_carousel_icon( $vc_card_s['icon'] ); ?><?php echo esc_html( $vc_card_s['label'] ); ?></span><?php endif; ?>
                                    <?php if ( '' !== $vc_card_s['title'] ) : ?><strong><?php echo esc_html( $vc_card_s['title'] ); ?></strong><?php endif; ?>
                                    <?php if ( '' !== $vc_card_s['action'] ) : ?><span class="vc-slide-card-action"><?php echo esc_html( $vc_card_s['action'] ); ?><?php if ( 'a' === $vc_card_tag ) : ?><span aria-hidden="true">→</span><?php endif; ?></span><?php endif; ?>
                                </<?php echo $vc_card_tag; ?>>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    </div>
                    <?php if ( '' !== $vc_slide['captionTitle'] || '' !== $vc_slide['captionNote'] ) : ?>
                        <figcaption class="vc-slide-caption">
                            <?php if ( '' !== $vc_slide['captionTitle'] ) : ?><strong><?php echo esc_html( $vc_slide['captionTitle'] ); ?></strong><?php endif; ?>
                            <?php if ( '' !== $vc_slide['captionNote'] ) : ?><span><?php echo esc_html( $vc_slide['captionNote'] ); ?></span><?php endif; ?>
                        </figcaption>
                    <?php endif; ?>
                </figure>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if ( count( $vc_carousel_items ) > 1 && ( $vc_carousel_s['pagination'] || $vc_carousel_s['arrows'] ) && ! $vc_carousel_editor ) : ?>
        <div class="vc-carousel-controls" hidden>
            <?php if ( $vc_carousel_s['pagination'] ) : ?>
                <div class="vc-carousel-dots" role="group" aria-label="<?php esc_attr_e( 'Choose a slide', 'peptide-site' ); ?>">
                    <?php foreach ( $vc_carousel_items as $vc_carousel_index => $vc_carousel_item ) : ?>
                        <button type="button" data-vc-slide-to="<?php echo (int) $vc_carousel_index; ?>" aria-controls="<?php echo esc_attr( $vc_carousel_id . '-slide-' . $vc_carousel_index ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Show slide %d', 'peptide-site' ), $vc_carousel_index + 1 ) . ( '' !== $vc_carousel_item['settings']['name'] ? ': ' . $vc_carousel_item['settings']['name'] : '' ) ); ?>"<?php echo 0 === $vc_carousel_index ? ' aria-current="true"' : ''; ?>><span></span></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ( $vc_carousel_s['arrows'] ) : ?>
                <div class="vc-carousel-arrows">
                    <button type="button" data-vc-slide-prev aria-label="<?php esc_attr_e( 'Previous slide', 'peptide-site' ); ?>"><span aria-hidden="true">←</span></button>
                    <button type="button" data-vc-slide-next aria-label="<?php esc_attr_e( 'Next slide', 'peptide-site' ); ?>"><span aria-hidden="true">→</span></button>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <p class="vc-carousel-sr vc-carousel-status" aria-live="polite" aria-atomic="true"></p>
</div>
