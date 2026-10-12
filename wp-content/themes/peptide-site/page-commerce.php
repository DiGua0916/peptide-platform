<?php
defined( 'ABSPATH' ) || exit;
get_header();
$vc_is_cart = is_cart();
$vc_is_endpoint = is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' );
$vc_title = $vc_is_cart ? 'Your selection.' : ( is_wc_endpoint_url( 'order-received' ) ? 'Your order.' : ( $vc_is_endpoint ? get_the_title() : 'The final details.' ) );
?>
<main id="main-content" class="container standard-page vc-commerce-page">
    <a class="vc-back-link" href="<?php echo esc_url( vc_url( $vc_is_cart || $vc_is_endpoint ? 'shop' : 'cart' ) ); ?>"><?php echo vc_icon(); ?> <?php echo $vc_is_cart || $vc_is_endpoint ? 'Continue exploring' : 'Back to your cart'; ?></a>
    <div class="vc-commerce-heading">
        <div><span class="eyebrow">VIALCOVE / YOUR ORDER</span><h1><?php echo esc_html( $vc_title ); ?></h1></div>
        <?php if ( ! $vc_is_endpoint ) : ?>
        <nav aria-label="Order progress"><ol class="vc-order-steps"><li<?php echo $vc_is_cart ? ' aria-current="step"' : ''; ?>><span>01</span><?php if ( ! $vc_is_cart ) : ?><a href="<?php echo esc_url( vc_url( 'cart' ) ); ?>">Cart</a><?php else : ?>Cart<?php endif; ?></li><li<?php echo ! $vc_is_cart ? ' aria-current="step"' : ''; ?>><span>02</span>Checkout</li></ol></nav>
        <?php endif; ?>
    </div>
    <div class="entry-content vc-commerce-content"><?php while ( have_posts() ) : the_post(); the_content(); endwhile; ?></div>
    <?php if ( ! $vc_is_endpoint ) { vc_use_statement(); } ?>
    <div class="vc-order-help"><span><?php echo vc_icon( 'file' ); ?> Product documentation, in one place.</span><a href="<?php echo esc_url( vc_url( 'reports' ) ); ?>">Find a batch report <?php echo vc_icon(); ?></a></div>
</main>
<?php get_footer(); ?>
