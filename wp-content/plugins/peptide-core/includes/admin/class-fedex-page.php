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
			Fedex_Track::save( $in );
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
		$s = Fedex_Track::settings();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$test = isset( $_GET['test'] ) ? preg_replace( '/[^0-9A-Za-z]/', '', sanitize_text_field( wp_unslash( $_GET['test'] ) ) ) : '';
		echo '<div class="wrap"><h1>物流追踪</h1>';
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>已保存。</p></div>';
		}
		// phpcs:enable
		echo '<p style="max-width:78ch;line-height:1.7">店铺右下角有一个货车图标的物流球，顾客点开能看到自己订单的进度和物流：登录的顾客看自己的订单；在这台手机上付过款的直接显示；其他人输入订单号 + 下单邮箱查询。物流信息来自订单里添加的发货记录：在订单页右上角“履约 / Fulfill”里选承运商 FedEx、填单号、标记为已发货。填了 FedEx 接口密钥后，顾客还能直接看到 FedEx 的每一条扫描记录（到哪了、预计送达），不用跳到 FedEx 网站。</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pc_fedex_save' );
		echo '<input type="hidden" name="action" value="pc_fedex_save"><table class="form-table">';
		echo '<tr><th>物流球</th><td><label><input type="checkbox" name="ball" value="1"' . checked( Track_Widget::enabled(), true, false ) . '> 在店铺右下角显示（结账页不显示）</label></td></tr>';
		echo '<tr><th><label for="pcfx-id">FedEx API Key</label></th><td><input id="pcfx-id" type="text" name="client_id" value="' . esc_attr( $s['client_id'] ) . '" class="regular-text" autocomplete="off"></td></tr>';
		echo '<tr><th><label for="pcfx-sec">FedEx Secret Key</label></th><td><input id="pcfx-sec" type="password" name="client_secret" value="" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( '' !== $s['client_secret'] ? '已保存，不改就留空' : '' ) . '"></td></tr>';
		echo '<tr><th>环境</th><td><label><input type="radio" name="env" value="production"' . checked( $s['env'], 'production', false ) . '> 正式（Production）</label> &nbsp; <label><input type="radio" name="env" value="sandbox"' . checked( $s['env'], 'sandbox', false ) . '> 测试（Sandbox，只能查 FedEx 的测试单号）</label></td></tr>';
		echo '<tr><th>状态</th><td>' . ( Fedex_Track::configured() ? '<strong style="color:#15803d">已填密钥</strong>' : '<span style="color:#9a4b0b">还没填，顾客只能看到单号和“去 FedEx 网站查看”按钮</span>' ) . '</td></tr>';
		echo '</table><p class="submit" style="display:flex;gap:8px;flex-wrap:wrap"><button type="submit" class="button button-primary">保存</button>';
		if ( Fedex_Track::configured() ) {
			echo '<button type="submit" class="button" name="clear" value="1">清除密钥</button>';
		}
		echo '</p>';
		echo '<h2>测试查询</h2><p>填一个 FedEx 单号，看看接口能不能取到物流（先保存密钥）。</p><p style="display:flex;gap:8px;flex-wrap:wrap"><input type="text" name="test_number" value="' . esc_attr( $test ) . '" class="regular-text" placeholder="FedEx 单号"><button type="submit" class="button" name="test" value="1">查询</button></p></form>';
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
		echo '<h2>怎么拿 FedEx 密钥</h2><ol style="max-width:78ch;line-height:1.8"><li>用你的 FedEx 账户登录 developer.fedex.com（没有就先注册一个 FedEx 账户）。</li><li>My Projects → Create API Project，选 “Track API”。</li><li>按提示关联你的 FedEx 账号（9 位账号，发货用的那个），进入 Production Key 页面。</li><li>把 API Key 和 Secret Key 复制到上面，保存，再用一个真实单号测试。</li></ol></div>';
	}
}
