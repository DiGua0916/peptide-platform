<?php
/** Merchant controls for browser-local recent products. */
namespace PeptideCore\Admin;

use PeptideCore\Recent\Store;

defined( 'ABSPATH' ) || exit;

final class Recently_Viewed_Page {

	public const APP = 'recently-viewed';

	public static function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function enqueue(): void {
		if ( 'pc-apps' !== ( $_GET['page'] ?? '' ) || self::APP !== ( $_GET['app'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only application routing.
			return;
		}
		self::require_access();
		$assets = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$version = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-recently-viewed-admin', $assets . 'recently-viewed-admin.css', array(), $version( 'recently-viewed-admin.css' ) );
		wp_enqueue_script( 'peptide-core-recently-viewed-admin', $assets . 'recently-viewed-admin.js', array( 'wp-api-fetch' ), $version( 'recently-viewed-admin.js' ), array( 'in_footer' => true ) );
		wp_add_inline_script(
			'peptide-core-recently-viewed-admin',
			'window.pcRecentlyViewedAdmin = ' . wp_json_encode(
				array( 'endpoint' => '/peptide/v1/recently-viewed', 'nonce' => wp_create_nonce( 'wp_rest' ), 'itemsUrl' => rest_url( 'peptide/v1/recently-viewed/items' ) ),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . ';',
			'before'
		);
	}

	public static function render(): void {
		self::require_access();
		?>
		<div class="wrap pc-recent-admin">
			<div class="pc-rva-head">
				<div><a href="<?php echo esc_url( Apps_Page::url() ); ?>"><?php esc_html_e( '返回应用中心', 'peptide-core' ); ?></a><h1><?php esc_html_e( '最近浏览', 'peptide-core' ); ?></h1></div>
				<p><?php esc_html_e( '管理当前浏览器的最近浏览商品。保存草稿不会改变客户配置，保存并应用后才更新。', 'peptide-core' ); ?></p>
			</div>
			<div id="pc-recently-viewed" aria-busy="true"><p role="status"><?php esc_html_e( '正在读取最近浏览配置…', 'peptide-core' ); ?></p></div>
			<noscript><p><?php esc_html_e( '请启用 JavaScript 后配置最近浏览。', 'peptide-core' ); ?></p></noscript>
		</div>
		<?php
	}

	private static function require_access(): void {
		if ( ! Store::can_manage() ) {
			wp_die( esc_html__( '此账号没有应用配置权限。', 'peptide-core' ), '', array( 'response' => 403 ) );
		}
	}
}
