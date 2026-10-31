<?php
/** Provider administration belongs to HQ; store order payment details remain available. */
namespace VialCove\Panapay;
defined( 'ABSPATH' ) || exit;

final class Store_Admin {
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'hide_menus' ), PHP_INT_MAX );
		add_action( 'admin_init', array( self::class, 'guard_settings' ), 0 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_filter( 'pre_update_option_woocommerce_pc_panapay_settings', static fn( $new, $old ) => Bridge::enabled() ? $old : $new, PHP_INT_MAX, 2 );
	}
	public static function hide_menus(): void {
		if ( ! Bridge::enabled() ) { return; }
		global $menu;
		foreach ( (array) $menu as $item ) {
			$slug = (string) ( $item[2] ?? '' );
			if ( str_starts_with( $slug, 'admin.php?page=wc-settings&tab=checkout' ) || str_starts_with( $slug, 'wc-admin&path=/payments' ) ) { remove_menu_page( $slug ); }
		}
	}
	public static function guard_settings(): void {
		if ( ! Bridge::enabled() || wp_doing_ajax() || ! is_admin() ) { return; }
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : '';
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : '';
		$path = isset( $_GET['path'] ) && is_string( $_GET['path'] ) ? wp_unslash( $_GET['path'] ) : '';
		if ( ! ( ( 'wc-settings' === $page && 'checkout' === $tab ) || ( 'wc-admin' === $page && str_starts_with( $path, '/payments' ) ) ) ) { return; }
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( '支付配置已移至总后台，此站点不能修改商户设置。', 403 ); }
		set_transient( 'pc_pay_moved_' . get_current_user_id(), 1, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=pc-overview' ) ); exit;
	}
	public static function notice(): void {
		$key = 'pc_pay_moved_' . get_current_user_id();
		if ( ! get_transient( $key ) ) { return; }
		delete_transient( $key );
		echo '<div class="notice notice-info"><p>支付设置已统一移至管理后台。本站订单中的付款状态和交易记录仍可正常查看。</p></div>';
	}
}
