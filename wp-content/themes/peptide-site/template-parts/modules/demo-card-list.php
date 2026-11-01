<?php
defined( 'ABSPATH' ) || exit;
$module = $args['module']; $s = $module['settings'];
$items = vc_demo_home_items( $module, $args['context'] ?? 'public' );
$cols = $s['columns'] ?? array( 'desktop' => 3, 'mobile' => 1 );
?>
<div class="vc-demo-module vc-demo-wrap vc-demo-how">
    <header class="vc-demo-section-heading"><div><?php if ( '' !== ( $s['eyebrow'] ?? '' ) ) : ?><p class="vc-demo-kicker"><?php echo esc_html( $s['eyebrow'] ); ?></p><?php endif; ?><h2><?php echo nl2br( esc_html( $s['title'] ) ); ?></h2></div><div class="vc-demo-section-description"><?php echo vc_demo_home_description( $s ); ?></div></header>
    <div class="vc-demo-steps" style="<?php echo esc_attr( '--vc-demo-step-cols:' . (int) $cols['desktop'] . ';--vc-demo-step-cols-mobile:' . (int) ( $cols['mobile'] ?? $cols['desktop'] ) . ';--vc-demo-step-gap:' . (int) ( $s['gapPx'] ?? 32 ) . 'px;text-align:' . ( $s['textAlign'] ?? 'left' ) ); ?>">
        <?php foreach ( $items as $index => $item ) : $row = $item['settings']; ?><article<?php echo vc_demo_home_item_attrs( $item ); ?>>
            <span class="vc-demo-step-number"><?php echo esc_html( str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span>
            <?php echo vc_module_picture( $row['media'] ?? array(), 'vc-demo-step-media', '(max-width:767px) 85vw, 30vw' ); ?>
            <?php if ( '' !== ( $row['href'] ?? '' ) ) : ?><a href="<?php echo esc_url( $row['href'] ); ?>"><?php endif; ?><h3><?php echo esc_html( $row['title'] ); ?></h3><?php if ( '' !== ( $row['href'] ?? '' ) ) : ?></a><?php endif; ?>
            <p><?php echo nl2br( esc_html( $row['text'] ) ); ?></p>
        </article><?php endforeach; ?>
    </div>
</div>
