<?php
/**
 * 1 号站定稿 P1-3 / P1-1（2026-11-14）：首页 “欢迎回来” 提示条。
 *
 * - 有待付款订单（B 线 Store API 的 pending_order）时，优先显示 “订单待付款”，一键去付款；
 * - 否则购物车里有东西，显示 “你的购物车还有 X 盒 · $X”；
 * - WooCommerce 的购物车 48 小时就清掉，所以浏览器里另存一份（30 天），清掉后用现有的 ?pc_cart= 放回；
 * - 下面一排最近浏览（现有的 recently-viewed 接口）。
 *
 * 全部在浏览器里判断，不写 cookie，首页缓存照常。它是页面的一部分，不是弹窗，不受 P0-2 弹窗规则限制；同一次访问只显示一次。
 */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', static function (): void {
	if ( is_admin() || ! is_front_page() || ! function_exists( 'wc_get_cart_url' ) ) {
		return;
	}
	$dir = get_template_directory() . '/assets/';
	$uri = get_template_directory_uri() . '/assets/';
	wp_enqueue_style( 'vc-welcome-back', $uri . 'welcome-back.css', array(), (string) filemtime( $dir . 'welcome-back.css' ) );
	wp_enqueue_script( 'vc-welcome-back', $uri . 'welcome-back.js', array(), (string) filemtime( $dir . 'welcome-back.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_add_inline_script(
		'vc-welcome-back',
		'window.vcWelcomeBack=' . wp_json_encode(
			array(
				'cartApi'   => rest_url( 'wc/store/v1/cart' ),
				'recentApi' => rest_url( 'peptide/v1/recently-viewed/items' ),
				'cartUrl'   => wc_get_cart_url(),
			)
		) . ';',
		'before'
	);
}, 120 );
