<?php
/**
 * Bilingual storefront and visitor language settings.
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Storefront_Language;
use PeptideCore\Storefront_Translation;

defined( 'ABSPATH' ) || exit;

final class Storefront_Language_Page {

	public const ACTION = 'pc_save_storefront_language';
	public const CAP = 'pc_manage_settings';

	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG . '&section=languages' );
	}

	public static function handle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '你没有修改语言识别设置的权限。', 'peptide-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$input = array();
		foreach ( array( 'default_language', 'us_language', 'pr_language' ) as $key ) {
			$value = $_POST[ $key ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			if ( ! is_string( $value ) || ! in_array( wp_unslash( $value ), array( 'en', 'es' ), true ) ) {
				wp_safe_redirect( add_query_arg( 'language_notice', 'invalid', self::url() ) );
				exit;
			}
			$input[ $key ] = wp_unslash( $value );
		}
		$enabled = $_POST['enabled'] ?? '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		if ( ! is_string( $enabled ) || ! in_array( $enabled, array( '0', '1' ), true ) ) {
			wp_safe_redirect( add_query_arg( 'language_notice', 'invalid', self::url() ) );
			exit;
		}
		$input['enabled'] = '1' === $enabled;
		$config = Storefront_Language::sanitize( $input );
		if ( is_wp_error( $config ) ) {
			wp_safe_redirect( add_query_arg( 'language_notice', 'invalid', self::url() ) );
			exit;
		}
		// A stale form from before the bilingual release must not switch off translation.
		$translation_present = array_key_exists( 'translation_settings_present', $_POST );
		$translation_enabled = null;
		if ( $translation_present ) {
			$translation = $_POST['translation_enabled'] ?? '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			if ( '1' !== ( $_POST['translation_settings_present'] ?? null ) || ! is_string( $translation ) || ! in_array( $translation, array( '0', '1' ), true ) ) {
				wp_safe_redirect( add_query_arg( 'language_notice', 'invalid', self::url() ) );
				exit;
			}
			$translation_enabled = '1' === $translation;
			if ( $translation_enabled && ! Storefront_Translation::packages_ready() ) {
				wp_safe_redirect( add_query_arg( 'language_notice', 'resources', self::url() ) );
				exit;
			}
		}
		update_option( Storefront_Language::OPTION, $config, false );
		$saved = Storefront_Language::get() === $config;
		if ( null !== $translation_enabled ) {
			update_option( Storefront_Translation::OPTION, $translation_enabled, false );
			$saved = $saved && in_array( get_option( Storefront_Translation::OPTION, false ), array( true, 1, '1' ), true ) === $translation_enabled;
		}
		wp_safe_redirect( add_query_arg( 'language_notice', $saved ? 'saved' : 'failed', self::url() ) );
		exit;
	}

	private static function language_select( string $name, string $selected ): void {
		echo '<select id="pc-language-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="pc-bc-input">';
		foreach ( array( 'en' => '英语 · English', 'es' => '西班牙语 · Español' ) as $code => $label ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $selected, $code, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( '你没有修改语言识别设置的权限。', 'peptide-core' ) . '</p></div>';
			return;
		}
		$config = Storefront_Language::get();
		$notice = isset( $_GET['language_notice'] ) && is_string( $_GET['language_notice'] ) ? wp_unslash( $_GET['language_notice'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only allowlist.
		$messages = array(
			'saved' => __( '语言识别设置已保存。', 'peptide-core' ),
			'invalid' => __( '设置没有保存，请选择有效的语言。', 'peptide-core' ),
			'failed' => __( '保存未完成，请重试。', 'peptide-core' ),
			'resources' => __( '西班牙语资源尚未安装完整，请先安装语言包后再启用。', 'peptide-core' ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="notice ' . ( 'saved' === $notice ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
		echo '<p class="pc-ss-back"><a href="' . esc_url( admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG ) ) . '">‹ ' . esc_html__( '系统设置', 'peptide-core' ) . '</a></p>';
		echo '<h1>' . esc_html__( '店铺语言', 'peptide-core' ) . '</h1>';
		echo '<p>' . esc_html__( '提供英语和西班牙语页面；客户手动选择的语言始终优先于 IP 识别。', 'peptide-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-bc">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="translation_settings_present" value="1">';
		echo '<section class="pc-ss-panel"><h2>' . esc_html__( '西班牙语', 'peptide-core' ) . '</h2>';
		echo '<p><label><input type="checkbox" name="translation_enabled" value="1"' . checked( Storefront_Translation::enabled(), true, false ) . '> ' . esc_html__( '启用英语 / 西班牙语切换', 'peptide-core' ) . '</label></p>';
		echo '<p class="pc-bc-note">' . esc_html__( Storefront_Translation::packages_ready() ? '官方语言资源已就绪。商品、购物车和结账共用同一套数据，英文原文保留。' : '西班牙语资源尚未就绪，启用前需安装对应版本的 WordPress 和 WooCommerce 官方语言包。', 'peptide-core' ) . '</p>';
		echo '<p class="pc-bc-note">' . esc_html__( '商品名称、价格、规格、客户填写内容和报告原件不会翻译或改写。新增自定义文案需补充对应译文。', 'peptide-core' ) . '</p></section>';
		echo '<section class="pc-ss-panel"><h2>' . esc_html__( '识别规则', 'peptide-core' ) . '</h2>';
		echo '<p><label><input type="checkbox" name="enabled" value="1"' . checked( ! empty( $config['enabled'] ), true, false ) . '> ' . esc_html__( '启用 IP 语言识别', 'peptide-core' ) . '</label></p>';
		echo '<div class="pc-bc-grid">';
		foreach ( array( 'us_language' => '美国 · US', 'pr_language' => '波多黎各 · PR', 'default_language' => '其他地区或无法识别时' ) as $key => $label ) {
			echo '<div class="pc-bc-field"><label class="pc-bc-label" for="pc-language-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
			self::language_select( $key, $config[ $key ] );
			echo '</div>';
		}
		echo '</div><p class="pc-bc-note">' . esc_html__( '识别关闭时使用默认语言；客户已选择的语言优先于 IP 识别，不改变客户的账单或收货地区。', 'peptide-core' ) . '</p></section>';
		echo '<section class="pc-ss-panel"><h2>' . esc_html__( '使用方式', 'peptide-core' ) . '</h2><p>' . esc_html__( '前台 EN / ES 可随时切换。开启 IP 识别后，新访客按上述规则选择语言；识别不可用时使用默认语言。手动选择会在当前域名保留 90 天。语言选择不改变收货国家，也不影响已有购物车、订单和支付回调。', 'peptide-core' ) . '</p></section>';
		echo '<div class="pc-bc-bar"><button type="submit" class="button button-primary">' . esc_html__( '保存设置', 'peptide-core' ) . '</button></div></form>';
	}
}
