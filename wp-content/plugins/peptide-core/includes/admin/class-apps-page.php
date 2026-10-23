<?php
/**
 * 应用中心（APP-CENTER-01），对照 Shopname 的“应用中心”：列出本站的应用，每个应用有自己的设置页。
 * 第一个应用：离开意图增收（PeptideCore\Exit_Intent）。设置页地址 admin.php?page=pc-apps&app=exit-intent。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Coupons;
use PeptideCore\Exit_Intent;

defined( 'ABSPATH' ) || exit;

final class Apps_Page {

	public const SLUG = 'pc-apps';
	public const CAP  = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_post_pc_app_exit_save', array( self::class, 'save_exit' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/** @return array<string,array{name:string,text:string,icon:string,on:bool}> */
	public static function apps(): array {
		return array(
			'exit-intent' => array(
				'name' => __( 'Exit-intent offers', 'peptide-core' ),
				'text' => __( 'When a visitor is about to leave, win them back with a coupon, a prize wheel or your own message.', 'peptide-core' ),
				'icon' => 'dashicons-undo',
				'on'   => (bool) Exit_Intent::settings()['enabled'],
			),
		);
	}

	public static function enqueue(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		Marketing::enqueue_assets();
		if ( 'exit-intent' === ( $_GET['app'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_media();
			Exit_Intent::enqueue_assets();
			$ver = PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/exit-intent-admin.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_enqueue_script( 'peptide-core-exit-admin', plugins_url( 'assets/exit-intent-admin.js', PEPTIDE_CORE_FILE ), array( 'jquery', 'peptide-core-exit-intent', 'peptide-core-marketing' ), $ver, true );
			wp_localize_script(
				'peptide-core-exit-admin',
				'pcExitAdmin',
				array(
					'maxPrizes' => Exit_Intent::MAX_PRIZES,
					'strings'   => array(
						'chooseImage' => __( 'Choose an image', 'peptide-core' ),
						'useImage'    => __( 'Use this image', 'peptide-core' ),
						'noPrize'     => __( 'No prize', 'peptide-core' ),
						'remove'      => __( 'Delete', 'peptide-core' ),
						'close'       => __( 'Close', 'peptide-core' ),
						'copy'        => __( 'Copy code', 'peptide-core' ),
						'copied'      => __( 'Copied', 'peptide-core' ),
						'shop'        => __( 'Continue shopping', 'peptide-core' ),
						'spinning'    => __( 'Spinning…', 'peptide-core' ),
					),
				)
			);
		}
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( 'exit-intent' === ( $_GET['app'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::render_exit();
			return;
		}
		echo '<div class="wrap pc-pl pc-mk pc-apps">';
		Marketing::notices();
		echo '<div class="pc-pl-head pc-mk-head"><h1>' . esc_html__( 'App center', 'peptide-core' ) . '</h1></div>';
		echo '<div class="pc-apps__grid">';
		foreach ( self::apps() as $key => $app ) {
			echo '<a class="pc-apps__card" href="' . esc_url( self::url( array( 'app' => $key ) ) ) . '"><span class="pc-apps__icon dashicons ' . esc_attr( $app['icon'] ) . '" aria-hidden="true"></span><span class="pc-apps__main"><strong>' . esc_html( $app['name'] ) . '</strong> <span class="pc-mk-badge ' . ( $app['on'] ? 'is-active' : 'is-off' ) . '">' . esc_html( $app['on'] ? __( 'On', 'peptide-core' ) : __( 'Off', 'peptide-core' ) ) . '</span><small>' . esc_html( $app['text'] ) . '</small></span><span class="button">' . esc_html__( 'Settings', 'peptide-core' ) . '</span></a>';
		}
		echo '</div></div>';
	}

	// ---------- 离开意图增收 ----------

	/** @return array<string,string> 可以选的优惠码：code => 显示文字（不能用的标出来）。 */
	private static function coupon_choices(): array {
		$out = array();
		foreach ( get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'posts_per_page' => 300, 'orderby' => 'date', 'order' => 'DESC' ) ) as $p ) {
			$c    = new \WC_Coupon( $p->ID );
			$code = wc_format_coupon_code( $c->get_code() );
			$name = \PeptideCore\Coupon_Rules::display_code( $c ) . ( '' !== $c->get_description() ? ' – ' . $c->get_description() : '' );
			if ( '' !== Coupons::unusable_reason( $c ) ) {
				$name .= ' (' . __( 'not usable now', 'peptide-core' ) . ')';
			}
			$out[ $code ] = $name;
		}
		return $out;
	}

	private static function coupon_select( string $name, string $value, array $choices, string $empty ): string {
		$html = '<select name="' . esc_attr( $name ) . '" class="pc-mk-select"><option value="">' . esc_html( $empty ) . '</option>';
		foreach ( $choices as $code => $label ) {
			$html .= '<option value="' . esc_attr( $code ) . '"' . selected( $code, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	private static function render_exit(): void {
		$s       = Exit_Intent::settings(); // 有错误时设置也已保存（只是不开启），所以这里显示的就是刚才填写的内容。
		$choices = self::coupon_choices();
		$t       = Exit_Intent::totals( 30 );
		$shows   = $t['show']['count'];
		$claims  = $t['claim']['count'] + $t['spin']['count'];
		$prizes  = $s['prizes'] ? $s['prizes'] : array( array( 'label' => '', 'coupon' => '', 'weight' => 10 ), array( 'label' => '', 'coupon' => '', 'weight' => 10 ) );
		$image   = $s['image'] ? (string) wp_get_attachment_image_url( (int) $s['image'], 'medium' ) : '';

		echo '<div class="wrap pc-pl pc-mk pc-mk-edit pc-exit-admin">';
		Marketing::notices();
		Marketing::edit_head( self::url(), __( 'Exit-intent offers', 'peptide-core' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-mk-form" data-kind="exit">';
		echo '<input type="hidden" name="action" value="pc_app_exit_save">';
		wp_nonce_field( 'pc_app_exit_save' );
		echo '<div class="pc-exit-admin__grid"><div class="pc-exit-admin__main">';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Marketing 的小部件已转义。
		Marketing::card_open( __( 'Last 30 days', 'peptide-core' ), 'pc-exit-admin__stats' );
		$tiles = array(
			array( __( 'Shown', 'peptide-core' ), number_format_i18n( $shows ) ),
			array( __( 'Claimed / spun', 'peptide-core' ), number_format_i18n( $claims ) ),
			array( __( 'Claim rate', 'peptide-core' ), $shows ? number_format_i18n( 100 * $claims / $shows, 1 ) . '%' : '—' ),
			array( __( 'Prizes won', 'peptide-core' ), number_format_i18n( $t['win']['count'] ) ),
			array( __( 'Closed', 'peptide-core' ), number_format_i18n( $t['close']['count'] ) ),
			array( __( 'Orders', 'peptide-core' ), number_format_i18n( $t['order']['count'] ) ),
			array( __( 'Order amount', 'peptide-core' ), wp_strip_all_tags( wc_price( $t['order']['amount'] ) ) ),
		);
		echo '<div class="pc-exit-admin__tiles">';
		foreach ( $tiles as [ $label, $value ] ) {
			echo '<div class="pc-exit-admin__tile"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
		}
		echo '</div><p class="pc-bc-note">' . esc_html__( 'Orders count when the order uses the code that this popup gave to the customer. Staff visits and previews are not counted.', 'peptide-core' ) . '</p>';
		Marketing::card_close();

		Marketing::card_open( __( 'Basic settings', 'peptide-core' ) );
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Status', 'peptide-core' ) ) . '<div class="pc-mk-radios">' . Marketing::radio( 'enabled', '1', $s['enabled'] ? '1' : '0', __( 'On', 'peptide-core' ) ) . Marketing::radio( 'enabled', '0', $s['enabled'] ? '1' : '0', __( 'Off', 'peptide-core' ) ) . '</div></div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Popup type', 'peptide-core' ), false, __( "Coupon: show a coupon code; one click applies it to the cart.\nPrize wheel: the visitor spins once per visit; the chances you set are the real chances, drawn on the server.\nCustom message: your own title, text and button link, e.g. to a collection or the offers page.", 'peptide-core' ) ) . '<div class="pc-mk-radios">';
		foreach ( array( 'coupon' => __( 'Coupon', 'peptide-core' ), 'wheel' => __( 'Prize wheel', 'peptide-core' ), 'message' => __( 'Custom message', 'peptide-core' ) ) as $k => $text ) {
			echo Marketing::radio( 'type', $k, (string) $s['type'], $text );
		}
		echo '</div></div>';
		Marketing::card_close();

		Marketing::card_open( __( 'Popup content', 'peptide-core' ) );
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Title', 'peptide-core' ), true, '', 'pc-exit-title' ) . Marketing::counted( 'title', (string) $s['title'], 80, '', 'pc-exit-title', true ) . '</div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Text', 'peptide-core' ), false, '', 'pc-exit-text' ) . '<textarea name="text" id="pc-exit-text" rows="2" class="large-text" maxlength="300">' . esc_textarea( (string) $s['text'] ) . '</textarea></div>';
		echo '<div class="pc-mk-field" data-show="type=coupon|message">' . Marketing::label( __( 'Image', 'peptide-core' ) ) . '<div class="pc-exit-admin__image"><input type="hidden" name="image" value="' . (int) $s['image'] . '"><span class="pc-exit-admin__thumb" data-url="' . esc_attr( $image ) . '">' . ( '' !== $image ? '<img src="' . esc_url( $image ) . '" alt="">' : '' ) . '</span> <button type="button" class="button pc-exit-image-pick">' . esc_html__( 'Choose image', 'peptide-core' ) . '</button> <button type="button" class="button-link pc-exit-image-clear">' . esc_html__( 'Remove', 'peptide-core' ) . '</button></div></div>';
		echo '<div class="pc-mk-field" data-show="type=coupon">' . Marketing::label( __( 'Coupon code', 'peptide-core' ), true ) . '<div class="pc-mk-inline">' . self::coupon_select( 'coupon', (string) $s['coupon'], $choices, __( '— Choose a coupon code —', 'peptide-core' ) ) . ' <a href="' . esc_url( Coupons_Page::url( array( 'new' => 1 ) ) ) . '">' . esc_html__( 'New coupon code', 'peptide-core' ) . '</a></div><p class="pc-bc-note">' . esc_html__( 'The rules of the code still apply (minimum spend, first order only, combining with promotions…).', 'peptide-core' ) . '</p></div>';
		echo '<div class="pc-mk-field pc-mk-narrow" data-show="type=coupon|message">' . Marketing::label( __( 'Button text', 'peptide-core' ), false, '', 'pc-exit-button' ) . Marketing::counted( 'button', (string) $s['button'], 40, '', 'pc-exit-button' ) . '</div>';
		echo '<div class="pc-mk-field pc-mk-narrow" data-show="type=message">' . Marketing::label( __( 'Button link', 'peptide-core' ), false, '', 'pc-exit-link' ) . '<input type="url" name="link" id="pc-exit-link" class="large-text" value="' . esc_attr( (string) $s['link'] ) . '" placeholder="https://"></div>';
		echo '<div class="pc-mk-field pc-mk-narrow">' . Marketing::label( __( 'Decline link text', 'peptide-core' ), false, __( 'Leave empty to show only the close button.', 'peptide-core' ), 'pc-exit-decline' ) . Marketing::counted( 'decline', (string) $s['decline'], 40, '', 'pc-exit-decline' ) . '</div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Main color', 'peptide-core' ), false, '', 'pc-exit-color' ) . '<input type="color" name="color" id="pc-exit-color" value="' . esc_attr( (string) $s['color'] ) . '"></div>';
		Marketing::card_close();

		Marketing::card_open( __( 'Prizes', 'peptide-core' ), 'pc-exit-admin__prizes', __( "Up to 8 prizes. The chance of each prize is its weight divided by the total weight.\nA prize without a coupon code is a “no prize” slot.\nEach shopping visit can spin once; the result is decided on the server, so it cannot be changed in the browser.", 'peptide-core' ) );
		echo '<div data-show="type=wheel"><table class="pc-pl-table pc-exit-admin__table"><thead><tr><th>' . esc_html__( 'Prize name', 'peptide-core' ) . '</th><th>' . esc_html__( 'Coupon code', 'peptide-core' ) . '</th><th>' . esc_html__( 'Weight', 'peptide-core' ) . '</th><th>' . esc_html__( 'Chance', 'peptide-core' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $prizes as $i => $p ) {
			echo '<tr class="pc-exit-prize"><td><input type="text" name="prizes[' . (int) $i . '][label]" maxlength="24" value="' . esc_attr( (string) $p['label'] ) . '" placeholder="' . esc_attr__( 'e.g. 10% OFF', 'peptide-core' ) . '"></td><td>' . self::coupon_select( 'prizes[' . (int) $i . '][coupon]', (string) $p['coupon'], $choices, __( 'No prize', 'peptide-core' ) ) . '</td><td><input type="number" min="0" max="1000" step="1" class="small-text" name="prizes[' . (int) $i . '][weight]" value="' . (int) $p['weight'] . '"></td><td class="pc-exit-chance">—</td><td><button type="button" class="button-link pc-exit-prize-del">' . esc_html__( 'Delete', 'peptide-core' ) . '</button></td></tr>';
		}
		echo '</tbody></table><p><button type="button" class="button pc-mk-tier-add pc-exit-prize-add">+ ' . esc_html__( 'Add prize', 'peptide-core' ) . '</button></p>';
		echo '<div class="pc-mk-field pc-mk-narrow">' . Marketing::label( __( 'Spin button text', 'peptide-core' ), false, '', 'pc-exit-spin' ) . Marketing::counted( 'spin_button', (string) $s['spin_button'], 40, '', 'pc-exit-spin' ) . '</div>';
		echo '<div class="pc-mk-field pc-mk-narrow">' . Marketing::label( __( 'Title when a prize is won', 'peptide-core' ), false, __( '{prize} is replaced by the prize name, {code} by the coupon code.', 'peptide-core' ), 'pc-exit-win-title' ) . Marketing::counted( 'win_title', (string) $s['win_title'], 80, '', 'pc-exit-win-title' ) . '</div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Text when a prize is won', 'peptide-core' ), false, '', 'pc-exit-win-text' ) . '<textarea name="win_text" id="pc-exit-win-text" rows="2" class="large-text" maxlength="300">' . esc_textarea( (string) $s['win_text'] ) . '</textarea></div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Text for a “no prize” slot', 'peptide-core' ), false, '', 'pc-exit-lose' ) . '<textarea name="lose_text" id="pc-exit-lose" rows="2" class="large-text" maxlength="300">' . esc_textarea( (string) $s['lose_text'] ) . '</textarea></div></div>';
		echo '<p class="pc-pl-muted" data-show="type=coupon|message">' . esc_html__( 'Only used by the prize wheel.', 'peptide-core' ) . '</p>';
		Marketing::card_close();

		Marketing::card_open( __( 'When to show', 'peptide-core' ) );
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Computer', 'peptide-core' ) ) . '<label><input type="checkbox" name="desktop_exit" value="1"' . checked( $s['desktop_exit'], true, false ) . '> ' . esc_html__( 'When the mouse leaves the top of the page (towards the address bar, tabs or close button)', 'peptide-core' ) . '</label></div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Phone', 'peptide-core' ), false, __( 'Phones have no mouse, so leaving cannot be seen directly. A quick scroll back up after reading usually means the visitor is going to the address bar or back.', 'peptide-core' ) ) . '<div class="pc-mk-radios">' . Marketing::radio( 'mobile', 'scroll', (string) $s['mobile'], __( 'Quick scroll back up', 'peptide-core' ) ) . Marketing::radio( 'mobile', 'idle', (string) $s['mobile'], __( 'No activity for', 'peptide-core' ) ) . '<span class="pc-mk-inline"><input type="number" min="5" max="600" class="small-text" name="idle_seconds" value="' . (int) $s['idle_seconds'] . '"> ' . esc_html__( 'seconds', 'peptide-core' ) . '</span>' . Marketing::radio( 'mobile', 'off', (string) $s['mobile'], __( 'Do not show on phones', 'peptide-core' ) ) . '</div></div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Wait at least', 'peptide-core' ) ) . '<span class="pc-mk-inline"><input type="number" min="0" max="300" class="small-text" name="min_seconds" value="' . (int) $s['min_seconds'] . '"> ' . esc_html__( 'seconds on the page before the popup can show', 'peptide-core' ) . '</span></div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Pages', 'peptide-core' ) ) . '<div class="pc-mk-radios">';
		foreach ( array( 'home' => __( 'Home page', 'peptide-core' ), 'shop' => __( 'Product lists and search', 'peptide-core' ), 'product' => __( 'Product pages', 'peptide-core' ), 'cart' => __( 'Cart', 'peptide-core' ), 'checkout' => __( 'Checkout', 'peptide-core' ), 'other' => __( 'Other pages', 'peptide-core' ) ) as $k => $text ) {
			echo '<label><input type="checkbox" name="pages[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, (array) $s['pages'], true ), true, false ) . '> ' . esc_html( $text ) . '</label>';
		}
		echo '</div></div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Visitors', 'peptide-core' ) ) . '<div class="pc-mk-radios">' . Marketing::radio( 'audience', 'all', (string) $s['audience'], __( 'Everyone', 'peptide-core' ) ) . Marketing::radio( 'audience', 'cart', (string) $s['audience'], __( 'Only with items in the cart', 'peptide-core' ) ) . Marketing::radio( 'audience', 'new', (string) $s['audience'], __( 'Only visitors without orders', 'peptide-core' ) ) . '</div></div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'How often', 'peptide-core' ), false, __( 'Remembered in the visitor’s browser. 0 = at most once each time the browser is opened.', 'peptide-core' ) ) . '<span class="pc-mk-inline">' . esc_html__( 'At most once every', 'peptide-core' ) . ' <input type="number" min="0" max="365" class="small-text" name="frequency_days" value="' . (int) $s['frequency_days'] . '"> ' . esc_html__( 'days per visitor', 'peptide-core' ) . '</span></div>';
		Marketing::card_close();
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</div><aside class="pc-exit-admin__side"><div class="pc-mk-card pc-mk-seo"><div class="pc-mk-seo__head"><strong>' . esc_html__( 'Preview', 'peptide-core' ) . '</strong><span><a href="' . esc_url( add_query_arg( 'pc_exit_preview', '1', home_url( '/' ) ) ) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-visibility" aria-hidden="true"></span>' . esc_html__( 'Preview in the store', 'peptide-core' ) . '</a></span></div>';
		echo '<div class="pc-exit-admin__stage"></div><p class="pc-bc-note">' . esc_html__( 'The preview in the store uses the saved settings and shows the popup right away. Staff never see the popup otherwise, and previews are not counted.', 'peptide-core' ) . '</p></div></aside></div>';
		Marketing::save_bar( self::url() );
		echo '</form></div>';
	}

	public static function save_exit(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( 'pc_app_exit_save' );
		$raw    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 在 Exit_Intent::clean() 里逐项清理。
		$raw['prizes'] = is_array( $raw['prizes'] ?? null ) ? array_values( $raw['prizes'] ) : array();
		$errors = Exit_Intent::save( $raw );
		if ( $errors ) {
			$want_on = ! empty( $raw['enabled'] );
			Marketing::flash_errors( array_merge( $errors, $want_on ? array( __( 'The settings are saved, but the popup stays off until this is fixed.', 'peptide-core' ) ) : array() ) );
			wp_safe_redirect( self::url( array( 'app' => 'exit-intent' ) ) );
			exit;
		}
		wp_safe_redirect( self::url( array( 'app' => 'exit-intent', 'pc_saved' => 1 ) ) );
		exit;
	}
}
