<?php defined( 'ABSPATH' ) || exit; ?>
<dialog id="vc-cart-drawer" class="vc-cart-drawer" aria-labelledby="vc-cart-title">
    <div class="vc-cart-shell">
        <header class="vc-cart-heading">
            <div><h2 id="vc-cart-title">Your cart <span class="vc-drawer-count"></span></h2><p>Your selection, in one place.</p></div>
            <button type="button" class="vc-cart-close" aria-label="Close cart" autofocus><svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></button>
        </header>
        <div class="vc-cart-feedback" role="status" aria-live="polite"></div>
        <div class="vc-cart-scroll" aria-busy="false">
            <div class="vc-cart-items"></div>
        </div>
        <footer class="vc-cart-bottom" hidden>
            <div class="vc-cart-subtotal"><span>Subtotal</span><div><del class="vc-cart-regular" hidden></del><strong class="vc-cart-total"></strong></div></div>
            <div class="vc-cart-saving" hidden><span>You save</span><strong></strong></div>
            <p class="vc-cart-pricing-note" hidden>Demo pricing · Comparison prices are simulated.</p>
            <p class="vc-cart-checkout-note">Shipping, taxes and any coupons are calculated at checkout.</p>
            <a class="button vc-cart-checkout" href="<?php echo esc_url( wc_get_checkout_url() ); ?>">Checkout <?php echo vc_icon(); ?></a>
            <a class="vc-cart-full" href="<?php echo esc_url( wc_get_cart_url() ); ?>">View full cart</a>
        </footer>
    </div>
</dialog>
