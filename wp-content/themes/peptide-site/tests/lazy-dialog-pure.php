<?php
/** Front-end optimisation 2026-11-07 items 1–2: dialog scripts on demand, off-screen styles (pure). */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['filters'] = array();
function add_action() {} function add_filter( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['filters'][ $hook ] = $cb; }
function apply_filters( $hook, $value ) { return $value; }
function is_admin() { return false; } function is_customize_preview() { return false; } function is_preview() { return false; }
function check( string $label, bool $ok ): void { global $checks; if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } $checks = ( $checks ?? 0 ) + 1; }
require dirname( __DIR__ ) . '/inc/lazy-dialog.php';
require dirname( __DIR__ ) . '/inc/offscreen-styles.php';

$deps = array(
	'jquery' => array(), 'wc-add-to-cart-variation' => array( 'jquery' ),
	'peptide-showcase-quick-view' => array( 'jquery', 'wc-add-to-cart-variation' ),
	'vc-product-dialog-ticker-model' => array(), 'vc-product-dialog-ticker' => array( 'vc-product-dialog-ticker-model' ),
	'vc-home-quick-view' => array( 'jquery', 'peptide-showcase-quick-view', 'vc-product-dialog-ticker' ),
	'vc-live-cart-recommendation' => array( 'vc-product-dialog-ticker-model' ), // an eager consumer
	'peptide-core-pixels' => array(),
);
$printed = array_keys( $deps );
$lazy = vc_lazy_dialog_resolve( $deps, $printed, vc_lazy_dialog_candidates() );
check( 'dialog scripts become lazy', in_array( 'vc-home-quick-view', $lazy, true ) && in_array( 'peptide-showcase-quick-view', $lazy, true ) && in_array( 'vc-product-dialog-ticker', $lazy, true ) );
check( 'a candidate an eager script depends on stays eager', ! in_array( 'vc-product-dialog-ticker-model', $lazy, true ) );
check( 'shared libraries and pixels are never deferred', ! array_intersect( array( 'jquery', 'wc-add-to-cart-variation', 'peptide-core-pixels' ), $lazy ) );
$deps2 = $deps + array( 'vc-catalog-extra' => array( 'vc-home-quick-view' ) );
$lazy2 = vc_lazy_dialog_resolve( $deps2, array_keys( $deps2 ), vc_lazy_dialog_candidates() );
check( 'an eager script needing the dialog keeps the whole chain eager', ! array_intersect( array( 'vc-home-quick-view', 'peptide-showcase-quick-view', 'vc-product-dialog-ticker' ), $lazy2 ) );
check( 'candidates not printed on this page are ignored', ! in_array( 'vc-product-dialog-polish', $lazy, true ) );

$tag = "<script id=\"vc-x-js-before\">\nvar a = 1;\n</script>\n<script src=\"https://shop.example/a.js?ver=1\" id=\"vc-x-js\" defer data-wp-strategy=\"defer\"></script>\n";
$inert = vc_lazy_dialog_inert_tag( $tag );
check( 'every script in the handle output becomes inert, in order', 2 === substr_count( $inert, '<script type="text/plain" data-vc-lazy="dialog"' ) && strpos( $inert, 'vc-x-js-before' ) < strpos( $inert, 'data-vc-src=' ) );
check( 'src moved to data-vc-src; defer removed; inline code kept', str_contains( $inert, 'data-vc-src="https://shop.example/a.js?ver=1"' ) && ! preg_match( '/\ssrc=/', $inert ) && ! preg_match( '/\sdefer[\s>]/', $inert ) && str_contains( $inert, 'var a = 1;' ) );
check( 'module scripts are left alone', '<script type="module" src="/m.js"></script>' === vc_lazy_dialog_inert_tag( '<script type="module" src="/m.js"></script>' ) );

$link = "<link rel='stylesheet' id='vc-home-quick-view-css' href='https://shop.example/q.css?ver=1' media='all' />\n";
$async = vc_offscreen_style_tag( $link );
check( 'stylesheet becomes non-blocking with a noscript fallback', str_contains( $async, 'media="print" onload="this.media=\'all\';this.onload=null"' ) && str_contains( $async, '<noscript>' . trim( $link ) . '</noscript>' ) );
check( 'print-only or other media stylesheets are untouched', "<link rel='stylesheet' href='p.css' media='print' />" === vc_offscreen_style_tag( "<link rel='stylesheet' href='p.css' media='print' />" ) );
check( 'module and content styles stay blocking', ! array_intersect( array( 'vialcove-modules-shared', 'vialcove-modules-content', 'vc-product-report-viewer' ), array_merge( vc_offscreen_dialog_styles(), vc_offscreen_section_styles() ) ) );

$_GET = array( 'vc_product' => '11006' ); check( 'product ad landing keeps eager dialog scripts', ! vc_lazy_dialog_enabled() );
$_GET = array( 'fbclid' => 'IwAR' ); check( 'plain ad landing gets on-demand dialog scripts', vc_lazy_dialog_enabled() );
echo "PASS {$checks} lazy dialog / off-screen style checks\n";
