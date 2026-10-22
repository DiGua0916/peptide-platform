<?php
/**
 * 系统设置 → 交易条款（TERMS-01），版式参照 Shopname：标题名称、方案表格（方案名称 / 条款描述 / 显示页面 / 操作），
 * 每个方案下可以添加多个条款（名称 + 内容，内容有加粗、斜体、链接、代码、颜色），效果预览，清空所有数据。
 *
 * 编辑在浏览器里完成（assets/terms-admin.js），提交时整份设置以 JSON 放在隐藏字段 data 里，服务端用 Terms::normalize 清理。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Terms;

defined( 'ABSPATH' ) || exit;

final class Terms_Page {

	public const CAP = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_post_pc_terms_save', array( self::class, 'save' ) );
		add_action( 'admin_post_pc_terms_reset', array( self::class, 'reset' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG . '&section=terms' ) );
	}

	public static function enqueue(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( Store_Settings::SYSTEM_SLUG !== ( $_GET['page'] ?? '' ) || 'terms' !== ( $_GET['section'] ?? '' ) ) {
			return;
		}
		// phpcs:enable
		$ver = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-plans', plugins_url( 'assets/plans.css', PEPTIDE_CORE_FILE ), array( 'peptide-core-store-settings' ), $ver( 'plans.css' ) );
		wp_enqueue_style( 'peptide-core-terms', plugins_url( 'assets/terms.css', PEPTIDE_CORE_FILE ), array(), $ver( 'terms.css' ) );
		wp_enqueue_script( 'peptide-core-terms', plugins_url( 'assets/terms.js', PEPTIDE_CORE_FILE ), array(), $ver( 'terms.js' ), true );
		wp_enqueue_script( 'peptide-core-terms-admin', plugins_url( 'assets/terms-admin.js', PEPTIDE_CORE_FILE ), array( 'jquery', 'jquery-ui-sortable' ), $ver( 'terms-admin.js' ), true );
		wp_localize_script(
			'peptide-core-terms-admin',
			'pcTermsAdmin',
			array(
				'max'     => array( 'title' => Terms::TITLE_MAX, 'desc' => Terms::DESC_MAX, 'name' => Terms::NAME_MAX ),
				'strings' => array(
					/* translators: %d: number */
					'plan'        => __( 'Plan %d', 'peptide-core' ),
					/* translators: %d: number */
					'term'        => __( 'Term %d', 'peptide-core' ),
					'descHolder'  => __( 'Enter the checkbox text, e.g. I agree to the Terms of Service', 'peptide-core' ),
					'nameHolder'  => __( 'Enter the term name', 'peptide-core' ),
					'name'        => __( 'Name', 'peptide-core' ),
					'content'     => __( 'Content', 'peptide-core' ),
					'checkout'    => __( 'Checkout page', 'peptide-core' ),
					'register'    => __( 'Registration page', 'peptide-core' ),
					'login'       => __( 'Login page', 'peptide-core' ),
					'checked'     => __( 'Ticked by default', 'peptide-core' ),
					'remove'      => __( 'Delete', 'peptide-core' ),
					'addTerm'     => __( 'Add term', 'peptide-core' ),
					'bold'        => __( 'Bold', 'peptide-core' ),
					'italic'      => __( 'Italic', 'peptide-core' ),
					'link'        => __( 'Link', 'peptide-core' ),
					'code'        => __( 'Code', 'peptide-core' ),
					'color'       => __( 'Text color', 'peptide-core' ),
					'linkPrompt'  => __( 'Link address (https://…)', 'peptide-core' ),
					'needDesc'    => __( 'Every plan needs a checkbox text.', 'peptide-core' ),
					'needPage'    => __( 'Every plan needs at least one page to show on.', 'peptide-core' ),
					'confirmDel'  => __( 'Delete this plan?', 'peptide-core' ),
					'toggle'      => __( 'Show or hide the terms of this plan', 'peptide-core' ),
				),
			)
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s = Terms::all();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['pc_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'peptide-core' ) . '</p></div>';
		}
		if ( isset( $_GET['pc_reset'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All terms data was cleared.', 'peptide-core' ) . '</p></div>';
		}
		// phpcs:enable
		echo '<div class="pc-pl pc-tm">';
		echo '<p class="pc-ss-back"><a href="' . esc_url( admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG ) ) . '">‹ ' . esc_html__( 'System settings', 'peptide-core' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-tm-form"><input type="hidden" name="action" value="pc_terms_save">';
		wp_nonce_field( 'pc_terms_save' );
		echo '<script type="application/json" class="pc-tm-data">' . wp_json_encode( $s ) . '</script><input type="hidden" name="data" value="">';
		echo '<div class="pc-pl-head"><h1>' . esc_html__( 'Terms of sale', 'peptide-core' ) . '</h1><span class="pc-pl-muted">' . esc_html__( 'When on, buyers must tick every term.', 'peptide-core' ) . '</span>';
		echo '<label class="pc-pl-switch" title="' . esc_attr__( 'On', 'peptide-core' ) . '"><input type="checkbox" class="pc-tm-enabled"' . checked( $s['enabled'], true, false ) . ' aria-label="' . esc_attr__( 'Turn the terms on', 'peptide-core' ) . '"><span></span></label></div>';

		echo '<div class="pc-pl-card pc-pl-pad"><div class="pc-tm-titlebar"><label class="pc-bc-label" for="pc-tm-title"><span class="pc-bc-req">*</span> ' . esc_html__( 'Title', 'peptide-core' ) . '</label><button type="button" class="button-link pc-tm-preview-open"><span class="dashicons dashicons-visibility"></span> ' . esc_html__( 'Preview', 'peptide-core' ) . '</button></div>';
		echo '<span class="pc-tm-count-wrap"><input type="text" id="pc-tm-title" class="large-text pc-tm-title" maxlength="' . (int) Terms::TITLE_MAX . '" value="' . esc_attr( $s['title'] ) . '" placeholder="' . esc_attr__( 'The title cannot be empty, e.g. Terms & Conditions', 'peptide-core' ) . '"><span class="pc-tm-count"></span></span>';
		echo '<table class="pc-pl-table pc-tm-table"><thead><tr><th>' . esc_html__( 'Plan', 'peptide-core' ) . '</th><th>' . esc_html__( 'Checkbox text', 'peptide-core' ) . '</th><th>' . esc_html__( 'Show on', 'peptide-core' ) . '</th><th>' . esc_html__( 'Actions', 'peptide-core' ) . '</th></tr></thead></table>';
		echo '<div class="pc-tm-plans"></div><p><button type="button" class="button button-primary pc-tm-add-plan">' . esc_html__( 'Add plan', 'peptide-core' ) . '</button></p></div>';

		echo '<div class="pc-bc-bar"><button class="button button-primary button-hero">' . esc_html__( 'Save', 'peptide-core' ) . '</button> <a class="button button-hero pc-pl-delete pc-tm-reset" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pc_terms_reset' ), 'pc_terms_reset' ) ) . '"><span class="dashicons dashicons-trash"></span> ' . esc_html__( 'Clear all data', 'peptide-core' ) . '</a></div></form>';

		$preview = Terms::checkboxes_html( 'checkout', true );
		echo '<dialog class="pc-pl-dialog pc-tm-preview"><h2>' . esc_html__( 'Preview', 'peptide-core' ) . '</h2><p class="pc-pl-muted">' . esc_html__( 'How the saved terms look to buyers (save first to see your latest changes).', 'peptide-core' ) . '</p>';
		echo '<div class="pc-tm-preview__body">' . ( $preview ? $preview : '<p class="pc-pl-muted">' . esc_html__( 'No terms yet.', 'peptide-core' ) . '</p>' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已转义。
		echo '<div class="pc-pl-dialog__foot"><button type="button" class="button pc-pl-close">' . esc_html__( 'Close', 'peptide-core' ) . '</button></div></dialog>';
		echo '</div>';
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function save(): void {
		self::guard( 'pc_terms_save' );
		$data = json_decode( (string) wp_unslash( $_POST['data'] ?? '' ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Terms::normalize 逐项清理。
		Terms::save( is_array( $data ) ? $data : array() );
		wp_safe_redirect( self::url( array( 'pc_saved' => 1 ) ) );
		exit;
	}

	public static function reset(): void {
		self::guard( 'pc_terms_reset' );
		Terms::reset();
		wp_safe_redirect( self::url( array( 'pc_reset' => 1 ) ) );
		exit;
	}
}
