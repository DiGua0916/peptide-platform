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
		Customer_Admin_I18n::register_hooks();
		Design_Pages::register_hooks();
		Reviews::register_hooks();
		Email_Campaigns::register_hooks();
		Site_Tools::register_hooks();
		Product_Offers::register_hooks();
		Modules\Site_Design::register_hooks();
		Modules\Records::register_hooks();
		Modules\Commerce_Search::register_hooks();
		Admin\Workspace::register_hooks();
		foreach ( array( Customer_Segments::class, Loyalty::class, Modules\Commerce_Blocks::class, Subscriptions::class, Content_Tools::class ) as $feature ) {
			if ( class_exists( $feature ) ) { $feature::register_hooks(); }
		}

		Post_Types::register_hooks();
		Meta::register_hooks();
		Reports::register_hooks();
		Order_Batches::register_hooks();
		Pricing::register_hooks();
		Checkout_Ack::register_hooks();
		Shipping_Tracking::register_hooks();
		Templates::register_hooks();
		Modules\Blocks::register_hooks();
		Modules\Layout::register_hooks();
		Modules\Assets::register_hooks();
		Api\Layout_Controller::register_hooks();
		Menus::register_hooks();
		Custom_Pages::register_hooks();
		Coupons::register_hooks();
		Coupon_Rules::register_hooks();
		Exit_Intent::register_hooks();
		Pixels::register_hooks();
		Seo::register_hooks();
		Domains::register_hooks();
		Blog::register_hooks();
		Media_Library::register_hooks();
		Activity_Log::register_hooks();
		Staff::register_hooks();
		Hq_Connector::register_hooks();
		Topics::register_hooks();
		Promotions::register_hooks();
		Recovery::register_hooks();
		Store_Config::register_hooks();
		Shipping\Plans::register_hooks();
		Shipping\Discounts::register_hooks();
		Emails\Registry::register_hooks();
		Terms::register_hooks();
		Tracking\Tracker::register_hooks();
		Tracking\Checkout_Steps::register_hooks();
		Payments\Local_Payments::register_hooks();
		Api\Tracking_Controller::register_hooks();
		Admin\Overview_Page::register_rest();
		Admin\Data_Pages::register_rest();
		Api\Menu_Controller::register_hooks();
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 5 );
		Api\Rest_Controller::register_hooks();
		Api\Store_Api::register_hooks();

		if ( is_admin() ) {
			Admin\Reviews_Page::register_hooks();
			Admin\Email_Campaigns_Page::register_hooks();
			Admin\Site_Tools_Page::register_hooks();
			Admin\Product_Offers_Page::register_hooks();
			foreach ( array( Admin\Customers_Page::class, Admin\Subscriptions_Page::class, Admin\Content_Tools_Page::class ) as $feature ) {
				if ( class_exists( $feature ) ) { $feature::register_hooks(); }
			}
			Admin_Notices::register_hooks();
			Admin\Product_Panel::register_hooks();
			Admin\Batch_Metabox::register_hooks();
			Admin\Report_Metabox::register_hooks();
			Admin\Order_Metabox::register_hooks();
			Admin\Dashboard::register_hooks();
			Admin\Dialogs::register_hooks();
			Admin\Settings_Page::register_hooks();
			Admin\Batch_Orders::register_hooks();
			Admin\List_Columns::register_hooks();
			Admin\Store_Design::register_hooks();
			Admin\Layout_Editor::register_hooks();
			Admin\Design_Editor::register_hooks();
			Admin\Menu_Page::register_hooks();
			Admin\Tracking_Page::register_hooks();
			Admin\Overview_Page::register_hooks();
			Admin\Admin_Menus::register_hooks();
			Admin\Store_Settings::register_hooks();
			Admin\Basic_Config_Page::register_hooks();
			Admin\Shipping_Page::register_hooks();
			Admin\Tax_Page::register_hooks();
			Admin\Email_Center::register_hooks();
			Admin\Terms_Page::register_hooks();
			Admin\Recovery_Page::register_hooks();
			Admin\Marketing::register_hooks();
			Admin\Promotions_Page::register_hooks();
			Admin\Coupons_Page::register_hooks();
			Admin\Shipping_Discounts_Page::register_hooks();
			Admin\Apps_Page::register_hooks();
			Admin\Pixels_Page::register_hooks();
			Admin\Seo_Page::register_hooks();
			Admin\Data_Pages::register_hooks();
			Admin\Domains_Page::register_hooks();
			Admin\Blog_Admin::register_hooks();
			Admin\Media_Page::register_hooks();
			Admin\Activity_Page::register_hooks();
			Admin\Staff_Page::register_hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'peptide demo', Cli\Demo_Command::class );
			\WP_CLI::add_command( 'peptide import', Cli\Import_Command::class );
			\WP_CLI::add_command( 'peptide doctor', Cli\Doctor_Command::class );
			\WP_CLI::add_command( 'peptide batch', Cli\Batch_Command::class );
			\WP_CLI::add_command( 'peptide pricing', Cli\Pricing_Command::class );
			\WP_CLI::add_command( 'peptide shipping', Cli\Shipping_Command::class );
			\WP_CLI::add_command( 'peptide layout', Cli\Layout_Command::class );
			\WP_CLI::add_command( 'peptide tracking', Cli\Tracking_Command::class );
			\WP_CLI::add_command( 'peptide recovery', Cli\Recovery_Command::class );
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
