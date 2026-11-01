<?php
defined( 'ABSPATH' ) || exit;
$module = $args['module']; $s = $module['settings'];
$items = vc_demo_home_items( $module, $args['context'] ?? 'public' );
?>
<div class="vc-demo-module vc-demo-faq"><div class="vc-demo-wrap vc-demo-faq-grid<?php echo 'stacked' === ( $s['layout'] ?? '' ) ? ' vc-demo-faq-grid--stacked' : ''; ?>">
    <header><?php if ( '' !== $s['eyebrow'] ) : ?><p class="vc-demo-kicker"><?php echo esc_html( $s['eyebrow'] ); ?></p><?php endif; ?><h2><?php echo nl2br( esc_html( $s['title'] ) ); ?><?php if ( '' !== $s['titleAccent'] ) : ?><br><?php echo esc_html( $s['titleAccent'] ); ?><?php endif; ?></h2><div class="vc-demo-faq-description"><?php echo vc_demo_home_description( $s ); ?></div><?php echo vc_demo_home_image( $s['supportImage'] ?? null, 'vc-demo-faq-image', '160px' ); ?><?php echo vc_module_button( $s['link'] ?? null, 'vc-demo-quiet-link' ); ?></header>
    <div class="vc-demo-faq-list"><?php foreach ( $items as $index => $item ) : $row = $item['settings']; ?>
        <details<?php echo vc_demo_home_item_attrs( $item ); ?><?php echo 0 === $index && ! empty( $s['openFirst'] ) ? ' open' : ''; ?>>
            <summary><?php echo esc_html( $row['question'] ); ?><span aria-hidden="true">+</span></summary><div class="vc-demo-faq-answer"<?php echo ! empty( $s['answerBgColor'] ) || ! empty( $s['answerTextColor'] ) ? ' style="' . esc_attr( ( ! empty( $s['answerBgColor'] ) ? 'background:' . $s['answerBgColor'] . ';' : '' ) . ( ! empty( $s['answerTextColor'] ) ? 'color:' . $s['answerTextColor'] : '' ) ) . '"' : ''; ?>><?php echo wp_kses_post( $row['answerHtml'] ); ?></div>
        </details>
    <?php endforeach; ?></div>
</div></div>
