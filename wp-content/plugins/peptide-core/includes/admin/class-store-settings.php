<?php
/**
 * 店铺设置下的“税费设置”和“系统设置”页面（STORE-SETTINGS-01），版式参照 Shopname。
 *
 * - 菜单在 Admin_Menus 里；支付设置用 WooCommerce 自己的顶层“支付”菜单（2026-10-23 起不在店铺设置下面）。
 * - 税费设置：见 Tax_Page（TAX-PLANS-01）。
 * - 系统设置：卡片列表。用户 2026-10-23 要了“基础配置、邮件通知、交易条款”三张卡片（先做占位页，同日按截图做成完整页面）。
 *   原来菜单里的 WooCommerce 设置、VialCove 设置、系统状态放在页面底部“其他设置”。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

defined( 'ABSPATH' ) || exit;

final class Store_Settings {

	public const CAP         = 'manage_woocommerce';
	public const SYSTEM_SLUG = 'pc-system';

	public static function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! self::SYSTEM_SLUG === $page ) {
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
				'placeholder' => false,
				'render'      => array( Email_Center::class, 'render' ),
				'now'         => array(
					__( 'WooCommerce emails', 'peptide-core' ) => admin_url( 'admin.php?page=wc-settings&tab=email' ),
				),
			),
			'terms' => array(
				'icon'        => 'dashicons-media-text',
				'title'       => __( 'Terms of sale', 'peptide-core' ),
				'desc'        => __( 'Create your own terms pages that set clear rules for everyone taking part in a purchase.', 'peptide-core' ),
				'placeholder' => false,
				'render'      => array( Terms_Page::class, 'render' ),
				'now'         => array(
					__( 'Custom pages (policy pages and checkout terms)', 'peptide-core' ) => admin_url( 'edit.php?post_type=page' ),
				),
			),
		);
	}

	/** 底部“其他设置”：原来店铺设置菜单里的页面。 */
	private static function others(): array {
		$out = array(
			array( 'dashicons-admin-settings', __( 'WooCommerce settings', 'peptide-core' ), __( 'General, products, accounts and advanced WooCommerce options.', 'peptide-core' ), admin_url( 'admin.php?page=wc-settings&tab=general' ) ),
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

}
