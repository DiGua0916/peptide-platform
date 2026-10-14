<?php
/**
 * 插件启动。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'render_missing_woocommerce_notice' ) );
			return;
		}

		require_once PEPTIDE_CORE_DIR . '/includes/functions.php';

		// 优先级 0：要早于 init 上注册文章类型时用到的翻译。
		add_action( 'init', array( self::class, 'load_textdomain' ), 0 );

		Post_Types::register_hooks();
		Meta::register_hooks();
		Reports::register_hooks();
		Order_Batches::register_hooks();
		Checkout_Ack::register_hooks();
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 5 );
		Api\Rest_Controller::register_hooks();
		Api\Store_Api::register_hooks();

		if ( is_admin() ) {
			Admin_Notices::register_hooks();
			Admin\Product_Panel::register_hooks();
			Admin\Batch_Metabox::register_hooks();
			Admin\Report_Metabox::register_hooks();
			Admin\Order_Metabox::register_hooks();
			Admin\Dashboard::register_hooks();
			Admin\Dialogs::register_hooks();
			Admin\Settings_Page::register_hooks();
			Admin\List_Columns::register_hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'peptide demo', Cli\Demo_Command::class );
			\WP_CLI::add_command( 'peptide import', Cli\Import_Command::class );
			\WP_CLI::add_command( 'peptide doctor', Cli\Doctor_Command::class );
		}
	}

	/**
	 * 按当前语言加载插件自带的翻译（languages/peptide-core-{locale}.mo）。
	 * 用绝对路径加载：插件目录通过目录联接挂载时，plugin_basename() 可能拿不到相对路径。
	 * determine_locale() 会使用后台用户自己的语言设置，前台访客仍按站点语言（英文）。
	 */
	public static function load_textdomain(): void {
		$mofile = PEPTIDE_CORE_DIR . '/languages/peptide-core-' . determine_locale() . '.mo';
		if ( is_readable( $mofile ) ) {
			load_textdomain( 'peptide-core', $mofile );
		}
	}

	public static function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Peptide Core requires WooCommerce to be installed and active.', 'peptide-core' )
		);
	}
}
