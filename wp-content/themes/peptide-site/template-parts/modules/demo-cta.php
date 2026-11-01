<?php
defined( 'ABSPATH' ) || exit;
$s = $args['module']['settings'];
$style = '';
if ( ! empty( $s['bgColor'] ) ) { $style .= 'background:' . $s['bgColor'] . ';'; }
if ( ! empty( $s['textColor'] ) ) { $style .= 'color:' . $s['textColor'] . ';'; }
?>
<div class="vc-demo-module vc-demo-wrap vc-demo-closing" style="<?php echo esc_attr( $style ); ?>"><div><?php if ( '' !== $s['eyebrow'] ) : ?><p class="vc-demo-kicker"><?php echo esc_html( $s['eyebrow'] ); ?></p><?php endif; ?><h2><?php echo nl2br( esc_html( $s['title'] ) ); ?></h2><?php if ( '' !== $s['description'] ) : ?><p><?php echo nl2br( esc_html( $s['description'] ) ); ?></p><?php endif; ?></div><?php echo vc_module_button( $s['button'], 'vc-demo-button' ); ?></div>
