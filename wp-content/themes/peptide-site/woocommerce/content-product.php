<?php defined( 'ABSPATH' ) || exit; global $product; if ( ! is_a( $product, 'WC_Product' ) || ! $product->is_visible() ) { return; } ?>
<li <?php wc_product_class( 'vc-product-item', $product ); ?>><?php vc_product_card( $product ); ?></li>
