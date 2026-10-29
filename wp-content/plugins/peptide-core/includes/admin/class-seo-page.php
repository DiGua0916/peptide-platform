<?php
/**
 * 后台“店铺装修 → 首页SEO”（照 YY 后台，用户 2026-10-27）。数据和前台输出见 PeptideCore\Seo。
 *
 * - 默认模板 / 域名指定模板两个标签；域名标签先选域名，再填这个域名的一套。
 * - 搜索结果预览（随输入更新）、标题和描述的字数（最多 2048）、关键词标签（回车添加）。
 * - 域名指定模板可以导出 / 导入 CSV（domain,title,description,keywords，关键词之间用 | 分隔）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Seo;
use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Seo_Page {

	public const SLUG = 'pc-store-design-seo';

	public static function register_hooks(): void {
		add_action( 'admin_post_pc_seo_save', array( self::class, 'handle_save' ) );
		add_action( 'admin_post_pc_seo_export', array( self::class, 'handle_export' ) );
		add_action( 'admin_post_pc_seo_import', array( self::class, 'handle_import' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public static function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, self::SLUG ) ) {
			return;
		}
		$ver = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-seo-admin', plugins_url( 'assets/seo-admin.css', PEPTIDE_CORE_FILE ), array(), $ver( 'seo-admin.css' ) );
		wp_enqueue_script( 'peptide-core-seo-admin', plugins_url( 'assets/seo-admin.js', PEPTIDE_CORE_FILE ), array(), $ver( 'seo-admin.js' ), true );
		wp_localize_script(
			'peptide-core-seo-admin',
			'pcSeoAdmin',
			array(
				'domain'      => Seo::current_host(),
				'titleHolder' => __( 'Page title', 'peptide-core' ),
				'descHolder'  => __( 'SEO description', 'peptide-core' ),
				'remove'      => __( 'Remove', 'peptide-core' ),
			)
		);
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( Templates::CAP_EDIT ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( $action );
	}

	// ---------- 保存 / 导入 / 导出 ----------

	public static function handle_save(): void {
		self::guard( 'pc_seo_save' );
		$raw = array(
			'title'       => wp_unslash( $_POST['title'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Seo::clean_set 清理。
			'description' => wp_unslash( $_POST['description'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'keywords'    => wp_unslash( $_POST['keywords'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		);
		if ( 'domain' === ( $_POST['tab'] ?? '' ) ) {
			$host = Seo::save_domain( sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) ), $raw );
			if ( is_wp_error( $host ) ) {
				wp_safe_redirect( self::url( array( 'tab' => 'domain', 'pc_error' => 'domain' ) ) );
				exit;
			}
			wp_safe_redirect( self::url( array( 'tab' => 'domain', 'domain' => $host, 'pc_saved' => 1 ) ) );
			exit;
		}
		Seo::save_home( $raw );
		wp_safe_redirect( self::url( array( 'pc_saved' => 1 ) ) );
		exit;
	}

	public static function handle_export(): void {
		self::guard( 'pc_seo_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="seo-domains-' . gmdate( 'Ymd' ) . '.csv"' );
		echo Seo::export_csv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV 下载。
		exit;
	}

	public static function handle_import(): void {
		self::guard( 'pc_seo_import' );
		$file = $_FILES['csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || (int) $file['size'] > 1048576 || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			wp_safe_redirect( self::url( array( 'tab' => 'domain', 'pc_error' => 'file' ) ) );
			exit;
		}
		[ $count, $errors ] = Seo::import_csv( (string) file_get_contents( (string) $file['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		set_transient( 'pc_seo_import_' . get_current_user_id(), $errors, 300 );
		wp_safe_redirect( self::url( array( 'tab' => 'domain', 'pc_imported' => $count ) ) );
		exit;
	}

	// ---------- 页面 ----------

	public static function render(): void {
		if ( ! current_user_can( Templates::CAP_EDIT ) ) {
			return;
		}
		$s       = Seo::settings();
		$tab     = isset( $_GET['tab'] ) && 'domain' === $_GET['tab'] ? 'domain' : 'home'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$domains = Seo::known_domains();
		$domain  = isset( $_GET['domain'] ) ? (string) Templates::normalize_host( sanitize_text_field( wp_unslash( $_GET['domain'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'domain' === $tab && '' === $domain && $domains ) {
			$domain = $domains[0];
		}
		$set     = 'domain' === $tab ? ( $s['domains'][ $domain ] ?? Seo::blank() ) : $s['home'];
		$preview = 'domain' === $tab && '' !== $domain ? $domain : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		echo '<div class="wrap pc-seo">';
		echo '<h1>' . esc_html__( 'Homepage SEO', 'peptide-core' ) . ' <span class="pc-seo-lead">' . esc_html__( 'A domain uses its own entry under “Domain templates”; domains without one use the default template.', 'peptide-core' ) . '</span></h1>';
		self::notices();
		echo '<div class="pc-seo-layout"><section class="pc-seo-card">';
		echo '<nav class="pc-seo-tabs" role="tablist">';
		echo '<a role="tab" href="' . esc_url( self::url() ) . '" class="' . ( 'home' === $tab ? 'is-active' : '' ) . '" aria-selected="' . ( 'home' === $tab ? 'true' : 'false' ) . '">' . esc_html__( 'Default template', 'peptide-core' ) . '</a>';
		echo '<a role="tab" href="' . esc_url( self::url( array( 'tab' => 'domain' ) ) ) . '" class="' . ( 'domain' === $tab ? 'is-active' : '' ) . '" aria-selected="' . ( 'domain' === $tab ? 'true' : 'false' ) . '">' . esc_html__( 'Domain templates', 'peptide-core' ) . '</a>';
		if ( 'domain' === $tab ) {
			echo '<span class="pc-seo-tabs__tools"><a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pc_seo_export' ), 'pc_seo_export' ) ) . '"><span class="dashicons dashicons-download" aria-hidden="true"></span>' . esc_html__( 'Export SEO', 'peptide-core' ) . '</a>';
			echo '<button type="button" class="button-link pc-seo-import-toggle" aria-expanded="false"><span class="dashicons dashicons-upload" aria-hidden="true"></span>' . esc_html__( 'Import SEO', 'peptide-core' ) . '</button></span>';
		}
		echo '</nav>';

		if ( 'domain' === $tab ) {
			echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-seo-import" hidden>';
			wp_nonce_field( 'pc_seo_import' );
			echo '<input type="hidden" name="action" value="pc_seo_import"><p>' . esc_html__( 'CSV with the columns domain, title, description, keywords (separate keywords with |). Rows replace the domain’s current entry. Export first to get the format.', 'peptide-core' ) . '</p><input type="file" name="csv" accept=".csv,text/csv" required> <button class="button">' . esc_html__( 'Import', 'peptide-core' ) . '</button></form>';
			echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="pc-seo-domain"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="domain">';
			echo '<label for="pc-seo-domain">' . esc_html__( 'Domain', 'peptide-core' ) . '</label> <input type="text" id="pc-seo-domain" name="domain" list="pc-seo-domains" value="' . esc_attr( $domain ) . '" placeholder="shop.example.com" required> <datalist id="pc-seo-domains">';
			foreach ( $domains as $h ) {
				echo '<option value="' . esc_attr( $h ) . '">' . esc_html( isset( $s['domains'][ $h ] ) ? $h . ' ✓' : $h ) . '</option>';
			}
			echo '</datalist> <button class="button">' . esc_html__( 'Select domain', 'peptide-core' ) . '</button>';
			if ( $s['domains'] ) {
				/* translators: %s: list of domains */
				echo '<p class="description">' . esc_html( sprintf( __( 'Domains with their own SEO: %s', 'peptide-core' ), implode( ', ', array_keys( $s['domains'] ) ) ) ) . '</p>';
			}
			echo '</form>';
		}

		$ready = 'home' === $tab || '' !== $domain;
		if ( $ready ) {
			echo '<div class="pc-seo-preview" aria-live="polite"><span class="pc-seo-preview__url">' . esc_html( $preview ) . '</span><span class="pc-seo-preview__title" data-preview="title">' . esc_html( '' !== $set['title'] ? $set['title'] : __( 'Page title', 'peptide-core' ) ) . '</span><span class="pc-seo-preview__desc" data-preview="desc">' . esc_html( '' !== $set['description'] ? $set['description'] : __( 'SEO description', 'peptide-core' ) ) . '</span></div>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-seo-form">';
			wp_nonce_field( 'pc_seo_save' );
			echo '<input type="hidden" name="action" value="pc_seo_save"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><input type="hidden" name="domain" value="' . esc_attr( $domain ) . '">';
			echo '<p><label for="pc-seo-title">' . esc_html__( 'SEO title', 'peptide-core' ) . '</label><input type="text" id="pc-seo-title" name="title" maxlength="' . esc_attr( (string) Seo::MAX_TEXT ) . '" value="' . esc_attr( $set['title'] ) . '" placeholder="' . esc_attr__( 'Shown as the title in search results', 'peptide-core' ) . '" data-count><span class="pc-seo-count"></span></p>';
			echo '<p><label for="pc-seo-desc">' . esc_html__( 'SEO description', 'peptide-core' ) . '</label><textarea id="pc-seo-desc" name="description" rows="4" maxlength="' . esc_attr( (string) Seo::MAX_TEXT ) . '" placeholder="' . esc_attr__( 'Shown under the title in search results', 'peptide-core' ) . '" data-count>' . esc_textarea( $set['description'] ) . '</textarea><span class="pc-seo-count"></span></p>';
			echo '<div class="pc-seo-keys"><label for="pc-seo-key-input">' . esc_html__( 'SEO keywords', 'peptide-core' ) . '</label><div class="pc-seo-tags" data-tags><input type="text" id="pc-seo-key-input" placeholder="' . esc_attr__( 'Type a keyword and press Enter; you can add several', 'peptide-core' ) . '"></div><input type="hidden" name="keywords" value="' . esc_attr( implode( "\n", $set['keywords'] ) ) . '"></div>';
			echo '<p class="pc-seo-actions"><button class="button button-primary">' . esc_html__( 'Save', 'peptide-core' ) . '</button>';
			if ( 'domain' === $tab && isset( $s['domains'][ $domain ] ) ) {
				echo ' <span class="description">' . esc_html__( 'To use the default template for this domain again, empty all three fields and save.', 'peptide-core' ) . '</span>';
			}
			echo '</p></form>';
		} else {
			echo '<p class="pc-seo-empty">' . esc_html__( 'Enter or choose a domain above.', 'peptide-core' ) . '</p>';
		}
		echo '</section>';
		echo '<aside class="pc-seo-card pc-seo-notes"><h2>' . esc_html__( 'Notes', 'peptide-core' ) . '</h2><ol>';
		echo '<li>' . esc_html__( 'Search engine optimisation (SEO) means adjusting the site to how search engines work, so it ranks higher in their results.', 'peptide-core' ) . '</li>';
		echo '<li>' . esc_html__( 'A good title, description and keywords for the home page help search engines read and index the store, so customers find it more easily.', 'peptide-core' ) . '</li>';
		echo '<li>' . esc_html__( 'Variable: {current_domain} is replaced by the domain the visitor opened.', 'peptide-core' ) . '</li>';
		echo '<li>' . esc_html__( 'Campaign pages have their own SEO in their editor.', 'peptide-core' ) . '</li>';
		echo '</ol></aside></div></div>';
	}

	private static function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['pc_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'peptide-core' ) . '</p></div>';
		}
		if ( isset( $_GET['pc_imported'] ) ) {
			/* translators: %d: number of domains */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( 'Imported SEO for %d domain.', 'Imported SEO for %d domains.', absint( $_GET['pc_imported'] ), 'peptide-core' ), absint( $_GET['pc_imported'] ) ) ) . '</p></div>';
			$errs = get_transient( 'pc_seo_import_' . get_current_user_id() );
			delete_transient( 'pc_seo_import_' . get_current_user_id() );
			if ( is_array( $errs ) && $errs ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( implode( ' ', $errs ) ) . '</p></div>';
			}
		}
		$error = isset( $_GET['pc_error'] ) ? sanitize_key( wp_unslash( $_GET['pc_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'domain' === $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Enter a valid domain name, for example shop.example.com.', 'peptide-core' ) . '</p></div>';
		} elseif ( 'file' === $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Choose a CSV file (up to 1 MB).', 'peptide-core' ) . '</p></div>';
		}
	}
}
