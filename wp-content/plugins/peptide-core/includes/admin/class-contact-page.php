<?php
/**
 * 系统设置 → 联系方式（2026-11-08）。
 *
 * 店铺对外的客服联系方式（WhatsApp / Telegram / 邮箱）以前只能在 WooCommerce 下面的“VialCove 设置”里改，
 * 店铺工作区看不到那个菜单。这里把同一份设置（Settings::OPTION['support']）放进“店铺设置 → 系统设置”，
 * 改一处，浮动客服按钮、页脚“联系我们”、政策页里的 {support_whatsapp} 等一起生效。
 * 公司电话 / 地址仍在“基础设置”，这里只放链接。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Settings;
use PeptideCore\Support_Contacts;

defined( 'ABSPATH' ) || exit;

final class Contact_Page {

	public const SECTION = 'contact';
	public const ACTION  = 'pc_contact_save';

	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'save' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG . '&section=' . self::SECTION );
	}

	public static function save(): void {
		if ( ! current_user_can( Store_Settings::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'peptide-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$in = array();
		foreach ( array( 'support_enabled', 'support_whatsapp', 'support_telegram', 'support_email', 'support_message', 'support_position' ) as $k ) {
			$in[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $k ] ) ) : '';
		}
		$stored = get_option( Settings::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$stored['support'] = Support_Contacts::sanitize( $in );
		update_option( Settings::OPTION, $stored );
		$errors = array();
		foreach ( get_settings_errors( Settings::OPTION ) as $e ) {
			$errors[] = (string) $e['message'];
		}
		set_transient( 'pc_contact_flash_' . get_current_user_id(), $errors ? array( 'error', implode( ' ', $errors ) ) : array( 'success', __( 'Contact details saved. The support button, footer and policy pages use them right away.', 'peptide-core' ) ), 60 );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( Store_Settings::CAP ) ) {
			return;
		}
		$s   = Settings::all();
		$sup = is_array( $s['support'] ?? null ) ? $s['support'] : Support_Contacts::defaults();
		$flash = get_transient( 'pc_contact_flash_' . get_current_user_id() );
		delete_transient( 'pc_contact_flash_' . get_current_user_id() );
		$basic = admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG . '&section=basic' );

		echo '<p class="pc-ss-back"><a href="' . esc_url( admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG ) ) . '">‹ ' . esc_html__( 'System settings', 'peptide-core' ) . '</a></p>';
		echo '<h1><span class="dashicons dashicons-phone" aria-hidden="true"></span> ' . esc_html__( 'Contact details', 'peptide-core' ) . '</h1>';
		echo '<p class="pc-ss-desc">' . esc_html__( 'The contact methods shown to customers. One place controls the floating support button, the footer “Connect with us” column and the {support_*} variables in policy pages. These are public: use business accounts, not personal ones.', 'peptide-core' ) . '</p>';
		if ( is_array( $flash ) ) {
			echo '<div class="notice notice-' . ( 'error' === $flash[0] ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( (string) $flash[1] ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';

		echo '<section class="pc-ss-panel"><h2>' . esc_html__( 'Customer support contacts', 'peptide-core' ) . '</h2><table class="form-table" role="presentation">';
		self::row( __( 'WhatsApp number', 'peptide-core' ), '<input type="text" id="pc_support_whatsapp" name="support_whatsapp" class="regular-text" value="' . esc_attr( '' !== (string) ( $sup['whatsapp'] ?? '' ) ? '+' . $sup['whatsapp'] : '' ) . '" placeholder="+1 555 123 4567">', __( 'International format with country code. Leave empty to hide. Shown in the support button and the footer.', 'peptide-core' ), 'pc_support_whatsapp' );
		self::row( __( 'Telegram username', 'peptide-core' ), '<input type="text" id="pc_support_telegram" name="support_telegram" class="regular-text" value="' . esc_attr( '' !== (string) ( $sup['telegram'] ?? '' ) ? '@' . $sup['telegram'] : '' ) . '" placeholder="@vialcove_support">', __( 'A username, not a phone number. Leave empty to hide.', 'peptide-core' ), 'pc_support_telegram' );
		self::row( __( 'Support email', 'peptide-core' ), '<input type="email" id="pc_support_email" name="support_email" class="regular-text" value="' . esc_attr( (string) ( $sup['email'] ?? '' ) ) . '" placeholder="support@example.com">', __( 'Shown as a contact method. The sender address of store emails is set in Basic settings.', 'peptide-core' ), 'pc_support_email' );
		self::row( __( 'WhatsApp greeting', 'peptide-core' ), '<input type="text" id="pc_support_message" name="support_message" class="large-text" maxlength="' . esc_attr( (string) Support_Contacts::MESSAGE_MAX ) . '" value="' . esc_attr( (string) ( $sup['message'] ?? '' ) ) . '">', __( 'Optional text pre-filled when a customer opens WhatsApp. English is recommended.', 'peptide-core' ), 'pc_support_message' );
		echo '</table></section>';

		echo '<section class="pc-ss-panel"><h2>' . esc_html__( 'Floating support button', 'peptide-core' ) . '</h2><table class="form-table" role="presentation">';
		self::row( __( 'Show button', 'peptide-core' ), '<label><input type="checkbox" name="support_enabled" value="1" ' . checked( ! empty( $sup['enabled'] ), true, false ) . '> ' . esc_html__( 'Show the support button on the storefront (needs at least one contact method above).', 'peptide-core' ) . '</label>' );
		self::row( __( 'Position', 'peptide-core' ), '<select id="pc_support_position" name="support_position"><option value="right" ' . selected( 'right', $sup['position'] ?? 'right', false ) . '>' . esc_html__( 'Bottom right', 'peptide-core' ) . '</option><option value="left" ' . selected( 'left', $sup['position'] ?? 'right', false ) . '>' . esc_html__( 'Bottom left', 'peptide-core' ) . '</option></select>', '', 'pc_support_position' );
		echo '</table></section>';

		echo '<section class="pc-ss-panel"><h2>' . esc_html__( 'Company phone and address', 'peptide-core' ) . '</h2><p>' . esc_html__( 'The company name, phone and address used in policy pages and search-engine data are in Basic settings → Company information.', 'peptide-core' ) . ' <a class="button" href="' . esc_url( $basic ) . '">' . esc_html__( 'Open Basic settings', 'peptide-core' ) . '</a></p></section>';

		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save contact details', 'peptide-core' ) . '</button></p></form>';
	}

	private static function row( string $label, string $control, string $help = '', string $for = '' ): void {
		echo '<tr><th scope="row">' . ( $for ? '<label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label ) ) . '</th><td>' . $control . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
