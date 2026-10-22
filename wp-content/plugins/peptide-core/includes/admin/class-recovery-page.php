<?php
/**
 * 后台“订单 → 弃单召回”（CART-RECOVERY-01）：统计（最近 30 天）、设置、发送测试邮件、弃单列表。
 * 页面是服务器端渲染的表单，不需要脚本。需要 manage_woocommerce。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Recovery;

defined( 'ABSPATH' ) || exit;

final class Recovery_Page {

	public const SLUG = 'pc-recovery';
	public const CAP  = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 61 );
		add_action( 'admin_post_pc_recovery_save', array( self::class, 'handle_save' ) );
		add_action( 'admin_post_pc_recovery_test', array( self::class, 'handle_test' ) );
		add_action( 'admin_post_pc_recovery_scan', array( self::class, 'handle_scan' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/** 放在“订单”菜单下面（Admin_Menus 建的顶层菜单）；没有这个菜单时放在 WooCommerce 下面。 */
	public static function add_menu(): void {
		global $menu;
		$parent = in_array( Admin_Menus::ORDERS, array_column( (array) $menu, 2 ), true ) ? Admin_Menus::ORDERS : 'woocommerce';
		if ( Admin_Menus::ORDERS === $parent ) {
			add_submenu_page( $parent, __( 'All orders', 'peptide-core' ), __( 'All orders', 'peptide-core' ), 'edit_shop_orders', Admin_Menus::ORDERS );
		}
		add_submenu_page( $parent, __( 'Abandoned cart recovery', 'peptide-core' ), __( 'Abandoned carts', 'peptide-core' ), self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( str_ends_with( $hook, self::SLUG ) ) {
			$file = PEPTIDE_CORE_DIR . '/assets/tracking-admin.css';
			wp_enqueue_style( 'peptide-core-tracking-admin', plugins_url( 'assets/tracking-admin.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function handle_save(): void {
		self::guard( 'pc_recovery_save' );
		$raw = isset( $_POST['pc_recovery'] ) && is_array( $_POST['pc_recovery'] ) ? wp_unslash( $_POST['pc_recovery'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- save_settings() 逐项清理。
		Recovery::save_settings( $raw );
		wp_safe_redirect( self::url( array( 'pc_msg' => 'saved' ) ) );
		exit;
	}

	/** 给当前管理员发一封示例提醒（用一个已发布的商品做示例购物车，不写弃单记录）。 */
	public static function handle_test(): void {
		self::guard( 'pc_recovery_test' );
		$user    = wp_get_current_user();
		$product = wc_get_products( array( 'status' => 'publish', 'limit' => 1, 'type' => array( 'simple', 'variable' ) ) );
		$msg     = 'test_failed';
		if ( '' === Recovery::store_address() ) {
			$msg = 'no_address';
		} elseif ( $product && $user->user_email ) {
			$p      = $product[0];
			$sample = array(
				'id'       => 0,
				'token'    => str_repeat( '0', 32 ),
				'email'    => $user->user_email,
				'subtotal' => (string) $p->get_price(),
				'cart'     => wp_json_encode( array( array( 'product_id' => $p->get_id(), 'variation_id' => 0, 'variation' => array(), 'quantity' => 1, 'line_subtotal' => (float) $p->get_price() ) ) ),
			);
			$msg    = Recovery::send( $sample, 1 ) ? 'test_sent' : 'test_failed';
		}
		wp_safe_redirect( self::url( array( 'pc_msg' => $msg ) ) );
		exit;
	}

	public static function handle_scan(): void {
		self::guard( 'pc_recovery_scan' );
		$r = Recovery::scan();
		wp_safe_redirect( self::url( array( 'pc_msg' => 'scanned', 'pc_sent' => (int) $r['sent'], 'pc_new' => (int) $r['tracked'], 'pc_blocked' => sanitize_key( $r['blocked'] ) ) ) );
		exit;
	}

	private static function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['pc_msg'] ) ? sanitize_key( wp_unslash( $_GET['pc_msg'] ) ) : '';
		if ( '' === $msg ) {
			return;
		}
		$text = array(
			'saved'       => array( 'success', __( 'Settings saved.', 'peptide-core' ) ),
			'test_sent'   => array( 'success', __( 'A sample reminder was sent to your email address.', 'peptide-core' ) ),
			'test_failed' => array( 'error', __( 'The sample reminder could not be sent. Check the site’s email setup.', 'peptide-core' ) ),
			'no_address'  => array( 'error', __( 'Add the store address first (WooCommerce settings → General). US law requires it in marketing emails.', 'peptide-core' ) ),
		);
		if ( 'scanned' === $msg ) {
			/* translators: 1: new abandoned carts, 2: emails sent */
			$line    = sprintf( __( 'Scan finished: %1$d new abandoned cart(s), %2$d reminder(s) sent.', 'peptide-core' ), isset( $_GET['pc_new'] ) ? absint( $_GET['pc_new'] ) : 0, isset( $_GET['pc_sent'] ) ? absint( $_GET['pc_sent'] ) : 0 );
			$blocked = isset( $_GET['pc_blocked'] ) ? sanitize_key( wp_unslash( $_GET['pc_blocked'] ) ) : '';
			if ( 'address' === $blocked ) {
				$line .= ' ' . __( 'No reminders were sent because the store address is missing.', 'peptide-core' );
			} elseif ( 'disabled' === $blocked ) {
				$line = __( 'Recovery is turned off, so nothing was scanned.', 'peptide-core' );
			}
			$text['scanned'] = array( 'info', $line );
		}
		// phpcs:enable
		if ( isset( $text[ $msg ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $text[ $msg ][0] ) . ' is-dismissible"><p>' . esc_html( $text[ $msg ][1] ) . '</p></div>';
		}
	}

	private static function time( int $ms ): string {
		return $ms ? wp_date( 'Y-m-d H:i', (int) floor( $ms / 1000 ) ) : '—';
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s        = Recovery::settings();
		$now      = (int) floor( microtime( true ) * 1000 );
		$stats    = Recovery::stats( $now - 30 * DAY_IN_SECONDS * 1000, $now + 1000 );
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list     = Recovery::records( $status, $paged );
		$address  = Recovery::store_address();
		$next     = wp_next_scheduled( Recovery::CRON );
		$coupons  = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'title', 'order' => 'ASC' ) );
		$statuses = array(
			''             => __( 'All', 'peptide-core' ),
			'open'         => __( 'Waiting', 'peptide-core' ),
			'recovered'    => __( 'Recovered', 'peptide-core' ),
			'converted'    => __( 'Paid without reminder', 'peptide-core' ),
			'closed'       => __( 'Cart emptied', 'peptide-core' ),
			'expired'      => __( 'Session expired', 'peptide-core' ),
			'unsubscribed' => __( 'Unsubscribed', 'peptide-core' ),
		);
		?>
		<div class="wrap pc-trk">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Abandoned cart recovery', 'peptide-core' ); ?></h1>
			<span class="pc-trk-chip <?php echo $s['enabled'] ? 'is-on' : 'is-off'; ?>"><?php echo $s['enabled'] ? esc_html__( 'On', 'peptide-core' ) : esc_html__( 'Off', 'peptide-core' ); ?></span>
			<?php self::notice(); ?>
			<p class="description"><?php esc_html_e( 'A cart counts as abandoned when a shopper has entered an email at checkout, has items in the cart, and has not paid. Reminders stop as soon as they pay. Every email has an unsubscribe link and your store address.', 'peptide-core' ); ?></p>
			<?php if ( '' === $address ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'No reminders will be sent until you add the store address (WooCommerce settings → General). US law (CAN-SPAM) requires a physical address in marketing emails.', 'peptide-core' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ); ?>"><?php esc_html_e( 'Open settings', 'peptide-core' ); ?></a></p></div>
			<?php endif; ?>

			<div class="pc-trk-cards">
				<div class="pc-trk-card"><span><?php esc_html_e( 'Abandoned carts (30 days)', 'peptide-core' ); ?></span><b><?php echo (int) $stats['abandoned']; ?></b></div>
				<div class="pc-trk-card"><span><?php esc_html_e( 'Reminders sent', 'peptide-core' ); ?></span><b><?php echo (int) $stats['emailed']; ?></b></div>
				<div class="pc-trk-card"><span><?php esc_html_e( 'Link clicked', 'peptide-core' ); ?></span><b><?php echo (int) $stats['clicked']; ?></b></div>
				<div class="pc-trk-card"><span><?php esc_html_e( 'Recovered orders', 'peptide-core' ); ?></span><b><?php echo (int) $stats['recovered']; ?></b></div>
				<div class="pc-trk-card"><span><?php esc_html_e( 'Recovery rate', 'peptide-core' ); ?></span><b><?php echo null === $stats['rate'] ? '—' : esc_html( $stats['rate'] . '%' ); ?></b></div>
				<div class="pc-trk-card"><span><?php echo esc_html( __( 'Recovered sales', 'peptide-core' ) . ' (' . get_woocommerce_currency() . ')' ); ?></span><b><?php echo esc_html( number_format_i18n( $stats['recovered_total'], 2 ) ); ?></b></div>
			</div>

			<details class="pc-sd-card" style="background:#fff;border:1px solid #dcdcde;padding:12px 16px;margin-bottom:16px" <?php echo $s['enabled'] ? '' : 'open'; ?>>
				<summary><strong><?php esc_html_e( 'Settings', 'peptide-core' ); ?></strong></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pc_recovery_save">
					<?php wp_nonce_field( 'pc_recovery_save' ); ?>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><?php esc_html_e( 'Send reminders', 'peptide-core' ); ?></th><td><label><input type="checkbox" name="pc_recovery[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Turn on abandoned cart reminders', 'peptide-core' ); ?></label></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'First reminder', 'peptide-core' ); ?></th><td><label><input type="number" min="15" max="4320" name="pc_recovery[delay1_min]" value="<?php echo (int) $s['delay1_min']; ?>" class="small-text"> <?php esc_html_e( 'minutes after the cart last changed', 'peptide-core' ); ?></label></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Second reminder', 'peptide-core' ); ?></th><td><label><input type="checkbox" name="pc_recovery[stage2]" value="1" <?php checked( $s['stage2'] ); ?>> <?php esc_html_e( 'Send a second reminder', 'peptide-core' ); ?></label> <label><input type="number" min="1" max="336" name="pc_recovery[delay2_hours]" value="<?php echo (int) $s['delay2_hours']; ?>" class="small-text"> <?php esc_html_e( 'hours after the first one', 'peptide-core' ); ?></label></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Coupon in the email', 'peptide-core' ); ?></th><td><select name="pc_recovery[coupon]"><option value=""><?php esc_html_e( 'None', 'peptide-core' ); ?></option><?php foreach ( $coupons as $c ) : ?><option value="<?php echo esc_attr( $c->post_title ); ?>" <?php selected( strtolower( $s['coupon'] ), strtolower( $c->post_title ) ); ?>><?php echo esc_html( strtoupper( $c->post_title ) ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Optional. The link in the email applies it automatically when the cart meets its conditions.', 'peptide-core' ); ?></p></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Subject (first / second)', 'peptide-core' ); ?></th><td><input type="text" class="regular-text" name="pc_recovery[subject1]" value="<?php echo esc_attr( $s['subject1'] ); ?>"><br><input type="text" class="regular-text" name="pc_recovery[subject2]" value="<?php echo esc_attr( $s['subject2'] ); ?>"></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Heading', 'peptide-core' ); ?></th><td><input type="text" class="regular-text" name="pc_recovery[heading]" value="<?php echo esc_attr( $s['heading'] ); ?>"></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Message', 'peptide-core' ); ?></th><td><textarea name="pc_recovery[intro]" rows="3" cols="60"><?php echo esc_textarea( $s['intro'] ); ?></textarea><p class="description"><?php esc_html_e( 'Shown above the product list. Keep it factual: no health claims and no fake urgency.', 'peptide-core' ); ?></p></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Button text', 'peptide-core' ); ?></th><td><input type="text" name="pc_recovery[button]" value="<?php echo esc_attr( $s['button'] ); ?>"></td></tr>
					</table>
					<p><button class="button button-primary"><?php esc_html_e( 'Save settings', 'peptide-core' ); ?></button></p>
				</form>
				<p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="pc_recovery_test"><?php wp_nonce_field( 'pc_recovery_test' ); ?>
						<button class="button"><?php esc_html_e( 'Send me a sample reminder', 'peptide-core' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="pc_recovery_scan"><?php wp_nonce_field( 'pc_recovery_scan' ); ?>
						<button class="button"><?php esc_html_e( 'Scan now', 'peptide-core' ); ?></button>
					</form>
					<?php if ( $next ) : ?>
						<?php /* translators: %s: date and time */ ?>
						<span class="description"><?php echo esc_html( sprintf( __( 'Next automatic scan: %s', 'peptide-core' ), wp_date( 'Y-m-d H:i', $next ) ) ); ?></span>
					<?php endif; ?>
				</p>
			</details>

			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( $statuses as $key => $label ) {
					$links[] = '<li><a href="' . esc_url( self::url( '' === $key ? array() : array( 'status' => $key ) ) ) . '"' . ( $key === $status ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
				}
				echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 上面已转义。
				?>
			</ul>
			<table class="widefat striped pc-trk-table">
				<thead><tr>
					<th><?php esc_html_e( 'Email', 'peptide-core' ); ?></th>
					<th><?php esc_html_e( 'Cart', 'peptide-core' ); ?></th>
					<th><?php esc_html_e( 'Abandoned', 'peptide-core' ); ?></th>
					<th><?php esc_html_e( 'Reminders', 'peptide-core' ); ?></th>
					<th><?php esc_html_e( 'Status', 'peptide-core' ); ?></th>
					<th><?php esc_html_e( 'Order', 'peptide-core' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $list['items'] ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No abandoned carts yet.', 'peptide-core' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $list['items'] as $r ) :
					$names = array();
					foreach ( (array) json_decode( (string) $r['cart'], true ) as $i ) {
						$p       = wc_get_product( $i['variation_id'] ? $i['variation_id'] : $i['product_id'] );
						$names[] = ( $p ? $p->get_name() : '#' . (int) $i['product_id'] ) . ' × ' . wc_stock_amount( $i['quantity'] );
					}
					$order = (int) $r['order_id'] ? wc_get_order( (int) $r['order_id'] ) : null;
					?>
					<tr>
						<td><?php echo esc_html( $r['email'] ); ?><?php echo (int) $r['user_id'] ? '<div class="pc-trk-sub">' . esc_html__( 'Account', 'peptide-core' ) . ' #' . (int) $r['user_id'] . '</div>' : ''; ?></td>
						<td><?php echo esc_html( implode( ', ', $names ) ); ?><div class="pc-trk-sub"><?php echo wp_kses_post( wc_price( (float) $r['subtotal'] ) ); ?></div></td>
						<td><?php echo esc_html( self::time( (int) $r['changed_ms'] ) ); ?></td>
						<td><?php echo esc_html( '1: ' . self::time( (int) $r['sent1_ms'] ) ); ?><div class="pc-trk-sub"><?php echo esc_html( '2: ' . self::time( (int) $r['sent2_ms'] ) ); ?><?php echo (int) $r['clicked_ms'] ? ' · ' . esc_html__( 'clicked', 'peptide-core' ) : ''; ?></div></td>
						<td><?php echo esc_html( $statuses[ $r['status'] ] ?? $r['status'] ); ?></td>
						<td><?php echo $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a> ' . wp_kses_post( wc_price( (float) $r['order_total'] ) ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $list['total'] / 25 );
			if ( $pages > 1 ) {
				echo '<p class="pc-trk-more">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'current' => $paged, 'total' => $pages ) ) ) . '</p>';
			}
			?>
		</div>
		<?php
	}
}
