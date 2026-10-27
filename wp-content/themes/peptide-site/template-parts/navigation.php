<?php
defined( 'ABSPATH' ) || exit;
$vc_menu = array_key_exists( 'items', $args ) ? vc_resolve_navigation_menu( $args['items'], 'header' ) : vc_site_menu( 'header' );
if ( ! $vc_menu ) { return; }
?>
<nav id="primary-nav" class="primary-nav" aria-label="Main navigation">
    <?php foreach ( $vc_menu as $vc_item ) :
        $vc_shop = 'shop' === ( $vc_item['type'] ?? '' );
        $vc_children = $vc_item['children'] ?? [];
        $vc_current = ! empty( $vc_item['current'] ) || (bool) array_filter( $vc_children, static fn( $child ) => ! empty( $child['current'] ) );
        if ( $vc_shop || $vc_children ) : ?>
        <details class="nav-group<?php echo $vc_shop ? ' nav-group--products' : ''; ?><?php echo $vc_current ? ' vc-menu-current' : ''; ?>">
            <summary<?php echo vc_menu_attributes( $vc_item ); ?>><?php echo vc_menu_label( $vc_item ); ?><span class="nav-chevron" aria-hidden="true"></span></summary>
            <?php if ( $vc_shop ) : ?>
                <?php get_template_part( 'template-parts/navigation-products', null, [ 'item' => $vc_item ] ); ?>
            <?php else : ?>
                <div class="nav-panel nav-panel--small">
                    <?php if ( 'none' !== ( $vc_item['type'] ?? '' ) && ! empty( $vc_item['url'] ) ) { echo vc_menu_link( $vc_item, 'vc-menu-parent' ); } ?>
                    <?php foreach ( $vc_children as $vc_child ) { echo vc_menu_link( $vc_child ); } ?>
                </div>
            <?php endif; ?>
        </details>
        <?php else : ?>
            <?php echo vc_menu_link( $vc_item, 'nav-top-link', false ); ?>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
