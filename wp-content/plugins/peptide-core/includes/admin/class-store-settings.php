<?php
/**
 * 店铺设置下的“税费设置”和“系统设置”页面（STORE-SETTINGS-01），版式参照 Shopname。
 *
 * - 菜单在 Admin_Menus 里；支付设置用 WooCommerce 自己的顶层“支付”菜单（2026-10-23 起不在店铺设置下面）。
 * - 税费设置：见 Tax_Page（TAX-PLANS-01）。
 * - 系统设置：卡片列表。用户 2026-10-23 要了“基础配置、邮件通知、交易条款”三张卡片（先做占位页，同日按截图做成完整页面）。
 *   原来菜单里的 WooCommerce 设置、VialCove 设置、系统状态放在页面底部“其他设置”。
 * - 店铺信息（ST-07，投手版后台的第 6 个菜单，也是开店向导的第 2 步）：只开放对外英文店名、客服 WhatsApp、客服邮箱、
 *   修改自己的密码；域名只读。这些值和“基础设置”“联系方式”是同一份数据（blogname、Settings 的 support），
 *   这里只改这三项，联系方式里的 Telegram、问候语、悬浮按钮开关等保持不变。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Domains;
use PeptideCore\Settings;
use PeptideCore\Staff_Buyer;
use PeptideCore\Support_Contacts;

defined( 'ABSPATH' ) || exit;

final class Store_Settings {

	public const CAP         = 'manage_woocommerce';
	public const SYSTEM_SLUG = 'pc-system';
	public const INFO_SLUG   = Staff_Buyer::PAGE_STORE;

	/** 对外店名最多多少个字符。 */
	public const NAME_MAX = 60;

	public static function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_menu', array( self::class, 'add_buyer_page' ), 62 );
		add_action( 'admin_post_' . Staff_Buyer::ACTION_STORE, array( self::class, 'save_buyer_store' ) );
	}

	// ---------- 店铺信息（ST-07） ----------

	/** 不出现在原来的菜单里（投手的菜单由 Staff_Buyer 生成）；管理员可以直接打开地址。 */
	public static function add_buyer_page(): void {
		add_submenu_page( '', '店铺信息', '店铺信息', Staff_Buyer::CAP_VIEW, self::INFO_SLUG, array( self::class, 'render_buyer_store' ) );
	}

	public static function render_buyer_store(): void {
		if ( ! Staff_Buyer::can_view() ) {
			return;
		}
		echo '<div class="wrap pc-by pc-by-store"><h1>店铺信息</h1>';
		echo Onboarding::banner(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- banner() 里已转义。
		Staff_Buyer::render_flash();
		echo '<section class="pc-by-card">';
		self::buyer_store_form( 'store' );
		echo '</section>';
		$host = Domains::primary_host();
		echo '<section class="pc-by-card"><h2>域名</h2><p class="pc-by-readonly"><code>' . esc_html( '' !== $host ? $host : (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</code> <a href="' . esc_url( home_url( '/' ) ) . '" target="_blank" rel="noopener">打开店铺<span class="screen-reader-text">（在新标签页打开）</span></a></p><p class="pc-by-hint">域名、证书和 CDN 由总后台管理，这里只能查看。需要更换请联系平台负责人。</p></section>';
		echo '<section class="pc-by-card">';
		Onboarding::form_password( 'store' );
		echo '</section></div>';
	}

	/** 店铺信息表单。$from：wizard（开店向导第 2 步）或 store（店铺信息页）。 */
	public static function buyer_store_form( string $from ): void {
		$support = Settings::all()['support'] ?? array();
		$support = is_array( $support ) ? $support : array();
		$wa      = (string) ( $support['whatsapp'] ?? '' );
		echo '<h2>店铺信息</h2><p class="pc-by-why">联系按钮、页脚的联系方式和邮件落款都用这些信息，顾客看得到，请填对外使用的内容。</p>';
		echo '<form class="pc-by-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Staff_Buyer::ACTION_STORE ) . '"><input type="hidden" name="from" value="' . esc_attr( $from ) . '">';
		wp_nonce_field( Staff_Buyer::ACTION_STORE );
		echo '<p><label for="pc-by-store-name">对外英文店名</label><input type="text" id="pc-by-store-name" name="store_name" maxlength="' . (int) self::NAME_MAX . '" value="' . esc_attr( (string) get_option( 'blogname', '' ) ) . '" placeholder="例如 VialCove Peptides" required><span class="pc-by-hint">用英文。订单邮件和付款成功提示里对顾客显示的店名。</span></p>';
		echo '<p><label for="pc-by-store-wa">客服 WhatsApp</label><input type="tel" id="pc-by-store-wa" name="whatsapp" value="' . esc_attr( '' !== $wa ? '+' . $wa : '' ) . '" placeholder="例如 +1 555 123 4567" required><span class="pc-by-hint">带国家代码。顾客点“联系我们”会直接打开和这个号码的对话。</span></p>';
		echo '<p><label for="pc-by-store-email">客服邮箱（可以不填）</label><input type="email" id="pc-by-store-email" name="email" value="' . esc_attr( (string) ( $support['email'] ?? '' ) ) . '" placeholder="例如 support@example.com"></p>';
		echo '<p class="pc-by-actions"><button type="submit" class="button button-primary">' . esc_html( 'wizard' === $from ? '保存，下一步' : '保存' ) . '</button></p></form>';
	}

	/**
	 * 检查店铺信息表单（除邮箱格式外不依赖 WordPress，测试里直接调用）。
	 *
	 * @param array<string,mixed> $in store_name / whatsapp / email
	 * @return array{0:string[],1:array{name:string,whatsapp:string,email:string}} 错误提示和清理后的值。
	 */
	public static function clean_buyer_store( array $in ): array {
		$errors = array();
		$name   = trim( (string) preg_replace( '/\s+/', ' ', (string) ( $in['store_name'] ?? '' ) ) );
		if ( '' === $name ) {
			$errors[] = '请填写店名。';
		} elseif ( strlen( $name ) > self::NAME_MAX ) {
			$errors[] = sprintf( '店名最多 %d 个字符。', self::NAME_MAX );
		} elseif ( 1 === preg_match( '/[^\x20-\x7E]/', $name ) ) {
			$errors[] = '对外店名请用英文（字母、数字和常用符号）。';
		}
		$wa_raw = trim( (string) ( $in['whatsapp'] ?? '' ) );
		$wa     = '' !== $wa_raw ? Support_Contacts::clean_whatsapp( $wa_raw ) : null;
		if ( null === $wa ) {
			$errors[] = '' === $wa_raw ? '请填写客服 WhatsApp 号码。' : 'WhatsApp 号码要带国家代码，例如 +1 555 123 4567。';
		}
		$email_raw = trim( (string) ( $in['email'] ?? '' ) );
		$email     = '' !== $email_raw ? Support_Contacts::clean_email( $email_raw ) : '';
		if ( null === $email ) {
			$errors[] = '客服邮箱的格式不对。不需要的话可以留空。';
		}
		return array( $errors, array( 'name' => $name, 'whatsapp' => (string) $wa, 'email' => (string) $email ) );
	}

	public static function save_buyer_store(): void {
		if ( ! Staff_Buyer::can_view() ) {
			wp_die( esc_html( '没有权限。' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( Staff_Buyer::ACTION_STORE );
		$from = isset( $_POST['from'] ) && 'wizard' === sanitize_key( wp_unslash( $_POST['from'] ) ) ? 'wizard' : 'store';
		$in   = array();
		foreach ( array( 'store_name', 'whatsapp', 'email' ) as $key ) {
			$in[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
		}
		[ $errors, $clean ] = self::clean_buyer_store( $in );
		if ( $errors ) {
			Staff_Buyer::flash( 'error', implode( ' ', $errors ) );
			wp_safe_redirect( 'wizard' === $from ? Onboarding::url( 'store' ) : Staff_Buyer::page_url( self::INFO_SLUG ) );
			exit;
		}
		update_option( 'blogname', $clean['name'] );
		// 和“联系方式”页面写同一份设置：只换 WhatsApp 和邮箱，其余字段照旧。
		$stored  = get_option( Settings::OPTION, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$support = array_merge( Support_Contacts::defaults(), is_array( $stored['support'] ?? null ) ? $stored['support'] : array() );
		$form    = Support_Contacts::to_form( $support );
		$form['support_whatsapp'] = $clean['whatsapp'];
		$form['support_email']    = $clean['email'];
		$stored['support']        = Support_Contacts::sanitize( $form );
		update_option( Settings::OPTION, $stored );
		Onboarding::mark_store_saved();
		Staff_Buyer::flash( 'success', '店铺信息已保存，前台马上生效。' );
		wp_safe_redirect( 'wizard' === $from ? Onboarding::url() : Staff_Buyer::page_url( self::INFO_SLUG ) );
		exit;
	}

	// ---------- 系统设置 ----------

	public static function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// The old test `! SYSTEM_SLUG === $page` was always false, so this loaded on every admin
		// screen. Its .pc-ss-/.pc-bc- styles are shared by many store pages (tax, shipping
		// discounts, language, pixels, apps…), so scope to store pages instead of pc-system only.
		if ( ! str_starts_with( $page, 'pc-' ) ) {
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
			'contact' => array(
				'icon'        => 'dashicons-phone',
				'title'       => __( 'Contact details', 'peptide-core' ),
				'desc'        => __( 'WhatsApp, Telegram and support email shown to customers: the floating support button, the footer and policy pages.', 'peptide-core' ),
				'placeholder' => false,
				'render'      => array( Contact_Page::class, 'render' ),
				'now'         => array(),
			),
			'shopping' => array(
				'icon' => 'dashicons-admin-users',
				'title' => __( 'Shopping preferences', 'peptide-core' ),
				'desc' => __( 'Other contact field preference for reference subscription blocks.', 'peptide-core' ),
				'placeholder' => false,
				'render' => array( Subscriptions_Page::class, 'render_contact_settings' ),
				'now' => array(),
			),
			'youtube' => array(
				'icon' => 'dashicons-video-alt3',
				'title' => __( 'YouTube 视频信息', 'peptide-core' ),
				'desc' => __( '自动读取公开视频的标题、封面和作者，配置来源或同步现有页面。', 'peptide-core' ),
				'placeholder' => false,
				'render' => array( YouTube_Settings::class, 'render' ),
				'now' => array(),
			),
			'languages' => array(
				'icon' => 'dashicons-translation',
				'title' => __( '店铺语言', 'peptide-core' ),
				'desc' => __( '设置美国、波多黎各与其他地区的建议语言，客户手动选择优先。', 'peptide-core' ),
				'placeholder' => false,
				'render' => array( Storefront_Language_Page::class, 'render' ),
				'now' => array(),
			),
			'pixels' => array(
				'icon'        => 'dashicons-chart-line',
				'title'       => __( 'Ad pixels', 'peptide-core' ),
				'desc'        => __( 'Send visits, add to carts and orders to Facebook, Google, TikTok and other ad platforms.', 'peptide-core' ),
				'placeholder' => false,
				'render'      => array( Pixels_Page::class, 'render' ),
				'now'         => array(),
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
		echo '<h1>' . esc_html__( 'System settings', 'peptide-core' ) . '</h1>';
		if ( isset( $_GET['pc_removed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-info"><p>' . esc_html__( 'That page is not part of the store admin. Platform settings (plugins, themes, WordPress and WooCommerce settings, system status) are opened from the master admin.', 'peptide-core' ) . '</p></div>';
		}
		echo '<div class="pc-ss-grid">';
		foreach ( $sections as $key => $s ) {
			echo self::card( $s['icon'], $s['title'], $s['desc'], admin_url( 'admin.php?page=' . self::SYSTEM_SLUG . '&section=' . $key ), $s['placeholder'] ? __( 'Coming soon', 'peptide-core' ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
