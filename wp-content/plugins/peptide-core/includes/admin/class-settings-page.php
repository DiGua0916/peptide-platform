<?php
/**
 * 后台设置页（BE-05）：WooCommerce → VialCove 设置。仅管理员（pc_manage_settings）可用。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Settings;
use PeptideCore\Shipping_Tracking;
use PeptideCore\Support_Contacts;

defined( 'ABSPATH' ) || exit;

final class Settings_Page {

	public const SLUG  = 'pc-settings';
	public const GROUP = 'pc_settings';
	public const CAP   = 'pc_manage_settings';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 60 );
		add_action( 'admin_init', array( self::class, 'register' ) );
		// options.php 默认要求 manage_options，这里改为插件自己的能力。
		add_filter( 'option_page_capability_' . self::GROUP, static fn() => self::CAP );
	}

	public static function add_menu(): void {
		/* translators: %s: store brand name */
		$title = sprintf( __( '%s settings', 'peptide-core' ), Settings::all()['brand_name'] );
		add_submenu_page( 'woocommerce', $title, $title, self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function register(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s    = Settings::all();
		$ack  = is_array( $s['checkout_ack'] ) ? $s['checkout_ack'] : array();
		$sup  = is_array( $s['support'] ) ? $s['support'] : array();
		$name = Settings::OPTION;
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors( Settings::OPTION ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Brand', 'peptide-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pc_brand_name"><?php esc_html_e( 'Brand name', 'peptide-core' ); ?></label></th>
						<td>
							<input type="text" id="pc_brand_name" name="<?php echo esc_attr( $name ); ?>[brand_name]" class="regular-text" value="<?php echo esc_attr( (string) $s['brand_name'] ); ?>">
							<p class="description"><?php esc_html_e( 'Used in the admin area (workbench, this page). Storefront branding is handled by the theme.', 'peptide-core' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Demo mode', 'peptide-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Demo mode', 'peptide-core' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[demo_mode]" value="1" <?php checked( ! empty( $s['demo_mode'] ) ); ?>> <?php esc_html_e( 'The site contains demonstration data; the storefront shows the demo notice.', 'peptide-core' ); ?></label>
							<p class="description"><?php esc_html_e( 'Turn this off only after all demo records have been removed and real data is in place.', 'peptide-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pc_demo_notice"><?php esc_html_e( 'Demo notice', 'peptide-core' ); ?></label></th>
						<td>
							<input type="text" id="pc_demo_notice" name="<?php echo esc_attr( $name ); ?>[demo_notice]" class="large-text" value="<?php echo esc_attr( (string) $s['demo_notice'] ); ?>">
							<p class="description"><?php esc_html_e( 'Shown to visitors while demo mode is on. English is recommended for overseas visitors.', 'peptide-core' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Use statement', 'peptide-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pc_use_statement"><?php esc_html_e( 'Use statement', 'peptide-core' ); ?></label></th>
						<td>
							<textarea id="pc_use_statement" name="<?php echo esc_attr( $name ); ?>[use_statement_html]" class="large-text" rows="5"><?php echo esc_textarea( (string) $s['use_statement_html'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'A statement about the intended use of your products, shown on the storefront. Leave empty to hide it. Basic HTML (links, bold) is allowed. The content is your decision; make sure it is accurate and compliant.', 'peptide-core' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Checkout confirmation', 'peptide-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Require confirmation', 'peptide-core' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[checkout_ack_enabled]" value="1" <?php checked( ! empty( $ack['enabled'] ) ); ?>> <?php esc_html_e( 'Customers must tick a checkbox before placing an order.', 'peptide-core' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pc_ack_label"><?php esc_html_e( 'Checkbox text', 'peptide-core' ); ?></label></th>
						<td>
							<textarea id="pc_ack_label" name="<?php echo esc_attr( $name ); ?>[checkout_ack_label]" class="large-text" rows="3"><?php echo esc_textarea( (string) ( $ack['label_html'] ?? '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Plain text only. The exact text and the time of acceptance are saved on each order. Required when the confirmation is enabled.', 'peptide-core' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Shipping', 'peptide-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Batch check', 'peptide-core' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[require_batch_to_ship]" value="1" <?php checked( ! empty( $s['require_batch_to_ship'] ) ); ?>> <?php esc_html_e( 'Do not allow marking items as shipped until a batch is assigned (only for products that have batches).', 'peptide-core' ); ?></label>
							<p class="description">
								<?php
								echo esc_html(
									Shipping_Tracking::native_enabled()
										? __( 'Add tracking numbers on the order page with the "Fulfill items" button. The carrier is detected automatically and the customer receives an email with the tracking link.', 'peptide-core' )
										: __( 'Shipment tracking is off. Run `wp peptide shipping enable` to turn on WooCommerce order fulfillments.', 'peptide-core' )
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Customer support button', 'peptide-core' ); ?></h2>
				<p><?php esc_html_e( 'A floating contact button on the storefront. Only the contact methods you fill in are shown. They are public: use business accounts, not personal ones.', 'peptide-core' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show button', 'peptide-core' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[support_enabled]" value="1" <?php checked( ! empty( $sup['enabled'] ) ); ?>> <?php esc_html_e( 'Show the support button on the storefront.', 'peptide-core' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="pc_support_whatsapp"><?php esc_html_e( 'WhatsApp number', 'peptide-core' ); ?></label></th>
						<td>
							<input type="text" id="pc_support_whatsapp" name="<?php echo esc_attr( $name ); ?>[support_whatsapp]" class="regular-text" value="<?php echo esc_attr( '' !== (string) ( $sup['whatsapp'] ?? '' ) ? '+' . $sup['whatsapp'] : '' ); ?>" placeholder="+1 555 123 4567" inputmode="tel">
							<p class="description"><?php esc_html_e( 'International format with country code. Leave empty to hide.', 'peptide-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pc_support_telegram"><?php esc_html_e( 'Telegram username', 'peptide-core' ); ?></label></th>
						<td>
							<input type="text" id="pc_support_telegram" name="<?php echo esc_attr( $name ); ?>[support_telegram]" class="regular-text" value="<?php echo esc_attr( '' !== (string) ( $sup['telegram'] ?? '' ) ? '@' . $sup['telegram'] : '' ); ?>" placeholder="@vialcove_support">
							<p class="description"><?php esc_html_e( 'A username, not a phone number. Leave empty to hide.', 'peptide-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pc_support_email"><?php esc_html_e( 'Support email', 'peptide-core' ); ?></label></th>
						<td><input type="email" id="pc_support_email" name="<?php echo esc_attr( $name ); ?>[support_email]" class="regular-text" value="<?php echo esc_attr( (string) ( $sup['email'] ?? '' ) ); ?>" placeholder="support@example.com"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pc_support_message"><?php esc_html_e( 'WhatsApp greeting', 'peptide-core' ); ?></label></th>
						<td>
							<input type="text" id="pc_support_message" name="<?php echo esc_attr( $name ); ?>[support_message]" class="large-text" maxlength="<?php echo esc_attr( (string) Support_Contacts::MESSAGE_MAX ); ?>" value="<?php echo esc_attr( (string) ( $sup['message'] ?? '' ) ); ?>" placeholder="Hi VialCove, I have a question about my order.">
							<p class="description"><?php esc_html_e( 'Optional text pre-filled when a customer opens WhatsApp. English is recommended.', 'peptide-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pc_support_position"><?php esc_html_e( 'Position', 'peptide-core' ); ?></label></th>
						<td>
							<select id="pc_support_position" name="<?php echo esc_attr( $name ); ?>[support_position]">
								<option value="right" <?php selected( 'right', $sup['position'] ?? 'right' ); ?>><?php esc_html_e( 'Bottom right', 'peptide-core' ); ?></option>
								<option value="left" <?php selected( 'left', $sup['position'] ?? 'right' ); ?>><?php esc_html_e( 'Bottom left', 'peptide-core' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
