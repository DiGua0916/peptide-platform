<?php
defined( 'ABSPATH' ) || exit;
$module = $args['module']; $s = $module['settings'];
$items = vc_demo_home_items( $module, $args['context'] ?? 'public' );
$size = $s['illustrationSizePx'] ?? array( 'desktop' => 72, 'mobile' => 64 );
?>
<div class="vc-demo-module vc-demo-wrap vc-demo-services<?php echo ! empty( $s['mobileSwipe'] ) ? ' vc-demo-services--swipe' : ''; ?>" style="<?php echo esc_attr( '--vc-demo-services-cols:' . max( 1, count( $items ) ) . ';--vc-demo-service-size:' . (int) $size['desktop'] . 'px;--vc-demo-service-size-mobile:' . (int) ( $size['mobile'] ?? $size['desktop'] ) . 'px;text-align:' . ( $s['align'] ?? 'left' ) ); ?>">
<?php foreach ( $items as $item ) : $row = $item['settings']; $href = $row['link']['href'] ?? ''; ?>
    <div class="vc-demo-service"<?php echo vc_demo_home_item_attrs( $item ); ?>>
        <?php if ( '' !== $href ) : ?><a href="<?php echo esc_url( $href ); ?>"<?php echo '_blank' === ( $row['link']['target'] ?? '' ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php endif; ?>
        <?php echo vc_demo_home_image( $row['image'] ?? null, 'vc-demo-service-image' ); ?>
        <div><div class="vc-demo-service-title"><?php echo null !== ( $row['textHtml'] ?? null ) ? wp_kses_post( $row['textHtml'] ) : esc_html( $row['text'] ); ?></div><?php if ( '' !== ( $row['subtext'] ?? '' ) ) : ?><p><?php echo esc_html( $row['subtext'] ); ?></p><?php endif; ?></div>
        <?php if ( '' !== $href ) : ?></a><?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
