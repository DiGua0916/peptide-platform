<?php
/**
 * 店铺设置下的“税费设置”和“系统设置”页面（STORE-SETTINGS-01），版式参照 Shopname。
 *
 * - 物流设置、支付设置直接打开 WooCommerce 自己的设置页（菜单在 Admin_Menus 里）。
 * - 税费设置：WooCommerce 关闭税费时会隐藏“税费”标签页，所以这里先说明并提供开启按钮；已开启时直接跳到 WooCommerce 的税费页。
 * - 系统设置：卡片列表。用户 2026-10-23 先要“基础配置、邮件通知、交易条款”三张卡片的占位页，具体内容之后由用户说明。
 *   原来菜单里的 WooCommerce 设置、VialCove 设置、系统状态放在页面底部“其他设置”。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

defined( 'ABSPATH' ) || exit;

final class Store_Settings {

	public const CAP         = 'manage_woocommerce';
	public const SYSTEM_SLUG = 'pc-system';
	public const TAX_SLUG    = 'pc-tax';

	private const ENABLE_TAX = 'pc_enable_tax';

	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::ENABLE_TAX, array( self::class, 'enable_tax' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/** 已开启税费时，“税费设置”直接打开 WooCommerce 的税费页。挂在 load-{页面} 上。 */
	public static function maybe_redirect_tax(): void {
		if ( function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=tax' ) );
			exit;
		}
	}

	public static function enable_tax(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( self::ENABLE_TAX );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=tax' ) );
		exit;
	}

	public static function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $page, array( self::SYSTEM_SLUG, self::TAX_SLUG ), true ) ) {
			return;
		}
		$file = PEPTIDE_CORE_DIR . '/assets/store-settings.css';
		wp_enqueue_style( 'peptide-core-store-settings', plugins_url( 'assets/store-settings.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * 系统设置里的卡片。placeholder 为真的卡片打开占位页，内容等用户说明；
	 * now 是内容确定之前可以先用的现有设置。
	 */
	public static function sections(): array {
		return array(
			'basic' => array(
				'icon'        => 'dashicons-admin-generic',
				'title'       => __( 'Basic settings', 'peptide-core' ),
				'desc'        => __( 'Store name, favicon, contact details, language, store currency, time zone and more.', 'peptide-core' ),
				'placeholder' => false,
				'render'      => array( Basic_Config_Page::class, 'render' ),
				'now'         => array(
					__( 'WooCommerce general settings (store address, currency)', 'peptide-core' ) => admin_url( 'admin.php?page=wc-settings&tab=general' ),
					__( 'WordPress general settings (site title, language, time zone)', 'peptide-core' ) => admin_url( 'options-general.php' ),
				),
			),
			'email' => array(
				'icon'        => 'dashicons-email-alt',
				'title'       => __( 'Email notifications', 'peptide-core' ),
				'desc'        => __( 'Templates for system emails that are sent automatically when certain events happen.', 'peptide-core' ),
				'placeholder' => true,
				'now'         => array(
					__( 'WooCommerce emails', 'peptide-core' ) => admin_url( 'admin.php?page=wc-settings&tab=email' ),
				),
			),
			'terms' => array(
				'icon'        => 'dashicons-media-text',
				'title'       => __( 'Terms of sale', 'peptide-core' ),
				'desc'        => __( 'Create your own terms pages that set clear rules for everyone taking part in a purchase.', 'peptide-core' ),
				'placeholder' => true,
				'now'         => array(
					__( 'Custom pages (policy pages and checkout terms)', 'peptide-core' ) => admin_url( 'edit.php?post_type=page' ),
				),
			),
		);
	}

	/** 底部“其他设置”：原来店铺设置菜单里的页面。 */
	private static function others(): array {
		$out = array(
			array( 'dashicons-admin-settings', __( 'WooCommerce settings', 'peptide-core' ), __( 'General, products, accounts, emails and advanced WooCommerce options.', 'peptide-core' ), admin_url( 'admin.php?page=wc-settings&tab=general' ) ),
		);
		if ( current_user_can( Settings_Page::CAP ) ) {
			$out[] = array( 'dashicons-admin-tools', __( 'VialCove settings', 'peptide-core' ), __( 'Brand name, demo mode, use statement, checkout confirmation, batch check and the support button.', 'peptide-core' ), admin_url( 'admin.php?page=' . Settings_Page::SLUG ) );
		}
		$out[] = array( 'dashicons-performance', __( 'System status', 'peptide-core' ), __( 'Server, database and plugin information for troubleshooting.', 'peptide-core' ), admin_url( 'admin.php?page=wc-status' ) );
		return $out;
	}

	private static function card( string $icon, string $title, string $desc, string $url, string $tag = '' ): string {
		return '<a class="pc-ss-card" href="' . esc_url( $url ) . '"><span class="pc-ss-card__icon dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span><span class="pc-ss-card__text"><strong>' . esc_html( $title ) . ( $tag ? ' <em class="pc-ss-tag">' . esc_html( $tag ) . '</em>' : '' ) . '</strong><span>' . esc_html( $desc ) . '</span></span></a>';
	}

	public static function render_system(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$sections = self::sections();
		$current  = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap pc-ss">';
		if ( isset( $sections[ $current ] ) ) {
			if ( isset( $sections[ $current ]['render'] ) ) {
				call_user_func( $sections[ $current ]['render'] );
			} else {
				self::render_section( $sections[ $current ] );
			}
			echo '</div>';
			return;
		}
		echo '<h1>' . esc_html__( 'System settings', 'peptide-core' ) . '</h1><div class="pc-ss-grid">';
		foreach ( $sections as $key => $s ) {
			echo self::card( $s['icon'], $s['title'], $s['desc'], admin_url( 'admin.php?page=' . self::SYSTEM_SLUG . '&section=' . $key ), $s['placeholder'] ? __( 'Coming soon', 'peptide-core' ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div><h2 class="pc-ss-sub">' . esc_html__( 'Other settings', 'peptide-core' ) . '</h2><div class="pc-ss-grid">';
		foreach ( self::others() as $o ) {
			echo self::card( $o[0], $o[1], $o[2], $o[3] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></div>';
	}

	private static function render_section( array $s ): void {
		echo '<p class="pc-ss-back"><a href="' . esc_url( admin_url( 'admin.php?page=' . self::SYSTEM_SLUG ) ) . '">‹ ' . esc_html__( 'System settings', 'peptide-core' ) . '</a></p>';
		echo '<h1><span class="dashicons ' . esc_attr( $s['icon'] ) . '" aria-hidden="true"></span> ' . esc_html( $s['title'] ) . '</h1><p class="pc-ss-desc">' . esc_html( $s['desc'] ) . '</p>';
		echo '<div class="pc-ss-panel pc-ss-placeholder"><h2>' . esc_html__( 'Coming soon', 'peptide-core' ) . '</h2><p>' . esc_html__( 'The details of this section are still being decided. Once they are, this page becomes the full settings form.', 'peptide-core' ) . '</p>';
		if ( $s['now'] ) {
			echo '<p>' . esc_html__( 'Until then, these existing settings cover it:', 'peptide-core' ) . '</p><ul>';
			foreach ( $s['now'] as $label => $url ) {
				echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . ' ›</a></li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	public static function render_tax(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="wrap pc-ss"><h1>' . esc_html__( 'Tax settings', 'peptide-core' ) . '</h1><div class="pc-ss-panel">';
		echo '<h2>' . esc_html__( 'Taxes are turned off', 'peptide-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'Checkout does not add any tax right now. Turning taxes on opens the tax rate tables, where you set rates by country, state and ZIP code. Until you add rates, no tax is charged.', 'peptide-core' ) . '</p>';
		echo '<p class="pc-ss-muted">' . esc_html__( 'US sales tax depends on the states where you have to collect it. Check with your accountant before adding rates.', 'peptide-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ENABLE_TAX ) . '">';
		wp_nonce_field( self::ENABLE_TAX );
		submit_button( __( 'Turn on taxes and set rates', 'peptide-core' ), 'primary', 'submit', false );
		echo '</form></div></div>';
	}
}
