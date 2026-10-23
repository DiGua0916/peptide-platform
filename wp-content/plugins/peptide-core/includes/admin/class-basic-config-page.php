<?php
/**
 * 系统设置 → 基础配置页面（BASIC-CONFIG-01），分四块：店铺信息、基础设置、店铺前台设置、公开联系方式，
 * 最后列出 Shopname 有、本站不适用的选项和原因。版式和每项的问号说明参照 Shopname；每项实际写到哪里见 PeptideCore\Store_Config。
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

	/** 问号说明：鼠标移上去或键盘聚焦时显示深色提示框（换行用 \n）。 */
	private static function help( string $text ): string {
		return ' <span class="pc-bc-help" tabindex="0" role="note" aria-label="' . esc_attr( $text ) . '" data-tip="' . esc_attr( $text ) . '"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span></span>';
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
		self::section_not_applicable();

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
		echo self::field( __( 'Admin email', 'peptide-core' ), '<input type="email" class="regular-text pc-bc-input" name="admin_email" value="' . esc_attr( $admin ) . '"' . ( $core ? ' required' : ' disabled' ) . '>', __( "We use the admin email to contact you and to send order notifications (admin emails such as new orders go here unless you set other recipients). Make sure it is correct so you do not miss anything.\nChanging it sends a confirmation link to the new address; the change takes effect after the link is opened.", 'peptide-core' ), true, $note ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Support email (important)', 'peptide-core' ), '<input type="email" class="regular-text pc-bc-input" name="support_email" value="' . esc_attr( $support ) . '" required>', __( 'Sender address of all store emails (order emails, cart reminders). Use an address on your own domain so emails are not marked as spam.', 'peptide-core' ), true, $warn ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Support name', 'peptide-core' ), self::text( 'support_name', (string) get_option( 'woocommerce_email_from_name' ), array( 'placeholder' => __( 'Sender name of store emails; empty uses the store name', 'peptide-core' ) ) ), __( 'The sender shown in store emails, so customers can tell where the email comes from.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		echo self::field( __( 'Generate SPU codes automatically', 'peptide-core' ), self::radios( 'spu_mode', array( 'random' => __( 'Random code (18 digits)', 'peptide-core' ), 'id' => __( 'Same as product ID (auto-increment number)', 'peptide-core' ) ), (string) $c['spu_mode'] ), __( "When you have not set an SPU yourself, the system generates one (it identifies the product uniquely in this store). This applies to new products (including imports) and to edited products.\nYou can also type an SPU on the product edit page; existing codes are kept.", 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$formats = array_combine( array_keys( Store_Config::PRICE_FORMATS ), array_keys( Store_Config::PRICE_FORMATS ) );
		$fmt     = Store_Config::price_format_key();
		if ( '' === $fmt ) {
			$formats = array( '' => __( 'Custom (kept as is)', 'peptide-core' ) ) + $formats;
		}
		echo self::field( __( 'Currency format', 'peptide-core' ), self::select( 'price_format', $formats, $fmt ), __( 'Thousands separator, decimal separator and number of decimals in prices.', 'peptide-core' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$modes = array(
			'smart'       => __( 'Same in storefront and admin: smart search (2 words or fewer must all match; more than that, 80% of the words)', 'peptide-core' ),
			'all'         => __( 'Same in storefront and admin: every word must be in the title', 'peptide-core' ),
			'front_smart' => __( 'Smart search in the storefront; WooCommerce’s own search in admin', 'peptide-core' ),
		);
		echo self::field( __( 'Product title search', 'peptide-core' ), self::select( 'search_mode', $modes, (string) $c['search_mode'] ), __( 'How product titles are matched by the storefront search and by the admin product search. SKUs (and the storefront search fields below) are matched as well.', 'peptide-core' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Admin login security check', 'peptide-core' ), self::on_off( 'admin_captcha', (bool) $c['admin_captcha'] ), __( 'Stops programs from guessing admin logins automatically: the WordPress login page asks a simple math question.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$known = '<span class="pc-bc-radios pc-bc-bots">';
		foreach ( Store_Config::KNOWN_BOTS as $label => $key ) {
			$known .= '<label><input type="checkbox" name="bot_known[]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, (array) $c['bot_known'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		$known .= '</span><textarea name="bot_custom" rows="2" class="large-text pc-bc-input" placeholder="' . esc_attr__( 'Other crawlers: one User-Agent keyword per line, e.g. examplebot', 'peptide-core' ) . '">' . esc_textarea( implode( "\n", (array) $c['bot_custom'] ) ) . '</textarea>';
		echo self::field( __( 'Block crawlers', 'peptide-core' ), $known, __( "Choose which internet crawlers may not crawl your store. Ticked crawlers get a 403 page, and a Disallow line is added to robots.txt.\nBlocking Googlebot or Bingbot removes the store from that search engine; blocking Meta / Facebook stops link previews on Facebook and Instagram.", 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div><div>';
		if ( $core ) {
			$tz_control = '<select class="pc-bc-input" name="timezone_string">' . wp_timezone_choice( $tz, get_user_locale() ) . '</select>';
		} else {
			$tz_control = self::text( 'timezone_string', $tz ? $tz : wp_timezone_string(), array( 'disabled' => true ) );
		}
		echo self::field( __( 'Time zone', 'peptide-core' ), $tz_control, __( 'Used for order times and the dashboard (“today” starts at midnight in this time zone).', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$patterns = array_combine( self::SKU_PATTERNS, self::SKU_PATTERNS );
		$sku      = self::radios( 'sku_auto', array( '0' => __( 'Do not generate', 'peptide-core' ), '1' => __( 'Generate', 'peptide-core' ) ), $c['sku_auto'] ? '1' : '0' ) . ' ' . self::select( 'sku_pattern', $patterns, (string) $c['sku_pattern'] );
		echo self::field( __( 'Generate SKU codes automatically', 'peptide-core' ), $sku, __( "When on, SKUs are created for new, duplicated, edited and imported products that have no SKU yet.\nVariations use the pattern ({SKU_CODE} is the variation number 01, 02, …). A product with a single variation has no variation part: its SKU is the SPU.", 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$hours = array();
		foreach ( Store_Config::RETURNING_HOURS as $h ) {
			/* translators: %d: number of hours */
			$hours[ (string) $h ] = $h < 24 ? sprintf( _n( '%d hour', '%d hours', $h, 'peptide-core' ), $h ) : sprintf( _n( '%d day', '%d days', (int) ( $h / 24 ), 'peptide-core' ), (int) ( $h / 24 ) );
		}
		echo self::field( __( 'Returning visitor rule', 'peptide-core' ), '<span class="pc-bc-inline">' . esc_html__( 'Visitors who come back more than', 'peptide-core' ) . ' ' . self::select( 'returning_hours', $hours, (string) $c['returning_hours'] ) . ' ' . esc_html__( 'after their first visit count as returning', 'peptide-core' ) . '</span>', __( "1. Separates new visitors from returning visitors (used by “Returning visitors” on the dashboard).\n2. A returning visitor visits the store again, e.g. more than 1 day after the first visit.\n3. Calculation: (end of the first visit’s day, 23:59:59, minus the visit time) + the returning time; in other words the next visit must come after the end of the first day plus this time.\n4. Keep one store time zone; changing it often makes the numbers less accurate.", 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Anti-crawler / high-risk IP limit', 'peptide-core' ), self::on_off( 'bot_system', (bool) $c['bot_system'] ), __( "1. When on, the system blocks unfriendly crawlers and security scanners from visiting your store (the built-in list below).\n2. When off, they are allowed, e.g. while you temporarily run a scanning tool on your store.\nHigh-risk IP detection needs an IP reputation service and is not available on this site; blocking works by crawler name.", 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$system = '<details class="pc-bc-details"><summary>' . esc_html( Store_Config::SYSTEM_BOTS[0] ) . ' <span class="pc-bc-chip">+' . (int) ( count( Store_Config::SYSTEM_BOTS ) - 1 ) . '</span></summary><p>' . esc_html( implode( ', ', Store_Config::SYSTEM_BOTS ) ) . '</p></details>';
		echo self::field( __( 'System crawler block', 'peptide-core' ), $system, __( 'These crawlers are blocked at system level and cannot be edited; they are switched on and off with “Anti-crawler / high-risk IP limit” above.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		echo self::field( __( 'Storefront search fields', 'peptide-core' ), $checks, __( "What the storefront product search matches. The product title is always searched (see “Product title search”).\nSKU also matches variation SKUs; category and tag match their names.", 'peptide-core' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Require sign-in to visit the store', 'peptide-core' ), self::on_off( 'force_login', (bool) $c['force_login'], __( 'Yes', 'peptide-core' ), __( 'No', 'peptide-core' ) ), __( "When on, visitors must register or sign in before they can see any page of the store (the account page, privacy policy and checkout terms pages stay open).\nNew accounts are disabled until you approve them under Users. Suitable for members-only or private stores; not recommended for stores that run traffic campaigns, because it lowers conversion.", 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$sort = '<ul class="pc-bc-sort">';
		foreach ( $order as $key ) {
			$sort .= '<li><span class="dashicons dashicons-menu" aria-hidden="true"></span><label><input type="checkbox" name="sorts[]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, $enabled, true ), true, false ) . '> ' . esc_html( $labels[ $key ] ) . '</label></li>';
		}
		$sort .= '</ul>';
		echo self::field( __( 'Storefront sort options', 'peptide-core' ), $sort, __( "Controls the sort options customers can choose on product lists (shop, category pages, search results).\nTicked options appear in the sort menu; drag to change the order. The first one is the default sort, so put “Recommended” first to keep your own product order.", 'peptide-core' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Allow the store in an iframe', 'peptide-core' ), self::on_off( 'allow_iframe', (bool) $c['allow_iframe'] ), __( 'When on, other websites may load your store inside an inline frame (iframe). When off, they cannot, which protects against clickjacking.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$links = array(
			'product'  => home_url( '/product/sample-product/' ),
			'shop'     => home_url( Store_Config::product_link_bases()['shop'] . '/sample-product/' ),
			'shop_cat' => home_url( str_replace( '%product_cat%', 'category', Store_Config::product_link_bases()['shop_cat'] ) . '/sample-product/' ),
		);
		$cur_link = Store_Config::current_product_link();
		if ( 'custom' === $cur_link ) {
			$links = array( 'custom' => __( 'Custom (set in Settings → Permalinks)', 'peptide-core' ) ) + $links;
		}
		echo self::field( __( 'Product link structure', 'peptide-core' ), self::select( 'product_link', $links, $cur_link ), __( "Controls the levels in product page addresses, e.g. shortening /shop/category/name to /product/name.\nChanging it changes the address of every product page and old links stop working, so decide before you promote the store.", 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$offline = array(
			'404'         => __( 'Show the 404 page', 'peptide-core' ),
			'visible'     => __( 'Visible but not purchasable', 'peptide-core' ),
			'visible_buy' => __( 'Visible and purchasable', 'peptide-core' ),
			'home'        => __( 'Go to the home page', 'peptide-core' ),
			'shop'        => __( 'Go to the shop page', 'peptide-core' ),
		);
		echo self::field( __( 'Product page of an unpublished product', 'peptide-core' ), self::select( 'offline_product', $offline, (string) $c['offline_product'] ), __( "For products set to draft (unpublished):\n404 page: customers cannot see the product.\nVisible but not purchasable: the product page shows, sales are paused.\nVisible and purchasable: the product page shows and it can be bought while in stock; for products sold only through a direct link.\nNote: this only affects the product page (which is kept out of search engines); product lists always hide unpublished products. Private products and products in the trash always show the 404 page.", 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div><div>';
		echo self::field( __( 'Storefront security check', 'peptide-core' ), self::on_off( 'front_captcha', (bool) $c['front_captcha'] ), __( 'Adds a simple math question to the login and registration forms on the account page, to stop programs from signing up or guessing passwords automatically.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$verify_note = $reg_on ? '' : esc_html__( 'Registration on the account page is currently turned off (WooCommerce settings → Accounts & Privacy).', 'peptide-core' );
		if ( ! Store_Config::verification_available() ) {
			$verify_note .= ( $verify_note ? '<br>' : '' ) . '<span class="pc-bc-warn">' . esc_html__( 'The “Registration email verification” email is turned off, so verification is skipped.', 'peptide-core' ) . ' <a href="' . esc_url( Email_Center::url( 'customer' ) ) . '">' . esc_html__( 'Email notifications', 'peptide-core' ) . '</a></span>';
		}
		echo self::field( __( 'Register email verification', 'peptide-core' ), self::on_off( 'register_verify', (bool) $c['register_verify'] ), __( "1. When on, new customers who register must confirm their email through a link before they can sign in, which keeps accounts genuine. Accounts created during checkout are not affected.\n2. The “Registration email verification” email must be on in Email notifications.", 'peptide-core' ), false, $verify_note ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Store status', 'peptide-core' ), self::radios( 'store_open', array( '1' => __( 'Open', 'peptide-core' ), '0' => __( 'Closed', 'peptide-core' ) ), $open ? '1' : '0' ), __( "1. Set the store to Closed before you officially open, or when you need to close the storefront for a while; set it back to Open to resume.\n2. While Closed, visitors see a “coming soon” page. You still see the storefront when signed in as admin; to see what visitors see, use another browser or a private window.", 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$variants = array(
			'first'   => __( 'First available variation', 'peptide-core' ),
			'lowest'  => __( 'Lowest price', 'peptide-core' ),
			'highest' => __( 'Highest price', 'peptide-core' ),
			'stock'   => __( 'Most stock', 'peptide-core' ),
			'none'    => __( 'None selected', 'peptide-core' ),
		);
		echo self::field( __( 'Default variation on product pages', 'peptide-core' ), self::select( 'default_variant', $variants, (string) $c['default_variant'] ), __( "1. Select a variation by default:\n• First available: the first variation that can be bought, for a quick decision.\n• Lowest price: the cheapest available variation, for price-sensitive buyers.\n• Highest price: the most expensive available variation.\n• Most stock: the available variation with the most stock, to lower the risk of running out.\n2. None selected: customers must choose themselves; when they try to buy without choosing, they are asked to select one.\n3. Used on product pages when the product has no default variation of its own.", 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( __( 'Sold-out variations on product pages', 'peptide-core' ), self::radios( 'soldout_variants', array( 'show' => __( 'Show', 'peptide-core' ), 'hide' => __( 'Hide', 'peptide-core' ) ), (string) $c['soldout_variants'] ), __( 'Choose whether sold-out variations are shown. Example: every size of “yellow” is in stock, but “red” in XL is sold out. With Hide, a customer who switches to red cannot select XL.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></div></section>';
	}

	private static function section_contact(): void {
		$c         = Store_Config::contact();
		$countries = array( '' => __( 'Select a country', 'peptide-core' ) ) + WC()->countries->get_countries();
		$states    = $c['country'] ? (array) WC()->countries->get_states( $c['country'] ) : array();

		echo '<section class="pc-ss-panel"><h2>' . esc_html__( 'Public contact information', 'peptide-core' ) . self::help( __( "Optional. When filled in it helps buyers contact you, is output as JSON-LD structured data (Organization) on the home page, and can be used in terms pages through [pc_var].\nCountry, state, city, address and ZIP are the WooCommerce store address. US law requires a physical address in marketing emails, so cart reminders are only sent after the address is filled in.", 'peptide-core' ) ) . '</h2><div class="pc-bc-grid"><div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

	/** Shopname 有、本站不适用（或要别的部分配合）的选项，写明原因，免得以为漏做了。 */
	private static function section_not_applicable(): void {
		$items = array(
			__( 'Temporary domain access and its password', 'peptide-core' )          => __( 'Shopname’s free temporary domain. This store runs on its own domain; use “Store status → Closed” to hide the store before opening.', 'peptide-core' ),
			__( 'Home and collection page cache time', 'peptide-core' )               => __( 'There is no page cache layer in this WordPress setup yet. When the store goes live, caching is set up at the hosting or with a cache plugin.', 'peptide-core' ),
			__( 'Ad pixel delay compensation', 'peptide-core' )                       => __( 'Ad pixels (Facebook, TikTok, Snapchat, Pinterest) are not connected yet; this belongs to the “Ad pixels” card.', 'peptide-core' ),
			__( 'Code mode', 'peptide-core' )                                         => __( 'A Shopname setting for how its page code is output for Google Ads. It does not exist in WordPress.', 'peptide-core' ),
			__( 'Static resource path', 'peptide-core' )                              => __( 'Images are served by the WordPress media library from this store’s own domain.', 'peptide-core' ),
			__( 'Product interest statistics', 'peptide-core' )                       => __( 'Needs visitor tracking, which is still a test feature on this site.', 'peptide-core' ),
			__( 'Product page: deleted variation combinations', 'peptide-core' )      => __( 'The variation picker on product pages is part of the theme; this needs the theme (Codex) to support it.', 'peptide-core' ),
			__( 'Order currency settlement mode', 'peptide-core' )                    => __( 'The store uses one currency.', 'peptide-core' ),
		);
		echo '<section class="pc-ss-panel pc-bc-na"><h2>' . esc_html__( 'Shopname options not used on this site', 'peptide-core' ) . '</h2><table><tbody>';
		foreach ( $items as $name => $why ) {
			echo '<tr><th scope="row">' . esc_html( $name ) . '</th><td>' . esc_html( $why ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
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
				'bot_known'        => array_values( array_intersect( array_map( 'strval', (array) ( $in['bot_known'] ?? array() ) ), Store_Config::KNOWN_BOTS ) ),
				'bot_custom'       => array_slice( array_values( array_unique( $bots ) ), 0, 100 ),
				'admin_captcha'    => ! empty( $in['admin_captcha'] ),
				'front_captcha'    => ! empty( $in['front_captcha'] ),
				'search_mode'      => $pick( 'search_mode', Store_Config::SEARCH_MODES, 'smart' ),
				'search_fields'    => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['search_fields'] ?? array() ) ), array( 'sku', 'category', 'tag' ) ) ),
				'force_login'      => ! empty( $in['force_login'] ),
				'register_verify'  => ! empty( $in['register_verify'] ),
				'sorts'            => $sorts,
				'allow_iframe'     => ! empty( $in['allow_iframe'] ),
				'offline_product'  => $pick( 'offline_product', Store_Config::OFFLINE_MODES, '404' ),
				'default_variant'  => $pick( 'default_variant', Store_Config::DEFAULT_VARIANTS, 'first' ),
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
