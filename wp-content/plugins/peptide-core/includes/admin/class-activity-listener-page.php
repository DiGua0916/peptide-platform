<?php
/** Merchant controls for the cart's selected promotion listener. */
namespace PeptideCore\Admin;

use PeptideCore\Activity\Store;

defined( 'ABSPATH' ) || exit;

final class Activity_Listener_Page {

	public const APP = 'activity-listener';

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
		wp_enqueue_style( 'peptide-core-activity-listener-admin', $assets . 'activity-listener-admin.css', array(), $version( 'activity-listener-admin.css' ) );
		wp_enqueue_script( 'peptide-core-activity-listener-admin', $assets . 'activity-listener-admin.js', array( 'wp-api-fetch' ), $version( 'activity-listener-admin.js' ), array( 'in_footer' => true ) );
		wp_add_inline_script(
			'peptide-core-activity-listener-admin',
			'window.pcActivityListenerAdmin = ' . wp_json_encode(
				array( 'endpoint' => '/peptide/v1/activity-listener', 'nonce' => wp_create_nonce( 'wp_rest' ), 'promotionsUrl' => admin_url( 'admin.php?page=pc-promotions' ) ),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . ';',
			'before'
		);
	}

	public static function render(): void {
		self::require_access();
		?>
		<div class="wrap pc-activity-admin">
			<div class="pc-ala-head">
				<div><a href="<?php echo esc_url( Apps_Page::url() ); ?>"><?php esc_html_e( '返回插件', 'peptide-core' ); ?></a><h1><?php esc_html_e( '活动监听', 'peptide-core' ); ?></h1></div>
				<p><?php esc_html_e( '选择已有优惠活动，在购物车展示真实档位进度。保存草稿不会改变客户配置，保存并应用后才更新。', 'peptide-core' ); ?></p>
			</div>
			<div id="pc-activity-listener" aria-busy="true"><p role="status"><?php esc_html_e( '正在读取活动监听配置…', 'peptide-core' ); ?></p></div>
			<noscript><p><?php esc_html_e( '请启用 JavaScript 后配置活动监听。', 'peptide-core' ); ?></p></noscript>
		</div>
		<?php
	}

	private static function require_access(): void {
		if ( ! Store::can_manage() ) {
			wp_die( esc_html__( '此账号没有应用配置权限。', 'peptide-core' ), '', array( 'response' => 403 ) );
		}
	}
}
