<?php
/** Isolated enqueue boundary; no WordPress bootstrap or database. */
define( 'ABSPATH', __DIR__ );
class WP_Post { public string $post_content = ''; }
$callback = null; $admin = false; $page = null; $enqueued = array(); $styles = array(); $localized = array();
$scripts = (object) array( 'registered' => array() );
function add_action( $hook, $fn, $priority ) { global $callback; if ( 'wp_enqueue_scripts' !== $hook || 112 !== $priority ) { throw new Exception( 'Unexpected hook' ); } $callback = $fn; }
function is_admin() { global $admin; return $admin; }
function get_queried_object() { global $page; return $page; }
function has_shortcode( $content, $shortcode ) { return str_contains( $content, '[' . $shortcode . ']' ); }
function wp_script_is( $handle, $kind ) { global $enqueued; return isset( $enqueued[ $handle ] ); }
function get_template_directory_uri() { return 'https://shop.test/theme'; }
function rest_url( $route ) { return 'https://shop.test/wp-json/' . $route; }
function get_template_directory() { return dirname( __DIR__ ); }
function wp_enqueue_style( $handle, ...$args ) { global $styles; $styles[ $handle ] = $args; }
function wp_enqueue_script( $handle, $url, $deps, $version, $footer ) { global $enqueued, $scripts; $enqueued[ $handle ] = $url; if ( ! isset( $scripts->registered[ $handle ] ) ) { $scripts->registered[ $handle ] = (object) array( 'deps' => $deps ); } }
function wp_localize_script( $handle, $key, $value ) { global $localized; $localized[ $key ] = $value; }
function is_user_logged_in() { return false; }
function wp_create_nonce( $action ) { throw new Exception( 'Anonymous nonce' ); }
function wp_scripts() { global $scripts; return $scripts; }
function check( bool $condition, string $name ): void { if ( ! $condition ) { throw new Exception( $name ); } echo "ok $name\n"; }
require dirname( __DIR__ ) . '/inc/product-report-viewer.php';
$callback(); check( ! $enqueued && ! $styles, 'ordinary page adds no viewer assets' );
$page = new WP_Post(); $page->post_content = '[pc_report_library]'; $callback();
check( isset( $enqueued['vc-product-dialog-report-viewer'], $enqueued['vc-product-report-library-viewer'], $enqueued['vc-live-commerce-navigation'] ), 'library reader independent from product/cart switches' );
check( $scripts->registered['vc-live-commerce-navigation']->deps === array( 'vc-product-report-library-viewer' ), 'library-only page has one prepared history controller' );
check( isset( $styles['vc-product-report-viewer'] ) && '' === $localized['vcProductReports']['wpNonce'], 'scoped CSS and anonymous configuration' );
$scripts->registered['vc-live-commerce-navigation']->deps = array( 'vc-home-quick-view', 'vc-live-cart-experience' ); $callback(); $callback();
check( $scripts->registered['vc-live-commerce-navigation']->deps === array( 'vc-home-quick-view', 'vc-live-cart-experience', 'vc-product-report-library-viewer' ), 'existing cart/product dependencies preserved without duplicates' );
$page = null; $enqueued = array( 'vc-product-dialog-report-viewer' => true ); $callback();
check( isset( $styles['vc-product-report-viewer'] ) && ! isset( $enqueued['vc-product-report-library-viewer'] ), 'product viewer gains style without archive bridge' );
$admin = true; $enqueued = array(); $callback(); check( ! $enqueued, 'no admin assets' );
