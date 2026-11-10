<?php
/**
 * 店铺设置 → 运费提前显示（2026-11-10）：每家店自己开关。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Ship_Preview;

defined( 'ABSPATH' ) || exit;

final class Ship_Preview_Page {

	public const SLUG = 'pc-ship-preview';
	public const CAP  = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 70 );
		add_action( 'admin_post_pc_ship_preview_save', array( self::class, 'save' ) );
	}

	public static function add_menu(): void {
		add_submenu_page( Admin_Menus::SETTINGS, '运费提前显示', '运费提前显示', self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '没有权限。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pc_ship_preview_save' );
		Ship_Preview::set_enabled( ! empty( $_POST['enabled'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s = Ship_Preview::settings();
		echo '<div class="wrap"><h1>运费提前显示</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>已保存。</p></div>';
		}
		echo '<p style="max-width:75ch;line-height:1.7">顾客通常到结账填地址时才第一次看到运费，很多人在这一步离开。开启后，商品弹窗的加购按钮旁会直接显示运费和预计总价（购物车里本来就显示运费和应付总额）（按这家店自己的运费方案和运费优惠算，默认按美国估算），顾客在结账前就知道要付多少。开启时间会告诉 AI，AI 分析会对比开启前后“填完地址 ÷ 发起结账”有没有变好。只影响这家店。</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pc_ship_preview_save' );
		echo '<input type="hidden" name="action" value="pc_ship_preview_save">';
		echo '<p><label><input type="checkbox" name="enabled" value="1"' . checked( $s['enabled'], true, false ) . '> 在商品弹窗显示运费和预计总价</label></p>';
		if ( $s['enabled'] && $s['since'] ) {
			echo '<p class="description">' . esc_html( '已开启，开启时间：' . wp_date( 'Y-m-d H:i', $s['since'] ) ) . '</p>';
		}
		submit_button( '保存' );
		echo '</form></div>';
	}
}
