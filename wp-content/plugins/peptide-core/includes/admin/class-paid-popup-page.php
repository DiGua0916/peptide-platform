<?php
/**
 * 店铺设置 → 付款成功弹窗（2026-11-10）：顾客付款后回到店铺看到的弹窗文案。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Paid_Popup;

defined( 'ABSPATH' ) || exit;

final class Paid_Popup_Page {

	public const SLUG = 'pc-paid-popup';
	public const CAP  = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 70 );
		add_action( 'admin_post_pc_paid_popup_save', array( self::class, 'save' ) );
	}

	public static function add_menu(): void {
		add_submenu_page( Admin_Menus::SETTINGS, '付款成功弹窗', '付款成功弹窗', self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '没有权限。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pc_paid_popup_save' );
		if ( ! empty( $_POST['reset'] ) ) {
			delete_option( Paid_Popup::OPTION );
		} else {
			Paid_Popup::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Paid_Popup::save 逐项清理。
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s      = Paid_Popup::settings();
		$fields = array(
			'title'       => array( '标题', 1 ),
			'message'     => array( '正文（可以分段，{order} = 订单号）', 4 ),
			'phone_label' => array( '客服电话那一行（{phone} = 店铺设置里的公司电话，没填电话就不显示）', 1 ),
			'wa_label'    => array( 'WhatsApp 按钮', 1 ),
			'wa_text'     => array( '点按钮后 WhatsApp 里预先填好的话（{items} = 买了什么和数量，{total} = 总金额，{order} = 订单号）', 2 ),
			'no_wa_label' => array( '“没有 WhatsApp”链接', 1 ),
			'form_intro'  => array( '没有 WhatsApp 时的说明', 2 ),
			'form_button' => array( '提交按钮', 1 ),
			'thanks'      => array( '提交后的感谢语', 1 ),
		);
		echo '<div class="wrap"><h1>付款成功弹窗</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>已保存。</p></div>';
		}
		echo '<p style="max-width:75ch;line-height:1.7">顾客付款成功后会回到店铺首页，弹出这个窗口：订单号、客服电话、加 WhatsApp 专员的按钮。没有 WhatsApp 的顾客可以留下自己的联系方式，会写进订单备注，并发邮件到新订单通知邮箱，请客服主动联系。顾客看到的是英文，WhatsApp 号码用“客服联系方式”里设置的。</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pc_paid_popup_save' );
		echo '<input type="hidden" name="action" value="pc_paid_popup_save">';
		echo '<table class="form-table"><tr><th>开启</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( $s['enabled'], true, false ) . '> 付款成功后弹出</label></td></tr>';
		foreach ( $fields as $k => [ $label, $rows ] ) {
			echo '<tr><th><label for="pcpp-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( $rows > 1 ) {
				echo '<textarea id="pcpp-' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '" rows="' . (int) $rows . '" class="large-text">' . esc_textarea( $s[ $k ] ) . '</textarea>';
			} else {
				echo '<input id="pcpp-' . esc_attr( $k ) . '" type="text" name="' . esc_attr( $k ) . '" value="' . esc_attr( $s[ $k ] ) . '" class="large-text">';
			}
			echo '</td></tr>';
		}
		echo '</table><p class="submit" style="display:flex;gap:8px"><button type="submit" class="button button-primary">保存</button><button type="submit" class="button" name="reset" value="1">恢复默认文案</button></p></form></div>';
	}
}
