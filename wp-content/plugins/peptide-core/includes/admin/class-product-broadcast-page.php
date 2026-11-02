<?php
/** Merchant editor for the store-scoped product broadcast configuration. */
namespace PeptideCore\Admin;

use PeptideCore\Broadcast\Store;

defined( 'ABSPATH' ) || exit;

final class Product_Broadcast_Page {

	public const APP = 'product-broadcast';

	public static function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function enqueue(): void {
		// This is the existing application center, not a second plugin manager.
		if ( 'pc-apps' !== ( $_GET['page'] ?? '' ) || self::APP !== ( $_GET['app'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		self::require_access();
		$assets = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$version = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-product-broadcast-admin', $assets . 'product-broadcast-admin.css', array(), $version( 'product-broadcast-admin.css' ) );
		wp_enqueue_script( 'peptide-core-product-broadcast-admin', $assets . 'product-broadcast-admin.js', array( 'wp-api-fetch' ), $version( 'product-broadcast-admin.js' ), array( 'in_footer' => true ) );
		wp_add_inline_script(
			'peptide-core-product-broadcast-admin',
			'window.pcProductBroadcastAdmin = ' . wp_json_encode(
				array( 'endpoint' => '/peptide/v1/product-broadcast', 'nonce' => wp_create_nonce( 'wp_rest' ) ),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . ';',
			'before'
		);
	}

	public static function render(): void {
		self::require_access();
		?>
		<div class="wrap pc-broadcast-admin">
			<div class="pc-bra-head">
				<div><a href="<?php echo esc_url( Apps_Page::url() ); ?>"><?php esc_html_e( '返回应用中心', 'peptide-core' ); ?></a><h1><?php esc_html_e( '商品播报', 'peptide-core' ); ?></h1></div>
				<p><?php esc_html_e( '填写文案，按需导入参数。右侧自动轮播预览；保存并应用后才更新客户页。', 'peptide-core' ); ?></p>
			</div>
			<div id="pc-product-broadcast" aria-busy="true"><p role="status"><?php esc_html_e( '正在读取商品播报配置…', 'peptide-core' ); ?></p></div>
			<noscript><p><?php esc_html_e( '请启用 JavaScript 后编辑商品播报。', 'peptide-core' ); ?></p></noscript>
		</div>
		<?php
	}

	private static function require_access(): void {
		if ( ! Store::can_manage() ) {
			wp_die( esc_html__( '此账号没有应用配置权限。', 'peptide-core' ), '', array( 'response' => 403 ) );
		}
	}
}
