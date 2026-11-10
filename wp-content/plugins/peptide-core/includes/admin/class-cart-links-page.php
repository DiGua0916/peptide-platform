<?php
/**
 * 店铺设置 → 付款链接（2026-11-10）：开关、打开链接后去哪、在这个浏览器开启 / 关闭“复制付款链接”按钮。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Cart_Links;

defined( 'ABSPATH' ) || exit;

final class Cart_Links_Page {

	public const SLUG = 'pc-cart-links';
	public const CAP  = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 70 );
		add_action( 'admin_post_pc_cart_links_save', array( self::class, 'save' ) );
	}

	public static function add_menu(): void {
		add_submenu_page( Admin_Menus::SETTINGS, '付款链接', '付款链接', self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '没有权限。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pc_cart_links_save' );
		Cart_Links::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Cart_Links::save 逐项清理。
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s = Cart_Links::settings();
		echo '<div class="wrap pc-pl">';
		echo '<h1>付款链接</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>已保存。</p></div>';
		}
		echo '<div class="pc-pl-card"><div class="pc-pl-pad">';
		echo '<p style="max-width:72ch;line-height:1.7">在店铺里把要给客户的商品加进购物车，点左下角的 <b>“🔗 复制付款链接”</b>，把链接发给客户。客户打开后，就是你选好的商品（数量、规格、优惠码都一样），直接进入结账付款。链接不依赖你的电脑或浏览器，谁打开都一样；价格和库存以客户打开时为准。</p>';
		echo '<h2 style="margin-top:20px">第一步：在你的浏览器里开启分享按钮</h2>';
		echo '<p class="description">“复制付款链接”按钮只在开启了的浏览器里出现，客户看不到。开启一次 30 天有效；换了浏览器或手机，要在那边再点一次。</p>';
		echo '<p style="display:flex;gap:8px;flex-wrap:wrap"><a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url( Cart_Links::share_mode_url() ) . '">在这个浏览器开启分享按钮（打开店铺）</a>';
		echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( Cart_Links::share_mode_url( false ) ) . '">在这个浏览器关闭</a></p>';
		echo '<h2 style="margin-top:20px">设置</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pc_cart_links_save' );
		echo '<input type="hidden" name="action" value="pc_cart_links_save">';
		echo '<table class="form-table"><tr><th>付款链接</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( $s['enabled'], true, false ) . '> 开启（关闭后已经发出去的链接也打不开购物车）</label></td></tr>';
		echo '<tr><th>客户打开链接后</th><td><label style="display:block;margin-bottom:6px"><input type="radio" name="target" value="checkout"' . checked( $s['target'], 'checkout', false ) . '> 直接进入结账页（推荐）</label><label><input type="radio" name="target" value="cart"' . checked( $s['target'], 'cart', false ) . '> 先到购物车页</label></td></tr></table>';
		submit_button( '保存' );
		echo '</form>';
		echo '<h2 style="margin-top:12px">注意</h2><ul style="list-style:disc;padding-left:20px;line-height:1.8"><li>客户打开链接时，会先清空他自己原来的购物车，再放入链接里的商品。</li><li>商品下架、没库存或改了规格，链接里的这件商品会被跳过。</li><li>优惠码过期或不满足条件时不会生效，其余商品照常。</li></ul>';
		echo '</div></div></div>';
	}
}
