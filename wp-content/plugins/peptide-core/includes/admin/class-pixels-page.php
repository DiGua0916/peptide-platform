<?php
/**
 * 系统设置 → 广告像素（PIXELS-01），版式参照 Shopname：每个平台一张卡片（如何获取、支持的事件、选项、像素表格或 ID 输入框），
 * 右边是备注说明，顶部“统计设置”是全局选项（GPC、员工、加载时机、高级匹配、Facebook 测试事件代码），底部是服务端回传记录。
 * 逻辑在 PeptideCore\Pixels 和 PeptideCore\Pixels\Server。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Pixels;
use PeptideCore\Pixels\Server;

defined( 'ABSPATH' ) || exit;

final class Pixels_Page {

	public const SECTION = 'pixels';

	/** 不显示“销售额使用商品金额”的平台（和 Shopname 一样；BIGO 的浏览器像素不带金额）。 */
	private const NO_ITEMS_VALUE = array( 'gads', 'gtm', 'vk', 'yandex', 'criteo', 'twitter', 'bigo' );

	public static function register_hooks(): void {
		add_action( 'admin_post_pc_pixels_save', array( self::class, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => Store_Settings::SYSTEM_SLUG, 'section' => self::SECTION ), $args ), admin_url( 'admin.php' ) );
	}

	public static function enqueue(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( Store_Settings::SYSTEM_SLUG !== ( $_GET['page'] ?? '' ) || self::SECTION !== ( $_GET['section'] ?? '' ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		Marketing::enqueue_assets();
		$ver = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-pixels-admin', plugins_url( 'assets/pixels-admin.css', PEPTIDE_CORE_FILE ), array( 'peptide-core-marketing' ), $ver( 'pixels-admin.css' ) );
		wp_enqueue_script( 'peptide-core-pixels-admin', plugins_url( 'assets/pixels-admin.js', PEPTIDE_CORE_FILE ), array(), $ver( 'pixels-admin.js' ), true );
		wp_localize_script(
			'peptide-core-pixels-admin',
			'pcPixelsAdmin',
			array(
				'strings' => array(
					'needId'     => __( 'Enter the pixel ID.', 'peptide-core' ),
					'duplicate'  => __( 'This pixel ID is already in the list.', 'peptide-core' ),
					'confirmDel' => __( 'Remove this pixel? It takes effect after you save.', 'peptide-core' ),
					'kept'       => __( 'saved (unchanged)', 'peptide-core' ),
					'none'       => '—',
					'edit'       => __( 'Edit', 'peptide-core' ),
					'remove'     => __( 'Delete', 'peptide-core' ),
				),
			)
		);
	}

	/** token 只显示前 6 位。 */
	public static function mask( string $token ): string {
		return '' === $token ? '' : substr( $token, 0, 6 ) . '…' . str_repeat( '•', 6 );
	}

	/** “PageView（浏览页面）, ViewContent（查看商品）…” */
	private static function events_text( array $def ): string {
		$labels = Pixels::event_labels();
		$parts  = array();
		foreach ( $def['events'] as $ev => $name ) {
			$parts[] = $name . '（' . ( $labels[ $ev ] ?? $ev ) . '）';
		}
		return implode( ', ', $parts );
	}

	private static function pixel_row( string $key, int $i, array $px, bool $token ): string {
		$base = 'platforms[' . $key . '][pixels][' . $i . ']';
		$html = '<tr class="pc-px-row"><td class="pc-mk-col-act"><button type="button" class="pc-mk-icon button-link pc-px-edit" title="' . esc_attr__( 'Edit', 'peptide-core' ) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Edit', 'peptide-core' ) . '</span></button><button type="button" class="pc-mk-icon button-link pc-px-del" title="' . esc_attr__( 'Delete', 'peptide-core' ) . '"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Delete', 'peptide-core' ) . '</span></button>'
			. '<input type="hidden" data-f="id" name="' . esc_attr( $base ) . '[id]" value="' . esc_attr( $px['id'] ) . '"><input type="hidden" data-f="note" name="' . esc_attr( $base ) . '[note]" value="' . esc_attr( $px['note'] ) . '"><input type="hidden" data-f="domain" name="' . esc_attr( $base ) . '[domain]" value="' . esc_attr( $px['domain'] ) . '">';
		if ( $token ) {
			$html .= '<input type="hidden" data-f="token" name="' . esc_attr( $base ) . '[token]" value=""><input type="hidden" data-f="clear_token" name="' . esc_attr( $base ) . '[clear_token]" value="">';
		}
		$html .= '</td><td data-show-f="id">' . esc_html( $px['id'] ) . '</td><td data-show-f="note">' . esc_html( '' !== $px['note'] ? $px['note'] : '—' ) . '</td><td data-show-f="domain">' . esc_html( '' !== $px['domain'] ? $px['domain'] : __( 'All domains', 'peptide-core' ) ) . '</td>';
		if ( $token ) {
			$html .= '<td data-show-f="token" data-had="' . ( '' !== $px['token'] ? '1' : '' ) . '"><code>' . esc_html( '' !== $px['token'] ? self::mask( $px['token'] ) : '—' ) . '</code></td>';
		}
		return $html . '</tr>';
	}

	private static function card( string $key, array $def, array $p ): void {
		$token = ! empty( $def['token'] );
		echo '<section class="pc-px-card" id="pc-px-' . esc_attr( $key ) . '" data-platform="' . esc_attr( $key ) . '"><div class="pc-px-card__head"><h2>' . esc_html( $def['label'] ) . '</h2> <a href="' . esc_url( $def['help'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'How to get it?', 'peptide-core' ) . '</a>';
		if ( 'pixels' === $def['kind'] ) {
			echo '<button type="button" class="button pc-px-add">' . esc_html__( 'New pixel', 'peptide-core' ) . '</button>';
		}
		echo '</div>';
		/* translators: %s: event names */
		echo '<p class="pc-px-desc">' . ( $def['events'] ? esc_html( sprintf( __( 'Supported events: %s', 'peptide-core' ), self::events_text( $def ) ) ) : esc_html__( 'The official code of this platform is still being checked, so it does not send events yet. You can already save the IDs.', 'peptide-core' ) ) . '</p>';
		if ( ! empty( $def['note'] ) ) {
			echo '<p class="pc-px-desc">' . esc_html( $def['note'] ) . '</p>';
		}

		$opts = '';
		if ( ! in_array( $key, self::NO_ITEMS_VALUE, true ) ) {
			$opts .= '<label class="pc-px-check"><input type="checkbox" name="platforms[' . esc_attr( $key ) . '][items_value]" value="1"' . checked( $p['items_value'], true, false ) . '> ' . esc_html__( 'Use the product amount as the sales value', 'peptide-core' ) . '</label>' . Marketing::help( __( 'On: the value of purchase events is the products’ amount (without shipping and tax). Off: the order total the customer paid.', 'peptide-core' ) );
		}
		if ( 'facebook' === $key ) {
			$opts .= '<div class="pc-px-fb"><div class="pc-px-fb__main">';
			$opts .= '<div class="pc-mk-field">' . Marketing::label( __( 'Content ID type', 'peptide-core' ), false, __( "The product ID sent as content_ids. It must match the IDs in your Meta catalog if you use one.\nSPU: one ID per product (the SPU code, or the product ID).\nSKU_CODE: the SKU of the chosen variation.", 'peptide-core' ) ) . '<div class="pc-mk-radios">' . Marketing::radio( 'platforms[facebook][content_id]', 'spu', (string) $p['content_id'], 'SPU' ) . Marketing::radio( 'platforms[facebook][content_id]', 'sku', (string) $p['content_id'], 'SKU_CODE' ) . '</div></div>';
			$opts .= '<div class="pc-mk-field">' . Marketing::label( __( 'Content name type', 'peptide-core' ), false, __( 'What is sent as content_name. This site has no separate internal name, so “Internal name” uses the product’s URL slug.', 'peptide-core' ) ) . '<div class="pc-mk-radios">';
			foreach ( array( 'name' => __( 'Product name', 'peptide-core' ), 'internal' => __( 'Internal name', 'peptide-core' ), 'category' => __( 'Product type', 'peptide-core' ), 'spu' => 'SPU' ) as $k => $t ) {
				$opts .= Marketing::radio( 'platforms[facebook][content_name]', $k, (string) $p['content_name'], $t );
			}
			$opts .= '</div></div>';
			$opts .= '<label class="pc-px-check"><input type="checkbox" name="platforms[facebook][custom_events]" value="1"' . checked( $p['custom_events'], true, false ) . '> ' . esc_html__( 'Upload custom events', 'peptide-core' ) . '</label>' . Marketing::help( __( 'Also sends ViewCategory, ViewCart, PlaceAnOrder and Login as custom events.', 'peptide-core' ) ) . '<br>';
			$opts .= '<label class="pc-px-check"><input type="checkbox" name="platforms[facebook][first_order_only]" value="1"' . checked( $p['first_order_only'], true, false ) . '> ' . esc_html__( 'Report purchases of first orders only', 'peptide-core' ) . '</label>' . Marketing::help( __( 'Only the first paid order of each customer (by account or email) is sent as Purchase.', 'peptide-core' ) );
			$opts .= '</div><div class="pc-px-fb__side">' . Marketing::label( __( 'Online order report ratio', 'peptide-core' ), false, __( 'The share of online orders that are reported to Facebook as Purchase. Which orders are left out is fixed by the order number, so an order is never reported twice. 100% = all orders.', 'peptide-core' ), 'pc-px-ratio' ) . '<span class="pc-mk-addon"><input type="number" min="1" max="100" id="pc-px-ratio" name="platforms[facebook][ratio]" value="' . (int) $p['ratio'] . '"><span class="pc-mk-addon__unit">%</span></span></div></div>';
		}
		if ( 'gtm' === $key ) {
			$opts .= '<label class="pc-px-check"><input type="checkbox" name="platforms[gtm][user_data]" value="1"' . checked( $p['user_data'], true, false ) . '> ' . esc_html__( 'Send email and phone number', 'peptide-core' ) . '</label>' . Marketing::help( __( 'Adds the customer’s email and phone to the data layer as SHA-256 hashes (sha256_email_address, sha256_phone_number), for enhanced conversions.', 'peptide-core' ) );
		}
		if ( '' !== $opts ) {
			echo '<div class="pc-px-opts">' . $opts . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 上面每一段都已转义。
		}

		$name = 'platforms[' . $key . ']';
		if ( 'pixels' === $def['kind'] ) {
			echo '<div class="pc-mk-scroll"><table class="pc-pl-table pc-px-table"><thead><tr><th class="pc-mk-col-act">' . esc_html__( 'Actions', 'peptide-core' ) . '</th><th>' . esc_html__( 'Pixel ID', 'peptide-core' ) . '</th><th>' . esc_html__( 'Note', 'peptide-core' ) . ' ' . Marketing::help( __( 'For your own reference, e.g. which media buyer or campaign uses the pixel.', 'peptide-core' ) ) . '</th><th>' . esc_html__( 'Linked domain', 'peptide-core' ) . '</th>' . ( $token ? '<th>' . esc_html( 'tiktok' === $key ? 'Events API access token' : 'CAPI access token' ) . '</th>' : '' ) . '</tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $p['pixels'] as $i => $px ) {
				echo self::pixel_row( $key, (int) $i, $px, $token ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '<tr class="pc-px-empty"' . ( $p['pixels'] ? ' hidden' : '' ) . '><td colspan="5" class="pc-pl-muted">' . esc_html__( 'No pixels yet.', 'peptide-core' ) . '</td></tr></tbody></table></div>';
			echo '<template class="pc-px-tpl">' . self::pixel_row( $key, 999, array( 'id' => '', 'note' => '', 'domain' => '', 'token' => '' ), $token ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'ids' === $def['kind'] || 'gads' === $def['kind'] ) {
			$label = 'gads' === $key ? __( 'Google Ads tracking ID', 'peptide-core' ) : ( 'ga4' === $key ? __( 'Google Analytics tracking ID', 'peptide-core' ) : __( 'Pixel IDs', 'peptide-core' ) );
			/* translators: %s: example id */
			$ph = __( 'One pixel ID per line', 'peptide-core' ) . ( ! empty( $def['placeholder'] ) ? ', ' . sprintf( __( 'e.g. %s', 'peptide-core' ), $def['placeholder'] ) : '' );
			echo '<div class="pc-mk-field"><label class="pc-mk-label" for="pc-px-ids-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><textarea id="pc-px-ids-' . esc_attr( $key ) . '" class="large-text pc-px-ids" rows="2" name="' . esc_attr( $name ) . '[ids]" placeholder="' . esc_attr( $ph ) . '">' . esc_textarea( implode( "\n", array_column( $p['pixels'], 'id' ) ) ) . '</textarea></div>';
			if ( 'gads' === $key ) {
				echo '<div class="pc-mk-field"><label class="pc-mk-label" for="pc-px-gads-purchase">' . esc_html__( 'Purchase event tracking (purchase conversion ID)', 'peptide-core' ) . '</label><textarea id="pc-px-gads-purchase" class="large-text pc-px-ids" rows="2" name="platforms[gads][purchase]" placeholder="' . esc_attr__( 'One per line, e.g. AW-872163871231/F234HFDSKhdj', 'peptide-core' ) . '">' . esc_textarea( implode( "\n", $p['purchase'] ) ) . '</textarea></div>';
				echo '<details class="pc-px-more"' . ( $p['labels'] ? ' open' : '' ) . '><summary>' . esc_html__( 'Track more events', 'peptide-core' ) . '</summary><div class="pc-px-more__grid">';
				$labels = Pixels::event_labels();
				foreach ( array( 'page_view', 'view_item', 'search', 'add_to_cart', 'begin_checkout' ) as $ev ) {
					echo '<label><span>' . esc_html( $def['events'][ $ev ] . '（' . $labels[ $ev ] . '）' ) . '</span><input type="text" name="platforms[gads][labels][' . esc_attr( $ev ) . ']" value="' . esc_attr( (string) ( $p['labels'][ $ev ] ?? '' ) ) . '" placeholder="AW-872163871231/AbCdEf"></label>';
				}
				echo '</div></details>';
			}
			if ( 'twitter' === $key ) {
				echo '<details class="pc-px-more"' . ( $p['event_ids'] ? ' open' : '' ) . '><summary>' . esc_html__( 'Event IDs', 'peptide-core' ) . '</summary><div class="pc-px-more__grid">';
				$labels = Pixels::event_labels();
				foreach ( $def['events'] as $ev => $ev_name ) {
					echo '<label><span>' . esc_html( $ev_name . '（' . $labels[ $ev ] . '）' ) . '</span><input type="text" name="platforms[twitter][event_ids][' . esc_attr( $ev ) . ']" value="' . esc_attr( implode( ', ', (array) ( $p['event_ids'][ $ev ] ?? array() ) ) ) . '" placeholder="tw-o1abc-o2def"></label>';
				}
				echo '</div></details>';
			}
		} elseif ( 'yahoo' === $def['kind'] ) {
			$first = $p['pixels'] ? $p['pixels'][0]['id'] : '';
			echo '<div class="pc-px-pair"><label for="pc-px-yahoo-id">Yahoo Pixelid</label><input type="text" id="pc-px-yahoo-id" name="platforms[yahoo][ids]" value="' . esc_attr( $first ) . '"></div>';
			echo '<div class="pc-px-pair"><label for="pc-px-yahoo-project">Yahoo Projectid</label><input type="text" id="pc-px-yahoo-project" name="platforms[yahoo][project_id]" value="' . esc_attr( (string) $p['project_id'] ) . '"></div>';
		}
		echo '</section>';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s = Pixels::settings();
		echo '<div class="pc-pl pc-mk pc-px">';
		echo '<p class="pc-ss-back"><a href="' . esc_url( admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG ) ) . '">‹ ' . esc_html__( 'Back to system settings', 'peptide-core' ) . '</a></p>';
		Marketing::notices();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-px-form">';
		echo '<input type="hidden" name="action" value="pc_pixels_save">';
		wp_nonce_field( 'pc_pixels_save' );
		echo '<div class="pc-pl-head pc-mk-head"><h1>' . esc_html__( 'Ad pixels', 'peptide-core' ) . '</h1><button type="button" class="button-link pc-px-settings-open"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> ' . esc_html__( 'Tracking settings', 'peptide-core' ) . '</button></div>';
		if ( ! Pixels::any( $s ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No pixels yet, so nothing is sent. Add the IDs of the platforms you advertise on and save.', 'peptide-core' ) . '</p></div>';
		}
		echo '<div class="pc-px-grid"><div class="pc-px-main">';
		foreach ( Pixels::platforms() as $key => $def ) {
			self::card( $key, $def, $s['platforms'][ $key ] );
		}

		// 服务端回传记录。
		$log = Server::recent( 30 );
		echo '<section class="pc-px-card"><div class="pc-px-card__head"><h2>' . esc_html__( 'Server-side sends', 'peptide-core' ) . '</h2></div><p class="pc-px-desc">' . esc_html__( 'Events sent by the server to Facebook (Conversions API) and TikTok (Events API) for pixels with an access token. Kept for 7 days. Browser events can be checked in each platform’s own test tool (for example Meta Events Manager → Test events).', 'peptide-core' ) . '</p>';
		if ( ! $log ) {
			echo '<p class="pc-pl-muted">' . esc_html__( 'Nothing sent yet.', 'peptide-core' ) . '</p>';
		} else {
			echo '<div class="pc-mk-scroll"><table class="pc-pl-table pc-px-table"><thead><tr><th>' . esc_html__( 'Time', 'peptide-core' ) . '</th><th>' . esc_html__( 'Platform', 'peptide-core' ) . '</th><th>' . esc_html__( 'Pixel ID', 'peptide-core' ) . '</th><th>' . esc_html__( 'Event', 'peptide-core' ) . '</th><th>' . esc_html__( 'Result', 'peptide-core' ) . '</th></tr></thead><tbody>';
			foreach ( $log as $r ) {
				echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) ( $r['sent_ms'] / 1000 ) ) ) . '</td><td>' . esc_html( Pixels::platforms()[ $r['platform'] ]['label'] ?? $r['platform'] ) . '</td><td>' . esc_html( $r['pixel'] ) . '</td><td>' . esc_html( $r['event'] ) . '</td><td>' . ( (int) $r['ok'] ? '<span class="pc-mk-badge is-active">' . esc_html__( 'OK', 'peptide-core' ) . '</span>' : '<span class="pc-mk-badge is-off">' . esc_html( sprintf( 'HTTP %d', (int) $r['code'] ) ) . '</span> <small>' . esc_html( $r['message'] ) . '</small>' ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section>';

		echo '</div><aside class="pc-px-side"><div class="pc-px-card pc-px-notes"><h2>' . esc_html__( 'Notes', 'peptide-core' ) . '</h2><ol>';
		echo '<li>' . esc_html__( 'A pixel is a piece of JavaScript in the page. It sends what visitors do (views, add to cart, orders) to the ad platform’s servers so the platform can count the results of your ads.', 'peptide-core' ) . '</li>';
		echo '<li>' . esc_html__( 'With the pixel of an ad platform installed you can measure, analyse, retarget and build audiences on that platform. With an access token the server also sends the same events, which fills in what ad blockers and phone privacy settings remove; both carry the same event ID so the platform counts them once.', 'peptide-core' ) . '</li>';
		echo '<li>' . esc_html__( 'The numbers in the ad platform and on the site can differ somewhat; this is normal.', 'peptide-core' ) . '</li>';
		echo '<li>' . esc_html__( 'Visitors who send the browser “Global Privacy Control” signal, or open any page with ?pc_px_optout=1, are not reported. Mention the platforms you use in your privacy policy.', 'peptide-core' ) . '</li>';
		echo '</ol></div></aside></div>';

		// 统计设置（全局选项）。
		echo '<dialog class="pc-pl-dialog pc-px-settings"><h2>' . esc_html__( 'Tracking settings', 'peptide-core' ) . '</h2><div class="pc-px-settings__body">';
		$box = static fn( string $n, bool $on, string $text, string $tip = '' ): string => '<p><label><input type="checkbox" name="' . esc_attr( $n ) . '" value="1"' . checked( $on, true, false ) . '> ' . esc_html( $text ) . '</label>' . ( '' !== $tip ? ' ' . Marketing::help( $tip ) : '' ) . '</p>';
		echo $box( 'gpc', (bool) $s['gpc'], __( 'Respect the browser’s Global Privacy Control (GPC) signal', 'peptide-core' ), __( 'California and several other US states treat a GPC signal as a request not to sell or share the visitor’s data. On: no pixels and no server-side events for these visitors.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $box( 'match', (bool) $s['match'], __( 'Advanced matching: send the known email and phone as SHA-256 hashes', 'peptide-core' ), __( 'Helps the platforms recognise the customer. The values are hashed before they leave the site and cannot be read back.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $box( 'staff', (bool) $s['staff'], __( 'Also report staff visits (for testing)', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $box( 'server_pv', (bool) $s['server_pv'], __( 'Also send PageView from the server', 'peptide-core' ), __( 'Off by default: every page view would then also become a server request to the platform.', 'peptide-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="pc-mk-label">' . esc_html__( 'When the pixels load', 'peptide-core' ) . '</p><div class="pc-mk-radios">' . Marketing::radio( 'timing', 'load', (string) $s['timing'], __( 'When the page has loaded', 'peptide-core' ) ) . Marketing::radio( 'timing', 'interaction', (string) $s['timing'], __( 'At the first scroll or tap (faster pages, a few page views may be missed)', 'peptide-core' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p><label class="pc-mk-label" for="pc-px-test">' . esc_html__( 'Facebook test event code', 'peptide-core' ) . '</label> ' . Marketing::help( __( 'From Meta Events Manager → Test events. While it is filled in, server-side events show up there. Clear it when you are done testing.', 'peptide-core' ) ) . '<br><input type="text" id="pc-px-test" name="test_code" value="' . esc_attr( (string) $s['test_code'] ) . '" placeholder="TEST12345"></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div><div class="pc-pl-dialog__foot"><button type="button" class="button pc-pl-close">' . esc_html__( 'Cancel', 'peptide-core' ) . '</button> <button class="button button-primary">' . esc_html__( 'Save', 'peptide-core' ) . '</button></div></dialog>';

		// 新建 / 编辑像素。
		echo '<dialog class="pc-pl-dialog pc-px-dialog"><h2 class="pc-px-dialog__title">' . esc_html__( 'Pixel', 'peptide-core' ) . '</h2>';
		echo '<p><label class="pc-mk-label" for="pc-px-d-id"><span class="pc-bc-req">*</span> ' . esc_html__( 'Pixel ID', 'peptide-core' ) . '</label><input type="text" class="large-text" id="pc-px-d-id" data-d="id" maxlength="120"></p>';
		echo '<p><label class="pc-mk-label" for="pc-px-d-note">' . esc_html__( 'Note', 'peptide-core' ) . '</label><input type="text" class="large-text" id="pc-px-d-note" data-d="note" maxlength="100"></p>';
		echo '<p><label class="pc-mk-label" for="pc-px-d-domain">' . esc_html__( 'Linked domain', 'peptide-core' ) . '</label><input type="text" class="large-text" id="pc-px-d-domain" data-d="domain" placeholder="' . esc_attr__( 'Empty = every domain of the store', 'peptide-core' ) . '"></p>';
		echo '<div class="pc-px-dialog__token"><p><label class="pc-mk-label" for="pc-px-d-token">Access token</label> ' . Marketing::help( __( 'Facebook: Events Manager → the pixel → Settings → Conversions API → Generate access token. TikTok: Events Manager → the pixel → Settings → Events API → Generate access token. The token stays on the server and is never shown in the store.', 'peptide-core' ) ) . '<input type="password" class="large-text" id="pc-px-d-token" data-d="token" autocomplete="new-password"></p><p class="pc-bc-note pc-px-dialog__keep" hidden>' . esc_html__( 'A token is saved. Leave empty to keep it.', 'peptide-core' ) . ' <label><input type="checkbox" data-d="clear_token" value="1"> ' . esc_html__( 'Remove the saved token', 'peptide-core' ) . '</label></p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="pc-pl-error" hidden></p><div class="pc-pl-dialog__foot"><button type="button" class="button pc-pl-close">' . esc_html__( 'Cancel', 'peptide-core' ) . '</button> <button type="button" class="button button-primary pc-px-dialog__ok">' . esc_html__( 'OK', 'peptide-core' ) . '</button></div></dialog>';

		Marketing::save_bar( admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG ) );
		echo '</form></div>';
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( 'pc_pixels_save' );
		$errors = Pixels::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 在 Pixels::clean() 里逐项清理。
		if ( $errors ) {
			Marketing::flash_errors( array_merge( array( __( 'Saved, but please check:', 'peptide-core' ) ), $errors ) );
			wp_safe_redirect( self::url() );
			exit;
		}
		wp_safe_redirect( self::url( array( 'pc_saved' => 1 ) ) );
		exit;
	}
}
