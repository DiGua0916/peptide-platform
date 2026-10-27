<?php
/**
 * 系统设置 → 邮件通知（EMAIL-CENTER-01），版式参照 Shopname：
 * 顾客通知 / 弃单通知 / 购物发现 / 管理员通知 四个标签，加“邮件详情数据”（发送记录）。
 *
 * 每张卡片对应一封 WooCommerce 邮件（WooCommerce 自带的或本插件的 PC_Email_*）：
 * 预览用 WooCommerce 自带的邮件预览（iframe），开关写回这封邮件的 enabled 设置，“编辑”打开 WooCommerce 的邮件设置页。
 * Shopname 的积分邮件（本店没有积分功能）和财务通知（Shopname 平台账单）不适用，没有做。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Emails\Log;
use PeptideCore\Recovery;

defined( 'ABSPATH' ) || exit;

final class Email_Center {

	public const CAP    = 'manage_woocommerce';
	public const TOGGLE = 'pc_email_toggle';

	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::TOGGLE, array( self::class, 'toggle' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function url( string $tab = 'customer' ): string {
		return admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG . '&section=email&tab=' . $tab );
	}

	public static function enqueue(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( Store_Settings::SYSTEM_SLUG !== ( $_GET['page'] ?? '' ) || 'email' !== ( $_GET['section'] ?? '' ) ) {
			return;
		}
		// phpcs:enable
		$ver = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-plans', plugins_url( 'assets/plans.css', PEPTIDE_CORE_FILE ), array( 'peptide-core-store-settings' ), $ver( 'plans.css' ) );
		wp_add_inline_script( 'common', "document.addEventListener('change',function(e){if(e.target.matches('.pc-em-switch input')){e.target.form.submit();}});" );
	}

	/**
	 * 各标签的卡片：WooCommerce 邮件类名 => 显示名称。没有注册的邮件不显示（邀请评价例外，可以从这里开启）。
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function groups(): array {
		return array(
			'customer'  => array(
				'PC_Email_Register_Invite'              => __( 'Registration invitation', 'peptide-core' ),
				'WC_Email_Customer_New_Account'         => __( 'Customer registration', 'peptide-core' ),
				'WC_Email_Customer_Processing_Order'    => __( 'Order paid', 'peptide-core' ),
				'WC_Email_Customer_On_Hold_Order'       => __( 'Offline payment order', 'peptide-core' ),
				'WC_Email_Customer_Cancelled_Order'     => __( 'Order cancelled', 'peptide-core' ),
				'WC_Email_Customer_Fulfillment_Created' => __( 'Order shipped', 'peptide-core' ),
				'WC_Email_Customer_Fulfillment_Updated' => __( 'Shipment updated', 'peptide-core' ),
				'WC_Email_Customer_Completed_Order'     => __( 'Order delivered', 'peptide-core' ),
				'WC_Email_Customer_Refunded_Order'      => __( 'Order refunded', 'peptide-core' ),
				'WC_Email_Customer_Failed_Order'        => __( 'Order payment failed', 'peptide-core' ),
				'WC_Email_Customer_Reset_Password'      => __( 'Customer password reset', 'peptide-core' ),
				'PC_Email_Register_Verify'              => __( 'Registration email verification', 'peptide-core' ),
				'WC_Email_Customer_Verify_Email'        => __( 'Confirm email for guest orders', 'peptide-core' ),
				'WC_Email_Customer_Review_Request'      => __( 'Review invitation', 'peptide-core' ),
				'WC_Email_Customer_Invoice'             => __( 'Order details (sent manually)', 'peptide-core' ),
				'WC_Email_Customer_Note'                => __( 'Order note to customer', 'peptide-core' ),
			),
			'abandoned' => array(
				'PC_Email_Cart_Recovery_1' => __( 'Stage 1', 'peptide-core' ),
				'PC_Email_Cart_Recovery_2' => __( 'Stage 2', 'peptide-core' ),
				'PC_Email_Cart_Recovery_3' => __( 'Stage 3', 'peptide-core' ),
			),
			'browse'    => array(
				'PC_Email_Browse_Reminder' => __( 'Shopping discovery', 'peptide-core' ),
			),
			'admin'     => array(
				'WC_Email_New_Order'                     => __( 'Order paid (admin)', 'peptide-core' ),
				'WC_Email_Failed_Order'                  => __( 'Order payment failed (admin)', 'peptide-core' ),
				'WC_Email_Cancelled_Order'               => __( 'Order cancelled (admin)', 'peptide-core' ),
				'PC_Email_Admin_Abandoned'               => __( 'Abandoned cart (admin)', 'peptide-core' ),
				'PC_Email_Admin_Duplicate'               => __( 'Possible duplicate payment (admin)', 'peptide-core' ),
				'PC_Email_Admin_Offline'                 => __( 'Offline payment order (admin)', 'peptide-core' ),
				'WC_Email_Admin_Payment_Gateway_Enabled' => __( 'Payment method enabled (admin)', 'peptide-core' ),
			),
		);
	}

	public static function preview_url( string $key ): string {
		return add_query_arg( array( 'preview_woocommerce_mail' => 'true', 'type' => $key, '_wpnonce' => wp_create_nonce( 'preview-mail' ) ), admin_url( '' ) );
	}

	public static function edit_url( string $key ): string {
		return admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( $key ) );
	}

	// ---------- 开关 ----------

	public static function toggle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( self::TOGGLE );
		$key = sanitize_text_field( wp_unslash( $_POST['email'] ?? '' ) );
		$on  = ! empty( $_POST['on'] );
		self::set_enabled( $key, $on );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::url() );
		exit;
	}

	/** 打开 / 关闭一封邮件。邀请评价是 WooCommerce 的测试功能，开启时同时打开这个功能。 */
	public static function set_enabled( string $key, bool $on ): bool {
		$known = array_merge( ...array_values( array_map( 'array_keys', self::groups() ) ) );
		if ( ! in_array( $key, $known, true ) ) {
			return false;
		}
		if ( 'WC_Email_Customer_Review_Request' === $key && $on ) {
			update_option( 'woocommerce_feature_customer_review_request_enabled', 'yes' );
		}
		$emails = WC()->mailer()->get_emails();
		$id     = isset( $emails[ $key ] ) ? $emails[ $key ]->id : ( 'WC_Email_Customer_Review_Request' === $key ? 'customer_review_request' : '' );
		if ( '' === $id ) {
			return false;
		}
		$opt            = (array) get_option( 'woocommerce_' . $id . '_settings', array() );
		$opt['enabled'] = $on ? 'yes' : 'no';
		update_option( 'woocommerce_' . $id . '_settings', $opt );
		return true;
	}

	private static function switch_form( string $key, bool $on, string $label ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-em-switch"><input type="hidden" name="action" value="' . esc_attr( self::TOGGLE ) . '"><input type="hidden" name="email" value="' . esc_attr( $key ) . '"><input type="hidden" name="on" value="' . ( $on ? '0' : '1' ) . '">'
			. wp_nonce_field( self::TOGGLE, '_wpnonce', true, false )
			. '<label class="pc-pl-switch" title="' . esc_attr( $on ? __( 'On', 'peptide-core' ) : __( 'Off', 'peptide-core' ) ) . '"><input type="checkbox"' . checked( $on, true, false ) . ' aria-label="' . esc_attr( $label ) . '"><span></span></label><noscript><button class="button button-small">' . esc_html( $on ? __( 'Turn off', 'peptide-core' ) : __( 'Turn on', 'peptide-core' ) ) . '</button></noscript></form>';
	}

	// ---------- 页面 ----------

	public static function render(): void {
		$tab  = sanitize_key( wp_unslash( $_GET['tab'] ?? 'customer' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'customer'  => __( 'Customer notifications', 'peptide-core' ),
			'abandoned' => __( 'Abandoned cart', 'peptide-core' ),
			'browse'    => __( 'Shopping discovery', 'peptide-core' ),
			'admin'     => __( 'Admin notifications', 'peptide-core' ),
		);
		$tab  = isset( $tabs[ $tab ] ) || 'log' === $tab ? $tab : 'customer';

		echo '<div class="pc-pl pc-em">';
		echo '<p class="pc-ss-back"><a href="' . esc_url( admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG ) ) . '">‹ ' . esc_html__( 'System settings', 'peptide-core' ) . '</a></p>';
		echo '<div class="pc-pl-head"><h1>' . esc_html__( 'Email notifications', 'peptide-core' ) . '</h1>';
		echo '<a class="button-link" href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ) . '"><span class="dashicons dashicons-admin-generic"></span> ' . esc_html__( 'Settings', 'peptide-core' ) . '</a>';
		echo '<a class="button-link pc-pl-new" href="' . esc_url( self::url( 'log' ) ) . '"><span class="dashicons dashicons-chart-bar"></span> ' . esc_html__( 'Email delivery data', 'peptide-core' ) . '</a></div>';

		$blocked = Recovery::blocked_reason();
		if ( 'sender' === $blocked || ! is_email( (string) get_option( 'woocommerce_email_from_address' ) ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Without a support email, automatic emails cannot be sent.', 'peptide-core' ) . ' <a href="' . esc_url( Basic_Config_Page::url() ) . '">' . esc_html__( 'Basic settings', 'peptide-core' ) . '</a></p></div>';
		}

		echo '<div class="pc-pl-card"><nav class="pc-pl-tabs">';
		foreach ( $tabs as $k => $label ) {
			echo '<a href="' . esc_url( self::url( $k ) ) . '" class="' . ( $k === $tab ? 'is-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><div class="pc-pl-body">';
		if ( 'log' === $tab ) {
			self::render_log();
		} elseif ( 'abandoned' === $tab || 'browse' === $tab ) {
			self::render_rows( $tab, $blocked );
		} else {
			self::render_cards( $tab );
		}
		echo '</div></div></div>';
	}

	private static function render_cards( string $tab ): void {
		$emails = WC()->mailer()->get_emails();
		echo '<div class="pc-em-grid">';
		foreach ( self::groups()[ $tab ] as $key => $label ) {
			$email = $emails[ $key ] ?? null;
			if ( ! $email && 'WC_Email_Customer_Review_Request' !== $key ) {
				continue;
			}
			$on   = $email && $email->is_enabled();
			$foot = '';
			if ( $email && ! $email->is_customer_email() ) {
				/* translators: %s: recipient address */
				$foot = sprintf( __( 'Sent to %s', 'peptide-core' ), (string) $email->get_recipient() );
			}
			if ( $email && method_exists( $email, 'delay' ) ) {
				/* translators: %d: seconds */
				$foot .= ' · ' . sprintf( __( 'Delay: %d s', 'peptide-core' ), $email->delay() );
			}
			if ( 'PC_Email_Register_Invite' === $key && 'yes' !== get_option( 'woocommerce_enable_myaccount_registration' ) ) {
				$foot = __( 'Not sent while registration on the account page is off.', 'peptide-core' );
			}
			if ( 'PC_Email_Register_Verify' === $key && ! \PeptideCore\Store_Config::get( 'register_verify' ) ) {
				$foot = __( 'Used when “Register email verification” is on in Basic settings.', 'peptide-core' );
			}
			echo '<div class="pc-em-card' . ( $on ? '' : ' is-off' ) . '"><div class="pc-em-card__head"><strong>' . esc_html( $label ) . '</strong>' . self::switch_form( $key, $on, $label ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( $email ) {
				echo '<a class="pc-em-card__preview" href="' . esc_url( self::edit_url( $key ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: email name */ __( 'Edit %s', 'peptide-core' ), $label ) ) . '"><iframe src="' . esc_url( self::preview_url( $key ) ) . '" loading="lazy" scrolling="no" tabindex="-1" aria-hidden="true" title=""></iframe></a>';
			} else {
				echo '<div class="pc-em-card__preview pc-em-card__off"><p>' . esc_html__( 'This is a WooCommerce beta feature. Turning it on also enables the feature: customers get an email after their order is completed, inviting them to review what they bought.', 'peptide-core' ) . '</p></div>';
			}
			echo '<div class="pc-em-card__foot">' . esc_html( $email ? wp_strip_all_tags( (string) $email->get_description() ) : '' ) . ( $foot ? '<br><span class="pc-pl-muted">' . esc_html( $foot ) . '</span>' : '' ) . '</div></div>';
		}
		echo '</div>';
	}

	private static function render_rows( string $tab, string $blocked ): void {
		$emails = WC()->mailer()->get_emails();
		if ( 'browse' === $tab ) {
			echo '<div class="pc-pl-info">1. ' . esc_html__( '“Shopping discovery” reminds new customers who showed interest in the store but have not ordered yet.', 'peptide-core' ) . '<br>2. ' . esc_html__( 'How it works: when a new customer registers and does not place an order within the delay, they automatically get this email with the products they recently added to the cart or viewed. Each customer gets it once.', 'peptide-core' ) . '</div>';
		} else {
			echo '<div class="pc-pl-info">' . esc_html__( 'Customers who entered their email and address at checkout but did not pay get up to three reminders. The delay counts from the last change to the cart; each reminder has its own coupon. Abandoned checkouts are listed under Orders → Abandoned checkouts.', 'peptide-core' ) . ' <a href="' . esc_url( Recovery_Page::url() ) . '">' . esc_html__( 'Abandoned checkouts', 'peptide-core' ) . '</a></div>';
		}
		if ( 'address' === $blocked ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'These emails are not sent until the store address is filled in (US law requires a physical address in marketing emails).', 'peptide-core' ) . ' <a href="' . esc_url( Basic_Config_Page::url() ) . '">' . esc_html__( 'Public contact information', 'peptide-core' ) . '</a></p></div>';
		}
		foreach ( self::groups()[ $tab ] as $key => $label ) {
			$email = $emails[ $key ] ?? null;
			if ( ! $email ) {
				continue;
			}
			$on     = $email->is_enabled();
			$coupon = method_exists( $email, 'coupon' ) ? $email->coupon() : '';
			if ( 'abandoned' === $tab ) {
				echo '<h3 class="pc-em-stage">' . esc_html( $label ) . '</h3>';
			}
			echo '<div class="pc-em-row' . ( $on ? '' : ' is-off' ) . '"><a class="pc-em-row__preview" href="' . esc_url( self::edit_url( $key ) ) . '"><iframe src="' . esc_url( self::preview_url( $key ) ) . '" loading="lazy" scrolling="no" tabindex="-1" aria-hidden="true" title=""></iframe></a>';
			echo '<span>' . esc_html__( 'Coupon', 'peptide-core' ) . ': ' . esc_html( '' !== $coupon ? strtoupper( $coupon ) : '—' ) . '</span>';
			/* translators: %d: seconds */
			echo '<span>' . esc_html( sprintf( __( 'Send delay: %d s', 'peptide-core' ), method_exists( $email, 'delay' ) ? $email->delay() : 0 ) ) . '</span>';
			echo self::switch_form( $key, $on, $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<a class="button" href="' . esc_url( self::edit_url( $key ) ) . '">' . esc_html__( 'Edit', 'peptide-core' ) . '</a></div>';
		}
		if ( 'abandoned' === $tab ) {
			$admin = $emails['PC_Email_Admin_Abandoned'] ?? null;
			if ( $admin ) {
				/* translators: %s: on/off */
				echo '<p class="pc-pl-muted">' . esc_html( sprintf( __( 'Admin notice for new abandoned checkouts: %s.', 'peptide-core' ), $admin->is_enabled() ? __( 'On', 'peptide-core' ) : __( 'Off', 'peptide-core' ) ) ) . ' <a href="' . esc_url( self::url( 'admin' ) ) . '">' . esc_html__( 'Admin notifications', 'peptide-core' ) . '</a></p>';
			}
		}
	}

	private static function render_log(): void {
		$emails = WC()->mailer()->get_emails();
		$names  = array();
		foreach ( self::groups() as $group ) {
			foreach ( $group as $key => $label ) {
				if ( isset( $emails[ $key ] ) ) {
					$names[ $emails[ $key ]->id ] = $label;
				}
			}
		}
		foreach ( $emails as $e ) {
			$names[ $e->id ] = $names[ $e->id ] ?? wp_strip_all_tags( (string) $e->get_title() );
		}
		$now = (int) floor( microtime( true ) * 1000 );
		$d7  = Log::stats( $now - 7 * DAY_IN_SECONDS * 1000 );
		$d30 = Log::stats( $now - 30 * DAY_IN_SECONDS * 1000 );
		echo '<h2>' . esc_html__( 'Email delivery data', 'peptide-core' ) . '</h2><p class="pc-pl-muted">' . esc_html__( 'Counts every store email handed to the mail server (WooCommerce and VialCove emails). “Failed” means the mail server refused it. Opens are not tracked.', 'peptide-core' ) . '</p>';
		echo '<table class="pc-pl-table"><thead><tr><th>' . esc_html__( 'Email', 'peptide-core' ) . '</th><th>' . esc_html__( 'Sent (7 days)', 'peptide-core' ) . '</th><th>' . esc_html__( 'Sent (30 days)', 'peptide-core' ) . '</th><th>' . esc_html__( 'Failed (30 days)', 'peptide-core' ) . '</th><th>' . esc_html__( 'Last sent', 'peptide-core' ) . '</th></tr></thead><tbody>';
		if ( ! $d30 ) {
			echo '<tr><td colspan="5" class="pc-pl-empty">' . esc_html__( 'No emails sent in the last 30 days.', 'peptide-core' ) . '</td></tr>';
		}
		foreach ( $d30 as $id => $s ) {
			echo '<tr><td>' . esc_html( $names[ $id ] ?? $id ) . '</td><td>' . (int) ( $d7[ $id ]['sent'] ?? 0 ) . '</td><td>' . (int) $s['sent'] . '</td><td>' . (int) $s['failed'] . '</td><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) floor( $s['last_ms'] / 1000 ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$recent = Log::recent( 1, 50 );
		echo '<h2>' . esc_html__( 'Latest emails', 'peptide-core' ) . '</h2><table class="pc-pl-table"><thead><tr><th>' . esc_html__( 'Time', 'peptide-core' ) . '</th><th>' . esc_html__( 'Email', 'peptide-core' ) . '</th><th>' . esc_html__( 'Recipient', 'peptide-core' ) . '</th><th>' . esc_html__( 'Subject', 'peptide-core' ) . '</th><th>' . esc_html__( 'Result', 'peptide-core' ) . '</th></tr></thead><tbody>';
		if ( ! $recent['items'] ) {
			echo '<tr><td colspan="5" class="pc-pl-empty">' . esc_html__( 'No emails yet.', 'peptide-core' ) . '</td></tr>';
		}
		foreach ( $recent['items'] as $r ) {
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) floor( (int) $r['sent_ms'] / 1000 ) ) ) . '</td><td>' . esc_html( $names[ $r['email_id'] ] ?? $r['email_id'] ) . '</td><td>' . esc_html( $r['recipient'] ) . '</td><td>' . esc_html( $r['subject'] ) . '</td><td>' . esc_html( (int) $r['ok'] ? __( 'Sent', 'peptide-core' ) : __( 'Failed', 'peptide-core' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
