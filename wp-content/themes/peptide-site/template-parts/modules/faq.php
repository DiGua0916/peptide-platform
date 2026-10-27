<?php
defined( 'ABSPATH' ) || exit;
$vc_content_module = $args['module'];
$vc_content_s = $vc_content_module['settings'];
$vc_content_items = array_values( array_filter( $vc_content_module['items'], static fn( $item ) => $item['enabled'] || 'editor' === ( $args['context'] ?? 'public' ) ) );
$vc_content_colors = array();
if ( '' !== $vc_content_s['answerBgColor'] ) { $vc_content_colors[] = '--vc-content-answer-bg:' . $vc_content_s['answerBgColor']; }
if ( '' !== $vc_content_s['answerTextColor'] ) { $vc_content_colors[] = '--vc-content-answer-fg:' . $vc_content_s['answerTextColor']; }
?>
<div class="container vc-content-faq vc-content-faq--<?php echo esc_attr( $vc_content_s['layout'] ); ?>"<?php if ( $vc_content_colors ) { echo ' style="' . esc_attr( implode( ';', $vc_content_colors ) ) . '"'; } ?>>
    <header class="vc-content-heading vc-content-heading--vertical">
        <?php if ( '' !== $vc_content_s['eyebrow'] ) : ?><p class="eyebrow"><?php echo esc_html( $vc_content_s['eyebrow'] ); ?></p><?php endif; ?>
        <?php if ( '' !== $vc_content_s['title'] || '' !== $vc_content_s['titleAccent'] ) : ?><h2><?php echo esc_html( $vc_content_s['title'] ); ?><?php if ( '' !== $vc_content_s['titleAccent'] ) : ?><?php if ( '' !== $vc_content_s['title'] ) : ?><br><?php endif; ?><em><?php echo esc_html( $vc_content_s['titleAccent'] ); ?></em><?php endif; ?></h2><?php endif; ?>
        <?php if ( '' !== $vc_content_s['description'] ) : ?><p class="vc-content-intro"><?php echo nl2br( esc_html( $vc_content_s['description'] ) ); ?></p><?php endif; ?>
        <?php echo vc_module_button( $vc_content_s['link'], 'text-link' ); ?>
    </header>
    <div class="faq-list vc-content-questions">
        <?php foreach ( $vc_content_items as $vc_content_index => $vc_content_item ) : $vc_content_row = $vc_content_item['settings']; ?>
            <details class="vc-content-question<?php echo $vc_content_item['enabled'] ? '' : ' vc-content-item-disabled'; ?>" data-module-item="<?php echo esc_attr( $vc_content_item['id'] ); ?>"<?php echo 0 === $vc_content_index && $vc_content_s['openFirst'] ? ' open' : ''; ?>>
                <summary><?php echo esc_html( $vc_content_row['question'] ); ?><span aria-hidden="true">+</span></summary>
                <div class="vc-content-answer"><?php echo wp_kses_post( $vc_content_row['answerHtml'] ); ?></div>
            </details>
        <?php endforeach; ?>
    </div>
</div>
