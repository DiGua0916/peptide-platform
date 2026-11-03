<?php
/** Pure route/dependency checks. No WordPress database, session or payment calls. */
define( 'ABSPATH', __DIR__ . '/' );
$route = array( 'checkout' => false, 'cart' => false, 'endpoint' => false, 'admin' => false );
$cart_enabled = true;
$product_enabled = true;
$actions = $scripts = $configs = array();
$quick_view_count = 0;
function WC() {}
function is_admin() { return $GLOBALS['route']['admin']; }
function is_checkout() { return $GLOBALS['route']['checkout']; }
function is_cart() { return $GLOBALS['route']['cart']; }
function is_wc_endpoint_url() { return $GLOBALS['route']['endpoint']; }
function vc_live_cart_active() { return $GLOBALS['cart_enabled']; }
function vc_live_product_settings() { return array( 'enabled' => $GLOBALS['product_enabled'] ); }
function vc_live_cart_settings() { return array( 'enabled' => $GLOBALS['cart_enabled'] ); }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['actions'][ $hook ][ $priority ][] = $callback; }
function add_filter( ...$unused ) {}
function vc_home_quick_view_assets() { $GLOBALS['quick_view_count']++; }
function get_template_directory() { return dirname( __DIR__ ); }
function get_template_directory_uri() { return 'https://store.example/theme'; }
function wp_enqueue_script( $handle, $url, $dependencies = array(), ...$rest ) { $GLOBALS['scripts'][ $handle ] = $dependencies; }
function wp_enqueue_style( ...$unused ) {}
function wp_dequeue_script( ...$unused ) {}
function wp_dequeue_style( ...$unused ) {}
function wp_localize_script( $handle, $name, $config ) { $GLOBALS['configs'][ $name ] = $config; }
function rest_url( $path ) { return 'https://store.example/wp-json/' . $path; }
function esc_url_raw( $url ) { return $url; }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_html__( $value, ...$unused ) { return esc_html( $value ); }
function wc_get_cart_url() { return 'https://store.example/cart/'; }
function wc_get_checkout_url() { return 'https://store.example/checkout/'; }
function vc_url( $page ) { return 'https://store.example/' . $page . '/'; }
function is_user_logged_in() { return false; }
// WordPress registers this before the theme, at the same default priority.
add_action( 'wp_footer', static function () { echo '<script data-test-footer-script></script>'; }, 20 );
require dirname( __DIR__ ) . '/inc/live-cart-experience.php';
require dirname( __DIR__ ) . '/inc/live-commerce-navigation.php';
$passed = 0;
function check( $label, $condition ) {
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    $GLOBALS['passed']++; echo 'ok ' . $label . PHP_EOL;
}
function enqueue() {
    $GLOBALS['scripts'] = $GLOBALS['configs'] = array(); $GLOBALS['quick_view_count'] = 0;
    $callbacks = $GLOBALS['actions']['wp_enqueue_scripts']; ksort( $callbacks );
    foreach ( $callbacks as $rows ) { foreach ( $rows as $callback ) { $callback(); } }
}
check( 'regular storefront keeps the real cart drawer available', vc_live_cart_available() );
$route['checkout'] = true;
check( 'plain native checkout also has a drawer', vc_live_cart_available() );
enqueue();
check( 'checkout loads the real Woo cart data store before its bridge', in_array( 'wc-blocks-data-store', $scripts['vc-live-cart-experience'], true ) && in_array( 'wp-data', $scripts['vc-live-cart-experience'], true ) );
check( 'checkout bridge is explicitly marked in the public UI config', true === $configs['VialCoveLiveCartConfig']['nativeCheckout'] );
check( 'checkout drawer product links can use the approved product quick view', 1 === $quick_view_count && in_array( 'vc-home-quick-view', $scripts['vc-live-commerce-navigation'], true ) );
check( 'history navigation waits for the one real cart controller', in_array( 'vc-live-cart-experience', $scripts['vc-live-commerce-navigation'], true ) );
$footer = $actions['wp_footer']; ksort( $footer ); ob_start();
foreach ( $footer as $rows ) { foreach ( $rows as $callback ) { $callback(); } }
$footer_html = ob_get_clean();
check( 'drawer markup exists before the native footer script printer executes', strpos( $footer_html, '<dialog id="vc-live-cart-drawer"' ) < strpos( $footer_html, '<script data-test-footer-script' ) );
$route['endpoint'] = true;
check( 'order-pay and order-received endpoint routes remain outside this adapter', ! vc_live_cart_available() );
enqueue();
check( 'payment/order endpoint does not load either overlay controller', ! isset( $scripts['vc-live-cart-experience'] ) && ! isset( $scripts['vc-live-commerce-navigation'] ) && 0 === $quick_view_count );
$route['endpoint'] = false; $product_enabled = false;
enqueue();
check( 'a disabled product experience does not load product scripts on checkout', 0 === $quick_view_count && array( 'vc-live-cart-experience' ) === $scripts['vc-live-commerce-navigation'] );
$cart_enabled = false;
enqueue();
check( 'disabled cart presentation leaves checkout completely native', ! isset( $scripts['vc-live-cart-experience'] ) && ! isset( $scripts['vc-live-commerce-navigation'] ) );
$route['checkout'] = false; $cart_enabled = true; $product_enabled = true;
enqueue();
check( 'ordinary storefront does not require Checkout Blocks data', ! in_array( 'wc-blocks-data-store', $scripts['vc-live-cart-experience'], true ) && false === $configs['VialCoveLiveCartConfig']['nativeCheckout'] );
$_GET['vc_native_cart'] = '1';
check( 'explicit native cart fallback still bypasses the custom controller', ! vc_live_cart_available() );
unset( $_GET['vc_native_cart'] ); $route['admin'] = true;
check( 'administration never loads the public cart presentation', ! vc_live_cart_available() );
echo $passed . ' entrypoint checks passed' . PHP_EOL;
