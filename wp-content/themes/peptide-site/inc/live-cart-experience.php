<?php
/** Native Woo cart data, with the approved mobile-first theme presentation. */
defined( 'ABSPATH' ) || exit;

function vc_live_cart_available(): bool {
    return function_exists( 'WC' ) && function_exists( 'vc_live_cart_active' ) && vc_live_cart_active()
        && ! is_admin() && ! isset( $_GET['vc_native_cart'] )
        && ! ( is_checkout() && function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() );
}

function vc_live_cart_markup( bool $drawer = false ): string {
    $s = vc_live_cart_settings();
    $id = $drawer ? 'vc-live-bag' : 'vc-live-cart-page';
    ob_start(); ?>
    <section id="<?php echo esc_attr( $id ); ?>" class="vc-live-cart" data-vc-live-cart data-drawer="<?php echo $drawer ? '1' : '0'; ?>" aria-labelledby="<?php echo esc_attr( $id ); ?>-title"<?php echo $drawer ? '' : ' hidden'; ?>>
        <header class="vc-live-cart__heading<?php echo $drawer ? '' : ' vc-live-cart__heading--page'; ?>"><h2 id="<?php echo esc_attr( $id ); ?>-title" tabindex="-1"><?php echo esc_html( $s['cart_title'] ?? 'Shopping cart' ); ?></h2><?php if ( $drawer ) : ?><button type="button" class="vc-live-cart__close" data-cart-close aria-label="Close shopping cart"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true" focusable="false"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button><?php endif; ?></header>
        <p data-cart-status class="vc-live-cart__status" role="status" aria-live="polite">Loading your bag…</p>
        <button data-cart-retry type="button" class="vc-live-cart__retry" hidden>Try again</button>
        <div class="vc-live-cart__layout">
            <div class="vc-live-cart__main">
                <div data-cart-progress class="cart-progress" hidden></div>
                <div data-cart-recommendation class="cart-offer-suggestion" hidden></div>
                <div data-cart-items></div>
                <div data-cart-empty class="vc-live-cart__empty" hidden><h2>Your bag is empty.</h2><p>Find the right specification for your collection.</p><a href="<?php echo esc_url( vc_url( 'shop' ) ); ?>"><?php echo esc_html( $s['cart_continue_label'] ?? 'Continue exploring' ); ?> &rarr;</a></div>
                <section data-cart-recent class="vc-live-cart__recent" hidden aria-label="Recently viewed"><h2>Recently viewed</h2><div data-cart-recent-items></div></section>
            </div>
            <aside data-cart-summary class="vc-live-cart__summary" aria-label="Order summary" hidden>
                <h2>Order summary</h2><div data-cart-totals></div>
                <form data-cart-coupon class="vc-live-cart__coupon"><label for="<?php echo esc_attr( $id ); ?>-coupon">Discount code</label><div><input id="<?php echo esc_attr( $id ); ?>-coupon" name="coupon" autocomplete="off" maxlength="100"><button type="submit">Apply</button></div></form>
                <div data-cart-coupons class="vc-live-cart__coupons"></div>
                <a class="vc-live-cart__checkout" data-cart-checkout href="<?php echo esc_url( wc_get_checkout_url() ); ?>" aria-disabled="true">Continue to checkout &rarr;</a>
                <a class="vc-live-cart__continue" href="<?php echo esc_url( $drawer ? wc_get_cart_url() : vc_url( 'shop' ) ); ?>"><?php echo esc_html( $drawer ? 'View full bag' : ( $s['cart_continue_label'] ?? 'Continue exploring' ) ); ?></a>
            </aside>
        </div>
    </section>
    <?php return (string) ob_get_clean();
}

/** Keep the native Cart block available until the real session has loaded successfully. */
add_filter( 'render_block_woocommerce/cart', function ( $html ) {
    if ( ! vc_live_cart_available() || ! is_cart() ) { return $html; }
    static $rendered = false;
    if ( $rendered ) { return $html; }
    $rendered = true;
    return vc_live_cart_markup() . '<div data-vc-native-cart-fallback>' . $html . '</div>';
}, 100 );

add_action( 'wp_footer', function () {
    if ( ! vc_live_cart_available() || is_cart() ) { return; }
    echo '<dialog id="vc-live-cart-drawer" class="vc-live-cart-drawer" aria-labelledby="vc-live-bag-title">' . vc_live_cart_markup( true ) . '</dialog>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup escapes individual values.
// wp_print_footer_scripts runs at 20: the controller must see its markup first.
}, 10 );

add_action( 'wp_enqueue_scripts', function () {
    if ( ! vc_live_cart_available() ) { return; }
    // One cart controller; checkout keeps its native fields and data store.
    wp_dequeue_script( 'vialcove-cart-drawer' );
    wp_dequeue_style( 'vialcove-cart-drawer' );
    if ( is_cart() ) {
        wp_dequeue_script( 'pc-activity-listener' );
        wp_dequeue_style( 'pc-activity-listener' );
    }
    $uri = get_template_directory_uri() . '/assets/';
    $dir = get_template_directory() . '/assets/';
    foreach ( array( 'live-cart-feedback', 'live-cart-progress', 'live-cart-recommendation', 'live-cart-experience' ) as $asset ) {
        wp_enqueue_style( 'vc-' . $asset, $uri . $asset . '.css', array(), (string) filemtime( $dir . $asset . '.css' ) );
        $deps = 'live-cart-recommendation' === $asset ? array( 'vc-live-cart-feedback', 'vc-live-cart-progress' ) : ( 'live-cart-experience' === $asset ? array( 'vc-live-cart-recommendation' ) : array() );
        if ( 'live-cart-experience' === $asset && is_checkout() ) { $deps = array_merge( $deps, array( 'wp-data', 'wc-blocks-data-store' ) ); }
        wp_enqueue_script( 'vc-' . $asset, $uri . $asset . '.js', $deps, (string) filemtime( $dir . $asset . '.js' ), true );
    }
    wp_localize_script( 'vc-live-cart-experience', 'VialCoveLiveCartConfig', array(
        'cartApiUrl' => esc_url_raw( rest_url( 'wc/store/v1/cart' ) ),
        'productsApiUrl' => esc_url_raw( rest_url( 'wc/store/v1/products/' ) ),
        'recommendationsUrl' => esc_url_raw( rest_url( 'peptide/v1/activity-listener/recommendations' ) ),
        'recentItemsUrl' => esc_url_raw( rest_url( 'peptide/v1/recently-viewed/items' ) ),
        'recentProductApiUrl' => esc_url_raw( rest_url( 'vialcove/v1/home-product/' ) ),
        'activityConfigUrl' => esc_url_raw( rest_url( 'peptide/v1/activity-listener' ) ),
        'cartUrl' => esc_url_raw( wc_get_cart_url() ), 'checkoutUrl' => esc_url_raw( wc_get_checkout_url() ), 'shopUrl' => esc_url_raw( vc_url( 'shop' ) ),
        'mascotUrl' => $uri . 'live-cart-bean.png',
        'wpNonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
        'nativeCheckout' => is_checkout(),
        'settings' => vc_live_cart_settings(),
    ) );
}, 90 );
