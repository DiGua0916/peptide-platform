<?php
/**
 * 总后台 → 物流追踪（FedEx）（2026-11-11）：FedEx 密钥填这里一次，所有店铺共用。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Fedex;

defined( 'ABSPATH' ) || exit;

final class Fedex_Page {

	public const SLUG = 'pchq-fedex';

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', '物流追踪（FedEx）', '物流追踪（FedEx）', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
			},
			24
		);
		add_action( 'admin_post_pchq_fedex_save', array( self::class, 'handle' ) );
	}

	public static function handle(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '没有权限。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pchq_fedex_save' );
		$in   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Fedex::save 逐项清理。
		$args = array( 'page' => self::SLUG );
		if ( ! empty( $in['test'] ) ) {
			$args['test'] = preg_replace( '/[^0-9A-Za-z]/', '', (string) ( $in['test_number'] ?? '' ) );
		} else {
			Fedex::save( $in );
			$args['saved'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$s = Fedex::settings();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$test  = isset( $_GET['test'] ) ? preg_replace( '/[^0-9A-Za-z]/', '', sanitize_text_field( wp_unslash( $_GET['test'] ) ) ) : '';
		$saved = isset( $_GET['saved'] );
		// phpcs:enable
		echo '<div class="wrap"><h1>物流追踪（FedEx）</h1>';
		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>已保存。所有店铺立即生效。</p></div>';
		}
		echo '<p style="max-width:78ch;line-height:1.7">FedEx 密钥在这里填一次，所有店铺共用。顾客点店铺右下角的物流球时，店铺通过总后台查 FedEx，能看到每一条扫描记录和预计送达。每个单号 30 分钟只查一次 FedEx（FedEx 额度：每个组织每天 10 万次）。密钥加密保存，不会发到店铺。</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pchq_fedex_save' );
		echo '<input type="hidden" name="action" value="pchq_fedex_save"><table class="form-table">';
		echo '<tr><th><label for="pchq-fx-id">API Key</label></th><td><input id="pchq-fx-id" type="text" name="client_id" value="' . esc_attr( $s['client_id'] ) . '" class="regular-text" autocomplete="off"></td></tr>';
		echo '<tr><th><label for="pchq-fx-sec">Secret Key</label></th><td><input id="pchq-fx-sec" type="password" name="client_secret" value="" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( '' !== $s['client_secret'] ? '已保存，不改就留空' : '' ) . '"></td></tr>';
		echo '<tr><th>环境</th><td><label><input type="radio" name="env" value="production"' . checked( $s['env'], 'production', false ) . '> 正式（Production，用 Production key）</label><br><label><input type="radio" name="env" value="sandbox"' . checked( $s['env'], 'sandbox', false ) . '> 测试（Sandbox，用 Test Key，只能查 FedEx 测试单号）</label></td></tr>';
		echo '<tr><th>状态</th><td>' . ( Fedex::configured() ? '<strong style="color:#15803d">已填密钥</strong>' : '<span style="color:#9a4b0b">还没填。没填时顾客只能看到单号和“去 FedEx 网站查看”按钮。</span>' ) . '</td></tr>';
		echo '</table><p class="submit" style="display:flex;gap:8px;flex-wrap:wrap"><button type="submit" class="button button-primary">保存</button>';
		if ( Fedex::configured() ) {
			echo '<button type="submit" class="button" name="clear" value="1">清除密钥</button>';
		}
		echo '</p><h2>测试查询</h2><p>保存密钥后，填一个真实的 FedEx 单号试试。</p><p style="display:flex;gap:8px;flex-wrap:wrap"><input type="text" name="test_number" value="' . esc_attr( $test ) . '" class="regular-text" placeholder="FedEx 单号"><button type="submit" class="button" name="test" value="1">查询</button></p></form>';
		if ( '' !== $test ) {
			$r = Fedex::track( $test, true );
			if ( is_wp_error( $r ) ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $r->get_error_message() ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success inline"><p><strong>' . esc_html( '' !== $r['status'] ? $r['status'] : '（没有状态）' ) . '</strong>' . ( $r['eta'] ? '　预计送达 ' . esc_html( $r['eta'] ) : '' ) . ( $r['delivered'] ? '　已送达 ' . esc_html( $r['delivered'] ) : '' ) . '</p><ol>';
				foreach ( array_slice( $r['events'], 0, 15 ) as $e ) {
					echo '<li>' . esc_html( $e['time'] . '　' . $e['text'] . ( '' !== $e['place'] ? '（' . $e['place'] . '）' : '' ) ) . '</li>';
				}
				echo '</ol></div>';
			}
		}
		echo '</div>';
	}
}
