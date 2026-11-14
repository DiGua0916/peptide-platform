<?php
// Site 1 P2-2: which scripts may wait until Woo has rendered. Run: php tests/commerce-speed-pure.php
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-commerce-speed.php';
use PeptideCore\Commerce_Speed as C;
$ok = 0; $n = 0;
$t = function ( $c, $m ) use ( &$ok, &$n ) { $n++; if ( $c ) { $ok++; } else { echo "FAIL $m\n"; } };
$t( C::touches_blocks( array( 'wc-blocks-checkout' ) ), 'checkout filters stay first' );
$t( C::touches_blocks( array( 'jquery', 'wp-plugins' ) ), 'slot fills stay first' );
$t( C::touches_blocks( array( 'wc-cart-checkout-base' ) ), 'cart/checkout base stays first' );
$t( C::touches_blocks( array( 'wc-checkout-block-frontend' ) ), 'block frontend stays first' );
$t( ! C::touches_blocks( array( 'wp-data', 'wc-blocks-data-store', 'wp-element' ) ), 'store readers may wait' );
$t( ! C::touches_blocks( array( 'jquery', 'wc-add-to-cart-variation' ) ), 'product scripts may wait' );
$t( C::ours( 'https://x.test/wp-content/themes/peptide-site/assets/a.js' ) && C::ours( '/wp-content/plugins/peptide-core/assets/b.js' ), 'own files recognised' );
$t( ! C::ours( '/wp-content/plugins/woocommerce/assets/c.js' ) && ! C::ours( '/wp-includes/js/d.js' ), 'others left alone' );
echo "commerce-speed $ok/$n\n";
exit( $ok === $n ? 0 : 1 );
