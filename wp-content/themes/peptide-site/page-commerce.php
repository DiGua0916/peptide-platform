<?php
defined( 'ABSPATH' ) || exit;
get_header();
$vc_is_cart = is_cart();
$vc_is_endpoint = is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' );
$vc_is_checkout = ! $vc_is_cart && ! $vc_is_endpoint;
$vc_title = $vc_is_cart ? ( function_exists( 'vc_live_cart_settings' ) ? ( vc_live_cart_settings()['cart_title'] ?? 'Your bag.' ) : 'Your bag.' ) : ( is_wc_endpoint_url( 'order-received' ) ? 'Your order.' : ( $vc_is_endpoint ? get_the_title() : 'The final details.' ) );
?>
<main id="main-content" class="container standard-page vc-commerce-page<?php echo $vc_is_cart ? ' vc-cart-page' : ( $vc_is_checkout ? ' vc-checkout-page' : '' ); ?>">
    <a class="vc-back-link" href="<?php echo esc_url( vc_url( $vc_is_cart || $vc_is_endpoint ? 'shop' : 'cart' ) ); ?>"><?php echo vc_icon(); ?> <?php echo $vc_is_cart || $vc_is_endpoint ? 'Continue exploring' : 'Back to your cart'; ?></a>
    <div class="vc-commerce-heading">
        <div><?php if ( $vc_is_endpoint ) : ?><span class="eyebrow">VIALCOVE / YOUR ORDER</span><?php endif; ?><h1><?php echo esc_html( $vc_title ); ?></h1></div>
        <?php if ( ! $vc_is_endpoint ) : ?>
        <nav aria-label="Order progress"><ol class="vc-order-steps"><li<?php echo $vc_is_cart ? ' aria-current="step"' : ''; ?>><span>01</span><?php if ( ! $vc_is_cart ) : ?><a href="<?php echo esc_url( vc_url( 'cart' ) ); ?>">Cart</a><?php else : ?>Cart<?php endif; ?></li><li<?php echo ! $vc_is_cart ? ' aria-current="step"' : ''; ?>><span>02</span>Checkout</li></ol></nav>
        <?php endif; ?>
    </div>
    <?php if ( ! $vc_is_cart && ! $vc_is_endpoint ) : ?>
    <div id="vc-checkout-pricing" class="vc-checkout-pricing"></div>
    <?php if ( class_exists( '\PeptideCore\Campaign_Countdown' ) ) { echo \PeptideCore\Campaign_Countdown::render( 'checkout' ); } ?>
    <?php endif; ?>
    <div class="entry-content vc-commerce-content"><?php while ( have_posts() ) : the_post(); the_content(); endwhile; ?></div>
    <?php if ( ! $vc_is_cart && ! $vc_is_endpoint ) { echo vc_checkout_terms_html(); } ?>
    <?php if ( ! $vc_is_endpoint ) { vc_use_statement(); } ?>
    <?php if ( $vc_is_endpoint ) : ?>
    <div class="vc-order-help"><span><?php echo vc_icon( 'file' ); ?> Product documentation, in one place.</span><a href="<?php echo esc_url( vc_url( 'reports' ) ); ?>">Find a batch report <?php echo vc_icon(); ?></a></div>
    <?php endif; ?>
</main>
<?php get_footer(); ?>
