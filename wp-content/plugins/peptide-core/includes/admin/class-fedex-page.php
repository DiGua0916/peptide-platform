<?php
/**
 * 店铺设置 → 物流追踪（2026-11-11）：右下角物流球开关 + FedEx 官方追踪接口密钥 + 测试查询。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Fedex_Track;
use PeptideCore\Track_Widget;

defined( 'ABSPATH' ) || exit;

final class Fedex_Page {

	public const SLUG = 'pc-tracking-ball';
	public const CAP  = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 72 );
		add_action( 'admin_post_pc_fedex_save', array( self::class, 'save' ) );
	}

	public static function add_menu(): void {
		add_submenu_page( Admin_Menus::SETTINGS, '物流追踪', '物流追踪', self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '没有权限。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pc_fedex_save' );
		$in   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 下面逐项清理。
		$args = array( 'page' => self::SLUG, 'saved' => 1 );
		if ( ! empty( $in['test'] ) ) {
			$args = array( 'page' => self::SLUG, 'test' => rawurlencode( preg_replace( '/[^0-9A-Za-z]/', '', (string) ( $in['test_number'] ?? '' ) ) ) );
		} else {
			update_option( Track_Widget::OPTION, empty( $in['ball'] ) ? '0' : '1', false );
			if ( class_exists( \PeptideCore\Page_Cache::class ) ) {
				\PeptideCore\Page_Cache::flush();
			}
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$test = isset( $_GET['test'] ) ? preg_replace( '/[^0-9A-Za-z]/', '', sanitize_text_field( wp_unslash( $_GET['test'] ) ) ) : '';
		echo '<div class="wrap"><h1>物流追踪</h1>';
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>已保存。</p></div>';
		}
		// phpcs:enable
		echo '<p style="max-width:78ch;line-height:1.7">店铺右下角有一个货车图标的物流球，顾客点开能看到自己订单的进度和物流：登录的顾客看自己的订单；在这台手机上付过款的直接显示；其他人输入订单号 + 下单邮箱查询。物流信息来自订单里添加的发货记录：在订单页右上角“履约 / Fulfill”里选承运商 FedEx、填单号、标记为已发货。总后台填了 FedEx 密钥后，顾客还能直接看到 FedEx 的每一条扫描记录（到哪了、预计送达），不用跳到 FedEx 网站。</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pc_fedex_save' );
		echo '<input type="hidden" name="action" value="pc_fedex_save"><table class="form-table">';
		echo '<tr><th>物流球</th><td><label><input type="checkbox" name="ball" value="1"' . checked( Track_Widget::enabled(), true, false ) . '> 在店铺右下角显示（结账页不显示）</label></td></tr>';
		echo '<tr><th>FedEx 物流记录</th><td>FedEx 密钥统一在<strong>总后台 → 物流追踪（FedEx）</strong>里设置，所有店铺共用，这里不用填。总后台没填时，顾客只能看到单号和“去 FedEx 网站查看”按钮。</td></tr>';
		echo '</table><p class="submit"><button type="submit" class="button button-primary">保存</button></p>';
		echo '<h2>测试查询</h2><p>填一个 FedEx 单号，看看这家店能不能通过总后台取到物流。</p><p style="display:flex;gap:8px;flex-wrap:wrap"><input type="text" name="test_number" value="' . esc_attr( $test ) . '" class="regular-text" placeholder="FedEx 单号"><button type="submit" class="button" name="test" value="1">查询</button></p></form>';
		if ( '' !== $test ) {
			$r = Fedex_Track::track( $test, true );
			if ( is_wp_error( $r ) ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $r->get_error_message() ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success inline"><p><strong>' . esc_html( $r['status'] ?: '（没有状态）' ) . '</strong>' . ( $r['eta'] ? '　预计送达 ' . esc_html( $r['eta'] ) : '' ) . ( $r['delivered'] ? '　已送达 ' . esc_html( $r['delivered'] ) : '' ) . '</p><ol>';
				foreach ( array_slice( $r['events'], 0, 15 ) as $e ) {
					echo '<li>' . esc_html( $e['time'] . '　' . $e['text'] . ( $e['place'] ? '（' . $e['place'] . '）' : '' ) ) . '</li>';
				}
				echo '</ol></div>';
			}
		}
		echo '</div>';
	}
}
