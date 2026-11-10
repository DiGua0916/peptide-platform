<?php
/** Merchant settings only. Customer animations are implemented by the frontend adapter. */
namespace PeptideCore\Admin;

use PeptideCore\Cart_Feedback\Store;

defined( 'ABSPATH' ) || exit;

final class Cart_Feedback_Page {
	public const APP = 'cart-feedback';
	public static function register_hooks(): void { add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) ); }
	public static function enqueue(): void {
		if ( 'pc-apps' !== ( $_GET['page'] ?? '' ) || self::APP !== ( $_GET['app'] ?? '' ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only app routing.
		self::require_access();
		$assets = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$version = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-cart-feedback-admin', $assets . 'cart-feedback-admin.css', array(), $version( 'cart-feedback-admin.css' ) );
		wp_enqueue_script( 'peptide-core-cart-feedback-admin', $assets . 'cart-feedback-admin.js', array( 'wp-api-fetch' ), $version( 'cart-feedback-admin.js' ), array( 'in_footer' => true ) );
		wp_add_inline_script( 'peptide-core-cart-feedback-admin', 'window.pcCartFeedbackAdmin = ' . wp_json_encode( array( 'endpoint' => '/peptide/v1/cart-feedback', 'nonce' => wp_create_nonce( 'wp_rest' ) ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}
	public static function render(): void {
		self::require_access();
		?>
		<div class="wrap pc-cart-feedback-admin">
			<div class="pc-cfa-head"><div><a href="<?php echo esc_url( Apps_Page::url() ); ?>"><?php esc_html_e( '返回插件', 'peptide-core' ); ?></a><h1><?php esc_html_e( '加购反馈', 'peptide-core' ); ?></h1></div>
			<p><?php esc_html_e( '配置原位加购反馈。只有 WooCommerce 确认本次加购成功后才显示成功提示；此页不发送加购请求。', 'peptide-core' ); ?></p></div>
			<div id="pc-cart-feedback" aria-busy="true"><p role="status"><?php esc_html_e( '正在读取加购反馈配置…', 'peptide-core' ); ?></p></div>
			<noscript><p><?php esc_html_e( '请启用 JavaScript 后配置加购反馈。', 'peptide-core' ); ?></p></noscript>
		</div>
		<?php
	}
	private static function require_access(): void { if ( ! Store::can_manage() ) { wp_die( esc_html__( '此账号没有应用配置权限。', 'peptide-core' ), '', array( 'response' => 403 ) ); } }
}
