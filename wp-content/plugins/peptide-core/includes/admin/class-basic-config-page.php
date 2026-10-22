<?php
/**
 * 系统设置 → 基础配置页面（BASIC-CONFIG-01），分四块：店铺信息、基础设置、店铺前台设置、公开联系方式。
 * 版式参照 Shopname；每项实际写到哪里见 PeptideCore\Store_Config 的说明。
 *
 * WordPress 自身的设置（店铺名称、管理员邮箱、Favicon、时区、语言）只有站点管理员（manage_options）能改，
 * 店长看到的是只读状态。管理员邮箱按 WordPress 原有流程：发确认邮件到新邮箱，点链接后才生效。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Store_Config;

defined( 'ABSPATH' ) || exit;

final class Basic_Config_Page {

	public const ACTION = 'pc_basic_config_save';

	private const SKU_PATTERNS = array( '{SPU}-{SKU_CODE}', '{SPU}{SKU_CODE}', '{SPU}_{SKU_CODE}' );

	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG . '&section=basic' ) );
	}

	public static function enqueue(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( Store_Settings::SYSTEM_SLUG !== ( $_GET['page'] ?? '' ) || 'basic' !== ( $_GET['section'] ?? '' ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		wp_enqueue_media();
		$file = PEPTIDE_CORE_DIR . '/assets/basic-config.js';
		wp_enqueue_script( 'peptide-core-basic-config', plugins_url( 'assets/basic-config.js', PEPTIDE_CORE_FILE ), array( 'jquery', 'jquery-ui-sortable' ), PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( $file ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_localize_script(
			'peptide-core-basic-config',
			'pcBasicConfig',
			array(
				'states'  => WC()->countries->get_states(),
				'strings' => array(
					'pick'    => __( 'Choose favicon', 'peptide-core' ),
					'use'     => __( 'Use this image', 'peptide-core' ),
					'noState' => __( '— None —', 'peptide-core' ),
				),
			)
		);
	}

	private static function can_core(): bool {
		return current_user_can( 'manage_options' );
	}

	// ---------- 小部件 ----------

	private static function help( string $text ): string {
		return ' <span class="pc-bc-help dashicons dashicons-editor-help" tabindex="0" title="' . esc_attr( $text ) . '" aria-label="' . esc_attr( $text ) . '"></span>';
	}

	private static function field( string $label, string $control, string $help = '', bool $required = false, string $note = '' ): string {
		return '<div class="pc-bc-field"><label class="pc-bc-label">' . ( $required ? '<span class="pc-bc-req">*</span> ' : '' ) . esc_html( $label ) . ( $help ? self::help( $help ) : '' ) . '</label>' . $control . ( $note ? '<p class="pc-bc-note">' . $note . '</p>' : '' ) . '</div>';
	}

	private static function text( string $name, string $value, array $attrs = array() ): string {
		$extra = '';
		foreach ( $attrs as $k => $v ) {
			$extra .= ' ' . esc_attr( $k ) . ( true === $v ? '' : '="' . esc_attr( (string) $v ) . '"' );
		}
		return '<input type="text" class="regular-text pc-bc-input" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $extra . '>';
	}

	/** @param array<string,string> $options */
	private static function select( string $name, array $options, string $current, bool $disabled = false, string $id = '' ): string {
		$html = '<select class="pc-bc-input" name="' . esc_attr( $name ) . '"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ( $disabled ? ' disabled' : '' ) . '>';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $value, $current, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/** @param array<string,string> $options */
	private static function radios( string $name, array $options, string $current, bool $disabled = false ): string {
		$html = '<span class="pc-bc-radios">';
		foreach ( $options as $value => $label ) {
			$html .= '<label><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . checked( (string) $value, $current, false ) . ( $disabled ? ' disabled' : '' ) . '> ' . esc_html( $label ) . '</label>';
		}
		return $html . '</span>';
	}

	private static function on_off( string $name, bool $on, string $on_label = '', string $off_label = '' ): string {
		return self::radios( $name, array( '1' => $on_label ? $on_label : __( 'Enable', 'peptide-core' ), '0' => $off_label ? $off_label : __( 'Disable', 'peptide-core' ) ), $on ? '1' : '0' );
	}

	// ---------- 页面 ----------

	public static function render(): void {
		$c    = Store_Config::all();
		$core = self::can_core();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'peptide-core' ) . '</p></div>';
		}
		if ( isset( $_GET['pc_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['pc_error'] ) ) ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		echo '<p class="pc-ss-back"><a href="' . esc_url( admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG ) ) . '">‹ ' . esc_html__( 'System settings', 'peptide-core' ) . '</a></p>';
		echo '<h1>' . esc_html__( 'Basic settings', 'peptide-core' ) . '</h1>';
		if ( ! $core ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Store name, admin email, favicon, time zone and language are WordPress settings: only site administrators can change them.', 'peptide-core' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-bc">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );

		self::section_store( $c, $core );
		self::section_basic( $c, $core );
		self::section_front( $c, $core );
		self::section_contact();

		echo '<div class="pc-bc-bar"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save settings', 'peptide-core' ) . '</button></div></form>';
	}

	private static function section_store( array $c, bool $core ): void {
		$pending = (string) get_option( 'new_admin_email' );
		$admin   = (string) get_option( 'admin_email' );
		$support = (string) get_option( 'woocommerce_email_from_address' );
		$icon_id = (int) get_option( 'site_icon' );
		$icon    = $icon_id ? (string) wp_get_attachment_image_url( $icon_id, array( 64, 64 ) ) : '';
		$warn    = is_email( $support ) ? '' : '<span class="pc-bc-warn">' . esc_html__( 'Without a support email, automatic emails cannot be sent.', 'peptide-core' ) . '</span>';

		echo '<section class="pc-ss-panel"><h2>' . esc_html__( 'Store information', 'peptide-core' ) . '</h2><div class="pc-bc-grid"><div>';
		echo self::field( __( 'Store name', 'peptide-core' ), self::text( 'blogname', (string) get_option( 'blogname' ), $core ? array( 'required' => true ) : array( 'disabled' => true ) ), '', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$note = esc_html__( 'We contact you at this address when needed (WordPress admin email).', 'peptide-core' );
		if ( $pending && $pending !== $admin ) {
			/* translators: %s: email address */
			$note .= '<br><span class="pc-bc-warn">' . esc_html( sprintf( __( 'Waiting for confirmation: %s. Open the link in the email sent to that address.', 'peptide-core' ), $pending ) ) . '</span>';
		}
		echo self::field( __( 'Admin email', 'peptide-core' ), '<input type="email" class="regular-text pc-bc-input" name="admin_email" value="' . esc_attr( $admin ) . '"' . ( $core ? ' required' : ' disabled' ) . '>', __( 'Changing it sends a confirmation link to the new address; the change takes effect after the link is opened.', 'peptide-core' ), true, $note ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Support email (important)', 'peptide-core' ), '<input type="email" class="regular-text pc-bc-input" name="support_email" value="' . esc_attr( $support ) . '" required>', __( 'Sender address of all store emails (order emails, cart reminders). Use an address on your own domain so emails are not marked as spam.', 'peptide-core' ), true, $warn ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Support name', 'peptide-core' ), self::text( 'support_name', (string) get_option( 'woocommerce_email_from_name' ), array( 'placeholder' => __( 'Sender name of store emails; empty uses the store name', 'peptide-core' ) ) ), __( 'Shown as the sender name in the customer’s inbox.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div><div>';
		$picker  = '<div class="pc-bc-favicon"><span class="pc-bc-favicon__img">' . ( $icon ? '<img src="' . esc_url( $icon ) . '" alt="">' : '<span class="dashicons dashicons-format-image"></span>' ) . '</span>';
		$picker .= '<input type="hidden" name="site_icon" value="' . esc_attr( (string) $icon_id ) . '">';
		if ( $core ) {
			$picker .= '<button type="button" class="button pc-bc-favicon__pick">' . esc_html__( 'Choose image', 'peptide-core' ) . '</button> <button type="button" class="button-link pc-bc-favicon__clear"' . ( $icon_id ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'peptide-core' ) . '</button>';
		}
		$picker .= '</div>';
		echo self::field( __( 'Website favicon', 'peptide-core' ), $picker, '', false, esc_html__( 'The small icon shown in the browser tab and bookmarks. Usually 32×32 px; use png, svg or ico so browsers show it correctly.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Finance email', 'peptide-core' ), '<input type="email" class="regular-text pc-bc-input" name="finance_email" value="' . esc_attr( (string) $c['finance_email'] ) . '">', '', false, esc_html__( 'Receives payment alerts such as possible duplicate payments. Empty uses the admin email.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></div></section>';
	}

	private static function section_basic( array $c, bool $core ): void {
		$locked     = Store_Config::currency_locked();
		$currencies = array();
		foreach ( get_woocommerce_currencies() as $code => $name ) {
			$currencies[ $code ] = $name . ' (' . $code . ')';
		}
		$tz = (string) get_option( 'timezone_string' );

		echo '<section class="pc-ss-panel"><h2>' . esc_html__( 'Basic', 'peptide-core' ) . '</h2><div class="pc-bc-grid"><div>';
		echo self::field( __( 'Store currency', 'peptide-core' ), self::select( 'currency', $currencies, get_woocommerce_currency(), $locked ), '', true, $locked ? esc_html__( 'The store currency cannot be changed after orders exist.', 'peptide-core' ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Generate SPU codes automatically', 'peptide-core' ), self::radios( 'spu_mode', array( 'random' => __( 'Random code (18 digits)', 'peptide-core' ), 'id' => __( 'Same as product ID', 'peptide-core' ) ), (string) $c['spu_mode'] ), __( 'The SPU is the code of the product itself; variation SKUs are built from it. Existing codes are kept.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$formats = array_combine( array_keys( Store_Config::PRICE_FORMATS ), array_keys( Store_Config::PRICE_FORMATS ) );
		$fmt     = Store_Config::price_format_key();
		if ( '' === $fmt ) {
			$formats = array( '' => __( 'Custom (kept as is)', 'peptide-core' ) ) + $formats;
		}
		echo self::field( __( 'Currency format', 'peptide-core' ), self::select( 'price_format', $formats, $fmt ), __( 'Thousands separator, decimal separator and number of decimals in prices.', 'peptide-core' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$bots  = '<textarea name="bot_custom" rows="3" class="large-text pc-bc-input" placeholder="' . esc_attr__( 'One keyword per line, e.g. examplebot', 'peptide-core' ) . '">' . esc_textarea( implode( "\n", (array) $c['bot_custom'] ) ) . '</textarea>';
		echo self::field( __( 'Block crawlers', 'peptide-core' ), $bots, __( 'Visitors whose browser identification (User-Agent) contains one of these keywords get a 403 page. Do not add search engines such as Googlebot or Bingbot.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Admin login security check', 'peptide-core' ), self::on_off( 'admin_captcha', (bool) $c['admin_captcha'] ), __( 'Adds a simple math question to the WordPress login page to slow down password guessing.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div><div>';
		if ( $core ) {
			$tz_control = '<select class="pc-bc-input" name="timezone_string">' . wp_timezone_choice( $tz, get_user_locale() ) . '</select>';
		} else {
			$tz_control = self::text( 'timezone_string', $tz ? $tz : wp_timezone_string(), array( 'disabled' => true ) );
		}
		echo self::field( __( 'Time zone', 'peptide-core' ), $tz_control, __( 'Used for order times and the dashboard (“today” starts at midnight in this time zone).', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$patterns = array_combine( self::SKU_PATTERNS, self::SKU_PATTERNS );
		$sku      = self::radios( 'sku_auto', array( '0' => __( 'Do not generate', 'peptide-core' ), '1' => __( 'Generate', 'peptide-core' ) ), $c['sku_auto'] ? '1' : '0' ) . ' ' . self::select( 'sku_pattern', $patterns, (string) $c['sku_pattern'] );
		echo self::field( __( 'Generate SKU codes automatically', 'peptide-core' ), $sku, __( 'When a product or variation is saved without a SKU, one is created: simple products use the SPU, variations use the pattern ({SKU_CODE} is the variation number 01, 02, …).', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$hours = array();
		foreach ( Store_Config::RETURNING_HOURS as $h ) {
			/* translators: %d: number of hours */
			$hours[ (string) $h ] = $h < 24 ? sprintf( _n( '%d hour', '%d hours', $h, 'peptide-core' ), $h ) : sprintf( _n( '%d day', '%d days', (int) ( $h / 24 ), 'peptide-core' ), (int) ( $h / 24 ) );
		}
		echo self::field( __( 'Returning visitor rule', 'peptide-core' ), '<span class="pc-bc-inline">' . esc_html__( 'Visitors who come back more than', 'peptide-core' ) . ' ' . self::select( 'returning_hours', $hours, (string) $c['returning_hours'] ) . ' ' . esc_html__( 'after their first visit count as returning', 'peptide-core' ) . '</span>', __( 'Used by “Returning visitors” on the dashboard.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$system  = '<label class="pc-bc-check"><input type="checkbox" name="bot_system" value="1"' . checked( (bool) $c['bot_system'], true, false ) . '> ' . esc_html__( 'Also block the built-in list of security scanners and scraping bots', 'peptide-core' ) . '</label>';
		$system .= '<details class="pc-bc-details"><summary>' . esc_html( sprintf( /* translators: %d: number of keywords */ __( 'Built-in list (%d keywords)', 'peptide-core' ), count( Store_Config::SYSTEM_BOTS ) ) ) . '</summary><p>' . esc_html( implode( ', ', Store_Config::SYSTEM_BOTS ) ) . '</p></details>';
		echo self::field( __( 'System crawler block', 'peptide-core' ), $system ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></div></section>';
	}

	private static function section_front( array $c, bool $core ): void {
		$langs = array( '' => 'English (United States)' );
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		$avail = wp_get_available_translations();
		foreach ( get_available_languages() as $code ) {
			$langs[ $code ] = $avail[ $code ]['native_name'] ?? $code;
		}
		$open    = 'yes' !== get_option( 'woocommerce_coming_soon' );
		$reg_on  = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' );
		$search  = (array) $c['search_fields'];
		$labels  = Store_Config::sort_labels();
		$enabled = (array) $c['sorts'];
		$order   = array_merge( array_values( array_intersect( $enabled, Store_Config::SORTS ) ), array_values( array_diff( Store_Config::SORTS, $enabled ) ) );

		echo '<section class="pc-ss-panel"><h2>' . esc_html__( 'Storefront', 'peptide-core' ) . '</h2><div class="pc-bc-grid"><div>';
		echo self::field( __( 'Language', 'peptide-core' ), $core ? self::select( 'WPLANG', $langs, (string) get_option( 'WPLANG' ) ) : self::text( 'WPLANG', $langs[ (string) get_option( 'WPLANG' ) ] ?? '', array( 'disabled' => true ) ), __( 'Language of the storefront texts provided by WordPress, WooCommerce and this plugin. Your own admin language is set in your profile.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$checks = '<span class="pc-bc-radios"><label><input type="checkbox" checked disabled> ' . esc_html__( 'Product title', 'peptide-core' ) . '</label>';
		foreach ( array( 'sku' => __( 'SKU', 'peptide-core' ), 'category' => __( 'Category', 'peptide-core' ), 'tag' => __( 'Tag', 'peptide-core' ) ) as $k => $l ) {
			$checks .= '<label><input type="checkbox" name="search_fields[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $search, true ), true, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		$checks .= '</span>';
		echo self::field( __( 'Storefront search fields', 'peptide-core' ), $checks, __( 'What the storefront product search matches besides the product title and description.', 'peptide-core' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Require sign-in to visit the store', 'peptide-core' ), self::on_off( 'force_login', (bool) $c['force_login'], __( 'Yes', 'peptide-core' ), __( 'No', 'peptide-core' ) ), __( 'Visitors who are not signed in are sent to the account page. The privacy policy and checkout terms pages stay public.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$sort = '<ul class="pc-bc-sort">';
		foreach ( $order as $key ) {
			$sort .= '<li><span class="dashicons dashicons-menu" aria-hidden="true"></span><label><input type="checkbox" name="sorts[]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, $enabled, true ), true, false ) . '> ' . esc_html( $labels[ $key ] ) . '</label></li>';
		}
		$sort .= '</ul>';
		echo self::field( __( 'Storefront sort options', 'peptide-core' ), $sort, __( 'Ticked options appear in the sort menu of the shop; drag to change the order. The first one is the default sort.', 'peptide-core' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Allow the store in an iframe', 'peptide-core' ), self::on_off( 'allow_iframe', (bool) $c['allow_iframe'] ), __( 'When off, other websites cannot embed your store pages (protects against clickjacking).', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$links = array(
			'product'  => home_url( '/product/sample-product/' ),
			'shop'     => home_url( Store_Config::product_link_bases()['shop'] . '/sample-product/' ),
			'shop_cat' => home_url( str_replace( '%product_cat%', 'category', Store_Config::product_link_bases()['shop_cat'] ) . '/sample-product/' ),
		);
		$cur_link = Store_Config::current_product_link();
		if ( 'custom' === $cur_link ) {
			$links = array( 'custom' => __( 'Custom (set in Settings → Permalinks)', 'peptide-core' ) ) + $links;
		}
		echo self::field( __( 'Product link structure', 'peptide-core' ), self::select( 'product_link', $links, $cur_link ), __( 'Changing it changes the address of every product page. Old links stop working, so decide before you promote the store.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Product page of an unpublished product', 'peptide-core' ), self::select( 'offline_product', array( '404' => __( 'Show the 404 page', 'peptide-core' ), 'home' => __( 'Go to the home page', 'peptide-core' ), 'shop' => __( 'Go to the shop page', 'peptide-core' ) ), (string) $c['offline_product'] ), __( 'What visitors see when they open a product that is a draft, private or in the trash.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div><div>';
		echo self::field( __( 'Register email verification', 'peptide-core' ), self::on_off( 'register_verify', (bool) $c['register_verify'] ), __( 'Customers who register on the account page must open a link in their email before they can sign in. Accounts created during checkout are not affected.', 'peptide-core' ), false, $reg_on ? '' : esc_html__( 'Registration on the account page is currently turned off (WooCommerce settings → Accounts & Privacy).', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Store status', 'peptide-core' ), self::radios( 'store_open', array( '1' => __( 'Open', 'peptide-core' ), '0' => __( 'Closed', 'peptide-core' ) ), $open ? '1' : '0' ), __( 'Closed shows a “coming soon” page to visitors (WooCommerce site visibility). You still see the store while signed in.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Default variation on product pages', 'peptide-core' ), self::select( 'default_variant', array( 'first' => __( 'First available variation', 'peptide-core' ), 'none' => __( 'None selected', 'peptide-core' ) ), (string) $c['default_variant'] ), __( 'Used when a variable product has no default variation of its own.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Sold-out variations on product pages', 'peptide-core' ), self::radios( 'soldout_variants', array( 'show' => __( 'Show', 'peptide-core' ), 'hide' => __( 'Hide', 'peptide-core' ) ), (string) $c['soldout_variants'] ), __( 'Hidden variations cannot be selected.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></div></section>';
	}

	private static function section_contact(): void {
		$c         = Store_Config::contact();
		$countries = array( '' => __( 'Select a country', 'peptide-core' ) ) + WC()->countries->get_countries();
		$states    = $c['country'] ? (array) WC()->countries->get_states( $c['country'] ) : array();

		echo '<section class="pc-ss-panel"><h2>' . esc_html__( 'Public contact information', 'peptide-core' ) . self::help( __( 'Shown in store emails (US law requires a physical address in marketing emails) and available to pages through [pc_var]. Country, state, city, address and ZIP are the WooCommerce store address.', 'peptide-core' ) ) . '</h2><div class="pc-bc-grid"><div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Company name', 'peptide-core' ), self::text( 'company_name', $c['company'], array( 'placeholder' => __( 'Enter the company name', 'peptide-core' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $states ) {
			$state_ctl = self::select( 'state', array( '' => __( '— None —', 'peptide-core' ) ) + $states, $c['state'], false, 'pc-bc-state' );
		} else {
			$state_ctl = self::text( 'state', $c['state'], array( 'id' => 'pc-bc-state', 'placeholder' => __( 'Enter the state or province', 'peptide-core' ) ) );
		}
		echo self::field( __( 'State / province', 'peptide-core' ), '<span class="pc-bc-state-wrap">' . $state_ctl . '</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Address', 'peptide-core' ), self::text( 'address', $c['address'], array( 'placeholder' => __( 'Enter the street address', 'peptide-core' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'ZIP / postcode', 'peptide-core' ), self::text( 'postcode', $c['postcode'], array( 'placeholder' => __( 'Enter the ZIP or postcode', 'peptide-core' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div><div>';
		echo self::field( __( 'Country', 'peptide-core' ), self::select( 'country', $countries, $c['country'], false, 'pc-bc-country' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'City', 'peptide-core' ), self::text( 'city', $c['city'], array( 'placeholder' => __( 'Enter the city', 'peptide-core' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Phone', 'peptide-core' ), self::text( 'phone', $c['phone'], array( 'placeholder' => __( 'Enter the phone number', 'peptide-core' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></div></section>';
	}

	// ---------- 保存 ----------

	public static function save(): void {
		if ( ! current_user_can( Store_Settings::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$errors = self::apply( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		wp_safe_redirect( self::url( $errors ? array( 'pc_error' => rawurlencode( implode( ' ', $errors ) ) ) : array( 'updated' => 1 ) ) );
		exit;
	}

	/**
	 * 校验并保存表单内容（测试里直接调用）。返回错误信息，空数组表示全部保存成功。
	 *
	 * @param array<string,mixed> $in
	 * @return string[]
	 */
	public static function apply( array $in ): array {
		$errors = array();
		$text   = static fn( string $k ): string => sanitize_text_field( (string) ( $in[ $k ] ?? '' ) );

		if ( self::can_core() ) {
			$name = $text( 'blogname' );
			if ( '' === $name ) {
				$errors[] = __( 'The store name cannot be empty.', 'peptide-core' );
			} else {
				update_option( 'blogname', $name );
			}
			$admin = sanitize_email( (string) ( $in['admin_email'] ?? '' ) );
			if ( ! is_email( $admin ) ) {
				$errors[] = __( 'The admin email is not valid.', 'peptide-core' );
			} elseif ( $admin !== get_option( 'admin_email' ) ) {
				// 和 WordPress “常规设置”一样：先发确认邮件到新地址，确认后才替换。
				if ( ! has_action( 'update_option_new_admin_email', 'update_option_new_admin_email' ) && function_exists( 'update_option_new_admin_email' ) ) {
					add_action( 'update_option_new_admin_email', 'update_option_new_admin_email', 10, 2 );
					add_action( 'add_option_new_admin_email', 'update_option_new_admin_email', 10, 2 );
				}
				update_option( 'new_admin_email', $admin );
			}
			$icon = absint( $in['site_icon'] ?? 0 );
			if ( 0 === $icon || wp_attachment_is_image( $icon ) ) {
				update_option( 'site_icon', $icon );
			}
			$tz = $text( 'timezone_string' );
			if ( in_array( $tz, timezone_identifiers_list(), true ) ) {
				update_option( 'timezone_string', $tz );
			} elseif ( '' !== $tz ) {
				$errors[] = __( 'Choose a city-based time zone (for example Los Angeles), so daylight saving time is handled.', 'peptide-core' );
			}
			$lang = $text( 'WPLANG' );
			if ( '' === $lang || in_array( $lang, get_available_languages(), true ) ) {
				update_option( 'WPLANG', $lang );
			}
		}

		$support = sanitize_email( (string) ( $in['support_email'] ?? '' ) );
		if ( is_email( $support ) ) {
			update_option( 'woocommerce_email_from_address', $support );
		} else {
			$errors[] = __( 'The support email is not valid.', 'peptide-core' );
		}
		update_option( 'woocommerce_email_from_name', $text( 'support_name' ) );
		$finance = sanitize_email( (string) ( $in['finance_email'] ?? '' ) );
		if ( '' !== $finance && ! is_email( $finance ) ) {
			$errors[] = __( 'The finance email is not valid.', 'peptide-core' );
			$finance  = (string) Store_Config::get( 'finance_email' );
		}

		$currency = strtoupper( $text( 'currency' ) );
		if ( '' !== $currency && ! Store_Config::currency_locked() && isset( get_woocommerce_currencies()[ $currency ] ) ) {
			update_option( 'woocommerce_currency', $currency );
		}
		$fmt = $text( 'price_format' );
		if ( isset( Store_Config::PRICE_FORMATS[ $fmt ] ) ) {
			[ $th, $dec, $num ] = Store_Config::PRICE_FORMATS[ $fmt ];
			update_option( 'woocommerce_price_thousand_sep', $th );
			update_option( 'woocommerce_price_decimal_sep', $dec );
			update_option( 'woocommerce_price_num_decimals', $num );
		}

		$bots = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) ( $in['bot_custom'] ?? '' ) ) as $k ) {
			$k = strtolower( trim( sanitize_text_field( $k ) ) );
			if ( strlen( $k ) >= 3 && strlen( $k ) <= 64 ) {
				$bots[] = $k;
			}
		}
		$sorts = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['sorts'] ?? array() ) ), Store_Config::SORTS ) );
		if ( ! $sorts ) {
			$errors[] = __( 'Keep at least one sort option.', 'peptide-core' );
			$sorts    = (array) Store_Config::get( 'sorts' );
		}
		$pick = static function ( string $k, array $allowed, $fallback ) use ( $in ) {
			$v = (string) ( $in[ $k ] ?? '' );
			return in_array( $v, array_map( 'strval', $allowed ), true ) ? $v : $fallback;
		};
		Store_Config::update(
			array(
				'spu_mode'         => $pick( 'spu_mode', array( 'random', 'id' ), 'random' ),
				'sku_auto'         => ! empty( $in['sku_auto'] ),
				'sku_pattern'      => $pick( 'sku_pattern', self::SKU_PATTERNS, '{SPU}-{SKU_CODE}' ),
				'returning_hours'  => (int) $pick( 'returning_hours', Store_Config::RETURNING_HOURS, '24' ),
				'bot_system'       => ! empty( $in['bot_system'] ),
				'bot_custom'       => array_slice( array_values( array_unique( $bots ) ), 0, 100 ),
				'admin_captcha'    => ! empty( $in['admin_captcha'] ),
				'search_fields'    => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['search_fields'] ?? array() ) ), array( 'sku', 'category', 'tag' ) ) ),
				'force_login'      => ! empty( $in['force_login'] ),
				'register_verify'  => ! empty( $in['register_verify'] ),
				'sorts'            => $sorts,
				'allow_iframe'     => ! empty( $in['allow_iframe'] ),
				'offline_product'  => $pick( 'offline_product', array( '404', 'home', 'shop' ), '404' ),
				'default_variant'  => $pick( 'default_variant', array( 'first', 'none' ), 'first' ),
				'soldout_variants' => $pick( 'soldout_variants', array( 'show', 'hide' ), 'show' ),
				'finance_email'    => $finance,
				'company_name'     => $text( 'company_name' ),
				'company_phone'    => $text( 'phone' ),
			)
		);

		if ( isset( $in['store_open'] ) ) {
			$closed = '0' === (string) $in['store_open'];
			update_option( 'woocommerce_coming_soon', $closed ? 'yes' : 'no' );
			if ( $closed ) {
				update_option( 'woocommerce_store_pages_only', 'no' );
			}
		}
		$link = $text( 'product_link' );
		if ( 'custom' !== $link ) {
			Store_Config::apply_product_link( $link );
		}

		$country = strtoupper( $text( 'country' ) );
		if ( '' !== $country && isset( WC()->countries->get_countries()[ $country ] ) ) {
			$state  = strtoupper( $text( 'state' ) );
			$states = (array) WC()->countries->get_states( $country );
			if ( $states && ! isset( $states[ $state ] ) ) {
				$state = '';
			}
			update_option( 'woocommerce_default_country', $country . ( '' !== $state ? ':' . $state : '' ) );
		}
		update_option( 'woocommerce_store_city', $text( 'city' ) );
		update_option( 'woocommerce_store_address', $text( 'address' ) );
		update_option( 'woocommerce_store_postcode', $text( 'postcode' ) );
		return $errors;
	}
}
