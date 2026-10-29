<?php
defined( 'ABSPATH' ) || exit;
$vc_menu = array_key_exists( 'items', $args ) ? vc_resolve_navigation_menu( $args['items'], 'footer' ) : vc_site_menu( 'footer' );
if ( ! $vc_menu ) { return; }
?>
<nav class="vc-footer-navigation" aria-label="Footer navigation">
    <?php foreach ( $vc_menu as $vc_item ) : ?>
    <div class="vc-footer-menu-group">
        <?php echo vc_menu_link( $vc_item ); ?>
        <?php if ( ! empty( $vc_item['children'] ) ) : ?>
        <ul><?php foreach ( $vc_item['children'] as $vc_child ) : ?><li><?php echo vc_menu_link( $vc_child ); ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</nav>
