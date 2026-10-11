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

		Post_Types::register_hooks();
		Meta::register_hooks();
		Reports::register_hooks();

		if ( is_admin() ) {
			Admin_Notices::register_hooks();
			Admin\Product_Panel::register_hooks();
			Admin\Batch_Metabox::register_hooks();
			Admin\Report_Metabox::register_hooks();
			Admin\List_Columns::register_hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'peptide demo', Cli\Demo_Command::class );
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
