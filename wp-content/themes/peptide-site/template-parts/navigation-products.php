<?php
defined( 'ABSPATH' ) || exit;
$vc_item = $args['item'];
$vc_menu_products = vc_navigation_products();
$vc_search_id = wp_unique_id( 'vc-nav-search-' );
$vc_list_id = wp_unique_id( 'vc-nav-products-' );
?>
<div class="nav-panel nav-panel--products">
    <div class="nav-panel-heading">
        <div><h2>Explore the collection</h2><p><?php echo esc_html( ( $vc_item['description'] ?? '' ) ?: 'Find a product. See the details.' ); ?></p></div>
        <?php echo vc_menu_link( array_merge( $vc_item, [ 'title' => 'View all products', 'icon' => null ] ), 'text-link', false ); ?>
    </div>
    <?php if ( ! empty( $vc_item['children'] ) ) : ?>
    <div class="nav-product-shortcuts"><?php foreach ( $vc_item['children'] as $vc_child ) { echo vc_menu_link( $vc_child ); } ?></div>
    <?php endif; ?>
    <div class="nav-search" hidden data-nav-search>
        <label class="screen-reader-text" for="<?php echo esc_attr( $vc_search_id ); ?>">Search products by name</label><?php echo vc_icon( 'search' ); ?>
        <input id="<?php echo esc_attr( $vc_search_id ); ?>" data-nav-query type="search" placeholder="Search products by name" autocomplete="off" aria-controls="<?php echo esc_attr( $vc_list_id ); ?>">
        <span data-nav-count aria-live="polite"><?php echo count( $vc_menu_products ); ?> products</span>
    </div>
    <ul class="nav-product-list" id="<?php echo esc_attr( $vc_list_id ); ?>">
        <?php foreach ( $vc_menu_products as $vc_menu_product ) : ?>
        <li data-product-search="<?php echo esc_attr( $vc_menu_product->get_name() . ' ' . $vc_menu_product->get_sku() ); ?>"><a href="<?php echo esc_url( $vc_menu_product->get_permalink() ); ?>"><span><?php echo esc_html( $vc_menu_product->get_name() ); ?></span><?php echo vc_icon(); ?></a></li>
        <?php endforeach; ?>
    </ul>
    <p class="nav-empty" data-nav-empty<?php echo $vc_menu_products ? ' hidden' : ''; ?>>No products found. Try another product name.</p>
</div>
