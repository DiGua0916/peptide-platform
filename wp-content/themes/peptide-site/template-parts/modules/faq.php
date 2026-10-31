<?php
defined( 'ABSPATH' ) || exit;
$vc_content_module = $args['module'];
$vc_content_s = $vc_content_module['settings'];
$vc_content_staff = 'public' !== ( $args['context'] ?? 'public' );
$vc_content_items = array_values( array_filter( $vc_content_module['items'], static fn( $item ) => $item['enabled'] || 'editor' === ( $args['context'] ?? 'public' ) ) );
$vc_content_colors = array();
if ( '' !== $vc_content_s['answerBgColor'] ) { $vc_content_colors[] = '--vc-content-answer-bg:' . $vc_content_s['answerBgColor']; }
if ( '' !== $vc_content_s['answerTextColor'] ) { $vc_content_colors[] = '--vc-content-answer-fg:' . $vc_content_s['answerTextColor']; }
$vc_content_panel = 'panel' === $vc_content_s['layout'];
$vc_content_rich = $vc_content_s['descriptionHtml'] ?? null;
$vc_content_opened = false;
if ( $vc_content_panel && ! $vc_content_staff ) {
    $vc_content_items = array_values( array_filter( $vc_content_items, static function ( $item ) {
        $row = $item['settings'];
        return '' !== trim( $row['question'] ) || (bool) preg_match( '/<img\b/i', $row['answerHtml'] ) || '' !== trim( html_entity_decode( wp_strip_all_tags( $row['answerHtml'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), " \t\n\r\0\x0B\xc2\xa0" );
    } ) );
    if ( ! $vc_content_items && '' === $vc_content_s['eyebrow'] . $vc_content_s['title'] . $vc_content_s['titleAccent'] . ( null === $vc_content_rich ? $vc_content_s['description'] : $vc_content_rich ) . ( $vc_content_s['link']['label'] ?? '' ) ) { return; }
}
?>
<div class="container vc-content-faq vc-content-faq--<?php echo esc_attr( $vc_content_s['layout'] ); ?>"<?php if ( $vc_content_colors ) { echo ' style="' . esc_attr( implode( ';', $vc_content_colors ) ) . '"'; } ?>>
    <header class="vc-content-heading vc-content-heading--vertical">
        <?php if ( '' !== $vc_content_s['eyebrow'] ) : ?><p class="eyebrow"><?php echo esc_html( $vc_content_s['eyebrow'] ); ?></p><?php endif; ?>
        <?php if ( '' !== $vc_content_s['title'] || '' !== $vc_content_s['titleAccent'] ) : ?><h2><?php echo esc_html( $vc_content_s['title'] ); ?><?php if ( '' !== $vc_content_s['titleAccent'] ) : ?><?php if ( '' !== $vc_content_s['title'] ) : ?><br><?php endif; ?><em><?php echo esc_html( $vc_content_s['titleAccent'] ); ?></em><?php endif; ?></h2><?php endif; ?>
        <?php if ( null !== $vc_content_rich && '' !== $vc_content_rich ) : ?><div class="vc-content-intro"><?php echo wp_kses_post( wpautop( $vc_content_rich ) ); ?></div><?php elseif ( null === $vc_content_rich && '' !== $vc_content_s['description'] ) : ?><p class="vc-content-intro"><?php echo nl2br( esc_html( $vc_content_s['description'] ) ); ?></p><?php endif; ?>
        <?php echo vc_module_button( $vc_content_s['link'], 'text-link' ); ?>
    </header>
    <?php if ( ! $vc_content_panel || $vc_content_items ) : ?>
    <div class="faq-list vc-content-questions">
        <?php if ( $vc_content_panel ) : ?><div class="vc-content-faq-panel-list"><?php endif; ?>
        <?php foreach ( $vc_content_items as $vc_content_index => $vc_content_item ) :
            $vc_content_row = $vc_content_item['settings'];
            $vc_content_answer = wp_kses_post( $vc_content_row['answerHtml'] );
            $vc_content_has_answer = (bool) preg_match( '/<img\b/i', $vc_content_answer ) || '' !== trim( html_entity_decode( wp_strip_all_tags( $vc_content_answer ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), " \t\n\r\0\x0B\xc2\xa0" );
            $vc_content_has_question = '' !== trim( $vc_content_row['question'] );
            if ( $vc_content_panel && ! $vc_content_has_answer && ! $vc_content_has_question && ! $vc_content_staff ) { continue; }
            if ( $vc_content_panel && ! $vc_content_has_answer ) : ?>
            <div class="vc-content-question vc-content-question--static<?php echo $vc_content_item['enabled'] ? '' : ' vc-content-item-disabled'; ?>" data-module-item="<?php echo esc_attr( $vc_content_item['id'] ); ?>"><p><?php echo esc_html( $vc_content_has_question ? $vc_content_row['question'] : __( 'Add a question or answer', 'peptide-core' ) ); ?></p></div>
            <?php else :
                $vc_content_open = $vc_content_panel ? ( ! $vc_content_opened && $vc_content_s['openFirst'] ) : ( 0 === $vc_content_index && $vc_content_s['openFirst'] );
                $vc_content_opened = true;
                $vc_content_question = $vc_content_panel && ! $vc_content_has_question ? __( 'View answer', 'peptide-core' ) : $vc_content_row['question']; ?>
            <details class="vc-content-question<?php echo $vc_content_item['enabled'] ? '' : ' vc-content-item-disabled'; ?>" data-module-item="<?php echo esc_attr( $vc_content_item['id'] ); ?>"<?php echo $vc_content_open ? ' open' : ''; ?>>
                <summary><?php echo esc_html( $vc_content_question ); ?><?php if ( $vc_content_panel ) : ?><span class="vc-content-faq-chevron" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none"><path d="m4 6 4 4 4-4" stroke="currentColor" stroke-width="1.5"/></svg></span><?php else : ?><span aria-hidden="true">+</span><?php endif; ?></summary>
                <div class="vc-content-answer"><?php echo $vc_content_answer; ?></div>
            </details>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ( $vc_content_panel ) : ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
