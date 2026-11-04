<?php
defined( 'ABSPATH' ) || exit;
$module = $args['module']; $s = $module['settings'];
$items = vc_demo_home_items( $module, $args['context'] ?? 'public' );
$counts = vc_demo_home_catalog_counts();
$size = max( 14, min( 120, (int) ( $s['numberSizePx'] ?? 96 ) ) );
$cols = $s['columns'] ?? array( 'desktop' => 2, 'mobile' => 2 );
?>
<div class="vc-demo-module vc-demo-wrap vc-demo-stats" data-vc-demo-counters style="<?php echo esc_attr( '--vc-demo-counter-cols:' . max( 1, (int) $cols['desktop'] ) . ';--vc-demo-counter-cols-mobile:' . max( 1, (int) ( $cols['mobile'] ?? $cols['desktop'] ) ) . ';--vc-demo-number-size:' . $size . 'px' ); ?>">
<?php foreach ( $items as $item ) : $row = $item['settings']; $source = $row['valueSource'] ?? 'manual'; $actual = (float) ( $counts[ $source ] ?? $row['number'] ); $cap = max( 0, (int) ( $row['displayCap'] ?? 0 ) ); $capped = $cap > 0 && $actual > $cap; $number = $capped ? $cap : $actual; $precision = in_array( $source, array( 'products', 'specifications' ), true ) || $capped ? 0 : min( 6, strlen( explode( '.', rtrim( rtrim( sprintf( '%.6F', $number ), '0' ), '.' ) )[1] ?? '' ) ); ?>
    <div class="vc-demo-stat"<?php echo vc_demo_home_item_attrs( $item ); ?>><strong><span data-vc-demo-count="<?php echo esc_attr( $number ); ?>" data-vc-demo-actual="<?php echo esc_attr( $actual ); ?>" data-vc-demo-precision="<?php echo $precision; ?>"><?php echo esc_html( number_format_i18n( $number, $precision ) ); ?></span><?php echo esc_html( $capped ? '+' : $row['unit'] ); ?></strong><span class="vc-demo-stat-label"><?php echo esc_html( $row['label'] ); ?></span></div>
<?php endforeach; ?>
<?php if ( '' !== $s['title'] || '' !== vc_demo_home_description( $s ) ) : ?><div class="vc-demo-stat-statement"><?php if ( '' !== $s['title'] ) : ?><p><?php echo nl2br( esc_html( $s['title'] ) ); ?></p><?php endif; ?><?php if ( '' !== vc_demo_home_description( $s ) ) : ?><div><?php echo vc_demo_home_description( $s ); ?></div><?php endif; ?></div><?php endif; ?>
</div>
