<?php
/**
 * 店铺设置 → 注册与年龄确认（2026-11-11）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Accounts;

defined( 'ABSPATH' ) || exit;

final class Accounts_Page {

	public const SLUG = 'pc-accounts';
	public const CAP  = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 71 );
		add_action( 'admin_post_pc_accounts_save', array( self::class, 'save' ) );
	}

	public static function add_menu(): void {
		add_submenu_page( Admin_Menus::SETTINGS, '注册与年龄确认', '注册与年龄确认', self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '没有权限。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pc_accounts_save' );
		Accounts::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Accounts::save 逐项清理。
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s = Accounts::settings();
		echo '<div class="wrap"><h1>注册与年龄确认</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>已保存。</p></div>';
		}
		echo '<p style="max-width:75ch;line-height:1.7">顾客第一次进站先确认年龄并同意使用条款和隐私政策（30 天内不再问），然后弹出注册（邮箱 / 密码 / 确认密码），顾客可以跳过，跳过后 7 天内不再弹。没注册就下单的顾客，付款成功后自动建账户：账号和密码都是他的邮箱，付款成功弹窗里会告诉他；这个邮箱已有账户的，订单直接归到那个账户。顾客登录后在“我的账户”首页能看到自己的订单和物流（订单里添加了发货记录和单号后显示）。顾客看到的都是英文。</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pc_accounts_save' );
		echo '<input type="hidden" name="action" value="pc_accounts_save"><table class="form-table">';
		echo '<tr><th>进站年龄确认</th><td><label><input type="checkbox" name="gate" value="1"' . checked( $s['gate'], true, false ) . '> 开启</label> &nbsp; 最低年龄 <input type="number" name="min_age" min="13" max="25" value="' . (int) $s['min_age'] . '" style="width:70px"> 岁</td></tr>';
		echo '<tr><th>注册弹窗</th><td><label><input type="checkbox" name="signup" value="1"' . checked( $s['signup'], true, false ) . '> 开启（关掉后顾客也不能自己注册，自动建账户不受影响）</label></td></tr>';
		echo '<tr><th>下单自动建账户</th><td><label><input type="checkbox" name="auto" value="1"' . checked( $s['auto'], true, false ) . '> 开启（账号、密码都是顾客邮箱）</label></td></tr>';
		echo '</table><p class="submit"><button type="submit" class="button button-primary">保存</button></p></form></div>';
	}
}
